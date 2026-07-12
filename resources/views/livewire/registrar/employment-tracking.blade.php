<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

new class extends Component {

    use WithPagination;

    // ── Filters — PAGE-LEVEL now. filterCourse (Program) + filterBatch
    //    (Batch Year) scope EVERYTHING: stat cards, all charts, the
    //    Program Breakdown table, AND the exported summary report. ───────
    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterLocation  = '';
    public string $filterRelevance = '';
    public string $filterBatch     = '';
    public string $filterCourse    = '';
    public string $filterDept      = '';
    public string $sortBy          = 'a.last_name';
    public string $sortDir         = 'asc';

    // ── Modal (read-only detail view — has its own in-modal SEARCH only;
    //    modalFilter/modalBatch/modalCourse are still set exclusively from
    //    openModal(), there is no way to change the underlying scope from
    //    inside the modal — modalSearch just narrows within it) ──────────
    public bool   $showModal    = false;
    public string $activeModal  = '';
    public string $modalFilter  = '';
    public string $modalCourse  = '';
    public ?int   $modalBatch   = null;
    public string $modalSearch  = '';
    public int    $modalPage    = 1;
    public int    $modalPageSize = 200;

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

    // ── Top Programs ranking (populated only when a single Program is
    //    selected via $filterCourse — used to render the "#6 of 12
    //    programs" rank card instead of the horizontal bar chart) ──────────
    public ?int $courseRank      = null;
    public ?int $courseRankTotal = null;
    public int  $courseRankCount = 0;

    // ── Allowed sort columns ──────────────────────────────────────────────────
    #[Locked]
    protected array $allowedSortColumns = [
        'a.last_name', 'a.first_name', 'a.student_id',
        'a.course_code', 'a.batch', 'et.employment_status',
    ];

    /**
     * Clean URL — same approach as Alumni Records: never sync filters to
     * the query string. Filtering must never change the address bar, and
     * switching Program/Batch must never resurrect a modal from a stale
     * URL/history state.
     */
    protected function queryString(): array { return []; }

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'registrar') {
            $this->redirect(route('login'));
            return;
        }
        $this->refreshDashboard();
    }

    public function refreshData(): void
    {
        $this->refreshDashboard();
    }

    /**
     * Program / Batch Year filter bar handlers — every time either changes,
     * close any open drill-down modal (it was scoped to the previous data
     * and is no longer valid), then recompute stats + rebuild every chart
     * + the Program Breakdown table so the whole dashboard (and whatever
     * gets exported next) stays in sync with the selected scope.
     */
    public function updatedFilterCourse(): void
    {
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    public function updatedFilterBatch(): void
    {
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * In-modal search — narrows the currently drilled-down record set
     * (name / student ID / email / program code) without touching
     * modalFilter/modalBatch/modalCourse. Fires on every debounced keystroke
     * from the search box inside the detail modal.
     */
    public function updatedModalSearch(): void
    {
        $this->modalPage = 1;
        unset($this->modalRecords);
    }

    public function clearFilters(): void
    {
        $this->closeAnyModal();
        $this->filterCourse = '';
        $this->filterBatch  = '';
        $this->refreshDashboard();
    }

    /**
     * Single choke point for "recompute everything + rebuild every chart"
     * — used by mount(), refreshData(), the filter-bar updated hooks,
     * clearFilters(), and closeModal(). Centralizing this here (instead of
     * repeating computeStats()+buildCharts()+unset() at every call site)
     * also lets us reliably tell the front-end *exactly* when fresh chart
     * data is ready via a dispatched browser event, rather than relying on
     * a generic Livewire lifecycle hook that can silently miss updates and
     * leave the charts (donut %, Top Programs, batch/trend bars) showing
     * stale data after a filter change.
     */
    private function refreshDashboard(): void
    {
        $this->computeStats();
        $this->buildCharts();
        unset($this->courseAnalytics);
        $this->dispatch('emp-charts-refresh');
    }

    /**
     * Closes the read-only detail modal, if one happens to be open, without
     * touching the Program/Batch filters themselves. Called whenever those
     * filters change so the user is never left staring at a modal full of
     * records that no longer match the current scope.
     */
    private function closeAnyModal(): void
    {
        if ($this->activeModal !== '') {
            $this->activeModal = '';
            $this->modalFilter = '';
            $this->modalBatch  = null;
            $this->modalCourse = '';
            $this->modalSearch = '';
            $this->modalPage   = 1;
            unset($this->modalRecords);
        }
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni as a')->whereNull('a.deleted_at');
        if ($this->filterCourse !== '') $q->where('a.course_code', $this->filterCourse);
        if ($this->filterBatch  !== '') $q->where('a.batch', $this->filterBatch);
        return $q;
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
        $courseFilter = $this->filterCourse;
        $batchFilter  = $this->filterBatch;

        // Status donut — already scoped since it reads from computeStats()
        $this->chartStatusData = json_encode([
            'labels'  => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'    => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors'  => ['#10b981','#3b82f6','#f59e0b','#d1d5db'],
            'filters' => ['employed', 'self_employed', 'unemployed', 'no_record'],
        ]);

        // Relevance donut — joined to alumni so the Program/Batch filter applies
        $relevanceRows = DB::table('employment_trackings as et')
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id'))
            ->join('alumni as a', 'a.id', '=', 'latest_et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at')
            ->whereNotNull('et.course_relevance')
            ->when($courseFilter !== '', fn($q) => $q->where('a.course_code', $courseFilter))
            ->when($batchFilter  !== '', fn($q) => $q->where('a.batch', $batchFilter))
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

        // Batch stacked bar — scoped by Program filter (Batch filter would
        // just collapse it to a single bar, which is expected/fine)
        $batchRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($courseFilter !== '', fn($q) => $q->where('a.course_code', $courseFilter))
            ->when($batchFilter  !== '', fn($q) => $q->where('a.batch', $batchFilter))
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

        // Top Programs — full ranking (by employed + self-employed count),
        // scoped only by Batch (never by Program — ranking one program
        // against itself is meaningless). Always computed in full so we
        // can tell a selected Program exactly where it stands.
        $courseRowsAll = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($batchFilter !== '', fn($q) => $q->where('a.batch', $batchFilter))
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->whereIn('et.employment_status', ['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->orderByDesc('cnt')->get()
            ->values();

        if ($courseFilter === '') {
            // No Program selected — rank ALL programs by employed/working
            // count (courseRowsAll above already covers every program),
            // but only surface the top 3 here as the horizontal bar.
            $courseRows = $courseRowsAll->take(3);

            $this->chartCourseData = json_encode([
                'labels' => $courseRows->pluck('course_code'),
                'data'   => $courseRows->pluck('cnt'),
            ]);

            $this->courseRank      = null;
            $this->courseRankTotal = null;
            $this->courseRankCount = 0;
        } else {
            // A single Program is selected — the horizontal bar is replaced
            // by a rank card in the Blade view (e.g. "#6 of 12 programs"),
            // so no chart data is needed here.
            $this->chartCourseData = json_encode(['labels' => [], 'data' => []]);

            // Case-insensitive, trimmed match. The Program dropdown's values
            // come from the `courses` table, but `alumni.course_code` is
            // free-entered data — a stray space or different casing (e.g.
            // "Bsit" vs "BSIT") still matches fine in the SQL filter above
            // (MySQL string comparison is case-insensitive by default), but
            // a strict PHP === comparison here would silently fail and make
            // a Program that clearly has data show up as "no data" in the
            // rank card. Normalize both sides before comparing so the two
            // stay in sync.
            $needle    = mb_strtolower(trim($courseFilter));
            $rankIndex = $courseRowsAll->search(fn($r) => mb_strtolower(trim((string) $r->course_code)) === $needle);

            // Ground-truth working count for THIS Program, taken from the
            // already course-scoped computeStats() run above (same filter,
            // same tables) — this can never disagree with the "Working"
            // stat card, unlike re-deriving it from $courseRowsAll where a
            // stray casing/whitespace mismatch in the free-typed
            // `alumni.course_code` column could make the row fail to match
            // and silently show "no data" even though alumni are working.
            $groundTruthWorking = $this->totalEmployed + $this->totalSelf;

            $this->courseRankTotal = $courseRowsAll->count();
            $this->courseRankCount = $groundTruthWorking;

            if ($rankIndex !== false) {
                $this->courseRank = $rankIndex + 1;
            } elseif ($groundTruthWorking > 0) {
                // The stats clearly show working alumni for this Program,
                // but the ranking list (matched by course_code) didn't pick
                // it up — rather than showing a misleading "no data" card,
                // still rank it. Worst case: it slots in last place.
                $this->courseRankTotal = $courseRowsAll->count() + 1;
                $this->courseRank      = $this->courseRankTotal;
            } else {
                $this->courseRank = null;
            }
        }

        // Employment rate trend per batch (line chart) — scoped by Program filter
        $trendRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($courseFilter !== '', fn($q) => $q->where('a.course_code', $courseFilter))
            ->when($batchFilter  !== '', fn($q) => $q->where('a.batch', $batchFilter))
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

    /**
     * Distinct batch years available — populates the Batch Year filter
     * dropdown.
     */
    #[Computed(persist: true)]
    public function batchYears()
    {
        return DB::table('alumni')
            ->whereNull('deleted_at')
            ->whereNotNull('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch');
    }

    #[Computed]
    public function courseAnalytics()
    {
        return DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($this->filterCourse !== '', fn($q) => $q->where('a.course_code', $this->filterCourse))
            ->when($this->filterBatch  !== '', fn($q) => $q->where('a.batch', $this->filterBatch))
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

    /**
     * Records shown inside the detail modal. modalFilter / modalBatch / modalCourse
     * are set ONLY from openModal() — i.e. by clicking a stat card, chart segment,
     * or Program Breakdown row. modalSearch is the ONLY thing the user can change
     * while the modal is open — it narrows within whatever scope was clicked,
     * it never changes modalFilter/modalBatch/modalCourse themselves.
     */
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

            if ($this->modalSearch !== '') {
                $term = '%' . $this->modalSearch . '%';
                $q->where(function ($s) use ($term) {
                    $s->where('a.first_name', 'like', $term)
                      ->orWhere('a.last_name', 'like', $term)
                      ->orWhere('a.middle_initial', 'like', $term)
                      ->orWhere('a.student_id', 'like', $term)
                      ->orWhere('a.email', 'like', $term)
                      ->orWhere('a.course_code', 'like', $term);
                });
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

        if ($this->modalSearch !== '') {
            $term = '%' . $this->modalSearch . '%';
            $q->where(function ($s) use ($term) {
                $s->where('a.first_name', 'like', $term)
                  ->orWhere('a.last_name', 'like', $term)
                  ->orWhere('a.middle_initial', 'like', $term)
                  ->orWhere('a.student_id', 'like', $term)
                  ->orWhere('a.email', 'like', $term)
                  ->orWhere('a.course_code', 'like', $term);
            });
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

    /**
     * Human-readable summary of the CURRENT PAGE-LEVEL filter (Program +
     * Batch Year) — used inside the Generate Reports dropdown message.
     * This is exactly what gets exported now (a scoped summary report),
     * independent of whatever drill-down modal happens to be open.
     */
    #[Computed]
    public function activeReportFilterSummary(): string
    {
        $parts = [];
        if ($this->filterCourse !== '') $parts[] = $this->filterCourse;
        if ($this->filterBatch  !== '') $parts[] = 'Batch ' . $this->filterBatch;

        return count($parts) ? implode(' · ', $parts) : 'All Programs';
    }

    public function openModal(string $filter = '', ?int $batch = null, string $course = ''): void
    {
        $allowedFilters = [
            '', 'employed', 'employed_all', 'self_employed', 'unemployed',
            'no_record', 'abroad', 'local',
            'relevance_yes', 'relevance_partially', 'relevance_no',
        ];
        if (!in_array($filter, $allowedFilters, true)) $filter = '';

        $this->modalFilter = $filter;
        $this->modalBatch  = $batch !== null ? (int)$batch : null;
        $this->modalCourse = strip_tags($course);
        $this->modalSearch = '';
        $this->modalPage   = 1;
        $this->activeModal = 'detail';
        unset($this->modalRecords);
    }

    public function closeModal(): void
    {
        $this->activeModal = '';
        $this->modalFilter = '';
        $this->modalBatch  = null;
        $this->modalCourse = '';
        $this->modalSearch = '';
        $this->modalPage   = 1;
        unset($this->modalRecords);
        $this->refreshDashboard();
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

    /**
     * Wraps every case-insensitive occurrence of $search inside $text with
     * <mark class="ar-hl"> (blue highlight) — same approach used by Alumni
     * Records' search highlight. Used inside the detail modal table when
     * modalSearch is active.
     */
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

<div @open-emp-modal.window="$wire.openModal($event.detail.filter, $event.detail.batch ?? null, $event.detail.course ?? '')" class="emp-dashboard-root">

{{-- ══ CURSOR-FOLLOW TOOLTIP (desktop only — hidden on mobile/touch, see CSS + JS below) ══ --}}
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

    /* Cursor-follow tooltip trigger elements (cursor only, tooltip itself is disabled on mobile below) */
    [data-tip] { cursor: pointer; }

    /* ── Disable ALL tooltips on mobile / touch devices ─────────────────────
       Matches the same breakpoint/approach used in Alumni Records. */
    @media (max-width: 768px), (hover: none) {
        #emp-cursor-tip { display: none !important; }
    }

    /* ── Close button (matches Alumni Records) ──────────────────────────────
       Static, attached tooltip that stays BELOW the X button — no longer
       uses the cursor-follow tooltip system. Hidden on mobile/touch too. */
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
    @media (max-width: 768px), (hover: none) {
        .emp-close-tip { display: none !important; }
    }

    /* ── Fixed page header (mirrors Alumni Records — stays put, only the
       content below it scrolls) ────────────────────────────────────────── */
    .emp-page-header-wrap {
        padding: 0.75rem 0.75rem 0.5rem;
    }
    @media (min-width: 640px) {
        .emp-page-header-wrap { padding: 1rem 1.5rem 0.5rem; }
    }

    /* ── Header row: title left, Generate Reports button pinned top-right ── */
    .emp-header-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    /* ── Program / Batch Year filter bar ─────────────────────────────────── */
    .emp-filter-select {
        appearance: none;
        -webkit-appearance: none;
        background: transparent;
        border: none;
        outline: none;
        cursor: pointer;
        padding-right: 4px;
    }
    .emp-filter-pill {
        transition: border-color .15s ease;
    }
    .emp-filter-pill:hover { border-color: #c4b5fd !important; }

    /* ── Filter bar: single row that WRAPS on narrow screens instead of
       horizontally scrolling. IMPORTANT: this bar must never set
       overflow-x/overflow-y — any overflow value here clips the absolutely
       positioned .ar-dropdown-menu popups (Batch Year / Program Code),
       which is what was making the dropdowns silently fail to show up.
       Mirrors the Alumni Records filter bar, which has no overflow on its
       wrapper for the same reason. This wrapper is also given an explicit
       stacking context (position + z-index) so its dropdown popups always
       paint ABOVE the dashboard content below (stat cards / charts), and
       so a click landing on a dropdown item can never be mistaken for a
       click on whatever card happens to sit underneath it. ──────────────── */
    .emp-filter-bar {
        transition: opacity .2s ease;
        position: relative;
        z-index: 50;
    }

    .ar-filter-label { pointer-events: none; }
    .ar-dropdown { position: relative; }
    .ar-dropdown-menu {
        position: absolute; top: calc(100% + 4px); left: 0;
        min-width: 100%; max-height: 220px; overflow-y: auto;
        background: #fff; border: 1.5px solid #E8E0F0;
        border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
        z-index: 500; padding: 4px;
        scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
    }
    .ar-dropdown-menu::-webkit-scrollbar { width: 5px; }
    .ar-dropdown-menu::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }
    .ar-dropdown-item {
        display: block; width: 100%; padding: 7px 10px; border-radius: 7px;
        font-size: .8rem; font-weight: 600; text-align: left; color: #333;
        transition: background .1s; cursor: pointer; white-space: nowrap;
        border: none; background: transparent;
    }
    .ar-dropdown-item:hover { background: #F5F0FA; color: #7A3F91; }
    .ar-dropdown-item.active { background: #F0E6F8; color: #7A3F91; }
    .ar-dropdown-trigger {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 11px; border: 1.5px solid #E8E0F0; border-radius: 8px;
        font-size: .8rem; font-weight: 600; background: #fff; color: #333;
        cursor: pointer; transition: border-color .15s, background .15s, color .15s;
        white-space: nowrap; user-select: none;
    }
    .ar-dropdown-trigger:hover { border-color: #c49ed8; }
    .ar-dropdown-trigger.has-value { border-color: #7A3F91; background: #F9F7FC; color: #7A3F91; }
    .ar-dropdown-trigger .ar-chevron { transition: transform .18s; font-size: .65rem; opacity: .6; }
    .ar-dropdown-trigger.open .ar-chevron { transform: rotate(180deg); }
    .emp-filter-progress-track {
        height: 2px; width: 100%; overflow: hidden;
        background: transparent; position: relative; margin-top: 4px;
    }
    .emp-filter-progress-bar {
        position: absolute; top: 0; left: 0; height: 100%; width: 40%;
        border-radius: 99px; background: linear-gradient(135deg,#7A3F91,#9b59b6);
        animation: empFilterProgress 1s ease-in-out infinite;
    }
    @keyframes empFilterProgress { 0%{left:-40%} 100%{left:100%} }

    /* ── Modal search — dedicated focus styling. Doesn't rely on Tailwind's
       arbitrary ring/border utilities (focus:ring-[#7A3F91]/10 etc.) since
       those can silently fail to generate and fall back to the browser's
       native default outline (which renders black in some browsers) —
       this guarantees the purple focus ring every time. ── */
    .emp-modal-search-input { outline: none; }
    .emp-modal-search-input:focus,
    .emp-modal-search-input:focus-visible {
        outline: none !important;
        border-color: #7A3F91 !important;
        box-shadow: 0 0 0 3px rgba(122,63,145,.14) !important;
    }

    /* ── Search highlight — matches Alumni Records' blue highlight ── */
    mark.ar-hl {
        background: #BFDBFE;
        color: inherit;
        border-radius: 2px;
        padding: 0 1px;
        font-weight: 700;
    }

    /* ── Force every table/row/cell in this page to use the design's gray
       border color instead of ever falling back to the browser/Tailwind
       default (which resolves to black — Tailwind's preflight reset sets
       default border-color to `currentColor`, so any element that ends up
       with a non-zero border-width but no explicit color class renders a
       black line). This was showing up as black lines around each row in
       the detail modal's results table specifically while typing in the
       search box (wire:loading/morph re-render re-adds row separators
       without always carrying the intended color utility with them).
       Scoped to `.emp-dashboard-root` only so it can't leak outside this
       component. `!important` because Tailwind's own border utilities are
       also loaded with `!important`-strength specificity via arbitrary
       values in places, and we need this to always win. ── */
    .emp-dashboard-root table,
    .emp-dashboard-root thead,
    .emp-dashboard-root tbody,
    .emp-dashboard-root tr,
    .emp-dashboard-root th,
    .emp-dashboard-root td {
        border-color: #E8E0F0 !important;
    }
    .emp-dashboard-root .modal-table-wrap tbody tr {
        border-bottom: 1px solid #F3F4F6 !important;
    }

    /* ── Generate Reports button (copied styling from Alumni Records) ──── */
    .ar-report-btn {
        position: relative;
        display: flex; align-items: center; justify-content: center;
        width: 40px; height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg,#475569,#64748b);
        border: 1.5px solid transparent;
        color: #fff;
        cursor: pointer;
        transition: all .15s;
        font-size: 15px;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(71,85,105,.35);
    }
    .ar-report-btn:hover,
    .ar-report-btn-active { background: linear-gradient(135deg,#334155,#475569); box-shadow: 0 3px 10px rgba(71,85,105,.5); }
    .ar-report-btn:disabled { opacity: .7; cursor: wait; }
    .ar-report-tip {
        position: absolute;
        top: calc(100% + 8px); right: 0;
        background: #1a1a1a; color: #fff;
        font-size: 10px; font-weight: 600; letter-spacing: .05em;
        padding: 5px 11px; border-radius: 7px; white-space: nowrap;
        pointer-events: none; opacity: 0; transition: opacity .15s ease;
        z-index: 9999; box-shadow: 0 4px 14px rgba(0,0,0,.30);
    }
    .ar-report-tip::before {
        content: '';
        position: absolute; bottom: 100%; right: 12px;
        border: 5px solid transparent; border-bottom-color: #1a1a1a;
    }
    .ar-report-btn:hover .ar-report-tip { opacity: 1; }
    @media (max-width: 768px), (hover: none) {
        .ar-report-tip { display: none !important; }
    }
    .ar-report-menu {
        position: absolute; top: calc(100% + 8px); right: 0;
        width: min(260px, calc(100vw - 24px));
        background: #fff;
        border: 1.5px solid #E8E0F0; border-radius: 12px;
        box-shadow: 0 10px 30px rgba(122,63,145,.18);
        z-index: 500; padding: 6px;
    }
    .ar-report-menu-message {
        padding: 10px 10px 11px;
        margin-bottom: 4px;
        border-bottom: 1px solid #F0ECF5;
    }
    .ar-report-menu-message .lbl {
        font-size: .6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .07em; color: #7A3F91; display: block; margin-bottom: 3px;
    }
    .ar-report-menu-message .txt {
        font-size: .75rem; font-weight: 600; color: #111111; line-height: 1.35;
    }
    .ar-report-menu-message .cnt {
        font-size: .68rem; font-weight: 600; color: #333333; margin-top: 3px; display: block;
    }
    .ar-report-menu-item {
        display: flex; align-items: center; gap: 9px; width: 100%;
        padding: 9px 10px; border-radius: 8px;
        margin-bottom: 4px;
        font-size: .82rem; font-weight: 600; color: #333333;
        border: 1.5px solid transparent; cursor: pointer; text-align: left;
        transition: background .12s, border-color .12s, opacity .12s;
    }
    .ar-report-menu-item:last-child { margin-bottom: 0; }
    .ar-report-menu-item .ar-item-icon {
        width: 22px; height: 22px; border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; font-size: 11px;
    }
    .ar-report-menu-item .ar-item-label { flex: 1; }
    .ar-report-menu-item.item-pdf   { background: #FEF2F2; border-color: #FEE2E2; }
    .ar-report-menu-item.item-pdf:hover   { background: #FEE2E2; border-color: #FECACA; }
    .ar-report-menu-item.item-pdf .ar-item-icon { background: #DC2626; color: #fff; }
    .ar-report-menu-item.item-excel { background: #ECFDF5; border-color: #D1FAE5; }
    .ar-report-menu-item.item-excel:hover { background: #D1FAE5; border-color: #A7F3D0; }
    .ar-report-menu-item.item-excel .ar-item-icon { background: #059669; color: #fff; }
    .ar-report-menu-item.item-print { background: #F5F5F5; border-color: #EDEDED; }
    .ar-report-menu-item.item-print:hover { background: #ECECEC; border-color: #E0E0E0; }
    .ar-report-menu-item.item-print .ar-item-icon { background: #555555; color: #fff; }
    .ar-report-menu-item:disabled { opacity: .55; cursor: wait; }

    @media (max-width: 640px) {
        .stat-cards-grid { display:grid!important; grid-template-columns:1fr 1fr!important; gap:8px!important; }
        .stat-cards-grid > div { flex:none!important; min-width:0!important; }
        .stat-cards-grid .text-2xl { font-size:1.25rem!important; }
        .charts-row-1 { display:flex!important; flex-direction:column!important; height:auto!important; }
        .charts-row-1 > div { height:260px!important; }
        .chart-batch-wrap { height:240px!important; }
        .chart-trend-wrap { height:240px!important; }
        .emp-page-header h1 { font-size:1.5rem!important; }
        .course-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .modal-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .modal-footer-inner { flex-direction:column; align-items:flex-start; gap:8px; }
    }
    @media (max-width:400px) {
        .emp-pg-btn { min-width:26px; height:26px; padding:0 6px; font-size:.68rem; }
    }

    /* ── "More content below" glow indicator ─────────────────────────────── */
    .emp-scroll-indicator {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border: none;
        background: transparent;
        cursor: pointer;
    }
    .emp-scroll-indicator-glow {
        position: absolute;
        inset: -6px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(122,63,145,.55) 0%, rgba(122,63,145,0) 70%);
        animation: empIndicatorGlow 1.8s ease-in-out infinite;
        pointer-events: none;
    }
    .emp-scroll-indicator-btn {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg,#7A3F91,#9b59b6);
        color: #fff;
        box-shadow: 0 2px 10px rgba(122,63,145,.45), 0 0 0 3px rgba(255,255,255,.9);
        animation: empIndicatorBounce 1.8s ease-in-out infinite;
        font-size: .8rem;
    }
    @keyframes empIndicatorGlow {
        0%, 100% { opacity:.45; transform:scale(.9); }
        50%      { opacity:1;   transform:scale(1.15); }
    }
    @keyframes empIndicatorBounce {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-4px); }
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
     MAIN PAGE — height capped like Alumni Records (up to the
     logout footer only). Header stays fixed in place; only the
     content below it scrolls.
═══════════════════════════════════════════════════════════ --}}
<div class="bg-gray-100 flex flex-col relative" style="height:calc(100vh - 180px);max-height:calc(100vh - 180px);overflow:hidden;"
     x-data="{ showMoreIndicator:true }">

    {{-- PAGE HEADER — fixed, does not move/scroll away --}}
    <div class="emp-page-header-wrap shrink-0">
        <div class="emp-header-row">
            <div class="flex items-center gap-3 emp-page-header">
                <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-chart-column text-white text-base"></i>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-2xl font-semibold text-[#333333] leading-tight">Employment Tracking</h1>
                    <p class="text-[#333333] text-xs sm:text-sm font-normal mt-0.5">System-wide alumni employment analytics &amp; records</p>
                </div>
            </div>

            {{-- ══ GENERATE REPORTS BUTTON ══ --}}
            <div class="relative shrink-0" wire:ignore
                 x-data
                 x-init="window.__empEnsureReportStore && window.__empEnsureReportStore()"
                 @click.outside="$store.empReport.open=false" wire:key="emp-report-dropdown">
                <button type="button" @click.stop="$store.empReport.toggle()" class="ar-report-btn"
                        :class="{ 'ar-report-btn-active': $store.empReport.open }">
                    <i class="fas fa-chart-column"></i>
                    <span class="ar-report-tip">Generate Reports</span>
                </button>

                <div x-show="$store.empReport.open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-report-menu" style="display:none;">

                    <div class="ar-report-menu-message">
                        <span class="lbl"><i class="fas fa-circle-info mr-1"></i>Report will include</span>
                        <span class="txt">{{ $this->activeReportFilterSummary }}</span>
                        <span class="cnt">{{ number_format($totalAlumni) }} alumni in this scope</span>
                    </div>

                    <button type="button" @click="$store.empReport.doExport('pdf', $wire)" class="ar-report-menu-item item-pdf">
                        <span class="ar-item-icon"><i class="fas fa-file-pdf"></i></span>
                        <span class="ar-item-label">Export as PDF</span>
                    </button>

                    <button type="button" @click="$store.empReport.doExport('excel', $wire)" class="ar-report-menu-item item-excel">
                        <span class="ar-item-icon"><i class="fas fa-file-excel"></i></span>
                        <span class="ar-item-label">Export as Excel</span>
                    </button>

                    <button type="button" @click="$store.empReport.doExport('print', $wire)"
                            :disabled="$store.empReport.printLock" class="ar-report-menu-item item-print">
                        <span class="ar-item-icon"><i class="fas fa-print"></i></span>
                        <span class="ar-item-label">Print Current View</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ══ PROGRAM / BATCH YEAR FILTER BAR — scopes cards, charts,
             Program Breakdown table, AND whatever gets exported. Same
             compact, icon-dropdown treatment as Alumni Records (FILTERS
             label + custom Alpine dropdowns — no native <select>). Wraps
             instead of scrolling so the dropdown popups are never clipped
             (see .emp-filter-bar comment above). Wire:loading dim +
             progress-bar match Alumni Records too. Every click inside this
             bar is stopped from bubbling (@click.stop), so picking a
             filter value can NEVER be mistaken for a click on the
             dashboard cards/charts underneath. ══ --}}
        <div class="emp-filter-bar flex items-center gap-2 mt-3 flex-wrap"
             wire:loading.class="opacity-60" wire:target="filterCourse,filterBatch"
             @click.stop>

            <span class="ar-filter-label text-xs font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">FILTERS</span>

            <div class="h-5 w-px bg-[#E8E0F0] shrink-0"></div>

            {{-- Batch Year --}}
            <div class="ar-dropdown shrink-0"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('filterBatch',val); this.close(); } }"
                 @click.outside="close()" wire:key="emp-batch-dropdown">
                <button type="button" @click.stop="toggle()" :class="{ 'has-value':$wire.filterBatch!=='','open':open }" class="ar-dropdown-trigger">
                    <i class="fas fa-calendar-days" style="font-size:11px;opacity:.7;"></i>
                    <span>@if($filterBatch){{ $filterBatch }}@else All Batch Years @endif</span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;" @click.stop>
                    <button type="button" @click.stop="select('')" :class="{'active':$wire.filterBatch===''}" class="ar-dropdown-item">All Batch Years</button>
                    @foreach($this->batchYears as $year)
                    <button type="button" @click.stop="select('{{ $year }}')" :class="{'active':$wire.filterBatch==='{{ $year }}'}" class="ar-dropdown-item">{{ $year }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Program Code --}}
            <div class="ar-dropdown shrink-0"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('filterCourse',val); this.close(); } }"
                 @click.outside="close()" wire:key="emp-course-dropdown">
                <button type="button" @click.stop="toggle()" :class="{ 'has-value':$wire.filterCourse!=='','open':open }" class="ar-dropdown-trigger">
                    <i class="fas fa-graduation-cap" style="font-size:11px;opacity:.7;"></i>
                    <span>@if($filterCourse){{ $filterCourse }}@else All Program Codes @endif</span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;" @click.stop>
                    <button type="button" @click.stop="select('')" :class="{'active':$wire.filterCourse===''}" class="ar-dropdown-item">All Program Codes</button>
                    @foreach($this->courseMap as $code => $name)
                    <button type="button" @click.stop="select('{{ $code }}')" :class="{'active':$wire.filterCourse==='{{ $code }}'}" class="ar-dropdown-item">{{ $code }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Reset --}}
            @if($filterCourse !== '' || $filterBatch !== '')
                <button wire:click="clearFilters" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="clearFilters"
                        type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-95 shrink-0 disabled:pointer-events-none">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            @endif
        </div>

        {{-- Filtering progress bar --}}
        <div class="emp-filter-progress-track" wire:loading wire:target="filterCourse,filterBatch">
            <div class="emp-filter-progress-bar"></div>
        </div>
    </div>

<div class="flex-1 overflow-y-auto"
     style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;"
     @scroll.passive="showMoreIndicator = ($event.target.scrollHeight - $event.target.scrollTop - $event.target.clientHeight) > 80">
<div class="flex flex-col px-3 sm:px-6 py-3 sm:py-4 gap-3 sm:gap-4 max-w-[1920px] mx-auto w-full box-border"
     wire:loading.class="opacity-60" wire:target="filterCourse,filterBatch" style="transition:opacity .2s ease;">

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
            ['no_record'   , $totalNotFilled, 'fa-circle-question'  , 'bg-gray-100'   , 'text-[#333333]'  , 'stat-card-nofill'   , 'No Record'  , $nfRate.'% of total alumni'],
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
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5">Overall breakdown of alumni job status</span>
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
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5">Where working alumni are based</span>
                </div>
                <span class="text-[.65rem] font-bold text-[#7a3f91] bg-[#f0e6f8] px-2 py-0.5 rounded-full shrink-0">{{ number_format($locTotal) }} working</span>
            </div>
            <div class="flex-1 flex flex-col justify-center px-4 sm:px-5 py-3 gap-3">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#7a3f91;">{{ number_format($totalLocal) }}</p>
                        <p class="text-[11px] font-bold text-[#111111] mt-1">Local / PH</p>
                        <p class="text-[11px] font-black mt-0.5" style="color:#7a3f91;">{{ $localPct }}%</p>
                    </div>
                    <div class="text-[10px] font-semibold text-[#333333] self-center pb-4">-</div>
                    <div class="text-right">
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#c084fc;">{{ number_format($totalAbroad) }}</p>
                        <p class="text-[11px] font-bold text-[#111111] mt-1">Abroad / OFW</p>
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
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5">Alumni whose jobs match their program</span>
                </div>
            </div>
            <div class="flex-1 min-h-0 flex items-center justify-center p-2" wire:ignore>
                <canvas id="chartRelevance" style="max-height:100%;max-width:100%;"></canvas>
            </div>
        </div>

        {{-- Top Programs — ranking board (top 3), or rank card when scoped to one Program.
             IMPORTANT: each branch below carries its own wire:key. Without
             distinct keys, Livewire's DOM diffing (morphdom) can mistake the
             <canvas> block for the rank-card block (or vice versa) when the
             Program filter toggles between "" and a real value, since both
             occupy the same position in the DOM tree. That confusion is what
             was leaving stale click handlers / stale content behind and
             making this card intermittently show wrong data right after a
             filter change. Keying them makes Livewire treat them as two
             completely distinct elements and always swap them cleanly. ── --}}
        <div class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0"></span>
                <div class="flex flex-col min-w-0">
                    <span class="text-[.72rem] font-bold text-[#111111] uppercase tracking-widest leading-tight">Top Programs</span>
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5">
                        {{ $filterCourse === '' ? 'Top 3 programs by employed alumni' : $filterCourse.' — ranking among all programs' }}
                    </span>
                </div>
            </div>

            @if($filterCourse === '')
                <div class="flex-1 min-h-0 p-2" wire:ignore wire:key="emp-top-programs-chart">
                    <canvas id="chartCourse" style="max-height:100%;width:100%;"></canvas>
                </div>
            @else
                @php
                    // ── Smart rank badge — color-tiers the rank number so a
                    //    Program's standing reads at a glance: green for the
                    //    top third of programs, amber for the middle third,
                    //    red for the bottom third. e.g. #1 of 12 -> green,
                    //    #9 of 12 -> red. ──────────────────────────────────
                    $rankTier = null;
                    if ($courseRank && $courseRankTotal) {
                        $tierSize = max(1, (int) ceil($courseRankTotal / 3));
                        if ($courseRank <= $tierSize) {
                            $rankTier = ['bg' => '#ECFDF5', 'text' => '#059669', 'ring' => '#6ee7b7', 'label' => 'Top Performer'];
                        } elseif ($courseRank <= $tierSize * 2) {
                            $rankTier = ['bg' => '#FFFBEB', 'text' => '#D97706', 'ring' => '#fcd34d', 'label' => 'Mid Performer'];
                        } else {
                            $rankTier = ['bg' => '#FEF2F2', 'text' => '#DC2626', 'ring' => '#fca5a5', 'label' => 'Needs Attention'];
                        }
                    }
                @endphp
                <div wire:click="openModal('employed_all','{{ $filterCourse }}',null)"
                     wire:key="emp-top-programs-rank"
                     data-tip="View {{ $filterCourse }} Working Alumni"
                     class="flex-1 min-h-0 flex flex-col items-center justify-center gap-1.5 p-3 cursor-pointer">
                    @if($courseRank && $rankTier)
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center font-black text-xl leading-none"
                             style="background:{{ $rankTier['bg'] }}; color:{{ $rankTier['text'] }}; border:2px solid {{ $rankTier['ring'] }};">
                            #{{ $courseRank }}
                        </div>
                        <p class="text-[11px] font-bold text-[#111111] mt-1">out of {{ $courseRankTotal }} program(s)</p>
                        <span class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
                              style="background:{{ $rankTier['bg'] }}; color:{{ $rankTier['text'] }};">
                            {{ $rankTier['label'] }}
                        </span>
                        <p class="text-[11px] font-semibold mt-0.5" style="color:#7a3f91;">{{ number_format($courseRankCount) }} working alumni</p>
                    @else
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-chart-simple" style="color:#c89de0;font-size:.9rem;"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] text-center mt-1">No working alumni yet for {{ $filterCourse }} in this scope.</p>
                    @endif
                </div>
            @endif
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
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5 hidden sm:block">Number of employed, self-employed &amp; unemployed per batch</span>
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
                    <span class="text-[.62rem] text-[#333333] font-medium leading-tight mt-0.5 hidden sm:block">% of alumni (employed + self-employed) out of total per batch</span>
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

    {{-- ── PROGRAM BREAKDOWN TABLE ── --}}
    <div class="bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden shadow-sm">
        <div class="flex items-center justify-between px-3 sm:px-5 py-2.5 border-b border-[#E8E0F0] bg-[#F9F7FC]">
            <div class="flex items-center gap-2.5">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#7a3f91;">
                    <i class="fas fa-table text-white" style="font-size:.65rem;"></i>
                </div>
                <div>
                    <p class="text-xs sm:text-sm font-bold leading-tight text-[#111111]">Program Breakdown</p>
                    <p class="text-[10px] text-[#333333] hidden sm:block">Employment rate per program — click any row to view records</p>
                </div>
            </div>
            <span class="text-[11px] font-semibold px-2 py-1 rounded-lg bg-[#F9F7FC] text-[#7a3f91] border border-[#E8E0F0] shrink-0">
                {{ count($this->courseAnalytics) }} programs
            </span>
        </div>
        <div class="course-table-wrap max-h-[450px] overflow-y-auto overflow-x-auto" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
            <table class="w-full border-collapse" style="min-width:580px;">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-[#f5f0fa] border-b-2 border-[#E8E0F0]">
                        <th class="pl-3 sm:pl-4 pr-3 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-[#111111]">Program</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-[#111111]">Total</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-emerald-700">Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-blue-700">Self-Employed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-amber-700">Unemployed</th>
                        <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-[#333333]">No Record</th>
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
                            <span class="text-xs font-semibold text-[#333333]">{{ number_format($cr->not_filled) }}</span>
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
                            <p class="text-[10px] mt-0.5 text-[#333333]">{{ $cr->response_rate }}% response</p>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center">
                            <p class="text-sm font-semibold text-[#333333]">No program data available</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

    {{-- ── "More content below" glow indicator ── --}}
    <button type="button"
            x-show="showMoreIndicator"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="$el.closest('.bg-gray-100.flex.flex-col.relative').querySelector('.overflow-y-auto').scrollBy({top:280,behavior:'smooth'})"
            class="emp-scroll-indicator absolute left-1/2 -translate-x-1/2 z-30"
            style="bottom:10px;display:none;">
        <span class="emp-scroll-indicator-glow"></span>
        <span class="emp-scroll-indicator-btn">
            <i class="fas fa-chevron-up"></i>
        </span>
    </button>

</div>


{{-- ═══════════════════════════════════════════════════════════
     DETAIL FULL-SCREEN MODAL — read-only drill-down for the
     underlying scope (modalFilter/modalBatch/modalCourse — those
     never change from inside the modal, only from the segment you
     clicked). The one thing you CAN change in here is the search
     box below the header, which narrows within that scope so long
     lists are actually searchable. Changing the Program/Batch
     filter bar while this is open automatically closes it (see
     updatedFilterCourse / updatedFilterBatch in the PHP class).
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

        <button wire:click="closeModal" class="emp-close-btn shrink-0 ml-3">
            <span class="emp-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    {{-- ── In-modal search — narrows the current record set (name /
         student ID / email / program code) without touching the
         underlying modalFilter/modalBatch/modalCourse scope. Matches
         wire:loading dim + progress-bar treatment used elsewhere, and
         highlights matches in blue (mark.ar-hl) like Alumni Records. ── --}}
    <div class="px-4 sm:px-6 lg:px-10 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center gap-2 shrink-0"
         wire:loading.class="opacity-60" wire:target="modalSearch">
        <span class="text-xs font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">
            <i class="fas fa-magnifying-glass sm:mr-1"></i><span class="hidden sm:inline">SEARCH</span>
        </span>
        <div class="h-5 w-px bg-[#E8E0F0] shrink-0 hidden sm:block"></div>
        <div class="relative flex-1 max-w-xs" wire:ignore
             x-data="{ q:'', init(){ this.q=$wire.modalSearch??''; $wire.$watch('modalSearch',v=>{ if(v!==this.q)this.q=v; }); } }">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
            <input type="text" x-model="q" @input.debounce.300ms="$wire.set('modalSearch',q)"
                   placeholder="Search name, student ID, email…"
                   class="emp-modal-search-input w-full pl-8 pr-8 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                          placeholder-[#999999] transition font-normal"
                   autocomplete="off" spellcheck="false">
            <button type="button" x-show="q" @click="q=''; $wire.set('modalSearch','')"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[#999999] hover:text-[#7A3F91] transition"
                    style="display:none;">
                <i class="fas fa-xmark text-xs"></i>
            </button>
        </div>
        <span class="text-[11px] font-semibold text-[#7A3F91] shrink-0 whitespace-nowrap">
            {{ number_format($records->total()) }} result(s)
        </span>
    </div>
    <div class="emp-filter-progress-track" wire:loading wire:target="modalSearch">
        <div class="emp-filter-progress-bar"></div>
    </div>

    {{-- ── Table ── --}}
    <div class="modal-table-wrap flex-1 overflow-y-auto overflow-x-auto min-h-0"
         wire:loading.class="opacity-50" wire:target="modalSearch" style="transition:opacity .2s ease;">
        <table class="w-full border-collapse" style="min-width:700px;">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-4 sm:pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Name</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Student ID</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Program</th>
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
                    $badge  = $isNoRecord ? null : ($statusBadge[$row->employment_status ?? ''] ?? null);
                    $relBadge = isset($row->course_relevance) ? ($relevanceBadge[$row->course_relevance] ?? null) : null;
                    $photo  = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName  = $this->formatName($row->first_name??'',$row->middle_initial??'',$row->last_name??'',$row->suffix??'');
                @endphp
                <tr class="bg-white hover:bg-[#F5F0FA] transition-colors duration-100">
                    <td class="pl-4 sm:pl-6 lg:pl-10 pr-3 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ e($row->first_name ?? '') }}"
                                 class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <p class="text-sm font-semibold truncate uppercase text-[#111111]">{!! $this->highlight($dName, $modalSearch) !!}</p>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-sm font-mono font-semibold text-[#111111]">{!! $this->highlight($row->student_id ?? '—', $modalSearch) !!}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-[#F9F7FC] text-[#7a3f91]">
                            {!! $this->highlight($row->course_code ?? '—', $modalSearch) !!}
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
                            <span class="text-xs text-[#333333]">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($row->email ?? null)
                            <span class="text-sm text-[#333333] truncate block max-w-[200px]">{!! $this->highlight(strtolower($row->email), $modalSearch) !!}</span>
                        @else
                            <span class="text-xs text-[#333333]">—</span>
                        @endif
                    </td>
                    <td class="px-4 pr-6 lg:pr-10 py-3">
                        @if($row->contact_number ?? null)
                            <span class="text-sm text-[#333333]">{{ $row->contact_number }}</span>
                        @else
                            <span class="text-xs text-[#333333]">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No records found</p>
                            <p class="text-xs text-[#333333]">
                                @if($modalSearch !== '')
                                    No results match "{{ $modalSearch }}" in this scope.
                                @else
                                    No employment data available for this segment
                                @endif
                            </p>
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


{{-- ══ CHARTS + CURSOR TOOLTIP + REPORT STORE SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    // ── Generate Reports Alpine store ───────────────────────────────────────
    function registerEmpReportStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('empReport')) return;

        window.Alpine.store('empReport', {
            open: false,
            _lastToggle: 0,
            printLock: false,
            _activePrintCleanup: null,

            toggle() {
                const now = Date.now();
                if (now - this._lastToggle < 150) return;
                this._lastToggle = now;
                this.open = !this.open;
            },

            async readErrorMessage(res, fallback) {
                try {
                    const data = await res.clone().json();
                    if (data && data.message) return data.message;
                } catch (e) { /* not JSON */ }
                return fallback;
            },

            /**
             * The exported report is ALWAYS the current page-level scope —
             * whatever Program + Batch Year filter is selected at the top
             * of the dashboard (wire.filterCourse / wire.filterBatch).
             * This is independent from the drill-down detail modal.
             */
            async doExport(type, wire) {
                this.open = false;

                // ── Print re-entry guard ──────────────────────────────────
                // "Print Current View" must only ever open the OS print
                // dialog because the button itself was clicked — never on
                // its own afterward. If a print flow is already running
                // (double-click, or a previous flow's cleanup hasn't fired
                // yet), ignore this call instead of stacking a second
                // frame/print() call — that stacking is what was causing
                // the dialog to pop back up right after Cancel.
                if (type === 'print') {
                    if (this.printLock) return;
                    this.printLock = true;
                    // Force-cleanup any leftover listeners/frame from a
                    // previous print flow that never finished cleanly, so
                    // nothing from it can fire later and interfere.
                    if (this._activePrintCleanup) {
                        try { this._activePrintCleanup(); } catch (e) { /* noop */ }
                        this._activePrintCleanup = null;
                    }
                }

                var self = this;

                var params = new URLSearchParams({
                    type:    type,
                    program: (wire && wire.filterCourse) || '',
                    batch:   (wire && wire.filterBatch)  || '',
                });
                var url = '/registrar/employment-tracking/export?' + params.toString();

                try {
                    if (type === 'print') {
                        var res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            var msg = await this.readErrorMessage(res, 'Print generation failed. Please try again.');
                            throw new Error(msg);
                        }
                        var html = await res.text();

                        // ── Always start from a clean iframe ────────────────
                        // Reusing the same iframe/onload across exports was the
                        // cause of "print -> Cancel -> print dialog pops right
                        // back up": a stale 'load' handler from a previous
                        // export (or a re-fired load event) could call
                        // print() again after the user had already dismissed
                        // it. Removing and recreating the iframe each time,
                        // plus binding 'load'/'afterprint' with {once:true},
                        // guarantees print() fires exactly once per click and
                        // the iframe is torn down as soon as the dialog
                        // closes — whether the user printed or hit Cancel.
                        var oldFrame = document.getElementById('emp-print-frame');
                        if (oldFrame) oldFrame.remove();

                        var frame = document.createElement('iframe');
                        frame.id = 'emp-print-frame';
                        frame.style.position = 'fixed';
                        frame.style.right = '0';
                        frame.style.bottom = '0';
                        frame.style.width = '0';
                        frame.style.height = '0';
                        frame.style.border = '0';
                        document.body.appendChild(frame);

                        var doc = frame.contentWindow.document;
                        doc.open();
                        doc.write(html);
                        doc.close();

                        var cleanedUp   = false;
                        var printFired  = false;
                        var onWinFocus; // declared below, referenced in cleanup

                        var cleanup = function () {
                            if (cleanedUp) return;
                            cleanedUp = true;
                            window.removeEventListener('focus', onWinFocus);
                            if (frame && frame.parentNode) frame.remove();
                            self.printLock = false;
                            if (self._activePrintCleanup === cleanup) self._activePrintCleanup = null;
                        };
                        self._activePrintCleanup = cleanup;

                        // A print dialog blurs the main window while it's
                        // open and returns focus to it the moment it closes
                        // — printed OR cancelled, doesn't matter. Some
                        // browsers only fire 'afterprint' on the top window
                        // (not the iframe that called print()), so this
                        // focus-based fallback catches those cases and is
                        // what actually fixes "Cancel brings the dialog
                        // right back": without it, a stale, never-cleared
                        // iframe/handler could still be sitting around to
                        // react to the next paint/focus cycle. Only ever
                        // *cleans up* — it never calls print() itself, so
                        // regaining focus can no longer reopen the dialog.
                        // Only arms itself AFTER print() has been called, so
                        // the page's very first focus event never closes
                        // the iframe prematurely.
                        onWinFocus = function () {
                            if (!printFired) return;
                            cleanup();
                        };

                        frame.addEventListener('load', function onLoad() {
                            frame.removeEventListener('load', onLoad);
                            setTimeout(function () {
                                // If this flow was already superseded/cleaned
                                // up (e.g. a newer print click came in) before
                                // this timeout ran, bail out — never call
                                // print() on a torn-down frame.
                                if (cleanedUp) return;
                                try {
                                    frame.contentWindow.addEventListener('afterprint', cleanup, { once: true });
                                    window.addEventListener('afterprint', cleanup, { once: true });
                                    window.addEventListener('focus', onWinFocus);
                                    printFired = true;
                                    frame.contentWindow.focus();
                                    frame.contentWindow.print();
                                } catch (e) {
                                    cleanup();
                                }
                            }, 150);

                            // Safety net in case none of the above ever fire
                            // (some older/webview browsers support neither).
                            setTimeout(cleanup, 60000);
                        }, { once: true });
                    } else {
                        var res2 = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res2.ok) {
                            var msg2 = await this.readErrorMessage(
                                res2,
                                type === 'excel' ? 'Excel export failed. Please try again.' : 'PDF export failed. Please try again.'
                            );
                            throw new Error(msg2);
                        }

                        var blob = await res2.blob();
                        var disposition = res2.headers.get('Content-Disposition') || '';
                        var filename = type === 'excel' ? 'employment-tracking-report.xls' : 'employment-tracking-report.pdf';
                        var match = disposition.match(/filename="?([^"]+)"?/);
                        if (match) filename = match[1];

                        var blobUrl = window.URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(blobUrl);
                    }
                } catch (e) {
                    if (type === 'print') this.printLock = false;
                    window.dispatchEvent(new CustomEvent('flash-message', {
                        detail: { type: 'error', message: e && e.message ? e.message : 'Export failed. Please try again.' }
                    }));
                }
            }
        });
    }

    window.__empEnsureReportStore = registerEmpReportStore;
    if (window.Alpine) registerEmpReportStore();
    document.addEventListener('alpine:init', registerEmpReportStore);
    document.addEventListener('livewire:init', registerEmpReportStore);
    document.addEventListener('livewire:navigated', registerEmpReportStore);

    // ── Cursor-follow tooltip (desktop only — never shows on mobile/touch) ─────
    (function initCursorTip() {
        var tip = document.getElementById('emp-cursor-tip');
        if (!tip) return;

        var currentTarget = null;

        function isHoverCapable() {
            return window.matchMedia('(hover: hover) and (pointer: fine)').matches
                && window.innerWidth > 768;
        }

        function showTip(el, e) {
            if (!isHoverCapable()) return;
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
            if (!isHoverCapable()) return;
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
            if (!isHoverCapable()) { hideTip(); return; }
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
        document.addEventListener('touchstart', hideTip, true);
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

    // ── Top Programs Horizontal Bar ───────────────────────────────────────────
    function buildCourseBar(data) {
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        var wrap = canvas.parentElement;
        safeDestroy('chartCourse');

        var hasData = !!(data && data.labels && data.labels.length);
        var emptyMsg = document.getElementById('chartCourseEmpty');

        if (!hasData) {
            canvas.style.display = 'none';
            if (!emptyMsg) {
                emptyMsg = document.createElement('div');
                emptyMsg.id = 'chartCourseEmpty';
                emptyMsg.style.cssText = 'height:100%;display:flex;align-items:center;justify-content:center;text-align:center;padding:0 16px;';
                emptyMsg.innerHTML = '<p style="font-size:11px;color:#333333;line-height:1.5;">No employment data yet for this scope.</p>';
                if (wrap) wrap.appendChild(emptyMsg);
            } else {
                emptyMsg.style.display = 'flex';
            }
            return;
        }

        if (emptyMsg) emptyMsg.style.display = 'none';
        canvas.style.display = 'block';

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
                    x:{grid:{color:'#f3f4f6'},ticks:{display:false},beginAtZero:true},
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

        // ── Reliable chart refresh ──────────────────────────────────────────
        // Previously this relied ONLY on a generic Livewire.hook('commit', ...)
        // listener to know when to rebuild the charts. That hook fires for
        // every commit across the whole app and depends on internal Livewire
        // plumbing (payload.succeed) that can silently no-op across version
        // differences — when it did, the canvases (Employment Status donut,
        // Job Relevance donut, Batch bar, Trend line, Top Programs bar) kept
        // showing whatever data they were built with at page load, even
        // though the Program/Batch filter (and the PHP-rendered stat cards)
        // had already moved on. That's what caused the donut % and the Top
        // Programs card to look "stuck" / wrong right after filtering.
        //
        // The PHP side now explicitly dispatches an 'emp-charts-refresh'
        // browser event every single time computeStats()+buildCharts() run
        // (see refreshDashboard() in the component). Listening for that
        // exact event is a direct, guaranteed signal — no guessing based on
        // generic Livewire internals.
        function hookChartsRefreshEvent() {
            if (!window.Livewire) return;
            Livewire.on('emp-charts-refresh', function () {
                requestAnimationFrame(initAllCharts);
            });
        }

        if (window.Livewire) { hookChartsRefreshEvent(); }
        else { document.addEventListener('livewire:initialized', hookChartsRefreshEvent); }

        // Kept as a secondary safety net in case the dispatched-event path
        // above ever misses a beat — harmless no-op re-render otherwise.
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