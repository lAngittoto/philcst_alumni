<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $batch  = '';
    public string $course = '';

    protected string $paginationTheme = 'tailwind';

    public function updatingBatch()  { $this->resetPage(); }
    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    #[Computed(cache: true, seconds: 60)]
    public function courses()
    {
        return Course::orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed(cache: true, seconds: 60)]
    public function batches()
    {
        return Alumni::select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch')
            ->filter(fn($b) => !is_null($b))
            ->values();
    }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at']);

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('name', 'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($this->batch !== '') {
            $q->where('batch', $this->batch);
        }

        if ($this->course) {
            $q->where('course_code', $this->course);
        }

        return $q->orderByDesc('created_at')->paginate(200);
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batch  = '';
        $this->course = '';
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

<div class="flex flex-col overflow-hidden" style="background:#f3f4f6; height:95vh;">

    <div class="flex flex-col flex-1 px-3 sm:px-5 lg:px-6 pt-5 max-w-screen-2xl mx-auto w-full overflow-hidden">

        {{-- PAGE HEADER --}}
        <div class="flex items-center gap-3 mb-6 flex-shrink-0">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-book text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-3xl font-semibold text-[#333333] leading-tight">Alumni Yearbook</h1>
                <p class="text-xl text-[#666666] font-normal">A digital collection of PhilCST graduates</p>
            </div>
        </div>

        {{-- FILTER BAR --}}
        <div class="rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden mb-4 flex-shrink-0"
             style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
            <div class="px-5 py-3 flex flex-wrap gap-2 items-center">

                {{-- Filter label --}}
                <div class="flex items-center gap-2 mr-1">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-filter text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Filter Records</p>
                </div>

                {{-- Search --}}
                <div class="relative flex-1 min-w-[140px] max-w-xs"
                     x-data="{ q: @entangle('search').live, timer: null, onInput(e){ clearTimeout(this.timer); this.timer=setTimeout(()=>{ this.q=e.target.value; },200); } }"
                     wire:ignore>
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" :value="q" @input="onInput($event)"
                           placeholder="Search name, ID, email…"
                           class="w-full pl-8 pr-3 py-2 rounded-xl text-sm
                                  border-[1.5px] border-[#E8E0F0] bg-white text-[#333333]
                                  hover:border-[#d4aaeb]
                                  focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                                  placeholder-gray-400 transition-all"
                           autocomplete="off" spellcheck="false">
                </div>

                {{-- Batch --}}
                <select wire:model.live="batch"
                        class="flex-1 sm:flex-none px-3 py-2 rounded-xl text-sm min-w-[110px]
                               border-[1.5px] border-[#E8E0F0] bg-white text-[#333333]
                               hover:border-[#d4aaeb]
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                               transition-all cursor-pointer">
                    <option value="">All Batches</option>
                    @forelse($this->batches as $b)
                        <option value="{{ $b }}">{{ $b }}</option>
                    @empty
                        <option disabled>No batches</option>
                    @endforelse
                </select>

                {{-- Course — full name --}}
                <select wire:model.live="course"
                        class="flex-1 sm:flex-none px-3 py-2 rounded-xl text-sm min-w-[200px]
                               border-[1.5px] border-[#E8E0F0] bg-white text-[#333333]
                               hover:border-[#d4aaeb]
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                               transition-all cursor-pointer">
                    <option value="">All Courses</option>
                    @forelse($this->courses as $c)
                        <option value="{{ $c->code }}">{{ $c->name }}</option>
                    @empty
                        <option disabled>No courses</option>
                    @endforelse
                </select>

                {{-- Reset --}}
                <button wire:click="resetFilters"
                        class="px-3 py-2 rounded-xl text-sm font-semibold flex items-center gap-1.5
                               bg-white text-[#7A3F91] border border-[#E8E0F0]
                               hover:bg-[#f0e6f8] hover:border-[#d4aaeb] hover:shadow-sm
                               transition-all">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>

                {{-- Total count --}}
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-white text-[#7A3F91] border border-[#E8E0F0] uppercase ml-auto">
                    {{ number_format($this->alumniRecords->total()) }} alumni
                </span>

                {{-- Spinner --}}
                <span wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage">
                    <i class="fas fa-spinner animate-spin text-sm text-[#7a3f91]"></i>
                </span>
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
                            [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                            [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                            hover:[&::-webkit-scrollbar-thumb]:bg-[#d4aaeb]">

                    <div wire:loading.class="opacity-40 pointer-events-none transition-opacity duration-150"
                         wire:target="search,batch,course,resetFilters,previousPage,nextPage">

                        @if($this->alumniRecords->count() > 0)
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 sm:gap-4">
                                @foreach($this->alumniRecords as $index => $alumni)
                                <div class="bg-white rounded-2xl overflow-hidden border border-[#E8E0F0]
                                            shadow-sm flex flex-col items-center relative
                                            opacity-0 animate-[fadeInUp_0.28s_ease-out_both]"
                                     style="animation-delay: {{ min($index * 0.03, 0.45) }}s; animation-fill-mode: both;">

                                    {{-- Header strip --}}
                                    <div class="w-full h-[88px] flex-shrink-0 relative"
                                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                                        <div class="absolute left-1/2 -translate-x-1/2 -bottom-10 w-[78px] h-[78px] z-10">
                                            <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                                 alt="{{ $alumni->name }}"
                                                 class="w-full h-full rounded-full object-cover border-[3px] border-white
                                                        shadow-[0_2px_10px_rgba(0,0,0,0.12)] bg-[#f0e6f8] block"
                                                 loading="lazy"
                                                 decoding="async"
                                                 onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                        </div>
                                    </div>

                                    {{-- Card body --}}
                                    <div class="w-full pt-[52px] pb-5 px-3.5 flex flex-col items-center text-center flex-1">

                                        {{-- Name --}}
                                        <p class="text-sm font-semibold text-[#333333] leading-snug mb-2.5 break-words w-full uppercase">
                                            {{ $this->formatAlumniName($alumni->name) }}
                                        </p>

                                        {{-- Batch badge --}}
                                        <span class="inline-flex items-center gap-1 px-2.5 py-[3px] mb-3
                                                     bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]
                                                     rounded-full text-xs font-semibold uppercase">
                                            <i class="fas fa-graduation-cap text-xs"></i>
                                            Class of {{ $alumni->batch }}
                                        </span>

                                        {{-- Course full name — readable dark text --}}
                                        <p class="text-xs font-semibold uppercase leading-snug" style="color:#333333; letter-spacing:0.02em;">
                                            {{ $alumni->course_name }}
                                        </p>

                                    </div>

                                </div>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center py-24">
                                <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3"
                                     style="background:#f0e6f8;">
                                    <i class="fas fa-book text-3xl" style="color:#c89de0;"></i>
                                </div>
                                <p class="text-sm font-semibold text-[#999999]">No alumni found.</p>
                                <p class="text-xs text-[#CCCCCC] mt-1 font-normal">Try adjusting your filters.</p>
                            </div>
                        @endif

                    </div>
                </div>

                {{-- Scroll-to-top --}}
                <button x-show="showTop" x-cloak
                        @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg
                               text-white transition-all duration-[180ms]"
                        style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            {{-- PAGINATION FOOTER --}}
            <div class="px-5 py-3 flex-shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                @php
                    $total = $this->alumniRecords->total();
                    $pp    = $this->alumniRecords->perPage();
                    $cp    = $this->alumniRecords->currentPage();
                    $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                    $to    = min($cp * $pp, $total);
                @endphp

                <p class="text-white text-sm font-normal">
                    Showing <strong class="font-semibold">{{ $from }}–{{ $to }}</strong>
                    of <strong class="font-semibold">{{ $total }}</strong>
                </p>

                <div class="flex items-center gap-1.5">
                    @if($this->alumniRecords->onFirstPage())
                        <button disabled
                                class="px-3 py-1.5 bg-white/20 text-white/50 rounded-xl text-xs font-semibold cursor-not-allowed">
                            ← Prev
                        </button>
                    @else
                        <button wire:click="previousPage"
                                class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-white text-[#7A3F91] shadow
                                       hover:bg-[#f0e6f8] transition-all duration-[180ms]">
                            ← Prev
                        </button>
                    @endif

                    <span class="px-3 py-1.5 text-[#7A3F91] text-xs font-semibold bg-white rounded-xl">
                        {{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}
                    </span>

                    @if($this->alumniRecords->hasMorePages())
                        <button wire:click="nextPage"
                                class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-white text-[#7A3F91] shadow
                                       hover:bg-[#f0e6f8] transition-all duration-[180ms]">
                            Next →
                        </button>
                    @else
                        <button disabled
                                class="px-3 py-1.5 bg-white/20 text-white/50 rounded-xl text-xs font-semibold cursor-not-allowed">
                            Next →
                        </button>
                    @endif
                </div>
            </div>

        </div>{{-- end card panel --}}

    </div>{{-- end page content --}}

    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>

</div>{{-- end root --}}