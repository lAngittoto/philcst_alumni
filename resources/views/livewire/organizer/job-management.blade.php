<?php
/**
 * FILE: resources/views/livewire/organizer/job-management.blade.php
 * FIXED: Wrapped <style> inside single root <div> to satisfy Livewire's single-root requirement.
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

        $org = auth()->user()?->organizer;
        $dupQuery = OrganizerJob::where('job_title', trim($this->jobTitle))
            ->where('company_name', $companyName)
            ->where('employment_type', trim($this->employmentType))
            ->where('organizer_id', $org?->id)
            ->whereNotIn('status', ['ORGANIZER_DELETED']);

        if ($this->isEditing) {
            $dupQuery->where('id', '!=', $this->editingJobId);
        }

        if ($dupQuery->exists()) {
            $this->formErrors['jobTitle'] = 'You already have a job posting with this title, organization, and employment type.';
            return;
        }

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

{{-- ✅ SINGLE ROOT ELEMENT — <style> moved inside the root <div> --}}
<div>
<style>
:root{--brand:#7a3f91;--brand-d:#5e2f72;--brand-50:#f5eef9;--brand-100:#e9d5f3;--brand-200:#d4aaeb;}
.btn-brand{background:#7a3f91;color:#fff;box-shadow:0 2px 8px rgba(122,63,145,.28);transition:background .18s,box-shadow .18s,transform .12s;}
.btn-brand:hover:not(:disabled){background:#5e2f72;box-shadow:0 4px 16px rgba(122,63,145,.38);transform:translateY(-1px);}
.btn-brand:active:not(:disabled){transform:translateY(0);}
.btn-brand:disabled{opacity:.5;cursor:not-allowed;}
.btn-ghost{background:#fff;color:#374151;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:all .15s;}
.btn-ghost:hover:not(:disabled){background:#f9fafb;border-color:#d1d5db;transform:translateY(-1px);}
.btn-danger{background:#fff;color:#dc2626;border:1px solid #fecaca;transition:all .15s;}
.btn-danger:hover:not(:disabled){background:#fef2f2;border-color:#f87171;transform:translateY(-1px);}
.btn-success{background:#fff;color:#16a34a;border:1px solid #bbf7d0;transition:all .15s;}
.btn-success:hover:not(:disabled){background:#f0fdf4;border-color:#4ade80;transform:translateY(-1px);}
.btn-warn{background:#fff;color:#d97706;border:1px solid #fde68a;transition:all .15s;}
.btn-warn:hover:not(:disabled){background:#fffbeb;border-color:#fcd34d;transform:translateY(-1px);}
.btn-view{background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;transition:all .15s;}
.btn-view:hover{background:#e9d5f3;border-color:#9b5bb0;transform:translateY(-1px);}
.inp{transition:border-color .15s,box-shadow .15s;}
.inp:focus{outline:none;border-color:#7a3f91;box-shadow:0 0 0 3px rgba(122,63,145,.11);}
.tbl-row{transition:background-color .12s;}
.tbl-row:hover{background-color:#faf5fc;}
.tbl-load{opacity:.45;pointer-events:none;transition:opacity .2s;}
.scroll-c::-webkit-scrollbar{width:5px;height:5px;}
.scroll-c::-webkit-scrollbar-track{background:#f3f4f6;border-radius:99px;}
.scroll-c::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:99px;}
.scroll-c::-webkit-scrollbar-thumb:hover{background:#9b5bb0;}
@keyframes mIn{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}
.m-in{animation:mIn .2s cubic-bezier(.25,.8,.25,1) both;}
@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.spin{animation:spin 1s linear infinite;}
.form-lbl{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:.45rem;letter-spacing:.01em;}
.form-inp{width:100%;padding:.625rem 1rem;border:1.5px solid #e2e8f0;border-radius:.5rem;font-size:.875rem;color:#1e293b;background:#fff;transition:border-color .15s,box-shadow .15s;}
.form-inp:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.12)!important;outline:none!important;}
.form-inp:disabled,.form-inp[readonly]{background:#f1f5f9;color:#64748b;cursor:not-allowed;}
.form-err{font-size:.74rem;color:#ef4444;margin-top:.35rem;display:flex;align-items:center;gap:.3rem;}
.field-err{border-color:#f87171!important;background:#fff8f8!important;}
.field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem;}
.org-cat-btn{flex:1;padding:13px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;transition:all .18s;text-align:center;font-size:.8rem;font-weight:700;color:#64748b;display:flex;flex-direction:column;align-items:center;gap:6px;}
.org-cat-btn:hover{border-color:#7a3f91;color:#7a3f91;background:#faf5ff;}
.org-cat-btn.active{border-color:#7a3f91;background:linear-gradient(135deg,#7a3f91,#6a3580);color:#fff;box-shadow:0 3px 12px rgba(122,63,145,.3);}
.org-confirm-box{border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px;}
.org-confirm-box.philcst-box{background:#faf5ff;border:1.5px solid #c4b5fd;}
.org-confirm-box.partner-box{background:#eff6ff;border:1.5px solid #bfdbfe;}
.org-confirm-box.custom-box{background:#f8fafc;border:1.5px solid #e2e8f0;}
.org-confirm-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;flex-shrink:0;}
.jv-modal{background:#fff;border-radius:16px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:760px;max-width:96vw;max-height:92vh;overflow:hidden;}
.jv-header{padding:26px 32px 20px;border-bottom:1px solid #f0f0f0;flex-shrink:0;position:relative;}
.jv-title{font-size:22px;font-weight:800;color:#111;line-height:1.25;margin-bottom:6px;padding-right:36px;}
.jv-company{display:flex;align-items:center;gap:6px;font-size:13.5px;color:#444;margin-bottom:16px;flex-wrap:wrap;}
.jv-pill{font-size:11px;font-weight:700;border-radius:4px;padding:2px 8px;}
.jv-pill-type{background:#f0ebff;color:#6d28d9;}
.jv-pill-active{background:#dcfce7;color:#15803d;}
.jv-pill-inactive{background:#fef9c3;color:#a16207;}
.jv-meta{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:9px;}
.jv-meta-item{display:flex;align-items:flex-start;gap:11px;font-size:13.5px;color:#222;line-height:1.4;}
.jv-meta-icon{width:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;color:#7a3f91;font-size:13px;}
.jv-body{flex:1;min-height:0;overflow-y:auto;}
.jv-section{padding:22px 32px;border-bottom:1px solid #f0f0f0;}
.jv-section:last-child{border-bottom:none;}
.jv-section-title{font-size:15px;font-weight:700;color:#111;margin-bottom:12px;}
.jv-desc{font-size:13.5px;color:#222;line-height:1.85;white-space:pre-wrap;}
.jv-grid{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;}
.jv-cell{padding:13px 16px;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8;}
.jv-cell:nth-child(3n){border-right:none;}
.jv-cell-full{grid-column:span 3;padding:13px 16px;border-bottom:none;}
.jv-cell-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px;}
.jv-cell-val{font-size:13px;font-weight:600;color:#111;}
.jv-cell-sub{font-size:11px;color:#888;margin-top:1px;}
.jv-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:4px;margin-top:6px;background:#f5f5f5;color:#555;border:1px solid #e5e5e5;}
.jv-badge.admin{background:#f5f0ff;color:#6d28d9;border-color:#e5d9ff;}
.jv-college-box{background:#faf5ff;border:1px solid #e0d7f5;border-radius:6px;padding:14px 18px;}
.jv-dept-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.jv-dept-chip{font-size:11px;font-weight:700;font-family:'Courier New',monospace;background:#fff;border:1px solid #d4c5f0;border-radius:3px;padding:3px 8px;color:#6d28d9;}
.jv-footer{padding:14px 32px;border-top:1px solid #ebebeb;display:flex;align-items:center;justify-content:flex-end;background:#fff;flex-shrink:0;gap:8px;}
.jv-close-x{position:absolute;top:16px;right:18px;width:28px;height:28px;border-radius:50%;border:none;background:transparent;color:#999;font-size:19px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s;line-height:1;}
.jv-close-x:hover{background:#f0f0f0;color:#333;}
</style>

<div class="min-h-screen bg-gray-50">

{{-- FLASH TOAST --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-6 pb-8 max-w-screen-2xl mx-auto">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl bg-[#7a3f91] flex items-center justify-center shadow-lg flex-shrink-0" style="box-shadow:0 4px 14px rgba(122,63,145,.35);">
                <i class="fas fa-briefcase text-white text-lg sm:text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-800 tracking-tight">My Job Postings</h1>
                <p class="text-gray-500 text-xs sm:text-sm mt-0.5">Create and manage your job listings for students.</p>
            </div>
        </div>
        <button wire:click="openCreateModal"
                class="btn-brand inline-flex items-center gap-2 px-5 py-3 rounded-xl font-bold text-sm shrink-0">
            <i class="fas fa-plus text-sm"></i> Post a Job
        </button>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden" style="min-height:0;height:calc(100vh - 210px);">

        {{-- FILTER BAR --}}
        <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] sm:min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.150ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-800"
                       autocomplete="off">
            </div>
            <select wire:model.live="filterStatus" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700 min-w-[120px]">
                <option value="">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
            <select wire:model.live="filterType" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700 min-w-[150px] hidden sm:block">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}" @selected($filterType === $opt->label)>{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700 min-w-[130px] hidden sm:block">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters" class="btn-ghost px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5">
                <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>
        {{-- mobile row 2 --}}
        <div class="px-4 py-2 border-b border-gray-100 bg-gray-50/80 flex gap-2 sm:hidden">
            <select wire:model.live="filterType" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- TABLE --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto scroll-c"
                 wire:loading.class="tbl-load"
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeToggle,executeDelete">
                <table class="w-full border-collapse min-w-[560px]">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200 sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Job Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Organization</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-500 uppercase tracking-wider hidden md:table-cell">Employment Type</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Deadline</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($this->jobPostings as $job)
                        @php $dl = \Carbon\Carbon::parse($job->deadline); @endphp
                        <tr class="tbl-row bg-white">
                            <td class="px-4 sm:px-5 py-3.5 max-w-[160px] sm:max-w-[200px]">
                                <p class="font-semibold text-sm text-gray-800 truncate">{{ $job->job_title }}</p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 max-w-[150px]">
                                <p class="font-semibold text-sm text-gray-700 truncate">{{ $job->company_name }}</p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden md:table-cell">
                                <span class="inline-block px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-100 rounded-full text-xs font-semibold">{{ $job->employment_type }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden lg:table-cell whitespace-nowrap">
                                <span class="text-sm font-semibold text-gray-700">{{ $dl->format('M d, Y') }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 text-center whitespace-nowrap">
                                @if($job->status === 'ACTIVE')
                                    <span class="inline-block px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">Active</span>
                                @else
                                    <span class="inline-block px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button wire:click="viewJob({{ $job->id }})" class="btn-view inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold">
                                        <i class="fas fa-eye text-xs"></i><span class="hidden sm:inline">View</span>
                                    </button>
                                    @if($job->status === 'ACTIVE')
                                        <button wire:click="confirmToggle({{ $job->id }})" class="btn-warn inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                            <i class="fas fa-ban text-xs"></i><span class="hidden sm:inline">Deactivate</span>
                                        </button>
                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})" class="btn-success inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                            <i class="fas fa-circle-check text-xs"></i><span class="hidden sm:inline">Activate</span>
                                        </button>
                                    @endif
                                    <button wire:click="confirmDelete({{ $job->id }})" class="btn-danger inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                        <i class="fas fa-trash text-xs"></i><span class="hidden lg:inline">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-briefcase text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="font-semibold text-gray-400">No job postings yet</p>
                                    <p class="text-sm text-gray-400">Click <strong>Post a Job</strong> to get started.</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- PAGINATION FOOTER --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-100 bg-gray-50/80 shrink-0 shadow-[0_-1px_4px_rgba(0,0,0,.04)]">
            @php
                $total=$this->jobPostings->total();$pp=$this->jobPostings->perPage();$cp=$this->jobPostings->currentPage();
                $from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-gray-500 text-xs sm:text-sm">
                    Showing <span class="font-bold text-gray-700">{{ $from }}–{{ $to }}</span> of <span class="font-bold text-gray-700">{{ $total }}</span> jobs
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">← Prev</button>
                    @endif
                    <span class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold shadow-sm">{{ $cp }} / {{ $this->jobPostings->lastPage() }}</span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">Next →</button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════ MODAL: Create / Edit Job ════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.closeFormModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden">
        <div class="flex items-center justify-between px-7 py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-{{ $isEditing ? 'pen-to-square' : 'briefcase' }}"></i>
                {{ $isEditing ? 'Edit Job Posting' : 'Post a New Job' }}
            </h2>
            <button wire:click="closeFormModal" class="text-white/70 hover:text-white text-2xl leading-none transition">×</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-c px-7 py-6 space-y-5">

            <div>
                <label class="form-lbl">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="jobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-inp {{ isset($formErrors['jobTitle']) ? 'field-err' : '' }}">
                @if(isset($formErrors['jobTitle']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['jobTitle'] }}</p>@endif
            </div>

            <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 px-5 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-building text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Organization Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="form-lbl">Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <button type="button" wire:click="$set('orgCategory','philcst')" class="org-cat-btn {{ $orgCategory==='philcst'?'active':'' }}">
                                <i class="fas fa-school text-lg"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('orgCategory','partner')" class="org-cat-btn {{ $orgCategory==='partner'?'active':'' }}">
                                <i class="fas fa-handshake text-lg"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('orgCategory','custom')" class="org-cat-btn {{ $orgCategory==='custom'?'active':'' }}">
                                <i class="fas fa-pen-to-square text-lg"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($formErrors['orgCategory']))<p class="form-err mt-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['orgCategory'] }}</p>@endif
                    </div>

                    @if($orgCategory==='philcst')
                        @if($philcstName)
                        <div class="org-confirm-box philcst-box">
                            <div class="org-confirm-icon" style="background:linear-gradient(135deg,#7a3f91,#6a3580)"><i class="fas fa-school"></i></div>
                            <div class="flex-1"><div style="font-size:.875rem;font-weight:700;color:#4c1d95;">{{ $philcstName }}</div>
                            @if($philcstLocation)<div style="font-size:.75rem;color:#7c3aed;margin-top:2px;"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif</div>
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-white border border-purple-200 px-3 py-1.5 rounded-full shrink-0"><i class="fas fa-lock text-[10px]"></i> Auto-filled</span>
                        </div>
                        @else
                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700"><i class="fas fa-triangle-exclamation mr-2"></i>No PHILCST campus found.</div>
                        @endif
                    @elseif($orgCategory==='partner')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-lbl">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="partnerOrgName" type="text" placeholder="e.g. Acme Corporation"
                                       class="form-inp {{ isset($formErrors['partnerOrgName'])?'field-err':'' }}">
                                @if(isset($formErrors['partnerOrgName']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['partnerOrgName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-lbl">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="partnerOrgType" type="text" placeholder="e.g. Private Company, NGO"
                                       class="form-inp {{ isset($formErrors['partnerOrgType'])?'field-err':'' }}">
                                @if(isset($formErrors['partnerOrgType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['partnerOrgType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-lbl">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="location" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote" maxlength="120"
                                   class="form-inp {{ isset($formErrors['location'])?'field-err':'' }}">
                            @if(isset($formErrors['location']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['location'] }}</p>@endif
                        </div>
                        @if(trim($partnerOrgName))
                        <div class="org-confirm-box partner-box">
                            <div class="org-confirm-icon" style="background:#2557a7"><i class="fas fa-handshake"></i></div>
                            <div><div style="font-size:.875rem;font-weight:700;color:#1e3a5f;">{{ $partnerOrgName }}</div>
                            @if(trim($partnerOrgType))<div style="font-size:.75rem;color:#2557a7;margin-top:2px;">{{ $partnerOrgType }}</div>@endif
                            @if(trim($location))<div style="font-size:.75rem;color:#555;margin-top:2px;"><i class="fas fa-location-dot mr-1"></i>{{ $location }}</div>@endif</div>
                        </div>
                        @endif
                    @elseif($orgCategory==='custom')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-lbl">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="customOrgName" type="text" placeholder="e.g. Department of Labor"
                                       class="form-inp {{ isset($formErrors['customOrgName'])?'field-err':'' }}">
                                @if(isset($formErrors['customOrgName']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['customOrgName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-lbl">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="customOrgType" type="text" placeholder="e.g. Government Agency, NGO"
                                       class="form-inp {{ isset($formErrors['customOrgType'])?'field-err':'' }}">
                                @if(isset($formErrors['customOrgType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['customOrgType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-lbl">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="location" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="form-inp {{ isset($formErrors['location'])?'field-err':'' }}">
                            @if(isset($formErrors['location']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['location'] }}</p>@endif
                        </div>
                        @if(trim($customOrgName))
                        <div class="org-confirm-box custom-box">
                            <div class="org-confirm-icon" style="background:#475569"><i class="fas fa-pen-to-square"></i></div>
                            <div><div style="font-size:.875rem;font-weight:700;color:#1e293b;">{{ $customOrgName }}</div>
                            @if(trim($customOrgType))<div style="font-size:.75rem;color:#475569;margin-top:2px;">{{ $customOrgType }}</div>@endif
                            @if(trim($location))<div style="font-size:.75rem;color:#555;margin-top:2px;"><i class="fas fa-location-dot mr-1"></i>{{ $location }}</div>@endif</div>
                        </div>
                        @endif
                    @else
                    <div class="text-center py-5 text-gray-400 text-sm"><i class="fas fa-arrow-up text-gray-300 text-xl block mb-2"></i>Select a category above to continue.</div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="employmentType" class="form-inp {{ isset($formErrors['employmentType'])?'field-err':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($formErrors['employmentType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['employmentType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-lbl">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="experienceLevel" class="form-inp {{ isset($formErrors['experienceLevel'])?'field-err':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($formErrors['experienceLevel']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['experienceLevel'] }}</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Salary <span class="text-gray-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="salary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-inp">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-lbl">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="deadline" type="date" class="form-inp {{ isset($formErrors['deadline'])?'field-err':'' }}">
                    @if(isset($formErrors['deadline']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['deadline'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-lbl">Target College <span class="text-gray-400 font-normal text-xs">(Optional — blank = all colleges)</span></label>
                <select wire:model.live="targetCollege" class="form-inp">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $selectedCollegeDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->targetCollege)['codes'] ?? []; @endphp
                @if(count($selectedCollegeDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($selectedCollegeDepts as $dCode)
                        <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>
                    @endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-lbl">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="description" rows="6" placeholder="Describe the role, responsibilities, qualifications…"
                          class="form-inp resize-none {{ isset($formErrors['description'])?'field-err':'' }}"></textarea>
                @if(isset($formErrors['description']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['description'] }}</p>@endif
            </div>
        </div>

        <div class="px-7 py-5 border-t border-gray-100 bg-gray-50/80 flex-shrink-0 flex gap-3">
            <button wire:click="closeFormModal" class="btn-ghost flex-1 px-4 py-3 rounded-xl text-sm font-bold">Cancel</button>
            <button wire:click="saveJob" wire:loading.attr="disabled" wire:target="saveJob"
                    class="btn-brand flex-1 px-4 py-3 rounded-xl text-sm font-extrabold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveJob"><i class="fas fa-spinner spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveJob">
                    <i class="fas fa-{{ $isEditing ? 'floppy-disk' : 'paper-plane' }} mr-1"></i>
                    {{ $isEditing ? 'Save Changes' : 'Post Job' }}
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: View Job ════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job      = $this->viewingJob;
    $dl       = \Carbon\Carbon::parse($job->deadline);
    $isExp    = now()->gt($dl);
    $viewDepts = $job->target_college
        ? \App\Models\Course::where('college', $job->target_college)->orderBy('code')->pluck('code')->toArray()
        : [];
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.closeViewModal()">
    <div class="jv-modal m-in relative">
        <button wire:click="closeViewModal" class="jv-close-x">&times;</button>
        <div class="jv-header">
            <div class="jv-title">{{ $job->job_title }}</div>
            <div class="jv-company">
                <strong>{{ $job->company_name }}</strong>
                <span class="jv-pill jv-pill-type">{{ $job->company_type }}</span>
                @if($job->status==='ACTIVE')<span class="jv-pill jv-pill-active">● Active</span>
                @else<span class="jv-pill jv-pill-inactive">● Inactive</span>@endif
            </div>
            <ul class="jv-meta">
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-location-dot"></i></span><span>{{ $job->location ?? 'Not specified' }}</span></li>
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-clock"></i></span><span>{{ $job->employment_type }}</span></li>
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-layer-group"></i></span><span>{{ $job->experience_level }}</span></li>
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-money-bill-wave"></i></span>
                    @if($job->salary)<span>{{ $job->salary }}</span>@else<span style="color:#999;font-style:italic;">Salary not disclosed</span>@endif
                </li>
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-calendar-xmark"></i></span>
                    <span>Deadline: {{ $dl->format('F d, Y') }}
                        @if($isExp)<span style="color:#c0392b;font-weight:700;margin-left:6px;">(Expired)</span>
                        @else<span style="color:#666;margin-left:6px;">· {{ $dl->diffForHumans() }}</span>@endif
                    </span>
                </li>
                @if($job->target_college)
                <li class="jv-meta-item"><span class="jv-meta-icon"><i class="fas fa-building-columns"></i></span><span>For: {{ $job->target_college }}</span></li>
                @endif
            </ul>
            <p style="margin-top:14px;font-size:12px;color:#777;">Posted {{ $job->created_at->diffForHumans() }}</p>
        </div>
        <div class="jv-body scroll-c">
            <div class="jv-section">
                <div class="jv-section-title">Job Description</div>
                <div class="jv-desc">{{ $job->description }}</div>
            </div>
            @if($job->target_college && count($viewDepts))
            <div class="jv-section">
                <div class="jv-section-title">Target College</div>
                <div class="jv-college-box">
                    <div style="font-size:14px;font-weight:700;color:#111;">{{ $job->target_college }}</div>
                    <div class="jv-dept-chips">@foreach($viewDepts as $dc)<span class="jv-dept-chip">{{ $dc }}</span>@endforeach</div>
                </div>
            </div>
            @endif
            <div class="jv-section">
                <div class="jv-cell-lbl" style="margin-bottom:12px;">Posting Details</div>
                <div class="jv-grid">
                    <div class="jv-cell"><div class="jv-cell-lbl">Posted On</div><div class="jv-cell-val">{{ $job->created_at->format('M d, Y') }}</div><div class="jv-cell-sub">{{ $job->created_at->format('g:i A') }}</div></div>
                    <div class="jv-cell"><div class="jv-cell-lbl">Deadline</div><div class="jv-cell-val">{{ $dl->format('M d, Y') }}</div><div class="jv-cell-sub" style="{{ $isExp ? 'color:#c0392b' : '' }}">{{ $isExp ? 'Expired' : $dl->diffForHumans() }}</div></div>
                    <div class="jv-cell"><div class="jv-cell-lbl">Status</div><div class="jv-cell-val">{{ $job->status }}</div></div>
                    <div class="jv-cell-full"><div class="jv-cell-lbl">Last Updated</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div><div class="jv-cell-val">{{ $job->updated_at->format('M d, Y · g:i A') }}</div><div class="jv-cell-sub">{{ $job->updated_at->diffForHumans() }}</div></div>
                            @if($job->updated_by)
                            <span class="jv-badge {{ $job->updated_by_role==='admin' ? 'admin' : '' }}">
                                <i class="fas fa-{{ $job->updated_by_role==='admin' ? 'shield-halved' : 'user' }}" style="font-size:9px"></i>
                                Updated by {{ $job->updated_by }}
                            </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="jv-footer">
            <button wire:click="closeViewModal" class="btn-ghost inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"><i class="fas fa-xmark text-xs"></i> Close</button>
            @if($job->status==='ACTIVE')
                <button wire:click="confirmToggle({{ $job->id }})" class="btn-warn inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"><i class="fas fa-ban text-xs"></i> Deactivate</button>
            @else
                <button wire:click="confirmToggle({{ $job->id }})" class="btn-success inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"><i class="fas fa-circle-check text-xs"></i> Activate</button>
            @endif
            <button wire:click="openEditModal({{ $job->id }})" class="btn-brand inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold"><i class="fas fa-pen-to-square text-xs"></i> Edit</button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Confirm Toggle ════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelConfirm()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden">
        <div class="px-6 py-5 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-50 border-b border-emerald-100' : 'bg-amber-50 border-b border-amber-100' }}">
            <h2 class="text-lg font-extrabold {{ $confirmAction==='ACTIVE' ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-xl flex items-center justify-center">
                    <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check text-emerald-600' : 'ban text-amber-600' }} text-sm"></i>
                </div>
                {{ $confirmAction==='ACTIVE' ? 'Activate Job?' : 'Deactivate Job?' }}
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 leading-relaxed mb-5">
                This will mark the job as <span class="font-bold {{ $confirmAction==='ACTIVE' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $confirmAction }}</span>.
                @if($confirmAction==='INACTIVE') It will be hidden from students but you can still edit it.@endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm" class="btn-ghost flex-1 px-4 py-3 rounded-xl text-sm font-bold">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-extrabold text-white flex items-center justify-center gap-2 transition shadow-md
                               {{ $confirmAction==='ACTIVE' ? 'bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300' : 'bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check' : 'ban' }} mr-1"></i>
                        {{ $confirmAction==='ACTIVE' ? 'Yes, Activate' : 'Yes, Deactivate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-triangle-exclamation text-red-500 text-sm"></i></div>
                Delete Job Posting
            </h2>
        </div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">Deleting:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-xs text-amber-800 flex items-start gap-2">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 shrink-0"></i>
                <span>This job will be removed from your list but kept in the system. The admin can restore it if needed.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="btn-ghost flex-1 px-4 py-3 rounded-xl text-sm font-bold">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- end .min-h-screen --}}
</div>{{-- end single root --}}