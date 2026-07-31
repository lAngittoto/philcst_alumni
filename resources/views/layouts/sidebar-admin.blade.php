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
        .admin-notif-item { cursor: pointer; position: relative; }
        .admin-notif-hover-label {
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
        .admin-notif-item:hover .admin-notif-hover-label { opacity: 1; }

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
        'audit.logs':           '/audit/logs',
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

                        // Generic user management update (still groups by day)
                        var isUserEvent = (
                            rawDedup.startsWith('user-management::') ||
                            n.icon === 'users'
                        ) && !isUserCreatedEvent && !isUserToggledEvent && !isUserEmailEvent;

                        var isEmploymentEvent = (
                            rawDedup.startsWith('employment-tracking::') ||
                            n.icon === 'chart-line'
                        );
                        var isYearbookEvent = (
                            rawDedup.startsWith('yearbook::') ||
                            n.icon === 'book-open'
                        );

                        // NEW JOB POST — dedup prefix: job-posted::
                        var isNewJobEvent = rawDedup.startsWith('job-posted::');

                        // Job edits/updates — dedup prefix: job-management::
                        var isJobUpdateEvent = (
                            rawDedup.startsWith('job-management::') ||
                            (!isNewJobEvent && n.icon === 'briefcase')
                        );

                        // NEW EVENT SUBMITTED (pending review) — dedup prefix: event-pending::
                        var isPendingEvent = rawDedup.startsWith('event-pending::');

                        // EVENT APPROVED — dedup prefix: event-approved::
                        var isApprovedEvent = rawDedup.startsWith('event-approved::');

                        var isEventEvent = (
                            rawDedup.startsWith('event-management::') ||
                            rawDedup.startsWith('event-announced::')  ||
                            n.icon === 'calendar-check' ||
                            n.icon === 'calendar'
                        );
                        var isAuditEvent = (
                            rawDedup.startsWith('audit-log::') ||
                            (n.icon === 'clipboard-list' && n.title === 'Audit Log Update')
                        );
                        var isCourseEvent = (
                            rawDedup.startsWith('course::') ||
                            (n.icon === 'clipboard-list' && n.title === 'Course Update')
                        );

                        // ── group key ──────────────────────────────────────
                        var groupKey;
                        if (isUserCreatedEvent)      { groupKey = rawDedup; }           // per-creation, no collapsing
                        else if (isUserToggledEvent) { groupKey = rawDedup; }           // per-toggle, no collapsing
                        else if (isUserEmailEvent)   { groupKey = rawDedup; }           // per-email-update, no collapsing
                        else if (isUserEvent)         { groupKey = 'user_day::' + day; }
                        else if (isEmploymentEvent)  { groupKey = 'employment_day::' + day; }
                        else if (isYearbookEvent)    { groupKey = 'yearbook_day::' + day; }
                        else if (isNewJobEvent)      { groupKey = rawDedup; }
                        else if (isJobUpdateEvent)   { groupKey = 'job_update_day::' + day; }
                        else if (isPendingEvent)     { groupKey = rawDedup; }
                        else if (isApprovedEvent)    { groupKey = rawDedup; }
                        else if (isEventEvent)       { groupKey = 'event_day::' + day; }
                        else if (isAuditEvent)       { groupKey = 'audit_day::' + day; }
                        else if (isCourseEvent)      { groupKey = 'course_day::' + day; }
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
                            else if (isJobUpdateEvent)   { g.title = 'Job Posting Update'; }
                            else if (isEventEvent)       { g.title = 'Event Update'; }
                            else if (isAuditEvent)       { g.title = 'Audit Log Update'; }
                            else if (isCourseEvent)      { g.title = 'Course Update'; }
                        } else {
                            map.set(groupKey, Object.assign({}, n, {
                                count: n.count || 1,
                                _ids:  [n.id],
                                title: isUserCreatedEvent  ? (n.title || 'New Director Created')
                                     : isUserToggledEvent  ? (n.title || 'Account Status Changed')
                                     : isUserEmailEvent    ? (n.title || 'Alumni Email Updated')
                                     : isUserEvent         ? (n.title || 'User Management Update')
                                     : isEmploymentEvent   ? (n.title || 'Employment Tracking Update')
                                     : isYearbookEvent     ? (n.title || 'Yearbook Update')
                                     : isNewJobEvent       ? (n.title || 'New Job Posting')
                                     : isJobUpdateEvent    ? (n.title || 'Job Posting Update')
                                     : isPendingEvent      ? (n.title || 'New Event Submitted')
                                     : isApprovedEvent     ? (n.title || 'Event Approved')
                                     : isEventEvent        ? (n.title || 'Event Update')
                                     : isAuditEvent        ? (n.title || 'Audit Log Update')
                                     : isCourseEvent       ? (n.title || 'Course Update')
                                     : n.title,
                                icon:  isUserCreatedEvent  ? 'user-tie'
                                     : isUserToggledEvent  ? 'circle-check'
                                     : isUserEmailEvent    ? 'envelope'
                                     : isUserEvent         ? 'users'
                                     : isEmploymentEvent   ? 'chart-line'
                                     : isYearbookEvent     ? 'book-open'
                                     : isNewJobEvent       ? 'briefcase'
                                     : isJobUpdateEvent    ? 'briefcase'
                                     : isPendingEvent      ? 'calendar-day'
                                     : isApprovedEvent     ? 'calendar-check'
                                     : isEventEvent        ? 'calendar-check'
                                     : isAuditEvent        ? 'clipboard-list'
                                     : isCourseEvent       ? 'clipboard-list'
                                     : (n.icon || 'bell'),
                                // Carry flags so the template knows what kind of row this is
                                _isNewJob:        isNewJobEvent,
                                _isPendingEvent:  isPendingEvent,
                                _isApprovedEvent: isApprovedEvent,
                                _isUserCreated:   isUserCreatedEvent,
                                _isUserToggled:   isUserToggledEvent,
                                _isUserEmail:     isUserEmailEvent,
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
                            await window.fetch('/admin/notifications/' + ids[j] + '/read', {
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
            var s = Alpine.store('adminNotifs');
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
    //  PANEL POSITIONING
    // ─────────────────────────────────────────────────────────────────────────
    function positionAdminPanel() {
        var isDesktop = window.innerWidth >= 1024;
        var btn   = document.getElementById(isDesktop ? 'admin-bell-btn' : 'admin-bell-btn-mobile');
        if (!btn) btn = document.getElementById('admin-bell-btn') || document.getElementById('admin-bell-btn-mobile');
        var panel = document.getElementById('admin-notif-panel');
        var aside = document.querySelector('aside');
        if (!btn || !panel) return;
        var btnRect = btn.getBoundingClientRect();
        if (aside && isDesktop) {
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
    window.positionAdminPanel = positionAdminPanel;

    window.addEventListener('resize', function () {
        var s = window.__safeAdminNotifsStore();
        if (s && s.open) positionAdminPanel();
    });

    // Cursor-following tooltip
    document.addEventListener('mousemove', function (e) {
        var target = e.target;
        if (!target || typeof target.closest !== 'function') return;
        var item = target.closest('.admin-notif-item');
        if (!item) return;
        var label = item.querySelector('.admin-notif-hover-label');
        if (!label) return;
        label.style.left = (e.clientX + 14) + 'px';
        label.style.top  = (e.clientY + 14) + 'px';
    });

    // ─────────────────────────────────────────────────────────────────────────
    //  SIDEBAR SMART MARK-READ
    // ─────────────────────────────────────────────────────────────────────────
    window.__adminSidebarNotifsMarkRead = function (routeName) {
        var s = window.__safeAdminNotifsStore();
        if (!s) return;
        s.markReadByRoute(routeName);
    };

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
    //  job-posted::   = brand-new job post      → separate row per job
    //  job-management:: = edits/updates         → grouped by day
    //  event-pending::  = new event submitted   → separate row per event
    //  event-approved:: = event approved        → separate row per event
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
            var d = e.detail;
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
            var d = e.detail;
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

        // ── ALUMNI EMAIL UPDATED ──────────────────────────────────────────────
        // Fired by manage-users.blade.php via __admin-user-email-rich.
        // dedup_key: user-email::{uid}::{minute} — separate row per update.
        // Message: "Full Name email has been updated. New email: newemail@x.com"
        window.addEventListener('__admin-user-email-rich', function (e) {
            var d = e.detail;
            if (!d || !d.uid) return;
            _saveAdminNotif({
                icon:       'envelope',
                title:      'Alumni Email Updated',
                message:    (d.name || 'An alumni') + ' email has been updated.'
                            + (d.email ? ' New email: ' + d.email : ''),
                link_route: 'user.management',
                link_label: 'View Users',
                dedup_key:  'user-email::' + d.uid + '::' + Math.floor(Date.now() / 60000),
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

        // ── job UPDATE (edits only — NOT new posts) ─────────────────────────
        window.addEventListener('admin-job-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'briefcase',
                title:      'Job Posting Update',
                message:    (d.title || 'A job posting') + ' has been updated.',
                link_route: 'job.posts',
                link_label: 'View Jobs',
                dedup_key:  'job-management::' + (d.id || Math.floor(Date.now() / 60000)),
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

        // ── NEW EVENT SUBMITTED (pending review) ────────────────────────────
        window.addEventListener('__admin-event-pending-rich', function (e) {
            var d = e.detail;
            if (!d || !d.id) return;
            _saveAdminNotif({
                icon:       'calendar-day',
                title:      'New Event Submitted',
                message:    d.message,
                link_route: 'events',
                link_label: 'View Events',
                dedup_key:  'event-pending::' + d.id,
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

        // ── event (generic updates) ──────────────────────────────────────────
        window.addEventListener('admin-event-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'calendar-check',
                title:      'Event Update',
                message:    (d.title || 'An event') + ' has been updated.',
                link_route: 'events',
                link_label: 'View Events',
                dedup_key:  'event-management::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── audit ────────────────────────────────────────────────────────────
        window.addEventListener('admin-audit-logged', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'clipboard-list',
                title:      'Audit Log Update',
                message:    (d.action || 'A new action') + ' was recorded in the audit log.',
                link_route: 'audit.logs',
                link_label: 'View Audit Logs',
                dedup_key:  'audit-log::' + (d.id || Math.floor(Date.now() / 60000)),
            });
        });

        // ── course ────────────────────────────────────────────────────────────
        window.addEventListener('admin-course-updated', function (e) {
            var d = _adminDetail(e);
            _saveAdminNotif({
                icon:       'clipboard-list',
                title:      'Course Update',
                message:    _courseChangeMessage(d),
                link_route: 'course',
                link_label: 'View Courses',
                dedup_key:  'course::' + (d.id || Math.floor(Date.now() / 60000)),
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
    x-data="{ open: false }"
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
                        'route'   => 'audit.logs',
                        'icon'    => 'clipboard-list',
                        'label'   => 'Audit Logs',
                        'pattern' => 'audit/logs*',
                        'color'   => '#374151',
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
                   @click="window.__adminSidebarNotifsMarkRead('{{ $link['route'] }}')"
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group
                          {{ $isActive
                              ? 'bg-[#F5F5F5] border border-[#E8E0F0] shadow-md'
                              : 'hover:bg-[#F9F7FC]' }}">

                    <div class="w-10 h-10 flex items-center justify-center rounded-lg
                                transition-transform duration-300 group-hover:scale-110 shrink-0 mr-4"
                         style="background-color:{{ $isActive ? $link['color'].'1F' : '#F9F7FC' }};color:{{ $link['color'] }};">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>

                    <span class="font-medium tracking-wide flex-1
                                 {{ $isActive ? 'font-semibold' : 'text-[#333333]' }}"
                          style="{{ $isActive ? 'color:'.$link['color'].';' : '' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <span class="ml-auto w-1.5 h-5 rounded-full shrink-0 opacity-70"
                              style="background:{{ $link['color'] }};"></span>
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
                        style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
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
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
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
                    class="bell-badge absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full
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

    {{-- Scrollable notification list --}}
    <div class="overflow-y-auto no-scrollbar flex-1" style="max-height: 460px;">

        <template x-if="$store.adminNotifs && $store.adminNotifs.items.length === 0">
            <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                     style="background:#F5F5F5;">
                    <i class="fas fa-bell-slash" style="font-size:28px;color:#D1D5DB;"></i>
                </div>
                <p class="font-bold text-[#888888]" style="font-size:15px;">No notifications yet</p>
                <p class="text-[#BBBBBB] mt-2 leading-relaxed" style="font-size:13px;">
                    User, employment, yearbook, job,<br>event, audit, and course updates will appear here.
                </p>
            </div>
        </template>

        <template x-if="$store.adminNotifs">
            <template x-for="notif in $store.adminNotifs.items" :key="notif.id">
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
                                 (notif.title === 'Audit Log Update' ? 'linear-gradient(135deg,#E5E7EB,#D1D5DB)' :
                                 'linear-gradient(135deg,#EDE9F8,#DDD5F0)')))
                             ),
                             color: (
                                 notif.icon === 'chart-line' ? '#B45309' :
                                 (notif.icon === 'book-open' ? '#0369A1' :
                                 ((notif.icon === 'briefcase' || notif.icon === 'calendar-check') ? '#047857' :
                                 (notif.title === 'Audit Log Update' ? '#374151' :
                                 '#7A3F91')))
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
                                            && !notif._isPendingEvent
                                            && !notif._isApprovedEvent
                                            && !notif._isUserCreated
                                            && !notif._isUserToggled
                                            && !notif._isUserEmail"
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

                                {{-- NEW JOB badge (green) --}}
                                <span
                                    x-show="notif._isNewJob && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    NEW JOB
                                </span>

                                {{-- PENDING EVENT badge (amber) --}}
                                <span
                                    x-show="notif._isPendingEvent && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#d97706,#b45309);">
                                    PENDING
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

                                {{-- JOB UPDATE badge (blue) --}}
                                <span
                                    x-show="notif.icon === 'briefcase' && !notif._isNewJob && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#0284c7,#0369a1);">
                                    JOB
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

                                {{-- Event badge (generic) --}}
                                <span
                                    x-show="notif.icon === 'calendar-check' && !notif._isApprovedEvent && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#059669,#047857);">
                                    EVENT
                                </span>

                                {{-- Audit badge --}}
                                <span
                                    x-show="notif.icon === 'clipboard-list' && notif.title === 'Audit Log Update' && !notif.read"
                                    x-cloak
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-white leading-none"
                                    style="font-size:9px;font-weight:800;letter-spacing:0.06em;
                                           background:linear-gradient(135deg,#374151,#1f2937);">
                                    AUDIT
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

                        {{-- Timestamp --}}
                        <div class="flex items-center gap-1 mt-2">
                            <i class="fas fa-clock" style="font-size:10px;color:#CCCCCC;"></i>
                            <span style="font-size:11px;color:#AAAAAA;font-weight:500;"
                                  x-text="window.__adminFormatNotifTime(notif.created_at)">
                            </span>
                        </div>
                    </div>

                    <span class="admin-notif-hover-label">
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
    x-show="$store.adminNotifs && $store.adminNotifs.open"
    x-cloak
    @click="$store.adminNotifs && $store.adminNotifs.close()"
    class="fixed inset-0"
    style="z-index: 9998; background: transparent;">
</div>

</body>
</html>