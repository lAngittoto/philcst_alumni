<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generate Reports (PDF / Excel / Print) for the Admin "Employment
 * Tracking" (Employment Analytics) dashboard —
 * resources/views/livewire/admin/employment-tracking.blade.php.
 *
 * This is a STATS/CHARTS export, not a per-alumni record listing — it
 * mirrors every stat card and every chart section on screen (Status,
 * Location, Relevance, Employment Type, Career Path, Further Education,
 * Top Courses, By Batch, By College, Unemployed breakdown), scoped by
 * the same Batch Year range / College / Programs / Employment Status
 * filters as the dashboard. No alumni names or student IDs appear in
 * any of the three formats.
 *
 * Query shapes below are kept 1:1 with the Livewire component's
 * baseQ()/empQ()/buildAllCharts() so the exported numbers always match
 * what's on screen. (Note: the dashboard's $search property was removed
 * — it scoped stat cards/charts by name/student ID, which never matched
 * anything since those are category breakdowns, not lists of people —
 * so this controller no longer accepts a search param either.)
 */
class AdminEmploymentTrackingExportController extends Controller
{
    public function export(Request $request)
    {
        $type = $request->query('type', 'pdf');

        $batchFrom = trim((string) $request->query('batch_from', ''));
        $batchTo   = trim((string) $request->query('batch_to', ''));
        $college   = trim((string) $request->query('college', ''));

        // Comma-separated lists — mirrors how the on-screen filters are
        // sent (see the doExport() params wired up in
        // employment-tracking.blade.php's Generate Reports button).
        $courses = array_values(array_filter(array_map('trim',
            explode(',', (string) $request->query('course', ''))
        )));
        $statuses = array_values(array_filter(array_map('trim',
            explode(',', (string) $request->query('status', ''))
        )));
        // The dashboard only ever sends one status value via $filterStatus,
        // but buildQuery-style filtering supports a list for forward
        // compatibility — take the first if present.
        $status = $statuses[0] ?? '';

        $data        = $this->buildChartData($batchFrom, $batchTo, $college, $courses, $status);
        $generatedAt = now();

        return match ($type) {
            'excel' => $this->exportExcel($data, $generatedAt),
            'print' => view('admin.employment-tracking-print', [
                'data'        => $data,
                'generatedAt' => $generatedAt,
            ]),
            default => $this->exportPdf($data, $generatedAt),
        };
    }

    private function courseCodesForCollege(string $college): array
    {
        if ($college === '') return [];

        return DB::table('courses')
            ->where('college', $college)
            ->pluck('code')
            ->all();
    }

    /**
     * Alumni-level base query — batch range / college / program filters
     * only. Mirrors the Livewire component's baseQ() (minus $search,
     * which no longer scopes anything here — see class docblock).
     */
    private function baseQ(string $batchFrom, string $batchTo, string $college, array $courses): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni as a')->whereNull('a.deleted_at');

        if ($batchFrom !== '' && $batchTo !== '') {
            $q->where('a.batch', '>=', $batchFrom)
              ->where('a.batch', '<=', $batchTo);
        }

        if ($college !== '') {
            $q->whereIn('a.course_code', $this->courseCodesForCollege($college));
        }

        if (!empty($courses)) {
            $q->whereIn('a.course_code', $courses);
        }

        return $q;
    }

    /**
     * Alumni + employment_trackings join, additionally scoped by
     * Employment Status — mirrors the Livewire component's empQ().
     */
    private function empQ(string $batchFrom, string $batchTo, string $college, array $courses, string $status): \Illuminate\Database\Query\Builder
    {
        $q = $this->baseQ($batchFrom, $batchTo, $college, $courses)
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        if ($status !== '' && $status !== 'not_filled') {
            $q->where('et.employment_status', $status);
        }

        return $q;
    }

    /**
     * Builds every stat card figure and every chart's data, scoped by
     * the given filters. Structure mirrors computeStats() +
     * buildAllCharts() in the Livewire component so PDF/Print/Excel and
     * the on-screen dashboard never drift apart.
     */
    private function buildChartData(
        string $batchFrom,
        string $batchTo,
        string $college,
        array $courses,
        string $status,
    ): array {
        // ── Summary stat cards ──────────────────────────────────────────
        $totalAlumni     = (clone $this->baseQ($batchFrom, $batchTo, $college, $courses))->distinct()->count('a.id');
        $totalEmployed   = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->where('et.employment_status', 'employed')->count();
        $totalSelf       = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->where('et.employment_status', 'self_employed')->count();
        $totalUnemployed = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->where('et.employment_status', 'unemployed')->count();
        $totalFilled     = $totalEmployed + $totalSelf + $totalUnemployed;
        $totalNotFilled  = max(0, $totalAlumni - $totalFilled);
        $totalLocal      = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->where('et.work_location', 'local')->count();
        $totalAbroad     = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->where('et.work_location', 'abroad')->count();

        $stats = compact(
            'totalAlumni', 'totalEmployed', 'totalSelf', 'totalUnemployed',
            'totalNotFilled', 'totalLocal', 'totalAbroad'
        );

        // ── Employment Status ───────────────────────────────────────────
        $statusChart = [
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [$totalEmployed, $totalSelf, $totalUnemployed, $totalNotFilled],
        ];

        // ── Work Location ───────────────────────────────────────────────
        $locationChart = [
            'labels' => ['Local', 'Abroad (OFW)'],
            'data'   => [$totalLocal, $totalAbroad],
        ];

        // ── Job-Course Relevance ────────────────────────────────────────
        $relRows = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $relevanceChart = [
            'labels' => ['Related', 'Partially', 'Not Related'],
            'data'   => [
                $relRows->get('yes')->cnt       ?? 0,
                $relRows->get('partially')->cnt ?? 0,
                $relRows->get('no')->cnt        ?? 0,
            ],
        ];

        // ── Employment by Batch Year ────────────────────────────────────
        $batchRows = (clone $this->baseQ($batchFrom, $batchTo, $college, $courses))
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->select(
                'a.batch',
                DB::raw("SUM(CASE WHEN et.employment_status='employed'      THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN et.employment_status='self_employed' THEN 1 ELSE 0 END) as self_emp"),
                DB::raw("SUM(CASE WHEN et.employment_status='unemployed'    THEN 1 ELSE 0 END) as unemployed"),
                DB::raw('COUNT(a.id) as total')
            )
            ->groupBy('a.batch')->orderBy('a.batch', 'asc')->get();

        $batchChart = [
            'labels'     => $batchRows->pluck('batch')->values()->all(),
            'employed'   => $batchRows->pluck('employed')->values()->all(),
            'self_emp'   => $batchRows->pluck('self_emp')->values()->all(),
            'unemployed' => $batchRows->pluck('unemployed')->values()->all(),
            'total'      => $batchRows->pluck('total')->values()->all(),
        ];

        // ── Employment by College ───────────────────────────────────────
        $colleges    = DB::table('courses')->distinct()->orderBy('college')->pluck('college')->filter()->values();
        $collegeData = $colleges->map(function ($col) use ($batchFrom, $batchTo) {
            $codes = DB::table('courses')->where('college', $col)->pluck('code');
            $base  = DB::table('alumni as a')->whereNull('a.deleted_at')->whereIn('a.course_code', $codes);
            if ($batchFrom !== '' && $batchTo !== '') {
                $base->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo);
            }
            $total = (clone $base)->count();

            $emp = DB::table('alumni as a')
                ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('a.course_code', $codes);
            if ($batchFrom !== '' && $batchTo !== '') {
                $emp->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo);
            }
            $employed   = (clone $emp)->where('et.employment_status', 'employed')->count();
            $self_emp   = (clone $emp)->where('et.employment_status', 'self_employed')->count();
            $unemployed = (clone $emp)->where('et.employment_status', 'unemployed')->count();

            return compact('col', 'total', 'employed', 'self_emp', 'unemployed');
        });

        $collegeChart = [
            'labels'     => $collegeData->pluck('col')->values()->all(),
            'employed'   => $collegeData->pluck('employed')->values()->all(),
            'self_emp'   => $collegeData->pluck('self_emp')->values()->all(),
            'unemployed' => $collegeData->pluck('unemployed')->values()->all(),
            'total'      => $collegeData->pluck('total')->values()->all(),
        ];

        // ── Top Courses (Employed) ──────────────────────────────────────
        $courseQ = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
            ->whereIn('et.employment_status', ['employed', 'self_employed']);
        if ($batchFrom !== '' && $batchTo !== '') {
            $courseQ->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo);
        }
        if ($college !== '') {
            $courseQ->whereIn('a.course_code', $this->courseCodesForCollege($college));
        }
        if (!empty($courses)) {
            $courseQ->whereIn('a.course_code', $courses);
        }
        $courseRows = $courseQ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->orderByDesc('cnt')->limit(10)->get();

        $courseChart = [
            'labels' => $courseRows->pluck('course_code')->values()->all(),
            'data'   => $courseRows->pluck('cnt')->values()->all(),
        ];

        // ── Employment Type ─────────────────────────────────────────────
        $empTypeRows = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))
            ->whereNotNull('et.employment_type')
            ->select('et.employment_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.employment_type')->get()->keyBy('employment_type');

        $empTypeChart = [
            'labels' => ['Full-Time', 'Part-Time', 'Contractual', 'Project-Based', 'Internship'],
            'data'   => [
                $empTypeRows->get('full_time')->cnt     ?? 0,
                $empTypeRows->get('part_time')->cnt     ?? 0,
                $empTypeRows->get('contractual')->cnt   ?? 0,
                $empTypeRows->get('project_based')->cnt ?? 0,
                $empTypeRows->get('internship')->cnt    ?? 0,
            ],
        ];

        // ── Career Path Labels ──────────────────────────────────────────
        $cpRows   = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))->whereNotNull('et.career_path')->select('et.career_path')->get();
        $cpCounts = ['ofw' => 0, 'freelancer' => 0, 'entrepreneur' => 0, 'career_shifter' => 0, 'industry_professional' => 0];
        foreach ($cpRows as $r) {
            $arr = json_decode($r->career_path, true) ?? [];
            foreach ($arr as $v) { if (isset($cpCounts[$v])) $cpCounts[$v]++; }
        }

        $careerPathChart = [
            'labels' => ['OFW', 'Freelancer', 'Entrepreneur', 'Career Shifter', 'Industry Pro'],
            'data'   => array_values($cpCounts),
        ];

        // ── Further Education ───────────────────────────────────────────
        $eduRows = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))
            ->whereNotNull('et.education_status')
            ->select('et.education_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.education_status')->get()->keyBy('education_status');

        $eduChart = [
            'labels' => ['None', 'Pursuing Masteral', 'Pursuing Doctorate'],
            'data'   => [
                $eduRows->get('none')->cnt               ?? 0,
                $eduRows->get('pursuing_masteral')->cnt  ?? 0,
                $eduRows->get('pursuing_doctorate')->cnt ?? 0,
            ],
        ];

        // ── Unemployed Breakdown ────────────────────────────────────────
        $unRows = (clone $this->empQ($batchFrom, $batchTo, $college, $courses, $status))
            ->where('et.employment_status', 'unemployed')
            ->whereNotNull('et.unemployment_status')
            ->select('et.unemployment_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.unemployment_status')->get()->keyBy('unemployment_status');

        $unemployedChart = [
            'labels' => ['Seeking Employment', 'Not Looking'],
            'data'   => [
                $unRows->get('seeking_employment')->cnt ?? 0,
                $unRows->get('not_looking')->cnt        ?? 0,
            ],
        ];

        return [
            'stats'      => $stats,
            'status'     => $statusChart,
            'location'   => $locationChart,
            'relevance'  => $relevanceChart,
            'batch'      => $batchChart,
            'college'    => $collegeChart,
            'course'     => $courseChart,
            'empType'    => $empTypeChart,
            'careerPath' => $careerPathChart,
            'edu'        => $eduChart,
            'unemployed' => $unemployedChart,
        ];
    }

    private function exportPdf(array $data, $generatedAt)
    {
        $pdf = Pdf::loadView('admin.employment-tracking-print', [
            'data'        => $data,
            'generatedAt' => $generatedAt,
        ])->setPaper('a4', 'portrait');

        $filename = 'employment-analytics-' . $generatedAt->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Plain HTML table (one section per chart/stat group) wrapped in the
     * Microsoft Office XML namespace so Excel opens it as a real .xls
     * workbook instead of prompting a "format doesn't match extension"
     * warning — same fix already applied to the Registrar Employment
     * Tracking export. No per-alumni rows — same stats/charts scope as
     * PDF/Print.
     */
    private function exportExcel(array $data, $generatedAt)
    {
        $filename = 'employment-analytics-' . $generatedAt->format('Y-m-d_His') . '.xls';

        $section = function (string $title, array $labels, array $values) {
            $rows = '';
            foreach ($labels as $i => $label) {
                $rows .= '<tr><td>' . e($label) . '</td><td>' . e($values[$i] ?? 0) . '</td></tr>';
            }
            return '<table><thead><tr><th colspan="2">' . e($title) . '</th></tr>'
                . '<tr><th>Category</th><th>Count</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table><br>';
        };

        $s = $data['stats'];
        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            . 'xmlns="http://www.w3.org/TR/REC-html40">'
            . '<head><meta charset="utf-8">'
            . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>'
            . '<x:ExcelWorksheet><x:Name>Employment Analytics</x:Name>'
            . '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>'
            . '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->'
            . '<style>table{border-collapse:collapse;margin-bottom:8px;} th,td{border:1px solid #ccc;padding:4px 8px;font-size:12px;} '
            . 'th{background:#F5F0FA;font-weight:bold;}</style></head><body>';

        $html .= $section('Summary', [
            'Total Alumni', 'Employed', 'Self-Employed', 'Unemployed', 'Not Filled', 'Local', 'Abroad (OFW)',
        ], [
            $s['totalAlumni'], $s['totalEmployed'], $s['totalSelf'], $s['totalUnemployed'],
            $s['totalNotFilled'], $s['totalLocal'], $s['totalAbroad'],
        ]);

        $html .= $section('Employment Status', $data['status']['labels'], $data['status']['data']);
        $html .= $section('Work Location', $data['location']['labels'], $data['location']['data']);
        $html .= $section('Job-Course Relevance', $data['relevance']['labels'], $data['relevance']['data']);
        $html .= $section('Employment Type', $data['empType']['labels'], $data['empType']['data']);
        $html .= $section('Career Path Labels', $data['careerPath']['labels'], $data['careerPath']['data']);
        $html .= $section('Further Education', $data['edu']['labels'], $data['edu']['data']);
        $html .= $section('Unemployed Breakdown', $data['unemployed']['labels'], $data['unemployed']['data']);
        $html .= $section('Top Courses (Employed)', $data['course']['labels'], $data['course']['data']);
        $html .= $section('Employment by Batch Year (Total)', $data['batch']['labels'], $data['batch']['total']);
        $html .= $section('Employment by College (Total)', $data['college']['labels'], $data['college']['total']);

        $html .= '</body></html>';

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}