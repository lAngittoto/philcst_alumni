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

    public bool   $deepLinkedView    = false;

    public bool   $showRsvpModal = false;
    public ?string $rsvpResponse  = null;

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

    public array  $alumniChatRooms = [];

    public bool   $showForwardModal = false;
    public array  $selectedRoomIds  = [];

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

        $chatRooms = collect();
        if ($this->alumniRoomId) {
            $chatRooms->push([
                'id'    => $this->alumniRoomId,
                'label' => 'Batch ' . ($alumni->batch ?? '') . ' Chat',
            ]);
        }
        if ($this->alumniCollege) {
            $marker = $this->collegeMarker($this->alumniCollege);
            $generalRoom = DB::table('chat_rooms')
                ->where('department', $this->alumniCollege)
                ->where('course_code', $marker)
                ->where('batch', 0)
                ->first();
            if ($generalRoom && (int) $generalRoom->id !== $this->alumniRoomId) {
                $chatRooms->push([
                    'id'    => (int) $generalRoom->id,
                    'label' => $generalRoom->name ?? ($this->alumniCollege . ' Chat'),
                ]);
            }
        }
        $this->alumniChatRooms = $chatRooms->values()->toArray();

        $filter = session()->pull('events_filter');
        if ($filter === 'upcoming') {
            $this->filterStatus = 'upcoming';
        } elseif ($filter === 'all') {
            $this->filterStatus = '';
        }

        $eventParam = request()->query('event');
        $typeParam  = strtoupper((string) request()->query('type', ''));

        if ($eventParam !== null && in_array($typeParam, ['ADMIN', 'ORGANIZER'], true)) {
            $this->filterStatus  = '';
            $this->deepLinkedView = true;
            $this->viewEvent((int) $eventParam, $typeParam);
        }
    }

    private function collegeMarker(string $college): string
    {
        return 'CLG_' . substr(md5($college), 0, 12);
    }

    private function isEventCompleted($event): bool
    {
        $now = \Carbon\Carbon::now('UTC');
        return ($event->event_end_date && $event->event_end_date <= $now) ||
               (!$event->event_end_date && $event->event_date <= $now);
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
        $this->showViewModal    = false;
        $this->viewingEventId   = null;
        $this->viewingEventType = null;
    }

    public function updatingFilterStatus(): void
    {
        $this->page = 1;
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

        $merged = $adminQ->get()->concat($organizerQ->get())->sortByDesc('created_at')->values();

        if ($this->filterStatus === '') {
            $merged = $merged->sortBy(fn($event) => $this->isEventCompleted($event) ? 1 : 0)->values();
        }

        return $merged;
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

        if ($this->deepLinkedView) {
            $this->filterStatus   = 'upcoming';
            $this->page           = 1;
            $this->deepLinkedView = false;
        }
    }

    public function openRsvpModal(): void  { $this->showRsvpModal = true; }
    public function closeRsvpModal(): void { $this->showRsvpModal = false; $this->resetRsvpModal(); }
    private function resetRsvpModal(): void { $this->rsvpResponse = null; }

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
                ['response' => $response]
            );
            $this->dispatch('flash-message', type: 'success', message: "Your RSVP has been recorded as {$response}!");
            unset($this->alumniRsvp);
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

    public function openForwardModal(): void
    {
        if (empty($this->alumniChatRooms)) {
            $this->dispatch('flash-message', type: 'error', message: 'No chat rooms found to share to.');
            return;
        }

        $this->selectedRoomIds  = $this->alumniRoomId ? [$this->alumniRoomId] : [];
        $this->showForwardModal = true;
    }

    public function closeForwardModal(): void
    {
        $this->showForwardModal = false;
        $this->selectedRoomIds  = [];
    }

    public function toggleRoomSelection(int $roomId): void
    {
        if (in_array($roomId, $this->selectedRoomIds, true)) {
            $this->selectedRoomIds = array_values(array_diff($this->selectedRoomIds, [$roomId]));
        } else {
            $this->selectedRoomIds[] = $roomId;
        }
    }

    public function confirmSendToChat(): void
    {
        if (empty($this->selectedRoomIds)) {
            $this->dispatch('flash-message', type: 'warning', message: 'Pumili muna ng chat kung saan mo ipapadala.');
            return;
        }

        if (!$this->shareEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Could not find the event to share.');
            return;
        }

        $type  = $this->shareEventType;
        $event = $type === 'ADMIN'
            ? AdminEvent::withoutTrashed()->where('id', $this->shareEventId)->whereIn('status', ['APPROVED', 'COMPLETED'])->first()
            : OrganizerEvent::where('id', $this->shareEventId)->whereIn('status', ['APPROVED', 'COMPLETED'])->first();

        if (!$event) { $this->dispatch('flash-message', type: 'error', message: 'Event not found.'); return; }

        $isCompleted = $this->shareIsCompleted;
        $body        = "@everyone [[EVENT:{$type}:{$event->id}]]";
        $now         = now();

        foreach ($this->selectedRoomIds as $roomId) {
            $msgId = DB::table('chat_messages')->insertGetId([
                'room_id'     => $roomId,
                'sender_type' => 'alumni',
                'sender_id'   => $this->alumniId,
                'body'        => $body,
                'reply_to_id' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            if (!$isCompleted) {
                DB::table('chat_mentions')->insert([
                    'message_id'   => $msgId,
                    'mention_type' => 'everyone',
                    'mentioned_id' => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        }

        $count = count($this->selectedRoomIds);
        $label = $isCompleted
            ? ($count > 1 ? "Event highlights posted to {$count} chats! 🏆" : 'Event highlights posted to your Batch Chat! 🏆')
            : ($count > 1 ? "Event posted to {$count} chats! 🎉" : 'Event posted to your batch chat! 🎉');

        $this->closeForwardModal();
        $this->closeShareModal();
        $this->dispatch('flash-message', type: 'success', message: $label);
    }
};
?>

<div class="flex flex-col" style="height:calc(100vh - 180px);max-height:calc(100vh - 180px);overflow:hidden;">

<style>
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.1rem;
    -webkit-appearance: none;
    appearance: none;
}

@keyframes detailIn { from { opacity: 0; } to { opacity: 1; } }
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }

@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

.pre-wrap { white-space: pre-wrap; }

.share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

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
    width: 11px; height: 11px; flex-shrink: 0;
    fill: none; stroke: #fff; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round;
}

[data-ev-card] { transition: border-color .15s ease, box-shadow .15s ease; }
[data-ev-card]:hover {
    border-color: #c4b5d4 !important;
    box-shadow: 0 4px 20px rgba(122,63,145,.12) !important;
}

.card-share-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0; z-index: 2;
}
.card-share-btn:hover { background: #dbeafe; border-color: #93c5fd; transform: scale(1.08); }
.card-share-btn .tip {
    position: absolute; bottom: calc(100% + 7px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.card-share-btn .tip::after {
    content: ''; position: absolute; top: 100%; right: 10px;
    border: 4px solid transparent; border-top-color: #111827;
}
.card-share-btn:hover .tip { opacity: 1; }

.badge-card-upcoming {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 999px; font-size: 13px; font-weight: 700;
    background: rgba(37,99,235,0.88); color: #fff; backdrop-filter: blur(4px);
}
.badge-card-completed {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 999px; font-size: 13px; font-weight: 700;
    background: rgba(21,128,61,0.88); color: #fff; backdrop-filter: blur(4px);
}

.detail-top-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    cursor: pointer; transition: background .15s, transform .1s;
    flex-shrink: 0; border: none; outline: none;
}
.detail-top-btn:active { transform: scale(.93); }
.detail-top-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.detail-top-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.detail-top-btn:hover .tip { opacity: 1; }

.detail-top-btn.share-btn { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); color: #fff; }
.detail-top-btn.share-btn:hover { background: rgba(255,255,255,.24); }
.detail-top-btn.rsvp-btn { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); color: #fff; }
.detail-top-btn.rsvp-btn:hover { background: rgba(255,255,255,.24); }
.detail-top-btn.close-btn { background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.15); }
.detail-top-btn.close-btn:hover { background: rgba(255,255,255,.22); }
.detail-top-btn.close-btn svg { width: 13px; height: 13px; stroke: #fff; stroke-width: 2.5; stroke-linecap: round; }

.share-close-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    cursor: pointer; transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
}
.share-close-btn:hover  { background: #e5e7eb; border-color: #d1d5db; }
.share-close-btn:active { transform: scale(.93); }
.share-close-btn svg    { width: 14px; height: 14px; stroke: #4b5563; stroke-width: 2.25; stroke-linecap: round; }
.share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.share-close-btn:hover .tip { opacity: 1; }

.share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .15s, transform .1s; border: none;
}
.share-option-btn:hover  { filter: brightness(0.94); }
.share-option-btn:active { transform: scale(.98); }
.share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

.philcst-post-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 14px; overflow: hidden; }
.philcst-post-ribbon {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #7a3f91; background: #f5eef9; border: 1px solid #e3cdf0;
    padding: 4px 10px; border-radius: 999px;
}

/* ─────────────────────────────────────────────
   SIDEBAR META LABELS — unified font-weight/size
   for ALL labels (Venue, Date & Time, Open For,
   Responses, Your RSVP, Posted). Previously some
   used .detail-label (italic, different weight)
   causing mismatched look; now every meta label
   in the sidebar uses this single consistent
   class/style. Sizes bumped up for readability. ──
───────────────────────────────────────────── */
.detail-side-item { display: flex; align-items: flex-start; gap: 10px; }
.detail-side-icon {
    flex-shrink: 0; width: 30px; height: 30px; border-radius: 8px;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center; font-size: 13px;
}
.detail-side-label {
    font-size: 11.5px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .08em; color: #666; margin: 0; font-style: normal !important;
}
.detail-side-value { font-size: 15.5px; font-weight: 600; color: #333333; margin: 2px 0 0; line-height: 1.4; }
.detail-side-sub   { font-size: 13px; margin-top: 2px; color: #666; }

#ev-detail-outer { position: relative; }

@media (max-width: 767px) {
    #ev-cursor-label { display: none !important; }
    .card-share-btn .tip,
    .detail-top-btn .tip,
    .share-close-btn .tip { display: none !important; }

    #share-modal-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
        background: #fff !important;
    }
    .share-modal-wrapper {
        max-width: 100% !important;
        width: 100% !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        border-radius: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }
    .share-modal-wrapper .share-sheet-header {
        padding-top: max(0.75rem, env(safe-area-inset-top)) !important;
    }
}

.detail-page * {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                 "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    font-style: normal !important;
}
.detail-header-title {
    font-size: 15px; font-weight: 600; color: #fff; line-height: 1.3;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ─────────────────────────────────────────────
   RSVP MODAL — indicator rows (read-only) shown
   after the alumni has already responded, in
   place of the 3 clickable action buttons.
───────────────────────────────────────────── */
.rsvp-indicator-row {
    width: 100%; padding: 0.875rem 1rem; border-radius: 0.75rem;
    border: 2px solid; display: flex; align-items: center; gap: 0.75rem;
    opacity: .55; filter: grayscale(.15);
}
.rsvp-indicator-row.is-selected { opacity: 1; filter: none; }
</style>

<div id="ev-cursor-label">
    <svg viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
    View Details
</div>

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

<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

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

    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

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

        <div class="ev-filter-progress-track" wire:loading wire:target="search,filterStatus">
            <div class="ev-filter-progress-bar"></div>
        </div>
        <style>
            .ev-filter-progress-track { height:2px; width:100%; overflow:hidden; background:transparent; position:relative; }
            .ev-filter-progress-bar { position:absolute; top:0; left:0; height:100%; width:40%; border-radius:99px; background:linear-gradient(135deg,#7a3f91,#9b59b6); animation:evFilterProgress 1s ease-in-out infinite; }
            @keyframes evFilterProgress { 0%{left:-40%} 100%{left:100%} }
        </style>

        <div class="bg-gray-100 p-4 relative flex-1 min-h-0 overflow-y-auto transition-opacity duration-200"
             wire:loading.class="opacity-40 pointer-events-none" wire:target="search,filterStatus">

            @if($this->pagedEvents->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->pagedEvents as $event)
                @php
                    $isCompleted  = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                    (!$event->event_end_date && $event->event_date <= now('UTC'));
                    $postedAgo    = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    $hasPhoto     = !empty($event->photo_url);
                    $descPreview  = $event->description ? Str::limit(strip_tags($event->description), 90) : null;
                    $displaySrc   = $event->event_source === 'ADMIN' ? 'PHILCST' : null;
                @endphp

                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden
                            cursor-pointer relative select-none flex flex-col group"
                     data-ev-card
                     wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    @if($hasPhoto)
                    <div class="relative w-full flex-shrink-0" style="height:200px;">
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="w-full h-full object-cover">
                        <div class="absolute inset-x-0 bottom-0 h-16 pointer-events-none"
                             style="background:linear-gradient(to top,rgba(0,0,0,.55),transparent);"></div>
                        <div class="absolute top-2.5 right-2.5">
                            @if($isCompleted)
                                <span class="badge-card-completed"><i class="fas fa-circle-check text-[11px]"></i> Completed</span>
                            @else
                                <span class="badge-card-upcoming"><i class="fas fa-calendar-check text-[11px]"></i> Upcoming</span>
                            @endif
                        </div>
                    </div>
                    @else
                    <div class="relative w-full flex items-center justify-center flex-shrink-0"
                         style="height:130px; background:linear-gradient(135deg,#7a3f91 0%,#4a1f6a 100%);">
                        <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                        <div class="absolute top-2.5 right-2.5">
                            @if($isCompleted)
                                <span class="badge-card-completed"><i class="fas fa-circle-check text-[11px]"></i> Completed</span>
                            @else
                                <span class="badge-card-upcoming"><i class="fas fa-calendar-check text-[11px]"></i> Upcoming</span>
                            @endif
                        </div>
                    </div>
                    @endif

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-[15px] leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</h3>
                            </div>
                            @if($displaySrc)
                            <span class="inline-flex shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-md border border-gray-200 bg-gray-50 mt-0.5 whitespace-nowrap" style="color:#333333;">
                                {{ $displaySrc }}
                            </span>
                            @endif
                        </div>

                        @if($event->target_participants)
                        <p class="text-[13px] truncate flex items-center gap-1.5" style="color:#333333;">
                            <i class="fas fa-users text-[11px]" style="color:#999;"></i>{{ Str::limit($event->target_participants, 40) }}
                        </p>
                        @else
                        <p class="text-[13px] italic" style="color:#333333;">No target specified</p>
                        @endif

                        @if($descPreview)
                        <p class="text-[13px] line-clamp-2 leading-relaxed" style="color:#333333;">{{ $descPreview }}</p>
                        @endif

                        <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-auto gap-2">
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="text-[12px]" style="color:#333333;">{{ $postedAgo }}</span>
                                <span class="inline-flex items-center gap-1 text-[12px] font-semibold text-emerald-600">
                                    <i class="fas fa-circle-check text-[9px]"></i>
                                    {{ $event->confirmed_count }} Attending
                                </span>
                            </div>

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

    </div>
</div>


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
    $hasDesc     = !empty($event->description);
    $hasNotes    = !empty($event->notes);
    $hasContact  = $event->contact_person || $event->contact_email || $event->contact_phone;
    $isPhilcst   = $event->event_source === 'ADMIN';
    $displaySrc  = $isPhilcst ? 'PHILCST' : ($event->event_source === 'ORGANIZER' ? 'Organizer' : null);
@endphp

<div class="detail-page fixed inset-0 z-[9000] flex flex-col bg-gray-100 overflow-y-auto lg:overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-6 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">

        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <span class="detail-header-title">Event Details</span>
        </div>

        <div class="flex items-center gap-1.5 flex-shrink-0">
            <button type="button" wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                    class="detail-top-btn share-btn" aria-label="Share">
                <i class="fas fa-share-nodes text-[13px] text-white"></i>
                <span class="tip">Share</span>
            </button>
            @if(!$isCompleted)
            <button type="button" wire:click="openRsvpModal"
                    class="detail-top-btn rsvp-btn" aria-label="{{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}">
                <i class="fas fa-calendar-plus text-[13px] text-white"></i>
                <span class="tip">{{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}</span>
            </button>
            @endif
            <button type="button" wire:click="closeViewModal"
                    class="detail-top-btn close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 lg:min-h-0 flex flex-col lg:flex-row">

        <div class="w-full lg:w-[340px] lg:flex-none lg:min-h-0 bg-white border-b lg:border-b-0 lg:border-r border-gray-200 lg:overflow-y-auto lg:scroll-thin flex flex-col">

            @if($hasPhoto)
                <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                     class="w-full h-48 sm:h-56 object-cover bg-[#f5eef9] flex-shrink-0"
                     onerror="this.style.display='none'">
            @endif

            <div class="p-4 flex flex-col gap-3">
                @if($isPhilcst)
                    <span class="philcst-post-ribbon self-start"><i class="fas fa-school text-[10px]"></i> Official PHILCST Event</span>
                @endif

                <div>
                    <p class="detail-side-label mb-1">Event Title</p>
                    <h2 class="text-lg font-semibold leading-snug mb-1" style="color:#333333;">{{ $event->title }}</h2>
                    @if($displaySrc)
                    <p class="text-xs font-semibold uppercase tracking-[.08em]" style="color:#333333;">{{ $displaySrc }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @if($isCompleted)
                        <span class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border border-green-200 bg-white text-green-700">
                            <i class="fas fa-circle-check mr-1 text-[9px]"></i>Completed
                        </span>
                    @else
                        <span class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border border-blue-200 bg-white text-blue-700">
                            <i class="fas fa-calendar-check mr-1 text-[9px]"></i>Upcoming
                        </span>
                    @endif
                    @if($event->target_participants)
                        @foreach(explode(',', $event->target_participants) as $part)
                            <span class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border border-gray-200 bg-white" style="color:#333333;">{{ trim($part) }}</span>
                        @endforeach
                    @endif
                </div>

                <div class="border-t border-gray-100"></div>

                <div class="flex flex-col gap-3">
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-location-dot"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Venue</p>
                            <p class="detail-side-value">{{ $event->venue ?: '—' }}</p>
                            @if($event->venue_address)
                                <p class="detail-side-sub">{{ $event->venue_address }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-calendar-days"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Date &amp; Time</p>
                            <p class="detail-side-value">{{ $eventDate->format('M d, Y') }}</p>
                            <p class="detail-side-sub">{{ $timeDisplay }}</p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-users"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Open For</p>
                            <p class="detail-side-value">{{ $event->target_participants ?: '—' }}</p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-clipboard-check"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Responses</p>
                            <p class="detail-side-value text-emerald-600">{{ $event->confirmed_count }} Attending</p>
                            <p class="detail-side-sub">{{ $event->tentative_count }} Maybe · {{ $event->declined_count }} No</p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-calendar-check"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Your RSVP</p>
                            <p class="detail-side-value {{ $rsvpColor }}">{{ $rsvpLabel }}</p>
                            @if(!$isCompleted)
                                <button wire:click="openRsvpModal"
                                        class="text-[13px] font-semibold text-[#7a3f91] hover:underline cursor-pointer mt-0.5">
                                    {{ $alumniRsvp ? 'Change →' : 'RSVP now →' }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Posted</p>
                            <p class="detail-side-value">{{ $createdPH->format('M d, Y') }}</p>
                            <p class="detail-side-sub">{{ $createdPH->diffForHumans() }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="ev-detail-outer" class="flex-1 min-w-0 lg:min-h-0 lg:overflow-y-auto scroll-thin bg-gray-100 flex">
            <div id="ev-detail-inner" class="max-w-[1100px] w-full mx-auto px-5 py-6 flex flex-col gap-4">

                @if($hasDesc || $hasNotes || $hasContact)
                <div class="philcst-post-card">
                    <div class="px-5 py-4 flex flex-col gap-4">

                        @if($hasDesc)
                            @if($isPhilcst)
                            <div>
                                <p class="text-lg font-bold" style="color:#333333;">📢 {{ strtoupper($event->title) }}</p>
                                <p class="text-[14px] mt-1 leading-relaxed" style="color:#333333;">
                                    The Philippine College of Science and Technology invites you to join this event! ✨
                                </p>
                            </div>
                          <div class="pre-wrap text-[16px] leading-relaxed overflow-y-auto scroll-thin" style="color:#000000;max-height:320px;">{{ trim($event->description) }}</div>
                            <p class="text-[15px] font-semibold" style="color:#333333;">
                                🗓️ {{ $eventDate->format('F d, Y') }} · {{ $timeDisplay }} &nbsp;•&nbsp; 📍 {{ $event->venue ?: 'TBA' }}
                            </p>
                            @else
                            <div>
                                <p class="detail-side-label mb-1.5 flex items-center gap-1.5">
                                    <i class="fas fa-align-left text-[#7a3f91] text-xs"></i>  <span class="text-black">About This Event</span>
                                </p>
                            </div>
                           <div class="pre-wrap text-[16px] leading-relaxed overflow-y-auto scroll-thin" style="color:#000000;max-height:320px;">{{ $event->description }}</div>
                            @endif
                        @endif
@if($hasNotes)
                        <div style="padding-bottom:48px;">
                            <div class="border-t border-gray-100" style="padding-top:24px;">
<p class="detail-side-label flex items-center gap-1.5" style="margin-bottom:16px;">
                                    <i class="fas fa-note-sticky text-[#7a3f91] text-xs"></i>  <span class="text-black">Additional Notes</span>
                                </p>
                                <div class="pre-wrap text-[16px] leading-relaxed overflow-y-auto scroll-thin" style="color:#000000;max-height:320px;">{{ $event->notes }}</div>
                            </div>
                        </div>
                        @endif

                        @if($hasContact)
                        <div class="bg-emerald-50/60 border border-emerald-100 rounded-xl px-4 py-3">
                            <p class="text-base font-bold text-emerald-800 mb-2 flex items-center gap-1.5">
                                <i class="fas fa-address-card text-xs"></i> Contact Information
                            </p>
                            <div class="flex flex-col gap-1.5">
                                @if($event->contact_person)
                                    <p class="text-[16px] font-semibold flex items-center gap-2" style="color:#333333;">
                                        <i class="fas fa-user text-[13px]" style="color:#999;"></i>{{ $event->contact_person }}
                                    </p>
                                @endif
                                @if($event->contact_email)
                                    <p class="text-[16px] flex items-center gap-2" style="color:#333333;">
                                        <i class="fas fa-envelope text-[13px]" style="color:#999;"></i>{{ $event->contact_email }}
                                    </p>
                                @endif
                                @if($event->contact_phone)
                                    <p class="text-[16px] flex items-center gap-2" style="color:#333333;">
                                        <i class="fas fa-phone text-[13px]" style="color:#999;"></i>{{ $event->contact_phone }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        @endif

                    </div>
                </div>
                @endif

                <p class="text-center text-[13px]" style="color:#333333;">Posted {{ $createdPH->format('M d, Y \a\t g:i A') }}</p>
            </div>
        </div>

    </div>

</div>
@endif


{{-- ══ RSVP MODAL ══
     Changes: no X close button (Cancel button only at bottom); no
     message/optional textarea; once the alumni already has an RSVP,
     the 3 action rows become read-only indicators with the chosen one
     highlighted, instead of clickable buttons. Submitting an RSVP no
     longer auto-closes this modal or the event view — alumni stays put
     and simply sees the indicator update. ══ --}}
@if($showRsvpModal)
@php $currentRsvp = $this->alumniRsvp; @endphp
<div class="fixed inset-0 z-[10001] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeRsvpModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden relative share-sheet">
        <div class="px-6 py-5 border-b border-white/10" style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                <i class="fas fa-calendar-plus text-white/80"></i> Confirm Your RSVP
            </h2>
            <p class="text-sm text-white/70 mt-0.5">Let us know if you're attending this event</p>
        </div>
        <div class="px-6 py-5 space-y-3">

            @if($currentRsvp)
                {{-- Read-only indicators — the chosen response is highlighted,
                     the other two are dimmed. Tapping Change on the sidebar
                     re-opens this same modal so alumni can still update. --}}
                <div class="rsvp-indicator-row {{ $currentRsvp->response === 'CONFIRMED' ? 'is-selected' : '' }}" style="{{ $currentRsvp->response === 'CONFIRMED' ? 'border-color:#7a3f91;background:#f5eef9;' : 'border-color:#e5e7eb;background:#fff;' }}">
                    <span class="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-circle-check text-emerald-600 text-lg"></i>
                    </span>
                    <div class="flex-1 text-left">
                        <p class="font-semibold text-emerald-700 text-sm">I'm Attending</p>
                        <p class="text-xs text-emerald-600">Confirm your attendance</p>
                    </div>
                    @if($currentRsvp->response === 'CONFIRMED')<i class="fas fa-check-circle text-emerald-600"></i>@endif
                </div>
                <div class="rsvp-indicator-row {{ $currentRsvp->response === 'TENTATIVE' ? 'is-selected' : '' }}" style="{{ $currentRsvp->response === 'TENTATIVE' ? 'border-color:#7a3f91;background:#f5eef9;' : 'border-color:#e5e7eb;background:#fff;' }}">
                    <span class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-circle-question text-amber-600 text-lg"></i>
                    </span>
                    <div class="flex-1 text-left">
                        <p class="font-semibold text-amber-700 text-sm">Maybe</p>
                        <p class="text-xs text-amber-600">You might attend</p>
                    </div>
                    @if($currentRsvp->response === 'TENTATIVE')<i class="fas fa-check-circle text-amber-600"></i>@endif
                </div>
                <div class="rsvp-indicator-row {{ $currentRsvp->response === 'DECLINED' ? 'is-selected' : '' }}" style="{{ $currentRsvp->response === 'DECLINED' ? 'border-color:#7a3f91;background:#f5eef9;' : 'border-color:#e5e7eb;background:#fff;' }}">
                    <span class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-circle-xmark text-red-600 text-lg"></i>
                    </span>
                    <div class="flex-1 text-left">
                        <p class="font-semibold text-red-700 text-sm">I Can't Attend</p>
                        <p class="text-xs text-red-600">You won't be attending</p>
                    </div>
                    @if($currentRsvp->response === 'DECLINED')<i class="fas fa-check-circle text-red-600"></i>@endif
                </div>

                <p class="text-xs text-center text-gray-400 pt-1">Tap a different option below to change your response.</p>

                <div class="flex flex-col gap-2 pt-1">
                    @if($currentRsvp->response !== 'CONFIRMED')
                    <button type="button" wire:click="submitRsvp('CONFIRMED')" wire:loading.attr="disabled"
                            class="w-full px-3 py-2 rounded-lg text-xs font-semibold border border-emerald-200 text-emerald-700 hover:bg-emerald-50 transition cursor-pointer">
                        Switch to I'm Attending
                    </button>
                    @endif
                    @if($currentRsvp->response !== 'TENTATIVE')
                    <button type="button" wire:click="submitRsvp('TENTATIVE')" wire:loading.attr="disabled"
                            class="w-full px-3 py-2 rounded-lg text-xs font-semibold border border-amber-200 text-amber-700 hover:bg-amber-50 transition cursor-pointer">
                        Switch to Maybe
                    </button>
                    @endif
                    @if($currentRsvp->response !== 'DECLINED')
                    <button type="button" wire:click="submitRsvp('DECLINED')" wire:loading.attr="disabled"
                            class="w-full px-3 py-2 rounded-lg text-xs font-semibold border border-red-200 text-red-700 hover:bg-red-50 transition cursor-pointer">
                        Switch to I Can't Attend
                    </button>
                    @endif
                </div>
            @else
                {{-- No RSVP yet — normal clickable action buttons --}}
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
            @endif

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


@if($showShareModal)
@php
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
        $fbLines[] = "🎉 Thank you to everyone who attended!";
    } else {
        $fbLines[] = "📅 Event: {$shareEventTitle}";
        if ($shareDate)        $fbLines[] = "🗓️  {$shareDate}" . ($shTimeStr ? " · {$shTimeStr}" : '');
        if ($shareVenue)       $fbLines[] = "📍 {$shareVenue}";
        if ($shareOrganizer)   $fbLines[] = "🏫 Organized by: {$shareOrganizer}";
        if ($shareTargetParts) $fbLines[] = "👥 Open for: {$shareTargetParts}";
        $fbLines[] = '';
        $fbLines[] = "See you there! 🎉";
    }
    $fbPostText = implode("\n", $fbLines);
@endphp

<div id="share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45"
     x-data="{
         copied:false,
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         shareText: {{ json_encode($fbPostText) }},
         eventTitle: {{ json_encode($shareEventTitle) }},
         imageUrl:  {{ json_encode($sharePhotoUrl) }},
         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'event-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
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
         async shareOnFacebook() {
             if (this.nativeShareSupported) { await this.nativeShare(); return; }
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             window.open(url,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
         },
         async shareOnMessenger() {
             if (this.nativeShareSupported) { await this.nativeShare(); return; }
             window.open('https://www.messenger.com/new','_blank','noopener,noreferrer');
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
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 share-modal-wrapper">

        <div class="share-sheet-header flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Event
            </h2>
            <button wire:click="closeShareModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0">
                    @if($sharePhotoUrl)
                    <div class="w-full bg-gray-100">
                        <img src="{{ $sharePhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full object-cover" style="max-height:160px;display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-100 px-4 py-3 {{ $isCompleted ? 'bg-amber-50/50' : 'bg-gray-50' }}">
                        <p class="font-semibold leading-tight" style="font-size:clamp(12px,1.2vw,14px);color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="font-medium mt-0.5" style="font-size:clamp(10px,1vw,12px);color:#333333;">{{ $shareOrganizer }}</p>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @if($shareDate)        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ $shareDate }}@if($shTimeStr) · {{ $shTimeStr }}@endif</span> @endif
                            @if($shareVenue)       <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ $shareVenue }}</span> @endif
                            @if($shareTargetParts) <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ Str::limit($shareTargetParts, 30) }}</span> @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-4 py-2">
                        <p class="leading-relaxed" style="font-size:clamp(10px,0.9vw,12px);color:#333333;">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5" style="color:#333333;"></i>
                    <p class="text-xs leading-relaxed" style="color:#333333;">
                        Sharing sends the event's photo and caption straight into the post —
                        no link needed. Use <strong>Share</strong> to open your device's
                        share sheet and pick Messenger, Facebook, or any app.
                    </p>
                </div>
            </div>

            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <div class="text-left flex-1">
                            <p class="text-xs font-semibold">Share</p>
                            <p class="text-[10px] text-white/70 mt-0.5">Send photo + caption via Messenger, Facebook, or any app</p>
                        </div>
                    </button>
                </template>

                <button type="button" @click="shareOnFacebook()" class="share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share on Facebook</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Posts the photo + caption directly</p>
                    </div>
                </button>

                <button type="button" @click="shareOnMessenger()" class="share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Send via Messenger</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Opens Messenger to pick a contact</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                <button type="button" wire:click="openForwardModal"
                        class="share-option-btn" style="background:#7a3f91;">
                    <span class="icon-wrap" style="background:rgba(255,255,255,.20);">
                        <i class="fas fa-comments text-white text-sm"></i>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share to Batch Chat</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Choose which of your chats to send to</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white" style="color:#333333;">or copy caption</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300
                               hover:bg-gray-50 text-sm transition cursor-pointer bg-white" style="color:#333333;">
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
    </div>
</div>
@endif


@if($showForwardModal)
<div class="fixed inset-0 z-[10003] flex items-center justify-center p-4 bg-black/45"
     @keydown.escape.window="$wire.closeForwardModal()">
    <div class="share-sheet bg-white rounded-2xl w-full max-w-[420px] shadow-xl border border-gray-200 flex flex-col" style="max-height:85vh;">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-paper-plane text-[#7a3f91] text-xs"></i> Send to Chat
            </h2>
            <button wire:click="closeForwardModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="px-5 py-3 flex-shrink-0">
            <p class="text-xs" style="color:#333333;">Tap to select — pick one chat, or both to send to everyone at once.</p>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-5 pb-3 flex flex-col gap-2">
            @forelse($alumniChatRooms as $room)
            @php $isSelected = in_array($room['id'], $selectedRoomIds); @endphp
            <button type="button"
                    wire:click="toggleRoomSelection({{ $room['id'] }})"
                    class="w-full flex items-center gap-3 px-3.5 py-3 rounded-xl border transition text-left cursor-pointer
                           {{ $isSelected ? 'border-[#7a3f91] bg-[#f5eef9]' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
                <span class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0
                             {{ $isSelected ? 'bg-[#7a3f91]' : 'bg-gray-100' }}">
                    <i class="fas fa-users text-sm {{ $isSelected ? 'text-white' : 'text-gray-400' }}"></i>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-semibold truncate" style="color:#333333;">{{ $room['label'] }}</span>
                </span>
                <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0
                             {{ $isSelected ? 'bg-[#7a3f91] border-[#7a3f91]' : 'border-gray-300' }}">
                    @if($isSelected)<i class="fas fa-check text-white text-[10px]"></i>@endif
                </span>
            </button>
            @empty
            <p class="text-xs text-center py-6" style="color:#333333;">No chats found to send to.</p>
            @endforelse
        </div>

        <div class="px-5 py-3.5 border-t border-gray-100 flex-shrink-0 flex items-center gap-2">
            <button type="button" wire:click="closeForwardModal"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                Cancel
            </button>
            <button type="button" wire:click="confirmSendToChat"
                    wire:loading.attr="disabled"
                    wire:target="confirmSendToChat"
                    @if(empty($selectedRoomIds)) disabled @endif
                    class="flex-1 px-4 py-2.5 rounded-xl text-xs font-semibold text-white transition cursor-pointer
                           bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                <span wire:loading.remove wire:target="confirmSendToChat">
                    <i class="fas fa-paper-plane text-[11px]"></i>
                    Send{{ count($selectedRoomIds) > 1 ? ' to Both' : '' }}
                </span>
                <span wire:loading wire:target="confirmSendToChat">
                    <i class="fas fa-spinner fa-spin text-[11px]"></i> Sending…
                </span>
            </button>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    function isTouchOrSmall() {
        return window.matchMedia('(max-width: 767px)').matches ||
               (window.matchMedia('(pointer: coarse)').matches);
    }

    // Auto-fit/shrink-to-fit was removed — the right panel now scrolls
    // normally (see #ev-detail-outer's lg:overflow-y-auto) instead of
    // scaling font size down to squeeze everything into one screen.
    // Kept as a no-op so existing call sites below don't need touching.
    function evAutoFitDetail() { /* no-op: panel scrolls instead of scaling */ }

    let evResizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(evResizeTimer);
        evResizeTimer = setTimeout(evAutoFitDetail, 120);
    });

    function init() {
        const label = document.getElementById('ev-cursor-label');

        evAutoFitDetail();

        if (!label) return;
        if (isTouchOrSmall()) return;

        let activeCard = null;
        let mouseX = 0;
        let mouseY = 0;

        function show() {
            if (isTouchOrSmall()) return;
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

        function attachListeners() {
            document.querySelectorAll('[data-ev-card]').forEach(card => {
                if (card._evBound) return;
                card._evBound = true;

                card.addEventListener('mouseenter', onCardEnter);
                card.addEventListener('mouseleave', onCardLeave);

                const shareBtn = card.querySelector('[data-ev-share]');
                if (shareBtn) {
                    shareBtn.addEventListener('mouseenter', onShareEnter);
                    shareBtn.addEventListener('mouseleave', onShareLeave);
                }
            });
        }

        attachListeners();

        document.addEventListener('livewire:navigated', () => {
            document.querySelectorAll('[data-ev-card]').forEach(c => { c._evBound = false; });
            attachListeners();
            evAutoFitDetail();
        });

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', ({ el }) => {
                requestAnimationFrame(() => {
                    document.querySelectorAll('[data-ev-card]').forEach(c => { c._evBound = false; });
                    attachListeners();
                    evAutoFitDetail();
                });
            });
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        requestAnimationFrame(() => {
                            document.querySelectorAll('[data-ev-card]').forEach(c => { c._evBound = false; });
                            attachListeners();
                            evAutoFitDetail();
                        });
                    });
                });
            } catch(e) {}
        }

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

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.has('event')) {
        window.history.replaceState({}, '', window.location.pathname);
    }
})();
</script>

</div>