{{-- resources/views/livewire/registrar/employment-tracking.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {

    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterLocation  = '';
    public string $filterRelevance = '';
    public string $filterBatch     = '';
    public string $filterCourse    = '';
    public string $filterDept      = '';
    public string $sortBy          = 'a.last_name';
    public string $sortDir         = 'asc';

    // ── Modal ─────────────────────────────────────────────────────────────────
    public bool  $showModal = false;
    public array $modalData = [];

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int $totalAlumni     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalAbroad     = 0;
    public int $totalLocal      = 0;

    // ── Chart Data (JSON strings) ─────────────────────────────────────────────
    public string $chartStatusData    = '{}';
    public string $chartLocationData  = '{}';
    public string $chartRelevanceData = '{}';
    public string $chartBatchData     = '{}';
    public string $chartCourseData    = '{}';

    protected $queryString = [
        'search'          => ['except' => ''],
        'filterStatus'    => ['except' => ''],
        'filterLocation'  => ['except' => ''],
        'filterRelevance' => ['except' => ''],
        'filterBatch'     => ['except' => ''],
        'filterCourse'    => ['except' => ''],
        'filterDept'      => ['except' => ''],
        'sortBy'          => ['except' => 'a.last_name'],
        'sortDir'         => ['except' => 'asc'],
    ];

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'registrar') {
            $this->redirect(route('login'));
            return;
        }
        $this->computeStats();
        $this->buildCharts();
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('alumni as a')->whereNull('a.deleted_at');
    }

    public function computeStats(): void
    {
        $base = $this->baseQuery();
        $this->totalAlumni = (clone $base)->count();

        $withEmp = (clone $base)
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        $this->totalEmployed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalEmployed - $this->totalSelf - $this->totalUnemployed);
        $this->totalAbroad     = (clone $withEmp)->where('et.work_location', 'abroad')->count();
        $this->totalLocal      = (clone $withEmp)->where('et.work_location', 'local')->count();
    }

    public function buildCharts(): void
    {
        $this->chartStatusData = json_encode([
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors' => ['#10b981','#3b82f6','#f59e0b','#d1d5db'],
        ]);

        $this->chartLocationData = json_encode([
            'labels' => ['Local', 'Abroad (OFW)'],
            'data'   => [$this->totalLocal, $this->totalAbroad],
            'colors' => ['#7a3f91','#c084fc'],
        ]);

        $relevanceRows = DB::table('employment_trackings as et')
            ->whereNull('et.deleted_at')
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $this->chartRelevanceData = json_encode([
            'labels' => ['Yes', 'Partially', 'No'],
            'data'   => [
                $relevanceRows->get('yes')->cnt ?? 0,
                $relevanceRows->get('partially')->cnt ?? 0,
                $relevanceRows->get('no')->cnt ?? 0,
            ],
            'colors' => ['#10b981','#f59e0b','#ef4444'],
        ]);

        $batchRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->select(
                'a.batch',
                DB::raw("SUM(CASE WHEN et.employment_status = 'employed' THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'self_employed' THEN 1 ELSE 0 END) as self_emp"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'unemployed' THEN 1 ELSE 0 END) as unemployed"),
                DB::raw('COUNT(a.id) as total')
            )
            ->groupBy('a.batch')
            ->orderBy('a.batch', 'asc')
            ->get();

        $this->chartBatchData = json_encode([
            'labels'     => $batchRows->pluck('batch')->values(),
            'employed'   => $batchRows->pluck('employed')->values(),
            'self_emp'   => $batchRows->pluck('self_emp')->values(),
            'unemployed' => $batchRows->pluck('unemployed')->values(),
        ]);

        $courseRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->join('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereIn('et.employment_status', ['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        $this->chartCourseData = json_encode([
            'labels' => $courseRows->pluck('course_code'),
            'data'   => $courseRows->pluck('cnt'),
        ]);
    }

    public function with(): array
    {
        return [];
    }

    public function closeModal(): void { $this->showModal = false; $this->modalData = []; }
}; ?>

<div>

<style>
    :root {
        --primary:    #7a3f91;
        --primary-dk: #5e2f72;
        --primary-lt: #F3E8FF;
        --ink:        #333333;
        --muted:      #666666;
        --border:     #E8E0F0;
        --surface:    #ffffff;
        --bg:         #f3f4f6;
        --subtle-bg:  #F5F5F5;
    }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 4px rgba(0,0,0,.04);
        transition: transform .15s, box-shadow .15s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(122,63,145,.10);
    }
    .stat-icon {
        width: 46px; height: 46px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .stat-number {
        font-size: 1.6rem;
        font-weight: 900;
        line-height: 1;
        color: var(--ink);
    }
    .stat-label {
        font-size: .85rem;
        font-weight: 600;
        color: var(--muted);
        margin-top: 2px;
    }

    .chart-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: 0 1px 4px rgba(0,0,0,.04);
        overflow: hidden;
    }
    .chart-header {
        padding: 10px 16px;
        border-bottom: 1px solid var(--border);
        background: var(--subtle-bg);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .chart-dot {
        width: 9px; height: 9px;
        border-radius: 50%;
        background: var(--primary);
        flex-shrink: 0;
    }
    .chart-title {
        font-size: .85rem;
        font-weight: 800;
        color: var(--ink);
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .chart-body { padding: 14px; }

    .progress-bar  { height: 6px; border-radius: 999px; background: #e9d5ff; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 999px; background: var(--primary); transition: width .6s cubic-bezier(.4,0,.2,1); }

    .batch-nav-btn {
        width: 30px; height: 30px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: #fff;
        color: var(--primary);
        font-size: .8rem;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background .15s, border-color .15s;
        flex-shrink: 0;
    }
    .batch-nav-btn:hover:not(:disabled) { background: var(--primary-lt); border-color: var(--primary); }
    .batch-nav-btn:disabled { opacity: .35; cursor: not-allowed; }
    .batch-page-info { font-size: .78rem; font-weight: 700; color: var(--muted); white-space: nowrap; }
</style>

{{-- Chart data bridge --}}
<div id="__emp_chart_data"
     style="display:none"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-course="{{ $chartCourseData }}">
</div>

<div class="min-h-screen" style="background:var(--bg);">
<div class="px-4 sm:px-6 lg:px-10 pt-6 pb-10 max-w-screen-2xl mx-auto">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-7">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0"
                 style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);box-shadow:0 6px 20px rgba(122,63,145,.30);">
                <i class="fas fa-chart-column text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-semibold leading-tight" style="color:var(--ink);">
                    Employment Tracking
                </h1>
                <p class="text-base mt-0.5 font-normal" style="color:var(--muted);">
                    System-wide alumni employment data &amp; analytics
                </p>
            </div>
        </div>
    </div>

    {{-- ══ STAT CARDS ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3 mb-7">

        <div class="stat-card lg:col-span-1">
            <div class="stat-icon" style="background:#ede9fe;">
                <i class="fa-solid fa-users" style="color:var(--primary);font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalAlumni }}</p>
                <p class="stat-label">Total Alumni</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-briefcase" style="color:#059669;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalEmployed }}</p>
                <p class="stat-label">Employed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:60px;">
                        <div class="progress-fill" style="width:{{ round($totalEmployed/$totalAlumni*100) }}%;background:#10b981;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;">
                <i class="fa-solid fa-store" style="color:#2563eb;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalSelf }}</p>
                <p class="stat-label">Self-Employed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:60px;">
                        <div class="progress-fill" style="width:{{ round($totalSelf/$totalAlumni*100) }}%;background:#3b82f6;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;">
                <i class="fa-solid fa-circle-pause" style="color:#d97706;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalUnemployed }}</p>
                <p class="stat-label">Unemployed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:60px;">
                        <div class="progress-fill" style="width:{{ round($totalUnemployed/$totalAlumni*100) }}%;background:#f59e0b;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#f3f4f6;">
                <i class="fa-solid fa-circle-question" style="color:#9ca3af;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalNotFilled }}</p>
                <p class="stat-label">Not Filled</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#fef9c3;">
                <i class="fa-solid fa-plane-departure" style="color:#b45309;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalAbroad }}</p>
                <p class="stat-label">OFW / Abroad</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-house" style="color:#059669;font-size:1.05rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalLocal }}</p>
                <p class="stat-label">Local</p>
            </div>
        </div>

    </div>

    {{-- ══ CHARTS ROW ══ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot"></div>
                <span class="chart-title">Status Breakdown</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartStatus"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#c084fc;"></div>
                <span class="chart-title">Work Location</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartLocation"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#10b981;"></div>
                <span class="chart-title">Job-Course Relevance</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartRelevance"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#3b82f6;"></div>
                <span class="chart-title">Top Courses (Employed)</span>
            </div>
            <div class="chart-body" style="height:220px;" wire:ignore>
                <canvas id="chartCourse"></canvas>
            </div>
        </div>

        {{-- Batch chart with pagination --}}
        <div class="chart-card md:col-span-2 xl:col-span-4">
            <div class="chart-header" style="justify-content:space-between;">
                <div class="flex items-center gap-2">
                    <div class="chart-dot" style="background:#f59e0b;"></div>
                    <span class="chart-title">Employment by Batch Year</span>
                </div>
                <div id="batchNavControls" class="flex items-center gap-2" style="display:none!important;">
                    <button id="batchPrev" class="batch-nav-btn" title="Previous batches">
                        <i class="fa-solid fa-chevron-left" style="font-size:.65rem;"></i>
                    </button>
                    <span id="batchPageInfo" class="batch-page-info"></span>
                    <button id="batchNext" class="batch-nav-btn" title="Next batches">
                        <i class="fa-solid fa-chevron-right" style="font-size:.65rem;"></i>
                    </button>
                </div>
            </div>
            <div class="chart-body" style="height:260px;" wire:ignore>
                <canvas id="chartBatch"></canvas>
            </div>
        </div>

    </div>

</div>{{-- end page container --}}
</div>{{-- end min-h-screen --}}

{{-- ══ CHARTS + BATCH NAV SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    var BATCH_PAGE_SIZE = 8;
    var batchPageIndex  = 0;
    var allBatchData    = null;

    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function readData() {
        var el = document.getElementById('__emp_chart_data');
        if (!el) return null;
        try {
            return {
                status:    JSON.parse(el.getAttribute('data-status')    || 'null'),
                location:  JSON.parse(el.getAttribute('data-location')  || 'null'),
                relevance: JSON.parse(el.getAttribute('data-relevance') || 'null'),
                batch:     JSON.parse(el.getAttribute('data-batch')     || 'null'),
                course:    JSON.parse(el.getAttribute('data-course')    || 'null'),
            };
        } catch (e) { return null; }
    }

    var registry = {};
    function destroyChart(id) {
        if (registry[id]) { registry[id].destroy(); delete registry[id]; }
    }

    function buildDonut(id, data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        destroyChart(id);
        registry[id] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 7,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12, weight: '700' }, color: '#333333', padding: 12, usePointStyle: true, pointStyleWidth: 9 },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    }

    function sliceBatch(data, startIdx) {
        var end = startIdx + BATCH_PAGE_SIZE;
        return {
            labels:     data.labels.slice(startIdx, end),
            employed:   data.employed.slice(startIdx, end),
            self_emp:   data.self_emp.slice(startIdx, end),
            unemployed: data.unemployed.slice(startIdx, end),
        };
    }

    function buildBatchBar(data, startIdx) {
        if (!data || !data.labels || !data.labels.length) return;

        var slice  = sliceBatch(data, startIdx);
        var canvas = document.getElementById('chartBatch');
        if (!canvas) return;

        if (registry['chartBatch']) {
            var ch = registry['chartBatch'];
            ch.data.labels            = slice.labels;
            ch.data.datasets[0].data  = slice.employed;
            ch.data.datasets[1].data  = slice.self_emp;
            ch.data.datasets[2].data  = slice.unemployed;
            ch.update('active');
        } else {
            destroyChart('chartBatch');
            registry['chartBatch'] = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: slice.labels,
                    datasets: [
                        { label: 'Employed',      data: slice.employed,   backgroundColor: '#10b981', borderRadius: 4, stack: 'a' },
                        { label: 'Self-Employed', data: slice.self_emp,   backgroundColor: '#3b82f6', borderRadius: 4, stack: 'a' },
                        { label: 'Unemployed',    data: slice.unemployed, backgroundColor: '#f59e0b', borderRadius: 4, stack: 'a' },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 350 },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: { font: { size: 12, weight: '700' }, color: '#333333', padding: 14, usePointStyle: true },
                        },
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 12, weight: '600' }, color: '#666666' } },
                        y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    },
                },
            });
        }

        var totalBatches = data.labels.length;
        var totalPages   = Math.ceil(totalBatches / BATCH_PAGE_SIZE);
        var currentPage  = Math.floor(startIdx / BATCH_PAGE_SIZE) + 1;

        var navEl    = document.getElementById('batchNavControls');
        var prevBtn  = document.getElementById('batchPrev');
        var nextBtn  = document.getElementById('batchNext');
        var infoEl   = document.getElementById('batchPageInfo');

        if (navEl && totalPages > 1) {
            navEl.style.display = 'flex';
            infoEl.textContent  = currentPage + ' / ' + totalPages;
            prevBtn.disabled    = (startIdx <= 0);
            nextBtn.disabled    = (startIdx + BATCH_PAGE_SIZE >= totalBatches);
        } else if (navEl) {
            navEl.style.display = 'none';
        }
    }

    function buildCourseBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        destroyChart('chartCourse');
        registry['chartCourse'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Employed Alumni',
                    data: data.data,
                    backgroundColor: '#7a3f91cc',
                    borderColor: '#7a3f91',
                    borderWidth: 1,
                    borderRadius: 5,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.parsed.x + ' alumni'; } } },
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 12, weight: '700' }, color: '#333333' } },
                },
            },
        });
    }

    function bindBatchNav() {
        var prevBtn = document.getElementById('batchPrev');
        var nextBtn = document.getElementById('batchNext');
        if (!prevBtn || !nextBtn) return;

        var newPrev = prevBtn.cloneNode(true);
        var newNext = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrev, prevBtn);
        nextBtn.parentNode.replaceChild(newNext, nextBtn);

        newPrev.addEventListener('click', function () {
            if (!allBatchData) return;
            batchPageIndex = Math.max(0, batchPageIndex - BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
        });
        newNext.addEventListener('click', function () {
            if (!allBatchData) return;
            var max = allBatchData.labels.length - BATCH_PAGE_SIZE;
            batchPageIndex = Math.min(max, batchPageIndex + BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
        });
    }

    function initAllCharts() {
        var d = readData();
        if (!d) return;

        if (d.batch && d.batch.labels) {
            var changed = !allBatchData || JSON.stringify(d.batch.labels) !== JSON.stringify(allBatchData.labels);
            if (changed) {
                allBatchData   = d.batch;
                var total      = allBatchData.labels.length;
                batchPageIndex = Math.max(0, total - BATCH_PAGE_SIZE);
                destroyChart('chartBatch');
            }
        }

        buildDonut('chartStatus',    d.status);
        buildDonut('chartLocation',  d.location);
        buildDonut('chartRelevance', d.relevance);
        buildBatchBar(allBatchData, batchPageIndex);
        buildCourseBar(d.course);
        bindBatchNav();
    }

    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { requestAnimationFrame(initAllCharts); });
        } else {
            requestAnimationFrame(initAllCharts);
        }

        document.addEventListener('livewire:navigated', function () {
            destroyChart('chartBatch');
            requestAnimationFrame(initAllCharts);
        });

        if (window.Livewire) {
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || (payload.component && payload.respond);
                if (typeof succeed === 'function') {
                    succeed(function () { requestAnimationFrame(initAllCharts); });
                } else {
                    requestAnimationFrame(initAllCharts);
                }
            });
        } else {
            document.addEventListener('livewire:initialized', function () {
                Livewire.hook('commit', function (payload) {
                    var succeed = payload.succeed || function (cb) { cb({}); };
                    succeed(function () { requestAnimationFrame(initAllCharts); });
                });
            });
        }
    });

})();
</script>

</div>{{-- ═══ END SINGLE ROOT ELEMENT ═══ --}}