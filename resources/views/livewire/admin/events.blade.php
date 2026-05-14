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
use Illuminate\Support\Facades\DB;
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
        AdminEvent::withoutTrashed()
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
            ->update(['status' => 'COMPLETED']);
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

    public function postToBatchChat(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        if (!$this->shareEventId) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $event = AdminEvent::withoutTrashed()->find($this->shareEventId);
        if (!$event) {
            $this->dispatch('flash-message', type: 'error', message: 'Event not found.');
            return;
        }

        $tp          = $event->target_participants ?? '';
        $tpParts     = explode(' · Batch ', $tp, 2);
        $coursesPart = trim($tpParts[0] ?? '');
        $batchYear   = trim($tpParts[1] ?? '');

        $roomQuery = DB::table('chat_rooms')
            ->join('courses', 'chat_rooms.course_code', '=', 'courses.code')
            ->select('chat_rooms.id', 'chat_rooms.course_code', 'chat_rooms.batch');

        if (!empty($batchYear)) {
            $roomQuery->where('chat_rooms.batch', $batchYear);
        }

        if ($coursesPart !== 'All Colleges' && !empty($coursesPart)) {
            $colleges = array_map('trim', explode(',', $coursesPart));
            $roomQuery->whereIn('courses.college', $colleges);
        }

        $rooms = $roomQuery->get();

        if ($rooms->isEmpty()) {
            $this->dispatch('flash-message', type: 'error', message: 'No batch chat rooms found for this event\'s target participants.');
            return;
        }

        $isCompleted = $this->shareEventStatus === 'COMPLETED';
        $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
        $eventEndPH  = $event->event_end_date?->setTimezone('Asia/Manila');
        $timeStr     = $eventDatePH->format('g:i A') . ($eventEndPH ? ' – ' . $eventEndPH->format('g:i A') : '');
        $baseUrl     = $this->eventsBaseUrl();

        if ($isCompleted) {
            $lines = [
                "🏆 Event Highlights",
                "━━━━━━━━━━━━━━━━━━━━━━━━",
                "✅ {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ];
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 {$event->target_participants}";
            $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "Thanks to everyone who joined! 🎉 Check the Events page → {$baseUrl}";
        } else {
            $lines = [
                "📢 @everyone — Event Alert!",
                "",
                "📅 {$event->title}",
                "🗓️  {$eventDatePH->format('F d, Y')} · {$timeStr}",
            ];
            if ($event->venue)               $lines[] = "📍 {$event->venue}";
            if ($event->target_participants) $lines[] = "👥 Open for: {$event->target_participants}";
            $lines[] = "";
            $lines[] = "Check it out & RSVP on the Events page! 🎉 → {$baseUrl}";
        }

        $body = implode("\n", $lines);

        foreach ($rooms as $room) {
            $msgId = DB::table('chat_messages')->insertGetId([
                'room_id'     => $room->id,
                'sender_type' => 'admin',
                'sender_id'   => auth()->id(),
                'body'        => $body,
                'reply_to_id' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            if (!$isCompleted) {
                DB::table('chat_mentions')->insert([
                    'message_id'   => $msgId,
                    'mention_type' => 'everyone',
                    'mentioned_id' => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $roomCount = $rooms->count();
        $label = $isCompleted
            ? "Event highlights posted to {$roomCount} batch chat(s)! 🏆"
            : "Event posted to {$roomCount} batch chat(s)! 🎉";

        $this->dispatch('flash-message', type: 'success', message: $label);
        $this->closeShareModal();
    }
};
?>

<div class="flex flex-col" style="height: calc(100vh - 70px); overflow: hidden;">

<style>
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}

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

/* ── Scrollbar ── */
.adm-scroll::-webkit-scrollbar { width: 5px; }
.adm-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Filter inputs ── */
.adm-filter {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
}
.adm-filter:hover  { border-color: #c4b5d4; }
.adm-filter:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.adm-filter {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}

/* ── Table block ── */
.adm-table-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.adm-table-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.adm-table-pagination {
    flex-shrink: 0;
    background: #7a3f91;
    padding: 0.6rem 1rem;
}

/* ── Table rows ── */
.adm-tbl-row { background-color: #ffffff; }
.adm-tbl-row:hover { background-color: #FAFAFA !important; }

/* ── Stat cards ── */
.adm-stat-card {
    border-radius: 1rem;
    border: 1.5px solid #e8e0f0;
    background: #ffffff;
    padding: 0.875rem 1.125rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: box-shadow .15s, border-color .15s;
    cursor: pointer;
}
.adm-stat-card:hover { box-shadow: 0 4px 16px rgba(122,63,145,.10); border-color: #d4aaeb; }
.adm-stat-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.625rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ── Meta rows (view modal) ── */
.adm-meta-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.625rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.adm-meta-label {
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    color: #555555; margin-bottom: 0.2rem;
}
.adm-meta-value { font-size: 0.975rem; font-weight: 700; color: #333333; line-height: 1.3; }
.adm-meta-sub   { font-size: 0.875rem; color: #333333; margin-top: 0.15rem; }

[x-cloak] { display: none !important; }
</style>

{{-- ── FLASH TOAST ─────────────────────────────────────────────────────────── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,
              display(t,m){this.type=t;this.msg=m;this.show=true;
              clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{'border-emerald-300':type==='success','border-blue-300':type==='info','border-amber-300':type==='warning','border-red-300':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" style="color:#333333;"
           x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" style="color:#333333;" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0 text-gray-600">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ── PAGE HEADER ──────────────────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Event Monitoring</h1>
                <p class="text-xs mt-0.5" style="color:#555555;">Review and monitor all event submissions across colleges.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide self-start sm:self-center">
            <i class="fas fa-shield-halved text-purple-600 text-[10px]"></i>
            Admin Control Panel
        </span>
    </div>

    {{-- ── STAT CARDS ────────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 flex-shrink-0">
        {{-- Total --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','')">
            <div class="adm-stat-icon" style="background:#f5eef9;">
                <i class="fas fa-calendar-days text-sm" style="color:#7a3f91;"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Total</p>
                <p class="text-xl font-semibold leading-tight" style="color:#333333;">{{ $this->statusCounts['total'] }}</p>
            </div>
        </div>
        {{-- Pending --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','PENDING')">
            <div class="adm-stat-icon bg-amber-50">
                <i class="fas fa-hourglass-half text-sm text-amber-500"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Pending</p>
                <p class="text-xl font-semibold leading-tight text-amber-600">{{ $this->statusCounts['PENDING'] }}</p>
            </div>
            @if($this->statusCounts['PENDING'] > 0)
                <span class="ml-auto flex-shrink-0 w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
            @endif
        </div>
        {{-- Approved --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','APPROVED')">
            <div class="adm-stat-icon bg-emerald-50">
                <i class="fas fa-circle-check text-sm text-emerald-500"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Approved</p>
                <p class="text-xl font-semibold leading-tight text-emerald-600">{{ $this->statusCounts['APPROVED'] }}</p>
            </div>
        </div>
        {{-- Completed --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','COMPLETED')">
            <div class="adm-stat-icon bg-green-50">
                <i class="fas fa-flag-checkered text-sm text-green-600"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Completed</p>
                <p class="text-xl font-semibold leading-tight text-green-600">{{ $this->statusCounts['COMPLETED'] }}</p>
            </div>
        </div>
    </div>

    {{-- ══ TABLE BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col adm-table-block">

        {{-- ── Filter Bar ──────────────────────────────────────────────────── --}}
        <div class="adm-table-filter flex flex-wrap gap-2 items-center">

            {{-- Filters Pill --}}
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-sliders text-white text-sm"></i>
                <span class="hidden sm:inline">Filters</span>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#7a3f91; z-index:1;"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Search title, venue…"
                       class="adm-filter w-full"
                       style="padding-left:2.25rem; padding-right:1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus" class="adm-filter" style="color:#333333; min-width:140px;">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
            </select>

            {{-- College --}}
            <select wire:model.live="filterCollege" class="adm-filter hidden sm:block" style="color:#333333; min-width:140px;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            {{-- Sort --}}
            <select wire:model.live="filterSort" class="adm-filter hidden sm:block" style="color:#333333; min-width:130px;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters"><i class="fas fa-rotate-left text-sm"></i></span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile row 2 --}}
            <select wire:model.live="filterCollege" class="adm-filter flex-1 sm:hidden" style="color:#333333;">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="filterSort" class="adm-filter flex-1 sm:hidden" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── Table Wrapper ────────────────────────────────────────────────── --}}
        <div class="relative flex-1 min-h-0 flex flex-col">

            {{-- Loading Overlay --}}
            <div wire:loading
                 wire:target="search,filterStatus,filterSort,filterCollege,resetFilters,previousPage,nextPage"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold" style="color:#7a3f91;">Loading events…</span>
                </div>
            </div>

            @if($this->events->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto adm-scroll" style="background:#fff; scrollbar-width:thin;">
                <table class="w-full min-w-[620px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Event Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Date &amp; Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell" style="color:#555555;">College</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->events as $index => $event)
                        @php
                            $isCompleted = $event->status === 'COMPLETED';
                            $isApproved  = $event->status === 'APPROVED';
                            $isPending   = $event->status === 'PENDING';
                            $isRejected  = $event->status === 'REJECTED';
                            $eventDatePH = $event->event_date->setTimezone('Asia/Manila');
                            $rowNum      = ($this->events->currentPage() - 1) * $this->events->perPage() + $index + 1;

                            if ($event->organizer_id && $event->organizer) {
                                $displayCollege = $event->organizer->department ?? '—';
                            } else {
                                $tp    = $event->target_participants ?? '';
                                $tpPts = explode(' · Batch ', $tp, 2);
                                $displayCollege = trim($tpPts[0]) ?: 'All Colleges';
                            }
                        @endphp
                        <tr class="adm-tbl-row transition-colors duration-100">

                            {{-- # --}}
                            <td class="px-4 py-3.5 text-xs font-semibold text-center text-purple-400">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            {{-- Event --}}
                            <td class="px-4 py-3.5">
                                <div class="max-w-[230px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $event->title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#666666;">{{ $event->created_at->diffForHumans() }}</p>
                                </div>
                            </td>

                            {{-- Date / Time --}}
                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $eventDatePH->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5" style="color:#555555;">
                                    {{ $eventDatePH->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            {{-- Coordinator --}}
                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="text-sm" style="color:#bbbbbb;">—</span>
                                @endif
                            </td>

                            {{-- College --}}
                            <td class="px-4 py-3.5 hidden xl:table-cell">
                                <p class="text-sm font-semibold max-w-[150px] truncate" style="color:#555555;" title="{{ $displayCollege }}">
                                    {{ $displayCollege }}
                                </p>
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-3.5 text-center">
                                @if($isCompleted)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-green-50 text-green-700 border border-green-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px]"></i> Completed
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-hourglass-half text-[9px]"></i> Pending
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px]"></i> Approved
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px]"></i> Rejected
                                    </span>
                                @endif
                            </td>

                            {{-- Actions: View + Share only ──────────────────── --}}
                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">

                                    {{-- View --}}
                                    <button wire:click="viewEvent({{ $event->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share (Approved) --}}
                                    @if($isApproved)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>

                                    {{-- Highlights (Completed) --}}
                                    @elseif($isCompleted)
                                        <button wire:click="openShareModal({{ $event->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trophy text-xs"></i>
                                            <span class="hidden xl:inline">Highlights</span>
                                        </button>
                                    @endif

                                </div>
                            </td>

                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-calendar-days text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#333333;">
                        @if($search || $filterStatus || $filterCollege) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus || $filterCollege) Try clearing your filters to see all events.
                        @else No events have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>{{-- /relative wrapper --}}

        {{-- ── Pagination ────────────────────────────────────────────────────── --}}
        @php
            $total = $this->events->total();
            $pp    = $this->events->perPage();
            $cp    = $this->events->currentPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);
        @endphp
        <div class="adm-table-pagination flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
                Showing
                <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
                of
                <span class="font-semibold text-white">{{ $total }}</span>
                event{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->events->onFirstPage())
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">&larr; Prev</button>
                @else
                    <button wire:click="previousPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">&larr; Prev</button>
                @endif
                <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#7a3f91;">
                    {{ $cp }} / {{ $this->events->lastPage() }}
                </span>
                @if($this->events->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">Next &rarr;</button>
                @else
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next &rarr;</button>
                @endif
            </div>
        </div>

    </div>{{-- /adm-table-block --}}

</div>{{-- /main layout --}}


{{-- ════════════════════════════════════════════════════════════════════════
     VIEW EVENT — FULL SCREEN SLIDE-IN
════════════════════════════════════════════════════════════════════════ --}}
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

    $postedByLabel = $ev->organizer
        ? $ev->organizer->name . ' · ' . $ev->organizer->department
        : ($ev->updated_by ?? 'Admin');
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden adm-fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-calendar-days text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Event Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $ev->title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
            {{-- Share / Highlights from view header --}}
            @if($isApproved)
                <button wire:click="openShareModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i><span class="hidden sm:inline">Share</span>
                </button>
            @elseif($isCompleted)
                <button wire:click="openShareModal({{ $ev->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-400/20 hover:bg-amber-400/30 border border-amber-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-trophy text-xs"></i><span class="hidden sm:inline">Highlights</span>
                </button>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- Body --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- ── Left Panel: Key Info ── --}}
        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto adm-scroll"
             style="scrollbar-width:thin;">

            {{-- Photo --}}
            @if($hasPhoto)
            <div class="w-full px-4 pt-4 pb-2 shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         class="w-full object-contain" style="max-height:200px; display:block;">
                    <div class="absolute top-2 right-2">
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Completed</span>
                        @elseif($isApproved)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Approved</span>
                        @elseif($isPending)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-hourglass-half text-[9px]"></i> Pending</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 backdrop-blur-sm text-white text-xs font-semibold"><i class="fas fa-circle-xmark text-[9px]"></i> Rejected</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center" style="height:90px; background:linear-gradient(135deg,#7a3f91,#4a1f6a);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isCompleted)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-semibold">Completed</span>
                    @elseif($isApproved)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Approved</span>
                    @elseif($isPending)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-semibold">Pending</span>
                    @else<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-semibold">Rejected</span>@endif
                </div>
            </div>
            @endif

            {{-- Meta Cards --}}
            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-meta-icon bg-violet-100"><i class="fas fa-calendar text-violet-600 text-base"></i></span>
                    <div>
                        <p class="adm-meta-label">Date &amp; Time</p>
                        <p class="adm-meta-value">{{ $eventDatePH->format('F d, Y') }}</p>
                        <p class="adm-meta-sub">{{ $timeDisplay }}</p>
                    </div>
                </div>

                @if($ev->venue)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-meta-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="adm-meta-label">Venue</p>
                        <p class="adm-meta-value truncate">{{ $ev->venue }}</p>
                        @if($ev->venue_address)<p class="adm-meta-sub truncate">{{ $ev->venue_address }}</p>@endif
                    </div>
                </div>
                @endif

                @if($ev->target_participants)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-meta-icon bg-purple-100"><i class="fas fa-users text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="adm-meta-label">Open For</p>
                        <p class="adm-meta-value line-clamp-2">{{ $ev->target_participants }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-meta-icon bg-blue-100"><i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }} text-blue-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="adm-meta-label">{{ $ev->organizer ? 'Coordinator' : 'Posted By' }}</p>
                        <p class="adm-meta-value truncate">{{ $postedByLabel }}</p>
                    </div>
                </div>

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="adm-meta-label mb-2">Contact</p>
                    <div class="flex flex-col gap-2">
                        @if($ev->contact_person)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-user text-purple-500 text-sm w-4"></i>
                            <span class="text-sm font-semibold" style="color:#333333;">{{ $ev->contact_person }}</span>
                        </div>
                        @endif
                        @if($ev->contact_email)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-envelope text-sky-500 text-sm w-4"></i>
                            <span class="text-sm truncate" style="color:#333333;">{{ $ev->contact_email }}</span>
                        </div>
                        @endif
                        @if($ev->contact_phone)
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-phone text-emerald-500 text-sm w-4"></i>
                            <span class="text-sm" style="color:#333333;">{{ $ev->contact_phone }}</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Review status badge --}}
                <div class="p-3.5 rounded-xl border
                    {{ $isCompleted ? 'bg-green-50 border-green-200' :
                       ($isApproved  ? 'bg-emerald-50 border-emerald-200' :
                       ($isPending   ? 'bg-amber-50 border-amber-200' : 'bg-red-50 border-red-200')) }}">
                    @if($isCompleted)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-green-800"><i class="fas fa-circle-check text-green-500 text-sm"></i> Completed</p>
                        <p class="text-sm text-green-800 mt-0.5">This event has already taken place.</p>
                    @elseif($isApproved)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-emerald-800"><i class="fas fa-circle-check text-emerald-500 text-sm"></i> Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="text-xs text-emerald-700 mt-0.5">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm text-emerald-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                    @elseif($isPending)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-amber-800"><i class="fas fa-hourglass-half text-amber-500 text-sm"></i> Awaiting Review</p>
                        <p class="text-sm text-amber-700 mt-0.5">This event is pending approval from the Director.</p>
                    @else
                        <p class="text-sm font-bold flex items-center gap-1.5 text-red-800"><i class="fas fa-circle-xmark text-red-500 text-sm"></i> Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm text-red-800 mt-0.5"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                    @endif
                </div>

                <p class="text-xs text-center" style="color:#777777;">
                    Submitted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        {{-- ── Right Panel: Description + RSVPs ── --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            {{-- RSVP header strip --}}
            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <p class="text-xs font-bold uppercase tracking-widest shrink-0" style="color:#333333;">Responses</p>
                    @if($totalRsvp === 0)
                        <span class="text-sm italic" style="color:#555555;">No responses yet.</span>
                    @else
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
                                <i class="fas fa-circle-check text-[9px]"></i> {{ $ev->confirmed_count }} Confirmed
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-xs font-semibold text-amber-700">
                                <i class="fas fa-circle-question text-[9px]"></i> {{ $ev->tentative_count }} Maybe
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-50 border border-red-200 text-xs font-semibold text-red-700">
                                <i class="fas fa-circle-xmark text-[9px]"></i> {{ $ev->declined_count }} Declined
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Scrollable content --}}
            <div class="flex-1 min-h-0 overflow-y-auto adm-scroll px-5 py-4 flex flex-col gap-4">

                @if($ev->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        About This Event
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($ev->description) }}</div>
                </div>
                @endif

                @if($ev->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-amber-50">
                            <i class="fas fa-list-check text-amber-500 text-[10px]"></i>
                        </span>
                        Additional Notes
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-amber-50/50 rounded-lg p-4 border border-amber-100" style="line-height:1.75; color:#333333;">{{ trim($ev->notes) }}</div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium" style="color:#555555;">No additional details provided.</p>
                    </div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     SHARE / HIGHLIGHTS SLIDE-OVER
════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl   = $this->eventsBaseUrl();
    $shareHost      = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isShCompleted  = $shareEventStatus === 'COMPLETED';
    $shTimeDisplay  = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $shDescPreview  = mb_strlen($shareEventDescription) > 160
        ? mb_substr($shareEventDescription, 0, 160) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isShCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    }
    if ($shareEventVenue)  $fbLines[] = "📍 {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = $isShCompleted ? "👥 {$shareEventTarget}" : "👥 Open for: {$shareEventTarget}";
    $fbLines[] = '';
    if ($shareEventDescription) {
        $dPrev = mb_strlen($shareEventDescription) > 200
            ? mb_substr($shareEventDescription, 0, 200) . '…'
            : $shareEventDescription;
        $fbLines[] = $dPrev;
        $fbLines[] = '';
    }
    $fbLines[] = $isShCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $shareEventPhotoUrl
        && !str_contains($shareEventPhotoUrl, 'default')
        && str_contains($shareEventPhotoUrl, '/storage/');
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false, fbCopyFailed: false,
         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         photoUrl: {{ json_encode($shareEventPhotoUrl) }},
         hasPhoto: {{ $hasRealPhoto ? 'true' : 'false' }},
         close() { this.open = false; setTimeout(() => $wire.closeShareModal(), 290); },
         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },
         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const html = '<img src=\'' + imageUrl + '\' alt=\'Event\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                     const htmlBlob = new Blob([html],  { type: 'text/html' });
                     const textBlob = new Blob([text],  { type: 'text/plain' });
                     await navigator.clipboard.write([new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })]);
                     return true;
                 }
             } catch(e) { console.warn('Rich copy failed, fallback:', e); }
             await this.copyPlainText(text);
             return false;
         },
         async shareOnFacebook() {
             const ok = await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied = true; this.fbCopyFailed = !ok;
             const target = this.hasPhoto ? this.photoUrl : this.baseUrl;
             window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(target), '_blank', 'width=626,height=436,noopener,noreferrer');
             setTimeout(() => { this.fbCopied = false; this.fbCopyFailed = false; }, 8000);
         },
         async shareOnMessenger() {
             await this.copyWithImage(this.fbText, this.photoUrl);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/', '_blank', 'noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/', '_blank', 'noopener');
             }
             setTimeout(() => { this.messengerCopied = false; }, 8000);
         },
         async copyLinkFn() {
             await this.copyPlainText(this.baseUrl);
             this.copied = true;
             setTimeout(() => this.copied = false, 2500);
         }
     }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="close()">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    {{-- Panel --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Share Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5" style="color:#333333;">
                @if($isShCompleted)
                    <i class="fas fa-trophy text-amber-500 text-sm"></i>
                    <span>Share Event Highlights</span>
                @else
                    <i class="fas fa-share-nodes text-sky-600 text-sm"></i>
                    <span>Share Event</span>
                @endif
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#333333;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Preview panel --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto adm-scroll"
                 style="scrollbar-width:thin;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3 pb-0">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4"
                         style="background-color: {{ $isShCompleted ? '#fffbeb' : '#f9f7fc' }};">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#555555;">
                            {{ $shareEventDate }}@if($shTimeDisplay) · {{ $shTimeDisplay }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareEventTarget, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#333333;">{{ $shDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#555555;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#333333;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                {{-- How sharing works --}}
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> opens the share dialog
                            <em>and</em> copies the event photo + caption to your clipboard.
                            Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd>
                            in the composer to paste automatically.
                        </p>
                    </div>
                </div>

                {{-- Batch chat info --}}
                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Sends the event caption directly to all target batch chat rooms for
                            <strong>{{ $shareEventTarget ?: 'all alumni' }}</strong>.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Actions panel --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto adm-scroll"
                 style="scrollbar-width:thin;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                {{-- FB copy status --}}
                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-xl px-4 py-3 flex items-start gap-2"
                     :class="fbCopyFailed ? 'bg-amber-50 border border-amber-300' : 'bg-emerald-50 border border-emerald-300'">
                    <i class="text-sm mt-0.5 flex-shrink-0 fas"
                       :class="fbCopyFailed ? 'fa-triangle-exclamation text-amber-500' : 'fa-check text-emerald-600'"></i>
                    <div>
                        <p class="text-sm font-semibold"
                           :class="fbCopyFailed ? 'text-amber-800' : 'text-emerald-800'"
                           x-text="fbCopyFailed ? 'Share dialog opened!' : 'Share dialog opened + clipboard ready!'"></p>
                        <p class="text-xs mt-0.5"
                           :class="fbCopyFailed ? 'text-amber-700' : 'text-emerald-700'"
                           x-text="fbCopyFailed ? 'Caption copied as text only — paste in the post.' : 'Press Ctrl+V to paste the photo + caption!'"></p>
                    </div>
                </div>

                {{-- Messenger copy status --}}
                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Messenger opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste the photo + caption.</p>
                    </div>
                </div>

                {{-- Facebook --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Post on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens share dialog · photo+text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_adm2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_adm2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · photo+text copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post directly</span>
                    </div>
                </div>

                {{-- Post to Batch Chats --}}
                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isShCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends to all target batch rooms</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or copy link</span>
                    </div>
                </div>

                {{-- Copy Link --}}
                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white" style="color:#333333;">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-base"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#555555;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>