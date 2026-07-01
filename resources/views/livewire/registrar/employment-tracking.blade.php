<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

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
    public string $modalCourse          = '';
    public bool   $modalCourseLocked    = false;
    public ?int   $modalBatch           = null;
    public bool   $modalBatchLocked     = false;
    public int    $modalPage            = 1;
    public int    $modalPageSize        = 200;
    public string $modalSearch          = '';

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int    $totalAlumni     = 0;
    public int    $totalSubmitted  = 0;
    public int    $totalEmployed   = 0;
    public int    $totalSelf       = 0;
    public int    $totalUnemployed = 0;
    public int    $totalNotFilled  = 0;
    public int    $totalAbroad     = 0;
    public int    $totalLocal      = 0;

    // ── Chart Data (JSON strings) ─────────────────────────────────────────────
    public string $chartStatusData    = '{}';
    public string $chartRelevanceData = '{}';
    public string $chartBatchData     = '{}';
    public string $chartCourseData    = '{}';
    public string $chartTrendData     = '{}';

    // ── Allowed sort columns ──────────────────────────────────────────────────
    #[Locked]
    protected array $allowedSortColumns = [
        'a.last_name', 'a.first_name', 'a.student_id',
        'a.course_code', 'a.batch', 'et.employment_status',
    ];

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

    public function refreshData(): void
    {
        $this->computeStats();
        $this->buildCharts();
        unset($this->courseAnalytics);
        unset($this->availableBatches);
        unset($this->availableCourses);
        unset($this->courseMap);
        unset($this->availableModalBatchesForFilter);
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('alumni as a')->whereNull('a.deleted_at');
    }

    private function latestEmpSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('employment_trackings as et_inner')
            ->whereNull('et_inner.deleted_at')
            ->select('et_inner.alumni_id', DB::raw('MAX(et_inner.id) as max_id'))
            ->groupBy('et_inner.alumni_id');
    }

    public function computeStats(): void
    {
        $base = $this->baseQuery();
        $this->totalAlumni = (clone $base)->count();

        $withEmp = (clone $base)
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', 'et.id', '=', 'latest_et.max_id')
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
        // Status donut
        $this->chartStatusData = json_encode([
            'labels'  => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'    => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors'  => ['#10b981','#3b82f6','#f59e0b','#d1d5db'],
            'filters' => ['employed', 'self_employed', 'unemployed', 'no_record'],
        ]);

        // Relevance donut
        $relevanceRows = DB::table('employment_trackings as et')
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id'))
            ->whereNull('et.deleted_at')
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $this->chartRelevanceData = json_encode([
            'labels'  => ['Relevant', 'Partially Relevant', 'Not Relevant'],
            'data'    => [
                $relevanceRows->get('yes')->cnt        ?? $relevanceRows->get('relevant')->cnt        ?? 0,
                $relevanceRows->get('partially')->cnt  ?? $relevanceRows->get('partially_relevant')->cnt ?? 0,
                $relevanceRows->get('no')->cnt         ?? $relevanceRows->get('not_relevant')->cnt     ?? 0,
            ],
            'colors'  => ['#10b981','#f59e0b','#ef4444'],
            'filters' => ['relevance_yes', 'relevance_partially', 'relevance_no'],
        ]);

        // Batch stacked bar
        $batchRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                'a.batch',
                DB::raw("SUM(CASE WHEN et.employment_status = 'employed' THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'self_employed' THEN 1 ELSE 0 END) as self_emp"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'unemployed' THEN 1 ELSE 0 END) as unemployed"),
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

        // Top courses horizontal bar
        $courseRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->whereIn('et.employment_status', ['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->orderByDesc('cnt')->limit(10)->get();

        $this->chartCourseData = json_encode([
            'labels' => $courseRows->pluck('course_code'),
            'data'   => $courseRows->pluck('cnt'),
        ]);

        // Employment rate trend per batch (line chart)
        $trendRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                'a.batch',
                DB::raw('COUNT(a.id) as total'),
                DB::raw("SUM(CASE WHEN et.employment_status IN ('employed','self_employed') THEN 1 ELSE 0 END) as working")
            )
            ->groupBy('a.batch')->orderBy('a.batch', 'asc')->get();

        $this->chartTrendData = json_encode([
            'labels' => $trendRows->pluck('batch')->values(),
            'rates'  => $trendRows->map(fn($r) => $r->total > 0 ? round($r->working / $r->total * 100, 1) : 0)->values(),
            'totals' => $trendRows->pluck('total')->values(),
        ]);
    }

    #[Computed(persist: true)]
    public function courseMap()
    {
        return DB::table('courses')
            ->select('code', 'name')
            ->orderBy('code')
            ->get()
            ->pluck('name', 'code')
            ->toArray();
    }

    #[Computed]
    public function courseAnalytics()
    {
        return DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                'a.course_code',
                DB::raw('COUNT(a.id) as total'),
                DB::raw("SUM(CASE WHEN et.employment_status IN ('employed','self_employed') THEN 1 ELSE 0 END) as working"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'employed' THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'self_employed' THEN 1 ELSE 0 END) as self_employed"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'unemployed' THEN 1 ELSE 0 END) as unemployed"),
                DB::raw("SUM(CASE WHEN et.id IS NOT NULL THEN 1 ELSE 0 END) as submitted")
            )
            ->groupBy('a.course_code')->orderByDesc('working')->get()
            ->map(function ($row) {
                $row->not_filled    = max(0, $row->total - $row->submitted);
                $row->emp_rate      = $row->total > 0 ? round($row->working / $row->total * 100, 1) : 0;
                $row->response_rate = $row->total > 0 ? round($row->submitted / $row->total * 100, 1) : 0;
                return $row;
            });
    }

    #[Computed(persist: true)]
    public function availableBatches()
    {
        return DB::table('alumni')->whereNull('deleted_at')
            ->select('batch')->distinct()->orderByDesc('batch')->pluck('batch');
    }

    #[Computed(persist: true)]
    public function availableCourses()
    {
        return DB::table('alumni')->whereNull('deleted_at')
            ->select('course_code')->distinct()->orderBy('course_code')->pluck('course_code');
    }

    #[Computed]
    public function availableModalBatchesForFilter()
    {
        if ($this->modalFilter === 'no_record') {
            return DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->whereNotExists(fn($sq) => $sq
                    ->from('employment_trackings as et')
                    ->whereColumn('et.alumni_id', 'a.id')
                    ->whereNull('et.deleted_at')
                )
                ->select('a.batch')->distinct()->orderByDesc('a.batch')->pluck('a.batch');
        }

        $q = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select('a.batch')->distinct()->orderByDesc('a.batch');

        match ($this->modalFilter) {
            'employed'            => $q->where('et.employment_status', 'employed'),
            'self_employed'       => $q->where('et.employment_status', 'self_employed'),
            'unemployed'          => $q->where('et.employment_status', 'unemployed'),
            'employed_all'        => $q->whereIn('et.employment_status', ['employed', 'self_employed']),
            'abroad'              => $q->where('et.work_location', 'abroad')->whereIn('et.employment_status', ['employed','self_employed']),
            'local'               => $q->where('et.work_location', 'local')->whereIn('et.employment_status', ['employed','self_employed']),
            'relevance_yes'       => $q->whereIn('et.employment_status', ['employed','self_employed'])->where('et.course_relevance', 'yes'),
            'relevance_partially' => $q->whereIn('et.employment_status', ['employed','self_employed'])->where('et.course_relevance', 'partially'),
            'relevance_no'        => $q->whereIn('et.employment_status', ['employed','self_employed'])->where('et.course_relevance', 'no'),
            default               => null,
        };

        return $q->pluck('a.batch');
    }

    #[Computed]
    public function modalRecords()
    {
        if ($this->modalFilter === 'no_record') {
            $q = DB::table('alumni as a')
                ->whereNull('a.deleted_at')
                ->whereNotExists(fn($sq) => $sq
                    ->from('employment_trackings as et')
                    ->whereColumn('et.alumni_id', 'a.id')
                    ->whereNull('et.deleted_at')
                )
                ->select([
                    'a.id','a.first_name','a.middle_initial','a.last_name','a.suffix',
                    'a.student_id','a.course_code','a.batch','a.profile_photo',
                    'a.email','a.contact_number',
                    DB::raw("NULL as employment_status"),
                    DB::raw("NULL as created_at"),
                    DB::raw("NULL as course_relevance"),
                ]);

            if ($this->modalBatch !== null) $q->where('a.batch', $this->modalBatch);
            if ($this->modalCourse !== '')  $q->where('a.course_code', $this->modalCourse);

            if ($this->modalSearch) {
                $term = '%' . str_replace(['%','_'], ['\%','\_'], $this->modalSearch) . '%';
                $q->where(fn($s) => $s
                    ->where('a.first_name',   'like', $term)
                    ->orWhere('a.last_name',  'like', $term)
                    ->orWhere('a.student_id', 'like', $term)
                    ->orWhere('a.course_code','like', $term)
                    ->orWhere('a.email',      'like', $term)
                );
            }

            return $q->orderBy('a.last_name')
                     ->paginate($this->modalPageSize, ['*'], 'mPage', $this->modalPage);
        }

        $q = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select([
                'a.id','a.first_name','a.middle_initial','a.last_name','a.suffix',
                'a.student_id','a.course_code','a.batch','a.profile_photo',
                'a.email','a.contact_number',
                'et.employment_status','et.created_at','et.course_relevance',
            ]);

        match ($this->modalFilter) {
            'employed'            => $q->where('et.employment_status', 'employed'),
            'self_employed'       => $q->where('et.employment_status', 'self_employed'),
            'unemployed'          => $q->where('et.employment_status', 'unemployed'),
            'employed_all'        => $q->whereIn('et.employment_status', ['employed', 'self_employed']),
            'abroad'              => $q->where('et.work_location', 'abroad')->whereIn('et.employment_status', ['employed','self_employed']),
            'local'               => $q->where('et.work_location', 'local')->whereIn('et.employment_status', ['employed','self_employed']),
            'relevance_yes'       => $q->whereIn('et.employment_status', ['employed','self_employed'])->where(fn($r) => $r->where('et.course_relevance','yes')->orWhere('et.course_relevance','relevant')),
            'relevance_partially' => $q->whereIn('et.employment_status', ['employed','self_employed'])->where(fn($r) => $r->where('et.course_relevance','partially')->orWhere('et.course_relevance','partially_relevant')),
            'relevance_no'        => $q->whereIn('et.employment_status', ['employed','self_employed'])->where(fn($r) => $r->where('et.course_relevance','no')->orWhere('et.course_relevance','not_relevant')),
            default               => null,
        };

        if ($this->modalBatch !== null) $q->where('a.batch', $this->modalBatch);
        if ($this->modalCourse !== '')  $q->where('a.course_code', $this->modalCourse);

        if ($this->modalSearch) {
            $term = '%' . str_replace(['%','_'], ['\%','\_'], $this->modalSearch) . '%';
            $q->where(fn($s) => $s
                ->where('a.first_name',      'like', $term)
                ->orWhere('a.last_name',     'like', $term)
                ->orWhere('a.student_id',    'like', $term)
                ->orWhere('a.course_code',   'like', $term)
                ->orWhere('a.email',         'like', $term)
                ->orWhere('a.contact_number','like', $term)
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

        return match ($this->modalFilter) {
            'employed'            => 'Employed Alumni'               . $suffix,
            'self_employed'       => 'Self-Employed Alumni'          . $suffix,
            'employed_all'        => 'Working Alumni'                . $suffix,
            'unemployed'          => 'Unemployed Alumni'             . $suffix,
            'no_record'           => 'No Employment Record'          . $suffix,
            'abroad'              => 'OFW / Working Abroad'          . $suffix,
            'local'               => 'Working Locally'               . $suffix,
            'relevance_yes'       => 'Course-Relevant Employment'    . $suffix,
            'relevance_partially' => 'Partially Relevant Employment' . $suffix,
            'relevance_no'        => 'Not Relevant to Course'        . $suffix,
            default               => $this->modalBatch
                                        ? 'Batch ' . $this->modalBatch . ' — Employment Records' . $courseSuffix
                                        : ($this->modalCourse
                                            ? $this->modalCourse . ' — All Employment Records'
                                            : 'All Employment Records'),
        };
    }

    #[Computed]
    public function modalIcon(): string
    {
        return match ($this->modalFilter) {
            'employed'            => 'fa-user-tie',
            'self_employed'       => 'fa-store',
            'employed_all'        => 'fa-briefcase',
            'unemployed'          => 'fa-magnifying-glass',
            'no_record'           => 'fa-circle-minus',
            'abroad'              => 'fa-plane-departure',
            'local'               => 'fa-house',
            'relevance_yes'       => 'fa-circle-check',
            'relevance_partially' => 'fa-circle-half-stroke',
            'relevance_no'        => 'fa-circle-xmark',
            default               => 'fa-briefcase',
        };
    }

    public function openModal(string $filter = '', ?int $batch = null, string $course = ''): void
    {
        $allowedFilters = [
            '', 'employed', 'employed_all', 'self_employed', 'unemployed',
            'no_record', 'abroad', 'local',
            'relevance_yes', 'relevance_partially', 'relevance_no',
        ];
        if (!in_array($filter, $allowedFilters, true)) $filter = '';

        $this->modalFilter       = $filter;
        $this->modalBatch        = $batch !== null ? (int)$batch : null;
        $this->modalBatchLocked  = ($batch !== null);
        $this->modalCourse       = strip_tags($course);
        $this->modalCourseLocked = ($course !== '');
        $this->modalPage         = 1;
        $this->modalSearch       = '';
        $this->activeModal       = 'detail';
        unset($this->modalRecords);
        unset($this->availableModalBatchesForFilter);
    }

    public function updatingModalSearch(): void { $this->modalPage = 1; }
    public function updatingModalBatch(): void  { $this->modalPage = 1; }
    public function updatingModalCourse(): void { $this->modalPage = 1; }

    public function clearModalFilters(): void
    {
        $this->modalBatch        = null;
        $this->modalBatchLocked  = false;
        $this->modalCourse       = '';
        $this->modalCourseLocked = false;
        $this->modalSearch       = '';
        $this->modalPage         = 1;
        unset($this->modalRecords);
        unset($this->availableModalBatchesForFilter);
    }

    public function closeModal(): void
    {
        $this->activeModal       = '';
        $this->modalFilter       = '';
        $this->modalBatch        = null;
        $this->modalBatchLocked  = false;
        $this->modalCourse       = '';
        $this->modalCourseLocked = false;
        $this->modalPage         = 1;
        $this->modalSearch       = '';
        unset($this->modalRecords);
        unset($this->availableModalBatchesForFilter);
        $this->computeStats();
        $this->buildCharts();
        unset($this->courseAnalytics);
    }

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

    public function highlight(string $text, string $search): string
    {
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="ar-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
    }

    public function with(): array { return []; }
};
?>

<div @open-emp-modal.window="$wire.openModal($event.detail.filter, $event.detail.batch ?? null, $event.detail.course ?? '')">

{{-- ══ CURSOR-FOLLOW TOOLTIP ══ --}}
<div id="emp-cursor-tip"
     style="position:fixed;pointer-events:none;z-index:99999;display:none;
            background:#1a1a1a;color:#fff;font-size:10px;font-weight:700;
            letter-spacing:.06em;padding:5px 11px;border-radius:7px;
            white-space:nowrap;box-shadow:0 4px 16px rgba(0,0,0,.22);
            transform:translate(14px,-50%);">
</div>

<style>
    @keyframes empPageIn {
        from { opacity:0; transform:translateY(8px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .emp-modal-enter { animation: empPageIn .20s cubic-bezier(.4,0,.2,1) both; }

    @keyframes countUp {
        from { opacity:0; transform:translateY(6px) scale(.95); }
        to   { opacity:1; transform:none; }
    }
    .stat-number-anim { animation: countUp .3s ease both; }

    .emp-pg-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 32px; height: 32px; padding: 0 10px;
        border-radius: 8px; font-size: .75rem; font-weight: 700;
        transition: all .15s; border: 1.5px solid transparent;
    }
    .emp-pg-active { background: rgba(255,255,255,1); color: #7A3F91; border-color: rgba(255,255,255,1); }
    .emp-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
    .emp-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
    .emp-pg-nav:disabled { opacity:.35; cursor:not-allowed; }

    /* Stat cards */
    .stat-card { border: 1.5px solid #E8E0F0 !important; transition: border-color .18s ease, box-shadow .18s ease; }
    .stat-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.07) !important; }
    .stat-card-submitted:hover { border-color: #c4b5fd !important; }
    .stat-card-working:hover   { border-color: #6ee7b7 !important; }
    .stat-card-unemployed:hover{ border-color: #fcd34d !important; }
    .stat-card-nofill:hover    { border-color: #d1d5db !important; }

    /* Location bar fill animation */
    .loc-bar-fill { transition: width .7s cubic-bezier(.4,0,.2,1); }

    /* Batch nav disabled */
    .batch-option-disabled { opacity:.38; cursor:not-allowed; pointer-events:none; }

    /* Cursor-follow tooltip trigger elements */
    [data-tip] { cursor: pointer; }

    /* ── Search highlight (matches Alumni Records / Dashboard) ─────────────── */
    mark.ar-hl {
        background: #BFDBFE;
        color: inherit;
        border-radius: 2px;
        padding: 0 1px;
        font-weight: 700;
    }

    /* ── Filtering progress bar (matches Alumni Records / Dashboard) ───────── */
    .ar-filter-progress-track { height:2px;width:100%;overflow:hidden;background:transparent;position:relative; }
    .ar-filter-progress-bar { position:absolute;top:0;left:0;height:100%;width:40%;border-radius:99px;background:linear-gradient(135deg,#7A3F91,#9b59b6);animation:arFilterProgress 1s ease-in-out infinite; }
    @keyframes arFilterProgress { 0%{left:-40%} 100%{left:100%} }

    /* ── Close button (matches Alumni Records) ──────────────────────────────
       Static, attached tooltip that stays BELOW the X button — no longer
       uses the cursor-follow tooltip system. */
    .emp-close-btn {
        position: relative;
        display: flex; align-items: center; justify-content: center;
        width: 36px; height: 36px;
        border-radius: 10px;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.2);
        color: #fff; cursor: pointer;
        transition: background .15s;
        overflow: visible;
    }
    .emp-close-btn:hover { background: rgba(255,255,255,.22); }
    .emp-close-tip {
        position: absolute;
        top: calc(100% + 8px); right: 0;
        background: rgba(27,6,46,.88);
        color: #fff; font-size: 10px; font-weight: 600;
        letter-spacing: .08em; text-transform: uppercase;
        padding: 4px 10px; border-radius: 7px;
        white-space: nowrap; pointer-events: none;
        opacity: 0; transition: opacity .15s ease;
        z-index: 200; box-shadow: 0 4px 12px rgba(0,0,0,.28);
    }
    .emp-close-tip::before {
        content: '';
        position: absolute; bottom: 100%; right: 10px;
        border: 5px solid transparent;
        border-bottom-color: rgba(27,6,46,.88);
    }
    .emp-close-btn:hover .emp-close-tip { opacity: 1; }

    @media (max-width: 640px) {
        .stat-cards-grid { display:grid!important; grid-template-columns:1fr 1fr!important; gap:8px!important; }
        .stat-cards-grid > div { flex:none!important; min-width:0!important; }
        .stat-cards-grid .text-2xl { font-size:1.25rem!important; }
        .charts-row-1 { display:flex!important; flex-direction:column!important; height:auto!important; }
        .charts-row-1 > div { height:260px!important; }
        .chart-batch-wrap { height:240px!important; }
        .chart-trend-wrap { height:240px!important; }
        .emp-page-header h1 { font-size:1rem!important; }
        .course-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .modal-toolbar { flex-wrap:wrap; gap:6px; }
        .modal-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .modal-footer-inner { flex-direction:column; align-items:flex-start; gap:8px; }
    }
    @media (max-width:400px) {
        .emp-pg-btn { min-width:26px; height:26px; padding:0 6px; font-size:.68rem; }
    }
</style>

{{-- Chart data bridge --}}
<div id="__emp_chart_data" class="hidden"
     data-status="{{ $chartStatusData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-course="{{ $chartCourseData }}"
     data-trend="{{ $chartTrendData }}">
</div>

{{-- ═══════════════════════════════════════════════════════════
     MAIN PAGE
═══════════════════════════════════════════════════════════ --}}
<div class="h-[90vh] max-h-[90vh] overflow-hidden bg-gray-100 flex flex-col">
<div class="flex-1 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
<div class="flex flex-col px-3 sm:px-6 py-3 sm:py-4 gap-3 sm:gap-4 max-w-[1920px] mx-auto w-full box-border">

    {{-- PAGE HEADER --}}
    <div class="flex items-center gap-3 emp-page-header">
        <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-chart-column text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-lg sm:text-xl font-bold leading-tight text-[#111111]">Employment Tracking</h1>
            <p class="text-xs text-[#333333] mt-0.5">System-wide alumni employment analytics &amp; records</p>
        </div>
    </div>

    {{-- ── 4 STAT CARDS ── --}}
    @php
        $totalWorking = $totalEmployed + $totalSelf;
        $empRate      = $totalAlumni   > 0 ? round($totalWorking   / $totalAlumni   * 100, 1) : 0;
        $responseRate = $totalAlumni   > 0 ? round($totalSubmitted / $totalAlumni   * 100, 1) : 0;
        $unempRate    = $totalSubmitted > 0 ? round($totalUnemployed / $totalSubmitted * 100, 1) : 0;
        $nfRate       = $totalAlumni   > 0 ? round($totalNotFilled / $totalAlumni   * 100, 1) : 0;

        $statCards = [
            [''           , $totalSubmitted , 'fa-file-circle-check', 'bg-violet-100' , 'text-violet-700' , 'stat-card-submitted' , 'Submitted'  , $responseRate.'% response rate'],
            ['employed_all', $totalWorking  , 'fa-briefcase'        , 'bg-emerald-100', 'text-emerald-700', 'stat-card-working'   , 'Working'    , $empRate.'% of total alumni'],
            ['unemployed'  , $totalUnemployed,'fa-circle-pause'     , 'bg-amber-100'  , 'text-amber-700'  , 'stat-card-unemployed', 'Unemployed' , $unempRate.'% of submitted'],
            ['no_record'   , $totalNotFilled, 'fa-circle-question'  , 'bg-gray-100'   , 'text-gray-600'   , 'stat-card-nofill'   , 'No Record'  , $nfRate.'% of total alumni'],
        ];
    @endphp
    <div class="stat-cards-grid flex gap-3 flex-wrap lg:flex-nowrap">
        @foreach($statCards as [$filter, $count, $icon, $iconBg, $iconColor, $hoverClass, $label, $rate])
        <div wire:click="openModal('{{ $filter }}')"
             data-tip="View {{ $label }}"
             class="group relative bg-white rounded-2xl p-3 sm:p-4 flex items-center gap-3
                    shadow-sm cursor-pointer flex-1 min-w-0 stat-card {{ $hoverClass }}">
            <div class="w-9 h-9 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shrink-0 {{ $iconBg }}">
                <i class="fa-solid {{ $icon }} {{ $iconColor }}" style="font-size:.9rem;"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xl sm:text-2xl font-black leading-none text-[#111111] stat-number-anim">{{ number_format($count) }}</p>
                <p class="text-[.7rem] sm:text-xs font-bold text-[#111111] mt-1">{{ $label }}</p>
                <p class="text-[.62rem] sm:text-[.68rem] font-semibold mt-0.5 {{ $iconColor }}">{{ $rate }}</p>
            </div>
            <div class="shrink-0 hidden sm:block">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg {{ $iconBg }} opacity-60
                             group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-arrow-right {{ $iconColor }}" style="font-size:.6rem;"></i>
                </span>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── CHARTS ROW 1 ── --}}
    <div class="charts-row-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" style="height:300px;">

        {{-- Status Donut --}}
        <div onclick="empOpenModal('','',null)"
             data-tip="View All Employment Records"
             class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all cursor-pointer flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-[#10b981] shrink-0"></span>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest leading-tight">Employment Status</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5">Overall breakdown of alumni job status</span>
                </div>
            </div>
            <div class="flex-1 min-h-0 flex items-center justify-center p-2" wire:ignore>
                <canvas id="chartStatus" style="max-height:100%;max-width:100%;"></canvas>
            </div>
        </div>

        {{-- Location Split Widget --}}
        @php
            $locTotal  = $totalLocal + $totalAbroad;
            $localPct  = $locTotal > 0 ? round($totalLocal  / $locTotal * 100, 1) : 0;
            $abroadPct = $locTotal > 0 ? round($totalAbroad / $locTotal * 100, 1) : 0;
        @endphp
        <div class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-[#7a3f91] shrink-0"></span>
                <div class="flex flex-col min-w-0 flex-1">
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest leading-tight">Work Location</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5">Where working alumni are based</span>
                </div>
                <span class="text-[.65rem] font-bold text-[#7a3f91] bg-[#f0e6f8] px-2 py-0.5 rounded-full shrink-0">{{ number_format($locTotal) }} working</span>
            </div>
            <div class="flex-1 flex flex-col justify-center px-4 sm:px-5 py-3 gap-3">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#7a3f91;">{{ number_format($totalLocal) }}</p>
                        <p class="text-[11px] font-bold text-[#111111] mt-1">Local</p>
                        <p class="text-[11px] font-black mt-0.5" style="color:#7a3f91;">{{ $localPct }}%</p>
                    </div>
                    <div class="text-[10px] font-semibold text-[#AAAAAA] self-center pb-4">-</div>
                    <div class="text-right">
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#c084fc;">{{ number_format($totalAbroad) }}</p>
                        <p class="text-[11px] font-bold text-[#111111] mt-1">OFW / Abroad</p>
                        <p class="text-[11px] font-black mt-0.5" style="color:#c084fc;">{{ $abroadPct }}%</p>
                    </div>
                </div>
                <div class="h-3 rounded-full overflow-hidden bg-gray-100 flex">
                    @if($locTotal > 0)
                        <div class="h-full loc-bar-fill" style="width:{{ $localPct }}%; background:#7a3f91;"></div>
                        <div class="h-full loc-bar-fill" style="width:{{ $abroadPct }}%; background:#c084fc;"></div>
                    @else
                        <div class="h-full w-full bg-gray-200"></div>
                    @endif
                </div>
                <div class="flex gap-2">
                    <button onclick="empOpenModal('local','',null)"
                            data-tip="View Local Workers"
                            class="flex-1 py-[7px] rounded-xl text-[11px] font-bold border border-[#7a3f91]/20
                                   bg-[#F9F7FC] text-[#7a3f91] hover:bg-[#7a3f91] hover:text-white hover:border-[#7a3f91]
                                   transition-all duration-150 cursor-pointer">
                        View Local
                    </button>
                    <button onclick="empOpenModal('abroad','',null)"
                            data-tip="View OFW / Abroad"
                            class="flex-1 py-[7px] rounded-xl text-[11px] font-bold border border-purple-200
                                   bg-purple-50 text-[#c084fc] hover:bg-[#c084fc] hover:text-white hover:border-[#c084fc]
                                   transition-all duration-150 cursor-pointer">
                        View Abroad
                    </button>
                </div>
            </div>
        </div>

        {{-- Relevance Donut --}}
        <div class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all cursor-pointer flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest leading-tight">Job Relevance</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5">Alumni whose jobs match their course</span>
                </div>
            </div>
            <div class="flex-1 min-h-0 flex items-center justify-center p-2" wire:ignore>
                <canvas id="chartRelevance" style="max-height:100%;max-width:100%;"></canvas>
            </div>
        </div>

        {{-- Top Courses Horizontal Bar --}}
        <div onclick="empOpenModal('employed_all','',null)"
             data-tip="View All Working Alumni"
             class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all cursor-pointer flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0"></span>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest leading-tight">Top Courses</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5">Courses with most employed alumni</span>
                </div>
            </div>
            <div class="flex-1 min-h-0 p-2" wire:ignore>
                <canvas id="chartCourse" style="max-height:100%;width:100%;"></canvas>
            </div>
        </div>

    </div>

    {{-- ── CHARTS ROW 2: Stacked Batch Bar ── --}}
    <div class="chart-batch-wrap bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                transition-all flex flex-col overflow-hidden" style="height:280px;">
        <div class="px-3 sm:px-[14px] py-2 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
            <div class="flex items-center gap-[7px] min-w-0">
                <div class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></div>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] sm:text-[.78rem] font-bold text-[#111111] uppercase tracking-[.06em] leading-tight">Employment Breakdown by Batch Year</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5 hidden sm:block">Number of employed, self-employed &amp; unemployed per batch</span>
                </div>
            </div>
            <div id="batchNavControls" class="hidden items-center gap-2 shrink-0 ml-2">
                <button id="batchPrev"
                        class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91]
                               cursor-pointer flex items-center justify-center
                               hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                               disabled:opacity-35 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                </button>
                <span id="batchPageInfo" class="text-[.74rem] font-semibold text-[#333333] whitespace-nowrap"></span>
                <button id="batchNext"
                        class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91]
                               cursor-pointer flex items-center justify-center
                               hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                               disabled:opacity-35 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i>
                </button>
            </div>
        </div>
        <div class="flex-1 min-h-0 p-[10px]" wire:ignore>
            <canvas id="chartBatch" style="width:100%;height:100%;"></canvas>
        </div>
    </div>

    {{-- ── CHARTS ROW 3: Employment Rate Trend Line ── --}}
    <div class="chart-trend-wrap bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                transition-all flex flex-col overflow-hidden" style="height:260px;">
        <div class="px-3 sm:px-[14px] py-2 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
            <div class="flex items-center gap-[7px] min-w-0">
                <div class="w-2 h-2 rounded-full bg-[#7a3f91] shrink-0"></div>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] sm:text-[.78rem] font-bold text-[#111111] uppercase tracking-[.06em] leading-tight">Employment Rate Trend per Batch Year</span>
                    <span class="text-[.62rem] text-[#888888] font-medium leading-tight mt-0.5 hidden sm:block">% of alumni (employed + self-employed) out of total per batch</span>
                </div>
            </div>
            <div id="trendNavControls" class="hidden items-center gap-2 shrink-0 ml-2">
                <button id="trendPrev"
                        class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91]
                               cursor-pointer flex items-center justify-center
                               hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                               disabled:opacity-35 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                </button>
                <span id="trendPageInfo" class="text-[.74rem] font-semibold text-[#333333] whitespace-nowrap"></span>
                <button id="trendNext"
                        class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91]
                               cursor-pointer flex items-center justify-center
                               hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                               disabled:opacity-35 disabled:cursor-not-allowed">
                    <i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i>
                </button>
            </div>
        </div>
        <div class="flex-1 min-h-0 px-3 sm:px-4 py-2" wire:ignore>
            <canvas id="chartTrend" style="width:100%;height:100%;"></canvas>
        </div>
    </div>

    {{-- ── COURSE BREAKDOWN TABLE ── --}}
    <div class="bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden shadow-sm">
        <div class="flex items-center justify-between px-3 sm:px-5 py-2.5 border-b border-[#E8E0F0] bg-[#F9F7FC]">
            <div class="flex items-center gap-2.5">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#7a3f91;">
                    <i class="fas fa-table text-white" style="font-size:.65rem;"></i>
                </div>
                <div>
                    <p class="text-xs sm:text-sm font-bold leading-tight text-[#111111]">Course Breakdown</p>
                    <p class="text-[10px] text-[#333333] hidden sm:block">Employment rate per course — click any row to view records</p>
                </div>
            </div>
            <span class="text-[11px] font-semibold px-2 py-1 rounded-lg bg-[#F9F7FC] text-[#7a3f91] border border-[#E8E0F0] shrink-0">
                {{ count($this->courseAnalytics) }} courses
            </span>
        </div>
        <div class="course-table-wrap max-h-[450px] overflow-y-auto overflow-x-auto" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
            <table class="w-full border-collapse" style="min-width:580px;">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-[#f5f0fa] border-b-2 border-[#E8E0F0]">
                        <th class="pl-3 sm:pl-4 pr-3 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-[#111111]">Course</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-[#111111]">Total</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-emerald-700">Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-blue-700">Self-Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-amber-700">Unemployed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-[#AAAAAA]">No Record</th>
                        <th class="px-3 sm:px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-[#7a3f91]" style="min-width:160px;">Employment Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->courseAnalytics as $cr)
                    @php $courseFullName = $this->courseMap[$cr->course_code ?? ''] ?? null; @endphp
                    <tr class="hover:bg-[#F5F0FA] transition-colors cursor-pointer"
                        data-tip="View {{ $cr->course_code ?? '' }} Records"
                        onclick="empOpenModal('employed_all','{{ $cr->course_code }}',null)">
                        <td class="pl-3 sm:pl-4 pr-3 py-2.5">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold bg-[#F9F7FC] text-[#7a3f91]">
                                <i class="fas fa-graduation-cap text-[9px]"></i>
                                {{ $cr->course_code ?? '—' }}
                            </span>
                            {{-- FIX 2: Full course name hidden on mobile, visible sm and up --}}
                            @if($courseFullName)
                                <p class="text-[11px] mt-0.5 font-semibold text-[#111111] leading-tight hidden sm:block">{{ $courseFullName }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="text-xs font-bold text-[#111111]">{{ number_format($cr->total) }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="text-xs font-semibold text-emerald-700">{{ number_format($cr->employed) }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="text-xs font-semibold text-blue-700">{{ number_format($cr->self_employed) }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="text-xs font-semibold text-amber-700">{{ number_format($cr->unemployed) }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <span class="text-xs font-semibold text-[#AAAAAA]">{{ number_format($cr->not_filled) }}</span>
                        </td>
                        <td class="px-3 sm:px-4 py-2.5" style="min-width:160px;">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 rounded-full bg-[#E8E0F0] overflow-hidden">
                                    <div class="h-full rounded-full bg-[#10b981] transition-all duration-500"
                                         style="width:{{ $cr->emp_rate }}%;"></div>
                                </div>
                                <span class="text-[11px] font-bold w-9 text-right shrink-0 text-[#111111]">
                                    {{ $cr->emp_rate }}%
                                </span>
                            </div>
                            <p class="text-[10px] mt-0.5 text-[#555555]">{{ $cr->response_rate }}% response</p>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center">
                            <p class="text-sm font-semibold text-[#333333]">No course data available</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>
</div>


{{-- ═══════════════════════════════════════════════════════════
     DETAIL FULL-SCREEN MODAL
═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'detail')
@php
    $records    = $this->modalRecords;
    $isNoRecord = $this->modalFilter === 'no_record';

    $statusBadge = [
        'employed'      => ['Employed',     'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'self_employed' => ['Self-Employed', 'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',    'text-amber-700 bg-amber-50 border-amber-200'],
    ];

    $relevanceBadge = [
        'yes'               => ['Relevant',          'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'relevant'          => ['Relevant',          'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'partially'         => ['Partially Relevant','text-amber-700 bg-amber-50 border-amber-200'],
        'partially_relevant'=> ['Partially Relevant','text-amber-700 bg-amber-50 border-amber-200'],
        'no'                => ['Not Relevant',      'text-red-700 bg-red-50 border-red-200'],
        'not_relevant'      => ['Not Relevant',      'text-red-700 bg-red-50 border-red-200'],
    ];

    $isRelevanceFilter = in_array($modalFilter, ['relevance_yes','relevance_partially','relevance_no']);

    $hasSubFilters  = $modalBatch !== null || $modalCourse !== '' || $modalSearch !== '';
    $hasChipsToShow = ($modalBatch !== null && !$modalBatchLocked)
                   || ($modalCourse !== '' && !$modalCourseLocked)
                   || $modalSearch !== '';

    $smartBatches = $this->availableModalBatchesForFilter;

    $rTotal    = $records->total();
    $rPp       = $records->perPage();
    $rCp       = $records->currentPage();
    $rLastPage = $records->lastPage();
    $rFrom     = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
    $rTo       = min($rCp * $rPp, $rTotal);
    $rPgStart  = max(1, $rCp - 2);
    $rPgEnd    = min($rLastPage, $rCp + 2);

    $filterLabel = match($modalFilter) {
        'employed'            => 'Employed',
        'self_employed'       => 'Self-Employed',
        'employed_all'        => 'Working Alumni',
        'unemployed'          => 'Unemployed',
        'no_record'           => 'No Record',
        'abroad'              => 'OFW / Abroad',
        'local'               => 'Local',
        'relevance_yes'       => 'Relevant to Course',
        'relevance_partially' => 'Partially Relevant',
        'relevance_no'        => 'Not Relevant',
        default               => 'All Records',
    };
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 emp-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- ── Header ── --}}
    <div class="flex items-center justify-between px-4 sm:px-6 lg:px-10 py-3 sm:py-3.5 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->modalIcon }} text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <h2 class="text-white font-semibold text-sm sm:text-base leading-tight truncate">{{ $this->modalTitle }}</h2>
                <p class="text-white/60 text-[11px] sm:text-xs">
                    {{ number_format($records->total()) }} record(s)
                    @if($modalCourse) &middot; {{ $modalCourse }} @endif
                    @if($modalBatch) &middot; Batch {{ $modalBatch }} @endif
                </p>
            </div>
        </div>

        {{-- FIXED: static close-button tooltip that stays below the X, matching Alumni Records --}}
        <button wire:click="closeModal" class="emp-close-btn shrink-0 ml-3">
            <span class="emp-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    {{-- ── Toolbar ── --}}
    <div class="px-3 sm:px-6 lg:px-10 py-2.5 sm:py-3 bg-white border-b border-gray-200 shrink-0 transition-opacity duration-200"
         wire:loading.class="opacity-60" wire:target="modalSearch,modalBatch,modalCourse">
        <div class="modal-toolbar flex flex-wrap gap-2 items-center">

            <span class="text-[10px] font-bold tracking-widest uppercase text-[#7A3F91] shrink-0 mr-1">FILTERS</span>

            {{-- Active filter pill (locked, non-clickable) --}}
            <span class="inline-flex items-center px-4 py-[7px] rounded-full text-xs font-semibold text-white border-transparent shadow-sm cursor-default"
                  style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                {{ $filterLabel }}
            </span>

            {{-- Search --}}
            <div class="relative" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.modalSearch??''; $wire.$watch('modalSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[#AAAAAA] pointer-events-none">
                    <i class="fas fa-search" style="font-size:.7rem;"></i>
                </span>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('modalSearch',q)"
                       placeholder="Search name, ID, email…"
                       class="pl-8 pr-3 py-[7px] border border-[#E0E0E0] rounded-full text-xs bg-white text-[#111111]
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all w-44 sm:w-56"
                       autocomplete="off">
            </div>

            {{-- Batch Dropdown --}}
            @if(!$modalBatchLocked)
            <div class="relative" x-data="{ open:false }" @click.outside="open=false">
                <button type="button" @click="open=!open"
                        :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.modalBatch !== null }"
                        class="inline-flex items-center gap-2 px-3 py-[7px] border border-[#E0E0E0] rounded-full
                               text-xs font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                               hover:border-[#c49ed8] hover:text-[#7A3F91] transition-all duration-150">
                    @if($modalBatch) Batch {{ $modalBatch }} @else All Batches @endif
                    <i class="fas fa-chevron-down text-[9px] opacity-50" :class="{'rotate-180':open}"></i>
                </button>
                <div x-show="open" x-transition
                     class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                            bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                            shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1
                            [scrollbar-width:thin] [scrollbar-color:#d4b8e8_transparent]"
                     style="display:none;">
                    <button type="button" @click="$wire.set('modalBatch',null); open=false"
                            class="block w-full px-[10px] py-[7px] rounded-[7px] text-left text-[.78rem] font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors
                                   {{ $modalBatch === null ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">
                        All Batches
                        @if($smartBatches->count() < $this->availableBatches->count())
                            <span class="ml-1 text-[10px] opacity-70">({{ $smartBatches->count() }} with records)</span>
                        @endif
                    </button>
                    @foreach($this->availableBatches as $bYear)
                    @php $hasRecords = $smartBatches->contains($bYear); @endphp
                    <button type="button"
                            @if($hasRecords) @click="$wire.set('modalBatch',{{ $bYear }}); open=false" @endif
                            class="block w-full px-[10px] py-[7px] rounded-[7px] text-left text-[.78rem] font-semibold transition
                                   {{ $modalBatch == $bYear ? 'bg-[#F0E6F8] text-[#7A3F91]' : 'text-[#111111]' }}
                                   {{ $hasRecords ? 'hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent' : 'batch-option-disabled' }}">
                        Batch {{ $bYear }}
                        @if(!$hasRecords)<span class="ml-1 text-[10px] font-normal text-[#AAAAAA]">— no records</span>@endif
                    </button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-2 px-3 py-[7px] rounded-full text-xs font-semibold border border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]">
                Batch {{ $modalBatch }}
            </span>
            @endif

            {{-- Course Dropdown --}}
            @if(!$modalCourseLocked)
            <div class="relative" x-data="{ open:false }" @click.outside="open=false">
                <button type="button" @click="open=!open"
                        :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.modalCourse !== '' }"
                        class="inline-flex items-center gap-2 px-3 py-[7px] border border-[#E0E0E0] rounded-full
                               text-xs font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                               hover:border-[#c49ed8] hover:text-[#7A3F91] transition-all duration-150">
                    @if($modalCourse) {{ $modalCourse }} @else All Courses @endif
                    <i class="fas fa-chevron-down text-[9px] opacity-50" :class="{'rotate-180':open}"></i>
                </button>
                <div x-show="open" x-transition
                     class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                            bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                            shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1
                            [scrollbar-width:thin] [scrollbar-color:#d4b8e8_transparent]"
                     style="display:none;">
                    <button type="button" @click="$wire.set('modalCourse',''); open=false"
                            class="block w-full px-[10px] py-[7px] rounded-[7px] text-left text-[.78rem] font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors
                                   {{ $modalCourse === '' ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">
                        All Courses
                    </button>
                    @foreach($this->availableCourses as $cCode)
                    <button type="button" @click="$wire.set('modalCourse','{{ $cCode }}'); open=false"
                            class="block w-full px-[10px] py-[7px] rounded-[7px] text-left text-[.78rem] font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors
                                   {{ $modalCourse === $cCode ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">
                        {{ $cCode }}
                    </button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-2 px-3 py-[7px] rounded-full text-xs font-semibold border border-blue-400 bg-blue-50 text-blue-700">
                {{ $modalCourse }}
            </span>
            @endif

            @if($hasSubFilters && $hasChipsToShow)
            <button wire:click="clearModalFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-[7px] rounded-full text-xs font-semibold border
                           border-[#E0E0E0] bg-white text-[#555555] hover:border-red-300 hover:text-red-500
                           transition-all duration-150 cursor-pointer">
                <i class="fas fa-rotate-left text-[10px]"></i>
                Reset
            </button>
            @endif

        </div>
    </div>

    {{-- Filtering progress bar --}}
    <div class="ar-filter-progress-track" wire:loading wire:target="modalSearch,modalBatch,modalCourse">
        <div class="ar-filter-progress-bar"></div>
    </div>

    {{-- ── Table ── --}}
    <div class="modal-table-wrap flex-1 overflow-y-auto overflow-x-auto min-h-0 transition-opacity duration-200"
         style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;"
         wire:loading.class="opacity-40" wire:target="modalSearch,modalBatch,modalCourse">
        <table class="w-full border-collapse" style="min-width:700px;">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-4 sm:pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-12">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Name</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Student ID</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Course</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Batch</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">
                        {{ $isRelevanceFilter ? 'Relevance' : 'Status' }}
                    </th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Email</th>
                    <th class="px-4 pr-6 lg:pr-10 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Contact No.</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($records as $idx => $row)
                @php
                    $rowNum = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                    $badge  = $isNoRecord ? null : ($statusBadge[$row->employment_status ?? ''] ?? null);
                    $relBadge = isset($row->course_relevance) ? ($relevanceBadge[$row->course_relevance] ?? null) : null;
                    $photo  = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName  = $this->formatName($row->first_name??'',$row->middle_initial??'',$row->last_name??'',$row->suffix??'');
                @endphp
                <tr class="bg-white hover:bg-[#F5F0FA] transition-colors duration-100">
                    <td class="pl-4 sm:pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold text-[#7A3F91]/40">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ e($row->first_name ?? '') }}"
                                 class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <p class="text-sm font-semibold truncate uppercase text-[#111111]">{!! $this->highlight($dName, $this->modalSearch) !!}</p>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-sm font-mono font-semibold text-[#111111]">{!! $row->student_id ? $this->highlight($row->student_id, $this->modalSearch) : '—' !!}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-[#F9F7FC] text-[#7a3f91]">
                            {!! $row->course_code ? $this->highlight($row->course_code, $this->modalSearch) : '—' !!}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-sm font-semibold text-[#111111]">{{ $row->batch ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if($isNoRecord || is_null($row->employment_status ?? null))
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border text-[#333333] bg-gray-50 border-gray-200">
                                No Record
                            </span>
                        @elseif($isRelevanceFilter && $relBadge)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $relBadge[1] }}">
                                {{ $relBadge[0] }}
                            </span>
                        @elseif($badge)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                {{ $badge[0] }}
                            </span>
                        @else
                            <span class="text-xs text-[#AAAAAA]">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($row->email ?? null)
                            <span class="text-sm text-[#333333] truncate block max-w-[200px]">{!! $this->highlight(strtolower($row->email), $this->modalSearch) !!}</span>
                        @else
                            <span class="text-xs text-[#AAAAAA]">—</span>
                        @endif
                    </td>
                    <td class="px-4 pr-6 lg:pr-10 py-3">
                        @if($row->contact_number ?? null)
                            <span class="text-sm text-[#333333]">{!! $this->highlight($row->contact_number, $this->modalSearch) !!}</span>
                        @else
                            <span class="text-xs text-[#AAAAAA]">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No records found</p>
                            <p class="text-xs text-[#555555]">Try adjusting your filters or search terms</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Footer Pagination ── --}}
    <div class="px-3 sm:px-4 py-2.5 shrink-0" style="background:#7A3F91;">
        <div class="modal-footer-inner flex flex-row items-center justify-between gap-2 flex-wrap">
            <p class="text-white/70 text-xs sm:text-sm whitespace-nowrap">
                Showing <strong class="text-white font-semibold">{{ $rFrom }}–{{ $rTo }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($rTotal) }}</strong>
            </p>
            <div class="flex items-center gap-1 flex-wrap">
                <button @if($rCp <= 1) disabled @endif
                        wire:click="$set('modalPage', {{ max(1,$rCp-1) }})"
                        class="emp-pg-btn emp-pg-nav">
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>
                @if($rPgStart > 1)
                    <button wire:click="$set('modalPage',1)" class="emp-pg-btn emp-pg-nav">1</button>
                    @if($rPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif
                @for($p = $rPgStart; $p <= $rPgEnd; $p++)
                    @if($p === $rCp)
                        <span class="emp-pg-btn emp-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="$set('modalPage',{{ $p }})" class="emp-pg-btn emp-pg-nav">{{ $p }}</button>
                    @endif
                @endfor
                @if($rPgEnd < $rLastPage)
                    @if($rPgEnd < $rLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="$set('modalPage',{{ $rLastPage }})" class="emp-pg-btn emp-pg-nav">{{ $rLastPage }}</button>
                @endif
                <button @if($rCp >= $rLastPage) disabled @endif
                        wire:click="$set('modalPage',{{ min($rLastPage,$rCp+1) }})"
                        class="emp-pg-btn emp-pg-nav">
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $rCp }} / {{ $rLastPage }}</span>
            </div>
        </div>
    </div>

</div>
@endif


{{-- ══ CHARTS + CURSOR TOOLTIP SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    // ── Cursor-follow tooltip ─────────────────────────────────────────────────
    (function initCursorTip() {
        var tip = document.getElementById('emp-cursor-tip');
        if (!tip) return;

        var currentTarget = null;

        function showTip(el, e) {
            // data-tip-noicon => plain text tooltip, no eye icon (no longer used by Close button)
            var noIconText = el.getAttribute('data-tip-noicon');
            var text = noIconText || el.getAttribute('data-tip');
            if (!text) return;
            tip.innerHTML = noIconText
                ? text
                : '<i class="fas fa-eye" style="font-size:.6rem;margin-right:5px;"></i>' + text;
            tip.style.display = 'block';
            moveTip(e);
        }

        function moveTip(e) {
            var x = e.clientX;
            var y = e.clientY;
            var tw = tip.offsetWidth;
            var vw = window.innerWidth;
            var left = x + 14;
            if (left + tw > vw - 8) left = x - tw - 14;
            tip.style.left = left + 'px';
            tip.style.top  = y + 'px';
        }

        function hideTip() {
            tip.style.display = 'none';
            currentTarget = null;
        }

        document.addEventListener('mouseover', function(e) {
            var el = e.target.closest('[data-tip], [data-tip-noicon]');
            if (el && el !== currentTarget) {
                currentTarget = el;
                showTip(el, e);
            } else if (!el) {
                hideTip();
            }
        }, true);

        document.addEventListener('mousemove', function(e) {
            if (currentTarget) moveTip(e);
        }, true);

        document.addEventListener('mouseout', function(e) {
            if (currentTarget && !currentTarget.contains(e.relatedTarget)) {
                hideTip();
            }
        }, true);

        document.addEventListener('scroll', hideTip, true);
    })();

    // ── Chart helpers ─────────────────────────────────────────────────────────
    var BAR_COLORS = [
        { bg: 'rgba(16,185,129,0.82)',  border: '#10b981' },
        { bg: 'rgba(37,99,235,0.80)',   border: '#2563eb' },
        { bg: 'rgba(245,158,11,0.82)',  border: '#f59e0b' },
        { bg: 'rgba(122,63,145,0.82)',  border: '#7A3F91' },
        { bg: 'rgba(239,68,68,0.80)',   border: '#ef4444' },
        { bg: 'rgba(20,184,166,0.80)',  border: '#14b8a6' },
        { bg: 'rgba(168,85,247,0.80)',  border: '#a855f7' },
        { bg: 'rgba(59,130,246,0.80)',  border: '#3b82f6' },
    ];

    var BATCH_PAGE_SIZE = 8;
    var batchPageIndex  = 0;
    var allBatchData    = null;

    var TREND_PAGE_SIZE = 8;
    var trendPageIndex  = 0;
    var allTrendData    = null;

    window.empOpenModal = function (filter, course, batch) {
        window.dispatchEvent(new CustomEvent('open-emp-modal', {
            detail: { filter: filter || '', batch: batch || null, course: course || '' }
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
                relevance: JSON.parse(el.getAttribute('data-relevance') || 'null'),
                batch:     JSON.parse(el.getAttribute('data-batch')     || 'null'),
                course:    JSON.parse(el.getAttribute('data-course')    || 'null'),
                trend:     JSON.parse(el.getAttribute('data-trend')     || 'null'),
            };
        } catch (e) { return null; }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Donut builder
    // ─────────────────────────────────────────────────────────────────────────
    var MIN_ARC_DEG = 18;

    function buildDisplayData(rawData) {
        var total   = rawData.reduce(function(a,b){return a+b;},0);
        if (total === 0) return rawData.slice();
        var nonZero = rawData.filter(function(v){return v>0;}).length;
        if (nonZero === 0) return rawData.slice();
        var reserved  = MIN_ARC_DEG * nonZero;
        var remainder = Math.max(0, 360 - reserved);
        var nzSum     = rawData.reduce(function(a,v){return a+(v>0?v:0);},0);
        return rawData.map(function(v) {
            if (v === 0) return 0;
            return MIN_ARC_DEG + (nzSum > 0 ? (v / nzSum) * remainder : 0);
        });
    }

    function buildDonut(id, data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        safeDestroy(id);

        var rawData  = data.data.map(function(v){ return Number(v) || 0; });
        var total    = rawData.reduce(function(a, b){ return a + b; }, 0);
        if (total === 0) return;
        var dispData = buildDisplayData(rawData);

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data:            dispData,
                    rawValues:       rawData,
                    backgroundColor: data.colors,
                    borderColor:     '#ffffff',
                    borderWidth:     2.5,
                    spacing:         2,
                    hoverOffset:     7,
                    hoverBorderWidth:3,
                    hoverBorderColor:'#ffffff',
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '60%',
                layout: { padding: { top:4, bottom:2, left:4, right:4 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size:9, weight:'600' }, color:'#111111',
                            padding:5, usePointStyle:true, pointStyleWidth:7, boxHeight:7,
                            generateLabels: function(chart) {
                                var d = chart.data;
                                if (!d.labels.length || !d.datasets.length) return [];
                                return d.labels.map(function(label, i) {
                                    var ds  = d.datasets[0];
                                    var raw = ds.rawValues ? ds.rawValues[i] : dispData[i];
                                    var pct = total > 0 ? Math.round(raw/total*100) : 0;
                                    var txt = label.length > 13 ? label.substring(0,12)+'…' : label;
                                    return {
                                        text:       txt + ' · ' + pct + '%',
                                        fillStyle:  ds.backgroundColor[i],
                                        strokeStyle:ds.backgroundColor[i],
                                        lineWidth:  0,
                                        hidden:     !chart.getDataVisibility(i),
                                        index:      i,
                                        pointStyle: 'circle',
                                    };
                                });
                            },
                        },
                        onClick: function(e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart, index = legendItem.index;
                            chart.getDataVisibility(index) ? chart.hide(index) : chart.show(index);
                            chart.update();
                        },
                    },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            title: function() { return ''; },
                            label: function(ctx) {
                                var ds  = ctx.dataset;
                                var raw = ds.rawValues ? ds.rawValues[ctx.dataIndex] : ctx.parsed;
                                var pct = total > 0 ? Math.round(raw / total * 100) : 0;
                                return '  ' + ctx.label + ': ' + raw.toLocaleString() + ' (' + pct + '%)';
                            },
                        },
                        padding: 10,
                        bodyFont: { size:12, weight:'600' },
                    },
                },
                onClick: function(event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (!elements || !elements.length) return;
                    var idx    = elements[0].index;
                    var filter = (data.filters && data.filters[idx]) ? data.filters[idx] : '';
                    if (!filter) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal', {
                        detail: { filter: filter, batch: null, course: '' }
                    }));
                },
            },
        });
    }

    // ── Batch Stacked Bar ─────────────────────────────────────────────────────
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
        safeDestroy('chartBatch');

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: slice.labels,
                datasets: [
                    { label:'Employed',     data:slice.employed,   backgroundColor:'rgba(16,185,129,0.85)', borderColor:'#10b981', borderWidth:1 },
                    { label:'Self-Employed',data:slice.self_emp,   backgroundColor:'rgba(37,99,235,0.80)',  borderColor:'#2563eb', borderWidth:1 },
                    { label:'Unemployed',   data:slice.unemployed, backgroundColor:'rgba(245,158,11,0.82)', borderColor:'#f59e0b', borderWidth:1 },
                ],
            },
            options: {
                responsive:true, maintainAspectRatio:false, animation:{duration:300},
                plugins: {
                    legend: {
                        display:true, position:'top',
                        labels: { font:{size:10,weight:'600'}, color:'#111111', usePointStyle:true, pointStyleWidth:8, boxHeight:8, padding:14 },
                    },
                    tooltip: {
                        callbacks: {
                            title: function(items) { return 'Batch '+items[0].label; },
                            label: function(ctx) { return '  '+ctx.dataset.label+': '+ctx.parsed.y; },
                            footer: function(items) {
                                var i   = items[0].dataIndex;
                                var tot = (parseInt(slice.employed[i])||0)+(parseInt(slice.self_emp[i])||0)+(parseInt(slice.unemployed[i])||0);
                                return 'Total submitted: '+tot;
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked:true, grid:{display:false}, ticks:{font:{size:11,weight:'600'},color:'#111111'} },
                    y: { stacked:true, grid:{color:'#f3f4f6'}, ticks:{font:{size:10,weight:'500'},color:'#333333',precision:0}, beginAtZero:true },
                },
                onClick: function(event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (!elements || !elements.length) return;
                    var batch = slice.labels[elements[0].index];
                    if (batch === undefined || batch === null) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal',{detail:{filter:'',batch:parseInt(batch),course:''}}));
                },
            },
        });

        var totalBatches = data.labels.length;
        var totalPages   = Math.ceil(totalBatches / BATCH_PAGE_SIZE);
        var currentPage  = Math.floor(startIdx / BATCH_PAGE_SIZE) + 1;
        var navEl   = document.getElementById('batchNavControls');
        var prevBtn = document.getElementById('batchPrev');
        var nextBtn = document.getElementById('batchNext');
        var infoEl  = document.getElementById('batchPageInfo');

        if (navEl && totalPages > 1) {
            navEl.classList.remove('hidden'); navEl.classList.add('flex');
            if (infoEl)  infoEl.textContent = currentPage+' / '+totalPages;
            if (prevBtn) prevBtn.disabled    = (startIdx <= 0);
            if (nextBtn) nextBtn.disabled    = (startIdx + BATCH_PAGE_SIZE >= totalBatches);
        } else if (navEl) {
            navEl.classList.add('hidden'); navEl.classList.remove('flex');
        }
    }

    // ── Employment Rate Trend Line ────────────────────────────────────────────
    function sliceTrend(data, startIdx) {
        var end = startIdx + TREND_PAGE_SIZE;
        return {
            labels: data.labels.slice(startIdx, end),
            rates:  data.rates.slice(startIdx, end),
            totals: data.totals ? data.totals.slice(startIdx, end) : [],
        };
    }

    function buildTrendLine(data, startIdx) {
        if (!data || !data.labels || !data.labels.length) return;
        var slice  = sliceTrend(data, startIdx);
        var canvas = document.getElementById('chartTrend');
        if (!canvas) return;
        safeDestroy('chartTrend');

        new Chart(canvas, {
            type:'line',
            data: {
                labels: slice.labels,
                datasets: [{
                    label:'Employment Rate (%)',
                    data:slice.rates,
                    borderColor:'#7A3F91',
                    backgroundColor:'rgba(122,63,145,0.07)',
                    borderWidth:2.5,
                    pointBackgroundColor:'#7A3F91',
                    pointBorderColor:'#fff',
                    pointBorderWidth:2,
                    pointRadius:5,
                    pointHoverRadius:7,
                    fill:true,
                    tension:0.35,
                }],
            },
            options: {
                responsive:true, maintainAspectRatio:false, animation:{duration:300},
                plugins: {
                    legend:{display:false},
                    tooltip: {
                        callbacks: {
                            title: function(items){return 'Batch '+items[0].label;},
                            label: function(ctx){
                                var lines=['  Employment Rate: '+ctx.parsed.y+'%'];
                                if(slice.totals && slice.totals[ctx.dataIndex]!==undefined)
                                    lines.push('  Total Alumni: '+slice.totals[ctx.dataIndex]);
                                return lines;
                            },
                        },
                    },
                },
                scales: {
                    x:{grid:{display:false},ticks:{font:{size:10,weight:'600'},color:'#111111'}},
                    y:{
                        grid:{color:'#f3f4f6'},
                        ticks:{font:{size:10,weight:'500'},color:'#333333',callback:function(val){return val+'%';}},
                        min:0,max:100,
                    },
                },
                onClick: function(event,elements){
                    if(event&&event.native) event.native.stopPropagation();
                    if(!elements||!elements.length) return;
                    var batch=slice.labels[elements[0].index];
                    if(batch===undefined||batch===null) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal',{detail:{filter:'',batch:parseInt(batch),course:''}}));
                },
            },
        });

        var totalBatches = data.labels.length;
        var totalPages   = Math.ceil(totalBatches / TREND_PAGE_SIZE);
        var currentPage  = Math.floor(startIdx / TREND_PAGE_SIZE) + 1;
        var navEl   = document.getElementById('trendNavControls');
        var prevBtn = document.getElementById('trendPrev');
        var nextBtn = document.getElementById('trendNext');
        var infoEl  = document.getElementById('trendPageInfo');

        if (navEl && totalPages > 1) {
            navEl.classList.remove('hidden'); navEl.classList.add('flex');
            if (infoEl)  infoEl.textContent = currentPage+' / '+totalPages;
            if (prevBtn) prevBtn.disabled    = (startIdx <= 0);
            if (nextBtn) nextBtn.disabled    = (startIdx + TREND_PAGE_SIZE >= totalBatches);
        } else if (navEl) {
            navEl.classList.add('hidden'); navEl.classList.remove('flex');
        }
    }

    // ── Top Courses Horizontal Bar ────────────────────────────────────────────
    function buildCourseBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        safeDestroy('chartCourse');

        var bgColors     = data.labels.map(function(_,i){return BAR_COLORS[i%BAR_COLORS.length].bg;});
        var borderColors = data.labels.map(function(_,i){return BAR_COLORS[i%BAR_COLORS.length].border;});

        new Chart(canvas, {
            type:'bar',
            data: {
                labels: data.labels,
                datasets:[{
                    label:'Working Alumni',
                    data:data.data,
                    backgroundColor:bgColors,
                    borderColor:borderColors,
                    borderWidth:1.5,
                    borderRadius:4,
                }],
            },
            options: {
                indexAxis:'y',
                responsive:true, maintainAspectRatio:false,
                plugins: {
                    legend:{display:false},
                    tooltip:{
                        callbacks:{
                            title:function(items){return items[0].label;},
                            label:function(ctx){return '  '+ctx.parsed.x+' working alumni';},
                        },
                    },
                },
                scales: {
                    x:{grid:{color:'#f3f4f6'},ticks:{font:{size:10,weight:'500'},color:'#333333',precision:0},beginAtZero:true},
                    y:{grid:{display:false},ticks:{font:{size:10,weight:'600'},color:'#111111'}},
                },
                onClick:function(event,elements){
                    if(event&&event.native) event.native.stopPropagation();
                    if(!elements||!elements.length) return;
                    var course=data.labels[elements[0].index];
                    if(!course) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal',{detail:{filter:'employed_all',batch:null,course:course}}));
                },
            },
        });
    }

    // ── Nav button binders ────────────────────────────────────────────────────
    function bindBatchNav() {
        var prevBtn = document.getElementById('batchPrev');
        var nextBtn = document.getElementById('batchNext');
        if (!prevBtn || !nextBtn) return;
        var newPrev = prevBtn.cloneNode(true);
        var newNext = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrev, prevBtn);
        nextBtn.parentNode.replaceChild(newNext, nextBtn);
        newPrev.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!allBatchData) return;
            batchPageIndex = Math.max(0, batchPageIndex - BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
            bindBatchNav();
        });
        newNext.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!allBatchData) return;
            var max = allBatchData.labels.length - BATCH_PAGE_SIZE;
            batchPageIndex = Math.min(max < 0 ? 0 : max, batchPageIndex + BATCH_PAGE_SIZE);
            buildBatchBar(allBatchData, batchPageIndex);
            bindBatchNav();
        });
    }

    function bindTrendNav() {
        var prevBtn = document.getElementById('trendPrev');
        var nextBtn = document.getElementById('trendNext');
        if (!prevBtn || !nextBtn) return;
        var newPrev = prevBtn.cloneNode(true);
        var newNext = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrev, prevBtn);
        nextBtn.parentNode.replaceChild(newNext, nextBtn);
        newPrev.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!allTrendData) return;
            trendPageIndex = Math.max(0, trendPageIndex - TREND_PAGE_SIZE);
            buildTrendLine(allTrendData, trendPageIndex);
            bindTrendNav();
        });
        newNext.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!allTrendData) return;
            var max = allTrendData.labels.length - TREND_PAGE_SIZE;
            trendPageIndex = Math.min(max < 0 ? 0 : max, trendPageIndex + TREND_PAGE_SIZE);
            buildTrendLine(allTrendData, trendPageIndex);
            bindTrendNav();
        });
    }

    // ── Init all charts ───────────────────────────────────────────────────────
    function initAllCharts() {
        var d = readData();
        if (!d) return;

        if (d.batch && d.batch.labels) {
            var batchChanged = !allBatchData || JSON.stringify(d.batch.labels) !== JSON.stringify(allBatchData.labels);
            if (batchChanged) {
                allBatchData   = d.batch;
                var bTotal     = allBatchData.labels.length;
                batchPageIndex = Math.max(0, bTotal - BATCH_PAGE_SIZE);
            }
        }

        if (d.trend && d.trend.labels) {
            var trendChanged = !allTrendData || JSON.stringify(d.trend.labels) !== JSON.stringify(allTrendData.labels);
            if (trendChanged) {
                allTrendData   = d.trend;
                var tTotal     = allTrendData.labels.length;
                trendPageIndex = Math.max(0, tTotal - TREND_PAGE_SIZE);
            }
        }

        buildDonut('chartStatus',    d.status);
        buildDonut('chartRelevance', d.relevance);
        buildBatchBar(allBatchData, batchPageIndex);
        buildTrendLine(allTrendData, trendPageIndex);
        buildCourseBar(d.course);
        bindBatchNav();
        bindTrendNav();
    }

    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAllCharts); });
        } else {
            requestAnimationFrame(initAllCharts);
        }

        document.addEventListener('livewire:navigated', function() {
            ['chartStatus','chartRelevance','chartBatch','chartTrend','chartCourse'].forEach(safeDestroy);
            allBatchData = null; batchPageIndex = 0;
            allTrendData = null; trendPageIndex = 0;
            requestAnimationFrame(initAllCharts);
        });

        function hookLivewire() {
            if (!window.Livewire) return;
            Livewire.hook('commit', function(payload) {
                var succeed = payload.succeed || function(cb){cb({});};
                if (typeof succeed === 'function') {
                    succeed(function(){ requestAnimationFrame(initAllCharts); });
                }
            });
        }

        if (window.Livewire) { hookLivewire(); }
        else { document.addEventListener('livewire:initialized', hookLivewire); }
    });

})();
</script>

</div>