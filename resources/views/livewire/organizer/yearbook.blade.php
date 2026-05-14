{{-- resources/views/livewire/organizer/yearbook.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $batch  = '';
    public string $course = '';

    protected string $paginationTheme = 'tailwind';

    public function mount(): void
    {
        if (!auth()->check() || !auth()->user()?->organizer) {
            abort(403, 'Access denied. Organizers only.');
        }
    }

    #[Computed]
    public function organizerDepartment(): string
    {
        return Auth::user()?->organizer?->department ?? '';
    }

    #[Computed]
    public function organizerBatchScope(): string
    {
        return Auth::user()?->organizer?->batch ?? '';
    }

    #[Computed]
    public function allowedCourseCodes(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        return DB::table('courses')
            ->where('college', $dept)
            ->pluck('code')
            ->toArray();
    }

    #[Computed(cache: true, seconds: 120)]
    public function courses()
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return collect();

        return Course::where('college', $dept)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed(cache: true, seconds: 120)]
    public function batches()
    {
        $codes = $this->allowedCourseCodes;
        $q = DB::table('alumni')
            ->whereNull('deleted_at')
            ->select('batch')
            ->distinct()
            ->orderByDesc('batch');

        if (!empty($codes)) {
            $q->whereIn('course_code', $codes);
        }
        if ($this->organizerBatchScope) {
            $q->where('batch', $this->organizerBatchScope);
        }

        return $q->pluck('batch')->filter()->values();
    }

    public function updatingBatch()  { $this->resetPage(); }
    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    #[Computed]
    public function alumniRecords()
    {
        $codes = $this->allowedCourseCodes;

        $q = Alumni::query()
            ->whereNull('deleted_at')
            ->select([
                'id', 'first_name', 'last_name', 'student_id',
                'email', 'course_code', 'batch', 'profile_photo',
            ]);

        if (!empty($codes)) {
            $q->whereIn('course_code', $codes);
        }

        if ($this->organizerBatchScope) {
            $q->where('batch', $this->organizerBatchScope);
        }

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name',  'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$s}%");
            });
        }

        if ($this->batch !== '') {
            $q->where('batch', $this->batch);
        }

        if ($this->course !== '') {
            $q->where('course_code', $this->course);
        }

        return $q->orderBy('course_code')->orderBy('last_name')->orderBy('first_name')->paginate(24);
    }

    #[Computed]
    public function totalAlumniInCollege(): int
    {
        $codes = $this->allowedCourseCodes;
        $q = DB::table('alumni')->whereNull('deleted_at');
        if (!empty($codes))              $q->whereIn('course_code', $codes);
        if ($this->organizerBatchScope)  $q->where('batch', $this->organizerBatchScope);
        return $q->count();
    }

    #[Computed]
    public function courseBreakdown(): array
    {
        $codes = $this->allowedCourseCodes;
        if (empty($codes)) return [];

        $q = DB::table('alumni as a')
            ->join('courses as c', 'a.course_code', '=', 'c.code')
            ->whereNull('a.deleted_at')
            ->whereIn('a.course_code', $codes)
            ->select('a.course_code', 'c.name as course_name', DB::raw('COUNT(*) as total'))
            ->groupBy('a.course_code', 'c.name')
            ->orderBy('a.course_code');

        if ($this->organizerBatchScope) {
            $q->where('a.batch', $this->organizerBatchScope);
        }

        return $q->get()->map(fn($r) => [
            'code'  => $r->course_code,
            'name'  => $r->course_name,
            'total' => $r->total,
        ])->toArray();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'batch', 'course']);
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

    public function formatAlumniName(?string $first, ?string $last): string
    {
        $first = trim($first ?? '');
        $last  = trim($last  ?? '');
        if (!$first && !$last) return '—';
        return strtoupper(trim("$first $last"));
    }

    public function formatAlumniNameShort(?string $first, ?string $last): string
    {
        $first = trim($first ?? '');
        $last  = trim($last  ?? '');
        if (!$first && !$last) return '—';
        $parts = explode(' ', $first);
        $initials = '';
        for ($i = 1; $i < count($parts); $i++) {
            if (!empty($parts[$i])) $initials .= ' ' . strtoupper($parts[$i][0]) . '.';
        }
        return strtoupper($parts[0] . $initials . ' ' . $last);
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = now('Asia/Manila')->hour;
        return match(true) {
            $hour < 12  => 'Good Morning',
            $hour < 18  => 'Good Afternoon',
            default     => 'Good Evening'
        };
    }
};
?>

<div class="flex flex-col overflow-hidden" style="background:#F4F0FA; height:95vh;">

<style>
:root {
    --yb-purple:      #7A3F91;
    --yb-purple-dark: #5c2f6e;
    --yb-purple-mid:  #9b59b6;
    --yb-purple-pale: #F9F7FC;
    --yb-border:      #E8E0F0;
    --yb-text:        #1f1a2e;
    --yb-sub:         #6b5b7c;
}

.yb-card {
    transition: border-color .15s ease, box-shadow .15s ease;
}
.yb-card:hover {
    border-color: #d4aaeb !important;
    box-shadow: 0 4px 14px rgba(122,63,145,.13);
}

@keyframes ybFadeUp {
    from { opacity:0; transform:translateY(8px); }
    to   { opacity:1; transform:translateY(0); }
}
.yb-grid-wrap { animation: ybFadeUp .22s cubic-bezier(.4,0,.2,1) both; }

.yb-scroll::-webkit-scrollbar       { width: 5px; }
.yb-scroll::-webkit-scrollbar-track { background:#ede8f5; border-radius:9999px; }
.yb-scroll::-webkit-scrollbar-thumb { background:#c0a0d8; border-radius:9999px; }
.yb-scroll::-webkit-scrollbar-thumb:hover { background:var(--yb-purple); }

.yb-loading-overlay {
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
}

.yb-chip {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:9999px;
    font-size:11px; font-weight:700; letter-spacing:.04em;
    background:rgba(122,63,145,.10);
    color:var(--yb-purple);
    border:1px solid rgba(122,63,145,.22);
    white-space:nowrap;
}

.yb-badge-0 { background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0; }
.yb-badge-1 { background:#EFF6FF; color:#1d4ed8; border-color:#bfdbfe; }
.yb-badge-2 { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
.yb-badge-3 { background:#fff7ed; color:#92400e; border-color:#fed7aa; }
.yb-badge-4 { background:#fdf4ff; color:#701a75; border-color:#f0abfc; }

.yb-input {
    padding: 7px 12px 7px 32px;
    border: 1.5px solid var(--yb-border);
    border-radius: 10px;
    font-size: 13px;
    background: #fff;
    color: var(--yb-text);
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.yb-input:focus {
    border-color: var(--yb-purple);
    box-shadow: 0 0 0 3px rgba(122,63,145,.10);
}
.yb-select {
    padding: 7px 30px 7px 12px;
    border: 1.5px solid var(--yb-border);
    border-radius: 10px;
    font-size: 13px;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%237A3F91' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E") no-repeat right 6px center / 1em;
    -webkit-appearance: none; appearance: none;
    color: var(--yb-text);
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.yb-select:focus {
    border-color: var(--yb-purple);
    box-shadow: 0 0 0 3px rgba(122,63,145,.10);
}

.yb-pg-btn {
    display:inline-flex; align-items:center; justify-content:center;
    height:30px; min-width:30px; padding:0 10px;
    border-radius:8px; font-size:12px; font-weight:700;
    transition:all .15s;
}
.yb-pg-active { background:#fff; color:var(--yb-purple); }
.yb-pg-nav    { background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.25); }
.yb-pg-nav:hover:not(:disabled) { background:rgba(255,255,255,.28); }
.yb-pg-nav:disabled { opacity:.35; cursor:not-allowed; }
</style>

<div class="flex flex-col flex-1 px-3 sm:px-5 lg:px-6 pt-4 max-w-screen-2xl mx-auto w-full overflow-hidden">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:#7A3F91;">
                <i class="fas fa-book-open text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold leading-tight" style="color:#1f1a2e;">
                    College Yearbook
                </h1>
                <p class="text-xs font-normal flex flex-wrap items-center gap-x-1.5" style="color:#6b5b7c;">
                    <span>{{ $this->organizerDepartment ?: 'Your College' }}</span>
                    @if($this->organizerBatchScope)
                        <span style="color:#c0a0d8;">·</span>
                        <span class="font-semibold" style="color:#7A3F91;">Batch {{ $this->organizerBatchScope }}</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Stat chips --}}
        <div class="flex flex-wrap items-center gap-2 mt-1">
            <span class="yb-chip">
                <i class="fas fa-graduation-cap text-[10px]"></i>
                {{ number_format($this->totalAlumniInCollege) }} Alumni
            </span>
            @foreach($this->courseBreakdown as $cb)
                <span class="yb-chip" style="background:rgba(122,63,145,.06);">
                    {{ $cb['code'] }} · {{ number_format($cb['total']) }}
                </span>
            @endforeach
        </div>
    </div>

    {{-- ═══ FILTER BAR ══════════════════════════════════════════════ --}}
    <div class="rounded-2xl border shadow-sm mb-3 flex-shrink-0 overflow-hidden"
         style="background:#fff; border-color:#E8E0F0;">
        <div class="px-4 py-2.5 flex flex-wrap items-center gap-2">

            <div class="flex items-center gap-1.5 shrink-0">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                     style="background:#7A3F91;">
                    <i class="fas fa-filter text-white" style="font-size:10px;"></i>
                </div>
                <span class="text-xs font-semibold uppercase tracking-wide" style="color:#7A3F91;">Filter</span>
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                   style="font-size:11px;"></i>
                <input type="text"
                       wire:model.live.debounce.350ms="search"
                       placeholder="Search name or student ID…"
                       class="yb-input w-full"
                       autocomplete="off" spellcheck="false">
            </div>

            @if(!$this->organizerBatchScope)
            <select wire:model.live="batch" class="yb-select min-w-[110px]">
                <option value="">All Batches</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b }}">{{ $b }}</option>
                @endforeach
            </select>
            @else
            <span class="text-xs font-semibold px-3 py-1.5 rounded-lg shrink-0"
                  style="background:#F9F7FC; color:#7A3F91; border:1.5px solid #E8E0F0;">
                <i class="fas fa-lock text-xs mr-1"></i> Batch {{ $this->organizerBatchScope }}
            </span>
            @endif

            <select wire:model.live="course" class="yb-select min-w-[180px] flex-1 sm:flex-none">
                <option value="">All Courses</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->code }}">{{ $c->name }}</option>
                @endforeach
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    class="flex items-center gap-1.5 px-3 py-[7px] rounded-xl text-xs font-semibold transition-all
                           border border-[#E8E0F0] bg-white hover:bg-[#f0e6f8] hover:border-[#d4aaeb]"
                    style="color:#7A3F91;">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            <div class="flex items-center gap-2 ml-auto">
                <span wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage">
                    <i class="fas fa-spinner animate-spin text-sm" style="color:#7A3F91;"></i>
                </span>
                <span class="text-xs font-bold px-2.5 py-1 rounded-full uppercase"
                      style="background:#F9F7FC; color:#7A3F91; border:1.5px solid #E8E0F0;">
                    {{ number_format($this->alumniRecords->total()) }} found
                </span>
            </div>
        </div>

        <div wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage"
             class="px-4 pb-2">
            <div class="h-0.5 rounded-full overflow-hidden" style="background:#f0e6f8;">
                <div class="h-full rounded-full animate-pulse"
                     style="background:#7A3F91; width:70%;"></div>
            </div>
        </div>
    </div>

    {{-- ═══ CARD PANEL ══════════════════════════════════════════════ --}}
    <div class="bg-white rounded-2xl border flex flex-col flex-1 min-h-0 overflow-hidden shadow-sm"
         style="border-color:#E8E0F0;">

        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">

            <div id="yb-organizer-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-scroll h-full overflow-y-auto overflow-x-hidden p-3 sm:p-4">

                {{-- Loading overlay --}}
                <div wire:loading
                     wire:target="search,batch,course,resetFilters,previousPage,nextPage"
                     class="yb-loading-overlay absolute inset-0 z-20 flex items-center justify-center"
                     style="background:rgba(255,255,255,.70);">
                    <div class="flex items-center gap-2.5 px-5 py-3 rounded-xl shadow-xl border"
                         style="background:#fff; border-color:#E8E0F0;">
                        <svg class="animate-spin w-4 h-4" style="color:#7A3F91;"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                    stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <span class="text-xs font-semibold" style="color:#7A3F91;">Loading alumni…</span>
                    </div>
                </div>

                {{-- ── Cards ── --}}
                @if($this->alumniRecords->count() > 0)
                @php
                    $grouped = collect($this->alumniRecords->items())
                        ->groupBy('course_code');
                @endphp
                <div class="yb-grid-wrap space-y-5">
                    @foreach($grouped as $courseCode => $members)
                    @php
                        $courseName = $this->courses->firstWhere('code', $courseCode)?->name ?? $courseCode;
                        $badgeClass = 'yb-badge-' . (crc32($courseCode) % 5);
                    @endphp

                    {{-- ── Section header (course name only, no code badge) ── --}}
                    <div>
                        <div class="flex items-center gap-2 mb-2.5">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border {{ $badgeClass }}">
                                <i class="fas fa-bookmark text-[10px]"></i>
                                {{ $courseName }}
                            </span>
                            <span class="text-xs font-semibold ml-auto shrink-0"
                                  style="color:#c0a0d8;">{{ $members->count() }} shown</span>
                        </div>

                        {{-- Card grid --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                            @foreach($members as $alumni)
                            <div wire:key="alum-{{ $alumni->id }}"
                                 class="yb-card relative bg-white rounded-2xl overflow-hidden border flex flex-col items-center
                                        shadow-sm cursor-default"
                                 style="border-color:#E8E0F0;">

                                {{-- Purple header strip --}}
                                <div class="w-full h-[88px] shrink-0 relative"
                                     style="background:#7A3F91;">
                                    <div class="absolute left-1/2 -translate-x-1/2 -bottom-[39px] z-10
                                                w-[78px] h-[78px]">
                                        <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                             alt="{{ $this->formatAlumniName($alumni->first_name, $alumni->last_name) }}"
                                             class="w-full h-full rounded-full object-cover block bg-[#f0e6f8]"
                                             style="border:3px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,.12);"
                                             loading="lazy" decoding="async"
                                             onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                    </div>
                                </div>

                                {{-- Card body --}}
                                <div class="w-full pt-[52px] pb-5 px-3.5 flex flex-col items-center text-center flex-1">

                                    {{-- Name --}}
                                    <p class="text-sm font-semibold leading-snug mb-2.5 break-words w-full uppercase"
                                       style="color:#333333;">
                                        {{ $this->formatAlumniNameShort($alumni->first_name, $alumni->last_name) }}
                                    </p>

                                    {{-- Class of badge --}}
                                    <span class="inline-flex items-center gap-1 px-2.5 py-[3px] rounded-full text-xs font-semibold border mb-2.5 {{ $badgeClass }}">
                                        <i class="fas fa-graduation-cap" style="font-size:9px;"></i>
                                        Class of {{ $alumni->batch ?? '—' }}
                                    </span>

                                    {{-- ✅ Course name below badge --}}
                                    <p class="text-xs font-semibold uppercase leading-snug"
                                       style="color:#333333; letter-spacing:0.02em;">
                                        {{ $courseName }}
                                    </p>

                                </div>

                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>

                @else
                {{-- Empty state --}}
                <div class="flex flex-col items-center justify-center py-24 text-center">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4"
                         style="background:#f0e6f8;">
                        <i class="fas fa-book-open text-3xl" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-sm font-semibold" style="color:#9b7db0;">No alumni found.</p>
                    <p class="text-xs mt-1 font-normal" style="color:#c0a0d8;">Try adjusting your search or filters.</p>
                    @if($search || $batch || $course)
                    <button wire:click="resetFilters"
                            class="mt-4 px-4 py-2 rounded-xl text-xs font-semibold transition-all
                                   border border-[#E8E0F0] hover:bg-[#f0e6f8]"
                            style="color:#7A3F91;">
                        <i class="fas fa-rotate-left text-xs mr-1"></i> Clear Filters
                    </button>
                    @endif
                </div>
                @endif

            </div>{{-- end scroll --}}

            {{-- Scroll-to-top button --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-organizer-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center
                           shadow-lg transition-all text-white"
                    style="background:#7A3F91;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>

        </div>{{-- end relative --}}

        {{-- ═══ PAGINATION FOOTER ══════════════════════════════════════ --}}
        <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:linear-gradient(135deg,#7A3F91,#5c2f6e);">
            @php
                $total = $this->alumniRecords->total();
                $pp    = $this->alumniRecords->perPage();
                $cp    = $this->alumniRecords->currentPage();
                $lp    = $this->alumniRecords->lastPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp

            <p class="text-white text-sm font-normal">
                Showing
                <strong class="font-bold">{{ $from }}–{{ $to }}</strong>
                of
                <strong class="font-bold">{{ number_format($total) }}</strong>
                alumni
            </p>

            @if($lp > 1)
            <div class="flex items-center gap-1.5">
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="yb-pg-btn yb-pg-nav">← Prev</button>
                @else
                    <button wire:click="previousPage" class="yb-pg-btn yb-pg-nav">← Prev</button>
                @endif

                @for($p = max(1, $cp - 2); $p <= min($lp, $cp + 2); $p++)
                    @if($p === $cp)
                        <span class="yb-pg-btn yb-pg-active font-bold">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }})" class="yb-pg-btn yb-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage" class="yb-pg-btn yb-pg-nav">Next →</button>
                @else
                    <button disabled class="yb-pg-btn yb-pg-nav">Next →</button>
                @endif

                <span class="text-white/60 text-xs ml-1 font-semibold">{{ $cp }}/{{ $lp }}</span>
            </div>
            @endif

        </div>

    </div>{{-- end card panel --}}

</div>{{-- end page wrapper --}}

</div>{{-- end root --}}