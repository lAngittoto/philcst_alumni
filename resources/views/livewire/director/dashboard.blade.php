{{-- resources/views/livewire/director/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use App\Models\Organizer;
use Carbon\Carbon;

new class extends Component {

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

    public string $activeModal      = '';
    public string $eventModalTitle  = '';
    public array  $modalEvents      = [];
    public array  $modalJobs        = [];
    public array  $modalCoords      = [];

    public string $eventSearch  = '';
    public string $jobSearch    = '';
    public string $coordSearch  = '';

    public int $eventModalPage    = 1;
    public int $eventModalSize    = 20;
    public int $jobModalPage      = 1;
    public int $jobModalPageSize  = 20;
    public int $coordModalPage    = 1;
    public int $coordModalSize    = 20;

    public string $chartEventData = '{}';
    public string $chartJobData   = '{}';

    public string $greeting    = '';
    public string $currentDate = '';

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

<div
    @open-dir-event-modal.window="$wire.openEventModalByStatus($event.detail.filter)"
    @open-dir-job-modal.window="$wire.openJobModalByStatus($event.detail.filter)"
>

<style>
/* ── Stat card tooltip (same as organizer .org-stat-card) ── */
.dir-stat-card { position: relative; overflow: visible; }
.dir-stat-card .dir-card-tip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.dir-stat-card .dir-card-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
.dir-stat-card:hover .dir-card-tip { opacity: 1; }

/* ── Mini event cards (same as organizer .org-emp-card) ── */
.dir-mini-card { position: relative; overflow: visible; cursor: pointer; transition: transform .12s ease, box-shadow .15s ease; }
.dir-mini-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.10); }
.dir-mini-card:active { transform: scale(.97); }
.dir-mini-card .dir-mini-tip {
    position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.dir-mini-card .dir-mini-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
.dir-mini-card:hover .dir-mini-tip { opacity: 1; }

/* ── Main grid (same proportions as organizer .org-main-grid) ── */
.dir-main-grid { display: grid; grid-template-columns: 300px 1fr; gap: 1rem; align-items: stretch; }
@media (max-width: 1023px) { .dir-main-grid { grid-template-columns: 1fr; } }

/* ── Account column & card (same as organizer .org-account-col / .org-account-card) ── */
.dir-account-col { display: flex; flex-direction: column; }
.dir-account-card { flex: 1; display: flex; flex-direction: column; min-height: 0; }

/* ── Right col (same as organizer .org-right-col) ── */
.dir-right-col { display: flex; flex-direction: column; gap: 1rem; }

/* ── 2x2 stat grid (same as organizer .org-stat-grid) ── */
.dir-stat-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 0.75rem; }
.dir-stat-grid .dir-stat-card { height: 100%; display: flex; flex-direction: column; justify-content: center; }

/* ── Info body fills space (same as organizer .org-info-body) ── */
.dir-info-body { flex: 1; display: flex; flex-direction: column; overflow-y: auto; min-height: 0; }

/* ── Info rows (same as organizer .org-info-row / label / value) ── */
.dir-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.dir-info-row:last-child { border-bottom: none; }
.dir-info-label {
    font-size: 0.70rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.07em; color: #555555; flex-shrink: 0;
}
.dir-info-value {
    font-size: 0.875rem; font-weight: 600; color: #111111;
    text-align: right; overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap; max-width: 165px;
}
.dir-info-value-sm {
    font-size: 0.80rem; font-weight: 600; color: #111111;
    text-align: right; word-break: break-all; max-width: 165px;
}

/* ── Overview chips section (same as organizer .org-courses-section / .org-course-chip) ── */
.dir-chips-section { padding: 0.65rem 1rem; }
.dir-chips-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #777777; margin-bottom: 0.4rem; }
.dir-chip {
    font-size: 0.72rem; font-weight: 600; padding: 2px 9px; border-radius: 999px;
    background: #F0E6F8; color: #333333; border: 1px solid #D8BEF0;
    display: inline-block; margin: 2px 2px 2px 0;
}

/* ── Avatar (same size as organizer .org-avatar) ── */
.dir-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: rgba(255,255,255,0.22); border: 2px solid rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; font-weight: 700; color: #ffffff; flex-shrink: 0; letter-spacing: 0.04em;
}

/* ── Chart card ── */
.dir-chart-card {
    background: #F9F7FC;
    border-radius: 14px;
    border: 1px solid #E8E0F0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    cursor: pointer;
    transition: box-shadow .18s ease, border-color .18s ease;
}
.dir-chart-card:hover { box-shadow: 0 5px 16px rgba(122,63,145,.13); border-color: rgba(122,63,145,.35); }
.dir-chart-hint {
    font-size:.68rem; color:#bbb; font-weight:500;
    margin-left:auto; display:flex; align-items:center; gap:3px; pointer-events:none;
}

/* ── Modal close button (same as organizer .org-close-btn) ── */
.dir-close-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 16px; border-radius: 10px; background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2); color: #fff; font-size: .875rem;
    font-weight: 600; cursor: pointer; transition: background .15s;
}
.dir-close-btn:hover { background: rgba(255,255,255,.22); }

/* ── Pagination (same as organizer .org-pg-*) ── */
.dir-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; transition: all .15s; border: 1.5px solid transparent;
}
.dir-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
.dir-pg-nav { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
.dir-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.dir-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Scrollbar (same as organizer .org-scroll) ── */
.dir-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.dir-scroll::-webkit-scrollbar { width: 4px; }
.dir-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

/* ── Table rows (same as organizer .org-table-row) ── */
.dir-table-row { transition: background .10s; }
.dir-table-row:hover { background: #F5F0FA !important; }

/* ── Modal animation (same as organizer .org-modal-enter) ── */
@keyframes dirModalIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
.dir-modal-enter { animation: dirModalIn .2s cubic-bezier(.4,0,.2,1) both; }
</style>

<div id="__dir_dash_data" style="display:none"
     data-event="{{ $chartEventData }}"
     data-job="{{ $chartJobData }}">
</div>

<div class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto">

    {{-- PAGE HEADER (same style as organizer) --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
                {{ $greeting }}, Director
            </h1>
            <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                <span>{{ $currentDate }}</span>
                <span class="text-[#c0a0d8]">·</span>
                <span class="font-semibold text-[#7A3F91]">Director Portal</span>
            </p>
        </div>
    </div>

    <div class="dir-main-grid">

        {{-- ══ LEFT: Director Account Card ══ --}}
        <div class="dir-account-col">
            <div class="dir-account-card rounded-2xl overflow-hidden shadow-md border border-[#E8E0F0] bg-white">

                {{-- Header with avatar --}}
                <div class="px-4 py-4 shrink-0 flex items-center gap-3"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <div class="dir-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.60rem] font-bold uppercase tracking-[0.14em] text-white/60 leading-none mb-0.5">DIRECTOR ACCOUNT</p>
                        <p class="text-[0.95rem] font-bold text-white leading-snug truncate">{{ auth()->user()->name ?? 'Director' }}</p>
                        <p class="text-[0.72rem] text-white/70 font-normal truncate mt-0.5">Alumni Portal Admin</p>
                    </div>
                </div>

                {{-- Info body --}}
                <div class="dir-info-body dir-scroll">

                    <div class="dir-info-row">
                        <span class="dir-info-label">Name</span>
                        <span class="dir-info-value">{{ auth()->user()->name ?? 'Director' }}</span>
                    </div>

                    <div class="dir-info-row" style="align-items:flex-start;">
                        <span class="dir-info-label" style="margin-top:2px;">Email</span>
                        <span class="dir-info-value-sm">{{ auth()->user()->email ?? '—' }}</span>
                    </div>

                    <div class="dir-info-row">
                        <span class="dir-info-label">Role</span>
                        <span class="text-[0.80rem] font-bold px-3 py-0.5 rounded-full text-white" style="background:#7A3F91;">Director</span>
                    </div>

                    <div class="dir-info-row">
                        <span class="dir-info-label">Active Coordinators</span>
                        <span class="dir-info-value">
                            {{ $activeCoordinators }}
                            <span class="text-[#999999] font-normal">/ {{ $totalCoordinators }}</span>
                        </span>
                    </div>

                    {{-- Quick overview chips --}}
                    <div class="dir-chips-section">
                        <p class="dir-chips-label">Events Overview</p>
                        <div>
                            <span class="dir-chip">Approved · {{ $approvedEvents }}</span>
                            <span class="dir-chip">Pending · {{ $pendingEvents }}</span>
                            <span class="dir-chip">Completed · {{ $completedEvents }}</span>
                            @if($rejectedEvents > 0)
                                <span class="dir-chip">Rejected · {{ $rejectedEvents }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="dir-chips-section">
                        <p class="dir-chips-label">Job Postings</p>
                        <div>
                            <span class="dir-chip">
                                Active · {{ $activeJobs }}
                                @if($newJobsThisMonth > 0)
                                    <span class="text-[#7A3F91]">(+{{ $newJobsThisMonth }} this month)</span>
                                @endif
                            </span>
                            <span class="dir-chip">Inactive · {{ $inactiveJobs }}</span>
                        </div>
                    </div>

                </div>{{-- end info body --}}
            </div>
        </div>

        {{-- ══ RIGHT: Stat Cards + Events Overview ══ --}}
        <div class="dir-right-col">

            <div class="dir-stat-grid">

                {{-- Active Coordinators --}}
                <button wire:click="openCoordsModal('ACTIVE')"
                        class="dir-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-[#7A3F91]/40 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Active Coordinators</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-user-tie text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                     border border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">Coordinators</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($activeCoordinators) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Active Coordinators</p>
                    <p class="font-semibold mt-1 flex items-center gap-1 text-[0.85rem]" style="color:#7A3F91;">
                        <i class="fas fa-users text-xs"></i> {{ $totalCoordinators }} total
                        @if(($totalCoordinators - $activeCoordinators) > 0)
                            <span class="text-[#555555] font-normal">· {{ $totalCoordinators - $activeCoordinators }} inactive</span>
                        @endif
                    </p>
                </button>

                {{-- Total Events --}}
                <button wire:click="openTotalEventsModal"
                        class="dir-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-emerald-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Events</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                            <i class="fas fa-calendar-days text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                     border border-emerald-200 bg-emerald-50 text-[0.75rem]">Events</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($totalEvents) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Total Events</p>
                    @if($approvedEvents > 0)
                        <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                            <i class="fas fa-circle-check text-xs"></i> {{ $approvedEvents }} Approved
                        </p>
                    @endif
                </button>

                {{-- Pending Events --}}
                <button wire:click="openPendingEventsModal"
                        class="dir-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-amber-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Pending Events</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                            <i class="fas fa-hourglass-end text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                     border border-amber-200 bg-amber-50 text-[0.75rem]">Pending</span>
                    </div>
                    <p class="text-amber-600 font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($pendingEvents) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Pending Review</p>
                    @if($pendingEvents > 0)
                        <p class="text-amber-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                            <i class="fas fa-circle-exclamation text-xs"></i> Needs attention
                        </p>
                    @else
                        <p class="text-[#555555] font-normal mt-1 text-[0.85rem]">All clear</p>
                    @endif
                </button>

                {{-- Job Postings --}}
                <button wire:click="openJobsModal"
                        class="dir-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-5
                               hover:shadow-lg hover:border-blue-300 transition-all duration-200
                               active:scale-[.985] text-left cursor-pointer w-full">
                    <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Job Postings</span>
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                            <i class="fas fa-briefcase text-white text-lg"></i>
                        </div>
                        <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                     border border-blue-200 bg-blue-50 text-[0.75rem]">Jobs</span>
                    </div>
                    <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[3rem]">{{ number_format($totalJobs) }}</p>
                    <p class="text-[#111111] font-semibold mt-2 text-[1.05rem]">Job Postings</p>
                    <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                        <span class="text-[#555555] font-normal">· {{ $inactiveJobs }} Inactive</span>
                    </p>
                </button>

            </div>{{-- end stat grid --}}

            {{-- EVENTS OVERVIEW PANEL (same layout as organizer's Employment Overview) --}}
            @php
                $evtCards = [
                    ['label'=>'Pending',   'count'=>$pendingEvents,   'icon'=>'fa-hourglass-end',  'cardCls'=>'bg-amber-50 border-amber-200',     'iconCls'=>'bg-amber-100 text-amber-600',     'cntCls'=>'text-amber-700',  'action'=>'openPendingEventsModal',   'ctip'=>'View Pending Events'],
                    ['label'=>'Approved',  'count'=>$approvedEvents,  'icon'=>'fa-calendar-check', 'cardCls'=>'bg-emerald-50 border-emerald-200', 'iconCls'=>'bg-emerald-100 text-emerald-600', 'cntCls'=>'text-emerald-700','action'=>'openApprovedEventsModal',  'ctip'=>'View Approved Events'],
                    ['label'=>'Completed', 'count'=>$completedEvents, 'icon'=>'fa-flag-checkered', 'cardCls'=>'bg-blue-50 border-blue-200',       'iconCls'=>'bg-blue-100 text-blue-600',       'cntCls'=>'text-blue-700',   'action'=>'openCompletedEventsModal', 'ctip'=>'View Completed Events'],
                    ['label'=>'Rejected',  'count'=>$rejectedEvents,  'icon'=>'fa-circle-xmark',   'cardCls'=>'bg-red-50 border-red-200',         'iconCls'=>'bg-red-100 text-red-600',         'cntCls'=>'text-red-700',    'action'=>'openRejectedEventsModal',  'ctip'=>'View Rejected Events'],
                ];
            @endphp
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right, #F9F7FC, #ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-calendar-days text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Overview</p>
                        <span class="text-[10px] text-[#999999] font-normal hidden sm:inline">— click a card to view</span>
                    </div>
                    <a href="{{ route('director.event/management') }}" wire:navigate
                       class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                        Manage <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <div class="p-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                        @foreach($evtCards as $card)
                        <div wire:click="{{ $card['action'] }}"
                             class="dir-mini-card rounded-xl border p-3 {{ $card['cardCls'] }}">
                            <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>{{ $card['ctip'] }}</span>
                            <div class="flex items-center gap-1.5 mb-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $card['iconCls'] }}">
                                    <i class="fas {{ $card['icon'] }} text-xs"></i>
                                </div>
                                <span class="text-xs font-bold text-[#333333]">{{ $card['label'] }}</span>
                            </div>
                            <p class="text-2xl font-extrabold leading-none {{ $card['cntCls'] }}">{{ number_format($card['count']) }}</p>
                        </div>
                        @endforeach
                    </div>

                    {{-- Charts Row --}}
                    <div class="border-t border-[#E8E0F0] pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="dir-chart-card" onclick="dirOpenEventModal('')">
                            <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0]">
                                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                                    <i class="fas fa-calendar-days text-white" style="font-size:9px;"></i>
                                </div>
                                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Chart</p>
                                <span class="dir-chart-hint"><i class="fas fa-hand-pointer"></i> Click segment</span>
                            </div>
                            <div class="p-3 flex items-center justify-center" style="height:170px;" wire:ignore>
                                <canvas id="dChartEvent"></canvas>
                            </div>
                        </div>

                        <div class="dir-chart-card" onclick="dirOpenJobModal('')">
                            <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0]">
                                <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-blue-500">
                                    <i class="fas fa-briefcase text-white" style="font-size:9px;"></i>
                                </div>
                                <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Jobs Chart</p>
                                <span class="dir-chart-hint"><i class="fas fa-hand-pointer"></i> Click segment</span>
                            </div>
                            <div class="p-3 flex items-center justify-center" style="height:170px;" wire:ignore>
                                <canvas id="dChartJob"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- end right col --}}
    </div>{{-- end main grid --}}

</div>


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
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dir-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow" style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $evtFrom }}–{{ $evtTo }} of {{ $evtTotal }} event(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dir-close-btn"><i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span></button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('eventSearch', q)"
                       placeholder="Search event title…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 hidden sm:inline">Showing <strong class="text-gray-600">{{ $evtFrom }}–{{ $evtTo }}</strong> of <strong class="text-gray-600">{{ $evtTotal }}</strong></span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto min-h-0 dir-scroll">
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
                <tr class="dir-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5"><span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($evtFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900 uppercase">{{ $evt['title'] }}</p></td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['date'] }}</p>
                        <p class="text-xs text-gray-400">{{ $evt['time'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-xs text-gray-500">{{ $evt['created_at'] }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $evtSc[0] }}">
                            <i class="fas {{ $evtSc[1] }} text-xs"></i> {{ $evt['status'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;"><i class="fas fa-calendar-days text-2xl" style="color:#c89de0;"></i></div>
                        <p class="text-sm font-semibold text-gray-400">No events found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="background:#7A3F91;">
        <p class="text-white text-sm">Showing <strong class="font-bold text-base">{{ $evtFrom }}–{{ $evtTo }}</strong> of <strong class="font-bold text-base">{{ $evtTotal }}</strong> event(s)</p>
        @if($evtLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="eventPrevPage" {{ $evtSafePage <= 1 ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @for($p = max(1, $evtSafePage - 2); $p <= min($evtLastPage, $evtSafePage + 2); $p++)
                @if($p === $evtSafePage)<span class="dir-pg-btn dir-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('eventModalPage', {{ $p }})" class="dir-pg-btn dir-pg-nav">{{ $p }}</button>@endif
            @endfor
            <button wire:click="eventNextPage({{ $evtLastPage }})" {{ $evtSafePage >= $evtLastPage ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $evtSafePage }}/{{ $evtLastPage }}</span>
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
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dir-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow" style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $jobModalTitleText }}</h2>
                <p class="text-white/60 text-xs">{{ $jobFrom }}–{{ $jobTo }} of {{ $jobTotalCount }} job(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dir-close-btn"><i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span></button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('jobSearch', q)"
                       placeholder="Search title, company, location…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 hidden sm:inline">Showing <strong class="text-gray-600">{{ $jobFrom }}–{{ $jobTo }}</strong> of <strong class="text-gray-600">{{ $jobTotalCount }}</strong></span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto min-h-0 dir-scroll">
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
                @php $isUrgent = ($job['days_left'] ?? 99) <= 7; $isActive = $job['status'] === 'ACTIVE'; @endphp
                <tr class="dir-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5"><span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($jobFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900 truncate" style="max-width:180px;">{{ $job['title'] }}</p></td>
                    <td class="px-4 py-3.5"><p class="text-sm text-gray-600 truncate" style="max-width:140px;">{{ $job['company'] }}</p></td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border" style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $job['type'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden md:table-cell"><p class="text-sm text-gray-500">{{ $job['location'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell"><p class="text-sm font-semibold" style="color:#7A3F91;">{{ $job['salary'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        @if($isActive)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200"><i class="fas fa-circle text-[8px]"></i> Active</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-amber-700 bg-amber-50 border-amber-200"><i class="fas fa-circle text-[8px]"></i> Inactive</span>
                        @endif
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <p class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-gray-500' }}">
                            <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs mr-0.5"></i>{{ $job['deadline'] }}
                        </p>
                        @if($isUrgent)<p class="text-xs text-red-400 mt-0.5">{{ $job['days_left'] }}d left</p>@endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;"><i class="fas fa-briefcase text-2xl" style="color:#c89de0;"></i></div>
                        <p class="text-sm font-semibold text-gray-400">No job postings found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="background:#7A3F91;">
        <p class="text-white text-sm">Showing <strong class="font-bold text-base">{{ $jobFrom }}–{{ $jobTo }}</strong> of <strong class="font-bold text-base">{{ $jobTotalCount }}</strong> posting(s)</p>
        @if($jobLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="jobPrevPage" {{ $jobSafePage <= 1 ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @for($p = max(1, $jobSafePage - 2); $p <= min($jobLastPage, $jobSafePage + 2); $p++)
                @if($p === $jobSafePage)<span class="dir-pg-btn dir-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('jobModalPage', {{ $p }})" class="dir-pg-btn dir-pg-nav">{{ $p }}</button>@endif
            @endfor
            <button wire:click="jobNextPage({{ $jobLastPage }})" {{ $jobSafePage >= $jobLastPage ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $jobSafePage }}/{{ $jobLastPage }}</span>
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
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dir-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow" style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-user-tie text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $coordModalTitle }}</h2>
                <p class="text-white/60 text-xs">{{ $coordFrom }}–{{ $coordTo }} of {{ $coordTotal }} coordinator(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dir-close-btn"><i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span></button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.coordSearch??''; $wire.$watch('coordSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('coordSearch', q)"
                       placeholder="Search name, email, department…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 hidden sm:inline">Showing <strong class="text-gray-600">{{ $coordFrom }}–{{ $coordTo }}</strong> of <strong class="text-gray-600">{{ $coordTotal }}</strong></span>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto min-h-0 dir-scroll">
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
                <tr class="dir-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5"><span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($coordFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900">{{ $coord['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-sm text-gray-500 truncate" style="max-width:200px;">{{ $coord['email'] }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $coord['department'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-xs text-gray-400">{{ $coord['created_at'] }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        @if($coord['status'] === 'ACTIVE')
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200"><i class="fas fa-circle text-[8px]"></i> Active</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200"><i class="fas fa-circle text-[8px]"></i> {{ $coord['status'] }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;"><i class="fas fa-user-tie text-2xl" style="color:#c89de0;"></i></div>
                        <p class="text-sm font-semibold text-gray-400">No coordinators found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="background:#7A3F91;">
        <p class="text-white text-sm">Showing <strong class="font-bold text-base">{{ $coordFrom }}–{{ $coordTo }}</strong> of <strong class="font-bold text-base">{{ $coordTotal }}</strong> coordinator(s)</p>
        @if($coordLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="coordPrevPage" {{ $coordSafePage <= 1 ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @for($p = max(1, $coordSafePage - 2); $p <= min($coordLastPage, $coordSafePage + 2); $p++)
                @if($p === $coordSafePage)<span class="dir-pg-btn dir-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('coordModalPage', {{ $p }})" class="dir-pg-btn dir-pg-nav">{{ $p }}</button>@endif
            @endfor
            <button wire:click="coordNextPage({{ $coordLastPage }})" {{ $coordSafePage >= $coordLastPage ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $coordSafePage }}/{{ $coordLastPage }}</span>
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

    window.dirOpenEventModal = function (filter) {
        window.dispatchEvent(new CustomEvent('open-dir-event-modal', { detail: { filter: filter || '' } }));
    };
    window.dirOpenJobModal = function (filter) {
        window.dispatchEvent(new CustomEvent('open-dir-job-modal', { detail: { filter: filter || '' } }));
    };

    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb; document.head.appendChild(s);
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

    function buildDonut(id, data, openModalFn) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        safeDestroy(id);
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 9, weight: '600', family: 'inherit' },
                            color: function (ctx) { return data.colors[ctx.index] || '#333'; },
                            padding: 6, usePointStyle: true, pointStyleWidth: 6,
                        },
                        onClick: function (e, legendItem, legend) {
                            if (e && e.native) e.native.stopPropagation();
                            var chart = legend.chart, index = legendItem.index;
                            if (chart.getDataVisibility(index)) { chart.hide(index); } else { chart.show(index); }
                            chart.update();
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var idx = elements[0].index;
                        var filter = (data.filters && data.filters[idx]) ? data.filters[idx] : '';
                        openModalFn(filter);
                    }
                },
            },
        });
    }

    function initAll() {
        var d = readData();
        if (!d) return;
        buildDonut('dChartEvent', d.event, function (f) { window.dispatchEvent(new CustomEvent('open-dir-event-modal', { detail: { filter: f } })); });
        buildDonut('dChartJob',   d.job,   function (f) { window.dispatchEvent(new CustomEvent('open-dir-job-modal',   { detail: { filter: f } })); });
    }

    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { requestAnimationFrame(initAll); });
        } else {
            requestAnimationFrame(initAll);
        }
        document.addEventListener('livewire:navigated', function () {
            safeDestroy('dChartEvent'); safeDestroy('dChartJob'); requestAnimationFrame(initAll);
        });
        function hookLivewire() {
            if (!window.Livewire) return;
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || function (cb) { cb({}); };
                if (typeof succeed === 'function') { succeed(function () { requestAnimationFrame(initAll); }); }
            });
        }
        if (window.Livewire) { hookLivewire(); } else { document.addEventListener('livewire:initialized', hookLivewire); }
    });
})();
</script>