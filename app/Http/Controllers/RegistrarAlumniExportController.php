<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
     *
     * FIX (batch range + multi-program + employment status mismatch):
     * this used to read `batch` as a single exact-match value and
     * `course` as a single string, both leftovers from before the Volt
     * component switched Batch to a FROM/TO range and Program Code /
     * Employment Status to multi-selects. The export JS (see
     * doExport() in alumni-records.blade.php) has been sending
     * `batch_from` / `batch_to`, a comma-separated `course` list, and a
     * comma-separated `employment_status` list for a while now — this
     * method was never updated to read them, so a batch range or a
     * multi-program selection produced an empty or wrong result set in
     * the exports while the on-screen table filtered correctly.
     */
    private function filteredRecords(Request $request)
    {
        $q = Alumni::query()->select([
            'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'course_name', 'batch',
            'email', 'profile_completed', 'created_at',
        ]);

        // Same "latest non-deleted employment_trackings row" scalar
        // subquery the Volt component's alumniRecords() uses, so the
        // Employment Status column in the exports (print/PDF already
        // read $item->employment_status — see
        // alumni-records-print.blade.php) is actually populated instead
        // of always falling back to "No Record".
        $q->addSelect(['employment_status' => DB::table('employment_trackings')
            ->select('employment_status')
            ->whereColumn('employment_trackings.alumni_id', 'alumni.id')
            ->whereNull('employment_trackings.deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1),
        ]);

        $search = trim((string) $request->query('search', ''));
        $filter = (string) $request->query('profile_filter', 'all');

        // Batch is now a FROM/TO range. batch_from/batch_to are the
        // real params sent by the export button; `batch` (old single
        // value) is kept only as a fallback for any caller that hasn't
        // been updated to send the range params yet.
        $batchFrom = trim((string) $request->query('batch_from', ''));
        $batchTo   = trim((string) $request->query('batch_to', ''));
        if ($batchFrom === '' && $batchTo === '') {
            $legacyBatch = trim((string) $request->query('batch', ''));
            if ($legacyBatch !== '') {
                $batchFrom = $legacyBatch;
                $batchTo   = $legacyBatch;
            }
        }

        // Program Code is now a multi-select, sent as a comma-separated
        // list (e.g. "BSIT,BSCS").
        $courses = collect(explode(',', (string) $request->query('course', '')))
            ->map(fn ($c) => trim($c))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->values()
            ->all();

        // Employment Status is now a multi-select too, same
        // comma-separated treatment.
        $employmentStatuses = collect(explode(',', (string) $request->query('employment_status', '')))
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => $s !== '')
            ->unique()
            ->values()
            ->all();

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

        $batchRangeComplete = $batchFrom !== '' && $batchTo !== '';

        if ($batchRangeComplete) {
            $q->where('batch', '>=', $batchFrom)
              ->where('batch', '<=', $batchTo);
        }

        if (!empty($courses)) {
            $q->whereIn('course_code', $courses);
        }

        if ($filter === 'complete') {
            $q->where('profile_completed', 1);
        } elseif ($filter === 'incomplete') {
            $q->where('profile_completed', 0);
        }

        if (!empty($employmentStatuses)) {
            $this->applyEmploymentStatusFilter($q, $employmentStatuses);
        }

        $hasActiveFilter = $search !== ''
            || $batchRangeComplete
            || !empty($courses)
            || $filter !== 'all'
            || !empty($employmentStatuses);

        // Same deterministic ordering (with an id tie-breaker) as the
        // alumni-records Volt component alumniRecords() computed
        // property, so the first row on screen always matches the first
        // row in every export.
        //
        // FIX (export started at 2025 instead of 2013 for a
        // Batch 2013–2025 filter): this method never sorted by `batch`
        // at all — a range filter only constrained WHICH rows came
        // back, not the ORDER they came back in, so exports fell back
        // to course_code/name ordering and mixed years together instead
        // of reading oldest batch → newest batch like the on-screen
        // table does. Now mirrors alumniRecords()/pageFor() in the Volt
        // component exactly: when a From–To batch range is active,
        // `batch` leads the sort (ascending — From's year first, To's
        // year last), with program/name/id as tie-breakers within each
        // batch year.
        if ($batchRangeComplete) {
            $q->orderBy('batch')
              ->orderBy('course_code')
              ->orderBy('last_name')
              ->orderBy('first_name')
              ->orderBy('id');
        } elseif ($hasActiveFilter) {
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

    /** Constrains a query to alumni whose LATEST (most recent, non-deleted)
     *  employment_trackings row matches ANY of the given statuses (OR'd
     *  together) — mirrors applyEmploymentStatusFilter() in the
     *  alumni-records Volt component exactly, so exports match the
     *  on-screen filtering. 'no_record' means the alumni has no
     *  employment_trackings row at all. */
    private function applyEmploymentStatusFilter($q, array $statuses): void
    {
        $statuses = array_values(array_unique($statuses));
        if (empty($statuses)) return;

        $realStatuses = array_values(array_diff($statuses, ['no_record']));
        $wantsNoRecord = in_array('no_record', $statuses, true);

        $latestPerAlumni = DB::table('employment_trackings')
            ->select('alumni_id', DB::raw('MAX(id) as latest_id'))
            ->whereNull('deleted_at')
            ->groupBy('alumni_id');

        $q->where(function ($outer) use ($realStatuses, $wantsNoRecord, $latestPerAlumni) {
            if (!empty($realStatuses)) {
                $outer->orWhereIn('id', function ($sub) use ($realStatuses, $latestPerAlumni) {
                    $sub->select('et.alumni_id')
                        ->from('employment_trackings as et')
                        ->joinSub($latestPerAlumni, 'latest', function ($join) {
                            $join->on('latest.latest_id', '=', 'et.id');
                        })
                        ->whereIn('et.employment_status', $realStatuses);
                });
            }
            if ($wantsNoRecord) {
                $outer->orWhereNotExists(fn ($s) => $s
                    ->from('employment_trackings')
                    ->whereColumn('employment_trackings.alumni_id', 'id')
                    ->whereNull('employment_trackings.deleted_at'));
            }
        });
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

    /*
     * FIX (all-rows-showed-Complete bug): this used to OR the DB flag
     * with a derived-from-basic-fields check (first_name, last_name,
     * middle_initial, student_id, course_code, batch, email). Those
     * fields are always populated at registration/bulk-import time
     * regardless of whether the alumnus has actually finished their full
     * profile (address, parent info, disability, etc via the alumni
     * portal), so that derived check was true for nearly every record,
     * which made every single row show Complete in the exports no
     * matter what profile_completed actually said in the database.
     *
     * profile_completed is the authoritative flag, set by the alumni
     * portal own more thorough completion check, so Status now comes
     * straight from that column, no derived fallback, no guessing.
     */
    private function isProfileComplete($item): bool
    {
        return (bool) ($item->profile_completed ?? false);
    }

    /** Label used for the Employment Status column in the Excel export —
     *  mirrors employmentStatusBadge() in the Volt component (label
     *  only, no icon/classes needed for a spreadsheet cell). */
    private function employmentStatusLabel($item): string
    {
        return match ($item->employment_status ?? null) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'unemployed'    => 'Unemployed',
            default         => 'No Record',
        };
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

    /*
     * Columns: Name, Student ID, Standard Abbreviation, Batch, Email,
     * Employment Status, Status. Status reflects the real
     * profile_completed value per row; Employment Status now reflects
     * the latest employment_trackings row instead of being missing
     * entirely.
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

        $headers = ['Name', 'Student ID', 'Standard Abbreviation', 'Batch', 'Email', 'Employment Status', 'Status'];
        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:G1';
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
                $this->employmentStatusLabel($item),
                $isComplete ? 'Complete' : 'Pending',
            ];
        }
        if (count($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $rowNum = 2;
        foreach ($statusFlags as $isComplete) {
            $cell = 'G' . $rowNum;
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
            ->setOptions([
                'isRemoteEnabled' => false,
                'dpi'             => 96,
                'defaultFont'     => 'sans-serif',
            ])
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
            'message' => "The {$package} package is not installed correctly. "
                . 'Run composer install/require again for it, then retry.',
        ], 500);
    }
}