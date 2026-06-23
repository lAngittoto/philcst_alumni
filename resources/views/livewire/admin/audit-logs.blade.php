{{-- resources/views/livewire/admin/audit-logs.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

new #[Layout('app')] class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string  $search    = '';
    public string  $module    = '';
    public string  $action    = '';
    public string  $severity  = '';
    public string  $role      = '';
    public string  $dateFrom  = '';
    public string  $dateTo    = '';
    public bool    $flagged   = false;
    public int     $perPage   = 20;

    public ?array  $selected  = null;
    public bool    $showModal = false;

    public function mount(): void
    {
        if (Auth::user()?->role !== 'admin') {
            $this->redirect(route('login'));
        }
    }

    public function updatedSearch():   void { $this->resetPage(); }
    public function updatedModule():   void { $this->resetPage(); }
    public function updatedAction():   void { $this->resetPage(); }
    public function updatedSeverity(): void { $this->resetPage(); }
    public function updatedRole():     void { $this->resetPage(); }
    public function updatedDateFrom(): void { $this->resetPage(); }
    public function updatedDateTo():   void { $this->resetPage(); }
    public function updatedFlagged():  void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search','module','action','severity','role','dateFrom','dateTo','flagged']);
        $this->resetPage();
    }

    // ── Click row → view detail (auto-flagged entries are read-only indicators) ──
    public function viewDetail(int $id): void
    {
        $log = AuditLog::findOrFail($id);
        $this->selected = [
            'id'            => $log->id,
            'action'        => $log->action,
            'action_label'  => $log->action_label,
            'action_icon'   => $log->action_icon,
            'module'        => $log->module,
            'module_label'  => $log->module_label,
            'user_name'     => $log->user_name,
            'user_email'    => $log->user_email,
            'user_role'     => strtoupper($log->user_role ?? 'SYSTEM'),
            'subject_label' => $log->subject_label,
            'description'   => preg_replace('/\s*\(ID\s+\d+\)/i', '', $log->description),
            'old_values'    => $log->old_values,
            'new_values'    => $log->new_values,
            'ip_address'    => $log->ip_address,
            'user_agent'    => $log->user_agent,
            'severity'      => $log->severity,
            'severity_badge'=> $log->severity_badge,
            'is_flagged'    => $log->is_flagged,
            'flag_reason'   => $log->flag_reason,
            'created_at'    => $log->created_at->setTimezone('Asia/Manila')->format('F j, Y  h:i:s A'),
        ];
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->selected  = null;
    }

    public function with(): array
    {
        $query = AuditLog::query()
            ->byModule($this->module   ?: null)
            ->byAction($this->action   ?: null)
            ->bySeverity($this->severity ?: null)
            ->byRole($this->role        ?: null)
            ->search($this->search      ?: null)
            ->when($this->flagged, fn ($q) => $q->flagged())
            ->when($this->dateFrom && ! $this->dateTo, function ($q) {
                $q->whereDate('created_at', $this->dateFrom);
            })
            ->when($this->dateFrom && $this->dateTo, function ($q) {
                $q->whereDate('created_at', '>=', $this->dateFrom)
                  ->whereDate('created_at', '<=', $this->dateTo);
            })
            ->orderByDesc('id');

        $stats = Cache::remember('audit_stats', 60, fn() => AuditLog::stats());

        return [
            'logs'       => $query->paginate($this->perPage),
            'stats'      => $stats,
            'hasFilters' => (bool) ($this->search || $this->module || $this->action
                || $this->severity || $this->role || $this->dateFrom || $this->dateTo || $this->flagged),
        ];
    }
}; ?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes slideInFull {
    from { opacity:0; }
    to   { opacity:1; }
}
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

[x-cloak] { display:none !important }

.tbl-load { opacity:.6; pointer-events:none; transition:opacity .2s }
</style>

{{-- Hover tooltip — same pattern as organizer's "View Details" tip --}}
<div id="al-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
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

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-shield-halved text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Audit Logs</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">
                    Complete activity trail for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        PHILCST
                    </span>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-list-ul text-purple-600 text-[10px]"></i>
                {{ number_format($logs->total()) }} Log{{ $logs->total() !== 1 ? 's' : '' }}
            </span>

            <div class="relative inline-flex group">
                <a href="{{ route('admin.audit-logs.export', array_filter([
                        'module'    => $module,
                        'action'    => $action,
                        'severity'  => $severity,
                        'role'      => $role,
                        'search'    => $search,
                        'date_from' => $dateFrom,
                        'date_to'   => $dateTo,
                        'flagged'   => $flagged ?: null,
                    ])) }}"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] no-underline">
                    <i class="fas fa-file-csv text-sm"></i>
                </a>
                <div class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                    <i class="fas fa-file-csv text-[9px] mr-1"></i>Export CSV
                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ STAT CARDS ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 flex-shrink-0">

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="w-9 h-9 rounded-[10px] bg-blue-50 flex items-center justify-center mb-2.5">
                <i class="fas fa-list-ul text-blue-600 text-sm"></i>
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['total']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Total Logs</div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="w-9 h-9 rounded-[10px] bg-cyan-50 flex items-center justify-center mb-2.5">
                <i class="fas fa-calendar-day text-cyan-600 text-sm"></i>
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['today']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Today (PHT)</div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="flex items-start justify-between mb-2.5">
                <div class="w-9 h-9 rounded-[10px] bg-amber-50 flex items-center justify-center">
                    <i class="fas fa-flag text-amber-600 text-sm"></i>
                </div>
                @if($stats['flagged'] > 0)
                <span class="text-[.6rem] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">!</span>
                @endif
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['flagged']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Auto-Flagged</div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="flex items-start justify-between mb-2.5">
                <div class="w-9 h-9 rounded-[10px] bg-red-50 flex items-center justify-center">
                    <i class="fas fa-triangle-exclamation text-red-600 text-sm"></i>
                </div>
                @if($stats['critical'] > 0)
                <span class="text-[.6rem] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-700">!</span>
                @endif
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['critical']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Critical</div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="flex items-start justify-between mb-2.5">
                <div class="w-9 h-9 rounded-[10px] bg-[#f5eef9] flex items-center justify-center">
                    <i class="fas fa-shield-xmark text-[#7a3f91] text-sm"></i>
                </div>
                @if($stats['failed_auth'] > 0)
                <span class="text-[.6rem] font-bold px-1.5 py-0.5 rounded-full bg-purple-100 text-[#7a3f91]">!</span>
                @endif
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['failed_auth']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Failed Auth</div>
        </div>

        <div class="bg-white rounded-2xl p-4 border border-[#E8E0F0] shadow-sm">
            <div class="w-9 h-9 rounded-[10px] bg-gray-100 flex items-center justify-center mb-2.5">
                <i class="fas fa-lock text-gray-600 text-sm"></i>
            </div>
            <div class="text-xl font-bold text-[#333333] leading-none">{{ number_format($stats['locked']) }}</div>
            <div class="text-[.65rem] font-semibold uppercase tracking-wider text-[#777777] mt-1.5">Locked</div>
        </div>

    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-shrink-0" style="height: 68vh; max-height: 68vh; overflow: hidden;">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search name, email, IP…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] placeholder-[#a78bbd] font-normal
                              hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="module"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                <option value="">All Modules</option>
                <option value="auth">Authentication</option>
                <option value="alumni">Alumni</option>
                <option value="organizer">Organizer</option>
                <option value="event">Events</option>
                <option value="job_posting">Job Postings</option>
                <option value="system">System</option>
            </select>

            <select wire:model.live="action"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hidden sm:inline-block
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                <option value="">All Actions</option>
                <optgroup label="Auth">
                    <option value="login">Login</option>
                    <option value="logout">Logout</option>
                    <option value="failed_login">Failed Login</option>
                    <option value="account_locked">Account Locked</option>
                    <option value="password_changed">Password Changed</option>
                </optgroup>
                <optgroup label="Records">
                    <option value="created">Created</option>
                    <option value="updated">Updated / Toggled</option>
                    <option value="deleted">Deleted</option>
                    <option value="verified">Verified</option>
                    <option value="rejected">Rejected</option>
                    <option value="viewed">Viewed</option>
                </optgroup>
            </select>

            <select wire:model.live="severity"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hidden md:inline-block
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                <option value="">All Severity</option>
                <option value="info">Info</option>
                <option value="warning">Warning</option>
                <option value="critical">Critical</option>
            </select>

            <select wire:model.live="role"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hidden lg:inline-block
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                <option value="">All Roles</option>
                <option value="admin">Admin</option>
                <option value="organizer">Organizer</option>
                <option value="alumni">Alumni</option>
                <option value="system">System</option>
            </select>

            <label class="flex items-center gap-2 cursor-pointer px-3 py-2 rounded-lg border transition"
                   :class="$wire.flagged ? 'border-amber-300 bg-amber-50' : 'border-[#E8E0F0] bg-white hover:bg-gray-50'">
                <input wire:model.live="flagged" type="checkbox" class="w-4 h-4 rounded border-gray-300 accent-amber-500">
                <i class="fas fa-flag text-amber-500 text-xs"></i>
                <span class="text-xs font-semibold text-[#333333]">Auto-Flagged</span>
            </label>

            <input wire:model.live="dateFrom" type="date"
                   class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                          hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
            <input wire:model.live="dateTo" type="date"
                   class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                          hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">

            @if($hasFilters)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-purple-50 border-purple-300 text-[#7a3f91]">
                <i class="fas fa-filter text-[9px]"></i>
                Filtered
            </span>
            @endif

            <button wire:click="clearFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="clearFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer ml-auto">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            <select wire:model.live="perPage"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                <option value="15"  @selected($perPage===15)>15 / page</option>
                <option value="20"  @selected($perPage===20)>20 / page</option>
                <option value="50"  @selected($perPage===50)>50 / page</option>
                <option value="100" @selected($perPage===100)>100 / page</option>
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($logs->count() > 0)

            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto scroll-c bg-white"
                 wire:loading.class="tbl-load"
                 wire:target="search,module,action,severity,role,dateFrom,dateTo,flagged,perPage">
                <table class="w-full bg-white border-collapse min-w-[880px]">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Date / Time (PHT)</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Action</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Module</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">User</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Role</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Description</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Severity</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest w-20 text-[#555555]">Flag</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($logs as $log)
                        @php
                            $phtDate = $log->created_at->setTimezone('Asia/Manila');

                            $actionBadge = match($log->action) {
                                'login'          => 'bg-green-50 text-green-700 border-green-200',
                                'logout'         => 'bg-gray-50 text-gray-600 border-gray-200',
                                'failed_login'   => 'bg-amber-50 text-amber-700 border-yellow-300',
                                'account_locked' => 'bg-red-50 text-red-700 border-red-200',
                                'deleted'        => 'bg-red-50 text-red-700 border-red-200',
                                'created'        => 'bg-blue-50 text-blue-700 border-blue-200',
                                'verified'       => 'bg-[#f5eef9] text-[#7a3f91] border-[#d4aaeb]',
                                'viewed'         => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                'updated'        => 'bg-orange-50 text-orange-700 border-orange-200',
                                default          => 'bg-gray-50 text-gray-700 border-gray-200',
                            };

                            $roleColor = match($log->user_role) {
                                'admin'     => 'text-[#7a3f91] font-semibold',
                                'organizer' => 'text-blue-600 font-semibold',
                                'alumni'    => 'text-green-600 font-semibold',
                                default     => 'text-gray-500',
                            };

                            $cleanDesc = preg_replace('/\s*\(ID\s+\d+\)/i', '', $log->description);
                            $shortDesc = mb_strlen($cleanDesc) > 55
                                ? mb_substr($cleanDesc, 0, 52) . '…'
                                : $cleanDesc;
                        @endphp

                        <tr class="transition-colors duration-100 cursor-pointer {{ $log->is_flagged ? 'bg-amber-50/50 hover:bg-amber-100/60' : 'bg-white hover:bg-[#f5f0fa]' }}"
                            wire:click="viewDetail({{ $log->id }})"
                            wire:key="audit-row-{{ $log->id }}"
                            data-al-row>

                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <div class="text-sm font-semibold text-[#333333]">{{ $phtDate->format('M j, Y') }}</div>
                                <div class="text-xs text-[#777777]">{{ $phtDate->format('h:i A') }}</div>
                            </td>

                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold
                                             px-2.5 py-1 rounded-full border {{ $actionBadge }}">
                                    <i class="fa-solid {{ $log->action_icon }} text-[10px]"></i>
                                    {{ $log->action_label }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                <span class="text-xs font-semibold text-[#333333] bg-gray-100 px-2.5 py-1 rounded-lg">
                                    {{ $log->module_label ?? 'System' }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="text-sm font-semibold text-[#333333]">{{ $log->user_name ?? 'System' }}</div>
                                @if($log->user_email)
                                <div class="text-xs text-[#777777]">
                                    {{ mb_strlen($log->user_email) > 25 ? mb_substr($log->user_email, 0, 22).'…' : $log->user_email }}
                                </div>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="text-[.68rem] font-semibold uppercase tracking-wider {{ $roleColor }}">
                                    {{ $log->user_role ?? 'SYSTEM' }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="text-sm text-[#333333] max-w-[260px] truncate">{{ $shortDesc }}</div>
                                @if($log->ip_address)
                                <div class="text-[.68rem] text-[#999999] font-mono mt-0.5">{{ $log->ip_address }}</div>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                <span class="inline-flex items-center gap-1 text-[.68rem] font-semibold
                                             px-2.5 py-1 rounded-full border {{ $log->severity_badge }} whitespace-nowrap">
                                    <i class="fa-solid fa-circle text-[5px]"></i>
                                    {{ ucfirst($log->severity) }}
                                </span>
                            </td>

                            {{-- Flag column: read-only auto badge, no click action --}}
                            <td class="px-4 py-3.5 text-center" @click.stop>
                                @if($log->is_flagged)
                                    <div class="relative inline-flex group">
                                        <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs
                                                     bg-amber-100 text-amber-700 border border-amber-300">
                                            <i class="fa-solid fa-flag"></i>
                                        </span>
                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                            Auto-flagged
                                            <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                        </div>
                                    </div>
                                @else
                                    <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs
                                                 bg-gray-50 text-gray-300 border border-gray-200">
                                        <i class="fa-regular fa-flag"></i>
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f5eef9]">
                    <i class="fa-solid fa-shield-halved text-xl text-[#7a3f91]"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-[#333333]">
                        @if($dateFrom)
                            No logs found for
                            @if($dateTo && $dateTo !== $dateFrom)
                                {{ \Carbon\Carbon::parse($dateFrom)->format('M j') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M j, Y') }}
                            @else
                                {{ \Carbon\Carbon::parse($dateFrom)->format('F j, Y') }}
                            @endif
                        @else
                            No audit logs found
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">Try adjusting your filters.</p>
                </div>
                @if($hasFilters)
                    <button wire:click="clearFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>

        {{-- ── PAGINATION ── --}}
        @php
            $total    = $logs->total();
            $pp       = $logs->perPage();
            $cp       = $logs->currentPage();
            $lp       = $logs->lastPage();
            $from     = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to       = min($cp * $pp, $total);
            $pgStart  = max(1, $cp - 2);
            $pgEnd    = min($lp, $cp + 2);
        @endphp
        <div class="flex-shrink-0 border-t border-purple-800/30 px-4 flex items-center justify-between gap-2 flex-wrap min-h-[48px] py-1"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ number_format($total) }}</strong>
                entr{{ $total !== 1 ? 'ies' : 'y' }}
                @if($hasFilters)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($logs->onFirstPage()) disabled @endif
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
                        @if(!$logs->hasMorePages()) disabled @endif
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

{{-- ══ DETAIL VIEW — FULL SCREEN (organizer view-modal style, read-only, auto-flag indicator only) ══ --}}
@if($showModal && $selected)

@php
    function auditFormatValue(mixed $val): string {
        if (is_null($val) || $val === '')     return '—';
        if (is_bool($val))                    return $val ? 'Yes' : 'No';
        if (is_array($val))                   return implode(', ', array_map('auditFormatValue', $val));
        $str = (string) $val;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $str)) {
            try {
                return \Carbon\Carbon::parse($str)->setTimezone('Asia/Manila')->format('M j, Y · g:i A');
            } catch (\Throwable) {}
        }
        return $str;
    }

    function auditFormatKey(string $key): string {
        return ucwords(str_replace('_', ' ', $key));
    }
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden fs-in"
     wire:keydown.escape.window="closeModal">

    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid {{ $selected['action_icon'] }} text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">{{ $selected['module_label'] }} &nbsp;·&nbsp; Log #{{ $selected['id'] }}</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $selected['action_label'] }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">

            @if($selected['is_flagged'])
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-400/30 border border-amber-300/40 text-white text-xs font-bold">
                <i class="fa-solid fa-flag text-[11px]"></i>
                <span class="hidden sm:inline">Auto-Flagged</span>
            </span>
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeModal" type="button"
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

        {{-- LEFT SUMMARY COLUMN --}}
        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c">

            <div class="relative mx-5 mt-5 mb-3 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-center h-20"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="absolute top-2 right-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-white text-xs font-bold border {{ $selected['severity_badge'] }} bg-white/10">
                        <i class="fa-solid fa-circle text-[5px]"></i>
                        {{ ucfirst($selected['severity']) }}
                    </span>
                </div>
                @if($selected['is_flagged'])
                <div class="absolute top-2 left-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/90 text-white text-xs font-bold">
                        <i class="fa-solid fa-flag text-[10px]"></i> Auto-Flagged
                    </span>
                </div>
                @endif
            </div>

            <div class="flex flex-col gap-3 px-5 pb-5">

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Timestamp</p>
                    <p class="text-base font-bold text-[#333333]">{{ $selected['created_at'] }}</p>
                </div>

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">User</p>
                    <p class="text-base font-bold text-[#333333]">{{ $selected['user_name'] ?? '—' }}</p>
                    @if($selected['user_email'])
                        <p class="text-sm font-medium mt-0.5 text-[#333333] break-all">{{ $selected['user_email'] }}</p>
                    @endif
                    <span class="inline-block mt-1.5 text-[.65rem] font-semibold uppercase tracking-wider text-[#7a3f91]">{{ $selected['user_role'] }}</span>
                </div>

                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">IP Address</p>
                    <p class="text-base font-bold text-[#333333] break-all">{{ $selected['ip_address'] ?? '—' }}</p>
                </div>

                @if($selected['subject_label'])
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">Subject</p>
                    <p class="text-base font-bold text-[#333333] break-all">{{ $selected['subject_label'] }}</p>
                </div>
                @endif

                @if($selected['is_flagged'] && $selected['flag_reason'])
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-amber-700">Flag Reason (auto)</p>
                    <p class="text-sm font-medium text-amber-800">{{ $selected['flag_reason'] }}</p>
                </div>
                @endif

                @if($selected['user_agent'])
                <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-[#333333]">User Agent</p>
                    <p class="text-xs font-mono text-[#555555] break-all leading-relaxed">{{ $selected['user_agent'] }}</p>
                </div>
                @endif

            </div>
        </div>

        {{-- RIGHT DETAIL COLUMN --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-6 py-5 flex flex-col gap-5">

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Description</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-base leading-relaxed whitespace-pre-wrap font-medium text-[#333333]" style="line-height:1.8;">{{ $selected['description'] }}</p>
                    </div>
                </div>

                @if($selected['old_values'] || $selected['new_values'])
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    @if($selected['old_values'])
                    <div class="bg-white rounded-xl border border-red-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-red-100 bg-red-50">
                            <p class="text-xs font-bold uppercase tracking-widest text-red-600">Before</p>
                        </div>
                        <div class="px-5 py-4">
                            <dl class="space-y-3">
                                @foreach((array) $selected['old_values'] as $key => $val)
                                <div class="flex flex-col gap-0.5">
                                    <dt class="text-[.62rem] font-bold text-red-500 uppercase tracking-wide">
                                        {{ auditFormatKey($key) }}
                                    </dt>
                                    <dd class="text-sm text-red-800 font-medium break-words leading-snug">
                                        {{ auditFormatValue($val) }}
                                    </dd>
                                </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                    @endif

                    @if($selected['new_values'])
                    <div class="bg-white rounded-xl border border-green-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-green-100 bg-green-50">
                            <p class="text-xs font-bold uppercase tracking-widest text-green-600">After</p>
                        </div>
                        <div class="px-5 py-4">
                            <dl class="space-y-3">
                                @foreach((array) $selected['new_values'] as $key => $val)
                                <div class="flex flex-col gap-0.5">
                                    <dt class="text-[.62rem] font-bold text-green-600 uppercase tracking-wide">
                                        {{ auditFormatKey($key) }}
                                    </dt>
                                    <dd class="text-sm text-green-800 font-medium break-words leading-snug">
                                        {{ auditFormatValue($val) }}
                                    </dd>
                                </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                    @endif

                </div>
                @endif

                @if(!$selected['old_values'] && !$selected['new_values'])
                <div class="flex-1 flex items-center justify-center py-10">
                    <p class="text-base font-medium text-[#333333]">No before/after values recorded for this entry.</p>
                </div>
                @endif

            </div>
        </div>

    </div>

</div>
@endif

</div>

<script>
(function () {
    var tip = document.getElementById('al-hover-tip');

    function bindRows() {
        document.querySelectorAll('[data-al-row]').forEach(function (row) {
            if (row._alTipBound) return;
            row._alTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
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
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);
})();
</script>

</div>