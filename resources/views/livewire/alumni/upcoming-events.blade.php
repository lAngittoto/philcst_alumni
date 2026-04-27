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

    // ── Share modal ───────────────────────────────────────────────────────────
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

    // Alumni identity for batch chat
    public int    $alumniId     = 0;
    public int    $alumniRoomId = 0;

    public function mount(): void
    {
        set_time_limit(600);
        $user = Auth::user();
        if (!$user || !$user->alumni) abort(403, 'Access denied.');
        $alumni = $user->alumni;

        $this->alumniId      = $alumni->id;
        $this->alumniCourses = $alumni->course ? [$alumni->course->code] : [];
        $this->alumniCollege = $alumni->course?->college ?? '';

        // Resolve batch chat room
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
    }

    #[Computed]
    public function events()
    {
        $college = $this->alumniCollege;
        $courses = $this->alumniCourses;

        if (!$college || empty($courses)) return collect();

        $now = \Carbon\Carbon::now('UTC');

        $adminQ = AdminEvent::withoutTrashed()
            ->where('status', 'APPROVED')
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

        $organizerQ = OrganizerEvent::where('status', 'APPROVED')
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
                $q->where('event_date', '>', $now)
                  ->orWhere(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $now));
            });
            $organizerQ->where(function ($q) use ($now) {
                $q->where('event_date', '>', $now)
                  ->orWhere(fn($s) => $s->whereNotNull('event_end_date')->where('event_end_date', '>', $now));
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

        $all = $adminQ->get()->concat($organizerQ->get());

        return $all->sortBy(function ($event) {
            return $this->filterSort === 'oldest'
                ? $event->created_at
                : $event->created_at->timestamp * -1;
        }, SORT_NUMERIC)->values();
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
            return AdminEvent::withoutTrashed()
                ->where('id', $this->viewingEventId)
                ->where('status', 'APPROVED')
                ->withCount($counts)->first();
        }
        return OrganizerEvent::where('id', $this->viewingEventId)
            ->where('status', 'APPROVED')
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

    // ── Share ─────────────────────────────────────────────────────────────────
    public function openShareModal(int $id, string $type): void
    {
        $event = $type === 'ADMIN'
            ? AdminEvent::withoutTrashed()->where('id', $id)->where('status', 'APPROVED')->first()
            : OrganizerEvent::where('id', $id)->where('status', 'APPROVED')->first();

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

    // ── Post to Batch Chat ────────────────────────────────────────────────────
    public function postToBatchChat(): void
    {
        if (!$this->shareEventId || !$this->alumniRoomId) {
            $this->dispatch('flash-message', type: 'error', message: 'Could not find your batch chat room.');
            return;
        }

        $type  = $this->shareEventType;
        $event = $type === 'ADMIN'
            ? AdminEvent::withoutTrashed()->where('id', $this->shareEventId)->where('status', 'APPROVED')->first()
            : OrganizerEvent::where('id', $this->shareEventId)->where('status', 'APPROVED')->first();

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
:root {
    --brand:       #7a3f91;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
}

@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }

.event-card { transition: box-shadow .18s; }
.event-card:hover {
    box-shadow: 0 8px 28px rgba(122,63,145,.18), 0 2px 8px rgba(0,0,0,.07);
}
.event-card:hover .card-view-hint { background-color: #6a3080 !important; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.filter-input {
    border: 1.5px solid #e8e0f0;
    transition: border-color .15s, box-shadow .15s;
}
.filter-input:hover  { border-color: var(--brand); }
.filter-input:focus  { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
</style>

{{-- ── FLASH TOAST ─────────────────────────────────────────────────────────── --}}
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
        <p class="text-xs mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

    <div class="flex flex-col flex-1 gap-5">

        {{-- ══ PAGE HEADER ═══════════════════════════════════════════════════ --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold text-[#333333] tracking-tight">Upcoming Events</h1>
                <p class="text-base leading-relaxed mt-2 text-[#666666] font-normal">
                    Discover and RSVP to events for
                    <span class="font-semibold">{{ $alumniCollege ?: 'your college' }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-4 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 uppercase tracking-widest">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    {{ $this->events->count() }} Event{{ $this->events->count() !== 1 ? 's' : '' }}
                </span>
            </div>
        </div>

        {{-- ══ FILTER BAR ════════════════════════════════════════════════════ --}}
        <div class="px-4 py-3 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[200px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search title, venue…"
                       class="filter-input w-full pl-9 pr-3 py-2.5 rounded-xl text-sm text-[#333333] bg-white"
                       autocomplete="off" maxlength="100">
            </div>
            <select wire:model.live="filterStatus" class="filter-input px-3 py-2.5 rounded-xl text-sm bg-white text-[#333333]">
                <option value="">All Events</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>
            <select wire:model.live="filterSort" class="filter-input px-3 py-2.5 rounded-xl text-sm bg-white text-[#333333]">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters"
                    class="filter-input px-3 py-2.5 rounded-xl bg-white text-sm font-semibold text-[#666666] hover:bg-[#f5f5f5] flex items-center gap-1.5 transition uppercase tracking-widest">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ══ CARDS / EMPTY STATE ═══════════════════════════════════════════ --}}
        <div wire:loading.class="opacity-50 pointer-events-none"
             wire:target="search,filterStatus,filterSort,resetFilters">

            @if($this->events->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach($this->events as $event)
                    @php
                        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                       (!$event->event_end_date && $event->event_date <= now('UTC'));
                        $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                        $postedAgo   = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    @endphp

                    <div class="event-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="p-5 flex flex-col gap-3">

                            {{-- Organizer + Status Badge --}}
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#999999] truncate uppercase tracking-widest">
                                        @if($event->event_source === 'ADMIN')
                                            Posted by Admin
                                        @else
                                            {{ $event->organizer?->name ?? 'Organizer' }}
                                        @endif
                                    </p>
                                    <h3 class="text-base font-bold text-[#333333] leading-snug mt-0.5 line-clamp-2">{{ $event->title }}</h3>
                                </div>
                                @if($isCompleted)
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-xl border border-green-200 bg-green-50 text-green-700 mt-0.5">
                                        <i class="fa-solid fa-check text-xs"></i> Done
                                    </span>
                                @else
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 mt-0.5">
                                        <i class="fa-solid fa-calendar-check text-xs"></i> Soon
                                    </span>
                                @endif
                            </div>

                            {{-- Date --}}
                            <div class="flex items-center gap-1.5 text-sm text-[#666666]">
                                <i class="fa-solid fa-calendar text-[#999999] text-xs"></i>
                                <span>{{ $eventDate->format('M d, Y') }}</span>
                            </div>

                            {{-- Venue --}}
                            @if($event->venue)
                            <div class="flex items-center gap-1.5 text-sm text-[#666666]">
                                <i class="fa-solid fa-location-dot text-[#999999] text-xs"></i>
                                <span class="truncate">{{ $event->venue }}</span>
                            </div>
                            @endif

                            {{-- Footer --}}
                            <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-0.5">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-xs text-[#999999]">{{ $postedAgo }}</span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                        <i class="fa-solid fa-circle-check text-xs"></i>
                                        {{ $event->confirmed_count }} Attending
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    {{-- Share / Highlights button --}}
                                    <button type="button"
                                            wire:click.stop="openShareModal({{ $event->id }}, '{{ $event->event_source }}')"
                                            class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-bold border transition cursor-pointer
                                                   {{ $isCompleted
                                                       ? 'bg-amber-50 text-amber-700 border-amber-300 hover:bg-white hover:border-amber-500'
                                                       : 'bg-sky-100 text-sky-700 border-sky-300 hover:bg-white hover:border-sky-500' }}">
                                        <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-xs"></i>
                                        <span class="hidden sm:inline">{{ $isCompleted ? 'Highlights' : 'Share' }}</span>
                                    </button>
                                    <button type="button"
                                            wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')"
                                            class="card-view-hint inline-flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-lg text-white cursor-pointer"
                                            style="background-color:#7a3f91;">
                                        <i class="fa-solid fa-eye text-xs"></i> View
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

            @else
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm py-20 flex flex-col items-center gap-4 text-center px-6">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fa-solid fa-calendar-days text-2xl text-[#999999]"></i>
                    </div>
                    <div>
                        <p class="font-bold text-[#333333] text-lg">
                            @if($search || $filterStatus)
                                No events match your filters
                            @else
                                No events found
                            @endif
                        </p>
                        <p class="text-sm text-[#999999] mt-1">
                            @if($search || $filterStatus)
                                Try clearing your filters to see all available events.
                            @else
                                Check back soon — new events will be posted here for
                                <strong>{{ $alumniCollege ?: 'your college' }}</strong>.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterStatus)
                        <button wire:click="resetFilters" class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition hover:opacity-90 uppercase tracking-widest" style="background-color:#7a3f91;">
                            <i class="fa-solid fa-rotate-left mr-1.5"></i> Clear Filters
                        </button>
                    @endif
                </div>
            @endif
        </div>

    </div>{{-- end inner flex column --}}


    {{-- ══ VIEW DETAILS MODAL ════════════════════════════════════════════════ --}}
    @if($showViewModal && $this->viewingEvent)
    @php
        $event        = $this->viewingEvent;
        $eventDate    = $event->event_date->setTimezone('Asia/Manila');
        $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
        $isCompleted  = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                        (!$event->event_end_date && $event->event_date <= now('UTC'));
        $totalRsvp    = $event->confirmed_count + $event->declined_count + $event->tentative_count;
        $alumniRsvp   = $this->alumniRsvp;
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         wire:keydown.escape.window="closeViewModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative">

            <button wire:click="closeViewModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-white/25 hover:bg-white/40 transition text-white z-10">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- Header --}}
            <div class="px-6 pt-6 pb-5 flex-shrink-0 text-white" style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                <div class="flex items-center gap-2 mb-2 flex-wrap pr-8">
                    <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-calendar-check text-white text-base"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white/75">
                            @if($viewingEventType === 'ADMIN') PHILCST Admin @else {{ $event->organizer?->name ?? 'Organizer' }} @endif
                        </p>
                        @if($isCompleted)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-white/20 text-white">Completed</span>
                        @else
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-white/20 text-white">Upcoming</span>
                        @endif
                    </div>
                </div>
                <h2 class="text-2xl font-bold text-white leading-snug mb-3">{{ $event->title }}</h2>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1.5 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-calendar text-xs"></i> {{ $eventDate->format('M d, Y') }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1.5 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-clock text-xs"></i> {{ $eventDate->format('g:i A') }}@if($eventEndDate) – {{ $eventEndDate->format('g:i A') }}@endif
                    </span>
                    <span class="inline-flex items-center gap-1 text-sm font-semibold px-3 py-1.5 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-map-pin text-xs"></i> {{ $event->venue ?? 'TBD' }}
                    </span>
                </div>
            </div>

            {{-- Modal Body --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">

                {{-- Your RSVP Status --}}
                @if($alumniRsvp)
                @php
                    $rsvpColor = match($alumniRsvp->response) {
                        'CONFIRMED' => 'emerald', 'DECLINED' => 'red', 'TENTATIVE' => 'amber', default => 'gray'
                    };
                @endphp
                <div class="px-6 py-4 border-b border-gray-100"
                     style="background-color: {{ match($rsvpColor) { 'emerald' => '#f0fdf4', 'red' => '#fef2f2', 'amber' => '#fffbeb', default => '#f9fafb' } }};">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-[#999999] uppercase tracking-wide mb-1">Your Response</p>
                            <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-base font-semibold text-white"
                                  style="background-color: {{ match($rsvpColor) { 'emerald' => '#16a34a', 'red' => '#dc2626', 'amber' => '#d97706', default => '#6b7280' } }};">
                                <i class="fa-solid {{ match($rsvpColor) { 'emerald' => 'fa-circle-check', 'red' => 'fa-circle-xmark', 'amber' => 'fa-circle-question', default => 'fa-circle' } }} text-xs"></i>
                                {{ $alumniRsvp->response }}
                            </span>
                        </div>
                        @if(!$isCompleted)
                        <button wire:click="openRsvpModal" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition"
                                style="background-color:#7a3f91;">
                            <i class="fa-solid fa-pen-to-square text-sm mr-1.5"></i> Change
                        </button>
                        @endif
                    </div>
                </div>
                @endif

                {{-- RSVP Stats --}}
                <div class="px-6 py-5 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-bold text-[#333333] mb-3 flex items-center gap-2 uppercase tracking-widest">
                        <i class="fa-solid fa-users text-xs text-[#999999]"></i>
                        Attendee Responses
                    </h3>
                    @if($totalRsvp === 0)
                        <div class="text-center py-4 text-[#999999] text-sm">
                            <i class="fa-solid fa-inbox text-2xl mb-2"></i>
                            <p>No responses yet</p>
                        </div>
                    @else
                        <div class="grid grid-cols-3 gap-3">
                            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-check text-emerald-600 text-xl mb-1"></i>
                                <p class="text-2xl font-bold text-emerald-700">{{ $event->confirmed_count }}</p>
                                <p class="text-xs font-semibold text-emerald-600 mt-0.5">Confirmed</p>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-xmark text-red-600 text-xl mb-1"></i>
                                <p class="text-2xl font-bold text-red-700">{{ $event->declined_count }}</p>
                                <p class="text-xs font-semibold text-red-600 mt-0.5">Not Attending</p>
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-question text-amber-600 text-xl mb-1"></i>
                                <p class="text-2xl font-bold text-amber-700">{{ $event->tentative_count }}</p>
                                <p class="text-xs font-semibold text-amber-600 mt-0.5">Maybe</p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Location --}}
                @if($event->venue || $event->venue_address)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-[#333333] mb-2 flex items-center gap-2 uppercase tracking-widest">
                        <i class="fa-solid fa-map-pin text-xs text-[#999999]"></i> Location
                    </h3>
                    <p class="text-sm font-semibold text-[#333333]">{{ $event->venue }}</p>
                    @if($event->venue_address)
                        <p class="text-xs text-[#999999] mt-1">{{ $event->venue_address }}</p>
                    @endif
                </div>
                @endif

                {{-- Description --}}
                @if($event->description)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-[#333333] mb-3 flex items-center gap-2 uppercase tracking-widest">
                        <i class="fa-solid fa-file-lines text-xs text-[#999999]"></i> About This Event
                    </h3>
                    <div class="text-sm text-[#666666] leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">
                        {{ $event->description }}
                    </div>
                </div>
                @endif

                {{-- Notes --}}
                @if($event->notes)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-[#333333] mb-3 flex items-center gap-2 uppercase tracking-widest">
                        <i class="fa-solid fa-list-check text-xs text-[#999999]"></i> Additional Notes
                    </h3>
                    <div class="text-sm text-[#666666] leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">
                        {{ $event->notes }}
                    </div>
                </div>
                @endif

                {{-- Contact --}}
                @if($event->contact_person || $event->contact_email || $event->contact_phone)
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-[#333333] mb-3 flex items-center gap-2 uppercase tracking-widest">
                        <i class="fa-solid fa-address-card text-xs text-[#999999]"></i> Contact Information
                    </h3>
                    <div class="space-y-1.5 text-sm text-[#666666]">
                        @if($event->contact_person)
                            <p><i class="fa-solid fa-user text-[#999999] text-xs mr-2"></i><span class="font-semibold">{{ $event->contact_person }}</span></p>
                        @endif
                        @if($event->contact_email)
                            <p><i class="fa-solid fa-envelope text-[#999999] text-xs mr-2"></i>{{ $event->contact_email }}</p>
                        @endif
                        @if($event->contact_phone)
                            <p><i class="fa-solid fa-phone text-[#999999] text-xs mr-2"></i>{{ $event->contact_phone }}</p>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Event Info --}}
                <div class="px-6 py-5">
                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-3">Event Information</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-[#999999] font-semibold mb-1 uppercase tracking-widest">Posted</p>
                            <p class="text-sm font-semibold text-[#333333]">
                                {{ \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->format('M d, Y') }}
                            </p>
                            <p class="text-xs text-[#999999] mt-0.5">
                                {{ \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans() }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[#999999] font-semibold mb-1 uppercase tracking-widest">Posted By</p>
                            <p class="text-sm font-semibold text-[#333333]">
                                @if($viewingEventType === 'ADMIN') Admin
                                @else {{ $event->organizer?->name ?? 'Organizer' }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Modal Footer --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex items-center justify-end gap-2">
                {{-- Share / Highlights button — always visible --}}
                <button type="button"
                        wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                        class="px-4 py-2.5 rounded-lg text-sm font-bold border transition cursor-pointer
                               {{ $isCompleted
                                   ? 'bg-amber-50 text-amber-700 border-amber-300 hover:bg-white hover:border-amber-500'
                                   : 'bg-sky-100 text-sky-700 border-sky-300 hover:bg-white hover:border-sky-500' }}">
                    <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-xs mr-1.5"></i>
                    {{ $isCompleted ? 'Share Highlights' : 'Share' }}
                </button>
                @if(!$isCompleted)
                <button type="button" wire:click="openRsvpModal"
                        class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition flex items-center gap-2 cursor-pointer"
                        style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                    <i class="fa-solid fa-check text-xs"></i> {{ $alumniRsvp ? 'Update RSVP' : 'RSVP Now' }}
                </button>
                @endif
                <button wire:click="closeViewModal" type="button"
                        class="px-5 py-2.5 rounded-xl text-sm font-semibold text-[#666666] border border-gray-300 bg-white hover:bg-[#f5f5f5] transition cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
    @endif


    {{-- ══ RSVP MODAL ═══════════════════════════════════════════════════════ --}}
    @if($showRsvpModal)
    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         wire:keydown.escape.window="closeRsvpModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden relative">
            <button wire:click="closeRsvpModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 transition text-[#666666]">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
            <div class="px-6 py-5 border-b border-gray-100" style="background-color:#7a3f91;">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clipboard-check"></i> Confirm Your RSVP
                </h2>
                <p class="text-sm text-white/75 mt-1">Let us know if you're attending this event</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="space-y-2.5">
                    <button type="button" wire:click="submitRsvp('CONFIRMED')" wire:loading.attr="disabled"
                            class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-emerald-200 hover:border-emerald-400 bg-white">
                        <i class="fa-solid fa-circle-check text-emerald-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-emerald-700 text-base">I'm Attending</p>
                            <p class="text-xs text-emerald-600">Confirm your attendance</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-emerald-400 text-sm"></i>
                    </button>
                    <button type="button" wire:click="submitRsvp('TENTATIVE')" wire:loading.attr="disabled"
                            class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-amber-200 hover:border-amber-400 bg-white">
                        <i class="fa-solid fa-circle-question text-amber-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-amber-700 text-base">Maybe</p>
                            <p class="text-xs text-amber-600">You might attend</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-amber-400 text-sm"></i>
                    </button>
                    <button type="button" wire:click="submitRsvp('DECLINED')" wire:loading.attr="disabled"
                            class="w-full px-4 py-3.5 rounded-xl border-2 transition flex items-center gap-3 border-red-200 hover:border-red-400 bg-white">
                        <i class="fa-solid fa-circle-xmark text-red-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-red-700 text-base">I Can't Attend</p>
                            <p class="text-xs text-red-600">You won't be attending</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-red-400 text-sm"></i>
                    </button>
                </div>
                <div>
                    <label class="text-xs font-bold text-[#666666] uppercase tracking-widest mb-2 block">
                        Message (Optional)
                    </label>
                    <textarea wire:model="rsvpMessage" rows="2"
                              placeholder="Add a personal note or question…"
                              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm text-[#333333] bg-white focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100 transition resize-none"
                              maxlength="200"></textarea>
                    <p class="text-xs text-[#999999] mt-1">{{ strlen($rsvpMessage) }}/200</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex gap-3">
                <button wire:click="closeRsvpModal" type="button"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-[#666666] border border-gray-300 bg-white hover:bg-[#f5f5f5] transition cursor-pointer">
                    Cancel
                </button>
            </div>
        </div>
    </div>
    @endif


    {{-- ══ SHARE / HIGHLIGHTS MODAL ═══════════════════════════════════════════ --}}
    @if($showShareModal)
    @php
        $shareBaseUrl     = $this->eventsBaseUrl();
        $shareDescPreview = mb_strlen($shareDescription) > 140
            ? mb_substr($shareDescription, 0, 140) . '…'
            : $shareDescription;
        $timeStr = $shareTime . ($shareEndTime ? ' – ' . $shareEndTime : '');
        $shareHost = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';

        $fbPostLines = [];
        if ($shareIsCompleted) {
            $fbPostLines[] = "🏆 Event Highlights: {$shareEventTitle}";
            if ($shareDate)        $fbPostLines[] = "🗓️  {$shareDate}" . ($timeStr ? " · {$timeStr}" : '');
            if ($shareVenue)       $fbPostLines[] = "📍 {$shareVenue}";
            if ($shareOrganizer)   $fbPostLines[] = "🏫 Organized by: {$shareOrganizer}";
            if ($shareTargetParts) $fbPostLines[] = "👥 {$shareTargetParts}";
            $fbPostLines[] = '';
            $fbPostLines[] = "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇";
        } else {
            $fbPostLines[] = "📅 Event: {$shareEventTitle}";
            if ($shareDate)        $fbPostLines[] = "🗓️  {$shareDate}" . ($timeStr ? " · {$timeStr}" : '');
            if ($shareVenue)       $fbPostLines[] = "📍 {$shareVenue}";
            if ($shareOrganizer)   $fbPostLines[] = "🏫 Organized by: {$shareOrganizer}";
            if ($shareTargetParts) $fbPostLines[] = "👥 Open for: {$shareTargetParts}";
            $fbPostLines[] = '';
            $fbPostLines[] = "See full details & RSVP on the PHILCST Alumni Portal 👇";
        }
        $fbPostLines[] = $shareBaseUrl;
        $fbPostText    = implode("\n", $fbPostLines);
        $fbShareUrl    = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);
    @endphp

    {{-- ══ SHARE MODAL OVERLAY ══ --}}
    <div class="fixed inset-0 z-[70] flex items-center justify-center p-3 sm:p-4 bg-black/60 backdrop-blur-sm"
         wire:keydown.escape="closeShareModal"
         x-data="{
             copied: false,
             fbCopied: false,
             messengerCopied: false,
             showFallback: false,
             fallbackText: '',
             fbText:      {{ json_encode($fbPostText) }},
             baseUrl:     {{ json_encode($shareBaseUrl) }},
             fbUrl:       {{ json_encode($fbShareUrl) }},
             isCompleted: {{ $shareIsCompleted ? 'true' : 'false' }},

             async copyText(text) {
                 try {
                     if (navigator.clipboard && window.isSecureContext) {
                         await navigator.clipboard.writeText(text);
                     } else {
                         const ta = document.createElement('textarea');
                         ta.value = text;
                         ta.setAttribute('readonly','');
                         ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
                         document.body.appendChild(ta);
                         ta.focus(); ta.select();
                         const ok = document.execCommand('copy');
                         document.body.removeChild(ta);
                         if (!ok) throw new Error('execCommand failed');
                     }
                     return true;
                 } catch(e) {
                     this.fallbackText = text;
                     this.showFallback = true;
                     return false;
                 }
             },

             async shareOnFacebook() {
                 const ok = await this.copyText(this.fbText);
                 if (ok) { this.fbCopied = true; setTimeout(() => this.fbCopied = false, 6000); }
                 setTimeout(() => {
                     const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
                     const popup = window.open(this.fbUrl,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
                     if (!popup || popup.closed || typeof popup.closed==='undefined') window.open(this.fbUrl,'_blank');
                 }, 150);
             },

             async shareOnMessenger() {
                 const ok = await this.copyText(this.fbText);
                 if (ok) { this.messengerCopied = true; setTimeout(() => this.messengerCopied = false, 6000); }
                 const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                 if (isMobile) {
                     window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                     setTimeout(() => window.open('https://www.messenger.com/','_blank'), 1500);
                 } else {
                     window.open('https://www.messenger.com/','_blank');
                 }
             },

             async copyLinkFn() {
                 const ok = await this.copyText(this.baseUrl);
                 if (ok) { this.copied = true; setTimeout(() => this.copied = false, 2500); }
             }
         }"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">

        {{-- ══ MODAL CONTAINER — max-w-5xl so both columns are fully visible ══ --}}
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[92vh] flex flex-col overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

            {{-- ── STICKY HEADER ── --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
                <h2 class="text-lg font-bold text-[#333333] flex items-center gap-2">
                    @if($shareIsCompleted)
                        <i class="fas fa-trophy text-amber-500"></i> Share Event Highlights
                    @else
                        <i class="fas fa-share-nodes text-sky-600"></i> Share Event
                    @endif
                </h2>
                <button wire:click="closeShareModal" type="button"
                        class="w-8 h-8 rounded-full flex items-center justify-center text-[#999999] hover:text-[#333333] hover:bg-gray-100 transition cursor-pointer">
                    <i class="fas fa-xmark text-base"></i>
                </button>
            </div>

            {{-- ── COMPLETED BANNER (sticky, shrink-0) ── --}}
            @if($shareIsCompleted)
            <div class="px-6 py-3 border-b border-amber-200 bg-amber-50 flex items-center gap-2.5 flex-shrink-0">
                <i class="fas fa-circle-info text-amber-500 text-sm flex-shrink-0"></i>
                <p class="text-xs text-amber-800 leading-snug">
                    This event has already ended. You can still share the <strong>highlights</strong> with your batchmates on Facebook, Messenger, or your Batch Chat!
                </p>
            </div>
            @endif

            {{-- ── MANUAL-COPY FALLBACK (sticky, shrink-0) ── --}}
            <div x-show="showFallback" x-cloak class="px-6 py-3 border-b border-amber-200 bg-amber-50 flex-shrink-0">
                <p class="text-xs font-bold text-amber-800 mb-2 flex items-center gap-1.5">
                    <i class="fas fa-triangle-exclamation"></i>
                    Auto-copy blocked. Please copy manually:
                </p>
                <textarea x-ref="fallbackArea" x-text="fallbackText" rows="3"
                          class="w-full px-3 py-2 text-xs border border-amber-300 rounded-lg bg-white resize-none focus:outline-none"
                          readonly @click="$refs.fallbackArea.select()"></textarea>
                <button @click="showFallback=false" class="mt-1 text-xs text-amber-700 font-semibold hover:underline">Dismiss</button>
            </div>

            {{-- ══ SCROLLABLE BODY — two-column layout ══ --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">
                <div class="flex flex-col lg:flex-row min-h-full">

                    {{-- ── LEFT: Preview ── --}}
                    <div class="flex-1 px-6 py-5 border-b lg:border-b-0 lg:border-r border-gray-100 flex flex-col gap-4">

                        <p class="text-xs font-bold text-[#999999] uppercase tracking-widest">What recipients will see</p>

                        {{-- Preview card --}}
                        <div class="rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm">
                            <div class="border-b border-gray-200 px-4 py-3 flex items-start gap-3"
                                 style="background-color: {{ $shareIsCompleted ? '#fffbeb' : '#f9f7fc' }};">
                                <div class="w-14 h-14 rounded-lg flex items-center justify-center flex-shrink-0 shadow"
                                     style="background: {{ $shareIsCompleted ? 'linear-gradient(135deg,#f59e0b,#d97706)' : 'linear-gradient(135deg,#7a3f91,#6a3080)' }};">
                                    <i class="fas {{ $shareIsCompleted ? 'fa-trophy' : 'fa-calendar-check' }} text-white text-xl"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-[#333333] text-sm leading-tight truncate">
                                        {{ $shareIsCompleted ? '🏆 ' : '' }}{{ $shareEventTitle }}
                                    </p>
                                    <p class="text-xs text-[#666666] mt-0.5 font-semibold">{{ $shareOrganizer }}</p>
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                        @if($shareDate)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-[#333333]"><i class="fas fa-calendar text-[8px]"></i>{{ $shareDate }}</span>
                                        @endif
                                        @if($shareTime)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-[#333333]"><i class="fas fa-clock text-[8px]"></i>{{ $shareTime }}{{ $shareEndTime ? ' – '.$shareEndTime : '' }}</span>
                                        @endif
                                        @if($shareVenue)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-[#333333]"><i class="fas fa-location-dot text-[8px]"></i>{{ $shareVenue }}</span>
                                        @endif
                                        @if($shareIsCompleted)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700"><i class="fas fa-check text-[8px]"></i>Completed</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($shareDescPreview)
                            <div class="px-4 py-2.5 bg-white border-b border-gray-100">
                                <p class="text-xs text-[#666666] leading-relaxed line-clamp-3">{{ $shareDescPreview }}</p>
                            </div>
                            @endif
                            <div class="px-4 py-2 bg-[#f9f7fc] flex items-center gap-2">
                                <i class="fas fa-globe text-[#999999] text-[10px]"></i>
                                <span class="text-[10px] text-[#666666] uppercase tracking-wide font-semibold">{{ strtoupper($shareHost) }}</span>
                            </div>
                        </div>

                        {{-- Info box --}}
                        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 flex items-start gap-2.5">
                            <i class="fas fa-circle-info text-blue-500 text-xs flex-shrink-0 mt-0.5"></i>
                            <p class="text-xs text-blue-800 leading-snug">
                                <strong>How it works:</strong> Click a share button — the
                                {{ $shareIsCompleted ? 'highlights text' : 'event text' }}
                                is copied to your clipboard and the platform opens. Just paste
                                (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>) in your post or chat!
                            </p>
                        </div>
                    </div>

                    {{-- ── RIGHT: Share buttons ── --}}
                    <div class="w-full lg:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0">

                        <p class="text-xs font-bold text-[#999999] uppercase tracking-widest">Share via</p>

                        {{-- Copied banners --}}
                        <div x-show="fbCopied" x-cloak
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 -translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="bg-emerald-50 border border-emerald-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                            <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                            <p class="text-xs font-bold text-emerald-800">Text copied! Paste in Facebook popup.</p>
                        </div>

                        <div x-show="messengerCopied" x-cloak
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 -translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="bg-blue-50 border border-blue-300 rounded-xl px-3 py-2.5 flex items-start gap-2">
                            <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                            <p class="text-xs font-bold text-blue-800">Text copied! Paste in Messenger.</p>
                        </div>

                        {{-- ── Facebook ── --}}
                        <button type="button" @click="shareOnFacebook()"
                                class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                            <span class="w-9 h-9 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                                </svg>
                            </span>
                            <span class="flex-1 text-left text-sm">
                                <span x-show="!fbCopied">Share on Facebook</span>
                                <span x-show="fbCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste in popup!</span>
                            </span>
                            <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                        </button>

                        {{-- ── Messenger ── --}}
                        <button type="button" @click="shareOnMessenger()"
                                class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl bg-gradient-to-r from-[#00B2FF] to-[#006AFF] hover:from-[#00a0e6] hover:to-[#005ee6] text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                            <span class="w-9 h-9 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                                    <defs><linearGradient id="mgr_ev" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                                    <path fill="url(#mgr_ev)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                                </svg>
                            </span>
                            <span class="flex-1 text-left text-sm">
                                <span x-show="!messengerCopied">Share via Messenger</span>
                                <span x-show="messengerCopied" x-cloak><i class="fas fa-check mr-1"></i> Paste in Messenger!</span>
                            </span>
                            <i class="fas fa-arrow-up-right-from-square text-white/60 text-xs group-hover:text-white transition"></i>
                        </button>
                        <p class="text-[10px] text-[#999999] text-center -mt-1">
                            <i class="fas fa-users text-[9px] mr-0.5"></i> Works for private chats & group chats.
                        </p>

                        {{-- Divider --}}
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                            <div class="relative flex justify-center">
                                <span class="bg-white px-2 text-[10px] font-bold text-[#999999] uppercase tracking-widest">or post directly</span>
                            </div>
                        </div>

                        {{-- ── Batch Chat ── --}}
                        <button type="button"
                                wire:click="postToBatchChat"
                                wire:loading.attr="disabled"
                                class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-purple-300 bg-gradient-to-r from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200"
                                style="color:#7a3f91;">
                            <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                                  style="background:#7a3f91;">
                                <i class="fas fa-users text-white text-sm"></i>
                            </span>
                            <span class="flex-1 text-left">
                                <span wire:loading.remove wire:target="postToBatchChat">
                                    {{ $shareIsCompleted ? 'Post Highlights to Batch Chat' : 'Post to Batch Chat' }}
                                </span>
                                <span wire:loading wire:target="postToBatchChat"><i class="fas fa-spinner fa-spin mr-1"></i> Posting…</span>
                                <span class="block text-xs font-semibold mt-0.5" style="color:#7a3f91;">
                                    {{ $shareIsCompleted ? 'Sends highlights to your batchmates' : 'Sends directly to your batchmates' }}
                                </span>
                            </span>
                            <i class="fas fa-paper-plane text-xs transition" style="color:#7a3f91;"></i>
                        </button>

                        {{-- Divider + Copy Link --}}
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                            <div class="relative flex justify-center">
                                <span class="bg-white px-2 text-[10px] font-bold text-[#999999] uppercase tracking-widest">or copy link</span>
                            </div>
                        </div>

                        <button type="button" @click="copyLinkFn()"
                                class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50 text-[#333333] font-bold text-sm transition cursor-pointer group">
                            <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                                <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-[#999999]'" class="text-sm"></i>
                            </span>
                            <div class="flex-1 text-left min-w-0">
                                <p :class="copied ? 'text-emerald-600' : 'text-[#333333]'" class="font-bold text-sm"
                                   x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                                <p class="text-[10px] text-[#999999] font-mono mt-0.5 truncate">{{ $shareBaseUrl }}</p>
                            </div>
                        </button>

                        <p class="text-[10px] text-[#999999] text-center leading-snug pt-1">
                            {{ $shareIsCompleted ? 'Sharing highlights is always available for completed events.' : 'Sharing is disabled once an event ends.' }}
                        </p>

                    </div>{{-- end RIGHT col --}}
                </div>{{-- end two-col --}}
            </div>{{-- end scrollable body --}}

        </div>{{-- end modal container --}}
    </div>{{-- end overlay --}}
    @endif
    {{-- END SHARE MODAL --}}
