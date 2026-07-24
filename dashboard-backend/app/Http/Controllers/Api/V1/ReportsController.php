<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'Clinical reports: pending visits, aging, and ICD-10 diagnosis detail')]
class ReportsController extends Controller
{
    #[OA\Get(
        path: '/dashboard/reports/pending',
        summary: 'Pending visits report — by clinic, aging, and full list with ICD-10 metadata',
        description: 'Returns pending consultations (visit_status != C) grouped by clinic, an aging breakdown by date, and a full list of up to 200 visits enriched with ICD-10 descriptions. Cached for 60s for today, 1 hour for historical ranges.',
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17'),
                description: 'Start date (defaults to today)'
            ),
            new OA\Parameter(name: 'end_date', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-07-17'),
                description: 'End date (defaults to today)'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pending visits report',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'by_clinic', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'clinic_name', type: 'string', example: 'General OPD'),
                            new OA\Property(property: 'count', type: 'integer', example: 42),
                        ])),
                        new OA\Property(property: 'aging', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'visit_date', type: 'string', format: 'date'),
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'days_elapsed', type: 'integer', example: 3),
                        ])),
                        new OA\Property(property: 'list', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'visit_date', type: 'string', format: 'date'),
                            new OA\Property(property: 'mr_number', type: 'string'),
                            new OA\Property(property: 'clinic_name', type: 'string'),
                            new OA\Property(property: 'doctor_name', type: 'string', nullable: true),
                            new OA\Property(property: 'prov_diag', type: 'string', nullable: true, description: 'ICD-10 code(s) for provisional diagnosis'),
                            new OA\Property(property: 'final_diag', type: 'string', nullable: true),
                            new OA\Property(property: 'visit_status', type: 'string', example: 'P'),
                        ])),
                        new OA\Property(property: 'diagnosis_metadata', type: 'object',
                            description: 'Map of ICD-10 code → {description, abbreviation}',
                            additionalProperties: new OA\AdditionalProperties(properties: [
                                new OA\Property(property: 'description', type: 'string'),
                                new OA\Property(property: 'abbreviation', type: 'string', nullable: true),
                            ], type: 'object')
                        ),
                    ]),
                ])
            ),
        ]
    )]
    public function pending(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::today()->toDateString());
        $endDate = $request->query('end_date', Carbon::today()->toDateString());

        $v = config('dashboard.cache_version', 7);
        $cacheKey = "report_pending_{$startDate}_{$endDate}_v{$v}";
        $isToday = ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d'));
        $ttl = $isToday ? 60 : 3600;

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function() use ($startDate, $endDate) {
            // 1. Pending by Clinic
            $byClinic = Visit::select('clinic_name', DB::raw('count(*) as count'))
                ->where('visit_status', '!=', 'C')
                ->whereDate('visit_date', '>=', $startDate)
                ->whereDate('visit_date', '<=', $endDate)
                ->groupBy('clinic_name')
                ->orderBy('count', 'desc')
                ->get();

            // 2. Aging (By Date) - Oldest First
            $aging = Visit::select('visit_date', DB::raw('count(*) as count'), DB::raw('DATEDIFF(NOW(), visit_date) as days_elapsed'))
                ->where('visit_status', '!=', 'C')
                ->whereDate('visit_date', '>=', $startDate)
                ->whereDate('visit_date', '<=', $endDate)
                ->whereDate('visit_date', '<', Carbon::today()) 
                ->groupBy('visit_date')
                ->orderBy('visit_date', 'asc')
                ->get();

            // 3. List of Pending Consultations
            $list = Visit::where('visit_status', '!=', 'C')
                ->whereDate('visit_date', '>=', $startDate)
                ->whereDate('visit_date', '<=', $endDate)
                ->select(
                    'id',
                    'visit_date',
                    'mr_number',
                    'clinic_name',
                    'cons_doctor as doctor_code',
                    'cons_doctor_name as doctor_name',
                    'pat_age',
                    'prov_diag',
                    'final_diag',
                    'visit_status'
                )
                ->orderBy('clinic_name', 'asc')
                ->orderBy('visit_date', 'asc')
                ->limit(200)
                ->get();

            // 4. Enrich list with ICD Metadata
            $allCodes = collect();
            $delimiters = '/[\$,;]/'; // Split by $, comma, or semicolon

            foreach ($list as $visit) {
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

            return response()->json([
                'status' => 'success',
                'data' => [
                    'by_clinic' => $byClinic,
                    'aging' => $aging,
                    'list' => $list,
                    'diagnosis_metadata' => $icdMetadata
                ]
            ]);
        });
    }
}
