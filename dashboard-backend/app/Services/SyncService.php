<?php

namespace App\Services;

use App\Models\Visit;
use App\Models\SyncLog;
use App\Models\DailyDashboardStat;
use App\Models\ClinicStat;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DailyReferralStat;
use App\Models\DuplicateVisit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class SyncService
{
    private function getCacheVersion(): int
    {
        return config('dashboard.cache_version', 10);
    }

    protected static $cachedClinics = [];
    protected static $cachedDepts = [];
    protected static $cachedDoctors = [];

    /**
     * Clear all dashboard caches for a specific date.
     * This ensures UI gets fresh data immediately after sync.
     */
    public function clearCacheForDate($date)
    {
        $today = date('Y-m-d');
        $v = $this->getCacheVersion();
        $dt = Carbon::parse($date);
        
        // Calculate month and year boundaries for this date
        $monthStart = $dt->copy()->startOfMonth()->toDateString();
        $monthEnd = $dt->copy()->endOfMonth()->toDateString();
        $yearStart = $dt->copy()->startOfYear()->toDateString();
        $yearEnd = $dt->copy()->endOfYear()->toDateString();

        // Clear caches that include this date
        $keysToForget = [
            // Single date caches
            "dashboard_stats_{$date}_{$date}_v{$v}",
            "clinic_breakdown_{$date}_{$date}_v{$v}",
            "pie_stats_{$date}_{$date}_v{$v}",
            "comp_stats_{$date}_{$date}_v{$v}",
            "referral_stats_{$date}_{$date}_v{$v}",
            "duplicate_visits_{$date}_{$date}_v{$v}",
            // Today's caches (always clear if syncing today)
            "dashboard_stats_{$today}_{$today}_v{$v}",
            "clinic_breakdown_{$today}_{$today}_v{$v}",
            "pie_stats_{$today}_{$today}_v{$v}",
            "comp_stats_{$today}_{$today}_v{$v}",
            "referral_stats_{$today}_{$today}_v{$v}",
            "duplicate_visits_{$today}_{$today}_v{$v}",
            // Snapshot caches (multiple defaults)
            "dashboard_snapshot_{$date}_{$date}_day_none_v{$v}",
            "dashboard_snapshot_{$date}_{$date}_range_none_v{$v}",
            "dashboard_snapshot_{$today}_{$today}_day_none_v{$v}",
            "dashboard_snapshot_{$today}_{$today}_range_none_v{$v}",
            // RANGE Snapshot caches (Month/Year)
            "dashboard_snapshot_{$monthStart}_{$monthEnd}_month_none_v{$v}",
            "dashboard_snapshot_{$yearStart}_{$yearEnd}_year_none_v{$v}",
            "dashboard_snapshot_{$monthStart}_{$monthEnd}_month_monthly_v{$v}",
            "dashboard_snapshot_{$yearStart}_{$yearEnd}_year_monthly_v{$v}",
            "duplicate_visits_{$monthStart}_{$monthEnd}_v{$v}",
            "duplicate_visits_{$yearStart}_{$yearEnd}_v{$v}",
        ];

        // Clear detailed_clinics cache - need to clear ALL page/per_page combinations
        // Common per_page values: 100, 250, 500, 1000
        $perPageValues = [100, 250, 500, 1000];
        $maxPages = 50;
        foreach ($perPageValues as $perPage) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $keysToForget[] = "detailed_clinics_{$date}_{$date}_{$page}_{$perPage}_v{$v}";
                $keysToForget[] = "detailed_clinics_{$today}_{$today}_{$page}_{$perPage}_v{$v}";
                $keysToForget[] = "detailed_clinics_{$monthStart}_{$monthEnd}_{$page}_{$perPage}_v{$v}";
                $keysToForget[] = "detailed_clinics_{$yearStart}_{$yearEnd}_{$page}_{$perPage}_v{$v}";
            }
        }

        foreach ($keysToForget as $key) {
            Cache::forget($key);
        }

        // Also clear service trends caches (multiple periods)
        $periods = ['day', 'week', 'month', 'year', 'range'];
        foreach ($periods as $period) {
            Cache::forget("service_trends_{$period}_{$date}_{$date}_default_v{$v}");
            Cache::forget("service_trends_{$period}_{$date}_{$date}_monthly_v{$v}");
            Cache::forget("service_trends_{$period}_{$today}_{$today}_default_v{$v}");
            Cache::forget("service_trends_{$period}_{$today}_{$today}_monthly_v{$v}");
            // Clear Month/Year trends
            Cache::forget("service_trends_{$period}_{$monthStart}_{$monthEnd}_default_v{$v}");
            Cache::forget("service_trends_{$period}_{$monthStart}_{$monthEnd}_monthly_v{$v}");
            Cache::forget("service_trends_{$period}_{$yearStart}_{$yearEnd}_default_v{$v}");
            Cache::forget("service_trends_{$period}_{$yearStart}_{$yearEnd}_monthly_v{$v}");
        }

        Log::info("[SyncService] Cache cleared for date: {$date}");
    }

    /**
     * Clear stale sync logs that have been stuck for too long.
     */
    public function cleanupStaleSyncLogs($minutes = 15)
    {
        $staleLogs = SyncLog::where('status', 'PROCESSING')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($staleLogs as $log) {
            $log->update([
                'status' => 'FAILED',
                'error_message' => 'Stale process cleared by maintenance after ' . $minutes . ' minutes',
                'finished_at' => now(),
            ]);
            Log::warning("[SyncService] Cleared stale sync log ID: {$log->id} for date: {$log->sync_date}");
        }

        // Also cleanup stale job batches (zombies)
        $this->cleanupStaleBatches($minutes);

        return count($staleLogs);
    }

    /**
     * Cancel batches that have been unfinished for too long (zombies).
     */
    public function cleanupStaleBatches($minutes = 30)
    {
        $staleThreshold = now()->subMinutes($minutes)->getTimestamp();
        
        $affected = DB::table('job_batches')
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('created_at', '<', $staleThreshold)
            ->update([
                'cancelled_at' => $staleThreshold,
                'finished_at' => $staleThreshold
            ]);

        if ($affected > 0) {
            Log::info("[SyncService] Cleaned up {$affected} stale job batches older than {$minutes} minutes.");
        }

        return $affected;
    }

    /**
     * Unified sync for a specific date (Y-m-d)
     * Wrapped in transaction for integrity.
     */
    public function syncForDate($date, $force = false)
    {
        $lockFile = storage_path("framework/cache/sync_{$date}.lock");
        $fp = fopen($lockFile, 'w+');
        
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            Log::warning("[SyncService] Sync already in progress for date: {$date}");
            fclose($fp);
            return ['success' => false, 'error' => 'Sync already in progress for this date'];
        }

        try {
            $dateYmd = Carbon::parse($date)->format('Ymd');
            $baseUrl = config('dashboard.sync.base_url', env('DASHBOARD_API_BASE_URL', 'http://192.168.235.250/labsms/swagger/mnh_dashboard'));
            $url = "{$baseUrl}/{$dateYmd}";
            
            $syncLog = SyncLog::updateOrCreate(
                ['sync_date' => $date, 'sync_type' => 'visits'],
                [
                    'status' => 'PROCESSING',
                    'records_synced' => 0,
                    'error_message' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                ]
            );

            $username = config('dashboard.sync.username', env('DASHBOARD_API_USERNAME'));
            $password = config('dashboard.sync.password', env('DASHBOARD_API_PASSWORD'));

            $response = Http::withBasicAuth($username, $password)
                ->connectTimeout(15)
                ->timeout(120)
                ->retry(3, 2000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
                ->get($url);

            if ($response->successful()) {
                $body = $response->body();
                
                // Check if response is valid JSON
                if (strpos(trim($body), '{') !== 0) {
                     $syncLog->update([
                        'status' => 'FAILED',
                        'error_message' => "HIS API returned non-JSON data (possibly Debug HTML). Snippet: " . substr(strip_tags($body), 0, 200),
                        'finished_at' => now(),
                    ]);
                    $this->clearCacheForDate($date);
                    
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    @unlink($lockFile);
                    
                    return ['success' => false, 'error' => 'Invalid JSON from HIS'];
                }

                $data = $response->json();
                $visits = $data['data'] ?? [];
                
                if (empty($visits) && !empty($data)) {
                     Log::info("[SyncService] API returned success but empty 'data' array for $date");
                }

                // 1. Extract and Persist Master Data (Clinics, Departments, Doctors)
                // We do this BEFORE the transaction to avoid holding global locks during heavy processing
                $masterData = $this->extractMasterData($visits);
                $this->persistMasterData('clinics', $masterData['clinics'], ['clinic_code'], ['clinic_name']);
                $this->persistMasterData('departments', $masterData['departments'], ['dept_code'], ['dept_name']);
                $this->persistMasterData('doctors', $masterData['doctors'], ['doctor_code'], ['doctor_code']);

                // 2. Perform main visit sync in a transaction
                $syncedCount = DB::transaction(function () use ($visits, $date, $force) {
                    if ($force) {
                        $this->purgeDateData($date);
                    }

                    return $this->bulkUpsertVisits($visits);
                });

                // 3. Post-sync processing (Aggregated stats & healing)
                try {
                    $this->updateAggregatedStats($date);
                    $this->healMissingData($date); // Fast: uses 2 bulk JOIN-UPDATE queries
                } catch (\Exception $e) {
                    Log::warning("[SyncService] Post-sync processing failed for $date: " . $e->getMessage());
                }

                $syncLog->update([
                    'status' => 'SUCCESS',
                    'records_synced' => $syncedCount,
                    'finished_at' => now(),
                ]);

                // Removed redundant clearCacheForDate($date) here as updateAggregatedStats already does it.

                flock($fp, LOCK_UN);
                fclose($fp);
                @unlink($lockFile);

                return ['success' => true, 'count' => $syncedCount];
            }

            $syncLog->update([
                'status' => 'FAILED',
                'error_message' => "API error: " . $response->status(),
                'finished_at' => now(),
            ]);
            
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
            
            return ['success' => false, 'error' => "API error: " . $response->status()];

        } catch (\Exception $e) {
            Log::error("Sync failed for date {$date}: " . $e->getMessage());
            $syncLog->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Alias for backward compatibility in jobs
     */
    public function syncForDateOptimized($date, $force = false)
    {
        return $this->syncForDate($date, $force);
    }

    private function purgeDateData(string $date): void
    {
        Visit::where('visit_date', $date)->delete();
        DuplicateVisit::where('visit_date', $date)->delete();
        DailyDashboardStat::where('stat_date', $date)->delete();
        ClinicStat::where('stat_date', $date)->delete();
        DailyReferralStat::where('stat_date', $date)->delete();

        $this->clearCacheForDate($date);
        Log::info("[SyncService] Purged existing dashboard data for forced re-sync", ['date' => $date]);
    }

    /**
     * Sync a range of dates in parallel batches.
     */
    public function syncDateRange($startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $results = [
            'total_synced_days' => 0,
            'errors' => []
        ];

        // Process in chunks of 20 parallel requests (Optimized for speed)
        $chunks = array_chunk($dates, 20);
        $username = config('dashboard.sync.username', env('DASHBOARD_API_USERNAME'));
        $password = config('dashboard.sync.password', env('DASHBOARD_API_PASSWORD'));
        $baseUrl = config('dashboard.sync.base_url', env('DASHBOARD_API_BASE_URL', 'http://192.168.235.250/labsms/swagger/mnh_dashboard'));

        // For larger ranges, we should ALWAYS use the background queue to avoid timeouts
        // and ensure each date is retried independently.
        $jobs = [];
        foreach ($dates as $date) {
            $jobs[] = new \App\Jobs\SyncForDateJob($date);
        }

        if (empty($jobs)) return $results;

        $batch = \Illuminate\Support\Facades\Bus::batch($jobs)
            ->name("sync_range_{$startDate}_{$endDate}")
            ->dispatch();

        return [
            'total_synced_days' => 0,
            'batch_id' => $batch->id,
            'message' => 'Sync enqueued for background processing.'
        ];
    }

    /**
     * Strictly validate and aggregate visits locally before dashboard view.
     */
    public function updateAggregatedStats($date)
    {
        $stats = Visit::where('visit_date', $date)
            ->selectRaw('
                COUNT(*) as total_visits,
                SUM(CASE WHEN visit_status = "C" THEN 1 ELSE 0 END) as consulted,
                SUM(CASE WHEN (visit_status IS NULL OR visit_status != "C") THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN visit_type = "N" THEN 1 ELSE 0 END) as new_visits,
                SUM(CASE WHEN visit_type = "F" THEN 1 ELSE 0 END) as followups,
                SUM(CASE WHEN is_nhif = "Y" THEN 1 ELSE 0 END) as nhif_visits,
                SUM(CASE WHEN pat_catg = "016" THEN 1 ELSE 0 END) as foreigner,
                SUM(CASE WHEN pat_catg = "001" THEN 1 ELSE 0 END) as public,
                SUM(CASE WHEN pat_catg_nm LIKE "%IPPM%PRIVATE%" THEN 1 ELSE 0 END) as ippm_private,
                SUM(CASE WHEN pat_catg_nm LIKE "%IPPM%CREDIT%" THEN 1 ELSE 0 END) as ippm_credit,
                SUM(CASE WHEN pat_catg_nm LIKE "%COST%SHARING%" THEN 1 ELSE 0 END) as cost_sharing,
                SUM(CASE WHEN pat_catg_nm LIKE "%NSSF%" THEN 1 ELSE 0 END) as nssf,
                SUM(CASE WHEN dept_code = "150" THEN 1 ELSE 0 END) as emergency,
                SUM(CASE WHEN gender = "M" THEN 1 ELSE 0 END) as male,
                SUM(CASE WHEN gender = "F" THEN 1 ELSE 0 END) as female,
                SUM(CASE WHEN gender = "N" THEN 1 ELSE 0 END) as no_gender,
                SUM(CASE WHEN gender IS NULL THEN 1 ELSE 0 END) as unknown_gender,
                SUM(CASE WHEN pat_age IS NOT NULL AND (
                    (pat_age LIKE "%.%.%" AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) = 0 AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pat_age, ".", 2), ".", -1) AS UNSIGNED) = 0 AND CAST(SUBSTRING_INDEX(pat_age, ".", -1) AS UNSIGNED) BETWEEN 0 AND 28) OR
                    (pat_age NOT LIKE "%.%" AND CAST(pat_age AS UNSIGNED) = 0)
                ) THEN 1 ELSE 0 END) as neonate,
                SUM(CASE WHEN pat_age IS NOT NULL AND (
                    (pat_age LIKE "%.%.%" AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) = 0 AND NOT (CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pat_age, ".", 2), ".", -1) AS UNSIGNED) = 0 AND CAST(SUBSTRING_INDEX(pat_age, ".", -1) AS UNSIGNED) BETWEEN 0 AND 28)) OR
                    (pat_age LIKE "%.%" AND pat_age NOT LIKE "%.%.%" AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) = 0)
                ) THEN 1 ELSE 0 END) as infant,
                SUM(CASE WHEN pat_age IS NOT NULL AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) BETWEEN 1 AND 12 THEN 1 ELSE 0 END) as child,
                SUM(CASE WHEN pat_age IS NOT NULL AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) BETWEEN 13 AND 17 THEN 1 ELSE 0 END) as adolescent,
                SUM(CASE WHEN pat_age IS NOT NULL AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) as adult,
                SUM(CASE WHEN pat_age IS NOT NULL AND CAST(SUBSTRING_INDEX(pat_age, ".", 1) AS UNSIGNED) >= 60 THEN 1 ELSE 0 END) as elderly,
                SUM(CASE WHEN (cons_doctor IS NOT NULL AND doct_code IS NOT NULL AND TRIM(cons_doctor) = TRIM(doct_code)) THEN 1 ELSE 0 END) as matches,
                SUM(CASE WHEN (cons_doctor IS NOT NULL AND doct_code IS NOT NULL AND TRIM(cons_doctor) != TRIM(doct_code)) THEN 1 ELSE 0 END) as mismatches
            ')
            ->first();

        $duplicatesCount = \App\Models\DuplicateVisit::where('visit_date', $date)->count();

        DailyDashboardStat::updateOrCreate(
            ['stat_date' => $date],
            [
                'total_visits' => (int)($stats->total_visits ?? 0),
                'consulted' => (int)($stats->consulted ?? 0),
                'pending' => (int)($stats->pending ?? 0),
                'matched_count' => (int)($stats->matches ?? 0),
                'mismatched_count' => (int)($stats->mismatches ?? 0),
                'new_visits' => (int)($stats->new_visits ?? 0),
                'followups' => (int)($stats->followups ?? 0),
                'nhif_visits' => (int)($stats->nhif_visits ?? 0),
                'emergency' => (int)($stats->emergency ?? 0),
                'foreigner' => (int)($stats->foreigner ?? 0),
                'public' => (int)($stats->public ?? 0),
                'ippm_private' => (int)($stats->ippm_private ?? 0),
                'ippm_credit' => (int)($stats->ippm_credit ?? 0),
                'cost_sharing' => (int)($stats->cost_sharing ?? 0),
                'nssf' => (int)($stats->nssf ?? 0),
                'male_count' => (int)($stats->male ?? 0),
                'female_count' => (int)($stats->female ?? 0),
                'no_gender_count' => (int)($stats->no_gender ?? 0),
                'unknown_gender_count' => (int)($stats->unknown_gender ?? 0),
                'neonate_count' => (int)($stats->neonate ?? 0),
                'infant_count' => (int)($stats->infant ?? 0),
                'child_count' => (int)($stats->child ?? 0),
                'adolescent_count' => (int)($stats->adolescent ?? 0),
                'adult_count' => (int)($stats->adult ?? 0),
                'elderly_count' => (int)($stats->elderly ?? 0),
                'duplicates' => (int)$duplicatesCount,
            ]
        );

        $clinicData = Visit::where('visit_date', $date)
            ->groupBy('clinic_code', 'clinic_name')
            ->select(
                'clinic_code', 
                'clinic_name', 
                DB::raw('COUNT(*) as total_visits'),
                DB::raw('SUM(CASE WHEN visit_status = "C" THEN 1 ELSE 0 END) as consulted'),
                DB::raw('SUM(CASE WHEN (visit_status IS NULL OR visit_status != "C") THEN 1 ELSE 0 END) as pending')
            )
            ->get();

        if ($clinicData->isNotEmpty()) {
            $clinicBatch = $clinicData->map(function ($item) use ($date) {
                return [
                    'stat_date' => $date,
                    'clinic_code' => $item->clinic_code,
                    'clinic_name' => $item->clinic_name ?: 'Unknown Clinic',
                    'total_visits' => (int)$item->total_visits,
                    'consulted' => (int)$item->consulted,
                    'pending' => (int)$item->pending
                ];
            })->toArray();

            ClinicStat::upsert($clinicBatch, ['stat_date', 'clinic_code'], ['clinic_name', 'total_visits', 'consulted', 'pending']);
        }

        // 3. Pre-aggregate Referral Stats
        // Always delete existing referral stats first to prevent duplication
        DailyReferralStat::where('stat_date', $date)->delete();
        
        $referralData = Visit::where('visit_date', $date)
            ->whereNotNull('ref_hosp')
            ->where('ref_hosp', '!=', '')
            ->groupBy('ref_hosp', 'ref_hosp_nm')
            ->select('ref_hosp as code', 'ref_hosp_nm as name', DB::raw('COUNT(*) as total'))
            ->get();

        if ($referralData->isNotEmpty()) {
            $referralBatch = $referralData->map(function ($item) use ($date) {
                $name = $item->name;
                // Explicit fix for Self Referral code 000037 which often has missing name
                if ($item->code === '000037' && (empty($name) || stripos($name, 'Facility') !== false)) {
                    $name = 'SELF REFERRAL';
                }
                return [
                    'stat_date' => $date,
                    'ref_hosp_code' => $item->code,
                    'ref_hosp_name' => $name,
                    'count' => (int)$item->total
                ];
            })->toArray();

            DailyReferralStat::insert($referralBatch);
        }
        
        // Clear dashboard caches for this specific date (versioned keys)
        $v = $this->getCacheVersion();
        $cacheKeys = [
            "dashboard_stats_{$date}_{$date}_v{$v}",
            "clinic_breakdown_{$date}_{$date}_v{$v}",
            "pie_stats_{$date}_{$date}_v{$v}",
            "comp_stats_{$date}_{$date}_v{$v}",
            "referral_stats_{$date}_{$date}_v{$v}",
            "dashboard_snapshot_{$date}_{$date}_day_none_v{$v}",
        ];

        // NEW: Also clear Month and Year ranges that contain this date
        // This prevents the "Selected August shows 0" issue if it was cached while empty
        $carbon = Carbon::parse($date);
        $monthStart = $carbon->copy()->startOfMonth()->toDateString();
        $monthEnd = $carbon->copy()->endOfMonth()->toDateString();
        $yearStart = $carbon->copy()->startOfYear()->toDateString();
        $yearEnd = $carbon->copy()->endOfYear()->toDateString();

        $cacheKeys[] = "dashboard_stats_{$monthStart}_{$monthEnd}_v{$v}";
        $cacheKeys[] = "clinic_breakdown_{$monthStart}_{$monthEnd}_v{$v}";
        $cacheKeys[] = "pie_stats_{$monthStart}_{$monthEnd}_v{$v}";
        $cacheKeys[] = "dashboard_stats_{$yearStart}_{$yearEnd}_v{$v}";
        // Clear referral_stats for month/year ranges
        $cacheKeys[] = "referral_stats_{$monthStart}_{$monthEnd}_v{$v}";
        $cacheKeys[] = "referral_stats_{$yearStart}_{$yearEnd}_v{$v}";

        // Also clear service_trends caches (all period types)
        foreach (['day', 'week', 'month', 'year', 'range'] as $period) {
            \Illuminate\Support\Facades\Cache::forget("service_trends_{$period}_{$date}_{$date}_default_v{$v}");
            \Illuminate\Support\Facades\Cache::forget("service_trends_{$period}_{$date}_{$date}_monthly_v{$v}");
        }

        foreach ($cacheKeys as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
    }

    /**
     * Bulk upsert visits with strict field cleaning.
     */
    private function bulkUpsertVisits(array $visits)
    {
        if (empty($visits)) {
            return 0;
        }

        $preparedVisits = [];
        $now = now();
        $skippedRecords = [];
        $seenInBatch = []; // NEW: Fix undefined variable
        $duplicateRecords = []; // NEW: Fix undefined variable

        foreach ($visits as $index => $visitData) {
            // Trim and validate all incoming fields using faster native array_map
            $cleanData = array_map(function ($value) {
                if (is_string($value)) {
                    $v = trim($value);
                    return $v === '' ? null : $v;
                }
                return $value;
            }, $visitData);

            $visitDateOrig = $cleanData['visitDate'] ?? null;
            if (!$visitDateOrig) {
                $skippedRecords[] = [
                    'index' => $index,
                    'reason' => 'Missing visitDate',
                    'mr_number' => $cleanData['mrNumber'] ?? 'unknown',
                    'visit_num' => $cleanData['visitNum'] ?? 'unknown',
                ];
                continue;
            }

            // Format dates
            $visitDate = $visitDateOrig;
            if (strlen($visitDate) === 8 && is_numeric($visitDate)) {
                 $visitDate = substr($visitDate, 0, 4) . '-' . substr($visitDate, 4, 2) . '-' . substr($visitDate, 6, 2);
            }

            $doctConsDt = $cleanData['doctConsDt'] ?? null;
            if ($doctConsDt && strlen($doctConsDt) === 8 && is_numeric($doctConsDt)) {
                 $doctConsDt = substr($doctConsDt, 0, 4) . '-' . substr($doctConsDt, 4, 2) . '-' . substr($doctConsDt, 6, 2);
            }

            // Category logic
            $patCatgNm = $cleanData['patCatgNm'] ?? '';
            $isNhif = (stripos($patCatgNm, 'NHIF') !== false) ? 'Y' : 'N';

            // Master data prep removed from here - now handled by extractMasterData()

            // Generate fallback cons_no if missing - ensures uniqueness for multiple consultations
            $consNo = $cleanData['consNo'] ?? null;
            // New API sends boolean false instead of null when no consNo
            if (empty($consNo) || $consNo === false) {
                $consTime = $cleanData['consTime'] ?? null;
                $timePart = $consTime ? str_replace(':', '', $consTime) : $index;
                $consNo = ($cleanData['visitNum'] ?? '') . '-' . $timePart;
            }

            $visitRecord = [
                'mr_number' => $cleanData['mrNumber'],
                'visit_num' => $cleanData['visitNum'],
                'visit_date' => $visitDate,
                'visit_type' => substr($cleanData['visitType'] ?? 'N', 0, 1),
                'doct_code' => $cleanData['doctCode'] ?? null,
                'bill_doct_name' => $cleanData['billDoctName'] ?? null,
                'cons_time' => $cleanData['consTime'] ?? null,
                'cons_no' => $consNo,
                'clinic_code' => $cleanData['clinicCode'],
                'clinic_name' => $cleanData['clinicName'] ?: 'Unknown Clinic',
                'cons_doctor' => $cleanData['consDoctor'] ?? null,
                'visit_status' => substr($cleanData['visitStatus'] ?? 'P', 0, 1),
                'accomp_code' => $cleanData['accompCode'] ?? null,
                'doct_cons_dt' => $doctConsDt,
                'doct_cons_tm' => $cleanData['doctConsTm'] ?? null,
                'dept_code' => $cleanData['deptCode'],
                'dept_name' => $cleanData['deptName'] ?: 'Unknown Dept',
                'pat_catg' => $cleanData['patCatg'] ?? null,
                'ref_hosp' => $cleanData['refHosp'] ?? null,
                'ref_hosp_nm' => $cleanData['refHospName'] ?? null,
                'nhi_yn' => substr($cleanData['nhiYn'] ?? 'N', 0, 1),
                'pat_catg_nm' => $patCatgNm,
                'status' => substr($cleanData['status'] ?? 'A', 0, 1),
                'is_nhif' => $isNhif,
                'gender' => isset($cleanData['patGender']) ? substr($cleanData['patGender'], 0, 1) : null,
                'cons_doctor_name' => $cleanData['consDoctName'] ?? null,
                'pat_age' => $cleanData['patAge'] ?? null,
                'prov_diag' => $cleanData['provDiag'] ?? null,
                'final_diag' => $cleanData['finalDiag'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // DUPLICATE DETECTION LOGIC
            // Criteria: mr_number, visit_num, visit_date, clinic_code, dept_code, cons_no
            $uniqueKey = "{$visitRecord['mr_number']}_{$visitRecord['visit_num']}_{$visitRecord['visit_date']}_{$visitRecord['clinic_code']}_{$visitRecord['dept_code']}_{$visitRecord['cons_no']}";
            
            if (isset($seenInBatch[$uniqueKey])) {
                $seenInBatch[$uniqueKey]['occurrence_count']++;
                $duplicateRecords[$uniqueKey] = $seenInBatch[$uniqueKey];
                continue;
            }

            $seenInBatch[$uniqueKey] = [
                ...$visitRecord,
                'occurrence_count' => 1,
            ];
            $preparedVisits[] = $visitRecord;
        }

        // Store duplicates in the dedicated table
        if (!empty($duplicateRecords)) {
            $duplicateBatch = collect(array_values($duplicateRecords))->map(function($record) use ($now) {
                return [
                    'mr_number' => $record['mr_number'],
                    'visit_num' => $record['visit_num'],
                    'visit_date' => $record['visit_date'],
                    'clinic_code' => $record['clinic_code'],
                    'clinic_name' => $record['clinic_name'],
                    'cons_time' => $record['cons_time'],
                    'cons_no' => $record['cons_no'],
                    'dept_code' => $record['dept_code'],
                    'dept_name' => $record['dept_name'],
                    'cons_doctor' => $record['cons_doctor'],
                    'pat_catg_nm' => $record['pat_catg_nm'],
                    'occurrence_count' => $record['occurrence_count'],
                    'synchronized_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->toArray();

            foreach (array_chunk($duplicateBatch, 400) as $chunk) {
                \App\Models\DuplicateVisit::upsert(
                    $chunk,
                    ['mr_number', 'visit_num', 'visit_date', 'clinic_code', 'dept_code', 'cons_no'],
                    ['clinic_name', 'cons_time', 'dept_name', 'cons_doctor', 'pat_catg_nm', 'occurrence_count', 'synchronized_at', 'updated_at']
                );
            }
            
            Log::info("[SyncService] Captured/Updated " . count($duplicateRecords) . " duplicate consultations.");
        }

        // Master upserts removed from here - now handled explicitly in syncForDate()

        // Visits upsert in chunks - Ensure we use the exact unique keys from migration
        // Including cons_no ensures each consultation is counted separately
        foreach (array_chunk($preparedVisits, 1000) as $chunk) {
            Visit::upsert(
                $chunk,
                ['mr_number', 'visit_num', 'visit_date', 'clinic_code', 'dept_code', 'cons_no'], // Matches visits_encounter_unique
                [
                    'visit_type', 'doct_code', 'bill_doct_name', 'cons_time',
                    'clinic_name', 'cons_doctor', 'visit_status', 'accomp_code', 
                    'doct_cons_dt', 'doct_cons_tm', 'dept_name', 
                    'pat_catg', 'ref_hosp', 'ref_hosp_nm', 'nhi_yn', 'pat_catg_nm', 'status', 
                    'is_nhif', 'gender', 'cons_doctor_name', 'pat_age', 'prov_diag', 'final_diag', 'updated_at'
                ]
            );
        }

        // Log skipped records for debugging
        if (!empty($skippedRecords)) {
            Log::warning('[SyncService] Skipped records during bulk upsert', [
                'skipped_count' => count($skippedRecords),
                'records' => array_slice($skippedRecords, 0, 10), // Log first 10 only
            ]);
        }

        return count($preparedVisits);
    }

    public function healMissingData($date = null)
    {
        Log::info("[SyncService] Starting Surgical Healing " . ($date ? "for $date" : "for all records"));
        $healedCount = 0;
        $v = $this->getCacheVersion();

        // 1. Resolve Bill Doctor Names with ONE bulk query
        $billHealed = DB::update("
            UPDATE visits v1
            JOIN (
                SELECT doct_code, MAX(bill_doct_name) as name 
                FROM visits 
                WHERE bill_doct_name IS NOT NULL AND bill_doct_name != '' 
                GROUP BY doct_code
            ) v2 ON v1.doct_code = v2.doct_code
            SET v1.bill_doct_name = v2.name, v1.updated_at = NOW()
            WHERE (v1.bill_doct_name IS NULL OR v1.bill_doct_name = '')
            " . ($date ? "AND v1.visit_date = ?" : ""), $date ? [$date] : []);

        // 2. Resolve Cons Doctor Names with ONE bulk query
        $consHealed = DB::update("
            UPDATE visits v1
            JOIN (
                SELECT cons_doctor, MAX(cons_doctor_name) as name 
                FROM visits 
                WHERE cons_doctor_name IS NOT NULL AND cons_doctor_name != '' 
                GROUP BY cons_doctor
            ) v2 ON v1.cons_doctor = v2.cons_doctor
            SET v1.cons_doctor_name = v2.name, v1.updated_at = NOW()
            WHERE (v1.cons_doctor_name IS NULL OR v1.cons_doctor_name = '')
            " . ($date ? "AND v1.visit_date = ?" : ""), $date ? [$date] : []);

        $healedCount = $billHealed + $consHealed;

        if ($healedCount > 0) {
            Log::info("[SyncService] Surgical Healing complete. Healed {$healedCount} records.");
        }

        return $healedCount;
    }

    /**
     * Parse visits array to extract master data entities
     */
    private function extractMasterData(array $visits)
    {
        $data = [
            'clinics' => [],
            'departments' => [],
            'doctors' => []
        ];

        foreach ($visits as $visit) {
            if (!empty($visit['clinicCode'])) {
                $data['clinics'][$visit['clinicCode']] = [
                    'clinic_code' => $visit['clinicCode'],
                    'clinic_name' => ($visit['clinicName'] ?? 'Unknown Clinic') ?: 'Unknown Clinic'
                ];
            }
            if (!empty($visit['deptCode'])) {
                $data['departments'][$visit['deptCode']] = [
                    'dept_code' => $visit['deptCode'],
                    'dept_name' => ($visit['deptName'] ?? 'Unknown Dept') ?: 'Unknown Dept'
                ];
            }
            if (!empty($visit['doctCode'])) {
                $data['doctors'][$visit['doctCode']] = [
                    'doctor_code' => $visit['doctCode']
                ];
            }
        }

        return $data;
    }

    /**
     * Persist master data using a global cache to avoid redundant upserts
     */
    private function persistMasterData($type, $data, $uniqueKeys, $updateKeys)
    {
        if (empty($data)) return;

        if (!empty($data)) {
            try {
                // Skip the Cache::has checks and just perform the idempotent upsert.
                // ON DUPLICATE KEY UPDATE is fast and avoids N+1 cache lookups.
                DB::table($type)->upsert(array_values($data), $uniqueKeys, $updateKeys);
            } catch (\Exception $e) {
                Log::warning("[SyncService] Master data upsert failed for $type: " . $e->getMessage());
            }
        }
    }

    public function refreshDuplicateOccurrences(string $date): array
    {
        return $this->syncForDate($date);
    }
}
