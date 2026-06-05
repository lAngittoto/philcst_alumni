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

    // Logged-in alumni details
    public string $myBatch          = '';
    public string $myCourseCode     = '';
    public string $myCourseName     = '';
    public string $myCollegeKeyword = '';
    public int    $myAlumniId       = 0;

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
                $this->myAlumniId   = (int)    ($alumni->id          ?? 0);

                // Extract the last word of the course name as the college keyword
                if ($this->myCourseName !== '') {
                    $words = array_filter(explode(' ', trim($this->myCourseName)));
                    $this->myCollegeKeyword = (string) end($words);
                }
            }
        }
    }

    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    /**
     * All courses visible (all colleges) — alumni can browse
     * any course but only see batchmates.
     */
    #[Computed(cache: true, seconds: 120)]
    public function courses()
    {
        return Course::orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Privacy rule: only alumni from the SAME BATCH as the logged-in user.
     * Within that batch, ALL courses are visible (no college restriction).
     * Optional: filter by specific course or search term.
     * Sorted: course name A-Z, then name A-Z.
     */
    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at']);

        // ── PRIVACY: locked to same batch only ──
        if ($this->myBatch !== '') {
            $q->where('batch', $this->myBatch);
        }

        // ── Optional search ──
        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('name',        'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email',      'like', "%{$s}%");
            });
        }

        // ── Optional course filter ──
        if ($this->course) {
            $q->where('course_code', $this->course);
        }

        // Sort: course name A-Z, then name A-Z
        $q->orderBy('course_name')
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

<div class="flex flex-col" style="height:90vh; overflow:hidden;">

<style>
/* ── Base ──────────────────────────────────────────────── */
.yb-card { transition: border-color .15s ease, box-shadow .15s ease; }
.yb-card:hover { border-color: #c49ed8 !important; box-shadow: 0 4px 14px rgba(122,63,145,.14); }

.yb-section-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 14px; border-radius: 9999px;
    font-size: 12px; font-weight: 700; letter-spacing: .02em;
    background: #F3E8FF; color: #7A3F91; border: 1.5px solid #D8B4FE;
}
.yb-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 12px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    background: rgba(122,63,145,.10); color: #7A3F91;
    border: 1px solid rgba(122,63,145,.22); white-space: nowrap;
}

/* ── Scrollbar ──────────────────────────────────────────── */
.yb-scroll::-webkit-scrollbar       { width: 5px; }
.yb-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Loading ────────────────────────────────────────────── */
.yb-loading-overlay { backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); }

/* ── Entry animation ────────────────────────────────────── */
@keyframes ybFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.yb-grid-wrap { animation: ybFadeUp .22s cubic-bezier(.4,0,.2,1) both; }

/* ── Search input ───────────────────────────────────────── */
.yb-search-input {
    padding: 0.5rem 0.75rem 0.5rem 2.25rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    transition: border-color .15s, box-shadow .15s;
    outline: none; width: 100%;
}
.yb-search-input::placeholder { color: #999999; font-weight: 400; }
.yb-search-input:hover  { border-color: #c4b5d4; }
.yb-search-input:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Dropdown button ────────────────────────────────────── */
.yb-dd-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0.5rem 2.25rem 0.5rem 0.75rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    cursor: pointer; white-space: nowrap;
    transition: border-color .15s, box-shadow .15s;
    outline: none; user-select: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat; background-size: 1.25em 1.25em;
}
.yb-dd-btn:hover  { border-color: #c4b5d4; }
.yb-dd-btn.active { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); color: #7a3f91; }

/* ── Dropdown panel ─────────────────────────────────────── */
.yb-dd-panel {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 224px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 600; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.yb-dd-panel::-webkit-scrollbar       { width: 4px; }
.yb-dd-panel::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 9999px; }
.yb-dd-item {
    display: block; width: 100%; padding: 6px 12px;
    border-radius: 7px; text-align: left;
    font-size: 12px; font-weight: 600; color: #333333;
    background: transparent; border: none; cursor: pointer;
    white-space: nowrap; transition: background .12s, color .12s;
}
.yb-dd-item:hover { background: #F5F0FA; color: #7A3F91; }
.yb-dd-item.sel   { background: #F0E6F8; color: #7A3F91; }

/* ── Main block ─────────────────────────────────────────── */
.yb-table-block {
    display: flex; flex-direction: column;
    border-radius: 1rem; overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
}
.yb-filter-bar {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem; flex-shrink: 0;
    position: relative; z-index: 50; overflow: visible;
}
.yb-pagination-bar {
    flex-shrink: 0;
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    padding: 0 1rem; min-height: 48px;
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.5rem;
    flex-wrap: wrap; border-top: 1px solid rgba(122,63,145,.3);
}
.yb-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px;
    border-radius: 8px; font-size: 12px; font-weight: 700; transition: all .15s;
}
.yb-pg-active { background: #fff; color: #7a3f91; }
.yb-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }
.yb-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.yb-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Batch watermark — removed (replaced by header banner) ── */

/* ── Big BATCH banner in header ─────────────────────────── */
.yb-batch-banner {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0;
    overflow: hidden;
    border-radius: 14px;
    border: 2px solid #7A3F91;
    box-shadow: 0 2px 16px rgba(122,63,145,.18), 0 0 0 4px rgba(122,63,145,.07);
    background: #fff;
    padding: 0;
    animation: ybFadeUp .3s cubic-bezier(.4,0,.2,1) both;
}
.yb-batch-banner-label {
    background: #7A3F91;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    padding: 0 10px;
    height: 38px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    flex-shrink: 0;
}
.yb-batch-banner-year {
    color: #7A3F91;
    font-size: clamp(1.15rem, 2.2vw, 1.55rem);
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 0 18px 0 14px;
    height: 38px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    background: #fff;
    line-height: 1;
}
.yb-batch-banner-year em {
    font-style: normal;
    color: #7A3F91;
    margin-left: 3px;
}

/* ── "My card" highlight — purple border + glow only ────── */
.yb-card-me {
    border-color: #7A3F91 !important;
    box-shadow: 0 0 0 3px rgba(122,63,145,.22), 0 6px 20px rgba(122,63,145,.18) !important;
}

/* Privacy notice pill */
.yb-privacy-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 11px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .03em;
    background: #FFF8E7; color: #92660A;
    border: 1.5px solid #F6D860;
    white-space: nowrap;
}
</style>

<div class="flex flex-col gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full" style="height:90vh; overflow:hidden;">

    {{-- PAGE HEADER --}}
    <div class="flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-book-open text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Alumni Yearbook</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">
                    A digital collection of PhilCST graduates
                    @if($myBatch !== '')
                        &mdash;
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold"
                              style="background:#EDE0F5; color:#7A3F91; border:1px solid #d4aaeb;">
                            Class of {{ $myBatch }}
                        </span>
                    @endif
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            {{-- Big readable BATCH XXXX banner --}}
            @if($myBatch !== '')
            <div class="yb-batch-banner" aria-label="Batch {{ $myBatch }}">
                <span class="yb-batch-banner-label">
                    <i class="fas fa-graduation-cap mr-1.5" style="font-size:9px;"></i>
                    Batch
                </span>
                <span class="yb-batch-banner-year">{{ $myBatch }}<em>.</em></span>
            </div>
            @endif
            {{-- Count chip --}}
            <span class="yb-chip">
                <i class="fas fa-graduation-cap text-[10px]"></i>
                {{ number_format($this->alumniRecords->total()) }} Alumni
            </span>
        </div>
    </div>

    {{-- UNIFIED BLOCK --}}
    <div class="yb-table-block">

        {{-- FILTER BAR --}}
        <div class="yb-filter-bar flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide"
                 style="color:#7a3f91;">
                Filters
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{ q: '', init() { this.q = $wire.search ?? ''; $wire.$watch('search', v => { if (v !== this.q) this.q = v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                   style="color:#555555; z-index:1;"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search name, ID, email…"
                       class="yb-search-input"
                       autocomplete="off" spellcheck="false">
            </div>

            {{-- Course dropdown — ALL courses, no college restriction --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button"
                        @click="open = !open"
                        :class="{ 'active': $wire.course !== '' }"
                        class="yb-dd-btn">
                    @if($course !== '')
                        <span>{{ $this->courses->firstWhere('code', $course)?->name ?? $course }}</span>
                    @else
                        <span>All Courses</span>
                    @endif
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="yb-dd-panel"
                     style="display:none; min-width:280px;">
                    <button type="button"
                            @click="$wire.set('course', ''); open = false"
                            :class="{ 'sel': $wire.course === '' }"
                            class="yb-dd-item">All Courses</button>
                    @foreach($this->courses as $c)
                    <button type="button"
                            @click="$wire.set('course', '{{ $c->code }}'); open = false"
                            :class="{ 'sel': $wire.course === '{{ $c->code }}' }"
                            class="yb-dd-item">{{ $c->name }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Reset --}}
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
                    <svg class="animate-spin w-3.5 h-3.5" style="color:#7A3F91;"
                         xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Count + spinner --}}
            <div class="flex items-center gap-2 ml-auto">
                <span wire:loading wire:target="search,course,resetFilters,previousPage,nextPage">
                    <svg class="animate-spin w-3.5 h-3.5" style="color:#7A3F91;"
                         xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="text-xs font-bold px-2.5 py-1 rounded-full uppercase"
                      style="background:#F9F7FC; color:#7A3F91; border:1.5px solid #E8E0F0;">
                    {{ number_format($this->alumniRecords->total()) }} found
                </span>
            </div>
        </div>

        {{-- SCROLLABLE CARDS AREA --}}
        <div class="flex-1 min-h-0 relative" style="background:#f3f4f6;"
             x-data="{ showTop: false }">

            <div id="yb-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-scroll absolute inset-0 overflow-y-auto overflow-x-hidden p-4"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,course,resetFilters,previousPage,nextPage">

                {{-- Loading overlay --}}
                <div wire:loading
                     wire:target="search,course,resetFilters,previousPage,nextPage"
                     class="yb-loading-overlay absolute inset-0 z-20 flex items-center justify-center"
                     style="background:rgba(243,244,246,.75);">
                    <div class="flex items-center gap-2.5 px-5 py-3 rounded-xl shadow-xl border bg-white"
                         style="border-color:#E8E0F0;">
                        <svg class="animate-spin w-4 h-4" style="color:#7A3F91;"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <span class="text-xs font-semibold" style="color:#7A3F91;">Loading alumni…</span>
                    </div>
                </div>

                {{-- ── Cards grouped by COURSE NAME (since batch is fixed) ── --}}
                @if($this->alumniRecords->count() > 0)
                    @php $prevCourse = null; @endphp
                    <div class="yb-grid-wrap space-y-2">
                        @foreach($this->alumniRecords as $alumni)

                            {{-- Course section divider --}}
                            @if($alumni->course_name !== $prevCourse)
                                @php $prevCourse = $alumni->course_name; @endphp

                                {{-- Close previous grid if not first --}}
                                @if(!$loop->first) </div> @endif

                                <div class="flex items-center gap-2 pt-2 pb-2 px-1">
                                    <span class="yb-section-badge">
                                        <i class="fas fa-book" style="font-size:10px;"></i>
                                        {{ $alumni->course_name }}
                                    </span>
                                    <div class="flex-1 h-px" style="background:#D8B4FE;"></div>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                            @endif

                            {{-- Alumni card --}}
                            @php $isMe = ($myAlumniId > 0 && $alumni->id === $myAlumniId); @endphp
                            <div wire:key="alumni-{{ $alumni->id }}"
                                 class="yb-card {{ $isMe ? 'yb-card-me' : '' }} bg-white rounded-2xl overflow-hidden border flex flex-col items-center shadow-sm cursor-default"
                                 style="border-color:#E8E0F0; position:relative;">

                                {{-- Purple header strip --}}
                                <div class="w-full h-[88px] shrink-0 relative"
                                     style="background:{{ $isMe ? 'linear-gradient(135deg,#6b2d85,#9b59b6)' : '#7A3F91' }};">
                                    <div class="absolute left-1/2 -translate-x-1/2 -bottom-[39px] z-10 w-[78px] h-[78px]">
                                        <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                             alt="{{ $alumni->name }}"
                                             class="w-full h-full rounded-full object-cover block"
                                             style="border:{{ $isMe ? '3px solid #7A3F91' : '3px solid #fff' }}; box-shadow:{{ $isMe ? '0 0 0 3px #fff, 0 0 0 5px #7A3F91, 0 3px 12px rgba(122,63,145,.3)' : '0 2px 10px rgba(0,0,0,.12)' }}; background:#f0e6f8;"
                                             loading="lazy" decoding="async"
                                             onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                    </div>
                                </div>

                                {{-- Card body --}}
                                <div class="w-full pt-[52px] pb-5 px-3.5 flex flex-col items-center text-center flex-1">
                                    <p class="text-sm font-semibold leading-snug mb-2 break-words w-full uppercase"
                                       style="color:#111111;">
                                        {{ $this->formatAlumniName($alumni->name) }}
                                    </p>
                                    <p class="text-xs font-semibold uppercase leading-snug mb-2.5"
                                       style="color:#555555; letter-spacing:0.02em;">
                                        {{ $alumni->course_name }}
                                    </p>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-[3px] rounded-full text-xs font-bold"
                                          style="background:#F3E8FF; color:#7A3F91; border:1.5px solid #D8B4FE;">
                                        <i class="fas fa-graduation-cap" style="font-size:9px;"></i>
                                        Class of {{ $alumni->batch }}
                                    </span>
                                </div>
                            </div>

                        @endforeach
                        </div>{{-- close last grid --}}
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-24 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100 mb-4">
                            <i class="fas fa-book text-xl text-gray-400"></i>
                        </div>
                        <p class="font-semibold text-base" style="color:#333333;">No alumni found.</p>
                        <p class="text-sm mt-1" style="color:#555555;">Try adjusting your filters.</p>
                        @if($search || $course)
                        <button wire:click="resetFilters"
                                class="mt-4 px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                                style="background-color:#7a3f91;">
                            <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                        </button>
                        @endif
                    </div>
                @endif

            </div>

            {{-- Scroll-to-top --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg transition-all text-white"
                    style="background:#7A3F91;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>

        </div>{{-- /scroll+watermark wrapper --}}

        {{-- PAGINATION --}}
        @php
            $total   = $this->alumniRecords->total();
            $pp      = $this->alumniRecords->perPage();
            $cp      = $this->alumniRecords->currentPage();
            $lp      = $this->alumniRecords->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="yb-pagination-bar">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ number_format($from) }}–{{ number_format($to) }}</strong>
                of <strong class="text-white font-bold">{{ number_format($total) }}</strong> alumni
                @if($search || $course)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            @if($lp > 1)
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="yb-pg-btn yb-pg-nav"
                        @if($this->alumniRecords->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="gotoPage(1)" class="yb-pg-btn yb-pg-nav">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="yb-pg-btn yb-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }})" class="yb-pg-btn yb-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $lp }})" class="yb-pg-btn yb-pg-nav">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="yb-pg-btn yb-pg-nav"
                        @if(!$this->alumniRecords->hasMorePages()) disabled @endif>
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
            @endif
        </div>

    </div>{{-- /yb-table-block --}}

</div>{{-- /main layout --}}

</div>{{-- /root --}}