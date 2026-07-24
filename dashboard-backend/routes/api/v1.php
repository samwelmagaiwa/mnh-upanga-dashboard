<?php

use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReportsController;

/*
|--------------------------------------------------------------------------
| V1 API Routes
|--------------------------------------------------------------------------
*/

// Health check (public — for uptime monitors)
Route::get('/health', [HealthController::class, 'check']);

// Public Auth Routes
Route::post('/login', [AuthController::class, 'login']);

// Public Dashboard Routes (No Authentication Required)
Route::prefix('dashboard')->controller(DashboardController::class)->group(function () {
    Route::get('/stats', 'getStats');
    Route::get('/snapshot', 'getSnapshot');
    Route::get('/clinics', 'getClinicBreakdown');
    Route::get('/business-units', 'getBusinessUnits');
    Route::get('/detailed-clinics', 'getDetailedClinicVisits');
    Route::get('/gaps', 'getGaps');
    Route::get('/pie-stats', 'getPieStats');
    Route::get('/chart-stats', 'getComparisonStats');
    Route::get('/check-updates', 'checkUpdates');
    Route::get('/service-trends', 'getServiceTrends');
    Route::get('/referral-stats', 'getReferralStats');
    Route::get('/gender-radar', 'getGenderRadarStats');
    Route::get('/pending-patients', 'getPendingPatients');
    Route::get('/duplicated-data', 'getDuplicateVisits');
    Route::get('/top-diseases', 'getTopDiseases');
});

// Reports Routes
Route::prefix('dashboard/reports')->controller(ReportsController::class)->group(function () {
    Route::get('/pending', 'pending');
});

// Public Sync Routes (No Authentication Required)
Route::prefix('sync')->controller(SyncController::class)->group(function () {
    Route::get('/range', 'syncRange');
    Route::get('/enqueue/range', 'enqueueSyncRange');
    Route::get('/batch/{id}', 'batchStatus');
    Route::get('/reaggregate/range', 'reaggregateRange');
    Route::post('/repair-gaps', 'repairGaps');
    Route::post('/cancel-batch/{id}', 'cancelBatch');
    Route::post('/reset-state', 'resetSyncState');
    Route::get('/heal-data', 'healData');
    Route::get('/trigger/{date?}', 'triggerSync');
    Route::get('/{date?}', 'sync');
});

// Protected Routes (Authentication Required)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile Routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\ProfileController::class, 'show']);
        Route::put('/', [\App\Http\Controllers\Api\V1\ProfileController::class, 'update']);
    });
});
