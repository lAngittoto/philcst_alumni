<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
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

    // ── Allowed sort columns (whitelist for security) ─────────────────────────
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

    protected array $allowedStatuses   = ['employed', 'self_employed', 'unemployed', 'no_record', ''];
    protected array $allowedLocations  = ['local', 'abroad', ''];
    protected array $allowedRelevances = ['yes', 'no', 'partially', 'relevant', 'not_relevant', 'partially_relevant', ''];

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

    // ── Report records with pagination ────────────────────────────────────────

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

    // ── All report records for print ──────────────────────────────────────────

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

    // ── Modal records ─────────────────────────────────────────────────────────

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

        if ($this->modalFilter === 'employed') {
            $q->where('et.employment_status', 'employed');
        } elseif ($this->modalFilter === 'self_employed') {
            $q->where('et.employment_status', 'self_employed');
        } elseif ($this->modalFilter === 'unemployed') {
            $q->where('et.employment_status', 'unemployed');
        } elseif ($this->modalFilter === 'employed_all') {
            $q->whereIn('et.employment_status', ['employed', 'self_employed']);
        }

        if (in_array($this->modalFilter, ['abroad','local'])) {
            $q->where('et.work_location', $this->modalFilter)
              ->whereIn('et.employment_status', ['employed','self_employed']);
        }

        if ($this->modalFilter === 'relevance_all') {
            $active = array_values(array_filter($this->modalRelevanceActive));
            $dbValues = array_map(fn($v) => match($v) {
                'relevant'           => ['yes', 'relevant'],
                'partially_relevant' => ['partially', 'partially_relevant'],
                'not_relevant'       => ['no', 'not_relevant'],
                default              => [$v],
            }, $active);
            $flat = array_merge(...($dbValues ?: [[]]));
            if ($flat) {
                $q->whereIn('et.course_relevance', $flat);
            } else {
                $q->whereNotNull('et.course_relevance');
            }
        }

        if ($this->modalBatch !== null) $q->where('a.batch', $this->modalBatch);
        if ($this->modalCourse !== '')  $q->where('a.course_code', $this->modalCourse);

        if ($this->modalSearch) {
            $term = '%' . str_replace(['%','_'], ['\%','\_'], $this->modalSearch) . '%';
            $q->where(fn($s) => $s
                ->where('a.first_name',    'like', $term)
                ->orWhere('a.last_name',   'like', $term)
                ->orWhere('a.student_id',  'like', $term)
                ->orWhere('a.course_code', 'like', $term)
                ->orWhere('a.email',       'like', $term)
                ->orWhere('a.contact_number','like', $term)
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

    // ── Modal actions ─────────────────────────────────────────────────────────

    public function openModal(string $filter = '', ?int $batch = null, string $course = ''): void
    {
        $allowedFilters = ['', 'employed', 'employed_all', 'self_employed', 'unemployed', 'no_record',
                           'abroad', 'local', 'relevance_yes', 'relevance_partially',
                           'relevance_no', 'relevance_all'];
        if (!in_array($filter, $allowedFilters, true)) {
            $filter = '';
        }

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
    }

    public function updatingModalSearch(): void { $this->modalPage = 1; }
    public function updatingModalBatch(): void  { $this->modalPage = 1; }
    public function updatingModalCourse(): void { $this->modalPage = 1; }

    public function updatingReportBatch(): void  {
        $this->reportPage = 1;
        $this->showPrintData = false;
        unset($this->reportRecords);
        unset($this->reportTotals);
        unset($this->allReportRecordsForPrint);
    }
    public function updatingReportCourse(): void {
        $this->reportPage = 1;
        $this->showPrintData = false;
        unset($this->reportRecords);
        unset($this->reportTotals);
        unset($this->allReportRecordsForPrint);
    }
    public function updatingReportStatus(): void {
        $this->reportPage = 1;
        $this->showPrintData = false;
        unset($this->reportRecords);
        unset($this->reportTotals);
        unset($this->allReportRecordsForPrint);
    }

    public function reportPrev(): void { if ($this->reportPage > 1) { $this->reportPage--; unset($this->reportRecords); } }
    public function reportNext(): void
    {
        $records = $this->reportRecords;
        if ($this->reportPage < $records->lastPage()) { $this->reportPage++; unset($this->reportRecords); }
    }

    public function modalPrev(): void { if ($this->modalPage > 1) $this->modalPage--; }
    public function modalNext(): void
    {
        if ($this->modalPage < $this->modalRecords->lastPage()) $this->modalPage++;
    }

    public function clearModalFilters(): void
    {
        $this->modalBatch = null; $this->modalBatchLocked = false;
        $this->modalCourse = ''; $this->modalCourseLocked = false;
        $this->modalSearch = ''; $this->modalPage = 1;
        unset($this->modalRecords);
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
        unset($this->modalRecords);
        unset($this->reportRecords);
        unset($this->reportTotals);
        unset($this->allReportRecordsForPrint);
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
}; ?>

<div @open-emp-modal.window="$wire.openModal($event.detail.filter, $event.detail.batch ?? null, $event.detail.course ?? '')">

{{-- Global cursor-following "VIEW DETAILS" tooltip --}}
<div id="emp-cursor-tip"
     style="position:fixed;display:none;pointer-events:none;z-index:99999;
            background:#111111;color:#ffffff;font-size:11px;font-weight:700;
            padding:5px 13px 5px 10px;border-radius:7px;letter-spacing:.06em;
            white-space:nowrap;box-shadow:0 3px 14px rgba(0,0,0,.40);
            border:1px solid #333333;user-select:none;">
    <i class="fas fa-eye" style="margin-right:6px;font-size:10px;opacity:.85;"></i>VIEW DETAILS
</div>

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
        to   { opacity:1; transform:translateY(0); }
    }
    @keyframes countUp {
        from { opacity:0; transform:translateY(6px) scale(.95); }
        to   { opacity:1; transform:none; }
    }

    .emp-modal-enter { animation: empPageIn .20s cubic-bezier(.4,0,.2,1) both; }

    .emp-page-wrapper { height:90vh; max-height:90vh; overflow:hidden; background:var(--bg); display:flex; flex-direction:column; }
    .emp-page-scroll  { flex:1; overflow-y:auto; scrollbar-width:thin; scrollbar-color:#d4b8e8 transparent; }
    .emp-page-scroll::-webkit-scrollbar { width:5px; }
    .emp-page-scroll::-webkit-scrollbar-thumb { background:#d4b8e8; border-radius:99px; }
    .emp-inner { display:flex; flex-direction:column; padding:14px 24px 20px; gap:14px; max-width:1920px; margin:0 auto; width:100%; box-sizing:border-box; }

    /* ── Stat Cards ── */
    .stat-card {
        background:var(--surface); border:1px solid var(--border); border-radius:14px;
        padding:10px 14px; display:flex; align-items:center; gap:10px;
        box-shadow:0 1px 3px rgba(0,0,0,.04);
        transition:box-shadow .15s, border-color .15s;
        cursor:pointer; flex:1; min-width:0; position:relative; overflow:visible;
    }
    .stat-card:hover { box-shadow:0 5px 16px rgba(122,63,145,.13); border-color:rgba(122,63,145,.35); }

    .stat-card .card-hover-tip {
        position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%);
        background:#1e1131; color:#fff; font-size:10px; font-weight:600; letter-spacing:.04em;
        padding:5px 10px; border-radius:7px; white-space:nowrap; pointer-events:none;
        opacity:0; transition:opacity .15s ease; z-index:100; box-shadow:0 4px 12px rgba(0,0,0,.25);
    }
    .stat-card .card-hover-tip::after {
        content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%);
        border:5px solid transparent; border-top-color:#1e1131;
    }
    .stat-card:hover .card-hover-tip { opacity:1; }

    .stat-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .stat-number { font-size:1.45rem; font-weight:700; line-height:1; color:var(--ink); animation:countUp .3s ease both; }
    .stat-label { font-size:.72rem; font-weight:600; color:var(--muted); margin-top:2px; }
    .stat-rate  { font-size:.68rem; font-weight:700; margin-top:3px; }

    /* ── Chart Cards ── */
    .chart-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); overflow:hidden; display:flex; flex-direction:column; cursor:pointer; transition:box-shadow .18s,border-color .18s; }
    .chart-card:hover { box-shadow:0 5px 16px rgba(122,63,145,.11); border-color:rgba(122,63,145,.28); }
    .chart-header { padding:8px 14px; border-bottom:1px solid var(--border); background:var(--subtle-bg); display:flex; align-items:center; gap:7px; flex-shrink:0; }
    .chart-dot   { width:8px; height:8px; border-radius:50%; background:var(--primary); flex-shrink:0; }
    .chart-title { font-size:.78rem; font-weight:700; color:var(--ink); text-transform:uppercase; letter-spacing:.06em; }
    .chart-body  { padding:10px; flex:1; min-height:0; }
    .chart-hint  { font-size:.68rem; color:#bbb; font-weight:500; margin-left:auto; display:flex; align-items:center; gap:3px; pointer-events:none; }

    /* ── Batch nav ── */
    .batch-nav-btn { width:28px; height:28px; border-radius:7px; border:1px solid var(--border); background:#fff; color:var(--primary); font-size:.75rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s,border-color .15s; flex-shrink:0; }
    .batch-nav-btn:hover:not(:disabled) { background:var(--primary-lt); border-color:var(--primary); }
    .batch-nav-btn:disabled { opacity:.35; cursor:not-allowed; }
    .batch-page-info { font-size:.74rem; font-weight:600; color:var(--muted); white-space:nowrap; }

    /* ── Relevance / filter chips ── */
    .rel-chip-active   { background:#7A3F91; color:#fff; border-color:transparent; box-shadow:0 2px 8px rgba(122,63,145,.25); }
    .rel-chip-inactive { background:#fff; color:#aaa; border-color:#e5e7eb; opacity:0.65; }
    .rel-chip-inactive:hover { opacity:1; border-color:#d4aaeb; color:#7A3F91; }
    .filter-chip-active { background:#7A3F91; color:#fff; border-color:transparent; }

    /* ── Custom dropdown ── */
    .emp-dropdown { position:relative; }
    .emp-dropdown-menu { position:absolute; top:calc(100% + 4px); left:0; min-width:100%; max-height:220px; overflow-y:auto; background:#fff; border:1.5px solid #E8E0F0; border-radius:10px; box-shadow:0 8px 24px rgba(122,63,145,.13); z-index:600; padding:4px; scrollbar-width:thin; scrollbar-color:#d4b8e8 transparent; }
    .emp-dropdown-menu::-webkit-scrollbar { width:5px; }
    .emp-dropdown-menu::-webkit-scrollbar-thumb { background:#d4b8e8; border-radius:99px; }
    .emp-dropdown-item { display:block; width:100%; padding:7px 10px; border-radius:7px; font-size:.78rem; font-weight:600; text-align:left; color:#333; transition:background .1s; cursor:pointer; white-space:nowrap; border:none; background:transparent; }
    .emp-dropdown-item:hover { background:#F5F0FA; color:#7A3F91; }
    .emp-dropdown-item.active { background:#F0E6F8; color:#7A3F91; }
    .emp-dropdown-trigger { display:inline-flex; align-items:center; gap:6px; padding:7px 11px; border:1.5px solid #E8E0F0; border-radius:8px; font-size:.78rem; font-weight:600; background:#fff; color:#555; cursor:pointer; transition:border-color .15s,background .15s,color .15s; white-space:nowrap; user-select:none; }
    .emp-dropdown-trigger:hover { border-color:#c49ed8; }
    .emp-dropdown-trigger.has-value { border-color:#7A3F91; background:#F9F7FC; color:#7A3F91; }
    .emp-dropdown-trigger .emp-chevron { transition:transform .18s; font-size:.62rem; opacity:.6; }
    .emp-dropdown-trigger.open .emp-chevron { transform:rotate(180deg); }

    /* ── Pagination ── */
    .emp-pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 10px; border-radius:8px; font-size:.75rem; font-weight:700; transition:all .15s; border:1.5px solid transparent; }
    .emp-pg-active { background:rgba(255,255,255,1); color:#7A3F91; border-color:rgba(255,255,255,1); }
    .emp-pg-nav    { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.25); }
    .emp-pg-nav:hover:not(:disabled) { background:rgba(255,255,255,.28); border-color:rgba(255,255,255,.5); }
    .emp-pg-nav:disabled { opacity:.35; cursor:not-allowed; }

    .rep-pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 10px; border-radius:8px; font-size:.75rem; font-weight:700; transition:all .15s; border:1.5px solid transparent; cursor:pointer; }
    .rep-pg-active { background:#7A3F91; color:#fff; border-color:#7A3F91; }
    .rep-pg-nav { background:#F3E8FF; color:#7A3F91; border-color:#E8E0F0; }
    .rep-pg-nav:hover:not(:disabled) { background:#E8D5F5; border-color:#7A3F91; }
    .rep-pg-nav:disabled { opacity:.35; cursor:not-allowed; }

    /* ── Layout ── */
    .charts-row-top { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; height:240px; }
    .charts-row-batch { height:240px; }
    @media (max-width:1024px) { .charts-row-top { grid-template-columns:repeat(2,1fr); height:auto; } .charts-row-batch { height:220px; } }
    @media (max-width:640px)  { .charts-row-top { grid-template-columns:1fr; height:auto; } }

    /* ── Rate bar ── */
    .rate-bar-track { height:6px; border-radius:99px; background:#f0e6f8; overflow:hidden; }
    .rate-bar-fill  { height:100%; border-radius:99px; background:#7a3f91; transition:width .6s cubic-bezier(.4,0,.2,1); }

    /* ── Course table rows ── */
    .course-table-row { transition: background .12s; cursor: pointer; }
    .course-table-row:hover { background: #EFEFEF; }
    .course-view-hint { display: none !important; }

    .course-table-scroll {
        max-height: 450px; overflow-y: auto; overflow-x: auto;
        scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
    }
    .course-table-scroll::-webkit-scrollbar { width:5px; height:5px; }
    .course-table-scroll::-webkit-scrollbar-thumb { background:#d4b8e8; border-radius:99px; }

    /* ── Close btn ── */
    .modal-close-btn {
        position: relative; display: flex; align-items: center; justify-content: center;
        width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.2); color: #fff; cursor: pointer;
        transition: background .15s; overflow: visible;
    }
    .modal-close-btn:hover { background:rgba(255,255,255,.22); }
    .modal-close-tip {
        position: absolute; top: calc(100% + 8px); right: 0;
        background: rgba(27,6,46,0.88); color: #fff; font-size: 10px; font-weight: 700;
        letter-spacing: .08em; text-transform: uppercase; padding: 4px 10px; border-radius: 7px;
        white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .15s ease;
        z-index: 200; box-shadow: 0 4px 12px rgba(0,0,0,.28);
    }
    .modal-close-tip::before {
        content: ''; position: absolute; bottom: 100%; right: 10px;
        border: 5px solid transparent; border-bottom-color: rgba(27,6,46,0.88);
    }
    .modal-close-btn:hover .modal-close-tip { opacity: 1; }

    /* ── PRINT STYLES ── */
    @media print {
        body > * { display: none !important; }
        body > #emp-print-clone {
            display: block !important;
            position: static !important;
            background: #fff !important;
            padding: 0 !important;
        }
        body > #emp-print-clone * {
            color: #000 !important;
            background: transparent !important;
            font-family: 'Times New Roman', Times, serif !important;
            -webkit-print-color-adjust: economy;
            print-color-adjust: economy;
        }
        body > #emp-print-clone table tr { border: none !important; }
        body > #emp-print-clone table td { border: none !important; }
        body > #emp-print-clone table tbody tr { border-bottom: none !important; }
    }
    #emp-print-area { display: none; }

    /* ── Filters label tag ── */
    .filters-label-tag {
        display: inline-flex; align-items: center; gap: 5px; font-size: .72rem; font-weight: 700;
        color: #7A3F91; letter-spacing: .07em; text-transform: uppercase; padding: 4px 10px;
        border-radius: 8px; border: 1.5px solid #E8E0F0; background: #F9F7FC;
        white-space: nowrap; flex-shrink: 0;
    }

    /* ── Header ── */
    .emp-header-bar { background: #7A3F91; }
    .emp-footer-bar { background: #7A3F91; }
</style>

{{-- Chart data bridge --}}
<div id="__emp_chart_data" style="display:none"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-course="{{ $chartCourseData }}">
</div>

<div class="emp-page-wrapper">
<div class="emp-page-scroll">
<div class="emp-inner">

    {{-- PAGE HEADER --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md flex-shrink-0"
                 style="background:#7a3f91;">
                <i class="fas fa-chart-column text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold leading-tight" style="color:var(--ink);">Employment Tracking</h1>
                <p class="text-xs font-normal mt-0.5" style="color:var(--muted);">
                    System-wide alumni employment analytics &amp; records
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openReports"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold shadow text-white cursor-pointer transition-all active:scale-95"
                    style="background:#7a3f91;">
                <i class="fas fa-print"></i>
                <span>Print</span>
            </button>
        </div>
    </div>

    {{-- STAT CARDS --}}
    @php
        $empRate      = $totalAlumni > 0 ? round(($totalEmployed + $totalSelf) / $totalAlumni * 100, 1) : 0;
        $responseRate = $totalAlumni > 0 ? round($totalSubmitted / $totalAlumni * 100, 1) : 0;
        $ofwRate      = $totalSubmitted > 0 ? round($totalAbroad / $totalSubmitted * 100, 1) : 0;
        $unempRate    = $totalSubmitted > 0 ? round($totalUnemployed / $totalSubmitted * 100, 1) : 0;
        $nfRate       = $totalAlumni > 0 ? round($totalNotFilled / $totalAlumni * 100, 1) : 0;

        $statCards = [
            ['',              $totalSubmitted,  'fa-file-circle-check', '#ede9fe', '#7a3f91', 'Submitted',    $responseRate . '% response rate',    'View All Submitted Records'],
            ['employed',      $totalEmployed,   'fa-briefcase',         '#d1fae5', '#059669', 'Employed',     ($totalAlumni>0?round($totalEmployed/$totalAlumni*100,1):0).'% of total', 'View Employed Alumni'],
            ['self_employed', $totalSelf,       'fa-store',             '#dbeafe', '#2563eb', 'Self-Employed',($totalAlumni>0?round($totalSelf/$totalAlumni*100,1):0).'% of total',     'View Self-Employed Alumni'],
            ['unemployed',    $totalUnemployed, 'fa-circle-pause',      '#fef3c7', '#d97706', 'Unemployed',   $unempRate.'% of submitted',           'View Unemployed Alumni'],
            ['no_record',     $totalNotFilled,  'fa-circle-question',   '#f3f4f6', '#9ca3af', 'Not Filled',   $nfRate.'% of total',                  'View Alumni With No Record'],
            ['abroad',        $totalAbroad,     'fa-plane-departure',   '#fef9c3', '#b45309', 'OFW/Abroad',   $ofwRate.'% working rate',             'View OFW / Abroad Alumni'],
            ['local',         $totalLocal,      'fa-house',             '#d1fae5', '#059669', 'Local',        ($totalSubmitted>0?round($totalLocal/$totalSubmitted*100,1):0).'% working rate', 'View Locally Working Alumni'],
        ];
    @endphp
    <div class="flex gap-2 flex-wrap lg:flex-nowrap">
        @foreach($statCards as [$filter, $count, $icon, $iconBg, $iconColor, $label, $rate, $tipText])
        <div class="stat-card" wire:click="openModal('{{ $filter }}')">
            <span class="card-hover-tip"><i class="fas fa-eye mr-1"></i> {{ $tipText }}</span>
            <div class="stat-icon" style="background:{{ $iconBg }};"><i class="fa-solid {{ $icon }}" style="color:{{ $iconColor }};font-size:.95rem;"></i></div>
            <div class="min-w-0">
                <p class="stat-number">{{ number_format($count) }}</p>
                <p class="stat-label">{{ $label }}</p>
                <p class="stat-rate" style="color:{{ $iconColor }};">{{ $rate }}</p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- CHARTS --}}
    <div class="flex flex-col gap-3">
        <div class="charts-row-top">
            <div class="chart-card" onclick="empOpenModal('','',null)">
                <div class="chart-header"><div class="chart-dot"></div><span class="chart-title">Status</span><span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click</span></div>
                <div class="chart-body flex items-center justify-center" wire:ignore><canvas id="chartStatus"></canvas></div>
            </div>
            <div class="chart-card" onclick="empOpenModal('','',null)">
                <div class="chart-header"><div class="chart-dot" style="background:#c084fc;"></div><span class="chart-title">Location</span><span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click</span></div>
                <div class="chart-body flex items-center justify-center" wire:ignore><canvas id="chartLocation"></canvas></div>
            </div>
            <div class="chart-card" onclick="empOpenModal('relevance_all','',null)">
                <div class="chart-header"><div class="chart-dot" style="background:#10b981;"></div><span class="chart-title">Relevance</span><span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click</span></div>
                <div class="chart-body flex items-center justify-center" wire:ignore><canvas id="chartRelevance"></canvas></div>
            </div>
            <div class="chart-card" onclick="empOpenModal('employed_all','',null)">
                <div class="chart-header"><div class="chart-dot" style="background:#3b82f6;"></div><span class="chart-title">Top Courses</span><span class="chart-hint"><i class="fas fa-hand-pointer"></i> Click bar</span></div>
                <div class="chart-body" wire:ignore><canvas id="chartCourse"></canvas></div>
            </div>
        </div>
        <div class="chart-card charts-row-batch" onclick="empOpenModal('','',null)">
            <div class="chart-header" style="justify-content:space-between;">
                <div class="flex items-center gap-2">
                    <div class="chart-dot" style="background:#f59e0b;"></div>
                    <span class="chart-title">Employment by Batch Year</span>
                    <span class="chart-hint ml-2"><i class="fas fa-hand-pointer"></i> Click bar</span>
                </div>
                <div id="batchNavControls" class="flex items-center gap-2" style="display:none!important;">
                    <button id="batchPrev" class="batch-nav-btn"><i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i></button>
                    <span id="batchPageInfo" class="batch-page-info"></span>
                    <button id="batchNext" class="batch-nav-btn"><i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i></button>
                </div>
            </div>
            <div class="chart-body" wire:ignore style="flex:1;min-height:0;"><canvas id="chartBatch" style="width:100%;height:100%;"></canvas></div>
        </div>
    </div>

    {{-- COURSE BREAKDOWN TABLE --}}
    @php
    $courseFullNames = [
        'BSIT'     => 'Bachelor of Science in Information Technology',
        'BSCS'     => 'Bachelor of Science in Computer Science',
        'BSCE'     => 'Bachelor of Science in Civil Engineering',
        'BSEd'     => 'Bachelor of Secondary Education',
        'BSED'     => 'Bachelor of Secondary Education',
        'BEEd'     => 'Bachelor of Elementary Education',
        'BEED'     => 'Bachelor of Elementary Education',
        'BSBA'     => 'Bachelor of Science in Business Administration',
        'BSA'      => 'Bachelor of Science in Accountancy',
        'BSHRM'    => 'Bachelor of Science in Hotel and Restaurant Management',
        'BSTM'     => 'Bachelor of Science in Tourism Management',
        'BSN'      => 'Bachelor of Science in Nursing',
        'BSMT'     => 'Bachelor of Science in Marine Transportation',
        'BSMarE'   => 'Bachelor of Science in Marine Engineering',
        'BSME'     => 'Bachelor of Science in Mechanical Engineering',
        'BSEE'     => 'Bachelor of Science in Electrical Engineering',
        'BSIE'     => 'Bachelor of Science in Industrial Engineering',
        'BSCPE'    => 'Bachelor of Science in Computer Engineering',
        'BSChem'   => 'Bachelor of Science in Chemistry',
        'BSMATH'   => 'Bachelor of Science in Mathematics',
        'BSSTAT'   => 'Bachelor of Science in Statistics',
        'BSARCH'   => 'Bachelor of Science in Architecture',
        'BSF'      => 'Bachelor of Science in Forestry',
        'BSFT'     => 'Bachelor of Science in Food Technology',
        'BSND'     => 'Bachelor of Science in Nutrition and Dietetics',
        'BSPT'     => 'Bachelor of Science in Physical Therapy',
        'BSOT'     => 'Bachelor of Science in Occupational Therapy',
        'BSPH'     => 'Bachelor of Science in Public Health',
        'BSRT'     => 'Bachelor of Science in Radiologic Technology',
        'BSPharm'  => 'Bachelor of Science in Pharmacy',
        'AB'       => 'Bachelor of Arts',
        'ABCOM'    => 'Bachelor of Arts in Communication',
        'BSPsy'    => 'Bachelor of Science in Psychology',
        'BSSW'     => 'Bachelor of Science in Social Work',
        'BSAGRIBUS'=> 'Bachelor of Science in Agribusiness',
        'BSAGRI'   => 'Bachelor of Science in Agriculture',
        'BSAPE'    => 'Bachelor of Science in Applied Physics with Electronics',
        'BSCRIM'   => 'Bachelor of Science in Criminology',
        'BSECE'    => 'Bachelor of Science in Electronics and Communications Engineering',
        'BSHM'     => 'Bachelor of Science in Hospitality Management',
        'BSOA'     => 'Bachelor of Science in Office Administration',
        'BSEM'     => 'Bachelor of Science in Engineering Management',
        'BSENTREP' => 'Bachelor of Science in Entrepreneurship',
    ];
    @endphp
    <div class="bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden shadow-sm">
        <div class="flex items-center justify-between px-5 py-2.5 border-b border-[#E8E0F0]" style="background:#F5F5F5;">
            <div class="flex items-center gap-2.5">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#7a3f91;">
                    <i class="fas fa-table text-white" style="font-size:.65rem;"></i>
                </div>
                <div>
                    <p class="text-sm font-bold leading-tight" style="color:var(--ink);">Course Breakdown</p>
                    <p class="text-[10px]" style="color:var(--muted);">Employment rate per course — click any row to view details</p>
                </div>
            </div>
            <span class="text-[11px] font-semibold px-2 py-1 rounded-lg" style="background:#F0E6F8;color:#7a3f91;">
                {{ count($this->courseAnalytics) }} courses
            </span>
        </div>

        <div class="course-table-scroll">
            <table class="w-full border-collapse" style="min-width:580px;">
                <thead class="sticky top-0 z-10">
                    <tr style="background:#faf7fd;border-bottom:2px solid #E8E0F0;">
                        <th class="pl-4 pr-3 py-2 text-left text-[10px] font-bold uppercase tracking-wider" style="color:var(--muted);">Course</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" style="color:var(--muted);">Total</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" style="color:#059669;">Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" style="color:#2563eb;">Self-Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" style="color:#d97706;">Unemployed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" style="color:#9ca3af;">No Record</th>
                        <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider" style="color:#7a3f91;min-width:160px;">Emp Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->courseAnalytics as $cr)
                    @php $fullCN = $courseFullNames[$cr->course_code ?? ''] ?? null; @endphp
                    <tr class="course-table-row"
                        onclick="empOpenModal('employed_all','{{ $cr->course_code }}',null)"
                        onmouseenter="showEmpCursorTip()"
                        onmouseleave="hideEmpCursorTip()">
                        <td class="pl-4 pr-3 py-2">
                            <div class="flex items-center flex-wrap gap-0">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold" style="background:#F0E6F8;color:#7a3f91;">
                                    <i class="fas fa-graduation-cap text-[9px]"></i>{{ $cr->course_code ?? '—' }}
                                </span>
                            </div>
                            @if($fullCN)
                            <p class="text-[10px] mt-0.5 font-medium" style="color:#333333;">{{ $fullCN }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center"><span class="text-xs font-bold" style="color:var(--ink);">{{ number_format($cr->total) }}</span></td>
                        <td class="px-3 py-2 text-center"><span class="text-xs font-semibold text-emerald-700">{{ number_format($cr->employed) }}</span></td>
                        <td class="px-3 py-2 text-center"><span class="text-xs font-semibold text-blue-700">{{ number_format($cr->self_employed) }}</span></td>
                        <td class="px-3 py-2 text-center"><span class="text-xs font-semibold text-amber-700">{{ number_format($cr->unemployed) }}</span></td>
                        <td class="px-3 py-2 text-center"><span class="text-xs font-semibold text-gray-400">{{ number_format($cr->not_filled) }}</span></td>
                        <td class="px-4 py-2" style="min-width:160px;">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 rate-bar-track"><div class="rate-bar-fill" style="width:{{ $cr->emp_rate }}%;"></div></div>
                                <span class="text-[11px] font-bold w-9 text-right flex-shrink-0" style="color:#7a3f91;">{{ $cr->emp_rate }}%</span>
                            </div>
                            <p class="text-[10px] mt-0.5" style="color:var(--muted);">{{ $cr->response_rate }}% response</p>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-10 text-center"><p class="text-sm font-semibold text-gray-400">No course data available</p></td></tr>
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
    $isLocal    = $this->modalFilter === 'local';
    $isAbroad   = $this->modalFilter === 'abroad';

    $showLocationCol  = !in_array($this->modalFilter, ['unemployed', 'no_record', 'abroad', 'local']);
    $showRelevanceCol = !in_array($this->modalFilter, ['unemployed', 'no_record']);
    $totalCols = 6 + ($showLocationCol ? 1 : 0) + ($showRelevanceCol ? 1 : 0);

    $showContactCell = in_array($this->modalFilter, ['unemployed', 'no_record', '']);
    $jobColHeader = match(true) {
        in_array($this->modalFilter, ['unemployed', 'no_record']) => 'Email / Contact',
        $this->modalFilter === 'self_employed'                    => 'Business Name',
        default                                                   => 'Job / Business',
    };

    $statusBadge = [
        'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]', 'fa-user-tie'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',      'fa-store'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',   'fa-magnifying-glass'],
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $locBadge = [
        'abroad' => ['OFW/Abroad', 'text-amber-700 bg-amber-50 border-amber-200',      'fa-plane-departure'],
        'local'  => ['Local',      'text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-house'],
    ];
    $relevanceBadge = [
        'yes'                => ['Relevant',           'text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check'],
        'relevant'           => ['Relevant',           'text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check'],
        'partially'          => ['Partially Relevant', 'text-amber-700 bg-amber-50 border-amber-200',       'fa-circle-half-stroke'],
        'partially_relevant' => ['Partially Relevant', 'text-amber-700 bg-amber-50 border-amber-200',       'fa-circle-half-stroke'],
        'no'                 => ['Not Relevant',       'text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark'],
        'not_relevant'       => ['Not Relevant',       'text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark'],
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
    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow emp-header-bar">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->modalIcon }} text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">{{ $this->modalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">
                    {{ number_format($records->total()) }} record(s) found
                    @if($modalCourse) · {{ $modalCourse }} @endif
                    @if($modalBatch) · Batch {{ $modalBatch }} @endif
                </p>
            </div>
        </div>
        <button wire:click="closeModal" class="modal-close-btn">
            <span class="modal-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    {{-- TOOLBAR --}}
    <div class="px-6 lg:px-10 pt-2.5 pb-2 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap gap-2 items-center mb-2">
            <span class="filters-label-tag">
                <i class="fas fa-filter text-[10px]"></i>
                Filters
            </span>

            <div class="relative min-w-[200px] max-w-xs flex-shrink-0" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.modalSearch??''; $wire.$watch('modalSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('modalSearch',q)"
                       placeholder="Name, ID, email…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900 focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                       autocomplete="off">
            </div>

            <div class="h-5 w-px bg-gray-200 flex-shrink-0"></div>

            @if($isRelMode && $modalRelevanceLocked)
                @php $lockedVal = $modalRelevanceActive[0] ?? 'relevant'; @endphp
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border filter-chip-active">
                    <i class="fas {{ $relLockedIcons[$lockedVal] ?? 'fa-chart-pie' }} text-[10px]"></i>
                    {{ $relLockedLabels[$lockedVal] ?? 'Relevant' }}
                    <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                </span>
            @elseif($isRelMode && !$modalRelevanceLocked)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-[11px] font-semibold text-gray-400">Relevance:</span>
                    @foreach($relChips as [$relVal, $relLbl, $relIcon, $relColors])
                    @php $isRelActive = in_array($relVal, $modalRelevanceActive ?? []); @endphp
                    <button wire:click="toggleRelevance('{{ $relVal }}')"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition-all active:scale-95
                                   {{ $isRelActive ? 'rel-chip-active' : 'rel-chip-inactive '.$relColors }}">
                        <i class="fas {{ $relIcon }} text-[10px]"></i> {{ $relLbl }}
                        @if($isRelActive)<i class="fas fa-check text-[9px] opacity-80 ml-0.5"></i>@endif
                    </button>
                    @endforeach
                </div>
            @else
                <div class="flex items-center gap-1.5">
                    @foreach($visibleStatusTabs as [$tabVal, $tabLbl, $tabIcon])
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border filter-chip-active">
                        <i class="fas {{ $tabIcon }} text-[10px]"></i>{{ $tabLbl }}
                        <i class="fas fa-lock text-[9px] opacity-60 ml-0.5"></i>
                    </span>
                    @endforeach
                </div>
            @endif

            <div class="flex-1 min-w-0"></div>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            @if(!$modalBatchLocked)
            <div class="emp-dropdown"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('modalBatch',val===''?null:parseInt(val)); this.close(); } }"
                 @click.outside="close()">
                <button type="button" @click="toggle()" :class="{'has-value':$wire.modalBatch!==null,'open':open}" class="emp-dropdown-trigger">
                    <i class="fas fa-calendar-alt" style="font-size:.68rem;opacity:.7;"></i>
                    <span>@if($modalBatch) Batch {{ $modalBatch }} @else All Batches @endif</span>
                    <i class="fas fa-chevron-down emp-chevron"></i>
                </button>
                <div x-show="open" x-transition class="emp-dropdown-menu" style="display:none;">
                    <button type="button" @click="select('')" :class="{'active':$wire.modalBatch===null}" class="emp-dropdown-item">All Batches</button>
                    @foreach($this->availableBatches as $bYear)
                    <button type="button" @click="select('{{ $bYear }}')" :class="{'active':$wire.modalBatch=={{ $bYear }}}" class="emp-dropdown-item">Batch {{ $bYear }}</button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#7A3F91;">
                <i class="fas fa-calendar-check text-[10px]"></i>Batch {{ $modalBatch }}<i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            @if(!$modalCourseLocked)
            <div class="emp-dropdown"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('modalCourse',val); this.close(); } }"
                 @click.outside="close()">
                <button type="button" @click="toggle()" :class="{'has-value':$wire.modalCourse!=='','open':open}" class="emp-dropdown-trigger">
                    <i class="fas fa-book-open" style="font-size:.68rem;opacity:.7;"></i>
                    <span>@if($modalCourse) {{ $modalCourse }} @else All Courses @endif</span>
                    <i class="fas fa-chevron-down emp-chevron"></i>
                </button>
                <div x-show="open" x-transition class="emp-dropdown-menu" style="display:none;">
                    <button type="button" @click="select('')" :class="{'active':$wire.modalCourse===''}" class="emp-dropdown-item">All Courses</button>
                    @foreach($this->availableCourses as $cCode)
                    <button type="button" @click="select('{{ $cCode }}')" :class="{'active':$wire.modalCourse==='{{ $cCode }}'}" class="emp-dropdown-item">{{ $cCode }}</button>
                    @endforeach
                </div>
            </div>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border" style="background:#EFF6FF;color:#2563eb;border-color:#2563eb;">
                <i class="fas fa-book-open text-[10px]"></i>{{ $modalCourse }}<i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
            </span>
            @endif

            @if($hasSubFilters)
            <div class="flex items-center gap-1.5 ml-1">
                @if($hasChipsToShow)
                    <span class="text-xs text-gray-400 font-normal">Filtering:</span>
                    @if($modalBatch !== null && !$modalBatchLocked)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                        <i class="fas fa-calendar text-[10px]"></i> Batch {{ $modalBatch }}
                    </span>
                    @endif
                    @if($modalCourse !== '' && !$modalCourseLocked)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                        <i class="fas fa-book text-[10px]"></i> {{ $modalCourse }}
                    </span>
                    @endif
                    @if($modalSearch !== '')
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                        <i class="fas fa-search text-[10px]"></i> "{{ Str::limit($modalSearch, 20) }}"
                    </span>
                    @endif
                @endif
                <button wire:click="clearModalFilters" wire:loading.attr="disabled" class="text-xs text-red-400 hover:text-red-600 font-semibold transition ml-1">
                    <span wire:loading.remove wire:target="clearModalFilters">Clear all</span>
                    <span wire:loading wire:target="clearModalFilters">Clearing…</span>
                </button>
            </div>
            @endif
        </div>
    </div>

    {{-- Table --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div wire:loading wire:target="modalFilter,modalBatch,modalCourse,modalSearch,modalPage,modalPrev,modalNext,clearModalFilters,toggleRelevance"
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
        <table class="w-full border-collapse" style="min-width:{{ $showLocationCol && $showRelevanceCol ? '900px' : '700px' }};">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-10">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alumni</th>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider" style="min-width:110px;">Student ID / Course</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider" style="min-width:200px;">{{ $jobColHeader }}</th>
                    @if($showLocationCol)
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                    @endif
                    @if($showRelevanceCol)
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Relevance</th>
                    @endif
                    <th class="px-4 pr-6 lg:pr-10 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Batch</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($records as $idx => $row)
                @php
                    $rowNum     = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                    $badge      = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? null);
                    $loc        = ($showLocationCol && !$isNoRecord) ? ($locBadge[$row->work_location ?? ''] ?? null) : null;
                    $empType    = !$isNoRecord ? ($empTypeMap[$row->employment_type ?? ''] ?? null) : null;
                    $relBdg     = ($showRelevanceCol && !$isNoRecord) ? ($relevanceBadge[$row->course_relevance ?? ''] ?? null) : null;
                    $photo      = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName      = $this->formatName($row->first_name??'',$row->middle_initial??'',$row->last_name??'',$row->suffix??'');
                    $rowStatus  = $row->employment_status ?? '';
                    $isRowEmp   = $rowStatus === 'employed';
                    $isRowSelf  = $rowStatus === 'self_employed';
                    $isRowUnemp = $rowStatus === 'unemployed';
                    $isRowNone  = is_null($row->employment_status ?? null);
                    $rowJob     = $row->job_title ?? null;
                    $rowCompany = $row->company_name ?? null;
                    $rowEmail   = $row->email ?? null;
                    $rowContact = $row->contact_number ?? null;
                @endphp
                <tr class="bg-white transition-colors"
                    onmouseenter="this.style.background='#EBEBEB'"
                    onmouseleave="this.style.background=''">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ e($row->first_name ?? '') }}"
                                 class="w-8 h-8 rounded-lg object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold truncate uppercase" style="color:#333333;">{{ $dName }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-xs font-mono font-semibold" style="color:#333333;">{{ $row->student_id ?? '—' }}</p>
                        <span class="inline-flex items-center gap-1 mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold" style="background:#F0E6F8;color:#7a3f91;">
                            {{ $row->course_code ?? '—' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($isNoRecord || $isRowNone)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">
                                <i class="fas fa-circle-minus text-[10px]"></i> No Record
                            </span>
                        @elseif($badge)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                <i class="fas {{ $badge[2] }} text-[10px]"></i> {{ $badge[0] }}
                            </span>
                            @if($empType)<p class="text-[11px] mt-0.5" style="color:#777777;">{{ $empType }}</p>@endif
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="min-width:200px;">
                        @if($isNoRecord || $isRowNone || $isRowUnemp)
                            @if($rowEmail)
                                <div class="flex items-center gap-1.5 mb-1">
                                    <i class="fas fa-envelope text-[10px]" style="color:#7A3F91;flex-shrink:0;"></i>
                                    <span class="text-xs" style="color:#333333;">{{ $rowEmail }}</span>
                                </div>
                            @endif
                            @if($rowContact)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-phone text-[10px]" style="color:#059669;flex-shrink:0;"></i>
                                    <span class="text-xs" style="color:#333333;">{{ $rowContact }}</span>
                                </div>
                            @endif
                            @if(!$rowEmail && !$rowContact)
                                <span class="text-xs" style="color:#CCCCCC;">—</span>
                            @endif
                        @elseif($isRowEmp)
                            @if($rowJob)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-briefcase text-[10px]" style="color:#7A3F91;flex-shrink:0;"></i>
                                    <span class="text-xs font-semibold" style="color:#333333;">{{ $rowJob }}</span>
                                </div>
                            @else
                                <span class="text-xs" style="color:#CCCCCC;">—</span>
                            @endif
                        @elseif($isRowSelf)
                            @if($rowCompany)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-store text-[10px]" style="color:#2563eb;flex-shrink:0;"></i>
                                    <span class="text-xs font-semibold" style="color:#333333;">{{ $rowCompany }}</span>
                                </div>
                                @if($rowJob)
                                    <p class="text-[11px] mt-0.5 ml-4" style="color:#555555;">{{ $rowJob }}</p>
                                @endif
                            @elseif($rowJob)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-store text-[10px]" style="color:#2563eb;flex-shrink:0;"></i>
                                    <span class="text-xs font-medium" style="color:#333333;">{{ $rowJob }}</span>
                                </div>
                            @else
                                <span class="text-xs" style="color:#CCCCCC;">—</span>
                            @endif
                        @else
                            @if($rowJob)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-briefcase text-[10px]" style="color:#7A3F91;flex-shrink:0;"></i>
                                    <span class="text-xs font-medium" style="color:#333333;">{{ $rowJob }}</span>
                                </div>
                            @elseif($rowCompany)
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-building text-[10px]" style="color:#7A3F91;flex-shrink:0;"></i>
                                    <span class="text-xs font-medium" style="color:#333333;">{{ $rowCompany }}</span>
                                </div>
                            @else
                                <span class="text-xs" style="color:#CCCCCC;">—</span>
                            @endif
                        @endif
                    </td>

                    @if($showLocationCol)
                    <td class="px-4 py-3 text-center">
                        @if($loc)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $loc[1] }}">
                                <i class="fas {{ $loc[2] }} text-[10px]"></i> {{ $loc[0] }}
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                    @endif
                    @if($showRelevanceCol)
                    <td class="px-4 py-3 text-center">
                        @if($relBdg)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $relBdg[1] }}">
                                <i class="fas {{ $relBdg[2] }} text-[10px]"></i> {{ $relBdg[0] }}
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                    @endif
                    <td class="px-4 pr-6 lg:pr-10 py-3 text-center">
                        <span class="text-sm font-semibold" style="color:#333333;">{{ $row->batch ?? '—' }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $totalCols }}" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-400">No records found</p>
                            <p class="text-xs text-gray-300">Try adjusting your filters or search terms</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination footer --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 emp-footer-bar">
        <p class="text-white/70 text-sm">
            Showing <strong class="text-white font-semibold">{{ $rFrom }}–{{ $rTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($rTotal) }}</strong> records
        </p>
        @if($rLastPage > 1)
        <div class="flex items-center gap-1 flex-wrap">
            <button @if($rCp <= 1) disabled @endif
                    wire:click="$set('modalPage', {{ max(1, $rCp - 1) }})"
                    class="emp-pg-btn emp-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @if($rPgStart > 1)
                <button wire:click="$set('modalPage', 1)" class="emp-pg-btn emp-pg-nav">1</button>
                @if($rPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $rPgStart; $p <= $rPgEnd; $p++)
                @if($p === $rCp)
                    <span class="emp-pg-btn emp-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('modalPage', {{ $p }})" class="emp-pg-btn emp-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            @if($rPgEnd < $rLastPage)
                @if($rPgEnd < $rLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('modalPage', {{ $rLastPage }})" class="emp-pg-btn emp-pg-nav">{{ $rLastPage }}</button>
            @endif
            <button @if($rCp >= $rLastPage) disabled @endif
                    wire:click="$set('modalPage', {{ min($rLastPage, $rCp + 1) }})"
                    class="emp-pg-btn emp-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $rCp }} / {{ $rLastPage }}</span>
        </div>
        @endif
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
        'employed'      => ['Employed',      'text-emerald-700 bg-emerald-50 border-emerald-200',  'fa-user-tie'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',           'fa-store'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',        'fa-magnifying-glass'],
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
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
    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow emp-header-bar">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-print text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">Print Employment Records</h2>
                <p class="text-white/60 text-xs font-normal">Filter, preview, and print detailed employment records</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            {{-- ═══ UPDATED PRINT BUTTON: direkta mag-load at mag-print ═══ --}}
            <button
                x-data="{ busy: false }"
                @click="busy = true; $wire.loadPrintData()"
                @emp-print-ready.window="busy = false; empPrintReport()"
                :disabled="busy"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-white/15 hover:bg-white/25 border border-white/25 text-white transition active:scale-95 disabled:opacity-60 disabled:cursor-wait">
                <i class="fas" :class="busy ? 'fa-spinner fa-spin' : 'fa-print'"></i>
                <span x-text="busy ? 'Preparing…' : 'Print'"></span>
            </button>
            <button wire:click="closeModal" class="modal-close-btn">
                <span class="modal-close-tip">Close</span>
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>
    </div>

    {{-- Filters bar --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-[#E8E0F0] shrink-0 flex flex-wrap gap-3 items-center">
        <span class="text-xs font-bold uppercase tracking-wider" style="color:#7A3F91;">
            <i class="fas fa-filter mr-1 text-[10px]"></i>Filter Records:
        </span>

        <div class="emp-dropdown"
             x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('reportBatch',val); this.close(); } }"
             @click.outside="close()">
            <button type="button" @click="toggle()" :class="{'has-value':$wire.reportBatch!=='','open':open}" class="emp-dropdown-trigger">
                <i class="fas fa-calendar-alt" style="font-size:.68rem;opacity:.7;"></i>
                <span>@if($reportBatch) Batch {{ $reportBatch }} @else All Batches @endif</span>
                <i class="fas fa-chevron-down emp-chevron"></i>
            </button>
            <div x-show="open" x-transition class="emp-dropdown-menu" style="display:none;">
                <button type="button" @click="select('')" :class="{'active':$wire.reportBatch===''}" class="emp-dropdown-item">All Batches</button>
                @foreach($this->availableBatches as $bYear)
                <button type="button" @click="select('{{ $bYear }}')" :class="{'active':$wire.reportBatch==='{{ $bYear }}'}" class="emp-dropdown-item">Batch {{ $bYear }}</button>
                @endforeach
            </div>
        </div>

        <div class="emp-dropdown"
             x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('reportCourse',val); this.close(); } }"
             @click.outside="close()">
            <button type="button" @click="toggle()" :class="{'has-value':$wire.reportCourse!=='','open':open}" class="emp-dropdown-trigger">
                <i class="fas fa-book-open" style="font-size:.68rem;opacity:.7;"></i>
                <span>@if($reportCourse) {{ $reportCourse }} @else All Courses @endif</span>
                <i class="fas fa-chevron-down emp-chevron"></i>
            </button>
            <div x-show="open" x-transition class="emp-dropdown-menu" style="display:none;">
                <button type="button" @click="select('')" :class="{'active':$wire.reportCourse===''}" class="emp-dropdown-item">All Courses</button>
                @foreach($this->availableCourses as $cCode)
                <button type="button" @click="select('{{ $cCode }}')" :class="{'active':$wire.reportCourse==='{{ $cCode }}'}" class="emp-dropdown-item">{{ $cCode }}</button>
                @endforeach
            </div>
        </div>

        <div class="emp-dropdown"
             x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('reportStatus',val); this.close(); } }"
             @click.outside="close()">
            <button type="button" @click="toggle()" :class="{'has-value':$wire.reportStatus!=='','open':open}" class="emp-dropdown-trigger">
                <i class="fas fa-filter" style="font-size:.68rem;opacity:.7;"></i>
                <span>
                    @if($reportStatus === 'employed') Employed
                    @elseif($reportStatus === 'self_employed') Self-Employed
                    @elseif($reportStatus === 'unemployed') Unemployed
                    @elseif($reportStatus === 'no_record') No Record
                    @else All Status
                    @endif
                </span>
                <i class="fas fa-chevron-down emp-chevron"></i>
            </button>
            <div x-show="open" x-transition class="emp-dropdown-menu" style="display:none;">
                <button type="button" @click="select('')" :class="{'active':$wire.reportStatus===''}" class="emp-dropdown-item">All Status</button>
                <button type="button" @click="select('employed')" :class="{'active':$wire.reportStatus==='employed'}" class="emp-dropdown-item">Employed</button>
                <button type="button" @click="select('self_employed')" :class="{'active':$wire.reportStatus==='self_employed'}" class="emp-dropdown-item">Self-Employed</button>
                <button type="button" @click="select('unemployed')" :class="{'active':$wire.reportStatus==='unemployed'}" class="emp-dropdown-item">Unemployed</button>
                <button type="button" @click="select('no_record')" :class="{'active':$wire.reportStatus==='no_record'}" class="emp-dropdown-item">No Record</button>
            </div>
        </div>

        <span class="ml-auto text-xs font-semibold px-3 py-1.5 rounded-lg" style="background:#F0E6F8;color:#7A3F91;border:1px solid #E8E0F0;">
            {{ number_format($rrTotal) }} records
        </span>
    </div>

    {{-- Summary cards --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-[#E8E0F0] shrink-0">
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
            @foreach([
                [$rTotal,  'Total',        '#F0E6F8','#7A3F91', 'fa-users'],
                [$rEmp,    'Employed',     '#d1fae5','#059669', 'fa-user-tie'],
                [$rSelf,   'Self-Employed','#dbeafe','#2563eb', 'fa-store'],
                [$rUnemp,  'Unemployed',   '#fef3c7','#d97706', 'fa-magnifying-glass'],
                [$rNone,   'No Record',    '#f3f4f6','#9ca3af', 'fa-circle-minus'],
            ] as [$cnt, $lbl, $bg, $color, $ico])
            <div class="rounded-xl p-3 flex items-center gap-2.5 border" style="background:{{ $bg }};border-color:{{ $color }}33;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:{{ $color }}22;">
                    <i class="fas {{ $ico }} text-xs" style="color:{{ $color }};"></i>
                </div>
                <div>
                    <p class="text-base font-black leading-none" style="color:{{ $color }};">{{ number_format($cnt) }}</p>
                    <p class="text-[10px] font-semibold mt-0.5" style="color:{{ $color }};">{{ $lbl }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Preview table — columns: #, Name, Student ID, Course, Batch, Status, Email Address --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 #f9fafb;">
        <div wire:loading wire:target="reportBatch,reportCourse,reportStatus,reportPage,reportPrev,reportNext"
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
        <div wire:loading.remove wire:target="reportBatch,reportCourse,reportStatus,reportPage,reportPrev,reportNext">
            @if($recs->isEmpty())
            <div class="flex flex-col items-center gap-3 py-20">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:#F0E6F8;">
                    <i class="fas fa-file-circle-xmark text-xl" style="color:#c084fc;"></i>
                </div>
                <p class="text-sm font-semibold text-gray-400">No records match your filters</p>
                <p class="text-xs text-gray-300">Try adjusting the batch, course, or status filter above</p>
            </div>
            @else
            <table class="w-full border-collapse" style="min-width:680px;">
                <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                    <tr style="border-bottom:2px solid #E8E0F0;">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">#</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Name</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Student ID</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Course</th>
                        <th class="px-3 py-2.5 text-center text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Batch</th>
                        <th class="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Status</th>
                        <th class="px-3 pr-6 lg:pr-10 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider" style="color:#7A3F91;">Email Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($recs as $i => $r)
                    @php
                        $rowN      = ($recs->currentPage() - 1) * $recs->perPage() + $i + 1;
                        $sLabel    = $empStatusMap[$r->employment_status ?? ''] ?? null;
                        $typeLabel = $empTypeMap[$r->employment_type ?? ''] ?? '';
                        $fullName  = trim(
                            strtoupper($r->last_name ?? '').', '.
                            strtoupper($r->first_name ?? '').
                            (!empty($r->middle_initial) ? ' '.strtoupper(substr($r->middle_initial,0,1)).'.' : '').
                            (!empty($r->suffix) ? ' '.strtoupper($r->suffix) : '')
                        );
                    @endphp
                    <tr class="transition-colors" style="{{ $i % 2 === 0 ? 'background:#fff;' : 'background:#faf7fd;' }}"
                        onmouseenter="this.style.background='#F0E6F8'"
                        onmouseleave="this.style.background='{{ $i % 2 === 0 ? '#fff' : '#faf7fd' }}'">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowN,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-3 py-3"><p class="font-semibold text-xs uppercase whitespace-nowrap" style="color:#333333;">{{ $fullName }}</p></td>
                        <td class="px-3 py-3"><p class="font-mono text-xs" style="color:#333333;">{{ $r->student_id ?? '—' }}</p></td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold" style="background:#F0E6F8;color:#7A3F91;">{{ $r->course_code ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded" style="background:#F0E6F8;color:#7A3F91;">{{ $r->batch ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-3">
                            @if($sLabel)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $sLabel[1] }}">
                                    <i class="fas {{ $sLabel[2] }} text-[9px]"></i> {{ $sLabel[0] }}
                                </span>
                                @if($typeLabel)<p class="text-[10px] mt-0.5" style="color:#777777;">{{ $typeLabel }}</p>@endif
                            @else
                                <span class="text-[10px] italic" style="color:#bbb;">No Record</span>
                            @endif
                        </td>
                        <td class="px-3 pr-6 lg:pr-10 py-3">
                            @if($r->email ?? null)
                                <span class="text-[11px]" style="color:#333333;">{{ $r->email }}</span>
                            @else
                                <span class="text-[10px]" style="color:#ccc;">—</span>
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
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 emp-footer-bar">
        <p class="text-white/70 text-sm">
            Showing <strong class="text-white font-semibold">{{ $rrFrom }}–{{ $rrTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($rrTotal) }}</strong> records
        </p>
        @if($rrLastPage > 1)
        <div class="flex items-center gap-1 flex-wrap">
            <button @if($rrCp <= 1) disabled @endif wire:click="reportPrev" class="emp-pg-btn emp-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @if($rrPgStart > 1)
                <button wire:click="$set('reportPage', 1)" class="emp-pg-btn emp-pg-nav">1</button>
                @if($rrPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $rrPgStart; $p <= $rrPgEnd; $p++)
                @if($p === $rrCp)<span class="emp-pg-btn emp-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('reportPage', {{ $p }})" class="emp-pg-btn emp-pg-nav">{{ $p }}</button>@endif
            @endfor
            @if($rrPgEnd < $rrLastPage)
                @if($rrPgEnd < $rrLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('reportPage', {{ $rrLastPage }})" class="emp-pg-btn emp-pg-nav">{{ $rrLastPage }}</button>
            @endif
            <button @if($rrCp >= $rrLastPage) disabled @endif wire:click="reportNext" class="emp-pg-btn emp-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $rrCp }} / {{ $rrLastPage }}</span>
        </div>
        @endif
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         PRINT AREA
         - 9pt Times New Roman — deretso ang names
         - Walang Contact Number column
         - Malinis na table, walang borders sa rows
    ════════════════════════════════════════════════════════════════ --}}
    <div id="emp-print-area">
        <div style="font-family:'Times New Roman',Times,serif;font-size:9pt;color:#000;padding:14px 20px;background:#fff;">

            {{-- Print header --}}
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

            {{-- Summary line --}}
            <div style="margin-bottom:8px;font-size:8pt;color:#000;font-family:'Times New Roman',Times,serif;border-bottom:1px solid #999;padding-bottom:5px;">
                <strong>Summary:</strong>
                &nbsp; Total: <strong>{{ number_format($rTotal) }}</strong>
                &nbsp;|&nbsp; Employed: <strong>{{ number_format($rEmp) }}</strong>
                &nbsp;|&nbsp; Self-Employed: <strong>{{ number_format($rSelf) }}</strong>
                &nbsp;|&nbsp; Unemployed: <strong>{{ number_format($rUnemp) }}</strong>
                &nbsp;|&nbsp; No Record: <strong>{{ number_format($rNone) }}</strong>
            </div>

            {{-- Print table — 9pt, walang Contact Number, deretso names --}}
            <table style="width:100%;border-collapse:collapse;font-size:9pt;font-family:'Times New Roman',Times,serif;color:#000;">
                <thead>
                    <tr style="border-top:1.5px solid #000;border-bottom:1.5px solid #000;">
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">#</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">Name</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">Student ID</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">Course</th>
                        <th style="padding:3px 5px;text-align:center;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">Batch</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;white-space:nowrap;">Status</th>
                        <th style="padding:3px 5px;text-align:left;font-weight:bold;font-family:'Times New Roman',Times,serif;color:#000;">Email Address</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allPrintRecs as $pi => $pr)
                    @php
                        $pAbsIdx = $pi + 1;
                        $pName   = trim(
                            strtoupper($pr->last_name ?? '') . ', ' .
                            strtoupper($pr->first_name ?? '') .
                            (!empty($pr->middle_initial) ? ' ' . strtoupper(substr($pr->middle_initial, 0, 1)) . '.' : '') .
                            (!empty($pr->suffix) ? ' ' . strtoupper($pr->suffix) : '')
                        );
                    @endphp
                    <tr style="border:none;">
                        <td style="padding:2px 5px;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ $pAbsIdx }}</td>
                        <td style="padding:2px 5px;font-weight:bold;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ $pName }}</td>
                        <td style="padding:2px 5px;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ $pr->student_id ?? '—' }}</td>
                        <td style="padding:2px 5px;font-weight:bold;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ $pr->course_code ?? '—' }}</td>
                        <td style="padding:2px 5px;text-align:center;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ $pr->batch ?? '—' }}</td>
                        <td style="padding:2px 5px;color:#000;font-family:'Times New Roman',Times,serif;border:none;white-space:nowrap;">{{ ucfirst(str_replace('_', ' ', $pr->employment_status ?? 'No Record')) }}</td>
                        <td style="padding:2px 5px;color:#000;font-family:'Times New Roman',Times,serif;border:none;">{{ $pr->email ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Print footer --}}
            <div style="margin-top:8px;border-top:1px solid #000;padding-top:4px;display:flex;justify-content:space-between;">
                <p style="font-size:8pt;color:#000;margin:0;font-family:'Times New Roman',Times,serif;">Employment Tracking System &nbsp;·&nbsp; {{ now()->format('F j, Y') }}</p>
                <p style="font-size:8pt;color:#000;margin:0;font-family:'Times New Roman',Times,serif;">{{ number_format($allPrintRecs->count()) }} total records printed</p>
            </div>

        </div>
    </div>

</div>
@endif


{{-- ══ CHARTS + SCRIPT ══ --}}
<script>
(function () {
    'use strict';

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
        var tip = null;
        function getTip() {
            if (!tip) tip = document.getElementById('emp-cursor-tip');
            return tip;
        }
        document.addEventListener('mousemove', function (e) {
            var t = getTip();
            if (t && t.style.display !== 'none') {
                t.style.left = (e.clientX + 18) + 'px';
                t.style.top  = (e.clientY - 14) + 'px';
            }
        });
        window.showEmpCursorTip = function () {
            var t = getTip();
            if (t) t.style.display = 'block';
        };
        window.hideEmpCursorTip = function () {
            var t = getTip();
            if (t) t.style.display = 'none';
        };
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
                datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 5 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10, weight: '600' }, color: '#333333', padding: 8, usePointStyle: true, pointStyleWidth: 7 },
                        onClick: function (e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart; var index = legendItem.index;
                            chart.getDataVisibility(index) ? chart.hide(index) : chart.show(index);
                            chart.update();
                        },
                    },
                    tooltip: { callbacks: { label: function (ctx) {
                        var total = ctx.dataset.data.reduce(function (a,b) { return a+b; }, 0);
                        var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                        return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                    }}},
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
        var slice = sliceBatch(data, startIdx);
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
                responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { font: { size: 11, weight: '600' }, color: '#333333', padding: 10, usePointStyle: true },
                        onClick: function (e) { if (e && e.native) e.native.stopPropagation(); },
                    },
                    tooltip: { callbacks: {
                        title: function (items) { return 'Batch ' + items[0].label; },
                        footer: function (items) { var t=0; items.forEach(function(i){t+=i.raw;}); return 'Total submitted: '+t; }
                    }},
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11, weight: '600' }, color: '#666666' } },
                    y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var el = elements[0];
                        var batch = slice.labels[el.index];
                        var filter = (datasetFilterMap[el.datasetIndex] !== undefined) ? datasetFilterMap[el.datasetIndex] : '';
                        if (batch === undefined || batch === null) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', { detail: { filter: filter, batch: batch, course: '' } }));
                    }
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
        safeDestroy('chartCourse');
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{ label: 'Working Alumni', data: data.data, backgroundColor: '#7a3f91cc', borderColor: '#7a3f91', borderWidth: 1, borderRadius: 4 }],
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false, onClick: function (e) { if (e && e.native) e.native.stopPropagation(); } },
                    tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.parsed.x + ' alumni'; } } },
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#333333' } },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var course = data.labels[elements[0].index];
                        if (!course) return;
                        window.dispatchEvent(new CustomEvent('open-emp-modal', { detail: { filter: 'employed_all', batch: null, course: course } }));
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
        if (window.Livewire) { hookLivewire(); }
        else { document.addEventListener('livewire:initialized', hookLivewire); }
    });

})();
</script>

</div>