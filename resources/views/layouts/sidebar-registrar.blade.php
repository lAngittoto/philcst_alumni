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
        .reg-nav-link:not(.is-active):hover { background: #F5F5F5; }
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
            background: #EDEDED;
            border-left: 4px solid transparent;
        }
        .notif-item.is-read:hover { background: #E4E4E4; }

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
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #7A3F91;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .notif-message-text {
            font-size: .8rem;
            color: #4D4D4D;
            line-height: 1.45;
            margin-top: 2px;
        }

        .notif-time-row {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 6px;
            font-size: .68rem;
            color: #333333;
            font-weight: 600;
        }
        .notif-time-row i { font-size: .62rem; }

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
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                max-height: 100dvh !important;
                min-height: 0 !important;
                border-radius: 0 !important;
                border: none !important;
            }
            #notif-list {
                max-height: calc(100dvh - 150px) !important;
            }
            #notif-footer-hint {
                display: none !important;
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
                        var nAlumniIds = Array.isArray(n.alumni_ids) ? n.alumni_ids : [];
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
                            if (nName && g._names.indexOf(nName) === -1) {
                                g._names.push(nName);
                            }
                            if (nTimestamp && new Date(nTimestamp) > new Date(g.created_at)) {
                                g.created_at = nTimestamp;
                            }
                            if (isChatMsg) {
                                var rName = g._roomName || 'group chat';
                                g.message = g.count + ' new message(s) in ' + rName + '.';
                            } else if (isAlumniEvent) {
                                // Show every distinct name we actually have,
                                // e.g. "Fernandos Sal Junios Sr. and Loki M.
                                // Asgard XIX have been registered..." — falls
                                // back to the generic count wording only if
                                // no names came through at all.
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
                                count:      Number(n.count) || 1,
                                _ids:       [n.id],
                                _roomName:  n._roomName || '',
                                _names:     nName ? [nName] : [],
                                alumni_ids: nAlumniIds.slice(),
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
                await this.goToTarget(item);
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
                if (!routeName || !window.__registrarRouteMap || !window.__registrarRouteMap[routeName]) return;

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

                this.close();

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
                    window.location.href = url;
                } else if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                    window.Livewire.navigate(url);
                } else {
                    window.location.href = url;
                }
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
          sidebarCollapsed: false,
          loggingOut: false
      }">


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
                   @click="window.__sidebarNotifsMarkRead('{{ $link['route'] }}'); sidebarOpen = false;"
                   class="reg-nav-link {{ $isActive ? 'is-active' : '' }}">
                    <div class="reg-nav-icon {{ $link['color'] }}">
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
    x-transition:enter-start="opacity-0 scale-[0.98] -translate-y-1"
    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
    x-transition:leave="transition ease-in duration-75"
    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
    x-transition:leave-end="opacity-0 scale-[0.98] -translate-y-1"
    @click.stop
    @contextmenu.prevent
    @copy.prevent
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
    <div style="background:#FFFFFF; padding:9px 18px; display:flex; align-items:center; justify-content:space-between; border-bottom:0.5px solid #E0D8ED; flex-shrink:0;">
        <span style="font-size:11px; font-weight:600; color:#7A3F91; letter-spacing:0.1em; text-transform:uppercase;">Recent Activity</span>
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
                    :class="[
                        notif.read ? 'is-read' : 'is-unread',
                        !notif.read
                            ? (notif.icon === 'user-graduate' ? 'clr-alumni'
                               : notif.icon === 'file-import' ? 'clr-import'
                               : notif.icon === 'comment-dots' ? 'clr-chat'
                               : 'clr-default')
                            : ''
                    ]"
                    @click.stop="
                        $store.notifs.markRead(notif);
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
    <div id="notif-footer-hint" style="background:#FFFFFF; border-top:0.5px solid #E0D8ED; padding:10px 18px; text-align:center; flex-shrink:0;">
        <p style="font-size:11px; color:#666666; font-weight:400; letter-spacing:0.01em;">
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