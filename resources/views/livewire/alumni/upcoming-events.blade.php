{{-- resources/views/livewire/alumni/upcoming-events.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\AdminEvent;
use App\Models\OrganizerEvent;
use App\Models\EventRsvp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterSort   = 'recent';
    public string $filterStatus = 'upcoming';

    public bool   $showViewModal  = false;
    public ?int   $viewingEventId = null;
    public ?string $viewingEventType = null;

    public bool   $showRsvpModal      = false;
    public ?string $rsvpResponse       = null;
    public string $rsvpMessage         = '';

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

    public function mount(): void
    {
        set_time_limit(600);

        $user = Auth::user();
        if (!$user || !$user->alumni) {
            abort(403, 'Access denied.');
        }

        $alumni = $user->alumni;
        $this->alumniCourses = $alumni->course ? [$alumni->course->code] : [];
        $this->alumniCollege = $alumni->course?->college ?? '';
    }

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterSort(): void   { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterStatus = 'upcoming';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    #[Computed]
    public function events()
    {
        $college = $this->alumniCollege;
        $courses = $this->alumniCourses;

        if (!$college || empty($courses)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 12, 1);
        }

        $now = \Carbon\Carbon::now('UTC');

        // Admin events
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
                \Illuminate\Support\Facades\DB::raw("'ADMIN' as event_source"),
            ])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

        // Organizer events
        $organizerQ = OrganizerEvent::where('status', 'APPROVED')
            ->where(function ($q) use ($college, $courses) {
                $q->where('target_participants', 'like', 'All Courses%')
                  ->orWhere(function ($sub) use ($college, $courses) {
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
                \Illuminate\Support\Facades\DB::raw("'ORGANIZER' as event_source"),
            ])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

        // Filter by status
        if ($this->filterStatus === 'upcoming') {
            $adminQ->where(function ($q) use ($now) {
                $q->where('event_date', '>', $now)
                  ->orWhere(function ($sub) use ($now) {
                      $sub->whereNotNull('event_end_date')
                          ->where('event_end_date', '>', $now);
                  });
            });
            $organizerQ->where(function ($q) use ($now) {
                $q->where('event_date', '>', $now)
                  ->orWhere(function ($sub) use ($now) {
                      $sub->whereNotNull('event_end_date')
                          ->where('event_end_date', '>', $now);
                  });
            });
        } elseif ($this->filterStatus === 'completed') {
            $adminQ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            });
            $organizerQ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            });
        }

        // Search
        if ($this->search !== '') {
            $s = trim($this->search);
            $adminQ->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
            );
            $organizerQ->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
            );
        }

        // Combine and sort
        $adminEvents = $adminQ->get();
        $organizerEvents = $organizerQ->get();
        $allEvents = $adminEvents->concat($organizerEvents);

        $sorted = $allEvents->sortBy(function ($event) {
            return $this->filterSort === 'oldest'
                ? $event->created_at
                : $event->created_at->timestamp * -1;
        }, SORT_NUMERIC)->values();

        // Manual pagination
        $page = $this->getPage();
        $perPage = 12;
        $total = $sorted->count();
        $items = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    public function getPage()
    {
        $page = request('page', 1);
        return max(1, intval($page));
    }

    #[Computed]
    public function viewingEvent()
    {
        if (!$this->viewingEventId || !$this->viewingEventType) return null;

        if ($this->viewingEventType === 'ADMIN') {
            return AdminEvent::withoutTrashed()
                ->where('id', $this->viewingEventId)
                ->where('status', 'APPROVED')
                ->withCount([
                    'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                    'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                    'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
                ])->first();
        } else {
            return OrganizerEvent::where('id', $this->viewingEventId)
                ->where('status', 'APPROVED')
                ->withCount([
                    'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                    'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                    'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
                ])->first();
        }
    }

    #[Computed]
    public function alumniRsvp()
    {
        if (!$this->viewingEventId) return null;

        $alumni = Auth::user()?->alumni;
        if (!$alumni) return null;

        return EventRsvp::where('event_id', $this->viewingEventId)
            ->where('alumni_id', $alumni->id)
            ->first();
    }

    public function viewEvent(int $id, string $type): void
    {
        $this->viewingEventId = $id;
        $this->viewingEventType = $type;
        $this->showViewModal = true;
        $this->resetRsvpModal();
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingEventId = null;
        $this->viewingEventType = null;
        $this->resetRsvpModal();
    }

    public function openRsvpModal(): void
    {
        $this->showRsvpModal = true;
    }

    public function closeRsvpModal(): void
    {
        $this->showRsvpModal = false;
        $this->resetRsvpModal();
    }

    private function resetRsvpModal(): void
    {
        $this->rsvpResponse = null;
        $this->rsvpMessage = '';
    }

    public function submitRsvp(string $response): void
    {
        $user = Auth::user();
        $alumni = $user?->alumni;

        if (!$alumni || !$this->viewingEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Something went wrong. Please try again.');
            return;
        }

        try {
            EventRsvp::updateOrCreate(
                [
                    'event_id' => $this->viewingEventId,
                    'alumni_id' => $alumni->id,
                ],
                [
                    'response' => $response,
                    'message' => trim($this->rsvpMessage) ?: null,
                ]
            );

            $this->dispatch('flash-message',
                type: 'success',
                message: "Your RSVP has been recorded as $response!"
            );

            $this->closeRsvpModal();
            $this->closeViewModal();
        } catch (\Exception $e) {
            $this->dispatch('flash-message',
                type: 'error',
                message: 'Failed to save RSVP. Please try again.'
            );
        }
    }

    // ── Share ─────────────────────────────────────────────────────────────────

    public function openShareModal(int $id, string $type): void
    {
        if ($type === 'ADMIN') {
            $event = AdminEvent::withoutTrashed()->where('id', $id)->where('status', 'APPROVED')->first();
        } else {
            $event = OrganizerEvent::where('id', $id)->where('status', 'APPROVED')->first();
        }

        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                       (!$event->event_end_date && $event->event_date <= now('UTC'));

        if ($isCompleted) {
            $this->dispatch('flash-message', type: 'warning', message: 'This event can no longer be shared — it has already ended.');
            return;
        }

        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');

        $this->shareEventId     = $id;
        $this->shareEventType   = $type;
        $this->shareEventTitle  = $event->title;
        $this->shareVenue       = $event->venue ?? '';
        $this->shareDate        = $eventDatePH->format('F d, Y');
        $this->shareTime        = $eventDatePH->format('g:i A');
        $this->shareEndTime     = $eventEndPH ? $eventEndPH->format('g:i A') : '';
        $this->shareDescription = $event->description ?? '';
        $this->shareTargetParts = $event->target_participants ?? '';
        $this->sharePhotoUrl    = $event->photo_url ?? '';

        if ($type === 'ADMIN') {
            $this->shareOrganizer = 'PHILCST Admin';
        } else {
            $this->shareOrganizer = $event->organizer?->name ?? 'Organizer';
        }

        $this->showShareModal = true;
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
    }

    public function eventsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try {
            $path = route('events.index', [], false);
        } catch (\Throwable) {
            $path = '/events';
        }
        return $base . $path;
    }

};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

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

<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }

.event-card { transition: box-shadow .18s, transform .18s; cursor: pointer; }
.event-card:hover {
    box-shadow: 0 8px 28px rgba(122,63,145,.18), 0 2px 8px rgba(0,0,0,.07);
    transform: translateY(-3px);
}
.event-card:hover .card-view-hint {
    background-color: #6a3080 !important;
}

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.filter-input {
    border: 1.5px solid #d1d5db;
    transition: border-color .15s, box-shadow .15s;
}
.filter-input:hover  { border-color: #7a3f91; }
.filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
</style>

    <div class="space-y-5 flex-1 flex flex-col">

        {{-- ══ PAGE HEADER ═══════════════════════════════════════════════════ --}}
        <div class="rounded-2xl overflow-hidden shadow-sm" style="background-color:#7a3f91;">
            <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-calendar-check text-white text-sm"></i>
                        </div>
                        Upcoming Events
                    </h1>
                    <p class="text-sm text-white/75 mt-1 ml-11">
                        Discover and RSVP to events for
                        <span class="font-semibold text-white">{{ $alumniCollege ?: 'your college' }}</span>
                    </p>
                </div>
                <div class="ml-11 sm:ml-0">
                    <span class="inline-flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl
                                 bg-white/20 text-white border border-white/30">
                        <i class="fa-solid fa-circle-check text-emerald-300"></i>
                        {{ $this->events->total() }} event{{ $this->events->total() !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- ══ FILTER BAR ════════════════════════════════════════════════════ --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-4 py-3 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[180px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.350ms="$wire.set('search', q)"
                       placeholder="Search title, venue…"
                       class="filter-input w-full pl-8 pr-3 py-2 rounded-xl text-sm text-gray-900 bg-white"
                       autocomplete="off" maxlength="100">
            </div>

            <select wire:model.live="filterStatus"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Events</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>

            <select wire:model.live="filterSort"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="filter-input px-3 py-2 rounded-xl bg-white text-sm font-medium text-gray-600
                           hover:bg-gray-50 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ══ CONTENT AREA ══════════════════════════════════════════════════ --}}
        <div class="flex flex-col flex-1"
             wire:loading.class="opacity-50 pointer-events-none"
             wire:target="search,filterStatus,filterSort,resetFilters,previousPage,nextPage">

            @if($this->events->count() > 0)

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 flex-1">
                    @foreach($this->events as $event)
                    @php
                        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                                       (!$event->event_end_date && $event->event_date <= now('UTC'));
                        $eventDate = $event->event_date->setTimezone('Asia/Manila');
                        $postedAgo = \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans();
                    @endphp

                    <div class="event-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col">

                        {{-- Event Image —— clicking the image area opens the view modal --}}
                        <div class="w-full h-40 bg-gradient-to-br from-purple-200 to-purple-100 relative overflow-hidden flex-shrink-0"
                             wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')">
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="w-full h-full object-cover">
                        </div>

                        <div class="p-4 flex flex-col flex-1 gap-3">

                            {{-- Date & Status --}}
                            <div class="flex items-start justify-between gap-2"
                                 wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-gray-500 truncate">{{ $eventDate->format('M d, Y') }}</p>
                                    <h3 class="text-sm font-extrabold text-gray-900 leading-snug mt-0.5 line-clamp-2">
                                        {{ $event->title }}
                                    </h3>
                                </div>
                                @if($isCompleted)
                                    <span class="inline-flex shrink-0 items-center text-[10px] font-bold px-2 py-0.5
                                                 rounded-full border border-green-200 bg-green-100 text-green-700 mt-0.5">
                                        <i class="fa-solid fa-check mr-1"></i> Done
                                    </span>
                                @else
                                    <span class="inline-flex shrink-0 items-center text-[10px] font-bold px-2 py-0.5
                                                 rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 mt-0.5">
                                        <i class="fa-solid fa-calendar-check mr-1"></i> Soon
                                    </span>
                                @endif
                            </div>

                            {{-- Venue --}}
                            @if($event->venue)
                            <div class="flex items-center gap-1.5 text-xs text-gray-500"
                                 wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')">
                                <i class="fa-solid fa-location-dot text-gray-400 text-[10px]"></i>
                                <span class="truncate">{{ $event->venue }}</span>
                            </div>
                            @endif

                            {{-- Organizer/Source --}}
                            <div class="flex items-center gap-1.5 text-xs text-gray-500"
                                 wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')">
                                <i class="fa-solid fa-{{ $event->event_source === 'ADMIN' ? 'shield-halved' : 'user-tie' }} text-gray-400 text-[10px]"></i>
                                <span class="truncate">
                                    @if($event->event_source === 'ADMIN')
                                        Posted by Admin
                                    @else
                                        {{ $event->organizer?->name ?? 'Organizer' }}
                                    @endif
                                </span>
                            </div>

                            <div class="flex-1"></div>

                            {{-- Card Footer --}}
                            <div class="flex items-center justify-between pt-2 border-t border-gray-100">

                                {{-- RSVPs (left side) --}}
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center gap-0.5 text-[11px] font-bold text-emerald-600">
                                        <i class="fa-solid fa-circle-check text-[8px]"></i>
                                        {{ $event->confirmed_count }}
                                    </span>
                                    <span class="text-gray-200">|</span>
                                    <span class="text-[10px] text-gray-400">{{ $event->confirmed_count + $event->declined_count + $event->tentative_count }} total</span>
                                </div>

                                {{-- Action buttons (right side) --}}
                                <div class="flex items-center gap-1.5">
                                    @if(!$isCompleted)
                                    <button type="button"
                                            wire:click.stop="openShareModal({{ $event->id }}, '{{ $event->event_source }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-sky-100 text-sky-700 border border-sky-300 hover:bg-white hover:border-sky-500 transition cursor-pointer">
                                        <i class="fas fa-share-nodes text-[10px]"></i>
                                        <span class="hidden sm:inline">Share</span>
                                    </button>
                                    @endif
                                    <button type="button"
                                            wire:click.stop="viewEvent({{ $event->id }}, '{{ $event->event_source }}')"
                                            class="card-view-hint inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg text-white cursor-pointer"
                                            style="background-color:#7a3f91;">
                                        <i class="fa-solid fa-eye text-[10px]"></i> View
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- ══ PAGINATION ══════════════════════════════════════════════ --}}
                <div class="mt-4 px-4 sm:px-5 py-3 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                     style="background-color:#2b0d3e;">
                    @php
                        $total = $this->events->total();
                        $pp    = $this->events->perPage();
                        $cp    = $this->events->currentPage();
                        $from  = $total > 0 ? ($cp-1)*$pp+1 : 0;
                        $to    = min($cp*$pp, $total);
                    @endphp
                    <p class="text-white text-xs sm:text-sm">
                        Showing <span class="font-bold">{{ $from }}–{{ $to }}</span>
                        of <span class="font-bold">{{ $total }}</span> event{{ $total !== 1 ? 's' : '' }}
                    </p>
                    <div class="flex items-center gap-1.5">
                        @if($this->events->onFirstPage())
                            <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage" class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white"
                                    style="background-color:#7a3f91;">← Prev</button>
                        @endif
                        <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold">
                            {{ $cp }} / {{ $this->events->lastPage() }}
                        </span>
                        @if($this->events->hasMorePages())
                            <button wire:click="nextPage" class="px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm font-semibold text-white"
                                    style="background-color:#7a3f91;">Next →</button>
                        @else
                            <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>

            @else
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm py-20 flex flex-col
                            items-center gap-4 text-center px-6 flex-1">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fa-solid fa-calendar-days text-2xl text-gray-400"></i>
                    </div>
                    <div>
                        <p class="font-bold text-gray-700 text-base">
                            @if($search || $filterStatus)
                                No events match your filters
                            @else
                                No events found
                            @endif
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            @if($search || $filterStatus)
                                Try clearing your filters to see all available events.
                            @else
                                Check back soon — new events will be posted here for
                                <strong>{{ $alumniCollege ?: 'your college' }}</strong>.
                            @endif
                        </p>
                    </div>
                    @if($search || $filterStatus)
                        <button wire:click="resetFilters"
                                class="px-4 py-2 rounded-xl text-sm font-bold text-white transition"
                                style="background-color:#7a3f91;">
                            <i class="fa-solid fa-rotate-left mr-1.5"></i> Clear Filters
                        </button>
                    @endif
                </div>
            @endif
        </div>

    </div>


    {{-- ══ VIEW DETAILS MODAL ════════════════════════════════════════════════ --}}
    @if($showViewModal && $this->viewingEvent)
    @php
        $event = $this->viewingEvent;
        $eventDate = $event->event_date->setTimezone('Asia/Manila');
        $eventEndDate = $event->event_end_date?->setTimezone('Asia/Manila');
        $isCompleted = ($event->event_end_date && $event->event_end_date <= now('UTC')) ||
                       (!$event->event_end_date && $event->event_date <= now('UTC'));
        $totalRsvp = $event->confirmed_count + $event->declined_count + $event->tentative_count;
        $alumniRsvp = $this->alumniRsvp;
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         wire:keydown.escape.window="closeViewModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative">

            <button wire:click="closeViewModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full
                           bg-white/25 hover:bg-white/40 transition text-white z-10">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- Event Image --}}
            <div class="relative h-40 sm:h-56 flex-shrink-0 overflow-hidden"
                 style="background: linear-gradient(135deg, #7a3f91 0%, #5e2f72 100%);">
                <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="w-full h-full object-cover">
            </div>

            {{-- Event Title & Meta --}}
            <div class="px-6 py-5 border-b border-gray-100 flex-shrink-0"
                 style="background: linear-gradient(to bottom, #7a3f91 0%, #6a3080 100%);">

                <div class="flex items-start justify-between gap-3 mb-3">
                    <h2 class="text-2xl font-extrabold text-white leading-snug">{{ $event->title }}</h2>
                    @if($isCompleted)
                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-lg bg-green-500/90 text-white">
                            <i class="fa-solid fa-check text-[9px]"></i> Completed
                        </span>
                    @else
                        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-lg bg-emerald-500/90 text-white">
                            <i class="fa-solid fa-calendar-check text-[9px]"></i> Upcoming
                        </span>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-calendar text-[9px]"></i> {{ $eventDate->format('M d, Y') }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-clock text-[9px]"></i> {{ $eventDate->format('g:i A') }}@if($eventEndDate)<span class="mx-1">–</span>{{ $eventEndDate->format('g:i A') }}@endif
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/20 text-white">
                        <i class="fa-solid fa-map-pin text-[9px]"></i> {{ $event->venue ?? 'TBD' }}
                    </span>
                </div>
            </div>

            {{-- Modal Body --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">

                {{-- Your RSVP Status --}}
                @if($alumniRsvp)
                @php
                    $rsvpColor = match($alumniRsvp->response) {
                        'CONFIRMED' => 'emerald',
                        'DECLINED' => 'red',
                        'TENTATIVE' => 'amber',
                        default => 'gray'
                    };
                @endphp
                <div class="px-6 py-4 border-b border-gray-100"
                     style="background-color: {{ match($rsvpColor) { 'emerald' => '#f0fdf4', 'red' => '#fef2f2', 'amber' => '#fffbeb', default => '#f9fafb' } }};">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Your Response</p>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-bold text-white"
                                      style="background-color: {{ match($rsvpColor) { 'emerald' => '#16a34a', 'red' => '#dc2626', 'amber' => '#d97706', default => '#6b7280' } }};">
                                    <i class="fa-solid {{ match($rsvpColor) { 'emerald' => 'fa-circle-check', 'red' => 'fa-circle-xmark', 'amber' => 'fa-circle-question', default => 'fa-circle' } }} text-[10px]"></i>
                                    {{ $alumniRsvp->response }}
                                </span>
                            </div>
                        </div>
                        <button wire:click="openRsvpModal" class="px-4 py-2 rounded-lg text-sm font-bold text-white transition"
                                style="background-color:#7a3f91;">
                            <i class="fa-solid fa-pen-to-square text-xs mr-1.5"></i> Change
                        </button>
                    </div>
                </div>
                @endif

                {{-- RSVP Stats --}}
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-users text-xs text-gray-400"></i>
                        Attendee Responses
                    </h3>
                    @if($totalRsvp === 0)
                        <div class="text-center py-4 text-gray-400 text-sm">
                            <i class="fa-solid fa-inbox text-2xl mb-2"></i>
                            <p>No responses yet</p>
                        </div>
                    @else
                        <div class="grid grid-cols-3 gap-3">
                            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-check text-emerald-600 text-lg mb-1"></i>
                                <p class="text-2xl font-black text-emerald-700">{{ $event->confirmed_count }}</p>
                                <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wide mt-0.5">Confirmed</p>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-xmark text-red-600 text-lg mb-1"></i>
                                <p class="text-2xl font-black text-red-700">{{ $event->declined_count }}</p>
                                <p class="text-[10px] font-bold text-red-600 uppercase tracking-wide mt-0.5">Not Attending</p>
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center">
                                <i class="fa-solid fa-circle-question text-amber-600 text-lg mb-1"></i>
                                <p class="text-2xl font-black text-amber-700">{{ $event->tentative_count }}</p>
                                <p class="text-[10px] font-bold text-amber-600 uppercase tracking-wide mt-0.5">Maybe</p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Location & Address --}}
                @if($event->venue || $event->venue_address)
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-map-pin text-xs text-gray-400"></i>
                        Location
                    </h3>
                    <div class="text-sm text-gray-700">
                        <p class="font-semibold">{{ $event->venue }}</p>
                        @if($event->venue_address)
                            <p class="text-xs text-gray-500 mt-1">{{ $event->venue_address }}</p>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Description --}}
                @if($event->description)
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-file-lines text-xs text-gray-400"></i>
                        About This Event
                    </h3>
                    <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">
                        {{ $event->description }}
                    </div>
                </div>
                @endif

                {{-- Notes --}}
                @if($event->notes)
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-xs text-gray-400"></i>
                        Additional Notes
                    </h3>
                    <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">
                        {{ $event->notes }}
                    </div>
                </div>
                @endif

                {{-- Contact Person --}}
                @if($event->contact_person || $event->contact_email || $event->contact_phone)
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-address-card text-xs text-gray-400"></i>
                        Contact Information
                    </h3>
                    <div class="space-y-1.5 text-sm text-gray-700">
                        @if($event->contact_person)
                            <p><i class="fa-solid fa-user text-gray-400 text-xs mr-2"></i><span class="font-semibold">{{ $event->contact_person }}</span></p>
                        @endif
                        @if($event->contact_email)
                            <p><i class="fa-solid fa-envelope text-gray-400 text-xs mr-2"></i>{{ $event->contact_email }}</p>
                        @endif
                        @if($event->contact_phone)
                            <p><i class="fa-solid fa-phone text-gray-400 text-xs mr-2"></i>{{ $event->contact_phone }}</p>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Event Meta --}}
                <div class="px-6 py-4">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Event Information</p>
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="text-gray-400 font-bold mb-1">Posted</p>
                            <p class="font-semibold text-gray-800">{{ \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                            <p class="text-gray-500 text-[10px] mt-0.5">{{ \Carbon\Carbon::parse($event->created_at)->setTimezone('Asia/Manila')->diffForHumans() }}</p>
                        </div>
                        <div>
                            <p class="text-gray-400 font-bold mb-1">Posted By</p>
                            <p class="font-semibold text-gray-800">
                                @if($viewingEventType === 'ADMIN')
                                    Admin
                                @else
                                    {{ $event->organizer?->name ?? 'Organizer' }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Modal Footer --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex items-center justify-end gap-3">
                <button wire:click="closeViewModal" type="button"
                        class="px-5 py-2 rounded-xl text-sm font-bold text-gray-600 border border-gray-300 bg-white hover:bg-gray-50 transition">
                    <i class="fa-solid fa-xmark text-xs mr-1.5"></i> Close
                </button>
                @if(!$isCompleted)
                <button type="button"
                        wire:click="openShareModal({{ $event->id }}, '{{ $viewingEventType }}')"
                        class="px-4 py-2 bg-sky-100 text-sky-700 border border-sky-300 rounded-xl text-sm font-bold hover:bg-white hover:border-sky-500 transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs mr-1"></i> Share
                </button>
                <button type="button" wire:click="openRsvpModal"
                        class="px-5 py-2 rounded-xl text-sm font-bold text-white transition flex items-center gap-2"
                        style="background-color:#7a3f91;">
                    <i class="fa-solid fa-check text-xs"></i> {{ $alumniRsvp ? 'Update RSVP' : 'RSVP Now' }}
                </button>
                @endif
            </div>

        </div>
    </div>
    @endif


    {{-- ══ RSVP MODAL ════════════════════════════════════════════════════════ --}}
    @if($showRsvpModal)
    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         wire:keydown.escape.window="closeRsvpModal">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden relative">

            <button wire:click="closeRsvpModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full
                           bg-gray-100 hover:bg-gray-200 transition text-gray-600">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            <div class="px-6 py-5 border-b border-gray-100" style="background-color:#7a3f91;">
                <h2 class="text-lg font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clipboard-check"></i> Confirm Your RSVP
                </h2>
                <p class="text-sm text-white/75 mt-2">Let us know if you're attending this event</p>
            </div>

            <div class="px-6 py-6 space-y-4">

                <div class="space-y-3">
                    <button type="button" wire:click="submitRsvp('CONFIRMED')"
                            wire:loading.attr="disabled"
                            class="w-full px-4 py-3 rounded-xl border-2 transition flex items-center gap-3
                                   {{ $rsvpResponse === 'CONFIRMED' ? 'border-emerald-500 bg-emerald-50' : 'border-emerald-200 hover:border-emerald-400 bg-white' }}">
                        <i class="fa-solid fa-circle-check text-emerald-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-emerald-700">I'm Attending</p>
                            <p class="text-xs text-emerald-600">Confirm your attendance</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-emerald-400 text-sm"></i>
                    </button>

                    <button type="button" wire:click="submitRsvp('TENTATIVE')"
                            wire:loading.attr="disabled"
                            class="w-full px-4 py-3 rounded-xl border-2 transition flex items-center gap-3
                                   {{ $rsvpResponse === 'TENTATIVE' ? 'border-amber-500 bg-amber-50' : 'border-amber-200 hover:border-amber-400 bg-white' }}">
                        <i class="fa-solid fa-circle-question text-amber-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-amber-700">Maybe</p>
                            <p class="text-xs text-amber-600">You might attend</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-amber-400 text-sm"></i>
                    </button>

                    <button type="button" wire:click="submitRsvp('DECLINED')"
                            wire:loading.attr="disabled"
                            class="w-full px-4 py-3 rounded-xl border-2 transition flex items-center gap-3
                                   {{ $rsvpResponse === 'DECLINED' ? 'border-red-500 bg-red-50' : 'border-red-200 hover:border-red-400 bg-white' }}">
                        <i class="fa-solid fa-circle-xmark text-red-600 text-xl"></i>
                        <div class="flex-1 text-left">
                            <p class="font-bold text-red-700">I Can't Attend</p>
                            <p class="text-xs text-red-600">You won't be attending</p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-red-400 text-sm"></i>
                    </button>
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-2 block">
                        Message (Optional)
                    </label>
                    <textarea wire:model="rsvpMessage" rows="2"
                              placeholder="Add a personal note or question…"
                              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm text-gray-900 bg-white focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100 transition resize-none"
                              maxlength="200"></textarea>
                    <p class="text-xs text-gray-400 mt-1">{{ strlen($rsvpMessage) }}/200</p>
                </div>

            </div>

            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex gap-3">
                <button wire:click="closeRsvpModal" type="button"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-gray-600 border border-gray-300 bg-white hover:bg-gray-50 transition">
                    Cancel
                </button>
            </div>

        </div>
    </div>
    @endif


    {{-- ══ SHARE MODAL ════════════════════════════════════════════════════════ --}}
    @if($showShareModal)
    @php
        $shareBaseUrl     = $this->eventsBaseUrl();
        $shareDescPreview = mb_strlen($shareDescription) > 120
            ? mb_substr($shareDescription, 0, 120) . '…'
            : $shareDescription;

        $timeStr = $shareTime . ($shareEndTime ? ' – ' . $shareEndTime : '');

        $fbPostLines   = [];
        $fbPostLines[] = "📅 Event: {$shareEventTitle}";
        if ($shareDate)       $fbPostLines[] = "🗓️  {$shareDate}" . ($timeStr ? " · {$timeStr}" : '');
        if ($shareVenue)      $fbPostLines[] = "📍 {$shareVenue}";
        if ($shareOrganizer)  $fbPostLines[] = "🏫 Organized by: {$shareOrganizer}";
        if ($shareTargetParts) $fbPostLines[] = "👥 Open for: {$shareTargetParts}";
        $fbPostLines[] = '';
        $fbPostLines[] = "See full details & RSVP on the PHILCST Alumni Portal 👇";
        $fbPostLines[] = $shareBaseUrl;
        if ($sharePhotoUrl) {
            $fbPostLines[] = '';
            $fbPostLines[] = "📸 Event cover: {$sharePhotoUrl}";
        }
        $fbPostText = implode("\n", $fbPostLines);

        $fbShareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);
        $shareHost  = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    @endphp

    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
         wire:keydown.escape="closeShareModal"
         x-data="{
             copied: false, fbCopied: false, messengerCopied: false,
             fbText:  {{ json_encode($fbPostText) }},
             baseUrl: {{ json_encode($shareBaseUrl) }},
             fbUrl:   {{ json_encode($fbShareUrl) }},
             photoUrl: {{ json_encode($sharePhotoUrl) }},
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

        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="text-base font-extrabold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-share-nodes text-sky-600"></i> Share Event
                </h2>
                <button wire:click="closeShareModal" type="button"
                        class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition cursor-pointer">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="px-6 pt-5 pb-5 space-y-4">

                {{-- FB copied banner --}}
                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-3">
                    <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-check text-emerald-600 text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-extrabold text-emerald-800">Event text copied to clipboard!</p>
                        <p class="text-xs text-emerald-700 mt-0.5 leading-snug">
                            In the Facebook popup, click the text box then <strong>paste (Ctrl+V / ⌘V)</strong> — then you're ready to post!
                        </p>
                    </div>
                </div>

                {{-- Messenger copied banner --}}
                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-3">
                    <div class="w-7 h-7 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-check text-blue-600 text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-extrabold text-blue-800">Event text copied to clipboard!</p>
                        <p class="text-xs text-blue-700 mt-0.5 leading-snug">
                            Messenger is now open. Paste the text
                            (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>)
                            in a conversation or group chat to share!
                        </p>
                    </div>
                </div>

                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest">Preview — What recipients will see</p>

                {{-- Preview card with event photo --}}
                <div class="rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm">

                    {{-- Event cover photo --}}
                    @if($sharePhotoUrl)
                    <div class="w-full h-32 bg-gradient-to-br from-purple-200 to-purple-100 overflow-hidden">
                        <img src="{{ $sharePhotoUrl }}" alt="{{ $shareEventTitle }}" class="w-full h-full object-cover">
                    </div>
                    @else
                    <div class="w-full h-20 bg-gradient-to-br from-[#7a3f91] to-[#4c1d95] flex items-center justify-center">
                        <i class="fas fa-calendar-days text-white/50 text-3xl"></i>
                    </div>
                    @endif

                    <div class="bg-[#f0f2f5] border-b border-gray-200 px-4 py-3 flex items-start gap-3">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#4c1d95] flex items-center justify-center flex-shrink-0 shadow">
                            <i class="fas fa-calendar-check text-white text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-extrabold text-gray-900 text-sm leading-tight truncate">{{ $shareEventTitle }}</p>
                            <p class="text-xs text-gray-700 mt-0.5 font-semibold">
                                @if($shareOrganizer)<span class="text-purple-700">{{ $shareOrganizer }}</span>@endif
                            </p>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                @if($shareDate)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700">
                                        <i class="fas fa-calendar text-[8px]"></i>{{ $shareDate }}
                                    </span>
                                @endif
                                @if($shareTime)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-gray-700">
                                        <i class="fas fa-clock text-[8px]"></i>{{ $shareTime }}{{ $shareEndTime ? ' – '.$shareEndTime : '' }}
                                    </span>
                                @endif
                                @if($shareVenue)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-gray-700">
                                        <i class="fas fa-location-dot text-[8px]"></i>{{ $shareVenue }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($shareDescPreview)
                    <div class="px-4 py-2.5 bg-white border-b border-gray-100">
                        <p class="text-xs text-gray-600 leading-relaxed line-clamp-3">{{ $shareDescPreview }}</p>
                    </div>
                    @endif

                    <div class="px-4 py-2 bg-[#f0f2f5] flex items-center gap-2">
                        <i class="fas fa-globe text-gray-400 text-[10px]"></i>
                        <span class="text-[10px] text-gray-500 uppercase tracking-wide font-semibold">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                {{-- Info --}}
                <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 flex items-start gap-2.5">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-800 leading-snug">
                        <strong>How it works:</strong> Click a share button — the full event text (including the cover photo link) is automatically copied to your clipboard and the platform opens.
                        Just paste (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>) in your post or chat and you're done!
                    </p>
                </div>

                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest pt-1">Share via</p>

                {{-- Facebook --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-extrabold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-8 h-8 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span x-show="!fbCopied">Share on Facebook</span>
                        <span x-show="fbCopied" x-cloak><i class="fas fa-check mr-1"></i> Facebook is open — paste the text!</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/70 text-xs group-hover:text-white transition"></i>
                </button>

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl bg-gradient-to-r from-[#00B2FF] to-[#006AFF] hover:from-[#00a0e6] hover:to-[#005ee6] text-white font-extrabold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-8 h-8 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4">
                            <defs><linearGradient id="mgr3" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr3)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span x-show="!messengerCopied">Share on Messenger</span>
                        <span x-show="messengerCopied" x-cloak><i class="fas fa-check mr-1"></i> Messenger is open — paste the text!</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/70 text-xs group-hover:text-white transition"></i>
                </button>
                <p class="text-[10px] text-gray-400 text-center -mt-2">
                    <i class="fas fa-users text-[9px] mr-1"></i>Works for private chats, group chats, and Messenger group pages.
                </p>

                {{-- Copy Link --}}
                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-bold text-sm transition cursor-pointer group">
                    <span class="w-8 h-8 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-500'" class="text-xs"></i>
                    </span>
                    <div class="flex-1 text-left">
                        <p :class="copied ? 'text-emerald-600' : 'text-gray-700'" class="font-bold text-sm"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-[10px] text-gray-400 font-mono mt-0.5">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <p class="text-[11px] text-gray-400 text-center leading-snug pb-1">
                    Sharing is disabled for events that have already ended.
                </p>
            </div>
        </div>
    </div>
    @endif
    {{-- END SHARE MODAL --}}

</div>