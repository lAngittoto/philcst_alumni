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

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public string $filterSort   = 'recent';

    public bool   $showFormModal = false;
    public bool   $isEditing     = false;
    public ?int   $editingJobId  = null;

    public string $jobTitle        = '';
    public string $orgCategory     = '';
    public string $partnerOrgName  = '';
    public string $partnerOrgType  = '';
    public string $customOrgName   = '';
    public string $customOrgType   = '';
    public string $location        = '';
    public string $employmentType  = '';
    public string $experienceLevel = '';
    public string $salary          = '';
    public string $deadline        = '';
    public string $description     = '';
    public string $targetCollege   = '';

    public string $philcstName     = '';
    public string $philcstLocation = '';

    public bool   $showViewModal    = false;
    public ?int   $viewingJobId     = null;
    public bool   $showDeleteModal  = false;
    public ?int   $deleteJobId      = null;
    public string $deleteJobTitle   = '';
    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';
    public array  $formErrors       = [];

    public function mount(): void
    {
        $philcst = JobOption::where('type', 'company_type')
            ->where('label', 'like', '%PHILCST%')
            ->orderBy('label')
            ->first();
        if ($philcst) {
            $this->philcstName     = $philcst->label;
            $this->philcstLocation = $philcst->default_location ?? '';
        }
    }

    public function updatingSearch()       { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingFilterType()   { $this->resetPage(); }
    public function updatingFilterSort()   { $this->resetPage(); }

    public function updatedOrgCategory(string $value): void
    {
        $this->partnerOrgName = $this->partnerOrgType = '';
        $this->customOrgName  = $this->customOrgType  = '';
        $this->location       = '';
        if ($value === 'philcst') {
            $this->location = $this->philcstLocation;
        }
    }

    #[Computed]
    public function jobPostings()
    {
        $org = auth()->user()?->organizer;
        if (!$org) return OrganizerJob::whereRaw('0=1')->paginate(20);

        $q = OrganizerJob::forOrganizer($org->id)
            ->whereIn('status', ['ACTIVE', 'INACTIVE']);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }
        if ($this->filterStatus !== '') $q->where('status', $this->filterStatus);
        if ($this->filterType   !== '') $q->where('employment_type', $this->filterType);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
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

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

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
        $this->location        = $job->location ?? '';
        $this->employmentType  = $job->employment_type;
        $this->experienceLevel = $job->experience_level;
        $this->salary          = $job->salary ?? '';
        $this->deadline        = $job->deadline->format('Y-m-d');
        $this->description     = $job->description;
        $this->targetCollege   = $job->target_college ?? '';
        $this->formErrors      = [];

        $ct = $job->company_type;
        if (str_contains(strtoupper($ct), 'PHILCST')) {
            $this->orgCategory = 'philcst';
        } elseif (JobOption::where('type', 'company_type')->where('label', $ct)->exists()) {
            $this->orgCategory    = 'partner';
            $this->partnerOrgName = $job->company_name;
            $this->partnerOrgType = $ct;
        } else {
            $this->orgCategory   = 'custom';
            $this->customOrgName = $job->company_name;
            $this->customOrgType = $ct;
        }

        $this->showFormModal = true;
        $this->showViewModal = false;
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

        if (!trim($this->jobTitle))    $errors['jobTitle']    = 'Job title is required.';
        if (!trim($this->orgCategory)) $errors['orgCategory'] = 'Please select an organization category.';

        if ($this->orgCategory === 'partner') {
            if (!trim($this->partnerOrgName)) $errors['partnerOrgName'] = 'Organization name is required.';
            if (!trim($this->partnerOrgType)) $errors['partnerOrgType'] = 'Organization type is required.';
            if (!trim($this->location))       $errors['location']       = 'Location is required.';
        }
        if ($this->orgCategory === 'custom') {
            if (!trim($this->customOrgName)) $errors['customOrgName'] = 'Organization name is required.';
            if (!trim($this->customOrgType)) $errors['customOrgType'] = 'Organization type is required.';
            if (!trim($this->location))      $errors['location']      = 'Location is required.';
        }

        if (!trim($this->employmentType)) $errors['employmentType'] = 'Employment type is required.';
        if (!trim($this->experienceLevel))$errors['experienceLevel']= 'Experience level is required.';
        if (!trim($this->deadline)) {
            $errors['deadline'] = 'Deadline is required.';
        } elseif (!$this->isEditing && strtotime($this->deadline) < strtotime('today')) {
            $errors['deadline'] = 'Deadline must be a future date.';
        }
        if (!trim($this->description)) $errors['description'] = 'Job description is required.';

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        [$companyName, $companyType] = match($this->orgCategory) {
            'philcst' => [$this->philcstName, $this->philcstName],
            'partner' => [trim($this->partnerOrgName), trim($this->partnerOrgType)],
            'custom'  => [trim($this->customOrgName),  trim($this->customOrgType)],
            default   => ['', ''],
        };

        $resolvedLocation = $this->orgCategory === 'philcst'
            ? $this->philcstLocation
            : trim($this->location);

        $data = [
            'job_title'        => trim($this->jobTitle),
            'company_name'     => $companyName,
            'company_type'     => $companyType,
            'location'         => $resolvedLocation,
            'employment_type'  => trim($this->employmentType),
            'experience_level' => trim($this->experienceLevel),
            'salary'           => trim($this->salary) ?: null,
            'deadline'         => $this->deadline,
            'description'      => trim($this->description),
            'target_college'   => trim($this->targetCollege) ?: null,
        ];

        $ctrl = app(OrganizerJobController::class);
        if ($this->isEditing) {
            $data['updated_by']      = auth()->user()->name;
            $data['updated_by_role'] = 'organizer';
            $ctrl->updateJob($this->editingJobId, $data);
            $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully!');
        } else {
            $ctrl->createJob($data);
            $this->dispatch('flash-message', type: 'success', message: 'Job posting created!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function viewJob(int $id): void  { $this->viewingJobId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void  { $this->showViewModal = false; $this->viewingJobId = null; }

    public function confirmDelete(int $id): void
    {
        $job = app(OrganizerJobController::class)->getJob($id);
        $this->deleteJobId    = $id;
        $this->deleteJobTitle = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteJobId) {
            $job = app(OrganizerJobController::class)->getJob($this->deleteJobId);
            $job->update([
                'status'          => 'ORGANIZER_DELETED',
                'deleted_by'      => auth()->user()->name,
                'deleted_by_role' => 'organizer',
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' deleted.");
        }
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingJobId = null; }
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
    }

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
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingJobId = null; }
    }

    public function cancelConfirm(): void { $this->showConfirmModal = false; $this->confirmJobId = null; }

    private function resetFormFields(): void
    {
        $this->jobTitle = $this->orgCategory = '';
        $this->partnerOrgName = $this->partnerOrgType = $this->customOrgName = $this->customOrgType = '';
        $this->location = $this->employmentType = $this->experienceLevel = $this->salary = '';
        $this->deadline = $this->description = $this->targetCollege = '';
        $this->formErrors = []; $this->editingJobId = null; $this->isEditing = false;
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
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    .modal-animate{animation:modalSlideIn .3s cubic-bezier(.16,1,.3,1)}
    .backdrop-animate{animation:fadeIn .18s ease}
    .spin-icon{animation:spin 1s linear infinite}
    .btn-primary{background:linear-gradient(135deg,#7a3f91,#6a3580);color:white;border:none;transition:background .2s,box-shadow .2s}
    .btn-primary:hover:not(:disabled){background:linear-gradient(135deg,#8b4aa5,#7a3f91);box-shadow:0 4px 14px rgba(122,63,145,.35)}
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
    .field-error{border-color:#ef4444!important;background:#fff8f8!important}
    .field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}

    /* ── Org category buttons ── */
    .org-cat-btn{flex:1;padding:14px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;transition:all .18s;text-align:center;font-size:.8rem;font-weight:700;color:#64748b;display:flex;flex-direction:column;align-items:center;gap:7px}
    .org-cat-btn:hover{border-color:#7a3f91;color:#7a3f91;background:#faf5ff}
    .org-cat-btn.active{border-color:#7a3f91;background:linear-gradient(135deg,#7a3f91,#6a3580);color:#fff;box-shadow:0 3px 12px rgba(122,63,145,.35)}
    .org-cat-btn .cat-fa{font-size:1.15rem}
    .org-confirm-box{border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px}
    .org-confirm-box.philcst-box{background:#faf5ff;border:1.5px solid #c4b5fd}
    .org-confirm-box.partner-box{background:#eff6ff;border:1.5px solid #bfdbfe}
    .org-confirm-box.custom-box{background:#f8fafc;border:1.5px solid #e2e8f0}
    .org-confirm-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;flex-shrink:0}
    .org-confirm-name{font-size:.875rem;font-weight:700}
    .org-confirm-sub{font-size:.75rem;margin-top:2px}

    /* ── Table action buttons — OUTLINED ── */
    .tbl-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit;white-space:nowrap}
    .tbl-btn-view{color:#7a3f91;border-color:#7a3f91}
    .tbl-btn-view:hover{background:#faf5ff}
    .tbl-btn-activate{color:#15803d;border-color:#15803d}
    .tbl-btn-activate:hover{background:#f0fdf4}
    .tbl-btn-deactivate{color:#b45309;border-color:#b45309}
    .tbl-btn-deactivate:hover{background:#fffbeb}
    .tbl-btn-delete{color:#dc2626;border-color:#dc2626}
    .tbl-btn-delete:hover{background:#fff5f5}

    /* ── JobStreet view modal ── */
    .js-modal{background:#fff;border-radius:12px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:760px;max-width:96vw;max-height:92vh;font-family:'Noto Sans','Segoe UI',system-ui,-apple-system,sans-serif;overflow:hidden}
    .js-header{background:#fff;padding:26px 32px 20px;border-bottom:1px solid #ebebeb;flex-shrink:0;position:relative}
    .js-job-title{font-size:23px;font-weight:700;color:#111;line-height:1.25;margin-bottom:6px;padding-right:38px}
    .js-company-line{display:flex;align-items:center;gap:6px;font-size:14px;color:#444;margin-bottom:18px;flex-wrap:wrap}
    .js-company-line strong{color:#111;font-weight:600}
    .js-company-type-pill{background:#f0ebff;color:#6d28d9;font-size:11.5px;font-weight:600;border-radius:3px;padding:2px 8px}
    .js-status-active{background:#dcfce7;color:#15803d;font-size:11.5px;font-weight:700;border-radius:3px;padding:2px 8px}
    .js-status-inactive{background:#fef9c3;color:#a16207;font-size:11.5px;font-weight:700;border-radius:3px;padding:2px 8px}
    .js-meta-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:9px}
    .js-meta-item{display:flex;align-items:flex-start;gap:11px;font-size:13.5px;color:#222;line-height:1.4}
    .js-meta-icon{width:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;color:#7a3f91;font-size:13px}
    .js-meta-label{color:#222}
    .js-meta-muted{color:#999;font-style:italic}
    .js-posted-line{margin-top:16px;font-size:12.5px;color:#777}
    .js-body{flex:1;min-height:0;overflow-y:auto;background:#fff}
    .js-section{padding:24px 32px;border-bottom:1px solid #f0f0f0}
    .js-section:last-child{border-bottom:none}
    .js-section-title{font-size:16px;font-weight:700;color:#111;margin-bottom:14px}
    .js-description{font-size:14px;color:#222;line-height:1.85;white-space:pre-wrap}
    .js-admin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden}
    .js-admin-cell{padding:13px 16px;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8}
    .js-admin-cell:nth-child(3n){border-right:none}
    .js-admin-cell-full{grid-column:span 3;padding:13px 16px;border-bottom:none}
    .js-admin-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px}
    .js-admin-value{font-size:13px;font-weight:600;color:#111}
    .js-admin-sub{font-size:11px;color:#888;margin-top:1px}
    .js-update-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:3px;margin-top:6px;background:#f5f5f5;color:#555;border:1px solid #e5e5e5}
    .js-update-badge.admin-badge{background:#f5f0ff;color:#6d28d9;border-color:#e5d9ff}
    .js-college-box{background:#faf5ff;border:1px solid #e0d7f5;border-radius:6px;padding:14px 18px}
    .js-college-name{font-size:14px;font-weight:700;color:#111;margin-bottom:8px}
    .js-dept-chips{display:flex;flex-wrap:wrap;gap:6px}
    .js-dept-chip{font-size:11px;font-weight:700;font-family:'Courier New',monospace;background:#fff;border:1px solid #d4c5f0;border-radius:3px;padding:3px 8px;color:#6d28d9}

    /* ── View modal footer buttons — OUTLINED ── */
    .js-footer{padding:14px 32px;border-top:1px solid #ebebeb;display:flex;align-items:center;justify-content:flex-end;background:#fff;flex-shrink:0;gap:8px}
    .js-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit}
    .js-btn-close{color:#374151;border-color:#cbd5e1}
    .js-btn-close:hover{background:#f8fafc}
    .js-btn-edit{color:#2557a7;border-color:#2557a7}
    .js-btn-edit:hover{background:#eff6ff}
    .js-btn-deactivate{color:#b45309;border-color:#b45309}
    .js-btn-deactivate:hover{background:#fffbeb}
    .js-btn-activate{color:#15803d;border-color:#15803d}
    .js-btn-activate:hover{background:#f0fdf4}
    .js-close-x{position:absolute;top:18px;right:20px;width:30px;height:30px;border-radius:50%;border:none;background:transparent;color:#999;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s;line-height:1}
    .js-close-x:hover{background:#f0f0f0;color:#333}

    /* ── Confirm modal buttons — OUTLINED ── */
    .confirm-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit;border:1.5px solid;background:#fff;flex:1}
    .confirm-btn-cancel{color:#374151;border-color:#cbd5e1}
    .confirm-btn-cancel:hover{background:#f8fafc}
    .confirm-btn-activate{color:#15803d;border-color:#15803d}
    .confirm-btn-activate:hover{background:#f0fdf4}
    .confirm-btn-deactivate{color:#b45309;border-color:#b45309}
    .confirm-btn-deactivate:hover{background:#fffbeb}
    .confirm-btn-delete{color:#dc2626;border-color:#dc2626}
    .confirm-btn-delete:hover{background:#fff5f5}
</style>

{{-- ── FLASH ── --}}
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
    <i class="fas mt-0.5 text-lg flex-shrink-0" :class="type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':'Error'"></div>
        <div class="text-sm mt-0.5 opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- PAGE HEADER --}}
    <div class="flex items-center justify-between mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
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

    {{-- TABLE PANEL --}}
    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden border border-slate-200">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                <input type="text" wire:model.live.debounce.200ms="search"
                       placeholder="Search title, company..."
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
                       autocomplete="off">
            </div>
            <select wire:model.live="filterStatus" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
            <select wire:model.live="filterType" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}" @selected($filterType === $opt->label)>{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                <i class="fas fa-rotate-left mr-2"></i>Reset
            </button>
            <span wire:loading wire:target="search,filterStatus,filterType,filterSort,resetFilters">
                <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;pointer-events:none;">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Job Title</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Organization</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Employment Type</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Deadline</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->jobPostings as $job)
                    <tr class="table-row-hover">
                        <td class="px-6 py-4"><p class="font-semibold text-slate-900 text-sm">{{ $job->job_title }}</p></td>
                        <td class="px-6 py-4"><p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p></td>
                        <td class="px-6 py-4">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">{{ $job->employment_type }}</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-sm font-semibold text-slate-700">{{ \Carbon\Carbon::parse($job->deadline)->format('M d, Y') }}</span>
                            @if(now()->gt($job->deadline))
                                <p class="text-xs text-red-400 mt-0.5">Expired</p>
                            @else
                                <p class="text-xs text-slate-400 mt-0.5">{{ \Carbon\Carbon::parse($job->deadline)->diffForHumans() }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $job->status_badge_class }}">{{ $job->status }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button wire:click="viewJob({{ $job->id }})" class="tbl-btn tbl-btn-view">
                                    <i class="fas fa-eye text-[11px]"></i> View
                                </button>
                                @if($job->status === 'ACTIVE')
                                    <button wire:click="confirmToggle({{ $job->id }})" class="tbl-btn tbl-btn-deactivate">
                                        <i class="fas fa-ban text-[11px]"></i> Deactivate
                                    </button>
                                @else
                                    <button wire:click="confirmToggle({{ $job->id }})" class="tbl-btn tbl-btn-activate">
                                        <i class="fas fa-circle-check text-[11px]"></i> Activate
                                    </button>
                                @endif
                                <button wire:click="confirmDelete({{ $job->id }})" class="tbl-btn tbl-btn-delete">
                                    <i class="fas fa-trash text-[11px]"></i> Delete
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
                @php $total=$this->jobPostings->total();$pp=$this->jobPostings->perPage();$cp=$this->jobPostings->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total); @endphp
                <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                <div class="flex gap-2 items-center">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->jobPostings->currentPage() }} / {{ $this->jobPostings->lastPage() }}</span>
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

{{-- ════════════════════════════════════════════════
     MODAL: Create / Edit Job
════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-xl shadow-2xl modal-animate w-full flex flex-col" style="max-width:800px;max-height:92vh;">

        <div class="flex items-center justify-between px-7 py-5 bg-[#7a3f91] border-b border-slate-100 shrink-0">
            <div class="flex items-center gap-3">
                <div class=" rounded-lg flex items-center justify-center shrink-0">
                    <i class="fas fa-{{ $isEditing ? 'pen' : 'plus' }} text-2xl text-white"></i>
                </div>
                <h2 class="text-lg font-bold text-white">{{ $isEditing ? 'Edit Job Posting' : 'Post a New Job' }}</h2>
            </div>
            <button wire:click="closeFormModal" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition text-xl leading-none">&times;</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-7 py-6 space-y-5">

            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="jobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-input {{ isset($formErrors['jobTitle']) ? 'field-error' : '' }}">
                @if(isset($formErrors['jobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['jobTitle'] }}</p>@endif
            </div>

            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-building text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Organization Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="form-label">Select Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <button type="button" wire:click="$set('orgCategory','philcst')" class="org-cat-btn {{ $orgCategory === 'philcst' ? 'active' : '' }}">
                                <i class="fas fa-school cat-fa"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('orgCategory','partner')" class="org-cat-btn {{ $orgCategory === 'partner' ? 'active' : '' }}">
                                <i class="fas fa-handshake cat-fa"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('orgCategory','custom')" class="org-cat-btn {{ $orgCategory === 'custom' ? 'active' : '' }}">
                                <i class="fas fa-pen-to-square cat-fa"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($formErrors['orgCategory']))<p class="form-error mt-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['orgCategory'] }}</p>@endif
                    </div>

                    @if($orgCategory === 'philcst')
                        @if($philcstName)
                        <div class="org-confirm-box philcst-box">
                            <div class="org-confirm-icon" style="background:linear-gradient(135deg,#7a3f91,#6a3580);"><i class="fas fa-school"></i></div>
                            <div class="flex-1">
                                <div class="org-confirm-name" style="color:#4c1d95;">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="org-confirm-sub" style="color:#7c3aed;"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-purple-600 bg-white border border-purple-200 px-3 py-1.5 rounded-full shrink-0">
                                <i class="fas fa-lock text-[10px]"></i> Auto-filled
                            </span>
                        </div>
                        @else
                        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-700"><i class="fas fa-triangle-exclamation mr-2"></i>No PHILCST campus found.</div>
                        @endif
                    @elseif($orgCategory === 'partner')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="partnerOrgName" type="text" placeholder="e.g. Acme Corporation" class="form-input {{ isset($formErrors['partnerOrgName']) ? 'field-error' : '' }}">
                                @if(isset($formErrors['partnerOrgName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['partnerOrgName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="partnerOrgType" type="text" placeholder="e.g. Private Company, NGO" class="form-input {{ isset($formErrors['partnerOrgType']) ? 'field-error' : '' }}">
                                @if(isset($formErrors['partnerOrgType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['partnerOrgType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="location" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote" maxlength="120" class="form-input {{ isset($formErrors['location']) ? 'field-error' : '' }}">
                            @if(isset($formErrors['location']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['location'] }}</p>@endif
                        </div>
                        @if(trim($partnerOrgName))
                        <div class="org-confirm-box partner-box">
                            <div class="org-confirm-icon" style="background:#2557a7;"><i class="fas fa-handshake"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e3a5f;">{{ $partnerOrgName }}</div>
                                @if(trim($partnerOrgType))<div class="org-confirm-sub" style="color:#2557a7;">{{ $partnerOrgType }}</div>@endif
                                @if(trim($location))<div class="org-confirm-sub" style="color:#555;"><i class="fas fa-location-dot mr-1"></i>{{ $location }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @elseif($orgCategory === 'custom')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="customOrgName" type="text" placeholder="e.g. Department of Labor" class="form-input {{ isset($formErrors['customOrgName']) ? 'field-error' : '' }}">
                                @if(isset($formErrors['customOrgName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['customOrgName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="customOrgType" type="text" placeholder="e.g. Government Agency, NGO" class="form-input {{ isset($formErrors['customOrgType']) ? 'field-error' : '' }}">
                                @if(isset($formErrors['customOrgType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['customOrgType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="location" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120" class="form-input {{ isset($formErrors['location']) ? 'field-error' : '' }}">
                            @if(isset($formErrors['location']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['location'] }}</p>@endif
                        </div>
                        @if(trim($customOrgName))
                        <div class="org-confirm-box custom-box">
                            <div class="org-confirm-icon" style="background:#475569;"><i class="fas fa-pen-to-square"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e293b;">{{ $customOrgName }}</div>
                                @if(trim($customOrgType))<div class="org-confirm-sub" style="color:#475569;">{{ $customOrgType }}</div>@endif
                                @if(trim($location))<div class="org-confirm-sub" style="color:#555;"><i class="fas fa-location-dot mr-1"></i>{{ $location }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @else
                    <div class="text-center py-5 text-slate-400 text-sm">
                        <i class="fas fa-arrow-up text-slate-300 text-xl block mb-2"></i>Select a category above to continue.
                    </div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="employmentType" class="form-input {{ isset($formErrors['employmentType']) ? 'field-error' : '' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)<option value="{{ $opt->label }}">{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($formErrors['employmentType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['employmentType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="experienceLevel" class="form-input {{ isset($formErrors['experienceLevel']) ? 'field-error' : '' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)<option value="{{ $opt->label }}">{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($formErrors['experienceLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['experienceLevel'] }}</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="salary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-input">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="deadline" type="date" class="form-input {{ isset($formErrors['deadline']) ? 'field-error' : '' }}">
                    @if(isset($formErrors['deadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['deadline'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Target College <span class="text-slate-400 font-normal text-xs">(Optional)</span></label>
                <select wire:model.live="targetCollege" class="form-input">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $selectedCollegeDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->targetCollege)['codes'] ?? []; @endphp
                @if(count($selectedCollegeDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($selectedCollegeDepts as $dCode)<span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>@endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="description" rows="6" placeholder="Describe the role, responsibilities, qualifications..." class="form-input resize-none {{ isset($formErrors['description']) ? 'field-error' : '' }}"></textarea>
                @if(isset($formErrors['description']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['description'] }}</p>@endif
            </div>

        </div>

        <div class="px-7 py-5 border-t border-slate-200 bg-slate-50 rounded-b-xl shrink-0 flex gap-3">
            <button wire:click="closeFormModal" class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
            <button wire:click="saveJob" wire:loading.attr="disabled" wire:target="saveJob" class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveJob"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="saveJob"><i class="fas fa-{{ $isEditing ? 'floppy-disk' : 'paper-plane' }} mr-1.5"></i>{{ $isEditing ? 'Save Changes' : 'Post Job' }}</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════
     MODAL: View Full Job
════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job         = $this->viewingJob;
    $updaterName = $job->updated_by ?? null;
    $updaterRole = $job->updated_by_role ?? null;
    $wasEdited   = $updaterName !== null;
    $dl          = \Carbon\Carbon::parse($job->deadline);
    $daysLeft    = now()->diffInDays($dl, false);
    $isExpired   = $daysLeft < 0;
    $viewDepts   = $job->target_college
        ? \App\Models\Course::where('college', $job->target_college)->orderBy('code')->pluck('code')->toArray()
        : [];
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="js-modal modal-animate relative">
        <button wire:click="closeViewModal" class="js-close-x" title="Close">&times;</button>
        <div class="js-header">
            <div class="js-job-title">{{ $job->job_title }}</div>
            <div class="js-company-line">
                <strong>{{ $job->company_name }}</strong>
                <span class="js-company-type-pill">{{ $job->company_type }}</span>
                @if($job->status === 'ACTIVE')
                    <span class="js-status-active">● Active</span>
                @else
                    <span class="js-status-inactive">● Inactive</span>
                @endif
            </div>
            <ul class="js-meta-list">
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-location-dot"></i></span><span class="js-meta-label">{{ $job->location ?? 'Location not specified' }}</span></li>
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-clock"></i></span><span class="js-meta-label">{{ $job->employment_type }}</span></li>
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-layer-group"></i></span><span class="js-meta-label">{{ $job->experience_level }}</span></li>
                <li class="js-meta-item">
                    <span class="js-meta-icon"><i class="fas fa-money-bill-wave"></i></span>
                    @if($job->salary)<span class="js-meta-label">{{ $job->salary }}</span>@else<span class="js-meta-muted">Salary not disclosed</span>@endif
                </li>
                <li class="js-meta-item">
                    <span class="js-meta-icon"><i class="fas fa-calendar-xmark"></i></span>
                    <span class="js-meta-label">Deadline: {{ $dl->format('F d, Y') }}
                        @if($isExpired)<span style="color:#c0392b;font-weight:700;margin-left:6px;">(Expired)</span>
                        @else<span style="color:#666;margin-left:6px;">· {{ $dl->diffForHumans() }}</span>@endif
                    </span>
                </li>
                @if($job->target_college)
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-building-columns"></i></span><span class="js-meta-label">For: {{ $job->target_college }}</span></li>
                @endif
            </ul>
            <div class="js-posted-line">Posted {{ $job->created_at->diffForHumans() }}</div>
        </div>
        <div class="js-body scrollbar-custom">
            <div class="js-section">
                <div class="js-section-title">Job Description</div>
                <div class="js-description">{{ $job->description }}</div>
            </div>
            @if($job->target_college && count($viewDepts))
            <div class="js-section">
                <div class="js-section-title">Target College</div>
                <div class="js-college-box">
                    <div class="js-college-name">{{ $job->target_college }}</div>
                    <div class="js-dept-chips">@foreach($viewDepts as $dc)<span class="js-dept-chip">{{ $dc }}</span>@endforeach</div>
                </div>
            </div>
            @endif
            <div class="js-section">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:12px;">Posting Details</div>
                <div class="js-admin-grid">
                    <div class="js-admin-cell"><div class="js-admin-label">Posted On</div><div class="js-admin-value">{{ $job->created_at->format('M d, Y') }}</div><div class="js-admin-sub">{{ $job->created_at->format('g:i A') }}</div></div>
                    <div class="js-admin-cell"><div class="js-admin-label">Deadline</div><div class="js-admin-value">{{ $dl->format('M d, Y') }}</div><div class="js-admin-sub" style="{{ $isExpired ? 'color:#c0392b;' : '' }}">{{ $isExpired ? 'Expired' : $dl->diffForHumans() }}</div></div>
                    <div class="js-admin-cell"><div class="js-admin-label">Status</div><div class="js-admin-value">{{ $job->status }}</div></div>
                    <div class="js-admin-cell-full">
                        <div class="js-admin-label">Last Updated</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div><div class="js-admin-value">{{ $job->updated_at->format('M d, Y · g:i A') }}</div><div class="js-admin-sub">{{ $job->updated_at->diffForHumans() }}</div></div>
                            @if($wasEdited)
                            <div class="js-update-badge {{ $updaterRole === 'admin' ? 'admin-badge' : '' }}">
                                <i class="fas fa-{{ $updaterRole === 'admin' ? 'shield-halved' : 'user' }}" style="font-size:9px;"></i>
                                Updated by {{ $updaterName }}
                                <span style="opacity:.55;font-weight:400;">· {{ $updaterRole === 'admin' ? 'Admin' : 'Organizer' }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="js-footer">
            <button wire:click="closeViewModal" class="js-btn js-btn-close">
                <i class="fas fa-xmark" style="font-size:12px;"></i> Close
            </button>
            @if($job->status === 'ACTIVE')
                <button wire:click="confirmToggle({{ $job->id }})" class="js-btn js-btn-deactivate">
                    <i class="fas fa-ban" style="font-size:12px;"></i> Deactivate
                </button>
            @else
                <button wire:click="confirmToggle({{ $job->id }})" class="js-btn js-btn-activate">
                    <i class="fas fa-circle-check" style="font-size:12px;"></i> Activate
                </button>
            @endif
            <button wire:click="openEditModal({{ $job->id }})" class="js-btn js-btn-edit">
                <i class="fas fa-pen-to-square" style="font-size:12px;"></i> Edit Posting
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════
     MODAL: Confirm Delete
════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:420px;max-width:95vw;">
        <div class="px-7 py-6 bg-red-50 border-b border-red-200 flex items-center gap-3 rounded-t-2xl">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fas fa-triangle-exclamation text-red-500 text-lg"></i>
            </div>
            <h2 class="text-lg font-extrabold text-red-800">Delete Job Posting</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5">
                <p class="text-amber-800 text-xs font-semibold flex items-center gap-2">
                    <i class="fas fa-info-circle text-amber-500"></i>
                    This job will be removed from your list but kept in the system. The admin can restore it if needed.
                </p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="confirm-btn confirm-btn-cancel">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete" class="confirm-btn confirm-btn-delete">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin-icon"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════
     MODAL: Confirm Toggle Status
════════════════════════════════════════════════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:380px;max-width:95vw;">
        <div class="px-7 py-6 {{ $confirmAction === 'ACTIVE' ? 'bg-green-50 border-b border-green-200' : 'bg-amber-50 border-b border-amber-200' }} flex items-center gap-3 rounded-t-2xl">
            <div class="w-10 h-10 {{ $confirmAction === 'ACTIVE' ? 'bg-green-100' : 'bg-amber-100' }} rounded-xl flex items-center justify-center shrink-0">
                @if($confirmAction === 'ACTIVE')
                    <i class="fas fa-circle-check text-green-600 text-lg"></i>
                @else
                    <i class="fas fa-ban text-amber-600 text-lg"></i>
                @endif
            </div>
            <h2 class="text-lg font-extrabold {{ $confirmAction === 'ACTIVE' ? 'text-green-800' : 'text-amber-800' }}">
                {{ $confirmAction === 'ACTIVE' ? 'Activate Job Posting?' : 'Deactivate Job Posting?' }}
            </h2>
        </div>
        <div class="p-7">
            <p class="text-sm text-slate-500 leading-relaxed mb-6">
                This will mark the job as <span class="font-bold {{ $confirmAction === 'ACTIVE' ? 'text-green-600' : 'text-amber-600' }}">{{ $confirmAction }}</span>.
                @if($confirmAction === 'INACTIVE')
                <br><span class="text-xs text-slate-400 mt-1 block">The job will be hidden from students but you can still edit it.</span>
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm" class="confirm-btn confirm-btn-cancel">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="confirm-btn {{ $confirmAction === 'ACTIVE' ? 'confirm-btn-activate' : 'confirm-btn-deactivate' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        <i class="fas fa-{{ $confirmAction === 'ACTIVE' ? 'circle-check' : 'ban' }}"></i>
                        {{ $confirmAction === 'ACTIVE' ? 'Yes, Activate' : 'Yes, Deactivate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>