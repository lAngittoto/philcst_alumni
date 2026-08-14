<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

new class extends Component {

    use WithPagination;

    // ── Filters — PAGE-LEVEL now. filterCourse (Program, MULTI-SELECT) +
    //    filterBatchFrom/filterBatchTo (Batch Year RANGE) scope EVERYTHING:
    //    stat cards, all charts, the Program Breakdown table, AND the
    //    exported summary report.
    //
    //    Batch range REQUIRES both bounds — if only From or only To is
    //    set, the range is treated as incomplete and is NOT applied to any
    //    query (registrar must finish picking both ends before it filters
    //    anything). Once both are set, both bounds are inclusive.
    //
    //    filterCourse is now an array of program codes so the registrar can
    //    scope to several programs at once instead of exactly one. ────────
    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterLocation  = '';
    public string $filterRelevance = '';
    public string $filterBatchFrom = '';
    public string $filterBatchTo   = '';
    public array  $filterCourse    = [];
    public string $filterDept      = '';

    /**
     * Internal guard — true only while setSingleBatchYear()/clearFilterBatch()/
     * setBatchRange() are writing to filterBatchFrom/filterBatchTo directly,
     * so Livewire's automatic updatedFilterBatchFrom()/updatedFilterBatchTo()
     * hooks skip their own refreshDashboard() call and we don't recompute
     * the whole dashboard twice in the same request.
     */
    private bool $skipBatchHooks = false;
    public string $sortBy          = 'a.last_name';
    public string $sortDir         = 'asc';

    // ── Modal (detail view — has its own in-modal SEARCH plus Batch/Program
    //    filter dropdowns to narrow the currently open record set; these
    //    only touch modalBatch/modalCourse and never the dashboard-level
    //    filterBatchFrom/filterBatchTo/filterCourse) ─────────────────────
    public bool   $showModal    = false;
    public string $activeModal  = '';
    public string $modalFilter  = '';
    public string $modalCourse  = '';
    public ?int   $modalBatch   = null;
    public string $modalSearch  = '';
    public int    $modalPage    = 1;
    public int    $modalPageSize = 100;

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
    public string $topProgramsFullData = '[]';
    public string $chartTrendData     = '{}';

    // ── Top Programs ranking. When exactly ONE program is selected via
    //    $filterCourse, these back the "#6 of 12 programs" rank card. When
    //    2+ programs are selected, the card instead shows a mini-ranking
    //    of just the selected programs, built from $topProgramsSelected
    //    below — courseRank/courseRankTotal/courseRankCount are unused in
    //    that case. ─────────────────────────────────────────────────────
    public ?int $courseRank      = null;
    public ?int $courseRankTotal = null;
    public int  $courseRankCount = 0;
    public string $topProgramsSelectedData = '[]';

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
        // Dedupe + drop empty entries — the checkbox list can only ever
        // toggle real codes on/off, but keep this defensive in case the
        // array is ever manipulated another way.
        $this->filterCourse = array_values(array_unique(array_filter(
            $this->filterCourse,
            fn ($c) => $c !== '' && $c !== null
        )));
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * Toggles a single program code in/out of the multi-select filter —
     * bound directly to each checkbox item in the Program dropdown.
     */
    public function toggleFilterCourse(string $code): void
    {
        if (in_array($code, $this->filterCourse, true)) {
            $this->filterCourse = array_values(array_diff($this->filterCourse, [$code]));
        } else {
            $this->filterCourse[] = $code;
        }
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * "All Program Codes" inside the Program dropdown — clears ONLY the
     * program selection, unlike clearFilters() which also wipes the Batch
     * Year range.
     */
    public function clearFilterCourse(): void
    {
        $this->filterCourse = [];
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * "Select All" inside the Program dropdown — checks every program code
     * currently in the course catalog, mirroring clearFilterCourse()'s
     * "uncheck all" behavior at the opposite end of the same toggle.
     */
    public function selectAllFilterCourse(): void
    {
        $this->filterCourse = array_values(array_keys($this->courseMap));
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    public function updatedFilterBatchFrom(): void
    {
        if ($this->skipBatchHooks) return;
        $this->normalizeBatchRange();
        // Don't fire a filter request yet if only "From" is picked — wait
        // until "To" is also set (or both are cleared back to "Any") so a
        // half-picked range never triggers a query, and picking From then
        // To doesn't cause two separate dashboard refreshes.
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo === '') {
            return;
        }
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    public function updatedFilterBatchTo(): void
    {
        if ($this->skipBatchHooks) return;
        $this->normalizeBatchRange();
        // Mirror of updatedFilterBatchFrom() above — picking only "To"
        // first shouldn't filter until "From" is set too.
        if ($this->filterBatchTo !== '' && $this->filterBatchFrom === '') {
            return;
        }
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * If the registrar picks a "from" year later than "to" (or vice versa),
     * swap them instead of silently returning zero rows. Keeps the range
     * always valid no matter which end was changed last.
     */
    private function normalizeBatchRange(): void
    {
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== ''
            && (int)$this->filterBatchFrom > (int)$this->filterBatchTo) {
            [$this->filterBatchFrom, $this->filterBatchTo] = [$this->filterBatchTo, $this->filterBatchFrom];
        }
    }

    /**
     * Single-year quick pick from the default (non-range) Batch Year list —
     * sets From and To to the same year in ONE Livewire round-trip, instead
     * of two separate $wire.set() calls (which each fire their own
     * updatedFilterBatch* hook and used to cause the dashboard to refresh
     * twice in a row for a single click). Writes via the internal setter so
     * the updatedFilterBatch*() hooks don't ALSO fire and refresh a second
     * time within the same request.
     */
    public function setSingleBatchYear(string $year): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = $year;
        $this->filterBatchTo   = $year;
        $this->skipBatchHooks = false;
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * "All Batch Years" — clears both ends of the range in one round-trip,
     * same reasoning as setSingleBatchYear() above.
     */
    public function clearFilterBatch(): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
        $this->skipBatchHooks = false;
        $this->closeAnyModal();
        $this->refreshDashboard();
    }

    /**
     * Applies a From–To range in ONE Livewire round-trip. Bound to the
     * range picker's local Alpine state (not wire:model.live on the two
     * <select>s) so picking "From" alone never touches the server at all —
     * the request only fires once both ends are chosen and this single
     * method is called.
     */
    public function setBatchRange(string $from, string $to): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = $from;
        $this->filterBatchTo   = $to;
        $this->skipBatchHooks = false;
        $this->normalizeBatchRange();
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

    /**
     * In-modal Batch/Program dropdowns — narrow the currently open
     * detail modal without touching the dashboard-level filterBatchFrom/
     * filterCourse or closing the modal. Fires whenever either value
     * changes via the dropdowns rendered inside the modal itself.
     */
    public function updatedModalBatch(): void
    {
        $this->modalPage = 1;
        unset($this->modalRecords);
    }

    public function updatedModalCourse(): void
    {
        $this->modalPage = 1;
        unset($this->modalRecords);
    }

    public function clearFilters(): void
    {
        $this->closeAnyModal();
        $this->filterCourse    = [];
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
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
        // ── Without this unset, the "Report will include" summary (and the
        // filter-scope badge next to Reset) could keep showing the PREVIOUS
        // filter's text — e.g. still "All Programs" right after picking
        // Batch 2027 — because #[Computed] memoizes this method's return
        // value for the lifetime of the component instance. It's cheap to
        // recompute, so just always drop the memoized value here alongside
        // every other filter-dependent piece of state. ────────────────────
        unset($this->activeReportFilterSummary);
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

    /**
     * Applies the dashboard-level Batch Year RANGE filter to any query
     * builder that has an `a.batch` column in scope. Centralized here so
     * every chart/stat query (and computeStats/baseQuery) applies the
     * exact same bounds — this is what was missing before: the Program
     * filter and the Batch filter used to be applied by two different,
     * inconsistently-wired `when()` clauses, so combining Program + Year
     * silently dropped the year bound on some queries. Single choke point
     * now; every caller below goes through this.
     *
     * RANGE IS ALL-OR-NOTHING: the filter only takes effect once BOTH
     * From and To have a value. A lone From (or lone To) is treated as an
     * incomplete range and is intentionally NOT applied to the query —
     * previously a lone bound quietly filtered as open-ended, which made
     * the dashboard look "broken" while the registrar was still in the
     * middle of picking a range.
     */
    private function batchRangeIsComplete(): bool
    {
        return $this->filterBatchFrom !== '' && $this->filterBatchTo !== '';
    }

    private function applyBatchRange(\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder
    {
        if ($this->batchRangeIsComplete()) {
            $q->where('a.batch', '>=', $this->filterBatchFrom)
              ->where('a.batch', '<=', $this->filterBatchTo);
        }
        return $q;
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni as a')->whereNull('a.deleted_at');
        if (!empty($this->filterCourse)) $q->whereIn('a.course_code', $this->filterCourse);
        $this->applyBatchRange($q);
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
        $courseFilter  = $this->filterCourse;
        $batchFrom     = $this->filterBatchFrom;
        $batchTo       = $this->filterBatchTo;
        $rangeComplete = $this->batchRangeIsComplete();

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
            ->when(!empty($courseFilter), fn($q) => $q->whereIn('a.course_code', $courseFilter))
            ->when($rangeComplete, fn($q) => $q->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo))
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

        // Batch stacked bar — scoped by Program filter AND the Batch Year
        // range (a range still shows multiple bars, one per year in range,
        // instead of collapsing to a single bar the way the old
        // single-year picker did)
        $batchRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when(!empty($courseFilter), fn($q) => $q->whereIn('a.course_code', $courseFilter))
            ->when($rangeComplete, fn($q) => $q->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo))
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
        // scoped only by the Batch Year range (never by Program — ranking
        // one program against itself is meaningless).
        //
        // IMPORTANT: this MUST be built from the same full-catalog dataset
        // as the "View All" list below (topProgramsAll), not from a
        // separate INNER-JOIN-only query. A program with 0 working alumni
        // legitimately still HAS a rank (it's simply ranked last, or tied
        // last) — it used to fall through to "no data" here purely because
        // the old $courseRowsAll INNER JOIN drops any course with zero
        // matching employment rows, while "View All" (LEFT JOIN, full
        // course catalog) kept it and correctly showed e.g. "#3". Both
        // views must agree, so we compute the full ranked list ONCE and
        // both the card and the modal read from it.
        $courseRowsAll = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($rangeComplete, fn($q) => $q->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo))
            ->joinSub($this->latestEmpSubquery(), 'latest_et', fn($j) => $j->on('a.id', '=', 'latest_et.alumni_id'))
            ->join('employment_trackings as et', fn($j) => $j->on('et.id', '=', 'latest_et.max_id')->whereNull('et.deleted_at'))
            ->whereIn('et.employment_status', ['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')->get();

        // Full catalog ranking — every program in courseMap gets a row and
        // a rank, working alumni or not. This is the ONE ranked list used
        // by both the "Top Programs" card AND the "View All" modal, so the
        // two can never disagree again.
        $topProgramsWorking = $courseRowsAll->pluck('cnt', 'course_code');
        $topProgramsTotals  = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when($rangeComplete, fn($q) => $q->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo))
            ->select('a.course_code', DB::raw('COUNT(*) as total'))
            ->groupBy('a.course_code')->pluck('total', 'course_code');

        // Case-insensitive, trimmed keying — `alumni.course_code` is
        // free-typed data, so "Bsit" and "BSIT" must land in the same
        // bucket instead of splitting into two separate rows.
        $topProgramsAll = collect(array_keys($this->courseMap))
            ->map(function ($code) use ($topProgramsWorking, $topProgramsTotals) {
                $needle = mb_strtolower(trim($code));
                $working = 0;
                foreach ($topProgramsWorking as $rowCode => $cnt) {
                    if (mb_strtolower(trim((string) $rowCode)) === $needle) { $working = (int) $cnt; break; }
                }
                $total = 0;
                foreach ($topProgramsTotals as $rowCode => $cnt) {
                    if (mb_strtolower(trim((string) $rowCode)) === $needle) { $total = (int) $cnt; break; }
                }
                return ['code' => $code, 'working' => $working, 'total' => $total];
            })
            // Rank by working DESC first (matches the "by employed +
            // self-employed" label), then by total DESC as a tiebreaker
            // so two programs both at 0 working still land in a stable,
            // sensible order instead of an arbitrary one.
            ->sortByDesc(fn($row) => [$row['working'], $row['total']])
            ->values();

        $this->topProgramsFullData = json_encode(
            $topProgramsAll->map(fn($row, $idx) => [
                'rank'    => $idx + 1,
                'code'    => $row['code'],
                'working' => $row['working'],
                'total'   => $row['total'],
            ])->values()
        );

        $selectedCount = count($courseFilter);

        if ($selectedCount === 0) {
            // No Program selected — show the top 3 as the horizontal bar.
            $courseRows = $courseRowsAll->sortByDesc('cnt')->take(3)->values();

            $this->chartCourseData = json_encode([
                'labels' => $courseRows->pluck('course_code'),
                'data'   => $courseRows->pluck('cnt'),
            ]);

            $this->courseRank            = null;
            $this->courseRankTotal       = null;
            $this->courseRankCount       = 0;
            $this->topProgramsSelectedData = '[]';
        } elseif ($selectedCount === 1) {
            // Exactly one Program selected — the horizontal bar is replaced
            // by a rank card in the Blade view (e.g. "#3 of 8 programs").
            // Pulled straight from topProgramsAll above — the exact same
            // list "View All" renders — so a program with 0 working alumni
            // still gets its real rank instead of falling back to a "no
            // data" empty state.
            $this->chartCourseData = json_encode(['labels' => [], 'data' => []]);
            $this->topProgramsSelectedData = '[]';

            $needle    = mb_strtolower(trim($courseFilter[0]));
            $rankIndex = $topProgramsAll->search(fn($r) => mb_strtolower(trim((string) $r['code'])) === $needle);

            if ($rankIndex !== false) {
                $matched = $topProgramsAll[$rankIndex];
                $this->courseRank      = $rankIndex + 1;
                $this->courseRankTotal = $topProgramsAll->count();
                $this->courseRankCount = $matched['working'];
            } else {
                // Program filter value doesn't exist in the course catalog
                // at all (e.g. stale/renamed course code) — genuinely no
                // rank to show.
                $this->courseRank      = null;
                $this->courseRankTotal = null;
                $this->courseRankCount = 0;
            }
        } else {
            // 2+ Programs selected — no single rank makes sense, so the
            // card instead shows a mini-ranking board of just the selected
            // programs (1st/2nd/3rd... among each other), same ordering
            // rule as the full ranking (working DESC, then total DESC).
            $this->chartCourseData = json_encode(['labels' => [], 'data' => []]);
            $this->courseRank      = null;
            $this->courseRankTotal = null;
            $this->courseRankCount = 0;

            $needles = collect($courseFilter)->map(fn($c) => mb_strtolower(trim($c)));
            $selectedRows = $topProgramsAll
                ->filter(fn($r) => $needles->contains(mb_strtolower(trim((string) $r['code']))))
                ->values();

            $this->topProgramsSelectedData = json_encode(
                $selectedRows->map(fn($row, $idx) => [
                    'rank'    => $idx + 1,
                    'code'    => $row['code'],
                    'working' => $row['working'],
                    'total'   => $row['total'],
                ])->values()
            );
        }

        // Employment rate trend per batch (line chart) — scoped by Program
        // filter AND Batch Year range (this is the query that powers
        // "Employment Breakdown by Batch Year"; both filters now apply
        // together correctly instead of the year silently being ignored
        // whenever a Program was also selected)
        $trendRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->when(!empty($courseFilter), fn($q) => $q->whereIn('a.course_code', $courseFilter))
            ->when($rangeComplete, fn($q) => $q->where('a.batch', '>=', $batchFrom)->where('a.batch', '<=', $batchTo))
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
            ->when(!empty($this->filterCourse), fn($q) => $q->whereIn('a.course_code', $this->filterCourse))
            ->when($this->batchRangeIsComplete(), fn($q) => $q->where('a.batch', '>=', $this->filterBatchFrom)->where('a.batch', '<=', $this->filterBatchTo))
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
     * Batch Year) — used to build the actual exported PDF/Excel/Print
     * report's "Report scope" line ($filters, passed straight through to
     * the print/export view). Always includes BOTH a Batch segment and a
     * Programs segment — "All Batch Years" / "All Programs" are shown
     * explicitly rather than silently omitted when nothing is picked, so
     * the exported report's scope line never looks like it's missing
     * something. (The live "Report will include" preview inside the
     * Generate Reports dropdown mirrors this exact logic client-side in
     * Alpine, since that panel sits inside a wire:ignore block.)
     */
    #[Computed]
    public function activeReportFilterSummary(): string
    {
        $parts = [];

        if ($this->batchRangeIsComplete()) {
            $parts[] = $this->filterBatchFrom === $this->filterBatchTo
                ? 'Batch ' . $this->filterBatchFrom
                : 'Batch ' . $this->filterBatchFrom . '–' . $this->filterBatchTo;
        } elseif ($this->filterBatchFrom !== '' || $this->filterBatchTo !== '') {
            // One end picked, the other still blank — range isn't applied
            // to the query yet, so say so instead of implying a scope that
            // isn't actually in effect.
            $parts[] = 'Batch range incomplete (not yet applied)';
        } else {
            $parts[] = 'All Batch Years';
        }

        if (count($this->filterCourse) === 1) {
            $parts[] = $this->filterCourse[0];
        } elseif (count($this->filterCourse) > 1) {
            $parts[] = implode(', ', $this->filterCourse);
        } else {
            $parts[] = 'All Programs';
        }

        return implode(' · ', $parts);
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

    /**
     * Narrows the currently open detail modal by Batch / Program without
     * closing it or touching the dashboard-level filterBatchFrom/filterBatchTo/filterCourse.
     * Mirrors clearFilters() below but scoped to the modal only.
     */
    public function clearModalFilters(): void
    {
        $this->modalBatch  = null;
        $this->modalCourse = '';
        $this->modalPage   = 1;
        unset($this->modalRecords);
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

{{-- ══ FLASH TOAST — mirrors Alumni Records' toast, shows the "Generating
     your PDF/Excel/print view… this only takes a moment" info message
     dispatched right when a report export starts, plus success/error
     messages. ══ --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,5000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2 scale-95"
     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 scale-95"
     class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3 rounded-xl shadow-2xl max-w-sm border-l-4 bg-white"
     :class="{ 'border-emerald-500':type==='success','border-red-500':type==='error','border-blue-500':type==='info' }"
     style="display:none">
    <i class="fas mt-0.5 text-base shrink-0"
       :class="{ 'fa-circle-check text-emerald-500':type==='success','fa-circle-exclamation text-red-500':type==='error','fa-circle-info text-blue-500':type==='info' }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-[#333333]" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 text-[#666666] leading-snug break-words font-normal" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-[#999999] hover:text-[#666666] transition shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

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
    .stat-card-employed:hover  { border-color: #6ee7b7 !important; }
    .stat-card-self-emp:hover  { border-color: #93c5fd !important; }
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

    /* ── Modal search+filter bar (Batch Year / Program Code inside the
       full-screen detail modal): same rule as .emp-filter-bar above —
       this wrapper must never set overflow-x/overflow-y, or it clips the
       absolutely positioned .ar-dropdown-menu popups and makes them
       silently fail to show. Wraps on narrow screens instead of
       horizontally scrolling, and gets its own stacking context so its
       dropdown popups always paint above the table body underneath. ── */
    .emp-modal-filter-bar {
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
        display: block; width: 100%; padding: 8px 10px; border-radius: 7px;
        font-size: .9rem; font-weight: 600; text-align: left; color: #333;
        transition: background .1s; cursor: pointer; white-space: nowrap;
        border: none; background: transparent;
        user-select: none; -webkit-user-select: none;
    }
    .ar-dropdown-item:hover { background: #F5F0FA; color: #7A3F91; }
    .ar-dropdown-item.active { background: #F0E6F8; color: #7A3F91; }
    .ar-dropdown-trigger {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 12px; border: 1.5px solid #E8E0F0; border-radius: 8px;
        font-size: .9rem; font-weight: 600; background: #fff; color: #333;
        cursor: pointer; transition: border-color .15s, background .15s, color .15s;
        white-space: nowrap; user-select: none;
    }
    .ar-dropdown-trigger:hover { border-color: #c49ed8; }
    .ar-dropdown-trigger.has-value { border-color: #7A3F91; background: #F9F7FC; color: #7A3F91; }
    .ar-dropdown-trigger .ar-chevron { transition: transform .18s; font-size: .65rem; opacity: .6; }
    .ar-dropdown-trigger.open .ar-chevron { transform: rotate(180deg); }

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
     data-trend="{{ $chartTrendData }}"
     data-top-programs-full="{{ $topProgramsFullData }}"
     data-top-programs-selected="{{ $topProgramsSelectedData }}">
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

            {{-- ══ GENERATE REPORTS BUTTON ══
                 wire:ignore on this whole block is intentional — it stops
                 Livewire from morphing the Alpine dropdown/transition state
                 out from under itself on every commit. BUT that also means
                 Livewire will NEVER touch the Blade `{{ }}` interpolations
                 in here again after first paint — which is exactly why
                 "Report will include" used to keep showing "All Programs" /
                 the old alumni count forever after the very first filter
                 change, no matter what Program/Batch was picked afterward.
                 Fix: don't interpolate Blade values into this subtree at
                 all — read $wire.filterCourse / $wire.filterBatchFrom /
                 $wire.filterBatchTo / $wire.totalAlumni reactively via
                 Alpine instead, mirroring activeReportFilterSummary()'s
                 logic client-side so it updates live without needing
                 Livewire to re-render this block. ── --}}
            <div class="relative shrink-0" wire:ignore
                 x-data="{
                    reportSummary() {
                        var course = $wire.filterCourse || [];
                        var from   = $wire.filterBatchFrom || '';
                        var to     = $wire.filterBatchTo   || '';
                        var parts  = [];
                        // Batch segment — only added once the range is
                        // actually complete (a lone From/To isn't applied
                        // to the query yet, so don't claim it's in scope).
                        if (from !== '' && to !== '') {
                            parts.push('Batch ' + (from === to ? from : from + '–' + to));
                        } else if (from !== '' || to !== '') {
                            parts.push('Batch range incomplete (not yet applied)');
                        } else {
                            parts.push('All Batch Years');
                        }
                        // Programs segment — ALWAYS shown, same as the
                        // filter bar's own 'All Programs' default, so
                        // the report scope never silently drops this line
                        // just because nothing is selected.
                        if (course.length === 1) {
                            parts.push(course[0]);
                        } else if (course.length > 1) {
                            parts.push(course.join(', '));
                        } else {
                            parts.push('All Programs');
                        }
                        return parts.join(' · ');
                    }
                 }"
                 x-init="window.__empEnsureReportStore && window.__empEnsureReportStore()"
                 @click.outside="$store.empReport.open=false" wire:key="emp-report-dropdown">
                <button type="button" @click.stop="$store.empReport.toggle()" class="ar-report-btn"
                        :disabled="$store.empReport.exporting"
                        :class="{ 'ar-report-btn-active': $store.empReport.open }">
                    <i class="fas fa-spinner animate-spin" x-show="$store.empReport.exporting" style="display:none;"></i>
                    <i class="fas fa-chart-column" x-show="!$store.empReport.exporting"></i>
                    <span class="ar-report-tip">Generate Reports</span>
                </button>

                <div x-show="$store.empReport.open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-report-menu" style="display:none;">

                    <div class="ar-report-menu-message">
                        <span class="lbl"><i class="fas fa-circle-info mr-1"></i>Report will include</span>
                        <span class="txt" x-text="reportSummary()"></span>
                        <span class="cnt" x-text="Number($wire.totalAlumni || 0).toLocaleString() + ' alumni in this scope'"></span>
                    </div>

                    <button type="button" @click="$store.empReport.doExport('pdf', $wire)"
                            :disabled="$store.empReport.exporting" class="ar-report-menu-item item-pdf">
                        <span class="ar-item-icon">
                            <i class="fas fa-spinner animate-spin" x-show="$store.empReport.exportingType==='pdf'" style="display:none;"></i>
                            <i class="fas fa-file-pdf" x-show="$store.empReport.exportingType!=='pdf'"></i>
                        </span>
                        <span class="ar-item-label">Export as PDF</span>
                    </button>

                    <button type="button" @click="$store.empReport.doExport('excel', $wire)"
                            :disabled="$store.empReport.exporting" class="ar-report-menu-item item-excel">
                        <span class="ar-item-icon">
                            <i class="fas fa-spinner animate-spin" x-show="$store.empReport.exportingType==='excel'" style="display:none;"></i>
                            <i class="fas fa-file-excel" x-show="$store.empReport.exportingType!=='excel'"></i>
                        </span>
                        <span class="ar-item-label">Export as Excel</span>
                    </button>

                    <button type="button" @click="$store.empReport.doExport('print', $wire)"
                            :disabled="$store.empReport.exporting || $store.empReport.printLock" class="ar-report-menu-item item-print">
                        <span class="ar-item-icon">
                            <i class="fas fa-spinner animate-spin" x-show="$store.empReport.exportingType==='print'" style="display:none;"></i>
                            <i class="fas fa-print" x-show="$store.empReport.exportingType!=='print'"></i>
                        </span>
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
             wire:loading.class="opacity-60" wire:target="toggleFilterCourse,clearFilterCourse,selectAllFilterCourse,setSingleBatchYear,clearFilterBatch,setBatchRange"
             @click.stop>

            <span class="ar-filter-label text-sm font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">FILTERS</span>

            <div class="h-5 w-px bg-[#E8E0F0] shrink-0"></div>

            {{-- Batch Year — back to the original single-select list of
                 years by default (click a year, done, like before), PLUS
                 an "Add Range" option at the bottom of the same dropdown.
                 Clicking it swaps the list view for From/To selects so a
                 range (e.g. 2000–2025) is opt-in rather than always-on.

                 RANGE IS ALL-OR-NOTHING: picking only From (or only To)
                 does NOT filter anything yet — the trigger label makes
                 this explicit ("2020 → pick an end year") instead of
                 quietly looking like a working filter while the query is
                 still unscoped server-side. --}}
            <div class="ar-dropdown shrink-0"
                 x-data="{
                    get open(){ return $store.empFilters.isOpen('batch'); },
                    rangeMode: {{ ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) ? 'true' : 'false' }},
                    rangeFrom: '{{ $filterBatchFrom }}',
                    rangeTo: '{{ $filterBatchTo }}',
                    toggle(){ $store.empFilters.toggle('batch'); },
                    close(){ $store.empFilters.close('batch'); },
                    selectYear(val){
                        $wire.setSingleBatchYear(val);
                        this.close();
                        this.$nextTick(() => {
                            setTimeout(() => {
                                var target = document.getElementById('emp-charts-row-1');
                                if (target) {
                                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }
                            }, 150);
                        });
                    },
                    clearYear(){ this.rangeFrom=''; this.rangeTo=''; $wire.clearFilterBatch(); this.close(); },
                    startRange(){ this.rangeFrom=$wire.filterBatchFrom||''; this.rangeTo=$wire.filterBatchTo||''; this.rangeMode=true; },
                    pickFrom(val){ this.rangeFrom=val; this.applyRangeIfComplete(); },
                    pickTo(val){ this.rangeTo=val; this.applyRangeIfComplete(); },
                    applyRangeIfComplete(){
                        if(this.rangeFrom!=='' && this.rangeTo!==''){
                            $wire.setBatchRange(this.rangeFrom, this.rangeTo);
                            this.close();
                            // auto-scroll down to the charts so the applied
                            // range is immediately visible instead of the
                            // user having to scroll manually to confirm it
                            this.$nextTick(() => {
                                setTimeout(() => {
                                    var target = document.getElementById('emp-charts-row-1');
                                    if (target) {
                                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }
                                }, 150);
                            });
                        }
                    }
                 }"
                 @click.outside="close()" wire:key="emp-batch-dropdown">
                <button type="button" @click.stop="toggle()"
                        :class="{ 'has-value': $wire.filterBatchFrom!=='' && $wire.filterBatchTo!=='', 'open':open }"
                        class="ar-dropdown-trigger">
                    <i class="fas fa-calendar-days" style="font-size:11px;opacity:.7;"></i>
                    <span>
                        @if($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo)
                            Batch {{ $filterBatchFrom }}–{{ $filterBatchTo }}
                        @elseif($filterBatchFrom !== '' && $filterBatchTo !== '')
                            Batch {{ $filterBatchFrom }}
                        @elseif($filterBatchFrom !== '')
                            Batch {{ $filterBatchFrom }} → pick end year
                        @elseif($filterBatchTo !== '')
                            pick start year → Batch {{ $filterBatchTo }}
                        @else
                            All Batch Years
                        @endif
                    </span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;min-width:190px;" @click.stop>

                    {{-- Default view: plain year list, exactly like before --}}
                    <template x-if="!rangeMode">
                        <div>
                            <button type="button" @click.stop="clearYear()" :class="{'active':$wire.filterBatchFrom==='' && $wire.filterBatchTo===''}" class="ar-dropdown-item">All Batch Years</button>
                            @foreach($this->batchYears as $year)
                            <button type="button" @click.stop="selectYear('{{ $year }}')" :class="{'active': $wire.filterBatchFrom==='{{ $year }}' && $wire.filterBatchTo==='{{ $year }}'}" class="ar-dropdown-item">{{ $year }}</button>
                            @endforeach
                            <div class="h-px bg-[#E8E0F0] my-1"></div>
                            <button type="button" @click.stop="startRange()"
                                    class="ar-dropdown-item flex items-center gap-1.5 font-semibold" style="color:#7A3F91;">
                                <i class="fas fa-plus" style="font-size:10px;"></i> Add Range
                            </button>
                        </div>
                    </template>

                    {{-- Range view: opt-in, shown right away once "Add Range"
                         is clicked (no extra click/reopen needed — same
                         dropdown, just swapped view via rangeMode). From/To
                         are held in LOCAL Alpine state only — nothing is
                         sent to the server while just one side is picked.
                         The moment both sides have a value, applyRangeIfComplete()
                         fires ONE Livewire call (setBatchRange) that applies
                         the whole range at once; normalizeBatchRange() on
                         the server auto-swaps them if From ends up later
                         than To. --}}
                    <template x-if="rangeMode">
                        <div class="p-2" style="width:220px;">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="flex-1 text-[10px] font-bold uppercase tracking-wide text-[#7A3F91]">From</span>
                                <span class="flex-1 text-[10px] font-bold uppercase tracking-wide text-[#7A3F91]">To</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:110px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                    @foreach($this->batchYears as $year)
                                    <button type="button" @click.stop="pickFrom('{{ $year }}')" :class="{'active':rangeFrom==='{{ $year }}'}" class="ar-dropdown-item" style="border-radius:0;">{{ $year }}</button>
                                    @endforeach
                                </div>
                                <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:110px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                    @foreach($this->batchYears as $year)
                                    <button type="button" @click.stop="pickTo('{{ $year }}')" :class="{'active':rangeTo==='{{ $year }}'}" class="ar-dropdown-item" style="border-radius:0;">{{ $year }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex items-center gap-2 mt-3">
                                <button type="button" @click.stop="rangeMode=false"
                                        class="flex-1 text-xs font-semibold text-[#333333] hover:bg-[#F5F5F5] rounded-lg py-1.5 transition-colors border border-[#E8E0F0]">
                                    Back to List
                                </button>
                                <button type="button" @click.stop="clearYear(); rangeMode=false;"
                                        class="flex-1 text-xs font-semibold text-[#7A3F91] hover:bg-[#F5F0FA] rounded-lg py-1.5 transition-colors border border-[#E8E0F0]">
                                    Clear
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Program Code — MULTI-SELECT with real checkboxes. Checking
                 a box toggles that program in/out of filterCourse via
                 toggleFilterCourse(); the dropdown stays open across
                 clicks (no auto-close) so the registrar can check several
                 programs in one go. A "Select All" checkbox lives in a
                 sticky header row at the TOP of the list (not a footer
                 button at the bottom) — it's tri-state: checked when every
                 program is selected, indeterminate (dash) when some but
                 not all are selected. The trigger label shows the count
                 once 2+ are picked so the bar doesn't overflow with every
                 selected code. --}}
            <div class="ar-dropdown shrink-0"
                 x-data="{ get open(){ return $store.empFilters.isOpen('course'); }, toggle(){ $store.empFilters.toggle('course'); if(this.open){ this.$nextTick(()=>{ if(this.$refs.courseMenu) this.$refs.courseMenu.scrollTop = 0; }); } }, close(){ $store.empFilters.close('course'); } }"
                 @click.outside="close()" wire:key="emp-course-dropdown">
                <button type="button" @click.stop="toggle()" :class="{ 'has-value':$wire.filterCourse.length>0,'open':open }" class="ar-dropdown-trigger">
                    <i class="fas fa-graduation-cap" style="font-size:11px;opacity:.7;"></i>
                    <span>
                        @if(count($filterCourse) === 0)
                            All Programs
                        @elseif(count($filterCourse) === 1)
                            {{ $filterCourse[0] }}
                        @else
                            {{ count($filterCourse) }} Programs
                        @endif
                    </span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open" x-ref="courseMenu"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;min-width:220px;" @click.stop>

                    {{-- Header row: "Select All" checkbox sits beside the
                         count, at the TOP of the list (not a footer button
                         at the bottom) — one tap checks/unchecks every
                         program code at once. --}}
                    <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-[#E8E0F0] sticky -top-1 -mx-1 -mt-1 bg-white z-10 rounded-t-[8px]">
                        <label class="flex items-center gap-2 text-xs font-semibold text-[#333333] cursor-pointer select-none">
                            <input type="checkbox"
                                   :checked="$wire.filterCourse.length === {{ count($this->courseMap) }}"
                                   :indeterminate="$wire.filterCourse.length > 0 && $wire.filterCourse.length < {{ count($this->courseMap) }}"
                                   @change="$event.target.checked ? $wire.selectAllFilterCourse() : $wire.clearFilterCourse()"
                                   class="w-3.5 h-3.5 rounded border-[#D4C5E8] text-[#7A3F91] focus:ring-[#7A3F91]/30 cursor-pointer">
                            Select All
                        </label>
                        <span class="text-xs font-bold text-[#7A3F91] select-none" x-show="$wire.filterCourse.length > 0">
                            <span x-text="$wire.filterCourse.length"></span> selected
                        </span>
                    </div>

                    @foreach($this->courseMap as $code => $name)
                    <label class="ar-dropdown-item flex items-center gap-2 cursor-pointer select-none"
                           :class="{'active': $wire.filterCourse.includes('{{ $code }}')}">
                        <input type="checkbox" wire:click="toggleFilterCourse('{{ $code }}')"
                               :checked="$wire.filterCourse.includes('{{ $code }}')"
                               class="w-3.5 h-3.5 rounded border-[#D4C5E8] text-[#7A3F91] focus:ring-[#7A3F91]/30 cursor-pointer shrink-0">
                        <span>{{ $name }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Reset — always visible now, not conditional on an active filter --}}
            <button wire:click="clearFilters" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="clearFilters"
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-95 shrink-0 disabled:pointer-events-none">
                <span wire:loading wire:target="clearFilters">
                    <i class="fas fa-spinner animate-spin text-sm"></i>
                </span>
                <i class="fas fa-rotate-left text-sm" wire:loading.remove wire:target="clearFilters"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Active-scope pill — same treatment as Alumni Records' "Batch
                 2027 — 1 result(s)" badge next to its own Reset button.
                 Only shows once a filter is ACTUALLY in effect (batch range
                 complete and/or 1+ programs picked) — an incomplete range
                 alone stays silent here too, consistent with it not being
                 applied to any query yet. Lives outside wire:ignore, so it
                 re-renders normally on every filter change like the rest
                 of the filter bar. --}}
            @if($this->batchRangeIsComplete() || count($filterCourse) > 0)
            <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold shrink-0"
                  style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                <i class="fas fa-calendar-check" style="font-size:11px;"></i>
                @php
                    $pillParts = [];
                    if ($this->batchRangeIsComplete()) {
                        $pillParts[] = $filterBatchFrom === $filterBatchTo
                            ? 'Batch ' . $filterBatchFrom
                            : 'Batch ' . $filterBatchFrom . '–' . $filterBatchTo;
                    }
                    if (count($filterCourse) === 1) {
                        $pillParts[] = $filterCourse[0];
                    } elseif (count($filterCourse) > 1) {
                        $pillParts[] = implode(', ', $filterCourse);
                    }
                @endphp
                {{ implode(' · ', $pillParts) }} — {{ number_format($totalAlumni) }} result(s)
            </span>
            @endif
        </div>
    </div>

<div class="relative flex-1 min-h-0">

    {{-- Center overlay spinner — icon only, no background box, absolutely
         positioned over the scroll area (NOT sticky/in-flow), so it never
         competes with the sticky <thead> elements below for the top:0 slot
         inside the scrolling container. Pure overlay: floats on top, table
         layout is completely undisturbed while loading. --}}
    <div class="absolute top-0 left-0 w-full z-20 flex items-center justify-center pointer-events-none"
         wire:loading wire:target="toggleFilterCourse,clearFilterCourse,selectAllFilterCourse,setSingleBatchYear,clearFilterBatch,setBatchRange">
        <div class="flex items-center justify-center" style="margin-top:16px;">
            <i class="fas fa-spinner fa-spin" style="font-size:34px; color:#7A3F91;"></i>
        </div>
    </div>

<div class="relative flex-1 h-full overflow-y-auto"
     style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;"
     @scroll.passive="showMoreIndicator = ($event.target.scrollHeight - $event.target.scrollTop - $event.target.clientHeight) > 80">

<div class="flex flex-col px-3 sm:px-6 py-3 sm:py-4 gap-3 sm:gap-4 max-w-[1920px] mx-auto w-full box-border"
     wire:loading.class="opacity-40 pointer-events-none" wire:target="toggleFilterCourse,clearFilterCourse,selectAllFilterCourse,setSingleBatchYear,clearFilterBatch,setBatchRange" style="transition:opacity .2s ease;">

    {{-- ── 5 STAT CARDS — Submitted, Employed, Self-Employed, Unemployed,
         No Record. Employed and Self-Employed are shown as their own
         cards (rather than combined into one "Working" card) so they
         match how the rest of this dashboard already breaks them out
         (Program Breakdown table, status badges, donut legend). ── --}}
    @php
        $empRate      = $totalAlumni   > 0 ? round($totalEmployed  / $totalAlumni   * 100, 1) : 0;
        $selfRate     = $totalAlumni   > 0 ? round($totalSelf      / $totalAlumni   * 100, 1) : 0;
        $responseRate = $totalAlumni   > 0 ? round($totalSubmitted / $totalAlumni   * 100, 1) : 0;
        $unempRate    = $totalSubmitted > 0 ? round($totalUnemployed / $totalSubmitted * 100, 1) : 0;
        $nfRate       = $totalAlumni   > 0 ? round($totalNotFilled / $totalAlumni   * 100, 1) : 0;

        $statCards = [
            [''             , $totalSubmitted , 'fa-file-circle-check', 'bg-violet-100' , 'text-violet-700' , 'stat-card-submitted' , 'Submitted'     , $responseRate.'% response rate'],
            ['employed'     , $totalEmployed  , 'fa-user-tie'         , 'bg-emerald-100', 'text-emerald-700', 'stat-card-employed'  , 'Employed'      , $empRate.'% of total alumni'],
            ['self_employed', $totalSelf      , 'fa-store'            , 'bg-blue-100'   , 'text-blue-700'   , 'stat-card-self-emp'  , 'Self-Employed' , $selfRate.'% of total alumni'],
            ['unemployed'   , $totalUnemployed,'fa-circle-pause'      , 'bg-amber-100'  , 'text-amber-700'  , 'stat-card-unemployed', 'Unemployed'    , $unempRate.'% of submitted'],
            ['no_record'    , $totalNotFilled, 'fa-circle-question'   , 'bg-gray-100'   , 'text-[#333333]'  , 'stat-card-nofill'    , 'No Record'     , $nfRate.'% of total alumni'],
        ];
    @endphp
    <div class="stat-cards-grid flex gap-3 flex-wrap xl:flex-nowrap">
        @foreach($statCards as [$filter, $count, $icon, $iconBg, $iconColor, $hoverClass, $label, $rate])
        @php
            // "Submitted" doesn't map to a single employment_status on the
            // Alumni Records page (it means "has any record at all"), so
            // it stays as the local detail modal. The other four link
            // straight out to Alumni Records, pre-filtered by status —
            // and by the current Batch Year range here, if set — matching
            // how the main Dashboard's legend rows do this. Sent as
            // batch_from/batch_to (Alumni Records reads either param
            // individually, so a single-ended range still filters there).
            $alumniUrl = $filter !== ''
                ? route('registrar.alumni', array_filter([
                    'employment_status' => $filter,
                    'batch_from'        => $filterBatchFrom,
                    'batch_to'          => $filterBatchTo,
                  ]))
                : null;
        @endphp
        @if($alumniUrl)
        <a href="{{ $alumniUrl }}"
             data-tip="View {{ $label }} in Alumni Records"
             class="group relative bg-white rounded-2xl p-3 sm:p-4 flex items-center gap-3
                    shadow-sm cursor-pointer flex-1 min-w-[190px] stat-card {{ $hoverClass }}">
            <div class="w-9 h-9 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shrink-0 {{ $iconBg }}">
                <i class="fa-solid {{ $icon }} {{ $iconColor }}" style="font-size:.9rem;"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xl sm:text-2xl font-black leading-none text-[#111111] stat-number-anim">{{ number_format($count) }}</p>
                <p class="text-sm sm:text-base font-bold text-[#111111] mt-1">{{ $label }}</p>
                <p class="text-xs sm:text-sm font-semibold mt-0.5 {{ $iconColor }}">{{ $rate }}</p>
            </div>
            <div class="shrink-0 hidden sm:block">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg {{ $iconBg }} opacity-60
                             group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-arrow-right {{ $iconColor }}" style="font-size:.6rem;"></i>
                </span>
            </div>
        </a>
        @else
        <div wire:click="openModal('{{ $filter }}')"
             data-tip="View {{ $label }}"
             class="group relative bg-white rounded-2xl p-3 sm:p-4 flex items-center gap-3
                    shadow-sm cursor-pointer flex-1 min-w-[190px] stat-card {{ $hoverClass }}">
            <div class="w-9 h-9 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shrink-0 {{ $iconBg }}">
                <i class="fa-solid {{ $icon }} {{ $iconColor }}" style="font-size:.9rem;"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xl sm:text-2xl font-black leading-none text-[#111111] stat-number-anim">{{ number_format($count) }}</p>
                <p class="text-sm sm:text-base font-bold text-[#111111] mt-1">{{ $label }}</p>
                <p class="text-xs sm:text-sm font-semibold mt-0.5 {{ $iconColor }}">{{ $rate }}</p>
            </div>
            <div class="shrink-0 hidden sm:block">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg {{ $iconBg }} opacity-60
                             group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-arrow-right {{ $iconColor }}" style="font-size:.6rem;"></i>
                </span>
            </div>
        </div>
        @endif
        @endforeach
    </div>

    {{-- ── CHARTS ROW 1 ── --}}
    <div id="emp-charts-row-1" class="charts-row-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" style="height:300px;">

        {{-- Status Donut --}}
        <div onclick="empOpenModal('','',null)"
             data-tip="View All Employment Records"
             class="bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                    transition-all cursor-pointer flex flex-col overflow-hidden">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center gap-2 shrink-0">
                <span class="w-2 h-2 rounded-full bg-[#10b981] shrink-0"></span>
                <div class="flex flex-col min-w-0">
                    <span class="text-sm font-bold text-[#111111] uppercase tracking-wide leading-tight">Employment Status</span>
                    <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5">Overall breakdown of alumni job status</span>
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
                    <span class="text-sm font-bold text-[#111111] uppercase tracking-wide leading-tight">Work Location</span>
                    <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5">Where working alumni are based</span>
                </div>
                <span class="text-xs font-bold text-[#7a3f91] bg-[#f0e6f8] px-2 py-0.5 rounded-full shrink-0">{{ number_format($locTotal) }} working</span>
            </div>
            <div class="flex-1 flex flex-col justify-center px-4 sm:px-5 py-3 gap-3">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#7a3f91;">{{ number_format($totalLocal) }}</p>
                        <p class="text-sm font-bold text-[#111111] mt-1">Local / PH</p>
                        <p class="text-sm font-black mt-0.5" style="color:#7a3f91;">{{ $localPct }}%</p>
                    </div>
                    <div class="text-xs font-semibold text-[#333333] self-center pb-4">-</div>
                    <div class="text-right">
                        <p class="text-2xl sm:text-3xl font-black leading-none" style="color:#c084fc;">{{ number_format($totalAbroad) }}</p>
                        <p class="text-sm font-bold text-[#111111] mt-1">Abroad / OFW</p>
                        <p class="text-sm font-black mt-0.5" style="color:#c084fc;">{{ $abroadPct }}%</p>
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
                            class="flex-1 py-[7px] rounded-xl text-sm font-bold border border-[#7a3f91]/20
                                   bg-[#F9F7FC] text-[#7a3f91] hover:bg-[#7a3f91] hover:text-white hover:border-[#7a3f91]
                                   transition-all duration-150 cursor-pointer">
                        View Local
                    </button>
                    <button onclick="empOpenModal('abroad','',null)"
                            data-tip="View OFW / Abroad"
                            class="flex-1 py-[7px] rounded-xl text-sm font-bold border border-purple-200
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
                    <span class="text-sm font-bold text-[#111111] uppercase tracking-wide leading-tight">Job Relevance</span>
                    <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5">Alumni whose jobs match their program</span>
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
                    transition-all flex flex-col overflow-hidden group/topprog"
             x-data="{ viewAll:false }">
            <div class="px-3.5 py-2 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center justify-between gap-2 shrink-0">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0"></span>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-bold text-[#111111] uppercase tracking-wide leading-tight">Top Programs</span>
                        <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5">
                            @if(count($filterCourse) === 0)
                                Top 3 programs by employed alumni
                            @elseif(count($filterCourse) === 1)
                                {{ $filterCourse[0] }} — ranking among all programs
                            @else
                                {{ count($filterCourse) }} selected programs — ranked against each other
                            @endif
                        </span>
                    </div>
                </div>
                <button type="button" @click="viewAll=true"
                        class="text-xs font-bold shrink-0 px-2 py-1 rounded-lg border border-transparent
                               hover:bg-white hover:border-[#E8E0F0] transition-all duration-150"
                        style="color:#7A3F91;">
                    View All <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
                </button>
            </div>

            @if(count($filterCourse) === 0)
                <div class="flex-1 min-h-0 p-2" wire:ignore wire:key="emp-top-programs-chart">
                    <canvas id="chartCourse" style="max-height:100%;width:100%;"></canvas>
                </div>
            @elseif(count($filterCourse) === 1)
                @php
                    $singleCourse = $filterCourse[0];
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
                {{-- wire:click / data-tip / cursor-pointer live ONLY on the
                     ranked state below — the no-rank empty state has
                     nothing to open and nothing to hover, so it must never
                     look or behave clickable. --}}
                @if($courseRank && $rankTier)
                <div wire:click="openModal('employed_all','{{ $singleCourse }}',null)"
                     wire:key="emp-top-programs-rank"
                     class="flex-1 min-h-0 flex flex-col items-center justify-center gap-1.5 p-3 cursor-pointer">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center font-black text-xl leading-none"
                             style="background:{{ $rankTier['bg'] }}; color:{{ $rankTier['text'] }}; border:2px solid {{ $rankTier['ring'] }};">
                            #{{ $courseRank }}
                        </div>
                        <p class="text-sm font-bold text-[#111111] mt-1">out of {{ $courseRankTotal }} program(s)</p>
                        <span class="text-[11px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
                              style="background:{{ $rankTier['bg'] }}; color:{{ $rankTier['text'] }};">
                            {{ $rankTier['label'] }}
                        </span>
                        <p class="text-sm font-semibold mt-0.5" style="color:#7a3f91;">{{ number_format($courseRankCount) }} working alumni</p>
                </div>
                @else
                <div wire:key="emp-top-programs-norank"
                     class="flex-1 min-h-0 flex flex-col items-center justify-center gap-1.5 p-3">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-chart-simple" style="color:#c89de0;font-size:.9rem;"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] text-center mt-1">"{{ $singleCourse }}" isn't in the program catalog for this scope.</p>
                </div>
                @endif

            @else
                {{-- 2+ Programs selected — mini-ranking board of just the
                     selected programs (their rank against each other only,
                     not the full catalog). Same visual language as the
                     rank card list — badge, code, working count — but as
                     a compact stacked list instead of one big number. --}}
                <div wire:key="emp-top-programs-multi" class="flex-1 min-h-0 overflow-y-auto px-2.5 py-1.5 scroll-c">
                    @forelse(json_decode($topProgramsSelectedData, true) ?? [] as $row)
                    <div wire:click="openModal('employed_all','{{ $row['code'] }}',null)"
                         class="flex items-center gap-2.5 py-1.5 px-1.5 rounded-lg hover:bg-[#F9F7FC] transition-colors cursor-pointer">
                        <div class="w-7 h-7 rounded-lg flex items-center justify-center font-black text-[11px] shrink-0"
                             style="background:{{ $row['rank'] === 1 ? '#ECFDF5' : ($row['rank'] === 2 ? '#FFFBEB' : '#F9F7FC') }};
                                    color:{{ $row['rank'] === 1 ? '#059669' : ($row['rank'] === 2 ? '#D97706' : '#7A3F91') }};">
                            #{{ $row['rank'] }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-[#111111] leading-tight">{{ $row['code'] }}</p>
                        </div>
                        <span class="text-xs font-semibold shrink-0" style="color:#7a3f91;">{{ number_format($row['working']) }} working</span>
                    </div>
                    @empty
                    <div class="flex-1 min-h-0 flex flex-col items-center justify-center gap-1.5 p-3">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-chart-simple" style="color:#c89de0;font-size:.9rem;"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] text-center mt-1">None of the selected programs are in the catalog for this scope.</p>
                    </div>
                    @endforelse
                </div>
            @endif

            {{-- ── "View All" — full program ranking, badge #1/#2/#3, no
                 filter needed (always shows every program regardless of
                 the current Program filter, Batch-scoped only, same data
                 as topProgramsFullData computed server-side). Pure Alpine
                 overlay — no Livewire round-trip, so it opens instantly. ── --}}
            {{-- ── Mobile: TRUE fullscreen (no backdrop gap, no rounded
                 corners, no max-width/height caps) below the `sm` (640px)
                 breakpoint. Desktop/tablet: the original centered dialog,
                 unchanged, from `sm:` up. Only the outer wrapper and card
                 sizing classes change here — internal content/list markup
                 is untouched. ── --}}
            <div x-show="viewAll"
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="fixed inset-0 z-[9998] flex items-end sm:items-center justify-center p-0 sm:p-4"
                 style="background:rgba(17,17,17,.45);display:none;"
                 @click.self="viewAll=false" @keydown.escape.window="viewAll=false"
                 x-data="topProgramsAllList()"
                 x-init="init()"
                 x-effect="if (viewAll) refresh()">
                <div x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                     class="bg-white shadow-2xl w-full h-full sm:h-auto sm:max-w-md sm:max-h-[80vh]
                            rounded-none sm:rounded-2xl flex flex-col overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 shrink-0"
                         style="background:#7A3F91;padding-top:max(0.875rem, env(safe-area-inset-top));">
                        <div class="min-w-0">
                            <h3 class="text-white font-bold text-sm leading-tight">All Programs — Full Ranking</h3>
                            <p class="text-white/60 text-xs mt-0.5">By employed + self-employed alumni</p>
                        </div>
                        <button type="button" @click="closing=true; $nextTick(() => setTimeout(() => { viewAll=false; closing=false; }, 250))"
                                :disabled="closing"
                                class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0
                                   bg-white/[.12] border border-white/20 text-white hover:bg-white/[.22] transition-colors disabled:opacity-70">
                            <i class="fas fa-spinner animate-spin text-sm" x-show="closing" style="display:none;"></i>
                            <i class="fas fa-xmark text-sm" x-show="!closing"></i>
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto divide-y divide-gray-100" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                        <template x-if="list.length === 0">
                            <p class="text-sm text-[#333333] text-center py-10">No program data available for this scope.</p>
                        </template>
                        <template x-for="p in list" :key="p.code">
                            <div class="flex items-center gap-3 px-5 py-3 hover:bg-[#F5F0FA] transition-colors">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center font-black text-xs shrink-0"
                                     :style="badgeStyle(p.rank)">
                                    <span x-show="p.rank > 3" x-text="'#'+p.rank"></span>
                                    <span x-show="p.rank <= 3" x-text="medal(p.rank)" style="font-size:1rem;line-height:1;"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-[#111111]" x-text="p.code"></p>
                                    <p class="text-xs text-[#333333] mt-0.5">
                                        <span class="font-semibold" :style="p.working>0 ? 'color:#059669;' : 'color:#9CA3AF;'" x-text="p.working + ' working'"></span>
                                        <span class="text-[#999999]"> · </span>
                                        <span x-text="p.total + ' total alumni'"></span>
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="px-5 py-2.5 border-t border-[#E8E0F0] bg-[#F9F7FC] text-xs text-[#333333] text-center shrink-0"
                         style="padding-bottom:max(0.625rem, env(safe-area-inset-bottom));">
                        <span x-text="list.length"></span> program(s) ranked
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function topProgramsAllList() {
            return {
                list: [],
                closing: false,
                // Reads whatever is CURRENTLY in the data bridge. Called both
                // on first mount (init) and every time the modal is opened
                // (x-effect watching viewAll in the markup) — x-init only
                // ever runs once at page load, which is why this modal used
                // to keep showing whatever Program/Batch scope was active
                // when the page first loaded, even after switching filters.
                refresh() {
                    var el = document.getElementById('__emp_chart_data');
                    if (!el) { this.list = []; return; }
                    try { this.list = JSON.parse(el.getAttribute('data-top-programs-full') || '[]'); }
                    catch (e) { this.list = []; }
                },
                init() {
                    this.refresh();
                },
                medal(rank) {
                    if (rank === 1) return '🥇';
                    if (rank === 2) return '🥈';
                    if (rank === 3) return '🥉';
                    return '';
                },
                badgeStyle(rank) {
                    if (rank === 1) return 'background:#ECFDF5;color:#059669;border:2px solid #6ee7b7;';
                    if (rank === 2) return 'background:#EFF6FF;color:#2563EB;border:2px solid #93c5fd;';
                    if (rank === 3) return 'background:#FFFBEB;color:#D97706;border:2px solid #fcd34d;';
                    return 'background:#F3F4F6;color:#374151;border:2px solid #E5E7EB;';
                },
            };
        }
    </script>

    {{-- ── CHARTS ROW 2: Stacked Batch Bar ── --}}
    <div class="chart-batch-wrap bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                transition-all flex flex-col overflow-hidden" style="height:280px;">
        <div class="px-3 sm:px-[14px] py-2 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
            <div class="flex items-center gap-[7px] min-w-0">
                <div class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></div>
                <div class="flex flex-col min-w-0">
                    <span class="text-sm sm:text-base font-bold text-[#111111] uppercase tracking-[.04em] leading-tight">Employment Breakdown by Batch Year</span>
                    <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5 hidden sm:block">Number of employed, self-employed &amp; unemployed per batch</span>
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
                <span id="batchPageInfo" class="text-sm font-semibold text-[#333333] whitespace-nowrap"></span>
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

    {{-- ── CHARTS ROW 3: Employment Rate Trend Line — now the sole home for
         "Employment Rate" (the Program Breakdown table's rate column/bar
         was removed to avoid duplicating this exact metric), so it's given
         extra height + bolder header treatment to carry that weight visually. ── --}}
    <div class="chart-trend-wrap bg-white border border-[#E8E0F0] rounded-2xl shadow-sm hover:shadow-md hover:border-[#c4b5fd]
                transition-all flex flex-col overflow-hidden" style="height:340px;">
        <div class="px-3 sm:px-4 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full bg-[#7a3f91] shrink-0"></div>
                <div class="flex flex-col min-w-0">
                    <span class="text-sm sm:text-base font-bold text-[#111111] uppercase tracking-[.04em] leading-tight">Employment Rate Trend per Batch Year</span>
                    <span class="text-xs text-[#333333] font-medium leading-tight mt-0.5 hidden sm:block">% of alumni (employed + self-employed) out of total per batch</span>
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
                <span id="trendPageInfo" class="text-sm font-semibold text-[#333333] whitespace-nowrap"></span>
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
                    <p class="text-sm sm:text-base font-bold leading-tight text-[#111111]">Program Breakdown</p>
                    <p class="text-xs text-[#333333] hidden sm:block">Alumni counts per program — click any row to view records</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-[#F9F7FC] text-[#7a3f91] border border-[#E8E0F0] shrink-0">
                {{ count($this->courseAnalytics) }} programs
            </span>
        </div>
        <div class="course-table-wrap max-h-[450px] overflow-y-auto overflow-x-auto" style="scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
            <table class="w-full border-collapse" style="min-width:520px;">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-[#f5f0fa] border-b-2 border-[#E8E0F0]">
                        <th class="pl-3 sm:pl-4 pr-3 py-2.5 text-left text-xs font-bold uppercase tracking-wider text-[#111111]">Program</th>
                        <th class="px-3 py-2.5 text-center text-xs font-bold uppercase tracking-wider text-[#111111]">Total</th>
                        <th class="px-3 py-2.5 text-center text-xs font-bold uppercase tracking-wider text-emerald-700">Employed</th>
                        <th class="px-3 py-2.5 text-center text-xs font-bold uppercase tracking-wider text-blue-700">Self-Employed</th>
                        <th class="px-3 py-2.5 text-center text-xs font-bold uppercase tracking-wider text-amber-700">Unemployed</th>
                        <th class="px-3 sm:px-4 py-2.5 text-center text-xs font-bold uppercase tracking-wider text-[#333333]">No Record</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->courseAnalytics as $cr)
                    @php $courseFullName = $this->courseMap[$cr->course_code ?? ''] ?? null; @endphp
                    <tr class="transition-colors">
                        <td class="pl-3 sm:pl-4 pr-3 py-3">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-sm font-bold bg-[#F9F7FC] text-[#7a3f91]">
                                <i class="fas fa-graduation-cap text-[11px]"></i>
                                {{ $cr->course_code ?? '—' }}
                            </span>
                            @if($courseFullName)
                                <p class="text-xs mt-0.5 font-semibold text-[#111111] leading-tight hidden sm:block">{{ $courseFullName }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="text-sm font-bold text-[#111111]">{{ number_format($cr->total) }}</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="text-sm font-semibold text-emerald-700">{{ number_format($cr->employed) }}</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="text-sm font-semibold text-blue-700">{{ number_format($cr->self_employed) }}</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="text-sm font-semibold text-amber-700">{{ number_format($cr->unemployed) }}</span>
                        </td>
                        <td class="px-3 sm:px-4 py-3 text-center">
                            <span class="text-sm font-semibold text-[#333333]">{{ number_format($cr->not_filled) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-10 text-center">
                            <p class="text-base font-semibold text-[#333333]">No program data available</p>
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
</div>

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

        <button wire:click="closeModal" wire:loading.attr="disabled" wire:target="closeModal" class="emp-close-btn shrink-0 ml-3">
            <span class="emp-close-tip">Close</span>
            <span wire:loading wire:target="closeModal">
                <i class="fas fa-spinner animate-spin text-sm"></i>
            </span>
            <i class="fas fa-xmark text-sm" wire:loading.remove wire:target="closeModal"></i>
        </button>
    </div>

    {{-- ── In-modal search + filters — merged into a single row: SEARCH
         box, result count, then FILTERS (Batch Year + Program Code +
         Reset), scoped to this modal's own modalSearch/modalBatch/
         modalCourse. Matches wire:loading dim + progress-bar treatment
         used elsewhere, and highlights matches in blue (mark.ar-hl)
         like Alumni Records. Single row, scrolls horizontally instead
         of wrapping if space is tight. ── --}}
    <div class="emp-modal-filter-bar px-4 sm:px-6 lg:px-10 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center gap-2 flex-wrap shrink-0"
         wire:loading.class="opacity-60" wire:target="modalSearch,modalBatch,modalCourse,clearModalFilters">
        <span class="text-xs font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">
            <i class="fas fa-magnifying-glass sm:mr-1"></i><span class="hidden sm:inline">SEARCH</span>
        </span>
        <div class="h-5 w-px bg-[#E8E0F0] shrink-0 hidden sm:block"></div>
        <div class="relative flex-1 min-w-[180px] max-w-xs shrink-0" wire:ignore
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

        <div class="h-5 w-px bg-[#E8E0F0] shrink-0"></div>
        <span class="text-xs font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">FILTERS</span>

        {{-- Batch Year --}}
        <div class="ar-dropdown shrink-0"
             x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('modalBatch', val===''?null:val); this.close(); } }"
             @click.outside="close()" wire:key="emp-modal-batch-dropdown">
            <button type="button" @click.stop="toggle()" :class="{ 'has-value':$wire.modalBatch!==null,'open':open }" class="ar-dropdown-trigger">
                <i class="fas fa-calendar-days" style="font-size:11px;opacity:.7;"></i>
                <span>@if($modalBatch){{ $modalBatch }}@else All Batch Years @endif</span>
                <i class="fas fa-chevron-down ar-chevron"></i>
            </button>
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="ar-dropdown-menu" style="display:none;" @click.stop>
                <button type="button" @click.stop="select('')" :class="{'active':$wire.modalBatch===null}" class="ar-dropdown-item">All Batch Years</button>
                @foreach($this->batchYears as $year)
                <button type="button" @click.stop="select('{{ $year }}')" :class="{'active':$wire.modalBatch=='{{ $year }}'}" class="ar-dropdown-item">{{ $year }}</button>
                @endforeach
            </div>
        </div>

        {{-- Program Code --}}
        <div class="ar-dropdown shrink-0"
             x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('modalCourse',val); this.close(); } }"
             @click.outside="close()" wire:key="emp-modal-course-dropdown">
            <button type="button" @click.stop="toggle()" :class="{ 'has-value':$wire.modalCourse!=='','open':open }" class="ar-dropdown-trigger">
                <i class="fas fa-graduation-cap" style="font-size:11px;opacity:.7;"></i>
                <span>@if($modalCourse){{ $modalCourse }}@else All Program Codes @endif</span>
                <i class="fas fa-chevron-down ar-chevron"></i>
            </button>
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="ar-dropdown-menu" style="display:none;" @click.stop>
                <button type="button" @click.stop="select('')" :class="{'active':$wire.modalCourse===''}" class="ar-dropdown-item">All Program Codes</button>
                @foreach($this->courseMap as $code => $name)
                <button type="button" @click.stop="select('{{ $code }}')" :class="{'active':$wire.modalCourse==='{{ $code }}'}" class="ar-dropdown-item">{{ $name }}</button>
                @endforeach
            </div>
        </div>

        {{-- Reset — always visible now, not conditional on an active filter --}}
        <button wire:click="clearModalFilters" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="clearModalFilters"
                type="button"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-95 shrink-0 disabled:pointer-events-none">
            <span wire:loading wire:target="clearModalFilters">
                <i class="fas fa-spinner animate-spin text-sm"></i>
            </span>
            <i class="fas fa-rotate-left text-sm" wire:loading.remove wire:target="clearModalFilters"></i>
            <span class="hidden sm:inline">Reset</span>
        </button>
    </div>

    {{-- Table with center overlay spinner — same treatment as the
         dashboard and Alumni Records. --}}
    <div class="modal-table-wrap relative flex-1 overflow-y-auto overflow-x-auto min-h-0"
         wire:loading.class="opacity-40 pointer-events-none" wire:target="modalSearch,modalBatch,modalCourse,clearModalFilters" style="transition:opacity .2s ease;">

        <div class="absolute inset-0 z-20 flex items-center justify-center pointer-events-none"
             wire:loading wire:target="modalSearch,modalBatch,modalCourse,clearModalFilters">
            <div class="flex items-center justify-center px-6 py-5 rounded-2xl"
                 style="background:rgba(255,255,255,0.92); box-shadow:0 8px 24px -6px rgba(90,34,112,0.22);">
                <i class="fas fa-spinner fa-spin" style="font-size:34px; color:#7A3F91;"></i>
            </div>
        </div>
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
            exporting: false,
            exportingType: '',
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
             * of the dashboard (wire.filterCourse / wire.filterBatchFrom / wire.filterBatchTo).
             * This is independent from the drill-down detail modal.
             */
            async doExport(type, wire) {
                // Guard against double-clicks while an export (of any type)
                // is already underway.
                if (this.exporting) return;

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

                this.exporting = true;
                this.exportingType = type;

                // ── "Please wait" info toast — mirrors Alumni Records,
                //    shown the instant the export request is dispatched. ──
                var label = type === 'excel' ? 'Excel file' : type === 'print' ? 'print view' : 'PDF';
                window.dispatchEvent(new CustomEvent('flash-message', {
                    detail: { type: 'info', message: 'Generating your ' + label + '… this only takes a moment.' }
                }));

                var self = this;

                // filterCourse is now an array (multi-select) — join into a
                // comma-separated list for the export endpoint, matching
                // the same "program" param it already expects. Batch range
                // is all-or-nothing on the dashboard now too: only send
                // batch_from/batch_to when BOTH are set, so the exported
                // report can never end up scoped to a half-picked range
                // that was never actually applied to what's on screen.
                var hasProgram = wire && Array.isArray(wire.filterCourse) && wire.filterCourse.length > 0;
                var hasFullRange = wire && wire.filterBatchFrom && wire.filterBatchTo;

                var params = new URLSearchParams({
                    type:       type,
                    program:    hasProgram   ? wire.filterCourse.join(',') : '',
                    batch_from: hasFullRange ? wire.filterBatchFrom        : '',
                    batch_to:   hasFullRange ? wire.filterBatchTo          : '',
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
                            self.printLock  = false;
                            self.exporting  = false;
                            self.exportingType = '';
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
                        var filename = type === 'excel' ? 'employment-tracking-report.xlsx' : 'employment-tracking-report.pdf';
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

                        this.exporting = false;
                        this.exportingType = '';
                    }
                } catch (e) {
                    if (type === 'print') this.printLock = false;
                    this.exporting = false;
                    this.exportingType = '';
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

    // ── Filter dropdown coordination store ──────────────────────────────
    // Tracks which single filter dropdown (Batch Year / Program Code) is
    // currently open, so opening one automatically closes the other
    // instead of both being able to stay open/stacked at the same time.
    // Mirrors Alumni Records' $store.arFilters exactly.
    function registerEmpFiltersStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('empFilters')) return;

        window.Alpine.store('empFilters', {
            openKey: '',
            isOpen(key) { return this.openKey === key; },
            toggle(key) { this.openKey = (this.openKey === key) ? '' : key; },
            close(key) { if (this.openKey === key) this.openKey = ''; },
            closeAll() { this.openKey = ''; },
        });
    }

    window.__empEnsureFiltersStore = registerEmpFiltersStore;
    if (window.Alpine) registerEmpFiltersStore();
    document.addEventListener('alpine:init', registerEmpFiltersStore);
    document.addEventListener('livewire:init', registerEmpFiltersStore);
    document.addEventListener('livewire:navigated', registerEmpFiltersStore);

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
            // If the element we're tracking got swapped out from under the
            // cursor (e.g. Livewire morphdom replaced the Top Programs
            // rank card / no-rank / multi-select-list block after a
            // Program filter change), it's no longer attached to the
            // document. `mouseout` never fires for a node that was
            // removed rather than actually left, so without this check
            // the tooltip kept showing the OLD block's text — a stale
            // "BSIT — 12 working alumni" tip hanging over content that no
            // longer has anything to do with BSIT. Catch that here on the
            // very next mousemove instead of waiting for a mouseout that
            // will never come.
            if (currentTarget && !document.contains(currentTarget)) {
                hideTip();
                return;
            }
            if (currentTarget) moveTip(e);
        }, true);

        document.addEventListener('mouseout', function(e) {
            if (currentTarget && !currentTarget.contains(e.relatedTarget)) {
                hideTip();
            }
        }, true);

        document.addEventListener('scroll', hideTip, true);
        document.addEventListener('touchstart', hideTip, true);

        // ── Belt-and-suspenders: also hide immediately whenever the
        // dashboard's own "data changed, rebuild everything" signal fires.
        // This is the exact moment DOM under the tooltip can be swapped
        // (Top Programs card branch changes shape), so don't wait for the
        // next mousemove poll above — clear it the instant refresh starts.
        function hookTipRefreshHide() {
            if (!window.Livewire) return;
            Livewire.on('emp-charts-refresh', hideTip);
        }
        if (window.Livewire) { hookTipRefreshHide(); }
        else { document.addEventListener('livewire:initialized', hookTipRefreshHide); }
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
                        labels: { font:{size:12,weight:'700'}, color:'#111111', usePointStyle:true, pointStyleWidth:9, boxHeight:9, padding:14 },
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
                    x: { stacked:true, grid:{display:false}, ticks:{font:{size:13,weight:'700'},color:'#111111'} },
                    y: { stacked:true, grid:{color:'#f3f4f6'}, ticks:{font:{size:13,weight:'700'},color:'#333333',precision:0}, beginAtZero:true },
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
                    x:{grid:{display:false},ticks:{font:{size:13,weight:'700'},color:'#111111'}},
                    y:{
                        grid:{color:'#f3f4f6'},
                        ticks:{font:{size:13,weight:'700'},color:'#333333',callback:function(val){return val+'%';}},
                        min:0,max:100,
                    },
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
                    // Fixed percentages (instead of Chart.js's auto default)
                    // so all 3 bars stay clearly visible/evenly spaced inside
                    // this card's fixed 300px row height — without this, a
                    // 3-category horizontal bar in a short container can
                    // render its 3rd bar so thin it looks missing.
                    categoryPercentage: 0.7,
                    barPercentage: 0.85,
                }],
            },
            options: {
                indexAxis:'y',
                responsive:true, maintainAspectRatio:false,
                layout:{ padding:{ top:2, bottom:2 } },
                plugins: {
                    legend:{display:false},
                    tooltip:{ enabled:false },
                },
                scales: {
                    x:{grid:{color:'#f3f4f6'},ticks:{display:false},beginAtZero:true},
                    y:{grid:{display:false},ticks:{font:{size:11,weight:'600'},color:'#111111',autoSkip:false}},
                },
                onHover:function(event){
                    if (event && event.native && event.native.target) {
                        event.native.target.style.cursor = 'default';
                    }
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

        // ⚠️ REMOVED: the old generic Livewire.hook('commit', ...) fallback
        // that also called initAllCharts() on every single commit. Having
        // BOTH that hook and the 'emp-charts-refresh' listener above meant
        // TWO re-renders raced each other after every filter change. Since
        // buildTrendLine()/buildBatchBar() write to the shared allTrendData/
        // allBatchData/trendPageIndex/batchPageIndex globals, whichever of
        // the two races finished LAST silently won — and depending on exact
        // timing that could be the one holding the OLDER data, which is
        // exactly what caused the Employment Rate Trend chart to keep
        // showing old batch-year labels (e.g. still 2023/2025/2026) right
        // after switching the Batch Year filter to 2027, even though the
        // stat cards above it (plain server-rendered Blade) already updated
        // correctly. The explicit 'emp-charts-refresh' dispatch is fired by
        // PHP every time computeStats()+buildCharts() run, so it's a
        // complete, reliable, single source of truth — no fallback needed.
    });

})();
</script>

</div>n