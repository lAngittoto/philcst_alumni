<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} - Alumni</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        /* Hide Livewire's default wire:navigate progress bar (nprogress) —
           the thin blue line at the very top of the browser viewport.
           This is Livewire's OWN built-in indicator, separate from any
           custom bar in this file, so it must be killed here explicitly. */
        #livewire-navigate-progress-bar,
        .livewire-progress-bar,
        nprogress,
        #nprogress,
        #nprogress .bar,
        #nprogress .spinner,
        #nprogress .peg {
            display: none !important;
            opacity: 0 !important;
            height: 0 !important;
            pointer-events: none !important;
        }

        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* ── Mobile viewport height fix ── */
        .alm-app-shell {
            height: 100vh;
            height: 100dvh;
        }

        /* ── Sidebar (desktop) bell ── */
        #alumni-bell-btn {
            background: transparent !important;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            padding: 0;
            cursor: pointer;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        #alumni-bell-btn:hover,
        #alumni-bell-btn:focus,
        #alumni-bell-btn:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }

        /* ── Mobile top-bar bell (icon only) ── */
        .alm-topbar-bell {
            background: transparent !important;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            -webkit-tap-highlight-color: transparent;
            appearance: none;
            padding: 6px;
            margin: 0;
            cursor: pointer;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 0;
        }
        .alm-topbar-bell:hover,
        .alm-topbar-bell:focus,
        .alm-topbar-bell:focus-visible,
        .alm-topbar-bell:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
            border: none !important;
        }

        .bell-badge { pointer-events: none; }

        /* ── Notif "bubbles" — replaces the bell-shake wave.
             Small red bubbles rise off the badge and pop, looping, only
             while there are unread notifs. Bell icon itself stays still
             and keeps its normal color (untouched). ── */
        .bell-bubbles {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 42px;
            height: 42px;
            pointer-events: none;
        }
        .bell-bubble {
            position: absolute;
            bottom: 10px;
            right: 12px;
            border-radius: 50%;
            background: #2563EB;
            opacity: 0;
            animation: bellBubbleRise 2.6s ease-in infinite;
        }
        .bell-bubble:nth-child(1) { width: 9px; height: 9px; right: 16px; animation-delay: 0s;    }
        .bell-bubble:nth-child(2) { width: 6px; height: 6px; right: 4px;  animation-delay: 0.55s; }
        .bell-bubble:nth-child(3) { width: 7px; height: 7px; right: 24px; animation-delay: 1.1s;  }
        @keyframes bellBubbleRise {
            0%   { opacity: 0;   transform: translateY(0) scale(0.4); }
            12%  { opacity: 1;   transform: translateY(-5px) scale(1.1); }
            70%  { opacity: 0.6; transform: translateY(-28px) scale(0.9); }
            100% { opacity: 0;   transform: translateY(-38px) scale(0.35); }
        }
        .notif-item { cursor: pointer; position: relative; }

        /* ── In-place loading spinner while a notif click is navigating ──
           Same treatment as the registrar sidebar: the item's own content
           blurs out instead of being fully hidden, and a centered spinner
           overlay fades in on top — so the panel stays open and visibly
           "busy" on the clicked item until the destination page actually
           lands (see the click handler's is-navigating flag +
           livewire:navigated listener below), instead of closing
           immediately on click.

           FIX (glitch on click): the blur/opacity here now transition on
           the SAME property list and duration as the overlay's own fade
           (150ms), instead of relying on the item's `transition-colors`
           (which only tweens background-color) while blur/opacity snapped
           instantly — that mismatch was the visible "jump" the instant
           a notif was clicked. The overlay itself also now fades in via
           x-transition (see the markup) rather than being hard
           inserted/removed by x-if, which was causing a one-frame
           layout flash. ── */
        .notif-item.is-loading > *:not(.notif-item-loading-overlay) {
            filter: blur(4px);
            opacity: 0.5;
            pointer-events: none;
            user-select: none;
            transition: filter 0.15s ease, opacity 0.15s ease;
        }
        .notif-item > *:not(.notif-item-loading-overlay) {
            transition: filter 0.15s ease, opacity 0.15s ease;
        }
        .notif-item-loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
        }
        .notif-item-spinner {
            font-size: 22px;
            color: #7A3F91;
        }

        /* ── Per-notification unread dot — blue, scales up on hover, and
             pulses a soft expanding "wave" ring while unread. Mirrors the
             registrar sidebar's notif-unread-dot exactly. ── */
        .notif-unread-dot {
            position: relative;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #2563EB;
            flex-shrink: 0;
            margin-top: 4px;
            transition: transform 0.15s ease;
        }
        .notif-item:hover .notif-unread-dot {
            transform: scale(1.6);
        }
        .notif-unread-dot::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: #2563EB;
            animation: notif-dot-wave 1.6s ease-out infinite;
        }
        @keyframes notif-dot-wave {
            0%   { transform: scale(1);   opacity: 0.7; }
            100% { transform: scale(2.8); opacity: 0; }
        }

        /* Prevent copy/select of notification text inside the dropdown panel */
        #alumni-notif-panel {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* ── Disable text selection/copy across the ENTIRE sidebar ──
             Covers every label, link, badge, and section inside .alm-sidebar
             (logo, nav items, section labels, collapse/expand button,
             notification bell trigger, profile mini-card, etc). This is on
             top of the #alumni-notif-panel rule above, which already covers
             the notification dropdown panel itself (a separate floating
             element that lives outside .alm-sidebar in the DOM). */
        .alm-sidebar,
        .alm-sidebar * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        /* Text inputs/textareas inside the sidebar (if any, e.g. a search
           box) still need to be selectable/typeable — only block selection
           of static labels/links, not form fields. */
        .alm-sidebar input,
        .alm-sidebar textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

        .notif-close-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .notif-close-tip {
            position: absolute;
            top: calc(100% + 7px);
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a1a;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 4px 10px;
            border-radius: 7px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s ease;
            z-index: 100000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.30);
        }
        .notif-close-tip::after {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-bottom-color: #1a1a1a;
        }
        .notif-close-wrap:hover .notif-close-tip { opacity: 1; }
        @media (max-width: 1023px) {
            .notif-close-tip { display: none !important; }
        }

        /* ════════════════════════════════════════════════════════
           ALUMNI SIDEBAR — GRADUATE / ALUMNI THEME
        ════════════════════════════════════════════════════════ */

        /*
         * FIX SUMMARY (collapse button)
         * ---------------------------------------------------------------
         * No localStorage, no pre-boot script, no multi-property timing
         * choreography. Just: `width` transitions on the sidebar, and
         * `opacity` + `max-width` transition on the text labels, both at
         * the same 0.2s. `justify-content`/`flex`/`gap` are NOT animated
         * (they can't be tweened smoothly by browsers anyway — animating
         * them was pure visual noise that could look like a stray
         * "half state"). Icon re-centering happens instantly via the
         * `.is-collapsed` class the moment the boolean flips.
         */
        .alm-sidebar {
            width: 18rem;
            min-width: 18rem;
            transition:
                width 0.2s ease,
                min-width 0.2s ease,
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* ── Kill the transition on first paint / hard refresh ────────
           Without this, the sidebar paints in its default (expanded,
           untranslated) state for one frame before Alpine applies
           is-collapsed / translate-x-0 — and because the transition
           above is already active, the browser ANIMATES that jump
           instead of snapping straight to the correct state. This
           class is only present until Alpine finishes initializing
           (removed in the x-init below), so the very first state is
           always instant, and only USER-triggered toggles afterward
           get the smooth transition. Same fix as the registrar sidebar. */
        .alm-sidebar.no-transition {
            transition: none !important;
        }

        .alm-sidebar-header {
            background: #7A3F91;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
        }
        .alm-header-inner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            flex: 1;
            position: relative;
            z-index: 10;
        }
        .alm-cap-badge {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.22);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            backdrop-filter: blur(2px);
        }

        .alm-nav-link {
            position: relative;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .alm-nav-link:not(.is-active):hover {
            background: #FAF6FE;
        }
        .alm-nav-link:not(.is-active):hover .alm-nav-icon {
            transform: scale(1.07);
        }
        .alm-nav-link.is-active {
            background: #F3EBFA;
            border: 1px solid #E0CFEE;
        }
        .alm-nav-icon { transition: transform 0.2s ease; position: relative; }

        /* ── Nav link click spinner ──────────────────────────────
           Same visual language/behavior as the registrar sidebar's
           loading spinner (fa-spinner fa-spin, brand purple) — icon
           and notif colors/design stay exactly as-is, only the
           loading-on-click effect is added. Expanded sidebar: sits
           at the end of the row (where the active dot sits), icon
           stays visible. Collapsed sidebar / mobile: centered on
           top of the icon chip, icon hidden, since there's no
           label/dot row to show it in. */
        .alm-nav-spinner {
            flex-shrink: 0;
            margin-left: auto;
            font-size: 13px;
            color: #7A3F91;
            line-height: 1;
        }
        .alm-nav-spinner-icon-anchored { display: none; }

        /* Icon-only sidebar states: collapsed desktop rail (≥1024px)
           AND mobile/tablet (<1024px, always icon-only here). Both
           get the icon-anchored spinner dead-centered on the chip,
           end-of-row spinner hidden, and the icon itself hidden so
           nothing peeks out from underneath the spinner. */
        .alm-sidebar.is-collapsed .alm-nav-link.is-navigating > .alm-nav-spinner,
        .alm-nav-link.is-navigating > .alm-nav-spinner {
            display: none !important;
        }
        .alm-sidebar.is-collapsed .alm-nav-link.is-navigating .alm-nav-spinner-icon-anchored,
        .alm-nav-link.is-navigating .alm-nav-spinner-icon-anchored {
            display: flex !important;
            align-items: center;
            justify-content: center;
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            font-size: 16px !important;
        }
        .alm-sidebar.is-collapsed .alm-nav-link.is-navigating .alm-nav-icon i.fa-solid,
        .alm-nav-link.is-navigating .alm-nav-icon i.fa-solid {
            display: none !important;
        }
        /* On expanded desktop (not collapsed), the end-of-row spinner
           is the one that should show, with the icon staying visible. */
        @media (min-width: 1024px) {
            .alm-sidebar:not(.is-collapsed) .alm-nav-link.is-navigating > .alm-nav-spinner {
                display: flex !important;
            }
            .alm-sidebar:not(.is-collapsed) .alm-nav-link.is-navigating .alm-nav-spinner-icon-anchored {
                display: none !important;
            }
            .alm-sidebar:not(.is-collapsed) .alm-nav-link.is-navigating .alm-nav-icon i.fa-solid {
                display: inline-block !important;
            }
        }

        /* ── Fade/width-collapsible text (labels, brand text, etc.) ──
           Only opacity + max-width transition, same 0.2s duration as the
           sidebar's own width transition, so they finish together. ── */
        .alm-collapsible-text {
            opacity: 1;
            max-width: 220px;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s ease, max-width 0.2s ease;
        }

        /* ── MENU label row + inline collapse icon-button (desktop) ── */
        .alm-nav-section-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }
        .alm-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #9A8AA8;
        }
        .alm-collapse-icon-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 7px;
            background: #F3EBFA;
            border: none;
            color: #7A3F91;
            cursor: pointer;
            font-size: 10px;
            flex-shrink: 0;
            transition: background-color 0.15s ease, transform 0.15s ease;
        }
        .alm-collapse-icon-btn:hover { background: #E9D8F5; }
        .alm-collapse-icon-btn:active { transform: scale(0.88); }
        .alm-collapse-icon-btn i {
            /* icon swap is instant — no separate fade so the arrow direction
               never lags behind the sidebar's collapsed/expanded state */
            pointer-events: none;
        }

        /* Logout button + spinner (registrar style) */
        .alm-logout-btn {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            padding: 0.9rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #fff;
            background: #7A3F91;
            border: none;
            cursor: pointer;
            overflow: hidden;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .alm-logout-btn:hover   { background: #6A3580; }
        .alm-logout-btn:active  { transform: scale(0.97); }
        .alm-logout-btn:disabled { cursor: not-allowed; background: #8E5DA3; }
        .alm-logout-spinner {
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            animation: alm-spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes alm-spin { to { transform: rotate(360deg); } }
        .alm-logout-text-swap { display: inline-flex; align-items: center; }

        /* ── Collapsed state (desktop only, manual << >> toggle) ──
           Relies on `.alm-collapsible-text` (opacity + max-width transition,
           now perfectly in sync with the sidebar width transition — same
           duration/easing on both) instead of `display:none !important`
           for labels, so everything animates together and settles after
           the FIRST click every time. ── */
        @media (min-width: 1024px) {
            .alm-sidebar.is-collapsed {
                width: 5rem !important;
                min-width: 5rem !important;
            }

            .alm-sidebar.is-collapsed .alm-collapsible-text {
                opacity: 0;
                max-width: 0;
                margin-left: 0 !important;
                margin-right: 0 !important;
                pointer-events: none;
            }

            .alm-sidebar.is-collapsed .alm-sidebar-header {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            .alm-sidebar.is-collapsed .alm-header-inner {
                flex: 0 0 auto;
                justify-content: center;
                gap: 0;
            }
            .alm-sidebar.is-collapsed .alm-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            .alm-sidebar.is-collapsed .alm-nav-icon {
                margin-right: 0 !important;
            }
            .alm-sidebar.is-collapsed nav.flex-1 {
                padding-left: 0;
                padding-right: 0;
            }
            .alm-sidebar.is-collapsed .alm-nav-section-row {
                justify-content: center;
                padding: 0;
                position: relative;
            }
            /* The collapse-toggle icon must land in EXACTLY the same
               horizontal spot as the graduation-cap badge above it, and
               must NOT drift/recenter along with the row when the label
               fades out — it should stay fixed in that one spot before
               and after collapsing (only the label disappears). The cap
               badge is centered relative to the collapsed sidebar's own
               width (5rem, padding stripped to 0 — see .alm-sidebar-header
               above), so this icon is centered the same way: relative to
               the row's own full width, which spans the same 5rem rail
               once the row's left/right padding is removed. Matching
               paddings (both 0 here) is what keeps the two icons in the
               same column instead of one sitting on a wider effective
               center than the other. */
            .alm-sidebar.is-collapsed .alm-nav-section-row .alm-collapse-icon-btn {
                position: absolute;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                margin: 0;
            }
            .alm-sidebar.is-collapsed .alm-logout-btn {
                gap: 0;
                padding: 0.9rem;
            }
            .alm-sidebar.is-collapsed .alm-logout-btn i.fa-right-from-bracket,
            .alm-sidebar.is-collapsed .alm-logout-spinner {
                margin-right: 0 !important;
            }
        }

        @media (max-width: 1023px) {
            #alumni-sidebar-aside {
                box-shadow: 0 0 60px rgba(0,0,0,0.18);
                width: 5.5rem;
                min-width: 5.5rem;
            }

            /* Hide the purple header block (cap icon + AlumniPortal / Graduate Network) on mobile */
            #alumni-sidebar-aside .alm-sidebar-header {
                display: none !important;
            }

            /* Icon-only nav on mobile: hide labels + section title + active dot */
            #alumni-sidebar-aside .alm-section-label,
            #alumni-sidebar-aside .alm-nav-section-row,
            #alumni-sidebar-aside .alm-nav-link span:not(.alm-nav-icon) {
                display: none !important;
            }
            #alumni-sidebar-aside .alm-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            #alumni-sidebar-aside .alm-nav-icon {
                margin-right: 0 !important;
            }

            /* Icon-only logout button on mobile */
            #alumni-sidebar-aside .alm-logout-btn {
                gap: 0;
                padding: 1rem;
            }
            #alumni-sidebar-aside .alm-logout-text-swap span:not(.alm-logout-spinner) {
                display: none;
            }
            #alumni-sidebar-aside .alm-logout-btn i.fa-right-from-bracket,
            #alumni-sidebar-aside .alm-logout-spinner {
                margin-right: 0 !important;
            }

            /* Mobile is always icon-only regardless of .is-collapsed, so
               the nav spinner must force the icon-anchored treatment here
               too — same rule shape as the ≥1024px .is-collapsed case. */
            #alumni-sidebar-aside .alm-nav-link.is-navigating > .alm-nav-spinner {
                display: none !important;
            }
            #alumni-sidebar-aside .alm-nav-link.is-navigating .alm-nav-spinner-icon-anchored {
                display: flex !important;
                align-items: center;
                justify-content: center;
                position: absolute !important;
                top: 50% !important;
                left: 50% !important;
                transform: translate(-50%, -50%) !important;
                font-size: 16px !important;
            }
            #alumni-sidebar-aside .alm-nav-link.is-navigating .alm-nav-icon i.fa-solid {
                display: none !important;
            }
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATION PANEL
        ════════════════════════════════════════════════════════ */
        .notif-icon-wrap {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* ── Read/Unread section divider (registrar style) ── */
        .notif-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px 6px;
        }
        .notif-divider::before,
        .notif-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #ECE2F8;
        }
        .notif-divider-label {
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #B9A6C7;
            white-space: nowrap;
        }

        /* ── Delete icon (only shown once a notif is 30+ days old) ──
           Registrar-style: sits at the end of the time row, next to the
           timestamp. Red icon, deeper red + light-red bg on hover. ── */
        .alm-notif-delete-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: none;
            background: transparent;
            color: #DC2626;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color .15s ease, color .15s ease;
        }
        .alm-notif-delete-btn:hover {
            background: #FDE8E8;
            color: #B91C1C;
        }
        .alm-notif-delete-btn i { font-size: .95rem; pointer-events: none; }
        .alm-notif-delete-btn.is-deleting {
            cursor: default;
            color: #B91C1C;
            background: #FDE8E8;
        }

        .alm-notif-delete-tooltip {
            position: absolute;
            bottom: calc(100% + 6px);
            right: 0;
            background: #DC2626;
            color: #fff;
            font-size: .62rem;
            font-weight: 600;
            letter-spacing: .02em;
            padding: 4px 8px;
            border-radius: 6px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transform: translateY(2px);
            transition: opacity .12s ease, transform .12s ease;
            z-index: 10;
        }
        .alm-notif-delete-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            right: 7px;
            border: 4px solid transparent;
            border-top-color: #DC2626;
        }
        .alm-notif-delete-btn:hover .alm-notif-delete-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Mobile: notification panel goes true full-screen (registrar style) ── */
        @media (max-width: 1023px) {
            #alumni-notif-panel {
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                min-height: 100% !important;
                max-height: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                border: none !important;
            }
            #alumni-notif-panel .notif-list-scroll {
                max-height: none !important;
                flex: 1;
            }
        }
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  BFCACHE FIX
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // FIX ("This page has expired" pagka-logout): dati plain native form
    // POST lang ang logout button. Kapag laos na ang CSRF token (matagal
    // nang bukas ang tab, lumipas na ang session lifetime, o galing sa
    // bfcache/Livewire cache na luma na ang baked-in @csrf value), sinasagot
    // ito ng Laravel ng sarili niyang 419 whoops page - kahit successful
    // naman talaga ang logout intent ng user.
    //
    // Fix: gawing AJAX ang submit. Anuman ang mangyari sa sagot ng server
    // (200 OK talagang na-logout, o 419 dahil laos na ang session, na
    // effectively logged-out na rin naman), palaging derecho na lang sa
    // login page ang user, hindi na makikita ang 419 error screen.
    //
    // Nilagay ito dito sa script block (hindi sa loob ng HTML attribute)
    // para walang panganib na masira ang quoting kapag maraming special
    // characters o comments.
    window.__alumniLoginUrl = '{{ route("login") }}';

    window.__alumniLogout = function (isAlreadyLoggingOut, setLoggingOut, formEl) {
        if (isAlreadyLoggingOut) return;
        setLoggingOut(true);
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var token = tokenMeta ? tokenMeta.content : '';
        fetch(formEl.action, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        }).catch(function () {}).finally(function () {
            window.location.href = window.__alumniLoginUrl;
        });
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  ROUTE MAP
    // ─────────────────────────────────────────────────────────────────────────
    window.__alumniRouteMap = {
        'alumni.dashboard':   '/alumni/dashboard',
        'alumni.information': '/alumni/information',
        'job.opportunities':  '/job/opportunities',
        'upcoming.events':    '/upcoming/events',
        'alumni.messenger':   '/alumni/messenger',
        'alumni.yearbook':    '/alumni/yearbook',
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIF → TARGET URL
    //  For event notifs, deep-link straight into that event's detail modal
    //  instead of just landing on the plain events list. The event's id +
    //  source (ADMIN/ORGANIZER) are encoded in dedup_key server-side as
    //  'event-announced::{source}::{id}' (see AlumniNotificationController
    //  ::syncEventNotifications()) — parse that back out here.
    //
    //  upcoming-events.blade.php's mount() already reads ?event=&type= from
    //  the query string, opens that event's view-details modal immediately,
    //  and — once the modal is closed — auto-resets the list filter back to
    //  "Upcoming" (see closeViewModal()'s deepLinkedView handling). This
    //  helper just has to produce the right URL; the rest already works.
    // ─────────────────────────────────────────────────────────────────────────
    window.__alumniNotifTargetUrl = function (notif) {
        var base = window.__alumniRouteMap[notif.link_route] || '/alumni/dashboard';

        if (notif.link_route === 'upcoming.events') {
            var dedup = notif.dedup_key || '';
            var parts = dedup.split('::'); // ['event-announced', 'ADMIN'|'ORGANIZER', '{id}']
            if (parts[0] === 'event-announced' && parts[1] && parts[2]) {
                return base + '?event=' + encodeURIComponent(parts[2]) +
                              '&type='  + encodeURIComponent(parts[1]);
            }
        }

        if (notif.link_route === 'job.opportunities') {
            // job-opportunities.blade.php's mount() reads ?job= from the
            // query string, opens that job's view-details panel immediately,
            // and clears the query string once loaded (see jbStripJobQuery
            // in that file) — same deep-link pattern as events above.
            // dedup_key shapes: 'job-posted::{id}' or
            // 'job-status-activated::{id}::{minuteBucket}' — the job id is
            // always the segment right after the first '::'.
            var dedup = notif.dedup_key || '';
            var parts = dedup.split('::');
            if (parts[1]) {
                return base + '?job=' + encodeURIComponent(parts[1]);
            }
        }

        return base;
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  LOCAL READ-STATE TRACKING  (fixes: mark-read notif "coming back" as
    //  unread after navigating to another sidebar item / refreshing / polling)
    //
    //  Root cause of the bug: markRead() only updated the in-memory item and
    //  fired PATCH requests to the server, but the very next _fetch() (from
    //  polling, livewire:navigated, or a plain page refresh) rebuilt `items`
    //  straight from the server response. If that PATCH hadn't finished
    //  committing yet (race condition), or if the grouping logic saw ANY
    //  unread row and flipped the whole group back to unread, the badge
    //  count would reappear even though the user already read it.
    //
    //  Fix: keep a persistent (sessionStorage-backed) set of notif ids the
    //  user has read locally. Every fetch/group pass forces those ids to
    //  read=true BEFORE grouping, so a slow PATCH or a stale server response
    //  can never resurrect an already-read notification.
    // ─────────────────────────────────────────────────────────────────────────
    window.__alumniLocalReadIds = (function () {
        var KEY = 'alm_locally_read_ids';
        var set;
        try {
            var raw = sessionStorage.getItem(KEY);
            set = new Set(raw ? JSON.parse(raw) : []);
        } catch (e) {
            set = new Set();
        }
        return {
            has: function (id) { return set.has(String(id)); },
            add: function (id) {
                set.add(String(id));
                this._persist();
            },
            addMany: function (ids) {
                var self = this;
                (ids || []).forEach(function (id) { set.add(String(id)); });
                this._persist();
            },
            _persist: function () {
                try {
                    sessionStorage.setItem(KEY, JSON.stringify(Array.from(set)));
                } catch (e) { /* ignore quota errors */ }
            },
        };
    })();

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeAlumniNotifsStore = function () {
        return {
            open:       false,
            items:      [],
            _pollTimer: null,
            _navigating: false, // guards against double-click/double-tap firing two navigations for the same notif (the "kidyam"/double-open flicker)
            navigating: false, // true while a clicked notif's navigation is in flight — drives the in-place item spinner AND keeps the panel open until livewire:navigated (or a same-page click) clears it
            loadingId:  null,  // id of the notif currently mid-navigation (drives which item shows the spinner)
            deleteToast: { show: false, message: '' },
            deletingId: null, // id of the notif currently mid-delete (drives its spinner)

            async init() {
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () { self._fetch(); }, 10000);
            },

            async _fetch() {
                if (this._deleting) return; // don't let a poll refresh clobber an in-flight delete
                try {
                    var res = await window.fetch('/alumni/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.ok) {
                        var raw = await res.json();
                        // Force any locally-marked-read id to read=true BEFORE
                        // grouping — this is what stops a slow/late-committing
                        // PATCH (or a stale server row) from re-appearing as
                        // unread after navigation, refresh, or the next poll.
                        var localReads = window.__alumniLocalReadIds;
                        Array.from(raw).forEach(function (n) {
                            if (localReads.has(n.id)) n.read = true;
                        });
                        this.items = this._groupByDay(raw);
                    }
                } catch (e) { /* silently fail */ }
            },

            _groupByDay(rows) {
                // Only CHAT MESSAGE notifs get grouped (by day) — otherwise
                // an active batch chat floods the panel with one row per
                // message. Everything else (jobs, events, profile, etc.)
                // stays individual, one row per notif.
                var localReads = window.__alumniLocalReadIds;
                var msgMap = new Map();
                var result = [];

                Array.from(rows)
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var rawDedup = n.dedup_key || '';
                        var isMessageEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon === 'comments'
                        );

                        if (!isMessageEvent) {
                            result.push(Object.assign({}, n, {
                                read:  n.read || localReads.has(n.id),
                                count: 1,
                                _ids:  [n.id],
                            }));
                            return;
                        }

                        var day = n.created_at
                            ? new Date(n.created_at).toISOString().slice(0, 10)
                            : 'unknown';
                        var groupKey = 'message_day::' + day;

                        if (msgMap.has(groupKey)) {
                            var g = msgMap.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            g._ids.push(n.id);

                            // Only flip the group back to unread if this member
                            // is unread AND hasn't been locally marked read.
                            if (!n.read && !localReads.has(n.id)) {
                                g.read = false;
                            }

                            g.message = 'You received ' + g.count + ' new messages today.';
                            g.title   = 'Batch Chat';
                        } else {
                            msgMap.set(groupKey, Object.assign({}, n, {
                                read:  n.read || localReads.has(n.id),
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: 'Batch Chat',
                                icon:  'comments',
                            }));
                        }
                    });

                msgMap.forEach(function (v) { result.push(v); });

                // Unread items float to the top (newest first), read items
                // sit below (also newest first) — keeps a single clean
                // "Already Read" divider point.
                result.sort(function (a, b) {
                    if (!!a.read !== !!b.read) return a.read ? 1 : -1;
                    return new Date(b.created_at) - new Date(a.created_at);
                });

                return result;
            },

            get unread() {
                return this.items.filter(function (n) { return !n.read; }).length;
            },

            toggle() { this.open = !this.open; },
            close()  {
                // Don't let the panel be closed (outside click, X button,
                // etc.) while a notif click is still navigating/loading —
                // it should only close once livewire:navigated fires (or
                // the click turned out not to navigate anywhere).
                if (this.navigating) return;
                this.open = false;
            },

            // FIX (glitch before navigate): returns whether THIS notif
            // should currently LOOK read (background, title weight, dot),
            // as opposed to `notif.read` itself which flips true the
            // instant markRead() runs — before the destination page has
            // actually landed. While this specific item is mid-navigation
            // (spinner overlay showing), its visual state stays frozen at
            // whatever it looked like the moment it was clicked, so no
            // color/weight change is visible peeking out from under the
            // overlay before the fade finishes covering it.
            isVisuallyRead(item) {
                if (this.navigating && this.loadingId === item.id) {
                    return this._preClickRead === true;
                }
                return !!item.read;
            },

            async markRead(item) {
                if (item.read) return;

                var ids = Array.isArray(item._ids) ? item._ids : [item.id];

                // Mark locally read FIRST (persisted to sessionStorage) so no
                // race with the server PATCH below, and no future _fetch()/poll
                // can ever resurrect this notif as unread.
                window.__alumniLocalReadIds.addMany(ids);
                item.read = true;

                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                // Await every PATCH so a caller that navigates right after
                // markRead() (see the notif click handler below) is guaranteed
                // the server has committed the read state first.
                await Promise.all(ids.map(function (id) {
                    return window.fetch('/alumni/notifications/' + id + '/read', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    }).catch(function () { /* ignore network errors */ });
                }));
            },

            async markAllRead() {
                var allIds = [];
                this.items.forEach(function (n) {
                    n.read = true;
                    allIds = allIds.concat(Array.isArray(n._ids) ? n._ids : [n.id]);
                });
                window.__alumniLocalReadIds.addMany(allIds);

                try {
                    await window.fetch('/alumni/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                } catch (e) { /* ignore */ }
            },

            // Deletes a notification MESSAGE only — never the underlying
            // alumni record, job posting, or event that generated it. This
            // just clears the row(s) from the `notifications` table so the
            // panel/list gets shorter; the actual data this notif was about
            // is untouched.
            //
            // Only ever called for notifs that are 30+ days old (enforced
            // by the x-show on the delete button in the markup), so this
            // is purely a "clean up old noise" action.
            async deleteNotif(item) {
                // Ignore repeat clicks on a notif that's already mid-delete.
                if (this.deletingId === item.id) return;

                var ids = item._ids || [item.id];
                var self = this;
                this._deleting = true;
                this.deletingId = item.id; // drives the per-item spinner in the markup

                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                var failedIds = [];

                for (var i = 0; i < ids.length; i++) {
                    try {
                        var res = await window.fetch('/alumni/notifications/' + ids[i], {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (!res.ok) failedIds.push(ids[i]);
                    } catch (e) {
                        failedIds.push(ids[i]);
                    }
                }

                // Server call finished — only now do we start the slide-out.
                // If it failed, leave the item in place and just reset the
                // spinner so the person can retry.
                if (failedIds.length > 0) {
                    this.deletingId = null;
                    this._deleting = false;
                    this._showDeleteToast('Delete failed, please try again');
                    return;
                }

                this._showDeleteToast('Notification deleted');

                // Give the slide-out leave transition time to play before
                // actually removing the item from the array — removing it
                // immediately would skip straight past x-transition:leave.
                await new Promise(function (resolve) { setTimeout(resolve, 250); });
                this.items = this.items.filter(function (n) { return n !== item; });

                this.deletingId = null;
                this._deleting = false;
            },

            // Small self-clearing toast shown at the edge of the notif
            // panel. Re-triggerable: calling this again while a toast is
            // already showing resets its timer instead of stacking.
            _showDeleteToast(message) {
                var self = this;
                this.deleteToast.message = message;
                this.deleteToast.show = true;
                if (this._toastTimer) clearTimeout(this._toastTimer);
                this._toastTimer = setTimeout(function () {
                    self.deleteToast.show = false;
                }, 2200);
            },
        };
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SAFE ACCESSOR
    // ─────────────────────────────────────────────────────────────────────────
    window.__safeAlumniNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('alumniNotifs');
                if (s) return s;
            }
        } catch (e) {}
        return null;
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SINGLE BOOT PATH
    // ─────────────────────────────────────────────────────────────────────────
    // FIX (glitch: notif panel/badge randomly flashing wrong content on
    // refresh): there used to be FIVE separate listeners (alpine:init,
    // alpine:initialized, window.load, livewire:navigated, and a plain
    // IIFE) that could each independently create the store and call
    // .init(), with no coordination between them. On a slow refresh or a
    // slow network, two or more of these fired close together, so two
    // overlapping _fetch() calls resolved out of order — the OLDER
    // response could land AFTER the newer one, so the panel/badge briefly
    // showed a stale item count before "correcting" itself a moment
    // later. That flicker is exactly the "biglang may lumalabas" bug.
    //
    // Fix: one _bootedOnce flag. Only the FIRST ready signal (whichever
    // of alpine:init / window.load fires first) creates the store and
    // calls init(). Every other signal just re-syncs an ALREADY-existing
    // store — it never fires a second overlapping init().
    window.__alumniNotifsBootedOnce = false;

    window.__bootAlumniNotifsStoreOnce = function () {
        if (window.__alumniNotifsBootedOnce) return;
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        window.__alumniNotifsBootedOnce = true;
        if (!Alpine.store('alumniNotifs')) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
        }
        Alpine.store('alumniNotifs').init();
    };

    document.addEventListener('alpine:init', function () {
        if (!Alpine.store('alumniNotifs')) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
        }
    });

    document.addEventListener('alpine:initialized', function () {
        window.__bootAlumniNotifsStoreOnce();
    });

    window.addEventListener('load', function () {
        window.__bootAlumniNotifsStoreOnce();
    });

    // A fresh hard refresh may run this whole script before alpine:initialized
    // has fired yet in some browsers — this fallback catches that case without
    // creating a second competing boot.
    setTimeout(function () { window.__bootAlumniNotifsStoreOnce(); }, 200);

    // FIX (walang notif pagka-login): kung sakaling naka-eval na si Alpine
    // (alpine:init/alpine:initialized already fired) BAGO pa umabot dito
    // ang script na 'to — halimbawa kapag late ma-inject itong partial
    // pagkatapos ng login redirect — subukan ring mag-boot agad ngayon,
    // hindi lang umasa sa mga listener/timeout sa taas.
    if (window.Alpine && typeof Alpine.store === 'function') {
        window.__bootAlumniNotifsStoreOnce();
    }

    document.addEventListener('livewire:navigated', function () {
        // FIX (glitch): the spinner should finish first, THEN the whole
        // panel closes with its own smooth fade — never before, never
        // fighting Livewire's DOM morph for the same paint frame.
        //
        // Sequence that actually gets this right:
        //   1. Drop the in-place item spinner immediately (nothing to
        //      do with the DOM morph, safe to clear right away).
        //   2. Wait one rAF (next paint) for Livewire's morph to settle
        //      before touching s.open. No extra setTimeout buffer on
        //      top anymore — that padding was the sluggish/delayed
        //      feel on close, and one rAF is enough for the morph to
        //      have already painted.
        //   3. Only THEN set s.open = false — by this point the morph
        //      is done, so Alpine's x-transition:leave (100ms fade)
        //      gets a clean, already-painted frame to animate from,
        //      and the panel closes quickly and cleanly instead of
        //      lingering open or getting cut off mid-fade.
        if (!window.Alpine || typeof Alpine.store !== 'function') {
            // FIX (walang notif pagka-login): kapag ang login->dashboard
            // redirect ay isa ring wire:navigate, posibleng umabot dito
            // ang event BAGO pa maging ready si Alpine (script loading
            // pa / hindi pa na-eval). Dati diretso itong `return` at
            // wala nang sumusubok ulit dito — aasa na lang sa ibang
            // listener na baka late o na-miss. Ngayon, mag-retry ng
            // ilang beses (kada 50ms) hanggang maging ready si Alpine,
            // saka lang mag-boot.
            var tries = 0;
            var retry = setInterval(function () {
                tries++;
                if (window.Alpine && typeof Alpine.store === 'function') {
                    clearInterval(retry);
                    window.__bootAlumniNotifsStoreOnce();
                } else if (tries >= 20) { // ~1s ceiling, huwag mag-loop forever
                    clearInterval(retry);
                }
            }, 50);
            return;
        }
        var s = Alpine.store('alumniNotifs');
        if (!s) {
            // Store never got created (edge case) — boot it now.
            window.__bootAlumniNotifsStoreOnce();
            return;
        }
        if (s._pollTimer) { clearInterval(s._pollTimer); s._pollTimer = null; }

        // FIX (glitch: notif modal flashing on the LEFT SIDE right after
        // navigating): Livewire's navigate morph re-renders this panel's
        // markup from the server response, which resets its inline
        // top/left back to the hardcoded template fallback
        // (top:88px; left:12px — see the panel's style="" block below).
        // That fallback position is exactly the "left side" the user
        // sees flash. The panel's own x-effect is supposed to re-run
        // positionAlumniPanel() whenever it's open, but it's gated on
        // $store.alumniNotifs.open CHANGING value — and during this
        // whole navigate flow .open stays `true` the entire time (it
        // only flips to false a bit further down, on its own delay),
        // so the effect never re-fires after the morph to correct it.
        // Force the reposition here too, unconditionally and as early
        // as possible in this handler, so the panel snaps back to its
        // correct spot under the bell BEFORE the browser paints the
        // fallback position.
        if (s.open) positionAlumniPanel();

        // Destination page has landed — drop the spinner now, not before.
        s.navigating   = false;
        s.loadingId    = null;
        s._navigating  = false;

        // FIX (glitch: panel/badge flashing open again after landing):
        // s.init() calls _fetch(), which reassigns s.items. That
        // reassignment re-renders anything bound to s.items/s.unread
        // (the badge, the panel list) — including x-show/x-transition
        // blocks tied to the panel. Previously init() was fired WITHOUT
        // waiting for it, and the panel close (s.open = false) was
        // scheduled on its own unrelated 60ms timer. Whichever finished
        // first raced the other: if the fetch resolved WHILE the panel
        // was still open (or right as it was closing), the items
        // reassignment kicked off a fresh transition/re-render on an
        // already-open panel — visually reads as the panel "popping"
        // open again at the corner before it disappears.
        //
        // Fix: close the panel FIRST (on its own settle timer, same
        // pattern as the sidebar's own settle-after-navigate fix), and
        // only run init()/_fetch() — which touches items/unread — AFTER
        // the panel has already finished closing. That way the items
        // reassignment never lands while the panel is still visible or
        // mid-transition.
        requestAnimationFrame(function () {
            s.open = false;
            // Let the close transition actually start before we
            // mutate items/unread underneath it.
            setTimeout(function () { s.init(); }, 60);
        });
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeAlumniNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — anchored to the single top-right bell button
    //  (Mobile goes fully full-screen via CSS media query, so no inline
    //  overrides are needed for mobile here — matches registrar behavior.)
    // ─────────────────────────────────────────────────────────────────────────
    function positionAlumniPanel() {
        var btn   = document.getElementById('alumni-bell-btn');
        var panel = document.getElementById('alumni-notif-panel');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        if (window.innerWidth >= 1024) {
            panel.style.left  = (btnRect.right - 400) + 'px';
            panel.style.top   = (btnRect.bottom + 8) + 'px';
            panel.style.width = '400px';
        }
    }
    window.positionAlumniPanel = positionAlumniPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeAlumniNotifsStore();
        if (s && s.open) positionAlumniPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIFICATION EVENT LISTENERS
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstAlumniNotifListeners) {
        window.__philcstAlumniNotifListeners = true;

        function _alumniDetail(e) {
            var d = e.detail;
            if (!d) return {};
            if (!Array.isArray(d)) return d;
            return d[0] || {};
        }

        async function _saveAlumniNotif(payload) {
            try {
                await window.fetch('/alumni/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeAlumniNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeAlumniNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        window.addEventListener('profile-updated', function (e) {
            _saveAlumniNotif({
                icon:       'user-circle',
                title:      'Profile Updated',
                message:    'Your alumni profile has been updated successfully.',
                link_route: 'alumni.information',
                link_label: 'View Profile',
                dedup_key:  'profile-updated',
            });
        });

        window.addEventListener('event-announced', function (e) {
            var d = _alumniDetail(e);
            _saveAlumniNotif({
                icon:       'calendar',
                title:      'New Event Announced',
                message:    (d.title || 'A new event') + ' has been scheduled' +
                            (d.date ? ' on ' + d.date : '') + '.',
                link_route: 'upcoming.events',
                link_label: 'View Events',
                dedup_key:  'event-announced::' + (d.id || ''),
            });
        });

        // ── New job posting (dispatched by the Organizer Job Management
        //    Volt component's savePost() method after logAudit()).
        window.addEventListener('job-posted', function (e) {
            var d = _alumniDetail(e);
            _saveAlumniNotif({
                icon:       'briefcase',
                title:      'New Job Posting',
                message:    (d.title || 'A new job') + ' at ' + (d.company || 'a company') + ' was just posted.',
                link_route: 'job.opportunities',
                link_label: 'View Job',
                dedup_key:  'job-posted::' + (d.id || ''),
            });
        });

        // ── Job posting status changes (dispatched by the Organizer Job
        //    Management Volt component's executeToggleStatus() and
        //    executeRestoreJob() methods as 'job-management-updated', with
        //    an `action` of 'activated' | 'deactivated' | 'restored').
        //
        //    ONLY 'activated' actually notifies the alumni now, and it's
        //    saved with the SAME 'job-posted::'-style shape (icon:
        //    'briefcase', title: 'New Job Posting') so _groupByDay() folds
        //    it straight into the same "New Job Postings" bucket as a
        //    brand-new post — it's still just one job either way.
        //
        //    'deactivated' and 'restored' are intentionally ignored here —
        //    no notification is created for those, per instruction: only
        //    new jobs and activated jobs should ever notify alumni.
        window.addEventListener('job-management-updated', function (e) {
            var d      = _alumniDetail(e);
            var action = d.action || 'updated';

            if (action !== 'activated') return;

            _saveAlumniNotif({
                icon:       'briefcase',
                title:      'New Job Posting',
                message:    (d.title || 'A job') + ' is now open and visible to alumni.',
                link_route: 'job.opportunities',
                link_label: 'View Job',
                dedup_key:  'job-status-activated::' + (d.id || '') + '::' + Math.floor(Date.now() / 60000),
            });
        });

        // ── Employment update / creation (dispatched by the Alumni
        //    Information Volt component's saveEmployment() method).
        window.addEventListener('employment-updated', function (e) {
            var d = _alumniDetail(e);
            var isNew    = !!d.is_new;
            var status   = d.status  || 'Updated';
            var company  = d.company || '';
            var jobTitle = d.job_title || '';

            var message;
            if (company && jobTitle) {
                message = 'Your employment status is now "' + status + '" — ' + jobTitle + ' at ' + company + '.';
            } else {
                message = 'Your employment status has been updated to "' + status + '".';
            }

            _saveAlumniNotif({
                icon:       'briefcase',
                title:      isNew ? 'Employment Record Added' : 'Employment Status Updated',
                message:    message,
                link_route: 'alumni.information',
                link_label: 'View Details',
                dedup_key:  'employment-updated::' + Math.floor(Date.now() / 1000),
            });
        });

        window.addEventListener('message-received', function (e) {
            var d = _alumniDetail(e);

            var sender = d.sender || 'Someone';
            var room   = d.room   || 'Group Chat';
            var body   = d.body   || '';
            var count  = Number(d.count) || 1;

            var msgText = count > 1
                ? 'You received ' + count + ' new messages.'
                : 'You received a message.';

            _saveAlumniNotif({
                icon:       'comments',
                title:      'Batch Chat',
                message:    msgText,
                link_route: 'alumni.messenger',
                link_label: 'Open Messenger',
                dedup_key:  'message-received::' + sender + '::' + room + '::' + Math.floor(Date.now() / 60000),
            });
        });

        window.addEventListener('alumni-notif-refresh', function () {
            var s = window.__safeAlumniNotifsStore();
            if (s) {
                s._fetch();
                setTimeout(function () {
                    var s2 = window.__safeAlumniNotifsStore();
                    if (s2) s2._fetch();
                }, 800);
            }
        });
    }
    </script>
</head>


<body
    class="antialiased"
    x-data="{
        open: false,
        // Collapsed state now persists across a refresh — same treatment
        // as the registrar sidebar — so if it was collapsed before you
        // reloaded, it stays collapsed. Icon/notif colors and design are
        // untouched; this only restores the last collapsed/expanded value.
        //
        // FIX: localStorage is shared by the whole BROWSER, not per
        // account — a plain 'alm_sidebar_collapsed' key meant that
        // logging out and logging into a different alumni account on
        // the same device/browser inherited whichever collapsed state
        // the previous account last left behind. Keyed by the logged-in
        // user's id instead, so each account remembers its own
        // preference independently.
        sidebarStorageKey: 'alm_sidebar_collapsed_{{ auth()->id() }}',
        sidebarCollapsed: localStorage.getItem('alm_sidebar_collapsed_{{ auth()->id() }}') === '1',
        // FIX (glitch: sidebar visibly jumping/flashing on refresh and
        // on livewire:navigate): a double requestAnimationFrame is NOT a
        // reliable the-browser-has-painted signal — on a busy page
        // (lots of Alpine components, a long notif list) two rAF ticks
        // can fire before the FIRST paint ever happens, so no-transition
        // was being removed too early and the width/translate transition
        // animated the initial state instead of snapping to it instantly.
        // Fix: wait for the actual 'load'-equivalent readiness via
        // requestAnimationFrame + a short setTimeout fallback, which
        // reliably lands after first paint regardless of page weight.
        sidebarSettled: false,
        loggingOut: false,
        navClickedRoute: null,
        profileComplete: {{ (bool)(auth()->user()?->alumni?->profile_completed ?? false) ? 'true' : 'false' }},
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
        },
        settleSidebar() {
            this.sidebarSettled = false;
            requestAnimationFrame(() => {
                setTimeout(() => { this.sidebarSettled = true; }, 50);
            });
        }
    }"
    x-init="
        $watch('sidebarCollapsed', function (val) { localStorage.setItem(sidebarStorageKey, val ? '1' : '0'); });
        settleSidebar();
    "
    x-on:profile-updated.window="profileComplete = $event.detail.completed"
    @@livewire:navigated.window="navClickedRoute = null; open = false; settleSidebar();"
    @click="$store.alumniNotifs && $store.alumniNotifs.open && $store.alumniNotifs.close()">

<div class="alm-app-shell flex bg-[#F5F5F5] font-sans overflow-hidden">

    {{-- Mobile overlay --}}
    <div
        x-show="open"
        x-transition:enter="transition opacity-ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition opacity-ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-40 bg-black/50 lg:hidden">
    </div>

    {{-- ══ SIDEBAR ══ --}}
    <aside
        id="alumni-sidebar-aside"
        :class="{
            'translate-x-0': open,
            '-translate-x-full': !open,
            'is-collapsed': sidebarCollapsed,
            'no-transition': !sidebarSettled
        }"
        class="alm-sidebar fixed inset-y-0 left-0 z-50 transform
               lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0;">

    <script>
        // FIX (glitch: sidebar visibly opening/closing on hard refresh
        // while collapsed): Alpine's :class bindings (is-collapsed,
        // no-transition above) only get applied once Alpine has parsed
        // and evaluated x-data on <body> — the <aside> itself paints
        // in its plain default state (expanded, full 18rem width) for
        // however many frames that takes on a heavier page. The user
        // sees: expanded sidebar → snaps to collapsed → (transition
        // was still enabled during that gap) briefly animates the
        // width change — which reads as the sidebar "opening and
        // closing" on refresh.
        //
        // Fix: read the SAME localStorage key Alpine will read a
        // moment later, and — synchronously, before this <script> tag
        // even finishes executing (which blocks the browser from
        // painting anything below it) — apply 'is-collapsed' and
        // 'no-transition' directly to the raw DOM node right now. By
        // the time Alpine's x-data initializes and takes over the
        // class binding, the node already matches, so there is
        // nothing left to visibly snap into place.
        (function () {
            var aside = document.getElementById('alumni-sidebar-aside');
            if (!aside) return;
            var collapsed = localStorage.getItem('alm_sidebar_collapsed_{{ auth()->id() }}') === '1';
            aside.classList.add('no-transition');
            if (collapsed) aside.classList.add('is-collapsed');
        })();
    </script>

        {{-- Sidebar header — graduate-themed (purple, cap badge).
             Text elements use `.alm-collapsible-text` (opacity + max-width
             transition, now perfectly synced with the sidebar's own 0.2s
             width transition — same duration/easing everywhere) instead of
             `display:none !important`, so the collapse settles correctly
             after exactly one click. --}}
        <div class="alm-sidebar-header h-24 px-5 shrink-0">
            <div class="alm-header-inner">
                <div class="alm-cap-badge">
                    <i class="fa-solid fa-graduation-cap text-white" style="font-size:17px;"></i>
                </div>
                <div class="min-w-0 alm-collapsible-text">
                    <h1 class="text-[19px] font-bold tracking-tight text-white leading-tight truncate">
                        Alumni<span class="font-semibold text-white/70">Portal</span>
                    </h1>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-white/60 font-semibold">
                        Graduate Network
                    </p>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">

            <div class="alm-nav-section-row">
                <p class="alm-section-label alm-collapsible-text">MENU</p>

                {{-- Collapse/expand toggle button.
                     Icon direction is a direct, one-to-one ternary off the
                     SAME `sidebarCollapsed` boolean the sidebar width and
                     text fades use — so it can never point the "wrong way"
                     or lag behind: collapsed → show angles-right (meaning
                     "click to expand"), expanded → show angles-left
                     (meaning "click to collapse").

                     IMPORTANT: the <i> tag carries a STATIC default class
                     of `fa-angles-left` that matches the default Alpine
                     state (`sidebarCollapsed: false`, i.e. expanded). This
                     is what was missing before — with no static fallback
                     class, the icon had nothing to render before Alpine
                     finished hydrating on the client, so on any slow
                     paint/hydration you'd briefly see the wrong (or no)
                     arrow. Now the static class already matches truth on
                     first paint, and Alpine's :class binding only takes
                     over cleanly after that — no flash, no mismatch. --}}
                <button type="button"
                        @click.stop="toggleSidebar()"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        class="alm-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="{ 'fa-angles-right': sidebarCollapsed, 'fa-angles-left': !sidebarCollapsed }"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                $sidebarLinks = [
                    [
                        'route'   => 'alumni.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'alumni/dashboard*',
                    ],
                    [
                        'route'   => 'alumni.information',
                        'icon'    => 'user-circle',
                        'label'   => 'My Profile',
                        'pattern' => 'alumni/information*',
                    ],
                    [
                        'route'   => 'job.opportunities',
                        'icon'    => 'briefcase',
                        'label'   => 'Job Opportunities',
                        'pattern' => 'job/opportunities*',
                    ],
                    [
                        'route'   => 'upcoming.events',
                        'icon'    => 'calendar',
                        'label'   => 'Alumni Events',
                        'pattern' => 'upcoming/events*',
                    ],
                    [
                        'route'   => 'alumni.messenger',
                        'icon'    => 'comments',
                        'label'   => 'Batch Chat',
                        'pattern' => 'alumni/messenger*',
                    ],
                    [
                        'route'   => 'alumni.yearbook',
                        'icon'    => 'book-open',
                        'label'   => 'Yearbook',
                        'pattern' => 'alumni/yearbook*',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php $isActive = request()->is($link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="navClickedRoute = '{{ $link['route'] }}';"
                   :class="{ 'is-navigating': navClickedRoute === '{{ $link['route'] }}' }"
                   class="alm-nav-link {{ $isActive ? 'is-active' : '' }}
                          flex items-center px-4 py-3 rounded-xl group">

                    <div class="alm-nav-icon w-10 h-10 flex items-center justify-center rounded-lg shrink-0 mr-3.5"
                         style="background-color:{{ $isActive ? '#FFFFFF' : '#F9F7FC' }};color:#7A3F91;
                                box-shadow:{{ $isActive ? '0 2px 6px rgba(122,63,145,0.18)' : 'none' }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"
                           x-show="!(navClickedRoute === '{{ $link['route'] }}' && (sidebarCollapsed || window.innerWidth < 1024))"></i>
                        <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                            <span class="alm-nav-spinner-icon-anchored">
                                <i class="fas fa-spinner fa-spin alm-nav-spinner"></i>
                            </span>
                        </template>
                    </div>

                    <span class="alm-nav-label alm-collapsible-text font-medium tracking-wide flex-1 text-[14px]
                                 {{ $isActive ? 'text-[#5A2D70] font-bold' : 'text-[#3A3A3A]' }}">
                        {{ $link['label'] }}
                    </span>

                    <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                        <i class="fas fa-spinner fa-spin alm-nav-spinner"></i>
                    </template>

                    @if($isActive)
                        <template x-if="navClickedRoute !== '{{ $link['route'] }}'">
                            <span class="alm-active-dot alm-collapsible-text ml-auto w-1.5 h-6 rounded-full shrink-0"
                                  style="background:#7A3F91;"></span>
                        </template>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Logout --}}
        <div class="p-2 lg:p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit.prevent="window.__alumniLogout(loggingOut, function(v){ loggingOut = v; }, $event.target)">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        title="Logout"
                        class="alm-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="alm-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>
                            <span class="alm-logout-label-text alm-collapsible-text">Logout</span>
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="alm-logout-text-swap">
                            <span class="alm-logout-spinner mr-2"></span>
                            <span class="alm-logout-label-text alm-collapsible-text">Logging out…</span>
                        </span>
                    </template>
                </button>
            </form>
        </div>
    </aside>

    <script>
        // ── Block copy/right-click/drag on the sidebar itself ──
        // CSS (user-select: none above) already prevents highlighting text,
        // but a user can still right-click → "Copy" on some browsers, or
        // drag-select across elements CSS doesn't fully cover. This backs
        // that up at the DOM event level, scoped only to #alumni-sidebar-aside
        // so the rest of the page (main content, modals, notif panel handled
        // separately) is completely unaffected.
        (function () {
            var sidebar = document.getElementById('alumni-sidebar-aside');
            if (!sidebar) return;
            ['contextmenu', 'copy', 'cut', 'dragstart', 'selectstart'].forEach(function (evt) {
                sidebar.addEventListener(evt, function (e) { e.preventDefault(); });
            });
        })();
    </script>

    {{-- ══ MAIN CONTENT ══ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Top bar — visible on ALL screen sizes. Hamburger only shows on mobile (lg:hidden).
             Bell always sits on the right, icon-only. --}}
        <header class="flex items-center justify-between px-4 lg:px-8 h-24 bg-white border-b border-[#E8E0F0]
                       shrink-0 z-30">
            <button @click="open = !open"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors lg:hidden">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <span class="hidden lg:block"></span>

            {{-- Notifications bell — right side, icon-only on every screen size --}}
            <button
                id="alumni-bell-btn"
                type="button"
                @click.stop="$store.alumniNotifs && $store.alumniNotifs.toggle(); positionAlumniPanel();"
                title="Notifications"
                aria-label="Open notifications"
                class="alm-topbar-bell">
                <i class="bell-icon fas fa-bell"
                   style="font-size:20px; color:#7A3F91; pointer-events:none;"></i>
                <span
                    x-show="$store.alumniNotifs && $store.alumniNotifs.unread > 0"
                    x-cloak
                    class="bell-bubbles">
                    <span class="bell-bubble"></span>
                    <span class="bell-bubble"></span>
                    <span class="bell-bubble"></span>
                </span>
                <span
                    x-show="$store.alumniNotifs && $store.alumniNotifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.alumniNotifs && $store.alumniNotifs.unread > 99
                                ? '99+'
                                : ($store.alumniNotifs ? $store.alumniNotifs.unread : 0)">
                </span>
            </button>
        </header>

        {{-- Page content --}}
        <div class="flex-1 overflow-y-auto no-scrollbar bg-[#F5F5F5] p-4 lg:p-8"
             style="min-height: 0; -webkit-overflow-scrolling: touch;">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ALUMNI NOTIFICATION PANEL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="alumni-notif-panel"
    x-show="$store.alumniNotifs && $store.alumniNotifs.open"
    x-cloak
    x-effect="
        // FIX (glitch: panel jumping to a corner right before it
        // vanishes on navigate): this used to re-run positionAlumniPanel()
        // on ANY reactive change while open (items/unread updates count
        // as reactive changes too, since Alpine's dependency tracking
        // isn't scoped to just .open). So the items reassignment from
        // s.init()/_fetch() — even one that lands milliseconds before
        // s.open flips to false — triggered one more repositioning pass,
        // which is what looked like the panel 'popping over to the
        // side' right before it disappeared. Reading $store.alumniNotifs.open
        // into a local first, and gating on ONLY that read, stops
        // unrelated items/unread changes from re-triggering this effect.
        let isOpen = $store.alumniNotifs && $store.alumniNotifs.open;
        if (isOpen) $nextTick(() => positionAlumniPanel());
    "
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
    x-transition:leave="transition ease-out duration-75"
    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
    @click.stop
    @contextmenu.prevent
    @copy.prevent
    class="bg-white rounded-2xl border border-[#E8E0F0] flex flex-col overflow-hidden"
    style="
        position: fixed;
        top: 88px;
        left: 12px;
        width: 400px;
        z-index: 9999;
        transform-origin: top left;
        min-height: 520px;
        box-shadow: 0 24px 60px -8px rgba(122,63,145,0.30),
                    0 6px 24px rgba(0,0,0,0.10);
    ">

    {{-- Panel Header --}}
    <div class="flex items-center justify-between px-5 py-4 shrink-0"
         style="background:#7A3F91;">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                 style="background:rgba(255,255,255,0.14);">
                <i class="fas fa-bell text-white" style="font-size:13px;"></i>
            </div>
            <span class="text-white font-bold" style="font-size:15px;">Notifications</span>
            <span x-show="$store.alumniNotifs && $store.alumniNotifs.unread > 0"
                  x-cloak
                  class="bg-red-500 text-white font-black px-2 py-0.5 rounded-full leading-none"
                  style="font-size:11px;"
                  x-text="$store.alumniNotifs ? $store.alumniNotifs.unread + ' new' : ''">
            </span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button"
                    x-show="$store.alumniNotifs && $store.alumniNotifs.unread > 0"
                    x-cloak
                    @click.stop="$store.alumniNotifs && $store.alumniNotifs.markAllRead()"
                    class="text-[#5A2D70] hover:text-[#7A3F91] font-bold
                           bg-white hover:bg-gray-100 shadow-sm
                           rounded-lg px-3 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>

            <div class="notif-close-wrap ml-1">
                <span class="notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.alumniNotifs && $store.alumniNotifs.close()"
                        aria-label="Close notifications"
                        class="w-7 h-7 flex items-center justify-center rounded-lg
                               text-white/50 hover:text-white hover:bg-white/10 transition">
                    <i class="fas fa-xmark" style="font-size:14px;"></i>
                </button>
            </div>
        </div>
    </div>

    {{-- Sub-header --}}
    <div class="px-5 py-2.5 flex items-center justify-between shrink-0"
         style="background:#FAF6FE; border-bottom:1px solid #ECE2F8;">
        <span style="font-size:11px; font-weight:700; color:#7A3F91; letter-spacing:0.08em; text-transform:uppercase;">
            Recent Activity
        </span>
        <span style="font-size:11px; color:#9A8AA8; font-weight:500;"
              x-text="($store.alumniNotifs ? $store.alumniNotifs.items.length : 0) + ' notification(s)'">
        </span>
    </div>

    {{-- Delete toast — slides in ABOVE the list, inside the panel --}}
    <div
        x-show="$store.alumniNotifs && $store.alumniNotifs.deleteToast.show"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        style="
            background: #ECFDF3;
            border-bottom: 1px solid #BBF7D0;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        ">
        <i class="fas fa-circle-check" style="font-size:13px; color:#16A34A;"></i>
        <span style="font-size:12.5px; font-weight:600; color:#15803D;"
              x-text="$store.alumniNotifs ? $store.alumniNotifs.deleteToast.message : ''"></span>
    </div>

    {{-- Scrollable notification list --}}
    <div class="notif-list-scroll overflow-y-auto no-scrollbar flex-1" style="max-height: 420px;">

        <template x-if="$store.alumniNotifs && $store.alumniNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F9F7FC; border:1px solid #ECE2F8;">
                    <i class="fas fa-bell-slash" style="font-size:26px;color:#D7C8E6;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    New job postings, events, messages,<br>and updates will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.alumniNotifs">
            <template x-for="(notif, notifIdx) in $store.alumniNotifs.items" :key="notif.id">
                <div>
                    <div class="notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.alumniNotifs.items[notifIdx - 1].read && !$store.alumniNotifs.navigating"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         x-cloak>
                        <span class="notif-divider-label">Already Read</span>
                    </div>

                    <div
                        class="notif-item flex items-start gap-4 px-5 py-4
                               border-b border-[#F5F5F5] last:border-b-0
                               transition-colors duration-150 select-none"
                        :class="[
                            // FIX (glitch before navigate): while this item is
                            // navigating/loading, its read/unread look is FROZEN
                            // at whatever it was the instant it was clicked —
                            // `markRead()` flips `notif.read` to true right away
                            // (so the server/local-read state is correct even if
                            // navigation is slow), but that used to also flip the
                            // background, title weight, and unread dot the exact
                            // same instant, all visible underneath the overlay
                            // before the fade had a chance to cover it. Now those
                            // visual bits only react to the frozen isVisuallyRead
                            // value below, so nothing changes color/weight until
                            // the destination page has actually landed and the
                            // overlay has fully faded in and back out.
                            $store.alumniNotifs.isVisuallyRead(notif) ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#FAF6FE] hover:bg-[#F3EBFA]',
                            ($store.alumniNotifs.navigating && $store.alumniNotifs.loadingId === notif.id) ? 'is-loading' : ''
                        ]"
                        @click.stop="
                            if ($store.alumniNotifs._navigating) return;
                            $store.alumniNotifs._preClickRead = !!notif.read;
                            $store.alumniNotifs._navigating = true;
                            $store.alumniNotifs.navigating   = true;
                            $store.alumniNotifs.loadingId    = notif.id;
                            $store.alumniNotifs.markRead(notif).then(() => {
                                if (notif.link_route) {
                                    const url = window.__alumniNotifTargetUrl(notif);
                                    // Sequence: let the spinner actually show for a
                                    // beat first, THEN close the panel, and only
                                    // navigate once the panel's own close transition
                                    // has finished — instead of firing the navigate
                                    // immediately and letting the panel close in the
                                    // background while the page is already loading.
                                    setTimeout(() => {
                                        $store.alumniNotifs.open = false;
                                        setTimeout(() => {
                                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                                        }, 80); // matches the panel's leave transition duration
                                    }, 150); // spinner-visible beat before closing
                                } else {
                                    $store.alumniNotifs._navigating = false;
                                    $store.alumniNotifs.navigating  = false;
                                    $store.alumniNotifs.loadingId   = null;
                                }
                            }).catch(() => {
                                $store.alumniNotifs._navigating = false;
                                $store.alumniNotifs.navigating  = false;
                                $store.alumniNotifs.loadingId   = null;
                            });
                        ">

                        <div class="notif-item-loading-overlay"
                             x-show="$store.alumniNotifs.navigating && $store.alumniNotifs.loadingId === notif.id"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             x-cloak>
                            <i class="fas fa-spinner fa-spin notif-item-spinner"></i>
                        </div>

                        <div class="notif-icon-wrap" style="background:#F3EBFA;">
                            <i class="fas text-[#7A3F91]"
                               :class="'fa-' + (notif.icon || 'bell')"
                               style="font-size:15px;"></i>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <p :class="$store.alumniNotifs.isVisuallyRead(notif) ? 'font-semibold text-[#555555]' : 'font-bold text-[#1a1a1a]'"
                                       style="font-size:13px;line-height:1.4;"
                                       x-text="notif.title"></p>

                                    <span
                                        x-show="Number(notif.count) > 1"
                                        x-cloak
                                        class="inline-flex items-center justify-center
                                               min-w-[22px] h-5 rounded-full px-1.5
                                               text-[10px] font-black text-white leading-none"
                                        style="background:#7A3F91;"
                                        x-text="'×' + Number(notif.count)">
                                    </span>

                                    <span
                                        x-show="notif.icon === 'briefcase' && !$store.alumniNotifs.isVisuallyRead(notif)"
                                        x-cloak
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                        style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                               background:#7A3F91;">
                                        NEW JOB
                                    </span>

                                    <span
                                        x-show="(notif.icon === 'calendar' || notif.icon === 'circle-check') && !$store.alumniNotifs.isVisuallyRead(notif)"
                                        x-cloak
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                        style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                               background:#059669;">
                                        NEW EVENT
                                    </span>

                                    <span
                                        x-show="notif.icon === 'comments' && !$store.alumniNotifs.isVisuallyRead(notif)"
                                        x-cloak
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                        style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                               background:#7A3F91;">
                                        NEW MESSAGE
                                    </span>
                                </div>

                                <span x-show="!$store.alumniNotifs.isVisuallyRead(notif)" x-cloak
                                      class="notif-unread-dot"></span>
                            </div>

                            <p class="text-[#333333] mt-1 leading-relaxed"
                               style="font-size:12px;
                                      display:-webkit-box;
                                      -webkit-line-clamp:2;
                                      -webkit-box-orient:vertical;
                                      overflow:hidden;"
                               x-text="notif.message">
                            </p>

                            <div class="flex items-center justify-between gap-2 mt-2">
                                <span style="display:flex; align-items:center; gap:5px;">
                                    <i class="fas fa-clock" style="font-size:10px;color:#666666;"></i>
                                    <span style="font-size:11px;color:#333333;font-weight:500;"
                                          x-text="notif.created_at
                                              ? new Date(notif.created_at).toLocaleString('en-PH',{
                                                  month:'short',day:'numeric',year:'numeric',
                                                  hour:'2-digit',minute:'2-digit'
                                                })
                                              : ''">
                                    </span>
                                </span>

                                {{-- Delete icon — only shown once a notif is 30+ days old --}}
                                <button type="button"
                                        x-show="notif.created_at && ((Date.now() - new Date(notif.created_at).getTime()) / 86400000) >= 30"
                                        x-cloak
                                        class="alm-notif-delete-btn"
                                        :class="{ 'is-deleting': $store.alumniNotifs && $store.alumniNotifs.deletingId === notif.id }"
                                        :disabled="$store.alumniNotifs && $store.alumniNotifs.deletingId === notif.id"
                                        @click.stop="$store.alumniNotifs && $store.alumniNotifs.deleteNotif(notif)"
                                        aria-label="Delete notification">
                                    <i class="fas fa-trash-can"
                                       x-show="!($store.alumniNotifs && $store.alumniNotifs.deletingId === notif.id)"></i>
                                    <i class="fas fa-spinner fa-spin"
                                       x-show="$store.alumniNotifs && $store.alumniNotifs.deletingId === notif.id"
                                       x-cloak></i>
                                    <span class="alm-notif-delete-tooltip">Delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>

    {{-- Panel Footer --}}
    <div class="px-5 py-3 border-t border-[#F0ECF8] text-center shrink-0" style="background:#FAFAFA;">
        <p style="font-size:11px;color:#333333;font-weight:600;">
            Click a notification to view and mark as read
        </p>
    </div>
</div>

@livewireScripts

{{-- ✅ CLOSE ON OUTSIDE CLICK --}}
<div
    x-data
    x-show="$store.alumniNotifs && $store.alumniNotifs.open"
    x-cloak
    @click="$store.alumniNotifs && $store.alumniNotifs.close()"
    class="fixed inset-0"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>