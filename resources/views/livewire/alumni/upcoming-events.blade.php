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

    public function updatingSearch():       void { $this->page = 1; }
    public function updatingFilterStatus(): void { $this->page = 1; }

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

<div class="flex flex-col" style="height: calc(100vh - 120px); overflow: hidden;">

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
.ev-hover-tip {
    position: fixed;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 99999;
    transform: translate(14px, 14px);
}
.ev-hover-tip.visible { opacity: 1; }
@keyframes detailIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }
@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }
.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.pre-wrap { white-space: pre-wrap; }
html, body { overflow: hidden !important; height: 100% !important; }
</style>

<div id="ev-hover-tip"
     class="ev-hover-tip bg-gray-900 text-white text-[11px] font-semibold tracking-wide px-3 py-1.5 rounded-lg shadow-lg whitespace-nowrap">
    <i class="fas fa-eye mr-1" style="font-size:.75rem;"></i>View Details
</div>

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

{{-- MAIN LAYOUT --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0 overflow-hidden">

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
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-800 border border-gray-200">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-gray-200 bg-gray-50 text-gray-700 uppercase tracking-wide">
                <i class="fas fa-calendar-check text-gray-500 text-[10px]"></i>
                {{ $this->events->count() }} Event{{ $this->events->count() !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    {{-- CONTENT BLOCK --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-gray-200 shadow-sm">

        {{-- FILTER BAR --}}
        <div class="bg-gray-100 border-b border-gray-200 px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or venue…"
                       class="filter-input w-full pl-8 pr-3 py-[7px] text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                              hover:border-gray-300 focus:outline-none focus:border-gray-400 focus:ring-2 focus:ring-gray-200 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-gray-400 focus:ring-2 focus:ring-gray-200 transition cursor-pointer">
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
                    <svg class="animate-spin w-3.5 h-3.5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- CARDS BODY --}}
        <div class="bg-gray-100 p-4 relative flex-1 min-h-0 overflow-y-auto scroll-thin">

            <div wire:loading
                 wire:target="search,filterStatus,resetFilters,nextPage,previousPage"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none bg-gray-100/70">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow border border-gray-200">
                    <svg class="animate-spin w-4 h-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold text-gray-700">Loading events…</span>
                </div>
            </div>

            @if($this->pagedEvents->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->pagedEvents as $event)
                @php
                    $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                   (!$event->event_end_date && $event->event_date <= now('UTC'));
                    $eventDate    = $event->event_date->setTimezone('Asia/Manila');
                    $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
                    $postedAgo    = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    $timeDisplay  = $eventDate->format('g:i A') . ($eventEndDate ? ' – ' . $eventEndDate->format('g:i A') : '');
                    $hasPhoto     = !empty($event->photo_url);
                @endphp

                <div class="bg-white border border-gray-200 rounded-xl shadow-sm hover:border-gray-400 hover:shadow-md
                            transition-all duration-150 overflow-hidden cursor-pointer relative select-none flex flex-col"
                     data-ev-card
                     wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    @if($hasPhoto)
                    <div class="relative w-full flex-shrink-0" style="height:130px;">
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="w-full h-full object-cover">
                        <div class="absolute inset-x-0 bottom-0 h-10 pointer-events-none"
                             style="background:linear-gradient(to top,rgba(0,0,0,.45),transparent);"></div>
                        <div class="absolute top-2.5 right-2.5">
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
                    </div>
                    @else
                    <div class="relative w-full flex items-center justify-center flex-shrink-0"
                         style="height:72px; background:linear-gradient(135deg,#7a3f91 0%,#4a1f6a 100%);">
                        <i class="fas fa-calendar-days text-white/20 text-3xl"></i>
                        <div class="absolute top-2.5 right-2.5">
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
                    </div>
                    @endif

                    <div class="flex flex-col flex-1 p-4 gap-2">
                        <h3 class="font-semibold text-base leading-snug line-clamp-2 text-gray-900">{{ $event->title }}</h3>

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
                        <p class="text-sm text-gray-400 italic">No target specified</p>
                        @endif

                        @if($event->description)
                        <p class="text-sm line-clamp-2 leading-relaxed text-gray-700">
                            {{ Str::limit(strip_tags($event->description), 80) }}
                        </p>
                        @endif

                        <div class="flex items-center justify-between pt-2 border-t border-gray-100 mt-auto">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-xs text-gray-500">{{ $postedAgo }}</span>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                    <i class="fas fa-circle-check text-[9px]"></i>
                                    {{ $event->confirmed_count }} Attending
                                </span>
                            </div>
                            <button type="button"
                                    data-ev-share
                                    wire:click.stop="openShareModal({{ $event->id }}, '{{ $event->event_source }}')"
                                    class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg z-[2] flex-shrink-0 group transition
                                           {{ $isCompleted
                                               ? 'border border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100'
                                               : 'border border-gray-200 bg-gray-50 text-gray-600 hover:border-gray-300 hover:bg-gray-100' }}">
                                <span class="absolute bottom-[calc(100%+6px)] right-0 bg-gray-900 text-white text-[10px] font-bold
                                             px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100
                                             transition shadow-md after:content-[''] after:absolute after:top-full after:right-2
                                             after:border-4 after:border-transparent after:border-t-gray-900">
                                    {{ $isCompleted ? 'Highlights' : 'Share' }}
                                </span>
                                <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-[11px]"></i>
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
                        @else No events found
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-gray-500">
                        @if($search || $filterStatus !== '') Try clearing your filters to see all available events.
                        @else Check back soon — new events will appear here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus !== '')
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                </button>
                @endif
            </div>
            @endif
        </div>

        {{-- PAGINATION BAR --}}
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
                               bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition"
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
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
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
                               bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition"
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
</div>{{-- end main layout --}}


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW EVENT — FULL SCREEN, NO SCROLL, TABLE LAYOUT
══════════════════════════════════════════════════════════════════════════ --}}
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
    $rsvpColor    = 'text-gray-400';
    if ($alumniRsvp) {
        $rsvpLabel = $alumniRsvp->response;
        $rsvpColor = match($alumniRsvp->response) {
            'CONFIRMED' => 'text-emerald-700', 'DECLINED' => 'text-red-600', 'TENTATIVE' => 'text-amber-600', default => 'text-gray-500'
        };
    }
@endphp

<div class="detail-page fixed inset-0 z-[9000] flex flex-col bg-white overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Purple top bar --}}
    <div class="flex items-center justify-between px-6 h-[54px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">
        <span class="text-base text-white font-semibold truncate flex-1 min-w-0">{{ $event->title }}</span>
        <div class="flex items-center gap-1.5 flex-shrink-0">
            <button type="button"
                    wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                    class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                           bg-white/12 border border-white/22 text-white hover:bg-white/24 transition cursor-pointer">
                <span class="absolute top-[calc(100%+6px)] right-0 bg-gray-900 text-white text-[10px] font-bold uppercase
                             tracking-wide px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0
                             group-hover:opacity-100 transition shadow-md z-[200]
                             before:content-[''] before:absolute before:bottom-full before:right-2.5
                             before:border-4 before:border-transparent before:border-b-gray-900">
                    {{ $isCompleted ? 'Highlights' : 'Share' }}
                </span>
                <i class="fas {{ $isCompleted ? 'fa-trophy' : 'fa-share-nodes' }} text-[14px]"></i>
            </button>
            @if(!$isCompleted)
            <button type="button" wire:click="openRsvpModal"
                    class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                           bg-white/12 border border-white/22 text-white hover:bg-white/24 transition cursor-pointer">
                <span class="absolute top-[calc(100%+6px)] right-0 bg-gray-900 text-white text-[10px] font-bold uppercase
                             tracking-wide px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0
                             group-hover:opacity-100 transition shadow-md z-[200]
                             before:content-[''] before:absolute before:bottom-full before:right-2.5
                             before:border-4 before:border-transparent before:border-b-gray-900">
                    {{ $alumniRsvp ? 'Update RSVP' : 'RSVP' }}
                </span>
                <i class="fas fa-calendar-plus text-[14px]"></i>
            </button>
            @endif
            <button type="button" wire:click="closeViewModal"
                    class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                           bg-white/12 border border-white/22 text-white hover:bg-white/24 transition cursor-pointer">
                <span class="absolute top-[calc(100%+6px)] right-0 bg-gray-900 text-white text-[10px] font-bold uppercase
                             tracking-wide px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0
                             group-hover:opacity-100 transition shadow-md z-[200]
                             before:content-[''] before:absolute before:bottom-full before:right-2.5
                             before:border-4 before:border-transparent before:border-b-gray-900">
                    Close
                </span>
                <i class="fas fa-xmark text-[15px]"></i>
            </button>
        </div>
    </div>

    {{-- Title + status badges row --}}
    <div class="bg-white border-b border-gray-200 px-6 py-3 flex-shrink-0">
        <h2 class="text-2xl font-bold text-gray-900 leading-tight mb-2">{{ $event->title }}</h2>
        <div class="flex flex-wrap gap-2">
            @if($isCompleted)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg text-xs font-semibold bg-green-50 border border-green-200 text-green-700">
                    <i class="fas fa-circle-check text-[9px]"></i> Completed
                </span>
            @else
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg text-xs font-semibold bg-emerald-50 border border-emerald-200 text-emerald-700">
                    <i class="fas fa-calendar-check text-[9px]"></i> Upcoming
                </span>
            @endif
            @if($event->target_participants)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg text-xs font-semibold bg-gray-100 border border-gray-200 text-gray-700">
                    <i class="fas fa-users text-[9px]"></i> {{ Str::limit($event->target_participants, 50) }}
                </span>
            @endif
        </div>
    </div>

    {{-- BODY: two-column grid, NO scroll --}}
    <div class="flex-1 min-h-0 overflow-hidden flex flex-col">

        {{-- Photo banner --}}
        @if($hasPhoto)
        <div class="flex-shrink-0 border-b border-gray-200 bg-gray-50" style="height:170px;">
            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                 class="w-full h-full object-cover" style="display:block;">
        </div>
        @endif

        {{-- Two-column info grid --}}
        <div class="flex-1 min-h-0 overflow-hidden grid grid-cols-2 divide-x divide-gray-100">

            {{-- LEFT: event details --}}
            <div class="flex flex-col divide-y divide-gray-100 overflow-hidden">

                {{-- Date & Time --}}
                <div class="flex items-start gap-3 px-5 py-3 flex-shrink-0">
                    <i class="fas fa-calendar text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-0.5">Date & Time</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $eventDate->format('F d, Y') }}</p>
                        <p class="text-sm text-gray-800">{{ $timeDisplay }}</p>
                    </div>
                </div>

                {{-- Venue --}}
                <div class="flex items-start gap-3 px-5 py-3 flex-shrink-0">
                    <i class="fas fa-location-dot text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-0.5">Venue</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $event->venue ?: '—' }}</p>
                        @if($event->venue_address)
                            <p class="text-xs text-gray-600 mt-0.5">{{ $event->venue_address }}</p>
                        @endif
                    </div>
                </div>

                {{-- Open For --}}
                @if($event->target_participants)
                <div class="flex items-start gap-3 px-5 py-3 flex-shrink-0">
                    <i class="fas fa-users text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-0.5">Open For</p>
                        <p class="text-sm text-gray-900">{{ $event->target_participants }}</p>
                    </div>
                </div>
                @endif

                {{-- Responses + RSVP --}}
                <div class="flex divide-x divide-gray-100 flex-shrink-0">
                    <div class="flex-1 flex items-start gap-3 px-5 py-3">
                        <i class="fas fa-chart-bar text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-1">Responses</p>
                            <div class="flex flex-wrap gap-x-3 gap-y-0.5">
                                <span class="text-xs font-bold text-emerald-600"><i class="fas fa-check text-[8px] mr-0.5"></i>{{ $event->confirmed_count }} Yes</span>
                                <span class="text-xs font-bold text-amber-600">{{ $event->tentative_count }} Maybe</span>
                                <span class="text-xs font-bold text-red-500">{{ $event->declined_count }} No</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 flex items-start gap-3 px-5 py-3">
                        <i class="fas fa-clipboard-check text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-0.5">Your RSVP</p>
                            <p class="text-sm font-bold {{ $rsvpColor }}">{{ $rsvpLabel }}</p>
                            @if(!$isCompleted)
                                <button wire:click="openRsvpModal"
                                        class="text-xs text-gray-500 font-semibold hover:text-gray-800 hover:underline cursor-pointer">
                                    {{ $alumniRsvp ? 'Change →' : 'RSVP now →' }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Posted --}}
                <div class="flex items-start gap-3 px-5 py-3 flex-shrink-0">
                    <i class="fas fa-clock text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-0.5">Posted</p>
                        <p class="text-sm text-gray-900">{{ $createdPH->format('M d, Y \a\t g:i A') }}</p>
                        <p class="text-xs text-gray-400">{{ $createdPH->diffForHumans() }}</p>
                    </div>
                </div>

                {{-- Contact --}}
                @if($event->contact_person || $event->contact_email || $event->contact_phone)
                <div class="flex items-start gap-3 px-5 py-3 flex-shrink-0">
                    <i class="fas fa-address-card text-gray-400 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-1">Contact</p>
                        @if($event->contact_person)
                            <p class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                                <i class="fas fa-user text-gray-400 text-[10px]"></i>{{ $event->contact_person }}
                            </p>
                        @endif
                        @if($event->contact_email)
                            <p class="text-sm text-gray-800 flex items-center gap-1.5 mt-0.5">
                                <i class="fas fa-envelope text-gray-400 text-[10px]"></i>{{ $event->contact_email }}
                            </p>
                        @endif
                        @if($event->contact_phone)
                            <p class="text-sm text-gray-800 flex items-center gap-1.5 mt-0.5">
                                <i class="fas fa-phone text-gray-400 text-[10px]"></i>{{ $event->contact_phone }}
                            </p>
                        @endif
                    </div>
                </div>
                @endif

            </div>

            {{-- RIGHT: Description + Notes --}}
            <div class="flex flex-col divide-y divide-gray-100 overflow-hidden">

                @if($event->description)
                <div class="flex flex-col flex-1 min-h-0 px-5 py-3 overflow-hidden">
                    <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-2 flex-shrink-0">About This Event</p>
                    <p class="text-sm text-gray-900 leading-relaxed overflow-hidden"
                       style="display:-webkit-box;-webkit-line-clamp:10;-webkit-box-orient:vertical;">{{ trim($event->description) }}</p>
                </div>
                @endif

                @if($event->notes)
                <div class="flex-shrink-0 px-5 py-3 bg-amber-50/40">
                    <p class="text-[10px] font-bold uppercase tracking-[.1em] text-gray-400 mb-2">Additional Notes</p>
                    <p class="text-sm text-gray-900 leading-relaxed overflow-hidden"
                       style="display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;">{{ trim($event->notes) }}</p>
                </div>
                @endif

                @if(!$event->description && !$event->notes)
                <div class="flex-1 flex flex-col items-center justify-center gap-3 text-center px-6 py-8">
                    <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center">
                        <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                    </div>
                    <p class="text-sm font-medium text-gray-500">No additional details provided.</p>
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
     @keydown.escape.window="$wire.closeRsvpModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden relative"
         style="animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both;">
        <button wire:click="closeRsvpModal" type="button"
                class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-white/20 hover:bg-white/30 transition text-white z-10 cursor-pointer">
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
                <label class="block text-xs font-semibold uppercase tracking-widest mb-2 text-gray-500">
                    Message <span class="font-normal normal-case text-gray-400">— optional</span>
                </label>
                <textarea wire:model="rsvpMessage" rows="2"
                          placeholder="Add a personal note or question…"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-gray-400 focus:ring-2 focus:ring-gray-100 transition resize-none text-gray-900"
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
     class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm"
     x-data="{
         copied: false, fbCopied: false, messengerCopied: false,
         showFallback: false, fallbackText: '',
         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         fbUrl:    {{ json_encode($fbShareUrl) }},
         photoUrl: {{ json_encode($sharePhotoUrl) }},
         hasPhoto: {{ $hasRealPhoto ? 'true' : 'false' }},
         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); }
                 else {
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
             await this.copyWithImage(this.fbText, this.photoUrl); this.fbCopied = true;
             setTimeout(() => {
                 const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
                 const popup = window.open(this.fbUrl,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
                 if(!popup||popup.closed) window.open(this.fbUrl,'_blank');
             }, 150);
             setTimeout(() => this.fbCopied = false, 7000);
         },
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl); this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) { window.location.href='fb-messenger://share/?link='+encodeURIComponent(this.baseUrl); setTimeout(()=>window.open('https://www.messenger.com/','_blank'),1500); }
             else { window.open('https://www.messenger.com/','_blank'); }
             setTimeout(() => this.messengerCopied = false, 7000);
         },
         async copyLinkFn() {
             const ok = await this.copyPlainText(this.baseUrl);
             if(ok) { this.copied=true; setTimeout(()=>this.copied=false,2500); }
         }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="share-sheet bg-white rounded-2xl w-full max-w-[900px] max-h-[90vh] flex flex-col overflow-hidden shadow-2xl">

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2.5 text-gray-800">
                @if($isCompleted)
                    <i class="fas fa-trophy text-amber-500 text-xs"></i> Share Event Highlights
                @else
                    <i class="fas fa-share-nodes text-gray-600 text-xs"></i> Share Event
                @endif
            </h2>
            <button wire:click="closeShareModal" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-gray-500">
                <i class="fas fa-xmark text-sm"></i>
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

            {{-- LEFT: Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto scroll-thin">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-500 flex-shrink-0">Post Preview</p>
                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0">
                    @if($sharePhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3">
                        <img src="{{ $sharePhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px;display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-100 px-5 py-4 {{ $isCompleted ? 'bg-amber-50/50' : 'bg-gray-50' }}">
                        <p class="font-semibold text-sm text-gray-900">{{ $shareEventTitle }}</p>
                        <p class="text-xs mt-0.5 font-medium text-gray-500">{{ $shareOrganizer }}</p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareDate)        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700">{{ $shareDate }}@if($shTimeStr) · {{ $shTimeStr }}@endif</span> @endif
                            @if($shareVenue)       <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700">{{ $shareVenue }}</span> @endif
                            @if($shareTargetParts) <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700">{{ Str::limit($shareTargetParts, 30) }}</span> @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-xs leading-relaxed text-gray-600">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 bg-gray-50">
                        <span class="text-xs uppercase tracking-wider font-semibold text-gray-500">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the
                        {{ $isCompleted ? 'highlights' : 'event' }} text and opens the platform.
                        Press <kbd class="bg-blue-100 px-1 rounded font-mono">Ctrl+V</kbd> to paste.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-72 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto scroll-thin">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Share via</p>

                <div x-show="fbCopied" x-cloak x-transition
                     class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-emerald-800">Opened! Press Ctrl+V to paste.</p>
                </div>
                <div x-show="messengerCopied" x-cloak x-transition
                     class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-xs mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs font-semibold text-blue-800">Opened! Press Ctrl+V to paste.</p>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm transition cursor-pointer">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share on Facebook</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Caption copied automatically</p>
                    </div>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-white font-semibold text-sm transition cursor-pointer"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4">
                            <defs><linearGradient id="mgr_ev" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_ev)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Send via Messenger</p>
                        <p class="text-[10px] text-white/60 mt-0.5">Caption copied automatically</p>
                    </div>
                </button>

                <div class="relative my-1">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white text-gray-400">or post directly</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer
                               border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <div class="text-left flex-1">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block text-xs font-semibold">
                            {{ $isCompleted ? 'Post Highlights to Batch Chat' : 'Post to Batch Chat' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block text-xs font-semibold">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-[10px] mt-0.5" style="color:#7a3f91;">
                            {{ $isCompleted ? 'Sends highlights to your batchmates' : 'Sends directly to your batch chat room' }}
                        </span>
                    </div>
                </button>

                <div class="relative my-1">
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
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : 'text-gray-700'"
                           x-text="copied ? 'Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-[10px] font-mono text-gray-400 truncate">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" wire:click="closeShareModal"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition cursor-pointer mt-1">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- end root --}}

<script>
(function () {
    var tip = document.getElementById('ev-hover-tip');
    function bindCards() {
        document.querySelectorAll('[data-ev-card]').forEach(function (card) {
            if (card._evTipBound) return;
            card._evTipBound = true;
            card.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var shareBtn = card.querySelector('[data-ev-share]');
                if (shareBtn && (e.target === shareBtn || shareBtn.contains(e.target))) {
                    tip.classList.remove('visible'); return;
                }
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.classList.add('visible');
            });
            card.addEventListener('mouseleave', function () { if (tip) tip.classList.remove('visible'); });
            card.addEventListener('click',      function () { if (tip) tip.classList.remove('visible'); });
        });
    }
    bindCards();
    document.addEventListener('livewire:updated', bindCards);
})();
</script>