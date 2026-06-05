{{-- resources/views/livewire/organizer/dashboard.blade.php --}}
<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
new class extends Component {
    public string $activeModal     = '';
    public string $eventModalTitle = '';
    public array  $modalEvents     = [];
    public array  $modalJobs       = [];
    public array  $modalAlumni     = [];
    public string $empModalFilter  = '';
    public string $eventSearch        = '';
    public string $jobSearch          = '';
    public string $alumniSearch       = '';
    public string $alumniFilterCourse = '';
    public string $alumniFilterBatch  = '';
    public int $alumniModalPage    = 1;
    public int $alumniModalSize    = 20;
    public int $eventModalPage     = 1;
    public int $eventModalSize     = 20;
    public int $jobModalPage       = 1;
    public int $jobModalPageSize   = 20;
    public int $empModalPage       = 1;
    public int $empModalSize       = 20;
    public function mount(): void
    {
        if (!auth()->check() || !auth()->user()?->organizer) {
            abort(403, 'Access denied. Organizers only.');
        }
        set_time_limit(120);
    }
    #[Computed] public function organizerName(): string      { return Auth::user()?->organizer?->name ?? Auth::user()?->name ?? 'Organizer'; }
    #[Computed] public function organizerDepartment(): string{ return Auth::user()?->organizer?->department ?? 'Your College'; }
    #[Computed] public function organizerEmail(): string     { return Auth::user()?->organizer?->email ?? Auth::user()?->email ?? ''; }
    #[Computed] public function organizerId(): ?int          { return Auth::user()?->organizer?->id; }
    #[Computed] public function organizerTeacherId(): string { return Auth::user()?->organizer?->id_number ?? '—'; }
    #[Computed] public function organizerBatch(): string     { return Auth::user()?->organizer?->batch ?? ''; }
    #[Computed]
    public function allowedCourseCodes(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        return DB::table('courses')->where('college', $dept)->pluck('code')->toArray();
    }
    #[Computed] public function totalEvents(): int   { return OrganizerEvent::where('organizer_id', $this->organizerId)->whereIn('status', ['PENDING','APPROVED','REJECTED','COMPLETED'])->count(); }
    #[Computed] public function pendingEvents(): int { return OrganizerEvent::where('organizer_id', $this->organizerId)->where('status','PENDING')->count(); }
    #[Computed] public function approvedEvents(): int{ return OrganizerEvent::where('organizer_id', $this->organizerId)->where('status','APPROVED')->count(); }
    #[Computed] public function rejectedEvents(): int{ return OrganizerEvent::where('organizer_id', $this->organizerId)->where('status','REJECTED')->count(); }
    #[Computed] public function totalJobs(): int     { return JobPosting::where('organizer_id', $this->organizerId)->whereIn('status',['ACTIVE','INACTIVE'])->count(); }
    #[Computed] public function activeJobs(): int    { return JobPosting::where('organizer_id', $this->organizerId)->where('status','ACTIVE')->count(); }
    #[Computed] public function inactiveJobs(): int  { return JobPosting::where('organizer_id', $this->organizerId)->where('status','INACTIVE')->count(); }
    #[Computed]
    public function totalAlumniInCollege(): int
    {
        $q = DB::table('alumni')->whereNull('deleted_at');
        if ($this->organizerBatch)             $q->where('batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $q->whereIn('course_code', $this->allowedCourseCodes);
        return $q->count();
    }
    #[Computed]
    public function empCounts(): array
    {
        $base = DB::table('alumni as a')->join('employment_trackings as et','a.id','=','et.alumni_id')->whereNull('a.deleted_at')->whereNull('et.deleted_at');
        if ($this->organizerBatch)             $base->where('a.batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $base->whereIn('a.course_code', $this->allowedCourseCodes);
        $rows       = (clone $base)->select('et.employment_status', DB::raw('COUNT(*) as total'))->groupBy('et.employment_status')->get()->keyBy('employment_status');
        $employed   = (int)($rows['employed']->total ?? 0);
        $self       = (int)($rows['self_employed']->total ?? 0);
        $unemployed = (int)($rows['unemployed']->total ?? 0);
        $noRecord   = max($this->totalAlumniInCollege - $employed - $self - $unemployed, 0);
        return compact('employed','self','unemployed','noRecord');
    }
    #[Computed]
    public function empCourseRelevanceBreakdown(): array
    {
        $base = DB::table('alumni as a')->join('employment_trackings as et','a.id','=','et.alumni_id')->whereNull('a.deleted_at')->whereNull('et.deleted_at');
        if ($this->organizerBatch)             $base->where('a.batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $base->whereIn('a.course_code', $this->allowedCourseCodes);
        $working      = (clone $base)->whereIn('et.employment_status',['employed','self_employed']);
        $rows         = (clone $working)->select('et.course_relevance', DB::raw('COUNT(*) as total'))->groupBy('et.course_relevance')->get()->keyBy('course_relevance');
        $related      = (int)($rows['yes']->total ?? 0);
        $notRelated   = (int)($rows['no']->total ?? 0);
        $partial      = (int)($rows['partially']->total ?? 0);
        $totalWorking = (clone $working)->count();
        $notSpecified = max(0, $totalWorking - $related - $notRelated - $partial);
        return compact('related','notRelated','partial','notSpecified','totalWorking');
    }
    #[Computed]
    public function alumniByDepartment(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        $courses = Course::where('college', $dept)->orderBy('code')->get();
        $result  = [];
        foreach ($courses as $course) {
            $q = Alumni::where('course_code', $course->code);
            if ($this->organizerBatch) $q->where('batch', $this->organizerBatch);
            $result[$course->code] = ['count' => $q->count(), 'name' => $course->name ?? $course->code];
        }
        return $result;
    }
    #[Computed] public function greeting(): string  { $h = now('Asia/Manila')->hour; return match(true){$h<12=>'Good Morning',$h<18=>'Good Afternoon',default=>'Good Evening'}; }
    #[Computed] public function todayDate(): string { return now('Asia/Manila')->format('l, F j, Y'); }
    #[Computed]
    public function modalAlumniBatches(): array
    {
        $q = DB::table('alumni')->whereNull('deleted_at');
        if ($this->organizerBatch)             $q->where('batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $q->whereIn('course_code', $this->allowedCourseCodes);
        return $q->distinct()->orderBy('batch','desc')->pluck('batch')->toArray();
    }
    #[Computed] public function modalAlumniCourses(): array { return $this->allowedCourseCodes; }

    // ── Navigation helpers (same pattern as alumni dashboard) ──
    public function goToEvents(): void
    {
        // Empty = show all events (clear any previous filter)
        session()->put('organizer_events_filter', '');
        $this->redirect(route('organizer.event/organizer'));
    }
    public function goToPendingEvents(): void
    {
        session()->put('organizer_events_filter', 'PENDING');
        $this->redirect(route('organizer.event/organizer'));
    }
    public function goToJobs(): void
    {
        // Empty = show all jobs (clear any previous filter)
        session()->put('organizer_jobs_filter', '');
        $this->redirect(route('organizer.job/management'));
    }
    public function goToEmployment(string $filter = ''): void
    {
        // Map dashboard filter keys to employment page filterStatus values
        $mapped = match($filter) {
            'employed'      => 'employed',
            'self_employed' => 'self_employed',
            'unemployed'    => 'unemployed',
            'no_record'     => 'not_filled',   // dashboard says no_record, page uses not_filled
            default         => '',
        };
        session()->put('organizer_employment_filter', $mapped);
        $this->redirect(route('organizer.alumni/employment'));
    }

    protected function buildAlumniModalRows(): array
    {
        $codes      = $this->allowedCourseCodes;
        $alumniRows = DB::table('alumni')->whereNull('deleted_at')
            ->when(!empty($codes), fn($q)=>$q->whereIn('course_code',$codes))
            ->when($this->organizerBatch, fn($q)=>$q->where('batch',$this->organizerBatch))
            ->select('id','first_name','last_name','course_code','student_id','batch')
            ->orderBy('course_code')->orderBy('last_name')->get();
        $empMap = DB::table('employment_trackings')->whereNull('deleted_at')
            ->whereIn('alumni_id',$alumniRows->pluck('id'))->orderByDesc('created_at')
            ->get(['alumni_id','employment_status','job_title','company_name','course_relevance'])
            ->unique('alumni_id')->keyBy('alumni_id');
        return $alumniRows->map(fn($r)=>[
            'id'               => $r->id,
            'name'             => strtoupper(trim(($r->first_name??'').' '.($r->last_name??''))),
            'student_id'       => $r->student_id ?? '—',
            'course'           => $r->course_code ?? '—',
            'batch'            => $r->batch ?? '—',
            'status'           => $empMap[$r->id]?->employment_status ?? null,
            'job_title'        => $empMap[$r->id]?->job_title ?? null,
            'company_name'     => $empMap[$r->id]?->company_name ?? null,
            'course_relevance' => $empMap[$r->id]?->course_relevance ?? null,
        ])->toArray();
    }
    public function openTotalAlumniModal(): void  { $this->modalAlumni=$this->buildAlumniModalRows(); $this->alumniSearch=''; $this->alumniFilterCourse=''; $this->alumniFilterBatch=''; $this->alumniModalPage=1; $this->activeModal='alumni'; }
    public function closeModal(): void { $this->activeModal = ''; }
    public function updatingAlumniSearch(): void       { $this->alumniModalPage = 1; }
    public function updatingAlumniFilterCourse(): void { $this->alumniModalPage = 1; }
    public function updatingAlumniFilterBatch(): void  { $this->alumniModalPage = 1; }
    public function alumniPrevPage(): void                  { if($this->alumniModalPage>1)$this->alumniModalPage--; }
    public function alumniNextPage(int $last): void         { if($this->alumniModalPage<$last)$this->alumniModalPage++; }
};
?>
<div>

<style>
/* ── Stat card tooltip ── */
.org-stat-card { position: relative; overflow: visible; }
.org-stat-card .stat-tooltip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.org-stat-card .stat-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
.org-stat-card:hover .stat-tooltip { opacity: 1; }

/* ── Employment mini-card ── */
.org-emp-card { position: relative; overflow: visible; cursor: pointer; transition: transform .12s ease, box-shadow .15s ease; }
.org-emp-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.10); }
.org-emp-card:active { transform: scale(.97); }
.org-emp-card .emp-tooltip {
    position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.org-emp-card .emp-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
.org-emp-card:hover .emp-tooltip { opacity: 1; }

/* ── Main grid ── */
.org-main-grid { display: grid; grid-template-columns: 300px 1fr; gap: 1rem; align-items: stretch; }
@media (max-width: 1023px) { .org-main-grid { grid-template-columns: 1fr; } }

/* ── Account column & card ── */
.org-account-col { display: flex; flex-direction: column; }
.org-account-card { flex: 1; display: flex; flex-direction: column; min-height: 0; }

/* ── Right col ── */
.org-right-col { display: flex; flex-direction: column; gap: 1rem; }

/* ── 2x2 stat grid ── */
.org-stat-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 0.75rem; }
.org-stat-grid .org-stat-card { height: 100%; display: flex; flex-direction: column; justify-content: center; }

/* ── Info body fills space ── */
.org-info-body { flex: 1; display: flex; flex-direction: column; overflow-y: auto; min-height: 0; }

/* ── Info rows ── */
.org-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.org-info-row:last-child { border-bottom: none; }
.org-info-label {
    font-size: 0.70rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.07em; color: #555555; flex-shrink: 0;
}
.org-info-value {
    font-size: 0.875rem; font-weight: 600; color: #111111;
    text-align: right; overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap; max-width: 165px;
}
.org-info-value-sm {
    font-size: 0.80rem; font-weight: 600; color: #111111;
    text-align: right; word-break: break-all; max-width: 165px;
}

/* ── Course chips section ── */
.org-courses-section { padding: 0.65rem 1rem; }
.org-courses-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #777777; margin-bottom: 0.4rem; }
.org-course-chip {
    font-size: 0.72rem; font-weight: 600; padding: 2px 9px; border-radius: 999px;
    background: #F0E6F8; color: #333333; border: 1px solid #D8BEF0;
    display: inline-block; margin: 2px 2px 2px 0;
}

/* ── Avatar ── */
.org-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: rgba(255,255,255,0.22); border: 2px solid rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; font-weight: 700; color: #ffffff; flex-shrink: 0; letter-spacing: 0.04em;
}

/* ── Modal close button ── */
.org-close-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 16px; border-radius: 10px; background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2); color: #fff; font-size: .875rem;
    font-weight: 600; cursor: pointer; transition: background .15s;
}
.org-close-btn:hover { background: rgba(255,255,255,.22); }

/* ── Pagination ── */
.org-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; transition: all .15s; border: 1.5px solid transparent;
}
.org-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
.org-pg-nav { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
.org-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.org-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Scrollbar ── */
.org-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.org-scroll::-webkit-scrollbar { width: 4px; }
.org-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── Table rows ── */
.org-table-row { transition: background .10s; }
.org-table-row:hover { background: #F5F0FA !important; }

/* ── Modal animation ── */
@keyframes orgModalIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
.org-modal-enter { animation: orgModalIn .2s cubic-bezier(.4,0,.2,1) both; }
</style>

<div class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto">

    {{-- PAGE HEADER --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
                {{ $this->greeting }}, {{ $this->organizerName }}
            </h1>
            <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                <span>{{ $this->todayDate }}</span>
                @if($this->organizerDepartment)
                    <span class="text-[#c0a0d8]">·</span>
                    <span class="font-semibold text-[#7A3F91]">{{ $this->organizerDepartment }}</span>
                @endif
                @if($this->organizerBatch)
                    <span class="text-[#c0a0d8]">·</span>
                    <span class="font-semibold text-[#7A3F91]">Batch {{ $this->organizerBatch }}</span>
                @endif
            </p>
        </div>
    </div>

    <div class="org-main-grid">

        {{-- ══ LEFT: Account Card ══ --}}
        <div class="org-account-col">
            <div class="org-account-card rounded-2xl overflow-hidden shadow-md border border-[#E8E0F0] bg-white">

                {{-- Header with avatar --}}
                <div class="px-4 py-4 shrink-0 flex items-center gap-3"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    @php
                        $initials = collect(explode(' ', $this->organizerName))
                            ->filter()->take(2)->map(fn($w) => strtoupper($w[0]))->implode('');
                    @endphp
                    <div class="org-avatar">{{ $initials ?: 'OR' }}</div>
                    <div class="min-w-0">
                        <p class="text-[0.60rem] font-bold uppercase tracking-[0.14em] text-white/60 leading-none mb-0.5">ORGANIZER ACCOUNT</p>
                        <p class="text-[0.95rem] font-bold text-white leading-snug truncate">{{ $this->organizerName }}</p>
                        <p class="text-[0.72rem] text-white/70 font-normal truncate mt-0.5">{{ $this->organizerDepartment }}</p>
                    </div>
                </div>

                {{-- Info body --}}
                <div class="org-info-body org-scroll">

                    <div class="org-info-row">
                        <span class="org-info-label">Name</span>
                        <div class="group/tip relative">
                            <span class="org-info-value cursor-default">{{ $this->organizerName }}</span>
                            <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100 absolute bottom-[125%] right-0 bg-[#1a1a1a] text-white rounded-xl px-3 py-2 z-[9990] whitespace-nowrap text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                                {{ $this->organizerName }}
                            </div>
                        </div>
                    </div>

                    <div class="org-info-row">
                        <span class="org-info-label">Teacher ID</span>
                        <span class="font-mono text-sm font-semibold text-[#111111]">{{ $this->organizerTeacherId }}</span>
                    </div>

                    <div class="org-info-row" style="align-items:flex-start;">
                        <span class="org-info-label" style="margin-top:2px;">Email</span>
                        <div class="group/tip relative">
                            <span class="org-info-value-sm cursor-default">{{ $this->organizerEmail }}</span>
                            <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100 absolute bottom-[125%] right-0 bg-[#1a1a1a] text-white rounded-xl px-3 py-2 z-[9990] whitespace-nowrap text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                                {{ $this->organizerEmail }}
                            </div>
                        </div>
                    </div>

                    <div class="org-info-row">
                        <span class="org-info-label">College</span>
                        <div class="group/tip relative">
                            <span class="org-info-value cursor-default">{{ $this->organizerDepartment }}</span>
                            <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100 absolute bottom-[125%] right-0 bg-[#1a1a1a] text-white rounded-xl px-3 py-2 z-[9990] whitespace-nowrap text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                                {{ $this->organizerDepartment }}
                            </div>
                        </div>
                    </div>

                    @if($this->organizerBatch)
                    <div class="org-info-row">
                        <span class="org-info-label">Batch</span>
                        <span class="text-[0.80rem] font-bold px-3 py-0.5 rounded-full text-white" style="background:#7A3F91;">{{ $this->organizerBatch }}</span>
                    </div>
                    @endif

                    @if(!empty($this->alumniByDepartment))
                    <div class="org-courses-section">
                        <p class="org-courses-label">Alumni per Course</p>
                        <div>
                            @foreach($this->alumniByDepartment as $code => $info)
                                <span class="org-course-chip">{{ $code }} · {{ $info['count'] }}</span>
                            @endforeach
                        </div>
                    </div>
                    @endif

                </div>{{-- end info body --}}
            </div>
        </div>

        {{-- ══ RIGHT: Stat Cards + Employment Overview ══ --}}
        <div class="org-right-col">

            @php $ec = $this->empCounts; @endphp
            <div class="org-stat-grid">

                {{-- Total Alumni — untouched, still opens modal --}}
                <button wire:click="openTotalAlumniModal"
                        class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-[#7A3F91]/40 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="stat-tooltip"><i class="fas fa-arrow-right mr-1.5"></i>View All Alumni</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-graduation-cap text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                     border border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">Alumni</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($this->totalAlumniInCollege) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Total Alumni</p>
                </button>

                {{-- Total Events — redirect to event management --}}
                <button wire:click="goToEvents"
                        class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-emerald-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="stat-tooltip"><i class="fas fa-arrow-right mr-1.5"></i>View All Events</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                            <i class="fas fa-calendar-days text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                     border border-emerald-200 bg-emerald-50 text-[0.75rem]">Events</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($this->totalEvents) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Total Events</p>
                    @if($this->approvedEvents > 0)
                        <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                            <i class="fas fa-circle-check text-xs"></i> {{ $this->approvedEvents }} Approved
                        </p>
                    @endif
                </button>

                {{-- Pending Events — redirect to event management with filter --}}
                <button wire:click="goToPendingEvents"
                        class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-amber-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="stat-tooltip"><i class="fas fa-arrow-right mr-1.5"></i>View Pending Events</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                            <i class="fas fa-hourglass-end text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                     border border-amber-200 bg-amber-50 text-[0.75rem]">Pending</span>
                    </div>
                    <p class="text-amber-600 font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($this->pendingEvents) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Pending Review</p>
                    @if($this->rejectedEvents > 0)
                        <p class="text-red-500 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                            <i class="fas fa-circle-xmark text-xs"></i> {{ $this->rejectedEvents }} Rejected
                        </p>
                    @else
                        <p class="text-[#555555] font-normal mt-1 text-[0.85rem]">Awaiting admin approval</p>
                    @endif
                </button>

                {{-- Job Postings — redirect to job management --}}
                <button wire:click="goToJobs"
                        class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-blue-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="stat-tooltip"><i class="fas fa-arrow-right mr-1.5"></i>View All Job Postings</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                            <i class="fas fa-briefcase text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                     border border-blue-200 bg-blue-50 text-[0.75rem]">Jobs</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($this->totalJobs) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Job Postings</p>
                    <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle text-[8px]"></i> {{ $this->activeJobs }} Active
                        <span class="text-[#555555] font-normal">· {{ $this->inactiveJobs }} Inactive</span>
                    </p>
                </button>

            </div>{{-- end stat grid --}}

            {{-- EMPLOYMENT OVERVIEW PANEL --}}
            @php
                $crb = $this->empCourseRelevanceBreakdown;
                $empRows = [
                    ['label'=>'Employed',      'count'=>$ec['employed'],   'icon'=>'fa-user-tie',        'cardCls'=>'bg-[#F9F7FC] border-[#E8E0F0]',    'iconCls'=>'bg-[#7A3F91]/10 text-[#7A3F91]', 'cntCls'=>'text-[#333333]',  'filter'=>'employed',      'ctip'=>'View Employed Alumni'],
                    ['label'=>'Self-Employed', 'count'=>$ec['self'],       'icon'=>'fa-store',            'cardCls'=>'bg-blue-50 border-blue-200',        'iconCls'=>'bg-blue-100 text-blue-600',       'cntCls'=>'text-blue-700',   'filter'=>'self_employed', 'ctip'=>'View Self-Employed Alumni'],
                    ['label'=>'Unemployed',    'count'=>$ec['unemployed'], 'icon'=>'fa-magnifying-glass', 'cardCls'=>'bg-amber-50 border-amber-200',      'iconCls'=>'bg-amber-100 text-amber-600',      'cntCls'=>'text-amber-700',  'filter'=>'unemployed',    'ctip'=>'View Unemployed Alumni'],
                    ['label'=>'No Record',     'count'=>$ec['noRecord'],   'icon'=>'fa-circle-minus',     'cardCls'=>'bg-[#ede0f5] border-[#c9ace0]',    'iconCls'=>'bg-[#7A3F91]/15 text-[#7A3F91]', 'cntCls'=>'text-[#333333]',  'filter'=>'no_record',     'ctip'=>'View Alumni With No Record'],
                ];
            @endphp
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right, #F9F7FC, #ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-briefcase text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Employment Overview</p>
                        <span class="text-[10px] text-[#999999] font-normal hidden sm:inline">— click a card to view</span>
                    </div>
                    <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
                       class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                        View All <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <div class="p-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                        @foreach($empRows as $row)
                        {{-- Redirect to employment page with filter via session --}}
                        <div wire:click="goToEmployment('{{ $row['filter'] }}')"
                             class="org-emp-card rounded-xl border p-3 {{ $row['cardCls'] }}">
                            <span class="emp-tooltip"><i class="fas fa-arrow-right mr-1"></i>{{ $row['ctip'] }}</span>
                            <div class="flex items-center gap-1.5 mb-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $row['iconCls'] }}">
                                    <i class="fas {{ $row['icon'] }} text-xs"></i>
                                </div>
                                <span class="text-xs font-bold text-[#333333]">{{ $row['label'] }}</span>
                            </div>
                            <p class="text-2xl font-extrabold leading-none {{ $row['cntCls'] }}">{{ number_format($row['count']) }}</p>
                        </div>
                        @endforeach
                    </div>

                    @if($crb['totalWorking'] > 0)
                    <div class="border-t border-[#E8E0F0] pt-4">
                        <p class="text-xs font-semibold text-[#444444] uppercase tracking-wide mb-2 flex items-center gap-1.5">
                            <i class="fas fa-graduation-cap text-[#7A3F91]"></i>
                            Course Relevance (Employed Only)
                        </p>
                        @php
                            $specifiedTotal = $crb['related'] + $crb['partial'] + $crb['notRelated'];
                            $showRelevance  = $specifiedTotal > 0;
                        @endphp
                        @if($showRelevance)
                            <div class="grid grid-cols-3 gap-2 mb-2">
                                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-2.5 text-center">
                                    <p class="text-xl font-semibold text-emerald-700 leading-none">{{ number_format($crb['related']) }}</p>
                                    <p class="text-xs font-semibold text-emerald-700 mt-1 flex items-center justify-center gap-0.5">
                                        <i class="fas fa-circle-check text-xs"></i> Related
                                    </p>
                                </div>
                                <div class="rounded-xl border border-amber-200 bg-amber-50 p-2.5 text-center">
                                    <p class="text-xl font-semibold text-amber-700 leading-none">{{ number_format($crb['partial']) }}</p>
                                    <p class="text-xs font-semibold text-amber-700 mt-1 flex items-center justify-center gap-0.5">
                                        <i class="fas fa-circle-half-stroke text-xs"></i> Partial
                                    </p>
                                </div>
                                <div class="rounded-xl border border-red-200 bg-red-50 p-2.5 text-center">
                                    <p class="text-xl font-semibold text-red-700 leading-none">{{ number_format($crb['notRelated']) }}</p>
                                    <p class="text-xs font-semibold text-red-700 mt-1 flex items-center justify-center gap-0.5">
                                        <i class="fas fa-circle-xmark text-xs"></i> Not Related
                                    </p>
                                </div>
                            </div>
                            <div class="rounded-full h-1.5 overflow-hidden bg-[#7A3F91]/10">
                                <div class="h-full flex">
                                    @if($crb['related'] > 0)<div class="h-full bg-emerald-500" style="width:{{ ($crb['related']/$specifiedTotal)*100 }}%"></div>@endif
                                    @if($crb['partial'] > 0)<div class="h-full bg-amber-400" style="width:{{ ($crb['partial']/$specifiedTotal)*100 }}%"></div>@endif
                                    @if($crb['notRelated'] > 0)<div class="h-full bg-red-500" style="width:{{ ($crb['notRelated']/$specifiedTotal)*100 }}%"></div>@endif
                                </div>
                            </div>
                        @else
                            <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5">
                                <i class="fas fa-circle-info text-gray-400 text-sm shrink-0"></i>
                                <p class="text-xs text-[#444444] font-normal">
                                    Course relevance not yet specified for
                                    <strong class="font-semibold text-[#333333]">{{ $crb['totalWorking'] }}</strong>
                                    employed / self-employed alumni.
                                </p>
                            </div>
                        @endif
                        <p class="text-xs text-[#777777] mt-1.5 font-normal">
                            Out of <strong class="text-[#333333]">{{ number_format($crb['totalWorking']) }}</strong> employed / self-employed alumni
                            @if(($crb['notSpecified'] ?? 0) > 0 && $showRelevance)
                                · <span class="text-amber-700 font-semibold">{{ $crb['notSpecified'] }} not yet specified</span>
                            @endif
                        </p>
                    </div>
                    @endif
                </div>
            </div>

        </div>{{-- end right col --}}
    </div>{{-- end main grid --}}

</div>


{{-- ═══ MODAL: TOTAL ALUMNI (untouched) ═══ --}}
@if($activeModal === 'alumni')
@php
    $statusLabels = [
        'employed'      => ['Employed',     'text-[#333333] bg-[#F9F7FC] border-[#E8E0F0]'],
        'self_employed' => ['Self-Employed', 'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',    'text-amber-700 bg-amber-50 border-amber-200'],
    ];
    $filteredAlumni = collect($modalAlumni)
        ->when($alumniSearch !== '', fn($c) => $c->filter(fn($a) =>
            str_contains(strtolower($a['name']), strtolower($alumniSearch)) ||
            str_contains(strtolower($a['student_id']), strtolower($alumniSearch)) ||
            str_contains(strtolower($a['course']), strtolower($alumniSearch))
        ))
        ->when($alumniFilterCourse !== '', fn($c) => $c->filter(fn($a) => strtolower($a['course']) === strtolower($alumniFilterCourse)))
        ->when($alumniFilterBatch !== '', fn($c) => $c->filter(fn($a) => (string)$a['batch'] === (string)$alumniFilterBatch))
        ->values();
    $alumniTotal    = $filteredAlumni->count();
    $alumniLastPage = max((int) ceil($alumniTotal / $alumniModalSize), 1);
    $alumniSafePage = min($alumniModalPage, $alumniLastPage);
    $alumniFrom     = $alumniTotal > 0 ? ($alumniSafePage - 1) * $alumniModalSize + 1 : 0;
    $alumniTo       = min($alumniSafePage * $alumniModalSize, $alumniTotal);
    $displayAlumni  = $filteredAlumni->slice(($alumniSafePage - 1) * $alumniModalSize, $alumniModalSize)->values()->toArray();
    $hasFilter      = $alumniSearch || $alumniFilterCourse || $alumniFilterBatch;
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 org-modal-enter" @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-graduation-cap text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Total Alumni</h2>
                <p class="text-white/60 text-xs font-normal">{{ $alumniFrom }}–{{ $alumniTo }} of {{ $alumniTotal }} alumni @if($hasFilter)<span class="text-white/40 ml-1">(filtered)</span>@endif</p>
            </div>
        </div>
        <button wire:click="closeModal" class="org-close-btn"><i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span></button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.alumniSearch??''; $wire.$watch('alumniSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('alumniSearch', q)" placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all" autocomplete="off">
            </div>
            @if(!empty($this->modalAlumniCourses))
            <div class="relative">
                <select wire:model.live="alumniFilterCourse" class="appearance-none pl-3 pr-8 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition cursor-pointer">
                    <option value="">All Courses</option>
                    @foreach($this->modalAlumniCourses as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach
                </select>
                <i class="fas fa-chevron-down text-gray-400 text-xs absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
            </div>
            @endif
            @if(!empty($this->modalAlumniBatches))
            <div class="relative">
                <select wire:model.live="alumniFilterBatch" class="appearance-none pl-3 pr-8 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition cursor-pointer">
                    <option value="">All Batches</option>
                    @foreach($this->modalAlumniBatches as $b)<option value="{{ $b }}">Batch {{ $b }}</option>@endforeach
                </select>
                <i class="fas fa-chevron-down text-gray-400 text-xs absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
            </div>
            @endif
            @if($hasFilter)
            <button wire:click="$set('alumniSearch',''); $set('alumniFilterCourse',''); $set('alumniFilterBatch',''); $set('alumniModalPage', 1)"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-red-200 bg-red-50 text-sm font-semibold text-red-600 hover:bg-red-100 transition active:scale-95">
                <i class="fas fa-rotate-left text-xs"></i> Reset Filters
            </button>
            @endif
            <span class="text-xs text-gray-400 font-normal hidden sm:inline ml-auto">Showing <strong class="text-gray-600">{{ $alumniFrom }}–{{ $alumniTo }}</strong> of <strong class="text-gray-600">{{ $alumniTotal }}</strong></span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto org-scroll min-h-0">
        <table class="w-full border-collapse min-w-[500px]">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Student ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Batch</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Employment</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayAlumni as $idx => $a)
                <tr class="org-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3"><span class="text-xs font-semibold text-[#c0a0d8]">{{ str_pad($alumniFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span></td>
                    <td class="px-4 py-3"><p class="text-sm font-semibold text-gray-900">{{ $a['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3"><p class="text-sm font-mono text-gray-600">{{ $a['student_id'] }}</p></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#333333] border-[#E8E0F0]">{{ $a['course'] }}</span>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell"><p class="text-sm text-gray-500">{{ $a['batch'] }}</p></td>
                    <td class="px-4 py-3 text-center">
                        @if($a['status'] && isset($statusLabels[$a['status']]))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $statusLabels[$a['status']][1] }}">{{ $statusLabels[$a['status']][0] }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">No Record</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f0e6f8]"><i class="fas fa-graduation-cap text-2xl text-[#c89de0]"></i></div>
                        <p class="text-sm font-semibold text-gray-400">No alumni found</p>
                        <p class="text-xs text-gray-300">Try adjusting your search or filters</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-[#7A3F91]">
        <p class="text-white text-sm font-normal">Showing <strong class="font-bold text-base">{{ $alumniFrom }}–{{ $alumniTo }}</strong> of <strong class="font-bold text-base">{{ $alumniTotal }}</strong> alumni @if($hasFilter)<span class="text-white/60 text-xs ml-1">(filtered)</span>@endif</p>
        @if($alumniLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="alumniPrevPage" {{ $alumniSafePage <= 1 ? 'disabled' : '' }} class="org-pg-btn org-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @for($p = max(1, $alumniSafePage - 2); $p <= min($alumniLastPage, $alumniSafePage + 2); $p++)
                @if($p === $alumniSafePage)<span class="org-pg-btn org-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('alumniModalPage', {{ $p }})" class="org-pg-btn org-pg-nav">{{ $p }}</button>@endif
            @endfor
            <button wire:click="alumniNextPage({{ $alumniLastPage }})" {{ $alumniSafePage >= $alumniLastPage ? 'disabled' : '' }} class="org-pg-btn org-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $alumniSafePage }}/{{ $alumniLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif

</div>