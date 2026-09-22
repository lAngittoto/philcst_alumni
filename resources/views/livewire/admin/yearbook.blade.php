{{-- resources/views/livewire/admin/yearbook.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public string $search = '';
    public string $batch  = '';
    public string $course = '';
    public int    $page   = 1;

    const PER_PAGE = 100;

    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }

    public function updatingBatch():  void { $this->page = 1; }
    public function updatingCourse(): void { $this->page = 1; }
    public function updatingSearch(): void { $this->page = 1; }

    #[Computed(cache: true, seconds: 120)]
    public function courses()
    {
        return Course::orderBy('code')->get(['id', 'code', 'name'])
            ->sortBy(function ($c) {
                $name = strtolower($c->name);
                if (str_contains($name, 'elementary')) return '0_' . $name;
                if (str_contains($name, 'secondary'))  return '1_' . $name;
                return '2_' . $name;
            })
            ->values();
    }

    #[Computed(cache: true, seconds: 120)]
    public function batches()
    {
        return DB::table('alumni')
            ->whereNull('deleted_at')
            ->select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch')
            ->filter()
            ->values();
    }

    private function courseSortKey(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'elementary')) return '0_' . $lower;
        if (str_contains($lower, 'secondary'))  return '1_' . $lower;
        return '2_' . $lower;
    }

    #[Computed]
    public function groupedAlumni()
    {
        $q = Alumni::query()
            ->whereNull('deleted_at')
            ->select([
                'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'email', 'course_code', 'batch', 'profile_photo',
                'date_of_birth',
                'address_street', 'address_barangay', 'address_municipality', 'address_province',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name', 'mother_given_name', 'mother_middle_name',
                'motto',
            ]);

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('first_name',      'like', "%{$s}%")
                    ->orWhere('middle_initial', 'like', "%{$s}%")
                    ->orWhere('last_name',      'like', "%{$s}%")
                    ->orWhere('student_id',     'like', "%{$s}%")
                    ->orWhere(DB::raw("CONCAT(first_name,' ',IFNULL(middle_initial,''),' ',last_name)"), 'like', "%{$s}%");
            });
        }

        if ($this->batch  !== '') $q->where('batch',       $this->batch);
        if ($this->course !== '') $q->where('course_code', $this->course);

        $all = $q->orderByDesc('batch')
                 ->orderBy('last_name')
                 ->orderBy('first_name')
                 ->get();

        $courseMap = $this->courses->keyBy('code');

        $grouped = $all->groupBy('course_code')
            ->map(function ($members, $code) use ($courseMap) {
                $name = $courseMap[$code]?->name ?? $code;
                return [
                    'courseCode' => $code,
                    'courseName' => $name,
                    'sortKey'    => $this->courseSortKey($name),
                    'members'    => $members
                        ->sortBy('first_name')
                        ->sortBy('last_name')
                        ->sortByDesc(function ($m) {
                            return is_numeric($m->batch) ? (int) $m->batch : -PHP_INT_MAX;
                        })
                        ->values(),
                ];
            })
            ->sortBy('sortKey')
            ->values();

        return $grouped;
    }

    #[Computed]
    public function totalFiltered(): int
    {
        return $this->groupedAlumni->sum(fn($g) => $g['members']->count());
    }

    #[Computed]
    public function totalAlumni(): int
    {
        return DB::table('alumni')->whereNull('deleted_at')->count();
    }

    #[Computed]
    public function flatRows(): array
    {
        $rows = [];
        foreach ($this->groupedAlumni as $group) {
            foreach ($group['members'] as $member) {
                $rows[] = ['group' => $group, 'member' => $member];
            }
        }
        return $rows;
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->totalFiltered / self::PER_PAGE));
    }

    #[Computed]
    public function currentPageGroups(): array
    {
        $offset = ($this->page - 1) * self::PER_PAGE;
        $slice  = array_slice($this->flatRows, $offset, self::PER_PAGE);

        $out = [];
        foreach ($slice as $row) {
            $code = $row['group']['courseCode'];
            if (!isset($out[$code])) {
                $out[$code] = $row['group'];
                $out[$code]['members'] = collect();
            }
            $out[$code]['members']->push($row['member']);
        }

        return array_values($out);
    }

    #[Computed]
    public function pageFrom(): int
    {
        if ($this->totalFiltered === 0) return 0;
        return (($this->page - 1) * self::PER_PAGE) + 1;
    }

    #[Computed]
    public function pageTo(): int
    {
        return min($this->page * self::PER_PAGE, $this->totalFiltered);
    }

    public function previousPage(): void
    {
        if ($this->page > 1) $this->page--;
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) $this->page++;
    }

    public function gotoPage(int $p): void
    {
        $this->page = max(1, min($p, $this->totalPages));
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batch  = '';
        $this->course = '';
        $this->page   = 1;
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

    public function formatAlumniName(
        ?string $first,
        ?string $middleInitial,
        ?string $last,
        ?string $suffix = null
    ): string {
        $first         = trim($first         ?? '');
        $middleInitial = trim($middleInitial ?? '');
        $last          = trim($last          ?? '');
        $suffix        = trim($suffix        ?? '');

        if (!$first && !$last) return '—';

        $mi   = $middleInitial !== '' ? ' ' . strtoupper($middleInitial[0]) . '.' : '';
        $name = strtoupper($first) . $mi . ' ' . strtoupper($last);
        if ($suffix !== '') $name .= ' ' . strtoupper($suffix);

        return trim($name);
    }

    public function highlight(string $text, string $search): string
    {
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="yb-adm-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
    }
};
?>

<div class="yb-adm-noselect flex flex-col gap-2 sm:gap-4 px-4 sm:px-7 lg:px-10 pt-3 sm:pt-6 pb-2 sm:pb-6 max-w-screen-2xl mx-auto w-full yb-adm-root-height"
     x-data="{
        setAvailHeight() {
            const rect = this.$el.getBoundingClientRect();
            const bottomSafe = 8;
            const avail = window.innerHeight - rect.top - bottomSafe;
            this.$el.style.setProperty('--yb-adm-avail-h', avail + 'px');
        }
     }"
     x-init="
        setAvailHeight();
        window.addEventListener('resize', () => setAvailHeight());
        window.addEventListener('orientationchange', () => setTimeout(() => setAvailHeight(), 150));
        Livewire.hook('morph.updated', () => setAvailHeight());
     "
     oncontextmenu="return false;"
     oncopy="return false;"
     onselectstart="return false;"
     oncut="return false;">

<style>
/* ── Disable text selection / copy ── */
.yb-adm-noselect,
.yb-adm-noselect * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}
.yb-adm-noselect input,
.yb-adm-noselect textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* ── Search highlight ── */
mark.yb-adm-hl {
    background: #BFDBFE;
    color: inherit;
    border-radius: 2px;
    padding: 0 1px;
    font-weight: 700;
}

/* ── Card base ── */
.yb-adm-card {
    transition: border-color .15s ease, box-shadow .15s ease;
    position: relative;
    width: 100%;
    background: #fff;
    display: flex;
    flex-direction: column;
    height: 420px;
    min-height: 420px;
    max-height: 420px;
    align-self: stretch;
    border-color: #E2D6F0;
}
.yb-adm-card:not(:hover) {
    box-shadow: 0 3px 12px rgba(90,26,138,.18);
}
.yb-adm-card:hover {
    border-color: #c49ed8 !important;
    box-shadow: 0 6px 22px rgba(0,0,0,.12);
}

/* ── Photo — top purple section ── */
.yb-adm-card-photo-wrap {
    width: 100%;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
    background: #7A3F91;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 22px 0 18px;
    min-height: 190px;
}
.yb-adm-card-photo {
    width: 130px;
    height: 130px;
    object-fit: cover;
    object-position: top center;
    display: block;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,.9);
    box-shadow: 0 4px 16px rgba(0,0,0,.3);
    flex-shrink: 0;
}

/* ── Right / body column ── */
.yb-adm-card-right {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Purple name ribbon ── */
.yb-adm-card-name-band {
    background: #7A3F91;
    padding: 8px 36px 8px 13px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}
.yb-adm-card-name-band::before {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 22px;
    width: 18px;
    background: rgba(255,255,255,.20);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-adm-card-name-band::after {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 9px;
    width: 8px;
    background: rgba(255,255,255,.10);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-adm-card-name {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF; line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: .01em;
    position: relative; z-index: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ── Card info body ── */
.yb-adm-card-text {
    padding: 10px 13px 12px;
    flex: 1;
    display: flex; flex-direction: column; gap: 3px;
    background: #fff;
    overflow: hidden;
}
.yb-adm-card-line {
    font-size: 14px; color: #1a1a1a; line-height: 1.4; font-weight: 600;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.yb-adm-card-motto {
    font-size: 13px; font-weight: 700;
    color: #5A1A8A; margin-top: 4px;
}
.yb-adm-card-motto-text {
    font-size: 13px; font-style: italic; font-weight: 600;
    color: #1a1a1a; line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ── Badges ── */
.yb-adm-section-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 14px; border-radius: 9999px;
    font-size: 12px; font-weight: 700; letter-spacing: .02em;
    background: #F3E8FF; color: #7A3F91; border: 1.5px solid #D8B4FE;
    white-space: normal; line-height: 1.3; max-width: 100%;
}
.yb-adm-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 12px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    background: rgba(122,63,145,.10); color: #7A3F91;
    border: 1px solid rgba(122,63,145,.22); white-space: nowrap;
}

/* ── Scrollbar ── */
.yb-adm-scroll::-webkit-scrollbar       { width: 5px; }
.yb-adm-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.yb-adm-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.yb-adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Entry animation ── */
@keyframes ybAdmFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.yb-adm-grid-wrap { animation: ybAdmFadeUp .22s cubic-bezier(.4,0,.2,1) both; }

/* ── Search input ── */
.yb-adm-search-input {
    padding: 0.5rem 0.75rem 0.5rem 2.25rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    transition: border-color .15s, box-shadow .15s;
    outline: none; width: 100%;
}
.yb-adm-search-input::placeholder { color: #999999; font-weight: 400; }
.yb-adm-search-input:hover  { border-color: #c4b5d4; }
.yb-adm-search-input:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Dropdown trigger ── */
.yb-adm-dd-btn {
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
.yb-adm-dd-btn:hover  { border-color: #c4b5d4; }
.yb-adm-dd-btn.active { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); color: #7a3f91; }
.yb-adm-dd-btn:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
.yb-adm-dd-btn:disabled { pointer-events: none !important; cursor: default !important; opacity: 0.5; }

/* ── Dropdown panel ── */
.yb-adm-dd-panel {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 224px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 600; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.yb-adm-dd-panel::-webkit-scrollbar       { width: 4px; }
.yb-adm-dd-panel::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 9999px; }
.yb-adm-dd-item {
    display: block; width: 100%; padding: 6px 12px;
    border-radius: 7px; text-align: left;
    font-size: 12px; font-weight: 600; color: #333333;
    background: transparent; border: none; cursor: pointer;
    white-space: nowrap; transition: background .12s, color .12s;
}
.yb-adm-dd-item:hover { background: #F5F0FA; color: #7A3F91; }
.yb-adm-dd-item.sel   { background: #F0E6F8; color: #7A3F91; }

/* ── Filter bar keeps controls interactive during Livewire loading ── */
.yb-adm-filter-bar *,
.yb-adm-dd-btn,
.yb-adm-dd-panel,
.yb-adm-dd-item,
.yb-adm-search-input {
    pointer-events: all !important;
}
.yb-adm-dd-btn       { cursor: pointer !important; }
.yb-adm-dd-item      { cursor: pointer !important; }
.yb-adm-search-input { cursor: text !important; }
.yb-adm-dd-btn:disabled { pointer-events: none !important; cursor: default !important; }

/* ── Main block ── */
.yb-adm-table-block {
    display: flex; flex-direction: column;
    border-radius: 1rem; overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
}
.yb-adm-filter-bar {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem; flex-shrink: 0;
    position: relative; z-index: 50; overflow: visible;
    pointer-events: all !important;
    cursor: default !important;
}
.yb-adm-pagination-bar {
    flex-shrink: 0;
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    padding: 0 1rem; min-height: 48px;
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.5rem;
    flex-wrap: wrap; border-top: 1px solid rgba(122,63,145,.3);
    position: sticky; bottom: 0; z-index: 30;
}
.yb-adm-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px;
    border-radius: 8px; font-size: 12px; font-weight: 700; transition: all .15s;
}
.yb-adm-pg-active { background: #fff; color: #7a3f91; }
.yb-adm-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }
.yb-adm-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.yb-adm-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Root height ── */
.yb-adm-root-height {
    height: calc(100vh - 180px);
    max-height: calc(100vh - 180px);
    overflow: hidden;
    height: var(--yb-adm-avail-h, calc(100vh - 180px));
    max-height: var(--yb-adm-avail-h, calc(100vh - 180px));
}

/* ── Mobile ── */
@media (max-width: 640px) {
    .yb-adm-filter-bar { gap: 8px; }
}
@media (max-width: 767px) {
    html, body { overflow: hidden !important; }
    .yb-adm-root-height {
        height: var(--yb-adm-avail-h, 100dvh) !important;
        max-height: var(--yb-adm-avail-h, 100dvh) !important;
        overflow: hidden !important;
    }
    .yb-adm-mobile-subtitle { display: none; }
    .yb-adm-mobile-header-icon { width: 2.25rem !important; height: 2.25rem !important; }
    .yb-adm-mobile-title { font-size: 1rem !important; }
    .yb-adm-filter-bar { padding: 0.45rem 0.65rem; }
    .yb-adm-dd-btn, .yb-adm-search-input { padding-top: 0.4rem; padding-bottom: 0.4rem; }
    .yb-adm-pagination-bar { min-height: 40px; padding: 6px 0.75rem; }
    .yb-adm-pagination-bar p { font-size: 11px; }
    .yb-adm-pagination-bar { padding-bottom: calc(0.4rem + env(safe-area-inset-bottom, 0px)); }
}

[x-cloak] { display: none !important; }
</style>

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col gap-3 flex-shrink-0">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-4">
                <div class="yb-adm-mobile-header-icon w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                     style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                    <i class="fas fa-book-open text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="yb-adm-mobile-title text-2xl font-semibold tracking-tight leading-tight" style="color:#333333;">Alumni Yearbook</h1>
                    <p class="yb-adm-mobile-subtitle text-sm leading-relaxed mt-0.5 font-semibold" style="color:#7A3F91;">All Colleges &amp; Courses</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="yb-adm-chip">
                    <i class="fas fa-graduation-cap text-[10px]"></i>
                    {{ number_format($this->totalAlumni) }} Alumni
                </span>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED BLOCK — filter + cards + pagination ══ --}}
    <div class="yb-adm-table-block">

        {{-- ── FILTER BAR ── --}}
        <div class="yb-adm-filter-bar flex flex-wrap gap-2 items-center"
             x-data="{
                openDd: '',
                init() {
                    document.addEventListener('livewire:request', () => { this.openDd = ''; });
                }
             }">

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
                       placeholder="Search..."
                       class="yb-adm-search-input"
                       autocomplete="off" spellcheck="false">
            </div>

            {{-- Batch dropdown ── --}}
            <div class="relative" @click.outside="if(openDd==='batch') openDd=''">
                <button type="button"
                        @click="openDd = openDd === 'batch' ? '' : 'batch'"
                        :class="{ 'active': $wire.batch !== '' }"
                        :style="openDd === 'course' ? 'pointer-events:none;cursor:default;opacity:.5;' : ''"
                        wire:loading.attr="disabled"
                        wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage"
                        class="yb-adm-dd-btn">
                    <span x-text="$wire.batch !== '' ? 'Batch ' + $wire.batch : 'All Batches'"></span>
                </button>
                <div x-show="openDd === 'batch'"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="yb-adm-dd-panel"
                     style="display:none;">
                    <button type="button"
                            @click="$wire.set('batch', ''); openDd = ''"
                            :class="{ 'sel': $wire.batch === '' }"
                            class="yb-adm-dd-item">All Batches</button>
                    @foreach($this->batches as $b)
                    <button type="button"
                            @click="$wire.set('batch', '{{ $b }}'); openDd = ''"
                            :class="{ 'sel': $wire.batch === '{{ $b }}' }"
                            class="yb-adm-dd-item">{{ $b }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Course dropdown ── --}}
            <div class="relative" @click.outside="if(openDd==='course') openDd=''">
                <button type="button"
                        @click="openDd = openDd === 'course' ? '' : 'course'"
                        :class="{ 'active': $wire.course !== '' }"
                        :style="openDd === 'batch' ? 'pointer-events:none;cursor:default;opacity:.5;' : ''"
                        wire:loading.attr="disabled"
                        wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage"
                        class="yb-adm-dd-btn">
                    @if($course !== '')
                        <span>{{ $this->courses->firstWhere('code', $course)?->name ?? $course }}</span>
                    @else
                        <span>All Programs</span>
                    @endif
                </button>
                <div x-show="openDd === 'course'"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="yb-adm-dd-panel"
                     style="display:none; min-width:220px;">
                    <button type="button"
                            @click="$wire.set('course', ''); openDd = ''"
                            :class="{ 'sel': $wire.course === '' }"
                            class="yb-adm-dd-item">All Programs</button>
                    @foreach($this->courses as $c)
                    <button type="button"
                            @click="$wire.set('course', '{{ $c->code }}'); openDd = ''"
                            :class="{ 'sel': $wire.course === '{{ $c->code }}' }"
                            class="yb-adm-dd-item">{{ $c->name }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Found count ── --}}
            <div class="flex items-center gap-2 ml-auto">
                <span class="text-xs font-bold px-2.5 py-1 rounded-full uppercase"
                      style="background:#F9F7FC; color:#7A3F91; border:1.5px solid #E8E0F0;">
                    {{ number_format($this->totalFiltered) }} found
                </span>
            </div>

            {{-- Reset ── --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    @if($search === '' && $batch === '' && $course === '') disabled @endif
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none disabled:opacity-40 cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7A3F91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ── SCROLLABLE CARDS AREA ── --}}
        <div class="flex-1 min-h-0 relative" style="background:#f3f4f6;"
             x-data="{ showTop: false }">

            {{-- Loading overlay ── --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 style="cursor:default;"
                 wire:loading.flex wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            <div id="yb-admin-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-adm-scroll absolute inset-0 overflow-y-auto overflow-x-hidden p-3 sm:p-4"
                 wire:loading.class="opacity-50"
                 wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage">

                @if($this->totalFiltered > 0)
                <div class="yb-adm-grid-wrap space-y-2"
                     wire:key="results-{{ md5($search . '|' . $batch . '|' . $course . '|' . $page) }}">
                    @foreach($this->currentPageGroups as $group)
                    <div wire:key="group-{{ $group['courseCode'] }}">

                        {{-- Section header --}}
                        <div class="flex items-center flex-wrap gap-2 pt-2 pb-2 px-1">
                            <span class="yb-adm-section-badge">
                                <i class="fas fa-bookmark" style="font-size:10px;"></i>
                                {{ $group['courseName'] }}
                            </span>
                            <div class="flex-1 min-w-[24px] h-px" style="background:#D8B4FE;"></div>
                            <span class="text-xs font-semibold shrink-0" style="color:#c0a0d8;">
                                {{ $group['members']->count() }} shown
                            </span>
                        </div>

                        {{-- Card grid ── capped at 5 cols, equal-height rows --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 items-stretch">
                            @foreach($group['members'] as $alumni)
                            @php
                                $cardName = $this->formatAlumniName(
                                    $alumni->first_name,
                                    $alumni->middle_initial ?? null,
                                    $alumni->last_name,
                                    $alumni->suffix ?? null
                                );

                                // Birthday
                                $cardDob = !empty($alumni->date_of_birth)
                                    ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('F j, Y')
                                    : null;

                                // Address
                                $cardAddr = implode(', ', array_filter([
                                    $alumni->address_street       ?? '',
                                    $alumni->address_barangay     ?? '',
                                    $alumni->address_municipality ?? '',
                                    $alumni->address_province     ?? '',
                                ]));

                                // Parents
                                $fLast  = trim($alumni->father_last_name   ?? '');
                                $fFirst = trim($alumni->father_given_name  ?? '');
                                $fMid   = trim($alumni->father_middle_name ?? '');
                                $mFirst = trim($alumni->mother_given_name  ?? '');
                                $mLast  = trim($alumni->mother_last_name   ?? '');

                                if ($fFirst && $fLast) {
                                    $fMidI      = $fMid ? strtoupper(mb_substr($fMid,0,1)).'.' : '';
                                    $cardParents = ($mFirst ? 'Mr. & Mrs. ' : 'Mr. ') . $fFirst . ($fMidI ? ' '.$fMidI : '') . ' ' . $fLast;
                                } elseif ($mFirst && $mLast) {
                                    $cardParents = 'Mrs. ' . $mFirst . ' ' . $mLast;
                                } else {
                                    $cardParents = null;
                                }
                            @endphp
                            <div wire:key="adm-alum-{{ $alumni->id }}"
                                 class="yb-adm-card rounded-xl overflow-hidden border cursor-default"
                                 style="border-color:#E2D6F0;">

                                {{-- Portrait photo — top purple section --}}
                                <div class="yb-adm-card-photo-wrap">
                                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                         alt="{{ $cardName }}"
                                         class="yb-adm-card-photo"
                                         loading="lazy" decoding="async"
                                         onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                </div>

                                {{-- Name ribbon + info body --}}
                                <div class="yb-adm-card-right">

                                    {{-- Purple name ribbon --}}
                                    <div class="yb-adm-card-name-band">
                                        <p class="yb-adm-card-name">{!! $this->highlight($cardName, $search) !!}</p>
                                    </div>

                                    {{-- Info body (white) — plain text, no icons --}}
                                    <div class="yb-adm-card-text">

                                        @if($cardDob)
                                        <p class="yb-adm-card-line">{{ $cardDob }}</p>
                                        @endif

                                        @if($cardAddr)
                                        <p class="yb-adm-card-line">{{ ucwords(mb_strtolower($cardAddr)) }}</p>
                                        @endif

                                        @if($cardParents)
                                        <p class="yb-adm-card-line">{{ ucwords(mb_strtolower($cardParents)) }}</p>
                                        @endif

                                        @if(!empty($alumni->motto))
                                        <p class="yb-adm-card-motto">Motto:
                                            <span class="yb-adm-card-motto-text">"{{ $alumni->motto }}"</span>
                                        </p>
                                        @endif

                                    </div>
                                </div>

                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>

                @else
                <div class="flex flex-col items-center justify-center py-24 text-center">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100 mb-4">
                        <i class="fas fa-users-slash text-xl text-gray-400"></i>
                    </div>
                    <p class="font-semibold text-base" style="color:#333333;">No alumni found.</p>
                    <p class="text-sm mt-1" style="color:#555555;">Try adjusting your search or filters.</p>
                    @if($search || $batch || $course)
                    <button wire:click="resetFilters"
                            class="mt-4 px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                    @endif
                </div>
                @endif

            </div>{{-- end absolute scroll --}}

            {{-- Scroll-to-top --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-admin-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg transition-all text-white"
                    style="background:#7A3F91;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>

        </div>{{-- end scrollable area --}}

        {{-- ── PAGINATION ── --}}
        @php
            $total   = $this->totalFiltered;
            $cp      = $this->page;
            $lp      = $this->totalPages;
            $from    = $this->pageFrom;
            $to      = $this->pageTo;
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="yb-adm-pagination-bar">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ number_format($from) }}–{{ number_format($to) }}</strong>
                of <strong class="text-white font-bold">{{ number_format($total) }}</strong>
                alumni
                @if($search || $batch || $course)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            @if($lp > 1)
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="yb-adm-pg-btn yb-adm-pg-nav"
                        @if($cp <= 1) disabled @endif aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="gotoPage(1)" class="yb-adm-pg-btn yb-adm-pg-nav">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="yb-adm-pg-btn yb-adm-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }})" class="yb-adm-pg-btn yb-adm-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $lp }})" class="yb-adm-pg-btn yb-adm-pg-nav">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="yb-adm-pg-btn yb-adm-pg-nav"
                        @if($cp >= $lp) disabled @endif aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
            @endif
        </div>

    </div>{{-- /yb-adm-table-block --}}

</div>{{-- /root --}}

<script>
(function () {
    var watchedActions = ['search', 'batch', 'course', 'resetFilters', 'previousPage', 'nextPage', 'gotoPage'];

    function resetScroll() {
        var el = document.getElementById('yb-admin-scroll');
        if (el) el.scrollTop = 0;
    }

    document.addEventListener('livewire:init', function () {
        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('commit', function ({ component, commit, succeed }) {
                var isRelevant = (commit.updates && watchedActions.some(k => k in commit.updates))
                    || (commit.calls && commit.calls.some(c => watchedActions.includes(c.method)));
                if (!isRelevant) return;
                succeed(function () { resetScroll(); });
            });
        }
    });
})();
</script>
