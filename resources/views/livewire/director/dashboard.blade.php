{{-- resources/views/livewire/director/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use App\Models\Organizer;
use App\Models\Alumni;
use App\Models\AuditLog;

new class extends Component {

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int    $totalAlumni        = 0;
    public int    $totalVerified      = 0;
    public int    $totalPending       = 0;
    public int    $totalCoordinators  = 0;
    public int    $activeCoordinators = 0;
    public int    $totalEvents        = 0;
    public int    $pendingEvents      = 0;
    public int    $approvedEvents     = 0;
    public int    $totalJobs          = 0;
    public int    $activeJobs         = 0;
    public int    $totalEmployed      = 0;
    public int    $totalUnemployed    = 0;
    public int    $totalNotFilled     = 0;
    public float  $employmentRate     = 0;
    public int    $newAlumniThisMonth = 0;
    public int    $newJobsThisMonth   = 0;

    // ── Chart data ────────────────────────────────────────────────────────────
    public string $chartEmploymentData = '{}';
    public string $chartBatchData      = '{}';
    public string $chartCollegeData    = '{}';
    public string $chartEventData      = '{}';

    // ── Recent data ──────────────────────────────────────────────────────────
    public array $recentAlumni    = [];
    public array $recentEvents    = [];
    public array $recentJobs      = [];
    public array $recentAuditLogs = [];
    public array $collegeStats    = [];

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
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };

        $this->loadStats();
        $this->loadCharts();
        $this->loadRecent();
        $this->loadCollegeStats();
    }

    private function loadStats(): void
    {
        $this->totalAlumni        = Alumni::count();
        $this->totalVerified      = Alumni::where('status', 'VERIFIED')->count();
        $this->totalPending       = Alumni::where('status', 'PENDING')->count();
        $this->totalCoordinators  = Organizer::withoutTrashed()->count();
        $this->activeCoordinators = Organizer::withoutTrashed()->where('status', 'ACTIVE')->count();
        $this->totalEvents        = AdminEvent::withTrashed()->count();
        $this->pendingEvents      = AdminEvent::withoutTrashed()->where('status', 'PENDING')->count();
        $this->approvedEvents     = AdminEvent::withoutTrashed()->where('status', 'APPROVED')->count();
        $this->totalJobs          = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])->count();
        $this->activeJobs         = JobPosting::where('status', 'ACTIVE')->count();

        $empBase = DB::table('employment_trackings')->whereNull('deleted_at');
        $this->totalEmployed   = (clone $empBase)->whereIn('employment_status', ['employed', 'self_employed'])->count();
        $this->totalUnemployed = (clone $empBase)->where('employment_status', 'unemployed')->count();
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalEmployed - $this->totalUnemployed);
        $this->employmentRate  = $this->totalAlumni > 0
            ? round(($this->totalEmployed / $this->totalAlumni) * 100, 1) : 0;

        $monthStart = now('Asia/Manila')->startOfMonth()->utc();
        $this->newAlumniThisMonth = Alumni::where('created_at', '>=', $monthStart)->count();
        $this->newJobsThisMonth   = JobPosting::where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['ADMIN_DELETED'])->count();
    }

    private function loadCharts(): void
    {
        // Employment breakdown
        $this->chartEmploymentData = json_encode([
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [
                DB::table('employment_trackings')->whereNull('deleted_at')->where('employment_status','employed')->count(),
                DB::table('employment_trackings')->whereNull('deleted_at')->where('employment_status','self_employed')->count(),
                $this->totalUnemployed,
                $this->totalNotFilled,
            ],
            'colors' => ['#10b981','#3b82f6','#f59e0b','#e5e7eb'],
        ]);

        // Alumni per batch (last 8)
        $batchRows = Alumni::whereNull('deleted_at')
            ->select('batch', DB::raw('COUNT(*) as cnt'))
            ->groupBy('batch')
            ->orderBy('batch','desc')
            ->limit(8)
            ->get()
            ->reverse()
            ->values();

        $this->chartBatchData = json_encode([
            'labels' => $batchRows->pluck('batch'),
            'data'   => $batchRows->pluck('cnt'),
        ]);

        // Alumni per college (top 8)
        $collegeRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->join('courses as c', 'a.course_code','=','c.code')
            ->whereNotNull('c.college')
            ->select('c.college', DB::raw('COUNT(*) as cnt'))
            ->groupBy('c.college')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        $this->chartCollegeData = json_encode([
            'labels' => $collegeRows->pluck('college'),
            'data'   => $collegeRows->pluck('cnt'),
        ]);

        // Events by status
        $eventRows = AdminEvent::withTrashed()
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $this->chartEventData = json_encode([
            'labels' => ['Approved','Pending','Rejected','Completed','Deleted'],
            'data'   => [
                $eventRows->get('APPROVED')->cnt ?? 0,
                $eventRows->get('PENDING')->cnt ?? 0,
                $eventRows->get('REJECTED')->cnt ?? 0,
                $eventRows->get('COMPLETED')->cnt ?? 0,
                $eventRows->get('ORGANIZER_DELETED')->cnt ?? 0,
            ],
            'colors' => ['#10b981','#f59e0b','#ef4444','#6366f1','#9ca3af'],
        ]);
    }

    private function loadRecent(): void
    {
        $this->recentAlumni = Alumni::orderByDesc('created_at')
            ->limit(5)
            ->get(['id','first_name','last_name','course_code','batch','status','created_at'])
            ->map(fn($a) => [
                'id'          => $a->id,
                'name'        => trim($a->first_name . ' ' . $a->last_name),
                'course_code' => $a->course_code,
                'batch'       => $a->batch,
                'status'      => $a->status,
                'created_at'  => $a->created_at->diffForHumans(),
            ])
            ->toArray();

        $this->recentEvents = AdminEvent::withTrashed()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id','title','status','event_date','created_at'])
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
            ->get(['id','job_title','company_name','status','employment_type','created_at'])
            ->map(fn($j) => [
                'id'              => $j->id,
                'job_title'       => $j->job_title,
                'company_name'    => $j->company_name,
                'status'          => $j->status,
                'employment_type' => $j->employment_type,
                'created_at'      => $j->created_at->diffForHumans(),
            ])
            ->toArray();

        try {
            $this->recentAuditLogs = AuditLog::orderByDesc('created_at')
                ->limit(6)
                ->get(['id','action','module','user_name','user_role','description','severity','created_at'])
                ->map(fn($l) => [
                    'id'          => $l->id,
                    'action'      => $l->action,
                    'module'      => $l->module,
                    'user_name'   => $l->user_name,
                    'user_role'   => $l->user_role,
                    'description' => \Illuminate\Support\Str::limit($l->description, 80),
                    'severity'    => $l->severity,
                    'created_at'  => $l->created_at->diffForHumans(),
                ])
                ->toArray();
        } catch (\Exception) {
            $this->recentAuditLogs = [];
        }
    }

    private function loadCollegeStats(): void
    {
        $rows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->join('courses as c', 'a.course_code','=','c.code')
            ->whereNotNull('c.college')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id','=','et.alumni_id')->whereNull('et.deleted_at');
            })
            ->select(
                'c.college',
                DB::raw('COUNT(a.id) as total'),
                DB::raw("SUM(CASE WHEN et.employment_status IN ('employed','self_employed') THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN a.status = 'VERIFIED' THEN 1 ELSE 0 END) as verified")
            )
            ->groupBy('c.college')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $this->collegeStats = $rows->map(fn($r) => [
            'college'  => $r->college,
            'total'    => $r->total,
            'employed' => $r->employed,
            'verified' => $r->verified,
            'rate'     => $r->total > 0 ? round(($r->employed / $r->total) * 100) : 0,
        ])->toArray();
    }
};
?>

<div class="min-h-screen" style="background:#f3f4f6;">

{{-- ══ CHART DATA STORE (morphed by Livewire) ══ --}}
<div id="__dir_dash_data" style="display:none"
     data-employment="{{ $chartEmploymentData }}"
     data-batch="{{ $chartBatchData }}"
     data-college="{{ $chartCollegeData }}"
     data-event="{{ $chartEventData }}">
</div>

<style>
/* ── Variables ───────────────────────────────────────── */
:root {
    --brand:     #7a3f91;
    --brand-dk:  #5e2f72;
    --brand-lt:  #f5eef9;
    --brand-100: #e9d5f3;
    --brand-200: #d4aaeb;
    --ink:       #1a1523;
    --muted:     #6b7280;
    --border:    #e5e7eb;
    --surface:   #ffffff;
    --bg:        #f3f4f6;
    --emerald:   #10b981;
    --blue:      #3b82f6;
    --amber:     #f59e0b;
    --red:       #ef4444;
    --indigo:    #6366f1;
}

/* ── Stat cards ──────────────────────────────────────── */
.d-stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    transition: transform .18s, box-shadow .18s;
    position: relative;
    overflow: hidden;
}
.d-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(122,63,145,.12);
}
.d-stat::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 18px 18px 0 0;
    background: var(--accent, #7a3f91);
}
.d-stat-icon {
    width: 50px; height: 50px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 1.25rem;
}
.d-stat-num {
    font-size: 2rem;
    font-weight: 900;
    line-height: 1;
    color: var(--ink);
    letter-spacing: -.03em;
}
.d-stat-label {
    font-size: .82rem;
    font-weight: 600;
    color: var(--muted);
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.d-stat-sub {
    font-size: .78rem;
    font-weight: 600;
    color: var(--muted);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.d-stat-sub .up   { color: #059669; }
.d-stat-sub .down { color: #dc2626; }

/* ── Progress bar ────────────────────────────────────── */
.prog-bar  { height: 5px; border-radius: 99px; background: #e9d5f3; overflow: hidden; margin-top: 8px; }
.prog-fill { height: 100%; border-radius: 99px; background: var(--brand); transition: width .8s cubic-bezier(.4,0,.2,1); }

/* ── Section cards ───────────────────────────────────── */
.d-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    overflow: hidden;
}
.d-card-header {
    padding: 14px 20px 12px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.d-card-title {
    font-size: .82rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 8px;
}
.d-card-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--brand);
    flex-shrink: 0;
}

/* ── Table rows ──────────────────────────────────────── */
.d-tbl-row {
    display: flex;
    align-items: center;
    padding: 11px 18px;
    border-bottom: 1px solid #f9fafb;
    transition: background .1s;
    gap: 12px;
}
.d-tbl-row:last-child { border-bottom: none; }
.d-tbl-row:hover { background: var(--brand-lt); }

/* ── Badges ──────────────────────────────────────────── */
.badge-sm {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 999px;
    font-size: .73rem; font-weight: 700;
}

/* ── Audit log ───────────────────────────────────────── */
.audit-row {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 10px 18px;
    border-bottom: 1px solid #f9fafb;
    transition: background .1s;
}
.audit-row:last-child { border-bottom: none; }
.audit-row:hover { background: var(--bg); }
.audit-dot {
    width: 8px; height: 8px; border-radius: 50%;
    flex-shrink: 0; margin-top: 6px;
}

/* ── College bar cards ───────────────────────────────── */
.college-row {
    padding: 12px 18px;
    border-bottom: 1px solid #f9fafb;
    transition: background .1s;
}
.college-row:last-child { border-bottom: none; }
.college-row:hover { background: var(--brand-lt); }

/* ── Scroll ──────────────────────────────────────────── */
.thin-s::-webkit-scrollbar { width: 4px; }
.thin-s::-webkit-scrollbar-track { background: transparent; }
.thin-s::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

/* ── Keyframes ───────────────────────────────────────── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fade-up { animation: fadeUp .35s cubic-bezier(.25,.8,.25,1) both; }
.fade-up-1 { animation-delay: .04s; }
.fade-up-2 { animation-delay: .08s; }
.fade-up-3 { animation-delay: .12s; }
.fade-up-4 { animation-delay: .16s; }
.fade-up-5 { animation-delay: .20s; }
.fade-up-6 { animation-delay: .24s; }
.fade-up-7 { animation-delay: .28s; }
.fade-up-8 { animation-delay: .32s; }

/* ── Greeting banner ─────────────────────────────────── */
.greet-banner {
    background: linear-gradient(135deg, #2b0d3e 0%, #4c1d6e 50%, #7a3f91 100%);
    border-radius: 20px;
    padding: 28px 32px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 6px 24px rgba(43,13,62,.35);
}
.greet-banner::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(255,255,255,.06);
}
.greet-banner::after {
    content: '';
    position: absolute;
    bottom: -40px; right: 120px;
    width: 130px; height: 130px;
    border-radius: 50%;
    background: rgba(255,255,255,.04);
}

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 640px) {
    .d-stat-num { font-size: 1.6rem; }
    .greet-banner { padding: 20px 20px; }
}
</style>

<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-6">

    {{-- ══ GREETING BANNER ══ --}}
    <div class="greet-banner fade-up">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-purple-300 text-sm font-semibold mb-1">{{ $currentDate }}</p>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white leading-tight">
                    {{ $greeting }}, Director {{ auth()->user()->name }} 👋
                </h1>
                <p class="text-purple-200 text-sm mt-1.5">Here's your system overview for today.</p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                @if($pendingEvents > 0)
                <div class="bg-amber-400/20 border border-amber-400/40 rounded-xl px-4 py-3 text-center">
                    <div class="text-2xl font-black text-amber-300">{{ $pendingEvents }}</div>
                    <div class="text-xs font-bold text-amber-200 uppercase tracking-wide mt-0.5">Pending Events</div>
                </div>
                @endif
                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3 text-center">
                    <div class="text-2xl font-black text-white">{{ $totalAlumni }}</div>
                    <div class="text-xs font-bold text-purple-200 uppercase tracking-wide mt-0.5">Total Alumni</div>
                </div>
                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3 text-center hidden sm:block">
                    <div class="text-2xl font-black text-white">{{ number_format($employmentRate, 1) }}%</div>
                    <div class="text-xs font-bold text-purple-200 uppercase tracking-wide mt-0.5">Employment Rate</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ STAT CARDS ROW 1 — Alumni & Coordinators ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

        {{-- Total Alumni --}}
        <div class="d-stat fade-up fade-up-1 lg:col-span-1" style="--accent:#7a3f91;">
            <div class="d-stat-icon" style="background:#ede9fe;">
                <i class="fas fa-users" style="color:#7a3f91;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalAlumni) }}</div>
                <div class="d-stat-label">Total Alumni</div>
                <div class="d-stat-sub">
                    <span class="up"><i class="fas fa-arrow-trend-up text-xs"></i> +{{ $newAlumniThisMonth }} this month</span>
                </div>
            </div>
        </div>

        {{-- Verified --}}
        <div class="d-stat fade-up fade-up-2" style="--accent:#10b981;">
            <div class="d-stat-icon" style="background:#d1fae5;">
                <i class="fas fa-circle-check" style="color:#059669;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalVerified) }}</div>
                <div class="d-stat-label">Verified</div>
                <div class="prog-bar" style="width:100%;">
                    <div class="prog-fill" style="width:{{ $totalAlumni > 0 ? round($totalVerified/$totalAlumni*100) : 0 }}%;background:#10b981;"></div>
                </div>
            </div>
        </div>

        {{-- Pending --}}
        <div class="d-stat fade-up fade-up-3" style="--accent:#f59e0b;">
            <div class="d-stat-icon" style="background:#fef3c7;">
                <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalPending) }}</div>
                <div class="d-stat-label">Pending</div>
                @if($totalPending > 0)
                <div class="d-stat-sub"><span style="color:#d97706;"><i class="fas fa-circle-exclamation text-xs"></i> Needs review</span></div>
                @endif
            </div>
        </div>

        {{-- Coordinators --}}
        <div class="d-stat fade-up fade-up-4" style="--accent:#3b82f6;">
            <div class="d-stat-icon" style="background:#dbeafe;">
                <i class="fas fa-user-tie" style="color:#2563eb;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ $activeCoordinators }}</div>
                <div class="d-stat-label">Active Coords.</div>
                <div class="d-stat-sub" style="color:var(--muted);">{{ $totalCoordinators }} total</div>
            </div>
        </div>

        {{-- Active Jobs --}}
        <div class="d-stat fade-up fade-up-5" style="--accent:#6366f1;">
            <div class="d-stat-icon" style="background:#e0e7ff;">
                <i class="fas fa-briefcase" style="color:#4f46e5;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ $activeJobs }}</div>
                <div class="d-stat-label">Active Jobs</div>
                <div class="d-stat-sub">
                    <span class="up"><i class="fas fa-plus text-xs"></i> {{ $newJobsThisMonth }} this month</span>
                </div>
            </div>
        </div>

        {{-- Approved Events --}}
        <div class="d-stat fade-up fade-up-6" style="--accent:#10b981;">
            <div class="d-stat-icon" style="background:#d1fae5;">
                <i class="fas fa-calendar-check" style="color:#059669;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ $approvedEvents }}</div>
                <div class="d-stat-label">Approved Events</div>
                <div class="d-stat-sub" style="color:var(--muted);">{{ $totalEvents }} total</div>
            </div>
        </div>

    </div>

    {{-- ══ EMPLOYMENT STAT CARDS ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">

        <div class="d-stat fade-up fade-up-1" style="--accent:#10b981;">
            <div class="d-stat-icon" style="background:#d1fae5;">
                <i class="fas fa-briefcase" style="color:#059669;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalEmployed) }}</div>
                <div class="d-stat-label">Employed</div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:{{ $totalAlumni > 0 ? round($totalEmployed/$totalAlumni*100) : 0 }}%;background:#10b981;"></div>
                </div>
                <div class="d-stat-sub" style="color:#059669;">{{ $totalAlumni > 0 ? round($totalEmployed/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        <div class="d-stat fade-up fade-up-2" style="--accent:#f59e0b;">
            <div class="d-stat-icon" style="background:#fef3c7;">
                <i class="fas fa-circle-pause" style="color:#d97706;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalUnemployed) }}</div>
                <div class="d-stat-label">Unemployed</div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:{{ $totalAlumni > 0 ? round($totalUnemployed/$totalAlumni*100) : 0 }}%;background:#f59e0b;"></div>
                </div>
                <div class="d-stat-sub" style="color:#d97706;">{{ $totalAlumni > 0 ? round($totalUnemployed/$totalAlumni*100) : 0 }}% of total</div>
            </div>
        </div>

        <div class="d-stat fade-up fade-up-3" style="--accent:#9ca3af;">
            <div class="d-stat-icon" style="background:#f3f4f6;">
                <i class="fas fa-circle-question" style="color:#9ca3af;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($totalNotFilled) }}</div>
                <div class="d-stat-label">Not Filled</div>
                <div class="d-stat-sub" style="color:var(--muted);">No data submitted</div>
            </div>
        </div>

        <div class="d-stat fade-up fade-up-4" style="--accent:#7a3f91;">
            <div class="d-stat-icon" style="background:#ede9fe;">
                <i class="fas fa-chart-line" style="color:#7a3f91;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="d-stat-num">{{ number_format($employmentRate, 1) }}%</div>
                <div class="d-stat-label">Employment Rate</div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:{{ $employmentRate }}%;"></div>
                </div>
                <div class="d-stat-sub" style="color:#7a3f91;">Overall</div>
            </div>
        </div>
    </div>

    {{-- ══ CHARTS ROW ══ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 fade-up fade-up-2">

        {{-- Employment Donut --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#10b981;"></div>Employment Status</div>
            </div>
            <div style="padding:16px;height:230px;display:flex;align-items:center;justify-content:center;" wire:ignore>
                <canvas id="dChartEmployment"></canvas>
            </div>
        </div>

        {{-- Events Donut --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#f59e0b;"></div>Events Breakdown</div>
            </div>
            <div style="padding:16px;height:230px;display:flex;align-items:center;justify-content:center;" wire:ignore>
                <canvas id="dChartEvent"></canvas>
            </div>
        </div>

        {{-- Alumni per batch --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#3b82f6;"></div>Alumni by Batch</div>
            </div>
            <div style="padding:16px;height:230px;" wire:ignore>
                <canvas id="dChartBatch"></canvas>
            </div>
        </div>

        {{-- Alumni per college --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#7a3f91;"></div>Alumni by College</div>
            </div>
            <div style="padding:16px;height:230px;" wire:ignore>
                <canvas id="dChartCollege"></canvas>
            </div>
        </div>

    </div>

    {{-- ══ COLLEGE PERFORMANCE + RECENT ACTIVITY ══ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 fade-up fade-up-3">

        {{-- College Stats --}}
        <div class="d-card lg:col-span-1">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot"></div>College Performance</div>
                <span class="text-xs font-bold text-gray-400 bg-gray-100 px-2 py-1 rounded-full">Top {{ count($collegeStats) }}</span>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($collegeStats as $cs)
                <div class="college-row">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-sm font-bold text-gray-800 truncate flex-1 pr-2">{{ $cs['college'] }}</span>
                        <span class="text-sm font-black" style="color:{{ $cs['rate'] >= 60 ? '#059669' : ($cs['rate'] >= 30 ? '#d97706' : '#dc2626') }};">{{ $cs['rate'] }}%</span>
                    </div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs text-gray-500">{{ $cs['total'] }} alumni</span>
                        <span class="text-gray-300">·</span>
                        <span class="text-xs text-gray-500">{{ $cs['employed'] }} employed</span>
                        <span class="text-gray-300">·</span>
                        <span class="text-xs text-gray-500">{{ $cs['verified'] }} verified</span>
                    </div>
                    <div class="prog-bar" style="width:100%;">
                        <div class="prog-fill" style="width:{{ $cs['rate'] }}%;background:{{ $cs['rate'] >= 60 ? '#10b981' : ($cs['rate'] >= 30 ? '#f59e0b' : '#ef4444') }};"></div>
                    </div>
                </div>
                @empty
                <div class="py-10 text-center text-sm text-gray-400">No college data available.</div>
                @endforelse
            </div>
        </div>

        {{-- Recent Alumni + Audit --}}
        <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">

            {{-- Recent Alumni --}}
            <div class="d-card flex flex-col" style="max-height:420px;">
                <div class="d-card-header flex-shrink-0">
                    <div class="d-card-title"><div class="d-card-dot" style="background:#3b82f6;"></div>Recent Alumni</div>
                </div>
                <div class="flex-1 overflow-y-auto thin-s">
                    @forelse($recentAlumni as $a)
                    <div class="d-tbl-row">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm font-black shrink-0"
                             style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);">
                            {{ strtoupper(substr($a['name'], 0, 1)) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-bold text-gray-900 truncate">{{ $a['name'] }}</div>
                            <div class="text-xs text-gray-500">{{ $a['course_code'] }} · Batch {{ $a['batch'] }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            @if($a['status'] === 'VERIFIED')
                                <span class="badge-sm bg-emerald-50 text-emerald-700 border border-emerald-200">Verified</span>
                            @elseif($a['status'] === 'PENDING')
                                <span class="badge-sm bg-amber-50 text-amber-700 border border-amber-200">Pending</span>
                            @else
                                <span class="badge-sm bg-gray-100 text-gray-600 border border-gray-200">{{ $a['status'] }}</span>
                            @endif
                            <div class="text-xs text-gray-400 mt-1">{{ $a['created_at'] }}</div>
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center text-sm text-gray-400">No alumni yet.</div>
                    @endforelse
                </div>
            </div>

            {{-- Audit Log --}}
            <div class="d-card flex flex-col" style="max-height:420px;">
                <div class="d-card-header flex-shrink-0">
                    <div class="d-card-title"><div class="d-card-dot" style="background:#ef4444;"></div>Audit Log</div>
                </div>
                <div class="flex-1 overflow-y-auto thin-s">
                    @forelse($recentAuditLogs as $log)
                    @php
                        $dotColor = match($log['severity'] ?? 'info') {
                            'critical' => '#ef4444',
                            'warning'  => '#f59e0b',
                            'info'     => '#3b82f6',
                            default    => '#9ca3af',
                        };
                        $actionColor = match($log['action'] ?? '') {
                            'created'  => '#059669',
                            'updated'  => '#2563eb',
                            'deleted'  => '#dc2626',
                            'verified' => '#7c3aed',
                            'rejected' => '#ef4444',
                            default    => '#6b7280',
                        };
                    @endphp
                    <div class="audit-row">
                        <div class="audit-dot" style="background:{{ $dotColor }};"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                <span class="text-xs font-bold px-1.5 py-0.5 rounded" style="background:{{ $actionColor }}18;color:{{ $actionColor }};">{{ strtoupper($log['action']) }}</span>
                                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ str_replace('_',' ',$log['module']) }}</span>
                            </div>
                            <div class="text-xs text-gray-700 leading-snug">{{ $log['description'] }}</div>
                            <div class="text-xs text-gray-400 mt-1 flex items-center gap-1.5">
                                <i class="fas fa-user text-xs"></i>{{ $log['user_name'] }}
                                <span class="text-gray-300">·</span>{{ $log['created_at'] }}
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center text-sm text-gray-400">No audit logs yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ══ EVENTS + JOBS ══ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 fade-up fade-up-4">

        {{-- Recent Events --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#f59e0b;"></div>Recent Events</div>
                <a href="{{ route('director.event/management') }}"
                   class="text-xs font-bold text-[#7a3f91] hover:text-[#5e2f72] transition flex items-center gap-1">
                    View all <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            @forelse($recentEvents as $ev)
            @php
                $evBadge = match($ev['status']) {
                    'APPROVED'          => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Approved'],
                    'PENDING'           => ['bg-amber-50 text-amber-700 border-amber-200',   'Pending'],
                    'REJECTED'          => ['bg-red-50 text-red-700 border-red-200',          'Rejected'],
                    'COMPLETED'         => ['bg-indigo-50 text-indigo-700 border-indigo-200', 'Completed'],
                    'ORGANIZER_DELETED' => ['bg-gray-100 text-gray-600 border-gray-200',      'Deleted'],
                    default             => ['bg-gray-100 text-gray-600 border-gray-200',       $ev['status']],
                };
            @endphp
            <div class="d-tbl-row">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                     style="background:#fef3c7;">
                    <i class="fas fa-calendar-days" style="color:#d97706;font-size:.9rem;"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-bold text-gray-900 truncate">{{ $ev['title'] }}</div>
                    <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                        <i class="fas fa-calendar text-xs"></i> {{ $ev['event_date'] }}
                        <span class="text-gray-300">·</span>{{ $ev['created_at'] }}
                    </div>
                </div>
                <span class="badge-sm border {{ $evBadge[0] }} shrink-0">{{ $evBadge[1] }}</span>
            </div>
            @empty
            <div class="py-10 text-center text-sm text-gray-400">No events yet.</div>
            @endforelse
        </div>

        {{-- Recent Jobs --}}
        <div class="d-card">
            <div class="d-card-header">
                <div class="d-card-title"><div class="d-card-dot" style="background:#6366f1;"></div>Recent Job Postings</div>
                <a href="{{ route('director.job/management') }}"
                   class="text-xs font-bold text-[#7a3f91] hover:text-[#5e2f72] transition flex items-center gap-1">
                    View all <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            @forelse($recentJobs as $job)
            @php
                $jobBadge = match($job['status']) {
                    'ACTIVE'            => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Active'],
                    'INACTIVE'          => ['bg-amber-50 text-amber-700 border-amber-200',       'Inactive'],
                    'ORGANIZER_DELETED' => ['bg-red-50 text-red-700 border-red-200',             'Deleted'],
                    default             => ['bg-gray-100 text-gray-600 border-gray-200',          $job['status']],
                };
            @endphp
            <div class="d-tbl-row">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                     style="background:#e0e7ff;">
                    <i class="fas fa-briefcase" style="color:#4f46e5;font-size:.9rem;"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-bold text-gray-900 truncate">{{ $job['job_title'] }}</div>
                    <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                        <i class="fas fa-building text-xs"></i>{{ $job['company_name'] }}
                        <span class="text-gray-300">·</span>{{ $job['employment_type'] }}
                        <span class="text-gray-300">·</span>{{ $job['created_at'] }}
                    </div>
                </div>
                <span class="badge-sm border {{ $jobBadge[0] }} shrink-0">{{ $jobBadge[1] }}</span>
            </div>
            @empty
            <div class="py-10 text-center text-sm text-gray-400">No job postings yet.</div>
            @endforelse
        </div>

    </div>

</div>

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
                employment: JSON.parse(el.getAttribute('data-employment') || 'null'),
                batch:      JSON.parse(el.getAttribute('data-batch')      || 'null'),
                college:    JSON.parse(el.getAttribute('data-college')    || 'null'),
                event:      JSON.parse(el.getAttribute('data-event')      || 'null'),
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
                datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 7 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11, weight: '700' }, color: '#374151', padding: 10, usePointStyle: true, pointStyleWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                                var pct = total ? Math.round(ctx.parsed/total*100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function barBatch(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('dChartBatch');
        if (!canvas) return;
        kill('dChartBatch');
        reg['dChartBatch'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{ label: 'Alumni', data: data.data, backgroundColor: '#7a3f91cc', borderColor: '#7a3f91', borderWidth: 1, borderRadius: 5 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11, weight: '700' }, color: '#6b7280' } },
                    y: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true }
                }
            }
        });
    }

    function barCollege(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('dChartCollege');
        if (!canvas) return;
        kill('dChartCollege');
        reg['dChartCollege'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{ label: 'Alumni', data: data.data, backgroundColor: '#3b82f6cc', borderColor: '#3b82f6', borderWidth: 1, borderRadius: 5 }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 11, weight: '700' }, color: '#374151' } }
                }
            }
        });
    }

    function initAll() {
        var d = readData();
        if (!d) return;
        donut('dChartEmployment', d.employment);
        donut('dChartEvent',      d.event);
        barBatch(d.batch);
        barCollege(d.college);
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

</div>