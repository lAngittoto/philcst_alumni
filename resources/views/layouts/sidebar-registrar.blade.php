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

        .bell-badge { pointer-events: none; }

        /* ════════════════════════════════════════════════════════
           LIVEWIRE LOADING BAR (blue, top of viewport)
           FIX: this is now a STATIC element that is the very first
           child of <body> (see markup below) instead of an element
           that JS creates/moves around at runtime. wire:navigate
           morphs the DOM in place on every navigation, and an
           element that only exists because JS injected it could get
           dropped or displaced mid-morph — that was the cause of the
           bar looking "putol" (cut off / disappearing) inside the
           registrar layout. A static element that's always present
           in the template can't be lost that way; JS below only
           toggles its width/opacity, never creates or moves it.
           position:fixed + inset + a very high z-index also means it
           always draws across the FULL viewport width, above the
           sidebar, never clipped by it.
        ════════════════════════════════════════════════════════ */
        #lw-loading-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            width: 0%;
            height: 3px;
            background: #2563EB;
            box-shadow: 0 0 8px rgba(37,99,235,0.6);
            z-index: 2147483647;
            opacity: 0;
            pointer-events: none;
        }

        /* ════════════════════════════════════════════════════════
           SIDEBAR
        ════════════════════════════════════════════════════════ */
        .reg-sidebar {
            background: #FFFFFF;
            border-right: 1px solid #E5E5E5;
            transition:
                width 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                min-width 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.25s ease,
                border-color 0.25s ease;
        }
        .reg-hamburger-line { background: #7A3F91; }

        .reg-nav-link {
            position: relative;
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .reg-nav-link:not(.is-active):hover { background: #F5F5F5; }
        .reg-nav-link:not(.is-active):hover .reg-nav-icon { transform: scale(1.05); }
        .reg-nav-link.is-active { background: #7A3F91; }

        .reg-nav-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 0.9rem;
            transition: transform 0.2s ease;
            background: #F0E9F6;
            color: #7A3F91;
        }
        .reg-nav-link.is-active .reg-nav-icon { background: #FFFFFF; color: #7A3F91; }

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

        /* ── MENU label row + inline collapse icon-button ─────────
           The label and the collapse toggle sit on the SAME row,
           label on the left, tiny icon-only button on the right. ── */
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

        /* Icon-only collapse toggle — sits right next to "MENU" */
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
            transition: background-color 0.15s ease, transform 0.2s ease;
        }
        .reg-collapse-icon-btn:hover { background: #E4D3F0; }
        .reg-collapse-icon-btn:active { transform: scale(0.88); }

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

        /* Top bar bell (single source of truth, replaces old sidebar bell) */
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

            /* ── Modal-open state: sidebar fully closes (not just collapses),
                 so it never covers/overlaps modal content on desktop.
                 FIX: transition is disabled here so the sidebar disappears
                 instantly instead of sliding/animating away, which used to
                 visually compete with the modal's own pop-in animation. ── */
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

            /* ── Icon-only nav on mobile: hide labels, section titles, active dots ── */
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

            /* ── Icon-only logout button on mobile ── */
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
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATIONS PANEL — item layout + colored icon chips
        ════════════════════════════════════════════════════════ */
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            cursor: pointer;
            border-bottom: 1px solid #F3EEF8;
            transition: background .12s ease;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #FAF7FC; }
        .notif-item.is-read { background: #FCFBFD; }

        .notif-icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
        }
        .notif-icon-alumni  { background: #EDE9FE; color: #7C3AED; }
        .notif-icon-import  { background: #DBEAFE; color: #2563EB; }
        .notif-icon-chat    { background: #DCFCE7; color: #16A34A; }
        .notif-icon-default { background: #F3E8FF; color: #7A3F91; }

        .notif-body { flex: 1; min-width: 0; }

        .notif-title-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .notif-title-text {
            font-size: .85rem;
            font-weight: 700;
            color: #1a1a1a;
        }
        .notif-title-text.is-read { font-weight: 600; color: #666666; }

        .notif-tag {
            font-size: .6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .notif-tag-alumni { background: #EDE9FE; color: #7C3AED; }
        .notif-tag-import { background: #DBEAFE; color: #2563EB; }
        .notif-tag-chat   { background: #DCFCE7; color: #16A34A; }

        .notif-count-badge {
            font-size: .65rem;
            font-weight: 800;
            background: #7A3F91;
            color: #fff;
            padding: 1px 6px;
            border-radius: 999px;
        }

        .notif-unread-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #7A3F91;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .notif-message-text {
            font-size: .8rem;
            color: #555555;
            line-height: 1.45;
            margin-top: 2px;
        }

        .notif-time-row {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 6px;
            font-size: .68rem;
            color: #aaaaaa;
            font-weight: 500;
        }
        .notif-time-row i { font-size: .62rem; }

        /* ── Read/Unread section divider ─────────────────────────── */
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
            color: #B9A6C7;
            white-space: nowrap;
        }

        /* ── Mobile: notification panel goes true full-screen ────── */
        @media (max-width: 1023px) {
            #notif-panel {
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                min-height: 100% !important;
                max-height: 100% !important;
                border-radius: 0 !important;
                border: none !important;
            }
        }
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  ROUTE MAP
    // ─────────────────────────────────────────────────────────────────────────
    window.__registrarRouteMap = {
        'registrar.alumni':              '/registrar/alumni',
        'registrar.dashboard':           '/registrar/dashboard',
        'registrar.employment.tracking': '/registrar/employment/tracking',
        'registrar.alumni.register':     '/registrar/alumni/register',
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
    //  STORE FACTORY
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
                        var nTimestamp = n.updated_at && new Date(n.updated_at) > new Date(n.created_at)
                            ? n.updated_at
                            : n.created_at;
                        var day = nTimestamp
                            ? new Date(nTimestamp).toISOString().slice(0, 10)
                            : 'unknown';

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

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (Number(g.count) || 1) + (Number(n.count) || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);
                            if (nTimestamp && new Date(nTimestamp) > new Date(g.created_at)) {
                                g.created_at = nTimestamp;
                            }
                            if (isChatMsg) {
                                var rName = g._roomName || 'group chat';
                                g.message = g.count + ' new message(s) in ' + rName + '.';
                            } else if (isAlumniEvent) {
                                g.message = g.count + ' new alumni registered today.';
                                g.title   = 'New Alumni Registered';
                            } else if (isImportEvent) {
                                g.message = g.count + ' alumni record(s) imported today.';
                                g.title   = 'Bulk Import Complete';
                            }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count:      Number(n.count) || 1,
                                _ids:       [n.id],
                                _roomName:  n._roomName || '',
                                created_at: nTimestamp || n.created_at,
                                title: isChatMsg      ? (n.title || 'New Chat Message')
                                     : isAlumniEvent  ? 'New Alumni Registered'
                                     : isImportEvent  ? 'Bulk Import Complete'
                                     : n.title,
                                icon: isChatMsg      ? 'comment-dots'
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
                            await window.fetch('/registrar/notifications/' + ids[j] + '/read', {
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
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;
            var s = Alpine.store('notifs');
            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open  = false;
                s.init();
            } else {
                Alpine.store('notifs', window.__makeNotifsStore());
                var newStore = Alpine.store('notifs');
                if (newStore) newStore.init();
            }
        }, 150);
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
        // fully owns full-screen layout — no inline overrides needed here.
    }
    window.positionPanel = positionPanel;
    window.addEventListener('resize', function () {
        var s = window.__safeNotifsStore();
        if (s && s.open) positionPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SIDEBAR SMART MARK-READ
    // ─────────────────────────────────────────────────────────────────────────
    window.__sidebarNotifsMarkRead = function (routeName) {
        var s = window.__safeNotifsStore();
        if (!s) return;
        s.markReadByRoute(routeName);
    };

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
                icon:       'user-graduate',
                title:      'New Alumni Registered',
                message:    (d.name || 'Alumni') + ' (ID: ' + (d.id || '—') + ') has been registered and is now verified.',
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
    //  LIVEWIRE BLUE LOADING BAR (top of viewport, shows during navigate/requests)
    //  FIX: the bar element (#lw-loading-bar) is now a STATIC element that
    //  lives directly in the blade markup as the very first child of <body>
    //  (see below), instead of being created/injected by this script at
    //  runtime. This script now only ever LOOKS UP the existing element by
    //  id and toggles its width/opacity — it never creates it and never
    //  moves it around the DOM. That's what was causing the bar to render
    //  "putol" (broken/cut off) in the registrar layout: the previous
    //  version had a syntax/structural bug in ensureBar() and relied on
    //  dynamically appending the bar to <body> on every run, which could
    //  race with Livewire's wire:navigate DOM morph.
    // ─────────────────────────────────────────────────────────────────────────
    (function () {
        var hideTimer = null;
        var creepTimer = null;

        function ensureBar() {
            return document.getElementById('lw-loading-bar');
        }

        function start() {
            var b = ensureBar();
            if (!b) return;
            clearTimeout(hideTimer);
            clearInterval(creepTimer);
            // Hard reset — don't trust any leftover inline state.
            b.style.transition = 'none';
            b.style.opacity = '1';
            b.style.width = '0%';
            void b.offsetWidth; // force reflow
            b.style.transition = 'width 0.4s ease-out, opacity 0.2s ease';
            b.style.width = '30%';

            // Keep gently creeping forward (never reaching 90%) for as long
            // as the request takes, so the bar is always visibly moving
            // instead of sitting frozen at 75% on slow requests.
            var current = 30;
            creepTimer = setInterval(function () {
                current += (90 - current) * 0.12;
                if (current < 90) b.style.width = current + '%';
            }, 400);
        }

        function finish() {
            var b = ensureBar();
            if (!b) return;
            clearInterval(creepTimer);
            b.style.transition = 'width 0.25s ease';
            b.style.width = '100%';
            hideTimer = setTimeout(function () {
                b.style.transition = 'opacity 0.3s ease';
                b.style.opacity = '0';
                setTimeout(function () {
                    b.style.transition = 'none';
                    b.style.width = '0%';
                }, 300);
            }, 200);
        }

        document.addEventListener('livewire:navigating', start);
        document.addEventListener('livewire:navigated', finish);
        document.addEventListener('livewire:init', function () {
            document.addEventListener('livewire:request', start);
            Livewire.hook('request', function ({ succeed, fail }) {
                succeed(function () { finish(); });
                fail(function () { finish(); });
            });
        });
    })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased"
      x-data="{
          sidebarOpen: false,
          sidebarCollapsed: localStorage.getItem('reg_sidebar_collapsed') === '1',
          loggingOut: false
      }"
      x-init="$watch('sidebarCollapsed', value => localStorage.setItem('reg_sidebar_collapsed', value ? '1' : '0'))">

{{-- Blue Livewire loading bar — STATIC placeholder, always the very first
     element in <body>. Fixed positioning + top:0/left:0/right:0 means it
     always spans the FULL viewport width and sits above the sidebar
     (z-index 2147483647), so it can never be cut off/"putol" by the
     sidebar or by any DOM morph during wire:navigate. The script above
     only toggles this element's width/opacity — it never creates or
     relocates it. --}}
<div id="lw-loading-bar"></div>

<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

    {{-- Mobile overlay — hidden entirely while a modal is open, same as the sidebar --}}
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

        {{-- Sidebar Header --}}
        <div class="flex items-center justify-center lg:justify-start h-24 px-2 lg:px-5 border-b border-[#E5E5E5] shrink-0">
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
                        title="Collapse sidebar"
                        class="reg-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="sidebarCollapsed ? 'fa-angles-right' : 'fa-angles-left'"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                $sidebarLinks = [
                    ['route' => 'registrar.dashboard',           'icon' => 'gauge-high',  'label' => 'Dashboard'],
                    ['route' => 'registrar.alumni',              'icon' => 'users',        'label' => 'Alumni Records'],
                    ['route' => 'registrar.alumni.register',     'icon' => 'user-plus',    'label' => 'Register Alumni'],
                    ['route' => 'registrar.employment.tracking', 'icon' => 'chart-line',   'label' => 'Employment Tracking'],
                ];
            @endphp
            @foreach($sidebarLinks as $link)
                @php $isActive = request()->routeIs($link['route']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="window.__sidebarNotifsMarkRead('{{ $link['route'] }}'); sidebarOpen = false;"
                   class="reg-nav-link {{ $isActive ? 'is-active' : '' }}">
                    <div class="reg-nav-icon">
                        <i class="fa-solid fa-{{ $link['icon'] }}"></i>
                    </div>
                    <span class="reg-nav-label">{{ $link['label'] }}</span>
                    @if($isActive)
                        <span class="reg-nav-dot"></span>
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

            {{-- Notifications bell (single source of truth for the whole layout) --}}
            <button
                id="bell-btn"
                type="button"
                @click.stop="$store.notifs && $store.notifs.toggle(); positionPanel();"
                title="Notifications"
                aria-label="Open notifications"
                class="reg-topbar-bell">
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
    style="
        position: fixed;
        top: 88px;
        left: 12px;
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
                    x-show="$store.notifs && $store.notifs.unread > 0"
                    x-cloak
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
    <div style="background:#F7F4FB; padding:9px 18px; display:flex; align-items:center; justify-content:space-between; border-bottom:0.5px solid #E0D8ED; flex-shrink:0;">
        <span style="font-size:11px; font-weight:600; color:#7A3F91; letter-spacing:0.1em; text-transform:uppercase;">Recent Activity</span>
        <span style="font-size:11px; color:#888888; font-weight:400;"
              x-text="($store.notifs ? $store.notifs.items.length : 0) + ' notification(s)'">
        </span>
    </div>

    {{-- Scrollable list --}}
    <div id="notif-list" class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">

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
                <div>
                    <div class="notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.notifs.items[notifIdx - 1].read"
                         x-cloak>
                        <span class="notif-divider-label">Already Read</span>
                    </div>
                <div
                    class="notif-item"
                    :class="notif.read ? 'is-read' : 'is-unread'"
                    @click.stop="
                        $store.notifs.markRead(notif);
                        $store.notifs.close();
                        if (notif.link_route) {
                            const url = window.__registrarRouteMap[notif.link_route] || '/registrar/alumni';
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

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
                            <i class="fas fa-clock"></i>
                            <span x-text="notif.created_at
                                ? new Date(notif.created_at).toLocaleString('en-PH', {
                                    month: 'short', day: 'numeric', year: 'numeric',
                                    hour: '2-digit', minute: '2-digit'
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
    <div style="background:#F7F7F5; border-top:0.5px solid #E0D8ED; padding:10px 18px; text-align:center; flex-shrink:0;">
        <p style="font-size:11px; color:#BBBBBB; font-weight:400; letter-spacing:0.01em;">
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