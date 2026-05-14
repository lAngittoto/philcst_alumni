{{-- resources/views/livewire/director/events.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\AdminEvent;
use App\Models\AuditLog;
use App\Http\Controllers\AdminEventController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;
use App\Models\Organizer;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSort    = 'recent';
    public string $filterCollege = '';

    public string $myDisplayName = '';

    public bool   $showFormModal           = false;
    public bool   $isEditing               = false;
    public ?int   $editingEventId          = null;
    public bool   $editingIsOrganizerEvent = false;

    public string $title          = '';
    public string $description    = '';
    public string $event_date     = '';
    public string $start_time     = '';
    public string $end_time       = '';
    public string $venue          = '';
    public string $venue_address  = '';
    public string $contact_person = '';
    public string $contact_email  = '';
    public string $contact_phone  = '';
    public string $notes          = '';

    public string $targetMode       = 'all';
    public array  $selectedColleges = [];
    public string $batchYear        = '';

    public $photo                    = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto       = false;

    public bool   $showViewModal  = false;
    public ?int   $viewingEventId = null;

    public bool   $showApproveModal  = false;
    public ?int   $approveEventId    = null;
    public string $approveEventTitle = '';
    public string $approveRemarks    = '';

    public bool   $showRejectModal   = false;
    public ?int   $rejectEventId     = null;
    public string $rejectEventTitle  = '';
    public string $rejectRemarks     = '';

    public array  $formErrors = [];

    public bool   $showShareModal        = false;
    public ?int   $shareEventId          = null;
    public string $shareEventTitle       = '';
    public string $shareEventDate        = '';
    public string $shareEventTime        = '';
    public string $shareEventEndTime     = '';
    public string $shareEventVenue       = '';
    public string $shareEventVenueAddr   = '';
    public string $shareEventDescription = '';
    public string $shareEventPhotoUrl    = '';
    public string $shareEventTarget      = '';
    public string $shareEventStatus      = '';

    public function mount(): void
    {
        set_time_limit(600);
        abort_unless(auth()->check() && auth()->user()->role === 'director', 403);

        $dirRecord = DB::table('director')
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->first();

        if ($dirRecord) {
            $this->myDisplayName = trim(($dirRecord->first_name ?? '') . ' ' . ($dirRecord->last_name ?? ''));
        }

        if (! $this->myDisplayName) {
            $this->myDisplayName = auth()->user()?->name ?? 'Director';
        }

        $cacheKey = 'director_events_auto_processed';
        if (! Cache::has($cacheKey)) {
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
            Cache::put($cacheKey, true, 60);
        }
    }

    private function autoRejectExpiredPendingEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');
        AdminEvent::withoutTrashed()
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->update([
                'status'         => 'REJECTED',
                'review_remarks' => 'Auto-rejected: event date has already passed without approval.',
            ]);
    }

    private function autoCompleteExpiredEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');
        AdminEvent::withoutTrashed()
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            })
            ->update(['status' => 'COMPLETED']);
    }

    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingFilterStatus(): void  { $this->resetPage(); }
    public function updatingFilterSort(): void    { $this->resetPage(); }
    public function updatingFilterCollege(): void { $this->resetPage(); }

    public function updatedTargetMode(): void
    {
        $this->selectedColleges = [];
        $this->batchYear        = '';
    }

    public function updatedSelectedColleges(): void
    {
        $this->batchYear = '';
    }

    #[Computed]
    public function events()
    {
        $q = AdminEvent::withTrashed()
            ->with(['organizer' => fn($q) => $q->withTrashed()->select('id','name','department','email')])
            ->select([
                'id','title','description','event_date','event_end_date',
                'venue','venue_address','contact_person','contact_email',
                'contact_phone','notes','photo','status','target_participants',
                'organizer_id','review_remarks','reviewed_at',
                'updated_by','updated_by_role','deleted_by','deleted_by_role',
                'created_at','updated_at','deleted_at',
            ])
            ->where('status', '!=', 'ORGANIZER_DELETED');

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
                    ->orWhere('target_participants', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '') $q->where('status', $this->filterStatus);
        if ($this->filterCollege !== '') $q->where('target_participants', 'like', "%{$this->filterCollege}%");

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(15);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (!$this->viewingEventId) return null;
        return AdminEvent::withTrashed()
            ->with(['organizer' => fn($q) => $q->withTrashed()->select('id','name','department','email')])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])->find($this->viewingEventId);
    }

    #[Computed(persist: true)]
    public function colleges(): array
    {
        return Cache::remember('director_event_colleges', 300, function () {
            return app(AdminEventController::class)->getColleges();
        });
    }

    #[Computed]
    public function organizersForSelectedColleges(): array
    {
        if ($this->targetMode !== 'college' || empty($this->selectedColleges)) return [];
        return Organizer::withTrashed()
            ->whereIn('department', $this->selectedColleges)
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'email'])
            ->toArray();
    }

    public function resetFilters(): void
    {
        $this->search        = '';
        $this->filterStatus  = '';
        $this->filterCollege = '';
        $this->filterSort    = 'recent';
        $this->resetPage();
    }

    public function openEditModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        $event = app(AdminEventController::class)->getEvent($id);

        $this->isEditing               = true;
        $this->editingEventId          = $id;
        $this->editingIsOrganizerEvent = $event->organizer_id !== null;
        $this->title                   = $event->title;
        $this->description             = $event->description ?? '';
        $this->event_date              = $event->event_date->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->start_time              = $event->event_date->setTimezone('Asia/Manila')->format('g:i A');
        $this->end_time                = $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A') ?? '';
        $this->venue                   = $event->venue;
        $this->venue_address           = $event->venue_address ?? '';
        $this->contact_person          = $event->contact_person ?? '';
        $this->contact_email           = $event->contact_email ?? '';
        $this->contact_phone           = $event->contact_phone ?? '';
        $this->notes                   = $event->notes ?? '';
        $this->existingPhotoUrl        = $event->photo_url;
        $this->removePhoto             = false;
        $this->photo                   = null;
        $this->formErrors              = [];

        $tp           = $event->target_participants ?? '';
        $parts        = explode(' · Batch ', $tp, 2);
        $collegesPart = trim($parts[0] ?? '');
        $this->batchYear = trim($parts[1] ?? '');
        if (!$collegesPart || $collegesPart === 'All Colleges') {
            $this->targetMode       = 'all';
            $this->selectedColleges = [];
        } else {
            $this->targetMode       = 'college';
            $this->selectedColleges = array_map('trim', explode(',', $collegesPart));
        }

        $this->showFormModal = true;
        $this->showViewModal = false;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function saveEvent(): void
    {
        abort_unless(auth()->user()->role === 'director', 403);

        $key = 'save_event_director_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->dispatch('flash-message', type: 'error', message: 'Too many requests. Please wait a moment.');
            return;
        }
        RateLimiter::hit($key, 60);

        $this->formErrors = [];
        $errors = [];

        $title     = strip_tags(trim($this->title));
        $venue     = strip_tags(trim($this->venue));
        $startTime = strip_tags(trim($this->start_time));
        $endTime   = strip_tags(trim($this->end_time));
        $batchYear = strip_tags(trim($this->batchYear));

        if (!$title)                  $errors['title']      = 'Event title is required.';
        if (!trim($this->event_date)) $errors['event_date'] = 'Event date is required.';
        if (!$venue)                  $errors['venue']      = 'Venue / Location is required.';

        if (!$startTime) {
            $errors['start_time'] = 'Start time is required.';
        } else {
            try { \Carbon\Carbon::parse($startTime); }
            catch (\Exception) { $errors['start_time'] = 'Invalid start time. Use a format like "8:00 AM" or "13:00".'; }
        }

        if ($endTime) {
            try {
                $endDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $endTime, 'Asia/Manila');
                if (!isset($errors['start_time'])) {
                    $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $startTime, 'Asia/Manila');
                    if ($endDt->lte($startDt)) $errors['end_time'] = 'End time must be after start time.';
                }
            } catch (\Exception) { $errors['end_time'] = 'Invalid end time. Use a format like "5:00 PM" or "17:00".'; }
        }

        if ($this->targetMode === 'college' && empty($this->selectedColleges))
            $errors['target'] = 'Please select at least one college.';

        if ($this->targetMode === 'college' && !empty($this->selectedColleges) && !isset($errors['target'])) {
            $colleges  = $this->selectedColleges;
            $hasAlumni = Alumni::where('status', 'VERIFIED')
                ->whereHas('course', fn($c) => $c->whereIn('college', $colleges))
                ->exists();
            if (!$hasAlumni)
                $errors['target'] = "Cannot create event — no verified alumni under " . implode(', ', $colleges) . ".";
        }

        if ($batchYear !== '' && !isset($errors['target'])) {
            $inputYear = (int) $batchYear;
            $q = Alumni::where('status', 'VERIFIED')->where('batch', $inputYear);
            if ($this->targetMode === 'college' && !empty($this->selectedColleges)) {
                $colleges = $this->selectedColleges;
                $q->whereHas('course', fn($c) => $c->whereIn('college', $colleges));
            }
            if (!$q->exists()) {
                $suggQ = Alumni::where('status', 'VERIFIED');
                if ($this->targetMode === 'college' && !empty($this->selectedColleges)) {
                    $colleges = $this->selectedColleges;
                    $suggQ->whereHas('course', fn($c) => $c->whereIn('college', $colleges));
                }
                $available  = $suggQ->distinct()->orderBy('batch', 'desc')->pluck('batch')->map(fn($b) => (int)$b)->toArray();
                $scopeLabel = $this->targetMode === 'college' && !empty($this->selectedColleges)
                    ? implode(', ', $this->selectedColleges) : 'all colleges';
                if (empty($available)) {
                    $errors['batch_year'] = "No verified alumni found for {$scopeLabel}.";
                } else {
                    $nearest   = collect($available)->sortBy(fn($y) => abs($y - $inputYear))->first();
                    $batchList = implode(', ', array_slice($available, 0, 8));
                    if (count($available) > 8) $batchList .= '…';
                    $errors['batch_year'] = "No verified alumni for batch {$inputYear} in {$scopeLabel}."
                        . ($nearest ? " Nearest: {$nearest}." : '')
                        . " Available: {$batchList}.";
                }
            }
        }

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        $collegesStr = $this->targetMode === 'all' ? 'All Colleges' : implode(', ', $this->selectedColleges);
        $yearSuffix  = $batchYear ? ' · Batch ' . $batchYear : '';
        $targetStr   = $collegesStr . $yearSuffix;

        $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $startTime, 'Asia/Manila')->utc();
        $endDt   = ($this->event_date && $endTime)
            ? \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $endTime, 'Asia/Manila')->utc()
            : null;

        $data = [
            'title'               => $title,
            'description'         => strip_tags(trim($this->description)) ?: null,
            'event_date'          => $startDt->format('Y-m-d H:i:s'),
            'event_end_date'      => $endDt ? $endDt->format('Y-m-d H:i:s') : null,
            'venue'               => $venue,
            'venue_address'       => strip_tags(trim($this->venue_address)) ?: null,
            'target_participants' => $targetStr,
            'notes'               => strip_tags(trim($this->notes)) ?: null,
        ];

        if (!$this->editingIsOrganizerEvent) {
            $data['contact_person'] = strip_tags(trim($this->contact_person)) ?: null;
            $data['contact_email']  = filter_var(trim($this->contact_email), FILTER_SANITIZE_EMAIL) ?: null;
            $data['contact_phone']  = strip_tags(trim($this->contact_phone)) ?: null;
        }

        $ctrl  = app(AdminEventController::class);
        $photo = $this->photo;

        if ($this->isEditing) {
            $oldEvent  = $ctrl->getEvent($this->editingEventId);
            $oldValues = [
                'title'               => $oldEvent->title,
                'event_date'          => $oldEvent->event_date->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                'venue'               => $oldEvent->venue,
                'target_participants' => $oldEvent->target_participants,
            ];

            if ($this->removePhoto && !$photo) {
                if ($oldEvent->photo && $oldEvent->photo !== AdminEvent::DEFAULT_PHOTO)
                    Storage::disk('public')->delete($oldEvent->photo);
                $data['photo'] = null;
                $oldEvent->update(array_merge($data, [
                    'updated_by'      => $this->myDisplayName,
                    'updated_by_role' => 'director',
                ]));
            } else {
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }

            AuditLog::create([
                'action'        => 'updated',
                'module'        => 'event',
                'user_name'     => $this->myDisplayName,
                'user_email'    => auth()->user()?->email,
                'user_role'     => 'director',
                'subject_label' => $title,
                'description'   => "Director edited event: {$title}",
                'old_values'    => $oldValues,
                'new_values'    => [
                    'title'               => $title,
                    'event_date'          => $startDt->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                    'venue'               => $venue,
                    'target_participants' => $targetStr,
                ],
                'severity'      => 'info',
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);

            $this->dispatch('flash-message', type: 'success', message: 'Event updated successfully!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function viewEvent(int $id): void
    {
        $this->viewingEventId = $id;
        $this->showViewModal  = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal  = false;
        $this->viewingEventId = null;
    }

    public function confirmApprove(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        $event = app(AdminEventController::class)->getEvent($id);

        $checkDate = $event->event_end_date ?? $event->event_date;
        if ($checkDate->isPast()) {
            $datePH = $event->event_date->setTimezone('Asia/Manila')->format('M d, Y');
            $this->dispatch('flash-message', type: 'error',
                message: "Cannot approve — event date ({$datePH}) has already passed. Please edit the event date to a future date first before approving.");
            return;
        }

        $this->approveEventId    = $id;
        $this->approveEventTitle = $event->title;
        $this->approveRemarks    = '';
        $this->showApproveModal  = true;
    }

    public function executeApprove(): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        if ($this->approveEventId) {
            $event     = app(AdminEventController::class)->getEvent($this->approveEventId);
            $checkDate = $event->event_end_date ?? $event->event_date;
            if ($checkDate->isPast()) {
                $datePH = $event->event_date->setTimezone('Asia/Manila')->format('M d, Y');
                $this->dispatch('flash-message', type: 'error',
                    message: "Cannot approve — event date ({$datePH}) has already passed.");
                $this->showApproveModal  = false;
                $this->approveEventId    = null;
                $this->approveEventTitle = '';
                $this->approveRemarks    = '';
                return;
            }

            app(AdminEventController::class)->approveEvent($this->approveEventId, trim($this->approveRemarks) ?: null);
            AuditLog::create([
                'action'        => 'verified',
                'module'        => 'event',
                'user_name'     => $this->myDisplayName,
                'user_email'    => auth()->user()?->email,
                'user_role'     => 'director',
                'subject_label' => $this->approveEventTitle,
                'description'   => "Director approved event: {$this->approveEventTitle}"
                    . (trim($this->approveRemarks) ? " — Remarks: " . trim($this->approveRemarks) : ''),
                'new_values'    => ['status' => 'APPROVED', 'remarks' => trim($this->approveRemarks) ?: null],
                'severity'      => 'info',
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->approveEventTitle}' approved!");
        }
        $this->showApproveModal  = false;
        $this->approveEventId    = null;
        $this->approveEventTitle = '';
        $this->approveRemarks    = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelApprove(): void
    {
        $this->showApproveModal = false;
        $this->approveEventId   = null;
        $this->approveRemarks   = '';
    }

    public function confirmReject(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        $event = app(AdminEventController::class)->getEvent($id);
        $this->rejectEventId    = $id;
        $this->rejectEventTitle = $event->title;
        $this->rejectRemarks    = '';
        $this->showRejectModal  = true;
    }

    public function executeReject(): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        if (!trim($this->rejectRemarks)) {
            $this->dispatch('flash-message', type: 'error', message: 'Please provide a reason for rejection.');
            return;
        }
        if ($this->rejectEventId) {
            app(AdminEventController::class)->rejectEvent($this->rejectEventId, trim($this->rejectRemarks));
            AuditLog::create([
                'action'        => 'rejected',
                'module'        => 'event',
                'user_name'     => $this->myDisplayName,
                'user_email'    => auth()->user()?->email,
                'user_role'     => 'director',
                'subject_label' => $this->rejectEventTitle,
                'description'   => "Director rejected event: {$this->rejectEventTitle} — Reason: {$this->rejectRemarks}",
                'new_values'    => ['status' => 'REJECTED', 'reason' => trim($this->rejectRemarks)],
                'severity'      => 'warning',
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->rejectEventTitle}' rejected.");
        }
        $this->showRejectModal  = false;
        $this->rejectEventId    = null;
        $this->rejectEventTitle = '';
        $this->rejectRemarks    = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelReject(): void
    {
        $this->showRejectModal = false;
        $this->rejectEventId   = null;
        $this->rejectRemarks   = '';
    }

    public function openShareModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        $event = AdminEvent::withoutTrashed()->find($id);
        if (!$event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }
        if (!in_array($event->status, ['APPROVED', 'COMPLETED'], true)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only approved or completed events can be shared.');
            return;
        }
        $this->shareEventId          = $id;
        $this->shareEventTitle       = $event->title;
        $this->shareEventDate        = $event->event_date->setTimezone('Asia/Manila')->format('F d, Y');
        $this->shareEventTime        = $event->event_date->setTimezone('Asia/Manila')->format('g:i A');
        $this->shareEventEndTime     = $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A') ?? '';
        $this->shareEventVenue       = $event->venue;
        $this->shareEventVenueAddr   = $event->venue_address ?? '';
        $this->shareEventDescription = $event->description ?? '';
        $this->shareEventPhotoUrl    = $event->photo_url;
        $this->shareEventTarget      = $event->target_participants ?? '';
        $this->shareEventStatus      = $event->status;
        $this->showShareModal        = true;
        $this->showViewModal         = false;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal        = false;
        $this->shareEventId          = null;
        $this->shareEventTitle       = '';
        $this->shareEventDate        = '';
        $this->shareEventTime        = '';
        $this->shareEventEndTime     = '';
        $this->shareEventVenue       = '';
        $this->shareEventVenueAddr   = '';
        $this->shareEventDescription = '';
        $this->shareEventPhotoUrl    = '';
        $this->shareEventTarget      = '';
        $this->shareEventStatus      = '';
    }

    public function eventsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('upcoming.events', [], false); } catch (\Throwable) { $path = '/upcoming/events'; }
        return $base . $path;
    }

    public function postToBatchChat(): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        if (!$this->shareEventId) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }
        $event = AdminEvent::withoutTrashed()->find($this->shareEventId);
        if (!$event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        $room = DB::table('chat_rooms')->where('course_code', '__director__')->first();
        if (!$room) {
            $roomId = DB::table('chat_rooms')->insertGetId([
                'name' => 'Directors & Coordinators', 'course_code' => '__director__',
                'batch' => 0, 'department' => 'ALL', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else { $roomId = $room->id; }

        $dirRecord = DB::table('director')->where('user_id', auth()->id())->whereNull('deleted_at')->first();
        if (!$dirRecord) { $this->dispatch('flash-message', type: 'error', message: 'Director record not found.'); return; }

        $isCompleted = $this->shareEventStatus === 'COMPLETED';
        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr     = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
        $baseUrl     = $this->eventsBaseUrl();

        $coordinatorId = null;
        $coordinatorMentionLine = '';
        if ($event->organizer_id) {
            $org = DB::table('organizer')->where('id', $event->organizer_id)->first(['id','first_name','last_name','department']);
            if ($org) {
                $coordinatorId = $org->id;
                $coordinatorMentionLine = '@' . trim(($org->first_name ?? '') . ' ' . ($org->last_name ?? ''))
                    . ($org->department ? " ({$org->department})" : '');
            }
        }

        $staffPhotoLine = '';
        if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
            $staffPhotoLine = url('storage/' . $event->photo);
        }

        if ($isCompleted) {
            $lines = [];
            if ($staffPhotoLine) { $lines[] = $staffPhotoLine; $lines[] = ''; }
            $lines = array_merge($lines, ["🏆 Event Highlights", "━━━━━━━━━━━━━━━━━━━━━━━━",
                "✅ {$event->title}", "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}"]);
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 {$event->target_participants}";
            if ($coordinatorMentionLine)     $lines[] = "📋 Organized by: {$coordinatorMentionLine}";
            $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "Thanks to everyone who joined! 🎉 Check the Events page for more → {$baseUrl}";
        } else {
            $lines = [];
            if ($staffPhotoLine) { $lines[] = $staffPhotoLine; $lines[] = ''; }
            $lines = array_merge($lines, ["📢 @everyone — Event Alert!", "",
                "📅 {$event->title}", "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}"]);
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 Open for: {$event->target_participants}";
            if ($coordinatorMentionLine)     $lines[] = "📋 Posted by: {$coordinatorMentionLine}";
            $lines[] = "";
            $lines[] = "Check it out & RSVP on the Events page! 🎉 → {$baseUrl}";
        }

        $body  = implode("\n", $lines);
        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id' => $roomId, 'sender_type' => 'director', 'sender_id' => $dirRecord->id,
            'body' => $body, 'reply_to_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chat_mentions')->insert(['message_id' => $msgId, 'mention_type' => 'everyone',
            'mentioned_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        if ($coordinatorId) {
            DB::table('chat_mentions')->insert(['message_id' => $msgId, 'mention_type' => 'coordinator',
                'mentioned_id' => $coordinatorId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $label = $isCompleted ? "Event highlights posted to Chat Room! 🏆" : "Event posted to Chat Room! 🎉";
        $this->dispatch('flash-message', type: 'success', message: $label);
        $this->closeShareModal();
    }

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_person = $this->contact_email = '';
        $this->contact_phone = $this->notes = '';
        $this->targetMode       = 'all';
        $this->selectedColleges = [];
        $this->batchYear        = '';
        $this->photo            = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto      = false;
        $this->formErrors       = [];
        $this->editingEventId   = null;
        $this->isEditing        = false;
        $this->editingIsOrganizerEvent = false;
    }
};
?>

{{-- ▼▼▼ height fix here ▼▼▼ --}}
<div class="flex flex-col" style="height: calc(100vh - 120px); overflow: hidden;">

<style>
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes slideInFull {
    from { opacity:0; }
    to   { opacity:1; }
}
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.filter-input {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
}
.filter-input:hover  { border-color: #c4b5d4; }
.filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #FAFAFA !important; cursor: default; }

.form-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #333333;
    margin-bottom: 0.4rem;
}
.form-input {
    width: 100%;
    padding: 0.7rem 0.95rem;
    border: 1.5px solid #d1d5db;
    border-radius: 0.65rem;
    font-size: 0.97rem;
    background: #fff;
    color: #222;
    transition: border-color .15s, box-shadow .15s;
}
.form-input:focus {
    outline: none;
    border-color: #7a3f91;
    box-shadow: 0 0 0 3px rgba(122,63,145,.1);
}
.form-input.error {
    border-color: #f87171;
    background: #fff5f5;
}
.card-section {
    background: #fff;
    border: 1.5px solid #e8e0f0;
    border-radius: 0.875rem;
    overflow: hidden;
}
.card-section-hd {
    padding: 0.55rem 0.85rem;
    background: #faf7fc;
    border-bottom: 1px solid #e8e0f0;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #7a3f91;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.card-section-body { padding: 0.85rem; }

.table-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.table-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.table-block-body {
    flex: 1;
    min-height: 0;
    background: #fff;
}
.table-block-pagination {
    flex-shrink: 0;
    background: #7a3f91;
    padding: 0.6rem 1rem;
}

.meta-row-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.meta-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #555555;
    margin-bottom: 0.2rem;
}
.meta-value {
    font-size: 0.975rem;
    font-weight: 700;
    color: #333333;
    line-height: 1.3;
}
.meta-sub {
    font-size: 0.875rem;
    color: #333333;
    margin-top: 0.15rem;
}

[x-cloak] { display: none !important; }
</style>

{{-- ── FLASH TOAST ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-amber-300 text-amber-800':type==='warning','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Event Overview</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">Review, moderate, and manage all event postings.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
            <i class="fas fa-calendar-days text-purple-600 text-[10px]"></i>
            {{ $this->events->total() }} {{ $this->events->total() !== 1 ? 'Events' : 'Event' }}
        </span>
    </div>

    {{-- ══ UNIFIED BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col table-block">

        {{-- ── FILTER BAR ── --}}
        <div class="table-block-filter flex flex-wrap gap-2 items-center">
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-sliders text-white text-sm"></i>
                <span class="hidden sm:inline">Filters</span>
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#7a3f91; z-index:1;"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or venue…"
                       class="filter-input w-full"
                       style="padding-left: 2.25rem; padding-right: 1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus" class="filter-input" style="color:#333333;">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
            </select>

            <select wire:model.live="filterCollege" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters"><i class="fas fa-rotate-left text-sm"></i></span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            <select wire:model.live="filterCollege" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="filterSort" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col">

            {{-- Loading Overlay --}}
            <div wire:loading
                 wire:target="search,filterStatus,filterSort,filterCollege,resetFilters,previousPage,nextPage"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold" style="color:#7a3f91;">Loading events…</span>
                </div>
            </div>

            @if($this->events->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto scroll-c" style="background:#fff;">
                <table class="w-full min-w-[600px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Event Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Date &amp; Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Coordinator</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum      = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors duration-100">

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#666666;">{{ $eventDate->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5" style="color:#555555;">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="text-sm" style="color:#bbbbbb;">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-yellow-200 bg-yellow-50 text-yellow-700 whitespace-nowrap">
                                        <i class="fas fa-hourglass-half text-[9px] mr-1"></i>Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    @if($isCompleted)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trophy text-xs"></i>
                                            <span class="hidden xl:inline">Highlights</span>
                                        </button>
                                    @elseif($isApproved)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @elseif($isPending)
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-check text-xs"></i>
                                            <span class="hidden xl:inline">Approve</span>
                                        </button>
                                        <button wire:click="confirmReject({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-100 text-red-600 border border-red-200 hover:bg-white hover:border-red-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-xmark text-xs"></i>
                                            <span class="hidden xl:inline">Reject</span>
                                        </button>
                                    @elseif($isRejected)
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-rotate-left text-xs"></i>
                                            <span class="hidden xl:inline">Re-Approve</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-calendar-days text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#333333;">
                        @if($search || $filterStatus || $filterCollege) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus || $filterCollege) Try clearing your filters to see all events.
                        @else No events have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>{{-- /relative wrapper --}}

        {{-- ── PAGINATION ── --}}
        @php
            $total = $this->events->total();
            $pp    = $this->events->perPage();
            $cp    = $this->events->currentPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);
        @endphp
        <div class="table-block-pagination flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
                Showing
                <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
                of
                <span class="font-semibold text-white">{{ $total }}</span>
                event{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->events->onFirstPage())
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">&larr; Prev</button>
                @else
                    <button wire:click="previousPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">&larr; Prev</button>
                @endif
                <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#7a3f91;">
                    {{ $cp }} / {{ $this->events->lastPage() }}
                </span>
                @if($this->events->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">Next &rarr;</button>
                @else
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next &rarr;</button>
                @endif
            </div>
        </div>

    </div>{{-- /table-block --}}

</div>


{{-- ══════════════════════════════════════════════════════════════════════════
     EDIT EVENT — SLIDE-OVER
══════════════════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeFormModal(), 290)">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform">

        <div class="flex items-center justify-between px-6 py-4 flex-shrink-0"
             style="background:#7a3f91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fas fa-pen-to-square text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-white font-semibold text-lg leading-tight">Edit Event</h2>
                    <p class="text-white/60 text-xs mt-0.5">Update event details below</p>
                </div>
            </div>
            <button @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-6 py-3 flex-shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-1 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation text-xs"></i> Please fix the following:
            </p>
            <ul class="text-red-700 text-xs space-y-0.5">
                @foreach($formErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto px-6 py-6 space-y-5 scroll-c" style="scrollbar-width:thin;">

            <div class="card-section">
                <div class="card-section-hd">
                    <i class="fas fa-image text-[9px]"></i> Event Photo
                    <span class="font-normal normal-case tracking-normal text-[10px] ml-1" style="color:#777777;">— optional</span>
                </div>
                <div class="card-section-body">
                    <div x-data="{isDragging:false}"
                         @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                         class="border-2 rounded-xl text-center cursor-pointer transition-all"
                         :class="isDragging?'border-[#7a3f91] bg-[#f5eef9]':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-[#7a3f91] border-solid bg-[#f5eef9]/40':'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-gray-50' }}'">
                        <label class="cursor-pointer block p-4">
                            <input type="file" wire:model="photo" accept="image/*" class="hidden">
                            @if($photo)
                                <div class="flex flex-col items-center gap-2">
                                    <img src="{{ $photo->temporaryUrl() }}" class="w-full h-28 object-contain rounded-lg shadow border border-purple-200">
                                    <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1 text-xs"></i>New photo selected</p>
                                </div>
                            @elseif($existingPhotoUrl&&!$removePhoto)
                                <div class="flex flex-col items-center gap-2">
                                    <img src="{{ $existingPhotoUrl }}" class="w-full h-28 object-contain rounded-lg shadow border border-gray-200">
                                    <p class="text-sm font-semibold" style="color:#555555;">Current photo — click to change</p>
                                </div>
                            @else
                                <div class="flex flex-col items-center gap-2 py-4">
                                    <i class="fas fa-cloud-arrow-up text-3xl text-gray-300"></i>
                                    <p class="font-semibold text-sm" style="color:#555555;">Click to upload or drag &amp; drop</p>
                                    <p class="text-xs" style="color:#777777;">JPG, PNG, WEBP — max 5 MB</p>
                                </div>
                            @endif
                        </label>
                    </div>
                    @if($existingPhotoUrl&&!$removePhoto&&!$photo)
                        <button type="button" wire:click="$set('removePhoto',true)"
                                class="mt-2 text-xs text-red-600 hover:text-red-700 font-semibold flex items-center gap-1 px-2 py-1 rounded-lg border border-red-200 hover:bg-red-50 transition">
                            <i class="fas fa-trash text-[10px]"></i> Remove photo
                        </button>
                    @endif
                    @if($removePhoto)
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs text-amber-700 font-semibold"><i class="fas fa-exclamation-circle mr-1 text-[10px]"></i>Photo removed on save</span>
                            <button type="button" wire:click="$set('removePhoto',false)" class="text-xs text-blue-600 underline">Undo</button>
                        </div>
                    @endif
                    <div wire:loading wire:target="photo" class="mt-2 text-sm text-[#7a3f91] flex items-center gap-2">
                        <i class="fas fa-spinner animate-spin text-xs"></i> Uploading…
                    </div>
                </div>
            </div>

            <div class="card-section">
                <div class="card-section-hd">
                    <i class="fas fa-circle-info text-[9px]"></i> Event Details
                </div>
                <div class="card-section-body space-y-4">
                    <div>
                        <label class="form-label">Event Title <span class="text-red-500">*</span></label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                               class="form-input {{ isset($formErrors['title']) ? 'error' : '' }}">
                        @if(isset($formErrors['title']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea wire:model.defer="description" rows="3" placeholder="Describe the event…" maxlength="5000"
                                  class="form-input resize-none"></textarea>
                    </div>
                    <div>
                        <label class="form-label">Event Date <span class="text-red-500">*</span></label>
                        <input wire:model="event_date" type="date" min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                               class="form-input {{ isset($formErrors['event_date']) ? 'error' : '' }}">
                        @if(isset($formErrors['event_date']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['event_date'] }}</p>@endif
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label">Start Time <span class="text-red-500">*</span></label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                   class="form-input {{ isset($formErrors['start_time']) ? 'error' : '' }}">
                            @if(isset($formErrors['start_time']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">End Time <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                   class="form-input {{ isset($formErrors['end_time']) ? 'error' : '' }}">
                            @if(isset($formErrors['end_time']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['end_time'] }}</p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label">Venue / Location <span class="text-red-500">*</span></label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                   class="form-input {{ isset($formErrors['venue']) ? 'error' : '' }}">
                            @if(isset($formErrors['venue']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['venue'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">Full Address <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Old Nalsian Road, Calasiao" maxlength="200"
                                   class="form-input">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-section">
                <div class="card-section-hd">
                    <i class="fas fa-users text-[9px]"></i> Target Participants
                </div>
                <div class="card-section-body space-y-4">
                    <div class="flex gap-3">
                        <button type="button" wire:click="$set('targetMode','all')"
                                class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                       {{ $targetMode==='all' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                style="{{ $targetMode!=='all' ? 'color:#666666;' : '' }}">
                            <i class="fas fa-globe text-base"></i><span>All Colleges</span>
                        </button>
                        <button type="button" wire:click="$set('targetMode','college')"
                                class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                       {{ $targetMode==='college' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                style="{{ $targetMode!=='college' ? 'color:#666666;' : '' }}">
                            <i class="fas fa-building-columns text-base"></i><span>Specific College(s)</span>
                        </button>
                    </div>

                    @if($targetMode === 'all')
                        <div class="flex items-center gap-3 bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3">
                            <i class="fas fa-globe text-[#7a3f91] text-lg"></i>
                            <div>
                                <p class="text-sm font-semibold text-[#5e2f72]">All Colleges</p>
                                <p class="text-xs text-[#7a3f91] mt-0.5">Visible to all alumni regardless of college.</p>
                            </div>
                        </div>
                    @else
                        @if(isset($formErrors['target']))
                            <p class="text-red-600 text-xs flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5"></i><span>{{ $formErrors['target'] }}</span></p>
                        @endif
                        @if(count($this->colleges) > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider" style="color:#555555;">Select college(s)</span>
                                <div class="flex gap-3">
                                    <button type="button" wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})"
                                            class="text-xs text-[#7a3f91] font-semibold hover:underline">
                                        <i class="fas fa-check-double mr-0.5 text-[10px]"></i>All
                                    </button>
                                    @if(count($selectedColleges) > 0)
                                        <button type="button" wire:click="$set('selectedColleges', [])" class="text-xs font-semibold hover:text-red-500" style="color:#555555;">Clear</button>
                                    @endif
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach($this->colleges as $col)
                                    <label class="flex items-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer transition text-sm font-semibold
                                                  {{ in_array($col, $selectedColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40' }}"
                                           style="{{ !in_array($col, $selectedColleges) ? 'color:#666666;' : '' }}">
                                        <input type="checkbox" wire:model.live="selectedColleges" value="{{ $col }}"
                                               class="w-4 h-4" style="accent-color:#7a3f91;">
                                        <span>{{ $col }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    @endif

                    <div class="pt-2 border-t border-gray-100">
                        <label class="form-label">Batch Year <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}"
                               placeholder="e.g. {{ now()->year - 2 }}"
                               class="form-input {{ isset($formErrors['batch_year']) ? 'error' : '' }}" style="max-width:200px;">
                        @if(isset($formErrors['batch_year']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['batch_year'] }}</p>@endif
                    </div>
                </div>
            </div>

            <div class="card-section">
                <div class="card-section-hd">
                    <i class="fas fa-address-card text-[9px]"></i> Contact Person
                    @if($editingIsOrganizerEvent)
                        <span class="ml-auto inline-flex items-center gap-1 text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-lg">
                            <i class="fas fa-lock text-[9px]"></i> Coordinator's contact — read only
                        </span>
                    @endif
                </div>
                <div class="card-section-body grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach([['contact_person','Name','text','Full name'],['contact_email','Email','email','contact@example.com'],['contact_phone','Phone','text','+63 9XX XXX XXXX']] as [$field,$label,$type,$ph])
                    <div>
                        <label class="form-label">{{ $label }}</label>
                        <input wire:model.defer="{{ $field }}" type="{{ $type }}" placeholder="{{ $ph }}"
                               @if($editingIsOrganizerEvent) readonly @endif
                               class="form-input {{ $editingIsOrganizerEvent ? 'cursor-not-allowed bg-gray-50' : '' }}"
                               style="color:{{ $editingIsOrganizerEvent ? '#999999' : '#333333' }};">
                    </div>
                    @endforeach
                    @if($editingIsOrganizerEvent)
                        <div class="col-span-full"><p class="text-xs" style="color:#777777;"><i class="fas fa-circle-info text-xs mr-1"></i>Contact details belong to the coordinator and cannot be edited here.</p></div>
                    @endif
                </div>
            </div>

            <div class="card-section">
                <div class="card-section-hd">
                    <i class="fas fa-list-check text-[9px]"></i> Additional Notes / Requirements
                    <span class="font-normal normal-case tracking-normal text-[10px] ml-1" style="color:#777777;">— optional</span>
                </div>
                <div class="card-section-body">
                    <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions…" maxlength="3000"
                              class="form-input resize-none"></textarea>
                </div>
            </div>

        </div>

        <div class="px-6 py-4 border-t border-gray-200 bg-white flex-shrink-0 flex gap-3">
            <button type="button" @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"
                    class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer"
                    style="color:#333333;">Cancel</button>
            <button type="button" wire:click="saveEvent"
                    wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-4 py-3 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                    style="background-color:#7a3f91;"
                    onmouseover="this.style.backgroundColor='#5e2f72'" onmouseout="this.style.backgroundColor='#7a3f91'">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner animate-spin text-sm"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEvent"><i class="fas fa-floppy-disk text-sm"></i> Save Changes</span>
            </button>
        </div>

    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW EVENT — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev          = $this->viewingEvent;
    $totalRsvp   = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
    $isCompleted = $ev->status === 'COMPLETED';
    $isApproved  = $ev->status === 'APPROVED';
    $isPending   = $ev->status === 'PENDING';
    $isRejected  = $ev->status === 'REJECTED';
    $eventDatePH = $ev->event_date->setTimezone('Asia/Manila');
    $eventEndPH  = $ev->event_end_date?->setTimezone('Asia/Manila');
    $timeDisplay = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
    $createdPH   = \Carbon\Carbon::parse($ev->created_at)->setTimezone('Asia/Manila');
    $hasPhoto    = !empty($ev->photo_url);

    $roleDisplayLabel = match($ev->updated_by_role ?? '') {
        'director'  => 'Alumni Director',
        'admin'     => 'Alumni Director',
        'organizer' => 'Coordinator',
        default     => ucfirst($ev->updated_by_role ?? '')
    };

    $updatedByDisplay = $ev->updated_by ?? '';
    if ($updatedByDisplay && !str_contains($updatedByDisplay, ' ')) {
        $dirLookup = DB::table('director')
            ->join('users', 'users.id', '=', 'director.user_id')
            ->whereNull('director.deleted_at')
            ->where('users.name', $updatedByDisplay)
            ->selectRaw("CONCAT(director.first_name, ' ', director.last_name) as full_name")
            ->first();
        if ($dirLookup && $dirLookup->full_name) $updatedByDisplay = trim($dirLookup->full_name);
    }

    $postedByLabel = $ev->organizer
        ? $ev->organizer->name . ' · ' . $ev->organizer->department
        : ($updatedByDisplay ?: 'Director');
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- ── VIEW HEADER (Edit button REMOVED) ── --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Event Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $ev->title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
            @if($isCompleted)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-400/20 hover:bg-amber-400/30 border border-amber-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-trophy text-xs"></i><span class="hidden sm:inline">Highlights</span>
                </button>
            @elseif($isApproved)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i><span class="hidden sm:inline">Share</span>
                </button>
            @elseif($isPending)
                <button wire:click="confirmReject({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-500/20 hover:bg-red-500/30 border border-red-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-xmark text-xs"></i><span class="hidden sm:inline">Reject</span>
                </button>
                <button wire:click="confirmApprove({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-500/20 hover:bg-emerald-500/30 border border-emerald-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-check text-xs"></i><span class="hidden sm:inline">Approve</span>
                </button>
            @elseif($isRejected)
                <button wire:click="confirmApprove({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-500/20 hover:bg-emerald-500/30 border border-emerald-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Re-Approve</span>
                </button>
            @endif
            {{-- EDIT BUTTON REMOVED --}}
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            @if($hasPhoto)
            <div class="w-full px-4 pt-4 pb-2 shrink-0 flex items-center justify-center relative">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         class="w-full object-contain" style="max-height: 190px; display:block;">
                    <div class="absolute top-2 right-2">
                        @if($isCompleted)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Completed</span>
                        @elseif($isApproved)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Approved</span>
                        @elseif($isPending)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-hourglass-half text-[9px]"></i> Pending</span>
                        @else<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-xmark text-[9px]"></i> Rejected</span>@endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:90px; background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isCompleted)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-semibold">Completed</span>
                    @elseif($isApproved)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Approved</span>
                    @elseif($isPending)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-semibold">Pending</span>
                    @else<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-semibold">Rejected</span>@endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100"><i class="fas fa-calendar text-violet-600 text-base"></i></span>
                    <div>
                        <p class="meta-label">Date &amp; Time</p>
                        <p class="meta-value">{{ $eventDatePH->format('F d, Y') }}</p>
                        <p class="meta-sub">{{ $timeDisplay }}</p>
                    </div>
                </div>

                @if($ev->venue)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Venue</p>
                        <p class="meta-value truncate">{{ $ev->venue }}</p>
                        @if($ev->venue_address)<p class="meta-sub truncate">{{ $ev->venue_address }}</p>@endif
                    </div>
                </div>
                @endif

                @if($ev->target_participants)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-purple-100"><i class="fas fa-users text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Open For</p>
                        <p class="meta-value line-clamp-2">{{ $ev->target_participants }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-blue-100"><i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }} text-blue-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">{{ $ev->organizer ? 'Coordinator' : 'Posted By' }}</p>
                        <p class="meta-value truncate">{{ $postedByLabel }}</p>
                    </div>
                </div>

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="meta-label mb-2">Contact</p>
                    <div class="flex flex-col gap-2">
                        @if($ev->contact_person)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-user text-purple-500 text-sm w-4"></i>
                            <span class="text-sm font-semibold" style="color:#333333;">{{ $ev->contact_person }}</span>
                        </div>
                        @endif
                        @if($ev->contact_email)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-envelope text-sky-500 text-sm w-4"></i>
                            <span class="text-sm truncate" style="color:#333333;">{{ $ev->contact_email }}</span>
                        </div>
                        @endif
                        @if($ev->contact_phone)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-phone text-emerald-500 text-sm w-4"></i>
                            <span class="text-sm" style="color:#333333;">{{ $ev->contact_phone }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                <div class="p-3.5 rounded-xl border {{ $isCompleted ? 'bg-green-50 border-green-200' : ($isApproved ? 'bg-emerald-50 border-emerald-200' : ($isPending ? 'bg-amber-50 border-amber-200' : 'bg-red-50 border-red-200')) }}">
                    @if($isCompleted)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-green-800"><i class="fas fa-circle-check text-green-500 text-sm"></i> Completed</p>
                        <p class="text-sm text-green-800 mt-0.5">This event has already taken place.</p>
                    @elseif($isApproved)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-emerald-800"><i class="fas fa-circle-check text-emerald-500 text-sm"></i> Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="text-sm text-emerald-800 mt-0.5">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm text-emerald-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                    @elseif($isPending)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-amber-800"><i class="fas fa-hourglass-half text-amber-500 text-sm"></i> Awaiting Review</p>
                        <p class="text-sm text-amber-800 mt-0.5">Use the Approve / Reject buttons above.</p>
                    @else
                        <p class="text-sm font-bold flex items-center gap-1.5 text-red-800"><i class="fas fa-circle-xmark text-red-500 text-sm"></i> Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm text-red-800 mt-0.5"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                        <p class="text-sm text-red-800 mt-1 font-semibold">Coordinator may edit and resubmit.</p>
                    @endif
                </div>

                @if($updatedByDisplay)
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100 text-xs" style="color:#555555;">
                    <span class="font-semibold">Last updated by:</span> {{ $updatedByDisplay }}
                    <span class="ml-1 text-[#7a3f91] font-semibold">({{ $roleDisplayLabel }})</span>
                    <span class="ml-1">· {{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</span>
                </div>
                @endif

                <p class="text-xs text-center" style="color:#777777;">
                    Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <p class="text-xs font-bold uppercase tracking-widest shrink-0" style="color:#333333;">Responses</p>
                    @if($totalRsvp === 0)
                        <span class="text-sm italic" style="color:#555555;">No responses yet.</span>
                    @else
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
                                <i class="fas fa-circle-check text-[9px]"></i> {{ $ev->confirmed_count }} Confirmed
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-700">
                                <i class="fas fa-circle-question text-[9px]"></i> {{ $ev->tentative_count }} Maybe
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-50 border border-red-200 text-xs font-semibold text-red-700">
                                <i class="fas fa-circle-xmark text-[9px]"></i> {{ $ev->declined_count }} Declined
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-5 py-4 flex flex-col gap-4">

                @if($ev->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        About This Event
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($ev->description) }}</div>
                </div>
                @endif

                @if($ev->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-amber-50">
                            <i class="fas fa-list-check text-amber-500 text-[10px]"></i>
                        </span>
                        Additional Notes
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-amber-50/50 rounded-lg p-4 border border-amber-100" style="line-height:1.75; color:#333333;">{{ trim($ev->notes) }}</div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
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


{{-- ══════════════════════════════════════════════════════════════════════════
     APPROVE MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelApprove()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in">
        <div class="px-6 py-4 bg-emerald-50 border-b border-emerald-100">
            <h2 class="text-base font-semibold text-emerald-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-circle-check text-emerald-600 text-sm"></i>
                </div>
                Approve Event
            </h2>
        </div>
        <div class="p-5">
            <p class="text-sm mb-1" style="color:#555555;">You are about to approve:</p>
            <p class="font-semibold text-emerald-700 text-base mb-4">"{{ $approveEventTitle }}"</p>
            <div class="mb-4">
                <label class="form-label">Remarks <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                <textarea wire:model.defer="approveRemarks" rows="2"
                          placeholder="e.g. Approved. Great event proposal!"
                          class="form-input resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelApprove"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    Cancel
                </button>
                <button wire:click="executeApprove" wire:loading.attr="disabled" wire:target="executeApprove"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition cursor-pointer disabled:opacity-60"
                        style="background-color:#059669;"
                        onmouseover="this.style.backgroundColor='#047857'" onmouseout="this.style.backgroundColor='#059669'">
                    <span wire:loading wire:target="executeApprove"><i class="fas fa-spinner animate-spin text-sm"></i></span>
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-circle-check mr-1 text-sm"></i> Yes, Approve</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     REJECT MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelReject()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in">
        <div class="px-6 py-4 bg-red-50 border-b border-red-100">
            <h2 class="text-base font-semibold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-circle-xmark text-red-500 text-sm"></i>
                </div>
                Reject Event
            </h2>
        </div>
        <div class="p-5">
            <p class="text-sm mb-1" style="color:#555555;">You are about to reject:</p>
            <p class="font-semibold text-red-700 text-base mb-4">"{{ $rejectEventTitle }}"</p>
            <div class="mb-4">
                <label class="form-label">Reason for Rejection <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="rejectRemarks" rows="3"
                          placeholder="e.g. Missing required details. Please revise and resubmit."
                          class="form-input resize-none"></textarea>
                <p class="mt-1 text-xs" style="color:#777777;">
                    <i class="fas fa-circle-info mr-1 text-[10px]"></i>Required — coordinator will see this reason.
                </p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelReject"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    Cancel
                </button>
                <button wire:click="executeReject" wire:loading.attr="disabled" wire:target="executeReject"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition cursor-pointer disabled:opacity-60"
                        style="background-color:#dc2626;"
                        onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">
                    <span wire:loading wire:target="executeReject"><i class="fas fa-spinner animate-spin text-sm"></i></span>
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1 text-sm"></i> Yes, Reject</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE / HIGHLIGHTS MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl   = $this->eventsBaseUrl();
    $shareHost      = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isCompleted    = $shareEventStatus === 'COMPLETED';
    $shTimeDisplay  = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $shDescPreview  = mb_strlen($shareEventDescription) > 160
        ? mb_substr($shareEventDescription, 0, 160) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    }
    if ($shareEventVenue)  $fbLines[] = "📍 {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = $isCompleted ? "👥 {$shareEventTarget}" : "👥 Open for: {$shareEventTarget}";
    $fbLines[] = '';
    if ($shareEventDescription) {
        $dPrev = mb_strlen($shareEventDescription) > 200 ? mb_substr($shareEventDescription, 0, 200) . '…' : $shareEventDescription;
        $fbLines[] = $dPrev;
        $fbLines[] = '';
    }
    $fbLines[] = $isCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $shareEventPhotoUrl
        && !str_contains($shareEventPhotoUrl, 'default')
        && str_contains($shareEventPhotoUrl, '/storage/');
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false, fbCopyFailed: false,
         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         photoUrl: {{ json_encode($shareEventPhotoUrl) }},
         hasPhoto: {{ $hasRealPhoto ? 'true' : 'false' }},
         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareModal(), 250);
         },
         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },
         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const htmlContent = '<img src=\'' + imageUrl + '\' alt=\'Event Photo\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                     const htmlBlob = new Blob([htmlContent], { type: 'text/html' });
                     const textBlob = new Blob([text], { type: 'text/plain' });
                     await navigator.clipboard.write([new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })]);
                     return true;
                 }
             } catch(e) { console.warn('Rich copy failed, fallback to plain text:', e); }
             await this.copyPlainText(text);
             return false;
         },
         async shareOnFacebook() {
             const richCopied = await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied     = true;
             this.fbCopyFailed = !richCopied;
             const target = this.hasPhoto ? this.photoUrl : this.baseUrl;
             window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(target), '_blank', 'width=626,height=436,noopener,noreferrer');
             setTimeout(() => { this.fbCopied = false; this.fbCopyFailed = false; }, 8000);
         },
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/', '_blank', 'noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/', '_blank', 'noopener');
             }
             setTimeout(() => { this.messengerCopied = false; }, 8000);
         },
         async copyLinkFn() {
             await this.copyPlainText(this.baseUrl);
             this.copied = true;
             setTimeout(() => this.copied = false, 2500);
         }
     }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="close()">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
         x-transition:leave-end="opacity-0 scale-95 translate-y-4"
         class="relative w-full max-w-5xl bg-white shadow-2xl flex flex-col rounded-2xl overflow-hidden will-change-transform"
         style="max-height: 90vh;">

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5" style="color:#333333;">
                @if($isCompleted)
                    <i class="fas fa-trophy text-amber-500 text-sm"></i>
                    <span>Share Event Highlights</span>
                @else
                    <i class="fas fa-share-nodes text-sky-600 text-sm"></i>
                    <span>Share Event</span>
                @endif
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#333333;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3 pb-0">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color: {{ $isCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#333333;">
                            {{ $shareEventDate }}@if($shTimeDisplay) · {{ $shTimeDisplay }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareEventTarget, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#333333;">{{ $shDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#555555;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#333333;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> opens the share dialog
                            <em>and</em> copies the event photo + caption to your clipboard.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd>
                            in the composer to paste automatically.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold flex items-center gap-2" style="color:#5e2f72;">Post to Chat Room</p>
                        <p class="text-sm mt-0.5 text-purple-700">
                            Posts directly to the <strong>Directors &amp; Coordinators</strong> chat room
                            @if($shareEventId)
                                @php
                                    $orgIdForInfo = AdminEvent::withoutTrashed()->find($shareEventId)?->organizer_id;
                                    $orgForInfo   = $orgIdForInfo ? DB::table('organizer')->where('id', $orgIdForInfo)->first(['first_name','last_name']) : null;
                                @endphp
                                @if($orgForInfo)
                                    and @mentions <strong>{{ trim($orgForInfo->first_name . ' ' . $orgForInfo->last_name) }}</strong>.
                                @endif
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-xl px-4 py-3 flex items-start gap-2"
                     :class="fbCopyFailed ? 'bg-amber-50 border border-amber-300' : 'bg-emerald-50 border border-emerald-300'">
                    <i class="text-sm mt-0.5 flex-shrink-0 fas"
                       :class="fbCopyFailed ? 'fa-triangle-exclamation text-amber-500' : 'fa-check text-emerald-600'"></i>
                    <div>
                        <p class="text-sm font-semibold"
                           :class="fbCopyFailed ? 'text-amber-800' : 'text-emerald-800'"
                           x-text="fbCopyFailed ? 'Share dialog opened!' : 'Share dialog opened + clipboard ready!'"></p>
                        <p class="text-xs mt-0.5"
                           :class="fbCopyFailed ? 'text-amber-700' : 'text-emerald-700'"
                           x-text="fbCopyFailed ? 'Caption copied as text only — paste in the post.' : 'Press Ctrl+V in the post to paste the photo + caption!'"></p>
                    </div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Messenger opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste the photo + caption.</p>
                    </div>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Post on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens share dialog · photo+text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_dir3" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_dir3)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · photo+text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post to chat room</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-shield-halved text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isCompleted ? 'Post Highlights to Chat Room' : 'Post to Chat Room' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Directors &amp; Coordinators · photo included</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white" style="color:#333333;">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-base" style="color:#555555;"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#555555;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>