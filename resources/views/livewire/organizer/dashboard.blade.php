{{-- resources/views/livewire/organizer/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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

    public int $totalJobs        = 0;
    public int $activeJobs       = 0;
    public int $inactiveJobs     = 0;

    public int $empEmployed      = 0;
    public int $empSelf          = 0;
    public int $empUnemployed    = 0;
    public int $empNotFilled     = 0;

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
};
?>

<div class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto w-full">

<style>
/* ── Stat card tooltip ── */
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
.org-stat-card:hover .org-card-tip { opacity: 1; }

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
.org-mini-card:hover .org-mini-tip { opacity: 1; }

/* ── Main grid ── */
.org-main-grid { display: grid; grid-template-columns: 290px 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1023px) { .org-main-grid { grid-template-columns: 1fr; } }

/* ── Account column ── */
.org-account-card { display: flex; flex-direction: column; }

/* ── Right col ── */
.org-right-col { display: flex; flex-direction: column; gap: 1rem; }

/* ── 2x2 stat grid ── */
.org-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.org-stat-grid .org-stat-card { display: flex; flex-direction: column; justify-content: center; }

/* ── Info rows ── */
.org-info-body { display: flex; flex-direction: column; }
.org-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.org-info-row:last-child { border-bottom: none; }
.org-info-label { font-size: 0.70rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #555555; flex-shrink: 0; }
.org-info-value { font-size: 0.875rem; font-weight: 600; color: #111111; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 160px; }
.org-info-value-sm { font-size: 0.80rem; font-weight: 600; color: #111111; text-align: right; word-break: break-all; max-width: 160px; }

/* ── Chips ── */
.org-chips-section { padding: 0.65rem 1rem; }
.org-chips-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #777777; margin-bottom: 0.4rem; }
.org-chip { font-size: 0.72rem; font-weight: 600; padding: 2px 9px; border-radius: 999px; background: #F0E6F8; color: #333333; border: 1px solid #D8BEF0; display: inline-block; margin: 2px 2px 2px 0; }

/* ── Avatar ── */
.org-avatar { width: 52px; height: 52px; border-radius: 50%; background: rgba(255,255,255,0.22); border: 2px solid rgba(255,255,255,0.5); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; font-weight: 700; color: #ffffff; flex-shrink: 0; letter-spacing: 0.04em; }

/* ── Scrollbar ── */
.org-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.org-scroll::-webkit-scrollbar { width: 4px; }
.org-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

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
            <span class="text-[#c0a0d8]">·</span>
            <span class="font-semibold text-[#7A3F91]">Coordinator Portal</span>
        </p>
    </div>
</div>

<div class="org-main-grid">

    {{-- ══ LEFT: Account Card ══ --}}
    <div>
        <div class="org-account-card rounded-2xl overflow-hidden shadow-md border border-[#E8E0F0] bg-white">

            {{-- Header --}}
            <div class="px-4 py-4 shrink-0 flex items-center gap-3"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <div class="org-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[0.60rem] font-bold uppercase tracking-[0.14em] text-white/60 leading-none mb-0.5">COORDINATOR ACCOUNT</p>
                    <p class="text-[0.95rem] font-bold text-white leading-snug truncate">{{ $this->organizerName }}</p>
                    <p class="text-[0.72rem] text-white/70 font-normal truncate mt-0.5">{{ $this->organizerDepartment ?: 'Event Coordinator' }}</p>
                </div>
            </div>

            {{-- Info rows --}}
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

                <div class="org-info-row">
                    <span class="org-info-label">Total Alumni</span>
                    <span class="org-info-value">
                        {{ number_format($totalAlumni) }}
                        <span class="text-[#999999] font-normal text-xs ml-1">total</span>
                    </span>
                </div>

                <div class="org-info-row">
                    <span class="org-info-label">Verified</span>
                    <span class="org-info-value text-emerald-700">{{ number_format($verifiedAlumni) }}</span>
                </div>

                {{-- Quick chips --}}
                <div class="org-chips-section">
                    <p class="org-chips-label">Events Overview</p>
                    <div>
                        <span class="org-chip">Approved · {{ $approvedEvents }}</span>
                        <span class="org-chip">Pending · {{ $pendingEvents }}</span>
                        <span class="org-chip">Completed · {{ $completedEvents }}</span>
                        @if($rejectedEvents > 0)
                            <span class="org-chip">Rejected · {{ $rejectedEvents }}</span>
                        @endif
                    </div>
                </div>

                <div class="org-chips-section">
                    <p class="org-chips-label">Job Postings</p>
                    <div>
                        <span class="org-chip">Active · {{ $activeJobs }}</span>
                        <span class="org-chip">Inactive · {{ $inactiveJobs }}</span>
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
            <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
               class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3.5
                      hover:shadow-lg hover:border-[#7A3F91]/40 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Employment Tracking</span>
                <div class="flex items-start justify-between mb-2">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users text-white text-sm"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                 border border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">Alumni</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.2rem]">{{ number_format($totalAlumni) }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.9rem]">Total Alumni</p>
                <p class="font-semibold mt-0.5 flex items-center gap-1 text-[0.78rem] text-emerald-600">
                    <i class="fas fa-circle-check text-xs"></i> {{ number_format($verifiedAlumni) }} verified
                    @if($pendingAlumni > 0)
                        <span class="text-amber-500 font-normal">· {{ $pendingAlumni }} pending</span>
                    @endif
                </p>
            </a>

            {{-- Total Events --}}
            <a href="{{ route('organizer.event/organizer') }}" wire:navigate
               class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3.5
                      hover:shadow-lg hover:border-emerald-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Events</span>
                <div class="flex items-start justify-between mb-2">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-sm"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                 border border-emerald-200 bg-emerald-50 text-[0.75rem]">Events</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.2rem]">{{ number_format($totalEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.9rem]">Total Events</p>
                @if($approvedEvents > 0)
                    <p class="text-emerald-600 font-semibold mt-0.5 flex items-center gap-1 text-[0.78rem]">
                        <i class="fas fa-circle-check text-xs"></i> {{ $approvedEvents }} Approved
                    </p>
                @elseif($pendingEvents > 0)
                    <p class="text-amber-500 font-semibold mt-0.5 flex items-center gap-1 text-[0.78rem]">
                        <i class="fas fa-hourglass-half text-xs"></i> {{ $pendingEvents }} Pending
                    </p>
                @else
                    <p class="text-[#555555] font-normal mt-0.5 text-[0.78rem]">No active events</p>
                @endif
            </a>

            {{-- Job Postings --}}
            <a href="{{ route('organizer.job/management') }}" wire:navigate
               class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3.5
                      hover:shadow-lg hover:border-blue-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Job Postings</span>
                <div class="flex items-start justify-between mb-2">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-briefcase text-white text-sm"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                 border border-blue-200 bg-blue-50 text-[0.75rem]">Jobs</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.2rem]">{{ number_format($totalJobs) }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.9rem]">Job Postings</p>
                <p class="text-emerald-600 font-semibold mt-0.5 flex items-center gap-1 text-[0.78rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                    <span class="text-[#555555] font-normal">· {{ $inactiveJobs }} Inactive</span>
                </p>
            </a>

            {{-- Employment --}}
            <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
               class="org-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-3.5
                      hover:shadow-lg hover:border-amber-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block">
                <span class="org-card-tip"><i class="fas fa-eye mr-1.5"></i>View Employment</span>
                <div class="flex items-start justify-between mb-2">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-amber-500">
                        <i class="fas fa-chart-line text-white text-sm"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                 border border-amber-200 bg-amber-50 text-[0.75rem]">Employment</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.2rem]">{{ number_format($empEmployed + $empSelf) }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.9rem]">Employed</p>
                @php $filled = $empEmployed + $empSelf + $empUnemployed; $rate = $filled > 0 ? round((($empEmployed + $empSelf) / $filled) * 100) : 0; @endphp
                <p class="text-amber-600 font-semibold mt-0.5 flex items-center gap-1 text-[0.78rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $rate }}% emp. rate
                    @if($empNotFilled > 0)
                        <span class="text-[#555555] font-normal">· {{ $empNotFilled }} not filled</span>
                    @endif
                </p>
            </a>

        </div>

        {{-- Course Breakdown Panel --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden org-fade-up org-fade-2">
            <div class="px-5 py-3 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-ranking-star text-white text-[10px]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Alumni per Course</p>
                    <span class="text-[10px] text-[#999999] font-normal hidden sm:inline">— {{ $this->organizerDepartment ?: 'your college' }}</span>
                </div>
                <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    Employment <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="p-4">
                @if(count($courseStats) > 0)
                @php
                    $maxCount = max(array_column($courseStats, 'alumni_count') ?: [1]);
                    $maxCount = $maxCount < 1 ? 1 : $maxCount;
                    $palette  = ['#7A3F91','#9b59b6','#c0a0d8','#2563eb','#059669','#d97706','#ef4444','#0891b2','#65a30d','#db2777'];
                @endphp
                <div class="space-y-3">
                    @foreach($courseStats as $idx => $cs)
                    @php
                        $pct   = round(($cs->alumni_count / $maxCount) * 100);
                        $color = $palette[$idx % count($palette)];
                    @endphp
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0 text-[.58rem] font-bold text-white"
                                     style="background:{{ $color }};">{{ $idx + 1 }}</div>
                                <span class="text-[.78rem] font-bold text-[#333333] font-mono uppercase truncate">{{ $cs->code }}</span>
                                <span class="text-[.68rem] text-[#777777] truncate hidden sm:inline">{{ $cs->name }}</span>
                            </div>
                            <span class="text-[.78rem] font-bold text-[#333333] ml-2 flex-shrink-0">
                                {{ number_format($cs->alumni_count) }}
                                <span class="text-[#999999] font-normal text-[.68rem]">alumni</span>
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full overflow-hidden" style="background:#F0E8F8;">
                            <div class="h-full rounded-full transition-all duration-700" style="width:{{ $pct }}%; background:{{ $color }};"></div>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-4 pt-3 border-t border-[#E8E0F0] flex items-center justify-between">
                    <p class="text-[.72rem] font-semibold text-[#7A3F91]">
                        {{ count($courseStats) }} course{{ count($courseStats) !== 1 ? 's' : '' }} total
                    </p>
                    <p class="text-[.68rem] text-[#999999] font-normal">
                        Total alumni: {{ number_format($totalAlumni) }}
                    </p>
                </div>

                @else
                <div class="py-10 text-center">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f5eef9;">
                        <i class="fas fa-users text-[#d4aaeb] text-xl"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333]">No alumni registered yet</p>
                    <p class="text-xs text-[#777777] mt-1">Alumni enrolled in {{ $this->organizerDepartment ?: 'your college' }} will appear here.</p>
                </div>
                @endif
            </div>
        </div>

        {{-- Employment Breakdown Mini-tiles --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden org-fade-up org-fade-3">
            <div class="px-5 py-3 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-amber-500">
                        <i class="fas fa-chart-pie text-white text-[10px]"></i>
                    </div>
                    <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Employment Snapshot</p>
                </div>
                <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    Full View <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach([
                    ['label' => 'Employed',     'count' => $empEmployed,   'color' => 'text-emerald-700', 'bg' => 'bg-emerald-50 border-emerald-200'],
                    ['label' => 'Self-Employed', 'count' => $empSelf,       'color' => 'text-blue-700',    'bg' => 'bg-blue-50 border-blue-200'],
                    ['label' => 'Unemployed',    'count' => $empUnemployed, 'color' => 'text-amber-700',   'bg' => 'bg-amber-50 border-amber-200'],
                    ['label' => 'Not Filled',    'count' => $empNotFilled,  'color' => 'text-gray-500',    'bg' => 'bg-gray-50 border-gray-200'],
                ] as $tile)
                <div class="rounded-xl border p-3 {{ $tile['bg'] }}">
                    <p class="text-2xl font-extrabold leading-none {{ $tile['color'] }}">{{ number_format($tile['count']) }}</p>
                    <p class="text-xs font-bold text-[#333333] uppercase tracking-wide mt-1.5">{{ $tile['label'] }}</p>
                </div>
                @endforeach
            </div>
        </div>

    </div>
</div>

</div>
