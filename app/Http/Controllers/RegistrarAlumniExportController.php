<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class RegistrarAlumniExportController extends Controller
{
    /**
     * Apply the SAME filters used by the alumni-records Volt component
     * so exports always match what the registrar sees on screen.
     */
    private function filteredRecords(Request $request)
    {
        $q = Alumni::query()->select([
            'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'course_name', 'batch',
            'email', 'profile_completed', 'created_at',
        ]);

        $search = trim((string) $request->query('search', ''));
        $batch  = trim((string) $request->query('batch', ''));
        $course = trim((string) $request->query('course', ''));
        $filter = (string) $request->query('profile_filter', 'all');

        if ($search !== '') {
            $term = '%' . $search . '%';
            $q->where(function ($s) use ($term) {
                $s->where('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('student_id', 'like', $term)
                  ->orWhere('course_code', 'like', $term)
                  ->orWhere('course_name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if ($batch !== '')  $q->where('batch', $batch);
        if ($course !== '') $q->where('course_code', $course);

        if ($filter === 'complete') {
            $q->where('profile_completed', 1);
        } elseif ($filter === 'incomplete') {
            $q->where('profile_completed', 0);
        }

        $hasActiveFilter = $search !== '' || $batch !== '' || $course !== '' || $filter !== 'all';

        // Same deterministic ordering (with `id` tie-breaker) as the
        // alumni-records Volt component's alumniRecords() computed
        // property, so the first row on screen always matches the first
        // row in every export.
        if ($hasActiveFilter) {
            $q->orderBy('course_code')
              ->orderBy('last_name')
              ->orderBy('first_name')
              ->orderBy('id');
        } else {
            $q->orderByDesc('created_at')
              ->orderByDesc('id');
        }

        return $q->get();
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
     * FIX (all-rows-showed-Complete bug): this used to OR the DB flag
     * with a "derived from basic fields" check (first_name, last_name,
     * middle_initial, student_id, course_code, batch, email). Those
     * fields are always populated at registration/bulk-import time
     * regardless of whether the alumnus has actually finished their full
     * profile (address, parents' info, disability, etc. via the alumni
     * portal) — so that derived check was true for nearly every record,
     * which made every single row show "Complete" in the exports no
     * matter what profile_completed actually said in the database.
     *
     * profile_completed is the authoritative flag (set by the alumni
     * portal's own, more thorough completion check), so Status now comes
     * straight from that column — no derived fallback, no guessing.
     */
    private function isProfileComplete($item): bool
    {
        return (bool) ($item->profile_completed ?? false);
    }

    public function export(Request $request)
    {
        $type = $request->query('type', 'pdf');

        try {
            $records = $this->filteredRecords($request);

            return match ($type) {
                'excel' => $this->toXlsx($records),
                'print' => $this->toPrintView($records),
                default => $this->toPdf($records),
            };
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Report generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Columns: Name, Student ID, Program Code, Batch, Email, Status.
     * Status now reflects the real profile_completed value per row —
     * Complete and Pending will both actually appear, matching the
     * true mix of alumni records.
     */
    private function toXlsx($records): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! class_exists(Spreadsheet::class)) {
            return $this->missingPackageResponse('phpoffice/phpspreadsheet');
        }

        $filename = 'alumni-records-' . now()->format('Ymd-His') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Alumni Records');

        $headers = ['Name', 'Student ID', 'Program Code', 'Batch', 'Email', 'Status'];
        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:F1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('333333');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5F0FA');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $rows = [];
        $statusFlags = [];
        foreach ($records as $item) {
            $isComplete = $this->isProfileComplete($item);
            $statusFlags[] = $isComplete;

            $rows[] = [
                $this->formatName($item),
                $item->student_id,
                $item->course_code,
                $item->batch,
                $item->email,
                $isComplete ? 'Complete' : 'Pending',
            ];
        }
        if (count($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $rowNum = 2;
        foreach ($statusFlags as $isComplete) {
            $cell = 'F' . $rowNum;
            $sheet->getStyle($cell)->getFont()->setBold(true)
                ->getColor()->setRGB($isComplete ? '059669' : 'D97706');
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($isComplete ? 'ECFDF5' : 'FFFBEB');
            $rowNum++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filePath = tempnam(sys_get_temp_dir(), 'alumni_xlsx_');
        $writer->save($filePath);

        return response()->streamDownload(function () use ($filePath) {
            readfile($filePath);
            @unlink($filePath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function toPdf($records)
    {
        if (! class_exists(Pdf::class)) {
            return $this->missingPackageResponse('barryvdh/laravel-dompdf');
        }

        $html = view('registrar.alumni-records-print', [
            'records'     => $records,
            'formatName'  => fn ($item) => $this->formatName($item),
            'isComplete'  => fn ($item) => $this->isProfileComplete($item),
            'generatedAt' => now(),
        ])->render();

        $filename = 'alumni-records-' . now()->format('Ymd-His') . '.pdf';

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions(['isHtml5ParserEnabled' => true, 'isPhpEnabled' => true])
            ->download($filename);
    }

    private function toPrintView($records)
    {
        return view('registrar.alumni-records-print', [
            'records'     => $records,
            'formatName'  => fn ($item) => $this->formatName($item),
            'isComplete'  => fn ($item) => $this->isProfileComplete($item),
            'generatedAt' => now(),
        ]);
    }

    private function missingPackageResponse(string $package): JsonResponse
    {
        return response()->json([
            'message' => "The \"{$package}\" package isn't installed correctly. "
                . 'Run composer install/require again for it, then retry.',
        ], 500);
    }
}