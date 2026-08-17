{{-- resources/views/livewire/organizer/alumni-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {

    use WithPagination;

    public string $search          = '';

    // Status is now a MULTI-SELECT (array of checked statuses) instead of
    // a single-value dropdown, so e.g. "Employed" + "Self-Employed" can
    // be viewed together — mirrors Alumni Records' alumniEmploymentStatuses.
    public array  $filterStatuses  = [];

    // Batch is now a FROM/TO range instead of a single-value dropdown —
    // mirrors Alumni Records' alumniBatchFrom/alumniBatchTo.
    public string $filterBatchFrom = '';
    public string $filterBatchTo   = '';

    /** Set while setSingleBatchYear()/clearFilterBatch()/setBatchRange()
     *  are writing to filterBatchFrom/filterBatchTo directly, so the
     *  updatedFilterBatchFrom()/updatedFilterBatchTo() hooks below don't
     *  ALSO fire and refresh the table a second time in the same request. */
    private bool $skipBatchHooks = false;

    public string $filterCourse    = '';

    public string $organizerBatch      = '';
    public string $organizerDepartment = '';
    public string $organizerName       = '';
    public array  $allowedCourseCodes  = [];

    public int $totalAlumni     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalLocal      = 0;
    public int $totalOFW        = 0;
    public int $totalRelated     = 0;
    public int $totalPartial     = 0;
    public int $totalNotRelated  = 0;

    public bool  $showModal = false;
    public array $modalData = [];

    // ── Compare Tool state ────────────────────────────────────────────────
    public bool   $showCompareModal = false;
    public string $compareCourseA   = '';
    public string $compareBatchA    = '';
    public string $compareCourseB   = '';
    public string $compareBatchB    = '';
    public array  $compareResultA   = [];
    public array  $compareResultB   = [];
    public string $compareInsight   = '';
    public bool   $compareRan       = false;
    public string $compareError     = '';

    // ── tracks the last seen emp update timestamp so we only notify once ──
    public string $lastSeenEmpAt = '';

    // NOTE: search / filterBatchFrom / filterBatchTo / filterStatuses / filterCourse
    // are all deliberately kept OUT of the query string now, so a plain page
    // refresh always resets every filter back to its default — same behavior
    // as the Job Management and Event Organizer tables. Deep-linking from the
    // dashboard still works via the one-time session handoff below
    // (session('organizer_employment_filter')).

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'organizer') {
            $this->redirect(route('login'));
            return;
        }

        $organizer = DB::table('organizer')
            ->where('user_id', $user->id)
            ->select(['batch', 'department'])
            ->first();

        $this->organizerBatch      = $organizer->batch      ?? '';
        $this->organizerDepartment = $organizer->department ?? '';
        $this->organizerName       = trim($user->name ?? '');

        if ($this->organizerDepartment) {
            $this->allowedCourseCodes = DB::table('courses')
                ->where('college', $this->organizerDepartment)
                ->pluck('code')
                ->toArray();
        }

        // Deep-link filters coming from the dashboard's clickable tiles
        // (Alumni per Program rows, Employment Snapshot tiles). The
        // dashboard stashes the target filter into the session right before
        // redirecting here, so the filter is applied on load with a fully
        // clean URL — no ?course= or ?status= query params ever appear.
        $sessionFilter = session()->pull('organizer_employment_filter', null);
        if (is_array($sessionFilter)) {
            $status = $sessionFilter['status'] ?? '';
            $this->filterStatuses = $status !== '' ? [$status] : [];
            $this->filterCourse   = $sessionFilter['course'] ?? '';
        } elseif (is_string($sessionFilter) && $sessionFilter !== '') {
            // backward-compat: older single-value session payload
            $this->filterStatuses = [$sessionFilter];
        }

        // Seed the last-seen timestamp to "now" on first load
        // so we don't flood notifications for old records
        $this->lastSeenEmpAt = now()->toDateTimeString();

        $this->computeStats();
    }

    public function computeStats(): void
    {
        $base = $this->baseAlumniQuery();

        $this->totalAlumni = (clone $base)->count();

        $withEmp = (clone $base)
            ->join('employment_trackings as et', 'alumni.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        $this->totalEmployed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $this->totalNotFilled  = max(0,
            $this->totalAlumni - $this->totalEmployed - $this->totalSelf - $this->totalUnemployed
        );

        $this->totalLocal = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'local')
            ->count();

        $this->totalOFW = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'abroad')
            ->count();

        $this->totalRelated = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'yes')
            ->count();

        $this->totalPartial = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'partially')
            ->count();

        $this->totalNotRelated = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.course_relevance', 'no')
            ->count();
    }

    // ── called by JS polling every 15s to check for new emp updates ──────
    public function checkEmploymentUpdates(): void
    {
        $q = DB::table('employment_trackings as et')
            ->join('alumni as a', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at')
            ->whereNull('a.deleted_at')
            ->where('et.updated_at', '>', $this->lastSeenEmpAt)
            ->select([
                'a.id',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'et.employment_status',
                'et.job_title',
                'et.company_name',
                'et.updated_at',
            ]);

        if ($this->organizerBatch) {
            $q->where('a.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $q->whereIn('a.course_code', $this->allowedCourseCodes);
        }

        $newUpdates = $q->orderBy('et.updated_at', 'desc')->get();

        if ($newUpdates->isEmpty()) {
            return;
        }

        // Advance the watermark
        $this->lastSeenEmpAt = now()->toDateTimeString();

        // Refresh stats so the cards stay current
        $this->computeStats();

        // Fire one browser event per updated alumni so the layout listener
        // saves a coordinator notification for each one.
        foreach ($newUpdates as $row) {
            $statusLabel = match($row->employment_status) {
                'employed'      => 'Employed',
                'self_employed' => 'Self-Employed',
                'unemployed'    => 'Unemployed',
                default         => 'Updated',
            };

            $detail = $row->job_title
                ? "{$row->full_name} is now {$statusLabel} as {$row->job_title}"
                    . ($row->company_name ? " at {$row->company_name}." : '.')
                : "{$row->full_name} updated their employment status to {$statusLabel}.";

            $this->dispatch('employment-updated', [
                'id'     => $row->id,
                'alumni' => trim($row->full_name) ?: 'An alumni',
                'status' => $statusLabel,
                'detail' => $detail,
            ]);
        }
    }

    private function baseAlumniQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni')->whereNull('alumni.deleted_at');

        if ($this->organizerBatch) {
            $q->where('alumni.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $q->whereIn('alumni.course_code', $this->allowedCourseCodes);
        }

        return $q;
    }

    public function with(): array
    {
        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->select([
                'a.id',
                'a.student_id',
                'a.profile_photo',
                'a.email',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.course_code',
                'a.course_name',
                'a.batch',
                'et.employment_status',
                'et.company_name',
                'et.job_title',
                'et.employment_type',
                'et.work_location',
                'et.date_hired',
                'et.career_path',
                'et.education_status',
                'et.course_relevance',
                'et.unemployment_status',
                'et.updated_at as emp_updated_at',
            ]);

        if ($this->organizerBatch) {
            $q->where('a.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $q->whereIn('a.course_code', $this->allowedCourseCodes);
        }

        if ($this->search) {
            $s = '%' . $this->search . '%';
            $q->where(function ($w) use ($s) {
                $w->where(DB::raw("CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))"), 'like', $s)
                  ->orWhere('a.student_id', 'like', $s)
                  ->orWhere('a.email', 'like', $s)
                  ->orWhere('et.company_name', 'like', $s)
                  ->orWhere('et.job_title', 'like', $s);
            });
        }

        if ($this->filterStatuses) {
            $q->where(function ($w) {
                foreach ($this->filterStatuses as $status) {
                    if ($status === 'not_filled') {
                        $w->orWhereNull('et.employment_status');
                    } else {
                        $w->orWhere('et.employment_status', $status);
                    }
                }
            });
        }
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $q->where('a.batch', '>=', $this->filterBatchFrom)
              ->where('a.batch', '<=', $this->filterBatchTo);
        }
        if ($this->filterCourse)    $q->where('a.course_code',       $this->filterCourse);

        $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
          ->orderBy('a.last_name');

        $rows = $q->paginate(20);

        $rows->getCollection()->transform(function ($row) {
            $row->career_path_arr = $row->career_path
                ? (json_decode($row->career_path, true) ?? []) : [];
            return $row;
        });

        $batches = DB::table('alumni')
            ->whereNull('deleted_at')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('course_code', $this->allowedCourseCodes))
            ->when($this->organizerBatch,
                fn($q) => $q->where('batch', $this->organizerBatch))
            ->distinct()->orderBy('batch', 'desc')->pluck('batch');

        $courses = DB::table('courses')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('code', $this->allowedCourseCodes))
            ->orderBy('code')
            ->get(['code', 'name']);

        return compact('rows', 'batches', 'courses');
    }

    public function updatingSearch(): void          { $this->resetPage(); }
    public function updatingFilterCourse(): void    { $this->resetPage(); }

    /** Toggles a single employment status in/out of the multi-select
     *  filter — bound directly to each checkbox item in the Status
     *  dropdown. Mirrors Alumni Records' toggleEmploymentStatus(). */
    public function toggleFilterStatus(string $status): void
    {
        if (in_array($status, $this->filterStatuses, true)) {
            $this->filterStatuses = array_values(array_diff($this->filterStatuses, [$status]));
        } else {
            $this->filterStatuses[] = $status;
        }
        $this->resetPage();
    }

    /** "All Statuses" inside the dropdown — clears the whole multi-select
     *  in one round-trip. */
    public function clearFilterStatuses(): void
    {
        $this->filterStatuses = [];
        $this->resetPage();
    }

    /** "Select All" inside the dropdown — checks every status option. */
    public function selectAllFilterStatuses(): void
    {
        $this->filterStatuses = ['employed', 'self_employed', 'unemployed', 'not_filled'];
        $this->resetPage();
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
        // two separate table refreshes.
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo === '') {
            return;
        }
        $this->resetPage();
    }

    public function updatedFilterBatchTo(): void
    {
        if ($this->skipBatchHooks) return;
        $this->normalizeBatchRange();
        // Mirror of updatedFilterBatchFrom() above.
        if ($this->filterBatchTo !== '' && $this->filterBatchFrom === '') {
            return;
        }
        $this->resetPage();
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
     *  list — sets From and To to the same year in ONE Livewire
     *  round-trip instead of two separate $wire.set() calls. */
    public function setSingleBatchYear(string $year): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = $year;
        $this->filterBatchTo   = $year;
        $this->skipBatchHooks = false;
        $this->resetPage();
    }

    /** "All Batch Years" — clears both ends of the range in one
     *  round-trip, same reasoning as setSingleBatchYear() above. */
    public function clearFilterBatch(): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
        $this->skipBatchHooks = false;
        $this->resetPage();
    }

    /** Applies a From–To range in ONE Livewire round-trip. Bound to the
     *  range picker's local Alpine state (not wire:model.live on the two
     *  lists) so picking "From" alone never touches the server at all —
     *  the request only fires once both ends are chosen. */
    public function setBatchRange(string $from, string $to): void
    {
        $this->skipBatchHooks = true;
        $this->filterBatchFrom = $from;
        $this->filterBatchTo   = $to;
        $this->skipBatchHooks = false;
        $this->normalizeBatchRange();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search          = '';
        $this->filterStatuses  = [];
        $this->filterBatchFrom = '';
        $this->filterBatchTo   = '';
        $this->filterCourse    = '';
        $this->resetPage();
    }

    public function viewDetail(int $alumniId): void
    {
        $row = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->where('a.id', $alumniId)
            ->select([
                'a.student_id',
                'a.profile_photo',
                'a.email',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.middle_initial,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.suffix', 'a.course_name', 'a.course_code', 'a.batch',
                'a.gender', 'a.civil_status', 'a.contact_number',
                'et.employment_status','et.company_name','et.job_title',
                'et.employment_type','et.work_location','et.date_hired',
                'et.career_path','et.education_status','et.course_relevance',
                'et.unemployment_status','et.updated_at as emp_updated_at',
            ])->first();

        if (!$row) return;

        if ($this->organizerBatch && $row->batch !== $this->organizerBatch) return;
        if (!empty($this->allowedCourseCodes) && !in_array($row->course_code, $this->allowedCourseCodes)) return;

        $this->modalData = (array) $row;
        $this->modalData['career_path_arr'] = $this->modalData['career_path']
            ? (json_decode($this->modalData['career_path'], true) ?? []) : [];

        $this->showModal = true;

        // Close any open mobile sidebar the moment the modal opens, so the
        // modal is never fighting with an overlay drawer for the screen.
        // The layout's sidebar component/Alpine store should listen for
        // this browser event (e.g. `x-on:close-sidebar.window="open = false"`
        // or `@close-sidebar.window="sidebarOpen = false"`).
        $this->dispatch('close-sidebar');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->modalData = [];
        $this->dispatch('open-sidebar');
    }

    // ── Compare Tool: opens the modal ─────────────────────────────────────
    public function openCompareModal(): void
    {
        $this->showCompareModal = true;
        $this->compareRan       = false;
        $this->compareResultA   = [];
        $this->compareResultB   = [];
        $this->compareInsight   = '';
        $this->compareError     = '';
        $this->dispatch('close-sidebar');
    }

    // ── Compare Tool: when a Program is picked/changed, the Batch dropdown
    // for that same group should only ever show batches that actually
    // exist for that program (instead of every batch in the system). If
    // the previously selected batch doesn't exist for the newly picked
    // program, it gets cleared automatically so an invalid combo can't
    // linger in the form.
    public function updatedCompareCourseA(): void
    {
        if ($this->compareBatchA !== '' && !in_array($this->compareBatchA, $this->batchesForCourse($this->compareCourseA))) {
            $this->compareBatchA = '';
        }
        $this->compareError = '';
    }

    public function updatedCompareCourseB(): void
    {
        if ($this->compareBatchB !== '' && !in_array($this->compareBatchB, $this->batchesForCourse($this->compareCourseB))) {
            $this->compareBatchB = '';
        }
        $this->compareError = '';
    }

    public function updatedCompareBatchA(): void { $this->compareError = ''; }
    public function updatedCompareBatchB(): void { $this->compareError = ''; }

    // ── Returns the batches that actually have alumni records for the
    // given program (or all organizer-scoped batches if no program is
    // selected), so the Batch dropdown never offers a combination with
    // zero possible results.
    public function batchesForCourse(string $course): array
    {
        return DB::table('alumni')
            ->whereNull('alumni.deleted_at')
            ->when($course !== '', fn($q) => $q->where('alumni.course_code', $course))
            ->when($this->organizerBatch !== '', fn($q) => $q->where('alumni.batch', $this->organizerBatch))
            ->when(!empty($this->allowedCourseCodes), fn($q) => $q->whereIn('alumni.course_code', $this->allowedCourseCodes))
            ->distinct()
            ->orderBy('alumni.batch', 'desc')
            ->pluck('alumni.batch')
            ->filter(fn($b) => $b !== null && $b !== '')
            ->values()
            ->toArray();
    }

    public function closeCompareModal(): void
    {
        $this->showCompareModal = false;
        $this->dispatch('open-sidebar');
    }

    // ── FIXED: fully-qualified every column so nothing is ambiguous once
    // the query joins `alumni` with `employment_trackings` (both tables
    // have a `deleted_at` column, and both `alumni` and `courses` can have
    // a `course_code`-like column depending on schema, so every reference
    // below is explicitly prefixed with `alumni.`). This is what caused:
    // "Column 'deleted_at' in where clause is ambiguous".
    private function computeGroupStats(string $course, string $batch): array
    {
        $base = DB::table('alumni')->whereNull('alumni.deleted_at');

        if ($course) $base->where('alumni.course_code', $course);
        if ($batch)  $base->where('alumni.batch', $batch);

        // still respect organizer scoping so an organizer can't peek outside
        // their assigned department/batch
        if ($this->organizerBatch) {
            $base->where('alumni.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $base->whereIn('alumni.course_code', $this->allowedCourseCodes);
        }

        $total = (clone $base)->count();

        $withEmp = (clone $base)
            ->join('employment_trackings as et', 'alumni.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        $employed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $self       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $unemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $working    = $employed + $self;

        $rate = $total > 0 ? round(($working / $total) * 100, 1) : 0.0;

        $courseName = $course
            ? (DB::table('courses')->where('code', $course)->value('name') ?: $course)
            : 'All Programs';

        return [
            'course'     => $courseName,
            'batch'      => $batch ?: 'All Batches',
            'total'      => $total,
            'employed'   => $employed,
            'self'       => $self,
            'unemployed' => $unemployed,
            'working'    => $working,
            'rate'       => $rate,
        ];
    }

    // ── Compare Tool: runs the comparison + builds the simple insight text ─
    public function runCompare(): void
    {
        $this->compareError   = '';
        $this->compareRan     = false;
        $this->compareResultA = [];
        $this->compareResultB = [];
        $this->compareInsight = '';

        // Require each group to be narrowed down by at least a Program or
        // a Batch — comparing "All Programs · All Batches" against itself
        // is not a meaningful comparison and would just show 2 identical
        // whole-college totals.
        if ($this->compareCourseA === '' && $this->compareBatchA === '') {
            $this->compareError = 'Please select a Program or Batch for Group A.';
            return;
        }
        if ($this->compareCourseB === '' && $this->compareBatchB === '') {
            $this->compareError = 'Please select a Program or Batch for Group B.';
            return;
        }

        // Block comparing a group against itself (identical Program + Batch).
        if ($this->compareCourseA === $this->compareCourseB && $this->compareBatchA === $this->compareBatchB) {
            $this->compareError = 'Group A and Group B are identical. Please choose a different Program or Batch to compare.';
            return;
        }

        // Guard against a Batch that doesn't actually belong to the chosen
        // Program (can only happen if the client posts a stale value).
        if ($this->compareBatchA !== '' && !in_array($this->compareBatchA, $this->batchesForCourse($this->compareCourseA))) {
            $this->compareError = 'The selected Batch for Group A is not available for that Program.';
            return;
        }
        if ($this->compareBatchB !== '' && !in_array($this->compareBatchB, $this->batchesForCourse($this->compareCourseB))) {
            $this->compareError = 'The selected Batch for Group B is not available for that Program.';
            return;
        }

        $this->compareResultA = $this->computeGroupStats($this->compareCourseA, $this->compareBatchA);
        $this->compareResultB = $this->computeGroupStats($this->compareCourseB, $this->compareBatchB);
        $this->compareRan     = true;

        $a = $this->compareResultA;
        $b = $this->compareResultB;

        $labelA = $a['course'] . ' · ' . $a['batch'];
        $labelB = $b['course'] . ' · ' . $b['batch'];

        if ($a['total'] === 0 && $b['total'] === 0) {
            $this->compareInsight = "No alumni records found for either group yet, so a comparison can't be made.";
            return;
        }

        if ($a['total'] === 0) {
            $this->compareInsight = "{$labelA} has no alumni records yet, so only {$labelB} can be evaluated ({$b['rate']}% employment rate).";
            return;
        }

        if ($b['total'] === 0) {
            $this->compareInsight = "{$labelB} has no alumni records yet, so only {$labelA} can be evaluated ({$a['rate']}% employment rate).";
            return;
        }

        $diff = round(abs($a['rate'] - $b['rate']), 1);

        if ($diff < 0.5) {
            $this->compareInsight = "Based on the results, {$labelA} and {$labelB} have almost the same employment rate ({$a['rate']}% vs {$b['rate']}%) — growth is basically even between the two.";
            return;
        }

        if ($a['rate'] > $b['rate']) {
            $this->compareInsight = "Based on the results, mas mataas ang growth ng employment ng {$labelA} ({$a['rate']}%) kumpara sa {$labelB} ({$b['rate']}%) — that's a {$diff} point difference.";
        } else {
            $this->compareInsight = "Based on the results, mas mataas ang growth ng employment ng {$labelB} ({$b['rate']}%) kumpara sa {$labelA} ({$a['rate']}%) — that's a {$diff} point difference.";
        }
    }

    public function resetCompare(): void
    {
        $this->compareCourseA = $this->compareBatchA = '';
        $this->compareCourseB = $this->compareBatchB = '';
        $this->compareResultA = $this->compareResultB = [];
        $this->compareInsight = '';
        $this->compareRan     = false;
        $this->compareError   = '';
    }

}; ?>

<div class="flex flex-col">

<div id="ae-hover-tip"
     wire:ignore
     class="fixed bg-neutral-900 text-white text-[11px] font-semibold tracking-wide px-3 py-1.5 rounded-lg whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-lg -translate-x-0"
     style="transform:translate(12px,-110%)">
    <i class="fas fa-eye mr-1.5"></i>View Details
</div>

{{-- FLASH TOAST --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
    :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-red-300 text-red-800':type==='error','bg-white border-blue-300 text-blue-800':type==='info'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- MAIN LAYOUT --}}
<div class="flex flex-col gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full transition-all duration-300 ease-in-out">

    {{-- PAGE HEADER --}}
    <div class="flex items-center justify-between gap-4 flex-shrink-0 flex-wrap">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-chart-line text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Employment Tracking</h1>
                <p class="text-xs leading-relaxed mt-0.5 flex flex-wrap items-center gap-1.5 text-[#555555]">
                    Track employment status of your assigned alumni.
                    @if($organizerDepartment)
                        <span class="inline-flex items-center gap-1 font-semibold px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                            <i class="fas fa-building-columns text-[9px]"></i>{{ $organizerDepartment }}
                        </span>
                    @endif
                    @if($organizerBatch)
                        <span class="inline-flex items-center gap-1 font-semibold px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                            <i class="fas fa-calendar text-[9px]"></i>Batch {{ $organizerBatch }}
                        </span>
                    @endif
                </p>
            </div>
        </div>

        {{-- COMPARE TOOL BUTTON — icon-only now. Hover shows a small
             tooltip that just says "Compare Tool", no extra description
             text underneath it anymore. --}}
        <div class="relative group shrink-0">
            <button type="button"
                    wire:click="openCompareModal"
                    wire:loading.attr="disabled" wire:target="openCompareModal"
                    aria-label="Compare Tool"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl text-white shadow-md transition active:scale-95 cursor-pointer bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] hover:brightness-110 disabled:opacity-60 disabled:cursor-wait">
                <i class="fa-solid fa-code-compare text-base" wire:loading.remove wire:target="openCompareModal"></i>
                <i class="fa-solid fa-spinner fa-spin text-base" wire:loading wire:target="openCompareModal"></i>
            </button>
            {{-- Tooltip: icon-only trigger, text-only tooltip. Hidden on
                 mobile (hidden sm:block) since touch devices don't have a
                 real hover state — the tooltip would otherwise get stuck
                 visible after a tap. --}}
            <div class="hidden sm:block pointer-events-none absolute right-0 top-full mt-2 rounded-lg bg-neutral-900 text-white text-xs font-semibold px-3 py-1.5 shadow-xl opacity-0 scale-95 origin-top-right transition-all duration-150 group-hover:opacity-100 group-hover:scale-100 z-50 whitespace-nowrap">
                Compare Tool
            </div>
        </div>
    </div>

    {{-- BODY: stat cards visually moved to the RIGHT side of the table via
         CSS `order`. Both columns always fill the same fixed height
         (calc(100vh - page chrome)) regardless of whether the table has
         results or not, so switching / clearing filters never resizes or
         shifts the layout. The cards column scrolls on its own on desktop.
         On tablet/mobile, the cards are shown in full (no scroll — they
         just wrap in the grid) and ONLY the table block below gets a
         capped height so it scrolls on its own instead of the whole page
         growing tall. --}}
    <div class="flex flex-col lg:flex-row gap-4 w-full lg:h-[calc(100vh-280px)] transition-all duration-300 ease-in-out">

        {{-- STAT CARDS — side column, ordered AFTER the table (right side)
             on large screens via lg:order-2. Always visible, no toggle.
             On tablet/mobile it is NOT independently scrollable (shows in
             full). On desktop it scrolls on its own (lg:h-full lg:overflow-y-auto). --}}
        <div class="w-full lg:w-56 xl:w-64 flex-shrink-0 lg:order-2
                    grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-1 gap-3 content-start
                    lg:h-full lg:overflow-y-auto lg:pr-1
                    [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f3f4f6]
                    [&::-webkit-scrollbar]:w-[5px]
                    [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                    [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                    hover:[&::-webkit-scrollbar-thumb]:bg-[#7a3f91]">

            {{-- Total Alumni --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-[#7A3F91]">
                        <i class="fa-solid fa-users text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">All</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalAlumni }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Total Alumni</p>
            </div>

            {{-- Employed --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-briefcase text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 uppercase">Work</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalEmployed }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Employed</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full" style="width:{{ min(($totalEmployed/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Self-Employed --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-store text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Self</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalSelf }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Self-Employed</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-blue-100 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full" style="width:{{ min(($totalSelf/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Unemployed --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                        <i class="fa-solid fa-circle-pause text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Idle</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalUnemployed }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Unemployed</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-amber-100 rounded-full overflow-hidden">
                        <div class="h-full bg-amber-400 rounded-full" style="width:{{ min(($totalUnemployed/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Not Filled --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-gray-400 flex items-center justify-center shadow">
                        <i class="fa-solid fa-circle-question text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-[#666666] border border-gray-200 uppercase">N/A</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalNotFilled }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Not Filled</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-gray-400 rounded-full" style="width:{{ min(($totalNotFilled/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Local --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-house text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 border border-teal-100 uppercase">PH</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalLocal }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Local</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-teal-100 rounded-full overflow-hidden">
                        <div class="h-full bg-teal-500 rounded-full" style="width:{{ min(($totalLocal/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- OFW --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-orange-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-plane-departure text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 border border-orange-100 uppercase">OFW</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalOFW }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Abroad (OFW)</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-orange-100 rounded-full overflow-hidden">
                        <div class="h-full bg-orange-500 rounded-full" style="width:{{ min(($totalOFW/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Course Relevant --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center shadow">
                        <i class="fa-solid fa-check-circle text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 uppercase">Fit</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalRelated }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Course Relevant</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-600 rounded-full" style="width:{{ min(($totalRelated/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Partially Relevant --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-adjust text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Half</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalPartial }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Partially Relevant</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-amber-100 rounded-full overflow-hidden">
                        <div class="h-full bg-amber-500 rounded-full" style="width:{{ min(($totalPartial/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Not Relevant --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 text-left w-full select-none">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-red-500 flex items-center justify-center shadow">
                        <i class="fa-solid fa-times-circle text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-600 border border-red-100 uppercase">Off</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalNotRelated }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Not Relevant</p>
                @if($totalAlumni > 0)
                    <div class="mt-2 h-1.5 bg-red-100 rounded-full overflow-hidden">
                        <div class="h-full bg-red-500 rounded-full" style="width:{{ min(($totalNotRelated/max($totalAlumni,1))*100,100) }}%;"></div>
                    </div>
                @endif
            </div>

        </div>
        {{-- /STAT CARDS --}}

        {{-- UNIFIED BLOCK (narrower now that the cards sit beside it). Always
             keeps its fixed height (lg:h-full) whether the table has rows
             or is showing the "no results" empty state — this stops the
             block from resizing / collapsing every time a filter changes.
             Uses CSS container queries via arbitrary Tailwind classes so
             column visibility reacts to the TABLE's own width, not the
             viewport (matters because the sidebar can collapse/expand
             independently of the window). --}}
        <div class="flex-1 min-w-0 w-full lg:order-1 lg:h-full flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm transition-all duration-300
                    [container-type:inline-size] [container-name:ae-tbl]
                    max-lg:h-[calc(100dvh-360px)] max-lg:max-h-[calc(100dvh-360px)] max-lg:min-h-[380px]
                    max-sm:h-[calc(100dvh-380px)] max-sm:max-h-[calc(100dvh-380px)] max-sm:min-h-[360px]"
             x-data="{ fullscreen: false }"
             :class="fullscreen ? 'fixed! inset-0! z-[999]! h-dvh! max-h-dvh! min-h-dvh! w-screen! rounded-none! border-none!' : ''"
             @keydown.escape.window="fullscreen = false">

            {{-- FILTER BAR --}}
            <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
                 wire:loading.class="opacity-60"
                 wire:target="search,filterStatuses,filterBatchFrom,filterBatchTo,filterCourse,clearFilters">

                <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                    Filters
                </div>

                {{-- Search --}}
                <div class="relative flex-1 min-w-[160px] max-w-xs"
                     wire:ignore
                     x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#555555] z-[1]"></i>
                    <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                           :class="q !== '' ? 'border-[#7a3f91] bg-[#EFF6FF] text-[#1D4ED8] font-semibold' : 'bg-white text-[#333333] font-medium'"
                           placeholder="Search name, ID, email or job…"
                           class="w-full border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 pr-4 pl-9 rounded-lg placeholder:text-[#999999] placeholder:font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"
                           autocomplete="off" maxlength="100" spellcheck="false">
                </div>

                {{-- Status — MULTI-SELECT with real checkboxes. Checking a
                     box toggles that status in/out of filterStatuses via
                     toggleFilterStatus(); the dropdown stays open across
                     clicks so e.g. "Employed" + "Self-Employed" can be
                     checked together. A "Select All" checkbox sits in a
                     sticky header row at the TOP of the list — tri-state:
                     checked when every status is selected, indeterminate
                     (dash) when some but not all are. --}}
                @php $aeStatusOptions = [
                    ['employed', 'Employed'],
                    ['self_employed', 'Self-Employed'],
                    ['unemployed', 'Unemployed'],
                    ['not_filled', 'Not Filled'],
                ]; @endphp
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click.stop="open = !open"
                            class="border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 px-3 rounded-lg font-medium inline-flex items-center gap-2 min-w-[130px] justify-between cursor-pointer hover:border-[#c4b5d4] focus:outline-none
                                   {{ $filterStatuses ? 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold' : 'text-[#333333] bg-white' }}">
                        <span class="truncate text-sm">
                            @if(count($filterStatuses) === 0)
                                All Statuses
                            @elseif(count($filterStatuses) === 1)
                                {{ $filterStatuses[0] === 'not_filled' ? 'Not Filled' : ucwords(str_replace('_', '-', $filterStatuses[0])) }}
                            @else
                                {{ count($filterStatuses) }} Statuses
                            @endif
                        </span>
                        <i class="fas fa-chevron-down text-xs flex-shrink-0 transition-transform duration-150"
                           :class="open ? 'rotate-180' : ''"></i>
                    </button>
                    <div x-show="open"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute top-full left-0 mt-1 z-50 bg-white border border-[#E8E0F0] rounded-xl shadow-xl overflow-hidden min-w-[180px] max-h-[220px] overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]"
                         @click.stop>
                        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-[#E8E0F0] sticky -top-1 -mx-0 -mt-0 bg-white z-10">
                            <label class="flex items-center gap-2 text-xs font-semibold text-[#333333] cursor-pointer select-none">
                                <input type="checkbox"
                                       :checked="$wire.filterStatuses.length === {{ count($aeStatusOptions) }}"
                                       :indeterminate="$wire.filterStatuses.length > 0 && $wire.filterStatuses.length < {{ count($aeStatusOptions) }}"
                                       @change="$event.target.checked ? $wire.selectAllFilterStatuses() : $wire.clearFilterStatuses()"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer">
                                Select All
                            </label>
                            <span class="text-xs font-bold text-[#7a3f91] select-none" x-show="$wire.filterStatuses.length > 0">
                                <span x-text="$wire.filterStatuses.length"></span> selected
                            </span>
                        </div>
                        <div class="py-1">
                            @foreach($aeStatusOptions as [$val, $label])
                            <label class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium cursor-pointer select-none hover:bg-purple-50 hover:text-purple-700 transition-colors
                                          {{ in_array($val, $filterStatuses, true) ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]' }}">
                                <input type="checkbox" wire:click="toggleFilterStatus('{{ $val }}')"
                                       :checked="$wire.filterStatuses.includes('{{ $val }}')"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer shrink-0">
                                {{ $label }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Programs (formerly "Courses") --}}
                @if($courses->isNotEmpty())
                    <select wire:model.live="filterCourse"
                            class="border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 px-3 rounded-lg font-medium appearance-none cursor-pointer bg-no-repeat pr-9 hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                                   {{ $filterCourse ? 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold' : 'text-[#333333] bg-white' }}"
                            style="background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E&quot;); background-position:right .6rem center; background-size:1.25em 1.25em;">
                        <option value="">All Programs</option>
                        @foreach($courses as $c)
                            <option value="{{ $c->code }}">{{ $c->name ?? $c->code }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Batch — FROM/TO range. Default view is a plain year
                     list (click a year, done); "Add Range" swaps to two
                     side-by-side scrollable year lists so a range like
                     2020–2024 is opt-in rather than always-on. Range is
                     all-or-nothing — picking only From (or only To)
                     doesn't filter anything yet, and the trigger label
                     says so explicitly. --}}
                @if($batches->isNotEmpty())
                    <div class="relative"
                         x-data="{
                            open: false,
                            rangeMode: {{ ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) ? 'true' : 'false' }},
                            rangeFrom: '{{ $filterBatchFrom }}',
                            rangeTo: '{{ $filterBatchTo }}',
                            selectYear(val){ $wire.setSingleBatchYear(val); this.open=false; },
                            clearYear(){ this.rangeFrom=''; this.rangeTo=''; $wire.clearFilterBatch(); this.open=false; },
                            startRange(){ this.rangeFrom=$wire.filterBatchFrom||''; this.rangeTo=$wire.filterBatchTo||''; this.rangeMode=true; },
                            pickFrom(val){ this.rangeFrom=val; this.applyRangeIfComplete(); },
                            pickTo(val){ this.rangeTo=val; this.applyRangeIfComplete(); },
                            applyRangeIfComplete(){ if(this.rangeFrom!=='' && this.rangeTo!==''){ $wire.setBatchRange(this.rangeFrom, this.rangeTo); this.open=false; } }
                         }"
                         @click.outside="open = false">
                        <button type="button"
                                @click="open = !open"
                                class="border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 px-3 rounded-lg font-medium inline-flex items-center gap-2 min-w-[130px] justify-between cursor-pointer hover:border-[#c4b5d4] focus:outline-none
                                       {{ ($filterBatchFrom !== '' || $filterBatchTo !== '') ? 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold' : 'text-[#333333] bg-white' }}">
                            <span class="truncate text-sm">
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
                            <i class="fas fa-chevron-down text-xs flex-shrink-0 transition-transform duration-150"
                               :class="open ? 'rotate-180' : ''"></i>
                        </button>
                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute top-full left-0 mt-1 z-50 bg-white border border-[#E8E0F0] rounded-xl shadow-xl overflow-hidden min-w-[150px]"
                             @click.stop>

                            {{-- Default view: plain year list --}}
                            <template x-if="!rangeMode">
                                <div class="py-1 max-h-[180px] overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]">
                                    <button type="button" @click.stop="clearYear()"
                                            class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors
                                                   {{ ($filterBatchFrom === '' && $filterBatchTo === '') ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]' }}">
                                        All Batches
                                    </button>
                                    @foreach($batches as $b)
                                        <button type="button" @click.stop="selectYear('{{ $b }}')"
                                                class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors
                                                       {{ ($filterBatchFrom == $b && $filterBatchTo == $b) ? 'bg-purple-100 text-purple-800 font-semibold' : 'text-[#333333]' }}">
                                            Batch {{ $b }}
                                        </button>
                                    @endforeach
                                    <div class="h-px bg-[#E8E0F0] my-1"></div>
                                    <button type="button" @click.stop="startRange()"
                                            class="w-full text-left px-3 py-2 text-sm font-semibold flex items-center gap-1.5 text-[#7a3f91] hover:bg-purple-50 transition-colors">
                                        <i class="fas fa-plus" style="font-size:10px;"></i> Add Range
                                    </button>
                                </div>
                            </template>

                            {{-- Range view: two side-by-side scrollable
                                 year lists. Local Alpine state only —
                                 nothing sent to the server until both
                                 sides are picked. --}}
                            <template x-if="rangeMode">
                                <div class="p-2" style="width:220px;">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="flex-1 text-[10px] font-bold uppercase tracking-wide text-[#7a3f91]">From</span>
                                        <span class="flex-1 text-[10px] font-bold uppercase tracking-wide text-[#7a3f91]">To</span>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:150px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                            @foreach($batches as $b)
                                            <button type="button" @click.stop="pickFrom('{{ $b }}')"
                                                    :class="{'bg-purple-100 text-purple-800 font-semibold':rangeFrom==='{{ $b }}'}"
                                                    class="w-full text-left px-2.5 py-1.5 text-xs font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors text-[#333333]">{{ $b }}</button>
                                            @endforeach
                                        </div>
                                        <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:150px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                            @foreach($batches as $b)
                                            <button type="button" @click.stop="pickTo('{{ $b }}')"
                                                    :class="{'bg-purple-100 text-purple-800 font-semibold':rangeTo==='{{ $b }}'}"
                                                    class="w-full text-left px-2.5 py-1.5 text-xs font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors text-[#333333]">{{ $b }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 mt-3">
                                        <button type="button" @click.stop="rangeMode=false"
                                                class="flex-1 text-xs font-semibold text-[#333333] hover:bg-[#F5F5F5] rounded-lg py-1.5 transition-colors border border-[#E8E0F0]">
                                            Back to List
                                        </button>
                                        <button type="button" @click.stop="clearYear(); rangeMode=false;"
                                                class="flex-1 text-xs font-semibold text-[#7a3f91] hover:bg-[#F5F0FA] rounded-lg py-1.5 transition-colors border border-[#E8E0F0]">
                                            Clear
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif

                {{-- Reset --}}
                <button wire:click="clearFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="clearFilters"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer text-[#333333]">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>

                {{-- ── Active filter badges ── shows which filters are
                     applied and how many results each narrows down to,
                     sitting right beside Reset in the same filter row.
                     Only rendered when at least one filter is active. --}}
                @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourse)
                @if($search !== '')
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7a3f91] border-[#E8E0F0]">
                    <i class="fas fa-search text-[10px]"></i>"{{ $search }}"
                    &mdash; {{ number_format($rows->total()) }} result(s)
                </span>
                @endif
                @if(!empty($filterStatuses))
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7a3f91] border-[#E8E0F0]">
                    <i class="fas fa-briefcase text-[10px]"></i>
                    @if(count($filterStatuses) === 1)
                        {{ $filterStatuses[0] === 'not_filled' ? 'Not Filled' : ucwords(str_replace('_', '-', $filterStatuses[0])) }}
                    @else
                        {{ count($filterStatuses) }} Statuses
                    @endif
                    &mdash; {{ number_format($rows->total()) }} result(s)
                </span>
                @endif
                @if($filterCourse !== '')
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7a3f91] border-[#E8E0F0]">
                    <i class="fas fa-graduation-cap text-[10px]"></i>{{ $filterCourse }}
                    &mdash; {{ number_format($rows->total()) }} result(s)
                </span>
                @endif
                @if($filterBatchFrom !== '' && $filterBatchTo !== '')
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7a3f91] border-[#E8E0F0]">
                    <i class="fas fa-calendar text-[10px]"></i>{{ $filterBatchFrom === $filterBatchTo ? 'Batch ' . $filterBatchFrom : 'Batch ' . $filterBatchFrom . '–' . $filterBatchTo }}
                    &mdash; {{ number_format($rows->total()) }} result(s)
                </span>
                @endif
                @endif

                {{-- Fullscreen toggle — mobile & tablet only. Expands the
                     alumni table to cover the whole screen so it's easier
                     to browse the list on smaller devices. Hidden on
                     desktop (lg+) since the table already has plenty of
                     room there. --}}
                <button type="button"
                        @click="fullscreen = !fullscreen"
                        class="lg:hidden inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer ml-auto text-[#7a3f91]">
                    <i class="fas" :class="fullscreen ? 'fa-compress' : 'fa-expand'"></i>
                    <span x-text="fullscreen ? 'Exit' : 'Full Screen'"></span>
                </button>
            </div>

            <div class="relative flex flex-col flex-1 min-h-0">

                {{-- Centered loading spinner — big icon over the table itself,
                     same pattern as Job Management / Event Organizer. --}}
                <div class="absolute inset-0 z-20 items-center justify-center hidden"
                     wire:loading.flex wire:target="search,filterStatuses,filterBatchFrom,filterBatchTo,filterCourse,clearFilters,previousPage,nextPage">
                    <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
                </div>

                @if($rows->count() > 0)
                <div class="overflow-x-hidden overflow-y-auto flex-1 min-h-0 bg-white
                            [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f3f4f6]
                            [&::-webkit-scrollbar]:w-[5px]
                            [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                            [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                            hover:[&::-webkit-scrollbar-thumb]:bg-[#7a3f91]"
                     wire:loading.class="opacity-40 pointer-events-none"
                     wire:target="search,filterStatuses,filterBatchFrom,filterBatchTo,filterCourse,clearFilters,previousPage,nextPage">

                    {{-- ── DESKTOP / TABLET: table view ── --}}
                    <table class="w-full bg-white border-collapse hidden md:table">
                        <thead class="sticky top-0 z-10 bg-white shadow-[0_1px_0_#E8E0F0]">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Alumni</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Program</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555] hidden @[660px]:table-cell">Batch</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555] hidden @[860px]:table-cell">Job Title</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555] hidden @[1120px]:table-cell">Email Address</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555] hidden @[980px]:table-cell">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F5F5F5]">

                            @foreach($rows as $row)
                            @php
                                $statusClass = match($row->employment_status) {
                                    'employed'      => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    'self_employed' => 'border-blue-200 bg-blue-50 text-blue-700',
                                    'unemployed'    => 'border-amber-200 bg-amber-50 text-amber-700',
                                    default         => 'border-[#E8E0F0] bg-[#F9F7FC] text-[#999999]',
                                };
                                $statusLabel = match($row->employment_status) {
                                    'employed'      => 'Employed',
                                    'self_employed' => 'Self-Employed',
                                    'unemployed'    => 'Unemployed',
                                    default         => 'Not Filled',
                                };
                                $statusIcon = match($row->employment_status) {
                                    'employed'      => 'fa-briefcase',
                                    'self_employed' => 'fa-store',
                                    'unemployed'    => 'fa-circle-pause',
                                    default         => 'fa-circle-question',
                                };

                                $photoPath = $row->profile_photo ?? null;
                                $photoUrl  = (!$photoPath || str_contains($photoPath, 'default.png'))
                                    ? asset('storage/alumni-photos/default.png')
                                    : (
                                        (str_starts_with($photoPath, 'alumni-photos/') || str_starts_with($photoPath, 'organizers/'))
                                        ? asset('storage/' . $photoPath)
                                        : asset('storage/alumni-photos/default.png')
                                    );

                            @endphp

                            <tr class="bg-white cursor-pointer transition-colors duration-150 hover:bg-[#f5f0fa]"
                                wire:click="viewDetail({{ $row->id }})"
                                wire:key="ae-row-{{ $row->id }}"
                                data-ae-row>

                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $photoUrl }}"
                                             alt="{{ $row->full_name }}"
                                             class="w-9 h-9 rounded-xl object-cover flex-shrink-0 shadow ring-1 ring-[#E8E0F0]">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-sm leading-snug truncate uppercase text-[#333333]">{{ $row->full_name }}</p>

                                            {{-- Compact inline row for info hidden at this container width.
                                                 Status badge and job title/unemployment note both collapse
                                                 into this line instead of fighting for their own columns,
                                                 so nothing overlaps on tablet widths. --}}
                                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                                <span class="inline-flex @[980px]:hidden items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-semibold border {{ $statusClass }}">
                                                    <i class="fa-solid {{ $statusIcon }} text-[8px]"></i>
                                                    {{ $statusLabel }}
                                                </span>
                                                <span class="block @[860px]:hidden text-xs font-medium truncate max-w-[220px] text-[#555555]">
                                                    @if($row->job_title)
                                                        {{ $row->job_title }}
                                                    @elseif($row->employment_status === 'unemployed')
                                                        <span class="italic text-[#999999]">
                                                            {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '' }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-100 uppercase">
                                        {{ $row->course_code ?? '—' }}
                                    </span>
                                </td>

                                <td class="px-4 py-3.5 text-sm font-semibold hidden @[660px]:table-cell text-[#333333]">
                                    {{ $row->batch ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 hidden @[860px]:table-cell">
                                    @if($row->job_title)
                                        <p class="font-semibold text-sm leading-snug uppercase text-[#333333]">{{ $row->job_title }}</p>
                                    @elseif($row->employment_status === 'unemployed')
                                        <span class="text-sm italic text-[#999999]">
                                            {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '—' }}
                                        </span>
                                    @else
                                        <span class="text-sm italic text-[#cccccc]">No data yet</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3.5 hidden @[1120px]:table-cell">
                                    @if($row->email ?? null)
                                        <p class="text-sm font-medium truncate max-w-[200px] text-[#333333]" title="{{ $row->email }}">
                                            {{ $row->email }}
                                        </p>
                                    @else
                                        <span class="text-sm text-[#cccccc]">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3.5 text-center hidden @[980px]:table-cell">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-semibold border {{ $statusClass }} whitespace-nowrap">
                                        <i class="fa-solid {{ $statusIcon }} text-[9px]"></i>
                                        {{ $statusLabel }}
                                    </span>
                                </td>

                            </tr>
                            @endforeach

                        </tbody>
                    </table>

                    {{-- ── MOBILE: stacked card list (no horizontal scroll, nothing hidden) ── --}}
                    <div class="block md:hidden">
                        @foreach($rows as $row)
                        @php
                            $statusClass = match($row->employment_status) {
                                'employed'      => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                'self_employed' => 'border-blue-200 bg-blue-50 text-blue-700',
                                'unemployed'    => 'border-amber-200 bg-amber-50 text-amber-700',
                                default         => 'border-[#E8E0F0] bg-[#F9F7FC] text-[#999999]',
                            };
                            $statusLabel = match($row->employment_status) {
                                'employed'      => 'Employed',
                                'self_employed' => 'Self-Employed',
                                'unemployed'    => 'Unemployed',
                                default         => 'Not Filled',
                            };
                            $statusIcon = match($row->employment_status) {
                                'employed'      => 'fa-briefcase',
                                'self_employed' => 'fa-store',
                                'unemployed'    => 'fa-circle-pause',
                                default         => 'fa-circle-question',
                            };

                            $photoPath = $row->profile_photo ?? null;
                            $photoUrl  = (!$photoPath || str_contains($photoPath, 'default.png'))
                                ? asset('storage/alumni-photos/default.png')
                                : (
                                    (str_starts_with($photoPath, 'alumni-photos/') || str_starts_with($photoPath, 'organizers/'))
                                    ? asset('storage/' . $photoPath)
                                    : asset('storage/alumni-photos/default.png')
                                );
                        @endphp

                        <div class="cursor-pointer select-none bg-white border-b border-[#F5F5F5] px-3.5 py-3 flex items-center gap-2.5 transition-colors duration-100 active:bg-[#f5f0fa]"
                             wire:click="viewDetail({{ $row->id }})"
                             wire:key="ae-mrow-{{ $row->id }}"
                             data-ae-row>

                            <img src="{{ $photoUrl }}"
                                 alt="{{ $row->full_name }}"
                                 class="w-10 h-10 rounded-lg object-cover flex-shrink-0 ring-1 ring-[#E8E0F0]">

                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm uppercase truncate text-[#333333]">{{ $row->full_name }}</p>

                                <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">
                                        {{ $row->course_code ?? '—' }}
                                    </span>
                                    <span class="text-[#CCCCCC] text-xs">&bull;</span>
                                    <span class="font-mono text-xs font-semibold text-[#666666]">Batch {{ $row->batch ?? '—' }}</span>
                                </div>

                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-semibold border {{ $statusClass }}">
                                        <i class="fa-solid {{ $statusIcon }} text-[8px]"></i>
                                        {{ $statusLabel }}
                                    </span>
                                    @if($row->job_title)
                                        <span class="text-xs font-medium truncate text-[#555555]">{{ $row->job_title }}</span>
                                    @elseif($row->employment_status === 'unemployed')
                                        <span class="text-xs italic text-[#999999]">
                                            {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '' }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <i class="fas fa-chevron-right text-[#CCCCCC] text-xs shrink-0"></i>
                        </div>
                        @endforeach
                    </div>

                </div>

                @else
                <div class="flex-1 min-h-0 flex flex-col items-center justify-center gap-4 text-center px-6 bg-white">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fas fa-users-slash text-xl text-gray-400"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-base text-[#333333]">
                            @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourse)
                                No alumni match your filters
                            @else
                                No alumni found
                            @endif
                        </p>
                        <p class="text-sm mt-1 text-[#555555]">
                            @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourse)
                                Try clearing your filters to see all alumni.
                            @else
                                No verified alumni are registered under your college yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourse)
                        <button wire:click="clearFilters"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91]">
                            <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                        </button>
                    @endif
                </div>
                @endif
            </div>

            {{-- PAGINATION --}}
            @php
                $total   = $rows->total();
                $pp      = $rows->perPage();
                $cp      = $rows->currentPage();
                $lp      = $rows->lastPage();
                $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to      = min($cp * $pp, $total);
                $pgStart = max(1, $cp - 2);
                $pgEnd   = min($lp, $cp + 2);
            @endphp
            <div class="flex-shrink-0 bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] px-4 min-h-[48px] flex items-center justify-between gap-2 flex-wrap border-t border-[#7a3f91]/30">
                <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                    Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                    of <strong class="text-white font-bold">{{ $total }}</strong>
                    alumni
                    @if($filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourse || $search)
                        <span class="text-white/50 text-xs ml-1">(filtered)</span>
                    @endif
                </p>

                <div class="flex items-center gap-1 flex-wrap py-2">
                    <button wire:click="previousPage"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white
                                   hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                            @if($rows->onFirstPage()) disabled @endif aria-label="Previous">
                        <i class="fas fa-chevron-left text-[9px]"></i>
                    </button>

                    @if($pgStart > 1)
                        <button wire:click="$set('page', 1)"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                        @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    @endif

                    @for($p = $pgStart; $p <= $pgEnd; $p++)
                        @if($p === $cp)
                            <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                         bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                        @else
                            <button wire:click="$set('page', {{ $p }})"
                                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                           bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                        @endif
                    @endfor

                    @if($pgEnd < $lp)
                        @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                        <button wire:click="$set('page', {{ $lp }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                    @endif

                    <button wire:click="nextPage"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white
                                   hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                            @if(!$rows->hasMorePages()) disabled @endif aria-label="Next">
                        <i class="fas fa-chevron-right text-[9px]"></i>
                    </button>

                    <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                        Page {{ $cp }}/{{ $lp }}
                    </span>
                </div>
            </div>

        </div>{{-- /table block --}}

    </div>{{-- /BODY: side cards + table --}}

</div>{{-- /main layout --}}


{{-- DETAIL MODAL --}}
@if($showModal && !empty($modalData))
@php
    $md        = $modalData;
    $isEmp     = in_array($md['employment_status'] ?? '', ['employed','self_employed']);
    $statusLbl = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'][$md['employment_status'] ?? ''] ?? 'Not Filled';
    $statusCls = match($md['employment_status'] ?? '') {
        'employed'      => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        'self_employed' => 'bg-blue-50 text-blue-700 border border-blue-200',
        'unemployed'    => 'bg-amber-50 text-amber-700 border border-amber-200',
        default         => 'bg-[#F9F7FC] text-[#999999] border border-[#E8E0F0]',
    };
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $eduMap = [
        'none'               => 'None',
        'pursuing_masteral'  => 'Pursuing Masteral',
        'pursuing_doctorate' => 'Pursuing Doctorate',
    ];
    $careerLabels = [
        'ofw'                   => ['fa-plane-departure', 'OFW'],
        'freelancer'            => ['fa-laptop-code',     'Freelancer'],
        'entrepreneur'          => ['fa-store',           'Entrepreneur'],
        'career_shifter'        => ['fa-arrows-rotate',   'Career Shifter'],
        'industry_professional' => ['fa-user-tie',        'Industry Professional'],
    ];
    $relModalMap = [
        'yes'       => ['Related to Program', 'fa-check-circle', 'text-emerald-700', 'bg-emerald-50 border-emerald-200'],
        'no'        => ['Not Related',        'fa-times-circle', 'text-red-600',     'bg-red-50 border-red-200'],
        'partially' => ['Partially Related',  'fa-adjust',       'text-amber-700',   'bg-amber-50 border-amber-200'],
    ];
    $relModal = $relModalMap[$md['course_relevance'] ?? ''] ?? null;

    $modalPhotoPath = $md['profile_photo'] ?? null;
    $modalPhotoUrl  = (!$modalPhotoPath || str_contains($modalPhotoPath, 'default.png'))
        ? asset('storage/alumni-photos/default.png')
        : (
            (str_starts_with($modalPhotoPath, 'alumni-photos/') || str_starts_with($modalPhotoPath, 'organizers/'))
            ? asset('storage/' . $modalPhotoPath)
            : asset('storage/alumni-photos/default.png')
        );
@endphp
{{-- x-data + wire:click.self on the backdrop lets Alpine manage this element
     properly (fixes the escape-key/$wire scope) and lets clicking the dark
     backdrop close the modal too. @click.stop on the inner card stops that
     backdrop handler from firing when you click inside the card itself, so
     it never intercepts clicks meant for the X button. --}}
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-0 sm:p-4"
     x-data
     wire:click.self="closeModal"
     @keydown.escape.window="$wire.closeModal()">
    {{-- On mobile (below sm) this is a full-screen sheet, not a floating
         modal: no rounded corners, no border, fills the entire viewport.
         From sm and up it goes back to being a centered, rounded modal
         card capped at max-w-lg / 90vh. --}}
    <div class="bg-white rounded-none sm:rounded-2xl w-full h-full sm:h-auto sm:max-w-lg max-h-[100dvh] sm:max-h-[90vh] flex flex-col overflow-hidden shadow-2xl border-0 sm:border sm:border-[#E8E0F0]"
         @click.stop
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 sm:scale-95 translate-y-4 sm:translate-y-2"
         x-transition:enter-end="opacity-100 sm:scale-100 translate-y-0">

        <div class="flex items-center justify-between px-5 py-4 border-b border-[#E8E0F0] flex-shrink-0 bg-[#7A3F91]">
            <div class="flex items-center gap-3">
                <img src="{{ $modalPhotoUrl }}"
                     alt="{{ $modalData['full_name'] ?? '' }}"
                     class="w-10 h-10 rounded-xl object-cover flex-shrink-0 ring-2 ring-white/30">
                <div>
                    <p class="font-semibold text-white text-sm leading-snug uppercase">
                        {{ $modalData['full_name'] ?? '—' }}
                        @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
                    </p>
                </div>
            </div>
            <button type="button"
                    wire:click="closeModal"
                    wire:loading.attr="disabled"
                    wire:target="closeModal"
                    class="relative group/close w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-base" wire:loading.remove wire:target="closeModal"></i>
                <i class="fa-solid fa-spinner fa-spin text-base" wire:loading wire:target="closeModal"></i>
                <span class="pointer-events-none absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-neutral-900 text-white text-[10px] font-semibold px-2 py-1 rounded-md whitespace-nowrap opacity-0 group-hover/close:opacity-100 transition-opacity duration-150">Close</span>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-5 [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]">

            <div>
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest mb-3">Student Information</p>
                <div class="grid grid-cols-3 gap-2 mb-2">
                    @foreach([
                        'Program' => $md['course_code']    ?? '—',
                        'Batch'   => $md['batch']          ?? '—',
                        'Contact' => $md['contact_number'] ?? '—',
                    ] as $label => $value)
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">{{ $label }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $value ?: '—' }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                    <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Email Address</p>
                    <p class="text-sm font-semibold text-[#333333] break-all">{{ $md['email'] ?? '—' }}</p>
                </div>
            </div>

            <div class="border-t border-[#E8E0F0] pt-4">
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest mb-3">Employment Information</p>
                <div class="flex items-center gap-2 mb-4 flex-wrap">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold {{ $statusCls }}">
                        {{ $statusLbl }}
                    </span>
                    @if($md['emp_updated_at'] ?? null)
                        <span class="text-xs text-[#999999]">
                            <i class="fa-regular fa-clock mr-1"></i>
                            Updated {{ \Carbon\Carbon::parse($md['emp_updated_at'])->diffForHumans() }}
                        </span>
                    @endif
                </div>

                @if($isEmp)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach([
                            ['Company',    $md['company_name']  ?? '—'],
                            ['Job Title',  $md['job_title']     ?? '—'],
                            ['Type',       $empTypeMap[$md['employment_type'] ?? ''] ?? '—'],
                            ['Location',   ucfirst($md['work_location'] ?? '—')],
                            ['Date Hired', $md['date_hired'] ? \Carbon\Carbon::parse($md['date_hired'])->format('M d, Y') : '—'],
                            ['Education',  $eduMap[$md['education_status'] ?? ''] ?? '—'],
                        ] as [$lbl, $val])
                            <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                                <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-[#333333]">{{ $val }}</p>
                            </div>
                        @endforeach
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0] sm:col-span-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-1.5">Job Related to Program?</p>
                            @if($relModal)
                                <p class="text-sm font-semibold text-[#333333]">{{ $relModal[0] }}</p>
                            @else
                                <p class="text-sm text-[#999999]">— Not specified</p>
                            @endif
                        </div>
                    </div>
                    @if(!empty($md['career_path_arr']))
                        <div class="mt-4">
                            <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest mb-2">Career Path</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($md['career_path_arr'] as $cp)
                                    @if(isset($careerLabels[$cp]))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold bg-gray-50 text-[#333333] border border-[#E8E0F0]">
                                            {{ $careerLabels[$cp][1] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @elseif(($md['employment_status'] ?? '') === 'unemployed')
                    <div class="space-y-3">
                        <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Unemployment Status</p>
                            <p class="text-sm font-semibold text-[#333333]">
                                {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$md['unemployment_status'] ?? ''] ?? '—' }}
                            </p>
                        </div>
                        <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Education Status</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $eduMap[$md['education_status'] ?? ''] ?? '—' }}</p>
                        </div>
                    </div>
                @else
                    <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-8 text-center">
                        <p class="text-sm font-semibold text-[#999999]">No employment record yet.</p>
                        <p class="text-xs text-[#CCCCCC] mt-1">This alumni has not filled in their employment information.</p>
                    </div>
                @endif
            </div>

        </div>
        <div class="flex-shrink-0 h-0"></div>
    </div>
</div>
@endif


{{-- COMPARE TOOL MODAL — bigger fixed height on desktop so everything
     (form + results + insight) fits without needing to scroll, full
     screen on mobile. --}}
@if($showCompareModal)
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-0 sm:p-4"
     x-data
     wire:click.self="closeCompareModal"
     @keydown.escape.window="$wire.closeCompareModal()">
    <div class="bg-white rounded-none sm:rounded-2xl w-full h-full sm:h-[760px] sm:max-w-3xl sm:max-h-[92vh] flex flex-col overflow-hidden shadow-2xl border-0 sm:border sm:border-[#E8E0F0]"
         @click.stop
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 sm:scale-95 translate-y-4 sm:translate-y-2"
         x-transition:enter-end="opacity-100 sm:scale-100 translate-y-0">

        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-4 border-b border-[#E8E0F0] flex-shrink-0 bg-gradient-to-r from-[#7a3f91] to-[#9b59b6]">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-code-compare text-white text-sm"></i>
                </div>
                <div>
                    <p class="font-semibold text-white text-sm leading-snug">Employment Compare Tool</p>
                    <p class="text-xs text-white/70 mt-0.5">Compare programs or batches side by side</p>
                </div>
            </div>
            <button type="button"
                    wire:click="closeCompareModal"
                    wire:loading.attr="disabled" wire:target="closeCompareModal"
                    class="relative group/close2 w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-base" wire:loading.remove wire:target="closeCompareModal"></i>
                <i class="fa-solid fa-spinner fa-spin text-base" wire:loading wire:target="closeCompareModal"></i>
                <span class="hidden sm:block pointer-events-none absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-neutral-900 text-white text-[10px] font-semibold px-2 py-1 rounded-md whitespace-nowrap opacity-0 group-hover/close2:opacity-100 transition-opacity duration-150">Close</span>
            </button>
        </div>

        {{-- Body — the modal itself is now tall enough (h-[760px]) that
             the form + results + insight all fit without scrolling in
             the common case; overflow-y-auto stays only as a safety net
             for smaller screens or extra-long content. --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4 [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]">

            {{-- Validation error banner --}}
            @if($compareError)
                <div class="flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3">
                    <i class="fa-solid fa-triangle-exclamation text-red-500 text-sm mt-0.5 flex-shrink-0"></i>
                    <p class="text-sm font-medium text-red-700 leading-snug">{{ $compareError }}</p>
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                {{-- GROUP A --}}
                <div class="bg-[#F9F7FC] rounded-xl border border-[#E8E0F0] p-4">
                    <p class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] mb-3 flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full bg-[#7a3f91] text-white flex items-center justify-center text-[10px]">A</span>
                        Group A
                    </p>
                    <label class="block text-xs font-semibold text-[#555555] mb-1">Program</label>
                    <select wire:model.live="compareCourseA"
                            class="w-full border border-[#E8E0F0] bg-white text-sm py-2 px-3 rounded-lg font-medium mb-3 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <option value="">All Programs</option>
                        @foreach($courses as $c)
                            <option value="{{ $c->code }}">{{ $c->name ?? $c->code }}</option>
                        @endforeach
                    </select>
                    <label class="block text-xs font-semibold text-[#555555] mb-1">Batch</label>
                    <select wire:model.live="compareBatchA"
                            class="w-full border border-[#E8E0F0] bg-white text-sm py-2 px-3 rounded-lg font-medium focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <option value="">All Batches</option>
                        @foreach($this->batchesForCourse($compareCourseA) as $b)
                            <option value="{{ $b }}">Batch {{ $b }}</option>
                        @endforeach
                    </select>
                    @if(empty($this->batchesForCourse($compareCourseA)))
                        <p class="text-xs text-[#999999] mt-1.5 italic">No batches available for this program yet.</p>
                    @endif
                </div>

                {{-- GROUP B --}}
                <div class="bg-[#F9F7FC] rounded-xl border border-[#E8E0F0] p-4">
                    <p class="text-xs font-bold uppercase tracking-widest text-[#9b59b6] mb-3 flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full bg-[#9b59b6] text-white flex items-center justify-center text-[10px]">B</span>
                        Group B
                    </p>
                    <label class="block text-xs font-semibold text-[#555555] mb-1">Program</label>
                    <select wire:model.live="compareCourseB"
                            class="w-full border border-[#E8E0F0] bg-white text-sm py-2 px-3 rounded-lg font-medium mb-3 focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-[#9b59b6]/10">
                        <option value="">All Programs</option>
                        @foreach($courses as $c)
                            <option value="{{ $c->code }}">{{ $c->name ?? $c->code }}</option>
                        @endforeach
                    </select>
                    <label class="block text-xs font-semibold text-[#555555] mb-1">Batch</label>
                    <select wire:model.live="compareBatchB"
                            class="w-full border border-[#E8E0F0] bg-white text-sm py-2 px-3 rounded-lg font-medium focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-[#9b59b6]/10">
                        <option value="">All Batches</option>
                        @foreach($this->batchesForCourse($compareCourseB) as $b)
                            <option value="{{ $b }}">Batch {{ $b }}</option>
                        @endforeach
                    </select>
                    @if(empty($this->batchesForCourse($compareCourseB)))
                        <p class="text-xs text-[#999999] mt-1.5 italic">No batches available for this program yet.</p>
                    @endif
                </div>
            </div>

            {{-- Results --}}
            @if($compareRan)
                <div class="border-t border-[#E8E0F0] pt-4 space-y-4">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {{-- Result A --}}
                        <div class="rounded-xl border border-[#E8E0F0] p-4 {{ ($compareResultA['rate'] ?? 0) >= ($compareResultB['rate'] ?? 0) && ($compareResultA['total'] ?? 0) > 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-white' }}">
                            <p class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] mb-1">{{ $compareResultA['course'] ?? '—' }} · {{ $compareResultA['batch'] ?? '—' }}</p>
                            <p class="text-3xl font-bold text-[#333333] leading-none">{{ $compareResultA['rate'] ?? 0 }}%</p>
                            <p class="text-xs text-[#666666] mt-1">Employment Rate</p>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Total Alumni</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultA['total'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Working</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultA['working'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Employed</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultA['employed'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Unemployed</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultA['unemployed'] ?? 0 }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Result B --}}
                        <div class="rounded-xl border border-[#E8E0F0] p-4 {{ ($compareResultB['rate'] ?? 0) > ($compareResultA['rate'] ?? 0) && ($compareResultB['total'] ?? 0) > 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-white' }}">
                            <p class="text-xs font-bold uppercase tracking-widest text-[#9b59b6] mb-1">{{ $compareResultB['course'] ?? '—' }} · {{ $compareResultB['batch'] ?? '—' }}</p>
                            <p class="text-3xl font-bold text-[#333333] leading-none">{{ $compareResultB['rate'] ?? 0 }}%</p>
                            <p class="text-xs text-[#666666] mt-1">Employment Rate</p>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Total Alumni</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultB['total'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Working</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultB['working'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Employed</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultB['employed'] ?? 0 }}</p>
                                </div>
                                <div class="bg-white/70 rounded-lg px-2 py-1.5 border border-black/5">
                                    <p class="text-[10px] uppercase font-semibold text-[#999999]">Unemployed</p>
                                    <p class="font-bold text-[#333333]">{{ $compareResultB['unemployed'] ?? 0 }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- AI-style simple insight, with highlighted text --}}
                    @if($compareInsight)
                        <div class="rounded-xl border border-purple-200 bg-gradient-to-br from-[#F9F7FC] to-purple-50 p-4 flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-wand-magic-sparkles text-white text-xs"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-[#7a3f91] mb-1">Insight</p>
                                <p class="text-sm leading-relaxed text-[#333333]">
                                    <mark class="bg-yellow-200/70 text-[#333333] px-1 rounded font-semibold">{{ $compareInsight }}</mark>
                                </p>
                            </div>
                        </div>
                    @endif

                </div>
            @endif

        </div>

        {{-- Actions — pinned at the very bottom of the modal, below the
             form and results, so the flow reads top-to-bottom: pick
             groups → see results → act (Compare again / Reset) last. --}}
        <div class="flex-shrink-0 flex items-center gap-2 px-5 py-4 border-t border-[#E8E0F0] bg-white">
            <button type="button"
                    wire:click="runCompare"
                    wire:loading.attr="disabled"
                    wire:target="runCompare"
                    class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white shadow-md transition active:scale-95 cursor-pointer bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] hover:brightness-110 disabled:opacity-60">
                <i class="fa-solid fa-chart-simple text-sm" wire:loading.remove wire:target="runCompare"></i>
                <i class="fa-solid fa-spinner fa-spin text-sm" wire:loading wire:target="runCompare"></i>
                Compare Now
            </button>
            <button type="button"
                    wire:click="resetCompare"
                    wire:loading.attr="disabled" wire:target="resetCompare"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer text-[#333333] disabled:opacity-60 disabled:cursor-wait">
                <i class="fas fa-rotate-left text-sm" wire:loading.remove wire:target="resetCompare"></i>
                <i class="fas fa-spinner fa-spin text-sm" wire:loading wire:target="resetCompare"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    // ── Row hover tooltip (desktop only) ────────────────────────────────────
    // isHoverCapable() requires BOTH a real hover-capable pointer (mouse)
    // AND a wider viewport, so on phones/tablets (touch input) this tip
    // never becomes visible — touch taps don't fire mousemove at all, so
    // there's no tooltip text shown on mobile.
    // NOTE: do NOT cache the tooltip element in a module-level var.
    // Livewire's morph can recreate #ae-hover-tip itself (e.g. when the
    // table flips from populated -> empty-state -> populated again after
    // a filter + clear-filter cycle), which leaves an old cached reference
    // pointing at a detached node — writes to it are silent no-ops, so the
    // tooltip stops appearing even though row binding still works fine.
    // Resolving it fresh on every mousemove/mouseleave/click is cheap and
    // guarantees we're always touching the node that's actually on screen.
    function getTip() {
        return document.getElementById('ae-hover-tip');
    }
    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }
    function bindRows() {
        document.querySelectorAll('[data-ae-row]').forEach(function (row) {
            if (row._aeTipBound) return;
            row._aeTipBound = true;
            row.addEventListener('mousemove', function (e) {
                var tip = getTip();
                if (!tip || !isHoverCapable()) return;
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.style.opacity = '1';
            });
            row.addEventListener('mouseleave', function () {
                var tip = getTip();
                if (tip) tip.style.opacity = '0';
            });
            row.addEventListener('click', function () {
                var tip = getTip();
                if (tip) tip.style.opacity = '0';
            });
        });
    }
    bindRows();
    document.addEventListener('livewire:updated', bindRows);

    // ── Employment update polling — every 15 seconds ───────────────────────
    // Calls the Livewire method checkEmploymentUpdates() on THIS component.
    //
    // We find this exact Volt component instance by walking up from this
    // <script> tag — which is always rendered inside this component's root
    // element — to the nearest [wire:id] ancestor. That is guaranteed to be
    // alumni-employment, instead of searching the whole page for a
    // "close enough" match.
    var _empPollTimer  = null;
    var _thisComponent = null;

    function resolveThisComponent() {
        if (_thisComponent) return _thisComponent;
        if (typeof Livewire === 'undefined') return null;

        var scriptEl = document.currentScript;
        var rootEl   = scriptEl ? scriptEl.closest('[wire\\:id]') : null;

        if (rootEl) {
            var id = rootEl.getAttribute('wire:id');
            try {
                var comp = Livewire.find(id);
                if (comp) {
                    _thisComponent = comp;
                    return comp;
                }
            } catch (e) { /* fall through to safe no-op below */ }
        }
        return null;
    }

    function pollOnce() {
        var comp = resolveThisComponent();
        if (!comp || typeof comp.call !== 'function') return;

        try {
            var result = comp.call('checkEmploymentUpdates');
            if (result && typeof result.catch === 'function') {
                result.catch(function () { /* silent — non-fatal poll miss */ });
            }
        } catch (e) {
            _thisComponent = null;
        }
    }

    function startEmpPolling() {
        if (_empPollTimer) clearInterval(_empPollTimer);
        _thisComponent = null; // re-resolve fresh after every (re)start
        _empPollTimer = setInterval(pollOnce, 15000); // poll every 15 seconds
    }

    document.addEventListener('livewire:initialized', startEmpPolling);
    document.addEventListener('livewire:navigated', startEmpPolling);
    if (typeof Livewire !== 'undefined') {
        setTimeout(startEmpPolling, 500);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            if (_empPollTimer) clearInterval(_empPollTimer);
        } else {
            startEmpPolling();
        }
    });
})();
</script>

</div>