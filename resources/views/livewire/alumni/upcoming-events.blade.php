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
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">
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

        {{-- ══ PAGE HEADER — purple bg ══════════════════════════════════════ --}}
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

            {{-- Search --}}
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

            {{-- Status filter --}}
            <select wire:model.live="filterStatus"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="">All Events</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>

            {{-- Sort --}}
            <select wire:model.live="filterSort"
                    class="filter-input px-3 py-2 rounded-xl text-sm bg-white text-gray-700">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset --}}
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

                    <div class="event-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col"
                         wire:click="viewEvent({{ $event->id }}, '{{ $event->event_source }}')">

                        {{-- Event Image/Photo --}}
                        <div class="w-full h-40 bg-gradient-to-br from-purple-200 to-purple-100 relative overflow-hidden flex-shrink-0">
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="w-full h-full object-cover">
                        </div>

                        <div class="p-4 flex flex-col flex-1 gap-3">

                            {{-- Date & Status --}}
                            <div class="flex items-start justify-between gap-2">
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
                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                <i class="fa-solid fa-location-dot text-gray-400 text-[10px]"></i>
                                <span class="truncate">{{ $event->venue }}</span>
                            </div>
                            @endif

                            {{-- Organizer/Source --}}
                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
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

                                {{-- Click to view (right side) --}}
                                <span class="card-view-hint inline-flex items-center gap-1.5 text-xs font-bold
                                             px-3 py-1.5 rounded-lg text-white transition-colors"
                                      style="background-color:#7a3f91;">
                                    <i class="fa-solid fa-eye text-[10px]"></i> View
                                </span>
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
                        of <span class="font-bold">{{ $total }}</span> events
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

            {{-- Close X --}}
            <button wire:click="closeViewModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full
                           bg-white/25 hover:bg-white/40 transition text-white z-10">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- ── Modal Header — Event Image ─────────────────────────────── --}}
            <div class="relative h-40 sm:h-56 flex-shrink-0 overflow-hidden"
                 style="background: linear-gradient(135deg, #7a3f91 0%, #5e2f72 100%);">
                <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="w-full h-full object-cover">
            </div>

            {{-- ── Event Title & Meta ────────────────────────────────────── --}}
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

            {{-- ── Modal Body ──────────────────────────────────────────────── --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c">

                {{-- Your RSVP Status --}}
                @if($alumniRsvp)
                <div class="px-6 py-4 border-b border-gray-100"
                     @php
                        $rsvpColor = match($alumniRsvp->response) {
                            'CONFIRMED' => 'emerald',
                            'DECLINED' => 'red',
                            'TENTATIVE' => 'amber',
                            default => 'gray'
                        };
                     @endphp
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

            {{-- ── Modal Footer ─────────────────────────────────────────────── --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex items-center justify-end gap-3">
                <button wire:click="closeViewModal" type="button"
                        class="px-5 py-2 rounded-xl text-sm font-bold text-gray-600 border border-gray-300 bg-white hover:bg-gray-50 transition">
                    <i class="fa-solid fa-xmark text-xs mr-1.5"></i> Close
                </button>
                @if(!$isCompleted)
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

            {{-- Close X --}}
            <button wire:click="closeRsvpModal" type="button"
                    class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full
                           bg-gray-100 hover:bg-gray-200 transition text-gray-600">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>

            {{-- ── Header ─────────────────────────────────────────────────── --}}
            <div class="px-6 py-5 border-b border-gray-100" style="background-color:#7a3f91;">
                <h2 class="text-lg font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clipboard-check"></i> Confirm Your RSVP
                </h2>
                <p class="text-sm text-white/75 mt-2">Let us know if you're attending this event</p>
            </div>

            {{-- ── Body ────────────────────────────────────────────────────── --}}
            <div class="px-6 py-6 space-y-4">

                {{-- RSVP Buttons --}}
                <div class="space-y-3">
                    {{-- Confirmed --}}
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

                    {{-- Tentative --}}
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

                    {{-- Not Attending --}}
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

                {{-- Message (Optional) --}}
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

            {{-- ── Footer ─────────────────────────────────────────────────── --}}
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex gap-3">
                <button wire:click="closeRsvpModal" type="button"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-gray-600 border border-gray-300 bg-white hover:bg-gray-50 transition">
                    Cancel
                </button>
            </div>

        </div>
    </div>
    @endif

</div>