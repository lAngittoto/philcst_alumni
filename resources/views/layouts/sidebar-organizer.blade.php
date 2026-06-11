<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} - Coordinator</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        #coord-bell-btn {
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
            align-self: flex-end;
            margin-bottom: 10px;
        }
        #coord-bell-btn:hover,
        #coord-bell-btn:focus,
        #coord-bell-btn:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .bell-badge { pointer-events: none; }
        .notif-item { cursor: pointer; position: relative; }
        .notif-hover-label {
            pointer-events: none;
            position: fixed;
            background: rgba(0,0,0,0.82);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 4px 10px;
            border-radius: 20px;
            opacity: 0;
            transition: opacity 0.15s ease;
            white-space: nowrap;
            z-index: 99999;
        }
        .notif-item:hover .notif-hover-label { opacity: 1; }

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
    </style>

    <script>
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
    //  STORE FACTORY
    //  Mirrors alumni sidebar exactly:
    //  - 3s poll (same as chat Livewire poll)
    //  - _saveCoordNotif does immediate fetch after DB write (no delay)
    //  - coord-notif-refresh just re-fetches (no DB write, no wait)
    //  - coord-message-received saves to DB then immediately re-fetches
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

                        var isEmpEvent = (
                            rawDedup.startsWith('employment_update') ||
                            rawDedup.startsWith('employment::')      ||
                            rawDedup.startsWith('recorded::')        ||
                            rawDedup.startsWith('updated::')         ||
                            n.title === 'Employment Status Updated'  ||
                            n.title === 'New Employment Record'      ||
                            n.icon  === 'chart-line'
                        );
                        var isMsgEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon  === 'comments'
                        );
                        var isJobEvent = (
                            rawDedup.startsWith('job-posted::')     ||
                            rawDedup.startsWith('job-management::') ||
                            n.icon  === 'briefcase'
                        );
                        var isCalEvent = (
                            rawDedup.startsWith('event-announced::')   ||
                            rawDedup.startsWith('event-management::')  ||
                            n.icon  === 'calendar-check' ||
                            n.icon  === 'calendar'
                        );
                        var isAlumniEvent = (
                            rawDedup.startsWith('alumni-registered::') ||
                            rawDedup.startsWith('profile-updated::')   ||
                            n.icon  === 'user-group' ||
                            n.icon  === 'user-plus'
                        );

                        var groupKey;
                        if (isEmpEvent)         { groupKey = 'employment_day::' + day; }
                        else if (isMsgEvent)    { groupKey = 'message_day::' + day; }
                        else if (isJobEvent)    { groupKey = 'job_day::' + day; }
                        else if (isCalEvent)    { groupKey = 'calendar_day::' + day; }
                        else if (isAlumniEvent) { groupKey = 'alumni_day::' + day; }
                        else { groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id); }

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);

                            if (isEmpEvent)         { g.message = g.count + ' employment update(s) today.';      g.title = 'Employment Status Updated'; }
                            else if (isMsgEvent)    { g.message = g.count + ' new message(s) today.';            g.title = g.count + ' New Messages'; }
                            else if (isJobEvent)    { g.message = g.count + ' new job posting(s) today.';        g.title = 'New Job Postings'; }
                            else if (isCalEvent)    { g.message = g.count + ' new event(s) updated today.';      g.title = 'Event Management Update'; }
                            else if (isAlumniEvent) { g.message = g.count + ' alumni profile update(s) today.';  g.title = 'Alumni Updates'; }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: isEmpEvent    ? 'Employment Status Updated'
                                     : isMsgEvent    ? (n.title || 'New Message')
                                     : isJobEvent    ? (n.title || 'New Job Posting')
                                     : isCalEvent    ? (n.title || 'Event Management Update')
                                     : isAlumniEvent ? (n.title || 'Alumni Update')
                                     : n.title,
                                icon:  isEmpEvent    ? 'chart-line'
                                     : isMsgEvent    ? 'comments'
                                     : isJobEvent    ? 'briefcase'
                                     : isCalEvent    ? 'calendar-check'
                                     : isAlumniEvent ? 'user-group'
                                     : (n.icon || 'bell'),
                            }));
                        }
                    });
                return Array.from(map.values());
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
    //  PANEL POSITIONING
    // ─────────────────────────────────────────────────────────────────────────
    function positionCoordPanel() {
        var btn   = document.getElementById('coord-bell-btn');
        var panel = document.getElementById('coord-notif-panel');
        var aside = document.querySelector('aside');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        if (aside && window.innerWidth >= 1024) {
            var asideRect = aside.getBoundingClientRect();
            panel.style.left  = (asideRect.right + 12) + 'px';
            panel.style.top   = (btnRect.bottom  + 8)  + 'px';
            panel.style.width = '400px';
        } else {
            panel.style.left  = '8px';
            panel.style.top   = (btnRect.bottom + 8) + 'px';
            panel.style.width = (window.innerWidth - 16) + 'px';
        }
    }
    window.positionCoordPanel = positionCoordPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeCoordNotifsStore();
        if (s && s.open) positionCoordPanel();
    });

    // Cursor-following tooltip
    document.addEventListener('mousemove', function (e) {
        var target = e.target;
        if (!target || typeof target.closest !== 'function') return;
        var item = target.closest('.notif-item');
        if (!item) return;
        var label = item.querySelector('.notif-hover-label');
        if (!label) return;
        label.style.left = (e.clientX + 14) + 'px';
        label.style.top  = (e.clientY + 14) + 'px';
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SIDEBAR SMART MARK-READ
    //  Clicking a sidebar link marks only that route's notifs as read.
    //  Does NOT clear red dots on chat rooms — those only clear on selectRoom().
    // ─────────────────────────────────────────────────────────────────────────
    window.__coordSidebarNotifsMarkRead = function (routeName) {
        var s = window.__safeCoordNotifsStore();
        if (!s) return;
        s.markReadByRoute(routeName);
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIFICATION EVENT LISTENERS
    //
    //  KEY DIFFERENCE vs old version — mirrors alumni pattern exactly:
    //
    //  coord-message-received:
    //    The Livewire PHP component now writes the coordinator notification
    //    DIRECTLY to the DB (coordinator_notifications table) server-side
    //    inside checkAndDispatchNewMessageNotifications(). It then dispatches
    //    'coord-notif-refresh' — which just tells the JS store to re-fetch.
    //    No JS-side DB write needed for chat messages.
    //    The _saveCoordNotif() function below is kept only for non-chat events
    //    (job updates, employment, events) that still come from JS dispatches.
    //
    //  coord-notif-refresh:
    //    Just re-fetches the store. Same as alumni-notif-refresh.
    //    Bell badge updates immediately without any extra DB round-trip.
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstCoordNotifListeners) {
        window.__philcstCoordNotifListeners = true;

        function _coordDetail(e) {
            var d = e.detail;
            if (!d) return {};
            if (!Array.isArray(d)) return d;
            return d[0] || {};
        }

        // Used only for non-chat events (job, employment, event updates)
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
                // Mirror alumni: small wait then fetch
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeCoordNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeCoordNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        window.addEventListener('event-management-updated', function (e) {
            var d = _coordDetail(e);
            _saveCoordNotif({
                icon:       'calendar-check',
                title:      'Event Management Update',
                message:    (d.title || 'An event') + ' has been updated.',
                link_route: 'organizer.event/organizer',
                link_label: 'View Events',
                dedup_key:  'event-management::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        window.addEventListener('job-management-updated', function (e) {
            var d = _coordDetail(e);
            _saveCoordNotif({
                icon:       'briefcase',
                title:      'Job Management Update',
                message:    (d.title || 'A job posting') + ' has been updated.',
                link_route: 'organizer.job/management',
                link_label: 'View Jobs',
                dedup_key:  'job-management::' + (d.id || Math.floor(Date.now() / 60000)),
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

        // ── CHAT MESSAGE NOTIFS ──────────────────────────────────────────────
        // The PHP component writes to DB directly (same as alumni notifyAlumniInRoom).
        // This JS listener just re-fetches the store so bell updates instantly.
        // No DB write here — avoids double-write and JS-side delay.
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

        // ── FALLBACK ONLY ────────────────────────────────────────────────────
        // coord-message-received is now only dispatched as a fallback if the
        // PHP DB write fails. In normal operation, coord-notif-refresh fires
        // instead. This keeps backward compat if something goes wrong server-side.
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
                title:      count > 1 ? count + ' New Messages' : 'New Message',
                message:    msgText,
                link_route: 'organizer.chat/alumni',
                link_label: 'Open Messages',
                dedup_key:  'message-received::' + room + '::' + Math.floor(Date.now() / 60000),
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
    x-data="{ open: false }"
    @click="$store.coordNotifs && $store.coordNotifs.open && $store.coordNotifs.close()">

<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

    {{-- Mobile overlay --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-40 bg-black/50 lg:hidden">
    </div>

    {{-- ══ SIDEBAR ══ --}}
    <aside
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0;">

        {{-- Sidebar header --}}
        <div class="flex items-center justify-between h-24 px-5 border-b border-[#E8E0F0] shrink-0">

            <div class="text-left min-w-0 flex-1 pr-2">
                <h1 class="text-2xl font-semibold tracking-tighter uppercase text-[#333333] leading-tight">
                    Coordinator<span class="font-semibold opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-semibold">
                    Event Management
                </p>
            </div>

            {{-- Bell Button --}}
            <button
                id="coord-bell-btn"
                type="button"
                @click.stop="$store.coordNotifs && $store.coordNotifs.toggle(); positionCoordPanel();"
                title="Notifications"
                aria-label="Open notifications">

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

            {{-- Mobile close --}}
            <button @click="open = false"
                    class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors ml-2 shrink-0">
                <i class="fa-solid fa-circle-xmark text-xl"></i>
            </button>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">

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
                   @click="window.__coordSidebarNotifsMarkRead('{{ $link['route'] }}')"
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group
                          {{ $isActive
                              ? 'bg-[#F5F5F5] border border-[#E8E0F0] shadow-md'
                              : 'hover:bg-[#F9F7FC]' }}">

                    <div class="w-10 h-10 flex items-center justify-center rounded-lg
                                transition-transform duration-300 group-hover:scale-110 shrink-0 mr-4"
                         style="background-color:{{ $isActive ? '#EDE9F8' : '#F9F7FC' }};color:#7A3F91;">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="font-medium tracking-wide flex-1
                                 {{ $isActive ? 'text-[#7A3F91] font-semibold' : 'text-[#333333]' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="ml-auto w-1.5 h-5 rounded-full bg-[#7A3F91] opacity-70 shrink-0"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-xs
                               transition-all flex items-center justify-center shadow-lg active:scale-95 hover:brightness-110"
                        style="background:linear-gradient(135deg,#7A3F91,#6a3080);">
                    <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- ══ MAIN CONTENT ══ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-[#E8E0F0]
                       lg:hidden shrink-0 z-30">
            <button @click.stop="open = !open"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#333333]">Coordinator Portal</h2>
            <div class="w-10"></div>
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
     COORDINATOR NOTIFICATION PANEL
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
         style="background:linear-gradient(135deg,#7A3F91,#5A2D70);">
        <div class="flex items-center gap-2.5">
            <i class="fas fa-bell text-white" style="font-size:15px;"></i>
            <span class="text-white font-bold" style="font-size:16px;">Notifications</span>
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

    {{-- Scrollable notification list --}}
    <div class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">

        <template x-if="$store.coordNotifs && $store.coordNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F5F5F5;">
                    <i class="fas fa-bell-slash" style="font-size:28px;color:#D1D5DB;"></i>
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
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#F8F5FD] hover:bg-[#F0E9FA]'"
                    @click.stop="
                        $store.coordNotifs.markRead(notif);
                        $store.coordNotifs.close();
                        if (notif.link_route) {
                            const url = window.__coordRouteMap[notif.link_route] || '/organizer/dashboard';
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

                    {{-- Icon --}}
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                         style="background:linear-gradient(135deg,#EDE9F8,#DDD5F0);">
                        <i class="fas text-[#7A3F91]"
                           :class="'fa-' + (notif.icon || 'bell')"
                           style="font-size:15px;"></i>
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

                                {{-- Job badge --}}
                                <span
                                    x-show="notif.icon === 'briefcase' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#7A3F91,#5A2D70);">
                                    JOB
                                </span>

                                {{-- Event badge --}}
                                <span
                                    x-show="(notif.icon === 'calendar-check' || notif.icon === 'calendar') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    EVENT
                                </span>

                                {{-- Employment badge --}}
                                <span
                                    x-show="notif.icon === 'chart-line' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    EMPLOYMENT
                                </span>

                                {{-- Message badge --}}
                                <span
                                    x-show="notif.icon === 'comments' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#7A3F91,#5A2D70);">
                                    MSG
                                </span>

                                {{-- Alumni badge --}}
                                <span
                                    x-show="(notif.icon === 'user-group' || notif.icon === 'user-plus') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#d97706,#b45309);">
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

                    <span class="notif-hover-label">
                        <i class="fas fa-eye" style="font-size:10px;margin-right:5px;"></i>View Details
                    </span>
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

{{-- CLOSE ON OUTSIDE CLICK --}}
<div
    x-show="$store.coordNotifs && $store.coordNotifs.open"
    x-cloak
    @click="$store.coordNotifs && $store.coordNotifs.close()"
    class="fixed inset-0"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>