{{-- resources/views/livewire/organizer/event-organizer.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\OrganizerEvent;
use App\Models\AuditLog;
use App\Http\Controllers\OrganizerEventController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterStatus = '';

    public bool   $showFormModal  = false;
    public bool   $isEditing      = false;
    public ?int   $editingEventId = null;

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
    public string $batchYear      = '';

    public array  $selectedCourses = [];

    public $photo                    = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto       = false;

    public array  $formErrors = [];

    public bool  $showViewModal  = false;
    public ?int  $viewingEventId = null;

    public bool $showNoAlumniModal = false;

    public bool   $isResubmitting           = false;
    public string $resubmitEventTitle       = '';
    public string $resubmitEventRemarks     = '';

    public bool   $isRestoring        = false;
    public string $restoreEventTitle  = '';

    // ── Confirm modals ──
    public bool   $showDeleteModal   = false;
    public ?int   $pendingDeleteId   = null;
    public string $pendingDeleteTitle = '';

    // ── Restore confirm modal (unified — future & past date) ──
    public bool   $showRestoreModal   = false;
    public ?int   $pendingRestoreId   = null;
    public string $pendingRestoreTitle = '';
    public bool   $restoreDateIsPast  = false;   // true = past date → YES opens view details

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

        $user = Auth::user();
        if (!$user || !$user->organizer) {
            abort(403, 'Access denied.');
        }

        $sessionFilter = session()->pull('organizer_events_filter', null);
        if ($sessionFilter !== null) {
            $this->filterStatus = $sessionFilter;
        }

        $orgId = $user->organizer->id;

        $throttleKey = "auto_event_ops_{$orgId}";
        if (!Cache::has($throttleKey)) {
            Cache::put($throttleKey, true, now()->addMinutes(5));
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
        }
    }

    // ── Auto-ops: NO event-management-updated dispatch (no notif for auto events) ──
    private function autoRejectExpiredPendingEvents(): void
    {
        $orgId = Auth::user()?->organizer?->id;
        if (!$orgId) return;

        $now = \Carbon\Carbon::now('UTC');

        $affected = OrganizerEvent::where('organizer_id', $orgId)
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->get(['id', 'title']);

        if ($affected->isEmpty()) return;

        OrganizerEvent::where('organizer_id', $orgId)
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->update([
                'status'         => 'REJECTED',
                'review_remarks' => 'Auto-rejected: event date has already passed without Alumni Director approval.',
            ]);

        // No dispatch — auto-ops do not generate notifs
    }

    private function autoCompleteExpiredEvents(): void
    {
        $orgId = Auth::user()?->organizer?->id;
        if (!$orgId) return;

        $now = \Carbon\Carbon::now('UTC');

        $query = fn() => OrganizerEvent::where('organizer_id', $orgId)
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })
                ->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            });

        $affected = $query()->get(['id', 'title']);

        if ($affected->isEmpty()) return;

        $query()->update(['status' => 'COMPLETED']);

        // No dispatch — auto-ops do not generate notifs
    }

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }

    #[Computed]
    public function organizerDepartment(): string
    {
        return Auth::user()?->organizer?->department ?? '';
    }

    #[Computed]
    public function organizerName(): string
    {
        return Auth::user()?->organizer?->name ?? Auth::user()?->name ?? '';
    }

    #[Computed]
    public function organizerEmail(): string
    {
        return Auth::user()?->organizer?->email ?? Auth::user()?->email ?? '';
    }

    #[Computed]
    public function organizerId(): ?int
    {
        return Auth::user()?->organizer?->id;
    }

    #[Computed]
    public function availableCourses(): array
    {
        $dept = $this->organizerDepartment;
        if (!$dept) return [];

        $cacheKey = 'organizer_courses_' . $dept;
        return Cache::remember($cacheKey, 300, function () use ($dept) {
            return Alumni::where('alumni.status', 'VERIFIED')
                ->join('courses', 'alumni.course_code', '=', 'courses.code')
                ->where('courses.college', $dept)
                ->select('courses.code')
                ->distinct()
                ->orderBy('courses.code')
                ->pluck('courses.code')
                ->toArray();
        });
    }

    #[Computed]
    public function hasAlumni(): bool
    {
        $dept     = $this->organizerDepartment;
        $cacheKey = 'organizer_has_alumni_' . ($dept ?: 'all');
        return Cache::remember($cacheKey, 300, function () use ($dept) {
            $q = Alumni::where('status', 'VERIFIED');
            if ($dept) {
                $q->join('courses', 'alumni.course_code', '=', 'courses.code')
                  ->where('courses.college', $dept);
            }
            return $q->exists();
        });
    }

    #[Computed]
    public function events()
    {
        $orgId = $this->organizerId;
        if (!$orgId) abort(403);

        $q = OrganizerEvent::where('organizer_id', $orgId)
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED', 'ORGANIZER_DELETED']);

        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '' && in_array($this->filterStatus, ['PENDING','APPROVED','REJECTED','COMPLETED','ORGANIZER_DELETED'], true)) {
            $q->where('status', $this->filterStatus);
        }

        $q->orderBy('created_at', 'desc');
        return $q->paginate(15);
    }

    #[Computed]
    public function viewingEvent(): ?OrganizerEvent
    {
        if (!$this->viewingEventId) return null;
        return OrganizerEvent::where('id', $this->viewingEventId)
            ->where('organizer_id', $this->organizerId)
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])->first();
    }

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = '';
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        if (!$this->hasAlumni) {
            $this->showNoAlumniModal = true;
            return;
        }
        $this->resetFormFields();
        $this->start_time     = '08:00';
        $this->end_time       = '17:00';
        $this->contact_person = $this->organizerName;
        $this->contact_email  = $this->organizerEmail;
        $this->isEditing      = false;
        $this->showFormModal  = true;
    }

    public function closeNoAlumniModal(): void { $this->showNoAlumniModal = false; }

    private function populateEditForm(OrganizerEvent $event): void
    {
        $this->isEditing        = true;
        $this->editingEventId   = $event->id;
        $this->title            = $event->title;
        $this->description      = $event->description ?? '';
        $this->event_date       = $event->event_date->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->start_time       = $event->event_date->setTimezone('Asia/Manila')->format('H:i');
        $this->end_time         = $event->event_end_date?->setTimezone('Asia/Manila')->format('H:i') ?? '';
        $this->venue            = $event->venue;
        $this->venue_address    = $event->venue_address ?? '';
        $this->contact_person   = $event->contact_person ?? $this->organizerName;
        $this->contact_email    = $event->contact_email  ?? $this->organizerEmail;
        $this->contact_phone    = $event->contact_phone  ?? '';
        $this->notes            = $event->notes ?? '';
        $this->existingPhotoUrl = $event->photo_url;
        $this->removePhoto      = false;
        $this->photo            = null;
        $this->formErrors       = [];

        $tp    = $event->target_participants ?? '';
        $parts = explode(' · Batch ', $tp, 2);
        $coursesPart = trim($parts[0] ?? '');
        $this->batchYear = trim($parts[1] ?? '');

        $this->selectedCourses = !empty($coursesPart) && $coursesPart !== 'All Courses'
            ? array_map('trim', explode(',', $coursesPart))
            : [];
    }

    public function openEditModal(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->firstOrFail();

        if ($event->status === 'REJECTED') {
            $this->isResubmitting = true;
            $this->resubmitEventTitle   = $event->title;
            $this->resubmitEventRemarks = $event->review_remarks ?? '';
            $this->populateEditForm($event);
            $this->showFormModal = true;
            $this->showViewModal = false;
            return;
        }

        if ($event->status === 'ORGANIZER_DELETED') {
            $this->isRestoring       = true;
            $this->restoreEventTitle = $event->title;
            $this->populateEditForm($event);
            $this->showFormModal = true;
            $this->showViewModal = false;
            return;
        }

        $this->isResubmitting = false;
        $this->isRestoring    = false;
        $this->populateEditForm($event);
        $this->showFormModal = true;
        $this->showViewModal = false;
    }

    public function viewEvent(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->firstOrFail();

        if ($event->status === 'PENDING') {
            $this->isResubmitting = false;
            $this->isRestoring    = false;
            $this->populateEditForm($event);
            $this->showFormModal = true;
            $this->showViewModal = false;
            return;
        }

        if ($event->status === 'REJECTED') {
            $this->isResubmitting = true;
            $this->resubmitEventTitle   = $event->title;
            $this->resubmitEventRemarks = $event->review_remarks ?? '';
            $this->populateEditForm($event);
            $this->showFormModal = true;
            $this->showViewModal = false;
            return;
        }

        if ($event->status === 'ORGANIZER_DELETED') {
            $this->isRestoring       = true;
            $this->restoreEventTitle = $event->title;
            $this->populateEditForm($event);
            $this->showFormModal = true;
            $this->showViewModal = false;
            return;
        }

        $this->viewingEventId = $id;
        $this->showViewModal  = true;
    }

    // ── Delete: open confirm modal ──
    public function confirmDelete(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)
            ->where('organizer_id', $this->organizerId)
            ->where('status', 'PENDING')
            ->firstOrFail();

        $this->pendingDeleteId    = $id;
        $this->pendingDeleteTitle = $event->title;
        $this->showDeleteModal    = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal    = false;
        $this->pendingDeleteId    = null;
        $this->pendingDeleteTitle = '';
    }

    public function deleteEvent(): void
    {
        if (!$this->pendingDeleteId) return;

        $event = OrganizerEvent::where('id', $this->pendingDeleteId)
            ->where('organizer_id', $this->organizerId)
            ->where('status', 'PENDING')
            ->firstOrFail();

        $user = Auth::user();

        $event->update([
            'status'          => 'ORGANIZER_DELETED',
            'deleted_by'      => $user?->name,
            'deleted_by_role' => $user?->role ?? 'organizer',
        ]);

        try {
            AuditLog::create([
                'user_id'       => Auth::id(),
                'user_name'     => Auth::user()?->name ?? 'Organizer',
                'user_email'    => Auth::user()?->email,
                'user_role'     => 'organizer',
                'action'        => 'deleted',
                'module'        => 'event',
                'subject_id'    => $this->pendingDeleteId,
                'subject_label' => $event->title,
                'description'   => "Organizer deleted pending event: '{$event->title}'.",
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'severity'      => 'warning',
            ]);
        } catch (\Throwable) {}

        // NO event-management-updated dispatch for delete
        $this->dispatch('flash-message', type: 'success', message: 'Event deleted. You can restore it anytime from the Deleted filter.');

        $this->showDeleteModal    = false;
        $this->pendingDeleteId    = null;
        $this->pendingDeleteTitle = '';
    }

    // ── Restore: unified confirm modal ──
    //    Future date → YES = auto-restore to PENDING
    //    Past date   → YES = open view details (so they can edit date)
    public function confirmRestore(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)
            ->where('organizer_id', $this->organizerId)
            ->where('status', 'ORGANIZER_DELETED')
            ->firstOrFail();

        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');

        $this->pendingRestoreId    = $id;
        $this->pendingRestoreTitle = $event->title;
        $this->restoreDateIsPast   = ! $eventDatePH->isFuture();
        $this->showRestoreModal    = true;
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal    = false;
        $this->pendingRestoreId    = null;
        $this->pendingRestoreTitle = '';
        $this->restoreDateIsPast   = false;
    }

    public function proceedRestore(): void
    {
        if (!$this->pendingRestoreId) return;

        if ($this->restoreDateIsPast) {
            // Past date → open view details so coordinator can edit date
            $id = $this->pendingRestoreId;
            $this->showRestoreModal    = false;
            $this->pendingRestoreId    = null;
            $this->pendingRestoreTitle = '';
            $this->restoreDateIsPast   = false;
            // Open view details (not edit form)
            $this->viewingEventId = $id;
            $this->showViewModal  = true;
            return;
        }

        // Future date → auto-restore directly to PENDING
        $event = OrganizerEvent::where('id', $this->pendingRestoreId)
            ->where('organizer_id', $this->organizerId)
            ->where('status', 'ORGANIZER_DELETED')
            ->firstOrFail();

        $event->update([
            'status'         => 'PENDING',
            'review_remarks' => null,
            'reviewed_at'    => null,
        ]);

        try {
            AuditLog::create([
                'user_id'       => Auth::id(),
                'user_name'     => Auth::user()?->name ?? 'Organizer',
                'user_email'    => Auth::user()?->email,
                'user_role'     => 'organizer',
                'action'        => 'restored',
                'module'        => 'event',
                'subject_id'    => $this->pendingRestoreId,
                'subject_label' => $event->title,
                'description'   => "Organizer restored deleted event: '{$event->title}' — resubmitted for Alumni Director review.",
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'severity'      => 'info',
            ]);
        } catch (\Throwable) {}

        // NO event-management-updated dispatch for restore
        $this->dispatch('flash-message', type: 'success', message: 'Event restored and resubmitted for Alumni Director review!');

        $this->showRestoreModal    = false;
        $this->pendingRestoreId    = null;
        $this->pendingRestoreTitle = '';
        $this->restoreDateIsPast   = false;
    }

    public function resetForm(): void
    {
        $savedId         = $this->editingEventId;
        $savedIsEditing  = $this->isEditing;
        $savedIsResubmit = $this->isResubmitting;
        $savedIsRestore  = $this->isRestoring;

        $this->resetFormFields();

        $this->start_time = '08:00';
        $this->end_time   = '17:00';

        $this->editingEventId = $savedId;
        $this->isEditing      = $savedIsEditing;
        $this->isResubmitting = $savedIsResubmit;
        $this->isRestoring    = $savedIsRestore;

        $this->contact_person = $this->organizerName;
        $this->contact_email  = $this->organizerEmail;

        $this->dispatch('reset-time-selects');
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function saveEvent(): void
    {
        $key = 'save_event_' . Auth::id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->formErrors = ['rate_limit' => 'Too many attempts. Please wait a moment before trying again.'];
            return;
        }
        RateLimiter::hit($key, 60);

        $this->formErrors = [];
        $errors = [];

        $this->title          = strip_tags(trim($this->title));
        $this->description    = strip_tags(trim($this->description));
        $this->venue          = strip_tags(trim($this->venue));
        $this->venue_address  = strip_tags(trim($this->venue_address));
        $this->contact_person = strip_tags(trim($this->contact_person));
        $this->contact_phone  = preg_replace('/[^0-9+\-\s()]/', '', trim($this->contact_phone));
        $this->notes          = strip_tags(trim($this->notes));

        if ($this->contact_email && !filter_var($this->contact_email, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Please enter a valid email address.';
        }

        if (trim($this->contact_phone) !== '') {
            $phoneRaw = preg_replace('/[\s\-\(\)]/', '', $this->contact_phone);
            if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phoneRaw)) {
                $errors['contact_phone'] = 'Enter a valid PH mobile number: 09XXXXXXXXX or +639XXXXXXXXX.';
            }
        }

        if (!trim($this->title))         $errors['title']         = 'Event title is required.';
        if (!trim($this->description))   $errors['description']   = 'Event description is required.';
        if (!trim($this->event_date))    $errors['event_date']    = 'Event date is required.';
        if (!trim($this->venue))         $errors['venue']         = 'Venue / Location is required.';
        if (!trim($this->venue_address)) $errors['venue_address'] = 'Full address is required.';

        if (!trim($this->start_time)) {
            $errors['start_time'] = 'Start time is required.';
        }

        if (!trim($this->end_time)) {
            $errors['end_time'] = 'End time is required.';
        }

        if (
            !isset($errors['end_time'])
            && !isset($errors['start_time'])
            && trim($this->start_time)
            && trim($this->end_time)
            && trim($this->start_time) === trim($this->end_time)
        ) {
            $errors['end_time']   = 'End time cannot be the same as start time.';
            $errors['start_time'] = 'Start time cannot be the same as end time.';
        }

        if (
            !isset($errors['end_time'])
            && !isset($errors['start_time'])
            && trim($this->start_time)
            && trim($this->end_time)
        ) {
            try {
                $startC = \Carbon\Carbon::createFromFormat('H:i', trim($this->start_time));
                $endC   = \Carbon\Carbon::createFromFormat('H:i', trim($this->end_time));
                if ($endC->lt($startC)) {
                    $errors['end_time'] = 'End time cannot be earlier than start time.';
                }
            } catch (\Exception $e) {}
        }

        if (!isset($errors['event_date']) && !isset($errors['start_time'])
            && trim($this->event_date) && trim($this->start_time)) {
            try {
                $proposedStart = \Carbon\Carbon::createFromFormat(
                    'Y-m-d H:i',
                    trim($this->event_date) . ' ' . trim($this->start_time),
                    'Asia/Manila'
                );
                if ($proposedStart->isPast()) {
                    $errors['event_date'] = $this->isRestoring
                        ? 'Please set a future date to restore this event.'
                        : 'Event date and start time cannot be in the past. Please choose a future date and time.';
                }
            } catch (\Exception $e) {}
        }

        $availableCourses = $this->availableCourses;
        if (!empty($availableCourses) && empty($this->selectedCourses)) {
            $errors['selected_courses'] = 'Please select at least one course or program, or click "Select All".';
        }

        if (!$this->isEditing) {
            $dept        = $this->organizerDepartment;
            $alumniBaseQ = Alumni::where('status', 'VERIFIED');
            if ($dept) { $alumniBaseQ->whereHas('course', fn($c) => $c->where('college', $dept)); }
            if (!$alumniBaseQ->exists()) {
                $errors['target'] = 'Cannot create event — no verified alumni found for ' . ($dept ?: 'your college') . '.';
            }
        }

        if (!isset($errors['title']) && trim($this->title)) {
            $dupQuery = OrganizerEvent::where('organizer_id', $this->organizerId)
                ->whereRaw('LOWER(title) = ?', [strtolower(trim($this->title))])
                ->whereIn('status', ['PENDING', 'APPROVED']);
            if ($this->isEditing && $this->editingEventId) {
                $dupQuery->where('id', '!=', $this->editingEventId);
            }
            if ($dupQuery->exists()) {
                $errors['title'] = 'A PENDING or APPROVED event with this title already exists. Please use a different title.';
            }
        }

        if (trim($this->batchYear) !== '') {
            if (!preg_match('/^\d{4}$/', trim($this->batchYear))) {
                $errors['batch_year'] = 'Batch year must be a valid 4-digit year (numbers only, e.g. ' . now()->year . ').';
            }
        }

        if (trim($this->batchYear) !== '' && !isset($errors['target']) && !isset($errors['batch_year'])) {
            $inputYear = (int) trim($this->batchYear);
            $dept = $this->organizerDepartment;
            $q = Alumni::where('status', 'VERIFIED')->where('batch', $inputYear);
            if ($dept) { $q->whereHas('course', fn($c) => $c->where('college', $dept)); }
            if (!$q->exists()) {
                $suggQ = Alumni::where('status', 'VERIFIED');
                if ($dept) { $suggQ->whereHas('course', fn($c) => $c->where('college', $dept)); }
                $available = $suggQ->distinct()->orderBy('batch', 'desc')
                    ->pluck('batch')->map(fn($b) => (int)$b)->toArray();
                if (empty($available)) {
                    $errors['batch_year'] = "No verified alumni found for your college. Leave batch blank to target all alumni.";
                } else {
                    $nearest   = collect($available)->sortBy(fn($y) => abs($y - $inputYear))->first();
                    $batchList = implode(', ', array_slice($available, 0, 8));
                    if (count($available) > 8) $batchList .= '…';
                    $errors['batch_year'] = "No verified alumni for batch {$inputYear}."
                        . ($nearest ? " Nearest: {$nearest}." : '') . " Available: {$batchList}.";
                }
            }
        }

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        $courseStr  = !empty($this->selectedCourses) ? implode(', ', $this->selectedCourses) : 'All Courses';
        $yearSuffix = trim($this->batchYear) ? ' · Batch ' . trim($this->batchYear) : '';
        $targetStr  = $courseStr . $yearSuffix;

        $startDt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $this->event_date . ' ' . $this->start_time, 'Asia/Manila');
        $endDt   = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $this->event_date . ' ' . $this->end_time,   'Asia/Manila');

        $startDtUtc = $startDt->copy()->utc();
        $endDtUtc   = $endDt->copy()->utc();

        $data = [
            'title'               => trim($this->title),
            'description'         => trim($this->description),
            'event_date'          => $startDtUtc->format('Y-m-d H:i:s'),
            'event_end_date'      => $endDtUtc->format('Y-m-d H:i:s'),
            'venue'               => trim($this->venue),
            'venue_address'       => trim($this->venue_address),
            'target_participants' => $targetStr,
            'contact_person'      => trim($this->contact_person) ?: null,
            'contact_email'       => trim($this->contact_email)  ?: null,
            'contact_phone'       => trim($this->contact_phone)  ?: null,
            'notes'               => trim($this->notes)          ?: null,
        ];

        if ($this->isResubmitting) {
            $data['status']         = 'PENDING';
            $data['review_remarks'] = null;
            $data['reviewed_at']    = null;
        }

        if ($this->isRestoring) {
            $data['status']         = 'PENDING';
            $data['review_remarks'] = null;
            $data['reviewed_at']    = null;
        }

        $ctrl  = app(OrganizerEventController::class);
        $photo = $this->photo;

        // ── Determine which actions dispatch a notif ──
        // ONLY 'updated' and 'resubmitted' dispatch — NOT 'created' or 'restored'
        $notifAction = $this->isRestoring
            ? null                                      // restored → no notif
            : ($this->isResubmitting
                ? 'resubmitted'                         // resubmitted → notif (optional)
                : ($this->isEditing ? 'updated' : null) // updated → notif, created → NO notif
            );

        if ($this->isEditing) {
            $event = OrganizerEvent::where('id', $this->editingEventId)
                ->where('organizer_id', $this->organizerId)->firstOrFail();

            $oldValues = [
                'title'               => $event->title,
                'description'         => $event->description,
                'event_date'          => $event->event_date?->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                'event_end_date'      => $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A'),
                'venue'               => $event->venue,
                'venue_address'       => $event->venue_address,
                'target_participants' => $event->target_participants,
                'notes'               => $event->notes,
            ];

            if ($this->removePhoto && !$photo) {
                if ($event->photo && $event->photo !== OrganizerEvent::DEFAULT_PHOTO) {
                    Storage::disk('public')->delete($event->photo);
                }
                $data['photo'] = null;
                $event->update(array_merge($data, [
                    'updated_by'      => auth()->user()?->name,
                    'updated_by_role' => 'organizer',
                ]));
            } else {
                if ($this->isResubmitting) {
                    $event->update([
                        'status'         => 'PENDING',
                        'review_remarks' => null,
                        'reviewed_at'    => null,
                    ]);
                    unset($data['status'], $data['review_remarks'], $data['reviewed_at']);
                }
                if ($this->isRestoring) {
                    $event->update([
                        'status'         => 'PENDING',
                        'review_remarks' => null,
                        'reviewed_at'    => null,
                    ]);
                    unset($data['status'], $data['review_remarks'], $data['reviewed_at']);
                }
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }

            if ($this->isRestoring) {
                $action      = 'restored';
                $description = "Organizer restored deleted event: '" . trim($this->title) . "' — resubmitted for Alumni Director review.";
            } elseif ($this->isResubmitting) {
                $action      = 'resubmitted';
                $description = "Organizer resubmitted rejected event: '" . trim($this->title) . "' for Alumni Director review.";
            } else {
                $action      = 'updated';
                $description = "Organizer updated event: '" . trim($this->title) . "'.";
            }

            try {
                AuditLog::create([
                    'user_id'       => Auth::id(),
                    'user_name'     => Auth::user()?->name ?? 'Organizer',
                    'user_email'    => Auth::user()?->email,
                    'user_role'     => 'organizer',
                    'action'        => $action,
                    'module'        => 'event',
                    'subject_id'    => $this->editingEventId,
                    'subject_label' => trim($this->title),
                    'description'   => $description,
                    'old_values'    => $oldValues,
                    'new_values'    => [
                        'title'               => trim($this->title),
                        'description'         => trim($this->description),
                        'event_date'          => $startDt->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                        'venue'               => trim($this->venue),
                        'target_participants' => $targetStr,
                    ],
                    'ip_address'    => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                    'severity'      => 'info',
                ]);
            } catch (\Throwable) {}

            if ($this->isRestoring) {
                $msg = 'Event restored and resubmitted for Alumni Director review!';
            } elseif ($this->isResubmitting) {
                $msg = 'Event resubmitted for Alumni Director review!';
            } else {
                $msg = 'Event updated successfully!';
            }

            $this->dispatch('flash-message', type: 'success', message: $msg);
        } else {
            // CREATE — no notif dispatch
            $ctrl->createEvent($data, $photo ?: null);

            try {
                AuditLog::create([
                    'user_id'       => Auth::id(),
                    'user_name'     => Auth::user()?->name ?? 'Organizer',
                    'user_email'    => Auth::user()?->email,
                    'user_role'     => 'organizer',
                    'action'        => 'created',
                    'module'        => 'event',
                    'subject_label' => trim($this->title),
                    'description'   => "Organizer submitted new event: '" . trim($this->title) . "' for Alumni Director review.",
                    'new_values'    => [
                        'title'               => trim($this->title),
                        'description'         => trim($this->description),
                        'event_date'          => $startDt->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                        'venue'               => trim($this->venue),
                        'target_participants' => $targetStr,
                    ],
                    'ip_address'    => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                    'severity'      => 'info',
                ]);
            } catch (\Throwable) {}

            $this->dispatch('flash-message', type: 'success', message: 'Event submitted for Alumni Director review!');
        }

        // Only dispatch event-management-updated for UPDATED action
        // NOT for created, deleted, restored
        $savedEventId = $this->isEditing ? $this->editingEventId : null;
        if ($notifAction !== null) {
            $this->dispatch('event-management-updated', id: $savedEventId, title: trim($this->title), action: $notifAction);
        }

        Cache::forget('organizer_has_alumni_' . ($this->organizerDepartment ?: 'all'));
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function closeViewModal(): void { $this->showViewModal = false; $this->viewingEventId = null; }

    public function openShareModal(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)
            ->where('organizer_id', $this->organizerId)
            ->firstOrFail();

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
        if (!$this->shareEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $event = OrganizerEvent::where('id', $this->shareEventId)
            ->where('organizer_id', $this->organizerId)
            ->first();

        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $tp          = $event->target_participants ?? '';
        $tpParts     = explode(' · Batch ', $tp, 2);
        $coursesPart = trim($tpParts[0] ?? '');
        $batchYear   = trim($tpParts[1] ?? '');

        $roomQuery = DB::table('chat_rooms')
            ->join('courses', 'chat_rooms.course_code', '=', 'courses.code')
            ->where('courses.college', $this->organizerDepartment)
            ->select('chat_rooms.id', 'chat_rooms.course_code', 'chat_rooms.batch');

        if (!empty($batchYear)) {
            $roomQuery->where('chat_rooms.batch', $batchYear);
        }

        if ($coursesPart !== 'All Courses' && !empty($coursesPart)) {
            $courseCodes = array_map('trim', explode(',', $coursesPart));
            $roomQuery->whereIn('chat_rooms.course_code', $courseCodes);
        }

        $rooms = $roomQuery->get();

        if ($rooms->isEmpty()) {
            $this->dispatch('flash-message', type: 'error', message: 'No batch chat rooms found for this event\'s target participants.');
            return;
        }

        $isCompleted = $this->shareEventStatus === 'COMPLETED';
        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr     = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
        $baseUrl     = $this->eventsBaseUrl();
        $orgId       = $this->organizerId;

        if ($isCompleted) {
            $lines = [
                "📢 @everyone — Event Highlights!",
                "━━━━━━━━━━━━━━━━━━━━━━━━",
                "🏆 {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ];
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 {$event->target_participants}";
            $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "Thanks to everyone who joined! 🎉 Check the Events page for more → {$baseUrl}";
        } else {
            $lines = [
                "📢 @everyone — Event Alert!",
                "",
                "📅 {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ];
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 Open for: {$event->target_participants}";
            $lines[] = "";
            $lines[] = "Check it out & RSVP on the Events page! 🎉 → {$baseUrl}";
        }

        $body = implode("\n", $lines);

        foreach ($rooms as $room) {
            $msgId = DB::table('chat_messages')->insertGetId([
                'room_id'     => $room->id,
                'sender_type' => 'organizer',
                'sender_id'   => $orgId,
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
        }

        $roomCount = $rooms->count();
        $label = $isCompleted
            ? "Event highlights posted to {$roomCount} batch chat(s) with @everyone! 🏆"
            : "Event posted to {$roomCount} batch chat(s) with @everyone! 🎉";

        $this->dispatch('flash-message', type: 'success', message: $label);
        $this->closeShareModal();
    }

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_phone = $this->notes = '';
        $this->contact_person = '';
        $this->contact_email  = '';
        $this->batchYear      = '';
        $this->selectedCourses = [];
        $this->photo          = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto    = false;
        $this->formErrors     = [];
        $this->editingEventId = null;
        $this->isEditing      = false;
        $this->isResubmitting = false;
        $this->isRestoring    = false;
        $this->resubmitEventTitle   = '';
        $this->resubmitEventRemarks = '';
        $this->restoreEventTitle    = '';
    }
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
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

.time-select-wrap select {
    border: none;
    outline: none;
    background: #fff;
    font-size: 0.875rem;
    color: #333333;
    cursor: pointer;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    text-align: center;
    padding: 0.65rem 0.4rem;
    flex: 1;
    min-width: 0;
}
.time-select-wrap select:focus { background: #faf7fc; }

select.tw-select-arrow {
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
</style>

{{-- Hover tooltip --}}
<div id="eo-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

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
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Event Management</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">
                    Manage and submit events for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $this->organizerDepartment ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-calendar-days text-purple-600 text-[10px]"></i>
                {{ $this->events->total() }} Event{{ $this->events->total() !== 1 ? 's' : '' }}
            </span>

            <div class="relative inline-flex group">
                <button wire:click="openCreateModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] {{ !$this->hasAlumni ? 'opacity-60 cursor-not-allowed' : '' }}">
                    <i class="fas fa-plus text-sm"></i>
                </button>
                <div class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                    <i class="fas fa-plus text-[9px] mr-1"></i>Submit Event
                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    @if(!$this->hasAlumni)
    <div class="flex-shrink-0 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
        <div>
            <p class="font-semibold text-sm text-[#333333]">No verified alumni found for {{ $this->organizerDepartment ?: 'your college' }}.</p>
            <p class="mt-0.5 text-xs text-[#555555]">You cannot post events until at least one verified alumni is registered under your college.</p>
        </div>
    </div>
    @endif

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-shrink-0" style="height: 75vh; max-height: 75vh; overflow: hidden;">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or venue…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] placeholder-[#a78bbd] font-normal
                              hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
                <option value="ORGANIZER_DELETED">Deleted</option>
            </select>

            @if($filterStatus)
            @php
                $pillMap = [
                    'PENDING'   => ['label' => 'Pending',   'cls' => 'bg-yellow-50 border-yellow-300 text-yellow-800'],
                    'APPROVED'  => ['label' => 'Approved',  'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'REJECTED'  => ['label' => 'Rejected',  'cls' => 'bg-orange-50 border-orange-300 text-orange-800'],
                    'COMPLETED' => ['label' => 'Completed', 'cls' => 'bg-green-50 border-green-300 text-green-800'],
                    'ORGANIZER_DELETED' => ['label' => 'Deleted', 'cls' => 'bg-red-50 border-red-300 text-red-700'],
                ];
                $pill = $pillMap[$filterStatus] ?? null;
            @endphp
            @if($pill)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border {{ $pill['cls'] }}">
                <i class="fas fa-filter text-[9px]"></i>
                {{ $pill['label'] }}
                <button wire:click="$set('filterStatus', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif
            @endif

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($this->events->count() > 0)

            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto scroll-c bg-white">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10 text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Event Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Date &amp; Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell text-[#555555]">Course</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest w-28 text-[#555555]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $isDeleted   = $event->status === 'ORGANIZER_DELETED';

                            $tp          = $event->target_participants ?? '';
                            $tpParts     = explode(' · Batch ', $tp, 2);
                            $displayCourses = trim($tpParts[0]) ?: ($this->organizerDepartment ?: 'All Courses');
                            $batchDisplay   = !empty($tpParts[1]) ? trim($tpParts[1]) : null;

                            $eventDate  = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum     = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                        @endphp

                        <tr class="transition-colors duration-100 cursor-pointer {{ $isDeleted ? 'bg-red-50/60 opacity-80 hover:opacity-100 hover:bg-red-100/60' : 'bg-white hover:bg-[#f5f0fa]' }}"
                            wire:click="viewEvent({{ $event->id }})"
                            wire:key="event-row-{{ $event->id }}"
                            data-eo-row>

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2 {{ $isDeleted ? 'text-red-400 line-through' : 'text-[#333333]' }}">{{ $event->title }}</p>
                                    <p class="text-xs mt-0.5 text-[#666666]">{{ $eventDate->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold {{ $isDeleted ? 'text-red-300' : 'text-[#333333]' }}">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5 text-[#555555]">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 py-3.5 hidden xl:table-cell">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 w-fit max-w-[160px] truncate block">
                                        {{ Str::limit($displayCourses, 20) }}
                                    </span>
                                    @if($batchDisplay)
                                        <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-gray-100 border border-gray-200 w-fit text-[#333333]">
                                            Batch {{ $batchDisplay }}
                                        </span>
                                    @endif
                                </div>
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
                                @elseif($isDeleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-600 whitespace-nowrap">
                                        <i class="fas fa-trash-can text-[9px] mr-1"></i>Deleted
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>

                                    @if($isApproved || $isCompleted)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button"
                                                    wire:click.stop="openShareModal({{ $event->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400">
                                                <i class="fas fa-share-nodes"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Share
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
                                    @endif

                                    @if($isPending)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button"
                                                    wire:click.stop="confirmDelete({{ $event->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 hover:border-red-400">
                                                <i class="fas fa-trash-can"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Delete
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
                                    @endif

                                    @if($isDeleted)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button"
                                                    wire:click.stop="confirmRestore({{ $event->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 hover:border-emerald-400">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Restore
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
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
                    <p class="font-semibold text-base text-[#333333]">
                        @if($search || $filterStatus) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($search || $filterStatus) Try clearing your filters to see all events.
                        @else Click the <strong>+</strong> button to submit your first event for Alumni Director review.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>

        {{-- ── PAGINATION ── --}}
        @php
            $total    = $this->events->total();
            $pp       = $this->events->perPage();
            $cp       = $this->events->currentPage();
            $lp       = $this->events->lastPage();
            $from     = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to       = min($cp * $pp, $total);
            $pgStart  = max(1, $cp - 2);
            $pgEnd    = min($lp, $cp + 2);
        @endphp
        <div class="flex-shrink-0 border-t border-purple-800/30 px-4 flex items-center justify-between gap-2 flex-wrap min-h-[48px] py-1"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                event{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->events->onFirstPage()) disabled @endif
                        aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->events->hasMorePages()) disabled @endif
                        aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>

</div>


{{-- ══ DELETE CONFIRM MODAL ══ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDelete">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b border-red-100 bg-red-50">
            <h2 class="text-base font-semibold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-trash-can text-red-500 text-sm"></i>
                </div>
                Delete Event
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">Are you sure you want to delete:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $pendingDeleteTitle }}
            </p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-amber-800">The event will be moved to <strong>Deleted</strong> status. You can restore it anytime from the Deleted filter.</span>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer">
                    <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                </button>
                <button wire:click="deleteEvent"
                        wire:loading.attr="disabled"
                        wire:target="deleteEvent"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-60">
                    <span wire:loading wire:target="deleteEvent"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="deleteEvent"><i class="fas fa-trash-can mr-1 text-xs"></i></span>
                    Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ RESTORE CONFIRM MODAL ══
     Future date → YES = auto-restore to PENDING
     Past date   → YES = open view details (edit date from there)
══ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelRestore">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">

        <div class="px-6 py-4 border-b {{ $restoreDateIsPast ? 'border-amber-100 bg-amber-50' : 'border-emerald-100 bg-emerald-50' }}">
            <h2 class="text-base font-semibold {{ $restoreDateIsPast ? 'text-amber-800' : 'text-emerald-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $restoreDateIsPast ? 'bg-amber-100' : 'bg-emerald-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-rotate-left {{ $restoreDateIsPast ? 'text-amber-600' : 'text-emerald-600' }} text-sm"></i>
                </div>
                {{ $restoreDateIsPast ? 'Restore Event — Update Required' : 'Restore Event' }}
            </h2>
        </div>

        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">
                {{ $restoreDateIsPast ? 'The event date has already passed for:' : 'Are you sure you want to restore:' }}
            </p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $pendingRestoreTitle }}
            </p>

            @if($restoreDateIsPast)
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-amber-800">
                    The event date has already passed. Click <strong>View Details</strong> to open the event — you can update the date there and save to resubmit for Alumni Director review.
                </span>
            </div>
            @else
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-emerald-800">
                    The event will be restored to <strong>Pending</strong> status and resubmitted for Alumni Director review.
                </span>
            </div>
            @endif

            <div class="flex gap-2">
                <button wire:click="cancelRestore"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer">
                    <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                </button>
                <button wire:click="proceedRestore"
                        wire:loading.attr="disabled"
                        wire:target="proceedRestore"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-60
                               {{ $restoreDateIsPast ? 'bg-amber-500 hover:bg-amber-600' : 'bg-emerald-500 hover:bg-emerald-600' }}">
                    <span wire:loading wire:target="proceedRestore">
                        <i class="fas fa-spinner animate-spin mr-1 text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="proceedRestore">
                        <i class="fas {{ $restoreDateIsPast ? 'fa-eye' : 'fa-rotate-left' }} mr-1 text-xs"></i>
                    </span>
                    {{ $restoreDateIsPast ? 'View Details' : 'Yes, Restore' }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ NO ALUMNI MODAL ══ --}}
@if($showNoAlumniModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeNoAlumniModal">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 bg-amber-50 border-b border-amber-100">
            <h2 class="text-base font-semibold text-amber-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-triangle-exclamation text-amber-500 text-sm"></i>
                </div>
                Cannot Post Event
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm mb-1 text-[#555555]">No verified alumni found for:</p>
            <p class="font-semibold text-amber-700 text-base mb-4">{{ $this->organizerDepartment ?: 'Your College' }}</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 flex items-start gap-2 text-[#333333]">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-sm">You cannot create an event until at least one verified alumni is registered under your college.</span>
            </div>
            <button wire:click="closeNoAlumniModal"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333]">
                Close
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══ CREATE / EDIT / RESUBMIT / RESTORE — FULL SCREEN ══ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeFormModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3 flex-shrink-0 shadow-lg"
         style="background: {{ $isRestoring ? '#d97706' : '#7a3f91' }};">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                @if($isRestoring)
                    <i class="fas fa-rotate-left text-white text-sm"></i>
                @elseif($isResubmitting)
                    <i class="fas fa-rotate-right text-white text-sm"></i>
                @elseif($isEditing)
                    <i class="fas fa-pen-to-square text-white text-sm"></i>
                @else
                    <i class="fas fa-calendar-plus text-white text-sm"></i>
                @endif
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">
                    @if($isRestoring) Restore Event — Update Date
                    @elseif($isResubmitting) Edit &amp; Resubmit Event
                    @elseif($isEditing) Edit Event
                    @else Submit a New Event
                    @endif
                </h2>
                <p class="text-white/60 text-xs mt-0.5">
                    @if($isRestoring) Set a future date and save to resubmit for Alumni Director review
                    @elseif($isResubmitting) Make changes — saving will resubmit for Alumni Director review
                    @elseif($isEditing) Update event details below
                    @else Fill in details — will be sent for Alumni Director review
                    @endif
                </p>
            </div>
        </div>

        <div class="flex items-center gap-1.5">
            @if(!$isEditing && !$isResubmitting && !$isRestoring)
            <div class="relative inline-flex group">
                <button wire:click="resetForm" type="button"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Reset form">
                    <i class="fas fa-rotate-left text-white text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Reset
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
            @endif
            <div class="relative inline-flex group">
                <button wire:click="closeFormModal" type="button"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    @if($isRestoring)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 flex-shrink-0 flex items-start gap-3">
        <i class="fas fa-rotate-left text-amber-600 flex-shrink-0 text-xs mt-1"></i>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-[#333333]">
                <strong>Restoring:</strong> Update the <strong>date &amp; time</strong> to a future date, then click <strong>Save &amp; Restore</strong> to resubmit for Alumni Director approval.
            </p>
            <p class="text-xs mt-1 text-amber-700 flex items-center gap-1.5">
                <i class="fas fa-circle-info text-amber-500 text-[10px] flex-shrink-0"></i>
                A future date is required — the event will go back to <strong>Pending</strong> status after saving.
            </p>
        </div>
    </div>
    @endif

    @if($isResubmitting)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 flex-shrink-0 flex items-start gap-3">
        <i class="fas fa-rotate-right text-amber-500 flex-shrink-0 text-xs mt-1"></i>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-[#333333]">
                <strong>Resubmitting:</strong> Edit the details and click <strong>Save &amp; Resubmit</strong> to send back for Alumni Director approval.
            </p>
            @if($resubmitEventRemarks)
            <p class="text-xs mt-1 text-red-700 flex items-center gap-1.5">
                <i class="fas fa-circle-xmark text-red-400 text-[10px] flex-shrink-0"></i>
                <strong>Rejection reason:</strong> {{ $resubmitEventRemarks }}
            </p>
            @endif
        </div>
    </div>
    @endif

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT COLUMN --}}
        <div class="w-full lg:w-72 xl:w-76 flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white"
             style="scrollbar-width:thin;">
            <div class="p-3 space-y-3">

                {{-- Event Photo --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Event Photo
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-2.5">
                        <div x-data="{isDragging:false}"
                             @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                             class="border-2 rounded-xl text-center cursor-pointer transition-all"
                             :class="isDragging?'border-[#7a3f91] bg-[#f5eef9]':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-[#7a3f91] border-solid bg-[#f5eef9]/40':'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-gray-50' }}'">
                            <label class="cursor-pointer block p-2.5">
                                <input type="file" wire:model="photo" accept="image/*" class="hidden">
                                @if($photo)
                                    <div class="flex flex-col items-center gap-1">
                                        <img src="{{ $photo->temporaryUrl() }}" class="w-full h-20 object-contain rounded-lg shadow border border-purple-200">
                                        <p class="text-xs font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1 text-[10px]"></i>New photo selected</p>
                                    </div>
                                @elseif($existingPhotoUrl&&!$removePhoto)
                                    <div class="flex flex-col items-center gap-1">
                                        <img src="{{ $existingPhotoUrl }}" class="w-full h-20 object-contain rounded-lg shadow border border-gray-200">
                                        <p class="text-xs font-semibold text-[#555555]">Current photo — click to change</p>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-1 py-3">
                                        <i class="fas fa-cloud-arrow-up text-2xl text-gray-300"></i>
                                        <p class="font-semibold text-xs text-[#555555]">Click to upload or drag &amp; drop</p>
                                        <p class="text-[10px] text-[#777777]">JPG, PNG, WEBP — max 5 MB</p>
                                    </div>
                                @endif
                            </label>
                        </div>
                        @if($existingPhotoUrl&&!$removePhoto&&!$photo)
                            <button type="button" wire:click="$set('removePhoto',true)"
                                    class="mt-1.5 text-xs text-red-600 hover:text-red-700 font-semibold flex items-center gap-1 px-2 py-1 rounded-lg border border-red-200 hover:bg-red-50 transition">
                                <i class="fas fa-trash text-[10px]"></i> Remove photo
                            </button>
                        @endif
                        @if($removePhoto)
                            <div class="mt-1.5 flex items-center gap-2">
                                <span class="text-xs text-amber-700 font-semibold"><i class="fas fa-exclamation-circle mr-1 text-[10px]"></i>Photo removed on save</span>
                                <button type="button" wire:click="$set('removePhoto',false)" class="text-xs text-blue-600 underline">Undo</button>
                            </div>
                        @endif
                        <div wire:loading wire:target="photo" class="mt-1.5 text-xs text-[#7a3f91] flex items-center gap-2">
                            <i class="fas fa-spinner animate-spin text-xs"></i> Uploading…
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] {{ isset($formErrors['selected_courses']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Courses
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                        @if(count($selectedCourses) > 0)
                            <span class="ml-auto inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-200 text-purple-800 text-[10px] font-bold">
                                {{ count($selectedCourses) }}
                            </span>
                        @else
                            <span class="ml-auto inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-semibold">
                                None
                            </span>
                        @endif
                    </div>
                    <div class="p-2.5 space-y-2.5">

                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-lg px-2.5 py-1.5">
                            <i class="fas fa-building-columns text-purple-500 text-xs flex-shrink-0"></i>
                            <span class="text-xs font-semibold text-purple-800 truncate">{{ $this->organizerDepartment ?: 'Your College' }}</span>
                        </div>

                        @if(count($this->availableCourses) > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-[#555555]">Select courses</span>
                                <div class="flex gap-2">
                                    <button type="button"
                                            wire:click="$set('selectedCourses', {{ json_encode($this->availableCourses) }})"
                                            class="text-xs font-semibold hover:underline text-[#7a3f91]">
                                        <i class="fas fa-check-double mr-0.5 text-[10px]"></i>All
                                    </button>
                                    @if(count($selectedCourses) > 0)
                                        <button type="button" wire:click="$set('selectedCourses', [])"
                                                class="text-xs font-semibold hover:text-red-500 text-[#555555]">Clear</button>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-1 {{ isset($formErrors['selected_courses']) ? 'p-1.5 rounded-lg border border-red-200 bg-red-50/30' : '' }}">
                                @foreach($this->availableCourses as $course)
                                    <label class="flex items-center gap-1 px-2 py-1 border rounded-lg cursor-pointer transition text-xs font-semibold
                                                  {{ in_array($course, $selectedCourses)
                                                      ? 'border-purple-400 bg-purple-50 text-purple-700'
                                                      : 'border-gray-200 hover:border-purple-300 hover:bg-purple-50/40 bg-white text-[#333333]' }}">
                                        <input type="checkbox" wire:model.live="selectedCourses" value="{{ $course }}"
                                               class="accent-purple-600 w-3 h-3 flex-shrink-0">
                                        <span class="truncate text-[11px]">{{ $course }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @if(isset($formErrors['selected_courses']))
                                <p class="text-red-600 text-xs flex items-center gap-1 font-semibold">
                                    <i class="fas fa-circle-exclamation text-[10px]"></i>
                                    {{ $formErrors['selected_courses'] }}
                                </p>
                            @endif

                        @else
                            <div class="text-center py-2">
                                <i class="fas fa-inbox text-xl block mb-1 text-gray-200"></i>
                                <p class="text-xs text-[#555555]">No courses available yet.</p>
                            </div>
                        @endif

                        <div class="pt-2 border-t border-gray-100"
                             x-data="{
                                 val: '{{ $batchYear ?: now()->year }}',
                                 init() {
                                     if (!$wire.batchYear) {
                                         $wire.set('batchYear', String(new Date().getFullYear()));
                                         this.val = String(new Date().getFullYear());
                                     }
                                 },
                                 validate(v) {
                                     if (!v) return true;
                                     return /^\d{4}$/.test(v) && parseInt(v) >= 1995 && parseInt(v) <= 3030;
                                 },
                                 onInput(e) {
                                     let raw = e.target.value.replace(/\D/g,'').substring(0,4);
                                     this.val = raw;
                                     $wire.set('batchYear', raw);
                                 },
                                 onBlur(e) {
                                     let n = parseInt(this.val);
                                     if (this.val.length === 4 && !isNaN(n)) {
                                         if (n < 1995) { this.val = '1995'; $wire.set('batchYear','1995'); }
                                         if (n > 3030) { this.val = '3030'; $wire.set('batchYear','3030'); }
                                     }
                                 }
                             }">
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Batch Year <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="4"
                                    placeholder="e.g. {{ now()->year }}"
                                    x-model="val"
                                    @input="onInput($event)"
                                    @blur="onBlur($event)"
                                    class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['batch_year']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                    :class="val && !validate(val) && val.length === 4 ? 'border-red-400 bg-red-50' : ''">
                                <div class="absolute right-1 top-1/2 -translate-y-1/2 flex flex-col gap-0">
                                    <button type="button"
                                            @click="let n=parseInt(val)||{{ now()->year }}; if(n<3030){val=String(n+1);$wire.set('batchYear',val);}"
                                            class="w-5 h-4 flex items-center justify-center text-[#555] hover:text-[#7a3f91] transition">
                                        <i class="fas fa-chevron-up text-[8px]"></i>
                                    </button>
                                    <button type="button"
                                            @click="let n=parseInt(val)||{{ now()->year }}; if(n>1995){val=String(n-1);$wire.set('batchYear',val);}"
                                            class="w-5 h-4 flex items-center justify-center text-[#555] hover:text-[#7a3f91] transition">
                                        <i class="fas fa-chevron-down text-[8px]"></i>
                                    </button>
                                </div>
                            </div>
                            @if(isset($formErrors['batch_year']))
                                <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['batch_year'] }}</p>
                            @else
                                <p class="text-[10px] mt-1 text-[#777777]">Leave blank to target all batches. Range: 1995–3030.</p>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE COLUMN --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto flex flex-col p-3 gap-3" style="scrollbar-width:thin;">

                <div class="flex flex-col bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden" style="min-height: 0; flex: 1;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Event Details
                    </div>
                    <div class="flex flex-col flex-1 min-h-0 p-2.5 gap-3">

                        <div class="flex-shrink-0">
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Event Title <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="title" type="text"
                                   placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['title']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['title']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['title'] }}</p>@endif
                        </div>

                        <div class="flex flex-col" style="flex: 1; min-height: 80px;">
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1 flex-shrink-0">
                                Description <span class="text-red-500">*</span>
                            </label>
                            <textarea wire:model.defer="description"
                                      placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                      class="flex-1 w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto {{ isset($formErrors['description']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                      style="min-height: 80px;"></textarea>
                            @if(isset($formErrors['description']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1 flex-shrink-0"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['description'] }}</p>@endif
                        </div>

                        <div class="flex-shrink-0 grid grid-cols-1 sm:grid-cols-3 gap-2.5">

                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Date <span class="text-red-500">*</span>
                                    @if($isRestoring)<span class="font-normal normal-case tracking-normal text-amber-600 ml-1">— must be future</span>@endif
                                </label>
                                <input wire:model="event_date" type="date"
                                       min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['event_date']) ? 'border-red-400 bg-red-50' : ($isRestoring ? 'border-amber-400' : 'border-gray-300') }}">
                                @if(isset($formErrors['event_date']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['event_date'] }}</p>@endif
                            </div>

                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Start Time <span class="text-red-500">*</span>
                                </label>
                                <div wire:ignore
                                     x-data="{
                                         h: '8', m: '00', p: 'AM',
                                         init() {
                                             const raw = $wire.start_time;
                                             if (raw && raw.includes(':')) {
                                                 const parts = raw.split(':');
                                                 let hi = parseInt(parts[0], 10);
                                                 this.p = hi >= 12 ? 'PM' : 'AM';
                                                 hi = hi % 12 || 12;
                                                 this.h = String(hi);
                                                 this.m = parts[1] ? parts[1].substring(0, 2) : '00';
                                             }
                                             this.sync();
                                         },
                                         sync() {
                                             let hi = parseInt(this.h, 10);
                                             if (this.p === 'PM' && hi !== 12) hi += 12;
                                             if (this.p === 'AM' && hi === 12) hi = 0;
                                             $wire.set('start_time', String(hi).padStart(2, '0') + ':' + this.m);
                                         }
                                     }"
                                     @reset-time-selects.window="h='8';m='00';p='AM';sync()"
                                     class="time-select-wrap flex items-stretch rounded-xl overflow-hidden border transition-shadow focus-within:ring-2 focus-within:ring-[#7a3f91]/20 {{ isset($formErrors['start_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-xs"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="border-r border-gray-200 text-[#333333]" title="Hour">
                                        @foreach(['12','1','2','3','4','5','6','7','8','9','10','11'] as $hr)
                                            <option value="{{ $hr }}">{{ $hr }}</option>
                                        @endforeach
                                    </select>
                                    <span class="flex items-center px-1 bg-white border-x border-gray-200 text-[#555] font-semibold text-sm select-none">:</span>
                                    <select x-model="m" @change="sync()" class="text-[#333333]" title="Minute">
                                        @foreach(['00','05','10','15','20','25','30','35','40','45','50','59'] as $mn)
                                            <option value="{{ $mn }}">{{ $mn }}</option>
                                        @endforeach
                                    </select>
                                    <select x-model="p" @change="sync()" class="border-l border-gray-200 bg-[#faf7fc] text-[#7a3f91] font-semibold min-w-[3rem] text-center" title="AM/PM">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                                @if(isset($formErrors['start_time']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['start_time'] }}</p>@endif
                            </div>

                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    End Time <span class="text-red-500">*</span>
                                </label>
                                <div wire:ignore
                                     x-data="{
                                         h: '5', m: '00', p: 'PM',
                                         init() {
                                             const raw = $wire.end_time;
                                             if (raw && raw.includes(':')) {
                                                 const parts = raw.split(':');
                                                 let hi = parseInt(parts[0], 10);
                                                 this.p = hi >= 12 ? 'PM' : 'AM';
                                                 hi = hi % 12 || 12;
                                                 this.h = String(hi);
                                                 this.m = parts[1] ? parts[1].substring(0, 2) : '00';
                                             }
                                             this.sync();
                                         },
                                         sync() {
                                             let hi = parseInt(this.h, 10);
                                             if (this.p === 'PM' && hi !== 12) hi += 12;
                                             if (this.p === 'AM' && hi === 12) hi = 0;
                                             $wire.set('end_time', String(hi).padStart(2, '0') + ':' + this.m);
                                         }
                                     }"
                                     @reset-time-selects.window="h='5';m='00';p='PM';sync()"
                                     class="time-select-wrap flex items-stretch rounded-xl overflow-hidden border transition-shadow focus-within:ring-2 focus-within:ring-[#7a3f91]/20 {{ isset($formErrors['end_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-xs"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="border-r border-gray-200 text-[#333333]" title="Hour">
                                        @foreach(['12','1','2','3','4','5','6','7','8','9','10','11'] as $hr)
                                            <option value="{{ $hr }}">{{ $hr }}</option>
                                        @endforeach
                                    </select>
                                    <span class="flex items-center px-1 bg-white border-x border-gray-200 text-[#555] font-semibold text-sm select-none">:</span>
                                    <select x-model="m" @change="sync()" class="text-[#333333]" title="Minute">
                                        @foreach(['00','05','10','15','20','25','30','35','40','45','50','59'] as $mn)
                                            <option value="{{ $mn }}">{{ $mn }}</option>
                                        @endforeach
                                    </select>
                                    <select x-model="p" @change="sync()" class="border-l border-gray-200 bg-[#faf7fc] text-[#7a3f91] font-semibold min-w-[3rem] text-center" title="AM/PM">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                                @if(isset($formErrors['end_time']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['end_time'] }}</p>@endif
                            </div>

                        </div>

                        <div class="flex-shrink-0 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Venue / Location <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="venue" type="text"
                                       placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['venue']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['venue']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['venue'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Full Address <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="venue_address" type="text"
                                       placeholder="e.g. Old Nalsian Road, Calasiao, Pangasinan" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['venue_address']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['venue_address']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['venue_address'] }}</p>@endif
                            </div>
                        </div>

                    </div>
                </div>

                <div class="flex-shrink-0 bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Notes / Requirements
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-2.5">
                        <textarea wire:model.defer="notes"
                                  placeholder="Dress code, special instructions, what to bring, parking info…" maxlength="3000"
                                  class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto"
                                  style="height: 200px;"></textarea>
                        <p class="text-[10px] mt-1.5 flex items-center gap-1 text-[#777777]">
                            <i class="fas fa-circle-info text-[9px]"></i>
                            Visible to alumni on the event page.
                        </p>
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT COLUMN --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto" style="scrollbar-width:thin;">
            <div class="p-3 space-y-3 flex-1">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Contact Person
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— pre-filled</span>
                    </div>
                    <div class="p-2.5 space-y-2.5">
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Name</label>
                            <input wire:model.defer="contact_person" type="text" placeholder="Full name"
                                   class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Email</label>
                            <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['contact_email']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['contact_email']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['contact_email'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Phone <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                            </label>
                            <input wire:model.defer="contact_phone" type="text"
                                   placeholder="09XXXXXXXXX" maxlength="16"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['contact_phone']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['contact_phone']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['contact_phone'] }}</p>@endif
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Submission Tips
                    </div>
                    <div class="p-2.5">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Set a future date — past dates are auto-rejected.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Choose correct courses so the right alumni are notified.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Upload a photo — events with photos get more RSVPs.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Review typically takes 1–2 business days.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="saveEvent"
                        wire:loading.attr="disabled" wire:target="saveEvent"
                        class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer
                               {{ $isRestoring ? 'bg-amber-500 hover:bg-amber-600' : ($isResubmitting ? 'bg-amber-600 hover:bg-amber-700' : 'bg-[#7a3f91] hover:bg-[#5e2f72]') }}">
                    <span wire:loading wire:target="saveEvent">
                        <i class="fas fa-spinner animate-spin text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">
                        @if($isRestoring)
                            <i class="fas fa-rotate-left text-xs"></i>
                        @elseif($isResubmitting)
                            <i class="fas fa-rotate-right text-xs"></i>
                        @elseif($isEditing)
                            <i class="fas fa-floppy-disk text-xs"></i>
                        @else
                            <i class="fas fa-paper-plane text-xs"></i>
                        @endif
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">
                        @if($isRestoring) Save &amp; Restore
                        @elseif($isResubmitting) Save &amp; Resubmit
                        @elseif($isEditing) Save Changes
                        @else Submit Event
                        @endif
                    </span>
                </button>
                <button type="button" wire:click="closeFormModal"
                        class="w-full px-5 py-2 rounded-xl text-xs font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333]">
                    <i class="fas fa-xmark mr-1 text-[10px]"></i>Cancel
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ VIEW EVENT — FULL SCREEN ══ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev          = $this->viewingEvent;
    $totalRsvp   = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
    $isCompleted = $ev->status === 'COMPLETED';
    $isApproved  = $ev->status === 'APPROVED';
    $eventDatePH = $ev->event_date->setTimezone('Asia/Manila');
    $eventEndPH  = $ev->event_end_date?->setTimezone('Asia/Manila');
    $timeDisplay = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
    $createdPH   = \Carbon\Carbon::parse($ev->created_at)->setTimezone('Asia/Manila');
    $hasPhoto    = !empty($ev->photo_url);
    $isDeleted   = $ev->status === 'ORGANIZER_DELETED';
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div>
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Event Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $ev->title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($isApproved || $isCompleted)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareModal({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24"
                            aria-label="Share event">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            {{-- If deleted and past date: show Edit Date button to open edit form --}}
            @if($isDeleted)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openEditModal({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-amber-400/30 border border-amber-300/40 hover:bg-amber-400/50"
                            aria-label="Edit to restore">
                        <i class="fas fa-pen-to-square text-white text-sm"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Edit &amp; Restore
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c">

            @if($hasPhoto)
            <div class="w-full px-5 pt-5 pb-3 flex-shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         class="w-full object-contain block" style="max-height: 200px;">
                    <div class="absolute top-3 right-3">
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Completed</span>
                        @elseif($isApproved)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Approved</span>
                        @elseif($isDeleted)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Deleted</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-5 mt-5 mb-3 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center h-20"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="absolute top-2 right-2">
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-700/90 text-white text-xs font-bold">Completed</span>
                    @elseif($isApproved)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-600/90 text-white text-xs font-bold">Approved</span>
                    @elseif($isDeleted)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-600/90 text-white text-xs font-bold">Deleted</span>
                    @endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-3 px-5 pb-5">

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Date &amp; Time</p>
                    <p class="text-lg font-bold text-[#333333]">{{ $eventDatePH->format('F d, Y') }}</p>
                    <p class="text-base font-semibold mt-0.5 text-[#333333]">{{ $timeDisplay }}</p>
                </div>

                @if($ev->venue)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Venue</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->venue }}</p>
                    @if($ev->venue_address)
                        <p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->venue_address }}</p>
                    @endif
                </div>
                @endif

                @if($ev->target_participants)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Open For</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->target_participants }}</p>
                </div>
                @endif

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-2 text-[#333333]">Contact</p>
                    <div class="flex flex-col gap-1.5">
                        @if($ev->contact_person)
                        <p class="text-base font-bold text-[#333333]">{{ $ev->contact_person }}</p>
                        @endif
                        @if($ev->contact_email)
                        <p class="text-sm font-medium text-[#333333]">{{ $ev->contact_email }}</p>
                        @endif
                        @if($ev->contact_phone)
                        <p class="text-sm font-medium text-[#333333]">{{ $ev->contact_phone }}</p>
                        @endif
                    </div>
                </div>
                @endif

                @if($isDeleted)
                <div class="p-4 rounded-xl border bg-red-50 border-red-200">
                    <p class="text-base font-bold text-[#333333]">Deleted</p>
                    <p class="text-sm font-medium mt-0.5 text-[#555555]">
                        @if($eventDatePH->isFuture())
                            This event was deleted but the date is still in the future. You can restore it.
                        @else
                            This event was deleted and its date has passed. Update the date to restore it.
                        @endif
                    </p>
                </div>
                @elseif($isCompleted)
                <div class="p-4 rounded-xl border bg-green-50 border-green-200">
                    <p class="text-base font-bold text-[#333333]">Completed</p>
                    <p class="text-sm font-medium mt-0.5 text-[#333333]">This event has already taken place.</p>
                </div>
                @elseif($isApproved)
                <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200">
                    <p class="text-base font-bold text-[#333333]">Approved — Now Live</p>
                    @if($ev->reviewed_at)
                    <p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>
                    @endif
                </div>
                @endif

                <p class="text-sm text-center font-medium text-[#333333]">
                    Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="flex-shrink-0 px-6 py-4 bg-white border-b border-gray-200">
                <p class="text-xs font-bold uppercase tracking-widest mb-2 text-[#333333]">Responses</p>
                @if($totalRsvp === 0)
                    <p class="text-base font-medium text-[#333333]">No responses yet.</p>
                @else
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex flex-col items-center px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-emerald-700">{{ $ev->confirmed_count }}</span>
                            <span class="text-xs font-semibold text-emerald-600 uppercase tracking-wide">Confirmed</span>
                        </div>
                        <div class="flex flex-col items-center px-4 py-2 bg-amber-50 border border-amber-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-amber-700">{{ $ev->tentative_count }}</span>
                            <span class="text-xs font-semibold text-amber-600 uppercase tracking-wide">Maybe</span>
                        </div>
                        <div class="flex flex-col items-center px-4 py-2 bg-red-50 border border-red-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-red-700">{{ $ev->declined_count }}</span>
                            <span class="text-xs font-semibold text-red-600 uppercase tracking-wide">Declined</span>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-6 py-5 flex flex-col gap-5">

                @if($ev->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">About This Event</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-base leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->description) }}</p>
                    </div>
                </div>
                @endif

                @if($ev->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-amber-50">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Additional Notes</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-base leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->notes) }}</p>
                    </div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
                <div class="flex-1 flex items-center justify-center py-10">
                    <p class="text-base font-medium text-[#333333]">No additional details provided.</p>
                </div>
                @endif

            </div>
        </div>

    </div>

</div>
@endif


{{-- ══ SHARE MODAL ══ --}}
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
        $fbLines[] = "📢 @everyone — Event Highlights!";
        $fbLines[] = "🏆 {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    } else {
        $fbLines[] = "📢 @everyone — Event Alert!";
        $fbLines[] = "📅 {$shareEventTitle}";
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
             const encodedUrl = encodeURIComponent(this.baseUrl);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodedUrl + '&app_id=';
                 setTimeout(() => {
                     window.open(
                         'https://www.facebook.com/dialog/send?link=' + encodedUrl
                         + '&app_id=291494419107518&redirect_uri=' + encodedUrl,
                         '_blank', 'noopener'
                     );
                 }, 1500);
             } else {
                 window.open(
                     'https://www.facebook.com/dialog/send?link=' + encodedUrl
                     + '&app_id=291494419107518&redirect_uri=' + encodedUrl,
                     '_blank', 'width=626,height=550,noopener,noreferrer'
                 );
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
            <h2 class="text-base font-semibold flex items-center gap-2.5 text-[#333333]">
                <i class="fas fa-share-nodes text-blue-500 text-sm"></i>
                <span>Share Event</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#333333]">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3 pb-0">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4 {{ $isCompleted ? 'bg-amber-50' : 'bg-[#f0f7ff]' }}">
                        <p class="font-semibold text-base leading-tight text-[#333333]">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold text-[#333333]">
                            {{ $shareEventDate }}@if($shTimeDisplay) · {{ $shTimeDisplay }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-[#333333]">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareEventTarget, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-sm leading-relaxed text-[#333333]">{{ $shDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f0f7ff]">
                        <i class="fas fa-globe text-xs text-blue-400"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold text-blue-600">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> copies the caption to clipboard then opens the share dialog.
                            Clicking <strong>Messenger</strong> opens the <strong>conversation picker</strong> so you can
                            forward the event directly to friends or groups.
                        </p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-blue-600 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold flex items-center gap-2 text-blue-800">
                            Post to Batch Chats
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-semibold tracking-wide">
                                <i class="fas fa-at text-[9px]"></i>everyone tagged
                            </span>
                        </p>
                        <p class="text-sm mt-0.5 text-blue-700">
                            Sends the event caption with <strong>@everyone</strong> directly to all target batch chat rooms for
                            <strong>{{ $shareEventTarget ?: ($this->organizerDepartment ?: 'your college') }}</strong>.
                        </p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Share via</p>

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
                        <p class="text-sm font-semibold text-blue-800">Conversation picker opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Choose who to send it to, then press Ctrl+V to paste the caption.</p>
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
                        style="background: linear-gradient(135deg, #0084FF 0%, #0050D0 100%);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs>
                                <linearGradient id="msg_eo_grad" x1="0%" y1="100%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color:#0099FF"/>
                                    <stop offset="100%" style="stop-color:#A033FF"/>
                                </linearGradient>
                            </defs>
                            <path fill="url(#msg_eo_grad)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens conversation picker · forward to friends</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or post directly</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-blue-200 hover:border-blue-400 hover:bg-blue-50 disabled:opacity-60 disabled:cursor-not-allowed bg-blue-50 text-blue-700">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-blue-600">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            Post to Batch Chats
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="flex items-center gap-1.5 text-xs mt-0.5 text-blue-600">
                            Sends to all target batch rooms
                            · <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-blue-600 text-white text-[9px] font-semibold">
                                <i class="fas fa-at text-[8px]"></i>everyone
                            </span>
                        </span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-blue-500"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-blue-300 hover:bg-blue-50 font-semibold text-sm transition cursor-pointer group bg-white text-[#333333]">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-blue-500'" class="text-base"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : 'text-blue-600'"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate text-[#555555]">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#333333]">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>

<script>
(function () {
    var tip = document.getElementById('eo-hover-tip');

    function bindRows() {
        document.querySelectorAll('[data-eo-row]').forEach(function (row) {
            if (row._eoTipBound) return;
            row._eoTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var shareWrap = e.target.closest('[data-eo-share]');
                if (shareWrap) {
                    tip.style.opacity = '0';
                    return;
                }
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.style.opacity = '1';
            });

            row.addEventListener('mouseleave', function () {
                if (tip) tip.style.opacity = '0';
            });

            row.addEventListener('click', function () {
                if (tip) tip.style.opacity = '0';
            });
        });

        document.querySelectorAll('[data-eo-share]').forEach(function (sw) {
            if (sw._eoShareBound) return;
            sw._eoShareBound = true;
            sw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);
})();
</script>

</div>