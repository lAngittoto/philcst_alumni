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
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED'])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

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
        $this->contact_person = $this->organizerName;
        $this->contact_email  = $this->organizerEmail;
        $this->isEditing      = false;
        $this->showFormModal  = true;
    }

    public function closeNoAlumniModal(): void { $this->showNoAlumniModal = false; }

    public function openEditModal(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->firstOrFail();

        $this->isEditing        = true;
        $this->editingEventId   = $id;
        $this->title            = $event->title;
        $this->description      = $event->description ?? '';
        $this->event_date       = $event->event_date->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->start_time       = $event->event_date->setTimezone('Asia/Manila')->format('g:i A');
        $this->end_time         = $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A') ?? '';
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
        $this->contact_phone  = preg_replace('/[^0-9+\-\s()]/', '', $this->contact_phone);
        $this->notes          = strip_tags(trim($this->notes));

        if ($this->contact_email && !filter_var($this->contact_email, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Please enter a valid email address.';
        }

        if (!trim($this->title))       $errors['title']       = 'Event title is required.';
        if (!trim($this->description)) $errors['description'] = 'Event description is required.';
        if (!trim($this->event_date))  $errors['event_date']  = 'Event date is required.';
        if (!trim($this->venue))       $errors['venue']       = 'Venue / Location is required.';

        if (!trim($this->start_time)) {
            $errors['start_time'] = 'Start time is required.';
        } else {
            try {
                \Carbon\Carbon::parse(trim($this->start_time));
            } catch (\Exception $e) {
                $errors['start_time'] = 'Invalid start time. Use format like "8:00 AM" or "13:00".';
            }
        }

        if (!isset($errors['event_date']) && !isset($errors['start_time'])
            && trim($this->event_date) && trim($this->start_time)) {
            try {
                $proposedStart = \Carbon\Carbon::createFromFormat(
                    'Y-m-d g:i A',
                    trim($this->event_date) . ' ' . trim($this->start_time),
                    'Asia/Manila'
                );
                if ($proposedStart->isPast()) {
                    $errors['event_date'] = 'Event date and start time cannot be in the past. Please choose a future date and time.';
                }
            } catch (\Exception $e) {}
        }

        if (trim($this->end_time)) {
            try {
                $endDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . trim($this->end_time), 'Asia/Manila');
                if (!isset($errors['start_time'])) {
                    $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . trim($this->start_time), 'Asia/Manila');
                    if ($endDt->lte($startDt)) {
                        $errors['end_time'] = 'End time must be after start time.';
                    }
                }
            } catch (\Exception $e) {
                $errors['end_time'] = 'Invalid end time. Use format like "5:00 PM" or "17:00".';
            }
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

        if (trim($this->batchYear) !== '' && !isset($errors['target'])) {
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

        $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $this->start_time, 'Asia/Manila')->utc();
        $endDt   = ($this->event_date && trim($this->end_time))
            ? \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $this->end_time, 'Asia/Manila')->utc()
            : null;

        $data = [
            'title'               => trim($this->title),
            'description'         => trim($this->description),
            'event_date'          => $startDt->format('Y-m-d H:i:s'),
            'event_end_date'      => $endDt ? $endDt->format('Y-m-d H:i:s') : null,
            'venue'               => trim($this->venue),
            'venue_address'       => trim($this->venue_address) ?: null,
            'target_participants' => $targetStr,
            'contact_person'      => trim($this->contact_person) ?: null,
            'contact_email'       => trim($this->contact_email)  ?: null,
            'contact_phone'       => trim($this->contact_phone)  ?: null,
            'notes'               => trim($this->notes)          ?: null,
        ];

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
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }

            try {
                AuditLog::create([
                    'user_id'       => Auth::id(),
                    'user_name'     => Auth::user()?->name ?? 'Organizer',
                    'user_email'    => Auth::user()?->email,
                    'user_role'     => 'organizer',
                    'action'        => 'updated',
                    'module'        => 'event',
                    'subject_id'    => $this->editingEventId,
                    'subject_label' => trim($this->title),
                    'description'   => "Organizer updated event: '" . trim($this->title) . "'.",
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

            $this->dispatch('flash-message', type: 'success', message: 'Event updated successfully!');
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
.tbl-row:hover { background: #f5f5f5; }

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
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
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
                <h1 class="text-2xl font-bold tracking-tight" style="color:#333333;">Event Management</h1>
                <p class="text-sm leading-relaxed mt-0.5 font-normal" style="color:#666666;">
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
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white shadow-md transition cursor-pointer {{ !$this->hasAlumni ? 'opacity-60 cursor-not-allowed' : '' }}"
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
            <p class="font-bold">No verified alumni found for {{ $this->organizerDepartment ?: 'your college' }}.</p>
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
                <table class="w-full min-w-[860px]" style="background-color:#ffffff;">
                    <thead class="sticky top-0 z-10" style="box-shadow: 0 1px 0 #E8E0F0;">
    <tr style="background-color:#ffffff;">
                           <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest text-[#333333] w-10">#</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest text-[#333333]">Event Title</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest text-[#333333] hidden md:table-cell">Date &amp; Time</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest text-[#333333] hidden lg:table-cell">Venue</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest text-[#333333] hidden xl:table-cell">Course</th>
<th class="px-4 py-3.5 text-center text-xs font-bold uppercase tracking-widest text-[#333333] hidden sm:table-cell">RSVP</th>
<th class="px-4 py-3.5 text-center text-xs font-bold uppercase tracking-widest text-[#333333]">Status</th>
<th class="px-4 py-3.5 text-right text-xs font-bold uppercase tracking-widest text-[#333333]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]" style="background-color:#ffffff;">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $canShare    = $isApproved || $isCompleted;
                            $canEdit     = !$isCompleted && !$isApproved;

                            $tp          = $event->target_participants ?? '';
                            $tpParts     = explode(' · Batch ', $tp, 2);
                            $displayCourses = trim($tpParts[0]) ?: ($this->organizerDepartment ?: 'All Courses');
                            $batchDisplay   = !empty($tpParts[1]) ? trim($tpParts[1]) : null;

                            $eventDate  = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum     = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors" style="background-color:#ffffff;">

                            {{-- # --}}
                            <td class="px-4 py-4 text-sm font-semibold text-[#c0a0d8] text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            {{-- Event Title --}}
                            <td class="px-4 py-4">
                                <div class="max-w-[240px]">
                                    <p class="font-bold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</p>
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

{{-- ── RSVP — all 3 counts + hover tooltip ── --}}
<td class="px-4 py-4 hidden sm:table-cell">
    <div class="flex items-center justify-center cursor-default select-none"
         x-data="{
             show: false,
             top: 0,
             left: 0,
             open(el) {
                 const r = el.getBoundingClientRect();
                 this.top  = r.top + window.scrollY - 8;
                 this.left = r.left + r.width / 2 + window.scrollX;
                 this.show = true;
             }
         }"
         @mouseenter="open($el)"
         @mouseleave="show = false">

        {{-- Compact pill display --}}
        <div class="flex items-center gap-2.5">
            <div class="flex items-center gap-1">
                <i class="fas fa-circle-check text-[11px] text-emerald-500"></i>
                <span class="text-xs font-bold" style="color:#333333;">{{ $event->confirmed_count }}</span>
            </div>
            <span class="text-[#dddddd] text-xs">|</span>
            <div class="flex items-center gap-1">
                <i class="fas fa-circle-xmark text-[11px] text-red-400"></i>
                <span class="text-xs font-bold" style="color:#333333;">{{ $event->declined_count }}</span>
            </div>
            <span class="text-[#dddddd] text-xs">|</span>
            <div class="flex items-center gap-1">
                <i class="fas fa-circle-question text-[11px] text-amber-400"></i>
                <span class="text-xs font-bold" style="color:#333333;">{{ $event->tentative_count }}</span>
            </div>
        </div>

        {{-- Teleported tooltip — renders on <body>, never clipped --}}
        <template x-teleport="body">
            <div x-show="show"
                 x-cloak
                 :style="`position:absolute; top:${top}px; left:${left}px;
                          transform: translateX(-50%) translateY(-100%);
                          z-index: 9999; pointer-events: none;`"
                 style="display:none;">
                <div class="bg-white border border-gray-200 rounded-xl shadow-2xl px-4 py-3 min-w-[180px]">
                    <div class="flex items-center gap-2 mb-1.5">
                        <i class="fas fa-circle-check text-emerald-500 text-xs w-3.5 text-center flex-shrink-0"></i>
                        <span class="text-xs font-semibold text-emerald-700">Attending</span>
                        <span class="ml-auto text-xs font-bold" style="color:#333333;">{{ $event->confirmed_count }}</span>
                    </div>
                    <div class="flex items-center gap-2 mb-1.5">
                        <i class="fas fa-circle-xmark text-red-400 text-xs w-3.5 text-center flex-shrink-0"></i>
                        <span class="text-xs font-semibold text-red-600">Not Attending</span>
                        <span class="ml-auto text-xs font-bold" style="color:#333333;">{{ $event->declined_count }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-circle-question text-amber-400 text-xs w-3.5 text-center flex-shrink-0"></i>
                        <span class="text-xs font-semibold text-amber-600">Tentative</span>
                        <span class="ml-auto text-xs font-bold" style="color:#333333;">{{ $event->tentative_count }}</span>
                    </div>
                    @php $totalRsvpRow = $event->confirmed_count + $event->declined_count + $event->tentative_count; @endphp
                    <div class="mt-2 pt-2 border-t border-gray-100 text-[10px] text-center font-semibold" style="color:#999999;">
                        {{ $totalRsvpRow }} total {{ $totalRsvpRow === 1 ? 'response' : 'responses' }}
                    </div>
                    {{-- Arrow --}}
                    <div class="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0"
                         style="border-left:6px solid transparent; border-right:6px solid transparent; border-top:6px solid #e5e7eb;"></div>
                    <div class="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0 -mt-px"
                         style="border-left:5px solid transparent; border-right:5px solid transparent; border-top:5px solid #ffffff; z-index:1;"></div>
                </div>
            </div>
        </template>
    </div>
</td>

                            {{-- Status --}}
                            <td class="px-4 py-4 text-center">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-yellow-200 bg-yellow-50 text-yellow-700 whitespace-nowrap">
                                        Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        Rejected
                                    </span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-4">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">

                                    {{-- View --}}
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share / Highlights --}}
                                    @if($isApproved)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @elseif($isCompleted)
                                        <button type="button" wire:click.stop="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-amber-100 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trophy text-xs"></i>
                                            <span class="hidden xl:inline">Highlights</span>
                                        </button>
                                    @endif

                                    {{-- Edit / Delete --}}
                                    @if($canEdit)
                                        <button type="button" wire:click.stop="openEditModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>
                                        <button type="button" wire:click.stop="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-red-100 text-red-700 border border-red-300 hover:bg-white hover:border-red-500 transition cursor-pointer whitespace-nowrap">
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
                <p class="font-bold text-lg" style="color:#333333;">
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

    </div>{{-- end table section --}}

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

</div>{{-- end main layout --}}


{{-- ══════════════════════════════════════════════════════════════════════════
     NO ALUMNI MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showNoAlumniModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeNoAlumniModal">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">
        <div class="px-6 py-5 bg-amber-50 border-b border-amber-100">
            <h2 class="text-lg font-extrabold text-amber-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center"><i class="fas fa-triangle-exclamation text-amber-500 text-base"></i></div>
                Cannot Post Event
            </h2>
        </div>
        <div class="p-6" style="background-color:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">No verified alumni found for:</p>
            <p class="font-extrabold text-amber-700 text-lg mb-4">{{ $this->organizerDepartment ?: 'Your College' }}</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 text-sm flex items-start gap-2" style="color:#666666;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>You cannot create an event until at least one verified alumni is registered under your college. Please contact the admin if this seems incorrect.</span>
            </div>
            <button wire:click="closeNoAlumniModal"
                    class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold hover:bg-gray-50 transition" style="color:#666666;">
                Close
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     CREATE / EDIT MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeFormModal">
    <div class="rounded-2xl shadow-2xl w-full max-w-2xl max-h-[95vh] sm:max-h-[92vh] flex flex-col overflow-hidden m-in"
         style="background-color:#ffffff;"
         x-data="{}"
         x-effect="if($wire.formErrors && Object.keys($wire.formErrors).length>0){$nextTick(()=>{const el=$refs.formScroll;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

        <button wire:click="closeFormModal" type="button"
                class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-white/20 transition text-white cursor-pointer z-10">
            <i class="fas fa-xmark text-lg"></i>
        </button>

        <div class="flex items-center px-7 py-5 flex-shrink-0" style="background:#7a3f91;">
            <h2 class="text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas {{ $isEditing ? 'fa-pen-to-square' : 'fa-calendar-plus' }}"></i>
                {{ $isEditing ? 'Edit Event' : 'Submit a New Event' }}
            </h2>
        </div>

        @if(!$isEditing)
        <div class="bg-blue-50 border-b border-blue-200 px-7 py-3 flex-shrink-0 flex items-center gap-2.5">
            <i class="fas fa-info-circle text-blue-500 flex-shrink-0"></i>
            <p class="text-sm text-blue-800">Event will be submitted for admin review before publishing to alumni.</p>
        </div>
        @endif

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-c px-7 py-6 space-y-5" x-ref="formScroll" style="background-color:#ffffff;">

            {{-- Photo Upload --}}
            <div>
                <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">
                    Event Photo <span class="font-normal normal-case tracking-normal" style="color:#999999;">(Optional)</span>
                </label>
                <div x-data="{isDragging:false}"
                     @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                     class="border-2 rounded-xl p-5 text-center cursor-pointer transition-all"
                     :class="isDragging?'border-purple-400 bg-purple-50':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-purple-400 border-solid bg-purple-50/50':'border-dashed border-gray-300 hover:border-purple-400 hover:bg-purple-50/30' }}'">
                    <label class="cursor-pointer block">
                        <input type="file" wire:model="photo" accept="image/*" class="hidden">
                        @if($photo)
                            <div class="flex flex-col items-center gap-2">
                                <img src="{{ $photo->temporaryUrl() }}" class="w-28 h-20 object-cover rounded-xl shadow border border-purple-200">
                                <p class="text-sm font-semibold text-purple-600"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
                            </div>
                        @elseif($existingPhotoUrl&&!$removePhoto)
                            <div class="flex flex-col items-center gap-2">
                                <img src="{{ $existingPhotoUrl }}" class="w-28 h-20 object-cover rounded-xl shadow border border-gray-200">
                                <p class="text-sm font-semibold" style="color:#666666;">Current photo — click to change</p>
                            </div>
                        @else
                            <div class="flex flex-col items-center gap-2 py-2">
                                <i class="fas fa-cloud-arrow-up text-4xl text-gray-300"></i>
                                <p class="font-semibold text-sm" style="color:#666666;">Click to upload or drag &amp; drop</p>
                                <p class="text-xs" style="color:#999999;">JPG, PNG, WEBP — max 5MB · Default photo used if blank</p>
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
                <div wire:loading wire:target="photo" class="mt-2 text-sm text-purple-700 flex items-center gap-2">
                    <i class="fas fa-spinner animate-spin"></i> Uploading…
                </div>
            </div>

            {{-- Event Details --}}
            <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 px-5 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-circle-info text-sm" style="color:#7a3f91;"></i>
                    <span class="text-base font-bold" style="color:#333333;">Event Details</span>
                </div>
                <div class="p-5 space-y-4" style="background-color:#ffffff;">
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Event Title <span class="text-red-500">*</span></label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['title'])?'border-red-400 bg-red-50':'' }}"
                               style="color:#333333; background-color:#ffffff;">
                        @if(isset($formErrors['title']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Description <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="description" rows="4" placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition resize-none {{ isset($formErrors['description'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333; background-color:#ffffff;"></textarea>
                        @if(isset($formErrors['description']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['description'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Event Date <span class="text-red-500">*</span></label>
                        <input wire:model="event_date" type="date" min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['event_date'])?'border-red-400 bg-red-50':'' }}"
                               style="color:#333333; background-color:#ffffff;">
                        @if(isset($formErrors['event_date']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['event_date'] }}</p>@endif
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Start Time <span class="text-red-500">*</span></label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['start_time'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333; background-color:#ffffff;">
                            @if(isset($formErrors['start_time']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">End Time <span class="font-normal" style="color:#999999;">(Optional)</span></label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['end_time'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333; background-color:#ffffff;">
                            @if(isset($formErrors['end_time']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['end_time'] }}</p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Venue / Location <span class="text-red-500">*</span></label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['venue'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333; background-color:#ffffff;">
                            @if(isset($formErrors['venue']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['venue'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Full Address <span class="font-normal" style="color:#999999;">(Optional)</span></label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Old Nalsian Road, Calasiao" maxlength="200"
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition"
                                   style="color:#333333; background-color:#ffffff;">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Courses / Programs --}}
            <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 px-5 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-book text-sm" style="color:#7a3f91;"></i>
                    <span class="text-base font-bold" style="color:#333333;">Courses / Programs</span>
                </div>
                <div class="p-5 space-y-4" style="background-color:#ffffff;">
                    <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-4 py-3">
                        <i class="fas fa-building-columns text-purple-500 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-bold text-purple-800">{{ $this->organizerDepartment ?: 'Your College' }}</div>
                            <div class="text-xs text-purple-700 mt-0.5">Select specific courses or leave unchecked for all courses.</div>
                        </div>
                    </div>

                    @if(count($this->availableCourses) > 0)
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-bold uppercase tracking-wider" style="color:#666666;">Available Courses</span>
                                <div class="flex gap-3">
                                    <button type="button" wire:click="$set('selectedCourses', {{ json_encode($this->availableCourses) }})"
                                            class="text-sm font-bold hover:underline" style="color:#7a3f91;">
                                        <i class="fas fa-check-double mr-1"></i>Select All
                                    </button>
                                    @if(count($selectedCourses) > 0)
                                        <button type="button" wire:click="$set('selectedCourses', [])"
                                                class="text-sm font-bold hover:text-red-600" style="color:#999999;">Clear</button>
                                    @endif
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach($this->availableCourses as $course)
                                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer transition text-sm font-semibold {{ in_array($course, $selectedCourses) ? 'border-purple-400 bg-purple-50 text-purple-700' : 'border-gray-200 text-gray-600 hover:border-purple-300 hover:bg-purple-50/40' }}"
                                           style="background-color:{{ in_array($course, $selectedCourses) ? '' : '#ffffff' }};">
                                        <input type="checkbox" wire:model.live="selectedCourses" value="{{ $course }}" class="accent-purple-600 w-4 h-4">
                                        <span>{{ $course }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="text-center py-4 text-sm" style="color:#999999;">
                            <i class="fas fa-inbox text-3xl block mb-2 text-gray-200"></i>
                            No courses available for your college yet.
                        </div>
                    @endif

                    <div class="pt-3 border-t border-gray-100">
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">
                            Batch Year <span class="font-normal" style="color:#999999;">(Optional)</span>
                        </label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}" placeholder="e.g. {{ now()->year - 2 }}"
                               class="w-full sm:max-w-xs px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['batch_year'])?'border-red-400 bg-red-50':'' }}"
                               style="color:#333333; background-color:#ffffff;">
                        @if(isset($formErrors['batch_year']))
                            <p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['batch_year'] }}</p>
                        @else
                            <p class="text-sm mt-1" style="color:#999999;"><i class="fas fa-circle-info mr-1"></i>Leave blank to target all batches.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Contact Person --}}
            <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 px-5 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-address-card text-sm" style="color:#7a3f91;"></i>
                    <span class="text-base font-bold" style="color:#333333;">Contact Person</span>
                    <span class="text-xs ml-1" style="color:#999999;">— pre-filled from your account</span>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4" style="background-color:#ffffff;">
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Name</label>
                        <input wire:model.defer="contact_person" type="text" placeholder="Full name"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition"
                               style="color:#333333; background-color:#ffffff;">
                    </div>
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Email</label>
                        <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($formErrors['contact_email'])?'border-red-400 bg-red-50':'' }}"
                               style="color:#333333; background-color:#ffffff;">
                        @if(isset($formErrors['contact_email']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $formErrors['contact_email'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Phone <span class="font-normal" style="color:#999999;">(Optional)</span></label>
                        <input wire:model.defer="contact_phone" type="text" placeholder="+63 9XX XXX XXXX"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition"
                               style="color:#333333; background-color:#ffffff;">
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-bold uppercase mb-2 tracking-wider" style="color:#333333;">Additional Notes / Requirements <span class="font-normal" style="color:#999999;">(Optional)</span></label>
                <textarea wire:model.defer="notes" rows="4" placeholder="Dress code, special instructions…" maxlength="3000"
                          class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-base focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition resize-none"
                          style="color:#333333; background-color:#ffffff;"></textarea>
            </div>

        </div>

        <div class="px-7 py-5 border-t border-gray-100 flex-shrink-0 flex gap-3" style="background-color:#ffffff;">
            <button wire:click="closeFormModal" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-base font-bold hover:bg-gray-50 transition cursor-pointer" style="color:#333333; background-color:#ffffff;">Cancel</button>
            <button wire:click="saveEvent" wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-4 py-3 text-white rounded-xl text-base font-extrabold disabled:opacity-50 transition shadow-md flex items-center justify-center gap-2 cursor-pointer"
                    style="background:#7a3f91;">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner animate-spin"></i> {{ $isEditing ? 'Saving…' : 'Submitting…' }}</span>
                <span wire:loading.remove wire:target="saveEvent">
                    <i class="fas {{ $isEditing ? 'fa-floppy-disk' : 'fa-paper-plane' }}"></i>
                    {{ $isEditing ? 'Save Changes' : 'Submit Event' }}
                </span>
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW MODAL
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
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeViewModal">
    <div class="rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden"
         style="background-color:#ffffff;">

        {{-- Close button --}}
        <button wire:click="closeViewModal" type="button"
                class="absolute top-3 right-3 w-8 h-8 flex items-center justify-center rounded-full bg-black/40 hover:bg-black/60 transition text-white z-20 cursor-pointer">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>

        {{-- EVENT PHOTO --}}
        <div class="w-full h-64 flex-shrink-0 overflow-hidden relative">
            <img src="{{ $ev->photo_url }}"
                 alt="{{ $ev->title }}"
                 class="w-full h-full object-cover">
            <div class="absolute bottom-3 left-4 flex items-center gap-2 flex-wrap">
                @if($isCompleted)
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full bg-green-600/90 text-white backdrop-blur-sm">
                        <i class="fas fa-circle-check text-[10px]"></i> Completed
                    </span>
                @elseif($isApproved)
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full bg-emerald-600/90 text-white backdrop-blur-sm">
                        <i class="fas fa-calendar-check text-[10px]"></i> Approved
                    </span>
                @elseif($isPending)
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full bg-yellow-500/90 text-white backdrop-blur-sm">
                        <i class="fas fa-hourglass-half text-[10px]"></i> Pending Review
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full bg-red-600/90 text-white backdrop-blur-sm">
                        <i class="fas fa-circle-xmark text-[10px]"></i> Rejected
                    </span>
                @endif
            </div>
        </div>

        {{-- WHITE HEADER --}}
        <div class="px-6 py-4 border-b border-gray-100 flex-shrink-0" style="background-color:#ffffff;">
            <h2 class="text-xl font-bold leading-snug mb-3" style="color:#333333;">{{ $ev->title }}</h2>
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-purple-50 border border-purple-100" style="color:#333333;">
                    <i class="fas fa-calendar text-xs" style="color:#7a3f91;"></i>
                    {{ $eventDatePH->format('M d, Y') }}
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-purple-50 border border-purple-100" style="color:#333333;">
                    <i class="fas fa-clock text-xs" style="color:#7a3f91;"></i>
                    {{ $timeDisplay }}
                </span>
                @if($ev->venue)
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-purple-50 border border-purple-100" style="color:#333333;">
                    <i class="fas fa-location-dot text-xs" style="color:#7a3f91;"></i>
                    {{ $ev->venue }}
                </span>
                @endif
            </div>
        </div>

        {{-- SCROLLABLE BODY --}}
        <div class="flex-1 min-h-0 overflow-y-auto scroll-c" style="background-color:#ffffff;">

            {{-- RSVP --}}
            <div class="px-6 py-5 border-b border-gray-100" style="background-color:#f9f9f9;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-users text-xs" style="color:#7a3f91;"></i>
                    Attendee Responses
                </h3>
                <div class="grid grid-cols-3 gap-3">
                    <div class="border border-gray-200 rounded-xl p-3 text-center hover:border-purple-300 hover:shadow-sm transition"
                         style="background-color:#ffffff;"
                         title="Alumni who confirmed they will attend the event">
                        <i class="fas fa-circle-check mb-1.5 text-lg text-emerald-500"></i>
                        <p class="text-2xl font-bold" style="color:#333333;">{{ $ev->confirmed_count }}</p>
                        <p class="text-xs font-semibold mt-0.5 text-emerald-600">Attending</p>
                        <p class="text-[10px] mt-0.5" style="color:#999999;">Confirmed</p>
                    </div>
                    <div class="border border-gray-200 rounded-xl p-3 text-center hover:border-purple-300 hover:shadow-sm transition"
                         style="background-color:#ffffff;"
                         title="Alumni who responded they will not attend the event">
                        <i class="fas fa-circle-xmark mb-1.5 text-lg text-red-400"></i>
                        <p class="text-2xl font-bold" style="color:#333333;">{{ $ev->declined_count }}</p>
                        <p class="text-xs font-semibold mt-0.5 text-red-500">Not Attending</p>
                        <p class="text-[10px] mt-0.5" style="color:#999999;">Declined</p>
                    </div>
                    <div class="border border-gray-200 rounded-xl p-3 text-center hover:border-purple-300 hover:shadow-sm transition"
                         style="background-color:#ffffff;"
                         title="Alumni who are uncertain and may or may not attend the event">
                        <i class="fas fa-circle-question mb-1.5 text-lg text-amber-400"></i>
                        <p class="text-2xl font-bold" style="color:#333333;">{{ $ev->tentative_count }}</p>
                        <p class="text-xs font-semibold mt-0.5 text-amber-500">Maybe</p>
                        <p class="text-[10px] mt-0.5" style="color:#999999;">Tentative</p>
                    </div>
                </div>
                @if($totalRsvp === 0)
                    <p class="text-xs text-center mt-3" style="color:#999999;">
                        <i class="fas fa-inbox mr-1"></i> No responses received yet.
                    </p>
                @else
                    <p class="text-xs text-center mt-3 font-semibold" style="color:#999999;">
                        {{ $totalRsvp }} total {{ $totalRsvp === 1 ? 'response' : 'responses' }}
                    </p>
                @endif
            </div>

            {{-- Status detail --}}
            <div class="px-6 py-5 border-b border-gray-100" style="background-color:#ffffff;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-shield-halved text-xs" style="color:#7a3f91;"></i>
                    Review Status
                </h3>
                @if($isCompleted)
                    <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                        <i class="fas fa-circle-check text-green-600 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-sm font-bold text-green-800">Event Completed</p>
                            <p class="text-sm text-green-700 mt-0.5">This event has already taken place. Thank you for a successful event!</p>
                        </div>
                    </div>
                @elseif($isApproved)
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                        <i class="fas fa-circle-check text-emerald-600 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-sm font-bold text-emerald-800">Approved — Now Live</p>
                            @if($ev->reviewed_at)<p class="text-xs text-emerald-700 mt-0.5">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                            @if($ev->review_remarks)<p class="text-sm text-emerald-700 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                        </div>
                    </div>
                @elseif($isPending)
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                        <i class="fas fa-hourglass-half text-yellow-600 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-sm font-bold text-yellow-800">Awaiting Admin Review</p>
                            <p class="text-sm text-yellow-700 mt-0.5">Your event is pending approval. You will be notified once it has been reviewed.</p>
                        </div>
                    </div>
                @else
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-2.5">
                        <i class="fas fa-circle-xmark text-red-600 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-sm font-bold text-red-800">Rejected by Administrator</p>
                            @if($ev->review_remarks)<p class="text-sm text-red-700 mt-1"><span class="font-semibold">Reason:</span> {{ $ev->review_remarks }}</p>@endif
                            <p class="text-sm text-red-700 mt-1 font-semibold">You may edit and resubmit this event.</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Location --}}
            @if($ev->venue || $ev->venue_address)
            <div class="px-6 py-4 border-b border-gray-100" style="background-color:#ffffff;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-location-dot text-xs" style="color:#7a3f91;"></i>
                    Location
                </h3>
                <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->venue }}</p>
                @if($ev->venue_address)<p class="text-xs mt-0.5" style="color:#666666;">{{ $ev->venue_address }}</p>@endif
            </div>
            @endif

            {{-- Description --}}
            @if($ev->description)
            <div class="px-6 py-4 border-b border-gray-100" style="background-color:#ffffff;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-file-lines text-xs" style="color:#7a3f91;"></i>
                    About This Event
                </h3>
                <div class="text-sm leading-relaxed whitespace-pre-wrap rounded-xl p-4 border border-gray-100" style="color:#333333; background-color:#f9f9f9;">{{ $ev->description }}</div>
            </div>
            @endif

            {{-- Notes --}}
            @if($ev->notes)
            <div class="px-6 py-4 border-b border-gray-100" style="background-color:#ffffff;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-list-check text-xs" style="color:#7a3f91;"></i>
                    Additional Notes
                </h3>
                <div class="text-sm leading-relaxed whitespace-pre-wrap rounded-xl p-4 border border-gray-100" style="color:#333333; background-color:#f9f9f9;">{{ $ev->notes }}</div>
            </div>
            @endif

            {{-- Contact --}}
            @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
            <div class="px-6 py-4 border-b border-gray-100" style="background-color:#ffffff;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#7a3f91;">
                    <i class="fas fa-address-card text-xs" style="color:#7a3f91;"></i>
                    Contact Information
                </h3>
                <div class="space-y-2 text-sm" style="color:#333333;">
                    @if($ev->contact_person)
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-user text-xs w-4 text-center" style="color:#7a3f91;"></i>
                        <span class="font-semibold">{{ $ev->contact_person }}</span>
                    </div>
                    @endif
                    @if($ev->contact_email)
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-envelope text-xs w-4 text-center" style="color:#7a3f91;"></i>
                        <span>{{ $ev->contact_email }}</span>
                    </div>
                    @endif
                    @if($ev->contact_phone)
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-phone text-xs w-4 text-center" style="color:#7a3f91;"></i>
                        <span>{{ $ev->contact_phone }}</span>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Meta --}}
            <div class="px-6 py-4" style="background-color:#ffffff;">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest mb-1" style="color:#7a3f91;">Date Posted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $createdPH->format('M d, Y') }}</p>
                        <p class="text-xs mt-0.5" style="color:#666666;">{{ $createdPH->diffForHumans() }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest mb-1" style="color:#7a3f91;">Target Participants</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->target_participants ?? '—' }}</p>
                    </div>
                </div>
            </div>

        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-gray-100 flex-shrink-0 flex items-center justify-end gap-2 flex-wrap" style="background-color:#ffffff;">
            <button wire:click="closeViewModal" type="button"
                    class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-bold hover:bg-gray-50 transition cursor-pointer" style="color:#333333; background-color:#ffffff;">
                <i class="fas fa-xmark text-xs mr-1"></i> Close
            </button>
            @if($isApproved)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="px-4 py-2.5 bg-sky-100 text-sky-700 border border-sky-300 rounded-lg text-sm font-bold hover:bg-white hover:border-sky-500 transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs mr-1.5"></i> Share
                </button>
            @elseif($isCompleted)
                <button type="button" wire:click="openShareModal({{ $ev->id }})"
                        class="px-4 py-2.5 bg-amber-100 text-amber-700 border border-amber-300 rounded-lg text-sm font-bold hover:bg-white hover:border-amber-500 transition cursor-pointer">
                    <i class="fas fa-trophy text-xs mr-1.5"></i> Share Highlights
                </button>
            @endif
            @if(!$isCompleted && !$isApproved)
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="px-4 py-2.5 bg-red-100 text-red-700 border border-red-300 rounded-lg text-sm font-bold hover:bg-white hover:border-red-500 transition cursor-pointer">
                    <i class="fas fa-trash text-xs mr-1.5"></i> Delete
                </button>
                <button wire:click="openEditModal({{ $ev->id }})" type="button"
                        class="px-4 py-2.5 bg-blue-100 text-blue-700 border border-blue-300 rounded-lg text-sm font-bold hover:bg-white hover:border-blue-500 transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs mr-1.5"></i> Edit
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
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDelete">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">
        <div class="px-6 py-5 bg-red-50 border-b border-red-200">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center"><i class="fas fa-triangle-exclamation text-red-600 text-base"></i></div>
                Delete Event
            </h2>
        </div>
        <div class="p-6" style="background-color:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">You are about to delete:</p>
            <p class="font-extrabold text-red-700 text-lg mb-4">"{{ $deleteEventTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 text-sm flex items-start gap-2" style="color:#666666;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>This event will be removed from your list. <strong>Admin can still see and restore it</strong> if needed.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-bold hover:bg-gray-50 transition cursor-pointer" style="color:#333333; background-color:#ffffff;">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition shadow-md cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
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
    $fbShareUrl     = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);
    $isCompleted    = $shareEventStatus === 'COMPLETED';
    $timeDisplay    = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $descPreview    = mb_strlen($shareEventDescription) > 140
        ? mb_substr($shareEventDescription, 0, 140) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($timeDisplay ? " · {$timeDisplay}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($timeDisplay ? " · {$timeDisplay}" : '');
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
    $fbLines[]  = $isCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);
@endphp

<div class="fixed inset-0 z-[70] flex items-center justify-center p-3 sm:p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape="closeShareModal"
     x-data="{
         copied:false, fbCopied:false, messengerCopied:false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},
         fbUrl:   {{ json_encode($fbShareUrl) }},
         shareOnFacebook() {
             navigator.clipboard.writeText(this.fbText).then(() => {
                 this.fbCopied = true; setTimeout(() => this.fbCopied = false, 6000);
             }).catch(() => {});
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open(this.fbUrl,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
         },
         shareOnMessenger() {
             navigator.clipboard.writeText(this.fbText).then(() => {
                 this.messengerCopied = true; setTimeout(() => this.messengerCopied = false, 6000);
             }).catch(() => {});
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/','_blank'), 1500);
             } else {
                 window.open('https://www.messenger.com/','_blank');
             }
         },
         copyLinkFn() {
             navigator.clipboard.writeText(this.baseUrl).then(() => {
                 this.copied = true; setTimeout(() => this.copied = false, 2500);
             });
         }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">

    <div class="rounded-2xl shadow-2xl w-full max-w-6xl overflow-hidden m-in"
         style="background-color:#ffffff;"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100" style="background-color:#ffffff;">
            <h2 class="text-lg font-bold flex items-center gap-2" style="color:#333333;">
                @if($isCompleted)
                    <i class="fas fa-trophy text-amber-500"></i> Share Event Highlights
                @else
                    <i class="fas fa-share-nodes text-sky-600"></i> Share Event
                @endif
            </h2>
            <button wire:click="closeShareModal" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#999999;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Body: two columns --}}
        <div class="flex flex-col lg:flex-row" style="background-color:#ffffff;">

            {{-- LEFT: Preview --}}
            <div class="flex-1 px-6 py-5 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col gap-4" style="background-color:#ffffff;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#999999;">What recipients will see</p>
                <div class="rounded-xl border border-gray-200 overflow-hidden shadow-sm" style="background-color:#ffffff;">
                    <div class="border-b border-gray-200 px-4 py-3 flex items-start gap-3"
                         style="background-color: {{ $isCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <div class="w-14 h-14 rounded-lg flex items-center justify-center flex-shrink-0 shadow"
                             style="background: {{ $isCompleted ? 'linear-gradient(135deg,#f59e0b,#d97706)' : 'linear-gradient(135deg,#7a3f91,#6a3080)' }};">
                            <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-calendar-check' }} text-white text-xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-sm leading-tight truncate" style="color:#333333;">{{ $shareEventTitle }}</p>
                            <p class="text-xs mt-0.5 font-semibold" style="color:#666666;">{{ $shareEventDate }}@if($timeDisplay) · {{ $timeDisplay }}@endif</p>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                @if($shareEventVenue)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200" style="color:#333333;"><i class="fas fa-location-dot text-[8px]"></i>{{ $shareEventVenue }}</span>@endif
                                @if($shareEventTarget)<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100" style="color:#7a3f91;"><i class="fas fa-users text-[8px]"></i>{{ Str::limit($shareEventTarget, 24) }}</span>@endif
                            </div>
                        </div>
                    </div>
                    @if($descPreview)
                    <div class="px-4 py-2.5 border-b border-gray-100" style="background-color:#ffffff;">
                        <p class="text-xs leading-relaxed line-clamp-3" style="color:#666666;">{{ $descPreview }}</p>
                    </div>
                    @endif
                    <div class="px-4 py-2 flex items-center gap-2" style="background-color:#f9f7fc;">
                        <i class="fas fa-globe text-[10px]" style="color:#999999;"></i>
                        <span class="text-[10px] uppercase tracking-wide font-semibold" style="color:#666666;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 flex items-start gap-2.5">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-800 leading-snug">
                        <strong>How it works:</strong> Click a share button — the full text is automatically copied to your clipboard and the platform opens. Just paste
                        (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>) in your post or message!
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full lg:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0" style="background-color:#ffffff;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#999999;">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-bold text-emerald-800">Text copied! I-paste sa Facebook popup.</p>
                </div>
                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-bold text-blue-800">Text copied! I-paste sa Messenger.</p>
                </div>

                {{-- Facebook --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform" style="background-color:#ffffff;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left text-sm">
                        <span x-show="!fbCopied">Share on Facebook</span>
                        <span x-show="fbCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste sa FB popup!</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                </button>

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform" style="background-color:#ffffff;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_ev" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_ev)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left text-sm">
                        <span x-show="!messengerCopied">Share via Messenger</span>
                        <span x-show="messengerCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste sa Messenger!</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                </button>
                <p class="text-[10px] text-center -mt-1" style="color:#999999;">
                    <i class="fas fa-users text-[9px] mr-0.5"></i> Works for private chats &amp; group chats.
                </p>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-2 text-[10px] font-bold uppercase tracking-widest" style="color:#999999; background-color:#ffffff;">or post directly</span>
                    </div>
                </div>

                {{-- Post to Batch Chats --}}
                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-purple-300 hover:border-purple-400"
                        style="color:#7a3f91; background-color:#f5f0fa;">
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat">
                            {{ $isCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat"><i class="fas fa-spinner fa-spin mr-1"></i> Posting…</span>
                        <span class="block text-xs font-semibold mt-0.5" style="color:#7a3f91;">Sends to all target batch rooms</span>
                    </span>
                    <i class="fas fa-paper-plane text-xs transition" style="color:#7a3f91;"></i>
                </button>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-2 text-[10px] font-bold uppercase tracking-widest" style="color:#999999; background-color:#ffffff;">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-bold text-sm transition cursor-pointer group" style="color:#333333; background-color:#ffffff;">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-sm" style="color:#999999;"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p :class="copied ? 'text-emerald-600' : ''" class="font-bold text-sm"
                           style="color:#333333;"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-[10px] font-mono mt-0.5 truncate" style="color:#999999;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

            </div>
        </div>
    </div>
</div>
@endif

</div>