<?php

namespace App\Console\Commands;

use App\Jobs\SyncForDateJob;
use App\Services\GapDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HealGaps extends Command
{
    protected $signature = 'sync:heal-gaps {--days=30 : How many past days to scan for gaps}';
    protected $description = 'Detect missing or empty dashboard stats and re-sync those dates automatically';

    public function handle(GapDetectionService $gapService): int
    {
        $days = (int) $this->option('days');
        $start = now()->subDays($days)->format('Y-m-d');
        $end   = now()->subDay()->format('Y-m-d'); // exclude today (may still be syncing)

        $this->info("Scanning for gaps between {$start} and {$end}...");

        $gaps = $gapService->detectGaps($start, $end);

        if (empty($gaps)) {
            $this->info('No gaps found.');
            return Command::SUCCESS;
        }

        $this->warn(count($gaps).' gap(s) found. Dispatching re-sync jobs...');

        foreach ($gaps as $gap) {
            SyncForDateJob::dispatch($gap['date'])->onQueue('low');
            $this->line("  → Queued re-sync for {$gap['date']} ({$gap['reason']})");
            Log::info('[HealGaps] Queued re-sync', ['date' => $gap['date'], 'reason' => $gap['reason']]);
        }

        $this->info('Done.');
        return Command::SUCCESS;
    }
}
