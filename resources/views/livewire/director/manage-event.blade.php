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
    public int    $directorId    = 0;

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
    public string $shareEventNotes       = '';
    public string $shareEventPhotoUrl    = '';
    public string $shareEventTarget      = '';
    public string $shareEventStatus      = '';

    /**
     * Status filter arrives via the session, not the query string, so the
     * address bar always shows the plain /director/event/management URL —
     * same pattern already used by manage-job.blade.php / manage-coordinator
     * (see dashboard.blade.php's goToPendingEvents() etc.). The dashboard
     * stat cards/mini-tiles session()->put('director_event_status', ...)
     * then redirect here; we pull it once (which also clears it), so a
     * plain refresh afterwards goes back to showing every status.
     */
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
            $this->directorId    = (int) $dirRecord->id;
        }

        if (! $this->myDisplayName) {
            $this->myDisplayName = auth()->user()?->name ?? 'Director';
        }

        $statusMap = [
            'pending'   => 'PENDING',
            'approved'  => 'APPROVED',
            'rejected'  => 'REJECTED',
            'completed' => 'COMPLETED',
        ];

        $incomingStatus = session()->pull('director_event_status');
        if ($incomingStatus && isset($statusMap[strtolower($incomingStatus)])) {
            $this->filterStatus = $statusMap[strtolower($incomingStatus)];
        }

        // ─────────────────────────────────────────────────────────────────
        // NEW: deep-link support for "View Event" coming from the director
        // messenger's shared event-post card (?event={id} in the URL).
        // 'type' is accepted too (ADMIN/ORGANIZER) but viewEvent() only
        // needs the id — it looks the event up directly, same as a manual
        // row click would.
        // ─────────────────────────────────────────────────────────────────
        $incomingEventId = request()->query('event');
        if ($incomingEventId && ctype_digit((string) $incomingEventId)) {
            $this->viewEvent((int) $incomingEventId);
        }

        $cacheKey = 'director_events_auto_processed';
        if (! Cache::has($cacheKey)) {
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
            Cache::put($cacheKey, true, 60);
        }

        // ─────────────────────────────────────────────────────────────────
        // One-time self-heal: notifs already sitting in the bell as
        // "New Event for Review" / "Event Resubmitted for Review" from
        // BEFORE updateDirectorReviewNotif() existed never got their
        // title flipped to "→ Approved" / "→ Rejected" when they were
        // acted on — so the bell still shows "→ Pending" for events that
        // are actually long since decided. Sync those stale rows to the
        // event's real current status here, once per director per
        // request-cache window (same pattern as the block above).
        // ─────────────────────────────────────────────────────────────────
        $syncCacheKey = 'director_review_notifs_synced_' . $this->directorId;
        if ($this->directorId && ! Cache::has($syncCacheKey)) {
            $this->syncStaleReviewNotifTitles();
            Cache::put($syncCacheKey, true, 60);
        }
    }

    private function syncStaleReviewNotifTitles(): void
    {
        try {
            $staleRows = DB::table('director_notifications')
                ->where('director_id', $this->directorId)
                ->where('link_route', 'director.event/management')
                ->whereIn('title', ['New Event for Review', 'Event Resubmitted for Review'])
                ->whereNotNull('event_id')
                ->get();

            if ($staleRows->isEmpty()) return;

            $eventIds = $staleRows->pluck('event_id')->unique()->all();
            $statuses = AdminEvent::withTrashed()->whereIn('id', $eventIds)->pluck('status', 'id');

            foreach ($staleRows as $row) {
                $status = $statuses[$row->event_id] ?? null;
                if (!in_array($status, ['APPROVED', 'REJECTED'], true)) continue; // genuinely still pending — leave as-is

                $label = $status === 'APPROVED' ? 'Approved' : 'Rejected';

                DB::table('director_notifications')->where('id', $row->id)->update([
                    'title'      => $row->title . ' → ' . $label,
                    'icon'       => $status === 'APPROVED' ? 'calendar-check' : 'calendar',
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Non-critical — bell just keeps showing the un-synced title until next mount
        }
    }

    private function notifyOrganizerEvent(?int $organizerId, string $icon, string $title, string $message, string $dedupKey, ?int $eventId = null): void
    {
        if (!$organizerId) return;

        $userId = DB::table('organizer')
            ->where('id', $organizerId)
            ->whereNull('deleted_at')
            ->value('user_id');

        if (!$userId) return;

        // ── One notification ROW per event, not one per status change. ──
        // The frontend's per-event grouping keys off the event_id column
        // (see _processNotifs in the sidebar JS), so THAT is what we match
        // on here — not dedup_key, which still varies per action
        // ("event-management::approved::{id}" vs "...rejected::{id}") so
        // the frontend can tell approve apart from reject. Matching on
        // event_id is what makes this overwrite the existing
        // "Submitted -> Pending" row in place instead of inserting a
        // second row next to it.
        try {
            $existing = DB::table('coordinator_notifications')
                ->where('user_id', $userId)
                ->where('link_route', 'organizer.event/organizer')
                ->where('event_id', $eventId)
                ->first();

            $payload = [
                'icon'       => $icon,
                'title'      => $title,
                'message'    => $message,
                'link_label' => 'View Events',
                'event_id'   => $eventId,
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
                    'user_id'    => $userId,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable) {}
    }

    // ── Keeps the director's OWN "New Event for Review" bell notif in
    //    sync with the event's outcome, same convention as the job
    //    posting's "You Posted a Job → Active/Inactive" chain title.
    //
    //    The row itself is created elsewhere (when the organizer submits
    //    or resubmits the event, matched by event_id on
    //    director_notifications). Here we just find THAT existing row and
    //    swap its title/message in place — e.g.
    //    "New Event for Review" -> "New Event for Review → Approved" —
    //    instead of leaving it stuck reading "for Review" forever after
    //    the director has already acted on it.
    private function updateDirectorReviewNotif(int $eventId, string $eventTitle, string $status): void
    {
        $directorId = $this->directorId;
        if (!$directorId || !$eventId) return;

        try {
            $existing = DB::table('director_notifications')
                ->where('director_id', $directorId)
                ->where('link_route', 'director.event/management')
                ->where('event_id', $eventId)
                ->first();

            if (!$existing) return; // no submission notif on file — nothing to update

            $prefix = str_contains((string) $existing->title, 'Resubmitted')
                ? 'Event Resubmitted for Review'
                : 'New Event for Review';

            DB::table('director_notifications')
                ->where('id', $existing->id)
                ->update([
                    'title'      => $prefix . ' → ' . $status,
                    'message'    => "\"{$eventTitle}\" has been {$status}.",
                    'icon'       => $status === 'Approved' ? 'calendar-check' : 'calendar',
                    'read'       => 0,
                    'updated_at' => now(),
                ]);

            $this->dispatch('dir-notif-refresh');
        } catch (\Throwable) {
            // Non-critical — don't break the approve/reject action if this fails
        }
    }

    private function autoRejectExpiredPendingEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');

        // ── Case 1: the event date has already passed entirely without
        //    ever being approved — nothing left to prepare for. ──────────
        $pastDue = AdminEvent::withoutTrashed()
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->get(['id', 'title', 'organizer_id']);

        if ($pastDue->isNotEmpty()) {
            AdminEvent::withoutTrashed()
                ->where('status', 'PENDING')
                ->where('event_date', '<=', $now)
                ->update([
                    'status'         => 'REJECTED',
                    'review_remarks' => 'Auto-rejected: event date has already passed without approval.',
                ]);
        }

        // ── Case 2: real-world prep-time rule — a proposed event needs at
        //    least 1 day's lead time to actually get ready for (venue,
        //    materials, alumni notice, etc). If it's still PENDING with
        //    less than 24 hours left before it starts, approving it now
        //    would leave the organizer no real time to prepare, so it
        //    auto-rejects instead of quietly slipping past the deadline
        //    unapproved. This runs BEFORE it actually passes (Case 1
        //    above already covers "already happened"), so the cutoff here
        //    is strictly "still upcoming, but under 24h away". ──────────
        $prepCutoff = $now->copy()->addDay();

        $tooLateToPrep = AdminEvent::withoutTrashed()
            ->where('status', 'PENDING')
            ->where('event_date', '>', $now)
            ->where('event_date', '<=', $prepCutoff)
            ->get(['id', 'title', 'organizer_id']);

        if ($tooLateToPrep->isNotEmpty()) {
            AdminEvent::withoutTrashed()
                ->where('status', 'PENDING')
                ->where('event_date', '>', $now)
                ->where('event_date', '<=', $prepCutoff)
                ->update([
                    'status'         => 'REJECTED',
                    'review_remarks' => 'Auto-rejected: less than 24 hours remained before the event with no approval yet. Events need at least 1 day of lead time to prepare (venue, materials, alumni notice, etc.) — please resubmit with a later date.',
                ]);
        }
    }

    private function autoCompleteExpiredEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');

        $query = fn() => AdminEvent::withoutTrashed()
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            });

        $affected = $query()->get(['id', 'title', 'organizer_id']);

        if ($affected->isEmpty()) return;

        $query()->update(['status' => 'COMPLETED']);
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
            $this->dispatch('event-management-updated', id: $this->editingEventId, title: $title, action: 'updated');
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

        $checkDate  = $event->event_date;
        $prepCutoff = \Carbon\Carbon::now('UTC')->addDay();
        if ($checkDate->lessThanOrEqualTo($prepCutoff)) {
            $datePH = $event->event_date->setTimezone('Asia/Manila')->format('M d, Y g:i A');
            $this->dispatch('flash-message', type: 'error',
                message: "Need to update date — event date ({$datePH}) is too close or has already passed. Please chat the coordinator to update the event date before approving.");
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
            $event      = app(AdminEventController::class)->getEvent($this->approveEventId);
            $checkDate  = $event->event_date;
            $prepCutoff = \Carbon\Carbon::now('UTC')->addDay();
            if ($checkDate->lessThanOrEqualTo($prepCutoff)) {
                $datePH = $event->event_date->setTimezone('Asia/Manila')->format('M d, Y g:i A');
                $this->dispatch('flash-message', type: 'error',
                    message: "Need to update date — event date ({$datePH}) is too close or has already passed.");
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

            $this->notifyOrganizerEvent(
                $event->organizer_id,
                'calendar-check',
                'Event Approved',
                "Your event '{$this->approveEventTitle}' has been approved by the Alumni Director"
                    . (trim($this->approveRemarks) ? " — Remarks: " . trim($this->approveRemarks) : '') . ".",
                'event-management::approved::' . $this->approveEventId,
                $this->approveEventId
            );

            $this->updateDirectorReviewNotif($this->approveEventId, $this->approveEventTitle, 'Approved');

            // ── Refresh director bell so approved events clear from pending count ──
            $this->dispatch('dir-notif-refresh');

            // ── Notify admin: forwarded via the admin events page's JS bridge ──
            $this->dispatch('admin-event-approved-notify', [
                'id'        => $this->approveEventId,
                'title'     => $this->approveEventTitle,
                'submitter' => $event->organizer->name ?? 'Alumni Director',
            ]);

            $this->dispatch('flash-message', type: 'success', message: "'{$this->approveEventTitle}' approved!");
            $this->dispatch('event-management-updated', id: $this->approveEventId, title: $this->approveEventTitle, action: 'approved');
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
            $event = app(AdminEventController::class)->getEvent($this->rejectEventId);

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

            $this->notifyOrganizerEvent(
                $event->organizer_id,
                'calendar',
                'Event Rejected',
                "Your event '{$this->rejectEventTitle}' was rejected by the Alumni Director — Reason: {$this->rejectRemarks}",
                'event-management::rejected::' . $this->rejectEventId,
                $this->rejectEventId
            );

            $this->updateDirectorReviewNotif($this->rejectEventId, $this->rejectEventTitle, 'Rejected');

            // ── Refresh director bell so rejected events clear from pending count ──
            $this->dispatch('dir-notif-refresh');

            $this->dispatch('flash-message', type: 'success', message: "'{$this->rejectEventTitle}' rejected.");
            $this->dispatch('event-management-updated', id: $this->rejectEventId, title: $this->rejectEventTitle, action: 'rejected');
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
        $this->shareEventNotes       = $event->notes ?? '';
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
        $this->shareEventNotes       = '';
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

        // ── Share as a rich card, not plain text ────────────────────────
        // The messenger renders [[EVENT:ADMIN:id]] as a styled preview
        // card (photo, title, date, View Event button) — same behavior
        // as job/event shares on the alumni side. @everyone / coordinator
        // mention line kept above the marker so notifications & mentions
        // still fire correctly.
        $lines = [];
        $lines[] = $isCompleted ? '🏆 @everyone — Event Highlights!' : '📢 @everyone — Event Alert!';
        if ($coordinatorMentionLine) $lines[] = "📋 " . ($isCompleted ? 'Organized by: ' : 'Posted by: ') . $coordinatorMentionLine;
        $lines[] = "[[EVENT:ADMIN:{$event->id}]]";

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

<div class="flex flex-col" wire:poll.3000ms
     style="height: calc(100vh - 180px); max-height: calc(100vh - 180px); overflow: hidden;">

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

.scroll-c {
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-y: contain;
    touch-action: pan-y;
}
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ══ MOBILE: full-screen View/Post/Edit modals — same fix as Manage Job.
   Locks the modal to a real 100dvh (falls back to 100vh) instead of relying
   on the flex children to compute their own height, which is what was
   breaking scroll on phones (0-height flex child = nothing to scroll). ══ */
@media (max-width: 1023px) {
    .fs-in {
        height: 100vh;
        height: 100dvh;
        max-height: 100vh;
        max-height: 100dvh;
    }
    /* The View Event modal's LEFT info pane (photo, date, venue, contact,
       status card) had no height cap on mobile, so it could grow tall
       enough to push the description/notes below out of the scrollable
       area, or fight the outer scroll container for space. Capping it
       keeps the whole-modal scroll (used on mobile — see the
       `overflow-y-auto lg:overflow-hidden` wrapper) predictable. */
    .dir-view-info-pane {
        max-height: 50vh;
        max-height: 50dvh;
    }
}

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

/* ══ Mobile stacked card row — mirrors the Manage Coordinators page ══ */
.dir-mrow {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    background: #fff;
    border-bottom: 1px solid #F0ECF5;
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: background .08s ease;
}
.dir-mrow:active { background: #F7F4FA; }

/* ══ Table container height — mirrors event-organizer's flex-fill card ══ */
.dir-table-card { display: flex; flex-direction: column; min-height: 0; flex: 1; }

@media (max-width: 640px) {
    .dir-table-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: none !important;
        box-shadow: none !important;
    }
}
</style>

{{-- Hover tooltip --}}
<div id="dir-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

{{-- Action button tooltip (fixed-position overlay — escapes the table's scroll clipping) --}}
<div id="dir-action-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold px-2.5 py-1.5 rounded-md whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(-50%, -100%);">
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
     class="fixed top-5 right-4 sm:right-6 z-[10020] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
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

    {{-- ══ PAGE HEADER (matches Dashboard placement — icon + title on the left, mirrors organizer style) ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-calendar-days text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Event Overview</h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                    Review, moderate, and manage
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        all colleges
                    </span>
                </p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
            <i class="fas fa-calendar-days text-purple-600 text-[10px]"></i>
            {{ $this->events->total() }} Event{{ $this->events->total() !== 1 ? 's' : '' }}
        </span>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="dir-table-card flex-1 min-h-0 rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus,filterCollege">

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
            </select>

            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            {{-- Active pill: Status --}}
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

            {{-- Active pill: College --}}
            @if($filterCollege)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-purple-50 border-purple-300 text-purple-800">
                <i class="fas fa-building-columns text-[9px]"></i>
                {{ $filterCollege }}
                <button wire:click="$set('filterCollege', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile college select --}}
            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] flex-1 sm:hidden tw-select-arrow">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col overflow-hidden">

            {{-- Centered loading spinner — mirrors event-organizer's table overlay --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="search,filterStatus,filterCollege,resetFilters,previousPage,nextPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            @if($this->events->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto scroll-c bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-50" wire:target="search,filterStatus,filterCollege,resetFilters,previousPage,nextPage">
                {{-- ── DESKTOP / TABLET: table view ── --}}
                <table class="w-full bg-white border-collapse hidden md:table table-fixed">
                    <colgroup>
                        <col style="width:32%;"><col style="width:20%;"><col style="width:22%;"><col style="width:12%;"><col style="width:14%;">
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Event Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Date &amp; Time</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Action</th>
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
                            $rowCheckDate  = $event->event_date;
                            $rowDateExpired = $rowCheckDate->lessThanOrEqualTo(\Carbon\Carbon::now('UTC')->addDay());
                        @endphp
                        <tr class="bg-white cursor-pointer transition-colors duration-100 hover:bg-[#f5f0fa]"
                            wire:click="viewEvent({{ $event->id }})"
                            wire:key="dir-event-row-{{ $event->id }}"
                            data-dir-row>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333]">{{ $event->title }}</p>
                                <p class="text-xs mt-0.5 text-[#666666] truncate">{{ $eventDate->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <p class="text-sm font-semibold text-[#333333] truncate">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5 text-[#555555] truncate">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold text-[#333333] truncate">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5 text-[#777777] truncate">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="text-xs text-[#bbbbbb]">—</span>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-badge-check text-[9px] mr-1"></i>Approved
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

                            <td class="px-4 sm:px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5" @click.stop>

                                    @if($isCompleted || $isApproved)
                                        <button wire:click.stop="openShareModal({{ $event->id }})"
                                                wire:loading.attr="disabled" wire:target="openShareModal({{ $event->id }})"
                                                data-dir-action data-tip="Share"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                            <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $event->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $event->id }})"></i>
                                        </button>
                                    @endif

                                    @if($isPending)
                                        @if($rowDateExpired)
                                            <span data-dir-action data-tip="Need to update date"
                                                  class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold
                                                         bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed">
                                                <i class="fas fa-check"></i>
                                            </span>
                                        @else
                                            <button wire:click.stop="confirmApprove({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="confirmApprove({{ $event->id }})"
                                                    data-dir-action data-tip="Approve"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 hover:border-emerald-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-check" wire:loading.remove wire:target="confirmApprove({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmApprove({{ $event->id }})"></i>
                                            </button>
                                        @endif
                                        <button wire:click.stop="confirmReject({{ $event->id }})"
                                                wire:loading.attr="disabled" wire:target="confirmReject({{ $event->id }})"
                                                data-dir-action data-tip="Reject"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 hover:border-red-400 disabled:opacity-60 disabled:cursor-wait">
                                            <i class="fas fa-xmark" wire:loading.remove wire:target="confirmReject({{ $event->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmReject({{ $event->id }})"></i>
                                        </button>
                                    @endif

                                    @if($isRejected)
                                        @if($rowDateExpired)
                                            <span data-dir-action data-tip="Need to update date"
                                                  class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold
                                                         bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed">
                                                <i class="fas fa-rotate-left"></i>
                                            </span>
                                        @else
                                            <button wire:click.stop="confirmApprove({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="confirmApprove({{ $event->id }})"
                                                    data-dir-action data-tip="Re-Approve"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 hover:border-emerald-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-rotate-left" wire:loading.remove wire:target="confirmApprove({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmApprove({{ $event->id }})"></i>
                                            </button>
                                        @endif
                                    @endif

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- ── MOBILE: stacked card list ── --}}
                <div class="block md:hidden">
                    @foreach($this->events as $index => $event)
                    @php
                        $isCompleted = $event->status === 'COMPLETED';
                        $isApproved  = $event->status === 'APPROVED';
                        $isPending   = $event->status === 'PENDING';
                        $isRejected  = $event->status === 'REJECTED';
                        $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                        $rowCheckDate  = $event->event_date;
                        $rowDateExpired = $rowCheckDate->lessThanOrEqualTo(\Carbon\Carbon::now('UTC')->addDay());
                    @endphp
                    <div class="dir-mrow" wire:key="dir-event-mrow-{{ $event->id }}" wire:click="viewEvent({{ $event->id }})" data-dir-row>

                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333]">{{ $event->title }}</p>
                            <p class="text-xs mt-0.5 text-[#666666]">{{ $eventDate->diffForHumans() }}</p>

                            <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                <span class="text-xs font-semibold text-[#333333]">{{ $eventDate->format('M d, Y') }}</span>
                                <span class="text-gray-300 text-xs">&bull;</span>
                                <span class="text-xs text-[#555555]">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </span>
                            </div>

                            @if($event->organizer)
                                <p class="text-xs mt-1 text-[#777777] truncate">
                                    <i class="fas fa-user-tie text-[9px] mr-1"></i>{{ $event->organizer->name }} &middot; {{ $event->organizer->department }}
                                </p>
                            @endif

                            <div class="flex items-center justify-between gap-2 mt-2">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-badge-check text-[9px] mr-1"></i>Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-xl border border-yellow-200 bg-yellow-50 text-yellow-700 whitespace-nowrap">
                                        <i class="fas fa-hourglass-half text-[9px] mr-1"></i>Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif

                                <div class="flex items-center gap-1.5" @click.stop>
                                    @if($isCompleted || $isApproved)
                                        <button wire:click.stop="openShareModal({{ $event->id }})"
                                                wire:loading.attr="disabled" wire:target="openShareModal({{ $event->id }})"
                                                aria-label="Share"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-blue-100 text-blue-600 border border-blue-200 active:bg-white active:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                            <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $event->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $event->id }})"></i>
                                        </button>
                                    @endif

                                    @if($isPending)
                                        @if($rowDateExpired)
                                            <span aria-label="Need to update date"
                                                  class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold
                                                         bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed">
                                                <i class="fas fa-check"></i>
                                            </span>
                                        @else
                                            <button wire:click.stop="confirmApprove({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="confirmApprove({{ $event->id }})"
                                                    aria-label="Approve"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-emerald-50 text-emerald-700 border border-emerald-200 active:bg-emerald-100 active:border-emerald-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-check" wire:loading.remove wire:target="confirmApprove({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmApprove({{ $event->id }})"></i>
                                            </button>
                                        @endif
                                        <button wire:click.stop="confirmReject({{ $event->id }})"
                                                wire:loading.attr="disabled" wire:target="confirmReject({{ $event->id }})"
                                                aria-label="Reject"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-red-50 text-red-600 border border-red-200 active:bg-red-100 active:border-red-400 disabled:opacity-60 disabled:cursor-wait">
                                            <i class="fas fa-xmark" wire:loading.remove wire:target="confirmReject({{ $event->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmReject({{ $event->id }})"></i>
                                        </button>
                                    @endif

                                    @if($isRejected)
                                        @if($rowDateExpired)
                                            <span aria-label="Need to update date"
                                                  class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold
                                                         bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed">
                                                <i class="fas fa-rotate-left"></i>
                                            </span>
                                        @else
                                            <button wire:click.stop="confirmApprove({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="confirmApprove({{ $event->id }})"
                                                    aria-label="Re-Approve"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-emerald-50 text-emerald-700 border border-emerald-200 active:bg-emerald-100 active:border-emerald-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-rotate-left" wire:loading.remove wire:target="confirmApprove({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmApprove({{ $event->id }})"></i>
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-calendar-days text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-[#333333]">
                        @if($search || $filterStatus || $filterCollege) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($search || $filterStatus || $filterCollege) Try clearing your filters to see all events.
                        @else No events have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterCollege)
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
                @if($filterStatus || $filterCollege || $search)
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


{{-- ══ APPROVE CONFIRM MODAL ══ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelApprove">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b border-emerald-100 bg-emerald-50">
            <h2 class="text-base font-semibold text-emerald-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-badge-check text-emerald-600 text-sm"></i>
                </div>
                Approve Event
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">You are about to approve:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $approveEventTitle }}
            </p>
            <div class="mb-4">
                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                    Remarks <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                </label>
                <textarea wire:model.defer="approveRemarks" rows="2"
                          placeholder="e.g. Approved. Great event proposal!"
                          class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"></textarea>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelApprove"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer">
                    <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                </button>
                <button wire:click="executeApprove"
                        wire:loading.attr="disabled"
                        wire:target="executeApprove"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-emerald-500 hover:bg-emerald-600 transition cursor-pointer disabled:opacity-60">
                    <span wire:loading wire:target="executeApprove"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-badge-check mr-1 text-xs"></i></span>
                    Yes, Approve
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ REJECT CONFIRM MODAL ══ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelReject">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b border-red-100 bg-red-50">
            <h2 class="text-base font-semibold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-circle-xmark text-red-500 text-sm"></i>
                </div>
                Reject Event
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">You are about to reject:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $rejectEventTitle }}
            </p>
            <div class="mb-4">
                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                    Reason for Rejection <span class="text-red-500">*</span>
                </label>
                <textarea wire:model.defer="rejectRemarks" rows="3"
                          placeholder="e.g. Missing required details. Please revise and resubmit."
                          class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"></textarea>
                <p class="text-[10px] mt-1 text-[#777777]">
                    <i class="fas fa-circle-info text-[9px] mr-1"></i>Required — coordinator will see this reason.
                </p>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelReject"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer">
                    <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                </button>
                <button wire:click="executeReject"
                        wire:loading.attr="disabled"
                        wire:target="executeReject"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-60">
                    <span wire:loading wire:target="executeReject"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1 text-xs"></i></span>
                    Yes, Reject
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ EDIT EVENT — FULL SCREEN (matching organizer form style) ══ --}}
@if($showFormModal)
<div class="fixed inset-0 z-[100] flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeFormModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 flex-shrink-0 shadow-lg"
         style="background: #7a3f91;">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Edit Event</h2>
                <p class="text-white/60 text-xs mt-0.5">Update event details — changes are saved immediately</p>
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            <div class="relative inline-flex group">
                <button wire:click="closeFormModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeFormModal"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm" wire:loading.remove wire:target="closeFormModal"></i>
                    <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="closeFormModal"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    @if(count($formErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-2 flex-shrink-0 flex items-start gap-3">
        <i class="fas fa-triangle-exclamation text-red-500 flex-shrink-0 text-xs mt-1"></i>
        <div>
            <p class="text-sm font-semibold text-red-800">Please fix the following:</p>
            <ul class="text-xs text-red-700 mt-1 space-y-0.5">
                @foreach($formErrors as $err)
                    <li class="flex items-start gap-1"><span class="text-red-400">&bull;</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden">

        {{-- LEFT COLUMN — Photo + Target --}}
        <div class="w-full lg:w-72 xl:w-76 flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-visible lg:overflow-y-auto bg-white"
             style="scrollbar-width:thin;">
            <div class="p-3 space-y-3">

                {{-- Event Photo --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
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

                {{-- Target Participants --}}
                <div class="bg-white border-[1.5px] {{ isset($formErrors['target']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Target Participants
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-2.5 space-y-2.5">
                        <div class="flex gap-2">
                            <button type="button" wire:click="$set('targetMode','all')"
                                    class="flex-1 py-2 px-2 border-2 rounded-xl text-xs font-semibold transition flex flex-col items-center gap-1
                                           {{ $targetMode==='all' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white text-[#666666]' }}">
                                <i class="fas fa-globe text-sm"></i><span>All Colleges</span>
                            </button>
                            <button type="button" wire:click="$set('targetMode','college')"
                                    class="flex-1 py-2 px-2 border-2 rounded-xl text-xs font-semibold transition flex flex-col items-center gap-1
                                           {{ $targetMode==='college' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white text-[#666666]' }}">
                                <i class="fas fa-building-columns text-sm"></i><span>Specific</span>
                            </button>
                        </div>

                        @if($targetMode === 'all')
                            <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-lg px-2.5 py-1.5">
                                <i class="fas fa-globe text-purple-500 text-xs flex-shrink-0"></i>
                                <span class="text-xs font-semibold text-purple-800">Visible to all alumni across all colleges</span>
                            </div>
                        @else
                            @if(isset($formErrors['target']))
                                <p class="text-red-600 text-xs flex items-center gap-1 font-semibold">
                                    <i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['target'] }}
                                </p>
                            @endif

                            @if(count($this->colleges) > 0)
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider text-[#555555]">Select college(s)</span>
                                    <div class="flex gap-2">
                                        <button type="button"
                                                wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})"
                                                class="text-xs font-semibold hover:underline text-[#7a3f91]">
                                            <i class="fas fa-check-double mr-0.5 text-[10px]"></i>All
                                        </button>
                                        @if(count($selectedColleges) > 0)
                                            <button type="button" wire:click="$set('selectedColleges', [])"
                                                    class="text-xs font-semibold hover:text-red-500 text-[#555555]">Clear</button>
                                        @endif
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 gap-1 {{ isset($formErrors['target']) ? 'p-1.5 rounded-lg border border-red-200 bg-red-50/30' : '' }}">
                                    @foreach($this->colleges as $col)
                                        <label class="flex items-center gap-1.5 px-2 py-1.5 border rounded-lg cursor-pointer transition text-xs font-semibold
                                                      {{ in_array($col, $selectedColleges)
                                                          ? 'border-purple-400 bg-purple-50 text-purple-700'
                                                          : 'border-gray-200 hover:border-purple-300 hover:bg-purple-50/40 bg-white text-[#333333]' }}">
                                            <input type="checkbox" wire:model.live="selectedColleges" value="{{ $col }}"
                                                   class="accent-purple-600 w-3 h-3 flex-shrink-0">
                                            <span class="truncate text-[11px]">{{ $col }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        @endif

                        <div class="pt-2 border-t border-gray-100">
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Batch Year <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                            </label>
                            <input wire:model.defer="batchYear" type="number"
                                   min="1990" max="{{ now()->year + 5 }}"
                                   placeholder="e.g. {{ now()->year - 2 }}"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['batch_year']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($formErrors['batch_year']))
                                <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['batch_year'] }}</p>
                            @else
                                <p class="text-[10px] mt-1 text-[#777777]">Leave blank to target all batches.</p>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE COLUMN — Event Details --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto flex flex-col p-3 gap-3" style="scrollbar-width:thin;">

                <div class="flex flex-col bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden" style="min-height: 0; flex: 1;">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
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
                                Description
                            </label>
                            <textarea wire:model.defer="description"
                                      placeholder="Describe the event, agenda, highlights…" maxlength="5000"
                                      class="flex-1 w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto"
                                      style="min-height: 80px;"></textarea>
                        </div>

                        <div class="flex-shrink-0 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Date <span class="text-red-500">*</span>
                                </label>
                                <input wire:model="event_date" type="date"
                                       min="{{ now('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['event_date']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['event_date']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['event_date'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Start Time <span class="text-red-500">*</span>
                                </label>
                                <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['start_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($formErrors['start_time']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $formErrors['start_time'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    End Time <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                                </label>
                                <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($formErrors['end_time']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
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
                                    Full Address <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                                </label>
                                <input wire:model.defer="venue_address" type="text"
                                       placeholder="e.g. Old Nalsian Road, Calasiao, Pangasinan" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                            </div>
                        </div>

                    </div>
                </div>

                <div class="flex-shrink-0 bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Notes / Requirements
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-2.5">
                        <textarea wire:model.defer="notes"
                                  placeholder="Dress code, special instructions, what to bring…" maxlength="3000"
                                  class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 overflow-y-auto"
                                  style="height: 160px;"></textarea>
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT COLUMN — Contact + Save --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto" style="scrollbar-width:thin;">
            <div class="p-3 space-y-3 flex-1">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Contact Person
                        @if($editingIsOrganizerEvent)
                            <span class="ml-auto inline-flex items-center gap-1 text-[9px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded-lg">
                                <i class="fas fa-lock text-[8px]"></i> Read only
                            </span>
                        @else
                            <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— pre-filled</span>
                        @endif
                    </div>
                    <div class="p-2.5 space-y-2.5">
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Name</label>
                            <input wire:model.defer="contact_person" type="text" placeholder="Full name"
                                   @if($editingIsOrganizerEvent) readonly @endif
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ $editingIsOrganizerEvent ? 'border-gray-200 bg-gray-50 cursor-not-allowed text-[#999999]' : 'border-gray-300' }}">
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Email</label>
                            <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com"
                                   @if($editingIsOrganizerEvent) readonly @endif
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ $editingIsOrganizerEvent ? 'border-gray-200 bg-gray-50 cursor-not-allowed text-[#999999]' : 'border-gray-300' }}">
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Phone</label>
                            <input wire:model.defer="contact_phone" type="text" placeholder="+63 9XX XXX XXXX"
                                   @if($editingIsOrganizerEvent) readonly @endif
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ $editingIsOrganizerEvent ? 'border-gray-200 bg-gray-50 cursor-not-allowed text-[#999999]' : 'border-gray-300' }}">
                        </div>
                        @if($editingIsOrganizerEvent)
                            <p class="text-[10px] text-[#777777]"><i class="fas fa-circle-info text-[9px] mr-1"></i>Contact belongs to the coordinator — cannot be edited here.</p>
                        @endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-white border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Director Notes
                    </div>
                    <div class="p-2.5">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Changes take effect immediately after saving.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Editing a coordinator's event updates the record but does not send them a separate notification.</span>
                            </li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i>
                                <span>Use Approve / Reject from the event view to notify coordinators.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="saveEvent"
                        wire:loading.attr="disabled" wire:target="saveEvent"
                        class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <span wire:loading wire:target="saveEvent">
                        <i class="fas fa-spinner animate-spin text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">
                        <i class="fas fa-floppy-disk text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="saveEvent">Save Changes</span>
                    <span wire:loading wire:target="saveEvent">Saving…</span>
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

    $approveCheckDate = $ev->event_date;
    $eventDateExpired = $approveCheckDate->lessThanOrEqualTo(\Carbon\Carbon::now('UTC')->addDay());
@endphp

<div class="fixed inset-0 z-[100] flex flex-col bg-gray-50 overflow-hidden fs-in"
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

            @if($isPending)
                <div class="relative inline-flex group">
                    <button wire:click="confirmReject({{ $ev->id }})"
                            wire:loading.attr="disabled" wire:target="confirmReject({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Reject">
                        <i class="fas fa-xmark text-white text-sm" wire:loading.remove wire:target="confirmReject({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="confirmReject({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Reject
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
                @if($eventDateExpired)
                <div class="relative inline-flex group">
                    <span class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white/10 border border-white/15 cursor-not-allowed"
                          aria-label="Need to update date">
                        <i class="fas fa-check text-white/50 text-sm"></i>
                    </span>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Need to update date
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
                @else
                <div class="relative inline-flex group">
                    <button wire:click="confirmApprove({{ $ev->id }})"
                            wire:loading.attr="disabled" wire:target="confirmApprove({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Approve">
                        <i class="fas fa-check text-white text-sm" wire:loading.remove wire:target="confirmApprove({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="confirmApprove({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Approve
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
                @endif
            @endif

            @if($isRejected)
                @if($eventDateExpired)
                <div class="relative inline-flex group">
                    <span class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white/10 border border-white/15 cursor-not-allowed"
                          aria-label="Need to update date">
                        <i class="fas fa-rotate-left text-white/50 text-sm"></i>
                    </span>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Need to update date
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
                @else
                <div class="relative inline-flex group">
                    <button wire:click="confirmApprove({{ $ev->id }})"
                            wire:loading.attr="disabled" wire:target="confirmApprove({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Re-Approve">
                        <i class="fas fa-rotate-left text-white text-sm" wire:loading.remove wire:target="confirmApprove({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="confirmApprove({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Re-Approve
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
                @endif
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeViewModal"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
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

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden scroll-c">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white lg:overflow-y-auto scroll-c dir-view-info-pane">

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
                        @elseif($isPending)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Pending</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-700/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Rejected</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-5 mt-5 mb-3 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center h-20"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isCompleted)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-bold">Completed</span>
                    @elseif($isApproved)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-bold">Approved</span>
                    @elseif($isPending)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-bold">Pending</span>
                    @else<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-bold">Rejected</span>@endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-3 px-5 pb-5">

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Date &amp; Time</p>
                    <p class="text-lg font-bold text-[#333333]">{{ $eventDatePH->format('F d, Y') }}</p>
                    <p class="text-base font-semibold mt-0.5 text-[#333333]">{{ $timeDisplay }}</p>
                </div>

                @if($ev->venue)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Venue</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->venue }}</p>
                    @if($ev->venue_address)<p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->venue_address }}</p>@endif
                </div>
                @endif

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 flex flex-col gap-2.5">

                    @if($ev->target_participants)
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-widest mb-0.5 text-[#333333]">Open For</p>
                        <p class="text-sm font-bold text-[#333333]">{{ $ev->target_participants }}</p>
                    </div>
                    @endif

                    <div class="{{ $ev->target_participants ? 'pt-2 border-t border-gray-200' : '' }}">
                        <p class="text-[9px] font-bold uppercase tracking-widest mb-0.5 text-[#333333]">{{ $ev->organizer ? 'Coordinator' : 'Posted By' }}</p>
                        <p class="text-sm font-bold text-[#333333]">{{ $postedByLabel }}</p>
                    </div>

                    @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                    <div class="pt-2 border-t border-gray-200">
                        <p class="text-[9px] font-bold uppercase tracking-widest mb-1 text-[#333333]">Contact</p>
                        <div class="flex flex-col gap-1">
                            @if($ev->contact_person)<p class="text-sm font-bold text-[#333333]">{{ $ev->contact_person }}</p>@endif
                            @if($ev->contact_email)<p class="text-xs font-medium text-[#333333]">{{ $ev->contact_email }}</p>@endif
                            @if($ev->contact_phone)<p class="text-xs font-medium text-[#333333]">{{ $ev->contact_phone }}</p>@endif
                        </div>
                    </div>
                    @endif

                </div>

                <div class="p-4 rounded-xl border {{ $isCompleted ? 'bg-green-50 border-green-200' : ($isApproved ? 'bg-emerald-50 border-emerald-200' : ($isPending ? 'bg-amber-50 border-amber-200' : 'bg-orange-50 border-orange-200')) }}">
                    @if($isCompleted)
                        <p class="text-base font-bold text-[#333333]">Completed</p>
                        <p class="text-sm font-medium mt-0.5 text-[#333333]">This event has already taken place.</p>
                    @elseif($isApproved)
                        <p class="text-base font-bold text-[#333333]">Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm italic mt-1 text-[#555555]">"{{ $ev->review_remarks }}"</p>@endif
                    @elseif($isPending)
                        <p class="text-base font-bold text-[#333333]">Awaiting Review</p>
                        @if($eventDateExpired)
                            <p class="text-sm font-semibold mt-0.5 text-[#333333]">Need to update date. Please chat the coordinator to update the event date before this can be approved.</p>
                        @else
                            <p class="text-sm font-medium mt-0.5 text-[#333333]">Use the Approve / Reject buttons above.</p>
                        @endif
                    @else
                        <p class="text-base font-bold text-[#333333]">Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm font-medium mt-0.5 text-[#333333]"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                        @if($eventDateExpired)
                            <p class="text-sm font-semibold mt-1 text-[#333333]">Need to update date. Please chat the coordinator to update the event date before this can be re-approved.</p>
                        @else
                            <p class="text-sm font-semibold mt-1 text-[#333333]">Coordinator may edit and resubmit.</p>
                        @endif
                    @endif
                </div>

                @if($updatedByDisplay)
                <div class="px-4 py-3 rounded-xl bg-gray-50 border border-gray-100 text-xs text-[#555555]">
                    <span class="font-semibold">Last updated by:</span> {{ $updatedByDisplay }}
                    <span class="ml-1 font-semibold text-[#7a3f91]">({{ $roleDisplayLabel }})</span>
                    <span class="ml-1">· {{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</span>
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

            <div class="flex-1 min-h-0 lg:overflow-y-auto scroll-c px-6 py-5 flex flex-col gap-5">

                @if($ev->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col lg:flex-1 lg:min-h-0">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex-shrink-0">
                        <p class="text-[12px] font-bold uppercase tracking-widest text-[#333333]">About This Event</p>
                    </div>
                    <div class="px-5 py-4 lg:flex-1 lg:overflow-y-auto scroll-c">
                        <p class="text-sm leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->description) }}</p>
                    </div>
                </div>
                @endif

                @if($ev->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden flex flex-col lg:flex-1 lg:min-h-0">
                    <div class="px-5 py-3 border-b border-gray-100 bg-amber-50 flex-shrink-0">
                        <p class="text-[12px] font-bold uppercase tracking-widest text-[#333333]">Additional Notes</p>
                    </div>
                    <div class="px-5 py-4 lg:flex-1 lg:overflow-y-auto scroll-c">
                        <p class="text-sm leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->notes) }}</p>
                    </div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-base font-medium text-[#555555]">No additional details provided.</p>
                    </div>
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
    $isCompleted = $shareEventStatus === 'COMPLETED';

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
@keyframes dirPanelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.dir-share-sheet { animation: dirPanelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.dir-share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Share modal: full screen on mobile, centered card on desktop ── */
@media (max-width: 767px) {
    .dir-share-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
    }
    .dir-share-backdrop .dir-share-sheet {
        border-radius: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
    }
}

.dir-share-close-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    cursor: pointer; transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
}
.dir-share-close-btn:hover  { background: #e5e7eb; border-color: #d1d5db; }
.dir-share-close-btn:active { transform: scale(.93); }
.dir-share-close-btn svg    { width: 14px; height: 14px; stroke: #4b5563; stroke-width: 2.25; stroke-linecap: round; }
.dir-share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.dir-share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.dir-share-close-btn:hover .tip { opacity: 1; }

.dir-share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .12s ease-out, transform .1s ease-out; border: none;
    will-change: transform;
}
.dir-share-option-btn:hover  { filter: brightness(0.94); }
.dir-share-option-btn:active { transform: scale(.97); transition-duration: .05s; }
.dir-share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.dir-share-option-btn .label-text { flex: 1; text-align: left; }

.dir-share-photo-preview {
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
.dir-share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain;
}
.dir-share-photo-preview .dl-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(17,24,39,.75); color: #fff;
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px;
    display: flex; align-items: center; gap: 4px;
    pointer-events: none;
}

.dir-dl-confirm-icon {
    width: 3rem; height: 3rem; border-radius: 0.9rem;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.dir-dl-confirm-btn {
    flex: 1; padding: 0.65rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .15s, transform .1s; border: none;
}
.dir-dl-confirm-btn:active { transform: scale(.97); }
.dir-dl-confirm-btn.primary { background: #7a3f91; color: #fff; }
.dir-dl-confirm-btn.primary:hover { filter: brightness(0.95); }
.dir-dl-confirm-btn.secondary { background: #f3f4f6; color: #333333; border: 1px solid #e5e7eb; }
.dir-dl-confirm-btn.secondary:hover { background: #e5e7eb; }
</style>

<div id="dir-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 dir-share-backdrop"
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
             const win = window.open(url, 'philcst_dir_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
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
             const win = window.open('https://www.messenger.com/new', 'philcst_dir_messenger_share', 'noopener,noreferrer');
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

    <div class="dir-share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 dir-share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Event
            </h2>
            <button wire:click="closeShareModal" type="button" class="dir-share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-c">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                @if($shareEventPhotoUrl)
                <div class="dir-share-photo-preview">
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
                    <button type="button" @click="nativeShare()" class="dir-share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" class="dir-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Share on Facebook</span>
                </button>

                <button type="button" @click="askShare('messenger')" class="dir-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Send via Messenger</span>
                </button>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-purple-200 hover:border-purple-400 hover:bg-purple-50 disabled:opacity-60 disabled:cursor-not-allowed bg-purple-50 text-purple-700">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-[#7a3f91]">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            Post to Chat Room
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="flex items-center gap-1.5 text-xs mt-0.5 text-purple-600">
                            Directors &amp; Coordinators
                            · <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-[#7a3f91] text-white text-[9px] font-semibold">
                                <i class="fas fa-at text-[8px]"></i>everyone
                            </span>
                        </span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-[#7a3f91]"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white" style="color:#333333;">or copy caption</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300
                               hover:bg-gray-50 active:scale-[.98] text-sm transition-all duration-150 cursor-pointer bg-white" style="color:#333333;">
                    <span class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-sm" :style="copied ? '' : 'color:#333333;'"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : ''" :style="copied ? '' : 'color:#333333;'" x-text="copied ? 'Caption copied!' : 'Copy Caption'"></p>
                        <p class="text-[10px] truncate" style="color:#333333;">Copies the post text (photo not included)</p>
                    </div>
                </button>

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
        <div class="dir-share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="dir-dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold" style="color:#333333;">Download the event photo?</p>
                    <p class="text-xs mt-1 leading-relaxed" style="color:#333333;">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($shareEventPhotoUrl)
            <div class="dir-share-photo-preview" style="height:110px;">
                <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="dir-dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="dir-dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>

{{-- ══ CLEAN-URL SCRIPT (strip ?event=46 from address bar on load) ══ --}}
<script>
    (function () {
        // Pure client-side: just rewrites the address bar in place so the
        // URL shows /director/event/management instead of ?event=46 — no
        // navigation, no reload, so it never touches the View Event modal
        // that the server already opened on this page load via viewEvent().
        if (window.location.search.indexOf('event=') !== -1) {
            var cleanUrl = window.location.origin + window.location.pathname;
            window.history.replaceState({}, '', cleanUrl);
        }
    })();
</script>

<script>
(function () {
    var tip       = document.getElementById('dir-hover-tip');
    var actionTip = document.getElementById('dir-action-tip');

    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }

    function bindRows() {
        document.querySelectorAll('[data-dir-row]').forEach(function (row) {
            if (row._dirTipBound) return;
            row._dirTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip || !isHoverCapable()) return;
                var actionWrap = e.target.closest('[data-dir-action]');
                if (actionWrap) {
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

        document.querySelectorAll('[data-dir-action]').forEach(function (sw) {
            if (sw._dirActionBound) return;
            sw._dirActionBound = true;
            sw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    // Fixed-position tooltip for Share/Approve/Reject/Re-Approve buttons inside
    // the vertical scrollable table — escapes the scroll container's clipping
    // so the label is always fully readable, even near the top/bottom edges.
    function bindActionTips() {
        if (!actionTip) return;
        document.querySelectorAll('[data-tip]').forEach(function (btn) {
            if (btn._dirActionTipBound) return;
            btn._dirActionTipBound = true;

            btn.addEventListener('mouseenter', function () {
                var rect = btn.getBoundingClientRect();
                actionTip.textContent  = btn.getAttribute('data-tip');
                actionTip.style.left   = (rect.left + rect.width / 2) + 'px';
                actionTip.style.top    = (rect.top - 8) + 'px';
                actionTip.style.opacity = '1';
            });

            btn.addEventListener('mouseleave', function () {
                actionTip.style.opacity = '0';
            });

            btn.addEventListener('click', function () {
                actionTip.style.opacity = '0';
            });
        });
    }

    bindRows();
    bindActionTips();
    document.addEventListener('livewire:updated', function () {
        bindRows();
        bindActionTips();
    });
})();
</script>