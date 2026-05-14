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

    public bool $showNoAlumniModal = false;

    public bool   $showResubmitConfirmModal = false;
    public ?int   $pendingEditId            = null;
    public bool   $isResubmitting           = false;
    public string $resubmitEventTitle       = '';
    public string $resubmitEventRemarks     = '';

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
                'review_remarks' => 'Auto-rejected: event date has already passed without Alumni Director approval.',
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

    public function resetForm(): void
    {
        $savedId         = $this->editingEventId;
        $savedIsEditing  = $this->isEditing;
        $savedIsResubmit = $this->isResubmitting;

        $this->resetFormFields();

        $this->start_time = '08:00';
        $this->end_time   = '17:00';

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

        // Check same time
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

        // Check end time before start time
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
                    $errors['event_date'] = 'Event date and start time cannot be in the past. Please choose a future date and time.';
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
                ? "Organizer resubmitted rejected event: '" . trim($this->title) . "' for Alumni Director review."
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
                ? 'Event resubmitted for Alumni Director review!'
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
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Filter inputs ── */
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
/* ── Table rows ── */
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #FAFAFA !important; cursor: default; }

/* ── Form labels ── */
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

/* time select */
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
    font-size: 0.95rem;
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
.time-select-wrap .ts-sep {
    display: flex;
    align-items: center;
    padding: 0 2px;
    background: #fff;
    color: #555;
    font-weight: 600;
    font-size: 0.95rem;
    border-left: 1px solid #e5e7eb;
    border-right: 1px solid #e5e7eb;
    user-select: none;
}
.time-select-wrap .ts-period {
    font-weight: 600;
    color: #7a3f91;
    border-left: 1px solid #e5e7eb;
    background: #faf7fc;
    min-width: 3rem;
}
.time-select-wrap .ts-hour { border-right: 1px solid #e5e7eb; }

/* ── Unified table block ── */
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
/* ── View modal meta row ── */
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

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Event Management</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">
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
                {{ $this->events->total() }} {{ $this->events->total() !== 1 ? 'Events' : 'Event' }}
            </span>
            <button wire:click="openCreateModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-sm text-white shadow-md transition cursor-pointer {{ !$this->hasAlumni ? 'opacity-60 cursor-not-allowed' : '' }}"
                    style="background-color:#7a3f91;"
                    onmouseover="this.style.backgroundColor='#5e2f72'"
                    onmouseout="this.style.backgroundColor='#7a3f91'">
                <i class="fas fa-plus text-xs"></i> Submit Event
            </button>
        </div>
    </div>

    {{-- No alumni notice --}}
    @if(!$this->hasAlumni)
    <div class="flex-shrink-0 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
        <div>
            <p class="font-semibold text-sm" style="color:#333333;">No verified alumni found for {{ $this->organizerDepartment ?: 'your college' }}.</p>
            <p class="mt-0.5 text-xs" style="color:#555555;">You cannot post events until at least one verified alumni is registered under your college.</p>
        </div>
    </div>
    @endif

    {{-- ══ UNIFIED BLOCK — filter + table + pagination ══ --}}
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
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            <select wire:model.live="filterSort" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col">

            <div wire:loading
                 wire:target="search,filterStatus,filterSort,resetFilters,previousPage,nextPage"
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
                <table class="w-full min-w-[700px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Event Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Date &amp; Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Venue</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell" style="color:#555555;">Course</th>
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
                            $canEdit     = !$isCompleted && !$isApproved;

                            $tp          = $event->target_participants ?? '';
                            $tpParts     = explode(' · Batch ', $tp, 2);
                            $displayCourses = trim($tpParts[0]) ?: ($this->organizerDepartment ?: 'All Courses');
                            $batchDisplay   = !empty($tpParts[1]) ? trim($tpParts[1]) : null;

                            $eventDate  = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum     = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
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
                                <p class="text-sm max-w-[160px] truncate" style="color:#333333;">{{ $event->venue ?: '—' }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden xl:table-cell">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 w-fit max-w-[160px] truncate block">
                                        {{ Str::limit($displayCourses, 20) }}
                                    </span>
                                    @if($batchDisplay)
                                        <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-gray-100 border border-gray-200 w-fit" style="color:#333333;">
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

                                    @if($isApproved)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @elseif($isCompleted)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trophy text-xs"></i>
                                            <span class="hidden xl:inline">Highlights</span>
                                        </button>
                                    @endif

                                    @if($canEdit)
                                        <button type="button" wire:click.stop="openEditModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
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
                        @if($search || $filterStatus) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus) Try clearing your filters to see all events.
                        @else Click <strong>Submit Event</strong> to submit your first event for Alumni Director review.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>

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
                @if($filterStatus || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->events->onFirstPage())
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">
                        &larr; Prev
                    </button>
                @else
                    <button wire:click="previousPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">
                        &larr; Prev
                    </button>
                @endif
                <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#7a3f91;">
                    {{ $cp }} / {{ $this->events->lastPage() }}
                </span>
                @if($this->events->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">
                        Next &rarr;
                    </button>
                @else
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">
                        Next &rarr;
                    </button>
                @endif
            </div>
        </div>

    </div>

</div>


{{-- ══════════════════════════════════════════════════════════════════════════
     NO ALUMNI MODAL
══════════════════════════════════════════════════════════════════════════ --}}
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
            <p class="text-sm mb-1" style="color:#555555;">No verified alumni found for:</p>
            <p class="font-semibold text-amber-700 text-base mb-4">{{ $this->organizerDepartment ?: 'Your College' }}</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm flex items-start gap-2" style="color:#333333;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-sm">You cannot create an event until at least one verified alumni is registered under your college. Please contact the Alumni Director if this seems incorrect.</span>
            </div>
            <button wire:click="closeNoAlumniModal"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition" style="color:#333333;">
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
    <div class="rounded-2xl shadow-2xl w-full max-w-md overflow-hidden m-in bg-white">

        <div class="px-6 py-4 border-b border-gray-100"
             style="background: linear-gradient(135deg,#f5eef9,#ede4f5);">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:#7a3f91;">
                    <i class="fas fa-rotate-right text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold" style="color:#333333;">Resubmit Event for Review?</h2>
                    <p class="text-xs mt-0.5" style="color:#555555;">This event was previously rejected.</p>
                </div>
            </div>
        </div>

        <div class="p-5 space-y-3 bg-white">
            <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-widest mb-1" style="color:#555555;">Event</p>
                <p class="font-semibold text-sm" style="color:#333333;">{{ $resubmitEventTitle }}</p>
            </div>

            @if($resubmitEventRemarks)
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                <i class="fas fa-circle-xmark text-red-400 text-sm flex-shrink-0 mt-0.5"></i>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-red-700 mb-0.5">Rejection Reason</p>
                    <p class="text-sm text-red-700">{{ $resubmitEventRemarks }}</p>
                </div>
            </div>
            @endif

            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                <p class="text-sm text-blue-700">
                    You can <strong>edit the event details</strong> and once you save, it will be
                    <strong>resubmitted for Alumni Director review</strong> and set back to
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-yellow-100 text-yellow-700 text-xs font-semibold">Pending</span>.
                </p>
            </div>

            <div class="flex gap-3 pt-1">
                <button wire:click="cancelResubmit" type="button"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    Cancel
                </button>
                <button wire:click="confirmResubmit" type="button"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white shadow-md transition cursor-pointer"
                        style="background-color:#7a3f91;"
                        onmouseover="this.style.backgroundColor='#5e2f72'"
                        onmouseout="this.style.backgroundColor='#7a3f91'">
                    <i class="fas fa-pen-to-square mr-1.5 text-xs"></i> Edit &amp; Resubmit
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     CREATE / EDIT — FULL SCREEN (3-column)
══════════════════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in"
     @keydown.escape.window="$wire.closeFormModal()">

    {{-- ── Top Bar ── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
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
                    @if($isResubmitting) Edit &amp; Resubmit Event
                    @elseif($isEditing) Edit Event
                    @else Submit a New Event
                    @endif
                </h2>
                <p class="text-white/60 text-xs mt-0.5">
                    @if($isResubmitting) Make changes — saving will resubmit for Alumni Director review
                    @elseif($isEditing) Update event details below
                    @else Fill in details — will be sent for Alumni Director review
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if(!$isEditing && !$isResubmitting)
            <button wire:click="resetForm" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline text-sm">Reset</span>
            </button>
            @endif
            <button wire:click="closeFormModal" type="button"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline text-sm">Close</span>
            </button>
        </div>
    </div>

    {{-- ── Resubmit Banner ── --}}
    @if($isResubmitting)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 shrink-0 flex items-center gap-3">
        <i class="fas fa-rotate-right text-amber-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm" style="color:#333333;">
            <strong>Resubmitting:</strong> Edit the details and click <strong>Save &amp; Resubmit</strong> to send back for Alumni Director approval.
        </p>
    </div>
    @endif

    {{-- ── 3-COLUMN BODY ── --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-0 overflow-hidden">

        {{-- ═══ LEFT COLUMN: Photo + Courses ═══ --}}
        <div class="w-full lg:w-[300px] xl:w-[320px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto scroll-c bg-white"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                {{-- Photo Upload --}}
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
                            <label class="cursor-pointer block p-3">
                                <input type="file" wire:model="photo" accept="image/*" class="hidden">
                                @if($photo)
                                    <div class="flex flex-col items-center gap-1.5">
                                        <img src="{{ $photo->temporaryUrl() }}" class="w-full h-24 object-contain rounded-lg shadow border border-purple-200">
                                        <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1 text-xs"></i>New photo selected</p>
                                    </div>
                                @elseif($existingPhotoUrl&&!$removePhoto)
                                    <div class="flex flex-col items-center gap-1.5">
                                        <img src="{{ $existingPhotoUrl }}" class="w-full h-24 object-contain rounded-lg shadow border border-gray-200">
                                        <p class="text-sm font-semibold" style="color:#555555;">Current photo — click to change</p>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-1.5 py-4">
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

                {{-- Courses / Programs --}}
                <div class="card-section {{ isset($formErrors['selected_courses']) ? 'border-red-300' : '' }}">
                    <div class="card-section-hd">
                        <i class="fas fa-book text-[9px]"></i>
                        Courses / Programs
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                        @if(count($selectedCourses) > 0)
                            <span class="ml-auto inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-purple-200 text-purple-800 text-[10px] font-semibold">
                                {{ count($selectedCourses) }} selected
                            </span>
                        @else
                            <span class="ml-auto inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-semibold">
                                None
                            </span>
                        @endif
                    </div>
                    <div class="card-section-body space-y-3">

                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
                            <i class="fas fa-building-columns text-purple-500 text-xs flex-shrink-0"></i>
                            <span class="text-sm font-semibold text-purple-800 truncate">{{ $this->organizerDepartment ?: 'Your College' }}</span>
                        </div>

                        @if(count($this->availableCourses) > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider" style="color:#555555;">Select courses</span>
                                <div class="flex gap-2">
                                    <button type="button"
                                            wire:click="$set('selectedCourses', {{ json_encode($this->availableCourses) }})"
                                            class="text-xs font-semibold hover:underline text-[#7a3f91]">
                                        <i class="fas fa-check-double mr-0.5 text-[10px]"></i>All
                                    </button>
                                    @if(count($selectedCourses) > 0)
                                        <button type="button" wire:click="$set('selectedCourses', [])"
                                                class="text-xs font-semibold hover:text-red-500" style="color:#555555;">Clear</button>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-1.5 {{ isset($formErrors['selected_courses']) ? 'p-2 rounded-lg border border-red-200 bg-red-50/30' : '' }}">
                                @foreach($this->availableCourses as $course)
                                    <label class="flex items-center gap-1.5 px-2 py-1.5 border rounded-lg cursor-pointer transition text-xs font-semibold
                                                  {{ in_array($course, $selectedCourses)
                                                      ? 'border-purple-400 bg-purple-50 text-purple-700'
                                                      : 'border-gray-200 hover:border-purple-300 hover:bg-purple-50/40 bg-white' }}"
                                          style="{{ !in_array($course, $selectedCourses) ? 'color:#333333;' : '' }}">
                                        <input type="checkbox" wire:model.live="selectedCourses" value="{{ $course }}"
                                               class="accent-purple-600 w-3.5 h-3.5 flex-shrink-0">
                                        <span class="truncate">{{ $course }}</span>
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
                            <div class="text-center py-3">
                                <i class="fas fa-inbox text-2xl block mb-1.5 text-gray-200"></i>
                                <p class="text-sm" style="color:#555555;">No courses available yet.</p>
                            </div>
                        @endif

                        {{-- Batch Year --}}
                        <div class="pt-2 border-t border-gray-100">
                            <label class="form-label">Batch Year <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                            <input wire:model.defer="batchYear"
                                   type="number" min="1990" max="{{ now()->year + 5 }}" step="1"
                                   placeholder="e.g. {{ now()->year - 2 }}"
                                   class="form-input {{ isset($formErrors['batch_year']) ? 'error' : '' }}">
                            @if(isset($formErrors['batch_year']))
                                <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['batch_year'] }}</p>
                            @else
                                <p class="text-xs mt-1" style="color:#777777;">Leave blank to target all batches.</p>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ MIDDLE COLUMN: Event Details + Notes ═══ --}}
        <div class="flex-1 min-w-0 overflow-y-auto scroll-c border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                {{-- Event Details Card --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-circle-info text-[9px]"></i> Event Details
                    </div>
                    <div class="card-section-body space-y-4">

                        {{-- Title --}}
                        <div>
                            <label class="form-label">Event Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="title" type="text"
                                   placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                                   class="form-input {{ isset($formErrors['title']) ? 'error' : '' }}">
                            @if(isset($formErrors['title']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['title'] }}</p>@endif
                        </div>

                        {{-- Description --}}
                        <div>
                            <label class="form-label">Description <span class="text-red-500">*</span></label>
                            <textarea wire:model.defer="description" rows="5"
                                      placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                      class="form-input resize-none {{ isset($formErrors['description']) ? 'error' : '' }}"></textarea>
                            @if(isset($formErrors['description']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['description'] }}</p>@endif
                        </div>

                        {{-- Date + Times (3-col grid) --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                            {{-- Event Date --}}
                            <div>
                                <label class="form-label">Date <span class="text-red-500">*</span></label>
                                <input wire:model="event_date" type="date"
                                       min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                                       class="form-input {{ isset($formErrors['event_date']) ? 'error' : '' }}">
                                @if(isset($formErrors['event_date']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['event_date'] }}</p>@endif
                            </div>

                            {{-- START TIME --}}
                            <div>
                                <label class="form-label">Start Time <span class="text-red-500">*</span></label>
                                <div wire:ignore
                                     x-data="{
                                         h: '8',
                                         m: '00',
                                         p: 'AM',
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
                                     class="time-select-wrap border {{ isset($formErrors['start_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2.5 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-sm"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="ts-hour" title="Hour">
                                        <option value="12">12</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9</option>
                                        <option value="10">10</option>
                                        <option value="11">11</option>
                                    </select>
                                    <span class="ts-sep">:</span>
                                    <select x-model="m" @change="sync()" title="Minute">
                                        <option value="00">00</option>
                                        <option value="05">05</option>
                                        <option value="10">10</option>
                                        <option value="15">15</option>
                                        <option value="20">20</option>
                                        <option value="25">25</option>
                                        <option value="30">30</option>
                                        <option value="35">35</option>
                                        <option value="40">40</option>
                                        <option value="45">45</option>
                                        <option value="50">50</option>
                                        <option value="59">59</option>
                                    </select>
                                    <select x-model="p" @change="sync()" class="ts-period" title="AM/PM">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                                @if(isset($formErrors['start_time']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['start_time'] }}</p>@endif
                            </div>

                            {{-- END TIME --}}
                            <div>
                                <label class="form-label">End Time <span class="text-red-500">*</span></label>
                                <div wire:ignore
                                     x-data="{
                                         h: '5',
                                         m: '00',
                                         p: 'PM',
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
                                     class="time-select-wrap border {{ isset($formErrors['end_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2.5 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-sm"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="ts-hour" title="Hour">
                                        <option value="12">12</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9</option>
                                        <option value="10">10</option>
                                        <option value="11">11</option>
                                    </select>
                                    <span class="ts-sep">:</span>
                                    <select x-model="m" @change="sync()" title="Minute">
                                        <option value="00">00</option>
                                        <option value="05">05</option>
                                        <option value="10">10</option>
                                        <option value="15">15</option>
                                        <option value="20">20</option>
                                        <option value="25">25</option>
                                        <option value="30">30</option>
                                        <option value="35">35</option>
                                        <option value="40">40</option>
                                        <option value="45">45</option>
                                        <option value="50">50</option>
                                        <option value="59">59</option>
                                    </select>
                                    <select x-model="p" @change="sync()" class="ts-period" title="AM/PM">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                                @if(isset($formErrors['end_time']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['end_time'] }}</p>@endif
                            </div>

                        </div>

                        {{-- Venue + Address (2-col) --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Venue / Location <span class="text-red-500">*</span></label>
                                <input wire:model.defer="venue" type="text"
                                       placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                       class="form-input {{ isset($formErrors['venue']) ? 'error' : '' }}">
                                @if(isset($formErrors['venue']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['venue'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Full Address <span class="text-red-500">*</span></label>
                                <input wire:model.defer="venue_address" type="text"
                                       placeholder="e.g. Old Nalsian Road, Calasiao, Pangasinan" maxlength="200"
                                       class="form-input {{ isset($formErrors['venue_address']) ? 'error' : '' }}">
                                @if(isset($formErrors['venue_address']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['venue_address'] }}</p>@endif
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Notes / Requirements --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-list-check text-[9px]"></i> Notes / Requirements
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1" style="color:#777777;">— optional</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="notes" rows="4"
                                  placeholder="Dress code, special instructions, what to bring, parking info…" maxlength="3000"
                                  class="form-input resize-none"></textarea>
                        <p class="text-xs mt-1.5 flex items-center gap-1" style="color:#777777;">
                            <i class="fas fa-circle-info text-[10px]"></i>
                            Visible to alumni on the event page. Keep it clear and concise.
                        </p>
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ RIGHT COLUMN: Contact + Actions ═══ --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 overflow-y-auto scroll-c bg-white flex flex-col"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4 flex-1">

                {{-- Contact Person --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-address-card text-[9px]"></i> Contact Person
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1" style="color:#777777;">— pre-filled</span>
                    </div>
                    <div class="card-section-body space-y-3">
                        <div>
                            <label class="form-label">Name</label>
                            <input wire:model.defer="contact_person" type="text" placeholder="Full name"
                                   class="form-input">
                        </div>
                        <div>
                            <label class="form-label">Email</label>
                            <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com"
                                   class="form-input {{ isset($formErrors['contact_email']) ? 'error' : '' }}">
                            @if(isset($formErrors['contact_email']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['contact_email'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">Phone <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                            <input wire:model.defer="contact_phone" type="text"
                                   placeholder="09XXXXXXXXX" maxlength="16"
                                   class="form-input {{ isset($formErrors['contact_phone']) ? 'error' : '' }}">
                            @if(isset($formErrors['contact_phone']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['contact_phone'] }}</p>@endif
                        </div>
                    </div>
                </div>

                {{-- Quick Tips Card --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-lightbulb text-[9px]"></i> Submission Tips
                    </div>
                    <div class="card-section-body">
                        <ul class="space-y-2.5">
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Set a future date — past dates are automatically rejected.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Choose correct courses so the right alumni are notified.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Upload a photo — events with photos get more RSVPs.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Alumni Director review typically takes 1–2 business days.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            {{-- Action Buttons - sticky at bottom --}}
            <div class="shrink-0 px-4 py-4 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="saveEvent"
                        wire:loading.attr="disabled" wire:target="saveEvent"
                        class="w-full px-5 py-3.5 rounded-xl text-base font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color: {{ $isResubmitting ? '#d97706' : '#7a3f91' }};"
                        onmouseover="this.style.backgroundColor='{{ $isResubmitting ? '#b45309' : '#5e2f72' }}'"
                        onmouseout="this.style.backgroundColor='{{ $isResubmitting ? '#d97706' : '#7a3f91' }}'">
                    <span wire:loading wire:target="saveEvent">
                        <i class="fas fa-spinner animate-spin text-sm"></i>
                        @if($isResubmitting) Resubmitting… @elseif($isEditing) Saving… @else Submitting… @endif
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">
                        @if($isResubmitting)
                            <i class="fas fa-rotate-right text-sm"></i> Save &amp; Resubmit
                        @elseif($isEditing)
                            <i class="fas fa-floppy-disk text-sm"></i> Save Changes
                        @else
                            <i class="fas fa-paper-plane text-sm"></i> Submit Event
                        @endif
                    </span>
                </button>
                <button type="button" wire:click="closeFormModal"
                        class="w-full px-5 py-2.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i>Cancel
                </button>
            </div>
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
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- ── Header Bar ── --}}
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
            @if($isApproved)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i>
                    <span class="hidden sm:inline">Share</span>
                </button>
            @elseif($isCompleted)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-400/20 hover:bg-amber-400/30 border border-amber-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-trophy text-xs"></i>
                    <span class="hidden sm:inline">Highlights</span>
                </button>
            @endif
            @if(!$isCompleted && !$isApproved)
                <button wire:click="openEditModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs"></i>
                    <span class="hidden sm:inline">Edit</span>
                </button>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- ── Two-column Body ── --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- ═══ LEFT: Photo + Meta Details ═══ --}}
        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            @if($hasPhoto)
            <div class="w-full px-4 pt-4 pb-2 shrink-0 flex items-center justify-center relative">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         class="w-full object-contain"
                         style="max-height: 190px; display:block;">
                    <div class="absolute top-2 right-2">
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Completed</span>
                        @elseif($isApproved)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Approved</span>
                        @elseif($isPending)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-hourglass-half text-[9px]"></i> Pending</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-xmark text-[9px]"></i> Rejected</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:90px; background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-semibold">Completed</span>
                    @elseif($isApproved)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Approved</span>
                    @elseif($isPending)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-semibold">Pending</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-semibold">Rejected</span>
                    @endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100">
                        <i class="fas fa-calendar text-violet-600 text-base"></i>
                    </span>
                    <div>
                        <p class="meta-label">Date &amp; Time</p>
                        <p class="meta-value">{{ $eventDatePH->format('F d, Y') }}</p>
                        <p class="meta-sub">{{ $timeDisplay }}</p>
                    </div>
                </div>

                @if($ev->venue)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100">
                        <i class="fas fa-location-dot text-rose-600 text-base"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="meta-label">Venue</p>
                        <p class="meta-value truncate">{{ $ev->venue }}</p>
                        @if($ev->venue_address)
                            <p class="meta-sub truncate">{{ $ev->venue_address }}</p>
                        @endif
                    </div>
                </div>
                @endif

                @if($ev->target_participants)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-purple-100">
                        <i class="fas fa-users text-purple-600 text-base"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="meta-label">Open For</p>
                        <p class="meta-value line-clamp-2">{{ $ev->target_participants }}</p>
                    </div>
                </div>
                @endif

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
                    @elseif($isPending)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-amber-800"><i class="fas fa-hourglass-half text-amber-500 text-sm"></i> Awaiting Alumni Director Review</p>
                        <p class="text-sm text-amber-800 mt-0.5">You'll be notified once reviewed.</p>
                    @else
                        <p class="text-sm font-bold flex items-center gap-1.5 text-red-800"><i class="fas fa-circle-xmark text-red-500 text-sm"></i> Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm text-red-800 mt-0.5"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                        <p class="text-sm text-red-800 mt-1 font-semibold">You may edit and resubmit.</p>
                    @endif
                </div>

                <p class="text-xs text-center" style="color:#777777;">
                    Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        {{-- ═══ RIGHT: RSVP Stats + Description + Notes ═══ --}}
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
     SHARE / HIGHLIGHTS — CENTERED MODAL
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

         {{-- ══ FIXED: shareOnMessenger — opens native send-to-friend picker ══ --}}
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.messengerCopied = true;

             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             const encodedUrl = encodeURIComponent(this.baseUrl);

             if (isMobile) {
                 {{-- Deep-link: opens Messenger app with conversation picker --}}
                 window.location.href = 'fb-messenger://share/?link=' + encodedUrl + '&app_id=';
                 {{-- Fallback after 1.5s if app not installed --}}
                 setTimeout(() => {
                     window.open(
                         'https://www.facebook.com/dialog/send?link=' + encodedUrl
                         + '&app_id=291494419107518&redirect_uri=' + encodedUrl,
                         '_blank', 'noopener'
                     );
                 }, 1500);
             } else {
                 {{-- Desktop: opens FB send dialog (conversation picker popup) --}}
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

        {{-- Header --}}
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

        {{-- Body --}}
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Left: Preview --}}
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
                            Clicking <strong>Facebook</strong> copies the caption to clipboard then opens the share dialog.
                            Clicking <strong>Messenger</strong> opens the <strong>conversation picker</strong> so you can
                            forward the event directly to friends or groups — caption is also copied so you can paste it in the chat.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold flex items-center gap-2" style="color:#5e2f72;">
                            Post to Batch Chats
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-700 text-white text-[10px] font-semibold tracking-wide">
                                <i class="fas fa-at text-[9px]"></i>everyone tagged
                            </span>
                        </p>
                        <p class="text-sm mt-0.5 text-purple-700">
                            Sends the event caption with <strong>@everyone</strong> directly to all target batch chat rooms for
                            <strong>{{ $shareEventTarget ?: ($this->organizerDepartment ?: 'your college') }}</strong>.
                            All members will be notified automatically.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Right: Share Actions --}}
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

                {{-- ══ FIXED: Messenger button — correct icon + forward/picker share ══ --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background: linear-gradient(135deg, #0084FF 0%, #0050D0 100%);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        {{-- FIXED: Use FontAwesome Brands messenger icon — no broken SVG path --}}
                        <i class="fab fa-facebook-messenger text-xl" style="background: linear-gradient(135deg,#0084FF,#0050D0); -webkit-background-clip:text; -webkit-text-fill-color:transparent;"></i>
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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post directly</span>
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
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5 flex items-center gap-1.5" style="color:#7a3f91;">
                            Sends to all target batch rooms
                            · <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-white text-[9px] font-semibold" style="background:#7a3f91;">
                                <i class="fas fa-at text-[8px]"></i>everyone
                            </span>
                        </span>
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