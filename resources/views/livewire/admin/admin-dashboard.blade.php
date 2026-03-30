<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Organizer;
use App\Models\Course;
use App\Models\JobPosting;
use App\Models\AdminEvent;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

new class extends Component {

    public string $activeTab     = 'overview';
    public string $recentPeriod  = '30'; // days

    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }

    // ── Core stats ─────────────────────────────────────────────────────────
    #[Computed]
    public function stats(): array
    {
        return Cache::remember('admin_dashboard_stats', 60, function () {
            $totalAlumni        = Alumni::count();
            $verifiedAlumni     = Alumni::where('status', 'VERIFIED')->count();
            $pendingAlumni      = Alumni::where('status', 'PENDING')->count();
            $totalOrganizers    = Organizer::withoutTrashed()->count();
            $activeOrganizers   = Organizer::withoutTrashed()->where('status', 'ACTIVE')->count();
            $totalCourses       = Course::count();
            $totalColleges      = Course::whereNotNull('college')->where('college','!=','')->distinct('college')->count('college');

            $activeJobs         = class_exists(\App\Models\JobPosting::class)
                ? \App\Models\JobPosting::where('status','ACTIVE')->count() : 0;
            $pendingEvents      = class_exists(\App\Models\AdminEvent::class)
                ? \App\Models\AdminEvent::where('status','PENDING')->count() : 0;

            // Growth: alumni added this month vs last month
            $thisMonth = Alumni::whereMonth('created_at', now()->month)
                               ->whereYear('created_at', now()->year)->count();
            $lastMonth = Alumni::whereMonth('created_at', now()->subMonth()->month)
                               ->whereYear('created_at', now()->subMonth()->year)->count();
            $growth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0);

            return compact(
                'totalAlumni','verifiedAlumni','pendingAlumni',
                'totalOrganizers','activeOrganizers',
                'totalCourses','totalColleges',
                'activeJobs','pendingEvents',
                'thisMonth','lastMonth','growth'
            );
        });
    }

    // ── Recent alumni (last 10) ─────────────────────────────────────────────
    #[Computed]
    public function recentAlumni()
    {
        return Alumni::orderByDesc('created_at')
            ->limit(10)
            ->get(['id','first_name','last_name','middle_initial','suffix',
                   'email','student_id','course_code','batch','status',
                   'profile_photo','created_at']);
    }

    // ── Alumni by batch (chart data) ──────────────────────────────────────
    #[Computed]
    public function batchData(): array
    {
        return Cache::remember('dashboard_batch_data', 120, function () {
            return Alumni::selectRaw('batch, COUNT(*) as total')
                ->groupBy('batch')
                ->orderBy('batch')
                ->limit(10)
                ->pluck('total','batch')
                ->toArray();
        });
    }

    // ── Alumni by course (top 6) ──────────────────────────────────────────
    #[Computed]
    public function courseData(): array
    {
        return Cache::remember('dashboard_course_data', 120, function () {
            return Alumni::selectRaw('course_code, COUNT(*) as total')
                ->whereNotNull('course_code')
                ->groupBy('course_code')
                ->orderByDesc('total')
                ->limit(6)
                ->pluck('total','course_code')
                ->toArray();
        });
    }

    // ── Monthly registrations (last 6 months) ─────────────────────────────
    #[Computed]
    public function monthlyData(): array
    {
        return Cache::remember('dashboard_monthly_data', 120, function () {
            $result = [];
            for ($i = 5; $i >= 0; $i--) {
                $date   = now()->subMonths($i);
                $count  = Alumni::whereYear('created_at', $date->year)
                                ->whereMonth('created_at', $date->month)
                                ->count();
                $result[$date->format('M Y')] = $count;
            }
            return $result;
        });
    }

    // ── Status breakdown ──────────────────────────────────────────────────
    #[Computed]
    public function statusBreakdown(): array
    {
        return Cache::remember('dashboard_status_breakdown', 120, function () {
            return Alumni::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total','status')
                ->toArray();
        });
    }

    // ── College breakdown ─────────────────────────────────────────────────
    #[Computed]
    public function collegeBreakdown(): array
    {
        return Cache::remember('dashboard_college_breakdown', 120, function () {
            return Alumni::selectRaw('course_code, COUNT(*) as total')
                ->whereNotNull('course_code')
                ->groupBy('course_code')
                ->orderByDesc('total')
                ->limit(8)
                ->pluck('total','course_code')
                ->toArray();
        });
    }

    // ── Pending organizer requests ────────────────────────────────────────
    #[Computed]
    public function inactiveOrganizers()
    {
        return Organizer::withoutTrashed()
            ->where('status','INACTIVE')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id','first_name','last_name','middle_initial','suffix',
                   'email','department','profile_photo','created_at']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return \Illuminate\Support\Facades\Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }

    public function switchTab(string $tab): void { $this->activeTab = $tab; }

    public function refreshStats(): void
    {
        Cache::forget('admin_dashboard_stats');
        Cache::forget('dashboard_batch_data');
        Cache::forget('dashboard_course_data');
        Cache::forget('dashboard_monthly_data');
        Cache::forget('dashboard_status_breakdown');
        Cache::forget('dashboard_college_breakdown');
        $this->dispatch('flash-message', type: 'success', message: 'Dashboard refreshed!');
    }
};
?>

<div class="min-h-screen" style="background:#f0f0f5;">

{{-- ══════════════════════════════════════════════
     CSS VARIABLES & GLOBAL STYLES
══════════════════════════════════════════════ --}}
<style>
:root {
    --brand:      #7a3f91;
    --brand-d:    #5e2f72;
    --brand-l:    #9b5bb0;
    --brand-50:   #f5eef9;
    --brand-100:  #e9d5f3;
    --brand-200:  #d4aaeb;
    --deep:       #2b0d3e;
    --surface:    #ffffff;
    --surface-2:  #f8f7fc;
    --border:     #ede9f4;
    --text-1:     #111827;
    --text-2:     #4b5563;
    --text-3:     #9ca3af;

    --shadow-sm:  0 1px 3px rgba(0,0,0,.07);
    --shadow-md:  0 4px 16px rgba(0,0,0,.08);
    --shadow-lg:  0 8px 32px rgba(0,0,0,.12);
    --shadow-brand: 0 4px 20px rgba(122,63,145,.30);
}

/* ── Keyframes ─────────────────────────────────── */
@keyframes fadeUp {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0);    }
}
@keyframes countUp {
    from { opacity:0; transform:scale(.85); }
    to   { opacity:1; transform:scale(1);   }
}
@keyframes pulse-ring {
    0%,100% { box-shadow: 0 0 0 0 rgba(122,63,145,.4); }
    50%      { box-shadow: 0 0 0 8px rgba(122,63,145,0); }
}
@keyframes bar-in {
    from { transform: scaleY(0); transform-origin: bottom; }
    to   { transform: scaleY(1); transform-origin: bottom; }
}
@keyframes shimmer {
    0%   { background-position: -400px 0; }
    100% { background-position: 400px 0;  }
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Animation utilities ───────────────────────── */
.fade-up { animation: fadeUp .45s cubic-bezier(.25,.8,.25,1) both; }
.fade-up-1 { animation-delay:.05s; }
.fade-up-2 { animation-delay:.10s; }
.fade-up-3 { animation-delay:.15s; }
.fade-up-4 { animation-delay:.20s; }
.fade-up-5 { animation-delay:.25s; }
.fade-up-6 { animation-delay:.30s; }
.fade-up-7 { animation-delay:.35s; }
.fade-up-8 { animation-delay:.40s; }
.count-in  { animation: countUp .5s cubic-bezier(.34,1.56,.64,1) both; }

/* ── Base card ─────────────────────────────────── */
.card {
    background: var(--surface);
    border-radius: 16px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: box-shadow .2s, transform .2s;
}
.card:hover { box-shadow: var(--shadow-md); }
.card-flat  { background:var(--surface); border-radius:16px; border:1px solid var(--border); }

/* ── Stat card ─────────────────────────────────── */
.stat-card {
    background: var(--surface);
    border-radius: 18px;
    padding: 22px 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: all .2s ease;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 18px 18px 0 0;
}
.stat-card.purple::before  { background: linear-gradient(90deg,#7a3f91,#9b5bb0); }
.stat-card.emerald::before { background: linear-gradient(90deg,#059669,#10b981); }
.stat-card.amber::before   { background: linear-gradient(90deg,#d97706,#f59e0b); }
.stat-card.blue::before    { background: linear-gradient(90deg,#2563eb,#60a5fa); }
.stat-card.rose::before    { background: linear-gradient(90deg,#e11d48,#fb7185); }
.stat-card.teal::before    { background: linear-gradient(90deg,#0d9488,#2dd4bf); }

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,.12);
}
.stat-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.stat-value {
    font-size: 2.1rem; font-weight: 900; line-height: 1;
    color: var(--text-1); letter-spacing: -.02em;
}
.stat-label {
    font-size: .73rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-3);
}
.stat-sub {
    font-size: .75rem; font-weight: 600; color: var(--text-2); margin-top: 4px;
}

/* ── Section header ────────────────────────────── */
.section-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
}
.section-title {
    font-size: .8rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .08em; color: var(--text-2);
    display: flex; align-items: center; gap: 8px;
}
.section-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--brand);
}

/* ── Badge ──────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
.badge-verified  { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.badge-pending   { background:#fffbeb; color:#b45309; border:1px solid #fcd34d; }
.badge-rejected  { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; }
.badge-active    { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.badge-inactive  { background:#fffbeb; color:#b45309; border:1px solid #fcd34d; }
.badge-brand     { background:var(--brand-50); color:var(--brand); border:1px solid var(--brand-200); }
.badge-default   { background:#f9fafb; color:#374151; border:1px solid #e5e7eb; }

/* ── Pill tabs ──────────────────────────────────── */
.pill-nav { display:flex; gap:6px; background:#ede9f4; padding:5px; border-radius:12px; }
.pill-tab {
    padding:8px 18px; border-radius:9px; font-size:.8rem; font-weight:700;
    cursor:pointer; transition:all .18s; border:none; background:transparent;
    color:#6b7280;
}
.pill-tab.active {
    background:#fff; color:var(--brand);
    box-shadow: 0 2px 8px rgba(122,63,145,.15);
}
.pill-tab:hover:not(.active) { background:rgba(255,255,255,.5); color:var(--brand); }

/* ── Mini bar chart ─────────────────────────────── */
.mini-bar-wrap { display:flex; align-items:flex-end; gap:5px; height:60px; }
.mini-bar {
    flex:1; border-radius:4px 4px 0 0; min-width:8px;
    background:var(--brand-100);
    transition:background .2s;
    animation: bar-in .6s cubic-bezier(.34,1.56,.64,1) both;
    cursor:pointer;
}
.mini-bar:hover { background:var(--brand); }

/* ── Progress ring ──────────────────────────────── */
.ring-wrap { position:relative; display:inline-flex; align-items:center; justify-content:center; }
.ring-label {
    position:absolute; text-align:center;
    font-size:1.1rem; font-weight:900; color:var(--text-1);
}
.ring-sub {
    font-size:.55rem; font-weight:700; color:var(--text-3);
    text-transform:uppercase; letter-spacing:.05em; display:block;
}

/* ── Timeline ───────────────────────────────────── */
.timeline-item { display:flex; gap:14px; position:relative; }
.timeline-line {
    position:absolute; left:17px; top:34px; bottom:-8px;
    width:2px; background:var(--border); border-radius:2px;
}
.timeline-dot {
    width:34px; height:34px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:13px;
}

/* ── Quick action cards ─────────────────────────── */
.action-card {
    border-radius:14px; padding:18px 16px;
    border:1.5px solid var(--border); background:var(--surface);
    cursor:pointer; transition:all .18s; text-align:center;
    text-decoration:none; display:flex; flex-direction:column;
    align-items:center; gap:10px;
}
.action-card:hover {
    border-color:var(--brand); background:var(--brand-50);
    transform:translateY(-2px); box-shadow:var(--shadow-brand);
}
.action-icon {
    width:48px; height:48px; border-radius:13px;
    display:flex; align-items:center; justify-content:center; font-size:20px;
}

/* ── Shimmer skeleton ───────────────────────────── */
.skeleton {
    background:linear-gradient(90deg,#f0f0f0 25%,#e8e8e8 50%,#f0f0f0 75%);
    background-size:400px 100%;
    animation:shimmer 1.4s infinite linear;
    border-radius:6px;
}

/* ── Table rows ─────────────────────────────────── */
.dash-row { transition:background .1s; }
.dash-row:hover { background:#faf5fd; }

/* ── Scrollbar ──────────────────────────────────── */
.scroll-sm::-webkit-scrollbar { width:4px; height:4px; }
.scroll-sm::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px; }
.scroll-sm::-webkit-scrollbar-thumb { background:#ddd4f0; border-radius:99px; }
.scroll-sm::-webkit-scrollbar-thumb:hover { background:var(--brand-l); }

/* ── Gradient header ────────────────────────────── */
.dash-header {
    background: linear-gradient(135deg, #2b0d3e 0%, #4a1a6b 50%, #7a3f91 100%);
    border-radius: 20px;
    padding: 28px 32px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.dash-header::before {
    content:'';
    position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.dash-header::after {
    content:'';
    position:absolute;
    right:-60px; top:-60px;
    width:280px; height:280px;
    border-radius:50%;
    background: radial-gradient(circle,rgba(255,255,255,.07) 0%,transparent 70%);
}
.header-orb {
    position:absolute; bottom:-30px; left:30%;
    width:160px; height:160px; border-radius:50%;
    background:radial-gradient(circle,rgba(255,255,255,.05),transparent 70%);
}

/* ── Btn ─────────────────────────────────────────── */
.btn-brand-sm {
    background:var(--brand); color:#fff; border:none;
    padding:7px 14px; border-radius:8px; font-size:.75rem; font-weight:700;
    cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:5px;
}
.btn-brand-sm:hover { background:var(--brand-d); transform:translateY(-1px); box-shadow:var(--shadow-brand); }
.btn-ghost-sm {
    background:#fff; color:var(--text-2); border:1.5px solid var(--border);
    padding:7px 14px; border-radius:8px; font-size:.75rem; font-weight:700;
    cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:5px;
}
.btn-ghost-sm:hover { background:#f9f5ff; border-color:var(--brand-200); color:var(--brand); }

/* ── Notification dot ───────────────────────────── */
.notif-dot {
    width:8px; height:8px; border-radius:50%; background:#ef4444;
    position:absolute; top:2px; right:2px;
    animation:pulse-ring 2s infinite;
}

/* ── Responsive ─────────────────────────────────── */
@media(max-width:640px) {
    .stat-value { font-size:1.6rem; }
    .dash-header { padding:20px 18px; }
    .pill-tab { padding:7px 12px; font-size:.72rem; }
}
</style>

{{-- ══════════════════════════════════════════════
     FLASH TOAST
══════════════════════════════════════════════ --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,
             display(t,m){this.type=t;this.msg=m;this.show=true;
             clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-5 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-2xl shadow-2xl
           max-w-xs w-full bg-white border"
    :class="{'border-emerald-300':type==='success','border-red-300':type==='error','border-blue-300':type==='info'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 text-gray-500 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-700 transition flex-shrink-0">
        <i class="fas fa-xmark text-xs"></i>
    </button>
</div>

{{-- ══════════════════════════════════════════════
     PAGE WRAPPER
══════════════════════════════════════════════ --}}
<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-5">

    {{-- ── GRADIENT HERO HEADER ──────────────────── --}}
    <div class="dash-header fade-up">
        <div class="header-orb"></div>
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center">
                        <i class="fas fa-gauge-high text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Admin Panel</p>
                        <h1 class="text-xl sm:text-2xl font-black text-white leading-tight">
                            Dashboard Overview
                        </h1>
                    </div>
                </div>
                <p class="text-white/60 text-sm mt-1">
                    Welcome back, <strong class="text-white">{{ auth()->user()->name }}</strong> ·
                    {{ now()->setTimezone('Asia/Manila')->format('l, F j, Y') }}
                </p>
            </div>

            <div class="flex items-center gap-2.5 flex-wrap">
                {{-- Pending events alert --}}
                @if($this->stats['pendingEvents'] > 0)
                <div class="relative">
                    <a href="{{ route('admin.events') }}"
                       class="flex items-center gap-2 bg-amber-400/20 border border-amber-400/40
                              text-amber-300 rounded-xl px-3.5 py-2 text-xs font-bold
                              hover:bg-amber-400/30 transition">
                        <i class="fas fa-calendar-exclamation text-amber-400"></i>
                        {{ $this->stats['pendingEvents'] }} Pending Event{{ $this->stats['pendingEvents']>1?'s':'' }}
                    </a>
                </div>
                @endif

                {{-- Pending alumni --}}
                @if($this->stats['pendingAlumni'] > 0)
                <div class="relative">
                    <div class="flex items-center gap-2 bg-rose-400/20 border border-rose-400/40
                                text-rose-300 rounded-xl px-3.5 py-2 text-xs font-bold">
                        <i class="fas fa-user-clock text-rose-400"></i>
                        {{ $this->stats['pendingAlumni'] }} Pending Alumni
                    </div>
                </div>
                @endif

                <button wire:click="refreshStats"
                        wire:loading.attr="disabled"
                        class="flex items-center gap-1.5 bg-white/15 border border-white/30
                               text-white rounded-xl px-3.5 py-2 text-xs font-bold
                               hover:bg-white/25 transition">
                    <i class="fas fa-rotate-right text-xs" wire:loading.class="spin" wire:target="refreshStats"></i>
                    <span wire:loading.remove wire:target="refreshStats">Refresh</span>
                    <span wire:loading wire:target="refreshStats">Refreshing…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── STAT CARDS ROW 1 ──────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">

        {{-- Total Alumni --}}
        <div class="stat-card purple fade-up fade-up-1 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#f5eef9;">
                    <i class="fas fa-graduation-cap" style="color:var(--brand);"></i>
                </div>
                @php $g = $this->stats['growth']; @endphp
                <span class="text-xs font-bold px-2 py-0.5 rounded-full
                      {{ $g >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                    {{ $g >= 0 ? '+' : '' }}{{ $g }}%
                </span>
            </div>
            <div class="stat-value count-in">{{ number_format($this->stats['totalAlumni']) }}</div>
            <div class="stat-label mt-2">Total Alumni</div>
            <div class="stat-sub">{{ $this->stats['thisMonth'] }} this month</div>
        </div>

        {{-- Verified --}}
        <div class="stat-card emerald fade-up fade-up-2 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#f0fdf4;">
                    <i class="fas fa-circle-check" style="color:#16a34a;"></i>
                </div>
                @php $vp = $this->stats['totalAlumni'] > 0 ? round(($this->stats['verifiedAlumni'] / $this->stats['totalAlumni']) * 100) : 0; @endphp
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">
                    {{ $vp }}%
                </span>
            </div>
            <div class="stat-value count-in">{{ number_format($this->stats['verifiedAlumni']) }}</div>
            <div class="stat-label mt-2">Verified</div>
            <div class="stat-sub">of total alumni</div>
        </div>

        {{-- Pending --}}
        <div class="stat-card amber fade-up fade-up-3 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#fffbeb;">
                    <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
                </div>
                @if($this->stats['pendingAlumni'] > 0)
                <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">
                    <i class="fas fa-circle text-[6px]"></i> New
                </span>
                @endif
            </div>
            <div class="stat-value count-in">{{ number_format($this->stats['pendingAlumni']) }}</div>
            <div class="stat-label mt-2">Pending</div>
            <div class="stat-sub">awaiting review</div>
        </div>

        {{-- Organizers --}}
        <div class="stat-card blue fade-up fade-up-4 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#eff6ff;">
                    <i class="fas fa-users-gear" style="color:#2563eb;"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">
                    {{ $this->stats['activeOrganizers'] }} active
                </span>
            </div>
            <div class="stat-value count-in">{{ $this->stats['totalOrganizers'] }}</div>
            <div class="stat-label mt-2">Organizers</div>
            <div class="stat-sub">{{ $this->stats['totalColleges'] }} college{{ $this->stats['totalColleges']!==1?'s':'' }}</div>
        </div>

        {{-- Courses --}}
        <div class="stat-card teal fade-up fade-up-5 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#f0fdfa;">
                    <i class="fas fa-book-open" style="color:#0d9488;"></i>
                </div>
            </div>
            <div class="stat-value count-in">{{ $this->stats['totalCourses'] }}</div>
            <div class="stat-label mt-2">Courses</div>
            <div class="stat-sub">{{ $this->stats['totalColleges'] }} colleges</div>
        </div>

        {{-- Active Jobs --}}
        <div class="stat-card rose fade-up fade-up-6 col-span-1">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#fff1f2;">
                    <i class="fas fa-briefcase" style="color:#e11d48;"></i>
                </div>
            </div>
            <div class="stat-value count-in">{{ $this->stats['activeJobs'] }}</div>
            <div class="stat-label mt-2">Active Jobs</div>
            <div class="stat-sub">job postings</div>
        </div>

    </div>

    {{-- ── TAB NAV ────────────────────────────────── --}}
    <div class="fade-up fade-up-3">
        <div class="pill-nav w-fit">
            <button wire:click="switchTab('overview')"
                    class="pill-tab {{ $activeTab==='overview' ? 'active' : '' }}">
                <i class="fas fa-chart-pie text-xs mr-1.5"></i>Overview
            </button>
            <button wire:click="switchTab('alumni')"
                    class="pill-tab {{ $activeTab==='alumni' ? 'active' : '' }}">
                <i class="fas fa-users text-xs mr-1.5"></i>Recent Alumni
            </button>
            <button wire:click="switchTab('quick')"
                    class="pill-tab {{ $activeTab==='quick' ? 'active' : '' }}">
                <i class="fas fa-bolt text-xs mr-1.5"></i>Quick Actions
            </button>
        </div>
    </div>

    {{-- ══════════════════════════════════════════
         TAB: OVERVIEW
    ══════════════════════════════════════════ --}}
    @if($activeTab === 'overview')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 fade-up fade-up-4">

        {{-- ── LEFT COL (2/3) ──────────────────── --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Monthly Registrations Chart ──── --}}
            <div class="card p-5 sm:p-6">
                <div class="section-head">
                    <div class="section-title">
                        <span class="section-dot"></span>
                        Monthly Registrations
                    </div>
                    <span class="text-xs text-gray-400 font-semibold">Last 6 months</span>
                </div>

                @php
                    $monthly = $this->monthlyData;
                    $maxM    = max(array_values($monthly) ?: [1]);
                    $months  = array_keys($monthly);
                    $vals    = array_values($monthly);
                @endphp

                {{-- Bar chart ─────────────────── --}}
                <div class="flex items-end gap-2 sm:gap-3 h-40 px-1">
                    @foreach($monthly as $label => $count)
                    @php
                        $pct  = $maxM > 0 ? max(4, round(($count / $maxM) * 100)) : 4;
                        $isLast = $loop->last;
                    @endphp
                    <div class="flex-1 flex flex-col items-center gap-1.5">
                        <span class="text-xs font-bold {{ $isLast ? 'text-purple-600' : 'text-gray-400' }}">
                            {{ $count }}
                        </span>
                        <div class="w-full relative" style="height:90px;">
                            <div class="absolute bottom-0 w-full rounded-t-lg transition-all duration-700"
                                 style="height:{{ $pct }}%;
                                        background:{{ $isLast
                                            ? 'linear-gradient(180deg,#9b5bb0,#7a3f91)'
                                            : 'linear-gradient(180deg,#e9d5f3,#d4aaeb)' }};
                                        animation:bar-in .6s cubic-bezier(.34,1.56,.64,1) {{ $loop->index * 80 }}ms both;">
                            </div>
                        </div>
                        <span class="text-[10px] font-semibold text-gray-400 leading-tight text-center whitespace-nowrap">
                            {{ explode(' ', $label)[0] }}
                        </span>
                    </div>
                    @endforeach
                </div>

                {{-- Summary row ──────────────── --}}
                <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-3 gap-3">
                    @php $total6 = array_sum($vals); @endphp
                    <div class="text-center">
                        <p class="text-xs text-gray-400 font-semibold">6-Month Total</p>
                        <p class="text-lg font-black text-gray-900">{{ number_format($total6) }}</p>
                    </div>
                    <div class="text-center border-x border-gray-100">
                        <p class="text-xs text-gray-400 font-semibold">Monthly Avg</p>
                        <p class="text-lg font-black text-gray-900">
                            {{ count($vals) > 0 ? number_format(round($total6 / count($vals))) : 0 }}
                        </p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-gray-400 font-semibold">This Month</p>
                        <p class="text-lg font-black" style="color:var(--brand);">{{ $this->stats['thisMonth'] }}</p>
                    </div>
                </div>
            </div>

            {{-- Course Distribution ──────────── --}}
            <div class="card p-5 sm:p-6">
                <div class="section-head">
                    <div class="section-title">
                        <span class="section-dot"></span>
                        Alumni by Course
                    </div>
                </div>
                @php
                    $courseData = $this->courseData;
                    $maxC = max(array_values($courseData) ?: [1]);
                    $colors = ['#7a3f91','#9b5bb0','#b57cc8','#c99dd8','#d4b0e0','#e2cef0'];
                @endphp
                @if(count($courseData))
                <div class="space-y-3">
                    @foreach($courseData as $code => $cnt)
                    @php
                        $pct   = $maxC > 0 ? round(($cnt / $this->stats['totalAlumni']) * 100, 1) : 0;
                        $barW  = $maxC > 0 ? round(($cnt / $maxC) * 100) : 0;
                        $color = $colors[$loop->index % count($colors)];
                    @endphp
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $color }};"></span>
                                <span class="text-sm font-bold text-gray-800 font-mono">{{ $code }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-gray-500">{{ number_format($cnt) }}</span>
                                <span class="text-xs font-bold" style="color:{{ $color }};">{{ $pct }}%</span>
                            </div>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700"
                                 style="width:{{ $barW }}%;background:{{ $color }};
                                        animation:bar-in .6s {{ $loop->index * 100 }}ms both;">
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-8 text-gray-400 text-sm">No course data available.</div>
                @endif
            </div>
        </div>

        {{-- ── RIGHT COL (1/3) ─────────────────── --}}
        <div class="space-y-4">

            {{-- Verification Donut ──────────── --}}
            <div class="card p-5">
                <div class="section-head mb-4">
                    <div class="section-title">
                        <span class="section-dot"></span>
                        Alumni Status
                    </div>
                </div>

                @php
                    $statusData = $this->statusBreakdown;
                    $total   = array_sum($statusData);
                    $verified = $statusData['VERIFIED'] ?? 0;
                    $pending  = $statusData['PENDING']  ?? 0;
                    $rejected = $statusData['REJECTED'] ?? 0;
                    $verPct   = $total > 0 ? round(($verified / $total) * 100) : 0;
                    $pendPct  = $total > 0 ? round(($pending  / $total) * 100) : 0;
                    $rejPct   = $total > 0 ? round(($rejected / $total) * 100) : 0;

                    $circ     = 2 * pi() * 36; // circumference for r=36
                    $verDash  = ($verPct  / 100) * $circ;
                    $pendDash = ($pendPct / 100) * $circ;
                    $rejDash  = ($rejPct  / 100) * $circ;
                @endphp

                {{-- SVG Donut ────────────────── --}}
                <div class="flex items-center justify-center mb-4">
                    <div class="ring-wrap" style="width:120px;height:120px;">
                        <svg width="120" height="120" viewBox="0 0 80 80">
                            <circle cx="40" cy="40" r="36" fill="none" stroke="#f3f4f6" stroke-width="8"/>
                            {{-- Verified (green) --}}
                            <circle cx="40" cy="40" r="36" fill="none" stroke="#16a34a" stroke-width="8"
                                    stroke-dasharray="{{ $verDash }} {{ $circ - $verDash }}"
                                    stroke-dashoffset="{{ $circ * 0.25 }}"
                                    stroke-linecap="round"
                                    style="transition:stroke-dasharray .8s ease;"/>
                            {{-- Pending (amber) --}}
                            @if($pendPct > 0)
                            <circle cx="40" cy="40" r="36" fill="none" stroke="#f59e0b" stroke-width="8"
                                    stroke-dasharray="{{ $pendDash }} {{ $circ - $pendDash }}"
                                    stroke-dashoffset="{{ $circ * 0.25 - $verDash }}"
                                    stroke-linecap="round"/>
                            @endif
                            {{-- Rejected (red) --}}
                            @if($rejPct > 0)
                            <circle cx="40" cy="40" r="36" fill="none" stroke="#ef4444" stroke-width="8"
                                    stroke-dasharray="{{ $rejDash }} {{ $circ - $rejDash }}"
                                    stroke-dashoffset="{{ $circ * 0.25 - $verDash - $pendDash }}"
                                    stroke-linecap="round"/>
                            @endif
                        </svg>
                        <div class="ring-label">
                            <span class="text-lg font-black text-gray-900">{{ $verPct }}%</span>
                            <span class="ring-sub">verified</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2.5">
                    @foreach([
                        ['VERIFIED', $verified, '#16a34a', 'Verified'],
                        ['PENDING',  $pending,  '#f59e0b', 'Pending'],
                        ['REJECTED', $rejected, '#ef4444', 'Rejected'],
                    ] as [$key,$cnt,$color,$lbl])
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-sm" style="background:{{ $color }};"></span>
                            <span class="text-sm font-semibold text-gray-700">{{ $lbl }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-gray-900">{{ number_format($cnt) }}</span>
                            <span class="text-xs text-gray-400">
                                {{ $total > 0 ? round(($cnt / $total)*100) : 0 }}%
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Batch Distribution ──────────── --}}
            <div class="card p-5">
                <div class="section-head">
                    <div class="section-title">
                        <span class="section-dot"></span>
                        Top Batches
                    </div>
                </div>

                @php
                    $batchData = $this->batchData;
                    $maxB = max(array_values($batchData) ?: [1]);
                    arsort($batchData);
                    $topBatches = array_slice($batchData, 0, 6, true);
                @endphp

                <div class="space-y-2.5">
                    @forelse($topBatches as $yr => $cnt)
                    @php $bpct = $maxB > 0 ? round(($cnt / $maxB) * 100) : 0; @endphp
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-xs font-bold text-gray-700 font-mono">{{ $yr }}</span>
                            <span class="text-xs font-semibold text-gray-500">{{ number_format($cnt) }}</span>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full" style="width:{{ $bpct }}%;background:linear-gradient(90deg,#7a3f91,#9b5bb0);"></div>
                        </div>
                    </div>
                    @empty
                    <p class="text-xs text-gray-400 text-center py-4">No batch data yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Organizer Health ─────────────── --}}
            <div class="card p-5">
                <div class="section-head">
                    <div class="section-title">
                        <span class="section-dot"></span>
                        Organizer Health
                    </div>
                </div>
                @php
                    $activeOrg   = $this->stats['activeOrganizers'];
                    $totalOrg    = $this->stats['totalOrganizers'];
                    $inactiveOrg = $totalOrg - $activeOrg;
                    $orgPct      = $totalOrg > 0 ? round(($activeOrg/$totalOrg)*100) : 0;
                @endphp
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex-1">
                        <div class="flex justify-between text-xs mb-1.5">
                            <span class="font-semibold text-gray-600">Active Organizers</span>
                            <span class="font-bold" style="color:var(--brand);">{{ $orgPct }}%</span>
                        </div>
                        <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700"
                                 style="width:{{ $orgPct }}%;background:linear-gradient(90deg,var(--brand),var(--brand-l));">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2.5">
                    <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-center">
                        <p class="text-xl font-black text-emerald-700">{{ $activeOrg }}</p>
                        <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wide mt-0.5">Active</p>
                    </div>
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center">
                        <p class="text-xl font-black text-amber-700">{{ $inactiveOrg }}</p>
                        <p class="text-[10px] font-bold text-amber-600 uppercase tracking-wide mt-0.5">Inactive</p>
                    </div>
                </div>

                @if(count($this->inactiveOrganizers) > 0)
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2.5">Inactive Organizers</p>
                    <div class="space-y-2">
                        @foreach($this->inactiveOrganizers as $org)
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-black text-white"
                                 style="background:var(--brand);">
                                {{ strtoupper(substr($org->first_name,0,1)) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-800 truncate">{{ $org->name }}</p>
                                <p class="text-[10px] text-gray-400 truncate">{{ $org->department }}</p>
                            </div>
                            <span class="badge badge-inactive flex-shrink-0">Inactive</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

        </div>
    </div>
    @endif

    {{-- ══════════════════════════════════════════
         TAB: RECENT ALUMNI
    ══════════════════════════════════════════ --}}
    @if($activeTab === 'alumni')
    <div class="card overflow-hidden fade-up fade-up-4">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
            <div class="section-title">
                <span class="section-dot"></span>
                Recently Registered Alumni
                <span class="text-xs font-normal text-gray-400 ml-1 normal-case">(Latest 10)</span>
            </div>
            <a href="{{ route('admin.alumni') }}"
               class="btn-brand-sm">
                <i class="fas fa-arrow-right text-xs"></i> View All
            </a>
        </div>

        <div class="overflow-x-auto scroll-sm">
            <table class="w-full border-collapse" style="min-width:640px;">
                <thead>
                    <tr class="border-b border-gray-100" style="background:#fafafa;">
                        <th class="px-5 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Alumni</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Student ID</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Course</th>
                        <th class="px-5 py-3 text-center text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Batch</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Email</th>
                        <th class="px-5 py-3 text-center text-[11px] font-bold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Registered</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($this->recentAlumni as $alum)
                    <tr class="dash-row bg-white">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $this->getPhotoUrl($alum->profile_photo) }}"
                                     alt="{{ $alum->name }}"
                                     class="w-9 h-9 rounded-xl object-cover flex-shrink-0 ring-1 ring-gray-100 shadow-sm">
                                <div>
                                    <p class="font-bold text-gray-900 text-sm leading-tight">{{ $alum->name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <span class="font-mono text-xs font-bold text-gray-700">{{ $alum->student_id }}</span>
                        </td>
                        <td class="px-5 py-3 hidden md:table-cell">
                            <span class="badge badge-brand">{{ $alum->course_code ?? '—' }}</span>
                        </td>
                        <td class="px-5 py-3 text-center hidden md:table-cell">
                            <span class="font-mono text-xs font-bold text-gray-700">{{ $alum->batch }}</span>
                        </td>
                        <td class="px-5 py-3 hidden lg:table-cell">
                            <span class="text-xs text-gray-500">{{ $alum->email }}</span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            @php
                                $bc = match($alum->status) {
                                    'VERIFIED' => 'badge-verified',
                                    'PENDING'  => 'badge-pending',
                                    'REJECTED' => 'badge-rejected',
                                    default    => 'badge-default'
                                };
                            @endphp
                            <span class="badge {{ $bc }}">{{ $alum->status }}</span>
                        </td>
                        <td class="px-5 py-3 text-right hidden sm:table-cell">
                            <span class="text-xs text-gray-400 font-semibold">
                                {{ $alum->created_at->setTimezone('Asia/Manila')->diffForHumans() }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-16 text-center text-gray-400">
                            <i class="fas fa-users text-3xl mb-2 block text-gray-200"></i>
                            No alumni registered yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-gray-100 flex justify-between items-center" style="background:#f9f8fc;">
            <p class="text-xs text-gray-400 font-semibold">
                Showing latest {{ min(10, $this->stats['totalAlumni']) }} of {{ number_format($this->stats['totalAlumni']) }} alumni
            </p>
            <a href="{{ route('admin.alumni') }}" class="text-xs font-bold hover:underline" style="color:var(--brand);">
                View all alumni →
            </a>
        </div>
    </div>
    @endif

    {{-- ══════════════════════════════════════════
         TAB: QUICK ACTIONS
    ══════════════════════════════════════════ --}}
    @if($activeTab === 'quick')
    <div class="space-y-5 fade-up fade-up-4">

        {{-- Primary Actions ──────────────────── --}}
        <div>
            <div class="section-head mb-3">
                <div class="section-title"><span class="section-dot"></span>Alumni Management</div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <a href="{{ route('admin.alumni') }}" class="action-card">
                    <div class="action-icon" style="background:var(--brand-50);">
                        <i class="fas fa-users" style="color:var(--brand);"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">View Alumni</span>
                    <span class="text-xs text-gray-400">{{ number_format($this->stats['totalAlumni']) }} records</span>
                </a>
                <a href="{{ route('admin.alumni') }}" class="action-card">
                    <div class="action-icon" style="background:#f0fdf4;">
                        <i class="fas fa-user-plus" style="color:#16a34a;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Register Alumni</span>
                    <span class="text-xs text-gray-400">Add single record</span>
                </a>
                <a href="{{ route('admin.alumni') }}" class="action-card">
                    <div class="action-icon" style="background:#eff6ff;">
                        <i class="fas fa-file-import" style="color:#2563eb;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Bulk Import</span>
                    <span class="text-xs text-gray-400">CSV / Excel</span>
                </a>
                <a href="{{ route('admin.alumni') }}" class="action-card">
                    <div class="action-icon" style="background:#fffbeb;">
                        <i class="fas fa-sliders" style="color:#d97706;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Manage Courses</span>
                    <span class="text-xs text-gray-400">{{ $this->stats['totalCourses'] }} courses</span>
                </a>
            </div>
        </div>

        {{-- Organizer Actions ────────────────── --}}
        <div>
            <div class="section-head mb-3">
                <div class="section-title"><span class="section-dot"></span>Organizer Management</div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <a href="{{ route('admin.alumni') }}?tab=organizers" class="action-card">
                    <div class="action-icon" style="background:#faf5ff;">
                        <i class="fas fa-users-gear" style="color:#7c3aed;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">View Organizers</span>
                    <span class="text-xs text-gray-400">{{ $this->stats['totalOrganizers'] }} registered</span>
                </a>
                <a href="{{ route('admin.alumni') }}?tab=organizers" class="action-card">
                    <div class="action-icon" style="background:#f0fdf4;">
                        <i class="fas fa-user-tie" style="color:#16a34a;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Register Organizer</span>
                    <span class="text-xs text-gray-400">Add new organizer</span>
                </a>
                <a href="{{ route('admin.alumni') }}?tab=organizers" class="action-card">
                    <div class="action-icon" style="background:#eff6ff;">
                        <i class="fas fa-building-columns" style="color:#2563eb;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Manage Colleges</span>
                    <span class="text-xs text-gray-400">{{ $this->stats['totalColleges'] }} colleges</span>
                </a>
                <a href="{{ route('admin.alumni') }}?tab=organizers" class="action-card">
                    <div class="action-icon relative" style="background:#fff1f2;">
                        <i class="fas fa-toggle-off" style="color:#e11d48;"></i>
                        @if($this->stats['totalOrganizers'] - $this->stats['activeOrganizers'] > 0)
                        <span class="notif-dot"></span>
                        @endif
                    </div>
                    <span class="text-sm font-bold text-gray-800">Toggle Status</span>
                    <span class="text-xs text-gray-400">
                        {{ $this->stats['totalOrganizers'] - $this->stats['activeOrganizers'] }} inactive
                    </span>
                </a>
            </div>
        </div>

        {{-- Content Actions ───────────────────── --}}
        <div>
            <div class="section-head mb-3">
                <div class="section-title"><span class="section-dot"></span>Content & Events</div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <a href="{{ route('admin.events') }}" class="action-card">
                    <div class="action-icon relative" style="background:#fffbeb;">
                        <i class="fas fa-calendar-check" style="color:#d97706;"></i>
                        @if($this->stats['pendingEvents'] > 0)
                        <span class="notif-dot"></span>
                        @endif
                    </div>
                    <span class="text-sm font-bold text-gray-800">Events</span>
                    <span class="text-xs text-gray-400">
                        {{ $this->stats['pendingEvents'] }} pending
                    </span>
                </a>
                <a href="{{ route('admin.job-posts') }}" class="action-card">
                    <div class="action-icon" style="background:#fff1f2;">
                        <i class="fas fa-briefcase" style="color:#e11d48;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Job Posts</span>
                    <span class="text-xs text-gray-400">{{ $this->stats['activeJobs'] }} active</span>
                </a>
                <a href="{{ route('admin.job-posts') }}" class="action-card">
                    <div class="action-icon" style="background:#f0fdf4;">
                        <i class="fas fa-plus-circle" style="color:#16a34a;"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Post a Job</span>
                    <span class="text-xs text-gray-400">Create job posting</span>
                </a>
                <a href="{{ route('admin.dashboard') }}" class="action-card"
                   wire:click.prevent="refreshStats">
                    <div class="action-icon" style="background:var(--brand-50);">
                        <i class="fas fa-rotate-right" style="color:var(--brand);" wire:loading.class="spin" wire:target="refreshStats"></i>
                    </div>
                    <span class="text-sm font-bold text-gray-800">Refresh Data</span>
                    <span class="text-xs text-gray-400">Clear cached stats</span>
                </a>
            </div>
        </div>

        {{-- System Summary Card ──────────────── --}}
        <div class="card p-5 sm:p-6">
            <div class="section-head mb-4">
                <div class="section-title"><span class="section-dot"></span>System Summary</div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                @foreach([
                    ['Total Alumni',    $this->stats['totalAlumni'],    'fas fa-graduation-cap', '#7a3f91', '#f5eef9'],
                    ['Verified',        $this->stats['verifiedAlumni'], 'fas fa-circle-check',   '#16a34a', '#f0fdf4'],
                    ['Pending Review',  $this->stats['pendingAlumni'],  'fas fa-clock',          '#d97706', '#fffbeb'],
                    ['Organizers',      $this->stats['totalOrganizers'],'fas fa-users-gear',     '#2563eb', '#eff6ff'],
                    ['Active Orgs',     $this->stats['activeOrganizers'],'fas fa-check-circle',  '#0d9488', '#f0fdfa'],
                    ['Courses',         $this->stats['totalCourses'],   'fas fa-book',           '#7c3aed', '#faf5ff'],
                    ['Colleges',        $this->stats['totalColleges'],  'fas fa-building-columns','#1d4ed8','#eff6ff'],
                    ['Active Jobs',     $this->stats['activeJobs'],     'fas fa-briefcase',      '#e11d48', '#fff1f2'],
                ] as [$lbl,$val,$ico,$clr,$bg])
                <div class="flex items-center gap-3 p-3.5 rounded-xl border border-gray-100 bg-gray-50 hover:border-gray-200 transition">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:{{ $bg }};">
                        <i class="{{ $ico }} text-sm" style="color:{{ $clr }};"></i>
                    </div>
                    <div>
                        <p class="text-lg font-black text-gray-900 leading-none">{{ number_format($val) }}</p>
                        <p class="text-[10px] font-semibold text-gray-400 mt-0.5 leading-tight">{{ $lbl }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- ── FOOTER INFO BAR ────────────────────── --}}
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-3.5 rounded-2xl fade-up"
         style="background:var(--deep);">
        <div class="flex items-center gap-2.5">
            <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center">
                <i class="fas fa-shield-halved text-white/60 text-xs"></i>
            </div>
            <div>
                <p class="text-white text-xs font-bold">PHILCST Alumni System</p>
                <p class="text-white/40 text-[10px]">Admin Dashboard · {{ now()->setTimezone('Asia/Manila')->format('M j, Y') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span class="text-white/60 text-xs font-semibold">System Online</span>
            </div>
            <div class="text-white/30 text-xs">|</div>
            <span class="text-white/50 text-xs">Logged in as <strong class="text-white/70">{{ auth()->user()->name }}</strong></span>
        </div>
    </div>

</div>{{-- end page wrapper --}}
</div>{{-- end min-h-screen --}}