{{-- resources/views/livewire/organizer/yearbook.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public string $search = '';
    public string $batch  = '';
    public string $course = '';
    public int    $page   = 1;

    public string $organizerDepartment = '';

    protected $queryString = [];

    const PER_PAGE = 100;

    public function mount(): void
    {
        if (!auth()->check() || !auth()->user()?->organizer) {
            abort(403, 'Access denied. Organizers only.');
        }

        // Used only to highlight the organizer's own college section
        // (glowing badge) among the full directory below — does NOT
        // restrict which alumni are visible.
        $this->organizerDepartment = DB::table('organizer')
            ->where('user_id', auth()->id())
            ->value('department') ?? '';
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

    /**
     * Organizer sees EVERY alumnus, regardless of batch or college —
     * unlike the alumni-facing yearbook (which is locked to the logged-in
     * alumni's own batch for privacy). No batch/college restriction is
     * applied here beyond whatever the organizer explicitly picks in the
     * filter bar. This is intentional and unchanged from before.
     *
     * Section headers are grouped by COURSE/PROGRAM (unchanged, back to
     * original behavior). Within each course group, members are sorted
     * by BATCH descending first (latest year, e.g. 2026, on top), then
     * A-Z (last name, then first name) within the same batch year.
     */
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

        if ($this->batch !== '') $q->where('batch', $this->batch);
        if ($this->course !== '') $q->where('course_code', $this->course);

        $all = $q->get();

        $courseMap   = $this->courses->keyBy('code');
        $collegeMap  = DB::table('courses')->pluck('college', 'code');
        $myDept      = $this->organizerDepartment;

        $grouped = $all->groupBy('course_code')
            ->map(function ($members, $code) use ($courseMap, $collegeMap, $myDept) {
                $name    = $courseMap[$code]?->name ?? $code;
                $college = $collegeMap[$code] ?? '';
                return [
                    'courseCode'  => $code,
                    'courseName'  => $name,
                    'sortKey'     => $this->courseSortKey($name),
                    // Marks this course group as belonging to the
                    // organizer's own college, for the glowing badge below.
                    'isMyCollege' => ($myDept !== '' && $college === $myDept),
                    // Batch descending (latest year first), then A-Z
                    // (last name, then first name) within the same batch.
                    'members'     => $members
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

    /**
     * Flattens groupedAlumni into a single ordered list of
     * ['group' => ..., 'member' => ...] rows, preserving batch order
     * (latest first) and A-Z order within each batch. This flat list is
     * what actually gets sliced into pages of exactly PER_PAGE (100)
     * members — so a page always shows 100 alumni (e.g. "1–100",
     * "101–200"), even if that means a batch group's cards continue
     * onto the next page.
     */
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

    /**
     * Returns the current page's rows re-grouped by batch, so the
     * template can still render one section header per batch — but the
     * underlying slice is a strict 100-row window into flatRows(), so
     * page size is always exactly PER_PAGE (except the last page).
     * A course group that spans two pages simply gets its header
     * repeated on both, with only the members that actually fall on
     * that page.
     */
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
        // Empty / null / literal "null" → default
        if (empty($path) || $path === 'null' || is_null($path)) {
            return asset('storage/alumni-photos/default.png');
        }

        // Explicit default.png references → default
        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }

        // Already a full Cloudinary (or any http/https) URL → use as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Cloudinary public_id stored without domain
        // e.g. "alumni-photos/abc123" or "v1234567890/alumni-photos/abc123"
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'v1')) {
            $cloudName = config('cloudinary.cloud_name')
                      ?? config('cloudinary.cloud.cloud_name')
                      ?? env('CLOUDINARY_CLOUD_NAME', '');
            if ($cloudName) {
                return 'https://res.cloudinary.com/' . $cloudName . '/image/upload/' . $path;
            }
        }

        // Local storage paths
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

    /**
     * Wraps every occurrence of the current search term in $value with a
     * light-blue <mark> highlight (case-insensitive). $value is HTML-escaped
     * first, so this is safe to output with {!! !!} in the view.
     */
    private function highlightSearch(string $value): string
    {
        $escaped = e($value);
        $term    = trim($this->search);
        if ($term === '') {
            return $escaped;
        }
        $escapedTerm = preg_quote(e($term), '/');
        return preg_replace(
            '/(' . $escapedTerm . ')/iu',
            '<mark class="yb-search-hl">$1</mark>',
            $escaped
        ) ?? $escaped;
    }
};
?>

<div class="yb-noselect yb-root-height yb-sidebar-blur flex flex-col gap-2 sm:gap-4 px-4 sm:px-7 lg:px-10 pt-3 sm:pt-6 pb-2 sm:pb-6 max-w-screen-2xl mx-auto w-full">

<style>
/* ── Disable text selection/copy across the whole Alumni Yearbook page ── */
.yb-noselect {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}
.yb-noselect input,
.yb-noselect textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* ─────────────────────────────────────────────────────────
   CARD — base (mobile-first: single col → grows via grid)
───────────────────────────────────────────────────────── */
.yb-card {
    transition: border-color .15s ease, box-shadow .15s ease;
    position: relative;
    width: 100%;
    background: #fff;
    display: flex;
    flex-direction: column;
    /* fluid height: enough room for photo + info on any screen */
    height: 360px;
    min-height: 360px;
    max-height: 360px;
    align-self: stretch;
}
.yb-card:hover          { box-shadow: 0 6px 22px rgba(0,0,0,.12); }
.yb-card:not(.yb-card-me) { box-shadow: 0 3px 12px rgba(90,26,138,.18); }

/* ── Photo — top of card ─────────────────────────────────── */
.yb-card-photo-wrap {
    width: 100%;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
    background: #7A3F91;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px 0 14px;
    min-height: 160px;
}
.yb-card-photo {
    width: 100px;
    height: 100px;
    object-fit: cover;
    object-position: top center;
    display: block;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,.9);
    box-shadow: 0 4px 16px rgba(0,0,0,.3);
    flex-shrink: 0;
}

/* ── Right column wrapper (info area below photo) ─────────── */
.yb-card-right {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Purple name ribbon ───────────────────────────────────── */
.yb-card-name-band {
    background: #7A3F91;
    padding: 7px 32px 7px 11px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}
.yb-card-name-band::before {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 22px; width: 18px;
    background: rgba(255,255,255,.20);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-card-name-band::after {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 9px; width: 8px;
    background: rgba(255,255,255,.10);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-card-name {
    font-size: 13px; font-weight: 800;
    color: #FFFFFF; line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: .01em;
    position: relative; z-index: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ── Card info body ───────────────────────────────────────── */
.yb-card-text {
    padding: 8px 11px 10px;
    flex: 1;
    display: flex; flex-direction: column; gap: 2px;
    background: #fff;
    overflow: hidden;
}
.yb-card-line {
    font-size: 12px; color: #1a1a1a; line-height: 1.35; font-weight: 600;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.yb-card-dash {
    display: block;
    width: 20px; height: 2px;
    background: #C8B8D8; border-radius: 2px; margin: 2px 0;
}
.yb-card-motto {
    font-size: 11px; font-weight: 700;
    color: #5A1A8A; margin-top: 3px;
}
.yb-card-motto-text {
    font-size: 11px; font-style: italic; font-weight: 600;
    color: #1a1a1a; line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ─────────────────────────────────────────────────────────
   TABLET (≥640px) — larger photo & text
───────────────────────────────────────────────────────── */
@media (min-width: 640px) {
    .yb-card {
        height: 400px;
        min-height: 400px;
        max-height: 400px;
    }
    .yb-card-photo-wrap { padding: 20px 0 16px; min-height: 180px; }
    .yb-card-photo      { width: 120px; height: 120px; border-width: 4px; }
    .yb-card-name-band  { padding: 8px 36px 8px 13px; }
    .yb-card-name       { font-size: 14px; }
    .yb-card-text       { padding: 10px 13px 12px; gap: 3px; }
    .yb-card-line       { font-size: 13px; }
    .yb-card-motto      { font-size: 12px; }
    .yb-card-motto-text { font-size: 12px; }
}

/* ─────────────────────────────────────────────────────────
   DESKTOP (≥1024px) — original full size
───────────────────────────────────────────────────────── */
@media (min-width: 1024px) {
    .yb-card {
        height: 420px;
        min-height: 420px;
        max-height: 420px;
    }
    .yb-card-photo-wrap { padding: 22px 0 18px; min-height: 190px; }
    .yb-card-photo      { width: 130px; height: 130px; }
    .yb-card-name       { font-size: 15px; }
    .yb-card-line       { font-size: 14px; }
    .yb-card-motto      { font-size: 13px; }
    .yb-card-motto-text { font-size: 13px; }
}

/* ── Section / batch badges ───────────────────────────── */
.yb-section-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 14px; border-radius: 9999px;
    font-size: 12px; font-weight: 700; letter-spacing: .02em;
    background: #F3E8FF; color: #7A3F91; border: 1.5px solid #D8B4FE;
    white-space: normal; line-height: 1.3; max-width: 100%;
}
.yb-batch-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 9999px;
    font-size: 11px; font-weight: 700;
    background: #F3E8FF; color: #7A3F91; border: 1.5px solid #D8B4FE;
}

/* ── "My college" glowing section badge ────────────────── */
.yb-section-badge-mine {
    border-color: #7A3F91 !important;
    border-width: 1.5px !important;
    animation: ybSectionGlowPulse 2.2s ease-in-out infinite;
}
@keyframes ybSectionGlowPulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(122,63,145,.18), 0 3px 10px rgba(122,63,145,.14); }
    50%       { box-shadow: 0 0 0 5px rgba(122,63,145,.30), 0 5px 16px rgba(122,63,145,.28); }
}

/* ── Alumni-count chip ──────────────────────────────────── */
.yb-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 12px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    background: rgba(122,63,145,.10); color: #7A3F91;
    border: 1px solid rgba(122,63,145,.22); white-space: nowrap;
}

/* ── Search highlight ───────────────────────────────────── */
.yb-search-hl {
    background: #dbeafe; color: #1e3a8a;
    border-radius: 3px; padding: 0 2px; font-weight: inherit;
}

/* ── Scrollbar ──────────────────────────────────────────── */
.yb-scroll::-webkit-scrollbar       { width: 5px; }
.yb-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Entry animation ────────────────────────────────────── */
@keyframes ybFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.yb-grid-wrap { animation: ybFadeUp .22s cubic-bezier(.4,0,.2,1) both; }

/* ── Search input ───────────────────────────────────────── */
.yb-search-input {
    padding: 0.4rem 0.75rem 0.4rem 2.25rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    transition: border-color .15s, box-shadow .15s;
    outline: none; width: 100%;
}
.yb-search-input::placeholder { color: #999999; font-weight: 400; }
.yb-search-input:hover  { border-color: #c4b5d4; }
.yb-search-input:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Dropdown trigger ───────────────────────────────────── */
.yb-dd-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0.4rem 2.25rem 0.4rem 0.75rem;
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
.yb-dd-btn:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Dropdown panel ─────────────────────────────────────── */
.yb-dd-panel {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 180px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 600; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
    /* Smooth, native-feeling scroll for long lists (Programs especially) —
       scroll-behavior handles wheel/anchor scrolls, -webkit-overflow-
       scrolling gives iOS Safari real inertia/momentum scroll instead of
       the stiff, step-by-step default it uses otherwise. */
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
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
    border-radius: 1rem; overflow: visible;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
    /* overflow:visible so dropdown panels escape the container;
       children handle their own border-radius at corners */
}

/* ─────────────────────────────────────────────────────────
   FILTER BAR — two-row layout on mobile, single row ≥768px
───────────────────────────────────────────────────────── */
.yb-filter-bar {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.5rem 0.75rem; flex-shrink: 0;
    position: relative; z-index: 50; overflow: visible;
    pointer-events: all;
    cursor: default !important;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    /* match parent's top border-radius since parent has overflow:visible */
    border-radius: 1rem 1rem 0 0;
}
/* The !important above previously defeated .yb-sidebar-blur's pointer-events:none,
   so the filter bar (and the dropdown buttons inside it) stayed clickable even
   while the sidebar overlay was open and the rest of the page was blurred/locked.
   These rules put it back under the sidebar's control.
   The filter bar also gets its own EXPLICIT filter:blur() here (not just relying
   on inheriting it from the .yb-sidebar-blur ancestor): because .yb-filter-bar has
   its own position:relative + z-index:50 + overflow:visible, it forms its own
   stacking context, and — depending on the host layout/sidebar implementation —
   can end up rendered on its own compositor layer that visually "pops out" above
   the blurred ancestor instead of blurring along with it. Setting the blur
   directly on this element guarantees it always blurs, regardless of stacking. */
.yb-sidebar-active .yb-filter-bar,
body.sidebar-open .yb-filter-bar,
.sidebar-open .yb-filter-bar,
[data-sidebar-open="true"] .yb-filter-bar {
    pointer-events: none !important;
    filter: blur(3px) !important;
    user-select: none !important;
}

/* Row 1 on mobile: search fills the full width */
.yb-filter-search-wrap {
    flex: 1 1 100%;    /* full row on mobile */
    min-width: 0;
    max-width: 100%;
    position: relative;
}

/* Row 2 on mobile: dropdowns + count + reset in one flex row */
.yb-filter-controls {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 6px;
    width: 100%;
    min-width: 0;
}

/* FILTERS label hidden on small screens to save space */
.yb-filter-label {
    display: none;
}

/* "Found" count shrinks label on tight screens */
.yb-found-count {
    flex-shrink: 0;
    margin-left: auto;
}

/* Tablet+: search + controls side by side */
@media (min-width: 640px) {
    .yb-filter-search-wrap {
        flex: 1 1 160px;
        max-width: 280px;
    }
    .yb-filter-controls {
        width: auto;
        flex: 1;
    }
    .yb-filter-label {
        display: flex;
    }
}

@media (min-width: 768px) {
    .yb-filter-bar      { padding: 0.6rem 0.875rem; gap: 8px; }
    .yb-filter-search-wrap { max-width: 320px; }
}

/* ── Keep filter controls interactive during Livewire loading — but NOT
   while the mobile sidebar is open. The old blanket !important here is
   what kept the filter bar and dropdowns clickable/unblurred underneath
   the sidebar overlay; the :not() guard restores the sidebar's control
   while still beating Livewire's wire:loading state in normal use. ── */
.yb-filter-bar *,
.yb-dd-btn,
.yb-dd-panel,
.yb-dd-item,
.yb-search-input {
    pointer-events: all;
}
html:not(.yb-sidebar-active):not(.sidebar-open) body:not(.sidebar-open) .yb-filter-bar *,
html:not(.yb-sidebar-active):not(.sidebar-open) body:not(.sidebar-open) .yb-dd-btn,
html:not(.yb-sidebar-active):not(.sidebar-open) body:not(.sidebar-open) .yb-dd-panel,
html:not(.yb-sidebar-active):not(.sidebar-open) body:not(.sidebar-open) .yb-dd-item,
html:not(.yb-sidebar-active):not(.sidebar-open) body:not(.sidebar-open) .yb-search-input {
    pointer-events: all !important;
}
.yb-dd-btn        { cursor: pointer !important; }
.yb-dd-item       { cursor: pointer !important; }
.yb-search-input  { cursor: text !important; }

.yb-dd-btn:disabled {
    pointer-events: none !important;
    cursor: default !important;
    opacity: 0.5;
}

/* ── Pagination bar ─────────────────────────────────────── */
.yb-pagination-bar {
    flex-shrink: 0;
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    padding: 0 1rem; min-height: 48px;
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.5rem;
    flex-wrap: wrap; border-top: 1px solid rgba(122,63,145,.3);
    position: sticky; bottom: 0; z-index: 30;
    /* match parent's bottom border-radius */
    border-radius: 0 0 1rem 1rem;
    overflow: hidden;
}
.yb-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; padding: 0 8px;
    border-radius: 8px; font-size: 12px; font-weight: 700; transition: all .15s;
}
.yb-pg-active { background: #fff; color: #7a3f91; }
.yb-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }
.yb-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.yb-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ─────────────────────────────────────────────────────────
   ROOT HEIGHT — JS-measured available space
───────────────────────────────────────────────────────── */
/* Pure CSS (no JS measuring): fills the screen exactly, same as Job / Event / Employment pages.
   Mobile/tablet: top bar 48px + bottom padding 16px = 64px. Desktop: 56px + 24px = 80px.
   100dvh follows the phone browser bars (address bar showing/hiding) so nothing gets cut off. */
.yb-root-height {
    height: calc(100vh - 64px);
    height: calc(100dvh - 64px);
    max-height: calc(100dvh - 64px);
    overflow: hidden;
}
html:has(.yb-root-height), body:has(.yb-root-height) { overflow: hidden !important; scrollbar-width: none; }
html:has(.yb-root-height)::-webkit-scrollbar, body:has(.yb-root-height)::-webkit-scrollbar { display: none; }
@media (min-width: 1024px) {
    .yb-root-height {
        height: calc(100vh - 80px);
        height: calc(100dvh - 80px);
        max-height: calc(100dvh - 80px);
        padding-top: 1.25rem !important; padding-bottom: 1.25rem !important;
    }
}
@media (min-width: 1024px) and (max-height: 800px) {
    .yb-root-height { gap: .6rem !important; padding-top: .75rem !important; padding-bottom: .75rem !important; }
}

/* ─────────────────────────────────────────────────────────
   MOBILE (<768px) — compact everything
───────────────────────────────────────────────────────── */
@media (max-width: 767px) {
    html, body { overflow: hidden !important; }

    /* Full screen on phones: the page wrapper has 1rem side padding — cancel it so the block spans edge to edge,
       and the block fills all remaining height down to the bottom of the screen. */
    .yb-root-height {
        margin-left: -1rem; margin-right: -1rem; width: calc(100% + 2rem) !important; max-width: none !important;
        padding: .5rem 0 0 !important; gap: .5rem !important;
        overflow: hidden !important;
    }
    .yb-root-height > div:first-of-type { padding: 0 .75rem; }
    .yb-table-block { border-radius: 1rem 1rem 0 0; border-left: 0; border-right: 0; border-bottom: 0; box-shadow: none; }
    /* Keep filter bar top radius matching table-block on mobile */
    .yb-filter-bar { border-radius: 1rem 1rem 0 0; }
    /* No bottom radius on mobile (block goes to screen edge) */
    .yb-pagination-bar { border-radius: 0; }

    /* Compact page header */
    .yb-mobile-subtitle      { display: none; }
    .yb-mobile-header-icon   { width: 2.25rem !important; height: 2.25rem !important; }
    .yb-mobile-title         { font-size: 1rem !important; }

    /* Compact pagination */
    .yb-pagination-bar {
        min-height: 40px;
        padding: 6px 0.75rem;
        padding-bottom: calc(0.4rem + env(safe-area-inset-bottom, 0px));
    }
    .yb-pagination-bar p { font-size: 10px; }
    .yb-pg-btn { min-width: 26px; height: 26px; padding: 0 6px; font-size: 11px; }

    /* Dropdowns: ensure panels aren't clipped on mobile; let them overflow down.
       z-index is kept BELOW the sidebar overlay (see .yb-sidebar-blur block)
       so that when the sidebar opens, these fixed-position panels blur and
       sit under it instead of floating on top of it. They're also closed
       automatically the moment the sidebar opens (see JS watcher below). */
    .yb-dd-panel {
        position: fixed !important;
        /* JS sets top/left dynamically via data attrs */
        top: var(--dd-top, auto);
        left: var(--dd-left, auto);
        min-width: var(--dd-width, 140px);
        width: var(--dd-width, 140px);
        max-width: min(calc(100vw - 1.5rem), 280px);
        max-height: 50vh;
        z-index: 400;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    /* Dropdown items must wrap on mobile — long program names like
       "Bachelor of Science in Information Technology" were overflowing
       the panel width (nowrap) and spilling past the screen edge. */
    .yb-dd-item {
        white-space: normal !important;
        word-break: break-word;
        line-height: 1.3;
    }

    /* Keep the trigger button itself readable — don't over-truncate it */
    .yb-dd-btn { font-size: 0.8rem; padding-right: 2rem; max-width: 150px; }
    .yb-dd-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
}

/* ── Fixed-position dropdown panels must blur/hide along with everything
     else when the sidebar opens — being position:fixed normally escapes
     an ancestor's filter:blur(), so they need their own explicit rule. ── */
.yb-sidebar-active .yb-dd-panel,
body.sidebar-open .yb-dd-panel,
.sidebar-open .yb-dd-panel,
[data-sidebar-open="true"] .yb-dd-panel {
    filter: blur(3px);
    pointer-events: none !important;
}

/* ─────────────────────────────────────────────────────────
   SIDEBAR BLUR — content blurs when the left sidebar opens
   on mobile. Detects sidebar open state via JS below.
───────────────────────────────────────────────────────── */
.yb-sidebar-blur {
    transition: filter 0.2s ease, opacity 0.2s ease;
}
.yb-sidebar-active .yb-sidebar-blur {
    filter: blur(3px);
    pointer-events: none;
    user-select: none;
}
/* Also handle common layout class conventions */
body.sidebar-open .yb-sidebar-blur,
.sidebar-open .yb-sidebar-blur,
[data-sidebar-open="true"] .yb-sidebar-blur {
    filter: blur(3px);
    pointer-events: none;
}

/* ── Scrollable area clipping fix (replaces overflow:hidden on table-block) ── */
/* The scrollable cards area still clips its own content via absolute inset */
#yb-organizer-scroll {
    /* already has overflow-y: auto; absolute inset-0 handles the bounds */
}

@media (min-width: 768px) and (max-width: 1023px) {
    .yb-root-height { padding-top: .75rem !important; padding-bottom: .5rem !important; gap: .6rem !important; }
}

/* ─────────────────────────────────────────────────────────
   EXTRA-SMALL (<400px) — very tight phones
───────────────────────────────────────────────────────── */
@media (max-width: 399px) {
    .yb-dd-btn { max-width: 96px; font-size: 0.75rem; }
    .yb-chip   { padding: 3px 8px; font-size: 10px; }
}
</style>

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col gap-3 flex-shrink-0">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-4">
                <div class="yb-mobile-header-icon w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                     style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                    <i class="fas fa-book-open text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="yb-mobile-title text-xl font-semibold tracking-tight" style="color:#333333;">Alumni Yearbook</h1>
                    <p class="yb-mobile-subtitle text-sm leading-relaxed mt-0.5 font-semibold" style="color:#7a3f91;">Complete Alumni Directory</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="yb-chip">
                    <i class="fas fa-graduation-cap text-[10px]"></i>
                    {{ number_format($this->totalAlumni) }} Alumni
                </span>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED BLOCK — filter + cards + pagination ══ --}}
    <div class="yb-table-block">

        {{-- ── FILTER BAR ── --}}
        {{-- openDd tracks which dropdown is currently open ('batch'|'course'|'').
             When one is open, the other button gets pointer-events:none + cursor:default
             so only one dropdown can be interacted with at a time. --}}
        <div class="yb-filter-bar"
             x-data="{
                openDd: '',
                init() {
                    document.addEventListener('livewire:request', () => { this.openDd = ''; });
                }
             }">

            {{-- Row 1: Search (full width on mobile, inline on ≥sm) --}}
            <div class="yb-filter-search-wrap"
                 wire:ignore
                 x-data="{ q: '', init() { this.q = $wire.search ?? ''; $wire.$watch('search', v => { if (v !== this.q) this.q = v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                   style="color:#555555; z-index:1;"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search..."
                       class="yb-search-input"
                       autocomplete="off" spellcheck="false">
            </div>

            {{-- Row 2 (mobile) / inline (≥sm): Filters label + dropdowns + count + reset --}}
            <div class="yb-filter-controls">

                {{-- FILTERS label (hidden on mobile) --}}
                <div class="yb-filter-label items-center gap-2 px-2 h-[34px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide"
                     style="color:#7a3f91;">
                    Filters
                </div>

                {{-- Batch dropdown ── --}}
                <div class="relative shrink-0" @click.outside="if(openDd==='batch') openDd=''">
                    <button type="button"
                            @click="
                                const r = $el.getBoundingClientRect();
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-top', (r.bottom + 4) + 'px');
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-left', r.left + 'px');
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-width', r.width + 'px');
                                openDd = openDd === 'batch' ? '' : 'batch'
                            "
                            :class="{ 'active': $wire.batch !== '' }"
                            :style="openDd === 'course' ? 'pointer-events:none;cursor:default;opacity:.5;' : ''"
                            wire:loading.attr="disabled"
                            wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage"
                            class="yb-dd-btn">
                        <span x-text="$wire.batch !== '' ? 'Batch ' + $wire.batch : 'All Batches'"></span>
                    </button>
                    <div x-show="openDd === 'batch'"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="yb-dd-panel"
                         style="display:none;">
                        <button type="button"
                                @click="$wire.set('batch', ''); openDd = ''"
                                :class="{ 'sel': $wire.batch === '' }"
                                class="yb-dd-item">All Batches</button>
                        @foreach($this->batches as $b)
                        <button type="button"
                                @click="$wire.set('batch', '{{ $b }}'); openDd = ''"
                                :class="{ 'sel': $wire.batch === '{{ $b }}' }"
                                class="yb-dd-item">{{ $b }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Program dropdown ── --}}
                <div class="relative shrink-0" @click.outside="if(openDd==='course') openDd=''">
                    <button type="button"
                            @click="
                                const r = $el.getBoundingClientRect();
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-top', (r.bottom + 4) + 'px');
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-left', r.left + 'px');
                                $el.closest('.yb-filter-bar').style.setProperty('--dd-width', Math.max(r.width, 200) + 'px');
                                openDd = openDd === 'course' ? '' : 'course'
                            "
                            :class="{ 'active': $wire.course !== '' }"
                            :style="openDd === 'batch' ? 'pointer-events:none;cursor:default;opacity:.5;' : ''"
                            wire:loading.attr="disabled"
                            wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage"
                            class="yb-dd-btn">
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
                         class="yb-dd-panel"
                         style="display:none; min-width:200px;">
                        <button type="button"
                                @click="$wire.set('course', ''); openDd = ''"
                                :class="{ 'sel': $wire.course === '' }"
                                class="yb-dd-item">All Programs</button>
                        @foreach($this->courses as $c)
                        <button type="button"
                                @click="$wire.set('course', '{{ $c->code }}'); openDd = ''"
                                :class="{ 'sel': $wire.course === '{{ $c->code }}' }"
                                class="yb-dd-item">{{ $c->name }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Found count ── --}}
                <div class="yb-found-count flex items-center">
                    <span class="text-xs font-bold px-2 py-1 rounded-full uppercase whitespace-nowrap"
                          style="background:#F9F7FC; color:#7A3F91; border:1.5px solid #E8E0F0;">
                        {{ number_format($this->totalFiltered) }} found
                    </span>
                </div>

                {{-- Reset ── --}}
                <button wire:click="resetFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="resetFilters"
                        class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                        style="color:#333333;">
                    <span wire:loading.remove wire:target="resetFilters">
                        <i class="fas fa-rotate-left text-xs"></i>
                    </span>
                    <span wire:loading wire:target="resetFilters">
                        <i class="fas fa-spinner fa-spin text-xs" style="color:#7A3F91;"></i>
                    </span>
                    <span class="hidden sm:inline text-xs">Reset</span>
                </button>

            </div>{{-- /yb-filter-controls --}}
        </div>

        {{-- ── SCROLLABLE CARDS AREA ── --}}
        <div class="flex-1 min-h-0 relative" style="background:#f3f4f6;"
             x-data="{ showTop: false }">

            {{-- Loading overlay — covers the entire card area during any wire request.
                 pointer-events is intentionally NOT none so all clicks/hovers are
                 blocked while loading is in progress, cursor shows default. --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 style="cursor:default;"
                 wire:loading.flex wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            <div id="yb-organizer-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-scroll absolute inset-0 overflow-y-auto overflow-x-hidden p-3 sm:p-4"
                 wire:loading.class="opacity-50"
                 wire:target="search,batch,course,resetFilters,previousPage,nextPage,gotoPage">

                @if($this->totalFiltered > 0)
                <div class="yb-grid-wrap space-y-2"
                     wire:key="results-{{ md5($search . '|' . $batch . '|' . $course . '|' . $page) }}">
                    @foreach($this->currentPageGroups as $group)
                    <div wire:key="group-{{ $group['courseCode'] }}">
                        {{-- Section header — per COURSE/PROGRAM --}}
                        <div class="flex items-center flex-wrap gap-2 pt-2 pb-2 px-1">
                            <span class="yb-section-badge {{ $group['isMyCollege'] ? 'yb-section-badge-mine' : '' }}">
                                {{ $group['courseName'] }}
                            </span>
                            <div class="flex-1 min-w-[24px] h-px" style="background:#D8B4FE;"></div>
                            <span class="text-xs font-semibold shrink-0" style="color:#c0a0d8;">
                                {{ $group['members']->count() }} shown
                            </span>
                        </div>

                        {{-- Card grid: 1 col <400px, 2 col sm, 3 col md, 4 col lg, 5 col xl --}}
                        <div class="grid grid-cols-1 min-[400px]:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2 sm:gap-3 items-stretch">
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
                                    $alumni->address_street      ?? '',
                                    $alumni->address_barangay    ?? '',
                                    $alumni->address_municipality ?? '',
                                    $alumni->address_province    ?? '',
                                ]));

                                // Parents
                                $fLast  = trim($alumni->father_last_name  ?? '');
                                $fFirst = trim($alumni->father_given_name ?? '');
                                $fMid   = trim($alumni->father_middle_name ?? '');
                                $mFirst = trim($alumni->mother_given_name ?? '');
                                $mLast  = trim($alumni->mother_last_name  ?? '');

                                if ($fFirst && $fLast) {
                                    $fMidI      = $fMid ? strtoupper(mb_substr($fMid,0,1)).'.' : '';
                                    $cardParents = ($mFirst ? 'Mr. & Mrs. ' : 'Mr. ') . $fFirst . ($fMidI ? ' '.$fMidI : '') . ' ' . $fLast;
                                } elseif ($mFirst && $mLast) {
                                    $cardParents = 'Mrs. ' . $mFirst . ' ' . $mLast;
                                } else {
                                    $cardParents = null;
                                }
                            @endphp
                            <div wire:key="alum-{{ $alumni->id }}"
                                 class="yb-card rounded-xl overflow-hidden border cursor-default"
                                 style="border-color: #E2D6F0;">

                                {{-- Portrait photo — top purple section --}}
                                <div class="yb-card-photo-wrap">
                                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                         alt="{{ $cardName }}"
                                         class="yb-card-photo"
                                         loading="lazy" decoding="async"
                                         onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                </div>

                                {{-- Name ribbon + info body --}}
                                <div class="yb-card-right">

                                    {{-- Purple name ribbon --}}
                                    <div class="yb-card-name-band">
                                        <p class="yb-card-name">{!! $this->highlightSearch($cardName) !!}</p>
                                    </div>

                                    {{-- Info body (white) — plain text, no icons --}}
                                    <div class="yb-card-text">

                                        @if($cardDob)
                                        <p class="yb-card-line">{{ $cardDob }}</p>
                                        @endif

                                        @if($cardAddr)
                                        <p class="yb-card-line">{{ ucwords(mb_strtolower($cardAddr)) }}</p>
                                        @endif

                                        @if($cardParents)
                                        <p class="yb-card-line">{{ ucwords(mb_strtolower($cardParents)) }}</p>
                                        @endif

                                        @if(!empty($alumni->motto))
                                        <p class="yb-card-motto">Motto:
                                            <span class="yb-card-motto-text">"{{ $alumni->motto }}"</span>
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
                </div>
                @endif

            </div>{{-- end absolute scroll --}}

            {{-- Scroll-to-top --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-organizer-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg transition-all text-white"
                    style="background:#7A3F91;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>

        </div>{{-- end scrollable area --}}

        {{-- ── PAGINATION (sticky — always visible, no extra scroll needed) ── --}}
        @php
            $total   = $this->totalFiltered;
            $cp      = $this->page;
            $lp      = $this->totalPages;
            $from    = $this->pageFrom;
            $to      = $this->pageTo;
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="yb-pagination-bar">
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
                        class="yb-pg-btn yb-pg-nav"
                        @if($cp <= 1) disabled @endif aria-label="Previous">
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
                        @if($cp >= $lp) disabled @endif aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
            @endif
        </div>

    </div>{{-- /yb-table-block --}}

</div>{{-- /root --}}

<script>
(function () {
    // ── Reset results scroll to top on filter/search/pagination changes ──
    var watchedActions = ['search', 'batch', 'course', 'resetFilters', 'previousPage', 'nextPage', 'gotoPage'];

    function resetScroll() {
        var el = document.getElementById('yb-organizer-scroll');
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

    // ── Sidebar blur detection ──
    // Watches the document for any common sidebar-open indicators and blurs
    // the yearbook content when the sidebar overlaps on mobile.
    (function () {
        function isSidebarOpen() {
            // Common class-based conventions
            if (document.body.classList.contains('sidebar-open')) return true;
            if (document.documentElement.classList.contains('sidebar-open')) return true;

            // Alpine store: try common store names
            try {
                if (window.Alpine) {
                    var stores = ['sidebar', 'nav', 'layout'];
                    for (var i = 0; i < stores.length; i++) {
                        var s = window.Alpine.store(stores[i]);
                        if (s && (s.open === true || s.isOpen === true || s.visible === true)) return true;
                    }
                }
            } catch (e) {}

            // Check for a visible mobile overlay/backdrop element
            var overlays = document.querySelectorAll(
                '[data-sidebar-overlay], .sidebar-overlay, .nav-overlay, .mobile-overlay, [x-ref="sidebarOverlay"]'
            );
            for (var j = 0; j < overlays.length; j++) {
                var o = overlays[j];
                var style = window.getComputedStyle(o);
                if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') return true;
            }

            // Check translate state: sidebar elements commonly use translate-x-0 when open
            var sidebars = document.querySelectorAll('aside, [data-sidebar], .sidebar, #sidebar, nav.sidebar');
            for (var k = 0; k < sidebars.length; k++) {
                var el = sidebars[k];
                // If sidebar is visually overlapping (fixed/absolute + left ≥ 0)
                var cs = window.getComputedStyle(el);
                if ((cs.position === 'fixed' || cs.position === 'absolute') && el.getBoundingClientRect().left >= 0) {
                    // Check if its data indicates open
                    if (el.dataset.sidebarOpen === 'true' || el.getAttribute('aria-expanded') === 'true') return true;
                }
            }

            // Generic fallback: this layout's mobile nav rail doesn't match any
            // of the named conventions above (no .sidebar-open class, no Alpine
            // store, no [data-sidebar-overlay] element, no aria-expanded flag).
            // What IS reliably true whenever that rail is open is that a large,
            // dark, semi-transparent, fixed/absolute full-viewport backdrop
            // appears behind it (the dimming effect itself) and sits above our
            // yearbook content in paint order. Detect that directly instead of
            // depending on the other app's class names.
            var allEls = document.querySelectorAll('body *');
            for (var m = 0; m < allEls.length; m++) {
                var el2 = allEls[m];
                if (el2.closest('.yb-root-height')) continue; // ignore our own elements
                var cs2 = window.getComputedStyle(el2);
                if (cs2.position !== 'fixed' && cs2.position !== 'absolute') continue;
                if (cs2.display === 'none' || cs2.visibility === 'hidden' || parseFloat(cs2.opacity) === 0) continue;
                var rect2 = el2.getBoundingClientRect();
                var coversMost = rect2.width >= window.innerWidth * 0.7 && rect2.height >= window.innerHeight * 0.7;
                if (!coversMost) continue;
                // Must actually look like a dimming backdrop: a semi-transparent
                // dark background (not plain white/transparent page chrome).
                var bg = cs2.backgroundColor || '';
                var rgbaMatch = bg.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\)/);
                if (!rgbaMatch) continue;
                var r = +rgbaMatch[1], g = +rgbaMatch[2], b = +rgbaMatch[3];
                var a = rgbaMatch[4] !== undefined ? +rgbaMatch[4] : 1;
                var isDark = (r + g + b) / 3 < 120;
                var isTranslucent = a > 0 && a < 0.95;
                if (isDark && isTranslucent) return true;
                // Some sidebar implementations use a lighter gray dimming
                // overlay instead of a dark one — it may not trip the
                // isDark<120 check above even though it visually reads as
                // a dimming layer (this is what the screenshot showed: a
                // mid-gray translucent overlay, not a near-black one).
                // A large, translucent, non-white overlay is already a
                // strong enough signal on its own — no need to also
                // require it be dark.
                var isNearWhite = r > 240 && g > 240 && b > 240;
                if (isTranslucent && !isNearWhite) return true;
            }

            // Last-resort fallback: body/html scroll-lock. Almost every
            // mobile sidebar/drawer implementation — regardless of its
            // class names, Alpine store names, or overlay markup, all of
            // which the checks above already tried to guess — locks page
            // scroll while it's open (overflow:hidden / position:fixed on
            // <body> or <html>, sometimes via an inline style, sometimes
            // via a utility class). This doesn't depend on knowing any of
            // that host app's naming conventions at all, so it catches the
            // sidebar even when every named-convention + color-sampling
            // check above misses it (e.g. a very light/near-white overlay
            // under the isNearWhite guard, or an overlay element that
            // hasn't painted large enough yet to pass coversMost).
            var bodyCs = window.getComputedStyle(document.body);
            var htmlCs = window.getComputedStyle(document.documentElement);
            var scrollLocked =
                bodyCs.overflow === 'hidden' || bodyCs.overflowY === 'hidden' ||
                htmlCs.overflow === 'hidden' || htmlCs.overflowY === 'hidden' ||
                bodyCs.position === 'fixed';
            if (scrollLocked && window.innerWidth < 768) return true;

            return false;
        }

        function applySidebarBlur() {
            var open = isSidebarOpen();
            var wasOpen = document.body.classList.contains('yb-sidebar-active');
            document.body.classList.toggle('yb-sidebar-active', open);

            // Close any open filter dropdown the moment the sidebar opens,
            // so a stale panel doesn't sit there blurred-but-visible.
            if (open && !wasOpen) {
                var filterBar = document.querySelector('.yb-filter-bar');
                if (filterBar && window.Alpine) {
                    var data = window.Alpine.$data(filterBar);
                    if (data) data.openDd = '';
                }
            }
        }

        // MutationObserver: watch class changes on body, html, and sidebar candidates
        var mo = new MutationObserver(applySidebarBlur);
        mo.observe(document.body,            { attributes: true, attributeFilter: ['class', 'data-sidebar-open'] });
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        // Also watch subtree for sidebar visibility changes (display/style toggling)
        var sto = new MutationObserver(applySidebarBlur);
        sto.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class', 'style', 'data-sidebar-open', 'aria-expanded'] });

        // Also watch for new nodes appearing/disappearing — some sidebars
        // mount/unmount their overlay+rail rather than toggling a class,
        // which the attribute-only observers above would miss entirely.
        var cto = new MutationObserver(applySidebarBlur);
        cto.observe(document.body, { childList: true, subtree: true });

        // Short-interval safety-net poll for the first couple seconds after
        // any detected DOM change, in case the overlay's dimming is driven
        // by a CSS transition (opacity animating in) rather than an
        // instant style/class flip — a mutation can fire before the
        // overlay is visually "translucent enough" to be detected yet.
        var pollTimer = null;
        function scheduleRecheck() {
            if (pollTimer) return;
            var ticks = 0;
            pollTimer = setInterval(function () {
                applySidebarBlur();
                if (++ticks > 6) { clearInterval(pollTimer); pollTimer = null; }
            }, 50);
        }
        mo.disconnect(); sto.disconnect(); cto.disconnect();
        var moWrap = new MutationObserver(function (m) { applySidebarBlur(); scheduleRecheck(); });
        moWrap.observe(document.body,            { attributes: true, attributeFilter: ['class', 'data-sidebar-open'] });
        moWrap.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        moWrap.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class', 'style', 'data-sidebar-open', 'aria-expanded'] });
        moWrap.observe(document.body, { childList: true, subtree: true });

        // Initial check
        applySidebarBlur();
    })();
})();
</script>