<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    public function check(): JsonResponse
    {
        $checks = [];
        $overallOk = true;

        // Database
        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $overallOk = false;
        }

        // Redis
        try {
            Redis::ping();
            $checks['redis'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['redis'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $overallOk = false;
        }

        // HIS API reachability — HEAD base URL (cached 60s); any sub-500 response = server is up
        $checks['his_api'] = Cache::remember('health:his_api', 60, function () {
            try {
                $baseUrl = config('dashboard.sync.base_url');
                $response = Http::withBasicAuth(
                    config('dashboard.sync.username'),
                    config('dashboard.sync.password')
                )->connectTimeout(5)->timeout(10)->head($baseUrl);

                $ok = $response->status() < 500;
                return ['status' => $ok ? 'ok' : 'fail', 'http_code' => $response->status()];
            } catch (\Throwable $e) {
                return ['status' => 'fail', 'error' => $e->getMessage()];
            }
        });

        if ($checks['his_api']['status'] !== 'ok') {
            $overallOk = false;
        }

        // Queue — check Horizon master supervisor via its repository
        try {
            $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
            $running = collect($masters)->contains(fn ($m) => ($m->status ?? '') !== 'paused');
            $checks['queue'] = [
                'status'  => 'ok',
                'horizon' => $running ? 'running' : 'paused',
                'masters' => count($masters),
            ];
        } catch (\Throwable $e) {
            $checks['queue'] = ['status' => 'ok', 'horizon' => 'unknown'];
        }

        // Sync lag (how many minutes since last successful sync)
        try {
            $lastSync = \App\Models\SyncLog::where('status', 'SUCCESS')->latest('finished_at')->first();
            $lagMinutes = $lastSync ? max(0, (int) now()->diffInMinutes($lastSync->finished_at, false)) : null;
            $checks['sync_lag'] = [
                'status' => $lagMinutes === null || $lagMinutes > 60 ? 'warn' : 'ok',
                'last_success_minutes_ago' => $lagMinutes,
            ];
        } catch (\Throwable $e) {
            $checks['sync_lag'] = ['status' => 'unknown'];
        }

        return response()->json([
            'status'  => $overallOk ? 'ok' : 'degraded',
            'checks'  => $checks,
            'time'    => now()->toIso8601String(),
        ], $overallOk ? 200 : 503);
    }
}
