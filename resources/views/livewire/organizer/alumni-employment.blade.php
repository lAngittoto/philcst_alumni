{{-- resources/views/livewire/organizer/alumni-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {

    use WithPagination, WithoutUrlPagination;

    protected string $paginationTheme = 'tailwind';

    // Keeps ?page= (and every other tracked prop) OUT of the URL — a
    // plain page refresh/link should always be the clean
    // /coordinator/alumni/employment, never
    // /coordinator/alumni/employment?page=1. WithoutUrlPagination
    // handles "page" specifically; this queryString() override covers
    // every other prop (search, filters, etc). Mirrors Alumni Records'
    // queryString() override.
    protected function queryString(): array { return []; }

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

    // Programs is now a MULTI-SELECT (array of checked course codes)
    // instead of a single-value dropdown, so several programs can be
    // viewed together — mirrors Alumni Records' alumniCourses.
    public array $filterCourses = [];

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

    // Signature of the currently-applied filters (search/status/programs/
    // batch range). computeStats() only needs to re-run when this changes
    // — plain pagination (next/prev/gotoPage) doesn't touch any filter,
    // so re-running 9 COUNT queries on every single page click was the
    // main reason pagination felt slow. Comparing this signature in
    // with() lets pagination clicks skip computeStats() entirely.
    public string $statsSignature = '';

    // ── tracks the last seen emp update timestamp so we only notify once ──
    public string $lastSeenEmpAt = '';

    // NOTE: search / filterBatchFrom / filterBatchTo / filterStatuses / filterCourses
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
            $course = $sessionFilter['course'] ?? '';
            $this->filterCourses  = $course !== '' ? [$course] : [];
        } elseif (is_string($sessionFilter) && $sessionFilter !== '') {
            // backward-compat: older single-value session payload
            $this->filterStatuses = [$sessionFilter];
        }

        // Seed the last-seen timestamp to "now" on first load
        // so we don't flood notifications for old records
        $this->lastSeenEmpAt = now()->toDateTimeString();

        // computeStats() no longer needs to run here — with() calls it
        // on every render (including the first), so cards are correct
        // from initial paint without doing the work twice.
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

    /** Human-readable summary of whatever filters are currently active,
     *  shown inside the Generate Reports dropdown ("Report will
     *  include…"). Only ACTIVE filters are listed now — an untouched
     *  filter group (Batch / Programs / Statuses) is simply omitted
     *  instead of showing as "All Batches" / "All Programs" / "All
     *  Statuses". If nothing at all is active (including search), the
     *  summary falls back to "No filters applied". */
    #[Computed]
    public function activeFilterSummary(): string
    {
        $parts = [];

        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $parts[] = $this->filterBatchFrom === $this->filterBatchTo
                ? 'Batch ' . $this->filterBatchFrom
                : 'Batch ' . $this->filterBatchFrom . '–' . $this->filterBatchTo;
        }

        if (!empty($this->filterCourses)) {
            $parts[] = implode(', ', $this->filterCourses);
        }

        if (!empty($this->filterStatuses)) {
            $labels = [
                'employed'      => 'Employed',
                'self_employed' => 'Self-Employed',
                'unemployed'    => 'Unemployed',
                'not_filled'    => 'Not Filled',
            ];
            $parts[] = implode(', ', array_map(fn($s) => $labels[$s] ?? $s, $this->filterStatuses));
        }

        if ($this->search !== '') {
            $parts[] = 'Search: "' . $this->search . '"';
        }

        return $parts ? implode(' · ', $parts) : 'No filters applied';
    }

    /** Count of records matching the currently-applied filters — shown
     *  next to the summary text in the Generate Reports dropdown. Uses
     *  the same scoping/filters as baseAlumniQuery() so the number the
     *  organizer sees before exporting always matches the export. */
    #[Computed]
    public function activeFilterCount(): int
    {
        return (clone $this->baseAlumniQuery())->count();
    }

    /** Wraps every occurrence of the current search term in a <mark> tag
     *  so it lights up light-blue in the table — mirrors highlight() in
     *  Alumni Records. Returns the text HTML-escaped either way, so this
     *  is always safe to output with {!! !!}. */
    public function highlight(?string $text, string $search): string
    {
        $text = $text ?? '';
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="ae-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
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

        // Cards reflect whatever is currently filtered — same scoping the
        // table itself uses (search / status / program / batch range) —
        // so the numbers on the side always match what's on screen.
        if ($this->search) {
            $s = '%' . $this->search . '%';
            $q->where(function ($w) use ($s) {
                $w->where(DB::raw("CONCAT(COALESCE(alumni.first_name,''), ' ', COALESCE(alumni.last_name,''))"), 'like', $s)
                  ->orWhere('alumni.student_id', 'like', $s)
                  ->orWhere('alumni.email', 'like', $s)
                  ->orWhereExists(function ($sub) use ($s) {
                      $sub->select(DB::raw(1))
                          ->from('employment_trackings as et_s')
                          ->whereColumn('et_s.alumni_id', 'alumni.id')
                          ->whereNull('et_s.deleted_at')
                          ->where(function ($w2) use ($s) {
                              $w2->where('et_s.company_name', 'like', $s)
                                 ->orWhere('et_s.job_title', 'like', $s);
                          });
                  });
            });
        }
        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $q->where('alumni.batch', '>=', $this->filterBatchFrom)
              ->where('alumni.batch', '<=', $this->filterBatchTo);
        }
        if (!empty($this->filterCourses)) {
            $q->whereIn('alumni.course_code', $this->filterCourses);
        }
        if (!empty($this->filterStatuses)) {
            $q->where(function ($w) {
                foreach ($this->filterStatuses as $status) {
                    if ($status === 'not_filled') {
                        $w->orWhereNotExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('employment_trackings as et_f')
                                ->whereColumn('et_f.alumni_id', 'alumni.id')
                                ->whereNull('et_f.deleted_at');
                        });
                    } else {
                        $w->orWhereExists(function ($sub) use ($status) {
                            $sub->select(DB::raw(1))
                                ->from('employment_trackings as et_f')
                                ->whereColumn('et_f.alumni_id', 'alumni.id')
                                ->whereNull('et_f.deleted_at')
                                ->where('et_f.employment_status', $status);
                        });
                    }
                }
            });
        }

        return $q;
    }

    public function with(): array
    {
        // Cards only need to recompute when a filter actually changed —
        // NOT on plain pagination (next/prev/gotoPage), which was
        // re-running all 9 COUNT queries on every page click for no
        // reason and made pagination feel slow.
        $currentSignature = json_encode([
            $this->search,
            $this->filterStatuses,
            $this->filterCourses,
            $this->filterBatchFrom,
            $this->filterBatchTo,
        ]);
        if ($currentSignature !== $this->statsSignature) {
            $this->statsSignature = $currentSignature;
            $this->computeStats();
        }

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
        if (!empty($this->filterCourses)) {
            $q->whereIn('a.course_code', $this->filterCourses);
        }

        if ($this->filterBatchFrom !== '' && $this->filterBatchTo !== '') {
            $q->orderBy('a.batch')
              ->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
              ->orderBy('a.last_name')
              ->orderBy('a.id');
        } else {
            $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
              ->orderBy('a.last_name')
              ->orderBy('a.id');
        }

        $rows = $q->paginate(100);

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

    /** Toggles a single program/course code in/out of the multi-select
     *  filter — bound directly to each checkbox item in the Programs
     *  dropdown. Mirrors Alumni Records' toggleProgramCode(). */
    public function toggleFilterCourse(string $code): void
    {
        if (in_array($code, $this->filterCourses, true)) {
            $this->filterCourses = array_values(array_diff($this->filterCourses, [$code]));
        } else {
            $this->filterCourses[] = $code;
        }
        $this->resetPage();
    }

    /** "All Programs" inside the dropdown — clears the whole multi-select
     *  in one round-trip. */
    public function clearFilterCourses(): void
    {
        $this->filterCourses = [];
        $this->resetPage();
    }

    /** "Select All" inside the dropdown — checks every program code
     *  currently visible to this organizer (respects allowedCourseCodes
     *  scoping, same as the dropdown list itself). */
    public function selectAllFilterCourses(): void
    {
        $this->filterCourses = DB::table('courses')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('code', $this->allowedCourseCodes))
            ->pluck('code')
            ->toArray();
        $this->resetPage();
    }

    /** "Apply" button inside the Programs dropdown — the checkboxes only
     *  edit a local Alpine draft while the panel is open, so nothing hits
     *  the table until this fires once with the whole picked set. Only
     *  codes that are actually valid course codes are kept, so a stale/
     *  tampered payload can't smuggle in a bogus filter value. */
    public function applyFilterCourses(array $codes): void
    {
        $valid = DB::table('courses')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('code', $this->allowedCourseCodes))
            ->pluck('code')
            ->toArray();

        $this->filterCourses = array_values(array_intersect($codes, $valid));
        $this->resetPage();
    }

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

    /** "Apply" button inside the Status dropdown — same Clear/Apply-gated
     *  pattern as applyFilterCourses(): checkboxes only edit a local
     *  Alpine draft while the panel is open, this fires once with the
     *  whole picked set. Only recognized status values are kept. */
    public function applyFilterStatuses(array $statuses): void
    {
        $valid = ['employed', 'self_employed', 'unemployed', 'not_filled'];
        $this->filterStatuses = array_values(array_intersect($statuses, $valid));
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
        $this->filterCourses   = [];
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
                'et.unemployment_status','et.unemployment_reason','et.updated_at as emp_updated_at',
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

}; ?>

<div class="ae-root flex flex-col">

<div id="ae-hover-tip"
     wire:ignore
     class="fixed bg-neutral-900 text-white text-[11px] font-semibold tracking-wide px-2 py-1 rounded-lg whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-lg -translate-x-0"
     style="transform:translate(12px,-110%)">
    <i class="fas fa-eye mr-1"></i>View Details
</div>

<style>
    /* ── Kill the browser's default blue tap/click highlight on table
         rows (data-ae-row). Without this, tapping/clicking a row flashes
         a translucent blue overlay before the Livewire request resolves
         — most visible on Chrome/mobile WebKit. ────────────────────── */
    [data-ae-row] {
        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        outline: none;
        user-select: none;
        -webkit-user-select: none;
    }
    [data-ae-row]:focus,
    [data-ae-row]:focus-visible {
        outline: none;
    }

    /* ── Search highlight — mirrors Alumni Records' mark.ar-hl ────── */
    mark.ae-hl {
        background: #BFDBFE;
        color: inherit;
        border-radius: 2px;
        padding: 0 1px;
        font-weight: 700;
    }

    /* ── Generate Reports button — cloned styling from Alumni Records
         (neutral slate color, top-right placement, hover tooltip + a
         dropdown with PDF / Excel / Print options). */
    .ae-report-btn {
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
    .ae-report-btn:hover,
    .ae-report-btn-active { background: linear-gradient(135deg,#334155,#475569); box-shadow: 0 3px 10px rgba(71,85,105,.5); }
    .ae-report-btn:disabled { opacity: .7; cursor: wait; }
    .ae-report-tip {
        position: absolute;
        top: calc(100% + 8px); right: 0;
        background: #1a1a1a; color: #fff;
        font-size: 10px; font-weight: 600; letter-spacing: .05em;
        padding: 5px 11px; border-radius: 7px; white-space: nowrap;
        pointer-events: none; opacity: 0; transition: opacity .15s ease;
        z-index: 9999; box-shadow: 0 4px 14px rgba(0,0,0,.30);
    }
    .ae-report-tip::before {
        content: '';
        position: absolute; bottom: 100%; right: 12px;
        border: 5px solid transparent; border-bottom-color: #1a1a1a;
    }
    .ae-report-btn:hover .ae-report-tip { opacity: 1; }
    @media (max-width: 768px), (hover: none) {
        .ae-report-tip { display: none !important; }
    }
    .ae-report-menu {
        position: absolute; top: calc(100% + 8px); right: 0;
        width: min(260px, calc(100vw - 24px));
        background: #fff;
        border: 1.5px solid #E8E0F0; border-radius: 12px;
        box-shadow: 0 10px 30px rgba(122,63,145,.18);
        z-index: 500; padding: 6px;
    }
    /* On narrow viewports the header row wraps (flex-wrap), so the
       report button can land anywhere horizontally — an absolute menu
       anchored to it can spill off the left/right edge of the screen.
       Anchor to the viewport instead so it's always fully visible,
       centered under the header, regardless of where the button wraps to. */
    @media (max-width: 640px) {
        .ae-report-menu {
            position: fixed;
            top: var(--ae-report-menu-top, 60px);
            left: 12px;
            right: 12px;
            width: auto;
            max-width: none;
        }
    }
    .ae-report-menu-message {
        padding: 10px 10px 11px;
        margin-bottom: 4px;
        border-bottom: 1px solid #F0ECF5;
    }
    .ae-report-menu-message .lbl {
        font-size: .6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .07em; color: #7A3F91; display: block; margin-bottom: 3px;
    }
    .ae-report-menu-message .txt {
        font-size: .75rem; font-weight: 600; color: #111111; line-height: 1.35;
    }
    .ae-report-menu-message .cnt {
        font-size: .68rem; font-weight: 500; color: #333333; margin-top: 3px; display: block;
    }
    .ae-report-menu-item {
        display: flex; align-items: center; gap: 9px; width: 100%;
        padding: 9px 10px; border-radius: 8px;
        margin-bottom: 4px;
        font-size: .82rem; font-weight: 600; color: #333333;
        border: 1.5px solid transparent; cursor: pointer; text-align: left;
        transition: background .12s, border-color .12s, opacity .12s;
    }
    .ae-report-menu-item:last-child { margin-bottom: 0; }
    .ae-report-menu-item .ae-item-icon {
        width: 22px; height: 22px; border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; font-size: 11px;
    }
    .ae-report-menu-item .ae-item-label { flex: 1; }
    .ae-report-menu-item.item-pdf   { background: #FEF2F2; border-color: #FEE2E2; }
    .ae-report-menu-item.item-pdf:hover   { background: #FEE2E2; border-color: #FECACA; }
    .ae-report-menu-item.item-pdf .ae-item-icon { background: #DC2626; color: #fff; }
    .ae-report-menu-item.item-excel { background: #ECFDF5; border-color: #D1FAE5; }
    .ae-report-menu-item.item-excel:hover { background: #D1FAE5; border-color: #A7F3D0; }
    .ae-report-menu-item.item-excel .ae-item-icon { background: #059669; color: #fff; }
    .ae-report-menu-item.item-print { background: #F5F5F5; border-color: #EDEDED; }
    .ae-report-menu-item.item-print:hover { background: #ECECEC; border-color: #E0E0E0; }
    .ae-report-menu-item.item-print .ae-item-icon { background: #555555; color: #fff; }
    .ae-report-menu-item:disabled { opacity: .55; cursor: wait; }
    .ae-report-menu-item.ae-no-results:disabled {
        opacity: .45; cursor: not-allowed;
    }
    .ae-report-menu-item.ae-no-results:disabled:hover {
        background: inherit; border-color: transparent;
    }
</style>

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
    <div class="ae-page-header-noselect flex items-center justify-between gap-4 flex-shrink-0 flex-wrap"
         style="-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;"
         onselectstart="return false;" oncopy="return false;" oncut="return false;" ondragstart="return false;">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-chart-line text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#333333] leading-tight">Employment Tracking</h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                    Track employment status of your assigned alumni
                    @if($organizerDepartment)
                        <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                            <i class="fas fa-building-columns text-[9px]"></i>
                            {{ $organizerDepartment }}
                        </span>
                    @endif
                    @if($organizerBatch)
                        <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                            <i class="fas fa-calendar text-[9px]"></i>
                            Batch {{ $organizerBatch }}
                        </span>
                    @endif
                </p>
            </div>
        </div>

        {{-- GENERATE REPORTS BUTTON — same PDF/Excel/Print export flow as
             Alumni Records, pointed at
             /coordinator/alumni/employment/export (OrganizerAlumniExportController). --}}
        <div class="relative shrink-0" wire:ignore
             x-data="{
                summary: 'All alumni records (no filters applied)',
                count: '0',
                _observer: null,
                menuTop: 0,
                syncFromSource(){
                    const src = document.getElementById('ae-report-summary-source');
                    if (!src) return;
                    this.summary = src.dataset.summary;
                    this.count   = src.dataset.count;
                },
                watchSource(){
                    const src = document.getElementById('ae-report-summary-source');
                    if (!src || this._observer) return;
                    this._observer = new MutationObserver(() => this.syncFromSource());
                    this._observer.observe(src, { attributes: true, attributeFilter: ['data-summary', 'data-count'] });
                },
                positionMenu(){
                    // Only matters on the narrow-viewport fixed-position
                    // layout (see .ae-report-menu media query) — on desktop
                    // the menu stays absolute/anchored to the button via
                    // CSS and this value is simply unused.
                    const btn = $el.querySelector('.ae-report-btn');
                    if (!btn) return;
                    const rect = btn.getBoundingClientRect();
                    this.menuTop = rect.bottom + 8;
                }
             }"
             x-init="
                window.__aeEnsureReportStore && window.__aeEnsureReportStore();
                syncFromSource();
                watchSource();
             "
             @click.outside="$store.aeReport.open=false" wire:key="ae-report-dropdown">
            <button type="button" @click.stop="positionMenu(); $store.aeReport.toggle()" class="ae-report-btn"
                    :disabled="$store.aeReport.exporting"
                    :class="{ 'ae-report-btn-active': $store.aeReport.open }">
                <i class="fas fa-spinner animate-spin" x-show="$store.aeReport.exporting" style="display:none;"></i>
                <i class="fas fa-chart-column" x-show="!$store.aeReport.exporting"></i>
                <span class="ae-report-tip">Generate Reports</span>
            </button>

            <div x-show="$store.aeReport.open"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="ae-report-menu" style="display:none;"
                 :style="{ '--ae-report-menu-top': menuTop + 'px' }"
                 @click="setTimeout(() => syncFromSource(), 0)">

                <div class="ae-report-menu-message">
                    <span class="lbl"><i class="fas fa-circle-info mr-1"></i>Report will include</span>
                    <span class="txt" x-text="summary"></span>
                </div>

                <button type="button" @click="$store.aeReport.doExport('pdf', $wire)"
                        :disabled="$store.aeReport.exporting || count === '0'"
                        :class="{ 'ae-no-results': count === '0' }" class="ae-report-menu-item item-pdf">
                    <span class="ae-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.aeReport.exportingType==='pdf'" style="display:none;"></i>
                        <i class="fas fa-file-pdf" x-show="$store.aeReport.exportingType!=='pdf'"></i>
                    </span>
                    <span class="ae-item-label">Export as PDF</span>
                </button>

                <button type="button" @click="$store.aeReport.doExport('excel', $wire)"
                        :disabled="$store.aeReport.exporting || count === '0'"
                        :class="{ 'ae-no-results': count === '0' }" class="ae-report-menu-item item-excel">
                    <span class="ae-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.aeReport.exportingType==='excel'" style="display:none;"></i>
                        <i class="fas fa-file-excel" x-show="$store.aeReport.exportingType!=='excel'"></i>
                    </span>
                    <span class="ae-item-label">Export as Excel</span>
                </button>

                <button type="button" @click="$store.aeReport.doExport('print', $wire)"
                        :disabled="$store.aeReport.exporting || count === '0'"
                        :class="{ 'ae-no-results': count === '0' }" class="ae-report-menu-item item-print">
                    <span class="ae-item-icon">
                        <i class="fas fa-spinner animate-spin" x-show="$store.aeReport.exportingType==='print'" style="display:none;"></i>
                        <i class="fas fa-print" x-show="$store.aeReport.exportingType!=='print'"></i>
                    </span>
                    <span class="ae-item-label">Print Current View</span>
                </button>
            </div>
        </div>

    </div>

    {{-- Hidden source for the "Report will include" text inside the
         wire:ignore'd report dropdown above — same pattern as Alumni
         Records: this span re-renders normally on every Livewire
         update, and Alpine watches it via MutationObserver so the
         dropdown text stays live without breaking the Alpine store's
         persistence (which wire:ignore protects). --}}
    <span id="ae-report-summary-source" class="hidden"
          data-summary="{{ $this->activeFilterSummary }}"
          data-count="{{ number_format($this->activeFilterCount) }}"></span>

    {{-- Hidden source for the Batch dropdown's trigger label — same
         wire:ignore + MutationObserver pattern as above: the batch
         dropdown root is wire:ignore'd (so background Livewire morphs
         never disturb its Alpine open/chevron state — that was the bug:
         a mid-click morph could reset the panel, requiring a second
         click to register), but this small span is NOT wire:ignore'd,
         so it re-renders normally and the dropdown watches it to keep
         its label live. --}}
    @php
        $aeBatchLabel = 'All Batches';
        if ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) {
            $aeBatchLabel = 'Batch ' . $filterBatchFrom . '–' . $filterBatchTo;
        } elseif ($filterBatchFrom !== '' && $filterBatchTo !== '') {
            $aeBatchLabel = 'Batch ' . $filterBatchFrom;
        } elseif ($filterBatchFrom !== '') {
            $aeBatchLabel = 'Batch ' . $filterBatchFrom . ' → pick end year';
        } elseif ($filterBatchTo !== '') {
            $aeBatchLabel = 'pick start year → Batch ' . $filterBatchTo;
        }
    @endphp
    <span id="ae-batch-label-source" class="hidden"
          data-label="{{ $aeBatchLabel }}"
          data-active="{{ ($filterBatchFrom !== '' || $filterBatchTo !== '') ? '1' : '0' }}"></span>

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
        <div class="ae-statcards-noselect w-full lg:w-56 xl:w-64 flex-shrink-0 lg:order-2
                    grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-1 gap-3 content-start
                    lg:h-full lg:overflow-y-auto lg:pr-1
                    [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f3f4f6]
                    [&::-webkit-scrollbar]:w-[5px]
                    [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                    [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                    hover:[&::-webkit-scrollbar-thumb]:bg-[#7a3f91]
                    transition-opacity duration-200"
             style="-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;"
             onselectstart="return false;" oncopy="return false;" oncut="return false;" ondragstart="return false;"
             wire:loading.class="opacity-50"
             wire:target="search,toggleFilterStatus,clearFilterStatuses,selectAllFilterStatuses,applyFilterStatuses,toggleFilterCourse,clearFilterCourses,selectAllFilterCourses,applyFilterCourses,setSingleBatchYear,clearFilterBatch,setBatchRange,clearFilters">

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
            <div class="ae-filter-bar-noselect bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
                 style="-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;"
                 onselectstart="return false;" oncopy="return false;" oncut="return false;" ondragstart="return false;"
                 wire:loading.class="opacity-60"
                 wire:target="search,toggleFilterStatus,clearFilterStatuses,selectAllFilterStatuses,applyFilterStatuses,toggleFilterCourse,clearFilterCourses,selectAllFilterCourses,applyFilterCourses,setSingleBatchYear,clearFilterBatch,setBatchRange,clearFilters">

                <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                    Filters
                </div>

                {{-- Search — text selection re-enabled here specifically so
                     the person can still select/copy/edit what they type. --}}
                <div class="relative flex-1 min-w-[160px] max-w-xs"
                     wire:ignore
                     x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#555555] z-[1]"></i>
                    <input type="text" x-model="q" @input.debounce.200ms="$wire.set('search',q)"
                           :class="q !== '' ? 'border-[#7a3f91] bg-white text-[#333333] font-semibold' : 'bg-white text-[#333333] font-medium'"
                           placeholder="Search ..."
                           style="-webkit-user-select:text;-moz-user-select:text;-ms-user-select:text;user-select:text;"
                           onselectstart="event.stopPropagation(); return true;"
                           class="w-full border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 pr-4 pl-9 rounded-lg placeholder:text-[#999999] placeholder:font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"
                           autocomplete="off" maxlength="100" spellcheck="false">
                </div>

                {{-- Status — MULTI-SELECT with real checkboxes, Clear/
                     Apply-gated: checking a box only edits a LOCAL Alpine
                     draft ("picked") while the panel is open — nothing
                     hits the server, nothing filters the table until
                     "Apply" is clicked. Mirrors the Programs dropdown
                     exactly. "Clear" only unchecks every box (does NOT
                     close the panel, does NOT touch the server); Apply
                     is what actually sends the picked set and closes. --}}
                @php $aeStatusOptions = [
                    ['employed', 'Employed'],
                    ['self_employed', 'Self-Employed'],
                    ['unemployed', 'Unemployed'],
                    ['not_filled', 'Not Filled'],
                ]; @endphp
                <div class="relative"
                     x-data="{
                        get open(){ return $store.aeFilters.isOpen('status'); },
                        toggle(){
                            if (!this.open) { this.picked = [...$wire.filterStatuses]; }
                            $store.aeFilters.toggle('status');
                        },
                        close(){ $store.aeFilters.close('status'); },
                        picked: [],
                        isPicked(val){ return this.picked.includes(val); },
                        togglePick(val){
                            this.picked = this.isPicked(val)
                                ? this.picked.filter(v => v !== val)
                                : [...this.picked, val];
                        },
                        clearPicked(){ this.picked = []; },
                        selectAllPicked(allVals){ this.picked = [...allVals]; },
                        apply(){
                            $store.aeFilters.closeAll();
                            $wire.applyFilterStatuses(this.picked);
                        }
                     }"
                     @click.outside="close()" wire:key="status-dropdown">
                    <button type="button" @click.stop="toggle()"
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
                         class="absolute top-full left-0 mt-1 z-50 bg-white border border-[#E8E0F0] rounded-xl shadow-xl overflow-hidden min-w-[180px] flex flex-col [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]"
                         style="display:none;"
                         @click.stop>
                        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-[#E8E0F0] bg-white z-10 shrink-0">
                            <label class="flex items-center gap-2 text-xs font-semibold select-none text-[#333333] cursor-pointer">
                                <input type="checkbox"
                                       :checked="picked.length === {{ count($aeStatusOptions) }}"
                                       :indeterminate="picked.length > 0 && picked.length < {{ count($aeStatusOptions) }}"
                                       @change="$event.target.checked ? selectAllPicked({{ \Illuminate\Support\Js::from(array_column($aeStatusOptions, 0)) }}) : clearPicked()"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] accent-[#7a3f91] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer">
                                Select All
                            </label>
                            <span class="text-xs font-bold text-[#7a3f91] select-none" x-show="picked.length > 0">
                                <span x-text="picked.length"></span> selected
                            </span>
                        </div>
                        <div class="py-1 max-h-[220px] overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]">
                            @foreach($aeStatusOptions as [$val, $label])
                            <label class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium cursor-pointer select-none hover:bg-purple-50 hover:text-purple-700 transition-colors"
                                   :class="isPicked('{{ $val }}') ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]'">
                                <input type="checkbox" @change="togglePick('{{ $val }}')"
                                       :checked="isPicked('{{ $val }}')"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] accent-[#7a3f91] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer shrink-0">
                                {{ $label }}
                            </label>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 border-t border-[#E8E0F0] bg-white shrink-0">
                            <button type="button" @click="clearPicked()" :disabled="picked.length === 0"
                                    class="flex-1 text-sm font-semibold py-1.5 rounded-lg border border-[#E8E0F0] transition-colors
                                           disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white
                                           text-[#666666] hover:bg-gray-50 cursor-pointer">
                                Clear
                            </button>
                            <button type="button" @click="apply()" :disabled="picked.length === 0"
                                    class="flex-1 text-sm font-semibold py-1.5 rounded-lg transition-colors
                                           disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-[#7a3f91]
                                           bg-[#7a3f91] text-white hover:bg-[#6a3580] cursor-pointer">
                                Apply
                            </button>
                        </div>
                    </div>
                </div>


                {{-- Programs — MULTI-SELECT with real checkboxes, but now
                     Clear/Apply-gated instead of live: checking a box only
                     edits a LOCAL Alpine draft ("picked") while the panel
                     is open — nothing hits the server, nothing filters the
                     table yet. Draft is seeded from $wire.filterCourses
                     every time the dropdown opens, so it always starts in
                     sync with whatever is actually applied.
                        - "Apply" sends the whole draft to the server in
                          ONE round-trip via applyFilterCourses() and
                          closes the dropdown.
                        - "Clear" just empties the draft (unchecks every
                          box) — it does NOT close the dropdown and does
                          NOT touch the server; the person still has to
                          hit Apply (with nothing checked) to actually
                          clear the live filter.
                     A "Select All" checkbox sits in a sticky header row,
                     tri-state against the DRAFT (not the live filter). --}}
                @if($courses->isNotEmpty())
                <div class="relative"
                     x-data="{
                        get open(){ return $store.aeFilters.isOpen('course'); },
                        toggle(){
                            // Reseed the draft from the live filter every time
                            // the panel is (re)opened, so stale in-progress
                            // picks from a previous open (that were never
                            // Applied) don't linger.
                            if (!this.open) { this.picked = [...$wire.filterCourses]; }
                            $store.aeFilters.toggle('course');
                        },
                        close(){ $store.aeFilters.close('course'); },
                        picked: [],
                        isPicked(code){ return this.picked.includes(code); },
                        togglePick(code){
                            this.picked = this.isPicked(code)
                                ? this.picked.filter(c => c !== code)
                                : [...this.picked, code];
                        },
                        clearPicked(){ this.picked = []; },
                        selectAllPicked(allCodes){ this.picked = [...allCodes]; },
                        apply(){
                            $store.aeFilters.closeAll();
                            $wire.applyFilterCourses(this.picked);
                        }
                     }"
                     @click.outside="close()" wire:key="course-dropdown">
                    <button type="button" @click.stop="toggle()"
                            class="border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 px-3 rounded-lg font-medium inline-flex items-center gap-2 min-w-[130px] justify-between cursor-pointer hover:border-[#c4b5d4] focus:outline-none
                                   {{ $filterCourses ? 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold' : 'text-[#333333] bg-white' }}">
                        <span class="truncate text-sm">
                            @if(count($filterCourses) === 0)
                                All Programs
                            @elseif(count($filterCourses) === 1)
                                {{ $filterCourses[0] }}
                            @else
                                {{ count($filterCourses) }} Programs
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
                         class="absolute top-full left-0 mt-1 z-50 bg-white border border-[#E8E0F0] rounded-xl shadow-xl overflow-hidden min-w-[220px] flex flex-col [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]"
                         style="display:none;"
                         @click.stop>
                        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-[#E8E0F0] bg-white z-10 shrink-0">
                            <label class="flex items-center gap-2 text-xs font-semibold select-none text-[#333333] cursor-pointer">
                                <input type="checkbox"
                                       :checked="picked.length === {{ $courses->count() }}"
                                       :indeterminate="picked.length > 0 && picked.length < {{ $courses->count() }}"
                                       @change="$event.target.checked ? selectAllPicked({{ \Illuminate\Support\Js::from($courses->pluck('code')) }}) : clearPicked()"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] accent-[#7a3f91] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer">
                                Select All
                            </label>
                            <span class="text-xs font-bold text-[#7a3f91] select-none" x-show="picked.length > 0">
                                <span x-text="picked.length"></span> selected
                            </span>
                        </div>
                        <div class="py-1 max-h-[220px] overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]">
                            @foreach($courses as $c)
                            <label class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium cursor-pointer select-none hover:bg-purple-50 hover:text-purple-700 transition-colors"
                                   :class="isPicked('{{ $c->code }}') ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]'">
                                <input type="checkbox" @change="togglePick('{{ $c->code }}')"
                                       :checked="isPicked('{{ $c->code }}')"
                                       class="w-3.5 h-3.5 rounded border-[#D4C5E8] accent-[#7a3f91] text-[#7a3f91] focus:ring-[#7a3f91]/30 cursor-pointer shrink-0">
                                {{ $c->name ?? $c->code }}
                            </label>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 border-t border-[#E8E0F0] bg-white shrink-0">
                            <button type="button" @click="clearPicked()" :disabled="picked.length === 0"
                                    class="flex-1 text-sm font-semibold py-1.5 rounded-lg border border-[#E8E0F0] transition-colors
                                           disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white
                                           text-[#666666] hover:bg-gray-50 cursor-pointer">
                                Clear
                            </button>
                            <button type="button" @click="apply()" :disabled="picked.length === 0"
                                    class="flex-1 text-sm font-semibold py-1.5 rounded-lg transition-colors
                                           disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-[#7a3f91]
                                           bg-[#7a3f91] text-white hover:bg-[#6a3580] cursor-pointer">
                                Apply
                            </button>
                        </div>
                    </div>
                </div>
                @endif


                {{-- Batch — FROM/TO range. Default view is a plain year
                     list (click a year, done); "Add Range" swaps to two
                     side-by-side scrollable year lists so a range like
                     2020–2024 is opt-in rather than always-on. Range is
                     all-or-nothing — picking only From (or only To)
                     doesn't filter anything yet, and the trigger label
                     says so explicitly. --}}
                @if($batches->isNotEmpty())
                    <div class="relative" wire:ignore
                         x-data="{
                            get open(){ return $store.aeFilters.isOpen('batch'); },
                            toggle(){
                                if (!this.open) {
                                    this.rangeFrom = $wire.filterBatchFrom || '';
                                    this.rangeTo   = $wire.filterBatchTo   || '';
                                    this.rangeMode = (this.rangeFrom !== '' && this.rangeTo !== '' && this.rangeFrom !== this.rangeTo);
                                }
                                $store.aeFilters.toggle('batch');
                            },
                            close(){ $store.aeFilters.close('batch'); },
                            rangeMode: {{ ($filterBatchFrom !== '' && $filterBatchTo !== '' && $filterBatchFrom !== $filterBatchTo) ? 'true' : 'false' }},
                            rangeFrom: '{{ $filterBatchFrom }}',
                            rangeTo: '{{ $filterBatchTo }}',
                            label: 'All Batches',
                            active: {{ ($filterBatchFrom !== '' || $filterBatchTo !== '') ? 'true' : 'false' }},
                            _observer: null,
                            syncLabel(){
                                const src = document.getElementById('ae-batch-label-source');
                                if (!src) return;
                                this.label  = src.dataset.label;
                                this.active = src.dataset.active === '1';
                            },
                            watchLabel(){
                                const src = document.getElementById('ae-batch-label-source');
                                if (!src || this._observer) return;
                                this._observer = new MutationObserver(() => this.syncLabel());
                                this._observer.observe(src, { attributes: true, attributeFilter: ['data-label', 'data-active'] });
                            },
                            selectYear(val){ $wire.setSingleBatchYear(val); this.close(); },
                            clearYear(){ this.rangeFrom=''; this.rangeTo=''; $wire.clearFilterBatch(); this.close(); },
                            startRange(){ this.rangeFrom=$wire.filterBatchFrom||''; this.rangeTo=$wire.filterBatchTo||''; this.rangeMode=true; },
                            pickFrom(val){ this.rangeFrom = (this.rangeFrom===val) ? '' : val; },
                            pickTo(val){ this.rangeTo = (this.rangeTo===val) ? '' : val; },
                            applyRange(){ if(this.rangeFrom!=='' && this.rangeTo!==''){ $wire.setBatchRange(this.rangeFrom, this.rangeTo); this.close(); } }
                         }"
                         x-init="syncLabel(); watchLabel();"
                         @click.outside="close()" wire:key="batch-dropdown">
                        <button type="button"
                                @click.stop="toggle()"
                                class="border border-[#E8E0F0] transition-[border-color,box-shadow] duration-150 text-sm py-2 px-3 rounded-lg font-medium inline-flex items-center gap-2 min-w-[130px] justify-between cursor-pointer hover:border-[#c4b5d4] focus:outline-none"
                                :class="active ? 'border-[#7a3f91] bg-[#f5f0fa] text-[#7a3f91] font-semibold' : 'text-[#333333] bg-white'">
                            <span class="truncate text-sm" x-text="label"></span>
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
                             style="display:none;"
                             @click.stop>

                            {{-- Default view: plain year list. "Add Range"
                                 sits in its own fixed footer row BELOW the
                                 scrollable year list — always visible, no
                                 scrolling needed to reach it, however many
                                 batch years there are. --}}
                            <div x-show="!rangeMode">
                                <div class="py-1 max-h-[180px] overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#c4b5d4_#f5f0fa]">
                                    <button type="button" @click.stop="clearYear()"
                                            class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors cursor-pointer
                                                   {{ ($filterBatchFrom === '' && $filterBatchTo === '') ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]' }}">
                                        All Batches
                                    </button>
                                    @foreach($batches as $b)
                                        <button type="button" @click.stop="selectYear('{{ $b }}')"
                                                class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors cursor-pointer
                                                       {{ ($filterBatchFrom == $b && $filterBatchTo == $b) ? 'bg-purple-100 text-purple-800 font-semibold' : 'text-[#333333]' }}">
                                            Batch {{ $b }}
                                        </button>
                                    @endforeach
                                </div>
                                <div class="border-t border-[#E8E0F0]">
                                    <button type="button" @click.stop="startRange()"
                                            class="w-full text-left px-3 py-2 text-sm font-semibold flex items-center gap-1.5 text-[#7a3f91] hover:bg-purple-50 transition-colors cursor-pointer">
                                        <i class="fas fa-plus" style="font-size:10px;"></i> Add Range
                                    </button>
                                </div>
                            </div>

                            {{-- Range view: two side-by-side scrollable
                                 year lists. Local Alpine state only —
                                 nothing sent to the server until "Apply"
                                 is clicked. Text sized up (text-sm) from
                                 the original text-xs for readability. --}}
                            <div x-show="rangeMode" class="p-2.5" style="width:240px;">
                                <div class="flex items-center justify-center mb-1.5">
                                    <span class="text-xs font-bold uppercase tracking-wide text-[#7a3f91]" x-text="(rangeFrom || '—') + ' → ' + (rangeTo || '—')"></span>
                                </div>
                                <div class="flex items-start gap-2">
                                    <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:160px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                        @foreach($batches as $b)
                                        <button type="button" @click.stop="pickFrom('{{ $b }}')"
                                                :class="rangeFrom==='{{ $b }}' ? 'bg-[#7a3f91] text-white font-bold' : 'text-[#333333]'"
                                                class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors cursor-pointer">{{ $b }}</button>
                                        @endforeach
                                    </div>
                                    <div class="flex-1 min-w-0 border border-[#E8E0F0] rounded-lg overflow-y-auto" style="max-height:160px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                        @foreach($batches as $b)
                                        <button type="button" @click.stop="pickTo('{{ $b }}')"
                                                :class="rangeTo==='{{ $b }}' ? 'bg-[#7a3f91] text-white font-bold' : 'text-[#333333]'"
                                                class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors cursor-pointer">{{ $b }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mt-3">
                                    <button type="button" @click.stop.prevent="rangeMode=false"
                                            class="flex-1 text-xs font-semibold text-[#333333] hover:bg-[#F5F5F5] rounded-lg py-1.5 transition-colors border border-[#E8E0F0] cursor-pointer">
                                        Back to List
                                    </button>
                                    <button type="button" @click.stop="applyRange()" :disabled="rangeFrom==='' || rangeTo===''"
                                            class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors border border-transparent
                                                   disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-[#7a3f91]
                                                   bg-[#7a3f91] text-white hover:bg-[#6a3580] cursor-pointer">
                                        Apply
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Reset — pushed to the far right of the bar (ml-auto),
                     and automatically disabled whenever no filter/search
                     is currently active, so there's nothing to reset. --}}
                @php
                    $aeHasActiveFilters = $search !== '' || !empty($filterStatuses) || $filterBatchFrom !== '' || $filterBatchTo !== '' || !empty($filterCourses);
                @endphp
                <button wire:click="clearFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="clearFilters"
                        @disabled(!$aeHasActiveFilters)
                        class="ml-auto inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95
                               disabled:pointer-events-none disabled:opacity-50 disabled:cursor-not-allowed
                               cursor-pointer text-[#333333]">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>

                {{-- ── Active filters badge ── ONE combined pill beside
                     Reset showing what's filtered and the result count,
                     instead of a separate pill per filter (which used to
                     wrap onto its own row once 2+ filters were active). --}}
                @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourses)
                    @php
                        $aeActiveParts = [];
                        if ($search !== '') $aeActiveParts[] = '"' . $search . '"';
                        if (!empty($filterStatuses)) {
                            $aeActiveParts[] = count($filterStatuses) === 1
                                ? ($filterStatuses[0] === 'not_filled' ? 'Not Filled' : ucwords(str_replace('_', '-', $filterStatuses[0])))
                                : count($filterStatuses) . ' Statuses';
                        }
                        if (!empty($filterCourses)) {
                            $aeActiveParts[] = count($filterCourses) === 1 ? $filterCourses[0] : count($filterCourses) . ' Programs';
                        }
                        if ($filterBatchFrom !== '' && $filterBatchTo !== '') {
                            $aeActiveParts[] = $filterBatchFrom === $filterBatchTo ? 'Batch ' . $filterBatchFrom : 'Batch ' . $filterBatchFrom . '–' . $filterBatchTo;
                        }
                    @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7a3f91] border-[#E8E0F0] truncate max-w-[260px]">
                        <i class="fas fa-filter text-[10px] flex-shrink-0"></i>
                        <span class="truncate">{{ implode(' · ', $aeActiveParts) }}</span>
                        <span class="flex-shrink-0">&mdash; {{ number_format($rows->total()) }} result(s)</span>
                    </span>
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

            <div class="relative flex flex-col flex-1 min-h-0" id="ae-rows-wrapper">

                {{-- Centered loading spinner — big icon over the table itself,
                     same pattern as Job Management / Event Organizer. --}}
                <div class="absolute inset-0 z-20 items-center justify-center hidden"
                     wire:loading.flex wire:target="search,toggleFilterStatus,clearFilterStatuses,selectAllFilterStatuses,applyFilterStatuses,toggleFilterCourse,clearFilterCourses,selectAllFilterCourses,applyFilterCourses,setSingleBatchYear,clearFilterBatch,setBatchRange,clearFilters,previousPage,nextPage,gotoPage">
                    <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
                </div>

                @if($rows->count() > 0)
                <div class="ae-table-noselect overflow-x-hidden overflow-y-auto flex-1 min-h-0 bg-white
                            [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f3f4f6]
                            [&::-webkit-scrollbar]:w-[5px]
                            [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                            [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                            hover:[&::-webkit-scrollbar-thumb]:bg-[#7a3f91]"
                     style="-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;"
                     onselectstart="return false;" oncopy="return false;" oncut="return false;" ondragstart="return false;"
                     wire:loading.class="opacity-40 pointer-events-none"
                     wire:target="search,toggleFilterStatus,clearFilterStatuses,selectAllFilterStatuses,applyFilterStatuses,toggleFilterCourse,clearFilterCourses,selectAllFilterCourses,applyFilterCourses,setSingleBatchYear,clearFilterBatch,setBatchRange,clearFilters,previousPage,nextPage,gotoPage">

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
                                wire:loading.class="opacity-60"
                                wire:target="viewDetail({{ $row->id }})"
                                data-ae-row>

                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $photoUrl }}"
                                             alt="{{ $row->full_name }}"
                                             class="w-9 h-9 rounded-xl object-cover flex-shrink-0 shadow ring-1 ring-[#E8E0F0]">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-sm leading-snug truncate uppercase text-[#333333]">{!! $this->highlight($row->full_name, $this->search) !!}</p>

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
                                        <p class="font-semibold text-sm leading-snug uppercase text-[#333333]">{!! $this->highlight($row->job_title, $this->search) !!}</p>
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
                                            {!! $this->highlight($row->email, $this->search) !!}
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
                             wire:loading.class="opacity-60"
                             wire:target="viewDetail({{ $row->id }})"
                             data-ae-row>

                            <img src="{{ $photoUrl }}"
                                 alt="{{ $row->full_name }}"
                                 class="w-10 h-10 rounded-lg object-cover flex-shrink-0 ring-1 ring-[#E8E0F0]">

                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm uppercase truncate text-[#333333]">{!! $this->highlight($row->full_name, $this->search) !!}</p>

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
                                        <span class="text-xs font-medium truncate text-[#555555]">{!! $this->highlight($row->job_title, $this->search) !!}</span>
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
                            @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourses)
                                No alumni match your filters
                            @else
                                No alumni found
                            @endif
                        </p>
                        <p class="text-sm mt-1 text-[#555555]">
                            @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourses)
                                Try clearing your filters to see all alumni.
                            @else
                                No verified alumni are registered under your college yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourses)
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
            <div class="ae-pagination-noselect flex-shrink-0 bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] px-4 min-h-[48px] flex items-center justify-between gap-2 flex-wrap border-t border-[#7a3f91]/30"
                 style="-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;-webkit-touch-callout:none;"
                 onselectstart="return false;" oncopy="return false;" oncut="return false;" ondragstart="return false;">
                <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                    Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                    of <strong class="text-white font-bold">{{ $total }}</strong>
                    alumni
                    @if($filterStatuses || $filterBatchFrom || $filterBatchTo || $filterCourses || $search)
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
                        <button wire:click="gotoPage(1)"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                        @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    @endif

                    @for($p = $pgStart; $p <= $pgEnd; $p++)
                        @if($p === $cp)
                            <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                         bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                        @else
                            <button wire:click="gotoPage({{ $p }})"
                                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                           bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                        @endif
                    @endfor

                    @if($pgEnd < $lp)
                        @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                        <button wire:click="gotoPage({{ $lp }})"
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
{{-- Full-screen page (not a modal/dialog): no backdrop, no centering,
     fills the entire viewport like the Edit Event page. Just an X button
     top-right to go back. x-data still manages the open/closing
     transition so closeModal() fires after the fade-out finishes. --}}
<div class="fixed inset-0 bg-white z-50 flex flex-col"
     x-data="{ open: false, isClosing: false, closing(){ if (this.isClosing) return; this.isClosing = true; this.open = false; window.dispatchEvent(new CustomEvent('open-sidebar')); setTimeout(() => $wire.closeModal(), 180); } }"
     x-init="requestAnimationFrame(() => open = true)"
     x-show="open"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 scale-[0.99]"
     x-transition:enter-end="opacity-100 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="closing()">

        <div class="flex items-center justify-between px-5 py-4 border-b border-[#E8E0F0] flex-shrink-0 bg-[#7A3F91]">
            <p class="font-semibold text-white text-base leading-snug uppercase">
                {{ $modalData['full_name'] ?? '—' }}
                @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
            </p>
            <button type="button"
                    @click="closing()"
                    :disabled="isClosing"
                    class="relative group/close w-9 h-9 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white cursor-pointer disabled:cursor-wait">
                <i class="fa-solid fa-spinner fa-spin text-lg" x-show="isClosing" x-cloak></i>
                <i class="fa-solid fa-xmark text-lg" x-show="!isClosing"></i>
                <span class="pointer-events-none absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-neutral-900 text-white text-xs font-semibold px-2 py-1 rounded-md whitespace-nowrap opacity-0 group-hover/close:opacity-100 transition-opacity duration-150">Close</span>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-5 sm:p-8 space-y-5 max-w-3xl w-full mx-auto [scrollbar-width:thin] [scrollbar-color:#d9c9e8_#F9F7FC]">

            <div class="flex flex-col items-center text-center gap-3 pb-2">
                <img src="{{ $modalPhotoUrl }}"
                     alt="{{ $md['full_name'] ?? '' }}"
                     class="w-24 h-24 rounded-2xl object-cover shadow-md ring-2 ring-[#E8E0F0]">
                <p class="text-xl font-bold text-[#333333] uppercase leading-snug">
                    {{ $md['full_name'] ?? '—' }}@if($md['suffix'] ?? null) {{ $md['suffix'] }}@endif
                </p>
            </div>

            <div>
                <p class="text-sm font-semibold text-[#333333] uppercase tracking-widest mb-3">Student Information</p>
                <div class="grid grid-cols-3 gap-2 mb-2">
                    @foreach([
                        'Program' => $md['course_code']    ?? '—',
                        'Batch'   => $md['batch']          ?? '—',
                        'Contact' => $md['contact_number'] ?? '—',
                    ] as $label => $value)
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                            <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-0.5">{{ $label }}</p>
                            <p class="text-base font-semibold text-[#333333]">{{ $value ?: '—' }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                    <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Email Address</p>
                    <p class="text-base font-semibold text-[#333333] break-all">{{ $md['email'] ?? '—' }}</p>
                </div>
            </div>

            <div class="border-t border-[#E8E0F0] pt-4">
                <p class="text-sm font-semibold text-[#333333] uppercase tracking-widest mb-3">Employment Information</p>
                <div class="flex items-center gap-2 mb-4 flex-wrap">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-sm font-semibold {{ $statusCls }}">
                        {{ $statusLbl }}
                    </span>
                    @if($md['emp_updated_at'] ?? null)
                        <span class="text-sm text-[#999999]">
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
                        ] as [$lbl, $val])
                            <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                                <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-0.5">{{ $lbl }}</p>
                                <p class="text-base font-semibold text-[#333333]">{{ $val }}</p>
                            </div>
                        @endforeach
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0] sm:col-span-3">
                            <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-1.5">Job Related to Program?</p>
                            @if($relModal)
                                <p class="text-base font-semibold text-[#333333]">{{ $relModal[0] }}</p>
                            @else
                                <p class="text-base text-[#999999]">— Not specified</p>
                            @endif
                        </div>
                    </div>
                    @if(!empty($md['career_path_arr']))
                        <div class="mt-4">
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-widest mb-2">Career Path</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($md['career_path_arr'] as $cp)
                                    @if(isset($careerLabels[$cp]))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-sm font-semibold bg-gray-50 text-[#333333] border border-[#E8E0F0]">
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
                            <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Unemployment Status</p>
                            <p class="text-base font-semibold text-[#333333]">
                                {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$md['unemployment_status'] ?? ''] ?? '—' }}
                            </p>
                        </div>
                        @if(($md['unemployment_status'] ?? '') === 'not_looking' && !empty($md['unemployment_reason']))
                        <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-3">
                            <p class="text-sm font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Reason</p>
                            <p class="text-base font-semibold text-[#333333]">{{ $md['unemployment_reason'] }}</p>
                        </div>
                        @endif
                    </div>
                @else
                    <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-8 text-center">
                        <p class="text-base font-semibold text-[#999999]">No employment record yet.</p>
                        <p class="text-sm text-[#CCCCCC] mt-1">This alumni has not filled in their employment information.</p>
                    </div>
                @endif
            </div>

        </div>
</div>
@endif

<script>
(function () {
    // ── Generate Reports store ──────────────────────────────────────────
    // Handles the PDF/Excel/Print export flow for this page, hitting
    // /coordinator/alumni/employment/export (OrganizerAlumniExportController).
    // Mirrors Alumni Records' window.Alpine.store('report') but under its
    // own key ('aeReport') so both stores can coexist without clobbering
    // each other if ever loaded on the same page.
    function registerReportStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('aeReport')) return;

        window.Alpine.store('aeReport', {
            open: false,
            exporting: false,
            exportingType: '',
            _lastToggle: 0,
            _lastExport: 0,

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
                } catch (e) {
                    // response wasn't JSON — fall through to generic message
                }
                return fallback;
            },

            async doExport(type, wire) {
                const now = Date.now();
                if (this.exporting || now - this._lastExport < 400) return;
                this._lastExport = now;
                this.exporting = true;
                this.exportingType = type;
                this.open = false;

                const label = type === 'excel' ? 'Excel file' : type === 'print' ? 'print view' : 'PDF';
                window.dispatchEvent(new CustomEvent('flash-message', {
                    detail: { type: 'info', message: 'Generating your ' + label + '… this only takes a moment.' }
                }));

                const params = new URLSearchParams({
                    type: type,
                    search: (wire && wire.search) || '',
                    batch_from: (wire && wire.filterBatchFrom) || '',
                    batch_to: (wire && wire.filterBatchTo) || '',
                    // OrganizerAlumniExportController only reads a single
                    // `course` value (unlike Alumni Records' whereIn), so
                    // only the first selected Program is sent here — if
                    // several are checked in the filter, the export is
                    // scoped to the first one.
                    course: (wire && wire.filterCourses && wire.filterCourses[0]) || '',
                    status: (wire && wire.filterStatuses && wire.filterStatuses.join(',')) || '',
                });
                const url = '/coordinator/alumni/employment/export?' + params.toString();

                try {
                    if (type === 'print') {
                        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            const msg = await this.readErrorMessage(res, 'Print generation failed. Please try again.');
                            throw new Error(msg);
                        }
                        const html = await res.text();

                        const oldFrame = document.getElementById('ae-print-frame');
                        if (oldFrame) oldFrame.remove();

                        const frame = document.createElement('iframe');
                        frame.id = 'ae-print-frame';
                        frame.style.position = 'fixed';
                        frame.style.right = '0';
                        frame.style.bottom = '0';
                        frame.style.width = '0';
                        frame.style.height = '0';
                        frame.style.border = '0';

                        let printFired = false;
                        const firePrintOnce = () => {
                            if (printFired) return;
                            printFired = true;
                            frame.contentWindow.focus();
                            frame.contentWindow.print();
                        };
                        frame.onload = () => setTimeout(firePrintOnce, 150);

                        document.body.appendChild(frame);

                        const doc = frame.contentWindow.document;
                        doc.open();
                        doc.write(html);
                        doc.close();

                        setTimeout(firePrintOnce, 200);
                    } else {
                        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            const msg = await this.readErrorMessage(
                                res,
                                type === 'excel' ? 'Excel export failed. Please try again.' : 'PDF export failed. Please try again.'
                            );
                            throw new Error(msg);
                        }

                        const blob = await res.blob();
                        const disposition = res.headers.get('Content-Disposition') || '';
                        let filename = type === 'excel' ? 'alumni-employment.xlsx' : 'alumni-employment.pdf';
                        const match = disposition.match(/filename="?([^"]+)"?/);
                        if (match) filename = match[1];

                        const blobUrl = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(blobUrl);
                    }
                } catch (e) {
                    window.dispatchEvent(new CustomEvent('flash-message', {
                        detail: { type: 'error', message: e && e.message ? e.message : 'Export failed. Please try again.' }
                    }));
                } finally {
                    this.exporting = false;
                    this.exportingType = '';
                }
            }
        });
    }

    window.__aeEnsureReportStore = registerReportStore;

    if (window.Alpine) registerReportStore();
    document.addEventListener('alpine:init', registerReportStore);
    document.addEventListener('livewire:init', registerReportStore);
    document.addEventListener('livewire:navigated', registerReportStore);

    // ── Shared filter-dropdown store ────────────────────────────────────
    // Tracks which single filter dropdown is currently open (status,
    // course, batch) so opening one automatically closes any other that
    // was left open — only one dropdown shown at a time instead of them
    // stacking on top of each other. Mirrors Alumni Records' arFilters.
    function registerFilterDropdownStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('aeFilters')) return;
        window.Alpine.store('aeFilters', {
            openKey: '',
            isOpen(key) { return this.openKey === key; },
            toggle(key) { this.openKey = (this.openKey === key) ? '' : key; },
            close(key) { if (this.openKey === key) this.openKey = ''; },
            closeAll() { this.openKey = ''; },
        });
    }
    if (window.Alpine) registerFilterDropdownStore();
    document.addEventListener('alpine:init', registerFilterDropdownStore);

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
    document.addEventListener('livewire:navigated', bindRows);

    // MutationObserver is the reliable catch-all: filtering + pagination
    // (or any combination of the two) morphs/replaces the rows, and
    // depending on Livewire's exact update path the 'livewire:updated'
    // DOM event doesn't always fire for every one of those morphs — which
    // is why "View Details" could stop appearing after filtering into
    // page 2. Watching the wrapper directly means new rows always get
    // (re)bound regardless of which Livewire event actually fired.
    (function observeRowsWrapper() {
        var wrapper = document.getElementById('ae-rows-wrapper');
        if (!wrapper) {
            // Wrapper not in the DOM yet (e.g. very first paint) — retry shortly.
            setTimeout(observeRowsWrapper, 300);
            return;
        }
        var observer = new MutationObserver(function () { bindRows(); });
        observer.observe(wrapper, { childList: true, subtree: true });
    })();

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