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

    // Employment
    public int    $empTotal      = 0;
    public int    $empEmployed   = 0;
    public int    $empSelf       = 0;
    public int    $empUnemployed = 0;
    public int    $empNotFilled  = 0;
    public int    $empRate       = 0;
    public string $chartEmpSnapshotData = '{}';

    // Events
    public int    $eventsTotal     = 0;
    public int    $eventsPending   = 0;
    public int    $eventsApproved  = 0;
    public int    $eventsCompleted = 0;
    public int    $eventsRejected  = 0;
    public string $chartEventsSnapshotData = '{}';

    // Job Postings
    public int    $jobsTotal    = 0;
    public int    $jobsActive   = 0;
    public int    $jobsInactive = 0;
    public int    $jobsExpiring = 0;
    public string $chartJobsSnapshotData = '{}';

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
    }

    // ── Navigate actions (organizer-style, with auto-filter) ───────────────
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
        // Load ALL courses (no take limit) — blade will scroll
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
}; ?>

<div class="flex flex-col" style="height:95vh; overflow:hidden;">

<style>
/* ── Tooltips (organizer-style) ── */
.adm-stat-card { position: relative; overflow: visible; }
.adm-stat-card .stat-tooltip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.adm-stat-card .stat-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
.adm-stat-card:hover .stat-tooltip { opacity: 1; }

/* ── Cards ── */
.adm-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 16px; }
.adm-card-hover { transition: transform .15s cubic-bezier(.25,.8,.25,1), box-shadow .15s; }
.adm-card-hover:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(122,63,145,.12), 0 2px 6px rgba(0,0,0,.05); }

.adm-panel-head {
    padding: 12px 18px; border-bottom: 1px solid #E8E0F0;
    background: linear-gradient(to right,#F9F7FC,#ffffff);
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.adm-panel-icon {
    width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg,#7A3F91,#9b59b6); flex-shrink: 0;
}
.adm-panel-ttl { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #333333; line-height: 1; }
.adm-panel-sub { font-size: .67rem; color: #333333; font-weight: 500; margin-top: 2px; }

/* ── Animations ── */
@keyframes adm-fadeUp { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:none } }
.adm-fade-up { animation: adm-fadeUp .4s cubic-bezier(.25,.8,.25,1) both; }
.adm-fade-1 { animation-delay:.04s } .adm-fade-2 { animation-delay:.08s }
.adm-fade-3 { animation-delay:.12s } .adm-fade-4 { animation-delay:.16s }

/* ── Scrollbar ── */
.adm-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.adm-scroll::-webkit-scrollbar { width: 4px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── KPI / Stat grid (organizer-style 2x2) ── */
.adm-stat-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 0.75rem; }
@media (max-width: 640px) { .adm-stat-grid { grid-template-columns: 1fr; grid-template-rows: auto; } }

.adm-stat-card { height: 100%; display: flex; flex-direction: column; justify-content: center; }

/* ── Snap cards (clickable, organizer-style) ── */
.adm-snap-grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
@media (max-width: 1023px) { .adm-snap-grid3 { grid-template-columns: 1fr; } }

.adm-snap-card {
    cursor: pointer;
    transition: transform .13s ease, box-shadow .15s ease, border-color .15s;
}
.adm-snap-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(122,63,145,.13), 0 2px 6px rgba(0,0,0,.05); }
.adm-snap-card:active { transform: scale(.98); }

.adm-snap-link {
    font-size: .68rem; font-weight: 700; padding: 4px 10px; border-radius: 999px;
    background: #f5eef9; color: #7A3F91; border: 1px solid #d4aaeb; white-space: nowrap;
    transition: background .15s; pointer-events: none;
}
.adm-snap-card:hover .adm-snap-link { background: #ecdcf5; }

.adm-snap-mini-chart { display: flex; align-items: center; justify-content: center; padding: 14px 14px 4px; }
.adm-snap-mini-tiles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 8px 14px 14px; }
.adm-snap-mini-tile { border-radius: 10px; padding: 8px 10px; border: 1px solid #E8E0F0; background: #FBF9FD; }
.adm-snap-mini-num { font-size: 1.05rem; font-weight: 800; color: #333333; line-height: 1; }
.adm-snap-mini-lbl { font-size: .62rem; font-weight: 700; color: #333333; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

[x-cloak] { display:none !important }
</style>

{{-- Single scroll container --}}
<div class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto w-full adm-scroll"
     style="height:95vh; overflow-y:auto; overflow-x:hidden;">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex items-center justify-between gap-3 mb-5 adm-fade-up">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-gauge-high text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
                    {{ $greeting }}, {{ $adminName }}
                </h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                    <i class="fas fa-circle text-[5px] text-emerald-500 align-middle"></i>
                    <span>{{ now()->setTimezone('Asia/Manila')->format('l, F j, Y · g:i A') }}</span>
                    <span class="text-[#c0a0d8]">·</span>
                    <span class="font-semibold text-[#7A3F91]">Admin Panel</span>
                </p>
            </div>
        </div>
    </div>

    {{-- ══ DASHBOARD COLUMN (full width — no profile card) ══ --}}
    <div class="flex flex-col gap-4">

        {{-- ── Stat Cards (organizer-style 2x2, with tooltip + auto-filter) ── --}}
        <div class="adm-stat-grid adm-fade-up adm-fade-1">

            {{-- Total Alumni --}}
            <button wire:click="goToAlumni"
                    class="adm-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-lg hover:border-[#7A3F91]/40 transition-all duration-200
                           active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View All Alumni</span>
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users text-white text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                 border border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">Alumni</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($stats['totalAlumni'] ?? 0) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Total Alumni</p>
                <p class="text-[#555555] font-normal mt-1 text-[0.85rem]">{{ $stats['totalCourses'] ?? 0 }} courses · {{ $stats['totalAlumni'] > 0 ? round((($stats['verified']??0)/$stats['totalAlumni'])*100) : 0 }}% verified</p>
            </button>

            {{-- Verified --}}
            <button wire:click="goToAlumni('verified')"
                    class="adm-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-lg hover:border-emerald-300 transition-all duration-200
                           active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View Verified Alumni</span>
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-circle-check text-white text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                 border border-emerald-200 bg-emerald-50 text-[0.75rem]">Verified</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($stats['verified'] ?? 0) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Verified Alumni</p>
                <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $stats['totalAlumni'] > 0 ? round((($stats['verified']??0)/$stats['totalAlumni'])*100) : 0 }}% of total
                </p>
            </button>

            {{-- Pending --}}
            <button wire:click="goToAlumni('pending')"
                    class="adm-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-lg hover:border-amber-300 transition-all duration-200
                           active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>Review Pending Alumni</span>
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                        <i class="fas fa-clock text-white text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                 border border-amber-200 bg-amber-50 text-[0.75rem]">Pending</span>
                </div>
                <p class="text-amber-600 font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($stats['pending'] ?? 0) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Pending Review</p>
                <p class="text-[#555555] font-normal mt-1 text-[0.85rem]">{{ $stats['totalAlumni'] > 0 ? round((($stats['pending']??0)/$stats['totalAlumni'])*100) : 0 }}% of total</p>
            </button>

            {{-- This Month --}}
            <button wire:click="goToAlumni('this_month')"
                    class="adm-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-lg hover:border-blue-300 transition-all duration-200
                           active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View New Registrations</span>
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-calendar-plus text-white text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                 border border-blue-200 bg-blue-50 text-[0.75rem]">New</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($stats['thisMonth'] ?? 0) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">This Month</p>
                <p class="text-blue-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ ($stats['growth'] ?? 0) >= 0 ? '+' : '' }}{{ $stats['growth'] ?? 0 }}% vs last mo.
                </p>
            </button>

        </div>

        {{-- ── Top Courses (Scrollable) ── --}}
        <div class="adm-card overflow-hidden adm-fade-up adm-fade-2">
            <div class="adm-panel-head">
                <div class="flex items-center gap-2">
                    <div class="adm-panel-icon"><i class="fas fa-ranking-star text-white text-[10px]"></i></div>
                    <div>
                        <p class="adm-panel-ttl">All Courses</p>
                        <p class="adm-panel-sub">Alumni count per course — scroll to see all</p>
                    </div>
                </div>
                <a href="{{ route('course') }}"
                   class="text-[.68rem] font-bold px-2.5 py-1 rounded-full bg-[#f5eef9] text-[#7A3F91] border border-[#d4aaeb] whitespace-nowrap hover:bg-[#ecdcf5] transition-colors">
                    Manage <i class="fas fa-arrow-right text-[9px] ml-1"></i>
                </a>
            </div>

            {{-- Scrollable course list (max-height = ~5 rows, then scroll) --}}
            <div class="adm-scroll" style="max-height: 260px; overflow-y: auto;">
                <div class="p-4 space-y-3">
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
                                <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 text-[.58rem] font-bold text-white"
                                     style="background:{{ $color }};">{{ $idx + 1 }}</div>
                                <span class="text-[.78rem] font-semibold text-[#333333] font-mono uppercase truncate">{{ $cs['code'] }}</span>
                            </div>
                            <span class="text-[.72rem] font-bold text-[#333333] ml-2 flex-shrink-0">{{ $cs['alumni_count'] }}</span>
                        </div>
                        <div class="w-full h-2 rounded-full overflow-hidden" style="background:#F0E8F8;">
                            <div class="h-full rounded-full transition-all duration-700" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                        </div>
                        <p class="text-[.62rem] text-[#333333] mt-0.5 truncate">{{ $cs['name'] }}</p>
                    </div>
                    @empty
                    <div class="py-10 text-center">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f5eef9;">
                            <i class="fas fa-book text-[#d4aaeb] text-xl"></i>
                        </div>
                        <p class="text-sm font-semibold text-[#333333]">No courses yet</p>
                    </div>
                    @endforelse
                </div>
            </div>

            @if(count($courseStats) > 0)
            <div class="px-5 py-2.5 border-t border-[#E8E0F0] bg-[#FAFBFF] flex items-center justify-between">
                <p class="text-[.72rem] font-semibold text-[#7A3F91]">
                    {{ $stats['totalCourses'] ?? 0 }} courses registered
                    @if(count($courseStats) > 5)
                        <span class="text-[#c0a0d8] font-normal">· scroll to see all</span>
                    @endif
                </p>
                <p class="text-[.68rem] text-[#999999] font-normal">
                    Total alumni: {{ number_format($stats['totalAlumni'] ?? 0) }}
                </p>
            </div>
            @endif
        </div>

        {{-- ── Snapshot Cards: Employment / Events / Jobs (Clickable) ── --}}
        <div class="adm-snap-grid3 adm-fade-up adm-fade-3">

            {{-- Employment Snapshot --}}
            <div wire:click="goToEmployment"
                 class="adm-card adm-snap-card overflow-hidden">
                <div class="adm-panel-head">
                    <div class="flex items-center gap-2">
                        <div class="adm-panel-icon"><i class="fas fa-briefcase text-white text-[10px]"></i></div>
                        <div>
                            <p class="adm-panel-ttl">Employment</p>
                            <p class="adm-panel-sub">Quick snapshot</p>
                        </div>
                    </div>
                    <span class="adm-snap-link">
                        Full <i class="fas fa-arrow-right text-[9px] ml-1"></i>
                    </span>
                </div>
                <div class="adm-snap-mini-chart" style="height:120px;" wire:ignore>
                    <canvas id="adm_chartEmpSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($empEmployed) }}</p>
                        <p class="adm-snap-mini-lbl">Employed</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#2563eb;">{{ number_format($empSelf) }}</p>
                        <p class="adm-snap-mini-lbl">Self-Employed</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($empUnemployed) }}</p>
                        <p class="adm-snap-mini-lbl">Unemployed</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#7a3f91;">{{ $empRate }}%</p>
                        <p class="adm-snap-mini-lbl">Emp. Rate</p>
                    </div>
                </div>
                {{-- Hover hint --}}
                <div class="px-4 pb-3 pt-1">
                    <p class="text-[.62rem] text-[#c0a0d8] font-semibold text-center flex items-center justify-center gap-1">
                        <i class="fas fa-hand-pointer text-[9px]"></i> Click to view full employment page
                    </p>
                </div>
            </div>

            {{-- Events Snapshot --}}
            <div wire:click="goToEvents"
                 class="adm-card adm-snap-card overflow-hidden">
                <div class="adm-panel-head">
                    <div class="flex items-center gap-2">
                        <div class="adm-panel-icon"><i class="fas fa-calendar-days text-white text-[10px]"></i></div>
                        <div>
                            <p class="adm-panel-ttl">Events</p>
                            <p class="adm-panel-sub">Quick snapshot</p>
                        </div>
                    </div>
                    <span class="adm-snap-link">
                        Full <i class="fas fa-arrow-right text-[9px] ml-1"></i>
                    </span>
                </div>
                <div class="adm-snap-mini-chart" style="height:120px;" wire:ignore>
                    <canvas id="adm_chartEventsSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($eventsPending) }}</p>
                        <p class="adm-snap-mini-lbl">Pending</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($eventsApproved) }}</p>
                        <p class="adm-snap-mini-lbl">Approved</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#16a34a;">{{ number_format($eventsCompleted) }}</p>
                        <p class="adm-snap-mini-lbl">Completed</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#dc2626;">{{ number_format($eventsRejected) }}</p>
                        <p class="adm-snap-mini-lbl">Rejected</p>
                    </div>
                </div>
                <div class="px-4 pb-3 pt-1">
                    <p class="text-[.62rem] text-[#c0a0d8] font-semibold text-center flex items-center justify-center gap-1">
                        <i class="fas fa-hand-pointer text-[9px]"></i> Click to view full events page
                    </p>
                </div>
            </div>

            {{-- Jobs Snapshot --}}
            <div wire:click="goToJobs"
                 class="adm-card adm-snap-card overflow-hidden">
                <div class="adm-panel-head">
                    <div class="flex items-center gap-2">
                        <div class="adm-panel-icon"><i class="fas fa-suitcase text-white text-[10px]"></i></div>
                        <div>
                            <p class="adm-panel-ttl">Job Postings</p>
                            <p class="adm-panel-sub">Quick snapshot</p>
                        </div>
                    </div>
                    <span class="adm-snap-link">
                        Full <i class="fas fa-arrow-right text-[9px] ml-1"></i>
                    </span>
                </div>
                <div class="adm-snap-mini-chart" style="height:120px;" wire:ignore>
                    <canvas id="adm_chartJobsSnapshot"></canvas>
                </div>
                <div class="adm-snap-mini-tiles">
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#059669;">{{ number_format($jobsActive) }}</p>
                        <p class="adm-snap-mini-lbl">Active</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#d97706;">{{ number_format($jobsInactive) }}</p>
                        <p class="adm-snap-mini-lbl">Inactive</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#f97316;">{{ number_format($jobsExpiring) }}</p>
                        <p class="adm-snap-mini-lbl">Expiring Soon</p>
                    </div>
                    <div class="adm-snap-mini-tile">
                        <p class="adm-snap-mini-num" style="color:#7a3f91;">{{ number_format($jobsTotal) }}</p>
                        <p class="adm-snap-mini-lbl">Total Postings</p>
                    </div>
                </div>
                <div class="px-4 pb-3 pt-1">
                    <p class="text-[.62rem] text-[#c0a0d8] font-semibold text-center flex items-center justify-center gap-1">
                        <i class="fas fa-hand-pointer text-[9px]"></i> Click to view full job postings page
                    </p>
                </div>
            </div>

        </div>{{-- end snap grid --}}

    </div>{{-- end dashboard column --}}

</div>{{-- end 95vh scroll wrapper --}}

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
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        onClick: function(){},
                        labels: {
                            font: { size: 9, weight: '600' },
                            color: '#333333',
                            padding: 6,
                            usePointStyle: true,
                            pointStyleWidth: 6,
                            boxHeight: 6
                        }
                    },
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

</div>
{{-- ✅ END single root wrapper --}}