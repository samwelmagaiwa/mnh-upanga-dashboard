<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyDashboardStat;
use App\Models\ClinicStat;
use App\Models\Visit;
use App\Models\DailyReferralStat;
use App\Services\SyncService;
use App\Services\GapDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard', description: 'Public dashboard statistics, charts, and real-time update polling')]
#[OA\Tag(name: 'Sync', description: 'Data synchronization from the external HIS API — all routes are intentionally public')]
class DashboardController extends Controller
{
    private function getRemoteApiAvailability(): bool
    {
        return Cache::remember('dashboard_remote_api_health_v1', 10, function () {
            try {
                $username = config('dashboard.sync.username', env('DASHBOARD_API_USERNAME'));
                $password = config('dashboard.sync.password', env('DASHBOARD_API_PASSWORD'));
                $baseUrl = config('dashboard.sync.base_url', env('DASHBOARD_API_BASE_URL', 'http://192.168.235.250/labsms/swagger/mnh_dashboard'));

                $response = Http::withBasicAuth($username, $password)
                    ->connectTimeout(5)
                    ->timeout(8)
                    ->head(rtrim($baseUrl, '/'));

                // Any HTTP response (even 404) confirms the server is reachable
                return $response->status() > 0;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    private function rememberUnlessFresh(Request $request, string $cacheKey, int $ttl, callable $callback)
    {
        if ($request->boolean('fresh')) {
            return $callback();
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Cache version - increment this when cache structure changes.
     * This invalidates all dashboard caches without manual key updates.
     */
    private function getCacheVersion(): int
    {
        return config('dashboard.cache_version', 10);
    }

    protected $syncService;
    protected $gapService;

    public function __construct(SyncService $syncService, GapDetectionService $gapService)
    {
        $this->syncService = $syncService;
        $this->gapService = $gapService;
    }

    /**
     * Generate a versioned cache key.
     */
    private function cacheKey(string $prefix, string ...$parts): string
    {
        return $prefix . '_' . implode('_', $parts) . '_v' . $this->getCacheVersion();
    }

    #[OA\Get(
        path: '/dashboard/stats',
        summary: 'Aggregated visit statistics for a date range',
        description: 'Returns total visits, consultation status, patient categories (NHIF, public, foreigner, etc.), gender breakdown, age groups, and comparison stats vs. a previous equivalent period. Auto-syncs today\'s data if missing.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17'),
                description: 'Start of range (defaults to today)'
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17'),
                description: 'End of range (defaults to today)'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard statistics',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'stats', ref: '#/components/schemas/DashboardStats'),
                    new OA\Property(property: 'previous_stats', ref: '#/components/schemas/DashboardStats', nullable: true),
                    new OA\Property(property: 'comp_label', type: 'string', example: 'vs. previous day'),
                    new OA\Property(property: 'remote_api_available', type: 'boolean', example: true),
                    new OA\Property(property: 'synced_stats', ref: '#/components/schemas/DashboardStats', nullable: true,
                        description: 'Most recent synced data — returned as fallback when remote API is unavailable'
                    ),
                ])
            ),
        ]
    )]
    public function getStats(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        $comparison = $this->getComparisonPeriod(Carbon::parse($startDate), Carbon::parse($endDate));
        $cacheKey = $this->cacheKey('dashboard_stats', $startDate, $endDate);
        $isToday = ($startDate === date('Y-m-d') && $endDate === date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        \Illuminate\Support\Facades\Log::info("[Dashboard] getStats", ['start' => $startDate, 'end' => $endDate]);

        $data = $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate, $comparison) {
            $stats = $this->aggregateStats($startDate, $endDate);
            $prevStats = $this->aggregateStats($comparison['start']->toDateString(), $comparison['end']->toDateString());

            $expectedDays = $this->gapService->countExpectedDataDays($startDate, $endDate);
            $aggregatedDays = $this->gapService->countRecordedDataDays($startDate, $endDate);

            return [
                'stats' => $stats,
                'previous_stats' => $prevStats,
                'expected_days' => $expectedDays,
                'aggregated_days' => $aggregatedDays,
            ];
        });

        // Sync status must NEVER be cached as it changes frequently and triggers UI loops
        $activeBatch = DB::table('job_batches')
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->latest('created_at')
            ->first();

        // Calculate metadata dynamically on every request
        $isSyncingNow = \App\Models\SyncLog::whereIn('status', ['PROCESSING', 'PENDING'])
            ->where('updated_at', '>', now()->subMinutes(15))
            ->exists() || ($activeBatch !== null);

        // AUTO-TRIGGER SYNC FOR GAPS (Silent Background Update)
        if (!$isSyncingNow) {
            $mainGaps = $this->gapService->detectGaps($startDate, $endDate);
            $compGaps = $this->gapService->detectGaps($comparison['start']->toDateString(), $comparison['end']->toDateString());
            $allGaps = array_merge($mainGaps, $compGaps);

            if (count($allGaps) > 0 && count($allGaps) <= 60) { // Safety Threshold: Max 2 months auto-sync
                $syncDates = array_unique(array_column($allGaps, 'date'));
                $jobs = [];
                foreach ($syncDates as $d) {
                    $jobs[] = new \App\Jobs\SyncForDateJob($d, false);
                }
                $batch = Bus::batch($jobs)->name("auto-sync-gaps-{$startDate}")->dispatch();
                $isSyncingNow = true;
                $activeBatch = $batch;
            }
        }

        $meta = [
            'expected_days' => $data['expected_days'],
            'aggregated_days' => $data['aggregated_days'],
            'is_fully_aggregated' => $data['aggregated_days'] >= $data['expected_days'],
            'is_syncing' => $isSyncingNow,
            'active_batch_id' => $activeBatch ? $activeBatch->id : null,
            'remote_api_available' => $this->getRemoteApiAvailability(),
            'cached_at' => now()->toDateTimeString(),
        ];

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'compLabel' => $comparison['label'],
            'meta' => $meta,
            'stats' => $data['stats'],
            'previous_stats' => $data['previous_stats']
        ];
    }

    private function getComparisonPeriod(Carbon $start, Carbon $end)
    {
        $diffInDays = $start->diffInDays($end) + 1;
        $prevStart = $start->copy();
        $prevEnd = $end->copy();
        $label = '';

        // Year comparison
        if ($start->day == 1 && $start->month == 1 && $end->day == 31 && $end->month == 12) {
            $prevStart->subYear()->startOfYear();
            $prevEnd->subYear()->endOfYear();
            $label = "vs " . $prevStart->year;
        }
        // Month comparison
        elseif ($start->day == 1 && $end->isLastOfMonth() && $start->month == $end->month) {
            $prevStart->subMonth()->startOfMonth();
            $prevEnd->subMonth()->endOfMonth();
            $label = "vs " . $prevStart->format('M Y');
        }
        // Week comparison
        elseif ($diffInDays == 7) {
            $prevStart->subDays(7);
            $prevEnd->subDays(7);
            $label = "vs Prev Week";
        }
        // Day comparison
        elseif ($diffInDays == 1) {
            $prevStart->subDay();
            $prevEnd->subDay();
            $label = "vs Yesterday";
        }
        else {
            $prevStart->subDays($diffInDays);
            $prevEnd->subDays($diffInDays);
            $label = "vs Prev Period";
        }

        return [
            'start' => $prevStart,
            'end' => $prevEnd,
            'label' => $label
        ];
    }

    private function aggregateStats($startDate, $endDate)
    {
        $baseStats = DailyDashboardStat::where('stat_date', '>=', $startDate)
            ->where('stat_date', '<=', $endDate)
            ->selectRaw('
                SUM(total_visits) as total_visits,
                SUM(consulted) as consulted,
                (SUM(total_visits) - SUM(consulted)) as pending,
                SUM(matched_count) as matched_count,
                SUM(mismatched_count) as mismatched_count,
                SUM(new_visits) as new_visits,
                SUM(followups) as followups,
                SUM(nhif_visits) as nhif_visits,
                SUM(foreigner) as foreigner,
                SUM(public) as public,
                SUM(ippm_private) as ippm_private,
                SUM(ippm_credit) as ippm_credit,
                SUM(cost_sharing) as cost_sharing,
                SUM(nssf) as nssf,
                SUM(emergency) as emergency_visits,
                SUM(duplicates) as duplicates,
                SUM(male_count) as male,
                SUM(female_count) as female,
                SUM(no_gender_count) as no_gender,
                SUM(unknown_gender_count) as unknown,
                SUM(neonate_count) as neonate,
                SUM(infant_count) as infant,
                SUM(child_count) as child,
                SUM(adolescent_count) as adolescent,
                SUM(adult_count) as adult,
                SUM(elderly_count) as elderly
            ')
            ->first();

        return [
            'total_visits' => (int)($baseStats->total_visits ?? 0),
            'total_patients' => (int)($baseStats->total_visits ?? 0),
            'consulted' => (int)($baseStats->consulted ?? 0),
            'matched_count' => (int)($baseStats->matched_count ?? 0),
            'mismatched_count' => (int)($baseStats->mismatched_count ?? 0),
            'total_detailed' => (int)($baseStats->total_visits ?? 0),
            'pending' => (int)($baseStats->pending ?? 0),
            'new_visits' => (int)($baseStats->new_visits ?? 0),
            'followups' => (int)($baseStats->followups ?? 0),
            'nhif_visits' => (int)($baseStats->nhif_visits ?? 0),
            'emergency' => (int)($baseStats->emergency_visits ?? 0),
            'emergency_patients' => (int)($baseStats->emergency_visits ?? 0),
            'emergency_visits' => (int)($baseStats->emergency_visits ?? 0),
            'foreigner' => (int)($baseStats->foreigner ?? 0),
            'public' => (int)($baseStats->public ?? 0),
            'ippm_private' => (int)($baseStats->ippm_private ?? 0),
            'ippm_credit' => (int)($baseStats->ippm_credit ?? 0),
            'cost_sharing' => (int)($baseStats->cost_sharing ?? 0),
            'nssf' => (int)($baseStats->nssf ?? 0),
            'duplicates' => (int)($baseStats->duplicates ?? 0),
            'gender' => [
                'male' => (int)($baseStats->male ?? 0),
                'female' => (int)($baseStats->female ?? 0),
                'no_gender' => (int)($baseStats->no_gender ?? 0),
                'unknown' => (int)($baseStats->unknown ?? 0),
            ],
            'age_groups' => [
                'neonate' => (int)($baseStats->neonate ?? 0),
                'infant' => (int)($baseStats->infant ?? 0),
                'child' => (int)($baseStats->child ?? 0),
                'adolescent' => (int)($baseStats->adolescent ?? 0),
                'adult' => (int)($baseStats->adult ?? 0),
                'elderly' => (int)($baseStats->elderly ?? 0),
            ],
            'categories' => [
                'foreigner' => (int)($baseStats->foreigner ?? 0),
                'public' => (int)($baseStats->public ?? 0),
                'nhif' => (int)($baseStats->nhif_visits ?? 0),
                'ippm_private' => (int)($baseStats->ippm_private ?? 0),
                'ippm_credit' => (int)($baseStats->ippm_credit ?? 0),
                'cost_sharing' => (int)($baseStats->cost_sharing ?? 0),
                'nssf' => (int)($baseStats->nssf ?? 0),
            ]
        ];
    }

    #[OA\Get(
        path: '/dashboard/clinics',
        summary: 'Clinic-wise visit breakdown for a date range',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Clinic breakdown',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/ClinicBreakdown')
                )
            ),
        ]
    )]
    public function getClinicBreakdown(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');
        $hospBu = $request->query('hosp_bu'); // optional BU filter

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        $buSuffix = $hospBu ? '_' . md5($hospBu) : '';
        $cacheKey = $this->cacheKey('clinic_breakdown', $startDate, $endDate) . $buSuffix;
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate, $hospBu) {
            $comparison = $this->getComparisonPeriod(Carbon::parse($startDate), Carbon::parse($endDate));
            $compLabel = $comparison['label'];

            $currentQuery = ClinicStat::where('stat_date', '>=', $startDate)
                ->where('stat_date', '<=', $endDate);
            if ($hospBu) {
                $currentQuery->where('hosp_bu', trim($hospBu));
            }
            $current = $currentQuery
                ->selectRaw('clinic_name, SUM(total_visits) as total_visits, SUM(consulted) as consulted, SUM(pending) as pending')
                ->groupBy('clinic_name')
                ->orderByDesc('total_visits')
                ->get();

            $prevQuery = ClinicStat::where('stat_date', '>=', $comparison['start']->toDateString())
                ->where('stat_date', '<=', $comparison['end']->toDateString());
            if ($hospBu) {
                $prevQuery->where('hosp_bu', trim($hospBu));
            }
            $prev = $prevQuery
                ->selectRaw('clinic_name, SUM(total_visits) as total_visits, SUM(consulted) as consulted, SUM(pending) as pending')
                ->groupBy('clinic_name')
                ->get();

            $prevMap = [];
            foreach ($prev as $row) {
                $normalized = preg_replace('/\s+/', ' ', trim($row->clinic_name));
                $prevMap[$normalized] = [
                    'total_visits' => (int)$row->total_visits,
                    'consulted' => (int)$row->consulted,
                    'pending' => (int)$row->pending
                ];
            }

            $breakdown = [];
            foreach ($current as $row) {
                $rawName = $row->clinic_name;
                $normalizedName = preg_replace('/\s+/', ' ', trim($rawName));
                
                $curTot = (int) ($row->total_visits ?? 0);
                $curCons = (int) ($row->consulted ?? 0);
                $curPend = (int) ($row->pending ?? 0);
                
                $pData = $prevMap[$normalizedName] ?? ['total_visits' => 0, 'consulted' => 0, 'pending' => 0];
                $prevTot = $pData['total_visits'];
                $prevCons = $pData['consulted'];
                $prevPend = $pData['pending'];

                $trend = 0.0;
                if ($prevTot > 0) {
                    $trend = (($curTot - $prevTot) / $prevTot) * 100;
                    $trend = round($trend, 1);
                } elseif ($curTot > 0) {
                    $trend = 100.0;
                }

                // Cap trend at +/- 100%
                if ($trend > 100) $trend = 100.0;
                if ($trend < -100) $trend = -100.0;

                $interpretation = 'Stable';
                if ($curTot === 0) {
                    $interpretation = 'No Visits';
                } elseif ($trend > 0) {
                    $interpretation = 'Increasing';
                } elseif ($trend < 0) {
                    $interpretation = 'Decreasing';
                }

                $breakdown[] = [
                    'clinic_name' => $rawName,
                    'total_visits' => $curTot,
                    'consulted' => $curCons,
                    'pending' => $curPend,
                    'previous_visits' => $prevTot,
                    'previous_consulted' => $prevCons,
                    'previous_pending' => $prevPend,
                    'trend' => $trend,
                    'interpretation' => $interpretation,
                    'comparison_dates' => $compLabel,
                ];
            }

            return $breakdown;
        });
    }

    /**
     * Get distinct business units available in the given date range.
     */
    public function getBusinessUnits(Request $request)
    {
        $startDate = $request->query('start_date', date('Y-m-d'));
        $endDate   = $request->query('end_date',   $startDate);

        $units = ClinicStat::where('stat_date', '>=', $startDate)
            ->where('stat_date', '<=', $endDate)
            ->whereNotNull('hosp_bu')
            ->where('hosp_bu', '!=', '')
            ->distinct()
            ->orderBy('hosp_bu')
            ->pluck('hosp_bu')
            ->map(fn($bu) => trim($bu))
            ->unique()
            ->values();

        return response()->json(['data' => $units]);
    }

    /**
     * Get detailed visit-level breakdown for clinics.
     * Uses pagination to prevent PHP memory/timeout crashes on large date ranges.
     */
    #[OA\Get(
        path: '/dashboard/detailed-clinics',
        summary: 'Detailed visit-level clinic breakdown (paginated)',
        description: 'Returns raw visit records grouped by clinic. Paginated to prevent memory issues on large ranges.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'clinic', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', example: 'All Clinics'),
                description: 'Filter to a specific clinic name, or "All Clinics" for all'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detailed clinic visits',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'total', type: 'integer'),
                ])
            ),
        ]
    )]
    public function getDetailedClinicVisits(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        $maxPerPage = $request->query('export') ? 5000 : 1000;
        $perPage = min((int) $request->query('per_page', 500), $maxPerPage);
        $page = (int) $request->query('page', 1);

        // Detailed clinics should ALWAYS be fresh - never serve cached stale data
        // This prevents the "stats show data but table is empty" issue
        $cacheKey = $this->cacheKey('detailed_clinics', $startDate, $endDate, (string)$page, (string)$perPage);
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate, $perPage, $page) {
            $query = Visit::where('visit_date', '>=', $startDate)
                ->where('visit_date', '<=', $endDate)
                ->select([
                    'mr_number',
                    'gender',
                    'pat_age',
                    'visit_type',
                    'visit_date',
                    'cons_time',
                    'doct_code',
                    'bill_doct_name',
                    'cons_doctor',      // Attend Doctor Code
                    'cons_doctor_name', // Attend Doctor Name
                    'clinic_name',
                    'clinic_code',
                    'final_diag',
                    'prov_diag'
                ]);

            $paginated = $query->orderBy('clinic_name', 'asc')
                ->orderBy('visit_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Enrich list with ICD Metadata
            $allCodes = collect();
            $delimiters = '/[\$,;]/'; // Split by $, comma, or semicolon

            foreach ($paginated->items() as $visit) {
                if ($visit->prov_diag) {
                    $codes = preg_split($delimiters, $visit->prov_diag, -1, PREG_SPLIT_NO_EMPTY);
                    $allCodes = $allCodes->concat(array_map('trim', $codes));
                }
                if ($visit->final_diag) {
                    $codes = preg_split($delimiters, $visit->final_diag, -1, PREG_SPLIT_NO_EMPTY);
                    $allCodes = $allCodes->concat(array_map('trim', $codes));
                }
            }

            $uniqueCodes = $allCodes->unique()->filter()->values();
            $icdMetadata = \App\Models\Icd::whereIn('code', $uniqueCodes)
                ->get(['code', 'description', 'abbreviation'])
                ->keyBy('code');

            return [
                'status' => 'success',
                'data' => collect($paginated->items())->toArray(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
                'diagnosis_metadata' => $icdMetadata
            ];
        });
    }

    #[OA\Get(
        path: '/dashboard/gaps',
        summary: 'Detect missing or zero-visit days in a date range',
        description: 'Compares expected operational days against actual aggregated records. Returns MISSING (no record) or EMPTY (record exists but zero visits). Skips non-operational weekdays (Sundays by default) and configured public holidays.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Gap detection result',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'gaps', type: 'array', items: new OA\Items(ref: '#/components/schemas/GapEntry')),
                    new OA\Property(property: 'total_gaps', type: 'integer', example: 3),
                    new OA\Property(property: 'expected_days', type: 'integer', example: 22),
                    new OA\Property(property: 'recorded_days', type: 'integer', example: 19),
                ])
            ),
        ]
    )]
    public function getGaps(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::today()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', Carbon::today()->toDateString());

        $gaps = $this->gapService->detectGaps($startDate, $endDate);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'gaps' => $gaps,
            'gap_count' => count($gaps),
            'calendar' => [
                'expected_data_days' => $this->gapService->countExpectedDataDays($startDate, $endDate),
            ],
        ];
    }

    #[OA\Get(
        path: '/dashboard/pie-stats',
        summary: 'Pie chart data — gender split and visit type distribution',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pie chart data',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'gender', type: 'object', properties: [
                        new OA\Property(property: 'male', type: 'integer', example: 620),
                        new OA\Property(property: 'female', type: 'integer', example: 590),
                        new OA\Property(property: 'no_gender', type: 'integer', example: 10),
                    ]),
                    new OA\Property(property: 'visit_type', type: 'object', properties: [
                        new OA\Property(property: 'new', type: 'integer', example: 800),
                        new OA\Property(property: 'followup', type: 'integer', example: 440),
                    ]),
                ])
            ),
        ]
    )]
    public function getPieStats(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        $cacheKey = $this->cacheKey('pie_stats', $startDate, $endDate);
        $isToday = ($startDate === date('Y-m-d') && $endDate === date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate) {
            // 1. Gender Distribution (Strictly from aggregated stats)
            $genderStats = \App\Models\DailyDashboardStat::where('stat_date', '>=', $startDate)
                ->where('stat_date', '<=', $endDate)
                ->selectRaw('SUM(total_visits) as total, SUM(male_count) as male, SUM(female_count) as female, SUM(no_gender_count) as no_gender, SUM(unknown_gender_count) as unknown')
                ->first();

            // 2. Visit Type (Strictly from aggregated stats)
            $visitTypeStats = \App\Models\DailyDashboardStat::where('stat_date', '>=', $startDate)
                ->where('stat_date', '<=', $endDate)
                ->selectRaw('SUM(total_visits) as total, SUM(new_visits) as new_visits, SUM(followups) as followups')
                ->first();

            // 3. Age Group Distribution (Strictly from aggregated stats)
            $ageStats = \App\Models\DailyDashboardStat::where('stat_date', '>=', $startDate)
                ->where('stat_date', '<=', $endDate)
                ->selectRaw('SUM(total_visits) as total, SUM(neonate_count) as neonate, SUM(infant_count) as infant, SUM(child_count) as child, SUM(adolescent_count) as adolescent, SUM(adult_count) as adult, SUM(elderly_count) as elderly')
                ->first();

            $result = [
                'gender' => [
                    'male' => (int)($genderStats->male ?? 0),
                    'female' => (int)($genderStats->female ?? 0),
                    'no_gender' => (int)($genderStats->no_gender ?? 0),
                    'unknown' => (int)($genderStats->unknown ?? 0),
                ],
                'visit_type' => [
                    'new' => (int)($visitTypeStats->new_visits ?? 0),
                    'followup' => (int)($visitTypeStats->followups ?? 0),
                ],
                'age_groups' => [
                    'neonate' => (int)($ageStats->neonate ?? 0),
                    'infant' => (int)($ageStats->infant ?? 0),
                    'child' => (int)($ageStats->child ?? 0),
                    'adolescent' => (int)($ageStats->adolescent ?? 0),
                    'adult' => (int)($ageStats->adult ?? 0),
                    'elderly' => (int)($ageStats->elderly ?? 0),
                ]
            ];

            \Illuminate\Support\Facades\Log::info("[Dashboard] getPieStats result", [
                'start' => $startDate,
                'end' => $endDate,
                'gender_total' => array_sum($result['gender']),
                'age_total' => array_sum($result['age_groups'])
            ]);

            return $result;
        });
    }

    #[OA\Get(
        path: '/dashboard/chart-stats',
        summary: 'Radar chart — current period vs. previous equivalent period',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Comparison chart data',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'current', ref: '#/components/schemas/DashboardStats'),
                    new OA\Property(property: 'previous', ref: '#/components/schemas/DashboardStats', nullable: true),
                    new OA\Property(property: 'label', type: 'string', example: 'vs. previous week'),
                ])
            ),
        ]
    )]
    public function getComparisonStats(Request $request)
    {
        $startDate = $request->query('start_date', date('Y-m-d'));
        $endDate = $request->query('end_date', date('Y-m-d'));

        $cacheKey = $this->cacheKey('comp_stats', $startDate, $endDate);
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate) {
            $comparison = $this->getComparisonPeriod(Carbon::parse($startDate), Carbon::parse($endDate));

            $current = $this->aggregateStats($startDate, $endDate);
            $previous = $this->aggregateStats($comparison['start']->toDateString(), $comparison['end']->toDateString());

            // Radar Chart Labels (Categories)
            $labels = ['PUBLIC', 'NHIF', 'IPPM-PRV', 'IPPM-CRD', 'COST-SH', 'NSSF', 'FOREIGN'];

            return [
                'labels' => $labels,
                'period_labels' => [
                    'current' => Carbon::parse($startDate)->format('M d') . ' - ' . Carbon::parse($endDate)->format('M d'),
                    'previous' => $comparison['start']->format('M d') . ' - ' . $comparison['end']->format('M d'),
                ],
                'current' => [
                    (int)($current['categories']['public'] ?? 0),
                    (int)($current['categories']['nhif'] ?? 0),
                    (int)($current['categories']['ippm_private'] ?? 0),
                    (int)($current['categories']['ippm_credit'] ?? 0),
                    (int)($current['categories']['cost_sharing'] ?? 0),
                    (int)($current['categories']['nssf'] ?? 0),
                    (int)($current['categories']['foreigner'] ?? 0),
                ],
                'previous' => [
                    (int)($previous['categories']['public'] ?? 0),
                    (int)($previous['categories']['nhif'] ?? 0),
                    (int)($previous['categories']['ippm_private'] ?? 0),
                    (int)($previous['categories']['ippm_credit'] ?? 0),
                    (int)($previous['categories']['cost_sharing'] ?? 0),
                    (int)($previous['categories']['nssf'] ?? 0),
                    (int)($previous['categories']['foreigner'] ?? 0),
                ],
                'compLabel' => $comparison['label']
            ];
        });
    }

    #[OA\Get(
        path: '/dashboard/service-trends',
        summary: 'Service trend data for the grouped bar chart',
        description: 'Returns daily totals across new visits, follow-ups, NHIF, and emergency per day in the selected range.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service trends',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'labels', type: 'array', items: new OA\Items(type: 'string', example: '2026-07-01')),
                    new OA\Property(property: 'datasets', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'label', type: 'string', example: 'New Visits'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'integer')),
                    ])),
                ])
            ),
        ]
    )]
    public function getServiceTrends(Request $request)
    {
        $period = $request->query('period', 'day');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $breakdown = $request->query('breakdown', null); // 'monthly' for monthly breakdown

        if (!$startDate || !$endDate) {
            $startDate = date('Y-m-d');
            $endDate = $startDate;
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // --- EXPANSION LOGIC FOR CHART CONTEXT ---
        // 1. If 'day' is selected (single day range), expand to rolling 7-day window (ending at selected day)
        if ($period === 'day' && $start->diffInDays($end) == 0 && !$breakdown) {
            $end = $end->copy();
            $start = $end->copy()->subDays(6);
        }

        // 2. If 'year' is selected, ensure we show full Jan-Dec
        if ($period === 'year' && !$breakdown) {
            $start = $start->copy()->startOfYear();
            $end = $end->copy()->endOfYear();
        }
        // -----------------------------------------

        // Use the exact range provided - no expansion
        // This ensures bars match cards data exactly

        $cacheKey = $this->cacheKey('service_trends', $period, $startDate, $endDate, $breakdown ?? 'default');
        $now = date('Y-m-d');
        // Reduced cache TTL for more responsive data updates
        $includesToday = ($startDate <= $now && $endDate >= $now);
        $ttl = $includesToday ? 30 : 300; // 30 seconds for today, 5 minutes otherwise
        $breakdownSuffix = $breakdown === 'monthly' ? '_monthly' : '';

        return $this->rememberUnlessFresh($request, $cacheKey . $breakdownSuffix, $ttl, function() use ($period, $startDate, $endDate, $start, $end, $breakdown) {
            $labels = [];
            $dataMap = [];
            $days = $start->diffInDays($end) + 1;

            // Smart Period Selection (Dynamic Grouping)
            $effectivePeriod = $period;
            if ($breakdown === 'monthly' || $period === 'year') {
                $effectivePeriod = 'monthly_breakdown';
            } elseif ($period === 'range' || $period === 'month' || $period === 'week') {
                if ($days > 45) {
                    $effectivePeriod = 'monthly_breakdown'; // Fallback to months for very long custom ranges
                } else {
                    $effectivePeriod = 'range'; // Show individual days for week, month, and short ranges
                }
            }

            // 1. Generate empty containers dynamically based on exact range
            switch ($effectivePeriod) {
                case 'monthly_breakdown':
                    // Monthly breakdown: show data grouped by month
                    $tempStart = $start->copy()->startOfMonth();
                    while ($tempStart <= $end) {
                        $label = $tempStart->format('M Y');
                        $key = $tempStart->format('Y-m');
                        $labels[] = $label;
                        $dataMap[$key] = [
                            'label' => $label,
                            'opd' => 0, 'emergency' => 0, 'consulted' => 0, 
                            'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0
                        ];
                        $tempStart->addMonth();
                    }
                    break;
                case 'day':
                    // Logic for displaying days (now potentially a full week)
                    $tempStart = $start->copy();
                    while ($tempStart <= $end) {
                        // Format: "Monday - 12"
                        $label = $tempStart->format('l - d');
                        $labels[] = $label;
                        $dataMap[$tempStart->toDateString()] = [
                            'label' => $label,
                            'opd' => 0, 'emergency' => 0, 'consulted' => 0, 
                            'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0
                        ];
                        $tempStart->addDay();
                    }
                    break;
                case 'range':
                    $tempStart = $start->copy();
                    while ($tempStart <= $end) {
                        // Use consistent "Weekday - Day" format for all day-wise views
                        $label = $tempStart->format('l - d');
                        $labels[] = $label;
                        $dataMap[$tempStart->toDateString()] = [
                            'label' => $label,
                            'opd' => 0, 'emergency' => 0, 'consulted' => 0, 
                            'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0
                        ];
                        $tempStart->addDay();
                    }
                    break;
                case 'week':
                    // Iterate through actual weeks in range
                    $tempStart = $start->copy()->startOfWeek(Carbon::MONDAY);
                    $tempEnd = $end->copy()->endOfWeek(Carbon::SUNDAY);
                    $seenWeeks = [];
                    while ($tempStart <= $tempEnd) {
                        $key = $tempStart->format('o-W');
                        if (!isset($seenWeeks[$key])) {
                            $label = "Week " . $tempStart->weekOfMonth . " (" . $tempStart->format('M') . ")";
                            $labels[] = $label;
                            $dataMap[$key] = [
                                'label' => $label,
                                'opd' => 0, 'emergency' => 0, 'consulted' => 0, 
                                'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0
                            ];
                            $seenWeeks[$key] = true;
                        }
                        $tempStart->addWeek();
                    }
                    break;
                case 'month':
                    // Month View: Show Weekly Breakdown (Week 1, Week 2, etc)
                    // We iterate through weeks within the selected month
                    $tempStart = $start->copy()->startOfMonth();
                    $tempEnd = $start->copy()->endOfMonth();
                    
                    // Grouping by Sunday-based weeks or just standard 7-day windows?
                    // Let's use standard Carbon week blocks starting from the 1st
                    $weekNum = 1;
                    while ($tempStart <= $tempEnd) {
                        $label = "Week $weekNum";
                        $key = "W$weekNum";
                        $labels[] = $label;
                        $dataMap[$key] = [
                            'label' => $label,
                            'opd' => 0, 'emergency' => 0, 'consulted' => 0, 
                            'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0,
                            'start' => $tempStart->toDateString(),
                            'end' => $tempStart->copy()->addDays(6)->min($tempEnd)->toDateString()
                        ];
                        $tempStart->addDays(7);
                        $weekNum++;
                    }
                    break;
                case 'year':
                    // Default Year View (Single Aggregated Bar)
                    $label = $start->format('Y');
                    $key = $start->format('Y');
                    $labels[] = $label;
                    $dataMap[$key] = [
                        'label' => $label,
                        'opd' => 0, 'emergency' => 0, 'consulted' => 0,
                        'not_consulted' => 0, 'new_visits' => 0, 'followups' => 0
                    ];
                    break;
            }

            // 2. Fetch data
            $query = DailyDashboardStat::where('stat_date', '>=', $start->toDateString())
                ->where('stat_date', '<=', $end->toDateString());

            switch ($effectivePeriod) {
                case 'monthly_breakdown':
                    $query->selectRaw('DATE_FORMAT(stat_date, "%Y-%m") as group_key');
                    break;
                case 'year':
                    $query->selectRaw('DATE_FORMAT(stat_date, "%Y") as group_key');
                    break;
                case 'week':
                    $query->selectRaw('DATE_FORMAT(stat_date, "%x-%v") as group_key'); // ISO Year-Week
                    break;
                default: // day, range, month
                    $query->selectRaw('DATE_FORMAT(stat_date, "%Y-%m-%d") as group_key');
                    break;
            }

            $results = $query->selectRaw('
                SUM(total_visits) as opd,
                SUM(emergency) as emergency,
                SUM(consulted) as consulted,
                (SUM(total_visits) - SUM(consulted)) as not_consulted,
                SUM(new_visits) as new_visits,
                SUM(followups) as followups
            ')
            ->groupBy('group_key')
            ->get();

            // 3. Map results into the pre-filled dataMap
            foreach ($results as $row) {
                $gk = (string)$row->group_key;
                
                // For the 'month' case (which is now weekly), we need to find which week container the date belongs to
                if ($period === 'month' && !isset($dataMap[$gk]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $gk)) {
                    foreach ($dataMap as $key => $container) {
                        if (isset($container['start']) && isset($container['end'])) {
                            if ($gk >= $container['start'] && $gk <= $container['end']) {
                                $dataMap[$key]['opd'] += (int)$row->opd;
                                $dataMap[$key]['emergency'] += (int)$row->emergency;
                                $dataMap[$key]['consulted'] += (int)$row->consulted;
                                $dataMap[$key]['not_consulted'] += (int)$row->not_consulted;
                                $dataMap[$key]['new_visits'] += (int)$row->new_visits;
                                $dataMap[$key]['followups'] += (int)$row->followups;
                                break;
                            }
                        }
                    }
                    continue;
                }

                if (isset($dataMap[$gk])) {
                    $dataMap[$gk]['opd'] = (int)$row->opd;
                    $dataMap[$gk]['emergency'] = (int)$row->emergency;
                    $dataMap[$gk]['consulted'] = (int)$row->consulted;
                    $dataMap[$gk]['not_consulted'] = (int)$row->not_consulted;
                    $dataMap[$gk]['new_visits'] = (int)$row->new_visits;
                    $dataMap[$gk]['followups'] = (int)$row->followups;
                }
            }

            // 4. Transform into Chart.js format
            $orderedData = collect(array_values($dataMap));

            return [
                'labels' => $labels,
                'dates' => array_keys($dataMap),
                'datasets' => [
                    [
                        'type' => 'line',
                        'label' => 'Total Trend',
                        'data' => $orderedData->pluck('opd'),
                        'borderColor' => '#1e293b', // Dark slate for high contrast
                        'borderWidth' => 2,
                        'pointBackgroundColor' => '#fff',
                        'pointBorderColor' => '#1e293b',
                        'pointRadius' => 4,
                        'pointHoverRadius' => 6,
                        'fill' => false,
                        'tension' => 0.4, // Smooth curve
                        'order' => 0, // Render on top
                    ],
                    [
                        'label' => 'Total OPD',
                        'data' => $orderedData->pluck('opd'),
                    ],
                    [
                        'label' => 'Emergency',
                        'data' => $orderedData->pluck('emergency'),
                    ],
                    [
                        'label' => 'Consulted',
                        'data' => $orderedData->pluck('consulted'),
                    ],
                    [
                        'label' => 'Not Consulted',
                        'data' => $orderedData->pluck('not_consulted'),
                    ],
                    [
                        'label' => 'New Visits',
                        'data' => $orderedData->pluck('new_visits'),
                    ],
                    [
                        'label' => 'Follow-ups',
                        'data' => $orderedData->pluck('followups'),
                    ]
                ]
            ];
        });
    }

    /**
     * Get detailed referral hospital distribution.
     */
    #[OA\Get(
        path: '/dashboard/referral-stats',
        summary: 'Referral hospital distribution for a date range',
        description: 'Reads from pre-aggregated daily_referral_stats table. Returns top referring hospitals and their visit counts.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Referral stats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'ref_hosp_nm', type: 'string', example: 'Muhimbili National Hospital'),
                        new OA\Property(property: 'total', type: 'integer', example: 45),
                    ])),
                ])
            ),
        ]
    )]
    public function getReferralStats(Request $request)
    {
        $startDate = $request->query('start_date', date('Y-m-d'));
        $endDate = $request->query('end_date', date('Y-m-d'));

        $cacheKey = $this->cacheKey('referral_stats', $startDate, $endDate);
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 30 : 300;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate) {
            // Use pre-aggregated DailyReferralStat for much better performance
            // Group strictly by CODE to merge duplicates where names might differ slightly
            $stats = DailyReferralStat::where('stat_date', '>=', $startDate)
                ->where('stat_date', '<=', $endDate)
                ->select('ref_hosp_code as code', DB::raw('MAX(ref_hosp_name) as name'), DB::raw('SUM(count) as total'))
                ->groupBy('ref_hosp_code')
                ->orderByDesc('total')
                ->get();

            if ($stats->isEmpty()) {
                // Return empty instead of hanging the UI with a 186k record scan fallback
                return collect([]);
            }

            return $stats->map(function($item) {
                return [
                    'code' => $item->code,
                    'name' => $item->name,
                    'count' => (int)$item->total
                ];
            });
        });
    }

    #[OA\Get(
        path: '/dashboard/snapshot',
        summary: 'Full dashboard snapshot — all core metrics in one request',
        description: 'Consolidates stats, clinics, pie data, and trends in a single call. Used for initial page load.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Complete dashboard snapshot',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'stats', ref: '#/components/schemas/DashboardStats'),
                    new OA\Property(property: 'clinics', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClinicBreakdown')),
                    new OA\Property(property: 'remote_api_available', type: 'boolean'),
                ])
            ),
        ]
    )]
    public function getSnapshot(Request $request)
    {
        $startDate = $request->query('start_date', date('Y-m-d'));
        $endDate = $request->query('end_date', date('Y-m-d'));
        $period = $request->query('period', 'day');

        $cacheKey = $this->cacheKey('dashboard_snapshot', $startDate, $endDate, $period, $request->query('breakdown', 'none'));
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 30 : 600;

        \Illuminate\Support\Facades\Log::info("[Dashboard] getSnapshot", ['start' => $startDate, 'end' => $endDate, 'period' => $period]);

        $data = $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($request) {
            return [
                'stats' => $this->getStats($request),
                'clinics' => $this->getClinicBreakdown($request),
                'pie' => $this->getPieStats($request),
                'comparison' => $this->getComparisonStats($request),
                'referrals' => $this->getReferralStats($request),
                'trends' => $this->getServiceTrends($request),
                'generated_at' => now()->toDateTimeString(),
            ];
        });

        // Ensure we always return the LIVE meta (including sync status) 
        // to avoid stale UI indicators in the consolidated snapshot.
        $liveStats = $this->getStats($request);
        $data['stats']['meta'] = $liveStats['meta'];

        return $data;
    }

    #[OA\Get(
        path: '/dashboard/gender-radar',
        summary: 'Monthly gender distribution for radar chart',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Gender radar data',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'labels', type: 'array', items: new OA\Items(type: 'string', example: 'Jan')),
                    new OA\Property(property: 'male', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'female', type: 'array', items: new OA\Items(type: 'integer')),
                ])
            ),
        ]
    )]
    public function getGenderRadarStats(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::today()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::today()->toDateString());

        $cacheKey = $this->cacheKey('gender_radar_stats', $startDate, $endDate);
        
        return Cache::remember($cacheKey, 3600, function() use ($startDate, $endDate) {
            $months = [];
            $tempStart = Carbon::parse($startDate)->startOfMonth();
            $tempEnd = Carbon::parse($endDate)->endOfMonth();

            while ($tempStart <= $tempEnd) {
                $monthKey = $tempStart->format('Y-m');
                $months[] = [
                    'key' => $monthKey,
                    'label' => $tempStart->format('M Y'),
                    'start' => $tempStart->copy()->startOfMonth()->toDateString(),
                    'end' => $tempStart->copy()->endOfMonth()->toDateString(),
                ];
                $tempStart->addMonth();
            }

            $datasets = [];
            foreach ($months as $month) {
                $stats = DailyDashboardStat::where('stat_date', '>=', $month['start'])
                    ->where('stat_date', '<=', $month['end'])
                    ->selectRaw('SUM(male_count) as male, SUM(female_count) as female, SUM(no_gender_count) as no_gender, SUM(unknown_gender_count) as unknown')
                    ->first();

                $datasets[] = [
                    'label' => $month['label'],
                    'male' => (int)($stats->male ?? 0),
                    'female' => (int)($stats->female ?? 0),
                    'other' => (int)($stats->no_gender ?? 0),
                    'not_specified' => (int)($stats->unknown ?? 0),
                ];
            }

            return $datasets;
        });
    }

    #[OA\Get(
        path: '/dashboard/pending-patients',
        summary: 'List of patients not yet consulted (visit_status != C)',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pending patients',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'mr_number', type: 'string'),
                        new OA\Property(property: 'clinic_name', type: 'string'),
                        new OA\Property(property: 'visit_date', type: 'string', format: 'date'),
                        new OA\Property(property: 'visit_status', type: 'string', example: 'P'),
                    ])),
                    new OA\Property(property: 'total', type: 'integer'),
                ])
            ),
        ]
    )]
    public function getPendingPatients(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');
        $clinicName = $request->query('clinic_name');

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        $clinicSuffix = $clinicName ? '_' . md5($clinicName) : '_all';
        $cacheKey = $this->cacheKey('pending_patients', $startDate, $endDate) . $clinicSuffix;
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 30 : 600;

        return Cache::remember($cacheKey, $ttl, function() use ($startDate, $endDate, $clinicName) {
            $query = Visit::where('visit_date', '>=', $startDate)
                ->where('visit_date', '<=', $endDate)
                ->where(function($q) {
                    $q->where('visit_status', '!=', 'C')
                      ->orWhereNull('visit_status');
                });

            if ($clinicName && $clinicName !== 'All Clinics') {
                $query->where('clinic_name', $clinicName);
            }

            $patients = $query
                ->select('id', 'mr_number', 'visit_date', 'cons_time', 'bill_doct_name', 'clinic_name', 'pat_age')
                ->orderBy('visit_date', 'asc')
                ->orderBy('cons_time', 'asc')
                ->orderBy('id', 'asc')
                ->limit(5000)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $patients,
                'clinic_name' => $clinicName ?? 'All Clinics',
                'total' => $patients->count(),
            ]);
        });
    }

    #[OA\Get(
        path: '/dashboard/duplicated-data',
        summary: 'Duplicate visit records detected during the last sync',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Duplicate visits',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'mr_number', type: 'string'),
                        new OA\Property(property: 'visit_date', type: 'string', format: 'date'),
                        new OA\Property(property: 'clinic_name', type: 'string'),
                        new OA\Property(property: 'occurrence_count', type: 'integer', example: 3),
                    ])),
                    new OA\Property(property: 'total', type: 'integer'),
                ])
            ),
        ]
    )]
    public function getDuplicateVisits(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $singleDate = $request->query('date');

        if (!$startDate || !$endDate) {
            $startDate = $singleDate ?? date('Y-m-d');
            $endDate = $startDate;
        }

        try {
            // Normalize dates using Carbon to handle YYYY-MM-DD, YYYYMMDD, etc.
            $startDate = \Carbon\Carbon::parse($startDate)->format('Y-m-d');
            $endDate = \Carbon\Carbon::parse($endDate)->format('Y-m-d');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[Dashboard] Date parsing failed", ['start' => $startDate, 'end' => $endDate]);
            $startDate = date('Y-m-d');
            $endDate = $startDate;
        }

        $cacheKey = $this->cacheKey('duplicate_visits', $startDate, $endDate);
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 600 : 3600;
        $fresh = filter_var($request->query('fresh', false), FILTER_VALIDATE_BOOLEAN);

        $resolver = function() use ($startDate, $endDate) {
            $duplicates = \App\Models\DuplicateVisit::select([
                    'mr_number',
                    'visit_num',
                    'visit_date',
                    'clinic_code',
                    'clinic_name',
                    'cons_time',
                    'cons_no',
                    'dept_code',
                    'dept_name',
                    'cons_doctor',
                    'pat_catg_nm',
                    \Illuminate\Support\Facades\DB::raw('MAX(occurrence_count) as occurrence_count'),
                    \Illuminate\Support\Facades\DB::raw('MAX(synchronized_at) as latest_sync_at')
                ])
                ->where('visit_date', '>=', $startDate)
                ->where('visit_date', '<=', $endDate)
                ->groupBy([
                    'mr_number',
                    'visit_num',
                    'visit_date',
                    'clinic_code',
                    'clinic_name',
                    'cons_time',
                    'cons_no',
                    'dept_code',
                    'dept_name',
                    'cons_doctor',
                    'pat_catg_nm'
                ])
                ->orderBy('occurrence_count', 'desc')
                ->orderBy('visit_date', 'desc')
                ->limit(300)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $duplicates
            ]);
        };

        if ($fresh) {
            Cache::forget($cacheKey);
            return $resolver();
        }

        return Cache::remember($cacheKey, $ttl, $resolver);
    }

    /**
     * Lightweight check to see if data has changed.
     * Super-fast polling endpoint for real-time detection.
     * No caching - always returns fresh data.
     */
    #[OA\Get(
        path: '/dashboard/check-updates',
        summary: 'Fast polling endpoint — detect data changes without fetching full stats',
        description: 'Called every 3–15 seconds by the frontend. Returns a version hash, latest visit ID, today\'s count, and sync status. Used to trigger silent refreshes only when data has actually changed. Never cached.',
        tags: ['Dashboard'],
        responses: [
            new OA\Response(response: 200, description: 'Update status',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'version', type: 'string', example: 'a1b2c3d4'),
                    new OA\Property(property: 'latest_visit_id', type: 'integer', example: 48291),
                    new OA\Property(property: 'today_count', type: 'integer', example: 348),
                    new OA\Property(property: 'total_count', type: 'integer', example: 91240),
                    new OA\Property(property: 'remote_api_available', type: 'boolean', example: true),
                    new OA\Property(property: 'is_syncing', type: 'boolean', example: false),
                    new OA\Property(property: 'active_batch_id', type: 'string', nullable: true),
                    new OA\Property(property: 'sync_needed', type: 'boolean', example: false),
                    new OA\Property(property: 'is_up_to_date', type: 'boolean', example: true),
                ])
            ),
        ]
    )]
    public function checkUpdates(Request $request)
    {
        $today = date('Y-m-d');
        
        // Get the latest visit ID (fastest way to detect new records)
        $latestVisitId = (int) Visit::max('id') ?? 0;
        
        // Get total visit count for today (detects deletes too)
        $todayVisitCount = (int) Visit::where('visit_date', $today)->count();
        
        // Get total overall visit count (detects any changes)
        $totalVisitCount = (int) Visit::count();

        // Get external count from SyncWatcher cache
        $externalCount = (int) Cache::get("sync_watcher_external_count_{$today}", 0);
        
        // If externalCount is 0, it might mean the watcher hasn't run yet or returned 0
        // If it's valid, use it to detect mismatches
        $syncNeeded = ($externalCount > $todayVisitCount);
        $isUpToDate = ($externalCount > 0 && $externalCount <= $todayVisitCount);
        
        // Get the latest sync timestamp from aggregated stats
        $latestStat = DailyDashboardStat::select('updated_at')
            ->orderByDesc('updated_at')
            ->first();
        $latestClinic = ClinicStat::select('updated_at')
            ->orderByDesc('updated_at')
            ->first();
        
        $statTimestamp = $latestStat ? $latestStat->updated_at->timestamp : 0;
        $clinicTimestamp = $latestClinic ? $latestClinic->updated_at->timestamp : 0;
        
        // Check if a sync is currently active in the queue (excluding background auto-syncs)
        $activeBatch = DB::table('job_batches')
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('name', 'NOT LIKE', 'auto-sync:%')
            ->latest('created_at')
            ->first();
        
        $isSyncing = ($activeBatch !== null);
        
        // Background batch for silent tracking if no manual batch is active
        $backgroundBatch = null;
        if (!$isSyncing) {
            $backgroundBatch = DB::table('job_batches')
                ->whereNull('finished_at')
                ->whereNull('cancelled_at')
                ->where('name', 'LIKE', 'auto-sync:%')
                ->latest('created_at')
                ->first();
        }
        
        // Combine all signals into a version hash
        $version = md5(
            $latestVisitId . '_' .
            $todayVisitCount . '_' .
            $totalVisitCount . '_' .
            $statTimestamp . '_' .
            $clinicTimestamp . '_' .
            $externalCount . '_' .
            ($isSyncing ? '1' : '0')
        );

        return response()->json([
            'version' => $version,
            'latest_visit_id' => $latestVisitId,
            'today_count' => $todayVisitCount,
            'total_count' => $totalVisitCount,
            'external_count' => $externalCount,
            'sync_needed' => $syncNeeded,
            'is_up_to_date' => $isUpToDate,
            'is_syncing' => $isSyncing,
            'active_batch_id' => $activeBatch ? $activeBatch->id : ($backgroundBatch ? $backgroundBatch->id : null),
            'is_silent_sync' => (!$isSyncing && $backgroundBatch !== null),
            'remote_api_available' => $this->getRemoteApiAvailability(),
            'stat_updated' => $statTimestamp,
            'clinic_updated' => $clinicTimestamp,
            'timestamp' => now()->toDateTimeString(),
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }

    #[OA\Get(
        path: '/dashboard/top-diseases',
        summary: 'Top ICD-10 diagnoses with per-clinic breakdown',
        description: 'Returns the most frequent diagnoses (provisional + final) enriched with ICD-10 descriptions and abbreviations. Used for the stacked horizontal bar chart on the Admin Dashboard.',
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-01')
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17')
            ),
            new OA\Parameter(name: 'limit', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', default: 10, example: 10),
                description: 'Number of top diseases to return'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Top diseases with clinic breakdown',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'J06.9'),
                        new OA\Property(property: 'description', type: 'string', example: 'Acute upper respiratory infection'),
                        new OA\Property(property: 'abbreviation', type: 'string', nullable: true, example: 'URTI'),
                        new OA\Property(property: 'total', type: 'integer', example: 120),
                        new OA\Property(property: 'by_clinic', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'clinic_name', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                        ])),
                    ])),
                ])
            ),
        ]
    )]
    public function getTopDiseases(Request $request)
    {
        $startDate = $request->query('start_date', date('Y-m-d'));
        $endDate = $request->query('end_date', date('Y-m-d'));
        $clinicName = $request->query('clinic_name');

        $cacheKey = $this->cacheKey('top_diseases', $startDate, $endDate, $clinicName ?? 'all');
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 60 : 600;

        return $this->rememberUnlessFresh($request, $cacheKey, $ttl, function() use ($startDate, $endDate, $clinicName) {
            // Extraction logic: get everything before the first separator ($, ;, ,)
            // SQL regex or nested SUBSTRING_INDEX
            $diagExpr = "TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(final_diag), ''), TRIM(prov_diag)), '$', 1), ';', 1), ',', 1))";

            $query = Visit::where('visit_date', '>=', $startDate)
                ->where('visit_date', '<=', $endDate)
                ->whereNotNull(DB::raw("COALESCE(NULLIF(TRIM(final_diag), ''), TRIM(prov_diag))"))
                ->where(DB::raw("COALESCE(NULLIF(TRIM(final_diag), ''), TRIM(prov_diag))"), '!=', '');

            if ($clinicName && $clinicName !== 'All Clinics') {
                $query->where('clinic_name', $clinicName);
            }

            $rawResults = $query->select(
                    DB::raw("$diagExpr as diagnosis_code"),
                    'dept_name',
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('diagnosis_code', 'dept_name')
                ->get();

            if ($rawResults->isEmpty()) {
                return [];
            }

            // Aggregate totals per disease to find the Top 10
            $diseaseTotals = $rawResults->groupBy('diagnosis_code')->map(fn($group) => $group->sum('count'))->sortDesc()->take(10);
            $topCodes = $diseaseTotals->keys();

            // Fetch ICD metadata for the top codes
            $metadata = \App\Models\Icd::whereIn('code', $topCodes)->get()->keyBy('code');

            // Format for stacked chart: each disease is a bar, stacks are departments
            $data = $topCodes->map(function($code) use ($rawResults, $metadata, $diseaseTotals) {
                $meta = $metadata->get($code);
                $deptBreakdown = $rawResults->where('diagnosis_code', $code)->pluck('count', 'dept_name');
                
                return [
                    'code' => $code,
                    'name' => $meta ? ($meta->abbreviation ?: $meta->description) : $code,
                    'full_description' => $meta ? $meta->description : $code,
                    'total' => (int)$diseaseTotals->get($code),
                    'departments' => $deptBreakdown->map(fn($v) => (int)$v)
                ];
            });

            return array_values($data->toArray());
        });
    }
}
