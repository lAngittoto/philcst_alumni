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

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

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

.adm-scroll::-webkit-scrollbar { width: 5px; }
.adm-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

select.adm-select-arrow {
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

[x-cloak] { display: none !important; }
</style>

{{-- Hover tooltip (row) --}}
<div id="adm-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

{{-- Action button tooltip (fixed-position — escapes the table's scroll clipping) --}}
<div id="adm-action-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold px-2.5 py-1.5 rounded-md whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(-50%, -100%);">
</div>

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
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
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
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-calendar-days text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Event Monitoring</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">Review and monitor all event submissions across colleges.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide self-start sm:self-center">
            <i class="fas fa-shield-halved text-purple-600 text-[10px]"></i>
            Admin Control Panel
        </span>
    </div>

    {{-- ── STAT CARDS ── --}}
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

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-shrink-0" style="height: 65vh; max-height: 65vh; overflow: hidden;">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title, venue…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] placeholder-[#a78bbd] font-normal
                              hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="COMPLETED">Completed</option>
            </select>

            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
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
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile college select --}}
            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($this->events->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto adm-scroll bg-white">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10 text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Event Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Date &amp; Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Coordinator</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest w-28 text-[#555555]"></th>
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

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333]">{{ $event->title }}</p>
                                    <p class="text-xs mt-0.5 text-[#666666]">{{ $eventDate->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold text-[#333333]">{{ $eventDate->format('M d, Y') }}</p>
                                <p class="text-xs mt-0.5 text-[#555555]">
                                    {{ $eventDate->format('g:i A') }}
                                    @if($event->event_end_date)
                                        &ndash; {{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}
                                    @endif
                                </p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                @if($event->organizer)
                                    <p class="text-sm font-semibold text-[#333333]">{{ $event->organizer->name }}</p>
                                    <p class="text-xs mt-0.5 text-[#777777]">{{ $event->organizer->department }}</p>
                                @else
                                    <span class="text-xs text-[#bbbbbb]">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isCompleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-green-200 bg-green-50 text-green-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Completed
                                    </span>
                                @elseif($isApproved)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Approved
                                    </span>
                                @elseif($isPending)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-yellow-200 bg-yellow-50 text-yellow-700 whitespace-nowrap">
                                        <i class="fas fa-hourglass-half text-[9px] mr-1"></i>Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-circle-xmark text-[9px] mr-1"></i>Rejected
                                    </span>
                                @endif
                            </td>

                            {{-- Actions: Share / Highlights only — row click already opens View --}}
                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>
                                    @if($isApproved)
                                        <button wire:click.stop="openShareModal({{ $event->id }})"
                                                data-adm-action data-tip="Share"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400">
                                            <i class="fas fa-share-nodes"></i>
                                        </button>
                                    @elseif($isCompleted)
                                        <button wire:click.stop="openShareModal({{ $event->id }})"
                                                data-adm-action data-tip="Highlights"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                       bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400">
                                            <i class="fas fa-trophy"></i>
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
                    <p class="font-semibold text-base text-[#333333]">
                        @if($search || $filterStatus || $filterCollege) No events match your filters
                        @else No events yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($search || $filterStatus || $filterCollege) Try clearing your filters to see all events.
                        @else No events have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
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
                        @if($this->events->onFirstPage()) disabled @endif
                        aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
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
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->events->hasMorePages()) disabled @endif
                        aria-label="Next">
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

    $postedByLabel = $ev->organizer
        ? $ev->organizer->name . ' · ' . $ev->organizer->department
        : ($ev->updated_by ?? 'Admin');
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden adm-fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div>
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Event Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $ev->title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($isApproved || $isCompleted)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareModal({{ $ev->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24"
                            aria-label="Share event">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        {{ $isCompleted ? 'Highlights' : 'Share' }}
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto adm-scroll">

            @if($hasPhoto)
            <div class="w-full px-5 pt-5 pb-3 flex-shrink-0">
                <div class="relative w-full rounded-xl overflow-hidden border border-gray-200 shadow-sm bg-gray-50">
                    <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}"
                         class="w-full object-contain block" style="max-height: 200px;">
                    <div class="absolute top-3 right-3">
                        @if($isCompleted)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-700/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Completed</span>
                        @elseif($isApproved)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Approved</span>
                        @elseif($isPending)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-600/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Pending</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-700/90 backdrop-blur-sm text-white text-xs font-bold tracking-wide">Rejected</span>
                        @endif
                    </div>
                </div>
            </div>
            @else
            <div class="relative mx-5 mt-5 mb-3 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center h-20"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <i class="fas fa-calendar-days text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isCompleted)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-700/90 text-white text-xs font-bold">Completed</span>
                    @elseif($isApproved)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-bold">Approved</span>
                    @elseif($isPending)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-bold">Pending</span>
                    @else<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-bold">Rejected</span>@endif
                </div>
            </div>
            @endif

            <div class="flex flex-col gap-3 px-5 pb-5">

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Date &amp; Time</p>
                    <p class="text-lg font-bold text-[#333333]">{{ $eventDatePH->format('F d, Y') }}</p>
                    <p class="text-base font-semibold mt-0.5 text-[#333333]">{{ $timeDisplay }}</p>
                </div>

                @if($ev->venue)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Venue</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->venue }}</p>
                    @if($ev->venue_address)<p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->venue_address }}</p>@endif
                </div>
                @endif

                @if($ev->target_participants)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Open For</p>
                    <p class="text-base font-bold text-[#333333]">{{ $ev->target_participants }}</p>
                </div>
                @endif

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">{{ $ev->organizer ? 'Coordinator' : 'Posted By' }}</p>
                    <p class="text-base font-bold text-[#333333]">{{ $postedByLabel }}</p>
                </div>

                @if($ev->contact_person || $ev->contact_email || $ev->contact_phone)
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-2 text-[#333333]">Contact</p>
                    <div class="flex flex-col gap-1.5">
                        @if($ev->contact_person)<p class="text-base font-bold text-[#333333]">{{ $ev->contact_person }}</p>@endif
                        @if($ev->contact_email)<p class="text-sm font-medium text-[#333333]">{{ $ev->contact_email }}</p>@endif
                        @if($ev->contact_phone)<p class="text-sm font-medium text-[#333333]">{{ $ev->contact_phone }}</p>@endif
                    </div>
                </div>
                @endif

                <div class="p-4 rounded-xl border {{ $isCompleted ? 'bg-green-50 border-green-200' : ($isApproved ? 'bg-emerald-50 border-emerald-200' : ($isPending ? 'bg-amber-50 border-amber-200' : 'bg-orange-50 border-orange-200')) }}">
                    @if($isCompleted)
                        <p class="text-base font-bold text-[#333333]">Completed</p>
                        <p class="text-sm font-medium mt-0.5 text-[#333333]">This event has already taken place.</p>
                    @elseif($isApproved)
                        <p class="text-base font-bold text-[#333333]">Approved — Now Live</p>
                        @if($ev->reviewed_at)<p class="text-sm font-medium mt-0.5 text-[#333333]">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif
                        @if($ev->review_remarks)<p class="text-sm italic mt-1 text-[#555555]">"{{ $ev->review_remarks }}"</p>@endif
                    @elseif($isPending)
                        <p class="text-base font-bold text-[#333333]">Awaiting Review</p>
                        <p class="text-sm font-medium mt-0.5 text-[#333333]">This event is pending approval from the Director.</p>
                    @else
                        <p class="text-base font-bold text-[#333333]">Rejected</p>
                        @if($ev->review_remarks)<p class="text-sm font-medium mt-0.5 text-[#333333]"><strong>Reason:</strong> {{ $ev->review_remarks }}</p>@endif
                    @endif
                </div>

                <p class="text-sm text-center font-medium text-[#333333]">
                    Submitted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                </p>

            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="flex-shrink-0 px-6 py-4 bg-white border-b border-gray-200">
                <p class="text-xs font-bold uppercase tracking-widest mb-2 text-[#333333]">Responses</p>
                @if($totalRsvp === 0)
                    <p class="text-base font-medium text-[#333333]">No responses yet.</p>
                @else
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex flex-col items-center px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-emerald-700">{{ $ev->confirmed_count }}</span>
                            <span class="text-xs font-semibold text-emerald-600 uppercase tracking-wide">Confirmed</span>
                        </div>
                        <div class="flex flex-col items-center px-4 py-2 bg-amber-50 border border-amber-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-amber-700">{{ $ev->tentative_count }}</span>
                            <span class="text-xs font-semibold text-amber-600 uppercase tracking-wide">Maybe</span>
                        </div>
                        <div class="flex flex-col items-center px-4 py-2 bg-red-50 border border-red-200 rounded-xl min-w-[80px]">
                            <span class="text-2xl font-bold text-red-700">{{ $ev->declined_count }}</span>
                            <span class="text-xs font-semibold text-red-600 uppercase tracking-wide">Declined</span>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto adm-scroll px-6 py-5 flex flex-col gap-5">

                @if($ev->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">About This Event</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-base leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->description) }}</p>
                    </div>
                </div>
                @endif

                @if($ev->notes)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-amber-50">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Additional Notes</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-base leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ trim($ev->notes) }}</p>
                    </div>
                </div>
                @endif

                @if(!$ev->description && !$ev->notes)
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-base font-medium text-[#555555]">No additional details provided.</p>
                    </div>
                </div>
                @endif

            </div>
        </div>

    </div>

</div>
@endif


{{-- ══ SHARE MODAL ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl   = $this->eventsBaseUrl();
    $shareHost      = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $isCompleted    = $shareEventStatus === 'COMPLETED';
    $shTimeDisplay  = $shareEventTime . ($shareEventEndTime ? ' – ' . $shareEventEndTime : '');
    $shDescPreview  = mb_strlen($shareEventDescription) > 160
        ? mb_substr($shareEventDescription, 0, 160) . '…'
        : $shareEventDescription;

    $fbLines = [];
    if ($isCompleted) {
        $fbLines[] = "🏆 Event Highlights: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    } else {
        $fbLines[] = "📅 Upcoming Event: {$shareEventTitle}";
        $fbLines[] = "🗓️  {$shareEventDate}" . ($shTimeDisplay ? " · {$shTimeDisplay}" : '');
    }
    if ($shareEventVenue)  $fbLines[] = "📍 {$shareEventVenue}" . ($shareEventVenueAddr ? ", {$shareEventVenueAddr}" : '');
    if ($shareEventTarget) $fbLines[] = $isCompleted ? "👥 {$shareEventTarget}" : "👥 Open for: {$shareEventTarget}";
    $fbLines[] = '';
    if ($shareEventDescription) {
        $dPrev = mb_strlen($shareEventDescription) > 200 ? mb_substr($shareEventDescription, 0, 200) . '…' : $shareEventDescription;
        $fbLines[] = $dPrev;
        $fbLines[] = '';
    }
    $fbLines[] = $isCompleted
        ? "🎉 Thank you to everyone who attended! See the full recap on the PHILCST Alumni Portal 👇"
        : "See full details & RSVP on the PHILCST Alumni Portal 👇";
    $fbLines[]  = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);

    $hasRealPhoto = $shareEventPhotoUrl
        && !str_contains($shareEventPhotoUrl, 'default')
        && str_contains($shareEventPhotoUrl, '/storage/');
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false, fbCopyFailed: false,
         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         photoUrl: {{ json_encode($shareEventPhotoUrl) }},
         hasPhoto: {{ $hasRealPhoto ? 'true' : 'false' }},
         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareModal(), 250);
         },
         async copyPlainText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
             } catch(e) { console.warn('Copy failed', e); }
         },
         async copyWithImage(text, imageUrl) {
             try {
                 if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && imageUrl && this.hasPhoto) {
                     const htmlContent = '<img src=\'' + imageUrl + '\' alt=\'Event Photo\' style=\'max-width:600px;display:block;margin-bottom:12px;\'><pre style=\'font-family:inherit;white-space:pre-wrap;\'>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                     const htmlBlob = new Blob([htmlContent], { type: 'text/html' });
                     const textBlob = new Blob([text], { type: 'text/plain' });
                     await navigator.clipboard.write([new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })]);
                     return true;
                 }
             } catch(e) { console.warn('Rich copy failed, fallback to plain text:', e); }
             await this.copyPlainText(text);
             return false;
         },
         async shareOnFacebook() {
             const richCopied = await this.copyWithImage(this.fbText, this.photoUrl);
             this.fbCopied     = true;
             this.fbCopyFailed = !richCopied;
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

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
         x-transition:leave-end="opacity-0 scale-95 translate-y-4"
         class="relative w-full max-w-5xl bg-white shadow-2xl flex flex-col rounded-2xl overflow-hidden will-change-transform"
         style="max-height: 90vh;">

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5 text-[#333333]">
                @if($isCompleted)
                    <i class="fas fa-trophy text-blue-500 text-sm"></i>
                    <span>Share Event Highlights</span>
                @else
                    <i class="fas fa-share-nodes text-blue-500 text-sm"></i>
                    <span>Share Event</span>
                @endif
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#333333]">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    @if($shareEventPhotoUrl)
                    <div class="w-full bg-gray-100 flex items-center justify-center px-3 pt-3 pb-0">
                        <img src="{{ $shareEventPhotoUrl }}" alt="{{ $shareEventTitle }}"
                             class="w-full rounded-lg object-contain" style="max-height:180px; display:block;">
                    </div>
                    @endif
                    <div class="border-b border-gray-200 px-5 py-4 bg-[#f0f7ff]">
                        <p class="font-semibold text-base leading-tight text-[#333333]">{{ $shareEventTitle }}</p>
                        <p class="text-sm mt-1 font-semibold text-[#333333]">
                            {{ $shareEventDate }}@if($shTimeDisplay) · {{ $shTimeDisplay }}@endif
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEventVenue)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-[#333333]">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareEventVenue }}
                            </span>
                            @endif
                            @if($shareEventTarget)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700">
                                <i class="fas fa-users text-[10px]"></i>{{ Str::limit($shareEventTarget, 30) }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shDescPreview)
                    <div class="px-5 py-3 border-b border-gray-100">
                        <p class="text-sm leading-relaxed text-[#333333]">{{ $shDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f0f7ff]">
                        <i class="fas fa-globe text-xs text-blue-400"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold text-blue-600">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
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

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-blue-600 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5 text-blue-700">
                            Sends the event caption directly to all target batch chat rooms for
                            <strong>{{ $shareEventTarget ?: 'all alumni' }}</strong>.
                        </p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Share via</p>

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

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background: linear-gradient(135deg, #0084FF 0%, #0050D0 100%);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs>
                                <linearGradient id="msg_adm_grad" x1="0%" y1="100%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color:#00B2FF"/>
                                    <stop offset="100%" style="stop-color:#006AFF"/>
                                </linearGradient>
                            </defs>
                            <path fill="url(#msg_adm_grad)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or post directly</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postToBatchChat"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-blue-200 hover:border-blue-400 hover:bg-blue-50 disabled:opacity-60 disabled:cursor-not-allowed bg-blue-50 text-blue-700">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-blue-600">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postToBatchChat" class="block font-semibold text-sm">
                            {{ $isCompleted ? 'Post Highlights to Batch Chats' : 'Post to Batch Chats' }}
                        </span>
                        <span wire:loading wire:target="postToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5 text-blue-600">Sends to all target batch rooms</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-blue-500"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-blue-300 hover:bg-blue-50 font-semibold text-sm transition cursor-pointer group bg-white text-[#333333]">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-blue-500'" class="text-base"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : 'text-blue-600'"
                           x-text="copied ? '✓ Link copied!' : 'Copy Events Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate text-[#555555]">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#333333]">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>

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
})();
</script>