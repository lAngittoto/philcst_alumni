{{-- resources/views/livewire/admin/events.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\AdminEvent;
use App\Models\AuditLog;
use App\Http\Controllers\AdminEventController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Alumni;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSort    = 'recent';
    public string $filterCollege = '';

    public string $myDisplayName = '';

    // ── View Modal ─────────────────────────────────────────────────────────────
    public bool   $showViewModal  = false;
    public ?int   $viewingEventId = null;

    // ── Share Modal ────────────────────────────────────────────────────────────
    public bool   $showShareModal        = false;
    public ?int   $shareEventId          = null;
    public string $shareEventTitle       = '';
    public string $shareEventDate        = '';
    public string $shareEventTime        = '';
    public string $shareEventEndTime     = '';
    public string $shareEventVenue       = '';
    public string $shareEventVenueAddr   = '';
    public string $shareEventDescription = '';
    public string $shareEventPhotoUrl    = '';
    public string $shareEventTarget      = '';
    public string $shareEventStatus      = '';

    public function mount(): void
    {
        set_time_limit(600);
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);

        $this->myDisplayName = auth()->user()?->name ?? 'Admin';

        $cacheKey = 'admin_events_auto_processed';
        if (!Cache::has($cacheKey)) {
            $this->autoRejectExpiredPendingEvents();
            $this->autoCompleteExpiredEvents();
            Cache::put($cacheKey, true, 60);
        }

        // Fire sidebar-bell notifications for recently submitted (PENDING)
        // and recently approved (APPROVED) events — mirrors the job-posts
        // dispatchJobNotifications() pattern.
        $this->dispatchEventNotifications();
    }

    private function autoRejectExpiredPendingEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');
        AdminEvent::withoutTrashed()
            ->where('status', 'PENDING')
            ->where('event_date', '<=', $now)
            ->update([
                'status'         => 'REJECTED',
                'review_remarks' => 'Auto-rejected: event date has already passed without approval.',
            ]);
    }

    private function autoCompleteExpiredEvents(): void
    {
        $now = \Carbon\Carbon::now('UTC');

        $expiring = AdminEvent::withoutTrashed()
            ->with('organizer:id,name')
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('event_end_date')
                        ->where('event_end_date', '<=', $now);
                })->orWhere(function ($sub) use ($now) {
                    $sub->whereNull('event_end_date')
                        ->where('event_date', '<=', $now);
                });
            })
            ->get(['id', 'title', 'organizer_id']);

        if ($expiring->isEmpty()) {
            return;
        }

        AdminEvent::withoutTrashed()
            ->whereIn('id', $expiring->pluck('id'))
            ->update(['status' => 'COMPLETED']);

        // Fire a completion notify event per event — bridge script below
        // forwards each one to the admin notification store.
        foreach ($expiring as $event) {
            $this->dispatch('admin-event-completed-notify', [
                'id'    => $event->id,
                'title' => $event->title,
            ]);
        }
    }

    /**
     * Fire admin-event-approved-notify browser events for recently approved
     * (APPROVED) events so the sidebar bell picks them up. Admin only cares
     * about approvals and completions — pending submissions no longer
     * trigger a bell notification.
     *
     * Mirrors job-posts.blade.php's dispatchJobNotifications() pattern:
     * the frontend bridge script (bottom of this file) listens for these
     * Livewire-dispatched events, dedups via sessionStorage, then forwards
     * a rich payload to admin.blade.php's notification store, which in turn
     * persists it server-side with a dedup_key so it never re-inserts.
     */
    private function dispatchEventNotifications(): void
    {
        try {
            // ── Recently approved events ─────────────────────────────────────
            $recentApproved = AdminEvent::withoutTrashed()
                ->with('organizer:id,name')
                ->where('status', 'APPROVED')
                ->whereNotNull('reviewed_at')
                ->where('reviewed_at', '>=', now('Asia/Manila')->subHours(24))
                ->orderBy('reviewed_at', 'desc')
                ->get(['id', 'title', 'organizer_id', 'reviewed_at']);

            foreach ($recentApproved as $event) {
                $this->dispatch('admin-event-approved-notify', [
                    'id'        => $event->id,
                    'title'     => $event->title,
                    'submitter' => $event->organizer?->name ?? 'Alumni Director',
                ]);
            }
        } catch (\Throwable) {
            // Silent — don't break page load
        }
    }

    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingFilterStatus(): void  { $this->resetPage(); }
    public function updatingFilterSort(): void    { $this->resetPage(); }
    public function updatingFilterCollege(): void { $this->resetPage(); }

    #[Computed]
    public function events()
    {
        $q = AdminEvent::withTrashed()
            ->with(['organizer' => fn($q) => $q->withTrashed()->select('id','name','department','email')])
            ->select([
                'id','title','description','event_date','event_end_date',
                'venue','venue_address','contact_person','contact_email',
                'contact_phone','notes','photo','status','target_participants',
                'organizer_id','review_remarks','reviewed_at',
                'updated_by','updated_by_role','deleted_by','deleted_by_role',
                'created_at','updated_at','deleted_at',
            ])
            ->where('status', '!=', 'ORGANIZER_DELETED');

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
                    ->orWhere('target_participants', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '')  $q->where('status', $this->filterStatus);
        if ($this->filterCollege !== '') $q->where('target_participants', 'like', "%{$this->filterCollege}%");

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (!$this->viewingEventId) return null;
        return AdminEvent::withTrashed()
            ->with(['organizer' => fn($q) => $q->withTrashed()->select('id','name','department','email')])
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])->find($this->viewingEventId);
    }

    #[Computed(persist: true)]
    public function colleges(): array
    {
        return Cache::remember('admin_event_colleges', 300, function () {
            return app(AdminEventController::class)->getColleges();
        });
    }

    #[Computed]
    public function statusCounts(): array
    {
        return Cache::remember('admin_event_status_counts', 30, function () {
            $counts = AdminEvent::withoutTrashed()
                ->where('status', '!=', 'ORGANIZER_DELETED')
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();
            return [
                'PENDING'   => $counts['PENDING']   ?? 0,
                'APPROVED'  => $counts['APPROVED']  ?? 0,
                'REJECTED'  => $counts['REJECTED']  ?? 0,
                'COMPLETED' => $counts['COMPLETED'] ?? 0,
                'total'     => array_sum($counts),
            ];
        });
    }

    public function resetFilters(): void
    {
        $this->search        = '';
        $this->filterStatus  = '';
        $this->filterCollege = '';
        $this->filterSort    = 'recent';
        $this->resetPage();
    }

    // ── View ──────────────────────────────────────────────────────────────────
    public function viewEvent(int $id): void
    {
        $this->viewingEventId = $id;
        $this->showViewModal  = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal  = false;
        $this->viewingEventId = null;
    }

    // ── Share Modal ────────────────────────────────────────────────────────────
    public function openShareModal(int $id): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $event = AdminEvent::withoutTrashed()->find($id);
        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        if (!in_array($event->status, ['APPROVED', 'COMPLETED'], true)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only approved or completed events can be shared.');
            return;
        }

        $this->shareEventId          = $id;
        $this->shareEventTitle       = $event->title;
        $this->shareEventDate        = $event->event_date->setTimezone('Asia/Manila')->format('F d, Y');
        $this->shareEventTime        = $event->event_date->setTimezone('Asia/Manila')->format('g:i A');
        $this->shareEventEndTime     = $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A') ?? '';
        $this->shareEventVenue       = $event->venue;
        $this->shareEventVenueAddr   = $event->venue_address ?? '';
        $this->shareEventDescription = $event->description ?? '';
        $this->shareEventPhotoUrl    = $event->photo_url;
        $this->shareEventTarget      = $event->target_participants ?? '';
        $this->shareEventStatus      = $event->status;

        $this->showShareModal = true;
        $this->showViewModal  = false;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal        = false;
        $this->shareEventId          = null;
        $this->shareEventTitle       = $this->shareEventDate = $this->shareEventTime = '';
        $this->shareEventEndTime     = $this->shareEventVenue = $this->shareEventVenueAddr = '';
        $this->shareEventDescription = $this->shareEventPhotoUrl = '';
        $this->shareEventTarget      = $this->shareEventStatus = '';
    }

    public function eventsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('upcoming.events', [], false); } catch (\Throwable) { $path = '/upcoming/events'; }
        return $base . $path;
    }
};
?>

<div class="flex flex-col h-full min-h-0" style="overflow: hidden;">

<style>
@keyframes admModalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes admSlideIn {
    from { opacity:0; }
    to   { opacity:1; }
}
.adm-m-in  { animation: admModalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.adm-fs-in { animation: admSlideIn .22s cubic-bezier(.4,0,.2,1) both; }

.adm-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.adm-scroll::-webkit-scrollbar-track { background: #eeeeee; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #cccccc; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Close-button tooltip (Share modal) — mirrors .dir-share-close-btn .tip on Manage Event (Director) ── */
.adm-share-close-btn { position: relative; }
.adm-share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
}
.adm-share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.adm-share-close-btn:hover .tip { opacity: 1; }

/* ── Table container height — always 58vh, never shrinks or grows regardless of content or flex siblings ── */
.adm-table-card { display: flex; flex-direction: column; flex: 0 0 58vh !important; min-height: 58vh !important; height: 58vh !important; max-height: 58vh !important; }

/* ── Share button tooltip (table rows) — pure CSS hover, no JS dependency ── */
.adm-share-tip-wrap { position: relative; display: inline-flex; }
.adm-share-tip-bubble {
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #111111; color: #ffffff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s;
    z-index: 999; box-shadow: 0 4px 14px rgba(0,0,0,.30);
}
.adm-share-tip-bubble::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #111111;
}
.adm-share-tip-wrap:hover .adm-share-tip-bubble { opacity: 1; }

@media (max-width: 640px) {
    .adm-table-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: none !important;
        box-shadow: none !important;
    }
}

/* ══ Mobile stacked card row — mirrors .dir-mrow on Manage Event (Director) ══ */
.adm-mrow {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    background: #fff;
    border-bottom: 1px solid #F0ECF5;
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: background .08s ease;
}
.adm-mrow:active { background: #F7F4FA; }

select.adm-select-arrow {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23111111' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}

/* ── Stat card ── */
.adm-stat-card {
    border-radius: 1rem;
    border: 1.5px solid #e8e0f0;
    background: #ffffff;
    padding: 0.875rem 1.125rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.adm-stat-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.625rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ══════════════════════════════════════════════
   VIEW MODAL — LEFT PANEL (white) field cards
   RIGHT PANEL (light gray #f2f2f2) body text
   ALL TEXT = #111111 black, zero gray text
   ══════════════════════════════════════════════ */

.vw-field {
    padding: 0.6rem 0.8rem;
    background: #ffffff;
    border: 1.5px solid #e0e0e0;
    border-radius: 0.75rem;
}
.vw-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #111111;
    margin-bottom: 3px;
}
.vw-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: #111111;
    line-height: 1.5;
}
.vw-subvalue {
    font-size: 0.75rem;
    font-weight: 400;
    color: #555555;
    margin-top: 2px;
}
.vw-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    background: #ffffff;
    border: 1.5px solid #cccccc;
    color: #111111;
}
.vw-section-title {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #111111;
    margin-bottom: 0.5rem;
}
.vw-body-box {
    font-size: 0.875rem;
    font-weight: 400;
    line-height: 1.8;
    color: #333333;
    white-space: pre-wrap;
    background: #ffffff;
    border: 1.5px solid #e0e0e0;
    border-radius: 0.75rem;
    padding: 1rem 1.125rem;
}

[x-cloak] { display:none !important; }
</style>

{{-- Hover tooltip (row) --}}
<div id="adm-hover-tip"
     class="fixed bg-[#111111] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#111111]"></span>
</div>

{{-- Action button tooltip --}}
<div id="adm-action-tip"
     class="fixed bg-[#111111] text-white text-[11px] font-semibold px-2.5 py-1.5 rounded-md whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(-50%, -100%);">
</div>

{{-- ── FLASH TOAST ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-amber-300 text-amber-800':type==='warning','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-calendar-days text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Event Monitoring</h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                    Review and monitor submissions across
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        all colleges
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 flex-shrink-0">

        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#f5eef9;">
                <i class="fas fa-calendar-days text-sm" style="color:#7a3f91;"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Total</p>
                <p class="text-xl font-bold leading-tight text-[#111111]">{{ $this->statusCounts['total'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-amber-50">
                <i class="fas fa-hourglass-half text-sm text-amber-500"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Pending</p>
                <p class="text-xl font-bold leading-tight text-amber-600">{{ $this->statusCounts['PENDING'] }}</p>
            </div>
            @if($this->statusCounts['PENDING'] > 0)
                <span class="ml-auto flex-shrink-0 w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
            @endif
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-emerald-50">
                <i class="fas fa-circle-check text-sm text-emerald-500"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Approved</p>
                <p class="text-xl font-bold leading-tight text-emerald-600">{{ $this->statusCounts['APPROVED'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-green-50">
                <i class="fas fa-flag-checkered text-sm text-green-600"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Completed</p>
                <p class="text-xl font-bold leading-tight text-green-600">{{ $this->statusCounts['COMPLETED'] }}</p>
            </div>
        </div>

    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="adm-table-card rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus,filterSort,filterCollege">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-bold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#111111] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Search title, venue…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] placeholder-[#aaaaaa] font-normal
                              hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] font-normal
                           hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
            </select>

            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] font-normal
                           hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            {{-- Active pill: Status --}}
            @if($filterStatus)
            @php
                $pillMap = [
                    'PENDING'   => ['label' => 'Pending',   'cls' => 'bg-yellow-50 border-yellow-300 text-yellow-800'],
                    'APPROVED'  => ['label' => 'Approved',  'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'REJECTED'  => ['label' => 'Rejected',  'cls' => 'bg-red-50 border-red-300 text-red-800'],
                    'COMPLETED' => ['label' => 'Completed', 'cls' => 'bg-green-50 border-green-300 text-green-800'],
                ];
                $pill = $pillMap[$filterStatus] ?? null;
            @endphp
            @if($pill)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border {{ $pill['cls'] }}">
                <i class="fas fa-filter text-[9px]"></i>
                {{ $pill['label'] }}
                <button wire:click="$set('filterStatus', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif
            @endif

            {{-- Active pill: College --}}
            @if($filterCollege)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-purple-50 border-purple-300 text-purple-800">
                <i class="fas fa-building-columns text-[9px]"></i>
                {{ $filterCollege }}
                <button wire:click="$set('filterCollege', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold text-[#111111]
                           bg-white border border-[#E0E0E0] hover:bg-[#f5f5f5] transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm text-[#111111]"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile college select --}}
            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col overflow-hidden">

            {{-- Centered loading spinner — mirrors Manage Event (Director)'s table overlay --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="search,filterStatus,filterSort,filterCollege,resetFilters,previousPage,nextPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            @if($this->events->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto adm-scroll bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-50" wire:target="search,filterStatus,filterSort,filterCollege,resetFilters,previousPage,nextPage">
                {{-- ── DESKTOP / TABLET: table view ── --}}
                <table class="w-full bg-white border-collapse hidden md:table table-fixed">
                    <colgroup>
                        <col style="width:32%;"><col style="width:20%;"><col style="width:22%;"><col style="width:14%;"><col style="width:12%;">
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #e0e0e0;">
                        <tr>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Event Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Date &amp; Time</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum      = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                        @endphp
                        <tr class="bg-white cursor-pointer transition-colors duration-100 hover:bg-[#f5f0fa]"
                            wire:click="viewEvent({{ $event->id }})"
                            wire:key="adm-event-row-{{ $event->id }}"
                            data-adm-row>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#111111]">{{ $event->title }}</p>
                                <p class="text-xs mt-0.5 text-[#666666] truncate">{{ $eventDate->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <p class="text-sm font-semibold text-[#111111] truncate">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5 text-[#555555] truncate">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold text-[#111111] truncate">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5 text-[#7a3f91] font-semibold truncate">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold whitespace-nowrap">
                                        Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        <i class="fas fa-flag-checkered text-[9px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        <i class="fas fa-hourglass-half text-[9px] mr-1"></i>Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-4 text-center overflow-visible">
                                <div class="flex items-center justify-center gap-1.5" @click.stop>
                                    @if($isApproved || $isCompleted)
                                        <span class="adm-share-tip-wrap">
                                            <button wire:click.stop="openShareModal({{ $event->id }})"
                                                    wire:loading.attr="disabled" wire:target="openShareModal({{ $event->id }})"
                                                    data-adm-action
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $event->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $event->id }})"></i>
                                            </button>
                                            <span class="adm-share-tip-bubble">Share</span>
                                        </span>
                                    @else
                                        <span class="text-xs text-[#bbbbbb]">&mdash;</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- ── MOBILE: stacked card list ── --}}
                <div class="block md:hidden">
                    @foreach($this->events as $index => $event)
                    @php
                        $isCompleted = $event->status === 'COMPLETED';
                        $isApproved  = $event->status === 'APPROVED';
                        $isPending   = $event->status === 'PENDING';
                        $isRejected  = $event->status === 'REJECTED';
                        $eventDate   = $event->event_date->setTimezone('Asia/Manila');
                        $rowNum      = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;
                    @endphp
                    <div class="adm-mrow" wire:click="viewEvent({{ $event->id }})" wire:key="adm-event-mrow-{{ $event->id }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#111111]">{{ $event->title }}</p>
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-green-200 bg-green-50 text-green-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-flag-checkered text-[8px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-circle-check text-[8px] mr-1"></i>Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-hourglass-half text-[8px] mr-1"></i>Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-red-200 bg-red-50 text-red-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-circle-xmark text-[8px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs mt-1 text-[#666666]">
                                {{ $eventDate->format('M d, Y · g:i A') }}
                            </p>
                            <div class="flex items-center justify-between mt-2">
                                <p class="text-xs text-[#7a3f91] font-semibold truncate">
                                    {{ $event->organizer?->name ?? 'Alumni Director' }}
                                </p>
                                @if($isApproved || $isCompleted)
                                    <button wire:click.stop="openShareModal({{ $event->id }})"
                                            wire:loading.attr="disabled" wire:target="openShareModal({{ $event->id }})"
                                            aria-label="Share"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer flex-shrink-0
                                                   bg-blue-100 text-blue-600 border border-blue-200 active:bg-white active:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                        <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $event->id }})"></i>
                                        <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $event->id }})"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f2f2f2]">
                    <i class="fas fa-calendar-days text-xl text-[#111111]"></i>
                </div>
                <div>
                    <p class="font-bold text-base text-[#111111]">
                        @if($search || $filterStatus || $filterCollege) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#111111]">
                        @if($search || $filterStatus || $filterCollege) Try clearing your filters to see all events.
                        @else No events have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-bold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>

        {{-- ── PAGINATION ── --}}
        @php
            $total    = $this->events->total();
            $pp       = $this->events->perPage();
            $cp       = $this->events->currentPage();
            $lp       = $this->events->lastPage();
            $from     = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to       = min($cp * $pp, $total);
            $pgStart  = max(1, $cp - 2);
            $pgEnd    = min($lp, $cp + 2);
        @endphp
        <div class="flex-shrink-0 border-t border-purple-800/30 px-4 flex items-center justify-between gap-2 flex-wrap min-h-[48px] py-1"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                event{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->events->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-bold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-bold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->events->hasMorePages()) disabled @endif>
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>

</div>


{{-- ══ VIEW EVENT — FULL SCREEN ══ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev          = $this->viewingEvent;
    $totalRsvp   = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
    $isCompleted = $ev->status === 'COMPLETED';
    $isApproved  = $ev->status === 'APPROVED';
    $isPending   = $ev->status === 'PENDING';
    $isRejected  = $ev->status === 'REJECTED';
    $eventDatePH = $ev->event_date->setTimezone('Asia/Manila');
    $eventEndPH  = $ev->event_end_date?->setTimezone('Asia/Manila');
    $timeDisplay = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
    $createdPH   = \Carbon\Carbon::parse($ev->created_at)->setTimezone('Asia/Manila');
    $hasPhoto    = !empty($ev->photo_url);

    $vStatusLabel = $isCompleted ? 'Completed' : ($isApproved ? 'Approved' : ($isPending ? 'Pending' : 'Rejected'));
    $vStatusColor = $isCompleted ? 'text-green-600' : ($isApproved ? 'text-emerald-600' : ($isPending ? 'text-amber-600' : 'text-red-600'));
    $vOrgName     = $ev->organizer?->name ?? null;
    $vOrgDept     = $ev->organizer?->department ?? null;
@endphp

{{-- Outer wrapper: light gray overall bg --}}
<div class="fixed inset-0 flex flex-col overflow-hidden adm-fs-in" style="background:#f2f2f2;z-index:9995;"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Purple header bar --}}
    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-sm" style="background:#7a3f91;">
        <div class="min-w-0">
            <p class="text-white/60 text-xs font-bold uppercase tracking-widest">Event Details</p>
            <h2 class="text-white font-bold text-base leading-tight truncate">{{ $ev->title }}</h2>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($isApproved || $isCompleted)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareModal({{ $ev->id }})"
                            wire:loading.attr="disabled" wire:target="openShareModal({{ $ev->id }})"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Share">
                        <i class="fas fa-share-nodes text-white text-sm" wire:loading.remove wire:target="openShareModal({{ $ev->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="openShareModal({{ $ev->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111111] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111111]"></span>
                    </div>
                </div>
            @endif
            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeViewModal"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20 disabled:opacity-60 disabled:cursor-wait"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm" wire:loading.remove wire:target="closeViewModal"></i>
                    <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="closeViewModal"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111111] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111111]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden adm-scroll">

        {{-- LEFT PANEL — white bg, vw-field cards --}}
        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-[#e0e0e0] overflow-visible lg:overflow-y-auto adm-scroll" style="background:#ffffff;">

            {{-- Photo / gradient placeholder --}}
            @if($hasPhoto)
            <div class="mx-4 mt-4 mb-0 flex-shrink-0 rounded-xl overflow-hidden" style="height:150px;">
                <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                     class="w-full h-full object-cover">
            </div>
            @else
            <div class="mx-4 mt-4 mb-0 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:150px; background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white/20 text-5xl"></i>
            </div>
            @endif

            {{-- Status + organizer chip row --}}
            <div class="px-4 pt-3 pb-1 flex-shrink-0 flex items-center justify-between">
                <span class="text-sm font-bold {{ $vStatusColor }}">{{ $vStatusLabel }}</span>
                @if($vOrgName)
                    <span class="vw-chip">{{ $vOrgName }}</span>
                @else
                    <span class="vw-chip">Alumni Director</span>
                @endif
            </div>

            <div class="flex flex-col gap-2.5 px-4 pb-4 pt-2">

                <div class="vw-field">
                    <p class="vw-label">Date &amp; Time</p>
                    <p class="vw-value">{{ $eventDatePH->format('F d, Y') }}</p>
                    <p class="vw-subvalue">{{ $timeDisplay }}</p>
                </div>

                @if($ev->venue)
                <div class="vw-field">
                    <p class="vw-label">Venue</p>
                    <p class="vw-value">{{ $ev->venue }}</p>
                    @if($ev->venue_address)<p class="vw-subvalue">{{ $ev->venue_address }}</p>@endif
                </div>
                @endif

                @if($ev->target_participants)
                <div class="vw-field">
                    <p class="vw-label">Open For</p>
                    <p class="vw-value">{{ $ev->target_participants }}</p>
                </div>
                @endif

                <div class="vw-field">
                    <p class="vw-label">{{ $vOrgName ? 'Coordinator' : 'Posted By' }}</p>
                    @if($vOrgName)
                        <p class="vw-value">{{ $vOrgName }}</p>
                        @if($vOrgDept)<p class="vw-subvalue">{{ $vOrgDept }}</p>@endif
                    @else
                        <p class="vw-value">Alumni Director</p>
                    @endif
                </div>

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="vw-field">
                    <p class="vw-label">Contact</p>
                    @if($ev->contact_person)<p class="vw-value">{{ $ev->contact_person }}</p>@endif
                    @if($ev->contact_email)<p class="vw-subvalue">{{ $ev->contact_email }}</p>@endif
                    @if($ev->contact_phone)<p class="vw-subvalue">{{ $ev->contact_phone }}</p>@endif
                </div>
                @endif

                <div class="vw-field">
                    <p class="vw-label">Review Status</p>
                    @if($isCompleted)
                        <p class="vw-value text-green-600">Completed</p>
                        <p class="vw-subvalue">This event has already taken place.</p>
                    @elseif($isApproved)
                        <p class="vw-value text-emerald-600">Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="vw-subvalue">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="vw-subvalue italic mt-1">"{{ $ev->review_remarks }}"</p>@endif
                    @elseif($isPending)
                        <p class="vw-value text-amber-600">Awaiting Review</p>
                        <p class="vw-subvalue">Pending approval from the Director.</p>
                    @else
                        <p class="vw-value text-red-600">Rejected</p>
                        @if($ev->review_remarks)<p class="vw-subvalue"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                    @endif
                </div>

                @if($ev->updated_by)
                <div class="vw-field">
                    <p class="vw-label">Last Updated By</p>
                    <p class="vw-value">{{ $ev->updated_by }}</p>
                    <p class="vw-subvalue">{{ \Carbon\Carbon::parse($ev->updated_at)->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</p>
                </div>
                @endif

                <p class="text-xs text-center text-[#111111] pt-1 font-semibold">
                    Submitted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        {{-- RIGHT PANEL — light gray bg (#f2f2f2) --}}
        <div class="flex-1 min-w-0 flex flex-col lg:overflow-hidden" style="background:#f2f2f2;">

            {{-- RSVP summary — white bg for contrast --}}
            <div class="flex-shrink-0 px-5 py-3 border-b border-[#e0e0e0]" style="background:#ffffff;">
                @if($totalRsvp > 0)
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="flex flex-col items-center px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-xl min-w-[64px]">
                            <span class="text-lg font-bold text-emerald-700">{{ $ev->confirmed_count }}</span>
                            <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-wide">Confirmed</span>
                        </div>
                        <div class="flex flex-col items-center px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-xl min-w-[64px]">
                            <span class="text-lg font-bold text-amber-700">{{ $ev->tentative_count }}</span>
                            <span class="text-[10px] font-bold text-amber-600 uppercase tracking-wide">Maybe</span>
                        </div>
                        <div class="flex flex-col items-center px-3 py-1.5 bg-red-50 border border-red-200 rounded-xl min-w-[64px]">
                            <span class="text-lg font-bold text-red-700">{{ $ev->declined_count }}</span>
                            <span class="text-[10px] font-bold text-red-600 uppercase tracking-wide">Declined</span>
                        </div>
                        <span class="text-xs font-semibold text-[#111111] ml-1">
                            {{ $totalRsvp }} total response{{ $totalRsvp !== 1 ? 's' : '' }}
                        </span>
                    </div>
                @else
                    <p class="text-xs font-semibold text-[#111111]">No responses yet.</p>
                @endif
            </div>

            {{-- Scrollable body sections --}}
            <div class="lg:flex-1 lg:min-h-0 overflow-visible lg:overflow-y-auto adm-scroll px-5 py-5 flex flex-col gap-5" style="background:#f2f2f2;">

                @if($ev->description)
                <div>
                    <p class="vw-section-title">About This Event</p>
                    <div class="vw-body-box">{{ trim($ev->description) }}</div>
                </div>
                @endif

                @if($ev->notes)
                <div>
                    <p class="vw-section-title">Additional Notes</p>
                    <div class="vw-body-box">{{ trim($ev->notes) }}</div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
                <div class="flex-1 flex items-center justify-center py-10">
                    <p class="text-sm font-bold text-[#111111]">No additional details provided.</p>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ SHARE EVENT — MODAL ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl   = $this->eventsBaseUrl();
    $isCompleted    = $shareEventStatus === 'COMPLETED';
    $shTimeDisplay  = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');

    $fbLines = [];
    $fbLines[] = $isCompleted ? "EVENT HIGHLIGHTS: " . strtoupper($shareEventTitle) : strtoupper($shareEventTitle);
    $fbLines[] = '';
    $fbLines[] = ($isCompleted ? 'Held on ' : 'Happening on ') . $shareEventDate . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    if ($shareEventVenue)  $fbLines[] = "Venue: {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = ($isCompleted ? 'Attendees: ' : 'Open for: ') . $shareEventTarget;

    if (trim($shareEventDescription) !== '') {
        $fbLines[] = '';
        $fbLines[] = 'About This Event:';
        $fbLines[] = trim($shareEventDescription);
    }

    $fbLines[] = '';
    $fbLines[] = $isCompleted
        ? 'Thank you to everyone who joined! For more updates, visit the PHILCST Alumni Connect portal.'
        : 'For more information and to RSVP, visit the PHILCST Alumni Connect portal.';
    $fbLines[] = $shareBaseUrl;
    $fbLines[] = '#YourFutureStarsHere';
    $fbPostText = implode("\n", $fbLines);
@endphp

<style>
@keyframes admPanelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.adm-share-sheet { animation: admPanelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.adm-share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Share modal: full screen on mobile, centered card on desktop ── */
@media (max-width: 767px) {
    .adm-share-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
    }
    .adm-share-backdrop .adm-share-sheet {
        border-radius: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
    }
}

.adm-share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .12s ease-out, transform .1s ease-out; border: none;
    will-change: transform;
}
.adm-share-option-btn:hover  { filter: brightness(0.94); }
.adm-share-option-btn:active { transform: scale(.97); transition-duration: .05s; }
.adm-share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.adm-share-option-btn .label-text { flex: 1; text-align: left; }

.adm-share-photo-preview {
    width: 100%;
    height: 140px;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}
.adm-share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain;
}
.adm-share-photo-preview .dl-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(17,24,39,.75); color: #fff;
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px;
    display: flex; align-items: center; gap: 4px;
    pointer-events: none;
}

.adm-dl-confirm-icon {
    width: 3rem; height: 3rem; border-radius: 0.9rem;
    background: #eff6ff; color: #2563eb;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.adm-dl-confirm-btn {
    flex: 1; padding: 0.65rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .15s, transform .1s; border: none;
}
.adm-dl-confirm-btn:active { transform: scale(.97); }
.adm-dl-confirm-btn.primary { background: #2563eb; color: #fff; }
.adm-dl-confirm-btn.primary:hover { filter: brightness(0.95); }
.adm-dl-confirm-btn.secondary { background: #f3f4f6; color: #333333; border: 1px solid #e5e7eb; }
.adm-dl-confirm-btn.secondary:hover { background: #e5e7eb; }
</style>

<div id="adm-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 adm-share-backdrop"
     x-data="{
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         sharingTo: null,
         downloading:false,
         downloaded:false,
         shareText: {{ json_encode($fbPostText) }},
         eventTitle: {{ json_encode($shareEventTitle) }},
         imageUrl:  {{ json_encode($shareEventPhotoUrl) }},

         showDlConfirm: false,
         pendingTarget: null,

         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'event-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
         },

         async autoCopyCaption() {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(this.shareText);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch (e) { return false; }
         },

         async downloadImage() {
             if (!this.imageUrl) return false;
             this.downloading = true;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 const url  = URL.createObjectURL(blob);
                 const a = document.createElement('a');
                 a.href = url;
                 a.download = 'event-photo.' + ext;
                 document.body.appendChild(a);
                 a.click();
                 document.body.removeChild(a);
                 setTimeout(() => URL.revokeObjectURL(url), 4000);
                 this.downloading = false;
                 this.downloaded  = true;
                 setTimeout(() => this.downloaded = false, 4000);
                 return true;
             } catch (e) {
                 this.downloading = false;
                 return false;
             }
         },

         async nativeShare() {
             this.sharingTo = 'native';
             try {
                 const shareData = { title: this.eventTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user — nothing to do */ }
             this.sharingTo = null;
         },

         askShare(target) {
             if (this.nativeShareSupported) { this.nativeShare(); return; }
             this.pendingTarget = target;
             this.showDlConfirm = true;
         },

         async confirmDownloadThenGo() {
             await this.downloadImage();
             this.proceedToTarget();
         },

         proceedToTarget() {
             this.showDlConfirm = false;
             const target = this.pendingTarget;
             this.pendingTarget = null;
             if (target === 'facebook') this.openFacebook();
             else if (target === 'messenger') this.openMessenger();
         },

         cancelDlConfirm() {
             this.showDlConfirm = false;
             this.pendingTarget = null;
         },

         async openFacebook() {
             this.sharingTo = 'facebook';
             const copyOk = await this.autoCopyCaption();
             const w=680,h=560,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             const win = window.open(url, 'philcst_adm_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into the Facebook post box that just opened.'
                     : 'Could not copy the caption automatically — please copy it manually from the preview, then paste it into Facebook.'
             });
             this.sharingTo = null;
         },

         async openMessenger() {
             this.sharingTo = 'messenger';
             const copyOk = await this.autoCopyCaption();
             const win = window.open('https://www.messenger.com/new', 'philcst_adm_messenger_share', 'noopener,noreferrer');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into Messenger.'
                     : 'Could not copy the caption automatically — please copy it manually from the preview, then paste it into Messenger.'
             });
             this.sharingTo = null;
         }
     }"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="if(showDlConfirm){cancelDlConfirm()}else{$wire.closeShareModal()}">

    <div class="adm-share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 adm-share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2 text-[#111111]">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Event
            </h2>
            <button wire:click="closeShareModal" type="button"
                    wire:loading.attr="disabled" wire:target="closeShareModal"
                    class="adm-share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                     wire:loading.remove wire:target="closeShareModal">
                    <path d="M2 2L12 12M12 2L2 12" stroke="#4b5563" stroke-width="2.25" stroke-linecap="round"/>
                </svg>
                <i class="fas fa-spinner fa-spin" style="font-size:12px;color:#4b5563;" wire:loading wire:target="closeShareModal"></i>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto adm-scroll">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0 text-[#111111]">Post Preview</p>

                @if($shareEventPhotoUrl)
                <div class="adm-share-photo-preview">
                    <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                         onerror="this.style.display='none'">
                    <span class="dl-badge" x-show="downloading || downloaded" x-cloak>
                        <i class="fas" :class="downloading ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="downloading ? 'Downloading…' : 'Downloaded'"></span>
                    </span>
                </div>
                @endif

                <div class="rounded-xl border border-gray-200 flex-shrink-0">
                    <div class="px-4 py-3">
                        <p class="whitespace-pre-wrap leading-relaxed text-[#111111]" style="font-size:clamp(11px,1vw,13px);">{{ rtrim(preg_replace('/#YourFutureStarsHere\s*$/', '', $fbPostText)) }}</p>
                        <p class="whitespace-pre-wrap leading-relaxed font-semibold mt-1" style="font-size:clamp(11px,1vw,13px);color:#1877F2;">#YourFutureStarsHere</p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto adm-scroll">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[#111111]">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="adm-share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" :disabled="sharingTo==='facebook'" class="adm-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#1877F2] text-sm" x-show="sharingTo==='facebook'" x-cloak></i>
                        <svg x-show="sharingTo!=='facebook'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='facebook' ? 'Opening…' : 'Share on Facebook'"></span>
                </button>

                <button type="button" @click="askShare('messenger')" :disabled="sharingTo==='messenger'" class="adm-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#0084FF] text-sm" x-show="sharingTo==='messenger'" x-cloak></i>
                        <svg x-show="sharingTo!=='messenger'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='messenger' ? 'Opening…' : 'Send via Messenger'"></span>
                </button>

                <p class="text-[10px] text-center text-[#666666]">Sharing highlights is available even after the event.</p>
            </div>
        </div>

        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex-shrink-0">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5 text-[#666666]"></i>
                <p class="text-xs leading-relaxed text-[#666666]">
                    The caption is copied to your clipboard automatically — just paste it (Ctrl+V)
                    into the Facebook or Messenger window that opens.
                </p>
            </div>
        </div>
    </div>

    {{-- ── PRE-SHARE "Download the photo?" CONFIRM MODAL ── --}}
    <div x-show="showDlConfirm" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[10010] flex items-center justify-center p-4 bg-black/55"
         @click.self="cancelDlConfirm()">
        <div class="adm-share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="adm-dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold text-[#111111]">Download the event photo?</p>
                    <p class="text-xs mt-1 leading-relaxed text-[#666666]">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($shareEventPhotoUrl)
            <div class="adm-share-photo-preview" style="height:110px;">
                <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="adm-dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="adm-dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>

<script>
(function () {
    function findScrollableAncestors(el) {
        var found = [];
        var node = el ? el.parentElement : null;
        while (node && node !== document.body) {
            var cs = window.getComputedStyle(node);
            if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
                found.push(node);
            }
            node = node.parentElement;
        }
        return found;
    }

    var lockedNodes = [];
    var prevStyles = [];

    function lockScroll() {
        var scrollEl = document.querySelector('[wire\\:id]') || document.body;
        var ancestors = findScrollableAncestors(scrollEl);

        [document.documentElement, document.body].concat(ancestors).forEach(function (node) {
            if (lockedNodes.indexOf(node) !== -1) return;
            prevStyles.push([node, node.style.overflow, node.style.overflowY]);
            node.style.overflow = 'hidden';
            node.style.overflowY = 'hidden';
            lockedNodes.push(node);
        });
    }

    function restore() {
        prevStyles.forEach(function (entry) {
            entry[0].style.overflow = entry[1];
            entry[0].style.overflowY = entry[2];
        });
        lockedNodes = [];
        prevStyles = [];
        document.removeEventListener('livewire:navigating', restore);
        window.removeEventListener('beforeunload', restore);
    }

    lockScroll();
    setTimeout(lockScroll, 150);
    setTimeout(lockScroll, 500);

    document.addEventListener('livewire:navigating', restore);
    window.addEventListener('beforeunload', restore);
})();
</script>

<script>
(function () {
    var tip       = document.getElementById('adm-hover-tip');
    var actionTip = document.getElementById('adm-action-tip');

    function bindRows() {
        document.querySelectorAll('[data-adm-row]').forEach(function (row) {
            if (row._admTipBound) return;
            row._admTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var actionWrap = e.target.closest('[data-adm-action]');
                if (actionWrap) {
                    tip.style.opacity = '0';
                    return;
                }
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.style.opacity = '1';
            });

            row.addEventListener('mouseleave', function () {
                if (tip) tip.style.opacity = '0';
            });

            row.addEventListener('click', function () {
                if (tip) tip.style.opacity = '0';
            });
        });

        document.querySelectorAll('[data-adm-action]').forEach(function (sw) {
            if (sw._admActionBound) return;
            sw._admActionBound = true;
            sw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    function bindActionTips() {
        if (!actionTip) return;
        document.querySelectorAll('[data-tip]').forEach(function (btn) {
            if (btn._admActionTipBound) return;
            btn._admActionTipBound = true;

            btn.addEventListener('mouseenter', function () {
                var rect = btn.getBoundingClientRect();
                actionTip.textContent  = btn.getAttribute('data-tip');
                actionTip.style.left   = (rect.left + rect.width / 2) + 'px';
                actionTip.style.top    = (rect.top - 8) + 'px';
                actionTip.style.opacity = '1';
            });

            btn.addEventListener('mouseleave', function () {
                actionTip.style.opacity = '0';
            });

            btn.addEventListener('click', function () {
                actionTip.style.opacity = '0';
            });
        });
    }

    bindRows();
    bindActionTips();
    document.addEventListener('livewire:updated', function () {
        bindRows();
        bindActionTips();
    });
    document.addEventListener('livewire:morph', function () {
        bindRows();
        bindActionTips();
    });
    document.addEventListener('livewire:morphed', function () {
        bindRows();
        bindActionTips();
    });

    // MutationObserver fallback — guarantees rebinding even if the Livewire
    // lifecycle event names above ever change between versions. Watches the
    // table body for row swaps caused by filtering/searching/pagination.
    var admTableRoot = document.querySelector('.adm-table-card');
    if (admTableRoot && window.MutationObserver) {
        var admObserver = new MutationObserver(function () {
            bindRows();
            bindActionTips();
        });
        admObserver.observe(admTableRoot, { childList: true, subtree: true });
    }

    // ─────────────────────────────────────────────────────────────────
    //  EVENT NOTIFICATION BRIDGE
    //
    //  Mirrors job-posts.blade.php's notification bridge pattern exactly:
    //  Listens for the Livewire-dispatched 'admin-event-approved-notify'
    //  and 'admin-event-completed-notify' events, dedups via
    //  sessionStorage so we don't re-fire on every filter change /
    //  Livewire re-render, then forwards a rich payload to
    //  admin.blade.php's notification store via __admin-event-approved-rich
    //  / __admin-event-completed-rich, which persists it server-side with
    //  its own dedup_key (event-approved::{id} / event-completed::{id}).
    //
    //  Admin only cares about approvals and completions — new/pending
    //  submissions no longer trigger a bell notification.
    // ─────────────────────────────────────────────────────────────────
    var EVT_NOTIF_STORE_KEY = 'admevt_notified_ids';

    function _evtGetNotifiedIds() {
        try {
            return JSON.parse(sessionStorage.getItem(EVT_NOTIF_STORE_KEY) || '[]');
        } catch (e) { return []; }
    }

    function _evtAddNotifiedId(key) {
        try {
            var ids = _evtGetNotifiedIds();
            if (ids.indexOf(key) === -1) {
                ids.push(key);
                // Keep last 300 to avoid unbounded growth
                if (ids.length > 300) ids = ids.slice(-300);
                sessionStorage.setItem(EVT_NOTIF_STORE_KEY, JSON.stringify(ids));
            }
        } catch (e) {}
    }

    function _evtIsAlreadyNotified(key) {
        return _evtGetNotifiedIds().indexOf(key) !== -1;
    }

    // ── Event approved (APPROVED) ───────────────────────────────────────
    document.addEventListener('admin-event-approved-notify', function (e) {
        var d = e.detail;
        if (!d) return;
        var payload = Array.isArray(d) ? d[0] : d;
        if (!payload || !payload.id) return;

        var key = 'approved-' + payload.id;
        if (_evtIsAlreadyNotified(key)) return;
        _evtAddNotifiedId(key);

        var message = (payload.title || 'An event')
            + ' was approved — Submitted by: ' + (payload.submitter || 'Alumni Director');

        window.dispatchEvent(new CustomEvent('__admin-event-approved-rich', {
            detail: { id: payload.id, message: message }
        }));
    });

    // ── Event completed (COMPLETED) ───────────────────────────────────────
    document.addEventListener('admin-event-completed-notify', function (e) {
        var d = e.detail;
        if (!d) return;
        var payload = Array.isArray(d) ? d[0] : d;
        if (!payload || !payload.id) return;

        var key = 'completed-' + payload.id;
        if (_evtIsAlreadyNotified(key)) return;
        _evtAddNotifiedId(key);

        var message = (payload.title || 'An event') + ' has been completed successfully.';

        window.dispatchEvent(new CustomEvent('__admin-event-completed-rich', {
            detail: { id: payload.id, message: message }
        }));
    });
})();
</script>