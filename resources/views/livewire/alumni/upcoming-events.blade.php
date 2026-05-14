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
    public string $filterSort   = 'recent';
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

    // ── Pagination ────────────────────────────────────────────────────────────
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
        $this->filterSort   = 'recent';
        $this->page         = 1;
    }

    public function updatingSearch():       void { $this->page = 1; }
    public function updatingFilterStatus(): void { $this->page = 1; }
    public function updatingFilterSort():   void { $this->page = 1; }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) $this->page++;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) $this->page--;
    }

    // ── Full filtered collection ──────────────────────────────────────────────
    #[Computed]
    public function events()
    {
        $college = $this->alumniCollege;
        $courses = $this->alumniCourses;

        if (!$college || empty($courses)) return collect();

        $now = \Carbon\Carbon::now('UTC');

        // ── Admin events ──────────────────────────────────────────────────────
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

        // ── Organizer events ──────────────────────────────────────────────────
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

        // ── Status filter ─────────────────────────────────────────────────────
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

        // ── Search ────────────────────────────────────────────────────────────
        if ($this->search !== '') {
            $s = trim($this->search);
            $adminQ->where(fn($sub) => $sub->where('title', 'like', "%{$s}%")->orWhere('venue', 'like', "%{$s}%"));
            $organizerQ->where(fn($sub) => $sub->where('title', 'like', "%{$s}%")->orWhere('venue', 'like', "%{$s}%"));
        }

        $all = $adminQ->get()->concat($organizerQ->get());

        return $all->sortBy(function ($event) {
            return $this->filterSort === 'oldest'
                ? $event->created_at
                : $event->created_at->timestamp * -1;
        }, SORT_NUMERIC)->values();
    }

    // ── Paginated slice ───────────────────────────────────────────────────────
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

    // ── View / RSVP / Share ───────────────────────────────────────────────────
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
            return AdminEvent::withoutTrashed()
                ->where('id', $this->viewingEventId)
                ->whereIn('status', ['APPROVED', 'COMPLETED'])
                ->withCount($counts)->first();
        }
        return OrganizerEvent::where('id', $this->viewingEventId)
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->withCount($counts)->first();
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
                ['response' => $response, 'message'  => trim($this->rsvpMessage) ?: null]
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
        $this->showShareModal    = false;
        $this->shareEventId      = null;
        $this->shareEventType    = '';
        $this->shareEventTitle   = '';
        $this->shareVenue        = '';
        $this->shareDate         = '';
        $this->shareTime         = '';
        $this->shareEndTime      = '';
        $this->shareDescription  = '';
        $this->shareOrganizer    = '';
        $this->shareTargetParts  = '';
        $this->sharePhotoUrl     = '';
        $this->shareIsCompleted  = false;
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

{{-- FIX 2: height (not min-height) so the whole page fits in the viewport without outer scroll --}}
<div class="flex flex-col" style="height: calc(100vh - 120px); overflow: hidden;">

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
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
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

/* ── Event Cards ── */
.event-card {
    background: #ffffff;
    border: 1px solid #E8E0F0;
    border-radius: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    transition: border-color .18s;
    overflow: hidden;
}
.event-card:hover { border-color: #c4b5d4; }
.event-card:hover .card-view-btn { background-color: #5e2f72 !important; }

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

/* ── Unified content block ── */
.content-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    /* FIX: must not overflow its flex parent */
    min-height: 0;
}
.content-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
/* FIX: cards body scrolls internally, never grows the page */
.content-block-body {
    flex: 1;
    min-height: 0;
    background: #fafafa;
    padding: 1rem;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db #f3f4f6;
}
.content-block-body::-webkit-scrollbar { width: 5px; }
.content-block-body::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.content-block-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.content-block-body::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Pagination bar ── */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #7A3F91, #9b59b6);
    flex-shrink: 0;
}
.pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: #ffffff;
    background: rgba(255,255,255,.15);
    border: none;
    cursor: pointer;
    transition: background .15s;
}
.pagination-btn:hover:not(:disabled) { background: rgba(255,255,255,.25); }
.pagination-btn:disabled {
    color: rgba(255,255,255,.3);
    background: rgba(255,255,255,.05);
    cursor: not-allowed;
}
.pagination-current {
    padding: 0.375rem 0.875rem;
    border-radius: 0.5rem;
    background: #ffffff;
    color: #333333;
    font-size: 0.875rem;
    font-weight: 700;
    white-space: nowrap;
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
     class="fixed top-5 right-4 sm:right-6 z-[99999] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
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

{{-- ══ MAIN LAYOUT — flex column, fills the fixed-height parent exactly ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0 overflow-hidden">

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Upcoming Events</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">
                    Events available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-calendar-check text-purple-600 text-[10px]"></i>
                {{ $this->events->count() }} Event{{ $this->events->count() !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    {{-- ══ UNIFIED CONTENT BLOCK — filter + cards + pagination ══ --}}
    {{-- FIX: flex-1 min-h-0 so it fills remaining space without growing past viewport --}}
    <div class="flex-1 min-h-0 flex flex-col content-block">

        {{-- ── FILTER BAR ── --}}
        <div class="content-block-filter flex flex-wrap gap-2 items-center">

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
                <option value="">All Events</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
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

        {{-- ── CARDS BODY — scrolls internally ── --}}
        <div class="content-block-body relative">

            <div wire:loading
                 wire:target="search,filterStatus,filterSort,resetFilters,nextPage,previousPage"
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

            @if($this->pagedEvents->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($this->pagedEvents as $event)
                @php
                    $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                   (!$event->event_end_date && $event->event_date <= now('UTC'));
                    $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                    $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
                    $postedAgo   = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    $timeDisplay = $eventDate->format('g:i A') . ($eventEndDate ? ' – ' . $eventEndDate->format('g:i A') : '');
                    $hasPhoto    = !empty($event->photo_url);
                @endphp

                <div class="event-card flex flex-col">

                    @if($hasPhoto)
                    <div class="relative w-full" style="height:130px; flex-shrink:0;">
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="w-full h-full object-cover">
                        <div class="absolute inset-x-0 bottom-0 h-10 pointer-events-none"
                             style="background:linear-gradient(to top,rgba(0,0,0,.45),transparent);"></div>
                        <div class="absolute top-2.5 right-2.5">
                            {{-- FIX 1: "Done" → "Completed" --}}
                            @if($isCompleted)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-700/90 backdrop-blur-sm text-white">
                                    <i class="fas fa-circle-check text-[8px]"></i> Completed
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-600/90 backdrop-blur-sm text-white">
                                    <i class="fas fa-calendar-check text-[8px]"></i> Upcoming
                                </span>
                            @endif
                        </div>
                        <div class="absolute top-2.5 left-2.5">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-black/40 backdrop-blur-sm text-white">
                                @if($event->event_source === 'ADMIN')
                                    <i class="fas fa-shield-halved text-[8px]"></i> Admin
                                @else
                                    <i class="fas fa-building-columns text-[8px]"></i> Organizer
                                @endif
                            </span>
                        </div>
                    </div>
                    @else
                    <div class="relative w-full flex items-center justify-center flex-shrink-0"
                         style="height:72px; background:linear-gradient(135deg,#7a3f91 0%,#4a1f6a 100%);">
                        <i class="fas fa-calendar-days text-white/20 text-3xl"></i>
                        <div class="absolute top-2.5 right-2.5">
                            {{-- FIX 1: "Done" → "Completed" --}}
                            @if($isCompleted)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-700/90 text-white">
                                    <i class="fas fa-circle-check text-[8px]"></i> Completed
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-600/90 text-white">
                                    <i class="fas fa-calendar-check text-[8px]"></i> Upcoming
                                </span>
                            @endif
                        </div>
                        <div class="absolute top-2.5 left-2.5">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white/20 text-white">
                                @if($event->event_source === 'ADMIN')
                                    <i class="fas fa-shield-halved text-[8px]"></i> Admin
                                @else
                                    <i class="fas fa-building-columns text-[8px]"></i> Organizer
                                @endif
                            </span>
                        </div>
                    </div>
                    @endif

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-widest mb-0.5" style="color:#777777;">
                                @if($event->event_source === 'ADMIN') PHILCST Admin
                                @else {{ $event->organizer?->name ?? 'Organizer' }}
                                @endif
                            </p>
                            <h3 class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</h3>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-calendar text-violet-500 text-[10px] flex-shrink-0"></i>
                            <span class="text-xs font-semibold" style="color:#333333;">{{ $eventDate->format('M d, Y') }}</span>
                            @if($timeDisplay)
                                <span class="text-xs" style="color:#555555;">· {{ $timeDisplay }}</span>
                            @endif
                        </div>

                        @if($event->venue)
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-location-dot text-rose-500 text-[10px] flex-shrink-0"></i>
                            <span class="text-xs truncate" style="color:#555555;">{{ $event->venue }}</span>
                        </div>
                        @endif

                        @if($event->target_participants)
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-users text-purple-500 text-[10px] flex-shrink-0"></i>
                            <span class="text-xs truncate font-semibold px-2 py-0.5 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 max-w-full">
                                {{ Str::limit($event->target_participants, 38) }}
                            </span>
                        </div>
                        @endif

                        <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-auto">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px]" style="color:#777777;">{{ $postedAgo }}</span>
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600">
                                    <i class="fas fa-circle-check text-[9px]"></i>
                                    {{ $event->confirmed_count }} Attending
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button"
                                        wire:click.stop="openShareModal({{ $event->id }}, '{{ $event->event_source }}')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer
                                               {{ $isCompleted
                                                   ? 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-white hover:border-amber-400'
                                                   : 'bg-sky-50 text-sky-700 border-sky-200 hover:bg-white hover:border-sky-400' }}">
                                    <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-[10px]"></i>
                                    <span class="hidden sm:inline">{{ $isCompleted ? 'Highlights' : 'Share' }}</span>
                                </button>
                                <button type="button"
                                        wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')"
                                        class="card-view-btn inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition cursor-pointer"
                                        style="background-color:#7a3f91;">
                                    <i class="fas fa-eye text-[10px]"></i> View
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
                    <p class="font-semibold text-base" style="color:#333333;">
                        @if($search || $filterStatus !== '') No events match your filters
                        @else No events found
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus !== '') Try clearing your filters to see all available events.
                        @else Check back soon — new events will appear here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus !== '')
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ══ PAGINATION BAR — only for All Events & Completed ══ --}}
        @if($filterStatus !== 'upcoming')
        @php
            $total = $this->events->count();
            $from  = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to    = min($page * $perPage, $total);
        @endphp
        <div class="pagination-bar">
            <p class="text-sm text-white/80 font-medium">
                Showing
                <span class="text-white font-bold">{{ $from }}–{{ $to }}</span>
                of
                <span class="text-white font-bold">{{ $total }}</span>
                event{{ $total !== 1 ? 's' : '' }}
            </p>
            <div class="flex items-center gap-1.5">
                <button wire:click="previousPage"
                        class="pagination-btn"
                        @if($page <= 1) disabled @endif>
                    <i class="fas fa-chevron-left text-xs"></i>
                    <span>Prev</span>
                </button>
                <span class="pagination-current">{{ $page }} / {{ $this->totalPages }}</span>
                <button wire:click="nextPage"
                        class="pagination-btn"
                        @if($page >= $this->totalPages) disabled @endif>
                    <span>Next</span>
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
            </div>
        </div>
        @endif

    </div>

</div>


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW EVENT — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $event        = $this->viewingEvent;
    $eventDate    = $event->event_date->setTimezone('Asia/Manila');
    $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
    $isCompleted  = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                    (!$event->event_end_date && $event->event_date <= now('UTC'));
    $totalRsvp    = $event->confirmed_count + $event->declined_count + $event->tentative_count;
    $alumniRsvp   = $this->alumniRsvp;
    $hasPhoto     = !empty($event->photo_url);
    $timeDisplay  = $eventDate->format('g:i A') . ($eventEndDate ? ' – ' . $eventEndDate->format('g:i A') : '');
    $createdPH    = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila');
@endphp

<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 overflow-hidden fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-[10px] font-semibold uppercase tracking-widest truncate">
                    @if($viewingEventType === 'ADMIN') PHILCST Admin
                    @else {{ $event->organizer?->name ?? 'Organizer' }}
                    @endif
                </p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $event->title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
            <button type="button"
                    wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer active:scale-95
                           {{ $isCompleted ? 'bg-amber-400/20 hover:bg-amber-400/30 border-amber-300/40 text-white' : 'bg-white/10 hover:bg-white/20 border-white/20 text-white' }}">
                <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-xs"></i>
                <span class="hidden sm:inline">{{ $isCompleted ? 'Highlights' : 'Share' }}</span>
            </button>
            @if(!$isCompleted)
            <button type="button" wire:click="openRsvpModal"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer active:scale-95">
                <i class="fas fa-check text-xs"></i>
                <span class="hidden sm:inline">{{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}</span>
            </button>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            @if($hasPhoto)
            <div class="w-full px-4 pt-4 pb-2 shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                         class="w-full object-contain"
                         style="max-height:190px; display:block;">
                    <div class="absolute top-2 right-2">
                        {{-- FIX 1: "Done" → "Completed" in view modal --}}
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Completed</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-calendar-check text-[9px]"></i> Upcoming</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:90px; background:linear-gradient(135deg,#7A3F91 0%,#4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    {{-- FIX 1: "Done" → "Completed" in no-photo view modal --}}
                    @if($isCompleted)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-semibold">Completed</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Upcoming</span>
                    @endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100"><i class="fas fa-calendar text-violet-600 text-base"></i></span>
                    <div>
                        <p class="meta-label">Date &amp; Time</p>
                        <p class="meta-value">{{ $eventDate->format('F d, Y') }}</p>
                        <p class="meta-sub">{{ $timeDisplay }}</p>
                    </div>
                </div>

                @if($event->venue)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Venue</p>
                        <p class="meta-value truncate">{{ $event->venue }}</p>
                        @if($event->venue_address)
                            <p class="meta-sub truncate">{{ $event->venue_address }}</p>
                        @endif
                    </div>
                </div>
                @endif

                @if($event->target_participants)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-purple-100"><i class="fas fa-users text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Open For</p>
                        <p class="meta-value line-clamp-2">{{ $event->target_participants }}</p>
                    </div>
                </div>
                @endif

                @if($event->contact_person || $event->contact_email || $event->contact_phone)
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="meta-label mb-2">Contact</p>
                    <div class="flex flex-col gap-2">
                        @if($event->contact_person)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-user text-purple-500 text-sm w-4"></i>
                            <span class="text-sm font-semibold" style="color:#333333;">{{ $event->contact_person }}</span>
                        </div>
                        @endif
                        @if($event->contact_email)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-envelope text-sky-500 text-sm w-4"></i>
                            <span class="text-sm truncate" style="color:#333333;">{{ $event->contact_email }}</span>
                        </div>
                        @endif
                        @if($event->contact_phone)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-phone text-emerald-500 text-sm w-4"></i>
                            <span class="text-sm" style="color:#333333;">{{ $event->contact_phone }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($alumniRsvp)
                @php
                    $rsvpColor = match($alumniRsvp->response) {
                        'CONFIRMED' => '#16a34a', 'DECLINED' => '#dc2626', 'TENTATIVE' => '#d97706', default => '#6b7280'
                    };
                    $rsvpIcon = match($alumniRsvp->response) {
                        'CONFIRMED' => 'fa-circle-check', 'DECLINED' => 'fa-circle-xmark', 'TENTATIVE' => 'fa-circle-question', default => 'fa-circle'
                    };
                @endphp
                <div class="p-3.5 rounded-xl border flex items-center justify-between gap-2"
                     style="background-color: {{ $rsvpColor }}15; border-color: {{ $rsvpColor }}40;">
                    <div class="flex items-center gap-2.5">
                        <i class="fas {{ $rsvpIcon }} text-base flex-shrink-0" style="color:{{ $rsvpColor }};"></i>
                        <div>
                            <p class="meta-label">Your RSVP</p>
                            <p class="text-sm font-bold" style="color:{{ $rsvpColor }};">{{ $alumniRsvp->response }}</p>
                        </div>
                    </div>
                    @if(!$isCompleted)
                    <button wire:click="openRsvpModal"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-white cursor-pointer hover:opacity-90 transition"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-pen-to-square text-[10px] mr-1"></i> Change
                    </button>
                    @endif
                </div>
                @endif

                <p class="text-xs text-center" style="color:#777777;">
                    Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>
            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <p class="text-xs font-bold uppercase tracking-widest shrink-0" style="color:#333333;">Attendee Responses</p>
                    @if($totalRsvp === 0)
                        <span class="text-sm italic" style="color:#555555;">No responses yet{{ !$isCompleted ? ' — be the first to RSVP!' : '' }}</span>
                    @else
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
                                <i class="fas fa-circle-check text-[9px]"></i> {{ $event->confirmed_count }} Confirmed
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-700">
                                <i class="fas fa-circle-question text-[9px]"></i> {{ $event->tentative_count }} Maybe
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-50 border border-red-200 text-xs font-semibold text-red-700">
                                <i class="fas fa-circle-xmark text-[9px]"></i> {{ $event->declined_count }} Declined
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-5 py-4 flex flex-col gap-4">

                @if($event->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        About This Event
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($event->description) }}</div>
                </div>
                @endif

                @if($event->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-amber-50">
                            <i class="fas fa-list-check text-amber-500 text-[10px]"></i>
                        </span>
                        Additional Notes
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-amber-50/50 rounded-lg p-4 border border-amber-100" style="line-height:1.75; color:#333333;">{{ trim($event->notes) }}</div>
                </div>
                @endif

                @if(!$event->description && !$event->notes)
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
     RSVP MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showRsvpModal)
<div class="fixed inset-0 z-[10001] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="closeRsvpModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden relative">
        <button wire:click="closeRsvpModal" type="button"
                class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-white/20 hover:bg-white/30 transition text-white z-10">
            <i class="fas fa-xmark text-base"></i>
        </button>
        <div class="px-6 py-5 border-b border-white/10" style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                <i class="fas fa-clipboard-check text-white/80"></i> Confirm Your RSVP
            </h2>
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
                <label class="block text-xs font-semibold uppercase tracking-widest mb-2" style="color:#555555;">Message <span class="font-normal normal-case" style="color:#777777;">— optional</span></label>
                <textarea wire:model="rsvpMessage" rows="2"
                          placeholder="Add a personal note or question…"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100 transition resize-none"
                          style="color:#333333;"
                          maxlength="200"></textarea>
                <p class="text-xs mt-1" style="color:#777777;">{{ strlen($rsvpMessage) }}/200</p>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50">
            <button wire:click="closeRsvpModal" type="button"
                    class="w-full px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 bg-white hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                Cancel
            </button>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE / HIGHLIGHTS MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->eventsBaseUrl();
    $shareDescPreview = mb_strlen($shareDescription) > 160
        ? mb_substr($shareDescription, 0, 160) . '…'
        : $shareDescription;
    $shTimeStr   = $shareTime . ($shareEndTime ? ' – ' . $shareEndTime : '');
    $shareHost   = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isCompleted = $shareIsCompleted;

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
    $fbShareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);

    $hasRealPhoto = $sharePhotoUrl
        && !str_contains($sharePhotoUrl, 'default')
        && str_contains($sharePhotoUrl, '/storage/');
@endphp

<div wire:ignore
     class="fixed inset-0 z-[10002] flex items-center justify-center p-4"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false,
         showFallback: false, fallbackText: '',
         fbText:      {{ json_encode($fbPostText) }},
         baseUrl:     {{ json_encode($shareBaseUrl) }},
         fbUrl:       {{ json_encode($fbShareUrl) }},
         photoUrl:    {{ json_encode($sharePhotoUrl) }},
         hasPhoto:    {{ $hasRealPhoto ? 'true' : 'false' }},
         close() { this.open=false; setTimeout(() => $wire.closeShareModal(), 250); },

         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     const ok = document.execCommand('copy'); document.body.removeChild(ta);
                     if(!ok) throw new Error('execCommand failed');
                 }
                 return true;
             } catch(e) { this.fallbackText=text; this.showFallback=true; return false; }
         },
         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const htmlContent = '<img src=\''+imageUrl+'\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>'+text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</pre>';
                     const htmlBlob = new Blob([htmlContent], {type:'text/html'});
                     const textBlob = new Blob([text], {type:'text/plain'});
                     await navigator.clipboard.write([new ClipboardItem({'text/html':htmlBlob,'text/plain':textBlob})]);
                     return true;
                 }
             } catch(e) { console.warn('Rich copy failed:', e); }
             return await this.copyPlainText(text);
         },
         async shareOnFacebook() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied = true;
             setTimeout(() => { this.fbCopied = false; }, 7000);
             setTimeout(() => {
                 const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
                 const popup = window.open(this.fbUrl,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
                 if(!popup||popup.closed) window.open(this.fbUrl,'_blank');
             }, 150);
         },
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.messengerCopied = true;
             setTimeout(() => { this.messengerCopied = false; }, 7000);
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) { window.location.href='fb-messenger://share/?link='+encodeURIComponent(this.baseUrl); setTimeout(()=>window.open('https://www.messenger.com/','_blank'),1500); }
             else { window.open('https://www.messenger.com/','_blank'); }
         },
         async copyLinkFn() {
             const ok = await this.copyPlainText(this.baseUrl);
             if(ok) { this.copied=true; setTimeout(()=>this.copied=false,2500); }
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

        <div x-show="showFallback" x-cloak class="px-6 py-3 border-b border-amber-200 bg-amber-50 flex-shrink-0">
            <p class="text-xs font-semibold text-amber-800 mb-2 flex items-center gap-1.5">
                <i class="fas fa-triangle-exclamation"></i> Auto-copy blocked. Copy manually:
            </p>
            <textarea x-ref="fallbackArea" x-text="fallbackText" rows="3"
                      class="w-full px-3 py-2 text-xs border border-amber-300 rounded-lg bg-white resize-none focus:outline-none"
                      readonly @click="$refs.fallbackArea.select()"></textarea>
            <button @click="showFallback=false" class="mt-1 text-xs text-amber-700 font-semibold hover:underline">Dismiss</button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($sharePhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3 pb-0">
                        <img src="{{ $sharePhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px;display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color: {{ $isCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#333333;">
                            {{ $shareDate }}@if($shTimeStr) · {{ $shTimeStr }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareVenue }}
                            </span>
                            @endif
                            @if($shareTargetParts)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareTargetParts, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#333333;">{{ $shareDescPreview }}</p>
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
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the
                            {{ $isCompleted ? 'highlights' : 'event' }} text to your clipboard and opens the platform.
                            Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste it in.
                        </p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">Share dialog opened!</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Press Ctrl+V in your post to paste the caption.</p>
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
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste the caption.</p>
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
                        <span class="block text-xs text-white/70 mt-0.5">Opens share dialog · text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_al" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_al)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · text copied</span>
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
                            {{ $isCompleted ? 'Post Highlights to Batch Chat' : 'Post to Batch Chat' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">
                            {{ $isCompleted ? 'Sends highlights to your batchmates' : 'Sends directly to your batch chat room' }}
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
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>