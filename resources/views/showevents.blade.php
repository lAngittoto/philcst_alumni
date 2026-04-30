
@extends('layouts.public')

@section('content')
@include('layouts.header')

@php
    use App\Models\AdminEvent;
    use Illuminate\Support\Facades\Storage;

    $events = AdminEvent::where('status', 'APPROVED')
        ->with('organizer')
        ->withCount([
            'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
            'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
            'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
        ])
        ->orderByDesc('event_date')
        ->get();

    $firstFive = $events->take(5);
    $remaining  = $events->skip(5);
    $hasMore    = $remaining->count() > 0;

    $eventsJson = [];
    foreach ($events as $e) {
        $eventsJson[] = [
            'id'                  => $e->id,
            'title'               => $e->title,
            'description'         => $e->description,
            'venue'               => $e->venue,
            'venue_address'       => $e->venue_address,
            'target_participants' => $e->target_participants,
            'contact_person'      => $e->contact_person,
            'contact_email'       => $e->contact_email,
            'contact_phone'       => $e->contact_phone,
            'notes'               => $e->notes,
            'photo_url'           => $e->photo_url,
            'event_date'          => $e->event_date->setTimezone('Asia/Manila')->format('F d, Y'),
            'start_time'          => $e->event_date->setTimezone('Asia/Manila')->format('g:i A'),
            'end_time'            => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('g:i A') : null,
            'organizer_name'      => $e->organizer?->name,
            'organizer_dept'      => $e->organizer?->department,
            'confirmed_count'     => (int) $e->confirmed_count,
            'declined_count'      => (int) $e->declined_count,
            'tentative_count'     => (int) $e->tentative_count,
            'created_at'          => $e->created_at->setTimezone('Asia/Manila')->format('M d, Y'),
        ];
    }
@endphp

<style>
    :root {
        --primary-purple: #7a3f91;
        --text-dark: #333333;
    }

    .text-primary  { color: var(--primary-purple); }
    .bg-primary    { background-color: var(--primary-purple); }
    .border-primary{ border-color: var(--primary-purple); }

    /* ── Side Drawer ── */
    #ev-drawer {
        position: fixed;
        top: 0; right: 0;
        height: 100%;
        width: 100%;
        max-width: 700px;
        background: #fff;
        z-index: 60;
        transform: translateX(100%);
        transition: transform .35s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
        box-shadow: -8px 0 40px rgba(0,0,0,.18);
    }
    #ev-drawer.open { transform: translateX(0); }

    #ev-drawer-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        backdrop-filter: blur(3px);
        z-index: 59;
        opacity: 0;
        pointer-events: none;
        transition: opacity .35s ease;
    }
    #ev-drawer-overlay.open { opacity: 1; pointer-events: auto; }

    #ev-drawer-body {
        flex: 1;
        overflow-y: auto;
        overscroll-behavior: contain;
        background: #fff;
    }

    /* Scrollbar */
    #ev-drawer-body::-webkit-scrollbar { width: 4px; }
    #ev-drawer-body::-webkit-scrollbar-track { background: #f3f4f6; }
    #ev-drawer-body::-webkit-scrollbar-thumb { background: var(--primary-purple); border-radius: 4px; }
</style>

<main class="w-full overflow-x-hidden bg-gray-100">

    {{-- ══ HERO ══ --}}
    <div class="relative bg-white px-6 py-16 text-center sm:py-20">
        <div class="mx-auto max-w-3xl">
            <span class="mb-4 inline-block font-sans text-xs font-semibold tracking-widest text-primary uppercase" data-aos="fade-down" data-aos-duration="600">
                <i class="fa-solid fa-calendar-star mr-1 text-primary"></i>
                Alumni Events
            </span>
            <h1 class="mb-3 font-sans text-4xl font-semibold tracking-tight sm:text-5xl" style="color: var(--text-dark);" data-aos="fade-up" data-aos-delay="100" data-aos-duration="700">
                Upcoming <span class="text-primary">Gatherings</span><br class="hidden sm:block">& Reunions
            </h1>
            <p class="mx-auto mb-4 max-w-md font-sans text-sm leading-relaxed text-gray-500" data-aos="fade-up" data-aos-delay="200" data-aos-duration="700">
                Stay connected with your alma mater. Join events made just for you.
            </p>
            <div class="mx-auto h-1 w-10 bg-primary" data-aos="fade-up" data-aos-delay="300"></div>
        </div>

        {{-- Wave shape --}}
        <svg class="absolute bottom-0 left-0 w-full" viewBox="0 0 1200 120" preserveAspectRatio="none" style="height:50px;">
            <path d="M0,40 Q300,80 600,40 T1200,40 L1200,120 L0,120 Z" fill="#f3f4f6"></path>
        </svg>
    </div>

    {{-- ══ EVENTS SECTION ══ --}}
    <div class="mx-auto max-w-7xl px-5 py-10 sm:px-6 md:py-14 lg:px-8">

        @if($events->isEmpty())

            <div class="py-20 text-center" data-aos="fade-up" data-aos-duration="700">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-200">
                    <i class="fa-solid fa-calendar-days text-2xl text-primary"></i>
                </div>
                <h2 class="mb-2 font-sans text-lg font-semibold uppercase" style="color: var(--text-dark);">No Events Yet</h2>
                <p class="font-sans text-sm text-gray-500">There are no upcoming events at the moment.<br>Check back soon for exciting alumni gatherings!</p>
            </div>

        @else

            {{-- Section divider --}}
            <div class="mb-7 flex items-center gap-4" data-aos="fade-right" data-aos-duration="500">
                <div class="h-px flex-1 bg-gray-300"></div>
                <span class="whitespace-nowrap font-sans text-xs font-semibold tracking-widest text-primary uppercase">
                    <i class="fa-solid fa-fire-flame-curved mr-2 text-primary"></i>Latest Events
                </span>
                <div class="h-px flex-1 bg-gray-300"></div>
            </div>

            {{-- ── FIRST 5 CARDS ── --}}
            <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3" id="ev-grid-main">
                @foreach($firstFive as $i => $event)
                @php $d = $i * 80; @endphp
                <div class="group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600">

                   
@if(!empty($event->photo_url))
    <img src="{{ $event->photo_url }}"
         alt="{{ $event->title }}" class="h-56 w-full object-cover">
@else
                        <div class="flex h-56 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                            <i class="fa-solid fa-image text-6xl text-primary opacity-15"></i>
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-3 p-4">

                        {{-- Date chip --}}
                        <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-2 text-xs font-semibold text-primary">
                            <i class="fa-solid fa-calendar text-primary" style="font-size:0.6rem;"></i>
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                        </div>

                        <h3 class="font-sans text-sm font-semibold uppercase tracking-tight leading-tight" style="color: var(--text-dark);">{{ $event->title }}</h3>

                        <div class="space-y-2">
                            @if($event->target_participants)
                            <div class="flex gap-2 text-xs text-gray-600">
                                <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.65rem;"></i>
                                <span>{{ $event->target_participants }}</span>
                            </div>
                            @endif
                        </div>

                        @if($event->description)
                        <p class="line-clamp-2 text-xs text-gray-500">{{ $event->description }}</p>
                        @endif

                        <button class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white transition-all duration-200 hover:opacity-90"
                                onclick="event.stopPropagation(); evOpenDrawer({{ $event->id }})">
                            <i class="fa-solid fa-arrow-right text-white" style="font-size:0.65rem;"></i>
                            View Details
                        </button>

                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── REMAINING CARDS (hidden) ── --}}
            @if($hasMore)
            <div id="ev-grid-more" style="display:none;">

                <div class="mb-7 mt-10 flex items-center gap-4">
                    <div class="h-px flex-1 bg-gray-300"></div>
                    <span class="whitespace-nowrap font-sans text-xs font-semibold tracking-widest text-primary uppercase">
                        <i class="fa-solid fa-calendar-days mr-2 text-primary"></i>More Events
                    </span>
                    <div class="h-px flex-1 bg-gray-300"></div>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($remaining as $i => $event)
                    @php $d = ($i % 5) * 80; @endphp
                    <div class="group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                         data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600">

                   @if(!empty($event->photo_url))
                            <img src="{{ asset('storage/' . $event->photo) }}"
                                 alt="{{ $event->title }}" class="h-56 w-full object-cover">
                        @else
                            <div class="flex h-56 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                                <i class="fa-solid fa-image text-6xl text-primary opacity-15"></i>
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col gap-3 p-4">

                            <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-2 text-xs font-semibold text-primary">
                                <i class="fa-solid fa-calendar text-primary" style="font-size:0.6rem;"></i>
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                                &nbsp;·&nbsp;
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                            </div>

                            <h3 class="font-sans text-sm font-semibold uppercase tracking-tight leading-tight" style="color: var(--text-dark);">{{ $event->title }}</h3>

                            <div class="space-y-2">
                                @if($event->target_participants)
                                <div class="flex gap-2 text-xs text-gray-600">
                                    <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.65rem;"></i>
                                    <span>{{ $event->target_participants }}</span>
                                </div>
                                @endif
                            </div>

                            @if($event->description)
                            <p class="line-clamp-2 text-xs text-gray-500">{{ $event->description }}</p>
                            @endif

                            <button class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white transition-all duration-200 hover:opacity-90"
                                    onclick="event.stopPropagation(); evOpenDrawer({{ $event->id }})">
                                <i class="fa-solid fa-arrow-right text-white" style="font-size:0.65rem;"></i>
                                View Details
                            </button>

                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Show more / less button --}}
            <div class="mt-8 text-center" data-aos="fade-up" data-aos-delay="150">
                <button class="inline-flex items-center gap-2 rounded-2xl border-2 border-primary px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-primary transition-all duration-200 hover:bg-primary hover:text-white" id="ev-more-btn" onclick="evToggleMore()">
                    <i class="fa-solid fa-chevron-down text-inherit transition-transform duration-300" id="ev-more-icon"></i>
                    <span id="ev-more-text">See All {{ $events->count() }} Events</span>
                </button>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- ══ SIDE DRAWER OVERLAY ══ --}}
<div id="ev-drawer-overlay" onclick="evCloseDrawer()"></div>

{{-- ══ SIDE DRAWER ══ --}}
<div id="ev-drawer" role="dialog" aria-modal="true">

    {{-- Drawer Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 flex-shrink-0">
        <div class="flex items-center gap-2">
            <i class="fa-solid fa-calendar-star text-primary"></i>
            <span class="text-xs font-semibold uppercase tracking-widest text-primary">Event Details</span>
        </div>
        <button onclick="evCloseDrawer()"
                class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 transition-colors text-sm font-bold">
            ×
        </button>
    </div>

    {{-- Drawer Body --}}
    <div id="ev-drawer-body">
        <div id="ev-drawer-inner" class="flex items-center justify-center py-16 text-center">
            <div>
                <i class="fa-solid fa-spinner fa-spin mb-3 block text-xl text-primary"></i>
                <span class="text-xs tracking-widest text-gray-400 uppercase">Loading…</span>
            </div>
        </div>
    </div>
</div>

{{-- Safe JSON --}}
<script>
const EV_DATA = {!! json_encode($eventsJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
</script>

<script>
// ── SHOW MORE / LESS ──
var evMoreOpen   = false;
var evTotalCount = {{ $events->count() }};

function evToggleMore() {
    var more = document.getElementById('ev-grid-more');
    var icon = document.getElementById('ev-more-icon');
    var txt  = document.getElementById('ev-more-text');

    evMoreOpen = !evMoreOpen;
    if (evMoreOpen) {
        more.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
        txt.textContent = 'Show Less';
        if (typeof AOS !== 'undefined') AOS.refresh();
        setTimeout(function () {
            more.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 80);
    } else {
        more.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
        txt.textContent = 'See All ' + evTotalCount + ' Events';
        document.getElementById('ev-grid-main').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ── SIDE DRAWER ──
function evOpenDrawer(id) {
    var ev = null;
    for (var i = 0; i < EV_DATA.length; i++) {
        if (EV_DATA[i].id === id) { ev = EV_DATA[i]; break; }
    }
    if (!ev) return;

    // Time range
    var timeHtml = ev.end_time
        ? ev.start_time + ' <span class="text-gray-400">–</span> ' + ev.end_time
        : ev.start_time;

    var targetHtml = ev.target_participants ? true : false;

    // Cover photo
    var photoHtml = ev.photo_url
        ? '<img src="' + ev.photo_url + '" alt="' + ev.title + '" class="w-full h-72 object-cover rounded-xl overflow-hidden">'
        : '<div class="flex h-72 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl"><i class="fa-solid fa-image text-6xl text-primary opacity-15"></i></div>';

    document.getElementById('ev-drawer-inner').innerHTML =
        '<div class="flex flex-col h-full">' +
            '<div class="p-6 pb-0">' +
                photoHtml +
            '</div>' +
            '<div class="flex-1 overflow-y-auto p-6">' +

                // Title
                '<h2 class="mb-5 text-2xl font-bold leading-snug" style="color: var(--text-dark);">' + ev.title + '</h2>' +

                // Key details section
                '<div class="mb-6 space-y-4 pb-6 border-b border-gray-200">' +
                    '<div class="flex gap-3 text-sm">' +
                        '<i class="fa-solid fa-calendar mt-0.5 flex-shrink-0 text-primary" style="font-size:0.9rem;"></i>' +
                        '<div>' +
                            '<p class="font-semibold text-primary">' + ev.event_date + '</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="flex gap-3 text-sm text-gray-600">' +
                        '<i class="fa-solid fa-clock mt-0.5 flex-shrink-0 text-primary" style="font-size:0.9rem;"></i>' +
                        '<span>' + timeHtml + '</span>' +
                    '</div>' +
                    '<div class="flex gap-3 text-sm text-gray-600">' +
                        '<i class="fa-solid fa-map-pin mt-0.5 flex-shrink-0 text-primary" style="font-size:0.9rem;"></i>' +
                        '<span>' + ev.venue + (ev.venue_address ? ', ' + ev.venue_address : '') + '</span>' +
                    '</div>' +
                    (targetHtml ? '<div class="flex gap-3 text-sm text-gray-600">' +
                        '<i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.9rem;"></i>' +
                        '<span>' + ev.target_participants + '</span>' +
                    '</div>' : '') +
                '</div>' +

                (ev.description ? '<div class="mb-6">' +
                    '<p class="mb-4 text-sm font-bold uppercase tracking-wider text-primary"><i class="fa-solid fa-align-left text-primary mr-2" style="font-size:0.75rem;"></i>About This Event</p>' +
                    '<p class="text-sm leading-relaxed text-gray-700 whitespace-pre-wrap">' + ev.description + '</p>' +
                '</div>' : '') +

            '</div>' +
        '</div>';

    document.getElementById('ev-drawer').classList.add('open');
    document.getElementById('ev-drawer-overlay').classList.add('open');
    document.getElementById('ev-drawer-body').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function evCloseDrawer() {
    document.getElementById('ev-drawer').classList.remove('open');
    document.getElementById('ev-drawer-overlay').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') evCloseDrawer();
});
</script>

@include('layouts.footer')

@endsection