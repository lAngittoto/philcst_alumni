{{-- resources/views/livewire/admin/admin-dashboard.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    public array  $stats        = [];
    public array  $recentLogs   = [];
    public array  $courseStats  = [];
    public array  $monthlyData  = [];
    public string $greeting     = '';
    public string $adminName    = '';

    // ── Employment Analytics ──────────────────────────────────────────────────
    public string $filterBatch   = '';
    public string $filterCollege = '';
    public string $filterCourse  = '';

    public int $totalAlumni     = 0;
    public int $totalFilled     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalLocal      = 0;
    public int $totalAbroad     = 0;

    public string $chartStatusData        = '{}';
    public string $chartLocationData      = '{}';
    public string $chartRelevanceData     = '{}';
    public string $chartBatchData         = '{}';
    public string $chartCollegeData       = '{}';
    public string $chartCourseData        = '{}';
    public string $chartEmpTypeData       = '{}';
    public string $chartCareerPathData    = '{}';
    public string $chartEduStatusData     = '{}';
    public string $chartUnemployedData    = '{}';

    public array $empBatches  = [];
    public array $empColleges = [];
    public array $empCourses  = [];

    public function mount(): void
    {
        if (Auth::user()?->role !== 'admin') {
            $this->redirect(route('login'));
        }

        $this->adminName = Auth::user()?->name ?? 'Admin';
        $hour = now()->setTimezone('Asia/Manila')->hour;
        $this->greeting = match(true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };

        $this->loadStats();
        $this->loadRecentLogs();
        $this->loadCourseStats();
        $this->loadMonthlyData();
        $this->loadEmpMetaLists();
        $this->refreshEmpAll();
    }

    // ── Dashboard Stats ───────────────────────────────────────────────────────

    private function loadStats(): void
    {
        $this->stats = Cache::remember('dashboard_stats', 60, function () {
            $totalAlumni   = Alumni::count();
            $verified      = Alumni::where('status', 'verified')->count();
            $pending       = Alumni::where('status', 'pending')->count();
            $rejected      = Alumni::where('status', 'rejected')->count();
            $totalCourses  = Course::count();
            $todayActivity = AuditLog::whereDate('created_at', today())->count();
            $flagged       = AuditLog::where('is_flagged', true)->count();
            $thisMonth     = Alumni::whereMonth('created_at', now()->month)
                                   ->whereYear('created_at',  now()->year)
                                   ->count();
            $lastMonth     = Alumni::whereMonth('created_at', now()->subMonth()->month)
                                   ->whereYear('created_at',  now()->subMonth()->year)
                                   ->count();
            $growth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0);

            return compact(
                'totalAlumni','verified','pending','rejected',
                'totalCourses','todayActivity','flagged',
                'thisMonth','growth'
            );
        });
    }

    private function loadRecentLogs(): void
    {
        $this->recentLogs = AuditLog::latest()
            ->take(8)
            ->get()
            ->map(fn($l) => [
                'id'          => $l->id,
                'action'      => $l->action,
                'action_label'=> $l->action_label,
                'action_icon' => $l->action_icon,
                'user_name'   => $l->user_name ?? 'System',
                'user_email'  => $l->user_email,
                'user_role'   => $l->user_role ?? 'system',
                'description' => mb_strlen($l->description) > 60
                    ? mb_substr($l->description, 0, 57).'…'
                    : $l->description,
                'severity'      => $l->severity,
                'severity_badge'=> $l->severity_badge,
                'is_flagged'    => $l->is_flagged,
                'time'          => $l->created_at->setTimezone('Asia/Manila')->diffForHumans(),
                'date'          => $l->created_at->setTimezone('Asia/Manila')->format('M j, g:i A'),
            ])
            ->toArray();
    }

    private function loadCourseStats(): void
    {
        $this->courseStats = Course::select('courses.id','courses.code','courses.name')
            ->withCount('alumni')
            ->orderByDesc('alumni_count')
            ->take(6)
            ->get()
            ->toArray();
    }

    private function loadMonthlyData(): void
    {
        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $count = Alumni::whereYear('created_at',  $month->year)
                           ->whereMonth('created_at', $month->month)
                           ->count();
            $data[] = [
                'label' => $month->format('M'),
                'count' => $count,
            ];
        }
        $this->monthlyData = $data;
    }

    public function refresh(): void
    {
        Cache::forget('dashboard_stats');
        Cache::forget('audit_stats');
        $this->loadStats();
        $this->loadRecentLogs();
        $this->loadCourseStats();
        $this->loadMonthlyData();
        $this->refreshEmpAll();
    }

    // ── Employment Analytics ──────────────────────────────────────────────────

    private function loadEmpMetaLists(): void
    {
        $this->empBatches = DB::table('alumni')
            ->whereNull('deleted_at')
            ->distinct()->orderBy('batch', 'desc')
            ->pluck('batch')->toArray();

        $this->empColleges = DB::table('courses')
            ->distinct()->orderBy('college')
            ->pluck('college')->filter()->values()->toArray();

        $this->empCourses = DB::table('courses')
            ->orderBy('code')
            ->get(['code', 'name', 'college'])
            ->map(fn($r) => ['code' => $r->code, 'name' => $r->name, 'college' => $r->college])
            ->toArray();
    }

    private function empBaseQ(): \Illuminate\Database\Query\Builder
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

    private function empEmpQ(): \Illuminate\Database\Query\Builder
    {
        return (clone $this->empBaseQ())
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');
    }

    public function refreshEmpAll(): void
    {
        $this->computeEmpStats();
        $this->buildEmpCharts();
    }

    public function computeEmpStats(): void
    {
        $this->totalAlumni     = (clone $this->empBaseQ())->count();
        $this->totalEmployed   = (clone $this->empEmpQ())->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $this->empEmpQ())->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $this->empEmpQ())->where('et.employment_status', 'unemployed')->count();
        $this->totalFilled     = $this->totalEmployed + $this->totalSelf + $this->totalUnemployed;
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalFilled);
        $this->totalLocal      = (clone $this->empEmpQ())->where('et.work_location', 'local')->count();
        $this->totalAbroad     = (clone $this->empEmpQ())->where('et.work_location', 'abroad')->count();
    }

    public function buildEmpCharts(): void
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

        $relRows = (clone $this->empEmpQ())
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

        $batchRows = (clone $this->empBaseQ())
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

        $empTypeRows = (clone $this->empEmpQ())
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

        $cpRows = (clone $this->empEmpQ())->whereNotNull('et.career_path')->select('et.career_path')->get();
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

        $eduRows = (clone $this->empEmpQ())
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

        $unRows = (clone $this->empEmpQ())
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
    }

    public function updatedFilterBatch(): void   { $this->refreshEmpAll(); }
    public function updatedFilterCollege(): void { $this->filterCourse = ''; $this->refreshEmpAll(); }
    public function updatedFilterCourse(): void  { $this->refreshEmpAll(); }

    public function clearEmpFilters(): void
    {
        $this->filterBatch = $this->filterCollege = $this->filterCourse = '';
        $this->refreshEmpAll();
    }
}; ?>

{{-- ✅ SINGLE ROOT ELEMENT — fixes MultipleRootElementsDetectedException --}}
<div>

<style>
@keyframes fadeUp  { from { opacity:0; transform:translateY(16px) } to { opacity:1; transform:none } }
@keyframes shimmer { 0%,100% { opacity:.7 } 50% { opacity:1 } }
@keyframes spin    { to { transform:rotate(360deg) } }
@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0 rgba(122,63,145,.35) }
    70%  { box-shadow: 0 0 0 8px rgba(122,63,145,0)  }
    100% { box-shadow: 0 0 0 0 rgba(122,63,145,0)    }
}
@keyframes bar-grow { from { height:0 } to { height:var(--h) } }

.fade-up   { animation: fadeUp .44s cubic-bezier(.25,.8,.25,1) both }
.fade-up-1 { animation-delay:.04s } .fade-up-2 { animation-delay:.09s }
.fade-up-3 { animation-delay:.14s } .fade-up-4 { animation-delay:.19s }
.fade-up-5 { animation-delay:.24s } .fade-up-6 { animation-delay:.29s }
.fade-up-7 { animation-delay:.34s } .fade-up-8 { animation-delay:.39s }

.pulse-dot  { animation: pulse-ring 2s ease-in-out infinite }
.spin-anim  { animation: spin 1s linear infinite }

.card-hover {
    transition: transform .18s cubic-bezier(.25,.8,.25,1), box-shadow .18s;
}
.card-hover:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(122,63,145,.13), 0 3px 8px rgba(0,0,0,.06);
}

.scroll-sm::-webkit-scrollbar       { width:3px }
.scroll-sm::-webkit-scrollbar-track { background:#f3f0f8; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb { background:#c4a8d8; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb:hover { background:#7a3f91 }

.bar-anim { animation: bar-grow .7s cubic-bezier(.25,.8,.25,1) both }

.hero-mesh {
    background: linear-gradient(135deg,#7A3F91 0%,#9b59b6 40%,#6c3483 100%);
    position: relative;
    overflow: hidden;
}
.hero-mesh::before {
    content:'';
    position:absolute;
    inset:0;
    background:
        radial-gradient(ellipse 60% 80% at 80% -20%, rgba(255,255,255,.12) 0%, transparent 60%),
        radial-gradient(ellipse 40% 60% at -10% 80%, rgba(0,0,0,.15) 0%, transparent 70%);
    pointer-events:none;
}
.hero-mesh::after {
    content:'';
    position:absolute;
    inset:0;
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 18px,
        rgba(255,255,255,.03) 18px,
        rgba(255,255,255,.03) 19px
    );
    pointer-events:none;
}

/* Employment analytics styles */
.emp-stat-card {
    background: #fff;
    border: 1px solid #E8E0F0;
    border-radius: 16px;
    padding: 18px 18px 14px;
    position: relative;
    overflow: hidden;
}
.emp-stat-card::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 0 0 16px 16px;
}
.emp-stat-card.c-purple::after { background: #7a3f91; }
.emp-stat-card.c-green::after  { background: #10b981; }
.emp-stat-card.c-blue::after   { background: #3b82f6; }
.emp-stat-card.c-amber::after  { background: #f59e0b; }
.emp-stat-card.c-gray::after   { background: #9ca3af; }
.emp-stat-card.c-teal::after   { background: #14b8a6; }
.emp-stat-card.c-rose::after   { background: #f43f5e; }
.emp-stat-card.c-orange::after { background: #f97316; }

.emp-chart-card {
    background: #fff;
    border: 1px solid #E8E0F0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.emp-chart-head {
    padding: 12px 18px;
    border-bottom: 1px solid #E8E0F0;
    background: #F9F7FC;
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px;
}
.emp-chart-dot  { width: 8px; height: 8px; border-radius: 50%; background: #7a3f91; flex-shrink: 0; }
.emp-chart-ttl  { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #333; }
.emp-chart-body { padding: 16px; }

.emp-prog { height: 5px; border-radius: 99px; background: #ede9fe; overflow: hidden; margin-top: 5px; }
.emp-prog-fill { height: 100%; border-radius: 99px; transition: width .6s; }

.emp-rank-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid #f5f5f5;
}
.emp-rank-row:last-child { border-bottom: none; }
.emp-rank-num { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .72rem; font-weight: 600; flex-shrink: 0; }

.emp-f-select {
    padding: 8px 12px; border: 1.5px solid #E8E0F0; border-radius: 10px;
    font-size: .85rem; font-family: inherit; font-weight: 500;
    background: #fff; color: #333;
    transition: border-color .15s, box-shadow .15s;
    cursor: pointer;
}
.emp-f-select:focus { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }

.wire-emp-loading { opacity: .4; pointer-events: none; transition: opacity .2s; }

[x-cloak] { display:none !important }
</style>

<div class="min-h-screen bg-[#F4F0F9] font-sans antialiased">
<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-5">

    {{-- ══════════════════════ HERO HEADER ══════════════════════ --}}
    <div class="hero-mesh rounded-2xl px-6 sm:px-8 py-7 fade-up">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">

            {{-- Left --}}
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-white/20 border border-white/30 flex items-center justify-center flex-shrink-0 shadow-lg pulse-dot">
                    <i class="fas fa-gauge-high text-white text-2xl"></i>
                </div>
                <div>
                    <p class="text-white/55 text-[.65rem] font-semibold uppercase tracking-[.15em] leading-none mb-1">
                        Admin Panel
                    </p>
                    <h1 class="text-white text-[1.75rem] sm:text-[2rem] font-bold leading-tight tracking-tight">
                        {{ $greeting }}, {{ $adminName }}
                    </h1>
                    <p class="text-white/60 text-[.78rem] mt-1">
                        <i class="fas fa-circle text-[5px] text-emerald-300 mr-1.5 align-middle"></i>
                        {{ now()->setTimezone('Asia/Manila')->format('l, F j, Y · g:i A') }} PHT
                    </p>
                </div>
            </div>

            {{-- Right Actions --}}
            <div class="flex items-center gap-2.5 flex-wrap">
                <button wire:click="refresh"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-white/15 border border-white/30
                               rounded-xl text-white text-sm font-semibold hover:bg-white/25 transition-colors">
                    <i class="fas fa-arrows-rotate text-xs" wire:loading.class="spin-anim" wire:target="refresh"></i>
                    Refresh
                </button>
            </div>
        </div>

        {{-- Mini KPI strip --}}
        <div class="relative z-10 mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            @php
            $kpis = [
                ['label' => 'Total Alumni',  'value' => number_format($stats['totalAlumni'] ?? 0),  'icon' => 'fa-users',         'color' => 'bg-white/15'],
                ['label' => 'Verified',      'value' => number_format($stats['verified']    ?? 0),  'icon' => 'fa-circle-check',  'color' => 'bg-emerald-400/20'],
                ['label' => 'Pending',       'value' => number_format($stats['pending']     ?? 0),  'icon' => 'fa-clock',         'color' => 'bg-amber-400/20'],
                ['label' => 'Total Courses', 'value' => number_format($stats['totalCourses']?? 0),  'icon' => 'fa-book-open',     'color' => 'bg-indigo-400/20'],
            ];
            @endphp
            @foreach($kpis as $k)
            <div class="flex items-center gap-3 {{ $k['color'] }} border border-white/20 rounded-xl px-4 py-3">
                <div class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas {{ $k['icon'] }} text-white text-sm"></i>
                </div>
                <div>
                    <div class="text-white text-[1.2rem] font-bold leading-none">{{ $k['value'] }}</div>
                    <div class="text-white/60 text-[.67rem] font-semibold mt-0.5">{{ $k['label'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ══════════════════════ STAT CARDS ROW ══════════════════════ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @php
        $cards = [
            ['label'=>'Total Alumni',  'value'=>number_format($stats['totalAlumni']??0),  'sub'=>'all records',        'icon'=>'fa-users',        'bg'=>'bg-[#f5eef9]', 'icon_c'=>'text-[#7A3F91]', 'delay'=>'fade-up-1'],
            ['label'=>'Verified',      'value'=>number_format($stats['verified']??0),     'sub'=>'approved alumni',    'icon'=>'fa-circle-check', 'bg'=>'bg-emerald-50','icon_c'=>'text-emerald-600','delay'=>'fade-up-2'],
            ['label'=>'Pending',       'value'=>number_format($stats['pending']??0),      'sub'=>'awaiting review',    'icon'=>'fa-clock',        'bg'=>'bg-amber-50',  'icon_c'=>'text-amber-600', 'delay'=>'fade-up-3'],
            ['label'=>'Rejected',      'value'=>number_format($stats['rejected']??0),     'sub'=>'declined records',   'icon'=>'fa-circle-xmark', 'bg'=>'bg-red-50',    'icon_c'=>'text-red-500',   'delay'=>'fade-up-4'],
            ['label'=>'This Month',    'value'=>number_format($stats['thisMonth']??0),    'sub'=>(($stats['growth']??0)>=0?'+':''). ($stats['growth']??0).'% vs last mo.','icon'=>'fa-calendar-plus','bg'=>'bg-cyan-50','icon_c'=>'text-cyan-600','delay'=>'fade-up-5'],
            ['label'=>'Flagged Logs',  'value'=>number_format($stats['flagged']??0),      'sub'=>'needs review',       'icon'=>'fa-flag',         'bg'=>'bg-orange-50', 'icon_c'=>'text-orange-500','delay'=>'fade-up-6'],
        ];
        @endphp

        @foreach($cards as $c)
        <div class="bg-white rounded-2xl p-5 border border-[#E8E0F0] shadow-sm card-hover fade-up {{ $c['delay'] }}">
            <div class="w-[42px] h-[42px] rounded-[11px] {{ $c['bg'] }} flex items-center justify-center mb-3">
                <i class="fas {{ $c['icon'] }} {{ $c['icon_c'] }} text-[17px]"></i>
            </div>
            <div class="text-[2rem] font-bold leading-none text-[#333333] tracking-tight">{{ $c['value'] }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-[#888] mt-2">{{ $c['label'] }}</div>
            <div class="text-[.72rem] font-normal text-[#AAAAAA] mt-[3px]">{{ $c['sub'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- ══════════════════════ MIDDLE ROW: Chart + System Summary ══════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        {{-- Monthly Alumni Bar Chart --}}
        <div class="lg:col-span-3 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden card-hover fade-up fade-up-4">
            <div class="px-6 py-4 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-chart-column text-white text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-[#333333] uppercase tracking-wide leading-none">Alumni Registrations</p>
                        <p class="text-[.67rem] text-[#999] mt-0.5">Last 6 months</p>
                    </div>
                </div>
                <span class="text-[.68rem] font-semibold px-2.5 py-1 rounded-full bg-[#f5eef9] text-[#7A3F91] border border-[#d4aaeb]">
                    Monthly Trend
                </span>
            </div>

            @php
                $maxVal = max(array_column($monthlyData, 'count') ?: [1]);
                $maxVal = $maxVal < 1 ? 1 : $maxVal;
            @endphp

            <div class="px-6 py-6">
                <div class="flex items-end justify-between gap-3 h-40">
                    @foreach($monthlyData as $i => $m)
                    @php
                        $pct   = ($m['count'] / $maxVal) * 100;
                        $isMax = $m['count'] === $maxVal && $m['count'] > 0;
                        $ht    = max($pct, 4);
                    @endphp
                    <div class="flex-1 flex flex-col items-center gap-2">
                        <span class="text-[.72rem] font-bold text-[#7A3F91]">{{ $m['count'] > 0 ? $m['count'] : '' }}</span>
                        <div class="w-full flex items-end" style="height:120px;">
                            <div class="w-full rounded-t-xl transition-all duration-700 bar-anim"
                                 style="--h:{{ $ht }}%; height:{{ $ht }}%;
                                        background: {{ $isMax
                                            ? 'linear-gradient(180deg,#7A3F91,#9b59b6)'
                                            : 'linear-gradient(180deg,#d4aaeb,#e9d5f9)' }};
                                        animation-delay: {{ $i * 0.08 }}s;">
                            </div>
                        </div>
                        <span class="text-[.67rem] font-semibold text-[#AAAAAA] uppercase">{{ $m['label'] }}</span>
                    </div>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center gap-4 pt-4 border-t border-[#F5F0FA]">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);"></div>
                        <span class="text-[.67rem] font-semibold text-[#888]">Peak Month</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:#d4aaeb;"></div>
                        <span class="text-[.67rem] font-semibold text-[#888]">Other Months</span>
                    </div>
                    <div class="ml-auto text-[.67rem] text-[#AAAAAA]">
                        Total: <strong class="text-[#333]">{{ array_sum(array_column($monthlyData,'count')) }}</strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- System Summary Panel --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden card-hover fade-up fade-up-5">
            <div class="px-5 py-4 border-b border-[#E8E0F0]"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-chart-pie text-white text-xs"></i>
                    </div>
                    <p class="text-sm font-bold text-[#333333] uppercase tracking-wide">System Summary</p>
                </div>
            </div>

            <div class="p-5 space-y-3">
                @php
                $summaryItems = [
                    ['icon'=>'fa-users',        'label'=>'Total Alumni',    'value'=>number_format($stats['totalAlumni']??0),  'color'=>'#7A3F91','bg'=>'#f5eef9'],
                    ['icon'=>'fa-circle-check', 'label'=>'Verified Alumni', 'value'=>number_format($stats['verified']??0),     'color'=>'#059669','bg'=>'#ecfdf5'],
                    ['icon'=>'fa-clock',        'label'=>'Pending Review',  'value'=>number_format($stats['pending']??0),      'color'=>'#d97706','bg'=>'#fffbeb'],
                    ['icon'=>'fa-circle-xmark', 'label'=>'Rejected',        'value'=>number_format($stats['rejected']??0),     'color'=>'#ef4444','bg'=>'#fef2f2'],
                    ['icon'=>'fa-book-open',    'label'=>'Total Courses',   'value'=>number_format($stats['totalCourses']??0), 'color'=>'#2563eb','bg'=>'#eff6ff'],
                    ['icon'=>'fa-flag',         'label'=>'Flagged Logs',    'value'=>number_format($stats['flagged']??0),      'color'=>'#f97316','bg'=>'#fff7ed'],
                ];
                @endphp

                @foreach($summaryItems as $item)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl border border-[#F0E8F8]"
                     style="background:#fdfbff;">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                         style="background:{{ $item['bg'] }};">
                        <i class="fas {{ $item['icon'] }} text-sm" style="color:{{ $item['color'] }};"></i>
                    </div>
                    <span class="text-sm font-semibold text-[#444444] flex-1">{{ $item['label'] }}</span>
                    <span class="text-sm font-bold" style="color:{{ $item['color'] }};">{{ $item['value'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══════════════════════ BOTTOM ROW: Recent Logs + Course Breakdown ══════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        {{-- Recent Activity Feed --}}
        <div class="lg:col-span-3 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col card-hover fade-up fade-up-6">
            <div class="px-5 py-4 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-clock-rotate-left text-white text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-[#333333] uppercase tracking-wide leading-none">Recent Activity</p>
                        <p class="text-[.67rem] text-[#999] mt-0.5">Latest system events</p>
                    </div>
                </div>
            </div>

            <div class="divide-y divide-[#F9F5FD] overflow-y-auto flex-1 max-h-[420px] scroll-sm">
                @forelse($recentLogs as $log)
                @php
                    $actionColor = match($log['action']) {
                        'login'          => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'logout'         => 'bg-gray-50 text-gray-600 border-gray-200',
                        'failed_login'   => 'bg-amber-50 text-amber-700 border-yellow-300',
                        'account_locked' => 'bg-red-50 text-red-700 border-red-200',
                        'deleted'        => 'bg-red-50 text-red-700 border-red-200',
                        'created'        => 'bg-blue-50 text-blue-700 border-blue-200',
                        'verified'       => 'bg-[#f5eef9] text-[#7a3f91] border-[#d4aaeb]',
                        'updated'        => 'bg-orange-50 text-orange-700 border-orange-200',
                        default          => 'bg-gray-50 text-gray-700 border-gray-200',
                    };
                    $roleColor = match($log['user_role']) {
                        'admin'     => 'text-[#7a3f91] font-semibold',
                        'organizer' => 'text-blue-600 font-semibold',
                        'alumni'    => 'text-emerald-600 font-semibold',
                        default     => 'text-gray-400',
                    };
                @endphp
                <div class="flex items-start gap-3.5 px-5 py-3.5 hover:bg-[#FAFBFF] transition-colors">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5"
                         style="background:linear-gradient(135deg,#f5eef9,#e9d5f9);">
                        <i class="fas {{ $log['action_icon'] }} text-[#7A3F91] text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[.78rem] font-semibold text-[#333333]">{{ $log['user_name'] }}</span>
                            <span class="text-[.62rem] {{ $roleColor }} uppercase tracking-wide">{{ $log['user_role'] }}</span>
                            @if($log['is_flagged'])
                            <span class="text-[.6rem] font-semibold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                <i class="fas fa-flag text-[8px]"></i> Flagged
                            </span>
                            @endif
                        </div>
                        <p class="text-[.73rem] text-[#888] font-normal mt-0.5 leading-snug truncate">
                            {{ $log['description'] }}
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                        <span class="inline-flex items-center gap-1 text-[.62rem] font-semibold
                                     px-2 py-0.5 rounded-full border {{ $actionColor }}">
                            {{ $log['action_label'] }}
                        </span>
                        <span class="text-[.67rem] text-[#BBBBBB]">{{ $log['time'] }}</span>
                    </div>
                </div>
                @empty
                <div class="py-16 text-center">
                    <div class="w-12 h-12 rounded-2xl bg-[#f5eef9] flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-shield-halved text-[#d4aaeb] text-xl"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#999]">No activity yet</p>
                </div>
                @endforelse
            </div>
        </div>

        {{-- Alumni by Course --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden card-hover fade-up fade-up-7">
            <div class="px-5 py-4 border-b border-[#E8E0F0]"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-chart-pie text-white text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-[#333333] uppercase tracking-wide leading-none">Top Courses</p>
                        <p class="text-[.67rem] text-[#999] mt-0.5">Alumni by course</p>
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-3">
                @php
                    $maxAlumni = max(array_column($courseStats, 'alumni_count') ?: [1]);
                    $maxAlumni = $maxAlumni < 1 ? 1 : $maxAlumni;
                    $palette   = ['#7A3F91','#9b59b6','#c0a0d8','#2563eb','#059669','#d97706'];
                @endphp

                @forelse($courseStats as $idx => $cs)
                @php
                    $pct   = round(($cs['alumni_count'] / $maxAlumni) * 100);
                    $color = $palette[$idx % count($palette)];
                @endphp
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center gap-2 min-w-0">
                            <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 text-[.58rem] font-bold text-white"
                                 style="background:{{ $color }};">
                                {{ $idx + 1 }}
                            </div>
                            <span class="text-[.78rem] font-semibold text-[#333] font-mono uppercase truncate">
                                {{ $cs['code'] }}
                            </span>
                        </div>
                        <span class="text-[.72rem] font-bold text-[#555] ml-2 flex-shrink-0">
                            {{ $cs['alumni_count'] }}
                        </span>
                    </div>
                    <div class="w-full h-2 bg-[#F0E8F8] rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700"
                             style="width:{{ $pct }}%; background:{{ $color }};"></div>
                    </div>
                    <p class="text-[.62rem] text-[#BBBBBB] mt-0.5 truncate">{{ $cs['name'] }}</p>
                </div>
                @empty
                <div class="py-10 text-center">
                    <div class="w-12 h-12 rounded-2xl bg-[#f5eef9] flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-book text-[#d4aaeb] text-xl"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#999]">No courses yet</p>
                </div>
                @endforelse
            </div>

            @if(count($courseStats) > 0)
            <div class="px-5 py-3 border-t border-[#E8E0F0] bg-[#FAFBFF]">
                <p class="text-[.72rem] font-semibold text-[#7A3F91]">
                    Total: {{ $stats['totalCourses'] ?? 0 }} courses registered
                </p>
            </div>
            @endif
        </div>
    </div>

    {{-- ══════════════════════ ALUMNI STATUS BREAKDOWN ══════════════════════ --}}
    <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden card-hover fade-up fade-up-8">
        <div class="px-5 py-4 border-b border-[#E8E0F0]"
             style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-circle-half-stroke text-white text-xs"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-[#333333] uppercase tracking-wide leading-none">Alumni Status Overview</p>
                    <p class="text-[.67rem] text-[#999] mt-0.5">Verification breakdown</p>
                </div>
            </div>
        </div>

        @php
            $total     = max($stats['totalAlumni'] ?? 1, 1);
            $verified  = $stats['verified']  ?? 0;
            $pending   = $stats['pending']   ?? 0;
            $rejected  = $stats['rejected']  ?? 0;
            $vPct      = round(($verified  / $total) * 100);
            $pPct      = round(($pending   / $total) * 100);
            $rPct      = round(($rejected  / $total) * 100);

            $statuses  = [
                ['label' => 'Verified',  'count' => $verified, 'pct' => $vPct, 'bar' => '#059669', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                ['label' => 'Pending',   'count' => $pending,  'pct' => $pPct, 'bar' => '#d97706', 'badge' => 'bg-amber-50 text-amber-700 border-yellow-300'],
                ['label' => 'Rejected',  'count' => $rejected, 'pct' => $rPct, 'bar' => '#ef4444', 'badge' => 'bg-red-50 text-red-700 border-red-200'],
            ];
        @endphp

        <div class="px-6 py-6">
            <div class="w-full h-4 rounded-full overflow-hidden flex mb-5 gap-0.5" style="background:#F0E8F8;">
                @foreach($statuses as $s)
                @if($s['pct'] > 0)
                <div class="h-full transition-all duration-700 first:rounded-l-full last:rounded-r-full"
                     style="width:{{ $s['pct'] }}%; background:{{ $s['bar'] }};"
                     title="{{ $s['label'] }}: {{ $s['count'] }}"></div>
                @endif
                @endforeach
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                @foreach($statuses as $s)
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 font-bold text-white text-sm"
                         style="background:{{ $s['bar'] }};">
                        {{ $s['pct'] }}%
                    </div>
                    <div>
                        <div class="text-xl font-bold text-[#333]">{{ number_format($s['count']) }}</div>
                        <span class="inline-flex items-center gap-1 text-[.67rem] font-semibold
                                     px-2 py-0.5 rounded-full border {{ $s['badge'] }} mt-0.5">
                            <i class="fa-solid fa-circle text-[4px]"></i>
                            {{ $s['label'] }}
                        </span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         EMPLOYMENT ANALYTICS SECTION
    ══════════════════════════════════════════════════════════════════════ --}}

    {{-- Section Divider --}}
    <div class="flex items-center gap-4 py-2">
        <div class="flex-1 h-px bg-gradient-to-r from-transparent via-[#d4aaeb] to-transparent"></div>
        <div class="flex items-center gap-2 px-4 py-2 rounded-full border border-[#d4aaeb] bg-[#f5eef9]">
            <i class="fas fa-chart-column text-[#7a3f91] text-sm"></i>
            <span class="text-[.78rem] font-bold text-[#7a3f91] uppercase tracking-wider">Employment Analytics</span>
        </div>
        <div class="flex-1 h-px bg-gradient-to-r from-[#d4aaeb] via-[#d4aaeb] to-transparent"></div>
    </div>

    {{-- Employment Filter Bar --}}
    <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm px-5 py-4 flex flex-wrap items-center gap-3"
         wire:loading.class="wire-emp-loading"
         wire:target="updatedFilterBatch,updatedFilterCollege,updatedFilterCourse,clearEmpFilters">
        <i class="fas fa-sliders text-sm text-[#7a3f91]"></i>
        <span class="text-[.75rem] font-bold uppercase tracking-wider text-[#888]">Filter Employment:</span>

        <select wire:model.live="filterBatch" class="emp-f-select">
            <option value="">All Batches</option>
            @foreach($empBatches as $b)
                <option value="{{ $b }}">Batch {{ $b }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterCollege" class="emp-f-select">
            <option value="">All Colleges</option>
            @foreach($empColleges as $c)
                <option value="{{ $c }}">{{ $c }}</option>
            @endforeach
        </select>

        @if($filterCollege)
        <select wire:model.live="filterCourse" class="emp-f-select">
            <option value="">All Courses in College</option>
            @foreach($empCourses as $c)
                @if($c['college'] === $filterCollege)
                    <option value="{{ $c['code'] }}">{{ $c['code'] }}</option>
                @endif
            @endforeach
        </select>
        @else
        <select wire:model.live="filterCourse" class="emp-f-select">
            <option value="">All Courses</option>
            @foreach($empCourses as $c)
                <option value="{{ $c['code'] }}">{{ $c['code'] }}</option>
            @endforeach
        </select>
        @endif

        @if($filterBatch || $filterCollege || $filterCourse)
            <button wire:click="clearEmpFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-semibold border border-red-200 bg-red-50 text-red-600 transition">
                <i class="fas fa-rotate-left text-xs"></i> Reset
            </button>
            <span class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-[#f5eef9] text-[#7a3f91]">
                <i class="fas fa-filter text-xs mr-1"></i> Filtered
            </span>
        @endif

        <div class="ml-auto" wire:loading wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">
            <i class="fas fa-circle-notch fa-spin text-sm text-[#7a3f91]"></i>
        </div>
    </div>

    {{-- Employment Stat Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3"
         wire:loading.class="wire-emp-loading"
         wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">

        @php
        $empRate  = ($totalFilled > 0) ? round(($totalEmployed + $totalSelf) / $totalFilled * 100) : 0;
        $empCards = [
            ['cls'=>'c-purple','bg'=>'#f5eef9','ic'=>'#7a3f91', 'icon'=>'fa-users',          'num'=>$totalAlumni,     'lbl'=>'Total Alumni',    'sub'=>($totalAlumni>0?round($totalFilled/$totalAlumni*100).'% have records':'—')],
            ['cls'=>'c-green', 'bg'=>'#d1fae5','ic'=>'#059669', 'icon'=>'fa-briefcase',       'num'=>$totalEmployed,   'lbl'=>'Employed',        'sub'=>($totalAlumni>0?round($totalEmployed/$totalAlumni*100).'% of total':'—')],
            ['cls'=>'c-blue',  'bg'=>'#dbeafe','ic'=>'#2563eb', 'icon'=>'fa-store',           'num'=>$totalSelf,       'lbl'=>'Self-Employed',   'sub'=>($totalAlumni>0?round($totalSelf/$totalAlumni*100).'% of total':'—')],
            ['cls'=>'c-amber', 'bg'=>'#fef3c7','ic'=>'#d97706', 'icon'=>'fa-circle-pause',    'num'=>$totalUnemployed, 'lbl'=>'Unemployed',      'sub'=>($totalAlumni>0?round($totalUnemployed/$totalAlumni*100).'% of total':'—')],
            ['cls'=>'c-gray',  'bg'=>'#f3f4f6','ic'=>'#9ca3af', 'icon'=>'fa-circle-question', 'num'=>$totalNotFilled,  'lbl'=>'Not Filled',      'sub'=>'no record'],
            ['cls'=>'c-teal',  'bg'=>'#ccfbf1','ic'=>'#0d9488', 'icon'=>'fa-house',           'num'=>$totalLocal,      'lbl'=>'Local Workers',   'sub'=>(($totalLocal+$totalAbroad)>0?round($totalLocal/max($totalLocal+$totalAbroad,1)*100).'% employed':'—')],
            ['cls'=>'c-orange','bg'=>'#ffedd5','ic'=>'#ea580c', 'icon'=>'fa-plane-departure',  'num'=>$totalAbroad,     'lbl'=>'Abroad / OFW',    'sub'=>(($totalLocal+$totalAbroad)>0?round($totalAbroad/max($totalLocal+$totalAbroad,1)*100).'% employed':'—')],
            ['cls'=>'c-rose',  'bg'=>'#ffe4e6','ic'=>'#e11d48', 'icon'=>'fa-chart-pie',        'num'=>$empRate.'%',     'lbl'=>'Emp. Rate',       'sub'=>'of those w/ records'],
        ];
        @endphp

        @foreach($empCards as $ec)
        <div class="emp-stat-card {{ $ec['cls'] }}">
            <div class="w-10 h-10 rounded-[10px] flex items-center justify-center mb-3"
                 style="background:{{ $ec['bg'] }};">
                <i class="fas {{ $ec['icon'] }} text-[15px]" style="color:{{ $ec['ic'] }};"></i>
            </div>
            <p class="text-[1.5rem] font-bold leading-none text-[#333] tracking-tight">{{ $ec['num'] }}</p>
            <p class="text-[.68rem] font-semibold uppercase tracking-[.07em] text-[#888] mt-1.5">{{ $ec['lbl'] }}</p>
            <p class="text-[.68rem] text-[#AAAAAA] mt-[3px]">{{ $ec['sub'] }}</p>
        </div>
        @endforeach
    </div>

    {{-- Row 1: Status / Location / Relevance / Unemployed Why --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"
         wire:loading.class="wire-emp-loading"
         wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot"></div>
                    <span class="emp-chart-ttl">Employment Status</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartStatus"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#e879f9;"></div>
                    <span class="emp-chart-ttl">Work Location</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartLocation"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#10b981;"></div>
                    <span class="emp-chart-ttl">Job-Course Relevance</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartRelevance"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#f59e0b;"></div>
                    <span class="emp-chart-ttl">Unemployed — Why?</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartUnemployed"></canvas>
            </div>
        </div>

    </div>

    {{-- Row 2: Emp Type / Career Path / Education / Top Courses --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"
         wire:loading.class="wire-emp-loading"
         wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#a855f7;"></div>
                    <span class="emp-chart-ttl">Employment Type</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartEmpType"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#14b8a6;"></div>
                    <span class="emp-chart-ttl">Career Path Labels</span>
                </div>
            </div>
            <div class="emp-chart-body" style="height:210px;" wire:ignore>
                <canvas id="dash_chartCareerPath"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#3b82f6;"></div>
                    <span class="emp-chart-ttl">Further Education</span>
                </div>
            </div>
            <div class="emp-chart-body flex items-center justify-center" style="height:210px;" wire:ignore>
                <canvas id="dash_chartEduStatus"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot"></div>
                    <span class="emp-chart-ttl">Top Courses (Employed)</span>
                </div>
            </div>
            <div class="emp-chart-body" style="height:210px;" wire:ignore>
                <canvas id="dash_chartCourse"></canvas>
            </div>
        </div>

    </div>

    {{-- Row 3: By Batch + By College --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4"
         wire:loading.class="wire-emp-loading"
         wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">

        <div class="emp-chart-card">
            <div class="emp-chart-head" style="justify-content:space-between;">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#f59e0b;"></div>
                    <div>
                        <div class="emp-chart-ttl">Employment by Batch Year</div>
                        <div class="text-[.68rem] text-[#999] font-medium mt-0.5">Stacked across all years</div>
                    </div>
                </div>
                <div id="dash_batchNavControls" class="flex items-center gap-1.5" style="display:none!important;">
                    <button id="dash_batchPrev"
                            class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30"
                            style="color:#7a3f91;">
                        <i class="fa-solid fa-chevron-left" style="font-size:.6rem;"></i>
                    </button>
                    <span id="dash_batchPageInfo" class="text-xs font-semibold text-[#999] whitespace-nowrap min-w-[36px] text-center"></span>
                    <button id="dash_batchNext"
                            class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30"
                            style="color:#7a3f91;">
                        <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
                    </button>
                </div>
            </div>
            <div class="emp-chart-body" style="height:260px;" wire:ignore>
                <canvas id="dash_chartBatch"></canvas>
            </div>
        </div>

        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#06b6d4;"></div>
                    <div>
                        <div class="emp-chart-ttl">Employment by College</div>
                        <div class="text-[.68rem] text-[#999] font-medium mt-0.5">Across all departments</div>
                    </div>
                </div>
            </div>
            <div class="emp-chart-body" style="height:260px;" wire:ignore>
                <canvas id="dash_chartCollege"></canvas>
            </div>
        </div>

    </div>

    {{-- Employment Insights: Top Colleges / Courses / Batches --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"
         wire:loading.class="wire-emp-loading"
         wire:target="filterBatch,filterCollege,filterCourse,clearEmpFilters">

        @php
            $allCollegesInsight = collect($empColleges)->map(function($col) {
                $codes    = DB::table('courses')->where('college',$col)->pluck('code');
                $total    = DB::table('alumni')->whereNull('deleted_at')->whereIn('course_code',$codes)->count();
                $employed = DB::table('alumni as a')
                    ->join('employment_trackings as et','a.id','=','et.alumni_id')
                    ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                    ->whereIn('a.course_code',$codes)
                    ->whereIn('et.employment_status',['employed','self_employed'])->count();
                return ['name'=>$col,'total'=>$total,'employed'=>$employed,'rate'=>$total>0?round($employed/$total*100):0];
            })->sortByDesc('rate')->values();

            $topCoursesInsight = DB::table('alumni as a')
                ->join('employment_trackings as et','a.id','=','et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('et.employment_status',['employed','self_employed'])
                ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
                ->groupBy('a.course_code')->orderByDesc('cnt')->limit(5)->get();

            $topBatchesInsight = DB::table('alumni as a')
                ->join('employment_trackings as et','a.id','=','et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('et.employment_status',['employed','self_employed'])
                ->select('a.batch', DB::raw('COUNT(*) as cnt'))
                ->groupBy('a.batch')->orderByDesc('cnt')->limit(5)->get();
        @endphp

        {{-- Colleges --}}
        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot"></div>
                    <div>
                        <div class="emp-chart-ttl">Colleges — Emp. Rate</div>
                        <div class="text-[.68rem] text-[#999] font-medium mt-0.5">Highest first</div>
                    </div>
                </div>
                <i class="fas fa-trophy text-sm text-amber-400"></i>
            </div>
            <div class="emp-chart-body space-y-0">
                @forelse($allCollegesInsight->take(6) as $i => $col)
                @php
                    $medals = ['🥇','🥈','🥉'];
                    $medal  = $medals[$i] ?? null;
                    $pct    = $col['rate'];
                    $fillColor = $pct >= 70 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
                @endphp
                <div class="emp-rank-row">
                    <div class="emp-rank-num"
                         style="background:{{ $i===0?'#fef3c7':($i===1?'#f3f4f6':($i===2?'#fde8d8':'#f9fafb')) }};
                                color:{{ $i===0?'#b45309':($i===1?'#6b7280':'#c2410c') }};">
                        {{ $medal ?? ($i+1) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold truncate text-[#333]">{{ $col['name'] }}</p>
                        <div class="emp-prog">
                            <div class="emp-prog-fill" style="width:{{ $pct }}%;background:{{ $fillColor }};"></div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2" style="color:{{ $fillColor }};">{{ $pct }}%</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#999]">No data available.</p>
                @endforelse
            </div>
        </div>

        {{-- Top Courses --}}
        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#3b82f6;"></div>
                    <div>
                        <div class="emp-chart-ttl">Top Courses (Employed)</div>
                        <div class="text-[.68rem] text-[#999] font-medium mt-0.5">Most alumni employed</div>
                    </div>
                </div>
                <i class="fas fa-graduation-cap text-sm text-blue-500"></i>
            </div>
            <div class="emp-chart-body space-y-0">
                @php $maxCourseI = $topCoursesInsight->max('cnt') ?: 1; @endphp
                @forelse($topCoursesInsight as $i => $c)
                <div class="emp-rank-row">
                    <div class="emp-rank-num bg-[#f5eef9] text-[#7a3f91]">{{ $i+1 }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333]">{{ $c->course_code }}</p>
                        <div class="emp-prog">
                            <div class="emp-prog-fill" style="width:{{ round($c->cnt/$maxCourseI*100) }}%;background:#7a3f91;"></div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2 text-[#7a3f91]">{{ $c->cnt }}</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#999]">No data available.</p>
                @endforelse
            </div>
        </div>

        {{-- Top Batches --}}
        <div class="emp-chart-card">
            <div class="emp-chart-head">
                <div class="flex items-center gap-2">
                    <div class="emp-chart-dot" style="background:#f59e0b;"></div>
                    <div>
                        <div class="emp-chart-ttl">Top Batches (Employed)</div>
                        <div class="text-[.68rem] text-[#999] font-medium mt-0.5">Most alumni working</div>
                    </div>
                </div>
                <i class="fas fa-calendar-check text-sm text-amber-400"></i>
            </div>
            <div class="emp-chart-body space-y-0">
                @php $maxBatchI = $topBatchesInsight->max('cnt') ?: 1; @endphp
                @forelse($topBatchesInsight as $i => $b)
                <div class="emp-rank-row">
                    <div class="emp-rank-num bg-amber-50 text-amber-700">{{ $i+1 }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333]">Batch {{ $b->batch }}</p>
                        <div class="emp-prog">
                            <div class="emp-prog-fill" style="width:{{ round($b->cnt/$maxBatchI*100) }}%;background:#f59e0b;"></div>
                        </div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2 text-amber-600">{{ $b->cnt }}</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#999]">No data available.</p>
                @endforelse
            </div>
        </div>

    </div>

    {{-- ══════════════════════ FOOTER ══════════════════════ --}}
    <div class="rounded-2xl px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#2b0d3e;">
        <p class="text-white/50 text-xs">
            <i class="fas fa-shield-halved text-[#9b59b6] mr-1.5"></i>
            Admin Dashboard &nbsp;·&nbsp; All times displayed in <strong class="text-white/70">Asia/Manila (PHT)</strong>
        </p>
        <p class="text-white/40 text-xs">
            Last refreshed: {{ now()->setTimezone('Asia/Manila')->format('g:i:s A') }}
        </p>
    </div>

</div>
</div>

{{-- ══ EMPLOYMENT CHART DATA BRIDGE ══════════════════════════════════════════ --}}
<div id="__dash_emp_data" style="display:none"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-college="{{ $chartCollegeData }}"
     data-course="{{ $chartCourseData }}"
     data-emptype="{{ $chartEmpTypeData }}"
     data-career="{{ $chartCareerPathData }}"
     data-edu="{{ $chartEduStatusData }}"
     data-unemployed="{{ $chartUnemployedData }}">
</div>

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
        var el = document.getElementById('__dash_emp_data');
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

    function donut(id, data){
        if(!data || !data.labels || allZero(data.data)){ kill(id); return; }
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2, borderColor: '#fff', hoverOffset: 8
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '64%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        onClick: function(){},
                        labels: { font: { size: 10, weight: '600' }, color: '#333', padding: 8, usePointStyle: true, pointStyleWidth: 7 }
                    },
                    tooltip: { callbacks: { label: function(ctx){
                        var t = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                        var p = t ? Math.round(ctx.parsed / t * 100) : 0;
                        return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + p + '%)';
                    }}}
                }
            }
        });
    }

    function hbar(id, data){
        if(!data || !data.labels) return;
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{ label: 'Alumni', data: data.data, backgroundColor: 'rgba(122,63,145,.75)', borderColor: '#7a3f91', borderWidth: 1, borderRadius: 4 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx){ return ' ' + ctx.parsed.x + ' alumni'; }}}
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#333' }}
                }
            }
        });
    }

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
                    borderColor: data.colors, borderWidth: 1.5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom', onClick: function(){},
                        labels: { font: { size: 10, weight: '600' }, color: '#333', padding: 7, usePointStyle: true, pointStyleWidth: 7 }
                    },
                    tooltip: { callbacks: { label: function(ctx){ return ' ' + ctx.label + ': ' + ctx.parsed.r; }}}
                },
                scales: { r: { ticks: { display: false }, grid: { color: '#f3f4f6' }}}
            }
        });
    }

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
                    legend: { position: 'top', align: 'end', onClick: function(){},
                        labels: { font: { size: 10, weight: '600' }, color: '#333', padding: 10, usePointStyle: true }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#666', maxRotation: 35 }},
                    y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true }
                }
            }
        });
    }

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
        var fullLabels = labels;
        var shortLabels = labels.map(function(l){
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
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 350 },
                plugins: {
                    legend: { position: 'top', align: 'end', onClick: function(){},
                        labels: { font: { size: 10, weight: '600' }, color: '#333', padding: 10, usePointStyle: true }
                    },
                    tooltip: { callbacks: {
                        title: function(items){ var idx = items[0].dataIndex; return fullLabels[idx] || shortLabels[idx]; },
                        label: function(ctx){ return ' ' + ctx.dataset.label + ': ' + ctx.parsed.x; }
                    }}
                },
                scales: {
                    x: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { stacked: true, grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#333', maxRotation: 0, minRotation: 0 }}
                }
            }
        });
    }

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
        stackedBar('dash_chartBatch', sl.labels, sl.employed, sl.self_emp, sl.unemployed);
        var total = data.labels.length, pages = Math.ceil(total / BATCH_PAGE), cur = Math.floor(start / BATCH_PAGE) + 1;
        var nav  = document.getElementById('dash_batchNavControls');
        var prev = document.getElementById('dash_batchPrev');
        var next = document.getElementById('dash_batchNext');
        var info = document.getElementById('dash_batchPageInfo');
        if(nav && pages > 1){
            nav.style.display = 'flex';
            if(info) info.textContent = cur + ' / ' + pages;
            if(prev) prev.disabled = (start <= 0);
            if(next) next.disabled = (start + BATCH_PAGE >= total);
        } else if(nav) { nav.style.display = 'none'; }
    }

    function bindBatchNav(){
        var prev = document.getElementById('dash_batchPrev');
        var next = document.getElementById('dash_batchNext');
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

    function initAll(){
        var d = bridge(); if(!d) return;

        donut('dash_chartStatus',    d.status);
        donut('dash_chartLocation',  d.location);
        donut('dash_chartRelevance', d.relevance);
        donut('dash_chartUnemployed',d.unemployed);
        donut('dash_chartEmpType',   d.emptype);
        donut('dash_chartEduStatus', d.edu);
        hbar( 'dash_chartCourse',    d.course);
        polar('dash_chartCareerPath',d.career);

        if(d.college && d.college.labels){
            stackedBarH('dash_chartCollege', d.college.labels, d.college.employed, d.college.self_emp, d.college.unemployed);
        }

        if(d.batch && d.batch.labels){
            var changed = !batchAll || JSON.stringify(d.batch.labels) !== JSON.stringify(batchAll.labels);
            if(changed){
                batchAll = d.batch;
                batchIdx = Math.max(0, batchAll.labels.length - BATCH_PAGE);
                kill('dash_chartBatch');
            }
            drawBatch(batchAll, batchIdx);
        }
        bindBatchNav();
    }

    loadChartJs(function(){
        if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }

        document.addEventListener('livewire:navigated', function(){
            kill('dash_chartBatch'); kill('dash_chartCollege');
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
{{-- ✅ END single root wrapper --}}