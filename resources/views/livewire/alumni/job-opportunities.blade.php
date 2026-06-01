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

    public string $search         = '';
    public string $filterType     = '';
    public string $filterLevel    = '';
    public string $filterSort     = 'deadline_asc';

    public bool $showDetail    = false;
    public ?int $viewingJobId  = null;

    public string $alumniCollege   = '';
    public string $alumniCourse    = '';
    public string $alumniFirstName = '';
    public int    $alumniId        = 0;
    public int    $alumniRoomId    = 0;

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
        $this->filterSort = 'deadline_asc';
        $this->resetPage();
    }

    #[Computed]
    public function jobPostings()
    {
        $college = $this->alumniCollege;
        $today   = now('Asia/Manila')->toDateString();

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
            ->where('deadline', '>=', $today);

        if ($this->search !== '') {
            $s = strip_tags(trim($this->search));
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('location',     'like', "%{$s}%")
            );
        }

        if ($this->filterType  !== '') $q->where('employment_type',  $this->filterType);
        if ($this->filterLevel !== '') $q->where('experience_level', $this->filterLevel);

        match ($this->filterSort) {
            'deadline_asc'  => $q->orderBy('deadline', 'asc'),
            'recent'        => $q->orderBy('created_at', 'desc'),
            default         => $q->orderBy('deadline', 'asc'),
        };

        return $q->paginate(20);
    }

    public function viewJob(int $id): void
    {
        $this->viewingJobId = $id;
        $this->showDetail   = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail   = false;
        $this->viewingJobId = null;
    }

    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return JobPosting::where('id', $this->viewingJobId)
            ->where('status', 'ACTIVE')
            ->first();
    }

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
/* ─────────────────────────────────────────────
   FILTER SELECTS
───────────────────────────────────────────── */
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.1rem;
    -webkit-appearance: none;
    appearance: none;
}

/* ─────────────────────────────────────────────
   DETAIL PAGE ENTRANCE
───────────────────────────────────────────── */
@keyframes detailIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }

/* ─────────────────────────────────────────────
   SHARE SHEET ENTRANCE
───────────────────────────────────────────── */
@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }

/* ─────────────────────────────────────────────
   SCROLLBAR
───────────────────────────────────────────── */
.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

.pre-wrap { white-space: pre-wrap; }

.share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ─────────────────────────────────────────────
   MOUSE-FOLLOWING "VIEW DETAILS" LABEL
   Rendered as a fixed div positioned by JS
───────────────────────────────────────────── */
#jb-cursor-label {
    position: fixed;
    z-index: 99999;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 5px;
    background: #111827;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    box-shadow: 0 4px 16px rgba(0,0,0,.28);
    user-select: none;
    font-family: ui-sans-serif, system-ui, sans-serif;
    opacity: 0;
    visibility: hidden;
    transition: opacity .1s ease, visibility .1s ease;
    left: -999px;
    top: -999px;
}
#jb-cursor-label svg {
    width: 11px;
    height: 11px;
    flex-shrink: 0;
    fill: none;
    stroke: #fff;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

/* ─────────────────────────────────────────────
   CARD HOVER STATE
───────────────────────────────────────────── */
[data-jb-card] {
    transition: border-color .15s ease, box-shadow .15s ease;
}
[data-jb-card]:hover {
    border-color: #c4b5d4 !important;
    box-shadow: 0 4px 20px rgba(122,63,145,.12) !important;
}

/* Share icon button on card */
.card-share-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
    z-index: 2;
}
.card-share-btn:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    transform: scale(1.08);
}
/* Tooltip ABOVE the share button */
.card-share-btn .tip {
    position: absolute;
    bottom: calc(100% + 7px);
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s;
    z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
/* Arrow pointing DOWN (tooltip is above) */
.card-share-btn .tip::after {
    content: '';
    position: absolute;
    top: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-top-color: #111827;
}
.card-share-btn:hover .tip { opacity: 1; }

/* ─────────────────────────────────────────────
   DETAIL HEADER — GLASSY BUTTONS
   Tooltips BELOW the button
───────────────────────────────────────────── */
.detail-top-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: background .15s, transform .1s;
    flex-shrink: 0;
    border: none;
    outline: none;
}
.detail-top-btn:active { transform: scale(.93); }

/* Tooltip BELOW */
.detail-top-btn .tip {
    position: absolute;
    top: calc(100% + 6px);   /* ← below the button */
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s;
    z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
/* Arrow pointing UP (tooltip is below button) */
.detail-top-btn .tip::before {
    content: '';
    position: absolute;
    bottom: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-bottom-color: #111827;
}
.detail-top-btn:hover .tip { opacity: 1; }

/* Share variant */
.detail-top-btn.share-btn {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
}
.detail-top-btn.share-btn:hover { background: rgba(255,255,255,.24); }

/* Close variant */
.detail-top-btn.close-btn {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.15);
}
.detail-top-btn.close-btn:hover { background: rgba(255,255,255,.22); }
.detail-top-btn.close-btn svg {
    width: 13px;
    height: 13px;
    stroke: #fff;
    stroke-width: 2.5;
    stroke-linecap: round;
}

/* ─────────────────────────────────────────────
   SHARE MODAL — Purple close button
───────────────────────────────────────────── */
.btn-close-purple {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: #7a3f91;
    border: none;
    cursor: pointer;
    transition: background .15s, transform .1s;
    flex-shrink: 0;
}
.btn-close-purple:hover  { background: #5e2f72; }
.btn-close-purple:active { transform: scale(.93); }
.btn-close-purple svg    { width: 14px; height: 14px; stroke: #fff; stroke-width: 2.5; stroke-linecap: round; }

/* ─────────────────────────────────────────────
   DETAIL PAGE — force sans-serif everywhere
───────────────────────────────────────────── */
.detail-page * {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                 "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    font-style: normal !important;
}
.detail-header-title {
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.detail-label { font-style: italic; }
</style>

{{-- Mouse-following cursor label (rendered once, moved by JS) --}}
<div id="jb-cursor-label">
    <svg viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
    View Details
</div>

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
     class="fixed top-5 right-4 sm:right-6 z-[999999] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{'border-emerald-300 text-emerald-800':type==='success','border-blue-300 text-blue-800':type==='info','border-amber-300 text-amber-800':type==='warning','border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- PAGE HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-gray-900">Job Opportunities</h1>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700">
                    Openings available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-gray-100 border-b border-[#E8E0F0] px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">

            <span class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] select-none px-1">Filters</span>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Title, company, location…"
                       class="filter-input w-full pl-8 pr-3 py-[7px] text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                              hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            {{-- Employment Type --}}
            <select wire:model.live="filterType"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                <option value="">All Types</option>
                <option value="Full-Time">Full-Time</option>
                <option value="Part-Time">Part-Time</option>
                <option value="Contract">Contract</option>
                <option value="Internship">Internship</option>
                <option value="Freelance">Freelance</option>
            </select>

            {{--
                Experience Level — values now match exactly what the organizer
                stores via the JobOption table (same order as $expLevelOrder in
                the organizer component).
            --}}
            <select wire:model.live="filterLevel"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                <option value="">All Experience</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level (At Least 1 Year)</option>
                <option value="Mid Level (2-3 Years)">Mid Level (2-3 Years)</option>
                <option value="Senior Level (4-5 Years)">Senior Level (4-5 Years)</option>
                <option value="Expert Level (5+ Years)">Expert Level (5+ Years)</option>
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-[7px] rounded-lg text-xs font-semibold
                           bg-white border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300
                           transition active:scale-95 cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-xs"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-3.5 h-3.5 text-[#7a3f91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- ── CARDS BODY ── --}}
        <div class="bg-gray-100 p-4 relative flex-1 min-h-0">

            @if($this->jobPostings->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->jobPostings as $job)
                @php
                    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $today    = now('Asia/Manila')->startOfDay();
                    $daysLeft = (int) $today->diffInDays($dl->copy()->startOfDay(), false);

                    if ($daysLeft === 0)     $dlLabel = 'Closes today';
                    elseif ($daysLeft === 1) $dlLabel = '1 day left';
                    else                     $dlLabel = $daysLeft . ' days left';

                    if ($daysLeft <= 3)       { $dlClass = 'text-red-600 font-bold'; $dlIcon = 'fa-fire'; }
                    elseif ($daysLeft <= 14)  { $dlClass = 'text-orange-700 font-semibold'; $dlIcon = 'fa-clock'; }
                    else                      { $dlClass = 'text-gray-600 font-medium'; $dlIcon = 'fa-calendar'; }

                    $descPreview = $job->description ? Str::limit(strip_tags($job->description), 90) : null;
                    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
                @endphp

                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden
                            cursor-pointer relative select-none flex flex-col group"
                     data-jb-card
                     wire:click="viewJob({{ $job->id }})"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        {{-- Company + Type badge --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-widest mb-1" style="color:#333333;">{{ $job->company_name }}</p>
                                <h3 class="font-semibold text-[15px] leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</h3>
                            </div>
                            @if($displayType)
                            <span class="inline-flex shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-md border border-gray-200 bg-gray-50 mt-0.5 whitespace-nowrap" style="color:#333333;">
                                {{ Str::limit($displayType, 14) }}
                            </span>
                            @endif
                        </div>

                        {{-- Tags --}}
                        <div class="flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center text-[12px] font-medium px-2.5 py-0.5 rounded-md bg-purple-50 border border-purple-100 text-purple-700">
                                {{ $job->employment_type }}
                            </span>
                            <span class="inline-flex items-center text-[12px] font-medium px-2.5 py-0.5 rounded-md bg-gray-100 border border-gray-200" style="color:#333333;">
                                {{ Str::words($job->experience_level, 3, '') }}
                            </span>
                        </div>

                        @if($job->location)
                        <p class="text-[13px] truncate flex items-center gap-1.5" style="color:#333333;">
                            <i class="fas fa-location-dot text-[11px]" style="color:#999;"></i>{{ $job->location }}
                        </p>
                        @endif

                        @if($job->salary)
                        <p class="text-[13px] font-semibold text-emerald-600 flex items-center gap-1.5">
                            <i class="fas fa-money-bill-wave text-emerald-400 text-[11px]"></i>{{ $job->salary }}
                        </p>
                        @else
                        <p class="text-[13px] italic" style="color:#333333;">Salary not disclosed</p>
                        @endif

                        @if($descPreview)
                        <p class="text-[13px] line-clamp-2 leading-relaxed" style="color:#333333;">{{ $descPreview }}</p>
                        @endif

                        {{-- Footer: deadline + share --}}
                        <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-auto">
                            <span class="text-[13px] {{ $dlClass }} flex items-center gap-1.5">
                                <i class="fas {{ $dlIcon }} text-[11px]"></i>
                                {{ $dlLabel }}
                            </span>

                            <button type="button"
                                    data-jb-share
                                    wire:click.stop="openShareModal({{ $job->id }})"
                                    class="card-share-btn">
                                <i class="fas fa-share-nodes text-[11px]"></i>
                                <span class="tip">Share</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 h-full">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-briefcase text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-gray-700">
                        @if($search || $filterType || $filterLevel) No jobs match your filters
                        @else No job openings yet @endif
                    </p>
                    <p class="text-sm mt-1 text-gray-500">
                        @if($search || $filterType || $filterLevel) Try clearing your filters to see all available jobs.
                        @else Check back soon — new opportunities will be posted here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>. @endif
                    </p>
                </div>
                @if($search || $filterType || $filterLevel)
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    Clear Filters
                </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ══ PAGINATION BAR ══ --}}
        @php
            $total   = $this->jobPostings->total();
            $pp      = $this->jobPostings->perPage();
            $cp      = $this->jobPostings->currentPage();
            $lp      = $this->jobPostings->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="flex items-center justify-between gap-2 flex-wrap px-5 min-h-[48px]
                    bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0">

            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                {{ $total !== 1 ? 'records' : 'record' }}
            </p>

            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif
                        aria-label="Previous">
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
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif
                        aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>{{-- end content-block --}}
</div>


{{-- ══ FULL-SCREEN JOB DETAIL ══ --}}
@if($showDetail && $this->viewingJob)
@php
    $job      = $this->viewingJob;
    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);

    if ($daysLeft === 0)     $dlLabel = 'Closes today';
    elseif ($daysLeft === 1) $dlLabel = '1 day left';
    else                     $dlLabel = $daysLeft . ' days left';

    $dlIsUrgent = $daysLeft <= 3;
    $dlIsSoon   = !$dlIsUrgent && $daysLeft <= 14;
    $dlValueClass = $dlIsUrgent ? 'text-red-600 font-bold' : ($dlIsSoon ? 'text-orange-700 font-bold' : 'text-gray-900 font-semibold');

    $isUrgent    = $daysLeft <= 7;
    $createdPH   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $hasQual     = !empty($job->qualifications);
    $hasInstr    = !empty($job->application_instructions);
@endphp

<div class="detail-page fixed inset-0 z-[9000] flex flex-col bg-gray-100 overflow-hidden"
     @keydown.escape.window="$wire.closeDetail()">

    {{-- Purple top bar --}}
    <div class="flex items-center justify-between px-6 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">

        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <span class="detail-header-title">Job Details</span>
        </div>

        {{-- Action buttons — tooltips BELOW --}}
        <div class="flex items-center gap-1.5 flex-shrink-0">
            <button type="button"
                    wire:click="openShareModal({{ $job->id }})"
                    class="detail-top-btn share-btn"
                    aria-label="Share">
                <i class="fas fa-share-nodes text-[13px] text-white"></i>
                <span class="tip">Share</span>
            </button>
            <button type="button"
                    wire:click="closeDetail"
                    class="detail-top-btn close-btn"
                    aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    {{-- White hero --}}
    <div class="bg-white border-b border-gray-200 px-6 py-4 flex-shrink-0">
        <p class="text-[9px] font-bold uppercase tracking-[.16em] mb-1" style="color:#333333;">Job Title</p>
        <h2 class="text-2xl font-semibold leading-snug mb-2" style="color:#333333;">{{ $job->job_title }}</h2>
        <p class="text-sm font-semibold uppercase tracking-[.08em] mb-3" style="color:#333333;">{{ $job->company_name }}</p>
        <div class="flex flex-wrap gap-1.5">
            @if($displayType)
                <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $displayType }}</span>
            @endif
            <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->employment_type }}</span>
            <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->experience_level }}</span>
            @if($job->target_college)
                @foreach(explode(',', $job->target_college) as $col)
                    <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ trim($col) }}</span>
                @endforeach
            @endif
            @if($isUrgent)
                <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-red-200 bg-white text-red-700">
                    <i class="fas fa-fire mr-1 text-[10px]"></i>{{ $dlLabel }}
                </span>
            @endif
        </div>
    </div>

    {{-- Info strip --}}
    <div class="bg-white border-b border-gray-200 flex flex-wrap flex-shrink-0">
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Company</span>
            <span class="text-base font-semibold" style="color:#333333;">{{ $job->company_name }}</span>
            @if($displayType && $displayType !== $job->company_name)
                <span class="text-sm" style="color:#333333;">{{ $displayType }}</span>
            @endif
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Location</span>
            <span class="text-base font-semibold" style="color:#333333;">{{ $job->location ?: '—' }}</span>
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Salary</span>
            @if($job->salary)
                <span class="text-base font-semibold text-emerald-600">{{ $job->salary }}</span>
            @else
                <span class="text-base italic font-normal" style="color:#333333;">Not disclosed</span>
            @endif
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Deadline</span>
            <span class="text-base {{ $dlValueClass }}">{{ $dl->format('M d, Y') }}</span>
            <span class="text-sm {{ $dlValueClass }}">
                @if($dlIsUrgent)<i class="fas fa-fire mr-0.5 text-xs"></i>@endif
                {{ $dlLabel }}
            </span>
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Posted</span>
            <span class="text-base font-semibold" style="color:#333333;">{{ $createdPH->format('M d, Y') }}</span>
            <span class="text-sm" style="color:#333333;">{{ $createdPH->diffForHumans() }}</span>
        </div>
    </div>

    {{-- Scrollable content --}}
    <div class="flex-1 overflow-y-auto bg-gray-100 scroll-thin min-h-0">
        <div class="max-w-[1100px] mx-auto px-5 py-4 pb-8 flex flex-col gap-4">

            @if($isUrgent)
            <div class="bg-red-50 border border-red-200 border-l-4 border-l-red-600 rounded-lg px-4 py-3 text-sm text-gray-900 leading-relaxed">
                @if($daysLeft === 0) Deadline is <strong class="text-red-600">today</strong>. Apply before it's too late.
                @elseif($daysLeft === 1) Only <strong class="text-red-600">1 day</strong> left — apply now.
                @else Only <strong class="text-red-600">{{ $daysLeft }} days</strong> left. Closes {{ $dl->format('F d, Y') }}.
                @endif
            </div>
            @endif

            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Job Description</span>
                </div>
                <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->description }}</div>
            </div>

            @if($hasQual || $hasInstr)
            <div class="{{ ($hasQual && $hasInstr) ? 'grid grid-cols-1 md:grid-cols-2 gap-4' : '' }}">
                @if($hasQual)
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Qualifications</span>
                    </div>
                    <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->qualifications }}</div>
                </div>
                @endif
                @if($hasInstr)
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-emerald-50">
                        <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-emerald-700">How to Apply</span>
                    </div>
                    <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->application_instructions }}</div>
                </div>
                @endif
            </div>
            @endif

            <p class="text-center text-xs" style="color:#333333;">Posted {{ $createdPH->format('M d, Y \a\t g:i A') }}</p>
        </div>
    </div>

</div>
@endif


{{-- ══ SHARE MODAL ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobsBaseUrl();
    $shareHost        = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';

    $fieldCount = (int)(bool)$shareEmpType + (int)(bool)$shareLocation + (int)(bool)$shareExpLevel + (int)(bool)$shareSalary + (int)(bool)$shareDlFormatted;
    $descLimit  = $fieldCount >= 4 ? 100 : ($fieldCount >= 2 ? 140 : 180);
    $shareDescPreview = mb_strlen($shareDescription) > $descLimit
        ? mb_substr($shareDescription, 0, $descLimit) . '…'
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

<div class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm"
     x-data="{
         copied:false, fbCopied:false, messengerCopied:false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},
         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); }
                 else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },
         async shareOnFacebook() {
             await this.copyText(this.fbText); this.fbCopied = true;
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(this.baseUrl),'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             setTimeout(() => this.fbCopied = false, 7000);
         },
         async shareOnMessenger() {
             await this.copyText(this.fbText); this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/new','_blank','noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/new','_blank','noopener,noreferrer');
             }
             setTimeout(() => this.messengerCopied = false, 7000);
         },
         async copyLinkFn() { await this.copyText(this.baseUrl); this.copied = true; setTimeout(() => this.copied = false, 2500); }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-2xl share-modal-wrapper">

        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2 text-gray-800">
                <i class="fas fa-share-nodes text-sky-600 text-xs"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" type="button" class="btn-close-purple" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 flex-shrink-0">Post Preview</p>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0">
                    <div class="border-b border-gray-100 px-4 py-3 bg-gray-50">
                        <p class="font-semibold text-gray-900 leading-tight" style="font-size:clamp(12px,1.2vw,14px);">{{ $shareJobTitle }}</p>
                        <p class="font-medium text-gray-500 mt-0.5" style="font-size:clamp(10px,1vw,12px);">{{ $shareCompany }}</p>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @if($shareEmpType)     <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareEmpType }}</span> @endif
                            @if($shareLocation)    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareLocation }}</span> @endif
                            @if($shareExpLevel)    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareExpLevel }}</span> @endif
                            @if($shareSalary)      <span class="inline-flex items-center px-1.5 py-0.5 rounded text-emerald-700 bg-emerald-50" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareSalary }}</span> @endif
                            @if($shareDlFormatted) <span class="inline-flex items-center px-1.5 py-0.5 rounded text-red-600 bg-red-50" style="font-size:clamp(9px,0.85vw,11px);">Deadline: {{ $shareDlFormatted }}</span> @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-4 py-2 border-b border-gray-100">
                        <p class="leading-relaxed text-gray-600" style="font-size:clamp(10px,0.9vw,12px);">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-4 py-2 bg-gray-50">
                        <span class="uppercase tracking-wider font-semibold text-gray-400" style="font-size:clamp(9px,0.8vw,11px);">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-xs flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the caption and opens the platform.
                        Press <kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd> (or <kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">⌘V</kbd>) to paste it.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Share via</p>

                <div x-show="fbCopied" x-cloak x-transition
                     class="bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-emerald-800">Caption copied! Paste it on Facebook.</p>
                </div>
                <div x-show="messengerCopied" x-cloak x-transition
                     class="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-blue-800">Caption copied! Pick a contact in Messenger and paste.</p>
                </div>

                {{-- Facebook --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm transition cursor-pointer">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share on Facebook</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Caption auto-copied · paste to post</p>
                    </div>
                </button>

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-white font-semibold text-sm transition cursor-pointer"
                        style="background:linear-gradient(135deg,#0099FF,#A033FF);">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4">
                            <defs>
                                <linearGradient id="mgr_alumni" x1="0%" y1="100%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color:#0099FF"/>
                                    <stop offset="100%" style="stop-color:#A033FF"/>
                                </linearGradient>
                            </defs>
                            <path fill="url(#mgr_alumni)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Send via Messenger</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Pick a contact · paste caption</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-lightbulb text-amber-500 text-[10px] flex-shrink-0 mt-0.5"></i>
                    <p class="text-[10px] text-gray-500 leading-relaxed">
                        Messenger will open. Search a contact, start a conversation, then press <span class="font-semibold text-gray-700">Ctrl+V</span> to paste the job details.
                    </p>
                </div>

                {{-- Batch Chat --}}
                <button type="button" wire:click="shareToChat"
                        wire:loading.attr="disabled"
                        wire:target="shareToChat"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-white font-semibold text-sm transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-60 disabled:cursor-not-allowed">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white/20 border border-white/20">
                        <span wire:loading.remove wire:target="shareToChat">
                            <i class="fas fa-comments text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="shareToChat">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Post to Batch Chat</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Notify all your batchmates</p>
                    </div>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white text-gray-400">or copy link</span>
                    </div>
                </div>

                {{-- Copy link --}}
                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300
                               hover:bg-gray-50 text-sm transition cursor-pointer bg-white text-gray-700">
                    <span class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-500'" class="text-sm"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : 'text-gray-700'" x-text="copied ? 'Link copied!' : 'Copy Link'"></p>
                        <p class="text-[10px] font-mono text-gray-400 truncate">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" wire:click="closeShareModal"
                        class="w-full px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition cursor-pointer">
                    Close
                </button>
                <p class="text-[10px] text-center text-gray-400">Sharing is disabled for expired postings.</p>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- end root --}}

{{-- ── Mouse-following cursor label logic ── --}}
<script>
(function () {
    // Wait for DOM to be fully ready
    function init() {
        const label = document.getElementById('jb-cursor-label');
        if (!label) return;

        let activeCard = null;
        let mouseX = 0;
        let mouseY = 0;

        function show() {
            label.style.opacity    = '1';
            label.style.visibility = 'visible';
        }

        function hide() {
            label.style.opacity    = '0';
            label.style.visibility = 'hidden';
        }

        function onMouseMove(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
            // Offset so label appears slightly below-right of cursor
            label.style.left = (mouseX + 16) + 'px';
            label.style.top  = (mouseY + 14) + 'px';
        }

        function onCardEnter(e) {
            // Don't show if coming from a child element (bubbling)
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = e.currentTarget;
            document.addEventListener('mousemove', onMouseMove);
            show();
        }

        function onCardLeave(e) {
            // Only hide if truly leaving the card boundary
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = null;
            hide();
            document.removeEventListener('mousemove', onMouseMove);
        }

        function onShareEnter() {
            hide();
        }

        function onShareLeave() {
            if (activeCard) show();
        }

        function attachListeners() {
            document.querySelectorAll('[data-jb-card]').forEach(card => {
                if (card._jbBound) return;
                card._jbBound = true;

                card.addEventListener('mouseenter', onCardEnter);
                card.addEventListener('mouseleave', onCardLeave);

                const shareBtn = card.querySelector('[data-jb-share]');
                if (shareBtn) {
                    shareBtn.addEventListener('mouseenter', onShareEnter);
                    shareBtn.addEventListener('mouseleave', onShareLeave);
                }
            });
        }

        attachListeners();

        // Livewire v3 — re-attach after any DOM update
        document.addEventListener('livewire:navigated', () => {
            // Reset all bound flags so we re-attach fresh
            document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
            attachListeners();
        });

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', ({ el }) => {
                requestAnimationFrame(() => {
                    document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
                    attachListeners();
                });
            });
            // Livewire v3 commit hook
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        requestAnimationFrame(() => {
                            document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
                            attachListeners();
                        });
                    });
                });
            } catch(e) {}
        }

        // Hide label if modal opens (share or detail)
        document.addEventListener('livewire:update', () => {
            hide();
            activeCard = null;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>