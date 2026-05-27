<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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
    public array  $modalRelevanceActive = ['relevant', 'partially_relevant', 'not_relevant'];
    public bool   $modalRelevanceLocked = false;

    // ── Reports modal ─────────────────────────────────────────────────────────
    public string $reportBatch    = '';
    public string $reportCourse   = '';
    public string $reportStatus   = '';
    public int    $reportPage     = 1;
    public int    $reportPageSize = 50;
    public bool   $showPrintData  = false;

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
    public string $chartLocationData  = '{}';
    public string $chartRelevanceData = '{}';
    public string $chartBatchData     = '{}';
    public string $chartCourseData    = '{}';

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

    // ── Smart batch filter: only batches that have records for the current modal filter ──
    #[Computed]
    public function availableModalBatchesForFilter()
    {
        $filter = $this->modalFilter;

        // no_record: batches that have at least one alumni WITHOUT an employment record
        if ($filter === 'no_record') {
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

        match ($filter) {
            'employed'      => $q->where('et.employment_status', 'employed'),
            'self_employed' => $q->where('et.employment_status', 'self_employed'),
            'unemployed'    => $q->where('et.employment_status', 'unemployed'),
            'employed_all'  => $q->whereIn('et.employment_status', ['employed', 'self_employed']),
            'abroad'        => $q->where('et.work_location', 'abroad')->whereIn('et.employment_status', ['employed','self_employed']),
            'local'         => $q->where('et.work_location', 'local')->whereIn('et.employment_status', ['employed','self_employed']),
            'relevance_all' => (function() use ($q) {
                $active = $this->modalRelevanceActive;
                $dbValues = array_merge(...array_map(fn($v) => match($v) {
                    'relevant'           => ['yes', 'relevant'],
                    'partially_relevant' => ['partially', 'partially_relevant'],
                    'not_relevant'       => ['no', 'not_relevant'],
                    default              => [$v],
                }, $active ?: ['relevant','partially_relevant','not_relevant']));
                $q->whereIn('et.course_relevance', $dbValues);
            })(),
            default => null,
        };

        return $q->pluck('a.batch');
    }

    #[Computed]
    public function reportRecords()
    {
        $q = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                'a.first_name','a.middle_initial','a.last_name','a.suffix',
                'a.student_id','a.course_code','a.batch',
                'a.email','a.contact_number',
                'et.employment_status','et.job_title','et.company_name',
                'et.employment_type','et.work_location','et.course_relevance','et.created_at'
            );

        if ($this->reportBatch && ctype_digit((string)$this->reportBatch)) {
            $q->where('a.batch', (int)$this->reportBatch);
        }
        if ($this->reportCourse && in_array($this->reportCourse, $this->availableCourses->toArray(), true)) {
            $q->where('a.course_code', $this->reportCourse);
        }
        if ($this->reportStatus === 'no_record') {
            $q->whereNull('et.id');
        } elseif ($this->reportStatus && in_array($this->reportStatus, ['employed','self_employed','unemployed'], true)) {
            $q->where('et.employment_status', $this->reportStatus);
        }

        return $q->orderBy('a.last_name')->orderBy('a.first_name')
                 ->paginate($this->reportPageSize, ['*'], 'rPage', $this->reportPage);
    }

    #[Computed]
    public function reportTotals()
    {
        $q = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                DB::raw("SUM(CASE WHEN et.employment_status = 'employed' THEN 1 ELSE 0 END) as emp"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'self_employed' THEN 1 ELSE 0 END) as self_emp"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'unemployed' THEN 1 ELSE 0 END) as unemp"),
                DB::raw("SUM(CASE WHEN et.id IS NULL THEN 1 ELSE 0 END) as none_count"),
                DB::raw("COUNT(a.id) as total")
            );

        if ($this->reportBatch && ctype_digit((string)$this->reportBatch)) {
            $q->where('a.batch', (int)$this->reportBatch);
        }
        if ($this->reportCourse && in_array($this->reportCourse, $this->availableCourses->toArray(), true)) {
            $q->where('a.course_code', $this->reportCourse);
        }
        if ($this->reportStatus === 'no_record') {
            $q->whereNull('et.id');
        } elseif ($this->reportStatus && in_array($this->reportStatus, ['employed','self_employed','unemployed'], true)) {
            $q->where('et.employment_status', $this->reportStatus);
        }

        return $q->first();
    }

    #[Computed]
    public function allReportRecordsForPrint()
    {
        if (!$this->showPrintData) {
            return collect();
        }

        $q = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->leftJoin('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->select(
                'a.first_name','a.middle_initial','a.last_name','a.suffix',
                'a.student_id','a.course_code','a.batch',
                'a.email',
                'et.employment_status','et.job_title','et.company_name',
                'et.employment_type','et.work_location','et.course_relevance','et.created_at'
            );

        if ($this->reportBatch && ctype_digit((string)$this->reportBatch)) {
            $q->where('a.batch', (int)$this->reportBatch);
        }
        if ($this->reportCourse && in_array($this->reportCourse, $this->availableCourses->toArray(), true)) {
            $q->where('a.course_code', $this->reportCourse);
        }
        if ($this->reportStatus === 'no_record') {
            $q->whereNull('et.id');
        } elseif ($this->reportStatus && in_array($this->reportStatus, ['employed','self_employed','unemployed'], true)) {
            $q->where('et.employment_status', $this->reportStatus);
        }

        return $q->orderBy('a.last_name')->orderBy('a.first_name')->get();
    }

    public function loadPrintData(): void
    {
        $this->showPrintData = true;
        unset($this->allReportRecordsForPrint);
        $this->dispatch('emp-print-ready');
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
                    DB::raw("NULL as job_title"),
                    DB::raw("NULL as company_name"),
                    DB::raw("NULL as employment_type"),
                    DB::raw("NULL as work_location"),
                    DB::raw("NULL as course_relevance"),
                    DB::raw("NULL as created_at"),
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
                'et.employment_status','et.job_title','et.company_name',
                'et.employment_type','et.work_location','et.course_relevance','et.created_at',
            ]);

        match ($this->modalFilter) {
            'employed'     => $q->where('et.employment_status', 'employed'),
            'self_employed'=> $q->where('et.employment_status', 'self_employed'),
            'unemployed'   => $q->where('et.employment_status', 'unemployed'),
            'employed_all' => $q->whereIn('et.employment_status', ['employed', 'self_employed']),
            default        => null,
        };

        if (in_array($this->modalFilter, ['abroad','local'])) {
            $q->where('et.work_location', $this->modalFilter)
              ->whereIn('et.employment_status', ['employed','self_employed']);
        }

        if ($this->modalFilter === 'relevance_all') {
            $active   = array_values(array_filter($this->modalRelevanceActive));
            $dbValues = array_map(fn($v) => match($v) {
                'relevant'           => ['yes', 'relevant'],
                'partially_relevant' => ['partially', 'partially_relevant'],
                'not_relevant'       => ['no', 'not_relevant'],
                default              => [$v],
            }, $active);
            $flat = array_merge(...($dbValues ?: [[]]));
            $flat ? $q->whereIn('et.course_relevance', $flat) : $q->whereNotNull('et.course_relevance');
        }

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
                ->orWhere('et.company_name', 'like', $term)
                ->orWhere('et.job_title',    'like', $term)
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
            $labels = ['relevant' => 'Relevant', 'partially_relevant' => 'Partially Relevant', 'not_relevant' => 'Not Relevant'];
            if (count($active) === 3 || empty($active)) return 'All Course-Relevance Records' . $suffix;
            return implode(' + ', array_map(fn($v) => $labels[$v] ?? $v, $active)) . ' Jobs' . $suffix;
        }

        return match ($this->modalFilter) {
            'employed'      => 'Employed Alumni'      . $suffix,
            'self_employed' => 'Self-Employed Alumni' . $suffix,
            'employed_all'  => 'Working Alumni'       . $suffix,
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
                    'relevant'           => 'fa-circle-check',
                    'partially_relevant' => 'fa-circle-half-stroke',
                    'not_relevant'       => 'fa-circle-xmark',
                    default              => 'fa-chart-pie',
                };
            }
            return 'fa-chart-pie';
        }

        return match ($this->modalFilter) {
            'employed'      => 'fa-user-tie',
            'self_employed' => 'fa-store',
            'employed_all'  => 'fa-briefcase',
            'unemployed'    => 'fa-magnifying-glass',
            'no_record'     => 'fa-circle-minus',
            'abroad'        => 'fa-plane-departure',
            'local'         => 'fa-house',
            default         => 'fa-briefcase',
        };
    }

    #[Computed]
    public function isRelevanceFilter(): bool { return $this->modalFilter === 'relevance_all'; }

    public function openModal(string $filter = '', ?int $batch = null, string $course = ''): void
    {
        $allowedFilters = ['', 'employed', 'employed_all', 'self_employed', 'unemployed', 'no_record',
                           'abroad', 'local', 'relevance_yes', 'relevance_partially',
                           'relevance_no', 'relevance_all'];
        if (!in_array($filter, $allowedFilters, true)) $filter = '';

        $relevanceMap = [
            'relevance_yes'       => ['relevant'],
            'relevance_partially' => ['partially_relevant'],
            'relevance_no'        => ['not_relevant'],
            'relevance_all'       => ['relevant', 'partially_relevant', 'not_relevant'],
        ];

        if (isset($relevanceMap[$filter])) {
            $this->modalRelevanceActive = $relevanceMap[$filter];
            $this->modalRelevanceLocked = ($filter !== 'relevance_all');
            $filter = 'relevance_all';
        } else {
            $this->modalRelevanceActive = ['relevant', 'partially_relevant', 'not_relevant'];
            $this->modalRelevanceLocked = false;
        }

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

    public function openReports(): void
    {
        $this->reportBatch    = '';
        $this->reportCourse   = '';
        $this->reportStatus   = '';
        $this->reportPage     = 1;
        $this->showPrintData  = false;
        $this->activeModal    = 'reports';
        unset($this->reportRecords);
        unset($this->reportTotals);
        unset($this->allReportRecordsForPrint);
    }

    public function toggleRelevance(string $val): void
    {
        $allowed = ['relevant', 'partially_relevant', 'not_relevant'];
        if ($this->modalRelevanceLocked || !in_array($val, $allowed, true)) return;

        if (in_array($val, $this->modalRelevanceActive)) {
            $this->modalRelevanceActive = array_values(
                array_filter($this->modalRelevanceActive, fn($v) => $v !== $val)
            );
        } else {
            $this->modalRelevanceActive[] = $val;
        }
        $this->modalPage = 1;
        unset($this->modalRecords);
        unset($this->availableModalBatchesForFilter);
    }

    public function updatingModalSearch(): void { $this->modalPage = 1; }
    public function updatingModalBatch(): void  { $this->modalPage = 1; }
    public function updatingModalCourse(): void { $this->modalPage = 1; }

    public function updatingReportBatch(): void {
        $this->reportPage = 1; $this->showPrintData = false;
        unset($this->reportRecords); unset($this->reportTotals); unset($this->allReportRecordsForPrint);
    }
    public function updatingReportCourse(): void {
        $this->reportPage = 1; $this->showPrintData = false;
        unset($this->reportRecords); unset($this->reportTotals); unset($this->allReportRecordsForPrint);
    }
    public function updatingReportStatus(): void {
        $this->reportPage = 1; $this->showPrintData = false;
        unset($this->reportRecords); unset($this->reportTotals); unset($this->allReportRecordsForPrint);
    }

    public function reportPrev(): void { if ($this->reportPage > 1) { $this->reportPage--; unset($this->reportRecords); } }
    public function reportNext(): void {
        if ($this->reportPage < $this->reportRecords->lastPage()) { $this->reportPage++; unset($this->reportRecords); }
    }

    public function modalPrev(): void { if ($this->modalPage > 1) $this->modalPage--; }
    public function modalNext(): void {
        if ($this->modalPage < $this->modalRecords->lastPage()) $this->modalPage++;
    }

    public function clearModalFilters(): void
    {
        $this->modalBatch = null; $this->modalBatchLocked = false;
        $this->modalCourse = ''; $this->modalCourseLocked = false;
        $this->modalSearch = ''; $this->modalPage = 1;
        unset($this->modalRecords);
        unset($this->availableModalBatchesForFilter);
    }

    public function closeModal(): void
    {
        $this->activeModal = ''; $this->modalFilter = '';
        $this->modalBatch = null; $this->modalBatchLocked = false;
        $this->modalCourse = ''; $this->modalCourseLocked = false;
        $this->modalPage = 1; $this->modalSearch = '';
        $this->modalRelevanceActive = ['relevant', 'partially_relevant', 'not_relevant'];
        $this->modalRelevanceLocked = false;
        $this->reportBatch = ''; $this->reportCourse = '';
        $this->reportStatus = ''; $this->reportPage = 1;
        $this->showPrintData = false;
        unset($this->modalRecords, $this->reportRecords, $this->reportTotals, $this->allReportRecordsForPrint);
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

    public function with(): array { return []; }
};
?>

<div @open-emp-modal.window="$wire.openModal($event.detail.filter, $event.detail.batch ?? null, $event.detail.course ?? '')">

{{-- Print styles --}}
<style>
    @media print {
        body > * { display: none !important; }
        body > #emp-print-clone { display: block !important; position: static !important; background: #fff !important; padding: 0 !important; }
        body > #emp-print-clone * { color: #000 !important; background: transparent !important; font-family: 'Times New Roman', Times, serif !important; }
    }
    #emp-print-area { display: none; }

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

    /* ── Pagination buttons ── */
    .emp-pg-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: .75rem;
        font-weight: 700;
        transition: all .15s;
        border: 1.5px solid transparent;
    }
    .emp-pg-active {
        background: rgba(255,255,255,1);
        color: #7A3F91;
        border-color: rgba(255,255,255,1);
    }
    .emp-pg-nav {
        background: rgba(255,255,255,.15);
        color: #fff;
        border-color: rgba(255,255,255,.25);
    }
    .emp-pg-nav:hover:not(:disabled) {
        background: rgba(255,255,255,.28);
        border-color: rgba(255,255,255,.5);
    }
    .emp-pg-nav:disabled { opacity:.35; cursor:not-allowed; }

    /* ── Course breakdown cursor tooltip ── */
    .cb-row-tip {
        position: fixed;
        background: #1a1a1a;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .05em;
        padding: 5px 11px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 99999;
        box-shadow: 0 4px 14px rgba(0,0,0,.30);
        transform: translate(14px, -50%);
    }
    .cb-row-tip.visible { opacity: 1; }

    /* ── Stat card: subtle border tint on hover, no bloom ── */
    .stat-card {
        border: 1.5px solid #E8E0F0 !important;
        transition: border-color .18s ease, box-shadow .18s ease;
    }
    .stat-card:hover {
        box-shadow: 0 2px 10px rgba(0,0,0,.07) !important;
    }
    .stat-card-employed:hover  { border-color: #6ee7b7 !important; }
    .stat-card-self:hover      { border-color: #93c5fd !important; }
    .stat-card-unemployed:hover{ border-color: #fcd34d !important; }
    .stat-card-abroad:hover    { border-color: #fcd34d !important; }
    .stat-card-local:hover     { border-color: #5eead4 !important; }
    .stat-card-nofill:hover    { border-color: #d1d5db !important; }
    .stat-card-submitted:hover { border-color: #c4b5fd !important; }

    /* ── Disabled batch option ── */
    .batch-option-disabled {
        opacity: .38;
        cursor: not-allowed;
        pointer-events: none;
    }
</style>

{{-- Course breakdown cursor tooltip --}}
<div id="cb-cursor-tip" class="cb-row-tip">
    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Details
</div>

{{-- Chart data bridge --}}
<div id="__emp_chart_data" class="hidden"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-course="{{ $chartCourseData }}">
</div>

{{-- ═══════════════════════════════════════════════════════════
     MAIN PAGE
═══════════════════════════════════════════════════════════ --}}
<div class="h-[90vh] max-h-[90vh] overflow-hidden bg-gray-100 flex flex-col">
<div class="flex-1 overflow-y-auto scrollbar-thin scrollbar-thumb-purple-200">
<div class="flex flex-col px-6 py-4 gap-4 max-w-[1920px] mx-auto w-full box-border">

    {{-- PAGE HEADER --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-[#7a3f91]">
                <i class="fas fa-chart-column text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold leading-tight text-gray-900">Employment Tracking</h1>
                <p class="text-xs text-gray-500 mt-0.5">System-wide alumni employment analytics &amp; records</p>
            </div>
        </div>
        <button wire:click="openReports"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold shadow text-white
                       bg-[#7a3f91] hover:bg-[#5e2f72] transition active:scale-95">
            <i class="fas fa-print"></i>
            <span>Print</span>
        </button>
    </div>

    {{-- ── STAT CARDS ── --}}
    @php
        $empRate      = $totalAlumni > 0 ? round(($totalEmployed + $totalSelf) / $totalAlumni * 100, 1) : 0;
        $responseRate = $totalAlumni > 0 ? round($totalSubmitted / $totalAlumni * 100, 1) : 0;
        $ofwRate      = $totalSubmitted > 0 ? round($totalAbroad / $totalSubmitted * 100, 1) : 0;
        $unempRate    = $totalSubmitted > 0 ? round($totalUnemployed / $totalSubmitted * 100, 1) : 0;
        $nfRate       = $totalAlumni > 0 ? round($totalNotFilled / $totalAlumni * 100, 1) : 0;

        $statCards = [
            ['',              $totalSubmitted,  'fa-file-circle-check', 'bg-violet-100',  'text-violet-700',  'stat-card-submitted',  'Submitted',     $responseRate.'% response rate'],
            ['employed',      $totalEmployed,   'fa-briefcase',         'bg-emerald-100', 'text-emerald-700', 'stat-card-employed',   'Employed',      ($totalAlumni > 0 ? round($totalEmployed/$totalAlumni*100,1) : 0).'% of total'],
            ['self_employed', $totalSelf,       'fa-store',             'bg-blue-100',    'text-blue-700',    'stat-card-self',       'Self-Employed', ($totalAlumni > 0 ? round($totalSelf/$totalAlumni*100,1) : 0).'% of total'],
            ['unemployed',    $totalUnemployed, 'fa-circle-pause',      'bg-amber-100',   'text-amber-700',   'stat-card-unemployed', 'Unemployed',    $unempRate.'% of submitted'],
            ['no_record',     $totalNotFilled,  'fa-circle-question',   'bg-gray-100',    'text-gray-600',    'stat-card-nofill',     'Not Filled',    $nfRate.'% of total'],
            ['abroad',        $totalAbroad,     'fa-plane-departure',   'bg-yellow-100',  'text-yellow-700',  'stat-card-abroad',     'OFW/Abroad',    $ofwRate.'% working rate'],
            ['local',         $totalLocal,      'fa-house',             'bg-teal-100',    'text-teal-700',    'stat-card-local',      'Local',         ($totalSubmitted > 0 ? round($totalLocal/$totalSubmitted*100,1) : 0).'% working rate'],
        ];
    @endphp
    <div class="flex gap-2 flex-wrap lg:flex-nowrap">
        @foreach($statCards as [$filter, $count, $icon, $iconBg, $iconColor, $hoverClass, $label, $rate])
        <div wire:click="openModal('{{ $filter }}')"
             class="group relative bg-white rounded-2xl p-2.5 flex items-center gap-2.5
                    shadow-sm cursor-pointer flex-1 min-w-0 overflow-visible stat-card {{ $hoverClass }}">
            {{-- Tooltip --}}
            <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                         bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                         px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                         opacity-0 group-hover:opacity-100 transition-opacity shadow-lg z-50
                         before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                         before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]">
                <i class="fas fa-eye mr-1 text-[9px]"></i>View {{ $label }}
            </span>
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 {{ $iconBg }}">
                <i class="fa-solid {{ $icon }} {{ $iconColor }}" style="font-size:.9rem;"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xl font-bold leading-none text-gray-900 stat-number-anim">{{ number_format($count) }}</p>
                <p class="text-[.70rem] font-semibold text-gray-600 mt-0.5">{{ $label }}</p>
                <p class="text-[.67rem] font-bold mt-0.5 {{ $iconColor }}">{{ $rate }}</p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── CHARTS ── --}}
    <div class="flex flex-col gap-3">
        {{-- Top row: 4 charts --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" style="height:280px;">

            {{-- Status donut --}}
            <div onclick="empOpenModal('','',null)"
                 class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                        transition-all cursor-pointer flex flex-col overflow-hidden">
                <div class="px-3.5 py-2 border-b border-gray-100 bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                    <span class="w-2 h-2 rounded-full bg-[#10b981] shrink-0"></span>
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest">Status</span>
                    <span class="ml-auto text-[.65rem] text-gray-400 font-medium flex items-center gap-1"><i class="fas fa-hand-pointer"></i> Click</span>
                </div>
                <div class="flex-1 min-h-0 flex items-center justify-center p-1" wire:ignore>
                    <canvas id="chartStatus" style="max-height:100%;max-width:100%;"></canvas>
                </div>
            </div>

            {{-- Location donut --}}
            <div onclick="empOpenModal('','',null)"
                 class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                        transition-all cursor-pointer flex flex-col overflow-hidden">
                <div class="px-3.5 py-2 border-b border-gray-100 bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                    <span class="w-2 h-2 rounded-full bg-purple-400 shrink-0"></span>
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest">Location</span>
                    <span class="ml-auto text-[.65rem] text-gray-400 font-medium flex items-center gap-1"><i class="fas fa-hand-pointer"></i> Click</span>
                </div>
                <div class="flex-1 min-h-0 flex items-center justify-center p-1" wire:ignore>
                    <canvas id="chartLocation" style="max-height:100%;max-width:100%;"></canvas>
                </div>
            </div>

            {{-- Relevance donut --}}
            <div onclick="empOpenModal('relevance_all','',null)"
                 class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                        transition-all cursor-pointer flex flex-col overflow-hidden">
                <div class="px-3.5 py-2 border-b border-gray-100 bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest">Relevance</span>
                    <span class="ml-auto text-[.65rem] text-gray-400 font-medium flex items-center gap-1"><i class="fas fa-hand-pointer"></i> Click</span>
                </div>
                <div class="flex-1 min-h-0 flex items-center justify-center p-1" wire:ignore>
                    <canvas id="chartRelevance" style="max-height:100%;max-width:100%;"></canvas>
                </div>
            </div>

            {{-- Top Courses bar --}}
            <div onclick="empOpenModal('employed_all','',null)"
                 class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                        transition-all cursor-pointer flex flex-col overflow-hidden">
                <div class="px-3.5 py-2 border-b border-gray-100 bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                    <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0"></span>
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest">Top Courses</span>
                    <span class="ml-auto text-[.65rem] text-gray-400 font-medium flex items-center gap-1"><i class="fas fa-hand-pointer"></i> Click bar</span>
                </div>
                <div class="flex-1 min-h-0 p-1" wire:ignore>
                    <canvas id="chartCourse" style="max-height:100%;width:100%;"></canvas>
                </div>
            </div>

        </div>

        {{-- ── Batch bar ── --}}
        <div class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all cursor-pointer flex flex-col overflow-hidden" style="height:260px;">
            <div class="px-[14px] py-2 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
                <div class="flex items-center gap-[7px]">
                    <div class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></div>
                    <span class="text-[.78rem] font-bold text-[#111111] uppercase tracking-[.06em]">Employment by Batch Year</span>
                    <span class="text-[.68rem] text-[#555555] font-medium flex items-center gap-[3px] ml-2 pointer-events-none">
                        <i class="fas fa-hand-pointer"></i> Click bar
                    </span>
                </div>
                <div id="batchNavControls" class="hidden items-center gap-2">
                    <button id="batchPrev"
                            class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91] text-[.75rem]
                                   cursor-pointer flex items-center justify-center
                                   hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                                   disabled:opacity-35 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                    </button>
                    <span id="batchPageInfo" class="text-[.74rem] font-semibold text-[#333333] whitespace-nowrap"></span>
                    <button id="batchNext"
                            class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91] text-[.75rem]
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
    </div>

    {{-- ── COURSE BREAKDOWN TABLE ── --}}
    <div class="bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden shadow-sm">
        <div class="flex items-center justify-between px-5 py-2.5 border-b border-[#E8E0F0] bg-[#F9F7FC]">
            <div class="flex items-center gap-2.5">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-[#7a3f91]">
                    <i class="fas fa-table text-white" style="font-size:.65rem;"></i>
                </div>
                <div>
                    <p class="text-sm font-bold leading-tight text-[#111111]">Course Breakdown</p>
                    <p class="text-[10px] text-[#555555]">Employment rate per course — click any row to view details</p>
                </div>
            </div>
            <span class="text-[11px] font-semibold px-2 py-1 rounded-lg bg-[#F9F7FC] text-[#7a3f91] border border-[#E8E0F0]">
                {{ count($this->courseAnalytics) }} courses
            </span>
        </div>

        <div class="max-h-[450px] overflow-y-auto overflow-x-auto" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
            <table class="w-full border-collapse" style="min-width:580px;">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-[#f5f0fa] border-b-2 border-[#E8E0F0]">
                        <th class="pl-4 pr-3 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-[#111111]">Course</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-[#111111]">Total</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-emerald-700">Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-blue-700">Self-Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-amber-700">Unemployed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-gray-500">No Record</th>
                        <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-[#7a3f91]" style="min-width:160px;">Emp Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->courseAnalytics as $cr)
                    @php $courseFullName = $this->courseMap[$cr->course_code ?? ''] ?? null; @endphp
                    <tr class="hover:bg-[#F5F0FA] transition-colors cursor-pointer cb-table-row"
                        data-course="{{ $cr->course_code }}"
                        onclick="empOpenModal('employed_all','{{ $cr->course_code }}',null)">
                        <td class="pl-4 pr-3 py-2.5">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold bg-[#F9F7FC] text-[#7a3f91]">
                                <i class="fas fa-graduation-cap text-[9px]"></i>
                                {{ $cr->course_code ?? '—' }}
                            </span>
                            @if($courseFullName)
                                <p class="text-[11px] mt-0.5 font-semibold text-[#333333] leading-tight">{{ $courseFullName }}</p>
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
                            <span class="text-xs font-semibold text-gray-500">{{ number_format($cr->not_filled) }}</span>
                        </td>
                        <td class="px-4 py-2.5" style="min-width:160px;">
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
    $isRelMode  = $this->isRelevanceFilter;
    $isUnemp    = $this->modalFilter === 'unemployed';

    $showLocationCol      = !in_array($this->modalFilter, ['unemployed','no_record','abroad','local']);
    $showRelevanceCol     = !in_array($this->modalFilter, ['unemployed','no_record']);
    $showEmailContactSplit = in_array($this->modalFilter, ['unemployed','no_record']);

    $totalCols = 7
        + ($showEmailContactSplit ? 1 : 0)
        + ($showLocationCol ? 1 : 0)
        + ($showRelevanceCol ? 1 : 0);

    $jobColHeader = match(true) {
        $showEmailContactSplit                 => 'Email Address',
        $this->modalFilter === 'self_employed' => 'Business Name',
        default                                => 'Job / Business',
    };

    $statusBadge = [
        'employed'      => ['Employed',      'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200'],
    ];
    $locBadge = [
        'abroad' => ['OFW / Abroad', 'text-amber-700 bg-amber-50 border-amber-200'],
        'local'  => ['Local',        'text-emerald-700 bg-emerald-50 border-emerald-200'],
    ];
    $relevanceBadge = [
        'yes'                => ['Relevant',           'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'relevant'           => ['Relevant',           'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'partially'          => ['Partially Relevant', 'text-amber-700 bg-amber-50 border-amber-200'],
        'partially_relevant' => ['Partially Relevant', 'text-amber-700 bg-amber-50 border-amber-200'],
        'no'                 => ['Not Relevant',       'text-red-600 bg-red-50 border-red-200'],
        'not_relevant'       => ['Not Relevant',       'text-red-600 bg-red-50 border-red-200'],
    ];
    $relChips = [
        ['relevant',           'Relevant',           'fa-circle-check',       'text-emerald-700 bg-emerald-50 border-emerald-200'],
        ['partially_relevant', 'Partially Relevant', 'fa-circle-half-stroke', 'text-amber-700 bg-amber-50 border-amber-200'],
        ['not_relevant',       'Not Relevant',       'fa-circle-xmark',       'text-red-600 bg-red-50 border-red-200'],
    ];
    $relLockedLabels = ['relevant' => 'Relevant', 'partially_relevant' => 'Partially Relevant', 'not_relevant' => 'Not Relevant'];
    $relLockedIcons  = ['relevant' => 'fa-circle-check', 'partially_relevant' => 'fa-circle-half-stroke', 'not_relevant' => 'fa-circle-xmark'];

    $visibleStatusTabs = [[
        $modalFilter,
        match($modalFilter) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'employed_all'  => 'Working Alumni',
            'unemployed'    => 'Unemployed',
            'no_record'     => 'No Record',
            'abroad'        => 'Abroad',
            'local'         => 'Local',
            default         => 'All Records',
        },
        match($modalFilter) {
            'employed'      => 'fa-user-tie',
            'self_employed' => 'fa-store',
            'employed_all'  => 'fa-briefcase',
            'unemployed'    => 'fa-magnifying-glass',
            'no_record'     => 'fa-circle-minus',
            'abroad'        => 'fa-plane-departure',
            'local'         => 'fa-house',
            default         => 'fa-briefcase',
        },
    ]];

    $hasSubFilters  = $modalBatch !== null || $modalCourse !== '' || $modalSearch !== '';
    $hasChipsToShow = ($modalBatch !== null && !$modalBatchLocked)
                   || ($modalCourse !== '' && !$modalCourseLocked)
                   || $modalSearch !== '';

    // ── Smart batch list: only batches relevant to the current filter ──
    $smartBatches = $this->availableModalBatchesForFilter;

    $rTotal    = $records->total();
    $rPp       = $records->perPage();
    $rCp       = $records->currentPage();
    $rLastPage = $records->lastPage();
    $rFrom     = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
    $rTo       = min($rCp * $rPp, $rTotal);
    $rPgStart  = max(1, $rCp - 2);
    $rPgEnd    = min($rLastPage, $rCp + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 emp-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow" style="background:#7a3f91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->modalIcon }} text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">{{ $this->modalTitle }}</h2>
                <p class="text-white/60 text-xs">
                    {{ number_format($records->total()) }} record(s) found
                    @if($modalCourse) · {{ $modalCourse }} @endif
                    @if($modalBatch) · Batch {{ $modalBatch }} @endif
                </p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="group relative w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white
                       flex items-center justify-center hover:bg-white/20 transition cursor-pointer overflow-visible">
            <span class="absolute top-[calc(100%+8px)] right-0 bg-gray-900/90 text-white text-[10px] font-bold
                         uppercase tracking-widest px-2.5 py-1 rounded-lg whitespace-nowrap pointer-events-none
                         opacity-0 group-hover:opacity-100 transition-opacity shadow-lg z-50
                         before:content-[''] before:absolute before:bottom-full before:right-2.5
                         before:border-4 before:border-transparent before:border-b-gray-900/90">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    {{-- Toolbar --}}
    <div class="px-6 lg:px-10 pt-2.5 pb-2 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap gap-2 items-center mb-2">
            <span class="inline-flex items-center gap-1.5 text-[.72rem] font-bold uppercase tracking-wider
                         text-[#7A3F91] px-2.5 py-1.5 rounded-lg border border-[#E8E0F0] bg-[#F9F7FC] whitespace-nowrap shrink-0">
                <i class="fas fa-filter text-[10px]"></i>Filters
            </span>

            <div class="relative w-72 shrink-0" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.modalSearch??''; $wire.$watch('modalSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('modalSearch',q)"
                       placeholder="Search name, ID, email, company…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-[#111111]
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-purple-100 transition"
                       autocomplete="off">
            </div>

            <div class="h-5 w-px bg-gray-200 shrink-0"></div>

            @if($isRelMode && $modalRelevanceLocked)
                @php $lockedVal = $modalRelevanceActive[0] ?? 'relevant'; @endphp
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border bg-[#7A3F91] text-white border-transparent">
                    <i class="fas {{ $relLockedIcons[$lockedVal] ?? 'fa-chart-pie' }} text-[10px]"></i>
                    {{ $relLockedLabels[$lockedVal] ?? 'Relevant' }}
                    <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                </span>
            @elseif($isRelMode)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-[11px] font-semibold text-[#111111]">Relevance:</span>
                    @foreach($relChips as [$relVal, $relLbl, $relIcon, $relColors])
                    @php $isRelActive = in_array($relVal, $modalRelevanceActive ?? []); @endphp
                    <button wire:click="toggleRelevance('{{ $relVal }}')"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition active:scale-95
                                   {{ $isRelActive ? 'bg-[#7A3F91] text-white border-transparent shadow-md' : 'bg-white text-[#111111] border-gray-200 opacity-65 hover:opacity-100 hover:border-purple-300 hover:text-[#7A3F91] '.$relColors }}">
                        <i class="fas {{ $relIcon }} text-[10px]"></i> {{ $relLbl }}
                        @if($isRelActive)<i class="fas fa-check text-[9px] opacity-80 ml-0.5"></i>@endif
                    </button>
                    @endforeach
                </div>
            @else
                <div class="flex items-center gap-1.5">
                    @foreach($visibleStatusTabs as [$tabVal, $tabLbl, $tabIcon])
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border bg-[#7A3F91] text-white border-transparent">
                        <i class="fas {{ $tabIcon }} text-[10px]"></i>{{ $tabLbl }}
                        <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                    </span>
                    @endforeach
                </div>
            @endif

            <div class="flex-1 min-w-0"></div>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            {{-- ── Smart Batch Dropdown ── --}}
            @if(!$modalBatchLocked)
            <div class="relative" x-data="{ open:false }" @click.outside="open=false">
                <button type="button" @click="open=!open"
                        class="inline-flex items-center gap-1.5 px-3 py-2 border rounded-lg text-xs font-semibold bg-white text-[#111111] cursor-pointer transition
                               {{ $modalBatch !== null ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 hover:border-purple-300' }}">
                    <i class="fas fa-calendar-alt text-[.68rem] opacity-70"></i>
                    @if($modalBatch) Batch {{ $modalBatch }} @else All Batches @endif
                    <i class="fas fa-chevron-down text-[.62rem] opacity-60 transition-transform" :class="{'rotate-180':open}"></i>
                </button>
                <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                     bg-white border-[1.5px] border-[#E8E0F0] rounded-xl shadow-xl z-[600] p-1" style="display:none;">
                    <button type="button" @click="$wire.set('modalBatch',null); open=false"
                            class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $modalBatch === null ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">
                        All Batches
                        @if($smartBatches->count() < $this->availableBatches->count())
                            <span class="ml-1 text-[10px] text-[#7A3F91] font-normal opacity-70">({{ $smartBatches->count() }} with records)</span>
                        @endif
                    </button>
                    @foreach($this->availableBatches as $bYear)
                    @php $hasRecords = $smartBatches->contains($bYear); @endphp
                    <button type="button"
                            @if($hasRecords) @click="$wire.set('modalBatch',{{ $bYear }}); open=false" @endif
                            class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold transition
                                   {{ $modalBatch == $bYear ? 'bg-[#F0E6F8] text-[#7A3F91]' : 'text-[#111111]' }}
                                   {{ $hasRecords ? 'hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer' : 'batch-option-disabled' }}">
                        Batch {{ $bYear }}
                        @if(!$hasRecords)
                            <span class="ml-1 text-[10px] font-normal text-gray-400">— no records</span>
                        @endif
                    </button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]">
                <i class="fas fa-calendar-check text-[10px]"></i>Batch {{ $modalBatch }}<i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            @if(!$modalCourseLocked)
            <div class="relative" x-data="{ open:false }" @click.outside="open=false">
                <button type="button" @click="open=!open"
                        class="inline-flex items-center gap-1.5 px-3 py-2 border rounded-lg text-xs font-semibold bg-white text-[#111111] cursor-pointer transition
                               {{ $modalCourse !== '' ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 hover:border-purple-300' }}">
                    <i class="fas fa-book-open text-[.68rem] opacity-70"></i>
                    @if($modalCourse) {{ $modalCourse }} @else All Courses @endif
                    <i class="fas fa-chevron-down text-[.62rem] opacity-60 transition-transform" :class="{'rotate-180':open}"></i>
                </button>
                <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                     bg-white border-[1.5px] border-[#E8E0F0] rounded-xl shadow-xl z-[600] p-1" style="display:none;">
                    <button type="button" @click="$wire.set('modalCourse',''); open=false"
                            class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $modalCourse === '' ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">All Courses</button>
                    @foreach($this->availableCourses as $cCode)
                    <button type="button" @click="$wire.set('modalCourse','{{ $cCode }}'); open=false"
                            class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111]
                                   hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $modalCourse === $cCode ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">{{ $cCode }}</button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border border-blue-500 bg-blue-50 text-blue-700">
                <i class="fas fa-book-open text-[10px]"></i>{{ $modalCourse }}<i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            @if($hasSubFilters && $hasChipsToShow)
            <div class="flex items-center gap-1.5 ml-1">
                <span class="text-xs text-[#111111]">Filtering:</span>
                @if($modalBatch !== null && !$modalBatchLocked)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border border-[#E8E0F0] bg-[#F9F7FC] text-[#7A3F91]">
                    <i class="fas fa-calendar text-[10px]"></i> Batch {{ $modalBatch }}
                </span>
                @endif
                @if($modalCourse !== '' && !$modalCourseLocked)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border border-[#E8E0F0] bg-[#F9F7FC] text-[#7A3F91]">
                    <i class="fas fa-book text-[10px]"></i> {{ $modalCourse }}
                </span>
                @endif
                @if($modalSearch !== '')
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border border-[#E8E0F0] bg-[#F9F7FC] text-[#7A3F91]">
                    <i class="fas fa-search text-[10px]"></i> "{{ Str::limit($modalSearch,20) }}"
                </span>
                @endif
                <button wire:click="clearModalFilters" wire:loading.attr="disabled"
                        class="text-xs text-red-500 hover:text-red-700 font-semibold transition ml-1">
                    <span wire:loading.remove wire:target="clearModalFilters">Clear all</span>
                    <span wire:loading wire:target="clearModalFilters">Clearing…</span>
                </button>
            </div>
            @endif
        </div>
    </div>

    {{-- Table --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin;">
        <div wire:loading wire:target="modalFilter,modalBatch,modalCourse,modalSearch,modalPage,modalPrev,modalNext,clearModalFilters,toggleRelevance"
             class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none bg-white/60">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
            </div>
        </div>

        <table class="w-full border-collapse" style="min-width:{{ $showEmailContactSplit ? '980px' : ($showLocationCol && $showRelevanceCol ? '900px' : '700px') }};">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-10">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Alumni</th>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-28">Student ID</th>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-24">Course</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider" style="min-width:200px;">{{ $jobColHeader }}</th>
                    @if($showEmailContactSplit)
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider" style="min-width:140px;">Contact Number</th>
                    @endif
                    @if($showLocationCol)
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Location</th>
                    @endif
                    @if($showRelevanceCol)
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Relevance</th>
                    @endif
                    <th class="px-4 pr-6 lg:pr-10 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Batch</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($records as $idx => $row)
                @php
                    $rowNum     = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                    $badge      = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? null);
                    $loc        = ($showLocationCol && !$isNoRecord) ? ($locBadge[$row->work_location ?? ''] ?? null) : null;
                    $relBdg     = ($showRelevanceCol && !$isNoRecord) ? ($relevanceBadge[$row->course_relevance ?? ''] ?? null) : null;
                    $photo      = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName      = $this->formatName($row->first_name??'',$row->middle_initial??'',$row->last_name??'',$row->suffix??'');
                    $rowStatus  = $row->employment_status ?? '';
                    $isRowEmp   = $rowStatus === 'employed';
                    $isRowSelf  = $rowStatus === 'self_employed';
                    $rowJob     = $row->job_title ?? null;
                    $rowCompany = $row->company_name ?? null;
                    $rowEmail   = $row->email ?? null;
                    $rowContact = $row->contact_number ?? null;
                @endphp
                <tr class="bg-white hover:bg-[#F5F0FA] transition-colors">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold text-[#7A3F91]/40">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ e($row->first_name ?? '') }}"
                                 class="w-8 h-8 rounded-lg object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <p class="text-sm font-semibold truncate uppercase text-[#111111]">{{ $dName }}</p>
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-xs font-mono font-semibold text-[#111111]">{{ $row->student_id ?? '—' }}</p>
                    </td>
                    <td class="px-3 py-3">
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#F9F7FC] text-[#7a3f91]">
                            {{ $row->course_code ?? '—' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($isNoRecord || is_null($row->employment_status ?? null))
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold border text-[#111111] bg-gray-50 border-gray-200">
                                No Record
                            </span>
                        @elseif($badge)
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                {{ $badge[0] }}
                            </span>
                        @else
                            <span class="text-xs text-[#111111]">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="min-width:200px;">
                        @if($showEmailContactSplit)
                            @if($rowEmail)
                                <p class="text-xs text-[#111111]">{{ $rowEmail }}</p>
                            @else
                                <span class="text-xs text-[#111111]">—</span>
                            @endif
                        @elseif($isRowEmp)
                            @if($rowJob)
                                <p class="text-xs font-semibold text-[#111111]">{{ $rowJob }}</p>
                                @if($rowCompany)<p class="text-xs text-[#111111] mt-0.5">{{ $rowCompany }}</p>@endif
                            @else
                                <span class="text-xs text-[#111111]">—</span>
                            @endif
                        @elseif($isRowSelf)
                            @if($rowCompany)
                                <p class="text-xs font-semibold text-[#111111]">{{ $rowCompany }}</p>
                            @else
                                <span class="text-xs text-[#111111]">—</span>
                            @endif
                        @else
                            @if($rowJob)
                                <p class="text-xs font-semibold text-[#111111]">{{ $rowJob }}</p>
                                @if($rowCompany)<p class="text-xs text-[#111111] mt-0.5">{{ $rowCompany }}</p>@endif
                            @elseif($rowCompany)
                                <p class="text-xs text-[#111111]">{{ $rowCompany }}</p>
                            @else
                                <span class="text-xs text-[#111111]">—</span>
                            @endif
                        @endif
                    </td>
                    @if($showEmailContactSplit)
                    <td class="px-4 py-3" style="min-width:140px;">
                        @if($rowContact)
                            <p class="text-xs text-[#111111]">{{ $rowContact }}</p>
                        @else
                            <span class="text-xs text-[#111111]">—</span>
                        @endif
                    </td>
                    @endif
                    @if($showLocationCol)
                    <td class="px-4 py-3 text-center">
                        @if($loc)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $loc[1] }}">
                                {{ $loc[0] }}
                            </span>
                        @else
                            <span class="text-xs text-[#111111]">—</span>
                        @endif
                    </td>
                    @endif
                    @if($showRelevanceCol)
                    <td class="px-4 py-3 text-center">
                        @if($relBdg)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $relBdg[1] }}">
                                {{ $relBdg[0] }}
                            </span>
                        @else
                            <span class="text-xs text-[#111111]">—</span>
                        @endif
                    </td>
                    @endif
                    <td class="px-4 pr-6 lg:pr-10 py-3 text-center">
                        <span class="text-sm font-semibold text-[#111111]">{{ $row->batch ?? '—' }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $totalCols }}" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-[#F0E6F8]">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No records found</p>
                            <p class="text-xs text-[#111111]">Try adjusting your filters or search terms</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Footer Pagination --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7a3f91;">
        <p class="text-white/70 text-sm font-normal">
            Showing <strong class="text-white font-semibold">{{ $rFrom }}–{{ $rTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($rTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
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
@endif


{{-- ═══════════════════════════════════════════════════════════
     PRINT / RECORDS MODAL
═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'reports')
@php
    $recs = $this->reportRecords;
    $tots = $this->reportTotals;
    $allPrintRecs = $this->allReportRecordsForPrint;

    $empStatusMap = [
        'employed'      => ['Employed',      'text-emerald-700 bg-emerald-50 border-emerald-200'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200'],
    ];

    $rEmp   = $tots->emp        ?? 0;
    $rSelf  = $tots->self_emp   ?? 0;
    $rUnemp = $tots->unemp      ?? 0;
    $rNone  = $tots->none_count ?? 0;
    $rTotal = $tots->total      ?? 0;

    $printTitle = 'Employment Report';
    if ($reportBatch && $reportCourse)  $printTitle .= ' — Batch '.$reportBatch.' · '.$reportCourse;
    elseif ($reportBatch)              $printTitle .= ' — Batch '.$reportBatch;
    elseif ($reportCourse)             $printTitle .= ' — '.$reportCourse;
    if ($reportStatus === 'employed')      $printTitle .= ' (Employed)';
    if ($reportStatus === 'self_employed') $printTitle .= ' (Self-Employed)';
    if ($reportStatus === 'unemployed')    $printTitle .= ' (Unemployed)';
    if ($reportStatus === 'no_record')     $printTitle .= ' (No Record)';

    $rrTotal    = $recs->total();
    $rrPp       = $recs->perPage();
    $rrCp       = $recs->currentPage();
    $rrLastPage = $recs->lastPage();
    $rrFrom     = $rrTotal > 0 ? ($rrCp - 1) * $rrPp + 1 : 0;
    $rrTo       = min($rrCp * $rrPp, $rrTotal);
    $rrPgStart  = max(1, $rrCp - 2);
    $rrPgEnd    = min($rrLastPage, $rrCp + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 emp-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow" style="background:#7a3f91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-print text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">Print Employment Records</h2>
                <p class="text-white/60 text-xs">Filter, preview, and print detailed employment records</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button
                x-data="{ busy: false }"
                @click="busy = true; $wire.loadPrintData()"
                @emp-print-ready.window="busy = false; empPrintReport()"
                :disabled="busy"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white/15 hover:bg-white/25 border border-white/25 text-white transition active:scale-95 disabled:opacity-60 disabled:cursor-wait">
                <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-print'"></i>
                <span x-text="busy ? 'Preparing…' : 'Print'"></span>
            </button>
            <button wire:click="closeModal"
                    class="group relative w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white
                           flex items-center justify-center hover:bg-white/20 transition cursor-pointer overflow-visible">
                <span class="absolute top-[calc(100%+8px)] right-0 bg-gray-900/90 text-white text-[10px] font-bold
                             uppercase tracking-widest px-2.5 py-1 rounded-lg whitespace-nowrap pointer-events-none
                             opacity-0 group-hover:opacity-100 transition-opacity shadow-lg z-50
                             before:content-[''] before:absolute before:bottom-full before:right-2.5
                             before:border-4 before:border-transparent before:border-b-gray-900/90">Close</span>
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>
    </div>

    {{-- Filters bar --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0 flex flex-wrap gap-3 items-center">
        <span class="text-xs font-bold uppercase tracking-wider text-[#7A3F91]">
            <i class="fas fa-filter mr-1 text-[10px]"></i>Filter Records:
        </span>

        <div class="relative" x-data="{ open:false }" @click.outside="open=false">
            <button type="button" @click="open=!open"
                    class="inline-flex items-center gap-1.5 px-3 py-2 border rounded-lg text-xs font-semibold bg-white text-[#111111] cursor-pointer transition
                           {{ $reportBatch !== '' ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 hover:border-purple-300' }}">
                <i class="fas fa-calendar-alt text-[.68rem] opacity-70"></i>
                @if($reportBatch) Batch {{ $reportBatch }} @else All Batches @endif
                <i class="fas fa-chevron-down text-[.62rem] opacity-60 transition-transform" :class="{'rotate-180':open}"></i>
            </button>
            <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                 bg-white border-[1.5px] border-[#E8E0F0] rounded-xl shadow-xl z-[600] p-1" style="display:none;">
                <button type="button" @click="$wire.set('reportBatch',''); open=false"
                        class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111] hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $reportBatch === '' ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">All Batches</button>
                @foreach($this->availableBatches as $bYear)
                <button type="button" @click="$wire.set('reportBatch','{{ $bYear }}'); open=false"
                        class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111] hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $reportBatch == $bYear ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">Batch {{ $bYear }}</button>
                @endforeach
            </div>
        </div>

        <div class="relative" x-data="{ open:false }" @click.outside="open=false">
            <button type="button" @click="open=!open"
                    class="inline-flex items-center gap-1.5 px-3 py-2 border rounded-lg text-xs font-semibold bg-white text-[#111111] cursor-pointer transition
                           {{ $reportCourse !== '' ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 hover:border-purple-300' }}">
                <i class="fas fa-book-open text-[.68rem] opacity-70"></i>
                @if($reportCourse) {{ $reportCourse }} @else All Courses @endif
                <i class="fas fa-chevron-down text-[.62rem] opacity-60 transition-transform" :class="{'rotate-180':open}"></i>
            </button>
            <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                 bg-white border-[1.5px] border-[#E8E0F0] rounded-xl shadow-xl z-[600] p-1" style="display:none;">
                <button type="button" @click="$wire.set('reportCourse',''); open=false"
                        class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111] hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $reportCourse === '' ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">All Courses</button>
                @foreach($this->availableCourses as $cCode)
                <button type="button" @click="$wire.set('reportCourse','{{ $cCode }}'); open=false"
                        class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111] hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $reportCourse === $cCode ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">{{ $cCode }}</button>
                @endforeach
            </div>
        </div>

        <div class="relative" x-data="{ open:false }" @click.outside="open=false">
            <button type="button" @click="open=!open"
                    class="inline-flex items-center gap-1.5 px-3 py-2 border rounded-lg text-xs font-semibold bg-white text-[#111111] cursor-pointer transition
                           {{ $reportStatus !== '' ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : 'border-gray-200 hover:border-purple-300' }}">
                <i class="fas fa-filter text-[.68rem] opacity-70"></i>
                @if($reportStatus === 'employed') Employed
                @elseif($reportStatus === 'self_employed') Self-Employed
                @elseif($reportStatus === 'unemployed') Unemployed
                @elseif($reportStatus === 'no_record') No Record
                @else All Status
                @endif
                <i class="fas fa-chevron-down text-[.62rem] opacity-60 transition-transform" :class="{'rotate-180':open}"></i>
            </button>
            <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-56 overflow-y-auto
                 bg-white border-[1.5px] border-[#E8E0F0] rounded-xl shadow-xl z-[600] p-1" style="display:none;">
                @foreach([
                    ['','All Status'],['employed','Employed'],['self_employed','Self-Employed'],
                    ['unemployed','Unemployed'],['no_record','No Record']
                ] as [$val,$lbl])
                <button type="button" @click="$wire.set('reportStatus','{{ $val }}'); open=false"
                        class="block w-full px-3 py-1.5 rounded-lg text-left text-xs font-semibold text-[#111111] hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition {{ $reportStatus === $val ? 'bg-[#F0E6F8] text-[#7A3F91]' : '' }}">{{ $lbl }}</button>
                @endforeach
            </div>
        </div>

        <span class="ml-auto text-xs font-semibold px-3 py-1.5 rounded-lg bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">
            {{ number_format($rrTotal) }} records
        </span>
    </div>

    {{-- Summary cards --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
            @foreach([
                [$rTotal, 'Total',        'bg-[#F9F7FC]',  'text-[#7A3F91]',  'border-[#E8E0F0]',  'fa-users'],
                [$rEmp,   'Employed',     'bg-emerald-50', 'text-emerald-700', 'border-emerald-200', 'fa-user-tie'],
                [$rSelf,  'Self-Employed','bg-blue-50',    'text-blue-700',    'border-blue-200',    'fa-store'],
                [$rUnemp, 'Unemployed',   'bg-amber-50',   'text-amber-700',   'border-amber-200',   'fa-magnifying-glass'],
                [$rNone,  'No Record',    'bg-gray-50',    'text-[#111111]',   'border-gray-200',    'fa-circle-minus'],
            ] as [$cnt,$lbl,$bg,$color,$border,$ico])
            <div class="rounded-xl p-3 flex items-center gap-2.5 border {{ $bg }} {{ $border }}">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $bg }}">
                    <i class="fas {{ $ico }} text-xs {{ $color }}"></i>
                </div>
                <div>
                    <p class="text-base font-black leading-none {{ $color }}">{{ number_format($cnt) }}</p>
                    <p class="text-[10px] font-semibold mt-0.5 {{ $color }}">{{ $lbl }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Preview table --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin;">
        <div wire:loading wire:target="reportBatch,reportCourse,reportStatus,reportPage,reportPrev,reportNext"
             class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none bg-white/60">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
            </div>
        </div>
        <div wire:loading.remove wire:target="reportBatch,reportCourse,reportStatus,reportPage,reportPrev,reportNext">
            @if($recs->isEmpty())
            <div class="flex flex-col items-center gap-3 py-20">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-[#F0E6F8]">
                    <i class="fas fa-file-circle-xmark text-xl" style="color:#c89de0;"></i>
                </div>
                <p class="text-sm font-semibold text-[#333333]">No records match your filters</p>
                <p class="text-xs text-[#111111]">Try adjusting the batch, course, or status filter above</p>
            </div>
            @else
            <table class="w-full border-collapse" style="min-width:680px;">
                <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">#</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Name</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Student ID</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Course</th>
                        <th class="px-3 py-2.5 text-center text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Batch</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Status</th>
                        <th class="px-3 pr-6 lg:pr-10 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-[#7A3F91]">Email Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($recs as $i => $r)
                    @php
                        $rowN      = ($recs->currentPage() - 1) * $recs->perPage() + $i + 1;
                        $sLabel    = $empStatusMap[$r->employment_status ?? ''] ?? null;
                        $fullName  = trim(
                            strtoupper($r->last_name ?? '').', '.
                            strtoupper($r->first_name ?? '').
                            (!empty($r->middle_initial) ? ' '.strtoupper(substr($r->middle_initial,0,1)).'.' : '').
                            (!empty($r->suffix) ? ' '.strtoupper($r->suffix) : '')
                        );
                    @endphp
                    <tr class="transition-colors {{ $i % 2 === 0 ? 'bg-white' : 'bg-[#F9F7FC]/40' }} hover:bg-[#F5F0FA]">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold text-[#7A3F91]/40">{{ str_pad($rowN,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <p class="font-semibold text-xs uppercase whitespace-nowrap text-[#111111]">{{ $fullName }}</p>
                        </td>
                        <td class="px-3 py-3">
                            <p class="font-mono text-xs text-[#111111]">{{ $r->student_id ?? '—' }}</p>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#F9F7FC] text-[#7A3F91]">{{ $r->course_code ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded bg-[#F9F7FC] text-[#7A3F91]">{{ $r->batch ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-3">
                            @if($sLabel)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $sLabel[1] }}">
                                    {{ $sLabel[0] }}
                                </span>
                            @else
                                <span class="text-[10px] font-semibold text-[#111111]">No Record</span>
                            @endif
                        </td>
                        <td class="px-3 pr-6 lg:pr-10 py-3">
                            @if($r->email ?? null)
                                <span class="text-[11px] text-[#111111]">{{ $r->email }}</span>
                            @else
                                <span class="text-[10px] text-[#111111]">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>

    {{-- Report pagination footer --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7a3f91;">
        <p class="text-white/70 text-sm font-normal">
            Showing <strong class="text-white font-semibold">{{ $rrFrom }}–{{ $rrTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($rrTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @if($rrCp <= 1) disabled @endif wire:click="reportPrev" class="emp-pg-btn emp-pg-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @if($rrPgStart > 1)
                <button wire:click="$set('reportPage',1)" class="emp-pg-btn emp-pg-nav">1</button>
                @if($rrPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $rrPgStart; $p <= $rrPgEnd; $p++)
                @if($p === $rrCp)
                    <span class="emp-pg-btn emp-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('reportPage',{{ $p }})" class="emp-pg-btn emp-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            @if($rrPgEnd < $rrLastPage)
                @if($rrPgEnd < $rrLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('reportPage',{{ $rrLastPage }})" class="emp-pg-btn emp-pg-nav">{{ $rrLastPage }}</button>
            @endif
            <button @if($rrCp >= $rrLastPage) disabled @endif wire:click="reportNext" class="emp-pg-btn emp-pg-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $rrCp }} / {{ $rrLastPage }}</span>
        </div>
    </div>

    {{-- Print area --}}
    <div id="emp-print-area">
        <div style="font-family:'Times New Roman',Times,serif;font-size:9pt;color:#000;padding:14px 20px;background:#fff;">
            <div style="border-bottom:2px solid #000;margin-bottom:8px;padding-bottom:6px;display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h1 style="font-size:12pt;font-weight:bold;color:#000;margin:0 0 2px;font-family:'Times New Roman',Times,serif;">{{ $printTitle }}</h1>
                    <p style="font-size:8pt;color:#000;margin:0;font-family:'Times New Roman',Times,serif;">
                        Generated: {{ now()->format('F j, Y · g:i A') }}
                        @if($reportBatch) &nbsp;·&nbsp; Batch {{ $reportBatch }}@endif
                        @if($reportCourse) &nbsp;·&nbsp; {{ $reportCourse }}@endif
                    </p>
                </div>
                <p style="font-size:8pt;color:#000;font-weight:bold;margin:0;text-align:right;font-family:'Times New Roman',Times,serif;">
                    Total: {{ number_format($allPrintRecs->count()) }} records
                </p>
            </div>
            <div style="margin-bottom:8px;font-size:8pt;color:#000;font-family:'Times New Roman',Times,serif;border-bottom:1px solid #999;padding-bottom:5px;">
                <strong>Summary:</strong>
                &nbsp; Total: <strong>{{ number_format($rTotal) }}</strong>
                &nbsp;|&nbsp; Employed: <strong>{{ number_format($rEmp) }}</strong>
                &nbsp;|&nbsp; Self-Employed: <strong>{{ number_format($rSelf) }}</strong>
                &nbsp;|&nbsp; Unemployed: <strong>{{ number_format($rUnemp) }}</strong>
                &nbsp;|&nbsp; No Record: <strong>{{ number_format($rNone) }}</strong>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:9pt;font-family:'Times New Roman',Times,serif;color:#000;">
                <thead>
                    <tr style="border-top:1.5px solid #000;border-bottom:1.5px solid #000;">
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;white-space:nowrap;">#</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;white-space:nowrap;">Name</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;white-space:nowrap;">Student ID</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;white-space:nowrap;">Course</th>
                        <th style="padding:3px 5px;text-align:center;font-weight:bold;white-space:nowrap;">Batch</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;white-space:nowrap;">Status</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;">Email Address</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allPrintRecs as $pi => $pr)
                    @php
                        $pName = trim(
                            strtoupper($pr->last_name ?? '') . ', ' .
                            strtoupper($pr->first_name ?? '') .
                            (!empty($pr->middle_initial) ? ' '.strtoupper(substr($pr->middle_initial,0,1)).'.' : '') .
                            (!empty($pr->suffix) ? ' '.strtoupper($pr->suffix) : '')
                        );
                    @endphp
                    <tr style="border:none;">
                        <td style="padding:2px 5px;border:none;white-space:nowrap;">{{ $pi + 1 }}</td>
                        <td style="padding:2px 5px;font-weight:bold;border:none;white-space:nowrap;">{{ $pName }}</td>
                        <td style="padding:2px 5px;border:none;white-space:nowrap;">{{ $pr->student_id ?? '—' }}</td>
                        <td style="padding:2px 5px;font-weight:bold;border:none;white-space:nowrap;">{{ $pr->course_code ?? '—' }}</td>
                        <td style="padding:2px 5px;text-align:center;border:none;white-space:nowrap;">{{ $pr->batch ?? '—' }}</td>
                        <td style="padding:2px 5px;border:none;white-space:nowrap;">{{ ucfirst(str_replace('_',' ',$pr->employment_status ?? 'No Record')) }}</td>
                        <td style="padding:2px 5px;border:none;">{{ $pr->email ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:8px;border-top:1px solid #000;padding-top:4px;display:flex;justify-content:space-between;">
                <p style="font-size:8pt;margin:0;">Employment Tracking System &nbsp;·&nbsp; {{ now()->format('F j, Y') }}</p>
                <p style="font-size:8pt;margin:0;">{{ number_format($allPrintRecs->count()) }} total records printed</p>
            </div>
        </div>
    </div>

</div>
@endif


{{-- ══ CHARTS + SCRIPT ══ --}}
<script>
(function () {
    'use strict';

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

    window.empOpenModal = function (filter, course, batch) {
        window.dispatchEvent(new CustomEvent('open-emp-modal', {
            detail: { filter: filter || '', batch: batch || null, course: course || '' }
        }));
    };

    window.empPrintReport = function () {
        var area = document.getElementById('emp-print-area');
        if (!area) return;
        var old = document.getElementById('emp-print-clone');
        if (old) document.body.removeChild(old);
        var clone = area.cloneNode(true);
        clone.id = 'emp-print-clone';
        clone.style.display = 'block';
        clone.style.position = 'static';
        clone.style.background = '#fff';
        document.body.appendChild(clone);
        window.print();
        setTimeout(function () {
            var c = document.getElementById('emp-print-clone');
            if (c) document.body.removeChild(c);
        }, 2000);
    };

    (function () {
        var tip = document.getElementById('cb-cursor-tip');
        function bindCbRows() {
            document.querySelectorAll('.cb-table-row').forEach(function (row) {
                if (row._cbTipBound) return;
                row._cbTipBound = true;
                row.addEventListener('mousemove', function (e) {
                    if (!tip) return;
                    tip.style.left = e.clientX + 'px';
                    tip.style.top  = e.clientY + 'px';
                    tip.classList.add('visible');
                });
                row.addEventListener('mouseleave', function () { if (!tip) return; tip.classList.remove('visible'); });
                row.addEventListener('click',      function () { if (!tip) return; tip.classList.remove('visible'); });
            });
        }
        bindCbRows();
        document.addEventListener('livewire:updated', bindCbRows);
    })();

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
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                layout: { padding: { top: 4, bottom: 4, left: 4, right: 4 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 9, weight: '600' },
                            color: '#333333',
                            padding: 6,
                            usePointStyle: true,
                            pointStyleWidth: 6,
                            boxHeight: 6,
                            generateLabels: function (chart) {
                                var d = chart.data;
                                if (d.labels.length && d.datasets.length) {
                                    return d.labels.map(function (label, i) {
                                        var ds     = d.datasets[0];
                                        var meta   = chart.getDatasetMeta(0);
                                        var hidden = meta.data[i] ? !chart.getDataVisibility(i) : false;
                                        return {
                                            text:        label.length > 14 ? label.substring(0, 13) + '…' : label,
                                            fillStyle:   ds.backgroundColor[i],
                                            strokeStyle: ds.backgroundColor[i],
                                            lineWidth:   0,
                                            hidden:      hidden,
                                            index:       i,
                                            pointStyle:  'circle',
                                        };
                                    });
                                }
                                return [];
                            },
                        },
                        onClick: function (e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart, index = legendItem.index;
                            chart.getDataVisibility(index) ? chart.hide(index) : chart.show(index);
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
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var filter = (data.filters && data.filters[elements[0].index]) ? data.filters[elements[0].index] : '';
                        if (!filter) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', { detail: { filter: filter, batch: null, course: '' } }));
                    }
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

    function buildBatchBar(data, startIdx) {
        if (!data || !data.labels || !data.labels.length) return;
        var slice  = sliceBatch(data, startIdx);
        var canvas = document.getElementById('chartBatch');
        if (!canvas) return;
        safeDestroy('chartBatch');

        var bgColors     = slice.labels.map(function (_, i) { return BAR_COLORS[i % BAR_COLORS.length].bg; });
        var borderColors = slice.labels.map(function (_, i) { return BAR_COLORS[i % BAR_COLORS.length].border; });

        var totals = slice.labels.map(function (_, i) {
            return (parseInt(slice.employed[i])   || 0) +
                   (parseInt(slice.self_emp[i])   || 0) +
                   (parseInt(slice.unemployed[i]) || 0);
        });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: slice.labels,
                datasets: [{
                    label: 'Submitted Employment',
                    data:  totals,
                    backgroundColor: bgColors,
                    borderColor:     borderColors,
                    borderWidth: 1.5,
                    borderRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return 'Batch ' + items[0].label; },
                            label: function (ctx) {
                                var i = ctx.dataIndex;
                                return [
                                    '  Employed:      ' + (parseInt(slice.employed[i])   || 0),
                                    '  Self-Employed: ' + (parseInt(slice.self_emp[i])   || 0),
                                    '  Unemployed:    ' + (parseInt(slice.unemployed[i]) || 0),
                                    '  Submitted:     ' + ctx.parsed.y,
                                ];
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#111111' },
                    },
                    y: {
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 10, weight: '500' }, color: '#333333', precision: 0 },
                        beginAtZero: true,
                    },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (!elements || !elements.length) return;
                    var batch = slice.labels[elements[0].index];
                    if (batch === undefined || batch === null) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal', { detail: { filter: '', batch: parseInt(batch), course: '' } }));
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
            navEl.classList.remove('hidden');
            navEl.classList.add('flex');
            if (infoEl)  infoEl.textContent  = currentPage + ' / ' + totalPages;
            if (prevBtn) prevBtn.disabled     = (startIdx <= 0);
            if (nextBtn) nextBtn.disabled     = (startIdx + BATCH_PAGE_SIZE >= totalBatches);
        } else if (navEl) {
            navEl.classList.add('hidden');
            navEl.classList.remove('flex');
        }
    }

    function buildCourseBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        safeDestroy('chartCourse');

        var bgColors     = data.labels.map(function (_, i) { return BAR_COLORS[i % BAR_COLORS.length].bg; });
        var borderColors = data.labels.map(function (_, i) { return BAR_COLORS[i % BAR_COLORS.length].border; });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Working Alumni',
                    data:  data.data,
                    backgroundColor: bgColors,
                    borderColor:     borderColors,
                    borderWidth: 1.5,
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return items[0].label; },
                            label: function (ctx)   { return '  ' + ctx.parsed.x + ' working alumni'; },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 10, weight: '500' }, color: '#333333', precision: 0 },
                        beginAtZero: true,
                    },
                    y: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#111111' },
                    },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (!elements || !elements.length) return;
                    var course = data.labels[elements[0].index];
                    if (!course) return;
                    window.dispatchEvent(new CustomEvent('open-emp-modal', { detail: { filter: 'employed_all', batch: null, course: course } }));
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
            batchPageIndex = Math.min(max < 0 ? 0 : max, batchPageIndex + BATCH_PAGE_SIZE);
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
            allBatchData = null; batchPageIndex = 0;
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

        if (window.Livewire) { hookLivewire(); }
        else { document.addEventListener('livewire:initialized', hookLivewire); }
    });

})();
</script>