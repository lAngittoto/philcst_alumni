<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} - Registrar</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
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

        .bell-badge { pointer-events: none; }

        /* ── Disable text selection/copy inside the notif panel ───
           Applies to the whole panel: header label, item titles,
           messages, timestamps, footer hint. Buttons still work
           fine since we're only blocking text selection, not clicks. ── */
        .notif-no-select,
        .notif-no-select * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* ════════════════════════════════════════════════════════
           SIDEBAR
        ════════════════════════════════════════════════════════ */
        .reg-sidebar {
            background: #FFFFFF;
            border-right: 1px solid #E5E5E5;
            transition:
                width 0.2s ease,
                min-width 0.2s ease,
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.25s ease,
                border-color 0.25s ease;
        }
        .reg-hamburger-line { background: #7A3F91; }

        /* ── Brand header icon mark ─────────────────────────────
           A registrar/records "badge" mark: an open book-with-
           checkmark reads immediately as "official student records",
           distinct from the generic gauge/user icons used in nav. */
        .reg-brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: linear-gradient(155deg, #7A3F91 0%, #5A2270 100%);
            box-shadow: 0 3px 10px rgba(90,34,112,0.28);
            color: #fff;
            font-size: 19px;
        }

        .reg-nav-link {
            position: relative;
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .reg-nav-link { background-color: transparent; }
        .reg-nav-link:not(.is-active):hover { background-color: #F5F5F5 !important; }
        .reg-nav-link:not(.is-active):hover .reg-nav-icon { transform: scale(1.05); }
        .reg-nav-link.is-active { background: #7A3F91; }

        /* ── Color-coded nav icons ───────────────────────────────
           Each destination gets its own accent so the sidebar can be
           scanned by color, not just by reading labels. Colors carry
           meaning: blue = overview/data, purple = people, green =
           adding/growth, amber = tracking/movement. When a link is
           active, its icon chip flips to solid brand purple so the
           active state still reads as one unmistakable signal. */
        .reg-nav-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 0.9rem;
            transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease;
        }
        .reg-nav-icon.clr-dashboard  { background: #DBEAFE; color: #2563EB; }
        .reg-nav-icon.clr-alumni     { background: #EDE9FE; color: #7C3AED; }
        .reg-nav-icon.clr-register   { background: #DCFCE7; color: #16A34A; }
        .reg-nav-icon.clr-employment { background: #FEF3C7; color: #C08A00; }

        .reg-nav-link.is-active .reg-nav-icon { background: #FFFFFF; color: #7A3F91 !important; }

        /* ── Strip icon color while navigating ──────────────────────
           While a link is mid-navigation (spinner showing), the chip
           drops its clr-* accent color and goes neutral gray — the
           spinner is the only signal that matters in that moment.
           Once navigation lands (is-navigating class removed), the
           chip's original clr-* color returns automatically since
           this rule no longer applies. !important beats the clr-*
           background/color rules above regardless of source order. */
        .reg-nav-link.is-navigating .reg-nav-icon {
            background: #F0F0F0 !important;
            color: #9CA3AF !important;
        }

        .reg-nav-label {
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.01em;
            color: #000000;
            white-space: nowrap;
        }
        .reg-nav-link.is-active .reg-nav-label { color: #FFFFFF; font-weight: 700; }

        .reg-nav-dot {
            margin-left: auto;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #fff;
            flex-shrink: 0;
        }

        /* ── Nav link click spinner ──────────────────────────────
           Same visual language as the alumni table's loading
           spinner (fa-spinner fa-spin, brand purple) so the whole
           app has one consistent "loading" interface instead of a
           different spinner style per screen.
           Expanded sidebar: sits exactly where the active dot sits
           (end of the row), icon stays visible. Collapsed sidebar /
           mobile: centered on top of the icon chip, icon hidden,
           since there's no label/dot row to show it in. */
        .reg-nav-spinner {
            flex-shrink: 0;
            margin-left: auto;
            font-size: 13px;
            color: #fff;
            line-height: 1;
        }
        .reg-nav-link:not(.is-active) .reg-nav-spinner {
            color: #7A3F91;
        }

        /* Two spinner copies are rendered: one inline at the end of
           the row (next to the label, where the dot sits), one
           inside the icon chip itself. Only one is ever visible at
           a time — CSS below toggles which, per breakpoint/state —
           so the icon-anchored copy is always dead-center on the
           icon regardless of the link's own padding. */
        .reg-nav-spinner-icon-anchored { display: none; }
        .reg-nav-icon { position: relative; }

        /* Icon-only sidebar states: collapsed desktop rail (≥1024px)
           AND mobile/tablet (<1024px, always icon-only here). Both
           cases get the exact same treatment — icon-anchored spinner
           dead-centered on the chip, end-of-row spinner hidden, and
           the icon itself fully removed from view so nothing peeks
           out from underneath the spinner. !important on every rule
           here so nothing else in the sheet can win against it. */
        .reg-sidebar.is-collapsed .reg-nav-link.is-navigating > .reg-nav-spinner,
        .reg-nav-link.is-navigating > .reg-nav-spinner {
            display: none !important;
        }
        .reg-sidebar.is-collapsed .reg-nav-link.is-navigating .reg-nav-spinner-icon-anchored,
        .reg-nav-link.is-navigating .reg-nav-spinner-icon-anchored {
            display: flex !important;
            align-items: center;
            justify-content: center;
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            font-size: 16px !important;
        }
        .reg-nav-spinner-icon-anchored .reg-nav-spinner {
            margin-left: 0;
        }
        .reg-sidebar.is-collapsed .reg-nav-link.is-navigating .reg-nav-icon i.fa-solid,
        .reg-nav-link.is-navigating .reg-nav-icon i.fa-solid {
            display: none !important;
        }
        /* On expanded desktop (not collapsed), the end-of-row spinner
           is the one that should show, with the icon staying visible —
           so undo the icon-only treatment above in that one case. */
        @media (min-width: 1024px) {
            .reg-sidebar:not(.is-collapsed) .reg-nav-link.is-navigating > .reg-nav-spinner {
                display: flex !important;
            }
            .reg-sidebar:not(.is-collapsed) .reg-nav-link.is-navigating .reg-nav-spinner-icon-anchored {
                display: none !important;
            }
            .reg-sidebar:not(.is-collapsed) .reg-nav-link.is-navigating .reg-nav-icon i.fa-solid {
                display: inline-block !important;
            }
        }

        .reg-nav-section-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            margin-top: 0.25rem;
            margin-bottom: 0.5rem;
        }
        .reg-nav-section-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #000000;
            opacity: 0.45;
        }

        .reg-collapse-icon-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 7px;
            background: #F0E9F6;
            border: none;
            color: #7A3F91;
            cursor: pointer;
            font-size: 10px;
            flex-shrink: 0;
            transition: background-color 0.15s ease, transform 0.15s ease;
        }
        .reg-collapse-icon-btn:hover { background: #E4D3F0; }
        .reg-collapse-icon-btn:active { transform: scale(0.88); }
        .reg-collapse-icon-btn i { pointer-events: none; }

        /* Logout */
        .reg-logout-btn {
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
        .reg-logout-btn:hover   { background: #6A3580; }
        .reg-logout-btn:active  { transform: scale(0.97); }
        .reg-logout-btn:disabled { cursor: not-allowed; background: #8E5DA3; }

        .reg-logout-spinner {
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            animation: reg-spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes reg-spin { to { transform: rotate(360deg); } }
        .reg-logout-text-swap { display: inline-flex; align-items: center; }

        /* ── Top bar bell ── */
        .reg-topbar-bell {
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
        .reg-topbar-bell:hover,
        .reg-topbar-bell:focus,
        .reg-topbar-bell:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }

        /* ── Bell "wave" alert — a soft expanding ring pulse behind the
           bell, running continuously as long as there is at least one
           unread notification. Distinct from the existing fa-shake
           icon wiggle: the ring is the "something needs attention"
           signal, the shake is just the icon's own accent motion. ── */
        .reg-bell-wave {
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 2px solid #DC2626;
            opacity: 0;
            pointer-events: none;
        }
        .reg-bell-wave.is-active {
            animation: reg-bell-wave-pulse 2s ease-out infinite;
        }
        @keyframes reg-bell-wave-pulse {
            0%   { transform: scale(0.7); opacity: 0.55; }
            70%  { transform: scale(1.55); opacity: 0; }
            100% { transform: scale(1.55); opacity: 0; }
        }

        /* ── Collapsed state (desktop only, manual << >> toggle) ── */
        @media (min-width: 1024px) {
            .reg-sidebar.is-collapsed {
                width: 5rem;
                min-width: 5rem;
            }
            .reg-sidebar.is-collapsed .reg-nav-label,
            .reg-sidebar.is-collapsed .reg-nav-section-label,
            .reg-sidebar.is-collapsed .reg-nav-dot,
            .reg-sidebar.is-collapsed .reg-logout-label-text,
            .reg-sidebar.is-collapsed .reg-brand-text {
                display: none !important;
            }
            .reg-sidebar.is-collapsed .reg-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            .reg-sidebar.is-collapsed .reg-nav-icon {
                margin-right: 0;
            }
            .reg-sidebar.is-collapsed .reg-nav-section-row {
                justify-content: center;
                padding: 0 0.5rem;
            }
            .reg-sidebar.is-collapsed .reg-logout-btn {
                gap: 0;
                padding: 0.9rem;
            }
            .reg-sidebar.is-collapsed .reg-logout-btn i.fa-right-from-bracket,
            .reg-sidebar.is-collapsed .reg-logout-spinner {
                margin-right: 0 !important;
            }
            .reg-sidebar.is-collapsed .reg-brand-mark {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .reg-sidebar.is-modal-hidden {
                width: 0 !important;
                min-width: 0 !important;
                opacity: 0;
                pointer-events: none;
                overflow: hidden;
                border-right-color: transparent;
                transition: none !important;
            }
            .reg-sidebar.is-modal-hidden .reg-nav-label,
            .reg-sidebar.is-modal-hidden .reg-brand-text {
                display: none !important;
            }
        }

        @media (max-width: 1023px) {
            .reg-sidebar { box-shadow: 0 0 60px rgba(0,0,0,0.18); }

            .reg-nav-label,
            .reg-nav-section-label,
            .reg-nav-dot,
            .reg-nav-section-row {
                display: none !important;
            }
            .reg-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            .reg-nav-icon {
                margin-right: 0;
            }

            .reg-logout-btn {
                gap: 0;
                padding: 0.9rem;
            }
            .reg-logout-label-text {
                display: none;
            }
            .reg-logout-btn i.fa-right-from-bracket,
            .reg-logout-spinner {
                margin-right: 0 !important;
            }

            .reg-sidebar.is-modal-hidden {
                transform: translateX(-100%) !important;
                transition: none !important;
                pointer-events: none;
                box-shadow: none;
            }
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATIONS PANEL — item layout + icon chips
           All accents unified to purple (brand color) — no more
           per-category blue/green tints, which made items feel like
           unrelated widgets instead of one consistent list.
        ════════════════════════════════════════════════════════ */
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px 17px;
            cursor: pointer;
            border-bottom: 2px solid #B8B8B8;
            transition: background .12s ease;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #FAF7FC; }

        /* Unread = pure white + left accent bar. Read = light gray fill,
           no accent bar — so the two states are unmistakably different
           at a glance instead of blending together. */
        .notif-item.is-unread {
            background: #FFFFFF;
            border-left: 4px solid #7A3F91;
        }
        .notif-item.is-read {
            background: #FFFFFF;
            border-left: 4px solid transparent;
        }
        .notif-item.is-read:hover { background: #F7F7F7; }

        .notif-icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            background: #EDE0F5;
            color: #7A3F91;
        }

        /* Bulk Import items get a blue accent so they read as a
           distinct category from the purple New Alumni items. */
        .notif-icon-wrap.notif-icon-import {
            background: #DBEAFE;
            color: #2563EB;
        }
        .notif-tag-import {
            background: #DBEAFE !important;
            color: #2563EB !important;
        }

        /* Read state: icon fades to a muted glass instead of losing its
           color entirely. Import keeps its blue tint even when read. */
        .notif-item.is-read .notif-icon-wrap {
            background: rgba(122,63,145,0.10) !important;
            color: rgba(122,63,145,0.55) !important;
        }
        .notif-item.is-read .notif-icon-wrap.notif-icon-import {
            background: rgba(37,99,235,0.10) !important;
            color: rgba(37,99,235,0.55) !important;
        }

        .notif-body { flex: 1; min-width: 0; }

        .notif-title-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* Dark gray instead of pure black — readable without feeling
           overly heavy/bold. */
        .notif-title-text {
            font-size: .85rem;
            font-weight: 600;
            color: #262626;
        }
        .notif-title-text.is-read { font-weight: 500; color: #333333; }

        .notif-tag {
            font-size: .6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
            background: #EDE0F5;
            color: #7A3F91;
        }

        .notif-count-badge {
            font-size: .65rem;
            font-weight: 800;
            background: #7A3F91;
            color: #fff;
            padding: 1px 6px;
            border-radius: 999px;
        }

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

        .notif-message-text {
            font-size: .8rem;
            color: #4D4D4D;
            line-height: 1.45;
            margin-top: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-time-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 5px;
            margin-top: 6px;
            padding-right: 72px;
            font-size: .68rem;
            color: #333333;
            font-weight: 600;
            position: relative;
        }
        /* Only the clock icon before the timestamp — NOT the delete
           button's trash icon, which also lives inside this row but
           needs to stay large/tappable (see .notif-delete-btn i
           below, which sets its own much bigger size). */
        .notif-time-row > span > i.fa-clock { font-size: .62rem; }

        /* ── Delete icon (only shown once a notif is 30+ days old) ──
           Sits at the end of the time row, next to the timestamp.
           Red icon by default so it's visible right away in the
           sidebar (not just on hover), with a slightly deeper red +
           light-red bg on hover for feedback. */
        .notif-delete-btn {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 76px !important;
            height: 76px !important;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #DC2626;
            cursor: pointer;
            flex-shrink: 0;
            transition: color .15s ease;
        }
        .notif-delete-btn:hover {
            color: #B91C1C;
        }
        .notif-delete-btn i,
        .notif-delete-btn i.fa-trash-can,
        .notif-delete-btn i.fas {
            font-size: 5.6rem !important;
            line-height: 1 !important;
            pointer-events: none;
        }

        .notif-delete-tooltip {
            position: absolute;
            bottom: calc(100% - 22px);
            left: 50%;
            transform: translate(-50%, 2px);
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
            transition: opacity .12s ease, transform .12s ease;
            z-index: 10;
        }
        .notif-delete-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: #DC2626;
        }
        .notif-delete-btn:hover .notif-delete-tooltip {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        /* ── In-place notif loading overlay ─────────────────────────
           Same visual language as the alumni table's loading spinner
           (fa-spinner fa-spin, brand purple) — one consistent loading
           interface across the app instead of a different spinner
           style per screen. The item's own content (icon/title/
           message) blurs out underneath instead of being fully
           covered by a flat white block, so it still reads as "this
           item is busy" rather than an empty white gap. ── */
        .notif-item { position: relative; }
        .notif-item.is-loading > *:not(.notif-item-loading-overlay) {
            filter: blur(4px);
            opacity: 0.5;
            pointer-events: none;
            user-select: none;
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

        .notif-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px 6px;
        }
        .notif-divider::before,
        .notif-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #EDE4F5;
        }
        .notif-divider-label {
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #9B85AC;
            white-space: nowrap;
        }

        /* ── Mobile notification panel — full screen ──────────────
           Claims the entire viewport on mobile so there's maximum
           room to read notifications comfortably. ── */
        @media (max-width: 1023px) {
            #notif-panel {
                inset: 0 !important;
                width: 100vw !important;
                height: 100dvh !important;
                max-height: 100dvh !important;
                min-height: 0 !important;
                border-radius: 0 !important;
                border: none !important;
                box-shadow: none !important;
                /* Full-screen panel: the desktop enter/leave transition
                   (scale-[0.98] + -translate-y-1) shrinks/shifts the box
                   just enough to flash a sliver of the page behind it at
                   the edges when closing. Neutralizing the transform only
                   (not touching transition timing) keeps Alpine's own
                   fast leave duration intact — just a plain, quick fade.
                   Also drop the desktop min-height/height transition —
                   on a fixed full-screen panel it has nothing useful to
                   animate and was stacking with the fade, making close
                   feel like a slow multi-stage "loading" settle instead
                   of an instant, clean dismiss. */
                transform: none !important;
                transition: opacity 150ms ease-out !important;
            }
            #notif-list {
                max-height: calc(100dvh - 150px) !important;
            }
            #notif-footer-hint {
                display: none !important;
            }
            #notif-delete-toast {
                left: 50% !important;
                top: auto !important;
                bottom: 24px !important;
                transform: translateX(-50%) !important;
            }

            /* Delete icon was tiny/hard to tap on mobile — enlarge the
               tap target and icon size, and give the time row room so
               it doesn't get squeezed against the timestamp. */
            .notif-time-row {
                flex-wrap: nowrap;
            }
            .notif-delete-btn {
                width: 76px !important;
                height: 76px !important;
                flex-shrink: 0;
            }
            .notif-delete-btn i {
                font-size: 5.6rem !important;
            }
            .notif-delete-tooltip {
                display: none;
            }
        }
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  ROUTE MAP — resolved server-side via route(), so this can never drift
    //  out of sync with routes/web.php the way a hardcoded string map can.
    // ─────────────────────────────────────────────────────────────────────────
    window.__registrarRouteMap = {
        'registrar.alumni':              @json(route('registrar.alumni')),
        'registrar.dashboard':           @json(route('registrar.dashboard')),
        'registrar.employment.tracking': @json(route('registrar.employment.tracking')),
        'registrar.alumni.register':     @json(route('registrar.alumni.register')),
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  GLOBAL "MODAL OPEN" STORE
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:init', function () {
        if (!Alpine.store('modal')) {
            Alpine.store('modal', { open: false });
        }
    });
    window.addEventListener('modal-opened', function () {
        var s = window.Alpine && Alpine.store('modal');
        if (s) s.open = true;
    });
    window.addEventListener('modal-closed', function () {
        var s = window.Alpine && Alpine.store('modal');
        if (s) s.open = false;
    });
    document.addEventListener('livewire:navigated', function () {
        var s = window.Alpine && Alpine.store('modal');
        if (s) s.open = false;
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  Pause the notification poll while a photo is being saved elsewhere on
    //  the page (e.g. alumni-records photo upload) — a poll landing mid-save
    //  competes for one of the browser's 6 concurrent connections and can
    //  delay the save request by several seconds for no benefit to the user.
    // ─────────────────────────────────────────────────────────────────────────
    window.__notifPollSuspended = false;
    document.addEventListener('photo-save-start', function () {
        window.__notifPollSuspended = true;
    });
    document.addEventListener('photo-save-end', function () {
        window.__notifPollSuspended = false;
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeNotifsStore = function () {
        return {
            open:       false,
            items:      [],
            _pollTimer: null,
            navigating: false,
            loadingId:  null,
            markingAll: false,
            deletingId: null,
            deleteToast: { show: false, message: '' },
            async init() {
                await this._fetch();
                this._startPolling();
            },
            _startPolling() {
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () {
                    if (window.__notifPollSuspended) return; // paused e.g. during a photo save
                    self._fetch();
                }, 5000);
            },
            async _fetch() {
                if (this._deleting) return; // don't let a poll refresh clobber an in-flight delete
                try {
                    var res = await window.fetch('/registrar/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.ok) {
                        var raw = await res.json();
                        this.items = this._groupByDay(raw);
                    }
                } catch (e) { /* silently fail */ }
            },

            _groupByDay(rows) {
                var map = new Map();
                Array.from(rows)
                    .filter(function (n) {
                        var rawDedup = n.dedup_key || '';
                        var isEmpEvent = (
                            rawDedup.startsWith('employment_update') ||
                            rawDedup.startsWith('employment::')      ||
                            rawDedup.startsWith('recorded::')        ||
                            rawDedup.startsWith('updated::')         ||
                            n.title === 'Employment Status Updated'  ||
                            n.title === 'New Employment Record'      ||
                            n.icon  === 'arrow-rotate-right'
                        );
                        var isProfileEvent = (
                            rawDedup.startsWith('profile_update::') ||
                            n.title === 'Alumni Profile Updated'    ||
                            n.icon  === 'user-pen'
                        );
                        return !isEmpEvent && !isProfileEvent;
                    })
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var rawDedup = n.dedup_key || '';
                        var nAlumniIds = Array.isArray(n.alumni_ids) ? n.alumni_ids : [];
                        var nTimestamp = n.updated_at && new Date(n.updated_at) > new Date(n.created_at)
                            ? n.updated_at
                            : n.created_at;
                        // Group by LOCAL calendar day, not UTC — toISOString()
                        // always converts to UTC, which silently shifts any
                        // timestamp made after 4:00 PM local (midnight PH time
                        // is UTC+8) back onto the previous day's UTC date,
                        // merging what should be a fresh day's notification
                        // into yesterday's group.
                        var day = 'unknown';
                        if (nTimestamp) {
                            var _d = new Date(nTimestamp);
                            day = _d.getFullYear() + '-'
                                + String(_d.getMonth() + 1).padStart(2, '0') + '-'
                                + String(_d.getDate()).padStart(2, '0');
                        }

                        var isChatMsg = (
                            rawDedup.startsWith('chat_msg::') ||
                            n.icon === 'comment-dots'
                        );
                        var isAlumniEvent = (
                            rawDedup === 'registered' ||
                            n.title  === 'New Alumni Registered'
                        );
                        var isImportEvent = (
                            rawDedup === 'imported' ||
                            n.title  === 'Bulk Import Complete'
                        );

                        var groupKey;
                        if (isChatMsg) {
                            var roomSlug = rawDedup.replace('chat_msg::', '') || 'chat';
                            groupKey = 'chat_msg::' + roomSlug + '::' + day;
                        } else if (isAlumniEvent) {
                            groupKey = 'alumni_registered::' + day;
                        } else if (isImportEvent) {
                            groupKey = 'alumni_imported::' + day;
                        } else {
                            groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id);
                        }

                        // Pull the individual alumni's display name out of this
                        // row so a grouped notif (count > 1) can list WHO was
                        // registered, not just a generic number. Prefer the
                        // explicit alumni_name field sent at creation time;
                        // fall back to parsing it out of the message text for
                        // rows saved before that field existed.
                        var nName = n.alumni_name || (function () {
                            var m = (n.message || '').match(/^(.*?)\s*\(ID:/);
                            return m ? m[1].trim() : '';
                        })();

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            // Use the backend's own count/alumni_ids when this
                            // row already represents a merged backend group
                            // (count > 1 or 2+ alumni_ids) rather than blindly
                            // adding 1 — otherwise a backend-merged row gets
                            // double-counted on top of the frontend's own
                            // grouping pass.
                            var nCount = Number(n.count) || 1;
                            g.count = Math.max(g.count, g._ids.length + nCount);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);
                            if (nAlumniIds.length) {
                                g.alumni_ids = (g.alumni_ids || []).concat(nAlumniIds)
                                    .filter(function (v, i, arr) { return arr.indexOf(v) === i; });
                            }
                            // Track each name together with when it happened
                            // so we can always tell who's most recent, not
                            // just who was first seen while grouping.
                            if (nName) {
                                var existing = g._nameEntries.filter(function (e) { return e.name === nName; })[0];
                                if (existing) {
                                    if (nTimestamp && new Date(nTimestamp) > new Date(existing.at)) {
                                        existing.at = nTimestamp;
                                    }
                                } else {
                                    g._nameEntries.push({ name: nName, at: nTimestamp || n.created_at });
                                }
                            }
                            if (nTimestamp && new Date(nTimestamp) > new Date(g.created_at)) {
                                g.created_at = nTimestamp;
                            }
                            // Oldest -> newest, so the most recently
                            // registered alumnus is always named last —
                            // e.g. "... and Dwight P. Ramos have been
                            // registered" when he's the latest arrival.
                            g._names = g._nameEntries
                                .slice()
                                .sort(function (a, b) { return new Date(a.at) - new Date(b.at); })
                                .map(function (e) { return e.name; });
                            if (isChatMsg) {
                                var rName = g._roomName || 'group chat';
                                g.message = g.count + ' new message(s) in ' + rName + '.';
                            } else if (isAlumniEvent) {
                                // Show every distinct name we actually have,
                                // most-recent last, e.g. "Kai K. Sotto II
                                // and Dwight P. Ramos have been registered
                                // and are now verified." — falls back to
                                // the generic count wording only if no
                                // names came through at all.
                                g.title = 'New Alumni Registered';
                                if (g._names.length > 0 && g._names.length >= g.count) {
                                    g.message = (g._names.length === 1
                                        ? g._names[0]
                                        : g._names.slice(0, -1).join(', ') + ' and ' + g._names.slice(-1)
                                    ) + ' have been registered and are now verified.';
                                } else if (g._names.length > 0) {
                                    var extra = g.count - g._names.length;
                                    g.message = g._names.join(', ') + ' and ' + extra + ' other(s) have been registered today.';
                                } else {
                                    g.message = g.count + ' new alumni registered today.';
                                }
                            } else if (isImportEvent) {
                                g.message = g.count + ' alumni record(s) imported today.';
                                g.title   = 'Bulk Import Complete';
                            }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count:       Number(n.count) || 1,
                                _ids:        [n.id],
                                _roomName:   n._roomName || '',
                                _names:      nName ? [nName] : [],
                                _nameEntries: nName ? [{ name: nName, at: nTimestamp || n.created_at }] : [],
                                alumni_ids:  nAlumniIds.slice(),
                                created_at:  nTimestamp || n.created_at,
                                title: isChatMsg      ? (n.title || 'New Chat Message')
                                     : isAlumniEvent  ? 'New Alumni Registered'
                                     : isImportEvent  ? 'Bulk Import Complete'
                                     : n.title,
                                icon: isChatMsg      ? 'comment-dots'
                                    : (n.icon || 'bell'),
                            }));
                        }
                    });
                // Re-sort the grouped notifications themselves by their
                // (possibly just-updated) timestamp, newest first. Without
                // this, a group that absorbs a brand-new member (e.g. Papa
                // Dwight registering at 11:49 merging into today's "New
                // Alumni Registered" group) would stay stuck wherever it
                // first appeared in Map insertion order — e.g. still below
                // "Bulk Import Complete" — instead of jumping back to the
                // top where the newest activity belongs.
                return Array.from(map.values()).sort(function (a, b) {
                    return new Date(b.created_at) - new Date(a.created_at);
                });
            },

            get unread() {
                return this.items.filter(function (n) { return !n.read; }).length;
            },
            toggle() { this.open = !this.open; },
            close()  {
                // Don't let the panel be closed (outside click, etc.)
                // while a notif click is still navigating/loading — it
                // should only close once livewire:navigated fires.
                if (this.navigating) return;
                this.open = false;
            },

            async markRead(item) {
                this.navigating = true;
                this.loadingId  = item.id;
                var clearedByNav = false;
                try {
                    if (!item.read) {
                        item.read = true;
                        var ids  = item._ids || [item.id];
                        var csrf = document.querySelector('meta[name="csrf-token"]').content;
                        for (var i = 0; i < ids.length; i++) {
                            try {
                                await window.fetch('/registrar/notifications/' + ids[i] + '/read', {
                                    method: 'PATCH',
                                    headers: {
                                        'X-CSRF-TOKEN':     csrf,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    }
                                });
                            } catch (e) { /* ignore */ }
                        }
                    }
                    clearedByNav = await this.goToTarget(item);
                } finally {
                    // If goToTarget actually kicked off a navigation, leave
                    // `navigating` (and `loadingId`) on — cleared once the
                    // destination page is truly ready (see livewire:navigated
                    // listener below), so the overlay never drops early and
                    // the panel never closes/navigates-looking before the
                    // page is actually ready.
                    if (!clearedByNav) {
                        this.navigating = false;
                        this.loadingId  = null;
                    }
                }
            },

            // Navigate to wherever this notif points, carrying the affected
            // record IDs as ?highlight=1,2,3 so the destination page can
            // jump to the right pagination page and blue-highlight the
            // matching row(s) — same behavior as before, restored here.
            //
            // When the notif represents a GROUP (bulk import, or "N alumni
            // registered"), also carry ?scope_title= so the destination
            // page's notif-scoped-view banner can say which notification
            // the narrowed table came from.
            //
            // ⚠️ FIX: the in-memory `item` here can be STALE. This store
            // only re-fetches on a 5s poll interval, so if a 2nd/3rd
            // registration or import merged into this notif's DB row
            // AFTER the last poll but BEFORE the user clicked it, the
            // clicked item's alumni_ids would only contain the older,
            // smaller set (e.g. just Fernandos, missing Loki) — causing
            // the destination page to highlight/scope only 1 of the 2
            // actual records. Re-fetch this specific notif's row fresh
            // from the server right before navigating, so we always
            // carry the true, fully-merged alumni_ids — not whatever
            // snapshot happened to be sitting in the store.
            async goToTarget(item) {
                var routeName = item.link_route;
                if (!routeName || !window.__registrarRouteMap || !window.__registrarRouteMap[routeName]) return false;

                var alumniIds = Array.isArray(item.alumni_ids) ? item.alumni_ids.filter(Boolean) : [];

                var freshIds = await this._fetchFreshAlumniIds(item);
                if (freshIds !== null) alumniIds = freshIds;

                var base = window.__registrarRouteMap[routeName];
                var url  = base;
                if (alumniIds.length > 0) {
                    url += (base.indexOf('?') === -1 ? '?' : '&') + 'highlight=' + alumniIds.join(',');
                    if (alumniIds.length > 1 && item.title) {
                        url += '&scope_title=' + encodeURIComponent(item.title);
                    }
                }

                // ⚠️ FIX: don't close() here anymore. Closing the panel the
                // instant the click happens made it look like the notif
                // "did nothing" while the request/navigation was still in
                // flight. The panel now stays open, showing the in-place
                // spinner overlay on the clicked item, and only closes once
                // the destination page has actually landed (SPA case: the
                // livewire:navigated listener below; hard-nav case: the page
                // unloads anyway so it doesn't matter).

                // ⚠️ FIX: if the notif points to the SAME route the user is
                // already sitting on (e.g. clicking a notif while already
                // viewing Alumni Records), Livewire.navigate() only swaps
                // the query string of a component that's already mounted —
                // it does NOT re-run mount() on same-component SPA
                // transitions in every Livewire setup, so the new
                // ?highlight=/?scope_title= params silently never get
                // processed and the table stays unscoped. A full hard
                // navigation (window.location.href) always re-runs mount()
                // from scratch, so force that path specifically when
                // target === current location, and keep the fast SPA
                // navigate for the normal cross-page case.
                var isSameLocation = (function () {
                    try {
                        var current = window.location.pathname + window.location.search;
                        var targetPath = url.split('?')[0];
                        return window.location.pathname === targetPath;
                    } catch (e) { return false; }
                })();

                if (isSameLocation) {
                    window.location.href = url; // hard nav — page unloads, spinner naturally ends with it
                } else if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                    window.Livewire.navigate(url); // SPA nav — spinner cleared by livewire:navigated listener
                } else {
                    window.location.href = url;
                }
                return true;
            },

            // Re-fetches the notifications list and returns the freshest
            // merged alumni_ids for the given (possibly grouped) item, by
            // matching on the underlying DB row id(s) it represents.
            // Returns null on any failure so the caller falls back to
            // whatever was already in memory instead of breaking nav.
            async _fetchFreshAlumniIds(item) {
                try {
                    var res = await window.fetch('/registrar/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return null;

                    var raw = await res.json();
                    var wantedIds = item._ids || [item.id];

                    var merged = [];
                    Array.from(raw).forEach(function (n) {
                        if (wantedIds.indexOf(n.id) === -1) return;
                        var rowIds = Array.isArray(n.alumni_ids) ? n.alumni_ids : [];
                        rowIds.forEach(function (id) {
                            if (id && merged.indexOf(id) === -1) merged.push(id);
                        });
                    });

                    return merged;
                } catch (e) {
                    return null;
                }
            },

            async markAllRead() {
                this.markingAll = true;
                this.items.forEach(function (n) { n.read = true; });
                try {
                    await window.fetch('/registrar/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                } catch (e) { /* ignore */ }
                this.markingAll = false;
                // Panel stays open — marking all read never closes it.
            },

            // Deletes a notification MESSAGE only — never the underlying
            // alumni record, employment update, or import batch that
            // generated it. This just clears the row(s) from the
            // `notifications` table so the panel/list gets shorter; the
            // actual alumni/import data this notif was about is untouched.
            //
            // Only ever called for notifs that are 30+ days old (enforced
            // by the x-show on the delete button in the markup), so this
            // is purely a "clean up old noise" action, not a moderation
            // action on real data.
            async deleteNotif(item) {
                var ids = item._ids || [item.id];
                var self = this;
                this._deleting = true;
                this.deletingId = item.id;
                this._showDeleteToast('Notification deleted');

                // Show a brief spinner on the item itself so the delete
                // reads as a deliberate action, not an instant disappear —
                // then play the slide-out leave transition before actually
                // removing it from the array.
                await new Promise(function (resolve) { setTimeout(resolve, 600); });
                this.deletingId = null;
                await new Promise(function (resolve) { setTimeout(resolve, 250); });
                this.items = this.items.filter(function (n) { return n !== item; });

                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                var failedIds = [];

                for (var i = 0; i < ids.length; i++) {
                    try {
                        var res = await window.fetch('/registrar/notifications/' + ids[i], {
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

                this._deleting = false;

                // If any delete calls actually failed server-side, put the
                // item back rather than silently losing it from view while
                // it still exists in the DB.
                if (failedIds.length > 0) {
                    await this._fetch();
                    this._showDeleteToast('Delete failed, please try again');
                }
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
                }, 5000);
            },
        };
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SAFE ACCESSOR
    // ─────────────────────────────────────────────────────────────────────────
    window.__safeNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('notifs');
                if (s) return s;
            }
        } catch (e) { /* ignore */ }
        return null;
    };

    window.__bootNotifsStore = function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('notifs')) {
            Alpine.store('notifs', window.__makeNotifsStore());
        }
        var s = Alpine.store('notifs');
        if (!s) return;
        if (!s._pollTimer) s.init();
    };

    document.addEventListener('alpine:init', function () {
        Alpine.store('notifs', window.__makeNotifsStore());
    });

    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    window.addEventListener('load', function () {
        var s = window.__safeNotifsStore();
        if (s) {
            if (s.items.length === 0) s.init();
        } else {
            window.__bootNotifsStore();
        }
    });

    document.addEventListener('livewire:navigated', function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('notifs');
        if (s) {
            if (s._pollTimer) clearInterval(s._pollTimer);
            s._pollTimer = null;
            s.open  = false;
            s.navigating = false; // destination page has landed — drop the spinner now, not before
            s.loadingId  = null;
            s.init();
        } else {
            Alpine.store('notifs', window.__makeNotifsStore());
            var newStore = Alpine.store('notifs');
            if (newStore) newStore.init();
        }
    });

    ;(function immediateBootOnSpaNavigation() {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('notifs');
        if (!s) {
            Alpine.store('notifs', window.__makeNotifsStore());
            s = Alpine.store('notifs');
        }
        if (s && !s._pollTimer) {
            setTimeout(function () { s.init(); }, 100);
        }
    })();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING
    // ─────────────────────────────────────────────────────────────────────────
    function positionPanel() {
        var btn   = document.getElementById('bell-btn');
        var panel = document.getElementById('notif-panel');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        if (window.innerWidth >= 1024) {
            panel.style.left  = (btnRect.right - 400) + 'px';
            panel.style.top   = (btnRect.bottom  + 8)  + 'px';
            panel.style.width = '400px';
        }
        // Mobile: the CSS media query (#notif-panel !important rules)
        // fully owns bottom-sheet layout — no inline overrides needed here.
    }
    window.positionPanel = positionPanel;
    window.addEventListener('resize', function () {
        var s = window.__safeNotifsStore();
        if (s && s.open) positionPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  FIX: re-anchor the panel to the bell EVERY time it's shown, not just
    //  when the bell itself is clicked. Previously, clicking a notif item
    //  (e.g. "Bulk Import Complete") calls markRead() → Livewire.navigate(),
    //  and if the panel's `open` flips true again outside the bell's own
    //  @click handler, the panel kept whatever left/top it had before (or
    //  fell back to the hardcoded top:88px;left:12px in the markup) instead
    //  of the bell's actual position on the new page — hence it popping up
    //  on the wrong side of the screen. Watching `open` directly guarantees
    //  positionPanel() runs on every single open, from every trigger.
    document.addEventListener('alpine:init', function () {
        Alpine.effect(function () {
            var s = window.Alpine && Alpine.store('notifs');
            if (s && s.open) {
                // rAF: wait one frame so #notif-panel/#bell-btn have their
                // post-navigation layout settled before we measure them.
                requestAnimationFrame(function () { positionPanel(); });
            }
        });
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  LIVEWIRE → DB BRIDGE
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstNotifListeners) {
        window.__philcstNotifListeners = true;

        function _detail(e) {
            var d = e.detail;
            if (!d) return {};
            return Array.isArray(d) ? (d[0] || {}) : d;
        }

        async function _saveNotif(payload) {
            try {
                await window.fetch('/registrar/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        window.addEventListener('alumni-registered', function (e) {
            var d = _detail(e);
            _saveNotif({
                icon:        'user-graduate',
                title:       'New Alumni Registered',
                message:     (d.name || 'Alumni') + ' (ID: ' + (d.id || '—') + ') has been registered and is now verified.',
                link_route:  'registrar.alumni',
                link_label:  'View Alumni',
                dedup_key:   'registered',
                alumni_ids:  d.id ? [d.id] : [],
                // ✅ Needed so the backend can rebuild the grouped message
                //    (e.g. "Fernandos and 1 other have been registered...")
                //    on the 2nd+ registration of the same day, instead of
                //    only ever showing whichever alumni registered last.
                alumni_name: d.name || 'Alumni',
            });
        });

        window.addEventListener('alumni-imported', function (e) {
            var d = _detail(e);
            // ✅ A bulk import fires this event exactly once for the whole
            //    batch (not once per row), so there's nothing for the
            //    backend's same-day dedup/merge to group against on this
            //    call alone. Build the full "N record(s) imported" message
            //    here in JS using the batch's own count so it's correct
            //    immediately, then still send alumni_ids so the "view"
            //    click can highlight every imported row, and dedup_key so
            //    a second import later today still merges/increments
            //    correctly instead of creating a separate notif.
            var importedCount = Number(d.count) || 0;
            var ids = Array.isArray(d.ids) ? d.ids : [];
            _saveNotif({
                icon:       'file-import',
                title:      'Bulk Import Complete',
                message:    importedCount + ' alumni record(s) imported successfully via CSV/Excel.',
                link_route: 'registrar.alumni',
                link_label: 'View Alumni',
                dedup_key:  'imported',
                alumni_ids: ids,
            });
        });

        // NOTE: 'employment-recorded', 'employment-updated', and 'profile-updated'
        // no longer create bell notifications on purpose — registrar only wants
        // New Alumni Registered, Bulk Import Complete, and chat messages here.

        window.addEventListener('message-received', function (e) {
            var d = _detail(e);
            var sender   = d.sender   || 'Someone';
            var roomName = d.room     || 'Group Chat';
            var bodySnip = d.body     || '';
            var count    = Number(d.count) || 1;
            if (bodySnip.length > 60) bodySnip = bodySnip.substring(0, 57) + '…';
            var message = count > 1
                ? sender + ' and others sent ' + count + ' new message(s) in ' + roomName + '.'
                : sender + ': ' + bodySnip;
            _saveNotif({
                icon:       'comment-dots',
                title:      'New Message — ' + roomName,
                message:    message,
                link_route: 'registrar.alumni',
                link_label: 'View',
                dedup_key:  'chat_msg::' + roomName,
                _roomName:  roomName,
            });
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FASTER NAV LINK CLICKS — prefetch on hover/touchstart
    //  FIX: sidebar links only started loading the next page's data the
    //  instant they were clicked. Livewire's wire:navigate supports
    //  prefetching a page on hover so by the time the click actually
    //  lands, the response is already back (or nearly there) — this
    //  wires that up for every sidebar link without needing to touch
    //  the wire:navigate directive itself (Livewire fires this from a
    //  plain mouseenter/touchstart using its own internal prefetch).
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('livewire:init', function () {
        function prefetchOnIntent(el) {
            var done = false;
            function go() {
                if (done) return;
                done = true;
                try {
                    if (window.Livewire && typeof Livewire.navigate === 'function' && Livewire.navigate.prefetch) {
                        Livewire.navigate.prefetch(el.href);
                    } else if (window.Alpine && el.href) {
                        // Fallback: warm the HTTP cache for the target URL.
                        fetch(el.href, { headers: { 'X-Livewire-Navigate-Prefetch': 'true' } }).catch(function(){});
                    }
                } catch (e) { /* ignore */ }
            }
            el.addEventListener('mouseenter', go, { passive: true });
            el.addEventListener('touchstart', go, { passive: true });
            el.addEventListener('focus', go, { passive: true });
        }
        document.querySelectorAll('.reg-nav-link[href]').forEach(prefetchOnIntent);
    });
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased"
      x-data="{
          sidebarOpen: false,
          sidebarCollapsed: localStorage.getItem('reg_sidebar_collapsed') === '1',
          loggingOut: false,
          navClickedRoute: null
      }"
      x-init="$watch('sidebarCollapsed', function (val) { localStorage.setItem('reg_sidebar_collapsed', val ? '1' : '0'); })"
      @@livewire:navigated.window="navClickedRoute = null">


<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen && !($store.modal && $store.modal.open)"
         x-cloak
         x-transition:enter="transition opacity-ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition opacity-ease-in duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="fixed inset-0 z-[9990] bg-black/50 lg:hidden">
    </div>

    {{-- ══ SIDEBAR ══════════════════════════════════════════════════════════ --}}
    <aside :class="{
                'translate-x-0':  sidebarOpen && !($store.modal && $store.modal.open),
                'is-collapsed':   sidebarCollapsed,
                'is-modal-hidden': ($store.modal && $store.modal.open)
           }"
           class="reg-sidebar fixed inset-y-0 left-0 z-[9995] w-20 min-w-[5rem] lg:w-72 lg:min-w-[18rem] -translate-x-full transform
                  transition-transform duration-300
                  lg:translate-x-0 lg:static lg:inset-0
                  flex flex-col h-full text-[#333333] shrink-0">

        {{-- Sidebar Header — brand mark + wordmark --}}
        <div class="flex items-center gap-3 justify-center lg:justify-start h-24 px-2 lg:px-5 border-b border-[#E5E5E5] shrink-0">
            <div class="reg-brand-mark">
                <i class="fa-solid fa-book-bookmark"></i>
            </div>
            <div class="min-w-0 hidden lg:block reg-brand-text">
                <p class="text-[15px] uppercase tracking-[0.18em] font-extrabold text-[#7A3F91] leading-none">
                    PHILCST
                </p>
                <p class="text-[13px] tracking-[0.04em] font-medium text-black mt-1.5 leading-none">
                    Records Management
                </p>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-2 lg:px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">

            <div class="reg-nav-section-row">
                <p class="reg-nav-section-label">MENU</p>

                <button type="button"
                        @click="sidebarCollapsed = !sidebarCollapsed"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        class="reg-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="{ 'fa-angles-right': sidebarCollapsed, 'fa-angles-left': !sidebarCollapsed }"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                // FIX: each link now carries a `color` key mapped to a
                // .clr-* class on its icon chip, so the sidebar can be
                // scanned by color as well as label — blue for the
                // overview/data view, purple for the people directory,
                // green for the "add new" action, amber for tracking
                // movement over time.
                $sidebarLinks = [
                    ['route' => 'registrar.dashboard',           'icon' => 'gauge-high', 'label' => 'Dashboard',            'color' => 'clr-dashboard'],
                    ['route' => 'registrar.alumni',              'icon' => 'users',       'label' => 'Alumni Records',       'color' => 'clr-alumni'],
                    ['route' => 'registrar.alumni.register',     'icon' => 'user-plus',   'label' => 'Register Alumni',      'color' => 'clr-register'],
                    ['route' => 'registrar.employment.tracking', 'icon' => 'chart-line',  'label' => 'Employment Tracking',  'color' => 'clr-employment'],
                ];
            @endphp
            @foreach($sidebarLinks as $link)
                @php $isActive = request()->routeIs($link['route']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="sidebarOpen = false; navClickedRoute = '{{ $link['route'] }}';"
                   :class="{ 'is-navigating': navClickedRoute === '{{ $link['route'] }}' }"
                   class="reg-nav-link {{ $isActive ? 'is-active' : '' }}">
                    <div class="reg-nav-icon {{ $link['color'] }}">
                        <i class="fa-solid fa-{{ $link['icon'] }}"
                           x-show="!(navClickedRoute === '{{ $link['route'] }}' && (sidebarCollapsed || window.innerWidth < 1024))"></i>
                        <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                            <span class="reg-nav-spinner-icon-anchored">
                                <i class="fas fa-spinner fa-spin reg-nav-spinner"></i>
                            </span>
                        </template>
                    </div>
                    <span class="reg-nav-label">{{ $link['label'] }}</span>
                    <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                        <i class="fas fa-spinner fa-spin reg-nav-spinner"></i>
                    </template>
                    @if($isActive)
                        <template x-if="navClickedRoute !== '{{ $link['route'] }}'">
                            <span class="reg-nav-dot"></span>
                        </template>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Logout --}}
        <div class="p-2 lg:p-4 mt-auto border-t border-[#E5E5E5] shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit="loggingOut = true">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        title="Logout"
                        class="reg-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="reg-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>
                            <span class="reg-logout-label-text">Logout</span>
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="reg-logout-text-swap">
                            <span class="reg-logout-spinner mr-2"></span>
                            <span class="reg-logout-label-text">Logging out…</span>
                        </span>
                    </template>
                </button>
            </form>
        </div>
    </aside>

    {{-- ══ MAIN CONTENT ═════════════════════════════════════════════════════ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        <header class="sticky top-0 flex items-center justify-between px-4 lg:px-8 h-24 bg-white border-b border-[#E8E0F0]
                       shrink-0 z-30">
            <button @click="sidebarOpen = !sidebarOpen"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors lg:hidden">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="sidebarOpen ? 'rotate-45 translate-y-2' : ''"
                          class="reg-hamburger-line w-full h-0.5 transition-all duration-300 origin-center"></span>
                    <span :class="sidebarOpen ? 'opacity-0' : ''"
                          class="reg-hamburger-line w-full h-0.5 transition-all duration-300"></span>
                    <span :class="sidebarOpen ? '-rotate-45 -translate-y-2.5' : ''"
                          class="reg-hamburger-line w-full h-0.5 transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <span class="hidden lg:block"></span>

            {{-- Notifications bell --}}
            <button
                id="bell-btn"
                type="button"
                @click.stop="positionPanel(); $store.notifs && $store.notifs.toggle();"
                title="Notifications"
                aria-label="Open notifications"
                class="reg-topbar-bell">
                {{-- FIX: expanding "wave" ring behind the bell — pulses
                     continuously whenever there's at least one unread
                     notification, instead of only the icon's own shake. --}}
                <span class="reg-bell-wave" :class="$store.notifs && $store.notifs.unread > 0 ? 'is-active' : ''"></span>
                <i class="bell-icon fas fa-bell"
                   :class="$store.notifs && $store.notifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91; position:relative;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>
                <span
                    x-show="$store.notifs && $store.notifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.notifs && $store.notifs.unread > 99
                                ? '99+'
                                : ($store.notifs ? $store.notifs.unread : 0)">
                </span>
            </button>
        </header>

        {{-- Page content --}}
        <div class="flex-1 overflow-y-auto no-scrollbar bg-[#F5F5F5] p-4 lg:p-8">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     NOTIFICATION PANEL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="notif-panel"
    class="notif-no-select"
    x-show="$store.notifs && $store.notifs.open"
    x-cloak
    x-transition:enter="transition ease-out duration-100"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @click.stop
    @contextmenu.prevent
    @copy.prevent
    style="
        position: fixed;
        top: -9999px;
        left: -9999px;
        width: 400px;
        z-index: 9999;
        transform-origin: top left;
        min-height: 520px;
        background-color: #FFFFFF;
        opacity: 1;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        border-radius: 16px;
        border: 1px solid #E0D8ED;
        box-shadow: 0 12px 28px -6px rgba(90,34,112,0.22), 0 2px 8px rgba(0,0,0,0.06);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: min-height 0.3s ease, height 0.3s ease;
    ">

    {{-- Panel Header --}}
    <div style="background:#5A2270; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-bell" style="color:#fff; font-size:15px; opacity:0.85;"></i>
            <span style="font-size:14px; font-weight:600; color:#fff; letter-spacing:0.02em;">Notifications</span>
            <span
                x-show="$store.notifs && $store.notifs.unread > 0"
                x-cloak
                style="background:#DC2626; color:#fff; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; letter-spacing:0.04em;"
                x-text="($store.notifs ? $store.notifs.unread : 0) + ' new'">
            </span>
        </div>
        <div style="display:flex; align-items:center; gap:4px;">
            <button type="button"
                    x-show="$store.notifs && ($store.notifs.unread > 0 || $store.notifs.markingAll)"
                    x-cloak
                    :disabled="$store.notifs && $store.notifs.markingAll"
                    @click.stop="$store.notifs && $store.notifs.markAllRead()"
                    style="background:transparent; border:0.5px solid rgba(255,255,255,0.3); color:rgba(255,255,255,0.85); font-size:11px; font-weight:500; padding:5px 10px; border-radius:8px; cursor:pointer; letter-spacing:0.02em; transition:background 0.15s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.12)'"
                    onmouseout="this.style.background='transparent'">
                Mark all read
            </button>
            <button type="button"
                    @click.stop="$store.notifs && $store.notifs.close()"
                    aria-label="Close notifications"
                    style="width:28px; height:28px; background:transparent; border:none; color:rgba(255,255,255,0.55); font-size:14px; cursor:pointer; border-radius:7px; display:flex; align-items:center; justify-content:center; transition:background 0.15s, color 0.15s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.12)'; this.style.color='#fff';"
                    onmouseout="this.style.background='transparent'; this.style.color='rgba(255,255,255,0.55)';">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </div>

    {{-- Sub-header --}}
    <div style="background:#FFFFFF; padding:9px 18px; display:flex; align-items:center; justify-content:space-between; border-bottom:0.5px solid #E0D8ED; flex-shrink:0;">
        <span style="font-size:11px; font-weight:600; color:#7A3F91; letter-spacing:0.1em; text-transform:uppercase;">Recent Activity</span>
        <span x-show="$store.notifs && $store.notifs.items.length > 0"
              x-cloak
              style="font-size:11px; font-weight:700; color:#7A3F91; background:#F0E9F6; padding:2px 9px; border-radius:999px;"
              x-text="$store.notifs ? $store.notifs.items.length : 0">
        </span>
    </div>

    {{-- Delete toast — slides in ABOVE the list, inside the panel --}}
    <div
        x-show="$store.notifs && $store.notifs.deleteToast.show"
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
              x-text="$store.notifs ? $store.notifs.deleteToast.message : ''"></span>
    </div>

    {{-- Scrollable list --}}
    <div id="notif-list" class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px; min-height: 400px;">

        <template x-if="$store.notifs && $store.notifs.items.length === 0">
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:56px 24px; text-align:center;">
                <div style="width:56px; height:56px; background:#F3F3F3; border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:16px; border:0.5px solid #EEEEEE;">
                    <i class="fas fa-bell-slash" style="font-size:22px; color:#CCCCCC;"></i>
                </div>
                <p style="font-size:14px; font-weight:600; color:#888888; margin-bottom:6px;">No notifications yet</p>
                <p style="font-size:12px; color:#BBBBBB; line-height:1.6;">
                    Alumni registrations, bulk imports, and<br>chat messages will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.notifs">
            <template x-for="(notif, notifIdx) in $store.notifs.items" :key="notif.id">
                <div
                    x-transition:leave="transition ease-in duration-250"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-full"
                    style="overflow: hidden;">
                    <div class="notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.notifs.items[notifIdx - 1].read"
                         x-cloak>
                        <span class="notif-divider-label">Already Read</span>
                    </div>
                <div
                    class="notif-item"
                    :class="[
                        notif.read ? 'is-read' : 'is-unread',
                        !notif.read
                            ? (notif.icon === 'user-graduate' ? 'clr-alumni'
                               : notif.icon === 'file-import' ? 'clr-import'
                               : notif.icon === 'comment-dots' ? 'clr-chat'
                               : 'clr-default')
                            : '',
                        ($store.notifs.navigating && $store.notifs.loadingId === notif.id) ? 'is-loading' : '',
                        ($store.notifs.deletingId === notif.id) ? 'is-loading' : ''
                    ]"
                    @click.stop="
                        $store.notifs.markRead(notif);
                    ">

                    <template x-if="$store.notifs.navigating && $store.notifs.loadingId === notif.id">
                        <div class="notif-item-loading-overlay">
                            <i class="fas fa-spinner fa-spin notif-item-spinner"></i>
                        </div>
                    </template>

                    <template x-if="$store.notifs.deletingId === notif.id">
                        <div class="notif-item-loading-overlay">
                            <i class="fas fa-spinner fa-spin notif-item-spinner" style="color:#DC2626;"></i>
                        </div>
                    </template>

                    <div class="notif-icon-wrap"
                         :class="{
                             'notif-icon-alumni':  notif.icon === 'user-graduate',
                             'notif-icon-import':  notif.icon === 'file-import',
                             'notif-icon-chat':    notif.icon === 'comment-dots',
                             'notif-icon-default': !['user-graduate','file-import','comment-dots'].includes(notif.icon)
                         }">
                        <i class="fas" :class="'fa-' + (notif.icon || 'bell')"></i>
                    </div>

                    <div class="notif-body">

                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px;">
                            <div class="notif-title-row">
                                <span class="notif-title-text"
                                      :class="notif.read ? 'is-read' : ''"
                                      x-text="notif.title">
                                </span>

                                <span x-show="notif.icon === 'user-graduate'" x-cloak
                                      class="notif-tag notif-tag-alumni">New Alumni</span>
                                <span x-show="notif.icon === 'file-import'" x-cloak
                                      class="notif-tag notif-tag-import">Import</span>
                                <span x-show="notif.icon === 'comment-dots'" x-cloak
                                      class="notif-tag notif-tag-chat">Message</span>

                                <span x-show="Number(notif.count) > 1" x-cloak
                                      class="notif-count-badge"
                                      x-text="'×' + Number(notif.count)">
                                </span>
                            </div>

                            <span x-show="!notif.read" x-cloak class="notif-unread-dot"></span>
                        </div>

                        <p class="notif-message-text" x-text="notif.message"></p>

                        <div class="notif-time-row">
                            <span style="display:flex; align-items:center; gap:5px;">
                                <i class="fas fa-clock"></i>
                                <span x-text="notif.created_at
                                    ? new Date(notif.created_at).toLocaleString('en-PH', {
                                        month: 'short', day: 'numeric', year: 'numeric',
                                        hour: '2-digit', minute: '2-digit'
                                      })
                                    : ''">
                                </span>
                            </span>

                            <button type="button"
                                    x-show="notif.created_at && (Date.now() - new Date(notif.created_at).getTime()) >= (30 * 24 * 60 * 60 * 1000)"
                                    x-cloak
                                    class="notif-delete-btn"
                                    @click.stop="$store.notifs && $store.notifs.deleteNotif(notif)"
                                    aria-label="Delete notification">
                                <i class="fas fa-trash-can"></i>
                                <span class="notif-delete-tooltip">Delete</span>
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </template>
        </template>
    </div>

    {{-- Panel Footer --}}
    <div id="notif-footer-hint" style="background:#FFFFFF; border-top:0.5px solid #E0D8ED; padding:12px 18px; text-align:center; flex-shrink:0;">
        <p style="font-size:13px; color:#555555; font-weight:500; letter-spacing:0.01em;">
            Select a notification to view details and mark as read.
        </p>
    </div>
</div>

@livewireScripts

{{-- CLOSE ON OUTSIDE CLICK --}}
<div
    x-data
    x-show="$store.notifs && $store.notifs.open"
    x-cloak
    @click="$store.notifs && $store.notifs.close()"
    class="fixed inset-0"
    style="z-index: 9998; background: transparent;">
</div>
</body>
</html>