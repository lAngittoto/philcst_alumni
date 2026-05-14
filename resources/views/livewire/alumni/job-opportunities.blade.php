{{-- resources/views/livewire/alumni/job-opportunities.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterType   = '';
    public string $filterLevel  = '';
    public string $filterSort   = 'recent';

    public bool  $showViewModal = false;
    public ?int  $viewingJobId  = null;

    public string $alumniCollege   = '';
    public string $alumniCourse    = '';
    public string $alumniFirstName = '';
    public int    $alumniId        = 0;
    public int    $alumniRoomId    = 0;

    // ── Share modal ───────────────────────────────────────────────────────────
    public bool   $showShareModal   = false;
    public ?int   $shareJobId       = null;
    public string $shareJobTitle    = '';
    public string $shareCompany     = '';
    public string $shareEmpType     = '';
    public string $shareLocation    = '';
    public string $shareExpLevel    = '';
    public string $shareSalary      = '';
    public string $shareDeadline    = '';
    public string $shareDescription = '';
    public string $shareCollege     = '';

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)
            ->select(['id', 'first_name', 'course_code', 'course_name', 'batch'])
            ->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId        = $alumni->id;
        $this->alumniFirstName = $alumni->first_name ?? '';
        $this->alumniCourse    = $alumni->course_name ?? $alumni->course_code ?? '';

        $this->alumniCollege = Cache::remember(
            'alumni_college_' . $alumni->course_code,
            600,
            fn() => Course::where('code', $alumni->course_code)->value('college') ?? ''
        );

        $room = DB::table('chat_rooms')
            ->where('course_code', $alumni->course_code)
            ->where('batch', $alumni->batch)
            ->first();
        $this->alumniRoomId = $room ? (int) $room->id : 0;
    }

    public function updatingSearch()      { $this->resetPage(); }
    public function updatingFilterType()  { $this->resetPage(); }
    public function updatingFilterLevel() { $this->resetPage(); }
    public function updatingFilterSort()  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->search = $this->filterType = $this->filterLevel = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    #[Computed]
    public function jobPostings()
    {
        $college = $this->alumniCollege;

        $q = JobPosting::select([
                'id', 'organizer_id', 'job_title', 'company_name', 'company_type',
                'location', 'employment_type', 'experience_level',
                'target_college', 'salary', 'deadline', 'status',
                'description', 'qualifications', 'application_instructions', 'created_at',
            ])
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($college) {
                $q->whereNull('target_college')
                  ->orWhere('target_college', '')
                  ->orWhere('target_college', 'like', "%{$college}%");
            })
            ->where('deadline', '>=', now('Asia/Manila')->toDateString());

        if ($this->search !== '') {
            $s = strip_tags(trim($this->search));
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('location',     'like', "%{$s}%")
            );
        }

        if ($this->filterType !== '')  $q->where('employment_type',  $this->filterType);
        if ($this->filterLevel !== '') $q->where('experience_level', $this->filterLevel);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');

        return $q->paginate(20);
    }

    public function viewJob(int $id): void
    {
        $this->viewingJobId  = $id;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingJobId  = null;
    }

    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;

        return JobPosting::where('id', $this->viewingJobId)
            ->where('status', 'ACTIVE')
            ->first();
    }

    // ── Share ─────────────────────────────────────────────────────────────────

    public function openShareModal(int $id): void
    {
        $job = JobPosting::findOrFail($id);

        $deadlinePassed = \Carbon\Carbon::parse($job->deadline)
            ->setTimezone('Asia/Manila')->startOfDay()
            ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — the deadline has already passed.');
            return;
        }

        $this->shareJobId       = $id;
        $this->shareJobTitle    = $job->job_title;
        $this->shareCompany     = $job->company_name;
        $this->shareEmpType     = $job->employment_type;
        $this->shareLocation    = $job->location ?? '';
        $this->shareExpLevel    = $job->experience_level ?? '';
        $this->shareSalary      = $job->salary ?? '';
        $this->shareDeadline    = $job->deadline ?? '';
        $this->shareDescription = $job->description ?? '';
        $this->shareCollege     = $job->target_college ?? '';
        $this->showShareModal   = true;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal   = false;
        $this->shareJobId       = null;
        $this->shareJobTitle    = '';
        $this->shareCompany     = '';
        $this->shareEmpType     = '';
        $this->shareLocation    = '';
        $this->shareExpLevel    = '';
        $this->shareSalary      = '';
        $this->shareDeadline    = '';
        $this->shareDescription = '';
        $this->shareCollege     = '';
    }

    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try {
            $path = route('jobs.index', [], false);
        } catch (\Throwable) {
            $path = '/jobs';
        }
        return $base . $path;
    }

    public function shareToChat(): void
    {
        if (! $this->shareJobId || ! $this->alumniRoomId) {
            $this->dispatch('flash-message', type: 'error', message: 'Could not find your batch chat room.');
            return;
        }

        $deadlinePassed = $this->shareDeadline
            && \Carbon\Carbon::parse($this->shareDeadline)
                ->setTimezone('Asia/Manila')->startOfDay()
                ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — the deadline has already passed.');
            return;
        }

        $dl = $this->shareDeadline
            ? \Carbon\Carbon::parse($this->shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
            : null;

        $lines   = [];
        $lines[] = "@everyone";
        $lines[] = "📢 Job Opportunity Shared";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "🎯 {$this->shareJobTitle}";
        $lines[] = "🏢 {$this->shareCompany}";
        if ($this->shareLocation)  $lines[] = "📍 {$this->shareLocation}";
        if ($this->shareEmpType)   $lines[] = "💼 {$this->shareEmpType}";
        if ($this->shareExpLevel)  $lines[] = "📊 {$this->shareExpLevel}";
        if ($this->shareSalary)    $lines[] = "💰 {$this->shareSalary}";
        if ($dl)                   $lines[] = "📅 Deadline: {$dl}";
        if ($this->shareCollege)   $lines[] = "🏫 For: {$this->shareCollege}";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "👀 Check it out on the Alumni Portal → " . $this->jobsBaseUrl();

        $body = implode("\n", $lines);

        DB::table('chat_messages')->insert([
            'room_id'     => $this->alumniRoomId,
            'sender_type' => 'alumni',
            'sender_id'   => $this->alumniId,
            'body'        => $body,
            'reply_to_id' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->closeShareModal();
        $this->dispatch('flash-message', type: 'success', message: 'Job shared to your Batch Chat! Your batchmates will see it shortly.');
    }

}; ?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes slideInFull {
    from { opacity:0; }
    to   { opacity:1; }
}
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Filter inputs ── */
.filter-input {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
}
.filter-input:hover  { border-color: #c4b5d4; }
.filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.filter-input {
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

/* ── Job Cards ── */
.job-card {
    background: #ffffff;
    border: 1px solid #E8E0F0;
    border-radius: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    transition: border-color .18s;
    overflow: hidden;
}
.job-card:hover {
    border-color: #c4b5d4;
}
.job-card:hover .card-view-btn { opacity: 0.90; }

/* ── View modal meta row ── */
.meta-row-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.meta-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #555555;
    margin-bottom: 0.2rem;
}
.meta-value {
    font-size: 0.975rem;
    font-weight: 700;
    color: #333333;
    line-height: 1.3;
}
.meta-sub {
    font-size: 0.875rem;
    color: #555555;
    margin-top: 0.15rem;
}

/* ── Unified content block (filter + cards) ── */
.content-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.content-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.content-block-body {
    flex: 1;
    min-height: 0;
    background: #fafafa;
    padding: 1rem;
}

/* ── Pagination bar ── */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #7A3F91, #9b59b6);
    flex-shrink: 0;
}
.pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: #ffffff;
    background: rgba(255,255,255,.15);
    border: none;
    cursor: pointer;
    transition: background .15s;
}
.pagination-btn:hover:not(:disabled) { background: rgba(255,255,255,.25); }
.pagination-btn:disabled {
    color: rgba(255,255,255,.3);
    background: rgba(255,255,255,.05);
    cursor: not-allowed;
}
.pagination-current {
    padding: 0.375rem 0.875rem;
    border-radius: 0.5rem;
    background: #ffffff;
    color: #333333;
    font-size: 0.875rem;
    font-weight: 700;
    white-space: nowrap;
}

/* ── View modal ── */
.view-modal-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}
@media (max-width: 900px) {
    .view-modal-grid { grid-template-columns: 1fr; overflow-y: auto; }
}
.view-col-scroll {
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db #f9fafb;
}
.view-col-scroll::-webkit-scrollbar { width: 4px; }
.view-col-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.view-col-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Content panels (view modal right col) ── */
.content-panel {
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.content-panel-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 11px; font-weight: 800; color: #333333;
    text-transform: uppercase; letter-spacing: .08em;
    padding: 14px 20px 12px;
    border-bottom: 1.5px solid #f3f4f6;
    background: #fafafa;
}
.content-panel-title .icon-wrap {
    width: 26px; height: 26px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.content-panel-body {
    font-size: 15px; color: #444444; line-height: 1.8;
    white-space: pre-wrap; padding: 20px;
}
</style>

{{-- ── FLASH TOAST ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[999999] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-amber-300 text-amber-800':type==='warning','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Job Opportunities</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">
                    Openings available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 uppercase tracking-wide">
                <i class="fas fa-circle-check text-emerald-600 text-[10px]"></i>
                {{ $this->jobPostings->total() }} Active {{ $this->jobPostings->total() !== 1 ? 'Jobs' : 'Job' }}
            </span>
        </div>
    </div>

    {{-- ══ UNIFIED CONTENT BLOCK — filter + cards + pagination ══ --}}
    <div class="flex-1 min-h-0 flex flex-col content-block">

        {{-- ── FILTER BAR ── --}}
        <div class="content-block-filter flex flex-wrap gap-2 items-center">

            {{-- Purple badge ── --}}
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-sliders text-white text-sm"></i>
                <span class="hidden sm:inline">Filters</span>
            </div>

            {{-- Search — Alpine debounce ── --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#7a3f91; z-index:1;"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Title, company, location…"
                       class="filter-input w-full"
                       style="padding-left: 2.25rem; padding-right: 1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            {{-- Job Type dropdown ── --}}
            <select wire:model.live="filterType" class="filter-input" style="color:#333333;">
                <option value="">All Types</option>
                <option value="Full-Time">Full-Time</option>
                <option value="Part-Time">Part-Time</option>
                <option value="Contract">Contract</option>
                <option value="Internship">Internship</option>
                <option value="Freelance">Freelance</option>
            </select>

            {{-- Experience Level dropdown ── hidden on mobile ── --}}
            <select wire:model.live="filterLevel" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="">All Levels</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level</option>
                <option value="Mid Level (2-3 Years)">Mid Level</option>
                <option value="Senior Level (4-5 Years)">Senior Level</option>
                <option value="Expert Level (5+ Years)">Expert Level</option>
            </select>

            {{-- Sort dropdown ── hidden on mobile ── --}}
            <select wire:model.live="filterSort" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset ── --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile: Level + Sort ── --}}
            <select wire:model.live="filterLevel" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="">All Levels</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level</option>
                <option value="Mid Level (2-3 Years)">Mid Level</option>
                <option value="Senior Level (4-5 Years)">Senior Level</option>
                <option value="Expert Level (5+ Years)">Expert Level</option>
            </select>

            <select wire:model.live="filterSort" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── CARDS BODY ── --}}
        <div class="content-block-body relative flex-1 min-h-0">

            {{-- Loading Overlay ── --}}
            <div wire:loading
                 wire:target="search,filterType,filterLevel,filterSort,resetFilters,previousPage,nextPage"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold" style="color:#7a3f91;">Loading jobs…</span>
                </div>
            </div>

            @if($this->jobPostings->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($this->jobPostings as $job)
                @php
                    $dl          = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $today       = now('Asia/Manila')->startOfDay();
                    $daysLeft    = (int) $today->diffInDays($dl->copy()->startOfDay(), false);
                    $isUrgent    = $daysLeft <= 7;
                    $postedAgo   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    $deadlineStr = $dl->format('M d, Y');
                    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
                @endphp

                <div class="job-card flex flex-col">

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        {{-- Title + company ── --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-widest mb-0.5" style="color:#777777;">{{ $job->company_name }}</p>
                                <h3 class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</h3>
                            </div>
                            <span class="inline-flex shrink-0 items-center text-[10px] font-semibold px-2 py-0.5 rounded-lg border border-gray-200 bg-gray-50 mt-0.5" style="color:#666666;">
                                {{ Str::limit($displayType, 14) }}
                            </span>
                        </div>

                        {{-- Badges ── --}}
                        <div class="flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-100">
                                <i class="fas fa-clock text-[10px]"></i> {{ $job->employment_type }}
                            </span>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100">
                                <i class="fas fa-layer-group text-[10px]"></i> {{ Str::limit($job->experience_level, 22) }}
                            </span>
                        </div>

                        {{-- Location ── --}}
                        @if($job->location)
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-location-dot text-rose-500 text-[10px] flex-shrink-0"></i>
                            <span class="text-xs truncate" style="color:#555555;">{{ $job->location }}</span>
                        </div>
                        @endif

                        {{-- Salary ── --}}
                        @if($job->salary)
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-money-bill-wave text-emerald-500 text-[10px] flex-shrink-0"></i>
                            <span class="text-xs font-semibold truncate text-emerald-700">{{ $job->salary }}</span>
                        </div>
                        @else
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-money-bill-wave text-[10px] flex-shrink-0" style="color:#dddddd;"></i>
                            <span class="text-xs italic" style="color:#bbbbbb;">Salary not disclosed</span>
                        </div>
                        @endif

                        {{-- Description preview ── --}}
                        @if($job->description)
                        <p class="text-xs line-clamp-2 leading-relaxed" style="color:#888888;">{{ $job->description }}</p>
                        @endif

                        {{-- Footer ── --}}
                        <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-auto">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px]" style="color:#777777;">{{ $postedAgo }}</span>
                                @if($isUrgent)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-red-600">
                                        <i class="fas fa-fire text-[9px]"></i>
                                        Closes {{ $deadlineStr }}
                                        <span class="text-red-500">({{ $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : 'in '.$daysLeft.' days') }})</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[10px]" style="color:#999999;">
                                        <i class="far fa-calendar text-[9px]"></i>
                                        Closes {{ $deadlineStr }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5">
                                {{-- Share ── --}}
                                <button type="button"
                                        wire:click.stop="openShareModal({{ $job->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer
                                               bg-sky-50 text-sky-700 border-sky-200 hover:bg-white hover:border-sky-400">
                                    <i class="fas fa-share-nodes text-[10px]"></i>
                                    <span class="hidden sm:inline">Share</span>
                                </button>
                                {{-- View ── --}}
                                <button type="button"
                                        wire:click="viewJob({{ $job->id }})"
                                        class="card-view-btn inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition cursor-pointer"
                                        style="background-color:#7a3f91;">
                                    <i class="fas fa-eye text-[10px]"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @else
            {{-- ── Empty State ── --}}
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 h-full">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-briefcase text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#333333;">
                        @if($search || $filterType || $filterLevel) No jobs match your filters
                        @else No job openings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterType || $filterLevel) Try clearing your filters to see all available jobs.
                        @else Check back soon — new opportunities will be posted here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>.
                        @endif
                    </p>
                </div>
                @if($search || $filterType || $filterLevel)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>{{-- /content-block-body --}}

        {{-- ══ PAGINATION BAR — always visible ══ --}}
        @php
            $total = $this->jobPostings->total();
            $pp    = $this->jobPostings->perPage();
            $cp    = $this->jobPostings->currentPage();
            $lp    = $this->jobPostings->lastPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);
        @endphp
        <div class="pagination-bar">
            <p class="text-sm text-white/80 font-medium">
                Showing
                <span class="text-white font-bold">{{ $from }}–{{ $to }}</span>
                of
                <span class="text-white font-bold">{{ $total }}</span>
                job{{ $total !== 1 ? 's' : '' }}
            </p>
            <div class="flex items-center gap-1.5">
                <button wire:click="previousPage"
                        class="pagination-btn"
                        @if($this->jobPostings->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-xs"></i>
                    <span>Prev</span>
                </button>
                <span class="pagination-current">{{ $cp }} / {{ $lp }}</span>
                <button wire:click="nextPage"
                        class="pagination-btn"
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif>
                    <span>Next</span>
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
            </div>
        </div>

    </div>{{-- /content-block --}}

</div>{{-- /main layout --}}


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW JOB — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job         = $this->viewingJob;
    $dl          = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft    = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
    $isUrgent    = $daysLeft <= 7;
    $createdPH   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $hasQual     = !empty($job->qualifications);
    $hasInstr    = !empty($job->application_instructions);
@endphp

<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- ── HEADER BAR ── --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-[10px] font-semibold uppercase tracking-widest truncate">{{ $job->company_name }}</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $job->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
            @if($isUrgent)
            <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-500/80 border border-red-400/50 text-white text-xs font-bold">
                <i class="fas fa-fire text-xs"></i>
                {{ $daysLeft === 0 ? 'Closes today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft.' days left') }}
            </span>
            @endif
            <button type="button"
                    wire:click="openShareModal({{ $job->id }})"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer active:scale-95">
                <i class="fas fa-share-nodes text-xs"></i>
                <span class="hidden sm:inline">Share</span>
            </button>
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- ── META STRIP ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100 border-b border-gray-200 shrink-0 bg-white">
        <div class="flex items-center gap-3 px-5 lg:px-8 py-4">
            <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-sm"></i></span>
            <div class="min-w-0">
                <p class="meta-label">Location</p>
                <p class="meta-value truncate">{{ $job->location ?? 'Not specified' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3 px-5 lg:px-8 py-4">
            <span class="meta-row-icon bg-emerald-100"><i class="fas fa-money-bill-wave text-emerald-600 text-sm"></i></span>
            <div class="min-w-0">
                <p class="meta-label">Salary</p>
                @if($job->salary)
                    <p class="meta-value text-emerald-700 truncate">{{ $job->salary }}</p>
                @else
                    <p class="text-sm text-[#999999] italic mt-0.5">Not disclosed</p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-3 px-5 lg:px-8 py-4">
            <span class="meta-row-icon {{ $isUrgent ? 'bg-red-100' : 'bg-amber-100' }}">
                <i class="fas fa-calendar-xmark text-sm {{ $isUrgent ? 'text-red-600' : 'text-amber-600' }}"></i>
            </span>
            <div>
                <p class="meta-label">Deadline</p>
                <p class="meta-value {{ $isUrgent ? 'text-red-600' : '' }}">{{ $dl->format('M d, Y') }}</p>
                <p class="text-xs mt-0.5 {{ $isUrgent ? 'text-red-500 font-semibold' : 'text-[#999999]' }}">
                    @if($daysLeft === 0) Today!
                    @elseif($daysLeft === 1) Tomorrow
                    @else {{ $daysLeft }} days left
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3 px-5 lg:px-8 py-4">
            <span class="meta-row-icon bg-sky-100"><i class="fas fa-calendar-plus text-sky-600 text-sm"></i></span>
            <div>
                <p class="meta-label">Posted</p>
                <p class="meta-value">{{ $createdPH->format('M d, Y') }}</p>
                <p class="text-xs text-[#999999] mt-0.5">{{ $createdPH->diffForHumans() }}</p>
            </div>
        </div>
    </div>

    {{-- ── PILL BADGES ROW ── --}}
    <div class="flex flex-wrap items-center gap-2 px-5 py-3 bg-[#faf9fc] border-b border-gray-100 shrink-0">
        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-[#F9F7FC] border border-[#E8E0F0] text-sm font-semibold text-[#7A3F91]">
            <i class="fas fa-building text-xs"></i> {{ $displayType }}
        </span>
        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-blue-50 border border-blue-100 text-sm font-semibold text-blue-700">
            <i class="fas fa-clock text-xs"></i> {{ $job->employment_type }}
        </span>
        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-purple-50 border border-purple-100 text-sm font-semibold text-purple-700">
            <i class="fas fa-layer-group text-xs"></i> {{ $job->experience_level }}
        </span>
        @if($job->target_college)
            @foreach(explode(',', $job->target_college) as $col)
            <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-indigo-50 border border-indigo-200 text-sm font-semibold text-indigo-700">
                <i class="fas fa-building-columns text-xs"></i> {{ trim($col) }}
            </span>
            @endforeach
        @endif
        @if($isUrgent)
        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-red-50 border border-red-200 text-sm font-semibold text-red-600">
            <i class="fas fa-fire text-xs"></i>
            {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft.' days left') }}
        </span>
        @endif
    </div>

    {{-- ── TWO-COLUMN BODY ── --}}
    <div class="view-modal-grid flex-1 bg-[#f5f3f8]">

        {{-- ═══ LEFT COL: Meta ═══ --}}
        <div class="view-col-scroll border-r border-gray-200 bg-white px-5 py-6 flex flex-col gap-3">

            {{-- ── Active status badge (clean, no purple background) ── --}}
            <div class="flex items-center gap-2 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 shrink-0 mb-1">
                <span class="w-7 h-7 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-circle-check text-emerald-600 text-sm"></i>
                </span>
                <div>
                    <p class="meta-label text-emerald-600">Status</p>
                    <p class="text-sm font-bold text-emerald-700">Active</p>
                </div>
            </div>

            {{-- Employment Type + Level ── --}}
            <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100 shrink-0">
                <span class="meta-row-icon bg-blue-100"><i class="fas fa-clock text-blue-600 text-base"></i></span>
                <div>
                    <p class="meta-label">Employment Type</p>
                    <p class="meta-value">{{ $job->employment_type }}</p>
                    <p class="meta-sub">{{ $job->experience_level }}</p>
                </div>
            </div>

            {{-- Company ── --}}
            <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100 shrink-0">
                <span class="meta-row-icon bg-violet-100"><i class="fas fa-building text-violet-600 text-base"></i></span>
                <div class="min-w-0">
                    <p class="meta-label">Company</p>
                    <p class="meta-value truncate">{{ $job->company_name }}</p>
                    <p class="meta-sub truncate">{{ $displayType }}</p>
                </div>
            </div>

            {{-- Location ── --}}
            <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100 shrink-0">
                <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                <div class="min-w-0">
                    <p class="meta-label">Location</p>
                    <p class="meta-value truncate">{{ $job->location ?? 'Not specified' }}</p>
                </div>
            </div>

            {{-- Salary ── --}}
            <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100 shrink-0">
                <span class="meta-row-icon bg-emerald-100"><i class="fas fa-money-bill-wave text-emerald-600 text-base"></i></span>
                <div>
                    <p class="meta-label">Salary</p>
                    @if($job->salary)
                        <p class="meta-value text-emerald-700">{{ $job->salary }}</p>
                    @else
                        <p class="text-sm text-[#999999] italic mt-0.5">Not disclosed</p>
                    @endif
                </div>
            </div>

            {{-- Deadline ── --}}
            <div class="flex items-center gap-3 p-3.5 rounded-xl border shrink-0
                {{ $isUrgent ? 'bg-red-50 border-red-200' : 'bg-amber-50 border-amber-100' }}">
                <span class="meta-row-icon {{ $isUrgent ? 'bg-red-100' : 'bg-amber-100' }}">
                    <i class="fas fa-calendar-xmark text-base {{ $isUrgent ? 'text-red-600' : 'text-amber-600' }}"></i>
                </span>
                <div>
                    <p class="meta-label">Deadline</p>
                    <p class="meta-value {{ $isUrgent ? 'text-red-700' : 'text-amber-700' }}">{{ $dl->format('F d, Y') }}</p>
                    <p class="text-xs mt-0.5 font-semibold {{ $isUrgent ? 'text-red-500' : 'text-amber-500' }}">
                        @if($daysLeft === 0) Closing today!
                        @elseif($daysLeft === 1) Tomorrow
                        @else {{ $daysLeft }} days left
                        @endif
                    </p>
                </div>
            </div>

            {{-- Target College ── --}}
            @if($job->target_college)
            <div class="p-3.5 rounded-xl bg-purple-50 border border-purple-100 shrink-0">
                <p class="meta-label text-purple-600 mb-2">Target College</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach(explode(',', $job->target_college) as $col)
                        <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-lg bg-white text-purple-700 border border-purple-200">{{ trim($col) }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            <p class="text-xs text-center mt-auto pt-3 border-t border-gray-100 shrink-0" style="color:#bbbbbb;">
                Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
            </p>
        </div>

        {{-- ═══ RIGHT COL: Description, Qualifications, How to Apply ═══ --}}
        <div class="view-col-scroll px-6 lg:px-8 py-6 flex flex-col gap-5">

            {{-- Job Description ── --}}
            <div class="content-panel">
                <div class="content-panel-title">
                    <span class="icon-wrap bg-blue-50"><i class="fas fa-file-lines text-blue-500 text-xs"></i></span>
                    Job Description
                </div>
                <div class="content-panel-body">{{ $job->description }}</div>
            </div>

            {{-- Qualifications ── --}}
            @if($hasQual)
            <div class="content-panel">
                <div class="content-panel-title">
                    <span class="icon-wrap bg-purple-50"><i class="fas fa-list-check text-[#7a3f91] text-xs"></i></span>
                    Qualifications
                </div>
                <div class="content-panel-body">{{ $job->qualifications }}</div>
            </div>
            @endif

            {{-- How to Apply ── --}}
            @if($hasInstr)
            <div class="content-panel">
                <div class="content-panel-title">
                    <span class="icon-wrap bg-emerald-50"><i class="fas fa-paper-plane text-emerald-600 text-xs"></i></span>
                    How to Apply
                </div>
                <div class="content-panel-body" style="background:#f0fdf4; border: none;">{{ $job->application_instructions }}</div>
            </div>
            @else
            <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-8 text-center">
                <i class="fas fa-paper-plane text-gray-300 text-3xl mb-3 block"></i>
                <p class="text-sm font-medium" style="color:#999999;">No application instructions provided.</p>
                <p class="text-sm mt-1" style="color:#bbbbbb;">Contact the company directly to apply.</p>
            </div>
            @endif

            {{-- Urgency Alert ── --}}
            @if($isUrgent)
            <div class="flex items-start gap-4 bg-red-50 border border-red-200 rounded-2xl px-6 py-5">
                <span class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-fire text-red-600 text-base"></i>
                </span>
                <div>
                    <p class="text-base font-bold text-red-800">Application Closing Soon!</p>
                    <p class="text-sm text-red-700 mt-1 leading-relaxed">
                        @if($daysLeft === 0) The deadline is <span class="font-semibold">today</span>. Apply now before it's too late!
                        @elseif($daysLeft === 1) Only <span class="font-semibold">1 day</span> left. Don't miss your chance!
                        @else Only <span class="font-semibold">{{ $daysLeft }} days</span> left — closes on {{ $dl->format('F d, Y') }}.
                        @endif
                    </p>
                </div>
            </div>
            @endif

        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE MODAL — z-[10002]
══════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobsBaseUrl();
    $shareHost        = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';
    $shareDescPreview = mb_strlen($shareDescription) > 160
        ? mb_substr($shareDescription, 0, 160) . '…'
        : $shareDescription;

    $fbLines   = [];
    $fbLines[] = "🎯 Job Opening: {$shareJobTitle}";
    $fbLines[] = "🏢 {$shareCompany}";
    if ($shareLocation)    $fbLines[] = "📍 {$shareLocation}";
    if ($shareEmpType)     $fbLines[] = "💼 {$shareEmpType}";
    if ($shareExpLevel)    $fbLines[] = "📊 {$shareExpLevel}";
    if ($shareSalary)      $fbLines[] = "💰 {$shareSalary}";
    if ($shareDlFormatted) $fbLines[] = "📅 Deadline: {$shareDlFormatted}";
    if ($shareCollege)     $fbLines[] = "🏫 For: {$shareCollege}";
    $fbLines[] = '';
    $fbLines[] = "Apply now through the PHILCST Alumni Portal 👇";
    $fbLines[] = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);
@endphp

<div class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     x-data="{
         copied: false, fbCopied: false, messengerCopied: false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},

         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },

         async shareOnFacebook() {
             await this.copyText(this.fbText);
             this.fbCopied = true;
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(this.baseUrl),'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             setTimeout(() => this.fbCopied = false, 7000);
         },

         async shareOnMessenger() {
             await this.copyText(this.fbText);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/','_blank','noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/','_blank','noopener');
             }
             setTimeout(() => this.messengerCopied = false, 7000);
         },

         async copyLinkFn() {
             await this.copyText(this.baseUrl);
             this.copied = true;
             setTimeout(() => this.copied = false, 2500);
         }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden flex flex-col"
         style="max-height: 90vh;"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        {{-- Header ── --}}
        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-base font-semibold flex items-center gap-2.5" style="color:#333333;">
                <i class="fas fa-share-nodes text-sky-600 text-sm"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#333333;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Body ── --}}
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- LEFT: Preview ── --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto scroll-c">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4 bg-[#f9f7fc]">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareJobTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#555555;">{{ $shareCompany }}</p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEmpType)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700">
                                <i class="fas fa-clock text-[10px]"></i>{{ $shareEmpType }}
                            </span>
                            @endif
                            @if($shareLocation)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareLocation }}
                            </span>
                            @endif
                            @if($shareExpLevel)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">
                                <i class="fas fa-layer-group text-[10px]"></i>{{ $shareExpLevel }}
                            </span>
                            @endif
                            @if($shareSalary)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-emerald-50 text-emerald-700">
                                <i class="fas fa-money-bill-wave text-[10px]"></i>{{ $shareSalary }}
                            </span>
                            @endif
                            @if($shareDlFormatted)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-red-50 text-red-600">
                                <i class="fas fa-calendar-xmark text-[10px]"></i>Deadline: {{ $shareDlFormatted }}
                            </span>
                            @endif
                            @if($shareCollege)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700">
                                <i class="fas fa-building-columns text-[10px]"></i>{{ $shareCollege }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-5 py-3.5 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#333333;">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#555555;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#333333;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <p class="text-sm text-blue-800 leading-relaxed">
                        <span class="font-semibold">How it works:</span>
                        Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the full job caption and opens the platform.
                        Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.
                    </p>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-sm flex-shrink-0 mt-0.5" style="color:#7a3f91;"></i>
                    <p class="text-sm text-purple-700">
                        <span class="font-semibold" style="color:#5e2f72;">Post to Batch Chat</span> — sends directly to your batchmates' chat room with @everyone.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons ── --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto scroll-c">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">Share dialog opened!</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Press Ctrl+V to paste the caption.</p>
                    </div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Messenger opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V to paste the caption.</p>
                    </div>
                </div>

                {{-- Facebook ── --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Share on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens share dialog · caption copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                {{-- Messenger ── --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_job" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_job)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · caption copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post directly</span>
                    </div>
                </div>

                {{-- Batch Chat ── --}}
                <button type="button"
                        wire:click="shareToChat"
                        wire:loading.attr="disabled"
                        wire:target="shareToChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="shareToChat" class="block font-semibold text-sm">Post to Batch Chat</span>
                        <span wire:loading wire:target="shareToChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends @everyone to your batchmates</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or copy link</span>
                    </div>
                </div>

                {{-- Copy link ── --}}
                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white" style="color:#333333;">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-base" style="color:#555555;"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#555555;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" wire:click="closeShareModal"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>

                <p class="text-[10px] text-center leading-snug" style="color:#999999;">
                    Sharing is disabled for postings past their deadline.
                </p>
            </div>
        </div>
    </div>
</div>
@endif

</div>