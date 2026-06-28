@extends('layouts.public')

@section('content')
@include('layouts.header')

@php
    use App\Models\AdminEvent;
    use Illuminate\Support\Facades\Storage;

    $events = AdminEvent::where('status', 'APPROVED')
        ->where('event_date', '>=', now())
        ->with('organizer')
        ->withCount([
            'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
            'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
            'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
        ])
        ->orderBy('event_date')
        ->get();

    $firstThree = $events->take(3);
    $remaining  = $events->skip(3);
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
            'organizer_name'      => $e->organizer?->name,
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
        flex-direction: column;
        gap: 4px;
        padding: 14px 0;
        border-bottom: 1px solid #F3F0F8;
    }
    .ev-info-row:last-child { border-bottom: none; }

    .ev-card { cursor: pointer; }

    #ev-global-tooltip {
        position: fixed;
        background: #111111;
        color: #ffffff;
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
    #ev-global-tooltip.visible { opacity: 1; }

    .ev-close-btn-wrap {
        position: relative;
        display: inline-flex;
        align-items: center;
    }
    .ev-close-btn-wrap .ev-close-tip {
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        background: #111111;
        color: #ffffff;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 4px 9px;
        border-radius: 6px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 99999;
    }
    .ev-close-btn-wrap:hover .ev-close-tip { opacity: 1; }

    /* Card hover lift */
    .ev-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(122,63,145,.10); }
    .ev-card { transition: transform .25s ease, box-shadow .25s ease; }
</style>

<main class="w-full overflow-x-hidden bg-gray-100">

    {{-- ══ HERO ══ --}}
    <div class="relative bg-white px-6 py-16 text-center sm:py-24">
        <div class="mx-auto max-w-3xl">
            <span class="mb-4 inline-block font-sans text-xs font-semibold tracking-widest text-primary uppercase"
                  data-aos="fade-down" data-aos-duration="600">
                Alumni Events
            </span>
            <h1 class="mb-4 font-sans text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight"
                style="color: var(--text-dark);"
                data-aos="fade-up" data-aos-delay="100" data-aos-duration="700">
                Upcoming <span class="text-primary">Gatherings</span><br class="hidden sm:block">& Reunions
            </h1>
            <p class="mx-auto mb-6 max-w-md font-sans text-base sm:text-lg leading-relaxed text-gray-500"
               data-aos="fade-up" data-aos-delay="200" data-aos-duration="700">
                Stay connected with your alma mater. Join events made just for you.
            </p>
            <div class="mx-auto h-1 w-12 bg-primary" data-aos="fade-up" data-aos-delay="300"></div>
        </div>
        <svg class="absolute bottom-0 left-0 w-full" viewBox="0 0 1200 120" preserveAspectRatio="none" style="height:50px;">
            <path d="M0,40 Q300,80 600,40 T1200,40 L1200,120 L0,120 Z" fill="#f3f4f6"></path>
        </svg>
    </div>

    {{-- ══ EVENTS SECTION ══ --}}
    <div class="mx-auto max-w-7xl px-5 py-12 sm:px-6 md:py-16 lg:px-8">

        @if($events->isEmpty())
            <div class="py-28 text-center" data-aos="fade-up" data-aos-duration="700">
                <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl bg-purple-100">
                    <i class="fa-solid fa-calendar-xmark text-3xl text-primary"></i>
                </div>
                <h2 class="mb-3 font-sans text-2xl font-semibold uppercase" style="color: var(--text-dark);">No Upcoming Events</h2>
                <p class="font-sans text-base text-gray-500 leading-relaxed">
                    There are no upcoming events at the moment.<br>Check back soon for exciting alumni gatherings!
                </p>
            </div>
        @else

            <div class="mb-10 flex items-center gap-4" data-aos="fade-right" data-aos-duration="500">
                <div class="h-px flex-1 bg-gray-300"></div>
                <span class="whitespace-nowrap font-sans text-xs font-semibold tracking-widest text-primary uppercase">
                    Latest Events
                </span>
                <div class="h-px flex-1 bg-gray-300"></div>
            </div>

            {{-- ── FIRST 3 CARDS ── --}}
            <div class="mb-10 grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3" id="ev-grid-main">
                @foreach($firstThree as $i => $event)
                @php $d = $i * 80; @endphp
                <div class="ev-card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                     data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                     onclick="evOpenModal({{ $event->id }})">

                    @if(!empty($event->photo_url))
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="h-60 w-full object-cover">
                    @else
                        <div class="flex h-60 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                            <i class="fa-solid fa-calendar-star text-5xl text-purple-300"></i>
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-4 p-6">
                        <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-2 text-sm font-semibold text-primary">
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}
                        </div>
                        <h3 class="font-sans text-lg font-semibold uppercase tracking-tight leading-snug" style="color: var(--text-dark);">
                            {{ $event->title }}
                        </h3>
                        @if($event->target_participants)
                        <p class="text-sm text-gray-500 font-medium">{{ $event->target_participants }}</p>
                        @endif
                        @if($event->description)
                        <p class="line-clamp-2 text-sm text-gray-400 leading-relaxed">{{ $event->description }}</p>
                        @endif

                        {{-- View Details Button --}}
                        <div class="mt-auto pt-2">
                            <span class="inline-flex items-center gap-2 rounded-xl border-2 border-[#7a3f91] px-4 py-2.5 text-sm font-semibold uppercase tracking-widest text-[#7a3f91] transition-all duration-200 group-hover:bg-[#7a3f91] group-hover:text-white">
                                View Details
                                <i class="fa-solid fa-arrow-right text-xs transition-transform duration-200 group-hover:translate-x-1"></i>
                            </span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── REMAINING CARDS ── --}}
            @if($hasMore)
            <div id="ev-grid-more" style="display:none;">
                <div class="mb-10 mt-12 flex items-center gap-4">
                    <div class="h-px flex-1 bg-gray-300"></div>
                    <span class="whitespace-nowrap font-sans text-xs font-semibold tracking-widest text-primary uppercase">
                        More Events
                    </span>
                    <div class="h-px flex-1 bg-gray-300"></div>
                </div>
                <div class="grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($remaining as $i => $event)
                    @php $d = ($i % 3) * 80; @endphp
                    <div class="ev-card group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                         data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                         onclick="evOpenModal({{ $event->id }})">

                        @if(!empty($event->photo_url))
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                                 class="h-60 w-full object-cover">
                        @else
                            <div class="flex h-60 w-full items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                                <i class="fa-solid fa-calendar-star text-5xl text-purple-300"></i>
                            </div>
                        @endif
                        <div class="flex flex-1 flex-col gap-4 p-6">
                            <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-2 text-sm font-semibold text-primary">
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}
                            </div>
                            <h3 class="font-sans text-lg font-semibold uppercase tracking-tight leading-snug" style="color: var(--text-dark);">
                                {{ $event->title }}
                            </h3>
                            @if($event->target_participants)
                            <p class="text-sm text-gray-500 font-medium">{{ $event->target_participants }}</p>
                            @endif
                            @if($event->description)
                            <p class="line-clamp-2 text-sm text-gray-400 leading-relaxed">{{ $event->description }}</p>
                            @endif

                            {{-- View Details Button --}}
                            <div class="mt-auto pt-2">
                                <span class="inline-flex items-center gap-2 rounded-xl border-2 border-[#7a3f91] px-4 py-2.5 text-sm font-semibold uppercase tracking-widest text-[#7a3f91] transition-all duration-200 group-hover:bg-[#7a3f91] group-hover:text-white">
                                    View Details
                                    <i class="fa-solid fa-arrow-right text-xs transition-transform duration-200 group-hover:translate-x-1"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-10 text-center" data-aos="fade-up" data-aos-delay="150">
                <button class="inline-flex items-center gap-2 rounded-2xl border-2 border-primary px-6 py-3 text-sm font-semibold uppercase tracking-widest text-primary transition-all duration-200 hover:bg-primary hover:text-white"
                        id="ev-more-btn" onclick="evToggleMore()">
                    <i class="fa-solid fa-chevron-down text-inherit transition-transform duration-300" id="ev-more-icon"></i>
                    <span id="ev-more-text">See All {{ $events->count() }} Events</span>
                </button>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- Global cursor-follow tooltip --}}
<div id="ev-global-tooltip">View Details</div>


{{-- ══════════════════════════════════════════════════════════════
     FULL-SCREEN EVENT DETAIL MODAL
══════════════════════════════════════════════════════════════ --}}
<div id="ev-modal"
     class="fixed inset-0 z-[9999] flex flex-col"
     style="display:none !important;"
     role="dialog" aria-modal="true">

    {{-- ─── Header ─────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-5 shrink-0 shadow-md"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="min-w-0">
                <h2 class="text-white font-semibold text-lg leading-tight truncate" id="ev-modal-heading">Event Details</h2>
                <p class="text-white/60 text-sm" style="font-weight:400;">Alumni Events</p>
            </div>
        </div>

        <div class="ev-close-btn-wrap ml-4">
            <button onclick="evCloseModal()"
                    class="flex items-center justify-center w-10 h-10 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white transition-all duration-150 shrink-0">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
            <span class="ev-close-tip">Close</span>
        </div>
    </div>

    {{-- ─── Body ──────────────────────────────────────────────── --}}
    <div class="flex-1 overflow-hidden bg-gray-50">
        <div class="h-full grid grid-cols-1 lg:grid-cols-5">

            {{-- ── LEFT: Image ── --}}
            <div class="lg:col-span-2 flex items-center justify-center overflow-hidden p-6 lg:p-10"
                 style="background:#F0EBF7;">
                <div id="ev-modal-photo-wrap"
                     class="w-full h-full flex items-center justify-center rounded-2xl overflow-hidden"
                     style="max-height: calc(100vh - 200px);">
                </div>
            </div>

            {{-- ── RIGHT: Event details ── --}}
            <div class="lg:col-span-3 flex flex-col h-full overflow-hidden border-l border-[#E8E0F0] bg-white">

                <div class="flex-1 overflow-y-auto px-8 py-8 flex flex-col gap-6"
                     style="scrollbar-width:thin; scrollbar-color:#d4aaeb #f9fafb;">

                    {{-- Date chip + Title --}}
                    <div class="shrink-0">
                        <div class="inline-flex items-center gap-1.5 rounded-lg bg-purple-100 px-4 py-2 text-sm font-semibold text-primary mb-4"
                             id="ev-modal-datechip"></div>
                        <h3 class="text-2xl sm:text-3xl font-bold leading-snug uppercase" style="color: var(--text-dark);"
                            id="ev-modal-title"></h3>
                    </div>

                    <div class="h-px bg-[#F3F0F8] shrink-0"></div>

                    {{-- Event Info rows --}}
                    <div id="ev-modal-info" class="flex flex-col shrink-0"></div>

                    {{-- Description --}}
                    <div id="ev-modal-desc-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-5"></div>
                        <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#1a1a1a;">About This Event</p>
                        <p class="text-base leading-relaxed" style="color:#333333; font-weight:400;" id="ev-modal-desc"></p>
                    </div>

                    {{-- Notes --}}
                    <div id="ev-modal-notes-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-5"></div>
                        <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#1a1a1a;">Additional Notes</p>
                        <p class="text-base leading-relaxed" style="color:#333333; font-weight:400;" id="ev-modal-notes"></p>
                    </div>

                    {{-- ── LOGIN PROMPT ── --}}
                    <div class="shrink-0 mt-2">
                        <div class="h-px bg-[#F3F0F8] mb-6"></div>
                        <div class="rounded-2xl border-2 border-[#7a3f91] bg-purple-50 px-6 py-6 flex flex-col sm:flex-row items-center gap-5">
                            <div class="flex-1 text-center sm:text-left">
                                <p class="text-base font-bold uppercase tracking-wide mb-1" style="color: var(--text-dark);">
                                    Want more information?
                                </p>
                                <p class="text-sm text-gray-500 leading-relaxed">
                                    Login to your alumni account to RSVP, view full event details, and stay connected.
                                </p>
                            </div>
                            <a href="{{ route('login') }}"
                               class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-[#7a3f91] px-6 py-3 text-sm font-bold uppercase tracking-widest text-white transition-all duration-200 hover:bg-[#5e2f72] active:scale-95">
                                Login Now
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>

                </div>

                {{-- Posted meta — pinned bottom --}}
                <div class="shrink-0 border-t border-[#F3F0F8] px-8 py-4 flex items-center gap-2"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <p class="text-sm" style="color:#888888; font-weight:400;" id="ev-modal-footer-meta"></p>
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

    // Header
    document.getElementById('ev-modal-heading').textContent = ev.title;

    // Date chip — date only, no time
    document.getElementById('ev-modal-datechip').textContent = ev.event_date;

    // Title
    document.getElementById('ev-modal-title').textContent = ev.title;

    // Photo
    document.getElementById('ev-modal-photo-wrap').innerHTML = ev.photo_url
        ? '<img src="' + ev.photo_url + '" alt="" style="max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;display:block;border-radius:14px;">'
        : '<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:280px;">' +
          '<span style="font-size:1.1rem;color:#aaa;font-weight:500;letter-spacing:.05em;">No Photo Available</span></div>';

    // Info rows — Date + Participants only
    var infoRows = [
        { label: 'Date', val: ev.event_date },
    ];
    if (ev.target_participants)
        infoRows.push({ label: 'Participants', val: ev.target_participants });

    var infoHtml = '';
    infoRows.forEach(function (r) {
        infoHtml +=
            '<div class="ev-info-row">' +
                '<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#888888;line-height:1.2;">' + r.label + '</p>' +
                '<p style="font-size:17px;font-weight:600;color:#1a1a1a;line-height:1.5;word-break:break-word;">' + r.val + '</p>' +
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
    document.getElementById('ev-modal-footer-meta').textContent = 'Posted ' + ev.created_at;

    // Hide tooltip
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