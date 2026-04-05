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

    #[Computed]
    public function courseNames(): array
    {
        return Course::pluck('name', 'code')->toArray();
    }

    #[Computed]
    public function batchData(): array
    {
        return Cache::remember('dashboard_batch_data', 120, function () {
            return Alumni::selectRaw('batch, COUNT(*) as total')
                ->groupBy('batch')->orderByDesc('total')->limit(6)
                ->pluck('total', 'batch')->toArray();
        });
    }

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

        $orgMap = [];
        foreach ($organizers as $org) {
            $dept = $org->department;
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
                'name'      => $collegeName,
                'courses'   => $courses->toArray(),
                'organizer' => $orgMap[$collegeName] ?? null,
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

{{-- ═══════════════════════════════════════════════════════
     ROOT — font-sans + antialiased locks rendering to one
     consistent weight on every load / Livewire hydration.
═══════════════════════════════════════════════════════ --}}
<div class="min-h-screen bg-gray-100 font-sans antialiased">

{{-- ── Keyframes only – no class-level CSS here ── --}}
<style>
@keyframes fadeUp  { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:translateY(0) } }
@keyframes barInH  { from { transform:scaleX(0) }                   to { transform:scaleX(1) } }
@keyframes spinAni { to   { transform:rotate(360deg) } }

.fade-up   { animation: fadeUp .42s cubic-bezier(.25,.8,.25,1) both }
.fade-up-1 { animation-delay:.05s } .fade-up-2 { animation-delay:.10s }
.fade-up-3 { animation-delay:.15s } .fade-up-4 { animation-delay:.20s }
.fade-up-5 { animation-delay:.25s } .fade-up-6 { animation-delay:.30s }

.bar-h { animation: barInH .5s cubic-bezier(.34,1.56,.64,1) both; transform-origin: left }

/* Livewire spin target */
.spin-anim { animation: spinAni 1s linear infinite }

/* Thin custom scrollbar */
.scroll-sm::-webkit-scrollbar       { width:3px; height:3px }
.scroll-sm::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb { background:#ddd4f0; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb:hover { background:#9b5bb0 }

[x-cloak] { display:none !important }
</style>

{{-- ══════════════════════════════════════
     FLASH TOAST
══════════════════════════════════════ --}}
<div
    x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true;
            clearTimeout(this.timer);
            this.timer=setTimeout(()=>this.show=false,5000); }
    }"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-6 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-6"
    class="fixed top-4 right-4 z-[999] flex items-start gap-3 px-4 py-3 rounded-2xl shadow-2xl max-w-xs w-full bg-white border"
    :class="{
        'border-emerald-200': type==='success',
        'border-red-200':     type==='error',
        'border-blue-200':    type==='info'
    }"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{
             'bg-emerald-50': type==='success',
             'bg-red-50':     type==='error',
             'bg-blue-50':    type==='info'
         }">
        <i class="fas text-sm"
           :class="{
               'fa-check text-emerald-600':            type==='success',
               'fa-triangle-exclamation text-red-600': type==='error',
               'fa-info text-blue-600':                type==='info'
           }"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-gray-900 leading-none"
           x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-1 text-gray-400 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false"
            class="text-gray-300 hover:text-gray-600 transition-colors flex-shrink-0 mt-0.5">
        <i class="fas fa-xmark text-xs"></i>
    </button>
</div>

{{-- ══════════════════════════════════════
     PAGE WRAPPER
══════════════════════════════════════ --}}
<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-4">

    {{-- ── HEADER ── --}}
    <div class="fade-up bg-[#7a3f91] rounded-2xl px-7 py-6 relative overflow-hidden">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-[42px] h-[42px] rounded-[11px] bg-white/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-gauge-high text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-white/60 text-[.65rem] font-semibold uppercase tracking-[.12em] leading-none">Admin Panel</p>
                        <h1 class="text-white text-[1.55rem] font-semibold leading-tight tracking-tight">Dashboard</h1>
                    </div>
                </div>
                <p class="text-white/60 text-[.77rem] font-normal mt-0.5 leading-normal">
                    Welcome back,
                    <strong class="text-white font-semibold">{{ auth()->user()->name }}</strong>
                    &nbsp;·&nbsp;
                    {{ now()->setTimezone('Asia/Manila')->format('l, F j, Y') }}
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                @if($this->stats['pendingEvents'] > 0)
                <a href="{{ route('events') }}"
                   class="inline-flex items-center gap-1.5 text-amber-400 text-[.75rem] font-semibold
                          bg-amber-400/10 border border-amber-400/30 rounded-[10px] px-3 py-1.5
                          no-underline hover:bg-amber-400/20 transition-colors">
                    <i class="fas fa-calendar-exclamation"></i>
                    {{ $this->stats['pendingEvents'] }} Pending Event{{ $this->stats['pendingEvents']>1?'s':'' }}
                </a>
                @endif

                <button wire:click="refreshStats"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 text-white/80 text-[.75rem] font-semibold
                               bg-white/10 border border-white/20 rounded-[10px] px-3 py-1.5
                               cursor-pointer hover:bg-white/20 transition-colors">
                    <i class="fas fa-rotate-right text-xs"
                       wire:loading.class="spin-anim"
                       wire:target="refreshStats"></i>
                    <span wire:loading.remove wire:target="refreshStats">Refresh</span>
                    <span wire:loading        wire:target="refreshStats">Refreshing…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">

        {{-- Verified Alumni --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm
                    hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200
                    fade-up fade-up-2">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-green-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-circle-check text-green-600 text-[17px]"></i>
                </div>
                @php $vp = $this->stats['totalAlumni'] > 0
                    ? round(($this->stats['verifiedAlumni'] / $this->stats['totalAlumni']) * 100) : 0; @endphp
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full
                             bg-green-50 text-green-700 border border-green-200">{{ $vp }}%</span>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">
                {{ number_format($this->stats['verifiedAlumni']) }}
            </div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Verified</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">of total alumni</div>
        </div>

        {{-- Events Total --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm
                    hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200
                    fade-up fade-up-3">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-amber-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar-days text-amber-600 text-[17px]"></i>
                </div>
                @if($this->stats['pendingEvents'] > 0)
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full
                             bg-amber-50 text-amber-700 border border-yellow-300">
                    {{ $this->stats['pendingEvents'] }} pending
                </span>
                @endif
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">
                {{ number_format($this->stats['totalEvents']) }}
            </div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Total Events</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">all time events</div>
        </div>

        {{-- Organizers --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm
                    hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200
                    fade-up fade-up-4">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-users-gear text-blue-600 text-[17px]"></i>
                </div>
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full
                             bg-blue-50 text-blue-700 border border-blue-200">
                    {{ $this->stats['activeOrganizers'] }} active
                </span>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">
                {{ $this->stats['totalOrganizers'] }}
            </div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Organizers</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">
                {{ $this->stats['totalColleges'] }} college{{ $this->stats['totalColleges']!==1?'s':'' }}
            </div>
        </div>

        {{-- Courses --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm
                    hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200
                    fade-up fade-up-5">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-teal-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-book-open text-teal-600 text-[17px]"></i>
                </div>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">
                {{ $this->stats['totalCourses'] }}
            </div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Courses</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">
                {{ $this->stats['totalColleges'] }} colleges
            </div>
        </div>

        {{-- Active Jobs --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm
                    hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200
                    fade-up fade-up-6">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-rose-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-briefcase text-rose-600 text-[17px]"></i>
                </div>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">
                {{ $this->stats['activeJobs'] }}
            </div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Active Jobs</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">job postings</div>
        </div>

    </div>

    {{-- ── MAIN CONTENT ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 fade-up fade-up-4">

        {{-- LEFT 2/3 --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- ── COLLEGES & ORGANIZERS ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow p-5 sm:p-6">
                <div class="flex items-center justify-between mb-3.5">
                    <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                        <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                        Colleges &amp; Organizers
                    </div>
                    <span class="text-[.72rem] text-gray-500 font-normal">
                        {{ count($this->collegesWithOrganizers) }} college{{ count($this->collegesWithOrganizers)!==1?'s':'' }}
                    </span>
                </div>

                @if(count($this->collegesWithOrganizers))
                <div class="space-y-3 overflow-y-auto scroll-sm" style="max-height:420px;">
                    @foreach($this->collegesWithOrganizers as $item)
                    @php
                        $org      = $item['organizer'];
                        $courses  = $item['courses'];
                        $hasOrg   = $org !== null;
                        $isActive = $hasOrg && ($org->status ?? $org['status'] ?? '') === 'ACTIVE';
                    @endphp
                    <div class="border border-gray-200 rounded-xl overflow-hidden">

                        {{-- College header --}}
                        <div class="bg-gray-50 px-3.5 py-2.5 flex items-center gap-3 border-b border-gray-100">
                            <div class="w-[34px] h-[34px] rounded-[9px] bg-[#f5eef9] flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-building-columns text-[#7a3f91] text-[13px]"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[.82rem] font-semibold text-gray-900 leading-tight">{{ $item['name'] }}</p>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($courses as $c)
                                    <span class="text-[.62rem] font-semibold font-mono px-[7px] py-[1px] rounded-full
                                                 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb]">
                                        {{ $c['code'] }}
                                    </span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                @if($hasOrg)
                                    @if($isActive)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
                                                 text-[.65rem] font-semibold bg-green-50 text-green-700 border border-green-200">
                                        <i class="fas fa-circle text-[.45rem]"></i> Active
                                    </span>
                                    @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
                                                 text-[.65rem] font-semibold bg-amber-50 text-amber-700 border border-yellow-300">
                                        <i class="fas fa-circle text-[.45rem]"></i> Inactive
                                    </span>
                                    @endif
                                @else
                                <span class="text-[.65rem] font-semibold px-[9px] py-[2px] rounded-full
                                             bg-gray-50 text-gray-400 border border-gray-200">
                                    No Organizer
                                </span>
                                @endif
                            </div>
                        </div>

                        {{-- Organizer row --}}
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
                        <div class="px-3.5 py-2.5 flex items-center gap-2.5 bg-white">
                            <img src="{{ $this->getPhotoUrl($orgPhoto) }}"
                                 alt="{{ $orgName }}"
                                 class="w-8 h-8 rounded-lg object-cover flex-shrink-0 border border-gray-200">
                            <div class="flex-1 min-w-0">
                                <p class="text-[.78rem] font-semibold text-gray-900 truncate leading-none">{{ $orgName }}</p>
                                <p class="text-[.68rem] font-normal text-gray-500 mt-[3px] truncate">{{ $orgEmail }}</p>
                            </div>
                            <i class="fas fa-users-gear text-gray-300 text-sm flex-shrink-0"></i>
                        </div>
                        @else
                        <div class="px-3.5 py-2.5 flex items-center gap-2 bg-white">
                            <div class="w-8 h-8 rounded-lg bg-gray-50 border-2 border-dashed border-gray-200
                                        flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user-slash text-gray-300 text-[11px]"></i>
                            </div>
                            <p class="text-[.75rem] font-normal text-gray-400 italic">
                                No organizer assigned to this college yet.
                            </p>
                        </div>
                        @endif

                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-10">
                    <i class="fas fa-building-columns text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-sm font-normal text-gray-500">No colleges configured yet.</p>
                </div>
                @endif
            </div>

            {{-- ── ALUMNI BY COURSE ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow p-5 sm:p-6">
                <div class="flex items-center justify-between mb-3.5">
                    <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                        <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                        Alumni by Course
                    </div>
                    <span class="text-[.72rem] text-gray-500 font-normal">
                        {{ count($this->courseData) }} course{{ count($this->courseData)!==1?'s':'' }}
                    </span>
                </div>

                @php
                    $courseData  = $this->courseData;
                    $courseNames = $this->courseNames;
                    $maxC        = max(array_values($courseData) ?: [1]);
                    $palette = [
                        ['#7a3f91','#f5eef9'], ['#2563eb','#eff6ff'], ['#16a34a','#f0fdf4'],
                        ['#dc2626','#fef2f2'], ['#d97706','#fffbeb'], ['#0891b2','#ecfeff'],
                        ['#7c3aed','#f5f3ff'], ['#db2777','#fdf4ff'], ['#059669','#ecfdf5'],
                        ['#ea580c','#fff7ed'], ['#4f46e5','#eef2ff'], ['#0284c7','#e0f2fe'],
                        ['#65a30d','#f7fee7'], ['#c026d3','#fdf4ff'], ['#0f766e','#f0fdfa'],
                    ];
                @endphp

                @if(count($courseData))
                <div class="overflow-y-auto scroll-sm space-y-2" style="max-height:296px;">
                    @foreach($courseData as $code => $cnt)
                    @php
                        $idx  = $loop->index % count($palette);
                        $clr  = $palette[$idx][0];
                        $barW = $maxC > 0 ? round(($cnt / $maxC) * 100) : 0;
                        $pct  = $this->stats['totalAlumni'] > 0
                            ? round(($cnt / $this->stats['totalAlumni']) * 100, 1) : 0;
                        $cName = $courseNames[$code] ?? $code;
                    @endphp

                    <div class="relative"
                         x-data="{ tip: false, tipX: 0, tipY: 0 }"
                         @mouseenter="tip = true"
                         @mousemove="tipX = $event.clientX; tipY = $event.clientY"
                         @mouseleave="tip = false">

                        <div class="flex items-center gap-2.5">
                            <div class="flex items-center gap-1.5 shrink-0" style="width:88px;">
                                <span class="w-2 h-2 rounded-full shrink-0" style="background:{{ $clr }};"></span>
                                <span class="text-[.72rem] font-semibold text-gray-900 font-mono">{{ $code }}</span>
                            </div>
                            <div class="flex-1 rounded-full overflow-hidden bg-gray-100" style="height:18px;">
                                <div class="h-full rounded-full bar-h"
                                     style="width:{{ $barW }}%;background:{{ $clr }};animation-delay:{{ min($loop->index * 25, 600) }}ms;">
                                </div>
                            </div>
                            <div class="shrink-0 flex items-center gap-2" style="width:80px;justify-content:flex-end;">
                                <span class="text-[.72rem] font-semibold text-gray-700">{{ number_format($cnt) }}</span>
                                <span class="text-[.68rem] font-semibold" style="color:{{ $clr }};">{{ $pct }}%</span>
                            </div>
                        </div>

                        <template x-teleport="body">
                            <div x-show="tip"
                                 x-cloak
                                 :style="`position:fixed;left:${tipX}px;top:${tipY - 48}px;
                                          transform:translateX(-50%);background:#111827;color:#fff;
                                          font-size:.72rem;font-weight:600;padding:6px 14px;
                                          border-radius:8px;white-space:normal;max-width:320px;
                                          word-break:break-word;line-height:1.4;z-index:9999;
                                          pointer-events:none;box-shadow:0 4px 16px rgba(0,0,0,.25);`"
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
                <div class="text-center py-8 text-sm font-normal text-gray-500">No course data available.</div>
                @endif
            </div>

        </div>

        {{-- RIGHT 1/3 --}}
        <div class="space-y-4">

            {{-- Top Batches --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow p-5">
                <div class="flex items-center justify-between mb-3.5">
                    <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                        <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                        Top Batches
                    </div>
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
                            <span class="text-[.75rem] font-semibold text-gray-900 font-mono">{{ $yr }}</span>
                            <span class="text-[.72rem] font-semibold text-gray-700">{{ number_format($cnt) }}</span>
                        </div>
                        <div class="h-1.5 rounded-full overflow-hidden bg-gray-100">
                            <div class="h-full rounded-full bg-[#7a3f91] transition-all duration-700"
                                 style="width:{{ $bpct }}%;"></div>
                        </div>
                    </div>
                    @empty
                    <p class="text-[.8rem] font-normal text-gray-500 text-center py-4">No batch data yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Organizer Health --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow p-5">
                <div class="flex items-center justify-between mb-3.5">
                    <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                        <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                        Organizer Health
                    </div>
                </div>
                @php
                    $activeOrg   = $this->stats['activeOrganizers'];
                    $totalOrg    = $this->stats['totalOrganizers'];
                    $inactiveOrg = $totalOrg - $activeOrg;
                    $orgPct      = $totalOrg > 0 ? round(($activeOrg / $totalOrg) * 100) : 0;
                @endphp
                <div class="mb-4">
                    <div class="flex justify-between text-xs mb-1.5">
                        <span class="font-semibold text-gray-700">Active Rate</span>
                        <span class="font-semibold text-[#7a3f91]">{{ $orgPct }}%</span>
                    </div>
                    <div class="h-2.5 rounded-full overflow-hidden bg-gray-100">
                        <div class="h-full rounded-full bg-[#7a3f91] transition-all duration-700"
                             style="width:{{ $orgPct }}%;"></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-4">
                    <div class="rounded-xl p-3 text-center bg-green-50 border border-green-200">
                        <p class="text-[1.3rem] font-semibold text-green-700 leading-none">{{ $activeOrg }}</p>
                        <p class="text-[.62rem] font-semibold text-green-600 uppercase tracking-[.05em] mt-1">Active</p>
                    </div>
                    <div class="rounded-xl p-3 text-center bg-amber-50 border border-yellow-300">
                        <p class="text-[1.3rem] font-semibold text-amber-700 leading-none">{{ $inactiveOrg }}</p>
                        <p class="text-[.62rem] font-semibold text-amber-600 uppercase tracking-[.05em] mt-1">Inactive</p>
                    </div>
                </div>

                @if(count($this->inactiveOrganizers) > 0)
                <div class="pt-3 border-t border-gray-100">
                    <p class="text-[.62rem] font-semibold text-gray-500 uppercase tracking-[.08em] mb-2">
                        Inactive Organizers
                    </p>
                    <div class="space-y-2">
                        @foreach($this->inactiveOrganizers as $org)
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg bg-[#7a3f91] flex items-center justify-center
                                        shrink-0 text-xs font-semibold text-white">
                                {{ strtoupper(substr($org->first_name, 0, 1)) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[.75rem] font-semibold text-gray-900 truncate leading-none">{{ $org->name }}</p>
                                <p class="text-[.68rem] font-normal text-gray-500 mt-[3px] truncate">{{ $org->department }}</p>
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
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 bg-gray-50">
                <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                    <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                    Recent Events
                </div>
            </div>

            @if($this->recentEvents->count())
            <div>
                @foreach($this->recentEvents as $event)
                @php
                    $eBadge = match($event->status) {
                        'APPROVED'          => ['bg-green-50 text-green-700 border-green-200',   'Approved'],
                        'PENDING'           => ['bg-amber-50 text-amber-700 border-yellow-300',  'Pending'],
                        'REJECTED'          => ['bg-red-50 text-red-700 border-red-300',         'Rejected'],
                        'ORGANIZER_DELETED' => ['bg-red-50 text-red-800 border-red-300',         'Deleted'],
                        default             => ['bg-amber-50 text-amber-700 border-yellow-300',  $event->status],
                    };
                @endphp
                <a href="{{ route('events') }}"
                   class="flex items-start gap-3 px-5 py-2.5 border-b border-gray-50 last:border-b-0
                          hover:bg-[#faf5fd] transition-colors no-underline">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 bg-[#f5eef9]">
                        <i class="fas fa-calendar-check text-xs text-[#7a3f91]"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[.8rem] font-semibold text-gray-900 truncate leading-none">{{ $event->title }}</p>
                        <p class="text-[.7rem] font-normal text-gray-500 mt-1 truncate">
                            @if($event->venue)
                                <i class="fas fa-location-dot mr-1"></i>{{ $event->venue }} &nbsp;·&nbsp;
                            @endif
                            {{ $event->created_at->setTimezone('Asia/Manila')->diffForHumans() }}
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
                                 text-[.65rem] font-semibold border shrink-0 {{ $eBadge[0] }}">
                        {{ $eBadge[1] }}
                    </span>
                </a>
                @endforeach
            </div>
            @else
            <div class="py-10 text-center">
                <i class="fas fa-calendar text-2xl text-gray-200 block mb-2"></i>
                <p class="text-[.825rem] font-normal text-gray-500">No events yet.</p>
            </div>
            @endif

            <div class="px-5 py-2.5 border-t border-gray-100 flex items-center justify-between bg-[#f9f8fc]">
                <p class="text-[.72rem] font-normal text-gray-500">
                    {{ $this->stats['totalEvents'] }} total event{{ $this->stats['totalEvents']!==1?'s':'' }}
                </p>
                <a href="{{ route('events') }}" class="text-[.72rem] font-semibold text-[#7a3f91] no-underline">
                    View all →
                </a>
            </div>
        </div>

        {{-- Recent Jobs --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 bg-gray-50">
                <div class="flex items-center gap-[7px] text-[.75rem] font-semibold uppercase tracking-[.08em] text-gray-800">
                    <span class="w-[5px] h-[5px] rounded-full bg-[#7a3f91] flex-shrink-0"></span>
                    Recent Job Posts
                </div>
            </div>

            @if($this->recentJobs->count())
            <div>
                @foreach($this->recentJobs as $job)
                @php
                    $jBadge = match($job->status) {
                        'ACTIVE'            => ['bg-green-50 text-green-700 border-green-200',  'Active'],
                        'INACTIVE'          => ['bg-amber-50 text-amber-700 border-yellow-300', 'Inactive'],
                        'ORGANIZER_DELETED' => ['bg-red-50 text-red-800 border-red-300',        'Deleted'],
                        default             => ['bg-amber-50 text-amber-700 border-yellow-300', $job->status],
                    };
                    $dl    = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $isExp = now('Asia/Manila')->gt($dl);
                @endphp
                <a href="{{ route('job.posts') }}"
                   class="flex items-start gap-3 px-5 py-2.5 border-b border-gray-50 last:border-b-0
                          hover:bg-[#faf5fd] transition-colors no-underline">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 bg-rose-50">
                        <i class="fas fa-briefcase text-xs text-rose-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[.8rem] font-semibold text-gray-900 truncate leading-none">{{ $job->job_title }}</p>
                        <p class="text-[.7rem] font-normal text-gray-500 mt-1 truncate">
                            {{ $job->company_name }} &nbsp;·&nbsp; {{ $job->employment_type }}
                            @if($isExp)
                                &nbsp;·&nbsp; <span class="text-red-600 font-semibold">Expired</span>
                            @else
                                &nbsp;·&nbsp; {{ $job->created_at->setTimezone('Asia/Manila')->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
                                 text-[.65rem] font-semibold border shrink-0 {{ $jBadge[0] }}">
                        {{ $jBadge[1] }}
                    </span>
                </a>
                @endforeach
            </div>
            @else
            <div class="py-10 text-center">
                <i class="fas fa-briefcase text-2xl text-gray-200 block mb-2"></i>
                <p class="text-[.825rem] font-normal text-gray-500">No job posts yet.</p>
            </div>
            @endif

            <div class="px-5 py-2.5 border-t border-gray-100 flex items-center justify-between bg-[#f9f8fc]">
                <p class="text-[.72rem] font-normal text-gray-500">
                    {{ $this->stats['activeJobs'] }} active posting{{ $this->stats['activeJobs']!==1?'s':'' }}
                </p>
                <a href="{{ route('job.posts') }}" class="text-[.72rem] font-semibold text-[#7a3f91] no-underline">
                    View all →
                </a>
            </div>
        </div>

    </div>

</div>{{-- end page wrapper --}}
</div>{{-- end root --}}