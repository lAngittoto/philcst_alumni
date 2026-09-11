{{-- resources/views/livewire/organizer/event-organizer.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    // ── Batch Year — now a FROM/TO range picker (like Alumni Records'
    //    Batch filter) instead of a free-typed single year. A single
    //    batch is just a range where From === To. batchYear (single
    //    string) is gone — everything downstream reads
    //    batchYearFrom/batchYearTo instead. ──
    public string $batchYearFrom  = '';
    public string $batchYearTo    = '';

    /** True only after the user explicitly taps "All Alumni" in the batch
     *  picker. Lets the trigger button show "All Alumni" instead of the
     *  ambiguous "Select Batch Year" placeholder — both batchYearFrom and
     *  batchYearTo are '' in BOTH states, so without this flag the button
     *  looked identical whether "All Alumni" was chosen or nothing was
     *  picked yet, making the selection look like it "didn't work". */
    public bool $allAlumniChosen = false;

    /** Set while setSingleBatchYear()/setBatchRange() are writing to
     *  batchYearFrom/batchYearTo directly, so the updated*() hooks below
     *  don't immediately re-trigger and double-fire normalization. */
    private bool $skipBatchYearHooks = false;

    public array  $selectedCourses = [];

    public $photo                    = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto       = false;

    public array  $formErrors = [];

    public bool  $showViewModal  = false;
    public ?int  $viewingEventId = null;

    public bool $showNoAlumniModal = false;

    /** True only when the currently open View Details / edit-form modal
     *  was opened via a notification click or the ?highlight_event= deep
     *  link (see mount() and openViewEventFromNotif() below) — never for
     *  an ordinary row click from the table. Only in that case should
     *  closing the modal snap the FILTERS pill to the event's status;
     *  otherwise closing a row opened while sitting on "All Statuses" (or
     *  any other filter) must leave that filter exactly as the organizer
     *  had it. */
    public bool $viaNotifOrDeepLink = false;

    public bool   $isResubmitting           = false;
    public string $resubmitEventTitle       = '';
    public string $resubmitEventRemarks     = '';

    // ── Pre-submit confirm modal (create only) ──
    public bool   $showSubmitConfirmModal = false;

    // ── Confirm modals ──
    public bool   $showDeleteModal   = false;
    public ?int   $pendingDeleteId   = null;
    public string $pendingDeleteTitle = '';

    public bool   $showShareModal        = false;
    public ?int   $shareEventId          = null;
    public string $shareEventType        = 'ORGANIZER';
    public string $shareEventTitle       = '';
    public string $shareEventVenue       = '';
    public string $shareEventDate        = '';
    public string $shareEventTime        = '';
    public string $shareEventEndTime     = '';
    public string $shareEventDescription = '';
    public string $shareEventNotes       = '';
    public string $shareEventOrganizer   = '';
    public string $shareEventTargetParts = '';
    public string $shareEventPhotoUrl    = '';
    public bool   $shareEventIsCompleted = false;

    // ── "Share to Message Hub" — chat room multi-select ──
    public array $shareAvailableRooms   = [];   // [['id'=>, 'label'=>, 'type'=>, 'course_code'=>, 'batch'=>, 'department'=>], ...]
    public array $shareTargetRoomIds    = [];   // checkbox-bound selected room ids (as strings)
    public array $shareAutoRoomIds      = [];   // room ids auto-checked because they match the event's target batch/courses
    public string $shareTargetBatchYear = '';   // parsed from target_participants, e.g. "2026" (blank = all batches)
    public array  $shareTargetCourseCodes = []; // parsed course codes (empty = "All Courses")

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

        // ── Deep-link: arriving from a "View Event" card shared in chat ────
        //    (organizer/chat-alumni.blade.php's [[EVENT:ORGANIZER:id]]
        //    preview card links here with ?highlight_event=ID). Previously
        //    this just landed on the plain table — the coordinator still
        //    had to find and click the right row themselves. Now, if the
        //    ID is valid and belongs to this coordinator, View Details
        //    opens automatically for that exact event, same as clicking it.
        $highlightId = (int) request()->query('highlight_event', 0);
        if ($highlightId > 0) {
            $owned = OrganizerEvent::where('id', $highlightId)
                ->where('organizer_id', $orgId)
                ->exists();
            if ($owned) {
                $this->viewEvent($highlightId);
                $this->viaNotifOrDeepLink = true;
            }
        }
    }

    // ── Same-page notif click: when the organizer clicks an event notif
    //    while already sitting on Event Management, the sidebar dispatches
    //    this Livewire event instead of doing a full page reload — no
    //    ?highlight_event= URL trip needed, no page flash, the left
    //    sidebar's close/open transition plays normally, and View Details
    //    (or the resubmit form, depending on status) opens instantly. ──
    #[On('open-view-event')]
    public function openViewEventFromNotif(int $id): void
    {
        $orgId = $this->organizerId;
        if (!$orgId) return;

        $owned = OrganizerEvent::where('id', $id)
            ->where('organizer_id', $orgId)
            ->exists();

        if ($owned) {
            $this->viewEvent($id);
            $this->viaNotifOrDeepLink = true;
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

    /** Distinct verified-alumni batch years available to pick from, scoped
     *  to this organizer's college (same scoping as availableCourses()),
     *  newest first — feeds both sides of the Batch Year range picker. */
    #[Computed]
    public function batches(): array
    {
        $dept = $this->organizerDepartment;

        $cacheKey = 'organizer_batches_' . ($dept ?: 'all');
        return Cache::remember($cacheKey, 300, function () use ($dept) {
            $q = Alumni::where('status', 'VERIFIED');
            if ($dept) { $q->whereHas('course', fn($c) => $c->where('college', $dept)); }
            return $q->distinct()->orderByDesc('batch')->pluck('batch')
                ->map(fn($b) => (string) $b)->toArray();
        });
    }

    /** True only once BOTH ends of the batch range are set — a half-picked
     *  range (only From, or only To) is not applied yet. */
    private function batchRangeIsComplete(): bool
    {
        return $this->batchYearFrom !== '' && $this->batchYearTo !== '';
    }

    private function normalizeBatchYearRange(): void
    {
        if ($this->batchYearFrom !== '' && $this->batchYearTo !== ''
            && (int) $this->batchYearFrom > (int) $this->batchYearTo) {
            [$this->batchYearFrom, $this->batchYearTo] = [$this->batchYearTo, $this->batchYearFrom];
        }
    }

    /** Single-year quick pick from the plain year list — sets both ends
     *  of the range to the same year in one round-trip. */
    public function setSingleBatchYear(string $year): void
    {
        $this->skipBatchYearHooks = true;
        $this->batchYearFrom = $year;
        $this->batchYearTo   = $year;
        $this->allAlumniChosen = false;
        $this->skipBatchYearHooks = false;
    }

    /** Explicit From/To range pick, applied together in one round-trip
     *  once both sides are chosen (see the "Apply" button in the picker). */
    public function setBatchRange(string $from, string $to): void
    {
        $this->skipBatchYearHooks = true;
        $this->batchYearFrom = $from;
        $this->batchYearTo   = $to;
        $this->allAlumniChosen = false;
        $this->skipBatchYearHooks = false;
        $this->normalizeBatchYearRange();
    }

    /** "All Alumni" — clears both ends of the range in one round-trip,
     *  same reasoning as setSingleBatchYear()/setBatchRange() above.
     *  Also flips allAlumniChosen so the trigger button can tell this
     *  apart from the never-picked-anything state. */
    public function chooseAllAlumniBatch(): void
    {
        $this->skipBatchYearHooks = true;
        $this->batchYearFrom = '';
        $this->batchYearTo   = '';
        $this->allAlumniChosen = true;
        $this->skipBatchYearHooks = false;
    }

    /** The "Clear" link next to the Batch Year label — a true reset back
     *  to the untouched/nothing-picked state. This is deliberately
     *  separate from chooseAllAlumniBatch(): both leave batchYearFrom/To
     *  empty, but only allAlumniChosen tells them apart, so Clear must
     *  turn that flag back off or clicking Clear while "All Alumni" was
     *  selected would look like it did nothing (still empty, still
     *  flagged as All Alumni). */
    public function clearBatchYear(): void
    {
        $this->skipBatchYearHooks = true;
        $this->batchYearFrom = '';
        $this->batchYearTo   = '';
        $this->allAlumniChosen = false;
        $this->skipBatchYearHooks = false;
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

        // ORGANIZER_DELETED events are excluded entirely — they no longer
        // appear in this list under any filter.
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

        $q->orderBy('created_at', 'desc');
        return $q->paginate(20);
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

    // ── Wraps the part of $text that matches the current search term in
    //    a light-blue <mark>, so the table visibly shows *why* a row
    //    matched instead of just filtering silently. $text is escaped
    //    first, so this is safe to output with {!! !!} in the view. ──
    public function highlightSearch(string $text): string
    {
        $term = trim($this->search);
        $escaped = e($text);
        if ($term === '') {
            return $escaped;
        }
        $pattern = '/(' . preg_quote(e($term), '/') . ')/i';
        return preg_replace($pattern, '<mark class="eo-search-hl">$1</mark>', $escaped);
    }

public function openCreateModal(): void
{
    if (!$this->hasAlumni) {
        $this->showNoAlumniModal = true;
        return;
    }
    $this->resetFormFields();
    $this->start_time     = '18:00'; // 6:00 PM default
    $this->end_time       = '23:59'; // 11:59 PM default
    $this->contact_person = $this->organizerName;
    $this->contact_email  = $this->organizerEmail;
    $this->isEditing      = false;
    $this->showFormModal  = true;
    $this->dispatch('close-sidebar');
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
        // Contact person name/email always reflect the organizer account (read-only)
        $this->contact_person   = $this->organizerName;
        $this->contact_email    = $this->organizerEmail;
        $this->contact_phone    = $event->contact_phone  ?? '';
        $this->notes            = $event->notes ?? '';
        $this->existingPhotoUrl = $event->photo_url;
        $this->removePhoto      = false;
        $this->photo            = null;
        $this->formErrors       = [];

        $tp    = $event->target_participants ?? '';
        $parts = explode(' · Batch ', $tp, 2);
        $coursesPart  = trim($parts[0] ?? '');
        $batchPart    = trim($parts[1] ?? '');

        // Batch part is either a single year ("2026") or a range
        // ("2021–2026") — split on the en-dash to tell them apart.
        if ($batchPart !== '' && str_contains($batchPart, '–')) {
            [$from, $to] = array_map('trim', explode('–', $batchPart, 2));
            $this->batchYearFrom = $from;
            $this->batchYearTo   = $to;
            $this->allAlumniChosen = false;
        } else {
            $this->batchYearFrom = $batchPart;
            $this->batchYearTo   = $batchPart;
            $this->allAlumniChosen = ($batchPart === '');
        }

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
        $this->dispatch('close-sidebar');
        return;
    }

    $this->isResubmitting = false;
    $this->populateEditForm($event);
    $this->showFormModal = true;
    $this->showViewModal = false;
    $this->dispatch('close-sidebar');
}

public function viewEvent(int $id): void
{
    // ── Ordinary entry point (table row click). Always resets the
    //    notif/deep-link flag to false first — viaNotifOpen() below is
    //    the only path that sets it true, and does so AFTER calling this,
    //    so a plain row click can never accidentally inherit a stale
    //    true left over from an earlier notif click this session. ──
    $this->viaNotifOrDeepLink = false;

    $event = OrganizerEvent::where('id', $id)->where('organizer_id', $this->organizerId)->firstOrFail();

    if ($event->status === 'PENDING') {
        $this->isResubmitting = false;
        $this->populateEditForm($event);
        $this->showFormModal = true;
        $this->showViewModal = false;
        $this->dispatch('close-sidebar');
        return;
    }

    if ($event->status === 'REJECTED') {
        $this->isResubmitting = true;
        $this->resubmitEventTitle   = $event->title;
        $this->resubmitEventRemarks = $event->review_remarks ?? '';
        $this->populateEditForm($event);
        $this->showFormModal = true;
        $this->showViewModal = false;
        $this->dispatch('close-sidebar');
        return;
    }

    $this->viewingEventId = $id;
    $this->showViewModal  = true;
    $this->dispatch('close-sidebar');
}

    // ── Delete: open confirm modal ── allowed for PENDING and REJECTED ──
    public function confirmDelete(int $id): void
    {
        $event = OrganizerEvent::where('id', $id)
            ->where('organizer_id', $this->organizerId)
            ->whereIn('status', ['PENDING', 'REJECTED'])
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
            ->whereIn('status', ['PENDING', 'REJECTED'])
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
                'description'   => "Organizer deleted event: '{$event->title}'.",
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'severity'      => 'warning',
            ]);
        } catch (\Throwable) {}

        // NO event-management-updated dispatch for delete — but DO fire the
        // self-notification bubble (mirrors job-self-action) so the
        // organizer sees "You Deleted an Event" in their own notif bell.
        $this->dispatch('event-self-action', id: $event->id, title: $event->title, action: 'deleted');

        $this->dispatch('flash-message', type: 'success', message: 'Event deleted.');

        $this->showDeleteModal    = false;
        $this->pendingDeleteId    = null;
        $this->pendingDeleteTitle = '';
    }

    public function resetForm(): void
    {
        $savedId         = $this->editingEventId;
        $savedIsEditing  = $this->isEditing;
        $savedIsResubmit = $this->isResubmitting;

        $this->resetFormFields();

        $this->start_time = '18:00'; // 6:00 PM default
        $this->end_time   = '23:59'; // 11:59 PM default

        $this->editingEventId = $savedId;
        $this->isEditing      = $savedIsEditing;
        $this->isResubmitting = $savedIsResubmit;

        $this->contact_person = $this->organizerName;
        $this->contact_email  = $this->organizerEmail;

        $this->dispatch('reset-time-selects');
    }

public function closeFormModal(): void
{
    // ── Auto-filter: same idea as closeViewModal() — after closing the
    //    edit/resubmit form for an existing event, snap the filter pill to
    //    that event's current status so the organizer lands back on the
    //    right slice of the table (e.g. resubmitting a REJECTED event
    //    turns it PENDING again, so the list auto-filters to "Pending").
    //    ONLY when the form was opened via a notification/deep-link — an
    //    ordinary row click (e.g. from "All Statuses") must leave the
    //    filter exactly as it was. ──
    if ($this->editingEventId && $this->viaNotifOrDeepLink) {
        $status = OrganizerEvent::where('id', $this->editingEventId)
            ->where('organizer_id', $this->organizerId)
            ->value('status');
        if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED'], true)) {
            $this->filterStatus = $status;
            $this->resetPage();
        }
    }
    $this->viaNotifOrDeepLink = false;

    $this->showFormModal = false;
    $this->resetFormFields();
    $this->dispatch('open-sidebar');
}
    // ── Runs full validation. If clean AND this is a brand-new (non-edit,
    //    non-resubmit) submission, opens the confirm modal instead of
    //    saving immediately. Editing/resubmitting saves straight away
    //    (no extra confirm — the "can't edit once approved" warning is
    //    only relevant the first time an event is submitted). ──
    public function requestSaveEvent(): void
    {
        if ($this->isEditing || $this->isResubmitting) {
            $this->saveEvent();
            return;
        }

        if (!$this->validateEventForm()) {
            return;
        }

        $this->showSubmitConfirmModal = true;
    }

    public function cancelSubmitConfirm(): void
    {
        $this->showSubmitConfirmModal = false;
    }

    public function confirmSubmitEvent(): void
    {
        $this->showSubmitConfirmModal = false;
        $this->saveEvent();
    }

    // ── Extracted validation so it can be called before showing the
    //    pre-submit confirm modal without duplicating saveEvent(). ──
    private function validateEventForm(): bool
    {
        $key = 'save_event_' . Auth::id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->formErrors = ['rate_limit' => 'Too many attempts. Please wait a moment before trying again.'];
            return false;
        }

        $this->formErrors = [];
        $errors = [];

        $this->contact_person = $this->organizerName;
        $this->contact_email  = $this->organizerEmail;

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

        // Batch year range validation. A half-picked range (only From or
        // only To) is treated the same as picking a single year on that
        // side — the picker itself never sends a half-picked range to the
        // server (see setSingleBatchYear()/setBatchRange() below), but we
        // still guard here in case both ends aren't in sync for any reason.
        $batchFrom = trim($this->batchYearFrom);
        $batchTo   = trim($this->batchYearTo);

        if ($batchFrom !== '' && !preg_match('/^\d{4}$/', $batchFrom)) {
            $errors['batch_year'] = 'Batch year must be a valid 4-digit year (numbers only, e.g. ' . now()->year . ').';
        }
        if ($batchTo !== '' && !preg_match('/^\d{4}$/', $batchTo)) {
            $errors['batch_year'] = 'Batch year must be a valid 4-digit year (numbers only, e.g. ' . now()->year . ').';
        }

        if (($batchFrom !== '' || $batchTo !== '') && !isset($errors['target']) && !isset($errors['batch_year'])) {
            $fromYear = (int) ($batchFrom !== '' ? $batchFrom : $batchTo);
            $toYear   = (int) ($batchTo   !== '' ? $batchTo   : $batchFrom);
            if ($fromYear > $toYear) { [$fromYear, $toYear] = [$toYear, $fromYear]; }

            $dept = $this->organizerDepartment;
            $q = Alumni::where('status', 'VERIFIED')
                ->where('batch', '>=', $fromYear)
                ->where('batch', '<=', $toYear);
            if ($dept) { $q->whereHas('course', fn($c) => $c->where('college', $dept)); }
            if (!$q->exists()) {
                $suggQ = Alumni::where('status', 'VERIFIED');
                if ($dept) { $suggQ->whereHas('course', fn($c) => $c->where('college', $dept)); }
                $available = $suggQ->distinct()->orderBy('batch', 'desc')
                    ->pluck('batch')->map(fn($b) => (int)$b)->toArray();
                $rangeLabel = $fromYear === $toYear ? (string) $fromYear : "{$fromYear}–{$toYear}";
                if (empty($available)) {
                    $errors['batch_year'] = "No verified alumni found for your college. Leave batch blank to target all alumni.";
                } else {
                    $nearest   = collect($available)->sortBy(fn($y) => abs($y - $fromYear))->first();
                    $batchList = implode(', ', array_slice($available, 0, 8));
                    if (count($available) > 8) $batchList .= '…';
                    $errors['batch_year'] = "No verified alumni for batch {$rangeLabel}."
                        . ($nearest ? " Nearest: {$nearest}." : '') . " Available: {$batchList}.";
                }
            }
        }

        if (!empty($errors)) { $this->formErrors = $errors; return false; }

        return true;
    }

    public function saveEvent(): void
    {
        $key = 'save_event_' . Auth::id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->formErrors = ['rate_limit' => 'Too many attempts. Please wait a moment before trying again.'];
            return;
        }
        RateLimiter::hit($key, 60);

        if (!$this->validateEventForm()) {
            return;
        }

        $courseStr = !empty($this->selectedCourses) ? implode(', ', $this->selectedCourses) : 'All Courses';

        $batchFrom = trim($this->batchYearFrom);
        $batchTo   = trim($this->batchYearTo);
        $yearSuffix = '';
        if ($batchFrom !== '' || $batchTo !== '') {
            $yearSuffix = $batchFrom === $batchTo
                ? ' · Batch ' . ($batchFrom !== '' ? $batchFrom : $batchTo)
                : ' · Batch ' . $batchFrom . '–' . $batchTo;
        }
        $targetStr = $courseStr . $yearSuffix;

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

        // ── Determine which actions dispatch a notif ──
        // ONLY 'updated' and 'resubmitted' dispatch — NOT 'created'
        $notifAction = $this->isResubmitting
            ? 'resubmitted'                         // resubmitted → notif (optional)
            : ($this->isEditing ? 'updated' : null); // updated → notif, created → NO notif

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

            if ($this->isResubmitting) {
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

            $msg = $this->isResubmitting
                ? 'Event resubmitted for Alumni Director review!'
                : 'Event updated successfully!';

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
        // NOT for created
        $savedEventId = $this->isEditing ? $this->editingEventId : null;
        if ($notifAction !== null) {
            $this->dispatch('event-management-updated', id: $savedEventId, title: trim($this->title), action: $notifAction);
        }

        // ── Self-notification bubble (organizer sees their own event
        //    actions) — mirrors job-self-action so create/update/resubmit
        //    all show up grouped in the organizer's own notif bell. ──
        if ($this->isEditing) {
            $selfAction  = $this->isResubmitting ? 'resubmitted' : 'updated';
            $selfEventId = $this->editingEventId;
        } else {
            $selfAction  = 'created';
            $selfEventId = OrganizerEvent::where('organizer_id', $this->organizerId)
                ->where('title', trim($this->title))
                ->orderByDesc('id')
                ->value('id');
        }
        $this->dispatch('event-self-action', id: $selfEventId, title: trim($this->title), action: $selfAction);

        // ── Resubmit resets the event's status-chain notif back to
        //    "Submitted → Pending", REPLACING whatever Approved/Rejected
        //    row was there before — matched by event_id (same column the
        //    director's approve/reject uses) so only 1 status row ever
        //    shows for this event. ──
        if ($this->isResubmitting && $selfEventId) {
            try {
                $dedupKey = 'event-management::resubmitted::' . $selfEventId;
                $existing = DB::table('coordinator_notifications')
                    ->where('user_id', Auth::id())
                    ->where('link_route', 'organizer.event/organizer')
                    ->where('event_id', $selfEventId)
                    ->first();

                $payload = [
                    'icon'       => 'rotate-right',
                    'title'      => 'Submitted → Pending',
                    'message'    => "You resubmitted '" . trim($this->title) . "' for Alumni Director review.",
                    'link_route' => 'organizer.event/organizer',
                    'link_label' => 'View Events',
                    'event_id'   => $selfEventId,
                    'dedup_key'  => $dedupKey,
                    'read'       => 0,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('coordinator_notifications')
                        ->where('id', $existing->id)
                        ->update($payload + ['created_at' => now()]);
                } else {
                    DB::table('coordinator_notifications')->insert($payload + [
                        'user_id'    => Auth::id(),
                        'created_at' => now(),
                    ]);
                }
            } catch (\Throwable) {}
        }

Cache::forget('organizer_has_alumni_' . ($this->organizerDepartment ?: 'all'));
        $this->showFormModal = false;
        $this->resetFormFields();
        $this->dispatch('open-sidebar');
    }

   public function closeViewModal(): void
{
    // ── Auto-filter: after closing View Details, snap the table's filter
    //    pill to whatever status this event is currently sitting at (e.g.
    //    closing a COMPLETED event's details auto-filters the list to
    //    "Completed") so the organizer lands right back on the relevant
    //    slice of the table instead of the unfiltered/previous view.
    //    ONLY when the modal was opened via a notification/deep-link —
    //    an ordinary row click (e.g. from "All Statuses") must leave the
    //    filter exactly as it was. ──
    if ($this->viewingEventId && $this->viaNotifOrDeepLink) {
        $status = OrganizerEvent::where('id', $this->viewingEventId)
            ->where('organizer_id', $this->organizerId)
            ->value('status');
        if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED'], true)) {
            $this->filterStatus = $status;
            $this->resetPage();
        }
    }
    $this->viaNotifOrDeepLink = false;

    $this->showViewModal = false;
    $this->viewingEventId = null;
    $this->dispatch('open-sidebar');
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

        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                       (!$event->event_end_date && $event->event_date <= now('UTC'));

        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');

        $this->shareEventId          = $id;
        $this->shareEventType        = 'ORGANIZER';
        $this->shareEventTitle       = $event->title;
        $this->shareEventVenue       = $event->venue ?? '';
        $this->shareEventDate        = $eventDatePH->format('F d, Y');
        $this->shareEventTime        = $eventDatePH->format('g:i A');
        $this->shareEventEndTime     = $eventEndPH ? $eventEndPH->format('g:i A') : '';
        $this->shareEventDescription = $event->description ?? '';
        $this->shareEventNotes       = $event->notes ?? '';
        $this->shareEventTargetParts = $event->target_participants ?? '';
        $this->shareEventPhotoUrl    = $event->photo_url ?? '';
        $this->shareEventOrganizer   = $this->organizerName ?: 'Organizer';
        $this->shareEventIsCompleted = $isCompleted;

        $this->loadShareableRooms();
        $this->computeAutoSelectedShareRooms();

        $this->showShareModal = true;
        // NOTE: intentionally NOT closing showViewModal here — opening the
        // Share modal from within View Details should keep the View Details
        // screen mounted underneath (per request #5). Previously this line
        // set showViewModal = false, which caused the view to "go back"
        // as soon as Share was clicked.
    }

    /**
     * ── Auto-select the chats that match this event's target audience ──
     * Parses target_participants (e.g. "BSIT, BSCS · Batch 2026" / "All
     * Courses · Batch 2026" / "All Courses") the same way populateEditForm()
     * does, then pre-checks the matching room(s) so the organizer doesn't
     * have to hunt for the right batch GC every time — Batch 2026 event ⇒
     * every targeted course's "Batch 2026" GC gets auto-ticked. Staff Chat
     * is never auto-selected. The organizer can still freely check/uncheck
     * before hitting Share.
     */
    private function computeAutoSelectedShareRooms(): void
    {
        $tp    = $this->shareEventTargetParts;
        $parts = explode(' · Batch ', $tp, 2);
        $coursesPart = trim($parts[0] ?? '');
        $batchPart   = trim($parts[1] ?? '');
        $courseCodes = (!empty($coursesPart) && $coursesPart !== 'All Courses')
            ? array_map('trim', explode(',', $coursesPart))
            : [];

        // Batch part is either a single year ("2026") or a range
        // ("2021–2026") — expand a range into the full list of years it
        // covers so every matching batch GC within the range gets
        // auto-ticked, not just the endpoints.
        $batchYears = [];
        if ($batchPart !== '') {
            if (str_contains($batchPart, '–')) {
                [$rFrom, $rTo] = array_map('trim', explode('–', $batchPart, 2));
                $rFrom = (int) $rFrom; $rTo = (int) $rTo;
                if ($rFrom > $rTo) { [$rFrom, $rTo] = [$rTo, $rFrom]; }
                for ($y = $rFrom; $y <= $rTo; $y++) { $batchYears[] = (string) $y; }
            } else {
                $batchYears[] = $batchPart;
            }
        }

        $this->shareTargetBatchYear   = $batchPart;
        $this->shareTargetCourseCodes = $courseCodes;

        $matched = [];
        foreach ($this->shareAvailableRooms as $r) {
            if (($r['type'] ?? '') === 'staff') continue;

            $roomCourse = strtoupper((string) ($r['course_code'] ?? ''));
            $inTarget   = empty($courseCodes) || in_array($roomCourse, array_map('strtoupper', $courseCodes), true);

            if (!empty($batchYears)) {
                // Specific batch(es) targeted — only those batch GC(s) qualify.
                if ($r['type'] === 'batch' && in_array((string) $r['batch'], $batchYears, true) && $inTarget) {
                    $matched[] = (string) $r['id'];
                }
            } else {
                // No specific batch — "All Batches" GC for targeted courses,
                // or the college-wide room when every course is targeted.
                if (!empty($courseCodes) && $r['type'] === 'course' && $inTarget) {
                    $matched[] = (string) $r['id'];
                } elseif (empty($courseCodes) && $r['type'] === 'college') {
                    $matched[] = (string) $r['id'];
                }
            }
        }

        $this->shareAutoRoomIds   = $matched;
        $this->shareTargetRoomIds = $matched;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal        = false;
        $this->shareEventId          = null;
        $this->shareEventTitle       = '';
        $this->shareEventVenue       = '';
        $this->shareEventDate        = '';
        $this->shareEventTime        = '';
        $this->shareEventEndTime     = '';
        $this->shareEventDescription = '';
        $this->shareEventNotes       = '';
        $this->shareEventOrganizer   = '';
        $this->shareEventTargetParts = '';
        $this->shareEventPhotoUrl    = '';
        $this->shareEventIsCompleted = false;
        $this->shareAvailableRooms   = [];
        $this->shareTargetRoomIds    = [];
        $this->shareAutoRoomIds      = [];
        $this->shareTargetBatchYear    = '';
        $this->shareTargetCourseCodes  = [];
    }

    // ── "Share to Message Hub" — posts the event recap as a chat
    //    message into every chat room the organizer picks (checkbox list,
    //    with Select All). Mirrors the same chat_rooms/chat_messages
    //    tables used by the organizer's Message Hub (chat-alumni.blade). ──
    private function loadShareableRooms(): void
    {
        $dept = $this->organizerDepartment;

        $rooms = [];

        // Staff Chat (directors + coordinators)
        $staffRoom = DB::table('chat_rooms')->where('course_code', '__director__')->first(['id']);
        if ($staffRoom) {
            $rooms[] = [
                'id' => (int) $staffRoom->id, 'label' => 'Staff Chat', 'type' => 'staff',
                'course_code' => '__director__', 'batch' => 0, 'department' => '',
            ];
        }

        if ($dept) {
            // College-wide room
            $collegeRoom = DB::table('chat_rooms')
                ->where('department', $dept)
                ->where('batch', 0)
                ->where(function ($q) use ($dept) {
                    $q->where('course_code', '')->orWhere('course_code', 'like', 'CLG_%');
                })
                ->first(['id', 'course_code']);
            if ($collegeRoom) {
                $rooms[] = [
                    'id' => (int) $collegeRoom->id, 'label' => $dept . ' · College-Wide', 'type' => 'college',
                    'course_code' => $collegeRoom->course_code, 'batch' => 0, 'department' => $dept,
                ];
            }

            $deptCourseCodes = DB::table('courses')->where('college', $dept)->pluck('code')->toArray();

            if (!empty($deptCourseCodes)) {
                // Course "All Batches" GCs
                $courseRooms = DB::table('chat_rooms')
                    ->whereIn('course_code', $deptCourseCodes)
                    ->where('batch', 0)
                    ->get(['id', 'course_code']);

                foreach ($courseRooms as $r) {
                    $courseName = DB::table('courses')->where('code', $r->course_code)->value('name') ?? strtoupper($r->course_code);
                    $rooms[] = [
                        'id' => (int) $r->id, 'label' => strtoupper($r->course_code) . ' · All Batches', 'type' => 'course',
                        'course_code' => $r->course_code, 'batch' => 0, 'department' => $dept,
                    ];
                }

                // Per-batch GCs
                $batchRooms = DB::table('chat_rooms')
                    ->whereIn('course_code', $deptCourseCodes)
                    ->where('batch', '>', 0)
                    ->orderBy('course_code')->orderByDesc('batch')
                    ->get(['id', 'course_code', 'batch', 'name']);

                foreach ($batchRooms as $r) {
                    $rooms[] = [
                        'id' => (int) $r->id, 'label' => $r->name ?: (strtoupper($r->course_code) . ' · Batch ' . $r->batch), 'type' => 'batch',
                        'course_code' => $r->course_code, 'batch' => (int) $r->batch, 'department' => $dept,
                    ];
                }
            }
        }

        $this->shareAvailableRooms = $rooms;
    }

    public function toggleSelectAllShareRooms(): void
    {
        $allIds = collect($this->shareAvailableRooms)->pluck('id')->map(fn ($id) => (string) $id)->toArray();

        // If everything is already selected, uncheck all. Otherwise select all.
        $allSelected = !empty($allIds) && empty(array_diff($allIds, $this->shareTargetRoomIds));

        $this->shareTargetRoomIds = $allSelected ? [] : $allIds;
    }

    public function shareToOrganizerUpdates(): void
    {
        if (empty($this->shareTargetRoomIds)) {
            $this->dispatch('flash-message', type: 'error', message: 'Select at least one chat to share to.');
            return;
        }

        if (!$this->shareEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Nothing to share — please reopen the Share window.');
            return;
        }

        // ── Card marker only — the chat views (chat-alumni.blade.php /
        //    director-messenger.blade.php) resolve [[EVENT:TYPE:id]] into a
        //    rich preview card (photo, title, date, View Event button)
        //    instead of a wall of plain "EVENT HIGHLIGHTS" text. ──────────
        $body = '[[EVENT:' . $this->shareEventType . ':' . $this->shareEventId . ']]';

        $roomsById     = collect($this->shareAvailableRooms)->keyBy(fn ($r) => (string) $r['id']);
        $validRoomIds  = $roomsById->keys()->toArray();
        $targetIds     = array_values(array_intersect($this->shareTargetRoomIds, $validRoomIds));

        if (empty($targetIds)) {
            $this->dispatch('flash-message', type: 'error', message: 'Select at least one chat to share to.');
            return;
        }

        $now = now();
        foreach ($targetIds as $roomId) {
            DB::table('chat_messages')->insert([
                'room_id'     => (int) $roomId,
                'sender_type' => 'organizer',
                'sender_id'   => $this->organizerId,
                'body'        => $body,
                'reply_to_id' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            // Notify the alumni who belong to this room so their unread
            // "red dot" lights up right away, same as a normal chat send
            // does — this used to be skipped entirely since this method
            // inserted straight into chat_messages with no side effects.
            $room = $roomsById->get((string) $roomId);
            if ($room) {
                $this->notifyAlumniOfSharedEvent((int) $roomId, $room);
            }
        }

        $count = count($targetIds);
        $this->dispatch('flash-message', type: 'success', message: 'Shared to ' . $count . ' chat' . ($count === 1 ? '' : 's') . '.');

        // Close the whole Share Event modal right after a successful share
        // — no need to make the organizer manually close it.
        $this->closeShareModal();
    }

    /**
     * ── Alumni notification for a shared event card — mirrors
     *    organizer/chat-alumni.blade.php's notifyAlumniInRoom(), but keyed
     *    off an arbitrary target room (this method shares to many rooms in
     *    one go, not just "the currently open room"). Staff Chat has no
     *    alumni members, so it's skipped. ──────────────────────────────────
     */
    private function notifyAlumniOfSharedEvent(int $roomId, array $room): void
    {
        if (($room['type'] ?? '') === 'staff') return;

        try {
            $courseCode = $room['course_code'] ?? '';
            $batch      = (int) ($room['batch'] ?? 0);
            $dept       = $room['department'] ?? $this->organizerDepartment;

            if (($room['type'] ?? '') === 'college') {
                $deptCourseCodes = DB::table('courses')->where('college', $dept)->pluck('code')->toArray();
                $alumniRows = empty($deptCourseCodes) ? collect() : DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->whereIn('course_code', $deptCourseCodes)
                    ->get(['id']);
            } elseif (($room['type'] ?? '') === 'course') {
                $alumniRows = DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->where('course_code', $courseCode)
                    ->get(['id']);
            } else {
                $alumniRows = DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->where('course_code', $courseCode)
                    ->where('batch', $batch)
                    ->get(['id']);
            }

            $senderName = $this->shareEventOrganizer ?: ($this->organizerName ?: 'Organizer');
            $title      = $senderName . ' shared an event';
            $message    = $senderName . ' shared "' . $this->shareEventTitle . '" in ' . ($room['label'] ?? 'a group chat') . '.';
            $dedupKey   = 'coord-event-share::' . $this->organizerId . '::' . $roomId . '::' . $this->shareEventId . '::' . floor(time() / 60);

            foreach ($alumniRows as $alumnus) {
                $alreadyExists = DB::table('alumni_notifications')
                    ->where('alumni_id', (int) $alumnus->id)
                    ->where('dedup_key', $dedupKey)
                    ->exists();

                if ($alreadyExists) continue;

                DB::table('alumni_notifications')->insert([
                    'alumni_id'  => (int) $alumnus->id,
                    'icon'       => 'comments',
                    'title'      => $title,
                    'message'    => $message,
                    'link_route' => 'alumni.messenger',
                    'link_label' => 'Open Messenger',
                    'dedup_key'  => $dedupKey,
                    'read'       => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Notification is best-effort — a failure here must never
            // block the actual share from succeeding.
        }
    }

    public function eventsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('upcoming.events', [], false); } catch (\Throwable) { $path = '/upcoming/events'; }
        return $base . $path;
    }

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_phone = $this->notes = '';
        $this->contact_person = '';
        $this->contact_email  = '';
        $this->batchYearFrom  = '';
        $this->batchYearTo    = '';
        $this->allAlumniChosen = false;
        $this->selectedCourses = [];
        $this->photo          = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto    = false;
        $this->formErrors     = [];
        $this->editingEventId = null;
        $this->isEditing      = false;
        $this->isResubmitting = false;
        $this->resubmitEventTitle   = '';
        $this->resubmitEventRemarks = '';
        $this->showSubmitConfirmModal = false;
    }
};
?>

{{-- wire:poll keeps this list + the open View modal "live" — same 3s
     cadence as the notif bell's chat poller, so an Approve/Reject/Resubmit
     done elsewhere (e.g. by the Alumni Director) shows up here without a
     manual refresh. Computed props (events, viewingEvent) re-evaluate on
     every poll tick automatically. --}}
<div class="flex flex-col" wire:poll.3000ms
     style="height: calc(100vh - 180px); max-height: calc(100vh - 180px); overflow: hidden;">

<style>
/* ── No copy/select on the Event Management list page (header, filters,
   table rows, pagination) — same behavior as the dashboards. Scoped to
   .eo-noselect only, so the View Details full-screen modal (rendered
   outside this wrapper) stays normally selectable/copyable. ── */
.eo-noselect,
.eo-noselect * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}
/* Inputs/textareas inside filters still need normal text interaction
   (typing, cursor, native selection while editing). */
.eo-noselect input,
.eo-noselect textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* ── Search match highlight — light blue, shows which part of the row
   text matched the current search term (title/venue). ── */
.eo-search-hl {
    background: #DBEAFE;
    color: #1e3a8a;
    padding: 0 2px;
    border-radius: 3px;
    font-weight: inherit;
}

@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes eoImgShimmer {
    0%   { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
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

/* ── Upcoming indicator badge ── */
.eo-upcoming-badge {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 9px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase;
    padding: 2px 6px; border-radius: 999px;
    background: #ECFDF5; color: #059669; border: 1px solid #6ee7b7;
    white-space: nowrap;
}

/* ── Tooltips: the row action tooltip (#eo-hover-tip) now stays visible
   on all devices — it no longer needs "hover: hover" support, since the
   JS below shows it on tap/touch too, not just mouse hover. Other
   hover-only tooltips (submit/reset/close buttons, share/close tooltips
   inside the view modal, etc.) still hide on touch/small screens, since
   those still depend on a real mouse hover to reveal. ── */
@media (max-width: 768px), (hover: none) {
    [class*="group-hover:opacity-100"] { display: none !important; }
}

/* ── Share modal: full screen on mobile, centered card on desktop ── */
@media (max-width: 767px) {
    .eo-share-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
    }
    .eo-share-backdrop .eo-share-sheet {
        border-radius: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
    }
}

/* ── Share modal close button (mirrors upcoming-events share-close-btn,
     with the SVG X + hover tooltip instead of a plain "X" text glyph) ── */
.eo-share-close-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    cursor: pointer; transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
}
.eo-share-close-btn:hover  { background: #e5e7eb; border-color: #d1d5db; }
.eo-share-close-btn:active { transform: scale(.93); }
.eo-share-close-btn svg    { width: 14px; height: 14px; stroke: #4b5563; stroke-width: 2.25; stroke-linecap: round; }
.eo-share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.eo-share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.eo-share-close-btn:hover .tip { opacity: 1; }

/* ── Simplified share option row — icon + label only (mirrors upcoming-events) ── */
.eo-share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .12s ease-out, transform .1s ease-out; border: none;
    will-change: transform;
}
.eo-share-option-btn:hover  { filter: brightness(0.94); }
.eo-share-option-btn:active { transform: scale(.97); transition-duration: .05s; }
.eo-share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.eo-share-option-btn .label-text { flex: 1; text-align: left; }

.eo-share-photo-preview {
    width: 100%;
    height: 140px;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}
.eo-share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain;
}
.eo-share-photo-preview .dl-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(17,24,39,.75); color: #fff;
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px;
    display: flex; align-items: center; gap: 4px;
    pointer-events: none;
}

.eo-dl-confirm-icon {
    width: 3rem; height: 3rem; border-radius: 0.9rem;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.eo-dl-confirm-btn {
    flex: 1; padding: 0.65rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .15s, transform .1s; border: none;
}
.eo-dl-confirm-btn:active { transform: scale(.97); }
.eo-dl-confirm-btn.primary { background: #7a3f91; color: #fff; }
.eo-dl-confirm-btn.primary:hover { filter: brightness(0.95); }
.eo-dl-confirm-btn.secondary { background: #f3f4f6; color: #333333; border: 1px solid #e5e7eb; }
.eo-dl-confirm-btn.secondary:hover { background: #e5e7eb; }

/* ── Pagination bar: purple gradient style mirroring upcoming-events ── */
.eo-pagination-bar {
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    border-top: 1px solid rgba(122,63,145,.3);
}

/* ── Event Details / Additional Notes tables (View Details screen) ──
     Used when the About/Notes content is long: renders as a bordered
     table with a capped, vertically scrollable body instead of an
     unbounded block of text. ── */
.eo-detail-table-wrap {
    max-height: none;
    min-height: 0;
    overflow-y: auto;
}
.eo-detail-table {
    width: 100%;
    border-collapse: collapse;
}
.eo-detail-table td {
    padding: 14px 18px;
    font-size: 0.95rem;
    line-height: 1.8;
    color: #333333;
    font-weight: 500;
    white-space: pre-wrap;
    vertical-align: top;
    border: none;
}

/* ── "All Batches" pill — gray border added per request ── */
.eo-all-batches-pill {
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.25rem 0.5rem;
    display: inline-block;
}

/* ── View Details: mobile scroll fix ──
     On mobile the whole right-panel content area (Responses + About +
     Notes) scrolls as ONE natural-height column instead of each card
     fighting for its own flex-1/min-h-0 scroll region (which caused the
     stuck/non-scrolling behaviour on phones). Desktop (lg:) keeps the
     original independently-scrolling two-column layout. ── */
@media (max-width: 1023px) {
    .eo-view-right-scroll {
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch;
    }
    .eo-view-detail-card {
        flex: none !important;
        min-height: 0 !important;
    }
    .eo-detail-table-wrap {
        flex: none !important;
        max-height: none !important;
        overflow-y: visible !important;
    }
}

/* ── Batch Year dropdown picker (Event form) — same look as the Batch
   filter dropdown in Alumni Records, scoped under eo- so it doesn't
   collide with that page's ar- classes. ── */
.eo-batch-dropdown { position: relative; }
.eo-batch-trigger {
    display: flex; align-items: center; gap: 6px; width: 100%;
    padding: 8px 11px; border: 1.5px solid #E8E0F0; border-radius: 10px;
    font-size: .875rem; font-weight: 600; background: #fff; color: #333;
    cursor: pointer; transition: border-color .15s, background .15s, color .15s;
    white-space: nowrap; user-select: none;
}
.eo-batch-trigger:hover { border-color: #c49ed8; }
.eo-batch-trigger.has-value { border-color: #7a3f91; background: #F9F7FC; color: #7a3f91; }
.eo-batch-trigger .eo-batch-chevron { transition: transform .18s; font-size: .65rem; opacity: .6; }
.eo-batch-trigger.open .eo-batch-chevron { transform: rotate(180deg); }
.eo-batch-menu {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 220px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 500; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.eo-batch-menu::-webkit-scrollbar { width: 5px; }
.eo-batch-menu::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }
.eo-batch-footer {
    position: sticky; bottom: -4px; left: 0; right: 0;
    background: #fff; margin: 0 -4px -4px; padding: 8px 4px 4px;
    border-top: 1px solid #E8E0F0; border-radius: 0 0 8px 8px;
}
.eo-batch-item {
    display: block; width: 100%; padding: 7px 10px; border-radius: 7px;
    font-size: .8rem; font-weight: 600; text-align: left; color: #333;
    transition: background .1s; cursor: pointer; white-space: nowrap;
    border: none; background: transparent;
    user-select: none; -webkit-user-select: none;
}
.eo-upload-spinner {
    width: 28px; height: 28px; border-radius: 50%;
    border: 3px solid #E8D9F2; border-top-color: #7a3f91;
    animation: eo-spin .7s linear infinite;
}
@keyframes eo-spin { to { transform: rotate(360deg); } }
.eo-batch-item:hover { background: #F5F0FA; color: #7a3f91; }
.eo-batch-item.active { background: #F0E6F8; color: #7a3f91; }
.eo-batch-range-item.active {
    background: #7a3f91 !important;
    color: #ffffff !important;
    font-weight: 700;
}
.eo-batch-range-item.active:hover { background: #6B3680 !important; color: #ffffff !important; }
.eo-batch-range-item.disabled,
.eo-batch-range-item:disabled {
    color: #C9C9C9 !important;
    cursor: not-allowed !important;
    background: transparent !important;
}
.eo-batch-range-item.disabled:hover,
.eo-batch-range-item:disabled:hover {
    background: transparent !important;
    color: #C9C9C9 !important;
}
</style>

{{-- Hover tooltip (desktop only — hidden on mobile via CSS above) --}}
<div id="eo-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5" id="eo-hover-tip-icon"></i><span id="eo-hover-tip-text">View Details</span>
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
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0 eo-noselect">

    {{-- ══ PAGE HEADER (matches Dashboard placement — icon + title on the left) ══ --}}
    <div class="eo-page-header-noselect flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-calendar-days text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Event Management</h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
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
                        wire:loading.attr="disabled" wire:target="openCreateModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] {{ !$this->hasAlumni ? 'opacity-60 cursor-not-allowed' : '' }}">
                    <i class="fas fa-plus text-sm" wire:loading.remove wire:target="openCreateModal"></i>
                    <i class="fas fa-spinner fa-spin text-sm" wire:loading wire:target="openCreateModal"></i>
                </button>
                <div class="absolute top-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                    <i class="fas fa-plus text-[9px] mr-1"></i>Submit Event
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#1a1a1a]"></span>
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

    {{-- ══ UNIFIED TABLE BLOCK (fixed-height card, scrolls internally — same pattern as Alumni Records) ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-1 min-h-0">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search..."
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
            </select>

            @if($filterStatus)
            @php
                $pillMap = [
                    'PENDING'   => ['label' => 'Pending',   'cls' => 'bg-yellow-50 border-yellow-300 text-yellow-800'],
                    'APPROVED'  => ['label' => 'Approved',  'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'REJECTED'  => ['label' => 'Rejected',  'cls' => 'bg-orange-50 border-orange-300 text-orange-800'],
                    'COMPLETED' => ['label' => 'Completed', 'cls' => 'bg-green-50 border-green-300 text-green-800'],
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

            {{-- Reset — pushed to the far end of the filter bar (ml-auto),
                 away from the search/status controls it acts on, so it
                 reads as a distinct "clear everything" action rather than
                 sitting in the middle of the filter controls. --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    @if(!$search && !$filterStatus) disabled @endif
                    class="ml-auto inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 cursor-pointer
                           disabled:pointer-events-none disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-white">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ── TABLE WRAPPER (fixed-height card, scrolls internally — same pattern as Alumni Records) ── --}}
        <div class="relative flex-1 min-h-0 bg-white">

            {{-- Centered loading spinner — big icon over the table itself,
                 same pattern as the alumni-facing yearbook, instead of only
                 the thin progress bar in the filter strip. --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="search,filterStatus,resetFilters,previousPage,nextPage,gotoPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            <div id="eo-table-scroll"
                 class="scroll-c h-full overflow-y-auto overflow-x-auto bg-white">

            @if($this->events->count() > 0)

                <table class="w-full bg-white border-collapse">
                    <thead class="bg-white sticky top-0 z-10 border-b border-[#E8E0F0]">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Event Title</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Date &amp; Time</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Program</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell text-[#555555]">Batch</th>
                            <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-widest w-28 text-[#555555]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5] transition-opacity duration-200"
                           wire:loading.class="opacity-50" wire:target="search,filterStatus,resetFilters,previousPage,nextPage,gotoPage">
                        @foreach($this->events as $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $isEditable  = $isPending || $isRejected;

                            $tp          = $event->target_participants ?? '';
                            $tpParts     = explode(' · Batch ', $tp, 2);
                            $displayCourses = trim($tpParts[0]) ?: ($this->organizerDepartment ?: 'All Courses');
                            $batchDisplay   = !empty($tpParts[1]) ? trim($tpParts[1]) : null;

                            $eventDate  = $event->event_date->setTimezone('Asia/Manila');
                            $isUpcoming = $isApproved && $eventDate->isFuture();
                        @endphp

                        <tr class="transition-colors duration-100 cursor-pointer bg-white hover:bg-[#f5f0fa]"
                            wire:click="viewEvent({{ $event->id }})"
                            wire:key="event-row-{{ $event->id }}"
                            data-eo-row
                            data-eo-row-editable="{{ $isEditable ? '1' : '0' }}">

                            <td class="px-4 py-2.5">
                                <div class="max-w-[240px]">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333]">{!! $this->highlightSearch($event->title) !!}</p>
                                        @if($isUpcoming)
                                            <span class="eo-upcoming-badge"><i class="fas fa-circle text-[6px]"></i>Upcoming</span>
                                        @endif
                                    </div>
                                    <p class="text-xs mt-0.5 text-[#666666]">{{ $eventDate->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-2.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold text-[#333333]">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5 text-[#555555]">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 py-2.5 hidden lg:table-cell">
                                <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 w-fit max-w-[160px] truncate block">
                                    {{ Str::limit($displayCourses, 24) }}
                                </span>
                            </td>

                            <td class="px-4 py-2.5 hidden xl:table-cell">
                                @if($batchDisplay)
                                    <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-gray-100 border border-gray-200 w-fit text-[#333333] inline-block">
                                        Batch {{ $batchDisplay }}
                                    </span>
@else
    <span class="text-xs text-[#333333] font-bold eo-all-batches-pill">All Batches</span>
@endif
                            </td>

                            <td class="px-4 py-2.5 text-center">
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
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>

                                    @if($isApproved || $isCompleted)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button"
                                                    wire:click.stop="openShareModal({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="openShareModal({{ $event->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition-all duration-150 active:scale-[.92] cursor-pointer
                                                           bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $event->id }})"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Share
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
                                    @endif

                                    @if($isPending || $isRejected)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button"
                                                    wire:click.stop="confirmDelete({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="confirmDelete({{ $event->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 hover:border-red-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-trash-can" wire:loading.remove wire:target="confirmDelete({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmDelete({{ $event->id }})"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Delete
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

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
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
        </div>

        {{-- ── PAGINATION (purple gradient bar style, mirrors upcoming-events) ── --}}
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
        <div class="eo-pagination-bar flex-shrink-0 px-4 flex items-center justify-between gap-2 flex-wrap min-h-[48px] py-1">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                event{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $search)
                    <span class="text-white/60 text-xs ml-1">(filtered)</span>
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
                    <button wire:click="gotoPage(1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $lp }})"
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
                <span class="text-xs text-amber-800">This action cannot be undone. The event will be permanently marked as deleted.</span>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelDelete"
                        wire:loading.attr="disabled" wire:target="deleteEvent"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                </button>
                <button wire:click="deleteEvent"
                        wire:loading.attr="disabled"
                        wire:target="deleteEvent"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-60 disabled:cursor-wait">
                    <span wire:loading wire:target="deleteEvent"><i class="fas fa-spinner fa-spin mr-1 text-xs"></i>Deleting…</span>
                    <span wire:loading.remove wire:target="deleteEvent"><i class="fas fa-trash-can mr-1 text-xs"></i>Yes, Delete</span>
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


{{-- ══ PRE-SUBMIT CONFIRM MODAL (create only) — warns that the event
     can no longer be edited once it is approved by the Alumni Director. ══ --}}
@if($showSubmitConfirmModal)
<div class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelSubmitConfirm">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b border-amber-100 bg-amber-50">
            <h2 class="text-lg font-semibold text-amber-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-triangle-exclamation text-amber-500 text-base"></i>
                </div>
                Confirm Submission
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-base text-[#555555] mb-1">You are about to submit:</p>
            <p class="font-semibold text-[#333333] text-base mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $title }}
            </p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-lock text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                <span class="text-sm text-amber-800">Once this event is approved by the Alumni Director, it can no longer be edited. Please review all details carefully before proceeding.</span>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelSubmitConfirm"
                        wire:loading.attr="disabled" wire:target="confirmSubmitEvent"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-base font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                    <i class="fas fa-xmark mr-1 text-sm"></i>Review Again
                </button>
                <button wire:click="confirmSubmitEvent"
                        wire:loading.attr="disabled"
                        wire:target="confirmSubmitEvent"
                        class="flex-1 px-4 py-2.5 rounded-xl text-base font-semibold text-white bg-[#7a3f91] hover:bg-[#5e2f72] transition cursor-pointer disabled:opacity-60 disabled:cursor-wait">
                    <span wire:loading wire:target="confirmSubmitEvent"><i class="fas fa-spinner fa-spin mr-1 text-sm"></i>Submitting…</span>
                    <span wire:loading.remove wire:target="confirmSubmitEvent"><i class="fas fa-paper-plane mr-1 text-sm"></i>Yes, Submit</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ CREATE / EDIT / RESUBMIT — FULL SCREEN ══ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeFormModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3 flex-shrink-0 shadow-lg"
         style="background: {{ $isResubmitting ? '#d97706' : '#7a3f91' }};">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                @if($isResubmitting)
                    <i class="fas fa-rotate-right text-white text-base"></i>
                @elseif($isEditing)
                    <i class="fas fa-pen-to-square text-white text-base"></i>
                @else
                    <i class="fas fa-calendar-plus text-white text-base"></i>
                @endif
            </div>
            <div>
                <h2 class="text-white font-semibold text-xl leading-tight">
                    @if($isResubmitting) Edit &amp; Resubmit Event
                    @elseif($isEditing) Edit Event
                    @else Submit a New Event
                    @endif
                </h2>
                <p class="text-white/60 text-sm mt-0.5">
                    @if($isResubmitting) Make changes — saving will resubmit for Alumni Director review
                    @elseif($isEditing) Update event details below
                    @else Fill in details — will be sent for Alumni Director review
                    @endif
                </p>
            </div>
        </div>

        <div class="flex items-center gap-1.5">
            @if(!$isEditing && !$isResubmitting)
            <div class="relative inline-flex group">
                <button wire:click="resetForm" type="button"
                        wire:loading.attr="disabled" wire:target="resetForm"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:opacity-60 disabled:cursor-wait"
                        aria-label="Reset form">
                    <i class="fas fa-rotate-left text-white text-base" wire:loading.remove wire:target="resetForm"></i>
                    <i class="fas fa-spinner fa-spin text-white text-base" wire:loading wire:target="resetForm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-xs font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Reset
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
            @endif
            @if(($isEditing || $isResubmitting) && $editingEventId)
            <div class="relative inline-flex group">
                <button wire:click="confirmDelete({{ $editingEventId }})" type="button"
                        wire:loading.attr="disabled" wire:target="confirmDelete({{ $editingEventId }})"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:opacity-60 disabled:cursor-wait"
                        aria-label="Delete event">
                    <i class="fas fa-trash-can text-white text-base" wire:loading.remove wire:target="confirmDelete({{ $editingEventId }})"></i>
                    <i class="fas fa-spinner fa-spin text-white text-base" wire:loading wire:target="confirmDelete({{ $editingEventId }})"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-xs font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Delete
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
            @endif
            <div class="relative inline-flex group">
                <button wire:click="closeFormModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeFormModal"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-base" wire:loading.remove wire:target="closeFormModal"></i>
                    <i class="fas fa-spinner fa-spin text-white text-base" wire:loading wire:target="closeFormModal"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-xs font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    @if($isResubmitting)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 flex-shrink-0 flex items-start gap-3">
        <i class="fas fa-rotate-right text-amber-500 flex-shrink-0 text-sm mt-1"></i>
        <div class="flex-1 min-w-0">
            <p class="text-base text-[#333333]">
                <strong>Resubmitting:</strong> Edit the details and click <strong>Save &amp; Resubmit</strong> to send back for Alumni Director approval.
            </p>
            @if($resubmitEventRemarks)
            <p class="text-sm mt-1 text-red-700 flex items-center gap-1.5">
                <i class="fas fa-circle-xmark text-red-400 text-xs flex-shrink-0"></i>
                <strong>Rejection reason:</strong> {{ $resubmitEventRemarks }}
            </p>
            @endif
        </div>
    </div>
    @endif

    {{-- ══ ROW: on mobile it stacks vertically and the WHOLE row scrolls together
         (no more per-column overflow-hidden clipping that was hiding Description/Notes),
         on desktop (lg:) it goes back to fixed-height side-by-side columns each
         scrolling independently, same as before. ══ --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden">

        {{-- LEFT COLUMN --}}
        <div class="w-full lg:w-72 xl:w-76 flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-visible lg:overflow-y-auto bg-white"
             style="scrollbar-width:thin;">
            <div class="p-3 space-y-3">

                {{-- Event Photo — with live preview, fully white background.
                     Default state shows the actual default event photo from
                     public/storage/event/default-photo-event.jpg, verified
                     server-side with file_exists() so it only renders the
                     <img> when the file is truly there — falling back to an
                     inline SVG placeholder (not a separate image file) if
                     it's ever missing, so something always displays. ── --}}
                @php
                    $defaultPhotoRelPath = 'storage/event/default-photo-event.jpg';
                    $defaultPhotoExists  = file_exists(public_path($defaultPhotoRelPath));
                @endphp
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-sm font-semibold uppercase tracking-widest">
                        Event Photo
                        <span class="font-normal normal-case tracking-normal text-xs ml-1 text-[#777777]">— Preview</span>
                    </div>
                    <div class="p-2.5 bg-white">
                        <div x-data="{isDragging:false}"
                             @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                             class="relative border-2 rounded-xl text-center cursor-pointer transition-all bg-white"
                             :class="isDragging?'border-[#7a3f91] bg-[#faf7fc]':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-[#7a3f91] border-solid bg-white':'border-dashed border-gray-300 hover:border-[#7a3f91] hover:bg-white' }}'">
                            {{-- Uploading overlay — sits directly on top of the preview
                                 box the moment a file is picked, instead of only a small
                                 text line below it, so the "something is happening" feel
                                 is immediate and impossible to miss. --}}
                            <div wire:loading wire:target="photo"
                                 class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-xl"
                                 style="background:rgba(255,255,255,.9);backdrop-filter:blur(1px);">
                                <div class="eo-upload-spinner"></div>
                                <p class="text-sm font-semibold text-[#7a3f91]">Uploading…</p>
                            </div>
                            <label class="cursor-pointer block p-2.5">
                                <input type="file" wire:model="photo" accept="image/*" class="hidden">
                                @if($photo)
                                    {{-- User just selected a new photo — always visible immediately via temporaryUrl() --}}
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="w-full rounded-lg overflow-hidden border border-purple-200 bg-white flex items-center justify-center" style="height:150px;">
                                            <img src="{{ $photo->temporaryUrl() }}" class="w-full h-full object-contain">
                                        </div>
                                        <p class="text-sm font-semibold text-[#7a3f91]"><i class="fas fa-check-circle mr-1 text-xs"></i>New photo selected — click to change</p>
                                    </div>
                                @elseif($existingPhotoUrl&&!$removePhoto)
                                    {{-- Editing an event that already has a saved photo — always visible --}}
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="w-full rounded-lg overflow-hidden border border-gray-200 bg-white flex items-center justify-center" style="height:150px;">
                                            <img src="{{ $existingPhotoUrl }}" class="w-full h-full object-contain">
                                        </div>
                                        <p class="text-sm font-semibold mt-1" style="color:#111111;">Current photo. Click photo to update.</p>
                                    </div>
                                @elseif($defaultPhotoExists)
                                    {{-- New event, no upload yet — show the real default event photo
                                         from public/storage/event/default-photo-event.jpg (confirmed
                                         to exist server-side, so it renders reliably) --}}
                                    <div class="flex flex-col items-center gap-1.5 py-2">
                                        <div class="w-full rounded-lg overflow-hidden border border-gray-200 bg-white flex items-center justify-center" style="height:120px;">
                                            <img src="{{ asset($defaultPhotoRelPath) }}" alt="Default event photo" class="w-full h-full object-contain">
                                        </div>
                                        <p class="font-semibold text-sm mt-1" style="color:#111111;">JPG, PNG, WEBP — max 5 MB</p>
                                        <p class="text-xs mt-0.5 text-center font-medium" style="color:#111111;">The default photo above is used automatically if you don't upload one. Click photo to update.</p>
                                    </div>
                                @else
                                    {{-- New event, no upload yet, AND default-photo-event.jpg is
                                         missing — inline SVG placeholder, always renders regardless
                                         of any file on the server --}}
                                    <div class="flex flex-col items-center gap-1.5 py-2">
                                        <div class="w-full rounded-lg overflow-hidden border border-gray-200 bg-gradient-to-br from-purple-50 to-white flex items-center justify-center" style="height:120px;">
                                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <rect x="6" y="14" width="60" height="46" rx="6" fill="#F3EAF8" stroke="#D9C3E6" stroke-width="1.5"/>
                                                <circle cx="24" cy="30" r="6" fill="#C9A6DC"/>
                                                <path d="M10 52L26 38C27.1 37.05 28.75 37.05 29.85 38L40 47" stroke="#7A3F91" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                <path d="M34 52L46 41C47.1 40.05 48.75 40.05 49.85 41L62 52" stroke="#9B59B6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </div>
                                        <p class="font-semibold text-sm mt-1" style="color:#111111;">Click to upload or drag &amp; drop</p>
                                        <p class="text-xs text-center font-medium" style="color:#111111;">JPG, PNG, WEBP — max 5 MB. Click photo to update.</p>
                                    </div>
                                @endif
                            </label>
                        </div>
                        @if($existingPhotoUrl&&!$removePhoto&&!$photo)
                            <button type="button" wire:click="$set('removePhoto',true)"
                                    class="mt-1.5 text-sm text-red-600 hover:text-red-700 font-semibold flex items-center gap-1 px-2 py-1 rounded-lg border border-red-200 hover:bg-red-50 transition">
                                <i class="fas fa-trash text-xs"></i> Remove photo
                            </button>
                        @endif
                        @if($removePhoto)
                            <div class="mt-1.5 flex items-center gap-2">
                                <span class="text-sm text-amber-700 font-semibold"><i class="fas fa-exclamation-circle mr-1 text-xs"></i>Photo removed on save</span>
                                <button type="button" wire:click="$set('removePhoto',false)" class="text-sm text-blue-600 underline">Undo</button>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] {{ isset($formErrors['selected_courses']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-visible">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-sm font-semibold uppercase tracking-widest rounded-t-2xl">
                        Programs
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                        @if(count($selectedCourses) > 0)
                            <span class="ml-auto inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-200 text-purple-800 text-xs font-bold">
                                {{ count($selectedCourses) }}
                            </span>
                        @else
                            <span class="ml-auto inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-xs font-semibold">
                                None
                            </span>
                        @endif
                    </div>
                    <div class="p-2.5 space-y-2.5 bg-white">

                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-lg px-2.5 py-1.5">
                            <i class="fas fa-building-columns text-purple-500 text-sm flex-shrink-0"></i>
                            <span class="text-sm font-semibold text-purple-800 truncate">{{ $this->organizerDepartment ?: 'Your College' }}</span>
                        </div>

                        @if(count($this->availableCourses) > 0)
                            <div class="flex items-center justify-between"
                                 x-data="{
                                     get allChecked() {
                                         const total = {{ count($this->availableCourses) }};
                                         return total > 0 && $wire.selectedCourses.length === total;
                                     },
                                     toggleAll(e) {
                                         if (e.target.checked) {
                                             $wire.set('selectedCourses', {{ json_encode($this->availableCourses) }});
                                         } else {
                                             $wire.set('selectedCourses', []);
                                         }
                                     }
                                 }">
                                <span class="text-sm font-semibold uppercase tracking-wider text-[#555555]">Select programs</span>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-1.5 cursor-pointer select-none">
                                        <input type="checkbox"
                                               :checked="allChecked"
                                               @change="toggleAll($event)"
                                               class="accent-purple-600 w-3.5 h-3.5 flex-shrink-0">
                                        <span class="text-sm font-semibold text-[#7a3f91] leading-none">Select All</span>
                                    </label>
                                    @if(count($selectedCourses) > 0)
                                        <button type="button" wire:click="$set('selectedCourses', [])"
                                                class="text-sm font-semibold hover:text-red-500 text-[#555555]">Clear</button>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-1 {{ isset($formErrors['selected_courses']) ? 'p-1.5 rounded-lg border border-red-200 bg-red-50/30' : '' }}">
                                @foreach($this->availableCourses as $course)
                                    <label class="flex items-center gap-1 px-2 py-1 border rounded-lg cursor-pointer transition text-sm font-semibold
                                                  {{ in_array($course, $selectedCourses)
                                                      ? 'border-purple-400 bg-purple-50 text-purple-700'
                                                      : 'border-gray-200 hover:border-purple-300 hover:bg-purple-50/40 bg-white text-[#333333]' }}">
                                        <input type="checkbox" wire:model.live="selectedCourses" value="{{ $course }}"
                                               class="accent-purple-600 w-3 h-3 flex-shrink-0">
                                        <span class="truncate text-sm">{{ $course }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @if(isset($formErrors['selected_courses']))
                                <p class="text-red-600 text-sm flex items-center gap-1 font-semibold">
                                    <i class="fas fa-circle-exclamation text-xs"></i>
                                    {{ $formErrors['selected_courses'] }}
                                </p>
                            @endif

                        @else
                            <div class="text-center py-2">
                                <i class="fas fa-inbox text-xl block mb-1 text-gray-200"></i>
                                <p class="text-sm text-[#555555]">No programs available yet.</p>
                            </div>
                        @endif

                        {{-- ── Batch Year — dropdown picker. Opens to a 3-choice
                             landing screen first: "Specific Batch/Year" (plain
                             year list, pick one, done), "Multiple Batches"
                             (From/To range picker for consecutive batches like
                             2021–2026), or "All Alumni" (clears the filter
                             entirely, no batch scoping). Each sub-screen has a
                             "Back" row to return to the landing screen.
                             RANGE IS ALL-OR-NOTHING: picking only From (or
                             only To) does not apply anything until both
                             sides are chosen and "Apply" is tapped. ── --}}
                        <div class="pt-2 border-t border-gray-100"
                             x-data="{
                                 rangeMode: {{ ($batchYearFrom !== '' && $batchYearTo !== '' && $batchYearFrom !== $batchYearTo) ? 'true' : 'false' }},
                                 rangeFrom: '{{ $batchYearFrom }}',
                                 rangeTo: '{{ $batchYearTo }}',
                                 open: false,
                                 // ── view: which screen the dropdown shows.
                                 // 'menu'   = the 3-choice landing (Specific Batch/Year,
                                 //            Multiple Batches, All Alumni) — always the
                                 //            first thing shown when the dropdown opens.
                                 // 'single' = the plain year list.
                                 // 'range'  = the From/To range picker.
                                 view: 'menu',
                                 menuStyle: '',
                                 // ── Positions the teleported dropdown menu using fixed
                                 // coordinates read from the trigger button's own
                                 // bounding box, recomputed every time it opens (and on
                                 // scroll/resize while open) — this is what lets the menu
                                 // float above the sidebar's own overflow-y-auto scroll
                                 // area instead of being clipped by it, since a teleported
                                 // node sits in <body> and is no longer a descendant of
                                 // that scrolling ancestor at all. ──
                                 positionMenu(){
                                     const btn = this.$refs.trigger;
                                     if(!btn) return;
                                     const r = btn.getBoundingClientRect();
                                     this.menuStyle = 'position:fixed; top:'+(r.bottom+4)+'px; left:'+r.left+'px; min-width:'+r.width+'px;';
                                 },
                                 toggle(){
                                     this.open = !this.open;
                                     if(this.open){
                                         this.view = 'menu';
                                         this.$nextTick(() => this.positionMenu());
                                     }
                                 },
                                 close(){ this.open = false; },
                                 backToMenu(){ this.view = 'menu'; },
                                 chooseSpecific(){ this.view = 'single'; },
                                 chooseMultiple(){
                                     this.rangeFrom = $wire.batchYearFrom || '';
                                     this.rangeTo   = $wire.batchYearTo   || '';
                                     this.rangeMode = true;
                                     this.view = 'range';
                                 },
                                 chooseAllAlumni(){ $wire.chooseAllAlumniBatch(); this.rangeMode=false; this.rangeFrom=''; this.rangeTo=''; this.close(); },
                                 selectYear(val){ $wire.setSingleBatchYear(val); this.rangeMode=false; this.close(); },
                                 pickFrom(val){ this.rangeFrom=val; },
                                 pickTo(val){ this.rangeTo=val; },
                                 applyRange(){ if(this.rangeFrom!=='' && this.rangeTo!==''){ $wire.setBatchRange(this.rangeFrom, this.rangeTo); this.rangeMode=true; this.close(); } }
                             }"
                             @scroll.window="if(open) positionMenu()"
                             @resize.window="if(open) positionMenu()">
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333]">
                                    Batch Year <span class="text-red-500">*</span>
                                </label>
                                @if($batchYearFrom !== '' || $batchYearTo !== '' || $allAlumniChosen)
                                <button type="button" wire:click="clearBatchYear" wire:loading.attr="disabled" wire:target="clearBatchYear"
                                        class="text-xs font-semibold text-[#7a3f91] hover:text-[#5f3272] transition-colors flex items-center gap-1 disabled:opacity-60 disabled:cursor-wait">
                                    <span wire:loading wire:target="clearBatchYear">
                                        <i class="fas fa-spinner fa-spin" style="font-size:10px;"></i>
                                    </span>
                                    <i class="fas fa-rotate-left" style="font-size:10px;" wire:loading.remove wire:target="clearBatchYear"></i>
                                    Clear
                                </button>
                                @endif
                            </div>

                            <div class="relative eo-batch-dropdown">
                                <button type="button" x-ref="trigger" @click.stop="toggle()"
                                        :class="{ 'has-value': ($wire.batchYearFrom!=='' && $wire.batchYearTo!=='') || $wire.allAlumniChosen, 'open': open }"
                                        class="eo-batch-trigger {{ isset($formErrors['batch_year']) ? 'border-red-400 bg-red-50' : '' }}">
                                    <i class="fas fa-calendar-days" style="font-size:11px;opacity:.7;"></i>
                                    <span class="flex-1 text-left">
                                        @if($batchYearFrom !== '' && $batchYearTo !== '' && $batchYearFrom !== $batchYearTo)
                                            Batch {{ $batchYearFrom }}–{{ $batchYearTo }}
                                        @elseif($batchYearFrom !== '' && $batchYearTo !== '')
                                            Batch {{ $batchYearFrom }}
                                        @elseif($batchYearFrom !== '')
                                            Batch {{ $batchYearFrom }} → pick end year
                                        @elseif($batchYearTo !== '')
                                            pick start year → Batch {{ $batchYearTo }}
                                        @elseif($allAlumniChosen)
                                            All Alumni
                                        @else
                                            Select Batch Year
                                        @endif
                                    </span>
                                    <i class="fas fa-chevron-down eo-batch-chevron"></i>
                                </button>

                                {{-- Teleported to <body> so this menu is no longer a
                                     descendant of the sidebar's own overflow-y-auto
                                     scroll container — that ancestor was clipping the
                                     dropdown instead of letting it float above the
                                     content underneath it. Positioned via fixed
                                     coordinates computed in positionMenu() above. --}}
                                <template x-teleport="body">
                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                                         @click.outside="close()"
                                         :style="menuStyle"
                                         class="eo-batch-menu" style="display:none;" @click.stop>

                                    {{-- Landing screen: the 3 top-level choices. Always
                                         the first thing shown on open — no years visible
                                         here at all, so the person picks a MODE first. --}}
                                    <template x-if="view === 'menu'">
                                        <div style="min-width:240px;">
                                            <button type="button" @click.stop="chooseSpecific()" class="eo-batch-item" style="white-space:normal;display:flex;align-items:center;gap:10px;">
                                                <i class="fas fa-calendar-day" style="font-size:13px;color:#7a3f91;width:14px;flex-shrink:0;"></i>
                                                <span>
                                                    <span class="block">Specific Batch/Year</span>
                                                    <span class="block text-xs font-normal text-[#777777]">Pick one batch year</span>
                                                </span>
                                            </button>
                                            <button type="button" @click.stop="chooseMultiple()" class="eo-batch-item" style="white-space:normal;display:flex;align-items:center;gap:10px;">
                                                <i class="fas fa-layer-group" style="font-size:13px;color:#7a3f91;width:14px;flex-shrink:0;"></i>
                                                <span>
                                                    <span class="block">Multiple Batches</span>
                                                    <span class="block text-xs font-normal text-[#777777]">Pick a range of consecutive batches</span>
                                                </span>
                                            </button>
                                            <button type="button" @click.stop="chooseAllAlumni()" :class="{'active': $wire.allAlumniChosen}" class="eo-batch-item" style="white-space:normal;display:flex;align-items:center;gap:10px;">
                                                <i class="fas fa-users" style="font-size:13px;color:#7a3f91;width:14px;flex-shrink:0;"></i>
                                                <span>
                                                    <span class="block">All Alumni</span>
                                                    <span class="block text-xs font-normal text-[#777777]">Every batch, no filter</span>
                                                </span>
                                            </button>
                                        </div>
                                    </template>

                                    {{-- Specific Batch/Year: plain year list, with a Back
                                         row pinned to the bottom to return to the 3-choice
                                         landing screen. --}}
                                    <template x-if="view === 'single'">
                                        <div>
                                            @forelse($this->batches as $b)
                                            <button type="button" @click.stop="selectYear('{{ $b }}')" :class="{'active': $wire.batchYearFrom==='{{ $b }}' && $wire.batchYearTo==='{{ $b }}'}" class="eo-batch-item">{{ $b }}</button>
                                            @empty
                                            <div class="px-3 py-2 text-xs text-[#777777]">No batch years available yet.</div>
                                            @endforelse
                                            <div class="eo-batch-footer">
                                                <button type="button" @click.stop="backToMenu()"
                                                        class="eo-batch-item flex items-center gap-1.5 font-semibold" style="color:#7a3f91;">
                                                    <i class="fas fa-arrow-left" style="font-size:10px;"></i> Back
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    {{-- Multiple Batches: two side-by-side From/To lists,
                                         applied together via "Apply". --}}
                                    <template x-if="view === 'range'">
                                        <div class="p-2" style="width:220px;">
                                            <div class="flex items-start gap-2">
                                                <div class="flex-1 min-w-0 border rounded-lg overflow-y-auto" style="border-color:#E8E0F0;max-height:110px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                                    @foreach($this->batches as $b)
                                                    <button type="button" @click.stop="if(rangeTo!=='{{ $b }}') pickFrom('{{ $b }}')"
                                                            :disabled="rangeTo==='{{ $b }}'"
                                                            :class="{'active':rangeFrom==='{{ $b }}', 'disabled':rangeTo==='{{ $b }}'}"
                                                            class="eo-batch-item eo-batch-range-item" style="border-radius:0;">{{ $b }}</button>
                                                    @endforeach
                                                </div>
                                                <div class="flex-1 min-w-0 border rounded-lg overflow-y-auto" style="border-color:#E8E0F0;max-height:110px;scrollbar-width:thin;scrollbar-color:#d4b8e8 transparent;">
                                                    @foreach($this->batches as $b)
                                                    <button type="button" @click.stop="if(rangeFrom!=='{{ $b }}') pickTo('{{ $b }}')"
                                                            :disabled="rangeFrom==='{{ $b }}'"
                                                            :class="{'active':rangeTo==='{{ $b }}', 'disabled':rangeFrom==='{{ $b }}'}"
                                                            class="eo-batch-item eo-batch-range-item" style="border-radius:0;">{{ $b }}</button>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 mt-3 eo-batch-footer">
                                                <button type="button" @click.stop="backToMenu()"
                                                        class="flex-1 text-xs font-semibold text-[#333333] hover:bg-[#F5F5F5] rounded-lg py-1.5 transition-colors border border-[#E8E0F0]">
                                                    Back
                                                </button>
                                                <button type="button" @click.stop="applyRange()"
                                                        :disabled="rangeFrom==='' || rangeTo===''"
                                                        class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors border"
                                                        :class="(rangeFrom==='' || rangeTo==='') ? 'text-[#B9A8CB] border-[#E8E0F0] bg-[#F5F5F5] cursor-not-allowed' : 'border-[#E8E0F0] hover:bg-[#F5F0FA]'"
                                                        :style="(rangeFrom==='' || rangeTo==='') ? '' : 'color:#7a3f91;'">
                                                    Apply
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                </template>
                            </div>

                            @if(isset($formErrors['batch_year']))
                                <p class="text-red-600 text-sm mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['batch_year'] }}</p>
                            @else
                                <p class="text-xs mt-1 text-[#777777]">Choose a specific batch, multiple batches, or all alumni.</p>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE COLUMN --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-visible lg:overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="lg:flex-1 lg:min-h-0 overflow-visible lg:overflow-y-auto flex flex-col p-3 gap-3" style="scrollbar-width:thin;">

                <div class="flex flex-col bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden" style="min-height: 0; flex: 1;">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-sm font-semibold uppercase tracking-widest flex-shrink-0">
                        Event Details
                    </div>
                    <div class="flex flex-col flex-1 min-h-0 p-2.5 gap-3 bg-white">

                        <div class="flex-shrink-0">
                            <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Event Title <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="title" type="text"
                                   placeholder="e.g. PHILCST Alumni Homecoming 2026" maxlength="200"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['title']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['title']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['title'] }}</p>@endif
                        </div>

                        <div class="flex flex-col" style="flex: 1; min-height: 80px;">
                            <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1 flex-shrink-0">
                                Description <span class="text-red-500">*</span>
                            </label>
                            <textarea wire:model.defer="description"
                                      placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                      class="flex-1 w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto {{ isset($formErrors['description']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                      style="min-height: 80px;"></textarea>
                            @if(isset($formErrors['description']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1 flex-shrink-0"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['description'] }}</p>@endif
                        </div>

                        <div class="flex-shrink-0 grid grid-cols-1 sm:grid-cols-3 gap-2.5">

                            <div>
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Date <span class="text-red-500">*</span>
                                </label>
                                <input wire:model="event_date" type="date"
                                       min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                                       onclick="window.__eoOpenDatePicker(this)"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 cursor-pointer {{ isset($formErrors['event_date']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['event_date']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['event_date'] }}</p>@endif
                            </div>

                            <div>
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Start Time <span class="text-red-500">*</span>
                                </label>
                                <div wire:ignore
                                     x-data="{
                                         h: '6', m: '00', p: 'PM',
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
                                     @reset-time-selects.window="h='6';m='00';p='PM';sync()"
                                     class="time-select-wrap flex items-stretch rounded-xl overflow-hidden border transition-shadow focus-within:ring-2 focus-within:ring-[#7a3f91]/20 {{ isset($formErrors['start_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-sm"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="border-r border-gray-200 text-[#333333]" title="Hour">
                                        @foreach(['12','1','2','3','4','5','6','7','8','9','10','11'] as $hr)
                                            <option value="{{ $hr }}">{{ $hr }}</option>
                                        @endforeach
                                    </select>
                                    <span class="flex items-center px-1 bg-white border-x border-gray-200 text-[#555] font-semibold text-base select-none">:</span>
                                    {{-- Start time minutes: no :59 (only up to :50) --}}
                                    <select x-model="m" @change="sync()" class="text-[#333333]" title="Minute">
                                        @foreach(['00','05','10','15','20','25','30','35','40','45','50'] as $mn)
                                            <option value="{{ $mn }}">{{ $mn }}</option>
                                        @endforeach
                                    </select>
                                    <select x-model="p" @change="sync()" class="border-l border-gray-200 bg-[#faf7fc] text-[#7a3f91] font-semibold min-w-[3rem] text-center" title="AM/PM">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                                @if(isset($formErrors['start_time']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['start_time'] }}</p>@endif
                            </div>

                            <div>
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    End Time <span class="text-red-500">*</span>
                                </label>
                                <div wire:ignore
                                     x-data="{
                                         h: '11', m: '59', p: 'PM',
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
                                     @reset-time-selects.window="h='11';m='59';p='PM';sync()"
                                     class="time-select-wrap flex items-stretch rounded-xl overflow-hidden border transition-shadow focus-within:ring-2 focus-within:ring-[#7a3f91]/20 {{ isset($formErrors['end_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300 focus-within:border-[#7a3f91]' }}">
                                    <span class="flex items-center justify-center px-2 bg-white border-r border-gray-200">
                                        <i class="fas fa-clock text-gray-300 text-sm"></i>
                                    </span>
                                    <select x-model="h" @change="sync()" class="border-r border-gray-200 text-[#333333]" title="Hour">
                                        @foreach(['12','1','2','3','4','5','6','7','8','9','10','11'] as $hr)
                                            <option value="{{ $hr }}">{{ $hr }}</option>
                                        @endforeach
                                    </select>
                                    <span class="flex items-center px-1 bg-white border-x border-gray-200 text-[#555] font-semibold text-base select-none">:</span>
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
                                @if(isset($formErrors['end_time']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['end_time'] }}</p>@endif
                            </div>

                        </div>

                        <div class="flex-shrink-0 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Venue / Location <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="venue" type="text"
                                       placeholder="e.g. PHILCST Main Gym" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['venue']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['venue']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['venue'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Full Address <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="venue_address" type="text"
                                       placeholder="e.g. Old Nalsian Road, Calasiao, Pangasinan" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['venue_address']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['venue_address']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['venue_address'] }}</p>@endif
                            </div>
                        </div>

                    </div>
                </div>

                <div class="flex-shrink-0 bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-sm font-semibold uppercase tracking-widest">
                        Notes / Requirements
                        <span class="font-normal normal-case tracking-normal text-xs ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-2.5 bg-white">
                        <textarea wire:model.defer="notes"
                                  placeholder="Dress code, special instructions, what to bring, parking info…" maxlength="3000"
                                  class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-base bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto"
                                  style="height: 200px;"></textarea>
                        <p class="text-xs mt-1.5 flex items-center gap-1 text-[#777777]">
                            <i class="fas fa-circle-info text-[11px]"></i>
                            Visible to alumni on the event page.
                        </p>
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT COLUMN --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-visible lg:overflow-y-auto" style="scrollbar-width:thin;">
            <div class="p-3 space-y-3 flex-1">

                {{-- Contact Person — Name & Email are always the organizer's own
                     account details and are NOT editable (read-only display).
                     Only Phone remains an actual input. ── --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] text-[#333333] text-sm font-semibold uppercase tracking-widest">
                        <span class="block leading-tight">Contact Person</span>
                        <span class="block font-normal normal-case tracking-normal text-xs text-[#777777] mt-0.5">from your account</span>
                    </div>
                    <div class="p-2.5 space-y-2.5 bg-white">
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Name</label>
                            <div class="w-full px-3 py-2 border-[1.5px] border-gray-200 rounded-xl text-base bg-white text-[#333333] flex items-center gap-2">
                                <i class="fas fa-user text-sm text-[#999999]"></i>
                                <span class="truncate">{{ $contact_person ?: $this->organizerName }}</span>
                                <i class="fas fa-lock text-xs text-[#bbbbbb] ml-auto flex-shrink-0"></i>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Email</label>
                            <div class="w-full px-3 py-2 border-[1.5px] border-gray-200 rounded-xl text-base bg-white text-[#333333] flex items-center gap-2">
                                <i class="fas fa-envelope text-sm text-[#999999]"></i>
                                <span class="truncate">{{ $contact_email ?: $this->organizerEmail }}</span>
                                <i class="fas fa-lock text-xs text-[#bbbbbb] ml-auto flex-shrink-0"></i>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Phone <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                            </label>
                            <input wire:model.defer="contact_phone" type="text"
                                   placeholder="09XXXXXXXXX" maxlength="11"
                                   inputmode="numeric"
                                   onfocus="if(!this.value) this.value='09';"
                                   onblur="if(this.value === '09') { this.value = ''; }"
                                   oninput="
                                       var d = this.value.replace(/\D/g, '');
                                       if (d.length === 0) { d = '09'; }
                                       else if (d.charAt(0) !== '0') { d = '0' + d; }
                                       if (d.length >= 2 && d.charAt(1) !== '9') { d = '09' + d.slice(2); }
                                       this.value = d.slice(0, 11);
                                   "
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-base bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['contact_phone']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['contact_phone']))<p class="text-red-600 text-sm mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['contact_phone'] }}</p>@endif
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-sm font-semibold uppercase tracking-widest">
                        Submission Tips
                    </div>
                    <div class="p-2.5 bg-white">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-sm text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[11px]"></i>
                                <span>Set a future date — past dates are auto-rejected.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-sm text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[11px]"></i>
                                <span>Choose correct programs so the right alumni are notified.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-sm text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[11px]"></i>
                                <span>Upload a photo — events with photos get more RSVPs.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-sm text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[11px]"></i>
                                <span>Review typically takes 1–2 business days.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="requestSaveEvent"
                        wire:loading.attr="disabled" wire:target="requestSaveEvent,saveEvent"
                        class="w-full px-5 py-3 rounded-xl text-base font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer
                               {{ $isResubmitting ? 'bg-amber-600 hover:bg-amber-700' : 'bg-[#7a3f91] hover:bg-[#5e2f72]' }}">
                    <span wire:loading wire:target="requestSaveEvent,saveEvent">
                        <i class="fas fa-spinner animate-spin text-sm"></i>
                    </span>
                    <span wire:loading.remove wire:target="requestSaveEvent,saveEvent">
                        @if($isResubmitting)
                            <i class="fas fa-rotate-right text-sm"></i>
                        @elseif($isEditing)
                            <i class="fas fa-floppy-disk text-sm"></i>
                        @else
                            <i class="fas fa-paper-plane text-sm"></i>
                        @endif
                    </span>
                    <span wire:loading.remove wire:target="requestSaveEvent,saveEvent">
                        @if($isResubmitting) Save &amp; Resubmit
                        @elseif($isEditing) Save Changes
                        @else Submit Event
                        @endif
                    </span>
                </button>
                <button type="button" wire:click="closeFormModal"
                        wire:loading.attr="disabled" wire:target="requestSaveEvent,saveEvent,closeFormModal"
                        class="w-full px-5 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333] disabled:opacity-60 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="closeFormModal">
                        <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                    </span>
                    <span wire:loading wire:target="closeFormModal">
                        <i class="fas fa-spinner fa-spin mr-1 text-xs"></i>Closing…
                    </span>
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
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

<div class="flex items-center justify-between px-4 sm:px-6 py-3 flex-shrink-0 shadow-md"
     style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
    <div class="flex items-center gap-3 min-w-0 flex-1">
        <div class="min-w-0 flex-1">
            <p class="text-white/60 text-[10px] sm:text-xs font-semibold uppercase tracking-widest">Event Details</p>
            <h2 class="text-white font-semibold text-sm sm:text-base leading-tight line-clamp-2 sm:truncate">{{ $ev->title }}</h2>
        </div>
    </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($isApproved || $isCompleted)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareModal({{ $ev->id }})"
                            wire:loading.attr="disabled" wire:target="openShareModal({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Share event">
                        <i class="fas fa-share-nodes text-white text-sm" wire:loading.remove wire:target="openShareModal({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="openShareModal({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            @if(!$isApproved && !$isCompleted && in_array($ev->status, ['PENDING', 'REJECTED']))
                <div class="relative inline-flex group">
                    <button wire:click="confirmDelete({{ $ev->id }})" type="button"
                            wire:loading.attr="disabled" wire:target="confirmDelete({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Delete event">
                        <i class="fas fa-trash-can text-white text-sm" wire:loading.remove wire:target="confirmDelete({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="confirmDelete({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Delete
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeViewModal"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:opacity-60 disabled:cursor-wait"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm" wire:loading.remove wire:target="closeViewModal"></i>
                    <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="closeViewModal"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white lg:overflow-y-auto scroll-c">

            @if($hasPhoto)
            <div class="w-full px-5 pt-5 pb-3 flex-shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50"
                     x-data="{ imgLoaded: false }" style="min-height: 120px;">
                    {{-- Skeleton shimmer shown until the image actually
                         finishes decoding — otherwise the modal looks
                         "stuck"/slow while the photo streams in. --}}
                    <div x-show="!imgLoaded" x-cloak
                         class="absolute inset-0 flex items-center justify-center"
                         style="background: linear-gradient(90deg,#f3f4f6 25%,#e9e6f0 37%,#f3f4f6 63%); background-size: 400% 100%; animation: eoImgShimmer 1.4s ease-in-out infinite;">
                        <i class="fas fa-image text-gray-300 text-2xl"></i>
                    </div>
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         loading="eager" fetchpriority="high" decoding="async"
                         x-on:load="imgLoaded = true"
                         x-bind:class="imgLoaded ? 'opacity-100' : 'opacity-0'"
                         class="w-full object-contain block transition-opacity duration-200"
                         style="max-height: 200px;">
                    <div class="absolute top-3 right-3">
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Completed</span>
                        @elseif($isApproved)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Approved</span>
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
                    @endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-3 px-5 pb-5">

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Date &amp; Time</p>
                    <p class="text-lg font-bold text-[#333333]">{{ $eventDatePH->format('F d, Y') }}</p>
                    <p class="text-base font-semibold mt-0.5 text-[#333333]">{{ $timeDisplay }}</p>
                    @if($isApproved && $eventDatePH->isFuture())
                        <span class="eo-upcoming-badge mt-2"><i class="fas fa-circle text-[6px]"></i>Upcoming</span>
                    @endif
                </div>

                @if($ev->venue)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Venue</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->venue }}</p>
                    @if($ev->venue_address)
                        <p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->venue_address }}</p>
                    @endif
                </div>
                @endif

                @if($ev->target_participants)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Open For</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->target_participants }}</p>
                </div>
                @endif

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-2 text-[#333333]">Contact</p>
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

                @if($isCompleted)
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

        <div class="flex-1 min-w-0 flex flex-col lg:overflow-hidden bg-gray-50">

            <div class="flex-shrink-0 px-6 py-4 bg-white border-b border-gray-200">
                <p class="text-[10px] font-bold uppercase tracking-widest mb-2 text-[#333333]">Responses</p>
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

<div class="flex-1 min-h-0 lg:overflow-y-auto scroll-c eo-view-right-scroll px-6 py-5 flex flex-col gap-5">

@if($ev->description)
<div class="eo-view-detail-card bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col lg:flex-1 lg:min-h-0">
    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex-shrink-0">
        <p class="text-[12px] font-bold uppercase tracking-widest text-[#333333]">About This Event</p>
    </div>
    <div class="eo-detail-table-wrap px-5 py-4 lg:flex-1">
      <p class="text-sm leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->description) }}</p>
    </div>
</div>
@endif

@if($ev->notes)
<div class="eo-view-detail-card bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col lg:flex-1 lg:min-h-0">
    <div class="px-5 py-3 border-b border-gray-100 bg-amber-50 flex-shrink-0">
        <p class="text-[12px] font-bold uppercase tracking-widest text-[#333333]">Additional Notes</p>
    </div>
    <div class="eo-detail-table-wrap px-5 py-4 lg:flex-1">
        <p class="text-sm leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->notes) }}</p>
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


{{-- ══ SHARE MODAL — mirrors the alumni "Upcoming Events" share modal design:
     SVG X close button with hover tooltip, simplified icon+label option
     rows, pre-share "Download the photo?" confirm modal, and clipboard-
     before-focus caption copy. Adds a "Share to Message Hub" chat
     option (UI only for now — no backend wiring yet, per request). ══ --}}
@if($showShareModal)
@php
    $shTimeStr        = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $isCompleted      = $shareEventIsCompleted;

    $fbLines   = [];
    $fbLines[] = $isCompleted ? "EVENT HIGHLIGHTS: " . strtoupper($shareEventTitle) : strtoupper($shareEventTitle);

    if (trim($shareEventDescription) !== '') {
        $fbLines[] = '';
        $fbLines[] = 'About This Event:';
        $fbLines[] = trim($shareEventDescription);
    }

    if (trim($shareEventNotes) !== '') {
        $fbLines[] = '';
        $fbLines[] = 'Additional Notes:';
        $fbLines[] = trim($shareEventNotes);
    }

    $fbLines[] = '';
    $fbLines[] = 'For more information, visit our PHILCST Alumni Connect and login.';
    $fbLines[] = '#YourFutureStarsHere';
    $fbPostText = implode("\n", $fbLines);
@endphp

<style>
@keyframes eoPanelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.eo-share-sheet { animation: eoPanelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.eo-share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
</style>

<div id="eo-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 eo-share-backdrop"
     x-data="{
         copied:false,
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         downloading:false,
         downloaded:false,
         shareText: {{ json_encode($fbPostText) }},
         eventTitle: {{ json_encode($shareEventTitle) }},
         imageUrl:  {{ json_encode($shareEventPhotoUrl) }},

         showDlConfirm: false,
         pendingTarget: null,

         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'event-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
         },

         async autoCopyCaption() {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(this.shareText);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch (e) { return false; }
         },

         async downloadImage() {
             if (!this.imageUrl) return false;
             this.downloading = true;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 const url  = URL.createObjectURL(blob);
                 const a = document.createElement('a');
                 a.href = url;
                 a.download = 'event-photo.' + ext;
                 document.body.appendChild(a);
                 a.click();
                 document.body.removeChild(a);
                 setTimeout(() => URL.revokeObjectURL(url), 4000);
                 this.downloading = false;
                 this.downloaded  = true;
                 setTimeout(() => this.downloaded = false, 4000);
                 return true;
             } catch (e) {
                 this.downloading = false;
                 return false;
             }
         },

         async nativeShare() {
             try {
                 const shareData = { title: this.eventTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user — nothing to do */ }
         },

         askShare(target) {
             if (this.nativeShareSupported) { this.nativeShare(); return; }
             this.pendingTarget = target;
             this.showDlConfirm = true;
         },

         async confirmDownloadThenGo() {
             await this.downloadImage();
             this.proceedToTarget();
         },

         proceedToTarget() {
             this.showDlConfirm = false;
             const target = this.pendingTarget;
             this.pendingTarget = null;
             if (target === 'facebook') this.openFacebook();
             else if (target === 'messenger') this.openMessenger();
         },

         cancelDlConfirm() {
             this.showDlConfirm = false;
             this.pendingTarget = null;
         },

         // Copy the caption FIRST while this page still has focus, then
         // open/focus the target window. Copying after focus has already
         // moved elsewhere can silently fail in some browsers, leaving
         // stale clipboard content behind instead of the caption.
         async openFacebook() {
             const copyOk = await this.autoCopyCaption();
             const w=680,h=560,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             const win = window.open(url, 'philcst_eo_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into the Facebook post box that just opened.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Facebook.'
             });
         },

         async openMessenger() {
             const copyOk = await this.autoCopyCaption();
             const win = window.open('https://www.messenger.com/new', 'philcst_eo_messenger_share', 'noopener,noreferrer');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into Messenger.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Messenger.'
             });
         },

         async copyLinkFn() {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(this.shareText); }
                 else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 this.copied = true; setTimeout(() => this.copied = false, 2500);
             } catch(e) { console.warn('Copy failed', e); }
         }
     }"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="if(showDlConfirm){cancelDlConfirm()}else{$wire.closeShareModal()}">

    <div class="eo-share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 eo-share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Event
            </h2>
            <button wire:click="closeShareModal" type="button"
                    wire:loading.attr="disabled" wire:target="closeShareModal"
                    class="eo-share-close-btn" aria-label="Close">
                <span wire:loading.remove wire:target="closeShareModal">
                    <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2L12 12M12 2L2 12"/>
                    </svg>
                </span>
                <span wire:loading wire:target="closeShareModal">
                    <i class="fas fa-spinner fa-spin text-xs"></i>
                </span>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-c">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                @if($shareEventPhotoUrl)
                <div class="eo-share-photo-preview">
                    <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                         onerror="this.style.display='none'">
                    <span class="dl-badge" x-show="downloading || downloaded" x-cloak>
                        <i class="fas" :class="downloading ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="downloading ? 'Downloading…' : 'Downloaded'"></span>
                    </span>
                </div>
                @endif

                <div class="rounded-xl border border-gray-200 flex-shrink-0">
                    <div class="px-4 py-3">
                        <p class="whitespace-pre-wrap leading-relaxed" style="font-size:clamp(11px,1vw,13px);color:#333333;">{{ rtrim(preg_replace('/#YourFutureStarsHere\s*$/', '', $fbPostText)) }}</p>
                        <p class="whitespace-pre-wrap leading-relaxed font-semibold mt-1" style="font-size:clamp(11px,1vw,13px);color:#1877F2;">#YourFutureStarsHere</p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto scroll-c">
                <p class="text-[10px] font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="eo-share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" class="eo-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Share on Facebook</span>
                </button>

                <button type="button" @click="askShare('messenger')" class="eo-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Send via Messenger</span>
                </button>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0" x-data="{ open: true }">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center gap-2.5 px-3 py-2.5 bg-[#F5F0FA] hover:bg-[#EFE6F7] active:scale-[.98] transition-all duration-150 cursor-pointer">
                        <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#7a3f91;">
                            <i class="fas fa-comments text-white text-xs"></i>
                        </span>
                        <span class="flex-1 text-left text-xs font-semibold" style="color:#333333;">Share to Message Hub</span>
                        <i class="fas fa-chevron-down text-[10px] transition-transform" style="color:#7a3f91;" :class="open ? 'rotate-180' : ''"></i>
                    </button>

                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        @if(empty($shareAvailableRooms))
                            <p class="px-3 py-3 text-[11px]" style="color:#333333;">No chats available to share to yet.</p>
                        @else
                            @php
                                $shareAllIds = collect($shareAvailableRooms)->pluck('id')->map(fn($id) => (string) $id)->toArray();
                                $shareAllSelected = !empty($shareAllIds) && empty(array_diff($shareAllIds, $shareTargetRoomIds));

                                $shareTargetLabel = '';
                                if ($shareTargetBatchYear !== '') {
                                    $shareTargetLabel = 'Batch ' . $shareTargetBatchYear
                                        . ' · ' . (empty($shareTargetCourseCodes) ? 'All Courses' : implode(', ', $shareTargetCourseCodes));
                                } elseif (!empty($shareTargetCourseCodes)) {
                                    $shareTargetLabel = implode(', ', $shareTargetCourseCodes) . ' · All Batches';
                                }
                            @endphp

                            @if($shareTargetLabel !== '' && !empty($shareAutoRoomIds))
                            <div class="mx-3 mt-2.5 mb-0.5 px-2.5 py-1.5 rounded-lg flex items-center gap-1.5" style="background:#F5F0FA;">
                                <i class="fas fa-wand-magic-sparkles text-[10px]" style="color:#7a3f91;"></i>
                                <span class="text-[10.5px] leading-snug" style="color:#5c2d7a;">
                                    Auto-selected for this event's target: <span class="font-bold">{{ $shareTargetLabel }}</span>
                                </span>
                            </div>
                            @endif

                            <label class="flex items-center gap-2.5 px-3 py-2 border-t border-gray-100 cursor-pointer hover:bg-gray-50 active:bg-gray-100 transition-colors duration-100">
                                <input type="checkbox" wire:click="toggleSelectAllShareRooms"
                                       wire:key="share-select-all-{{ $shareAllSelected ? 'on' : 'off' }}"
                                       @checked($shareAllSelected)
                                       class="w-3.5 h-3.5 rounded accent-[#7a3f91] cursor-pointer flex-shrink-0">
                                <span class="text-[11px] font-bold uppercase tracking-wide" style="color:#7a3f91;">Select All</span>
                            </label>

                            <div class="max-h-40 overflow-y-auto scroll-c border-t border-gray-100">
                                @foreach($shareAvailableRooms as $r)
                                    @php $isAutoRoom = in_array((string) $r['id'], $shareAutoRoomIds, true); @endphp
                                    <label class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 active:bg-gray-100 transition-colors duration-100 {{ $isAutoRoom ? 'bg-[#FAF7FD]' : '' }}">
                                        <input type="checkbox" wire:model.live="shareTargetRoomIds" value="{{ $r['id'] }}"
                                               class="w-3.5 h-3.5 rounded accent-[#7a3f91] cursor-pointer flex-shrink-0">
                                        <span class="text-xs truncate flex-1" style="color:#333333;">{{ $r['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="px-3 py-2.5 border-t border-gray-100 bg-white">
                                <button type="button" wire:click="shareToOrganizerUpdates"
                                        wire:loading.attr="disabled" wire:target="shareToOrganizerUpdates"
                                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-white text-xs font-semibold cursor-pointer transition-all duration-150 active:scale-[.97] disabled:opacity-60 disabled:cursor-wait disabled:active:scale-100"
                                        style="background:#7a3f91;" onmouseover="this.style.background='#6a3280'" onmouseout="this.style.background='#7a3f91'">
                                    <span wire:loading.remove wire:target="shareToOrganizerUpdates">
                                        <i class="fas fa-paper-plane text-[11px]"></i> Share ({{ count($shareTargetRoomIds) }})
                                    </span>
                                    <span wire:loading wire:target="shareToOrganizerUpdates">
                                        <i class="fas fa-spinner fa-spin text-[11px]"></i> Sharing…
                                    </span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                <p class="text-[10px] text-center" style="color:#333333;">Sharing highlights is available even after the event.</p>
            </div>
        </div>

        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex-shrink-0">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5" style="color:#333333;"></i>
                <p class="text-xs leading-relaxed" style="color:#333333;">
                    The caption is copied to your clipboard automatically — just paste it (Ctrl+V)
                    into the Facebook or Messenger window that opens.
                </p>
            </div>
        </div>
    </div>

    {{-- ── PRE-SHARE "Download the photo?" CONFIRM MODAL ── --}}
    <div x-show="showDlConfirm" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[10010] flex items-center justify-center p-4 bg-black/55"
         @click.self="cancelDlConfirm()">
        <div class="eo-share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="eo-dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold" style="color:#333333;">Download the event photo?</p>
                    <p class="text-xs mt-1 leading-relaxed" style="color:#333333;">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($shareEventPhotoUrl)
            <div class="eo-share-photo-preview" style="height:110px;">
                <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="eo-dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="eo-dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>

<script>
(function () {
    var tip     = document.getElementById('eo-hover-tip');
    var tipIcon = document.getElementById('eo-hover-tip-icon');
    var tipText = document.getElementById('eo-hover-tip-text');

    // ── Event delegation instead of per-row binding ──────────────────
    // Filtering, searching, and pagination re-render the table rows
    // (Livewire morphs/replaces the <tr> elements), which used to wipe
    // out the mousemove/touchstart listeners bound directly to each row
    // — that's why the tooltip would stop appearing right after a
    // filter/search/page change. Listening on the document instead
    // means the tooltip keeps working no matter how many times the
    // rows underneath get swapped out, with nothing left to rebind.
    function setTooltip(row, x, y) {
        if (!tip) return;
        var isEditable = row.getAttribute('data-eo-row-editable') === '1';
        if (tipIcon && tipText) {
            if (isEditable) {
                tipIcon.className = 'fas fa-pen-to-square mr-1.5';
                tipText.textContent = 'Edit';
            } else {
                tipIcon.className = 'fas fa-eye mr-1.5';
                tipText.textContent = 'View Details';
            }
        }
        tip.style.left = x + 'px';
        tip.style.top  = y + 'px';
        tip.style.opacity = '1';
    }

    function hideTooltip() {
        if (tip) tip.style.opacity = '0';
    }

    document.addEventListener('mousemove', function (e) {
        var row = e.target.closest('[data-eo-row]');
        if (!row) { hideTooltip(); return; }
        if (e.target.closest('[data-eo-share]')) { hideTooltip(); return; }
        setTooltip(row, e.clientX, e.clientY);
    });

    // Touch devices: no mousemove, so show the same tooltip right above
    // the finger the moment a row is pressed — this is what keeps
    // "View Details" visible on mobile too, not just desktop hover.
    document.addEventListener('touchstart', function (e) {
        var row = e.target.closest('[data-eo-row]');
        if (!row || e.target.closest('[data-eo-share]')) return;
        var t = e.touches && e.touches[0];
        if (!t) return;
        setTooltip(row, t.clientX, t.clientY);
    }, { passive: true });

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-eo-row]')) hideTooltip();
    });
})();

// ── Deep-link cleanup: strip ?highlight_event=ID from the address bar
//    once mount() has already consumed it to auto-open View Details.
//    Without this, refreshing the page (or hitting Back later) would
//    keep re-opening the same event's modal every time. ─────────────────
(function () {
    var url = new URL(window.location.href);
    if (url.searchParams.has('highlight_event')) {
        url.searchParams.delete('highlight_event');
        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
})();
// ── Mobile scroll-up fix: Add Event / View Details ──────────────────
//    Both openCreateModal() and viewEvent() dispatch 'close-sidebar' the
//    moment they open their fullscreen modal. On mobile, if the events
//    table underneath was scrolled down (or a previous modal's inner
//    panel was left scrolled from before), the new modal could appear
//    with the page/inner content still sitting mid-scroll instead of at
//    the top — reading as "not working" since the header/actions the
//    user expects to see first are off-screen. Reset every relevant
//    scroll position back to the top whenever a modal opens.
document.addEventListener('livewire:init', function () {
    Livewire.on('close-sidebar', function () {
        requestAnimationFrame(function () {
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0; // Safari

            ['#eo-table-scroll', '.eo-view-right-scroll'].forEach(function (sel) {
                var el = document.querySelector(sel);
                if (el) el.scrollTop = 0;
            });
        });
    });
});
</script>

<script>
(function () {
    // ── Click anywhere in the date field to open the picker ────────────
    // A native <input type="date"> only opens its calendar when you click
    // the small icon on the right — clicking the text/box area just moves
    // the text cursor. This makes the WHOLE input act like the icon, so
    // one click anywhere in the box pops the picker open immediately —
    // same behavior as the date field in Job Management.
    window.__eoOpenDatePicker = function (el) {
        if (!el) return;
        if (typeof el.showPicker === 'function') {
            try { el.showPicker(); } catch (e) { /* ignore — e.g. not user-triggered enough for some browsers */ }
        }
    };
})();
</script>

</div>