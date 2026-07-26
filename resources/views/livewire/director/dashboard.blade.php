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
    public array  $modalCoords      = [];

    public string $coordSearch  = '';

    public int $coordModalPage    = 1;
    public int $coordModalSize    = 20;

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

    /**
     * Sends the director to the coordinator management page with the
     * "Active" status filter pre-applied. We use the session (instead of
     * a route/query parameter) so the URL stays clean:
     *   /director/coordinator/management
     * The management page's mount() pulls this value once, applies it
     * to the filter, then clears it — so a plain refresh afterwards goes
     * back to showing all statuses, which is expected.
     */
    public function goToActiveCoordinators()
    {
        session()->put('director_coord_status', 'ACTIVE');
        return $this->redirect(route('director.coordinator/management'), navigate: true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // NEW: Job stat card / mini-tile clicks now navigate straight to the
    // Job Management page (director.job/management) instead of opening a
    // modal on the dashboard — same clean-URL + session pattern already
    // used above for goToActiveCoordinators().
    //
    // 'director_job_status' is read once in manage-job.blade.php's mount()
    // via session()->pull(), applied to $filterStatus, then cleared — so a
    // plain refresh of the job management page afterwards goes back to
    // showing every status (ACTIVE + INACTIVE + ORGANIZER_DELETED), same
    // as normal. This mirrors the existing coordinator-filter pattern.
    //
    //   goToAllJobs()      -> Total Jobs card      -> no filter (all)
    //   goToActiveJobs()   -> Active mini-tile      -> filterStatus=ACTIVE
    //   goToInactiveJobs() -> Inactive mini-tile    -> filterStatus=INACTIVE
    // ─────────────────────────────────────────────────────────────────────
    public function goToAllJobs()
    {
        session()->put('director_job_status', '');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    public function goToActiveJobs()
    {
        session()->put('director_job_status', 'ACTIVE');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    public function goToInactiveJobs()
    {
        session()->put('director_job_status', 'INACTIVE');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    public function updatingCoordSearch(): void { $this->coordModalPage = 1; }

    public function coordPrevPage(): void { if ($this->coordModalPage > 1) $this->coordModalPage--; }
    public function coordNextPage(int $last): void { if ($this->coordModalPage < $last) $this->coordModalPage++; }
};
?>

<div
    class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto w-full"
>

<style>
/* ── Stat card tooltip (desktop only — no tooltip text on mobile) ── */
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
@media (min-width: 1024px) {
    .dir-stat-card:hover .dir-card-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .dir-stat-card .dir-card-tip { display: none !important; }
}

/* ── Mini cards (clickable stat tiles) ── */
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
@media (min-width: 1024px) {
    .dir-mini-card:hover .dir-mini-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .dir-mini-card .dir-mini-tip { display: none !important; }
}

/* ── Main grid ── */
.dir-main-grid { display: grid; grid-template-columns: 300px 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1023px) {
    .dir-main-grid { grid-template-columns: 1fr; gap: 0.85rem; }
}

.dir-account-col { display: flex; flex-direction: column; }
.dir-account-card { display: flex; flex-direction: column; }

.dir-right-col { display: flex; flex-direction: column; gap: 1rem; }

.dir-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.dir-stat-grid .dir-stat-card { display: flex; flex-direction: column; justify-content: center; }
@media (max-width: 639px) {
    .dir-stat-grid { grid-template-columns: 1fr; gap: 0.65rem; }
    .dir-stat-grid .dir-stat-card { padding: 1rem !important; }
    .dir-stat-grid .dir-stat-card .dir-stat-num { font-size: 2.1rem !important; }
}
@media (min-width: 640px) and (max-width: 1023px) {
    .dir-stat-grid .dir-stat-card .dir-stat-num { font-size: 2.4rem !important; }
}

.dir-info-body { display: flex; flex-direction: column; }
.dir-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.dir-info-row:last-child { border-bottom: none; }
.dir-info-label { font-size: 0.70rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; flex-shrink: 0; }
.dir-info-value { font-size: 0.875rem; font-weight: 600; color: #111111; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 160px; }
.dir-info-value-sm { font-size: 0.80rem; font-weight: 600; color: #111111; text-align: right; word-break: break-all; max-width: 160px; }

.dir-chips-section { padding: 0.65rem 1rem; }
.dir-chips-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; margin-bottom: 0.4rem; }

.dir-chip {
    font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px;
    display: inline-flex; align-items: center; gap: 4px;
    border: 1px solid transparent; margin: 2px 4px 2px 0;
}
.dir-chip i { font-size: 7px; }

.dir-chip-approved  { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-pending   { background: #FEF6E7; color: #B5750A; border-color: #FBE4B4; }
.dir-chip-completed { background: #EAF1FE; color: #1D4ED8; border-color: #C9DBFC; }
.dir-chip-rejected  { background: #FDECEC; color: #C0311A; border-color: #F8C9C2; }

.dir-chip-active   { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-inactive { background: #F1F1F3; color: #52525B; border-color: #E1E1E5; }

.dir-chip-coord-active   { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-coord-inactive { background: #F1F1F3; color: #52525B; border-color: #E1E1E5; }

.dir-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.dir-scroll::-webkit-scrollbar { width: 4px; }
.dir-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

.dir-mini-tile { padding: 0.45rem 0.6rem !important; }
.dir-mini-tile .dir-mini-num { font-size: 1.1rem !important; line-height: 1 !important; }
.dir-mini-tile .dir-mini-label { font-size: 0.6rem !important; margin-top: 0.15rem !important; }

.dir-close-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 16px; border-radius: 10px; background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2); color: #fff; font-size: .875rem;
    font-weight: 600; cursor: pointer; transition: background .15s;
}
.dir-close-btn:hover { background: rgba(255,255,255,.22); }

.dir-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; transition: all .15s; border: 1.5px solid transparent;
}
.dir-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
.dir-pg-nav { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
.dir-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.dir-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

.dir-table-row { transition: background .10s; }
.dir-table-row:hover { background: #F5F0FA !important; }

@keyframes dirModalIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
.dir-modal-enter { animation: dirModalIn .2s cubic-bezier(.4,0,.2,1) both; }

@keyframes dirFadeUp { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:none } }
.dir-fade-up { animation: dirFadeUp .4s cubic-bezier(.25,.8,.25,1) both; }
.dir-fade-1 { animation-delay:.04s } .dir-fade-2 { animation-delay:.08s }
.dir-fade-3 { animation-delay:.12s } .dir-fade-4 { animation-delay:.16s }
</style>

{{-- ── PAGE HEADER ── --}}
<div class="flex items-center gap-3 mb-5 dir-fade-up">
    <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <i class="fas fa-gauge-high text-white text-base"></i>
    </div>
    <div>
        <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
            {{ $greeting }}, Director
        </h1>
        <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
            <i class="fas fa-circle text-[5px] text-emerald-500 align-middle"></i>
            <span>{{ $currentDate }}</span>
        </p>
    </div>
</div>

{{-- ══ MAIN GRID ══ --}}
<div class="dir-main-grid">

    {{-- ══ LEFT: Director Account Card ══ --}}
    <div class="dir-account-col dir-fade-up">
        <div class="dir-account-card rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm bg-white">

            <div class="relative w-full overflow-hidden shrink-0 h-[400px] sm:h-[240px]"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <div class="w-full h-full flex items-center justify-center">
                    <div class="w-24 h-24 sm:w-20 sm:h-20 rounded-full flex items-center justify-center font-black text-white text-[2.4rem] sm:text-[2rem]"
                         style="background:rgba(255,255,255,0.16); border:2px solid rgba(255,255,255,0.4);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
                <div class="absolute inset-0" style="background:linear-gradient(to bottom, transparent 35%, rgba(0,0,0,.55) 100%);"></div>
                <div class="absolute bottom-0 left-0 right-0 px-4 pb-4">
                    <p class="text-white font-bold uppercase leading-tight tracking-wide text-[1.1rem] sm:text-[1.15rem]"
                       style="text-shadow:0 1px 5px rgba(0,0,0,.6);">
                        {{ auth()->user()->name ?? 'Director' }}
                    </p>
                    <p class="font-mono text-[0.78rem] sm:text-[0.8rem]" style="color:rgba(255,255,255,.75);">
                        Alumni Portal Admin
                    </p>
                </div>
            </div>

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
                    <span class="dir-info-value text-[#7A3F91] font-bold">Director</span>
                </div>

                <div class="dir-info-row">
                    <span class="dir-info-label">Active Coordinators</span>
                    <span class="dir-info-value">
                        {{ $activeCoordinators }}
                        <span class="text-[#333333] font-normal text-xs ml-1">/ {{ $totalCoordinators }}</span>
                    </span>
                </div>

                <div class="dir-chips-section">
                    <p class="dir-chips-label">Events Overview</p>
                    <div>
                        <span class="dir-chip dir-chip-approved"><i class="fas fa-circle"></i>Approved · {{ $approvedEvents }}</span>
                        <span class="dir-chip dir-chip-pending"><i class="fas fa-circle"></i>Pending · {{ $pendingEvents }}</span>
                        <span class="dir-chip dir-chip-completed"><i class="fas fa-circle"></i>Completed · {{ $completedEvents }}</span>
                        @if($rejectedEvents > 0)
                            <span class="dir-chip dir-chip-rejected"><i class="fas fa-circle"></i>Rejected · {{ $rejectedEvents }}</span>
                        @endif
                    </div>
                </div>

                <div class="dir-chips-section">
                    <p class="dir-chips-label">Job Postings</p>
                    <div>
                        <span class="dir-chip dir-chip-active">
                            <i class="fas fa-circle"></i>Active · {{ $activeJobs }}
                            @if($newJobsThisMonth > 0)
                                <span class="font-normal">(+{{ $newJobsThisMonth }} this month)</span>
                            @endif
                        </span>
                        <span class="dir-chip dir-chip-inactive"><i class="fas fa-circle"></i>Inactive · {{ $inactiveJobs }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ══ RIGHT: Stats + Breakdown Panels ══ --}}
    <div class="dir-right-col">

        {{-- 2x2 Stat Cards --}}
        <div class="dir-stat-grid dir-fade-up dir-fade-1">

            {{-- Active Coordinators — clean URL, filter pre-set via session --}}
            <button type="button" wire:click="goToActiveCoordinators"
               class="dir-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-[#7A3F91]/40 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Active Coordinators</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-user-tie text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                 border border-[#E8E0F0] bg-[#F9F7FC] text-[0.7rem] sm:text-[0.75rem]">Coordinators</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($activeCoordinators) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Active Coordinators</p>
                <p class="font-semibold mt-1 flex items-center gap-1 text-[0.85rem]" style="color:#7A3F91;">
                    <i class="fas fa-users text-xs"></i> {{ $totalCoordinators }} total
                    @if(($totalCoordinators - $activeCoordinators) > 0)
                        <span class="text-[#333333] font-normal">· {{ $totalCoordinators - $activeCoordinators }} inactive</span>
                    @endif
                </p>
            </button>

            {{-- Total Events — clean URL: /director/event/management (no status segment) --}}
            <a href="{{ route('director.event/management') }}" wire:navigate
               class="dir-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-emerald-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                 border border-emerald-200 bg-emerald-50 text-[0.7rem] sm:text-[0.75rem]">Events</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Total Events</p>
                @if($approvedEvents > 0)
                    <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle-check text-xs"></i> {{ $approvedEvents }} Approved
                    </p>
                @else
                    <p class="text-[#333333] font-normal mt-1 text-[0.85rem]">No approved events yet</p>
                @endif
            </a>

            {{-- Pending Events — clean URL: /director/event/management/pending --}}
            <a href="{{ route('director.event/management', ['status' => 'pending']) }}" wire:navigate
               class="dir-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-amber-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Pending Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                        <i class="fas fa-hourglass-end text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                 border border-amber-200 bg-amber-50 text-[0.7rem] sm:text-[0.75rem]">Pending</span>
                </div>
                <p class="dir-stat-num text-amber-600 font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($pendingEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Pending Review</p>
                @if($pendingEvents > 0)
                    <p class="text-amber-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle-exclamation text-xs"></i> Needs attention
                    </p>
                @else
                    <p class="text-[#333333] font-normal mt-1 text-[0.85rem]">All clear</p>
                @endif
            </a>

            {{-- Job Postings — clean URL, filter pre-set via session (same pattern as Active Coordinators) --}}
            <button type="button" wire:click="goToAllJobs"
                    class="dir-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-md hover:border-blue-300 transition-all duration-200
                           active:scale-[.985] cursor-pointer block text-left w-full">
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Job Postings</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-briefcase text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                 border border-blue-200 bg-blue-50 text-[0.7rem] sm:text-[0.75rem]">Jobs</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalJobs) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Job Postings</p>
                <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                    <span class="text-[#333333] font-normal">· {{ $inactiveJobs }} Inactive</span>
                </p>
            </button>

        </div>

        {{-- Side-by-side breakdown panels (chart cards removed) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 dir-fade-up dir-fade-2">

            {{-- Events Overview Panel — mini tiles now link to clean filtered URLs --}}
            @php
                $evtCards = [
                    ['label'=>'Pending',   'count'=>$pendingEvents,   'icon'=>'fa-hourglass-end',  'bg'=>'bg-amber-50 border-amber-200',     'color'=>'text-amber-700',   'status'=>'pending',   'ctip'=>'View Pending Events'],
                    ['label'=>'Approved',  'count'=>$approvedEvents,  'icon'=>'fa-calendar-check', 'bg'=>'bg-emerald-50 border-emerald-200', 'color'=>'text-emerald-700', 'status'=>'approved',  'ctip'=>'View Approved Events'],
                    ['label'=>'Completed', 'count'=>$completedEvents, 'icon'=>'fa-flag-checkered', 'bg'=>'bg-blue-50 border-blue-200',       'color'=>'text-blue-700',    'status'=>'completed', 'ctip'=>'View Completed Events'],
                    ['label'=>'Rejected',  'count'=>$rejectedEvents,  'icon'=>'fa-circle-xmark',   'bg'=>'bg-red-50 border-red-200',         'color'=>'text-red-700',     'status'=>'rejected',  'ctip'=>'View Rejected Events'],
                ];
            @endphp
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-calendar-days text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Overview</p>
                    </div>
                    <a href="{{ route('director.event/management') }}" wire:navigate
                       class="text-[.68rem] font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                        Manage <i class="fas fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <div class="p-3 flex-1">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($evtCards as $card)
                        <a href="{{ route('director.event/management', ['status' => $card['status']]) }}" wire:navigate
                           class="dir-mini-card dir-mini-tile rounded-xl border {{ $card['bg'] }} block w-full text-left">
                            <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>{{ $card['ctip'] }}</span>
                            <div class="flex items-center gap-1.5 mb-1">
                                <i class="fas {{ $card['icon'] }} text-[10px] {{ $card['color'] }}"></i>
                                <span class="text-[.68rem] font-bold text-[#333333] uppercase tracking-wide">{{ $card['label'] }}</span>
                            </div>
                            <p class="dir-mini-num font-extrabold leading-none {{ $card['color'] }}">{{ number_format($card['count']) }}</p>
                        </a>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Job Postings Panel — mini tiles now navigate to Job Management with auto-filter (session pattern) --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-blue-600">
                            <i class="fas fa-briefcase text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Job Postings</p>
                    </div>
                    <a href="{{ route('director.job/management') }}" wire:navigate
                       class="text-[.68rem] font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                        Manage <i class="fas fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <div class="p-3 grid grid-cols-2 gap-2 content-start flex-1">
                    <button type="button" wire:click="goToActiveJobs"
                            class="dir-mini-card dir-mini-tile rounded-xl border bg-emerald-50 border-emerald-200 block w-full text-left">
                        <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>View Active Jobs</span>
                        <p class="dir-mini-num font-extrabold leading-none text-emerald-700">{{ number_format($activeJobs) }}</p>
                        <p class="dir-mini-label font-bold text-[#333333] uppercase tracking-wide">Active</p>
                    </button>
                    <button type="button" wire:click="goToInactiveJobs"
                            class="dir-mini-card dir-mini-tile rounded-xl border bg-gray-50 border-gray-200 block w-full text-left">
                        <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>View Inactive Jobs</span>
                        <p class="dir-mini-num font-extrabold leading-none text-[#333333]">{{ number_format($inactiveJobs) }}</p>
                        <p class="dir-mini-label font-bold text-[#333333] uppercase tracking-wide">Inactive</p>
                    </button>
                </div>
            </div>

        </div>{{-- end side-by-side grid --}}

    </div>{{-- end right col --}}
</div>{{-- end main grid --}}


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