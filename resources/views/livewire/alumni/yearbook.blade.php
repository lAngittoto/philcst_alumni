<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithoutUrlPagination;

    public string $search = '';
    public string $course = '';

    // Logged-in alumni details
    public string $myBatch       = '';
    public string $myCourseCode  = '';
    public string $myCourseName  = '';
    public string $myCollege     = '';
    public int    $myAlumniId    = 0;

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

                if ($this->myCourseCode !== '') {
                    $this->myCollege = (string) (Course::where('code', $this->myCourseCode)->value('college') ?? '');
                }
            }
        }
    }

    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    public function setCourse(string $code): void
    {
        $this->course = $code;
        $this->resetPage();
    }

    public function clearCourse(): void
    {
        $this->course = '';
        $this->resetPage();
    }

    #[Computed]
    public function courses()
    {
        $q = Alumni::query();
        if ($this->myBatch !== '') {
            $q->where('batch', $this->myBatch);
        }

        $codes = $q->pluck('course_code')->unique()->filter()->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        return Course::whereIn('code', $codes)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at',
                      'profile_completed',
                      'date_of_birth', 'address_street', 'address_barangay', 'address_municipality', 'address_province',
                      'father_last_name', 'father_given_name', 'father_middle_name',
                      'mother_last_name', 'mother_given_name', 'mother_middle_name',
                      'motto']);

        if ($this->myBatch !== '') {
            $q->where('batch', $this->myBatch);
        }

        if (trim($this->search) !== '') {
            $s = trim($this->search);
            $q->where(function ($sub) use ($s) {
                $sub->where('name',        'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email',      'like', "%{$s}%");
            });
        }

        if ($this->course !== '') {
            $q->where('course_code', $this->course);
        }

        $collegeMap = Course::pluck('college', 'code')->toArray();

        $myCourseCode = $this->myCourseCode;
        $myCollege    = $this->myCollege;

        $all = $q->get()->sortBy(function ($alumni) use ($collegeMap, $myCourseCode, $myCollege) {
            $college = $collegeMap[$alumni->course_code] ?? '';

            $ownCourseRank  = ($myCourseCode !== '' && $alumni->course_code === $myCourseCode) ? 0 : 1;
            $ownCollegeRank = ($myCollege !== '' && $college === $myCollege) ? 0 : 1;

            return sprintf(
                '%d|%d|%s|%s|%s',
                $ownCourseRank,
                $ownCollegeRank,
                $college,
                $alumni->course_name,
                $alumni->name
            );
        })->values();

        $perPage = 100;
        $page    = (int) \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage('page');
        $page    = $page > 0 ? $page : 1;
        $sliced  = $all->forPage($page, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );
    }

    #[Computed]
    public function groupedAlumni()
    {
        return $this->alumniRecords->getCollection()->groupBy('course_name');
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
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (str_starts_with($path, 'alumni-photos/')) {
            return asset('storage/' . $path);
        }
        if (!str_contains($path, '/')) {
            return asset('storage/alumni-photos/' . $path);
        }
        return asset('storage/' . $path);
    }

    public function formatAlumniNameYearbook(string $fullName): string
    {
        $parts = array_values(array_filter(explode(' ', trim($fullName)), fn ($p) => $p !== ''));

        if (count($parts) === 0) return '';
        if (count($parts) === 1) return $parts[0];

        $suffixes   = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];
        $suffix     = '';
        $lastToken  = strtolower(rtrim(end($parts), '.'));
        if (in_array($lastToken, $suffixes, true)) {
            $suffix = array_pop($parts);
        }

        $count = count($parts);

        if ($count === 1) {
            return $parts[0] . ($suffix !== '' ? ' ' . $suffix : '');
        }

        $lastName       = $parts[$count - 1];
        $firstName      = $parts[0];
        $middleInitials = '';

        for ($i = 1; $i < $count - 1; $i++) {
            $middleInitials .= strtoupper($parts[$i][0]) . '. ';
        }

        $middleInitials = rtrim($middleInitials);

        $formatted = $lastName . ', ' . $firstName;
        if ($middleInitials !== '') $formatted .= ' ' . $middleInitials;
        if ($suffix !== '')        $formatted .= ' ' . $suffix;

        return $formatted;
    }

    public function formatAlumniName(string $fullName): string
    {
        $parts = array_values(array_filter(explode(' ', trim($fullName)), fn ($p) => $p !== ''));

        if (count($parts) === 0) return '';
        if (count($parts) === 1) return $parts[0];

        $suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

        $suffix = '';
        $lastToken = strtolower(rtrim(end($parts), '.'));
        if (in_array($lastToken, $suffixes, true)) {
            $suffix = array_pop($parts);
        }

        $count = count($parts);

        if ($count === 1) {
            return trim($parts[0] . ($suffix !== '' ? ' ' . $suffix : ''));
        }

        if ($count === 2) {
            return trim($parts[0] . ' ' . $parts[1] . ($suffix !== '' ? ' ' . $suffix : ''));
        }

        $firstName      = $parts[0];
        $lastName       = $parts[$count - 1];
        $middleInitials = '';

        for ($i = 1; $i < $count - 1; $i++) {
            $middleInitials .= strtoupper($parts[$i][0]) . '. ';
        }

        return trim($firstName . ' ' . $middleInitials . $lastName . ($suffix !== '' ? ' ' . $suffix : ''));
    }
};
?>

<div class="flex flex-col gap-2 sm:gap-3 px-2 sm:px-5 lg:px-10 pt-2 sm:pt-4 pb-1 sm:pb-4 max-w-screen-2xl mx-auto w-full yb-root-height yb-no-select"
     oncontextmenu="return false;"
     x-data="{
        activeFilter: null,
        courseBusy: false,
        setAvailHeight() {
            const rect = this.$el.getBoundingClientRect();
            const bottomSafe = 8;
            const avail = window.innerHeight - rect.top - bottomSafe;
            this.$el.style.setProperty('--yb-avail-h', avail + 'px');
        },
        recalcHeight() {
            setTimeout(() => this.setAvailHeight(), 80);
            setTimeout(() => this.setAvailHeight(), 300);
        }
     }"
     x-init="
        setAvailHeight();
        window.addEventListener('resize', () => recalcHeight());
        window.addEventListener('orientationchange', () => recalcHeight());
        document.addEventListener('livewire:navigated', () => recalcHeight());
        if (typeof Livewire !== 'undefined') {
            Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                succeed(({ snapshot, effect }) => { recalcHeight(); });
            });
        }
     ">

<style>
/* ── Block text selection/copy across the whole page ──────
   Inputs/textareas are explicitly exempted below so typing,
   selecting-to-edit, and copy/paste inside the search box
   still work normally. ────────────────────────────────── */
.yb-no-select, .yb-no-select * {
    -webkit-user-select: none;
    -moz-user-select: none;
    user-select: none;
}
.yb-no-select input,
.yb-no-select textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    user-select: text;
}

/* ── Base ──────────────────────────────────────────────── */
.yb-card {
    transition: border-color .15s ease, box-shadow .15s ease;
    position: relative;
    width: 100%;
    background: #fff;
    display: flex;
    flex-direction: column;
    min-height: 320px;
    height: auto;
    cursor: default;
}
@media (min-width: 641px) {
    .yb-card { min-height: 370px; }
}
@media (max-width: 400px) {
    .yb-card { min-height: 260px; }
}
.yb-card:hover {
    box-shadow: 0 6px 22px rgba(0,0,0,.12);
}

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
    padding: 14px 0 12px;
    min-height: 140px;
}
@media (min-width: 480px) {
    .yb-card-photo-wrap { padding: 18px 0 14px; min-height: 160px; }
}
@media (min-width: 641px) {
    .yb-card-photo-wrap { padding: 22px 0 18px; min-height: 190px; }
}
.yb-card-photo {
    width: 76px;
    height: 76px;
    object-fit: cover;
    object-position: top center;
    display: block;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,.9);
    box-shadow: 0 4px 16px rgba(0,0,0,.3);
    flex-shrink: 0;
}
@media (min-width: 480px) {
    .yb-card-photo { width: 90px; height: 90px; }
}
@media (min-width: 641px) {
    .yb-card-photo { width: 110px; height: 110px; border-width: 4px; }
}
@media (min-width: 1024px) {
    .yb-card-photo { width: 120px; height: 120px; border-width: 4px; }
}

/* ── Right column wrapper ─────────────────────────────── */
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
    padding: 6px 30px 6px 10px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
}
@media (min-width: 480px) {
    .yb-card-name-band { padding: 7px 33px 7px 12px; }
}
@media (min-width: 641px) {
    .yb-card-name-band { padding: 8px 36px 8px 13px; }
}
.yb-card-name-band::before {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 22px;
    width: 18px;
    background: rgba(255,255,255,.20);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-card-name-band::after {
    content: '';
    position: absolute;
    top: -10%; bottom: -10%;
    right: 9px;
    width: 8px;
    background: rgba(255,255,255,.10);
    transform: skewX(-14deg);
    pointer-events: none;
}
.yb-card-name {
    font-size: 11px; font-weight: 800;
    color: #FFFFFF; line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: .01em;
    position: relative; z-index: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
@media (min-width: 480px) {
    .yb-card-name { font-size: 12px; }
}
@media (min-width: 641px) {
    .yb-card-name { font-size: 14px; }
}

/* ── Card info body — white ───────────────────────────────── */
.yb-card-text {
    padding: 6px 8px 8px;
    flex: 1;
    display: flex; flex-direction: column; gap: 2px;
    background: #fff;
    overflow: hidden;
}
@media (min-width: 480px) {
    .yb-card-text { padding: 8px 10px 10px; }
}
@media (min-width: 641px) {
    .yb-card-text { padding: 10px 13px 12px; gap: 3px; }
}
.yb-card-line {
    font-size: 10px; color: #1a1a1a; line-height: 1.35; font-weight: 600;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
}
@media (min-width: 480px) {
    .yb-card-line { font-size: 11px; }
}
@media (min-width: 641px) {
    .yb-card-line { font-size: 13px; -webkit-line-clamp: 2; line-height: 1.4; }
}
.yb-card-motto {
    font-size: 10px; font-weight: 700;
    color: #5A1A8A; margin-top: 2px;
}
@media (min-width: 480px) {
    .yb-card-motto { font-size: 11px; margin-top: 3px; }
}
@media (min-width: 641px) {
    .yb-card-motto { font-size: 13px; margin-top: 4px; }
}
.yb-card-motto-text {
    font-size: 10px; font-style: italic; font-weight: 600;
    color: #1a1a1a; line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
@media (min-width: 480px) {
    .yb-card-motto-text { font-size: 11px; }
}
@media (min-width: 641px) {
    .yb-card-motto-text { font-size: 13px; -webkit-line-clamp: 3; line-height: 1.4; }
}
/* Hide address & parents on mobile — birthday + motto lang */
@media (max-width: 640px) {
    .yb-card-hide-mobile { display: none !important; }
}

.yb-section-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 14px 4px 12px; border-radius: 6px;
    font-size: 12px; font-weight: 700; letter-spacing: .02em;
    background: rgba(122,63,145,.07); color: #7A3F91;
    border: none; border-left: 4px solid #7A3F91;
    word-break: break-word; white-space: normal; line-height: 1.3;
}
@media (min-width: 641px) {
    .yb-section-badge { font-size: 14px; }
}
@media (min-width: 1024px) {
    .yb-section-badge { font-size: 15px; }
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
.yb-search-input-active { background: #fff; border-color: #E8E0F0; }
.yb-search-input-active:focus { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

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
    border-radius: 1rem;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
    position: relative;
    align-self: stretch;
    overflow: clip;
}
@media (max-width: 640px) {
    .yb-table-block { border-radius: 0.75rem; }
}
.yb-filter-bar {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.5rem 0.75rem; flex-shrink: 0;
    position: relative; z-index: 50; overflow: visible;
}
@media (min-width: 641px) {
    .yb-filter-bar { padding: 0.6rem 0.875rem; }
}
.yb-pagination-bar {
    flex-shrink: 0;
    background: #7A3F91;
    padding: 0 0.75rem; min-height: 44px;
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.375rem;
    flex-wrap: wrap; border-top: 1px solid rgba(255,255,255,.15);
    position: sticky;
    bottom: 0;
    z-index: 30;
}
@media (min-width: 641px) {
    .yb-pagination-bar { padding: 0 1rem; min-height: 48px; gap: 0.5rem; }
}
.yb-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 28px; height: 28px; padding: 0 8px;
    border-radius: 7px; font-size: 11px; font-weight: 700; transition: all .15s;
}
@media (min-width: 641px) {
    .yb-pg-btn { min-width: 32px; height: 32px; padding: 0 10px; font-size: 12px; border-radius: 8px; }
}
.yb-pg-active { background: #fff; color: #7a3f91; }
.yb-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }
.yb-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.yb-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Big BATCH banner in header ─────────────────────────── */
.yb-batch-banner {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0;
    overflow: hidden;
    border-radius: 12px;
    border: 2px solid #7A3F91;
    box-shadow: 0 2px 16px rgba(122,63,145,.18), 0 0 0 4px rgba(122,63,145,.07);
    background: #fff;
    padding: 0;
    animation: ybFadeUp .3s cubic-bezier(.4,0,.2,1) both;
}
@media (min-width: 641px) {
    .yb-batch-banner { border-radius: 14px; }
}
.yb-batch-banner-label {
    background: #7A3F91;
    color: #fff;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    padding: 0 8px;
    height: 32px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    flex-shrink: 0;
}
@media (min-width: 641px) {
    .yb-batch-banner-label { font-size: 10px; padding: 0 10px; height: 38px; }
}
.yb-batch-banner-year {
    color: #7A3F91;
    font-size: clamp(1rem, 2.2vw, 1.55rem);
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 0 14px 0 10px;
    height: 32px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    background: #fff;
    line-height: 1;
}
@media (min-width: 641px) {
    .yb-batch-banner-year { padding: 0 18px 0 14px; height: 38px; }
}

/* ── "My card" highlight ─────────────────────────────────── */
.yb-card-me {
    border-color: #C49FD8 !important;
    border-width: 2px !important;
    animation: ybMeGlowPulse 2.2s ease-in-out infinite;
}
@keyframes ybMeGlowPulse {
    0%, 100% {
        box-shadow: 0 0 0 3px rgba(196,159,216,.20), 0 6px 18px rgba(90,26,138,.28);
    }
    50% {
        box-shadow: 0 0 0 7px rgba(196,159,216,.35), 0 10px 28px rgba(90,26,138,.38);
    }
}

/* ── Default card shadow on white cards ─────────────────── */
.yb-card:not(.yb-card-me) {
    box-shadow: 0 3px 12px rgba(90,26,138,.18);
}

/* ── Root height ─────────────────────────────────────────── */
.yb-root-height {
    height: calc(100vh - 160px);
    max-height: calc(100vh - 160px);
    overflow: hidden;
}
@media (min-width: 641px) {
    .yb-root-height {
        height: calc(100vh - 180px);
        max-height: calc(100vh - 180px);
    }
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

/* ── Mobile responsiveness ──────────────────────────────── */
@media (max-width: 640px) {
    .yb-filter-bar { gap: 6px; }
    /* Search takes full row on mobile */
    .yb-filter-bar > .relative.flex-1 { min-width: 0; }
}

/* Extra-small phones */
@media (max-width: 400px) {
    .yb-filter-bar { flex-wrap: wrap; }
    .yb-filter-bar > .relative.flex-1 { width: 100%; flex: 1 1 100%; }
    .yb-dd-btn { font-size: 0.78rem; padding-left: 0.6rem; padding-right: 1.9rem; }
    .yb-card-name-band { padding: 6px 28px 6px 10px; }
}

@media (max-width: 767px) {
    html, body { overflow: hidden !important; }

    .yb-root-height {
        height: var(--yb-avail-h, 100dvh) !important;
        max-height: var(--yb-avail-h, 100dvh) !important;
        overflow: hidden !important;
    }

    .yb-mobile-subtitle { display: none; }
    .yb-mobile-header-icon { width: 2rem !important; height: 2rem !important; }
    .yb-mobile-title { font-size: 0.95rem !important; }

    .yb-filter-bar { padding: 0.4rem 0.6rem; }
    .yb-dd-btn, .yb-search-input { padding-top: 0.38rem; padding-bottom: 0.38rem; font-size: 0.8rem; }

    .yb-pagination-bar { min-height: 38px; padding: 4px 0.65rem; }
    .yb-pagination-bar p { font-size: 10px; }

    .yb-pagination-bar {
        position: fixed !important;
        bottom: 0; left: 0; right: 0;
        padding-bottom: calc(0.35rem + env(safe-area-inset-bottom, 0px));
        z-index: 200;
        border-radius: 0;
    }
    #yb-scroll {
        padding-bottom: calc(52px + env(safe-area-inset-bottom, 0px)) !important;
    }
}

/* ── Scroll area background ─────────────────────────────── */
.yb-bubble-bg {
    position: relative;
    background-color: #ffffff;
}

/* ── Extra-small: single column on phones < 380px ───────── */
@media (max-width: 380px) {
    .grid-cols-2 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
    .yb-card { min-height: 220px; }
    .yb-card-photo-wrap { min-height: 120px; padding: 12px 0 10px; }
    .yb-card-photo { width: 68px; height: 68px; }
    .yb-search-input, .yb-dd-btn { font-size: 0.78rem; }
}

/* ── Smooth card grid on all breakpoints ─────────────────── */
@media (min-width: 381px) and (max-width: 479px) {
    .yb-card { min-height: 250px; }
}

/* ── Prevent dd panel from overflowing viewport ─────────── */
@media (max-width: 640px) {
    .yb-dd-panel {
        left: auto;
        right: 0;
        max-width: calc(100vw - 1.5rem);
    }
}

/* ── Scroll area on mobile: smoother iOS-style ───────────── */
@media (max-width: 767px) {
    #yb-scroll {
        -webkit-overflow-scrolling: touch;
    }
}
</style>

    {{-- PAGE HEADER --}}
    <div class="flex flex-col gap-2 flex-shrink-0">

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                <div class="yb-mobile-header-icon w-9 h-9 sm:w-11 sm:h-11 rounded-xl sm:rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-[#7A3F91]">
                    <i class="fas fa-book-open text-white text-base sm:text-lg"></i>
                </div>
                <div class="min-w-0">
                    <h1 class="yb-mobile-title text-base sm:text-xl font-semibold tracking-tight text-gray-900 truncate" style="user-select:none;-webkit-user-select:none;">Digital Alumni Yearbook</h1>
                    <p class="yb-mobile-subtitle text-xs sm:text-sm font-semibold leading-relaxed mt-0.5 text-gray-700" style="user-select:none;-webkit-user-select:none;">
                        GRADUATES {{ $myBatch !== '' ? $myBatch : '' }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                @if($myBatch !== '')
                <div class="yb-batch-banner" aria-label="Batch {{ $myBatch }}">
                    <span class="yb-batch-banner-label">
                        <i class="fas fa-graduation-cap mr-1 sm:mr-1.5" style="font-size:8px;"></i>
                        <span class="hidden xs:inline">The </span>Pillars
                    </span>
                    <span class="yb-batch-banner-year">{{ $myBatch }}</span>
                </div>
                @endif
            </div>
        </div>

    </div>

    {{-- UNIFIED BLOCK --}}
    <div class="yb-table-block">

        {{-- FILTER BAR --}}
        <div class="yb-filter-bar flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-1 h-[32px] sm:h-[38px] rounded-xl shrink-0 font-semibold text-xs sm:text-sm uppercase tracking-wide"
                 style="color:#7a3f91;">
                Filters
            </div>

            <div class="relative flex-1 min-w-[100px] max-w-xs" wire:ignore
                 x-data="{
                    q: @js($search),
                    init() {
                        this.q = $wire.search ?? '';
                        $wire.$watch('search', v => { if (v !== this.q) this.q = v; });
                    }
                 }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                   style="color:#555555; z-index:1;"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.300ms="$wire.set('search', q)"
                       placeholder="Search…"
                       class="yb-search-input"
                       :class="{ 'yb-search-input-active': q !== '' }"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button"
                        @click="if (courseBusy) return; open = !open"
                        :class="{ 'active': $wire.course !== '', 'opacity-50 cursor-not-allowed': courseBusy }"
                        :disabled="courseBusy"
                        class="yb-dd-btn">
                    @if($course !== '')
                        <span>{{ $this->courses->firstWhere('code', $course)?->name ?? $course }}</span>
                    @else
                        <span>All Programs</span>
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
                     style="display:none; min-width:220px;">
                    <button type="button"
                            :disabled="courseBusy"
                            :class="{ 'sel': $wire.course === '', 'opacity-50 cursor-not-allowed': courseBusy }"
                            @click="
                                if (courseBusy) return;
                                open = false;
                                courseBusy = true;
                                $wire.clearCourse().then(() => { courseBusy = false; });
                            "
                            class="yb-dd-item">All Programs</button>
                    @forelse($this->courses as $c)
                    <button type="button"
                            :disabled="courseBusy"
                            :class="{ 'sel': $wire.course === '{{ $c->code }}', 'opacity-50 cursor-not-allowed': courseBusy }"
                            @click="
                                if (courseBusy) return;
                                open = false;
                                courseBusy = true;
                                $wire.setCourse('{{ $c->code }}').then(() => { courseBusy = false; });
                            "
                            class="yb-dd-item">{{ $c->name }}</button>
                    @empty
                    <p class="px-3 py-2 text-xs" style="color:#999;">No other courses in your batch yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Reset --}}
            @php $hasActiveFilters = $search !== '' || $course !== ''; @endphp
            <button wire:click="resetFilters"
                    @click="activeFilter = null"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    @disabled(!$hasActiveFilters)
                    class="ml-auto inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm font-semibold
                           border transition active:scale-95 whitespace-nowrap flex-shrink-0
                           {{ $hasActiveFilters
                                ? 'bg-white border-[#E8E0F0] text-gray-600 hover:text-gray-900 hover:border-gray-300 cursor-pointer'
                                : 'bg-gray-50 border-gray-100 text-gray-300 cursor-not-allowed' }}">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-xs sm:text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-xs sm:text-sm" style="color:#7A3F91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- LOADING SPINNER --}}
        <div class="hidden absolute inset-0 z-[9999] items-center justify-center pointer-events-none"
             wire:loading.flex wire:target="search,course,setCourse,clearCourse,resetFilters,previousPage,nextPage,gotoPage">
            <i class="fas fa-spinner fa-spin" style="font-size:36px;color:#7a3f91;"></i>
        </div>

        {{-- SCROLLABLE CARDS AREA --}}
        <div class="flex-1 min-h-0 relative yb-bubble-bg"
             x-data="{ showTop: false }">

            <div id="yb-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-scroll absolute inset-0 overflow-y-auto overflow-x-hidden p-2 sm:p-3 lg:p-4 pb-4 sm:pb-6 transition-opacity duration-200"
                 style="z-index: 1;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,course,setCourse,clearCourse,resetFilters,previousPage,nextPage,gotoPage">

                @if($this->alumniRecords->count() > 0)
                    <div class="yb-grid-wrap space-y-2"
                         wire:key="results-{{ md5($search . '|' . $course . '|' . $this->alumniRecords->currentPage()) }}">
                        @foreach($this->groupedAlumni as $courseName => $group)
                            <div wire:key="group-{{ Str::slug($courseName) }}">
                                <div class="flex items-center gap-2 pt-2 pb-2 px-1">
                                    <span class="yb-section-badge">
                                        {{ $courseName }}
                                    </span>
                                    <div class="flex-1 h-px" style="background:#D8B4FE;"></div>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2 sm:gap-3" style="--tw-gap: 0.5rem;">
                                    @foreach($group as $alumni)
                                        @php
                                            $isMe = ($myAlumniId > 0 && $alumni->id === $myAlumniId);

                                            $cardAddr = implode(', ', array_filter([
                                                $alumni->address_street ?? '',
                                                $alumni->address_barangay ?? '',
                                                $alumni->address_municipality ?? '',
                                                $alumni->address_province ?? '',
                                            ]));

                                            $fLast  = trim($alumni->father_last_name  ?? '');
                                            $mLast  = trim($alumni->mother_last_name  ?? '');
                                            $fFirst = trim($alumni->father_given_name ?? '');
                                            $mFirst = trim($alumni->mother_given_name ?? '');
                                            $fMid   = trim($alumni->father_middle_name ?? '');

                                            if ($fFirst && $fLast) {
                                                $fMiddleI = $fMid ? strtoupper(mb_substr($fMid,0,1)).'.' : '';
                                                $cardParents = ($mFirst ? 'Mr. & Mrs. ' : 'Mr. ') . $fFirst . ($fMiddleI ? ' '.$fMiddleI : '') . ' ' . $fLast;
                                            } elseif ($mFirst && $mLast) {
                                                $cardParents = 'Mrs. ' . $mFirst . ' ' . $mLast;
                                            } else {
                                                $cardParents = null;
                                            }
                                        @endphp

                                        <div wire:key="alumni-{{ $alumni->id }}"
                                             class="yb-card {{ $isMe ? 'yb-card-me' : '' }} rounded-xl overflow-hidden border flex flex-col"
                                             style="border-color: #E2D6F0;">

                                            {{-- Portrait photo --}}
                                            <div class="yb-card-photo-wrap">
                                                <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                                     alt="{{ $alumni->name }}"
                                                     class="yb-card-photo"
                                                     loading="lazy" decoding="async"
                                                     onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                            </div>

                                            {{-- Name ribbon + info --}}
                                            <div class="yb-card-right">

                                                <div class="yb-card-name-band">
                                                    <p class="yb-card-name">{{ $this->formatAlumniNameYearbook($alumni->name) }}</p>
                                                </div>

                                                <div class="yb-card-text">

                                                    @if(!empty($alumni->date_of_birth))
                                                    <p class="yb-card-line">{{ \Carbon\Carbon::parse($alumni->date_of_birth)->format('M j, Y') }}</p>
                                                    @endif

                                                    @if(!empty($cardAddr))
                                                    <p class="yb-card-line yb-card-hide-mobile">{{ ucwords(mb_strtolower($cardAddr)) }}</p>
                                                    @endif

                                                    @if($cardParents)
                                                    <p class="yb-card-line yb-card-hide-mobile">{{ ucwords(mb_strtolower($cardParents)) }}</p>
                                                    @endif

                                                    @if(!empty($alumni->motto))
                                                    <p class="yb-card-motto">Motto:
                                                        <span class="yb-card-motto-text">"{{ $alumni->motto }}"</span>
                                                    </p>
                                                    @endif

                                                </div>

                                            </div>{{-- /yb-card-right --}}

                                        </div>{{-- /yb-card --}}
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-24 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100 mb-4">
                            <i class="fas fa-book text-xl text-gray-400"></i>
                        </div>
                        <p class="font-semibold text-base" style="color:#333333;">No alumni found.</p>
                        <p class="text-sm mt-1" style="color:#555555;">Try adjusting your filters.</p>
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

        </div>{{-- /scroll wrapper --}}

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