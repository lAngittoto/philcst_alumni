<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>{{ config('app.name', 'Philcst') }} - Alumni Director</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        /* ── Disable Livewire wire:navigate top progress bar (nprogress) ── */
        #nprogress {
            display: none !important;
        }

        [x-cloak] { display: none !important; }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
            overscroll-behavior-y: contain;
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* ── Mobile viewport height fix ── */
        .dir-app-shell {
            height: 100vh;
            height: 100dvh;
        }

        /* ── Topbar bell (all screen sizes) ── */
        .dir-topbar-bell {
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
        .dir-topbar-bell:hover,
        .dir-topbar-bell:focus,
        .dir-topbar-bell:focus-visible,
        .dir-topbar-bell:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
            border: none !important;
        }

        .bell-badge { pointer-events: none; }
        .dir-notif-item {
            cursor: pointer;
            position: relative;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        .dir-notif-close-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .dir-notif-close-tip {
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
        .dir-notif-close-tip::after {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-bottom-color: #1a1a1a;
        }
        .dir-notif-close-wrap:hover .dir-notif-close-tip { opacity: 1; }
        @media (max-width: 1023px) {
            .dir-notif-close-tip { display: none !important; }
        }

        /* ── Delete icon (only shown once notif is 30+ days old) ──
           Sits at the end of the time row, next to the timestamp.
           Red icon by default so it's visible right away once it
           appears (not just on hover), with a slightly deeper red +
           light-red bg on hover for feedback. */
        .dir-notif-delete-btn {
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
        .dir-notif-delete-btn:hover {
            background: #FDE8E8;
            color: #B91C1C;
        }
        .dir-notif-delete-btn i { font-size: .95rem; pointer-events: none; }

        .dir-notif-delete-tooltip {
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
        .dir-notif-delete-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            right: 7px;
            border: 4px solid transparent;
            border-top-color: #DC2626;
        }
        .dir-notif-delete-btn:hover .dir-notif-delete-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Read/Unread section divider ─────────────────────────── */
        .dir-notif-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px 6px;
        }
        .dir-notif-divider::before,
        .dir-notif-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #ECE2F8;
        }
        .dir-notif-divider-label {
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #B9A6C7;
            white-space: nowrap;
        }

        /* ════════════════════════════════════════════════════════
           DIRECTOR SIDEBAR — WHITE + COLLAPSIBLE (Coordinator-style)
        ════════════════════════════════════════════════════════ */

        .dir-sidebar {
            width: 18rem;
            min-width: 18rem;
            background: #FFFFFF;
            border-right: 1px solid #E8E0F0;
            transition: width 0.2s ease, min-width 0.2s ease;
        }

        @media (min-width: 1024px) {
            .dir-sidebar.dir-sidebar-modal-hidden {
                position: fixed !important;
                opacity: 0;
                transform: translateX(-16px);
                pointer-events: none;
                transition: opacity 0.22s ease, transform 0.22s ease;
            }
        }
        @media (max-width: 1023px) {
            #dir-sidebar-aside.dir-sidebar-modal-hidden {
                opacity: 0;
                transform: translateX(-100%);
                pointer-events: none;
                transition: opacity 0.22s ease, transform 0.22s ease;
            }
        }

        .dir-sidebar-header {
            background: #7A3F91;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
        }
        .dir-header-inner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            flex: 1;
            position: relative;
            z-index: 10;
        }
        .dir-badge-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.22);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            backdrop-filter: blur(2px);
        }

        .dir-nav-link {
            position: relative;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .dir-nav-link:not(.is-active):hover {
            background: #FAF6FE;
        }
        .dir-nav-link:not(.is-active):hover .dir-nav-icon {
            transform: scale(1.07);
        }
        .dir-nav-link.is-active {
            background: #F3EBFA;
            border: 1px solid #E0CFEE;
        }
        .dir-nav-icon { transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease; }

        /* ── Color-coded nav icons — each destination gets its own accent
           so the sidebar can be scanned by color, not just by label. ── */
        .dir-nav-icon.clr-dashboard    { background: #DBEAFE !important; color: #2563EB !important; }
        .dir-nav-icon.clr-coordinator  { background: #FDEBD3 !important; color: #B45309 !important; }
        .dir-nav-icon.clr-event        { background: #DCFCE7 !important; color: #16A34A !important; }
        .dir-nav-icon.clr-job          { background: #DBEAFE !important; color: #0284C7 !important; }
        .dir-nav-icon.clr-chat         { background: #FCE7F3 !important; color: #DB2777 !important; }

        .dir-nav-link.is-active .dir-nav-icon {
            background: #FFFFFF !important;
            color: #7A3F91 !important;
        }

        /* ── Bell "sonar" alert — two staggered rings ping out from the
           badge dot like a radar sweep, runs continuously while there is
           at least one unread notification. The second ring is delayed
           half a cycle behind the first so a new ring is always mid-fade
           somewhere, instead of one plain ring blinking on/off. ── */
        .dir-bell-wave {
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            pointer-events: none;
        }
        .dir-bell-wave::before,
        .dir-bell-wave::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid #DC2626;
            opacity: 0;
        }
        .dir-bell-wave.is-active::before {
            animation: dir-bell-sonar 1.8s cubic-bezier(.25,.6,.4,1) infinite;
        }
        .dir-bell-wave.is-active::after {
            animation: dir-bell-sonar 1.8s cubic-bezier(.25,.6,.4,1) infinite;
            animation-delay: 0.9s;
        }
        @keyframes dir-bell-sonar {
            0%   { transform: scale(0.6) rotate(0deg);   opacity: 0.6; border-color: #DC2626; }
            60%  { transform: scale(1.4) rotate(20deg);  opacity: 0.18; border-color: #F87171; }
            100% { transform: scale(1.9) rotate(35deg);  opacity: 0;   border-color: #F87171; }
        }

        .dir-collapsible-text {
            opacity: 1;
            max-width: 220px;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s ease, max-width 0.2s ease;
        }

        .dir-nav-section-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }
        .dir-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #9A8AA8;
        }
        .dir-collapse-icon-btn {
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
        .dir-collapse-icon-btn:hover { background: #E9D8F5; }
        .dir-collapse-icon-btn:active { transform: scale(0.88); }
        .dir-collapse-icon-btn i { pointer-events: none; }

        .dir-logout-btn {
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
        .dir-logout-btn:hover   { background: #6A3580; }
        .dir-logout-btn:active  { transform: scale(0.97); }
        .dir-logout-btn:disabled { cursor: not-allowed; background: #8E5DA3; }
        .dir-logout-spinner {
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            animation: dir-spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes dir-spin { to { transform: rotate(360deg); } }
        .dir-logout-text-swap { display: inline-flex; align-items: center; }

        @media (min-width: 1024px) {
            .dir-sidebar.is-collapsed {
                width: 5rem !important;
                min-width: 5rem !important;
            }

            .dir-sidebar.is-collapsed .dir-collapsible-text {
                opacity: 0;
                max-width: 0;
                margin-left: 0 !important;
                margin-right: 0 !important;
                pointer-events: none;
            }

            .dir-sidebar.is-collapsed .dir-sidebar-header {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            .dir-sidebar.is-collapsed .dir-header-inner {
                flex: 0 0 auto;
                justify-content: center;
                gap: 0;
            }
            .dir-sidebar.is-collapsed .dir-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            .dir-sidebar.is-collapsed .dir-nav-icon {
                margin-right: 0 !important;
            }
            .dir-sidebar.is-collapsed .dir-nav-section-row {
                justify-content: center;
                padding: 0 0.5rem;
            }
            .dir-sidebar.is-collapsed .dir-logout-btn {
                gap: 0;
                padding: 0.9rem;
            }
            .dir-sidebar.is-collapsed .dir-logout-btn i.fa-right-from-bracket,
            .dir-sidebar.is-collapsed .dir-logout-spinner {
                margin-right: 0 !important;
            }
        }

        @media (max-width: 1023px) {
            #dir-sidebar-aside {
                box-shadow: 0 0 60px rgba(0,0,0,0.18);
                width: 5.5rem;
                min-width: 5.5rem;
            }

            #dir-sidebar-aside .dir-sidebar-header {
                display: none !important;
            }

            #dir-sidebar-aside .dir-section-label,
            #dir-sidebar-aside .dir-nav-section-row,
            #dir-sidebar-aside .dir-nav-link span:not(.dir-nav-icon) {
                display: none !important;
            }
            #dir-sidebar-aside .dir-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            #dir-sidebar-aside .dir-nav-icon {
                margin-right: 0 !important;
            }

            #dir-sidebar-aside .dir-logout-btn {
                gap: 0;
                padding: 1rem;
            }
            #dir-sidebar-aside .dir-logout-text-swap span:not(.dir-logout-spinner) {
                display: none;
            }
            #dir-sidebar-aside .dir-logout-btn i.fa-right-from-bracket,
            #dir-sidebar-aside .dir-logout-spinner {
                margin-right: 0 !important;
            }
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATION PANEL — desktop dropdown, mobile FULL SCREEN
        ════════════════════════════════════════════════════════ */
        #dir-notif-panel {
            max-width: calc(100vw - 16px);
        }
        @media (max-width: 1023px) {
            #dir-notif-panel {
                position: fixed !important;
                inset: 0 !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 100% !important;
                min-height: 100% !important;
                max-height: 100% !important;
                border-radius: 0 !important;
                border: none !important;
            }
            #dir-notif-panel .dir-notif-list-scroll {
                max-height: calc(100vh - 190px) !important;
            }
        }

        /* ════════════════════════════════════════════════════════
           SESSION-EXPIRED SOFT MODAL (replaces raw "Page Expired" page)
        ════════════════════════════════════════════════════════ */
        #dir-session-expired-modal {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(1px);
        }
        #dir-session-expired-modal.is-visible { display: flex; }
        .dir-sem-card {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 360px;
            margin: 16px;
            padding: 28px 24px 24px;
            text-align: center;
            box-shadow: 0 30px 70px rgba(0,0,0,0.35);
            animation: dirSemIn 0.22s cubic-bezier(.25,.8,.25,1) both;
        }
        @keyframes dirSemIn {
            from { opacity: 0; transform: translateY(10px) scale(.97); }
            to   { opacity: 1; transform: none; }
        }
        .dir-sem-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            background: #F3EBFA;
            color: #7A3F91;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px;
        }
        .dir-sem-title { font-weight: 800; font-size: 16px; color: #1a1a1a; }
        .dir-sem-sub { font-size: 13px; color: #666; margin-top: 6px; line-height: 1.5; }
        .dir-sem-btn {
            margin-top: 18px;
            width: 100%;
            padding: 11px 16px;
            border-radius: 12px;
            border: none;
            background: #7A3F91;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: .03em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background-color .15s ease, transform .1s ease;
        }
        .dir-sem-btn:hover { background: #6A3580; }
        .dir-sem-btn:active { transform: scale(0.97); }
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  LOGOUT-IN-PROGRESS FLAG
    //  Set to true the instant the Logout button is clicked (before the
    //  POST /logout request is even sent). Every 419-handling path below
    //  checks this flag first and bails out silently if it's true — because
    //  once we're logging out, a 419 is EXPECTED (session is being killed)
    //  and should never trigger the session-expired modal or any fallback
    //  page content, soft or raw.
    // ─────────────────────────────────────────────────────────────────────────
    window.__dirLoggingOut = false;

    // ─────────────────────────────────────────────────────────────────────────
    //  BFCACHE FIX
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SESSION / CSRF EXPIRED (419) — SOFT RECOVERY
    //  Instead of letting Livewire swap in Laravel's raw "Page Expired" HTML
    //  (which looks like the whole app broke), we intercept the failed
    //  request, suppress the default full-page replace, and show a small
    //  branded modal asking the user to refresh. Clicking refresh does a
    //  normal location.reload(), which mints a fresh session + CSRF token.
    //
    //  EXCEPTION: if the user is actively logging out (__dirLoggingOut),
    //  we suppress this entirely — a 419 during logout is expected (the
    //  session was just destroyed server-side) and should not surface
    //  anything to the user, since they're already being redirected to
    //  the login page.
    // ─────────────────────────────────────────────────────────────────────────
    window.__dirShowSessionExpired = function () {
        if (window.__dirLoggingOut) return;
        var modal = document.getElementById('dir-session-expired-modal');
        if (modal) modal.classList.add('is-visible');
    };

    document.addEventListener('livewire:init', function () {
        if (!window.Livewire || typeof Livewire.hook !== 'function') return;

        Livewire.hook('request', function ({ fail }) {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    // Stop Livewire from dumping the raw expired-page HTML
                    // into the DOM — show our own modal instead (unless
                    // we're logging out, in which case show nothing).
                    preventDefault();
                    window.__dirShowSessionExpired();
                }
            });
        });
    });

    // Fallback for older Livewire versions / plain fetch-based failures
    // that don't go through the hook above (defensive double-cover).
    window.addEventListener('livewire:navigate:failed', function () {
        window.__dirShowSessionExpired();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  STOP ALL BACKGROUND POLLING ON LOGOUT
    //  Fired from the logout <form>'s @submit handler, BEFORE the POST
    //  request is sent. This kills the Alpine-store notification poll
    //  (_pollTimer) immediately so no stale-session fetch can race the
    //  logout request and trip a 419. Combined with the __dirLoggingOut
    //  flag above (which mutes any 419 handling that still slips through
    //  from the Livewire wire:poll on dir-notif-poller), this closes
    //  the race condition that caused "This page has expired" to flash
    //  right before the redirect to /login.
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('stop-dir-polling', function () {
        window.__dirLoggingOut = true;

        var s = window.__safeDirNotifsStore();
        if (s && s._pollTimer) {
            clearInterval(s._pollTimer);
            s._pollTimer = null;
        }
        if (s) {
            s.open = false;
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  ROUTE MAP
    // ─────────────────────────────────────────────────────────────────────────
    window.__dirRouteMap = {
        'director.dashboard':              '/director/dashboard',
        'director.coordinator/management': '/director/coordinator/management',
        'director.event/management':       '/director/event/management',
        'director.job/management':         '/director/job/management',
        'director.director/messenger':     '/director/messenger',
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  PER-ICON COLOR MAP (each notif type gets its own color)
    // ─────────────────────────────────────────────────────────────────────────
    window.__dirIconColors = {
        'comments':        { bg: '#FCE7F3', color: '#DB2777' }, // messages — pink
        'briefcase':       { bg: '#DBEAFE', color: '#0369A1' }, // job posting — blue
        'circle-check':    { bg: '#D1FAE5', color: '#059669' }, // activated — green
        'circle-pause':    { bg: '#FEF3C7', color: '#D97706' }, // deactivated — orange
        'rotate-left':     { bg: '#DBEAFE', color: '#0284C7' }, // restored — blue
        'calendar-check':  { bg: '#D1FAE5', color: '#047857' }, // event — green
        'calendar':        { bg: '#FEE2E2', color: '#DC2626' }, // event rejected — red
        'calendar-days':   { bg: '#F3EBFA', color: '#7A3F91' }, // new/resubmitted event for review — purple
        'users-gear':      { bg: '#FDEBD3', color: '#B45309' }, // coordinator — amber
        'user-plus':       { bg: '#FFE8D1', color: '#B45309' }, // alumni registered — amber
        'user-group':      { bg: '#FFE8D1', color: '#B45309' }, // alumni — amber
        'pen-to-square':   { bg: '#EDE9FE', color: '#6D28D9' }, // job updated — violet
        'trash':           { bg: '#FEE2E2', color: '#DC2626' }, // deleted — red
        'bell':            { bg: '#F3F4F6', color: '#6B7280' }, // default — gray
    };
    window.__dirIconBg = function (icon) {
        return (window.__dirIconColors[icon] || window.__dirIconColors['bell']).bg;
    };
    window.__dirIconColor = function (icon) {
        return (window.__dirIconColors[icon] || window.__dirIconColors['bell']).color;
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeDirNotifsStore = function () {
        return {
            open:        false,
            items:       [],
            _pollTimer:  null,
            deleteToast: { show: false, message: '' },

            async init() {
                if (window.__dirLoggingOut) return;
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (window.__dirLoggingOut) return;
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () {
                    if (window.__dirLoggingOut) {
                        clearInterval(self._pollTimer);
                        self._pollTimer = null;
                        return;
                    }
                    self._fetch();
                }, 10000);
            },

            async _fetch() {
                if (window.__dirLoggingOut) return;
                if (this._deleting) return; // don't let a poll refresh clobber an in-flight delete
                try {
                    var res = await window.fetch('/director/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.status === 419) {
                        window.__dirShowSessionExpired();
                        return;
                    }
                    if (res.ok) {
                        var raw = await res.json();
                        this.items = this._groupByDay(raw);
                    }
                } catch (e) { /* silently fail */ }
            },

            _groupByDay(rows) {
                var map = new Map();

                Array.from(rows)
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var day = n.created_at
                            ? new Date(n.created_at).toISOString().slice(0, 10)
                            : 'unknown';
                        var rawDedup = n.dedup_key || '';

                        var isJobSelfEvent      = rawDedup.indexOf('job-self::') === 0;
                        var isCoordSelfEvent    = rawDedup.indexOf('coordinator-self::') === 0;

                        var isEventSubmit       = rawDedup.startsWith('event-submitted::');
                        var isEventResubmit     = rawDedup.startsWith('event-resubmitted::');

                        var isCoordEvent  = !isCoordSelfEvent && (rawDedup.startsWith('coordinator::') || n.icon === 'users-gear');
                        var isCalEvent    = !isEventSubmit && !isEventResubmit && (
                                                rawDedup.startsWith('event-management::') ||
                                                rawDedup.startsWith('event-announced::') ||
                                                n.icon === 'calendar-check' ||
                                                n.icon === 'calendar'
                                            );
                        var isJobEvent    = !isJobSelfEvent && (rawDedup.startsWith('job-posted::') || rawDedup.startsWith('job-management::') || n.icon === 'briefcase');
                        var isMsgEvent    = rawDedup.startsWith('message-received::') || n.icon === 'comments';
                        var isAlumniEvent = rawDedup.startsWith('alumni-registered::') || rawDedup.startsWith('profile-updated::') || n.icon === 'user-group' || n.icon === 'user-plus';

                        var groupKey;
                        if      (isJobSelfEvent)   { groupKey = rawDedup; }
                        else if (isCoordSelfEvent) { groupKey = rawDedup; }
                        else if (isEventSubmit)    { groupKey = rawDedup; }
                        else if (isEventResubmit)  { groupKey = rawDedup; }
                        else if (isCoordEvent)     { groupKey = 'coordinator_day::' + day; }
                        else if (isCalEvent)       { groupKey = 'calendar_day::' + day; }
                        else if (isJobEvent)       { groupKey = 'job_day::' + day; }
                        else if (isMsgEvent)       { groupKey = 'message_day::' + day; }
                        else if (isAlumniEvent)    { groupKey = 'alumni_day::' + day; }
                        else { groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id); }

                        var rowCount = Number(n.count) || 1;

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);

                            g.count = (g.count || 1) + rowCount;

                            if (!n.read) g.read = false;

                            if (Array.isArray(n._ids)) {
                                g._ids = g._ids.concat(n._ids);
                            } else {
                                g._ids.push(n.id);
                            }

                            if (isJobSelfEvent || isCoordSelfEvent || isEventSubmit || isEventResubmit) {
                                g.title   = n.title   || g.title;
                                g.message = n.message || g.message;
                            } else if (isCoordEvent) {
                                g.title   = 'Coordinator Updates';
                                g.message = g.count + ' coordinator update(s) today.';
                            } else if (isCalEvent) {
                                g.title   = 'Event Management Update';
                                g.message = g.count + ' event update(s) today.';
                            } else if (isJobEvent) {
                                g.title   = 'Job Posting Update';
                                g.message = g.count + ' job posting update(s) today.';
                            } else if (isMsgEvent) {
                                g.title   = g.count + ' New Messages';
                                g.message = n.message || g.message;
                            } else if (isAlumniEvent) {
                                g.title   = 'Alumni Updates';
                                g.message = g.count + ' alumni update(s) today.';
                            }

                            g.link_route = n.link_route || g.link_route;
                            g.job_id     = n.job_id     || g.job_id;
                            g.event_id   = n.event_id   || g.event_id;
                            g.created_at = g.created_at || n.created_at;

                        } else {
                            var entry = Object.assign({}, n, {
                                count: rowCount,
                                _ids:  Array.isArray(n._ids) ? n._ids.slice() : [n.id],
                                title: (isJobSelfEvent || isCoordSelfEvent || isEventSubmit || isEventResubmit)
                                    ? (n.title || (isEventSubmit
                                        ? 'New Event for Review'
                                        : isEventResubmit
                                        ? 'Event Resubmitted for Review'
                                        : isCoordSelfEvent ? 'Coordinator Update' : 'Job Update'))
                                    : isMsgEvent
                                    ? (rowCount > 1 ? rowCount + ' New Messages' : (n.title || 'New Message'))
                                    : (isCoordEvent  ? (n.title || 'Coordinator Update')
                                     : isCalEvent    ? (n.title || 'Event Management Update')
                                     : isJobEvent    ? (n.title || 'Job Posting Update')
                                     : isAlumniEvent ? (n.title || 'Alumni Update')
                                     : n.title),
                                icon: (isCoordEvent || isCoordSelfEvent) ? 'users-gear'
                                    : (isEventSubmit || isEventResubmit) ? 'calendar-days'
                                    : isCalEvent    ? 'calendar-check'
                                    : (isJobEvent || isJobSelfEvent) ? 'briefcase'
                                    : isMsgEvent    ? 'comments'
                                    : isAlumniEvent ? 'user-group'
                                    : (n.icon || 'bell'),
                            });
                            map.set(groupKey, entry);
                        }
                    });

                var result = Array.from(map.values());

                // Unread items float to the top (newest first), read items
                // sit below (also newest first) — mirrors coordinator's
                // sort so the panel behaves the same way across roles.
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
            close()  { this.open = false; },

            async markRead(item) {
                if (window.__dirLoggingOut) return;
                if (item.read) return;
                item.read = true;
                var ids  = Array.isArray(item._ids) ? item._ids : [item.id];
                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                for (var i = 0; i < ids.length; i++) {
                    try {
                        var r = await window.fetch('/director/notifications/' + ids[i] + '/read', {
                            method: 'PATCH',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (r.status === 419) { window.__dirShowSessionExpired(); return; }
                    } catch (e) { /* ignore */ }
                }
            },

            async markAllRead() {
                if (window.__dirLoggingOut) return;
                this.items.forEach(function (n) { n.read = true; });
                try {
                    var r = await window.fetch('/director/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                    if (r.status === 419) { window.__dirShowSessionExpired(); }
                } catch (e) { /* ignore */ }
            },

            async markReadByRoute(routeName) {
                if (window.__dirLoggingOut) return;
                var matched = this.items.filter(function (n) {
                    return n.link_route === routeName && !n.read;
                });
                if (matched.length === 0) return;
                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                for (var i = 0; i < matched.length; i++) {
                    matched[i].read = true;
                    var ids = matched[i]._ids || [matched[i].id];
                    for (var j = 0; j < ids.length; j++) {
                        try {
                            var r = await window.fetch('/director/notifications/' + ids[j] + '/read', {
                                method: 'PATCH',
                                headers: {
                                    'X-CSRF-TOKEN':     csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                }
                            });
                            if (r.status === 419) { window.__dirShowSessionExpired(); return; }
                        } catch (e) { /* ignore */ }
                    }
                }
            },

            // Deletes a notification MESSAGE only — never the underlying
            // job, event, or chat data that generated it. This just clears
            // the row(s) from the `notifications` table so the panel/list
            // gets shorter; the actual data this notif was about is
            // untouched.
            //
            // Available on every notif now (no age restriction) — purely
            // a "clean up noise" action, not a moderation action on real
            // data.
            async deleteNotif(item) {
                if (window.__dirLoggingOut) return;
                var ids = item._ids || [item.id];
                var self = this;
                this._deleting = true;
                this._showDeleteToast('Notification deleted');

                // Give the slide-out leave transition time to play before
                // actually removing the item from the array — removing it
                // immediately would skip straight past x-transition:leave.
                await new Promise(function (resolve) { setTimeout(resolve, 250); });
                this.items = this.items.filter(function (n) { return n !== item; });

                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                var failedIds = [];

                for (var i = 0; i < ids.length; i++) {
                    try {
                        var res = await window.fetch('/director/notifications/' + ids[i], {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (res.status === 419) { window.__dirShowSessionExpired(); }
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
                }, 2200);
            },
        };
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SAFE ACCESSOR
    // ─────────────────────────────────────────────────────────────────────────
    window.__safeDirNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('dirNotifs');
                if (s) return s;
            }
        } catch (e) {}
        return null;
    };

    window.__bootDirNotifsStore = function () {
        if (window.__dirLoggingOut) return;
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('dirNotifs')) {
            Alpine.store('dirNotifs', window.__makeDirNotifsStore());
        }
        var s = Alpine.store('dirNotifs');
        if (s && !s._pollTimer) s.init();
    };

    document.addEventListener('alpine:init', function () {
        Alpine.store('dirNotifs', window.__makeDirNotifsStore());
    });

    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            if (window.__dirLoggingOut) return;
            var s = window.__safeDirNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    window.addEventListener('load', function () {
        if (window.__dirLoggingOut) return;
        var s = window.__safeDirNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootDirNotifsStore(); }
    });

    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (window.__dirLoggingOut) return;
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('dirNotifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open = false;
                s.init();
            } else {
                Alpine.store('dirNotifs', window.__makeDirNotifsStore());
                var ns = Alpine.store('dirNotifs');
                if (ns) ns.init();
            }
        }, 150);
    });

    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('dirNotifs');
        if (!s) {
            Alpine.store('dirNotifs', window.__makeDirNotifsStore());
            s = Alpine.store('dirNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    document.addEventListener('visibilitychange', function () {
        if (window.__dirLoggingOut) return;
        if (document.visibilityState === 'visible') {
            var s = window.__safeDirNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — desktop only (mobile is handled entirely by CSS, full screen)
    // ─────────────────────────────────────────────────────────────────────────
    function positionDirPanel() {
        if (window.innerWidth < 1024) return;
        var btn   = document.getElementById('dir-bell-btn');
        var panel = document.getElementById('dir-notif-panel');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        panel.style.left  = (btnRect.right - 400) + 'px';
        panel.style.top   = (btnRect.bottom + 8) + 'px';
        panel.style.width = '400px';
    }
    window.positionDirPanel = positionDirPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeDirNotifsStore();
        if (s && s.open) positionDirPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SIDEBAR SMART MARK-READ
    // ─────────────────────────────────────────────────────────────────────────
    window.__dirSidebarNotifsMarkRead = function (routeName) {
        if (window.__dirLoggingOut) return;
        var s = window.__safeDirNotifsStore();
        if (!s) return;
        s.markReadByRoute(routeName);
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIFICATION EVENT LISTENERS
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstDirNotifListeners) {
        window.__philcstDirNotifListeners = true;

        function _dirDetail(e) {
            var d = e.detail;
            if (!d) return {};
            if (!Array.isArray(d)) return d;
            return d[0] || {};
        }

        async function _saveDirNotif(payload) {
            if (window.__dirLoggingOut) return;
            try {
                var res = await window.fetch('/director/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.status === 419) { window.__dirShowSessionExpired(); return; }
                await new Promise(function (r) { setTimeout(r, 300); });
                if (window.__dirLoggingOut) return;
                var s = window.__safeDirNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    if (window.__dirLoggingOut) return;
                    var s2 = window.__safeDirNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        window.addEventListener('dir-coordinator-updated', function (e) {
            if (window.__dirLoggingOut) return;
            var d      = _dirDetail(e);
            var name   = d.name   || 'A coordinator';
            var action = d.action || 'updated';
            var id     = d.id     || Math.floor(Date.now() / 60000);

            var titleMap = {
                created:       'New Coordinator Registered',
                activated:     'Coordinator Activated',
                deactivated:   'Coordinator Deactivated',
                email_updated: 'Coordinator Email Updated',
            };
            var msgMap = {
                created:       name + ' has been registered as a new coordinator.',
                activated:     name + ' has been activated.',
                deactivated:   name + ' has been deactivated.',
                email_updated: name + "'s email address has been updated.",
            };

            _saveDirNotif({
                icon:       'users-gear',
                title:      titleMap[action] || ('Coordinator ' + action),
                message:    msgMap[action]   || (name + ' account has been updated.'),
                link_route: 'director.coordinator/management',
                link_label: 'View Coordinators',
                dedup_key:  'coordinator-self::' + id + '::' + action + '::' + Math.floor(Date.now() / 60000),
            });
        });

        window.addEventListener('dir-event-updated', function (e) {
            if (window.__dirLoggingOut) return;
            var d = _dirDetail(e);
            _saveDirNotif({
                icon:       'calendar-check',
                title:      'Event Management Update',
                message:    (d.title || 'An event') + ' has been updated.',
                link_route: 'director.event/management',
                link_label: 'View Events',
                event_id:   d.id || null,
                dedup_key:  'event-management::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        window.addEventListener('dir-job-updated', function (e) {
            if (window.__dirLoggingOut) return;
            var d = _dirDetail(e);
            _saveDirNotif({
                icon:       'briefcase',
                title:      'Job Posting Update',
                message:    (d.title || 'A job posting') + ' has been updated.',
                link_route: 'director.job/management',
                link_label: 'View Jobs',
                job_id:     d.id || null,
                dedup_key:  'job-management::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        window.addEventListener('dir-message-received', function (e) {
            if (window.__dirLoggingOut) return;
            var d = _dirDetail(e);
            var sender = d.sender || 'Someone';
            var room   = d.room   || 'Chat Room';
            var body   = d.body   || '';
            var count  = Number(d.count) || 1;
            var msgText = count > 1
                ? sender + ' and others sent ' + count + ' new messages in ' + room + '.'
                : sender + ' sent a message in ' + room +
                  (body ? ': "' + body.substring(0, 50) + (body.length > 50 ? '…' : '') + '"' : '.');
            _saveDirNotif({
                icon:       'comments',
                title:      count > 1 ? count + ' New Messages' : 'New Message',
                message:    msgText,
                link_route: 'director.director/messenger',
                link_label: 'Open Chat',
                dedup_key:  'message-received::' + sender + '::' + room + '::' + Math.floor(Date.now() / 60000),
            });
        });
    }
    </script>
</head>

<body
    class="antialiased"
    x-data="{
        open: false,
        sidebarCollapsed: false,
        sidebarHiddenByModal: false,
        loggingOut: false,
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
        }
    }"
    @click="$store.dirNotifs && $store.dirNotifs.open && $store.dirNotifs.close()"
    @close-sidebar.window="sidebarHiddenByModal = true; open = false;"
    @open-sidebar.window="sidebarHiddenByModal = false;">

<div class="dir-app-shell flex bg-[#F5F5F5] font-sans overflow-hidden">

    <div
        x-show="open"
        x-transition:enter="transition opacity-ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition opacity-ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-50 bg-black/50 lg:hidden">
    </div>

    <aside
        id="dir-sidebar-aside"
        :class="{
            'translate-x-0': open,
            '-translate-x-full': !open,
            'is-collapsed': sidebarCollapsed,
            'dir-sidebar-modal-hidden': sidebarHiddenByModal
        }"
        class="dir-sidebar fixed inset-y-0 left-0 z-[60] transform
               lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0">

        {{-- Sidebar header --}}
        <div class="dir-sidebar-header h-24 px-5 shrink-0">
            <div class="dir-header-inner">
                <div class="dir-badge-icon">
                    <i class="fa-solid fa-user-tie text-white" style="font-size:17px;"></i>
                </div>
                <div class="min-w-0 dir-collapsible-text">
                    <h1 class="text-[19px] font-bold tracking-tight text-white leading-tight truncate">
                        Director<span class="font-semibold text-white/70">Portal</span>
                    </h1>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-white/60 font-semibold">
                        Alumni Management
                    </p>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">

            <div class="dir-nav-section-row">
                <p class="dir-section-label dir-collapsible-text">MENU</p>

                <button type="button"
                        @click.stop="toggleSidebar()"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        class="dir-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="{ 'fa-angles-right': sidebarCollapsed, 'fa-angles-left': !sidebarCollapsed }"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                // NOTE: 'pattern' matches the ACTUAL URL path (see routes/web.php),
                // not the route name — request()->is() matches against the URL path.
                $sidebarLinks = [
                    [
                        'route'   => 'director.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'director/dashboard*',
                        'color'   => 'clr-dashboard',
                    ],
                    [
                        'route'   => 'director.coordinator/management',
                        'icon'    => 'users-gear',
                        'label'   => 'Coordinator Management',
                        'pattern' => 'director/coordinator/management*',
                        'color'   => 'clr-coordinator',
                    ],
                    [
                        'route'   => 'director.event/management',
                        'icon'    => 'calendar-check',
                        'label'   => 'Events Overview',
                        'pattern' => 'director/event/management*',
                        'color'   => 'clr-event',
                    ],
                    [
                        'route'   => 'director.job/management',
                        'icon'    => 'briefcase',
                        'label'   => 'Jobs Overview',
                        'pattern' => 'director/job/management*',
                        'color'   => 'clr-job',
                    ],
                    [
                        'route'   => 'director.director/messenger',
                        'icon'    => 'comments',
                        'label'   => 'Chat Room',
                        'pattern' => 'director/messenger*',
                        'color'   => 'clr-chat',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php $isActive = request()->is($link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="window.__dirSidebarNotifsMarkRead('{{ $link['route'] }}'); open = false;"
                   class="dir-nav-link {{ $isActive ? 'is-active' : '' }}
                          flex items-center px-4 py-3 rounded-xl group">

                    <div class="dir-nav-icon {{ $link['color'] }} w-10 h-10 flex items-center justify-center rounded-lg shrink-0 mr-3.5"
                         style="box-shadow:{{ $isActive ? '0 2px 6px rgba(122,63,145,0.18)' : 'none' }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="dir-nav-label dir-collapsible-text font-medium tracking-wide flex-1 text-[14px]
                                 {{ $isActive ? 'text-[#5A2D70] font-bold' : 'text-[#3A3A3A]' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="dir-active-dot dir-collapsible-text ml-auto w-1.5 h-6 rounded-full shrink-0"
                              style="background:#7A3F91;"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- ══ BACKGROUND NOTIF POLLER ══ --}}
        {{--
            IMPORTANT: this Livewire component is what caused the "This page
            has expired" flash on logout. Its wire:poll request runs through
            Livewire's own request pipeline, and can be in-flight (or fire)
            the instant the session/CSRF token is destroyed by POST /logout,
            right before the redirect navigates away.

            We stop it from ever making a poll request AGAIN after logout
            starts by wiring wire:poll to a condition that goes false the
            moment __dirLoggingOut flips true (see @submit on the logout
            form below, and the 'stop-dir-polling' listener above).
        --}}
        <div wire:ignore.self x-data="{ pollingActive: true }" x-on:stop-dir-polling.window="pollingActive = false">
            <template x-if="pollingActive">
                @livewire('director.director-notif-poller')
            </template>
        </div>

        {{-- Logout --}}
        <div class="p-2 lg:p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit="loggingOut = true; window.dispatchEvent(new CustomEvent('stop-dir-polling'));">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        title="Logout"
                        class="dir-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="dir-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>
                            <span class="dir-logout-label-text dir-collapsible-text">Logout</span>
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="dir-logout-text-swap">
                            <span class="dir-logout-spinner mr-2"></span>
                            <span class="dir-logout-label-text dir-collapsible-text">Logging out…</span>
                        </span>
                    </template>
                </button>
            </form>
        </div>
    </aside>

    {{-- ══ MAIN CONTENT ══ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0 min-h-0">

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

            <button
                id="dir-bell-btn"
                type="button"
                @click.stop="$store.dirNotifs && $store.dirNotifs.toggle(); positionDirPanel();"
                title="Notifications"
                aria-label="Open notifications"
                class="dir-topbar-bell">
                <span class="dir-bell-wave"
                      :class="$store.dirNotifs && $store.dirNotifs.unread > 0 ? 'is-active' : ''"></span>
                <i class="bell-icon fas fa-bell"
                   :class="$store.dirNotifs && $store.dirNotifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>
                <span
                    x-show="$store.dirNotifs && $store.dirNotifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.dirNotifs && $store.dirNotifs.unread > 99
                                ? '99+'
                                : ($store.dirNotifs ? $store.dirNotifs.unread : 0)">
                </span>
            </button>
        </header>

        {{-- Page content ── FIX: default is now page-level SCROLL, not clip.
             Root cause of "hindi ma-scroll sa dashboard" on mobile/tablet:
             this wrapper used to be overflow-hidden unconditionally, so ANY
             page whose content is taller than the viewport (e.g. the
             director dashboard: account card + stat cards + breakdown
             panels) had nowhere for the overflow to go — it was just
             clipped, full stop, with no way to reach what's below the fold.

             Pages like Manage Coordinator that WANT a fixed-height shell
             with only their own inner table scrolling (header/filters/
             footer pinned) still get that — they just now opt IN to it
             from inside their own component's root element (e.g.
             `h-full overflow-hidden flex flex-col` on that page's outermost
             div) instead of every page being forced into it from here.
             Normal pages like the dashboard don't set that, so they now
             fall through to this wrapper's default page-level scroll. --}}
        <div class="flex-1 overflow-y-auto no-scrollbar bg-[#F5F5F5] p-4 lg:p-8 flex flex-col"
             style="min-height: 0; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain;">
            <div class="container mx-auto flex-1 min-h-0 flex flex-col">
                @yield('content')
            </div>
        </div>
    </main>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     DIRECTOR NOTIFICATION PANEL — dropdown on desktop, full screen on mobile
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="dir-notif-panel"
    x-show="$store.dirNotifs && $store.dirNotifs.open"
    x-cloak
    x-effect="if ($store.dirNotifs && $store.dirNotifs.open) $nextTick(() => positionDirPanel())"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
    @click.stop
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
            <span x-show="$store.dirNotifs && $store.dirNotifs.unread > 0"
                  x-cloak
                  class="bg-red-500 text-white font-black px-2 py-0.5 rounded-full leading-none"
                  style="font-size:11px;"
                  x-text="$store.dirNotifs ? $store.dirNotifs.unread + ' new' : ''">
            </span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button"
                    x-show="$store.dirNotifs && $store.dirNotifs.unread > 0"
                    x-cloak
                    @click.stop="$store.dirNotifs && $store.dirNotifs.markAllRead()"
                    class="text-white/70 hover:text-white font-semibold hover:bg-white/10
                           rounded-lg px-2.5 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>

            <div class="dir-notif-close-wrap ml-1">
                <span class="dir-notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.dirNotifs && $store.dirNotifs.close()"
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
              x-text="($store.dirNotifs ? $store.dirNotifs.items.length : 0) + ' notification(s)'">
        </span>
    </div>

    {{-- Delete toast — slides in ABOVE the list, inside the panel --}}
    <div
        x-show="$store.dirNotifs && $store.dirNotifs.deleteToast.show"
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
              x-text="$store.dirNotifs ? $store.dirNotifs.deleteToast.message : ''"></span>
    </div>

    {{-- Scrollable notification list --}}
    <div class="dir-notif-list-scroll overflow-y-auto no-scrollbar flex-1" style="max-height: 420px;">

        <template x-if="$store.dirNotifs && $store.dirNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F9F7FC; border:1px solid #ECE2F8;">
                    <i class="fas fa-bell-slash" style="font-size:26px;color:#D7C8E6;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    Coordinator activity, events, jobs,<br>and messages will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.dirNotifs">
            <template x-for="(notif, notifIdx) in $store.dirNotifs.items" :key="notif.id">
                <div
                    x-transition:leave="transition ease-in duration-250"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-full"
                    style="overflow: hidden;">
                    <div class="dir-notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.dirNotifs.items[notifIdx - 1].read"
                         x-cloak>
                        <span class="dir-notif-divider-label">Already Read</span>
                    </div>
                <div
                    class="dir-notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#FAF6FE] hover:bg-[#F3EBFA]'"
                    oncontextmenu="return false;"
                    ondragstart="return false;"
                    @click.stop="
                        $store.dirNotifs.markRead(notif);
                        $store.dirNotifs.close();
                        if (notif.link_route) {
                            let url = window.__dirRouteMap[notif.link_route] || '/director/dashboard';
                            if (notif.link_route === 'director.event/management' && notif.event_id) {
                                url += (url.indexOf('?') === -1 ? '?' : '&') + 'event=' + encodeURIComponent(notif.event_id);
                            } else if (notif.link_route === 'director.job/management' && notif.job_id) {
                                url += (url.indexOf('?') === -1 ? '?' : '&') + 'job=' + encodeURIComponent(notif.job_id);
                            }
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

                    {{-- Icon — colored per notif type --}}
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                         :style="'background:' + window.__dirIconBg(notif.icon) + ';'">
                        <i class="fas"
                           :class="'fa-' + (notif.icon || 'bell')"
                           :style="'font-size:15px;color:' + window.__dirIconColor(notif.icon) + ';'"></i>
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <p :class="notif.read ? 'font-semibold text-[#555555]' : 'font-bold text-[#1a1a1a]'"
                                   style="font-size:13px;line-height:1.4;"
                                   x-text="notif.title"></p>

                                {{-- Count badge --}}
                                <span
                                    x-show="Number(notif.count) > 1"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#7A3F91;"
                                    x-text="'×' + Number(notif.count)">
                                </span>

                                {{-- New/Resubmitted Event badge --}}
                                <span
                                    x-show="(notif.icon === 'calendar-days') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#7A3F91;">
                                    NEW EVENT
                                </span>

                                {{-- Coordinator badge --}}
                                <span
                                    x-show="notif.icon === 'users-gear' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#B45309;">
                                    COORDINATOR
                                </span>

                                {{-- Event badge --}}
                                <span
                                    x-show="(notif.icon === 'calendar-check' || notif.icon === 'calendar') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#059669;">
                                    EVENT
                                </span>

                                {{-- Job badge --}}
                                <span
                                    x-show="notif.icon === 'briefcase' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#0369A1;">
                                    JOB
                                </span>

                                {{-- Message badge --}}
                                <span
                                    x-show="notif.icon === 'comments' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#DB2777;">
                                    MESSAGE
                                </span>

                                {{-- Alumni badge --}}
                                <span
                                    x-show="(notif.icon === 'user-group' || notif.icon === 'user-plus') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#B45309;">
                                    ALUMNI
                                </span>
                            </div>

                            <span x-show="!notif.read" x-cloak
                                  class="w-2 h-2 rounded-full bg-red-500 shrink-0 shadow-sm mt-1 flex-shrink-0"></span>
                        </div>

                        <p class="text-[#333333] mt-1 leading-relaxed"
                           style="font-size:12px;
                                  display:-webkit-box;
                                  -webkit-line-clamp:2;
                                  -webkit-box-orient:vertical;
                                  overflow:hidden;"
                           x-text="notif.message">
                        </p>

                        <div class="flex items-center justify-between gap-1 mt-2">
                            <span class="flex items-center gap-1">
                                <i class="fas fa-clock" style="font-size:10px;color:#333333;"></i>
                                <span style="font-size:11px;color:#333333;font-weight:500;"
                                      x-text="notif.created_at
                                          ? new Date(notif.created_at).toLocaleString('en-PH',{
                                              month:'short',day:'numeric',year:'numeric',
                                              hour:'2-digit',minute:'2-digit',second:'2-digit'
                                            })
                                          : ''">
                                </span>
                            </span>

                            <button type="button"
                                    x-show="notif.created_at && ((Date.now() - new Date(notif.created_at).getTime()) / 86400000) >= 30"
                                    x-cloak
                                    class="dir-notif-delete-btn"
                                    @click.stop="$store.dirNotifs && $store.dirNotifs.deleteNotif(notif)"
                                    aria-label="Delete notification">
                                <i class="fas fa-trash-can"></i>
                                <span class="dir-notif-delete-tooltip">Delete</span>
                            </button>
                        </div>
                    </div>

                </div>
                </div>
            </template>
        </template>
    </div>

    {{-- Panel Footer --}}
    <div class="px-5 py-3 border-t border-[#F0ECF8] text-center shrink-0" style="background:#FFFFFF;">
        <p style="font-size:13px;color:#555555;font-weight:500;letter-spacing:0.01em;">
            Click a notification to view and mark as read
        </p>
    </div>
</div>

{{-- ══ SESSION-EXPIRED SOFT MODAL — replaces raw "Page Expired" screen ══ --}}
<div id="dir-session-expired-modal">
    <div class="dir-sem-card">
        <div class="dir-sem-icon"><i class="fas fa-clock-rotate-left"></i></div>
        <p class="dir-sem-title">Your session has expired</p>
        <p class="dir-sem-sub">
            This tab was open for a while and your session timed out.
            Refresh the page to continue where you left off.
        </p>
        <button type="button" class="dir-sem-btn" onclick="window.location.reload()">
            <i class="fas fa-rotate-right mr-1.5"></i> Refresh Page
        </button>
    </div>
</div>

@livewireScripts

{{-- ✅ CLOSE ON OUTSIDE CLICK (no-op on mobile since panel is full screen) --}}
<div
    x-data
    x-show="$store.dirNotifs && $store.dirNotifs.open"
    x-cloak
    @click="$store.dirNotifs && $store.dirNotifs.close()"
    class="fixed inset-0 lg:block hidden"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>