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
    public int $myRsvps        = 0;

    public array $recentEvents = [];
    public array $recentJobs   = [];

    // ── Modal state ───────────────────────────────────────────────
    public string $activeModal     = '';
    public string $eventModalTitle = 'Events';
    public array  $modalEvents     = [];
    public array  $modalJobs       = [];
    public array  $modalRsvps      = [];

    // ── Search filters ────────────────────────────────────────────
    public string $eventSearch = '';
    public string $jobSearch   = '';

    // ── Jobs modal pagination ─────────────────────────────────────
    public int $jobModalPage     = 1;
    public int $jobModalPageSize = 20;

    // ── Detail view state ─────────────────────────────────────────
    public array $selectedEvent = [];
    public array $selectedJob   = [];

    // ── Profile photo helper ──────────────────────────────────────
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

        // ── Upcoming Events count (future date only — APPROVED only since
        //    COMPLETED means already past, so no need to include them here)
        $this->upcomingEvents = Cache::remember("dash_upcoming_{$college}", 120, function () use ($college, $course, $now) {
            $admin = AdminEvent::withoutTrashed()
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->where('event_date', '>', $now)->count();
            // FIX: organizer events that are still upcoming will be APPROVED;
            // COMPLETED ones are past — but include both and let the date filter decide.
            $org = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                    ->orWhere('target_participants', 'like', "%{$course}%"))
                ->where('event_date', '>', $now)->count();
            return $admin + $org;
        });

        // ── Total Events count (ALL — past + future)
        // FIX: include COMPLETED organizer events so they appear in total count.
        $this->totalEvents = Cache::remember("dash_total_{$college}", 120, function () use ($college, $course) {
            $admin = AdminEvent::withoutTrashed()
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->count();
            // FIX: was where('status', 'APPROVED') — missed all COMPLETED events
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

        $this->myRsvps = \App\Models\EventRsvp::where('alumni_id', $alumni->id)
            ->where('response', 'CONFIRMED')->count();

        // ── 3 recent upcoming events (future only → APPROVED is correct here,
        //    but include COMPLETED too in case edge-case events straddle midnight)
        $adminEvts = AdminEvent::withoutTrashed()
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                ->orWhere('target_participants', 'like', "%{$college}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')->limit(3)
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ADMIN',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => true,
            ])->toArray();

        $orgEvts = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')->limit(3)
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ORGANIZER',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => true,
            ])->toArray();

        $this->recentEvents = collect(array_merge($adminEvts, $orgEvts))
            ->sortBy('date')->take(3)->values()->toArray();

        // 3 recent jobs
        $this->recentJobs = JobPosting::where('status', 'ACTIVE')
            ->where(fn($q) => $q->whereNull('target_college')
                                ->orWhere('target_college', '')
                                ->orWhere('target_college', 'like', "%{$college}%"))
            ->where('deadline', '>=', now('Asia/Manila')->toDateString())
            ->orderByDesc('created_at')->limit(3)
            ->get(['id','job_title','company_name','employment_type','location','deadline','salary'])
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
            ])->toArray();
    }

    // ── Modal: fetch all events ────────────────────────────────────
    // FIX: include COMPLETED in both admin and organizer queries so that
    // the Total Events modal actually shows all events including past ones.
    private function fetchAllEvents(bool $upcomingOnly = false): array
    {
        $college = $this->alumniCollege;
        $course  = $this->alumniCourseCode;
        $now     = Carbon::now('UTC');

        $adminQ = AdminEvent::withoutTrashed()
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                ->orWhere('target_participants', 'like', "%{$college}%"));
        if ($upcomingOnly) $adminQ->where('event_date', '>', $now);

        $adminEvts = $adminQ->orderBy('event_date')
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id,
                'source'      => 'ADMIN',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => $e->event_date->gt($now),
            ])->toArray();

        // FIX: was where('status', 'APPROVED') — COMPLETED organizer events
        // never showed up in either the Upcoming or Total Events modals.
        $orgQ = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"));
        if ($upcomingOnly) $orgQ->where('event_date', '>', $now);

        $orgEvts = $orgQ->orderBy('event_date')
            ->get(['id','title','event_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id,
                'source'      => 'ORGANIZER',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => $e->event_date->gt($now),
            ])->toArray();

        return collect(array_merge($adminEvts, $orgEvts))->sortBy('date')->values()->toArray();
    }

    // ── Modal open/close ──────────────────────────────────────────

    public function openUpcomingEventsModal(): void
    {
        $this->eventModalTitle = 'Upcoming Events';
        $this->modalEvents     = $this->fetchAllEvents(upcomingOnly: true);
        $this->eventSearch     = '';
        $this->activeModal     = 'events';
    }

    public function openTotalEventsModal(): void
    {
        $this->eventModalTitle = 'All Events';
        $this->modalEvents     = $this->fetchAllEvents(upcomingOnly: false);
        $this->eventSearch     = '';
        $this->activeModal     = 'events';
    }

    public function openJobsModal(): void
    {
        $college = $this->alumniCollege;
        $this->modalJobs = JobPosting::where('status', 'ACTIVE')
            ->where(fn($q) => $q->whereNull('target_college')
                                ->orWhere('target_college', '')
                                ->orWhere('target_college', 'like', "%{$college}%"))
            ->where('deadline', '>=', now('Asia/Manila')->toDateString())
            ->orderByDesc('created_at')
            ->get(['id','job_title','company_name','employment_type','location','deadline','salary'])
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
            ])->toArray();

        $this->jobSearch    = '';
        $this->jobModalPage = 1;
        $this->activeModal  = 'jobs';
    }

    public function updatingJobSearch(): void  { $this->jobModalPage = 1; }
    public function jobPrevPage(): void        { if ($this->jobModalPage > 1) $this->jobModalPage--; }
    public function jobNextPage(int $lastPage): void
    {
        if ($this->jobModalPage < $lastPage) $this->jobModalPage++;
    }

    public function openRsvpsModal(): void
    {
        $rsvps  = \App\Models\EventRsvp::where('alumni_id', $this->alumniId)
            ->where('response', 'CONFIRMED')
            ->orderByDesc('created_at')
            ->get();

        $result = [];
        $now    = Carbon::now('UTC');

        foreach ($rsvps as $r) {
            $event  = null;
            $source = 'ADMIN';

            if (!empty($r->event_type) && strtoupper($r->event_type) === 'ORGANIZER') {
                // FIX: include COMPLETED organizer events in RSVP lookup
                $event  = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])->find($r->event_id);
                $source = 'ORGANIZER';
            } else {
                $event = AdminEvent::withoutTrashed()->find($r->event_id ?? 0);
                if (!$event && !empty($r->event_id)) {
                    // FIX: fallback also includes COMPLETED
                    $event  = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])->find($r->event_id);
                    $source = 'ORGANIZER';
                }
            }

            $result[] = [
                'id'          => $r->id,
                'rsvp_date'   => $r->created_at->setTimezone('Asia/Manila')->format('M d, Y'),
                'source'      => $source,
                'title'       => $event?->title ?? '(Event #' . ($r->event_id ?? '?') . ')',
                'date'        => $event ? $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') : '—',
                'time'        => $event ? $event->event_date->setTimezone('Asia/Manila')->format('h:i A')  : '',
                'venue'       => $event?->venue ?? '',
                'photo'       => $event?->photo_url ?? '',
                'is_upcoming' => $event ? $event->event_date->gt($now) : false,
            ];
        }

        $this->modalRsvps  = $result;
        $this->activeModal = 'rsvps';
    }

    public function openEmploymentModal(): void
    {
        $this->activeModal = 'employment';
    }

    public function openEventDetail(int $id, string $source): void
    {
        foreach ($this->recentEvents as $evt) {
            if ($evt['id'] === $id && $evt['source'] === $source) {
                $this->selectedEvent = $evt;
                $this->activeModal   = 'event_detail';
                return;
            }
        }
        $now   = Carbon::now('UTC');
        // FIX: include COMPLETED when looking up event for detail view
        $event = $source === 'ORGANIZER'
            ? OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])->find($id)
            : AdminEvent::withoutTrashed()->find($id);
        if ($event) {
            $this->selectedEvent = [
                'id'          => $event->id,
                'source'      => $source,
                'title'       => $event->title,
                'date'        => $event->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $event->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'venue'       => $event->venue ?? '',
                'photo'       => $event->photo_url ?? '',
                'is_upcoming' => $event->event_date->gt($now),
            ];
            $this->activeModal = 'event_detail';
        }
    }

    public function openJobDetail(int $id): void
    {
        foreach ($this->recentJobs as $job) {
            if ($job['id'] === $id) {
                $this->selectedJob = $job;
                $this->activeModal = 'job_detail';
                return;
            }
        }
        $j = JobPosting::find($id);
        if ($j) {
            $deadline = Carbon::parse($j->deadline)->setTimezone('Asia/Manila');
            $this->selectedJob = [
                'id'        => $j->id,
                'title'     => $j->job_title,
                'company'   => $j->company_name,
                'type'      => $j->employment_type,
                'location'  => $j->location ?? '',
                'salary'    => $j->salary   ?? '',
                'deadline'  => $deadline->format('M d, Y'),
                'days_left' => (int) now('Asia/Manila')->startOfDay()->diffInDays(
                    $deadline->copy()->startOfDay(), false
                ),
            ];
            $this->activeModal = 'job_detail';
        }
    }

    public function closeModal(): void
    {
        $this->activeModal = '';
    }

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
    .stat-card:active  { transform: scale(.985); }
    .stat-card         { transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease; }
    .dash-list-item    { transition: background .12s ease, border-color .12s ease; }
    .dash-list-item:hover { background:#faf7ff; border-color:#d9c9e8; }
    @keyframes dashModalIn {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .dash-modal-enter { animation: dashModalIn .22s cubic-bezier(.4,0,.2,1) both; }
    .dash-scroll { scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb; }
    .modal-loading-overlay {
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }
</style>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 pb-4 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:#7A3F91;">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-[#333333] leading-tight">Alumni Dashboard</h1>
            <p class="text-sm text-[#666666] font-normal">{{ now()->format('l, F j, Y') }}</p>
        </div>

        @if(!$profileComplete || !$hasEmployment)
        <div class="ml-auto hidden sm:flex items-center gap-2.5 px-3 py-2 rounded-xl border text-xs font-semibold"
             style="background:#F9F7FC; border-color:#d9c9e8; color:#5a2d72;">
            <i class="fas fa-triangle-exclamation text-sm" style="color:#9b59b6;"></i>
            <span>@if(!$profileComplete) Complete your profile @else Add employment info @endif</span>
            <a href="{{ !$profileComplete ? route('alumni.information') : route('alumni.employment') }}"
               class="px-2.5 py-1 rounded-lg text-white text-xs font-semibold transition hover:opacity-90"
               style="background:#7A3F91;">
                Go <i class="fas fa-arrow-right text-xs ml-0.5"></i>
            </a>
        </div>
        @endif
    </div>

    {{-- ═══ STAT CARDS ══════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Upcoming Events — BLUE --}}
        <div wire:click="openUpcomingEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-[#2563eb]/40">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:#2563eb;">
                    <i class="fas fa-calendar-check text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#EFF6FF; color:#1d4ed8; border:1px solid #bfdbfe;">Upcoming</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $upcomingEvents }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Upcoming Events</p>
            @if($upcomingEvents > 0)
                <p class="text-xs font-semibold mt-2 flex items-center gap-1" style="color:#2563eb;">
                    <i class="fas fa-arrow-trend-up text-sm"></i> For your college
                </p>
            @endif
        </div>

        {{-- Total Events — GREEN --}}
        <div wire:click="openTotalEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-[#059669]/40">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:#059669;">
                    <i class="fas fa-calendar-days text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#ECFDF5; color:#047857; border:1px solid #a7f3d0;">Total</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalEvents }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Total Events</p>
        </div>

        {{-- Active Jobs — AMBER --}}
        <div wire:click="openJobsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-[#d97706]/40">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:#d97706;">
                    <i class="fas fa-briefcase text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#FFFBEB; color:#b45309; border:1px solid #fde68a;">Jobs</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $activeJobs }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Active Job Posts</p>
            @if($activeJobs > 0)
                <p class="text-xs font-semibold mt-2 flex items-center gap-1" style="color:#d97706;">
                    <i class="fas fa-circle-dot text-sm"></i> Open for your college
                </p>
            @endif
        </div>

        {{-- My RSVPs — TEAL --}}
        <div wire:click="openRsvpsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-[#0891b2]/40">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:#0891b2;">
                    <i class="fas fa-circle-check text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full uppercase"
                      style="background:#ECFEFF; color:#0e7490; border:1px solid #a5f3fc;">RSVPs</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $myRsvps }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">My RSVPs</p>
            @if($myRsvps > 0)
                <div class="mt-2 h-1.5 rounded-full overflow-hidden" style="background:#cffafe;">
                    <div class="h-full rounded-full transition-all duration-700"
                         style="width:{{ min(($myRsvps / max($totalEvents,1)) * 100, 100) }}%;
                                background:#0891b2;"></div>
                </div>
                <p class="text-xs text-[#999999] mt-1 font-normal">Confirmed attendances</p>
            @endif
        </div>

    </div>

    {{-- ═══ MAIN GRID ═══════════════════════════════════════════════ --}}
    @php
        $sMap = [
            'employed'      => ['Employed',     'fa-user-tie',        '#7A3F91','#F9F7FC','#E8E0F0'],
            'self_employed' => ['Self-Employed', 'fa-store',           '#5c2d7a','#EDE0F5','#c9ace0'],
            'unemployed'    => ['Unemployed',    'fa-magnifying-glass','#9b59b6','#F5EDF9','#dbbcef'],
        ];
        $empRow = $sMap[$employmentStatus] ?? null;

        $eMap = [
            'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',     '#5c2d7a','#EDE0F5','#c9ace0'],
            'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard', '#7A3F91','#F9F7FC','#E8E0F0'],
        ];
        $eduRow = $eMap[$educationStatus] ?? null;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ─── LEFT COL: Profile Overview ──────────────────────── --}}
        <div class="lg:col-span-1 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0]"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:#7A3F91;">
                        <i class="fas fa-user text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">My Profile</p>
                </div>
            </div>

            <div class="p-4 flex flex-col gap-3 flex-1">

                <div class="flex items-center gap-3">
                    @php $photoUrl = $this->getProfilePhotoUrl(); @endphp
                    <div class="w-11 h-11 rounded-xl flex-shrink-0 overflow-hidden shadow ring-2 ring-[#E8E0F0]">
                        <img src="{{ $photoUrl }}"
                             alt="{{ $alumniFirstName }}"
                             class="w-full h-full object-cover"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full items-center justify-center text-lg font-black text-white hidden"
                             style="background:#7A3F91; display:none;">
                            {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-[#333333] text-sm leading-snug truncate uppercase">
                            {{ $alumniName ?: '—' }}
                        </p>
                        <p class="text-xs text-[#999999] font-mono mt-0.5">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                </div>

                <div class="space-y-2">

                    <div class="rounded-xl border p-3 flex items-center justify-between"
                         style="background:#F9F7FC; border-color:#E8E0F0;">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                 style="background:#2563eb;">
                                <i class="fas fa-book-open text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-[#333333]">Course</span>
                        </div>
                        <span class="text-sm font-semibold text-[#7A3F91] font-mono">{{ $alumniCourseCode ?: '—' }}</span>
                    </div>

                    <div class="rounded-xl border p-3 flex items-center justify-between"
                         style="background:#EDE0F5; border-color:#c9ace0;">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                 style="background:#059669;">
                                <i class="fas fa-calendar-check text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-[#333333]">Batch</span>
                        </div>
                        <span class="text-sm font-semibold" style="color:#5c2d7a;">{{ $alumniBatch ?: '—' }}</span>
                    </div>

                    @if($alumniCollege)
                    <div class="rounded-xl border p-3" style="background:#F5EDF9; border-color:#dbbcef;">
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                 style="background:#d97706;">
                                <i class="fas fa-university text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-[#333333]">College</span>
                        </div>
                        <p class="text-xs font-semibold text-[#666666] uppercase leading-snug pl-9">{{ $alumniCollege }}</p>
                    </div>
                    @endif
                </div>

            </div>
        </div>

        {{-- ─── RIGHT COL: Employment + Events + Jobs ──────────── --}}
        <div class="lg:col-span-2 flex flex-col gap-4">

            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden cursor-pointer
                        hover:shadow-md hover:border-[#7A3F91]/40 transition-all duration-150"
                 wire:click="openEmploymentModal">
                <div class="px-5 py-3.5 border-b border-[#E8E0F0]"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                                 style="background:#4f46e5;">
                                <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Employment Status</p>
                        </div>
                        <i class="fas fa-chevron-right text-xs text-[#CCCCCC]"></i>
                    </div>
                </div>

                <div class="p-4">
                    @if($hasEmployment && $empRow)
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="rounded-xl border p-3 flex items-center gap-3 flex-1 min-w-[160px]"
                                 style="background:{{ $empRow[3] }}; border-color:{{ $empRow[4] }};">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                     style="background:{{ $empRow[2] }}20; color:{{ $empRow[2] }};">
                                    <i class="fas {{ $empRow[1] }} text-xs"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Status</p>
                                    <p class="text-sm font-semibold text-[#333333]">{{ $empRow[0] }}</p>
                                </div>
                            </div>

                            @if($jobTitle || $companyName)
                            <div class="rounded-xl border p-3 flex-1 min-w-[160px]"
                                 style="background:#F9F7FC; border-color:#E8E0F0;">
                                @if($jobTitle)
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $jobTitle }}</p>
                                @endif
                                @if($companyName)
                                <p class="text-xs text-[#999999] font-normal truncate uppercase mt-0.5">{{ $companyName }}</p>
                                @endif
                            </div>
                            @endif

                            @if($eduRow)
                            <div class="rounded-xl border p-3 flex items-center gap-3"
                                 style="background:{{ $eduRow[3] }}; border-color:{{ $eduRow[4] }};">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                     style="background:{{ $eduRow[2] }}20; color:{{ $eduRow[2] }};">
                                    <i class="fas {{ $eduRow[1] }} text-xs"></i>
                                </div>
                                <p class="text-sm font-semibold text-[#333333]">{{ $eduRow[0] }}</p>
                            </div>
                            @endif
                        </div>
                    @else
                        <div class="flex items-center gap-4">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center"
                                 style="background:#F9F7FC; border:1px solid #E8E0F0;">
                                <i class="fas fa-briefcase text-base" style="color:#c89de0;"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-[#666666]">No employment record submitted yet.</p>
                                <p class="text-xs text-[#999999] font-normal">Tap to add your employment information.</p>
                            </div>
                            <i class="fas fa-chevron-right text-xs text-[#CCCCCC] shrink-0"></i>
                        </div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 flex-1">

                {{-- Upcoming Events --}}
                <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                    <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center"
                         style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                                 style="background:#0891b2;">
                                <i class="fas fa-calendar-check text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Upcoming Events</p>
                        </div>
                    </div>

                    <div class="p-3 flex flex-col gap-2 flex-1">
                        @forelse($recentEvents as $evt)
                        <div class="dash-list-item flex items-start gap-2.5 border border-[#E8E0F0] rounded-xl p-2.5 cursor-pointer"
                             wire:click="openEventDetail({{ $evt['id'] }}, '{{ $evt['source'] }}')">
                            <div class="w-8 h-8 rounded-lg flex-shrink-0 overflow-hidden" style="background:#f0e6f8;">
                                @if($evt['photo'])
                                    <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <i class="fas fa-calendar-days text-xs" style="color:#7A3F91;"></i>
                                    </div>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-[#333333] leading-snug truncate">{{ $evt['title'] }}</p>
                                <p class="text-xs text-[#999999] font-normal mt-0.5 flex items-center gap-1">
                                    <i class="fas fa-calendar text-xs"></i>{{ $evt['date'] }}
                                </p>
                                @if(!empty($evt['venue']))
                                <p class="text-xs text-[#BBBBBB] font-normal truncate flex items-center gap-1">
                                    <i class="fas fa-location-dot text-xs"></i>{{ $evt['venue'] }}
                                </p>
                                @endif
                            </div>
                            <span class="inline-flex items-center text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 uppercase"
                                  style="background:#F9F7FC; color:#7A3F91; border:1px solid #E8E0F0;">
                                Soon
                            </span>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-8 text-center flex-1">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" style="background:#f0e6f8;">
                                <i class="fas fa-calendar-days text-base" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#999999]">No upcoming events.</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                {{-- Latest Jobs --}}
                <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                    <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center"
                         style="background:linear-gradient(135deg,#F5EDF9,#FFFFFF);">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                                 style="background:#e11d48;">
                                <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Latest Jobs</p>
                        </div>
                    </div>

                    <div class="p-3 flex flex-col gap-2 flex-1">
                        @forelse($recentJobs as $job)
                        @php $isUrgent = ($job['days_left'] ?? 99) <= 7; @endphp
                        <div class="dash-list-item border border-[#E8E0F0] rounded-xl p-2.5 cursor-pointer hover:border-[#d9c9e8] hover:bg-[#faf7ff]"
                             wire:click="openJobDetail({{ $job['id'] }})">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $job['title'] }}</p>
                                    <p class="text-xs text-[#999999] font-normal truncate">{{ $job['company'] }}</p>
                                </div>
                                <span class="inline-flex items-center text-xs font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 uppercase"
                                      style="background:#F5EDF9; color:#9b59b6; border:1px solid #dbbcef;">
                                    {{ $job['type'] }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($job['salary'])
                                    <span class="text-xs font-semibold" style="color:#7A3F91;">
                                        <i class="fas fa-money-bill-wave text-xs"></i> {{ Str::limit($job['salary'], 16) }}
                                    </span>
                                @endif
                                @if($job['location'])
                                    <span class="text-xs text-[#AAAAAA] font-normal truncate">
                                        <i class="fas fa-location-dot text-xs"></i> {{ Str::limit($job['location'], 14) }}
                                    </span>
                                @endif
                                <span class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-[#999999]' }} ml-auto">
                                    <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs"></i>
                                    {{ $job['deadline'] }}
                                </span>
                            </div>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-8 text-center flex-1">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-base" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#999999]">No active job postings.</p>
                        </div>
                        @endforelse
                    </div>
                </div>

            </div>

        </div>

    </div>

</div>


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENTS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'events')
@php
    $displayEvents = collect($modalEvents)
        ->when($eventSearch !== '', fn($c) => $c->filter(fn($e) =>
            str_contains(strtolower($e['title']), strtolower($eventSearch)) ||
            str_contains(strtolower($e['venue'] ?? ''), strtolower($eventSearch))
        ))
        ->values()->toArray();
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ count($displayEvents) }} event(s) found</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('eventSearch', q)"
                       placeholder="Search event title or venue…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900
                              focus:outline-none focus:ring-2 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ count($displayEvents) }}</strong> event(s)
            </span>
        </div>
        <div wire:loading wire:target="eventSearch" class="mt-2">
            <div class="h-0.5 rounded-full overflow-hidden" style="background:#f0e6f8;">
                <div class="h-full rounded-full animate-pulse" style="background:#7A3F91; width:60%;"></div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <div wire:loading wire:target="eventSearch"
             class="modal-loading-overlay absolute inset-0 z-20 flex items-center justify-center pointer-events-none"
             style="background:rgba(255,255,255,.55);">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4" style="color:#7A3F91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold" style="color:#7A3F91;">Searching…</span>
            </div>
        </div>

        <table class="w-full border-collapse" style="min-width:520px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-16">Photo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Event Title</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date & Time</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Venue</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEvents as $idx => $evt)
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($idx+1,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <div class="w-10 h-10 rounded-xl overflow-hidden flex-shrink-0" style="background:#f0e6f8;">
                            @if($evt['photo'])
                                <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-calendar-days text-sm" style="color:#7A3F91;"></i>
                                </div>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-900">{{ $evt['title'] }}</p>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['date'] }}</p>
                        <p class="text-xs text-gray-400 font-normal">{{ $evt['time'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <p class="text-sm text-gray-500">{{ $evt['venue'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        @if($evt['is_upcoming'] ?? true)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                  style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                                <i class="fas fa-clock text-xs"></i> Upcoming
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-700 border-green-200">
                                <i class="fas fa-circle-check text-xs"></i> Completed
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-calendar-days text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No events found</p>
                        <p class="text-xs text-gray-300">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-6 py-4 shrink-0 flex items-center justify-between gap-3"
         style="background:#7A3F91;">
        <p class="text-white text-base font-normal">
            <strong class="font-bold text-lg">{{ count($displayEvents) }}</strong> event(s) shown
        </p>
        <button wire:click="closeModal"
                class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                style="color:#7A3F91;">
            Close
        </button>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: JOBS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'jobs')
@php
    $filteredJobs = collect($modalJobs)
        ->when($jobSearch !== '', fn($c) => $c->filter(fn($j) =>
            str_contains(strtolower($j['title']),   strtolower($jobSearch)) ||
            str_contains(strtolower($j['company']), strtolower($jobSearch)) ||
            str_contains(strtolower($j['location'] ?? ''), strtolower($jobSearch))
        ))
        ->values();

    $jobTotalCount = $filteredJobs->count();
    $jobLastPage   = max((int) ceil($jobTotalCount / $jobModalPageSize), 1);
    $jobSafePage   = min($jobModalPage, $jobLastPage);
    $jobFrom       = $jobTotalCount > 0 ? ($jobSafePage - 1) * $jobModalPageSize + 1 : 0;
    $jobTo         = min($jobSafePage * $jobModalPageSize, $jobTotalCount);

    $displayJobs   = $filteredJobs->slice(($jobSafePage - 1) * $jobModalPageSize, $jobModalPageSize)->values()->toArray();
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Active Job Postings</h2>
                <p class="text-white/60 text-xs font-normal">{{ $jobTotalCount }} job(s) found</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('jobSearch', q)"
                       placeholder="Search title, company, location…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900
                              focus:outline-none focus:ring-2 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 font-normal hidden sm:inline">
                Showing <strong class="text-gray-600">{{ $jobTotalCount }}</strong> posting(s)
            </span>
        </div>
        <div wire:loading wire:target="jobSearch" class="mt-2">
            <div class="h-0.5 rounded-full overflow-hidden" style="background:#f0e6f8;">
                <div class="h-full rounded-full animate-pulse" style="background:#7A3F91; width:60%;"></div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <div wire:loading wire:target="jobSearch,jobModalPage,jobPrevPage,jobNextPage"
             class="modal-loading-overlay absolute inset-0 z-20 flex items-center justify-center pointer-events-none"
             style="background:rgba(255,255,255,.55);">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4" style="color:#7A3F91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold" style="color:#7A3F91;">Loading…</span>
            </div>
        </div>

        <table class="w-full border-collapse" style="min-width:600px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Location</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Salary</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayJobs as $idx => $job)
                @php
                    $rowNum   = $jobFrom + $idx;
                    $isUrgent = ($job['days_left'] ?? 99) <= 7;
                @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-900 truncate" style="max-width:200px;">{{ $job['title'] }}</p>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm text-gray-600 truncate" style="max-width:160px;">{{ $job['company'] }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                            {{ $job['type'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <p class="text-sm text-gray-500">{{ $job['location'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <p class="text-sm font-semibold" style="color:#7A3F91;">{{ $job['salary'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <p class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-gray-500' }}">
                            <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-xs mr-0.5"></i>
                            {{ $job['deadline'] }}
                        </p>
                        @if($isUrgent)
                        <p class="text-xs text-red-400 font-normal mt-0.5">{{ $job['days_left'] }}d left</p>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-briefcase text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No active job postings</p>
                        <p class="text-xs text-gray-300">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-4 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:#7A3F91;">
        <p class="text-white text-base font-normal">
            Showing <strong class="font-bold text-lg">{{ $jobFrom }}–{{ $jobTo }}</strong>
            of <strong class="font-bold text-lg">{{ $jobTotalCount }}</strong> posting(s)
        </p>
        <div class="flex items-center gap-2">
            @if($jobSafePage <= 1)
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">← Prev</button>
            @else
                <button wire:click="jobPrevPage"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">← Prev</button>
            @endif
            <span class="px-4 py-2 text-sm font-bold bg-white rounded-xl shadow-sm" style="color:#7A3F91;">
                Page {{ $jobSafePage }} / {{ $jobLastPage }}
            </span>
            @if($jobSafePage < $jobLastPage)
                <button wire:click="jobNextPage({{ $jobLastPage }})"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">Next →</button>
            @else
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">Next →</button>
            @endif
        </div>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: MY RSVPs
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'rsvps')
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:#6b3585;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">My Confirmed RSVPs</h2>
                <p class="text-white/60 text-xs font-normal">{{ count($modalRsvps) }} RSVP(s)</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-16">Photo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Event</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Event Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Venue</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($modalRsvps as $idx => $rsvp)
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($idx+1,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <div class="w-10 h-10 rounded-xl overflow-hidden" style="background:#f0e6f8;">
                            @if($rsvp['photo'])
                                <img src="{{ $rsvp['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-calendar-days text-sm" style="color:#7A3F91;"></i>
                                </div>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-900">{{ $rsvp['title'] }}</p>
                        <p class="text-xs text-gray-400 font-normal mt-0.5">RSVP'd: {{ $rsvp['rsvp_date'] }}</p>
                    </td>
                    <td class="px-4 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $rsvp['date'] }}</p>
                        <p class="text-xs text-gray-400 font-normal">{{ $rsvp['time'] }}</p>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        <p class="text-sm text-gray-500">{{ $rsvp['venue'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        @if($rsvp['is_upcoming'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                  style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                                <i class="fas fa-circle-check text-xs"></i> Confirmed
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-700 border-green-200">
                                <i class="fas fa-circle-check text-xs"></i> Attended
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-circle-check text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No confirmed RSVPs yet</p>
                        <p class="text-xs text-gray-300 font-normal">RSVP to upcoming events to see them here</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-4 shrink-0 flex items-center justify-between gap-3"
         style="background:#6b3585;">
        <p class="text-white text-base font-normal">
            <strong class="font-bold text-lg">{{ count($modalRsvps) }}</strong> confirmed RSVP(s)
        </p>
        <button wire:click="closeModal"
                class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                style="color:#6b3585;">
            Close
        </button>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EMPLOYMENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'employment')
@php
    $sMap2 = [
        'employed'      => ['Employed',     'fa-user-tie',        '#7A3F91','#F9F7FC','#E8E0F0'],
        'self_employed' => ['Self-Employed', 'fa-store',           '#5c2d7a','#EDE0F5','#c9ace0'],
        'unemployed'    => ['Unemployed',    'fa-magnifying-glass','#9b59b6','#F5EDF9','#dbbcef'],
    ];
    $empRowM = $sMap2[$employmentStatus] ?? null;
    $eMap2 = [
        'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',     '#5c2d7a','#EDE0F5','#c9ace0'],
        'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard', '#7A3F91','#F9F7FC','#E8E0F0'],
    ];
    $eduRowM = $eMap2[$educationStatus] ?? null;
@endphp
<div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()"
     wire:click.self="closeModal">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fas fa-briefcase text-white text-sm"></i>
                </div>
                <h2 class="text-white font-semibold text-base leading-tight">My Employment Info</h2>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-95">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-6 space-y-3">
            @if($hasEmployment && $empRowM)

                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:{{ $empRowM[3] }}; border-color:{{ $empRowM[4] }};">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                         style="background:{{ $empRowM[2] }}20; color:{{ $empRowM[2] }};">
                        <i class="fas {{ $empRowM[1] }} text-base"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Employment Status</p>
                        <p class="text-base font-semibold text-[#333333]">{{ $empRowM[0] }}</p>
                    </div>
                </div>

                @if($jobTitle || $companyName)
                <div class="rounded-xl border p-4" style="background:#F9F7FC; border-color:#E8E0F0;">
                    @if($jobTitle)
                    <p class="text-base font-semibold text-[#333333] uppercase">{{ $jobTitle }}</p>
                    @endif
                    @if($companyName)
                    <p class="text-sm text-[#666666] uppercase mt-1">{{ $companyName }}</p>
                    @endif
                </div>
                @endif

                @if($eduRowM)
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:{{ $eduRowM[3] }}; border-color:{{ $eduRowM[4] }};">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                         style="background:{{ $eduRowM[2] }}20; color:{{ $eduRowM[2] }};">
                        <i class="fas {{ $eduRowM[1] }} text-base"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Education Status</p>
                        <p class="text-base font-semibold text-[#333333]">{{ $eduRowM[0] }}</p>
                    </div>
                </div>
                @endif

                <div class="pt-1 flex gap-2">
                    <button wire:click="closeModal"
                            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-[#E8E0F0] text-[#666666] hover:bg-gray-50 transition active:scale-95">
                        Close
                    </button>
                    <a href="{{ route('alumni.employment') }}"
                       class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                       style="background:#7A3F91;">
                        <i class="fas fa-pen text-xs"></i> Edit Info
                    </a>
                </div>

            @else

                <div class="text-center py-4">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f0e6f8;">
                        <i class="fas fa-briefcase text-2xl" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-sm font-semibold text-gray-600">No employment record yet.</p>
                    <p class="text-xs text-gray-400 font-normal mt-1 mb-4">Help track graduate outcomes by adding your info.</p>
                    <div class="flex gap-2">
                        <button wire:click="closeModal"
                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-[#E8E0F0] text-[#666666] hover:bg-gray-50 transition active:scale-95">
                            Close
                        </button>
                        <a href="{{ route('alumni.employment') }}"
                           class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                           style="background:#7A3F91;">
                            <i class="fas fa-plus text-xs"></i> Add Now
                        </a>
                    </div>
                </div>

            @endif
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'event_detail')
<div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()"
     wire:click.self="closeModal">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fas fa-calendar-check text-white text-sm"></i>
                </div>
                <h2 class="text-white font-semibold text-base leading-tight">Event Details</h2>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-95">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        @if(!empty($selectedEvent['photo']))
        <div class="w-full h-36 overflow-hidden">
            <img src="{{ $selectedEvent['photo'] }}" class="w-full h-full object-cover" alt="">
        </div>
        @else
        <div class="w-full h-20 flex items-center justify-center" style="background:#f0e6f8;">
            <i class="fas fa-calendar-days text-4xl" style="color:#c89de0;"></i>
        </div>
        @endif

        <div class="p-5 space-y-3">

            <div>
                <p class="text-base font-bold text-[#333333] leading-snug">{{ $selectedEvent['title'] ?? '' }}</p>
            </div>

            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:#F9F7FC; border-color:#E8E0F0;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:#2563eb;">
                    <i class="fas fa-calendar text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Date & Time</p>
                    <p class="text-sm font-semibold text-[#333333]">{{ $selectedEvent['date'] ?? '' }} &bull; {{ $selectedEvent['time'] ?? '' }}</p>
                </div>
            </div>

            @if(!empty($selectedEvent['venue']))
            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:#F9F7FC; border-color:#E8E0F0;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:#d97706;">
                    <i class="fas fa-location-dot text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Venue</p>
                    <p class="text-sm font-semibold text-[#333333]">{{ $selectedEvent['venue'] }}</p>
                </div>
            </div>
            @endif

            @php $evtUpcoming = $selectedEvent['is_upcoming'] ?? false; @endphp
            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:{{ $evtUpcoming ? '#F9F7FC' : '#F0FDF4' }}; border-color:{{ $evtUpcoming ? '#E8E0F0' : '#BBF7D0' }};">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:{{ $evtUpcoming ? '#059669' : '#16a34a' }};">
                    <i class="fas fa-{{ $evtUpcoming ? 'clock' : 'circle-check' }} text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Status</p>
                    <p class="text-sm font-semibold" style="color:{{ $evtUpcoming ? '#059669' : '#16a34a' }};">
                        {{ $evtUpcoming ? 'Upcoming' : 'Completed' }}
                    </p>
                </div>
            </div>

            <div class="pt-1">
                <button wire:click="closeModal"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                        style="background:#7A3F91;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: JOB DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'job_detail')
@php $jobUrgent = ($selectedJob['days_left'] ?? 99) <= 7; @endphp
<div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()"
     wire:click.self="closeModal">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fas fa-briefcase text-white text-sm"></i>
                </div>
                <h2 class="text-white font-semibold text-base leading-tight">Job Details</h2>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all active:scale-95">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-5 space-y-3">

            <div class="pb-1">
                <p class="text-base font-bold text-[#333333] leading-snug">{{ $selectedJob['title'] ?? '' }}</p>
                <p class="text-sm text-[#666666] mt-0.5 uppercase">{{ $selectedJob['company'] ?? '' }}</p>
            </div>

            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:#F9F7FC; border-color:#E8E0F0;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:#4f46e5;">
                    <i class="fas fa-id-badge text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Employment Type</p>
                    <p class="text-sm font-semibold text-[#333333]">{{ $selectedJob['type'] ?? '—' }}</p>
                </div>
            </div>

            @if(!empty($selectedJob['location']))
            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:#F9F7FC; border-color:#E8E0F0;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:#0891b2;">
                    <i class="fas fa-location-dot text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Location</p>
                    <p class="text-sm font-semibold text-[#333333]">{{ $selectedJob['location'] }}</p>
                </div>
            </div>
            @endif

            @if(!empty($selectedJob['salary']))
            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:#EDE0F5; border-color:#c9ace0;">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:#059669;">
                    <i class="fas fa-money-bill-wave text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Salary</p>
                    <p class="text-sm font-semibold" style="color:#5c2d7a;">{{ $selectedJob['salary'] }}</p>
                </div>
            </div>
            @endif

            <div class="rounded-xl border p-3 flex items-center gap-3"
                 style="background:{{ $jobUrgent ? '#FFF5F5' : '#F9F7FC' }}; border-color:{{ $jobUrgent ? '#FECACA' : '#E8E0F0' }};">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                     style="background:{{ $jobUrgent ? '#DC2626' : '#d97706' }};">
                    <i class="fas fa-{{ $jobUrgent ? 'fire' : 'calendar' }} text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Application Deadline</p>
                    <p class="text-sm font-semibold {{ $jobUrgent ? 'text-red-600' : 'text-[#333333]' }}">
                        {{ $selectedJob['deadline'] ?? '—' }}
                        @if($jobUrgent)
                        <span class="text-xs font-normal text-red-400 ml-1">({{ $selectedJob['days_left'] }} day(s) left)</span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="pt-1">
                <button wire:click="closeModal"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                        style="background:#7A3F91;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- end root --}}