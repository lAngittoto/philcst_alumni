{{-- resources/views/livewire/alumni/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use App\Models\Alumni;
use App\Models\AdminEvent;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

new class extends Component {

    public string $alumniName       = '';
    public string $alumniFirstName  = '';
    public string $alumniLastName   = '';
    public string $alumniCourse     = '';
    public string $alumniCourseCode = '';
    public string $alumniCourseFull = '';
    public string $alumniCollege    = '';
    public string $alumniStudentId  = '';
    public string $alumniBatch      = '';
    public string $alumniPhoto      = '';
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

    // ── Modal state ──
    public string $activeModal       = '';
    public array  $selectedEmployment = [];

    public function getProfilePhotoUrl(): string
    {
        $path = $this->alumniPhoto;
        if (!$path || str_contains($path, 'default.png')) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        }
        return asset('storage/alumni-photos/default.png');
    }

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
        $this->alumniPhoto      = $alumni->profile_photo ?? '';

        $courseRecord = Cache::remember(
            'alumni_course_record_' . $this->alumniCourseCode, 600,
            fn() => Course::where('code', $this->alumniCourseCode)->first()
        );
        $this->alumniCollege    = $courseRecord?->college ?? '';
        $this->alumniCourseFull = $courseRecord?->name   ?? '';

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
        $nowUtc  = Carbon::now('UTC');

        $this->upcomingEvents = Cache::remember("dash_upcoming_{$college}", 120, function () use ($college, $course, $nowUtc) {
            $admin = AdminEvent::withoutTrashed()
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->where(function ($q) use ($nowUtc) {
                    $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $nowUtc))
                      ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '>', $nowUtc));
                })
                ->count();

            $org = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                    ->orWhere('target_participants', 'like', "%{$course}%"))
                ->where(function ($q) use ($nowUtc) {
                    $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $nowUtc))
                      ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '>', $nowUtc));
                })
                ->count();

            return $admin + $org;
        });

        $this->totalEvents = Cache::remember("dash_total_{$college}", 120, function () use ($college, $course) {
            $admin = AdminEvent::withoutTrashed()
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->count();
            $org = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
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
    }

    // ── Clean-URL navigation: flash filter into session, redirect to clean URL ──

    // ✅ navigate: true — SPA nav via wire:navigate (same mechanism the
    //    sidebar links already use). Without this, these were doing a
    //    hard full-page redirect while the sidebar itself is wire:navigate,
    //    so clicking "Upcoming"/"Events"/"Jobs" tore down and rebuilt the
    //    ENTIRE page (sidebar included) instead of morphing just the
    //    content — that mismatch is what read as the sidebar "glitching"
    //    on click, especially the mobile drawer flashing/resetting mid-nav.
    public function goToUpcomingEvents(): void
    {
        session()->put('events_filter', 'upcoming');
        $this->redirect(route('upcoming.events'), navigate: true);
    }

    public function goToAllEvents(): void
    {
        session()->put('events_filter', 'all');
        $this->redirect(route('upcoming.events'), navigate: true);
    }

    public function goToJobs(): void
    {
        $this->redirect(route('job.opportunities'), navigate: true);
    }

    // ── Sends the alumni straight into the Update Employment editor on the
    //    Alumni Information page instead of just landing on the page. ──
    public function goToUpdateEmployment(): void
    {
        session()->put('open_employment', true);
        $this->redirect(route('alumni.information'), navigate: true);
    }

    // ── Employment modal ──────────────────────────────────────────
    public function openEmploymentModal(): void
    {
        if (!$this->hasEmployment) {
            $this->redirect(route('alumni.information'), navigate: true);
            return;
        }

        $employment = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if (!$employment) { $this->activeModal = ''; return; }

        $sMap = [
            'employed'      => ['Employed',      'fa-user-tie',         '#16a34a', '#F0FDF4', '#BBF7D0'],
            'self_employed' => ['Self-Employed',  'fa-store',            '#0891b2', '#ECFEFF', '#a5f3fc'],
            'unemployed'    => ['Unemployed',     'fa-magnifying-glass', '#d97706', '#FFFBEB', '#fde68a'],
        ];
        $empInfo = $sMap[$employment->employment_status ?? ''] ?? ['—', 'fa-briefcase', '#333333', '#F9F7FC', '#E8E0F0'];

        $eMap = [
            'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',     '#333333', '#EDE0F5', '#c9ace0'],
            'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard', '#333333', '#F9F7FC', '#E8E0F0'],
        ];
        $eduInfo = $eMap[$employment->education_status ?? ''] ?? null;

        $this->selectedEmployment = [
            'employment_status'       => $employment->employment_status     ?? '',
            'status_label'            => $empInfo[0],
            'status_icon'             => $empInfo[1],
            'status_color'            => $empInfo[2],
            'status_bg'               => $empInfo[3],
            'status_border'           => $empInfo[4],
            'job_title'               => $employment->job_title             ?? '',
            'company_name'            => $employment->company_name          ?? '',
            'company_address'         => $employment->company_address       ?? '',
            'industry'                => $employment->industry              ?? '',
            'employment_type'         => $employment->employment_type       ?? '',
            'monthly_salary'          => $employment->monthly_salary        ?? '',
            'date_hired'              => $employment->date_hired
                                            ? Carbon::parse($employment->date_hired)->setTimezone('Asia/Manila')->format('F d, Y')
                                            : '',
            'date_hired_ago'          => $employment->date_hired
                                            ? Carbon::parse($employment->date_hired)->setTimezone('Asia/Manila')->diffForHumans()
                                            : '',
            'skills'                  => $employment->skills               ?? '',
            'education_status'        => $employment->education_status      ?? '',
            'edu_label'               => $eduInfo ? $eduInfo[0] : '',
            'edu_icon'                => $eduInfo ? $eduInfo[1] : '',
            'edu_color'               => $eduInfo ? $eduInfo[2] : '',
            'abroad'                  => $employment->is_abroad ?? false,
            'country'                 => $employment->country               ?? '',
            'linkedin_url'            => $employment->linkedin_url          ?? '',
            'remarks'                 => $employment->remarks               ?? '',
            'updated_at'              => $employment->updated_at
                                            ? Carbon::parse($employment->updated_at)->setTimezone('Asia/Manila')->format('M d, Y \a\t g:i A')
                                            : '',
            'updated_ago'             => $employment->updated_at
                                            ? Carbon::parse($employment->updated_at)->setTimezone('Asia/Manila')->diffForHumans()
                                            : '',
        ];

        $this->activeModal = 'employment_detail';
    }

    public function openProfileModal(): void
    {
        $this->activeModal = 'profile_detail';
    }

    public function closeModal(): void { $this->activeModal = ''; }

    public function getGreeting(): string
    {
        $h = (int) Carbon::now('Asia/Manila')->format('H');
        if ($h < 12) return 'Good morning';
        if ($h < 17) return 'Good afternoon';
        return 'Good evening';
    }
}; ?>

<div>

<style>
    /* ── Animations ── */
    @keyframes dashPageIn  { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes dashModalIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .dash-modal-enter { animation: dashModalIn .22s cubic-bezier(.4,0,.2,1) both; }
    @keyframes slideInFull { from { opacity:0; } to { opacity:1; } }
    .fs-in, .emp-detail-in, .id-card-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }

    /* ── STAT CARD TOOLTIP (appears ABOVE, desktop only) ── */
    .dash-stat-card {
        position: relative;
        overflow: visible;
    }
    .dash-stat-card .stat-tooltip {
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: #000000;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.05em;
        padding: 5px 11px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s;
        z-index: 30;
    }
    .dash-stat-card .stat-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 5px solid transparent;
        border-top-color: #000000;
    }
    @media (min-width: 1024px) {
        .dash-stat-card:hover .stat-tooltip { opacity: 1; }
    }
    @media (max-width: 1023px) {
        .dash-stat-card .stat-tooltip { display: none; }
    }

    /* ── Close button tooltip — BOTTOM ── */
    .close-btn-wrap { position: relative; }
    .close-btn-wrap .close-tooltip {
        position: absolute;
        top: calc(100% + 7px);
        left: 50%;
        transform: translateX(-50%);
        background: #000000;
        color: #ffffff;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        padding: 4px 10px;
        border-radius: 6px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s;
        z-index: 9999;
    }
    .close-btn-wrap .close-tooltip::after {
        content: '';
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 4px solid transparent;
        border-bottom-color: #000000;
    }
    @media (min-width: 1024px) {
        .close-btn-wrap:hover .close-tooltip { opacity: 1; }
    }

    /* ── Equal-height main grid ── */
    .dash-main-grid {
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 1rem;
        align-items: stretch;
    }
    @media (max-width: 1023px) {
        .dash-main-grid {
            grid-template-columns: 1fr;
            gap: 0.85rem;
        }
    }

    /* ── Profile card fills height ── */
    .dash-profile-col {
        display: flex;
        flex-direction: column;
    }
    .dash-profile-card {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    /* ── Stat grid: 2×2 equal height on desktop, 1-col on phone ── */
    .dash-stat-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: 1fr 1fr;
        gap: 0.75rem;
        height: 100%;
    }
    .dash-stat-grid .dash-stat-card {
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    @media (max-width: 639px) {
        .dash-stat-grid {
            grid-template-columns: 1fr;
            grid-template-rows: none;
            gap: 0.65rem;
        }
        .dash-stat-grid .dash-stat-card {
            padding: 1rem !important;
        }
        .dash-stat-grid .dash-stat-card .stat-big-num {
            font-size: 2.1rem !important;
        }
    }
    @media (min-width: 640px) and (max-width: 1023px) {
        .dash-stat-grid .dash-stat-card .stat-big-num {
            font-size: 2.4rem !important;
        }
    }
</style>

{{-- ═══ DASHBOARD ROOT ════════════════════════════════════════════ --}}
<div class="px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex items-center gap-4 mb-5 flex-wrap">
        <div class="w-11 h-11 rounded-2xl flex items-center justify-center shadow-md shrink-0 bg-[#7A3F91]">
            <i class="fas fa-graduation-cap text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-gray-900">Alumni Dashboard</h1>
            <p class="text-sm leading-relaxed mt-0.5 text-gray-700">{{ now()->format('l, F j, Y') }}</p>
        </div>

        {{-- Only show this banner for an incomplete PROFILE.
             Employment reminder is no longer shown here — the Employment
             stat card below already covers that ("No Record / Add record now"). --}}
        @if(!$profileComplete)
        <div class="sm:ml-auto flex items-center gap-2.5 px-3 py-2 rounded-xl border text-xs font-semibold bg-[#F9F7FC] border-[#d9c9e8] text-[#111111] w-full sm:w-auto">
            <i class="fas fa-triangle-exclamation text-sm text-[#7A3F91] shrink-0"></i>
            <span class="flex-1">Complete your profile</span>
            <a href="{{ route('alumni.information') }}"
               class="px-2.5 py-1 rounded-lg text-white text-xs font-semibold transition hover:opacity-90 bg-[#7A3F91] shrink-0">
                Go <i class="fas fa-arrow-right text-xs ml-0.5"></i>
            </a>
        </div>
        @endif
    </div>

    {{-- ═══ MAIN GRID ══════════════════════════════════════════════ --}}
    @php $photoUrl = $this->getProfilePhotoUrl(); @endphp

    <div class="dash-main-grid">

        {{-- ══ LEFT: Profile Card ═══ --}}
        <div class="dash-profile-col">
            <div class="dash-profile-card rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm bg-white">

                {{-- Photo banner --}}
                <div class="relative w-full overflow-hidden shrink-0 h-[300px] sm:h-[220px] bg-[#EDE0F5]">
                    <img src="{{ $photoUrl }}"
                         alt="{{ $alumniFirstName }}"
                         class="w-full h-full object-cover object-top"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-full h-full items-center justify-center font-black text-white hidden text-[5rem] bg-[#7A3F91]" style="display:none;">
                        {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                    </div>
                    <div class="absolute inset-0" style="background:linear-gradient(to bottom, transparent 35%, rgba(0,0,0,.65) 100%);"></div>
                    <div class="absolute bottom-0 left-0 right-0 px-4 pb-4">
                        <p class="text-white font-bold uppercase leading-tight tracking-wide text-[1.1rem] sm:text-[1.15rem]"
                           style="text-shadow:0 1px 5px rgba(0,0,0,.6);">
                            {{ $alumniName ?: '—' }}
                        </p>
                        <p class="font-mono text-[0.78rem] sm:text-[0.8rem]" style="color:rgba(255,255,255,.65);">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                </div>

                <div class="px-4 py-3 flex flex-col divide-y divide-[#F3F4F6] flex-1">

                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <span class="text-[0.7rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0">Student ID</span>
                        <span class="text-[0.83rem] font-semibold font-mono text-gray-900">{{ $alumniStudentId ?: '—' }}</span>
                    </div>

                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <span class="text-[0.7rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0">Program</span>
                        <span class="text-[0.88rem] font-bold font-mono text-gray-900">{{ $alumniCourseCode ?: '—' }}</span>
                    </div>

                    @if($alumniCourseFull)
                    <div class="py-2.5 flex items-center justify-center">
                        <span class="text-[0.82rem] font-semibold text-center text-gray-900 leading-snug">{{ $alumniCourseFull }}</span>
                    </div>
                    @endif

                    @if($alumniBatch)
                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <span class="text-[0.7rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0">Batch</span>
                        <span class="text-[0.88rem] font-semibold text-gray-900">{{ $alumniBatch }}</span>
                    </div>
                    @endif

                    @if($alumniCollege)
                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <span class="text-[0.7rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0">College</span>
                        <span class="text-[0.82rem] font-semibold text-right uppercase text-gray-900 leading-snug" style="max-width:170px;">{{ $alumniCollege }}</span>
                    </div>
                    @endif

                    @if($profileComplete)
                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <span class="text-[0.7rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0">Profile</span>
                        <span class="inline-flex items-center gap-1 text-[0.75rem] font-bold px-2.5 py-1 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">
                            <i class="fas fa-circle-check text-[10px]"></i> Complete
                        </span>
                    </div>
                    @endif

                    <div class="flex-1"></div>

                </div>
            </div>
        </div>

        {{-- ══ RIGHT: Stat Cards — 2×2 grid (1-col on phone) ══════════════════════ --}}
        <div class="dash-stat-grid">

            {{-- Card 1: Upcoming Events --}}
            <button wire:click="goToUpcomingEvents"
               class="dash-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-blue-300 transition-all duration-200 active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View Upcoming Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-calendar-check text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700 border border-blue-200 bg-blue-50 text-[0.7rem] sm:text-[0.75rem]">Upcoming</span>
                </div>
                <p class="stat-big-num text-gray-900 font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ $upcomingEvents }}</p>
                <p class="text-gray-900 font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Upcoming Events</p>
            </button>

            {{-- Card 2: Total Events --}}
            <button wire:click="goToAllEvents"
               class="dash-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-green-300 transition-all duration-200 active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View Total Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700 border border-emerald-200 bg-emerald-50 text-[0.7rem] sm:text-[0.75rem]">Total</span>
                </div>
                <p class="stat-big-num text-gray-900 font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ $totalEvents }}</p>
                <p class="text-gray-900 font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Total Events</p>
            </button>

            {{-- Card 3: Active Jobs --}}
            <button wire:click="goToJobs"
               class="dash-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-amber-300 transition-all duration-200 active:scale-[.985] text-left cursor-pointer w-full">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>View Job Opportunities</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-amber-600">
                        <i class="fas fa-briefcase text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700 border border-amber-200 bg-amber-50 text-[0.7rem] sm:text-[0.75rem]">Jobs</span>
                </div>
                <p class="stat-big-num text-gray-900 font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ $activeJobs }}</p>
                <p class="text-gray-900 font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Active Job Posts</p>
            </button>

            {{-- Card 4: Employment — now goes DIRECTLY into the Update Employment
                 editor on the Alumni Information page (auto-opens via session flag). --}}
            @php
                $empCardMap = [
                    'employed'      => ['Employed',      'fa-user-tie',         '#7A3F91', '#F9F7FC', '#E8E0F0'],
                    'self_employed' => ['Self-Employed',  'fa-store',            '#5c2d7a', '#EDE0F5', '#c9ace0'],
                    'unemployed'    => ['Unemployed',     'fa-magnifying-glass', '#9b59b6', '#F5EDF9', '#dbbcef'],
                ];
                $empCard = $empCardMap[$employmentStatus] ?? null;
            @endphp
            <button type="button" wire:click="goToUpdateEmployment"
               class="dash-stat-card bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      transition-all duration-200 active:scale-[.985] text-left cursor-pointer w-full
                      {{ $hasEmployment ? 'hover:shadow-md hover:border-[#7A3F91]/40' : 'hover:shadow-md hover:border-red-300' }}">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1.5"></i>{{ $hasEmployment ? 'Update Employment Status' : 'Add Employment Record' }}</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow shrink-0"
                         style="background:{{ $hasEmployment ? ($empCard ? $empCard[2] : '#7A3F91') : '#e11d48' }};">
                        <i class="fas {{ $hasEmployment ? ($empCard ? $empCard[1] : 'fa-briefcase') : 'fa-triangle-exclamation' }} text-white text-base sm:text-lg"></i>
                    </div>
                    <i class="fas fa-chevron-right text-gray-700 opacity-40 text-base mt-1"></i>
                </div>
                @if($hasEmployment && $empCard)
                    <p class="stat-big-num font-extrabold text-gray-900 text-[2.6rem] sm:text-[3rem] leading-none tracking-tight truncate">{{ $empCard[0] }}</p>
                    <p class="font-semibold mt-2 text-gray-700 text-[0.98rem] sm:text-[1.05rem]">Employment Status</p>
                    @if($jobTitle)
                        <p class="font-semibold mt-1 truncate uppercase text-[0.8rem] sm:text-[0.82rem] text-gray-700">
                            <i class="fas fa-id-badge mr-1 text-[0.65rem]"></i>{{ $jobTitle }}
                            @if($companyName) · {{ $companyName }} @endif
                        </p>
                    @endif
                @else
                    <p class="stat-big-num font-extrabold leading-none text-red-600 text-[2.6rem] sm:text-[3rem] tracking-tight">No Record</p>
                    <p class="font-semibold mt-2 text-gray-700 text-[0.98rem] sm:text-[1.05rem]">Employment Status</p>
                    <p class="font-semibold mt-1 flex items-center gap-1 text-red-600 text-[0.85rem]">
                        <i class="fas fa-plus-circle"></i> Add record now
                    </p>
                @endif
            </button>

        </div>{{-- end stat grid --}}
    </div>{{-- end main grid --}}

</div>


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EMPLOYMENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'employment_detail' && !empty($selectedEmployment))
@php $emp = $selectedEmployment; @endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-[#F9FAFB] overflow-hidden emp-detail-in"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-4 sm:px-6 h-auto min-h-[3rem] py-2 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)] bg-[#7A3F91]">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.6rem] sm:text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">MY EMPLOYMENT RECORD</p>
                <p class="text-[0.82rem] sm:text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[180px] sm:max-w-[460px]">{{ $emp['job_title'] ?: ($emp['status_label'] ?: 'Employment Details') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-2 sm:ml-4">
            <button type="button" wire:click="goToUpdateEmployment"
               class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-lg bg-white/12 border border-white/20 text-white text-[0.78rem] font-semibold hover:bg-white/22 transition cursor-pointer">
                <i class="fas fa-pen text-xs"></i><span class="hidden sm:inline ml-1">Update</span>
            </button>
            <div class="close-btn-wrap">
                <span class="close-tooltip">Close</span>
                <button wire:click="closeModal" type="button"
                        class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap border-b border-gray-200 bg-white shrink-0">
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Status</p>
            <p class="text-[0.92rem] font-bold leading-snug" style="color:{{ $emp['status_color'] }};">{{ $emp['status_label'] ?: '—' }}</p>
        </div>
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Job Title</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate max-w-[180px] text-gray-900">{{ $emp['job_title'] ?: '—' }}</p>
        </div>
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Company</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate max-w-[180px] text-gray-900">{{ $emp['company_name'] ?: '—' }}</p>
        </div>
        <div class="px-4 sm:px-5 py-3 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Date Hired</p>
            <p class="text-[0.92rem] font-bold leading-snug text-gray-900">{{ $emp['date_hired'] ?: '—' }}</p>
            @if($emp['date_hired_ago'])<p class="text-[0.78rem] text-gray-700 mt-px">{{ $emp['date_hired_ago'] }}</p>@endif
        </div>
    </div>

    <div class="flex flex-wrap gap-1.5 items-center px-4 sm:px-5 py-2 bg-white border-b border-gray-100 shrink-0">
        <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-gray-900 bg-[#F9F7FC] border-[#E8E0F0]"><i class="fas fa-building text-[10px]"></i> PHILCST Alumni</span>
        @if(!empty($emp['employment_type']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-blue-700 bg-blue-50 border-blue-200"><i class="fas fa-id-badge text-[10px]"></i> {{ $emp['employment_type'] }}</span>@endif
        @if(!empty($emp['industry']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-amber-700 bg-amber-50 border-amber-200"><i class="fas fa-industry text-[10px]"></i> {{ $emp['industry'] }}</span>@endif
        @if(!empty($emp['edu_label']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-gray-900 bg-[#EDE0F5] border-[#c9ace0]"><i class="fas fa-graduation-cap text-[10px]"></i> {{ $emp['edu_label'] }}</span>@endif
        @if(!empty($emp['abroad']) && $emp['abroad'])<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-cyan-700 bg-cyan-50 border-cyan-200"><i class="fas fa-globe text-[10px]"></i> Working Abroad</span>@endif
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto bg-[#F9FAFB] px-4 sm:px-6 py-5 flex flex-col gap-3.5 [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">

        @if(!empty($emp['job_title']) || !empty($emp['company_name']) || !empty($emp['industry']))
        <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">POSITION INFORMATION</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                @if(!empty($emp['job_title']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Job Title / Position</p>
                    <p class="text-[0.92rem] font-bold text-gray-900 leading-snug uppercase">{{ $emp['job_title'] }}</p>
                </div>
                @endif
                @if(!empty($emp['company_name']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Company / Employer</p>
                    <p class="text-[0.92rem] font-bold text-gray-900 leading-snug">{{ $emp['company_name'] }}</p>
                    @if(!empty($emp['company_address']))<p class="text-[0.78rem] text-gray-700 mt-px">{{ $emp['company_address'] }}</p>@endif
                </div>
                @endif
                @if(!empty($emp['industry']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Industry</p>
                    <p class="text-[0.92rem] font-bold text-gray-900 leading-snug">{{ $emp['industry'] }}</p>
                </div>
                @endif
                @if(!empty($emp['monthly_salary']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Monthly Salary</p>
                    <p class="text-[0.92rem] font-bold text-green-600 leading-snug">{{ $emp['monthly_salary'] }}</p>
                </div>
                @endif
                @if(!empty($emp['date_hired']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Date Hired</p>
                    <p class="text-[0.92rem] font-bold text-gray-900 leading-snug">{{ $emp['date_hired'] }}</p>
                    @if(!empty($emp['date_hired_ago']))<p class="text-[0.78rem] text-gray-700 mt-px">{{ $emp['date_hired_ago'] }}</p>@endif
                </div>
                @endif
            </div>
        </div>
        @endif

        @if(!empty($emp['skills']))
        <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">SKILLS</p>
            <p class="text-[0.90rem] leading-[1.75] text-gray-700 whitespace-pre-wrap">{{ trim($emp['skills']) }}</p>
        </div>
        @endif

        @if(!empty($emp['linkedin_url']) || !empty($emp['remarks']))
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            @if(!empty($emp['linkedin_url']))
            <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">LINKEDIN</p>
                <a href="{{ $emp['linkedin_url'] }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 text-sm font-semibold hover:underline text-[#0a66c2]">
                    <i class="fab fa-linkedin"></i> View Profile
                </a>
            </div>
            @endif
            @if(!empty($emp['remarks']))
            <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">REMARKS</p>
                <p class="text-[0.90rem] leading-[1.75] text-gray-700 whitespace-pre-wrap">{{ trim($emp['remarks']) }}</p>
            </div>
            @endif
        </div>
        @endif

        @if(!empty($emp['updated_at']))
        <p class="text-center text-gray-400 font-normal text-[0.82rem]">Last updated {{ $emp['updated_ago'] }}</p>
        @endif

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="goToUpdateEmployment"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 bg-[#7A3F91] cursor-pointer">
                <i class="fas fa-pen text-xs"></i> Update Employment
            </button>
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-gray-700 hover:bg-gray-50 transition bg-white">
                <i class="fas fa-xmark text-xs"></i> Close
            </button>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: PROFILE DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'profile_detail')
@php
    $empCardMap3 = [
        'employed'      => ['Employed',      'fa-user-tie',         '#16a34a', '#F0FDF4', '#BBF7D0'],
        'self_employed' => ['Self-Employed',  'fa-store',            '#0891b2', '#ECFEFF', '#a5f3fc'],
        'unemployed'    => ['Unemployed',     'fa-magnifying-glass', '#d97706', '#FFFBEB', '#fde68a'],
    ];
    $empInfo3 = $empCardMap3[$employmentStatus] ?? null;
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-white overflow-hidden id-card-in"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-4 sm:px-6 h-auto min-h-[3rem] py-2 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)] bg-[#7A3F91]">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-user text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.6rem] sm:text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">MY ALUMNI PROFILE</p>
                <p class="text-[0.82rem] sm:text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[180px] sm:max-w-[460px] uppercase">{{ $alumniName ?: '—' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-2 sm:ml-4">
            <a href="{{ route('alumni.information') }}"
               class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-lg bg-white/12 border border-white/20 text-white text-[0.78rem] font-semibold hover:bg-white/22 transition no-underline">
                <i class="fas fa-pen text-xs"></i><span class="hidden sm:inline ml-1">Update Profile</span>
            </a>
            <div class="close-btn-wrap">
                <span class="close-tooltip">Close</span>
                <button wire:click="closeModal" type="button"
                        class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap border-b border-gray-200 bg-white shrink-0">
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Course</p>
            <p class="text-[0.92rem] font-bold leading-snug font-mono text-gray-900">{{ $alumniCourseCode ?: '—' }}</p>
            @if($alumniCourseFull)<p class="text-[0.78rem] text-gray-700 mt-px truncate max-w-[160px]">{{ $alumniCourseFull }}</p>@endif
        </div>
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Batch Year</p>
            <p class="text-[0.92rem] font-bold leading-snug text-gray-900">{{ $alumniBatch ?: '—' }}</p>
        </div>
        <div class="px-4 sm:px-5 py-3 border-r border-gray-100 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">College</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate uppercase max-w-[160px] text-gray-900">{{ $alumniCollege ?: '—' }}</p>
        </div>
        <div class="px-4 sm:px-5 py-3 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Profile Status</p>
            <p class="text-[0.92rem] font-bold leading-snug {{ $profileComplete ? 'text-green-600' : 'text-amber-600' }}">
                {{ $profileComplete ? 'Complete' : 'Incomplete' }}
            </p>
        </div>
    </div>

    <div class="flex flex-wrap gap-1.5 items-center px-4 sm:px-5 py-2 bg-white border-b border-gray-100 shrink-0">
        <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-gray-900 bg-[#F9F7FC] border-[#E8E0F0]"><i class="fas fa-building text-[10px]"></i> PHILCST Alumni</span>
        @if($alumniCourseCode)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-blue-700 bg-blue-50 border-blue-200"><i class="fas fa-book-open text-[10px]"></i> {{ $alumniCourseCode }}</span>@endif
        @if($alumniBatch)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-green-700 bg-green-50 border-green-200"><i class="fas fa-calendar-check text-[10px]"></i> Batch {{ $alumniBatch }}</span>@endif
        @if($hasEmployment && $empInfo3)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border" style="background:{{ $empInfo3[3] }}; color:{{ $empInfo3[2] }}; border-color:{{ $empInfo3[4] }};"><i class="fas {{ $empInfo3[1] }} text-[10px]"></i> {{ $empInfo3[0] }}</span>@endif
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto bg-[#F9FAFB] px-4 sm:px-6 py-5 flex flex-col gap-3.5 [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">

        <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
            <div class="flex flex-col sm:flex-row gap-5">
                <div class="w-full sm:w-36 shrink-0">
                    <div class="rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-[#EDE0F5] mx-auto sm:mx-0 max-w-[160px] sm:max-w-none" style="aspect-ratio:3/4;">
                        <img src="{{ $this->getProfilePhotoUrl() }}" alt="{{ $alumniFirstName }}" class="w-full h-full object-cover"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full items-center justify-center text-5xl font-black text-white hidden bg-[#7A3F91]"
                             style="display:none;">
                            {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                        </div>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="mb-3 text-center sm:text-left">
                        <p class="text-xl font-bold text-gray-900 uppercase tracking-wide leading-tight">{{ $alumniName ?: '—' }}</p>
                        <p class="text-sm text-gray-700 font-mono mt-0.5">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Student ID</p>
                            <p class="text-[0.92rem] font-bold text-gray-900 leading-snug font-mono">{{ $alumniStudentId ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Program</p>
                            <p class="text-[0.92rem] font-bold text-gray-900 leading-snug">{{ $alumniCourseFull ?: ($alumniCourseCode ?: '—') }}</p>
                        </div>
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Batch / Graduation Year</p>
                            <p class="text-[0.92rem] font-bold text-gray-900 leading-snug">{{ $alumniBatch ?: '—' }}</p>
                        </div>
                        @if($alumniCollege)
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">College</p>
                            <p class="text-[0.92rem] font-bold text-gray-900 leading-snug uppercase">{{ $alumniCollege }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-0.5">Profile Status</p>
                            <p class="text-[0.92rem] font-bold leading-snug {{ $profileComplete ? 'text-emerald-600' : 'text-amber-600' }}">
                                <i class="fas fa-{{ $profileComplete ? 'circle-check' : 'circle-exclamation' }} mr-1"></i>
                                {{ $profileComplete ? 'Profile Complete' : 'Incomplete' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">EMPLOYMENT SUMMARY</p>
            @if($hasEmployment && $empInfo3)
            <div class="flex items-center gap-3 p-3 rounded-xl border mb-4"
                 style="background:{{ $empInfo3[3] }}; border-color:{{ $empInfo3[4] }};">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                     style="background:{{ $empInfo3[2] }}22;">
                    <i class="fas {{ $empInfo3[1] }} text-sm" style="color:{{ $empInfo3[2] }};"></i>
                </div>
                <div>
                    <p class="font-bold text-[0.9rem]" style="color:{{ $empInfo3[2] }};">{{ $empInfo3[0] }}</p>
                    @if($jobTitle)<p class="text-gray-700 font-semibold uppercase text-[0.8rem]">{{ $jobTitle }}@if($companyName) · {{ $companyName }}@endif</p>@endif
                </div>
            </div>
            <button wire:click="openEmploymentModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-white transition hover:opacity-90 bg-[#7A3F91]">
                <i class="fas fa-eye text-xs"></i> View Full Employment Details
            </button>
            @else
            <div class="flex flex-col items-center py-6 gap-3">
                <div class="w-12 h-12 rounded-2xl bg-red-50 flex items-center justify-center">
                    <i class="fas fa-triangle-exclamation text-xl text-red-400"></i>
                </div>
                <p class="font-medium text-gray-700 text-[0.95rem]">No employment record on file.</p>
                <button type="button" wire:click="goToUpdateEmployment"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-white transition hover:opacity-90 bg-red-600 cursor-pointer">
                    <i class="fas fa-plus text-xs"></i> Add Employment Record
                </button>
            </div>
            @endif
        </div>

        <div class="bg-white border border-gray-200 rounded-xl px-4 sm:px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-gray-400 mb-3">ACTIVITY SUMMARY</p>
            <div class="grid grid-cols-3 gap-2 sm:gap-3">
                <button wire:click="goToUpcomingEvents"
                   class="rounded-xl border p-2.5 sm:p-3 text-center bg-blue-50 border-blue-200 hover:bg-blue-100 transition w-full">
                    <p class="text-xl sm:text-2xl font-bold text-blue-700">{{ $upcomingEvents }}</p>
                    <p class="text-[0.65rem] sm:text-xs font-semibold mt-0.5 uppercase text-gray-700">Upcoming</p>
                </button>
                <button wire:click="goToAllEvents"
                   class="rounded-xl border p-2.5 sm:p-3 text-center bg-emerald-50 border-emerald-200 hover:bg-emerald-100 transition w-full">
                    <p class="text-xl sm:text-2xl font-bold text-emerald-700">{{ $totalEvents }}</p>
                    <p class="text-[0.65rem] sm:text-xs font-semibold mt-0.5 uppercase text-gray-700">Events</p>
                </button>
                <button wire:click="goToJobs"
                   class="rounded-xl border p-2.5 sm:p-3 text-center bg-amber-50 border-amber-200 hover:bg-amber-100 transition w-full">
                    <p class="text-xl sm:text-2xl font-bold text-amber-700">{{ $activeJobs }}</p>
                    <p class="text-[0.65rem] sm:text-xs font-semibold mt-0.5 uppercase text-gray-700">Jobs</p>
                </button>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('alumni.information') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 bg-[#7A3F91]">
                <i class="fas fa-pen text-xs"></i> Update Profile
            </a>
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-gray-700 hover:bg-gray-50 transition bg-white">
                <i class="fas fa-xmark text-xs"></i> Close
            </button>
        </div>

    </div>
</div>
@endif

</div>{{-- end root --}}