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

<div class="space-y-5">

    {{-- ══ MERGED BANNER + HERO ══════════════════════════════════════════════ --}}
    <div class="rounded-2xl overflow-hidden" style="background:#7a3f91;">

        {{-- Top strip: Portal name + tagline + badge --}}
        <div class="px-6 pt-5 pb-4 flex flex-col sm:flex-row sm:items-center gap-4 border-b"
             style="border-color:rgba(255,255,255,.12);">

            {{-- Logo icon + portal name --}}
            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center"
                     style="background:rgba(255,255,255,.15); border:1.5px solid rgba(255,255,255,.25);">
                    <i class="fa-solid fa-graduation-cap text-white text-lg"></i>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-widest uppercase text-white/50">Welcome to</p>
                    <p class="text-base font-black text-white leading-tight tracking-tight">PHILCST Alumni Portal</p>
                </div>
            </div>

            {{-- Vertical divider --}}
            <div class="hidden sm:block w-px h-9 self-center flex-shrink-0"
                 style="background:rgba(255,255,255,.2);"></div>

            {{-- Tagline --}}
            <div class="flex-1 min-w-0">
                <p class="text-white font-extrabold text-sm sm:text-base leading-snug">
                    Your success is our legacy. 🎓
                </p>
                <p class="text-white/55 text-xs mt-0.5 leading-snug">
                    Stay connected · Update your employment · Join upcoming events · Inspire future graduates
                </p>
            </div>

            {{-- Right badge --}}
            <div class="flex-shrink-0 flex items-center gap-2 text-xs font-bold px-4 py-2.5 rounded-xl text-white"
                 style="background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22);">
                <i class="fa-solid fa-star text-yellow-300"></i>
                <span>Alumni Network</span>
            </div>
        </div>

        {{-- Bottom section: greeting + progress --}}
        <div class="px-6 pt-5 pb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl flex-shrink-0 flex items-center justify-center text-xl font-black text-white"
                     style="background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.28);">
                    {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                </div>
                <div>
                    <p class="text-white/60 text-xs font-bold tracking-widest uppercase">
                        {{ $this->getGreeting() }}
                    </p>
                    <h1 class="text-2xl sm:text-[1.75rem] font-black text-white tracking-tight leading-tight mt-0.5">
                        {{ $alumniName ?: 'Alumni' }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        @if($alumniStudentId)
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg"
                                  style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.9);">
                                <i class="fa-solid fa-id-badge text-[9px]"></i> {{ $alumniStudentId }}
                            </span>
                        @endif
                        @if($alumniCourseCode)
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg"
                                  style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.9);">
                                <i class="fa-solid fa-graduation-cap text-[9px]"></i> {{ $alumniCourseCode }}
                            </span>
                        @endif
                        @if($alumniBatch)
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg"
                                  style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.9);">
                                <i class="fa-solid fa-calendar text-[9px]"></i> Batch {{ $alumniBatch }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex-shrink-0">
                @if($profileComplete && $hasEmployment)
                    <span class="inline-flex items-center gap-2 text-sm font-bold px-4 py-2.5 rounded-xl text-white"
                          style="background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);">
                        <i class="fa-solid fa-circle-check text-emerald-300"></i> All Set
                    </span>
                @elseif(!$profileComplete)
                    <span class="inline-flex items-center gap-2 text-sm font-bold px-4 py-2.5 rounded-xl text-white"
                          style="background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);">
                        <i class="fa-solid fa-triangle-exclamation text-yellow-300"></i> Profile Incomplete
                    </span>
                @else
                    <span class="inline-flex items-center gap-2 text-sm font-bold px-4 py-2.5 rounded-xl text-white"
                          style="background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);">
                        <i class="fa-solid fa-briefcase text-sky-300"></i> Add Employment
                    </span>
                @endif
            </div>
        </div>

        {{-- Progress bar --}}
        <div class="px-6 pb-5 pt-1">
            @php
                $steps   = ($profileComplete ? 1 : 0) + ($hasEmployment ? 1 : 0);
                $pct     = ($steps / 2) * 100;
                $stepTxt = match($steps) {
                    0 => 'Start by completing your profile information',
                    1 => $profileComplete
                            ? 'Profile done — now submit your employment info'
                            : 'Profile info saved — complete all required fields',
                    2 => 'You\'re all set! Both steps completed 🎉',
                };
            @endphp
            <div class="flex items-center justify-between mb-1.5">
                <p class="text-white/60 text-[11px] font-semibold">Profile Setup — {{ $steps }}/2 steps</p>
                <p class="text-white text-[11px] font-extrabold">{{ (int)$pct }}%</p>
            </div>
            <div class="h-1.5 rounded-full overflow-hidden" style="background:rgba(255,255,255,.18);">
                <div class="h-full rounded-full bg-white transition-all duration-500"
                     style="width:{{ $pct }}%;"></div>
            </div>
            <p class="text-white/40 text-[10px] mt-1.5">{{ $stepTxt }}</p>
        </div>
    </div>


    {{-- ══ TIP BANNER ════════════════════════════════════════════════════════ --}}
    @if(!$profileComplete || !$hasEmployment)
    <div class="flex items-start gap-3 rounded-2xl px-4 py-3 border border-purple-200 bg-purple-50">
        <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center" style="background:#7a3f91;">
            <i class="fa-solid fa-lightbulb text-yellow-300 text-sm"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-purple-900">
                @if(!$profileComplete)
                    Complete your profile to unlock all portal features.
                @else
                    One more step — submit your employment information.
                @endif
            </p>
            <p class="text-xs mt-0.5 leading-snug text-purple-700">
                @if(!$profileComplete)
                    Fill in your personal info under <strong>My Profile</strong> to keep alumni records accurate.
                @else
                    Help PHILCST track graduate outcomes. Go to <strong>Employment Tracking</strong>.
                @endif
            </p>
        </div>
        <a href="{{ !$profileComplete ? route('alumni.information') : route('alumni.employment') }}"
           class="flex-shrink-0 text-xs font-bold px-3 py-2 rounded-lg text-white transition hover:opacity-90"
           style="background:#7a3f91;">
            Go <i class="fa-solid fa-arrow-right ml-1 text-[10px]"></i>
        </a>
    </div>
    @endif


    {{-- ══ STAT CARDS ════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">

        <div class="bg-white border border-gray-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
            <div class="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center bg-purple-50">
                <i class="fa-solid fa-calendar-check" style="color:#7a3f91;"></i>
            </div>
            <div>
                <div class="text-2xl font-black leading-none text-gray-900">{{ $upcomingEvents }}</div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">Upcoming<br>Events</div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
            <div class="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center bg-purple-50">
                <i class="fa-solid fa-calendar-days" style="color:#7a3f91;"></i>
            </div>
            <div>
                <div class="text-2xl font-black leading-none text-gray-900">{{ $totalEvents }}</div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">Total<br>Events</div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
            <div class="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center bg-blue-50">
                <i class="fa-solid fa-briefcase text-blue-600"></i>
            </div>
            <div>
                <div class="text-2xl font-black leading-none text-gray-900">{{ $activeJobs }}</div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">Active<br>Job Posts</div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
            <div class="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center bg-emerald-50">
                <i class="fa-solid fa-circle-check text-emerald-600"></i>
            </div>
            <div>
                <div class="text-2xl font-black leading-none text-gray-900">{{ $myRsvps }}</div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-0.5">My<br>RSVPs</div>
            </div>
        </div>

    </div>


    {{-- ══ MAIN CONTENT ══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- LEFT: Profile + Employment --}}
        <div class="flex flex-col gap-5">

            {{-- Profile Card --}}
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-100 bg-purple-50">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#7a3f91;">
                        <i class="fa-solid fa-user text-white text-xs"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-900">My Profile</p>
                        <p class="text-xs text-gray-500">Personal information</p>
                    </div>
                    @if($profileComplete)
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">
                            <i class="fa-solid fa-circle-check text-[9px]"></i> Complete
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-amber-700 bg-amber-50 border-amber-200">
                            <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> Incomplete
                        </span>
                    @endif
                </div>
                <div class="p-4 space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl flex-shrink-0 flex items-center justify-center text-base font-black text-white"
                             style="background:#7a3f91;">
                            {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                        </div>
                        <div class="min-w-0">
                            <p class="font-extrabold text-gray-900 text-sm leading-snug truncate uppercase">
                                {{ $alumniName ?: '—' }}
                            </p>
                            <p class="text-xs text-gray-500">{{ $alumniStudentId ?: 'No student ID' }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="bg-gray-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">Course</p>
                            <p class="text-sm font-bold text-gray-800 truncate uppercase">{{ $alumniCourseCode ?: '—' }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">Batch</p>
                            <p class="text-sm font-bold text-gray-800">{{ $alumniBatch ?: '—' }}</p>
                        </div>
                    </div>

                    @if($alumniCollege)
                    <div class="bg-gray-50 rounded-xl px-3 py-2.5">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">College</p>
                        <p class="text-xs font-semibold text-gray-700 uppercase leading-snug">{{ $alumniCollege }}</p>
                    </div>
                    @endif

                    <a href="{{ route('alumni.information') }}"
                       class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white hover:opacity-90 active:scale-[.98] transition"
                       style="background:#7a3f91;">
                        <i class="fa-solid fa-pen text-xs"></i>
                        {{ $profileComplete ? 'View / Edit Profile' : 'Complete My Profile' }}
                    </a>
                </div>
            </div>

            {{-- Employment Card --}}
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-100 bg-purple-50">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-blue-600">
                        <i class="fa-solid fa-briefcase text-white text-xs"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-900">Employment</p>
                        <p class="text-xs text-gray-500">Current work status</p>
                    </div>
                    @if($hasEmployment)
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">
                            <i class="fa-solid fa-circle-check text-[9px]"></i> Submitted
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-amber-700 bg-amber-50 border-amber-200">
                            <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> No Record
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
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border {{ $s[2] }} {{ $s[3] }}">
                            <i class="fa-solid {{ $s[1] }} text-[10px]"></i> {{ $s[0] }}
                        </span>

                        @if($jobTitle || $companyName)
                        <div class="bg-gray-50 rounded-xl p-3 space-y-1.5">
                            @if($jobTitle)
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Position</p>
                                <p class="text-sm font-bold text-gray-800 uppercase">{{ $jobTitle }}</p>
                            </div>
                            @endif
                            @if($companyName)
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Company</p>
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
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border {{ $e[2] }} {{ $e[3] }}">
                                <i class="fa-solid {{ $e[1] }} text-[10px]"></i> {{ $e[0] }}
                            </span>
                        @endif

                    @else
                        <div class="text-center py-6 text-gray-400 text-sm font-medium">
                            <i class="fa-solid fa-briefcase block text-2xl mb-2 text-gray-300"></i>
                            No employment record yet.
                        </div>
                    @endif

                    <a href="{{ route('alumni.employment') }}"
                       class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold text-white hover:opacity-90 active:scale-[.98] transition"
                       style="background:{{ $hasEmployment ? '#2563eb' : '#7a3f91' }};">
                        <i class="fa-solid fa-{{ $hasEmployment ? 'pen' : 'plus' }} text-xs"></i>
                        {{ $hasEmployment ? 'Update Employment' : 'Add Employment Info' }}
                    </a>
                </div>
            </div>

        </div>

        {{-- RIGHT: Events + Jobs --}}
        <div class="flex flex-col gap-5 lg:col-span-2">

            {{-- Upcoming Events --}}
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-100 bg-purple-50">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#7a3f91;">
                        <i class="fa-solid fa-calendar-check text-white text-xs"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-900">Upcoming Events</p>
                        <p class="text-xs text-gray-500">Next events for your college</p>
                    </div>
                    @if($upcomingEvents > 0)
                        <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-lg text-white" style="background:#7a3f91;">
                            {{ $upcomingEvents }} total
                        </span>
                    @endif
                </div>
                <div class="p-4 space-y-3">
                    @forelse($recentEvents as $evt)
                    <div class="flex items-start gap-3 border border-gray-200 rounded-2xl p-3 hover:border-purple-400 hover:shadow-sm transition-all duration-150">
                        <div class="w-11 h-11 rounded-xl flex-shrink-0 overflow-hidden bg-purple-50">
                            @if($evt['photo'])
                                <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fa-solid fa-calendar-days text-sm" style="color:#7a3f91;"></i>
                                </div>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 leading-snug truncate">{{ $evt['title'] }}</p>
                            <div class="flex flex-wrap items-center gap-x-3 mt-1">
                                <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                    <i class="fa-solid fa-calendar text-[9px]"></i> {{ $evt['date'] }}
                                </span>
                                @if($evt['venue'])
                                    <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                        <i class="fa-solid fa-location-dot text-[9px]"></i> {{ Str::limit($evt['venue'], 30) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200 flex-shrink-0 mt-0.5">
                            <i class="fa-solid fa-clock text-[8px]"></i> Soon
                        </span>
                    </div>
                    @empty
                        <div class="text-center py-6 text-gray-400 text-sm font-medium">
                            <i class="fa-solid fa-calendar-days block text-2xl mb-2 text-gray-300"></i>
                            No upcoming events right now.
                        </div>
                    @endforelse

                    <a href="{{ route('upcoming.events') }}"
                       class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold border-2 border-gray-200 bg-white text-gray-700 transition-all duration-150"
                       style="text-decoration:none;"
                       onmouseover="this.style.background='#7a3f91'; this.style.borderColor='#7a3f91'; this.style.color='#fff';"
                       onmouseout="this.style.background='#fff'; this.style.borderColor='#e5e7eb'; this.style.color='#374151';">
                        <i class="fa-solid fa-eye text-xs"></i> View All Events
                    </a>
                </div>
            </div>

            {{-- Latest Jobs --}}
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-100 bg-purple-50">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-blue-600">
                        <i class="fa-solid fa-briefcase text-white text-xs"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-900">Latest Job Openings</p>
                        <p class="text-xs text-gray-500">Active postings for your college</p>
                    </div>
                    @if($activeJobs > 0)
                        <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-lg bg-blue-600 text-white">
                            {{ $activeJobs }} active
                        </span>
                    @endif
                </div>
                <div class="p-4 space-y-3">
                    @forelse($recentJobs as $job)
                    @php
                        $dlParsed = \Carbon\Carbon::createFromFormat('M d, Y', $job['deadline']);
                        $daysLeft = (int) now('Asia/Manila')->startOfDay()->diffInDays($dlParsed->copy()->startOfDay(), false);
                        $isUrgent = $daysLeft <= 7;
                    @endphp
                    <div class="border border-gray-200 rounded-2xl p-3 hover:border-blue-400 hover:shadow-sm transition-all duration-150">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">{{ $job['title'] }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $job['company'] }}</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border text-blue-700 bg-blue-50 border-blue-100 flex-shrink-0">
                                {{ $job['type'] }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            @if($job['location'])
                                <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                    <i class="fa-solid fa-location-dot text-[9px]"></i> {{ Str::limit($job['location'], 24) }}
                                </span>
                            @endif
                            @if($job['salary'])
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                                    <i class="fa-solid fa-money-bill-wave text-[9px]"></i> {{ Str::limit($job['salary'], 22) }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 text-[11px] {{ $isUrgent ? 'text-red-600 font-bold' : 'text-gray-400' }}">
                                <i class="fa-solid fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-[9px]"></i>
                                Closes {{ $job['deadline'] }}
                            </span>
                        </div>
                    </div>
                    @empty
                        <div class="text-center py-6 text-gray-400 text-sm font-medium">
                            <i class="fa-solid fa-briefcase block text-2xl mb-2 text-gray-300"></i>
                            No active job postings right now.
                        </div>
                    @endforelse

                    <a href="{{ route('job.opportunities') }}"
                       class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-bold border-2 border-gray-200 bg-white text-gray-700 transition-all duration-150"
                       style="text-decoration:none;"
                       onmouseover="this.style.background='#2563eb'; this.style.borderColor='#2563eb'; this.style.color='#fff';"
                       onmouseout="this.style.background='#fff'; this.style.borderColor='#e5e7eb'; this.style.color='#374151';">
                        <i class="fa-solid fa-eye text-xs"></i> View All Jobs
                    </a>
                </div>
            </div>

        </div>
    </div>

</div>