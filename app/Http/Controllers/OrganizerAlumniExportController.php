<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class OrganizerAlumniExportController extends Controller
{
    /**
     * Applies organizer scoping + all active filters (search, status,
     * batch range, course) to a base alumni+employment query builder.
     * Used as the shared foundation for BOTH the record list
     * (filteredRecords()) and the stat-card totals (computeFilteredStats())
     * below, so the numbers on the printed/exported cards always describe
     * exactly the same rows that appear in the exported table — mirrors
     * filteredAlumniQuery() in alumni-employment.blade.php.
     */
    private function scopedQuery(Request $request): \Illuminate\Database\Query\Builder
    {
        $user = auth()->user();
        abort_unless($user && $user->role === 'organizer', 403);

        $organizer = DB::table('organizer')
            ->where('user_id', $user->id)
            ->select(['batch', 'department'])
            ->first();

        $organizerBatch      = $organizer->batch      ?? '';
        $organizerDepartment = $organizer->department ?? '';

        $allowedCourseCodes = $organizerDepartment
            ? DB::table('courses')->where('college', $organizerDepartment)->pluck('code')->toArray()
            : [];

        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at');

        if ($organizerBatch) {
            $q->where('a.batch', $organizerBatch);
        }
        if (!empty($allowedCourseCodes)) {
            $q->whereIn('a.course_code', $allowedCourseCodes);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $q->where(function ($s) use ($term) {
                $s->where(DB::raw("CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))"), 'like', $term)
                  ->orWhere('a.student_id', 'like', $term)
                  ->orWhere('a.email', 'like', $term)
                  ->orWhere('et.company_name', 'like', $term)
                  ->orWhere('et.job_title', 'like', $term);
            });
        }

        // Batch is a FROM/TO range, same as Alumni Records.
        $batchFrom = trim((string) $request->query('batch_from', ''));
        $batchTo   = trim((string) $request->query('batch_to', ''));
        $batchRangeComplete = $batchFrom !== '' && $batchTo !== '';
        if ($batchRangeComplete) {
            $q->where('a.batch', '>=', $batchFrom)
              ->where('a.batch', '<=', $batchTo);
        }

        // Program Code is a single value on this page (unlike Alumni
        // Records' multi-select) — mirrors filterCourse in the Volt
        // component.
        $course = trim((string) $request->query('course', ''));
        if ($course !== '') {
            $q->where('a.course_code', $course);
        }

        // Employment Status is a multi-select, comma-separated, sent by
        // the export button — mirrors filterStatuses. 'not_filled' means
        // no employment_trackings row at all.
        $statuses = collect(explode(',', (string) $request->query('status', '')))
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => $s !== '')
            ->unique()
            ->values()
            ->all();

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

        return $q;
    }

    /**
     * Apply the SAME filters + organizer scoping used by
     * alumni-employment.blade.php's filteredAlumniQuery()/with(), so exports
     * always match what the organizer sees on screen.
     */
    private function filteredRecords(Request $request)
    {
        $q = $this->scopedQuery($request)
            ->select([
                'a.id',
                'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                'a.student_id', 'a.course_code', 'a.course_name', 'a.batch', 'a.email',
                'et.employment_status',
                'et.company_name',
                'et.job_title',
                'et.employment_type',
                'et.work_location',
                'et.date_hired',
            ]);

        // Same ordering as the Volt component's with(): unfilled records
        // last, then alphabetical by last name.
        $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
          ->orderBy('a.last_name')
          ->orderBy('a.id');

        return $q->get();
    }

    /**
     * Recomputes every sidebar stat card total using the SAME organizer
     * scoping + active filters as filteredRecords() above — mirrors
     * computeStats() in alumni-employment.blade.php, so the cards printed
     * at the top of the PDF/Excel/print report always describe exactly
     * the filtered rows in the table beneath them, not organizer-wide
     * totals.
     */
    private function computeFilteredStats(Request $request): array
    {
        $base = $this->scopedQuery($request);

        $totalAlumni = (clone $base)->distinct()->count('a.id');

        $withEmp = (clone $base)->whereNotNull('et.employment_status');

        $totalEmployed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $totalSelf       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $totalUnemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $totalNotFilled  = max(0, $totalAlumni - $totalEmployed - $totalSelf - $totalUnemployed);

        $totalLocal = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'local')
            ->count();

        $totalOFW = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'abroad')
            ->count();

        $totalRelated = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'yes')
            ->count();

        $totalPartial = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'partially')
            ->count();

        $totalNotRelated = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'no')
            ->count();

        return [
            'totalAlumni'     => $totalAlumni,
            'totalEmployed'   => $totalEmployed,
            'totalSelf'       => $totalSelf,
            'totalUnemployed' => $totalUnemployed,
            'totalNotFilled'  => $totalNotFilled,
            'totalLocal'      => $totalLocal,
            'totalOFW'        => $totalOFW,
            'totalRelated'    => $totalRelated,
            'totalPartial'    => $totalPartial,
            'totalNotRelated' => $totalNotRelated,
        ];
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

    /** Label used for the Employment Status column — mirrors
     *  employmentStatusBadge()/the $statusLabel match() blocks already
     *  used throughout alumni-employment.blade.php. */
    private function employmentStatusLabel($item): string
    {
        return match ($item->employment_status ?? null) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'unemployed'    => 'Unemployed',
            default         => 'Not Filled',
        };
    }

    public function export(Request $request)
    {
        $type = $request->query('type', 'pdf');

        try {
            $records = $this->filteredRecords($request);
            $stats   = $this->computeFilteredStats($request);

            return match ($type) {
                'excel' => $this->toXlsx($records, $stats),
                'print' => $this->toPrintView($records, $stats),
                default => $this->toPdf($records, $stats),
            };
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Report generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /*
     * Columns: Name, Student ID, Program Code, Batch, Employment Status,
     * Company, Job Title, Location. A stat summary block is written above
     * the header row, mirroring the on-screen sidebar cards.
     */
    private function toXlsx($records, array $stats): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! class_exists(Spreadsheet::class)) {
            return $this->missingPackageResponse('phpoffice/phpspreadsheet');
        }

        $filename = 'alumni-employment-' . now()->format('Ymd-His') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employment Tracking');

        // ── Stat summary block (rows 1-2), mirrors the sidebar cards ──
        $summaryPairs = [
            'Total Alumni'    => $stats['totalAlumni'],
            'Employed'        => $stats['totalEmployed'],
            'Self-Employed'   => $stats['totalSelf'],
            'Unemployed'      => $stats['totalUnemployed'],
            'Not Filled'      => $stats['totalNotFilled'],
            'Local'           => $stats['totalLocal'],
            'OFW'             => $stats['totalOFW'],
            'Course-Related'  => $stats['totalRelated'],
            'Partially Rel.'  => $stats['totalPartial'],
            'Not Related'     => $stats['totalNotRelated'],
        ];
        $col = 'A';
        foreach ($summaryPairs as $label => $value) {
            $sheet->setCellValue($col . '1', $label);
            $sheet->setCellValue($col . '2', $value);
            $sheet->getStyle($col . '1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('7A3F91');
            $sheet->getStyle($col . '2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('333333');
            $col++;
        }
        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F0FA');
        $sheet->getStyle('A1:J2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Table headers now start at row 4 ──
        $headerRow = 4;
        $headers = ['Name', 'Student ID', 'Program Code', 'Batch', 'Employment Status', 'Company', 'Job Title', 'Location'];
        $sheet->fromArray($headers, null, 'A' . $headerRow);

        $headerRange = 'A' . $headerRow . ':H' . $headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('333333');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5F0FA');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $firstDataRow = $headerRow + 1;
        $rows = [];
        $statusFlags = [];
        foreach ($records as $item) {
            $label = $this->employmentStatusLabel($item);
            $statusFlags[] = $label;

            $rows[] = [
                $this->formatName($item),
                $item->student_id,
                $item->course_code,
                $item->batch,
                $label,
                $item->company_name,
                $item->job_title,
                $item->work_location ? ucfirst($item->work_location) : '',
            ];
        }
        if (count($rows)) {
            $sheet->fromArray($rows, null, 'A' . $firstDataRow);
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->freezePane('A' . $firstDataRow);

        $statusColors = [
            'Employed'      => ['059669', 'ECFDF5'],
            'Self-Employed' => ['1D4ED8', 'EFF6FF'],
            'Unemployed'    => ['B45309', 'FFFBEB'],
            'Not Filled'    => ['6B7280', 'F3F4F6'],
        ];

        $rowNum = $firstDataRow;
        foreach ($statusFlags as $label) {
            [$fontColor, $fillColor] = $statusColors[$label] ?? ['333333', 'FFFFFF'];
            $cell = 'E' . $rowNum;
            $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB($fontColor);
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($fillColor);
            $rowNum++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filePath = tempnam(sys_get_temp_dir(), 'emp_xlsx_');
        $writer->save($filePath);

        return response()->streamDownload(function () use ($filePath) {
            readfile($filePath);
            @unlink($filePath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function toPdf($records, array $stats)
    {
        if (! class_exists(Pdf::class)) {
            return $this->missingPackageResponse('barryvdh/laravel-dompdf');
        }

        $html = view('livewire.organizer.organizer-alumni-print', [
            'records'     => $records,
            'stats'       => $stats,
            'formatName'  => fn ($item) => $this->formatName($item),
            'statusLabel' => fn ($item) => $this->employmentStatusLabel($item),
            'generatedAt' => now(),
        ])->render();

        $filename = 'alumni-employment-' . now()->format('Ymd-His') . '.pdf';

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,
                'dpi'             => 96,
                'defaultFont'     => 'sans-serif',
            ])
            ->download($filename);
    }

    private function toPrintView($records, array $stats)
    {
        return view('livewire.organizer.organizer-alumni-print', [
            'records'     => $records,
            'stats'       => $stats,
            'formatName'  => fn ($item) => $this->formatName($item),
            'statusLabel' => fn ($item) => $this->employmentStatusLabel($item),
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