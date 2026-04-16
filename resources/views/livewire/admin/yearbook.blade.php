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

        return $q->orderByDesc('created_at')->paginate(100);
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
        
        if (count($parts) == 1) {
            return $parts[0];
        }
        
        if (count($parts) == 2) {
            return $parts[0] . ' ' . $parts[1];
        }
        
        // For 3 or more names: First Name, Middle Initial(s), Last Name
        $firstName = $parts[0];
        $lastName = $parts[count($parts) - 1];
        
        // Get middle initials
        $middleInitials = '';
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $middleInitials .= strtoupper($parts[$i][0]) . '. ';
        }
        
        return trim($firstName . ' ' . $middleInitials . $lastName);
    }
};
?>

{{-- Root: full viewport height, flex column, no page-level overflow --}}
<div class="flex flex-col overflow-hidden" style="background:#f3f4f6; height:95vh;">

    {{-- ── PAGE CONTENT: flex column filling viewport ── --}}
    <div class="flex flex-col flex-1 px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto w-full overflow-hidden">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 flex-shrink-0
                    animate-[slideDown_0.35s_ease_both]"
             style="--tw-translate-y:0;">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-[#7a3f91]">
                    <i class="fas fa-book text-white text-base"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 leading-tight">Alumni Yearbook</h1>
                    <p class="text-gray-500 text-xs mt-0.5">A digital collection of PhilCST graduates</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-500 bg-white border border-gray-200 px-3 py-1.5 rounded-full shadow-sm">
                    {{ number_format($this->alumniRecords->total()) }} alumni
                </span>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-3 sm:px-5 py-3 mb-4 flex flex-wrap gap-2 items-center flex-shrink-0">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[140px] max-w-xs"
                 x-data="{ q: @entangle('search').live, timer: null, onInput(e){ clearTimeout(this.timer); this.timer=setTimeout(()=>{ this.q=e.target.value; },200); } }"
                 wire:ignore>
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" :value="q" @input="onInput($event)"
                       placeholder="Search name, ID, email…"
                       class="w-full pl-8 pr-3 py-2 rounded-lg text-xs sm:text-sm
                              border-[1.5px] border-gray-200 bg-white text-gray-900
                              hover:border-[#d4aaeb]
                              focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                              placeholder-gray-400 transition-all"
                       autocomplete="off" spellcheck="false">
            </div>

            {{-- Batch --}}
            <select wire:model.live="batch"
                    class="flex-1 sm:flex-none px-3 py-2 rounded-lg text-xs sm:text-sm min-w-[100px]
                           border-[1.5px] border-gray-200 bg-white text-gray-900
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

            {{-- Course --}}
            <select wire:model.live="course"
                    class="flex-1 sm:flex-none px-3 py-2 rounded-lg text-xs sm:text-sm min-w-[100px]
                           border-[1.5px] border-gray-200 bg-white text-gray-900
                           hover:border-[#d4aaeb]
                           focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10
                           transition-all cursor-pointer">
                <option value="">All Courses</option>
                @forelse($this->courses as $c)
                    <option value="{{ $c->code }}">{{ $c->code }}</option>
                @empty
                    <option disabled>No courses</option>
                @endforelse
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    class="px-3 py-2 rounded-lg text-xs font-semibold flex items-center gap-1.5
                           bg-white text-gray-700 border border-gray-200
                           hover:bg-gray-50 hover:border-gray-300 hover:shadow-sm
                           transition-all">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Spinner --}}
            <span wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage"
                  class="ml-auto">
                <i class="fas fa-spinner animate-spin text-xs text-[#7a3f91]"></i>
            </span>
        </div>

        {{-- Card panel: fills remaining height --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col flex-1 min-h-0 overflow-hidden">

            {{-- Scrollable card area --}}
            <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
                <div id="yb-scroll"
                     @scroll.passive="showTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-hidden p-3 sm:p-5
                            [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-track]:rounded-full
                            [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full
                            hover:[&::-webkit-scrollbar-thumb]:bg-[#d4aaeb]">

                    <div wire:loading.class="opacity-40 pointer-events-none transition-opacity duration-150"
                         wire:target="search,batch,course,resetFilters,previousPage,nextPage">

                        @if($this->alumniRecords->count() > 0)
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4 sm:gap-[1.125rem]">
                                @foreach($this->alumniRecords as $index => $alumni)
                                <div class="bg-white rounded-2xl overflow-hidden
                                            shadow-[0_1px_8px_rgba(0,0,0,0.07),0_0_0_1px_rgba(0,0,0,0.04)]
                                            flex flex-col items-center relative
                                            transition-all duration-[220ms] ease-out
                                            hover:-translate-y-1 hover:shadow-[0_8px_28px_rgba(122,63,145,0.18),0_0_0_1px_rgba(122,63,145,0.12)]
                                            opacity-0 animate-[fadeInUp_0.28s_ease-out_both]"
                                     style="animation-delay: {{ min($index * 0.03, 0.32) }}s; animation-fill-mode: both;">

                                    {{-- Purple header strip --}}
                                    <div class="w-full h-20 bg-[#7a3f91] flex-shrink-0 relative">
                                        {{-- Photo floated over strip --}}
                                        <div class="absolute left-1/2 -translate-x-1/2 -bottom-9 w-[72px] h-[72px] z-10">
                                            <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                                 alt="{{ $alumni->name }}"
                                                 class="w-full h-full rounded-full object-cover border-[3px] border-white
                                                        shadow-[0_2px_10px_rgba(0,0,0,0.15)] bg-[#c4b5d4] block"
                                                 loading="lazy"
                                                 decoding="async"
                                                 onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                        </div>
                                    </div>

                                    {{-- Card body --}}
                                    <div class="w-full pt-[46px] pb-[18px] px-3.5 flex flex-col items-center text-center flex-1">

                                        {{-- Name (Formatted) --}}
                                        <p class="text-[0.9375rem] font-bold text-gray-900 leading-[1.35] mb-[7px] break-words w-full">
                                            {{ $this->formatAlumniName($alumni->name) }}
                                        </p>

                                        {{-- Batch badge --}}
                                        <span class="inline-flex items-center gap-1 px-2.5 py-[3px] mb-[7px]
                                                     bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb]
                                                     rounded-full text-xs font-bold">
                                            <i class="fas fa-graduation-cap text-xs"></i>
                                            Class of {{ $alumni->batch }}
                                        </span>

                                        {{-- Course --}}
                                        <p class="text-xs text-gray-500 font-semibold leading-[1.4] mb-2.5">
                                            {{ $alumni->course_name }}
                                        </p>

                                    </div>

                                </div>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center py-24">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-book text-2xl text-gray-300"></i>
                                </div>
                                <p class="font-semibold text-gray-400 text-base">No alumni found</p>
                                <p class="text-sm text-gray-400 mt-1">Try adjusting your filters</p>
                            </div>
                        @endif

                    </div>
                </div>

                {{-- Scroll-to-top button --}}
                <button x-show="showTop" x-cloak
                        @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center shadow-lg
                               bg-[#7a3f91] text-white
                               hover:bg-[#5e2f72] hover:shadow-[0_4px_14px_rgba(122,63,145,0.35)] hover:-translate-y-px
                               transition-all duration-[180ms]">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            {{-- Pagination footer — always visible at bottom --}}
            <div class="px-3 sm:px-5 py-3 border-t border-gray-200 flex-shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                 style="background:#2b0d3e;">
                @php
                    $total = $this->alumniRecords->total();
                    $pp    = $this->alumniRecords->perPage();
                    $cp    = $this->alumniRecords->currentPage();
                    $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                    $to    = min($cp * $pp, $total);
                @endphp
                <p class="text-white text-xs sm:text-sm">
                    Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $total }}</strong>
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->alumniRecords->onFirstPage())
                        <button disabled
                                class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">
                            ← Prev
                        </button>
                    @else
                        <button wire:click="previousPage"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white
                                       bg-[#7a3f91] shadow-[0_2px_6px_rgba(122,63,145,0.25)]
                                       hover:bg-[#5e2f72] hover:shadow-[0_4px_14px_rgba(122,63,145,0.35)] hover:-translate-y-px
                                       transition-all duration-[180ms]">
                            ← Prev
                        </button>
                    @endif
                    <span class="px-3 py-1.5 text-gray-900 text-xs font-semibold bg-white rounded-lg">
                        {{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}
                    </span>
                    @if($this->alumniRecords->hasMorePages())
                        <button wire:click="nextPage"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white
                                       bg-[#7a3f91] shadow-[0_2px_6px_rgba(122,63,145,0.25)]
                                       hover:bg-[#5e2f72] hover:shadow-[0_4px_14px_rgba(122,63,145,0.35)] hover:-translate-y-px
                                       transition-all duration-[180ms]">
                            Next →
                        </button>
                    @else
                        <button disabled
                                class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">
                            Next →
                        </button>
                    @endif
                </div>
            </div>
            <style>
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
</style>

        </div>{{-- end card panel --}}

    </div>{{-- end page content --}}

</div>{{-- end root --}}