{{-- resources/views/livewire/admin/admin-dashboard.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    public array  $stats        = [];
    public array  $courseStats  = [];
    public string $greeting     = '';
    public string $adminName    = '';

    public int    $empTotal      = 0;
    public int    $empEmployed   = 0;
    public int    $empSelf       = 0;
    public int    $empUnemployed = 0;
    public int    $empNotFilled  = 0;
    public int    $empRate       = 0;
    public string $chartEmpSnapshotData = '{}';

    public int    $eventsTotal     = 0;
    public int    $eventsPending   = 0;
    public int    $eventsApproved  = 0;
    public int    $eventsCompleted = 0;
    public int    $eventsRejected  = 0;
    public string $chartEventsSnapshotData = '{}';

    public int    $jobsTotal    = 0;
    public int    $jobsActive   = 0;
    public int    $jobsInactive = 0;
    public int    $jobsExpiring = 0;
    public string $chartJobsSnapshotData = '{}';

    // ── Announcements & News carousel — latest 5 items across approved
    //    events, job postings, and courses, newest first. Each entry is a
    //    plain array (not a model) so the blade side stays framework-agnostic:
    //    ['type', 'icon', 'title', 'subtitle', 'when' (Carbon), 'badge', 'badge_color']
    public array $announcements = [];

    // Role counts for the footer strip
    public int $roleActiveDirectors    = 0;
    public int $roleActiveCoordinators = 0;
    public int $roleActiveRegistrars   = 0;
    public int $roleInactiveDirectors    = 0;
    public int $roleInactiveCoordinators = 0;
    public int $roleInactiveRegistrars   = 0;

    public function mount(): void
    {
        if (Auth::user()?->role !== 'admin') {
            $this->redirect(route('login'));
        }

        $this->adminName = 'Admin';

        $hour = now()->setTimezone('Asia/Manila')->hour;
        $this->greeting = match(true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };

        $this->loadStats();
        $this->loadCourseStats();
        $this->loadEmploymentSnapshot();
        $this->loadEventsSnapshot();
        $this->loadJobsSnapshot();
        $this->loadAnnouncements();
        $this->loadRoleCounts();
    }

    public function goToAlumni(string $filter = ''): void
    {
        session()->put('admin_alumni_filter', $filter);
        $this->redirect(route('user.management'));
    }

    public function goToEmployment(string $filter = ''): void
    {
        $mapped = match($filter) {
            'employed'      => 'employed',
            'self_employed' => 'self_employed',
            'unemployed'    => 'unemployed',
            'no_record'     => 'not_filled',
            default         => '',
        };
        session()->put('admin_employment_filter', $mapped);
        $this->redirect(route('employment.tracking'));
    }

    public function goToEvents(string $filter = ''): void
    {
        session()->put('admin_events_filter', $filter);
        $this->redirect(route('events'));
    }

    public function goToJobs(string $filter = ''): void
    {
        session()->put('admin_jobs_filter', $filter);
        $this->redirect(route('job.posts'));
    }

    public function goToUsers(string $tab = '', string $status = ''): void
    {
        session()->put('admin_users_tab', $tab);
        session()->put('admin_users_status', $status);
        $this->redirect(route('user.management'));
    }

    private function loadStats(): void
    {
        $this->stats = Cache::remember('dashboard_stats', 60, function () {
            $totalAlumni  = Alumni::count();
            $complete     = Alumni::where('profile_completed', 1)->count();
            $pending      = Alumni::where('profile_completed', 0)->count();
            $totalCourses = Course::count();
            $thisMonth    = Alumni::whereMonth('created_at', now()->month)
                                   ->whereYear('created_at',  now()->year)
                                   ->count();
            $lastMonth    = Alumni::whereMonth('created_at', now()->subMonth()->month)
                                   ->whereYear('created_at',  now()->subMonth()->year)
                                   ->count();
            $growth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0);

            return compact(
                'totalAlumni','complete','pending',
                'totalCourses','thisMonth','growth'
            );
        });
    }

    private function loadCourseStats(): void
    {
        $this->courseStats = Course::select('courses.id','courses.code','courses.name')
            ->withCount('alumni')
            ->orderByDesc('alumni_count')
            ->get()
            ->toArray();
    }

    private function loadEmploymentSnapshot(): void
    {
        $snap = Cache::remember('dashboard_emp_snapshot', 60, function () {
            $total = DB::table('alumni')->whereNull('deleted_at')->count();

            $empQ = DB::table('alumni as a')
                ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
                ->whereNull('a.deleted_at')->whereNull('et.deleted_at');

            $employed   = (clone $empQ)->where('et.employment_status', 'employed')->count();
            $selfEmp    = (clone $empQ)->where('et.employment_status', 'self_employed')->count();
            $unemployed = (clone $empQ)->where('et.employment_status', 'unemployed')->count();

            $filled    = $employed + $selfEmp + $unemployed;
            $notFilled = max(0, $total - $filled);
            $rate      = $filled > 0 ? round((($employed + $selfEmp) / $filled) * 100) : 0;

            return compact('total', 'employed', 'selfEmp', 'unemployed', 'notFilled', 'rate');
        });

        $this->empTotal      = $snap['total'];
        $this->empEmployed   = $snap['employed'];
        $this->empSelf       = $snap['selfEmp'];
        $this->empUnemployed = $snap['unemployed'];
        $this->empNotFilled  = $snap['notFilled'];
        $this->empRate       = $snap['rate'];

        $this->chartEmpSnapshotData = json_encode([
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [$this->empEmployed, $this->empSelf, $this->empUnemployed, $this->empNotFilled],
            'colors' => ['#059669', '#2563eb', '#d97706', '#9ca3af'],
        ]);
    }

    private function loadEventsSnapshot(): void
    {
        $snap = Cache::remember('dashboard_events_snapshot', 60, function () {
            $base = AdminEvent::withoutTrashed()->where('status', '!=', 'ORGANIZER_DELETED');

            return [
                'total'     => (clone $base)->count(),
                'pending'   => (clone $base)->where('status', 'PENDING')->count(),
                'approved'  => (clone $base)->where('status', 'APPROVED')->count(),
                'completed' => (clone $base)->where('status', 'COMPLETED')->count(),
                'rejected'  => (clone $base)->where('status', 'REJECTED')->count(),
            ];
        });

        $this->eventsTotal     = $snap['total'];
        $this->eventsPending   = $snap['pending'];
        $this->eventsApproved  = $snap['approved'];
        $this->eventsCompleted = $snap['completed'];
        $this->eventsRejected  = $snap['rejected'];

        $this->chartEventsSnapshotData = json_encode([
            'labels' => ['Pending', 'Approved', 'Completed', 'Rejected'],
            'data'   => [$this->eventsPending, $this->eventsApproved, $this->eventsCompleted, $this->eventsRejected],
            'colors' => ['#d97706', '#059669', '#16a34a', '#dc2626'],
        ]);
    }

    private function loadJobsSnapshot(): void
    {
        $snap = Cache::remember('dashboard_jobs_snapshot', 60, function () {
            return [
                'total'    => JobPosting::whereIn('status', ['ACTIVE', 'INACTIVE'])->count(),
                'active'   => JobPosting::where('status', 'ACTIVE')->count(),
                'inactive' => JobPosting::where('status', 'INACTIVE')->count(),
                'expiring' => JobPosting::where('status', 'ACTIVE')
                                ->whereBetween('deadline', [
                                    now('Asia/Manila')->toDateString(),
                                    now('Asia/Manila')->addDays(7)->toDateString(),
                                ])
                                ->count(),
            ];
        });

        $this->jobsTotal    = $snap['total'];
        $this->jobsActive   = $snap['active'];
        $this->jobsInactive = $snap['inactive'];
        $this->jobsExpiring = $snap['expiring'];

        $this->chartJobsSnapshotData = json_encode([
            'labels' => ['Active', 'Inactive', 'Expiring Soon'],
            'data'   => [$this->jobsActive, $this->jobsInactive, $this->jobsExpiring],
            'colors' => ['#059669', '#d97706', '#f97316'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Announcements & News — a single merged "live feed" of the 5 most
    // recent noteworthy things across the system: newly APPROVED events
    // (ranked by reviewed_at, when the director actually approved it —
    // NOT created_at/submission time), newly posted jobs (ACTIVE/INACTIVE,
    // ranked by created_at), and newly added courses (ranked by
    // created_at). Everything is normalized into the same plain-array
    // shape so the carousel markup below doesn't need to branch on model
    // type — it just reads ->type to pick an icon/badge color.
    //
    // Capped at 5 total (not 5 per type) — whichever 5 rows are the most
    // recent overall, mixed freely across the three sources.
    // ─────────────────────────────────────────────────────────────────────
    private function loadAnnouncements(): void
    {
        $this->announcements = Cache::remember('dashboard_announcements_feed', 60, function () {
            $items = collect();

            AdminEvent::withoutTrashed()
                ->where('status', 'APPROVED')
                ->whereNotNull('reviewed_at')
                ->orderByDesc('reviewed_at')
                ->limit(5)
                ->get(['id', 'title', 'reviewed_at'])
                ->each(function ($event) use ($items) {
                    $items->push([
                        'type'        => 'event',
                        'icon'        => 'calendar-check',
                        'badge'       => 'Event Approved',
                        'badge_color' => '#059669',
                        'title'       => $event->title ?: 'Untitled Event',
                        'subtitle'    => 'Approved and now visible to alumni',
                        'when'        => $event->reviewed_at,
                    ]);
                });

            JobPosting::whereIn('status', ['ACTIVE', 'INACTIVE'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'job_title', 'company_name', 'created_at'])
                ->each(function ($job) use ($items) {
                    $items->push([
                        'type'        => 'job',
                        'icon'        => 'briefcase',
                        'badge'       => 'Job Posted',
                        'badge_color' => '#7a3f91',
                        'title'       => $job->job_title ?: 'Untitled Job',
                        'subtitle'    => $job->company_name ?: 'A new opportunity was posted',
                        'when'        => $job->created_at,
                    ]);
                });

            Course::orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'code', 'name', 'created_at'])
                ->each(function ($course) use ($items) {
                    $items->push([
                        'type'        => 'course',
                        'icon'        => 'book',
                        'badge'       => 'New Course',
                        'badge_color' => '#2563eb',
                        'title'       => $course->code ?: 'New Course',
                        'subtitle'    => $course->name ?: 'A new course was added',
                        'when'        => $course->created_at,
                    ]);
                });

            return $items
                ->sortByDesc(fn ($row) => $row['when']?->timestamp ?? 0)
                ->take(5)
                ->values()
                ->map(function ($row) {
                    $row['when_human'] = $row['when']
                        ? $row['when']->setTimezone('Asia/Manila')->diffForHumans()
                        : '';
                    unset($row['when']); // Carbon instance not needed past this point — keep the array plain/serializable
                    return $row;
                })
                ->toArray();
        });
    }

    private function loadRoleCounts(): void
    {
        $snap = Cache::remember('dashboard_role_counts', 60, function () {
            return [
                'dirActive'      => DB::table('director')->where('status', 'ACTIVE')->whereNull('deleted_at')->count(),
                'dirInactive'    => DB::table('director')->where('status', 'INACTIVE')->whereNull('deleted_at')->count(),
                'coordActive'    => DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->count(),
                'coordInactive'  => DB::table('organizer')->where('status', 'INACTIVE')->whereNull('deleted_at')->count(),
                'regActive'      => DB::table('users')->where('role', 'registrar')->where('user_status', 'ACTIVE')->count(),
                'regInactive'    => DB::table('users')->where('role', 'registrar')->where('user_status', 'INACTIVE')->count(),
            ];
        });

        $this->roleActiveDirectors      = $snap['dirActive'];
        $this->roleInactiveDirectors    = $snap['dirInactive'];
        $this->roleActiveCoordinators   = $snap['coordActive'];
        $this->roleInactiveCoordinators = $snap['coordInactive'];
        $this->roleActiveRegistrars     = $snap['regActive'];
        $this->roleInactiveRegistrars   = $snap['regInactive'];
    }
}; ?>

<div class="adm-root">

<style>
/* ══════════════════════════════════════════════
   ROOT — page now scrolls naturally, no fixed-height lock
   ══════════════════════════════════════════════ */
.adm-root { display: flex; flex-direction: column; min-height: 100%; }

/* ── Tooltips (shared) — DESKTOP ONLY, hidden entirely on mobile/touch ── */
.adm-tip-wrap { position: relative; overflow: visible; }
.adm-tip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 12px; font-weight: 700; letter-spacing: 0.05em;
    padding: 6px 12px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.adm-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
@media (hover: hover) and (pointer: fine) {
    .adm-tip-wrap:hover .adm-tip { opacity: 1; }
}

/* ── Overlay tooltip (fixed-position, never clipped) — DESKTOP ONLY ── */
#adm-overlay-tip {
    position: fixed;
    background: #000; color: #fff; font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
    padding: 5px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; z-index: 99999;
    transition: opacity 0.12s;
    opacity: 0;
}
#adm-overlay-tip.visible { opacity: 1; }
#adm-overlay-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
.adm-mini-tip-wrap { position: relative; overflow: visible; }
.adm-mini-tip {
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.adm-mini-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
@media (hover: hover) and (pointer: fine) {
    .adm-mini-tip-wrap:hover .adm-mini-tip { opacity: 1; }
}
/* Touch devices (phones/tablets): tooltips fully disabled, never take space */
@media (hover: none), (pointer: coarse) {
    .adm-tip, .adm-mini-tip, #adm-overlay-tip { display: none !important; }
}
/* Fallback: also hide on narrow/mobile-sized viewports regardless of hover/pointer detection */
@media (max-width: 767px) {
    .adm-tip, .adm-mini-tip, #adm-overlay-tip { display: none !important; }
}

/* ── Cards ── */
.adm-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 14px; }

.adm-panel-head {
    padding: 12px 18px; border-bottom: 1px solid #E8E0F0;
    background: #ffffff;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    flex-shrink: 0;
}
.adm-panel-ttl { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #000000; line-height: 1.2; }
.adm-panel-sub { font-size: .68rem; color: #555555; font-weight: 500; margin-top: 3px; }

/* ── Scrollbar ── */
.adm-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.adm-scroll::-webkit-scrollbar { width: 5px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── KPI Stat grid — icon LEFT, text RIGHT ── */
.adm-stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; flex-shrink: 0; }
@media (max-width: 900px) { .adm-stat-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px) { .adm-stat-grid { grid-template-columns: 1fr; } }

.adm-stat-card {
    background: #ffffff;
    border: 1px solid #E8E0F0;
    border-radius: 12px; padding: 14px 16px; position: relative; overflow: visible;
    display: flex; flex-direction: row; align-items: center; gap: 12px;
    cursor: pointer;
    transition: box-shadow .15s, border-color .15s;
}
.adm-stat-card:hover { border-color: #c4b5d4; box-shadow: 0 3px 10px rgba(122,63,145,.10); }

/* Large icon on the left */
.adm-stat-icon-lg {
    width: 50px; height: 50px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.adm-stat-icon-lg i { font-size: 1.3rem; }

/* Text block on the right */
.adm-stat-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.adm-stat-num { font-size: 1.875rem; font-weight: 700; line-height: 1; letter-spacing: -.01em; color: #000000; }
.adm-stat-lbl { font-size: .875rem; font-weight: 500; margin-top: 4px; color: #333333; }
.adm-stat-sub { font-size: .75rem; font-weight: 500; margin-top: 4px; color: #555555; }

/* ── Body grid: courses (left) + snapshots (right) ── */
.adm-body-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
    align-items: start;
}
@media (max-width: 1023px) {
    .adm-body-grid { grid-template-columns: 1fr; }
}

.adm-panel-col { display: flex; flex-direction: column; gap: 0.75rem; }
.adm-panel-body-scroll { max-height: 560px; overflow-y: auto; }
@media (max-width: 1023px) {
    .adm-panel-body-scroll { max-height: 420px; }
}

/* ── Role counts strip — now bigger, more detailed cards ── */
.adm-role-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    flex-shrink: 0;
}
@media (max-width: 640px) {
    .adm-role-strip { grid-template-columns: 1fr; }
}
.adm-role-tile {
    display: flex; flex-direction: column; gap: 8px;
    padding: 14px 16px; border-radius: 12px;
    border: 1px solid #E8E0F0; background: #fff;
    cursor: pointer; transition: background .12s, border-color .12s, box-shadow .12s;
}
.adm-role-tile:hover { background: #FBF7FD; border-color: #d4b8e8; box-shadow: 0 3px 10px rgba(122,63,145,.10); }
.adm-role-tile-top { display: flex; align-items: center; gap: 10px; }
.adm-role-tile-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.adm-role-tile-icon i { font-size: .85rem !important; }
.adm-role-tile-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #333; }
.adm-role-tile-total { font-size: 1.05rem; font-weight: 700; color: #000; line-height: 1; margin-top: 2px; }
.adm-role-tile-counts { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.adm-role-tile-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .75rem; font-weight: 600; padding: 3px 9px; border-radius: 999px; border: 1px solid;
}

/* ── Snapshot stack (right column) — no charts, bigger detail tiles ── */
.adm-snap-stack {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
}

.adm-snap-card {
    cursor: pointer;
    display: flex;
    flex-direction: column;
}

.adm-snap-row {
    padding: 14px 16px 16px;
}

.adm-snap-mini-tiles {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
}
@media (max-width: 480px) {
    .adm-snap-mini-tiles { grid-template-columns: 1fr; }
}
.adm-snap-mini-tile {
    border-radius: 10px; padding: 10px 12px; border: 1px solid #E8E0F0; background: #FAFBFF;
    cursor: pointer; transition: background .12s, border-color .12s;
}
.adm-snap-mini-tile:hover { background: #F0E6F8; border-color: #d4b8e8; }
.adm-snap-mini-num { font-size: 1.5rem; font-weight: 700; color: #000000; line-height: 1; }
.adm-snap-mini-lbl { font-size: .75rem; font-weight: 600; color: #333333; text-transform: uppercase; letter-spacing: .04em; margin-top: 4px; }

/* Snap panel head — no purple icon, just text */
.adm-snap-head-text { display: flex; flex-direction: column; }

/* ── Bar chart container (Chart.js canvas) inside snapshot cards ── */
.adm-snap-chart-box {
    width: 100%; height: 150px; position: relative;
    margin-bottom: 12px;
}
@media (max-width: 480px) {
    .adm-snap-chart-box { height: 130px; }
}

/* ── Course list — bigger, more detail ── */
.adm-course-row { padding: 12px 0; }
.adm-course-code { font-size: 1rem !important; }
.adm-course-count { font-size: .875rem !important; }
.adm-course-name { font-size: .8rem !important; margin-top: 4px !important; }
.adm-course-badge { width: 26px !important; height: 26px !important; font-size: .75rem !important; }
.adm-course-bar { height: 8px !important; }

/* ── Announcements & News — swipeable carousel ── */
.adm-announce-card {
    background: #fff;
    border: 1px solid #E8E0F0;
    border-radius: 14px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: 600px; /* sane fallback before JS syncHeight() takes over */
}
.adm-announce-live {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: #dc2626; flex-shrink: 0;
}
.adm-announce-live-dot {
    width: 6px; height: 6px; border-radius: 999px; background: #dc2626;
    animation: adm-live-pulse 1.6s ease-in-out infinite;
}
@keyframes adm-live-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .35; transform: scale(.8); }
}
.adm-announce-track {
    display: flex;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    flex: 1 1 auto;
    /* select/click affordances intentionally suppressed — this is a
       display-only "live feed" strip, not a set of clickable cards */
    user-select: none;
    cursor: grab;
}
.adm-announce-track:active { cursor: grabbing; }
.adm-announce-track::-webkit-scrollbar { display: none; }
.adm-announce-slide {
    flex: 0 0 100%;
    scroll-snap-align: start;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 7px;
    min-height: 140px;
    height: 100%;
    pointer-events: none; /* whole slide is inert — swiping happens on the track itself */
}
.adm-announce-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg,#7A3F91,#9b59b6);
    margin-bottom: 2px;
}
.adm-announce-icon i { color: #fff; font-size: 1.1rem; }
.adm-announce-badge {
    display: inline-flex; align-items: center;
    font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 3px 10px; border-radius: 999px; border: 1px solid;
}
.adm-announce-title { font-size: .9rem; font-weight: 700; color: #000000; }
.adm-announce-desc { font-size: .78rem; color: #666666; font-weight: 500; max-width: 320px; }
.adm-announce-time { font-size: .7rem; color: #999999; font-weight: 600; margin-top: 2px; }
.adm-announce-dots {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px 0 14px;
    flex-shrink: 0;
}
.adm-announce-dot {
    width: 6px; height: 6px; border-radius: 999px;
    background: #E0D4EC;
    transition: background .15s, width .15s;
}
.adm-announce-dot.active {
    background: #7A3F91;
    width: 18px;
    border-radius: 999px;
}

[x-cloak] { display:none !important }
</style>

{{-- ══ PAGE HEADER ══ --}}
<div class="flex items-center justify-between gap-3 mb-3 shrink-0">
    <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold leading-tight" style="color:#000000;">
                {{ $greeting }}, {{ $adminName }}
            </h1>
            <p class="text-sm font-normal flex flex-wrap items-center gap-x-1.5 mt-0.5">
                <i class="fas fa-circle text-[5px] text-emerald-500 align-middle"></i>
                <span style="color:#000000;">{{ now()->setTimezone('Asia/Manila')->format('l, F j, Y · g:i A') }}</span>
                <span class="text-[#c0a0d8]">·</span>
                <span class="font-semibold text-[#7A3F91]">Admin Panel</span>
            </p>
        </div>
    </div>
</div>

{{-- ══ KPI STRIP — icon left, text right ══ --}}
<div class="adm-stat-grid">

    <div wire:click="goToAlumni" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View All Alumni</span>
        <div class="adm-stat-icon-lg" style="background:linear-gradient(135deg,#6d2f84,#9b59b6);">
            <i class="fas fa-users text-white"></i>
        </div>
        <div class="adm-stat-text">
            <div class="adm-stat-num">{{ number_format($stats['totalAlumni'] ?? 0) }}</div>
            <div class="adm-stat-lbl">Total Alumni</div>
            <div class="adm-stat-sub">{{ $stats['totalCourses'] ?? 0 }} courses · {{ $stats['totalAlumni'] > 0 ? round((($stats['complete']??0)/$stats['totalAlumni'])*100) : 0 }}% complete</div>
        </div>
    </div>

    <div wire:click="goToAlumni('complete')" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-circle-check mr-1.5"></i>View Complete Profiles</span>
        <div class="adm-stat-icon-lg" style="background:linear-gradient(135deg,#027a4f,#059669);">
            <i class="fas fa-circle-check text-white"></i>
        </div>
        <div class="adm-stat-text">
            <div class="adm-stat-num">{{ number_format($stats['complete'] ?? 0) }}</div>
            <div class="adm-stat-lbl">Complete</div>
            <div class="adm-stat-sub">{{ $stats['totalAlumni'] > 0 ? round((($stats['complete']??0)/$stats['totalAlumni'])*100) : 0 }}% of total</div>
        </div>
    </div>

    <div wire:click="goToAlumni('pending')" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-clock mr-1.5"></i>Review Pending Profiles</span>
        <div class="adm-stat-icon-lg" style="background:linear-gradient(135deg,#b55a05,#d97706);">
            <i class="fas fa-clock text-white"></i>
        </div>
        <div class="adm-stat-text">
            <div class="adm-stat-num">{{ number_format($stats['pending'] ?? 0) }}</div>
            <div class="adm-stat-lbl">Pending</div>
            <div class="adm-stat-sub">{{ $stats['totalAlumni'] > 0 ? round((($stats['pending']??0)/$stats['totalAlumni'])*100) : 0 }}% of total</div>
        </div>
    </div>

    <div wire:click="goToAlumni('this_month')" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-calendar-plus mr-1.5"></i>View New Registrations</span>
        <div class="adm-stat-icon-lg" style="background:linear-gradient(135deg,#1a4db5,#2563eb);">
            <i class="fas fa-calendar-plus text-white"></i>
        </div>
        <div class="adm-stat-text">
            <div class="adm-stat-num">{{ number_format($stats['thisMonth'] ?? 0) }}</div>
            <div class="adm-stat-lbl">This Month</div>
            <div class="adm-stat-sub">{{ ($stats['growth'] ?? 0) >= 0 ? '+' : '' }}{{ $stats['growth'] ?? 0 }}% vs last mo.</div>
        </div>
    </div>

</div>

{{-- ══ BODY: Courses + Role Strip (left) + Snapshots (right) ══ --}}
<div class="adm-body-grid mt-3">

    {{-- ── LEFT: Role strip (TOP) + All Courses (BELOW) stacked ── --}}
    <div class="adm-panel-col">

        {{-- ── Role counts strip — bigger, more detailed cards ── --}}
        <div class="adm-role-strip">
            @php
                $dirTotal   = $roleActiveDirectors + $roleInactiveDirectors;
                $coordTotal = $roleActiveCoordinators + $roleInactiveCoordinators;
                $regTotal   = $roleActiveRegistrars + $roleInactiveRegistrars;
            @endphp

            {{-- Directors --}}
            <div wire:click="goToUsers('director')" class="adm-role-tile adm-tip-wrap">
                <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Directors</span>
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#4f46e5,#6366f1);">
                        <i class="fas fa-user-tie text-white"></i>
                    </div>
                    <div>
                        <span class="adm-role-tile-label">Directors</span>
                        <div class="adm-role-tile-total">{{ $dirTotal }} total</div>
                    </div>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleActiveDirectors }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleInactiveDirectors }} Inactive
                    </span>
                </div>
            </div>

            {{-- Coordinators --}}
            <div wire:click="goToUsers('coordinator')" class="adm-role-tile adm-tip-wrap">
                <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Coordinators</span>
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users-gear text-white"></i>
                    </div>
                    <div>
                        <span class="adm-role-tile-label">Coordinators</span>
                        <div class="adm-role-tile-total">{{ $coordTotal }} total</div>
                    </div>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleActiveCoordinators }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleInactiveCoordinators }} Inactive
                    </span>
                </div>
            </div>

            {{-- Registrars --}}
            <div wire:click="goToUsers('registrar')" class="adm-role-tile adm-tip-wrap">
                <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Registrars</span>
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#059669,#10b981);">
                        <i class="fas fa-user-clock text-white"></i>
                    </div>
                    <div>
                        <span class="adm-role-tile-label">Registrars</span>
                        <div class="adm-role-tile-total">{{ $regTotal }} total</div>
                    </div>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleActiveRegistrars }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[5px]"></i>{{ $roleInactiveRegistrars }} Inactive
                    </span>
                </div>
            </div>
        </div>

        {{-- All Courses — now BELOW the role strip ── --}}
        <div class="adm-card" style="display:flex; flex-direction:column; min-height:0; flex:0 1 auto; overflow:hidden;">
            <div class="adm-panel-head">
                <div class="flex items-center gap-2">
                    <div>
                        <p class="adm-panel-ttl">All Courses</p>
                        <p class="adm-panel-sub">Alumni count per course</p>
                    </div>
                </div>
            </div>

            <div class="adm-panel-body-scroll adm-scroll">
                <div class="p-3 space-y-2.5">
                    @php
                        $maxAlumni = max(array_column($courseStats, 'alumni_count') ?: [1]);
                        $maxAlumni = $maxAlumni < 1 ? 1 : $maxAlumni;
                        $palette   = ['#7A3F91','#9b59b6','#c0a0d8','#2563eb','#059669','#d97706','#ef4444','#0891b2','#65a30d','#db2777'];
                    @endphp
                    @forelse($courseStats as $idx => $cs)
                    @php
                        $pct   = round(($cs['alumni_count'] / $maxAlumni) * 100);
                        $color = $palette[$idx % count($palette)];
                    @endphp
                    <div class="adm-course-row">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="adm-course-badge w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0 font-bold text-white"
                                     style="background:{{ $color }};">{{ $idx + 1 }}</div>
                                <span class="adm-course-code font-semibold font-mono uppercase truncate" style="color:#000000;">{{ $cs['code'] }}</span>
                            </div>
                            <span class="adm-course-count font-bold ml-2 flex-shrink-0" style="color:#000000;">{{ $cs['alumni_count'] }} alumni</span>
                        </div>
                        <div class="adm-course-bar w-full rounded-full overflow-hidden" style="background:#F0E8F8;">
                            <div class="h-full rounded-full transition-all duration-700" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                        </div>
                        <p class="adm-course-name mt-1" style="color:#555555;">{{ $cs['name'] }}</p>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <div class="w-10 h-10 rounded-2xl flex items-center justify-center mx-auto mb-2" style="background:#f5eef9;">
                            <i class="fas fa-book text-[#d4aaeb] text-lg"></i>
                        </div>
                        <p class="text-sm font-semibold" style="color:#000000;">No courses yet</p>
                    </div>
                    @endforelse
                </div>
            </div>

            @if(count($courseStats) > 0)
            <div class="px-4 py-2 border-t border-[#E8E0F0] bg-white flex items-center justify-between shrink-0">
                <p class="text-xs font-semibold text-[#7A3F91]">
                    {{ $stats['totalCourses'] ?? 0 }} courses
                    @if(count($courseStats) > 4)
                        <span class="text-[#c0a0d8] font-normal">· scroll for more</span>
                    @endif
                </p>
                <p class="text-xs font-normal" style="color:#555555;">
                    Alumni: {{ number_format($stats['totalAlumni'] ?? 0) }}
                </p>
            </div>
            @endif
        </div>

        {{-- Announcements & News — auto-advancing, swipeable "live feed"
             carousel (max 5 slides, newest first). Deliberately NOT
             clickable anywhere in this card — pure display, like a small
             live-TV strip, not a navigation control. --}}
        <div class="adm-announce-card" id="admAnnounceCard">
            <div class="adm-panel-head">
                <div>
                    <p class="adm-panel-ttl">Announcements &amp; News</p>
                    <p class="adm-panel-sub">Swipe to see more</p>
                </div>
                <span class="adm-announce-live">
                    <span class="adm-announce-live-dot"></span>Live
                </span>
            </div>

            <div class="adm-announce-track" id="admAnnounceTrack" oncontextmenu="return false;">
                @forelse($announcements as $item)
                <div class="adm-announce-slide" draggable="false">
                    <div class="adm-announce-icon" style="background:linear-gradient(135deg,{{ $item['badge_color'] }},{{ $item['badge_color'] }}cc);">
                        <i class="fas fa-{{ $item['icon'] }}"></i>
                    </div>
                    <span class="adm-announce-badge" style="color:{{ $item['badge_color'] }};border-color:{{ $item['badge_color'] }}33;background:{{ $item['badge_color'] }}14;">
                        {{ $item['badge'] }}
                    </span>
                    <p class="adm-announce-title">{{ $item['title'] }}</p>
                    <p class="adm-announce-desc">{{ $item['subtitle'] }}</p>
                    <p class="adm-announce-time">{{ $item['when_human'] }}</p>
                </div>
                @empty
                <div class="adm-announce-slide">
                    <div class="adm-announce-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <p class="adm-announce-title">Coming Soon</p>
                    <p class="adm-announce-desc">Announcements and news updates will appear here.</p>
                </div>
                @endforelse
            </div>

            <div class="adm-announce-dots" id="admAnnounceDots">
                @forelse($announcements as $idx => $item)
                    <span class="adm-announce-dot @if($idx === 0) active @endif" data-idx="{{ $idx }}"></span>
                @empty
                    <span class="adm-announce-dot active" data-idx="0"></span>
                @endforelse
            </div>
        </div>

    </div>{{-- /adm-panel-col --}}

    {{-- ── RIGHT: Snapshot Stack (Employment / Events / Jobs) ── --}}
    <div class="adm-snap-stack adm-scroll">

        {{-- Employment Snapshot --}}
        <div wire:click="goToEmployment" class="adm-card adm-snap-card">
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Employment</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-chart-box" wire:ignore>
                    <canvas id="adm_barEmpSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    {{-- Employed --}}
                    <div wire:click.stop="goToEmployment('employed')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Employed</span>
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($empEmployed) }}</p>
                        <p class="adm-snap-mini-lbl">Employed</p>
                    </div>
                    {{-- Self-Employed --}}
                    <div wire:click.stop="goToEmployment('self_employed')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Self-Employed</span>
                        <p class="adm-snap-mini-num" style="color:#2563eb;">{{ number_format($empSelf) }}</p>
                        <p class="adm-snap-mini-lbl">Self-Employed</p>
                    </div>
                    {{-- Unemployed --}}
                    <div wire:click.stop="goToEmployment('unemployed')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Unemployed</span>
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($empUnemployed) }}</p>
                        <p class="adm-snap-mini-lbl">Unemployed</p>
                    </div>
                    {{-- Not Filled --}}
                    <div wire:click.stop="goToEmployment('no_record')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Not Filled</span>
                        <p class="adm-snap-mini-num" style="color:#9ca3af;">{{ number_format($empNotFilled) }}</p>
                        <p class="adm-snap-mini-lbl">Not Filled</p>
                    </div>
                </div>
                <p class="text-[.78rem] font-semibold mt-3" style="color:#7A3F91;">
                    {{ $empRate }}% employment rate ({{ number_format($empTotal) }} total alumni)
                </p>
            </div>
        </div>

        {{-- Events Snapshot --}}
        <div id="admEventsSnapCard" wire:click="goToEvents" class="adm-card adm-snap-card">
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Events</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-chart-box" wire:ignore>
                    <canvas id="adm_barEventsSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    <div wire:click.stop="goToEvents('PENDING')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Pending</span>
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($eventsPending) }}</p>
                        <p class="adm-snap-mini-lbl">Pending</p>
                    </div>
                    <div wire:click.stop="goToEvents('APPROVED')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Approved</span>
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($eventsApproved) }}</p>
                        <p class="adm-snap-mini-lbl">Approved</p>
                    </div>
                    <div wire:click.stop="goToEvents('COMPLETED')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Completed</span>
                        <p class="adm-snap-mini-num" style="color:#16a34a;">{{ number_format($eventsCompleted) }}</p>
                        <p class="adm-snap-mini-lbl">Completed</p>
                    </div>
                    <div wire:click.stop="goToEvents('REJECTED')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Rejected</span>
                        <p class="adm-snap-mini-num" style="color:#dc2626;">{{ number_format($eventsRejected) }}</p>
                        <p class="adm-snap-mini-lbl">Rejected</p>
                    </div>
                </div>
                <p class="text-[.78rem] font-semibold mt-3" style="color:#7A3F91;">
                    {{ number_format($eventsTotal) }} total events
                </p>
            </div>
        </div>

        {{-- Jobs Snapshot --}}
        <div id="admJobsSnapCard" wire:click="goToJobs" class="adm-card adm-snap-card">
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Job Postings</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-chart-box" wire:ignore>
                    <canvas id="adm_barJobsSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    <div wire:click.stop="goToJobs('ACTIVE')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Active</span>
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($jobsActive) }}</p>
                        <p class="adm-snap-mini-lbl">Active</p>
                    </div>
                    <div wire:click.stop="goToJobs('INACTIVE')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Inactive</span>
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($jobsInactive) }}</p>
                        <p class="adm-snap-mini-lbl">Inactive</p>
                    </div>
                    <div wire:click.stop="goToJobs('EXPIRING')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Expiring Soon</span>
                        <p class="adm-snap-mini-num" style="color:#f97316;">{{ number_format($jobsExpiring) }}</p>
                        <p class="adm-snap-mini-lbl">Expiring Soon</p>
                    </div>
                    <div wire:click.stop="goToJobs" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View All Postings</span>
                        <p class="adm-snap-mini-num" style="color:#7a3f91;">{{ number_format($jobsTotal) }}</p>
                        <p class="adm-snap-mini-lbl">Total Postings</p>
                    </div>
                </div>
            </div>
        </div>

    </div>{{-- end snapshot stack --}}

</div>{{-- end body grid --}}

{{-- ══ CHART DATA BRIDGE ══ --}}
<div id="__dash_chart_data" style="display:none"
     data-empsnapshot="{{ $chartEmpSnapshotData }}"
     data-eventssnapshot="{{ $chartEventsSnapshotData }}"
     data-jobssnapshot="{{ $chartJobsSnapshotData }}">
</div>

<script>
(function(){
    'use strict';

    var registry = {};

    function loadChartJs(cb){
        if(window.Chart){ cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function bridge(){
        var el = document.getElementById('__dash_chart_data');
        if(!el) return null;
        try {
            return {
                empSnapshot:    JSON.parse(el.getAttribute('data-empsnapshot')    || 'null'),
                eventsSnapshot: JSON.parse(el.getAttribute('data-eventssnapshot') || 'null'),
                jobsSnapshot:   JSON.parse(el.getAttribute('data-jobssnapshot')   || 'null'),
            };
        } catch(e){ return null; }
    }

    function kill(id){
        // Ask Chart.js itself first. Our local `registry` object gets
        // wiped and recreated every time this <script> block re-executes
        // (e.g. on wire:navigate swapping in fresh page HTML), but Chart.js
        // still remembers a chart attached to a canvas element from a
        // PREVIOUS run — registry[id] alone can't see that. Trusting only
        // the local registry is what let bar() throw "Canvas is already
        // in use" during navigation.
        var c = document.getElementById(id);
        var existing = (c && typeof Chart !== 'undefined' && Chart.getChart) ? Chart.getChart(c) : null;
        if(existing){ existing.destroy(); }
        else if(registry[id]){ registry[id].destroy(); }
        delete registry[id];
    }
    function allZero(arr){ return !arr || arr.every(function(v){ return !v || v === 0; }); }

    function bar(id, data){
        if(!data || !data.labels || allZero(data.data)){ kill(id); return; }
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderRadius: 6,
                    maxBarThickness: 34,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500, easing: 'easeInOutQuart' },
                layout: { padding: { right: 10 } },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#F0E8F8' },
                        ticks: { font: { size: 11 }, color: '#555555', precision: 0 }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { font: { size: 12, weight: '600' }, color: '#111111' }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        padding: 10,
                        bodyFont: { size: 12, weight: '600' },
                        callbacks: {
                            title: function(){ return ''; },
                            label: function(ctx){
                                var t = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                                var p = t ? Math.round(ctx.parsed.x / t * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed.x.toLocaleString() + ' (' + p + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    var initAllRunning = false;
    var initAllQueued  = false;

    function initAll(){
        // Guard against overlapping runs: the Livewire commit hook and the
        // livewire:navigated listener can both schedule initAll() via
        // requestAnimationFrame close together (e.g. right after a
        // wire:navigate page swap), letting two "new Chart()" calls race
        // on the same canvas. Queue the second run instead of letting it
        // run concurrently.
        if(initAllRunning){ initAllQueued = true; return; }
        initAllRunning = true;

        try{
            runInitAll();
        } finally {
            initAllRunning = false;
            if(initAllQueued){
                initAllQueued = false;
                requestAnimationFrame(initAll);
            }
        }
    }

    function runInitAll(){
        var d = bridge(); if(!d) return;
        bar('adm_barEmpSnapshot',    d.empSnapshot);
        bar('adm_barEventsSnapshot', d.eventsSnapshot);
        bar('adm_barJobsSnapshot',   d.jobsSnapshot);
    }

    loadChartJs(function(){
        if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }
        document.addEventListener('livewire:navigated', function(){
            kill('adm_barEmpSnapshot');
            kill('adm_barEventsSnapshot');
            kill('adm_barJobsSnapshot');
            requestAnimationFrame(initAll);
        });
        if(window.Livewire){
            Livewire.hook('commit', function(p){
                var ok = p.succeed || (p.component && p.respond);
                if(typeof ok === 'function'){ ok(function(){ requestAnimationFrame(initAll); }); }
                else { requestAnimationFrame(initAll); }
            });
        } else {
            document.addEventListener('livewire:initialized', function(){
                Livewire.hook('commit', function(p){
                    var ok = p.succeed || function(cb){ cb({}); };
                    ok(function(){ requestAnimationFrame(initAll); });
                });
            });
        }
    });
})();
</script>

<script>
(function(){
    'use strict';

    // ── Announcements & News — live-feed carousel ──────────────────────
    // Auto-advances every few seconds through up to 5 slides, newest
    // first. Manual swipe (touch AND mouse-drag) also works and briefly
    // pauses auto-advance so it doesn't fight the person's own swipe.
    // Deliberately has NO click/navigate behavior anywhere — this is a
    // passive "live show" strip, not a set of links.
    var AUTO_MS   = 4000;   // time between auto-advances
    var RESUME_MS = 6000;   // how long a manual swipe pauses auto-advance

    var timer        = null;
    var resumeTimer  = null;
    var dragging     = false;
    var dragStartX   = 0;
    var dragStartScroll = 0;
    var boundTrack   = null;

    function track(){ return document.getElementById('admAnnounceTrack'); }
    function dotsWrap(){ return document.getElementById('admAnnounceDots'); }

    function slideCount(){
        var t = track();
        return t ? t.children.length : 0;
    }

    function currentIndex(){
        var t = track();
        if(!t || !t.clientWidth) return 0;
        return Math.round(t.scrollLeft / t.clientWidth);
    }

    function goTo(idx){
        var t = track();
        if(!t) return;
        var n = slideCount();
        if(n <= 0) return;
        idx = ((idx % n) + n) % n; // wrap both directions
        t.scrollTo({ left: idx * t.clientWidth, behavior: 'smooth' });
        syncDots(idx);
    }

    function syncDots(idx){
        var dw = dotsWrap();
        if(!dw) return;
        var dots = dw.querySelectorAll('.adm-announce-dot');
        dots.forEach(function(d, i){
            d.classList.toggle('active', i === idx);
        });
    }

    function stopAuto(){
        if(timer){ clearInterval(timer); timer = null; }
    }

    function startAuto(){
        stopAuto();
        if(slideCount() <= 1) return; // nothing to rotate through
        timer = setInterval(function(){
            goTo(currentIndex() + 1);
        }, AUTO_MS);
    }

    function pauseThenResume(){
        stopAuto();
        if(resumeTimer) clearTimeout(resumeTimer);
        resumeTimer = setTimeout(startAuto, RESUME_MS);
    }

    // Track scroll -> keep dots in sync no matter how the scroll happened
    // (auto-advance, native touch swipe, or the mouse-drag handler below).
    function onScroll(){
        syncDots(currentIndex());
    }

    // ── Mouse-drag swipe support ────────────────────────────────────────
    // .adm-announce-track already scrolls natively via touch (overflow-x:
    // auto + scroll-snap) but a plain overflow container does NOT support
    // click-and-drag scrolling with a mouse — desktop users would have no
    // way to swipe at all without this.
    function onPointerDown(e){
        var t = track();
        if(!t) return;
        dragging = true;
        dragStartX = (e.touches ? e.touches[0].clientX : e.clientX);
        dragStartScroll = t.scrollLeft;
        pauseThenResume();
    }
    function onPointerMove(e){
        if(!dragging) return;
        var t = track();
        if(!t) return;
        var x = (e.touches ? e.touches[0].clientX : e.clientX);
        t.scrollLeft = dragStartScroll - (x - dragStartX);
    }
    function onPointerUp(){
        if(!dragging) return;
        dragging = false;
        // Snap to the nearest slide after a drag release.
        goTo(currentIndex());
    }

    function bindDots(){
        var dw = dotsWrap();
        if(!dw) return;
        dw.querySelectorAll('.adm-announce-dot').forEach(function(dot){
            if(dot._admBound) return;
            dot._admBound = true;
            dot.style.cursor = 'pointer';
            dot.addEventListener('click', function(){
                var idx = parseInt(dot.getAttribute('data-idx'), 10) || 0;
                goTo(idx);
                pauseThenResume();
            });
        });
    }

    function bindTrack(){
        var t = track();
        if(!t || t === boundTrack) return; // already bound to this exact element
        boundTrack = t;

        t.addEventListener('scroll', onScroll, { passive: true });

        // Mouse drag (touch already works natively, so only wire mouse events).
        t.addEventListener('mousedown', onPointerDown);
        window.addEventListener('mousemove', onPointerMove);
        window.addEventListener('mouseup', onPointerUp);

        // Any manual touch swipe also pauses/resumes auto-advance.
        t.addEventListener('touchstart', pauseThenResume, { passive: true });

        // Dots ARE clickable (direct jump-to-slide) — only the slide
        // CONTENT itself is inert/non-clickable, not the progress dots.
        bindDots();
    }

    function init(){
        bindTrack();
        syncDots(0);
        startAuto();
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-init on every Livewire page swap (wire:navigate) — same
    // convention as the chart script above, since the DOM (and therefore
    // the track/dots elements) is fully replaced on navigation.
    document.addEventListener('livewire:navigated', function(){
        stopAuto();
        if(resumeTimer) clearTimeout(resumeTimer);
        boundTrack = null; // force rebind to the freshly-swapped-in track element
        requestAnimationFrame(init);
    });
})();
</script>

</div>
{{-- ✅ END single root wrapper --}}