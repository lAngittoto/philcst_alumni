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
    #[Computed] public function recentEvents() { return OrganizerEvent::where('organizer_id',$this->organizerId)->whereIn('status',['PENDING','APPROVED','REJECTED','COMPLETED'])->orderBy('created_at','desc')->limit(5)->get(); }
    #[Computed] public function recentJobs()   { return JobPosting::where('organizer_id',$this->organizerId)->whereIn('status',['ACTIVE','INACTIVE'])->orderBy('created_at','desc')->limit(5)->get(); }
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
    protected function buildAlumniModalRows(): array
    {
        $codes     = $this->allowedCourseCodes;
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
    public function openEmploymentModal(string $filter=''): void { $this->modalAlumni=$this->buildAlumniModalRows(); $this->alumniSearch=''; $this->alumniFilterCourse=''; $this->alumniFilterBatch=''; $this->empModalFilter=$filter; $this->empModalPage=1; $this->activeModal='employment'; }
    protected function buildEventRows(string $status=''): array
    {
        $q = OrganizerEvent::where('organizer_id', $this->organizerId);
        $status ? $q->where('status',$status) : $q->whereIn('status',['PENDING','APPROVED','REJECTED','COMPLETED']);
        return $q->orderByDesc('event_date')->get(['id','title','event_date','venue','status','photo'])
            ->map(fn($e)=>['id'=>$e->id,'title'=>$e->title,'date'=>$e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),'time'=>$e->event_date->setTimezone('Asia/Manila')->format('h:i A'),'venue'=>$e->venue??'','status'=>$e->status,'photo'=>$e->photo_url??''])->toArray();
    }
    public function openTotalEventsModal(): void    { $this->eventModalTitle='All My Events';    $this->modalEvents=$this->buildEventRows();           $this->eventSearch=''; $this->eventModalPage=1; $this->activeModal='events'; }
    public function openPendingEventsModal(): void  { $this->eventModalTitle='Pending Events';   $this->modalEvents=$this->buildEventRows('PENDING');  $this->eventSearch=''; $this->eventModalPage=1; $this->activeModal='events'; }
    public function openApprovedEventsModal(): void { $this->eventModalTitle='Approved Events';  $this->modalEvents=$this->buildEventRows('APPROVED'); $this->eventSearch=''; $this->eventModalPage=1; $this->activeModal='events'; }
    public function openRejectedEventsModal(): void { $this->eventModalTitle='Rejected Events';  $this->modalEvents=$this->buildEventRows('REJECTED'); $this->eventSearch=''; $this->eventModalPage=1; $this->activeModal='events'; }
    public function openCompletedEventsModal(): void{ $this->eventModalTitle='Completed Events'; $this->modalEvents=$this->buildEventRows('COMPLETED');$this->eventSearch=''; $this->eventModalPage=1; $this->activeModal='events'; }
    public function openEventModalByStatus(string $status): void { match($status){'PENDING'=>$this->openPendingEventsModal(),'APPROVED'=>$this->openApprovedEventsModal(),'REJECTED'=>$this->openRejectedEventsModal(),'COMPLETED'=>$this->openCompletedEventsModal(),default=>$this->openTotalEventsModal()}; }
    protected function buildJobRows(string $status=''): array
    {
        $q = JobPosting::where('organizer_id', $this->organizerId);
        $status ? $q->where('status',$status) : $q->whereIn('status',['ACTIVE','INACTIVE']);
        return $q->orderByDesc('created_at')->get(['id','job_title','company_name','employment_type','location','deadline','salary','status'])
            ->map(fn($j)=>['id'=>$j->id,'title'=>$j->job_title,'company'=>$j->company_name,'type'=>$j->employment_type,'location'=>$j->location??'','salary'=>$j->salary??'','deadline'=>Carbon::parse($j->deadline)->setTimezone('Asia/Manila')->format('M d, Y'),'days_left'=>(int)now('Asia/Manila')->startOfDay()->diffInDays(Carbon::parse($j->deadline)->startOfDay(),false),'status'=>$j->status])->toArray();
    }
    public function openActiveJobsModal(): void  { $this->modalJobs=$this->buildJobRows('ACTIVE');   $this->jobSearch=''; $this->jobModalPage=1; $this->activeModal='jobs'; }
    public function openInactiveJobsModal(): void{ $this->modalJobs=$this->buildJobRows('INACTIVE'); $this->jobSearch=''; $this->jobModalPage=1; $this->activeModal='jobs'; }
    public function openJobsModal(): void        { $this->modalJobs=$this->buildJobRows();           $this->jobSearch=''; $this->jobModalPage=1; $this->activeModal='jobs'; }
    public function openJobModalByStatus(string $status): void { match($status){'ACTIVE'=>$this->openActiveJobsModal(),'INACTIVE'=>$this->openInactiveJobsModal(),default=>$this->openJobsModal()}; }
    public function closeModal(): void { $this->activeModal = ''; }
    public function updatingEventSearch(): void        { $this->eventModalPage  = 1; }
    public function updatingJobSearch(): void          { $this->jobModalPage    = 1; }
    public function updatingAlumniSearch(): void       { $this->alumniModalPage = 1; }
    public function updatingAlumniFilterCourse(): void { $this->alumniModalPage = 1; }
    public function updatingAlumniFilterBatch(): void  { $this->alumniModalPage = 1; }
    public function alumniPrevPage(): void                  { if($this->alumniModalPage>1)$this->alumniModalPage--; }
    public function alumniNextPage(int $last): void         { if($this->alumniModalPage<$last)$this->alumniModalPage++; }
    public function eventPrevPage(): void                   { if($this->eventModalPage>1)$this->eventModalPage--; }
    public function eventNextPage(int $last): void          { if($this->eventModalPage<$last)$this->eventModalPage++; }
    public function jobPrevPage(): void                     { if($this->jobModalPage>1)$this->jobModalPage--; }
    public function jobNextPage(int $last): void            { if($this->jobModalPage<$last)$this->jobModalPage++; }
    public function empPrevPage(): void                     { if($this->empModalPage>1)$this->empModalPage--; }
    public function empNextPage(int $last): void            { if($this->empModalPage<$last)$this->empModalPage++; }
};
?>
<div>{{-- single Livewire root --}}

{{-- ══ Cursor-following floating tooltip ══ --}}
<div id="org-float-tip"></div>

<style>
/* ── Cursor-following tooltip ─────────────────────────────── */
#org-float-tip {
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
    box-shadow: 0 4px 14px rgba(0,0,0,.35);
    transform: translate(-50%, calc(-100% - 10px));
}

/* ── Stat cards ───────────────────────────────────────────── */
.org-stat-card {
    position: relative;
    overflow: hidden;
    transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease;
    cursor: pointer;
}
.org-stat-card:active { transform: scale(.985); }

/* ── "View Details" slide-up overlay ─────────────────────── */
.org-view-overlay {
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transform: translateY(100%);
    transition: transform .2s ease-out;
    border-radius: 0 0 1rem 1rem;
    z-index: 10;
    pointer-events: none;
}
.org-stat-card:hover .org-view-overlay { transform: translateY(0); }

/* ── Employment mini-card overlay ────────────────────────── */
.org-emp-card {
    position: relative;
    overflow: hidden;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .15s ease;
}
.org-emp-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.10); }
.org-emp-card:active { transform: scale(.97); }
.org-emp-overlay {
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 1.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transform: translateY(100%);
    transition: transform .2s ease-out;
    border-radius: 0 0 .75rem .75rem;
    z-index: 10;
    pointer-events: none;
}
.org-emp-card:hover .org-emp-overlay { transform: translateY(0); }

/* ── List-row hover badges ───────────────────────────────── */
.org-list-row { transition: background .12s ease; }
.org-list-row:hover { background: #faf7ff !important; }
.org-list-row .view-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 7px;
    border-radius: 6px;
    background: #ede0f5;
    color: #7A3F91;
    border: 1px solid #d4aaeb;
    opacity: 0;
    transition: opacity .15s ease;
    white-space: nowrap;
    flex-shrink: 0;
    align-self: center;
}
.org-list-row:hover .view-badge { opacity: 1; }

/* ── Table row ───────────────────────────────────────────── */
.org-table-row { transition: background .10s; }
.org-table-row:hover { background: #F5F0FA !important; }

/* ── Modal close button ──────────────────────────────────── */
.org-close-btn {
    position: relative;
    display: flex; align-items: center; justify-content: center;
    gap: 6px;
    padding: 6px 16px;
    border-radius: 10px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.org-close-btn:hover { background: rgba(255,255,255,.22); }

/* ── Pagination ──────────────────────────────────────────── */
.org-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px;
    border-radius: 8px; font-size: .75rem; font-weight: 700;
    transition: all .15s; border: 1.5px solid transparent;
}
.org-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
.org-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
.org-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.org-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Scrollbar ───────────────────────────────────────────── */
.org-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.org-scroll::-webkit-scrollbar { width: 4px; }
.org-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── Modal animation ─────────────────────────────────────── */
@keyframes orgModalIn {
    from { opacity:0; transform: translateY(8px); }
    to   { opacity:1; transform: translateY(0); }
}
.org-modal-enter { animation: orgModalIn .2s cubic-bezier(.4,0,.2,1) both; }
</style>

{{-- ═══════════════════════════════════════════════════════════════
     MAIN PAGE
════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 max-w-screen-2xl mx-auto w-full"
     style="height:90vh;">

    {{-- PAGE HEADER --}}
    <div class="flex items-center gap-3 mb-3 shrink-0">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg shrink-0 bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]">
            <i class="fas fa-gauge-high text-white text-sm"></i>
        </div>
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold text-[#333333] leading-tight truncate">
                {{ $this->greeting }}, {{ $this->organizerName }}
            </h1>
            <p class="text-xs text-[#666666] font-normal flex flex-wrap items-center gap-x-1.5">
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

    {{-- ── STAT CARDS ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 shrink-0 mb-2.5">

        {{-- Total Alumni --}}
        <div wire:click="openTotalAlumniModal"
             data-ctip="View All Alumni"
             class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3
                    hover:shadow-md hover:border-[#7A3F91]/40">

            <div class="org-view-overlay bg-gradient-to-t from-[#7A3F91] to-[#9b59b6]">
                <i class="fas fa-eye text-white text-[10px]"></i>
                <span class="text-white text-[11px] font-bold tracking-wide">View Details</span>
            </div>

            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]">
                    <i class="fas fa-graduation-cap text-white text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">Alumni</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalAlumniInCollege) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Total Alumni</p>
            @if(!empty($this->alumniByDepartment))
                <div class="flex flex-wrap gap-1 mt-1.5 pb-7">
                    @foreach($this->alumniByDepartment as $code => $info)
                        <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full bg-[#7A3F91]/10 text-[#7A3F91] border border-[#7A3F91]/20">
                            {{ $code }} · {{ $info['count'] }}
                        </span>
                    @endforeach
                </div>
            @else
                <div class="pb-7"></div>
            @endif
        </div>

        {{-- Total Events --}}
        <div wire:click="openTotalEventsModal"
             data-ctip="View All Events"
             class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3
                    hover:shadow-md hover:border-emerald-500/40">

            <div class="org-view-overlay bg-gradient-to-t from-[#7A3F91] to-[#9b59b6]">
                <i class="fas fa-eye text-white text-[10px]"></i>
                <span class="text-white text-[11px] font-bold tracking-wide">View Details</span>
            </div>

            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-purple-100 flex items-center justify-center shadow">
                    <i class="fas fa-calendar-days text-sm text-[#7A3F91]"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">Events</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalEvents) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Total Events</p>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 pb-7">
                <span wire:click.stop="openApprovedEventsModal"
                      class="text-xs font-semibold text-emerald-600 flex items-center gap-1 cursor-pointer hover:underline">
                    <i class="fas fa-circle-check text-xs"></i> {{ $this->approvedEvents }} Approved
                </span>
            </div>
        </div>

        {{-- Pending Events --}}
        <div wire:click="openPendingEventsModal"
             data-ctip="View Pending Events"
             class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3
                    hover:shadow-md hover:border-amber-500/40">

            <div class="org-view-overlay bg-gradient-to-t from-amber-500 to-amber-400">
                <i class="fas fa-eye text-white text-[10px]"></i>
                <span class="text-white text-[11px] font-bold tracking-wide">View Details</span>
            </div>

            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center shadow">
                    <i class="fas fa-hourglass-end text-amber-500 text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase bg-amber-50 text-amber-700 border border-amber-200">Pending</span>
            </div>
            <p class="text-2xl font-semibold text-amber-600 leading-none">{{ number_format($this->pendingEvents) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Pending Review</p>
            <div class="pb-7">
                @if($this->rejectedEvents > 0)
                    <p wire:click.stop="openRejectedEventsModal"
                       class="text-xs text-red-500 font-semibold mt-1.5 flex items-center gap-1 cursor-pointer hover:underline">
                        <i class="fas fa-circle-xmark text-xs"></i> {{ $this->rejectedEvents }} Rejected
                    </p>
                @else
                    <p class="text-xs text-[#999999] mt-1.5 font-normal">Awaiting admin approval</p>
                @endif
            </div>
        </div>

        {{-- Job Postings --}}
        <div wire:click="openJobsModal"
             data-ctip="View All Job Postings"
             class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3
                    hover:shadow-md hover:border-blue-500/40">

            <div class="org-view-overlay bg-gradient-to-t from-blue-600 to-blue-500">
                <i class="fas fa-eye text-white text-[10px]"></i>
                <span class="text-white text-[11px] font-bold tracking-wide">View Details</span>
            </div>

            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center shadow">
                    <i class="fas fa-briefcase text-blue-500 text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase bg-blue-50 text-blue-700 border border-blue-200">Jobs</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalJobs) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Job Postings</p>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 pb-7">
                <span wire:click.stop="openActiveJobsModal"
                      class="text-xs font-semibold text-emerald-600 flex items-center gap-1 cursor-pointer hover:underline">
                    <i class="fas fa-circle text-[8px]"></i> {{ $this->activeJobs }} Active
                </span>
                <span class="text-xs font-semibold text-[#999999] flex items-center gap-1">
                    <i class="fas fa-circle text-[8px]"></i> {{ $this->inactiveJobs }} Inactive
                </span>
            </div>
        </div>

    </div>{{-- end stat cards --}}

    {{-- ── CONTENT GRID ─────────────────────────────────────────── --}}
    @php
        $ec  = $this->empCounts;
        $crb = $this->empCourseRelevanceBreakdown;
        $empRows = [
            ['label'=>'Employed',      'count'=>$ec['employed'],   'icon'=>'fa-user-tie',        'cardCls'=>'bg-[#F9F7FC] border-[#E8E0F0]',  'iconCls'=>'bg-[#7A3F91]/10 text-[#7A3F91]', 'cntCls'=>'text-[#7A3F91]',  'overlayCls'=>'from-[#7A3F91] to-[#9b59b6]', 'filter'=>'employed',      'ctip'=>'View Employed Alumni'],
            ['label'=>'Self-Employed', 'count'=>$ec['self'],       'icon'=>'fa-store',            'cardCls'=>'bg-blue-50 border-blue-200',      'iconCls'=>'bg-blue-100 text-blue-600',       'cntCls'=>'text-blue-600',   'overlayCls'=>'from-blue-600 to-blue-500',    'filter'=>'self_employed', 'ctip'=>'View Self-Employed Alumni'],
            ['label'=>'Unemployed',    'count'=>$ec['unemployed'], 'icon'=>'fa-magnifying-glass', 'cardCls'=>'bg-amber-50 border-amber-200',    'iconCls'=>'bg-amber-100 text-amber-600',     'cntCls'=>'text-amber-600',  'overlayCls'=>'from-amber-500 to-amber-400',  'filter'=>'unemployed',    'ctip'=>'View Unemployed Alumni'],
            ['label'=>'No Record',     'count'=>$ec['noRecord'],   'icon'=>'fa-circle-minus',     'cardCls'=>'bg-gray-50 border-gray-200',      'iconCls'=>'bg-gray-200 text-gray-500',       'cntCls'=>'text-gray-500',   'overlayCls'=>'from-gray-500 to-gray-400',    'filter'=>'no_record',     'ctip'=>'View Alumni With No Record'],
        ];
    @endphp

    <div class="flex-1 min-h-0 grid gap-2.5"
         style="grid-template-columns:1fr 1fr 280px; grid-template-rows:1fr 1fr;">

        {{-- Employment Overview: col 1-2, row 1 --}}
        <div class="col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col min-h-0">
            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center justify-between bg-gradient-to-br from-[#F9F7FC] to-white">
                <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]">
                        <i class="fas fa-briefcase text-white text-[10px]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Employment Overview</p>
                    <span class="text-[10px] text-[#c0a0d8] font-normal hidden sm:inline">— click a card to view</span>
                </div>
                <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="flex-1 overflow-y-auto org-scroll p-3 min-h-0">

                {{-- Employment status mini-cards --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                    @foreach($empRows as $row)
                    <div wire:click="openEmploymentModal('{{ $row['filter'] }}')"
                         data-ctip="{{ $row['ctip'] }}"
                         class="org-emp-card rounded-xl border p-2.5 {{ $row['cardCls'] }}">

                        <div class="org-emp-overlay bg-gradient-to-t {{ $row['overlayCls'] }}">
                            <i class="fas fa-eye text-white text-[9px]"></i>
                            <span class="text-white text-[10px] font-bold tracking-wide">View Details</span>
                        </div>

                        <div class="flex items-center gap-1.5 mb-1.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0 {{ $row['iconCls'] }}">
                                <i class="fas {{ $row['icon'] }} text-xs"></i>
                            </div>
                            <span class="text-xs font-semibold text-[#555555]">{{ $row['label'] }}</span>
                        </div>
                        <p class="text-2xl font-semibold leading-none pb-6 {{ $row['cntCls'] }}">
                            {{ number_format($row['count']) }}
                        </p>
                    </div>
                    @endforeach
                </div>

                @if($crb['totalWorking'] > 0)
                <div class="border-t border-[#E8E0F0] pt-3">
                    <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-2 flex items-center gap-1.5">
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
                                <p class="text-xs font-semibold text-emerald-600 mt-1 flex items-center justify-center gap-0.5">
                                    <i class="fas fa-circle-check text-xs"></i> Related
                                </p>
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-2.5 text-center">
                                <p class="text-xl font-semibold text-amber-700 leading-none">{{ number_format($crb['partial']) }}</p>
                                <p class="text-xs font-semibold text-amber-600 mt-1 flex items-center justify-center gap-0.5">
                                    <i class="fas fa-circle-half-stroke text-xs"></i> Partial
                                </p>
                            </div>
                            <div class="rounded-xl border border-red-200 bg-red-50 p-2.5 text-center">
                                <p class="text-xl font-semibold text-red-700 leading-none">{{ number_format($crb['notRelated']) }}</p>
                                <p class="text-xs font-semibold text-red-600 mt-1 flex items-center justify-center gap-0.5">
                                    <i class="fas fa-circle-xmark text-xs"></i> Not Related
                                </p>
                            </div>
                        </div>
                        <div class="rounded-full h-1.5 overflow-hidden bg-[#7A3F91]/10">
                            <div class="h-full flex">
                                @if($crb['related'] > 0)
                                <div class="h-full bg-emerald-500" style="width:{{ ($crb['related']/$specifiedTotal)*100 }}%"></div>
                                @endif
                                @if($crb['partial'] > 0)
                                <div class="h-full bg-amber-400" style="width:{{ ($crb['partial']/$specifiedTotal)*100 }}%"></div>
                                @endif
                                @if($crb['notRelated'] > 0)
                                <div class="h-full bg-red-500" style="width:{{ ($crb['notRelated']/$specifiedTotal)*100 }}%"></div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5">
                            <i class="fas fa-circle-info text-gray-400 text-sm shrink-0"></i>
                            <p class="text-xs text-[#666666] font-normal">
                                Course relevance not yet specified for
                                <strong class="font-semibold text-[#333333]">{{ $crb['totalWorking'] }}</strong>
                                employed / self-employed alumni.
                            </p>
                        </div>
                    @endif
                    <p class="text-xs text-[#999999] mt-1.5 font-normal">
                        Out of <strong>{{ number_format($crb['totalWorking']) }}</strong> employed / self-employed alumni
                        @if(($crb['notSpecified'] ?? 0) > 0 && $showRelevance)
                            · <span class="text-amber-600 font-semibold">{{ $crb['notSpecified'] }} not yet specified</span>
                        @endif
                    </p>
                </div>
                @endif
            </div>
        </div>

        {{-- Account Info: col 3, rows 1-2 --}}
        <div class="col-start-3 row-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col min-h-0">
            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center gap-2 bg-gradient-to-br from-[#F9F7FC] to-white">
                <div class="w-5 h-5 rounded-lg flex items-center justify-center bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]">
                    <i class="fas fa-user-circle text-white text-[10px]"></i>
                </div>
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Account Info</p>
            </div>
            <div class="flex-1 overflow-y-auto org-scroll divide-y divide-[#F5F5F5] px-4 min-h-0">
                {{-- Name --}}
                <div class="flex items-center justify-between py-2.5">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Name</span>
                    <div class="group/tip relative ml-2">
                        <span class="text-xs font-semibold text-[#333333] text-right truncate max-w-[145px] block cursor-default">{{ $this->organizerName }}</span>
                        <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100
                                    absolute bottom-[125%] left-1/2 -translate-x-1/2
                                    bg-[#1a1a1a] text-white text-center rounded-xl px-3 py-2 z-[9990] whitespace-nowrap
                                    text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                            {{ $this->organizerName }}
                        </div>
                    </div>
                </div>
                {{-- Teacher ID --}}
                <div class="flex items-center justify-between py-2.5">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Teacher ID</span>
                    <div class="group/tip relative ml-2">
                        <span class="text-xs font-semibold font-mono text-[#7A3F91] cursor-default">{{ $this->organizerTeacherId }}</span>
                        <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100
                                    absolute bottom-[125%] left-1/2 -translate-x-1/2
                                    bg-[#1a1a1a] text-white text-center rounded-xl px-3 py-2 z-[9990] whitespace-nowrap
                                    text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                            {{ $this->organizerTeacherId }}
                        </div>
                    </div>
                </div>
                {{-- Email --}}
                <div class="flex items-start justify-between py-2.5">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0 mt-0.5">Email</span>
                    <div class="group/tip relative ml-2">
                        <span class="text-xs text-[#666666] font-normal text-right break-all max-w-[145px] block cursor-default">{{ $this->organizerEmail }}</span>
                        <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100
                                    absolute bottom-[125%] left-1/2 -translate-x-1/2
                                    bg-[#1a1a1a] text-white text-center rounded-xl px-3 py-2 z-[9990] whitespace-nowrap
                                    text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                            {{ $this->organizerEmail }}
                        </div>
                    </div>
                </div>
                {{-- College --}}
                <div class="flex items-center justify-between py-2.5">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">College</span>
                    <div class="group/tip relative ml-2">
                        <span class="text-xs font-semibold text-[#333333] text-right truncate max-w-[145px] block cursor-default">{{ $this->organizerDepartment }}</span>
                        <div class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100
                                    absolute bottom-[125%] left-1/2 -translate-x-1/2
                                    bg-[#1a1a1a] text-white text-center rounded-xl px-3 py-2 z-[9990] whitespace-nowrap
                                    text-xs font-semibold transition-all duration-200 shadow-2xl pointer-events-none">
                            {{ $this->organizerDepartment }}
                        </div>
                    </div>
                </div>
                @if($this->organizerBatch)
                <div class="flex items-center justify-between py-2.5">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Batch</span>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">{{ $this->organizerBatch }}</span>
                </div>
                @endif
                {{-- Quick Stats --}}
                <div class="py-3">
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide mb-2">Quick Stats</p>
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between rounded-lg px-2.5 py-1.5 bg-[#F9F7FC] border border-[#E8E0F0]">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-calendar-check text-xs text-[#7A3F91]"></i> Approved
                            </span>
                            <span class="text-xs font-semibold text-emerald-600">{{ $this->approvedEvents }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg px-2.5 py-1.5 bg-amber-50 border border-amber-200">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-hourglass-end text-xs text-amber-500"></i> Pending
                            </span>
                            <span class="text-xs font-semibold text-amber-600">{{ $this->pendingEvents }}</span>
                        </div>
                        @if($this->rejectedEvents > 0)
                        <div class="flex items-center justify-between rounded-lg px-2.5 py-1.5 bg-red-50 border border-red-200">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-circle-xmark text-xs text-red-400"></i> Rejected
                            </span>
                            <span class="text-xs font-semibold text-red-600">{{ $this->rejectedEvents }}</span>
                        </div>
                        @endif
                        <div class="flex items-center justify-between rounded-lg px-2.5 py-1.5 bg-blue-50 border border-blue-200">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-briefcase text-xs text-blue-400"></i> Active Jobs
                            </span>
                            <span class="text-xs font-semibold text-blue-600">{{ $this->activeJobs }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Events: col 1, row 2 --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col min-h-0">
            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center bg-gradient-to-br from-[#F9F7FC] to-white">
                <button type="button" wire:click="openTotalEventsModal"
                        class="flex items-center gap-2 hover:opacity-80 transition-opacity cursor-pointer">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]">
                        <i class="fas fa-calendar-days text-white text-[10px]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Recent Events</p>
                    <i class="fas fa-up-right-from-square text-[#c0a0d8] text-[9px]"></i>
                </button>
                <span class="ml-auto text-[10px] text-[#c0a0d8] hidden sm:inline font-normal">click row to filter</span>
            </div>
            <div class="flex-1 overflow-y-auto org-scroll divide-y divide-[#F5F5F5] min-h-0">
                @forelse($this->recentEvents as $index => $event)
                @php
                    $sc = match($event->status) {
                        'PENDING'   => ['text-amber-700 bg-amber-50 border-amber-200',       'fa-hourglass-end',  'bg-amber-100 text-amber-600'],
                        'APPROVED'  => ['text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check',   'bg-emerald-100 text-emerald-600'],
                        'REJECTED'  => ['text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark',   'bg-red-100 text-red-500'],
                        'COMPLETED' => ['text-blue-700 bg-blue-50 border-blue-200',          'fa-check-double',   'bg-blue-100 text-blue-600'],
                        default     => ['text-[#666666] bg-[#F9F7FC] border-[#E8E0F0]',      'fa-circle',         'bg-purple-100 text-purple-500'],
                    };
                @endphp
                <div class="org-list-row px-3 py-2.5 flex items-center gap-2 cursor-pointer"
                     wire:click="openEventModalByStatus('{{ $event->status }}')">
                    <span class="w-4 text-center text-xs font-semibold shrink-0 text-[#c0a0d8]">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $sc[2] }}">
                        <i class="fas {{ $sc[1] }} text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333] truncate">{{ $event->title }}</p>
                        <p class="text-xs text-[#999999] font-normal">
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                        </p>
                    </div>
                    <span class="view-badge"><i class="fas fa-eye" style="font-size:8px;"></i> View</span>
                    <div class="shrink-0 text-right">
                        <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border {{ $sc[0] }}">
                            {{ $event->status }}
                        </span>
                        <p class="text-xs text-[#BBBBBB] font-normal mt-0.5">{{ $event->created_at->diffForHumans() }}</p>
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2 bg-[#f0e6f8]">
                        <i class="fas fa-calendar-days text-base text-[#c89de0]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#999999]">No events posted yet</p>
                    <a href="{{ route('organizer.event/organizer') }}" wire:navigate
                       class="text-xs font-semibold hover:underline mt-1 text-[#7A3F91]">
                        Create your first event →
                    </a>
                </div>
                @endforelse
            </div>
        </div>

        {{-- Recent Jobs: col 2, row 2 --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col min-h-0">
            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center bg-gradient-to-br from-[#F9F7FC] to-white">
                <button type="button" wire:click="openJobsModal"
                        class="flex items-center gap-2 hover:opacity-80 transition-opacity cursor-pointer">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center bg-blue-500">
                        <i class="fas fa-briefcase text-white text-[10px]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Recent Job Posts</p>
                    <i class="fas fa-up-right-from-square text-blue-300 text-[9px]"></i>
                </button>
                <span class="ml-auto text-[10px] text-[#c0a0d8] hidden sm:inline font-normal">click row to filter</span>
            </div>
            <div class="flex-1 overflow-y-auto org-scroll divide-y divide-[#F5F5F5] min-h-0">
                @forelse($this->recentJobs as $index => $job)
                @php
                    $dl       = Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $isActive = $job->status === 'ACTIVE';
                @endphp
                <div class="org-list-row px-3 py-2.5 flex items-center gap-2 cursor-pointer"
                     wire:click="openJobModalByStatus('{{ $job->status }}')">
                    <span class="w-4 text-center text-xs font-semibold shrink-0 text-[#c0a0d8]">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0
                                {{ $isActive ? 'bg-blue-50 text-blue-600' : 'bg-gray-50 text-gray-500' }}">
                        <i class="fas fa-briefcase text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333] truncate">{{ $job->job_title }}</p>
                        <p class="text-xs text-[#999999] font-normal truncate flex items-center gap-1">
                            <span class="truncate max-w-[90px]">{{ $job->company_name }}</span>
                            <span class="text-[#E8E0F0]">·</span>
                            <span class="font-semibold text-blue-500">{{ $job->employment_type }}</span>
                        </p>
                    </div>
                    <span class="view-badge"><i class="fas fa-eye" style="font-size:8px;"></i> View</span>
                    <div class="shrink-0 text-right">
                        @if($isActive)
                            <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">Active</span>
                        @else
                            <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border text-amber-700 bg-amber-50 border-amber-200">Inactive</span>
                        @endif
                        <p class="text-xs text-[#BBBBBB] font-normal mt-0.5">{{ $dl->format('M d, Y') }}</p>
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2 bg-blue-50">
                        <i class="fas fa-briefcase text-base text-blue-300"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#999999]">No job postings yet</p>
                    <a href="{{ route('organizer.job/management') }}" wire:navigate
                       class="text-xs font-semibold hover:underline mt-1 text-[#7A3F91]">
                        Create your first posting →
                    </a>
                </div>
                @endforelse
            </div>
        </div>

    </div>{{-- end content grid --}}
</div>{{-- end 90vh wrapper --}}


{{-- ═══════════════════════════════════════════════════════════════
     MODAL: TOTAL ALUMNI
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'alumni')
@php
    $statusLabels = [
        'employed'      => ['Employed',     'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]'],
        'self_employed' => ['Self-Employed', 'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',    'text-amber-700 bg-amber-50 border-amber-200'],
    ];
    $filteredAlumni = collect($modalAlumni)
        ->when($alumniSearch !== '', fn($c) => $c->filter(fn($a) =>
            str_contains(strtolower($a['name']),       strtolower($alumniSearch)) ||
            str_contains(strtolower($a['student_id']), strtolower($alumniSearch)) ||
            str_contains(strtolower($a['course']),     strtolower($alumniSearch))
        ))
        ->when($alumniFilterCourse !== '', fn($c) => $c->filter(fn($a) =>
            strtolower($a['course']) === strtolower($alumniFilterCourse)
        ))
        ->when($alumniFilterBatch !== '', fn($c) => $c->filter(fn($a) =>
            (string)$a['batch'] === (string)$alumniFilterBatch
        ))
        ->values();
    $alumniTotal    = $filteredAlumni->count();
    $alumniLastPage = max((int) ceil($alumniTotal / $alumniModalSize), 1);
    $alumniSafePage = min($alumniModalPage, $alumniLastPage);
    $alumniFrom     = $alumniTotal > 0 ? ($alumniSafePage - 1) * $alumniModalSize + 1 : 0;
    $alumniTo       = min($alumniSafePage * $alumniModalSize, $alumniTotal);
    $displayAlumni  = $filteredAlumni->slice(($alumniSafePage - 1) * $alumniModalSize, $alumniModalSize)->values()->toArray();
    $hasFilter      = $alumniSearch || $alumniFilterCourse || $alumniFilterBatch;
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 org-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-graduation-cap text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Total Alumni</h2>
                <p class="text-white/60 text-xs font-normal">
                    {{ $alumniFrom }}–{{ $alumniTo }} of {{ $alumniTotal }} alumni
                    @if($hasFilter)<span class="text-white/40 ml-1">(filtered)</span>@endif
                </p>
            </div>
        </div>
        <button wire:click="closeModal" class="org-close-btn">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.alumniSearch??''; $wire.$watch('alumniSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('alumniSearch', q)"
                       placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            @if(!empty($this->modalAlumniCourses))
            <div class="relative">
                <select wire:model.live="alumniFilterCourse"
                        class="appearance-none pl-3 pr-8 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition cursor-pointer">
                    <option value="">All Courses</option>
                    @foreach($this->modalAlumniCourses as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
                <i class="fas fa-chevron-down text-gray-400 text-xs absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
            </div>
            @endif
            @if(!empty($this->modalAlumniBatches))
            <div class="relative">
                <select wire:model.live="alumniFilterBatch"
                        class="appearance-none pl-3 pr-8 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition cursor-pointer">
                    <option value="">All Batches</option>
                    @foreach($this->modalAlumniBatches as $b)
                        <option value="{{ $b }}">Batch {{ $b }}</option>
                    @endforeach
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
            <span class="text-xs text-gray-400 font-normal hidden sm:inline ml-auto">
                Showing <strong class="text-gray-600">{{ $alumniFrom }}–{{ $alumniTo }}</strong> of <strong class="text-gray-600">{{ $alumniTotal }}</strong>
            </span>
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
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold text-[#c0a0d8]">{{ str_pad($alumniFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3"><p class="text-sm font-semibold text-gray-900">{{ $a['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3"><p class="text-sm font-mono text-gray-600">{{ $a['student_id'] }}</p></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">{{ $a['course'] }}</span>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell"><p class="text-sm text-gray-500">{{ $a['batch'] }}</p></td>
                    <td class="px-4 py-3 text-center">
                        @if($a['status'] && isset($statusLabels[$a['status']]))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $statusLabels[$a['status']][1] }}">
                                {{ $statusLabels[$a['status']][0] }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">No Record</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-graduation-cap text-2xl text-[#c89de0]"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No alumni found</p>
                        <p class="text-xs text-gray-300">Try adjusting your search or filters</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-[#7A3F91]">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $alumniFrom }}–{{ $alumniTo }}</strong>
            of <strong class="font-bold text-base">{{ $alumniTotal }}</strong> alumni
            @if($hasFilter)<span class="text-white/60 text-xs ml-1">(filtered)</span>@endif
        </p>
        @if($alumniLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="alumniPrevPage" {{ $alumniSafePage <= 1 ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $alumniSafePage - 2); $p <= min($alumniLastPage, $alumniSafePage + 2); $p++)
                @if($p === $alumniSafePage)
                    <span class="org-pg-btn org-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('alumniModalPage', {{ $p }})" class="org-pg-btn org-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="alumniNextPage({{ $alumniLastPage }})" {{ $alumniSafePage >= $alumniLastPage ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $alumniSafePage }}/{{ $alumniLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════════
     MODAL: EMPLOYMENT
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'employment')
@php
    $empFilterLabels = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed','no_record'=>'No Record',''=>'All Alumni'];
    $currentFilterLabel = $empFilterLabels[$empModalFilter] ?? 'All Alumni';
    $statusLabels2 = [
        'employed'      => ['Employed',     'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]'],
        'self_employed' => ['Self-Employed', 'text-blue-700 bg-blue-50 border-blue-200'],
        'unemployed'    => ['Unemployed',    'text-amber-700 bg-amber-50 border-amber-200'],
    ];
    $empFiltered = collect($modalAlumni)
        ->when($alumniSearch !== '', fn($c) => $c->filter(fn($a) =>
            str_contains(strtolower($a['name']),       strtolower($alumniSearch)) ||
            str_contains(strtolower($a['student_id']), strtolower($alumniSearch)) ||
            str_contains(strtolower($a['course']),     strtolower($alumniSearch))
        ))
        ->when($empModalFilter !== '', fn($c) => $c->filter(fn($a) =>
            $empModalFilter === 'no_record' ? ($a['status'] === null) : ($a['status'] === $empModalFilter)
        ))
        ->values();
    $empTotal    = $empFiltered->count();
    $empLastPage = max((int) ceil($empTotal / $empModalSize), 1);
    $empSafePage = min($empModalPage, $empLastPage);
    $empFrom     = $empTotal > 0 ? ($empSafePage - 1) * $empModalSize + 1 : 0;
    $empTo       = min($empSafePage * $empModalSize, $empTotal);
    $displayEmp  = $empFiltered->slice(($empSafePage - 1) * $empModalSize, $empModalSize)->values()->toArray();
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 org-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Employment — {{ $currentFilterLabel }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $empFrom }}–{{ $empTo }} of {{ $empTotal }} alumni</p>
            </div>
        </div>
        <button wire:click="closeModal" class="org-close-btn">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.alumniSearch??''; $wire.$watch('alumniSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('alumniSearch', q)"
                       placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $empFrom }}–{{ $empTo }}</strong> of <strong class="text-gray-600">{{ $empTotal }}</strong>
            </span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto org-scroll min-h-0">
        <table class="w-full border-collapse min-w-[600px]">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Student ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Batch</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Company / Job</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    @if(in_array($empModalFilter, ['employed','self_employed']))
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider hidden lg:table-cell">Relevance</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEmp as $idx => $a)
                <tr class="org-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold text-[#c0a0d8]">{{ str_pad($empFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3"><p class="text-sm font-semibold text-gray-900">{{ $a['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3"><p class="text-sm font-mono text-gray-600">{{ $a['student_id'] }}</p></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">{{ $a['course'] }}</span>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell"><p class="text-sm text-gray-500">{{ $a['batch'] }}</p></td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($a['company_name'] || $a['job_title'])
                            <p class="text-xs font-semibold text-gray-800 truncate max-w-[140px]">{{ $a['company_name'] ?: '—' }}</p>
                            <p class="text-xs text-gray-400 truncate max-w-[140px]">{{ $a['job_title'] ?: '' }}</p>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($a['status'] && isset($statusLabels2[$a['status']]))
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $statusLabels2[$a['status']][1] }}">
                                {{ $statusLabels2[$a['status']][0] }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">No Record</span>
                        @endif
                    </td>
                    @if(in_array($empModalFilter, ['employed','self_employed']))
                    <td class="px-4 py-3 text-center hidden lg:table-cell">
                        @php $relMap=['yes'=>['Related','text-emerald-700 bg-emerald-50 border-emerald-200'],'no'=>['Not Related','text-red-600 bg-red-50 border-red-200'],'partially'=>['Partial','text-amber-700 bg-amber-50 border-amber-200']]; @endphp
                        @if($a['course_relevance'] && isset($relMap[$a['course_relevance']]))
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $relMap[$a['course_relevance']][1] }}">
                                {{ $relMap[$a['course_relevance']][0] }}
                            </span>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-briefcase text-2xl text-[#c89de0]"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No alumni found for this filter</p>
                        <p class="text-xs text-gray-300">Try selecting a different status filter</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-[#7A3F91]">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $empFrom }}–{{ $empTo }}</strong>
            of <strong class="font-bold text-base">{{ $empTotal }}</strong> alumni
        </p>
        @if($empLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="empPrevPage" {{ $empSafePage <= 1 ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $empSafePage - 2); $p <= min($empLastPage, $empSafePage + 2); $p++)
                @if($p === $empSafePage)
                    <span class="org-pg-btn org-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('empModalPage', {{ $p }})" class="org-pg-btn org-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="empNextPage({{ $empLastPage }})" {{ $empSafePage >= $empLastPage ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $empSafePage }}/{{ $empLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════════
     MODAL: EVENTS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'events')
@php
    $filteredEvents = collect($modalEvents)
        ->when($eventSearch !== '', fn($c) => $c->filter(fn($e) =>
            str_contains(strtolower($e['title']), strtolower($eventSearch)) ||
            str_contains(strtolower($e['venue'] ?? ''), strtolower($eventSearch))
        ))
        ->values();
    $evtTotal      = $filteredEvents->count();
    $evtLastPage   = max((int) ceil($evtTotal / $eventModalSize), 1);
    $evtSafePage   = min($eventModalPage, $evtLastPage);
    $evtFrom       = $evtTotal > 0 ? ($evtSafePage - 1) * $eventModalSize + 1 : 0;
    $evtTo         = min($evtSafePage * $eventModalSize, $evtTotal);
    $displayEvents = $filteredEvents->slice(($evtSafePage - 1) * $eventModalSize, $eventModalSize)->values()->toArray();
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 org-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $evtFrom }}–{{ $evtTo }} of {{ $evtTotal }} event(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="org-close-btn">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('eventSearch', q)"
                       placeholder="Search event title or venue…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $evtFrom }}–{{ $evtTo }}</strong> of <strong class="text-gray-600">{{ $evtTotal }}</strong>
            </span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto org-scroll min-h-0">
        <table class="w-full border-collapse min-w-[540px]">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-16">Photo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Event Title</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date &amp; Time</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Venue</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEvents as $idx => $evt)
                @php
                    $evtSc = match($evt['status']) {
                        'PENDING'   => ['text-amber-700 bg-amber-50 border-amber-200',       'fa-hourglass-end'],
                        'APPROVED'  => ['text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check'],
                        'REJECTED'  => ['text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark'],
                        'COMPLETED' => ['text-blue-700 bg-blue-50 border-blue-200',          'fa-check-double'],
                        default     => ['text-gray-600 bg-gray-50 border-gray-200',          'fa-circle'],
                    };
                @endphp
                <tr class="org-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold text-[#c0a0d8]">{{ str_pad($evtFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <div class="w-10 h-10 rounded-xl overflow-hidden shrink-0 bg-[#f0e6f8]">
                            @if($evt['photo'])
                                <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-calendar-days text-sm text-[#7A3F91]"></i>
                                </div>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900">{{ $evt['title'] }}</p></td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['date'] }}</p>
                        <p class="text-xs text-gray-400 font-normal">{{ $evt['time'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-sm text-gray-500">{{ $evt['venue'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $evtSc[0] }}">
                            <i class="fas {{ $evtSc[1] }} text-xs"></i> {{ $evt['status'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-calendar-days text-2xl text-[#c89de0]"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No events found</p>
                        <p class="text-xs text-gray-300">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-[#7A3F91]">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $evtFrom }}–{{ $evtTo }}</strong>
            of <strong class="font-bold text-base">{{ $evtTotal }}</strong> event(s)
        </p>
        @if($evtLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="eventPrevPage" {{ $evtSafePage <= 1 ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $evtSafePage - 2); $p <= min($evtLastPage, $evtSafePage + 2); $p++)
                @if($p === $evtSafePage)
                    <span class="org-pg-btn org-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('eventModalPage', {{ $p }})" class="org-pg-btn org-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="eventNextPage({{ $evtLastPage }})" {{ $evtSafePage >= $evtLastPage ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $evtSafePage }}/{{ $evtLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════════
     MODAL: JOB POSTINGS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'jobs')
@php
    $filteredJobs = collect($modalJobs)
        ->when($jobSearch !== '', fn($c) => $c->filter(fn($j) =>
            str_contains(strtolower($j['title']),          strtolower($jobSearch)) ||
            str_contains(strtolower($j['company']),        strtolower($jobSearch)) ||
            str_contains(strtolower($j['location'] ?? ''), strtolower($jobSearch))
        ))
        ->values();
    $jobTotalCount = $filteredJobs->count();
    $jobLastPage   = max((int) ceil($jobTotalCount / $jobModalPageSize), 1);
    $jobSafePage   = min($jobModalPage, $jobLastPage);
    $jobFrom       = $jobTotalCount > 0 ? ($jobSafePage - 1) * $jobModalPageSize + 1 : 0;
    $jobTo         = min($jobSafePage * $jobModalPageSize, $jobTotalCount);
    $displayJobs   = $filteredJobs->slice(($jobSafePage - 1) * $jobModalPageSize, $jobModalPageSize)->values()->toArray();
    $jobStatuses   = collect($modalJobs)->pluck('status')->unique()->toArray();
    $jobModalTitleText = match(true) {
        count($jobStatuses) === 1 && $jobStatuses[0] === 'ACTIVE'   => 'Active Job Postings',
        count($jobStatuses) === 1 && $jobStatuses[0] === 'INACTIVE' => 'Inactive Job Postings',
        default => 'My Job Postings',
    };
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 org-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $jobModalTitleText }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $jobFrom }}–{{ $jobTo }} of {{ $jobTotalCount }} job(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="org-close-btn">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('jobSearch', q)"
                       placeholder="Search title, company, location…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $jobFrom }}–{{ $jobTo }}</strong> of <strong class="text-gray-600">{{ $jobTotalCount }}</strong>
            </span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto org-scroll min-h-0">
        <table class="w-full border-collapse min-w-[620px]">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Location</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Salary</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayJobs as $idx => $job)
                @php $isUrgent=($job['days_left']??99)<=7; $isActive=$job['status']==='ACTIVE'; @endphp
                <tr class="org-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold text-[#c0a0d8]">{{ str_pad($jobFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900 truncate max-w-[180px]">{{ $job['title'] }}</p></td>
                    <td class="px-4 py-3.5"><p class="text-sm text-gray-600 truncate max-w-[140px]">{{ $job['company'] }}</p></td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">{{ $job['type'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden md:table-cell"><p class="text-sm text-gray-500">{{ $job['location'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell"><p class="text-sm font-semibold text-[#7A3F91]">{{ $job['salary'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        @if($isActive)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200"><i class="fas fa-circle text-[8px]"></i> Active</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-amber-700 bg-amber-50 border-amber-200"><i class="fas fa-circle text-[8px]"></i> Inactive</span>
                        @endif
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <p class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-gray-500' }}">
                            <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs mr-0.5"></i>{{ $job['deadline'] }}
                        </p>
                        @if($isUrgent)<p class="text-xs text-red-400 font-normal mt-0.5">{{ $job['days_left'] }}d left</p>@endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-briefcase text-2xl text-[#c89de0]"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No job postings found</p>
                        <p class="text-xs text-gray-300">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-[#7A3F91]">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $jobFrom }}–{{ $jobTo }}</strong>
            of <strong class="font-bold text-base">{{ $jobTotalCount }}</strong> posting(s)
        </p>
        @if($jobLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="jobPrevPage" {{ $jobSafePage <= 1 ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $jobSafePage - 2); $p <= min($jobLastPage, $jobSafePage + 2); $p++)
                @if($p === $jobSafePage)
                    <span class="org-pg-btn org-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('jobModalPage', {{ $p }})" class="org-pg-btn org-pg-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="jobNextPage({{ $jobLastPage }})" {{ $jobSafePage >= $jobLastPage ? 'disabled' : '' }}
                    class="org-pg-btn org-pg-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $jobSafePage }}/{{ $jobLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif


{{-- ══ Cursor-following tooltip script ══ --}}
<script>
(function () {
    'use strict';

    if (window._orgCursorTipBound) return;
    window._orgCursorTipBound = true;

    function getTip() {
        return document.getElementById('org-float-tip');
    }

    document.addEventListener('mousemove', function (e) {
        var tip = getTip();
        if (tip && tip._ctipVisible) {
            tip.style.left = e.clientX + 'px';
            tip.style.top  = e.clientY + 'px';
        }
    });

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest('[data-ctip]');
        if (!el) return;
        var tip = getTip();
        if (!tip) return;
        tip.textContent   = el.getAttribute('data-ctip');
        tip._ctipVisible  = true;
        tip.style.opacity = '1';
    });

    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest('[data-ctip]');
        if (!el) return;
        var related = e.relatedTarget;
        if (related && el.contains(related)) return;
        var tip = getTip();
        if (!tip) return;
        tip._ctipVisible  = false;
        tip.style.opacity = '0';
    });

    document.addEventListener('livewire:navigating', function () {
        var tip = getTip();
        if (tip) { tip._ctipVisible = false; tip.style.opacity = '0'; }
    });
})();
</script>

</div>{{-- end single Livewire root --}}