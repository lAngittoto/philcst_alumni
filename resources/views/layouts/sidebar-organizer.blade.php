<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>{{ config('app.name', 'Philcst') }} - Coordinator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
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
        .coord-app-shell {
            height: 100vh;
            height: 100dvh;
        }

        /* ── Topbar bell (all screen sizes) ── */
        .coord-topbar-bell {
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
        .coord-topbar-bell:hover,
        .coord-topbar-bell:focus,
        .coord-topbar-bell:focus-visible,
        .coord-topbar-bell:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
            border: none !important;
        }

        .bell-badge { pointer-events: none; }
        .notif-item { cursor: pointer; position: relative; }

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

        /* ── Read/Unread section divider ─────────────────────────── */
        .coord-notif-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px 6px;
        }
        .coord-notif-divider::before,
        .coord-notif-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #ECE2F8;
        }
        .coord-notif-divider-label {
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #B9A6C7;
            white-space: nowrap;
        }

        /* ════════════════════════════════════════════════════════
           COORDINATOR SIDEBAR — WHITE + COLLAPSIBLE (Alumni-style)
        ════════════════════════════════════════════════════════ */

        .coord-sidebar {
            width: 18rem;
            min-width: 18rem;
            background: #FFFFFF;
            border-right: 1px solid #E8E0F0;
            transition: width 0.2s ease, min-width 0.2s ease;
        }

        @media (min-width: 1024px) {
            .coord-sidebar.coord-sidebar-modal-hidden {
                position: fixed !important;
                opacity: 0;
                transform: translateX(-16px);
                pointer-events: none;
                transition: opacity 0.22s ease, transform 0.22s ease;
            }
        }
        @media (max-width: 1023px) {
            #coord-sidebar-aside.coord-sidebar-modal-hidden {
                opacity: 0;
                transform: translateX(-100%);
                pointer-events: none;
                transition: opacity 0.22s ease, transform 0.22s ease;
            }
        }

        .coord-sidebar-header {
            background: #7A3F91;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
        }
        .coord-header-inner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            flex: 1;
            position: relative;
            z-index: 10;
        }
        .coord-badge-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.22);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            backdrop-filter: blur(2px);
        }

        .coord-nav-link {
            position: relative;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .coord-nav-link:not(.is-active):hover {
            background: #FAF6FE;
        }
        .coord-nav-link:not(.is-active):hover .coord-nav-icon {
            transform: scale(1.07);
        }
        .coord-nav-link.is-active {
            background: #F3EBFA;
            border: 1px solid #E0CFEE;
        }
        .coord-nav-icon { transition: transform 0.2s ease; }

        .coord-collapsible-text {
            opacity: 1;
            max-width: 220px;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s ease, max-width 0.2s ease;
        }

        .coord-nav-section-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }
        .coord-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #9A8AA8;
        }
        .coord-collapse-icon-btn {
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
        .coord-collapse-icon-btn:hover { background: #E9D8F5; }
        .coord-collapse-icon-btn:active { transform: scale(0.88); }
        .coord-collapse-icon-btn i { pointer-events: none; }

        .coord-logout-btn {
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
        .coord-logout-btn:hover   { background: #6A3580; }
        .coord-logout-btn:active  { transform: scale(0.97); }
        .coord-logout-btn:disabled { cursor: not-allowed; background: #8E5DA3; }
        .coord-logout-spinner {
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            animation: coord-spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes coord-spin { to { transform: rotate(360deg); } }
        .coord-logout-text-swap { display: inline-flex; align-items: center; }

        @media (min-width: 1024px) {
            .coord-sidebar.is-collapsed {
                width: 5rem !important;
                min-width: 5rem !important;
            }

            .coord-sidebar.is-collapsed .coord-collapsible-text {
                opacity: 0;
                max-width: 0;
                margin-left: 0 !important;
                margin-right: 0 !important;
                pointer-events: none;
            }

            .coord-sidebar.is-collapsed .coord-sidebar-header {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            .coord-sidebar.is-collapsed .coord-header-inner {
                flex: 0 0 auto;
                justify-content: center;
                gap: 0;
            }
            .coord-sidebar.is-collapsed .coord-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            .coord-sidebar.is-collapsed .coord-nav-icon {
                margin-right: 0 !important;
            }
            .coord-sidebar.is-collapsed .coord-nav-section-row {
                justify-content: center;
                padding: 0 0.5rem;
            }
            .coord-sidebar.is-collapsed .coord-logout-btn {
                gap: 0;
                padding: 0.9rem;
            }
            .coord-sidebar.is-collapsed .coord-logout-btn i.fa-right-from-bracket,
            .coord-sidebar.is-collapsed .coord-logout-spinner {
                margin-right: 0 !important;
            }
        }

        @media (max-width: 1023px) {
            #coord-sidebar-aside {
                box-shadow: 0 0 60px rgba(0,0,0,0.18);
                width: 5.5rem;
                min-width: 5.5rem;
            }

            #coord-sidebar-aside .coord-sidebar-header {
                display: none !important;
            }

            #coord-sidebar-aside .coord-section-label,
            #coord-sidebar-aside .coord-nav-section-row,
            #coord-sidebar-aside .coord-nav-link span:not(.coord-nav-icon) {
                display: none !important;
            }
            #coord-sidebar-aside .coord-nav-link {
                justify-content: center;
                padding: 0.85rem;
            }
            #coord-sidebar-aside .coord-nav-icon {
                margin-right: 0 !important;
            }

            #coord-sidebar-aside .coord-logout-btn {
                gap: 0;
                padding: 1rem;
            }
            #coord-sidebar-aside .coord-logout-text-swap span:not(.coord-logout-spinner) {
                display: none;
            }
            #coord-sidebar-aside .coord-logout-btn i.fa-right-from-bracket,
            #coord-sidebar-aside .coord-logout-spinner {
                margin-right: 0 !important;
            }
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATION PANEL — desktop dropdown, mobile FULL SCREEN
        ════════════════════════════════════════════════════════ */
        #coord-notif-panel {
            max-width: calc(100vw - 16px);
        }
        @media (max-width: 1023px) {
            #coord-notif-panel {
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
            #coord-notif-panel .notif-list-scroll {
                max-height: calc(100vh - 190px) !important;
            }
        }

        /* ════════════════════════════════════════════════════════
           SESSION-EXPIRED SOFT MODAL (replaces raw "Page Expired" page)
        ════════════════════════════════════════════════════════ */
        #coord-session-expired-modal {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(1px);
        }
        #coord-session-expired-modal.is-visible { display: flex; }
        .coord-sem-card {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 360px;
            margin: 16px;
            padding: 28px 24px 24px;
            text-align: center;
            box-shadow: 0 30px 70px rgba(0,0,0,0.35);
            animation: coordSemIn 0.22s cubic-bezier(.25,.8,.25,1) both;
        }
        @keyframes coordSemIn {
            from { opacity: 0; transform: translateY(10px) scale(.97); }
            to   { opacity: 1; transform: none; }
        }
        .coord-sem-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            background: #F3EBFA;
            color: #7A3F91;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            margin: 0 auto 14px;
        }
        .coord-sem-title { font-weight: 800; font-size: 16px; color: #1a1a1a; }
        .coord-sem-sub { font-size: 13px; color: #666; margin-top: 6px; line-height: 1.5; }
        .coord-sem-btn {
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
        .coord-sem-btn:hover { background: #6A3580; }
        .coord-sem-btn:active { transform: scale(0.97); }
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
    window.__coordLoggingOut = false;

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
    //  EXCEPTION: if the user is actively logging out (__coordLoggingOut),
    //  we suppress this entirely — a 419 during logout is expected (the
    //  session was just destroyed server-side) and should not surface
    //  anything to the user, since they're already being redirected to
    //  the login page.
    // ─────────────────────────────────────────────────────────────────────────
    window.__coordShowSessionExpired = function () {
        if (window.__coordLoggingOut) return;
        var modal = document.getElementById('coord-session-expired-modal');
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
                    window.__coordShowSessionExpired();
                }
            });
        });
    });

    // Fallback for older Livewire versions / plain fetch-based failures
    // that don't go through the hook above (defensive double-cover).
    window.addEventListener('livewire:navigate:failed', function () {
        window.__coordShowSessionExpired();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  STOP ALL BACKGROUND POLLING ON LOGOUT
    //  Fired from the logout <form>'s @submit handler, BEFORE the POST
    //  request is sent. This kills the Alpine-store notification poll
    //  (_pollTimer) immediately so no stale-session fetch can race the
    //  logout request and trip a 419. Combined with the __coordLoggingOut
    //  flag above (which mutes any 419 handling that still slips through
    //  from the Livewire wire:poll on coord-notif-poller), this closes
    //  the race condition that caused "This page has expired" to flash
    //  right before the redirect to /login.
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('stop-coord-polling', function () {
        window.__coordLoggingOut = true;

        var s = window.__safeCoordNotifsStore();
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
    window.__coordRouteMap = {
        'organizer.dashboard':         '/organizer/dashboard',
        'organizer.event/organizer':   '/organizer/event/organizer',
        'organizer.job/management':    '/organizer/job/management',
        'organizer.alumni/employment': '/organizer/alumni/employment',
        'organizer.chat/alumni':       '/organizer/chat/alumni',
        'organizer.yearbook':          '/organizer/yearbook',
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  PER-ICON COLOR MAP (each notif type gets its own color)
    // ─────────────────────────────────────────────────────────────────────────
    window.__coordIconColors = {
        'comments':        { bg: '#F3EBFA', color: '#7A3F91' }, // messages — purple
        'briefcase':       { bg: '#FDEBD3', color: '#B45309' }, // job posted — amber
        'circle-check':    { bg: '#D1FAE5', color: '#059669' }, // job activated — green
        'circle-pause':    { bg: '#FEF3C7', color: '#D97706' }, // job deactivated — orange
        'rotate-left':     { bg: '#DBEAFE', color: '#0284C7' }, // job restored — blue
        'calendar-check':  { bg: '#D1FAE5', color: '#059669' }, // event approved — green
        'calendar':        { bg: '#FEE2E2', color: '#DC2626' }, // event rejected — red
        'chart-line':      { bg: '#DBEAFE', color: '#0369A1' }, // employment — blue (kept for legacy rows, no longer emitted)
        'user-plus':       { bg: '#FFE8D1', color: '#B45309' }, // alumni registered — amber
        'user-group':      { bg: '#FFE8D1', color: '#B45309' }, // alumni — amber
        'pen-to-square':   { bg: '#EDE9FE', color: '#6D28D9' }, // self job-update — violet
        'trash':           { bg: '#FEE2E2', color: '#DC2626' }, // self job-delete — red
        'calendar-plus':   { bg: '#F3EBFA', color: '#7A3F91' }, // self event-created — purple
        'pen':             { bg: '#EDE9FE', color: '#6D28D9' }, // self event-updated — violet
        'calendar-xmark':  { bg: '#FEE2E2', color: '#DC2626' }, // self event-deleted — red
        'rotate-right':    { bg: '#DBEAFE', color: '#0284C7' }, // self event-resubmitted — blue
        'bell':            { bg: '#F3F4F6', color: '#6B7280' }, // default — gray
    };
    window.__coordIconBg = function (icon) {
        return (window.__coordIconColors[icon] || window.__coordIconColors['bell']).bg;
    };
    window.__coordIconColor = function (icon) {
        return (window.__coordIconColors[icon] || window.__coordIconColors['bell']).color;
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeCoordNotifsStore = function () {
        return {
            open:       false,
            items:      [],
            _pollTimer: null,

            async init() {
                if (window.__coordLoggingOut) return;
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (window.__coordLoggingOut) return;
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () {
                    if (window.__coordLoggingOut) {
                        clearInterval(self._pollTimer);
                        self._pollTimer = null;
                        return;
                    }
                    self._fetch();
                }, 3000);
            },

            async _fetch() {
                if (window.__coordLoggingOut) return;
                try {
                    var res = await window.fetch('/coordinator/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.status === 419) {
                        window.__coordShowSessionExpired();
                        return;
                    }
                    if (res.ok) {
                        var raw = await res.json();
                        this.items = this._processNotifs(raw);
                    }
                } catch (e) { /* silently fail */ }
            },

            // ─────────────────────────────────────────────────────────────────
            //  PROCESS NOTIFS
            //  - Chat/message notifs   : group by day
            //  - Employment notifs     : EXCLUDED — not shown to organizer/coordinator at all
            //  - Self job-action notifs: group by day + action (created/updated/
            //                            activated/deactivated/deleted), ×N badge
            //  - Self event-action notifs: group by day + action (created/updated/
            //                            resubmitted/deleted), ×N badge
            //  - Event notifs          : show individually (APPROVED, REJECTED, UPDATED only)
            //  - Everything else       : show individually
            // ─────────────────────────────────────────────────────────────────
            _processNotifs(rows) {
                var result = [];
                var msgMap = new Map();
                var selfJobMap = new Map();
                var selfEventMap = new Map();

                Array.from(rows)
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var rawDedup = n.dedup_key || '';

                        var isMsgEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon === 'comments'
                        );

                        var isEventNotif = (
                            rawDedup.startsWith('event-management::') ||
                            n.icon === 'calendar-check' ||
                            n.icon === 'calendar'
                        );

                        // Employment notifs are intentionally excluded from the
                        // organizer/coordinator notification list — skip entirely.
                        var isEmpNotif = (
                            rawDedup.startsWith('employment::') ||
                            n.icon === 'chart-line'
                        );
                        if (isEmpNotif) return;

                        // Self job-action notifs (organizer posting/editing/
                        // activating/deactivating/deleting their OWN job).
                        var isSelfJobNotif = rawDedup.startsWith('job-self::');

                        // Self event-action notifs (organizer creating/editing/
                        // resubmitting/deleting their OWN event).
                        var isSelfEventNotif = rawDedup.startsWith('event-self::');

                        if (isEventNotif) {
                            var action = '';
                            var parts = rawDedup.split('::');
                            if (parts.length >= 2) action = parts[1];
                            // 'updated' intentionally excluded here — it's
                            // redundant with the event-self "You Updated an
                            // Event" notif, which already covers the
                            // organizer editing their own event. Only
                            // approved/rejected (Alumni Director decisions)
                            // are shown from this generic event channel.
                            var allowedActions = ['approved', 'rejected'];
                            if (allowedActions.indexOf(action) === -1) return;
                        }

                        // ── Timestamp used for display + sorting ──
                        // IMPORTANT: this must ALWAYS be n.created_at — never
                        // n.updated_at. updated_at changes the moment a
                        // notification row is marked as read (Eloquent
                        // "touch"), which previously caused an older
                        // notification to jump to the top of the list (and
                        // show a "just now" time) the instant it was opened,
                        // making the whole list look out of order and several
                        // unrelated items appear to share the same time.
                        var nTimestamp = n.created_at;

                        if (isMsgEvent) {
                            var day      = nTimestamp ? new Date(nTimestamp).toISOString().slice(0, 10) : 'unknown';
                            var groupKey = 'message_day::' + day;

                            if (msgMap.has(groupKey)) {
                                var g = msgMap.get(groupKey);
                                g.count = (Number(g.count) || 1) + (Number(n.count) || 1);
                                if (!n.read) g.read = false;
                                g._ids.push(n.id);
                                if (nTimestamp && new Date(nTimestamp) > new Date(g.created_at)) {
                                    g.created_at = nTimestamp;
                                }
                                g.title   = 'Chat Messages';
                                g.message = g.count + ' new chat message(s) today.';
                            } else {
                                var initCount = Number(n.count) || 1;
                                msgMap.set(groupKey, Object.assign({}, n, {
                                    count:      initCount,
                                    _ids:       [n.id],
                                    created_at: nTimestamp || n.created_at,
                                    title:      'Chat Messages',
                                    message:    initCount + ' new chat message(s) today.',
                                    icon:       'comments',
                                }));
                            }

                        } else if (isSelfJobNotif) {
                            // dedup_key shape: job-self::{action}::{YYYY-MM-DD}::{jobId}
                            var sjParts  = rawDedup.split('::');
                            var sjAction = sjParts[1] || 'updated';
                            var sjDay    = sjParts[2] || (nTimestamp ? new Date(nTimestamp).toISOString().slice(0, 10) : 'unknown');
                            var sjKey    = 'job-self_day::' + sjAction + '::' + sjDay;

                            var sjTitleMap = {
                                'created':      'Job Posted',
                                'updated':      'Job Updated',
                                'activated':    'Job Activated',
                                'deactivated':  'Job Deactivated',
                                'deleted':      'Job Deleted',
                            };
                            var sjIconMap = {
                                'created':      'briefcase',
                                'updated':      'pen-to-square',
                                'activated':    'circle-check',
                                'deactivated':  'circle-pause',
                                'deleted':      'trash',
                            };

                            if (selfJobMap.has(sjKey)) {
                                var sg = selfJobMap.get(sjKey);
                                sg.count = (Number(sg.count) || 1) + 1;
                                if (!n.read) sg.read = false;
                                sg._ids.push(n.id);
                                if (nTimestamp && new Date(nTimestamp) > new Date(sg.created_at)) {
                                    sg.created_at   = nTimestamp;
                                    sg._latestTitle = n.job_title || n.message || sg._latestTitle;
                                }
                                var sLabel = sjTitleMap[sjAction] || 'Job Updated';
                                sg.title   = sg.count + ' ' + sLabel + (sg.count > 1 ? 's' : '') + ' Today';
                                sg.message = 'Latest: "' + (sg._latestTitle || 'a job posting') + '". ' + sg.count + ' total today.';
                            } else {
                                var sInitLabel = sjTitleMap[sjAction] || 'Job Updated';
                                selfJobMap.set(sjKey, Object.assign({}, n, {
                                    count:        1,
                                    _ids:         [n.id],
                                    created_at:   nTimestamp || n.created_at,
                                    title:        '1 ' + sInitLabel + ' Today',
                                    message:      n.message || (sInitLabel + '.'),
                                    icon:         sjIconMap[sjAction] || 'briefcase',
                                    _latestTitle: n.job_title || '',
                                }));
                            }

                        } else if (isSelfEventNotif) {
                            // dedup_key shape: event-self::{action}::{YYYY-MM-DD}::{eventId}
                            var seParts  = rawDedup.split('::');
                            var seAction = seParts[1] || 'updated';
                            var seDay    = seParts[2] || (nTimestamp ? new Date(nTimestamp).toISOString().slice(0, 10) : 'unknown');
                            var seKey    = 'event-self_day::' + seAction + '::' + seDay;

                            var seTitleMap = {
                                'created':      'Event Submitted',
                                'updated':      'Event Updated',
                                'resubmitted':  'Event Resubmitted',
                                'deleted':      'Event Deleted',
                            };
                            var seIconMap = {
                                'created':      'calendar-plus',
                                'updated':      'pen',
                                'resubmitted':  'rotate-right',
                                'deleted':      'calendar-xmark',
                            };

                            if (selfEventMap.has(seKey)) {
                                var eg = selfEventMap.get(seKey);
                                eg.count = (Number(eg.count) || 1) + 1;
                                if (!n.read) eg.read = false;
                                eg._ids.push(n.id);
                                if (nTimestamp && new Date(nTimestamp) > new Date(eg.created_at)) {
                                    eg.created_at   = nTimestamp;
                                    eg._latestTitle = n.event_title || n.message || eg._latestTitle;
                                }
                                var eLabel = seTitleMap[seAction] || 'Event Updated';
                                eg.title   = eg.count + ' ' + eLabel + (eg.count > 1 ? 's' : '') + ' Today';
                                eg.message = 'Latest: "' + (eg._latestTitle || 'an event') + '". ' + eg.count + ' total today.';
                            } else {
                                var eInitLabel = seTitleMap[seAction] || 'Event Updated';
                                selfEventMap.set(seKey, Object.assign({}, n, {
                                    count:        1,
                                    _ids:         [n.id],
                                    created_at:   nTimestamp || n.created_at,
                                    title:        '1 ' + eInitLabel + ' Today',
                                    message:      n.message || (eInitLabel + '.'),
                                    icon:         seIconMap[seAction] || 'calendar-plus',
                                    _latestTitle: n.event_title || '',
                                }));
                            }

                        } else {
                            result.push(Object.assign({}, n, {
                                count:      Number(n.count) || 1,
                                _ids:       [n.id],
                                created_at: nTimestamp || n.created_at,
                            }));
                        }
                    });

                msgMap.forEach(function (v) { result.push(v); });
                selfJobMap.forEach(function (v) { result.push(v); });
                selfEventMap.forEach(function (v) { result.push(v); });

                // Unread items float to the top (newest first), read items
                // sit below (also newest first). This guarantees a single,
                // clean "Already Read" divider point in the rendered list —
                // no unread item can ever appear after a read one.
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
                if (window.__coordLoggingOut) return;
                if (item.read) return;
                item.read = true;
                var ids  = Array.isArray(item._ids) ? item._ids : [item.id];
                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                for (var i = 0; i < ids.length; i++) {
                    try {
                        var r = await window.fetch('/coordinator/notifications/' + ids[i] + '/read', {
                            method: 'PATCH',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (r.status === 419) { window.__coordShowSessionExpired(); return; }
                    } catch (e) { /* ignore */ }
                }
            },

            async markAllRead() {
                if (window.__coordLoggingOut) return;
                this.items.forEach(function (n) { n.read = true; });
                try {
                    var r = await window.fetch('/coordinator/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                    if (r.status === 419) { window.__coordShowSessionExpired(); }
                } catch (e) { /* ignore */ }
            },

            async markReadByRoute(routeName) {
                if (window.__coordLoggingOut) return;
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
                            var r = await window.fetch('/coordinator/notifications/' + ids[j] + '/read', {
                                method: 'PATCH',
                                headers: {
                                    'X-CSRF-TOKEN':     csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                }
                            });
                            if (r.status === 419) { window.__coordShowSessionExpired(); return; }
                        } catch (e) { /* ignore */ }
                    }
                }
            },
        };
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SAFE ACCESSOR
    // ─────────────────────────────────────────────────────────────────────────
    window.__safeCoordNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('coordNotifs');
                if (s) return s;
            }
        } catch (e) {}
        return null;
    };

    window.__bootCoordNotifsStore = function () {
        if (window.__coordLoggingOut) return;
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('coordNotifs')) {
            Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
        }
        var s = Alpine.store('coordNotifs');
        if (s && !s._pollTimer) s.init();
    };

    document.addEventListener('alpine:init', function () {
        Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
    });

    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            if (window.__coordLoggingOut) return;
            var s = window.__safeCoordNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    window.addEventListener('load', function () {
        if (window.__coordLoggingOut) return;
        var s = window.__safeCoordNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootCoordNotifsStore(); }
    });

    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (window.__coordLoggingOut) return;
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('coordNotifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open = false;
                s.init();
            } else {
                Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
                var ns = Alpine.store('coordNotifs');
                if (ns) ns.init();
            }
        }, 150);
    });

    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('coordNotifs');
        if (!s) {
            Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
            s = Alpine.store('coordNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    document.addEventListener('visibilitychange', function () {
        if (window.__coordLoggingOut) return;
        if (document.visibilityState === 'visible') {
            var s = window.__safeCoordNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — desktop only (mobile is handled entirely by CSS, full screen)
    // ─────────────────────────────────────────────────────────────────────────
    function positionCoordPanel() {
        if (window.innerWidth < 1024) return;
        var btn   = document.getElementById('coord-bell-btn');
        var panel = document.getElementById('coord-notif-panel');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        panel.style.left  = (btnRect.right - 400) + 'px';
        panel.style.top   = (btnRect.bottom + 8) + 'px';
        panel.style.width = '400px';
    }
    window.positionCoordPanel = positionCoordPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeCoordNotifsStore();
        if (s && s.open) positionCoordPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SIDEBAR SMART MARK-READ
    // ─────────────────────────────────────────────────────────────────────────
    window.__coordSidebarNotifsMarkRead = function (routeName) {
        if (window.__coordLoggingOut) return;
        var s = window.__safeCoordNotifsStore();
        if (!s) return;
        s.markReadByRoute(routeName);
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIFICATION EVENT LISTENERS
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstCoordNotifListeners) {
        window.__philcstCoordNotifListeners = true;

        function _coordDetail(e) {
            var d = e.detail;
            if (!d) return {};
            if (!Array.isArray(d)) return d;
            return d[0] || {};
        }

        function _todayStr() {
            // Local-date (not UTC) "today" bucket so the ×N grouping matches
            // what the organizer actually perceives as "today".
            var d = new Date();
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        async function _saveCoordNotif(payload) {
            if (window.__coordLoggingOut) return;
            try {
                var res = await window.fetch('/coordinator/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.status === 419) { window.__coordShowSessionExpired(); return; }
                await new Promise(function (r) { setTimeout(r, 300); });
                if (window.__coordLoggingOut) return;
                var s = window.__safeCoordNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    if (window.__coordLoggingOut) return;
                    var s2 = window.__safeCoordNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        window.addEventListener('event-management-updated', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            var action = d.action || '';

            // 'updated' intentionally excluded — it's redundant with the
            // event-self "You Updated an Event" notif already fired by
            // event-self-action when the organizer edits their own event.
            // Only Alumni Director decisions (approved/rejected) go through
            // this channel.
            if (action !== 'approved' && action !== 'rejected') {
                return;
            }

            var titleMap = {
                'approved': 'Event Approved',
                'rejected': 'Event Rejected',
            };
            var msgMap = {
                'approved': (d.title || 'Your event') + ' has been approved by the Alumni Director.',
                'rejected': (d.title || 'Your event') + ' has been rejected by the Alumni Director.',
            };
            var iconMap = {
                'approved': 'calendar-check',
                'rejected': 'calendar',
            };

            _saveCoordNotif({
                icon:       iconMap[action]  || 'calendar-check',
                title:      titleMap[action] || 'Event Update',
                message:    msgMap[action]   || (d.title || 'An event') + ' was ' + action + '.',
                link_route: 'organizer.event/organizer',
                link_label: 'View Events',
                dedup_key:  'event-management::' + action + '::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── Job actions coming from the Alumni Director's Manage Job screen
        //    (resources/views/livewire/director/manage-job.blade.php →
        //    notifyOrganizers() → dispatch('job-management-updated', ...)).
        //    Covers the full lifecycle: posted (created), updated, activated,
        //    deactivated, deleted, restored — all clearly labeled as done
        //    "by the Alumni Director" so organizers/coordinators know these
        //    came from the director's side, not their own actions. ──
        window.addEventListener('job-management-updated', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            var action = d.action || '';

            var allowedJobActions = ['director_posted', 'updated', 'activated', 'deactivated', 'deleted', 'restored'];
            if (allowedJobActions.indexOf(action) === -1) return;

            var titleMap = {
                'director_posted': 'Job Posted by Alumni Director',
                'updated':         'Job Updated by Alumni Director',
                'activated':       'Job Activated by Alumni Director',
                'deactivated':     'Job Deactivated by Alumni Director',
                'deleted':         'Job Deleted by Alumni Director',
                'restored':        'Job Restored by Alumni Director',
            };
            var msgMap = {
                'director_posted': 'Alumni Director posted a new job: "' + (d.title || 'Untitled') + '".',
                'updated':         'Alumni Director updated the job posting "' + (d.title || 'a job posting') + '".',
                'activated':       'Alumni Director activated "' + (d.title || 'a job posting') + '" — now visible to alumni.',
                'deactivated':     'Alumni Director deactivated "' + (d.title || 'a job posting') + '".',
                'deleted':         'Alumni Director deleted the job posting "' + (d.title || 'a job posting') + '".',
                'restored':        'Alumni Director restored the job posting "' + (d.title || 'a job posting') + '".',
            };
            var iconMap = {
                'director_posted': 'briefcase',
                'updated':         'pen-to-square',
                'activated':       'circle-check',
                'deactivated':     'circle-pause',
                'deleted':         'trash',
                'restored':        'rotate-left',
            };

            _saveCoordNotif({
                icon:       iconMap[action]  || 'briefcase',
                title:      titleMap[action] || 'Job Update by Alumni Director',
                message:    msgMap[action]   || (d.title || 'A job posting') + ' was ' + action + ' by the Alumni Director.',
                link_route: 'organizer.job/management',
                link_label: 'View Jobs',
                dedup_key:  'job-management::' + action + '::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── Self-notif: fires when the organizer creates/updates/activates/
        //    deactivates/deletes THEIR OWN job posting. Grouped by day+action
        //    on the frontend (job-self::{action}::{day}::{jobId} dedup key),
        //    so 3 edits today collapse into one "3 Jobs Updated Today" row. ──
        window.addEventListener('job-self-action', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            var action = d.action || 'updated';

            var titleMap = {
                'created':      'You Posted a Job',
                'updated':      'You Updated a Job',
                'activated':    'You Activated a Job',
                'deactivated':  'You Deactivated a Job',
                'deleted':      'You Deleted a Job',
            };
            var msgMap = {
                'created':      'You posted "' + (d.title || 'a job') + '".',
                'updated':      'You updated "' + (d.title || 'a job') + '".',
                'activated':    'You activated "' + (d.title || 'a job') + '".',
                'deactivated':  'You deactivated "' + (d.title || 'a job') + '".',
                'deleted':      'You deleted "' + (d.title || 'a job') + '".',
            };
            var iconMap = {
                'created':      'briefcase',
                'updated':      'pen-to-square',
                'activated':    'circle-check',
                'deactivated':  'circle-pause',
                'deleted':      'trash',
            };

            _saveCoordNotif({
                icon:       iconMap[action]  || 'briefcase',
                title:      titleMap[action] || 'Job Update',
                message:    msgMap[action]   || (d.title || 'A job posting') + ' was ' + action + '.',
                job_title:  d.title || '',
                link_route: 'organizer.job/management',
                link_label: 'View Jobs',
                dedup_key:  'job-self::' + action + '::' + _todayStr() + '::' + (d.id || 0),
            });
        });

        // ── Self-notif: fires when the organizer creates/updates/resubmits/
        //    deletes THEIR OWN event. Grouped by day+action on the frontend
        //    (event-self::{action}::{day}::{eventId} dedup key), so multiple
        //    edits today collapse into one "3 Events Updated Today" row. ──
        window.addEventListener('event-self-action', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            var action = d.action || 'updated';

            var titleMap = {
                'created':      'You Submitted an Event',
                'updated':      'You Updated an Event',
                'resubmitted':  'You Resubmitted an Event',
                'deleted':      'You Deleted an Event',
            };
            var msgMap = {
                'created':      'You submitted "' + (d.title || 'an event') + '" for Alumni Director review.',
                'updated':      'You updated "' + (d.title || 'an event') + '".',
                'resubmitted':  'You resubmitted "' + (d.title || 'an event') + '" for Alumni Director review.',
                'deleted':      'You deleted "' + (d.title || 'an event') + '".',
            };
            var iconMap = {
                'created':      'calendar-plus',
                'updated':      'pen',
                'resubmitted':  'rotate-right',
                'deleted':      'calendar-xmark',
            };

            _saveCoordNotif({
                icon:        iconMap[action]  || 'calendar-plus',
                title:       titleMap[action] || 'Event Update',
                message:     msgMap[action]   || (d.title || 'An event') + ' was ' + action + '.',
                event_title: d.title || '',
                link_route:  'organizer.event/organizer',
                link_label:  'View Events',
                dedup_key:   'event-self::' + action + '::' + _todayStr() + '::' + (d.id || 0),
            });
        });

        // ── Employment notifs: listener intentionally removed. Organizer/
        //    coordinator should NOT see employment-status-update notifs. ──

        window.addEventListener('coord-notif-refresh', function () {
            if (window.__coordLoggingOut) return;
            var s = window.__safeCoordNotifsStore();
            if (s) {
                s._fetch();
                setTimeout(function () {
                    if (window.__coordLoggingOut) return;
                    var s2 = window.__safeCoordNotifsStore();
                    if (s2) s2._fetch();
                }, 600);
            }
        });

        window.addEventListener('coord-message-received', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            var sender = d.sender || 'Someone';
            var room   = d.room   || 'Group Chat';
            var body   = d.body   || '';
            var count  = Number(d.count) || 1;
            var msgText = count > 1
                ? sender + ' and others sent ' + count + ' new messages in ' + room + '.'
                : sender + ' sent a message in ' + room +
                  (body ? ': "' + body.substring(0, 50) + (body.length > 50 ? '…' : '') + '"' : '.');

            _saveCoordNotif({
                icon:       'comments',
                title:      'Chat Messages',
                message:    msgText,
                link_route: 'organizer.chat/alumni',
                link_label: 'Open Messages',
                dedup_key:  'message-received::fallback::' + Math.floor(Date.now() / 60000),
            });
        });

        window.addEventListener('alumni-registered', function (e) {
            if (window.__coordLoggingOut) return;
            var d = _coordDetail(e);
            _saveCoordNotif({
                icon:       'user-plus',
                title:      'New Alumni Registered',
                message:    (d.name || 'A new alumni') + ' has completed their registration.',
                link_route: 'organizer.dashboard',
                link_label: 'View Dashboard',
                dedup_key:  'alumni-registered::' + (d.id || Math.floor(Date.now() / 60000)),
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
    @click="$store.coordNotifs && $store.coordNotifs.open && $store.coordNotifs.close()"
    @close-sidebar.window="sidebarHiddenByModal = true; open = false;"
    @open-sidebar.window="sidebarHiddenByModal = false;">

<div class="coord-app-shell flex bg-[#F5F5F5] font-sans overflow-hidden">

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
        id="coord-sidebar-aside"
        :class="{
            'translate-x-0': open,
            '-translate-x-full': !open,
            'is-collapsed': sidebarCollapsed,
            'coord-sidebar-modal-hidden': sidebarHiddenByModal
        }"
        class="coord-sidebar fixed inset-y-0 left-0 z-[60] transform
               lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0">

        {{-- Sidebar header --}}
        <div class="coord-sidebar-header h-24 px-5 shrink-0">
            <div class="coord-header-inner">
                <div class="coord-badge-icon">
                    <i class="fa-solid fa-diagram-project text-white" style="font-size:17px;"></i>
                </div>
                <div class="min-w-0 coord-collapsible-text">
                    <h1 class="text-[19px] font-bold tracking-tight text-white leading-tight truncate">
                        Coordinator<span class="font-semibold text-white/70">Portal</span>
                    </h1>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-white/60 font-semibold">
                        Management
                    </p>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">

            <div class="coord-nav-section-row">
                <p class="coord-section-label coord-collapsible-text">MENU</p>

                <button type="button"
                        @click.stop="toggleSidebar()"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        class="coord-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="{ 'fa-angles-right': sidebarCollapsed, 'fa-angles-left': !sidebarCollapsed }"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                $sidebarLinks = [
                    [
                        'route'   => 'organizer.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'organizer/dashboard*',
                    ],
                    [
                        'route'   => 'organizer.event/organizer',
                        'icon'    => 'calendar-check',
                        'label'   => 'Event Management',
                        'pattern' => 'organizer/event/organizer*',
                    ],
                    [
                        'route'   => 'organizer.job/management',
                        'icon'    => 'briefcase',
                        'label'   => 'Job Management',
                        'pattern' => 'organizer/job/management*',
                    ],
                    [
                        'route'   => 'organizer.alumni/employment',
                        'icon'    => 'chart-line',
                        'label'   => 'Employment Tracking',
                        'pattern' => 'organizer/alumni/employment*',
                    ],
                    [
                        'route'   => 'organizer.chat/alumni',
                        'icon'    => 'comments',
                        'label'   => 'Message Hub',
                        'pattern' => 'organizer/chat/alumni*',
                    ],
                    [
                        'route'   => 'organizer.yearbook',
                        'icon'    => 'book-open',
                        'label'   => 'Alumni Yearbook',
                        'pattern' => 'organizer/yearbook*',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php $isActive = request()->is($link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="window.__coordSidebarNotifsMarkRead('{{ $link['route'] }}'); open = false;"
                   class="coord-nav-link {{ $isActive ? 'is-active' : '' }}
                          flex items-center px-4 py-3 rounded-xl group">

                    <div class="coord-nav-icon w-10 h-10 flex items-center justify-center rounded-lg shrink-0 mr-3.5"
                         style="background-color:{{ $isActive ? '#FFFFFF' : '#F9F7FC' }};color:#7A3F91;
                                box-shadow:{{ $isActive ? '0 2px 6px rgba(122,63,145,0.18)' : 'none' }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="coord-nav-label coord-collapsible-text font-medium tracking-wide flex-1 text-[14px]
                                 {{ $isActive ? 'text-[#5A2D70] font-bold' : 'text-[#3A3A3A]' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="coord-active-dot coord-collapsible-text ml-auto w-1.5 h-6 rounded-full shrink-0"
                              style="background:#7A3F91;"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- ══ BACKGROUND NOTIF POLLER ══ --}}
        {{--
            IMPORTANT: this Livewire component is what caused the "This page
            has expired" flash on logout. Its wire:poll.3000ms request runs
            through Livewire's own request pipeline, and can be in-flight
            (or fire) the instant the session/CSRF token is destroyed by
            POST /logout, right before the redirect navigates away.

            We stop it from ever making a poll request AGAIN after logout
            starts by wiring wire:poll to a condition that goes false the
            moment __coordLoggingOut flips true (see @submit on the logout
            form below, and the 'stop-coord-polling' listener in <head>).
            wire:poll.3000ms.keep-alive would still fire once more mid-flight
            in the worst case, but the 419 that comes back from THAT request
            is now silently swallowed because __coordShowSessionExpired()
            checks __coordLoggingOut and no-ops if true.
        --}}
      <div wire:ignore.self x-data="{ pollingActive: true }" x-on:stop-coord-polling.window="pollingActive = false">
            <template x-if="pollingActive">
                @livewire('organizer.coord-notif-poller')
            </template>
        </div>

        {{-- Logout --}}
        <div class="p-2 lg:p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit="loggingOut = true; window.dispatchEvent(new CustomEvent('stop-coord-polling'));">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        title="Logout"
                        class="coord-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="coord-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>
                            <span class="coord-logout-label-text coord-collapsible-text">Logout</span>
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="coord-logout-text-swap">
                            <span class="coord-logout-spinner mr-2"></span>
                            <span class="coord-logout-label-text coord-collapsible-text">Logging out…</span>
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
                id="coord-bell-btn"
                type="button"
                @click.stop="$store.coordNotifs && $store.coordNotifs.toggle(); positionCoordPanel();"
                title="Notifications"
                aria-label="Open notifications"
                class="coord-topbar-bell">
                <i class="bell-icon fas fa-bell"
                   :class="$store.coordNotifs && $store.coordNotifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>
                <span
                    x-show="$store.coordNotifs && $store.coordNotifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.coordNotifs && $store.coordNotifs.unread > 99
                                ? '99+'
                                : ($store.coordNotifs ? $store.coordNotifs.unread : 0)">
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
     COORDINATOR NOTIFICATION PANEL — dropdown on desktop, full screen on mobile
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="coord-notif-panel"
    x-show="$store.coordNotifs && $store.coordNotifs.open"
    x-cloak
    x-effect="if ($store.coordNotifs && $store.coordNotifs.open) $nextTick(() => positionCoordPanel())"
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
            <span x-show="$store.coordNotifs && $store.coordNotifs.unread > 0"
                  x-cloak
                  class="bg-red-500 text-white font-black px-2 py-0.5 rounded-full leading-none"
                  style="font-size:11px;"
                  x-text="$store.coordNotifs ? $store.coordNotifs.unread + ' new' : ''">
            </span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button"
                    x-show="$store.coordNotifs && $store.coordNotifs.unread > 0"
                    x-cloak
                    @click.stop="$store.coordNotifs && $store.coordNotifs.markAllRead()"
                    class="text-white/70 hover:text-white font-semibold hover:bg-white/10
                           rounded-lg px-2.5 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>

            <div class="notif-close-wrap ml-1">
                <span class="notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.coordNotifs && $store.coordNotifs.close()"
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
              x-text="($store.coordNotifs ? $store.coordNotifs.items.length : 0) + ' notification(s)'">
        </span>
    </div>

    {{-- Scrollable notification list --}}
    <div class="notif-list-scroll overflow-y-auto no-scrollbar flex-1" style="max-height: 420px;">

        <template x-if="$store.coordNotifs && $store.coordNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F9F7FC; border:1px solid #ECE2F8;">
                    <i class="fas fa-bell-slash" style="font-size:26px;color:#D7C8E6;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    Job updates, events, messages,<br>and alumni activity will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.coordNotifs">
            <template x-for="(notif, notifIdx) in $store.coordNotifs.items" :key="notif.id">
                <div>
                    <div class="coord-notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.coordNotifs.items[notifIdx - 1].read"
                         x-cloak>
                        <span class="coord-notif-divider-label">Already Read</span>
                    </div>
                <div
                    class="notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#FAF6FE] hover:bg-[#F3EBFA]'"
                    @click.stop="
                        $store.coordNotifs.markRead(notif);
                        $store.coordNotifs.close();
                        if (notif.link_route) {
                            const url = window.__coordRouteMap[notif.link_route] || '/organizer/dashboard';
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

                    {{-- Icon — colored per notif type --}}
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                         :style="'background:' + window.__coordIconBg(notif.icon) + ';'">
                        <i class="fas"
                           :class="'fa-' + (notif.icon || 'bell')"
                           :style="'font-size:15px;color:' + window.__coordIconColor(notif.icon) + ';'"></i>
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <p :class="notif.read ? 'font-semibold text-[#555555]' : 'font-bold text-[#1a1a1a]'"
                                   style="font-size:13px;line-height:1.4;"
                                   x-text="notif.title"></p>

                                {{-- Count badge: grouped chat messages --}}
                                <span
                                    x-show="Number(notif.count) > 1 && notif.icon === 'comments'"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#7A3F91;"
                                    x-text="'×' + Number(notif.count)">
                                </span>

                                {{-- Count badge: grouped self job-action + self event-action notifs --}}
                                <span
                                    x-show="Number(notif.count) > 1 && ['briefcase','pen-to-square','circle-check','circle-pause','trash','calendar-plus','pen','calendar-xmark','rotate-right'].includes(notif.icon)"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#6D28D9;"
                                    x-text="'×' + Number(notif.count)">
                                </span>

                                {{-- Job badge — posted by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'briefcase' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#B45309;">
                                    POSTED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Job activated badge — by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'circle-check' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#059669;">
                                    ACTIVATED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Job deactivated badge — by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'circle-pause' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#D97706;">
                                    DEACTIVATED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Job restored badge — by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'rotate-left' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#0284C7;">
                                    RESTORED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Job updated badge — by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'pen-to-square' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#6D28D9;">
                                    UPDATED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Job deleted badge — by Alumni Director --}}
                                <span
                                    x-show="notif.icon === 'trash' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#DC2626;">
                                    DELETED BY ALUMNI DIRECTOR
                                </span>

                                {{-- Event Approved badge --}}
                                <span
                                    x-show="notif.icon === 'calendar-check' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#059669;">
                                    EVENT
                                </span>

                                {{-- Event Rejected badge --}}
                                <span
                                    x-show="notif.icon === 'calendar' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#DC2626;">
                                    EVENT
                                </span>

                                {{-- Chat Message badge --}}
                                <span
                                    x-show="notif.icon === 'comments' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#7A3F91;">
                                    MESSAGES
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

                        <p class="text-[#666666] mt-1 leading-relaxed"
                           style="font-size:12px;
                                  display:-webkit-box;
                                  -webkit-line-clamp:2;
                                  -webkit-box-orient:vertical;
                                  overflow:hidden;"
                           x-text="notif.message">
                        </p>

                        <div class="flex items-center gap-1 mt-2">
                            <i class="fas fa-clock" style="font-size:10px;color:#CCCCCC;"></i>
                            <span style="font-size:11px;color:#AAAAAA;font-weight:500;"
                                  x-text="notif.created_at
                                      ? new Date(notif.created_at).toLocaleString('en-PH',{
                                          month:'short',day:'numeric',year:'numeric',
                                          hour:'2-digit',minute:'2-digit',second:'2-digit'
                                        })
                                      : ''">
                            </span>
                        </div>
                    </div>

                </div>
                </div>
            </template>
        </template>
    </div>

    {{-- Panel Footer --}}
    <div class="px-5 py-3 border-t border-[#F0ECF8] text-center shrink-0" style="background:#FAFAFA;">
        <p style="font-size:11px;color:#BBBBBB;font-weight:500;">
            Click a notification to view and mark as read
        </p>
    </div>
</div>

{{-- ══ SESSION-EXPIRED SOFT MODAL — replaces raw "Page Expired" screen ══ --}}
<div id="coord-session-expired-modal">
    <div class="coord-sem-card">
        <div class="coord-sem-icon"><i class="fas fa-clock-rotate-left"></i></div>
        <p class="coord-sem-title">Your session has expired</p>
        <p class="coord-sem-sub">
            This tab was open for a while and your session timed out.
            Refresh the page to continue where you left off.
        </p>
        <button type="button" class="coord-sem-btn" onclick="window.location.reload()">
            <i class="fas fa-rotate-right mr-1.5"></i> Refresh Page
        </button>
    </div>
</div>

@livewireScripts

{{-- ✅ CLOSE ON OUTSIDE CLICK (no-op on mobile since panel is full screen) --}}
<div
    x-data
    x-show="$store.coordNotifs && $store.coordNotifs.open"
    x-cloak
    @click="$store.coordNotifs && $store.coordNotifs.close()"
    class="fixed inset-0 lg:block hidden"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>