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
        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        #bell-btn {
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
        #bell-btn:hover,
        #bell-btn:focus,
        #bell-btn:active {
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
    window.__makeNotifsStore = function () {
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
                this._pollTimer = setInterval(function () { self._fetch(); }, 5000);
            },
            async _fetch() {
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
                    .sort(function (a, b) {
                        return new Date(b.created_at) - new Date(a.created_at);
                    })
                    .forEach(function (n) {
                        var day = n.created_at
                            ? new Date(n.created_at).toISOString().slice(0, 10)
                            : 'unknown';
                        var rawDedup = n.dedup_key || '';
                        var isEmpEvent = (
                            rawDedup.startsWith('employment::') ||
                            rawDedup.startsWith('recorded::')   ||
                            rawDedup.startsWith('updated::')    ||
                            n.title === 'Employment Status Updated' ||
                            n.title === 'New Employment Record'
                        );
                        var groupKey = isEmpEvent
                            ? 'employment_day::' + day
                            : (n.title || '') + '::' + day + '::' + (rawDedup || n.id);
                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);
                            g.message = g.count + ' employment status update(s) today.';
                            g.title   = 'Employment Status Updated';
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title:   isEmpEvent ? 'Employment Status Updated' : n.title,
                                icon:    isEmpEvent ? 'arrow-rotate-right'        : (n.icon || 'bell'),
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
            },
            async markAllRead() {
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
    // ─────────────────────────────────────────────────────────────────────────
    //  INTERNAL BOOT HELPER
    // ─────────────────────────────────────────────────────────────────────────
    window.__bootNotifsStore = function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('notifs')) {
            Alpine.store('notifs', window.__makeNotifsStore());
        }
        var s = Alpine.store('notifs');
        if (!s) return;
        if (!s._pollTimer) {
            s.init();
        }
    };
    // ─────────────────────────────────────────────────────────────────────────
    //  PATH A — alpine:init
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:init', function () {
        Alpine.store('notifs', window.__makeNotifsStore());
    });
    // ─────────────────────────────────────────────────────────────────────────
    //  PATH B — alpine:initialized
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });
    // ─────────────────────────────────────────────────────────────────────────
    //  PATH C — window load
    // ─────────────────────────────────────────────────────────────────────────
    window.addEventListener('load', function () {
        var s = window.__safeNotifsStore();
        if (s) {
            if (s.items.length === 0) s.init();
        } else {
            window.__bootNotifsStore();
        }
    });
    // ─────────────────────────────────────────────────────────────────────────
    //  PATH D — livewire:navigated (wire:navigate SPA page swaps)
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('notifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open  = false;
                s.items = [];
                s.init();
            } else {
                Alpine.store('notifs', window.__makeNotifsStore());
                var newStore = Alpine.store('notifs');
                if (newStore) newStore.init();
            }
        }, 150);
    });
    // ─────────────────────────────────────────────────────────────────────────
    //  PATH E — IIFE IMMEDIATE BOOT
    // ─────────────────────────────────────────────────────────────────────────
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
    // ─────────────────────────────────────────────────────────────────────────
    //  Re-fetch on tab focus
    // ─────────────────────────────────────────────────────────────────────────
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
    window.positionPanel = positionPanel;
    window.addEventListener('resize', function () {
        var s = window.__safeNotifsStore();
        if (s && s.open) positionPanel();
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
    //  LIVEWIRE → DB BRIDGE
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstNotifListeners) {
        window.__philcstNotifListeners = true;
        function _detail(e) {
            var d = e.detail;
            if (!d) return {};
            return Array.isArray(d) ? (d[0] || {}) : d;
        }
        function _statusLabel(raw) {
            var map = { employed: 'Employed', self_employed: 'Self-Employed', unemployed: 'Unemployed' };
            return map[raw] || (raw ? raw.replace(/_/g, ' ') : 'N/A');
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
                icon:       'user-graduate',
                title:      'New Alumni Registered',
                message:    (d.name || 'Alumni') + ' (ID: ' + (d.id || '—') + ') has been registered and is now VERIFIED.',
                link_route: 'registrar.alumni',
                link_label: 'View Alumni',
                dedup_key:  'registered',
            });
        });
        window.addEventListener('alumni-imported', function (e) {
            var d = _detail(e);
            _saveNotif({
                icon:       'file-import',
                title:      'Bulk Import Complete',
                message:    (d.count || 0) + ' alumni record(s) imported successfully via CSV/Excel.',
                link_route: 'registrar.alumni',
                link_label: 'View Alumni',
                dedup_key:  'imported',
            });
        });
        window.addEventListener('employment-recorded', function (e) {
            var d      = _detail(e);
            var status = d.status || 'unknown';
            _saveNotif({
                icon:       'arrow-rotate-right',
                title:      'Employment Status Updated',
                message:    (d.name || 'An alumni') + ' submitted their employment record as ' + _statusLabel(status) + '.',
                link_route: 'registrar.employment.tracking',
                link_label: 'View Tracking',
                dedup_key:  'employment_update',
            });
        });
        window.addEventListener('employment-updated', function (e) {
            var d      = _detail(e);
            var status = d.status || 'unknown';
            var from   = d.old_status ? ' from ' + _statusLabel(d.old_status) : '';
            _saveNotif({
                icon:       'arrow-rotate-right',
                title:      'Employment Status Updated',
                message:    (d.name || 'An alumni') + ' updated their employment status' + from + ' to ' + _statusLabel(status) + '.',
                link_route: 'registrar.employment.tracking',
                link_label: 'View Tracking',
                dedup_key:  'employment_update',
            });
        });
    }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- ✅ TANGGAL NA ang @click close dito sa body --}}
<body class="antialiased" x-data="{ sidebarOpen: false }">
<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">
    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen"
         x-transition:enter="transition opacity-ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition opacity-ease-in duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden">
    </div>
    {{-- ══ SIDEBAR ══════════════════════════════════════════════════════════ --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform
                  transition-transform duration-300 shadow-2xl
                  lg:translate-x-0 lg:static lg:inset-0
                  flex flex-col h-full text-[#333333] shrink-0"
           style="background:#FFFFFF; border-right:1px solid #E8E0F0;">
        {{-- Sidebar Header --}}
        <div class="flex items-center justify-between h-24 px-5 border-b border-[#E8E0F0] shrink-0">
            {{-- Branding --}}
            <div class="text-left min-w-0 flex-1 pr-2">
                <h1 class="text-2xl font-semibold tracking-tighter uppercase text-[#333333] leading-tight">
                    Registrar<span class="font-semibold opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-semibold">
                    Records Management
                </p>
            </div>
            {{-- Bell Button --}}
            <button
                id="bell-btn"
                type="button"
                @click.stop="$store.notifs && $store.notifs.toggle(); positionPanel();"
                title="Notifications"
                aria-label="Open notifications">
                <i class="bell-icon fas fa-bell"
                   :class="$store.notifs && $store.notifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91;
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
            {{-- Mobile close --}}
            <button @click="sidebarOpen = false"
                    class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors ml-2 shrink-0">
                <i class="fa-solid fa-circle-xmark text-xl"></i>
            </button>
        </div>
        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">
            @php
                $sidebarLinks = [
                    ['route' => 'registrar.dashboard',           'icon' => 'gauge-high', 'label' => 'Dashboard'],
                    ['route' => 'registrar.alumni',              'icon' => 'users',       'label' => 'Alumni Records'],
                    ['route' => 'registrar.alumni.register',     'icon' => 'user-plus',   'label' => 'Register Alumni'],
                    ['route' => 'registrar.employment.tracking', 'icon' => 'chart-line',  'label' => 'Employment Tracking'],
                ];
            @endphp
            @foreach($sidebarLinks as $link)
                @php $isActive = request()->routeIs($link['route']); @endphp
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
                    <span class="font-medium tracking-wide
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
    {{-- ══ MAIN CONTENT ═════════════════════════════════════════════════════ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">
        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-[#E8E0F0]
                       lg:hidden shrink-0 z-30">
            <button @click="sidebarOpen = !sidebarOpen"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="sidebarOpen ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    <span :class="sidebarOpen ? 'opacity-0' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                    <span :class="sidebarOpen ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#333333]">Registrar Portal</h2>
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
     NOTIFICATION PANEL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="notif-panel"
    x-show="$store.notifs && $store.notifs.open"
    x-cloak
    x-effect="if ($store.notifs && $store.notifs.open) $nextTick(() => positionPanel())"
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
            <span x-show="$store.notifs && $store.notifs.unread > 0"
                  x-cloak
                  class="bg-red-500 text-white font-black px-2 py-0.5 rounded-full leading-none"
                  style="font-size:11px;"
                  x-text="$store.notifs ? $store.notifs.unread + ' new' : ''">
            </span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button"
                    x-show="$store.notifs && $store.notifs.unread > 0"
                    x-cloak
                    @click.stop="$store.notifs && $store.notifs.markAllRead()"
                    class="text-white/70 hover:text-white font-semibold hover:bg-white/10
                           rounded-lg px-2.5 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>

            {{-- ── Close button with tooltip ── --}}
            <div class="notif-close-wrap ml-1">
                <span class="notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.notifs && $store.notifs.close()"
                        class="w-7 h-7 flex items-center justify-center rounded-lg
                               text-white/50 hover:text-white hover:bg-white/10 transition">
                    <i class="fas fa-xmark" style="font-size:14px;"></i>
                </button>
            </div>
        </div>
    </div>
    {{-- Scrollable notification list --}}
    <div class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">
        <template x-if="$store.notifs && $store.notifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F5F5F5;">
                    <i class="fas fa-bell-slash" style="font-size:28px;color:#D1D5DB;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    Alumni registrations, imports, and<br>employment updates will appear here.
                </p>
            </div>
        </template>
        {{-- Notification items --}}
        <template x-if="$store.notifs">
            <template x-for="notif in $store.notifs.items" :key="notif.id">
                <div
                    class="notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#F8F5FD] hover:bg-[#F0E9FA]'"
                    @click.stop="
                        $store.notifs.markRead(notif);
                        $store.notifs.close();
                        if (notif.link_route) {
                            const routeMap = {
                                'registrar.alumni':              '/registrar/alumni',
                                'registrar.dashboard':           '/registrar/dashboard',
                                'registrar.employment.tracking': '/registrar/employment-tracking',
                            };
                            const url = routeMap[notif.link_route] || '/registrar/alumni';
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
                                    x-show="notif.count > 1"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#7A3F91;"
                                    x-text="'×' + notif.count">
                                </span>
                            </div>
                            <span x-show="!notif.read" x-cloak
                                  class="w-2 h-2 rounded-full bg-red-500 shrink-0 shadow-sm mt-1"></span>
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
{{-- ✅ CLOSE ON OUTSIDE CLICK — narito na sa baba, after ng lahat ng elements --}}
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