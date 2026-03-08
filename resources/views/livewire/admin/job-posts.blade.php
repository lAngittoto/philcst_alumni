<?php
/**
 * FILE: resources/views/livewire/admin/jobs.blade.php
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Http\Controllers\JobController;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    // ── Filters ──────────────────────────────────────────────
    public string $search            = '';
    public string $filterStatus      = '';
    public string $filterType        = '';
    // ── Manage Jobs Options Modal ─────────────────────────────
    public bool   $showManageModal   = false;
    public string $activeOptionTab   = 'company_type';
    public string $optionModalMode   = 'add';   // 'add' | 'edit'
    public string $optionType        = 'company_type';
    public string $optionLabel       = '';
    public ?int   $editingOptionId   = null;

    // ── View Full Job Modal ───────────────────────────────────
    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    // ── Toggle Status Confirmation ────────────────────────────
    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    // ── Validation ───────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'optionLabel' => 'required|string|max:255',
            'optionType'  => 'required|in:company_type,employment_type,experience_level',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────
    public function updatingSearch()        { $this->resetPage(); }
    public function updatingFilterStatus()  { $this->resetPage(); }
    public function updatingFilterType()    { $this->resetPage(); }

    // ── Computed: Job Postings ────────────────────────────────
    #[Computed]
    public function jobPostings()
    {
        $q = JobPosting::with('organizer')
            ->select([
                'id', 'organizer_id', 'job_title', 'company_name',
                'company_type', 'employment_type', 'experience_level',
                'target_college', 'salary', 'deadline', 'status', 'created_at',
            ]);

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%");
            });
        }
        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }
        if ($this->filterType !== '') {
            $q->where('employment_type', $this->filterType);
        }
        return $q->orderByDesc('created_at')->paginate(15);
    }

    // ── Computed: Job Options grouped ────────────────────────
    #[Computed]
    public function jobOptions()
    {
        return JobOption::orderBy('type')->orderBy('label')
            ->get()
            ->groupBy('type');
    }

    // ── Computed: Viewing Job ─────────────────────────────────
    #[Computed]
    public function viewingJob()
    {
        if (!$this->viewingJobId) return null;
        return app(JobController::class)->getJob($this->viewingJobId);
    }

    // ── Manage Modal ──────────────────────────────────────────
    public function openManageModal(): void
    {
        $this->resetOptionForm();
        $this->showManageModal = true;
    }

    public function closeManageModal(): void
    {
        $this->showManageModal = false;
        $this->resetOptionForm();
    }

    public function selectTab(string $tab): void
    {
        $this->activeOptionTab = $tab;
        $this->resetOptionForm();
        $this->optionType = $tab;
    }

    public function startAdd(): void
    {
        $this->resetOptionForm();
        $this->optionType      = $this->activeOptionTab;
        $this->optionModalMode = 'add';
    }

    public function startEdit(int $id): void
    {
        $opt = JobOption::findOrFail($id);
        $this->editingOptionId = $id;
        $this->optionType      = $opt->type;
        $this->optionLabel     = $opt->label;
        $this->optionModalMode = 'edit';
    }

    public function saveOption(): void
    {
        $this->validate();

        app(JobController::class)->saveOption(
            ['type' => $this->optionType, 'label' => $this->optionLabel],
            $this->editingOptionId
        );

        session()->flash('success',
            $this->optionModalMode === 'edit'
                ? 'Option updated successfully.'
                : 'Option added successfully.'
        );

        $this->resetOptionForm();
    }

    public function deleteOption(int $id): void
    {
        app(JobController::class)->deleteOption($id);
        session()->flash('success', 'Option deleted.');
    }

    private function resetOptionForm(): void
    {
        $this->optionLabel     = '';
        $this->editingOptionId = null;
        $this->optionModalMode = 'add';
        $this->optionType      = $this->activeOptionTab;
        $this->resetValidation();
    }

    // ── View Full Job ─────────────────────────────────────────
    public function viewJob(int $id): void
    {
        $this->viewingJobId  = $id;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingJobId  = null;
    }

    // ── Toggle Status ─────────────────────────────────────────
    public function confirmToggle(int $id): void
    {
        $job = JobPosting::findOrFail($id);
        $this->confirmJobId     = $id;
        $this->confirmAction    = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $this->showConfirmModal = true;
    }

    public function executeToggle(): void
    {
        if ($this->confirmJobId) {
            $newStatus = app(JobController::class)->toggleStatus($this->confirmJobId);
            session()->flash('success', "Job marked as {$newStatus}.");
        }
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
    }

    public function cancelConfirm(): void
    {
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
    }

    // ── Delete Job ────────────────────────────────────────────
    public function deleteJob(int $id): void
    {
        app(JobController::class)->deleteJob($id);
        session()->flash('success', 'Job posting deleted.');
    }

    // ── Reset Filters ─────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search        = '';
        $this->filterStatus  = '';
        $this->filterType    = '';
        $this->resetPage();
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:93vh;">

<style>
    :root { --primary: #7a3f91; --primary-dark: #6a3580; --primary-light: rgba(122,63,145,.1); }

    .scrollbar-custom::-webkit-scrollbar { width: 6px; }
    .scrollbar-custom::-webkit-scrollbar-track { background: transparent; }
    .scrollbar-custom::-webkit-scrollbar-thumb { background: rgba(122,63,145,.3); border-radius: 10px; }
    .scrollbar-custom::-webkit-scrollbar-thumb:hover { background: rgba(122,63,145,.6); }

    @keyframes slideInDown { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes spin        { from { transform:rotate(0deg); }               to { transform:rotate(360deg); } }
    @keyframes fadeInUp    { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
    @keyframes modalIn     { from { opacity:0; transform:scale(.95) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }

    .spin-icon { animation: spin 1s linear infinite; }
    .slide-in  { animation: slideInDown .4s ease-out; }
    .fade-up   { animation: fadeInUp .32s ease-out both; }
    .modal-in  { animation: modalIn .25s cubic-bezier(.16,1,.3,1); }

    .btn-primary {
        background: linear-gradient(135deg, #7a3f91, #7a3f91);
        color: #fff; border: none;
        transition: all .25s cubic-bezier(.16,1,.3,1);
    }
    .btn-primary:hover  { background: linear-gradient(135deg,#6a3580,#6a3580); transform:translateY(-2px); box-shadow:0 8px 16px rgba(122,63,145,.25); }
    .btn-primary:active { transform:translateY(0); }

    .badge-active   { background:#dcfce7; color:#166534; }
    .badge-inactive { background:#fef9c3; color:#854d0e; }

    .manage-tab-active   { background: var(--primary); color: #fff; border-color: var(--primary); }
    .manage-tab-inactive { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    .manage-tab-inactive:hover { background: #f1f5f9; }

    .option-row { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 10px; background: #f8fafc; transition: background .15s; }
    .option-row:hover { background: #f1f5f9; }

    .table-row { transition: background .15s; }
    .table-row:hover { background: #faf5ff; }

    input, select, textarea { border-color: rgba(0,0,0,.15) !important; color: #1e293b !important; }
    input:focus, select:focus, textarea:focus {
        border-color: rgba(122,63,145,.5) !important;
        box-shadow: 0 0 0 3px rgba(122,63,145,.1) !important;
        outline: none !important;
    }

    .grid-loading { opacity: .4; pointer-events: none; transition: opacity .2s; }

    .panel-left  { width: 220px; flex-shrink: 0; border-right: 1px solid #f1f5f9; }
    .panel-right { flex: 1; min-width: 0; }
</style>

{{-- ── FLASH MESSAGE ──────────────────────────────────────── --}}
@if(session('success'))
<div x-data="{ show: true }"
     x-show="show"
     x-init="setTimeout(() => show = false, 3500)"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-end="opacity-0"
     class="fixed top-5 right-5 z-[999] bg-white border border-green-200 text-green-800 px-5 py-3 rounded-xl shadow-lg flex items-center gap-3 text-sm font-semibold">
    <i class="fas fa-circle-check text-green-500"></i>
    {{ session('success') }}
</div>
@endif

{{-- ── MAIN CONTENT ────────────────────────────────────────── --}}
<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-4">

    {{-- HEADER --}}
    <div class="mb-5 shrink-0 slide-in flex items-start justify-between">
        <div>
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3 mb-2">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                    <i class="fas fa-briefcase text-xl"></i>
                </div>
                Job Management
            </h1>
            <p class="text-slate-500 text-sm ml-0.5">Manage job postings and configure dropdown options.</p>
        </div>

        {{-- Manage Jobs Button --}}
        <button wire:click="openManageModal"
                class="mt-1 px-5 py-3 btn-primary rounded-xl text-sm font-bold flex items-center gap-2 shadow-md">
            <i class="fas fa-sliders"></i>
            Manage Job Options
        </button>
    </div>

    {{-- ── JOB POSTINGS TABLE (full width, filters inline) ─── --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 flex flex-col flex-1 min-h-0 fade-up">

        {{-- Table Header + Filters --}}
        <div class="px-6 py-4 border-b border-slate-100 shrink-0">
            <div class="flex flex-wrap gap-3 items-center">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="fas fa-table-list text-[#7a3f91]"></i>
                    Job Postings
                </h2>

                <div class="ml-auto flex flex-wrap gap-2 items-center">
                    {{-- Search --}}
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                        <input type="text"
                               wire:model.live.debounce.400ms="search"
                               placeholder="Search title, company…"
                               class="pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm bg-white w-52"
                               autocomplete="off">
                    </div>

                    {{-- Status Filter (ACTIVE / INACTIVE only) --}}
                    <select wire:model.live="filterStatus"
                            class="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white min-w-[130px]">
                        <option value="">All Status</option>
                        <option value="ACTIVE">Active</option>
                        <option value="INACTIVE">Inactive</option>
                    </select>

                    {{-- Employment Type Filter --}}
                    <select wire:model.live="filterType"
                            class="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white min-w-[140px]">
                        <option value="">All Types</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>

                    {{-- Reset --}}
                    <button wire:click="resetFilters"
                            class="px-3 py-2 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                        <i class="fas fa-rotate-left mr-1.5"></i>Reset
                    </button>

                    {{-- Spinner + Count --}}
                    <div class="flex items-center gap-2">
                        <span wire:loading wire:target="search,filterStatus,filterType,filterCollege,resetFilters,previousPage,nextPage">
                            <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
                        </span>
                        <span class="text-xs font-semibold text-slate-400 bg-slate-100 px-3 py-1.5 rounded-full">
                            {{ number_format($this->jobPostings->total()) }} jobs
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom"
             wire:loading.class="grid-loading"
             wire:target="search,filterStatus,filterType,resetFilters,previousPage,nextPage">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10">
                    <tr style="background:#7a3f91;">
                        <th class="text-left px-5 py-3.5 text-xs font-bold text-white uppercase tracking-widest">#</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Job Title</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Company</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Type</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Organizer</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Deadline</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Status</th>
                        <th class="text-left px-4 py-3.5 text-xs font-bold text-white uppercase tracking-widest">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($this->jobPostings as $job)
                    <tr class="table-row">
                        <td class="px-5 py-3.5 text-slate-400 font-mono text-xs">{{ $job->id }}</td>
                        <td class="px-4 py-3.5">
                            <p class="font-bold text-slate-800">{{ $job->job_title }}</p>
                            <p class="text-xs text-slate-400">{{ $job->experience_level }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="font-semibold text-slate-700">{{ $job->company_name }}</p>
                            <p class="text-xs text-slate-400">{{ $job->company_type }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700">
                                {{ $job->employment_type }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            @if($job->organizer)
                                <p class="font-semibold text-xs" style="color:#7a3f91;">{{ $job->organizer->name }}</p>
                                <p class="text-xs text-slate-400">{{ $job->organizer->department ?? '' }}</p>
                            @else
                                <span class="text-slate-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="{{ now()->gt($job->deadline) ? 'text-red-500' : 'text-slate-600' }} text-xs font-semibold">
                                {{ \Carbon\Carbon::parse($job->deadline)->format('M d, Y') }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold
                                {{ $job->status === 'ACTIVE'   ? 'badge-active'   : '' }}
                                {{ $job->status === 'INACTIVE' ? 'badge-inactive' : '' }}">
                                {{ $job->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-1.5">
                                <button wire:click="viewJob({{ $job->id }})"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-100 text-slate-500 hover:bg-[rgba(122,63,145,.1)] hover:text-[#7a3f91] transition-all"
                                        title="View">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                                <button wire:click="confirmToggle({{ $job->id }})"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg transition-all
                                               {{ $job->status === 'ACTIVE'
                                                  ? 'bg-yellow-50 text-yellow-600 hover:bg-yellow-100'
                                                  : 'bg-green-50 text-green-600 hover:bg-green-100' }}"
                                        title="{{ $job->status === 'ACTIVE' ? 'Set Inactive' : 'Set Active' }}">
                                    <i class="fas fa-toggle-{{ $job->status === 'ACTIVE' ? 'off' : 'on' }} text-xs"></i>
                                </button>
                                <button wire:click="deleteJob({{ $job->id }})"
                                        wire:confirm="Are you sure you want to delete this job posting?"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg bg-red-50 text-red-500 hover:bg-red-100 transition-all"
                                        title="Delete">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8">
                            <div class="flex flex-col items-center justify-center py-20">
                                <i class="fas fa-briefcase text-5xl text-slate-200 mb-4"></i>
                                <p class="font-semibold text-slate-400 text-lg">No job postings found</p>
                                <p class="text-sm text-slate-400 mt-1">Try adjusting your filters</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="bg-white rounded-b-xl border-t border-slate-100 px-6 py-4 shrink-0">
            <div class="flex items-center justify-between">
                @php
                    $total = $this->jobPostings->total();
                    $pp    = $this->jobPostings->perPage();
                    $cp    = $this->jobPostings->currentPage();
                    $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                    $to    = min($cp * $pp, $total);
                @endphp
                <p class="text-slate-600 text-sm">
                    Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span>
                    of <span class="font-semibold text-slate-800">{{ $total }}</span>
                </p>
                <div class="flex gap-2 items-center">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-5 py-2.5 bg-slate-100 text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-5 py-2.5 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-4 py-2.5 text-slate-700 text-sm font-semibold">
                        {{ $this->jobPostings->currentPage() }} / {{ $this->jobPostings->lastPage() }}
                    </span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="px-5 py-2.5 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else
                        <button disabled class="px-5 py-2.5 bg-slate-100 text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     MODAL: Manage Job Options  (left tabs + right edit panel)
══════════════════════════════════════════════════════════ --}}
@if($showManageModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div wire:click="closeManageModal"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl modal-in flex flex-col"
         style="max-height:88vh;">

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 shrink-0">
            <h3 class="text-lg font-black text-slate-800 flex items-center gap-2">
                <i class="fas fa-sliders text-[#7a3f91]"></i>
                Manage Job Options
            </h3>
            <button wire:click="closeManageModal" class="text-slate-400 hover:text-slate-600 transition-colors">
                <i class="fas fa-xmark text-xl"></i>
            </button>
        </div>

        {{-- Modal Body: Left Panel (tabs) + Right Panel (list + form) --}}
        <div class="flex flex-1 min-h-0 overflow-hidden">

            {{-- LEFT: Tab Navigation --}}
            <div class="panel-left py-4 px-3 flex flex-col gap-1 overflow-y-auto scrollbar-custom bg-slate-50 rounded-bl-2xl">
                @foreach([
                    'company_type'     => ['icon' => 'building', 'label' => 'Company Type'],
                    'employment_type'  => ['icon' => 'clock',    'label' => 'Employment Type'],
                    'experience_level' => ['icon' => 'star',     'label' => 'Experience Level'],
                ] as $key => $meta)
                <button wire:click="selectTab('{{ $key }}')"
                        class="w-full text-left px-4 py-3 rounded-xl text-base font-semibold border transition-all flex items-center gap-2
                               {{ $activeOptionTab === $key ? 'manage-tab-active' : 'manage-tab-inactive' }}">
                    <i class="fas fa-{{ $meta['icon'] }} w-4 text-center"></i>
                    {{ $meta['label'] }}
                </button>
                @endforeach

            </div>

            {{-- RIGHT: Option List + Inline Edit Form --}}
            <div class="panel-right flex flex-col min-h-0">

                {{-- Option List --}}
                <div class="flex-1 overflow-y-auto scrollbar-custom px-5 py-4 space-y-2">

                    @php
                        $currentTabKey = $activeOptionTab;
                        $currentLabel  = match($currentTabKey) {
                            'company_type'     => 'Company Type',
                            'employment_type'  => 'Employment Type',
                            'experience_level' => 'Experience Level',
                            default            => $currentTabKey,
                        };
                    @endphp

                    <div class="mb-3">
                        <p class="text-sm font-bold text-slate-500 uppercase tracking-widest">{{ $currentLabel }} Options</p>
                    </div>

                    @forelse($this->jobOptions->get($currentTabKey, collect()) as $opt)
                    <div class="option-row group">
                        <i class="fas fa-tag text-[#7a3f91] shrink-0"></i>
                        <span class="flex-1 text-base font-semibold text-slate-700">{{ $opt->label }}</span>
                        <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button wire:click="startEdit({{ $opt->id }})"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg bg-purple-50 text-[#7a3f91] hover:bg-purple-100 transition-all"
                                    title="Edit">
                                <i class="fas fa-pen text-[11px]"></i>
                            </button>
                            <button wire:click="deleteOption({{ $opt->id }})"
                                    wire:confirm="Delete '{{ $opt->label }}'?"
                                    class="w-7 h-7 flex items-center justify-center rounded-lg bg-red-50 text-red-500 hover:bg-red-100 transition-all"
                                    title="Delete">
                                <i class="fas fa-trash text-[11px]"></i>
                            </button>
                        </div>
                    </div>
                    @empty
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        <i class="fas fa-inbox text-3xl text-slate-200 mb-2"></i>
                        <p class="text-base text-slate-400 font-medium">No options yet for {{ $currentLabel }}</p>
                        <p class="text-sm text-slate-300 mt-1">Use the form below to add one</p>
                    </div>
                    @endforelse
                </div>

                {{-- Inline Add / Edit Form --}}
                <div class="border-t border-slate-100 px-5 py-4 bg-slate-50 rounded-br-2xl shrink-0">
                    <p class="text-sm font-bold text-slate-500 uppercase tracking-widest mb-3">
                        {{ $optionModalMode === 'edit' ? 'Edit Option' : 'Add New Option' }}
                    </p>
                    <div class="flex gap-3 items-start">
                        <div class="flex-1">
                            <input type="text"
                                   wire:model="optionLabel"
                                   wire:keydown.enter="saveOption"
                                   placeholder="e.g. Full-time"
                                   class="w-full px-3 py-2.5 border border-slate-200 rounded-lg text-base bg-white">
                            @error('optionLabel')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button wire:click="saveOption"
                                class="px-4 py-2.5 btn-primary rounded-lg text-sm font-bold whitespace-nowrap flex items-center gap-1.5">
                            <i class="fas fa-{{ $optionModalMode === 'edit' ? 'floppy-disk' : 'plus' }}"></i>
                            {{ $optionModalMode === 'edit' ? 'Save' : 'Add' }}
                        </button>
                        @if($optionModalMode === 'edit')
                        <button wire:click="startAdd"
                                class="px-3 py-2.5 text-slate-500 hover:bg-slate-200 rounded-lg border border-slate-200 transition text-sm font-semibold"
                                title="Cancel edit">
                            <i class="fas fa-xmark"></i>
                        </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     MODAL: View Full Job
══════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php $job = $this->viewingJob; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div wire:click="closeViewModal"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto scrollbar-custom modal-in">

        <div class="sticky top-0 bg-white border-b border-slate-100 px-6 py-5 flex items-start justify-between z-10">
            <div>
                <h3 class="text-xl font-black text-slate-800">{{ $job->job_title }}</h3>
                <p class="text-sm text-slate-500 mt-0.5">{{ $job->company_name }} · {{ $job->company_type }}</p>
            </div>
            <button wire:click="closeViewModal" class="text-slate-400 hover:text-slate-600 transition-colors ml-4 shrink-0">
                <i class="fas fa-xmark text-xl"></i>
            </button>
        </div>

        <div class="px-6 py-5 space-y-5">
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700">
                    <i class="fas fa-clock"></i> {{ $job->employment_type }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-purple-50 text-purple-700">
                    <i class="fas fa-star"></i> {{ $job->experience_level }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold
                    {{ $job->status === 'ACTIVE' ? 'badge-active' : 'badge-inactive' }}">
                    <i class="fas fa-circle text-[8px]"></i> {{ $job->status }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-4">
                @foreach([
                    ['icon' => 'location-dot',   'label' => 'Location',   'value' => $job->location],
                    ['icon' => 'money-bill-wave', 'label' => 'Salary',     'value' => $job->salary ?? 'Not specified'],
                    ['icon' => 'calendar-xmark',  'label' => 'Deadline',   'value' => \Carbon\Carbon::parse($job->deadline)->format('F d, Y')],
                    ['icon' => 'user-tie',        'label' => 'Posted By',  'value' => $job->organizer?->name ?? '—'],
                    ['icon' => 'calendar-plus',   'label' => 'Posted On',  'value' => $job->created_at->format('M d, Y')],
                ] as $info)
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1 flex items-center gap-1.5">
                        <i class="fas fa-{{ $info['icon'] }} text-[#7a3f91]"></i> {{ $info['label'] }}
                    </p>
                    <p class="text-sm font-semibold text-slate-700">{{ $info['value'] }}</p>
                </div>
                @endforeach
            </div>

            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Job Description</p>
                <div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">
                    {{ $job->description }}
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 bg-white border-t border-slate-100 px-6 py-4 flex justify-end gap-3">
            <button wire:click="closeViewModal"
                    class="px-5 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-semibold">
                Close
            </button>
            <button wire:click="confirmToggle({{ $job->id }})"
                    class="px-5 py-2.5 rounded-lg text-sm font-bold transition-all
                           {{ $job->status === 'ACTIVE'
                              ? 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100 border border-yellow-200'
                              : 'bg-green-50 text-green-700 hover:bg-green-100 border border-green-200' }}">
                <i class="fas fa-toggle-{{ $job->status === 'ACTIVE' ? 'off' : 'on' }} mr-1.5"></i>
                Mark as {{ $job->status === 'ACTIVE' ? 'Inactive' : 'Active' }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     MODAL: Confirm Toggle Status
══════════════════════════════════════════════════════════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div wire:click="cancelConfirm"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-in">
        <div class="px-6 py-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center
                        {{ $confirmAction === 'ACTIVE' ? 'bg-green-50' : 'bg-yellow-50' }}">
                <i class="fas fa-toggle-{{ $confirmAction === 'ACTIVE' ? 'on text-green-500' : 'off text-yellow-500' }} text-3xl"></i>
            </div>
            <h3 class="text-lg font-black text-slate-800 mb-2">Mark as {{ $confirmAction }}?</h3>
            <p class="text-sm text-slate-500">
                This will change the job posting status to
                <span class="font-bold {{ $confirmAction === 'ACTIVE' ? 'text-green-600' : 'text-yellow-600' }}">
                    {{ $confirmAction }}
                </span>.
            </p>
        </div>
        <div class="px-6 pb-6 flex gap-3">
            <button wire:click="cancelConfirm"
                    class="flex-1 px-4 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-semibold">
                Cancel
            </button>
            <button wire:click="executeToggle"
                    class="flex-1 px-4 py-2.5 btn-primary rounded-lg text-sm font-bold">
                Confirm
            </button>
        </div>
    </div>
</div>
@endif

</div>