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
    
    .text-primary { color: var(--primary-purple); }
    .bg-primary { background-color: var(--primary-purple); }
    .border-primary { border-color: var(--primary-purple); }
</style>

<main class="w-full overflow-x-hidden bg-gray-100">

    {{-- ══ HERO ══ --}}
    <div class="relative bg-white px-6 py-20 text-center sm:py-24 md:py-28">
        <div class="mx-auto max-w-3xl">
            <span class="mb-5 inline-block font-sans text-xl font-semibold tracking-widest text-primary uppercase" data-aos="fade-down" data-aos-duration="600">
                <i class="fa-solid fa-calendar-star mr-1 text-primary" style="font-size:0.65rem;"></i>
                Alumni Events
            </span>
            <h1 class="mb-4 font-sans text-5xl font-semibold tracking-tight" style="color: var(--text-dark);" data-aos="fade-up" data-aos-delay="100" data-aos-duration="700">
                Upcoming <span class="text-primary">Gatherings</span><br class="hidden sm:block">& Reunions
            </h1>
            <p class="mx-auto mb-4 max-w-md font-sans text-xl leading-relaxed" style="color: var(--text-dark);" data-aos="fade-up" data-aos-delay="200" data-aos-duration="700">
                Stay connected with your alma mater. Join events made just for you.
            </p>
            <div class="mx-auto h-1 w-12 bg-primary" data-aos="fade-up" data-aos-delay="300"></div>
        </div>
        
        {{-- Wave shape --}}
        <svg class="absolute bottom-0 left-0 w-full" viewBox="0 0 1200 120" preserveAspectRatio="none" style="height: 60px;">
            <path d="M0,40 Q300,80 600,40 T1200,40 L1200,120 L0,120 Z" fill="#f3f4f6"></path>
        </svg>
    </div>

    {{-- ══ EVENTS SECTION ══ --}}
    <div class="mx-auto max-w-7xl px-5 py-12 sm:px-6 md:py-16 lg:px-8">

        @if($events->isEmpty())

            {{-- Empty state --}}
            <div class="py-20 text-center" data-aos="fade-up" data-aos-duration="700">
                <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-2xl bg-gray-200">
                    <i class="fa-solid fa-calendar-days text-3xl text-primary"></i>
                </div>
                <h2 class="mb-2 font-sans text-2xl font-semibold uppercase" style="color: var(--text-dark);">No Events Yet</h2>
                <p class="font-sans text-xl" style="color: var(--text-dark);">There are no upcoming events at the moment.<br>Check back soon for exciting alumni gatherings!</p>
            </div>

        @else

            {{-- Section divider label --}}
            <div class="mb-8 flex items-center gap-4" data-aos="fade-right" data-aos-duration="500">
                <div class="h-px flex-1 bg-gray-300"></div>
                <span class="whitespace-nowrap font-sans text-xl font-semibold tracking-widest text-primary uppercase">
                    <i class="fa-solid fa-fire-flame-curved mr-2 text-primary"></i>Latest Events
                </span>
                <div class="h-px flex-1 bg-gray-300"></div>
            </div>

            {{-- ── FIRST 5 CARDS ── --}}
            <div class="mb-8 grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3" id="ev-grid-main">
                @foreach($firstFive as $i => $event)
                @php $d = $i * 80; @endphp
                <div class="group relative flex cursor-pointer flex-col overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                     onclick="evOpenModal({{ $event->id }})">

                    {{-- Approved badge --}}
                    <span class="absolute top-3 left-3 z-10 inline-flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 text-xl font-semibold text-green-700 border border-green-200">
                        <i class="fa-solid fa-circle-check text-green-700" style="font-size:0.55rem;"></i> Approved
                    </span>

                    {{-- Cover image --}}
                    @if($event->photo && Storage::disk('public')->exists($event->photo))
                        <img src="{{ asset('storage/' . $event->photo) }}"
                             alt="{{ $event->title }}" class="h-48 w-full object-cover">
                    @else
                        <div class="flex h-48 w-full items-center justify-center bg-gray-100">
                            <i class="fa-solid fa-calendar-days text-5xl text-primary opacity-20"></i>
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-2 p-5">

                        {{-- Date chip --}}
                        <div class="inline-flex w-fit items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-xl font-semibold text-primary font-sans">
                            <i class="fa-solid fa-calendar text-primary" style="font-size:0.65rem;"></i>
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                            &nbsp;·&nbsp;
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                        </div>

                        <h3 class="font-sans text-2xl font-semibold uppercase tracking-tight" style="color: var(--text-dark);">{{ $event->title }}</h3>

                        <div class="space-y-2">
                            <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                <i class="fa-solid fa-location-dot mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                <span>{{ $event->venue }}@if($event->venue_address), {{ $event->venue_address }}@endif</span>
                            </div>
                            @if($event->target_participants)
                            <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                <span>{{ $event->target_participants }}</span>
                            </div>
                            @endif
                            @if($event->organizer)
                            <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                <i class="fa-solid fa-user-tie mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                <span>{{ $event->organizer->name }}</span>
                            </div>
                            @else
                            <div class="flex gap-2 font-sans text-xl">
                                <i class="fa-solid fa-shield-halved mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                <span class="font-semibold text-primary">Posted by Admin</span>
                            </div>
                            @endif
                        </div>

                        @if($event->description)
                        <p class="line-clamp-2 font-sans text-xl" style="color: var(--text-dark);">{{ $event->description }}</p>
                        @endif

                        @php $total = $event->confirmed_count + $event->declined_count + $event->tentative_count; @endphp
                        @if($total > 0)
                        <div class="mt-2 flex flex-wrap gap-2 border-t border-gray-200 pt-2">
                            <span class="inline-flex items-center gap-1 rounded-lg bg-green-50 px-2 py-1 text-xl font-semibold text-green-700 border border-green-200 font-sans">
                                <i class="fa-solid fa-circle-check text-green-700" style="font-size:0.6rem;"></i>
                                {{ $event->confirmed_count }} Attending
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-lg bg-yellow-50 px-2 py-1 text-xl font-semibold text-yellow-700 border border-yellow-200 font-sans">
                                <i class="fa-solid fa-circle-question text-yellow-700" style="font-size:0.6rem;"></i>
                                {{ $event->tentative_count }} Tentative
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-2 py-1 text-xl font-semibold text-red-700 border border-red-200 font-sans">
                                <i class="fa-solid fa-circle-xmark text-red-700" style="font-size:0.6rem;"></i>
                                {{ $event->declined_count }} Not Attending
                            </span>
                        </div>
                        @endif

                        <button class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-sans text-xl font-semibold uppercase tracking-wide text-white transition-all duration-200"
                                onclick="event.stopPropagation(); evOpenModal({{ $event->id }})">
                            <i class="fa-solid fa-arrow-right text-white" style="font-size:0.7rem;"></i>
                            View Details
                        </button>

                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── REMAINING CARDS (hidden) ── --}}
            @if($hasMore)
            <div id="ev-grid-more" style="display:none;">

                <div class="mb-8 flex items-center gap-4 mt-12">
                    <div class="h-px flex-1 bg-gray-300"></div>
                    <span class="whitespace-nowrap font-sans text-xl font-semibold tracking-widest text-primary uppercase">
                        <i class="fa-solid fa-calendar-days mr-2 text-primary"></i>More Events
                    </span>
                    <div class="h-px flex-1 bg-gray-300"></div>
                </div>

                <div class="grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($remaining as $i => $event)
                    @php $d = ($i % 5) * 80; @endphp
                    <div class="group relative flex cursor-pointer flex-col overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                         data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                         onclick="evOpenModal({{ $event->id }})">

                        <span class="absolute top-3 left-3 z-10 inline-flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 text-xl font-semibold text-green-700 border border-green-200">
                            <i class="fa-solid fa-circle-check text-green-700" style="font-size:0.55rem;"></i> Approved
                        </span>

                        @if($event->photo && Storage::disk('public')->exists($event->photo))
                            <img src="{{ asset('storage/' . $event->photo) }}"
                                 alt="{{ $event->title }}" class="h-48 w-full object-cover">
                        @else
                            <div class="flex h-48 w-full items-center justify-center bg-gray-100">
                                <i class="fa-solid fa-calendar-days text-5xl text-primary opacity-20"></i>
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col gap-2 p-5">

                            <div class="inline-flex w-fit items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-xl font-semibold text-primary font-sans">
                                <i class="fa-solid fa-calendar text-primary" style="font-size:0.65rem;"></i>
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                                &nbsp;·&nbsp;
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                            </div>

                            <h3 class="font-sans text-2xl font-semibold uppercase tracking-tight" style="color: var(--text-dark);">{{ $event->title }}</h3>

                            <div class="space-y-2">
                                <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                    <i class="fa-solid fa-location-dot mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                    <span>{{ $event->venue }}@if($event->venue_address), {{ $event->venue_address }}@endif</span>
                                </div>
                                @if($event->target_participants)
                                <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                    <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                    <span>{{ $event->target_participants }}</span>
                                </div>
                                @endif
                                @if($event->organizer)
                                <div class="flex gap-2 font-sans text-xl" style="color: var(--text-dark);">
                                    <i class="fa-solid fa-user-tie mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                    <span>{{ $event->organizer->name }}</span>
                                </div>
                                @else
                                <div class="flex gap-2 font-sans text-xl">
                                    <i class="fa-solid fa-shield-halved mt-0.5 flex-shrink-0 text-primary" style="font-size:0.7rem;"></i>
                                    <span class="font-semibold text-primary">Posted by Admin</span>
                                </div>
                                @endif
                            </div>

                            @if($event->description)
                            <p class="line-clamp-2 font-sans text-xl" style="color: var(--text-dark);">{{ $event->description }}</p>
                            @endif

                            @php $total = $event->confirmed_count + $event->declined_count + $event->tentative_count; @endphp
                            @if($total > 0)
                            <div class="mt-2 flex flex-wrap gap-2 border-t border-gray-200 pt-2">
                                <span class="inline-flex items-center gap-1 rounded-lg bg-green-50 px-2 py-1 text-xl font-semibold text-green-700 border border-green-200 font-sans">
                                    <i class="fa-solid fa-circle-check text-green-700" style="font-size:0.6rem;"></i>
                                    {{ $event->confirmed_count }} Attending
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-lg bg-yellow-50 px-2 py-1 text-xl font-semibold text-yellow-700 border border-yellow-200 font-sans">
                                    <i class="fa-solid fa-circle-question text-yellow-700" style="font-size:0.6rem;"></i>
                                    {{ $event->tentative_count }} Tentative
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-2 py-1 text-xl font-semibold text-red-700 border border-red-200 font-sans">
                                    <i class="fa-solid fa-circle-xmark text-red-700" style="font-size:0.6rem;"></i>
                                    {{ $event->declined_count }} Not Attending
                                </span>
                            </div>
                            @endif

                            <button class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-sans text-xl font-semibold uppercase tracking-wide text-white transition-all duration-200"
                                    onclick="event.stopPropagation(); evOpenModal({{ $event->id }})">
                                <i class="fa-solid fa-arrow-right text-white" style="font-size:0.7rem;"></i>
                                View Details
                            </button>

                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Show more / show less button --}}
            <div class="mt-10 text-center" data-aos="fade-up" data-aos-delay="150">
                <button class="inline-flex items-center gap-2 rounded-2xl border-2 border-primary px-6 py-3 font-sans text-xl font-semibold uppercase tracking-widest text-primary transition-all duration-200" id="ev-more-btn" onclick="evToggleMore()">
                    <i class="fa-solid fa-chevron-down text-primary transition-transform duration-300"></i>
                    <span id="ev-more-text">See All {{ $events->count() }} Events</span>
                </button>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- ══ MODAL ══ --}}
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 opacity-0 pointer-events-none transition-opacity duration-300 backdrop-blur-sm" id="ev-overlay" onclick="evCloseOnOverlay(event)">
    <div class="relative w-full max-w-2xl">
        <button class="absolute top-4 right-4 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white transition-colors" onclick="evCloseModal()">×</button>
        <div class="max-h-[90vh] w-full overflow-y-auto rounded-3xl bg-white shadow-2xl" id="ev-modal-box">
            <div id="ev-modal-inner">
                <div class="flex items-center justify-center py-12">
                    <div class="text-center">
                        <i class="fa-solid fa-spinner fa-spin mb-3 block text-2xl text-primary"></i>
                        <span class="font-sans text-xl tracking-widest" style="color: var(--text-dark);">Loading…</span>
                    </div>
                </div>
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
var evMoreOpen    = false;
var evTotalCount  = {{ $events->count() }};

function evToggleMore() {
    var more = document.getElementById('ev-grid-more');
    var btn  = document.getElementById('ev-more-btn');
    var txt  = document.getElementById('ev-more-text');
    var icon = btn.querySelector('i');
    
    evMoreOpen = !evMoreOpen;
    if (evMoreOpen) {
        more.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
        txt.textContent = 'Show Less';
        if (typeof AOS !== 'undefined') AOS.refresh();
        setTimeout(function() {
            more.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 80);
    } else {
        more.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
        txt.textContent = 'See All ' + evTotalCount + ' Events';
        document.getElementById('ev-grid-main').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ── MODAL ──
function evOpenModal(id) {
    var ev = null;
    for (var i = 0; i < EV_DATA.length; i++) {
        if (EV_DATA[i].id === id) { ev = EV_DATA[i]; break; }
    }
    if (!ev) return;

    var totalRsvp = ev.confirmed_count + ev.declined_count + ev.tentative_count;

    var rsvpHtml = '';
    if (totalRsvp > 0) {
        rsvpHtml =
            '<p class="mb-4 font-sans text-xl font-semibold uppercase tracking-widest" style="color: var(--text-dark);"><i class="fa-solid fa-chart-bar text-primary mr-2"></i>Attendee Responses</p>' +
            '<div class="grid grid-cols-3 gap-3 mb-4">' +
                '<div class="rounded-xl border border-green-200 bg-green-50 p-4 text-center"><div class="font-sans text-3xl font-semibold text-green-700">'  + ev.confirmed_count + '</div><div class="mt-1 font-sans text-xl font-semibold uppercase text-green-700">Attending</div></div>'    +
                '<div class="rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-center"><div class="font-sans text-3xl font-semibold text-yellow-700">' + ev.tentative_count + '</div><div class="mt-1 font-sans text-xl font-semibold uppercase text-yellow-700">Tentative</div></div>'    +
                '<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-center"><div class="font-sans text-3xl font-semibold text-red-700">'  + ev.declined_count  + '</div><div class="mt-1 font-sans text-xl font-semibold uppercase text-red-700">Not Attending</div></div>' +
            '</div>';
    }

    var timeHtml = ev.end_time
        ? ev.start_time + ' <span class="text-gray-400">–</span> ' + ev.end_time
        : ev.start_time;

    var organizerHtml = ev.organizer_name
        ? '<div class="flex gap-3"><i class="fa-solid fa-user-tie mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl" style="color: var(--text-dark);">' + ev.organizer_name + (ev.organizer_dept ? ' · ' + ev.organizer_dept : '') + '</span></div>'
        : '<div class="flex gap-3"><i class="fa-solid fa-shield-halved mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl font-semibold text-primary">Posted by Admin</span></div>';

    var targetHtml = ev.target_participants
        ? '<div class="flex gap-3"><i class="fa-solid fa-users mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl" style="color: var(--text-dark);">' + ev.target_participants + '</span></div>'
        : '';

    var contactHtml = '';
    if (ev.contact_person || ev.contact_email || ev.contact_phone) {
        contactHtml = '<hr class="my-4 border-gray-200"><p class="mb-3 font-sans text-xl font-semibold uppercase tracking-widest" style="color: var(--text-dark);"><i class="fa-solid fa-address-card text-primary mr-2"></i>Contact</p><div class="space-y-2">';
        if (ev.contact_person) contactHtml += '<div class="flex gap-3"><i class="fa-solid fa-user mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl" style="color: var(--text-dark);">'     + ev.contact_person + '</span></div>';
        if (ev.contact_email)  contactHtml += '<div class="flex gap-3"><i class="fa-solid fa-envelope mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><a href="mailto:' + ev.contact_email + '" class="font-sans text-xl text-primary">' + ev.contact_email + '</a></div>';
        if (ev.contact_phone)  contactHtml += '<div class="flex gap-3"><i class="fa-solid fa-phone mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl" style="color: var(--text-dark);">'    + ev.contact_phone  + '</span></div>';
        contactHtml += '</div>';
    }

    var notesHtml = ev.notes
        ? '<hr class="my-4 border-gray-200"><p class="mb-3 font-sans text-xl font-semibold uppercase tracking-widest" style="color: var(--text-dark);"><i class="fa-solid fa-note-sticky text-primary mr-2"></i>Notes</p><p class="font-sans text-xl italic whitespace-pre-wrap" style="color: var(--text-dark);">' + ev.notes + '</p>'
        : '';

    var descHtml = ev.description
        ? '<hr class="my-4 border-gray-200"><p class="mb-3 font-sans text-xl font-semibold uppercase tracking-widest" style="color: var(--text-dark);"><i class="fa-solid fa-align-left text-primary mr-2"></i>About This Event</p><p class="font-sans text-xl whitespace-pre-wrap" style="color: var(--text-dark);">' + ev.description + '</p>'
        : '';

    var photoHtml = ev.photo_url
        ? '<img src="' + ev.photo_url + '" alt="' + ev.title + '" class="h-56 w-full object-cover">'
        : '<div class="flex h-56 w-full items-center justify-center bg-gray-100"><i class="fa-solid fa-calendar-days text-5xl text-primary opacity-20"></i></div>';

    document.getElementById('ev-modal-inner').innerHTML =
        photoHtml +
        '<div class="p-7">' +
            '<span class="mb-4 inline-flex items-center gap-2 rounded-full border border-green-200 bg-green-50 px-3 py-1.5 font-sans text-xl font-semibold text-green-700 uppercase">' +
                '<i class="fa-solid fa-circle-check text-green-700" style="font-size:.55rem;"></i> Approved Event' +
            '</span>' +
            '<h2 class="mb-4 font-sans text-3xl font-semibold uppercase" style="color: var(--text-dark);">' + ev.title + '</h2>' +
            '<div class="mb-4 space-y-2 font-sans text-xl" style="color: var(--text-dark);">' +
                '<div class="flex gap-3"><i class="fa-solid fa-calendar mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl"><strong>' + ev.event_date + '</strong></span></div>' +
                '<div class="flex gap-3"><i class="fa-solid fa-clock mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl">' + timeHtml + '</span></div>' +
                '<div class="flex gap-3"><i class="fa-solid fa-location-dot mt-1 text-primary flex-shrink-0" style="font-size:0.8rem;"></i><span class="font-sans text-xl">' + ev.venue + (ev.venue_address ? ' · <em style="color: #666;">' + ev.venue_address + '</em>' : '') + '</span></div>' +
                targetHtml +
                organizerHtml +
            '</div>' +
            rsvpHtml +
            descHtml +
            contactHtml +
            notesHtml +
            '<p class="mt-6 font-sans text-right text-xl uppercase tracking-widest" style="color: var(--text-dark);">' +
                '<i class="fa-solid fa-circle-info text-primary mr-2" style="font-size:.6rem;"></i>Posted on ' + ev.created_at +
            '</p>' +
        '</div>';

    document.getElementById('ev-overlay').classList.add('!opacity-100', '!pointer-events-auto');
    document.getElementById('ev-modal-box').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function evCloseModal() {
    document.getElementById('ev-overlay').classList.remove('!opacity-100', '!pointer-events-auto');
    document.body.style.overflow = '';
}

function evCloseOnOverlay(e) {
    if (e.target === document.getElementById('ev-overlay')) evCloseModal();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') evCloseModal();
});
</script>

@include('layouts.footer')

@endsection