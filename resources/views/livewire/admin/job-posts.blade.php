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
    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';

    // ── Manage Options Modal ──────────────────────────────────
    // NOTE: Requires migration — add `default_location` nullable string column to job_options table:
    //   $table->string('default_location')->nullable()->after('label');
    public bool   $showManageModal = false;
    public string $activeOptionTab = 'company_type';
    public string $optionModalMode = 'add';
    public string $optionType      = 'company_type';
    public string $optionLabel     = '';
    public ?int   $editingOptionId = null;
    public array  $optionErrors    = [];

    // ── Company Type Location Assignment ─────────────────────
    // When admin clicks "Location" button on a company type row,
    // this inline form opens below that row to set/update its default_location.
    public ?int   $assignLocationForId    = null;   // company_type option id being assigned
    public string $assignLocationValue    = '';     // the location string being typed
    public array  $assignLocationErrors   = [];

    // ── View Full Job Modal ───────────────────────────────────
    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    // ── Edit Job Modal ────────────────────────────────────────
    public bool   $showEditModal   = false;
    public ?int   $editingJobId    = null;
    public string $editJobTitle    = '';
    public string $editCompany     = '';
    public string $editCompanyType = '';
    public string $editLocation    = '';
    public string $editEmpType     = '';
    public string $editExpLevel    = '';
    public string $editSalary      = '';
    public string $editDeadline    = '';
    public string $editDescription = '';
    public array  $editErrors      = [];

    // ── Toggle Status Confirmation ────────────────────────────
    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    // ── Delete Job Confirmation ───────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    // ── Validation rules for options ──────────────────────────
    protected function rules(): array
    {
        return [
            'optionLabel' => 'required|string|max:255',
            'optionType'  => 'required|in:company_type,employment_type,experience_level',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────
    public function updatingSearch()       { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingFilterType()   { $this->resetPage(); }

    // Auto-fill location when company type is changed in the edit modal
    public function updatedEditCompanyType(string $value): void
    {
        if ($value === '') return;
        $opt = JobOption::where('type', 'company_type')
            ->where('label', $value)
            ->first();
        if ($opt && !empty($opt->default_location)) {
            $this->editLocation = $opt->default_location;
        }
    }

    // ── Computed: Job Postings ────────────────────────────────
    #[Computed]
    public function jobPostings()
    {
        $q = JobPosting::with('organizer')
            ->select([
                'id','organizer_id','job_title','company_name','company_type',
                'location','employment_type','experience_level',
                'target_college','salary','deadline','status','created_at',
            ]);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }
        if ($this->filterStatus !== '') $q->where('status', $this->filterStatus);
        if ($this->filterType   !== '') $q->where('employment_type', $this->filterType);

        return $q->orderByDesc('created_at')->paginate(20);
    }

    // ── Computed: Job Options ─────────────────────────────────
    #[Computed]
    public function jobOptions()
    {
        return JobOption::orderBy('type')->orderBy('label')
            ->get()
            ->groupBy('type');
    }

    // ── Computed: Viewing Job ─────────────────────────────────
    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return app(JobController::class)->getJob($this->viewingJobId);
    }

    // ── Reset Filters ─────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->resetPage();
    }

    // ── Manage Options Modal ──────────────────────────────────
    public function openManageModal(): void
    {
        $this->resetOptionForm();
        $this->showManageModal = true;
    }

    public function closeManageModal(): void
    {
        $this->showManageModal = false;
        $this->resetOptionForm();
        $this->cancelAssignLocation();
    }

    public function selectTab(string $tab): void
    {
        $this->activeOptionTab = $tab;
        $this->optionType      = $tab;
        $this->resetOptionForm();
        $this->cancelAssignLocation();
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
        $this->optionErrors    = [];
        $this->cancelAssignLocation();
    }

    public function saveOption(): void
    {
        $this->optionErrors = [];

        $label = trim($this->optionLabel);

        try {
                $this->validate();
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->optionErrors = collect($e->errors())->map(fn($v) => $v[0])->toArray();
                return;
            }

        app(JobController::class)->saveOption(
            ['type' => $this->optionType, 'label' => $label],
            $this->editingOptionId
        );

        $this->dispatch('flash-message', type: 'success',
            message: $this->optionModalMode === 'edit'
                ? 'Option updated successfully.'
                : 'Option added successfully.'
        );

        $this->resetOptionForm();
    }

    public function deleteOption(int $id): void
    {
        app(JobController::class)->deleteOption($id);
        if ($this->assignLocationForId === $id) $this->cancelAssignLocation();
        $this->dispatch('flash-message', type: 'success', message: 'Option deleted.');
    }

    private function resetOptionForm(): void
    {
        $this->optionLabel     = '';
        $this->editingOptionId = null;
        $this->optionModalMode = 'add';
        $this->optionType      = $this->activeOptionTab;
        $this->optionErrors    = [];
        $this->resetValidation();
    }

    // ── Company Type: Assign Default Location ─────────────────
    public function openAssignLocation(int $id): void
    {
        $opt = JobOption::findOrFail($id);
        $this->assignLocationForId  = $id;
        $this->assignLocationValue  = $opt->default_location ?? '';
        $this->assignLocationErrors = [];
        // Close the option edit form so they don't conflict
        $this->resetOptionForm();
    }

    public function cancelAssignLocation(): void
    {
        $this->assignLocationForId  = null;
        $this->assignLocationValue  = '';
        $this->assignLocationErrors = [];
    }

    public function saveAssignLocation(): void
    {
        $this->assignLocationErrors = [];
        $loc = trim($this->assignLocationValue);

        if ($loc === '') {
            // Allow clearing the location — treat empty as remove
            JobOption::where('id', $this->assignLocationForId)
                ->update(['default_location' => null]);
            $this->dispatch('flash-message', type: 'success', message: 'Default location removed.');
            $this->cancelAssignLocation();
            return;
        }

        if (strlen($loc) < 3) {
            $this->assignLocationErrors['location'] = 'Location must be at least 3 characters.';
            return;
        }
        if (strlen($loc) > 120) {
            $this->assignLocationErrors['location'] = 'Location must not exceed 120 characters.';
            return;
        }
        if (!preg_match('/^[\pL\pN\s,.\-\/()]+$/u', $loc)) {
            $this->assignLocationErrors['location'] = 'Only letters, numbers, commas, dots, dashes, and slashes are allowed.';
            return;
        }

        JobOption::where('id', $this->assignLocationForId)
            ->update(['default_location' => $loc]);

        $this->dispatch('flash-message', type: 'success', message: 'Default location saved.');
        $this->cancelAssignLocation();
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

    // ── Edit Job ──────────────────────────────────────────────
    public function openEditModal(int $id): void
    {
        $job = app(JobController::class)->getJob($id);
        $this->editingJobId    = $id;
        $this->editJobTitle    = $job->job_title;
        $this->editCompany     = $job->company_name;
        $this->editCompanyType = $job->company_type;
        $this->editLocation    = $job->location ?? '';
        $this->editEmpType     = $job->employment_type;
        $this->editExpLevel    = $job->experience_level;
        $this->editSalary      = $job->salary ?? '';
        $this->editDeadline    = $job->deadline->format('Y-m-d');
        $this->editDescription = $job->description;
        $this->editErrors      = [];
        $this->showViewModal   = false;
        $this->showEditModal   = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetEditFields();
    }

    public function saveEditJob(): void
    {
        $this->editErrors = [];
        $errors = [];

        if (!trim($this->editJobTitle))    $errors['editJobTitle']    = 'Job title is required.';
        if (!trim($this->editCompany))     $errors['editCompany']     = 'Company name is required.';
        if (!trim($this->editCompanyType)) $errors['editCompanyType'] = 'Company type is required.';

        $loc = trim($this->editLocation);
        if (!$loc) {
            $errors['editLocation'] = 'Location is required.';
        } elseif (strlen($loc) < 3) {
            $errors['editLocation'] = 'Location must be at least 3 characters.';
        } elseif (strlen($loc) > 120) {
            $errors['editLocation'] = 'Location must not exceed 120 characters.';
        } elseif (!preg_match('/^[\pL\pN\s,.\-\/()]+$/u', $loc)) {
            $errors['editLocation'] = 'Only letters, numbers, commas, dots, dashes, and slashes are allowed.';
        }

        if (!trim($this->editEmpType))     $errors['editEmpType']     = 'Employment type is required.';
        if (!trim($this->editExpLevel))    $errors['editExpLevel']    = 'Experience level is required.';
        if (!trim($this->editDeadline))    $errors['editDeadline']    = 'Deadline is required.';
        if (!trim($this->editDescription)) $errors['editDescription'] = 'Job description is required.';

        if (!empty($errors)) { $this->editErrors = $errors; return; }

        $job = app(JobController::class)->getJob($this->editingJobId);
        $job->update([
            'job_title'        => trim($this->editJobTitle),
            'company_name'     => trim($this->editCompany),
            'company_type'     => trim($this->editCompanyType),
            'location'         => trim($this->editLocation),
            'employment_type'  => trim($this->editEmpType),
            'experience_level' => trim($this->editExpLevel),
            'salary'           => trim($this->editSalary) ?: null,
            'deadline'         => $this->editDeadline,
            'description'      => trim($this->editDescription),
        ]);

        $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully.');
        $this->showEditModal = false;
        $this->resetEditFields();
    }

    private function resetEditFields(): void
    {
        $this->editingJobId = null;
        $this->editJobTitle = $this->editCompany = $this->editCompanyType = '';
        $this->editLocation = $this->editEmpType = $this->editExpLevel   = '';
        $this->editSalary   = $this->editDeadline = $this->editDescription = '';
        $this->editErrors   = [];
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
            $label = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';
            $this->dispatch('flash-message', type: 'success', message: "Job posting has been {$label}.");
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
    public function confirmDelete(int $id): void
    {
        $job = JobPosting::findOrFail($id);
        $this->deleteJobId     = $id;
        $this->deleteJobTitle  = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteJobId) {
            app(JobController::class)->deleteJob($this->deleteJobId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been deleted.");
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
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:90vh;">

<style>
    /* ── Scrollbar ── */
    .scrollbar-custom::-webkit-scrollbar        { width:6px; height:6px }
    .scrollbar-custom::-webkit-scrollbar-track  { background:transparent }
    .scrollbar-custom::-webkit-scrollbar-thumb  { background:rgba(122,63,145,.3); border-radius:10px }
    .scrollbar-custom::-webkit-scrollbar-thumb:hover { background:rgba(122,63,145,.6) }

    /* ── Animations ── */
    @keyframes slideInDown  { from { opacity:0; transform:translateY(-12px) } to { opacity:1; transform:translateY(0) } }
    @keyframes modalSlideIn { from { opacity:0; transform:scale(.96) translateY(12px) } to { opacity:1; transform:scale(1) translateY(0) } }
    @keyframes fadeIn       { from { opacity:0 } to { opacity:1 } }
    @keyframes spin         { from { transform:rotate(0) } to { transform:rotate(360deg) } }

    .modal-animate    { animation:modalSlideIn .26s cubic-bezier(.16,1,.3,1) }
    .backdrop-animate { animation:fadeIn .18s ease }
    .spin-icon        { animation:spin 1s linear infinite }
    .header-animate   { animation:slideInDown .4s ease-out }

    /* ── Buttons ── */
    .btn-primary          { background:linear-gradient(135deg,#7a3f91,#5e2f72); color:#fff; border:none; transition:all .2s }
    .btn-primary:hover:not(:disabled) { background:linear-gradient(135deg,#8b4aa5,#6a3580); box-shadow:0 4px 14px rgba(122,63,145,.35) }
    .btn-primary:disabled { background:linear-gradient(135deg,#cbd5e1,#94a3b8); cursor:not-allowed; box-shadow:none }

    /* ── Form ── */
    .form-label  { display:block; font-size:.78rem; font-weight:700; color:#374151; margin-bottom:.45rem; letter-spacing:.01em }
    .form-input  { width:100%; padding:.625rem 1rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-size:.875rem; color:#1e293b; background:#fff; transition:border-color .15s, box-shadow .15s }
    .form-input:focus { border-color:#7a3f91 !important; box-shadow:0 0 0 3px rgba(122,63,145,.12) !important; outline:none !important }
    .form-error  { font-size:.74rem; color:#ef4444; margin-top:.35rem; display:flex; align-items:center; gap:.3rem }
    .field-error { border-color:#f87171 !important; background:#fff8f8 !important }
    .field-hint  { font-size:.72rem; color:#94a3b8; margin-top:.3rem }

    /* ── Table ── */
    .table-row-hover            { transition:background-color .1s ease }
    .table-row-hover:hover      { background-color:rgba(122,63,145,.04) }
    .tbl-container              { transition:opacity .15s ease }
    .tbl-loading                { opacity:.4; pointer-events:none }

    /* ── Manage Options tabs ── */
    .manage-tab-active   { background:#7a3f91; color:#fff; border-color:#7a3f91 }
    .manage-tab-inactive { background:#f8fafc; color:#64748b; border-color:#e2e8f0 }
    .manage-tab-inactive:hover { background:#f1f5f9 }
    .option-row { display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:10px; background:#f8fafc; transition:background .12s }
    .option-row:hover { background:#f1f5f9 }

    /* ── Input focus (filter bar) ── */
    .input-focus:focus { border-color:#7a3f91 !important; box-shadow:0 0 0 3px rgba(122,63,145,.1) !important; outline:none !important }
</style>

{{-- ── FLASH ────────────────────────────────────────────────── --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,4500); }
     }"
     @flash-message.window="display($event.detail.type, $event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0 scale-100"
     x-transition:leave-end="opacity-0 translate-x-8 scale-95"
     class="fixed top-5 right-6 z-[70] flex items-start gap-3 px-5 py-4 rounded-xl shadow-2xl max-w-sm border backdrop-blur-sm"
     :class="type==='success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0"
       :class="type==='success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-bold text-sm" x-text="type==='success' ? 'Success' : 'Error'"></div>
        <div class="text-sm mt-0.5 opacity-85 leading-snug" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition ml-1">
        <i class="fas fa-times text-sm"></i>
    </button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- ── PAGE HEADER ─────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0 header-animate">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 btn-primary rounded-xl flex items-center justify-center shadow-md shrink-0">
                    <i class="fas fa-briefcase text-sm"></i>
                </div>
                Admin Job Posts Overview
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-[52px]">
                Review, moderate, and manage all job postings submitted by organizers across the platform.
            </p>
        </div>
        <button wire:click="openManageModal"
                class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-xl font-bold hover:shadow-md transition text-sm border border-slate-200 shrink-0">
            <i class="fas fa-sliders text-purple-600"></i> Configure Job Options
        </button>
    </div>

    {{-- ── TABLE PANEL ──────────────────────────────────────────── --}}
    <div class="flex-1 min-h-0 bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col overflow-hidden">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/80 flex flex-wrap gap-3 items-center shrink-0">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{
                     q: '',
                     init(){
                         this.q = $wire.search ?? '';
                         $wire.$watch('search', val => { if (val !== this.q) this.q = val; });
                     }
                 }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.200ms="$wire.set('search', q)"
                       placeholder="Search by job title or company..."
                       class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-100 focus:outline-none transition"
                       autocomplete="off">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus transition">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            {{-- Employment Type --}}
            <select wire:model.live="filterType"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus transition">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    class="px-4 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-bold flex items-center gap-1.5">
                <i class="fas fa-rotate-left text-xs"></i> Reset
            </button>

            {{-- Spinner --}}
            <span wire:loading wire:target="search,filterStatus,filterType,resetFilters"
                  class="flex items-center gap-1.5 text-purple-500 text-sm">
                <i class="fas fa-spinner spin-icon"></i>
                <span class="text-xs font-medium">Refreshing...</span>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterType,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead style="position:sticky;top:0;z-index:10;">
                    <tr class="btn-primary text-white text-left">
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Job Title</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Company</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Type</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Posted By</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Deadline</th>
                        <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wider">Status</th>
                        <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->jobPostings as $job)
                    <tr class="table-row-hover">

                        {{-- Job Title --}}
                        <td class="px-5 py-3.5">
                            <p class="font-bold text-slate-900 text-sm leading-snug">{{ $job->job_title }}</p>
                        </td>

                        {{-- Company --}}
                        <td class="px-5 py-3.5">
                            <p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p>
                        </td>

                        {{-- Employment Type --}}
                        <td class="px-5 py-3.5">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                {{ $job->employment_type }}
                            </span>
                        </td>

                        {{-- Organizer --}}
                        <td class="px-5 py-3.5">
                            @if($job->organizer)
                                @php
                                    $orgDept = $job->organizer->department ?? '';
                                    $hasCollege = $orgDept !== '' && \App\Models\Course::where('college', $orgDept)->exists();
                                    $collegeName = $orgDept !== ''
                                        ? ($hasCollege ? $orgDept : (\App\Models\Course::where('code', $orgDept)->value('college') ?? $orgDept))
                                        : null;
                                @endphp
                                <p class="font-bold text-sm text-purple-700">{{ $job->organizer->name }}</p>
                                @if($collegeName)
                                    <p class="text-xs text-slate-400 mt-0.5 leading-snug">{{ $collegeName }}</p>
                                @endif
                            @else
                                <span class="text-slate-400 text-sm">—</span>
                            @endif
                        </td>

                        {{-- Deadline --}}
                        <td class="px-5 py-3.5">
                            <span class="text-sm font-bold {{ now()->gt($job->deadline) ? 'text-red-500' : 'text-slate-700' }}">
                                {{ \Carbon\Carbon::parse($job->deadline)->format('M d, Y') }}
                            </span>
                            <p class="text-xs mt-0.5 {{ now()->gt($job->deadline) ? 'text-red-400' : 'text-slate-400' }}">
                                {{ now()->gt($job->deadline) ? 'Expired' : \Carbon\Carbon::parse($job->deadline)->diffForHumans() }}
                            </p>
                        </td>

                        {{-- Status --}}
                        <td class="px-5 py-3.5 text-center">
                            @php
                                $sc = match($job->status) {
                                    'ACTIVE'   => 'bg-emerald-100 text-emerald-700',
                                    'INACTIVE' => 'bg-amber-100 text-amber-700',
                                    default    => 'bg-slate-100 text-slate-600',
                                };
                            @endphp
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-bold {{ $sc }}">
                                {{ $job->status }}
                            </span>
                        </td>

                        {{-- Actions --}}
                        <td class="px-5 py-3.5 text-center">
                            <div class="flex items-center justify-center gap-1.5 flex-wrap">

                                <button wire:click="viewJob({{ $job->id }})"
                                        title="View full details"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                @if($job->status === 'ACTIVE')
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            title="Deactivate"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                @else
                                    <button wire:click="confirmToggle({{ $job->id }})"
                                            title="Activate"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                        <i class="fas fa-circle-check"></i> Activate
                                    </button>
                                @endif

                                <button wire:click="confirmDelete({{ $job->id }})"
                                        title="Delete this posting"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                    <i class="fas fa-trash"></i> Delete
                                </button>

                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-20 text-center">
                            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-briefcase text-3xl text-slate-300"></i>
                            </div>
                            <p class="font-bold text-slate-400 text-base">No job postings found</p>
                            <p class="text-sm text-slate-400 mt-1">
                                @if($search || $filterStatus || $filterType)
                                    Try adjusting your filters or
                                    <button wire:click="resetFilters" class="text-purple-600 underline font-semibold">reset them</button>.
                                @else
                                    No postings have been submitted yet.
                                @endif
                            </p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/80 shrink-0">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex items-center justify-between">
                <p class="text-slate-500 text-sm">
                    Showing
                    <span class="font-bold text-slate-800">{{ $from }}&ndash;{{ $to }}</span>
                    of
                    <span class="font-bold text-slate-800">{{ $total }}</span>
                    job{{ $total !== 1 ? 's' : '' }}
                </p>
                <div class="flex gap-2 items-center">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-400 rounded-lg text-sm font-semibold cursor-not-allowed">
                            &larr; Previous
                        </button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-bold">
                            &larr; Previous
                        </button>
                    @endif
                    <span class="px-4 py-2 text-slate-600 text-sm font-semibold">
                        Page {{ $this->jobPostings->currentPage() }} of {{ $this->jobPostings->lastPage() }}
                    </span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-bold">
                            Next &rarr;
                        </button>
                    @else
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-400 rounded-lg text-sm font-semibold cursor-not-allowed">
                            Next &rarr;
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════════════════
     MODAL: Configure Job Options
     Tabs: Company Type, Employment Type, Experience Level, Location
     Must click X or Cancel — no backdrop click to dismiss
════════════════════════════════════════════════════════════ --}}
@if($showManageModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate flex"
         style="width:800px;height:620px;max-width:95vw;max-height:92vh;">

        {{-- LEFT: Tabs --}}
        <div class="w-56 shrink-0 bg-slate-50 rounded-l-2xl border-r border-slate-100 flex flex-col py-5 px-3 gap-1">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest px-3 mb-2">Categories</p>
            @foreach([
                'company_type'     => ['icon' => 'building',      'label' => 'Company Type'],
                'employment_type'  => ['icon' => 'clock',         'label' => 'Employment Type'],
                'experience_level' => ['icon' => 'star',          'label' => 'Experience Level'],
            ] as $key => $meta)
            <button wire:click="selectTab('{{ $key }}')"
                    class="w-full text-left px-4 py-3 rounded-xl text-sm font-bold border transition-all flex items-center gap-2
                           {{ $activeOptionTab === $key ? 'manage-tab-active' : 'manage-tab-inactive' }}">
                <i class="fas fa-{{ $meta['icon'] }} w-4 text-center"></i>
                {{ $meta['label'] }}
            </button>
            @endforeach
        </div>

        {{-- RIGHT: Content --}}
        <div class="flex flex-col flex-1 min-w-0 rounded-r-2xl overflow-hidden">

            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
                <h3 class="text-base font-extrabold text-slate-800 flex items-center gap-2">
                    <i class="fas fa-sliders text-purple-600"></i>
                    @php echo match($activeOptionTab) {
                        'company_type'     => 'Company Type Options',
                        'employment_type'  => 'Employment Type Options',
                        'experience_level' => 'Experience Level Options',
                        default            => 'Options',
                    }; @endphp
                </h3>
                <button wire:click="closeManageModal"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                    <i class="fas fa-xmark text-base"></i>
                </button>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-5 py-4 space-y-2">
                @forelse($this->jobOptions->get($activeOptionTab, collect()) as $opt)
                <div>
                    <div class="option-row group">
                        <i class="fas fa-tag text-purple-500 shrink-0 text-sm"></i>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-semibold text-slate-700">{{ $opt->label }}</span>
                            @if($activeOptionTab === 'company_type' && !empty($opt->default_location))
                                <span class="ml-2 inline-flex items-center gap-1 text-xs text-slate-400 font-medium">
                                    <i class="fas fa-location-dot text-purple-300 text-[10px]"></i>{{ $opt->default_location }}
                                </span>
                            @endif
                        </div>
                        <div class="flex gap-1.5 shrink-0">
                            @if($activeOptionTab === 'company_type')
                            <button wire:click="openAssignLocation({{ $opt->id }})"
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition border
                                           {{ $assignLocationForId === $opt->id
                                               ? 'text-emerald-700 bg-emerald-100 border-emerald-200'
                                               : 'text-slate-600 bg-slate-100 hover:bg-slate-200 border-slate-200' }}">
                                <i class="fas fa-location-dot mr-1"></i>Location
                            </button>
                            @endif
                            <button wire:click="startEdit({{ $opt->id }})"
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-purple-700 bg-purple-50 hover:bg-purple-100 transition border border-purple-100">
                                <i class="fas fa-pen mr-1"></i>Edit
                            </button>
                            <button wire:click="deleteOption({{ $opt->id }})"
                                    wire:confirm="Delete '{{ $opt->label }}'?"
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-red-600 bg-red-50 hover:bg-red-100 transition border border-red-100">
                                <i class="fas fa-trash mr-1"></i>Delete
                            </button>
                        </div>
                    </div>

                    {{-- Inline location assign form --}}
                    @if($assignLocationForId === $opt->id)
                    <div class="mt-1.5 ml-6 p-4 bg-white border border-emerald-200 rounded-xl shadow-sm">
                        <p class="text-xs font-bold text-slate-600 mb-2 flex items-center gap-1.5">
                            <i class="fas fa-location-dot text-emerald-500"></i>
                            Set default location for <span class="text-emerald-700">{{ $opt->label }}</span>
                        </p>
                        <p class="text-[11px] text-slate-400 mb-2.5">When an organizer selects this company type, this location will be auto-filled for them.</p>
                        <div class="flex gap-2 items-start">
                            <div class="flex-1">
                                <input type="text"
                                       wire:model="assignLocationValue"
                                       wire:keydown.enter="saveAssignLocation"
                                       placeholder="e.g. Tuguegarao, Cagayan / Remote"
                                       maxlength="120"
                                       class="w-full px-3 py-2 border rounded-lg text-sm bg-white transition
                                              {{ isset($assignLocationErrors['location']) ? 'border-red-300 bg-red-50' : 'border-slate-200' }}
                                              focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 focus:outline-none">
                                <p class="field-hint mt-1.5"><i class="fas fa-circle-info text-[10px] mr-1"></i>Letters, numbers, commas, dashes, slashes only. Max 120 chars. Leave blank to remove.</p>
                                @if(isset($assignLocationErrors['location']))
                                    <p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $assignLocationErrors['location'] }}</p>
                                @endif
                            </div>
                            <button wire:click="saveAssignLocation"
                                    wire:loading.attr="disabled"
                                    wire:target="saveAssignLocation"
                                    class="px-3 py-2 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 transition flex items-center gap-1.5 whitespace-nowrap disabled:opacity-60">
                                <span wire:loading wire:target="saveAssignLocation"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                                <span wire:loading.remove wire:target="saveAssignLocation"><i class="fas fa-floppy-disk"></i> Save</span>
                            </button>
                            <button wire:click="cancelAssignLocation"
                                    class="px-3 py-2 rounded-lg text-xs font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition border border-slate-200">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                    @endif
                </div>
                @empty
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <i class="fas fa-inbox text-4xl text-slate-200 mb-3"></i>
                    <p class="text-sm text-slate-400 font-semibold">No options yet</p>
                    <p class="text-xs text-slate-300 mt-1">Use the form below to add one</p>
                </div>
                @endforelse
            </div>

            {{-- Add / Edit form --}}
            <div class="border-t border-slate-100 px-5 py-4 bg-slate-50 rounded-br-2xl shrink-0">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3">
                    {{ $optionModalMode === 'edit' ? 'Editing Option' : 'Add New Option' }}
                </p>
                <div class="flex gap-2 items-start">
                    <div class="flex-1">
                        <input type="text"
                               wire:model="optionLabel"
                               wire:keydown.enter="saveOption"
                               placeholder="e.g. Full-time"
                               maxlength="255"
                               class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus
                                      {{ isset($optionErrors['optionLabel']) ? 'field-error' : '' }}">
                        @if(isset($optionErrors['optionLabel']))
                            <p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $optionErrors['optionLabel'] }}</p>
                        @endif
                    </div>
                    <button wire:click="saveOption"
                            class="px-4 py-2.5 btn-primary rounded-lg text-sm font-bold whitespace-nowrap flex items-center gap-1.5">
                        <i class="fas fa-{{ $optionModalMode === 'edit' ? 'floppy-disk' : 'plus' }}"></i>
                        {{ $optionModalMode === 'edit' ? 'Save' : 'Add' }}
                    </button>
                    @if($optionModalMode === 'edit')
                    <button wire:click="startAdd"
                            title="Cancel edit"
                            class="px-3 py-2.5 text-slate-500 hover:bg-slate-200 rounded-lg border border-slate-200 transition text-sm font-bold">
                        <i class="fas fa-xmark"></i>
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: View Full Job Details
════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php $job = $this->viewingJob; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate flex flex-col"
         style="width:660px;max-width:95vw;max-height:90vh;">

        <div class="flex items-start justify-between px-6 py-5 border-b border-slate-100 shrink-0">
            <div>
                <h3 class="text-xl font-extrabold text-slate-800 leading-snug">{{ $job->job_title }}</h3>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ $job->company_name }}
                    <span class="mx-1.5 text-slate-300">·</span>
                    {{ $job->company_type }}
                </p>
            </div>
            <button wire:click="closeViewModal"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition ml-4 shrink-0">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-6 py-5 space-y-5">

            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                    <i class="fas fa-clock text-[10px]"></i> {{ $job->employment_type }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-purple-50 text-purple-700 border border-purple-100">
                    <i class="fas fa-star text-[10px]"></i> {{ $job->experience_level }}
                </span>
                @php
                    $sc = match($job->status) {
                        'ACTIVE'   => 'bg-emerald-100 text-emerald-700',
                        'INACTIVE' => 'bg-amber-100 text-amber-700',
                        default    => 'bg-slate-100 text-slate-600',
                    };
                @endphp
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold {{ $sc }}">
                    <i class="fas fa-circle text-[8px]"></i> {{ $job->status }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    ['icon' => 'location-dot',   'label' => 'Location',  'value' => $job->location ?? '—'],
                    ['icon' => 'money-bill-wave', 'label' => 'Salary',    'value' => $job->salary ?? 'Not disclosed'],
                    ['icon' => 'calendar-xmark',  'label' => 'Deadline',  'value' => \Carbon\Carbon::parse($job->deadline)->format('F d, Y')],
                    ['icon' => 'calendar-plus',   'label' => 'Posted On', 'value' => $job->created_at->format('M d, Y')],
                ] as $info)
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                    <p class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-1.5 flex items-center gap-1.5">
                        <i class="fas fa-{{ $info['icon'] }} text-purple-500"></i> {{ $info['label'] }}
                    </p>
                    <p class="text-sm font-bold text-slate-800">{{ $info['value'] }}</p>
                </div>
                @endforeach

                {{-- Posted By — name + college combined --}}
                @php
                    $orgDept2 = $job->organizer?->department ?? '';
                    $hasCollege2 = $orgDept2 !== '' && \App\Models\Course::where('college', $orgDept2)->exists();
                    $postedByCollege = $orgDept2 !== ''
                        ? ($hasCollege2 ? $orgDept2 : (\App\Models\Course::where('code', $orgDept2)->value('college') ?? $orgDept2))
                        : null;
                @endphp
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                    <p class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-1.5 flex items-center gap-1.5">
                        <i class="fas fa-user-tie text-purple-500"></i> Posted By
                    </p>
                    <p class="text-sm font-bold text-slate-800">{{ $job->organizer?->name ?? '—' }}</p>
                    @if($postedByCollege)
                        <p class="text-xs font-semibold text-slate-500 mt-0.5">{{ $postedByCollege }}</p>
                    @endif
                </div>

                {{-- Last Updated --}}
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                    <p class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-1.5 flex items-center gap-1.5">
                        <i class="fas fa-clock-rotate-left text-purple-500"></i> Last Updated
                    </p>
                    <p class="text-sm font-bold text-slate-800">{{ $job->updated_at->diffForHumans() }}</p>
                    <p class="text-xs font-semibold text-slate-500 mt-0.5">{{ $job->updated_at->format('M d, Y · g:i A') }}</p>
                </div>

            </div>

            <div>
                <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-widest mb-2">Job Description</p>
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-100 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">{{ $job->description }}</div>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 shrink-0 flex justify-end gap-2 rounded-b-2xl">
            <button wire:click="closeViewModal"
                    class="px-5 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-bold">
                <i class="fas fa-xmark mr-1"></i> Close
            </button>
            <button wire:click="openEditModal({{ $job->id }})"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 rounded-lg transition border border-blue-200">
                <i class="fas fa-pen-to-square"></i> Update / Edit
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Edit Job Posting
════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-2xl shadow-2xl modal-animate w-full flex flex-col"
         style="max-width:800px;max-height:92vh;">

        <div class="flex items-center justify-between px-8 py-5 btn-primary text-white rounded-t-2xl shrink-0">
            <h2 class="text-lg font-extrabold flex items-center gap-3">
                <i class="fas fa-pen-to-square"></i> Edit Job Posting
            </h2>
            <button wire:click="closeEditModal"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-white/20 transition text-xl leading-none">
                &times;
            </button>
        </div>

        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-8 py-4 shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation"></i> Please fix the following:
            </p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($editErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5 shrink-0">&bull;</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">

            {{-- Job Title + Company --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer"
                           class="form-input {{ isset($editErrors['editJobTitle']) ? 'field-error' : '' }}">
                    @if(isset($editErrors['editJobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Company Name <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editCompany" type="text" placeholder="e.g. Acme Corp"
                           class="form-input {{ isset($editErrors['editCompany']) ? 'field-error' : '' }}">
                    @if(isset($editErrors['editCompany']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>

            {{-- Company Type + Location --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Company Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="editCompanyType"
                            class="form-input {{ isset($editErrors['editCompanyType']) ? 'field-error' : '' }}">
                        <option value="">— Select Company Type —</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">
                        Location <span class="text-red-500">*</span>
                        <span class="text-slate-400 font-normal text-[11px] ml-1">(City, Province or Remote)</span>
                    </label>
                    <input wire:model="editLocation" type="text"
                           placeholder="e.g. Tuguegarao, Cagayan / Remote / Hybrid - Manila"
                           maxlength="120"
                           class="form-input {{ isset($editErrors['editLocation']) ? 'field-error' : '' }}">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Auto-filled when a company type with a default location is selected. You can still edit it manually.</p>
                    @if(isset($editErrors['editLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
                </div>
            </div>

            {{-- Employment Type + Experience Level --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editEmpType"
                            class="form-input {{ isset($editErrors['editEmpType']) ? 'field-error' : '' }}">
                        <option value="">— Select Type —</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editExpLevel"
                            class="form-input {{ isset($editErrors['editExpLevel']) ? 'field-error' : '' }}">
                        <option value="">— Select Level —</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editExpLevel === $opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editExpLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                </div>
            </div>

            {{-- Salary + Deadline --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <div class="relative">
                        <i class="fas fa-money-bill-wave absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                        <input wire:model.defer="editSalary" type="text"
                               placeholder="e.g. P25,000 - P35,000 / month"
                               class="form-input pl-8">
                    </div>
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <i class="fas fa-calendar-days absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                        <input wire:model.defer="editDeadline" type="date"
                               class="form-input pl-8 {{ isset($editErrors['editDeadline']) ? 'field-error' : '' }}">
                    </div>
                    @if(isset($editErrors['editDeadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                </div>
            </div>

            {{-- Description --}}
            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="editDescription" rows="7"
                          placeholder="Describe the role, responsibilities, and qualifications..."
                          class="form-input resize-none {{ isset($editErrors['editDescription']) ? 'field-error' : '' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>

        </div>

        <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 rounded-b-2xl shrink-0 flex gap-3">
            <button wire:click="closeEditModal"
                    class="flex-1 px-6 py-3 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-100 transition">
                <i class="fas fa-xmark mr-1.5"></i> Cancel
            </button>
            <button wire:click="saveEditJob"
                    wire:loading.attr="disabled" wire:target="saveEditJob"
                    class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 shadow-md">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="saveEditJob">
                    <i class="fas fa-floppy-disk mr-1"></i> Save Changes
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Delete
════════════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:420px;max-width:95vw;">

        <div class="px-7 py-6 bg-red-50 border-b border-red-200 rounded-t-2xl flex items-center gap-3">
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
                        class="flex-1 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-extrabold transition flex items-center justify-center gap-2 shadow-md">
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
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:400px;max-width:95vw;">

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
            <p class="text-sm text-slate-500 leading-relaxed">
                @if($confirmAction === 'ACTIVE')
                    This job will be marked as <span class="font-bold text-emerald-600">ACTIVE</span> and visible to students.
                @else
                    This job will be marked as <span class="font-bold text-amber-600">INACTIVE</span> and hidden from students.
                @endif
            </p>
        </div>

        <div class="px-6 pb-6 flex gap-3">
            <button wire:click="cancelConfirm"
                    class="flex-1 px-4 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl transition text-sm font-bold hover:bg-slate-50">
                <i class="fas fa-xmark mr-1"></i> Cancel
            </button>
            <button wire:click="executeToggle"
                    wire:loading.attr="disabled" wire:target="executeToggle"
                    class="flex-1 px-4 py-2.5 btn-primary rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 shadow-md">
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