{{-- resources/views/livewire/admin/admin-notif-poller.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {

    // Kept only to gate mount() to actual admin users — NOT used in the
    // admin_notifications query, since that table has no user_id column
    // (it's a single global feed shared by every admin, not scoped
    // per-account).
    public bool $isAdmin = false;

    // Snapshot pushed into the JS store the instant this component
    // mounts — this is what makes the bell show notifications on the
    // very first render instead of waiting for the client-side JS
    // fetch() to kick in after the page has already painted.
    public array $preloadItems = [];

    // ─────────────────────────────────────────────────────────────────────
    // Mount — runs server-side as part of the SAME response that renders
    // the sidebar, so the notification data is already embedded in the
    // HTML/JS payload the browser gets. No round trip needed before the
    // bell can show something.
    //
    // NOTE: this does NOT create any new notifications. Job postings,
    // event approvals, etc. already write to admin_notifications
    // synchronously at the moment they happen (see manage-job_blade.php /
    // job-management_blade.php's writeAdminNotif()). This component's
    // only job is to read what's already there, immediately, instead of
    // making the browser wait for its first poll tick.
    //
    // admin_notifications has NO user_id column — it's one shared feed
    // for every admin account (see the migration: id, icon, title,
    // message, link_route, link_label, dedup_key, read, read_at,
    // timestamps — nothing that scopes a row to a specific user). So
    // this reads the table as-is, unfiltered by user.
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'admin') return;

        $this->isAdmin = true;

        $this->preloadItems = DB::table('admin_notifications')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->toArray();

        // Push straight into the Alpine store the moment this component
        // (and Alpine) is ready — same "retry until the store exists"
        // pattern coord-notif-poller uses, since Alpine may not have
        // initialized yet at the exact instant this runs.
        $this->js("
            (function () {
                var payload = " . json_encode($this->preloadItems) . ";
                function tryPush() {
                    if (window.__safeAdminNotifsStore) {
                        var s = window.__safeAdminNotifsStore();
                        if (s) {
                            if (!s._pollTimer && (!s.items || s.items.length === 0)) {
                                s.items = s._groupByDay(payload);
                            }
                            // Always do a real fetch right after, so we're
                            // fully in sync with the server (read state,
                            // anything created a split-second after this
                            // snapshot was taken, etc).
                            s._fetch();
                            return;
                        }
                    }
                    setTimeout(tryPush, 50);
                }
                tryPush();
            })();
        ");
    }

    // ─────────────────────────────────────────────────────────────────────
    // poll() — lightweight backup tick via wire:poll. Does not write
    // anything; just tells the client-side store to refresh. This exists
    // as a second layer on top of the 1.5s JS setInterval poll already in
    // the sidebar, in case the tab was backgrounded/throttled and the JS
    // timer got delayed by the browser — Livewire's own poll mechanism
    // tends to be more reliable across tab-visibility changes.
    // ─────────────────────────────────────────────────────────────────────
    public function poll(): void
    {
        if (! $this->isAdmin) return;

        $this->js("
            (function () {
                if (window.__safeAdminNotifsStore) {
                    var s = window.__safeAdminNotifsStore();
                    if (s) s._fetch();
                }
            })();
        ");
    }
};
?>

{{-- Invisible — pure background poller, no UI. wire:poll here is a
     backup nudge only; the real notification data flow is the JS
     _fetch() calls against /admin/notifications. --}}
<div wire:poll.3000ms="poll" class="hidden" aria-hidden="true"></div>