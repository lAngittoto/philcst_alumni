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
    public string $optionModalMode   = 'add';
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

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-50 overflow-hidden" style="height:90vh;">

<style>
    :root { --primary: #7a3f91; --primary-dark: #6a3580; }

    .scrollbar-custom::-webkit-scrollbar { width: 6px; height: 6px; }
    .scrollbar-custom::-webkit-scrollbar-track { background: transparent; }
    .scrollbar-custom::-webkit-scrollbar-thumb { background: rgba(122,63,145,.3); border-radius: 10px; }
    .scrollbar-custom::-webkit-scrollbar-thumb:hover { background: rgba(122,63,145,.6); }

    @keyframes slideInDown { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes modalSlideIn { from { opacity:0; transform:scale(.95) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
    @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }

    .modal-animate { animation: modalSlideIn .3s cubic-bezier(.16,1,.3,1); }
    .spin-icon { animation: spin 1s linear infinite; }

    .btn-primary { background: linear-gradient(135deg, #7a3f91, #6a3580); color: white; border: none; }
    .btn-primary:disabled { background: linear-gradient(135deg, #cbd5e1, #94a3b8); cursor: not-allowed; }

    .input-focus:focus {
        border-color: #7a3f91 !important;
        box-shadow: 0 0 0 3px rgba(122,63,145,.1) !important;
        outline: none !important;
    }

    .table-row-hover { transition: background-color .15s ease; }
    .table-row-hover:hover { background-color: rgba(122,63,145,.05); }

    .tbl-container { transition: opacity .2s ease; }
    .tbl-loading { opacity: .45; pointer-events: none; }

    .manage-tab-active   { background: #7a3f91; color: #fff; border-color: #7a3f91; }
    .manage-tab-inactive { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    .manage-tab-inactive:hover { background: #f1f5f9; }

    .option-row { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; background:#f8fafc; transition:background .15s; }
    .option-row:hover { background:#f1f5f9; }
</style>

{{-- FLASH --}}
@if(session('success'))
<div x-data="{ show: true }"
     x-show="show"
     x-init="setTimeout(() => show = false, 3500)"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm bg-emerald-50 border-emerald-200 text-emerald-800">
    <i class="fas fa-check-circle mt-0.5 text-lg text-emerald-500 flex-shrink-0"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm">Success</div>
        <div class="text-sm mt-0.5 opacity-90">{{ session('success') }}</div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition"><i class="fas fa-times text-sm"></i></button>
</div>
@endif

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
        <div>
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                    <i class="fas fa-briefcase text-xl"></i>
                </div>
                Job Management
            </h1>
            <p class="text-slate-600 text-sm mt-2 ml-0.5">Manage job postings and configure dropdown options</p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <button wire:click="openManageModal"
                    class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                <i class="fas fa-sliders"></i> Manage Job Options
            </button>
        </div>
    </div>

    {{-- TABLE PANEL --}}
    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">

        {{-- Filters bar --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            {{-- Search --}}
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                <input type="text"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Search title, company…"
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
                       autocomplete="off">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            {{-- Employment Type --}}
            <select wire:model.live="filterType"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                <i class="fas fa-rotate-left mr-2"></i>Reset
            </button>

            {{-- Loading spinner --}}
            <span wire:loading wire:target="search,filterStatus,filterType,resetFilters">
                <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterType,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Job Title</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Company</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Type</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Organizer</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Deadline</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->jobPostings as $job)
                    <tr class="table-row-hover">
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-900 text-sm">{{ $job->job_title }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $job->experience_level }}</p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $job->company_type }}</p>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                                {{ $job->employment_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            @if($job->organizer)
                                <p class="font-semibold text-sm text-purple-700">{{ $job->organizer->name }}</p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ $job->organizer->department ?? '' }}</p>
                            @else
                                <span class="text-slate-400 text-sm">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-sm font-semibold {{ now()->gt($job->deadline) ? 'text-red-500' : 'text-slate-700' }}">
                                {{ \Carbon\Carbon::parse($job->deadline)->format('M d, Y') }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $sc = match($job->status) {
                                    'ACTIVE'   => 'bg-emerald-100 text-emerald-700',
                                    'INACTIVE' => 'bg-amber-100 text-amber-700',
                                    default    => 'bg-slate-100 text-slate-600',
                                };
                            @endphp
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">
                                {{ $job->status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <button wire:click="viewJob({{ $job->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                @if($job->status === 'ACTIVE')
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                @else
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                        <i class="fas fa-circle-check"></i> Activate
                                    </button>
                                @endif
                                <button wire:click="deleteJob({{ $job->id }})"
                                        wire:confirm="Are you sure you want to delete this job posting?"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-16 text-center">
                            <i class="fas fa-briefcase text-5xl text-slate-200 block mb-4"></i>
                            <p class="font-semibold text-slate-400">No job postings found</p>
                            <p class="text-sm text-slate-400 mt-1">Try adjusting your filters</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
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
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">
                        {{ $this->jobPostings->currentPage() }} / {{ $this->jobPostings->lastPage() }}
                    </span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     MODAL: Manage Job Options
══════════════════════════════════════════════════════════ --}}
@if($showManageModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div wire:click="closeManageModal"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate flex"
         style="width:760px; height:540px; max-width:95vw; max-height:90vh;">

        {{-- LEFT: Tab Navigation --}}
        <div class="w-52 shrink-0 bg-slate-50 rounded-l-2xl border-r border-slate-100 flex flex-col py-5 px-3 gap-1">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest px-3 mb-2">Categories</p>
            @foreach([
                'company_type'     => ['icon' => 'building',  'label' => 'Company Type'],
                'employment_type'  => ['icon' => 'clock',     'label' => 'Employment Type'],
                'experience_level' => ['icon' => 'star',      'label' => 'Experience Level'],
            ] as $key => $meta)
            <button wire:click="selectTab('{{ $key }}')"
                    class="w-full text-left px-4 py-3 rounded-xl text-sm font-semibold border transition-all flex items-center gap-2
                           {{ $activeOptionTab === $key ? 'manage-tab-active' : 'manage-tab-inactive' }}">
                <i class="fas fa-{{ $meta['icon'] }} w-4 text-center"></i>
                {{ $meta['label'] }}
            </button>
            @endforeach
        </div>

        {{-- RIGHT: Content --}}
        <div class="flex flex-col flex-1 min-w-0 rounded-r-2xl overflow-hidden">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fas fa-sliders text-purple-600"></i>
                    @php
                        echo match($activeOptionTab) {
                            'company_type'     => 'Company Type Options',
                            'employment_type'  => 'Employment Type Options',
                            'experience_level' => 'Experience Level Options',
                            default            => 'Options',
                        };
                    @endphp
                </h3>
                <button wire:click="closeManageModal"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition">
                    <i class="fas fa-xmark text-lg"></i>
                </button>
            </div>

            {{-- Option List --}}
            <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-5 py-4 space-y-2">
                @forelse($this->jobOptions->get($activeOptionTab, collect()) as $opt)
                <div class="option-row group">
                    <i class="fas fa-tag text-purple-500 shrink-0 text-sm"></i>
                    <span class="flex-1 text-sm font-semibold text-slate-700">{{ $opt->label }}</span>
                    <div class="flex gap-1.5">
                        <button wire:click="startEdit({{ $opt->id }})"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-purple-700 bg-purple-50 hover:bg-purple-100 transition border border-purple-100">
                            <i class="fas fa-pen mr-1"></i>Edit
                        </button>
                        <button wire:click="deleteOption({{ $opt->id }})"
                                wire:confirm="Delete '{{ $opt->label }}'?"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 transition border border-red-100">
                            <i class="fas fa-trash mr-1"></i>Delete
                        </button>
                    </div>
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <i class="fas fa-inbox text-4xl text-slate-200 mb-3"></i>
                    <p class="text-sm text-slate-400 font-medium">No options yet</p>
                    <p class="text-xs text-slate-300 mt-1">Use the form below to add one</p>
                </div>
                @endforelse
            </div>

            {{-- Add / Edit Form — always visible at bottom --}}
            <div class="border-t border-slate-100 px-5 py-4 bg-slate-50 rounded-br-2xl shrink-0">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3">
                    {{ $optionModalMode === 'edit' ? '✏️  Editing Option' : '➕  Add New Option' }}
                </p>
                <div class="flex gap-2 items-start">
                    <div class="flex-1">
                        <input type="text"
                               wire:model="optionLabel"
                               wire:keydown.enter="saveOption"
                               placeholder="e.g. Full-time"
                               class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                        @error('optionLabel')
                            <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
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
@endif

{{-- ══════════════════════════════════════════════════════════
     MODAL: View Full Job
══════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php $job = $this->viewingJob; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div wire:click="closeViewModal"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate"
         style="width:640px; max-width:95vw; max-height:90vh; display:flex; flex-direction:column;">

        {{-- Header --}}
        <div class="flex items-start justify-between px-6 py-5 border-b border-slate-100 shrink-0">
            <div>
                <h3 class="text-xl font-bold text-slate-800">{{ $job->job_title }}</h3>
                <p class="text-sm text-slate-500 mt-0.5">{{ $job->company_name }} · {{ $job->company_type }}</p>
            </div>
            <button wire:click="closeViewModal"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition ml-4 shrink-0">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-6 py-5 space-y-5">
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                    <i class="fas fa-clock"></i> {{ $job->employment_type }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-purple-50 text-purple-700">
                    <i class="fas fa-star"></i> {{ $job->experience_level }}
                </span>
                @php
                    $sc = match($job->status) {
                        'ACTIVE'   => 'bg-emerald-100 text-emerald-700',
                        'INACTIVE' => 'bg-amber-100 text-amber-700',
                        default    => 'bg-slate-100 text-slate-600',
                    };
                @endphp
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">
                    <i class="fas fa-circle text-[8px]"></i> {{ $job->status }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    ['icon' => 'location-dot',    'label' => 'Location',   'value' => $job->location ?? '—'],
                    ['icon' => 'money-bill-wave',  'label' => 'Salary',     'value' => $job->salary ?? 'Not specified'],
                    ['icon' => 'calendar-xmark',   'label' => 'Deadline',   'value' => \Carbon\Carbon::parse($job->deadline)->format('F d, Y')],
                    ['icon' => 'user-tie',         'label' => 'Posted By',  'value' => $job->organizer?->name ?? '—'],
                    ['icon' => 'calendar-plus',    'label' => 'Posted On',  'value' => $job->created_at->format('M d, Y')],
                ] as $info)
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1 flex items-center gap-1.5">
                        <i class="fas fa-{{ $info['icon'] }} text-purple-500"></i> {{ $info['label'] }}
                    </p>
                    <p class="text-sm font-semibold text-slate-700">{{ $info['value'] }}</p>
                </div>
                @endforeach
            </div>

            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Job Description</p>
                <div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">{{ $job->description }}</div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-slate-100 shrink-0 flex justify-end gap-3">
            <button wire:click="closeViewModal"
                    class="px-5 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-semibold">
                Close
            </button>
            @if($job->status === 'ACTIVE')
                <button wire:click="confirmToggle({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                    <i class="fas fa-ban"></i> Deactivate
                </button>
            @else
                <button wire:click="confirmToggle({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                    <i class="fas fa-circle-check"></i> Activate
                </button>
            @endif
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

    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:380px; max-width:95vw;">
        <div class="px-6 py-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center
                        {{ $confirmAction === 'ACTIVE' ? 'bg-emerald-50' : 'bg-amber-50' }}">
                @if($confirmAction === 'ACTIVE')
                    <i class="fas fa-circle-check text-emerald-500 text-3xl"></i>
                @else
                    <i class="fas fa-ban text-amber-500 text-3xl"></i>
                @endif
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2">
                {{ $confirmAction === 'ACTIVE' ? 'Activate Job?' : 'Deactivate Job?' }}
            </h3>
            <p class="text-sm text-slate-500">
                This will mark the job posting as
                <span class="font-bold {{ $confirmAction === 'ACTIVE' ? 'text-emerald-600' : 'text-amber-600' }}">
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