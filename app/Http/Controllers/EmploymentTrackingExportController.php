<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmploymentTrackingExportController extends Controller
{
    /**
     * GET /registrar/employment-tracking/export?type=pdf|excel|print&program=&batch=
     *
     * IMPORTANT: this mirrors the dashboard SCOPE, not the drill-down
     * modal. The Generate Reports button only ever sends `type`,
     * `program`, and `batch` (see the empReport Alpine store's
     * doExport()) — whatever Program/Batch Year filter is active up
     * top in the dashboard. There is no `filter`/`course` param coming
     * in from that button, so getFilteredRecords() must not depend on
     * those — it needs to return the SAME population as the
     * dashboard's own computeStats() (every alumnus in scope, LEFT
     * JOINed to their latest employment record so "No Record" alumni
     * are included, not silently dropped).
     */
    public function export(Request $request)
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'registrar') {
            abort(403);
        }

        $type = $request->query('type', 'pdf');

        try {
            $records = $this->getFilteredRecords($request);
            $summary = $this->filterSummary($request);

            $viewData = [
                'records'     => $records,
                'filters'     => $summary,
                'generatedAt' => now(),
                'generatedBy' => $user->name ?? 'Registrar',
            ];

            if ($type === 'excel') {
                return $this->exportExcel($records, $summary);
            }

            if ($type === 'print') {
                // IMPORTANT: force render the Blade view HERE (inside the
                // try block) instead of passing a lazy View object to
                // response()->view(). response()->view() only renders the
                // template when the response is actually sent — which
                // happens AFTER this method returns, outside the try/catch.
                // That meant any error inside the print blade (missing
                // view, undefined var, bad column, etc.) escaped as a raw
                // Laravel 500 page instead of our friendly JSON message.
                $html = view('registrar.employment-tracking-print', $viewData)->render();

                return response($html, 200)->header('Content-Type', 'text/html');
            }

            // Default: PDF
            return $this->exportPdf($viewData);

        } catch (\Throwable $e) {
            Log::error('Employment Tracking export failed', [
                'type'    => $type,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Report generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PDF export via barryvdh/laravel-dompdf.
     *
     * Kung hindi pa naka-install yung package (`composer require
     * barryvdh/laravel-dompdf`), dating nagre-result ito ng
     * "Class not found" error at basta na lang "hindi gumagana" ang
     * PDF button na walang malinaw na dahilan. Ngayon, chine-check muna
     * kung available yung class bago tumawag dito, at kung wala, nagbabalik
     * ng malinaw na error message sa halip na fatal crash.
     */
    private function exportPdf(array $viewData)
    {
        $pdfClass = 'Barryvdh\\DomPDF\\Facade\\Pdf';

        if (!class_exists($pdfClass)) {
            return response()->json([
                'message' => 'PDF export is not available yet. Kulang pa yung "barryvdh/laravel-dompdf" package sa server — patakbuhin muna ang: composer require barryvdh/laravel-dompdf',
            ], 500);
        }

        $pdf = $pdfClass::loadView('registrar.employment-tracking-print', $viewData)
            ->setPaper('legal', 'landscape');

        return $pdf->download('employment-tracking-report-' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * Simple Excel export using an HTML table served as .xls
     * (walang dependency sa Maatwebsite\Excel — gumagana agad out of the box).
     * Kung meron ka ng maatwebsite/excel package, pwede mo palitan ito ng
     * proper Export class kung gusto mo ng totoong .xlsx.
     */
    private function exportExcel($records, string $summary)
    {
        $filename = 'employment-tracking-report-' . now()->format('Y-m-d_His') . '.xls';

        $html = view('registrar.employment-tracking-print', [
            'records'     => $records,
            'filters'     => $summary,
            'generatedAt' => now(),
            'generatedBy' => auth()->user()->name ?? 'Registrar',
            'excelMode'   => true,
        ])->render();

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Human-readable scope text — reflects EXACTLY the Program / Batch
     * Year filter active on the dashboard when Generate Reports was
     * clicked (mirrors activeReportFilterSummary() in the Volt
     * component). No more "filter"/"course" params — those belonged to
     * the old per-segment modal export, which this button doesn't use.
     */
    private function filterSummary(Request $request): string
    {
        $program = trim((string) $request->query('program', ''));
        $batch   = trim((string) $request->query('batch', ''));

        $parts = [];
        if ($program !== '') $parts[] = $program;
        if ($batch !== '')   $parts[] = 'Batch ' . $batch;

        return count($parts) ? implode(' · ', $parts) : 'All Programs · All Batch Years';
    }

    private function latestEmpSubquery()
    {
        return DB::table('employment_trackings as et_inner')
            ->whereNull('et_inner.deleted_at')
            ->select('et_inner.alumni_id', DB::raw('MAX(et_inner.id) as max_id'))
            ->groupBy('et_inner.alumni_id');
    }

    private function baseAlumniQuery(string $program, string $batch)
    {
        $q = DB::table('alumni as a')->whereNull('a.deleted_at');

        if ($program !== '') $q->where('a.course_code', $program);
        if ($batch !== '')   $q->where('a.batch', $batch);

        return $q;
    }

    /**
     * Same population as the dashboard's $totalAlumni stat: every
     * alumnus in scope (Program + Batch Year applied), LEFT JOINed to
     * their latest employment_trackings row. Alumni with no row at all
     * still come back as a record — employment_status / course_relevance
     * / work_location simply null — instead of being dropped by an
     * inner join. That's what makes "No Record" in the report match
     * "No Record" on the dashboard, no matter the scope.
     */
    private function getFilteredRecords(Request $request)
    {
        $program = trim((string) $request->query('program', ''));
        $batch   = trim((string) $request->query('batch', ''));

        return $this->baseAlumniQuery($program, $batch)
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select([
                'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                'a.student_id', 'a.course_code', 'a.batch', 'a.email', 'a.contact_number',
                'et.employment_status', 'et.course_relevance', 'et.work_location', 'et.created_at',
            ])
            ->orderBy('a.last_name')
            ->orderBy('a.first_name')
            ->get();
    }
}