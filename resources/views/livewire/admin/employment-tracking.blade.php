{{-- resources/views/livewire/admin/employment-tracking.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component {

    // ── Global Filters ────────────────────────────────────────────────────────
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

    public function resetFilters(): void
    {
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
        $this->filterCourses   = [];
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

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full h-[90vh] overflow-y-auto overflow-x-hidden
            [&::-webkit-scrollbar]:w-[5px] [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
            [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb:hover]:bg-[#7a3f91]">

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

    </div>

    {{-- ── FILTERS ── --}}
    <div class="flex flex-wrap items-center gap-2.5 flex-shrink-0 bg-white border border-[#E8E0F0] rounded-xl px-3.5 py-2.5">
        <span class="text-[0.68rem] font-bold uppercase tracking-[0.08em] text-[#7a3f91] mr-1">Filters</span>

        {{-- Batch — FROM/TO range. Default view is a plain year list
             (click a year, done); "Add Range" swaps to two side-by-side
             scrollable year lists so a range like 2020–2024 is opt-in
             rather than always-on. Range is all-or-nothing — picking
             only From (or only To) doesn't filter anything yet, and the
             trigger label says so explicitly. --}}
        @if(count($batches))
        <div class="relative"
             x-data="{
                open: false,
                rangeMode: {{ ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) ? 'true' : 'false' }},
                rangeFrom: '{{ $filterBatchFrom }}',
                rangeTo: '{{ $filterBatchTo }}',
                startRange(){ this.rangeFrom='{{ $filterBatchFrom }}'; this.rangeTo='{{ $filterBatchTo }}'; this.rangeMode=true; },
                pickFrom(val){ this.rangeFrom=val; this.applyRangeIfComplete(); },
                pickTo(val){ this.rangeTo=val; this.applyRangeIfComplete(); },
                applyRangeIfComplete(){ if(this.rangeFrom!=='' && this.rangeTo!==''){ $wire.setBatchRange(this.rangeFrom, this.rangeTo); this.open = false; } }
             }"
             @click.outside="open = false">
            <button type="button"
                    @click="open = !open"
                    :class="{ 'active': {{ ($filterBatchFrom !== '' || $filterBatchTo !== '') ? 'true' : 'false' }} }"
                    class="yb-adm-dd-btn">
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
                        All Batches
                    @endif
                </span>
            </button>
            <div x-show="open" x-cloak
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="yb-adm-dd-panel"
                 style="min-width:160px;">

                {{-- Default view: plain year list --}}
                <div x-show="!rangeMode">
                    <button type="button" @click="$wire.clearFilterBatch(); open = false"
                            class="yb-adm-dd-item {{ ($filterBatchFrom === '' && $filterBatchTo === '') ? 'sel' : '' }}">
                        All Batches
                    </button>
                    @foreach($batches as $b)
                        <button type="button" @click="$wire.setSingleBatchYear('{{ $b }}'); open = false"
                                class="yb-adm-dd-item {{ ($filterBatchFrom == $b && $filterBatchTo == $b) ? 'sel' : '' }}">
                            Batch {{ $b }}
                        </button>
                    @endforeach
                    <div class="h-px bg-[#E8E0F0] my-1"></div>
                    <button type="button" @click="startRange()"
                            class="yb-adm-dd-item font-bold" style="color:#7a3f91;">
                        <i class="fas fa-plus" style="font-size:10px;"></i> Add Range
                    </button>
                </div>


                {{-- Range view: two side-by-side scrollable year lists.
                     Local Alpine state only — nothing sent to the server
                     until both sides are picked. --}}
                <div x-show="rangeMode" class="p-1" style="width:210px;">
                    <div class="flex items-center gap-2 mb-1 px-1">
                        <span class="flex-1 text-[10px] font-bold uppercase tracking-wide" style="color:#7a3f91;" x-text="rangeFrom ? ('From: ' + rangeFrom) : 'From'"></span>
                        <span class="flex-1 text-[10px] font-bold uppercase tracking-wide" style="color:#7a3f91;" x-text="rangeTo ? ('To: ' + rangeTo) : 'To'"></span>
                    </div>
                    <div class="flex items-start gap-2 px-1">
                        <div class="flex-1 min-w-0 border rounded-lg overflow-y-auto" style="border-color:#E8E0F0;max-height:150px;">
                            @foreach($batches as $b)
                            <button type="button" @click="pickFrom('{{ $b }}')"
                                    :class="rangeFrom==='{{ $b }}' ? 'font-bold' : ''"
                                    :style="rangeFrom==='{{ $b }}' ? 'background:#7a3f91;color:#fff;' : 'color:#333333;'"
                                    class="w-full text-left px-2.5 py-1.5 text-xs font-medium hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition-colors">{{ $b }}</button>
                            @endforeach
                        </div>
                        <div class="flex-1 min-w-0 border rounded-lg overflow-y-auto" style="border-color:#E8E0F0;max-height:150px;">
                            @foreach($batches as $b)
                            <button type="button" @click="pickTo('{{ $b }}')"
                                    :class="rangeTo==='{{ $b }}' ? 'font-bold' : ''"
                                    :style="rangeTo==='{{ $b }}' ? 'background:#7a3f91;color:#fff;' : 'color:#333333;'"
                                    class="w-full text-left px-2.5 py-1.5 text-xs font-medium hover:bg-[#F5F0FA] hover:text-[#7A3F91] transition-colors">{{ $b }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mt-2 px-1 pb-1">
                        <button type="button" @click="rangeMode=false"
                                class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors border"
                                style="color:#333333;border-color:#E8E0F0;">
                            Back
                        </button>
                        <button type="button" @click="$wire.clearFilterBatch(); rangeFrom=''; rangeTo=''; rangeMode=false; open=false;"
                                class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors border"
                                style="color:#7a3f91;border-color:#E8E0F0;">
                            Clear
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Programs — MULTI-SELECT with real checkboxes. Checking a box
             toggles that program in/out of filterCourses via
             toggleFilterCourse(); the dropdown stays open across clicks
             so several programs can be checked together. A "Select All"
             checkbox sits in a sticky header row at the top of the
             list — tri-state: checked when every program is selected,
             indeterminate (dash) when some but not all are. --}}
        @if(count($courses))
        <div class="relative"
             x-data="{
                open: false,
                selected(){ return Array.isArray($wire.filterCourses) ? $wire.filterCourses : []; },
                isChecked(code){ return this.selected().includes(code); },
                count(){ return this.selected().length; }
             }"
             wire:key="admin-course-dropdown"
             @click.outside="open = false">
            <button type="button" @click="open = !open"
                    :class="{ 'active': count() > 0 }"
                    class="yb-adm-dd-btn">
                <span>
                    @if(count($filterCourses) === 0)
                        All Programs
                    @elseif(count($filterCourses) === 1)
                        {{ collect($courses)->firstWhere('code', $filterCourses[0])['name'] ?? $filterCourses[0] }}
                    @else
                        {{ count($filterCourses) }} Programs
                    @endif
                </span>
            </button>
            <div x-show="open" x-cloak
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="yb-adm-dd-panel"
                 style="min-width:230px;">
                <div class="flex items-center justify-between gap-2 px-2 py-2 border-b sticky -top-1 bg-white z-10" style="border-color:#E8E0F0;">
                    <label class="flex items-center gap-2 text-xs font-semibold select-none"
                           :class="count() === 0 ? 'cursor-not-allowed' : 'cursor-pointer'"
                           style="color:#333333;">
                        <input type="checkbox"
                               :checked="count() === {{ count($courses) }}"
                               :indeterminate="count() > 0 && count() < {{ count($courses) }}"
                               :disabled="count() === 0"
                               @change="$event.target.checked ? $wire.selectAllFilterCourses() : $wire.clearFilterCourses()"
                               class="w-3.5 h-3.5 rounded cursor-pointer disabled:cursor-not-allowed disabled:opacity-40"
                               style="border-color:#D4C5E8;accent-color:#7a3f91;">
                        Select All
                    </label>
                    <span class="text-xs font-bold select-none" style="color:#7a3f91;" x-show="count() > 0">
                        <span x-text="count()"></span> selected
                    </span>
                </div>
                <div class="py-1">
                    @foreach($courses as $c)
                    <label class="yb-adm-dd-item flex items-center gap-2 {{ in_array($c['code'], $filterCourses, true) ? 'sel' : '' }}" style="cursor:pointer;">
                        <input type="checkbox" wire:click="toggleFilterCourse('{{ $c['code'] }}')"
                               :checked="isChecked('{{ $c['code'] }}')"
                               class="w-3.5 h-3.5 rounded cursor-pointer shrink-0"
                               style="border-color:#D4C5E8;accent-color:#7a3f91;">
                        {{ $c['name'] }}
                    </label>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Reset ── --}}
        <button wire:click="resetFilters"
                class="yb-adm-plain-btn">
            <i class="fas fa-rotate-left text-xs"></i>
            <span class="hidden sm:inline">Reset</span>
        </button>

        {{-- Spinner ── --}}
        <div class="flex items-center gap-2 ml-auto">
            <span wire:loading wire:target="toggleFilterCourse,clearFilterCourses,selectAllFilterCourses,setSingleBatchYear,clearFilterBatch,setBatchRange,resetFilters">
                <svg class="animate-spin w-3.5 h-3.5" style="color:#7A3F91;"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
            </span>
        </div>
    </div>

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

    {{-- ── ROW 1 — Status / Location / Relevance / Unemployed Reason ── --}}
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
                    <span class="{{ $chartTtl }}">Unemployed — Why?</span>
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

</div>{{-- /inner scroll container (NOT the component root — root div closes at end of file) --}}


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
        safe(function(){ donut('admChartUnemployed', d.unemployed); });
        safe(function(){ donut('admChartEmpType',    d.emptype); });
        safe(function(){ donut('admChartEduStatus',  d.edu); });
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

</div>