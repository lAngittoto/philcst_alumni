{{-- resources/views/livewire/organizer/alumni-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {

    use WithPagination;

    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterRelevance = '';
    public string $filterBatch     = '';
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

    // ── tracks the last seen emp update timestamp so we only notify once ──
    public string $lastSeenEmpAt = '';

    // NOTE: filterStatus / filterCourse deliberately removed from queryString.
    // Deep-linking from the dashboard now goes exclusively through the
    // session handoff below (session('organizer_employment_filter')), so the
    // browser URL always stays clean (/organizer/alumni/employment) instead
    // of showing ?course=BSED or ?status=employed.
    protected $queryString = [
        'search'          => ['except' => ''],
        'filterRelevance' => ['except' => ''],
        'filterBatch'     => ['except' => ''],
    ];

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
            $this->filterStatus = $sessionFilter['status'] ?? '';
            $this->filterCourse = $sessionFilter['course'] ?? '';
        } elseif (is_string($sessionFilter) && $sessionFilter !== '') {
            // backward-compat: older single-value session payload
            $this->filterStatus = $sessionFilter;
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

        if ($this->filterStatus) {
            $this->filterStatus === 'not_filled'
                ? $q->whereNull('et.employment_status')
                : $q->where('et.employment_status', $this->filterStatus);
        }
        if ($this->filterRelevance) $q->where('et.course_relevance', $this->filterRelevance);
        if ($this->filterBatch)     $q->where('a.batch',             $this->filterBatch);
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
    public function updatingFilterStatus(): void    { $this->resetPage(); }
    public function updatingFilterRelevance(): void { $this->resetPage(); }
    public function updatingFilterBatch(): void     { $this->resetPage(); }
    public function updatingFilterCourse(): void    { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = $this->filterStatus =
        $this->filterRelevance = $this->filterBatch = $this->filterCourse = '';
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
                'a.address_street',
                'a.address_barangay',
                'a.address_municipality',
                'a.address_province',
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

}; ?>

<div class="flex flex-col">

<style>
.ae-hover-tip {
    position: fixed;
    background: #1a1a1a;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .05em;
    padding: 6px 12px;
    border-radius: 7px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 99999;
    box-shadow: 0 4px 14px rgba(0,0,0,.30);
    transform: translate(12px, -110%);
}
.ae-hover-tip.visible { opacity: 1; }
.ae-hover-tip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 14px;
    border: 5px solid transparent;
    border-top-color: #1a1a1a;
}

.ae-tbl-row {
    background-color: #ffffff;
    cursor: pointer;
    transition: background-color .15s ease;
}
.ae-tbl-row:hover { background-color: #f5f0fa !important; }

/* ── Mobile card rows (no horizontal scroll, nothing hidden) ── */
.ae-mrow {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    background: #fff;
    border-bottom: 1px solid #F5F5F5;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background-color .12s ease;
}
.ae-mrow:active { background-color: #f5f0fa; }

.ae-filter-input {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-weight: 500;
}
.ae-filter-input::placeholder { color: #999999; font-weight: 400; }
.ae-filter-input:hover  { border-color: #c4b5d4; }
.ae-filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.ae-filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}
select.ae-filter-input option { color: #333333; font-weight: 500; }
select.ae-filter-input.ae-active {
    border-color: #7a3f91;
    background-color: #f5f0fa;
    color: #7a3f91;
    font-weight: 600;
}

/* ── Filter bar loading progress bar (same effect as Alumni Records) ── */
.ae-filter-progress-track {
    height: 2px;
    width: 100%;
    overflow: hidden;
    background: transparent;
    position: relative;
    flex-shrink: 0;
}
.ae-filter-progress-bar {
    position: absolute;
    top: 0; left: 0;
    height: 100%;
    width: 40%;
    border-radius: 99px;
    background: linear-gradient(135deg, #7a3f91, #9b59b6);
    animation: aeFilterProgress 1s ease-in-out infinite;
}
@keyframes aeFilterProgress {
    0%   { left: -40%; }
    100% { left: 100%; }
}

/* ── Stat cards live in a side column, ordered after the table ── */
.ae-cards-side {
    scrollbar-width: thin;
    scrollbar-color: #d1d5db #f3f4f6;
}
.ae-cards-side::-webkit-scrollbar { width: 5px; }
.ae-cards-side::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.ae-cards-side::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.ae-cards-side::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.ae-stat-card {
    background: #fff;
    border-radius: 1rem;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    padding: 1rem;
    text-align: left;
    width: 100%;
    cursor: default;
    user-select: none;
}

.ae-table-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: all .3s ease;
}

/* ── Mobile/tablet fullscreen mode — toggled via Alpine (fullscreen flag).
     Takes the table block out of the normal flow and covers the entire
     viewport so the alumni list is easier to browse on small screens. ── */
.ae-tb-fullscreen {
    position: fixed !important;
    inset: 0 !important;
    z-index: 999 !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    min-height: 100dvh !important;
    width: 100vw !important;
    border-radius: 0 !important;
    border: none !important;
}
.ae-table-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.ae-table-block-pagination {
    flex-shrink: 0;
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    padding: 0 1rem;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
    border-top: 1px solid rgba(122,63,145,.3);
}
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.ae-close-tooltip { position: relative; }
.ae-close-tooltip::after {
    content: 'Close';
    position: absolute;
    top: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #1a1a1a;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    padding: 4px 8px;
    border-radius: 5px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 99999;
}
.ae-close-tooltip::before {
    content: '';
    position: absolute;
    top: calc(100% + 1px);
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-bottom-color: #1a1a1a;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 99999;
}
.ae-close-tooltip:hover::after,
.ae-close-tooltip:hover::before { opacity: 1; }

/* ── Column visibility uses CONTAINER queries, not viewport queries.
     This matters because the sidebar can collapse/expand independently of
     the browser window — that changes how much room the table actually
     has without changing the viewport at all. Viewport-based (sm:/md:/lg:)
     classes would keep a column "on" even when there's no longer enough
     room for it, so it renders half cut off at the edge. Container queries
     fix this properly: each column only switches on once the table itself
     (not the window) is wide enough to fit it.

     UPDATED TIERS (Job Title and Email no longer both fight for space at
     the same width — Job Title now has its own container-query toggle
     instead of always being on). On narrow/tablet widths only Alumni +
     Program show in the table; Job Title and Status surface as compact
     inline info inside the Alumni cell instead, so nothing overlaps or
     gets squeezed. ── */
.ae-table-block { container-type: inline-size; container-name: ae-tbl; }

.ae-col-studentid,
.ae-col-batch,
.ae-col-status,
.ae-col-jobtitle,
.ae-col-email { display: none; }

.ae-inline-studentid { display: block; }
.ae-inline-status    { display: inline-flex; }
.ae-inline-jobtitle   { display: block; }

@container ae-tbl (min-width: 540px) {
    .ae-col-studentid    { display: table-cell; }
    .ae-inline-studentid { display: none; }
}
@container ae-tbl (min-width: 660px) {
    .ae-col-batch { display: table-cell; }
}
@container ae-tbl (min-width: 860px) {
    .ae-col-jobtitle    { display: table-cell; }
    .ae-inline-jobtitle { display: none; }
}
@container ae-tbl (min-width: 980px) {
    .ae-col-status    { display: table-cell; }
    .ae-inline-status { display: none; }
}
@container ae-tbl (min-width: 1120px) {
    .ae-col-email { display: table-cell; }
}

/* ── Responsive height: the alumni table always keeps this capped,
     independently scrollable height on tablet/mobile — filtered/empty
     results no longer shrink the block, so switching filters in and out
     never causes the table to resize or jump around. ── */
@media (max-width: 1023px) {
    .ae-table-block {
        height: calc(100dvh - 360px);
        max-height: calc(100dvh - 360px);
        min-height: 380px;
    }
}
@media (max-width: 640px) {
    .ae-table-block {
        height: calc(100dvh - 380px);
        max-height: calc(100dvh - 380px);
        min-height: 360px;
    }
}
</style>

<div id="ae-hover-tip" class="ae-hover-tip">
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
    <div class="flex items-center gap-4 flex-shrink-0">
        <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
             style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <i class="fas fa-chart-line text-white text-lg"></i>
        </div>
        <div>
            <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Employment Tracking</h1>
            <p class="text-xs leading-relaxed mt-0.5 flex flex-wrap items-center gap-1.5" style="color:#555555;">
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
        <div class="ae-cards-side w-full lg:w-56 xl:w-64 flex-shrink-0 lg:order-2
                    grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-1 gap-3 content-start
                    lg:h-full lg:overflow-y-auto lg:pr-1">

            {{-- Total Alumni --}}
            <div class="ae-stat-card">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow" style="background:#7A3F91;">
                        <i class="fa-solid fa-users text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">All</span>
                </div>
                <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalAlumni }}</p>
                <p class="text-sm text-[#666666] mt-1 font-normal">Total Alumni</p>
            </div>

            {{-- Employed --}}
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
            <div class="ae-stat-card">
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
             block from resizing / collapsing every time a filter changes. --}}
        <div class="ae-table-block flex-1 min-w-0 w-full lg:order-1 lg:h-full"
             x-data="{ fullscreen: false }"
             :class="fullscreen ? 'ae-tb-fullscreen' : ''"
             @keydown.escape.window="fullscreen = false">

            {{-- FILTER BAR --}}
            <div class="ae-table-block-filter flex flex-wrap gap-2 items-center transition-opacity duration-200"
                 wire:loading.class="opacity-60"
                 wire:target="search,filterStatus,filterRelevance,filterBatch,filterCourse,clearFilters">

                <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide"
                     style="color:#7a3f91;">
                    Filters
                </div>

                {{-- Search --}}
                <div class="relative flex-1 min-w-[160px] max-w-xs"
                     wire:ignore
                     x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#555555; z-index:1;"></i>
                    <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                           placeholder="Search name, ID, email or job…"
                           class="ae-filter-input w-full"
                           style="padding-left: 2.25rem; padding-right: 1rem;"
                           autocomplete="off" maxlength="100" spellcheck="false">
                </div>

                {{-- Status --}}
                <select wire:model.live="filterStatus"
                        class="ae-filter-input {{ $filterStatus ? 'ae-active' : '' }}">
                    <option value="">All Statuses</option>
                    <option value="employed">Employed</option>
                    <option value="self_employed">Self-Employed</option>
                    <option value="unemployed">Unemployed</option>
                    <option value="not_filled">Not Filled</option>
                </select>

                {{-- Relevance --}}
                <select wire:model.live="filterRelevance"
                        class="ae-filter-input {{ $filterRelevance ? 'ae-active' : '' }}">
                    <option value="">All Relevance</option>
                    <option value="yes">Related to Program</option>
                    <option value="partially">Partially Related</option>
                    <option value="no">Not Related</option>
                </select>

                {{-- Programs (formerly "Courses") --}}
                @if($courses->isNotEmpty())
                    <select wire:model.live="filterCourse"
                            class="ae-filter-input {{ $filterCourse ? 'ae-active' : '' }}">
                        <option value="">All Programs</option>
                        @foreach($courses as $c)
                            <option value="{{ $c->code }}">{{ $c->code }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Batch --}}
                @if($batches->isNotEmpty())
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button"
                                @click="open = !open"
                                class="ae-filter-input inline-flex items-center gap-2 min-w-[130px] justify-between
                                       {{ $filterBatch ? 'ae-active' : '' }}">
                            <span class="truncate text-sm">
                                {{ $filterBatch ? 'Batch ' . $filterBatch : 'All Batches' }}
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
                             class="absolute top-full left-0 mt-1 z-50 bg-white border border-[#E8E0F0] rounded-xl shadow-xl overflow-hidden"
                             style="min-width:150px; max-height:180px; overflow-y:auto;
                                    scrollbar-width:thin; scrollbar-color:#c4b5d4 #f5f0fa;">
                            <div class="py-1">
                                <button type="button"
                                        @click="$wire.set('filterBatch', ''); open = false"
                                        class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors
                                               {{ !$filterBatch ? 'bg-purple-50 text-purple-700 font-semibold' : 'text-[#333333]' }}">
                                    All Batches
                                </button>
                                @foreach($batches as $b)
                                    <button type="button"
                                            @click="$wire.set('filterBatch', '{{ $b }}'); open = false"
                                            class="w-full text-left px-3 py-2 text-sm font-medium hover:bg-purple-50 hover:text-purple-700 transition-colors
                                                   {{ $filterBatch == $b ? 'bg-purple-100 text-purple-800 font-semibold' : 'text-[#333333]' }}">
                                        Batch {{ $b }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Reset --}}
                <button wire:click="clearFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="clearFilters"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                        style="color:#333333;">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>

                {{-- Fullscreen toggle — mobile & tablet only. Expands the
                     alumni table to cover the whole screen so it's easier
                     to browse the list on smaller devices. Hidden on
                     desktop (lg+) since the table already has plenty of
                     room there. --}}
                <button type="button"
                        @click="fullscreen = !fullscreen"
                        class="lg:hidden inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer ml-auto"
                        style="color:#7a3f91;">
                    <i class="fas" :class="fullscreen ? 'fa-compress' : 'fa-expand'"></i>
                    <span x-text="fullscreen ? 'Exit' : 'Full Screen'"></span>
                </button>
            </div>

            {{-- LOADING PROGRESS BAR (same effect as Alumni Records) --}}
            <div class="ae-filter-progress-track" wire:loading wire:target="search,filterStatus,filterRelevance,filterBatch,filterCourse,clearFilters,previousPage,nextPage">
                <div class="ae-filter-progress-bar"></div>
            </div>

            {{-- TABLE WRAPPER — always flex-1 so the empty state fills the
                 same fixed height as when rows are present; the block never
                 shrinks or resizes when a filter returns zero results. --}}
            <div class="relative flex flex-col flex-1 min-h-0">

                @if($rows->count() > 0)
                <div class="overflow-x-hidden overflow-y-auto scroll-c flex-1 min-h-0" style="background:#fff;"
                     wire:loading.class="opacity-40 pointer-events-none"
                     wire:target="search,filterStatus,filterBatch,filterCourse,filterRelevance,clearFilters,previousPage,nextPage">

                    {{-- ── DESKTOP / TABLET: table view ── --}}
                    <table class="w-full bg-white border-collapse hidden md:table">
                        <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Alumni</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest ae-col-studentid" style="color:#555555;">Student ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Program</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest ae-col-batch" style="color:#555555;">Batch</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest ae-col-jobtitle" style="color:#555555;">Job Title</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest ae-col-email" style="color:#555555;">Email Address</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest ae-col-status" style="color:#555555;">Status</th>
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

                            <tr class="ae-tbl-row"
                                wire:click="viewDetail({{ $row->id }})"
                                wire:key="ae-row-{{ $row->id }}"
                                data-ae-row>

                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $photoUrl }}"
                                             alt="{{ $row->full_name }}"
                                             class="w-9 h-9 rounded-xl object-cover flex-shrink-0 shadow ring-1 ring-[#E8E0F0]">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-sm leading-snug truncate uppercase" style="color:#333333;">{{ $row->full_name }}</p>
                                            <p class="text-xs font-mono mt-0.5 ae-inline-studentid" style="color:#999999;">{{ $row->student_id }}</p>

                                            {{-- Compact inline row for info hidden at this container width.
                                                 Status badge and job title/unemployment note both collapse
                                                 into this line instead of fighting for their own columns,
                                                 so nothing overlaps on tablet widths. --}}
                                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-semibold border ae-inline-status {{ $statusClass }}">
                                                    <i class="fa-solid {{ $statusIcon }} text-[8px]"></i>
                                                    {{ $statusLabel }}
                                                </span>
                                                <span class="ae-inline-jobtitle text-xs font-medium truncate max-w-[220px]" style="color:#555555;">
                                                    @if($row->job_title)
                                                        {{ $row->job_title }}
                                                    @elseif($row->employment_status === 'unemployed')
                                                        <span class="italic" style="color:#999999;">
                                                            {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '' }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5 ae-col-studentid">
                                    <span class="text-sm font-mono font-semibold" style="color:#333333;">{{ $row->student_id }}</span>
                                </td>

                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-100 uppercase">
                                        {{ $row->course_code ?? '—' }}
                                    </span>
                                </td>

                                <td class="px-4 py-3.5 text-sm font-semibold ae-col-batch" style="color:#333333;">
                                    {{ $row->batch ?? '—' }}
                                </td>

                                <td class="px-4 py-3.5 ae-col-jobtitle">
                                    @if($row->job_title)
                                        <p class="font-semibold text-sm leading-snug uppercase" style="color:#333333;">{{ $row->job_title }}</p>
                                    @elseif($row->employment_status === 'unemployed')
                                        <span class="text-sm italic" style="color:#999999;">
                                            {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '—' }}
                                        </span>
                                    @else
                                        <span class="text-sm italic" style="color:#cccccc;">No data yet</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3.5 ae-col-email">
                                    @if($row->email ?? null)
                                        <p class="text-sm font-medium truncate max-w-[200px]" style="color:#333333;" title="{{ $row->email }}">
                                            {{ $row->email }}
                                        </p>
                                    @else
                                        <span class="text-sm" style="color:#cccccc;">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3.5 text-center ae-col-status">
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

                        <div class="ae-mrow"
                             wire:click="viewDetail({{ $row->id }})"
                             wire:key="ae-mrow-{{ $row->id }}"
                             data-ae-row>

                            <img src="{{ $photoUrl }}"
                                 alt="{{ $row->full_name }}"
                                 class="w-10 h-10 rounded-lg object-cover flex-shrink-0 ring-1 ring-[#E8E0F0]">

                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm uppercase truncate" style="color:#333333;">{{ $row->full_name }}</p>

                                <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                    <span class="font-mono text-xs font-semibold" style="color:#666666;">{{ $row->student_id }}</span>
                                    <span class="text-[#CCCCCC] text-xs">&bull;</span>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase" style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                        {{ $row->course_code ?? '—' }}
                                    </span>
                                    <span class="text-[#CCCCCC] text-xs">&bull;</span>
                                    <span class="font-mono text-xs font-semibold" style="color:#666666;">Batch {{ $row->batch ?? '—' }}</span>
                                </div>

                                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-semibold border {{ $statusClass }}">
                                        <i class="fa-solid {{ $statusIcon }} text-[8px]"></i>
                                        {{ $statusLabel }}
                                    </span>
                                    @if($row->job_title)
                                        <span class="text-xs font-medium truncate" style="color:#555555;">{{ $row->job_title }}</span>
                                    @elseif($row->employment_status === 'unemployed')
                                        <span class="text-xs italic" style="color:#999999;">
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
                        <p class="font-semibold text-base" style="color:#333333;">
                            @if($search || $filterStatus || $filterBatch || $filterCourse || $filterRelevance)
                                No alumni match your filters
                            @else
                                No alumni found
                            @endif
                        </p>
                        <p class="text-sm mt-1" style="color:#555555;">
                            @if($search || $filterStatus || $filterBatch || $filterCourse || $filterRelevance)
                                Try clearing your filters to see all alumni.
                            @else
                                No verified alumni are registered under your college yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterStatus || $filterBatch || $filterCourse || $filterRelevance)
                        <button wire:click="clearFilters"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                                style="background-color:#7a3f91;">
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
            <div class="ae-table-block-pagination">
                <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                    Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                    of <strong class="text-white font-bold">{{ $total }}</strong>
                    alumni
                    @if($filterStatus || $filterBatch || $filterCourse || $filterRelevance || $search)
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

        </div>{{-- /ae-table-block --}}

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

    $addressParts = array_filter([
        $md['address_street']       ?? '',
        $md['address_barangay']     ?? '',
        $md['address_municipality'] ?? '',
        $md['address_province']     ?? '',
    ]);
    $fullAddress = !empty($addressParts) ? implode(', ', $addressParts) : null;
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

        <div class="flex items-center justify-between px-5 py-4 border-b border-[#E8E0F0] flex-shrink-0"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <img src="{{ $modalPhotoUrl }}"
                     alt="{{ $modalData['full_name'] ?? '' }}"
                     class="w-10 h-10 rounded-xl object-cover flex-shrink-0 ring-2 ring-white/30">
                <div>
                    <p class="font-semibold text-white text-sm leading-snug uppercase">
                        {{ $modalData['full_name'] ?? '—' }}
                        @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
                    </p>
                    <p class="text-xs text-white/70 font-mono mt-0.5">{{ $modalData['student_id'] ?? '—' }}</p>
                </div>
            </div>
            <button type="button"
                    wire:click="closeModal"
                    wire:loading.attr="disabled"
                    wire:target="closeModal"
                    class="ae-close-tooltip w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-5"
             style="scrollbar-width:thin;scrollbar-color:#d9c9e8 #F9F7FC;">

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
                <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0] mb-2">
                    <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Email Address</p>
                    <p class="text-sm font-semibold text-[#333333] break-all">{{ $md['email'] ?? '—' }}</p>
                </div>
                <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                    <p class="text-xs font-semibold uppercase tracking-widest text-[#333333] mb-0.5">Full Address</p>
                    @if($fullAddress)
                        <p class="text-sm font-semibold text-[#333333] uppercase">{{ $fullAddress }}</p>
                    @else
                        <p class="text-sm text-[#999999]">— Not provided</p>
                    @endif
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
        <div class="flex-shrink-0" style="height:0;"></div>
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
    var tip = document.getElementById('ae-hover-tip');
    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }
    function bindRows() {
        document.querySelectorAll('[data-ae-row]').forEach(function (row) {
            if (row._aeTipBound) return;
            row._aeTipBound = true;
            row.addEventListener('mousemove', function (e) {
                if (!tip || !isHoverCapable()) return;
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.classList.add('visible');
            });
            row.addEventListener('mouseleave', function () {
                if (tip) tip.classList.remove('visible');
            });
            row.addEventListener('click', function () {
                if (tip) tip.classList.remove('visible');
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