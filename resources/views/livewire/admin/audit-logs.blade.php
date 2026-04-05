{{-- resources/views/livewire/admin/audit-logs.blade.php --}}
<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

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

        $user = Auth::user();
        AuditLog::create([
            'user_id'     => $user->id,
            'user_role'   => $user->role,
            'user_name'   => $user->name,
            'user_email'  => $user->email,
            'action'      => 'viewed',
            'module'      => 'system',
            'description' => "Admin {$user->name} viewed the Audit Logs page.",
            'ip_address'  => request()->ip(),
            'user_agent'  => substr(request()->userAgent() ?? 'Unknown', 0, 512),
            'session_id'  => session()->getId(),
            'severity'    => 'info',
            'is_flagged'  => false,
        ]);
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
            'description'   => $log->description,
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

        if ($this->showModal && $this->selected && $this->selected['id'] === $id) {
            $this->viewDetail($id);
        }
    }

    public function with(): array
    {
        $query = AuditLog::query()
            ->byModule($this->module    ?: null)
            ->byAction($this->action    ?: null)
            ->bySeverity($this->severity ?: null)
            ->byRole($this->role         ?: null)
            ->dateRange($this->dateFrom  ?: null, $this->dateTo ?: null)
            ->search($this->search       ?: null)
            ->when($this->flagged, fn ($q) => $q->flagged())
            ->orderByDesc('id');

        $stats = AuditLog::stats();

        return [
            'logs'       => $query->paginate($this->perPage),
            'stats'      => $stats,
            'hasFilters' => (bool) ($this->search || $this->module || $this->action
                || $this->severity || $this->role || $this->dateFrom || $this->dateTo || $this->flagged),
        ];
    }
}; ?>

<div class="min-h-screen bg-gray-100">

<style>
:root {
    --brand:     #7a3f91;
    --brand-d:   #5e2f72;
    --brand-50:  #f5eef9;
    --brand-100: #e9d5f3;
}

@keyframes modalIn { from { opacity:0; transform:translateY(16px) scale(.96) } to { opacity:1; transform:none } }
.m-in { animation:modalIn .22s cubic-bezier(.25,.8,.25,1) both; }

@keyframes spin { from{transform:rotate(0)}to{transform:rotate(360deg)} }
.spin { animation:spin 1s linear infinite; }

.scroll-c::-webkit-scrollbar { width:5px; height:5px; }
.scroll-c::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px; }
.scroll-c::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background:#9b5bb0; }

.tbl-load { opacity:.68; pointer-events:none; transition:opacity .2s; }

.tbl-row { transition:background .1s; background:#fff; }
.tbl-row:hover { background:#faf5fd; }

.tbl-row-crit { background:#fee2e2 !important; }
.tbl-row-crit:hover { background:#fecaca !important; }
.tbl-row-crit td { color:#991b1b !important; }

.tbl-row-warn { background:#fff7ed !important; }
.tbl-row-warn:hover { background:#fef3c7 !important; }
.tbl-row-warn td { color:#92400e !important; }
</style>

<div class="flex flex-col px-3 sm:px-5 lg:px-8 pt-5 pb-8 max-w-screen-2xl mx-auto">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 sm:w-13 sm:h-13 rounded-xl bg-[#7a3f91] flex items-center justify-center shadow-md flex-shrink-0"
                 style="box-shadow:0 4px 14px rgba(122,63,145,.35);">
                <i class="fas fa-shield-halved text-white text-base sm:text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 tracking-tight">Audit Logs</h1>
                <p class="text-gray-500 text-xs mt-0.5">Complete activity trail — every action recorded and secured.</p>
            </div>
        </div>
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
           class="inline-flex items-center justify-center gap-1.5 font-bold rounded-lg transition cursor-pointer border-2 px-4 py-2.5 text-sm bg-white hover:bg-gray-50"
           style="border-color: var(--brand); color: var(--brand);">
            <i class="fas fa-file-csv text-xs"></i> Export CSV
        </a>
    </div>

    {{-- STAT CARDS --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        @php
        $cards = [
            ['label'=>'Total Logs',      'value'=>$stats['total'],       'icon'=>'fa-list-ul',              'bg'=>'bg-blue-50', 'color'=>'text-blue-600', 'badge'=>''],
            ['label'=>'Today (PHT)',     'value'=>$stats['today'],       'icon'=>'fa-calendar-day',         'bg'=>'bg-cyan-50', 'color'=>'text-cyan-600', 'badge'=>''],
            ['label'=>'Flagged',        'value'=>$stats['flagged'],     'icon'=>'fa-flag',                 'bg'=>'bg-amber-50', 'color'=>'text-amber-600', 'badge'=>''],
            ['label'=>'Critical',       'value'=>$stats['critical'],    'icon'=>'fa-triangle-exclamation', 'bg'=>'bg-red-50', 'color'=>'text-red-600', 'badge'=>'Alert'],
            ['label'=>'Failed Auth',    'value'=>$stats['failed_auth'], 'icon'=>'fa-shield-xmark',         'bg'=>'bg-purple-50', 'color'=>'text-purple-600', 'badge'=>'Security'],
            ['label'=>'Locked',         'value'=>$stats['locked'],      'icon'=>'fa-lock',                 'bg'=>'bg-gray-100', 'color'=>'text-gray-600', 'badge'=>''],
        ];
        @endphp

        @foreach($cards as $card)
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-lg hover:border-gray-300 transition-all duration-200">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-lg flex items-center justify-center {{ $card['bg'] }}">
                    <i class="fa-solid {{ $card['icon'] }} {{ $card['color'] }} text-lg"></i>
                </div>
                @if($card['badge'])
                <span class="text-xs font-bold px-2 py-1 rounded-full {{ $card['bg'] }} {{ $card['color'] }}">
                    {{ $card['badge'] }}
                </span>
                @endif
            </div>
            <div class="text-3xl font-extrabold text-gray-900">{{ number_format($card['value']) }}</div>
            <div class="text-xs font-semibold text-gray-600 mt-2 uppercase tracking-wide">{{ $card['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl border border-gray-200 flex flex-col overflow-hidden"
         style="box-shadow:0 4px 12px rgba(0,0,0,0.10), 0 2px 4px rgba(0,0,0,0.06); min-height:0; height:calc(100vh - 195px);">

        {{-- FILTER BAR --}}
        <div class="px-4 sm:px-6 py-4 border-b border-gray-200 bg-gray-50 space-y-3">
            {{-- First Row Filters --}}
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
                           class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-900 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition"
                           autocomplete="off" maxlength="100">
                </div>

                {{-- Module --}}
                <select wire:model.live="module"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition">
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
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition hidden sm:inline-block">
                    <option value="">All Actions</option>
                    <option value="login">Login</option>
                    <option value="logout">Logout</option>
                    <option value="failed_login">Failed Login</option>
                    <option value="account_locked">Account Locked</option>
                    <option value="created">Created</option>
                    <option value="updated">Updated</option>
                    <option value="deleted">Deleted</option>
                    <option value="verified">Verified</option>
                    <option value="rejected">Rejected</option>
                    <option value="password_changed">Password Changed</option>
                    <option value="viewed">Viewed</option>
                </select>

                {{-- Severity --}}
                <select wire:model.live="severity"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition hidden md:inline-block">
                    <option value="">All Severity</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="critical">Critical</option>
                </select>

                {{-- Role --}}
                <select wire:model.live="role"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition hidden lg:inline-block">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="organizer">Organizer</option>
                    <option value="alumni">Alumni</option>
                    <option value="system">System</option>
                </select>

                {{-- Reset --}}
                <button wire:click="clearFilters"
                        class="px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-600 hover:bg-gray-100 transition flex items-center gap-1.5">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            {{-- Second Row - Additional Options --}}
            <div class="flex flex-wrap gap-3 items-center justify-between">
                <div class="flex items-center gap-3 flex-wrap">
                    {{-- Flagged Checkbox --}}
                    <label class="flex items-center gap-2 cursor-pointer px-3 py-2 rounded-lg border transition"
                           :class="$wire.flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-300 bg-white hover:bg-gray-50'">
                        <input wire:model.live="flagged" type="checkbox" class="w-4 h-4 rounded border-gray-300 accent-amber-500">
                        <i class="fas fa-flag text-amber-500 text-xs"></i>
                        <span class="text-sm font-medium text-gray-700">Flagged Only</span>
                    </label>

                    {{-- Date From --}}
                    <input wire:model.live="dateFrom" type="date"
                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition">
                </div>

                {{-- Per Page --}}
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-600 font-medium">Per Page:</span>
                    <select wire:model.live="perPage"
                            class="py-2 px-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-100 focus:outline-none bg-white">
                        <option value="15">15</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto scroll-c"
                 wire:loading.class="tbl-load"
                 wire:target="search,module,action,severity,role,dateFrom,dateTo,flagged,perPage">
                <table class="w-full border-collapse min-w-[860px]">
                    <thead>
                        <tr class="sticky top-0 z-10 bg-gray-100 border-b border-gray-200">
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">#</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Date / Time (PHT)</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden lg:table-cell">Module</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">User</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Role</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Description</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Severity</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($logs as $log)
                        @php
                            $phtDate = $log->created_at->setTimezone('Asia/Manila');

                            $rowClass = match($log->severity) {
                                'critical' => 'tbl-row-crit',
                                'warning' => 'tbl-row-warn',
                                default => 'tbl-row',
                            };

                            $actionBadge = match($log->action) {
                                'login'          => 'bg-green-50 text-green-700 border-green-200',
                                'logout'         => 'bg-gray-50 text-gray-600 border-gray-200',
                                'failed_login'   => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                                'account_locked' => 'bg-red-50 text-red-700 border-red-200',
                                'deleted'        => 'bg-red-50 text-red-700 border-red-200',
                                'created'        => 'bg-blue-50 text-blue-700 border-blue-200',
                                'verified'       => 'bg-purple-50 text-purple-700 border-purple-200',
                                'viewed'         => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                default          => 'bg-gray-50 text-gray-700 border-gray-200',
                            };

                            $roleColor = match($log->user_role) {
                                'admin'     => 'text-purple-700 font-semibold',
                                'organizer' => 'text-blue-600 font-semibold',
                                'alumni'    => 'text-green-600 font-semibold',
                                default     => 'text-gray-500',
                            };
                        @endphp

                        <tr class="{{ $rowClass }}">
                            <td class="px-4 sm:px-5 py-3 text-gray-500 text-xs font-mono">{{ $log->id }}</td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="font-semibold text-sm text-gray-900">{{ $phtDate->format('M j, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $phtDate->format('h:i A') }}</div>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full border {{ $actionBadge }}">
                                    <i class="fa-solid {{ $log->action_icon }}"></i>
                                    {{ $log->action_label }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3 hidden lg:table-cell">
                                <span class="text-xs font-semibold text-gray-700 bg-gray-100 px-2.5 py-1 rounded">
                                    {{ $log->module_label ?? 'System' }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="text-sm font-semibold text-gray-900">{{ $log->user_name ?? 'System' }}</div>
                                @if($log->user_email)
                                <div class="text-xs text-gray-500">{{ Str::limit($log->user_email, 25) }}</div>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-3 hidden md:table-cell">
                                <span class="text-xs font-bold uppercase {{ $roleColor }}">
                                    {{ $log->user_role ?? 'SYSTEM' }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="text-xs text-gray-700 line-clamp-2">{{ Str::limit($log->description, 50) }}</div>
                                @if($log->ip_address)
                                <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $log->ip_address }}</div>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-3 text-center">
                                <span class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full border {{ $log->severity_badge }}">
                                    <i class="fa-solid fa-circle text-[6px]"></i>
                                    {{ ucfirst($log->severity) }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-3">
                                <div class="flex items-center justify-center gap-2">
                                    {{-- Flag Button --}}
                                    <button wire:click="toggleFlag({{ $log->id }})"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $log->is_flagged ? 'bg-amber-100 text-amber-700 border border-amber-300 hover:bg-amber-200' : 'bg-gray-100 text-gray-600 border border-gray-300 hover:bg-gray-200' }}">
                                        <i class="fa-solid fa-flag text-xs"></i>
                                        <span class="hidden sm:inline">Flag</span>
                                    </button>

                                    {{-- View Button --}}
                                    <button wire:click="viewDetail({{ $log->id }})"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all bg-[#f5eef9] text-[#7a3f91] border border-purple-200 hover:bg-purple-200">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                        <span class="hidden sm:inline">View</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="py-16 px-4 text-center">
                                <div class="inline-flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 rounded-lg flex items-center justify-center bg-gray-100">
                                        <i class="fa-solid fa-shield-halved text-2xl text-gray-400"></i>
                                    </div>
                                    <p class="font-semibold text-gray-600">No audit logs found</p>
                                    <p class="text-sm text-gray-500">Try adjusting your filters</p>
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
                $from  = $total > 0 ? ($cp-1)*$pp+1 : 0;
                $to    = min($cp*$pp, $total);
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
                        <button wire:click="previousPage" class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm bg-[#7a3f91] text-white hover:bg-[#5e2f72]">← Prev</button>
                    @endif
                    <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold">
                        {{ $cp }} / {{ $logs->lastPage() }}
                    </span>
                    @if($logs->hasMorePages())
                        <button wire:click="nextPage" class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm bg-[#7a3f91] text-white hover:bg-[#5e2f72]">Next →</button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-700 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- DETAIL MODAL --}}
@if($showModal && $selected)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="closeModal">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden m-in flex flex-col max-h-[92vh]"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">

        <div class="px-6 sm:px-7 py-5 flex items-start justify-between border-b border-gray-100 bg-[#2b0d3e] flex-shrink-0">
            <div class="flex items-center gap-3 flex-1">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-[#7a3f91]">
                    <i class="fa-solid {{ $selected['action_icon'] }} text-white text-lg"></i>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-white">{{ $selected['action_label'] }}</h2>
                    <p class="text-xs text-white/70">{{ $selected['module_label'] }} • Log #{{ $selected['id'] }}</p>
                </div>
            </div>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition-colors p-1 flex-shrink-0">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scroll-c p-6 sm:px-7 space-y-5">

            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1 text-xs font-bold px-3 py-1 rounded-full border {{ $selected['severity_badge'] }}">
                    <i class="fa-solid fa-circle text-[6px]"></i>
                    {{ ucfirst($selected['severity']) }}
                </span>
                @if($selected['is_flagged'])
                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                    <i class="fa-solid fa-flag text-xs"></i> Flagged
                </span>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @php
                $fields = [
                    ['label' => 'User',      'value' => $selected['user_name'] ?? '—'],
                    ['label' => 'Email',     'value' => $selected['user_email'] ?? '—'],
                    ['label' => 'Role',      'value' => $selected['user_role']],
                    ['label' => 'Timestamp', 'value' => $selected['created_at']],
                    ['label' => 'IP',        'value' => $selected['ip_address'] ?? '—'],
                    ['label' => 'Subject',   'value' => $selected['subject_label'] ?? '—'],
                ];
                @endphp

                @foreach($fields as $f)
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">{{ $f['label'] }}</div>
                    <div class="text-sm text-gray-800 font-medium break-all">{{ $f['value'] }}</div>
                </div>
                @endforeach
            </div>

            <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Description</div>
                <p class="text-sm text-gray-700">{{ $selected['description'] }}</p>
            </div>

            @if($selected['user_agent'])
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">User Agent</div>
                <p class="text-xs font-mono text-gray-600 break-all">{{ $selected['user_agent'] }}</p>
            </div>
            @endif

            @if($selected['old_values'] || $selected['new_values'])
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($selected['old_values'])
                <div class="bg-red-50 rounded-lg p-4 border border-red-200">
                    <div class="text-xs font-semibold text-red-700 uppercase tracking-wider mb-2">Before</div>
                    <pre class="text-xs text-red-700 overflow-auto whitespace-pre-wrap">{{ json_encode($selected['old_values'], JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
                @if($selected['new_values'])
                <div class="bg-green-50 rounded-lg p-4 border border-green-200">
                    <div class="text-xs font-semibold text-green-700 uppercase tracking-wider mb-2">After</div>
                    <pre class="text-xs text-green-700 overflow-auto whitespace-pre-wrap">{{ json_encode($selected['new_values'], JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
            </div>
            @endif
        </div>

        <div class="px-6 sm:px-7 py-4 border-t border-gray-100 flex items-center justify-between gap-3 bg-gray-50 flex-shrink-0 flex-wrap">
            <button wire:click="toggleFlag({{ $selected['id'] }})"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition-all {{ $selected['is_flagged'] ? 'bg-amber-100 text-amber-700 hover:bg-amber-200 border border-amber-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300 border border-gray-400' }}">
                <i class="fa-solid fa-flag"></i>
                {{ $selected['is_flagged'] ? 'Unflag' : 'Flag' }}
            </button>

            <button wire:click="closeModal"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-all hover:opacity-90 bg-[#2b0d3e]">
                Close
            </button>
        </div>
    </div>
</div>
@endif

</div>