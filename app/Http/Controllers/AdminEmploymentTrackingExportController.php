<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generate Reports (PDF / Excel / Print) for the Admin "Employment
 * Tracking" table — resources/views/livewire/admin/employment-tracking.blade.php.
 *
 * Mirrors OrganizerAlumniExportController's export flow and query shape,
 * minus the organizer batch/department scoping (admin sees every alumni
 * record, not just their assigned batch/college).
 */
class AdminEmploymentTrackingExportController extends Controller
{
    public function export(Request $request)
    {
        $type = $request->query('type', 'pdf');

        $search    = trim((string) $request->query('search', ''));
        $batchFrom = trim((string) $request->query('batch_from', ''));
        $batchTo   = trim((string) $request->query('batch_to', ''));

        // Comma-separated lists — mirrors how the on-screen filters are
        // sent (see the doExport() params in employment-tracking.blade.php).
        $courses = array_values(array_filter(array_map('trim',
            explode(',', (string) $request->query('course', ''))
        )));
        $statuses = array_values(array_filter(array_map('trim',
            explode(',', (string) $request->query('status', ''))
        )));

        $records = $this->buildQuery($search, $batchFrom, $batchTo, $courses, $statuses)->get();
        $stats   = $this->buildStats($search, $batchFrom, $batchTo, $courses, $statuses);

        $generatedAt = now();
        $formatName  = fn($row) => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        $statusLabel = fn($row) => match ($row->employment_status ?? null) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'unemployed'    => 'Unemployed',
            default         => 'Not Filled',
        };

        return match ($type) {
            'excel' => $this->exportExcel($records, $formatName, $statusLabel, $generatedAt),
            'print' => view('admin.employment-tracking-print', compact(
                'records', 'stats', 'generatedAt', 'formatName', 'statusLabel'
            )),
            default => $this->exportPdf($records, $stats, $generatedAt, $formatName, $statusLabel),
        };
    }

    /**
     * Base alumni + employment_trackings query, filtered the same way as
     * the Livewire component's with() query (search / status / program /
     * batch range) — kept in sync so the export always matches what's on
     * screen.
     */
    private function buildQuery(
        string $search,
        string $batchFrom,
        string $batchTo,
        array $courses,
        array $statuses,
    ): \Illuminate\Database\Query\Builder {
        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->select([
                'a.id',
                'a.student_id',
                'a.first_name',
                'a.last_name',
                'a.course_code',
                'a.course_name',
                'a.batch',
                'et.employment_status',
                'et.company_name',
                'et.job_title',
                'et.work_location',
            ]);

        if ($search !== '') {
            $s = '%' . $search . '%';
            $q->where(function ($w) use ($s) {
                $w->where(DB::raw("CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))"), 'like', $s)
                  ->orWhere('a.student_id', 'like', $s)
                  ->orWhere('a.email', 'like', $s)
                  ->orWhere('et.company_name', 'like', $s)
                  ->orWhere('et.job_title', 'like', $s);
            });
        }

        if ($batchFrom !== '' && $batchTo !== '') {
            $q->where('a.batch', '>=', $batchFrom)
              ->where('a.batch', '<=', $batchTo);
        }

        if (!empty($courses)) {
            $q->whereIn('a.course_code', $courses);
        }

        if (!empty($statuses)) {
            $q->where(function ($w) use ($statuses) {
                foreach ($statuses as $status) {
                    if ($status === 'not_filled') {
                        $w->orWhereNull('et.employment_status');
                    } else {
                        $w->orWhere('et.employment_status', $status);
                    }
                }
            });
        }

        return $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
            ->orderBy('a.last_name')
            ->orderBy('a.id');
    }

    /**
     * Summary stat cards shown on page 1 of the PDF/Print output — same
     * ten figures as the on-screen stat cards, scoped by the same filters.
     */
    private function buildStats(
        string $search,
        string $batchFrom,
        string $batchTo,
        array $courses,
        array $statuses,
    ): array {
        $base = fn() => $this->buildQuery($search, $batchFrom, $batchTo, $courses, $statuses);

        $totalAlumni = (clone $base())->count();

        $withEmp = fn() => (clone $base())->whereNotNull('et.employment_status');

        $totalEmployed   = (clone $withEmp())->where('et.employment_status', 'employed')->count();
        $totalSelf       = (clone $withEmp())->where('et.employment_status', 'self_employed')->count();
        $totalUnemployed = (clone $withEmp())->where('et.employment_status', 'unemployed')->count();
        $totalNotFilled  = max(0, $totalAlumni - $totalEmployed - $totalSelf - $totalUnemployed);

        $totalLocal = (clone $withEmp())
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'local')->count();

        $totalOFW = (clone $withEmp())
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'abroad')->count();

        // course_relevance isn't selected in buildQuery()'s minimal column
        // list, so pull the relatedness breakdown with its own query.
        $relBase = DB::table('alumni as a')
            ->join('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->whereIn('et.employment_status', ['employed', 'self_employed']);

        if ($batchFrom !== '' && $batchTo !== '') {
            $relBase->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo);
        }
        if (!empty($courses)) {
            $relBase->whereIn('a.course_code', $courses);
        }

        $totalRelated    = (clone $relBase)->where('et.course_relevance', 'yes')->count();
        $totalPartial    = (clone $relBase)->where('et.course_relevance', 'partially')->count();
        $totalNotRelated = (clone $relBase)->where('et.course_relevance', 'no')->count();

        return compact(
            'totalAlumni', 'totalEmployed', 'totalSelf', 'totalUnemployed', 'totalNotFilled',
            'totalLocal', 'totalOFW', 'totalRelated', 'totalPartial', 'totalNotRelated'
        );
    }

    private function exportPdf($records, array $stats, $generatedAt, callable $formatName, callable $statusLabel)
    {
        $pdf = Pdf::loadView('admin.employment-tracking-print', compact(
            'records', 'stats', 'generatedAt', 'formatName', 'statusLabel'
        ))->setPaper('a4', 'portrait');

        $filename = 'employment-tracking-' . $generatedAt->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Plain HTML table wrapped in the Microsoft Office XML namespace so
     * Excel opens it as a real .xls workbook instead of prompting a
     * "format doesn't match extension" warning — same fix already applied
     * to the Employment Tracking compare-tool export.
     */
    private function exportExcel($records, callable $formatName, callable $statusLabel, $generatedAt)
    {
        $filename = 'employment-tracking-' . $generatedAt->format('Y-m-d_His') . '.xls';

        $rowsHtml = '';
        foreach ($records as $r) {
            $rowsHtml .= '<tr>'
                . '<td>' . e(strtoupper($formatName($r))) . '</td>'
                . '<td>' . e($r->student_id) . '</td>'
                . '<td>' . e($r->course_code) . '</td>'
                . '<td>' . e($r->batch) . '</td>'
                . '<td>' . e($statusLabel($r)) . '</td>'
                . '<td>' . e($r->company_name ?? '—') . '</td>'
                . '<td>' . e($r->job_title ?? '—') . '</td>'
                . '<td>' . e($r->work_location ? ucfirst($r->work_location) : '—') . '</td>'
                . '</tr>';
        }

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            . 'xmlns="http://www.w3.org/TR/REC-html40">'
            . '<head><meta charset="utf-8">'
            . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>'
            . '<x:ExcelWorksheet><x:Name>Employment Tracking</x:Name>'
            . '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>'
            . '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->'
            . '<style>table{border-collapse:collapse;} th,td{border:1px solid #ccc;padding:4px 8px;font-size:12px;} '
            . 'th{background:#F5F0FA;font-weight:bold;}</style></head><body>'
            . '<table><thead><tr>'
            . '<th>Name</th><th>Student ID</th><th>Program Code</th><th>Batch</th>'
            . '<th>Employment Status</th><th>Company</th><th>Job Title</th><th>Location</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}