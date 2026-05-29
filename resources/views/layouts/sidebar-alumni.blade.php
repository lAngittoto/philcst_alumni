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
        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

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
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY — fresh object every call, no shared-reference bugs
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

            // ─── GROUP-BY-DAY (same logic as Registrar) ───────────────────
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

                        // ── Employment events: group all into one per day ──
                        var isEmpEvent = (
                            rawDedup.startsWith('employment_update') ||
                            rawDedup.startsWith('employment::')      ||
                            rawDedup.startsWith('recorded::')        ||
                            rawDedup.startsWith('updated::')         ||
                            n.title === 'Employment Status Updated'  ||
                            n.title === 'New Employment Record'      ||
                            n.icon  === 'chart-line'
                        );

                        // ── Message events: group all messages per day ──
                        var isMsgEvent = (
                            rawDedup.startsWith('message-received::') ||
                            n.icon  === 'comments'
                        );

                        // ── Job events: group all jobs per day ──
                        var isJobEvent = (
                            rawDedup.startsWith('job-posted::') ||
                            n.icon  === 'briefcase'
                        );

                        // ── Event/calendar events: group all events per day ──
                        var isCalEvent = (
                            rawDedup.startsWith('event-announced::') ||
                            n.icon  === 'calendar'
                        );

                        var groupKey;
                        if (isEmpEvent) {
                            groupKey = 'employment_day::' + day;
                        } else if (isMsgEvent) {
                            groupKey = 'message_day::' + day;
                        } else if (isJobEvent) {
                            groupKey = 'job_day::' + day;
                        } else if (isCalEvent) {
                            groupKey = 'calendar_day::' + day;
                        } else {
                            // Everything else: group by title + dedup_key + day
                            groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id);
                        }

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);

                            // Update message based on group type
                            if (isEmpEvent) {
                                g.message = g.count + ' employment update(s) today.';
                                g.title   = 'Employment Status Updated';
                            } else if (isMsgEvent) {
                                g.message = g.count + ' new message(s) today.';
                                g.title   = g.count + ' New Messages';
                            } else if (isJobEvent) {
                                g.message = g.count + ' new job posting(s) today.';
                                g.title   = 'New Job Postings';
                            } else if (isCalEvent) {
                                g.message = g.count + ' new event(s) announced today.';
                                g.title   = 'New Events Announced';
                            }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: isEmpEvent ? 'Employment Status Updated'
                                     : isMsgEvent ? (n.title || 'New Message')
                                     : isJobEvent ? (n.title || 'New Job Posting')
                                     : isCalEvent ? (n.title || 'New Event Announced')
                                     : n.title,
                                icon:  isEmpEvent ? 'chart-line'
                                     : isMsgEvent ? 'comments'
                                     : isJobEvent ? 'briefcase'
                                     : isCalEvent ? 'calendar'
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
    //  INTERNAL BOOT HELPER
    // ─────────────────────────────────────────────────────────────────────────
    window.__bootAlumniNotifsStore = function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('alumniNotifs')) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
        }
        var s = Alpine.store('alumniNotifs');
        if (s && !s._pollTimer) s.init();
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  PATH A — alpine:init
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:init', function () {
        Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PATH B — alpine:initialized
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeAlumniNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PATH C — window load
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('load', function () {
        var s = window.__safeAlumniNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootAlumniNotifsStore(); }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PATH D — livewire:navigated (wire:navigate SPA page swaps)
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('alumniNotifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open  = false;
                s.items = [];
                s.init();
            } else {
                Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
                var ns = Alpine.store('alumniNotifs');
                if (ns) ns.init();
            }
        }, 150);
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PATH E — IIFE IMMEDIATE BOOT
    // ─────────────────────────────────────────────────────────────────────────
    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('alumniNotifs');
        if (!s) {
            Alpine.store('alumniNotifs', window.__makeAlumniNotifsStore());
            s = Alpine.store('alumniNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    // ─────────────────────────────────────────────────────────────────────────
    //  Re-fetch on tab focus
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeAlumniNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING
    // ─────────────────────────────────────────────────────────────────────────
    function positionAlumniPanel() {
        var btn   = document.getElementById('alumni-bell-btn');
        var panel = document.getElementById('alumni-notif-panel');
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

        /* ── Profile Updated ── */
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

        /* ── Event Announced ── */
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

        /* ── Message Received (FALLBACK only) ──────────────────────────────────
           The messenger component now writes message notifications DIRECTLY to
           the DB server-side via checkAndDispatchNewMessageNotifications().
           This listener only fires when the DB write fails (the PHP catch block
           dispatches 'message-received' as a fallback). In normal operation the
           server-side write succeeds and only 'alumni-notif-refresh' is fired.
        ────────────────────────────────────────────────────────────────────── */
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

        /* ── alumni-notif-refresh ───────────────────────────────────────────────
           Fired by the messenger component after it successfully writes a message
           notification to the DB server-side. Just re-fetches the store so the
           bell badge and panel update immediately — no double-write.
        ────────────────────────────────────────────────────────────────────── */
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
                    Alumni<span class="font-semibold opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-semibold">
                    Graduate Network
                </p>
            </div>

            {{-- Bell Button --}}
            <button
                id="alumni-bell-btn"
                type="button"
                @click.stop="$store.alumniNotifs && $store.alumniNotifs.toggle(); positionAlumniPanel();"
                title="Notifications"
                aria-label="Open notifications">

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
                        'route'   => 'alumni.employment',
                        'icon'    => 'chart-line',
                        'label'   => 'Employment',
                        'pattern' => 'alumni/employment*',
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
            <button @click="open = !open"
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
            <h2 class="text-lg font-bold text-[#333333]">Alumni Portal</h2>
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
         style="background:linear-gradient(135deg,#7A3F91,#5A2D70);">
        <div class="flex items-center gap-2.5">
            <i class="fas fa-bell text-white" style="font-size:15px;"></i>
            <span class="text-white font-bold" style="font-size:16px;">Notifications</span>
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

            {{-- ── Close button with tooltip ── --}}
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

    {{-- Scrollable notification list --}}
    <div class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">

        <template x-if="$store.alumniNotifs && $store.alumniNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F5F5F5;">
                    <i class="fas fa-bell-slash" style="font-size:28px;color:#D1D5DB;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    New job postings, events, messages,<br>and updates will appear here.
                </p>
            </div>
        </template>

        {{-- Notification items --}}
        <template x-if="$store.alumniNotifs">
            <template x-for="notif in $store.alumniNotifs.items" :key="notif.id">
                <div
                    class="notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#F8F5FD] hover:bg-[#F0E9FA]'"
                    @click.stop="
                        $store.alumniNotifs.markRead(notif);
                        $store.alumniNotifs.close();
                        if (notif.link_route) {
                            const routeMap = {
                                'alumni.dashboard':   '/alumni/dashboard',
                                'alumni.information': '/alumni/information',
                                'job.opportunities':  '/job/opportunities',
                                'upcoming.events':    '/upcoming/events',
                                'alumni.employment':  '/alumni/employment',
                                'alumni.messenger':   '/alumni/messenger',
                                'alumni.yearbook':    '/alumni/yearbook',
                            };
                            const url = routeMap[notif.link_route] || '/alumni/dashboard';
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
                                    NEW JOB
                                </span>

                                {{-- Event badge --}}
                                <span
                                    x-show="(notif.icon === 'calendar' || notif.icon === 'circle-check') && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    NEW EVENT
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
                                    NEW MSG
                                </span>
                            </div>

                            <span x-show="!notif.read" x-cloak
                                  class="w-2 h-2 rounded-full bg-red-500 shrink-0 shadow-sm mt-1 flex-shrink-0"></span>
                        </div>

                        {{-- Message --}}
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