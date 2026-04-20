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
    /* ══════════════════════════════════════════
       FONT SYSTEM  — same as Home & About
       Label  → Courier New, 700, 0.8rem, #7a3f91
       Title  → Courier New, 900, uppercase, #2b0d3e
       Body   → Georgia, serif, 1.05–1.15rem, #4a4056
    ══════════════════════════════════════════ */

    html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }
    body { background: #f9f7fc; }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #f9f7fc; }
    ::-webkit-scrollbar-thumb { background: #2b0d3e; border-radius: 10px; }

    [data-aos] { transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important; }

    /* ── SHARED FONT CLASSES ── */
    .ev-label {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;              /* 12.8px */
        font-weight: 700;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: #7a3f91;
        display: block;
    }
    .ev-title {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -0.02em;
        line-height: 1.08;
        color: #2b0d3e;
    }
    .ev-body {
        font-family: 'Georgia', serif;
        font-size: 1.05rem;             /* 16.8px */
        line-height: 1.85;
        color: #4a4056;
    }

    /* ── HERO — pure solid color, no gradient ── */
    .ev-hero {
        background: #2b0d3e;
        padding: 5rem 1.5rem 6rem;
        text-align: center;
        position: relative;
    }
    /* Simple bottom wave shape — pure CSS, no SVG, no gradient */
    .ev-hero::after {
        content: '';
        position: absolute;
        bottom: -1px; left: 0; right: 0;
        height: 52px;
        background: #f9f7fc;
        clip-path: ellipse(55% 100% at 50% 100%);
    }
    .ev-hero-label {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: rgba(255,255,255,0.55);
        display: block;
        margin-bottom: 1.25rem;
    }
    .ev-hero-title {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        font-size: clamp(2.2rem, 5vw, 3.6rem);
        text-transform: uppercase;
        letter-spacing: -0.02em;
        line-height: 1.08;
        color: #ffffff;
        margin-bottom: 1rem;
    }
    .ev-hero-title span { color: #c59dd9; }
    .ev-hero-sub {
        font-family: 'Georgia', serif;
        font-size: 1.1rem;
        color: rgba(255,255,255,0.60);
        line-height: 1.75;
        max-width: 420px;
        margin: 0 auto;
    }
    .ev-hero-accent {
        width: 3rem; height: 2px;
        background: #7a3f91;
        margin: 1rem auto 0;
    }

    /* ── SECTION ── */
    .ev-section {
        max-width: 1160px;
        margin: 0 auto;
        padding: 3.5rem 1.25rem 5rem;
    }

    /* ── DIVIDER — same style as Home/About ── */
    .ev-divider {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .ev-divider-line { flex: 1; height: 1.5px; background: #e0d5ee; }
    .ev-divider-text {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: #7a3f91;
        white-space: nowrap;
    }

    /* ── GRID ── */
    .ev-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.75rem;
    }

    /* ── CARD ── */
    .ev-card {
        background: #ffffff;
        border: 1.5px solid #e8e0f0;
        border-radius: 1.5rem;          /* 24px — same as home/about cards */
        overflow: hidden;
        display: flex;
        flex-direction: column;
        cursor: pointer;
        transition: box-shadow 0.3s ease, transform 0.3s ease,
                    border-color 0.3s, background 0.3s;
        position: relative;
    }
    .ev-card:hover {
        background: #f3ecfa;            /* bright lavender — same as home hover */
        box-shadow: 0 14px 45px rgba(122,63,145,0.14);
        transform: translateY(-5px);
        border-color: #b87fd4;
    }

    /* Card cover image */
    .ev-card-cover {
        width: 100%; height: 196px;
        object-fit: cover;
        display: block;
        flex-shrink: 0;
    }
    .ev-card-cover-empty {
        width: 100%; height: 196px;
        background: #f3ecfa;            /* pure color — no gradient */
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* Approved pill */
    .ev-card-pill {
        position: absolute;
        top: 12px; left: 12px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #047857;
        font-family: 'Courier New', monospace;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding: 0.28rem 0.75rem;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    /* Card body */
    .ev-card-body {
        padding: 1.4rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
    }

    /* Date chip */
    .ev-date-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: #f3ecfa;            /* pure color */
        color: #7a3f91;
        font-family: 'Courier New', monospace;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.3rem 0.7rem;
        border-radius: 8px;
        width: fit-content;
    }

    /* Card title */
    .ev-card-title {
        font-family: 'Courier New', monospace;
        font-size: 1.08rem;             /* ~17px */
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -0.01em;
        color: #2b0d3e;
        line-height: 1.3;
    }

    /* Card meta rows */
    .ev-card-meta { display: flex; flex-direction: column; gap: 0.35rem; }
    .ev-card-meta-row {
        display: flex;
        align-items: flex-start;
        gap: 0.45rem;
        font-family: 'Georgia', serif;
        font-size: 0.85rem;
        color: #6b7280;
    }
    .ev-card-meta-row i {
        color: #7a3f91;
        margin-top: 0.1rem;
        flex-shrink: 0;
        font-size: 0.7rem;
    }

    /* Card description */
    .ev-card-desc {
        font-family: 'Georgia', serif;
        font-size: 0.88rem;
        color: #4b5563;
        line-height: 1.6;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* RSVP chips */
    .ev-rsvp-row {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
        padding-top: 0.55rem;
        border-top: 1.5px solid #e8e0f0;
    }
    .ev-rsvp-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-family: 'Courier New', monospace;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.22rem 0.6rem;
        border-radius: 7px;
    }
    .chip-go  { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .chip-no  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .chip-mby { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

    /* View details button */
    .ev-btn-view {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        background: #7a3f91;
        color: #ffffff;
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 0.65rem 1.1rem;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        transition: background 0.25s, transform 0.2s;
        margin-top: auto;
    }
    .ev-btn-view:hover { background: #5e2f72; transform: translateY(-1px); color: #fff; }

    /* ── SHOW MORE button ── */
    .ev-show-more-wrap { text-align: center; margin-top: 2.5rem; }
    .ev-show-more-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        font-family: 'Courier New', monospace;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #7a3f91;
        background: #ffffff;
        border: 1.5px solid #7a3f91;
        border-radius: 14px;
        padding: 0.75rem 2rem;
        cursor: pointer;
        transition: background 0.25s, color 0.25s;
    }
    .ev-show-more-btn:hover { background: #7a3f91; color: #fff; }
    .ev-show-more-btn i { transition: transform 0.3s; }
    .ev-show-more-btn.open i { transform: rotate(180deg); }

    /* ── EMPTY STATE ── */
    .ev-empty { text-align: center; padding: 5rem 1.5rem; }
    .ev-empty-icon {
        width: 76px; height: 76px;
        border-radius: 1.5rem;
        background: #f3ecfa;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 1.9rem;
        color: #7a3f91;
    }
    .ev-empty h2 {
        font-family: 'Courier New', monospace;
        font-size: 1.5rem;
        font-weight: 900;
        text-transform: uppercase;
        color: #2b0d3e;
        margin-bottom: 0.5rem;
    }
    .ev-empty p {
        font-family: 'Georgia', serif;
        font-size: 1.05rem;
        color: #9ca3af;
        line-height: 1.75;
    }

    /* ── MODAL OVERLAY ── */
    .ev-overlay {
        position: fixed; inset: 0; z-index: 9999;
        background: rgba(43,13,62,0.65);
        backdrop-filter: blur(6px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s;
    }
    .ev-overlay.open { opacity: 1; pointer-events: all; }

    .ev-modal-wrap { position: relative; max-width: 640px; width: 100%; }

    .ev-modal {
        background: #fff;
        border-radius: 1.5rem;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 32px 80px rgba(43,13,62,0.25);
        transform: translateY(20px) scale(0.97);
        transition: transform 0.3s;
        scrollbar-width: thin;
        scrollbar-color: #e0d5ee #f9f7fc;
    }
    .ev-overlay.open .ev-modal { transform: translateY(0) scale(1); }

    .ev-modal-close-btn {
        position: absolute; top: 0.85rem; right: 0.85rem; z-index: 2;
        width: 34px; height: 34px;
        border-radius: 50%;
        background: rgba(43,13,62,0.5);
        color: #fff;
        border: none;
        font-size: 1.15rem;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background 0.2s;
    }
    .ev-modal-close-btn:hover { background: rgba(43,13,62,0.8); }

    .ev-modal-img {
        width: 100%; height: 230px;
        object-fit: cover;
        border-radius: 1.5rem 1.5rem 0 0;
        display: block;
    }
    .ev-modal-img-empty {
        width: 100%; height: 160px;
        background: #f3ecfa;            /* pure color */
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 1.5rem 1.5rem 0 0;
        font-size: 2.5rem;
        color: #7a3f91;
        opacity: 0.45;
    }

    .ev-modal-body { padding: 1.75rem 2rem 1.5rem; }

    .ev-modal-title {
        font-family: 'Courier New', monospace;
        font-size: 1.35rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -0.01em;
        color: #2b0d3e;
        margin-bottom: 1rem;
        line-height: 1.25;
    }
    .ev-modal-meta { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
    .ev-modal-meta-row {
        display: flex;
        align-items: flex-start;
        gap: 0.55rem;
        font-family: 'Georgia', serif;
        font-size: 0.9rem;
        color: #374151;
    }
    .ev-modal-meta-row i { color: #7a3f91; margin-top: 0.15rem; width: 15px; flex-shrink: 0; font-size: 0.8rem; }

    .ev-modal-sec-title {
        font-family: 'Courier New', monospace;
        font-size: 0.7rem;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: 0.2em;
        margin: 1.25rem 0 0.55rem;
    }
    .ev-modal-hr { border: none; border-top: 1.5px solid #e8e0f0; margin: 1.1rem 0; }
    .ev-modal-desc {
        font-family: 'Georgia', serif;
        font-size: 0.9rem;
        color: #4b5563;
        line-height: 1.75;
        white-space: pre-wrap;
    }

    /* Modal RSVP grid */
    .ev-modal-rsvp-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.6rem; margin-bottom: 0.5rem; }
    .ev-modal-rsvp-card { border-radius: 13px; padding: 0.9rem 0.5rem; text-align: center; }
    .ev-modal-rsvp-card .num {
        font-family: 'Courier New', monospace;
        font-size: 1.65rem;
        font-weight: 900;
        line-height: 1;
    }
    .ev-modal-rsvp-card .lbl {
        font-family: 'Courier New', monospace;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-top: 0.25rem;
    }
    .rc-go  { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; }
    .rc-no  { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
    .rc-mby { background: #fffbeb; border: 1px solid #fde68a; color: #d97706; }

    @media (max-width: 480px) {
        .ev-modal-body { padding: 1.25rem; }
    }
</style>

<main class="w-full overflow-x-hidden" style="background:#f9f7fc;">

    {{-- ══ HERO — pure solid #2b0d3e, no gradient ══ --}}
    <div class="ev-hero">
        <span class="ev-hero-label" data-aos="fade-down" data-aos-duration="600">
            <i class="fa-solid fa-calendar-star" style="font-size:0.65rem; margin-right:0.4rem;"></i>
            Alumni Events
        </span>
        <h1 class="ev-hero-title" data-aos="fade-up" data-aos-delay="100" data-aos-duration="700">
            Upcoming <span>Gatherings</span><br>& Reunions
        </h1>
        <p class="ev-hero-sub" data-aos="fade-up" data-aos-delay="200" data-aos-duration="700">
            Stay connected with your alma mater. Join events made just for you.
        </p>
        <div class="ev-hero-accent" data-aos="fade-up" data-aos-delay="300"></div>
    </div>

    {{-- ══ EVENTS SECTION ══ --}}
    <div class="ev-section">

        @if($events->isEmpty())

            {{-- Empty state --}}
            <div class="ev-empty" data-aos="fade-up" data-aos-duration="700">
                <div class="ev-empty-icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <h2>No Events Yet</h2>
                <p>There are no upcoming events at the moment.<br>Check back soon for exciting alumni gatherings!</p>
            </div>

        @else

            {{-- Section divider label --}}
            <div class="ev-divider" data-aos="fade-right" data-aos-duration="500">
                <div class="ev-divider-line"></div>
                <span class="ev-divider-text">
                    <i class="fa-solid fa-fire-flame-curved" style="margin-right:0.3rem;"></i> Latest Events
                </span>
                <div class="ev-divider-line"></div>
            </div>

            {{-- ── FIRST 5 CARDS ── --}}
            <div class="ev-grid" id="ev-grid-main">
                @foreach($firstFive as $i => $event)
                @php $d = $i * 80; @endphp
                <div class="ev-card"
                     data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                     onclick="evOpenModal({{ $event->id }})">

                    {{-- Approved badge --}}
                    <span class="ev-card-pill">
                        <i class="fa-solid fa-circle-check" style="font-size:0.55rem;"></i> Approved
                    </span>

                    {{-- Cover image --}}
                    @if($event->photo && Storage::disk('public')->exists($event->photo))
                        <img src="{{ asset('storage/' . $event->photo) }}"
                             alt="{{ $event->title }}" class="ev-card-cover">
                    @else
                        <div class="ev-card-cover-empty">
                            <i class="fa-solid fa-calendar-days"
                               style="font-size:2.2rem; color:#7a3f91; opacity:0.35;"></i>
                        </div>
                    @endif

                    <div class="ev-card-body">

                        {{-- Date chip --}}
                        <div class="ev-date-chip">
                            <i class="fa-solid fa-calendar" style="font-size:0.65rem;"></i>
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                            &nbsp;·&nbsp;
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                        </div>

                        <h3 class="ev-card-title">{{ $event->title }}</h3>

                        <div class="ev-card-meta">
                            <div class="ev-card-meta-row">
                                <i class="fa-solid fa-location-dot"></i>
                                <span>{{ $event->venue }}@if($event->venue_address), {{ $event->venue_address }}@endif</span>
                            </div>
                            @if($event->target_participants)
                            <div class="ev-card-meta-row">
                                <i class="fa-solid fa-users"></i>
                                <span>{{ $event->target_participants }}</span>
                            </div>
                            @endif
                            @if($event->organizer)
                            <div class="ev-card-meta-row">
                                <i class="fa-solid fa-user-tie"></i>
                                <span>{{ $event->organizer->name }}</span>
                            </div>
                            @else
                            <div class="ev-card-meta-row">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span style="font-family:'Courier New',monospace; font-weight:700; color:#7a3f91;">Posted by Admin</span>
                            </div>
                            @endif
                        </div>

                        @if($event->description)
                        <p class="ev-card-desc">{{ $event->description }}</p>
                        @endif

                        @php $total = $event->confirmed_count + $event->declined_count + $event->tentative_count; @endphp
                        @if($total > 0)
                        <div class="ev-rsvp-row">
                            <span class="ev-rsvp-chip chip-go">
                                <i class="fa-solid fa-circle-check" style="font-size:0.6rem;"></i>
                                {{ $event->confirmed_count }} Going
                            </span>
                            <span class="ev-rsvp-chip chip-mby">
                                <i class="fa-solid fa-circle-question" style="font-size:0.6rem;"></i>
                                {{ $event->tentative_count }} Maybe
                            </span>
                            <span class="ev-rsvp-chip chip-no">
                                <i class="fa-solid fa-circle-xmark" style="font-size:0.6rem;"></i>
                                {{ $event->declined_count }} Can't Go
                            </span>
                        </div>
                        @endif

                        <button class="ev-btn-view"
                                onclick="event.stopPropagation(); evOpenModal({{ $event->id }})">
                            <i class="fa-solid fa-arrow-right" style="font-size:0.7rem;"></i>
                            View Details
                        </button>

                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── REMAINING CARDS (hidden) ── --}}
            @if($hasMore)
            <div id="ev-grid-more" style="display:none;">

                <div class="ev-divider" style="margin-top:2.75rem;">
                    <div class="ev-divider-line"></div>
                    <span class="ev-divider-text">
                        <i class="fa-solid fa-calendar-days" style="margin-right:0.3rem;"></i> More Events
                    </span>
                    <div class="ev-divider-line"></div>
                </div>

                <div class="ev-grid">
                    @foreach($remaining as $i => $event)
                    @php $d = ($i % 5) * 80; @endphp
                    <div class="ev-card"
                         data-aos="fade-up" data-aos-delay="{{ $d }}" data-aos-duration="600"
                         onclick="evOpenModal({{ $event->id }})">

                        <span class="ev-card-pill">
                            <i class="fa-solid fa-circle-check" style="font-size:0.55rem;"></i> Approved
                        </span>

                        @if($event->photo && Storage::disk('public')->exists($event->photo))
                            <img src="{{ asset('storage/' . $event->photo) }}"
                                 alt="{{ $event->title }}" class="ev-card-cover">
                        @else
                            <div class="ev-card-cover-empty">
                                <i class="fa-solid fa-calendar-days"
                                   style="font-size:2.2rem; color:#7a3f91; opacity:0.35;"></i>
                            </div>
                        @endif

                        <div class="ev-card-body">

                            <div class="ev-date-chip">
                                <i class="fa-solid fa-calendar" style="font-size:0.65rem;"></i>
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}
                                &nbsp;·&nbsp;
                                {{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}
                            </div>

                            <h3 class="ev-card-title">{{ $event->title }}</h3>

                            <div class="ev-card-meta">
                                <div class="ev-card-meta-row">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <span>{{ $event->venue }}@if($event->venue_address), {{ $event->venue_address }}@endif</span>
                                </div>
                                @if($event->target_participants)
                                <div class="ev-card-meta-row">
                                    <i class="fa-solid fa-users"></i>
                                    <span>{{ $event->target_participants }}</span>
                                </div>
                                @endif
                                @if($event->organizer)
                                <div class="ev-card-meta-row">
                                    <i class="fa-solid fa-user-tie"></i>
                                    <span>{{ $event->organizer->name }}</span>
                                </div>
                                @else
                                <div class="ev-card-meta-row">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <span style="font-family:'Courier New',monospace; font-weight:700; color:#7a3f91;">Posted by Admin</span>
                                </div>
                                @endif
                            </div>

                            @if($event->description)
                            <p class="ev-card-desc">{{ $event->description }}</p>
                            @endif

                            @php $total = $event->confirmed_count + $event->declined_count + $event->tentative_count; @endphp
                            @if($total > 0)
                            <div class="ev-rsvp-row">
                                <span class="ev-rsvp-chip chip-go">
                                    <i class="fa-solid fa-circle-check" style="font-size:0.6rem;"></i>
                                    {{ $event->confirmed_count }} Going
                                </span>
                                <span class="ev-rsvp-chip chip-mby">
                                    <i class="fa-solid fa-circle-question" style="font-size:0.6rem;"></i>
                                    {{ $event->tentative_count }} Maybe
                                </span>
                                <span class="ev-rsvp-chip chip-no">
                                    <i class="fa-solid fa-circle-xmark" style="font-size:0.6rem;"></i>
                                    {{ $event->declined_count }} Can't Go
                                </span>
                            </div>
                            @endif

                            <button class="ev-btn-view"
                                    onclick="event.stopPropagation(); evOpenModal({{ $event->id }})">
                                <i class="fa-solid fa-arrow-right" style="font-size:0.7rem;"></i>
                                View Details
                            </button>

                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Show more / show less button --}}
            <div class="ev-show-more-wrap" data-aos="fade-up" data-aos-delay="150">
                <button class="ev-show-more-btn" id="ev-more-btn" onclick="evToggleMore()">
                    <i class="fa-solid fa-chevron-down"></i>
                    <span id="ev-more-text">See All {{ $events->count() }} Events</span>
                </button>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- ══ MODAL ══ --}}
<div class="ev-overlay" id="ev-overlay" onclick="evCloseOnOverlay(event)">
    <div class="ev-modal-wrap">
        <button class="ev-modal-close-btn" onclick="evCloseModal()">×</button>
        <div class="ev-modal" id="ev-modal-box">
            <div id="ev-modal-inner">
                <div style="padding:3rem; text-align:center; color:#9ca3af;">
                    <i class="fa-solid fa-spinner fa-spin"
                       style="font-size:1.5rem; display:block; margin-bottom:0.6rem; color:#7a3f91;"></i>
                    <span style="font-family:'Courier New',monospace; font-size:0.8rem; letter-spacing:0.1em;">
                        Loading…
                    </span>
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
    evMoreOpen = !evMoreOpen;
    if (evMoreOpen) {
        more.style.display = 'block';
        btn.classList.add('open');
        txt.textContent = 'Show Less';
        if (typeof AOS !== 'undefined') AOS.refresh();
        setTimeout(function() {
            more.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 80);
    } else {
        more.style.display = 'none';
        btn.classList.remove('open');
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
            '<p class="ev-modal-sec-title"><i class="fa-solid fa-chart-bar" style="margin-right:.3rem;"></i> Attendee Responses</p>' +
            '<div class="ev-modal-rsvp-grid">' +
                '<div class="ev-modal-rsvp-card rc-go"><div class="num">'  + ev.confirmed_count + '</div><div class="lbl">Going</div></div>'    +
                '<div class="ev-modal-rsvp-card rc-mby"><div class="num">' + ev.tentative_count + '</div><div class="lbl">Maybe</div></div>'    +
                '<div class="ev-modal-rsvp-card rc-no"><div class="num">'  + ev.declined_count  + '</div><div class="lbl">Can\'t Go</div></div>' +
            '</div>';
    }

    var timeHtml = ev.end_time
        ? ev.start_time + ' <span style="color:#9ca3af;">–</span> ' + ev.end_time
        : ev.start_time;

    var organizerHtml = ev.organizer_name
        ? '<div class="ev-modal-meta-row"><i class="fa-solid fa-user-tie"></i><span>' + ev.organizer_name + (ev.organizer_dept ? ' · ' + ev.organizer_dept : '') + '</span></div>'
        : '<div class="ev-modal-meta-row"><i class="fa-solid fa-shield-halved"></i><span style="font-family:\'Courier New\',monospace; font-weight:700; color:#7a3f91;">Posted by Admin</span></div>';

    var targetHtml = ev.target_participants
        ? '<div class="ev-modal-meta-row"><i class="fa-solid fa-users"></i><span>' + ev.target_participants + '</span></div>'
        : '';

    var contactHtml = '';
    if (ev.contact_person || ev.contact_email || ev.contact_phone) {
        contactHtml = '<hr class="ev-modal-hr"><p class="ev-modal-sec-title"><i class="fa-solid fa-address-card" style="margin-right:.3rem;"></i> Contact</p><div class="ev-modal-meta">';
        if (ev.contact_person) contactHtml += '<div class="ev-modal-meta-row"><i class="fa-solid fa-user"></i><span>'     + ev.contact_person + '</span></div>';
        if (ev.contact_email)  contactHtml += '<div class="ev-modal-meta-row"><i class="fa-solid fa-envelope"></i><a href="mailto:' + ev.contact_email + '" style="color:#7a3f91;">' + ev.contact_email + '</a></div>';
        if (ev.contact_phone)  contactHtml += '<div class="ev-modal-meta-row"><i class="fa-solid fa-phone"></i><span>'    + ev.contact_phone  + '</span></div>';
        contactHtml += '</div>';
    }

    var notesHtml = ev.notes
        ? '<hr class="ev-modal-hr"><p class="ev-modal-sec-title"><i class="fa-solid fa-note-sticky" style="margin-right:.3rem;"></i> Notes</p><p class="ev-modal-desc" style="font-style:italic;">' + ev.notes + '</p>'
        : '';

    var descHtml = ev.description
        ? '<hr class="ev-modal-hr"><p class="ev-modal-sec-title"><i class="fa-solid fa-align-left" style="margin-right:.3rem;"></i> About This Event</p><p class="ev-modal-desc">' + ev.description + '</p>'
        : '';

    var photoHtml = ev.photo_url
        ? '<img src="' + ev.photo_url + '" alt="' + ev.title + '" class="ev-modal-img">'
        : '<div class="ev-modal-img-empty"><i class="fa-solid fa-calendar-days"></i></div>';

    document.getElementById('ev-modal-inner').innerHTML =
        photoHtml +
        '<div class="ev-modal-body">' +
            '<span style="display:inline-flex;align-items:center;gap:.35rem;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;font-family:\'Courier New\',monospace;font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:.28rem .75rem;border-radius:999px;margin-bottom:.8rem;">' +
                '<i class="fa-solid fa-circle-check" style="font-size:.55rem;"></i> Approved Event' +
            '</span>' +
            '<h2 class="ev-modal-title">' + ev.title + '</h2>' +
            '<div class="ev-modal-meta">' +
                '<div class="ev-modal-meta-row"><i class="fa-solid fa-calendar"></i><span><strong>' + ev.event_date + '</strong></span></div>' +
                '<div class="ev-modal-meta-row"><i class="fa-solid fa-clock"></i><span>' + timeHtml + '</span></div>' +
                '<div class="ev-modal-meta-row"><i class="fa-solid fa-location-dot"></i><span>' + ev.venue + (ev.venue_address ? ' · <em style="color:#6b7280;">' + ev.venue_address + '</em>' : '') + '</span></div>' +
                targetHtml +
                organizerHtml +
            '</div>' +
            rsvpHtml +
            descHtml +
            contactHtml +
            notesHtml +
            '<p style="font-family:\'Courier New\',monospace;font-size:.7rem;color:#9ca3af;margin-top:1.5rem;text-align:right;letter-spacing:.08em;">' +
                '<i class="fa-solid fa-circle-info" style="font-size:.6rem;margin-right:.3rem;"></i>Posted on ' + ev.created_at +
            '</p>' +
        '</div>';

    document.getElementById('ev-overlay').classList.add('open');
    document.getElementById('ev-modal-box').scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function evCloseModal() {
    document.getElementById('ev-overlay').classList.remove('open');
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