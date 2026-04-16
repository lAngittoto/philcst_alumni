<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use App\Models\EmploymentTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * EmploymentTrackingController
 *
 * Handles CRUD for alumni employment records.
 *
 * Security hardening:
 *  - All mutating actions verify the authenticated alumni owns the record.
 *  - No mass assignment from raw $request->all().
 *  - Validation rules use strict enum checks.
 *  - Admin-only aggregate endpoints are guarded by role middleware.
 *
 * Performance / scalability:
 *  - SELECT only needed columns.
 *  - Cache-busting on write; stale reads never served beyond TTL.
 *  - Chunked processing for bulk exports.
 *  - Eager-load `alumni` relationship to prevent N+1.
 *  - DB::transaction() for writes to guarantee atomicity.
 */
class EmploymentTrackingController extends Controller
{
    /**
     * Cache time-to-live in seconds.
     * Short enough to stay fresh, long enough to absorb traffic spikes.
     */
    private const CACHE_TTL = 60;

    // ── Alumni-facing endpoints ───────────────────────────────────────────────

    /**
     * GET /alumni/employment
     * Returns the current user's employment record (or null).
     */
    public function show(): JsonResponse
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->forbidden();
        }

        $cacheKey = "employment_tracking_alumni_{$alumni->id}";

        $record = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($alumni) {
            return EmploymentTracking::where('alumni_id', $alumni->id)
                ->select([
                    'id', 'employment_status', 'company_name', 'job_title',
                    'employment_type', 'work_location', 'date_hired',
                    'career_path', 'education_status', 'course_relevance',
                    'unemployment_status', 'updated_at',
                ])
                ->first();
        });

        return response()->json([
            'success' => true,
            'data'    => $record,
        ]);
    }

    /**
     * POST /alumni/employment
     * Upsert the authenticated alumni's employment record.
     * Rate-limited via route middleware (throttle:10,1).
     */
    public function upsert(Request $request): JsonResponse
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->forbidden();
        }

        $data = $this->validateEmploymentData($request);

        try {
            DB::transaction(function () use ($alumni, $data) {
                EmploymentTracking::updateOrCreate(
                    ['alumni_id' => $alumni->id],
                    $data
                );
            });

            // Bust the per-alumni cache immediately after write
            Cache::forget("employment_tracking_alumni_{$alumni->id}");

            Log::info("Employment tracking upserted | alumni_id: {$alumni->id} | status: {$data['employment_status']}");

            return response()->json([
                'success' => true,
                'message' => 'Employment information saved successfully.',
            ]);

        } catch (\Throwable $e) {
            Log::error('EmploymentTracking upsert error: ' . $e->getMessage(), [
                'alumni_id' => $alumni->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save employment information. Please try again.',
            ], 500);
        }
    }

    // ── Admin-facing endpoints (protected by role middleware in routes) ────────

    /**
     * GET /admin/employment/statistics
     * Returns aggregate employment statistics.
     * Cached aggressively — recalculated only every 5 minutes.
     *
     * Apply `middleware(['auth', 'role:admin'])` in routes/web.php.
     */
    public function statistics(): JsonResponse
    {
        $stats = Cache::remember('employment_statistics', 300, function () {
            // Single aggregated query — avoids N+1 and multiple round-trips
            $totals = EmploymentTracking::query()
                ->selectRaw("
                    COUNT(*) AS total,
                    SUM(employment_status = 'employed')      AS employed,
                    SUM(employment_status = 'self_employed') AS self_employed,
                    SUM(employment_status = 'unemployed')    AS unemployed,
                    SUM(work_location = 'abroad')            AS ofw,
                    SUM(work_location = 'local')             AS local,
                    SUM(course_relevance = 'yes')            AS course_relevant,
                    SUM(course_relevance = 'no')             AS course_not_relevant,
                    SUM(course_relevance = 'partially')      AS course_partial
                ")
                ->first();

            return $totals;
        });

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    /**
     * GET /admin/employment/export
     * Streams a paginated list for CSV export without timing out.
     * Uses chunk() so memory stays flat regardless of alumni count.
     *
     * Apply `middleware(['auth', 'role:admin'])` in routes/web.php.
     */
    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Set a generous time limit only for this export endpoint
        set_time_limit(300);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employment_tracking_' . now()->format('Y-m-d') . '.csv"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering'   => 'no',  // Nginx: disable buffering for streamed response
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');

            // CSV header row
            fputcsv($handle, [
                'Student ID', 'Full Name', 'Course', 'Batch',
                'Employment Status', 'Company', 'Job Title',
                'Employment Type', 'Work Location', 'Date Hired',
                'Career Path', 'Education Status', 'Course Relevance',
                'Unemployment Status', 'Last Updated',
            ]);

            // chunk(200) → 200 rows per DB query, never loads everything into RAM
            EmploymentTracking::with([
                    'alumni:id,student_id,first_name,last_name,course_code,batch',
                ])
                ->select([
                    'id', 'alumni_id', 'employment_status', 'company_name', 'job_title',
                    'employment_type', 'work_location', 'date_hired', 'career_path',
                    'education_status', 'course_relevance', 'unemployment_status', 'updated_at',
                ])
                ->orderBy('id')
                ->chunk(200, function ($records) use ($handle) {
                    foreach ($records as $r) {
                        $a = $r->alumni;
                        fputcsv($handle, [
                            $a->student_id ?? '',
                            trim(($a->first_name ?? '') . ' ' . ($a->last_name ?? '')),
                            $a->course_code ?? '',
                            $a->batch       ?? '',
                            EmploymentTracking::EMPLOYMENT_STATUSES[$r->employment_status] ?? $r->employment_status,
                            $r->company_name ?? '',
                            $r->job_title    ?? '',
                            EmploymentTracking::EMPLOYMENT_TYPES[$r->employment_type ?? ''] ?? '',
                            EmploymentTracking::WORK_LOCATIONS[$r->work_location ?? '']    ?? '',
                            $r->date_hired ? $r->date_hired->format('Y-m-d') : '',
                            is_array($r->career_path) ? implode('; ', $r->career_path) : '',
                            EmploymentTracking::EDUCATION_STATUSES[$r->education_status ?? ''] ?? '',
                            EmploymentTracking::COURSE_RELEVANCES[$r->course_relevance  ?? ''] ?? '',
                            EmploymentTracking::UNEMPLOYMENT_STATUSES[$r->unemployment_status ?? ''] ?? '',
                            $r->updated_at?->format('Y-m-d H:i:s') ?? '',
                        ]);
                    }

                    // Flush output buffer every chunk to avoid memory build-up
                    ob_flush();
                    flush();
                });

            fclose($handle);
        }, 200, $headers);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the authenticated user's alumni record.
     * Returns null if not found or wrong role.
     */
    private function resolveAlumni(): ?Alumni
    {
        $user = Auth::user();

        if (!$user || $user->role !== 'alumni') {
            return null;
        }

        // Cache the alumni lookup for this request only (no DB hit on repeat calls)
        return Cache::store('array')->remember("auth_alumni_{$user->id}", 60, function () use ($user) {
            return Alumni::where('user_id', $user->id)
                ->select(['id', 'user_id', 'student_id'])
                ->first();
        });
    }

    /**
     * Validate and return only whitelisted fields.
     * Never returns raw $request->all().
     */
    private function validateEmploymentData(Request $request): array
    {
        $base = $request->validate([
            'employment_status' => [
                'required',
                Rule::in(array_keys(EmploymentTracking::EMPLOYMENT_STATUSES)),
            ],
        ]);

        $status = $base['employment_status'];

        if (in_array($status, ['employed', 'self_employed'], true)) {
            $extra = $request->validate([
                'company_name'     => 'required|string|max:255',
                'job_title'        => 'required|string|max:255',
                'employment_type'  => ['required', Rule::in(array_keys(EmploymentTracking::EMPLOYMENT_TYPES))],
                'work_location'    => ['required', Rule::in(array_keys(EmploymentTracking::WORK_LOCATIONS))],
                'date_hired'       => 'required|date|before_or_equal:today',
                'career_path'      => 'nullable|array|max:5',
                'career_path.*'    => Rule::in(array_keys(EmploymentTracking::CAREER_PATHS)),
                'education_status' => ['required', Rule::in(array_keys(EmploymentTracking::EDUCATION_STATUSES))],
                'course_relevance' => ['required', Rule::in(array_keys(EmploymentTracking::COURSE_RELEVANCES))],
                // Clear unemployment fields
                'unemployment_status' => 'prohibited',
            ]);

            return array_merge($base, $extra, [
                'unemployment_status' => null,
            ]);
        }

        // Unemployed
        $extra = $request->validate([
            'unemployment_status' => [
                'required',
                Rule::in(array_keys(EmploymentTracking::UNEMPLOYMENT_STATUSES)),
            ],
        ]);

        // Clear employment fields
        return array_merge($base, $extra, [
            'company_name'     => null,
            'job_title'        => null,
            'employment_type'  => null,
            'work_location'    => null,
            'date_hired'       => null,
            'career_path'      => null,
            'education_status' => null,
            'course_relevance' => null,
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
}