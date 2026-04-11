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

    public function toggleFlag(int $id): void
    {
        $log        = AuditLog::findOrFail($id);
        $wasFlagged = $log->is_flagged;

        $log->update([
            'is_flagged'  => ! $wasFlagged,
            'flag_reason' => $wasFlagged ? null : 'Manually flagged by admin',
        ]);

        Cache::forget('audit_stats');

        if ($this->showModal && $this->selected && $this->selected['id'] === $id) {
            $this->viewDetail($id);
        }
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

<div class="min-h-screen bg-gray-100 font-sans antialiased">

<style>
@keyframes fadeUp  { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:translateY(0) } }
@keyframes spinAni { to   { transform:rotate(360deg) } }
@keyframes modalIn { from { opacity:0; transform:translateY(16px) scale(.96) } to { opacity:1; transform:none } }

.fade-up   { animation: fadeUp .42s cubic-bezier(.25,.8,.25,1) both }
.fade-up-1 { animation-delay:.05s } .fade-up-2 { animation-delay:.10s }
.fade-up-3 { animation-delay:.15s } .fade-up-4 { animation-delay:.20s }
.fade-up-5 { animation-delay:.25s } .fade-up-6 { animation-delay:.30s }

.spin-anim { animation: spinAni 1s linear infinite }
.m-in      { animation: modalIn .22s cubic-bezier(.25,.8,.25,1) both }

.scroll-sm::-webkit-scrollbar       { width:3px; height:3px }
.scroll-sm::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb { background:#ddd4f0; border-radius:99px }
.scroll-sm::-webkit-scrollbar-thumb:hover { background:#9b5bb0 }

.tbl-load { opacity:.68; pointer-events:none; transition:opacity .2s }

.tbl-row       { transition:background .1s; background:#fff }
.tbl-row:hover { background:#faf5fd }

[x-cloak] { display:none !important }
</style>

<div class="px-3 sm:px-5 lg:px-7 pt-5 pb-10 max-w-screen-2xl mx-auto space-y-4">

    {{-- ══════════════════════ HEADER ══════════════════════ --}}
    <div class="fade-up bg-[#7a3f91] rounded-2xl px-7 py-6 relative overflow-hidden">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-[42px] h-[42px] rounded-[11px] bg-white/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-shield-halved text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-white/60 text-[.65rem] font-semibold uppercase tracking-[.12em] leading-none">Admin Panel</p>
                        <h1 class="text-white text-[1.55rem] font-semibold leading-tight tracking-tight">Audit Logs</h1>
                    </div>
                </div>
                <p class="text-white/60 text-[.77rem] font-normal mt-0.5 leading-normal">
                    Complete activity trail — every action recorded and secured.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
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
                   class="inline-flex items-center gap-1.5 text-white text-[.75rem] font-semibold
                          bg-white/15 border border-white/30 rounded-[10px] px-3 py-1.5
                          no-underline hover:bg-white/25 transition-colors">
                    <i class="fas fa-file-csv text-xs text-white"></i>
                    Export CSV
                </a>
            </div>
        </div>
    </div>

    {{-- ══════════════════════ STAT CARDS ══════════════════════ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

        {{-- Total Logs --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-1">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-list-ul text-blue-600 text-[17px]"></i>
                </div>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['total']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Total Logs</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">all records</div>
        </div>

        {{-- Today --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-2">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-cyan-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar-day text-cyan-600 text-[17px]"></i>
                </div>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['today']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Today (PHT)</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">today's activity</div>
        </div>

        {{-- Flagged --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-3">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-amber-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-flag text-amber-600 text-[17px]"></i>
                </div>
                @if($stats['flagged'] > 0)
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-yellow-300">Alert</span>
                @endif
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['flagged']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Flagged</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">needs review</div>
        </div>

        {{-- Critical --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-4">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-red-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-triangle-exclamation text-red-600 text-[17px]"></i>
                </div>
                @if($stats['critical'] > 0)
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-700 border border-red-200">Alert</span>
                @endif
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['critical']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Critical</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">high severity</div>
        </div>

        {{-- Failed Auth --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-5">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-[#f5eef9] flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-shield-xmark text-[#7a3f91] text-[17px]"></i>
                </div>
                @if($stats['failed_auth'] > 0)
                <span class="text-[.65rem] font-semibold px-2 py-0.5 rounded-full bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb]">Security</span>
                @endif
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['failed_auth']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Failed Auth</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">login failures</div>
        </div>

        {{-- Locked --}}
        <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all duration-200 fade-up fade-up-6">
            <div class="flex items-start justify-between mb-3">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-gray-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-lock text-gray-600 text-[17px]"></i>
                </div>
            </div>
            <div class="text-[2rem] font-semibold leading-none text-gray-900 tracking-tight">{{ number_format($stats['locked']) }}</div>
            <div class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500 mt-2">Locked</div>
            <div class="text-[.72rem] font-normal text-gray-600 mt-[3px]">account locks</div>
        </div>

    </div>

    {{-- ══════════════════════ TABLE CARD ══════════════════════ --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden fade-up fade-up-4"
         style="min-height:0; height:calc(100vh - 200px);">

        {{-- FILTER BAR --}}
        <div class="px-4 sm:px-6 py-4 border-b border-gray-200 bg-gray-50 space-y-3">

            {{-- Row 1 --}}
            <div class="flex flex-wrap gap-2 items-center">

                {{-- Search --}}
                <div class="relative flex-1 min-w-[200px] max-w-xs"
                     wire:ignore
                     x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text"
                           x-model="q"
                           @input.debounce.400ms="$wire.set('search',q)"
                           placeholder="Search name, email, IP…"
                           class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-[10px] text-sm text-gray-900
                                  bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                           autocomplete="off" maxlength="100">
                </div>

                {{-- Module --}}
                <select wire:model.live="module"
                        class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                    <option value="">All Modules</option>
                    <option value="auth">Authentication</option>
                    <option value="alumni">Alumni</option>
                    <option value="organizer">Organizer</option>
                    <option value="event">Events</option>
                    <option value="job_posting">Job Postings</option>
                    <option value="system">System</option>
                </select>

                {{-- Action --}}
                <select wire:model.live="action"
                        class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition hidden sm:inline-block">
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

                {{-- Severity --}}
                <select wire:model.live="severity"
                        class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition hidden md:inline-block">
                    <option value="">All Severity</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="critical">Critical</option>
                </select>

                {{-- Role --}}
                <select wire:model.live="role"
                        class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                               focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition hidden lg:inline-block">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="organizer">Organizer</option>
                    <option value="alumni">Alumni</option>
                    <option value="system">System</option>
                </select>

                {{-- Reset --}}
                <button wire:click="clearFilters"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-[10px] border border-gray-300
                               bg-white text-sm font-semibold text-gray-600 hover:bg-gray-100 transition cursor-pointer">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            {{-- Row 2 --}}
            <div class="flex flex-wrap gap-3 items-end justify-between">
                <div class="flex items-end gap-3 flex-wrap">

                    {{-- Flagged toggle --}}
                    <div class="flex flex-col">
                        <span class="text-[.6rem] font-semibold uppercase tracking-[.07em] text-transparent mb-0.5 ml-0.5 select-none" aria-hidden="true">·</span>
                        <label class="flex items-center gap-2 cursor-pointer px-3 py-2 rounded-[10px] border transition"
                               :class="$wire.flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-300 bg-white hover:bg-gray-50'">
                            <input wire:model.live="flagged" type="checkbox" class="w-4 h-4 rounded border-gray-300 accent-amber-500">
                            <i class="fas fa-flag text-amber-500 text-xs"></i>
                            <span class="text-[.8rem] font-semibold text-gray-700">Flagged Only</span>
                        </label>
                    </div>

                    {{-- Date range --}}
                    <div class="flex items-end gap-2">
                        <div class="flex flex-col">
                            <span class="text-[.6rem] font-semibold uppercase tracking-[.07em] text-gray-400 mb-0.5 ml-0.5">From</span>
                            <input wire:model.live="dateFrom" type="date"
                                   class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                                          focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                        </div>
                        <div class="flex flex-col">
                            <span class="text-[.6rem] font-semibold uppercase tracking-[.07em] text-gray-400 mb-0.5 ml-0.5">To</span>
                            <input wire:model.live="dateTo" type="date"
                                   class="px-3 py-2 border border-gray-300 rounded-[10px] text-sm bg-white text-gray-700
                                          focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                        </div>
                    </div>

                    @if($dateFrom)
                    <div class="flex flex-col">
                        <span class="text-[.6rem] font-semibold uppercase tracking-[.07em] text-transparent mb-0.5 ml-0.5 select-none" aria-hidden="true">·</span>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-[9px] rounded-[8px] bg-[#f5eef9] border border-[#d4aaeb]">
                            <i class="fas fa-calendar-check text-[#7a3f91] text-[11px]"></i>
                            <span class="text-[.7rem] font-semibold text-[#7a3f91]">
                                @if($dateTo && $dateTo !== $dateFrom)
                                    {{ \Carbon\Carbon::parse($dateFrom)->format('M j') }} – {{ \Carbon\Carbon::parse($dateTo)->format('M j, Y') }}
                                @else
                                    {{ \Carbon\Carbon::parse($dateFrom)->format('M j, Y') }}
                                @endif
                            </span>
                        </div>
                    </div>
                    @endif

                </div>

                {{-- Per Page --}}
                <div class="flex items-center gap-2">
                    <span class="text-[.7rem] font-semibold uppercase tracking-[.07em] text-gray-500">Per Page</span>
                    <select wire:model.live="perPage"
                            class="py-2 px-3 text-sm border border-gray-300 rounded-[10px]
                                   focus:ring-2 focus:ring-[#7a3f91]/10 focus:outline-none bg-white text-gray-700
                                   focus:border-[#7a3f91] transition">
                        <option value="15"  @selected($perPage===15)>15</option>
                        <option value="20"  @selected($perPage===20)>20</option>
                        <option value="50"  @selected($perPage===50)>50</option>
                        <option value="100" @selected($perPage===100)>100</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto scroll-sm"
                 wire:loading.class="tbl-load"
                 wire:target="search,module,action,severity,role,dateFrom,dateTo,flagged,perPage">
                <table class="w-full border-collapse min-w-[860px]">
                    <thead>
                        <tr class="sticky top-0 z-10 bg-gray-100 border-b border-gray-200">
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">Date / Time (PHT)</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">Action</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500 hidden lg:table-cell">Module</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">User</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500 hidden md:table-cell">Role</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">Description</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">Severity</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-[.7rem] font-semibold uppercase tracking-[.08em] text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($logs as $log)
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

                        <tr class="tbl-row">
                            <td class="px-4 sm:px-5 py-3">
                                <div class="text-[.8rem] font-semibold text-gray-900">{{ $phtDate->format('M j, Y') }}</div>
                                <div class="text-[.7rem] font-normal text-gray-500">{{ $phtDate->format('h:i A') }}</div>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 text-[.7rem] font-semibold
                                             px-2.5 py-1 rounded-full border {{ $actionBadge }}">
                                    <i class="fa-solid {{ $log->action_icon }}"></i>
                                    {{ $log->action_label }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3 hidden lg:table-cell">
                                <span class="text-[.7rem] font-semibold text-gray-700 bg-gray-100 px-2.5 py-1 rounded-lg">
                                    {{ $log->module_label ?? 'System' }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="text-[.8rem] font-semibold text-gray-900">{{ $log->user_name ?? 'System' }}</div>
                                @if($log->user_email)
                                <div class="text-[.7rem] font-normal text-gray-500">
                                    {{ mb_strlen($log->user_email) > 25 ? mb_substr($log->user_email, 0, 22).'…' : $log->user_email }}
                                </div>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-3 hidden md:table-cell">
                                <span class="text-[.68rem] font-semibold uppercase tracking-[.07em] {{ $roleColor }}">
                                    {{ $log->user_role ?? 'SYSTEM' }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="text-[.75rem] font-normal text-gray-700">{{ $shortDesc }}</div>
                                @if($log->ip_address)
                                <div class="text-[.68rem] font-normal text-gray-400 font-mono mt-0.5">{{ $log->ip_address }}</div>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-3 text-center">
                                <span class="inline-flex items-center gap-1 text-[.68rem] font-semibold
                                             px-2.5 py-1 rounded-full border {{ $log->severity_badge }}">
                                    <i class="fa-solid fa-circle text-[5px]"></i>
                                    {{ ucfirst($log->severity) }}
                                </span>
                            </td>

                            {{-- ACTION BUTTONS --}}
                            <td class="px-4 sm:px-5 py-3">
                                <div class="flex items-center justify-center gap-2">
                                    <button wire:click="toggleFlag({{ $log->id }})"
                                            class="inline-flex items-center justify-center gap-1.5 w-[72px] py-1.5 rounded-[8px]
                                                   text-[.72rem] font-semibold transition-all cursor-pointer
                                                   {{ $log->is_flagged
                                                       ? 'bg-amber-50 text-amber-700 border border-amber-300 hover:bg-amber-100'
                                                       : 'bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200' }}">
                                        <i class="fa-solid fa-flag text-[11px]"></i>
                                        <span>{{ $log->is_flagged ? 'Unflag' : 'Flag' }}</span>
                                    </button>
                                    <button wire:click="viewDetail({{ $log->id }})"
                                            class="inline-flex items-center justify-center gap-1.5 w-[72px] py-1.5 rounded-[8px]
                                                   text-[.72rem] font-semibold transition-all cursor-pointer
                                                   bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] hover:bg-[#e9d5f3]">
                                        <i class="fa-solid fa-eye text-[11px]"></i>
                                        <span>View</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="py-16 px-4 text-center">
                                <div class="inline-flex flex-col items-center gap-3">
                                    <div class="w-[50px] h-[50px] rounded-[11px] flex items-center justify-center bg-[#f5eef9]">
                                        <i class="fa-solid fa-shield-halved text-xl text-[#7a3f91]"></i>
                                    </div>
                                    <p class="text-[.85rem] font-semibold text-gray-700">
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
                                    <p class="text-[.78rem] font-normal text-gray-500">Try adjusting your filters</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- PAGINATION --}}
        <div class="px-4 sm:px-6 py-3 border-t border-gray-200 bg-[#2b0d3e] shrink-0 rounded-b-2xl">
            @php
                $total = $logs->total();
                $pp    = $logs->perPage();
                $cp    = $logs->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <p class="text-white text-xs sm:text-sm">
                    Showing <span class="font-bold">{{ $from }}–{{ $to }}</span>
                    of <span class="font-bold">{{ number_format($total) }}</span> entries
                </p>
                <div class="flex items-center gap-1.5 flex-wrap">
                    @if($logs->onFirstPage())
                        <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-700 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg
                                       text-xs sm:text-sm bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition-colors">
                            ← Prev
                        </button>
                    @endif
                    <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold">
                        {{ $cp }} / {{ $logs->lastPage() }}
                    </span>
                    @if($logs->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg
                                       text-xs sm:text-sm bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition-colors">
                            Next →
                        </button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-700 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════ DETAIL MODAL ══════════════════════ --}}
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

<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm"
     wire:keydown.escape="closeModal">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden m-in flex flex-col max-h-[92vh]"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">

        <div class="px-6 sm:px-7 py-5 flex items-start justify-between bg-[#7a3f91] flex-shrink-0">
            <div class="flex items-center gap-3 flex-1">
                <div class="w-[42px] h-[42px] rounded-[11px] bg-white/20 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid {{ $selected['action_icon'] }} text-white text-lg"></i>
                </div>
                <div class="flex-1">
                    <h2 class="text-[1.1rem] font-semibold text-white leading-tight">{{ $selected['action_label'] }}</h2>
                    <p class="text-[.7rem] text-white/60 mt-[2px]">
                        {{ $selected['module_label'] }} &nbsp;·&nbsp; Log #{{ $selected['id'] }}
                    </p>
                </div>
            </div>
            <button wire:click="closeModal"
                    class="text-white/60 hover:text-white transition-colors p-1 flex-shrink-0 cursor-pointer">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scroll-sm p-6 sm:px-7 space-y-4">

            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 text-[.7rem] font-semibold
                             px-3 py-1 rounded-full border {{ $selected['severity_badge'] }}">
                    <i class="fa-solid fa-circle text-[5px]"></i>
                    {{ ucfirst($selected['severity']) }}
                </span>
                @if($selected['is_flagged'])
                <span class="inline-flex items-center gap-1.5 text-[.7rem] font-semibold
                             px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                    <i class="fa-solid fa-flag text-[11px]"></i> Flagged
                </span>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @php
                $fields = [
                    ['label' => 'User',       'value' => $selected['user_name']     ?? '—'],
                    ['label' => 'Email',      'value' => $selected['user_email']    ?? '—'],
                    ['label' => 'Role',       'value' => $selected['user_role']],
                    ['label' => 'Timestamp',  'value' => $selected['created_at']],
                    ['label' => 'IP Address', 'value' => $selected['ip_address']    ?? '—'],
                    ['label' => 'Subject',    'value' => $selected['subject_label'] ?? '—'],
                ];
                @endphp
                @foreach($fields as $f)
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <div class="text-[.65rem] font-semibold text-gray-500 uppercase tracking-[.08em] mb-1">{{ $f['label'] }}</div>
                    <div class="text-[.82rem] text-gray-800 font-semibold break-all">{{ $f['value'] }}</div>
                </div>
                @endforeach
            </div>

            <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                <div class="text-[.65rem] font-semibold text-gray-500 uppercase tracking-[.08em] mb-2">Description</div>
                <p class="text-[.82rem] text-gray-700 font-normal leading-relaxed">{{ $selected['description'] }}</p>
            </div>

            @if($selected['user_agent'])
            <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                <div class="text-[.65rem] font-semibold text-gray-500 uppercase tracking-[.08em] mb-2">User Agent</div>
                <p class="text-[.72rem] font-mono text-gray-600 break-all leading-relaxed">{{ $selected['user_agent'] }}</p>
            </div>
            @endif

            @if($selected['old_values'] || $selected['new_values'])
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                @if($selected['old_values'])
                <div class="bg-red-50 rounded-xl p-4 border border-red-200">
                    <div class="text-[.65rem] font-semibold text-red-600 uppercase tracking-[.08em] mb-3">Before</div>
                    <dl class="space-y-2">
                        @foreach((array) $selected['old_values'] as $key => $val)
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-[.62rem] font-bold text-red-500 uppercase tracking-wide">
                                {{ auditFormatKey($key) }}
                            </dt>
                            <dd class="text-[.78rem] text-red-800 font-medium break-words leading-snug">
                                {{ auditFormatValue($val) }}
                            </dd>
                        </div>
                        @endforeach
                    </dl>
                </div>
                @endif

                @if($selected['new_values'])
                <div class="bg-green-50 rounded-xl p-4 border border-green-200">
                    <div class="text-[.65rem] font-semibold text-green-600 uppercase tracking-[.08em] mb-3">After</div>
                    <dl class="space-y-2">
                        @foreach((array) $selected['new_values'] as $key => $val)
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-[.62rem] font-bold text-green-600 uppercase tracking-wide">
                                {{ auditFormatKey($key) }}
                            </dt>
                            <dd class="text-[.78rem] text-green-800 font-medium break-words leading-snug">
                                {{ auditFormatValue($val) }}
                            </dd>
                        </div>
                        @endforeach
                    </dl>
                </div>
                @endif

            </div>
            @endif

        </div>

        <div class="px-6 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3 flex-shrink-0 flex-wrap rounded-b-2xl">
            <button wire:click="toggleFlag({{ $selected['id'] }})"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-[10px] text-sm font-semibold transition-all cursor-pointer
                           {{ $selected['is_flagged']
                               ? 'bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-300'
                               : 'bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300' }}">
                <i class="fa-solid fa-flag text-xs"></i>
                {{ $selected['is_flagged'] ? 'Unflag' : 'Flag this entry' }}
            </button>
            <button wire:click="closeModal"
                    class="px-5 py-2 rounded-[10px] text-sm font-semibold text-white transition-all cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                Close
            </button>
        </div>

    </div>
</div>
@endif

</div>