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

        // Resolve batch chat room ID for "Share to Chat"
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

        return $q->paginate(12);
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

    // ── Share to Batch Chat ───────────────────────────────────────────────────

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

{{-- Root: flex column filling available viewport height --}}
<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

{{-- ── FLASH TOAST ─────────────────────────────────────────────────────────── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-amber-300 text-amber-800':type==='warning','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }

.job-card { transition: box-shadow .18s, transform .18s; }
.job-card:hover {
    box-shadow: 0 8px 28px rgba(122,63,145,.18), 0 2px 8px rgba(0,0,0,.07);
    transform: translateY(-3px);
}
.job-card:hover .card-view-hint { background-color: #6a3080 !important; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.filter-input {
    border: 1.5px solid #d1d5db;
    transition: border-color .15s, box-shadow .15s;
}
.filter-input:hover  { border-color: #7a3f91; }
.filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
</style>

    {{-- Inner flex column: header + filters + cards grow, pagination pinned last --}}
    <div class="flex flex-col flex-1 gap-5">

        {{-- ══ PAGE HEADER ═══════════════════════════════════════════════════ --}}
        <div class="rounded-2xl overflow-hidden shadow-sm" style="background-color:#7a3f91;">
            <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                        <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-briefcase text-white text-base"></i>
                        </div>
                        Job Opportunities
                    </h1>
                    <p class="text-sm text-white/75 mt-1 ml-12">
                        Showing jobs available for
                        <span class="font-semibold text-white">{{ $alumniCourse ?: 'your course' }}</span>
                        @if($alumniCollege)
                            &middot; <span class="font-semibold text-white/90">{{ $alumniCollege }}</span>
                        @endif
                    </p>
                </div>
                <div class="ml-12 sm:ml-0">
                    <span class="inline-flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl bg-white/20 text-white border border-white/30">
                        <i class="fa-solid fa-circle-check text-emerald-300"></i>
                        {{ $this->jobPostings->total() }} active job{{ $this->jobPostings->total() !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- ══ FILTER BAR ════════════════════════════════════════════════════ --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-4 py-3 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[200px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search title, company, location…"
                       class="filter-input w-full pl-9 pr-3 py-2.5 rounded-xl text-sm text-gray-900 bg-white"
                       autocomplete="off" maxlength="100">
            </div>
            <select wire:model.live="filterType" class="filter-input px-3 py-2.5 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Types</option>
                <option value="Full-Time">Full-Time</option>
                <option value="Part-Time">Part-Time</option>
                <option value="Contract">Contract</option>
                <option value="Internship">Internship</option>
                <option value="Freelance">Freelance</option>
            </select>
            <select wire:model.live="filterLevel" class="filter-input px-3 py-2.5 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Levels</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level (At Least 1 Year)</option>
                <option value="Mid Level (2-3 Years)">Mid Level (2-3 Years)</option>
                <option value="Senior Level (4-5 Years)">Senior Level (4-5 Years)</option>
                <option value="Expert Level (5+ Years)">Expert Level (5+ Years)</option>
            </select>
            <select wire:model.live="filterSort" class="filter-input px-3 py-2.5 rounded-xl text-sm bg-white text-gray-700">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters"
                    class="filter-input px-3 py-2.5 rounded-xl bg-white text-sm font-medium text-gray-600 hover:bg-gray-50 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ══ CARDS / EMPTY STATE ═══════════════════════════════════════════ --}}
        <div wire:loading.class="opacity-50 pointer-events-none"
             wire:target="search,filterType,filterLevel,filterSort,resetFilters,previousPage,nextPage">

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
                    <div class="job-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="p-5 flex flex-col gap-3">

                            {{-- Company + badge --}}
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-500 truncate">{{ $job->company_name }}</p>
                                    <h3 class="text-base font-extrabold text-gray-900 leading-snug mt-0.5 line-clamp-2">{{ $job->job_title }}</h3>
                                </div>
                                <span class="inline-flex shrink-0 items-center text-xs font-bold px-2.5 py-1 rounded-full border border-gray-200 bg-gray-100 text-gray-600 mt-0.5">
                                    {{ Str::limit($displayType, 14) }}
                                </span>
                            </div>

                            {{-- Pills --}}
                            <div class="flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700 border border-blue-100">
                                    <i class="fa-solid fa-clock text-[10px]"></i> {{ $job->employment_type }}
                                </span>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-600 border border-gray-200">
                                    <i class="fa-solid fa-layer-group text-[10px]"></i> {{ Str::limit($job->experience_level, 22) }}
                                </span>
                            </div>

                            {{-- Location --}}
                            @if($job->location)
                            <div class="flex items-center gap-1.5 text-sm text-gray-500">
                                <i class="fa-solid fa-location-dot text-gray-400 text-xs"></i>
                                <span class="truncate">{{ $job->location }}</span>
                            </div>
                            @endif

                            {{-- Salary --}}
                            @if($job->salary)
                            <div class="flex items-center gap-1.5 text-sm font-semibold text-emerald-700">
                                <i class="fa-solid fa-money-bill-wave text-xs"></i>
                                <span class="truncate">{{ $job->salary }}</span>
                            </div>
                            @else
                            <div class="flex items-center gap-1.5 text-sm text-gray-400 italic">
                                <i class="fa-solid fa-money-bill-wave text-xs"></i>
                                Salary not disclosed
                            </div>
                            @endif

                            {{-- Footer --}}
                            <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-0.5">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-xs text-gray-400">{{ $postedAgo }}</span>
                                    @if($isUrgent)
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-red-600">
                                            <i class="fa-solid fa-fire text-[10px]"></i>
                                            Closes {{ $deadlineStr }} ({{ $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : 'in '.$daysLeft.' days') }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-400">
                                            <i class="fa-regular fa-calendar text-[10px]"></i>
                                            Closes {{ $deadlineStr }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button type="button"
                                            wire:click.stop="openShareModal({{ $job->id }})"
                                            class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-bold bg-sky-100 text-sky-700 border border-sky-300 hover:bg-white hover:border-sky-500 transition cursor-pointer">
                                        <i class="fas fa-share-nodes text-xs"></i>
                                        <span class="hidden sm:inline">Share</span>
                                    </button>
                                    <button type="button"
                                            wire:click="viewJob({{ $job->id }})"
                                            class="card-view-hint inline-flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-lg text-white cursor-pointer"
                                            style="background-color:#7a3f91;">
                                        <i class="fa-solid fa-eye text-xs"></i> View
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

            @else
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm py-20 flex flex-col items-center gap-4 text-center px-6">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fa-solid fa-briefcase text-2xl text-gray-400"></i>
                    </div>
                    <div>
                        <p class="font-bold text-gray-700 text-lg">
                            @if($search || $filterType || $filterLevel)
                                No jobs match your filters
                            @else
                                No job openings yet
                            @endif
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            @if($search || $filterType || $filterLevel)
                                Try clearing your filters to see all available jobs.
                            @else
                                Check back soon — new opportunities will be posted here for
                                <strong>{{ $alumniCollege ?: 'your college' }}</strong>.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterType || $filterLevel)
                        <button wire:click="resetFilters" class="px-4 py-2 rounded-xl text-sm font-bold text-white" style="background-color:#7a3f91;">
                            <i class="fa-solid fa-rotate-left mr-1.5"></i> Clear Filters
                        </button>
                    @endif
                </div>
            @endif
        </div>

        {{-- ══ PAGINATION — mt-auto pushes it to the bottom of the flex column ══ --}}
        <div class="mt-auto rounded-2xl px-4 sm:px-5 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background-color:#2b0d3e;">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="text-white text-xs sm:text-sm">
                Showing <span class="font-bold">{{ $from }}&ndash;{{ $to }}</span>
                of <span class="font-bold">{{ $total }}</span> job{{ $total !== 1 ? 's' : '' }}
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->jobPostings->onFirstPage())
                    <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">&larr; Prev</button>
                @else
                    <button wire:click="previousPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white cursor-pointer hover:opacity-90 transition"
                            style="background-color:#7a3f91;">&larr; Prev</button>
                @endif
                <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold shadow-sm">
                    {{ $cp }} / {{ $this->jobPostings->lastPage() }}
                </span>
                @if($this->jobPostings->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white cursor-pointer hover:opacity-90 transition"
                            style="background-color:#7a3f91;">Next &rarr;</button>
                @else
                    <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next &rarr;</button>
                @endif
            </div>
        </div>

    </div>{{-- end inner flex column --}}


    {{-- ══ VIEW MODAL ════════════════════════════════════════════════════════ --}}
    @if($showViewModal && $this->viewingJob)
    @php
        $job         = $this->viewingJob;
        $dl          = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
        $daysLeft    = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
        $isUrgent    = $daysLeft <= 7;
        $createdPH   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
        $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         wire:keydown.escape.window="closeViewModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative">

            <button wire:click="closeViewModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-white/25 hover:bg-white/40 transition text-white z-10">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- Header --}}
            <div class="px-6 pt-6 pb-5 flex-shrink-0 text-white" style="background-color:#7a3f91;">
                <div class="flex items-center gap-2 mb-2 flex-wrap pr-8">
                    <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-building text-white text-base"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white/75">{{ $job->company_name }}</p>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-white/20 text-white">{{ $displayType }}</span>
                    </div>
                </div>
                <h2 class="text-2xl font-extrabold text-white leading-snug mb-3">{{ $job->job_title }}</h2>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1.5 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-clock text-xs"></i> {{ $job->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1.5 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-layer-group text-xs"></i> {{ $job->experience_level }}
                    </span>
                    @if($isUrgent)
                        <span class="inline-flex items-center gap-1 text-sm font-bold px-3 py-1.5 rounded-lg bg-red-500/80 text-white">
                            <i class="fa-solid fa-fire text-xs"></i>
                            {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft.' days left') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Body --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">

                {{-- Key details --}}
                <div class="px-6 py-5 border-b border-gray-100">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex items-start gap-2.5">
                            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-location-dot text-base text-gray-500"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Location</p>
                                <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $job->location ?? 'Not specified' }}</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-money-bill-wave text-base text-emerald-600"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Salary</p>
                                @if($job->salary)
                                    <p class="text-sm font-semibold text-emerald-700 mt-0.5">{{ $job->salary }}</p>
                                @else
                                    <p class="text-sm text-gray-400 italic mt-0.5">Not disclosed</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-xmark text-base text-amber-600"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Application Deadline</p>
                                <p class="text-sm font-bold mt-0.5 {{ $isUrgent ? 'text-red-600' : 'text-gray-800' }}">{{ $dl->format('F d, Y') }}</p>
                                <p class="text-xs mt-0.5 {{ $isUrgent ? 'text-red-500 font-semibold' : 'text-gray-400' }}">
                                    @if($daysLeft === 0) Closing today!
                                    @elseif($daysLeft === 1) Tomorrow is the last day
                                    @else {{ $daysLeft }} days remaining
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-plus text-base text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">Date Posted</p>
                                <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $createdPH->format('F d, Y') }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $createdPH->diffForHumans() }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Description --}}
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-base font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-file-lines text-sm text-gray-400"></i> Job Description
                    </h3>
                    <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">{{ $job->description }}</div>
                </div>

                {{-- Qualifications --}}
                @if($job->qualifications)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-base font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-sm" style="color:#7a3f91;"></i> Qualifications
                    </h3>
                    <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap bg-purple-50 rounded-xl p-4 border border-purple-100">{{ $job->qualifications }}</div>
                </div>
                @endif

                {{-- Application Instructions --}}
                @if($job->application_instructions)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-base font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane text-sm" style="color:#7a3f91;"></i> How to Apply
                    </h3>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                        <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $job->application_instructions }}</div>
                    </div>
                </div>
                @endif

                {{-- Target college --}}
                @if($job->target_college)
                <div class="px-6 py-5">
                    <h3 class="text-base font-bold text-gray-900 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-building-columns text-sm text-gray-400"></i> Open For
                    </h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $job->target_college) as $col)
                            <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1 rounded-lg border border-gray-200 bg-gray-100 text-gray-600">
                                <i class="fa-solid fa-check text-xs"></i> {{ trim($col) }}
                            </span>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex items-center justify-end gap-2">
                <button wire:click="openShareModal({{ $job->id }})" type="button"
                        class="px-4 py-2.5 bg-sky-100 text-sky-700 border border-sky-300 rounded-lg text-sm font-bold hover:bg-white hover:border-sky-500 transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs mr-1.5"></i> Share
                </button>
                <button wire:click="closeViewModal" type="button"
                        class="px-5 py-2.5 rounded-xl text-sm font-bold text-white transition flex items-center gap-2 cursor-pointer"
                        style="background-color:#7a3f91;">
                    <i class="fa-solid fa-xmark text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
    @endif


    {{-- ══ SHARE MODAL ════════════════════════════════════════════════════════ --}}
    @if($showShareModal)
    @php
        $shareBaseUrl     = $this->jobsBaseUrl();
        $shareDlFormatted = $shareDeadline
            ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
            : '';
        $shareDescPreview = mb_strlen($shareDescription) > 140
            ? mb_substr($shareDescription, 0, 140) . '…'
            : $shareDescription;

        $fbPostLines   = [];
        $fbPostLines[] = "🎯 Job Opening: {$shareJobTitle}";
        $fbPostLines[] = "🏢 {$shareCompany}";
        if ($shareLocation)    $fbPostLines[] = "📍 {$shareLocation}";
        if ($shareEmpType)     $fbPostLines[] = "💼 {$shareEmpType}";
        if ($shareExpLevel)    $fbPostLines[] = "📊 {$shareExpLevel}";
        if ($shareSalary)      $fbPostLines[] = "💰 {$shareSalary}";
        if ($shareDlFormatted) $fbPostLines[] = "📅 Deadline: {$shareDlFormatted}";
        if ($shareCollege)     $fbPostLines[] = "🏫 For: {$shareCollege}";
        $fbPostLines[] = '';
        $fbPostLines[] = "Apply now through the PHILCST Alumni Portal 👇";
        $fbPostLines[] = $shareBaseUrl;
        $fbPostText    = implode("\n", $fbPostLines);

        $fbShareUrl  = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);
        $shareHost   = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    @endphp

    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
         wire:keydown.escape="closeShareModal"
         x-data="{
             copied:false, fbCopied:false, messengerCopied:false,
             fbText:   {{ json_encode($fbPostText) }},
             baseUrl:  {{ json_encode($shareBaseUrl) }},
             fbUrl:    {{ json_encode($fbShareUrl) }},
             shareOnFacebook() {
                 navigator.clipboard.writeText(this.fbText).then(() => {
                     this.fbCopied = true; setTimeout(() => this.fbCopied = false, 6000);
                 }).catch(() => {});
                 const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
                 window.open(this.fbUrl,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             },
             shareOnMessenger() {
                 navigator.clipboard.writeText(this.fbText).then(() => {
                     this.messengerCopied = true; setTimeout(() => this.messengerCopied = false, 6000);
                 }).catch(() => {});
                 const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                 if (isMobile) {
                     window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                     setTimeout(() => window.open('https://www.messenger.com/','_blank'), 1500);
                 } else {
                     window.open('https://www.messenger.com/','_blank');
                 }
             },
             copyLinkFn() {
                 navigator.clipboard.writeText(this.baseUrl).then(() => {
                     this.copied = true; setTimeout(() => this.copied = false, 2500);
                 });
             }
         }"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">

        {{-- Modal — max-w-3xl for wide horizontal layout --}}
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="text-lg font-extrabold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-share-nodes text-sky-600"></i> Share Job Posting
                </h2>
                <button wire:click="closeShareModal" type="button"
                        class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition cursor-pointer">
                    <i class="fas fa-xmark text-base"></i>
                </button>
            </div>

            {{-- Two-column body --}}
            <div class="flex flex-col lg:flex-row">

                {{-- LEFT: Preview --}}
                <div class="flex-1 px-6 py-5 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col gap-4">

                    <p class="text-xs font-black text-gray-400 uppercase tracking-widest">What recipients will see</p>

                    {{-- Preview card --}}
                    <div class="rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm">
                        <div class="bg-[#f0f2f5] border-b border-gray-200 px-4 py-3 flex items-start gap-3">
                            <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#4c1d95] flex items-center justify-center flex-shrink-0 shadow">
                                <i class="fas fa-briefcase text-white text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-extrabold text-gray-900 text-sm leading-tight truncate">{{ $shareJobTitle }}</p>
                                <p class="text-xs text-gray-700 mt-0.5 font-semibold">
                                    {{ $shareCompany }}@if($shareEmpType) &middot; <span class="text-purple-700">{{ $shareEmpType }}</span>@endif
                                </p>
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @if($shareLocation)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-gray-700"><i class="fas fa-location-dot text-[8px]"></i>{{ $shareLocation }}</span>@endif
                                    @if($shareExpLevel)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700"><i class="fas fa-layer-group text-[8px]"></i>{{ $shareExpLevel }}</span>@endif
                                    @if($shareSalary)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700"><i class="fas fa-money-bill-wave text-[8px]"></i>{{ $shareSalary }}</span>@endif
                                    @if($shareDlFormatted)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-600"><i class="fas fa-calendar-xmark text-[8px]"></i>Deadline: {{ $shareDlFormatted }}</span>@endif
                                    @if($shareCollege)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700"><i class="fas fa-building-columns text-[8px]"></i>{{ $shareCollege }}</span>@endif
                                </div>
                            </div>
                        </div>
                        @if($shareDescPreview)
                        <div class="px-4 py-2.5 bg-white border-b border-gray-100">
                            <p class="text-xs text-gray-600 leading-relaxed line-clamp-3">{{ $shareDescPreview }}</p>
                        </div>
                        @endif
                        <div class="px-4 py-2 bg-[#f0f2f5] flex items-center gap-2">
                            <i class="fas fa-globe text-gray-400 text-[10px]"></i>
                            <span class="text-[10px] text-gray-500 uppercase tracking-wide font-semibold">{{ strtoupper($shareHost) }}</span>
                        </div>
                    </div>

                    {{-- Info box --}}
                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 flex items-start gap-2.5">
                        <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                        <p class="text-xs text-blue-800 leading-snug">
                            <strong>How it works:</strong> Click a share button — the full job text is automatically copied to your clipboard and the platform opens.
                            Just paste (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>) in your post or chat!
                        </p>
                    </div>
                </div>

                {{-- RIGHT: Share buttons --}}
                <div class="w-full lg:w-72 px-6 py-5 flex flex-col gap-3 flex-shrink-0">

                    <p class="text-xs font-black text-gray-400 uppercase tracking-widest">Share via</p>

                    {{-- Copied banners --}}
                    <div x-show="fbCopied" x-cloak
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="bg-emerald-50 border border-emerald-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                        <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-xs font-extrabold text-emerald-800">Text copied! Paste in Facebook popup.</p>
                        </div>
                    </div>

                    <div x-show="messengerCopied" x-cloak
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="bg-blue-50 border border-blue-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                        <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                        <p class="text-xs font-extrabold text-blue-800">Text copied! Paste in Messenger.</p>
                    </div>

                    {{-- Facebook --}}
                    <button type="button" @click="shareOnFacebook()"
                            class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-extrabold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                        <span class="w-9 h-9 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                                <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                            </svg>
                        </span>
                        <span class="flex-1 text-left text-sm">
                            <span x-show="!fbCopied">Share on Facebook</span>
                            <span x-show="fbCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste in popup!</span>
                        </span>
                        <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                    </button>

                    {{-- Messenger --}}
                    <button type="button" @click="shareOnMessenger()"
                            class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl bg-gradient-to-r from-[#00B2FF] to-[#006AFF] hover:from-[#00a0e6] hover:to-[#005ee6] text-white font-extrabold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                        <span class="w-9 h-9 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                                <defs><linearGradient id="mgr3" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                                <path fill="url(#mgr3)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                            </svg>
                        </span>
                        <span class="flex-1 text-left text-sm">
                            <span x-show="!messengerCopied">Share via Messenger</span>
                            <span x-show="messengerCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste in Messenger!</span>
                        </span>
                        <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                    </button>
                    <p class="text-[10px] text-gray-400 text-center -mt-1">
                        <i class="fas fa-users text-[9px] mr-0.5"></i> Works for private chats & group chats.
                    </p>

                    {{-- ── Batch Chat (new) ────────────────────────────────── --}}
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                        <div class="relative flex justify-center">
                            <span class="bg-white px-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest">or post directly</span>
                        </div>
                    </div>

                    <button type="button"
                            wire:click="shareToChat"
                            wire:loading.attr="disabled"
                            class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-extrabold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-purple-300 bg-gradient-to-r from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 text-purple-800">
                        <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                              style="background:#7a3f91;">
                            <i class="fas fa-users text-white text-sm"></i>
                        </span>
                        <span class="flex-1 text-left">
                            <span wire:loading.remove wire:target="shareToChat">Post to Batch Chat</span>
                            <span wire:loading wire:target="shareToChat"><i class="fas fa-spinner fa-spin mr-1"></i> Posting…</span>
                            <span class="block text-xs font-semibold text-purple-500 mt-0.5">Sends directly to your batchmates</span>
                        </span>
                        <i class="fas fa-paper-plane text-purple-400 text-xs group-hover:text-purple-700 transition"></i>
                    </button>

                    {{-- Divider then Copy Link --}}
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                        <div class="relative flex justify-center">
                            <span class="bg-white px-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest">or copy link</span>
                        </div>
                    </div>

                    <button type="button" @click="copyLinkFn()"
                            class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-bold text-sm transition cursor-pointer group">
                        <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                            <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-500'" class="text-sm"></i>
                        </span>
                        <div class="flex-1 text-left min-w-0">
                            <p :class="copied ? 'text-emerald-600' : 'text-gray-700'" class="font-bold text-sm"
                               x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                            <p class="text-[10px] text-gray-400 font-mono mt-0.5 truncate">{{ $shareBaseUrl }}</p>
                        </div>
                    </button>

                    <p class="text-[10px] text-gray-400 text-center leading-snug pt-1">
                        Sharing is disabled for postings past their deadline.
                    </p>
                </div>
            </div>
        </div>
    </div>
    @endif
    {{-- END SHARE MODAL --}}

</div>