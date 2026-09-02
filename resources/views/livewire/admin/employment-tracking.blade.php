{{-- resources/views/livewire/admin/employment-tracking.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component {

    // ── Global Filters ────────────────────────────────────────────────────────
    // (Removed: a page-wide $search here previously scoped the aggregate
    // stat cards/charts by name/student ID, which never matched anything
    // since those are category breakdowns, not lists of people. Search by
    // name/ID lives on modalSearch below instead, scoped to the actual
    // alumni list.)

    // Batch is a FROM/TO range (mirrors the organizer-facing Alumni
    // Employment table) instead of a single-value dropdown.
    public string $filterBatchFrom = '';
    public string $filterBatchTo   = '';

    /** Set while setSingleBatchYear()/clearFilterBatch()/setBatchRange()
     *  are writing to filterBatchFrom/filterBatchTo directly, so the
     *  updatedFilterBatchFrom()/updatedFilterBatchTo() hooks below don't
     *  ALSO fire and refresh a second time in the same request. */
    private bool $skipBatchHooks = false;

    public string $filterCollege  = '';

    // Programs is a MULTI-SELECT (array of checked course codes) instead
    // of a single-value dropdown, so several programs can be viewed
    // together — mirrors the organizer-facing Alumni Employment table.
    public array  $filterCourses  = [];

    public string $filterStatus  = '';

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
    public int $totalUnemployed = 0;    public int $totalNotFilled  = 0;
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
    public string $chartUnemployedData    = '{}';

    // ── Meta lists ────────────────────────────────────────────────────────────
    public array $batches  = [];
    public array $colleges = [];
    public array $courses  = [];

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

    /** Toggles a single program/course code in/out of the multi-select
     *  filter — bound directly to each checkbox item in the Programs
     *  dropdown. */
    public function toggleFilterCourse(string $code): void
    {
        if (in_array($code, $this->filterCourses, true)) {
            $this->filterCourses = array_values(array_diff($this->filterCourses, [$code]));
        } else {
            $this->filterCourses[] = $code;
        }
        $this->refreshAll();
    }

    /** "All Programs" inside the dropdown — clears the whole multi-select
     *  in one round-trip. */
    public function clearFilterCourses(): void
    {
        $this->filterCourses = [];
        $this->refreshAll();
    }

    /** "Select All" inside the dropdown — checks every program code. */
    public function selectAllFilterCourses(): void
    {
        $this->filterCourses = collect($this->courses)->pluck('code')->toArray();
        $this->refreshAll();
    }

    /** "Select all" under one college's group header, inside the merged
     *  Programs dropdown — adds every course code belonging to that
     *  college into the multi-select in one round-trip (existing picks
     *  from other colleges are kept). */
    public function selectCollegeCourses(string $college): void
    {
        $codesInCollege = collect($this->courses)
            ->where('college', $college)
            ->pluck('code')
            ->all();

        $this->filterCourses = array_values(array_unique(array_merge($this->filterCourses, $codesInCollege)));
        $this->refreshAll();
    }

    /** True only once BOTH ends of the batch range are set — a half-picked
     *  range (just From, or just To) never scopes the query. */
    private function batchRangeIsComplete(): bool
    {
        return $this->filterBatchFrom !== '' && $this->filterBatchTo !== '';
    }

    public function updatedFilterBatchFrom(): void
    {
        if ($this->skipBatchHooks) return;
        $this->normalizeBatchRange();
        // Don't fire a filter request yet if only "From" is picked — wait
        // until "To" is also set (or both cleared) so a half-picked range
        // never triggers a query, and picking From then To doesn't cause
        // two separate refreshes.
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo === '') {
            return;
        }
        $this->refreshAll();
    }

    public function updatedFilterBatchTo(): void
    {
        if ($this->skipBatchHooks) return;
        $this->normalizeBatchRange();
        if ($this->filterBatchTo !== '' && $this->filterBatchFrom === '') {
            return;
        }
        $this->refreshAll();
    }

    /** If "from" ends up later than "to" (or vice versa), swap them
     *  instead of silently returning zero rows. */
    private function normalizeBatchRange(): void
    {
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== ''
            && (int)$this->filterBatchFrom > (int)$this->filterBatchTo) {
            [$this->filterBatchFrom, $this->filterBatchTo] = [$this->filterBatchTo, $this->filterBatchFrom];
        }
    }

    /** Single-year quick pick from the default (non-range) Batch Year
     *  list — sets From and To to the same year in ONE round-trip
     *  instead of two separate $wire.set() calls. */
    public function setSingleBatchYear(string $year): void
    {
        $this->skipBatchHooks  = true;
        $this->filterBatchFrom = $year;
        $this->filterBatchTo   = $year;
        $this->skipBatchHooks  = false;
        $this->refreshAll();
    }

    /** "All Batch Years" — clears both ends of the range in one
     *  round-trip, same reasoning as setSingleBatchYear() above. */
    public function clearFilterBatch(): void
    {
        $this->skipBatchHooks  = true;
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
        $this->skipBatchHooks  = false;
        $this->refreshAll();
    }

    /** Applies a From–To range in ONE round-trip. Bound to the range
     *  picker's local Alpine state (not wire:model.live on the two
     *  lists) so picking "From" alone never touches the server at all —
     *  the request only fires once both ends are chosen. */
    public function setBatchRange(string $from, string $to): void
    {
        $this->skipBatchHooks  = true;
        $this->filterBatchFrom = $from;
        $this->filterBatchTo   = $to;
        $this->skipBatchHooks  = false;
        $this->normalizeBatchRange();
        $this->refreshAll();
    }

    /** College dropdown — narrows Programs down to that college's course
     *  codes (combined with filterCourses if any are also picked). */
    public function updatedFilterCollege(): void
    {
        $this->refreshAll();
    }

    /** Employment Status dropdown. */
    public function updatedFilterStatus(): void
    {
        $this->refreshAll();
    }

    public function resetFilters(): void
    {
        $this->filterBatchFrom  = '';
        $this->filterBatchTo    = '';
        $this->filterCollege    = '';
        $this->filterCourses    = [];
        $this->filterStatus     = '';
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
        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et_s', function ($j) {
                $j->on('a.id', '=', 'et_s.alumni_id')->whereNull('et_s.deleted_at');
            })
            ->whereNull('a.deleted_at');

        // NOTE: search is intentionally NOT applied here. baseQ() powers
        // the aggregate stat cards and charts (Employment Status, Work
        // Location, etc.) — those are breakdowns by category, not by
        // person, so a name/student-ID search has nothing to match
        // against them. Name/ID search only makes sense against an
        // actual list of individual alumni, which is what modalSearch
        // (the detail-modal list) is for.

        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $q->where('a.batch', '>=', $this->filterBatchFrom)
              ->where('a.batch', '<=', $this->filterBatchTo);
        }
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $q->whereIn('a.course_code', $codes);
        }
        if (!empty($this->filterCourses)) $q->whereIn('a.course_code', $this->filterCourses);

        if ($this->filterStatus === 'not_filled') {
            $q->whereNotExists(function ($sub) {
                $sub->from('employment_trackings as et_f')
                    ->whereColumn('et_f.alumni_id', 'a.id')
                    ->whereNull('et_f.deleted_at');
            });
        } elseif ($this->filterStatus !== '') {
            $status = $this->filterStatus;
            $q->whereExists(function ($sub) use ($status) {
                $sub->from('employment_trackings as et_f')
                    ->whereColumn('et_f.alumni_id', 'a.id')
                    ->whereNull('et_f.deleted_at')
                    ->where('et_f.employment_status', $status);
            });
        }

        return $q;
    }

    private function empQ(): \Illuminate\Database\Query\Builder
    {
        $q = (clone $this->baseQ())
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        if ($this->filterStatus !== '' && $this->filterStatus !== 'not_filled') {
            $q->where('et.employment_status', $this->filterStatus);
        }

        return $q;
    }

    // ── Helper: build display name from alumni row ────────────────────────────
    private function buildName(object $r): string
    {
        $mi = !empty($r->middle_initial) ? ' ' . strtoupper(substr(trim($r->middle_initial), 0, 1)) . '.' : '';
        $suffix = !empty($r->suffix) ? ', ' . $r->suffix : '';
        return $r->last_name . ', ' . $r->first_name . $mi . $suffix;
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
            'location' => [
                'local'  => 'Local Alumni',
                'abroad' => 'Abroad / OFW Alumni',
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

            if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
                $q->where('a.batch', '>=', $this->filterBatchFrom)
                  ->where('a.batch', '<=', $this->filterBatchTo);
            }
            if ($this->filterCollege) {
                $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
                $q->whereIn('a.course_code', $codes);
            }
            if (!empty($this->filterCourses)) $q->whereIn('a.course_code', $this->filterCourses);

            if ($this->filterStatus && $this->filterStatus !== 'not_filled') {
                $q->whereRaw('1 = 0');
            }

            if ($this->modalBatch)    $q->where('a.batch', $this->modalBatch);
            if ($this->modalSearch) {
                $s = '%' . $this->modalSearch . '%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('a.first_name',  'like', $s)
                       ->orWhere('a.last_name',  'like', $s)
                       ->orWhere('a.student_id', 'like', $s);
                });
            }

            $this->modalTotal = (clone $q)->count();
            $rows = $q->select(
                    'a.first_name', 'a.last_name', 'a.middle_initial', 'a.suffix',
                    'a.student_id', 'a.course_code', 'a.batch'
                )
                ->orderBy('a.last_name')->orderBy('a.first_name')->limit(100)->get();

            $this->modalAlumni = $rows->map(fn($r) => [
                'name'       => $this->buildName($r),
                'id_number'  => $r->student_id ?? '—',
                'course'     => $r->course_code ?? '—',
                'batch'      => $r->batch,
                'status'     => 'No Record',
                'status_key' => 'not_filled',
                'type'       => null,
                'company'    => null,
                'location'   => null,
                'relevance'  => null,
            ])->toArray();
            return;
        }

        $q = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');

        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $q->where('a.batch', '>=', $this->filterBatchFrom)
              ->where('a.batch', '<=', $this->filterBatchTo);
        }
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $q->whereIn('a.course_code', $codes);
        }
        if (!empty($this->filterCourses)) $q->whereIn('a.course_code', $this->filterCourses);

        if ($this->filterStatus === 'not_filled') {
            $q->whereRaw('1 = 0');
        } elseif ($this->filterStatus !== '') {
            $q->where('et.employment_status', $this->filterStatus);
        }

        if ($this->modalBatch)    $q->where('a.batch', $this->modalBatch);

        if ($this->modalFilterType === 'status') {
            $q->where('et.employment_status', $this->modalFilter);
        } elseif ($this->modalFilterType === 'relevance') {
            if ($this->modalFilter === 'yes_partial') {
                $q->whereIn('et.course_relevance', ['yes', 'partially']);
            } else {
                $q->where('et.course_relevance', $this->modalFilter);
            }
        } elseif ($this->modalFilterType === 'location') {
            $q->where('et.work_location', $this->modalFilter);
        }

        if ($this->modalSearch) {
            $s = '%' . $this->modalSearch . '%';
            $q->where(function ($sq) use ($s) {
                $sq->where('a.first_name',      'like', $s)
                   ->orWhere('a.last_name',      'like', $s)
                   ->orWhere('a.student_id',     'like', $s)
                   ->orWhere('et.company_name',  'like', $s);
            });
        }

        $this->modalTotal = (clone $q)->count();
        $rows = $q->select(
            'a.first_name', 'a.last_name', 'a.middle_initial', 'a.suffix',
            'a.student_id', 'a.course_code', 'a.batch',
            'et.employment_status', 'et.employment_type', 'et.company_name',
            'et.work_location', 'et.course_relevance'
        )->orderBy('a.last_name')->orderBy('a.first_name')->limit(100)->get();

        $sLabel = ['employed' => 'Employed', 'self_employed' => 'Self-Employed', 'unemployed' => 'Unemployed'];
        $tLabel = ['full_time' => 'Full-Time', 'part_time' => 'Part-Time', 'contractual' => 'Contractual',
                   'project_based' => 'Project-Based', 'internship' => 'Internship'];

        $this->modalAlumni = $rows->map(function ($r) use ($sLabel, $tLabel) {
            return [
                'name'       => $this->buildName($r),
                'id_number'  => $r->student_id ?? '—',
                'course'     => $r->course_code ?? '—',
                'batch'      => $r->batch,
                'status'     => $sLabel[$r->employment_status] ?? ucfirst($r->employment_status ?? ''),
                'status_key' => $r->employment_status ?? 'unknown',
                'type'       => $tLabel[$r->employment_type ?? ''] ?? null,
                'company'    => $r->company_name,
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
    }

    public function computeStats(): void
    {
        // baseQ() left-joins employment_trackings so downstream chart
        // queries can reach company_name/job_title where needed — count
        // distinct alumni rows so a stray duplicate tracking row can
        // never inflate this number.
        $this->totalAlumni     = (clone $this->baseQ())->distinct()->count('a.id');
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
            if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
                $base->where('a.batch', '>=', $this->filterBatchFrom)
                     ->where('a.batch', '<=', $this->filterBatchTo);
            }
            $total = (clone $base)->count();
            $emp   = DB::table('alumni as a')
                ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at')
                ->whereIn('a.course_code', $codes);
            if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
                $emp->where('a.batch', '>=', $this->filterBatchFrom)
                    ->where('a.batch', '<=', $this->filterBatchTo);
            }
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
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $courseQ->where('a.batch', '>=', $this->filterBatchFrom)
                    ->where('a.batch', '<=', $this->filterBatchTo);
        }
        if ($this->filterCollege) {
            $codes = DB::table('courses')->where('college', $this->filterCollege)->pluck('code');
            $courseQ->whereIn('a.course_code', $codes);
        }
        if (!empty($this->filterCourses)) $courseQ->whereIn('a.course_code', $this->filterCourses);
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

    }

    public function with(): array { return []; }
};
?>

{{-- Tiny keyframes — Tailwind has no utility for defining new @keyframes, everything else below is pure Tailwind --}}
<style>
@keyframes admFadeIn  { from { opacity:0; } to { opacity:1; } }
@keyframes admSlideUp { from { opacity:0; transform: translateY(20px) scale(.98); } to { opacity:1; transform:none; } }

/* ── Dropdown trigger (filters) ── */
.yb-adm-dd-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0.5rem 2.25rem 0.5rem 0.75rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    cursor: pointer; white-space: nowrap;
    transition: border-color .15s, box-shadow .15s;
    outline: none; user-select: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat; background-size: 1.25em 1.25em;
}
.yb-adm-dd-btn:hover  { border-color: #c4b5d4; }
.yb-adm-dd-btn.active { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); color: #7a3f91; }
.yb-adm-dd-btn:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* Plain button (no dropdown chevron) — used for Reset */
.yb-adm-plain-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0.5rem 0.75rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    cursor: pointer; white-space: nowrap;
    transition: border-color .15s, box-shadow .15s;
    outline: none; user-select: none;
}
.yb-adm-plain-btn:hover { border-color: #c4b5d4; }
.yb-adm-plain-btn:focus { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Generate Reports button (copied styling from Registrar Employment
     Tracking / Alumni Records) ──────────────────────────────────────── */
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

/* ── Dropdown panel (filters) ── */
.yb-adm-dd-panel {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 224px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 600; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.yb-adm-dd-panel::-webkit-scrollbar       { width: 4px; }
.yb-adm-dd-panel::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 9999px; }
.yb-adm-dd-item {
    display: block; width: 100%; padding: 6px 12px;
    border-radius: 7px; text-align: left;
    font-size: 12px; font-weight: 600; color: #333333;
    background: transparent; border: none; cursor: pointer;
    white-space: nowrap; transition: background .12s, color .12s;
}
.yb-adm-dd-item:hover { background: #F5F0FA; color: #7A3F91; }
.yb-adm-dd-item.sel   { background: #F0E6F8; color: #7A3F91; }
</style>

@php
    $inputBase     = 'border border-[#E8E0F0] rounded-lg text-sm font-medium bg-white text-[#333333] px-3 py-2 outline-none transition-colors duration-150 placeholder:text-[#999999] placeholder:font-normal hover:border-[#c4b5d4] focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10';
    $selectBase    = $inputBase . ' appearance-none cursor-pointer pr-9 bg-no-repeat max-w-[190px] truncate';
    $activeSelect  = 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold';
    $selectArrow   = "background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-size:1.25em 1.25em;";

    $statCard      = 'bg-white border border-[#E8E0F0] rounded-xl px-3.5 py-3 flex flex-row items-center gap-3 transition-all duration-150';
    $statIcon      = 'w-[46px] h-[46px] rounded-xl flex items-center justify-center flex-shrink-0';

    $chartCard     = 'bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden shadow-[0_1px_4px_rgba(0,0,0,0.04)]';
    $chartHead     = 'px-[18px] py-3 border-b border-[#E8E0F0] bg-[#F9F7FC] flex items-center justify-between gap-2';
    $chartDot      = 'w-2 h-2 rounded-full bg-[#7a3f91] flex-shrink-0';
    $chartTtl      = 'text-[0.8rem] font-bold uppercase tracking-[0.06em] text-[#333333]';
    $chartSub      = 'text-[0.72rem] text-[#666666] font-medium';

    $rankRow       = 'flex items-center gap-2.5 py-[9px] border-b border-[#f5f5f5] last:border-b-0';
    $progTrack     = 'h-[5px] rounded-full bg-[#ede9fe] overflow-hidden mt-[5px]';
    $progFill      = 'h-full rounded-full transition-[width] duration-500';

    $statusPill    = 'inline-flex items-center gap-1 px-[9px] py-[3px] rounded-full text-[0.71rem] font-semibold border whitespace-nowrap';

    $thBase        = 'px-3.5 py-2.5 text-left text-[0.72rem] font-semibold uppercase tracking-[0.06em] text-[#666666] whitespace-nowrap first:pl-6 last:pr-6 last:text-right';
    $tdBase        = 'px-3.5 py-[11px] align-middle first:pl-6 last:pr-6';

    $wireFade      = 'opacity-40 pointer-events-none transition-opacity duration-200';
@endphp

<div class="flex flex-col h-[90vh] overflow-hidden">

{{-- ══ MAIN LAYOUT ══
     Header + filter bar are OUTSIDE the scrolling area (shrink-0, never
     scrolls away) — only the stat cards / charts / rankings below them
     scroll, in their own overflow-y-auto container. Mirrors the
     Registrar Employment Tracking dashboard's .emp-page-header-wrap
     pattern: "Header stays fixed in place; only the content below it
     scrolls." ── --}}
<div class="flex flex-col gap-4 px-5 sm:px-7 lg:px-10 pt-6 max-w-screen-2xl mx-auto w-full h-[90vh] overflow-hidden">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fa-solid fa-chart-column text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Employment Analytics</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">
                    System-wide employment intelligence
                </p>
            </div>
        </div>

        {{-- ══ GENERATE REPORTS BUTTON — wire:ignore so Alpine's own
             dropdown/toggle state and the live "Report will include"
             summary (read reactively off $wire) never get clobbered by a
             Livewire re-render. Mirrors the Registrar Employment
             Tracking dashboard's button 1:1, pointed at the admin export
             route. ── --}}
        <div class="relative shrink-0" wire:ignore
             x-data="{
                // Defensive unwrap: in some Livewire/Alpine hydration
                // states inside a wire:ignore subtree, $wire.someProp can
                // resolve to a callable getter instead of its value
                // (observed as literal '() => {}' leaking into text/URLs
                // downstream). uw() calls it if it's a function, otherwise
                // returns it as-is — safe either way.
                uw(val) {
                    return (typeof val === 'function') ? val.call(this) : val;
                },
                reportSummary() {
                    var from   = this.uw($wire.filterBatchFrom) || '';
                    var to     = this.uw($wire.filterBatchTo)   || '';
                    var course = this.uw($wire.filterCourses)   || [];
                    var status = this.uw($wire.filterStatus)    || '';
                    var parts  = [];
                    if (from !== '' && to !== '') {
                        parts.push('Batch ' + (from === to ? from : from + '–' + to));
                    } else if (from !== '' || to !== '') {
                        parts.push('Batch range incomplete (not yet applied)');
                    } else {
                        parts.push('All Batch Years');
                    }
                    if (course.length === 1) {
                        parts.push(course[0]);
                    } else if (course.length > 1) {
                        parts.push(course.join(', '));
                    } else {
                        parts.push('All Programs');
                    }
                    if (status !== '') {
                        var labels = { employed:'Employed', self_employed:'Self-Employed', unemployed:'Unemployed', not_filled:'Not Filled' };
                        parts.push(labels[status] || status);
                    }
                    return parts.join(' · ');
                }
             }"
             x-init="window.__admEmpEnsureReportStore && window.__admEmpEnsureReportStore()"
             @click.outside="$store.admEmpReport.open=false" wire:key="adm-emp-report-dropdown">
            <button type="button" @click.stop="$store.admEmpReport.toggle()" class="ar-report-btn"
                    :disabled="$store.admEmpReport.exporting"
                    :class="{ 'ar-report-btn-active': $store.admEmpReport.open }">
                <i class="fas fa-spinner animate-spin" x-show="$store.admEmpReport.exporting" style="display:none;"></i>
                <i class="fas fa-chart-column" x-show="!$store.admEmpReport.exporting"></i>
                <span class="ar-report-tip">Generate Reports</span>
            </button>

            <div x-show="$store.admEmpReport.open"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="ar-report-menu" style="display:none;">

                <div class="ar-report-menu-message">
                    <span class="lbl"><i class="fas fa-circle-info mr-1"></i>Report will include</span>
                    <span class="txt" x-text="reportSummary()"></span>
                    <span class="cnt" x-text="Number(uw($wire.totalAlumni) || 0).toLocaleString() + ' alumni in this scope'"></span>
                </div>

                <button type="button" @click="$store.admEmpReport.doExport('pdf', $wire)"
                        :disabled="$store.admEmpReport.exporting" class="ar-report-menu-item item-pdf">
                    <span class="ar-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.admEmpReport.exportingType==='pdf'" style="display:none;"></i>
                        <i class="fas fa-file-pdf" x-show="$store.admEmpReport.exportingType!=='pdf'"></i>
                    </span>
                    <span class="ar-item-label">Export as PDF</span>
                </button>

                <button type="button" @click="$store.admEmpReport.doExport('excel', $wire)"
                        :disabled="$store.admEmpReport.exporting" class="ar-report-menu-item item-excel">
                    <span class="ar-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.admEmpReport.exportingType==='excel'" style="display:none;"></i>
                        <i class="fas fa-file-excel" x-show="$store.admEmpReport.exportingType!=='excel'"></i>
                    </span>
                    <span class="ar-item-label">Export as Excel</span>
                </button>

                <button type="button" @click="$store.admEmpReport.doExport('print', $wire)"
                        :disabled="$store.admEmpReport.exporting || $store.admEmpReport.printLock" class="ar-report-menu-item item-print">
                    <span class="ar-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.admEmpReport.exportingType==='print'" style="display:none;"></i>
                        <i class="fas fa-print" x-show="$store.admEmpReport.exportingType!=='print'"></i>
                    </span>
                    <span class="ar-item-label">Print Current View</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ══ FLASH TOAST — mirrors the Registrar Employment Tracking
         export toast (info while generating, success/error after). ══ --}}
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

    {{-- ── FILTER BAR — Batch Year range, Programs (College + Program
         Code merged into one grouped multi-select), Employment Status.
         Scopes the stat cards, every chart below, AND whatever gets
         exported via Generate Reports. (Name/student-ID search was
         removed from here — it has no meaningful match against
         category breakdowns; belongs on a records list instead.) ── --}}
    <div class="flex flex-wrap items-center gap-2.5 flex-shrink-0">

        {{-- Batch Year range --}}
        <div class="relative" x-data="{
                open:false,
                rangeMode: {{ ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) ? 'true' : 'false' }},
                rangeFrom: '{{ $filterBatchFrom }}',
                rangeTo: '{{ $filterBatchTo }}',
                startRange(){ this.rangeFrom=$wire.filterBatchFrom||''; this.rangeTo=$wire.filterBatchTo||''; this.rangeMode=true; },
                applyRange(){ if(this.rangeFrom && this.rangeTo){ $wire.setBatchRange(this.rangeFrom, this.rangeTo); this.open=false; } },
                selectYear(y){ $wire.setSingleBatchYear(y); this.rangeMode=false; this.open=false; },
                clearYear(){ $wire.clearFilterBatch(); this.rangeMode=false; this.open=false; }
             }"
             @click.outside="open=false">
            <button type="button" @click="open = !open"
                    class="yb-adm-dd-btn"
                    :class="{ 'active': $wire.filterBatchFrom!=='' || $wire.filterBatchTo!=='' }">
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
            </button>

            <div x-show="open" x-transition class="yb-adm-dd-panel" style="display:none; width:210px;">
                <button type="button" @click="clearYear()" :class="{'sel': $wire.filterBatchFrom==='' && $wire.filterBatchTo===''}" class="yb-adm-dd-item">All Batch Years</button>
                <div class="border-t border-[#F0ECF5] my-1"></div>
                @foreach($batches as $year)
                    <button type="button" @click="selectYear('{{ $year }}')" :class="{'sel': $wire.filterBatchFrom==='{{ $year }}' && $wire.filterBatchTo==='{{ $year }}'}" class="yb-adm-dd-item">{{ $year }}</button>
                @endforeach
                <div class="border-t border-[#F0ECF5] my-1"></div>
                <div class="px-2 py-1.5">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-[#7A3F91] mb-1.5">Or pick a range</p>
                    <div class="flex items-center gap-1.5">
                        <select x-model="rangeFrom" class="{{ $selectBase }} !max-w-none !text-xs !py-1.5" style="{{ $selectArrow }}">
                            <option value="">From</option>
                            @foreach($batches as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                        </select>
                        <span class="text-[#999999] text-xs">–</span>
                        <select x-model="rangeTo" class="{{ $selectBase }} !max-w-none !text-xs !py-1.5" style="{{ $selectArrow }}">
                            <option value="">To</option>
                            @foreach($batches as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                        </select>
                    </div>
                    <button type="button" @click="applyRange()" class="w-full mt-2 text-xs font-semibold rounded-lg py-1.5 text-white bg-[#7a3f91] hover:bg-[#6a3580] transition">Apply Range</button>
                </div>
            </div>
        </div>

        {{-- Programs — College + Program Code combined into ONE dropdown.
             Grouped by college so the college context isn't lost even
             though there's no separate College select anymore. Checking
             a college's own "Select all" link adds every course code
             under that college into the filterCourses multi-select
             (existing picks from other colleges are kept); individual
             program checkboxes toggle one code at a time the same way
             they always did. Sticky "All Programs" reset header row at
             the TOP of the list, mirrors the Registrar Employment
             Tracking dropdown's sticky Select-All row. --}}
        <div class="relative" x-data="{ open:false }" @click.outside="open=false" wire:key="adm-emp-course-dropdown">
            <button type="button" @click="open = !open" class="yb-adm-dd-btn"
                    :class="{ 'active': {{ !empty($filterCourses) ? 'true' : 'false' }} }">
                @if(empty($filterCourses))
                    All Programs
                @elseif(count($filterCourses) === 1)
                    {{ $filterCourses[0] }}
                @else
                    {{ count($filterCourses) }} Programs
                @endif
            </button>
            <div x-show="open" x-transition class="yb-adm-dd-panel" style="display:none; width:260px; max-height:320px;">
                {{-- Sticky header: "All Programs" reset sits at the top,
                     stays visible while the grouped list below scrolls. --}}
                <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-[#E8E0F0] sticky -top-1 -mx-1 -mt-1 bg-white z-10 rounded-t-[8px]">
                    <button type="button" wire:click="clearFilterCourses" class="text-xs font-semibold text-[#333333] hover:text-[#7A3F91] transition">
                        All Programs
                    </button>
                    <span class="text-xs font-bold text-[#7A3F91]" @if(empty($filterCourses)) style="display:none;" @endif>
                        {{ count($filterCourses) }} selected
                    </span>
                </div>

                @php $groupedCourses = collect($courses)->groupBy('college'); @endphp
                @foreach($groupedCourses as $collegeName => $collegeCourses)
                    <div class="pt-1.5 first:pt-0.5">
                        <div class="flex items-center justify-between gap-2 px-2 py-1">
                            <span class="text-[10px] font-bold uppercase tracking-wide text-[#7A3F91] truncate">{{ $collegeName ?: 'Other' }}</span>
                            <button type="button" wire:click="selectCollegeCourses('{{ $collegeName }}')" class="text-[10px] font-semibold text-[#999999] hover:text-[#7A3F91] transition shrink-0">
                                Select all
                            </button>
                        </div>
                        @foreach($collegeCourses as $c)
                            <label class="yb-adm-dd-item flex items-center gap-2 cursor-pointer" @class(['sel' => in_array($c['code'], $filterCourses)])>
                                <input type="checkbox" wire:click="toggleFilterCourse('{{ $c['code'] }}')"
                                       @checked(in_array($c['code'], $filterCourses))
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] text-[#7A3F91] focus:ring-[#7A3F91]/30 cursor-pointer shrink-0">
                                <span class="truncate">{{ $c['code'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Employment Status --}}
        <select wire:model.live="filterStatus" style="{{ $selectArrow }}" class="{{ $selectBase }}"
                @class([$activeSelect => $filterStatus !== ''])>
            <option value="">All Statuses</option>
            <option value="employed">Employed</option>
            <option value="self_employed">Self-Employed</option>
            <option value="unemployed">Unemployed</option>
            <option value="not_filled">Not Filled</option>
        </select>

        @if($filterBatchFrom !== '' || $filterBatchTo !== '' || !empty($filterCourses) || $filterStatus !== '')
            <button type="button" wire:click="resetFilters" class="yb-adm-plain-btn">
                <i class="fa-solid fa-rotate-left text-xs"></i> Reset
            </button>
        @endif
    </div>

    {{-- ── SCROLLABLE CONTENT — stat cards, charts, rankings. Header and
         filter bar above stay put; only this container scrolls. ── --}}
    <div class="flex flex-col gap-4 pb-6 -mr-5 pr-5 sm:-mr-7 sm:pr-7 lg:-mr-10 lg:pr-10 overflow-y-auto overflow-x-hidden flex-1 min-h-0
                [&::-webkit-scrollbar]:w-[5px] [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb:hover]:bg-[#7a3f91]">

    {{-- ── STAT CARDS (view-only, Emp Rate removed) ── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 flex-shrink-0">

        @php
            $fillRate = $totalAlumni > 0 ? round($totalFilled/$totalAlumni*100) : 0;
        @endphp

        {{-- Total --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#6d2f84] to-[#9b59b6]">
                <i class="fas fa-users text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalAlumni) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Total Alumni</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ $fillRate }}% have records</div>
            </div>
        </div>

        {{-- Employed --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#027a4f] to-[#059669]">
                <i class="fas fa-briefcase text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalEmployed) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Employed</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ $totalAlumni > 0 ? round($totalEmployed/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        {{-- Self-Employed --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#1a4db5] to-[#2563eb]">
                <i class="fas fa-store text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalSelf) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Self-Employed</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ $totalAlumni > 0 ? round($totalSelf/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        {{-- Unemployed --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#b55a05] to-[#d97706]">
                <i class="fas fa-circle-pause text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalUnemployed) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Unemployed</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ $totalAlumni > 0 ? round($totalUnemployed/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        {{-- Not Filled --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#4b5563] to-[#6b7280]">
                <i class="fas fa-circle-question text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalNotFilled) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Not Filled</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ $totalAlumni > 0 ? round($totalNotFilled/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        {{-- Local --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#0d7377] to-[#14b8a6]">
                <i class="fas fa-house text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalLocal) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Local</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ ($totalLocal + $totalAbroad) > 0 ? round($totalLocal/($totalLocal+$totalAbroad)*100) : 0 }}% of employed</div>
            </div>
        </div>

        {{-- Abroad / OFW --}}
        <div class="{{ $statCard }}">
            <div class="{{ $statIcon }} bg-gradient-to-br from-[#b84c05] to-[#f97316]">
                <i class="fas fa-plane-departure text-white text-[1.15rem]"></i>
            </div>
            <div class="flex flex-col gap-[1px] min-w-0">
                <div class="text-[1.45rem] font-extrabold leading-none tracking-[-0.01em] text-black">{{ number_format($totalAbroad) }}</div>
                <div class="text-[0.68rem] font-bold mt-0.5 text-black">Abroad / OFW</div>
                <div class="text-[0.62rem] font-medium mt-px text-[#555555]">{{ ($totalLocal + $totalAbroad) > 0 ? round($totalAbroad/($totalLocal+$totalAbroad)*100) : 0 }}% of employed</div>
            </div>
        </div>

    </div>

    {{-- ── ROW 1 — Status / Location / Relevance ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 flex-shrink-0">

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="{{ $chartDot }}"></div>
                    <span class="{{ $chartTtl }}">Employment Status</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="admChartStatus"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-[#e879f9] flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Work Location</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="admChartLocation"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Job-Course Relevance</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="admChartRelevance"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Unemployed</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center relative" style="height:220px;" wire:ignore>
                <canvas id="admChartUnemployed"></canvas>
                <div id="admChartUnemployedNoData" class="flex flex-col items-center justify-center h-full gap-2 text-[#666666] absolute inset-0" style="display:none;">
                    <i class="fa-solid fa-circle-info text-[1.8rem] opacity-25"></i>
                    <p class="text-[0.8rem] font-semibold opacity-60">No unemployment data yet</p>
                </div>
            </div>
        </div>

    </div>

    {{-- ── ROW 2 — Emp Type / Career Path / Education / Top Courses ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 flex-shrink-0">

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-purple-500 flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Employment Type</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="admChartEmpType"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-teal-500 flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Career Path Labels</span>
                </div>
            </div>
            <div class="p-4" style="height:220px;" wire:ignore>
                <canvas id="admChartCareerPath"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0"></div>
                    <span class="{{ $chartTtl }}">Further Education</span>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="admChartEduStatus"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="{{ $chartDot }}"></div>
                    <span class="{{ $chartTtl }}">Top Courses (Employed)</span>
                </div>
            </div>
            <div class="p-4" style="height:220px;" wire:ignore>
                <canvas id="admChartCourse"></canvas>
            </div>
        </div>

    </div>

    {{-- ── ROW 3 — By Batch + By College ── --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 flex-shrink-0">

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></div>
                    <div>
                        <div class="{{ $chartTtl }}">Employment by Batch Year</div>
                        <div class="{{ $chartSub }}">Stacked across all years</div>
                    </div>
                </div>
                <div id="admBatchNavControls" class="flex items-center gap-1.5" style="display:none!important;">
                    <button id="admBatchPrev"
                            class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30 disabled:cursor-not-allowed text-[#7a3f91]">
                        <i class="fa-solid fa-chevron-left text-[0.6rem]"></i>
                    </button>
                    <span id="admBatchPageInfo" class="text-xs font-semibold text-[#666666] whitespace-nowrap min-w-[36px] text-center"></span>
                    <button id="admBatchNext"
                            class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-xs transition disabled:opacity-30 disabled:cursor-not-allowed text-[#7a3f91]">
                        <i class="fa-solid fa-chevron-right text-[0.6rem]"></i>
                    </button>
                </div>
            </div>
            <div class="p-4" style="height:270px;" wire:ignore>
                <canvas id="admChartBatch"></canvas>
            </div>
        </div>

        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-cyan-500 flex-shrink-0"></div>
                    <div>
                        <div class="{{ $chartTtl }}">Employment by College</div>
                        <div class="{{ $chartSub }}">Across all departments</div>
                    </div>
                </div>
            </div>
            <div class="p-4" style="height:270px;" wire:ignore>
                <canvas id="admChartCollege"></canvas>
            </div>
        </div>

    </div>

    {{-- ── INSIGHTS / RANKINGS ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 flex-shrink-0">

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

        {{-- College Employment Rate --}}
        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="{{ $chartDot }}"></div>
                    <div>
                        <div class="{{ $chartTtl }}">Colleges — Employment Rate</div>
                        <div class="{{ $chartSub }}">Highest rate first</div>
                    </div>
                </div>
                <i class="fa-solid fa-trophy text-sm text-amber-500"></i>
            </div>
            <div class="p-4 space-y-0 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]" style="height:220px;">
                @forelse($allColleges->take(6) as $i => $col)
                @php
                    $medals    = ['🥇','🥈','🥉'];
                    $medal     = $medals[$i] ?? null;
                    $pct       = $col['rate'];
                    $fillColor = $pct >= 70 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-amber-500' : 'bg-red-500');
                    $fillText  = $pct >= 70 ? 'text-emerald-500' : ($pct >= 40 ? 'text-amber-500' : 'text-red-500');
                    $rankBg    = $i===0 ? 'bg-amber-100' : ($i===1 ? 'bg-gray-100' : ($i===2 ? 'bg-orange-100' : 'bg-gray-50'));
                    $rankTx    = $i===0 ? 'text-amber-700' : ($i===1 ? 'text-gray-500' : 'text-orange-700');
                @endphp
                <div class="{{ $rankRow }}">
                    <div class="w-[22px] h-[22px] rounded-full flex items-center justify-center text-[0.72rem] font-semibold flex-shrink-0 {{ $rankBg }} {{ $rankTx }}">
                        {{ $medal ?? ($i+1) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold truncate text-[#333333]">{{ $col['name'] }}</p>
                        <div class="{{ $progTrack }}"><div class="{{ $progFill }} {{ $fillColor }}" style="width:{{ $pct }}%;"></div></div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2 {{ $fillText }}">{{ $pct }}%</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#666666]">No college data available.</p>
                @endforelse
            </div>
        </div>

        {{-- Top Courses --}}
        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0"></div>
                    <div>
                        <div class="{{ $chartTtl }}">Top Courses by Employed</div>
                        <div class="{{ $chartSub }}">Most alumni employed</div>
                    </div>
                </div>
                <i class="fa-solid fa-graduation-cap text-sm text-blue-500"></i>
            </div>
            <div class="p-4 space-y-0 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]" style="height:220px;">
                @php $maxCourse = $topCourses->max('cnt') ?: 1; @endphp
                @forelse($topCourses as $i => $c)
                <div class="{{ $rankRow }}">
                    <div class="w-[22px] h-[22px] rounded-full flex items-center justify-center text-[0.72rem] font-semibold flex-shrink-0 bg-purple-100 text-[#7a3f91]">{{ $i+1 }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333]">{{ $c->course_code }}</p>
                        <div class="{{ $progTrack }}"><div class="{{ $progFill }} bg-[#7a3f91]" style="width:{{ round($c->cnt/$maxCourse*100) }}%;"></div></div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2 text-[#7a3f91]">{{ $c->cnt }}</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#666666]">No course data available.</p>
                @endforelse
            </div>
        </div>

        {{-- Top Batches --}}
        <div class="{{ $chartCard }}">
            <div class="{{ $chartHead }}">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></div>
                    <div>
                        <div class="{{ $chartTtl }}">Top Batches by Employed</div>
                        <div class="{{ $chartSub }}">Most alumni working</div>
                    </div>
                </div>
                <i class="fa-solid fa-calendar-check text-sm text-amber-500"></i>
            </div>
            <div class="p-4 space-y-0 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]" style="height:220px;">
                @php $maxBatch = $topBatches->max('cnt') ?: 1; @endphp
                @forelse($topBatches as $i => $b)
                <div class="{{ $rankRow }}">
                    <div class="w-[22px] h-[22px] rounded-full flex items-center justify-center text-[0.72rem] font-semibold flex-shrink-0 bg-amber-100 text-amber-700">{{ $i+1 }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333]">Batch {{ $b->batch }}</p>
                        <div class="{{ $progTrack }}"><div class="{{ $progFill }} bg-amber-500" style="width:{{ round($b->cnt/$maxBatch*100) }}%;"></div></div>
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0 ml-2 text-amber-600">{{ $b->cnt }}</span>
                </div>
                @empty
                    <p class="text-sm text-center py-6 text-[#666666]">No batch data available.</p>
                @endforelse
            </div>
        </div>

    </div>

    </div>{{-- /scrollable content container --}}

</div>{{-- /MAIN LAYOUT (header + filter bar + scrollable content; root div closes at end of file) --}}


{{-- ══ EMPLOYMENT DETAIL MODAL ══ --}}
@if($modalOpen)
<div class="fixed inset-0 z-[80] bg-[rgba(18,4,35,0.62)] backdrop-blur-lg flex items-center justify-center p-5 animate-[admFadeIn_0.18s_ease_both]"
     wire:click.self="closeModal"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-[22px] w-full max-w-[1040px] h-[90vh] max-h-[90vh] flex flex-col overflow-hidden shadow-[0_30px_90px_rgba(60,15,100,0.28)] animate-[admSlideUp_0.2s_cubic-bezier(0.4,0,0.2,1)_both]">

        <div class="bg-gradient-to-br from-[#7a3f91] to-[#5c2d6e] px-6 py-[18px] flex items-center justify-between gap-3 flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-white/[0.18]">
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
                            'local'         => 'fa-house',
                            'abroad'        => 'fa-plane-departure',
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
            <button wire:click="closeModal" class="inline-flex items-center gap-1.5 px-[18px] py-2 rounded-xl bg-white/15 text-white text-[0.82rem] font-semibold border border-white/25 cursor-pointer flex-shrink-0 transition-colors duration-150 hover:bg-white/25">
                <i class="fa-solid fa-xmark text-sm"></i> Close
            </button>
        </div>

        <div class="px-6 py-3 border-b border-[#E8E0F0] flex items-center gap-2.5 flex-wrap flex-shrink-0 bg-[#faf7fc]">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fa-solid fa-magnifying-glass absolute left-[11px] top-1/2 -translate-y-1/2 text-[0.75rem] text-[#666666] pointer-events-none"></i>
                <input wire:model.live.debounce.350ms="modalSearch"
                       type="text"
                       placeholder="Search name, ID, course, company…"
                       class="w-full pl-[34px] pr-3 py-2 border-[1.5px] border-[#E8E0F0] rounded-[10px] text-[0.85rem] font-medium text-[#333333] bg-white outline-none transition-all duration-150 focus:border-[#7a3f91] focus:ring-[3px] focus:ring-[#7a3f91]/10">
            </div>
            <select wire:model.live="modalBatch" style="{{ $selectArrow }}" class="{{ $selectBase }}" style="min-width:150px;{{ $selectArrow }}">
                <option value="">All Batch Years</option>
                @foreach($batches as $b)
                    <option value="{{ $b }}">Batch {{ $b }}</option>
                @endforeach
            </select>
            @if($modalBatch)
                <button wire:click="$set('modalBatch','')"
                        class="text-xs font-semibold px-3 py-2 rounded-xl border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100">
                    <i class="fa-solid fa-rotate-left text-xs mr-1"></i>Clear
                </button>
            @endif
        </div>

        <div id="admModalScrollWrap" class="flex-1 overflow-y-auto relative"
             wire:loading.class="{{ $wireFade }}"
             wire:target="openEmploymentModal,updatedModalBatch,updatedModalSearch">

            <div id="admScrollDirIndicator"
                 class="fixed left-1/2 -translate-x-1/2 z-[70] w-9 h-9 rounded-full bg-[#1a0a2e]/85 backdrop-blur-sm text-white flex items-center justify-center shadow-[0_4px_14px_rgba(0,0,0,0.3)] opacity-0 pointer-events-none transition-opacity duration-200">
                <i class="fa-solid fa-arrow-down text-sm"></i>
            </div>

            @if(count($modalAlumni) > 0)
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-[#f8f5fc] border-b-[1.5px] border-[#E8E0F0] sticky top-0 z-[2]">
                        <th class="{{ $thBase }}">#</th>
                        <th class="{{ $thBase }}">Alumni</th>
                        <th class="{{ $thBase }}">Status</th>
                        <th class="{{ $thBase }}">Company</th>
                        <th class="{{ $thBase }}">Location</th>
                        <th class="{{ $thBase }}">Relevance</th>
                        <th class="{{ $thBase }}">Batch</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($modalAlumni as $i => $alum)
                    @php
                        $sk  = $alum['status_key'];
                        $sc  = match($sk) { 'employed'=>'text-emerald-600','self_employed'=>'text-[#7a3f91]','unemployed'=>'text-amber-600', default=>'text-gray-400' };
                        $sbg = match($sk) { 'employed'=>'bg-emerald-100','self_employed'=>'bg-purple-100','unemployed'=>'bg-amber-100', default=>'bg-gray-100' };
                        $sbd = match($sk) { 'employed'=>'border-emerald-600/30','self_employed'=>'border-[#7a3f91]/30','unemployed'=>'border-amber-600/30', default=>'border-gray-400/30' };
                        $si  = match($sk) { 'employed'=>'fa-briefcase','self_employed'=>'fa-store','unemployed'=>'fa-circle-pause', default=>'fa-circle-question' };
                    @endphp
                    <tr wire:key="modal-alum-{{ $alum['id'] ?? $i }}" class="border-b border-[#f3f0f9] transition-colors duration-100 hover:bg-[#faf7ff]">
                        <td class="{{ $tdBase }}">
                            <span class="text-sm font-semibold text-[#666666]">{{ str_pad($i+1,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="{{ $tdBase }}">
                            <div class="flex items-center gap-2.5">
                                <div class="w-[34px] h-[34px] rounded-full bg-[#f3f0f9] border-[1.5px] border-[#e4dff0] flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-user text-xs text-[#666666]"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-[#333333]">{{ $alum['name'] }}</p>
                                    <p class="text-xs font-normal text-[#666666]">{{ $alum['id_number'] }} &bull; {{ $alum['course'] }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="{{ $tdBase }}">
                            <div class="{{ $statusPill }} {{ $sbg }} {{ $sc }} {{ $sbd }}">
                                <i class="fa-solid {{ $si }} text-[9px]"></i>
                                {{ $alum['status'] }}
                            </div>
                            @if($alum['type'])
                                <p class="text-xs font-normal mt-1 text-[#666666]">{{ $alum['type'] }}</p>
                            @endif
                        </td>
                        <td class="{{ $tdBase }}">
                            @if($alum['company'])
                                <p class="text-sm font-semibold text-[#333333]">{{ $alum['company'] }}</p>
                            @else
                                <span class="text-xs text-[#cccccc]">—</span>
                            @endif
                        </td>
                        <td class="{{ $tdBase }}">
                            @if($alum['location'])
                            @php
                                $lc  = $alum['location']==='local' ? 'text-teal-600' : 'text-orange-600';
                                $lbg = $alum['location']==='local' ? 'bg-teal-100'  : 'bg-orange-100';
                                $lbd = $alum['location']==='local' ? 'border-teal-600/30' : 'border-orange-600/30';
                                $li  = $alum['location']==='local' ? 'fa-house' : 'fa-plane-departure';
                                $ll  = $alum['location']==='local' ? 'Local' : 'Abroad';
                            @endphp
                                <div class="{{ $statusPill }} {{ $lbg }} {{ $lc }} {{ $lbd }}">
                                    <i class="fa-solid {{ $li }} text-[9px]"></i> {{ $ll }}
                                </div>
                            @else
                                <span class="text-xs text-[#cccccc]">—</span>
                            @endif
                        </td>
                        <td class="{{ $tdBase }}">
                            @if($alum['relevance'])
                            @php
                                $rel = $alum['relevance'];
                                $rc  = $rel==='yes' ? 'text-emerald-600' : ($rel==='partially' ? 'text-amber-600' : 'text-red-600');
                                $rbg = $rel==='yes' ? 'bg-emerald-100'  : ($rel==='partially' ? 'bg-amber-100'  : 'bg-red-100');
                                $rbd = $rel==='yes' ? 'border-emerald-600/30' : ($rel==='partially' ? 'border-amber-600/30' : 'border-red-600/30');
                                $rl  = $rel==='yes' ? 'Related' : ($rel==='partially' ? 'Partial' : 'Not Rel.');
                                $ri  = $rel==='yes' ? 'fa-circle-check' : ($rel==='partially' ? 'fa-circle-half-stroke' : 'fa-circle-xmark');
                            @endphp
                                <div class="{{ $statusPill }} {{ $rbg }} {{ $rc }} {{ $rbd }}">
                                    <i class="fa-solid {{ $ri }} text-[9px]"></i> {{ $rl }}
                                </div>
                            @else
                                <span class="text-xs text-[#cccccc]">—</span>
                            @endif
                        </td>
                        <td class="{{ $tdBase }}">
                            <span class="text-sm font-semibold text-[#333333]">{{ $alum['batch'] }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="flex flex-col items-center justify-center py-20 text-[#666666]">
                <i class="fa-solid fa-inbox text-5xl mb-4 opacity-20"></i>
                <p class="text-sm font-semibold">No alumni found matching your criteria.</p>
                @if($modalSearch || $modalBatch)
                    <button wire:click="$set('modalSearch',''); $set('modalBatch','')"
                            class="mt-3 text-xs font-semibold px-4 py-2 rounded-xl border border-[#E8E0F0] text-[#7a3f91] transition">
                        Clear filters
                    </button>
                @endif
            </div>
            @endif
        </div>

    </div>
</div>
@endif

{{-- ══ CHART DATA BRIDGE ══ --}}
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
     data-unemployed="{{ $chartUnemployedData }}">
</div>

{{-- ══ CHART SCRIPT (unchanged — Chart.js colors are canvas config, not CSS) ══ --}}
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
                unemployed: JSON.parse(el.getAttribute('data-unemployed') || 'null'),
            };
        } catch(e){ return null; }
    }

    function kill(id){
        // Ask Chart.js itself first — our local registry can go stale if a
        // chart was destroyed/replaced through another path (e.g. an
        // overlapping initAll() run). Trusting only registry[id] is what let
        // hbar()/polar()/groupedBar() throw "Canvas is already in use".
        var c = document.getElementById(id);
        var existing = (c && typeof Chart !== 'undefined' && Chart.getChart) ? Chart.getChart(c) : null;
        if(existing){ existing.destroy(); }
        else if(registry[id]){ registry[id].destroy(); }
        delete registry[id];
    }
    function allZero(arr){ return !arr || arr.every(function(v){ return !v || v===0; }); }

    function toggleNoData(canvasId, isEmpty){
        var noDataId = canvasId + 'NoData';
        var canvas   = document.getElementById(canvasId);
        var noData   = document.getElementById(noDataId);
        if(canvas) canvas.style.display = isEmpty ? 'none' : '';
        if(noData) noData.style.display = isEmpty ? 'flex' : 'none';
    }

    function donut(id, data){
        if(!data || !data.labels) return;
        var empty = allZero(data.data);
        toggleNoData(id, empty);
        if(empty){ kill(id); return; }
        var c = document.getElementById(id); if(!c) return;

        // Ask Chart.js itself (not just our local registry) whether this
        // canvas already has a live chart attached. Two overlapping
        // initAll() calls (e.g. from a fast double-commit) can otherwise
        // race: both see registry[id] as unset and both try to create a
        // new Chart on the same canvas, which throws "Canvas is already
        // in use". Chart.getChart() is Chart.js's own source of truth.
        var existing = (typeof Chart !== 'undefined' && Chart.getChart) ? Chart.getChart(c) : null;

        if(existing){
            registry[id] = existing;
            existing.data.labels = data.labels;
            existing.data.datasets[0].data = data.data;
            existing.data.datasets[0].backgroundColor = data.colors;
            existing.update('active');
            return;
        }

        if(registry[id]){
            // Our registry thinks a chart exists but Chart.js doesn't know
            // about it anymore (already destroyed elsewhere) — drop the
            // stale reference before creating a fresh one.
            delete registry[id];
        }

        // Belt-and-suspenders: even though Chart.getChart(c) returned
        // nothing above, Livewire's DOM morphing can occasionally leave a
        // canvas element marked as "in use" internally by Chart.js without
        // a live entry in Chart.getChart()'s registry (e.g. when the
        // canvas node itself gets replaced/recreated mid-render). Calling
        // kill(id) here is a no-op if the canvas is genuinely free, but it
        // guarantees any lingering instance tied to this element is torn
        // down before we ever call "new Chart()" — this is what fixes the
        // "Canvas is already in use" crash that donut() alone didn't guard
        // against (unlike hbar()/polar()/groupedBar(), which already kill()
        // unconditionally before creating).
        kill(id);

        var opts = {
            responsive: true, maintainAspectRatio: false, cutout: '66%',
            events: ['mousemove','mouseout','touchstart','touchmove'],
            plugins: {
                legend: {
                    position: 'bottom', onClick: function(){},
                    labels: { font:{size:11,weight:'600'}, color:'#333', padding:10, usePointStyle:true, pointStyleWidth:8 }
                },
                tooltip: { callbacks: { label: function(ctx){
                    var t = ctx.dataset.data.reduce(function(a,b){ return a+b; },0);
                    var p = t ? Math.round(ctx.parsed/t*100) : 0;
                    return ' '+ctx.label+': '+ctx.parsed+' ('+p+'%)';
                }}}
            }
        };
        registry[id] = new Chart(c, {
            type: 'doughnut',
            data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth:2, borderColor:'#fff', hoverOffset:8 }] },
            options: opts
        });
    }

    function hbar(id, data){
        if(!data || !data.labels) return;
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: { labels: data.labels, datasets: [{ label:'Alumni', data:data.data, backgroundColor:'rgba(122,63,145,.75)', borderColor:'#7a3f91', borderWidth:1, borderRadius:5 }] },
            options: {
                indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins: { legend:{display:false}, tooltip:{callbacks:{label:function(ctx){ return ' '+ctx.parsed.x+' alumni'; }}} },
                scales: {
                    x: { grid:{color:'#f3f4f6'}, ticks:{font:{size:11,weight:'600'},color:'#9ca3af',precision:0}, beginAtZero:true },
                    y: { grid:{display:false}, ticks:{font:{size:11,weight:'600'},color:'#333'} }
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
            data: { labels:data.labels, datasets:[{ data:data.data, backgroundColor:data.colors.map(function(x){ return x+'cc'; }), borderColor:data.colors, borderWidth:1.5 }] },
            options: {
                responsive:true, maintainAspectRatio:false,
                plugins: { legend:{ position:'bottom', onClick:function(){}, labels:{font:{size:10,weight:'600'},color:'#333',padding:8,usePointStyle:true,pointStyleWidth:7} }, tooltip:{callbacks:{label:function(ctx){ return ' '+ctx.label+': '+ctx.parsed.r; }}} },
                scales: { r:{ ticks:{display:false}, grid:{color:'#f3f4f6'} } }
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
            type:'bar',
            data:{ labels:labels, datasets:[
                { label:'Employed',      data:employed,   backgroundColor:'#10b981', borderRadius:3, stack:'a' },
                { label:'Self-Employed', data:self_emp,   backgroundColor:'#3b82f6', borderRadius:3, stack:'a' },
                { label:'Unemployed',    data:unemployed, backgroundColor:'#f59e0b', borderRadius:3, stack:'a' },
            ]},
            options:{
                responsive:true, maintainAspectRatio:false, animation:{duration:350},
                plugins:{ legend:{ position:'top', align:'end', onClick:function(){}, labels:{font:{size:11,weight:'600'},color:'#333',padding:12,usePointStyle:true} } },
                scales:{
                    x:{ stacked:true, grid:{display:false}, ticks:{font:{size:10,weight:'600'},color:'#666',maxRotation:35} },
                    y:{ stacked:true, grid:{color:'#f3f4f6'}, ticks:{font:{size:10},color:'#9ca3af',precision:0}, beginAtZero:true }
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
            var s = l.replace(/^College of\s+/i,'').replace(/^College\s+/i,'');
            return s.length>22 ? s.slice(0,20)+'…' : s;
        });
        registry[id] = new Chart(c, {
            type:'bar',
            data:{ labels:shortLabels, datasets:[
                { label:'Employed',      data:employed,   backgroundColor:'#10b981', borderRadius:3, stack:'a' },
                { label:'Self-Employed', data:self_emp,   backgroundColor:'#3b82f6', borderRadius:3, stack:'a' },
                { label:'Unemployed',    data:unemployed, backgroundColor:'#f59e0b', borderRadius:3, stack:'a' },
            ]},
            options:{
                indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:{duration:350},
                plugins:{
                    legend:{ position:'top', align:'end', onClick:function(){}, labels:{font:{size:11,weight:'600'},color:'#333',padding:12,usePointStyle:true} },
                    tooltip:{ callbacks:{
                        title:function(items){ var idx=items[0].dataIndex; return fullLabels[idx]||shortLabels[idx]; },
                        label:function(ctx){ return ' '+ctx.dataset.label+': '+ctx.parsed.x; }
                    }}
                },
                scales:{
                    x:{ stacked:true, grid:{color:'#f3f4f6'}, ticks:{font:{size:10},color:'#9ca3af',precision:0}, beginAtZero:true },
                    y:{ stacked:true, grid:{display:false}, ticks:{font:{size:10,weight:'600'},color:'#333',maxRotation:0,minRotation:0} }
                }
            }
        });
    }

    function sliceBatch(data, start){
        var end = start + BATCH_PAGE;
        return { labels:data.labels.slice(start,end), employed:data.employed.slice(start,end), self_emp:data.self_emp.slice(start,end), unemployed:data.unemployed.slice(start,end) };
    }

    function drawBatch(data, start){
        if(!data || !data.labels || !data.labels.length) return;
        var sl = sliceBatch(data, start);
        stackedBar('admChartBatch', sl.labels, sl.employed, sl.self_emp, sl.unemployed);
        var total=data.labels.length, pages=Math.ceil(total/BATCH_PAGE), cur=Math.floor(start/BATCH_PAGE)+1;
        var nav=document.getElementById('admBatchNavControls');
        var prev=document.getElementById('admBatchPrev');
        var next=document.getElementById('admBatchNext');
        var info=document.getElementById('admBatchPageInfo');
        if(nav && pages>1){
            nav.style.display='flex';
            if(info) info.textContent=cur+' / '+pages;
            if(prev) prev.disabled=(start<=0);
            if(next) next.disabled=(start+BATCH_PAGE>=total);
        } else if(nav){ nav.style.display='none'; }
    }

    function bindBatchNav(){
        var prev=document.getElementById('admBatchPrev');
        var next=document.getElementById('admBatchNext');
        if(!prev||!next) return;
        var np=prev.cloneNode(true); var nn=next.cloneNode(true);
        prev.parentNode.replaceChild(np,prev);
        next.parentNode.replaceChild(nn,next);
        np.addEventListener('click',function(){ if(!batchAll)return; batchIdx=Math.max(0,batchIdx-BATCH_PAGE); drawBatch(batchAll,batchIdx); });
        nn.addEventListener('click',function(){ if(!batchAll)return; var mx=batchAll.labels.length-BATCH_PAGE; batchIdx=Math.min(mx,batchIdx+BATCH_PAGE); drawBatch(batchAll,batchIdx); });
    }

    // ── Scroll direction indicator (Alumni modal table) ──────────────────────
    var scrollDirState = { lastY: 0, hideTimer: null, bound: false };

    function bindScrollDirIndicator(){
        var wrap = document.getElementById('admModalScrollWrap');
        var indicator = document.getElementById('admScrollDirIndicator');
        if(!wrap || !indicator) return;
        if(wrap._admScrollBound) return;
        wrap._admScrollBound = true;

        scrollDirState.lastY = wrap.scrollTop;

        wrap.addEventListener('scroll', function(){
            var currentY = wrap.scrollTop;
            var goingDown = currentY > scrollDirState.lastY;
            var goingUp   = currentY < scrollDirState.lastY;
            scrollDirState.lastY = currentY;

            if(!goingDown && !goingUp) return;

            var rect = wrap.getBoundingClientRect();
            indicator.style.top = Math.round(rect.top + rect.height/2 - 18) + 'px';

            var icon = indicator.querySelector('i');
            if(goingDown){
                icon.className = 'fa-solid fa-arrow-down text-sm';
            } else {
                icon.className = 'fa-solid fa-arrow-up text-sm';
            }
            indicator.classList.remove('opacity-0');
            indicator.classList.add('opacity-100');

            clearTimeout(scrollDirState.hideTimer);
            scrollDirState.hideTimer = setTimeout(function(){
                indicator.classList.remove('opacity-100');
                indicator.classList.add('opacity-0');
            }, 650);
        }, { passive: true });
    }

    var initAllRunning = false;
    var initAllQueued  = false;

    function initAll(){
        // Guard against overlapping runs: Livewire.hook('commit', ...) fires
        // on every filter change / poll tick, each
        // scheduling initAll() via requestAnimationFrame. If two of those
        // land close together, both can start before the first one's Chart
        // constructors finish, and two "new Chart()" calls race on the same
        // canvas. Instead of running concurrently, queue the second run and
        // replay it once the first is done.
        if(initAllRunning){ initAllQueued = true; return; }
        initAllRunning = true;

        try{
            runInitAll();
        } finally {
            initAllRunning = false;
            if(initAllQueued){
                initAllQueued = false;
                requestAnimationFrame(initAll);
            }
        }
    }

    function runInitAll(){
        var d = bridge(); if(!d) return;

        function safe(fn){
            try { fn(); } catch(e){ console.warn('[employment-tracking] chart render skipped:', e); }
        }

        safe(function(){ donut('admChartStatus', d.status); });
        safe(function(){ donut('admChartLocation', d.location); });
        safe(function(){ donut('admChartRelevance', d.relevance); });
        safe(function(){ donut('admChartEmpType',    d.emptype); });
        safe(function(){ donut('admChartEduStatus',  d.edu); });
        safe(function(){ donut('admChartUnemployed', d.unemployed); });
        safe(function(){ hbar( 'admChartCourse',     d.course); });
        safe(function(){ polar('admChartCareerPath', d.career); });

        safe(function(){
            if(d.college && d.college.labels){
                stackedBarH('admChartCollege', d.college.labels, d.college.employed, d.college.self_emp, d.college.unemployed);
            }
        });

        safe(function(){
            if(d.batch && d.batch.labels){
                var changed = !batchAll || JSON.stringify(d.batch.labels)!==JSON.stringify(batchAll.labels);
                if(changed){ batchAll=d.batch; batchIdx=Math.max(0,batchAll.labels.length-BATCH_PAGE); kill('admChartBatch'); }
                drawBatch(batchAll, batchIdx);
            }
        });
        safe(bindBatchNav);
        safe(bindScrollDirIndicator);
    }

    loadChartJs(function(){
        if(document.readyState==='loading'){
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }

        document.addEventListener('livewire:navigated', function(){
            kill('admChartBatch'); kill('admChartCollege');
            requestAnimationFrame(initAll);
        });

        if(window.Livewire){
            Livewire.hook('commit', function(p){
                var ok = p.succeed || (p.component && p.respond);
                if(typeof ok==='function'){ ok(function(){ requestAnimationFrame(initAll); }); }
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

{{-- ══ GENERATE REPORTS STORE — mirrors the Registrar Employment
     Tracking dashboard's empReport store 1:1, pointed at the admin
     export route with admin's own filter param names. ══ --}}
<script>
(function () {
    'use strict';

    // Built from the named route so this never drifts out of sync with
    // web.php again (previously hardcoded as '/admin/employment-tracking/export',
    // which didn't match the registered '/employment/tracking/export' path).
    var ADM_EMP_EXPORT_URL = "{{ route('employment.tracking.export') }}";

    function registerAdmEmpReportStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('admEmpReport')) return;

        window.Alpine.store('admEmpReport', {
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
             * The exported report is ALWAYS the current page-level scope
             * — Batch Year range + College + Programs + Employment
             * Status, whatever is selected at the top of the dashboard
             * (read straight off $wire). Name/ID search does not scope
             * this — it never scoped the dashboard either.
             */
            async doExport(type, wire) {
                if (this.exporting) return;

                this.open = false;

                // ── Print re-entry guard — see Registrar Employment
                // Tracking for the full rationale: without this, Cancel
                // on the OS print dialog could pop it right back up.
                if (type === 'print') {
                    if (this.printLock) return;
                    this.printLock = true;
                    if (this._activePrintCleanup) {
                        try { this._activePrintCleanup(); } catch (e) { /* noop */ }
                        this._activePrintCleanup = null;
                    }
                }

                this.exporting = true;
                this.exportingType = type;

                var label = type === 'excel' ? 'Excel file' : type === 'print' ? 'print view' : 'PDF';
                window.dispatchEvent(new CustomEvent('flash-message', {
                    detail: { type: 'info', message: 'Generating your ' + label + '… this only takes a moment.' }
                }));

                var self = this;

                // Same defensive unwrap as reportSummary() above — some
                // Livewire/Alpine hydration states resolve $wire.someProp
                // to a callable getter instead of its value inside a
                // wire:ignore subtree (observed as literal '() => {}'
                // leaking into the export URL's query params).
                var uw = function (val) {
                    return (typeof val === 'function') ? val.call(wire) : val;
                };

                var wireCollege    = wire ? uw(wire.filterCollege)    : '';
                var wireCourses    = wire ? uw(wire.filterCourses)    : [];
                var wireStatus     = wire ? uw(wire.filterStatus)     : '';
                var wireBatchFrom  = wire ? uw(wire.filterBatchFrom)  : '';
                var wireBatchTo    = wire ? uw(wire.filterBatchTo)    : '';

                // filterCourses is a multi-select array — join into a
                // comma-separated "course" param. Batch range is
                // all-or-nothing: only send batch_from/batch_to when
                // BOTH are set, so a half-picked range on screen never
                // leaks into an export scoped to something never
                // actually applied.
                var hasProgram   = Array.isArray(wireCourses) && wireCourses.length > 0;
                var hasFullRange = !!(wireBatchFrom && wireBatchTo);

                var params = new URLSearchParams({
                    type:       type,
                    college:    wireCollege || '',
                    course:     hasProgram   ? wireCourses.join(',') : '',
                    status:     wireStatus  || '',
                    batch_from: hasFullRange ? wireBatchFrom         : '',
                    batch_to:   hasFullRange ? wireBatchTo           : '',
                });
                var url = ADM_EMP_EXPORT_URL + '?' + params.toString();

                try {
                    if (type === 'print') {
                        var res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            var msg = await this.readErrorMessage(res, 'Print generation failed. Please try again.');
                            throw new Error(msg);
                        }
                        var html = await res.text();

                        var oldFrame = document.getElementById('adm-emp-print-frame');
                        if (oldFrame) oldFrame.remove();

                        var frame = document.createElement('iframe');
                        frame.id = 'adm-emp-print-frame';
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

                        var cleanedUp  = false;
                        var printFired = false;
                        var onWinFocus;

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

                        onWinFocus = function () {
                            if (!printFired) return;
                            cleanup();
                        };

                        frame.addEventListener('load', function onLoad() {
                            frame.removeEventListener('load', onLoad);
                            setTimeout(function () {
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

    window.__admEmpEnsureReportStore = registerAdmEmpReportStore;
    if (window.Alpine) registerAdmEmpReportStore();
    document.addEventListener('alpine:init', registerAdmEmpReportStore);
})();
</script>

</div>