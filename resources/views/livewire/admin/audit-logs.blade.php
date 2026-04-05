{{-- resources/views/livewire/admin/audit-logs.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

new #[Layout('app')] class extends Component {
    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    public string  $search    = '';
    public string  $module    = '';
    public string  $action    = '';
    public string  $severity  = '';
    public string  $role      = '';
    public string  $dateFrom  = '';
    public string  $dateTo    = '';
    public bool    $flagged   = false;
    public int     $perPage   = 20;

    // ── Modal ─────────────────────────────────────────────────────────────────
    public ?array  $selected  = null;
    public bool    $showModal = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function mount(): void
    {
        if (Auth::user()?->role !== 'admin') {
            $this->redirect(route('login'));
        }

        // Log page view once per full page load (mount() only fires on initial load,
        // NOT on every Livewire re-render — so this is safe and won't spam the log)
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

    // ── Actions ───────────────────────────────────────────────────────────────

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

    // ── Data ─────────────────────────────────────────────────────────────────

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

        // Always fresh — no cache so every render reflects the real-time count
        $stats = AuditLog::stats();

        return [
            'logs'       => $query->paginate($this->perPage),
            'stats'      => $stats,
            'hasFilters' => (bool) ($this->search || $this->module || $this->action
                || $this->severity || $this->role || $this->dateFrom || $this->dateTo || $this->flagged),
        ];
    }
}; ?>

<div class="min-h-screen bg-gray-50 font-sans antialiased" x-data="{ showExport: false }">

    <style>
        :root {
            --brand:   #7a3f91;
            --dark:    #2b0d3e;
            --brand-l: #f3eafc;
        }
        [x-cloak] { display: none !important; }

        .log-row:hover td { background: #faf5ff; }

        .expand-enter { animation: fadeDown .2s ease-out; }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .table-scroll-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 600px;
        }
        .table-scroll-wrapper thead tr {
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .table-scroll-wrapper::-webkit-scrollbar       { width: 6px; height: 6px; }
        .table-scroll-wrapper::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb { background: #c5a8d8; border-radius: 10px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: var(--brand); }
    </style>

    <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── PAGE HEADER ──────────────────────────────────────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:var(--brand)">
                        <i class="fa-solid fa-shield-halved text-white text-lg"></i>
                    </div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Audit Logs</h1>
                </div>
                <p class="text-sm text-gray-500 ml-13">Complete activity trail — every action recorded and secured.</p>
            </div>

            <div class="flex items-center gap-2">
                @php
                    $exportUrl = route('admin.audit-logs.export', array_filter([
                        'module'    => $module,
                        'action'    => $action,
                        'severity'  => $severity,
                        'role'      => $role,
                        'search'    => $search,
                        'date_from' => $dateFrom,
                        'date_to'   => $dateTo,
                        'flagged'   => $flagged ?: null,
                    ]));
                @endphp
                <a href="{{ $exportUrl }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold border-2 transition-all duration-200 hover:scale-[1.02]"
                   style="border-color:var(--brand); color:var(--brand)">
                    <i class="fa-solid fa-file-csv"></i>
                    Export CSV
                </a>
            </div>
        </div>

        {{-- ── STAT CARDS ───────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            @php
            $cards = [
                ['label'=>'Total Logs',      'value'=>$stats['total'],       'icon'=>'fa-list-ul',              'color'=>'var(--brand)', 'bg'=>'var(--brand-l)'],
                ['label'=>'Today (PHT)',      'value'=>$stats['today'],        'icon'=>'fa-calendar-day',         'color'=>'#0ea5e9',      'bg'=>'#e0f2fe'],
                ['label'=>'Flagged',         'value'=>$stats['flagged'],      'icon'=>'fa-flag',                 'color'=>'#f59e0b',      'bg'=>'#fef3c7'],
                ['label'=>'Critical',        'value'=>$stats['critical'],     'icon'=>'fa-triangle-exclamation', 'color'=>'#dc2626',      'bg'=>'#fee2e2'],
                ['label'=>'Failed Auth',     'value'=>$stats['failed_auth'],  'icon'=>'fa-shield-xmark',         'color'=>'#9333ea',      'bg'=>'#f3e8ff'],
                ['label'=>'Locked Accounts', 'value'=>$stats['locked'],       'icon'=>'fa-lock',                 'color'=>'#1f2937',      'bg'=>'#f3f4f6'],
            ];
            @endphp

            @foreach($cards as $card)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-sm"
                         style="background:{{ $card['bg'] }}; color:{{ $card['color'] }}">
                        <i class="fa-solid {{ $card['icon'] }}"></i>
                    </div>
                </div>
                <div class="text-2xl font-black text-gray-900">{{ number_format($card['value']) }}</div>
                <div class="text-xs font-semibold text-gray-500 mt-0.5">{{ $card['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- ── FILTERS ──────────────────────────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3 items-end">

                <div class="xl:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Search</label>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input wire:model.live.debounce.400ms="search"
                               type="text" placeholder="Name, email, description, IP…"
                               class="w-full pl-8 pr-3 py-2.5 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Module</label>
                    <select wire:model.live="module"
                            class="w-full py-2.5 px-3 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none bg-white transition-colors">
                        <option value="">All Modules</option>
                        <option value="auth">Authentication</option>
                        <option value="alumni">Alumni</option>
                        <option value="organizer">Organizer</option>
                        <option value="event">Events</option>
                        <option value="job_posting">Job Postings</option>
                        <option value="system">System</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Action</label>
                    <select wire:model.live="action"
                            class="w-full py-2.5 px-3 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none bg-white transition-colors">
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
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Severity</label>
                    <select wire:model.live="severity"
                            class="w-full py-2.5 px-3 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none bg-white transition-colors">
                        <option value="">All</option>
                        <option value="info">Info</option>
                        <option value="warning">Warning</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Date From</label>
                    <input wire:model.live="dateFrom" type="date"
                           class="w-full py-2.5 px-3 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none transition-colors">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1 uppercase tracking-wider">Date To</label>
                    <input wire:model.live="dateTo" type="date"
                           class="w-full py-2.5 px-3 text-sm border-2 border-gray-100 rounded-xl focus:border-[#7a3f91] focus:outline-none transition-colors">
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4 mt-4 pt-4 border-t border-gray-50">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <div class="relative">
                        <input wire:model.live="flagged" type="checkbox" class="sr-only peer">
                        <div class="w-10 h-5 bg-gray-200 rounded-full peer-checked:bg-[#7a3f91] transition-colors"></div>
                        <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
                    </div>
                    <span class="text-sm font-semibold text-gray-700">Flagged Only</span>
                </label>

                <div class="flex items-center gap-2 ml-auto">
                    <span class="text-xs text-gray-500 font-medium">Rows per page:</span>
                    <select wire:model.live="perPage"
                            class="py-1.5 px-3 text-sm border-2 border-gray-100 rounded-lg focus:border-[#7a3f91] focus:outline-none bg-white">
                        <option value="15">15</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>

                    @if($hasFilters)
                    <button wire:click="clearFilters"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                        <i class="fa-solid fa-xmark"></i> Clear Filters
                    </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── LOG TABLE ────────────────────────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

            <div wire:loading class="absolute inset-0 bg-white/60 z-10 flex items-center justify-center rounded-2xl">
                <i class="fa-solid fa-circle-notch fa-spin text-2xl" style="color:var(--brand)"></i>
            </div>

            <div class="table-scroll-wrapper">
                <table class="w-full text-sm" style="min-width: 900px;">
                    <thead>
                        <tr class="border-b border-gray-100" style="background:var(--dark)">
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-16">#</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-44">Date / Time (PHT)</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-36">Action</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-32">Module</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider">User</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-24">Role</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider">Description</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-24">Severity</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-16">Flag</th>
                            <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-wider w-20">Detail</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-50">
                        @forelse($logs as $log)
                        @php
                            // Convert to PH time once per row
                            $phtDate = $log->created_at->setTimezone('Asia/Manila');

                            $rowBg = match($log->severity) {
                                'critical' => 'bg-red-50/30',
                                'warning'  => 'bg-yellow-50/30',
                                default    => '',
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
                                'admin'     => 'text-purple-700',
                                'organizer' => 'text-blue-600',
                                'alumni'    => 'text-green-600',
                                default     => 'text-gray-500',
                            };
                        @endphp

                        <tr class="log-row transition-colors duration-100 {{ $rowBg }}">

                            <td class="px-4 py-3 text-gray-400 text-xs font-mono">#{{ $log->id }}</td>

                            <td class="px-4 py-3 text-gray-600 text-xs whitespace-nowrap">
                                <div class="font-semibold text-gray-800">{{ $phtDate->format('M j, Y') }}</div>
                                <div class="text-gray-400">
                                    {{ $phtDate->format('h:i:s A') }}
                                    <span class="text-[10px] text-gray-300 ml-0.5">PHT</span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full border {{ $actionBadge }}">
                                    <i class="fa-solid {{ $log->action_icon }} text-[10px]"></i>
                                    {{ $log->action_label }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="text-xs font-bold text-gray-600 bg-gray-100 px-2 py-1 rounded-lg">
                                    {{ $log->module_label }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-800 text-xs">{{ $log->user_name ?? 'System' }}</div>
                                @if($log->user_email)
                                <div class="text-gray-400 text-xs">{{ $log->user_email }}</div>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="text-xs font-bold uppercase tracking-wider {{ $roleColor }}">
                                    {{ $log->user_role ?? 'system' }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-gray-700 text-xs max-w-xs">
                                <div class="line-clamp-2">{{ $log->description }}</div>
                                @if($log->ip_address)
                                <div class="text-gray-400 font-mono text-[10px] mt-0.5">{{ $log->ip_address }}</div>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="text-xs font-bold px-2 py-1 rounded-full border uppercase tracking-wider {{ $log->severity_badge }}">
                                    {{ $log->severity }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-center">
                                @php
                                    $flagClass = $log->is_flagged
                                        ? 'bg-amber-100 text-amber-600 hover:bg-amber-200'
                                        : 'bg-gray-100 text-gray-400 hover:bg-gray-200';
                                @endphp
                                <button wire:click="toggleFlag({{ $log->id }})"
                                        title="{{ $log->is_flagged ? 'Unflag' : 'Flag' }} this log"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center transition-all hover:scale-110 {{ $flagClass }}">
                                    <i class="fa-solid fa-flag text-xs"></i>
                                </button>
                            </td>

                            <td class="px-4 py-3 text-center">
                                <button wire:click="viewDetail({{ $log->id }})"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center transition-all hover:scale-110"
                                        style="background:var(--brand-l); color:var(--brand)">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="py-20 text-center">
                                <div class="inline-flex flex-col items-center gap-3 text-gray-400">
                                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                                        <i class="fa-solid fa-shield-halved text-2xl"></i>
                                    </div>
                                    <div class="font-semibold text-gray-600">No audit logs found</div>
                                    <div class="text-sm">Try adjusting your filters</div>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($logs->hasPages())
            <div class="border-t border-gray-100 px-5 py-4">
                {{ $logs->links() }}
            </div>
            @endif

            <div class="border-t border-gray-50 px-5 py-3 bg-gray-50/50 flex items-center justify-between">
                <span class="text-xs text-gray-500 font-medium">
                    Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of <strong>{{ number_format($logs->total()) }}</strong> entries
                </span>
                <span class="text-xs text-gray-400">
                    <i class="fa-solid fa-lock-open mr-1"></i>Read-only — logs cannot be deleted
                </span>
            </div>
        </div>

    </div>

    {{-- ── DETAIL MODAL ─────────────────────────────────────────────────────── --}}
    @if($showModal && $selected)
    <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4"
         x-data x-show="true"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">

        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="closeModal"></div>

        <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl overflow-hidden expand-enter" @click.stop>

            <div class="px-6 py-5 flex items-start justify-between border-b border-gray-100" style="background:var(--dark)">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl flex items-center justify-center" style="background:var(--brand)">
                        <i class="fa-solid {{ $selected['action_icon'] }} text-white"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-black text-white">{{ $selected['action_label'] }}</h2>
                        <p class="text-xs text-white/60">{{ $selected['module_label'] }} · Log #{{ $selected['id'] }}</p>
                    </div>
                </div>
                <button wire:click="closeModal" class="text-white/60 hover:text-white transition-colors p-1">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">

                <div class="flex flex-wrap gap-2">
                    <span class="text-xs font-bold px-3 py-1 rounded-full border uppercase tracking-wider {{ $selected['severity_badge'] }}">
                        <i class="fa-solid fa-circle mr-1 text-[8px]"></i>{{ $selected['severity'] }}
                    </span>
                    @if($selected['is_flagged'])
                    <span class="text-xs font-bold px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                        <i class="fa-solid fa-flag mr-1"></i>Flagged
                        @if($selected['flag_reason']) — {{ $selected['flag_reason'] }}@endif
                    </span>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @php
                    $fields = [
                        ['label' => 'User',      'value' => $selected['user_name']     ?? '—'],
                        ['label' => 'Email',      'value' => $selected['user_email']    ?? '—'],
                        ['label' => 'Role',       'value' => $selected['user_role']],
                        ['label' => 'Timestamp',  'value' => $selected['created_at'] . ' (PHT)'],
                        ['label' => 'IP Address', 'value' => $selected['ip_address']   ?? '—'],
                        ['label' => 'Subject',    'value' => $selected['subject_label'] ?? '—'],
                    ];
                    @endphp

                    @foreach($fields as $f)
                    <div class="bg-gray-50 rounded-2xl p-3">
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">{{ $f['label'] }}</div>
                        <div class="text-sm font-semibold text-gray-800 break-all">{{ $f['value'] }}</div>
                    </div>
                    @endforeach
                </div>

                <div class="bg-gray-50 rounded-2xl p-4">
                    <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Description</div>
                    <p class="text-sm text-gray-700">{{ $selected['description'] }}</p>
                </div>

                @if($selected['user_agent'])
                <div class="bg-gray-50 rounded-2xl p-4">
                    <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">User Agent</div>
                    <p class="text-xs font-mono text-gray-500 break-all">{{ $selected['user_agent'] }}</p>
                </div>
                @endif

                @if($selected['old_values'] || $selected['new_values'])
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @if($selected['old_values'])
                    <div class="bg-red-50 rounded-2xl p-4">
                        <div class="text-xs font-bold text-red-400 uppercase tracking-wider mb-2">Before (Old Values)</div>
                        <pre class="text-xs text-red-700 overflow-auto whitespace-pre-wrap">{{ json_encode($selected['old_values'], JSON_PRETTY_PRINT) }}</pre>
                    </div>
                    @endif
                    @if($selected['new_values'])
                    <div class="bg-green-50 rounded-2xl p-4">
                        <div class="text-xs font-bold text-green-500 uppercase tracking-wider mb-2">After (New Values)</div>
                        <pre class="text-xs text-green-700 overflow-auto whitespace-pre-wrap">{{ json_encode($selected['new_values'], JSON_PRETTY_PRINT) }}</pre>
                    </div>
                    @endif
                </div>
                @endif
            </div>

            @php
                $modalFlagClass = $selected['is_flagged']
                    ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200';
                $modalFlagLabel = $selected['is_flagged'] ? 'Unflag' : 'Flag';
            @endphp
            <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between bg-gray-50">
                <button wire:click="toggleFlag({{ $selected['id'] }})"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold transition-all {{ $modalFlagClass }}">
                    <i class="fa-solid fa-flag"></i>
                    {{ $modalFlagLabel }} This Log
                </button>

                <button wire:click="closeModal"
                        class="px-5 py-2 rounded-xl text-sm font-bold text-white transition-all hover:opacity-90"
                        style="background:var(--dark)">
                    Close
                </button>
            </div>
        </div>
    </div>
    @endif

</div>