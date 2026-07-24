<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\SyncLog;
use App\Models\DailyDashboardStat;
use App\Models\ClinicStat;
use App\Services\GapDetectionService;
use App\Services\SyncService;
use App\Jobs\SyncForDateJob;
use App\Jobs\HealDataJob;
use App\Jobs\ReaggregateRangeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sync', description: 'Data synchronization from the external HIS API — all routes are intentionally public for LAN access')]
class SyncController extends Controller
{
    protected $syncService;
    protected $gapService;

    public function __construct(SyncService $syncService, GapDetectionService $gapService)
    {
        $this->syncService = $syncService;
        $this->gapService = $gapService;
        set_time_limit(0);
    }

    #[OA\Get(
        path: '/sync/{date}',
        summary: 'Synchronous (blocking) sync for a single date',
        description: 'Fetches data from the HIS API and upserts into the local database in the same HTTP request. Blocks until complete. Use `/sync/trigger/{date}` for async background sync. Pass `today` as the date to sync today\'s data.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', example: '2026-07-17'),
                description: 'Date in YYYY-MM-DD or YYYYMMDD format.'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sync completed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Successfully synced 348 records for date 2026-07-17'),
                    new OA\Property(property: 'sample_channeled_data', type: 'object', nullable: true),
                ])
            ),
            new OA\Response(response: 500, description: 'Sync failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function sync($date = null)
    {
        // Handle Ymd or Y-m-d
        if ($date && strlen($date) === 8 && is_numeric($date)) {
            $formattedDate = Carbon::createFromFormat('Ymd', $date)->toDateString();
        } else {
            $formattedDate = $date ?: date('Y-m-d');
        }

        $result = $this->syncService->syncForDate($formattedDate);

        if ($result['success']) {
            $sample = Visit::whereDate('visit_date', $formattedDate)->latest()->first();
            return response()->json([
                'message' => "Successfully synced {$result['count']} records for date {$formattedDate}",
                'sample_channeled_data' => $sample
            ]);
        }

        return response()->json([
            'error' => 'Sync failed',
            'details' => $result['error']
        ], 500);
    }

    #[OA\Get(
        path: '/sync/trigger/{date}',
        summary: 'Dispatch an async background sync job for a single date',
        description: 'Returns immediately with a batch ID. Use `GET /sync/batch/{id}` to poll progress. Pass `today` to trigger today\'s sync.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', example: '2026-07-17'),
                description: 'Date in YYYY-MM-DD or YYYYMMDD format.'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job dispatched',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Sync job dispatched for date 2026-07-17'),
                    new OA\Property(property: 'status', type: 'string', example: 'queued'),
                    new OA\Property(property: 'batch_id', type: 'string', example: 'abc123def456'),
                ])
            ),
        ]
    )]
    public function triggerSync($date = null)
    {
        // Handle Ymd or Y-m-d
        if ($date && strlen($date) === 8 && is_numeric($date)) {
            $formattedDate = Carbon::createFromFormat('Ymd', $date)->toDateString();
        } else {
            $formattedDate = $date ?: date('Y-m-d');
        }

        $batch = Bus::batch([
            new SyncForDateJob($formattedDate, true) // Force true for manual triggers
        ])->name("sync:{$formattedDate}")->dispatch();

        return response()->json([
            'message' => "Sync job dispatched for date {$formattedDate}",
            'status' => 'queued',
            'batch_id' => $batch->id
        ]);
    }

    #[OA\Get(
        path: '/sync/range',
        summary: 'Synchronous (blocking) sync for a date range — max 366 days',
        description: 'Fetches and upserts data from the HIS API for each date in the range sequentially. Will timeout on large ranges — use `/sync/enqueue/range` for ranges longer than a few weeks.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Range sync completed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'synced_days', type: 'integer', example: 17),
                    new OA\Property(property: 'errors', type: 'object'),
                ])
            ),
            new OA\Response(response: 400, description: 'Missing or invalid date params'),
            new OA\Response(response: 500, description: 'Sync failed'),
        ]
    )]
    public function syncRange(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Start date and end date are required'], 400);
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        // Safety limit: prevent syncing more than 366 days at once via API to avoid timeout issues
        if ($start->diffInDays($end) > 366) {
            return response()->json(['error' => 'Range too large. Please sync max 1 year at a time.'], 400);
        }

        try {
            // Use the optimized parallel sync service
            $result = $this->syncService->syncDateRange($start->toDateString(), $end->toDateString());
            
            return response()->json([
                'message' => "Sync completed for range $startDate to $endDate",
                'synced_days' => $result['total_synced_days'],
                'errors' => $result['errors']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Sync failed',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Get(
        path: '/sync/reaggregate/range',
        summary: 'Rebuild aggregated stats from already-synced visits (no HIS API call)',
        description: 'Recomputes daily_dashboard_stats and clinic_stats from the local visits table. Much faster than a full sync. Use when stats are out of sync with raw visit data.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reaggregation completed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'rebuilt_days', type: 'integer', example: 17),
                    new OA\Property(property: 'errors', type: 'object'),
                ])
            ),
            new OA\Response(response: 400, description: 'Missing date params'),
        ]
    )]
    public function reaggregateRange(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Start date and end date are required'], 400);
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Same safety limit as syncing.
        if ($start->diffInDays($end) > 366) {
            return response()->json(['error' => 'Range too large. Please rebuild max 1 year at a time.'], 400);
        }

        $rebuiltDays = 0;
        $errors = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();
            try {
                $this->syncService->updateAggregatedStats($dateString);
                $rebuiltDays++;
            } catch (\Exception $e) {
                $errors[$dateString] = $e->getMessage();
            }
        }

        return response()->json([
            'message' => "Re-aggregation completed for range $startDate to $endDate",
            'rebuilt_days' => $rebuiltDays,
            'errors' => $errors
        ]);
    }

    #[OA\Get(
        path: '/sync/enqueue/range',
        summary: 'Queue background sync jobs for a date range — returns immediately',
        description: 'Dispatches one SyncForDateJob per date into the queue. Returns a batch ID immediately. Safe for large ranges (up to 366 days). Poll with `GET /sync/batch/{id}`.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'force', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean', default: false),
                description: 'Force re-sync even for dates that already have successful sync logs'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Batch queued',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'batch_id', type: 'string', example: 'abc123def456'),
                    new OA\Property(property: 'total_jobs', type: 'integer', example: 198),
                ])
            ),
            new OA\Response(response: 400, description: 'Missing or invalid params'),
        ]
    )]
    public function enqueueSyncRange(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $force = $request->query('force') === 'true';

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Start date and end date are required'], 400);
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        if ($start->diffInDays($end) > 366) {
            return response()->json(['error' => 'Range too large. Please sync max 1 year at a time.'], 400);
        }

        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (!$this->gapService->expectsDataForDate($date)) {
                continue;
            }

            $dates[] = $date->toDateString();
        }

        $visitCounts = Visit::whereBetween('visit_date', [$startDate, $endDate])
            ->selectRaw('visit_date, COUNT(*) as total_visits')
            ->groupBy('visit_date')
            ->pluck('total_visits', 'visit_date');

        $statsByDate = DailyDashboardStat::whereBetween('stat_date', [$startDate, $endDate])
            ->get(['stat_date', 'total_visits'])
            ->keyBy(fn ($item) => $item->stat_date->format('Y-m-d'));

        $syncDates = [];
        $reaggregateDates = [];

        foreach ($dates as $date) {
            if ($force) {
                $syncDates[] = $date;
                continue;
            }

            $rawVisits = (int) ($visitCounts[$date] ?? 0);
            $stat = $statsByDate->get($date);
            $statVisits = $stat ? (int) $stat->total_visits : null;

            if ($rawVisits > 0) {
                if ($statVisits === null || $statVisits !== $rawVisits) {
                    $reaggregateDates[] = $date;
                }

                continue;
            }

            if ($statVisits === null) {
                $syncDates[] = $date;
                continue;
            }

            if ($statVisits > 0) {
                $syncDates[] = $date;
            }
        }

        $syncDates = array_values(array_unique($syncDates));
        $reaggregateDates = array_values(array_unique(array_diff($reaggregateDates, $syncDates)));

        if (empty($syncDates) && empty($reaggregateDates)) {
            return response()->json([
                'message' => 'Selected range is already complete and aggregated.',
                'batch_id' => null,
                'total_jobs' => 0,
                'sync_jobs' => 0,
                'reaggregate_jobs' => 0,
            ], 200);
        }

        $batchName = "sync:$startDate:$endDate" . ($force ? ":force" : "");

        // 1. Prevent duplicate active batches for the same range
        $existingBatch = DB::table('job_batches')
            ->where('name', $batchName)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->first();

        if ($existingBatch) {
            return response()->json([
                'message' => "An active sync for this range is already in progress. Re-attaching to existing batch.",
                'batch_id' => $existingBatch->id,
                'total_jobs' => $existingBatch->total_jobs,
                'is_duplicate' => true
            ], 200);
        }

        // 2. Clear out ALL other pending sync batches (auto or manual) to ensure this manual sync runs IMMEDIATELY.
        DB::table('job_batches')
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => now()->getTimestamp(),
                'finished_at' => now()->getTimestamp()
            ]);

        foreach ($syncDates as $date) {
            $jobs[] = (new SyncForDateJob($date, $force))->onQueue('high');
        }

        foreach (array_chunk($reaggregateDates, 14) as $dateChunk) {
            $jobs[] = (new ReaggregateRangeJob($dateChunk))->onQueue('default');
        }

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->dispatch();

        return response()->json([
            'message' => $force
                ? "Force sync enqueued for range $startDate to $endDate (" . count($jobs) . " jobs)."
                : "Smart sync enqueued for range $startDate to $endDate ({$batch->totalJobs} jobs: " . count($syncDates) . " remote sync, " . count($reaggregateDates) . " dates to re-aggregate).",
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'sync_jobs' => count($syncDates),
            'reaggregate_jobs' => count($reaggregateDates),
        ], 202);
    }

    #[OA\Get(
        path: '/sync/heal-data',
        summary: 'Heal missing data — fix records with blank doctor/clinic names from the HIS',
        description: 'Queues a HealDataJob to backfill missing names (e.g. "Bill Doctor N/A") by re-fetching those records from the HIS API. Optionally scoped to a single date.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-10'),
                description: 'Scope healing to a specific date. Omit to heal all dates.'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Healing job dispatched',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Healing job dispatched'),
                    new OA\Property(property: 'batch_id', type: 'string', nullable: true),
                ])
            ),
        ]
    )]
    public function healData(Request $request)
    {
        $date = $request->query('date');
        
        $batch = Bus::batch([new HealDataJob($date)])
            ->name("data-healing:" . ($date ?: 'all'))
            ->dispatch();
            
        return response()->json([
            'message' => "Data healing process started in the background.",
            'batch_id' => $batch->id
        ], 202);
    }

    #[OA\Post(
        path: '/sync/reset-state',
        summary: 'Force-clear all in-progress sync state to unblock the UI',
        description: 'Cancels all non-finished batches and clears sync-related cache locks. Use when the UI is stuck showing "sync in progress" after a worker crash.',
        tags: ['Sync'],
        responses: [
            new OA\Response(response: 200, description: 'Sync state reset',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Sync state reset successfully'),
                    new OA\Property(property: 'cancelled_batches', type: 'integer', example: 2),
                ])
            ),
        ]
    )]
    public function resetSyncState()
    {
        SyncLog::where('status', 'PENDING')->update(['status' => 'FAILED', 'error_message' => 'Manual Reset']);
        SyncLog::where('status', 'PROCESSING')->update(['status' => 'FAILED', 'error_message' => 'Manual Reset']);
        DB::table('job_batches')->whereNull('finished_at')->update(['cancelled_at' => time(), 'finished_at' => time()]);
        Cache::flush();

        return response()->json(['message' => 'Sync state reset and cache flushed successfully.']);
    }

    #[OA\Post(
        path: '/sync/repair-gaps',
        summary: 'Queue sync jobs for specific gap dates detected by gap detection',
        tags: ['Sync'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['dates'],
                properties: [
                    new OA\Property(
                        property: 'dates',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'date', example: '2026-06-15'),
                        description: 'Array of YYYY-MM-DD dates to re-sync'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gap repair jobs dispatched',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'batch_id', type: 'string', example: 'abc123def456'),
                    new OA\Property(property: 'queued_dates', type: 'integer', example: 3),
                ])
            ),
            new OA\Response(response: 400, description: 'Missing or invalid dates array'),
        ]
    )]
    public function repairGaps(Request $request)
    {
        $rawDates = $request->input('dates', []);
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOL);

        if (!is_array($rawDates)) {
            return response()->json(['error' => 'Dates must be provided as an array'], 422);
        }

        $dates = [];
        $ignored = [];

        foreach ($rawDates as $rawDate) {
            try {
                $date = Carbon::parse($rawDate)->toDateString();
            } catch (\Throwable $e) {
                $ignored[] = [
                    'date' => (string) $rawDate,
                    'reason' => 'Invalid date format',
                ];
                continue;
            }

            if ($this->gapService->shouldSkipDate($date)) {
                $ignored[] = [
                    'date' => $date,
                    'reason' => 'Date is outside the operational gap-detection calendar',
                ];
                continue;
            }

            $dates[$date] = $date;
        }

        $dates = array_values($dates);

        if (empty($dates)) {
            return response()->json([
                'error' => 'No repairable operational dates provided',
                'ignored_dates' => $ignored,
            ], 422);
        }

        $jobs = [];
        foreach ($dates as $date) {
            if ($force) {
                // Remove existing success logs to allow overwrite if forced
                SyncLog::where('sync_date', $date)
                    ->where('sync_type', 'visits')
                    ->delete();
                
                // Also clear cache to be sure
                $this->syncService->clearCacheForDate($date);
            }
            $jobs[] = new SyncForDateJob($date, $force);
        }

        $batch = Bus::batch($jobs)
            ->name("gap-repair:" . count($dates) . "_days")
            ->dispatch();

        return response()->json([
            'message' => 'Repair jobs enqueued for ' . count($dates) . ' dates.',
            'batch_id' => $batch->id,
            'ignored_dates' => $ignored,
        ], 202);
    }

    #[OA\Get(
        path: '/sync/batch/{id}',
        summary: 'Get the status and progress of a queued sync batch',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', example: 'abc123def456'),
                description: 'Batch ID returned by trigger or enqueue endpoints'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Batch status',
                content: new OA\JsonContent(ref: '#/components/schemas/BatchStatus')
            ),
            new OA\Response(response: 404, description: 'Batch not found'),
        ]
    )]
    public function batchStatus(string $id)
    {
        // Special case for 'auto' - return a global status if any sync is running
        if ($id === 'active' || $id === 'global') {
            $activeSyncs = SyncLog::whereIn('status', ['PROCESSING', 'PENDING'])
                ->where('updated_at', '>', now()->subMinutes(15))
                ->count();
            
            if ($activeSyncs === 0) {
                return response()->json(['finished' => true, 'progress' => 100]);
            }

            // Estimate progress based on recent logs
            return response()->json([
                'id' => 'global',
                'name' => 'Background Sync',
                'progress' => 0, // We can't easily calculate aggregate progress without a batch
                'active_tasks' => $activeSyncs,
                'is_global' => true
            ]);
        }

        $batch = Bus::findBatch($id);

        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }

        $progress = $batch->progress();
        $isWaiting = ($progress == 0 && $batch->pendingJobs > 0);
        $isSilent = (strpos($batch->name, 'auto-sync:') === 0);
        
        if ($isWaiting) {
            $this->syncService->cleanupStaleBatches();
        }

        // Format the name for the message (remove sync: prefix and :force suffix)
        $cleanName = str_replace(['sync:', ':force'], '', $batch->name);

        return response()->json([
            'id' => $batch->id,
            'name' => $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'processed_jobs' => $batch->processedJobs(),
            'progress' => $progress,
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'is_waiting' => $isWaiting,
            'is_silent' => $isSilent,
            'message' => ($progress < 100)
                ? "Background Syncing... ({$batch->processedJobs()}/{$batch->totalJobs})"
                : null
        ]);
    }

    #[OA\Post(
        path: '/sync/cancel-batch/{id}',
        summary: 'Cancel a running or pending sync batch',
        description: 'Marks the batch as cancelled. Already-running jobs will finish but no new jobs from the batch will be picked up.',
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', example: 'abc123def456')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Batch cancelled',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccess')
            ),
            new OA\Response(response: 404, description: 'Batch not found'),
        ]
    )]
    public function cancelBatch($id)
    {
        $batch = Bus::findBatch($id);
        
        if (!$batch) {
            return response()->json(['error' => 'Batch not found'], 404);
        }

        if (!$batch->finished()) {
            $batch->cancel();
        }

        return response()->json([
            'message' => 'Sync cancelled successfully.',
            'batch_id' => $id,
        ]);
    }


}
