<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmploymentTrackingExportController extends Controller
{
    /**
     * GET /registrar/employment-tracking/export?type=pdf|excel|print&filter=&batch=&course=
     *
     * Note: walang na "search" param dito — tinanggal na yung in-modal
     * search/filter toolbar sa frontend, so hindi na siya kailangan.
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
     * Human-readable summary of the record scope. No more "filters" language —
     * this just describes what segment of employment records is included.
     */
    private function filterSummary(Request $request): string
    {
        $parts  = [];
        $filter = $request->query('filter', '');
        $batch  = $request->query('batch', '');
        $course = $request->query('course', '');

        $labels = [
            'employed'            => 'Employed',
            'self_employed'       => 'Self-Employed',
            'employed_all'        => 'Working Alumni',
            'unemployed'          => 'Unemployed',
            'no_record'           => 'No Employment Record',
            'abroad'              => 'OFW / Working Abroad',
            'local'               => 'Working Locally',
            'relevance_yes'       => 'Course-Relevant Employment',
            'relevance_partially' => 'Partially Relevant Employment',
            'relevance_no'        => 'Not Relevant to Course',
        ];

        if ($filter !== '' && isset($labels[$filter])) $parts[] = $labels[$filter];
        if ($batch !== '')  $parts[] = 'Batch ' . $batch;
        if ($course !== '') $parts[] = 'Course ' . $course;

        return count($parts) ? implode(' · ', $parts) : 'All Employment Records';
    }

    private function latestEmpSubquery()
    {
        return DB::table('employment_trackings as et_inner')
            ->whereNull('et_inner.deleted_at')
            ->select('et_inner.alumni_id', DB::raw('MAX(et_inner.id) as max_id'))
            ->groupBy('et_inner.alumni_id');
    }

    private function getFilteredRecords(Request $request)
    {
        $filter = $request->query('filter', '');
        $batch  = $request->query('batch', '');
        $course = $request->query('course', '');

        if ($filter === 'no_record') {
            $q = DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->whereNotExists(fn($sq) => $sq
                    ->from('employment_trackings as et')
                    ->whereColumn('et.alumni_id', 'a.id')
                    ->whereNull('et.deleted_at')
                )
                ->select([
                    'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                    'a.student_id', 'a.course_code', 'a.batch', 'a.email', 'a.contact_number',
                    DB::raw('NULL as employment_status'),
                    DB::raw('NULL as course_relevance'),
                    DB::raw('NULL as work_location'),
                    DB::raw('NULL as created_at'),
                ]);
        } else {
            $q = DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
                ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
                ->select([
                    'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                    'a.student_id', 'a.course_code', 'a.batch', 'a.email', 'a.contact_number',
                    'et.employment_status', 'et.course_relevance', 'et.work_location', 'et.created_at',
                ]);

            match ($filter) {
                'employed'            => $q->where('et.employment_status', 'employed'),
                'self_employed'       => $q->where('et.employment_status', 'self_employed'),
                'unemployed'          => $q->where('et.employment_status', 'unemployed'),
                'employed_all'        => $q->whereIn('et.employment_status', ['employed', 'self_employed']),
                'abroad'              => $q->where('et.work_location', 'abroad')->whereIn('et.employment_status', ['employed', 'self_employed']),
                'local'               => $q->where('et.work_location', 'local')->whereIn('et.employment_status', ['employed', 'self_employed']),
                'relevance_yes'       => $q->whereIn('et.employment_status', ['employed', 'self_employed'])->whereIn('et.course_relevance', ['yes', 'relevant']),
                'relevance_partially' => $q->whereIn('et.employment_status', ['employed', 'self_employed'])->whereIn('et.course_relevance', ['partially', 'partially_relevant']),
                'relevance_no'        => $q->whereIn('et.employment_status', ['employed', 'self_employed'])->whereIn('et.course_relevance', ['no', 'not_relevant']),
                default               => null,
            };
        }

        if ($batch !== '')  $q->where('a.batch', $batch);
        if ($course !== '') $q->where('a.course_code', $course);

        return $q->orderBy('a.last_name')->get();
    }
}