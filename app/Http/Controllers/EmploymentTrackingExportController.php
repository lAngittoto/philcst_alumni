<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

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
                return $this->exportExcel($records, $summary, $user->name ?? 'Registrar');
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
     * FIX (Excel "format and extension don't match" warning): dati,
     * ang exportExcel() ay nagse-serve ng HTML na nilagyan lang ng
     * ".xls" extension at "application/vnd.ms-excel" content-type.
     * Ang Excel mismo ay sinisipat/sinni-sniff yung actual bytes ng
     * file bago ito buksan — nakikita niyang HTML pala ang laman kahit
     * ".xls" ang pangalan, kaya lumalabas yung "file format and
     * extension don't match" prompt.
     *
     * Ngayon, totoong binary .xlsx na ang gawa gamit ang
     * PhpOffice\PhpSpreadsheet — parang ginawa na natin sa
     * RegistrarAlumniExportController — kaya tugma na yung tunay na
     * laman ng file sa extension nito. Layout mirrors the PDF's
     * section-by-section structure: report title/meta block muna,
     * tapos summary counts, tapos yung detailed per-alumnus table.
     */
    private function exportExcel($records, string $summary, string $generatedBy): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! class_exists(Spreadsheet::class)) {
            return response()->json([
                'message' => 'Excel export is not available yet. Kulang pa yung "phpoffice/phpspreadsheet" package sa server — patakbuhin muna ang: composer require phpoffice/phpspreadsheet',
            ], 500);
        }

        $filename = 'employment-tracking-report-' . now()->format('Y-m-d_His') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employment Tracking');

        $purple = '7A3F91';
        $lightPurple = 'F5F0FA';
        $bodyGray = '333333';

        // ---- Section 1: Report header / meta (mirrors PDF header block) ----
        $sheet->setCellValue('A1', 'Employment Tracking Report');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($purple);

        $sheet->setCellValue('A2', 'Scope: ' . $summary);
        $sheet->mergeCells('A2:D2');
        $sheet->getStyle('A2')->getFont()->getColor()->setRGB($bodyGray);

        $sheet->setCellValue('A3', 'Generated: ' . now()->format('F j, Y g:i A') . ' by ' . $generatedBy);
        $sheet->mergeCells('A3:D3');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB($bodyGray);

        // ---- Section 2: Summary counts (mirrors PDF summary block) ----
        $counts = [
            'Employed'     => 0,
            'Unemployed'   => 0,
            'Underemployed' => 0,
            'No Record'    => 0,
        ];

        foreach ($records as $r) {
            $status = $r->employment_status;
            if ($status === null || $status === '') {
                $counts['No Record']++;
            } elseif (isset($counts[$status])) {
                $counts[$status]++;
            } else {
                // Any status value not in the predefined buckets still gets counted
                // under its own label instead of being silently dropped.
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        $summaryRow = 5;
        $sheet->setCellValue('A' . $summaryRow, 'Summary');
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->getColor()->setRGB($purple);
        $summaryRow++;

        foreach ($counts as $label => $count) {
            $sheet->setCellValue('A' . $summaryRow, $label);
            $sheet->setCellValue('B' . $summaryRow, $count);
            $sheet->getStyle('A' . $summaryRow . ':B' . $summaryRow)->getFont()->getColor()->setRGB($bodyGray);
            $summaryRow++;
        }
        $sheet->setCellValue('A' . $summaryRow, 'Total');
        $sheet->setCellValue('B' . $summaryRow, count($records));
        $sheet->getStyle('A' . $summaryRow . ':B' . $summaryRow)->getFont()->setBold(true);

        // ---- Section 3: Detailed per-alumnus table ----
        $tableStartRow = $summaryRow + 2;

        $headers = [
            'Name', 'Student ID', 'Program', 'Batch', 'Email', 'Contact Number',
            'Employment Status', 'Course Relevance', 'Work Location', 'Date Recorded',
        ];
        $sheet->fromArray($headers, null, 'A' . $tableStartRow);

        $headerRange = 'A' . $tableStartRow . ':J' . $tableStartRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB($bodyGray);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($lightPurple);
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($headerRange)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $dataRow = $tableStartRow + 1;
        foreach ($records as $r) {
            $name = $this->formatName($r);
            $status = ($r->employment_status === null || $r->employment_status === '')
                ? 'No Record'
                : $r->employment_status;

            $sheet->fromArray([
                $name,
                $r->student_id,
                $r->course_code,
                $r->batch,
                $r->email,
                $r->contact_number,
                $status,
                $r->course_relevance ?? '—',
                $r->work_location ?? '—',
                $r->created_at ? \Illuminate\Support\Carbon::parse($r->created_at)->format('M j, Y') : '—',
            ], null, 'A' . $dataRow);

            $dataRow++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($tableStartRow + 1));

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filePath = tempnam(sys_get_temp_dir(), 'emp_tracking_xlsx_');
        $writer->save($filePath);

        return response()->streamDownload(function () use ($filePath) {
            readfile($filePath);
            @unlink($filePath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function formatName($item): string
    {
        $parts = [trim($item->first_name ?? '')];

        if (trim($item->middle_initial ?? '') !== '') {
            $parts[] = strtoupper(substr(trim($item->middle_initial), 0, 1)) . '.';
        }

        $parts[] = trim($item->last_name ?? '');

        if (trim($item->suffix ?? '') !== '') {
            $parts[] = trim($item->suffix);
        }

        return implode(' ', array_filter($parts));
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