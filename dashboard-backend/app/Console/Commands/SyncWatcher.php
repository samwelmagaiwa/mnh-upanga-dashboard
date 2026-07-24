<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Visit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SyncWatcher extends Command
{
    protected $signature = 'sync:watch';
    protected $description = 'Lightweight monitor to detect new records in the external API and trigger sync';

    public function handle()
    {
        $this->info("🚀 Sync Watcher STARTED. Monitoring HIS API every 10s...");
        $this->info("Press Ctrl+C to stop.");

        while (true) {
            $today    = Carbon::today()->format('Y-m-d');
            $todayYmd = Carbon::today()->format('Ymd');
            $baseUrl  = env('DASHBOARD_API_BASE_URL', 'http://192.168.235.250/labsms/swagger/mnh_dashboard');
            $url      = "{$baseUrl}/{$todayYmd}";

            try {
                // Ensure SyncService is resolved here to call cleanup
                $syncService = app(\App\Services\SyncService::class);
                $syncService->cleanupStaleBatches(30);

                // ── Atomic single-instance guard ─────────────────────────────
                // Only ONE sync:watch process may dispatch at a time (across all terminals)
                $dispatchLock = Cache::lock('sync_watcher_dispatch_lock', 60);

                if (!$dispatchLock->get()) {
                    // Another instance is already running its dispatch cycle — skip
                    $this->line("[" . now()->toTimeString() . "] ⏭️  Another watcher instance is active. Sleeping...");
                    sleep(10);
                    continue;
                }

                try {
                    $localCount = Visit::where('visit_date', $today)->count();

                    // SKIP auto-sync if a manual sync is already active (don't interfere with user)
                    $manualActive = DB::table('job_batches')
                        ->whereNull('finished_at')
                        ->whereNull('cancelled_at')
                        ->where('name', 'NOT LIKE', 'auto-sync:%')
                        ->exists();

                    if ($manualActive) {
                        $this->line("[" . now()->toTimeString() . "] ⏭️  Manual sync in progress. Skipping watcher cycle...");
                        $dispatchLock->release();
                        sleep(15);
                        continue;
                    }

                    // 30-second cooldown per date after a successful dispatch
                    $lastSyncedAt = Cache::get("sync_watcher_last_sync_{$today}");
                    $secondsSinceSync = $lastSyncedAt ? abs(now()->diffInSeconds($lastSyncedAt)) : 999;

                    if ($secondsSinceSync < 30) {
                        $this->line("[" . now()->toTimeString() . "] ⏳ In cooldown ({$secondsSinceSync}s / 60s). Local: {$localCount}");
                        $dispatchLock->release();
                        sleep(10);
                        continue;
                    }

                    // ── Poll HIS API ─────────────────────────────────────────
                    $username = env('DASHBOARD_API_USERNAME');
                    $password = env('DASHBOARD_API_PASSWORD');

                    $response = Http::withBasicAuth($username, $password)
                        ->connectTimeout(10)
                        ->timeout(60)
                        ->get($url);

                    if ($response->successful()) {
                        $data          = $response->json();
                        $externalCount = count($data['data'] ?? []);

                        // Always record the latest external count for the frontend checkUpdates
                        Cache::put("sync_watcher_external_count_{$today}", $externalCount, now()->addMinutes(15));

                        if ($externalCount > $localCount) {
                            $diff = $externalCount - $localCount;
                            $this->info("🚩 [" . now()->toTimeString() . "] NEW DATA: External={$externalCount}, Local={$localCount}. Dispatching sync for +{$diff} records...");
                            Log::info("[SyncWatcher] New records found ({$diff}) for {$today}. Triggering sync.");

                            Artisan::call('sync:auto-daily');

                            // Set cooldown BEFORE releasing lock so no other instance can sneak in
                            Cache::put("sync_watcher_last_sync_{$today}", now(), now()->addMinutes(10));

                            $this->info("✅ Sync dispatched. 30s cooldown active.");
                        } else {
                            $this->line("[" . now()->toTimeString() . "] ✔  Up to date. Local={$localCount}, External={$externalCount}");
                        }
                    } elseif ($response->status() === 401) {
                        // 401 = HIS session expired — transient, just wait and retry
                        $this->line("[" . now()->toTimeString() . "] ⚠️  HIS API auth expired (401). Will retry next cycle...");
                        Log::warning("[SyncWatcher] HIS API returned 401 for {$today}. Credentials may need renewal.");
                        Cache::forget("sync_watcher_last_sync_{$today}");
                    } else {
                        $this->error("❌ External API error: HTTP " . $response->status());
                        Log::warning("[SyncWatcher] HIS API returned status " . $response->status() . " for {$today}.");
                    }
                } finally {
                    // Always release the dispatch lock
                    $dispatchLock->release();
                }

            } catch (\Exception $e) {
                $this->error("❌ Watcher failed: " . $e->getMessage());
                Log::error("[SyncWatcher] Error: " . $e->getMessage());
            }

            sleep(10);
        }

        return Command::SUCCESS;
    }
}
