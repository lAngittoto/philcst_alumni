<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} - Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        .admin-bell-btn {
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
        .admin-bell-btn:hover,
        .admin-bell-btn:focus,
        .admin-bell-btn:active {
            background: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .bell-badge { pointer-events: none; }

        /* ── Ripple / expanding wave effect for unread indicators ──
           Used on the bell badge count AND the per-notification red
           dot. Rather than a ::before that inherits the parent's exact
           box shape (which broke for .bell-badge, since it's
           min-width-based and can be oblong once it holds "99+"), this
           renders a separate small ABSOLUTE, perfectly circular layer
           centered directly behind the badge/dot via a fixed size +
           top/left/transform centering — independent of whatever shape
           or size the parent element itself ends up being. */
        .notif-ripple {
            position: relative;
        }
        .notif-ripple::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 18px;
            height: 18px;
            margin-top: -9px;
            margin-left: -9px;
            border-radius: 999px;
            background: #EF4444;
            opacity: 0.6;
            animation: notifRippleWave 1.8s ease-out infinite;
            pointer-events: none;
            z-index: -1;
        }
        @keyframes notifRippleWave {
            0%   { transform: scale(1);    opacity: 0.55; }
            70%  { transform: scale(2.2);  opacity: 0; }
            100% { transform: scale(2.2);  opacity: 0; }
        }
        .admin-notif-item { cursor: pointer; position: relative; }

        /* Disable text selection/copy inside the notif panel — header
           label, item titles, messages, timestamps, footer hint.
           Buttons/links still clickable, just no text selection. */
        .admin-notif-no-select,
        .admin-notif-no-select * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .admin-notif-close-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .admin-notif-close-tip {
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
        .admin-notif-close-tip::after {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-bottom-color: #1a1a1a;
        }
        .admin-notif-close-wrap:hover .admin-notif-close-tip { opacity: 1; }
        @media (max-width: 1023px) {
            .admin-notif-close-tip { display: none !important; }
        }

        /* ── Delete button (30+ days old notifs only) ── */
        .admin-notif-delete-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: #DC2626;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color .15s ease, color .15s ease;
        }
        .admin-notif-delete-btn:hover {
            background: #FDE8E8;
            color: #B91C1C;
        }
        .admin-notif-delete-btn i { font-size: .85rem; pointer-events: none; }
        .admin-notif-delete-tooltip {
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
        .admin-notif-delete-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            right: 7px;
            border: 4px solid transparent;
            border-top-color: #DC2626;
        }
        .admin-notif-delete-btn:hover .admin-notif-delete-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Read/Unread section divider ─────────────────────────── */
        .admin-notif-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px 6px;
        }
        .admin-notif-divider::before,
        .admin-notif-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #E8E0F0;
        }
        .admin-notif-divider-label {
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #B9A6C7;
            white-space: nowrap;
        }

        /* ════════════════════════════════════════════════════════
           NOTIFICATION PANEL — desktop dropdown, mobile FULL SCREEN
        ════════════════════════════════════════════════════════ */
        #admin-notif-panel {
            max-width: calc(100vw - 16px);
        }
        @media (max-width: 1023px) {
            #admin-notif-panel {
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
            #admin-notif-panel .admin-notif-list-scroll {
                max-height: calc(100vh - 190px) !important;
            }
        }
        /* ════════════════════════════════════════════════════════
           SIDEBAR COLLAPSE (desktop only)
        ════════════════════════════════════════════════════════ */
        .admin-collapsible-text {
            opacity: 1;
            max-width: 220px;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s ease, max-width 0.2s ease;
        }
        .admin-nav-section-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 0.5rem;
            margin-bottom: 0.5rem;
        }
        .admin-section-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.16em;
            color: #333333;
        }
        .admin-collapse-icon-btn {
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
        .admin-collapse-icon-btn:hover { background: #E9D8F5; }
        .admin-collapse-icon-btn:active { transform: scale(0.88); }
        .admin-collapse-icon-btn i { pointer-events: none; }

        @media (min-width: 1024px) {
            #admin-sidebar-aside.is-collapsed {
                width: 0 !important;
                min-width: 0 !important;
                border-right-width: 0 !important;
                overflow: hidden !important;
                pointer-events: none;
            }
            #admin-sidebar-aside.is-collapsed .admin-collapsible-text {
                opacity: 0;
                max-width: 0;
                margin-left: 0 !important;
                margin-right: 0 !important;
                pointer-events: none;
            }
            #admin-sidebar-aside.is-collapsed .admin-sidebar-header {
                justify-content: center;
                padding-left: 0;
                padding-right: 0;
            }
            #admin-sidebar-aside.is-collapsed nav a {
                justify-content: center;
                padding: 0.85rem;
            }
            #admin-sidebar-aside.is-collapsed nav a > div:first-child {
                margin-right: 0 !important;
            }
            #admin-sidebar-aside.is-collapsed .admin-nav-section-row {
                justify-content: center;
                padding: 0 0.25rem;
            }
            #admin-sidebar-aside.is-collapsed .admin-nav-active-dot {
                display: none !important;
            }
            #admin-sidebar-aside.is-collapsed form button[type="submit"] {
                padding-left: 0.9rem;
                padding-right: 0.9rem;
            }
            #admin-sidebar-aside.is-collapsed form button[type="submit"] i {
                margin-right: 0 !important;
            }
        }
    </style>

    <script>
    // ─────────────────────────────────────────────────────────────────────────
    //  ROUTE MAP
    // ─────────────────────────────────────────────────────────────────────────
    window.__adminRouteMap = {
        'admin.dashboard':      '/admin/dashboard',
        'user.management':      '/user/management',
        'employment.tracking':  '/employment/tracking',
        'admin.yearbook':       '/yearbook',
        'job.posts':            '/job/posts',
        'events':               '/events',
        'course':               '/course',
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeAdminNotifsStore = function () {
        return {
            open:       false,
            items:      [],
            _pollTimer: null,
            _deleting:  false,
            deleteToast: { show: false, message: '' },

            async init() {
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                // 1.5s so a new job posting (or any admin notif) lands in
                // the bell almost the instant it's written to the DB —
                // was 10000ms, which is what made the bell lag behind the
                // jobs table by several seconds even after the write
                // itself became real-time.
                this._pollTimer = setInterval(function () { self._fetch(); }, 1500);
            },

            async _fetch() {
                if (this._deleting) return; // don't let a poll refresh clobber an in-flight delete
                try {
                    var res = await window.fetch('/admin/notifications', {
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
                        var day      = n.created_at
                            ? new Date(n.created_at).toISOString().slice(0, 10)
                            : 'unknown';
                        var rawDedup = n.dedup_key || '';

                        // ── event type flags ───────────────────────────────

                        // USER MANAGEMENT — separate-per-action types (never grouped)
                        var isUserCreatedEvent  = rawDedup.startsWith('user-created::');
                        var isUserToggledEvent  = rawDedup.startsWith('user-toggled::');
                        var isUserEmailEvent    = rawDedup.startsWith('user-email::');
                        var isUserUsernameEvent = rawDedup.startsWith('user-username::');

                        // Generic user management update (still groups by day)
                        var isUserEvent = (
                            rawDedup.startsWith('user-management::') ||
                            n.icon === 'users'
                        ) && !isUserCreatedEvent && !isUserToggledEvent && !isUserEmailEvent && !isUserUsernameEvent;

                        var isEmploymentEvent = (
                            rawDedup.startsWith('employment-tracking::') ||
                            n.icon === 'chart-line'
                        );
                        var isYearbookEvent = (
                            rawDedup.startsWith('yearbook::') ||
                            n.icon === 'book-open'
                        );

                        // NEW JOB POST — dedup prefix: job-posted:: (separate row per job, never grouped)
                        var isNewJobEvent = rawDedup.startsWith('job-posted::');

                        // EVENT APPROVED / COMPLETED — dedup prefix: event-status::
                        // One row PER EVENT that morphs in place: created as
                        // "Event Approved", later updated (same dedup_key,
                        // same row) to "Event Completed" when the event's
                        // date passes. Never grouped/collapsed. Badge is
                        // decided by the row's current title/icon rather
                        // than by a fixed dedup suffix, since the same row
                        // can represent either state depending on when you
                        // look at it.
                        //
                        // Legacy prefixes (event-approved:: / event-completed::)
                        // are still recognized for any older rows already in
                        // the table before this morph-in-place change.
                        var isEventStatusRow = rawDedup.startsWith('event-status::');
                        var isApprovedEvent = isEventStatusRow
                            ? (n.title === 'Event Approved')
                            : rawDedup.startsWith('event-approved::');
                        var isCompletedEvent = isEventStatusRow
                            ? (n.title === 'Event Completed')
                            : rawDedup.startsWith('event-completed::');

                        // COURSE — capped at 2 rows a day (AM / PM slot), dedup_key already
                        // encodes course::{day}::{am|pm} so the map naturally caps it.
                        var isCourseEvent = (
                            rawDedup.startsWith('course::') ||
                            (n.icon === 'clipboard-list' && n.title === 'Course Update')
                        );

                        // ── group key ──────────────────────────────────────
                        var groupKey;
                        if (isUserCreatedEvent)      { groupKey = rawDedup; }           // per-creation, no collapsing
                        else if (isUserToggledEvent) { groupKey = rawDedup; }           // per-toggle, no collapsing
                        else if (isUserEmailEvent)   { groupKey = rawDedup; }           // per-email-update, no collapsing
                        else if (isUserUsernameEvent){ groupKey = rawDedup; }           // per-username-update, no collapsing
                        else if (isUserEvent)         { groupKey = 'user_day::' + day; }
                        else if (isEmploymentEvent)  { groupKey = 'employment_day::' + day; }
                        else if (isYearbookEvent)    { groupKey = 'yearbook_day::' + day; }
                        else if (isNewJobEvent)      { groupKey = rawDedup; }
                        else if (isApprovedEvent)    { groupKey = rawDedup; }
                        else if (isCompletedEvent)   { groupKey = rawDedup; }
                        else if (isCourseEvent)      { groupKey = rawDedup; }           // dedup_key already caps to 2/day
                        else { groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id); }

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);

                            // Update group titles for collapsible types only
                            if (isUserEvent)         { g.title = 'User Management Update'; }
                            else if (isEmploymentEvent)  { g.title = 'Employment Tracking Update'; }
                            else if (isYearbookEvent)    { g.title = 'Yearbook Update'; }
                            else if (isCourseEvent)      { g.title = 'Course Update'; }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: isUserCreatedEvent  ? (n.title || 'New Director Created')
                                     : isUserToggledEvent  ? (n.title || 'Account Status Changed')
                                     : isUserEmailEvent    ? (n.title || 'Email Updated')
                                     : isUserUsernameEvent ? (n.title || 'Username Updated')
                                     : isUserEvent         ? (n.title || 'User Management Update')
                                     : isEmploymentEvent   ? (n.title || 'Employment Tracking Update')
                                     : isYearbookEvent     ? (n.title || 'Yearbook Update')
                                     : isNewJobEvent       ? (n.title || 'New Job Posting')
                                     : isApprovedEvent     ? (n.title || 'Event Approved')
                                     : isCompletedEvent    ? (n.title || 'Event Completed')
                                     : isCourseEvent       ? (n.title || 'Course Update')
                                     : n.title,
                                icon:  isUserCreatedEvent  ? 'user-tie'
                                     : isUserToggledEvent  ? 'circle-check'
                                     : isUserEmailEvent    ? 'envelope'
                                     : isUserUsernameEvent ? 'user-pen'
                                     : isUserEvent         ? 'users'
                                     : isEmploymentEvent   ? 'chart-line'
                                     : isYearbookEvent     ? 'book-open'
                                     : isNewJobEvent       ? 'briefcase'
                                     : isApprovedEvent     ? 'calendar-check'
                                     : isCompletedEvent    ? 'circle-check'
                                     : isCourseEvent       ? 'clipboard-list'
                                     : (n.icon || 'bell'),
                                // Carry flags so the template knows what kind of row this is
                                _isNewJob:         isNewJobEvent,
                                _isApprovedEvent:  isApprovedEvent,
                                _isCompletedEvent: isCompletedEvent,
                                _isUserCreated:    isUserCreatedEvent,
                                _isUserToggled:    isUserToggledEvent,
                                _isUserEmail:      isUserEmailEvent,
                                _isUserUsername:   isUserUsernameEvent,
                            }));
                        }
                    });
                return Array.from(map.values());
            },

            get unread() {
                return this.items.filter(function (n) { return !n.read; }).length;
            },

            toggle() {
                this.open = !this.open;
                // Fetch immediately on open — don't wait for the next
                // 1.5s poll tick, so anything posted a split-second ago
                // is guaranteed visible the moment the panel appears.
                if (this.open) this._fetch();
            },
            close()  { this.open = false; },

            async markRead(item) {
                if (item.read) return;
                item.read = true;
                var ids  = Array.isArray(item._ids) ? item._ids : [item.id];
                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                for (var i = 0; i < ids.length; i++) {
                    try {
                        await window.fetch('/admin/notifications/' + ids[i] + '/read', {
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
                    await window.fetch('/admin/notifications/read-all', {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                } catch (e) { /* ignore */ }
            },

            // Deletes a notification MESSAGE only — never the underlying
            // record that generated it. Only ever called for notifs that
            // are 30+ days old (enforced by the x-show on the delete
            // button in the markup), so this is purely a "clean up old
            // noise" action, not a moderation action on real data.
            async deleteNotif(item) {
                var ids = item._ids || [item.id];
                var self = this;
                this._deleting = true;
                this._showDeleteToast('Notification deleted');

                // Give the slide-out leave transition time to play before
                // actually removing the item from the array.
                await new Promise(function (resolve) { setTimeout(resolve, 250); });
                this.items = this.items.filter(function (n) { return n !== item; });

                var csrf = document.querySelector('meta[name="csrf-token"]').content;
                var failedIds = [];

                for (var i = 0; i < ids.length; i++) {
                    try {
                        var res = await window.fetch('/admin/notifications/' + ids[i], {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (!res.ok) failedIds.push(ids[i]);
                    } catch (e) {
                        failedIds.push(ids[i]);
                    }
                }

                this._deleting = false;

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
                }, 1200);
            },
        };
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  SAFE ACCESSOR
    // ─────────────────────────────────────────────────────────────────────────
    window.__safeAdminNotifsStore = function () {
        try {
            if (window.Alpine && typeof Alpine.store === 'function') {
                var s = Alpine.store('adminNotifs');
                if (s) return s;
            }
        } catch (e) {}
        return null;
    };

    window.__bootAdminNotifsStore = function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        if (!Alpine.store('adminNotifs')) {
            Alpine.store('adminNotifs', window.__makeAdminNotifsStore());
        }
        var s = Alpine.store('adminNotifs');
        if (s && !s._pollTimer) s.init();
    };

    // PATH A
    document.addEventListener('alpine:init', function () {
        Alpine.store('adminNotifs', window.__makeAdminNotifsStore());
    });

    // PATH B
    document.addEventListener('alpine:initialized', function () {
        setTimeout(function () {
            var s = window.__safeAdminNotifsStore();
            if (s && !s._pollTimer) s.init();
        }, 0);
    });

    // PATH C
    window.addEventListener('load', function () {
        var s = window.__safeAdminNotifsStore();
        if (s) { if (s.items.length === 0) s.init(); }
        else    { window.__bootAdminNotifsStore(); }
    });

    // PATH D — livewire:navigated
    //
    // FIX: this used to unconditionally clear + restart the poller (or
    // even create a brand-new one) on every single wire:navigate hop,
    // with no check on WHERE we navigated to. Two bugs came from that:
    //
    //   1. Navigating away from the admin portal entirely (e.g. after
    //      logout, or — if an Alpine store ever survives across a role
    //      switch — into a Director/Organizer page) would still find
    //      #admin-bell-btn missing, but the code didn't check for that:
    //      it called s.init() anyway, which kicks off _fetch() +
    //      _startPolling() against /admin/notifications using whatever
    //      session happens to be active at that moment. If a fresh
    //      login (as a DIFFERENT account) landed in the same 150ms
    //      window, this stray poll could interfere with the new
    //      session and boot it back out — the exact "log in, instantly
    //      logged out" bug.
    //
    //   2. The bare 150ms setTimeout meant the poller could still be
    //      alive and ticking during that gap even when we're mid-
    //      logout, since wire:navigate doesn't tear down window-scoped
    //      JS state.
    //
    // Now: only (re)start the poller if we've actually landed on an
    // admin page (#admin-bell-btn or #admin-bell-btn-mobile present in
    // the DOM). Anywhere else, tear the store down completely so it
    // can't keep firing requests in the background.
    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;

            var onAdminPage = !!(document.getElementById('admin-bell-btn') || document.getElementById('admin-bell-btn-mobile'));
            var s = Alpine.store('adminNotifs');

            if (!onAdminPage) {
                // Left the admin portal (logout, role switch, or any
                // other page) — fully stop and drop the store so
                // nothing keeps polling in the background.
                if (s) {
                    if (s._pollTimer) clearInterval(s._pollTimer);
                    s._pollTimer = null;
                    s.open = false;
                }
                return;
            }

            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open = false;
                s.init();
            } else {
                Alpine.store('adminNotifs', window.__makeAdminNotifsStore());
                var ns = Alpine.store('adminNotifs');
                if (ns) ns.init();
            }
        }, 150);
    });

    // PATH E — IIFE
    ;(function () {
        if (!window.Alpine || typeof Alpine.store !== 'function') return;
        var s = Alpine.store('adminNotifs');
        if (!s) {
            Alpine.store('adminNotifs', window.__makeAdminNotifsStore());
            s = Alpine.store('adminNotifs');
        }
        if (s && !s._pollTimer) setTimeout(function () { s.init(); }, 100);
    })();

    // Re-fetch on tab focus
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var s = window.__safeAdminNotifsStore();
            if (s) s._fetch();
        }
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  PANEL POSITIONING — desktop only (mobile is handled entirely by CSS, full screen)
    // ─────────────────────────────────────────────────────────────────────────
    function positionAdminPanel() {
        if (window.innerWidth < 1024) return;
        var btn   = document.getElementById('admin-bell-btn');
        var panel = document.getElementById('admin-notif-panel');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        panel.style.left  = (btnRect.right - 400) + 'px';
        panel.style.top   = (btnRect.bottom + 8) + 'px';
        panel.style.width = '400px';
    }
    window.positionAdminPanel = positionAdminPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeAdminNotifsStore();
        if (s && s.open) positionAdminPanel();
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  TIMESTAMP HELPER — "Today, 5:07 PM" vs "Jun 23, 5:07 PM"
    // ─────────────────────────────────────────────────────────────────────────
    window.__adminFormatNotifTime = function (isoStr) {
        if (!isoStr) return '';
        var d       = new Date(isoStr);
        var now     = new Date();
        var isToday = d.getFullYear() === now.getFullYear() &&
                      d.getMonth()    === now.getMonth()    &&
                      d.getDate()     === now.getDate();
        var timePart = d.toLocaleString('en-PH', { hour: '2-digit', minute: '2-digit' });
        if (isToday) {
            return 'Today, ' + timePart;
        }
        var datePart = d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
        return datePart + ', ' + timePart;
    };

    // ─────────────────────────────────────────────────────────────────────────
    //  NOTIFICATION EVENT LISTENERS
    //
    //  user-created:: = new director created    → separate row per creation
    //  user-toggled:: = activate / deactivate   → separate row per action
    //  user-email::   = alumni email updated    → separate row per update
    //
    //  job-posted::      = brand-new job post      → separate row per job (only job trigger — no "edit" notif)
    //  event-approved::  = event approved           → separate row per event
    //  event-completed:: = event marked completed   → separate row per event
    //  course::{day}::{am|pm} = course changes       → capped at 2 rows a day (matches chat's cap)
    // ─────────────────────────────────────────────────────────────────────────
    if (!window.__philcstAdminNotifListeners) {
        window.__philcstAdminNotifListeners = true;

        function _adminDetail(e) {
            var d = e.detail;
            if (!d) return {};
            if (!Array.isArray(d)) return d;
            return d[0] || {};
        }

        function _courseChangeMessage(d) {
            var code    = (d.new_code || d.code    || '').trim();
            var oldCode = (d.old_code || '').trim();
            var name    = (d.new_name || d.name    || '').trim();
            var oldName = (d.old_name || '').trim();

            if (d.action === 'created') {
                return 'New course added: ' + code;
            }
            var codeChanged = oldCode && code && oldCode !== code;
            var nameChanged = oldName && name && oldName !== name;
            if (codeChanged && nameChanged) {
                return oldCode + ' → ' + code + ' (' + oldName + ' → ' + name + ')';
            }
            if (codeChanged) { return oldCode + ' → ' + code; }
            if (nameChanged) { return oldCode + ': ' + oldName + ' → ' + name; }
            return (code || 'A course') + ' was re-saved with no changes.';
        }

        async function _saveAdminNotif(payload) {
            try {
                await window.fetch('/admin/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeAdminNotifsStore();
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeAdminNotifsStore();
                    if (s2) await s2._fetch();
                }, 600);
            } catch (e) { /* ignore */ }
        }

        // ── NEW DIRECTOR CREATED ─────────────────────────────────────────────
        // Fired by manage-users.blade.php via __admin-user-created-rich.
        // dedup_key: user-created::{uid} — separate row per creation, never grouped.
        // Message: "Full Name account has been created. (Username: username)"
        window.addEventListener('__admin-user-created-rich', function (e) {
            var d = _adminDetail(e);
            if (!d || !d.uid) return;
            _saveAdminNotif({
                icon:       'user-tie',
                title:      'New Director Created',
                message:    (d.name || 'A new director') + ' account has been created.'
                            + (d.username ? ' (Username: ' + d.username + ')' : ''),
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-created::' + d.uid,
            });
        });

        // ── DIRECTOR / REGISTRAR ACTIVATE | DEACTIVATE ───────────────────────
        // Fired by manage-users.blade.php via __admin-user-toggled-rich.
        // dedup_key: user-toggled::{uid}::{minute} — separate row per action.
        // Message: "Full Name has been activated/deactivated. (Director)"
        window.addEventListener('__admin-user-toggled-rich', function (e) {
            var d = _adminDetail(e);
            if (!d || !d.uid) return;
            var actionLabel = d.action === 'activate' ? 'activated' : 'deactivated';
            var roleLabel   = d.role
                ? d.role.charAt(0).toUpperCase() + d.role.slice(1)
                : '';
            _saveAdminNotif({
                icon:       d.action === 'activate' ? 'circle-check' : 'ban',
                title:      'Account ' + (d.action === 'activate' ? 'Activated' : 'Deactivated'),
                message:    (d.name || 'A user') + ' has been ' + actionLabel + '.'
                            + (roleLabel ? ' (' + roleLabel + ')' : ''),
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-toggled::' + d.uid + '::' + Math.floor(Date.now() / 60000),
            });
        });

        // ── EMAIL UPDATED (Alumni / Director) ────────────────────────────────
        // Fired by manage-users.blade.php via __admin-user-email-rich.
        // dedup_key: user-email::{uid}::{minute} — separate row per update.
        // Message: "Full Name email has been updated. New email: newemail@x.com"
        window.addEventListener('__admin-user-email-rich', function (e) {
            var d = _adminDetail(e);
            if (!d || !d.uid) return;
            var roleLabel = d.role
                ? d.role.charAt(0).toUpperCase() + d.role.slice(1)
                : 'Alumni';
            _saveAdminNotif({
                icon:       'envelope',
                title:      'Email Updated',
                message:    (d.name || 'A user') + ' email has been updated.'
                            + (d.email ? ' New email: ' + d.email : '')
                            + ' (' + roleLabel + ')',
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-email::' + d.uid + '::' + Math.floor(Date.now() / 60000),
            });
        });

        // ── USERNAME UPDATED (Registrar) ─────────────────────────────────────
        // Fired by manage-users.blade.php via __admin-user-username-rich.
        // dedup_key: user-username::{uid}::{minute} — separate row per update.
        // Message: "Full Name username has been updated. New username: jdelacruz2024"
        window.addEventListener('__admin-user-username-rich', function (e) {
            var d = _adminDetail(e);
            if (!d || !d.uid) return;
            _saveAdminNotif({
                icon:       'user-pen',
                title:      'Username Updated',
                message:    (d.name || 'A registrar') + ' username has been updated.'
                            + (d.username ? ' New username: ' + d.username : ''),
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-username::' + d.uid + '::' + Math.floor(Date.now() / 60000),
            });
        });

        // ── user (generic grouped) ────────────────────────────────────────────
        window.addEventListener('admin-user-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'users',
                title:      'User Management Update',
                message:    (d.name || 'A user') + ' account has been updated.',
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-management::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── employment ──────────────────────────────────────────────────────
        window.addEventListener('admin-employment-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'chart-line',
                title:      'Employment Tracking Update',
                message:    (d.name || 'An employment record') + ' has been updated.',
                link_route: 'employment.tracking',
                link_label: 'View Employment Tracking',
                dedup_key:  'employment-tracking::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── yearbook ────────────────────────────────────────────────────────
        window.addEventListener('admin-yearbook-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'book-open',
                title:      'Yearbook Update',
                message:    (d.name || 'A yearbook entry') + ' has been updated.',
                link_route: 'admin.yearbook',
                link_label: 'View Yearbook',
                dedup_key:  'yearbook::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── NEW JOB POST ─────────────────────────────────────────────────────
        window.addEventListener('__admin-job-posted-rich', function (e) {
            var d = e.detail;
            if (!d || !d.id) return;

            var message = (d.title || 'A new job posting')
                + (d.company ? ' at ' + d.company : '')
                + ' — Posted by: ' + (d.poster || 'Alumni Director');

            _saveAdminNotif({
                icon:       'briefcase',
                title:      'New Job Posting',
                message:    message,
                link_route: 'job.posts',
                link_label: 'View Jobs',
                dedup_key:  'job-posted::' + d.id,
            });
        });

        // ── EVENT APPROVED ───────────────────────────────────────────────────
        window.addEventListener('__admin-event-approved-rich', function (e) {
            var d = e.detail;
            if (!d || !d.id) return;
            _saveAdminNotif({
                icon:       'calendar-check',
                title:      'Event Approved',
                message:    d.message,
                link_route: 'events',
                link_label: 'View Events',
                dedup_key:  'event-approved::' + d.id,
            });
        });

        // ── EVENT COMPLETED ──────────────────────────────────────────────────
        window.addEventListener('__admin-event-completed-rich', function (e) {
            var d = e.detail;
            if (!d || !d.id) return;
            _saveAdminNotif({
                icon:       'circle-check',
                title:      'Event Completed',
                message:    d.message,
                link_route: 'events',
                link_label: 'View Events',
                dedup_key:  'event-completed::' + d.id,
            });
        });

        // ── course — capped at 2 notifications a day ─────────────────────────
        // dedup_key groups by DAY + slot (AM/PM half), so no matter how many
        // course edits happen, only up to 2 rows land in the bell per day.
        window.addEventListener('admin-course-updated', function (e) {
            var d = _adminDetail(e);
            var now  = new Date();
            var day  = now.toISOString().slice(0, 10);
            var slot = now.getHours() < 12 ? 'am' : 'pm';
            _saveAdminNotif({
                icon:       'clipboard-list',
                title:      'Course Update',
                message:    _courseChangeMessage(d),
                link_route: 'course',
                link_label: 'View Courses',
                dedup_key:  'course::' + day + '::' + slot,
            });
        });

        // ── generic refresh ──────────────────────────────────────────────────
        window.addEventListener('admin-notif-refresh', function () {
            var s = window.__safeAdminNotifsStore();
            if (s) {
                s._fetch();
                setTimeout(function () {
                    var s2 = window.__safeAdminNotifsStore();
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
        sidebarCollapsed: false,
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
        }
    }"
    @click="$store.adminNotifs && $store.adminNotifs.open && $store.adminNotifs.close()">

@php
    $authAdmin = auth()->user();
@endphp

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
        class="fixed inset-0 bg-black/50 lg:hidden"
        style="z-index: 9990;">
    </div>

    {{-- ══ SIDEBAR ══ --}}
    <aside
        id="admin-sidebar-aside"
        :class="[open ? 'translate-x-0' : '-translate-x-full', sidebarCollapsed ? 'is-collapsed' : '']"
        class="fixed inset-y-0 left-0 w-72 min-w-[18rem] transform transition-all duration-300
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0; z-index: 9991;">

        {{-- Sidebar header --}}
        <div class="admin-sidebar-header flex items-center justify-between h-24 px-5 border-b border-[#E8E0F0] shrink-0">

            <div class="admin-collapsible-text text-left min-w-0 flex-1 pr-2">
                <h1 class="text-2xl font-semibold tracking-tighter uppercase text-[#333333] leading-tight">
                    Admin<span class="font-semibold opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-semibold">
                    Management System
                </p>
            </div>

            {{-- Mobile close --}}
            <button @click="open = false"
                    class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors ml-2 shrink-0">
                <i class="fa-solid fa-circle-xmark text-xl"></i>
            </button>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">

            <div class="admin-nav-section-row">
                <p class="admin-section-label admin-collapsible-text">MENU</p>

                <button type="button"
                        @click.stop="toggleSidebar()"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        class="admin-collapse-icon-btn hidden lg:flex">
                    <i class="fas"
                       :class="{ 'fa-angles-right': sidebarCollapsed, 'fa-angles-left': !sidebarCollapsed }"
                       style="font-size:11px;line-height:1;"></i>
                </button>
            </div>

            @php
                $sidebarLinks = [
                    [
                        'route'   => 'admin.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'admin/dashboard*',
                        'color'   => '#7A3F91',
                    ],
                    [
                        'route'   => 'user.management',
                        'icon'    => 'users',
                        'label'   => 'User Management',
                        'pattern' => 'user/management*',
                        'color'   => '#7A3F91',
                    ],
                    [
                        'route'   => 'employment.tracking',
                        'icon'    => 'chart-line',
                        'label'   => 'Employment Tracking',
                        'pattern' => 'employment/tracking*',
                        'color'   => '#D97706',
                    ],
                    [
                        'route'   => 'admin.yearbook',
                        'icon'    => 'book-open',
                        'label'   => 'Yearbook',
                        'pattern' => 'yearbook*',
                        'color'   => '#0284C7',
                    ],
                    [
                        'route'   => 'job.posts',
                        'icon'    => 'briefcase',
                        'label'   => 'Job Posts',
                        'pattern' => 'job/posts*',
                        'color'   => '#059669',
                    ],
                    [
                        'route'   => 'events',
                        'icon'    => 'calendar-check',
                        'label'   => 'Events',
                        'pattern' => 'events*',
                        'color'   => '#059669',
                    ],
                    [
                        'route'   => 'course',
                        'icon'    => 'clipboard-list',
                        'label'   => 'Courses',
                        'pattern' => 'course*',
                        'color'   => '#7A3F91',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php $isActive = request()->is($link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group
                          {{ $isActive
                              ? 'bg-[#F5F5F5] border border-[#E8E0F0] shadow-md'
                              : 'hover:bg-[#F9F7FC]' }}">

                    <div class="w-10 h-10 flex items-center justify-center rounded-lg
                                transition-transform duration-300 group-hover:scale-110 shrink-0 mr-4"
                         style="background-color:{{ $isActive ? $link['color'].'1F' : '#F9F7FC' }};color:{{ $link['color'] }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="admin-collapsible-text font-medium tracking-wide flex-1
                                 {{ $isActive ? 'font-semibold' : 'text-[#333333]' }}"
                          style="{{ $isActive ? 'color:'.$link['color'].';' : '' }}{{ $link['route'] === 'employment.tracking' ? 'font-size:13.5px;' : '' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="admin-nav-active-dot ml-auto w-1.5 h-5 rounded-full shrink-0 opacity-70"
                              style="background:{{ $link['color'] }};"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Admin notification poller — mounted here (not <head>) so it
             renders as part of the sidebar's own Livewire tree. On mount
             it preloads existing admin_notifications straight into the
             JS store, so the bell already has data on the very first
             paint instead of waiting for the client-side poll's first
             tick. Wrapped in the same "kill it before logout" guard as
             the organizer sidebar's coord-notif-poller, so it can't keep
             firing requests into a session that's about to be destroyed
             — see the logout link's @click below, which dispatches
             'stop-admin-polling' before the wire:navigate hop starts. --}}
        <div wire:ignore.self x-data="{ pollingActive: true }" x-on:stop-admin-polling.window="pollingActive = false">
            <template x-if="pollingActive">
                @livewire('admin.admin-notif-poller')
            </template>
        </div>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            {{-- Plain wire:navigate link to a GET /logout route — same
                 pattern as every other sidebar link above. No form, no
                 CSRF token, so there's nothing that can go stale after
                 SPA hops or session expiry. This replaced a POST form
                 whose hidden _token field (and even a JS-synced version
                 read from the <meta name="csrf-token"> tag) could still
                 go stale, since wire:navigate never re-renders <head> —
                 that was the root cause of the immediate 419 Page
                 Expired on Logout click.

                 loggingOut just swaps the button's content to a
                 "Logging out" bouncing-dot state on click. The link
                 still navigates normally right after — this only
                 changes what's visible in the instant before the
                 redirect lands. --}}
            <a href="{{ route('logout') }}"
               wire:navigate
               title="Logout"
               x-data="{ loggingOut: false }"
               @click="
                   loggingOut = true;
                   /* Stop the admin notif poller RIGHT NOW, before the
                      wire:navigate hop even starts. Without this, the
                      setInterval poll timer survives the SPA navigation
                      (wire:navigate never fully reloads the page/JS
                      context) and keeps firing /admin/notifications
                      requests using the about-to-be-invalidated session.
                      If you log back in as a different account fast
                      enough, one of those stale in-flight requests can
                      land after the new session is established and
                      knock it back out — which is what caused the
                      'log in as X, instantly logged out again' bug.
                      Also dispatches stop-admin-polling, which unmounts
                      the admin-notif-poller Livewire component entirely
                      (its own wire:poll can't be reached by a plain
                      JS clearInterval, since it's driven by Livewire's
                      own request cycle, not a setInterval we control). */
                   window.dispatchEvent(new CustomEvent('stop-admin-polling'));
                   if (window.__safeAdminNotifsStore) {
                       var s = window.__safeAdminNotifsStore();
                       if (s) {
                           if (s._pollTimer) { clearInterval(s._pollTimer); s._pollTimer = null; }
                           s.open = false;
                       }
                   }
               "
               class="w-full text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-xs
                      transition-all flex items-center justify-center shadow-lg active:scale-95 hover:brightness-110"
               style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                <template x-if="!loggingOut">
                    <span class="flex items-center justify-center">
                        <i class="fa-solid fa-right-from-bracket mr-2"></i>
                        <span class="admin-collapsible-text">Logout</span>
                    </span>
                </template>
                <template x-if="loggingOut">
                    <span class="flex items-center justify-center">
                        <span class="admin-collapsible-text mr-2">Logging out</span>
                        <span class="inline-flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-white inline-block"
                                  style="animation: admLogoutDotBounce 0.9s infinite ease-in-out; animation-delay: 0s;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-white inline-block"
                                  style="animation: admLogoutDotBounce 0.9s infinite ease-in-out; animation-delay: 0.15s;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-white inline-block"
                                  style="animation: admLogoutDotBounce 0.9s infinite ease-in-out; animation-delay: 0.3s;"></span>
                        </span>
                    </span>
                </template>
            </a>
            <style>
                @keyframes admLogoutDotBounce {
                    0%, 80%, 100% { transform: translateY(0); opacity: 0.5; }
                    40% { transform: translateY(-4px); opacity: 1; }
                }
            </style>
        </div>
    </aside>

    {{-- Floating expand button — only visible when sidebar is fully collapsed --}}
    <button type="button"
            x-show="sidebarCollapsed"
            x-cloak
            @click.stop="toggleSidebar()"
            title="Expand sidebar"
            class="hidden lg:flex fixed items-center justify-center w-8 h-8 rounded-r-lg
                   transition hover:bg-[#E9D8F5]"
            style="top: 1.75rem; left: 0; background:#F3EBFA; color:#7A3F91; z-index: 9992; border:1px solid #E8E0F0; border-left:none;">
        <i class="fas fa-angles-right" style="font-size:11px;line-height:1;"></i>
    </button>

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
            <h2 class="text-lg font-bold text-[#333333]">Admin Portal</h2>

            {{-- Bell Button (mobile) --}}
            <button
                id="admin-bell-btn-mobile"
                type="button"
                class="admin-bell-btn"
                @click.stop="$store.adminNotifs && $store.adminNotifs.toggle(); positionAdminPanel();"
                title="Notifications"
                aria-label="Open notifications">

                <i class="fas fa-bell"
                   :class="$store.adminNotifs && $store.adminNotifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:19px; color:#7A3F91;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>

                <span
                    x-show="$store.adminNotifs && $store.adminNotifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="notif-ripple bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.adminNotifs && $store.adminNotifs.unread > 99
                                ? '99+'
                                : ($store.adminNotifs ? $store.adminNotifs.unread : 0)">
                </span>
            </button>
        </header>

        {{-- Desktop top bar --}}
        <header class="hidden lg:flex items-center justify-end h-24 px-8 bg-white border-b border-[#E8E0F0]
                       shrink-0 z-30">

            {{-- Bell Button (desktop) --}}
            <button
                id="admin-bell-btn"
                type="button"
                class="admin-bell-btn"
                @click.stop="$store.adminNotifs && $store.adminNotifs.toggle(); positionAdminPanel();"
                title="Notifications"
                aria-label="Open notifications">

                <i class="fas fa-bell"
                   :class="$store.adminNotifs && $store.adminNotifs.unread > 0 ? 'fa-shake' : ''"
                   style="font-size:20px; color:#7A3F91;
                          --fa-animation-duration:4s;
                          --fa-animation-iteration-count:infinite;
                          pointer-events:none;"></i>

                <span
                    x-show="$store.adminNotifs && $store.adminNotifs.unread > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-0"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="notif-ripple bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
                           bg-red-500 text-white text-[9px] font-black
                           flex items-center justify-center px-1 leading-none
                           shadow-md ring-2 ring-white"
                    x-text="$store.adminNotifs && $store.adminNotifs.unread > 99
                                ? '99+'
                                : ($store.adminNotifs ? $store.adminNotifs.unread : 0)">
                </span>
            </button>
        </header>

        {{-- Page content --}}
        <div class="flex-1 overflow-y-auto min-h-0 bg-[#F5F5F5] p-4 lg:p-8 no-scrollbar">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ADMIN NOTIFICATION PANEL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    id="admin-notif-panel"
    x-show="$store.adminNotifs && $store.adminNotifs.open"
    x-cloak
    x-effect="if ($store.adminNotifs && $store.adminNotifs.open) $nextTick(() => positionAdminPanel())"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
    @click.stop
    @contextmenu.prevent
    @copy.prevent
    class="bg-white rounded-2xl border border-[#E8E0F0] flex flex-col overflow-hidden admin-notif-no-select"
    style="
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
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
            <span x-show="$store.adminNotifs && $store.adminNotifs.unread > 0"
                  x-cloak
                  class="bg-red-500 text-white font-black px-2 py-0.5 rounded-full leading-none"
                  style="font-size:11px;"
                  x-text="$store.adminNotifs ? $store.adminNotifs.unread + ' new' : ''">
            </span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button"
                    x-show="$store.adminNotifs && $store.adminNotifs.unread > 0"
                    x-cloak
                    @click.stop="$store.adminNotifs && $store.adminNotifs.markAllRead()"
                    class="text-white/70 hover:text-white font-semibold hover:bg-white/10
                           rounded-lg px-2.5 py-1.5 transition"
                    style="font-size:11px;">
                Mark all read
            </button>
            <div class="admin-notif-close-wrap ml-1">
                <span class="admin-notif-close-tip">Close</span>
                <button type="button"
                        @click.stop="$store.adminNotifs && $store.adminNotifs.close()"
                        class="w-7 h-7 flex items-center justify-center rounded-lg
                               text-white/50 hover:text-white hover:bg-white/10 transition">
                    <i class="fas fa-xmark" style="font-size:14px;"></i>
                </button>
            </div>
        </div>
    </div>

    {{-- Sub-header --}}
    <div class="flex items-center justify-between px-[18px] py-[9px] border-b border-[#E0D8ED] shrink-0" style="background:#FFFFFF;">
        <span style="font-size:11px; font-weight:600; color:#7A3F91; letter-spacing:0.1em; text-transform:uppercase;">Recent Activity</span>
        <span x-show="$store.adminNotifs && $store.adminNotifs.items.length > 0"
              x-cloak
              style="font-size:11px; font-weight:700; color:#7A3F91; background:#F0E9F6; padding:2px 9px; border-radius:999px;"
              x-text="$store.adminNotifs ? $store.adminNotifs.items.length : 0">
        </span>
    </div>

    {{-- Delete toast — appears right below Recent Activity, quick fade --}}
    <div
        x-show="$store.adminNotifs && $store.adminNotifs.deleteToast.show"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
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
              x-text="$store.adminNotifs ? $store.adminNotifs.deleteToast.message : ''"></span>
    </div>

    {{-- Scrollable notification list --}}
    <div class="admin-notif-list-scroll overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">

        <template x-if="$store.adminNotifs && $store.adminNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F5F5F5;">
                    <i class="fas fa-bell-slash" style="font-size:28px;color:#D1D5DB;"></i>
                </div>
                <p class="font-bold text-[#333333]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#555555] mt-2 leading-relaxed" style="font-size:13px;">
                    User, employment, yearbook, job,<br>event, and course updates will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.adminNotifs">
            <template x-for="(notif, notifIdx) in $store.adminNotifs.items" :key="notif.id">
                <div
                    x-transition:leave="transition ease-in duration-250"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-full"
                    style="overflow: hidden;">
                    <div class="admin-notif-divider"
                         x-show="notif.read && notifIdx > 0 && !$store.adminNotifs.items[notifIdx - 1].read"
                         x-cloak>
                        <span class="admin-notif-divider-label">Already Read</span>
                    </div>
                <div
                    class="admin-notif-item flex items-start gap-4 px-5 py-4
                           border-b border-[#F5F5F5] last:border-b-0
                           transition-colors duration-150 select-none"
                    :class="notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#F8F5FD] hover:bg-[#F0E9FA]'"
                    @click.stop="
                        $store.adminNotifs.markRead(notif);
                        $store.adminNotifs.close();
                        if (notif.link_route) {
                            const url = window.__adminRouteMap[notif.link_route] || '/admin/dashboard';
                            window.Livewire ? Livewire.navigate(url) : (window.location.href = url);
                        }
                    ">

                    {{-- Icon (color-coded per notification type) --}}
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5"
                         :style="{
                             background: (
                                 notif.icon === 'chart-line' ? 'linear-gradient(135deg,#FDECD2,#FBDBAA)' :
                                 (notif.icon === 'book-open' ? 'linear-gradient(135deg,#D6ECFB,#B7DEF7)' :
                                 ((notif.icon === 'briefcase' || notif.icon === 'calendar-check') ? 'linear-gradient(135deg,#D6F3E7,#B6E8D2)' :
                                 'linear-gradient(135deg,#EDE9F8,#DDD5F0)'))
                             ),
                             color: (
                                 notif.icon === 'chart-line' ? '#B45309' :
                                 (notif.icon === 'book-open' ? '#0369A1' :
                                 ((notif.icon === 'briefcase' || notif.icon === 'calendar-check') ? '#047857' :
                                 '#7A3F91'))
                             )
                         }">
                        <i class="fas"
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

                                {{-- Count badge (only for collapsed/grouped types) --}}
                                <span
                                    x-show="Number(notif.count) > 1
                                            && !notif._isNewJob
                                            && !notif._isApprovedEvent
                                            && !notif._isCompletedEvent
                                            && !notif._isUserCreated
                                            && !notif._isUserToggled
                                            && !notif._isUserEmail
                                            && !notif._isUserUsername"
                                    x-cloak
                                    class="inline-flex items-center justify-center
                                           min-w-[22px] h-5 rounded-full px-1.5
                                           text-[10px] font-black text-white leading-none"
                                    style="background:#7A3F91;"
                                    x-text="'×' + Number(notif.count)">
                                </span>

                                {{-- NEW DIRECTOR badge (indigo) --}}
                                <span
                                    x-show="notif._isUserCreated && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#4f46e5,#3730a3);">
                                    NEW DIR
                                </span>

                                {{-- ACTIVATED badge (green) --}}
                                <span
                                    x-show="notif._isUserToggled && !notif.read && notif.icon === 'circle-check'"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    ACTIVATED
                                </span>

                                {{-- DEACTIVATED badge (red) --}}
                                <span
                                    x-show="notif._isUserToggled && !notif.read && notif.icon === 'ban'"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#dc2626,#b91c1c);">
                                    DEACTIVATED
                                </span>

                                {{-- EMAIL UPDATED badge (blue) --}}
                                <span
                                    x-show="notif._isUserEmail && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    EMAIL
                                </span>

                                {{-- USERNAME UPDATED badge (purple) --}}
                                <span
                                    x-show="notif._isUserUsername && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#7A3F91,#5e2f72);">
                                    USERNAME
                                </span>

                                {{-- NEW JOB badge (green) --}}
                                <span
                                    x-show="notif._isNewJob && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    NEW JOB
                                </span>

                                {{-- APPROVED EVENT badge (green) --}}
                                <span
                                    x-show="notif._isApprovedEvent && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    APPROVED
                                </span>

                                {{-- COMPLETED EVENT badge (teal) --}}
                                <span
                                    x-show="notif._isCompletedEvent && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#0d9488,#0f766e);">
                                    COMPLETED
                                </span>

                                {{-- User badge (generic grouped) --}}
                                <span
                                    x-show="notif.icon === 'users' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#7A3F91,#5A2D70);">
                                    USER
                                </span>

                                {{-- Employment badge --}}
                                <span
                                    x-show="notif.icon === 'chart-line' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#d97706,#b45309);">
                                    EMPLOYMENT
                                </span>

                                {{-- Yearbook badge --}}
                                <span
                                    x-show="notif.icon === 'book-open' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    YEARBOOK
                                </span>

                                {{-- Course badge --}}
                                <span
                                    x-show="notif.icon === 'clipboard-list' && notif.title === 'Course Update' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#7A3F91,#5A2D70);">
                                    COURSE
                                </span>
                            </div>

                            <span x-show="!notif.read" x-cloak
                                  class="notif-ripple w-2 h-2 rounded-full bg-red-500 shrink-0 shadow-sm mt-1 flex-shrink-0"></span>
                        </div>

                        <p class="text-[#333333] mt-1 leading-relaxed"
                           style="font-size:12px;
                                  display:-webkit-box;
                                  -webkit-line-clamp:2;
                                  -webkit-box-orient:vertical;
                                  overflow:hidden;"
                           x-text="notif.message">
                        </p>

                        {{-- Timestamp + delete (delete only shows once notif is 30+ days old) --}}
                        <div class="flex items-center justify-between gap-1 mt-2">
                            <span class="flex items-center gap-1">
                                <i class="fas fa-clock" style="font-size:10px;color:#333333;"></i>
                                <span style="font-size:11px;color:#333333;font-weight:500;"
                                      x-text="window.__adminFormatNotifTime(notif.created_at)">
                                </span>
                            </span>

                            <button type="button"
                                    class="admin-notif-delete-btn"
                                    x-show="notif.created_at && ((Date.now() - new Date(notif.created_at).getTime()) / 86400000) >= 30"
                                    x-cloak
                                    @click.stop="$store.adminNotifs && $store.adminNotifs.deleteNotif(notif)"
                                    aria-label="Delete notification">
                                <i class="fas fa-trash-can"></i>
                                <span class="admin-notif-delete-tooltip">Delete</span>
                            </button>
                        </div>
                    </div>

                </div>
                </div>
            </template>
        </template>
    </div>

    {{-- Panel Footer --}}
    <div class="px-5 py-3 border-t border-[#F0ECF8] text-center shrink-0" style="background:#FAFAFA;">
        <p style="font-size:13px;color:#333333;font-weight:600;
                  -webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;">
            Click a notification to view and mark as read
        </p>
    </div>
</div>

@livewireScripts

{{-- CLOSE ON OUTSIDE CLICK --}}
<div
    x-show="$store.adminNotifs && $store.adminNotifs.open"
    x-cloak
    @click="$store.adminNotifs && $store.adminNotifs.close()"
    class="fixed inset-0"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>