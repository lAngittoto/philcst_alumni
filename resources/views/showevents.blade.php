@extends('layouts.public')

@section('content')
@include('layouts.header')

@php
    use App\Models\AdminEvent;
    use Illuminate\Support\Facades\Storage;

    $totalUpcomingCount = AdminEvent::where('status', 'APPROVED')
        ->where('event_date', '>=', now())
        ->count();

    $events = AdminEvent::where('status', 'APPROVED')
        ->where('event_date', '>=', now())
        ->with('organizer')
        ->withCount([
            'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
            'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
            'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
        ])
        ->orderBy('event_date')
        ->take(3)
        ->get();

    $hasMoreEvents = $totalUpcomingCount > 3;

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

    /* Card hover lift */
    .ev-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(122,63,145,.10); }
    .ev-card { transition: transform .25s ease, box-shadow .25s ease; }

    /* ── Bounce reveal — same as home/about pages ── */
    .reveal-bounce {
        opacity: 0;
        transform: translateY(28px) scale(0.90);
        transition: opacity 0.65s cubic-bezier(0.34, 1.56, 0.64, 1),
                    transform 0.65s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .reveal-bounce.is-visible {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    /* ── Glassy cards — same true glass look as home/about pages ── */
    .glass-card {
        position: relative;
        background: linear-gradient(135deg, rgba(255,255,255,0.35), rgba(255,255,255,0.10));
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border: 1.5px solid rgba(255, 255, 255, 0.55);
        box-shadow: 0 8px 32px 0 rgba(122, 63, 145, 0.15),
                    inset 0 1px 1px 0 rgba(255, 255, 255, 0.6);
    }

    /* Event cards now use the glass look instead of solid white */
    .ev-card.glass-card:hover {
        box-shadow: 0 12px 36px rgba(122, 63, 145, 0.20),
                    inset 0 1px 1px 0 rgba(255, 255, 255, 0.6);
    }
</style>

<main class="w-full overflow-x-hidden bg-white">

    {{-- ══ HERO ══ --}}
    <div class="relative bg-white px-6 py-16 text-center sm:py-24">
        <div class="mx-auto max-w-3xl">
            <span class="mb-4 inline-block font-sans text-xs font-semibold tracking-widest text-primary uppercase reveal-bounce" data-reveal>
                Alumni Events
            </span>
            <h1 class="mb-4 font-sans text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight reveal-bounce"
                style="color: var(--text-dark);" data-reveal style="transition-delay:0.1s">
                Upcoming <span class="text-primary">Gatherings</span><br class="hidden sm:block">& Reunions
            </h1>
            <p class="mx-auto mb-6 max-w-md font-sans text-base sm:text-lg leading-relaxed reveal-bounce"
               style="color: var(--text-dark); transition-delay:0.2s" data-reveal>
                Stay connected with your alma mater. Join events made just for you.
            </p>
            <div class="mx-auto h-1 w-12 bg-primary reveal-bounce" style="transition-delay:0.3s" data-reveal></div>
        </div>
    </div>

    {{-- ══ SUBTLE DIVIDER (same style as home/about) ══ --}}
    <div class="px-6 bg-white">
        <div class="max-w-5xl mx-auto border-t-2 border-[#e0e0e0]"></div>
    </div>

    {{-- ══ EVENTS SECTION ══ --}}
    <div class="mx-auto max-w-7xl px-5 py-12 sm:px-6 md:py-16 lg:px-8">

        @if($events->isEmpty())
            <div class="py-28 text-center reveal-bounce" data-reveal>
                <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl bg-purple-100">
                    <i class="fa-solid fa-calendar-xmark text-3xl text-primary"></i>
                </div>
                <h2 class="mb-3 font-sans text-2xl font-semibold uppercase" style="color: var(--text-dark);">No Upcoming Events</h2>
                <p class="font-sans text-base leading-relaxed" style="color: var(--text-dark);">
                    There are no upcoming events at the moment.<br>Check back soon for exciting alumni gatherings!
                </p>
            </div>
        @else

            <div class="mb-10 flex items-center gap-4 reveal-bounce" data-reveal>
                <div class="h-px flex-1 bg-[#e0e0e0]"></div>
                <span class="whitespace-nowrap font-sans text-xs font-semibold tracking-widest text-primary uppercase">
                    Latest Events
                </span>
                <div class="h-px flex-1 bg-[#e0e0e0]"></div>
            </div>

            {{-- ── 3 CARDS MAX ── --}}
            <div class="mb-10 grid grid-cols-1 gap-7 sm:grid-cols-2 lg:grid-cols-3 items-stretch" id="ev-grid-main">
                @foreach($events as $i => $event)
                @php $d = $i * 80; @endphp
                <div class="ev-card glass-card relative flex flex-col overflow-hidden rounded-2xl reveal-bounce h-full"
                     style="transition-delay: {{ $d / 1000 }}s"
                     data-reveal>

                    @if(!empty($event->photo_url))
                        <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                             class="h-44 w-full object-cover shrink-0">
                    @else
                        <div class="flex h-44 w-full shrink-0 items-center justify-center bg-gradient-to-br from-purple-50 to-purple-100">
                            <i class="fa-solid fa-calendar-star text-4xl text-purple-300"></i>
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col gap-3 p-5">
                        <div class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-purple-100 px-3 py-1.5 text-xs font-semibold text-primary">
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}
                        </div>
                        <h3 class="font-sans text-base font-bold uppercase tracking-tight leading-snug line-clamp-2" style="color: var(--text-dark);">
                            {{ $event->title }}
                        </h3>
                        @if($event->target_participants)
                        <p class="text-xs font-semibold line-clamp-1" style="color: var(--text-dark);">
                            <i class="fa-solid fa-users text-primary mr-1"></i>{{ $event->target_participants }}
                        </p>
                        @endif
                        @if($event->description)
                        <p class="line-clamp-3 text-xs leading-relaxed" style="color: var(--text-dark);">{{ $event->description }}</p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- ── LOGIN TO VIEW MORE (shown only when more than 3 upcoming events exist) ── --}}
            @if($hasMoreEvents)
            <div class="glass-card reveal-bounce rounded-2xl px-6 py-8 sm:px-10 sm:py-10 flex flex-col sm:flex-row items-center gap-6 border-2"
                 style="border-color: rgba(122,63,145,0.3);" data-reveal>
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-purple-100">
                    <i class="fa-solid fa-lock text-2xl text-primary"></i>
                </div>
                <div class="flex-1 text-center sm:text-left">
                    <p class="text-base font-bold uppercase tracking-wide mb-1" style="color: var(--text-dark);">
                        There's more happening
                    </p>
                    <p class="text-sm leading-relaxed" style="color:#333333;">
                        Login to your alumni account to view all upcoming events and gatherings.
                    </p>
                </div>
                <a href="{{ route('login') }}"
                   class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-[#7a3f91] px-6 py-3 text-sm font-bold uppercase tracking-widest text-white transition-all duration-200 hover:bg-[#5e2f72] active:scale-95">
                    Login to View More
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>
            @endif

        @endif
    </div>

</main>

{{-- ══════════════════════════════════════════════════════════════
     FULL-SCREEN EVENT DETAIL MODAL (unused — kept out of DOM flow)
══════════════════════════════════════════════════════════════ --}}
<div id="ev-modal" style="display:none !important;" hidden
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
    <div class="flex-1 overflow-hidden bg-white">
        <div class="h-full grid grid-cols-1 lg:grid-cols-5">

            {{-- ── LEFT: Image + Title/Date/Info ── --}}
            <div class="lg:col-span-2 flex flex-col overflow-y-auto p-6 lg:p-8 gap-6"
                 style="background:#F0EBF7; scrollbar-width:thin; scrollbar-color:#d4aaeb #f0ebf7;">

                <div id="ev-modal-photo-wrap"
                     class="w-full flex items-center justify-center rounded-2xl overflow-hidden shrink-0"
                     style="max-height: 320px;">
                </div>

                <div class="shrink-0">
                    <div class="inline-flex items-center gap-1.5 rounded-lg bg-purple-100 px-4 py-2 text-sm font-semibold text-primary mb-4"
                         id="ev-modal-datechip"></div>
                    <h3 class="text-xl sm:text-2xl font-bold leading-snug uppercase" style="color: var(--text-dark);"
                        id="ev-modal-title"></h3>
                </div>

                {{-- Event Info rows --}}
                <div id="ev-modal-info" class="flex flex-col shrink-0"></div>
            </div>

            {{-- ── RIGHT: Event details ── --}}
            <div class="lg:col-span-3 flex flex-col h-full overflow-hidden border-l border-[#E8E0F0] bg-white">

                <div class="flex-1 overflow-y-auto px-8 py-8 flex flex-col gap-6"
                     style="scrollbar-width:thin; scrollbar-color:#d4aaeb #f9fafb;">

                    {{-- Description --}}
                    <div id="ev-modal-desc-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-5"></div>
                        <p class="text-base font-bold uppercase tracking-widest mb-3" style="color:#333333;">About This Event</p>
                        <p class="text-sm leading-relaxed" style="color:#333333; font-weight:400;" id="ev-modal-desc"></p>
                    </div>

                    {{-- Notes --}}
                    <div id="ev-modal-notes-section" style="display:none;" class="shrink-0">
                        <div class="h-px bg-[#F3F0F8] mb-5"></div>
                        <p class="text-base font-bold uppercase tracking-widest mb-3" style="color:#333333;">Additional Notes</p>
                        <p class="text-sm leading-relaxed" style="color:#333333; font-weight:400;" id="ev-modal-notes"></p>
                    </div>

                    {{-- ── LOGIN PROMPT ── --}}
                    <div class="shrink-0 mt-2">
                        <div class="h-px bg-[#F3F0F8] mb-6"></div>
                        <div class="rounded-2xl border-2 border-[#7a3f91] bg-purple-50 px-6 py-6 flex flex-col sm:flex-row items-center gap-5">
                            <div class="flex-1 text-center sm:text-left">
                                <p class="text-base font-bold uppercase tracking-wide mb-1" style="color: var(--text-dark);">
                                    Want more information?
                                </p>
                                <p class="text-sm leading-relaxed" style="color:#333333;">
                                    Login to your alumni account to view full event details and stay connected.
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
                    <p class="text-sm" style="color:#333333; font-weight:400;" id="ev-modal-footer-meta"></p>
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
    function initRevealBounce() {
        // Reset all elements so re-navigation (Livewire SPA) replays them.
        const bounceTargets = document.querySelectorAll('.reveal-bounce[data-reveal]');

        bounceTargets.forEach((el) => el.classList.remove('is-visible'));

        const bounceObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    // One-time only — stop watching once revealed.
                    bounceObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });

        bounceTargets.forEach((el) => {
            // Safety net: reveal immediately if already in viewport on load
            // (e.g. right after a Livewire SPA navigation).
            const rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) {
                el.classList.add('is-visible');
            } else {
                bounceObserver.observe(el);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initRevealBounce);
    document.addEventListener('livewire:navigated', initRevealBounce);
</script>

@include('layouts.footer')

@endsection