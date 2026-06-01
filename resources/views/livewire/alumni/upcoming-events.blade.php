{{-- resources/views/livewire/alumni/upcoming-events.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\AdminEvent;
use App\Models\OrganizerEvent;
use App\Models\EventRsvp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public string $search       = '';
    public string $filterStatus = 'upcoming';

    public bool   $showViewModal     = false;
    public ?int   $viewingEventId    = null;
    public ?string $viewingEventType = null;

    public bool   $showRsvpModal = false;
    public ?string $rsvpResponse  = null;
    public string $rsvpMessage    = '';

    public string $alumniCollege = '';
    public array  $alumniCourses = [];

    public bool   $showShareModal    = false;
    public ?int   $shareEventId      = null;
    public string $shareEventType    = '';
    public string $shareEventTitle   = '';
    public string $shareVenue        = '';
    public string $shareDate         = '';
    public string $shareTime         = '';
    public string $shareEndTime      = '';
    public string $shareDescription  = '';
    public string $shareOrganizer    = '';
    public string $shareTargetParts  = '';
    public string $sharePhotoUrl     = '';
    public bool   $shareIsCompleted  = false;

    public int    $alumniId     = 0;
    public int    $alumniRoomId = 0;

    public int $page    = 1;
    public int $perPage = 20;

    public function mount(): void
    {
        set_time_limit(600);
        $user = Auth::user();
        if (!$user || !$user->alumni) abort(403, 'Access denied.');
        $alumni = $user->alumni;

        $this->alumniId      = $alumni->id;
        $this->alumniCourses = $alumni->course ? [$alumni->course->code] : [];
        $this->alumniCollege = $alumni->course?->college ?? '';

        $room = DB::table('chat_rooms')
            ->where('course_code', $alumni->course_code)
            ->where('batch', $alumni->batch)
            ->first();
        $this->alumniRoomId = $room ? (int) $room->id : 0;
    }

    public function resetFilters(): void
    {
        $this->search       = '';
        $this->filterStatus = 'upcoming';
        $this->page         = 1;
    }

    public function updatingSearch(): void
    {
        $this->page = 1;
        // FIX: close any open modal when filters change to prevent ghost re-opens
        $this->showViewModal    = false;
        $this->viewingEventId   = null;
        $this->viewingEventType = null;
    }

    public function updatingFilterStatus(): void
    {
        $this->page = 1;
        // FIX: close any open modal when filters change to prevent ghost re-opens
        $this->showViewModal    = false;
        $this->viewingEventId   = null;
        $this->viewingEventType = null;
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) $this->page++;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) $this->page--;
    }

    #[Computed]
    public function events()
    {
        $college = $this->alumniCollege;
        $courses = $this->alumniCourses;

        if (!$college || empty($courses)) return collect();

        $now = \Carbon\Carbon::now('UTC');

        $adminQ = AdminEvent::withoutTrashed()
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(function ($q) use ($college) {
                $q->where('target_participants', 'like', 'All Colleges%')
                  ->orWhere('target_participants', 'like', "%{$college}%");
            })
            ->select([
                'id','title','description','event_date','event_end_date',
                'venue','venue_address','contact_person','contact_email',
                'contact_phone','notes','photo','status','target_participants',
                'organizer_id','review_remarks','reviewed_at',
                'created_at','updated_at',
                DB::raw("'ADMIN' as event_source"),
            ])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

        $organizerQ = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where(function ($q) use ($college, $courses) {
                $q->where('target_participants', 'like', 'All Courses%')
                  ->orWhere(function ($sub) use ($courses) {
                      foreach ($courses as $course) {
                          $sub->orWhere('target_participants', 'like', "%{$course}%");
                      }
                  });
            })
            ->select([
                'id','title','description','event_date','event_end_date',
                'venue','venue_address','contact_person','contact_email',
                'contact_phone','notes','photo','status','target_participants',
                'organizer_id','review_remarks','reviewed_at',
                'created_at','updated_at',
                DB::raw("'ORGANIZER' as event_source"),
            ])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

        if ($this->filterStatus === 'upcoming') {
            $adminQ->where(function ($q) use ($now) {
                $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $now))
                  ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '>', $now));
            });
            $organizerQ->where(function ($q) use ($now) {
                $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $now))
                  ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '>', $now));
            });
        } elseif ($this->filterStatus === 'completed') {
            $adminQ->where(function ($q) use ($now) {
                $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '<=', $now))
                  ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '<=', $now));
            });
            $organizerQ->where(function ($q) use ($now) {
                $q->where(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '<=', $now))
                  ->orWhere(fn($s) => $s->whereNull('event_end_date')->where('event_date', '<=', $now));
            });
        }

        if ($this->search !== '') {
            $s = trim($this->search);
            $adminQ->where(fn($sub) => $sub->where('title', 'like', "%{$s}%")->orWhere('venue', 'like', "%{$s}%"));
            $organizerQ->where(fn($sub) => $sub->where('title', 'like', "%{$s}%")->orWhere('venue', 'like', "%{$s}%"));
        }

        return $adminQ->get()->concat($organizerQ->get())->sortByDesc('created_at')->values();
    }

    #[Computed]
    public function pagedEvents()
    {
        $all = $this->events;
        if ($this->filterStatus === 'upcoming') return $all;
        return $all->slice(($this->page - 1) * $this->perPage, $this->perPage)->values();
    }

    #[Computed]
    public function totalPages(): int
    {
        if ($this->filterStatus === 'upcoming') return 1;
        return max(1, (int) ceil($this->events->count() / $this->perPage));
    }

    #[Computed]
    public function viewingEvent()
    {
        if (!$this->viewingEventId || !$this->viewingEventType) return null;
        $counts = [
            'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
            'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
            'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
        ];
        if ($this->viewingEventType === 'ADMIN') {
            return AdminEvent::withoutTrashed()->where('id', $this->viewingEventId)
                ->whereIn('status', ['APPROVED', 'COMPLETED'])->withCount($counts)->first();
        }
        return OrganizerEvent::where('id', $this->viewingEventId)
            ->whereIn('status', ['APPROVED', 'COMPLETED'])->withCount($counts)->first();
    }

    #[Computed]
    public function alumniRsvp()
    {
        if (!$this->viewingEventId) return null;
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return null;
        return EventRsvp::where('event_id', $this->viewingEventId)
            ->where('alumni_id', $alumni->id)->first();
    }

    public function viewEvent(int $id, string $type): void
    {
        $this->viewingEventId   = $id;
        $this->viewingEventType = $type;
        $this->showViewModal    = true;
        $this->resetRsvpModal();
    }

    public function closeViewModal(): void
    {
        $this->showViewModal    = false;
        $this->viewingEventId   = null;
        $this->viewingEventType = null;
        $this->resetRsvpModal();
    }

    public function openRsvpModal(): void  { $this->showRsvpModal = true; }
    public function closeRsvpModal(): void { $this->showRsvpModal = false; $this->resetRsvpModal(); }
    private function resetRsvpModal(): void { $this->rsvpResponse = null; $this->rsvpMessage = ''; }

    public function submitRsvp(string $response): void
    {
        $user   = Auth::user();
        $alumni = $user?->alumni;
        if (!$alumni || !$this->viewingEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Something went wrong. Please try again.');
            return;
        }
        try {
            EventRsvp::updateOrCreate(
                ['event_id' => $this->viewingEventId, 'alumni_id' => $alumni->id],
                ['response' => $response, 'message' => trim($this->rsvpMessage) ?: null]
            );
            $this->dispatch('flash-message', type: 'success', message: "Your RSVP has been recorded as {$response}!");
            $this->closeRsvpModal();
            $this->closeViewModal();
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to save RSVP. Please try again.');
        }
    }

    public function openShareModal(int $id, string $type): void
    {
        $event = $type === 'ADMIN'
            ? AdminEvent::withoutTrashed()->where('id', $id)->whereIn('status', ['APPROVED', 'COMPLETED'])->first()
            : OrganizerEvent::where('id', $id)->whereIn('status', ['APPROVED', 'COMPLETED'])->first();

        if (!$event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                       (!$event->event_end_date && $event->event_date <= now('UTC'));

        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');

        $this->shareEventId      = $id;
        $this->shareEventType    = $type;
        $this->shareEventTitle   = $event->title;
        $this->shareVenue        = $event->venue ?? '';
        $this->shareDate         = $eventDatePH->format('F d, Y');
        $this->shareTime         = $eventDatePH->format('g:i A');
        $this->shareEndTime      = $eventEndPH ? $eventEndPH->format('g:i A') : '';
        $this->shareDescription  = $event->description ?? '';
        $this->shareTargetParts  = $event->target_participants ?? '';
        $this->sharePhotoUrl     = $event->photo_url ?? '';
        $this->shareOrganizer    = $type === 'ADMIN' ? 'PHILCST Admin' : ($event->organizer?->name ?? 'Organizer');
        $this->shareIsCompleted  = $isCompleted;
        $this->showShareModal    = true;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal   = false;
        $this->shareEventId     = null;
        $this->shareEventType   = '';
        $this->shareEventTitle  = '';
        $this->shareVenue       = '';
        $this->shareDate        = '';
        $this->shareTime        = '';
        $this->shareEndTime     = '';
        $this->shareDescription = '';
        $this->shareOrganizer   = '';
        $this->shareTargetParts = '';
        $this->sharePhotoUrl    = '';
        $this->shareIsCompleted = false;
    }

    public function eventsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('upcoming.events', [], false); } catch (\Throwable) { $path = '/upcoming/events'; }
        return $base . $path;
    }

    public function postToBatchChat(): void
    {
        if (!$this->shareEventId || !$this->alumniRoomId) {
            $this->dispatch('flash-message', type: 'error', message: 'Could not find your batch chat room.');
            return;
        }

        $type  = $this->shareEventType;
        $event = $type === 'ADMIN'
            ? AdminEvent::withoutTrashed()->where('id', $this->shareEventId)->whereIn('status', ['APPROVED', 'COMPLETED'])->first()
            : OrganizerEvent::where('id', $this->shareEventId)->whereIn('status', ['APPROVED', 'COMPLETED'])->first();

        if (!$event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr     = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
        $isCompleted = $this->shareIsCompleted;

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
            $lines[] = "Thanks to everyone who joined! 🎉 Check the Events page for more → " . $this->eventsBaseUrl();
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
            $lines[] = "Check it out & RSVP on the Events page! 🎉 → " . $this->eventsBaseUrl();
        }

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $this->alumniRoomId,
            'sender_type' => 'alumni',
            'sender_id'   => $this->alumniId,
            'body'        => implode("\n", $lines),
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

        $label = $isCompleted ? 'Event highlights posted to your Batch Chat! 🏆' : 'Event posted to your batch chat! 🎉';
        $this->dispatch('flash-message', type: 'success', message: $label);
        $this->closeShareModal();
    }
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
/* ─────────────────────────────────────────────
   FILTER SELECTS
───────────────────────────────────────────── */
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.1rem;
    -webkit-appearance: none;
    appearance: none;
}

/* ─────────────────────────────────────────────
   DETAIL PAGE ENTRANCE
───────────────────────────────────────────── */
@keyframes detailIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }

/* ─────────────────────────────────────────────
   SHARE SHEET ENTRANCE
───────────────────────────────────────────── */
@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }

/* ─────────────────────────────────────────────
   SCROLLBAR
───────────────────────────────────────────── */
.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

.pre-wrap { white-space: pre-wrap; }

.share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ─────────────────────────────────────────────
   MOUSE-FOLLOWING "VIEW DETAILS" CURSOR LABEL
───────────────────────────────────────────── */
#ev-cursor-label {
    position: fixed;
    z-index: 99999;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 5px;
    background: #111827;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    box-shadow: 0 4px 16px rgba(0,0,0,.28);
    user-select: none;
    font-family: ui-sans-serif, system-ui, sans-serif;
    opacity: 0;
    visibility: hidden;
    transition: opacity .1s ease, visibility .1s ease;
    left: -999px;
    top: -999px;
}
#ev-cursor-label svg {
    width: 11px;
    height: 11px;
    flex-shrink: 0;
    fill: none;
    stroke: #fff;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

/* ─────────────────────────────────────────────
   CARD HOVER STATE
───────────────────────────────────────────── */
[data-ev-card] {
    transition: border-color .15s ease, box-shadow .15s ease;
}
[data-ev-card]:hover {
    border-color: #c4b5d4 !important;
    box-shadow: 0 4px 20px rgba(122,63,145,.12) !important;
}

/* ─────────────────────────────────────────────
   CARDS BODY — always 90vh max, scrollable
───────────────────────────────────────────── */
.events-cards-body {
    max-height: 90vh;
    overflow-y: auto;
    flex: 1 1 0;
    min-height: 0;
}
.events-cards-body::-webkit-scrollbar       { width: 4px; }
.events-cards-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

/* ─────────────────────────────────────────────
   CARD SHARE BUTTON (bottom-right of card)
   Tooltip ABOVE
───────────────────────────────────────────── */
.card-share-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
    z-index: 2;
}
.card-share-btn:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    transform: scale(1.08);
}
/* Tooltip ABOVE */
.card-share-btn .tip {
    position: absolute;
    bottom: calc(100% + 7px);
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s;
    z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.card-share-btn .tip::after {
    content: '';
    position: absolute;
    top: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-top-color: #111827;
}
.card-share-btn:hover .tip { opacity: 1; }

/* ─────────────────────────────────────────────
   STATUS BADGES on cards
───────────────────────────────────────────── */
.badge-card-upcoming {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    background: rgba(37,99,235,0.88);
    color: #fff;
    backdrop-filter: blur(4px);
}
.badge-card-completed {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    background: rgba(21,128,61,0.88);
    color: #fff;
    backdrop-filter: blur(4px);
}

/* ─────────────────────────────────────────────
   DETAIL PAGE — purple top bar action buttons
   Tooltips BELOW the button
───────────────────────────────────────────── */
.detail-top-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: background .15s, transform .1s;
    flex-shrink: 0;
    border: none;
    outline: none;
}
.detail-top-btn:active { transform: scale(.93); }

/* Tooltip BELOW */
.detail-top-btn .tip {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s;
    z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
/* Arrow pointing UP (tooltip is below button) */
.detail-top-btn .tip::before {
    content: '';
    position: absolute;
    bottom: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-bottom-color: #111827;
}
.detail-top-btn:hover .tip { opacity: 1; }

/* Share variant */
.detail-top-btn.share-btn {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
}
.detail-top-btn.share-btn:hover { background: rgba(255,255,255,.24); }

/* RSVP variant */
.detail-top-btn.rsvp-btn {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
}
.detail-top-btn.rsvp-btn:hover { background: rgba(255,255,255,.24); }

/* Close variant */
.detail-top-btn.close-btn {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.15);
}
.detail-top-btn.close-btn:hover { background: rgba(255,255,255,.22); }
.detail-top-btn.close-btn svg {
    width: 13px;
    height: 13px;
    stroke: #fff;
    stroke-width: 2.5;
    stroke-linecap: round;
}

/* ─────────────────────────────────────────────
   SHARE MODAL — purple close button
───────────────────────────────────────────── */
.btn-close-purple {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    cursor: pointer;
    transition: background .15s, transform .1s;
    flex-shrink: 0;
}
.btn-close-purple:hover  { background: rgba(255,255,255,0.28); }
.btn-close-purple:active { transform: scale(.93); }
.btn-close-purple svg    { width: 13px; height: 13px; stroke: #fff; stroke-width: 2.5; stroke-linecap: round; }

/* ─────────────────────────────────────────────
   DETAIL PAGE — force sans-serif everywhere
───────────────────────────────────────────── */
.detail-page * {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                 "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif !important;
    font-style: normal !important;
}
.detail-header-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.55);
    line-height: 1;
    margin-bottom: 2px;
    display: block;
}
.detail-header-title {
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.detail-label { font-style: italic; }
</style>

{{-- Mouse-following cursor label --}}
<div id="ev-cursor-label">
    <svg viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
    View Details
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
     class="fixed top-5 right-4 sm:right-6 z-[999999] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{'border-emerald-300 text-emerald-800':type==='success','border-blue-300 text-blue-800':type==='info','border-amber-300 text-amber-800':type==='warning','border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- PAGE HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-gray-900">Upcoming Events</h1>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700">
                    Events available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-gray-100 border-b border-[#E8E0F0] px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">

            <span class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] select-none px-1">Filters</span>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Title, venue…"
                       class="filter-input w-full pl-8 pr-3 py-[7px] text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                              hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                <option value="">All Events</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-[7px] rounded-lg text-xs font-semibold
                           bg-white border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300
                           transition active:scale-95 cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-xs"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-3.5 h-3.5 text-[#7a3f91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- ── CARDS BODY ── --}}
        <div class="events-cards-body bg-gray-100 p-4 relative">

            @if($this->pagedEvents->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->pagedEvents as $event)
                @php
                    $isCompleted  = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                    (!$event->event_end_date && $event->event_date <= now('UTC'));
                    $eventDate    = $event->event_date->setTimezone('Asia/Manila');
                    $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
                    $postedAgo    = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    $timeDisplay  = $eventDate->format('g:i A') . ($eventEndDate ? ' – ' . $eventEndDate->format('g:i A') : '');
                    $hasPhoto     = !empty($event->photo_url);
                    $descPreview  = $event->description ? Str::limit(strip_tags($event->description), 80) : null;
                @endphp

                {{--
                    FIX: Removed wire:click from the outer div entirely.
                    Only the "View Details" cursor label triggers viewEvent now.
                    The share button uses wire:click.stop to prevent card-level click.
                    This eliminates the double-fire that caused the modal auto-open bug.
                --}}
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden
                            cursor-pointer relative select-none flex flex-col group"
                     data-ev-card
                     data-ev-id="{{ $event->id }}"
                     data-ev-type="{{ $event->event_source }}"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    {{-- Photo or gradient banner --}}
                    @if($hasPhoto)
                    <div class="relative w-full flex-shrink-0" style="height:130px;">
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="w-full h-full object-cover">
                        <div class="absolute inset-x-0 bottom-0 h-10 pointer-events-none"
                             style="background:linear-gradient(to top,rgba(0,0,0,.45),transparent);"></div>
                        <div class="absolute top-2.5 right-2.5">
                            @if($isCompleted)
                                <span class="badge-card-completed">
                                    <i class="fas fa-circle-check text-[11px]"></i> Completed
                                </span>
                            @else
                                <span class="badge-card-upcoming">
                                    <i class="fas fa-calendar-check text-[11px]"></i> Upcoming
                                </span>
                            @endif
                        </div>
                    </div>
                    @else
                    <div class="relative w-full flex items-center justify-center flex-shrink-0"
                         style="height:72px; background:linear-gradient(135deg,#7a3f91 0%,#4a1f6a 100%);">
                        <i class="fas fa-calendar-days text-white/20 text-3xl"></i>
                        <div class="absolute top-2.5 right-2.5">
                            @if($isCompleted)
                                <span class="badge-card-completed">
                                    <i class="fas fa-circle-check text-[11px]"></i> Completed
                                </span>
                            @else
                                <span class="badge-card-upcoming">
                                    <i class="fas fa-calendar-check text-[11px]"></i> Upcoming
                                </span>
                            @endif
                        </div>
                    </div>
                    @endif

                    <div class="flex flex-col flex-1 p-4 gap-2">

                        <h3 class="font-normal text-base leading-snug line-clamp-2 text-gray-900">{{ $event->title }}</h3>

                        <div class="flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center text-sm font-medium px-2.5 py-0.5 rounded-md bg-gray-100 border border-gray-200 text-gray-900">
                                <i class="fas fa-calendar mr-1 text-[10px] text-gray-500"></i>
                                {{ $eventDate->format('M d, Y') }}
                            </span>
                            @if($timeDisplay)
                            <span class="inline-flex items-center text-sm font-medium px-2.5 py-0.5 rounded-md bg-gray-100 border border-gray-200 text-gray-900">
                                {{ $timeDisplay }}
                            </span>
                            @endif
                        </div>

                        @if($event->venue)
                        <p class="text-sm truncate text-gray-900">
                            <i class="fas fa-location-dot text-gray-500 text-xs mr-1"></i>{{ $event->venue }}
                        </p>
                        @endif

                        @if($event->target_participants)
                        <p class="text-sm font-medium text-gray-900 truncate">
                            <i class="fas fa-users text-[10px] mr-1 text-gray-500"></i>{{ Str::limit($event->target_participants, 40) }}
                        </p>
                        @else
                        <p class="text-sm text-gray-500 italic">No target specified</p>
                        @endif

                        @if($descPreview)
                        <p class="text-sm line-clamp-2 leading-relaxed text-gray-700">{{ $descPreview }}</p>
                        @endif

                        {{-- Card footer --}}
                        <div class="flex items-center justify-between pt-2 border-t border-gray-100 mt-auto gap-2">
                            {{-- Left: posted ago + attending count --}}
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="text-xs text-gray-500">{{ $postedAgo }}</span>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                    <i class="fas fa-circle-check text-[9px]"></i>
                                    {{ $event->confirmed_count }} Attending
                                </span>
                            </div>
                            {{-- Right: share button only — cursor label handles View Details --}}
                            <div class="flex items-center gap-1.5 flex-shrink-0 z-[2]">
                                <button type="button"
                                        data-ev-share
                                        wire:click.stop="openShareModal({{ $event->id }}, '{{ $event->event_source }}')"
                                        class="card-share-btn">
                                    <i class="fas fa-share-nodes text-[11px]"></i>
                                    <span class="tip">Share</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 h-full">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-calendar-days text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-gray-700">
                        @if($search || $filterStatus !== '') No events match your filters
                        @else No events found @endif
                    </p>
                    <p class="text-sm mt-1 text-gray-500">
                        @if($search || $filterStatus !== '') Try clearing your filters to see all available events.
                        @else Check back soon — new events will appear here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>. @endif
                    </p>
                </div>
                @if($search || $filterStatus !== '')
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    Clear Filters
                </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ══ PAGINATION BAR ══ --}}
        @if($filterStatus !== 'upcoming')
        @php
            $total   = $this->events->count();
            $from    = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to      = min($page * $perPage, $total);
            $tp      = $this->totalPages;
            $pgStart = max(1, $page - 2);
            $pgEnd   = min($tp, $page + 2);
        @endphp
        <div class="flex items-center justify-between gap-2 flex-wrap px-5 min-h-[48px]
                    bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0">

            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                event{{ $total !== 1 ? 's' : '' }}
            </p>

            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($page <= 1) disabled @endif aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $page)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $tp)
                    @if($pgEnd < $tp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $tp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $tp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($page >= $tp) disabled @endif aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $page }}/{{ $tp }}
                </span>
            </div>
        </div>
        @endif

    </div>{{-- end content-block --}}
</div>


{{-- ══ FULL-SCREEN EVENT DETAIL ══ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $event        = $this->viewingEvent;
    $eventDate    = $event->event_date->setTimezone('Asia/Manila');
    $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
    $isCompleted  = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                    (!$event->event_end_date && $event->event_date <= now('UTC'));
    $alumniRsvp   = $this->alumniRsvp;
    $hasPhoto     = !empty($event->photo_url);
    $timeDisplay  = $eventDate->format('g:i A') . ($eventEndDate ? ' – ' . $eventEndDate->format('g:i A') : '');
    $createdPH    = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila');
    $rsvpLabel    = 'Not responded';
    $rsvpColor    = 'text-gray-900 font-semibold';
    if ($alumniRsvp) {
        $rsvpLabel = $alumniRsvp->response;
        $rsvpColor = match($alumniRsvp->response) {
            'CONFIRMED' => 'text-emerald-700 font-bold',
            'DECLINED'  => 'text-red-600 font-bold',
            'TENTATIVE' => 'text-amber-600 font-bold',
            default     => 'text-gray-900 font-semibold'
        };
    }
    $hasDesc    = !empty($event->description);
    $hasNotes   = !empty($event->notes);
    $hasContact = $event->contact_person || $event->contact_email || $event->contact_phone;
@endphp

<div class="detail-page fixed inset-0 z-[9000] flex flex-col bg-gray-100 overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Purple top bar --}}
    <div class="flex items-center justify-between px-6 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">

        <div class="flex flex-col justify-center flex-1 min-w-0">
            <span class="detail-header-label">Event</span>
            <span class="detail-header-title">{{ $event->title }}</span>
        </div>

        <div class="flex items-center gap-1.5 flex-shrink-0">
            {{-- Share — tooltip BELOW --}}
            <button type="button" wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                    class="detail-top-btn share-btn"
                    aria-label="Share">
                <i class="fas fa-share-nodes text-[13px] text-white"></i>
                <span class="tip">Share</span>
            </button>
            {{-- RSVP — tooltip BELOW --}}
            @if(!$isCompleted)
            <button type="button" wire:click="openRsvpModal"
                    class="detail-top-btn rsvp-btn"
                    aria-label="{{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}">
                <i class="fas fa-calendar-plus text-[13px] text-white"></i>
                <span class="tip">{{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}</span>
            </button>
            @endif
            {{-- Close — tooltip BELOW --}}
            <button type="button" wire:click="closeViewModal"
                    class="detail-top-btn close-btn"
                    aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    {{-- White hero --}}
    <div class="bg-white border-b border-gray-200 px-6 py-4 flex-shrink-0">
        <h2 class="text-2xl font-semibold text-gray-900 leading-snug mb-3">{{ $event->title }}</h2>
        <div class="flex flex-wrap gap-1.5">
            @if($isCompleted)
                <span class="inline-flex items-center text-xs font-normal px-2.5 py-1 rounded border border-green-200 bg-green-50 text-green-700">
                    <i class="fas fa-circle-check mr-1 text-[10px]"></i>Completed
                </span>
            @else
                <span class="inline-flex items-center text-xs font-normal px-2.5 py-1 rounded border border-blue-200 bg-blue-50 text-blue-700">
                    <i class="fas fa-calendar-check mr-1 text-[10px]"></i>Upcoming
                </span>
            @endif
            @if($event->target_participants)
                @foreach(explode(',', $event->target_participants) as $part)
                    <span class="inline-flex items-center text-xs font-normal px-2.5 py-1 rounded border border-gray-200 bg-white text-gray-900">{{ trim($part) }}</span>
                @endforeach
            @endif
        </div>
    </div>

    {{-- Info strip --}}
    <div class="bg-white border-b border-gray-200 flex flex-wrap flex-shrink-0">
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Date</span>
            <span class="text-base font-semibold text-gray-900">{{ $eventDate->format('M d, Y') }}</span>
            <span class="text-sm text-gray-900">{{ $timeDisplay }}</span>
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Venue</span>
            <span class="text-base font-semibold text-gray-900">{{ $event->venue ?: '—' }}</span>
            @if($event->venue_address)
                <span class="text-sm text-gray-900">{{ $event->venue_address }}</span>
            @endif
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Open For</span>
            <span class="text-base font-semibold text-gray-900">{{ $event->target_participants ?: '—' }}</span>
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Responses</span>
            <span class="text-base font-bold text-emerald-600">{{ $event->confirmed_count }} Attending</span>
            <span class="text-sm text-gray-900">{{ $event->tentative_count }} Maybe · {{ $event->declined_count }} No</span>
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 border-r border-gray-100 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Your RSVP</span>
            <span class="text-base {{ $rsvpColor }}">{{ $rsvpLabel }}</span>
            @if(!$isCompleted)
                <button wire:click="openRsvpModal"
                        class="text-xs font-semibold text-violet-700 hover:underline cursor-pointer text-left">
                    {{ $alumniRsvp ? 'Change →' : 'RSVP now →' }}
                </button>
            @endif
        </div>
        <div class="flex-1 min-w-[110px] px-5 py-3 flex flex-col gap-0.5">
            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Posted</span>
            <span class="text-base font-semibold text-gray-900">{{ $createdPH->format('M d, Y') }}</span>
            <span class="text-sm text-gray-900">{{ $createdPH->diffForHumans() }}</span>
        </div>
    </div>

    {{-- Scrollable content --}}
    <div class="flex-1 overflow-y-auto bg-gray-100 scroll-thin min-h-0">
        <div class="max-w-[1100px] mx-auto px-5 py-4 pb-8 flex flex-col gap-4">

            @if($hasPhoto)
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                     class="w-full object-cover" style="max-height:320px;display:block;">
            </div>
            @endif

            @if($hasDesc)
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">About This Event</span>
                </div>
                <div class="px-5 py-4 text-base text-gray-900 leading-relaxed pre-wrap">{{ $event->description }}</div>
            </div>
            @endif

            @if($hasNotes || $hasContact)
            <div class="{{ ($hasNotes && $hasContact) ? 'grid grid-cols-1 md:grid-cols-2 gap-4' : '' }}">
                @if($hasNotes)
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Additional Notes</span>
                    </div>
                    <div class="px-5 py-4 text-base text-gray-900 leading-relaxed pre-wrap">{{ $event->notes }}</div>
                </div>
                @endif
                @if($hasContact)
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-gray-900">Contact Information</span>
                    </div>
                    <div class="px-5 py-4 flex flex-col gap-2">
                        @if($event->contact_person)
                            <p class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-user text-gray-400 text-sm"></i>{{ $event->contact_person }}
                            </p>
                        @endif
                        @if($event->contact_email)
                            <p class="text-base text-gray-900 flex items-center gap-2">
                                <i class="fas fa-envelope text-gray-400 text-sm"></i>{{ $event->contact_email }}
                            </p>
                        @endif
                        @if($event->contact_phone)
                            <p class="text-base text-gray-900 flex items-center gap-2">
                                <i class="fas fa-phone text-gray-400 text-sm"></i>{{ $event->contact_phone }}
                            </p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @endif

            <p class="text-center text-xs text-gray-500">Posted {{ $createdPH->format('M d, Y \a\t g:i A') }}</p>
        </div>
    </div>

</div>
@endif


{{-- ══ RSVP MODAL ══ --}}
@if($showRsvpModal)
<div class="fixed inset-0 z-[10001] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeRsvpModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden relative"
         style="animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both;">
        <div class="px-6 py-5 border-b border-white/10" style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                    <i class="fas fa-calendar-plus text-white/80"></i> Confirm Your RSVP
                </h2>
                <button wire:click="closeRsvpModal" type="button"
                        class="detail-top-btn close-btn" aria-label="Close">
                    <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2L12 12M12 2L2 12"/>
                    </svg>
                </button>
            </div>
            <p class="text-sm text-white/70 mt-0.5">Let us know if you're attending this event</p>
        </div>
        <div class="px-6 py-5 space-y-3">
            <button type="button" wire:click="submitRsvp('CONFIRMED')" wire:loading.attr="disabled"
                    class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-emerald-200 hover:border-emerald-400 bg-white cursor-pointer group">
                <span class="w-9 h-9 rounded-xl bg-emerald-100 group-hover:bg-emerald-200 flex items-center justify-center flex-shrink-0 transition">
                    <i class="fas fa-circle-check text-emerald-600 text-lg"></i>
                </span>
                <div class="flex-1 text-left">
                    <p class="font-semibold text-emerald-700 text-sm">I'm Attending</p>
                    <p class="text-xs text-emerald-600">Confirm your attendance</p>
                </div>
                <i class="fas fa-chevron-right text-emerald-400 text-xs"></i>
            </button>
            <button type="button" wire:click="submitRsvp('TENTATIVE')" wire:loading.attr="disabled"
                    class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-amber-200 hover:border-amber-400 bg-white cursor-pointer group">
                <span class="w-9 h-9 rounded-xl bg-amber-100 group-hover:bg-amber-200 flex items-center justify-center flex-shrink-0 transition">
                    <i class="fas fa-circle-question text-amber-600 text-lg"></i>
                </span>
                <div class="flex-1 text-left">
                    <p class="font-semibold text-amber-700 text-sm">Maybe</p>
                    <p class="text-xs text-amber-600">You might attend</p>
                </div>
                <i class="fas fa-chevron-right text-amber-400 text-xs"></i>
            </button>
            <button type="button" wire:click="submitRsvp('DECLINED')" wire:loading.attr="disabled"
                    class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-red-200 hover:border-red-400 bg-white cursor-pointer group">
                <span class="w-9 h-9 rounded-xl bg-red-100 group-hover:bg-red-200 flex items-center justify-center flex-shrink-0 transition">
                    <i class="fas fa-circle-xmark text-red-600 text-lg"></i>
                </span>
                <div class="flex-1 text-left">
                    <p class="font-semibold text-red-700 text-sm">I Can't Attend</p>
                    <p class="text-xs text-red-600">You won't be attending</p>
                </div>
                <i class="fas fa-chevron-right text-red-400 text-xs"></i>
            </button>
            <div class="pt-1">
                <label class="block text-xs font-semibold uppercase tracking-widest mb-2 text-gray-500">
                    Message <span class="font-normal normal-case text-gray-400">— optional</span>
                </label>
                <textarea wire:model="rsvpMessage" rows="2"
                          placeholder="Add a personal note or question…"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none text-gray-900"
                          maxlength="200"></textarea>
                <p class="text-xs mt-1 text-gray-400">{{ strlen($rsvpMessage) }}/200</p>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50">
            <button wire:click="closeRsvpModal" type="button"
                    class="w-full px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 bg-white hover:bg-gray-50 transition cursor-pointer text-gray-700">
                Cancel
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══ SHARE MODAL ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->eventsBaseUrl();
    $shareHost        = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $shTimeStr        = $shareTime . ($shareEndTime ? ' – ' . $shareEndTime : '');
    $isCompleted      = $shareIsCompleted;

    $descLimit        = 160;
    $shareDescPreview = mb_strlen($shareDescription) > $descLimit
        ? mb_substr($shareDescription, 0, $descLimit) . '…'
        : $shareDescription;

    $fbLines = [];
    if ($isCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        if ($shareDate)        $fbLines[] = "🗓️  {$shareDate}" . ($shTimeStr ? " · {$shTimeStr}" : '');
        if ($shareVenue)       $fbLines[] = "📍 {$shareVenue}";
        if ($shareOrganizer)   $fbLines[] = "🏫 Organized by: {$shareOrganizer}";
        if ($shareTargetParts) $fbLines[] = "👥 {$shareTargetParts}";
        $fbLines[] = '';
        $fbLines[] = "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇";
    } else {
        $fbLines[] = "📅 Event: {$shareEventTitle}";
        if ($shareDate)        $fbLines[] = "🗓️  {$shareDate}" . ($shTimeStr ? " · {$shTimeStr}" : '');
        if ($shareVenue)       $fbLines[] = "📍 {$shareVenue}";
        if ($shareOrganizer)   $fbLines[] = "🏫 Organized by: {$shareOrganizer}";
        if ($shareTargetParts) $fbLines[] = "👥 Open for: {$shareTargetParts}";
        $fbLines[] = '';
        $fbLines[] = "See full details & RSVP on the PHILCST Alumni Portal 👇";
    }
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $sharePhotoUrl
        && !str_contains($sharePhotoUrl, 'default')
        && str_contains($sharePhotoUrl, '/storage/');
@endphp

<div class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm"
     x-data="{
         copied:false, fbCopied:false, messengerCopied:false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},
         photoUrl:{{ json_encode($sharePhotoUrl) }},
         hasPhoto:{{ $hasRealPhoto ? 'true' : 'false' }},
         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); }
                 else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },
         async shareOnFacebook() {
             await this.copyText(this.fbText); this.fbCopied = true;
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(this.baseUrl),'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             setTimeout(() => this.fbCopied = false, 7000);
         },
         async shareOnMessenger() {
             await this.copyText(this.fbText); this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/new','_blank','noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/new','_blank','noopener,noreferrer');
             }
             setTimeout(() => this.messengerCopied = false, 7000);
         },
         async copyLinkFn() { await this.copyText(this.baseUrl); this.copied = true; setTimeout(() => this.copied = false, 2500); }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-2xl share-modal-wrapper">

        {{-- Share modal header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-white/10 flex-shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <h2 class="text-sm font-semibold flex items-center gap-2 text-white">
                @if($isCompleted)
                    <i class="fas fa-share-nodes text-sky-300 text-xs"></i> Share Event
                @else
                    <i class="fas fa-share-nodes text-sky-300 text-xs"></i> Share Event
                @endif
            </h2>
            <button wire:click="closeShareModal" type="button" class="btn-close-purple" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 flex-shrink-0">Post Preview</p>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0">
                    @if($sharePhotoUrl)
                    <div class="w-full bg-gray-100">
                        <img src="{{ $sharePhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full object-cover" style="max-height:160px;display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-100 px-4 py-3 {{ $isCompleted ? 'bg-amber-50/50' : 'bg-gray-50' }}">
                        <p class="font-semibold text-gray-900 leading-tight" style="font-size:clamp(12px,1.2vw,14px);">{{ $shareEventTitle }}</p>
                        <p class="font-medium text-gray-500 mt-0.5" style="font-size:clamp(10px,1vw,12px);">{{ $shareOrganizer }}</p>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @if($shareDate)        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareDate }}@if($shTimeStr) · {{ $shTimeStr }}@endif</span> @endif
                            @if($shareVenue)       <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareVenue }}</span> @endif
                            @if($shareTargetParts) <span class="inline-flex items-center px-1.5 py-0.5 rounded text-gray-700 bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);">{{ Str::limit($shareTargetParts, 30) }}</span> @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-4 py-2 border-b border-gray-100">
                        <p class="leading-relaxed text-gray-600" style="font-size:clamp(10px,0.9vw,12px);">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-4 py-2 bg-gray-50">
                        <span class="uppercase tracking-wider font-semibold text-gray-400" style="font-size:clamp(9px,0.8vw,11px);">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-xs flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the caption and opens the platform.
                        Press <kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd> (or <kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">⌘V</kbd>) to paste it.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Share via</p>

                <div x-show="fbCopied" x-cloak x-transition
                     class="bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-emerald-800">Caption copied! Paste it on Facebook.</p>
                </div>
                <div x-show="messengerCopied" x-cloak x-transition
                     class="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-blue-800">Caption copied! Pick a contact in Messenger and paste.</p>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm transition cursor-pointer">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share on Facebook</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Caption auto-copied · paste to post</p>
                    </div>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-white font-semibold text-sm transition cursor-pointer"
                        style="background:linear-gradient(135deg,#0099FF,#A033FF);">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4">
                            <defs>
                                <linearGradient id="mgr_ev2" x1="0%" y1="100%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color:#0099FF"/>
                                    <stop offset="100%" style="stop-color:#A033FF"/>
                                </linearGradient>
                            </defs>
                            <path fill="url(#mgr_ev2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Send via Messenger</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Pick a contact · paste caption</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 flex items-start gap-2">
                    <i class="fas fa-lightbulb text-amber-500 text-[10px] flex-shrink-0 mt-0.5"></i>
                    <p class="text-[10px] text-gray-500 leading-relaxed">
                        Messenger will open. Search a contact, start a conversation, then press <span class="font-semibold text-gray-700">Ctrl+V</span> to paste the event details.
                    </p>
                </div>

                <button type="button" wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-white font-semibold text-sm transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-60 disabled:cursor-not-allowed">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white/20 border border-white/20">
                        <span wire:loading.remove wire:target="postToBatchChat">
                            <i class="fas fa-comments text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="postToBatchChat">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Post to Batch Chat</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Notify all your batchmates</p>
                    </div>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white text-gray-400">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300
                               hover:bg-gray-50 text-sm transition cursor-pointer bg-white text-gray-700">
                    <span class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-500'" class="text-sm"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : 'text-gray-700'" x-text="copied ? 'Link copied!' : 'Copy Link'"></p>
                        <p class="text-[10px] font-mono text-gray-400 truncate">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" wire:click="closeShareModal"
                        class="w-full px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition cursor-pointer">
                    Close
                </button>
                <p class="text-[10px] text-center text-gray-400">Sharing highlights is available even after the event.</p>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- end root --}}

{{-- ── Mouse-following cursor label + card click logic ── --}}
<script>
(function () {
    function init() {
        const label = document.getElementById('ev-cursor-label');
        if (!label) return;

        let activeCard = null;
        let mouseX = 0;
        let mouseY = 0;

        function show() {
            label.style.opacity    = '1';
            label.style.visibility = 'visible';
        }

        function hide() {
            label.style.opacity    = '0';
            label.style.visibility = 'hidden';
        }

        function onMouseMove(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
            label.style.left = (mouseX + 16) + 'px';
            label.style.top  = (mouseY + 14) + 'px';
        }

        function onCardEnter(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = e.currentTarget;
            document.addEventListener('mousemove', onMouseMove);
            show();
        }

        function onCardLeave(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = null;
            hide();
            document.removeEventListener('mousemove', onMouseMove);
        }

        function onShareEnter() { hide(); }
        function onShareLeave() { if (activeCard) show(); }

        // Click the card → call Livewire viewEvent
        function onCardClick(e) {
            const card = e.currentTarget;
            const id   = parseInt(card.dataset.evId, 10);
            const type = card.dataset.evType;
            if (id && type) {
                window.Livewire.find(
                    card.closest('[wire\\:id]')?.getAttribute('wire:id') ||
                    document.querySelector('[wire\\:id]')?.getAttribute('wire:id')
                )?.call('viewEvent', id, type);
            }
        }

        function attachListeners() {
            document.querySelectorAll('[data-ev-card]').forEach(card => {
                if (card._evBound) return;
                card._evBound = true;

                card.addEventListener('mouseenter', onCardEnter);
                card.addEventListener('mouseleave', onCardLeave);
                card.addEventListener('click', onCardClick);

                const shareBtn = card.querySelector('[data-ev-share]');
                if (shareBtn) {
                    shareBtn.addEventListener('mouseenter', onShareEnter);
                    shareBtn.addEventListener('mouseleave', onShareLeave);
                }
            });
        }

        attachListeners();

        // Re-attach after Livewire DOM updates
        document.addEventListener('livewire:navigated', () => {
            document.querySelectorAll('[data-ev-card]').forEach(c => { c._evBound = false; });
            attachListeners();
        });

        if (window.Livewire) {
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        requestAnimationFrame(() => {
                            document.querySelectorAll('[data-ev-card]').forEach(c => { c._evBound = false; });
                            attachListeners();
                        });
                    });
                });
            } catch(e) {}
        }

        // Hide label when any modal opens
        document.addEventListener('livewire:update', () => {
            hide();
            activeCard = null;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>