<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component {

    // ── Global Filters ────────────────────────────────────────────────────────
    public string $filterBatch   = '';
    public string $filterCollege = '';
    public string $filterCourse  = '';

    // ── Comparison Mode ───────────────────────────────────────────────────────
    public string $compareMode       = 'college';
    public string $compareA          = '';
    public string $compareB          = '';
    public bool   $compareFullscreen = false;

    // ── Detail Modal ──────────────────────────────────────────────────────────
    public bool   $modalOpen       = false;
    public string $modalTitle      = '';
    public string $modalFilterType = '';
    public string $modalFilter     = '';
    public string $modalBatch      = '';
    public string $modalSearch     = '';
    public array  $modalAlumni     = [];
    public int    $modalTotal      = 0;

    // ── Summary Stats ─────────────────────────────────────────────────────────
    public int $totalAlumni     = 0;
    public int $totalFilled     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalLocal      = 0;
    public int $totalAbroad     = 0;

    // ── Chart JSON payloads ───────────────────────────────────────────────────
    public string $chartStatusData        = '{}';
    public string $chartLocationData      = '{}';
    public string $chartRelevanceData     = '{}';
    public string $chartBatchData         = '{}';
    public string $chartCollegeData       = '{}';
    public string $chartCourseData        = '{}';
    public string $chartEmpTypeData       = '{}';
    public string $chartCareerPathData    = '{}';
    public string $chartEduStatusData     = '{}';
    public string $chartCompareSideBySide = '{}';
    public string $chartUnemployedData    = '{}';

    // ── Meta lists ────────────────────────────────────────────────────────────
    public array $batches  = [];
    public array $colleges = [];
    public array $courses  = [];

    // ── Compare panel entity lists ────────────────────────────────────────────
    public array $compareOptions = [];

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'admin') {
            $this->redirect(route('login'));
            return;
        }
        $this->loadMetaLists();
        $this->refreshAll();
    }

    private function loadMetaLists(): void
    {
        $this->batches = DB::table('alumni')
            ->whereNull('deleted_at')
            ->distinct()->orderBy('batch', 'desc')
            ->pluck('batch')->toArray();

        $this->colleges = DB::table('courses')
            ->distinct()->orderBy('college')
            ->pluck('college')->filter()->values()->toArray();

        $this->courses = DB::table('courses')
            ->orderBy('code')
            ->get(['code', 'name', 'college'])
            ->map(fn($r) => ['code' => $r->code, 'name' => $r->name, 'college' => $r->college])
            ->toArray();
    }

    private function baseQ(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni as a')->whereNull('a.deleted_at');
        if ($this->filterBatch)   $q->where('a.batch', $this->filterBatch);
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $q->whereIn('a.course_code', $codes);
        }
        if ($this->filterCourse)  $q->where('a.course_code', $this->filterCourse);
        return $q;
    }

    private function empQ(): \Illuminate\Database\Query\Builder
    {
        return (clone $this->baseQ())
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');
    }

    // ── Modal Methods ─────────────────────────────────────────────────────────

    #[On('openEmploymentModal')]
    public function openEmploymentModal(string $filterType, string $filter): void
    {
        $this->modalFilterType = $filterType;
        $this->modalFilter     = $filter;
        $this->modalBatch      = '';
        $this->modalSearch     = '';
        $this->modalOpen       = true;

        $titles = [
            'status' => [
                'employed'      => 'Employed Alumni',
                'self_employed' => 'Self-Employed Alumni',
                'unemployed'    => 'Unemployed Alumni',
                'not_filled'    => 'Alumni with No Record',
            ],
            'relevance' => [
                'yes'         => 'Course-Related Alumni',
                'partially'   => 'Partially Related Alumni',
                'yes_partial' => 'Course-Related & Partial Alumni',
                'no'          => 'Not Related Alumni',
            ],
        ];

        $this->modalTitle = $titles[$filterType][$filter] ?? 'Alumni';
        $this->loadModalData();
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
    }

    public function updatedModalBatch(): void  { $this->loadModalData(); }
    public function updatedModalSearch(): void { $this->loadModalData(); }

    private function loadModalData(): void
    {
        if ($this->modalFilterType === 'status' && $this->modalFilter === 'not_filled') {
            $q = DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->whereNotExists(function ($sub) {
                    $sub->from('employment_trackings as et')
                        ->whereColumn('et.alumni_id', 'a.id')
                        ->whereNull('et.deleted_at');
                });

            if ($this->filterBatch)   $q->where('a.batch', $this->filterBatch);
            if ($this->filterCollege) {
                $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
                $q->whereIn('a.course_code', $codes);
            }
            if ($this->filterCourse)  $q->where('a.course_code', $this->filterCourse);
            if ($this->modalBatch)    $q->where('a.batch', $this->modalBatch);
            if ($this->modalSearch) {
                $s = '%' . $this->modalSearch . '%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('a.first_name', 'like', $s)
                       ->orWhere('a.last_name',  'like', $s)
                       ->orWhere('a.id_number',  'like', $s);
                });
            }

            $this->modalTotal = (clone $q)->count();
            $rows = $q->select('a.first_name', 'a.last_name', 'a.middle_name', 'a.id_number', 'a.course_code', 'a.batch')
                ->orderBy('a.last_name')->orderBy('a.first_name')->limit(100)->get();

            $this->modalAlumni = $rows->map(fn($r) => [
                'name'       => $r->last_name . ', ' . $r->first_name . ($r->middle_name ? ' ' . substr($r->middle_name, 0, 1) . '.' : ''),
                'id_number'  => $r->id_number ?? '—',
                'course'     => $r->course_code ?? '—',
                'batch'      => $r->batch,
                'status'     => 'No Record',
                'status_key' => 'not_filled',
                'type'       => null,
                'company'    => null,
                'position'   => null,
                'location'   => null,
                'relevance'  => null,
            ])->toArray();
            return;
        }

        $q = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');

        if ($this->filterBatch)   $q->where('a.batch', $this->filterBatch);
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $q->whereIn('a.course_code', $codes);
        }
        if ($this->filterCourse)  $q->where('a.course_code', $this->filterCourse);
        if ($this->modalBatch)    $q->where('a.batch', $this->modalBatch);

        if ($this->modalFilterType === 'status') {
            $q->where('et.employment_status', $this->modalFilter);
        } elseif ($this->modalFilterType === 'relevance') {
            if ($this->modalFilter === 'yes_partial') {
                $q->whereIn('et.course_relevance', ['yes', 'partially']);
            } else {
                $q->where('et.course_relevance', $this->modalFilter);
            }
        }

        if ($this->modalSearch) {
            $s = '%' . $this->modalSearch . '%';
            $q->where(function ($sq) use ($s) {
                $sq->where('a.first_name',      'like', $s)
                   ->orWhere('a.last_name',     'like', $s)
                   ->orWhere('a.id_number',     'like', $s)
                   ->orWhere('et.company_name', 'like', $s);
            });
        }

        $this->modalTotal = (clone $q)->count();
        $rows = $q->select(
            'a.first_name', 'a.last_name', 'a.middle_name', 'a.id_number', 'a.course_code', 'a.batch',
            'et.employment_status', 'et.employment_type', 'et.company_name', 'et.position',
            'et.work_location', 'et.course_relevance'
        )->orderBy('a.last_name')->orderBy('a.first_name')->limit(100)->get();

        $sLabel = ['employed' => 'Employed', 'self_employed' => 'Self-Employed', 'unemployed' => 'Unemployed'];
        $tLabel = ['full_time' => 'Full-Time', 'part_time' => 'Part-Time', 'contractual' => 'Contractual',
                   'project_based' => 'Project-Based', 'internship' => 'Internship'];

        $this->modalAlumni = $rows->map(function ($r) use ($sLabel, $tLabel) {
            return [
                'name'       => $r->last_name . ', ' . $r->first_name . ($r->middle_name ? ' ' . substr($r->middle_name, 0, 1) . '.' : ''),
                'id_number'  => $r->id_number ?? '—',
                'course'     => $r->course_code ?? '—',
                'batch'      => $r->batch,
                'status'     => $sLabel[$r->employment_status] ?? ucfirst($r->employment_status ?? ''),
                'status_key' => $r->employment_status ?? 'unknown',
                'type'       => $tLabel[$r->employment_type ?? ''] ?? null,
                'company'    => $r->company_name,
                'position'   => $r->position,
                'location'   => $r->work_location,
                'relevance'  => $r->course_relevance,
            ];
        })->toArray();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function refreshAll(): void
    {
        $this->computeStats();
        $this->buildAllCharts();
        $this->buildCompareOptions();
    }

    public function computeStats(): void
    {
        $this->totalAlumni     = (clone $this->baseQ())->count();
        $this->totalEmployed   = (clone $this->empQ())->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $this->empQ())->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $this->empQ())->where('et.employment_status', 'unemployed')->count();
        $this->totalFilled     = $this->totalEmployed + $this->totalSelf + $this->totalUnemployed;
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalFilled);
        $this->totalLocal      = (clone $this->empQ())->where('et.work_location', 'local')->count();
        $this->totalAbroad     = (clone $this->empQ())->where('et.work_location', 'abroad')->count();
    }

    public function buildAllCharts(): void
    {
        $this->chartStatusData = json_encode([
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors' => ['#10b981', '#3b82f6', '#f59e0b', '#d1d5db'],
        ]);

        $this->chartLocationData = json_encode([
            'labels' => ['Local', 'Abroad (OFW)'],
            'data'   => [$this->totalLocal, $this->totalAbroad],
            'colors' => ['#7a3f91', '#e879f9'],
        ]);

        $relRows = (clone $this->empQ())
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $this->chartRelevanceData = json_encode([
            'labels' => ['Related', 'Partially', 'Not Related'],
            'data'   => [
                $relRows->get('yes')->cnt       ?? 0,
                $relRows->get('partially')->cnt ?? 0,
                $relRows->get('no')->cnt        ?? 0,
            ],
            'colors' => ['#10b981', '#f59e0b', '#ef4444'],
        ]);

        $batchRows = (clone $this->baseQ())
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

        $this->chartBatchData = json_encode([
            'labels'     => $batchRows->pluck('batch')->values(),
            'employed'   => $batchRows->pluck('employed')->values(),
            'self_emp'   => $batchRows->pluck('self_emp')->values(),
            'unemployed' => $batchRows->pluck('unemployed')->values(),
            'total'      => $batchRows->pluck('total')->values(),
        ]);

        $colleges    = DB::table('courses')->distinct()->orderBy('college')->pluck('college')->filter()->values();
        $collegeData = $colleges->map(function ($col) {
            $codes = DB::table('courses')->where('college', $col)->pluck('code');
            $base  = DB::table('alumni as a')->whereNull('a.deleted_at')->whereIn('a.course_code', $codes);
            if ($this->filterBatch) $base->where('a.batch', $this->filterBatch);
            $total = (clone $base)->count();
            $emp   = DB::table('alumni as a')
                ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('a.course_code', $codes);
            if ($this->filterBatch) $emp->where('a.batch', $this->filterBatch);
            $employed   = (clone $emp)->where('et.employment_status', 'employed')->count();
            $self_emp   = (clone $emp)->where('et.employment_status', 'self_employed')->count();
            $unemployed = (clone $emp)->where('et.employment_status', 'unemployed')->count();
            return compact('col', 'total', 'employed', 'self_emp', 'unemployed');
        });

        $this->chartCollegeData = json_encode([
            'labels'     => $collegeData->pluck('col')->values(),
            'employed'   => $collegeData->pluck('employed')->values(),
            'self_emp'   => $collegeData->pluck('self_emp')->values(),
            'unemployed' => $collegeData->pluck('unemployed')->values(),
            'total'      => $collegeData->pluck('total')->values(),
        ]);

        $courseQ = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
            ->whereIn('et.employment_status', ['employed', 'self_employed']);
        if ($this->filterBatch)   $courseQ->where('a.batch', $this->filterBatch);
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $courseQ->whereIn('a.course_code', $codes);
        }
        if ($this->filterCourse) $courseQ->where('a.course_code', $this->filterCourse);
        $courseRows = $courseQ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->orderByDesc('cnt')->limit(10)->get();

        $this->chartCourseData = json_encode([
            'labels' => $courseRows->pluck('course_code')->values(),
            'data'   => $courseRows->pluck('cnt')->values(),
        ]);

        $empTypeRows = (clone $this->empQ())
            ->whereNotNull('et.employment_type')
            ->select('et.employment_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.employment_type')->get()->keyBy('employment_type');

        $this->chartEmpTypeData = json_encode([
            'labels' => ['Full-Time', 'Part-Time', 'Contractual', 'Project-Based', 'Internship'],
            'data'   => [
                $empTypeRows->get('full_time')->cnt     ?? 0,
                $empTypeRows->get('part_time')->cnt     ?? 0,
                $empTypeRows->get('contractual')->cnt   ?? 0,
                $empTypeRows->get('project_based')->cnt ?? 0,
                $empTypeRows->get('internship')->cnt    ?? 0,
            ],
            'colors' => ['#7a3f91', '#a855f7', '#c084fc', '#ddd6fe', '#ede9fe'],
        ]);

        $cpRows = (clone $this->empQ())->whereNotNull('et.career_path')->select('et.career_path')->get();
        $cpCounts = ['ofw' => 0, 'freelancer' => 0, 'entrepreneur' => 0, 'career_shifter' => 0, 'industry_professional' => 0];
        foreach ($cpRows as $r) {
            $arr = json_decode($r->career_path, true) ?? [];
            foreach ($arr as $v) { if (isset($cpCounts[$v])) $cpCounts[$v]++; }
        }

        $this->chartCareerPathData = json_encode([
            'labels' => ['OFW', 'Freelancer', 'Entrepreneur', 'Career Shifter', 'Industry Pro'],
            'data'   => array_values($cpCounts),
            'colors' => ['#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#7a3f91'],
        ]);

        $eduRows = (clone $this->empQ())
            ->whereNotNull('et.education_status')
            ->select('et.education_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.education_status')->get()->keyBy('education_status');

        $this->chartEduStatusData = json_encode([
            'labels' => ['None', 'Pursuing Masteral', 'Pursuing Doctorate'],
            'data'   => [
                $eduRows->get('none')->cnt               ?? 0,
                $eduRows->get('pursuing_masteral')->cnt  ?? 0,
                $eduRows->get('pursuing_doctorate')->cnt ?? 0,
            ],
            'colors' => ['#9ca3af', '#3b82f6', '#7a3f91'],
        ]);

        $unRows = (clone $this->empQ())
            ->where('et.employment_status', 'unemployed')
            ->whereNotNull('et.unemployment_status')
            ->select('et.unemployment_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.unemployment_status')->get()->keyBy('unemployment_status');

        $this->chartUnemployedData = json_encode([
            'labels' => ['Seeking Employment', 'Not Looking'],
            'data'   => [
                $unRows->get('seeking_employment')->cnt ?? 0,
                $unRows->get('not_looking')->cnt        ?? 0,
            ],
            'colors' => ['#f59e0b', '#9ca3af'],
        ]);

        $this->buildCompareChart();
    }

    private function buildCompareChart(): void
    {
        if (!$this->compareA || !$this->compareB) { $this->chartCompareSideBySide = '{}'; return; }

        $getStats = function (string $key): array {
            $empQ  = DB::table('alumni as a')->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')->whereNull('a.deleted_at')->whereNull('et.deleted_at');
            $baseQ = DB::table('alumni as a')->whereNull('a.deleted_at');

            if ($this->compareMode === 'college') {
                $codes = DB::table('courses')->where('college', $key)->pluck('code');
                $empQ->whereIn('a.course_code', $codes);
                $baseQ->whereIn('a.course_code', $codes);
            } elseif ($this->compareMode === 'course') {
                $empQ->where('a.course_code', $key);
                $baseQ->where('a.course_code', $key);
            } else {
                $empQ->where('a.batch', $key);
                $baseQ->where('a.batch', $key);
            }

            if ($this->filterBatch && $this->compareMode !== 'batch') {
                $empQ->where('a.batch', $this->filterBatch);
                $baseQ->where('a.batch', $this->filterBatch);
            }

            $total      = (clone $baseQ)->count();
            $employed   = (clone $empQ)->where('et.employment_status', 'employed')->count();
            $self_emp   = (clone $empQ)->where('et.employment_status', 'self_employed')->count();
            $unemployed = (clone $empQ)->where('et.employment_status', 'unemployed')->count();
            $local      = (clone $empQ)->where('et.work_location', 'local')->count();
            $abroad     = (clone $empQ)->where('et.work_location', 'abroad')->count();
            $related    = (clone $empQ)->where('et.course_relevance', 'yes')->count();

            return compact('total', 'employed', 'self_emp', 'unemployed', 'local', 'abroad', 'related');
        };

        $aStats = $getStats($this->compareA);
        $bStats = $getStats($this->compareB);

        $this->chartCompareSideBySide = json_encode([
            'labelA'     => $this->compareA,
            'labelB'     => $this->compareB,
            'categories' => ['Employed', 'Self-Employed', 'Unemployed', 'Local', 'Abroad', 'Course-Related'],
            'dataA'      => [$aStats['employed'], $aStats['self_emp'], $aStats['unemployed'], $aStats['local'], $aStats['abroad'], $aStats['related']],
            'dataB'      => [$bStats['employed'], $bStats['self_emp'], $bStats['unemployed'], $bStats['local'], $bStats['abroad'], $bStats['related']],
            'totalA'     => $aStats['total'],
            'totalB'     => $bStats['total'],
        ]);
    }

    public function buildCompareOptions(): void
    {
        if ($this->compareMode === 'college') {
            $this->compareOptions = DB::table('courses')->distinct()->orderBy('college')->pluck('college')->filter()->values()->toArray();
        } elseif ($this->compareMode === 'course') {
            $this->compareOptions = DB::table('courses')->orderBy('code')->pluck('code')->toArray();
        } else {
            $this->compareOptions = DB::table('alumni')->whereNull('deleted_at')->distinct()->orderBy('batch', 'desc')->pluck('batch')->toArray();
        }
        $this->compareA = '';
        $this->compareB = '';
        $this->chartCompareSideBySide = '{}';
    }

    public function resetCompare(): void          { $this->compareA = ''; $this->compareB = ''; $this->chartCompareSideBySide = '{}'; }
    public function toggleCompareFullscreen(): void { $this->compareFullscreen = !$this->compareFullscreen; }

    public function updatedCompareMode(): void { $this->buildCompareOptions(); }
    public function updatedCompareA(): void    { $this->buildCompareChart(); }
    public function updatedCompareB(): void    { $this->buildCompareChart(); }

    public function updatedFilterBatch(): void   { $this->refreshAll(); }
    public function updatedFilterCollege(): void { $this->filterCourse = ''; $this->refreshAll(); }
    public function updatedFilterCourse(): void  { $this->refreshAll(); }

    public function clearFilters(): void
    {
        $this->filterBatch = $this->filterCollege = $this->filterCourse = '';
        $this->refreshAll();
    }

    public function with(): array { return []; }
};
?>

<div class="min-h-screen emp-admin-root">

{{-- ══ STYLES ══════════════════════════════════════════════════════════════════ --}}
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=DM+Mono:wght@400;500&display=swap');

.emp-admin-root {
    font-family: 'DM Sans', sans-serif;
    background: #f4f3f8;
    color: #1a1a2e;
    min-height: 100vh;
}

:root {
    --P:  #7a3f91;
    --P2: #5c2d6e;
    --PL: #f3e8ff;
    --PM: #e9d5ff;
    --ink:    #333333;
    --muted:  #666666;
    --border: #E8E0F0;
    --card:   #ffffff;
    --bg:     #f4f3f8;
    --green:  #10b981;
    --blue:   #3b82f6;
    --amber:  #f59e0b;
    --red:    #ef4444;
}

.adm-header {
    background: transparent;
    border-bottom: 1px solid var(--border);
    padding: 20px 32px;
}

/* ── Stat cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px 18px 14px;
    position: relative;
    overflow: hidden;
    cursor: default;
}
.stat-card::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 0 0 16px 16px;
}
.stat-card.c-purple::after { background: var(--P); }
.stat-card.c-green::after  { background: var(--green); }
.stat-card.c-blue::after   { background: var(--blue); }
.stat-card.c-amber::after  { background: var(--amber); }
.stat-card.c-gray::after   { background: #9ca3af; }
.stat-card.c-teal::after   { background: #14b8a6; }
.stat-card.c-rose::after   { background: #f43f5e; }
.stat-card.c-orange::after { background: #f97316; }

.stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px;
}
.stat-num { font-size: 2rem; font-weight: 800; line-height: 1; color: var(--ink); }
.stat-lbl { font-size: .8rem; font-weight: 600; color: var(--muted); margin-top: 4px; letter-spacing: .01em; }
.stat-pct { font-size: .75rem; font-weight: 600; color: var(--P); margin-top: 3px; }

/* ── Chart cards ── */
.chart-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.chart-head {
    padding: 12px 18px;
    border-bottom: 1px solid var(--border);
    background: #F9F7FC;
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px;
}
.chart-head-left { display: flex; align-items: center; gap: 8px; }
.chart-dot  { width: 8px; height: 8px; border-radius: 50%; background: var(--P); flex-shrink: 0; }
.chart-ttl  { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--ink); }
.chart-sub  { font-size: .72rem; color: var(--muted); font-weight: 500; }
.chart-body { padding: 16px; }

/* ── Clickable chart cards ── */
.chart-clickable { cursor: pointer; transition: box-shadow .15s, border-color .15s; }
.chart-clickable:hover { border-color: var(--PM); box-shadow: 0 4px 18px rgba(122,63,145,.12); }
.chart-clickable:hover .chart-head { background: #f5effc; }
.chart-clickable canvas { cursor: pointer; }

/* ── Filter bar ── */
.filter-bar {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
}
.f-select {
    padding: 8px 12px; border: 1.5px solid var(--border); border-radius: 10px;
    font-size: .85rem; font-family: inherit; font-weight: 500;
    background: #fff; color: var(--ink);
    transition: border-color .15s, box-shadow .15s;
    cursor: pointer;
}
.f-select:focus { outline: none; border-color: var(--P); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.f-label { font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }

/* ── Compare panel ── */
.compare-panel { background: var(--card); border: 2px solid var(--PM); border-radius: 18px; overflow: hidden; }
.compare-head  { background: #F9F7FC; padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.compare-body  { padding: 18px 20px; }

.mode-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border: 1.5px solid var(--border); border-radius: 99px;
    font-size: .8rem; font-weight: 600; color: var(--muted);
    cursor: pointer; transition: border-color .15s, background .15s, color .15s; background: #fff;
}
.mode-pill:hover, .mode-pill.active { border-color: var(--P); background: var(--PL); color: var(--P); }

.vs-badge {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--P); color: #fff;
    font-size: .75rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 10px rgba(122,63,145,.35);
}

.insight-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 99px; font-size: .78rem; font-weight: 600;
    border: 1px solid;
}

.prog { height: 5px; border-radius: 99px; background: #ede9fe; overflow: hidden; margin-top: 5px; }
.prog-fill { height: 100%; border-radius: 99px; transition: width .6s; }

.rank-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid #f5f5f5;
}
.rank-row:last-child { border-bottom: none; }
.rank-num { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .72rem; font-weight: 600; flex-shrink: 0; }

/* ── Compare fullscreen overlay ── */
.compare-fs-overlay {
    position: fixed; inset: 0; z-index: 60;
    background: rgba(27, 6, 46, 0.55);
    backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
    animation: fadeInOverlay .2s ease both;
}
@keyframes fadeInOverlay { from { opacity: 0; } to { opacity: 1; } }
.compare-fs-panel {
    background: #fff;
    border-radius: 20px;
    width: 100%; max-width: 900px;
    max-height: 92vh;
    display: flex; flex-direction: column;
    overflow: hidden;
    box-shadow: 0 24px 80px rgba(74,25,110,.22);
    animation: slideUpPanel .22s cubic-bezier(.4,0,.2,1) both;
}
@keyframes slideUpPanel { from { opacity: 0; transform: translateY(20px) scale(.98); } to { opacity: 1; transform: none; } }
.compare-fs-head {
    background: #7a3f91;
    padding: 16px 22px;
    display: flex; align-items: center; gap: 12px;
    flex-shrink: 0;
}
.compare-fs-body { flex: 1; overflow-y: auto; padding: 22px; }

.btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 9px;
    border: 1.5px solid var(--P);
    background: var(--PL); color: var(--P);
    font-size: .75rem;
    cursor: pointer; transition: border-color .15s, color .15s, background .15s;
    flex-shrink: 0;
}
.btn-icon:hover { border-color: var(--P2); color: var(--P2); background: var(--PM); }

.btn-reset {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 99px;
    border: 1.5px solid #fca5a5;
    background: #fff5f5; color: #dc2626;
    font-size: .8rem; font-weight: 600;
    cursor: pointer; transition: background .15s, border-color .15s;
}
.btn-reset:hover { background: #fee2e2; border-color: #ef4444; }

@media(max-width:640px) {
    .adm-header { padding: 16px; }
    .stat-num   { font-size: 1.6rem; }
}

.wire-loading { opacity: .4; pointer-events: none; transition: opacity .2s; }

.chart-nodata {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    height: 100%; gap: 8px; color: var(--muted);
}
.chart-nodata i { font-size: 1.8rem; opacity: .25; }
.chart-nodata p { font-size: .8rem; font-weight: 600; opacity: .6; }

/* ══ EMPLOYMENT DETAIL MODAL ══════════════════════════════════════════════════ */
.emp-modal-overlay {
    position: fixed; inset: 0; z-index: 80;
    background: rgba(18, 4, 35, 0.62);
    backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    animation: fadeInOverlay .18s ease both;
}
.emp-modal-panel {
    background: #fff;
    border-radius: 22px;
    width: 100%; max-width: 1040px;
    height: 90vh;
    max-height: 90vh;
    display: flex; flex-direction: column;
    overflow: hidden;
    box-shadow: 0 30px 90px rgba(60,15,100,.28);
    animation: slideUpPanel .2s cubic-bezier(.4,0,.2,1) both;
}
.emp-modal-hd {
    background: linear-gradient(135deg, #7a3f91 0%, #5c2d6e 100%);
    padding: 18px 24px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    flex-shrink: 0;
}
.emp-modal-close {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border-radius: 12px;
    background: rgba(255,255,255,.15);
    color: #fff; font-size: .82rem; font-weight: 600;
    border: 1px solid rgba(255,255,255,.25);
    cursor: pointer; flex-shrink: 0;
    transition: background .15s;
}
.emp-modal-close:hover { background: rgba(255,255,255,.25); }

.emp-modal-filters {
    padding: 12px 24px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    flex-shrink: 0;
    background: #faf7fc;
}
.emp-modal-search {
    flex: 1; min-width: 200px;
    padding: 8px 12px 8px 34px;
    border: 1.5px solid var(--border); border-radius: 10px;
    font-size: .85rem; font-family: 'DM Sans', sans-serif; font-weight: 500;
    color: var(--ink); background: #fff;
    transition: border-color .15s, box-shadow .15s;
    position: relative;
}
.emp-modal-search:focus { outline: none; border-color: var(--P); box-shadow: 0 0 0 3px rgba(122,63,145,.10); }
.emp-modal-search-wrap { position: relative; flex: 1; min-width: 200px; }
.emp-modal-search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: .75rem; color: var(--muted); pointer-events: none; }

.emp-modal-body {
    flex: 1; overflow-y: auto;
    padding: 0;
}
.emp-modal-table { width: 100%; border-collapse: collapse; }
.emp-modal-table thead tr {
    background: #f8f5fc;
    border-bottom: 1.5px solid var(--border);
    position: sticky; top: 0; z-index: 2;
}
.emp-modal-table thead th {
    padding: 10px 14px;
    text-align: left;
    font-size: .72rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); white-space: nowrap;
}
.emp-modal-table thead th:first-child { padding-left: 24px; }
.emp-modal-table thead th:last-child  { padding-right: 24px; text-align: right; }
.emp-modal-table tbody tr { border-bottom: 1px solid #f3f0f9; transition: background .1s; }
.emp-modal-table tbody tr:hover { background: #faf7ff; }
.emp-modal-table tbody td { padding: 11px 14px; vertical-align: middle; }
.emp-modal-table tbody td:first-child { padding-left: 24px; }
.emp-modal-table tbody td:last-child  { padding-right: 24px; }

.alum-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: #f3f0f9; border: 1.5px solid #e4dff0;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 99px;
    font-size: .71rem; font-weight: 600;
    border: 1px solid; white-space: nowrap;
}
</style>

{{-- ══ PAGE HEADER ══════════════════════════════════════════════════════════════ --}}
<div class="adm-header">
    <div class="max-w-screen-2xl mx-auto flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-sm shrink-0" style="background:#7a3f91;">
                <i class="fa-solid fa-chart-column text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-3xl font-semibold text-[#333333] leading-tight">Employment Analytics</h1>
                <p class="text-sm text-[#666666] font-normal mt-0.5">System-wide employment intelligence &amp; comparison tool</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border border-[#E8E0F0] bg-[#F9F7FC] text-[#7a3f91]">
            <i class="fa-solid fa-users text-xs"></i>
            {{ number_format($totalAlumni) }} alumni tracked
        </span>
    </div>
</div>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

{{-- ══ FILTER BAR ══════════════════════════════════════════════════════════════ --}}
<div class="filter-bar"
     wire:loading.class="wire-loading"
     wire:target="updatedFilterBatch,updatedFilterCollege,updatedFilterCourse,clearFilters">
    <i class="fa-solid fa-sliders text-sm" style="color:var(--P);"></i>
    <span class="f-label">Filter:</span>

    <select wire:model.live="filterBatch" class="f-select">
        <option value="">All Batches</option>
        @foreach($batches as $b)
            <option value="{{ $b }}">Batch {{ $b }}</option>
        @endforeach
    </select>

    <select wire:model.live="filterCollege" class="f-select">
        <option value="">All Colleges</option>
        @foreach($colleges as $c)
            <option value="{{ $c }}">{{ $c }}</option>
        @endforeach
    </select>

    @if($filterCollege)
    <select wire:model.live="filterCourse" class="f-select">
        <option value="">All Courses in College</option>
        @foreach($courses as $c)
            @if($c['college'] === $filterCollege)
                <option value="{{ $c['code'] }}">{{ $c['code'] }}</option>
            @endif
        @endforeach
    </select>
    @else
    <select wire:model.live="filterCourse" class="f-select">
        <option value="">All Courses</option>
        @foreach($courses as $c)
            <option value="{{ $c['code'] }}">{{ $c['code'] }}</option>
        @endforeach
    </select>
    @endif

    @if($filterBatch || $filterCollege || $filterCourse)
        <button wire:click="clearFilters"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-semibold border border-red-200 bg-red-50 text-red-600 transition">
            <i class="fa-solid fa-rotate-left text-xs"></i> Reset
        </button>
        <span class="text-xs font-semibold px-3 py-1.5 rounded-lg" style="background:var(--PL);color:var(--P);">
            <i class="fa-solid fa-filter text-xs mr-1"></i> Filtered view
        </span>
    @endif

    <div class="ml-auto" wire:loading wire:target="filterBatch,filterCollege,filterCourse,clearFilters">
        <i class="fa-solid fa-circle-notch fa-spin text-sm" style="color:var(--P);"></i>
    </div>
</div>

{{-- ══ STAT CARDS ══════════════════════════════════════════════════════════════ --}}
<div class="stat-grid"
     wire:loading.class="wire-loading"
     wire:target="filterBatch,filterCollege,filterCourse,clearFilters">

    <div class="stat-card c-purple">
        <div class="stat-icon" style="background:var(--PL);">
            <i class="fa-solid fa-users" style="color:var(--P);font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalAlumni) }}</p>
        <p class="stat-lbl">Total Alumni</p>
        <p class="stat-pct">
            @if($totalAlumni > 0){{ round($totalFilled / $totalAlumni * 100) }}% have records
            @else No data yet @endif
        </p>
    </div>

    <div class="stat-card c-green">
        <div class="stat-icon" style="background:#d1fae5;">
            <i class="fa-solid fa-briefcase" style="color:#059669;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalEmployed) }}</p>
        <p class="stat-lbl">Employed</p>
        @if($totalAlumni > 0)
            <div class="prog"><div class="prog-fill" style="width:{{ round($totalEmployed/$totalAlumni*100) }}%;background:#10b981;"></div></div>
            <p class="stat-pct">{{ round($totalEmployed/$totalAlumni*100) }}% of total</p>
        @endif
    </div>

    <div class="stat-card c-blue">
        <div class="stat-icon" style="background:#dbeafe;">
            <i class="fa-solid fa-store" style="color:#2563eb;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalSelf) }}</p>
        <p class="stat-lbl">Self-Employed</p>
        @if($totalAlumni > 0)
            <div class="prog"><div class="prog-fill" style="width:{{ round($totalSelf/$totalAlumni*100) }}%;background:#3b82f6;"></div></div>
            <p class="stat-pct">{{ round($totalSelf/$totalAlumni*100) }}% of total</p>
        @endif
    </div>

    <div class="stat-card c-amber">
        <div class="stat-icon" style="background:#fef3c7;">
            <i class="fa-solid fa-circle-pause" style="color:#d97706;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalUnemployed) }}</p>
        <p class="stat-lbl">Unemployed</p>
        @if($totalAlumni > 0)
            <div class="prog"><div class="prog-fill" style="width:{{ round($totalUnemployed/$totalAlumni*100) }}%;background:#f59e0b;"></div></div>
            <p class="stat-pct">{{ round($totalUnemployed/$totalAlumni*100) }}% of total</p>
        @endif
    </div>

    <div class="stat-card c-gray">
        <div class="stat-icon" style="background:#f3f4f6;">
            <i class="fa-solid fa-circle-question" style="color:#9ca3af;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalNotFilled) }}</p>
        <p class="stat-lbl">Not Filled</p>
        @if($totalAlumni > 0)
            <div class="prog"><div class="prog-fill" style="width:{{ round($totalNotFilled/$totalAlumni*100) }}%;background:#9ca3af;"></div></div>
        @endif
    </div>

    <div class="stat-card c-teal">
        <div class="stat-icon" style="background:#ccfbf1;">
            <i class="fa-solid fa-house" style="color:#0d9488;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalLocal) }}</p>
        <p class="stat-lbl">Local Workers</p>
        @if(($totalLocal + $totalAbroad) > 0)
            <p class="stat-pct">{{ round($totalLocal/($totalLocal+$totalAbroad)*100) }}% of employed</p>
        @endif
    </div>

    <div class="stat-card c-orange">
        <div class="stat-icon" style="background:#ffedd5;">
            <i class="fa-solid fa-plane-departure" style="color:#ea580c;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ number_format($totalAbroad) }}</p>
        <p class="stat-lbl">Abroad / OFW</p>
        @if(($totalLocal + $totalAbroad) > 0)
            <p class="stat-pct">{{ round($totalAbroad/($totalLocal+$totalAbroad)*100) }}% of employed</p>
        @endif
    </div>

    @php
        $fillRate = $totalAlumni > 0 ? round($totalFilled/$totalAlumni*100) : 0;
        $empRate  = $totalFilled  > 0 ? round(($totalEmployed+$totalSelf)/$totalFilled*100) : 0;
    @endphp
    <div class="stat-card c-rose">
        <div class="stat-icon" style="background:#ffe4e6;">
            <i class="fa-solid fa-chart-pie" style="color:#e11d48;font-size:1.1rem;"></i>
        </div>
        <p class="stat-num">{{ $empRate }}%</p>
        <p class="stat-lbl">Employment Rate</p>
        <div class="prog"><div class="prog-fill" style="width:{{ $empRate }}%;background:#f43f5e;"></div></div>
        <p class="stat-pct">of those with records</p>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     ROW 1 — Status / Location / Relevance / Unemployment Reason
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"
     wire:loading.class="wire-loading"
     wire:target="filterBatch,filterCollege,filterCourse,clearFilters">

    <div class="chart-card chart-clickable" title="Click a segment to view alumni">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot"></div>
                <span class="chart-ttl">Employment Status</span>
            </div>
            <i class="fa-solid fa-arrow-pointer text-xs" style="color:var(--muted);opacity:.5;"></i>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartStatus"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#e879f9;"></div>
                <span class="chart-ttl">Work Location</span>
            </div>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartLocation"></canvas>
        </div>
    </div>

    <div class="chart-card chart-clickable" title="Click green for Related only • Click background for Related + Partial">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#10b981;"></div>
                <span class="chart-ttl">Job-Course Relevance</span>
            </div>
            <i class="fa-solid fa-arrow-pointer text-xs" style="color:var(--muted);opacity:.5;"></i>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartRelevance"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#f59e0b;"></div>
                <span class="chart-ttl">Unemployed — Why?</span>
            </div>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartUnemployed"></canvas>
            <div id="chartUnemployedNoData" class="chart-nodata" style="display:none;">
                <i class="fa-solid fa-circle-info"></i>
                <p>No unemployment data yet</p>
            </div>
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     ROW 2 — Employment Type / Career Path / Education Status / Top Courses
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"
     wire:loading.class="wire-loading"
     wire:target="filterBatch,filterCollege,filterCourse,clearFilters">

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#a855f7;"></div>
                <span class="chart-ttl">Employment Type</span>
            </div>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartEmpType"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#14b8a6;"></div>
                <span class="chart-ttl">Career Path Labels</span>
            </div>
        </div>
        <div class="chart-body" style="height:220px;" wire:ignore>
            <canvas id="chartCareerPath"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#3b82f6;"></div>
                <span class="chart-ttl">Further Education</span>
            </div>
        </div>
        <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
            <canvas id="chartEduStatus"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#7a3f91;"></div>
                <span class="chart-ttl">Top Courses (Employed)</span>
            </div>
        </div>
        <div class="chart-body" style="height:220px;" wire:ignore>
            <canvas id="chartCourse"></canvas>
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     ROW 3 — By Batch + By College (FIXED: horizontal bar so labels don't rotate)
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 xl:grid-cols-2 gap-4"
     wire:loading.class="wire-loading"
     wire:target="filterBatch,filterCollege,filterCourse,clearFilters">

    {{-- Batch chart — vertical stacked, paginated --}}
    <div class="chart-card">
        <div class="chart-head" style="justify-content:space-between;">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#f59e0b;"></div>
                <div>
                    <div class="chart-ttl">Employment by Batch Year</div>
                    <div class="chart-sub">Stacked across all years</div>
                </div>
            </div>
            <div id="batchNavControls" class="flex items-center gap-1.5" style="display:none!important;">
                <button id="batchPrev"
                        class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30 disabled:cursor-not-allowed"
                        style="color:var(--P);">
                    <i class="fa-solid fa-chevron-left" style="font-size:.6rem;"></i>
                </button>
                <span id="batchPageInfo" class="text-xs font-semibold" style="color:var(--muted);white-space:nowrap;min-width:36px;text-align:center;"></span>
                <button id="batchNext"
                        class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30 disabled:cursor-not-allowed"
                        style="color:var(--P);">
                    <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
                </button>
            </div>
        </div>
        <div class="chart-body" style="height:270px;" wire:ignore>
            <canvas id="chartBatch"></canvas>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════════
         College chart — HORIZONTAL stacked bar (FIX: no more sideways labels)
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#06b6d4;"></div>
                <div>
                    <div class="chart-ttl">Employment by College</div>
                    <div class="chart-sub">Across all departments</div>
                </div>
            </div>
        </div>
        {{-- Height grows with number of colleges so bars are never cramped --}}
        <div class="chart-body" style="height:270px;" wire:ignore>
            <canvas id="chartCollege"></canvas>
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     INSIGHTS SUMMARY
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4"
     wire:loading.class="wire-loading"
     wire:target="filterBatch,filterCollege,filterCourse,clearFilters">

    @php
        $allColleges = collect($colleges)->map(function($col) {
            $codes    = DB::table('courses')->where('college',$col)->pluck('code');
            $total    = DB::table('alumni')->whereNull('deleted_at')->whereIn('course_code',$codes)->count();
            $employed = DB::table('alumni as a')
                ->join('employment_trackings as et','a.id','=','et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('a.course_code',$codes)
                ->whereIn('et.employment_status',['employed','self_employed'])->count();
            return ['name'=>$col,'total'=>$total,'employed'=>$employed,'rate'=>$total>0?round($employed/$total*100):0];
        })->sortByDesc('rate')->values();

        $topCourses = DB::table('alumni as a')
            ->join('employment_trackings as et','a.id','=','et.alumni_id')
            ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
            ->whereIn('et.employment_status',['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->orderByDesc('cnt')->limit(5)->get();

        $topBatches = DB::table('alumni as a')
            ->join('employment_trackings as et','a.id','=','et.alumni_id')
            ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
            ->whereIn('et.employment_status',['employed','self_employed'])
            ->select('a.batch', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.batch')->orderByDesc('cnt')->limit(5)->get();
    @endphp

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#7a3f91;"></div>
                <div>
                    <div class="chart-ttl">Colleges — Employment Rate</div>
                    <div class="chart-sub">Highest employment rate first</div>
                </div>
            </div>
            <i class="fa-solid fa-trophy text-sm" style="color:#f59e0b;"></i>
        </div>
        <div class="chart-body space-y-0">
            @forelse($allColleges->take(6) as $i => $col)
            @php
                $medals = ['🥇','🥈','🥉'];
                $medal  = $medals[$i] ?? null;
                $pct    = $col['rate'];
                $fillColor = $pct >= 70 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
            @endphp
            <div class="rank-row">
                <div class="rank-num" style="background:{{ $i===0?'#fef3c7':($i===1?'#f3f4f6':($i===2?'#fde8d8':'#f9fafb')) }};color:{{ $i===0?'#b45309':($i===1?'#6b7280':'#c2410c') }}">
                    {{ $medal ?? ($i+1) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold truncate" style="color:var(--ink);">{{ $col['name'] }}</p>
                    <div class="prog" style="margin-top:4px;">
                        <div class="prog-fill" style="width:{{ $pct }}%;background:{{ $fillColor }};"></div>
                    </div>
                </div>
                <span class="text-xs font-semibold flex-shrink-0 ml-2" style="color:{{ $fillColor }};">{{ $pct }}%</span>
            </div>
            @empty
                <p class="text-sm text-center py-6" style="color:var(--muted);">No college data available.</p>
            @endforelse
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#3b82f6;"></div>
                <div>
                    <div class="chart-ttl">Top Courses by Employed</div>
                    <div class="chart-sub">Most alumni employed</div>
                </div>
            </div>
            <i class="fa-solid fa-graduation-cap text-sm" style="color:#3b82f6;"></i>
        </div>
        <div class="chart-body space-y-0">
            @php $maxCourse = $topCourses->max('cnt') ?: 1; @endphp
            @forelse($topCourses as $i => $c)
            <div class="rank-row">
                <div class="rank-num" style="background:var(--PL);color:var(--P);">{{ $i+1 }}</div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold" style="color:var(--ink);">{{ $c->course_code }}</p>
                    <div class="prog" style="margin-top:4px;">
                        <div class="prog-fill" style="width:{{ round($c->cnt/$maxCourse*100) }}%;background:var(--P);"></div>
                    </div>
                </div>
                <span class="text-xs font-semibold flex-shrink-0 ml-2" style="color:var(--P);">{{ $c->cnt }}</span>
            </div>
            @empty
                <p class="text-sm text-center py-6" style="color:var(--muted);">No course data available.</p>
            @endforelse
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-head">
            <div class="chart-head-left">
                <div class="chart-dot" style="background:#f59e0b;"></div>
                <div>
                    <div class="chart-ttl">Top Batches by Employed</div>
                    <div class="chart-sub">Most alumni working</div>
                </div>
            </div>
            <i class="fa-solid fa-calendar-check text-sm" style="color:#f59e0b;"></i>
        </div>
        <div class="chart-body space-y-0">
            @php $maxBatch = $topBatches->max('cnt') ?: 1; @endphp
            @forelse($topBatches as $i => $b)
            <div class="rank-row">
                <div class="rank-num" style="background:#fef3c7;color:#b45309;">{{ $i+1 }}</div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold" style="color:var(--ink);">Batch {{ $b->batch }}</p>
                    <div class="prog" style="margin-top:4px;">
                        <div class="prog-fill" style="width:{{ round($b->cnt/$maxBatch*100) }}%;background:#f59e0b;"></div>
                    </div>
                </div>
                <span class="text-xs font-semibold flex-shrink-0 ml-2" style="color:#d97706;">{{ $b->cnt }}</span>
            </div>
            @empty
                <p class="text-sm text-center py-6" style="color:var(--muted);">No batch data available.</p>
            @endforelse
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     COMPARE PANEL
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="compare-panel">

    <div class="compare-head">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:var(--P);">
            <i class="fa-solid fa-code-compare text-white text-sm"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold" style="color:var(--ink);">Compare Tool</p>
            <p class="text-xs" style="color:var(--muted);">Select two entities to compare employment side-by-side</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            @if($compareA && $compareB)
            <button wire:click="resetCompare" class="btn-reset" title="Reset comparison">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
            @endif
            <button wire:click="toggleCompareFullscreen" class="btn-icon" title="Open fullscreen compare">
                <i class="fa-solid fa-expand text-xs"></i>
            </button>
        </div>
    </div>

    <div class="compare-body">

        <div class="flex flex-wrap gap-2 mb-4">
            <label class="mode-pill {{ $compareMode==='college' ? 'active' : '' }}">
                <input wire:model.live="compareMode" type="radio" value="college" class="sr-only">
                <i class="fa-solid fa-building-columns text-xs"></i> By College
            </label>
            <label class="mode-pill {{ $compareMode==='course' ? 'active' : '' }}">
                <input wire:model.live="compareMode" type="radio" value="course" class="sr-only">
                <i class="fa-solid fa-book text-xs"></i> By Course
            </label>
            <label class="mode-pill {{ $compareMode==='batch' ? 'active' : '' }}">
                <input wire:model.live="compareMode" type="radio" value="batch" class="sr-only">
                <i class="fa-solid fa-calendar text-xs"></i> By Batch Year
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
            <div class="flex-1 min-w-[140px]">
                <p class="f-label mb-1.5"><i class="fa-solid fa-a text-xs mr-1" style="color:var(--P);"></i> Entity A</p>
                <select wire:model.live="compareA" class="f-select w-full">
                    <option value=""> Select </option>
                    @foreach($compareOptions as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="vs-badge mt-5">VS</div>
            <div class="flex-1 min-w-[140px]">
                <p class="f-label mb-1.5"><i class="fa-solid fa-b text-xs mr-1" style="color:#3b82f6;"></i> Entity B</p>
                <select wire:model.live="compareB" class="f-select w-full">
                    <option value=""> Select </option>
                    @foreach($compareOptions as $opt)
                        @if($opt !== $compareA)
                            <option value="{{ $opt }}">{{ $opt }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        </div>

        @if($compareA && $compareB && $chartCompareSideBySide !== '{}')

        @php
            $cmp    = json_decode($chartCompareSideBySide, true);
            $totalA = $cmp['totalA'] ?? 0;
            $totalB = $cmp['totalB'] ?? 0;
            $dA     = $cmp['dataA'] ?? [];
            $dB     = $cmp['dataB'] ?? [];
            $cats   = $cmp['categories'] ?? [];
            $catIcons  = ['fa-briefcase','fa-store','fa-circle-pause','fa-house','fa-plane-departure','fa-graduation-cap'];
            $catColors = ['#10b981','#3b82f6','#f59e0b','#14b8a6','#f97316','#7a3f91'];
        @endphp

        <div class="grid grid-cols-2 gap-3 mb-4 p-4 rounded-2xl" style="background:var(--bg);">
            <div class="text-center">
                <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:var(--P);">{{ $cmp['labelA'] ?? '' }}</p>
                <p class="text-2xl font-black" style="color:var(--ink);">{{ number_format($totalA) }}</p>
                <p class="text-xs" style="color:var(--muted);">total alumni</p>
            </div>
            <div class="text-center">
                <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:#3b82f6;">{{ $cmp['labelB'] ?? '' }}</p>
                <p class="text-2xl font-black" style="color:var(--ink);">{{ number_format($totalB) }}</p>
                <p class="text-xs" style="color:var(--muted);">total alumni</p>
            </div>
        </div>

        <div class="space-y-3 mb-4">
            @foreach($cats as $idx => $cat)
            @php
                $vA     = $dA[$idx] ?? 0;
                $vB     = $dB[$idx] ?? 0;
                $maxV   = max($vA, $vB, 1);
                $pctA   = round($vA / $maxV * 100);
                $pctB   = round($vB / $maxV * 100);
                $winner = $vA > $vB ? 'A' : ($vB > $vA ? 'B' : null);
                $color  = $catColors[$idx] ?? '#7a3f91';
                $icon   = $catIcons[$idx] ?? 'fa-circle';
            @endphp
            <div class="p-3 rounded-xl border" style="border-color:#ede9fe;background:#faf7ff;">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fa-solid {{ $icon }} text-xs" style="color:{{ $color }};"></i>
                    <span class="text-xs font-semibold" style="color:var(--ink);">{{ $cat }}</span>
                    @if($winner === 'A')
                        <span class="ml-auto insight-pill text-xs" style="color:var(--P);background:var(--PL);border-color:var(--PM);">
                            <i class="fa-solid fa-chevron-up text-[9px]"></i> {{ $cmp['labelA'] ?? 'A' }} leads
                        </span>
                    @elseif($winner === 'B')
                        <span class="ml-auto insight-pill text-xs" style="color:#2563eb;background:#dbeafe;border-color:#bfdbfe;">
                            <i class="fa-solid fa-chevron-up text-[9px]"></i> {{ $cmp['labelB'] ?? 'B' }} leads
                        </span>
                    @else
                        <span class="ml-auto insight-pill text-xs" style="color:#6b7280;background:#f3f4f6;border-color:#e5e7eb;">Tied</span>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold" style="color:var(--muted);">{{ $cmp['labelA'] ?? 'A' }}</span>
                            <span class="text-xs font-semibold" style="color:var(--ink);">{{ $vA }}</span>
                        </div>
                        <div class="prog"><div class="prog-fill" style="width:{{ $pctA }}%;background:{{ $color }};"></div></div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold" style="color:var(--muted);">{{ $cmp['labelB'] ?? 'B' }}</span>
                            <span class="text-xs font-semibold" style="color:var(--ink);">{{ $vB }}</span>
                        </div>
                        <div class="prog"><div class="prog-fill" style="width:{{ $pctB }}%;background:{{ $color }};opacity:.5;"></div></div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div wire:ignore style="height:240px;">
            <canvas id="chartCompare"></canvas>
        </div>

        @elseif($compareA || $compareB)
        <div class="flex flex-col items-center justify-center py-10" style="color:var(--muted);">
            <i class="fa-solid fa-arrow-right-arrow-left text-3xl mb-3 opacity-30"></i>
            <p class="text-sm font-semibold">Select both Entity A and Entity B to compare.</p>
        </div>
        @else
        <div class="flex flex-col items-center justify-center py-10" style="color:var(--muted);">
            <i class="fa-solid fa-code-compare text-3xl mb-3 opacity-20"></i>
            <p class="text-sm font-semibold">Choose two {{ $compareMode === 'college' ? 'colleges' : ($compareMode === 'course' ? 'courses' : 'batch years') }} above to compare.</p>
        </div>
        @endif

    </div>
</div>

</div>{{-- end page container --}}

{{-- ═══════════════════════════════════════════════════════════════════════════
     COMPARE FULLSCREEN MODAL
══════════════════════════════════════════════════════════════════════════════ --}}
@if($compareFullscreen)
<div class="compare-fs-overlay" @keydown.escape.window="$wire.toggleCompareFullscreen()">
    <div class="compare-fs-panel">
        <div class="compare-fs-head">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(255,255,255,.18);">
                <i class="fa-solid fa-code-compare text-white text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-base font-semibold text-white leading-tight">Compare Tool</p>
                <p class="text-xs text-white/60 font-normal mt-0.5">
                    {{ $compareMode === 'college' ? 'By College' : ($compareMode === 'course' ? 'By Course' : 'By Batch Year') }}
                    @if($compareA && $compareB) &mdash; {{ $compareA }} vs {{ $compareB }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                @if($compareA && $compareB)
                <button wire:click="resetCompare"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition"
                        style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);">
                    <i class="fa-solid fa-rotate-left text-xs"></i> Reset
                </button>
                @endif
                <button wire:click="toggleCompareFullscreen"
                        class="w-9 h-9 rounded-xl flex items-center justify-center transition"
                        style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.18);"
                        title="Close fullscreen">
                    <i class="fa-solid fa-compress text-sm"></i>
                </button>
            </div>
        </div>

        <div class="compare-fs-body">
            <div class="flex flex-wrap gap-2 mb-5">
                <label class="mode-pill {{ $compareMode==='college' ? 'active' : '' }}">
                    <input wire:model.live="compareMode" type="radio" value="college" class="sr-only">
                    <i class="fa-solid fa-building-columns text-xs"></i> By College
                </label>
                <label class="mode-pill {{ $compareMode==='course' ? 'active' : '' }}">
                    <input wire:model.live="compareMode" type="radio" value="course" class="sr-only">
                    <i class="fa-solid fa-book text-xs"></i> By Course
                </label>
                <label class="mode-pill {{ $compareMode==='batch' ? 'active' : '' }}">
                    <input wire:model.live="compareMode" type="radio" value="batch" class="sr-only">
                    <i class="fa-solid fa-calendar text-xs"></i> By Batch Year
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-3 mb-5 p-4 rounded-2xl" style="background:#f9f7fc;border:1.5px solid var(--PM);">
                <div class="flex-1 min-w-[160px]">
                    <p class="f-label mb-1.5"><i class="fa-solid fa-a text-xs mr-1" style="color:var(--P);"></i> Entity A</p>
                    <select wire:model.live="compareA" class="f-select w-full">
                        <option value="">Select</option>
                        @foreach($compareOptions as $opt)
                            <option value="{{ $opt }}">{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="vs-badge mt-5">VS</div>
                <div class="flex-1 min-w-[160px]">
                    <p class="f-label mb-1.5"><i class="fa-solid fa-b text-xs mr-1" style="color:#3b82f6;"></i> Entity B</p>
                    <select wire:model.live="compareB" class="f-select w-full">
                        <option value="">Select</option>
                        @foreach($compareOptions as $opt)
                            @if($opt !== $compareA)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>

            @if($compareA && $compareB && $chartCompareSideBySide !== '{}')
            @php
                $cmpFs    = json_decode($chartCompareSideBySide, true);
                $totalAFs = $cmpFs['totalA'] ?? 0;
                $totalBFs = $cmpFs['totalB'] ?? 0;
                $dAFs     = $cmpFs['dataA'] ?? [];
                $dBFs     = $cmpFs['dataB'] ?? [];
                $catsFs   = $cmpFs['categories'] ?? [];
                $catIconsFs  = ['fa-briefcase','fa-store','fa-circle-pause','fa-house','fa-plane-departure','fa-graduation-cap'];
                $catColorsFs = ['#10b981','#3b82f6','#f59e0b','#14b8a6','#f97316','#7a3f91'];
            @endphp

            <div class="grid grid-cols-2 gap-4 mb-5 p-4 rounded-2xl" style="background:#f4f3f8;">
                <div class="text-center p-3 bg-white rounded-xl border" style="border-color:var(--PM);">
                    <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:var(--P);">{{ $cmpFs['labelA'] ?? '' }}</p>
                    <p class="text-3xl font-black" style="color:var(--ink);">{{ number_format($totalAFs) }}</p>
                    <p class="text-xs mt-0.5" style="color:var(--muted);">total alumni</p>
                </div>
                <div class="text-center p-3 bg-white rounded-xl border" style="border-color:#bfdbfe;">
                    <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:#3b82f6;">{{ $cmpFs['labelB'] ?? '' }}</p>
                    <p class="text-3xl font-black" style="color:var(--ink);">{{ number_format($totalBFs) }}</p>
                    <p class="text-xs mt-0.5" style="color:var(--muted);">total alumni</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                @foreach($catsFs as $idx => $cat)
                @php
                    $vAFs   = $dAFs[$idx] ?? 0;
                    $vBFs   = $dBFs[$idx] ?? 0;
                    $maxVFs = max($vAFs, $vBFs, 1);
                    $pctAFs = round($vAFs / $maxVFs * 100);
                    $pctBFs = round($vBFs / $maxVFs * 100);
                    $winFs  = $vAFs > $vBFs ? 'A' : ($vBFs > $vAFs ? 'B' : null);
                    $colorFs = $catColorsFs[$idx] ?? '#7a3f91';
                    $iconFs  = $catIconsFs[$idx] ?? 'fa-circle';
                @endphp
                <div class="p-4 rounded-xl border" style="border-color:#ede9fe;background:#faf7ff;">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                             style="background:{{ $colorFs }}18;color:{{ $colorFs }};">
                            <i class="fa-solid {{ $iconFs }} text-xs"></i>
                        </div>
                        <span class="text-sm font-semibold flex-1" style="color:var(--ink);">{{ $cat }}</span>
                        @if($winFs === 'A')
                            <span class="insight-pill text-xs" style="color:var(--P);background:var(--PL);border-color:var(--PM);">
                                <i class="fa-solid fa-chevron-up text-[9px]"></i> {{ $cmpFs['labelA'] ?? 'A' }}
                            </span>
                        @elseif($winFs === 'B')
                            <span class="insight-pill text-xs" style="color:#2563eb;background:#dbeafe;border-color:#bfdbfe;">
                                <i class="fa-solid fa-chevron-up text-[9px]"></i> {{ $cmpFs['labelB'] ?? 'B' }}
                            </span>
                        @else
                            <span class="insight-pill text-xs" style="color:#6b7280;background:#f3f4f6;border-color:#e5e7eb;">Tied</span>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <div>
                            <div class="flex justify-between mb-1">
                                <span class="text-xs font-semibold" style="color:var(--P);">{{ $cmpFs['labelA'] ?? 'A' }}</span>
                                <span class="text-sm font-semibold" style="color:var(--ink);">{{ $vAFs }}</span>
                            </div>
                            <div class="prog" style="height:7px;">
                                <div class="prog-fill" style="width:{{ $pctAFs }}%;background:{{ $colorFs }};height:7px;border-radius:99px;"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between mb-1">
                                <span class="text-xs font-semibold" style="color:#3b82f6;">{{ $cmpFs['labelB'] ?? 'B' }}</span>
                                <span class="text-sm font-semibold" style="color:var(--ink);">{{ $vBFs }}</span>
                            </div>
                            <div class="prog" style="height:7px;">
                                <div class="prog-fill" style="width:{{ $pctBFs }}%;background:{{ $colorFs }};opacity:.45;height:7px;border-radius:99px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div wire:ignore style="height:280px;">
                <canvas id="chartCompareFs"></canvas>
            </div>

            @elseif($compareA || $compareB)
            <div class="flex flex-col items-center justify-center py-16" style="color:var(--muted);">
                <i class="fa-solid fa-arrow-right-arrow-left text-4xl mb-3 opacity-30"></i>
                <p class="text-sm font-semibold">Select both Entity A and Entity B to compare.</p>
            </div>
            @else
            <div class="flex flex-col items-center justify-center py-16" style="color:var(--muted);">
                <i class="fa-solid fa-code-compare text-4xl mb-3 opacity-20"></i>
                <p class="text-base font-semibold">Choose two {{ $compareMode === 'college' ? 'colleges' : ($compareMode === 'course' ? 'courses' : 'batch years') }} above to compare.</p>
            </div>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════════
     EMPLOYMENT DETAIL MODAL
══════════════════════════════════════════════════════════════════════════════ --}}
@if($modalOpen)
<div class="emp-modal-overlay"
     wire:click.self="closeModal"
     @keydown.escape.window="$wire.closeModal()">

    <div class="emp-modal-panel">

        <div class="emp-modal-hd">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:rgba(255,255,255,.18);">
                    @php
                        $mIcon = match($modalFilter) {
                            'employed'      => 'fa-briefcase',
                            'self_employed' => 'fa-store',
                            'unemployed'    => 'fa-circle-pause',
                            'not_filled'    => 'fa-circle-question',
                            'yes'           => 'fa-check-circle',
                            'yes_partial'   => 'fa-adjust',
                            'partially'     => 'fa-adjust',
                            'no'            => 'fa-times-circle',
                            default         => 'fa-users',
                        };
                    @endphp
                    <i class="fa-solid {{ $mIcon }} text-white"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-base font-semibold text-white leading-tight">{{ $modalTitle }}</p>
                    <p class="text-xs text-white/60 mt-0.5 font-normal">
                        <span wire:loading wire:target="openEmploymentModal,updatedModalBatch,updatedModalSearch">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Loading...
                        </span>
                        <span wire:loading.remove wire:target="openEmploymentModal,updatedModalBatch,updatedModalSearch">
                            {{ number_format($modalTotal) }} record(s) found
                            @if($modalTotal > 100) &nbsp;· showing top 100 @endif
                        </span>
                    </p>
                </div>
            </div>
            <button wire:click="closeModal" class="emp-modal-close">
                <i class="fa-solid fa-xmark text-sm"></i> Close
            </button>
        </div>

        <div class="emp-modal-filters">
            <div class="emp-modal-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input wire:model.live.debounce.350ms="modalSearch"
                       type="text"
                       placeholder="Search name, ID, course, company…"
                       class="emp-modal-search w-full">
            </div>
            <select wire:model.live="modalBatch" class="f-select" style="min-width:150px;">
                <option value="">All Batch Years</option>
                @foreach($batches as $b)
                    <option value="{{ $b }}">Batch {{ $b }}</option>
                @endforeach
            </select>
            @if($modalBatch)
                <button wire:click="$set('modalBatch','')" class="text-xs font-semibold px-3 py-2 rounded-xl border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100">
                    <i class="fa-solid fa-rotate-left text-xs mr-1"></i>Clear Batch
                </button>
            @endif
        </div>

        <div class="emp-modal-body"
             wire:loading.class="wire-loading"
             wire:target="openEmploymentModal,updatedModalBatch,updatedModalSearch">

            @if(count($modalAlumni) > 0)
            <table class="emp-modal-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Alumni</th>
                        <th>Status</th>
                        <th>Company / Position</th>
                        <th>Location</th>
                        <th>Relevance</th>
                        <th style="text-align:right;">Batch</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($modalAlumni as $i => $alum)
                    @php
                        $sk  = $alum['status_key'];
                        $sc  = match($sk) { 'employed' => '#059669', 'self_employed' => '#7a3f91', 'unemployed' => '#d97706', default => '#9ca3af' };
                        $sbg = match($sk) { 'employed' => '#d1fae5', 'self_employed' => '#f3e8ff', 'unemployed' => '#fef3c7', default => '#f3f4f6' };
                        $si  = match($sk) { 'employed' => 'fa-briefcase', 'self_employed' => 'fa-store', 'unemployed' => 'fa-circle-pause', default => 'fa-circle-question' };
                    @endphp
                    <tr>
                        <td>
                            <span class="text-sm font-semibold" style="color:var(--muted);">
                                {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <div class="alum-avatar">
                                    <i class="fa-solid fa-user text-xs" style="color:var(--muted);"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold" style="color:var(--ink);">{{ $alum['name'] }}</p>
                                    <p class="text-xs font-normal" style="color:var(--muted);">{{ $alum['id_number'] }} &bull; {{ $alum['course'] }}</p>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="status-pill" style="background:{{ $sbg }};color:{{ $sc }};border-color:{{ $sc }}30;">
                                <i class="fa-solid {{ $si }} text-[9px]"></i>
                                {{ $alum['status'] }}
                            </div>
                            @if($alum['type'])
                                <p class="text-xs font-normal mt-1" style="color:var(--muted);">{{ $alum['type'] }}</p>
                            @endif
                        </td>
                        <td>
                            @if($alum['company'])
                                <p class="text-sm font-semibold" style="color:var(--ink);">{{ $alum['company'] }}</p>
                                @if($alum['position'])
                                    <p class="text-xs font-normal" style="color:var(--muted);">{{ $alum['position'] }}</p>
                                @endif
                            @else
                                <span class="text-xs" style="color:var(--muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @if($alum['location'])
                                @php
                                    $lc  = $alum['location'] === 'local' ? '#0d9488' : '#ea580c';
                                    $lbg = $alum['location'] === 'local' ? '#ccfbf1' : '#ffedd5';
                                    $li  = $alum['location'] === 'local' ? 'fa-house' : 'fa-plane-departure';
                                    $ll  = $alum['location'] === 'local' ? 'Local' : 'Abroad';
                                @endphp
                                <div class="status-pill" style="background:{{ $lbg }};color:{{ $lc }};border-color:{{ $lc }}30;">
                                    <i class="fa-solid {{ $li }} text-[9px]"></i> {{ $ll }}
                                </div>
                            @else
                                <span class="text-xs" style="color:var(--muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @if($alum['relevance'])
                                @php
                                    $rel = $alum['relevance'];
                                    $rc  = $rel === 'yes' ? '#059669' : ($rel === 'partially' ? '#d97706' : '#dc2626');
                                    $rbg = $rel === 'yes' ? '#d1fae5' : ($rel === 'partially' ? '#fef3c7' : '#fee2e2');
                                    $rl  = $rel === 'yes' ? 'Related' : ($rel === 'partially' ? 'Partial' : 'Not Rel.');
                                    $ri  = $rel === 'yes' ? 'fa-circle-check' : ($rel === 'partially' ? 'fa-circle-half-stroke' : 'fa-circle-xmark');
                                @endphp
                                <div class="status-pill" style="background:{{ $rbg }};color:{{ $rc }};border-color:{{ $rc }}30;">
                                    <i class="fa-solid {{ $ri }} text-[9px]"></i> {{ $rl }}
                                </div>
                            @else
                                <span class="text-xs" style="color:var(--muted);">—</span>
                            @endif
                        </td>
                        <td style="text-align:right;">
                            <span class="text-sm font-semibold" style="color:var(--ink);">{{ $alum['batch'] }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="flex flex-col items-center justify-center py-20" style="color:var(--muted);">
                <i class="fa-solid fa-inbox text-5xl mb-4 opacity-20"></i>
                <p class="text-sm font-semibold">No alumni found matching your criteria.</p>
                @if($modalSearch || $modalBatch)
                    <button wire:click="$set('modalSearch',''); $set('modalBatch','')"
                            class="mt-3 text-xs font-semibold px-4 py-2 rounded-xl border transition"
                            style="border-color:var(--border);color:var(--P);">
                        Clear filters
                    </button>
                @endif
            </div>
            @endif
        </div>

    </div>
</div>
@endif

{{-- ══ CHART DATA BRIDGE ══════════════════════════════════════════════════════ --}}
<div id="__adm_emp_data" style="display:none"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-college="{{ $chartCollegeData }}"
     data-course="{{ $chartCourseData }}"
     data-emptype="{{ $chartEmpTypeData }}"
     data-career="{{ $chartCareerPathData }}"
     data-edu="{{ $chartEduStatusData }}"
     data-compare="{{ $chartCompareSideBySide }}"
     data-unemployed="{{ $chartUnemployedData }}">
</div>

{{-- ══ SCRIPT ══════════════════════════════════════════════════════════════════ --}}
<script>
(function(){
    'use strict';

    var BATCH_PAGE = 8;
    var batchIdx   = 0;
    var batchAll   = null;
    var registry   = {};

    function loadChartJs(cb){
        if(window.Chart){ cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function bridge(){
        var el = document.getElementById('__adm_emp_data');
        if(!el) return null;
        try {
            return {
                status:     JSON.parse(el.getAttribute('data-status')     || 'null'),
                location:   JSON.parse(el.getAttribute('data-location')   || 'null'),
                relevance:  JSON.parse(el.getAttribute('data-relevance')  || 'null'),
                batch:      JSON.parse(el.getAttribute('data-batch')      || 'null'),
                college:    JSON.parse(el.getAttribute('data-college')    || 'null'),
                course:     JSON.parse(el.getAttribute('data-course')     || 'null'),
                emptype:    JSON.parse(el.getAttribute('data-emptype')    || 'null'),
                career:     JSON.parse(el.getAttribute('data-career')     || 'null'),
                edu:        JSON.parse(el.getAttribute('data-edu')        || 'null'),
                compare:    JSON.parse(el.getAttribute('data-compare')    || 'null'),
                unemployed: JSON.parse(el.getAttribute('data-unemployed') || 'null'),
            };
        } catch(e){ return null; }
    }

    function kill(id){
        if(registry[id]){ registry[id].destroy(); delete registry[id]; }
    }

    function allZero(arr){
        return !arr || arr.every(function(v){ return !v || v === 0; });
    }

    function toggleNoData(canvasId, isEmpty){
        var noDataId = canvasId + 'NoData';
        var canvas   = document.getElementById(canvasId);
        var noData   = document.getElementById(noDataId);
        if(canvas)  canvas.style.display = isEmpty ? 'none' : '';
        if(noData)  noData.style.display = isEmpty ? 'flex' : 'none';
    }

    /* ── Donut ── */
    function donut(id, data, clickHandler){
        if(!data || !data.labels) return;
        var empty = allZero(data.data);
        toggleNoData(id, empty);
        if(empty){ kill(id); return; }
        var c = document.getElementById(id); if(!c) return;
        kill(id);

        var opts = {
            responsive: true, maintainAspectRatio: false, cutout: '66%',
            plugins: {
                legend: {
                    position: 'bottom',
                    onClick: function(){},
                    labels: { font: { size: 11, weight: '600' }, color: '#333', padding: 10, usePointStyle: true, pointStyleWidth: 8 }
                },
                tooltip: { callbacks: { label: function(ctx){
                    var t = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                    var p = t ? Math.round(ctx.parsed / t * 100) : 0;
                    return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + p + '%)';
                }}}
            }
        };

        if(typeof clickHandler === 'function'){
            opts.onClick = clickHandler;
        }

        registry[id] = new Chart(c, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8
                }]
            },
            options: opts
        });
    }

    /* ── Horizontal bar (e.g. top courses) ── */
    function hbar(id, data){
        if(!data || !data.labels) return;
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Alumni', data: data.data,
                    backgroundColor: 'rgba(122,63,145,.75)',
                    borderColor: '#7a3f91', borderWidth: 1, borderRadius: 5
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx){ return ' ' + ctx.parsed.x + ' alumni'; }}}
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11, weight: '600' }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' }, color: '#333' }}
                }
            }
        });
    }

    /* ── Polar area ── */
    function polar(id, data){
        if(!data || !data.labels) return;
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'polarArea',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors.map(function(x){ return x + 'cc'; }),
                    borderColor: data.colors,
                    borderWidth: 1.5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        onClick: function(){},
                        labels: { font: { size: 10, weight: '600' }, color: '#333', padding: 8, usePointStyle: true, pointStyleWidth: 7 }
                    },
                    tooltip: { callbacks: { label: function(ctx){ return ' ' + ctx.label + ': ' + ctx.parsed.r; }}}
                },
                scales: { r: { ticks: { display: false }, grid: { color: '#f3f4f6' }}}
            }
        });
    }

    /* ── Vertical stacked bar (for Batch chart) ── */
    function stackedBar(id, labels, employed, self_emp, unemployed){
        var c = document.getElementById(id); if(!c) return;
        if(registry[id]){
            var ch = registry[id];
            ch.data.labels = labels;
            ch.data.datasets[0].data = employed;
            ch.data.datasets[1].data = self_emp;
            ch.data.datasets[2].data = unemployed;
            ch.update('active'); return;
        }
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Employed',      data: employed,   backgroundColor: '#10b981', borderRadius: 3, stack: 'a' },
                    { label: 'Self-Employed', data: self_emp,   backgroundColor: '#3b82f6', borderRadius: 3, stack: 'a' },
                    { label: 'Unemployed',    data: unemployed, backgroundColor: '#f59e0b', borderRadius: 3, stack: 'a' },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 350 },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        onClick: function(){},
                        labels: { font: { size: 11, weight: '600' }, color: '#333', padding: 12, usePointStyle: true }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#666', maxRotation: 35 }},
                    y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true }
                }
            }
        });
    }

    /*
     * ════════════════════════════════════════════════════════════════════════
     * FIX: stackedBarH — HORIZONTAL stacked bar for College chart
     * Labels go on the Y-axis → no more sideways/diagonal text
     * ════════════════════════════════════════════════════════════════════════
     */
    function stackedBarH(id, labels, employed, self_emp, unemployed){
        var c = document.getElementById(id); if(!c) return;
        if(registry[id]){
            var ch = registry[id];
            ch.data.labels = labels;
            ch.data.datasets[0].data = employed;
            ch.data.datasets[1].data = self_emp;
            ch.data.datasets[2].data = unemployed;
            ch.update('active'); return;
        }
        kill(id);

        /*
         * Truncate long college names so they fit neatly on the Y-axis.
         * Full name is still shown in the tooltip.
         */
        var fullLabels = labels;
        var shortLabels = labels.map(function(l){
            // Strip common "College of" prefix to save space
            var s = l.replace(/^College of\s+/i, '').replace(/^College\s+/i, '');
            return s.length > 22 ? s.slice(0, 20) + '…' : s;
        });

        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: shortLabels,
                datasets: [
                    { label: 'Employed',      data: employed,   backgroundColor: '#10b981', borderRadius: 3, stack: 'a' },
                    { label: 'Self-Employed', data: self_emp,   backgroundColor: '#3b82f6', borderRadius: 3, stack: 'a' },
                    { label: 'Unemployed',    data: unemployed, backgroundColor: '#f59e0b', borderRadius: 3, stack: 'a' },
                ]
            },
            options: {
                /* KEY: indexAxis:'y' flips to horizontal — labels on Y-axis, values on X */
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350 },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        onClick: function(){},
                        labels: { font: { size: 11, weight: '600' }, color: '#333', padding: 12, usePointStyle: true }
                    },
                    tooltip: {
                        callbacks: {
                            /* Show full college name in tooltip, not the truncated version */
                            title: function(items){
                                var idx = items[0].dataIndex;
                                return fullLabels[idx] || shortLabels[idx];
                            },
                            label: function(ctx){
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.x;
                            }
                        }
                    }
                },
                scales: {
                    /* X axis = values (numbers) */
                    x: {
                        stacked: true,
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 },
                        beginAtZero: true
                    },
                    /* Y axis = category labels — no rotation needed, they're already upright */
                    y: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#333',
                            /* Never rotate Y-axis labels */
                            maxRotation: 0,
                            minRotation: 0
                        }
                    }
                }
            }
        });
    }

    /* ── Grouped bar (compare) ── */
    function groupedBar(id, data){
        if(!data || !data.labelA || !data.labelB) return;
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: data.categories,
                datasets: [
                    { label: data.labelA, data: data.dataA, backgroundColor: 'rgba(122,63,145,.8)', borderColor: '#7a3f91', borderWidth: 1, borderRadius: 4 },
                    { label: data.labelB, data: data.dataB, backgroundColor: 'rgba(59,130,246,.7)',  borderColor: '#3b82f6', borderWidth: 1, borderRadius: 4 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        onClick: function(){},
                        labels: { font: { size: 11, weight: '600' }, color: '#333', padding: 12, usePointStyle: true }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#333' }},
                    y: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true }
                }
            }
        });
    }

    /* ── Batch pagination helpers ── */
    function sliceBatch(data, start){
        var end = start + BATCH_PAGE;
        return {
            labels:     data.labels.slice(start, end),
            employed:   data.employed.slice(start, end),
            self_emp:   data.self_emp.slice(start, end),
            unemployed: data.unemployed.slice(start, end)
        };
    }

    function drawBatch(data, start){
        if(!data || !data.labels || !data.labels.length) return;
        var sl = sliceBatch(data, start);
        stackedBar('chartBatch', sl.labels, sl.employed, sl.self_emp, sl.unemployed);
        var total = data.labels.length, pages = Math.ceil(total / BATCH_PAGE), cur = Math.floor(start / BATCH_PAGE) + 1;
        var nav  = document.getElementById('batchNavControls');
        var prev = document.getElementById('batchPrev');
        var next = document.getElementById('batchNext');
        var info = document.getElementById('batchPageInfo');
        if(nav && pages > 1){
            nav.style.display = 'flex';
            if(info) info.textContent = cur + ' / ' + pages;
            if(prev) prev.disabled = (start <= 0);
            if(next) next.disabled = (start + BATCH_PAGE >= total);
        } else if(nav) { nav.style.display = 'none'; }
    }

    function bindBatchNav(){
        var prev = document.getElementById('batchPrev');
        var next = document.getElementById('batchNext');
        if(!prev || !next) return;
        var np = prev.cloneNode(true); var nn = next.cloneNode(true);
        prev.parentNode.replaceChild(np, prev);
        next.parentNode.replaceChild(nn, next);
        np.addEventListener('click', function(){
            if(!batchAll) return;
            batchIdx = Math.max(0, batchIdx - BATCH_PAGE);
            drawBatch(batchAll, batchIdx);
        });
        nn.addEventListener('click', function(){
            if(!batchAll) return;
            var mx = batchAll.labels.length - BATCH_PAGE;
            batchIdx = Math.min(mx, batchIdx + BATCH_PAGE);
            drawBatch(batchAll, batchIdx);
        });
    }

    /* ── Modal dispatcher ── */
    function dispatchModal(filterType, filter){
        if(window.Livewire){
            Livewire.dispatch('openEmploymentModal', { filterType: filterType, filter: filter });
        }
    }

    /* ── Master init ── */
    function initAll(){
        var d = bridge(); if(!d) return;

        /* Employment Status: click segment → modal */
        donut('chartStatus', d.status, function(event, elements){
            if(!elements || !elements.length) return;
            var statusMap = ['employed', 'self_employed', 'unemployed', 'not_filled'];
            var filter    = statusMap[elements[0].index];
            if(filter) dispatchModal('status', filter);
        });

        donut('chartLocation', d.location);

        /* Relevance: green segment = YES only, anything else = YES+PARTIAL */
        donut('chartRelevance', d.relevance, function(event, elements){
            var isGreen = elements && elements.length > 0 && elements[0].index === 0;
            dispatchModal('relevance', isGreen ? 'yes' : 'yes_partial');
        });

        donut('chartUnemployed', d.unemployed);
        donut('chartEmpType',    d.emptype);
        donut('chartEduStatus',  d.edu);
        hbar( 'chartCourse',     d.course);
        polar('chartCareerPath', d.career);

        /* ── College chart: use HORIZONTAL stacked bar ── */
        if(d.college && d.college.labels){
            stackedBarH(
                'chartCollege',
                d.college.labels,
                d.college.employed,
                d.college.self_emp,
                d.college.unemployed
            );
        }

        /* Batch chart: vertical stacked, paginated */
        if(d.batch && d.batch.labels){
            var changed = !batchAll || JSON.stringify(d.batch.labels) !== JSON.stringify(batchAll.labels);
            if(changed){
                batchAll = d.batch;
                batchIdx = Math.max(0, batchAll.labels.length - BATCH_PAGE);
                kill('chartBatch');
            }
            drawBatch(batchAll, batchIdx);
        }
        bindBatchNav();

        if(d.compare && d.compare.labelA && d.compare.labelB){
            groupedBar('chartCompare',   d.compare);
            groupedBar('chartCompareFs', d.compare);
        } else {
            kill('chartCompare');
            kill('chartCompareFs');
        }
    }

    /* ── Bootstrap ── */
    loadChartJs(function(){
        if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }

        document.addEventListener('livewire:navigated', function(){
            kill('chartBatch'); kill('chartCollege');
            kill('chartCompare'); kill('chartCompareFs');
            requestAnimationFrame(initAll);
        });

        if(window.Livewire){
            Livewire.hook('commit', function(p){
                var ok = p.succeed || (p.component && p.respond);
                if(typeof ok === 'function'){ ok(function(){ requestAnimationFrame(initAll); }); }
                else { requestAnimationFrame(initAll); }
            });
        } else {
            document.addEventListener('livewire:initialized', function(){
                Livewire.hook('commit', function(p){
                    var ok = p.succeed || function(cb){ cb({}); };
                    ok(function(){ requestAnimationFrame(initAll); });
                });
            });
        }
    });

})();
</script>

</div>