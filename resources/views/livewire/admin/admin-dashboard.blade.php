<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use App\Models\Alumni;
use App\Models\Organizer;
use App\Models\Course;
use App\Models\JobPosting;
use App\Models\AdminEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

new class extends Component {

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->role === 'admin',
            403
        );
        \Illuminate\Support\Facades\Log::info('Admin dashboard accessed', [
            'user_id' => auth()->id(),
            'ip'      => request()->ip(),
            'ua'      => substr(request()->userAgent() ?? '', 0, 120),
        ]);
    }

    #[Computed]
    public function stats(): array
    {
        return Cache::remember('admin_dashboard_stats', 60, function () {
            $totalAlumni      = Alumni::count();
            $verifiedAlumni   = Alumni::where('status', 'VERIFIED')->count();
            $totalOrganizers  = Organizer::withoutTrashed()->count();
            $activeOrganizers = Organizer::withoutTrashed()->where('status', 'ACTIVE')->count();
            $totalCourses     = Course::count();
            $totalColleges    = Course::whereNotNull('college')->where('college', '!=', '')->distinct('college')->count('college');
            $activeJobs       = JobPosting::where('status', 'ACTIVE')->count();
            $totalEvents      = AdminEvent::withoutTrashed()->count();
            $pendingEvents    = AdminEvent::withoutTrashed()->where('status', 'PENDING')->count();
            $thisMonth = Alumni::whereMonth('created_at', now()->month)
                               ->whereYear('created_at', now()->year)->count();
            $lastMonth = Alumni::whereMonth('created_at', now()->subMonth()->month)
                               ->whereYear('created_at', now()->subMonth()->year)->count();
            $growth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0);
            return compact(
                'totalAlumni', 'verifiedAlumni',
                'totalOrganizers', 'activeOrganizers',
                'totalCourses', 'totalColleges',
                'activeJobs', 'totalEvents', 'pendingEvents',
                'thisMonth', 'lastMonth', 'growth'
            );
        });
    }

    // All courses (not capped at 6)
    #[Computed]
    public function courseData(): array
    {
        return Cache::remember('dashboard_course_data_all', 120, function () {
            return Alumni::selectRaw('course_code, COUNT(*) as total')
                ->whereNotNull('course_code')
                ->groupBy('course_code')
                ->orderByDesc('total')
                ->pluck('total', 'course_code')
                ->toArray();
        });
    }

    // Course names for hover tooltips
    #[Computed]
    public function courseNames(): array
    {
        return Course::pluck('name', 'code')->toArray();
    }

    #[Computed]
    public function batchData(): array
    {
        return Cache::remember('dashboard_batch_data', 120, function () {
            $data = Alumni::selectRaw('batch, COUNT(*) as total')
                ->groupBy('batch')->orderByDesc('total')->limit(6)
                ->pluck('total', 'batch')->toArray();
            return $data;
        });
    }

    /**
     * Returns colleges with their courses and assigned organizer (if any).
     */
    #[Computed]
    public function collegesWithOrganizers(): array
    {
        $colleges = Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->orderBy('college')
            ->orderBy('code')
            ->get()
            ->groupBy('college');

        $organizers = Organizer::withoutTrashed()
            ->select('id', 'first_name', 'middle_initial', 'last_name', 'suffix',
                     'email', 'department', 'profile_photo', 'status')
            ->get();

        // Build a map: college name → organizer
        $orgMap = [];
        foreach ($organizers as $org) {
            $dept = $org->department;
            // direct match (department stored as college name)
            $collegeName = Course::where('college', $dept)->exists()
                ? $dept
                : (Course::where('code', $dept)->value('college') ?? $dept);
            if ($collegeName && !isset($orgMap[$collegeName])) {
                $orgMap[$collegeName] = $org;
            }
        }

        $result = [];
        foreach ($colleges as $collegeName => $courses) {
            $result[] = [
                'name'       => $collegeName,
                'courses'    => $courses->toArray(),
                'organizer'  => $orgMap[$collegeName] ?? null,
            ];
        }

        return $result;
    }

    #[Computed]
    public function inactiveOrganizers()
    {
        return Organizer::withoutTrashed()
            ->where('status', 'INACTIVE')
            ->orderByDesc('created_at')->limit(5)
            ->get(['id', 'first_name', 'last_name', 'middle_initial', 'suffix',
                   'email', 'department', 'profile_photo', 'created_at']);
    }

    #[Computed]
    public function recentEvents()
    {
        return AdminEvent::withoutTrashed()
            ->with('organizer:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'event_date', 'status', 'venue',
                   'organizer_id', 'target_participants', 'created_at']);
    }

    #[Computed]
    public function recentJobs()
    {
        return JobPosting::orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'job_title', 'company_name', 'status',
                   'employment_type', 'deadline', 'created_at']);
    }

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

    public function refreshStats(): void
    {
        $key = 'refresh_stats_uid_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $wait = RateLimiter::availableIn($key);
            $this->dispatch('flash-message', type: 'error',
                message: "Too many refresh requests. Please wait {$wait}s.");
            return;
        }
        RateLimiter::hit($key, 60);
        foreach ([
            'admin_dashboard_stats',
            'dashboard_batch_data',
            'dashboard_course_data_all',
        ] as $k) {
            Cache::forget($k);
        }
        $this->dispatch('flash-message', type: 'success', message: 'Dashboard refreshed successfully!');
    }
};
?>

<div class="min-h-screen" style="background:#f3f4f6;">

{{-- ══════════════════════════════════════
     CSS
══════════════════════════════════════ --}}
<style>
:root{
    --brand:#7a3f91;--brand-d:#5e2f72;--brand-l:#9b5bb0;
    --brand-50:#f5eef9;--brand-100:#e9d5f3;--brand-200:#d4aaeb;
    --surface:#ffffff;--border:#e5e7eb;
    --text-1:#111827;--text-2:#1f2937;--text-3:#4b5563;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06);
    --shadow-md:0 4px 16px rgba(0,0,0,.07);
    --shadow-brand:0 4px 16px rgba(122,63,145,.22);
}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes barInV{from{transform:scaleY(0);transform-origin:bottom}to{transform:scaleY(1);transform-origin:bottom}}
@keyframes barInH{from{transform:scaleX(0);transform-origin:left}to{transform:scaleX(1);transform-origin:left}}
@keyframes countUp{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}
@keyframes spin{to{transform:rotate(360deg)}}

.fade-up{animation:fadeUp .42s cubic-bezier(.25,.8,.25,1) both}
.fade-up-1{animation-delay:.05s}.fade-up-2{animation-delay:.1s}
.fade-up-3{animation-delay:.15s}.fade-up-4{animation-delay:.2s}
.fade-up-5{animation-delay:.25s}.fade-up-6{animation-delay:.3s}
.count-in{animation:countUp .5s cubic-bezier(.34,1.56,.64,1) both}

.card{background:var(--surface);border-radius:14px;border:1px solid var(--border);box-shadow:var(--shadow-sm);transition:box-shadow .2s}
.card:hover{box-shadow:var(--shadow-md)}

.stat-card{background:var(--surface);border-radius:14px;padding:20px 18px 18px;border:1px solid var(--border);box-shadow:var(--shadow-sm);transition:box-shadow .18s,transform .18s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.09)}
.stat-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.stat-value{font-size:2rem;font-weight:900;line-height:1;color:var(--text-1);letter-spacing:-.025em}
.stat-label{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3)}
.stat-sub{font-size:.72rem;font-weight:600;color:var(--text-2);margin-top:3px}

.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.section-title{font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2);display:flex;align-items:center;gap:7px}
.section-dot{width:5px;height:5px;border-radius:50%;background:var(--brand);flex-shrink:0}

.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:.65rem;font-weight:700}
.badge-approved{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.badge-pending{background:#fffbeb;color:#b45309;border:1px solid #fcd34d}
.badge-rejected{background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5}
.badge-active{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.badge-inactive{background:#fffbeb;color:#b45309;border:1px solid #fcd34d}
.badge-deleted{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}

.scroll-sm::-webkit-scrollbar{width:3px;height:3px}
.scroll-sm::-webkit-scrollbar-track{background:#f3f4f6;border-radius:99px}
.scroll-sm::-webkit-scrollbar-thumb{background:#ddd4f0;border-radius:99px}
.scroll-sm::-webkit-scrollbar-thumb:hover{background:var(--brand-l)}

.btn-ghost-sm{background:#fff;color:var(--text-2);border:1.5px solid var(--border);padding:6px 12px;border-radius:8px;font-size:.72rem;font-weight:700;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.btn-ghost-sm:hover{background:var(--brand-50);border-color:var(--brand-200);color:var(--brand)}

.recent-row{display:flex;align-items:flex-start;gap:12px;padding:10px 16px;border-bottom:1px solid #f3f4f6;transition:background .12s;text-decoration:none}
.recent-row:last-child{border-bottom:none}
.recent-row:hover{background:#faf5fd}

.c-wrap{position:relative}
.bar-v{animation:barInV .55s cubic-bezier(.34,1.56,.64,1) both;transform-origin:bottom}
.bar-h{animation:barInH .5s cubic-bezier(.34,1.56,.64,1) both;transform-origin:left}

[x-cloak]{display:none!important}

@media(max-width:480px){.stat-value{font-size:1.6rem}}
@media(max-width:767px){.stat-value{font-size:1.75rem}}
</style>

{{-- ══════════════════════════════════════
     FLASH TOAST
══════════════════════════════════════ --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-4 right-4 z-[999] flex items-start gap-3 px-4 py-3 rounded-2xl shadow-2xl max-w-xs w-full bg-white border"
     :class="{'border-emerald-200':type==='success','border-red-200':type==='error','border-blue-200':type==='info'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-50':type==='success','bg-red-50':type==='error','bg-blue-50':type==='info'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-triangle-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 text-gray-400 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-300 hover:text-gray-600 transition flex-shrink-0 mt-0.5">
        <i class="fas fa-xmark text-xs"></i>
    </button>
</div>

{{-- ══════════════════════════════════════
     PAGE WRAPPER
══════════════════════════════════════ --}}
<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-4">

    {{-- ── HEADER ── --}}
    <div class="fade-up" style="background:#7a3f91;border-radius:16px;padding:26px 28px;position:relative;overflow:hidden;">
        <div style="position:absolute;inset:0;background:url('data:image/svg+xml,%3Csvg width=\\'60\\' height=\\'60\\' viewBox=\\'0 0 60 60\\' xmlns=\\'http://www.w3.org/2000/svg\\'%3E%3Cg fill=\\'none\\'%3E%3Cg fill=\\'%23ffffff\\' fill-opacity=\\'0.06\\'%3E%3Cpath d=\\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>
        <div style="position:relative;z-index:1;" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div style="width:42px;height:42px;border-radius:11px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-gauge-high text-white text-lg"></i>
                    </div>
                    <div>
                        <p style="color:rgba(255,255,255,.6);font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;">Admin Panel</p>
                        <h1 style="color:#fff;font-size:1.55rem;font-weight:900;line-height:1.15;">Dashboard</h1>
                    </div>
                </div>
                <p style="color:rgba(255,255,255,.6);font-size:.77rem;margin-top:2px;">
                    Welcome back, <strong style="color:#fff;">{{ auth()->user()->name }}</strong>
                    &nbsp;·&nbsp; {{ now()->setTimezone('Asia/Manila')->format('l, F j, Y') }}
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                @if($this->stats['pendingEvents'] > 0)
                <a href="{{ route('events') }}"
                   style="display:inline-flex;align-items:center;gap:6px;color:#fbbf24;font-size:.75rem;font-weight:700;background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);border-radius:10px;padding:7px 12px;text-decoration:none;transition:background .15s;">
                    <i class="fas fa-calendar-exclamation"></i>
                    {{ $this->stats['pendingEvents'] }} Pending Event{{ $this->stats['pendingEvents']>1?'s':'' }}
                </a>
                @endif

                <button wire:click="refreshStats" wire:loading.attr="disabled"
                        style="display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);font-size:.75rem;font-weight:700;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:10px;padding:7px 12px;cursor:pointer;transition:background .15s;"
                        onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.12)'">
                    <i class="fas fa-rotate-right text-xs" wire:loading.class="spin" wire:target="refreshStats"></i>
                    <span wire:loading.remove wire:target="refreshStats">Refresh</span>
                    <span wire:loading wire:target="refreshStats">Refreshing…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">

        {{-- Verified Alumni --}}
        <div class="stat-card fade-up fade-up-2">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#f0fdf4;">
                    <i class="fas fa-circle-check" style="color:#16a34a;"></i>
                </div>
                @php $vp = $this->stats['totalAlumni'] > 0 ? round(($this->stats['verifiedAlumni'] / $this->stats['totalAlumni']) * 100) : 0; @endphp
                <span style="font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:99px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">{{ $vp }}%</span>
            </div>
            <div class="stat-value count-in">{{ number_format($this->stats['verifiedAlumni']) }}</div>
            <div class="stat-label mt-2">Verified</div>
            <div class="stat-sub">of total alumni</div>
        </div>

        {{-- Events Total --}}
        <div class="stat-card fade-up fade-up-3">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#fffbeb;">
                    <i class="fas fa-calendar-days" style="color:#d97706;"></i>
                </div>
                @if($this->stats['pendingEvents'] > 0)
                <span style="font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:99px;background:#fffbeb;color:#b45309;border:1px solid #fcd34d;">{{ $this->stats['pendingEvents'] }} pending</span>
                @endif
            </div>
            <div class="stat-value count-in">{{ number_format($this->stats['totalEvents']) }}</div>
            <div class="stat-label mt-2">Total Events</div>
            <div class="stat-sub">all time events</div>
        </div>

        {{-- Organizers --}}
        <div class="stat-card fade-up fade-up-4">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-icon" style="background:#eff6ff;">
                    <i class="fas fa-users-gear" style="color:#2563eb;"></i>
                </div>
                <span style="font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:99px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">{{ $this->stats['activeOrganizers'] }} active</span>
            </div>
            <div class="stat-value count-in">{{ $this->stats['totalOrganizers'] }}</div>
            <div class="stat-label mt-2">Organizers</div>
            <div class="stat-sub">{{ $this->stats['totalColleges'] }} college{{ $this->stats['totalColleges']!==1?'s':'' }}</div>
        </div>

        {{-- Courses --}}
        <div class="stat-card fade-up fade-up-5">
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
        <div class="stat-card fade-up fade-up-6">
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

    {{-- ── MAIN CONTENT ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 fade-up fade-up-4">

        {{-- LEFT 2/3 --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- ── COLLEGES & ORGANIZERS ── --}}
            <div class="card p-5 sm:p-6">
                <div class="section-head">
                    <div class="section-title">
                        <span class="section-dot"></span>Colleges &amp; Organizers
                    </div>
                    <span style="font-size:.72rem;color:var(--text-3);font-weight:600;">
                        {{ count($this->collegesWithOrganizers) }} college{{ count($this->collegesWithOrganizers)!==1?'s':'' }}
                    </span>
                </div>

                @if(count($this->collegesWithOrganizers))
                <div class="space-y-3 overflow-y-auto scroll-sm" style="max-height:420px;">
                    @foreach($this->collegesWithOrganizers as $item)
                    @php
                        $org       = $item['organizer'];
                        $courses   = $item['courses'];
                        $hasOrg    = $org !== null;
                        $isActive  = $hasOrg && ($org->status ?? $org['status'] ?? '') === 'ACTIVE';
                    @endphp
                    <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">

                        {{-- College header row --}}
                        <div style="background:#fafafa;padding:10px 14px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #f3f4f6;">
                            {{-- Icon --}}
                            <div style="width:34px;height:34px;border-radius:9px;background:var(--brand-50);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-building-columns" style="color:var(--brand);font-size:13px;"></i>
                            </div>

                            {{-- College name + dept codes --}}
                            <div style="flex:1;min-width:0;">
                                <p style="font-size:.82rem;font-weight:800;color:var(--text-1);line-height:1.2;">{{ $item['name'] }}</p>
                                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                                    @foreach($courses as $c)
                                    <span style="font-size:.62rem;font-weight:700;font-family:monospace;padding:1px 7px;border-radius:99px;background:var(--brand-50);color:var(--brand);border:1px solid var(--brand-200);">
                                        {{ $c['code'] }}
                                    </span>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Organizer status badge --}}
                            <div style="flex-shrink:0;">
                                @if($hasOrg)
                                    @if($isActive)
                                    <span class="badge badge-active">
                                        <i class="fas fa-circle" style="font-size:.45rem;"></i> Active
                                    </span>
                                    @else
                                    <span class="badge badge-inactive">
                                        <i class="fas fa-circle" style="font-size:.45rem;"></i> Inactive
                                    </span>
                                    @endif
                                @else
                                <span style="font-size:.65rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#f9fafb;color:#9ca3af;border:1px solid #e5e7eb;">
                                    No Organizer
                                </span>
                                @endif
                            </div>
                        </div>

                        {{-- Organizer info row --}}
                        @if($hasOrg)
                        @php
                            $orgArr   = is_array($org) ? $org : $org->toArray();
                            $orgName  = trim(implode(' ', array_filter([
                                $orgArr['first_name']     ?? '',
                                $orgArr['middle_initial'] ?? '',
                                $orgArr['last_name']      ?? '',
                                $orgArr['suffix']         ?? '',
                            ])));
                            $orgEmail = $orgArr['email']         ?? '';
                            $orgPhoto = $orgArr['profile_photo'] ?? null;
                        @endphp
                        <div style="padding:10px 14px;display:flex;align-items:center;gap:10px;background:#fff;">
                            <img src="{{ $this->getPhotoUrl($orgPhoto) }}"
                                 alt="{{ $orgName }}"
                                 style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;border:1px solid #e5e7eb;">
                            <div style="flex:1;min-width:0;">
                                <p style="font-size:.78rem;font-weight:700;color:var(--text-1);" class="truncate">{{ $orgName }}</p>
                                <p style="font-size:.68rem;color:var(--text-3);margin-top:1px;" class="truncate">{{ $orgEmail }}</p>
                            </div>
                            <i class="fas fa-users-gear" style="color:#d1d5db;font-size:14px;flex-shrink:0;"></i>
                        </div>
                        @else
                        <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;background:#fff;">
                            <div style="width:32px;height:32px;border-radius:8px;background:#f9fafb;border:1.5px dashed #e5e7eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-user-slash" style="color:#d1d5db;font-size:11px;"></i>
                            </div>
                            <p style="font-size:.75rem;color:#9ca3af;font-style:italic;">No organizer assigned to this college yet.</p>
                        </div>
                        @endif

                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-10">
                    <i class="fas fa-building-columns text-3xl text-gray-200 block mb-2"></i>
                    <p style="font-size:.875rem;color:var(--text-3);">No colleges configured yet.</p>
                </div>
                @endif
            </div>

            {{-- Alumni by Course — ALL courses, scrollable, multi-color, teleport tooltip --}}
            <div class="card p-5 sm:p-6">
                <div class="section-head">
                    <div class="section-title"><span class="section-dot"></span>Alumni by Course</div>
                    <span style="font-size:.72rem;color:var(--text-3);font-weight:600;">
                        {{ count($this->courseData) }} course{{ count($this->courseData)!==1?'s':'' }}
                    </span>
                </div>

                @php
                    $courseData  = $this->courseData;
                    $courseNames = $this->courseNames;
                    $maxC        = max(array_values($courseData) ?: [1]);

                    $palette = [
                        ['#7a3f91','#f5eef9'],
                        ['#2563eb','#eff6ff'],
                        ['#16a34a','#f0fdf4'],
                        ['#dc2626','#fef2f2'],
                        ['#d97706','#fffbeb'],
                        ['#0891b2','#ecfeff'],
                        ['#7c3aed','#f5f3ff'],
                        ['#db2777','#fdf4ff'],
                        ['#059669','#ecfdf5'],
                        ['#ea580c','#fff7ed'],
                        ['#4f46e5','#eef2ff'],
                        ['#0284c7','#e0f2fe'],
                        ['#65a30d','#f7fee7'],
                        ['#c026d3','#fdf4ff'],
                        ['#0f766e','#f0fdfa'],
                    ];
                @endphp

                @if(count($courseData))
                <div class="overflow-y-auto scroll-sm space-y-2" style="max-height:296px;">
                    @foreach($courseData as $code => $cnt)
                    @php
                        $idx   = $loop->index % count($palette);
                        $clr   = $palette[$idx][0];
                        $bg    = $palette[$idx][1];
                        $barW  = $maxC > 0 ? round(($cnt / $maxC) * 100) : 0;
                        $pct   = $this->stats['totalAlumni'] > 0
                            ? round(($cnt / $this->stats['totalAlumni']) * 100, 1) : 0;
                        $cName = $courseNames[$code] ?? $code;
                    @endphp

                    <div class="c-wrap"
                         x-data="{ tip: false, tipX: 0, tipY: 0 }"
                         @mouseenter="tip = true"
                         @mousemove="tipX = $event.clientX; tipY = $event.clientY"
                         @mouseleave="tip = false">

                        <div class="flex items-center gap-2.5">
                            <div class="flex items-center gap-1.5 shrink-0" style="width:88px;">
                                <span class="w-2 h-2 rounded-full shrink-0" style="background:{{ $clr }};"></span>
                                <span style="font-size:.72rem;font-weight:700;color:var(--text-1);font-family:monospace;">{{ $code }}</span>
                            </div>
                            <div class="flex-1 rounded-full overflow-hidden" style="height:18px;background:#f3f4f6;">
                                <div class="h-full rounded-full bar-h"
                                     style="width:{{ $barW }}%;background:{{ $clr }};animation-delay:{{ min($loop->index * 25, 600) }}ms;">
                                </div>
                            </div>
                            <div class="shrink-0 flex items-center gap-2" style="width:80px;justify-content:flex-end;">
                                <span style="font-size:.72rem;font-weight:600;color:var(--text-2);">{{ number_format($cnt) }}</span>
                                <span style="font-size:.68rem;font-weight:700;color:{{ $clr }};">{{ $pct }}%</span>
                            </div>
                        </div>

                        <template x-teleport="body">
                            <div x-show="tip"
                                 x-cloak
                                 :style="`position:fixed;left:${tipX}px;top:${tipY - 48}px;transform:translateX(-50%);
                                          background:#111827;color:#fff;font-size:.72rem;font-weight:600;
                                          padding:6px 14px;border-radius:8px;white-space:normal;
                                          max-width:320px;word-break:break-word;line-height:1.4;
                                          z-index:9999;pointer-events:none;
                                          box-shadow:0 4px 16px rgba(0,0,0,.25);`"
                                 style="display:none;">
                                {{ $cName }}
                                <div style="position:absolute;top:100%;left:50%;transform:translateX(-50%);
                                            border:5px solid transparent;border-top-color:#111827;"></div>
                            </div>
                        </template>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-8" style="color:var(--text-3);font-size:.875rem;">No course data available.</div>
                @endif
            </div>
        </div>

        {{-- RIGHT 1/3 --}}
        <div class="space-y-4">

            {{-- Top Batches --}}
            <div class="card p-5">
                <div class="section-head">
                    <div class="section-title"><span class="section-dot"></span>Top Batches</div>
                </div>
                @php
                    $batchData = $this->batchData;
                    $maxB = max(array_values($batchData) ?: [1]);
                @endphp
                <div class="space-y-2.5">
                    @forelse($batchData as $yr => $cnt)
                    @php $bpct = $maxB > 0 ? round(($cnt / $maxB) * 100) : 0; @endphp
                    <div>
                        <div class="flex justify-between mb-1">
                            <span style="font-size:.75rem;font-weight:700;color:var(--text-1);font-family:monospace;">{{ $yr }}</span>
                            <span style="font-size:.72rem;font-weight:600;color:var(--text-2);">{{ number_format($cnt) }}</span>
                        </div>
                        <div class="h-1.5 rounded-full overflow-hidden" style="background:#f3f4f6;">
                            <div class="h-full rounded-full" style="width:{{ $bpct }}%;background:#7a3f91;transition:width .7s;"></div>
                        </div>
                    </div>
                    @empty
                    <p style="font-size:.8rem;color:var(--text-3);text-align:center;padding:1rem 0;">No batch data yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Organizer Health --}}
            <div class="card p-5">
                <div class="section-head">
                    <div class="section-title"><span class="section-dot"></span>Organizer Health</div>
                </div>
                @php
                    $activeOrg   = $this->stats['activeOrganizers'];
                    $totalOrg    = $this->stats['totalOrganizers'];
                    $inactiveOrg = $totalOrg - $activeOrg;
                    $orgPct      = $totalOrg > 0 ? round(($activeOrg / $totalOrg) * 100) : 0;
                @endphp
                <div class="mb-4">
                    <div class="flex justify-between text-xs mb-1.5">
                        <span style="font-weight:600;color:var(--text-2);">Active Rate</span>
                        <span style="font-weight:700;color:#7a3f91;">{{ $orgPct }}%</span>
                    </div>
                    <div class="h-2.5 rounded-full overflow-hidden" style="background:#f3f4f6;">
                        <div class="h-full rounded-full" style="width:{{ $orgPct }}%;background:#7a3f91;transition:width .7s ease;"></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-4">
                    <div class="rounded-xl p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <p style="font-size:1.3rem;font-weight:900;color:#15803d;">{{ $activeOrg }}</p>
                        <p style="font-size:.62rem;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Active</p>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:#fffbeb;border:1px solid #fcd34d;">
                        <p style="font-size:1.3rem;font-weight:900;color:#b45309;">{{ $inactiveOrg }}</p>
                        <p style="font-size:.62rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Inactive</p>
                    </div>
                </div>

                @if(count($this->inactiveOrganizers) > 0)
                <div class="pt-3 border-t border-gray-100">
                    <p style="font-size:.62rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">Inactive Organizers</p>
                    <div class="space-y-2">
                        @foreach($this->inactiveOrganizers as $org)
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 text-xs font-black text-white" style="background:#7a3f91;">
                                {{ strtoupper(substr($org->first_name, 0, 1)) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p style="font-size:.75rem;font-weight:600;color:var(--text-1);" class="truncate">{{ $org->name }}</p>
                                <p style="font-size:.68rem;color:var(--text-3);" class="truncate">{{ $org->department }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

        </div>
    </div>

    {{-- ── RECENT EVENTS & JOBS ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 fade-up" style="animation-delay:.35s;">

        {{-- Recent Events --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100" style="background:#fafafa;">
                <div class="section-title">
                    <span class="section-dot"></span>
                    Recent Events
                </div>
            </div>

            @if($this->recentEvents->count())
            <div>
                @foreach($this->recentEvents as $event)
                @php
                    $es = $event->status;
                    $eBadge = match($es) {
                        'APPROVED'          => ['badge-approved', 'Approved'],
                        'PENDING'           => ['badge-pending',  'Pending'],
                        'REJECTED'          => ['badge-rejected', 'Rejected'],
                        'ORGANIZER_DELETED' => ['badge-deleted',  'Deleted'],
                        default             => ['badge-pending',  $es],
                    };
                @endphp
                <a href="{{ route('events') }}" class="recent-row">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background:#f5eef9;">
                        <i class="fas fa-calendar-check text-xs" style="color:#7a3f91;"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p style="font-size:.8rem;font-weight:700;color:var(--text-1);" class="truncate">{{ $event->title }}</p>
                        <p style="font-size:.7rem;color:var(--text-3);margin-top:2px;" class="truncate">
                            @if($event->venue)
                                <i class="fas fa-location-dot mr-1"></i>{{ $event->venue }} &nbsp;·&nbsp;
                            @endif
                            {{ $event->created_at->setTimezone('Asia/Manila')->diffForHumans() }}
                        </p>
                    </div>
                    <span class="badge {{ $eBadge[0] }} shrink-0">{{ $eBadge[1] }}</span>
                </a>
                @endforeach
            </div>
            @else
            <div class="py-10 text-center">
                <i class="fas fa-calendar text-2xl text-gray-200 block mb-2"></i>
                <p style="font-size:.825rem;color:var(--text-3);">No events yet.</p>
            </div>
            @endif

            <div class="px-5 py-2.5 border-t border-gray-100 flex items-center justify-between" style="background:#f9f8fc;">
                <p style="font-size:.72rem;color:var(--text-3);font-weight:600;">
                    {{ $this->stats['totalEvents'] }} total event{{ $this->stats['totalEvents']!==1?'s':'' }}
                </p>
                <a href="{{ route('events') }}"
                   style="font-size:.72rem;font-weight:700;color:#7a3f91;text-decoration:none;">
                    View all →
                </a>
            </div>
        </div>

        {{-- Recent Jobs --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100" style="background:#fafafa;">
                <div class="section-title">
                    <span class="section-dot"></span>
                    Recent Job Posts
                </div>
            </div>

            @if($this->recentJobs->count())
            <div>
                @foreach($this->recentJobs as $job)
                @php
                    $js = $job->status;
                    $jBadge = match($js) {
                        'ACTIVE'            => ['badge-active',   'Active'],
                        'INACTIVE'          => ['badge-inactive', 'Inactive'],
                        'ORGANIZER_DELETED' => ['badge-deleted',  'Deleted'],
                        default             => ['badge-inactive', $js],
                    };
                    $dl = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $isExp = now('Asia/Manila')->gt($dl);
                @endphp
                <a href="{{ route('job.posts') }}" class="recent-row">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background:#fff1f2;">
                        <i class="fas fa-briefcase text-xs" style="color:#e11d48;"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p style="font-size:.8rem;font-weight:700;color:var(--text-1);" class="truncate">{{ $job->job_title }}</p>
                        <p style="font-size:.7rem;color:var(--text-3);margin-top:2px;" class="truncate">
                            {{ $job->company_name }} &nbsp;·&nbsp; {{ $job->employment_type }}
                            @if($isExp)
                                &nbsp;·&nbsp; <span style="color:#dc2626;font-weight:600;">Expired</span>
                            @else
                                &nbsp;·&nbsp; {{ $job->created_at->setTimezone('Asia/Manila')->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    <span class="badge {{ $jBadge[0] }} shrink-0">{{ $jBadge[1] }}</span>
                </a>
                @endforeach
            </div>
            @else
            <div class="py-10 text-center">
                <i class="fas fa-briefcase text-2xl text-gray-200 block mb-2"></i>
                <p style="font-size:.825rem;color:var(--text-3);">No job posts yet.</p>
            </div>
            @endif

            <div class="px-5 py-2.5 border-t border-gray-100 flex items-center justify-between" style="background:#f9f8fc;">
                <p style="font-size:.72rem;color:var(--text-3);font-weight:600;">
                    {{ $this->stats['activeJobs'] }} active posting{{ $this->stats['activeJobs']!==1?'s':'' }}
                </p>
                <a href="{{ route('job.posts') }}"
                   style="font-size:.72rem;font-weight:700;color:#7a3f91;text-decoration:none;">
                    View all →
                </a>
            </div>
        </div>

    </div>

</div>{{-- end page wrapper --}}
</div>{{-- end min-h-screen --}}