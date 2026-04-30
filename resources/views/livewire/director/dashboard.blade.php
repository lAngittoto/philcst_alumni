{{-- resources/views/livewire/director/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use App\Models\Organizer;

new class extends Component {

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int $totalCoordinators  = 0;
    public int $activeCoordinators = 0;
    public int $totalEvents        = 0;
    public int $pendingEvents      = 0;
    public int $approvedEvents     = 0;
    public int $completedEvents    = 0;
    public int $totalJobs          = 0;
    public int $activeJobs         = 0;
    public int $inactiveJobs       = 0;
    public int $newJobsThisMonth   = 0;

    // ── Chart data ────────────────────────────────────────────────────────────
    public string $chartEventData = '{}';
    public string $chartJobData   = '{}';

    // ── Recent data ───────────────────────────────────────────────────────────
    public array $recentEvents = [];
    public array $recentJobs   = [];

    // ── Greeting ──────────────────────────────────────────────────────────────
    public string $greeting    = '';
    public string $currentDate = '';

    // ─────────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'director', 403);

        $this->currentDate = now('Asia/Manila')->format('l, F j, Y');
        $hour = (int) now('Asia/Manila')->format('H');
        $this->greeting = match(true) {
            $hour < 12 => 'Good Morning',
            $hour < 17 => 'Good Afternoon',
            default    => 'Good Evening',
        };

        $this->loadStats();
        $this->loadCharts();
        $this->loadRecent();
    }

    private function loadStats(): void
    {
        $this->totalCoordinators  = Organizer::withoutTrashed()->count();
        $this->activeCoordinators = Organizer::withoutTrashed()->where('status', 'ACTIVE')->count();

        $this->totalEvents     = AdminEvent::withTrashed()->count();
        $this->pendingEvents   = AdminEvent::withoutTrashed()->where('status', 'PENDING')->count();
        $this->approvedEvents  = AdminEvent::withoutTrashed()->where('status', 'APPROVED')->count();
        $this->completedEvents = AdminEvent::withoutTrashed()->where('status', 'COMPLETED')->count();

        $this->totalJobs    = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])->count();
        $this->activeJobs   = JobPosting::where('status', 'ACTIVE')->count();
        $this->inactiveJobs = JobPosting::where('status', 'INACTIVE')->count();

        $monthStart = now('Asia/Manila')->startOfMonth()->utc();
        $this->newJobsThisMonth = JobPosting::where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['ADMIN_DELETED'])->count();
    }

    private function loadCharts(): void
    {
        $eventRows = AdminEvent::withTrashed()
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Removed 'Deleted' from Events chart
        $this->chartEventData = json_encode([
            'labels' => ['Approved', 'Pending', 'Rejected', 'Completed'],
            'data'   => [
                $eventRows->get('APPROVED')->cnt  ?? 0,
                $eventRows->get('PENDING')->cnt   ?? 0,
                $eventRows->get('REJECTED')->cnt  ?? 0,
                $eventRows->get('COMPLETED')->cnt ?? 0,
            ],
            'colors' => ['#22c55e', '#f59e0b', '#ef4444', '#3b82f6'],
        ]);

        $jobRows = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Removed 'Deleted by Org.' from Jobs chart
        $this->chartJobData = json_encode([
            'labels' => ['Active', 'Inactive'],
            'data'   => [
                $jobRows->get('ACTIVE')->cnt   ?? 0,
                $jobRows->get('INACTIVE')->cnt ?? 0,
            ],
            'colors' => ['#22c55e', '#f59e0b'],
        ]);
    }

    private function loadRecent(): void
    {
        // Limit changed to 5
        $this->recentEvents = AdminEvent::withTrashed()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'status', 'event_date', 'created_at'])
            ->map(fn($e) => [
                'id'         => $e->id,
                'title'      => $e->title,
                'status'     => $e->status,
                'event_date' => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'created_at' => $e->created_at->diffForHumans(),
            ])
            ->toArray();

        // Limit changed to 5
        $this->recentJobs = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'job_title', 'company_name', 'status', 'employment_type', 'created_at'])
            ->map(fn($j) => [
                'id'              => $j->id,
                'job_title'       => $j->job_title,
                'company_name'    => $j->company_name,
                'status'          => $j->status,
                'employment_type' => $j->employment_type,
                'created_at'      => $j->created_at->diffForHumans(),
            ])
            ->toArray();
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-8 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ── CHART DATA STORE ─────────────────────────────────────────── --}}
    <div id="__dir_dash_data" style="display:none"
         data-event="{{ $chartEventData }}"
         data-job="{{ $chartJobData }}">
    </div>

    {{-- ── PAGE HEADER ──────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold leading-tight" style="color:#333333;">Director Portal</h1>
            <p class="text-sm font-normal" style="color:#666666;">{{ $currentDate }}</p>
        </div>
        <div class="ml-auto hidden sm:flex items-center gap-2 bg-white rounded-xl border border-[#E8E0F0] shadow-sm px-4 py-2">
            <i class="fas fa-user-shield text-sm" style="color:#7A3F91;"></i>
            <span class="text-sm font-semibold" style="color:#333333;">{{ $greeting }}</span>
        </div>
    </div>

    {{-- ── COORDINATORS ─────────────────────────────────────────────── --}}
    <p class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#999999;">
        <i class="fas fa-user-tie" style="color:#7A3F91;"></i> Coordinators
    </p>
    <div class="grid grid-cols-2 gap-3 mb-4">

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-user-tie text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] border border-[#E8E0F0] uppercase" style="color:#7A3F91;">Active</span>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $activeCoordinators }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Active Coordinators</p>
            <p class="text-xs mt-1 font-normal" style="color:#999999;">{{ $totalCoordinators }} total registered</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-gray-400 flex items-center justify-center shadow">
                    <i class="fas fa-users-gear text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200 uppercase" style="color:#666666;">Total</span>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $totalCoordinators }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Total Coordinators</p>
            <p class="text-xs mt-1 font-normal" style="color:#999999;">{{ $totalCoordinators - $activeCoordinators }} inactive</p>
        </div>

    </div>

    {{-- ── EVENTS ───────────────────────────────────────────────────── --}}
    <p class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#999999;">
        <i class="fas fa-calendar-days" style="color:#7A3F91;"></i> Events
    </p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow mb-3"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $totalEvents }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Total Events</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-amber-400 flex items-center justify-center shadow mb-3">
                <i class="fas fa-hourglass-half text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $pendingEvents }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Pending</p>
            @if($pendingEvents > 0)
                <p class="text-xs font-semibold mt-1 flex items-center gap-1" style="color:#d97706;">
                    <i class="fas fa-circle-exclamation text-xs"></i> Needs attention
                </p>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-emerald-500 flex items-center justify-center shadow mb-3">
                <i class="fas fa-calendar-check text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $approvedEvents }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Approved</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-blue-500 flex items-center justify-center shadow mb-3">
                <i class="fas fa-flag-checkered text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $completedEvents }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Completed</p>
        </div>

    </div>

    {{-- ── JOBS ─────────────────────────────────────────────────────── --}}
    <p class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#999999;">
        <i class="fas fa-briefcase" style="color:#7A3F91;"></i> Job Postings
    </p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow mb-3"
                 style="background:linear-gradient(135deg,#4f46e5,#6366f1);">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $totalJobs }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Total Jobs</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-emerald-500 flex items-center justify-center shadow mb-3">
                <i class="fas fa-circle-check text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $activeJobs }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Active Jobs</p>
            @if($newJobsThisMonth > 0)
                <p class="text-xs font-semibold mt-1 flex items-center gap-1" style="color:#4f46e5;">
                    <i class="fas fa-plus text-xs"></i> {{ $newJobsThisMonth }} this month
                </p>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-amber-400 flex items-center justify-center shadow mb-3">
                <i class="fas fa-ban text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">{{ $inactiveJobs }}</p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Inactive Jobs</p>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-xl bg-gray-400 flex items-center justify-center shadow mb-3">
                <i class="fas fa-chart-pie text-white text-sm"></i>
            </div>
            <p class="text-3xl font-semibold leading-none" style="color:#333333;">
                {{ $totalJobs > 0 ? round($activeJobs / $totalJobs * 100) : 0 }}%
            </p>
            <p class="text-sm font-medium mt-1" style="color:#333333;">Active Rate</p>
            <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full" style="width:{{ $totalJobs > 0 ? round($activeJobs / $totalJobs * 100) : 0 }}%; background:#7A3F91;"></div>
            </div>
        </div>

    </div>

    {{-- ── CHARTS ROW ────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0]"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-calendar-days text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold uppercase tracking-wide" style="color:#333333;">Events Overview</p>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="dChartEvent"></canvas>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0]"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#4f46e5,#6366f1);">
                        <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold uppercase tracking-wide" style="color:#333333;">Jobs Overview</p>
                </div>
            </div>
            <div class="p-4 flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="dChartJob"></canvas>
            </div>
        </div>

    </div>

    {{-- ── RECENT EVENTS + RECENT JOBS ──────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-calendar-days text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold uppercase tracking-wide" style="color:#333333;">Recent Events</p>
                </div>
                <a href="{{ route('director.event/management') }}" wire:navigate
                   class="text-xs font-semibold hover:underline flex items-center gap-1" style="color:#7A3F91;">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="divide-y divide-[#F5F5F5]">
                @forelse($recentEvents as $ev)
                @php
                    [$evCls, $evLabel] = match($ev['status']) {
                        'APPROVED'          => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Approved'],
                        'PENDING'           => ['bg-amber-50 text-amber-700 border-amber-200',       'Pending'],
                        'REJECTED'          => ['bg-red-50 text-red-700 border-red-200',             'Rejected'],
                        'COMPLETED'         => ['bg-blue-50 text-blue-700 border-blue-200',          'Completed'],
                        'ORGANIZER_DELETED' => ['bg-gray-100 text-gray-600 border-gray-200',         'Deleted'],
                        default             => ['bg-gray-100 text-gray-600 border-gray-200',          $ev['status']],
                    };
                @endphp
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#FAFAFA] transition-colors">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 bg-amber-50">
                        <i class="fas fa-calendar-days text-sm text-amber-500"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold truncate uppercase" style="color:#333333;">{{ $ev['title'] }}</p>
                        <p class="text-xs font-normal mt-0.5" style="color:#999999;">
                            <i class="fas fa-calendar text-xs mr-1"></i>{{ $ev['event_date'] }}
                            &bull; {{ $ev['created_at'] }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full border {{ $evCls }} uppercase">
                        {{ $evLabel }}
                    </span>
                </div>
                @empty
                <div class="py-10 text-center">
                    <p class="text-sm font-semibold" style="color:#999999;">No events yet</p>
                </div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#4f46e5,#6366f1);">
                        <i class="fas fa-file-lines text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold uppercase tracking-wide" style="color:#333333;">Recent Job Postings</p>
                </div>
                <a href="{{ route('director.job/management') }}" wire:navigate
                   class="text-xs font-semibold hover:underline flex items-center gap-1" style="color:#7A3F91;">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="divide-y divide-[#F5F5F5]">
                @forelse($recentJobs as $job)
                @php
                    [$jobCls, $jobLabel] = match($job['status']) {
                        'ACTIVE'            => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Active'],
                        'INACTIVE'          => ['bg-amber-50 text-amber-700 border-amber-200',       'Inactive'],
                        'ORGANIZER_DELETED' => ['bg-red-50 text-red-700 border-red-200',             'Deleted'],
                        default             => ['bg-gray-100 text-gray-600 border-gray-200',          $job['status']],
                    };
                @endphp
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#FAFAFA] transition-colors">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                         style="background:#E8E0F0;">
                        <i class="fas fa-briefcase text-sm" style="color:#7A3F91;"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold truncate uppercase" style="color:#333333;">{{ $job['job_title'] }}</p>
                        <p class="text-xs font-normal mt-0.5" style="color:#999999;">
                            <i class="fas fa-building text-xs mr-1"></i>{{ $job['company_name'] }}
                            &bull; {{ $job['employment_type'] }}
                            &bull; {{ $job['created_at'] }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full border {{ $jobCls }} uppercase">
                        {{ $jobLabel }}
                    </span>
                </div>
                @empty
                <div class="py-10 text-center">
                    <p class="text-sm font-semibold" style="color:#999999;">No job postings yet</p>
                </div>
                @endforelse
            </div>
        </div>

    </div>

</div>{{-- end page --}}


{{-- ══ CHARTS SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function readData() {
        var el = document.getElementById('__dir_dash_data');
        if (!el) return null;
        try {
            return {
                event: JSON.parse(el.getAttribute('data-event') || 'null'),
                job:   JSON.parse(el.getAttribute('data-job')   || 'null'),
            };
        } catch (e) { return null; }
    }

    var reg = {};
    function kill(id) { if (reg[id]) { reg[id].destroy(); delete reg[id]; } }

    function donut(id, data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        kill(id);
        reg[id] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 7
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        // Disable clicking on legend items (view only)
                        onClick: function() { return false; },
                        labels: {
                            font: { size: 11, weight: '600', family: 'inherit' },
                            // Color each label text to match its corresponding chart color
                            color: function(context) {
                                return data.colors[context.index] || '#333333';
                            },
                            padding: 10,
                            usePointStyle: true,
                            pointStyleWidth: 8
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                                var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function initAll() {
        var d = readData();
        if (!d) return;
        donut('dChartEvent', d.event);
        donut('dChartJob',   d.job);
    }

    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }
        document.addEventListener('livewire:navigated', function () { requestAnimationFrame(initAll); });
        if (window.Livewire) {
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || function(cb){cb({});};
                succeed(function () { requestAnimationFrame(initAll); });
            });
        } else {
            document.addEventListener('livewire:initialized', function () {
                Livewire.hook('commit', function (payload) {
                    var succeed = payload.succeed || function(cb){cb({});};
                    succeed(function () { requestAnimationFrame(initAll); });
                });
            });
        }
    });
})();
</script>