<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $course = '';

    // Logged-in alumni details — batch is locked, not a user-controlled filter
    public string $myBatch      = '';
    public string $myCourseCode = '';
    public string $myCourseName = '';

    protected string $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user && $user->role === 'alumni') {
            $alumni = \App\Models\Alumni::where('user_id', $user->id)->first();
            if ($alumni) {
                $this->myBatch      = (string) ($alumni->batch       ?? '');
                $this->myCourseCode = (string) ($alumni->course_code ?? '');
                $this->myCourseName = (string) ($alumni->course_name ?? '');
            }
        }
    }

    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    /**
     * Extract the last meaningful word from the course name to use as a
     * college-grouping keyword.
     * e.g. "Bachelor of Elementary Education" → "Education"
     *      "Bachelor of Science in Business Administration" → "Administration"
     */
    private function getCourseGroupKeyword(): string
    {
        if (empty($this->myCourseName)) return '';
        $words = array_filter(explode(' ', trim($this->myCourseName)));
        return (string) end($words);
    }

    #[Computed(cache: true, seconds: 120)]
    public function courses()
    {
        // Only show courses present in this batch
        return Course::whereIn('code',
                Alumni::where('batch', $this->myBatch)->distinct()->pluck('course_code')
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function alumniRecords()
    {
        $myCourse = $this->myCourseCode;
        $myBatch  = $this->myBatch;
        $keyword  = $this->getCourseGroupKeyword(); // e.g. "Education"

        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at']);

        // Always locked to the logged-in alumni's batch
        if ($myBatch !== '') {
            $q->where('batch', $myBatch);
        }

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('name',        'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email',      'like', "%{$s}%");
            });
        }

        if ($this->course) {
            $q->where('course_code', $this->course);
        }

        // 1st: my exact course
        // 2nd: same college group (courses sharing the same last keyword, e.g. "Education")
        // 3rd: everyone else alphabetically
        $q->orderByRaw(
                "CASE
                    WHEN course_code = ? THEN 0
                    WHEN ? != '' AND course_name LIKE ? THEN 1
                    ELSE 2
                 END",
                [$myCourse, $keyword, "%{$keyword}%"]
            )
          ->orderBy('course_code')
          ->orderBy('name');

        return $q->paginate(100);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'course']);
        $this->resetPage();
    }

    public function getPhotoUrl(?string $path): string
    {
        if (empty($path) || $path === 'null' || is_null($path)) {
            return asset('storage/alumni-photos/default.png');
        }
        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/')) {
            return asset('storage/' . $path);
        }
        if (!str_contains($path, '/')) {
            return asset('storage/alumni-photos/' . $path);
        }
        return asset('storage/' . $path);
    }

    public function formatAlumniName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));

        if (count($parts) == 1) return $parts[0];
        if (count($parts) == 2) return $parts[0] . ' ' . $parts[1];

        $firstName      = $parts[0];
        $lastName       = $parts[count($parts) - 1];
        $middleInitials = '';

        for ($i = 1; $i < count($parts) - 1; $i++) {
            $middleInitials .= strtoupper($parts[$i][0]) . '. ';
        }

        return trim($firstName . ' ' . $middleInitials . $lastName);
    }
};
?>

<div class="flex flex-col overflow-hidden bg-gray-100" style="height:95vh;">

<div class="flex flex-col flex-1 px-3 sm:px-5 lg:px-6 pt-5 max-w-screen-2xl mx-auto w-full overflow-hidden">

    {{-- PAGE HEADER — compact, matches Job Opportunities style --}}
    <div class="flex items-center gap-3 mb-4 flex-shrink-0">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-[#7A3F91]">
            <i class="fas fa-book text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-xl font-bold text-[#333333] leading-tight">Alumni Yearbook</h1>
            <p class="text-sm text-[#666666] font-normal">
                A digital collection of PhilCST graduates
                @if($myBatch !== '')
                    &mdash;
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#EDE0F5] text-[#7A3F91] text-xs font-semibold border border-[#d4aaeb]">
                        Batch {{ $myBatch }}
                    </span>
                @endif
            </p>
        </div>
    </div>

    {{-- FILTER BAR --}}
    <div class="rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden mb-4 flex-shrink-0 bg-gradient-to-br from-[#F9F7FC] to-white">
        <div class="px-5 py-3 flex flex-wrap gap-2 items-center">

            {{-- Filter label --}}
            <div class="flex items-center gap-2 mr-1">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 bg-[#7A3F91]">
                    <i class="fas fa-filter text-white" style="font-size:11px;"></i>
                </div>
                <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Filter</p>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[140px] max-w-xs">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search name, ID, email…"
                    class="w-full pl-8 pr-3 py-2 rounded-xl text-sm border-[1.5px] border-[#E8E0F0] bg-white text-[#333333] hover:border-[#d4aaeb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 placeholder-gray-400 transition-all"
                    autocomplete="off" spellcheck="false">
            </div>

            {{-- Course (only courses present in this batch) --}}
            <select wire:model.live="course"
                    class="flex-1 sm:flex-none px-3 py-2 rounded-xl text-sm min-w-[200px] border-[1.5px] border-[#E8E0F0] bg-white text-[#333333] hover:border-[#d4aaeb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition-all cursor-pointer">
                <option value="">All Courses</option>
                @forelse($this->courses as $c)
                    <option value="{{ $c->code }}" @selected($c->code === $course)>{{ $c->name }}</option>
                @empty
                    <option disabled>No courses</option>
                @endforelse
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="px-3 py-2 rounded-xl text-sm font-semibold flex items-center gap-1.5 bg-white text-[#7A3F91] border border-[#E8E0F0] hover:bg-[#f0e6f8] hover:border-[#d4aaeb] hover:shadow-sm transition-all disabled:pointer-events-none">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Total count --}}
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-white text-[#7A3F91] border border-[#E8E0F0] uppercase ml-auto">
                {{ number_format($this->alumniRecords->total()) }} alumni
            </span>

            {{-- Spinner --}}
            <span wire:loading wire:target="search,course,resetFilters,previousPage,nextPage">
                <i class="fas fa-spinner animate-spin text-sm text-[#7a3f91]"></i>
            </span>
        </div>

        {{-- Loading bar --}}
        <div wire:loading wire:target="search,course,resetFilters,previousPage,nextPage" class="px-5 pb-2">
            <div class="h-0.5 rounded-full overflow-hidden bg-[#f0e6f8]">
                <div class="h-full rounded-full animate-pulse bg-[#7A3F91]" style="width:65%;"></div>
            </div>
        </div>
    </div>

    {{-- CARD PANEL --}}
    <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm flex flex-col flex-1 min-h-0 overflow-hidden">

        {{-- Scrollable grid --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
            <div id="yb-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-hidden p-4 sm:p-5
                        [&::-webkit-scrollbar]:w-1.5
                        [&::-webkit-scrollbar-track]:bg-gray-100
                        [&::-webkit-scrollbar-track]:rounded-full
                        [&::-webkit-scrollbar-thumb]:bg-gray-300
                        [&::-webkit-scrollbar-thumb]:rounded-full
                        hover:[&::-webkit-scrollbar-thumb]:bg-[#d4aaeb]">

                {{-- Loading overlay --}}
                <div wire:loading
                     wire:target="search,course,resetFilters,previousPage,nextPage"
                     class="absolute inset-0 z-20 flex items-center justify-center backdrop-blur-sm bg-white/65">
                    <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                        <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <span class="text-xs font-semibold text-[#7A3F91]">Loading…</span>
                    </div>
                </div>

                {{-- Cards --}}
                @if($this->alumniRecords->count() > 0)
                    @php $prevCourse = null; @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 sm:gap-4">
                        @foreach($this->alumniRecords as $alumni)

                        {{-- Course divider label --}}
                        @if($alumni->course_code !== $prevCourse)
                            @php $prevCourse = $alumni->course_code; @endphp
                            <div class="col-span-full flex items-center gap-3 mt-2 mb-1">
                                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider
                                             {{ $alumni->course_code === $myCourseCode
                                                 ? 'bg-[#7A3F91] text-white shadow-md'
                                                 : 'bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]' }}">
                                    <i class="fas fa-graduation-cap text-xs"></i>
                                    {{ $alumni->course_code }}
                                </span>
                                <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                            </div>
                        @endif

                        <div wire:key="alumni-{{ $alumni->id }}"
                             class="bg-white rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex flex-col items-center hover:border-[#d4aaeb] hover:shadow-md transition-all duration-150">

                            {{-- Header strip --}}
                            <div class="w-full h-[88px] flex-shrink-0 relative bg-[#7A3F91]">
                                <div class="absolute left-1/2 -translate-x-1/2 -bottom-10 w-[78px] h-[78px] z-10">
                                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                         alt="{{ $alumni->name }}"
                                         class="w-full h-full rounded-full object-cover border-[3px] border-white shadow-[0_2px_10px_rgba(0,0,0,0.12)] bg-[#f0e6f8] block"
                                         loading="lazy"
                                         decoding="async"
                                         onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                </div>
                            </div>

                            {{-- Card body --}}
                            <div class="w-full pt-[52px] pb-5 px-3.5 flex flex-col items-center text-center flex-1">

                                <p class="text-sm font-semibold text-[#333333] leading-snug mb-2.5 break-words w-full uppercase">
                                    {{ $this->formatAlumniName($alumni->name) }}
                                </p>

                                <p class="text-xs font-semibold uppercase leading-snug text-[#333333] mb-3" style="letter-spacing:0.02em;">
                                    {{ $alumni->course_name }}
                                </p>

                                <span class="inline-flex items-center gap-1 px-2.5 py-[3px] bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] rounded-full text-xs font-semibold uppercase">
                                    <i class="fas fa-graduation-cap text-xs"></i>
                                    Class of {{ $alumni->batch }}
                                </span>

                            </div>
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-24">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3 bg-[#f0e6f8]">
                            <i class="fas fa-book text-3xl text-[#c89de0]"></i>
                        </div>
                        <p class="text-sm font-semibold text-[#999999]">No alumni found.</p>
                        <p class="text-xs text-[#CCCCCC] mt-1 font-normal">Try adjusting your filters.</p>
                    </div>
                @endif

            </div>

            {{-- Scroll-to-top --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg text-white bg-[#7A3F91] transition-all duration-[180ms]">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>
        </div>

        {{-- PAGINATION FOOTER --}}
        @php
            $total = $this->alumniRecords->total();
            $pp    = $this->alumniRecords->perPage();
            $cp    = $this->alumniRecords->currentPage();
            $lp    = $this->alumniRecords->lastPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);

            $pgWindow = 2;
            $pgStart  = max(1, $cp - $pgWindow);
            $pgEnd    = min($lp, $cp + $pgWindow);
        @endphp

        <div class="px-4 py-2.5 flex-shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#7A3F91]">

            <p class="text-white/70 text-sm">
                Showing <strong class="text-white font-semibold">{{ number_format($from) }}–{{ number_format($to) }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($total) }}</strong>
            </p>

            <div class="flex items-center gap-1.5 flex-wrap">

                {{-- Prev --}}
                @if($this->alumniRecords->onFirstPage())
                    <button disabled
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white/35 border-white/20 cursor-not-allowed">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                @else
                    <button wire:click="previousPage"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                @endif

                {{-- First page + ellipsis --}}
                @if($pgStart > 1)
                    <button wire:click="gotoPage(1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">
                        1
                    </button>
                    @if($pgStart > 2)
                        <span class="text-white/50 text-sm font-bold px-1">…</span>
                    @endif
                @endif

                {{-- Page numbers --}}
                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white text-[#7A3F91] border-white">
                            {{ $p }}
                        </span>
                    @else
                        <button wire:click="gotoPage({{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">
                            {{ $p }}
                        </button>
                    @endif
                @endfor

                {{-- Last page + ellipsis --}}
                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)
                        <span class="text-white/50 text-sm font-bold px-1">…</span>
                    @endif
                    <button wire:click="gotoPage({{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">
                        {{ $lp }}
                    </button>
                @endif

                {{-- Next --}}
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                @else
                    <button disabled
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold border-[1.5px] bg-white/15 text-white/35 border-white/20 cursor-not-allowed">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                @endif

                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">
                    Page {{ $cp }}/{{ $lp }}
                </span>

            </div>
        </div>

    </div>{{-- end card panel --}}

</div>{{-- end page content --}}

</div>{{-- end root --}}