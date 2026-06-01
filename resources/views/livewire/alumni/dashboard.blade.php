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

    public string $activeModal     = '';
    public string $eventModalTitle = 'Events';
    public array  $modalEvents     = [];
    public array  $modalJobs       = [];
    public array  $modalRsvps      = [];

    public string $eventSearch       = '';
    public string $eventStatusFilter = '';
    public string $jobSearch         = '';
    public string $jobTypeFilter     = '';

    public int $jobModalPage     = 1;
    public int $jobModalPageSize = 20;
    public int $eventModalPage     = 1;
    public int $eventModalPageSize = 20;
    public int $rsvpModalPage     = 1;
    public int $rsvpModalPageSize = 20;

    public array $selectedEvent      = [];
    public array $selectedJob        = [];
    public array $selectedEmployment = [];

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
    }

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

        $adminEvts = $adminQ->orderByDesc('event_date')
            ->get(['id','title','event_date','event_end_date','venue','photo','description','status'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ADMIN',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'date_raw'    => $e->event_date->setTimezone('Asia/Manila')->format('F d, Y'),
                'date_ago'    => $e->event_date->setTimezone('Asia/Manila')->diffForHumans(),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'    => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'description' => $e->description ?? '',
                'status'      => $e->status ?? '',
                'is_upcoming' => $e->event_date->gt($now),
                'event_date_ts' => $e->event_date->timestamp,
            ])->toArray();

        $orgQ = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(fn($q) => $q->where('target_participants', 'like', 'All Courses%')
                                ->orWhere('target_participants', 'like', "%{$course}%"));
        if ($upcomingOnly) $orgQ->where('event_date', '>', $now);

        $orgEvts = $orgQ->orderByDesc('event_date')
            ->get(['id','title','event_date','event_end_date','venue','photo','description','status'])
            ->map(fn($e) => [
                'id'          => $e->id, 'source' => 'ORGANIZER',
                'title'       => $e->title,
                'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
                'date_raw'    => $e->event_date->setTimezone('Asia/Manila')->format('F d, Y'),
                'date_ago'    => $e->event_date->setTimezone('Asia/Manila')->diffForHumans(),
                'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
                'end_time'    => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'       => $e->venue ?? '',
                'photo'       => $e->photo_url ?? '',
                'description' => $e->description ?? '',
                'status'      => $e->status ?? '',
                'is_upcoming' => $e->event_date->gt($now),
                'event_date_ts' => $e->event_date->timestamp,
            ])->toArray();

        return collect(array_merge($adminEvts, $orgEvts))
            ->sortByDesc('event_date_ts')
            ->values()
            ->toArray();
    }

    public function openUpcomingEventsModal(): void
    {
        $this->eventModalTitle   = 'Upcoming Events';
        $this->modalEvents       = $this->fetchAllEvents(upcomingOnly: true);
        $this->eventSearch       = '';
        $this->eventStatusFilter = '';
        $this->eventModalPage    = 1;
        $this->activeModal       = 'events';
    }

    public function openTotalEventsModal(): void
    {
        $this->eventModalTitle   = 'All Events';
        $this->modalEvents       = $this->fetchAllEvents(upcomingOnly: false);
        $this->eventSearch       = '';
        $this->eventStatusFilter = '';
        $this->eventModalPage    = 1;
        $this->activeModal       = 'events';
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
            ->get(['id','job_title','company_name','employment_type','location','deadline','salary','created_at'])
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
                'posted_ago' => $j->created_at ? Carbon::parse($j->created_at)->setTimezone('Asia/Manila')->diffForHumans() : '',
            ])->toArray();

        $this->jobSearch    = '';
        $this->jobTypeFilter = '';
        $this->jobModalPage = 1;
        $this->activeModal  = 'jobs';
    }

    public function updatingJobSearch(): void        { $this->jobModalPage = 1; }
    public function updatingJobTypeFilter(): void    { $this->jobModalPage = 1; }
    public function updatingEventSearch(): void      { $this->eventModalPage = 1; }
    public function updatingEventStatusFilter(): void { $this->eventModalPage = 1; }
    public function jobPrevPage(): void              { if ($this->jobModalPage > 1) $this->jobModalPage--; }
    public function jobNextPage(int $lastPage): void {
        if ($this->jobModalPage < $lastPage) $this->jobModalPage++;
    }
    public function eventPrevPage(): void { if ($this->eventModalPage > 1) $this->eventModalPage--; }
    public function eventNextPage(int $lastPage): void {
        if ($this->eventModalPage < $lastPage) $this->eventModalPage++;
    }
    public function rsvpPrevPage(): void { if ($this->rsvpModalPage > 1) $this->rsvpModalPage--; }
    public function rsvpNextPage(int $lastPage): void {
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
                'event_id'    => $r->event_id,
                'event_type'  => $r->event_type ?? 'ADMIN',
                'rsvp_date'   => $r->created_at->setTimezone('Asia/Manila')->format('M d, Y'),
                'source'      => $source,
                'title'       => $event?->title ?? '(Event #' . ($r->event_id ?? '?') . ')',
                'date'        => $event ? $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') : '—',
                'date_raw'    => $event ? $event->event_date->setTimezone('Asia/Manila')->format('F d, Y') : '',
                'time'        => $event ? $event->event_date->setTimezone('Asia/Manila')->format('h:i A')  : '',
                'end_time'    => $event && $event->event_end_date ? $event->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
                'venue'       => $event?->venue ?? '',
                'photo'       => $event?->photo_url ?? '',
                'description' => $event?->description ?? '',
                'is_upcoming' => $event ? $event->event_date->gt($now) : false,
            ];
        }

        $this->modalRsvps    = $result;
        $this->rsvpModalPage = 1;
        $this->activeModal   = 'rsvps';
    }

    public function openEventDetail(int $id, string $source = 'ADMIN'): void
    {
        $now = Carbon::now('UTC');

        if (strtoupper($source) === 'ORGANIZER') {
            $e = OrganizerEvent::find($id);
        } else {
            $e = AdminEvent::withoutTrashed()->find($id);
        }

        if (!$e) { $this->activeModal = ''; return; }

        $rsvp = \App\Models\EventRsvp::where('alumni_id', $this->alumniId)
            ->where('event_id', $id)
            ->first();

        $rsvpResponse = $rsvp ? strtoupper($rsvp->response ?? '') : '';
        $isConfirmed  = $rsvpResponse === 'CONFIRMED';

        $attending = \App\Models\EventRsvp::where('event_id', $id)->where('response', 'CONFIRMED')->count();
        $maybe     = \App\Models\EventRsvp::where('event_id', $id)->where('response', 'MAYBE')->count();
        $no        = \App\Models\EventRsvp::where('event_id', $id)->where('response', 'NO')->count();

        $organizer = null;
        if (strtoupper($source) === 'ORGANIZER') {
            try {
                $organizerId = $e->organizer_id ?? 0;
                if ($organizerId) {
                    foreach (['organizers', 'users'] as $table) {
                        if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                            $organizer = DB::table($table)->where('id', $organizerId)->first();
                            if ($organizer) break;
                        }
                    }
                }
            } catch (\Throwable $ex) {
                $organizer = null;
            }
        }

        $this->selectedEvent = [
            'id'          => $e->id,
            'source'      => strtoupper($source),
            'title'       => $e->title ?? '',
            'date'        => $e->event_date->setTimezone('Asia/Manila')->format('M d, Y'),
            'date_raw'    => $e->event_date->setTimezone('Asia/Manila')->format('F d, Y'),
            'date_ago'    => $e->event_date->setTimezone('Asia/Manila')->diffForHumans(),
            'time'        => $e->event_date->setTimezone('Asia/Manila')->format('h:i A'),
            'end_time'    => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('h:i A') : '',
            'venue'       => $e->venue ?? '',
            'photo'       => $e->photo_url ?? '',
            'description' => $e->description ?? '',
            'additional_notes' => $e->additional_notes ?? $e->notes ?? '',
            'status'      => $e->status ?? '',
            'target_participants' => $e->target_participants ?? '',
            'is_upcoming' => $e->event_date->gt($now),
            'organizer_name'  => $organizer ? trim(($organizer->first_name ?? '') . ' ' . ($organizer->last_name ?? '')) : '',
            'organizer_email' => $organizer?->email ?? '',
            'organizer_phone' => $organizer?->contact_number ?? $e->contact_phone ?? '',
            'is_confirmed'    => $isConfirmed,
            'rsvp_response'   => $rsvpResponse,
            'attending_count' => $attending,
            'maybe_count'     => $maybe,
            'no_count'        => $no,
            'posted_at'       => $e->created_at ? $e->created_at->setTimezone('Asia/Manila')->format('M d, Y') : '',
            'posted_ago'      => $e->created_at ? $e->created_at->setTimezone('Asia/Manila')->diffForHumans() : '',
        ];
        $this->activeModal = 'event_detail';
    }

    public function openEmploymentModal(): void
    {
        if (!$this->hasEmployment) {
            $this->redirect(route('alumni.employment'));
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
                'deadline_full'            => $deadline->format('F d, Y'),
                'days_left'                => (int) now('Asia/Manila')->startOfDay()->diffInDays(
                    $deadline->copy()->startOfDay(), false
                ),
                'posted_at'                => $postedAt ? $postedAt->format('M d, Y') : '',
                'posted_at_full'           => $postedAt ? $postedAt->format('F d, Y \a\t g:i A') : '',
                'posted_ago'               => $postedAt ? $postedAt->diffForHumans(now('Asia/Manila')) : '',
                'target_college'           => $this->safeColumn($j, 'target_college'),
                'status'                   => $j->status ?? 'ACTIVE',
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

    private function safeColumn($model, string $column): string
    {
        try { return $model->$column ?? ''; }
        catch (\Throwable) { return ''; }
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
    .fs-in, .evt-detail-in, .emp-detail-in, .id-card-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }
    @keyframes newPulse {
        0%,100% { box-shadow:0 0 0 0 rgba(37,99,235,.4); }
        50%      { box-shadow:0 0 0 4px rgba(37,99,235,0); }
    }
    .new-badge { animation: newPulse 2s ease-in-out infinite; }

    /* ── STAT CARD TOOLTIP (appears ABOVE) ── */
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
        z-index: 9999;
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
    .dash-stat-card:hover .stat-tooltip { opacity: 1; }

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
    .close-btn-wrap:hover .close-tooltip { opacity: 1; }

    /* ── VIEW DETAILS pill — follows cursor, yellow arrow below ── */
    .view-details-pill {
        position: fixed;
        pointer-events: none;
        z-index: 99999;
        transform: translate(-50%, -140%);
        opacity: 0;
        transition: opacity 0.1s ease;
        white-space: nowrap;
    }
    /* Arrow pointing DOWN from pill */
    .view-details-pill::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 6px solid transparent;
        border-top-color: #EAB308;
        margin-top: -1px;
    }

    /* ── Filter bar ── */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 10px 20px;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        flex-shrink: 0;
    }
    .filter-bar-label {
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #7A3F91;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .filter-divider {
        width: 1px;
        height: 22px;
        background: #e5e7eb;
        flex-shrink: 0;
    }
    .filter-search-wrap {
        position: relative;
        flex: 1;
        min-width: 180px;
        max-width: 320px;
    }
    .filter-search-wrap .fi-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 0.7rem;
        pointer-events: none;
    }
    .filter-search-input {
        width: 100%;
        padding: 7px 12px 7px 32px;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 500;
        color: #111111;
        background: #ffffff;
        outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .filter-search-input:focus {
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,0.1);
    }
    .filter-search-input::placeholder { color: #9ca3af; }

    .filter-select-wrap {
        position: relative;
        flex-shrink: 0;
    }
    .filter-select {
        appearance: none;
        padding: 7px 32px 7px 12px;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #111111;
        background: #ffffff;
        outline: none;
        cursor: pointer;
        min-width: 120px;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .filter-select:focus {
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,0.1);
    }
    .filter-select-caret {
        pointer-events: none;
        position: absolute;
        right: 11px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-left: 4px solid transparent;
        border-right: 4px solid transparent;
        border-top: 5px solid #6b7280;
    }

    .filter-reset-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 7px 13px;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #111111;
        background: #ffffff;
        cursor: pointer;
        white-space: nowrap;
        transition: border-color 0.15s, color 0.15s;
        flex-shrink: 0;
    }
    .filter-reset-btn:hover {
        border-color: #f87171;
        color: #ef4444;
    }
</style>

{{-- Cursor-following script for View Details pill --}}
<script>
document.addEventListener('mousemove', function(e) {
    document.querySelectorAll('.view-details-pill').forEach(function(pill) {
        pill.style.left = e.clientX + 'px';
        pill.style.top  = (e.clientY - 8) + 'px';
    });
});
</script>

{{-- ═══ DASHBOARD ROOT ════════════════════════════════════════════ --}}
<div class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto">

    {{-- ═══ PAGE HEADER ════════════════════════════════════════════ --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Alumni Dashboard</h1>
            <p class="text-sm text-[#333333] font-normal">{{ now()->format('l, F j, Y') }}</p>
        </div>

        @if(!$profileComplete || !$hasEmployment)
        <div class="ml-auto hidden sm:flex items-center gap-2.5 px-3 py-2 rounded-xl border text-xs font-semibold bg-[#F9F7FC] border-[#d9c9e8] text-[#111111]">
            <i class="fas fa-triangle-exclamation text-sm text-[#9b59b6]"></i>
            <span>@if(!$profileComplete) Complete your profile @else Add employment info @endif</span>
            <a href="{{ !$profileComplete ? route('alumni.information') : route('alumni.employment') }}"
               class="px-2.5 py-1 rounded-lg text-white text-xs font-semibold transition hover:opacity-90 bg-[#7A3F91]">
                Go <i class="fas fa-arrow-right text-xs ml-0.5"></i>
            </a>
        </div>
        @endif
    </div>

    {{-- ═══ MAIN GRID ══════════════════════════════════════════════ --}}
    @php $photoUrl = $this->getProfilePhotoUrl(); @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-4 items-start">

        {{-- ══ LEFT: Profile Card ═══════════════════════════════════ --}}
        <div>
            <div class="rounded-2xl overflow-hidden shadow-md border border-[#E8E0F0] bg-white">

                {{-- Photo banner --}}
                <div class="relative w-full overflow-hidden h-[220px] bg-[#EDE0F5]">
                    <img src="{{ $photoUrl }}"
                         alt="{{ $alumniFirstName }}"
                         class="w-full h-full object-cover object-top"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-full h-full items-center justify-center font-black text-white hidden text-[5rem]"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6); display:none;">
                        {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                    </div>
                    <div class="absolute inset-0" style="background:linear-gradient(to bottom, transparent 35%, rgba(0,0,0,.65) 100%);"></div>
                    <div class="absolute bottom-0 left-0 right-0 px-4 pb-4">
                        <p class="text-white font-bold uppercase leading-tight tracking-wide text-[1.15rem]"
                           style="text-shadow:0 1px 5px rgba(0,0,0,.6);">
                            {{ $alumniName ?: '—' }}
                        </p>
                        <p class="font-mono text-[0.8rem]" style="color:rgba(255,255,255,.65);">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                </div>

                {{-- Body --}}
                <div class="px-4 py-3 flex flex-col gap-1">
                    <div class="flex items-start justify-between gap-2 py-2 border-b border-[#F3F4F6] last:border-b-0">
                        <span class="text-[0.72rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0 mt-0.5">Course</span>
                        <span class="text-[0.88rem] font-semibold text-right break-words font-mono text-[#111111]">{{ $alumniCourseCode ?: '—' }}</span>
                    </div>
                    @if($alumniCourseFull)
                    <div class="flex items-start justify-between gap-2 py-2 border-b border-[#F3F4F6] last:border-b-0">
                        <span class="text-[0.82rem] font-semibold text-right break-words max-w-[180px] text-[#111111]">{{ $alumniCourseFull }}</span>
                    </div>
                    @endif
                    @if($alumniCollege)
                    <div class="flex items-start justify-between gap-2 py-2 border-b border-[#F3F4F6] last:border-b-0">
                        <span class="text-[0.72rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0 mt-0.5">College</span>
                        <span class="text-[0.82rem] font-semibold text-right break-words uppercase max-w-[180px] text-[#111111]">{{ $alumniCollege }}</span>
                    </div>
                    @endif
                    @if($alumniBatch)
                    <div class="flex items-start justify-between gap-2 py-2 border-b border-[#F3F4F6] last:border-b-0">
                        <span class="text-[0.72rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0 mt-0.5">Batch</span>
                        <span class="text-[0.88rem] font-semibold text-right break-words text-[#111111]">{{ $alumniBatch }}</span>
                    </div>
                    @endif
                    <div class="flex items-start justify-between gap-2 py-2 border-b border-[#F3F4F6] last:border-b-0">
                        <span class="text-[0.72rem] font-bold uppercase tracking-[0.07em] text-[#333333] shrink-0 mt-0.5">Student ID</span>
                        <span class="text-[0.83rem] font-semibold text-right break-words font-mono text-[#111111]">{{ $alumniStudentId ?: '—' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ══ RIGHT: Stat Cards ═══════════════════════════════════ --}}
        <div class="grid grid-cols-2 gap-3">

            {{-- Card 1: Upcoming Events --}}
            <div wire:click="openUpcomingEventsModal"
                 class="dash-stat-card cursor-pointer bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-lg hover:border-blue-300 transition-all duration-150 active:scale-[.985]">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1" style="font-size:.65rem;"></i>View Upcoming Events</span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-calendar-check text-white text-base"></i>
                    </div>
                    <span class="font-semibold px-2 py-0.5 rounded-full uppercase text-blue-700 border border-blue-200 bg-blue-50 text-[0.72rem]">Upcoming</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.4rem]">{{ $upcomingEvents }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.95rem]">Upcoming Events</p>
            </div>

            {{-- Card 2: Total Events --}}
            <div wire:click="openTotalEventsModal"
                 class="dash-stat-card cursor-pointer bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-lg hover:border-green-300 transition-all duration-150 active:scale-[.985]">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1" style="font-size:.65rem;"></i>View All Events</span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-base"></i>
                    </div>
                    <span class="font-semibold px-2 py-0.5 rounded-full uppercase text-emerald-700 border border-emerald-200 bg-emerald-50 text-[0.72rem]">Total</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.4rem]">{{ $totalEvents }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.95rem]">Total Events</p>
            </div>

            {{-- Card 3: Active Jobs --}}
            <div wire:click="openJobsModal"
                 class="dash-stat-card cursor-pointer bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-lg hover:border-amber-300 transition-all duration-150 active:scale-[.985]">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1" style="font-size:.65rem;"></i>View Active Job Posts</span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-amber-600">
                        <i class="fas fa-briefcase text-white text-base"></i>
                    </div>
                    <span class="font-semibold px-2 py-0.5 rounded-full uppercase text-amber-700 border border-amber-200 bg-amber-50 text-[0.72rem]">Jobs</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.4rem]">{{ $activeJobs }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.95rem]">Active Job Posts</p>
            </div>

            {{-- Card 4: My RSVPs --}}
            <div wire:click="openRsvpsModal"
                 class="dash-stat-card cursor-pointer bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 hover:shadow-lg hover:border-cyan-300 transition-all duration-150 active:scale-[.985]">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1" style="font-size:.65rem;"></i>View My Confirmed RSVPs</span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow bg-cyan-600">
                        <i class="fas fa-circle-check text-white text-base"></i>
                    </div>
                    <span class="font-semibold px-2 py-0.5 rounded-full uppercase text-cyan-700 border border-cyan-200 bg-cyan-50 text-[0.72rem]">RSVPs</span>
                </div>
                <p class="text-[#111111] font-extrabold leading-none tracking-tight text-[2.4rem]">{{ $myRsvps }}</p>
                <p class="text-[#111111] font-semibold mt-1 text-[0.95rem]">My RSVPs</p>
                @if($myRsvps > 0)
                    <div class="mt-2 h-1.5 rounded-full overflow-hidden bg-cyan-100">
                        <div class="h-full rounded-full transition-all duration-700 bg-cyan-600"
                             style="width:{{ min(($myRsvps / max($totalEvents,1)) * 100, 100) }}%;"></div>
                    </div>
                @endif
            </div>

            {{-- Card 5: Employment — spans full width --}}
            @php
                $empCardMap = [
                    'employed'      => ['Employed',      'fa-user-tie',         '#7A3F91', '#F9F7FC', '#E8E0F0'],
                    'self_employed' => ['Self-Employed',  'fa-store',            '#5c2d7a', '#EDE0F5', '#c9ace0'],
                    'unemployed'    => ['Unemployed',     'fa-magnifying-glass', '#9b59b6', '#F5EDF9', '#dbbcef'],
                ];
                $empCard = $empCardMap[$employmentStatus] ?? null;
            @endphp
            <div wire:click="openEmploymentModal"
                 class="dash-stat-card cursor-pointer col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 transition-all duration-150 active:scale-[.985]
                        {{ $hasEmployment ? 'hover:shadow-lg hover:border-[#7A3F91]/40' : 'hover:shadow-lg hover:border-red-300' }}">
                <span class="stat-tooltip"><i class="fas fa-eye mr-1" style="font-size:.65rem;"></i>{{ $hasEmployment ? 'View Employment Details' : 'Add Employment Record' }}</span>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-md shrink-0"
                         style="background:{{ $hasEmployment ? ($empCard ? $empCard[2] : '#7A3F91') : '#e11d48' }};">
                        <i class="fas {{ $hasEmployment ? ($empCard ? $empCard[1] : 'fa-briefcase') : 'fa-triangle-exclamation' }} text-white text-lg"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        @if($hasEmployment && $empCard)
                            <p class="font-bold text-[#111111] truncate text-[1.5rem] leading-tight tracking-tight">{{ $empCard[0] }}</p>
                            <p class="font-normal mt-0.5 text-[#333333] text-[0.9rem]">Employment Status</p>
                            @if($jobTitle)
                                <p class="font-semibold mt-1 truncate uppercase text-[0.8rem] text-[#333333]">
                                    <i class="fas fa-id-badge mr-1 text-[0.65rem]"></i>{{ $jobTitle }}
                                    @if($companyName) · {{ $companyName }} @endif
                                </p>
                            @endif
                        @else
                            <p class="font-bold leading-tight text-red-600 text-[1.5rem]">No Record</p>
                            <p class="font-normal mt-0.5 text-[#333333] text-[0.9rem]">Employment Status</p>
                            <p class="font-semibold mt-1 flex items-center gap-1 text-red-600 text-[0.8rem]">
                                <i class="fas fa-plus-circle"></i> Add record now
                            </p>
                        @endif
                    </div>
                </div>
            </div>

        </div>{{-- end stat grid --}}
    </div>{{-- end main grid --}}

</div>


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENTS LIST
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'events')
@php
    $filteredModalEvents = collect($modalEvents)
        ->when($eventSearch !== '', fn($c) => $c->filter(fn($e) =>
            str_contains(strtolower($e['title']), strtolower($eventSearch)) ||
            str_contains(strtolower($e['venue'] ?? ''), strtolower($eventSearch))
        ))
        ->when($eventStatusFilter === 'upcoming', fn($c) => $c->filter(fn($e) => $e['is_upcoming']))
        ->when($eventStatusFilter === 'completed', fn($c) => $c->filter(fn($e) => !$e['is_upcoming']))
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

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3.5 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">{{ $eventModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">{{ $evtModalTotal }} record(s) · Latest first</p>
            </div>
        </div>
        <div class="close-btn-wrap">
            <span class="close-tooltip">Close</span>
            <button wire:click="closeModal" class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="filter-bar">
        <span class="filter-bar-label">Filters</span>
        <div class="filter-divider"></div>

        <div class="filter-search-wrap" wire:ignore
             x-data="{ q:'', init(){ this.q=$wire.eventSearch??''; $wire.$watch('eventSearch',v=>{ if(v!==this.q) this.q=v; }); } }">
            <i class="fas fa-search fi-icon"></i>
            <input type="text" x-model="q"
                   @input.debounce.300ms="$wire.set('eventSearch', q)"
                   placeholder="Title, venue..."
                   class="filter-search-input"
                   autocomplete="off">
        </div>

        <div class="filter-select-wrap">
            <select wire:model.live="eventStatusFilter" class="filter-select">
                <option value="">All Status</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>
            <div class="filter-select-caret"></div>
        </div>

        @if($eventSearch || $eventStatusFilter)
        <button wire:click="$set('eventSearch',''); $set('eventStatusFilter','')" class="filter-reset-btn">
            <i class="fas fa-rotate-left text-xs"></i> Reset
        </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="flex-1 overflow-y-auto min-h-0 scrollbar-thin scrollbar-thumb-[#d4b8e8] scrollbar-track-[#f9fafb]">
        <table class="w-full border-collapse" style="min-width:560px;">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 pr-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider w-12 text-[0.72rem]">#</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider w-14 text-[0.72rem]">Photo</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Event Title</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Date</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Time</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider hidden sm:table-cell text-[0.72rem]">Venue</th>
                    <th class="px-3 py-2.5 text-center font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayEvents as $idx => $evt)
                <tr class="table-hover-row relative cursor-pointer bg-white hover:bg-[#F5F0FA] transition-colors duration-100"
                    wire:click="openEventDetail({{ $evt['id'] }}, '{{ $evt['source'] }}')"
                    x-data="{}"
                    @mouseenter="$el.querySelector('.view-details-pill').style.opacity='1'"
                    @mouseleave="$el.querySelector('.view-details-pill').style.opacity='0'">

                    <td class="p-0 m-0 border-0 w-0 overflow-visible" style="position:static;">
                        <span class="view-details-pill inline-flex items-center gap-[5px] px-4 py-1.5 rounded-lg text-[0.72rem] font-bold tracking-[.05em] uppercase text-white shadow-xl" style="background:#EAB308; color:#1a1a1a;">
                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
                            View Details
                        </span>
                    </td>
                    <td class="pl-6 pr-3 py-3">
                        <span class="font-semibold text-[#333333] text-[0.8rem]">{{ str_pad($evtModalFrom + $idx,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-3 py-3">
                        <div class="w-10 h-10 rounded-xl overflow-hidden flex-shrink-0 bg-[#f0e6f8]">
                            @if($evt['photo'])
                                <img src="{{ $evt['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-calendar-days text-sm text-[#333333]"></i>
                                </div>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $evt['title'] }}</p>
                        @if(!empty($evt['date_ago']))<p class="text-[#333333] mt-0.5 text-[0.75rem]">{{ $evt['date_ago'] }}</p>@endif
                    </td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $evt['date'] }}</p>
                    </td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $evt['time'] }}</p>
                        @if(!empty($evt['end_time']))<p class="text-[#333333] text-[0.75rem]">– {{ $evt['end_time'] }}</p>@endif
                    </td>
                    <td class="px-3 py-3 hidden sm:table-cell">
                        <p class="text-[#333333] text-[0.88rem]">{{ $evt['venue'] ?: '—' }}</p>
                    </td>
                    <td class="px-3 py-3 text-center">
                        @if($evt['is_upcoming'] ?? true)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full font-semibold border text-[#111111] border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">
                                <i class="fas fa-clock text-[#333333] text-[0.62rem]"></i> Upcoming
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full font-semibold border bg-green-50 text-green-700 border-green-200 text-[0.75rem]">
                                <i class="fas fa-circle-check text-[0.62rem]"></i> Completed
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-calendar-days text-xl text-[#333333]"></i>
                        </div>
                        <p class="font-semibold text-[#333333] text-[0.9rem]">No events found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#7A3F91]">
        <p class="text-white/70 text-[0.82rem]">
            Showing <strong class="text-white">{{ $evtModalFrom }}–{{ $evtModalTo }}</strong>
            of <strong class="text-white">{{ number_format($evtModalTotal) }}</strong>
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($evtModalSafePage <= 1) wire:click="eventPrevPage"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @if($evtPgStart > 1)
                <button wire:click="$set('eventModalPage', 1)" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">1</button>
                @if($evtPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $evtPgStart; $p <= $evtPgEnd; $p++)
                @if($p === $evtModalSafePage)
                    <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white text-[#7A3F91] border border-white">{{ $p }}</span>
                @else
                    <button wire:click="$set('eventModalPage', {{ $p }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $p }}</button>
                @endif
            @endfor
            @if($evtPgEnd < $evtModalLastPage)
                @if($evtPgEnd < $evtModalLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('eventModalPage', {{ $evtModalLastPage }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $evtModalLastPage }}</button>
            @endif
            <button @disabled($evtModalSafePage >= $evtModalLastPage) wire:click="eventNextPage({{ $evtModalLastPage }})"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-white/60 font-semibold ml-1 hidden sm:inline text-[0.8rem]">Page {{ $evtModalSafePage }}/{{ $evtModalLastPage }}</span>
        </div>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: JOBS LIST
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'jobs')
@php
    $allJobTypes = collect($modalJobs)->pluck('type')->unique()->filter()->values()->toArray();
    $filteredJobs = collect($modalJobs)
        ->when($jobSearch !== '', fn($c) => $c->filter(fn($j) =>
            str_contains(strtolower($j['title']),   strtolower($jobSearch)) ||
            str_contains(strtolower($j['company']), strtolower($jobSearch)) ||
            str_contains(strtolower($j['location'] ?? ''), strtolower($jobSearch))
        ))
        ->when($jobTypeFilter !== '', fn($c) => $c->filter(fn($j) => $j['type'] === $jobTypeFilter))
        ->values();

    $jobTotalCount = $filteredJobs->count();
    $jobLastPage   = max((int) ceil($jobTotalCount / $jobModalPageSize), 1);
    $jobSafePage   = min($jobModalPage, $jobLastPage);
    $jobFrom       = $jobTotalCount > 0 ? ($jobSafePage - 1) * $jobModalPageSize + 1 : 0;
    $jobTo         = min($jobSafePage * $jobModalPageSize, $jobTotalCount);
    $displayJobs   = $filteredJobs->slice(($jobSafePage - 1) * $jobModalPageSize, $jobModalPageSize)->values()->toArray();
    $jPgStart = max(1, $jobSafePage - 2);
    $jPgEnd   = min($jobLastPage, $jobSafePage + 2);
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3.5 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">Active Job Postings</h2>
                <p class="text-white/60 text-xs font-normal">{{ $jobTotalCount }} record(s) · Latest first</p>
            </div>
        </div>
        <div class="close-btn-wrap">
            <span class="close-tooltip">Close</span>
            <button wire:click="closeModal" class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="filter-bar">
        <span class="filter-bar-label">Filters</span>
        <div class="filter-divider"></div>

        <div class="filter-search-wrap" wire:ignore
             x-data="{ q:'', init(){ this.q=$wire.jobSearch??''; $wire.$watch('jobSearch',v=>{ if(v!==this.q) this.q=v; }); } }">
            <i class="fas fa-search fi-icon"></i>
            <input type="text" x-model="q"
                   @input.debounce.300ms="$wire.set('jobSearch', q)"
                   placeholder="Title, company, location..."
                   class="filter-search-input"
                   autocomplete="off">
        </div>

        @if(count($allJobTypes) > 0)
        <div class="filter-select-wrap">
            <select wire:model.live="jobTypeFilter" class="filter-select">
                <option value="">All Types</option>
                @foreach($allJobTypes as $jtype)
                <option value="{{ $jtype }}">{{ $jtype }}</option>
                @endforeach
            </select>
            <div class="filter-select-caret"></div>
        </div>
        @endif

        @if($jobSearch || $jobTypeFilter)
        <button wire:click="$set('jobSearch',''); $set('jobTypeFilter','')" class="filter-reset-btn">
            <i class="fas fa-rotate-left text-xs"></i> Reset
        </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="flex-1 overflow-y-auto min-h-0 scrollbar-thin scrollbar-thumb-[#d4b8e8] scrollbar-track-[#f9fafb]">
        <table class="w-full border-collapse" style="min-width:580px;">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 pr-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider w-12 text-[0.72rem]">#</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Job Title</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Company</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider hidden sm:table-cell text-[0.72rem]">Type</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider hidden md:table-cell text-[0.72rem]">Location</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider hidden md:table-cell text-[0.72rem]">Salary</th>
                    <th class="px-3 py-2.5 text-center font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayJobs as $idx => $job)
                @php
                    $rowNum   = $jobFrom + $idx;
                    $isUrgent = ($job['days_left'] ?? 99) <= 7;
                    $isNew    = str_contains($job['posted_ago'] ?? '', 'hour') || str_contains($job['posted_ago'] ?? '', 'minute') || str_contains($job['posted_ago'] ?? '', '1 day');
                @endphp
                <tr class="table-hover-row relative cursor-pointer bg-white hover:bg-[#F5F0FA] transition-colors duration-100"
                    wire:click="openJobDetail({{ $job['id'] }})"
                    x-data="{}"
                    @mouseenter="$el.querySelector('.view-details-pill').style.opacity='1'"
                    @mouseleave="$el.querySelector('.view-details-pill').style.opacity='0'">

                    <td class="p-0 m-0 border-0 w-0 overflow-visible" style="position:static;">
                        <span class="view-details-pill inline-flex items-center gap-[5px] px-4 py-1.5 rounded-lg text-[0.72rem] font-bold tracking-[.05em] uppercase shadow-xl" style="background:#EAB308; color:#1a1a1a;">
                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
                            View Details
                        </span>
                    </td>
                    <td class="pl-6 pr-3 py-3">
                        <span class="font-semibold text-[#333333] text-[0.8rem]">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-3 py-3">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-[#111111] truncate max-w-[200px] text-[0.88rem]">{{ $job['title'] }}</p>
                            @if($isNew)
                            <span class="new-badge inline-flex items-center px-1.5 py-0.5 rounded-full font-black uppercase text-white bg-blue-600 text-[0.62rem]">NEW</span>
                            @endif
                        </div>
                        @if(!empty($job['posted_ago']))<p class="text-[#333333] mt-0.5 text-[0.75rem]">Posted {{ $job['posted_ago'] }}</p>@endif
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-[#333333] truncate max-w-[160px] text-[0.88rem]">{{ $job['company'] }}</p>
                    </td>
                    <td class="px-3 py-3 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full font-semibold border text-[#111111] border-[#E8E0F0] bg-[#F9F7FC] text-[0.75rem]">
                            {{ $job['type'] }}
                        </span>
                    </td>
                    <td class="px-3 py-3 hidden md:table-cell">
                        <p class="text-[#333333] text-[0.88rem]">{{ $job['location'] ?: '—' }}</p>
                    </td>
                    <td class="px-3 py-3 hidden md:table-cell">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $job['salary'] ?: '—' }}</p>
                    </td>
                    <td class="px-3 py-3 text-center">
                        <p class="font-semibold text-[0.8rem] {{ $isUrgent ? 'text-red-600' : 'text-[#111111]' }}">
                            <i class="fas fa-{{ $isUrgent ? 'fire' : 'calendar' }} mr-0.5"></i>
                            {{ $job['deadline'] }}
                        </p>
                        @if($isUrgent)
                        <p class="text-red-400 font-normal mt-0.5 text-[0.75rem]">{{ $job['days_left'] }}d left</p>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-briefcase text-xl text-[#333333]"></i>
                        </div>
                        <p class="font-semibold text-[#333333] text-[0.9rem]">No active job postings</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#7A3F91]">
        <p class="text-white/70 text-[0.82rem]">
            Showing <strong class="text-white">{{ $jobFrom }}–{{ $jobTo }}</strong>
            of <strong class="text-white">{{ number_format($jobTotalCount) }}</strong>
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($jobSafePage <= 1) wire:click="jobPrevPage"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @if($jPgStart > 1)
                <button wire:click="$set('jobModalPage', 1)" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">1</button>
                @if($jPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $jPgStart; $p <= $jPgEnd; $p++)
                @if($p === $jobSafePage)
                    <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white text-[#7A3F91] border border-white">{{ $p }}</span>
                @else
                    <button wire:click="$set('jobModalPage', {{ $p }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $p }}</button>
                @endif
            @endfor
            @if($jPgEnd < $jobLastPage)
                @if($jPgEnd < $jobLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('jobModalPage', {{ $jobLastPage }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $jobLastPage }}</button>
            @endif
            <button @disabled($jobSafePage >= $jobLastPage) wire:click="jobNextPage({{ $jobLastPage }})"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-white/60 font-semibold ml-1 hidden sm:inline text-[0.8rem]">Page {{ $jobSafePage }}/{{ $jobLastPage }}</span>
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

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3.5 shrink-0 shadow bg-[#7A3F91]">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">My Confirmed RSVPs</h2>
                <p class="text-white/60 text-xs font-normal">{{ $rsvpTotal }} record(s) · Latest first</p>
            </div>
        </div>
        <div class="close-btn-wrap">
            <span class="close-tooltip">Close</span>
            <button wire:click="closeModal" class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="flex-1 overflow-y-auto min-h-0 scrollbar-thin scrollbar-thumb-[#d4b8e8] scrollbar-track-[#f9fafb]">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 pr-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider w-12 text-[0.72rem]">#</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider w-14 text-[0.72rem]">Photo</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Event</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider text-[0.72rem]">Event Date</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-[#333333] uppercase tracking-wider hidden sm:table-cell text-[0.72rem]">Venue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayRsvps as $idx => $rsvp)
                <tr class="table-hover-row relative cursor-pointer bg-white hover:bg-[#F5F0FA] transition-colors duration-100"
                    wire:click="openEventDetail({{ $rsvp['event_id'] }}, '{{ $rsvp['source'] }}')"
                    x-data="{}"
                    @mouseenter="$el.querySelector('.view-details-pill').style.opacity='1'"
                    @mouseleave="$el.querySelector('.view-details-pill').style.opacity='0'">

                    <td class="p-0 m-0 border-0 w-0 overflow-visible" style="position:static;">
                        <span class="view-details-pill inline-flex items-center gap-[5px] px-4 py-1.5 rounded-lg text-[0.72rem] font-bold tracking-[.05em] uppercase shadow-xl" style="background:#EAB308; color:#1a1a1a;">
                            <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
                            View Details
                        </span>
                    </td>
                    <td class="pl-6 pr-3 py-3">
                        <span class="font-semibold text-[#333333] text-[0.8rem]">{{ str_pad($rsvpFrom + $idx,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-3 py-3">
                        <div class="w-10 h-10 rounded-xl overflow-hidden bg-[#f0e6f8]">
                            @if($rsvp['photo'])
                                <img src="{{ $rsvp['photo'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-calendar-days text-sm text-[#333333]"></i>
                                </div>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $rsvp['title'] }}</p>
                        <p class="text-[#333333] font-normal mt-0.5 text-[0.75rem]">RSVP'd: {{ $rsvp['rsvp_date'] }}</p>
                    </td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-[#111111] text-[0.88rem]">{{ $rsvp['date'] }}</p>
                        <p class="text-[#333333] font-normal text-[0.75rem]">{{ $rsvp['time'] }}</p>
                    </td>
                    <td class="px-3 py-3 hidden sm:table-cell">
                        <p class="text-[#333333] text-[0.88rem]">{{ $rsvp['venue'] ?: '—' }}</p>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center bg-[#f0e6f8]">
                            <i class="fas fa-circle-check text-xl text-[#333333]"></i>
                        </div>
                        <p class="font-semibold text-[#333333] text-[0.9rem]">No confirmed RSVPs yet</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#7A3F91]">
        <p class="text-white/70 text-[0.82rem]">
            Showing <strong class="text-white">{{ $rsvpFrom }}–{{ $rsvpTo }}</strong>
            of <strong class="text-white">{{ number_format($rsvpTotal) }}</strong> RSVP(s)
        </p>
        <div class="flex items-center gap-1.5 flex-wrap">
            <button @disabled($rsvpSafePage <= 1) wire:click="rsvpPrevPage"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left text-xs"></i>
            </button>
            @if($rsvpPgStart > 1)
                <button wire:click="$set('rsvpModalPage', 1)" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">1</button>
                @if($rsvpPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
            @endif
            @for($p = $rsvpPgStart; $p <= $rsvpPgEnd; $p++)
                @if($p === $rsvpSafePage)
                    <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white text-[#7A3F91] border border-white">{{ $p }}</span>
                @else
                    <button wire:click="$set('rsvpModalPage', {{ $p }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $p }}</button>
                @endif
            @endfor
            @if($rsvpPgEnd < $rsvpLastPage)
                @if($rsvpPgEnd < $rsvpLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                <button wire:click="$set('rsvpModalPage', {{ $rsvpLastPage }})" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all">{{ $rsvpLastPage }}</button>
            @endif
            <button @disabled($rsvpSafePage >= $rsvpLastPage) wire:click="rsvpNextPage({{ $rsvpLastPage }})"
                    class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-[0.8rem] font-bold bg-white/15 text-white border border-white/25 hover:bg-white/28 hover:border-white/50 transition-all disabled:opacity-35 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-right text-xs"></i>
            </button>
            <span class="text-white/60 font-semibold ml-1 hidden sm:inline text-[0.8rem]">Page {{ $rsvpSafePage }}/{{ $rsvpLastPage }}</span>
        </div>
    </div>

</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EVENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'event_detail' && !empty($selectedEvent))
@php
    $evt = $selectedEvent;
    $evtIsUpcoming = $evt['is_upcoming'] ?? false;
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-white overflow-hidden evt-detail-in"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Detail Header --}}
    <div class="flex items-center justify-between px-6 h-12 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)]"
         style="background:linear-gradient(135deg,#7A3F91,#6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-calendar-check text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">EVENT</p>
                <p class="text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[460px]">{{ $evt['title'] ?? '' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-4">
            <div class="close-btn-wrap">
                <span class="close-tooltip">Close</span>
                <button wire:click="closeModal" type="button"
                        class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">
        <div class="max-w-4xl mx-auto px-5 py-6 flex flex-col gap-5">

            <div>
                <h1 class="text-2xl font-bold text-[#111111] leading-tight mb-2">{{ $evt['title'] ?? '' }}</h1>
                <div class="flex flex-wrap gap-2 mb-1">
                    <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border
                                {{ $evtIsUpcoming ? 'bg-blue-50 text-blue-600 border-blue-200' : 'bg-green-50 text-emerald-700 border-green-200' }}">
                        <i class="fas fa-circle text-[0.4rem]"></i>
                        {{ $evtIsUpcoming ? 'Upcoming' : 'Completed' }}
                    </span>
                    @if(!empty($evt['target_participants']))
                        @foreach(explode(',', $evt['target_participants']) as $tp)
                        <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-[#111111] bg-[#F9F7FC] border-[#E8E0F0]">
                            {{ trim($tp) }}
                        </span>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Meta strip --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 border border-gray-200 rounded-xl overflow-hidden bg-white shadow-sm">
                <div class="px-4 py-3 border-r border-b sm:border-b-0 border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Date</p>
                    <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $evt['date_raw'] ?: $evt['date'] }}</p>
                    <p class="text-[0.78rem] text-[#333333] mt-px">{{ $evt['time'] }}{{ !empty($evt['end_time']) ? ' – '.$evt['end_time'] : '' }}</p>
                </div>
                <div class="px-4 py-3 border-r border-b sm:border-b-0 border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Venue</p>
                    <p class="text-[0.82rem] font-bold text-[#111111] leading-snug">{{ strtoupper($evt['venue'] ?: 'TBA') }}</p>
                </div>
                @if(!empty($evt['target_participants']))
                <div class="px-4 py-3 border-r border-b lg:border-b-0 border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Open For</p>
                    <p class="text-[0.82rem] font-bold text-[#111111] leading-snug">{{ $evt['target_participants'] }}</p>
                </div>
                @endif
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Responses</p>
                    <p class="text-[0.92rem] font-bold text-green-600 leading-snug">{{ $evt['attending_count'] ?? 0 }} Attending</p>
                    <p class="text-[0.78rem] text-[#333333] mt-px">{{ $evt['maybe_count'] ?? 0 }} Maybe · {{ $evt['no_count'] ?? 0 }} No</p>
                </div>
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Your RSVP</p>
                    @if(!empty($evt['is_confirmed']) && $evt['is_confirmed'])
                        <p class="text-[0.92rem] font-bold text-green-600 leading-snug">CONFIRMED</p>
                    @else
                        <p class="text-[0.92rem] font-bold text-[#333333] leading-snug">Not RSVP'd</p>
                    @endif
                </div>
                <div class="px-4 py-3">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Posted</p>
                    <p class="text-[0.85rem] font-bold text-[#111111] leading-snug">{{ $evt['posted_at'] ?: '—' }}</p>
                    @if($evt['posted_ago'])<p class="text-[0.78rem] text-[#333333] mt-px">{{ $evt['posted_ago'] }}</p>@endif
                </div>
            </div>

            @if(!empty($evt['photo']))
            <div class="rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-[#F0E6F8]" style="aspect-ratio:16/6;">
                <img src="{{ $evt['photo'] }}" alt="{{ $evt['title'] }}" class="w-full h-full object-cover">
            </div>
            @endif

            @if(!empty($evt['description']))
            <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">ABOUT THIS EVENT</p>
                <p class="font-semibold text-[#111111] mb-2 text-[0.95rem]">Description:</p>
                <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($evt['description']) }}</p>
            </div>
            @endif

            @if(!empty($evt['additional_notes']) || !empty($evt['organizer_name']) || !empty($evt['organizer_email']) || !empty($evt['organizer_phone']))
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if(!empty($evt['additional_notes']))
                <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                    <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">ADDITIONAL NOTES</p>
                    <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap italic">"{{ trim($evt['additional_notes']) }}"</p>
                </div>
                @endif
                @if(!empty($evt['organizer_name']) || !empty($evt['organizer_email']) || !empty($evt['organizer_phone']))
                <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                    <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">CONTACT INFORMATION</p>
                    <div>
                        @if(!empty($evt['organizer_name']))
                        <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                            <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0">
                                <i class="fas fa-user text-xs text-[#333333]"></i>
                            </div>
                            <span class="font-semibold text-[#111111] text-[0.9rem]">{{ $evt['organizer_name'] }}</span>
                        </div>
                        @endif
                        @if(!empty($evt['organizer_email']))
                        <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                            <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0">
                                <i class="fas fa-envelope text-xs text-[#333333]"></i>
                            </div>
                            <span class="text-[#333333] text-[0.88rem]">{{ $evt['organizer_email'] }}</span>
                        </div>
                        @endif
                        @if(!empty($evt['organizer_phone']))
                        <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                            <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0">
                                <i class="fas fa-phone text-xs text-[#333333]"></i>
                            </div>
                            <span class="text-[#333333] text-[0.88rem]">{{ $evt['organizer_phone'] }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @endif

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

    {{-- Detail Header --}}
    <div class="flex items-center justify-between px-6 h-12 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)]"
         style="background:linear-gradient(135deg,#7A3F91,#6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">JOB DETAILS</p>
                <p class="text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[460px]">{{ $selectedJob['title'] ?? '' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-4">
            <div class="close-btn-wrap">
                <span class="close-tooltip">Close</span>
                <button wire:click="closeModal" type="button"
                        class="flex items-center justify-center w-9 h-9 rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition cursor-pointer">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">
        <div class="max-w-4xl mx-auto px-5 py-6 flex flex-col gap-5">

            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#9CA3AF] mb-1">JOB TITLE</p>
                <h1 class="text-2xl font-bold text-[#111111] leading-tight mb-1">{{ $selectedJob['title'] ?? '' }}</h1>
                <p class="text-sm font-semibold text-[#333333] mb-3">{{ $selectedJob['company'] ?? '' }}</p>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-[#111111] bg-[#F9F7FC] border-[#E8E0F0]"><i class="fas fa-building text-[10px]"></i> PHILCST</span>
                    @if(!empty($selectedJob['type']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-blue-700 bg-blue-50 border-blue-200"><i class="fas fa-id-badge text-[10px]"></i> {{ $selectedJob['type'] }}</span>@endif
                    @if(!empty($selectedJob['experience']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-amber-700 bg-amber-50 border-amber-200">{{ $selectedJob['experience'] }}</span>@endif
                    @if(!empty($selectedJob['target_college']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-red-600 bg-red-50 border-red-200"><i class="fas fa-graduation-cap text-[10px]"></i> {{ $selectedJob['target_college'] }}</span>@endif
                    @if($jobUrgent)
                    <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-red-600 bg-red-50 border-red-200">
                        <i class="fas fa-fire text-[10px]"></i> {{ $selectedJob['days_left'] }} days left
                    </span>
                    @endif
                </div>
            </div>

            {{-- Meta strip --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 border border-gray-200 rounded-xl overflow-hidden bg-white shadow-sm">
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Company</p>
                    <p class="text-[0.85rem] font-bold text-[#111111] leading-snug">{{ $selectedJob['company'] ?: '—' }}</p>
                </div>
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Location</p>
                    <p class="text-[0.85rem] font-bold text-[#111111] leading-snug">{{ $selectedJob['location'] ?: 'Not specified' }}</p>
                </div>
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Salary</p>
                    <p class="text-[0.85rem] font-bold text-[#111111] leading-snug">{{ $selectedJob['salary'] ?: 'Not disclosed' }}</p>
                </div>
                <div class="px-4 py-3 border-r border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Deadline</p>
                    <p class="text-[0.85rem] font-bold leading-snug {{ $jobUrgent ? 'text-red-600' : 'text-[#111111]' }}">{{ $selectedJob['deadline_full'] ?? $selectedJob['deadline'] }}</p>
                    <p class="text-[0.78rem] mt-px {{ $jobUrgent ? 'text-red-400' : 'text-[#333333]' }}">
                        <i class="fas fa-{{ $jobUrgent ? 'fire' : 'clock' }} mr-0.5 text-[0.65rem]"></i>
                        {{ $selectedJob['days_left'] }} days left
                    </p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Posted</p>
                    <p class="text-[0.85rem] font-bold text-[#111111] leading-snug">{{ $selectedJob['posted_at'] ?: '—' }}</p>
                    @if($selectedJob['posted_ago'])<p class="text-[0.78rem] text-[#333333] mt-px">{{ $selectedJob['posted_ago'] }}</p>@endif
                </div>
            </div>

            @if($jobUrgent)
            <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl border-l-4 border-red-500 bg-red-50 text-[0.85rem] font-semibold text-red-600">
                <i class="fas fa-fire text-sm"></i>
                Only <strong>{{ $selectedJob['days_left'] }} days</strong> left. Closes {{ $selectedJob['deadline_full'] ?? $selectedJob['deadline'] }}.
            </div>
            @endif

            @if(!empty($selectedJob['description']))
            <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">JOB DESCRIPTION</p>
                <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($selectedJob['description']) }}</p>
            </div>
            @endif

            @if(!empty($selectedJob['qualifications']) || !empty($selectedJob['application_instructions']))
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if(!empty($selectedJob['qualifications']))
                <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                    <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">QUALIFICATIONS</p>
                    <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($selectedJob['qualifications']) }}</p>
                </div>
                @endif
                @if(!empty($selectedJob['application_instructions']))
                <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-[18px]">
                    <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-green-700 mb-3">HOW TO APPLY</p>
                    <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($selectedJob['application_instructions']) }}</p>
                </div>
                @endif
            </div>
            @endif

            @if(!empty($selectedJob['contact_person']) || !empty($selectedJob['contact_email']) || !empty($selectedJob['contact_phone']))
            <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">CONTACT INFORMATION</p>
                <div>
                    @if(!empty($selectedJob['contact_person']))
                    <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                        <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0"><i class="fas fa-user text-xs text-[#333333]"></i></div>
                        <span class="font-semibold text-[#111111] text-[0.9rem]">{{ $selectedJob['contact_person'] }}</span>
                    </div>
                    @endif
                    @if(!empty($selectedJob['contact_email']))
                    <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                        <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0"><i class="fas fa-envelope text-xs text-[#333333]"></i></div>
                        <span class="text-[#333333] text-[0.88rem]">{{ $selectedJob['contact_email'] }}</span>
                    </div>
                    @endif
                    @if(!empty($selectedJob['contact_phone']))
                    <div class="flex items-center gap-2.5 py-2.5 border-b border-[#F3F4F6] last:border-b-0">
                        <div class="w-8 h-8 rounded-lg bg-[#F3F4F6] flex items-center justify-center shrink-0"><i class="fas fa-phone text-xs text-[#333333]"></i></div>
                        <span class="text-[#333333] text-[0.88rem]">{{ $selectedJob['contact_phone'] }}</span>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if(!empty($selectedJob['posted_at_full']))
            <p class="text-center text-[#9CA3AF] font-normal text-[0.82rem]">
                Posted {{ $selectedJob['posted_at_full'] }}
            </p>
            @endif

        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════
     MODAL: EMPLOYMENT DETAIL
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'employment_detail' && !empty($selectedEmployment))
@php $emp = $selectedEmployment; @endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-[#F9FAFB] overflow-hidden emp-detail-in"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Detail Header --}}
    <div class="flex items-center justify-between px-6 h-12 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)]"
         style="background:linear-gradient(135deg,#7A3F91,#6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-briefcase text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">MY EMPLOYMENT RECORD</p>
                <p class="text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[460px]">{{ $emp['job_title'] ?: ($emp['status_label'] ?: 'Employment Details') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-4">
            <a href="{{ route('alumni.employment') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/12 border border-white/20 text-white text-[0.78rem] font-semibold hover:bg-white/22 transition no-underline">
                <i class="fas fa-pen text-xs"></i><span class="hidden sm:inline ml-1">Edit</span>
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

    {{-- Tag row --}}
    <div class="flex flex-wrap gap-1.5 items-center px-5 py-2 bg-white border-b border-[#F3F4F6] shrink-0">
        <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-[#111111] bg-[#F9F7FC] border-[#E8E0F0]"><i class="fas fa-building text-[10px]"></i> PHILCST Alumni</span>
        @if(!empty($emp['employment_type']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-blue-700 bg-blue-50 border-blue-200"><i class="fas fa-id-badge text-[10px]"></i> {{ $emp['employment_type'] }}</span>@endif
        @if(!empty($emp['industry']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-amber-700 bg-amber-50 border-amber-200"><i class="fas fa-industry text-[10px]"></i> {{ $emp['industry'] }}</span>@endif
        @if(!empty($emp['edu_label']))<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-[#111111] bg-[#EDE0F5] border-[#c9ace0]"><i class="fas fa-graduation-cap text-[10px]"></i> {{ $emp['edu_label'] }}</span>@endif
        @if(!empty($emp['abroad']) && $emp['abroad'])<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-cyan-700 bg-cyan-50 border-cyan-200"><i class="fas fa-globe text-[10px]"></i> Working Abroad</span>@endif
    </div>

    {{-- Meta strip --}}
    <div class="flex flex-wrap border-b border-[#E5E7EB] bg-white shrink-0">
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Status</p>
            <p class="text-[0.92rem] font-bold leading-snug" style="color:{{ $emp['status_color'] }};">{{ $emp['status_label'] ?: '—' }}</p>
        </div>
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Job Title</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate max-w-[180px] text-[#111111]">{{ $emp['job_title'] ?: '—' }}</p>
        </div>
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Company</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate max-w-[180px] text-[#111111]">{{ $emp['company_name'] ?: '—' }}</p>
        </div>
        <div class="px-5 py-3 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Date Hired</p>
            <p class="text-[0.92rem] font-bold leading-snug text-[#111111]">{{ $emp['date_hired'] ?: '—' }}</p>
            @if($emp['date_hired_ago'])<p class="text-[0.78rem] text-[#333333] mt-px">{{ $emp['date_hired_ago'] }}</p>@endif
        </div>
    </div>

    {{-- Body --}}
    <div class="flex-1 min-h-0 overflow-y-auto bg-[#F9FAFB] px-6 py-5 flex flex-col gap-3.5 [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">

        @if(!empty($emp['job_title']) || !empty($emp['company_name']) || !empty($emp['industry']))
        <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">POSITION INFORMATION</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                @if(!empty($emp['job_title']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Job Title / Position</p>
                    <p class="text-[0.92rem] font-bold text-[#111111] leading-snug uppercase">{{ $emp['job_title'] }}</p>
                </div>
                @endif
                @if(!empty($emp['company_name']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Company / Employer</p>
                    <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $emp['company_name'] }}</p>
                    @if(!empty($emp['company_address']))<p class="text-[0.78rem] text-[#333333] mt-px">{{ $emp['company_address'] }}</p>@endif
                </div>
                @endif
                @if(!empty($emp['industry']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Industry</p>
                    <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $emp['industry'] }}</p>
                </div>
                @endif
                @if(!empty($emp['monthly_salary']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Monthly Salary</p>
                    <p class="text-[0.92rem] font-bold text-green-600 leading-snug">{{ $emp['monthly_salary'] }}</p>
                </div>
                @endif
                @if(!empty($emp['date_hired']))
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Date Hired</p>
                    <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $emp['date_hired'] }}</p>
                    @if(!empty($emp['date_hired_ago']))<p class="text-[0.78rem] text-[#333333] mt-px">{{ $emp['date_hired_ago'] }}</p>@endif
                </div>
                @endif
            </div>
        </div>
        @endif

        @if(!empty($emp['skills']))
        <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">SKILLS</p>
            <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($emp['skills']) }}</p>
        </div>
        @endif

        @if(!empty($emp['linkedin_url']) || !empty($emp['remarks']))
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            @if(!empty($emp['linkedin_url']))
            <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">LINKEDIN</p>
                <a href="{{ $emp['linkedin_url'] }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 text-sm font-semibold hover:underline text-[#0a66c2]">
                    <i class="fab fa-linkedin"></i> View Profile
                </a>
            </div>
            @endif
            @if(!empty($emp['remarks']))
            <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">REMARKS</p>
                <p class="text-[0.90rem] leading-[1.75] text-[#333333] whitespace-pre-wrap">{{ trim($emp['remarks']) }}</p>
            </div>
            @endif
        </div>
        @endif

        @if(!empty($emp['updated_at']))
        <p class="text-center text-[#9CA3AF] font-normal text-[0.82rem]">Last updated {{ $emp['updated_ago'] }}</p>
        @endif

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('alumni.employment') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 bg-[#7A3F91]">
                <i class="fas fa-pen text-xs"></i> Edit Employment
            </a>
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-[#333333] hover:bg-gray-50 transition bg-white">
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

    {{-- Detail Header --}}
    <div class="flex items-center justify-between px-6 h-12 shrink-0 shadow-[0_2px_8px_rgba(0,0,0,.15)]"
         style="background:linear-gradient(135deg,#7A3F91,#6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-user text-white text-xs"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/55 leading-none">MY ALUMNI PROFILE</p>
                <p class="text-[0.88rem] font-bold text-white leading-snug whitespace-nowrap overflow-hidden text-ellipsis max-w-[460px] uppercase">{{ $alumniName ?: '—' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-4">
            <a href="{{ route('alumni.information') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/12 border border-white/20 text-white text-[0.78rem] font-semibold hover:bg-white/22 transition no-underline">
                <i class="fas fa-pen text-xs"></i><span class="hidden sm:inline ml-1">Edit Profile</span>
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

    {{-- Meta strip --}}
    <div class="flex flex-wrap border-b border-[#E5E7EB] bg-white shrink-0">
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Course</p>
            <p class="text-[0.92rem] font-bold leading-snug font-mono text-[#111111]">{{ $alumniCourseCode ?: '—' }}</p>
            @if($alumniCourseFull)<p class="text-[0.78rem] text-[#333333] mt-px truncate max-w-[160px]">{{ $alumniCourseFull }}</p>@endif
        </div>
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Batch Year</p>
            <p class="text-[0.92rem] font-bold leading-snug text-[#111111]">{{ $alumniBatch ?: '—' }}</p>
        </div>
        <div class="px-5 py-3 border-r border-[#F3F4F6] min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">College</p>
            <p class="text-[0.92rem] font-bold leading-snug truncate uppercase max-w-[160px] text-[#111111]">{{ $alumniCollege ?: '—' }}</p>
        </div>
        <div class="px-5 py-3 min-w-[110px] flex-1">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Profile Status</p>
            <p class="text-[0.92rem] font-bold leading-snug {{ $profileComplete ? 'text-green-600' : 'text-amber-600' }}">
                {{ $profileComplete ? 'Complete' : 'Incomplete' }}
            </p>
        </div>
    </div>

    {{-- Tag row --}}
    <div class="flex flex-wrap gap-1.5 items-center px-5 py-2 bg-white border-b border-[#F3F4F6] shrink-0">
        <span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-[#111111] bg-[#F9F7FC] border-[#E8E0F0]"><i class="fas fa-building text-[10px]"></i> PHILCST Alumni</span>
        @if($alumniCourseCode)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-blue-700 bg-blue-50 border-blue-200"><i class="fas fa-book-open text-[10px]"></i> {{ $alumniCourseCode }}</span>@endif
        @if($alumniBatch)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border text-green-700 bg-green-50 border-green-200"><i class="fas fa-calendar-check text-[10px]"></i> Batch {{ $alumniBatch }}</span>@endif
        @if($hasEmployment && $empInfo3)<span class="inline-flex items-center gap-[5px] text-[0.75rem] font-bold px-[11px] py-[5px] rounded-full border" style="background:{{ $empInfo3[3] }}; color:{{ $empInfo3[2] }}; border-color:{{ $empInfo3[4] }};"><i class="fas {{ $empInfo3[1] }} text-[10px]"></i> {{ $empInfo3[0] }}</span>@endif
    </div>

    {{-- Body --}}
    <div class="flex-1 min-h-0 overflow-y-auto bg-[#F9FAFB] px-6 py-5 flex flex-col gap-3.5 [scrollbar-width:thin] [scrollbar-color:#d1d5db_#f9fafb]">

        <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
            <div class="flex flex-col sm:flex-row gap-5">
                <div class="w-full sm:w-36 shrink-0">
                    <div class="rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-[#EDE0F5]" style="aspect-ratio:3/4;">
                        <img src="{{ $this->getProfilePhotoUrl() }}" alt="{{ $alumniFirstName }}" class="w-full h-full object-cover"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full items-center justify-center text-5xl font-black text-white hidden bg-[#7A3F91]"
                             style="display:none;">
                            {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                        </div>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="mb-3">
                        <p class="text-xl font-bold text-[#111111] uppercase tracking-wide leading-tight">{{ $alumniName ?: '—' }}</p>
                        <p class="text-sm text-[#333333] font-mono mt-0.5">{{ $alumniStudentId ?: 'No student ID' }}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Course Code</p>
                            <p class="text-[0.92rem] font-bold text-[#111111] leading-snug font-mono">{{ $alumniCourseCode ?: '—' }}</p>
                        </div>
                        @if($alumniCourseFull)
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Course</p>
                            <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $alumniCourseFull }}</p>
                        </div>
                        @endif
                        @if($alumniCollege)
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">College</p>
                            <p class="text-[0.92rem] font-bold text-[#111111] leading-snug uppercase">{{ $alumniCollege }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Batch / Graduation Year</p>
                            <p class="text-[0.92rem] font-bold text-[#111111] leading-snug">{{ $alumniBatch ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-0.5">Profile Status</p>
                            <p class="text-[0.92rem] font-bold leading-snug {{ $profileComplete ? 'text-emerald-600' : 'text-amber-600' }}">
                                <i class="fas fa-{{ $profileComplete ? 'circle-check' : 'circle-exclamation' }} mr-1"></i>
                                {{ $profileComplete ? 'Profile Complete' : 'Incomplete' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">EMPLOYMENT SUMMARY</p>
            @if($hasEmployment && $empInfo3)
            <div class="flex items-center gap-3 p-3 rounded-xl border mb-4"
                 style="background:{{ $empInfo3[3] }}; border-color:{{ $empInfo3[4] }};">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                     style="background:{{ $empInfo3[2] }}22;">
                    <i class="fas {{ $empInfo3[1] }} text-sm" style="color:{{ $empInfo3[2] }};"></i>
                </div>
                <div>
                    <p class="font-bold text-[0.9rem]" style="color:{{ $empInfo3[2] }};">{{ $empInfo3[0] }}</p>
                    @if($jobTitle)<p class="text-[#333333] font-semibold uppercase text-[0.8rem]">{{ $jobTitle }}@if($companyName) · {{ $companyName }}@endif</p>@endif
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
                <p class="font-medium text-[#333333] text-[0.95rem]">No employment record on file.</p>
                <a href="{{ route('alumni.employment') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-white transition hover:opacity-90 bg-red-600">
                    <i class="fas fa-plus text-xs"></i> Add Employment Record
                </a>
            </div>
            @endif
        </div>

        <div class="bg-white border border-gray-200 rounded-xl px-5 py-[18px]">
            <p class="text-[0.68rem] font-bold uppercase tracking-[0.1em] text-[#9CA3AF] mb-3">ACTIVITY SUMMARY</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl border p-3 text-center bg-blue-50 border-blue-200">
                    <p class="text-2xl font-bold text-blue-700">{{ $upcomingEvents }}</p>
                    <p class="text-xs font-semibold mt-0.5 uppercase text-[#333333]">Upcoming</p>
                </div>
                <div class="rounded-xl border p-3 text-center bg-emerald-50 border-emerald-200">
                    <p class="text-2xl font-bold text-emerald-700">{{ $totalEvents }}</p>
                    <p class="text-xs font-semibold mt-0.5 uppercase text-[#333333]">Events</p>
                </div>
                <div class="rounded-xl border p-3 text-center bg-cyan-50 border-cyan-200">
                    <p class="text-2xl font-bold text-cyan-700">{{ $myRsvps }}</p>
                    <p class="text-xs font-semibold mt-0.5 uppercase text-[#333333]">RSVPs</p>
                </div>
                <div class="rounded-xl border p-3 text-center bg-amber-50 border-amber-200">
                    <p class="text-2xl font-bold text-amber-700">{{ $activeJobs }}</p>
                    <p class="text-xs font-semibold mt-0.5 uppercase text-[#333333]">Jobs</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('alumni.information') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 bg-[#7A3F91]">
                <i class="fas fa-pen text-xs"></i> Edit Profile
            </a>
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-[#333333] hover:bg-gray-50 transition bg-white">
                <i class="fas fa-xmark text-xs"></i> Close
            </button>
        </div>

    </div>
</div>
@endif

</div>{{-- end root --}}