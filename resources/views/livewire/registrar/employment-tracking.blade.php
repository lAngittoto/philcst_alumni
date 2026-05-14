<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;

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
    public bool   $showModal            = false;
    public string $activeModal          = '';
    public string $modalFilter          = '';
    public ?int   $modalBatch           = null;
    public bool   $modalBatchLocked     = false;
    public string $modalCourse          = '';
    public bool   $modalCourseLocked    = false;
    public int    $modalPage            = 1;
    public int    $modalPageSize        = 200;
    public string $modalSearch          = '';

    /**
     * Multi-select relevance set.
     * When relevance_all  → ['yes','partially','no'].
     * When specific segment clicked → single value e.g. ['yes'].
     */
    public array $modalRelevanceActive = ['yes', 'partially', 'no'];

    /**
     * FIX 3: true when modal was opened by clicking a specific relevance segment
     * (not the card header). Locks the relevance chip so user can't toggle others.
     */
    public bool $modalRelevanceLocked  = false;

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int $totalAlumni     = 0;
    public int $totalSubmitted  = 0;
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
        $this->totalSubmitted  = $this->totalEmployed + $this->totalSelf + $this->totalUnemployed;
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalSubmitted);
        $this->totalAbroad     = (clone $withEmp)->where('et.work_location', 'abroad')->count();
        $this->totalLocal      = (clone $withEmp)->where('et.work_location', 'local')->count();
    }

    public function buildCharts(): void
    {
        $this->chartStatusData = json_encode([
            'labels'  => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'    => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors'  => ['#10b981','#3b82f6','#f59e0b','#d1d5db'],
            'filters' => ['employed', 'self_employed', 'unemployed', 'no_record'],
        ]);

        $this->chartLocationData = json_encode([
            'labels'  => ['Local', 'Abroad (OFW)'],
            'data'    => [$this->totalLocal, $this->totalAbroad],
            'colors'  => ['#7a3f91','#c084fc'],
            'filters' => ['local', 'abroad'],
        ]);

        $relevanceRows = DB::table('employment_trackings as et')
            ->whereNull('et.deleted_at')
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $this->chartRelevanceData = json_encode([
            'labels'  => ['Yes', 'Partially', 'No'],
            'data'    => [
                $relevanceRows->get('yes')->cnt    ?? 0,
                $relevanceRows->get('partially')->cnt ?? 0,
                $relevanceRows->get('no')->cnt     ?? 0,
            ],
            'colors'  => ['#10b981','#f59e0b','#ef4444'],
            'filters' => ['relevance_yes', 'relevance_partially', 'relevance_no'],
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
            'total'      => $batchRows->pluck('total')->values(),
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

    // ── Available filter options ──────────────────────────────────────────────

    #[Computed(persist: true)]
    public function availableBatches()
    {
        return DB::table('alumni')
            ->whereNull('deleted_at')
            ->select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch');
    }

    #[Computed(persist: true)]
    public function availableCourses()
    {
        return DB::table('alumni')
            ->whereNull('deleted_at')
            ->select('course_code')
            ->distinct()
            ->orderBy('course_code')
            ->pluck('course_code');
    }

    // ── Modal Records ─────────────────────────────────────────────────────────

    #[Computed]
    public function modalRecords()
    {
        if ($this->modalFilter === 'no_record') {
            $q = DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->whereNotExists(fn ($sq) => $sq
                    ->from('employment_trackings as et')
                    ->whereColumn('et.alumni_id', 'a.id')
                    ->whereNull('et.deleted_at')
                )
                ->select([
                    'a.id','a.first_name','a.middle_initial','a.last_name','a.suffix',
                    'a.student_id','a.course_code','a.batch','a.profile_photo',
                    DB::raw("NULL as employment_status"),
                    DB::raw("NULL as job_title"),
                    DB::raw("NULL as company_name"),
                    DB::raw("NULL as employment_type"),
                    DB::raw("NULL as work_location"),
                    DB::raw("NULL as course_relevance"),
                ]);

            if ($this->modalBatch !== null) $q->where('a.batch', $this->modalBatch);
            if ($this->modalCourse !== '')  $q->where('a.course_code', $this->modalCourse);

            if ($this->modalSearch) {
                $term = '%' . $this->modalSearch . '%';
                $q->where(fn ($s) => $s
                    ->where('a.first_name',  'like', $term)
                    ->orWhere('a.last_name',  'like', $term)
                    ->orWhere('a.student_id', 'like', $term)
                    ->orWhere('a.course_code','like', $term)
                );
            }

            return $q->orderBy('a.last_name')
                     ->paginate($this->modalPageSize, ['*'], 'mPage', $this->modalPage);
        }

        $q = DB::table('employment_trackings as et')
            ->join('alumni as a', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at')
            ->whereNull('a.deleted_at')
            ->select([
                'a.id','a.first_name','a.middle_initial','a.last_name','a.suffix',
                'a.student_id','a.course_code','a.batch','a.profile_photo',
                'et.employment_status','et.job_title','et.company_name',
                'et.employment_type','et.work_location','et.course_relevance',
            ]);

        if (in_array($this->modalFilter, ['employed','self_employed','unemployed']))
            $q->where('et.employment_status', $this->modalFilter);

        if (in_array($this->modalFilter, ['abroad','local']))
            $q->where('et.work_location', $this->modalFilter);

        if ($this->modalFilter === 'relevance_all') {
            $active = array_values(array_filter($this->modalRelevanceActive));
            if (!empty($active)) {
                $q->whereIn('et.course_relevance', $active);
            } else {
                $q->whereNotNull('et.course_relevance');
            }
        }

        if ($this->modalBatch !== null) $q->where('a.batch', $this->modalBatch);
        if ($this->modalCourse !== '')  $q->where('a.course_code', $this->modalCourse);

        if ($this->modalSearch) {
            $term = '%' . $this->modalSearch . '%';
            $q->where(fn ($s) => $s
                ->where('a.first_name',    'like', $term)
                ->orWhere('a.last_name',    'like', $term)
                ->orWhere('a.student_id',   'like', $term)
                ->orWhere('a.course_code',  'like', $term)
                ->orWhere('et.company_name','like', $term)
                ->orWhere('et.job_title',   'like', $term)
            );
        }

        return $q->orderByDesc('et.created_at')
                 ->paginate($this->modalPageSize, ['*'], 'mPage', $this->modalPage);
    }

    #[Computed]
    public function modalTitle(): string
    {
        $batchSuffix  = $this->modalBatch  ? ' — Batch ' . $this->modalBatch : '';
        $courseSuffix = $this->modalCourse ? ' — ' . $this->modalCourse      : '';
        $suffix       = $batchSuffix . $courseSuffix;

        if ($this->modalFilter === 'relevance_all') {
            $active = $this->modalRelevanceActive;
            $labels = ['yes' => 'Relevant', 'partially' => 'Partial', 'no' => 'Non-Relevant'];
            if (count($active) === 3 || empty($active)) return 'All Course-Relevance Records' . $suffix;
            $names = array_map(fn($v) => $labels[$v] ?? $v, $active);
            return implode(' + ', $names) . ' Jobs' . $suffix;
        }

        return match ($this->modalFilter) {
            'employed'      => 'Employed Alumni'      . $suffix,
            'self_employed' => 'Self-Employed Alumni' . $suffix,
            'unemployed'    => 'Unemployed Alumni'    . $suffix,
            'no_record'     => 'No Employment Record' . $suffix,
            'abroad'        => 'OFW / Working Abroad' . $suffix,
            'local'         => 'Working Locally'      . $suffix,
            default         => $this->modalBatch
                                ? 'Batch ' . $this->modalBatch . ' — Employment Records' . $courseSuffix
                                : ($this->modalCourse
                                    ? $this->modalCourse . ' — All Employment Records'
                                    : 'All Employment Records'),
        };
    }

    #[Computed]
    public function modalIcon(): string
    {
        if ($this->modalFilter === 'relevance_all') {
            $active = $this->modalRelevanceActive;
            if (count($active) === 1) {
                return match($active[0]) {
                    'yes'       => 'fa-circle-check',
                    'partially' => 'fa-circle-half-stroke',
                    'no'        => 'fa-circle-xmark',
                    default     => 'fa-chart-pie',
                };
            }
            return 'fa-chart-pie';
        }

        return match ($this->modalFilter) {
            'employed'      => 'fa-user-tie',
            'self_employed' => 'fa-store',
            'unemployed'    => 'fa-magnifying-glass',
            'no_record'     => 'fa-circle-minus',
            'abroad'        => 'fa-plane-departure',
            'local'         => 'fa-house',
            default         => 'fa-briefcase',
        };
    }

    #[Computed]
    public function isRelevanceFilter(): bool
    {
        return $this->modalFilter === 'relevance_all';
    }

    // ── Modal actions ─────────────────────────────────────────────────────────

    public function openModal(string $filter = '', ?int $batch = null, string $course = ''): void
    {
        $relevanceMap = [
            'relevance_yes'       => ['yes'],
            'relevance_partially' => ['partially'],
            'relevance_no'        => ['no'],
            'relevance_all'       => ['yes', 'partially', 'no'],
        ];

        if (isset($relevanceMap[$filter])) {
            $this->modalRelevanceActive = $relevanceMap[$filter];
            // FIX 3: Lock when opened from a specific segment click; unlock for card/all click
            $this->modalRelevanceLocked = ($filter !== 'relevance_all');
            $filter = 'relevance_all';
        } else {
            $this->modalRelevanceActive = ['yes', 'partially', 'no'];
            $this->modalRelevanceLocked = false;
        }

        $this->modalFilter       = $filter;
        $this->modalBatch        = $batch;
        $this->modalBatchLocked  = ($batch !== null);
        $this->modalCourse       = $course;
        $this->modalCourseLocked = ($course !== '');
        $this->modalPage         = 1;
        $this->modalSearch       = '';
        $this->activeModal       = 'detail';
        unset($this->modalRecords);
    }

    /**
     * Toggle a single relevance value on/off in the multi-select set.
     * Silently ignored when the modal is in locked (specific segment) mode.
     */
    public function toggleRelevance(string $val): void
    {
        if ($this->modalRelevanceLocked) return;

        if (in_array($val, $this->modalRelevanceActive)) {
            $this->modalRelevanceActive = array_values(
                array_filter($this->modalRelevanceActive, fn($v) => $v !== $val)
            );
        } else {
            $this->modalRelevanceActive[] = $val;
        }
        $this->modalPage = 1;
        unset($this->modalRecords);
    }

    public function updatingModalSearch(): void { $this->modalPage = 1; }
    public function updatingModalBatch(): void  { $this->modalPage = 1; }
    public function updatingModalCourse(): void { $this->modalPage = 1; }

    public function modalPrev(): void { if ($this->modalPage > 1) $this->modalPage--; }
    public function modalNext(): void
    {
        if ($this->modalPage < $this->modalRecords->lastPage())
            $this->modalPage++;
    }

    // ── FIX 1: Clear helpers — fast single round-trip each ───────────────────

    public function clearModalBatch(): void
    {
        $this->modalBatch       = null;
        $this->modalBatchLocked = false;
        $this->modalPage        = 1;
        unset($this->modalRecords);
    }

    public function clearModalCourse(): void
    {
        $this->modalCourse       = '';
        $this->modalCourseLocked = false;
        $this->modalPage         = 1;
        unset($this->modalRecords);
    }

    /**
     * FIX 1: "Clear all" — clears every secondary filter in ONE Livewire round-trip.
     * Matches the dashboard's clearEmpModalFilters() pattern.
     */
    public function clearModalFilters(): void
    {
        $this->modalBatch        = null;
        $this->modalBatchLocked  = false;
        $this->modalCourse       = '';
        $this->modalCourseLocked = false;
        $this->modalSearch       = '';
        $this->modalPage         = 1;
        unset($this->modalRecords);
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->modalFilter          = '';
        $this->modalBatch           = null;
        $this->modalBatchLocked     = false;
        $this->modalCourse          = '';
        $this->modalCourseLocked    = false;
        $this->modalPage            = 1;
        $this->modalSearch          = '';
        $this->modalRelevanceActive = ['yes', 'partially', 'no'];
        $this->modalRelevanceLocked = false;
        unset($this->modalRecords);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }

    public function formatName(?string $f, ?string $m, ?string $l, ?string $s): string
    {
        $parts = [trim($f ?? '')];
        if (trim($m ?? '') !== '') $parts[] = ucfirst(strtolower(substr(trim($m), 0, 1))) . '.';
        $parts[] = trim($l ?? '');
        if (trim($s ?? '') !== '') $parts[] = trim($s);
        return implode(' ', array_filter($parts));
    }

    public function with(): array { return []; }
}; ?>

{{-- ═══ SINGLE ROOT ELEMENT ═══ --}}
<div @open-emp-modal.window="$wire.openModal($event.detail.filter, $event.detail.batch ?? null, $event.detail.course ?? '')">

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

    @keyframes empPageIn {
        from { opacity:0; transform:translateY(8px); }
        to   { opacity:1; transform:translateY(0);   }
    }
    .emp-modal-enter { animation: empPageIn .20s cubic-bezier(.4,0,.2,1) both; }

    .emp-page-wrapper {
        height: 90vh;
        max-height: 90vh;
        overflow: hidden;
        background: var(--bg);
        display: flex;
        flex-direction: column;
        gap: 0;
    }
    .emp-page-scroll {
        flex: 1;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .emp-inner {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 14px 24px 10px;
        gap: 12px;
        max-width: 1920px;
        margin: 0 auto;
        width: 100%;
        box-sizing: border-box;
    }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
        transition: transform .15s, box-shadow .15s, border-color .15s;
        cursor: pointer;
        flex: 1;
        min-width: 0;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 16px rgba(122,63,145,.13);
        border-color: rgba(122,63,145,.35);
    }
    .stat-card:active { transform: scale(.975); }
    .stat-icon {
        width: 38px; height: 38px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .stat-number { font-size: 1.45rem; font-weight: 600; line-height: 1; color: var(--ink); }
    .stat-label  { font-size: .75rem; font-weight: 600; color: var(--muted); margin-top: 2px; }

    .chart-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        cursor: pointer;
        transition: box-shadow .18s ease, border-color .18s ease;
    }
    .chart-card:hover {
        box-shadow: 0 5px 16px rgba(122,63,145,.11);
        border-color: rgba(122,63,145,.28);
    }
    .chart-card:active { transform: scale(.998); }
    .chart-header {
        padding: 8px 14px;
        border-bottom: 1px solid var(--border);
        background: var(--subtle-bg);
        display: flex;
        align-items: center;
        gap: 7px;
        flex-shrink: 0;
    }
    .chart-dot   { width:8px; height:8px; border-radius:50%; background:var(--primary); flex-shrink:0; }
    .chart-title { font-size:.78rem; font-weight:600; color:var(--ink); text-transform:uppercase; letter-spacing:.06em; }
    .chart-body  { padding:10px; flex:1; min-height:0; }
    .chart-hint  {
        font-size: .70rem;
        color: #bbb;
        font-weight: 500;
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 3px;
        pointer-events: none;
    }

    .batch-nav-btn {
        width:28px; height:28px; border-radius:7px;
        border:1px solid var(--border); background:#fff; color:var(--primary);
        font-size:.75rem; cursor:pointer;
        display:flex; align-items:center; justify-content:center;
        transition:background .15s, border-color .15s; flex-shrink:0;
    }
    .batch-nav-btn:hover:not(:disabled) { background:var(--primary-lt); border-color:var(--primary); }
    .batch-nav-btn:disabled { opacity:.35; cursor:not-allowed; }
    .batch-page-info { font-size:.74rem; font-weight:600; color:var(--muted); white-space:nowrap; }

    /* FIX 3: Relevance chips — multi-select toggle style */
    .rel-chip-active {
        background: linear-gradient(135deg,#7A3F91,#9b59b6);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 2px 8px rgba(122,63,145,.25);
    }
    .rel-chip-inactive {
        background: #fff;
        color: #aaa;
        border-color: #e5e7eb;
        opacity: 0.65;
    }
    .rel-chip-inactive:hover {
        opacity: 1;
        border-color: #d4aaeb;
        color: #7A3F91;
    }

    .filter-chip-active {
        background: linear-gradient(135deg,#7A3F91,#9b59b6);
        color: #fff;
        border-color: transparent;
    }
    .modal-select {
        appearance:none; -webkit-appearance:none;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237A3F91' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 10px center;
        padding-right:30px !important; cursor:pointer;
    }
    .modal-select:focus { outline:none; border-color:#7A3F91; box-shadow:0 0 0 3px rgba(122,63,145,.10); }

    .charts-section {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .charts-row-top {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        flex: 4;
        min-height: 0;
    }
    .charts-row-bottom {
        flex: 5;
        min-height: 0;
    }
    @media (max-width: 1024px) {
        .charts-row-top { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .charts-row-top { grid-template-columns: 1fr; }
    }

    .emp-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    .stat-row {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }
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

{{-- ── 90vh strict wrapper ── --}}
<div class="emp-page-wrapper">
<div class="emp-page-scroll">
<div class="emp-inner">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="emp-page-header">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow-md flex-shrink-0"
                 style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);box-shadow:0 4px 14px rgba(122,63,145,.28);">
                <i class="fas fa-chart-column text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold leading-tight" style="color:var(--ink);">Employment Tracking</h1>
                <p class="text-xs font-normal mt-0.5" style="color:var(--muted);">System-wide alumni employment data &amp; analytics</p>
            </div>
        </div>
    </div>

    {{-- ══ STAT CARDS ══ --}}
    <div class="stat-row">

        <div class="stat-card" wire:click="openModal('')">
            <div class="stat-icon" style="background:#ede9fe;">
                <i class="fa-solid fa-file-circle-check" style="color:var(--primary);font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalSubmitted }}</p>
                <p class="stat-label">Submitted</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('employed')">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-briefcase" style="color:#059669;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalEmployed }}</p>
                <p class="stat-label">Employed</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('self_employed')">
            <div class="stat-icon" style="background:#dbeafe;">
                <i class="fa-solid fa-store" style="color:#2563eb;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalSelf }}</p>
                <p class="stat-label">Self-Employed</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('unemployed')">
            <div class="stat-icon" style="background:#fef3c7;">
                <i class="fa-solid fa-circle-pause" style="color:#d97706;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalUnemployed }}</p>
                <p class="stat-label">Unemployed</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('no_record')">
            <div class="stat-icon" style="background:#f3f4f6;">
                <i class="fa-solid fa-circle-question" style="color:#9ca3af;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalNotFilled }}</p>
                <p class="stat-label">Not Filled</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('abroad')">
            <div class="stat-icon" style="background:#fef9c3;">
                <i class="fa-solid fa-plane-departure" style="color:#b45309;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalAbroad }}</p>
                <p class="stat-label">OFW / Abroad</p>
            </div>
        </div>

        <div class="stat-card" wire:click="openModal('local')">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-house" style="color:#059669;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalLocal }}</p>
                <p class="stat-label">Local</p>
            </div>
        </div>

    </div>

    {{-- ══ CHARTS SECTION ══ --}}
    <div class="charts-section">

        {{-- Top row: 4 small charts --}}
        <div class="charts-row-top">

            {{-- Status Breakdown --}}
            <div class="chart-card" onclick="empOpenModal('','',null)">
                <div class="chart-header">
                    <div class="chart-dot"></div>
                    <span class="chart-title">Status Breakdown</span>
                    <span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click segment</span>
                </div>
                <div class="chart-body flex items-center justify-center" wire:ignore>
                    <canvas id="chartStatus"></canvas>
                </div>
            </div>

            {{-- Work Location --}}
            <div class="chart-card" onclick="empOpenModal('','',null)">
                <div class="chart-header">
                    <div class="chart-dot" style="background:#c084fc;"></div>
                    <span class="chart-title">Work Location</span>
                    <span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click segment</span>
                </div>
                <div class="chart-body flex items-center justify-center" wire:ignore>
                    <canvas id="chartLocation"></canvas>
                </div>
            </div>

            {{-- Job-Course Relevance: card click opens relevance_all (all 3 active) --}}
            <div class="chart-card" onclick="empOpenModal('relevance_all','',null)">
                <div class="chart-header">
                    <div class="chart-dot" style="background:#10b981;"></div>
                    <span class="chart-title">Job-Course Relevance</span>
                    <span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click segment</span>
                </div>
                <div class="chart-body flex items-center justify-center" wire:ignore>
                    <canvas id="chartRelevance"></canvas>
                </div>
            </div>

            {{-- Top Courses (Employed) --}}
            <div class="chart-card" onclick="empOpenModal('employed','',null)">
                <div class="chart-header">
                    <div class="chart-dot" style="background:#3b82f6;"></div>
                    <span class="chart-title">Top Courses (Employed)</span>
                    <span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click bar</span>
                </div>
                <div class="chart-body" wire:ignore>
                    <canvas id="chartCourse"></canvas>
                </div>
            </div>

        </div>

        {{-- Bottom row: Batch chart (full width) --}}
        <div class="chart-card charts-row-bottom" onclick="empOpenModal('','',null)">
            <div class="chart-header" style="justify-content:space-between;">
                <div class="flex items-center gap-2">
                    <div class="chart-dot" style="background:#f59e0b;"></div>
                    <span class="chart-title">Employment by Batch Year</span>
                    <span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click a bar segment — filters status + batch</span>
                </div>
                <div id="batchNavControls" class="flex items-center gap-2" style="display:none!important;">
                    <button id="batchPrev" class="batch-nav-btn" title="Previous batches">
                        <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                    </button>
                    <span id="batchPageInfo" class="batch-page-info"></span>
                    <button id="batchNext" class="batch-nav-btn" title="Next batches">
                        <i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i>
                    </button>
                </div>
            </div>
            <div class="chart-body" wire:ignore style="flex:1;min-height:0;">
                <canvas id="chartBatch" style="width:100%;height:100%;"></canvas>
            </div>
        </div>

    </div>

</div>
</div>
</div>{{-- end emp-page-wrapper --}}


{{-- ═══════════════════════════════════════════════════════════
     DETAIL FULL-SCREEN MODAL
═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'detail')
@php
    $records    = $this->modalRecords;
    $isNoRecord = $this->modalFilter === 'no_record';
    $isRelMode  = $this->isRelevanceFilter;

    $statusBadge = [
        'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]',  'fa-user-tie'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',       'fa-store'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',    'fa-magnifying-glass'],
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $locBadge = [
        'abroad' => ['OFW/Abroad', 'text-amber-700 bg-amber-50 border-amber-200',       'fa-plane-departure'],
        'local'  => ['Local',      'text-emerald-700 bg-emerald-50 border-emerald-200',  'fa-house'],
    ];
    $relevanceBadge = [
        'yes'       => ['Relevant',  'text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check'],
        'partially' => ['Partial',   'text-amber-700 bg-amber-50 border-amber-200',       'fa-circle-half-stroke'],
        'no'        => ['Not Rel.',  'text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark'],
    ];

    // Relevance chip definitions for multi-select mode
    $relChips = [
        ['yes',       'Relevant',     'fa-circle-check',       'text-emerald-700 bg-emerald-50 border-emerald-200'],
        ['partially', 'Partial',      'fa-circle-half-stroke', 'text-amber-700 bg-amber-50 border-amber-200'],
        ['no',        'Not Relevant', 'fa-circle-xmark',       'text-red-600 bg-red-50 border-red-200'],
    ];

    // Labels/icons for the locked relevance chip
    $relLockedLabels = ['yes' => 'Relevant', 'partially' => 'Partial', 'no' => 'Not Relevant'];
    $relLockedIcons  = ['yes' => 'fa-circle-check', 'partially' => 'fa-circle-half-stroke', 'no' => 'fa-circle-xmark'];

    // Status tabs for locked chip display
    $allStatusTabs = [
        [''             , 'All Records',  'fa-briefcase'],
        ['employed'     , 'Employed',     'fa-user-tie'],
        ['self_employed', 'Self-Employed','fa-store'],
        ['unemployed'   , 'Unemployed',   'fa-magnifying-glass'],
        ['no_record'    , 'No Record',    'fa-circle-minus'],
        ['abroad'       , 'Abroad',       'fa-plane-departure'],
        ['local'        , 'Local',        'fa-house'],
    ];
    $visibleStatusTabs = array_values(array_filter($allStatusTabs, fn($t) => $t[0] === $modalFilter));

    // FIX 1: Secondary filter detection (matches dashboard pattern)
    $hasSubFilters = $modalBatch !== null || $modalCourse !== '' || $modalSearch !== '';

    // Determines which chips to show in "Filtering by:" (only unlocked/user-added ones)
    $hasChipsToShow = ($modalBatch !== null && !$modalBatchLocked)
                   || ($modalCourse !== '' && !$modalCourseLocked)
                   || $modalSearch !== '';
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 emp-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- ─── Header ──────────────────────────────────────────── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->modalIcon }} text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">{{ $this->modalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">
                    {{ number_format($records->total()) }} record(s) found
                </p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- ─── FIX 1: Toolbar — 2-row layout matching dashboard pattern ── --}}
    <div class="px-6 lg:px-10 pt-2.5 pb-2 bg-white border-b border-gray-200 shrink-0">

        {{-- Row 1: Search + Filter chips (relevance or status) --}}
        <div class="flex flex-wrap gap-2 items-center mb-2">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs" wire:ignore
                 x-data="{ q:'', init(){ this.q = $wire.modalSearch ?? ''; $wire.$watch('modalSearch', v => { if(v!==this.q) this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('modalSearch', q)"
                       placeholder="Name, ID, course, company…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all duration-150"
                       autocomplete="off">
            </div>

            <div class="h-5 w-px bg-gray-200 flex-shrink-0"></div>

            {{-- FIX 3: Relevance chips (locked single OR multi-select) OR status chip --}}
            @if($isRelMode && $modalRelevanceLocked)
                {{-- Single locked chip — opened from a specific segment click --}}
                @php $lockedVal = $modalRelevanceActive[0] ?? 'yes'; @endphp
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border filter-chip-active">
                    <i class="fas {{ $relLockedIcons[$lockedVal] ?? 'fa-chart-pie' }} text-[10px]"></i>
                    {{ $relLockedLabels[$lockedVal] ?? 'Relevant' }}
                    <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                </span>

            @elseif($isRelMode && !$modalRelevanceLocked)
                {{-- Multi-select toggleable chips — opened from card header --}}
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-[11px] font-semibold text-gray-400">Relevance:</span>
                    @foreach($relChips as [$relVal, $relLbl, $relIcon, $relColors])
                    @php $isRelActive = in_array($relVal, $modalRelevanceActive ?? []); @endphp
                    <button wire:click="toggleRelevance('{{ $relVal }}')"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition-all duration-150 flex items-center gap-1.5 active:scale-95
                                   {{ $isRelActive ? 'rel-chip-active' : 'rel-chip-inactive ' . $relColors }}">
                        <i class="fas {{ $relIcon }} text-[10px]"></i>
                        {{ $relLbl }}
                        @if($isRelActive)
                        <i class="fas fa-check text-[9px] opacity-80"></i>
                        @endif
                    </button>
                    @endforeach
                </div>

            @else
                {{-- Status / location locked chip --}}
                <div class="flex items-center gap-1.5">
                    @foreach($visibleStatusTabs as [$tabVal, $tabLbl, $tabIcon])
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border filter-chip-active">
                        <i class="fas {{ $tabIcon }} text-[10px]"></i>{{ $tabLbl }}
                        <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                    </span>
                    @endforeach
                </div>
            @endif

        </div>

        {{-- Row 2: Batch + Course dropdowns + Active filter chips + "Clear all" --}}
        <div class="flex flex-wrap gap-2 items-center">

            {{-- Batch Year Dropdown --}}
            @if(!$modalBatchLocked)
            <div class="relative flex items-center">
                <i class="fas fa-calendar-alt text-[10px] absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $modalBatch ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="modalBatch"
                        class="modal-select pl-7 pr-7 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $modalBatch ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Batches</option>
                    @foreach($this->availableBatches as $bYear)
                        <option value="{{ $bYear }}">Batch {{ $bYear }}</option>
                    @endforeach
                </select>
                @if($modalBatch)
                <button wire:click="clearModalBatch"
                        class="absolute right-1.5 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear batch filter">
                    <i class="fas fa-xmark" style="font-size:8px;"></i>
                </button>
                @endif
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border"
                  style="background:#F9F7FC; color:#7A3F91; border-color:#7A3F91;">
                <i class="fas fa-calendar-check text-[10px]"></i>Batch {{ $modalBatch }}
                <i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            {{-- Course Dropdown --}}
            @if(!$modalCourseLocked)
            <div class="relative flex items-center">
                <i class="fas fa-book-open text-[10px] absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $modalCourse ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="modalCourse"
                        class="modal-select pl-7 pr-7 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $modalCourse ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Courses</option>
                    @foreach($this->availableCourses as $cCode)
                        <option value="{{ $cCode }}">{{ $cCode }}</option>
                    @endforeach
                </select>
                @if($modalCourse)
                <button wire:click="clearModalCourse"
                        class="absolute right-1.5 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear course filter">
                    <i class="fas fa-xmark" style="font-size:8px;"></i>
                </button>
                @endif
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border"
                  style="background:#EFF6FF; color:#2563eb; border-color:#2563eb;">
                <i class="fas fa-book-open text-[10px]"></i>{{ $modalCourse }}
                <i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            {{-- FIX 1: Active filter chips + "Clear all" text link (dashboard style) --}}
            @if($hasSubFilters)
            <div class="flex items-center gap-1.5 ml-1">
                @if($hasChipsToShow)
                    <span class="text-xs text-gray-400 font-normal">Filtering by:</span>
                    @if($modalBatch !== null && !$modalBatchLocked)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                            <i class="fas fa-calendar text-[10px]"></i> Batch {{ $modalBatch }}
                        </span>
                    @endif
                    @if($modalCourse !== '' && !$modalCourseLocked)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                            <i class="fas fa-book text-[10px]"></i> {{ $modalCourse }}
                        </span>
                    @endif
                    @if($modalSearch !== '')
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                            <i class="fas fa-search text-[10px]"></i> "{{ Str::limit($modalSearch, 20) }}"
                        </span>
                    @endif
                @endif
                {{-- FIX 1: Single round-trip "Clear all" text link (matches dashboard) --}}
                <button wire:click="clearModalFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-wait"
                        class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors duration-100 ml-1">
                    <span wire:loading.remove wire:target="clearModalFilters">Clear all</span>
                    <span wire:loading wire:target="clearModalFilters">Clearing…</span>
                </button>
            </div>
            @endif

        </div>
    </div>

    {{-- ─── Table ────────────────────────────────────────────── --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb;">

        {{-- Loading overlay --}}
        <div wire:loading
             wire:target="modalFilter,modalBatch,modalCourse,modalSearch,modalPage,modalPrev,modalNext,clearModalFilters,clearModalBatch,clearModalCourse,toggleRelevance"
             class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
             style="background:rgba(255,255,255,.60);">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
            </div>
        </div>

        <table class="w-full border-collapse" style="min-width:700px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-12">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alumni</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Job Title</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Relevance</th>
                    <th class="px-4 pr-6 lg:pr-10 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Batch</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($records as $idx => $row)
                @php
                    $rowNum  = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                    $badge   = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? null);
                    $loc     = !$isNoRecord ? ($locBadge[$row->work_location ?? ''] ?? null) : null;
                    $empType = !$isNoRecord ? ($empTypeMap[$row->employment_type ?? ''] ?? null) : null;
                    $relBdg  = !$isNoRecord ? ($relevanceBadge[$row->course_relevance ?? ''] ?? null) : null;
                    $photo   = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName   = $this->formatName($row->first_name??'',$row->middle_initial??'',$row->last_name??'',$row->suffix??'');
                @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">

                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ $row->first_name ?? '' }}"
                                 class="w-8 h-8 rounded-lg object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $dName }}</p>
                                <p class="text-xs text-[#999999] font-normal">
                                    {{ $row->student_id ?? '' }} &bull; {{ $row->course_code ?? '' }}
                                </p>
                            </div>
                        </div>
                    </td>

                    <td class="px-4 py-3">
                        @if($isNoRecord)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">
                                <i class="fas fa-circle-minus text-[10px]"></i> No Record
                            </span>
                        @elseif($badge)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                <i class="fas {{ $badge[2] }} text-[10px]"></i> {{ $badge[0] }}
                            </span>
                            @if($empType)
                                <p class="text-[11px] text-[#999999] mt-0.5 font-normal">{{ $empType }}</p>
                            @endif
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 hidden sm:table-cell">
                        @if(!$isNoRecord && ($row->company_name || $row->job_title))
                            <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $row->company_name ?? '—' }}</p>
                            <p class="text-xs text-[#999999] font-normal truncate uppercase">{{ $row->job_title ?? '' }}</p>
                        @else
                            <span class="text-xs text-[#CCCCCC] font-normal">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-center">
                        @if(!$isNoRecord && $loc)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $loc[1] }}">
                                <i class="fas {{ $loc[2] }} text-[10px]"></i> {{ $loc[0] }}
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-center">
                        @if(!$isNoRecord && $relBdg)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $relBdg[1] }}">
                                <i class="fas {{ $relBdg[2] }} text-[10px]"></i> {{ $relBdg[0] }}
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>

                    <td class="px-4 pr-6 lg:pr-10 py-3 text-center">
                        <span class="text-sm font-semibold text-[#333333]">{{ $row->batch ?? '—' }}</span>
                    </td>

                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-400">No records found</p>
                            <p class="text-xs text-gray-300 font-normal">Try adjusting your filters</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ─── Footer Pagination ───────────────────────────────── --}}
    @php
        $rTotal = $records->total();
        $rPp    = $records->perPage();
        $rCp    = $records->currentPage();
        $rFrom  = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
        $rTo    = min($rCp * $rPp, $rTotal);
    @endphp
    <div class="px-6 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-semibold">{{ $rFrom }}–{{ $rTo }}</strong>
            of <strong class="font-semibold">{{ number_format($rTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-2">
            @if($records->onFirstPage())
                <button disabled class="px-3 py-1.5 rounded-lg text-xs font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">← Prev</button>
            @else
                <button wire:click="modalPrev"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">← Prev</button>
            @endif
            <span class="px-3 py-1.5 text-xs font-semibold bg-white rounded-lg shadow-sm" style="color:#7A3F91;">
                {{ $rCp }} / {{ $records->lastPage() }}
            </span>
            @if($records->hasMorePages())
                <button wire:click="modalNext"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">Next →</button>
            @else
                <button disabled class="px-3 py-1.5 rounded-lg text-xs font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">Next →</button>
            @endif
        </div>
    </div>

</div>
@endif


{{-- ══ CHARTS + BATCH NAV SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    var BATCH_PAGE_SIZE = 8;
    var batchPageIndex  = 0;
    var allBatchData    = null;

    /**
     * Dispatch Livewire open event globally.
     * Card-level onclick attributes call this directly.
     */
    window.empOpenModal = function (filter, course, batch) {
        window.dispatchEvent(new CustomEvent('open-emp-modal', {
            detail: {
                filter: filter || '',
                batch:  batch  || null,
                course: course || '',
            }
        }));
    };

    function safeDestroy(id) {
        var canvas = document.getElementById(id);
        if (canvas && window.Chart && Chart.getChart) {
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();
        }
    }

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

    /**
     * FIX 2: buildDonut — Legend items are now toggleable (hide/show segments).
     *
     * - Legend click  → stops propagation (no card onclick) + toggles segment visibility
     * - Segment click → stops propagation + opens modal with specific filter
     * - Empty canvas  → stops propagation (no modal, no bubble to card)
     *
     * Clicking the card HEADER / PADDING (outside the canvas) still bubbles to card onclick.
     */
    function buildDonut(id, data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        safeDestroy(id);

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data:            data.data,
                    backgroundColor: data.colors,
                    borderWidth:     2,
                    borderColor:     '#fff',
                    hoverOffset:     4,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 11, weight: '600' },
                            color: '#333333',
                            padding: 10,
                            usePointStyle: true,
                            pointStyleWidth: 8,
                        },
                        /**
                         * FIX 2: Restore default Chart.js segment toggle.
                         * Stop propagation so card onclick doesn't fire.
                         */
                        onClick: function (e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart;
                            var index = legendItem.index;
                            if (chart.getDataVisibility(index)) {
                                chart.hide(index);
                            } else {
                                chart.show(index);
                            }
                            chart.update();
                        },
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
                /**
                 * Always stop propagation from canvas.
                 * FIX 3: Segment click dispatches specific filter (e.g. relevance_yes).
                 *        openModal() in PHP normalises this → locked single chip.
                 *        Empty canvas click is stopped with no modal.
                 */
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var idx    = elements[0].index;
                        var filter = (data.filters && data.filters[idx]) ? data.filters[idx] : '';
                        if (!filter) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', {
                            detail: { filter: filter, batch: null, course: '' }
                        }));
                    }
                    // Empty canvas click: stopped, no modal, no bubble.
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
            total:      data.total ? data.total.slice(startIdx, end) : [],
        };
    }

    /**
     * Batch bar chart.
     * - Legend click: stopped, no toggle (bar chart legend toggles datasets; kept off intentionally).
     * - Bar segment click: stopped + opens modal with batch + status filter.
     * - Empty canvas click: stopped, no bubble to card.
     */
    function buildBatchBar(data, startIdx) {
        if (!data || !data.labels || !data.labels.length) return;

        var slice  = sliceBatch(data, startIdx);
        var canvas = document.getElementById('chartBatch');
        if (!canvas) return;
        safeDestroy('chartBatch');

        var datasetFilterMap = { 0: 'employed', 1: 'self_employed', 2: 'unemployed' };

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: slice.labels,
                datasets: [
                    { label: 'Employed',      data: slice.employed,   backgroundColor: '#10b981', borderRadius: 3, stack: 'a' },
                    { label: 'Self-Employed', data: slice.self_emp,   backgroundColor: '#3b82f6', borderRadius: 3, stack: 'a' },
                    { label: 'Unemployed',    data: slice.unemployed, backgroundColor: '#f59e0b', borderRadius: 3, stack: 'a' },
                ],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                animation:           { duration: 300 },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { font: { size: 11, weight: '600' }, color: '#333333', padding: 12, usePointStyle: true },
                        onClick: function (e) {
                            if (e && e.native) e.native.stopPropagation();
                            // No dataset toggle for batch bar — keep all stacks visible
                        },
                    },
                    tooltip: {
                        callbacks: {
                            title:  function (items) { return 'Batch ' + items[0].label; },
                            footer: function (items) {
                                var t = 0; items.forEach(function (i) { t += i.raw; });
                                return 'Total submitted: ' + t;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11, weight: '600' }, color: '#666666' } },
                    y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var el     = elements[0];
                        var batch  = slice.labels[el.index];
                        var filter = (datasetFilterMap[el.datasetIndex] !== undefined)
                                        ? datasetFilterMap[el.datasetIndex] : '';
                        if (batch === undefined || batch === null) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', {
                            detail: { filter: filter, batch: batch, course: '' }
                        }));
                    }
                },
            },
        });

        // Batch pagination nav
        var totalBatches = data.labels.length;
        var totalPages   = Math.ceil(totalBatches / BATCH_PAGE_SIZE);
        var currentPage  = Math.floor(startIdx / BATCH_PAGE_SIZE) + 1;
        var navEl   = document.getElementById('batchNavControls');
        var prevBtn = document.getElementById('batchPrev');
        var nextBtn = document.getElementById('batchNext');
        var infoEl  = document.getElementById('batchPageInfo');

        if (navEl && totalPages > 1) {
            navEl.style.display  = 'flex';
            infoEl.textContent   = currentPage + ' / ' + totalPages;
            prevBtn.disabled     = (startIdx <= 0);
            nextBtn.disabled     = (startIdx + BATCH_PAGE_SIZE >= totalBatches);
        } else if (navEl) {
            navEl.style.display = 'none';
        }
    }

    /**
     * Course horizontal bar chart.
     * - Legend: blocked (no legend shown), propagation stopped anyway.
     * - Bar click: stopped + opens modal with course + employed filter.
     * - Empty canvas click: stopped, no bubble.
     */
    function buildCourseBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        safeDestroy('chartCourse');

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label:           'Employed Alumni',
                    data:            data.data,
                    backgroundColor: '#7a3f91cc',
                    borderColor:     '#7a3f91',
                    borderWidth:     1,
                    borderRadius:    4,
                }],
            },
            options: {
                indexAxis:           'y',
                responsive:          true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                        onClick: function (e) { if (e && e.native) e.native.stopPropagation(); },
                    },
                    tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.parsed.x + ' alumni'; } } },
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' }, color: '#333333' } },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var course = data.labels[elements[0].index];
                        if (!course) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', {
                            detail: { filter: 'employed', batch: null, course: course }
                        }));
                    }
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

        newPrev.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!allBatchData) return;
            batchPageIndex = Math.max(0, batchPageIndex - BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
            bindBatchNav();
        });
        newNext.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!allBatchData) return;
            var max = allBatchData.labels.length - BATCH_PAGE_SIZE;
            batchPageIndex = Math.min(max, batchPageIndex + BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
            bindBatchNav();
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
            ['chartStatus','chartLocation','chartRelevance','chartBatch','chartCourse'].forEach(safeDestroy);
            allBatchData   = null;
            batchPageIndex = 0;
            requestAnimationFrame(initAllCharts);
        });

        function hookLivewire() {
            if (!window.Livewire) return;
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || function (cb) { cb({}); };
                if (typeof succeed === 'function') {
                    succeed(function () { requestAnimationFrame(initAllCharts); });
                }
            });
        }

        if (window.Livewire) {
            hookLivewire();
        } else {
            document.addEventListener('livewire:initialized', hookLivewire);
        }
    });

})();
</script>

</div>{{-- ═══ END SINGLE ROOT ELEMENT ═══ --}}