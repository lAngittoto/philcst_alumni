{{-- resources/views/livewire/organizer/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Models\Alumni;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use Carbon\Carbon;

new class extends Component {

    public string $greeting    = '';
    public string $currentDate = '';

    // ── Stats ──
    public int $totalAlumni      = 0;
    public int $verifiedAlumni   = 0;
    public int $pendingAlumni    = 0;

    public int $totalEvents      = 0;
    public int $pendingEvents    = 0;
    public int $approvedEvents   = 0;
    public int $completedEvents  = 0;
    public int $rejectedEvents   = 0;
    public int $upcomingEvents   = 0;

    public int $totalJobs        = 0;
    public int $activeJobs       = 0;
    public int $inactiveJobs     = 0;

    public int $empEmployed      = 0;
    public int $empSelf          = 0;
    public int $empUnemployed    = 0;
    public int $empNotFilled     = 0;

    // Last 6 months of Employed-count trend, oldest → newest, for the
    // Employment card's zigzag sparkline (see loadStats() for the query).
    public array $empMonthlyTrend = [];

    // ── Course breakdown ──
    public array $courseStats = [];

    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->organizer) {
            abort(403, 'Access denied.');
        }

        $this->currentDate = now('Asia/Manila')->format('l, F j, Y');
        $hour = (int) now('Asia/Manila')->format('H');
        $this->greeting = match(true) {
            $hour < 12 => 'Good Morning',
            $hour < 17 => 'Good Afternoon',
            default    => 'Good Evening',
        };

        $this->loadStats();
    }

    #[Computed]
    public function organizerDepartment(): string
    {
        return Auth::user()?->organizer?->department ?? '';
    }

    #[Computed]
    public function organizerName(): string
    {
        return Auth::user()?->organizer?->name ?? Auth::user()?->name ?? '';
    }

    #[Computed]
    public function organizerEmail(): string
    {
        return Auth::user()?->organizer?->email ?? Auth::user()?->email ?? '';
    }

    #[Computed]
    public function organizerId(): ?int
    {
        return Auth::user()?->organizer?->id;
    }

    /**
     * ── Organizer's own profile photo (used in the dashboard banner) ──
     * Mirrors the same resolution pattern used across the app (alumni /
     * organizers / directors storage paths), falling back to the shared
     * default avatar if nothing is set or the file no longer exists.
     */
    #[Computed]
    public function organizerPhotoUrl(): string
    {
        $path    = Auth::user()?->organizer?->profile_photo;
        $default = asset('storage/alumni-photos/default.png');

        if (! $path || str_contains($path, 'default.png')) {
            return $default;
        }

        if (
            str_starts_with($path, 'organizers/')   ||
            str_starts_with($path, 'alumni-photos/') ||
            str_starts_with($path, 'directors/')
        ) {
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : $default;
        }

        return $default;
    }

    private function loadStats(): void
    {
        $dept  = $this->organizerDepartment;
        $orgId = $this->organizerId;

        // ── Alumni counts (filtered by department) ──
        $alumniBase = Alumni::whereNull('deleted_at');
        if ($dept) {
            $alumniBase->whereHas('course', fn($q) => $q->where('college', $dept));
        }

        $this->totalAlumni    = (clone $alumniBase)->count();
        $this->verifiedAlumni = (clone $alumniBase)->where('status', 'verified')->count();
        $this->pendingAlumni  = (clone $alumniBase)->where('status', 'pending')->count();

        // ── Events (this organizer's own events) ──
        $evBase = OrganizerEvent::where('organizer_id', $orgId)
            ->where('status', '!=', 'ORGANIZER_DELETED');

        $this->totalEvents     = (clone $evBase)->count();
        $this->pendingEvents   = (clone $evBase)->where('status', 'PENDING')->count();
        $this->approvedEvents  = (clone $evBase)->where('status', 'APPROVED')->count();
        $this->completedEvents = (clone $evBase)->where('status', 'COMPLETED')->count();
        $this->rejectedEvents  = (clone $evBase)->where('status', 'REJECTED')->count();

        // Upcoming = approved events whose date hasn't passed yet (Asia/Manila).
        // NOTE: assumes the events table has an `event_date` column — adjust
        // the column name below if yours is named differently.
        $this->upcomingEvents = (clone $evBase)
            ->where('status', 'APPROVED')
            ->whereDate('event_date', '>=', now('Asia/Manila')->toDateString())
            ->count();

        // ── Jobs (this organizer's own jobs) ──
        $jobBase = JobPosting::where('organizer_id', $orgId)
            ->whereNotIn('status', ['ORGANIZER_DELETED']);

        $this->totalJobs   = (clone $jobBase)->count();
        $this->activeJobs  = (clone $jobBase)->where('status', 'ACTIVE')->count();
        $this->inactiveJobs = (clone $jobBase)->where('status', 'INACTIVE')->count();

        // ── Employment tracking (dept alumni) ──
        $empQ = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');
        if ($dept) {
            $empQ->join('courses as c', 'a.course_code', '=', 'c.code')
                 ->where('c.college', $dept);
        }

        $this->empEmployed   = (clone $empQ)->where('et.employment_status', 'employed')->count();
        $this->empSelf       = (clone $empQ)->where('et.employment_status', 'self_employed')->count();
        $this->empUnemployed = (clone $empQ)->where('et.employment_status', 'unemployed')->count();

        $filled = $this->empEmployed + $this->empSelf + $this->empUnemployed;
        $this->empNotFilled = max(0, $this->totalAlumni - $filled);

        // ── Employed monthly trend (last 6 months, for the sparkline) ──
        // Buckets by the month each employment_trackings row was last
        // touched (updated_at), counting rows whose CURRENT status is
        // "employed". This is a per-month snapshot count, not a running
        // total, so the line can genuinely rise and fall month to month
        // instead of only ever climbing.
        $trendStart = now('Asia/Manila')->subMonths(5)->startOfMonth();

        $trendRowsQ = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at')
            ->where('et.employment_status', 'employed')
            ->where('et.updated_at', '>=', $trendStart);
        if ($dept) {
            $trendRowsQ->join('courses as tc', 'a.course_code', '=', 'tc.code')
                       ->where('tc.college', $dept);
        }

        $trendRows = $trendRowsQ
            ->selectRaw("DATE_FORMAT(et.updated_at, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')
            ->pluck('cnt', 'ym');

        $this->empMonthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now('Asia/Manila')->subMonths($i);
            $key = $m->format('Y-m');
            $this->empMonthlyTrend[] = [
                'label' => $m->format('M'),
                'count' => (int) ($trendRows[$key] ?? 0),
            ];
        }

        // ── Course breakdown (alumni count per course) ──
        $courseQ = DB::table('alumni as a')
            ->join('courses as c', 'a.course_code', '=', 'c.code')
            ->whereNull('a.deleted_at')
            ->select('c.code', 'c.name', DB::raw('COUNT(a.id) as alumni_count'));

        if ($dept) {
            $courseQ->where('c.college', $dept);
        }

        $this->courseStats = $courseQ
            ->groupBy('c.code', 'c.name')
            ->orderByDesc('alumni_count')
            ->get()
            ->toArray();
    }

    /**
     * ── Deep-link into Employment Tracking with a clean URL ──
     * Stashes the desired filter (status and/or course/program code) into
     * the session, then redirects to the plain employment route with no
     * query string at all. The employment page's mount() picks this up via
     * session()->pull('organizer_employment_filter') and applies it before
     * first render — so the address bar only ever shows
     * /organizer/alumni/employment, never ?course=BSED or ?status=employed,
     * and the corresponding filter dropdown shows the applied value instead
     * of staying on "All Statuses" / "All Programs".
     */
    public function goToEmployment(string $status = '', string $course = ''): void
    {
        session([
            'organizer_employment_filter' => [
                'status' => $status,
                'course' => $course,
            ],
        ]);
        session()->save();

        $this->redirect(route('organizer.alumni/employment'), navigate: true);
    }
};
?>

<div class="org-dashboard-root px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto w-full">

<style>
/* ── Stat card tooltip (desktop only — no tooltip text on mobile) ── */
.org-stat-card { position: relative; overflow: visible; }
.org-stat-card .org-card-tip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.org-stat-card .org-card-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
@media (min-width: 1024px) {
    .org-stat-card:hover .org-card-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .org-stat-card .org-card-tip { display: none !important; }
}

/* ── Mini cards (clickable stat tiles) ── */
.org-mini-card { position: relative; overflow: visible; cursor: pointer; transition: transform .12s ease, box-shadow .15s ease; }
.org-mini-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.10); }
.org-mini-card:active { transform: scale(.97); }
.org-mini-card .org-mini-tip {
    position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.org-mini-card .org-mini-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
@media (min-width: 1024px) {
    .org-mini-card:hover .org-mini-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .org-mini-card .org-mini-tip { display: none !important; }
}

/* ── Main grid — align-items: start so the profile card only takes its
     own natural height, no stretching to match the taller right column ── */
.org-main-grid { display: grid; grid-template-columns: 300px 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1023px) {
    .org-main-grid { grid-template-columns: 1fr; gap: 0.85rem; }
}

/* ── Profile column — natural height, no forced stretch ── */
.org-profile-col { display: flex; flex-direction: column; }
.org-profile-card { display: flex; flex-direction: column; }

/* ── No copy/select ANYWHERE on the dashboard — greeting header,
   stat cards, profile card, chips, tables, everything. Buttons and
   links stay fully clickable; only text selection is blocked. ── */
.org-dashboard-root,
.org-dashboard-root * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}

/* ── Right col ── */
.org-right-col { display: flex; flex-direction: column; gap: 1rem; }

/* ── 2x2 stat grid: equal height on desktop, 1-col on phone ── */
.org-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.org-stat-grid .org-stat-card { display: flex; flex-direction: column; justify-content: center; }
@media (max-width: 639px) {
    .org-stat-grid { grid-template-columns: 1fr; gap: 0.65rem; }
    .org-stat-grid .org-stat-card { padding: 1rem !important; }
    .org-stat-grid .org-stat-card .org-stat-num { font-size: 2.1rem !important; }
}
@media (min-width: 640px) and (max-width: 1023px) {
    .org-stat-grid .org-stat-card .org-stat-num { font-size: 2.4rem !important; }
}

/* ── Info rows — dark, readable text (no gray) ── */
.org-info-body { display: flex; flex-direction: column; }
.org-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.org-info-row:last-child { border-bottom: none; }
.org-info-label { font-size: 0.70rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; flex-shrink: 0; }
.org-info-value { font-size: 0.875rem; font-weight: 600; color: #111111; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 160px; }
.org-info-value-sm { font-size: 0.80rem; font-weight: 600; color: #111111; text-align: right; overflow-wrap: anywhere; word-break: normal; max-width: 180px; line-height: 1.4; }

/* ── Chips section wrapper ── */
.org-chips-section { padding: 0.65rem 1rem; }
.org-chips-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; margin-bottom: 0.4rem; }

/* ── Chips — base shape, color comes from status-specific classes below ──
     (previously all chips shared one flat lavender bg regardless of
     status, so "Rejected · 1" looked the same as "Approved · 2" — now
     each status gets its own readable color) ── */
.org-chip {
    font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px;
    display: inline-flex; align-items: center; gap: 4px;
    border: 1px solid transparent; margin: 2px 4px 2px 0;
}
.org-chip i { font-size: 7px; }

/* Event status chips */
.org-chip-approved  { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.org-chip-pending   { background: #FEF6E7; color: #B5750A; border-color: #FBE4B4; }
.org-chip-completed { background: #EAF1FE; color: #1D4ED8; border-color: #C9DBFC; }
.org-chip-rejected  { background: #FDECEC; color: #C0311A; border-color: #F8C9C2; }

/* Job status chips */
.org-chip-active   { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.org-chip-inactive { background: #F1F1F3; color: #52525B; border-color: #E1E1E5; }

/* ── Scrollbar ── */
.org-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.org-scroll::-webkit-scrollbar { width: 4px; }
.org-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── Employment Snapshot tiles — compacted so this block takes less
     vertical space (was getting cut off at the bottom on mobile) ── */
.org-emp-tile { padding: 0.45rem 0.6rem !important; }
.org-emp-tile .org-emp-num { font-size: 1.1rem !important; line-height: 1 !important; }
.org-emp-tile .org-emp-label { font-size: 0.6rem !important; margin-top: 0.15rem !important; }
.org-emp-snapshot-header { padding-top: 0.5rem !important; padding-bottom: 0.5rem !important; }

/* ── Course row (now a clickable mini-card) ── */
.org-course-row { display: block; border-radius: 0.6rem; padding: 0.3rem 0.4rem; margin: -0.3rem -0.4rem; }
.org-course-row:hover { background: #F9F5FC; }

/* ── Animations ── */
@keyframes orgFadeUp { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:none } }
.org-fade-up { animation: orgFadeUp .4s cubic-bezier(.25,.8,.25,1) both; }
.org-fade-1 { animation-delay:.04s } .org-fade-2 { animation-delay:.08s }
.org-fade-3 { animation-delay:.12s } .org-fade-4 { animation-delay:.16s }
</style>

{{-- ── PAGE HEADER ── --}}
<div class="flex items-center gap-3 mb-5 org-fade-up">
    <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <i class="fas fa-gauge-high text-white text-base"></i>
    </div>
    <div>
        <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
            {{ $greeting }}, {{ $this->organizerName }}
        </h1>
        <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
            <i class="fas fa-circle text-[5px] text-emerald-500 align-middle"></i>
            <span>{{ $currentDate }}</span>
        </p>
    </div>
</div>

{{-- ══ MAIN GRID ══════════════════════════════════════════════ --}}
@php $orgPhotoUrl = $this->organizerPhotoUrl; @endphp

<div class="org-main-grid">

    {{-- ══ LEFT: Profile Card (photo banner, natural height — no stretch) ══ --}}
    <div class="org-profile-col org-fade-up">
        <div class="org-profile-card rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm bg-white">

            {{-- Photo banner — organizer's actual profile picture, not an icon --}}
          <div class="relative w-full overflow-hidden shrink-0 h-[400px] sm:h-[240px] bg-[#EDE0F5]">
                <img src="{{ $orgPhotoUrl }}"
                     alt="{{ $this->organizerName }}"
                     class="w-full h-full object-cover object-top"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="w-full h-full items-center justify-center font-black text-white hidden text-[5rem] bg-[#7A3F91]" style="display:none;">
                    {{ strtoupper(substr($this->organizerName, 0, 1)) ?: '?' }}
                </div>
                <div class="absolute inset-0" style="background:linear-gradient(to bottom, transparent 35%, rgba(0,0,0,.65) 100%);"></div>
                <div class="absolute bottom-0 left-0 right-0 px-4 pb-4">
                    <p class="text-white font-bold uppercase leading-tight tracking-wide text-[1.1rem] sm:text-[1.15rem]"
                       style="text-shadow:0 1px 5px rgba(0,0,0,.6);">
                        {{ $this->organizerName ?: '—' }}
                    </p>
                    <p class="font-mono text-[0.78rem] sm:text-[0.8rem]" style="color:rgba(255,255,255,.75);">
                        {{ $this->organizerDepartment ?: 'Event Coordinator' }}
                    </p>
                </div>
            </div>

            <div class="org-info-body org-scroll">

                <div class="org-info-row">
                    <span class="org-info-label">Name</span>
                    <span class="org-info-value">{{ $this->organizerName }}</span>
                </div>

                <div class="org-info-row" style="align-items:flex-start;">
                    <span class="org-info-label" style="margin-top:2px;">Email</span>
                    <span class="org-info-value-sm">{{ $this->organizerEmail ?: '—' }}</span>
                </div>

                <div class="org-info-row">
                    <span class="org-info-label">College</span>
                    <span class="org-info-value text-[#7A3F91] font-bold">{{ $this->organizerDepartment ?: '—' }}</span>
                </div>

                {{-- Quick chips — Events (color-coded per status) --}}
                <div class="org-chips-section">
                    <p class="org-chips-label">Events Overview</p>
                    <div>
                        <span class="org-chip org-chip-approved"><i class="fas fa-circle"></i>Approved · {{ $approvedEvents }}</span>
                        <span class="org-chip org-chip-pending"><i class="fas fa-circle"></i>Pending · {{ $pendingEvents }}</span>
                        <span class="org-chip org-chip-completed"><i class="fas fa-circle"></i>Completed · {{ $completedEvents }}</span>
                        @if($rejectedEvents > 0)
                            <span class="org-chip org-chip-rejected"><i class="fas fa-circle"></i>Rejected · {{ $rejectedEvents }}</span>
                        @endif
                    </div>
                </div>

                {{-- Quick chips — Jobs (color-coded per status) --}}
                <div class="org-chips-section">
                    <p class="org-chips-label">Job Postings</p>
                    <div>
                        <span class="org-chip org-chip-active"><i class="fas fa-circle"></i>Active · {{ $activeJobs }}</span>
                        <span class="org-chip org-chip-inactive"><i class="fas fa-circle"></i>Inactive · {{ $inactiveJobs }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ══ RIGHT: Stats + Course Breakdown ══ --}}
    <div class="org-right-col">

        {{-- 2x2 Stat Cards --}}
        <div class="org-stat-grid org-fade-up org-fade-1">

            {{-- Total Alumni --}}
            <button type="button" wire:click="goToEmployment('', '')"
               class="org-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-[#7A3F91]/40 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
               <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Total Alumni</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                 border border-[#E8E0F0] bg-[#F9F7FC] text-[0.7rem] sm:text-[0.75rem]">Alumni</span>
                </div>
                <p class="org-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalAlumni) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Total Alumni</p>
                <p class="font-semibold mt-1 flex items-center gap-1 text-[0.85rem] text-emerald-600">
                    <i class="fas fa-circle-check text-xs"></i> {{ number_format($verifiedAlumni) }} verified
                    @if($pendingAlumni > 0)
                        <span class="text-amber-600 font-normal">· {{ $pendingAlumni }} pending</span>
                    @endif
                </p>
            </button>

            {{-- Total Events --}}
            <a href="{{ route('organizer.event/organizer') }}" wire:navigate
               class="org-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-emerald-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                 border border-emerald-200 bg-emerald-50 text-[0.7rem] sm:text-[0.75rem]">Events</span>
                </div>
                <p class="org-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Total Events</p>
                @if($upcomingEvents > 0)
                    <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-calendar-check text-xs"></i> {{ $upcomingEvents }} Upcoming Events
                    </p>
                @elseif($pendingEvents > 0)
                    <p class="text-amber-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-hourglass-half text-xs"></i> {{ $pendingEvents }} Pending
                    </p>
                @else
                    <p class="text-[#333333] font-normal mt-1 text-[0.85rem]">No active events</p>
                @endif
            </a>

            {{-- Job Postings --}}
            <a href="{{ route('organizer.job/management') }}" wire:navigate
               class="org-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-blue-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Job Postings</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-briefcase text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                 border border-blue-200 bg-blue-50 text-[0.7rem] sm:text-[0.75rem]">Jobs</span>
                </div>
                <p class="org-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalJobs) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Job Postings</p>
                <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                    <span class="text-[#333333] font-normal">· {{ $inactiveJobs }} Inactive</span>
                </p>
            </a>

            {{-- Employment — zigzag monthly trend --}}
            <button type="button" wire:click="goToEmployment('employed', '')"
               class="org-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-amber-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Employed</span>
                <div class="flex items-start justify-between mb-2 sm:mb-3">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                        <i class="fas fa-chart-line text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                 border border-amber-200 bg-amber-50 text-[0.7rem] sm:text-[0.75rem]">Employment</span>
                </div>

                <div class="flex items-end justify-between gap-2">
                    <div>
                        <p class="org-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.1rem] sm:text-[2.4rem]">{{ number_format($empEmployed) }}</p>
                        <p class="text-[#111111] font-semibold mt-1 text-[0.92rem] sm:text-[1rem]">Employed</p>
                    </div>

                    {{-- Zigzag sparkline: last 6 months of Employed counts.
                         Pure SVG, no JS charting lib needed — built from
                         $empMonthlyTrend (see loadStats() in the component). --}}
                    @php
                        $trend  = $empMonthlyTrend ?: [];
                        $counts = array_column($trend, 'count');
                        $max    = max($counts ?: [0]);
                        $max    = $max < 1 ? 1 : $max;
                        $min    = min($counts ?: [0]);
                        $n      = count($trend);
                        $w      = 108;
                        $h      = 46;
                        $padY   = 6;
                        $points = [];
                        foreach ($trend as $i => $pt) {
                            $x = $n > 1 ? ($i / ($n - 1)) * $w : $w / 2;
                            $range = ($max - $min) ?: 1;
                            $y = $padY + (1 - (($pt['count'] - $min) / $range)) * ($h - $padY * 2);
                            $points[] = [round($x, 1), round($y, 1)];
                        }
                        $polyline = implode(' ', array_map(fn($p) => "{$p[0]},{$p[1]}", $points));
                    @endphp
                    <div class="shrink-0" style="width:{{ $w }}px;">
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" width="{{ $w }}" height="{{ $h }}" fill="none">
                            @if($n > 1)
                                <polyline points="{{ $polyline }}"
                                          stroke="#D97706" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                @foreach($points as $i => $p)
                                    <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="{{ $i === $n - 1 ? 3 : 2 }}"
                                            fill="{{ $i === $n - 1 ? '#D97706' : '#F3D9A8' }}"/>
                                @endforeach
                            @endif
                        </svg>
                        <div class="flex justify-between mt-0.5">
                            @if($n > 0)
                                <span class="text-[0.58rem] text-[#999999] font-medium">{{ $trend[0]['label'] }}</span>
                                @if($n > 1)
                                    <span class="text-[0.58rem] text-[#999999] font-medium">{{ $trend[$n - 1]['label'] }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                @php $filled = $empEmployed + $empSelf + $empUnemployed; $rate = $filled > 0 ? round((($empEmployed + $empSelf) / $filled) * 100) : 0; @endphp
                <p class="text-amber-600 font-semibold mt-2 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $rate }}% emp. rate
                    @if($empNotFilled > 0)
                        <span class="text-[#333333] font-normal">· {{ $empNotFilled }} not filled</span>
                    @endif
                </p>
            </button>

        </div>

        {{-- Course Breakdown + Employment Snapshot — side-by-side cards
             (matches the compact registrar-dashboard layout: two panels in
             one row instead of stacked, so the page fits without scrolling) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 org-fade-up org-fade-2">

        {{-- Program Breakdown Panel --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
            <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center gap-2"
                 style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-ranking-star text-white text-[10px]"></i>
                </div>
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Alumni per Program</p>
            </div>

            <div class="p-3 flex-1">
                @if(count($courseStats) > 0)
                @php
                    $maxCount = max(array_column($courseStats, 'alumni_count') ?: [1]);
                    $maxCount = $maxCount < 1 ? 1 : $maxCount;
                    $palette  = ['#7A3F91','#9b59b6','#c0a0d8','#2563eb','#059669','#d97706','#ef4444','#0891b2','#65a30d','#db2777'];
                @endphp
                <div class="space-y-2">
                    @foreach($courseStats as $idx => $cs)
                    @php
                        $pct   = round(($cs->alumni_count / $maxCount) * 100);
                        $color = $palette[$idx % count($palette)];
                    @endphp
                    {{-- Clicking a program row stashes the program code into
                         the session and redirects to the plain employment
                         URL (no ?course= query string). The employment
                         page's mount() applies it immediately, and its
                         "All Programs" dropdown reflects the selected
                         program on load instead of staying unset. --}}
                    <button type="button"
                            wire:click="goToEmployment('', '{{ $cs->code }}')"
                            class="org-mini-card org-course-row w-full text-left">
                        <span class="org-mini-tip"><i class="fas fa-eye mr-1"></i>View {{ $cs->code }}</span>
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 text-[.58rem] font-bold text-white"
                                     style="background:{{ $color }};">{{ $idx + 1 }}</div>
                                <span class="text-[.78rem] font-bold text-[#333333] font-mono uppercase truncate">{{ $cs->code }}</span>
                                <span class="text-[.68rem] text-[#333333] truncate hidden xl:inline">{{ $cs->name }}</span>
                            </div>
                            <span class="text-[.78rem] font-bold text-[#333333] ml-2 flex-shrink-0">
                                {{ number_format($cs->alumni_count) }}
                                <span class="text-[#333333] font-normal text-[.68rem]">alumni</span>
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full overflow-hidden" style="background:#F0E8F8;">
                            <div class="h-full rounded-full transition-all duration-700" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                        </div>
                    </button>
                    @endforeach
                </div>

                <div class="mt-3 pt-2 border-t border-[#E8E0F0] flex items-center justify-between">
                    <p class="text-[.72rem] font-semibold text-[#7A3F91]">
                        {{ count($courseStats) }} program{{ count($courseStats) !== 1 ? 's' : '' }} total
                    </p>
                    <p class="text-[.68rem] text-[#333333] font-normal">
                        Total alumni: {{ number_format($totalAlumni) }}
                    </p>
                </div>

                @else
                <div class="py-6 text-center">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f5eef9;">
                        <i class="fas fa-users text-[#d4aaeb] text-xl"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333]">No alumni registered yet</p>
                    <p class="text-xs text-[#333333] mt-1">Alumni enrolled in {{ $this->organizerDepartment ?: 'your college' }} will appear here.</p>
                </div>
                @endif
            </div>
        </div>

        {{-- Employment Breakdown Mini-tiles --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
            <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center gap-2"
                 style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-amber-500">
                    <i class="fas fa-chart-pie text-white text-[10px]"></i>
                </div>
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Employment Snapshot</p>
            </div>
            <div class="p-3 grid grid-cols-2 gap-2 flex-1 content-start">
                @foreach([
                    ['label' => 'Employed',      'count' => $empEmployed,   'color' => 'text-emerald-700', 'bg' => 'bg-emerald-50 border-emerald-200', 'filter' => 'employed'],
                    ['label' => 'Self-Employed', 'count' => $empSelf,       'color' => 'text-blue-700',    'bg' => 'bg-blue-50 border-blue-200',       'filter' => 'self_employed'],
                    ['label' => 'Unemployed',    'count' => $empUnemployed, 'color' => 'text-amber-700',   'bg' => 'bg-amber-50 border-amber-200',     'filter' => 'unemployed'],
                    ['label' => 'Not Filled',    'count' => $empNotFilled,  'color' => 'text-[#333333]',   'bg' => 'bg-gray-50 border-gray-200',       'filter' => 'not_filled'],
                ] as $tile)
                {{-- Same session-handoff pattern as the program rows above:
                     stash the status filter, redirect to the clean
                     employment URL, filter auto-applies on load. --}}
                <button type="button"
                        wire:click="goToEmployment('{{ $tile['filter'] }}', '')"
                        class="org-mini-card org-emp-tile rounded-xl border {{ $tile['bg'] }} block w-full text-left">
                    <span class="org-mini-tip"><i class="fas fa-eye mr-1"></i>View {{ $tile['label'] }}</span>
                    <p class="org-emp-num font-extrabold leading-none {{ $tile['color'] }}">{{ number_format($tile['count']) }}</p>
                    <p class="org-emp-label font-bold text-[#333333] uppercase tracking-wide">{{ $tile['label'] }}</p>
                </button>
                @endforeach
            </div>
        </div>

        </div>{{-- end side-by-side grid --}}

    </div>
</div>

</div>