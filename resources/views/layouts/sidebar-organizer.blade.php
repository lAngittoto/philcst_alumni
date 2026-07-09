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

        /* ── Mobile viewport height fix ──
           h-screen (100vh) is unreliable on mobile browsers because the
           address bar resizes the viewport and 100vh gets computed against
           the LARGEST possible viewport, not the current visible one. That
           mismatch is what breaks scroll on mobile. h-dvh (dynamic
           viewport height) tracks the real visible viewport instead. */
        .coord-app-shell {
            height: 100vh;      /* fallback for older browsers */
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

        /* ════════════════════════════════════════════════════════
           COORDINATOR SIDEBAR — WHITE (baliktad ng dark version)
        ════════════════════════════════════════════════════════ */
        #coord-sidebar-aside {
            background: #FFFFFF;
            border-right: 1px solid #E8E0F0;
        }

        .coord-sidebar-header {
            border-bottom: 1px solid #E8E0F0;
        }
        .coord-badge-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: #F3EBFA;
            border: 1px solid #ECE2F8;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: #7A3F91;
        }

        .coord-nav-panel {
            background: #7A3F91;
        }

        .coord-nav-link {
            position: relative;
            border-left: 3px solid transparent;
            transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
        }
        .coord-nav-link:not(.is-active):hover {
            background: rgba(255,255,255,0.08);
        }
        .coord-nav-link:not(.is-active):hover .coord-nav-icon {
            transform: scale(1.07);
        }
        .coord-nav-link.is-active {
            background: rgba(255,255,255,0.18);
            border-left-color: #FFFFFF;
        }
        .coord-nav-icon { transition: transform 0.2s ease; }

        .coord-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: rgba(255,255,255,0.55);
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }

        /* Logout button + spinner */
        .coord-logout-btn {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            padding: 1rem 1rem;
            border-radius: 14px;
            font-weight: 800;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #7A3F91;
            background: #FFFFFF;
            border: none;
            cursor: pointer;
            overflow: hidden;
            box-shadow: 0 8px 18px -6px rgba(0,0,0,0.25);
            transition: filter 0.2s ease, transform 0.15s ease;
        }
        .coord-logout-btn:hover   { background: #F3EBFA; }
        .coord-logout-btn:active  { transform: scale(0.97); }
        .coord-logout-btn:disabled {
            cursor: not-allowed;
            filter: grayscale(0.15) brightness(0.9);
        }
        .coord-logout-spinner {
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(122,63,145,0.25);
            border-top-color: #7A3F91;
            animation: coord-spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes coord-spin { to { transform: rotate(360deg); } }
        .coord-logout-text-swap { display: inline-flex; align-items: center; }

        @media (max-width: 1023px) {
            #coord-sidebar-aside {
                box-shadow: 0 0 60px rgba(0,0,0,0.35);
                width: 5.5rem;
                min-width: 5.5rem;
            }

            /* Hide the header block (icon + Coordinator/Event Management) on mobile */
            #coord-sidebar-aside .coord-sidebar-header {
                display: none !important;
            }

            /* Icon-only nav on mobile: hide labels + section title */
            #coord-sidebar-aside .coord-section-label,
            #coord-sidebar-aside .coord-nav-link span:not(.coord-nav-icon) {
                display: none !important;
            }
            #coord-sidebar-aside .coord-nav-link {
                justify-content: center;
                padding: 0.85rem;
                border-left: none;
                border-top: 3px solid transparent;
            }
            #coord-sidebar-aside .coord-nav-link.is-active {
                border-top-color: #FFFFFF;
            }
            #coord-sidebar-aside .coord-nav-icon {
                margin-right: 0 !important;
            }

            /* Icon-only logout button on mobile */
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
        'chart-line':      { bg: '#DBEAFE', color: '#0369A1' }, // employment — blue
        'user-plus':       { bg: '#FFE8D1', color: '#B45309' }, // alumni registered — amber
        'user-group':      { bg: '#FFE8D1', color: '#B45309' }, // alumni — amber
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
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () { self._fetch(); }, 3000);
            },

            async _fetch() {
                try {
                    var res = await window.fetch('/coordinator/notifications', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.ok) {
                        var raw = await res.json();
                        this.items = this._processNotifs(raw);
                    }
                } catch (e) { /* silently fail */ }
            },

            // ─────────────────────────────────────────────────────────────────
            //  PROCESS NOTIFS
            //  - Chat/message notifs  : group by day
            //  - Employment notifs    : group by day (×N badge, latest updater)
            //  - Event notifs         : show individually (APPROVED, REJECTED, UPDATED only)
            // ─────────────────────────────────────────────────────────────────
            _processNotifs(rows) {
                var result = [];
                var msgMap = new Map();
                var empMap = new Map();

                // Sort newest first
                Array.from(rows)
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var rawDedup = n.dedup_key || '';

                        // ── Determine notif type ──
                        var isMsgEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon === 'comments'
                        );

                        var isEventNotif = (
                            rawDedup.startsWith('event-management::') ||
                            n.icon === 'calendar-check' ||
                            n.icon === 'calendar'
                        );

                        var isEmpNotif = (
                            rawDedup.startsWith('employment::') ||
                            n.icon === 'chart-line'
                        );

                        // ── Filter: only keep APPROVED, REJECTED, UPDATED for events ──
                        if (isEventNotif) {
                            var action = '';
                            var parts = rawDedup.split('::');
                            if (parts.length >= 2) action = parts[1];
                            var allowedActions = ['approved', 'rejected', 'updated'];
                            if (allowedActions.indexOf(action) === -1) return;
                        }

                        // ── Use the latest timestamp for display ──
                        var nTimestamp = n.updated_at && new Date(n.updated_at) > new Date(n.created_at)
                            ? n.updated_at
                            : n.created_at;

                        if (isMsgEvent) {
                            // ── Group chat messages by day ──
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

                        } else if (isEmpNotif) {
                            // ── Group employment notifs by day ──
                            var empDay = nTimestamp ? new Date(nTimestamp).toISOString().slice(0, 10) : 'unknown';
                            var empKey = 'employment_day::' + empDay;

                            if (empMap.has(empKey)) {
                                var eg = empMap.get(empKey);
                                eg.count = (Number(eg.count) || 1) + 1;
                                if (!n.read) eg.read = false;
                                eg._ids.push(n.id);
                                // If this notif is newer → update timestamp + message (most recent updater)
                                if (nTimestamp && new Date(nTimestamp) > new Date(eg.created_at)) {
                                    eg.created_at = nTimestamp;
                                    eg.message    = n.message;
                                }
                                eg.title = eg.count + ' Employment Status Updated';
                            } else {
                                empMap.set(empKey, Object.assign({}, n, {
                                    count:      1,
                                    _ids:       [n.id],
                                    created_at: nTimestamp || n.created_at,
                                    title:      '1 Employment Status Updated',
                                    icon:       'chart-line',
                                }));
                            }

                        } else {
                            // ── All other notifs (events, jobs, alumni): individual ──
                            result.push(Object.assign({}, n, {
                                count:      Number(n.count) || 1,
                                _ids:       [n.id],
                                created_at: nTimestamp || n.created_at,
                            }));
                        }
                    });

                // Merge grouped items into result
                msgMap.forEach(function (v) { result.push(v); });
                empMap.forEach(function (v) { result.push(v); });

                // Re-sort combined result newest first
                result.sort(function (a, b) {
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
                if (item.read) return;
                item.read = true;
                var ids  = Array.isArray(item._ids) ? item._ids : [item.id];
                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                for (var i = 0; i < ids.length; i++) {
                    try {
                        await window.fetch('/coordinator/notifications/' + ids[i] + '/read', {
                            method: 'PATCH',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                    } catch (e) { /* ignore */ }
                }
            },

            async markAllRead() {
                this.items.forEach(function (n) { n.read = true; });
                try {
                    await window.fetch('/coordinator/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                } catch (e) { /* ignore */ }
            },

            async markReadByRoute(routeName) {
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
                            await window.fetch('/coordinator/notifications/' + ids[j] + '/read', {
                                method: 'PATCH',
                                headers: {
                                    'X-CSRF-TOKEN':     csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                }
                            });
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
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('coordNotifs')) {
            Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
        }
        var s = Alpine.store('coordNotifs');
        if (s && !s._pollTimer) s.init();
    };

    // PATH A
    document.addEventListener('alpine:init', function () {
        Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
    });

    // PATH B
    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeCoordNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    // PATH C
    window.addEventListener('load', function () {
        var s = window.__safeCoordNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootCoordNotifsStore(); }
    });

    // PATH D — livewire:navigated
    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
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

    // PATH E — IIFE
    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('coordNotifs');
        if (!s) {
            Alpine.store('coordNotifs', window.__makeCoordNotifsStore());
            s = Alpine.store('coordNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    // Re-fetch on tab focus
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeCoordNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — desktop only (mobile is handled entirely by CSS, full screen)
    // ─────────────────────────────────────────────────────────────────────────
    function positionCoordPanel() {
        if (window.innerWidth < 1024) return; // mobile = full screen via CSS, no inline positioning needed
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

        async function _saveCoordNotif(payload) {
            try {
                await window.fetch('/coordinator/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                // Wait a bit then re-fetch so panel shows updated timestamp immediately
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeCoordNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeCoordNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        // ── EVENT NOTIFS: only APPROVED and REJECTED dispatch from director ──
        window.addEventListener('event-management-updated', function (e) {
            var d = _coordDetail(e);
            var action = d.action || '';

            if (action !== 'approved' && action !== 'rejected' && action !== 'updated') {
                return;
            }

            var titleMap = {
                'approved': 'Event Approved',
                'rejected': 'Event Rejected',
                'updated':  'Event Updated',
            };
            var msgMap = {
                'approved': (d.title || 'Your event') + ' has been approved by the Alumni Director.',
                'rejected': (d.title || 'Your event') + ' has been rejected by the Alumni Director.',
                'updated':  (d.title || 'An event') + ' has been updated.',
            };
            var iconMap = {
                'approved': 'calendar-check',
                'rejected': 'calendar',
                'updated':  'calendar-check',
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

        // ── JOB NOTIFS: only activated, deactivated, restored, director_posted ──
        window.addEventListener('job-management-updated', function (e) {
            var d = _coordDetail(e);
            var action = d.action || '';

            // Only these actions trigger a coordinator notification
            var allowedJobActions = ['activated', 'deactivated', 'restored', 'director_posted'];
            if (allowedJobActions.indexOf(action) === -1) return;

            var titleMap = {
                'activated':       'Job Posting Activated',
                'deactivated':     'Job Posting Deactivated',
                'restored':        'Job Posting Restored',
                'director_posted': 'New Job Posted by Director',
            };
            var msgMap = {
                'activated':       (d.title || 'A job posting') + ' is now active and visible to alumni.',
                'deactivated':     (d.title || 'A job posting') + ' has been deactivated.',
                'restored':        (d.title || 'A deleted job posting') + ' has been restored.',
                'director_posted': 'Alumni Director posted a new job: "' + (d.title || 'Untitled') + '".',
            };
            var iconMap = {
                'activated':       'circle-check',
                'deactivated':     'circle-pause',
                'restored':        'rotate-left',
                'director_posted': 'briefcase',
            };

            _saveCoordNotif({
                icon:       iconMap[action]  || 'briefcase',
                title:      titleMap[action] || 'Job Update',
                message:    msgMap[action]   || (d.title || 'A job posting') + ' was ' + action + '.',
                link_route: 'organizer.job/management',
                link_label: 'View Jobs',
                dedup_key:  'job-management::' + action + '::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        window.addEventListener('employment-updated', function (e) {
            var d = _coordDetail(e);
            _saveCoordNotif({
                icon:       'chart-line',
                title:      'Employment Status Updated',
                message:    (d.alumni || 'An alumni') + ' updated their employment status.',
                link_route: 'organizer.alumni/employment',
                link_label: 'View Employment',
                dedup_key:  'employment::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // coord-notif-refresh: just re-fetch the store — no DB write
        window.addEventListener('coord-notif-refresh', function () {
            var s = window.__safeCoordNotifsStore();
            if (s) {
                s._fetch();
                setTimeout(function () {
                    var s2 = window.__safeCoordNotifsStore();
                    if (s2) s2._fetch();
                }, 600);
            }
        });

        // Fallback only — normally the PHP poller writes to DB directly
        window.addEventListener('coord-message-received', function (e) {
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
    x-data="{ open: false, loggingOut: false }"
    @click="$store.coordNotifs && $store.coordNotifs.open && $store.coordNotifs.close()">

<div class="coord-app-shell flex bg-[#F5F5F5] font-sans overflow-hidden">

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

    {{-- ══ SIDEBAR — dark, distinct coordinator theme ══ --}}
    <aside
        id="coord-sidebar-aside"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300
               lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full overflow-hidden shrink-0">

        {{-- Sidebar header --}}
        <div class="coord-sidebar-header flex items-center h-24 px-5 shrink-0">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <div class="coord-badge-icon">
                    <i class="fa-solid fa-diagram-project" style="font-size:17px;"></i>
                </div>
                <div class="min-w-0">
                    <h1 class="text-[19px] font-bold tracking-tight text-[#333333] leading-tight truncate">
                        Coordinator<span class="font-semibold text-[#7A3F91]">Portal</span>
                    </h1>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-[#9A8AA8] font-semibold">
                        Management
                    </p>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="coord-nav-panel flex-1 px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">
            <p class="coord-section-label">MENU</p>

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
                   @click="window.__coordSidebarNotifsMarkRead('{{ $link['route'] }}'); open = false;"
                   class="coord-nav-link {{ $isActive ? 'is-active' : '' }}
                          flex items-center px-4 py-3 rounded-r-xl group">

                    <div class="coord-nav-icon w-10 h-10 flex items-center justify-center rounded-lg shrink-0 mr-3.5"
                         style="background-color:{{ $isActive ? '#FFFFFF' : 'rgba(255,255,255,0.14)' }};
                                color:{{ $isActive ? '#7A3F91' : '#FFFFFF' }};
                                box-shadow:{{ $isActive ? '0 2px 6px rgba(0,0,0,0.15)' : 'none' }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="font-medium tracking-wide flex-1 text-[14px]
                                 {{ $isActive ? 'text-white font-bold' : 'text-white/75' }}">
                        {{ $link['label'] }}
                    </span>
                </a>
            @endforeach
        </nav>

        {{-- ══ BACKGROUND NOTIF POLLER ══ --}}
        @livewire('organizer.coord-notif-poller')

        {{-- Logout --}}
        <div class="coord-nav-panel p-4 mt-auto border-t border-white/15 shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit="loggingOut = true">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        class="coord-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="coord-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>
                            <span>Logout</span>
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="coord-logout-text-swap">
                            <span class="coord-logout-spinner mr-2"></span>
                            <span>Logging out…</span>
                        </span>
                    </template>
                </button>
            </form>
        </div>
    </aside>

    {{-- ══ MAIN CONTENT ══ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0 min-h-0">

        {{-- Top bar — visible on ALL screen sizes. Hamburger only shows on mobile.
             Bell always sits on the right, icon-only. --}}
        <header class="flex items-center justify-between px-4 lg:px-8 h-24 bg-white border-b border-[#E8E0F0]
                       shrink-0 z-30">
            <div class="flex items-center gap-3">
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
            </div>

            <span class="hidden lg:block"></span>

            {{-- Notifications bell — right side, icon-only on every screen size --}}
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

        {{-- Page content — scroll container. min-height:0 is REQUIRED here:
             without it, this flex child refuses to shrink below its content
             size, so it grows past the screen instead of scrolling, and the
             outer `overflow-hidden` wrapper just clips the excess.
             That's what was causing both the "cut off" look AND the frozen
             mobile scroll — same bug, two symptoms.
             overscroll-behavior-y: contain (in .no-scrollbar) stops mobile
             browsers from bubbling the scroll gesture up to the body when
             this container hits its top/bottom edge, which is the other
             common cause of "hindi ako maka-scroll" on phones. --}}
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
            <template x-for="notif in $store.coordNotifs.items" :key="notif.id">
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

                                {{-- Count badge: grouped employment notifs --}}
                                <span
                                    x-show="Number(notif.count) > 1 && notif.icon === 'chart-line'"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#0284c7;"
                                    x-text="'×' + Number(notif.count)">
                                </span>

                                {{-- Job badge --}}
                                <span
                                    x-show="notif.icon === 'briefcase' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#B45309;">
                                    JOB
                                </span>

                                {{-- Job activated badge --}}
                                <span
                                    x-show="notif.icon === 'circle-check' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#059669;">
                                    JOB
                                </span>

                                {{-- Job deactivated badge --}}
                                <span
                                    x-show="notif.icon === 'circle-pause' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#D97706;">
                                    JOB
                                </span>

                                {{-- Job restored badge --}}
                                <span
                                    x-show="notif.icon === 'rotate-left' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#0284C7;">
                                    JOB
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

                                {{-- Employment badge --}}
                                <span
                                    x-show="notif.icon === 'chart-line' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#0369A1;">
                                    EMPLOYMENT
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
                                          hour:'2-digit',minute:'2-digit'
                                        })
                                      : ''">
                            </span>
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