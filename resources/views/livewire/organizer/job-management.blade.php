<?php
/**
 * FILE: resources/views/livewire/organizer/job-management.blade.php
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\OrganizerJob;
use App\Models\JobOption;
use App\Models\Course;
use App\Http\Controllers\OrganizerJobController;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    // ── Filters ──────────────────────────────────────────────
    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';

    // ── Create / Edit ─────────────────────────────────────────
    public bool   $showFormModal = false;
    public bool   $isEditing     = false;
    public ?int   $editingJobId  = null;

    public string $jobTitle        = '';
    public string $companyName     = '';
    public string $companyType     = '';
    public string $location        = '';
    public string $employmentType  = '';
    public string $experienceLevel = '';
    public string $salary          = '';
    public string $deadline        = '';
    public string $description     = '';
    public string $targetCollege   = '';

    // ── View ─────────────────────────────────────────────────
    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    // ── Delete ────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    // ── Toggle Status ─────────────────────────────────────────
    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    // ── Form errors ───────────────────────────────────────────
    public array $formErrors = [];

    // ── Lifecycle ─────────────────────────────────────────────
    public function updatingSearch()       { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingFilterType()   { $this->resetPage(); }

    // Auto-fill company name + location when company type changes
    public function updatedCompanyType(string $value): void
    {
        if ($value === '') return;
        $opt = JobOption::where('type', 'company_type')
            ->where('label', $value)
            ->first();
        if ($opt) {
            // If PHILCST (or any type whose label contains 'PHILCST'), lock company name
            if (str_contains(strtoupper($opt->label), 'PHILCST')) {
                $this->companyName = $opt->label;
            }
            // Auto-fill location if default_location is set
            if (!empty($opt->default_location)) {
                $this->location = $opt->default_location;
            }
        }
    }

    // ── Computed ──────────────────────────────────────────────

    #[Computed]
    public function jobPostings()
    {
        return app(OrganizerJobController::class)
            ->getJobs($this->search, $this->filterStatus, $this->filterType, 20);
    }

    #[Computed]
    public function jobOptions()
    {
        return app(OrganizerJobController::class)->getOptions();
    }

    #[Computed]
    public function viewingJob(): ?OrganizerJob
    {
        if (!$this->viewingJobId) return null;
        return OrganizerJob::find($this->viewingJobId);
    }

    #[Computed]
    public function collegesWithDepts(): array
    {
        return app(OrganizerJobController::class)->getCollegesWithDepts();
    }

    #[Computed]
    public function collegeMap(): array
    {
        $map = [];
        foreach ($this->collegesWithDepts as $c) {
            $map[$c['name']] = $c['codes'];
        }
        return $map;
    }

    // ── Reset filters ─────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->resetPage();
    }

    // ── Create / Edit actions ─────────────────────────────────
    public function openCreateModal(): void
    {
        $this->resetFormFields();
        $this->showFormModal = true;
        $this->deadline      = now()->addMonth()->format('Y-m-d');
    }

    public function openEditModal(int $id): void
    {
        $job = app(OrganizerJobController::class)->getJob($id);

        $this->isEditing       = true;
        $this->editingJobId    = $id;
        $this->jobTitle        = $job->job_title;
        $this->companyName     = $job->company_name;
        $this->companyType     = $job->company_type;
        $this->location        = $job->location;
        $this->employmentType  = $job->employment_type;
        $this->experienceLevel = $job->experience_level;
        $this->salary          = $job->salary ?? '';
        $this->deadline        = $job->deadline->format('Y-m-d');
        $this->description     = $job->description;
        $this->targetCollege   = $job->target_college ?? '';
        $this->formErrors      = [];
        $this->showFormModal   = true;
        $this->showViewModal   = false;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function saveJob(): void
    {
        $this->formErrors = [];
        $errors = [];

        if (!trim($this->jobTitle))        $errors['jobTitle']        = 'Job title is required.';
        if (!trim($this->companyName))     $errors['companyName']     = 'Company name is required.';
        if (!trim($this->companyType))     $errors['companyType']     = 'Company type is required.';
        if (!trim($this->location))        $errors['location']        = 'Location is required.';
        if (!trim($this->employmentType))  $errors['employmentType']  = 'Employment type is required.';
        if (!trim($this->experienceLevel)) $errors['experienceLevel'] = 'Experience level is required.';
        if (!trim($this->deadline)) {
            $errors['deadline'] = 'Deadline is required.';
        } elseif (!$this->isEditing && strtotime($this->deadline) < strtotime('today')) {
            $errors['deadline'] = 'Deadline must be a future date.';
        }
        if (!trim($this->description)) $errors['description'] = 'Job description is required.';

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        $data = [
            'job_title'        => trim($this->jobTitle),
            'company_name'     => trim($this->companyName),
            'company_type'     => trim($this->companyType),
            'location'         => trim($this->location),
            'employment_type'  => trim($this->employmentType),
            'experience_level' => trim($this->experienceLevel),
            'salary'           => trim($this->salary) ?: null,
            'deadline'         => $this->deadline,
            'description'      => trim($this->description),
            'target_college'   => trim($this->targetCollege) ?: null,
        ];

        $ctrl = app(OrganizerJobController::class);

        if ($this->isEditing) {
            $ctrl->updateJob($this->editingJobId, $data);
            $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully!');
        } else {
            $ctrl->createJob($data);
            $this->dispatch('flash-message', type: 'success', message: 'Job posting created!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    // ── View actions ──────────────────────────────────────────
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

    // ── Delete actions ────────────────────────────────────────
    public function confirmDelete(int $id): void
    {
        $job = app(OrganizerJobController::class)->getJob($id);
        $this->deleteJobId     = $id;
        $this->deleteJobTitle  = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteJobId) {
            app(OrganizerJobController::class)->deleteJob($this->deleteJobId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' deleted.");
        }
        $this->showDeleteModal = false;
        $this->deleteJobId     = null;
        $this->deleteJobTitle  = '';
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId     = null;
        $this->deleteJobTitle  = '';
    }

    // ── Toggle actions ────────────────────────────────────────
    public function confirmToggle(int $id): void
    {
        $job = app(OrganizerJobController::class)->getJob($id);
        $this->confirmJobId     = $id;
        $this->confirmAction    = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $this->showConfirmModal = true;
    }

    public function executeToggle(): void
    {
        if ($this->confirmJobId) {
            $newStatus = app(OrganizerJobController::class)->toggleStatus($this->confirmJobId);
            $this->dispatch('flash-message', type: 'success', message: "Job marked as {$newStatus}.");
        }
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }
    }

    public function cancelConfirm(): void
    {
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
    }

    // ── Private helpers ───────────────────────────────────────
    private function resetFormFields(): void
    {
        $this->jobTitle = $this->companyName = $this->companyType = $this->location = '';
        $this->employmentType = $this->experienceLevel = $this->salary = '';
        $this->deadline = $this->description = $this->targetCollege = '';
        $this->formErrors   = [];
        $this->editingJobId = null;
        $this->isEditing    = false;
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-50 overflow-hidden" style="height:90vh;">

<style>
    .scrollbar-custom::-webkit-scrollbar{width:6px;height:6px}
    .scrollbar-custom::-webkit-scrollbar-track{background:transparent}
    .scrollbar-custom::-webkit-scrollbar-thumb{background:rgba(122,63,145,.3);border-radius:10px}
    .scrollbar-custom::-webkit-scrollbar-thumb:hover{background:rgba(122,63,145,.6)}
    @keyframes slideInDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
    @keyframes modalSlideIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    .modal-animate{animation:modalSlideIn .3s cubic-bezier(.16,1,.3,1)}
    .spin-icon{animation:spin 1s linear infinite}
    .btn-primary{background:linear-gradient(135deg,#7a3f91,#6a3580);color:white;border:none}
    .btn-primary:disabled{background:linear-gradient(135deg,#cbd5e1,#94a3b8);cursor:not-allowed}
    .input-focus:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .table-row-hover{transition:background-color .15s ease}
    .table-row-hover:hover{background-color:rgba(122,63,145,.05)}
    .tbl-container{transition:opacity .2s ease}
    .tbl-loading{opacity:.45;pointer-events:none}
    .form-label{display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.5rem}
    .form-input{width:100%;padding:.625rem 1rem;border:1.5px solid #d1d5db;border-radius:.5rem;font-size:.875rem;color:#1e293b;background:#fff;transition:border-color .15s,box-shadow .15s}
    .form-input:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .form-input:disabled,.form-input[readonly]{background:#f1f5f9;color:#64748b;cursor:not-allowed}
    .form-error{font-size:.75rem;color:#ef4444;margin-top:.375rem;display:flex;align-items:center;gap:.3rem}
    .field-error{border-color:#ef4444!important}
    .field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}
    .info-card{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:1rem}
    .info-card-label{font-size:.68rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.4rem;display:flex;align-items:center;gap:.4rem}
    .info-card-value{font-size:.875rem;font-weight:700;color:#1e293b}
    .info-card-sub{font-size:.75rem;font-weight:600;color:#64748b;margin-top:.2rem}
</style>

{{-- ── FLASH ──────────────────────────────────────────────────── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,4500);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-[60] flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm"
     :class="type==='success'?'bg-emerald-50 border-emerald-200 text-emerald-800':'bg-red-50 border-red-200 text-red-800'"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0"
       :class="type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':'Error'"></div>
        <div class="text-sm mt-0.5 opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- ── HEADER ──────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-5 shrink-0"
         style="animation:slideInDown .5s ease-out;">
        <h1 class="text-3xl font-bold text-slate-800 flex items-center gap-3">
            <div class="w-11 h-11 btn-primary rounded-lg flex items-center justify-center shadow-md shrink-0">
                <i class="fas fa-briefcase text-base"></i>
            </div>
            My Job Postings
        </h1>
        <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
            <i class="fas fa-plus"></i> Post a Job
        </button>
    </div>

    {{-- ── TABLE PANEL ──────────────────────────────────────────── --}}
    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">

            <div class="relative flex-1 min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',val=>{if(val!==this.q)this.q=val;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.150ms="$wire.set('search',q)"
                       placeholder="Search title, company…"
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
                       autocomplete="off">
            </div>

            <select wire:model.live="filterStatus"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <select wire:model.live="filterType"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <button wire:click="resetFilters"
                    class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                <i class="fas fa-rotate-left mr-2"></i>Reset
            </button>

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
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Deadline</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->jobPostings as $job)
                    <tr class="table-row-hover">

                        {{-- Job Title only --}}
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-900 text-sm">{{ $job->job_title }}</p>
                        </td>

                        {{-- Company name only --}}
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p>
                        </td>

                        {{-- Employment Type only --}}
                        <td class="px-6 py-4">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                                {{ $job->employment_type }}
                            </span>
                        </td>

                        {{-- Deadline --}}
                        <td class="px-6 py-4">
                            <span class="text-sm font-semibold {{ now()->gt($job->deadline) ? 'text-red-500' : 'text-slate-700' }}">
                                {{ \Carbon\Carbon::parse($job->deadline)->format('M d, Y') }}
                            </span>
                            @if(now()->gt($job->deadline))
                                <p class="text-xs text-red-400 mt-0.5">Expired</p>
                            @else
                                <p class="text-xs text-slate-400 mt-0.5">{{ \Carbon\Carbon::parse($job->deadline)->diffForHumans() }}</p>
                            @endif
                        </td>

                        {{-- Status --}}
                        <td class="px-6 py-4 text-center">
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $job->status_badge_class }}">
                                {{ $job->status }}
                            </span>
                        </td>

                        {{-- Actions — View, Delete only (no Edit) --}}
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <button wire:click="viewJob({{ $job->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                @if($job->status === 'ACTIVE')
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                @elseif($job->status === 'INACTIVE')
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                        <i class="fas fa-circle-check"></i> Activate
                                    </button>
                                @endif
                                <button wire:click="confirmDelete({{ $job->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-16 text-center">
                            <i class="fas fa-briefcase text-5xl text-slate-200 block mb-4"></i>
                            <p class="font-semibold text-slate-400">No job postings yet</p>
                            <p class="text-sm text-slate-400 mt-1">Click <strong>Post a Job</strong> to get started</p>
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

{{-- ════════════════════════════════════════════════════════════
     MODAL: Create / Edit Job
════════════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl modal-animate w-full flex flex-col"
         style="max-width:780px;max-height:92vh;">

        <div class="flex items-center justify-between px-8 py-5 btn-primary text-white rounded-t-2xl shrink-0">
            <h2 class="text-xl font-bold flex items-center gap-3">
                <i class="fas fa-{{ $isEditing ? 'pen' : 'plus-circle' }}"></i>
                {{ $isEditing ? 'Edit Job Posting' : 'Post a New Job' }}
            </h2>
            <button wire:click="closeFormModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-8 py-4 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2">
                <i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:
            </p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">

            {{-- Job Title --}}
            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="jobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-input {{ isset($formErrors['jobTitle']) ? 'field-error' : '' }}">
                @if(isset($formErrors['jobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['jobTitle'] }}</p>@endif
            </div>

            {{-- Company Type + Company Name --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Company Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="companyType"
                            class="form-input {{ isset($formErrors['companyType']) ? 'field-error' : '' }}">
                        <option value="">— Select Company Type —</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($formErrors['companyType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['companyType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Company Name <span class="text-red-500">*</span></label>
                    @php
                        $isPhilcst = str_contains(strtoupper($companyType), 'PHILCST');
                    @endphp
                    <input wire:model.defer="companyName"
                           type="text"
                           placeholder="e.g. Acme Corporation"
                           @if($isPhilcst) readonly @endif
                           class="form-input {{ isset($formErrors['companyName']) ? 'field-error' : '' }} {{ $isPhilcst ? 'bg-slate-100 text-slate-500 cursor-not-allowed' : '' }}">
                    @if($isPhilcst)
                        <p class="field-hint"><i class="fas fa-lock text-[10px] mr-1"></i>Auto-set based on selected company type.</p>
                    @endif
                    @if(isset($formErrors['companyName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['companyName'] }}</p>@endif
                </div>
            </div>

            {{-- Location --}}
            <div>
                <label class="form-label">Location <span class="text-red-500">*</span></label>
                <input wire:model.defer="location" type="text"
                       placeholder="e.g. Tuguegarao, Cagayan / Remote"
                       @if($isPhilcst) readonly @endif
                       class="form-input {{ isset($formErrors['location']) ? 'field-error' : '' }} {{ $isPhilcst ? 'bg-slate-100 text-slate-500 cursor-not-allowed' : '' }}">
                @if($isPhilcst)
                    <p class="field-hint"><i class="fas fa-lock text-[10px] mr-1"></i>Auto-set based on company type default location.</p>
                @else
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Auto-filled when a company type with a default location is selected. You can still edit it.</p>
                @endif
                @if(isset($formErrors['location']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['location'] }}</p>@endif
            </div>

            {{-- Employment Type + Experience Level --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="employmentType"
                            class="form-input {{ isset($formErrors['employmentType']) ? 'field-error' : '' }}">
                        <option value="">— Select Type —</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($formErrors['employmentType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['employmentType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="experienceLevel"
                            class="form-input {{ isset($formErrors['experienceLevel']) ? 'field-error' : '' }}">
                        <option value="">— Select Level —</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($formErrors['experienceLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['experienceLevel'] }}</p>@endif
                </div>
            </div>

            {{-- Salary + Deadline --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="salary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="deadline" type="date"
                           class="form-input {{ isset($formErrors['deadline']) ? 'field-error' : '' }}">
                    @if(isset($formErrors['deadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['deadline'] }}</p>@endif
                </div>
            </div>

            {{-- Target College --}}
            <div>
                <label class="form-label">
                    Target College
                    <span class="text-slate-400 font-normal text-xs">(Optional — blank = visible to all colleges)</span>
                </label>
                <div x-data="{
                        map: {{ Js::from($this->collegeMap) }},
                        selected: @entangle('targetCollege').defer,
                        get depts() { return this.selected ? (this.map[this.selected] ?? []) : []; }
                     }">
                    <select x-model="selected" class="form-input">
                        <option value="">All Colleges</option>
                        @foreach($this->collegesWithDepts as $c)
                            <option value="{{ $c['name'] }}">{{ $c['name'] }}</option>
                        @endforeach
                    </select>
                    <div x-show="depts.length > 0" x-cloak class="mt-3">
                        <p class="text-xs text-slate-500 mb-2 font-medium">
                            <i class="fas fa-graduation-cap mr-1 text-purple-400"></i>
                            Departments under this college:
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="code in depts" :key="code">
                                <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono"
                                      x-text="code"></span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Job Description --}}
            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="description" rows="6"
                          placeholder="Describe the role, responsibilities, qualifications…"
                          class="form-input resize-none {{ isset($formErrors['description']) ? 'field-error' : '' }}"></textarea>
                @if(isset($formErrors['description']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['description'] }}</p>@endif
            </div>

        </div>

        <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 rounded-b-2xl shrink-0 flex gap-3">
            <button wire:click="closeFormModal"
                    class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-100 transition">
                Cancel
            </button>
            <button wire:click="saveJob"
                    wire:loading.attr="disabled" wire:target="saveJob"
                    class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveJob"><i class="fas fa-spinner spin-icon"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveJob">
                    <i class="fas fa-{{ $isEditing ? 'floppy-disk' : 'paper-plane' }} mr-1.5"></i>
                    {{ $isEditing ? 'Save Changes' : 'Post Job' }}
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: View Full Job
════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job = $this->viewingJob;
    // Determine who last updated: compare updated_at vs created_at
    // If updated_at > created_at by more than 5 seconds, someone edited it
    $wasEdited = $job->updated_at->diffInSeconds($job->created_at) > 5;
    // Check if last editor is admin (user role = admin) or organizer
    // We use the job's organizer to compare — if updated_at != created_at the admin may have changed it
    // We rely on a simple heuristic: if admin page updated it, updated_by would differ
    // Since we don't track updated_by, we show "Admin" if status was changed, else organizer name
    $updaterLabel = $wasEdited ? 'Admin or Organizer' : $job->organizer?->name;
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate flex flex-col"
         style="width:660px;max-width:95vw;max-height:90vh;">

        {{-- Header --}}
        <div class="flex items-start justify-between px-6 py-5 border-b border-slate-200 shrink-0">
            <div>
                <h3 class="text-xl font-extrabold text-slate-900 leading-snug">{{ $job->job_title }}</h3>
                <p class="text-sm font-semibold text-slate-500 mt-0.5">
                    {{ $job->company_name }}
                    <span class="mx-1.5 text-slate-300">·</span>
                    {{ $job->company_type }}
                </p>
            </div>
            <button wire:click="closeViewModal"
                    class="w-9 h-9 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition ml-4 shrink-0">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-6 py-5 space-y-5">

            {{-- Status badges --}}
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                    <i class="fas fa-clock text-[10px]"></i> {{ $job->employment_type }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-purple-50 text-purple-700 border border-purple-100">
                    <i class="fas fa-star text-[10px]"></i> {{ $job->experience_level }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold {{ $job->status_badge_class }} border border-current border-opacity-20">
                    <i class="fas fa-circle text-[8px]"></i> {{ $job->status }}
                </span>
            </div>

            {{-- Info cards grid --}}
            <div class="grid grid-cols-2 gap-3">

                <div class="info-card">
                    <div class="info-card-label"><i class="fas fa-location-dot text-purple-500"></i> Location</div>
                    <div class="info-card-value">{{ $job->location ?? '—' }}</div>
                </div>

                <div class="info-card">
                    <div class="info-card-label"><i class="fas fa-money-bill-wave text-purple-500"></i> Salary</div>
                    <div class="info-card-value">{{ $job->salary ?? 'Not disclosed' }}</div>
                </div>

                <div class="info-card">
                    <div class="info-card-label"><i class="fas fa-calendar-xmark text-purple-500"></i> Deadline</div>
                    <div class="info-card-value">{{ \Carbon\Carbon::parse($job->deadline)->format('F d, Y') }}</div>
                    @if(now()->gt($job->deadline))
                        <div class="info-card-sub text-red-500">Expired</div>
                    @else
                        <div class="info-card-sub">{{ \Carbon\Carbon::parse($job->deadline)->diffForHumans() }}</div>
                    @endif
                </div>

                <div class="info-card">
                    <div class="info-card-label"><i class="fas fa-calendar-plus text-purple-500"></i> Posted On</div>
                    <div class="info-card-value">{{ $job->created_at->format('M d, Y') }}</div>
                    <div class="info-card-sub">{{ $job->created_at->format('g:i A') }}</div>
                </div>

                <div class="info-card col-span-2">
                    <div class="info-card-label"><i class="fas fa-clock-rotate-left text-purple-500"></i> Last Updated</div>
                    <div class="info-card-value">{{ $job->updated_at->diffForHumans() }}</div>
                    <div class="info-card-sub">
                        {{ $job->updated_at->format('M d, Y · g:i A') }}
                        @if($wasEdited)
                            <span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-700 rounded text-[10px] font-bold">
                                <i class="fas fa-pen text-[9px] mr-0.5"></i>Edited
                            </span>
                        @endif
                    </div>
                </div>

            </div>

            {{-- Target College --}}
            @if($job->target_college)
            @php
                $viewDepts = \App\Models\Course::where('college', $job->target_college)
                    ->orderBy('code')->pluck('code')->toArray();
            @endphp
            <div class="bg-purple-50 rounded-xl p-4 border border-purple-200">
                <p class="info-card-label text-purple-600"><i class="fas fa-building-columns text-purple-500"></i> Target College</p>
                <p class="font-extrabold text-purple-900 text-sm">{{ $job->target_college }}</p>
                @if(count($viewDepts))
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach($viewDepts as $dc)
                        <span class="px-2.5 py-1 bg-white text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dc }}</span>
                    @endforeach
                </div>
                @endif
            </div>
            @else
            <div class="info-card">
                <p class="info-card-label"><i class="fas fa-building-columns text-purple-500"></i> Target College</p>
                <p class="info-card-value text-slate-400 italic font-normal">Visible to all colleges</p>
            </div>
            @endif

            {{-- Job Description --}}
            <div>
                <p class="info-card-label mb-2"><i class="fas fa-align-left text-purple-500"></i> Job Description</p>
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200 text-sm font-medium text-slate-800 leading-relaxed whitespace-pre-wrap">{{ $job->description }}</div>
            </div>

        </div>

        {{-- Footer actions --}}
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0 flex justify-end gap-2 rounded-b-2xl flex-wrap">
            <button wire:click="closeViewModal"
                    class="px-5 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-bold">
                <i class="fas fa-xmark mr-1"></i> Close
            </button>
            <button wire:click="openEditModal({{ $job->id }})"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 rounded-lg transition border border-blue-200">
                <i class="fas fa-pen"></i> Edit
            </button>
            @if($job->status === 'ACTIVE')
                <button wire:click="confirmToggle({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                    <i class="fas fa-ban"></i> Deactivate
                </button>
            @elseif($job->status === 'INACTIVE')
                <button wire:click="confirmToggle({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                    <i class="fas fa-circle-check"></i> Activate
                </button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Delete
════════════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:400px;max-width:95vw;">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fas fa-triangle-exclamation text-red-500 text-lg"></i>
            </div>
            <h2 class="text-lg font-extrabold text-red-800">Delete Job Posting</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to permanently delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-2">"{{ $deleteJobTitle }}"</p>
            <p class="text-slate-400 text-xs mb-6 bg-red-50 rounded-lg px-3 py-2 border border-red-100">
                <i class="fas fa-exclamation-circle text-red-400 mr-1.5"></i>
                This action <strong>cannot be undone</strong>.
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelDelete"
                        class="flex-1 px-5 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-50 transition">
                    <i class="fas fa-xmark mr-1"></i> Cancel
                </button>
                <button wire:click="executeDelete"
                        wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-5 py-2.5 bg-red-600 text-white rounded-xl text-sm font-extrabold hover:bg-red-700 transition flex items-center justify-center gap-2 shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin-icon"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Toggle Status
════════════════════════════════════════════════════════════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:380px;max-width:95vw;">
        <div class="px-6 py-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center
                        {{ $confirmAction === 'ACTIVE' ? 'bg-emerald-50' : 'bg-amber-50' }}">
                @if($confirmAction === 'ACTIVE')
                    <i class="fas fa-circle-check text-emerald-500 text-3xl"></i>
                @else
                    <i class="fas fa-ban text-amber-500 text-3xl"></i>
                @endif
            </div>
            <h3 class="text-lg font-extrabold text-slate-800 mb-2">
                {{ $confirmAction === 'ACTIVE' ? 'Activate Job Posting?' : 'Deactivate Job Posting?' }}
            </h3>
            <p class="text-sm text-slate-500">
                This will mark the job as
                <span class="font-bold {{ $confirmAction === 'ACTIVE' ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ $confirmAction }}
                </span>.
            </p>
        </div>
        <div class="px-6 pb-6 flex gap-3">
            <button wire:click="cancelConfirm"
                    class="flex-1 px-4 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-bold">
                <i class="fas fa-xmark mr-1"></i> Cancel
            </button>
            <button wire:click="executeToggle"
                    wire:loading.attr="disabled" wire:target="executeToggle"
                    class="flex-1 px-4 py-2.5 btn-primary rounded-lg text-sm font-extrabold flex items-center justify-center gap-2 shadow-md">
                <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner spin-icon"></i></span>
                <span wire:loading.remove wire:target="executeToggle">
                    <i class="fas fa-{{ $confirmAction === 'ACTIVE' ? 'circle-check' : 'ban' }} mr-1"></i> Confirm
                </span>
            </button>
        </div>
    </div>
</div>
@endif

</div>