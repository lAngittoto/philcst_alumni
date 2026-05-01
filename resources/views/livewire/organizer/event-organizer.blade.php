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
    public string $filterSort   = 'recent';

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

    public bool   $showDeleteModal  = false;
    public ?int   $deleteEventId    = null;
    public string $deleteEventTitle = '';

    public bool $showNoAlumniModal = false;

    // ── Resubmit Confirmation Modal ───────────────────────────────────────────
    public bool   $showResubmitConfirmModal = false;
    public ?int   $pendingEditId            = null;
    public bool   $isResubmitting           = false;
    public string $resubmitEventTitle       = '';
    public string $resubmitEventRemarks     = '';
    // ─────────────────────────────────────────────────────────────────────────

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

        $user = Auth::user();
        if (!$user || !$user->organizer) {
            abort(403, 'Access denied.');
        }

        $orgId = $user->organizer->id;

        $throttleKey = "auto_event_ops_{$orgId}";
        if (!Cache::has($throttleKey)) {
            Cache::put($throttleKey, true, now()->addMinutes(5));
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
        }
    }

    private function autoRejectExpiredPendingEvents(): void
    {
        $orgId = Auth::user()?->organizer?->id;
        if (!$orgId) return;

        $now = \Carbon\Carbon::now('UTC');

        OrganizerEvent::where('organizer_id', $orgId)
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->update([
                'status'         => 'REJECTED',
                'review_remarks' => 'Auto-rejected: event date has already passed without admin approval.',
            ]);
    }

    private function autoCompleteExpiredEvents(): void
    {
        $orgId = Auth::user()?->organizer?->id;
        if (!$orgId) return;

        $now = \Carbon\Carbon::now('UTC');

        OrganizerEvent::where('organizer_id', $orgId)
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
            })
            ->update(['status' => 'COMPLETED']);
    }

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterSort(): void   { $this->resetPage(); }

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
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED']);

        if ($this->search !== '') {
            $s = trim($this->search);
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '' && in_array($this->filterStatus, ['PENDING','APPROVED','REJECTED','COMPLETED'], true)) {
            $q->where('status', $this->filterStatus);
        }

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
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
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        if (!$this->hasAlumni) {
            $this->showNoAlumniModal = true;
            return;
        }
        $this->resetFormFields();
        $this->start_time     = '00:00';
        $this->end_time       = '00:00';
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
            $this->pendingEditId          = $id;
            $this->resubmitEventTitle     = $event->title;
            $this->resubmitEventRemarks   = $event->review_remarks ?? '';
            $this->showResubmitConfirmModal = true;
            return;
        }

        $this->isResubmitting = false;
        $this->populateEditForm($event);
        $this->showFormModal = true;
        $this->showViewModal = false;
    }

    public function confirmResubmit(): void
    {
        $this->showResubmitConfirmModal = false;

        if (!$this->pendingEditId) return;

        $event = OrganizerEvent::where('id', $this->pendingEditId)
            ->where('organizer_id', $this->organizerId)->firstOrFail();

        $this->isResubmitting = true;
        $this->populateEditForm($event);
        $this->pendingEditId = null;
        $this->showFormModal = true;
        $this->showViewModal = false;
    }

    public function cancelResubmit(): void
    {
        $this->showResubmitConfirmModal = false;
        $this->pendingEditId            = null;
        $this->resubmitEventTitle       = '';
        $this->resubmitEventRemarks     = '';
        $this->isResubmitting           = false;
    }

    public function deleteFromResubmitModal(): void
    {
        if ($this->pendingEditId) {
            $this->showResubmitConfirmModal = false;
            $event = OrganizerEvent::where('id', $this->pendingEditId)
                ->where('organizer_id', $this->organizerId)->firstOrFail();
            $this->deleteEventId    = $this->pendingEditId;
            $this->deleteEventTitle = $event->title;
            $this->pendingEditId    = null;
            $this->resubmitEventTitle   = '';
            $this->resubmitEventRemarks = '';
            $this->isResubmitting       = false;
            $this->showDeleteModal = true;
        }
    }

    public function resetForm(): void
    {
        $savedId         = $this->editingEventId;
        $savedIsEditing  = $this->isEditing;
        $savedIsResubmit = $this->isResubmitting;

        $this->resetFormFields();

        $this->start_time = '00:00';
        $this->end_time   = '00:00';

        $this->editingEventId = $savedId;
        $this->isEditing      = $savedIsEditing;
        $this->isResubmitting = $savedIsResubmit;

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

        if (!isset($errors['event_date']) && !isset($errors['start_time'])
            && trim($this->event_date) && trim($this->start_time)) {
            try {
                $proposedStart = \Carbon\Carbon::createFromFormat(
                    'Y-m-d H:i',
                    trim($this->event_date) . ' ' . trim($this->start_time),
                    'Asia/Manila'
                );
                if ($proposedStart->isPast()) {
                    $errors['event_date'] = 'Event date and start time cannot be in the past. Please choose a future date and time.';
                }
            } catch (\Exception $e) {}
        }

        // ── REQUIRED: At least one course must be selected ────────────────────
        $availableCourses = $this->availableCourses;
        if (!empty($availableCourses) && empty($this->selectedCourses)) {
            $errors['selected_courses'] = 'Please select at least one course or program, or click "Select All".';
        }
        // ─────────────────────────────────────────────────────────────────────

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

        if ($endDt->lte($startDt)) {
            $endDt->addDay();
        }

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

        $ctrl  = app(OrganizerEventController::class);
        $photo = $this->photo;

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
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }

            $action      = $this->isResubmitting ? 'resubmitted' : 'updated';
            $description = $this->isResubmitting
                ? "Organizer resubmitted rejected event: '" . trim($this->title) . "' for admin review."
                : "Organizer updated event: '" . trim($this->title) . "'.";

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

            $msg = $this->isResubmitting
                ? 'Event resubmitted for admin review!'
                : 'Event updated successfully!';

            $this->dispatch('flash-message', type: 'success', message: $msg);
        } else {
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
                    'description'   => "Organizer submitted new event: '" . trim($this->title) . "' for admin review.",
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

            $this->dispatch('flash-message', type: 'success', message: 'Event submitted for admin review!');
        }

        Cache::forget('organizer_has_alumni_' . ($this->organizerDepartment ?: 'all'));
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function viewEvent(int $id): void
    {
        if (!OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->exists()) abort(403);
        $this->viewingEventId = $id;
        $this->showViewModal  = true;
    }

    public function closeViewModal(): void { $this->showViewModal = false; $this->viewingEventId = null; }

    public function confirmDelete(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->firstOrFail();
        $this->deleteEventId    = $id;
        $this->deleteEventTitle = $event->title;
        $this->showDeleteModal  = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteEventId) {
            $event = OrganizerEvent::where('id', $this->deleteEventId)
                ->where('organizer_id', $this->organizerId)->firstOrFail();

            try {
                AuditLog::create([
                    'user_id'       => Auth::id(),
                    'user_name'     => Auth::user()?->name ?? 'Organizer',
                    'user_email'    => Auth::user()?->email,
                    'user_role'     => 'organizer',
                    'action'        => 'deleted',
                    'module'        => 'event',
                    'subject_id'    => $event->id,
                    'subject_label' => $event->title,
                    'description'   => "Organizer deleted event: '{$event->title}'.",
                    'old_values'    => [
                        'title'               => $event->title,
                        'status'              => $event->status,
                        'event_date'          => $event->event_date?->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                        'venue'               => $event->venue,
                        'target_participants' => $event->target_participants,
                    ],
                    'ip_address'    => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                    'severity'      => 'warning',
                ]);
            } catch (\Throwable) {}

            app(OrganizerEventController::class)->deleteEvent($this->deleteEventId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteEventTitle}' deleted.");
        }
        $this->showDeleteModal  = false;
        $this->deleteEventId    = null;
        $this->deleteEventTitle = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal  = false;
        $this->deleteEventId    = null;
        $this->deleteEventTitle = '';
    }

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
                "🏆 Event Highlights",
                "━━━━━━━━━━━━━━━━━━━━━━━━",
                "✅ {$event->title}",
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

            if (!$isCompleted) {
                DB::table('chat_mentions')->insert([
                    'message_id'   => $msgId,
                    'mention_type' => 'everyone',
                    'mentioned_id' => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $roomCount = $rooms->count();
        $label = $isCompleted
            ? "Event highlights posted to {$roomCount} batch chat(s)! 🏆"
            : "Event posted to {$roomCount} batch chat(s)! 🎉";

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
        $this->pendingEditId  = null;
    }
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #666666;
    --text-muted:     #999999;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }
.filter-input {
    border: 1.5px solid #e8e0f0;
    transition: border-color .15s, box-shadow .15s;
    color: var(--text-primary);
}
.filter-input:hover  { border-color: var(--brand); }
.filter-input:focus  { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #f4f0f8 !important; cursor: default; }
.time-select-wrap {
    display: flex;
    align-items: stretch;
    border-radius: 0.75rem;
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
}
.time-select-wrap:focus-within {
    box-shadow: 0 0 0 3px rgba(122,63,145,.12);
}
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
    padding: 0.75rem 0.5rem;
    flex: 1;
    min-width: 0;
}
.time-select-wrap select:focus { background: #faf7fc; }
.time-select-wrap .ts-sep {
    display: flex;
    align-items: center;
    padding: 0 2px;
    background: #fff;
    color: #999;
    font-weight: 700;
    font-size: 0.875rem;
    border-left: 1px solid #e5e7eb;
    border-right: 1px solid #e5e7eb;
    user-select: none;
}
.time-select-wrap .ts-period {
    font-weight: 700;
    color: #7a3f91;
    border-left: 1px solid #e5e7eb;
    background: #faf7fc;
    min-width: 3.5rem;
}
.time-select-wrap .ts-hour { border-right: 1px solid #e5e7eb; }
</style>

{{-- FLASH TOAST --}}
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

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-5 px-4 sm:px-6 lg:px-8 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight" style="color:#333333;">Event Management</h1>
                <p class="text-sm leading-relaxed mt-0.5" style="color:#666666;">
                    Manage and submit events for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $this->organizerDepartment ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-4 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-widest">
                <i class="fas fa-calendar-days text-purple-600"></i>
                {{ $this->events->total() }} {{ $this->events->total() !== 1 ? 'Events' : 'Event' }}
            </span>
            <button wire:click="openCreateModal"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-sm text-white shadow-md transition cursor-pointer {{ !$this->hasAlumni ? 'opacity-60 cursor-not-allowed' : '' }}"
                    style="background-color:#7a3f91;"
                    onmouseover="this.style.backgroundColor='#5e2f72'"
                    onmouseout="this.style.backgroundColor='#7a3f91'">
                <i class="fas fa-plus text-sm"></i> Submit Event
            </button>
        </div>
    </div>

    {{-- No alumni notice --}}
    @if(!$this->hasAlumni)
    <div class="flex-shrink-0 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0"></i>
        <div>
            <p class="font-semibold">No verified alumni found for {{ $this->organizerDepartment ?: 'your college' }}.</p>
            <p class="mt-0.5 text-amber-700">You cannot post events until at least one verified alumni is registered under your college.</p>
        </div>
    </div>
    @endif

    {{-- ══ FILTER BAR ═══════════════════════════════════════════════════════ --}}
    <div class="flex flex-wrap gap-2 items-center flex-shrink-0 px-4 py-3 rounded-2xl border border-[#E8E0F0] shadow-sm"
         style="background-color:#ffffff;">
        <div class="relative flex-1 min-w-[180px] max-w-xs"
             wire:ignore
             x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#999999;"></i>
            <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                   placeholder="Search title or venue…"
                   class="filter-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm"
                   style="background-color:#ffffff;"
                   autocomplete="off" maxlength="100">
        </div>
        <select wire:model.live="filterStatus"
                class="filter-input px-3 py-2.5 rounded-xl text-sm"
                style="background-color:#ffffff;">
            <option value="">All Statuses</option>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Approved</option>
            <option value="REJECTED">Rejected</option>
            <option value="COMPLETED">Completed</option>
        </select>
        <select wire:model.live="filterSort"
                class="filter-input px-3 py-2.5 rounded-xl text-sm hidden sm:block"
                style="background-color:#ffffff;">
            <option value="recent">Newest First</option>
            <option value="oldest">Oldest First</option>
        </select>
        <button wire:click="resetFilters"
                class="filter-input px-3 py-2.5 rounded-xl text-sm font-semibold flex items-center gap-1.5 transition uppercase tracking-widest cursor-pointer"
                style="background-color:#ffffff; color:#666666;">
            <i class="fas fa-rotate-left text-xs"></i>
            <span class="hidden sm:inline">Reset</span>
        </button>
    </div>

    {{-- Mobile sort --}}
    <div class="flex gap-2 sm:hidden -mt-3 flex-shrink-0">
        <select wire:model.live="filterSort"
                class="filter-input flex-1 px-3 py-2.5 rounded-xl text-sm"
                style="background-color:#ffffff;">
            <option value="recent">Newest First</option>
            <option value="oldest">Oldest First</option>
        </select>
    </div>

    {{-- ══ TABLE SECTION ════════════════════════════════════════════════════ --}}
    <div class="flex-1 min-h-0 flex flex-col"
         wire:loading.class="opacity-50 pointer-events-none"
         wire:target="search,filterStatus,filterSort,resetFilters,previousPage,nextPage,executeDelete">

        @if($this->events->count() > 0)

        <div class="flex-1 min-h-0 rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col"
             style="background-color:#ffffff;">
            <div class="overflow-x-auto overflow-y-auto flex-1 scroll-c">
                <table class="w-full min-w-[700px]" style="background-color:#ffffff;">
                    <thead class="sticky top-0 z-10" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr style="background-color:#ffffff;">
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] w-10">#</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333]">Event Title</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden md:table-cell">Date &amp; Time</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden lg:table-cell">Venue</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden xl:table-cell">Course</th>
                            <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#333333]">Status</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-widest text-[#333333]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $canEdit     = !$isCompleted && !$isApproved;

                            $tp          = $event->target_participants ?? '';
                            $tpParts     = explode(' · Batch ', $tp, 2);
                            $displayCourses = trim($tpParts[0]) ?: ($this->organizerDepartment ?: 'All Courses');
                            $batchDisplay   = !empty($tpParts[1]) ? trim($tpParts[1]) : null;

                            $eventDate  = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum     = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors">

                            {{-- # --}}
                            <td class="px-4 py-4 text-sm font-semibold text-[#c0a0d8] text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            {{-- Event Title --}}
                            <td class="px-4 py-4">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $eventDate->diffForHumans() }}</p>
                                </div>
                            </td>

                            {{-- Date & Time --}}
                            <td class="px-4 py-4 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5" style="color:#666666;">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            {{-- Venue --}}
                            <td class="px-4 py-4 hidden lg:table-cell">
                                <p class="text-sm max-w-[160px] truncate" style="color:#666666;">{{ $event->venue ?: '—' }}</p>
                            </td>

                            {{-- Course --}}
                            <td class="px-4 py-4 hidden xl:table-cell">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 w-fit max-w-[160px] truncate block">
                                        {{ Str::limit($displayCourses, 20) }}
                                    </span>
                                    @if($batchDisplay)
                                        <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-gray-100 border border-gray-200 w-fit" style="color:#666666;">
                                            Batch {{ $batchDisplay }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-4 text-center">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-yellow-200 bg-yellow-50 text-yellow-700 whitespace-nowrap">
                                        Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        Rejected
                                    </span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-4">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">

                                    {{-- View --}}
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share / Highlights --}}
                                    @if($isApproved)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @elseif($isCompleted)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trophy text-xs"></i>
                                            <span class="hidden xl:inline">Highlights</span>
                                        </button>
                                    @endif

                                    {{-- Edit / Delete --}}
                                    @if($canEdit)
                                        <button type="button" wire:click.stop="openEditModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>
                                        <button type="button" wire:click.stop="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-red-100 text-red-700 border border-red-300 hover:bg-white hover:border-red-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trash text-xs"></i>
                                            <span>Delete</span>
                                        </button>
                                    @endif

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @else
        <div class="flex-1 rounded-2xl border border-gray-200 shadow-sm flex flex-col items-center justify-center gap-4 text-center px-6 py-20"
             style="background-color:#ffffff;">
            <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                <i class="fas fa-calendar-days text-2xl" style="color:#999999;"></i>
            </div>
            <div>
                <p class="font-semibold text-lg" style="color:#333333;">
                    @if($search || $filterStatus)
                        No events match your filters
                    @else
                        No events yet
                    @endif
                </p>
                <p class="text-sm mt-1" style="color:#999999;">
                    @if($search || $filterStatus)
                        Try clearing your filters to see all events.
                    @else
                        Click <strong>Submit Event</strong> to submit your first event for admin review.
                    @endif
                </p>
            </div>
            @if($search || $filterStatus)
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                        style="background-color:#7a3f91;">
                    <i class="fas fa-rotate-left mr-1.5"></i> Clear Filters
                </button>
            @endif
        </div>
        @endif

    </div>

    {{-- ══ PAGINATION ═══════════════════════════════════════════════════════ --}}
    @php
        $total = $this->events->total();
        $pp    = $this->events->perPage();
        $cp    = $this->events->currentPage();
        $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
        $to    = min($cp * $pp, $total);
    @endphp
    <div class="flex-shrink-0 rounded-2xl px-4 sm:px-5 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7a3f91;">
        <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
            Showing <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
            of <span class="font-semibold text-white">{{ $total }}</span> event{{ $total !== 1 ? 's' : '' }}
            @if($filterStatus || $search)
                <span class="text-white/60 text-xs ml-1">(filtered)</span>
            @endif
        </p>
        <div class="flex items-center gap-1.5">
            @if($this->events->onFirstPage())
                <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">&larr; Prev</button>
            @else
                <button wire:click="previousPage"
                        class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                        style="background:rgba(255,255,255,.15);">&larr; Prev</button>
            @endif
            <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#333333;">{{ $cp }} / {{ $this->events->lastPage() }}</span>
            @if($this->events->hasMorePages())
                <button wire:click="nextPage"
                        class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                        style="background:rgba(255,255,255,.15);">Next &rarr;</button>
            @else
                <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next &rarr;</button>
            @endif
        </div>
    </div>

</div>


{{-- ══════════════════════════════════════════════════════════════════════════
     NO ALUMNI MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showNoAlumniModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeNoAlumniModal">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">
        <div class="px-6 py-5 bg-amber-50 border-b border-amber-100">
            <h2 class="text-lg font-semibold text-amber-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center"><i class="fas fa-triangle-exclamation text-amber-500 text-base"></i></div>
                Cannot Post Event
            </h2>
        </div>
        <div class="p-6" style="background-color:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">No verified alumni found for:</p>
            <p class="font-semibold text-amber-700 text-lg mb-4">{{ $this->organizerDepartment ?: 'Your College' }}</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 text-sm flex items-start gap-2" style="color:#666666;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>You cannot create an event until at least one verified alumni is registered under your college. Please contact the admin if this seems incorrect.</span>
            </div>
            <button wire:click="closeNoAlumniModal"
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition" style="color:#666666;">
                Close
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     RESUBMIT CONFIRMATION MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showResubmitConfirmModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape.window="cancelResubmit">
    <div class="rounded-2xl shadow-2xl w-full max-w-md overflow-hidden m-in" style="background-color:#ffffff;">

        <div class="px-6 py-5 border-b border-gray-100"
             style="background: linear-gradient(135deg,#f5eef9,#ede4f5);">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:#7a3f91;">
                    <i class="fas fa-rotate-right text-white text-base"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold" style="color:#333333;">Resubmit Event for Review?</h2>
                    <p class="text-xs mt-0.5" style="color:#666666;">This event was previously rejected.</p>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-4" style="background-color:#ffffff;">

            <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:#999999;">Event</p>
                <p class="font-semibold text-sm" style="color:#333333;">{{ $resubmitEventTitle }}</p>
            </div>

            @if($resubmitEventRemarks)
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                <i class="fas fa-circle-xmark text-red-400 text-base flex-shrink-0 mt-0.5"></i>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-red-700 mb-0.5">Rejection Reason</p>
                    <p class="text-sm text-red-700">{{ $resubmitEventRemarks }}</p>
                </div>
            </div>
            @endif

            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                <p class="text-sm text-blue-700">
                    You can <strong>edit the event details</strong> and once you save, it will be
                    <strong>resubmitted for admin review</strong> and set back to <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-yellow-100 text-yellow-700 text-xs font-semibold">Pending</span>.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 pt-1">
                <button wire:click="deleteFromResubmitModal" type="button"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-3 border border-red-200 bg-red-50 text-red-700 rounded-xl text-sm font-semibold hover:bg-red-100 transition cursor-pointer">
                    <i class="fas fa-trash text-xs"></i> Delete Instead
                </button>
                <div class="flex gap-3 flex-1">
                    <button wire:click="cancelResubmit" type="button"
                            class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer"
                            style="color:#666666; background-color:#ffffff;">
                        Cancel
                    </button>
                    <button wire:click="confirmResubmit" type="button"
                            class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white shadow-md transition cursor-pointer"
                            style="background-color:#7a3f91;"
                            onmouseover="this.style.backgroundColor='#5e2f72'"
                            onmouseout="this.style.backgroundColor='#7a3f91'">
                        <i class="fas fa-pen-to-square mr-1.5"></i> Edit &amp; Resubmit
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     CREATE / EDIT — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeFormModal()"
     x-data="{}"
     x-effect="if($wire.formErrors && Object.keys($wire.formErrors).length > 0){$nextTick(()=>{const el=$refs.formBody;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

    {{-- ── Top Bar ── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                @if($isResubmitting)
                    <i class="fas fa-rotate-right text-white text-sm"></i>
                @elseif($isEditing)
                    <i class="fas fa-pen-to-square text-white text-sm"></i>
                @else
                    <i class="fas fa-calendar-plus text-white text-sm"></i>
                @endif
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">
                    @if($isResubmitting)
                        Edit &amp; Resubmit Event
                    @elseif($isEditing)
                        Edit Event
                    @else
                        Submit a New Event
                    @endif
                </h2>
                <p class="text-white/60 text-xs">
                    @if($isResubmitting)
                        Make your changes — saving will resubmit for admin review
                    @elseif($isEditing)
                        Update event details below
                    @else
                        Fill in the event details — will be sent for admin review
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if(!$isEditing && !$isResubmitting)
            <button wire:click="resetForm" type="button"
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer"
                    title="Reset all form fields">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline text-xs">Reset</span>
            </button>
            @endif
            <button wire:click="closeFormModal" type="button"
                    class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
                <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- ── Resubmit Banner ── --}}
    @if($isResubmitting)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-3 shrink-0 flex items-center gap-3">
        <i class="fas fa-rotate-right text-amber-500 flex-shrink-0"></i>
        <p class="text-sm text-amber-800">
            <strong>Resubmitting:</strong> Edit the details below and click <strong>Save &amp; Resubmit</strong> to send it back for admin approval.
        </p>
    </div>
    @endif

    {{-- ── Validation Errors Banner ── --}}
    @if(count($formErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-4 shrink-0">
        <p class="font-semibold text-red-800 text-sm mb-2 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i> Please fix the following:
        </p>
        <ul class="text-red-700 text-sm space-y-1">
            @foreach($formErrors as $err)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- ── Scrollable Body ── --}}
    <div class="flex-1 overflow-y-auto" x-ref="formBody"
         style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- ── CARD: Event Photo ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-image text-[#7a3f91] text-xs"></i>
                        Event Photo
                        <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— optional</span>
                    </h3>
                </div>
                <div class="p-6">
                    <div x-data="{isDragging:false}"
                         @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                         class="border-2 rounded-xl p-5 text-center cursor-pointer transition-all"
                         :class="isDragging?'border-[#7a3f91] bg-[#f5eef9]':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-[#7a3f91] border-solid bg-[#f5eef9]/40':'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-gray-50' }}'">
                        <label class="cursor-pointer block">
                            <input type="file" wire:model="photo" accept="image/*" class="hidden">
                            @if($photo)
                                <div class="flex flex-col items-center gap-2">
                                    <img src="{{ $photo->temporaryUrl() }}" class="w-40 h-28 object-cover rounded-xl shadow border border-purple-200">
                                    <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
                                </div>
                            @elseif($existingPhotoUrl&&!$removePhoto)
                                <div class="flex flex-col items-center gap-2">
                                    <img src="{{ $existingPhotoUrl }}" class="w-40 h-28 object-cover rounded-xl shadow border border-gray-200">
                                    <p class="text-sm font-semibold" style="color:#666666;">Current photo — click to change</p>
                                </div>
                            @else
                                <div class="flex flex-col items-center gap-2 py-3">
                                    <i class="fas fa-cloud-arrow-up text-4xl text-gray-300"></i>
                                    <p class="font-semibold text-sm" style="color:#666666;">Click to upload or drag &amp; drop</p>
                                    <p class="text-xs" style="color:#999999;">JPG, PNG, WEBP — max 5 MB · Default photo used if blank</p>
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
                        </div>
                    @endif
                    @if($removePhoto)
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-sm text-amber-700 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span>
                            <button type="button" wire:click="$set('removePhoto',false)" class="text-sm text-blue-600 underline">Undo</button>
                        </div>
                    @endif
                    <div wire:loading wire:target="photo" class="mt-2 text-sm text-[#7a3f91] flex items-center gap-2">
                        <i class="fas fa-spinner animate-spin"></i> Uploading…
                    </div>
                </div>
            </div>

            {{-- ── CARD: Event Details ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-circle-info text-[#7a3f91] text-xs"></i> Event Details
                    </h3>
                </div>
                <div class="p-6 space-y-5">

                    {{-- Title --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Event Title <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['title'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['title']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>

                    {{-- Description --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Description <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="description" rows="10" placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                  class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 resize-y {{ isset($formErrors['description'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($formErrors['description']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['description'] }}</p>@endif
                    </div>

                    {{-- ── Date + Times ── --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                        {{-- Event Date --}}
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Event Date <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="event_date" type="date" min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['event_date'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['event_date']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['event_date'] }}</p>@endif
                        </div>

                        {{-- Start Time --}}
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Start Time <span class="text-red-500">*</span>
                            </label>
                            <div wire:ignore
                                 x-data="{
                                     h: '12', m: '00', p: 'AM',
                                     init() {
                                         let v = $wire.start_time;
                                         if (v && v.includes(':')) {
                                             let parts = v.split(':');
                                             let hi = parseInt(parts[0]);
                                             this.p = hi >= 12 ? 'PM' : 'AM';
                                             hi = hi % 12 || 12;
                                             this.h = String(hi);
                                             this.m = parts[1] ? parts[1].substring(0,2) : '00';
                                         }
                                     },
                                     sync() {
                                         let hi = parseInt(this.h);
                                         if (this.p === 'PM' && hi !== 12) hi += 12;
                                         if (this.p === 'AM' && hi === 12) hi = 0;
                                         $wire.set('start_time', String(hi).padStart(2,'0') + ':' + this.m);
                                     }
                                 }"
                                 @reset-time-selects.window="h='12'; m='00'; p='AM'; sync()"
                                 class="time-select-wrap border {{ isset($formErrors['start_time']) ? 'border-red-400' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                <i class="fas fa-clock text-gray-300 text-sm" style="padding:0 0 0 12px; display:flex; align-items:center; background:#fff; border-right:1px solid #e5e7eb;"></i>
                                <select x-model="h" @change="sync()" class="ts-hour" title="Hour">
                                    <option value="1">01</option><option value="2">02</option><option value="3">03</option>
                                    <option value="4">04</option><option value="5">05</option><option value="6">06</option>
                                    <option value="7">07</option><option value="8">08</option><option value="9">09</option>
                                    <option value="10">10</option><option value="11">11</option><option value="12">12</option>
                                </select>
                                <span class="ts-sep">:</span>
                                <select x-model="m" @change="sync()" title="Minute">
                                    <option value="00">00</option><option value="05">05</option><option value="10">10</option>
                                    <option value="15">15</option><option value="20">20</option><option value="25">25</option>
                                    <option value="30">30</option><option value="35">35</option><option value="40">40</option>
                                    <option value="45">45</option><option value="50">50</option><option value="55">55</option>
                                </select>
                                <select x-model="p" @change="sync()" class="ts-period" title="AM/PM">
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            @if(isset($formErrors['start_time']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>

                        {{-- End Time --}}
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                End Time <span class="text-red-500">*</span>
                            </label>
                            <div wire:ignore
                                 x-data="{
                                     h: '12', m: '00', p: 'AM',
                                     init() {
                                         let v = $wire.end_time;
                                         if (v && v.includes(':')) {
                                             let parts = v.split(':');
                                             let hi = parseInt(parts[0]);
                                             this.p = hi >= 12 ? 'PM' : 'AM';
                                             hi = hi % 12 || 12;
                                             this.h = String(hi);
                                             this.m = parts[1] ? parts[1].substring(0,2) : '00';
                                         }
                                     },
                                     sync() {
                                         let hi = parseInt(this.h);
                                         if (this.p === 'PM' && hi !== 12) hi += 12;
                                         if (this.p === 'AM' && hi === 12) hi = 0;
                                         $wire.set('end_time', String(hi).padStart(2,'0') + ':' + this.m);
                                     }
                                 }"
                                 @reset-time-selects.window="h='12'; m='00'; p='AM'; sync()"
                                 class="time-select-wrap border {{ isset($formErrors['end_time']) ? 'border-red-400' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                <i class="fas fa-clock text-gray-300 text-sm" style="padding:0 0 0 12px; display:flex; align-items:center; background:#fff; border-right:1px solid #e5e7eb;"></i>
                                <select x-model="h" @change="sync()" class="ts-hour" title="Hour">
                                    <option value="1">01</option><option value="2">02</option><option value="3">03</option>
                                    <option value="4">04</option><option value="5">05</option><option value="6">06</option>
                                    <option value="7">07</option><option value="8">08</option><option value="9">09</option>
                                    <option value="10">10</option><option value="11">11</option><option value="12">12</option>
                                </select>
                                <span class="ts-sep">:</span>
                                <select x-model="m" @change="sync()" title="Minute">
                                    <option value="00">00</option><option value="05">05</option><option value="10">10</option>
                                    <option value="15">15</option><option value="20">20</option><option value="25">25</option>
                                    <option value="30">30</option><option value="35">35</option><option value="40">40</option>
                                    <option value="45">45</option><option value="50">50</option><option value="55">55</option>
                                </select>
                                <select x-model="p" @change="sync()" class="ts-period" title="AM/PM">
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            @if(isset($formErrors['end_time']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['end_time'] }}</p>@endif
                        </div>

                    </div>

                    {{-- Venue + Full Address --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Venue / Location <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['venue'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['venue']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['venue'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Full Address <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Old Nalsian Road, Calasiao, Pangasinan" maxlength="200"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['venue_address'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['venue_address']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['venue_address'] }}</p>@endif
                        </div>
                    </div>

                </div>
            </div>

            {{-- ══════════════════════════════════════════════════════════════
                 CARD: Courses / Programs  — NOW REQUIRED
            ══════════════════════════════════════════════════════════════ --}}
            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden
                        {{ isset($formErrors['selected_courses']) ? 'border-red-300' : 'border-gray-200' }}">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-book text-[#7a3f91] text-xs"></i>
                        Courses / Programs
                        <span class="text-red-500 font-bold">*</span>
                        {{-- Live count badge --}}
                        @if(count($selectedCourses) > 0)
                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 text-xs font-semibold border border-purple-200">
                                <i class="fas fa-check text-[9px]"></i>
                                {{ count($selectedCourses) }} selected
                            </span>
                        @else
                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-xs font-semibold border border-red-200">
                                <i class="fas fa-triangle-exclamation text-[9px]"></i>
                                None selected
                            </span>
                        @endif
                    </h3>
                </div>
                <div class="p-6 space-y-4">

                    {{-- College banner --}}
                    <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-4 py-3">
                        <i class="fas fa-building-columns text-purple-500 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-purple-800">{{ $this->organizerDepartment ?: 'Your College' }}</div>
                            <div class="text-xs text-purple-700 mt-0.5">
                                Select at least one course, or click <strong>Select All</strong> to target the entire college.
                            </div>
                        </div>
                    </div>

                    @if(count($this->availableCourses) > 0)
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-semibold text-gray-500 uppercase tracking-wide flex items-center gap-1.5">
                                    Available Courses
                                    <span class="text-red-500">*</span>
                                </span>
                                <div class="flex gap-3">
                                    <button type="button"
                                            wire:click="$set('selectedCourses', {{ json_encode($this->availableCourses) }})"
                                            class="text-sm font-semibold hover:underline" style="color:#7a3f91;">
                                        <i class="fas fa-check-double mr-1"></i>Select All
                                    </button>
                                    @if(count($selectedCourses) > 0)
                                        <button type="button" wire:click="$set('selectedCourses', [])"
                                                class="text-sm font-semibold hover:text-red-600" style="color:#999999;">Clear</button>
                                    @endif
                                </div>
                            </div>

                            {{-- Course checkboxes — error ring when none selected --}}
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2
                                        {{ isset($formErrors['selected_courses']) ? 'p-3 rounded-xl border border-red-200 bg-red-50/40' : '' }}">
                                @foreach($this->availableCourses as $course)
                                    <label class="flex items-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer transition text-sm font-semibold
                                                  {{ in_array($course, $selectedCourses)
                                                      ? 'border-purple-400 bg-purple-50 text-purple-700'
                                                      : 'border-gray-200 text-gray-600 hover:border-purple-300 hover:bg-purple-50/40 bg-white' }}">
                                        <input type="checkbox" wire:model.live="selectedCourses" value="{{ $course }}"
                                               class="accent-purple-600 w-4 h-4 flex-shrink-0">
                                        <span>{{ $course }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Validation error message for courses --}}
                        @if(isset($formErrors['selected_courses']))
                            <p class="text-red-600 text-sm flex items-center gap-1.5 font-semibold -mt-1">
                                <i class="fas fa-circle-exclamation"></i>
                                {{ $formErrors['selected_courses'] }}
                            </p>
                        @endif

                    @else
                        <div class="text-center py-4 text-sm" style="color:#999999;">
                            <i class="fas fa-inbox text-3xl block mb-2 text-gray-200"></i>
                            No courses available for your college yet.
                        </div>
                    @endif

                    {{-- Batch Year --}}
                    <div class="pt-3 border-t border-gray-100">
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Batch Year
                        </label>
                        <input wire:model.defer="batchYear"
                               type="number"
                               min="1990"
                               max="{{ now()->year + 5 }}"
                               step="1"
                               placeholder="e.g. {{ now()->year - 2 }}"
                               inputmode="numeric"
                               pattern="\d{4}"
                               class="w-full sm:max-w-xs px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['batch_year'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['batch_year']))
                            <p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['batch_year'] }}</p>
                        @else
                            <p class="text-sm mt-1" style="color:#999999;"><i class="fas fa-circle-info mr-1"></i>Leave blank to target all batches.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── CARD: Contact Person ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-address-card text-[#7a3f91] text-xs"></i> Contact Person
                        <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— pre-filled from your account</span>
                    </h3>
                </div>
                <div class="p-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Name</label>
                        <input wire:model.defer="contact_person" type="text" placeholder="Full name"
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               style="color:#333333;">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Email</label>
                        <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com"
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white focus:outline-none focus:ring-2 transition {{ isset($formErrors['contact_email'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['contact_email']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['contact_email'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Phone <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— optional</span>
                        </label>
                        <input wire:model.defer="contact_phone"
                               type="text"
                               placeholder="09XXXXXXXXX"
                               maxlength="16"
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white focus:outline-none focus:ring-2 transition {{ isset($formErrors['contact_phone'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['contact_phone']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['contact_phone'] }}</p>@endif
                    </div>
                </div>
            </div>

            {{-- ── CARD: Additional Notes ── --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-list-check text-[#7a3f91] text-xs"></i>
                        Additional Notes / Requirements
                        <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— optional</span>
                    </h3>
                </div>
                <div class="p-6">
                    <textarea wire:model.defer="notes" rows="8" placeholder="Dress code, special instructions, requirements…" maxlength="3000"
                              class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-y"
                              style="color:#333333;"></textarea>
                </div>
            </div>

            {{-- ── Action Buttons ── --}}
            <div class="flex flex-wrap gap-3 pb-3">
                <button type="button" wire:click="closeFormModal"
                        class="flex-1 sm:flex-none sm:w-40 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition cursor-pointer">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="saveEvent"
                        wire:loading.attr="disabled" wire:target="saveEvent"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color: {{ $isResubmitting ? '#d97706' : '#7a3f91' }};"
                        onmouseover="this.style.backgroundColor='{{ $isResubmitting ? '#b45309' : '#5e2f72' }}'"
                        onmouseout="this.style.backgroundColor='{{ $isResubmitting ? '#d97706' : '#7a3f91' }}'">
                    <span wire:loading wire:target="saveEvent">
                        <i class="fas fa-spinner animate-spin"></i>
                        @if($isResubmitting) Resubmitting… @elseif($isEditing) Saving… @else Submitting… @endif
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">
                        @if($isResubmitting)
                            <i class="fas fa-rotate-right"></i> Save &amp; Resubmit
                        @elseif($isEditing)
                            <i class="fas fa-floppy-disk"></i> Save Changes
                        @else
                            <i class="fas fa-paper-plane"></i> Submit Event
                        @endif
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW EVENT — SLIDE-OVER
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
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none flex-shrink-0 ml-3 cursor-pointer">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            <div class="relative w-full bg-gray-100 flex items-center justify-center" style="min-height:180px; max-height:300px;">
                <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                     class="w-full object-contain"
                     style="max-height:300px; display:block;">
                <div class="absolute top-3 right-3">
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">
                            <i class="fas fa-circle-check text-xs"></i> Completed
                        </span>
                    @elseif($isApproved)
                        <span class="inline-block px-3 py-1.5 bg-emerald-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Approved</span>
                    @elseif($isPending)
                        <span class="inline-block px-3 py-1.5 bg-amber-600/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Pending</span>
                    @else
                        <span class="inline-block px-3 py-1.5 bg-red-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Rejected</span>
                    @endif
                </div>
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-calendar text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $eventDatePH->format('F d, Y') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-clock text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $timeDisplay }}</span>
                    </li>
                    @if($ev->venue)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-location-dot text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            {{ $ev->venue }}
                            @if($ev->venue_address)
                                <span class="text-sm font-normal" style="color:#666666;"> · {{ $ev->venue_address }}</span>
                            @endif
                        </span>
                    </li>
                    @endif
                    @if($ev->target_participants)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-users text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $ev->target_participants }}</span>
                    </li>
                    @endif
                </ul>
            </div>

            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#333333;">
                    <i class="fas fa-users text-xs"></i> Attendee Responses
                    @if($totalRsvp > 0)<span class="font-normal ml-1" style="color:#999999;">{{ $totalRsvp }} total</span>@endif
                </h3>
                @if($totalRsvp === 0)
                    <div class="text-center py-5" style="color:#999999;">
                        <i class="fas fa-inbox text-2xl block mb-2 text-gray-200"></i>
                        <p class="text-sm font-semibold">No responses yet.</p>
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
                            <i class="fas fa-circle-check text-emerald-500 text-lg mb-1"></i>
                            <div class="text-2xl font-semibold text-emerald-700">{{ $ev->confirmed_count }}</div>
                            <div class="text-xs font-semibold text-emerald-600 uppercase tracking-wide mt-1">Attending</div>
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
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Review Status</h3>
                @if($isCompleted)
                    <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-green-800"><i class="fas fa-circle-check mr-2 text-green-500"></i>Event Completed</p>
                        <p class="text-sm text-green-700 mt-1">This event has already taken place successfully.</p>
                    </div>
                @elseif($isApproved)
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800"><i class="fas fa-circle-check mr-2 text-emerald-500"></i>Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="text-xs text-emerald-700 mt-1">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm text-emerald-700 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                    </div>
                @elseif($isPending)
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-amber-800"><i class="fas fa-hourglass-half mr-2 text-amber-500"></i>Awaiting Admin Review</p>
                        <p class="text-sm text-amber-700 mt-1">Your event is pending approval. You will be notified once it has been reviewed.</p>
                    </div>
                @else
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-red-800"><i class="fas fa-circle-xmark mr-2 text-red-500"></i>Rejected by Administrator</p>
                        @if($ev->review_remarks)<p class="text-sm text-red-700 mt-1"><span class="font-semibold">Reason:</span> {{ $ev->review_remarks }}</p>@endif
                        <p class="text-sm text-red-700 mt-1 font-semibold">You may edit and resubmit this event.</p>
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

            @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Contact Person</h3>
                <div class="space-y-2">
                    @if($ev->contact_person)
                    <p class="flex items-center gap-2.5 text-sm font-semibold" style="color:#333333;">
                        <i class="fas fa-user text-[#7a3f91] w-4"></i>{{ $ev->contact_person }}
                    </p>
                    @endif
                    @if($ev->contact_email)
                    <p class="flex items-center gap-2.5 text-sm" style="color:#333333;">
                        <i class="fas fa-envelope text-[#7a3f91] w-4"></i>{{ $ev->contact_email }}
                    </p>
                    @endif
                    @if($ev->contact_phone)
                    <p class="flex items-center gap-2.5 text-sm" style="color:#333333;">
                        <i class="fas fa-phone text-[#7a3f91] w-4"></i>{{ $ev->contact_phone }}
                    </p>
                    @endif
                </div>
            </div>
            @endif

            <div class="px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Posting Details</p>
                <div class="grid grid-cols-2 border border-gray-200 rounded-xl overflow-hidden divide-x divide-gray-100">
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Date Posted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $createdPH->format('M d, Y') }}</p>
                        <p class="text-xs mt-0.5" style="color:#666666;">{{ $createdPH->format('g:i A') }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Target Participants</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->target_participants ?? '—' }}</p>
                    </div>
                </div>
            </div>

        </div>

        <div class="px-6 py-4 border-t border-gray-200 flex-shrink-0 flex items-center justify-end gap-2 flex-wrap bg-white">
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)" type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 rounded-xl transition cursor-pointer" style="color:#666666;">
                <i class="fas fa-xmark text-xs"></i> Close
            </button>
            @if($isApproved)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i> Share
                </button>
            @elseif($isCompleted)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-trophy text-xs"></i> Share Highlights
                </button>
            @endif
            @if(!$isCompleted && !$isApproved)
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 bg-red-50 border border-red-200 hover:bg-white hover:border-red-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-trash text-xs"></i> Delete
                </button>
                <button wire:click="openEditModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white hover:border-blue-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs"></i> Edit
                </button>
            @endif
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     DELETE MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDelete">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">
        <div class="px-6 py-5 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-semibold text-lg">Delete Event</h2>
        </div>
        <div class="p-6" style="background-color:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">You are about to delete:</p>
            <p class="font-semibold text-red-700 text-lg mb-4">"{{ $deleteEventTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2" style="color:#666666;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>This event will be removed from your list.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer" style="color:#333333; background-color:#ffffff;">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE / HIGHLIGHTS — SLIDE-OVER
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
        $dPrev = mb_strlen($shareEventDescription) > 200
            ? mb_substr($shareEventDescription, 0, 200) . '…'
            : $shareEventDescription;
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
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false, fbCopyFailed: false,
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
             } catch(e) { console.warn('Copy failed', e); }
         },
         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const htmlContent = '<img src=\'' + imageUrl + '\' alt=\'Event Photo\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                     const htmlBlob = new Blob([htmlContent], { type: 'text/html' });
                     const textBlob = new Blob([text],        { type: 'text/plain' });
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
             const target   = this.hasPhoto ? this.photoUrl : this.baseUrl;
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
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0" style="background-color:#ffffff;">
            <h2 class="text-lg font-semibold flex items-center gap-2" style="color:#333333;">
                @if($isCompleted)
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
                             class="w-full object-contain" style="max-height:220px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color: {{ $isCompleted ? '#fffbeb' : '#f9f7fc' }};">
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
                            <em>and</em> copies the event photo + caption to your clipboard.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd>
                            in the composer to paste automatically.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Sends the event caption directly to all target batch chat rooms for
                            <strong>{{ $shareEventTarget ?: ($this->organizerDepartment ?: 'your college') }}</strong>.
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
                            <defs><linearGradient id="mgr_org2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_org2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or post directly</span>
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
                        <i class="fas fa-users text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends to all target batch rooms</span>
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