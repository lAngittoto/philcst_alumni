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
            'notes'               => $e->notes,
            'photo_url'           => $e->photo_url,
            'event_date'          => $e->event_date->setTimezone('Asia/Manila')->format('F d, Y'),
            'start_time'          => $e->event_date->setTimezone('Asia/Manila')->format('g:i A'),
            'end_time'            => $e->event_end_date ? $e->event_end_date->setTimezone('Asia/Manila')->format('g:i A') : null,
            'organizer_name'      => $e->organizer?->name,
            'organizer_dept'      => $e->organizer?->department,
            'created_at'          => $e->created_at->setTimezone('Asia/Manila')->format('M d, Y'),
        ];
    }
@endphp

<style>
    :root {
        --primary-purple: #7a3f91;
        --text-dark: #333333;
    }
    .text-primary   { color: var(--primary-purple); }
    .bg-primary     { background-color: var(--primary-purple); }
    .border-primary { border-color: var(--primary-purple); }

    @keyframes evModalIn {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .ev-modal-enter { animation: evModalIn .2s cubic-bezier(.4,0,.2,1) both; }

    .ev-info-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 9px 0;
        border-bottom: 1px solid #F3F0F8;
    }
    .ev-info-row:last-child { border-bottom: none; }

    .ev-info-icon {
        width: 28px; height: 28px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        background: #F9F7FC;
        border: 1px solid #E8E0F0;
        flex-shrink: 0;
    }

    .ev-section-label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
    }
    .ev-section-dot {
        width: 20px; height: 20px;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg,#7A3F91,#9b59b6);
        flex-shrink: 0;
    }

    /* Clickable card */
    .ev-card {
        cursor: pointer;
    }

    /* Global cursor-follow tooltip */
    #ev-global-tooltip {
        position: fixed;
        background: rgba(122, 63, 145, 0.92);
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 5px 10px;
        border-radius: 8px;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        white-space: nowrap;
        z-index: 99999;
        transform: translate(14px, 14px);
    }
    #ev-global-tooltip.visible {
        opacity: 1;
    }
</style>

<main class="w-full overflow-x-hidden bg-gray-100">

    {{-- ══ HERO ══ --}}
    <div class="relative bg-white px-6 py-16 text-center sm:py-20">
        <div class="mx-auto max-w-3xl">
            <span class="mb-4 inline-block font-sans text-xs font-semibold tracking-widest text-primary uppercase"
                  data-aos="fade-down" data-aos-duration="600">
                <i class="fa-solid fa-calendar-star mr-1 text-primary"></i>
                Alumni Events
            </span>
            <h1 class="mb-3 font-sans text-4xl font-semibold tracking-tight sm:text-5xl"
                style="color: var(--text-dark);"
                data-aos="fade-up" data-aos-delay="100" data-aos-duration="700">
                Upcoming <span class="text-primary">Gatherings</span><br class="hidden sm:block">& Reunions
            </h1>
            <p class="mx-auto mb-4 max-w-md font-sans text-sm leading-relaxed text-gray-500"
               data-aos="fade-up" data-aos-delay="200" data-aos-duration="700">
                Stay connected with your alma mater. Join events made just for you.
            </p>
            <div class="mx-auto h-1 w-10 bg-primary" data-aos="fade-up" data-aos-delay="300"></div>
        </div>
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
                <div class="ev-card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                     onclick="evOpenModal({{ $event->id }})">

                    @if(!empty($event->photo_url))
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="h-56 w-full object-cover">
                    @else
                        <div class="flex h-56 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                            <i class="fa-solid fa-image text-6xl text-primary opacity-15"></i>
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-2 text-xs font-semibold text-primary">
                            <i class="fa-solid fa-calendar text-primary" style="font-size:0.6rem;"></i>
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                        </div>
                        <h3 class="font-sans text-sm font-semibold uppercase tracking-tight leading-tight" style="color: var(--text-dark);">{{ $event->title }}</h3>
                        @if($event->target_participants)
                        <div class="flex gap-2 text-xs text-gray-600">
                            <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.65rem;"></i>
                            <span>{{ $event->target_participants }}</span>
                        </div>
                        @endif
                        @if($event->description)
                        <p class="line-clamp-2 text-xs text-gray-500">{{ $event->description }}</p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── REMAINING CARDS ── --}}
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
                    <div class="ev-card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-all duration-300"
                         data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                         onclick="evOpenModal({{ $event->id }})">

                        @if(!empty($event->photo_url))
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}" class="h-56 w-full object-cover">
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
                            @if($event->target_participants)
                            <div class="flex gap-2 text-xs text-gray-600">
                                <i class="fa-solid fa-users mt-0.5 flex-shrink-0 text-primary" style="font-size:0.65rem;"></i>
                                <span>{{ $event->target_participants }}</span>
                            </div>
                            @endif
                            @if($event->description)
                            <p class="line-clamp-2 text-xs text-gray-500">{{ $event->description }}</p>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-8 text-center" data-aos="fade-up" data-aos-delay="150">
                <button class="inline-flex items-center gap-2 rounded-2xl border-2 border-primary px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-primary transition-all duration-200 hover:bg-primary hover:text-white"
                        id="ev-more-btn" onclick="evToggleMore()">
                    <i class="fa-solid fa-chevron-down text-inherit transition-transform duration-300" id="ev-more-icon"></i>
                    <span id="ev-more-text">See All {{ $events->count() }} Events</span>
                </button>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- Global cursor-follow tooltip (outside cards to avoid overflow-hidden clipping) --}}
<div id="ev-global-tooltip">
    <i class="fa-solid fa-eye mr-1" style="font-size:.6rem;"></i>View Details
</div>


{{-- ══════════════════════════════════════════════════════════════
     FULL-SCREEN EVENT DETAIL MODAL
══════════════════════════════════════════════════════════════ --}}
<div id="ev-modal"
     class="fixed inset-0 z-[9999] flex flex-col"
     style="display:none !important;"
     role="dialog" aria-modal="true">

    {{-- ─── Header ─────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow-md"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-calendar-star text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <h2 class="text-white font-semibold text-base leading-tight truncate" id="ev-modal-heading">Event Details</h2>
                <p class="text-white/60 text-xs" style="font-weight:400;">Alumni Events</p>
            </div>
        </div>
        <button onclick="evCloseModal()"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150 shrink-0 ml-4">
            <i class="fa-solid fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- ─── Body ──────────────────────────────────────────────── --}}
    <div class="flex-1 overflow-hidden bg-gray-50">
        <div class="h-full grid grid-cols-1 lg:grid-cols-5">

            {{-- ── LEFT: Image ── --}}
            <div class="lg:col-span-2 flex items-center justify-center overflow-hidden p-6 lg:p-8"
                 style="background:#F0EBF7;">
                <div id="ev-modal-photo-wrap"
                     class="w-full h-full flex items-center justify-center rounded-2xl overflow-hidden"
                     style="max-height: calc(100vh - 200px);">
                </div>
            </div>

            {{-- ── RIGHT: All event details ── --}}
            <div class="lg:col-span-3 flex flex-col h-full overflow-hidden border-l border-[#E8E0F0] bg-white">

                <div class="flex-1 overflow-y-auto px-7 py-6 flex flex-col gap-5"
                     style="scrollbar-width:thin; scrollbar-color:#d4aaeb #f9fafb;">

                    {{-- Date chip + Title --}}
                    <div class="shrink-0">
                        <div class="inline-flex items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-1.5 text-xs font-semibold text-primary mb-3"
                             id="ev-modal-datechip"></div>
                        <h3 class="text-xl font-semibold leading-snug uppercase" style="color: var(--text-dark);"
                            id="ev-modal-title"></h3>
                    </div>

                    <div class="h-px bg-[#F3F0F8] shrink-0"></div>

                    {{-- Event Info rows --}}
                    <div id="ev-modal-info" class="flex flex-col shrink-0"></div>

                    {{-- Description --}}
                    <div id="ev-modal-desc-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-4"></div>
                        <div class="ev-section-label">
                            <div class="ev-section-dot">
                                <i class="fa-solid fa-align-left text-white" style="font-size:8px;"></i>
                            </div>
                            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#7A3F91;">About This Event</p>
                        </div>
                        <p class="text-sm leading-relaxed text-gray-600" style="font-weight:400;" id="ev-modal-desc"></p>
                    </div>

                    {{-- Notes --}}
                    <div id="ev-modal-notes-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-4"></div>
                        <div class="ev-section-label">
                            <div class="ev-section-dot">
                                <i class="fa-solid fa-note-sticky text-white" style="font-size:8px;"></i>
                            </div>
                            <p class="text-xs font-semibold uppercase tracking-wide" style="color:#7A3F91;">Additional Notes</p>
                        </div>
                        <p class="text-sm leading-relaxed text-gray-600" style="font-weight:400;" id="ev-modal-notes"></p>
                    </div>

                </div>

                {{-- Posted meta — pinned bottom --}}
                <div class="shrink-0 border-t border-[#F3F0F8] px-7 py-3 flex items-center gap-2"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <i class="fa-solid fa-clock-rotate-left text-xs" style="color:#c0a0d8;"></i>
                    <p class="text-xs text-gray-400" style="font-weight:400;" id="ev-modal-footer-meta"></p>
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
// ── SHOW MORE / LESS ──────────────────────────────────────────────
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
        setTimeout(function () { more.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80);
    } else {
        more.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
        txt.textContent = 'See All ' + evTotalCount + ' Events';
        document.getElementById('ev-grid-main').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ── CURSOR-FOLLOW TOOLTIP ─────────────────────────────────────────
var evTooltip = document.getElementById('ev-global-tooltip');

document.querySelectorAll('.ev-card').forEach(function (card) {
    card.addEventListener('mousemove', function (e) {
        evTooltip.style.left = e.clientX + 'px';
        evTooltip.style.top  = e.clientY + 'px';
        evTooltip.classList.add('visible');
    });
    card.addEventListener('mouseleave', function () {
        evTooltip.classList.remove('visible');
    });
});

// ── FULL-SCREEN MODAL ─────────────────────────────────────────────
function evOpenModal(id) {
    var ev = null;
    for (var i = 0; i < EV_DATA.length; i++) {
        if (EV_DATA[i].id === id) { ev = EV_DATA[i]; break; }
    }
    if (!ev) return;

    var timeRange = ev.end_time ? ev.start_time + ' \u2013 ' + ev.end_time : ev.start_time;

    // Header
    document.getElementById('ev-modal-heading').textContent = ev.title;

    // Date chip
    document.getElementById('ev-modal-datechip').innerHTML =
        '<i class="fa-solid fa-calendar" style="font-size:.6rem;"></i>&nbsp;' +
        ev.event_date + '&nbsp;&middot;&nbsp;' + timeRange;

    // Title
    document.getElementById('ev-modal-title').textContent = ev.title;

    // Photo
    document.getElementById('ev-modal-photo-wrap').innerHTML = ev.photo_url
        ? '<img src="' + ev.photo_url + '" alt="" style="max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;display:block;border-radius:12px;">'
        : '<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:240px;">' +
          '<i class="fa-solid fa-image" style="font-size:5rem;color:#c8a0e0;opacity:.3;"></i></div>';

    // Info rows
    var infoRows = [
        { icon: 'fa-calendar',  label: 'Date',         val: ev.event_date },
        { icon: 'fa-clock',     label: 'Time',         val: timeRange },
        { icon: 'fa-map-pin',   label: 'Venue',        val: ev.venue + (ev.venue_address ? ', ' + ev.venue_address : '') },
    ];
    if (ev.target_participants)
        infoRows.push({ icon: 'fa-users',    label: 'Participants', val: ev.target_participants });
    if (ev.organizer_name)
        infoRows.push({ icon: 'fa-user-tie', label: 'Organizer',   val: ev.organizer_name + (ev.organizer_dept ? ' \u2014 ' + ev.organizer_dept : '') });

    var infoHtml = '';
    infoRows.forEach(function (r) {
        infoHtml +=
            '<div class="ev-info-row">' +
                '<div class="ev-info-icon">' +
                    '<i class="fa-solid ' + r.icon + '" style="font-size:.65rem;color:#7A3F91;"></i>' +
                '</div>' +
                '<div style="min-width:0;">' +
                    '<p style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#999;line-height:1.2;">' + r.label + '</p>' +
                    '<p style="font-size:14px;font-weight:600;color:#333;line-height:1.5;word-break:break-word;">' + r.val + '</p>' +
                '</div>' +
            '</div>';
    });
    document.getElementById('ev-modal-info').innerHTML = infoHtml;

    // Description
    var descSection = document.getElementById('ev-modal-desc-section');
    if (ev.description) {
        document.getElementById('ev-modal-desc').textContent = ev.description;
        descSection.style.display = '';
    } else {
        descSection.style.display = 'none';
    }

    // Notes
    var notesSection = document.getElementById('ev-modal-notes-section');
    if (ev.notes) {
        document.getElementById('ev-modal-notes').textContent = ev.notes;
        notesSection.style.display = '';
    } else {
        notesSection.style.display = 'none';
    }

    // Footer meta
    document.getElementById('ev-modal-footer-meta').textContent =
        'Posted ' + ev.created_at +
        (ev.organizer_name ? '  \u00b7  Organized by ' + ev.organizer_name : '');

    // Hide tooltip before opening modal
    evTooltip.classList.remove('visible');

    // Show modal
    var modal = document.getElementById('ev-modal');
    modal.style.removeProperty('display');
    modal.classList.add('ev-modal-enter');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { modal.classList.remove('ev-modal-enter'); }, 250);
}

function evCloseModal() {
    document.getElementById('ev-modal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') evCloseModal();
});
</script>

@include('layouts.footer')

@endsection