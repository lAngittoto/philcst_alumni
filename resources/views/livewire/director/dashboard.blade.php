{{-- resources/views/livewire/director/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use App\Models\Organizer;
use Carbon\Carbon;

new class extends Component {

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int $totalCoordinators  = 0;
    public int $activeCoordinators = 0;
    public int $totalEvents        = 0;
    public int $pendingEvents      = 0;
    public int $approvedEvents     = 0;
    public int $completedEvents    = 0;
    public int $rejectedEvents     = 0;
    public int $totalJobs          = 0;
    public int $activeJobs         = 0;
    public int $inactiveJobs       = 0;
    public int $newJobsThisMonth   = 0;

    // ── Modal state ───────────────────────────────────────────────────────────
    public string $activeModal      = '';
    public string $eventModalTitle  = '';
    public array  $modalEvents      = [];
    public array  $modalJobs        = [];
    public array  $modalCoords      = [];

    // ── Search / Pagination ───────────────────────────────────────────────────
    public string $eventSearch  = '';
    public string $jobSearch    = '';
    public string $coordSearch  = '';

    public int $eventModalPage    = 1;
    public int $eventModalSize    = 20;
    public int $jobModalPage      = 1;
    public int $jobModalPageSize  = 20;
    public int $coordModalPage    = 1;
    public int $coordModalSize    = 20;

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
        $this->rejectedEvents  = AdminEvent::withoutTrashed()->where('status', 'REJECTED')->count();

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

        $this->chartEventData = json_encode([
            'labels'  => ['Approved', 'Pending', 'Rejected', 'Completed'],
            'data'    => [
                $eventRows->get('APPROVED')->cnt  ?? 0,
                $eventRows->get('PENDING')->cnt   ?? 0,
                $eventRows->get('REJECTED')->cnt  ?? 0,
                $eventRows->get('COMPLETED')->cnt ?? 0,
            ],
            'colors'  => ['#22c55e', '#f59e0b', '#ef4444', '#3b82f6'],
            'filters' => ['APPROVED', 'PENDING', 'REJECTED', 'COMPLETED'],
        ]);

        $jobRows = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $this->chartJobData = json_encode([
            'labels'  => ['Active', 'Inactive'],
            'data'    => [
                $jobRows->get('ACTIVE')->cnt   ?? 0,
                $jobRows->get('INACTIVE')->cnt ?? 0,
            ],
            'colors'  => ['#22c55e', '#f59e0b'],
            'filters' => ['ACTIVE', 'INACTIVE'],
        ]);
    }

    private function loadRecent(): void
    {
        $this->recentEvents = AdminEvent::withoutTrashed()
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

        $this->recentJobs = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'job_title', 'company_name', 'status', 'employment_type', 'created_at', 'deadline'])
            ->map(fn($j) => [
                'id'              => $j->id,
                'job_title'       => $j->job_title,
                'company_name'    => $j->company_name,
                'status'          => $j->status,
                'employment_type' => $j->employment_type,
                'deadline'        => Carbon::parse($j->deadline)->setTimezone('Asia/Manila')->format('M d, Y'),
                'created_at'      => $j->created_at->diffForHumans(),
            ])
            ->toArray();
    }

    // ══════════════════════════════════════════════════════════════
    // MODAL BUILDERS
    // ══════════════════════════════════════════════════════════════

    protected function buildEventRows(string $status = ''): array
    {
        $q = AdminEvent::withTrashed();
        if ($status) {
            $q->where('status', $status);
        } else {
            $q->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED']);
        }
        return $q->orderByDesc('event_date')
            ->get(['id', 'title', 'event_date', 'status', 'created_at'])
            ->map(fn($e) => [
                'id'         => $e->id,
                'title'      => $e->title,
                'date'       => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'       => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'status'     => $e->status,
                'created_at' => $e->created_at->diffForHumans(),
            ])->toArray();
    }

    public function openTotalEventsModal(): void
    {
        $this->eventModalTitle = 'All Events';
        $this->modalEvents     = $this->buildEventRows();
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openPendingEventsModal(): void
    {
        $this->eventModalTitle = 'Pending Events';
        $this->modalEvents     = $this->buildEventRows('PENDING');
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openApprovedEventsModal(): void
    {
        $this->eventModalTitle = 'Approved Events';
        $this->modalEvents     = $this->buildEventRows('APPROVED');
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openRejectedEventsModal(): void
    {
        $this->eventModalTitle = 'Rejected Events';
        $this->modalEvents     = $this->buildEventRows('REJECTED');
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openCompletedEventsModal(): void
    {
        $this->eventModalTitle = 'Completed Events';
        $this->modalEvents     = $this->buildEventRows('COMPLETED');
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openEventModalByStatus(string $status): void
    {
        match($status) {
            'PENDING'   => $this->openPendingEventsModal(),
            'APPROVED'  => $this->openApprovedEventsModal(),
            'REJECTED'  => $this->openRejectedEventsModal(),
            'COMPLETED' => $this->openCompletedEventsModal(),
            default     => $this->openTotalEventsModal(),
        };
    }

    protected function buildJobRows(string $status = ''): array
    {
        $q = JobPosting::query();
        if ($status) {
            $q->where('status', $status);
        } else {
            $q->whereNotIn('status', ['ADMIN_DELETED']);
        }
        return $q->orderByDesc('created_at')
            ->get(['id', 'job_title', 'company_name', 'employment_type', 'location', 'deadline', 'salary', 'status'])
            ->map(fn($j) => [
                'id'        => $j->id,
                'title'     => $j->job_title,
                'company'   => $j->company_name,
                'type'      => $j->employment_type,
                'location'  => $j->location ?? '',
                'salary'    => $j->salary   ?? '',
                'deadline'  => Carbon::parse($j->deadline)->setTimezone('Asia/Manila')->format('M d, Y'),
                'days_left' => (int) now('Asia/Manila')->startOfDay()->diffInDays(
                    Carbon::parse($j->deadline)->startOfDay(), false
                ),
                'status'    => $j->status,
            ])->toArray();
    }

    public function openJobsModal(): void
    {
        $this->modalJobs    = $this->buildJobRows();
        $this->jobSearch    = '';
        $this->jobModalPage = 1;
        $this->activeModal  = 'jobs';
    }

    public function openActiveJobsModal(): void
    {
        $this->modalJobs    = $this->buildJobRows('ACTIVE');
        $this->jobSearch    = '';
        $this->jobModalPage = 1;
        $this->activeModal  = 'jobs';
    }

    public function openInactiveJobsModal(): void
    {
        $this->modalJobs    = $this->buildJobRows('INACTIVE');
        $this->jobSearch    = '';
        $this->jobModalPage = 1;
        $this->activeModal  = 'jobs';
    }

    public function openJobModalByStatus(string $status): void
    {
        match($status) {
            'ACTIVE'   => $this->openActiveJobsModal(),
            'INACTIVE' => $this->openInactiveJobsModal(),
            default    => $this->openJobsModal(),
        };
    }

    protected function buildCoordRows(string $status = ''): array
    {
        $q = Organizer::withoutTrashed();
        if ($status) $q->where('status', $status);
        return $q->orderBy('name')
            ->get(['id', 'name', 'email', 'department', 'status', 'created_at'])
            ->map(fn($o) => [
                'id'         => $o->id,
                'name'       => strtoupper($o->name),
                'email'      => $o->email,
                'department' => $o->department ?? '—',
                'status'     => $o->status,
                'created_at' => $o->created_at->format('M d, Y'),
            ])->toArray();
    }

    public function openCoordsModal(string $status = ''): void
    {
        $this->modalCoords    = $this->buildCoordRows($status);
        $this->coordSearch    = '';
        $this->coordModalPage = 1;
        $this->activeModal    = 'coords';
    }

    public function closeModal(): void { $this->activeModal = ''; }

    // ── Pagination helpers ────────────────────────────────────────────────────
    public function updatingEventSearch(): void { $this->eventModalPage = 1; }
    public function updatingJobSearch(): void   { $this->jobModalPage   = 1; }
    public function updatingCoordSearch(): void { $this->coordModalPage = 1; }

    public function eventPrevPage(): void { if ($this->eventModalPage > 1) $this->eventModalPage--; }
    public function eventNextPage(int $last): void { if ($this->eventModalPage < $last) $this->eventModalPage++; }

    public function jobPrevPage(): void { if ($this->jobModalPage > 1) $this->jobModalPage--; }
    public function jobNextPage(int $last): void { if ($this->jobModalPage < $last) $this->jobModalPage++; }

    public function coordPrevPage(): void { if ($this->coordModalPage > 1) $this->coordModalPage--; }
    public function coordNextPage(int $last): void { if ($this->coordModalPage < $last) $this->coordModalPage++; }
};
?>

{{-- ══════════════════════════════════════════════════════════════════
     ROOT — listens for chart-segment click events dispatched by JS
══════════════════════════════════════════════════════════════════ --}}
<div
    @open-dir-event-modal.window="$wire.openEventModalByStatus($event.detail.filter)"
    @open-dir-job-modal.window="$wire.openJobModalByStatus($event.detail.filter)"
>

<style>
    .stat-card        { transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease; cursor: pointer; }
    .stat-card:hover  { box-shadow: 0 4px 16px rgba(122,63,145,.13); border-color: #c0a0d8 !important; }
    .stat-card:active { transform: scale(.985); }

    @keyframes dashModalIn {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .dash-modal-enter { animation: dashModalIn .22s cubic-bezier(.4,0,.2,1) both; }
    .dash-scroll { scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb; }

    .pg-btn {
        display:inline-flex; align-items:center; justify-content:center;
        min-width:32px; height:32px; padding:0 10px; border-radius:8px;
        font-size:.75rem; font-weight:700; transition:all .15s;
        border:1.5px solid transparent;
    }
    .pg-btn-active { background:#7A3F91; color:#fff; border-color:#7A3F91; }
    .pg-btn-nav    { background:#fff; color:#7A3F91; border-color:#d9c9e8; }
    .pg-btn-nav:hover:not(:disabled) { background:#f9f7fc; border-color:#7A3F91; }
    .pg-btn-nav:disabled { opacity:.4; cursor:not-allowed; }
    .pg-info { font-size:.75rem; font-weight:600; color:#7A3F91; }

    .recent-row { transition: background .12s ease; cursor: pointer; }
    .recent-row:hover { background: #faf7ff; }

    .inline-stat { cursor:pointer; transition:all .15s; }
    .inline-stat:hover { text-decoration: underline; }

    /* Chart card — same pattern as employment tracking */
    .dir-chart-card {
        background: #F9F7FC;
        border-radius: 12px;
        border: 1px solid #E8E0F0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        cursor: pointer;
        transition: box-shadow .18s ease, border-color .18s ease;
    }
    .dir-chart-card:hover {
        box-shadow: 0 5px 16px rgba(122,63,145,.13);
        border-color: rgba(122,63,145,.35);
    }
    .dir-chart-card:active { transform: scale(.998); }
    .dir-chart-hint {
        font-size:.70rem; color:#bbb; font-weight:500;
        margin-left:auto; display:flex; align-items:center; gap:3px;
        pointer-events:none;
    }
</style>

{{-- ════════════════════════════════════════════════════════════════
     CHART DATA STORE
════════════════════════════════════════════════════════════════ --}}
<div id="__dir_dash_data" style="display:none"
     data-event="{{ $chartEventData }}"
     data-job="{{ $chartJobData }}">
</div>

{{-- ════════════════════════════════════════════════════════════════
     MAIN PAGE — 90VH FIXED, NO SCROLL
════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 max-w-screen-2xl mx-auto w-full"
     style="height:90vh; overflow:hidden;">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex items-center gap-3 mb-3 shrink-0">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-sm"></i>
        </div>
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold text-[#333333] leading-tight truncate">
                {{ $greeting }}, Director
            </h1>
            <p class="text-xs text-[#666666] font-normal">{{ $currentDate }}</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 bg-white rounded-xl border border-[#E8E0F0] shadow-sm px-3 py-2 shrink-0">
            <i class="fas fa-user-shield text-sm" style="color:#7A3F91;"></i>
            <span class="text-xs font-semibold text-[#333333]">Director Portal</span>
        </div>
    </div>

    {{-- ═══ STAT CARDS ══════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 mb-3 shrink-0">

        {{-- Coordinators --}}
        <div wire:click="openCoordsModal('ACTIVE')"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3 overflow-hidden">
            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-user-tie text-white text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#F9F7FC; color:#7A3F91; border:1px solid #E8E0F0;">Active</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($activeCoordinators) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Active Coordinators</p>
            <div class="mt-1.5 flex items-center gap-1">
                <span wire:click.stop="openCoordsModal()"
                      class="inline-stat text-xs font-semibold"
                      style="color:#7A3F91;">
                    <i class="fas fa-users text-xs mr-0.5"></i> {{ $totalCoordinators }} total
                </span>
                @if(($totalCoordinators - $activeCoordinators) > 0)
                    <span class="text-[#E8E0F0]">·</span>
                    <span class="text-xs font-semibold text-gray-400">{{ $totalCoordinators - $activeCoordinators }} inactive</span>
                @endif
            </div>
        </div>

        {{-- Total Events --}}
        <div wire:click="openTotalEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3 overflow-hidden">
            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-purple-100 flex items-center justify-center shadow">
                    <i class="fas fa-calendar-days text-sm" style="color:#7A3F91;"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#F9F7FC; color:#7A3F91; border:1px solid #E8E0F0;">Events</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($totalEvents) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Total Events</p>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span wire:click.stop="openApprovedEventsModal"
                      class="inline-stat text-xs font-semibold text-emerald-600 flex items-center gap-1">
                    <i class="fas fa-circle-check text-xs"></i> {{ $approvedEvents }} Approved
                </span>
            </div>
        </div>

        {{-- Pending Events --}}
        <div wire:click="openPendingEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3 overflow-hidden">
            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center shadow">
                    <i class="fas fa-hourglass-end text-amber-500 text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#FFFBEB; color:#b45309; border:1px solid #fde68a;">Pending</span>
            </div>
            <p class="text-2xl font-semibold text-amber-600 leading-none">{{ number_format($pendingEvents) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Pending Review</p>
            @if($pendingEvents > 0)
                <p class="text-xs font-semibold mt-1.5 flex items-center gap-1" style="color:#d97706;">
                    <i class="fas fa-circle-exclamation text-xs"></i> Needs attention
                </p>
            @else
                <p class="text-xs text-[#999999] mt-1.5 font-normal">All clear</p>
            @endif
        </div>

        {{-- Job Postings --}}
        <div wire:click="openJobsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3 overflow-hidden">
            <div class="flex items-start justify-between mb-2">
                <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center shadow">
                    <i class="fas fa-briefcase text-blue-500 text-sm"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#EFF6FF; color:#1d4ed8; border:1px solid #bfdbfe;">Jobs</span>
            </div>
            <p class="text-2xl font-semibold text-[#333333] leading-none">{{ number_format($totalJobs) }}</p>
            <p class="text-xs text-[#666666] mt-1 font-normal">Job Postings</p>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span wire:click.stop="openActiveJobsModal"
                      class="inline-stat text-xs font-semibold text-emerald-600 flex items-center gap-1">
                    <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                </span>
                <span class="text-xs font-semibold text-[#999999] flex items-center gap-1">
                    <i class="fas fa-circle text-[8px]"></i> {{ $inactiveJobs }} Inactive
                </span>
            </div>
        </div>

    </div>

    {{-- ═══ CONTENT GRID ══════════════════════════════════════════════ --}}
    <div class="flex-1 min-h-0 grid gap-2.5"
         style="grid-template-columns: 1fr 1fr 280px; grid-template-rows: 3fr 2fr;">

        {{-- ── Events Overview: col 1-2, row 1 ─────────────────── --}}
        <div class="col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-calendar-days text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Overview</p>
                    <span class="text-[10px] text-[#c0a0d8] font-normal hidden sm:inline">— click a card to filter</span>
                </div>
                <a href="{{ route('director.event/management') }}" wire:navigate
                   class="text-xs font-semibold hover:underline flex items-center gap-1" style="color:#7A3F91;">
                    Manage <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="flex-1 overflow-y-auto dash-scroll p-3">

                {{-- Event Status Grid --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">

                    @php
                        $evtCards = [
                            ['label'=>'Pending',   'count'=>$pendingEvents,   'icon'=>'fa-hourglass-end',    'color'=>'#d97706','light'=>'#FFFBEB','border'=>'#FCD34D', 'action'=>'openPendingEventsModal'],
                            ['label'=>'Approved',  'count'=>$approvedEvents,  'icon'=>'fa-calendar-check',   'color'=>'#059669','light'=>'#ECFDF5','border'=>'#A7F3D0', 'action'=>'openApprovedEventsModal'],
                            ['label'=>'Completed', 'count'=>$completedEvents, 'icon'=>'fa-flag-checkered',   'color'=>'#2563eb','light'=>'#EFF6FF','border'=>'#BFDBFE', 'action'=>'openCompletedEventsModal'],
                            ['label'=>'Rejected',  'count'=>$rejectedEvents,  'icon'=>'fa-circle-xmark',     'color'=>'#dc2626','light'=>'#FFF5F5','border'=>'#FECACA', 'action'=>'openRejectedEventsModal'],
                        ];
                    @endphp

                    @foreach($evtCards as $card)
                    <div wire:click="{{ $card['action'] }}"
                         class="stat-card rounded-xl border p-2.5"
                         style="background:{{ $card['light'] }}; border-color:{{ $card['border'] }};">
                        <div class="flex items-center gap-1.5 mb-1.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0"
                                 style="background:{{ $card['color'] }}20; color:{{ $card['color'] }};">
                                <i class="fas {{ $card['icon'] }} text-xs"></i>
                            </div>
                            <span class="text-xs font-semibold text-[#555555]">{{ $card['label'] }}</span>
                        </div>
                        <p class="text-2xl font-semibold leading-none" style="color:{{ $card['color'] }};">
                            {{ number_format($card['count']) }}
                        </p>
                        <p class="text-[10px] text-[#999999] mt-1 font-normal flex items-center gap-1">
                            <i class="fas fa-arrow-up-right-from-square text-[8px]"></i> View details
                        </p>
                    </div>
                    @endforeach

                </div>

                {{-- Charts Row --}}
                <div class="border-t border-[#E8E0F0] pt-3 grid grid-cols-2 gap-3">

                    {{-- Events Donut — card click opens all-events modal; segment click filtered --}}
                    <div class="dir-chart-card" onclick="dirOpenEventModal('')">
                        <div class="px-3 py-2 flex items-center gap-1.5 border-b border-[#E8E0F0]">
                            <div class="w-4 h-4 rounded-md flex items-center justify-center"
                                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                                <i class="fas fa-calendar-days text-white" style="font-size:8px;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Chart</p>
                            <span class="dir-chart-hint">
                                <i class="fas fa-hand-pointer"></i> Click segment
                            </span>
                        </div>
                        <div class="p-2 flex items-center justify-center" style="height:160px;" wire:ignore>
                            <canvas id="dChartEvent"></canvas>
                        </div>
                    </div>

                    {{-- Jobs Donut — card click opens all-jobs modal; segment click filtered --}}
                    <div class="dir-chart-card" onclick="dirOpenJobModal('')">
                        <div class="px-3 py-2 flex items-center gap-1.5 border-b border-[#E8E0F0]">
                            <div class="w-4 h-4 rounded-md flex items-center justify-center bg-blue-500">
                                <i class="fas fa-briefcase text-white" style="font-size:8px;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Jobs Chart</p>
                            <span class="dir-chart-hint">
                                <i class="fas fa-hand-pointer"></i> Click segment
                            </span>
                        </div>
                        <div class="p-2 flex items-center justify-center" style="height:160px;" wire:ignore>
                            <canvas id="dChartJob"></canvas>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        {{-- ── Summary Panel: col 3, spans rows 1 & 2 ─────────── --}}
        <div class="row-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col"
             style="grid-column:3; grid-row:1/3;">

            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center gap-2"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="w-5 h-5 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-chart-bar text-white" style="font-size:10px;"></i>
                </div>
                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Quick Summary</p>
            </div>

            <div class="flex-1 overflow-y-auto dash-scroll divide-y divide-[#F5F5F5] px-4">

                <div class="py-3">
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide mb-2">Coordinators</p>
                    <div class="space-y-1.5">
                        <div wire:click="openCoordsModal('ACTIVE')"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#F9F7FC; border:1px solid #E8E0F0;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-user-check text-xs" style="color:#7A3F91;"></i> Active
                            </span>
                            <span class="text-xs font-semibold" style="color:#7A3F91;">{{ $activeCoordinators }}</span>
                        </div>
                        <div wire:click="openCoordsModal()"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#F9FAFB; border:1px solid #E5E7EB;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-users text-xs text-gray-400"></i> Total
                            </span>
                            <span class="text-xs font-semibold text-gray-600">{{ $totalCoordinators }}</span>
                        </div>
                    </div>
                </div>

                <div class="py-3">
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide mb-2">Events</p>
                    <div class="space-y-1.5">
                        <div wire:click="openApprovedEventsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#ECFDF5; border:1px solid #A7F3D0;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-calendar-check text-xs text-emerald-500"></i> Approved
                            </span>
                            <span class="text-xs font-semibold text-emerald-600">{{ $approvedEvents }}</span>
                        </div>
                        <div wire:click="openPendingEventsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#FFFBEB; border:1px solid #FCD34D;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-hourglass-end text-xs text-amber-500"></i> Pending
                            </span>
                            <span class="text-xs font-semibold text-amber-600">{{ $pendingEvents }}</span>
                        </div>
                        <div wire:click="openCompletedEventsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#EFF6FF; border:1px solid #BFDBFE;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-flag-checkered text-xs text-blue-400"></i> Completed
                            </span>
                            <span class="text-xs font-semibold text-blue-600">{{ $completedEvents }}</span>
                        </div>
                        @if($rejectedEvents > 0)
                        <div wire:click="openRejectedEventsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#FFF5F5; border:1px solid #FECACA;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-circle-xmark text-xs text-red-400"></i> Rejected
                            </span>
                            <span class="text-xs font-semibold text-red-600">{{ $rejectedEvents }}</span>
                        </div>
                        @endif
                    </div>
                </div>

                <div class="py-3">
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide mb-2">Job Postings</p>
                    <div class="space-y-1.5">
                        <div wire:click="openActiveJobsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#ECFDF5; border:1px solid #A7F3D0;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-circle-check text-xs text-emerald-500"></i> Active
                            </span>
                            <span class="text-xs font-semibold text-emerald-600">
                                {{ $activeJobs }}
                                @if($newJobsThisMonth > 0)
                                    <span class="text-[10px] font-normal text-emerald-400 ml-0.5">+{{ $newJobsThisMonth }} this month</span>
                                @endif
                            </span>
                        </div>
                        <div wire:click="openInactiveJobsModal"
                             class="stat-card flex items-center justify-between rounded-lg px-2.5 py-1.5"
                             style="background:#FFFBEB; border:1px solid #FDE68A;">
                            <span class="text-xs text-[#666666] font-normal flex items-center gap-1.5">
                                <i class="fas fa-ban text-xs text-amber-400"></i> Inactive
                            </span>
                            <span class="text-xs font-semibold text-amber-600">{{ $inactiveJobs }}</span>
                        </div>
                    </div>
                    {{-- Active Rate Progress --}}
                    <div class="mt-2.5">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[10px] font-semibold text-[#999999] uppercase tracking-wide">Active Rate</span>
                            <span class="text-xs font-semibold" style="color:#7A3F91;">
                                {{ $totalJobs > 0 ? round($activeJobs / $totalJobs * 100) : 0 }}%
                            </span>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full" style="width:{{ $totalJobs > 0 ? round($activeJobs / $totalJobs * 100) : 0 }}%; background:#7A3F91;"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- ── Recent Events: col 1, row 2 ─────────────────────── --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <button type="button" wire:click="openTotalEventsModal"
                        class="flex items-center gap-2 hover:opacity-80 transition cursor-pointer">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-calendar-days text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Recent Events</p>
                    <i class="fas fa-up-right-from-square text-[#c0a0d8]" style="font-size:9px;"></i>
                </button>
                <span class="ml-auto text-[10px] text-[#c0a0d8] hidden sm:inline font-normal">click row to filter</span>
            </div>

            <div class="flex-1 overflow-y-auto dash-scroll divide-y divide-[#F5F5F5]">
                @forelse($recentEvents as $index => $event)
                @php
                    $sc = match($event['status']) {
                        'PENDING'   => ['text-amber-700 bg-amber-50 border-amber-200',       'fa-hourglass-end', '#d97706'],
                        'APPROVED'  => ['text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check',  '#059669'],
                        'REJECTED'  => ['text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark',  '#dc2626'],
                        'COMPLETED' => ['text-blue-700 bg-blue-50 border-blue-200',          'fa-check-double',  '#2563eb'],
                        default     => ['text-[#666666] bg-[#F9F7FC] border-[#E8E0F0]',      'fa-circle',        '#9b59b6'],
                    };
                @endphp
                <div class="recent-row px-3 py-2.5 flex items-center gap-2"
                     wire:click="openEventModalByStatus('{{ $event['status'] }}')">
                    <span class="w-4 text-center text-xs font-semibold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                         style="background:{{ $sc[2] }}20; color:{{ $sc[2] }};">
                        <i class="fas {{ $sc[1] }} text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333] truncate uppercase">{{ $event['title'] }}</p>
                        <p class="text-xs text-[#999999] font-normal">
                            <i class="fas fa-calendar text-xs mr-1"></i>{{ $event['event_date'] }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border {{ $sc[0] }} uppercase">
                            {{ $event['status'] }}
                        </span>
                        <p class="text-xs text-[#BBBBBB] font-normal mt-0.5">{{ $event['created_at'] }}</p>
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" style="background:#f0e6f8;">
                        <i class="fas fa-calendar-days text-base" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#999999]">No events yet</p>
                </div>
                @endforelse
            </div>
        </div>

        {{-- ── Recent Jobs: col 2, row 2 ────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-4 py-2.5 border-b border-[#E8E0F0] shrink-0 flex items-center"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <button type="button" wire:click="openJobsModal"
                        class="flex items-center gap-2 hover:opacity-80 transition cursor-pointer">
                    <div class="w-5 h-5 rounded-lg flex items-center justify-center bg-blue-500">
                        <i class="fas fa-briefcase text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Recent Job Posts</p>
                    <i class="fas fa-up-right-from-square text-blue-300" style="font-size:9px;"></i>
                </button>
                <span class="ml-auto text-[10px] text-[#c0a0d8] hidden sm:inline font-normal">click row to filter</span>
            </div>

            <div class="flex-1 overflow-y-auto dash-scroll divide-y divide-[#F5F5F5]">
                @forelse($recentJobs as $index => $job)
                @php $isActive = $job['status'] === 'ACTIVE'; @endphp
                <div class="recent-row px-3 py-2.5 flex items-center gap-2"
                     wire:click="openJobModalByStatus('{{ $job['status'] }}')">
                    <span class="w-4 text-center text-xs font-semibold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                         style="background:{{ $isActive ? '#EFF6FF' : '#F9FAFB' }}; color:{{ $isActive ? '#2563eb' : '#6B7280' }};">
                        <i class="fas fa-briefcase text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#333333] truncate uppercase">{{ $job['job_title'] }}</p>
                        <p class="text-xs text-[#999999] font-normal truncate flex items-center gap-1">
                            <i class="fas fa-building text-xs mr-0.5"></i>
                            <span class="truncate max-w-[80px]">{{ $job['company_name'] }}</span>
                            <span class="text-[#E8E0F0]">·</span>
                            <span class="font-semibold text-blue-500">{{ $job['employment_type'] }}</span>
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        @if($isActive)
                            <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">Active</span>
                        @else
                            <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full border text-amber-700 bg-amber-50 border-amber-200">Inactive</span>
                        @endif
                        <p class="text-xs text-[#BBBBBB] font-normal mt-0.5">{{ $job['created_at'] }}</p>
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2 bg-blue-50">
                        <i class="fas fa-briefcase text-base text-blue-300"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#999999]">No job postings yet</p>
                </div>
                @endforelse
            </div>
        </div>

    </div>{{-- end content grid --}}

</div>{{-- end 90vh wrapper --}}


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENTS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'events')
@php
    $filteredEvents = collect($modalEvents)
        ->when($eventSearch !== '', fn($c) => $c->filter(fn($e) =>
            str_contains(strtolower($e['title']), strtolower($eventSearch))
        ))
        ->values();

    $evtTotal      = $filteredEvents->count();
    $evtLastPage   = max((int) ceil($evtTotal / $eventModalSize), 1);
    $evtSafePage   = min($eventModalPage, $evtLastPage);
    $evtFrom       = $evtTotal > 0 ? ($evtSafePage - 1) * $eventModalSize + 1 : 0;
    $evtTo         = min($evtSafePage * $eventModalSize, $evtTotal);
    $displayEvents = $filteredEvents->slice(($evtSafePage - 1) * $eventModalSize, $eventModalSize)->values()->toArray();
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $evtFrom }}–{{ $evtTo }} of {{ $evtTotal }} event(s)</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('eventSearch', q)"
                       placeholder="Search event title…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $evtFrom }}–{{ $evtTo }}</strong> of <strong class="text-gray-600">{{ $evtTotal }}</strong>
            </span>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Event Title</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date &amp; Time</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Posted</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEvents as $idx => $evt)
                @php
                    $evtSc = match($evt['status']) {
                        'PENDING'   => ['text-amber-700 bg-amber-50 border-amber-200',       'fa-hourglass-end'],
                        'APPROVED'  => ['text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check'],
                        'REJECTED'  => ['text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark'],
                        'COMPLETED' => ['text-blue-700 bg-blue-50 border-blue-200',          'fa-check-double'],
                        default     => ['text-gray-600 bg-gray-50 border-gray-200',          'fa-circle'],
                    };
                @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($evtFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900 uppercase">{{ $evt['title'] }}</p></td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['date'] }}</p>
                        <p class="text-xs text-gray-400 font-normal">{{ $evt['time'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <p class="text-xs text-gray-500">{{ $evt['created_at'] }}</p>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $evtSc[0] }}">
                            <i class="fas {{ $evtSc[1] }} text-xs"></i> {{ $evt['status'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-calendar-days text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No events found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:#7A3F91;">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $evtFrom }}–{{ $evtTo }}</strong>
            of <strong class="font-bold text-base">{{ $evtTotal }}</strong> event(s)
        </p>
        @if($evtLastPage > 1)
        <div class="flex items-center gap-2">
            <button wire:click="eventPrevPage" {{ $evtSafePage <= 1 ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $evtSafePage - 2); $p <= min($evtLastPage, $evtSafePage + 2); $p++)
                @if($p === $evtSafePage)
                    <span class="pg-btn pg-btn-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('eventModalPage', {{ $p }})" class="pg-btn pg-btn-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="eventNextPage({{ $evtLastPage }})" {{ $evtSafePage >= $evtLastPage ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="pg-info text-white ml-1">Page {{ $evtSafePage }}/{{ $evtLastPage }}</span>
        </div>
        @endif
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: JOB POSTINGS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'jobs')
@php
    $filteredJobs = collect($modalJobs)
        ->when($jobSearch !== '', fn($c) => $c->filter(fn($j) =>
            str_contains(strtolower($j['title']),          strtolower($jobSearch)) ||
            str_contains(strtolower($j['company']),        strtolower($jobSearch)) ||
            str_contains(strtolower($j['location'] ?? ''), strtolower($jobSearch))
        ))
        ->values();

    $jobTotalCount = $filteredJobs->count();
    $jobLastPage   = max((int) ceil($jobTotalCount / $jobModalPageSize), 1);
    $jobSafePage   = min($jobModalPage, $jobLastPage);
    $jobFrom       = $jobTotalCount > 0 ? ($jobSafePage - 1) * $jobModalPageSize + 1 : 0;
    $jobTo         = min($jobSafePage * $jobModalPageSize, $jobTotalCount);
    $displayJobs   = $filteredJobs->slice(($jobSafePage - 1) * $jobModalPageSize, $jobModalPageSize)->values()->toArray();

    $jobStatuses = collect($modalJobs)->pluck('status')->unique()->toArray();
    $jobModalTitleText = match(true) {
        count($jobStatuses) === 1 && $jobStatuses[0] === 'ACTIVE'   => 'Active Job Postings',
        count($jobStatuses) === 1 && $jobStatuses[0] === 'INACTIVE' => 'Inactive Job Postings',
        default => 'All Job Postings',
    };
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $jobModalTitleText }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $jobFrom }}–{{ $jobTo }} of {{ $jobTotalCount }} job(s)</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('jobSearch', q)"
                       placeholder="Search title, company, location…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $jobFrom }}–{{ $jobTo }}</strong> of <strong class="text-gray-600">{{ $jobTotalCount }}</strong>
            </span>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <table class="w-full border-collapse" style="min-width:620px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Location</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Salary</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayJobs as $idx => $job)
                @php
                    $isUrgent = ($job['days_left'] ?? 99) <= 7;
                    $isActive = $job['status'] === 'ACTIVE';
                @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($jobFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-900 truncate" style="max-width:180px;">{{ $job['title'] }}</p>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm text-gray-600 truncate" style="max-width:140px;">{{ $job['company'] }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $job['type'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden md:table-cell"><p class="text-sm text-gray-500">{{ $job['location'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <p class="text-sm font-semibold" style="color:#7A3F91;">{{ $job['salary'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        @if($isActive)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200">
                                <i class="fas fa-circle text-[8px]"></i> Active
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-amber-700 bg-amber-50 border-amber-200">
                                <i class="fas fa-circle text-[8px]"></i> Inactive
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <p class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-gray-500' }}">
                            <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs mr-0.5"></i>
                            {{ $job['deadline'] }}
                        </p>
                        @if($isUrgent)
                        <p class="text-xs text-red-400 font-normal mt-0.5">{{ $job['days_left'] }}d left</p>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-briefcase text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No job postings found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:#7A3F91;">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $jobFrom }}–{{ $jobTo }}</strong>
            of <strong class="font-bold text-base">{{ $jobTotalCount }}</strong> posting(s)
        </p>
        @if($jobLastPage > 1)
        <div class="flex items-center gap-2">
            <button wire:click="jobPrevPage" {{ $jobSafePage <= 1 ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $jobSafePage - 2); $p <= min($jobLastPage, $jobSafePage + 2); $p++)
                @if($p === $jobSafePage)
                    <span class="pg-btn pg-btn-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('jobModalPage', {{ $p }})" class="pg-btn pg-btn-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="jobNextPage({{ $jobLastPage }})" {{ $jobSafePage >= $jobLastPage ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="pg-info text-white ml-1">Page {{ $jobSafePage }}/{{ $jobLastPage }}</span>
        </div>
        @endif
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: COORDINATORS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'coords')
@php
    $filteredCoords = collect($modalCoords)
        ->when($coordSearch !== '', fn($c) => $c->filter(fn($o) =>
            str_contains(strtolower($o['name']),       strtolower($coordSearch)) ||
            str_contains(strtolower($o['email']),      strtolower($coordSearch)) ||
            str_contains(strtolower($o['department']), strtolower($coordSearch))
        ))
        ->values();

    $coordTotal    = $filteredCoords->count();
    $coordLastPage = max((int) ceil($coordTotal / $coordModalSize), 1);
    $coordSafePage = min($coordModalPage, $coordLastPage);
    $coordFrom     = $coordTotal > 0 ? ($coordSafePage - 1) * $coordModalSize + 1 : 0;
    $coordTo       = min($coordSafePage * $coordModalSize, $coordTotal);
    $displayCoords = $filteredCoords->slice(($coordSafePage - 1) * $coordModalSize, $coordModalSize)->values()->toArray();

    $coordStatuses   = collect($modalCoords)->pluck('status')->unique()->toArray();
    $coordModalTitle = count($coordStatuses) === 1 && $coordStatuses[0] === 'ACTIVE'
        ? 'Active Coordinators' : 'All Coordinators';
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-user-tie text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $coordModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $coordFrom }}–{{ $coordTo }} of {{ $coordTotal }} coordinator(s)</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.coordSearch??''; $wire.$watch('coordSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('coordSearch', q)"
                       placeholder="Search name, email, department…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $coordFrom }}–{{ $coordTo }}</strong> of <strong class="text-gray-600">{{ $coordTotal }}</strong>
            </span>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Department</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Registered</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayCoords as $idx => $coord)
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($coordFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900">{{ $coord['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-sm text-gray-500 truncate" style="max-width:200px;">{{ $coord['email'] }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $coord['department'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-xs text-gray-400">{{ $coord['created_at'] }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        @if($coord['status'] === 'ACTIVE')
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200">
                                <i class="fas fa-circle text-[8px]"></i> Active
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">
                                <i class="fas fa-circle text-[8px]"></i> {{ $coord['status'] }}
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-user-tie text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No coordinators found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:#7A3F91;">
        <p class="text-white text-sm font-normal">
            Showing <strong class="font-bold text-base">{{ $coordFrom }}–{{ $coordTo }}</strong>
            of <strong class="font-bold text-base">{{ $coordTotal }}</strong> coordinator(s)
        </p>
        @if($coordLastPage > 1)
        <div class="flex items-center gap-2">
            <button wire:click="coordPrevPage" {{ $coordSafePage <= 1 ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @for($p = max(1, $coordSafePage - 2); $p <= min($coordLastPage, $coordSafePage + 2); $p++)
                @if($p === $coordSafePage)
                    <span class="pg-btn pg-btn-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('coordModalPage', {{ $p }})" class="pg-btn pg-btn-nav">{{ $p }}</button>
                @endif
            @endfor
            <button wire:click="coordNextPage({{ $coordLastPage }})" {{ $coordSafePage >= $coordLastPage ? 'disabled' : '' }} class="pg-btn pg-btn-nav">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="pg-info text-white ml-1">Page {{ $coordSafePage }}/{{ $coordLastPage }}</span>
        </div>
        @endif
    </div>

</div>
@endif


</div>{{-- end root --}}


{{-- ══ CHARTS SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    // ── Global helpers dispatched by card-level onclick attrs ──────────────────
    // Card header click → open all-events or all-jobs (empty filter = "all")
    window.dirOpenEventModal = function (filter) {
        window.dispatchEvent(new CustomEvent('open-dir-event-modal', {
            detail: { filter: filter || '' }
        }));
    };
    window.dirOpenJobModal = function (filter) {
        window.dispatchEvent(new CustomEvent('open-dir-job-modal', {
            detail: { filter: filter || '' }
        }));
    };

    // ── Utilities ──────────────────────────────────────────────────────────────
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

    function safeDestroy(id) {
        var canvas = document.getElementById(id);
        if (canvas && window.Chart && Chart.getChart) {
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();
        }
    }

    // ── Donut builder — identical pattern to Employment Tracking ───────────────
    /**
     * Legend click  → stops propagation + toggles segment visibility (no card bubble)
     * Segment click → stops propagation + dispatches the correct open-dir-*-modal event
     * Empty canvas  → stops propagation, no modal, no bubble to card
     * Card header   → onclick="dirOpenEventModal('')" / dirOpenJobModal('') for "all"
     */
    function buildDonut(id, data, openModalFn) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        safeDestroy(id);

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data:            data.data,
                    backgroundColor: data.colors,
                    borderWidth:     2,
                    borderColor:     '#fff',
                    hoverOffset:     6,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 10, weight: '600', family: 'inherit' },
                            color: function (context) {
                                return data.colors[context.index] || '#333333';
                            },
                            padding:         8,
                            usePointStyle:   true,
                            pointStyleWidth: 7,
                        },
                        // FIX: stop propagation so card onclick doesn't also fire;
                        // toggle the clicked segment's visibility (same as emp. tracking)
                        onClick: function (e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart;
                            var index = legendItem.index;
                            if (chart.getDataVisibility(index)) {
                                chart.hide(index);
                            } else {
                                chart.show(index);
                            }
                            chart.update();
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                // Segment click → dispatch correct modal event with filter value
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var idx    = elements[0].index;
                        var filter = (data.filters && data.filters[idx]) ? data.filters[idx] : '';
                        openModalFn(filter);
                    }
                    // Empty canvas click: stop propagation, no modal, no bubble.
                },
            },
        });
    }

    // ── Init all charts ────────────────────────────────────────────────────────
    function initAll() {
        var d = readData();
        if (!d) return;

        buildDonut('dChartEvent', d.event, function (filter) {
            window.dispatchEvent(new CustomEvent('open-dir-event-modal', {
                detail: { filter: filter }
            }));
        });

        buildDonut('dChartJob', d.job, function (filter) {
            window.dispatchEvent(new CustomEvent('open-dir-job-modal', {
                detail: { filter: filter }
            }));
        });
    }

    // ── Boot ───────────────────────────────────────────────────────────────────
    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }

        document.addEventListener('livewire:navigated', function () {
            safeDestroy('dChartEvent');
            safeDestroy('dChartJob');
            requestAnimationFrame(initAll);
        });

        function hookLivewire() {
            if (!window.Livewire) return;
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || function (cb) { cb({}); };
                if (typeof succeed === 'function') {
                    succeed(function () { requestAnimationFrame(initAll); });
                }
            });
        }

        if (window.Livewire) {
            hookLivewire();
        } else {
            document.addEventListener('livewire:initialized', hookLivewire);
        }
    });

})();
</script>