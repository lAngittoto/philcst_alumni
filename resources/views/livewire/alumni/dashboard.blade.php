{{-- resources/views/livewire/alumni/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\AdminEvent;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $alumniName       = '';
    public string $alumniFirstName  = '';
    public string $alumniLastName   = '';
    public string $alumniCourse     = '';
    public string $alumniCourseCode = '';
    public string $alumniCollege    = '';
    public string $alumniStudentId  = '';
    public string $alumniBatch      = '';
    public bool   $profileComplete  = false;
    public bool   $hasEmployment    = false;
    public string $employmentStatus = '';
    public string $jobTitle         = '';
    public string $companyName      = '';
    public string $educationStatus  = '';
    public int    $alumniId         = 0;

    public int $totalEvents    = 0;
    public int $upcomingEvents = 0;
    public int $activeJobs     = 0;
    public int $myRsvps        = 0;

    public array $recentEvents = [];
    public array $recentJobs   = [];

    public function mount(): void
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)->first();
        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId         = $alumni->id;
        $this->alumniFirstName  = $alumni->first_name ?? '';
        $this->alumniLastName   = $alumni->last_name  ?? '';
        $this->alumniName       = trim(($alumni->first_name ?? '') . ' ' . ($alumni->last_name ?? ''));
        $this->alumniCourseCode = $alumni->course_code ?? '';
        $this->alumniCourse     = $alumni->course_name ?? $alumni->course_code ?? '';
        $this->alumniBatch      = (string)($alumni->batch ?? '');
        $this->alumniStudentId  = $alumni->student_id ?? '';
        $this->profileComplete  = (bool)($alumni->profile_completed ?? false);

        $this->alumniCollege = Cache::remember(
            'alumni_college_' . $this->alumniCourseCode, 600,
            fn() => Course::where('code', $this->alumniCourseCode)->value('college') ?? ''
        );

        $employment = DB::table('employment_trackings')
            ->where('alumni_id', $alumni->id)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if ($employment) {
            $this->hasEmployment    = true;
            $this->employmentStatus = $employment->employment_status ?? '';
            $this->jobTitle         = $employment->job_title         ?? '';
            $this->companyName      = $employment->company_name      ?? '';
            $this->educationStatus  = $employment->education_status  ?? '';
        }

        $college = $this->alumniCollege;
        $course  = $this->alumniCourseCode;
        $now     = Carbon::now('UTC');

        $this->upcomingEvents = Cache::remember("dash_upcoming_{$college}", 120, function () use ($college, $course, $now) {
            $admin = AdminEvent::withoutTrashed()->where('status', 'APPROVED')
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->where('event_date', '>', $now)->count();
            $org = OrganizerEvent::where('status', 'APPROVED')
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                    ->orWhere('target_participants', 'like', "%{$course}%"))
                ->where('event_date', '>', $now)->count();
            return $admin + $org;
        });

        $this->totalEvents = Cache::remember("dash_total_{$college}", 120, function () use ($college, $course) {
            $admin = AdminEvent::withoutTrashed()->where('status', 'APPROVED')
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->count();
            $org = OrganizerEvent::where('status', 'APPROVED')
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                    ->orWhere('target_participants', 'like', "%{$course}%"))
                ->count();
            return $admin + $org;
        });

        $this->activeJobs = Cache::remember("dash_jobs_{$college}", 120, function () use ($college) {
            return JobPosting::where('status', 'ACTIVE')
                ->where(fn($q) => $q->whereNull('target_college')
                                    ->orWhere('target_college', '')
                                    ->orWhere('target_college', 'like', "%{$college}%"))
                ->where('deadline', '>=', now('Asia/Manila')->toDateString())
                ->count();
        });

        $this->myRsvps = \App\Models\EventRsvp::where('alumni_id', $alumni->id)
            ->where('response', 'CONFIRMED')->count();

        $adminEvts = AdminEvent::withoutTrashed()->where('status', 'APPROVED')
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                ->orWhere('target_participants', 'like', "%{$college}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')->limit(3)
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'    => $e->id, 'source' => 'ADMIN',
                'title' => $e->title,
                'date'  => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'venue' => $e->venue ?? '',
                'photo' => $e->photo_url ?? '',
            ])->toArray();

        $orgEvts = OrganizerEvent::where('status', 'APPROVED')
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')->limit(3)
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'    => $e->id, 'source' => 'ORGANIZER',
                'title' => $e->title,
                'date'  => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'venue' => $e->venue ?? '',
                'photo' => $e->photo_url ?? '',
            ])->toArray();

        $this->recentEvents = collect(array_merge($adminEvts, $orgEvts))
            ->sortBy('date')->take(3)->values()->toArray();

        $this->recentJobs = JobPosting::where('status', 'ACTIVE')
            ->where(fn($q) => $q->whereNull('target_college')
                                ->orWhere('target_college', '')
                                ->orWhere('target_college', 'like', "%{$college}%"))
            ->where('deadline', '>=', now('Asia/Manila')->toDateString())
            ->orderByDesc('created_at')->limit(3)
            ->get(['id','job_title','company_name','employment_type','location','deadline','salary'])
            ->map(fn($j) => [
                'id'       => $j->id,
                'title'    => $j->job_title,
                'company'  => $j->company_name,
                'type'     => $j->employment_type,
                'location' => $j->location ?? '',
                'salary'   => $j->salary   ?? '',
                'deadline' => Carbon::parse($j->deadline)->setTimezone('Asia/Manila')->format('M d, Y'),
            ])->toArray();
    }

    public function getGreeting(): string
    {
        $h = (int) Carbon::now('Asia/Manila')->format('H');
        if ($h < 12) return 'Good morning';
        if ($h < 17) return 'Good afternoon';
        return 'Good evening';
    }
}; ?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-8 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ══ PAGE HEADER ═══════════════════════════════════════════════════════ --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-4xl font-extrabold text-[#2b0d3e] leading-tight">Alumni Dashboard</h1>
            <p class="text-gray-500 text-sm sm:text-base">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    {{-- ══ TIP BANNER ════════════════════════════════════════════════════════ --}}
    @if(!$profileComplete || !$hasEmployment)
    <div class="flex items-start gap-3 rounded-2xl px-4 py-3 border border-purple-200 bg-purple-50 mb-5">
        <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center shadow"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fa-solid fa-lightbulb text-yellow-300 text-sm"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-base font-bold text-purple-900">
                @if(!$profileComplete)
                    Complete your profile to unlock all portal features.
                @else
                    One more step — submit your employment information.
                @endif
            </p>
            <p class="text-sm mt-0.5 leading-snug text-purple-700">
                @if(!$profileComplete)
                    Fill in your personal info under <strong>My Profile</strong> to keep alumni records accurate.
                @else
                    Help PHILCST track graduate outcomes. Go to <strong>Employment Tracking</strong>.
                @endif
            </p>
        </div>
        <a href="{{ !$profileComplete ? route('alumni.information') : route('alumni.employment') }}"
           class="flex-shrink-0 text-sm font-bold px-3 py-2 rounded-lg text-white shadow transition hover:opacity-90"
           style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            Go <i class="fa-solid fa-arrow-right ml-1 text-sm"></i>
        </a>
    </div>
    @endif

    {{-- ══ STAT CARDS ════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Upcoming Events --}}
        <div class="bg-white rounded-2xl border border-purple-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07]" style="background:#7a3f91;"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                    <i class="fa-solid fa-calendar-check text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-50 text-purple-600 border border-purple-100 uppercase">Events</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ $upcomingEvents }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Upcoming Events</p>
            @if($upcomingEvents > 0)
                <p class="text-xs text-purple-600 font-bold mt-2 flex items-center gap-1">
                    <i class="fas fa-arrow-trend-up text-sm"></i> For your college
                </p>
            @endif
        </div>

        {{-- Total Events --}}
        <div class="bg-white rounded-2xl border border-violet-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07]" style="background:#6d28d9;"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
                    <i class="fa-solid fa-calendar-days text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-violet-50 text-violet-600 border border-violet-100 uppercase">Total</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ $totalEvents }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Total Events</p>
            @if($totalEvents > 0 && $upcomingEvents > 0)
                <p class="text-xs text-violet-600 font-bold mt-2">
                    {{ round(($upcomingEvents / $totalEvents) * 100) }}% still upcoming
                </p>
            @endif
        </div>

        {{-- Active Jobs --}}
        <div class="bg-white rounded-2xl border border-blue-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07] bg-blue-500"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-briefcase text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Jobs</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ $activeJobs }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Active Job Posts</p>
            @if($activeJobs > 0)
                <p class="text-xs text-blue-600 font-bold mt-2 flex items-center gap-1">
                    <i class="fas fa-circle-dot text-sm"></i> Open for your college
                </p>
            @endif
        </div>

        {{-- My RSVPs --}}
        <div class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07] bg-emerald-500"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-circle-check text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase">RSVPs</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ $myRsvps }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">My RSVPs</p>
            @if($myRsvps > 0)
                <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full"
                         style="width:{{ min(($myRsvps / max($totalEvents,1)) * 100, 100) }}%;"></div>
                </div>
                <p class="text-xs text-gray-400 mt-1">Confirmed attendances</p>
            @endif
        </div>

    </div>

    {{-- ══ PROFILE & EMPLOYMENT ══════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">

        {{-- PROFILE CARD --}}
        <div class="bg-white rounded-2xl border border-purple-100 shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#f9f5ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <i class="fa-solid fa-user text-white" style="font-size:12px;"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">My Profile</p>
                        <p class="text-xs text-gray-400">Personal information</p>
                    </div>
                </div>
                @if($profileComplete)
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">
                        <i class="fa-solid fa-circle-check text-xs"></i> Complete
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 uppercase">
                        <i class="fa-solid fa-triangle-exclamation text-xs"></i> Incomplete
                    </span>
                @endif
            </div>

            <div class="p-4 space-y-3">
                {{-- Avatar + Name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl flex-shrink-0 flex items-center justify-center text-lg font-black text-white shadow"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                    </div>
                    <div class="min-w-0">
                        <p class="font-extrabold text-gray-900 text-base leading-snug truncate uppercase">
                            {{ $alumniName ?: '—' }}
                        </p>
                        <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                </div>

                {{-- Course / Batch / College --}}
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-gray-50 rounded-xl p-2.5 border border-gray-100">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-400 mb-0.5">Course</p>
                        <p class="text-sm font-extrabold text-gray-800 truncate uppercase">{{ $alumniCourseCode ?: '—' }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-2.5 border border-gray-100">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-400 mb-0.5">Batch</p>
                        <p class="text-sm font-extrabold text-gray-800">{{ $alumniBatch ?: '—' }}</p>
                    </div>
                </div>

                @if($alumniCollege)
                <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400 mb-0.5">College</p>
                    <p class="text-sm font-semibold text-gray-700 uppercase leading-snug">{{ $alumniCollege }}</p>
                </div>
                @endif

                {{-- Completion Bar --}}
                @php $pct = $profileComplete ? 100 : 40; @endphp
                <div>
                    <div class="flex justify-between mb-1">
                        <span class="text-xs font-bold text-gray-500">Profile completion</span>
                        <span class="text-xs font-extrabold" style="color:#7a3f91;">{{ $pct }}%</span>
                    </div>
                    <div class="h-1.5 rounded-full overflow-hidden" style="background:#f0e6f8;">
                        <div class="h-full rounded-full transition-all duration-500"
                             style="width:{{ $pct }}%; background:linear-gradient(90deg,#7a3f91,#9b59b6);"></div>
                    </div>
                </div>

                <a href="{{ route('alumni.information') }}"
                   class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white shadow hover:opacity-90 active:scale-[.98] transition"
                   style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                    <i class="fa-solid fa-pen text-sm"></i>
                    {{ $profileComplete ? 'View / Edit Profile' : 'Complete My Profile' }}
                </a>
            </div>
        </div>

        {{-- EMPLOYMENT CARD --}}
        <div class="bg-white rounded-2xl border border-blue-100 shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#eff6ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg bg-blue-500 flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                        <i class="fa-solid fa-briefcase text-white" style="font-size:12px;"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Employment</p>
                        <p class="text-xs text-gray-400">Current work status</p>
                    </div>
                </div>
                @if($hasEmployment)
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">
                        <i class="fa-solid fa-circle-check text-xs"></i> Submitted
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 uppercase">
                        <i class="fa-solid fa-triangle-exclamation text-xs"></i> No Record
                    </span>
                @endif
            </div>

            <div class="p-4 space-y-3">
                @if($hasEmployment)
                    @php
                        $sMap = [
                            'employed'      => ['Employed',      'fa-user-tie',         'text-violet-700', 'bg-violet-50 border-violet-200'],
                            'self_employed' => ['Self-Employed', 'fa-store',            'text-blue-700',   'bg-blue-50 border-blue-200'],
                            'unemployed'    => ['Unemployed',    'fa-magnifying-glass', 'text-orange-700', 'bg-orange-50 border-orange-200'],
                        ];
                        $s = $sMap[$employmentStatus] ?? ['Unknown','fa-circle','text-gray-600','bg-gray-100 border-gray-200'];
                    @endphp

                    {{-- Status Badge --}}
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full border {{ $s[2] }} {{ $s[3] }} uppercase">
                        <i class="fa-solid {{ $s[1] }} text-sm"></i> {{ $s[0] }}
                    </span>

                    @if($jobTitle || $companyName)
                    <div class="bg-gray-50 rounded-xl p-3 border border-gray-100 space-y-1.5">
                        @if($jobTitle)
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Position</p>
                            <p class="text-sm font-extrabold text-gray-800 uppercase">{{ $jobTitle }}</p>
                        </div>
                        @endif
                        @if($companyName)
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Company</p>
                            <p class="text-sm font-semibold text-gray-700 uppercase">{{ $companyName }}</p>
                        </div>
                        @endif
                    </div>
                    @endif

                    @php
                        $eMap = [
                            'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',     'text-blue-700',   'bg-blue-50 border-blue-200'],
                            'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard', 'text-violet-700', 'bg-violet-50 border-violet-200'],
                        ];
                        $e = $eMap[$educationStatus] ?? null;
                    @endphp
                    @if($e)
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full border {{ $e[2] }} {{ $e[3] }} uppercase">
                            <i class="fa-solid {{ $e[1] }} text-sm"></i> {{ $e[0] }}
                        </span>
                    @endif

                @else
                    <div class="py-8 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3 bg-blue-50">
                            <i class="fa-solid fa-briefcase text-3xl text-blue-300"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-400">No employment record yet.</p>
                        <p class="text-xs text-gray-300 mt-1">Help us track graduate outcomes.</p>
                    </div>
                @endif

                <a href="{{ route('alumni.employment') }}"
                   class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white shadow hover:opacity-90 active:scale-[.98] transition"
                   style="background:linear-gradient(135deg,{{ $hasEmployment ? '#2563eb,#3b82f6' : '#7a3f91,#9b59b6' }});">
                    <i class="fa-solid fa-{{ $hasEmployment ? 'pen' : 'plus' }} text-sm"></i>
                    {{ $hasEmployment ? 'Update Employment' : 'Add Employment Info' }}
                </a>
            </div>
        </div>

    </div>

    {{-- ══ UPCOMING EVENTS & LATEST JOBS ════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- UPCOMING EVENTS --}}
        <div class="bg-white rounded-2xl border border-purple-100 shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#f9f5ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <i class="fa-solid fa-calendar-check text-white" style="font-size:12px;"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Upcoming Events</p>
                        <p class="text-xs text-gray-400">Next events for your college</p>
                    </div>
                </div>
                @if($upcomingEvents > 0)
                    <a href="{{ route('upcoming.events') }}"
                       class="text-xs font-bold flex items-center gap-1 hover:underline"
                       style="color:#7a3f91;">
                        View All <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                @endif
            </div>

            <div class="p-4 space-y-3 flex-1 flex flex-col">
                <div class="flex-1 space-y-3">
                    @forelse($recentEvents as $evt)
                    <div class="flex items-start gap-3 border border-gray-100 rounded-2xl p-3 hover:border-purple-200 hover:bg-[#faf7ff] transition-all duration-150">
                        <div class="w-10 h-10 rounded-xl flex-shrink-0 overflow-hidden"
                             style="background:#f0e6f8;">
                            @if($evt['photo'])
                                <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fa-solid fa-calendar-days text-base" style="color:#7a3f91;"></i>
                                </div>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 leading-snug truncate">{{ $evt['title'] }}</p>
                            <div class="flex flex-wrap items-center gap-x-3 mt-1">
                                <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                    <i class="fa-solid fa-calendar text-xs"></i> {{ $evt['date'] }}
                                </span>
                                @if($evt['venue'])
                                    <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                        <i class="fa-solid fa-location-dot text-xs"></i> {{ Str::limit($evt['venue'], 28) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 flex-shrink-0 uppercase">
                            <i class="fa-solid fa-clock text-xs"></i> Soon
                        </span>
                    </div>
                    @empty
                    <div class="py-10 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f0e6f8;">
                            <i class="fa-solid fa-calendar-days text-3xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-400">No upcoming events right now.</p>
                        <p class="text-xs text-gray-300 mt-1">Check back soon for new events.</p>
                    </div>
                    @endforelse
                </div>

                <a href="{{ route('upcoming.events') }}"
                   class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold border border-purple-200 transition-all duration-150 flex-shrink-0"
                   style="color:#7a3f91; background:#faf7ff;"
                   onmouseover="this.style.background='linear-gradient(135deg,#7a3f91,#9b59b6)'; this.style.color='#fff'; this.style.borderColor='transparent';"
                   onmouseout="this.style.background='#faf7ff'; this.style.color='#7a3f91'; this.style.borderColor='#e9d5ff';">
                    <i class="fa-solid fa-eye text-sm"></i> View All Events
                </a>
            </div>
        </div>

        {{-- LATEST JOBS --}}
        <div class="bg-white rounded-2xl border border-blue-100 shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#eff6ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                        <i class="fa-solid fa-briefcase text-white" style="font-size:12px;"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Latest Job Openings</p>
                        <p class="text-xs text-gray-400">Active postings for your college</p>
                    </div>
                </div>
                @if($activeJobs > 0)
                    <a href="{{ route('job.opportunities') }}"
                       class="text-xs font-bold text-blue-600 flex items-center gap-1 hover:underline">
                        View All <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                @endif
            </div>

            <div class="p-4 space-y-3 flex-1 flex flex-col">
                <div class="flex-1 space-y-3">
                    @forelse($recentJobs as $job)
                    @php
                        $dlParsed = \Carbon\Carbon::createFromFormat('M d, Y', $job['deadline']);
                        $daysLeft = (int) now('Asia/Manila')->startOfDay()->diffInDays($dlParsed->copy()->startOfDay(), false);
                        $isUrgent = $daysLeft <= 7;
                    @endphp
                    <div class="border border-gray-100 rounded-2xl p-3 hover:border-blue-200 hover:bg-[#f0f7ff] transition-all duration-150">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">{{ $job['title'] }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $job['company'] }}</p>
                            </div>
                            <span class="inline-flex items-center text-xs font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100 flex-shrink-0 uppercase">
                                {{ $job['type'] }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            @if($job['location'])
                                <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                    <i class="fa-solid fa-location-dot text-xs"></i> {{ Str::limit($job['location'], 22) }}
                                </span>
                            @endif
                            @if($job['salary'])
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                                    <i class="fa-solid fa-money-bill-wave text-xs"></i> {{ Str::limit($job['salary'], 20) }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-gray-400' }}">
                                <i class="fa-solid fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs"></i>
                                Closes {{ $job['deadline'] }}
                            </span>
                        </div>
                    </div>
                    @empty
                    <div class="py-10 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3 bg-blue-50">
                            <i class="fa-solid fa-briefcase text-3xl text-blue-300"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-400">No active job postings right now.</p>
                        <p class="text-xs text-gray-300 mt-1">New listings will appear here.</p>
                    </div>
                    @endforelse
                </div>

                <a href="{{ route('job.opportunities') }}"
                   class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold border border-blue-200 transition-all duration-150 flex-shrink-0"
                   style="color:#2563eb; background:#eff6ff;"
                   onmouseover="this.style.background='linear-gradient(135deg,#2563eb,#3b82f6)'; this.style.color='#fff'; this.style.borderColor='transparent';"
                   onmouseout="this.style.background='#eff6ff'; this.style.color='#2563eb'; this.style.borderColor='#bfdbfe';">
                    <i class="fa-solid fa-eye text-sm"></i> View All Jobs
                </a>
            </div>
        </div>

    </div>

</div>