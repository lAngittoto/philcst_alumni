<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>{{ config('app.name', 'Philcst') }} - Alumni</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* ── Sidebar (desktop) bell — old design ── */
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

        /* ── Mobile top-bar bell (icon only) — kept from current/new version, untouched behavior ── */
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

        /* ── Close button tooltip ── */
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

        /* ════════════════════════════════════════════════════════
           ALUMNI SIDEBAR — GRADUATE / ALUMNI THEME (OLD / RESTORED)
        ════════════════════════════════════════════════════════ */
        .alm-sidebar-header {
            background: #7A3F91;
            position: relative;
            overflow: hidden;
        }
        .alm-sidebar-header::after {
            content: '';
            position: absolute;
            top: -40px; right: -30px;
            width: 130px; height: 130px;
            display: none;
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
        .alm-nav-icon { transition: transform 0.2s ease; }

        .alm-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #9A8AA8;
            padding: 0 1rem;
            margin-bottom: 0.5rem;
        }

        /* Logout button + spinner */
        .alm-logout-btn {
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
            color: #fff;
            background: #7A3F91;
            border: none;
            cursor: pointer;
            overflow: hidden;
            box-shadow: 0 8px 18px -6px rgba(122,63,145,0.55);
            transition: filter 0.2s ease, transform 0.15s ease;
        }
        .alm-logout-btn:hover   { filter: brightness(1.1); }
        .alm-logout-btn:active  { transform: scale(0.97); }
        .alm-logout-btn:disabled {
            cursor: not-allowed;
            filter: grayscale(0.15) brightness(0.95);
        }
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
        }

        /* Mobile topbar — icon mark + icon-only bell, no "Alumni Portal" text clutter */
        .alm-mobile-topbar-mark {
            display: flex; align-items: center; gap: 8px;
        }
        .alm-mobile-topbar-mark .alm-mark-icon {
            width: 30px; height: 30px;
            border-radius: 9px;
            background: #7A3F91;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 13px;
            flex-shrink: 0;
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATION PANEL — refined
        ════════════════════════════════════════════════════════ */
        #alumni-notif-panel {
            max-width: calc(100vw - 16px);
        }
        @media (max-width: 1023px) {
            #alumni-notif-panel {
                left: 8px !important;
                right: 8px;
                width: auto !important;
                min-height: 0 !important;
                max-height: 80vh;
            }
            #alumni-notif-panel .notif-list-scroll {
                max-height: calc(80vh - 130px) !important;
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
    window.__alumniRouteMap = {
        'alumni.dashboard':   '/alumni/dashboard',
        'alumni.information': '/alumni/information',
        'job.opportunities':  '/job/opportunities',
        'upcoming.events':    '/upcoming/events',
        'alumni.messenger':   '/alumni/messenger',
        'alumni.yearbook':    '/alumni/yearbook',
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeAlumniNotifsStore = function () {
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
                this._pollTimer = setInterval(function () { self._fetch(); }, 10000);
            },

            async _fetch() {
                try {
                    var res = await window.fetch('/alumni/notifications', {
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

                        var isMessageEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon  === 'comments'
                        );
                        var isJobEvent = (
                            rawDedup.startsWith('job-posted::') ||
                            n.icon  === 'briefcase'
                        );
                        var isCalendarEvent = (
                            rawDedup.startsWith('event-announced::') ||
                            n.icon  === 'calendar'
                        );

                        var groupKey;
                        if (isMessageEvent) {
                            groupKey = 'message_day::' + day;
                        } else if (isJobEvent) {
                            groupKey = 'job_day::' + day;
                        } else if (isCalendarEvent) {
                            groupKey = 'calendar_day::' + day;
                        } else {
                            groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id);
                        }

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);

                            if (isMessageEvent) {
                                g.message = g.count + ' new message(s) today.';
                                g.title   = g.count + ' New Messages';
                            } else if (isJobEvent) {
                                g.message = g.count + ' new job posting(s) today.';
                                g.title   = 'New Job Postings';
                            } else if (isCalendarEvent) {
                                g.message = g.count + ' new event(s) announced today.';
                                g.title   = 'New Events Announced';
                            }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: isMessageEvent ? (n.title || 'New Message')
                                     : isJobEvent ? (n.title || 'New Job Posting')
                                     : isCalendarEvent ? (n.title || 'New Event Announced')
                                     : n.title,
                                icon:  isMessageEvent ? 'comments'
                                     : isJobEvent ? 'briefcase'
                                     : isCalendarEvent ? 'calendar'
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
                        await window.fetch('/alumni/notifications/' + ids[i] + '/read', {
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
                    await window.fetch('/alumni/notifications/read-all', {
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
                            await window.fetch('/alumni/notifications/' + ids[j] + '/read', {
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
    window.__safeAlumniNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('alumniNotifs');
                if (s) return s;
            }
        } catch (e) {}
        return null;
    };

    window.__bootAlumniNotifsStore = function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('alumniNotifs')) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
        }
        var s = Alpine.store('alumniNotifs');
        if (s && !s._pollTimer) s.init();
    };

    document.addEventListener('alpine:init', function () {
        Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
    });

    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeAlumniNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    window.addEventListener('load', function () {
        var s = window.__safeAlumniNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootAlumniNotifsStore(); }
    });

    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('alumniNotifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open  = false;
                s.init();
            } else {
                Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
                var ns = Alpine.store('alumniNotifs');
                if (ns) ns.init();
            }
        }, 150);
    });

    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('alumniNotifs');
        if (!s) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
            s = Alpine.store('alumniNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeAlumniNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — anchored to the single top-right bell button
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
        } else {
            panel.style.left  = '8px';
            panel.style.top   = (btnRect.bottom + 8) + 'px';
            panel.style.width = (window.innerWidth - 16) + 'px';
        }
    }
    window.positionAlumniPanel = positionAlumniPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeAlumniNotifsStore();
        if (s && s.open) positionAlumniPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  CURSOR-FOLLOWING TOOLTIP
    // ─────────────────────────────────────────────────────────────────────────
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
    // ─────────────────────────────────────────────────────────────────────────
    window.__alumniSidebarNotifsMarkRead = function (routeName) {
        var s = window.__safeAlumniNotifsStore();
        if (!s) return;
        var routesToMark = [routeName];
        routesToMark.forEach(function (r) {
            s.markReadByRoute(r);
        });
    };

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

        window.addEventListener('message-received', function (e) {
            var d = _alumniDetail(e);

            var sender = d.sender || 'Someone';
            var room   = d.room   || 'Group Chat';
            var body   = d.body   || '';
            var count  = Number(d.count) || 1;

            var msgText = count > 1
                ? sender + ' and others sent ' + count + ' new messages in ' + room + '.'
                : sender + ' sent a message in ' + room +
                  (body ? ': "' + body.substring(0, 50) + (body.length > 50 ? '…' : '') + '"' : '.');

            _saveAlumniNotif({
                icon:       'comments',
                title:      count > 1 ? count + ' New Messages' : 'New Message',
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
        loggingOut: false,
        profileComplete: {{ (bool)(auth()->user()?->alumni?->profile_completed ?? false) ? 'true' : 'false' }}
    }"
    x-on:profile-updated.window="profileComplete = $event.detail.completed"
    @click="$store.alumniNotifs && $store.alumniNotifs.open && $store.alumniNotifs.close()">

<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

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

    {{-- ══ SIDEBAR (old / restored design) ══ --}}
    <aside
        id="alumni-sidebar-aside"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300
               lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0;">

        {{-- Sidebar header — graduate-themed (purple, cap badge). Bell now lives in the top-right bar only. --}}
        <div class="alm-sidebar-header flex items-center h-24 px-5 shrink-0">

            <div class="flex items-center gap-3 min-w-0 flex-1 relative z-10">
                <div class="alm-cap-badge">
                    <i class="fa-solid fa-graduation-cap text-white" style="font-size:17px;"></i>
                </div>
                <div class="min-w-0">
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
            <p class="alm-section-label">MENU</p>

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
                        'label'   => 'Job Board',
                        'pattern' => 'job/opportunities*',
                    ],
                    [
                        'route'   => 'upcoming.events',
                        'icon'    => 'calendar',
                        'label'   => 'Events',
                        'pattern' => 'upcoming/events*',
                    ],
                    [
                        'route'   => 'alumni.messenger',
                        'icon'    => 'comments',
                        'label'   => 'Messages',
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
                   @click="window.__alumniSidebarNotifsMarkRead('{{ $link['route'] }}'); open = false;"
                   class="alm-nav-link {{ $isActive ? 'is-active' : '' }}
                          flex items-center px-4 py-3 rounded-xl group">

                    <div class="alm-nav-icon w-10 h-10 flex items-center justify-center rounded-lg shrink-0 mr-3.5"
                         style="background-color:{{ $isActive ? '#FFFFFF' : '#F9F7FC' }};color:#7A3F91;
                                box-shadow:{{ $isActive ? '0 2px 6px rgba(122,63,145,0.18)' : 'none' }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="font-medium tracking-wide flex-1 text-[14px]
                                 {{ $isActive ? 'text-[#5A2D70] font-bold' : 'text-[#3A3A3A]' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="ml-auto w-1.5 h-6 rounded-full shrink-0"
                              style="background:#7A3F91;"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST"
                  action="{{ route('logout') }}"
                  @submit="loggingOut = true">
                @csrf
                <button type="submit"
                        :disabled="loggingOut"
                        class="alm-logout-btn">
                    <template x-if="!loggingOut">
                        <span class="alm-logout-text-swap">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                        </span>
                    </template>
                    <template x-if="loggingOut">
                        <span class="alm-logout-text-swap">
                            <span class="alm-logout-spinner mr-2"></span> Logging out…
                        </span>
                    </template>
                </button>
            </form>
        </div>
    </aside>

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
                   :class="$store.alumniNotifs && $store.alumniNotifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>
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
        <div class="flex-1 overflow-y-auto no-scrollbar bg-[#F5F5F5] p-4 lg:p-8">
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
    x-effect="if ($store.alumniNotifs && $store.alumniNotifs.open) $nextTick(() => positionAlumniPanel())"
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
                    class="text-white/70 hover:text-white font-semibold hover:bg-white/10
                           rounded-lg px-2.5 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>

            <div class="notif-close-wrap ml-1">
                <span class="notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.alumniNotifs && $store.alumniNotifs.close()"
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
            <template x-for="notif in $store.alumniNotifs.items" :key="notif.id">
                <div
                    class="notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#FAF6FE] hover:bg-[#F3EBFA]'"
                    @click.stop="
                        $store.alumniNotifs.markRead(notif);
                        $store.alumniNotifs.close();
                        if (notif.link_route) {
                            const url = window.__alumniRouteMap[notif.link_route] || '/alumni/dashboard';
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                         style="background:#F3EBFA;">
                        <i class="fas text-[#7A3F91]"
                           :class="'fa-' + (notif.icon || 'bell')"
                           style="font-size:15px;"></i>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <p :class="notif.read ? 'font-semibold text-[#555555]' : 'font-bold text-[#1a1a1a]'"
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
                                    x-show="notif.icon === 'briefcase' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#7A3F91;">
                                    NEW JOB POSTING
                                </span>

                                <span
                                    x-show="(notif.icon === 'calendar' || notif.icon === 'circle-check') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#059669;">
                                    NEW EVENT
                                </span>

                                <span
                                    x-show="notif.icon === 'comments' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:#7A3F91;">
                                    NEW MESSAGE
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