{{-- resources/views/livewire/admin/events.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\AdminEvent;
use App\Models\AuditLog;
use App\Http\Controllers\AdminEventController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;
use App\Models\Organizer;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    // ── Filters ──────────────────────────────────────────────────────────────
    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSort    = 'recent';
    public string $filterCollege = '';

    // ── Form Modal (Edit only — admin does not CREATE events here) ───────────
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

    public array  $formErrors = [];

    // ── View Modal ────────────────────────────────────────────────────────────
    public bool  $showViewModal  = false;
    public ?int  $viewingEventId = null;

    // ── Approve Modal ─────────────────────────────────────────────────────────
    public bool   $showApproveModal  = false;
    public ?int   $approveEventId    = null;
    public string $approveEventTitle = '';
    public string $approveRemarks    = '';

    // ── Reject Modal ──────────────────────────────────────────────────────────
    public bool   $showRejectModal   = false;
    public ?int   $rejectEventId     = null;
    public string $rejectEventTitle  = '';
    public string $rejectRemarks     = '';

    // ── Delete Modal ──────────────────────────────────────────────────────────
    public bool   $showDeleteModal   = false;
    public ?int   $deleteEventId     = null;
    public string $deleteEventTitle  = '';

    // ── Restore Modal ─────────────────────────────────────────────────────────
    public bool   $showRestoreModal   = false;
    public ?int   $restoreEventId     = null;
    public string $restoreEventTitle  = '';

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
    // MOUNT
    // ─────────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        set_time_limit(600);
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);

        // Throttled auto-processing — runs at most once per 60 s
        if (! Cache::has('admin_events_auto_processed')) {
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
            Cache::put('admin_events_auto_processed', true, 60);
        }
    }

    // ── Auto-processing ───────────────────────────────────────────────────────

    private function autoRejectExpiredPendingEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');
        AdminEvent::withoutTrashed()
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->update([
                'status'         => 'REJECTED',
                'review_remarks' => 'Auto-rejected: event date has already passed without admin approval.',
            ]);
    }

    private function autoCompleteExpiredEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');
        AdminEvent::withoutTrashed()
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($now) {
                $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '<=', $now))
                  ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '<=', $now));
            })
            ->update(['status' => 'COMPLETED']);
    }

    // ── Filter watchers ───────────────────────────────────────────────────────

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

    // ── Computed ──────────────────────────────────────────────────────────────

    #[Computed]
    public function events()
    {
        $q = AdminEvent::withTrashed()
            ->with(['organizer:id,name,department,email'])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])
            ->select([
                'id','title','description','event_date','event_end_date',
                'venue','venue_address','contact_person','contact_email',
                'contact_phone','notes','photo','status','target_participants',
                'organizer_id','review_remarks','reviewed_at',
                'updated_by','updated_by_role','deleted_by','deleted_by_role',
                'created_at','updated_at','deleted_at',
            ]);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('title',               'like', "%{$s}%")
                    ->orWhere('venue',              'like', "%{$s}%")
                    ->orWhere('target_participants','like', "%{$s}%")
            );
        }

        if ($this->filterStatus  !== '') $q->where('status', $this->filterStatus);
        if ($this->filterCollege !== '') $q->where('target_participants', 'like', "%{$this->filterCollege}%");

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (! $this->viewingEventId) return null;
        return AdminEvent::withTrashed()
            ->with(['organizer:id,name,department,email'])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])->find($this->viewingEventId);
    }

    #[Computed(persist: true)]
    public function colleges(): array
    {
        return Cache::remember('admin_event_colleges', 300,
            fn() => app(AdminEventController::class)->getColleges()
        );
    }

    // ── Filters reset ─────────────────────────────────────────────────────────

    public function resetFilters(): void
    {
        $this->search        = '';
        $this->filterStatus  = '';
        $this->filterCollege = '';
        $this->filterSort    = 'recent';
        $this->resetPage();
    }

    // ── Edit Modal ────────────────────────────────────────────────────────────

    public function openEditModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

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

        if (! $collegesPart || $collegesPart === 'All Colleges') {
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

    // ── Save Event (edit only) ────────────────────────────────────────────────

    public function saveEvent(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $key = 'save_event_admin_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->dispatch('flash-message', type: 'error', message: 'Too many requests. Please wait a moment.');
            return;
        }
        RateLimiter::hit($key, 60);

        $this->formErrors = [];
        $errors           = [];

        $title     = strip_tags(trim($this->title));
        $venue     = strip_tags(trim($this->venue));
        $startTime = strip_tags(trim($this->start_time));
        $endTime   = strip_tags(trim($this->end_time));
        $batchYear = strip_tags(trim($this->batchYear));

        if (! $title)                  $errors['title']      = 'Event title is required.';
        if (! trim($this->event_date)) $errors['event_date'] = 'Event date is required.';
        if (! $venue)                  $errors['venue']      = 'Venue / Location is required.';

        if (! $startTime) {
            $errors['start_time'] = 'Start time is required.';
        } else {
            try { \Carbon\Carbon::parse($startTime); }
            catch (\Exception) { $errors['start_time'] = 'Invalid start time. Use e.g. "8:00 AM".'; }
        }

        if ($endTime) {
            try {
                $endDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $endTime, 'Asia/Manila');
                if (! isset($errors['start_time'])) {
                    $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $startTime, 'Asia/Manila');
                    if ($endDt->lte($startDt)) $errors['end_time'] = 'End time must be after start time.';
                }
            } catch (\Exception) { $errors['end_time'] = 'Invalid end time. Use e.g. "5:00 PM".'; }
        }

        if ($this->targetMode === 'college' && empty($this->selectedColleges))
            $errors['target'] = 'Please select at least one college.';

        if ($this->targetMode === 'college' && ! empty($this->selectedColleges) && ! isset($errors['target'])) {
            $cols      = $this->selectedColleges;
            $hasAlumni = Alumni::where('status', 'VERIFIED')
                ->whereHas('course', fn($c) => $c->whereIn('college', $cols))
                ->exists();
            if (! $hasAlumni)
                $errors['target'] = 'No verified alumni under ' . implode(', ', $cols) . '.';
        }

        if ($batchYear !== '' && ! isset($errors['target'])) {
            $inputYear = (int) $batchYear;
            $q = Alumni::where('status', 'VERIFIED')->where('batch', $inputYear);
            if ($this->targetMode === 'college' && ! empty($this->selectedColleges)) {
                $cols = $this->selectedColleges;
                $q->whereHas('course', fn($c) => $c->whereIn('college', $cols));
            }
            if (! $q->exists()) {
                $suggQ = Alumni::where('status', 'VERIFIED');
                if ($this->targetMode === 'college' && ! empty($this->selectedColleges)) {
                    $cols = $this->selectedColleges;
                    $suggQ->whereHas('course', fn($c) => $c->whereIn('college', $cols));
                }
                $available  = $suggQ->distinct()->orderBy('batch', 'desc')->pluck('batch')->map(fn($b) => (int)$b)->toArray();
                $scopeLabel = $this->targetMode === 'college' && ! empty($this->selectedColleges)
                    ? implode(', ', $this->selectedColleges) : 'all colleges';
                if (empty($available)) {
                    $errors['batch_year'] = "No verified alumni for {$scopeLabel}.";
                } else {
                    $nearest   = collect($available)->sortBy(fn($y) => abs($y - $inputYear))->first();
                    $batchList = implode(', ', array_slice($available, 0, 8));
                    if (count($available) > 8) $batchList .= '…';
                    $errors['batch_year'] = "No verified alumni for batch {$inputYear} in {$scopeLabel}."
                        . ($nearest ? " Nearest: {$nearest}." : '') . " Available: {$batchList}.";
                }
            }
        }

        if (! empty($errors)) { $this->formErrors = $errors; return; }

        $collegesStr = $this->targetMode === 'all' ? 'All Colleges' : implode(', ', $this->selectedColleges);
        $targetStr   = $collegesStr . ($batchYear ? ' · Batch ' . $batchYear : '');

        $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $startTime, 'Asia/Manila')->utc();
        $endDt   = ($this->event_date && $endTime)
            ? \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $endTime, 'Asia/Manila')->utc()
            : null;

        $data = [
            'title'               => $title,
            'description'         => strip_tags(trim($this->description)) ?: null,
            'event_date'          => $startDt->format('Y-m-d H:i:s'),
            'event_end_date'      => $endDt?->format('Y-m-d H:i:s'),
            'venue'               => $venue,
            'venue_address'       => strip_tags(trim($this->venue_address)) ?: null,
            'target_participants' => $targetStr,
            'notes'               => strip_tags(trim($this->notes)) ?: null,
        ];

        if (! $this->editingIsOrganizerEvent) {
            $data['contact_person'] = strip_tags(trim($this->contact_person)) ?: null;
            $data['contact_email']  = filter_var(trim($this->contact_email), FILTER_SANITIZE_EMAIL) ?: null;
            $data['contact_phone']  = strip_tags(trim($this->contact_phone)) ?: null;
        }

        $ctrl     = app(AdminEventController::class);
        $oldEvent = $ctrl->getEvent($this->editingEventId);
        $oldValues = [
            'title'               => $oldEvent->title,
            'event_date'          => $oldEvent->event_date->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
            'venue'               => $oldEvent->venue,
            'target_participants' => $oldEvent->target_participants,
        ];

        if ($this->removePhoto && ! $this->photo) {
            if ($oldEvent->photo && $oldEvent->photo !== AdminEvent::DEFAULT_PHOTO)
                Storage::disk('public')->delete($oldEvent->photo);
            $data['photo'] = null;
            $oldEvent->update(array_merge($data, [
                'updated_by'      => auth()->user()?->name,
                'updated_by_role' => 'admin',
            ]));
        } else {
            $ctrl->updateEvent($this->editingEventId, $data, $this->photo ?: null);
        }

        AuditLog::create([
            'action'        => 'updated',
            'module'        => 'event',
            'user_name'     => auth()->user()?->name,
            'user_email'    => auth()->user()?->email,
            'user_role'     => 'admin',
            'subject_label' => $title,
            'description'   => "Admin edited event: {$title}",
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

        $this->dispatch('flash-message', type: 'success', message: "'{$title}' updated successfully!");
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    // ── View Modal ────────────────────────────────────────────────────────────

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

    // ── Approve ───────────────────────────────────────────────────────────────

    public function confirmApprove(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $event = app(AdminEventController::class)->getEvent($id);

        $checkDate = $event->event_end_date ?? $event->event_date;
        if ($checkDate->isPast()) {
            $datePH = $event->event_date->setTimezone('Asia/Manila')->format('M d, Y');
            $this->dispatch('flash-message', type: 'error',
                message: "Cannot approve — event date ({$datePH}) has already passed. Edit the date first.");
            return;
        }

        $this->approveEventId    = $id;
        $this->approveEventTitle = $event->title;
        $this->approveRemarks    = '';
        $this->showApproveModal  = true;
    }

    public function executeApprove(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        if (! $this->approveEventId) return;

        $event     = app(AdminEventController::class)->getEvent($this->approveEventId);
        $checkDate = $event->event_end_date ?? $event->event_date;
        if ($checkDate->isPast()) {
            $this->dispatch('flash-message', type: 'error', message: 'Cannot approve — event date has already passed.');
            $this->cancelApprove();
            return;
        }

        app(AdminEventController::class)->approveEvent($this->approveEventId, trim($this->approveRemarks) ?: null);

        AuditLog::create([
            'action'        => 'verified',
            'module'        => 'event',
            'user_name'     => auth()->user()?->name,
            'user_email'    => auth()->user()?->email,
            'user_role'     => 'admin',
            'subject_label' => $this->approveEventTitle,
            'description'   => "Admin approved event: {$this->approveEventTitle}"
                . (trim($this->approveRemarks) ? ' — Remarks: ' . trim($this->approveRemarks) : ''),
            'new_values'    => ['status' => 'APPROVED', 'remarks' => trim($this->approveRemarks) ?: null],
            'severity'      => 'info',
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);

        $this->dispatch('flash-message', type: 'success', message: "'{$this->approveEventTitle}' approved!");
        $this->cancelApprove();
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelApprove(): void
    {
        $this->showApproveModal  = false;
        $this->approveEventId    = null;
        $this->approveEventTitle = '';
        $this->approveRemarks    = '';
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function confirmReject(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $event = app(AdminEventController::class)->getEvent($id);
        $this->rejectEventId    = $id;
        $this->rejectEventTitle = $event->title;
        $this->rejectRemarks    = '';
        $this->showRejectModal  = true;
    }

    public function executeReject(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        if (! trim($this->rejectRemarks)) {
            $this->dispatch('flash-message', type: 'error', message: 'Please provide a reason for rejection.');
            return;
        }
        if (! $this->rejectEventId) return;

        app(AdminEventController::class)->rejectEvent($this->rejectEventId, trim($this->rejectRemarks));

        AuditLog::create([
            'action'        => 'rejected',
            'module'        => 'event',
            'user_name'     => auth()->user()?->name,
            'user_email'    => auth()->user()?->email,
            'user_role'     => 'admin',
            'subject_label' => $this->rejectEventTitle,
            'description'   => "Admin rejected event: {$this->rejectEventTitle} — Reason: {$this->rejectRemarks}",
            'new_values'    => ['status' => 'REJECTED', 'reason' => trim($this->rejectRemarks)],
            'severity'      => 'warning',
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);

        $this->dispatch('flash-message', type: 'success', message: "'{$this->rejectEventTitle}' rejected.");
        $this->cancelReject();
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelReject(): void
    {
        $this->showRejectModal  = false;
        $this->rejectEventId    = null;
        $this->rejectEventTitle = '';
        $this->rejectRemarks    = '';
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function confirmDelete(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $event = app(AdminEventController::class)->getEvent($id);
        $this->deleteEventId    = $id;
        $this->deleteEventTitle = $event->title;
        $this->showDeleteModal  = true;
    }

    public function executeDelete(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        if (! $this->deleteEventId) return;

        app(AdminEventController::class)->deleteEvent($this->deleteEventId);

        AuditLog::create([
            'action'        => 'deleted',
            'module'        => 'event',
            'user_name'     => auth()->user()?->name,
            'user_email'    => auth()->user()?->email,
            'user_role'     => 'admin',
            'subject_label' => $this->deleteEventTitle,
            'description'   => "Admin permanently deleted event: {$this->deleteEventTitle}",
            'old_values'    => ['title' => $this->deleteEventTitle],
            'severity'      => 'critical',
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);

        $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteEventTitle}' deleted.");
        $this->cancelDelete();
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal  = false;
        $this->deleteEventId    = null;
        $this->deleteEventTitle = '';
    }

    // ── Restore ───────────────────────────────────────────────────────────────

    public function confirmRestore(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $event = app(AdminEventController::class)->getEvent($id);
        $this->restoreEventId    = $id;
        $this->restoreEventTitle = $event->title;
        $this->showRestoreModal  = true;
    }

    public function executeRestore(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        if (! $this->restoreEventId) return;

        $event = app(AdminEventController::class)->getEvent($this->restoreEventId);
        if ($event->trashed()) $event->restore();
        $event->update([
            'status'          => 'PENDING',
            'deleted_by'      => null,
            'deleted_by_role' => null,
            'updated_by'      => auth()->user()?->name,
            'updated_by_role' => 'admin',
        ]);

        AuditLog::create([
            'action'        => 'updated',
            'module'        => 'event',
            'user_name'     => auth()->user()?->name,
            'user_email'    => auth()->user()?->email,
            'user_role'     => 'admin',
            'subject_label' => $this->restoreEventTitle,
            'description'   => "Admin restored event: {$this->restoreEventTitle} — Status reset to PENDING",
            'old_values'    => ['status' => 'ORGANIZER_DELETED'],
            'new_values'    => ['status' => 'PENDING'],
            'severity'      => 'info',
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);

        $this->dispatch('flash-message', type: 'success', message: "'{$this->restoreEventTitle}' restored!");
        $this->cancelRestore();
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal  = false;
        $this->restoreEventId    = null;
        $this->restoreEventTitle = '';
    }

    // ── Share Modal ───────────────────────────────────────────────────────────

    public function openShareModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $event = AdminEvent::withoutTrashed()->find($id);
        if (! $event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        if (! in_array($event->status, ['APPROVED', 'COMPLETED'], true)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only approved or completed events can be shared.');
            return;
        }

        $datePH = $event->event_date->setTimezone('Asia/Manila');
        $endPH  = $event->event_end_date?->setTimezone('Asia/Manila');

        $this->shareEventId          = $id;
        $this->shareEventTitle       = $event->title;
        $this->shareEventDate        = $datePH->format('F d, Y');
        $this->shareEventTime        = $datePH->format('g:i A');
        $this->shareEventEndTime     = $endPH?->format('g:i A') ?? '';
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

    // Post to batch chat — admin posts to ALL targeted batch rooms
    public function postToBatchChat(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        if (! $this->shareEventId) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        $event = AdminEvent::withoutTrashed()->find($this->shareEventId);
        if (! $event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        // Resolve which rooms to post to based on target_participants
        $tp          = $event->target_participants ?? '';
        $tpParts     = explode(' · Batch ', $tp, 2);
        $collegesPart = trim($tpParts[0] ?? '');
        $batchYear   = trim($tpParts[1] ?? '');

        $roomQuery = DB::table('chat_rooms')
            ->join('courses', 'chat_rooms.course_code', '=', 'courses.code')
            ->select('chat_rooms.id', 'chat_rooms.course_code', 'chat_rooms.batch');

        if ($collegesPart !== 'All Colleges' && ! empty($collegesPart)) {
            $roomQuery->whereIn('courses.college', array_map('trim', explode(',', $collegesPart)));
        }
        if (! empty($batchYear)) {
            $roomQuery->where('chat_rooms.batch', $batchYear);
        }

        $rooms = $roomQuery->get();

        if ($rooms->isEmpty()) {
            $this->dispatch('flash-message', type: 'error', message: 'No batch chat rooms found for this event\'s target participants.');
            return;
        }

        $isCompleted = $this->shareEventStatus === 'COMPLETED';
        $datePH      = $event->event_date->setTimezone('Asia/Manila');
        $endPH       = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr     = $datePH->format('g:i A') . ($endPH ? ' – ' . $endPH->format('g:i A') : '');
        $baseUrl     = $this->eventsBaseUrl();

        $adminRecord = DB::table('users')->where('id', auth()->id())->first(['id', 'name']);

        if ($isCompleted) {
            $lines = [
                "🏆 Event Highlights",
                "━━━━━━━━━━━━━━━━━━━━━━━━",
                "✅ {$event->title}",
                "🗓️  {$datePH->format('F d, Y')} · {$timeStr}",
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
                "🗓️  {$datePH->format('F d, Y')} · {$timeStr}",
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
                'sender_type' => 'admin',
                'sender_id'   => auth()->id(),
                'body'        => $body,
                'reply_to_id' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            if (! $isCompleted) {
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_person = $this->contact_email = '';
        $this->contact_phone = $this->notes = '';
        $this->targetMode              = 'all';
        $this->selectedColleges        = [];
        $this->batchYear               = '';
        $this->photo                   = null;
        $this->existingPhotoUrl        = null;
        $this->removePhoto             = false;
        $this->formErrors              = [];
        $this->editingEventId          = null;
        $this->isEditing               = false;
        $this->editingIsOrganizerEvent = false;
    }
};
?>

<div class="min-h-screen bg-gray-50">

<style>
/* ── Admin Events — scoped styles ── */
.ae-filter-sel {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right .6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem !important;
    -webkit-appearance: none;
    appearance: none;
}
.ae-filter-sel:hover  { border-color: #7a3f91 !important; }
.ae-filter-sel:focus  { outline: none; border-color: #7a3f91 !important; box-shadow: 0 0 0 3px rgba(122,63,145,.12) !important; }

@keyframes aeModalIn { from { opacity:0; transform:translateY(14px) scale(.97) } to { opacity:1; transform:none } }
@keyframes aeSlideIn { from { transform:translateX(100%) } to { transform:translateX(0) } }
@keyframes aeSlideOut{ from { transform:translateX(0) } to { transform:translateX(100%) } }

.ae-min { animation: aeModalIn .2s cubic-bezier(.25,.8,.25,1) both; }

.ae-tbl-row { background:#fff; transition: background .1s; }
.ae-tbl-row:hover { background: #faf5fd; }

.ae-scroll::-webkit-scrollbar       { width:4px; height:4px; }
.ae-scroll::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px; }
.ae-scroll::-webkit-scrollbar-thumb { background:#ddd4f0; border-radius:99px; }
.ae-scroll::-webkit-scrollbar-thumb:hover { background:#7a3f91; }

[x-cloak] { display:none !important; }
</style>

{{-- ── FLASH TOAST ──────────────────────────────────────────────────────────── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,
              display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
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
        <p class="font-semibold text-sm" style="color:#333333;"
           x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 leading-snug break-words" style="color:#666666;" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-600 transition flex-shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-8 max-w-screen-2xl mx-auto space-y-5">

    {{-- ── HEADER ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0 shadow-lg">
            <i class="fas fa-calendar-days text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold leading-tight" style="color:#333333;">Event Management</h1>
            <p class="text-sm mt-0.5 font-normal" style="color:#999999;">Review, moderate, and manage all event postings across all colleges.</p>
        </div>
    </div>

    {{-- ── MAIN TABLE CARD ─────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col"
         style="height: calc(100vh - 175px); min-height: 500px;">

        {{-- ── Filter Bar ── --}}
        <div class="px-4 sm:px-5 py-3 border-b border-gray-200 bg-white flex flex-wrap gap-2 items-center">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title, venue, college…"
                       class="w-full pl-8 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       style="color:#333333;" autocomplete="off" maxlength="100">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="ae-filter-sel px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333; min-width:140px;">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="ORGANIZER_DELETED">Deleted by Organizer</option>
                <option value="COMPLETED">Completed</option>
            </select>

            {{-- College --}}
            <select wire:model.live="filterCollege"
                    class="ae-filter-sel px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:140px;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            {{-- Sort --}}
            <select wire:model.live="filterSort"
                    class="ae-filter-sel px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:130px;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    class="px-3 py-2.5 rounded-lg border border-gray-200 bg-white text-sm font-semibold hover:bg-gray-50 transition flex items-center gap-1.5"
                    style="color:#666666;">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Mobile row 2 --}}
        <div class="px-4 py-2 border-b border-gray-200 bg-white flex gap-2 sm:hidden">
            <select wire:model.live="filterCollege"
                    class="ae-filter-sel flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort"
                    class="ae-filter-sel flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── Table ── --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto ae-scroll"
                 wire:loading.class="opacity-50 pointer-events-none"
                 wire:target="search,filterStatus,filterCollege,filterSort,resetFilters,
                              previousPage,nextPage,executeApprove,executeReject,
                              executeDelete,executeRestore">
                <table class="w-full border-collapse min-w-[700px]">
                    <thead>
                        <tr class="sticky top-0 z-10 bg-[#f5f0fa] border-b border-[#e2d3ef]">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Event</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Date &amp; Time</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:#333333;">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden lg:table-cell" style="color:#333333;">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider hidden lg:table-cell" style="color:#333333;">RSVPs</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">

                        @forelse($this->events as $event)
                        @php
                            $isOrgDeleted = $event->status === 'ORGANIZER_DELETED';
                            $isCompleted  = $event->status === 'COMPLETED';
                            $isApproved   = $event->status === 'APPROVED';
                            $isPending    = $event->status === 'PENDING';
                            $isRejected   = $event->status === 'REJECTED';

                            // Derive display college
                            if ($event->organizer_id && $event->organizer) {
                                $displayCollege = $event->organizer->department ?? '—';
                            } else {
                                $tp = $event->target_participants ?? '';
                                $parts = explode(' · Batch ', $tp, 2);
                                $displayCollege = trim($parts[0]) ?: 'All Colleges';
                            }
                        @endphp

                        <tr class="ae-tbl-row">

                            {{-- Title --}}
                            <td class="px-4 sm:px-5 py-3.5 max-w-[200px]">
                                <p class="font-semibold text-sm truncate {{ $isOrgDeleted ? 'line-through opacity-50' : '' }}"
                                   style="color:#333333;">{{ $event->title }}</p>
                                <p class="text-xs mt-0.5" style="color:#999999;">{{ $event->created_at->diffForHumans() }}</p>
                            </td>

                            {{-- Date / Time --}}
                            <td class="px-4 sm:px-5 py-3.5 whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">
                                    {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                                </p>
                                <p class="text-xs mt-0.5" style="color:#666666;">
                                    {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @if($event->event_end_date)
                                        <span class="mx-0.5">–</span>{{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            {{-- Coordinator --}}
                            <td class="px-4 sm:px-5 py-3.5 hidden md:table-cell">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-[#f5eef9] border border-[#d4aaeb] rounded-full text-xs font-semibold" style="color:#7a3f91;">
                                        <i class="fas fa-shield-halved text-[9px]"></i> Admin
                                    </span>
                                @endif
                            </td>

                            {{-- College --}}
                            <td class="px-4 sm:px-5 py-3.5 hidden lg:table-cell">
                                <p class="text-sm font-semibold max-w-[150px] truncate" style="color:#666666;" title="{{ $displayCollege }}">
                                    {{ $displayCollege }}
                                </p>
                            </td>

                            {{-- RSVPs --}}
                            <td class="px-4 sm:px-5 py-3.5 text-center hidden lg:table-cell">
                                @if($isOrgDeleted)
                                    <span class="text-xs" style="color:#cccccc;">—</span>
                                @else
                                    <div class="flex items-center justify-center gap-1">
                                        <span class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold">
                                            <i class="fas fa-circle-check text-[9px]"></i>{{ $event->confirmed_count }}
                                        </span>
                                        <span class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg bg-red-50 border border-red-200 text-red-600 text-xs font-semibold">
                                            <i class="fas fa-circle-xmark text-[9px]"></i>{{ $event->declined_count }}
                                        </span>
                                        <span class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-600 text-xs font-semibold">
                                            <i class="fas fa-circle-question text-[9px]"></i>{{ $event->tentative_count }}
                                        </span>
                                    </div>
                                @endif
                            </td>

                            {{-- Status Badge --}}
                            <td class="px-4 sm:px-5 py-3.5 text-center whitespace-nowrap">
                                @if($isCompleted)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-green-100 text-green-800 border border-green-300 rounded-full text-xs font-semibold">
                                        <i class="fas fa-circle-check text-[9px]"></i> Completed
                                    </span>
                                @elseif($isOrgDeleted)
                                    <span class="inline-block px-2.5 py-1.5 bg-red-100 text-red-700 border border-red-300 rounded-full text-xs font-semibold">Org. Deleted</span>
                                @elseif($isPending)
                                    <span class="inline-block px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-semibold">Pending</span>
                                @elseif($isApproved)
                                    <span class="inline-block px-2.5 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold">Approved</span>
                                @else
                                    <span class="inline-block px-2.5 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-full text-xs font-semibold">Rejected</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 sm:px-5 py-3.5 text-center">
                                <div class="flex items-center justify-center gap-1 flex-wrap">

                                    {{-- View — always --}}
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-[#7a3f91] bg-[#f5eef9] border border-[#d4aaeb] hover:bg-[#e9d5f3] rounded-lg transition">
                                        <i class="fas fa-eye text-[10px]"></i><span>View</span>
                                    </button>

                                    @if($isCompleted)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-lg transition">
                                            <i class="fas fa-trophy text-[10px]"></i><span>Highlights</span>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-[10px]"></i>
                                        </button>

                                    @elseif($isApproved)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-lg transition">
                                            <i class="fas fa-share-nodes text-[10px]"></i><span>Share</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white hover:border-blue-400 rounded-lg transition">
                                            <i class="fas fa-pencil text-[10px]"></i>
                                        </button>

                                    @elseif($isOrgDeleted)
                                        <button wire:click="confirmRestore({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-orange-600 bg-orange-50 border border-orange-200 hover:bg-orange-100 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-[10px]"></i><span>Restore</span>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-[10px]"></i>
                                        </button>

                                    @elseif($isPending)
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-lg transition">
                                            <i class="fas fa-check text-[10px]"></i><span>Approve</span>
                                        </button>
                                        <button wire:click="confirmReject({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 rounded-lg transition">
                                            <i class="fas fa-xmark text-[10px]"></i><span>Reject</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white hover:border-blue-400 rounded-lg transition">
                                            <i class="fas fa-pencil text-[10px]"></i>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-[10px]"></i>
                                        </button>

                                    @elseif($isRejected)
                                        <button wire:click="confirmApprove({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-[10px]"></i><span>Re-Approve</span>
                                        </button>
                                        <button wire:click="confirmDelete({{ $event->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-trash text-[10px]"></i>
                                        </button>
                                    @endif

                                </div>
                            </td>
                        </tr>

                        @empty
                        <tr>
                            <td colspan="7" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 bg-[#f5eef9] rounded-2xl flex items-center justify-center">
                                        <i class="fas fa-calendar-days text-2xl" style="color:#d4aaeb;"></i>
                                    </div>
                                    <p class="font-semibold text-base" style="color:#666666;">No events found</p>
                                    <p class="text-sm" style="color:#999999;">
                                        @if($search || $filterStatus || $filterCollege)
                                            Try adjusting your filters.
                                        @else
                                            No events have been submitted yet.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                        @endforelse

                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Pagination ── --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-200 shrink-0 rounded-b-2xl" style="background:#7a3f91;">
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
                    of <strong class="text-white">{{ number_format($total) }}</strong>
                    event{{ $total !== 1 ? 's' : '' }}
                    @if($filterStatus || $filterCollege || $search)
                        <span class="text-xs ml-1" style="color:rgba(255,255,255,.5);">(filtered)</span>
                    @endif
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

</div>{{-- /container --}}


{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: View Event
════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev           = $this->viewingEvent;
    $totalRsvp    = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
    $isOrgDeleted = $ev->status === 'ORGANIZER_DELETED';
    $isCompleted  = $ev->status === 'COMPLETED';
    $isApproved   = $ev->status === 'APPROVED';
    $isPending    = $ev->status === 'PENDING';
    $isRejected   = $ev->status === 'REJECTED';

    if ($ev->organizer_id && $ev->organizer) {
        $displayCollege = $ev->organizer->department ?? '—';
    } else {
        $tp = $ev->target_participants ?? '';
        $pts = explode(' · Batch ', $tp, 2);
        $displayCollege = trim($pts[0]) ?: 'All Colleges';
    }

    $roleLabel = match($ev->updated_by_role ?? '') {
        'director'  => 'Alumni Director',
        'admin'     => 'Admin',
        'organizer' => 'Coordinator',
        default     => ucfirst($ev->updated_by_role ?? '')
    };
@endphp
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => open = true)"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeViewModal(), 290)">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"></div>

    {{-- Panel --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Header --}}
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

        {{-- Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto ae-scroll">

            {{-- Hero photo --}}
            <div class="relative w-full bg-gray-100 flex items-center justify-center" style="min-height:180px;max-height:300px;">
                <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                     class="w-full object-contain {{ $isCompleted ? 'brightness-90' : '' }}"
                     style="max-height:300px;display:block;">
                <div class="absolute top-3 right-3">
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">
                            <i class="fas fa-circle-check text-xs"></i> Completed
                        </span>
                    @elseif($isOrgDeleted)
                        <span class="px-3 py-1.5 bg-red-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Deleted by Org.</span>
                    @elseif($isPending)
                        <span class="px-3 py-1.5 bg-amber-600/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Pending</span>
                    @elseif($isApproved)
                        <span class="px-3 py-1.5 bg-emerald-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Approved</span>
                    @else
                        <span class="px-3 py-1.5 bg-red-700/90 backdrop-blur text-white rounded-full text-xs font-semibold shadow">Rejected</span>
                    @endif
                </div>
            </div>

            {{-- Org-deleted banner --}}
            @if($isOrgDeleted)
            <div class="mx-5 mt-4 flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-700">
                <i class="fas fa-trash text-red-500 shrink-0"></i>
                Deleted by <strong>{{ $ev->deleted_by ?? $ev->organizer?->name ?? 'Coordinator' }}</strong>
                · {{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
            </div>
            @endif

            {{-- Core info --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-calendar text-[#7a3f91] mt-0.5 w-4 flex-shrink-0"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $ev->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-clock text-[#7a3f91] mt-0.5 w-4 flex-shrink-0"></i>
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
                        <i class="fas fa-location-dot text-[#7a3f91] mt-0.5 w-4 flex-shrink-0"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            {{ $ev->venue }}
                            @if($ev->venue_address)
                                <span class="text-sm font-normal" style="color:#666666;"> · {{ $ev->venue_address }}</span>
                            @endif
                        </span>
                    </li>
                    @if($ev->target_participants)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-users text-[#7a3f91] mt-0.5 w-4 flex-shrink-0"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $ev->target_participants }}</span>
                    </li>
                    @endif
                    <li class="flex items-start gap-3">
                        <i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }} text-[#7a3f91] mt-0.5 w-4 flex-shrink-0"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            @if($ev->organizer)
                                {{ $ev->organizer->name }} · {{ $ev->organizer->department }}
                            @else
                                Posted by Admin
                            @endif
                        </span>
                    </li>
                </ul>
            </div>

            {{-- RSVPs --}}
            <div class="px-6 py-5 border-b border-gray-100 bg-gray-50">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:#333333;">
                    <i class="fas fa-users text-xs"></i> Attendee Responses
                    @if($totalRsvp > 0)<span class="font-normal" style="color:#999999;"> · {{ $totalRsvp }} total</span>@endif
                </h3>
                @if($totalRsvp === 0)
                    <div class="text-center py-5 text-sm" style="color:#999999;">
                        <i class="fas fa-inbox text-2xl block mb-2 text-gray-200"></i>
                        No responses yet.
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

            {{-- Status block --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Status</h3>
                @if($isCompleted)
                    <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-green-800"><i class="fas fa-circle-check mr-2 text-green-500"></i>Event Completed</p>
                        <p class="text-sm text-green-700 mt-1">This event has already taken place successfully.</p>
                    </div>
                @elseif($isOrgDeleted)
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-red-800"><i class="fas fa-trash mr-2 text-red-500"></i>Deleted by Coordinator</p>
                        <p class="text-sm text-red-600 mt-1">You can restore this event to return it to Pending review.</p>
                    </div>
                @elseif($isPending)
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-amber-800"><i class="fas fa-hourglass-half mr-2 text-amber-500"></i>Pending Admin Review</p>
                        <p class="text-sm text-amber-700 mt-1">This event is waiting for your approval or rejection.</p>
                    </div>
                @elseif($isApproved)
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800"><i class="fas fa-circle-check mr-2 text-emerald-500"></i>Approved</p>
                        @if($ev->reviewed_at)<p class="text-xs text-emerald-700 mt-1">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
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

            {{-- Posting details --}}
            <div class="px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Posting Details</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 border border-gray-200 rounded-xl overflow-hidden divide-x divide-y divide-gray-100">
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Submitted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->created_at->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                        <p class="text-xs mt-0.5" style="color:#666666;">{{ $ev->created_at->setTimezone('Asia/Manila')->format('g:i A') }}</p>
                    </div>
                    @if($ev->updated_by)
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Last Updated By</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $ev->updated_by }}</p>
                        <p class="text-xs font-semibold mt-0.5" style="color:#7a3f91;">{{ $roleLabel }}</p>
                        <p class="text-xs mt-0.5" style="color:#666666;">{{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>
                    </div>
                    @endif
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Status</p>
                        @if($isCompleted)<p class="text-sm font-semibold text-green-700">Completed</p>
                        @elseif($isOrgDeleted)<p class="text-sm font-semibold text-red-600">Deleted by Org.</p>
                        @elseif($isPending)<p class="text-sm font-semibold text-amber-600">Pending</p>
                        @elseif($isApproved)<p class="text-sm font-semibold text-emerald-600">Approved</p>
                        @else<p class="text-sm font-semibold text-red-600">Rejected</p>@endif
                    </div>
                </div>
            </div>

        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-2 flex-wrap bg-white flex-shrink-0">
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)" type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 rounded-xl transition"
                    style="color:#666666;">
                <i class="fas fa-xmark text-sm"></i> Close
            </button>

            @if($isCompleted)
                <button wire:click="openShareModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-xl transition">
                    <i class="fas fa-trophy text-sm"></i> Share Highlights
                </button>
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>

            @elseif($isApproved)
                <button wire:click="openShareModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-xl transition">
                    <i class="fas fa-share-nodes text-sm"></i> Share
                </button>
                <button wire:click="openEditModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white rounded-xl transition">
                    <i class="fas fa-pencil text-sm"></i> Edit
                </button>

            @elseif($isOrgDeleted)
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmRestore({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-orange-600 border border-orange-200 bg-white hover:bg-orange-50 rounded-xl transition">
                    <i class="fas fa-rotate-left text-sm"></i> Restore
                </button>

            @elseif($isPending)
                <button wire:click="openEditModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white rounded-xl transition">
                    <i class="fas fa-pencil text-sm"></i> Edit
                </button>
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmReject({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-xmark text-sm"></i> Reject
                </button>
                <button wire:click="confirmApprove({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-xl transition">
                    <i class="fas fa-check text-sm"></i> Approve
                </button>

            @elseif($isRejected)
                <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmApprove({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-xl transition">
                    <i class="fas fa-rotate-left text-sm"></i> Re-Approve
                </button>
            @endif
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: Edit Event
════════════════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => open = true)"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeFormModal(), 290)">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform"
         x-data="{}"
         x-effect="if($wire.formErrors && Object.keys($wire.formErrors).length > 0){ $nextTick(() => { const el = $refs.panelBody; if(el) el.scrollTo({top:0,behavior:'smooth'}); }); }">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] text-white flex-shrink-0">
            <h2 class="text-base font-semibold flex items-center gap-2.5">
                <i class="fas fa-pen-to-square"></i> Edit Event
            </h2>
            <button @click="open = false; setTimeout(() => $wire.closeFormModal(), 290)"
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Error banner --}}
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

        {{-- Scrollable body --}}
        <div class="flex-1 min-h-0 overflow-y-auto ae-scroll px-6 py-6 space-y-5" x-ref="panelBody">

            {{-- Photo --}}
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-2">
                    Event Photo <span class="font-normal normal-case text-gray-400">— optional</span>
                </label>
                <div x-data="{isDragging:false}"
                     @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                     class="border-2 rounded-xl p-5 text-center cursor-pointer transition-all"
                     :class="isDragging ? 'border-[#7a3f91] bg-[#f5eef9]' : '{{ ($photo||($existingPhotoUrl&&!$removePhoto)) ? 'border-[#7a3f91] border-solid bg-[#f5eef9]/40' : 'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-gray-50' }}'">
                    <label class="cursor-pointer block">
                        <input type="file" wire:model="photo" accept="image/*" class="hidden">
                        @if($photo)
                            <div class="flex flex-col items-center gap-3">
                                <img src="{{ $photo->temporaryUrl() }}" class="w-full max-h-52 object-contain rounded-xl shadow border border-[#d4aaeb]">
                                <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
                            </div>
                        @elseif($existingPhotoUrl && !$removePhoto)
                            <div class="flex flex-col items-center gap-3">
                                <img src="{{ $existingPhotoUrl }}" class="w-full max-h-52 object-contain rounded-xl shadow border border-gray-200">
                                <p class="text-sm font-semibold" style="color:#666666;">Current photo — click to change</p>
                            </div>
                        @else
                            <div class="flex flex-col items-center gap-2 py-2">
                                <i class="fas fa-cloud-arrow-up text-3xl text-gray-300"></i>
                                <p class="font-semibold text-sm" style="color:#666666;">Click to upload or drag &amp; drop</p>
                                <p class="text-xs" style="color:#999999;">JPG, PNG, WEBP — max 5 MB</p>
                            </div>
                        @endif
                    </label>
                </div>
                @if($existingPhotoUrl && !$removePhoto && !$photo)
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" wire:click="$set('removePhoto',true)"
                                class="text-sm text-red-600 hover:text-red-700 font-semibold flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50 transition">
                            <i class="fas fa-trash text-xs"></i> Remove photo
                        </button>
                        <span class="text-xs" style="color:#999999;">(uses default)</span>
                    </div>
                @endif
                @if($removePhoto)
                    <div class="mt-2 flex items-center gap-2">
                        <span class="text-sm text-amber-600 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span>
                        <button type="button" wire:click="$set('removePhoto',false)" class="text-sm text-blue-500 underline">Undo</button>
                    </div>
                @endif
                <div wire:loading wire:target="photo" class="mt-2 text-sm text-[#7a3f91] flex items-center gap-2">
                    <i class="fas fa-spinner animate-spin"></i> Uploading…
                </div>
            </div>

            {{-- Event Details section --}}
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2">
                    <i class="fas fa-circle-info text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Event Details</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">

                    {{-- Title --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                            Event Title <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026"
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['title']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['title']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>

                    {{-- Description --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">Description</label>
                        <textarea wire:model.defer="description" rows="3" placeholder="Describe the event…"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                                  style="color:#333333;"></textarea>
                    </div>

                    {{-- Date + Times --}}
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                            Event Date <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="event_date" type="date" min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['event_date']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['event_date']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['event_date'] }}</p>@endif
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                                Start Time <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['start_time']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['start_time']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                                End Time <span class="font-normal normal-case text-gray-400">— optional</span>
                            </label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['end_time']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['end_time']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['end_time'] }}</p>@endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                                Venue / Location <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['venue']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($formErrors['venue']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['venue'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                                Full Address <span class="font-normal normal-case text-gray-400">— optional</span>
                            </label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Old Nalsian Road, Calasiao"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   style="color:#333333;">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Target Participants --}}
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2">
                    <i class="fas fa-users text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Target Participants</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="flex gap-3">
                        <button type="button" wire:click="$set('targetMode','all')"
                                class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                       {{ $targetMode === 'all' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                style="{{ $targetMode !== 'all' ? 'color:#666666;' : '' }}">
                            <i class="fas fa-globe text-base"></i><span>All Colleges</span>
                        </button>
                        <button type="button" wire:click="$set('targetMode','college')"
                                class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                       {{ $targetMode === 'college' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                style="{{ $targetMode !== 'college' ? 'color:#666666;' : '' }}">
                            <i class="fas fa-building-columns text-base"></i><span>Specific College(s)</span>
                        </button>
                    </div>

                    @if($targetMode === 'all')
                        <div class="flex items-center gap-3 bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3">
                            <i class="fas fa-globe text-[#7a3f91] text-lg"></i>
                            <div>
                                <div class="text-sm font-semibold" style="color:#5e2f72;">All Colleges</div>
                                <div class="text-xs mt-0.5" style="color:#7a3f91;">Visible to all alumni regardless of college.</div>
                            </div>
                        </div>

                    @elseif($targetMode === 'college')
                        <div>
                            @if(isset($formErrors['target']))<p class="text-sm text-red-600 flex items-center gap-1.5 mb-2"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['target'] }}</p>@endif
                            @if(count($this->colleges) > 0)
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-semibold uppercase tracking-[.08em]" style="color:#666666;">Select College(s)</span>
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})"
                                                class="text-sm font-semibold hover:underline" style="color:#7a3f91;">
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
                            @endif
                        </div>
                    @endif

                    <div class="pt-3 border-t border-gray-100">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                            Batch Year <span class="font-normal normal-case text-gray-400">— optional</span>
                        </label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}"
                               placeholder="e.g. {{ now()->year - 2 }}"
                               class="w-full sm:max-w-xs px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['batch_year']) ? 'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                               style="color:#333333;">
                        @if(isset($formErrors['batch_year']))<p class="mt-1.5 text-sm text-red-600 flex items-center gap-1.5"><i class="fas fa-circle-exclamation"></i>{{ $formErrors['batch_year'] }}</p>@endif
                    </div>
                </div>
            </div>

            {{-- Contact Person --}}
            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-[#f5f0fa] px-4 py-3 border-b border-[#e2d3ef] flex items-center gap-2 flex-wrap">
                    <i class="fas fa-address-card text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold" style="color:#333333;">Contact Person</span>
                    @if($editingIsOrganizerEvent)
                        <span class="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg shrink-0">
                            <i class="fas fa-lock text-xs"></i> Coordinator's contact — read only
                        </span>
                    @endif
                </div>
                <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach([['contact_person','Name','text','Full name'],['contact_email','Email','email','contact@example.com'],['contact_phone','Phone','text','+63 9XX XXX XXXX']] as [$field,$label,$type,$ph])
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">{{ $label }}</label>
                        <input wire:model.defer="{{ $field }}" type="{{ $type }}" placeholder="{{ $ph }}"
                               @if($editingIsOrganizerEvent) readonly @endif
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition {{ $editingIsOrganizerEvent ? 'cursor-not-allowed bg-gray-50' : '' }}"
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

            {{-- Notes --}}
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                    Additional Notes / Requirements
                </label>
                <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions…"
                          class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                          style="color:#333333;"></textarea>
            </div>
        </div>

        {{-- Footer --}}
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


{{-- ════ MODAL: Approve ════ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelApprove()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden ae-min">
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
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
                    Remarks <span class="font-normal normal-case text-gray-400">— optional</span>
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
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-circle-check mr-1"></i>Yes, Approve</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Reject ════ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelReject()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden ae-min">
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
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-[.08em] mb-1.5">
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
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1"></i>Yes, Reject</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Restore ════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden ae-min">
        <div class="px-6 py-5 bg-orange-50 border-b border-orange-100">
            <h2 class="text-lg font-semibold text-orange-800 flex items-center gap-2.5">
                <div class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-rotate-left text-orange-500 text-base"></i>
                </div>
                Restore Event
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1" style="color:#666666;">You are about to restore:</p>
            <p class="font-semibold text-orange-700 text-base mb-4">"{{ $restoreEventTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-sm text-blue-800 flex items-start gap-2">
                <i class="fas fa-circle-info text-blue-500 mt-0.5 flex-shrink-0"></i>
                <span>The event will be set back to <strong>PENDING</strong> for review. The coordinator will see it again.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition"
                        style="color:#333333;">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-4 py-3 bg-orange-500 hover:bg-orange-600 disabled:bg-orange-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i>Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden ae-min">
        <div class="px-6 py-5 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-semibold text-lg">Permanently Delete</h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1" style="color:#666666;">You are about to permanently delete:</p>
            <p class="font-semibold text-red-700 text-lg mb-4">"{{ $deleteEventTitle }}"</p>
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3.5 mb-5">
                <p class="text-sm font-semibold text-red-800 flex items-center gap-2">
                    <i class="fas fa-circle-exclamation text-red-500"></i> This action cannot be undone.
                </p>
                <p class="text-sm text-red-700 mt-1 pl-5">The event and its photo will be permanently removed.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition flex items-center justify-center gap-2"
                        style="color:#333333;">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i>Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: Share / Highlights
     wire:ignore prevents Livewire from re-morphing Alpine expressions
     during FontAwesome SVG mutations.
════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl  = $this->eventsBaseUrl();
    $shareHost     = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isShCompleted = $shareEventStatus === 'COMPLETED';
    $shTime        = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $shDescPreview = mb_strlen($shareEventDescription) > 160
        ? mb_substr($shareEventDescription, 0, 160) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isShCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTime ? " · {$shTime}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTime ? " · {$shTime}" : '');
    }
    if ($shareEventVenue)  $fbLines[] = "📍 {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = $isShCompleted ? "👥 {$shareEventTarget}" : "👥 Open for: {$shareEventTarget}";
    $fbLines[] = '';
    if ($shareEventDescription) {
        $dPrev     = mb_strlen($shareEventDescription) > 200 ? mb_substr($shareEventDescription, 0, 200) . '…' : $shareEventDescription;
        $fbLines[] = $dPrev;
        $fbLines[] = '';
    }
    $fbLines[]  = $isShCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $shareEventPhotoUrl
        && ! str_contains($shareEventPhotoUrl, 'default')
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
         close() { this.open = false; setTimeout(() => $wire.closeShareModal(), 290); },
         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
                 else {
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
                     const html = '<img src=\'' + imageUrl + '\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                     await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html],{type:'text/html'}), 'text/plain': new Blob([text],{type:'text/plain'}) })]);
                     return true;
                 }
             } catch(e) { console.warn('Rich copy failed:', e); }
             await this.copyPlainText(text);
             return false;
         },
         async shareOnFacebook() {
             const ok = await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied = true; this.fbCopyFailed = !ok;
             const target = this.hasPhoto ? this.photoUrl : this.baseUrl;
             window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(target), '_blank', 'width=626,height=436,noopener,noreferrer');
             setTimeout(() => { this.fbCopied = false; this.fbCopyFailed = false; }, 8000);
         },
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) { window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl); setTimeout(() => window.open('https://www.messenger.com/','_blank','noopener'), 1500); }
             else window.open('https://www.messenger.com/','_blank','noopener');
             setTimeout(() => { this.messengerCopied = false; }, 8000);
         },
         async copyLinkFn() {
             await this.copyPlainText(this.baseUrl);
             this.copied = true; setTimeout(() => this.copied = false, 2500);
         }
     }"
     x-init="requestAnimationFrame(() => open = true)"
     @keydown.escape.window="close()">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    {{-- Panel --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-lg font-semibold flex items-center gap-2" style="color:#333333;">
                @if($isShCompleted)
                    <i class="fas fa-trophy text-amber-500 text-lg"></i> Share Event Highlights
                @else
                    <i class="fas fa-share-nodes text-sky-600 text-lg"></i> Share Event
                @endif
            </h2>
            <button @click="close()" type="button"
                    class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer"
                    style="color:#999999;">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        {{-- Body — two-column --}}
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto ae-scroll">
                <p class="text-xs font-semibold uppercase tracking-widest flex-shrink-0" style="color:#999999;">Post preview</p>

                {{-- Preview card --}}
                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center" style="max-height:220px;overflow:hidden;">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full object-contain" style="max-height:220px;display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color:{{ $isShCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#555555;">
                            {{ $shareEventDate }}@if($shTime) · {{ $shTime }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-[#f5eef9]" style="color:#7a3f91;">
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

                {{-- How it works --}}
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> opens the share dialog
                            <em>and</em> copies the event photo + caption to your clipboard.
                            Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> in the composer to paste automatically.
                        </p>
                    </div>
                </div>

                {{-- Batch chat info --}}
                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Sends the event caption to all batch chat rooms matching
                            <strong>{{ $shareEventTarget ?: 'all colleges' }}</strong>.
                        </p>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto ae-scroll">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Share via</p>

                {{-- Facebook feedback --}}
                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
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
                           x-text="fbCopyFailed ? 'Caption copied as text only — paste in the post.' : 'Press Ctrl+V in the post to paste photo + caption!'"></p>
                    </div>
                </div>

                {{-- Messenger feedback --}}
                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Messenger opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste photo + caption.</p>
                    </div>
                </div>

                {{-- Facebook --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 rounded-xl bg-white flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
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

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 rounded-xl bg-white flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_adm" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_adm)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · photo+text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                {{-- Divider --}}
                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or post directly</span>
                    </div>
                </div>

                {{-- Batch Chat --}}
                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72;background-color:#f5eef9;">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isShCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends to all targeted batch rooms</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                {{-- Divider --}}
                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or copy link</span>
                    </div>
                </div>

                {{-- Copy link --}}
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

                {{-- Close --}}
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