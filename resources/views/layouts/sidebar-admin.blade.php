<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>{{ config('app.name', 'Philcst') }} - Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles

    <style>
        /* ── Disable Livewire wire:navigate top progress bar (nprogress) ── */
        #nprogress {
            display: none !important;
        }

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

        /* ── Ripple / expanding wave effect for unread indicators ── */
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

        .notif-ripple {
            transition: transform 0.15s ease;
        }
        .admin-notif-item:hover .notif-ripple {
            transform: scale(1.6);
        }

        .admin-notif-item.is-loading > *:not(.admin-notif-item-loading-overlay) {
            filter: blur(4px);
            opacity: 0.5;
            pointer-events: none;
            user-select: none;
        }
        .admin-notif-item-loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
        }
        .admin-notif-item-spinner {
            font-size: 22px;
            color: #7A3F91;
        }

        /* Disable text selection inside notif panel */
        .admin-notif-no-select,
        .admin-notif-no-select * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Disable text selection across the whole sidebar */
        #admin-sidebar-aside,
        #admin-sidebar-aside * {
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
           SIDEBAR CORE
        ════════════════════════════════════════════════════════ */
        #admin-sidebar-aside {
            background-color: #FFFFFF;
            border-right: 1px solid #E8E0F0;
            transition:
                width 0.2s ease,
                min-width 0.2s ease,
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.25s ease,
                border-color 0.25s ease;
        }

        /* ── Kill transition on first paint / hard refresh ────────────
           Without this the sidebar animates from its default state to
           the saved (collapsed/expanded) state on every refresh — an
           ugly flash/slide. The no-transition class is removed in x-init
           after two rAF frames, so only user-triggered toggles get the
           smooth animation. */
        #admin-sidebar-aside.no-transition {
            transition: none !important;
        }

        /* ── Collapsible text (labels, section headers) ── */
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

        /* ════════════════════════════════════════════════════════
           DESKTOP — collapsed / expanded states
        ════════════════════════════════════════════════════════ */
        @media (min-width: 1024px) {
            /* Sidebar must stay perfectly still on desktop — transform is
               a mobile-only concept. Force it every time and drop the
               transition on that property so a stray `open` flip never
               produces a visible slide/jump on desktop. */
            #admin-sidebar-aside {
                transform: none !important;
                transition-property: width, min-width, background-color, opacity, border-color !important;
            }
            #admin-sidebar-aside.is-collapsed {
                width: 5rem !important;
                min-width: 5rem !important;
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
            #admin-sidebar-aside.is-collapsed .admin-nav-link.is-navigating .admin-nav-icon-wrap i.fa-solid {
                display: none !important;
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

            /* ── is-modal-hidden: shrink sidebar to zero when a modal is
               open on desktop so the overlay never peeks through the sidebar
               edge — same technique as the registrar sidebar. ── */
            #admin-sidebar-aside.is-modal-hidden {
                width: 0 !important;
                min-width: 0 !important;
                opacity: 0;
                pointer-events: none;
                overflow: hidden;
                border-right-color: transparent;
                transition: none !important;
            }
            #admin-sidebar-aside.is-modal-hidden .admin-collapsible-text {
                display: none !important;
            }
        }

        /* ── is-modal-hidden: on mobile, slide the sidebar fully off-screen
           when a modal is open so it never fights the modal overlay. ── */
        @media (max-width: 1023px) {
            #admin-sidebar-aside {
                box-shadow: 0 0 60px rgba(0,0,0,0.18);
            }
            #admin-sidebar-aside.is-modal-hidden {
                transform: translateX(-100%) !important;
                transition: none !important;
                pointer-events: none;
                box-shadow: none;
            }
        }

        /* ── Lock other nav links while one is navigating (same pattern as
           registrar sidebar) — everything except the clicked link is dimmed
           and inert so double-clicks / stray clicks are harmless. ── */
        #admin-sidebar-aside.is-navigating-any .admin-nav-link:not(.is-navigating) {
            pointer-events: none !important;
            opacity: 0.45 !important;
            filter: grayscale(0.3);
            cursor: default !important;
        }
        #admin-sidebar-aside.is-navigating-any .admin-collapse-icon-btn {
            pointer-events: none !important;
            opacity: 0.45 !important;
        }
        .admin-nav-link.is-navigating { cursor: wait !important; }

        /* ── Nav link click spinner — mirrors the registrar sidebar's
           approach. Shows a small spinner on the row while a page
           navigation is in flight for immediate feedback.
           Expanded sidebar: sits at the end of the row (active dot slot).
           Collapsed sidebar / mobile: centered on top of the icon chip. ── */
        .admin-nav-link { position: relative; }
        .admin-nav-icon-wrap { position: relative; }
        .admin-nav-link.is-navigating .admin-nav-icon-wrap {
            background: #F0F0F0 !important;
            color: #9CA3AF !important;
        }
        .admin-nav-spinner {
            flex-shrink: 0;
            margin-left: auto;
            font-size: 13px;
            color: #7A3F91;
            line-height: 1;
        }
        .admin-nav-spinner-icon-anchored { display: none; }

        #admin-sidebar-aside.is-collapsed .admin-nav-link.is-navigating > .admin-nav-spinner,
        .admin-nav-link.is-navigating > .admin-nav-spinner {
            display: none !important;
        }
        #admin-sidebar-aside.is-collapsed .admin-nav-link.is-navigating .admin-nav-spinner-icon-anchored,
        .admin-nav-link.is-navigating .admin-nav-spinner-icon-anchored {
            display: flex !important;
            align-items: center;
            justify-content: center;
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            font-size: 16px !important;
        }
        .admin-nav-spinner-icon-anchored .admin-nav-spinner {
            margin-left: 0;
        }

        @media (min-width: 1024px) {
            #admin-sidebar-aside:not(.is-collapsed) .admin-nav-link.is-navigating > .admin-nav-spinner {
                display: inline-block !important;
            }
            #admin-sidebar-aside:not(.is-collapsed) .admin-nav-link.is-navigating .admin-nav-spinner-icon-anchored {
                display: none !important;
            }
            #admin-sidebar-aside:not(.is-collapsed) .admin-nav-link.is-navigating .admin-nav-icon-wrap i.fa-solid {
                display: inline-block !important;
            }
        }

        /* Logout button */
        .admin-logout-btn {
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
            background: linear-gradient(135deg, #7A3F91, #6a3080);
            border: none;
            cursor: pointer;
            transition: opacity 0.2s ease, transform 0.15s ease;
        }
        .admin-logout-btn:hover   { opacity: 0.92; }
        .admin-logout-btn:active  { transform: scale(0.97); }

        @keyframes admLogoutDotBounce {
            0%, 80%, 100% { transform: translateY(0); opacity: 0.5; }
            40% { transform: translateY(-4px); opacity: 1; }
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
    //  GLOBAL "MODAL OPEN" STORE — same pattern as registrar sidebar.
    //  Livewire pages dispatch 'modal-opened' / 'modal-closed' so the
    //  sidebar can slide away and never fight the overlay.
    // ─────────────────────────────────────────────────────────────────────────
    document.addEventListener('alpine:init', function () {
        if (!Alpine.store('adminModal')) {
            Alpine.store('adminModal', { open: false });
        }
    });
    window.addEventListener('modal-opened', function () {
        var s = window.Alpine && Alpine.store('adminModal');
        if (s) s.open = true;
    });
    window.addEventListener('modal-closed', function () {
        var s = window.Alpine && Alpine.store('adminModal');
        if (s) s.open = false;
    });
    document.addEventListener('livewire:navigated', function () {
        var s = window.Alpine && Alpine.store('adminModal');
        if (s) s.open = false;
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  STORE FACTORY
    // ─────────────────────────────────────────────────────────────────────────
    window.__makeAdminNotifsStore = function () {
        return {
            open:       false,
            items:      [],
            _pollTimer: null,
            _deleting:  false,
            navigating: false,
            loadingId:  null,
            deleteToast: { show: false, message: '' },

            async init() {
                await this._fetch();
                this._startPolling();
            },

            _startPolling() {
                if (this._pollTimer) clearInterval(this._pollTimer);
                var self = this;
                this._pollTimer = setInterval(function () { self._fetch(); }, 1500);
            },

            async _fetch() {
                if (this._deleting)  return;
                if (this.navigating) return;
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

                        var isUserCreatedEvent  = rawDedup.startsWith('user-created::');
                        var isUserToggledEvent  = rawDedup.startsWith('user-toggled::');
                        var isUserEmailEvent    = rawDedup.startsWith('user-email::');
                        var isUserUsernameEvent = rawDedup.startsWith('user-username::');

                        // Extract user_id from ALL per-user dedup keys:
                        // user-created::{uid}
                        // user-toggled::{uid}::{minute}
                        // user-email::{uid}::{minute}
                        // user-username::{uid}::{minute}
                        // The uid is always the segment right after the first "::"
                        // so split('::')[1] works for all four formats.
                        var userIdFromDedup = (
                            isUserCreatedEvent  ||
                            isUserToggledEvent  ||
                            isUserEmailEvent    ||
                            isUserUsernameEvent
                        ) ? (rawDedup.split('::')[1] || null) : null;

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

                        var isNewJobEvent = rawDedup.startsWith('job-posted::');
                        var jobIdFromDedup = isNewJobEvent
                            ? (rawDedup.split('::')[1] || null)
                            : null;

                        var isEventStatusRow = rawDedup.startsWith('event-status::');
                        var isApprovedEvent = isEventStatusRow
                            ? (n.title === 'Event Approved')
                            : rawDedup.startsWith('event-approved::');
                        var isCompletedEvent = isEventStatusRow
                            ? (n.title === 'Event Completed')
                            : rawDedup.startsWith('event-completed::');
                        var eventIdFromDedup = (isApprovedEvent || isCompletedEvent)
                            ? (rawDedup.split('::')[1] || null)
                            : null;

                        var isCourseEvent = (
                            rawDedup.startsWith('course::') ||
                            (n.icon === 'clipboard-list' && n.title === 'Course Update')
                        );

                        var groupKey;
                        if (isUserCreatedEvent)      { groupKey = rawDedup; }
                        else if (isUserToggledEvent) { groupKey = rawDedup; }
                        else if (isUserEmailEvent)   { groupKey = rawDedup; }
                        else if (isUserUsernameEvent){ groupKey = rawDedup; }
                        else if (isUserEvent)         { groupKey = 'user_day::' + day; }
                        else if (isEmploymentEvent)  { groupKey = 'employment_day::' + day; }
                        else if (isYearbookEvent)    { groupKey = 'yearbook_day::' + day; }
                        else if (isNewJobEvent)      { groupKey = rawDedup; }
                        else if (isApprovedEvent)    { groupKey = rawDedup; }
                        else if (isCompletedEvent)   { groupKey = rawDedup; }
                        else if (isCourseEvent)      { groupKey = rawDedup; }
                        else { groupKey = (n.title || '') + '::' + day + '::' + (rawDedup || n.id); }

                        if (map.has(groupKey)) {
                            var g = map.get(groupKey);
                            g.count = (g.count || 1) + (n.count || 1);
                            if (!n.read) g.read = false;
                            g._ids.push(n.id);

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
                                _isNewJob:         isNewJobEvent,
                                _isApprovedEvent:  isApprovedEvent,
                                _isCompletedEvent: isCompletedEvent,
                                _isUserCreated:    isUserCreatedEvent,
                                _isUserToggled:    isUserToggledEvent,
                                _isUserEmail:      isUserEmailEvent,
                                _isUserUsername:   isUserUsernameEvent,
                                job_id:            n.job_id || jobIdFromDedup || null,
                                event_id:          n.event_id || eventIdFromDedup || null,
                                user_id:           n.user_id || userIdFromDedup || null,
                                link_route:        isNewJobEvent ? 'job.posts'
                                                  : (isApprovedEvent || isCompletedEvent) ? 'events'
                                                  : n.link_route,
                            }));
                        }
                    });

                var grouped = Array.from(map.values());
                var unreadGroup = grouped.filter(function (n) { return !n.read; });
                var readGroup   = grouped.filter(function (n) { return n.read; });
                return unreadGroup.concat(readGroup);
            },

            get unread() {
                return this.items.filter(function (n) { return !n.read; }).length;
            },

            toggle() {
                this.open = !this.open;
                if (this.open) this._fetch();
            },
            close()  { this.open = false; },

            async markRead(item) {
                if (item.read) return;
                var ids     = Array.isArray(item._ids) ? item._ids : [item.id];
                var csrf    = document.querySelector('meta[name="csrf-token"]').content;
                var allOk   = true;
                for (var i = 0; i < ids.length; i++) {
                    try {
                        var res = await window.fetch('/admin/notifications/' + ids[i] + '/read', {
                            method: 'PATCH',
                            headers: {
                                'X-CSRF-TOKEN':     csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            }
                        });
                        if (!res.ok) allOk = false;
                    } catch (e) {
                        allOk = false;
                    }
                }
                if (allOk) {
                    item.read = true;
                }
            },

            async openNotif(item) {
                this.navigating = true;
                this.loadingId  = item.id;
                var clearedByNav = false;
                try {
                    await this.markRead(item);
                    clearedByNav = this._goToTarget(item);
                } finally {
                    if (!clearedByNav) {
                        this.navigating = false;
                        this.loadingId  = null;
                    }
                }
            },

            _goToTarget(item) {
                if (!item.link_route) return false;

                var self = this;
                var url  = window.__adminRouteMap[item.link_route] || '/admin/dashboard';
                if (item.link_route === 'job.posts' && item.job_id) {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + 'highlight_job=' + encodeURIComponent(item.job_id);
                } else if (item.link_route === 'events' && item.event_id) {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + 'highlight_event=' + encodeURIComponent(item.event_id);
                } else if (item.link_route === 'user.management' && item.user_id &&
                           (item._isUserEmail || item._isUserCreated || item._isUserToggled || item._isUserUsername)) {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + 'highlight_user=' + encodeURIComponent(item.user_id);
                }

                var targetPath    = url.split('?')[0];
                var isSameLocation = window.location.pathname === targetPath;

                if (isSameLocation && item.link_route === 'job.posts' && item.job_id && window.Livewire) {
                    Livewire.dispatch('open-view-job', { id: Number(item.job_id) });
                    setTimeout(function () {
                        self.navigating = false;
                        self.loadingId  = null;
                        self.open       = false;
                    }, 400);
                    return true;
                } else if (isSameLocation && item.link_route === 'events' && item.event_id && window.Livewire) {
                    Livewire.dispatch('open-view-event', { id: Number(item.event_id) });
                    setTimeout(function () {
                        self.navigating = false;
                        self.loadingId  = null;
                        self.open       = false;
                    }, 400);
                    return true;
                } else if (isSameLocation && item.link_route === 'user.management' && item.user_id &&
                           (item._isUserEmail || item._isUserCreated || item._isUserToggled || item._isUserUsername) &&
                           window.Livewire) {
                    // Already on User Management — dispatch directly to the
                    // mounted component so View Details opens immediately with
                    // the right user, no page flash. Works for every per-user
                    // notif type: New Director Created, Account Activated/
                    // Deactivated, Email Updated, and Username Updated.
                    Livewire.dispatch('open-view-user', { id: Number(item.user_id) });
                    setTimeout(function () {
                        self.navigating = false;
                        self.loadingId  = null;
                        self.open       = false;
                    }, 400);
                    return true;
                } else if (isSameLocation) {
                    setTimeout(function () {
                        self.navigating = false;
                        self.loadingId  = null;
                        self.open       = false;
                    }, 400);
                    return true;
                } else if (window.Livewire) {
                    Livewire.navigate(url);
                    return true;
                } else {
                    window.location.href = url;
                    return true;
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

            async deleteNotif(item) {
                var ids = item._ids || [item.id];
                var self = this;
                this._deleting = true;
                this._showDeleteToast('Notification deleted');

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
    document.addEventListener('livewire:navigated', function () {
        setTimeout(function () {
            if (!window.Alpine || typeof Alpine.store !== 'function') return;

            var onAdminPage = !!(document.getElementById('admin-bell-btn') || document.getElementById('admin-bell-btn-mobile'));
            var s = Alpine.store('adminNotifs');

            if (!onAdminPage) {
                if (s) {
                    if (s._pollTimer) clearInterval(s._pollTimer);
                    s._pollTimer = null;
                    s.open = false;
                    s.navigating = false;
                    s.loadingId  = null;
                }
                return;
            }

            if (s) {
                if (s._pollTimer) clearInterval(s._pollTimer);
                s._pollTimer = null;
                s.open = false;
                s.navigating = false;
                s.loadingId  = null;
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
    //  PANEL POSITIONING — desktop only (mobile is handled entirely by CSS)
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
    //  TIMESTAMP HELPER
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
                link_label: 'View Job Posts',
                dedup_key:  'job-posted::' + d.id,
            });
        });

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
        sidebarCollapsed: localStorage.getItem('admin_sidebar_collapsed') === '1',
        sidebarSettled: false,
        navClickedRoute: null,
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
        }
    }"
    x-init="
        $watch('sidebarCollapsed', function (val) { localStorage.setItem('admin_sidebar_collapsed', val ? '1' : '0'); });
        requestAnimationFrame(function () { requestAnimationFrame(function () { sidebarSettled = true; }); });
    "
    @click="$store.adminNotifs && $store.adminNotifs.open && $store.adminNotifs.close()"
    @@livewire:navigated.window="navClickedRoute = null; open = false; sidebarSettled = false; requestAnimationFrame(function () { requestAnimationFrame(function () { sidebarSettled = true; }); });">

@php
    $authAdmin = auth()->user();
@endphp

<div class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

    {{-- Mobile overlay --}}
    <div
        x-show="open && !($store.adminModal && $store.adminModal.open)"
        x-cloak
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
    {{-- x-bind:class is evaluated as soon as this element is parsed. "is-collapsed"
         is seeded synchronously from localStorage so on desktop the narrow width is
         already correct on first paint — no flash of the wide sidebar snapping narrow.
         "no-transition" suppresses the animation until Alpine finishes initializing,
         preventing the jarring slide-in on hard refresh. --}}
    <aside
        id="admin-sidebar-aside"
        x-bind:class="{
            'translate-x-0':     open && !($store.adminModal && $store.adminModal.open),
            'is-collapsed':      sidebarCollapsed,
            'is-modal-hidden':   ($store.adminModal && $store.adminModal.open),
            'no-transition':     !sidebarSettled,
            'is-navigating-any': navClickedRoute !== null
        }"
        class="fixed inset-y-0 left-0 w-72 min-w-[18rem] transform -translate-x-full
               transition-all duration-300
               shadow-2xl lg:!translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="z-index: 9991;">

        {{-- Sidebar header --}}
        <div class="admin-sidebar-header flex items-center justify-between h-24 px-5 border-b border-[#E8E0F0] shrink-0">

            <div class="admin-collapsible-text text-left min-w-0 flex-1 pr-2">
                <h1 class="text-2xl font-semibold tracking-tighter uppercase text-[#333333] leading-tight">
                    Admin<span class="font-semibold opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-semibold">
                    System Administration
                </p>
            </div>

            {{-- Mobile close --}}
            <button @click="open = false"
                    class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors ml-2 shrink-0">
                <i class="fa-solid fa-circle-xmark text-xl"></i>
            </button>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto no-scrollbar">

            <div class="admin-nav-section-row">
                <p class="admin-section-label admin-collapsible-text">MAIN MENU</p>

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
                        'bg'      => '#EDE9F8',
                    ],
                    [
                        'route'   => 'user.management',
                        'icon'    => 'users-gear',
                        'label'   => 'User Management',
                        'pattern' => 'user/management*',
                        'color'   => '#7A3F91',
                        'bg'      => '#EDE9F8',
                    ],
                    [
                        'route'   => 'employment.tracking',
                        'icon'    => 'chart-line',
                        'label'   => 'Employment Tracking',
                        'pattern' => 'employment/tracking*',
                        'color'   => '#D97706',
                        'bg'      => '#FEF3C7',
                    ],
                    [
                        'route'   => 'admin.yearbook',
                        'icon'    => 'book-open',
                        'label'   => 'Alumni Yearbook',
                        'pattern' => 'yearbook*',
                        'color'   => '#0284C7',
                        'bg'      => '#DBEAFE',
                    ],
                    [
                        'route'   => 'job.posts',
                        'icon'    => 'briefcase',
                        'label'   => 'Job Postings',
                        'pattern' => 'job/posts*',
                        'color'   => '#059669',
                        'bg'      => '#DCFCE7',
                    ],
                    [
                        'route'   => 'events',
                        'icon'    => 'calendar-check',
                        'label'   => 'Events & Activities',
                        'pattern' => 'events*',
                        'color'   => '#059669',
                        'bg'      => '#DCFCE7',
                    ],
                    [
                        'route'   => 'course',
                        'icon'    => 'graduation-cap',
                        'label'   => 'Programs',
                        'pattern' => 'course*',
                        'color'   => '#7A3F91',
                        'bg'      => '#EDE9F8',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php $isActive = request()->is($link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   title="{{ $link['label'] }}"
                   @click="if (navClickedRoute !== null) { $event.preventDefault(); return; } navClickedRoute = '{{ $link['route'] }}'; if (window.innerWidth < 1024) open = false;"
                   :class="{ 'is-navigating': navClickedRoute === '{{ $link['route'] }}' }"
                   class="admin-nav-link flex items-center px-4 py-3 transition-all duration-200 rounded-xl group
                          {{ $isActive
                              ? 'bg-[#F5F5F5] border border-[#E8E0F0] shadow-sm'
                              : 'hover:bg-[#F9F7FC]' }}">

                    <div class="admin-nav-icon-wrap w-10 h-10 flex items-center justify-center rounded-lg
                                transition-transform duration-200 group-hover:scale-110 shrink-0 mr-4"
                         style="background-color:{{ $isActive ? $link['color'].'22' : $link['bg'] }};color:{{ $link['color'] }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"
                           x-show="!(navClickedRoute === '{{ $link['route'] }}' && (sidebarCollapsed || window.innerWidth < 1024))"></i>
                        <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                            <span class="admin-nav-spinner-icon-anchored">
                                <i class="fas fa-spinner fa-spin admin-nav-spinner"></i>
                            </span>
                        </template>
                    </div>

                    <span class="admin-collapsible-text font-medium tracking-wide flex-1
                                 {{ $isActive ? 'font-semibold' : 'text-[#333333]' }}"
                          style="{{ $isActive ? 'color:'.$link['color'].';' : '' }}{{ in_array($link['route'], ['employment.tracking','events']) ? 'font-size:13.5px;' : '' }}">
                        {{ $link['label'] }}
                    </span>

                    <template x-if="navClickedRoute === '{{ $link['route'] }}'">
                        <i class="fas fa-spinner fa-spin admin-nav-spinner"></i>
                    </template>

                    @if($isActive)
                        <template x-if="navClickedRoute !== '{{ $link['route'] }}'">
                            <span class="admin-nav-active-dot ml-auto w-1.5 h-5 rounded-full shrink-0 opacity-70"
                                  style="background:{{ $link['color'] }};"></span>
                        </template>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Admin notification poller --}}
        <div wire:ignore.self x-data="{ pollingActive: true }" x-on:stop-admin-polling.window="pollingActive = false">
            <template x-if="pollingActive">
                @livewire('admin.admin-notif-poller')
            </template>
        </div>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <a href="{{ route('logout') }}"
               wire:navigate
               title="Logout"
               x-data="{ loggingOut: false }"
               @click="
                   loggingOut = true;
                   window.dispatchEvent(new CustomEvent('stop-admin-polling'));
                   if (window.__safeAdminNotifsStore) {
                       var s = window.__safeAdminNotifsStore();
                       if (s) {
                           if (s._pollTimer) { clearInterval(s._pollTimer); s._pollTimer = null; }
                           s.open = false;
                       }
                   }
               "
               class="admin-logout-btn">
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
        </div>
    </aside>

    {{-- ══ MAIN CONTENT ══ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0 relative">

        {{-- Click-eating overlay while nav is in-flight — prevents stray
             clicks on the bell or page content from interrupting navigation.
             Scoped inside <main> so it never covers the sidebar. --}}
        <div x-show="navClickedRoute !== null"
             x-cloak
             class="absolute inset-0 z-40 cursor-wait"
             style="background: transparent;"
             @click.stop.prevent=""
             aria-hidden="true">
        </div>

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-[#E8E0F0]
                       lg:hidden shrink-0 z-30">
            <button @click.stop="open = !open"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 bg-[#7A3F91] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''"
                          class="w-full h-0.5 bg-[#7A3F91] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 bg-[#7A3F91] transition-all duration-300 origin-center"></span>
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

    {{-- Delete toast --}}
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
                    :class="[
                        notif.read ? 'bg-white hover:bg-[#FAFAFA]' : 'bg-[#F8F5FD] hover:bg-[#F0E9FA]',
                        ($store.adminNotifs.navigating && $store.adminNotifs.loadingId === notif.id) ? 'is-loading' : ''
                    ]"
                    @click.stop="$store.adminNotifs.openNotif(notif)">

                    <template x-if="$store.adminNotifs.navigating && $store.adminNotifs.loadingId === notif.id">
                        <div class="admin-notif-item-loading-overlay">
                            <i class="fas fa-spinner fa-spin admin-notif-item-spinner"></i>
                        </div>
                    </template>

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

                                {{-- NEW DIRECTOR badge --}}
                                <span x-show="notif._isUserCreated && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#4f46e5,#3730a3);">
                                    NEW DIR
                                </span>

                                {{-- ACTIVATED badge --}}
                                <span x-show="notif._isUserToggled && !notif.read && notif.icon === 'circle-check'" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#059669,#047857);">
                                    ACTIVATED
                                </span>

                                {{-- DEACTIVATED badge --}}
                                <span x-show="notif._isUserToggled && !notif.read && notif.icon === 'ban'" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#dc2626,#b91c1c);">
                                    DEACTIVATED
                                </span>

                                {{-- EMAIL UPDATED badge --}}
                                <span x-show="notif._isUserEmail && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    EMAIL
                                </span>

                                {{-- USERNAME UPDATED badge --}}
                                <span x-show="notif._isUserUsername && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#7A3F91,#5e2f72);">
                                    USERNAME
                                </span>

                                {{-- NEW JOB badge --}}
                                <span x-show="notif._isNewJob && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#059669,#047857);">
                                    NEW JOB
                                </span>

                                {{-- APPROVED EVENT badge --}}
                                <span x-show="notif._isApprovedEvent && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#059669,#047857);">
                                    APPROVED
                                </span>

                                {{-- COMPLETED EVENT badge --}}
                                <span x-show="notif._isCompletedEvent && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#0d9488,#0f766e);">
                                    COMPLETED
                                </span>

                                {{-- USER badge (generic grouped) --}}
                                <span x-show="notif.icon === 'users' && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#7A3F91,#5A2D70);">
                                    USER
                                </span>

                                {{-- Employment badge --}}
                                <span x-show="notif.icon === 'chart-line' && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#d97706,#b45309);">
                                    EMPLOYMENT
                                </span>

                                {{-- Yearbook badge --}}
                                <span x-show="notif.icon === 'book-open' && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    YEARBOOK
                                </span>

                                {{-- Course badge --}}
                                <span x-show="notif.icon === 'clipboard-list' && notif.title === 'Course Update' && !notif.read" x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;background:linear-gradient(135deg,#7A3F91,#5A2D70);">
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

                        {{-- Timestamp + delete --}}
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