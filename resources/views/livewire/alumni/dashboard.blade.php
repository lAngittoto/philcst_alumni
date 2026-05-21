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

    // ── Events modal pagination ───────────────────────────────────
    public int $eventModalPage     = 1;
    public int $eventModalPageSize = 20;

    // ── RSVPs modal pagination ────────────────────────────────────
    public int $rsvpModalPage     = 1;
    public int $rsvpModalPageSize = 20;

    // ── Detail view state ─────────────────────────────────────────
    public array $selectedEvent = [];
    public array $selectedJob   = [];

    // ── All events & jobs for panels (limited to 5 recent) ───────
    public array $allPanelEvents = [];
    public array $allPanelJobs   = [];

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
        $now     = Carbon::now('UTC');

        $this->upcomingEvents = Cache::remember("dash_upcoming_{$college}", 120, function () use ($college, $course, $now) {
            $admin = AdminEvent::withoutTrashed()
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                    ->orWhere('target_participants', 'like', "%{$college}%"))
                ->where('event_date', '>', $now)->count();
            $org = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
                ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                    ->orWhere('target_participants', 'like', "%{$course}%"))
                ->where('event_date', '>', $now)->count();
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

        $this->myRsvps = \App\Models\EventRsvp::where('alumni_id', $alumni->id)
            ->where('response', 'CONFIRMED')->count();

        // ── Load 5 most recent upcoming panel events ──────────────
        $adminEvts = AdminEvent::withoutTrashed()
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Colleges%')
                                ->orWhere('target_participants', 'like', "%{$college}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')
            ->limit(5)
            ->get(['id','title','event_date','event_end_date','venue','photo','description','notes','contact_person','contact_email','contact_phone','target_participants','organizer_id'])
            ->map(fn($e) => [
                'id'              => $e->id,
                'source'          => 'ADMIN',
                'title'           => $e->title,
                'date'            => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'            => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'        => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'           => $e->venue ?? '',
                'photo'           => $e->photo_url ?? '',
                'is_upcoming'     => true,
                'description'     => $e->description ?? '',
                'notes'           => $e->notes ?? '',
                'contact_person'  => $e->contact_person ?? '',
                'contact_email'   => $e->contact_email ?? '',
                'contact_phone'   => $e->contact_phone ?? '',
                'target_participants' => $e->target_participants ?? '',
                'organizer_name'  => 'PHILCST Admin',
            ])->toArray();

        $orgEvts = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"))
            ->where('event_date', '>', $now)->orderBy('event_date')
            ->limit(5)
            ->get(['id','title','event_date','event_end_date','venue','photo','description','notes','contact_person','contact_email','contact_phone','target_participants','organizer_id'])
            ->map(fn($e) => [
                'id'              => $e->id,
                'source'          => 'ORGANIZER',
                'title'           => $e->title,
                'date'            => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'            => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'        => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'           => $e->venue ?? '',
                'photo'           => $e->photo_url ?? '',
                'is_upcoming'     => true,
                'description'     => $e->description ?? '',
                'notes'           => $e->notes ?? '',
                'contact_person'  => $e->contact_person ?? '',
                'contact_email'   => $e->contact_email ?? '',
                'contact_phone'   => $e->contact_phone ?? '',
                'target_participants' => $e->target_participants ?? '',
                'organizer_name'  => $e->organizer?->name ?? 'Organizer',
            ])->toArray();

        $this->allPanelEvents = collect(array_merge($adminEvts, $orgEvts))
            ->sortBy('date')->take(5)->values()->toArray();

        $this->recentEvents = $this->allPanelEvents;

        // ── Load 5 most recent active panel jobs ──────────────────
        $this->allPanelJobs = JobPosting::where('status', 'ACTIVE')
            ->where(fn($q) => $q->whereNull('target_college')
                                ->orWhere('target_college', '')
                                ->orWhere('target_college', 'like', "%{$college}%"))
            ->where('deadline', '>=', now('Asia/Manila')->toDateString())
            ->orderByDesc('created_at')
            ->limit(5)
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
                'description'             => '',
                'qualifications'          => '',
                'application_instructions'=> '',
                'contact_person'          => '',
                'contact_email'           => '',
                'contact_phone'           => '',
            ])->toArray();

        $this->recentJobs = $this->allPanelJobs;
    }

    // ── Fetch all events for modal ────────────────────────────────
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
            ->get(['id','title','event_date','event_end_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ADMIN',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'    => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => $e->event_date->gt($now),
            ])->toArray();

        $orgQ = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"));
        if ($upcomingOnly) $orgQ->where('event_date', '>', $now);

        $orgEvts = $orgQ->orderBy('event_date')
            ->get(['id','title','event_date','event_end_date','venue','photo'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ORGANIZER',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'    => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'is_upcoming' => $e->event_date->gt($now),
            ])->toArray();

        return collect(array_merge($adminEvts, $orgEvts))->sortBy('date')->values()->toArray();
    }

    public function openUpcomingEventsModal(): void
    {
        $this->eventModalTitle = 'Upcoming Events';
        $this->modalEvents     = $this->fetchAllEvents(upcomingOnly: true);
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
        $this->activeModal     = 'events';
    }

    public function openTotalEventsModal(): void
    {
        $this->eventModalTitle = 'All Events';
        $this->modalEvents     = $this->fetchAllEvents(upcomingOnly: false);
        $this->eventSearch     = '';
        $this->eventModalPage  = 1;
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
    public function updatingEventSearch(): void { $this->eventModalPage = 1; }
    public function jobPrevPage(): void        { if ($this->jobModalPage > 1) $this->jobModalPage--; }
    public function jobNextPage(int $lastPage): void
    {
        if ($this->jobModalPage < $lastPage) $this->jobModalPage++;
    }
    public function eventPrevPage(): void { if ($this->eventModalPage > 1) $this->eventModalPage--; }
    public function eventNextPage(int $lastPage): void
    {
        if ($this->eventModalPage < $lastPage) $this->eventModalPage++;
    }
    public function rsvpPrevPage(): void { if ($this->rsvpModalPage > 1) $this->rsvpModalPage--; }
    public function rsvpNextPage(int $lastPage): void
    {
        if ($this->rsvpModalPage < $lastPage) $this->rsvpModalPage++;
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
                $event  = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])->find($r->event_id);
                $source = 'ORGANIZER';
            } else {
                $event = AdminEvent::withoutTrashed()->find($r->event_id ?? 0);
                if (!$event && !empty($r->event_id)) {
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

        $this->modalRsvps   = $result;
        $this->rsvpModalPage = 1;
        $this->activeModal  = 'rsvps';
    }

    public function openEventDetail(int $id, string $source): void
    {
        foreach ($this->allPanelEvents as $evt) {
            if ($evt['id'] === $id && $evt['source'] === $source) {
                $this->selectedEvent = $evt;
                $this->activeModal   = 'event_detail';
                return;
            }
        }
        $now   = Carbon::now('UTC');
        $event = $source === 'ORGANIZER'
            ? OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])->find($id)
            : AdminEvent::withoutTrashed()->find($id);
        if ($event) {
            $this->selectedEvent = [
                'id'              => $event->id,
                'source'          => $source,
                'title'           => $event->title,
                'date'            => $event->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'time'            => $event->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'        => $event->event_end_date ? $event->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'           => $event->venue ?? '',
                'photo'           => $event->photo_url ?? '',
                'is_upcoming'     => $event->event_date->gt($now),
                'description'     => $event->description ?? '',
                'notes'           => $event->notes ?? '',
                'contact_person'  => $event->contact_person ?? '',
                'contact_email'   => $event->contact_email ?? '',
                'contact_phone'   => $event->contact_phone ?? '',
                'target_participants' => $event->target_participants ?? '',
                'organizer_name'  => $source === 'ADMIN' ? 'PHILCST Admin' : ($event->organizer?->name ?? 'Organizer'),
            ];
            $this->activeModal = 'event_detail';
        }
    }

    public function openJobDetail(int $id): void
    {
        $j = JobPosting::find($id);
        if ($j) {
            $deadline = Carbon::parse($j->deadline)->setTimezone('Asia/Manila');
            $postedAt = $j->created_at ? Carbon::parse($j->created_at)->setTimezone('Asia/Manila') : null;
            $this->selectedJob = [
                'id'                       => $j->id,
                'title'                    => $j->job_title,
                'company'                  => $j->company_name,
                'type'                     => $j->employment_type,
                'experience'               => $this->safeColumn($j, 'experience_level'),
                'location'                 => $j->location ?? '',
                'salary'                   => $j->salary   ?? '',
                'deadline'                 => $deadline->format('M d, Y'),
                'days_left'                => (int) now('Asia/Manila')->startOfDay()->diffInDays(
                    $deadline->copy()->startOfDay(), false
                ),
                'posted_at'                => $postedAt ? $postedAt->format('M d, Y') : '',
                'posted_ago'               => $postedAt ? $postedAt->diffForHumans(now('Asia/Manila')) : '',
                'target_college'           => $this->safeColumn($j, 'target_college'),
                'status'                   => $j->status ?? 'ACTIVE',
                // ── FIX 1: correct field names matching the JobPosting model ──
                'description'              => $this->safeColumn($j, 'description'),
                'qualifications'           => $this->safeColumn($j, 'qualifications'),
                'application_instructions' => $this->safeColumn($j, 'application_instructions'),
                'contact_person'           => $this->safeColumn($j, 'contact_person'),
                'contact_email'            => $this->safeColumn($j, 'contact_email'),
                'contact_phone'            => $this->safeColumn($j, 'contact_phone'),
            ];
            $this->activeModal = 'job_detail';
        }
    }

    /** Safely read a model attribute that may not exist in the DB schema yet. */
    private function safeColumn($model, string $column): string
    {
        try {
            return $model->$column ?? '';
        } catch (\Throwable) {
            return '';
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

{{-- ══ Global cursor-following tooltip ══ --}}
<div id="alumni-float-tip"></div>

<style>
    /* ── Animations ──────────────────────────────────────────── */
    @keyframes dashModalIn {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .dash-modal-enter { animation: dashModalIn .22s cubic-bezier(.4,0,.2,1) both; }

    /* ── Cursor-following tooltip ────────────────────────────── */
    #alumni-float-tip {
        position: fixed;
        background: #1a1a1a;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .05em;
        padding: 5px 11px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 99999;
        box-shadow: 0 4px 14px rgba(0,0,0,.35);
        transform: translate(-50%, calc(-100% - 10px));
    }

    /* ── Stat cards ──────────────────────────────────────────── */
    .stat-card {
        position: relative;
        overflow: visible;
        transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease;
        cursor: pointer;
    }
    .stat-card:active { transform: scale(.985); }

    .dash-hover-tip {
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: #1a1a1a;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .05em;
        padding: 5px 11px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 200;
        box-shadow: 0 4px 14px rgba(0,0,0,.30);
    }
    .dash-hover-tip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 5px solid transparent;
        border-top-color: #1a1a1a;
    }
    .stat-card:hover .dash-hover-tip { opacity: 1; }

    /* ── List items ──────────────────────────────────────────── */
    .dash-list-item    { transition: background .12s ease, border-color .12s ease; }
    .dash-list-item:hover { background:#faf7ff; border-color:#d9c9e8; }

    /* ── FIX 2: Eye-icon "View Details" badge (shown on hover) ── */
    .dash-list-item .view-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 9px;
        font-weight: 700;
        padding: 3px 7px;
        border-radius: 6px;
        background: #ede0f5;
        color: #7A3F91;
        border: 1px solid #d4aaeb;
        opacity: 0;
        transition: opacity .15s ease;
        white-space: nowrap;
        flex-shrink: 0;
        align-self: center;
    }
    .dash-list-item:hover .view-badge { opacity: 1; }

    /* ── Table row hover ─────────────────────────────────────── */
    .dash-table-row { transition: background .10s; }
    .dash-table-row:hover { background: #F5F0FA !important; }

    /* ── Close button ────────────────────────────────────────── */
    .dash-close-btn {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.2);
        color: #fff;
        cursor: pointer;
        transition: background .15s;
        overflow: visible;
    }
    .dash-close-btn:hover { background: rgba(255,255,255,.22); }
    .dash-close-tip {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: rgba(27,6,46,.88);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 200;
        box-shadow: 0 4px 12px rgba(0,0,0,.28);
    }
    .dash-close-tip::before {
        content: '';
        position: absolute;
        bottom: 100%;
        right: 10px;
        border: 5px solid transparent;
        border-bottom-color: rgba(27,6,46,.88);
    }
    .dash-close-btn:hover .dash-close-tip { opacity: 1; }

    /* ── Modal pagination buttons ────────────────────────────── */
    .dash-pg-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: .75rem;
        font-weight: 700;
        transition: all .15s;
        border: 1.5px solid transparent;
    }
    .dash-pg-active { background: rgba(255,255,255,1); color: #7A3F91; border-color: rgba(255,255,255,1); }
    .dash-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
    .dash-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
    .dash-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

    /* ── Scrollbar ───────────────────────────────────────────── */
    .dash-scroll { scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb; }
    .dash-scroll::-webkit-scrollbar { width: 4px; }
    .dash-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

    /* ── Panel scrollable list ───────────────────────────────── */
    .panel-scroll {
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #e0d0ef #f9f7fc;
    }
    .panel-scroll::-webkit-scrollbar { width: 4px; }
    .panel-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

    /* ── DASHBOARD: 90vh total, panels fill the rest ─────────── */
    .dash-root {
        height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .dash-main-grid {
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }

    .dash-profile-col {
        height: 100%;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #e0d0ef #f9f7fc;
    }
    .dash-profile-col::-webkit-scrollbar { width: 4px; }
    .dash-profile-col::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

    .dash-right-col {
        height: 100%;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        overflow: hidden;
    }

    .dash-employment-card {
        flex-shrink: 0;
    }

    .dash-panel-row {
        flex: 1;
        min-height: 0;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    @media (max-width: 639px) {
        .dash-panel-row { grid-template-columns: 1fr; }
    }

    .dash-panel {
        height: 100%;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    /* ── Full-screen detail modal ────────────────────────────── */
    @keyframes slideInFull {
        from { opacity:0; }
        to   { opacity:1; }
    }
    .fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }
    .scroll-c::-webkit-scrollbar { width: 5px; }
    .scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
    .scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
    .scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }
    .meta-row-icon {
        width: 2.25rem; height: 2.25rem; border-radius: 0.625rem;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .meta-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #555555; margin-bottom: 0.2rem; }
    .meta-value { font-size: 0.975rem; font-weight: 700; color: #333333; line-height: 1.3; }
    .meta-sub   { font-size: 0.875rem; color: #333333; margin-top: 0.15rem; }

    /* ── Job detail card styles ──────────────────────────────── */
    .job-detail-meta-card {
        background: #fff;
        border: 1px solid #E8E0F0;
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .job-detail-meta-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #999999;
        margin-bottom: 2px;
    }
    .job-detail-meta-value {
        font-size: 0.9rem;
        font-weight: 700;
        color: #222222;
        line-height: 1.3;
    }
    .job-detail-meta-sub {
        font-size: 0.78rem;
        color: #777777;
        font-weight: 500;
        margin-top: 1px;
    }
    .job-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 99px;
        border: 1px solid transparent;
    }
</style>

{{-- ═══ DASHBOARD ROOT (90vh) ═══════════════════════════════════ --}}
<div class="dash-root px-3 sm:px-5 lg:px-6 pt-4 pb-4 max-w-screen-2xl mx-auto">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex items-center gap-3 mb-4 shrink-0">
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
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 shrink-0">

        {{-- Upcoming Events --}}
        <div wire:click="openUpcomingEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                    hover:shadow-md hover:border-[#2563eb]/40">
            <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Upcoming Events</span>
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

        {{-- Total Events --}}
        <div wire:click="openTotalEventsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                    hover:shadow-md hover:border-[#059669]/40">
            <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View All Events</span>
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

        {{-- Active Jobs --}}
        <div wire:click="openJobsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                    hover:shadow-md hover:border-[#d97706]/40">
            <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Active Job Posts</span>
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

        {{-- My RSVPs --}}
        <div wire:click="openRsvpsModal"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                    hover:shadow-md hover:border-[#0891b2]/40">
            <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View My RSVPs</span>
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

    {{-- ═══ MAIN GRID (fills remaining 90vh space) ══════════════════ --}}
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

    <div class="dash-main-grid grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- ─── LEFT COL: Profile Overview ──────────────────────── --}}
        <div class="lg:col-span-1 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden dash-profile-col">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] sticky top-0 z-10"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#7A3F91;">
                        <i class="fas fa-user text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">My Profile</p>
                </div>
            </div>

            <div class="p-4 flex flex-col gap-3">

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
                    <div class="rounded-xl border p-3"
                         style="background:#F9F7FC; border-color:#E8E0F0;">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background:#2563eb;">
                                    <i class="fas fa-book-open text-white text-xs"></i>
                                </div>
                                <span class="text-sm font-semibold text-[#333333]">Course</span>
                            </div>
                            <span class="text-sm font-semibold text-[#7A3F91] font-mono">{{ $alumniCourseCode ?: '—' }}</span>
                        </div>
                        @if($alumniCourseFull)
                        <p class="text-xs text-[#888888] font-normal mt-1.5 pl-9 leading-snug">{{ $alumniCourseFull }}</p>
                        @endif
                    </div>

                    <div class="rounded-xl border p-3 flex items-center justify-between"
                         style="background:#EDE0F5; border-color:#c9ace0;">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background:#059669;">
                                <i class="fas fa-calendar-check text-white text-xs"></i>
                            </div>
                            <span class="text-sm font-semibold text-[#333333]">Batch</span>
                        </div>
                        <span class="text-sm font-semibold" style="color:#5c2d7a;">{{ $alumniBatch ?: '—' }}</span>
                    </div>

                    @if($alumniCollege)
                    <div class="rounded-xl border p-3" style="background:#F5EDF9; border-color:#dbbcef;">
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background:#d97706;">
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
        <div class="lg:col-span-2 dash-right-col">

            {{-- ── Employment Card ─────────────────────────────────── --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden dash-employment-card">
                <div class="px-5 py-3.5 border-b border-[#E8E0F0] rounded-t-2xl"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#4f46e5;">
                                <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Employment Status</p>
                        </div>
                        @if(!$hasEmployment)
                        <a href="{{ route('alumni.employment') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90"
                           style="background:#7A3F91;">
                            <i class="fas fa-plus text-xs"></i> Add Now
                        </a>
                        @endif
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
                                <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5">Job Title</p>
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $jobTitle }}</p>
                                @endif
                                @if($companyName)
                                <p class="text-xs font-semibold text-[#999999] uppercase tracking-wide leading-none mb-0.5 mt-1.5">Company</p>
                                <p class="text-xs text-[#666666] font-semibold truncate uppercase">{{ $companyName }}</p>
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
                                <p class="text-xs text-[#999999] font-normal">Click "Add Now" above to add your employment information.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Events + Jobs Side by Side (fills remaining height) --}}
            <div class="dash-panel-row">

                {{-- ─── Upcoming Events Panel ─── --}}
                <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden dash-panel">
                    <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between shrink-0"
                         style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#0891b2;">
                                <i class="fas fa-calendar-check text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Upcoming Events</p>
                        </div>
                        @if(count($allPanelEvents) > 0)
                        <button wire:click="openUpcomingEventsModal"
                                class="text-xs font-semibold px-2.5 py-1 rounded-full transition hover:opacity-80"
                                style="background:#F9F7FC; color:#7A3F91; border:1px solid #E8E0F0;">
                            View all
                        </button>
                        @endif
                    </div>

                    {{-- FIX 2: Eye icon View Details badge on each event item --}}
                    <div class="flex-1 overflow-y-auto panel-scroll p-3 flex flex-col gap-2 min-h-0">
                        @forelse($allPanelEvents as $evt)
                        <div class="dash-list-item border border-[#E8E0F0] rounded-xl p-2.5 cursor-pointer group"
                             wire:click="openEventDetail({{ $evt['id'] }}, '{{ $evt['source'] }}')">
                            <div class="flex items-start gap-2.5">
                                <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden" style="background:#f0e6f8;">
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
                                    <p class="text-xs font-semibold text-[#555555] flex items-center gap-1 mt-0.5">
                                        <i class="fas fa-calendar text-[10px]" style="color:#0891b2;"></i>
                                        {{ $evt['date'] }} &bull; {{ $evt['time'] }}@if(!empty($evt['end_time'])) – {{ $evt['end_time'] }}@endif
                                    </p>
                                    @if(!empty($evt['venue']))
                                    <p class="text-xs text-[#AAAAAA] font-normal truncate flex items-center gap-1 mt-0.5">
                                        <i class="fas fa-location-dot text-[10px]"></i>{{ $evt['venue'] }}
                                    </p>
                                    @endif
                                </div>
                                {{-- FIX 2: Eye badge --}}
                                <span class="view-badge">
                                    <i class="fas fa-eye" style="font-size:8px;"></i> View
                                </span>
                            </div>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-10 text-center flex-1">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" style="background:#f0e6f8;">
                                <i class="fas fa-calendar-days text-base" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#999999]">No upcoming events.</p>
                        </div>
                        @endforelse
                    </div>
                    {{-- FIX 3: Footer "Showing X of X / See all" REMOVED --}}
                </div>

                {{-- ─── Latest Jobs Panel ─── --}}
                <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden dash-panel">
                    <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between shrink-0"
                         style="background:linear-gradient(135deg,#F5EDF9,#FFFFFF);">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:#e11d48;">
                                <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Latest Jobs</p>
                        </div>
                        @if(count($allPanelJobs) > 0)
                        <button wire:click="openJobsModal"
                                class="text-xs font-semibold px-2.5 py-1 rounded-full transition hover:opacity-80"
                                style="background:#F9F7FC; color:#7A3F91; border:1px solid #E8E0F0;">
                            View all
                        </button>
                        @endif
                    </div>

                    {{-- FIX 2: Eye icon View Details badge on each job item --}}
                    <div class="flex-1 overflow-y-auto panel-scroll p-3 flex flex-col gap-2 min-h-0">
                        @forelse($allPanelJobs as $job)
                        @php $isUrgent = ($job['days_left'] ?? 99) <= 7; @endphp
                        <div class="dash-list-item border border-[#E8E0F0] rounded-xl p-2.5 cursor-pointer group"
                             wire:click="openJobDetail({{ $job['id'] }})">

                            <div class="flex items-start justify-between gap-2 mb-1">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-[#333333] truncate leading-snug">{{ $job['title'] }}</p>
                                    <p class="text-xs text-[#555555] font-normal truncate">{{ $job['company'] }}</p>
                                </div>
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    {{-- FIX 2: Eye badge --}}
                                    <span class="view-badge">
                                        <i class="fas fa-eye" style="font-size:8px;"></i> View
                                    </span>
                                    <span class="inline-flex items-center text-xs font-semibold px-1.5 py-0.5 rounded-full uppercase"
                                          style="background:#F5EDF9; color:#9b59b6; border:1px solid #dbbcef; font-size:9px;">
                                        {{ $job['type'] }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-1.5 border-t border-[#F0E8F8]">
                                @if($job['location'])
                                <p class="text-xs text-[#AAAAAA] font-normal truncate flex items-center gap-1">
                                    <i class="fas fa-location-dot text-[10px]"></i>{{ Str::limit($job['location'], 16) }}
                                </p>
                                @endif
                                <p class="text-xs font-semibold {{ $isUrgent ? 'text-red-600' : 'text-[#666666]' }} flex items-center gap-1 ml-auto">
                                    <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} text-[10px]"></i>
                                    {{ $job['deadline'] }}
                                </p>
                            </div>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-10 text-center flex-1">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-base" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-xs font-semibold text-[#999999]">No active job postings.</p>
                        </div>
                        @endforelse
                    </div>
                    {{-- FIX 3: Footer "Showing X of X / See all" REMOVED --}}
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
    $filteredModalEvents = collect($modalEvents)
        ->when($eventSearch !== '', fn($c) => $c->filter(fn($e) =>
            str_contains(strtolower($e['title']), strtolower($eventSearch)) ||
            str_contains(strtolower($e['venue'] ?? ''), strtolower($eventSearch))
        ))
        ->values();

    $evtModalTotal    = $filteredModalEvents->count();
    $evtModalLastPage = max((int) ceil($evtModalTotal / $eventModalPageSize), 1);
    $evtModalSafePage = min($eventModalPage, $evtModalLastPage);
    $evtModalFrom     = $evtModalTotal > 0 ? ($evtModalSafePage - 1) * $eventModalPageSize + 1 : 0;
    $evtModalTo       = min($evtModalSafePage * $eventModalPageSize, $evtModalTotal);
    $displayEvents    = $filteredModalEvents->slice(($evtModalSafePage - 1) * $eventModalPageSize, $eventModalPageSize)->values()->toArray();

    $evtPgStart = max(1, $evtModalSafePage - 2);
    $evtPgEnd   = min($evtModalLastPage, $evtModalSafePage + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $evtModalTotal }} record(s) found</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dash-close-btn">
            <span class="dash-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{ if(v!==this.q) this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('eventSearch', q)"
                       placeholder="Search event title or venue…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                       autocomplete="off">
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <table class="w-full border-collapse" style="min-width:560px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-16">Photo</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event Title</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Time</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Venue</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEvents as $idx => $evt)
                <tr class="dash-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($evtModalFrom + $idx,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
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
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-900">{{ $evt['title'] }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['date'] }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-800">{{ $evt['time'] }}</p>
                        @if(!empty($evt['end_time']))
                        <p class="text-xs text-gray-400 font-normal">– {{ $evt['end_time'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <p class="text-sm text-gray-500">{{ $evt['venue'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($evt['is_upcoming'] ?? true)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                  style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                                <i class="fas fa-clock text-[10px]"></i> Upcoming
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-700 border-green-200">
                                <i class="fas fa-circle-check text-[10px]"></i> Completed
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-calendar-days text-xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No events found</p>
                        <p class="text-xs text-gray-300 font-normal">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7A3F91;">
        <p class="text-white/70 text-sm">
            Showing <strong class="text-white font-semibold">{{ $evtModalFrom }}–{{ $evtModalTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($evtModalTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($evtModalSafePage <= 1)
                    wire:click="eventPrevPage"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>

            @if($evtPgStart > 1)
                <button wire:click="$set('eventModalPage', 1)" class="dash-pg-btn dash-pg-nav">1</button>
                @if($evtPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif

            @for($p = $evtPgStart; $p <= $evtPgEnd; $p++)
                @if($p === $evtModalSafePage)
                    <span class="dash-pg-btn dash-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('eventModalPage', {{ $p }})" class="dash-pg-btn dash-pg-nav">{{ $p }}</button>
                @endif
            @endfor

            @if($evtPgEnd < $evtModalLastPage)
                @if($evtPgEnd < $evtModalLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('eventModalPage', {{ $evtModalLastPage }})" class="dash-pg-btn dash-pg-nav">{{ $evtModalLastPage }}</button>
            @endif

            <button @disabled($evtModalSafePage >= $evtModalLastPage)
                    wire:click="eventNextPage({{ $evtModalLastPage }})"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>

            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $evtModalSafePage }}/{{ $evtModalLastPage }}</span>
        </div>
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

    $displayJobs = $filteredJobs->slice(($jobSafePage - 1) * $jobModalPageSize, $jobModalPageSize)->values()->toArray();

    $jPgStart = max(1, $jobSafePage - 2);
    $jPgEnd   = min($jobLastPage, $jobSafePage + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">Active Job Postings</h2>
                <p class="text-white/60 text-xs font-normal">{{ $jobTotalCount }} record(s) found</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dash-close-btn">
            <span class="dash-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{ if(v!==this.q) this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('jobSearch', q)"
                       placeholder="Search title, company, location…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                       autocomplete="off">
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll relative">
        <table class="w-full border-collapse" style="min-width:600px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Job Title</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Company</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Type</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Location</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Salary</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayJobs as $idx => $job)
                @php
                    $rowNum   = $jobFrom + $idx;
                    $isUrgent = ($job['days_left'] ?? 99) <= 7;
                @endphp
                <tr class="dash-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-900 truncate" style="max-width:200px;">{{ $job['title'] }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm text-gray-600 truncate" style="max-width:160px;">{{ $job['company'] }}</p>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                            {{ $job['type'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <p class="text-sm text-gray-500">{{ $job['location'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <p class="text-sm font-semibold" style="color:#7A3F91;">{{ $job['salary'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 text-center">
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
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No active job postings</p>
                        <p class="text-xs text-gray-300 font-normal">Try adjusting your search</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7A3F91;">
        <p class="text-white/70 text-sm">
            Showing <strong class="text-white font-semibold">{{ $jobFrom }}–{{ $jobTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($jobTotalCount) }}</strong> records
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($jobSafePage <= 1)
                    wire:click="jobPrevPage"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>

            @if($jPgStart > 1)
                <button wire:click="$set('jobModalPage', 1)" class="dash-pg-btn dash-pg-nav">1</button>
                @if($jPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif

            @for($p = $jPgStart; $p <= $jPgEnd; $p++)
                @if($p === $jobSafePage)
                    <span class="dash-pg-btn dash-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('jobModalPage', {{ $p }})" class="dash-pg-btn dash-pg-nav">{{ $p }}</button>
                @endif
            @endfor

            @if($jPgEnd < $jobLastPage)
                @if($jPgEnd < $jobLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('jobModalPage', {{ $jobLastPage }})" class="dash-pg-btn dash-pg-nav">{{ $jobLastPage }}</button>
            @endif

            <button @disabled($jobSafePage >= $jobLastPage)
                    wire:click="jobNextPage({{ $jobLastPage }})"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>

            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $jobSafePage }}/{{ $jobLastPage }}</span>
        </div>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: MY RSVPs
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'rsvps')
@php
    $rsvpTotal    = count($modalRsvps);
    $rsvpLastPage = max((int) ceil($rsvpTotal / $rsvpModalPageSize), 1);
    $rsvpSafePage = min($rsvpModalPage, $rsvpLastPage);
    $rsvpFrom     = $rsvpTotal > 0 ? ($rsvpSafePage - 1) * $rsvpModalPageSize + 1 : 0;
    $rsvpTo       = min($rsvpSafePage * $rsvpModalPageSize, $rsvpTotal);
    $displayRsvps = array_slice($modalRsvps, ($rsvpSafePage - 1) * $rsvpModalPageSize, $rsvpModalPageSize);

    $rsvpPgStart = max(1, $rsvpSafePage - 2);
    $rsvpPgEnd   = min($rsvpLastPage, $rsvpSafePage + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
         style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">My Confirmed RSVPs</h2>
                <p class="text-white/60 text-xs font-normal">{{ $rsvpTotal }} record(s) found</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dash-close-btn">
            <span class="dash-close-tip">Close</span>
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center justify-end">
            <span class="text-xs text-gray-400 font-normal">
                <strong class="text-gray-600">{{ $rsvpTotal }}</strong> confirmed RSVP(s)
            </span>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto min-h-0 dash-scroll">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-16">Photo</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Event Date</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Venue</th>
                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayRsvps as $idx => $rsvp)
                <tr class="dash-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rsvpFrom + $idx,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3">
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
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-900">{{ $rsvp['title'] }}</p>
                        <p class="text-xs text-gray-400 font-normal mt-0.5">RSVP'd: {{ $rsvp['rsvp_date'] }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-sm font-semibold text-gray-800">{{ $rsvp['date'] }}</p>
                        <p class="text-xs text-gray-400 font-normal">{{ $rsvp['time'] }}</p>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <p class="text-sm text-gray-500">{{ $rsvp['venue'] ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($rsvp['is_upcoming'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                  style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                                <i class="fas fa-circle-check text-[10px]"></i> Confirmed
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-700 border-green-200">
                                <i class="fas fa-circle-check text-[10px]"></i> Attended
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-circle-check text-xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No confirmed RSVPs yet</p>
                        <p class="text-xs text-gray-300 font-normal">RSVP to upcoming events to see them here</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7A3F91;">
        <p class="text-white/70 text-sm">
            Showing <strong class="text-white font-semibold">{{ $rsvpFrom }}–{{ $rsvpTo }}</strong>
            of <strong class="text-white font-semibold">{{ number_format($rsvpTotal) }}</strong> RSVP(s)
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($rsvpSafePage <= 1)
                    wire:click="rsvpPrevPage"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>

            @if($rsvpPgStart > 1)
                <button wire:click="$set('rsvpModalPage', 1)" class="dash-pg-btn dash-pg-nav">1</button>
                @if($rsvpPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif

            @for($p = $rsvpPgStart; $p <= $rsvpPgEnd; $p++)
                @if($p === $rsvpSafePage)
                    <span class="dash-pg-btn dash-pg-active">{{ $p }}</span>
                @else
                    <button wire:click="$set('rsvpModalPage', {{ $p }})" class="dash-pg-btn dash-pg-nav">{{ $p }}</button>
                @endif
            @endfor

            @if($rsvpPgEnd < $rsvpLastPage)
                @if($rsvpPgEnd < $rsvpLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('rsvpModalPage', {{ $rsvpLastPage }})" class="dash-pg-btn dash-pg-nav">{{ $rsvpLastPage }}</button>
            @endif

            <button @disabled($rsvpSafePage >= $rsvpLastPage)
                    wire:click="rsvpNextPage({{ $rsvpLastPage }})"
                    class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>

            <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $rsvpSafePage }}/{{ $rsvpLastPage }}</span>
        </div>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'event_detail' && !empty($selectedEvent))
@php
    $evtUpcoming    = $selectedEvent['is_upcoming'] ?? false;
    $evtHasPhoto    = !empty($selectedEvent['photo']);
    $evtTimeDisplay = ($selectedEvent['time'] ?? '') . (!empty($selectedEvent['end_time']) ? ' – ' . $selectedEvent['end_time'] : '');
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-[10px] font-semibold uppercase tracking-widest truncate">
                    {{ $selectedEvent['organizer_name'] ?? ($selectedEvent['source'] === 'ADMIN' ? 'PHILCST Admin' : 'Organizer') }}
                </p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $selectedEvent['title'] ?? '' }}</h2>
            </div>
        </div>
        <button wire:click="closeModal" type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer ml-3 flex-shrink-0">
            <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            @if($evtHasPhoto)
            <div class="w-full px-4 pt-4 pb-2 shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $selectedEvent['photo'] }}" alt="{{ $selectedEvent['title'] }}"
                         class="w-full object-contain" style="max-height:190px; display:block;">
                    <div class="absolute top-2 right-2">
                        @if($evtUpcoming)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-calendar-check text-[9px]"></i> Upcoming</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Completed</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:90px; background:linear-gradient(135deg,#7A3F91 0%,#4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($evtUpcoming)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Upcoming</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-semibold">Completed</span>
                    @endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100"><i class="fas fa-calendar text-violet-600 text-base"></i></span>
                    <div>
                        <p class="meta-label">Date &amp; Time</p>
                        <p class="meta-value">{{ $selectedEvent['date'] ?? '' }}</p>
                        <p class="meta-sub">{{ $evtTimeDisplay }}</p>
                    </div>
                </div>

                @if(!empty($selectedEvent['venue']))
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Venue</p>
                        <p class="meta-value truncate">{{ $selectedEvent['venue'] }}</p>
                    </div>
                </div>
                @endif

                @if(!empty($selectedEvent['target_participants']))
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-purple-100"><i class="fas fa-users text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Open For</p>
                        <p class="meta-value line-clamp-2">{{ $selectedEvent['target_participants'] }}</p>
                    </div>
                </div>
                @endif

                @if(!empty($selectedEvent['contact_person']) || !empty($selectedEvent['contact_email']) || !empty($selectedEvent['contact_phone']))
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="meta-label mb-2">Contact</p>
                    <div class="flex flex-col gap-2">
                        @if(!empty($selectedEvent['contact_person']))
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-user text-purple-500 text-sm w-4"></i>
                            <span class="text-sm font-semibold" style="color:#333333;">{{ $selectedEvent['contact_person'] }}</span>
                        </div>
                        @endif
                        @if(!empty($selectedEvent['contact_email']))
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-envelope text-sky-500 text-sm w-4"></i>
                            <span class="text-sm truncate" style="color:#333333;">{{ $selectedEvent['contact_email'] }}</span>
                        </div>
                        @endif
                        @if(!empty($selectedEvent['contact_phone']))
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-phone text-emerald-500 text-sm w-4"></i>
                            <span class="text-sm" style="color:#333333;">{{ $selectedEvent['contact_phone'] }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                <div class="p-3 flex items-center gap-2"
                     style="background:{{ $evtUpcoming ? '#F9F7FC' : '#F0FDF4' }}; border-radius:.75rem; border:1px solid {{ $evtUpcoming ? '#E8E0F0' : '#BBF7D0' }};">
                    <i class="fas fa-{{ $evtUpcoming ? 'clock' : 'circle-check' }} text-sm"
                       style="color:{{ $evtUpcoming ? '#059669' : '#16a34a' }};"></i>
                    <span class="text-sm font-semibold" style="color:{{ $evtUpcoming ? '#059669' : '#16a34a' }};">
                        {{ $evtUpcoming ? 'Upcoming Event' : 'Completed Event' }}
                    </span>
                </div>

                <button wire:click="closeModal"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                        style="background:#7A3F91;">
                    <i class="fas fa-xmark text-xs"></i> Close
                </button>
            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Event Details</p>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-5 py-4 flex flex-col gap-4">

                @if(!empty($selectedEvent['description']))
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        About This Event
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($selectedEvent['description']) }}</div>
                </div>
                @endif

                @if(!empty($selectedEvent['notes']))
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-amber-50">
                            <i class="fas fa-list-check text-amber-500 text-[10px]"></i>
                        </span>
                        Additional Notes
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-amber-50/50 rounded-lg p-4 border border-amber-100" style="line-height:1.75; color:#333333;">{{ trim($selectedEvent['notes']) }}</div>
                </div>
                @endif

                @if(empty($selectedEvent['description']) && empty($selectedEvent['notes']))
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium" style="color:#555555;">No additional details provided.</p>
                    </div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: JOB DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'job_detail' && !empty($selectedJob))
@php
    $jobUrgent   = ($selectedJob['days_left'] ?? 99) <= 7;
    $jobIsActive = strtoupper($selectedJob['status'] ?? 'ACTIVE') === 'ACTIVE';
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-white overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeModal()">

    {{-- ── Top purple header bar ── --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-[10px] font-semibold uppercase tracking-widest truncate">
                    {{ $selectedJob['company'] ?? '' }}
                </p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $selectedJob['title'] ?? '' }}</h2>
            </div>
        </div>
        <button wire:click="closeModal" type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer ml-3 flex-shrink-0">
            <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- ── 4-column quick meta strip ── --}}
    <div class="shrink-0 border-b border-gray-100 bg-white">
        <div class="grid grid-cols-2 lg:grid-cols-4 divide-x divide-gray-100">

            {{-- Location --}}
            <div class="flex items-center gap-3 px-5 py-4">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:#FFF1F2;">
                    <i class="fas fa-location-dot text-sm" style="color:#e11d48;"></i>
                </div>
                <div class="min-w-0">
                    <p class="job-detail-meta-label">Location</p>
                    <p class="job-detail-meta-value truncate">{{ $selectedJob['location'] ?: 'Not specified' }}</p>
                </div>
            </div>

            {{-- Salary --}}
            <div class="flex items-center gap-3 px-5 py-4">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:#F0FDF4;">
                    <i class="fas fa-money-bill-wave text-sm" style="color:#16a34a;"></i>
                </div>
                <div class="min-w-0">
                    <p class="job-detail-meta-label">Salary</p>
                    <p class="job-detail-meta-value">{{ $selectedJob['salary'] ?: 'Not disclosed' }}</p>
                </div>
            </div>

            {{-- Deadline --}}
            <div class="flex items-center gap-3 px-5 py-4">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:{{ $jobUrgent ? '#FEF2F2' : '#FFFBEB' }};">
                    <i class="fas fa-{{ $jobUrgent ? 'fire' : 'calendar' }} text-sm"
                       style="color:{{ $jobUrgent ? '#DC2626' : '#D97706' }};"></i>
                </div>
                <div class="min-w-0">
                    <p class="job-detail-meta-label">Deadline</p>
                    <p class="job-detail-meta-value {{ $jobUrgent ? 'text-red-600' : '' }}">{{ $selectedJob['deadline'] ?? '—' }}</p>
                    @if($jobUrgent)
                        <p class="job-detail-meta-sub text-red-400">{{ $selectedJob['days_left'] }} days left</p>
                    @else
                        <p class="job-detail-meta-sub">{{ $selectedJob['days_left'] }} days left</p>
                    @endif
                </div>
            </div>

            {{-- Posted --}}
            <div class="flex items-center gap-3 px-5 py-4">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:#EFF6FF;">
                    <i class="fas fa-clock text-sm" style="color:#2563eb;"></i>
                </div>
                <div class="min-w-0">
                    <p class="job-detail-meta-label">Posted</p>
                    <p class="job-detail-meta-value">{{ $selectedJob['posted_at'] ?: '—' }}</p>
                    @if($selectedJob['posted_ago'])
                        <p class="job-detail-meta-sub">{{ $selectedJob['posted_ago'] }}</p>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- ── Tags row ── --}}
    <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-100 flex flex-wrap items-center gap-2">

        <span class="job-tag" style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
            <i class="fas fa-building text-[10px]"></i>
            PHILCST
        </span>

        @if(!empty($selectedJob['type']))
        <span class="job-tag" style="background:#EFF6FF; color:#2563eb; border-color:#BFDBFE;">
            <i class="fas fa-id-badge text-[10px]"></i>
            {{ $selectedJob['type'] }}
        </span>
        @endif

        @if(!empty($selectedJob['experience']))
        <span class="job-tag" style="background:#FEF3C7; color:#B45309; border-color:#FDE68A;">
            <i class="fas fa-layer-group text-[10px]"></i>
            {{ $selectedJob['experience'] }}
        </span>
        @endif

        @if(!empty($selectedJob['target_college']))
        <span class="job-tag" style="background:#FFF1F2; color:#e11d48; border-color:#FECDD3;">
            <i class="fas fa-graduation-cap text-[10px]"></i>
            {{ $selectedJob['target_college'] }}
        </span>
        @endif

    </div>

    {{-- ── Main scrollable body ── --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT sidebar --}}
        <div class="w-full lg:w-[280px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-100 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            <div class="p-4 flex flex-col gap-3">

                {{-- Status --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:{{ $jobIsActive ? '#F0FDF4' : '#F9F7FC' }}; border-color:{{ $jobIsActive ? '#BBF7D0' : '#E8E0F0' }};">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:{{ $jobIsActive ? '#dcfce7' : '#ede9fe' }};">
                        <i class="fas fa-{{ $jobIsActive ? 'circle-check' : 'circle-pause' }} text-sm"
                           style="color:{{ $jobIsActive ? '#16a34a' : '#7c3aed' }};"></i>
                    </div>
                    <div>
                        <p class="job-detail-meta-label">Status</p>
                        <p class="job-detail-meta-value" style="color:{{ $jobIsActive ? '#16a34a' : '#7c3aed' }};">
                            {{ $jobIsActive ? 'Active' : ucfirst(strtolower($selectedJob['status'] ?? '')) }}
                        </p>
                    </div>
                </div>

                {{-- Employment Type --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:#EFF6FF; border-color:#BFDBFE;">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DBEAFE;">
                        <i class="fas fa-id-badge text-sm" style="color:#2563eb;"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="job-detail-meta-label">Employment Type</p>
                        <p class="job-detail-meta-value">{{ $selectedJob['type'] ?? '—' }}</p>
                        @if(!empty($selectedJob['experience']))
                        <p class="job-detail-meta-sub">{{ $selectedJob['experience'] }}</p>
                        @endif
                    </div>
                </div>

                {{-- Company --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:#F9F7FC; border-color:#E8E0F0;">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#EDE0F5;">
                        <i class="fas fa-building text-sm" style="color:#7A3F91;"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="job-detail-meta-label">Company</p>
                        <p class="job-detail-meta-value truncate">{{ $selectedJob['company'] ?? '—' }}</p>
                        <p class="job-detail-meta-sub">PHILCST</p>
                    </div>
                </div>

                {{-- Location --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:#FFF1F2; border-color:#FECDD3;">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#FFE4E6;">
                        <i class="fas fa-location-dot text-sm" style="color:#e11d48;"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="job-detail-meta-label">Location</p>
                        <p class="job-detail-meta-value truncate">{{ $selectedJob['location'] ?: 'Not specified' }}</p>
                    </div>
                </div>

                {{-- Salary --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:#F0FDF4; border-color:#BBF7D0;">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#DCFCE7;">
                        <i class="fas fa-money-bill-wave text-sm" style="color:#16a34a;"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="job-detail-meta-label">Salary</p>
                        <p class="job-detail-meta-value" style="color:{{ $selectedJob['salary'] ? '#16a34a' : '#999999' }};">
                            {{ $selectedJob['salary'] ?: 'Not disclosed' }}
                        </p>
                    </div>
                </div>

                {{-- Deadline --}}
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:{{ $jobUrgent ? '#FEF2F2' : '#FFFBEB' }}; border-color:{{ $jobUrgent ? '#FECACA' : '#FDE68A' }};">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:{{ $jobUrgent ? '#FEE2E2' : '#FEF3C7' }};">
                        <i class="fas fa-{{ $jobUrgent ? 'fire' : 'calendar' }} text-sm"
                           style="color:{{ $jobUrgent ? '#DC2626' : '#D97706' }};"></i>
                    </div>
                    <div>
                        <p class="job-detail-meta-label">Application Deadline</p>
                        <p class="job-detail-meta-value {{ $jobUrgent ? 'text-red-600' : '' }}">{{ $selectedJob['deadline'] ?? '—' }}</p>
                        <p class="job-detail-meta-sub {{ $jobUrgent ? 'text-red-400' : '' }}">{{ $selectedJob['days_left'] }} day(s) left</p>
                    </div>
                </div>

                {{-- Target College --}}
                @if(!empty($selectedJob['target_college']))
                <div class="rounded-xl border p-4 flex items-center gap-3"
                     style="background:#FFF1F2; border-color:#FECDD3;">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#FFE4E6;">
                        <i class="fas fa-graduation-cap text-sm" style="color:#e11d48;"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="job-detail-meta-label">Target College</p>
                        <p class="job-detail-meta-value truncate" style="color:#e11d48;">{{ $selectedJob['target_college'] }}</p>
                    </div>
                </div>
                @endif

                {{-- Contact info --}}
                @if(!empty($selectedJob['contact_person']) || !empty($selectedJob['contact_email']) || !empty($selectedJob['contact_phone']))
                <div class="rounded-xl border p-4" style="background:#F9F7FC; border-color:#E8E0F0;">
                    <p class="job-detail-meta-label mb-2.5">Contact Information</p>
                    <div class="flex flex-col gap-2.5">
                        @if(!empty($selectedJob['contact_person']))
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#EDE0F5;">
                                <i class="fas fa-user text-[10px]" style="color:#7A3F91;"></i>
                            </div>
                            <span class="text-sm font-semibold" style="color:#333333;">{{ $selectedJob['contact_person'] }}</span>
                        </div>
                        @endif
                        @if(!empty($selectedJob['contact_email']))
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#EFF6FF;">
                                <i class="fas fa-envelope text-[10px]" style="color:#2563eb;"></i>
                            </div>
                            <span class="text-sm truncate" style="color:#333333;">{{ $selectedJob['contact_email'] }}</span>
                        </div>
                        @endif
                        @if(!empty($selectedJob['contact_phone']))
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#F0FDF4;">
                                <i class="fas fa-phone text-[10px]" style="color:#16a34a;"></i>
                            </div>
                            <span class="text-sm" style="color:#333333;">{{ $selectedJob['contact_phone'] }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Posted footer --}}
                @if($selectedJob['posted_at'])
                <p class="text-xs text-gray-400 font-normal text-center">
                    Posted {{ $selectedJob['posted_ago'] }} &bull; {{ $selectedJob['posted_at'] }}
                </p>
                @endif

                <button wire:click="closeModal"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                        style="background:#7A3F91;">
                    <i class="fas fa-xmark text-xs"></i> Close
                </button>
            </div>
        </div>

        {{-- RIGHT: Job content sections --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-100">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Job Details</p>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-5 py-4 flex flex-col gap-4">

                {{-- Job Description --}}
                @if(!empty($selectedJob['description']))
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-6 h-6 rounded-lg flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        Job Description
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($selectedJob['description']) }}</div>
                </div>
                @endif

                {{-- FIX 1: Qualifications — now correctly reads $selectedJob['qualifications'] --}}
                @if(!empty($selectedJob['qualifications']))
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-6 h-6 rounded-lg flex items-center justify-center bg-purple-50">
                            <i class="fas fa-list-check text-purple-500 text-[10px]"></i>
                        </span>
                        Qualifications
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-purple-50/50 rounded-lg p-4 border border-purple-100" style="line-height:1.75; color:#333333;">{{ trim($selectedJob['qualifications']) }}</div>
                </div>
                @endif

                {{-- FIX 1: How to Apply — now correctly reads $selectedJob['application_instructions'] --}}
                @if(!empty($selectedJob['application_instructions']))
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-6 h-6 rounded-lg flex items-center justify-center bg-emerald-50">
                            <i class="fas fa-paper-plane text-emerald-500 text-[10px]"></i>
                        </span>
                        How to Apply
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-emerald-50/50 rounded-lg p-4 border border-emerald-100" style="line-height:1.75; color:#333333;">{{ trim($selectedJob['application_instructions']) }}</div>
                </div>
                @endif

                @if(empty($selectedJob['description']) && empty($selectedJob['qualifications']) && empty($selectedJob['application_instructions']))
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium" style="color:#555555;">No additional details provided.</p>
                    </div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ Cursor-following tooltip script ══ --}}
<script>
(function () {
    'use strict';

    if (window._alumniCursorTipBound) return;
    window._alumniCursorTipBound = true;

    function getTip() {
        return document.getElementById('alumni-float-tip');
    }

    document.addEventListener('mousemove', function (e) {
        var tip = getTip();
        if (tip && tip._ctipVisible) {
            tip.style.left = e.clientX + 'px';
            tip.style.top  = e.clientY + 'px';
        }
    });

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest('[data-ctip]');
        if (!el) return;
        var tip = getTip();
        if (!tip) return;
        tip.textContent  = el.getAttribute('data-ctip');
        tip._ctipVisible = true;
        tip.style.opacity = '1';
    });

    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest('[data-ctip]');
        if (!el) return;
        var related = e.relatedTarget;
        if (related && el.contains(related)) return;
        var tip = getTip();
        if (!tip) return;
        tip._ctipVisible  = false;
        tip.style.opacity = '0';
    });

    document.addEventListener('livewire:navigating', function () {
        var tip = getTip();
        if (tip) { tip._ctipVisible = false; tip.style.opacity = '0'; }
    });
})();
</script>

</div>{{-- end root --}}