<?php

namespace App\Http\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Clinical Dashboard API',
    description: 'REST API for the MNH Clinical Dashboard system. Provides real-time clinical visit statistics, data synchronization controls, ICD-10 diagnosis reporting, and gap detection. Dashboard and sync routes are intentionally public for LAN access. Admin routes require a Sanctum Bearer token.',
    contact: new OA\Contact(
        name: 'Samwel Magaiwa',
        email: 'samwelmagaiwa229@gmail.com'
    ),
    license: new OA\License(name: 'MIT')
)]
#[OA\Server(
    url: '/api/v1',
    description: 'Production (relative to current host)'
)]
#[OA\Server(
    url: 'http://localhost:8000/api/v1',
    description: 'Local development'
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    description: 'Laravel Sanctum token. Format: `Bearer {token}`',
    name: 'Authorization',
    in: 'header'
)]

// ─── Shared Schemas ────────────────────────────────────────────────────────

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Dr. Samwel'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@hospital.or.tz'),
        new OA\Property(property: 'role', type: 'string', enum: ['ED', 'DED', 'DICT'], example: 'ED'),
        new OA\Property(property: 'avatar', type: 'string', nullable: true, example: 'storage/avatars/1.jpg'),
    ]
)]

#[OA\Schema(
    schema: 'ApiSuccess',
    properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Operation completed'),
    ]
)]

#[OA\Schema(
    schema: 'ApiError',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
        new OA\Property(property: 'details', type: 'string', nullable: true),
    ]
)]

#[OA\Schema(
    schema: 'DashboardStats',
    description: 'Aggregated visit statistics for a date range',
    properties: [
        new OA\Property(property: 'total_visits', type: 'integer', example: 1240),
        new OA\Property(property: 'consulted', type: 'integer', example: 1100),
        new OA\Property(property: 'pending', type: 'integer', example: 140),
        new OA\Property(property: 'new_visits', type: 'integer', example: 800),
        new OA\Property(property: 'followups', type: 'integer', example: 440),
        new OA\Property(property: 'nhif_visits', type: 'integer', example: 320),
        new OA\Property(property: 'emergency', type: 'integer', example: 45),
        new OA\Property(property: 'male_count', type: 'integer', example: 620),
        new OA\Property(property: 'female_count', type: 'integer', example: 590),
        new OA\Property(property: 'duplicates', type: 'integer', example: 12),
        new OA\Property(property: 'foreigner', type: 'integer', example: 8),
        new OA\Property(property: 'public', type: 'integer', example: 540),
        new OA\Property(property: 'ippm_private', type: 'integer', example: 200),
        new OA\Property(property: 'ippm_credit', type: 'integer', example: 180),
        new OA\Property(property: 'cost_sharing', type: 'integer', example: 90),
        new OA\Property(property: 'nssf', type: 'integer', example: 110),
    ]
)]

#[OA\Schema(
    schema: 'ClinicBreakdown',
    properties: [
        new OA\Property(property: 'clinic_code', type: 'string', example: 'OPD-01'),
        new OA\Property(property: 'clinic_name', type: 'string', example: 'General Outpatient'),
        new OA\Property(property: 'total_visits', type: 'integer', example: 320),
        new OA\Property(property: 'consulted', type: 'integer', example: 280),
        new OA\Property(property: 'pending', type: 'integer', example: 40),
    ]
)]

#[OA\Schema(
    schema: 'GapEntry',
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-06-15'),
        new OA\Property(property: 'status', type: 'string', enum: ['MISSING', 'EMPTY'], example: 'MISSING'),
        new OA\Property(property: 'reason', type: 'string', example: 'No aggregate record found for an operational day'),
        new OA\Property(property: 'raw_visits', type: 'integer', example: 0),
    ]
)]

#[OA\Schema(
    schema: 'BatchStatus',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 'abc123def456'),
        new OA\Property(property: 'name', type: 'string', example: 'sync:2026-07-17'),
        new OA\Property(property: 'total_jobs', type: 'integer', example: 5),
        new OA\Property(property: 'pending_jobs', type: 'integer', example: 2),
        new OA\Property(property: 'failed_jobs', type: 'integer', example: 0),
        new OA\Property(property: 'progress', type: 'integer', example: 60, description: 'Percentage complete'),
        new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]

#[OA\Schema(
    schema: 'SyncLog',
    properties: [
        new OA\Property(property: 'sync_date', type: 'string', format: 'date', example: '2026-07-17'),
        new OA\Property(property: 'status', type: 'string', enum: ['SUCCESS', 'FAILED', 'IN_PROGRESS'], example: 'SUCCESS'),
        new OA\Property(property: 'records_synced', type: 'integer', example: 348),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]

#[OA\Schema(
    schema: 'DateRangeParams',
    description: 'Common date range query parameters',
    properties: [
        new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-07-01'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2026-07-17'),
    ]
)]

class ApiInfo {}
