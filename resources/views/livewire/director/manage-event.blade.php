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

    public bool   $showDeleteModal   = false;
    public ?int   $deleteEventId     = null;
    public string $deleteEventTitle  = '';

    public array  $formErrors = [];

    // ── Share Modal ───────────────────────────────────────────────────────────
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
    // ─────────────────────────────────────────────────────────────────────────

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
        // FIX 1: Exclude ORGANIZER_DELETED events — director should not see events the organizer deleted
        // FIX 2: Use withTrashed() on organizer relationship so deactivated coordinators still show their name
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
            ->where('status', '!=', 'ORGANIZER_DELETED'); // ← HIDE organizer-deleted events from director

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
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (!$this->viewingEventId) return null;
        // FIX 2: withTrashed on organizer so deactivated coordinator name still loads
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
        // FIX 2: withTrashed so deactivated organizers still appear
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
        $this->showApproveModal = false; $this->approveEventId = null;
        $this->approveEventTitle = ''; $this->approveRemarks = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelApprove(): void { $this->showApproveModal = false; $this->approveEventId = null; $this->approveRemarks = ''; }

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
        $this->showRejectModal = false; $this->rejectEventId = null;
        $this->rejectEventTitle = ''; $this->rejectRemarks = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelReject(): void { $this->showRejectModal = false; $this->rejectEventId = null; $this->rejectRemarks = ''; }

    public function confirmDelete(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        $event = app(AdminEventController::class)->getEvent($id);
        $this->deleteEventId    = $id;
        $this->deleteEventTitle = $event->title;
        $this->showDeleteModal  = true;
    }

    public function executeDelete(): void
    {
        abort_unless(auth()->user()->role === 'director', 403);
        if ($this->deleteEventId) {
            app(AdminEventController::class)->deleteEvent($this->deleteEventId);
            AuditLog::create([
                'action'        => 'deleted',
                'module'        => 'event',
                'user_name'     => $this->myDisplayName,
                'user_email'    => auth()->user()?->email,
                'user_role'     => 'director',
                'subject_label' => $this->deleteEventTitle,
                'description'   => "Director permanently deleted event: {$this->deleteEventTitle}",
                'old_values'    => ['title' => $this->deleteEventTitle, 'status' => 'deleted'],
                'severity'      => 'critical',
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteEventTitle}' deleted.");
        }
        $this->showDeleteModal = false; $this->deleteEventId = null; $this->deleteEventTitle = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelDelete(): void { $this->showDeleteModal = false; $this->deleteEventId = null; $this->deleteEventTitle = ''; }

    // ── Share Modal Methods ───────────────────────────────────────────────────

    public function openShareModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'director', 403);

        $event = AdminEvent::withoutTrashed()->find($id);
        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

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

        $this->showShareModal = true;
        $this->showViewModal  = false;
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

        if (!$this->shareEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $event = AdminEvent::withoutTrashed()->find($this->shareEventId);
        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $room = DB::table('chat_rooms')
            ->where('course_code', '__director__')
            ->first();

        if (!$room) {
            $roomId = DB::table('chat_rooms')->insertGetId([
                'name'        => 'Directors & Coordinators',
                'course_code' => '__director__',
                'batch'       => 0,
                'department'  => 'ALL',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $roomId = $room->id;
        }

        $dirRecord = DB::table('director')
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->first();

        if (!$dirRecord) {
            $this->dispatch('flash-message', type: 'error', message: 'Director record not found.');
            return;
        }

        $isCompleted    = $this->shareEventStatus === 'COMPLETED';
        $eventDatePH    = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH     = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr        = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
        $baseUrl        = $this->eventsBaseUrl();

        $coordinatorMention     = '';
        $coordinatorId          = null;
        $coordinatorMentionLine = '';

        if ($event->organizer_id) {
            // FIX 2: Remove whereNull('deleted_at') so deactivated coordinators are still found
            $org = DB::table('organizer')
                ->where('id', $event->organizer_id)
                ->first(['id', 'first_name', 'last_name', 'department']);

            if ($org) {
                $coordinatorId      = $org->id;
                $coordinatorMention = '@' . trim(($org->first_name ?? '') . ' ' . ($org->last_name ?? ''));
                $deptLabel          = $org->department ? " ({$org->department})" : '';
                $coordinatorMentionLine = $coordinatorMention . $deptLabel;
            }
        }

        $staffPhotoLine = '';
        if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
            $staffPhotoLine = url('storage/' . $event->photo);
        }

        if ($isCompleted) {
            $lines = [];
            if ($staffPhotoLine) { $lines[] = $staffPhotoLine; $lines[] = ''; }
            $lines = array_merge($lines, [
                "🏆 Event Highlights",
                "━━━━━━━━━━━━━━━━━━━━━━━━",
                "✅ {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ]);
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 {$event->target_participants}";
            if ($coordinatorMentionLine)     $lines[] = "📋 Organized by: {$coordinatorMentionLine}";
            $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "Thanks to everyone who joined! 🎉 Check the Events page for more → {$baseUrl}";
        } else {
            $lines = [];
            if ($staffPhotoLine) { $lines[] = $staffPhotoLine; $lines[] = ''; }
            $lines = array_merge($lines, [
                "📢 @everyone — Event Alert!",
                "",
                "📅 {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ]);
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 Open for: {$event->target_participants}";
            if ($coordinatorMentionLine)     $lines[] = "📋 Posted by: {$coordinatorMentionLine}";
            $lines[] = "";
            $lines[] = "Check it out & RSVP on the Events page! 🎉 → {$baseUrl}";
        }

        $body = implode("\n", $lines);

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $roomId,
            'sender_type' => 'director',
            'sender_id'   => $dirRecord->id,
            'body'        => $body,
            'reply_to_id' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('chat_mentions')->insert([
            'message_id'   => $msgId,
            'mention_type' => 'everyone',
            'mentioned_id' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        if ($coordinatorId) {
            DB::table('chat_mentions')->insert([
                'message_id'   => $msgId,
                'mention_type' => 'coordinator',
                'mentioned_id' => $coordinatorId,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $label = $isCompleted
            ? "Event highlights posted to Staff Chat! 🏆"
            : "Event posted to Staff Chat! 🎉";

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

<div style="min-height:90vh;">

<style>
.dir-filter-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem !important;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}
.dir-filter-select:hover  { border-color: #7a3f91 !important; }
.dir-filter-select:focus  { outline: none; border-color: #7a3f91 !important; box-shadow: 0 0 0 3px rgba(122,63,145,.12) !important; }

@keyframes dirModalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.d-m-in { animation: dirModalIn .2s cubic-bezier(.25,.8,.25,1) both; }

@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.fade-in { animation: fadeIn .2s ease both; }

[x-cloak] { display: none !important; }
</style>

{{-- ── FLASH TOAST ─────────────────────────────────────────────────────────── --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,
             display(t,m){this.type=t;this.msg=m;this.show=true;
             clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show" x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-6 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-6"
    class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-2xl max-w-sm w-full border-l-4 bg-white"
    :class="{'border-emerald-500':type==='success','border-red-500':type==='error','border-blue-500':type==='info','border-amber-500':type==='warning'}"
    style="display:none">
    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info','bg-amber-100':type==='warning'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" style="color:#333333;" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 leading-snug break-words" style="color:#666666;" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-700 transition flex-shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

<div class="px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto space-y-5">

    {{-- ── HEADER ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0"
             style="box-shadow:0 4px 14px rgba(122,63,145,.25)">
            <i class="fas fa-calendar-days text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold leading-tight" style="color:#333333;">Event Overview</h1>
            <p class="text-sm mt-0.5 font-normal" style="color:#999999;">Review, moderate, and manage all event postings.</p>
        </div>
    </div>

    {{-- ── MAIN CARD ────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden flex flex-col"
         style="height: calc(100vh - 175px); min-height: 500px;">

        {{-- ── Filter Bar ─────────────────────────────────────────────────── --}}
        <div class="px-4 sm:px-5 py-3 border-b border-gray-200 bg-white flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title, venue…"
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       style="color:#333333;"
                       autocomplete="off">
            </div>

            <select wire:model.live="filterStatus"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333; min-width:140px;">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
            </select>

            <select wire:model.live="filterCollege"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:140px;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:130px;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="px-3 py-2.5 rounded-lg border border-gray-200 bg-white text-sm font-semibold hover:bg-gray-50 transition flex items-center gap-1.5"
                    style="color:#666666;">
                <i class="fas fa-rotate-left text-sm"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Mobile row 2 --}}
        <div class="px-4 py-2.5 border-b border-gray-200 bg-white flex gap-2 sm:hidden">
            <select wire:model.live="filterCollege"
                    class="dir-filter-select flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort"
                    class="dir-filter-select flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── Table ──────────────────────────────────────────────────────── --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;"
                 wire:loading.class="opacity-50 pointer-events-none"
                 wire:target="search,filterStatus,filterCollege,filterSort,resetFilters,
                              previousPage,nextPage,executeApprove,executeReject,executeDelete">
                <table class="w-full border-collapse min-w-[650px]">
                    <thead>
                        <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef] sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Event</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Date &amp; Time</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:#333333;">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden lg:table-cell" style="color:#333333;">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->events as $event)
                        @php
                            $isCompleted  = $event->status === 'COMPLETED';
                            $isApproved   = $event->status === 'APPROVED';
                            // FIX 2: organizer loaded with withTrashed(), so name shows even if deactivated
                            if ($event->organizer_id && $event->organizer) {
                                $displayCollege = $event->organizer->department ?? '—';
                            } else {
                                $tp = $event->target_participants ?? '';
                                $parts = explode(' · Batch ', $tp, 2);
                                $displayCollege = trim($parts[0]) ?: 'All Colleges';
                            }
                        @endphp

                        <tr class="bg-white hover:bg-[#faf7fd] transition-colors duration-100">

                            {{-- Title --}}
                            <td class="px-4 sm:px-5 py-4 max-w-[180px] sm:max-w-[220px]">
                                <p class="font-semibold text-sm truncate" style="color:#333333;">{{ $event->title }}</p>
                                <p class="text-xs mt-0.5" style="color:#999999;">{{ $event->created_at->diffForHumans() }}</p>
                            </td>

                            {{-- Date / Time --}}
                            <td class="px-4 sm:px-5 py-4 whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5" style="color:#666666;">
                                    {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @if($event->event_end_date)<span class="mx-1">–</span>{{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}@endif
                                </p>
                            </td>

                            {{-- Coordinator — FIX 2: shows even if organizer is deactivated --}}
                            <td class="px-4 sm:px-5 py-4 hidden md:table-cell">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="text-sm font-medium" style="color:#bbbbbb;">—</span>
                                @endif
                            </td>

                            {{-- College --}}
                            <td class="px-4 sm:px-5 py-4 hidden lg:table-cell">
                                <p class="text-sm font-semibold max-w-[150px] truncate" style="color:#666666;" title="{{ $displayCollege }}">{{ $displayCollege }}</p>
                            </td>

                            {{-- Status badge --}}
                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @if($isCompleted)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-green-100 text-green-800 border border-green-300 rounded-full text-xs font-semibold">
                                        <i class="fas fa-circle-check text-xs"></i> Completed
                                    </span>
                                @elseif($event->status === 'PENDING')
                                    <span class="inline-block px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-semibold">Pending</span>
                                @elseif($isApproved)
                                    <span class="inline-block px-2.5 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold">Approved</span>
                                @else
                                    <span class="inline-block px-2.5 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-full text-xs font-semibold">Rejected</span>
                                @endif
                            </td>

                            {{-- Actions — FIX 1: removed all ORGANIZER_DELETED (restore/delete) logic --}}
                            <td class="px-4 sm:px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-[#7a3f91] bg-[#f5eef9] border border-[#d4aaeb] hover:bg-[#e9d5f3] rounded-lg transition">
                                        <i class="fas fa-eye text-xs"></i><span>View</span>
                                    </button>

                                    @if($isCompleted)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-lg transition">
                                            <i class="fas fa-trophy text-xs"></i><span>Highlights</span>
                                        </button>

                                    @elseif($isApproved)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-lg transition">
                                            <i class="fas fa-share-nodes text-xs"></i><span>Share</span>
                                        </button>

                                    @elseif($event->status === 'PENDING')
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-lg transition">
                                            <i class="fas fa-check text-xs"></i><span>Approve</span>
                                        </button>
                                        <button wire:click="confirmReject({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 rounded-lg transition">
                                            <i class="fas fa-xmark text-xs"></i><span>Reject</span>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-xs"></i><span>Delete</span>
                                        </button>

                                    @elseif($event->status === 'REJECTED')
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-xs"></i><span>Re-Approve</span>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-xs"></i><span>Delete</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-[#f5eef9] rounded-full flex items-center justify-center">
                                        <i class="fas fa-calendar-days text-2xl text-[#c49dd8]"></i>
                                    </div>
                                    <p class="font-semibold text-base" style="color:#666666;">No events found</p>
                                    <p class="text-sm" style="color:#999999;">
                                        @if($search || $filterStatus || $filterCollege) Try adjusting your filters.
                                        @else No events have been submitted yet.@endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Pagination ──────────────────────────────────────────────────── --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-200 shrink-0" style="background:#7a3f91;">
            @php
                $total = $this->events->total();
                $pp    = $this->events->perPage();
                $cp    = $this->events->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-sm" style="color:rgba(255,255,255,.8);">
                    Showing <strong class="text-white">{{ $from }}–{{ $to }}</strong>
                    of <strong class="text-white">{{ $total }}</strong>
                    event{{ $total !== 1 ? 's' : '' }}
                    @if($filterStatus || $filterCollege || $search)<span class="text-xs ml-1" style="color:rgba(255,255,255,.5);">(filtered)</span>@endif
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->events->onFirstPage())
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">← Prev</button>
                    @endif
                    <span class="px-4 py-2 bg-white rounded-lg text-sm font-semibold shadow-sm" style="color:#333333;">{{ $cp }} / {{ $this->events->lastPage() }}</span>
                    @if($this->events->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: Edit Event
════════════════════════════════════════════════════════════════════════ --}}
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
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform"
         x-data="{}"
         x-effect="if($wire.formErrors && Object.keys($wire.formErrors).length > 0){$nextTick(()=>{const el=$refs.panelBody;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] text-white flex-shrink-0">
            <h2 class="text-lg font-semibold flex items-center gap-2.5">
                <i class="fas fa-pen-to-square"></i> Edit Event
            </h2>
            <button @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-6 py-4 flex-shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation"></i> Please fix the following:
            </p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span><span>{{ $err }}</span></li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto px-6 py-6 space-y-5"
             x-ref="panelBody"
             style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            <div>
                <label class="block text-sm font-semibold uppercase tracking-wide mb-2" style="color:#333333;">
                    Event Photo <span class="font-normal normal-case" style="color:#999999;">(Optional)</span>
                </label>
                <div x-data="{isDragging:false}"
                     @dragover.prevent="isDragging=true"
                     @dragleave.prevent="isDragging=false"
                     @drop.prevent="isDragging=false"
                     class="border-2 rounded-xl p-5 text-center cursor-pointer transition-all"
                     :class="isDragging?'border-[#7a3f91] bg-[#f5eef9]':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-[#7a3f91] border-solid bg-[#f5eef9]/40':'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-gray-50' }}'">
                    <label class="cursor-pointer block">
                        <input type="file" wire:model="photo" accept="image/*" class="hidden">
                        @if($photo)
                            <div class="flex flex-col items-center gap-3">
                                <img src="{{ $photo->temporaryUrl() }}" class="w-full max-h-52 object-contain rounded-xl shadow border border-[#d4aaeb]">
                                <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
                            </div>
                        @elseif($existingPhotoUrl&&!$removePhoto)
                            <div class="flex flex-col items-center gap-3">
                                <img src="{{ $existingPhotoUrl }}" class="w-full max-h-52 object-contain rounded-xl shadow border border-gray-200">
                                <p class="text-sm font-semibold" style="color:#666666;">Current photo — click to change</p>
                            </div>
                        @else
                            <div class="flex flex-col items-center gap-2 py-2">
                                <i class="fas fa-cloud-arrow-up text-3xl text-gray-300"></i>
                                <p class="font-semibold text-sm" style="color:#666666;">Click to upload or drag &amp; drop</p>
                                <p class="text-sm" style="color:#999999;">JPG, PNG, WEBP — max 5 MB</p>
                            </div>
                        @endif
                    </label>
                </div>
                @if($existingPhotoUrl&&!$removePhoto&&!$photo)
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" wire:click="$set('removePhoto',true)"
                                class="text-sm text-red-600 hover:text-red-700 font-semibold flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50 transition">
                            <i class="fas fa-trash text-xs"></i> Remove photo
                        </button>
                        <span class="text-sm" style="color:#999999;">(uses default)</span>
                    </div>
                @endif
                @if($removePhoto)
                    <div class="mt-2 flex items-center gap-2">
                        <span class="text-sm text-amber-600 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span>
                        <button type="button" wire:click="$set('removePhoto',false)" class="text-sm text-blue-500 underline hover:text-blue-700">Undo</button>
                    </div>
                @endif
                <div wire:loading wire:target="photo" class="mt-2 text-sm text-[#7a3f91] flex items-center gap-2">
                    <i class="fas fa-spinner animate-spin"></i> Uploading…
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2">
                    <i class="fas fa-circle-info text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Event Details</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                            Event Title <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026"
                               class="w-full px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['title'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['title']))
                            <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5">
                                <i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i>
                                <span>{{ $formErrors['title'] }}</span>
                            </p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">Description</label>
                        <textarea wire:model.defer="description" rows="3" placeholder="Describe the event…"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                                  style="color:#333333;"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                            Event Date <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="event_date" type="date" min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                               class="w-full px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['event_date'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['event_date']))
                            <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['event_date'] }}</span></p>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                                Start Time <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                   class="w-full px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['start_time'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['start_time']))
                                <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['start_time'] }}</span></p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                                End Time <span class="font-normal normal-case" style="color:#999999;">(Optional)</span>
                            </label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                   class="w-full px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['end_time'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['end_time']))
                                <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['end_time'] }}</span></p>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                                Venue / Location <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym"
                                   class="w-full px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['venue'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['venue']))
                                <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['venue'] }}</span></p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                                Full Address <span class="font-normal normal-case" style="color:#999999;">(Optional)</span>
                            </label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Old Nalsian Road, Calasiao"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   style="color:#333333;">
                        </div>
                    </div>
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2">
                    <i class="fas fa-users text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Target Participants</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">
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
                                <div class="text-sm font-semibold text-[#5e2f72]">All Colleges</div>
                                <div class="text-sm text-[#7a3f91] mt-0.5">Visible to all alumni regardless of college.</div>
                            </div>
                        </div>
                    @elseif($targetMode === 'college')
                        <div>
                            @if(isset($formErrors['target']))
                                <p class="text-sm text-red-600 flex items-start gap-1.5 mb-2">
                                    <i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i>
                                    <span>{{ $formErrors['target'] }}</span>
                                </p>
                            @endif
                            @if(count($this->colleges) > 0)
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-semibold uppercase tracking-wide" style="color:#666666;">Select College(s)</span>
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})"
                                                class="text-sm text-[#7a3f91] font-semibold hover:underline">
                                            <i class="fas fa-check-double mr-1"></i>Select All
                                        </button>
                                        @if(count($selectedColleges) > 0)
                                            <button type="button" wire:click="$set('selectedColleges', [])"
                                                    class="text-sm font-semibold hover:text-red-500 hover:underline" style="color:#999999;">Clear</button>
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
                                @if(count($selectedColleges) > 0)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach($selectedColleges as $col)
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] text-sm font-semibold rounded-lg">
                                                <i class="fas fa-building-columns text-xs"></i>{{ $col }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    <div class="pt-3 border-t border-gray-100">
                        <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                            Batch Year <span class="font-normal normal-case" style="color:#999999;">(Optional)</span>
                        </label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}"
                               placeholder="e.g. {{ now()->year - 2 }}"
                               class="w-full sm:max-w-xs px-4 py-3 border rounded-lg text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['batch_year'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['batch_year']))
                            <p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5">
                                <i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i>
                                <span>{{ $formErrors['batch_year'] }}</span>
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2 flex-wrap">
                    <i class="fas fa-address-card text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Contact Person</span>
                    @if($editingIsOrganizerEvent)
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg ml-auto shrink-0">
                            <i class="fas fa-lock text-xs"></i> Coordinator's contact — read only
                        </span>
                    @endif
                </div>
                <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach([['contact_person','Name','text','Full name'],['contact_email','Email','email','contact@example.com'],['contact_phone','Phone','text','+63 9XX XXX XXXX']] as [$field,$label,$type,$ph])
                    <div>
                        <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">{{ $label }}</label>
                        <input wire:model.defer="{{ $field }}" type="{{ $type }}" placeholder="{{ $ph }}"
                               @if($editingIsOrganizerEvent) readonly @endif
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition {{ $editingIsOrganizerEvent ? 'cursor-not-allowed bg-gray-50' : '' }}"
                               style="color:{{ $editingIsOrganizerEvent ? '#999999' : '#333333' }};">
                    </div>
                    @endforeach
                    @if($editingIsOrganizerEvent)
                        <div class="col-span-1 sm:col-span-3">
                            <p class="text-sm" style="color:#999999;">
                                <i class="fas fa-circle-info text-xs mr-1"></i>
                                Contact details belong to the coordinator and cannot be edited here.
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">Additional Notes / Requirements</label>
                <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions…"
                          class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                          style="color:#333333;"></textarea>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex-shrink-0 flex gap-3">
            <button type="button" @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"
                    class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-100 transition"
                    style="color:#333333;">Cancel</button>
            <button type="button" wire:click="saveEvent"
                    wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-4 py-3 text-white rounded-xl text-sm font-semibold bg-[#7a3f91] hover:bg-[#5e2f72] flex items-center justify-center gap-2 transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEvent"><i class="fas fa-floppy-disk mr-1"></i>Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: View Event
════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev           = $this->viewingEvent;
    $totalRsvp    = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
    $isCompleted  = $ev->status === 'COMPLETED';
    $isApproved   = $ev->status === 'APPROVED';

    // FIX 2: organizer loaded with withTrashed() so name still shows if deactivated
    if ($ev->organizer_id && $ev->organizer) {
        $displayCollege = $ev->organizer->department ?? '—';
    } else {
        $tp = $ev->target_participants ?? '';
        $parts = explode(' · Batch ', $tp, 2);
        $displayCollege = trim($parts[0]) ?: 'All Colleges';
    }

    $roleDisplayLabel = match($ev->updated_by_role ?? '') {
        'director'  => 'Alumni Director',
        'admin'     => 'Alumni Director',
        'organizer' => 'Coordinator',
        default     => ucfirst($ev->updated_by_role ?? '')
    };

    $updatedByDisplay = $ev->updated_by ?? '';
    if ($updatedByDisplay && ! str_contains($updatedByDisplay, ' ')) {
        $dirLookup = DB::table('director')
            ->join('users', 'users.id', '=', 'director.user_id')
            ->whereNull('director.deleted_at')
            ->where('users.name', $updatedByDisplay)
            ->selectRaw("CONCAT(director.first_name, ' ', director.last_name) as full_name")
            ->first();
        if ($dirLookup && $dirLookup->full_name) {
            $updatedByDisplay = trim($dirLookup->full_name);
        }
    }

    $postedByLabel = $ev->organizer
        ? $ev->organizer->name . ' · ' . $ev->organizer->department
        : ($updatedByDisplay ?: 'Director');
@endphp
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeViewModal(), 290)">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform">

        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] text-white flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar-days text-white text-sm"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold leading-tight truncate">{{ $ev->title }}</h2>
                    <p class="text-xs mt-0.5" style="color:rgba(255,255,255,.7);">Posted {{ $ev->created_at->diffForHumans() }}</p>
                </div>
            </div>
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none flex-shrink-0 ml-3">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            <div class="relative w-full bg-gray-100 flex items-center justify-center" style="min-height:180px; max-height:340px;">
                <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                     class="w-full object-contain {{ $isCompleted ? 'brightness-90' : '' }}"
                     style="max-height:340px; display:block;">
                <div class="absolute top-3 right-3">
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">
                            <i class="fas fa-circle-check text-xs"></i> Completed
                        </span>
                    @elseif($ev->status === 'PENDING')
                        <span class="inline-block px-3 py-1.5 bg-amber-600/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Pending</span>
                    @elseif($isApproved)
                        <span class="inline-block px-3 py-1.5 bg-emerald-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Approved</span>
                    @else
                        <span class="inline-block px-3 py-1.5 bg-red-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Rejected</span>
                    @endif
                </div>
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-calendar text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $ev->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-clock text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            {{ $ev->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                            @if($ev->event_end_date)
                                <span style="color:#999999;" class="mx-1">–</span>{{ $ev->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                            @else
                                <span class="text-sm italic font-normal" style="color:#999999;"> · End time not set</span>
                            @endif
                        </span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-location-dot text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            {{ $ev->venue }}
                            @if($ev->venue_address)
                                <span class="text-sm font-normal" style="color:#666666;"> · {{ $ev->venue_address }}</span>
                            @endif
                        </span>
                    </li>
                    @if($ev->target_participants)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-users text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $ev->target_participants }}</span>
                    </li>
                    @endif
                    <li class="flex items-start gap-3">
                        {{-- FIX 2: organizer name shows even if deactivated --}}
                        <i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }} text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $postedByLabel }}</span>
                    </li>
                </ul>
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#333333;">
                    <i class="fas fa-users text-xs"></i> Attendee Responses
                    @if($totalRsvp>0)<span class="font-normal ml-1" style="color:#999999;">{{ $totalRsvp }} total</span>@endif
                </h3>
                @if($totalRsvp===0)
                    <div class="text-center py-5" style="color:#999999;">
                        <i class="fas fa-inbox text-2xl block mb-2 text-gray-200"></i>
                        <p class="text-sm font-semibold">No responses yet.</p>
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
                            <i class="fas fa-circle-check text-emerald-500 text-lg mb-1"></i>
                            <div class="text-2xl font-semibold text-emerald-700">{{ $ev->confirmed_count }}</div>
                            <div class="text-xs font-semibold text-emerald-600 uppercase tracking-wide mt-1">Confirmed</div>
                        </div>
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
                            <i class="fas fa-circle-xmark text-red-500 text-lg mb-1"></i>
                            <div class="text-2xl font-semibold text-red-700">{{ $ev->declined_count }}</div>
                            <div class="text-xs font-semibold text-red-600 uppercase tracking-wide mt-1">Not Attending</div>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
                            <i class="fas fa-circle-question text-amber-500 text-lg mb-1"></i>
                            <div class="text-2xl font-semibold text-amber-700">{{ $ev->tentative_count }}</div>
                            <div class="text-xs font-semibold text-amber-600 uppercase tracking-wide mt-1">Maybe</div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Status</h3>
                @if($isCompleted)
                    <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-green-800"><i class="fas fa-circle-check mr-2 text-green-500"></i>Event Completed</p>
                        <p class="text-sm text-green-700 mt-1">This event has already taken place successfully.</p>
                    </div>
                @elseif($ev->status==='PENDING')
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-amber-800"><i class="fas fa-hourglass-half mr-2 text-amber-500"></i>Pending Review</p>
                        <p class="text-sm text-amber-700 mt-1">This event is waiting for your approval.</p>
                    </div>
                @elseif($isApproved)
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800"><i class="fas fa-circle-check mr-2 text-emerald-500"></i>Approved</p>
                        @if($ev->reviewed_at)<p class="text-sm text-emerald-700 mt-1">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm text-emerald-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                    </div>
                @else
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-red-800"><i class="fas fa-circle-xmark mr-2 text-red-500"></i>Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm text-red-600 mt-2"><span class="font-semibold">Reason:</span> {{ $ev->review_remarks }}</p>@endif
                    </div>
                @endif
            </div>

            @if($ev->description)
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">About This Event</h3>
                <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $ev->description }}</p>
            </div>
            @endif

            @if($ev->notes)
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Additional Notes</h3>
                <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $ev->notes }}</p>
            </div>
            @endif

            @if($ev->contact_person||$ev->contact_email||$ev->contact_phone)
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Contact Person</h3>
                <div class="space-y-2">
                    @if($ev->contact_person)
                    <p class="flex items-center gap-2.5 text-sm font-semibold" style="color:#333333;">
                        <i class="fas fa-user text-[#7a3f91] w-4"></i>{{ $ev->contact_person }}
                    </p>
                    @endif
                    @if($ev->contact_email)
                    <p class="flex items-center gap-2.5 text-sm font-semibold" style="color:#333333;">
                        <i class="fas fa-envelope text-[#7a3f91] w-4"></i>{{ $ev->contact_email }}
                    </p>
                    @endif
                    @if($ev->contact_phone)
                    <p class="flex items-center gap-2.5 text-sm font-semibold" style="color:#333333;">
                        <i class="fas fa-phone text-[#7a3f91] w-4"></i>{{ $ev->contact_phone }}
                    </p>
                    @endif
                </div>
            </div>
            @endif

            <div class="px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Posting Details</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 border border-gray-200 rounded-xl overflow-hidden divide-x divide-y divide-gray-100">
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Submitted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->created_at->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                        <p class="text-sm font-semibold mt-0.5" style="color:#555555;">{{ $ev->created_at->setTimezone('Asia/Manila')->format('g:i A') }}</p>
                    </div>
                    @if($updatedByDisplay)
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Last Updated By</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $updatedByDisplay }}</p>
                        <p class="text-xs font-semibold mt-0.5" style="color:#7a3f91;">{{ $roleDisplayLabel }}</p>
                        <p class="text-xs font-semibold mt-0.5" style="color:#555555;">{{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>
                    </div>
                    @endif
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Status</p>
                        @if($isCompleted)<p class="text-sm font-semibold text-green-700">Completed</p>
                        @elseif($ev->status==='PENDING')<p class="text-sm font-semibold text-amber-600">Pending</p>
                        @elseif($isApproved)<p class="text-sm font-semibold text-emerald-600">Approved</p>
                        @else<p class="text-sm font-semibold text-red-600">Rejected</p>@endif
                    </div>
                </div>
            </div>
        </div>

        {{-- FIX 1: removed all ORGANIZER_DELETED handling from footer --}}
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-2 flex-wrap bg-white flex-shrink-0">
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 rounded-xl transition"
                    style="color:#666666;">
                <i class="fas fa-xmark text-sm"></i> Close
            </button>

            @if($isCompleted)
                <button wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-xl transition">
                    <i class="fas fa-trophy text-sm"></i> Share Highlights
                </button>
            @elseif($isApproved)
                <button wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-xl transition">
                    <i class="fas fa-share-nodes text-sm"></i> Share
                </button>
            @elseif($ev->status==='PENDING')
                <button wire:click="confirmDelete({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmReject({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-xmark text-sm"></i> Reject
                </button>
                <button wire:click="confirmApprove({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-xl transition">
                    <i class="fas fa-check text-sm"></i> Approve
                </button>
            @elseif($ev->status==='REJECTED')
                <button wire:click="confirmDelete({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmApprove({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-xl transition">
                    <i class="fas fa-rotate-left text-sm"></i> Re-Approve
                </button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Approve ════ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelApprove()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 bg-emerald-50 border-b border-emerald-100">
            <h2 class="text-lg font-semibold text-emerald-800 flex items-center gap-2.5">
                <div class="w-9 h-9 bg-emerald-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-circle-check text-emerald-600 text-base"></i>
                </div>
                Approve Event
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1" style="color:#666666;">You are about to approve:</p>
            <p class="font-semibold text-emerald-700 text-base mb-4">"{{ $approveEventTitle }}"</p>
            <div class="mb-5">
                <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                    Remarks <span class="font-normal normal-case" style="color:#999999;">(Optional)</span>
                </label>
                <textarea wire:model.defer="approveRemarks" rows="2" placeholder="e.g. Approved. Great event proposal!"
                          class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 transition resize-none"
                          style="color:#333333;"></textarea>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelApprove"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition"
                        style="color:#333333;">Cancel</button>
                <button wire:click="executeApprove" wire:loading.attr="disabled" wire:target="executeApprove"
                        class="flex-1 px-4 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeApprove"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-circle-check mr-1"></i> Yes, Approve</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Reject ════ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelReject()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100">
            <h2 class="text-lg font-semibold text-red-800 flex items-center gap-2.5">
                <div class="w-9 h-9 bg-red-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-circle-xmark text-red-500 text-base"></i>
                </div>
                Reject Event
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1" style="color:#666666;">You are about to reject:</p>
            <p class="font-semibold text-red-700 text-base mb-4">"{{ $rejectEventTitle }}"</p>
            <div class="mb-5">
                <label class="block text-sm font-semibold uppercase tracking-wide mb-1.5" style="color:#333333;">
                    Reason for Rejection <span class="text-red-500">*</span>
                </label>
                <textarea wire:model.defer="rejectRemarks" rows="3" placeholder="e.g. Missing required details."
                          class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100 transition resize-none"
                          style="color:#333333;"></textarea>
                <p class="mt-1.5 text-xs" style="color:#999999;">
                    <i class="fas fa-circle-info mr-1"></i>Required — coordinator will see this reason.
                </p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelReject"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition"
                        style="color:#333333;">Cancel</button>
                <button wire:click="executeReject" wire:loading.attr="disabled" wire:target="executeReject"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeReject"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1"></i> Yes, Reject</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-semibold text-lg">Permanently Delete</h2>
        </div>
        <div class="p-6">
            <div class="flex flex-col items-center text-center mb-5">
                <div class="w-16 h-16 bg-red-50 border-2 border-red-200 rounded-full flex items-center justify-center mb-3">
                    <i class="fas fa-triangle-exclamation text-red-500 text-2xl"></i>
                </div>
                <p class="font-semibold text-sm" style="color:#333333;">Are you sure you want to permanently delete</p>
                <p class="font-semibold text-lg mt-1 text-red-700">"{{ $deleteEventTitle }}"?</p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3.5 mb-5 space-y-1.5">
                <p class="text-sm font-semibold text-red-800 flex items-center gap-2">
                    <i class="fas fa-circle-exclamation text-red-500"></i> This action cannot be undone.
                </p>
                <p class="text-sm text-red-700 pl-5">The event and its photo will be <strong>permanently removed</strong>.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition flex items-center justify-center gap-2"
                        style="color:#333333;">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: Share / Highlights
════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl   = $this->eventsBaseUrl();
    $shareHost      = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isShCompleted  = $shareEventStatus === 'COMPLETED';
    $shTimeDisplay  = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $shDescPreview  = mb_strlen($shareEventDescription) > 160
        ? mb_substr($shareEventDescription, 0, 160) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isShCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    }
    if ($shareEventVenue)  $fbLines[] = "📍 {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = $isShCompleted ? "👥 {$shareEventTarget}" : "👥 Open for: {$shareEventTarget}";
    $fbLines[] = '';
    if ($shareEventDescription) {
        $dPrev = mb_strlen($shareEventDescription) > 200
            ? mb_substr($shareEventDescription, 0, 200) . '…'
            : $shareEventDescription;
        $fbLines[] = $dPrev;
        $fbLines[] = '';
    }
    $fbLines[] = $isShCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $shareEventPhotoUrl
        && !str_contains($shareEventPhotoUrl, 'default')
        && str_contains($shareEventPhotoUrl, '/storage/');
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied:          false,
         fbCopied:        false,
         messengerCopied: false,
         fbCopyFailed:    false,

         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         photoUrl: {{ json_encode($shareEventPhotoUrl) }},
         hasPhoto: {{ $hasRealPhoto ? 'true' : 'false' }},

         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareModal(), 290);
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
             } catch(e) { console.warn('Plain text copy failed', e); }
         },

         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const htmlContent = [
                         '<img src=\'' + imageUrl + '\' alt=\'Event Photo\' style=\'max-width:600px;display:block;margin-bottom:12px;\'>',
                         '<pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>'
                     ].join('');
                     const htmlBlob = new Blob([htmlContent], { type: 'text/html' });
                     const textBlob = new Blob([text],        { type: 'text/plain' });
                     await navigator.clipboard.write([
                         new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })
                     ]);
                     return true;
                 }
             } catch(e) {
                 console.warn('Rich copy (image+text) failed, falling back to plain text:', e);
             }
             await this.copyPlainText(text);
             return false;
         },

         async shareOnFacebook() {
             const richCopied = await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied     = true;
             this.fbCopyFailed = !richCopied;
             const target   = (this.hasPhoto) ? this.photoUrl : this.baseUrl;
             const shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(target);
             window.open(shareUrl, '_blank', 'width=626,height=436,noopener,noreferrer');
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
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-lg font-semibold flex items-center gap-2" style="color:#333333;">
                @if($isShCompleted)
                    <i class="fas fa-trophy text-amber-500 text-lg"></i>
                    <span>Share Event Highlights</span>
                @else
                    <i class="fas fa-share-nodes text-sky-600 text-lg"></i>
                    <span>Share Event</span>
                @endif
            </h2>
            <button @click="close()" type="button"
                    class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer"
                    style="color:#999999;">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-semibold uppercase tracking-widest flex-shrink-0" style="color:#999999;">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center" style="max-height:220px; overflow:hidden;">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full object-contain"
                             style="max-height:220px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color: {{ $isShCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#555555;">
                            {{ $shareEventDate }}@if($shTimeDisplay) · {{ $shTimeDisplay }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100" style="color:#7a3f91;">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareEventTarget, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shDescPreview)
                    <div class="px-5 py-3.5 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#555555;">{{ $shDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#999999;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#666666;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> opens the share dialog
                            <em>and</em> copies both the event photo + caption to your clipboard.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd>
                            in the composer and the image pastes automatically with the text.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Staff Chat</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Posts the event photo + caption directly to the <strong>Directors &amp; Coordinators</strong> chat
                            @if($shareEventId)
                                @php
                                    // FIX 2: no whereNull filter so deactivated coordinators still found
                                    $orgIdForInfo = AdminEvent::withoutTrashed()->find($shareEventId)?->organizer_id;
                                    $orgForInfo   = $orgIdForInfo
                                        ? DB::table('organizer')->where('id', $orgIdForInfo)->first(['id', 'first_name','last_name'])
                                        : null;
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
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Share via</p>

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
                           x-text="fbCopyFailed
                               ? 'Caption copied as text only — paste in the post.'
                               : 'Press Ctrl+V in the post to paste the photo + caption!'"></p>
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
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
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
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_dir2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_dir2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or post to staff</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isShCompleted ? 'Post Highlights to Staff Chat' : 'Post to Staff Chat' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">
                            Directors &amp; Coordinators · photo included
                        </span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white"
                        style="color:#333333;">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#999999;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1"
                        style="color:#666666;">
                    <i class="fas fa-xmark mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>