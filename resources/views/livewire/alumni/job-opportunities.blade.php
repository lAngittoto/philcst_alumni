{{-- resources/views/livewire/alumni/job-opportunities.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Cache;

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

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)
            ->select(['id', 'first_name', 'course_code', 'course_name'])
            ->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniFirstName = $alumni->first_name ?? '';
        $this->alumniCourse    = $alumni->course_name ?? $alumni->course_code ?? '';

        $this->alumniCollege = Cache::remember(
            'alumni_college_' . $alumni->course_code,
            600,
            fn() => Course::where('code', $alumni->course_code)->value('college') ?? ''
        );
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
                'description', 'created_at',
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

}; ?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">
<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }

.job-card { transition: box-shadow .18s, transform .18s; cursor: pointer; }
.job-card:hover {
    box-shadow: 0 8px 28px rgba(122,63,145,.18), 0 2px 8px rgba(0,0,0,.07);
    transform: translateY(-3px);
}
.job-card:hover .card-view-hint {
    background-color: #6a3080 !important;
}

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

    <div class="space-y-5 flex-1 flex flex-col">

        {{-- ══ PAGE HEADER — purple bg ══════════════════════════════════════ --}}
        <div class="rounded-2xl overflow-hidden shadow-sm" style="background-color:#7a3f91;">
            <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-briefcase text-white text-sm"></i>
                        </div>
                        Job Opportunities
                    </h1>
                    <p class="text-sm text-white/75 mt-1 ml-11">
                        Showing jobs available for
                        <span class="font-semibold text-white">{{ $alumniCourse ?: 'your course' }}</span>
                        @if($alumniCollege)
                            · <span class="font-semibold text-white/90">{{ $alumniCollege }}</span>
                        @endif
                    </p>
                </div>
                <div class="ml-11 sm:ml-0">
                    <span class="inline-flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl
                                 bg-white/20 text-white border border-white/30">
                        <i class="fa-solid fa-circle-check text-emerald-300"></i>
                        {{ $this->jobPostings->total() }} active job{{ $this->jobPostings->total() !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- ══ FILTER BAR ════════════════════════════════════════════════════ --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-4 py-3 flex flex-wrap gap-2 items-center">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search title, company, location…"
                       class="filter-input w-full pl-8 pr-3 py-2 rounded-xl text-sm text-gray-900 bg-white"
                       autocomplete="off" maxlength="100">
            </div>

            {{-- Employment type --}}
            <select wire:model.live="filterType"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Types</option>
                <option value="Full-Time">Full-Time</option>
                <option value="Part-Time">Part-Time</option>
                <option value="Contract">Contract</option>
                <option value="Internship">Internship</option>
                <option value="Freelance">Freelance</option>
            </select>

            {{-- Experience level --}}
            <select wire:model.live="filterLevel"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Levels</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level (At Least 1 Year)</option>
                <option value="Mid Level (2-3 Years)">Mid Level (2-3 Years)</option>
                <option value="Senior Level (4-5 Years)">Senior Level (4-5 Years)</option>
                <option value="Expert Level (5+ Years)">Expert Level (5+ Years)</option>
            </select>

            {{-- Sort --}}
            <select wire:model.live="filterSort"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    class="filter-input px-3 py-2 rounded-xl bg-white text-sm font-medium text-gray-600
                           hover:bg-gray-50 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ══ CONTENT AREA ══════════════════════════════════════════════════ --}}
        <div class="flex flex-col flex-1"
             wire:loading.class="opacity-50 pointer-events-none"
             wire:target="search,filterType,filterLevel,filterSort,resetFilters,previousPage,nextPage">

            @if($this->jobPostings->count() > 0)

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 flex-1">
                    @foreach($this->jobPostings as $job)
                    @php
                        $dl          = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                        $today       = now('Asia/Manila')->startOfDay();
                        $daysLeft    = (int) $today->diffInDays($dl->copy()->startOfDay(), false);
                        $isUrgent    = $daysLeft <= 7;
                        $postedAgo   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                        $deadlineStr = $dl->format('M d, Y');             // e.g. "Apr 30, 2025"
                        $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
                    @endphp

                    <div class="job-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col"
                         wire:click="viewJob({{ $job->id }})">

                        <div class="p-4 flex flex-col flex-1 gap-3">

                            {{-- Company + badge --}}
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-gray-500 truncate">{{ $job->company_name }}</p>
                                    <h3 class="text-sm font-extrabold text-gray-900 leading-snug mt-0.5 line-clamp-2">
                                        {{ $job->job_title }}
                                    </h3>
                                </div>
                                <span class="inline-flex shrink-0 items-center text-[10px] font-bold px-2 py-0.5
                                             rounded-full border border-gray-200 bg-gray-100 text-gray-600 mt-0.5">
                                    {{ Str::limit($displayType, 14) }}
                                </span>
                            </div>

                            {{-- Meta pills --}}
                            <div class="flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1
                                             rounded-lg bg-blue-50 text-blue-700 border border-blue-100">
                                    <i class="fa-solid fa-clock text-[9px]"></i>
                                    {{ $job->employment_type }}
                                </span>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1
                                             rounded-lg bg-gray-100 text-gray-600 border border-gray-200">
                                    <i class="fa-solid fa-layer-group text-[9px]"></i>
                                    {{ Str::limit($job->experience_level, 22) }}
                                </span>
                            </div>

                            {{-- Location --}}
                            @if($job->location)
                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                <i class="fa-solid fa-location-dot text-gray-400 text-[10px]"></i>
                                <span class="truncate">{{ $job->location }}</span>
                            </div>
                            @endif

                            {{-- Salary --}}
                            @if($job->salary)
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                                <i class="fa-solid fa-money-bill-wave text-[10px]"></i>
                                <span class="truncate">{{ $job->salary }}</span>
                            </div>
                            @else
                            <div class="flex items-center gap-1.5 text-xs text-gray-400 italic">
                                <i class="fa-solid fa-money-bill-wave text-[10px]"></i>
                                Salary not disclosed
                            </div>
                            @endif

                            <div class="flex-1"></div>

                            {{-- Card Footer --}}
                            <div class="flex items-center justify-between pt-2 border-t border-gray-100">

                                {{-- Posted + deadline (left side) --}}
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-[11px] text-gray-400">{{ $postedAgo }}</span>

                                    {{-- Human-readable deadline --}}
                                    @if($isUrgent)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-red-600">
                                            <i class="fa-solid fa-fire text-[9px]"></i>
                                            Closes {{ $deadlineStr }}
                                            ({{ $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : 'in ' . $daysLeft . ' days') }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[11px] text-gray-400">
                                            <i class="fa-regular fa-calendar text-[9px]"></i>
                                            Closes {{ $deadlineStr }}
                                        </span>
                                    @endif
                                </div>

                                {{-- Click to view (right side) --}}
                                <span class="card-view-hint inline-flex items-center gap-1.5 text-xs font-bold
                                             px-3 py-1.5 rounded-lg text-white transition-colors"
                                      style="background-color:#7a3f91;">
                                    <i class="fa-solid fa-eye text-[10px]"></i> Click to View
                                </span>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- ══ PAGINATION ══════════════════════════════════════════════ --}}
                <div class="mt-4 px-4 sm:px-5 py-3 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                     style="background-color:#2b0d3e;">
                    @php
                        $total = $this->jobPostings->total();
                        $pp    = $this->jobPostings->perPage();
                        $cp    = $this->jobPostings->currentPage();
                        $from  = $total > 0 ? ($cp-1)*$pp+1 : 0;
                        $to    = min($cp*$pp, $total);
                    @endphp
                    <p class="text-white text-xs sm:text-sm">
                        Showing <span class="font-bold">{{ $from }}–{{ $to }}</span>
                        of <span class="font-bold">{{ $total }}</span> jobs
                    </p>
                    <div class="flex items-center gap-1.5">
                        @if($this->jobPostings->onFirstPage())
                            <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage" class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white"
                                    style="background-color:#7a3f91;">← Prev</button>
                        @endif
                        <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold">
                            {{ $cp }} / {{ $this->jobPostings->lastPage() }}
                        </span>
                        @if($this->jobPostings->hasMorePages())
                            <button wire:click="nextPage" class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white"
                                    style="background-color:#7a3f91;">Next →</button>
                        @else
                            <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>

            @else
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm py-20 flex flex-col
                            items-center gap-4 text-center px-6 flex-1">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fa-solid fa-briefcase text-2xl text-gray-400"></i>
                    </div>
                    <div>
                        <p class="font-bold text-gray-700 text-base">
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
                        <button wire:click="resetFilters"
                                class="px-4 py-2 rounded-xl text-sm font-bold text-white transition"
                                style="background-color:#7a3f91;">
                            <i class="fa-solid fa-rotate-left mr-1.5"></i> Clear Filters
                        </button>
                    @endif
                </div>
            @endif
        </div>

    </div>

    {{-- ══ VIEW DETAILS MODAL ════════════════════════════════════════════════ --}}
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

            {{-- Close X --}}
            <button wire:click="closeViewModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full
                           bg-white/25 hover:bg-white/40 transition text-white z-10">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- ── Modal Header — solid #7a3f91 ─────────────────────────── --}}
            <div class="px-6 pt-6 pb-5 flex-shrink-0 text-white"
                 style="background-color:#7a3f91;">

                <div class="flex items-center gap-2 mb-2 flex-wrap pr-8">
                    <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-building text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-white/75">{{ $job->company_name }}</p>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/20 text-white">
                            {{ $displayType }}
                        </span>
                    </div>
                </div>

                <h2 class="text-xl font-extrabold text-white leading-snug mb-3">{{ $job->job_title }}</h2>

                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-clock text-[9px]"></i> {{ $job->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-layer-group text-[9px]"></i> {{ $job->experience_level }}
                    </span>
                    @if($isUrgent)
                        <span class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-lg bg-red-500/80 text-white">
                            <i class="fa-solid fa-fire text-[9px]"></i>
                            {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft . ' days left') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- ── Modal Body ──────────────────────────────────────────────── --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">

                {{-- Key details --}}
                <div class="px-6 py-4 border-b border-gray-100">
                    <div class="grid grid-cols-2 gap-4">

                        <div class="flex items-start gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-location-dot text-sm text-gray-500"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Location</p>
                                <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $job->location ?? 'Not specified' }}</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-money-bill-wave text-sm text-emerald-600"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Salary</p>
                                @if($job->salary)
                                    <p class="text-sm font-semibold text-emerald-700 mt-0.5">{{ $job->salary }}</p>
                                @else
                                    <p class="text-sm text-gray-400 italic mt-0.5">Not disclosed</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-start gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-xmark text-sm text-amber-600"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Application Deadline</p>
                                <p class="text-sm font-bold mt-0.5 {{ $isUrgent ? 'text-red-600' : 'text-gray-800' }}">
                                    {{ $dl->format('F d, Y') }}
                                </p>
                                <p class="text-xs mt-0.5 {{ $isUrgent ? 'text-red-500 font-semibold' : 'text-gray-400' }}">
                                    @if($daysLeft === 0) Closing today!
                                    @elseif($daysLeft === 1) Tomorrow is the last day
                                    @else {{ $daysLeft }} days remaining
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-start gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-plus text-sm text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Date Posted</p>
                                <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $createdPH->format('F d, Y') }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $createdPH->diffForHumans() }}</p>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Job description --}}
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-file-lines text-xs text-gray-400"></i>
                        Job Description
                    </h3>
                    <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">{{ $job->description }}</div>
                </div>

                {{-- Target college --}}
                @if($job->target_college)
                <div class="px-6 py-4">
                    <h3 class="text-sm font-bold text-gray-900 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-building-columns text-xs text-gray-400"></i>
                        Open For
                    </h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $job->target_college) as $col)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1
                                         rounded-lg border border-gray-200 bg-gray-100 text-gray-600">
                                <i class="fa-solid fa-check text-[8px]"></i>
                                {{ trim($col) }}
                            </span>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>

            {{-- ── Modal Footer ─────────────────────────────────────────────── --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex items-center justify-end">
                <button wire:click="closeViewModal" type="button"
                        class="px-5 py-2 rounded-xl text-sm font-bold text-white transition flex items-center gap-2"
                        style="background-color:#7a3f91;">
                    <i class="fa-solid fa-xmark text-xs"></i> Close
                </button>
            </div>

        </div>
    </div>
    @endif

</div>