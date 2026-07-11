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

        return $q->orderByDesc('created_at')->get();
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
     * type=pdf    -> downloads a real PDF file
     * type=excel  -> downloads a real .xlsx file (PhpSpreadsheet)
     * type=print  -> returns clean printable HTML (used inside a hidden iframe)
     *
     * Every branch is wrapped so a failure returns a clean JSON error
     * response (with a real HTTP status code) instead of a raw fatal-error
     * page — the frontend's fetch() relies on getting SOME response back
     * quickly so it can stop its loading spinner.
     */
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

    private function toXlsx($records): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! class_exists(Spreadsheet::class)) {
            return $this->missingPackageResponse('phpoffice/phpspreadsheet');
        }

        $filename = 'alumni-records-' . now()->format('Ymd-His') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Alumni Records');

        $headers = ['Name', 'Student ID', 'Course', 'Batch', 'Email', 'Status'];
        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:F1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('333333');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5F0FA');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $row = 2;
        foreach ($records as $item) {
            $sheet->fromArray([
                $this->formatName($item),
                $item->student_id,
                $item->course_code,
                $item->batch,
                $item->email,
                $item->profile_completed ? 'Complete' : 'Pending',
            ], null, 'A' . $row);
            $row++;
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
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
            'generatedAt' => now(),
        ])->render();

        $filename = 'alumni-records-' . now()->format('Ymd-His') . '.pdf';

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }

    private function toPrintView($records)
    {
        return view('registrar.alumni-records-print', [
            'records'     => $records,
            'formatName'  => fn ($item) => $this->formatName($item),
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