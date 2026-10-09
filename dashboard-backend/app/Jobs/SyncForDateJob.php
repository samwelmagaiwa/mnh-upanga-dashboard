<?php

namespace App\Jobs;

use App\Services\SyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncForDateJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $date;
    public $force;
    public $timeout = 600; // 10 minutes per job to be safe
    public $uniqueFor = 600; // Lock expires after 10 minutes if job crashes
    public $tries = 3;
    public $backoff = [60, 120, 300];

    public function __construct($date, $force = false)
    {
        $this->date = $date;
        $this->force = $force;
        
        // If not already explicitly set (e.g. by batch onQueue), 
        // default to high for today/yesterday.
        if (!$this->queue) {
            $isPriority = ($date === now()->format('Y-m-d') || $date === now()->subDay()->format('Y-m-d'));
            $this->onQueue($isPriority ? 'high' : 'default');
        }
    }

    /**
     * Unique key prevents duplicate jobs for the same date from being queued.
     */
    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(SyncService $syncService)
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        // Skip if already synced successfully and not forced
        // EXCEPT for today and yesterday, where data might still be streaming in
        if (!$this->force) {
            $isStreamingDate = $this->date === now()->format('Y-m-d') || $this->date === now()->subDay()->format('Y-m-d');
            
            if (!$isStreamingDate) {
                $exists = \App\Models\SyncLog::where('sync_date', $this->date)
                    ->where('status', 'SUCCESS')
                    ->exists();
                
                if ($exists) {
                    return;
                }
            }
        }

        $result = $syncService->syncForDate($this->date, $this->force);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Sync failed';

            // "Already in progress" is a lock conflict — skip silently, don't retry
            if (stripos($error, 'already in progress') !== false) {
                Log::info("[SyncForDateJob] Skipping {$this->date} — sync lock already held by another worker.");
                return;
            }

            // Any other real error — fail the job so it appears in failed_jobs
            throw new \Exception($error);
        }
    }
}
