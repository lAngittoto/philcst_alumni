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
            $verified     = Alumni::where('status', 'verified')->count();
            $pending      = Alumni::where('status', 'pending')->count();
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
                'totalAlumni','verified','pending',
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
            'colors' => ['#10b981', '#3b82f6', '#f59e0b', '#d1d5db'],
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
            'colors' => ['#f59e0b', '#10b981', '#16a34a', '#ef4444'],
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
            'labels' => ['Active', 'Inactive'],
            'data'   => [$this->jobsActive, $this->jobsInactive],
            'colors' => ['#10b981', '#f59e0b'],
        ]);
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

<div style="height:80vh; display:flex; flex-direction:column; overflow:hidden;">

<style>
/* ── Tooltips (shared) ── */
.adm-tip-wrap { position: relative; overflow: visible; }
.adm-tip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.adm-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
.adm-tip-wrap:hover .adm-tip { opacity: 1; }

/* ── Overlay tooltip (fixed-position, never clipped) ── */
#adm-overlay-tip {
    position: fixed;
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 9px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; z-index: 99999;
    transition: opacity 0.12s;
    opacity: 0;
}
#adm-overlay-tip.visible { opacity: 1; }
#adm-overlay-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
/* keep old mini-tip-wrap class for snap tiles (they're not clipped) */
.adm-mini-tip-wrap { position: relative; overflow: visible; }
.adm-mini-tip {
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 9px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.adm-mini-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
.adm-mini-tip-wrap:hover .adm-mini-tip { opacity: 1; }

/* ── Cards ── */
.adm-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 14px; }

.adm-panel-head {
    padding: 10px 16px; border-bottom: 1px solid #E8E0F0;
    background: #ffffff;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    flex-shrink: 0;
}
.adm-panel-ttl { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #000000; line-height: 1; }
.adm-panel-sub { font-size: .63rem; color: #555555; font-weight: 500; margin-top: 2px; }

/* ── Scrollbar ── */
.adm-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.adm-scroll::-webkit-scrollbar { width: 4px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── KPI Stat grid — icon LEFT, text RIGHT ── */
.adm-stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; flex-shrink: 0; }
@media (max-width: 768px) { .adm-stat-grid { grid-template-columns: 1fr 1fr; } }

.adm-stat-card {
    background: #ffffff;
    border: 1px solid #E8E0F0;
    border-radius: 12px; padding: 12px 14px; position: relative; overflow: visible;
    display: flex; flex-direction: row; align-items: center; gap: 12px;
    cursor: pointer;
    transition: box-shadow .15s, border-color .15s;
}
.adm-stat-card:hover { border-color: #c4b5d4; box-shadow: 0 3px 10px rgba(122,63,145,.10); }

/* Large icon on the left */
.adm-stat-icon-lg {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.adm-stat-icon-lg i { font-size: 1.15rem; }

/* Text block on the right */
.adm-stat-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.adm-stat-num { font-size: 1.45rem; font-weight: 800; line-height: 1; letter-spacing: -.01em; color: #000000; }
.adm-stat-lbl { font-size: .68rem; font-weight: 700; margin-top: 2px; color: #000000; }
.adm-stat-sub { font-size: .62rem; font-weight: 500; margin-top: 1px; color: #555555; }

/* ── Body grid: courses (left) + snapshots (right) ── */
.adm-body-grid {
    display: grid; grid-template-columns: 1.6fr 1fr; gap: 0.75rem;
    flex: 1; min-height: 0; overflow: hidden;
}
@media (max-width: 1023px) {
    .adm-body-grid { grid-template-columns: 1fr; }
}

.adm-panel-col { display: flex; flex-direction: column; min-height: 0; overflow: hidden; gap: 0.5rem; }
.adm-panel-body-scroll { flex: 1; min-height: 0; overflow-y: auto; }

/* ── Role counts strip (now at TOP of left column) ── */
.adm-role-strip {
    background: #fff;
    border: 1px solid #E8E0F0;
    border-radius: 12px;
    padding: 8px 14px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    flex-shrink: 0;
}
.adm-role-tile {
    display: flex; flex-direction: column; gap: 2px;
    padding: 6px 8px; border-radius: 8px;
    border: 1px solid #E8E0F0; background: #FAFBFF;
    cursor: pointer; transition: background .12s, border-color .12s;
}
.adm-role-tile:hover { background: #F0E6F8; border-color: #d4b8e8; }
.adm-role-tile-top { display: flex; align-items: center; gap: 5px; }
.adm-role-tile-icon { width: 18px; height: 18px; border-radius: 5px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.adm-role-tile-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #333; }
.adm-role-tile-counts { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.adm-role-tile-pill {
    display: inline-flex; align-items: center; gap: 2px;
    font-size: .58rem; font-weight: 700; padding: 1px 5px; border-radius: 999px; border: 1px solid;
}

/* ── Snapshot stack (right column) — equal height across all 3 cards ── */
.adm-snap-stack {
    display: grid;
    grid-template-rows: 1fr 1fr 1fr;
    gap: 0.6rem;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* Each snap card fills its row and clips internally */
.adm-snap-card {
    cursor: pointer;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

/* snap-row fills remaining height inside each card */
.adm-snap-row {
    display: flex;
    align-items: stretch;
    flex: 1;
    min-height: 0;
}

.adm-snap-mini-chart {
    display: flex; align-items: center; justify-content: center;
    padding: 6px 4px; width: 88px; flex-shrink: 0;
}
.adm-snap-mini-tiles {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 5px;
    padding: 8px 10px 8px 0; flex: 1;
}
.adm-snap-mini-tile {
    border-radius: 8px; padding: 5px 7px; border: 1px solid #E8E0F0; background: #FAFBFF;
    cursor: pointer;
}
.adm-snap-mini-tile:hover { background: #F0E6F8; }
.adm-snap-mini-num { font-size: .9rem; font-weight: 800; color: #000000; line-height: 1; }
.adm-snap-mini-lbl { font-size: .56rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: .03em; margin-top: 1px; }

/* Snap panel head — no purple icon, just text */
.adm-snap-head-text { display: flex; flex-direction: column; }

[x-cloak] { display:none !important }
</style>

{{-- ══ PAGE HEADER ══ --}}
<div class="flex items-center justify-between gap-3 mb-3 shrink-0">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-xl font-semibold leading-tight" style="color:#000000;">
                {{ $greeting }}, {{ $adminName }}
            </h1>
            <p class="text-xs font-normal flex flex-wrap items-center gap-x-1.5">
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
            <div class="adm-stat-sub">{{ $stats['totalCourses'] ?? 0 }} courses · {{ $stats['totalAlumni'] > 0 ? round((($stats['verified']??0)/$stats['totalAlumni'])*100) : 0 }}% verified</div>
        </div>
    </div>

    <div wire:click="goToAlumni('verified')" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-circle-check mr-1.5"></i>View Verified Alumni</span>
        <div class="adm-stat-icon-lg" style="background:linear-gradient(135deg,#027a4f,#059669);">
            <i class="fas fa-circle-check text-white"></i>
        </div>
        <div class="adm-stat-text">
            <div class="adm-stat-num">{{ number_format($stats['verified'] ?? 0) }}</div>
            <div class="adm-stat-lbl">Verified</div>
            <div class="adm-stat-sub">{{ $stats['totalAlumni'] > 0 ? round((($stats['verified']??0)/$stats['totalAlumni'])*100) : 0 }}% of total</div>
        </div>
    </div>

    <div wire:click="goToAlumni('pending')" class="adm-stat-card adm-tip-wrap">
        <span class="adm-tip"><i class="fas fa-clock mr-1.5"></i>Review Pending Alumni</span>
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

        {{-- ── Role counts strip — MOVED TO TOP ── --}}
        <div class="adm-role-strip">
            {{-- Directors --}}
            <div wire:click="goToUsers('director')" class="adm-role-tile adm-otip" data-tip="👁 View Directors">
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#4f46e5,#6366f1);">
                        <i class="fas fa-user-tie text-white" style="font-size:.5rem;"></i>
                    </div>
                    <span class="adm-role-tile-label">Directors</span>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleActiveDirectors }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleInactiveDirectors }} Inactive
                    </span>
                </div>
            </div>

            {{-- Coordinators --}}
            <div wire:click="goToUsers('coordinator')" class="adm-role-tile adm-otip" data-tip="👁 View Coordinators">
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users-gear text-white" style="font-size:.5rem;"></i>
                    </div>
                    <span class="adm-role-tile-label">Coordinators</span>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleActiveCoordinators }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleInactiveCoordinators }} Inactive
                    </span>
                </div>
            </div>

            {{-- Registrars --}}
            <div wire:click="goToUsers('registrar')" class="adm-role-tile adm-otip" data-tip="👁 View Registrars">
                <div class="adm-role-tile-top">
                    <div class="adm-role-tile-icon" style="background:linear-gradient(135deg,#059669,#10b981);">
                        <i class="fas fa-user-clock text-white" style="font-size:.5rem;"></i>
                    </div>
                    <span class="adm-role-tile-label">Registrars</span>
                </div>
                <div class="adm-role-tile-counts">
                    <span class="adm-role-tile-pill" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleActiveRegistrars }} Active
                    </span>
                    <span class="adm-role-tile-pill" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fas fa-circle text-[4px]"></i>{{ $roleInactiveRegistrars }} Inactive
                    </span>
                </div>
            </div>
        </div>

        {{-- All Courses — now BELOW the role strip ── --}}
        <div class="adm-card" style="display:flex; flex-direction:column; min-height:0; flex:1; overflow:hidden;">
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
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 text-[.56rem] font-bold text-white"
                                     style="background:{{ $color }};">{{ $idx + 1 }}</div>
                                <span class="text-[.75rem] font-semibold font-mono uppercase truncate" style="color:#000000;">{{ $cs['code'] }}</span>
                            </div>
                            <span class="text-[.7rem] font-bold ml-2 flex-shrink-0" style="color:#000000;">{{ $cs['alumni_count'] }}</span>
                        </div>
                        <div class="w-full h-1.5 rounded-full overflow-hidden" style="background:#F0E8F8;">
                            <div class="h-full rounded-full transition-all duration-700" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                        </div>
                        <p class="text-[.6rem] mt-0.5 truncate" style="color:#555555;">{{ $cs['name'] }}</p>
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
                <p class="text-[.66rem] font-semibold text-[#7A3F91]">
                    {{ $stats['totalCourses'] ?? 0 }} courses
                    @if(count($courseStats) > 4)
                        <span class="text-[#c0a0d8] font-normal">· scroll for more</span>
                    @endif
                </p>
                <p class="text-[.62rem] font-normal" style="color:#555555;">
                    Alumni: {{ number_format($stats['totalAlumni'] ?? 0) }}
                </p>
            </div>
            @endif
        </div>

    </div>{{-- /adm-panel-col --}}

    {{-- ── RIGHT: Snapshot Stack (Employment / Events / Jobs) ── --}}
    <div class="adm-snap-stack adm-scroll">

        {{-- Employment Snapshot --}}
        <div wire:click="goToEmployment" class="adm-card adm-snap-card adm-tip-wrap">
            <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Full Employment Page</span>
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Employment</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-mini-chart" wire:ignore>
                    <canvas id="adm_chartEmpSnapshot"></canvas>
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
                    {{-- Not Filled (replaces Emp. Rate) --}}
                    <div wire:click.stop="goToEmployment('no_record')" class="adm-snap-mini-tile adm-mini-tip-wrap">
                        <span class="adm-mini-tip"><i class="fas fa-eye mr-1"></i>View Not Filled</span>
                        <p class="adm-snap-mini-num" style="color:#9ca3af;">{{ number_format($empNotFilled) }}</p>
                        <p class="adm-snap-mini-lbl">Not Filled</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Events Snapshot --}}
        <div wire:click="goToEvents" class="adm-card adm-snap-card adm-tip-wrap">
            <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Full Events Page</span>
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Events</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-mini-chart" wire:ignore>
                    <canvas id="adm_chartEventsSnapshot"></canvas>
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
            </div>
        </div>

        {{-- Jobs Snapshot --}}
        <div wire:click="goToJobs" class="adm-card adm-snap-card adm-tip-wrap">
            <span class="adm-tip"><i class="fas fa-eye mr-1.5"></i>View Full Job Postings Page</span>
            <div class="adm-panel-head">
                <div class="adm-snap-head-text">
                    <p class="adm-panel-ttl">Job Postings</p>
                    <p class="adm-panel-sub">Quick snapshot</p>
                </div>
            </div>
            <div class="adm-snap-row">
                <div class="adm-snap-mini-chart" wire:ignore>
                    <canvas id="adm_chartJobsSnapshot"></canvas>
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

    function kill(id){ if(registry[id]){ registry[id].destroy(); delete registry[id]; } }
    function allZero(arr){ return !arr || arr.every(function(v){ return !v || v === 0; }); }

    function donut(id, data){
        if(!data || !data.labels || allZero(data.data)){ kill(id); return; }
        var c = document.getElementById(id); if(!c) return;
        kill(id);
        registry[id] = new Chart(c, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx){
                                var t = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                                var p = t ? Math.round(ctx.parsed / t * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + p + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function initAll(){
        var d = bridge(); if(!d) return;
        donut('adm_chartEmpSnapshot',    d.empSnapshot);
        donut('adm_chartEventsSnapshot', d.eventsSnapshot);
        donut('adm_chartJobsSnapshot',   d.jobsSnapshot);
    }

    loadChartJs(function(){
        if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){ requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }
        document.addEventListener('livewire:navigated', function(){
            kill('adm_chartEmpSnapshot');
            kill('adm_chartEventsSnapshot');
            kill('adm_chartJobsSnapshot');
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
// ── Overlay tooltip for role strip tiles (fixed-pos, never clipped) ──
(function(){
    var tip = document.getElementById('adm-overlay-tip');
    if (!tip) return;
    var hideTimer;

    function show(el, text) {
        clearTimeout(hideTimer);
        tip.textContent = text;
        tip.classList.add('visible');
        position(el);
    }
    function hide() {
        hideTimer = setTimeout(function(){ tip.classList.remove('visible'); }, 80);
    }
    function position(el) {
        var r  = el.getBoundingClientRect();
        var tw = tip.offsetWidth;
        var th = tip.offsetHeight;
        var left = r.left + r.width / 2 - tw / 2;
        var top  = r.top - th - 8;
        left = Math.max(6, Math.min(left, window.innerWidth - tw - 6));
        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
    }

    function bind() {
        document.querySelectorAll('.adm-otip').forEach(function(el) {
            if (el.__otipBound) return;
            el.__otipBound = true;
            el.addEventListener('mouseenter', function() { show(el, el.getAttribute('data-tip')); });
            el.addEventListener('mousemove',  function() { position(el); });
            el.addEventListener('mouseleave', hide);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
    document.addEventListener('livewire:navigated', function(){ setTimeout(bind, 50); });
    document.addEventListener('livewire:updated',   function(){ setTimeout(bind, 50); });
})();
</script>

{{-- Overlay tooltip element — inside root div so Livewire sees only one root --}}
<div id="adm-overlay-tip"></div>

</div>
{{-- ✅ END single root wrapper --}}