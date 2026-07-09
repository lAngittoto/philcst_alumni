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
           SIDEBAR
        ════════════════════════════════════════════════════════ */
        .reg-sidebar {
            background: #FFFFFF;
            border-right: 1px solid #E5E5E5;
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
        .reg-nav-section-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #000000;
            opacity: 0.45;
            padding: 0 1rem;
            margin-top: 0.25rem;
            margin-bottom: 0.5rem;
        }

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

        @media (max-width: 1023px) {
            .reg-sidebar { box-shadow: 0 0 60px rgba(0,0,0,0.18); }

            /* ── Icon-only nav on mobile: hide labels, section titles, active dots ── */
            .reg-nav-label,
            .reg-nav-section-label,
            .reg-nav-dot {
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
           NOTIFICATION PANEL
        ════════════════════════════════════════════════════════ */
        .notif-item {
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 14px 18px;
            border-bottom: 0.5px solid #EEEEEE;
            transition: background 0.12s ease;
        }
        .notif-item:last-child  { border-bottom: none; }
        .notif-item.is-unread   { background: #FBFAFF; }
        .notif-item.is-read     { background: #FFFFFF; }
        .notif-item:hover       { background: #F5F0FB; }

        .notif-icon-wrap {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
            font-size: 15px;
        }
        .notif-icon-emp     { background: #EBF4FE; color: #1A6FC4; }
        .notif-icon-alumni  { background: #F0E9F8; color: #7A3F91; }
        .notif-icon-import  { background: #EAFAF3; color: #0E8058; }
        .notif-icon-chat    { background: #FEF0E6; color: #C06A20; }
        .notif-icon-profile { background: #FDECEF; color: #B4326B; }
        .notif-icon-default { background: #F3F3F3; color: #888888; }

        .notif-body         { flex: 1; min-width: 0; }

        .notif-title-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }
        .notif-title-text      { font-size: 13px; font-weight: 600; color: #222222; line-height: 1.35; }
        .notif-title-text.is-read { color: #555555; font-weight: 500; }

        .notif-tag {
            display: inline-flex;
            align-items: center;
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .notif-tag-emp     { background: #DBEAFE; color: #1347A0; }
        .notif-tag-alumni  { background: #EDE1F9; color: #5A2270; }
        .notif-tag-import  { background: #D1FAE5; color: #065F46; }
        .notif-tag-chat    { background: #FEE8D1; color: #953F0E; }
        .notif-tag-profile { background: #FBD9E3; color: #8A2049; }

        .notif-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px; height: 18px;
            background: #7A3F91;
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            border-radius: 20px;
            padding: 0 5px;
            flex-shrink: 0;
        }

        .notif-unread-dot {
            width: 7px; height: 7px;
            background: #DC2626;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .notif-message-text {
            font-size: 12px;
            color: #555555;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 6px;
        }

        .notif-time-row { display: flex; align-items: center; gap: 5px; font-size: 11px; color: #AAAAAA; }
        .notif-time-row i { font-size: 10px; }

        /* ── MOBILE: full-screen notification panel (Messenger-style) ──────────
           Overrides the desktop floating-panel inline styles (top/left/width/
           border-radius/etc. set via style="" + positionPanel()) using
           !important, since an !important rule in an external stylesheet
           always wins over a non-important inline style. No JS changes
           needed — positionPanel() can keep writing left/top/width, this
           simply overrides it on small screens. ── */
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
                box-shadow: none !important;
            }
            #notif-list {
                max-height: none !important;
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

                        var isEmpEvent = (
                            rawDedup.startsWith('employment_update') ||
                            rawDedup.startsWith('employment::')      ||
                            rawDedup.startsWith('recorded::')        ||
                            rawDedup.startsWith('updated::')         ||
                            n.title === 'Employment Status Updated'  ||
                            n.title === 'New Employment Record'      ||
                            n.icon  === 'arrow-rotate-right'
                        );
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
                        var isProfileEvent = (
                            rawDedup.startsWith('profile_update::') ||
                            n.title === 'Alumni Profile Updated'    ||
                            n.icon  === 'user-pen'
                        );

                        var groupKey;
                        if (isEmpEvent) {
                            groupKey = 'employment_day::' + day;
                        } else if (isProfileEvent) {
                            groupKey = 'profile_day::' + day;
                        } else if (isChatMsg) {
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
                            if (isEmpEvent) {
                                g.message = g.count + ' employment status update(s) today.';
                                g.title   = 'Employment Status Updated';
                            } else if (isProfileEvent) {
                                g.message = g.count + ' alumni profile update(s) today.';
                                g.title   = 'Alumni Profile Updated';
                            } else if (isChatMsg) {
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
                                title: isEmpEvent     ? 'Employment Status Updated'
                                     : isProfileEvent ? 'Alumni Profile Updated'
                                     : isChatMsg      ? (n.title || 'New Chat Message')
                                     : isAlumniEvent  ? 'New Alumni Registered'
                                     : isImportEvent  ? 'Bulk Import Complete'
                                     : n.title,
                                icon: isEmpEvent     ? 'arrow-rotate-right'
                                    : isProfileEvent ? 'user-pen'
                                    : isChatMsg      ? 'comment-dots'
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
        var aside = document.querySelector('aside');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        if (window.innerWidth >= 1024) {
            panel.style.left  = (btnRect.right - 400) + 'px';
            panel.style.top   = (btnRect.bottom  + 8)  + 'px';
            panel.style.width = '400px';
        } else {
            // Mobile: the CSS media query (#notif-panel !important rules)
            // forces this full-screen regardless of what's set here.
            panel.style.left  = '0px';
            panel.style.top   = '0px';
            panel.style.width = '100%';
        }
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
        var routesToMark = [routeName];
        if (routeName === 'registrar.alumni') {
            routesToMark.push('registrar.alumni.register');
        }
        if (routeName === 'registrar.alumni.register') {
            routesToMark.push('registrar.alumni');
        }
        routesToMark.forEach(function (r) { s.markReadByRoute(r); });
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

        window.addEventListener('employment-recorded', function (e) {
            var today = new Date().toISOString().slice(0, 10);
            _saveNotif({
                icon:       'arrow-rotate-right',
                title:      'Employment Status Updated',
                message:    'An alumni submitted a new employment record.',
                link_route: 'registrar.employment.tracking',
                link_label: 'View Tracking',
                dedup_key:  'employment_update::' + today,
            });
        });

        window.addEventListener('employment-updated', function (e) {
            var today = new Date().toISOString().slice(0, 10);
            _saveNotif({
                icon:       'arrow-rotate-right',
                title:      'Employment Status Updated',
                message:    'An alumni updated their employment status.',
                link_route: 'registrar.employment.tracking',
                link_label: 'View Tracking',
                dedup_key:  'employment_update::' + today,
            });
        });

        // Alumni profile updates (personal info form) — grouped per day,
        // same pattern as employment-updated above, with the alumnus'
        // actual name in the message instead of a generic placeholder.
        window.addEventListener('profile-updated', function (e) {
            var d = _detail(e);
            var today = new Date().toISOString().slice(0, 10);
            var who = d.name || 'An alumnus';
            _saveNotif({
                icon:       'user-pen',
                title:      'Alumni Profile Updated',
                message:    who + ' updated their profile information.',
                link_route: 'registrar.alumni',
                link_label: 'View Alumni',
                dedup_key:  'profile_update::' + today,
            });
        });

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
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" x-data="{ sidebarOpen: false, loggingOut: false }">
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
           class="reg-sidebar fixed inset-y-0 left-0 z-50 w-20 min-w-[5rem] lg:w-72 lg:min-w-[18rem] transform
                  transition-transform duration-300
                  lg:translate-x-0 lg:static lg:inset-0
                  flex flex-col h-full text-[#333333] shrink-0">

        {{-- Sidebar Header --}}
        <div class="flex items-center justify-center lg:justify-start h-24 px-2 lg:px-5 border-b border-[#E5E5E5] shrink-0">
            <div class="min-w-0 hidden lg:block">
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
            <p class="reg-nav-section-label">MENU</p>
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

        {{-- Top bar (universal — hamburger on mobile only, single bell always) --}}
        <header class="flex items-center justify-between px-4 lg:px-8 h-24 bg-white border-b border-[#E8E0F0]
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
     Desktop: small floating card near the bell (positioned via positionPanel()).
     Mobile (<1024px): forced full-screen, Messenger-style, via the
     "#notif-panel" !important media query in <style> above.
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
        background: #FFFFFF;
        border-radius: 16px;
        border: 0.5px solid #E0D8ED;
        box-shadow: 0 20px 48px -8px rgba(90,34,112,0.18), 0 4px 16px rgba(0,0,0,0.08);
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
            {{-- Close (X) button: no tooltip/title anywhere (mobile & desktop) --}}
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

        {{-- Empty state --}}
        <template x-if="$store.notifs && $store.notifs.items.length === 0">
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:56px 24px; text-align:center;">
                <div style="width:56px; height:56px; background:#F3F3F3; border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:16px; border:0.5px solid #EEEEEE;">
                    <i class="fas fa-bell-slash" style="font-size:22px; color:#CCCCCC;"></i>
                </div>
                <p style="font-size:14px; font-weight:600; color:#888888; margin-bottom:6px;">No notifications yet</p>
                <p style="font-size:12px; color:#BBBBBB; line-height:1.6;">
                    Alumni registrations, bulk imports, employment updates,<br>profile updates, and chat messages will appear here.
                </p>
            </div>
        </template>

        {{-- Notification items --}}
        <template x-if="$store.notifs">
            <template x-for="notif in $store.notifs.items" :key="notif.id">
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

                    {{-- Icon --}}
                    <div class="notif-icon-wrap"
                         :class="{
                             'notif-icon-emp':     notif.icon === 'arrow-rotate-right',
                             'notif-icon-alumni':  notif.icon === 'user-graduate',
                             'notif-icon-import':  notif.icon === 'file-import',
                             'notif-icon-chat':    notif.icon === 'comment-dots',
                             'notif-icon-profile': notif.icon === 'user-pen',
                             'notif-icon-default': !['arrow-rotate-right','user-graduate','file-import','comment-dots','user-pen'].includes(notif.icon)
                         }">
                        <i class="fas" :class="'fa-' + (notif.icon || 'bell')"></i>
                    </div>

                    {{-- Body --}}
                    <div class="notif-body">

                        {{-- Title row --}}
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px;">
                            <div class="notif-title-row">
                                <span class="notif-title-text"
                                      :class="notif.read ? 'is-read' : ''"
                                      x-text="notif.title">
                                </span>

                                {{-- Type tags --}}
                                <span x-show="notif.icon === 'arrow-rotate-right'" x-cloak
                                      class="notif-tag notif-tag-emp">Employment</span>
                                <span x-show="notif.icon === 'user-graduate'" x-cloak
                                      class="notif-tag notif-tag-alumni">New Alumni</span>
                                <span x-show="notif.icon === 'file-import'" x-cloak
                                      class="notif-tag notif-tag-import">Import</span>
                                <span x-show="notif.icon === 'comment-dots'" x-cloak
                                      class="notif-tag notif-tag-chat">Message</span>
                                <span x-show="notif.icon === 'user-pen'" x-cloak
                                      class="notif-tag notif-tag-profile">Profile</span>

                                {{-- Count badge --}}
                                <span x-show="Number(notif.count) > 1" x-cloak
                                      class="notif-count-badge"
                                      x-text="'×' + Number(notif.count)">
                                </span>
                            </div>

                            {{-- Unread dot --}}
                            <span x-show="!notif.read" x-cloak class="notif-unread-dot"></span>
                        </div>

                        {{-- Message --}}
                        <p class="notif-message-text" x-text="notif.message"></p>

                        {{-- Timestamp --}}
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