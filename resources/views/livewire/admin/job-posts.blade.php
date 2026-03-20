<?php
/**
 * FILE: resources/views/livewire/admin/jobs.blade.php
 *
 * Changes:
 * - Table: Employment Type (full), Activity (most recent: updated > posted), Org name only
 * - Location validation removed
 * - Edit allowed on INACTIVE (hidden from students only)
 * - Organizer-deleted jobs visible to admin with Restore button
 * - Restore → status back to ACTIVE, updated_by = "Restored by [admin]"
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
    public string $filterSort   = 'recent';

    // ── Post Job Modal ────────────────────────────────────────
    public bool   $showPostModal     = false;
    public string $postJobTitle      = '';
    public string $postOrgCategory   = '';
    public string $postPartnerName   = '';
    public string $postPartnerType   = '';
    public string $postCustomName    = '';
    public string $postCustomType    = '';
    public string $postLocation      = '';
    public string $postEmpType       = '';
    public string $postExpLevel      = '';
    public string $postSalary        = '';
    public string $postDeadline      = '';
    public string $postDescription   = '';
    public string $postTargetCollege = '';
    public array  $postErrors        = [];

    public string $philcstName     = '';
    public string $philcstLocation = '';

    // ── View Full Job Modal ───────────────────────────────────
    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    // ── Edit Job Modal ────────────────────────────────────────
    public bool   $showEditModal     = false;
    public ?int   $editingJobId      = null;
    public string $editJobTitle      = '';
    public string $editCompany       = '';
    public string $editCompanyType   = '';
    public string $editLocation      = '';
    public string $editEmpType       = '';
    public string $editExpLevel      = '';
    public string $editSalary        = '';
    public string $editDeadline      = '';
    public string $editDescription   = '';
    public string $editTargetCollege = '';
    public array  $editErrors        = [];

    // ── Toggle Status Confirmation ────────────────────────────
    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    // ── Delete Job Confirmation ───────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    // ── Restore Confirmation ──────────────────────────────────
    public bool   $showRestoreModal = false;
    public ?int   $restoreJobId     = null;
    public string $restoreJobTitle  = '';

    // ── Mount ─────────────────────────────────────────────────
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

    // ── Lifecycle ─────────────────────────────────────────────
    public function updatingSearch()       { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingFilterType()   { $this->resetPage(); }
    public function updatingFilterSort()   { $this->resetPage(); }

    public function updatedPostOrgCategory(string $value): void
    {
        $this->postPartnerName = $this->postPartnerType = '';
        $this->postCustomName  = $this->postCustomType  = '';
        $this->postLocation    = '';
        if ($value === 'philcst') {
            $this->postLocation = $this->philcstLocation;
        }
    }

    public function updatedEditCompanyType(string $value): void
    {
        if ($value === '') return;
        $opt = JobOption::where('type', 'company_type')->where('label', $value)->first();
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
                'id', 'organizer_id', 'job_title', 'company_name', 'company_type',
                'location', 'employment_type', 'experience_level',
                'target_college', 'salary', 'deadline', 'status',
                'created_at', 'updated_at', 'updated_by', 'updated_by_role',
                'deleted_by', 'deleted_by_role',
            ]);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        if ($this->filterType !== '') $q->where('employment_type', $this->filterType);
        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');

        return $q->paginate(20);
    }

    // ── Computed: Job Options ─────────────────────────────────
    #[Computed]
    public function jobOptions()
    {
        return JobOption::orderBy('type')->orderBy('label')->get()->groupBy('type');
    }

    // ── Computed: Viewing Job ─────────────────────────────────
    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return app(JobController::class)->getJob($this->viewingJobId);
    }

    // ── Computed: Colleges ────────────────────────────────────
    #[Computed]
    public function collegesWithDepts(): array
    {
        return app(\App\Http\Controllers\OrganizerJobController::class)->getCollegesWithDepts();
    }

    // ── Reset Filters ─────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    // ── Post Job ──────────────────────────────────────────────
    public function openPostModal(): void
    {
        $this->resetPostFields();
        $this->postDeadline  = now()->addMonth()->format('Y-m-d');
        $this->showPostModal = true;
    }

    public function closePostModal(): void
    {
        $this->showPostModal = false;
        $this->resetPostFields();
    }

    public function savePost(): void
    {
        $this->postErrors = [];
        $errors = [];

        if (!trim($this->postJobTitle))    $errors['postJobTitle']    = 'Job title is required.';
        if (!trim($this->postOrgCategory)) $errors['postOrgCategory'] = 'Please select an organization category.';

        if ($this->postOrgCategory === 'partner') {
            if (!trim($this->postPartnerName)) $errors['postPartnerName'] = 'Organization name is required.';
            if (!trim($this->postPartnerType)) $errors['postPartnerType'] = 'Organization type is required.';
            if (!trim($this->postLocation))    $errors['postLocation']    = 'Location is required.';
        }
        if ($this->postOrgCategory === 'custom') {
            if (!trim($this->postCustomName)) $errors['postCustomName'] = 'Organization name is required.';
            if (!trim($this->postCustomType)) $errors['postCustomType'] = 'Organization type is required.';
            if (!trim($this->postLocation))   $errors['postLocation']   = 'Location is required.';
        }

        if (!trim($this->postEmpType))  $errors['postEmpType']  = 'Employment type is required.';
        if (!trim($this->postExpLevel)) $errors['postExpLevel'] = 'Experience level is required.';
        if (!trim($this->postDeadline)) {
            $errors['postDeadline'] = 'Deadline is required.';
        } elseif (strtotime($this->postDeadline) < strtotime('today')) {
            $errors['postDeadline'] = 'Deadline must be a future date.';
        }
        if (!trim($this->postDescription)) $errors['postDescription'] = 'Job description is required.';

        if (!empty($errors)) { $this->postErrors = $errors; return; }

        [$companyName, $companyType] = match($this->postOrgCategory) {
            'philcst' => [$this->philcstName,           $this->philcstName],
            'partner' => [trim($this->postPartnerName), trim($this->postPartnerType)],
            'custom'  => [trim($this->postCustomName),  trim($this->postCustomType)],
            default   => ['', ''],
        };

        $resolvedLocation = $this->postOrgCategory === 'philcst'
            ? $this->philcstLocation
            : trim($this->postLocation);

        JobPosting::create([
            'organizer_id'     => null,
            'job_title'        => trim($this->postJobTitle),
            'company_name'     => $companyName,
            'company_type'     => $companyType,
            'location'         => $resolvedLocation,
            'employment_type'  => trim($this->postEmpType),
            'experience_level' => trim($this->postExpLevel),
            'salary'           => trim($this->postSalary) ?: null,
            'deadline'         => $this->postDeadline,
            'description'      => trim($this->postDescription),
            'target_college'   => trim($this->postTargetCollege) ?: null,
            'status'           => 'ACTIVE',
            'updated_by'       => auth()->user()->name,
            'updated_by_role'  => 'admin',
        ]);

        $this->dispatch('flash-message', type: 'success', message: 'Job posting created successfully!');
        $this->showPostModal = false;
        $this->resetPostFields();
    }

    private function resetPostFields(): void
    {
        $this->postJobTitle = $this->postOrgCategory = '';
        $this->postPartnerName = $this->postPartnerType = $this->postCustomName = $this->postCustomType = '';
        $this->postLocation = $this->postEmpType = $this->postExpLevel = $this->postSalary = '';
        $this->postDeadline = $this->postDescription = $this->postTargetCollege = '';
        $this->postErrors = [];
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

        $this->editingJobId      = $id;
        $this->editJobTitle      = $job->job_title;
        $this->editCompany       = $job->company_name;
        $this->editCompanyType   = $job->company_type;
        $this->editLocation      = $job->location ?? '';
        $this->editEmpType       = $job->employment_type;
        $this->editExpLevel      = $job->experience_level;
        $this->editSalary        = $job->salary ?? '';
        $this->editDeadline      = $job->deadline->format('Y-m-d');
        $this->editDescription   = $job->description;
        $this->editTargetCollege = $job->target_college ?? '';
        $this->editErrors        = [];
        $this->showViewModal     = false;
        $this->showEditModal     = true;
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
        if (!trim($this->editCompany))     $errors['editCompany']     = 'Organization name is required.';
        if (!trim($this->editCompanyType)) $errors['editCompanyType'] = 'Organization type is required.';
        if (!trim($this->editLocation))    $errors['editLocation']    = 'Location is required.';
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
            'target_college'   => trim($this->editTargetCollege) ?: null,
            'updated_by'       => auth()->user()->name,
            'updated_by_role'  => 'admin',
        ]);

        $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully.');
        $this->showEditModal = false;
        $this->resetEditFields();
    }

    private function resetEditFields(): void
    {
        $this->editingJobId      = null;
        $this->editJobTitle      = $this->editCompany = $this->editCompanyType = '';
        $this->editLocation      = $this->editEmpType = $this->editExpLevel    = '';
        $this->editSalary        = $this->editDeadline = $this->editDescription = '';
        $this->editTargetCollege = '';
        $this->editErrors        = [];
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
        $this->deleteJobId    = $id;
        $this->deleteJobTitle = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteJobId) {
            app(JobController::class)->deleteJob($this->deleteJobId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been permanently deleted.");
        }
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
    }

    // ── Restore ───────────────────────────────────────────────
    public function confirmRestore(int $id): void
    {
        $job = JobPosting::findOrFail($id);
        $this->restoreJobId    = $id;
        $this->restoreJobTitle = $job->job_title;
        $this->showRestoreModal = true;
    }

    public function executeRestore(): void
    {
        if ($this->restoreJobId) {
            $job = JobPosting::findOrFail($this->restoreJobId);
            $job->update([
                'status'          => 'ACTIVE',
                'deleted_by'      => null,
                'deleted_by_role' => null,
                'updated_by'      => 'Restored by ' . auth()->user()->name,
                'updated_by_role' => 'admin',
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->restoreJobTitle}' has been restored.");
        }
        $this->showRestoreModal = false;
        $this->restoreJobId    = null;
        $this->restoreJobTitle = '';
        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreJobId    = null;
        $this->restoreJobTitle = '';
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:90vh;">

<style>
    .scrollbar-custom::-webkit-scrollbar        { width:6px; height:6px }
    .scrollbar-custom::-webkit-scrollbar-track  { background:transparent }
    .scrollbar-custom::-webkit-scrollbar-thumb  { background:rgba(122,63,145,.3); border-radius:10px }
    .scrollbar-custom::-webkit-scrollbar-thumb:hover { background:rgba(122,63,145,.6) }
    @keyframes slideInDown  { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:translateY(0)} }
    @keyframes modalSlideIn { from{opacity:0;transform:scale(.96) translateY(12px)} to{opacity:1;transform:scale(1) translateY(0)} }
    @keyframes fadeIn       { from{opacity:0} to{opacity:1} }
    @keyframes spin         { from{transform:rotate(0)} to{transform:rotate(360deg)} }
    .modal-animate    { animation:modalSlideIn .26s cubic-bezier(.16,1,.3,1) }
    .backdrop-animate { animation:fadeIn .18s ease }
    .spin-icon        { animation:spin 1s linear infinite }
    .header-animate   { animation:slideInDown .4s ease-out }
    .btn-primary          { background:linear-gradient(135deg,#7a3f91,#5e2f72); color:#fff; border:none; transition:background .2s,box-shadow .2s }
    .btn-primary:hover:not(:disabled) { background:linear-gradient(135deg,#8b4aa5,#6a3580); box-shadow:0 4px 14px rgba(122,63,145,.35) }
    .btn-primary:disabled { background:linear-gradient(135deg,#cbd5e1,#94a3b8); cursor:not-allowed; box-shadow:none }
    .form-label  { display:block; font-size:.78rem; font-weight:700; color:#374151; margin-bottom:.45rem; letter-spacing:.01em }
    .form-input  { width:100%; padding:.625rem 1rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-size:.875rem; color:#1e293b; background:#fff; transition:border-color .15s,box-shadow .15s }
    .form-input:focus { border-color:#7a3f91!important; box-shadow:0 0 0 3px rgba(122,63,145,.12)!important; outline:none!important }
    .form-input:disabled,.form-input[readonly] { background:#f1f5f9; color:#64748b; cursor:not-allowed }
    .form-error  { font-size:.74rem; color:#ef4444; margin-top:.35rem; display:flex; align-items:center; gap:.3rem }
    .field-error { border-color:#f87171!important; background:#fff8f8!important }
    .field-hint  { font-size:.72rem; color:#94a3b8; margin-top:.3rem }
    .table-row-hover { transition:background-color .1s ease }
    .table-row-hover:hover { background-color:rgba(122,63,145,.04) }
    .table-row-deleted { background-color:#fff7ed!important }
    .table-row-deleted:hover { background-color:#ffedd5!important }
    .tbl-container   { transition:opacity .15s ease }
    .tbl-loading     { opacity:.4; pointer-events:none }
    .tbl-container thead tr th { pointer-events:none }
    .input-focus:focus { border-color:#7a3f91!important; box-shadow:0 0 0 3px rgba(122,63,145,.1)!important; outline:none!important }

    /* ── Org category buttons ── */
    .org-cat-btn{flex:1;padding:13px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;transition:all .18s;text-align:center;font-size:.8rem;font-weight:700;color:#64748b;display:flex;flex-direction:column;align-items:center;gap:6px}
    .org-cat-btn:hover{border-color:#7a3f91;color:#7a3f91;background:#faf5ff}
    .org-cat-btn.active{border-color:#7a3f91;background:linear-gradient(135deg,#7a3f91,#6a3580);color:#fff;box-shadow:0 3px 12px rgba(122,63,145,.3)}
    .org-cat-btn .cat-fa{font-size:1.1rem}
    .org-confirm-box{border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px}
    .org-confirm-box.philcst-box{background:#faf5ff;border:1.5px solid #c4b5fd}
    .org-confirm-box.partner-box{background:#eff6ff;border:1.5px solid #bfdbfe}
    .org-confirm-box.custom-box{background:#f8fafc;border:1.5px solid #e2e8f0}
    .org-confirm-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;flex-shrink:0}
    .org-confirm-name{font-size:.875rem;font-weight:700}
    .org-confirm-sub{font-size:.75rem;margin-top:2px}

    /* ── Table action buttons — OUTLINED ── */
    .tbl-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit;white-space:nowrap}
    .tbl-btn-view{color:#7a3f91;border-color:#7a3f91}
    .tbl-btn-view:hover{background:#faf5ff}
    .tbl-btn-activate{color:#15803d;border-color:#15803d}
    .tbl-btn-activate:hover{background:#f0fdf4}
    .tbl-btn-deactivate{color:#b45309;border-color:#b45309}
    .tbl-btn-deactivate:hover{background:#fffbeb}
    .tbl-btn-restore{color:#ea580c;border-color:#ea580c}
    .tbl-btn-restore:hover{background:#fff7ed}
    .tbl-btn-delete{color:#dc2626;border-color:#dc2626}
    .tbl-btn-delete:hover{background:#fff5f5}

    /* ── View modal footer buttons — SOLID ── */
    .js-modal{background:#fff;border-radius:12px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:760px;max-width:96vw;max-height:92vh;font-family:'Noto Sans','Segoe UI',system-ui,-apple-system,sans-serif;overflow:hidden}
    .js-header{background:#fff;padding:26px 32px 20px;border-bottom:1px solid #ebebeb;flex-shrink:0;position:relative}
    .js-job-title{font-size:23px;font-weight:700;color:#111;line-height:1.25;margin-bottom:6px;padding-right:38px}
    .js-company-line{display:flex;align-items:center;gap:6px;font-size:14px;color:#444;margin-bottom:18px;flex-wrap:wrap}
    .js-company-line strong{color:#111;font-weight:600}
    .js-company-type-pill{background:#f0ebff;color:#6d28d9;font-size:11.5px;font-weight:600;border-radius:3px;padding:2px 8px}
    .js-status-active{background:#dcfce7;color:#15803d;font-size:11.5px;font-weight:700;border-radius:3px;padding:2px 8px}
    .js-status-inactive{background:#fef9c3;color:#a16207;font-size:11.5px;font-weight:700;border-radius:3px;padding:2px 8px}
    .js-status-deleted{background:#ffedd5;color:#c2410c;font-size:11.5px;font-weight:700;border-radius:3px;padding:2px 8px}
    .js-meta-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:9px}
    .js-meta-item{display:flex;align-items:flex-start;gap:11px;font-size:13.5px;color:#222;line-height:1.4}
    .js-meta-icon{width:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;color:#7a3f91;font-size:13px}
    .js-meta-label{color:#222}
    .js-meta-muted{color:#999;font-style:italic}
    .js-posted-line{margin-top:16px;font-size:12.5px;color:#777;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .js-dot{color:#ccc}
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
    .js-update-badge.delete-badge{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
    .js-college-box{background:#faf5ff;border:1px solid #e0d7f5;border-radius:6px;padding:14px 18px}
    .js-college-name{font-size:14px;font-weight:700;color:#111;margin-bottom:8px}
    .js-dept-chips{display:flex;flex-wrap:wrap;gap:6px}
    .js-dept-chip{font-size:11px;font-weight:700;font-family:'Courier New',monospace;background:#fff;border:1px solid #d4c5f0;border-radius:3px;padding:3px 8px;color:#6d28d9}
    .js-footer{padding:14px 32px;border-top:1px solid #ebebeb;display:flex;align-items:center;justify-content:flex-end;background:#fff;flex-shrink:0;gap:8px}
    .js-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit}
    .js-btn-close{color:#374151;border-color:#cbd5e1}
    .js-btn-close:hover{background:#f8fafc}
    .js-btn-edit{color:#2557a7;border-color:#2557a7}
    .js-btn-edit:hover{background:#eff6ff}
    .js-btn-restore{color:#ea580c;border-color:#ea580c}
    .js-btn-restore:hover{background:#fff7ed}
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
    .confirm-btn-restore{color:#ea580c;border-color:#ea580c}
    .confirm-btn-restore:hover{background:#fff7ed}
    .confirm-btn-delete{color:#dc2626;border-color:#dc2626}
    .confirm-btn-delete:hover{background:#fff5f5}

    /* Deleted row banner */
    .deleted-banner{background:#fff7ed;border:1.5px solid #fed7aa;border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px}
</style>

{{-- ── FLASH ────────────────────────────────────────────────── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,4500);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0 scale-100"
     x-transition:leave-end="opacity-0 translate-x-8 scale-95"
     class="fixed top-5 right-6 z-[70] flex items-start gap-3 px-5 py-4 rounded-xl shadow-2xl max-w-sm border backdrop-blur-sm"
     :class="type==='success'?'bg-emerald-50 border-emerald-200 text-emerald-800':'bg-red-50 border-red-200 text-red-800'"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0" :class="type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-bold text-sm" x-text="type==='success'?'Success':'Error'"></div>
        <div class="text-sm mt-0.5 opacity-85 leading-snug" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition ml-1"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0 header-animate">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 btn-primary rounded-xl flex items-center justify-center shadow-md shrink-0">
                    <i class="fas fa-briefcase text-sm"></i>
                </div>
                Admin Job Posts Overview
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-[52px]">Review, moderate, and manage all job postings across the platform.</p>
        </div>
        <button wire:click="openPostModal"
                class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-xl font-bold text-sm hover:shadow-lg transition-all shrink-0">
            <i class="fas fa-plus"></i> Post a Job
        </button>
    </div>

    {{-- ── TABLE PANEL ── --}}
    <div class="flex-1 min-h-0 bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col overflow-hidden">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/80 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',val=>{if(val!==this.q)this.q=val;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.200ms="$wire.set('search',q)"
                       placeholder="Search by job title or company..."
                       class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-100 focus:outline-none transition"
                       autocomplete="off">
            </div>

            <select wire:model.live="filterStatus"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus transition">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="ORGANIZER_DELETED">Deleted by Organizer</option>
            </select>

            <select wire:model.live="filterType"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus transition">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}" @selected($filterType === $opt->label)>{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort"
                    class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="px-4 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-bold flex items-center gap-1.5">
                <i class="fas fa-rotate-left text-xs"></i> Reset
            </button>

            <span wire:loading wire:target="search,filterStatus,filterType,filterSort,resetFilters">
                <i class="fas fa-spinner spin-icon text-purple-400 text-sm"></i>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead style="position:sticky;top:0;z-index:10;">
                    <tr class="btn-primary text-white text-left" style="pointer-events:none;">
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Job Title</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Organization</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Employment Type</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Deadline</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider">Activity</th>
                        <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wider">Status</th>
                        <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->jobPostings as $job)
                    @php
                        $isOrgDeleted = $job->status === 'ORGANIZER_DELETED';
                        $updaterName  = $job->updated_by      ?? null;
                        $updaterRole  = $job->updated_by_role ?? null;
                        $wasEdited    = $updaterName !== null && !$isOrgDeleted;
                    @endphp
                    <tr class="{{ $isOrgDeleted ? 'table-row-deleted' : 'table-row-hover' }}">

                        <td class="px-5 py-3.5">
                            <p class="font-bold text-sm leading-snug {{ $isOrgDeleted ? 'text-slate-400 line-through' : 'text-slate-900' }}">{{ $job->job_title }}</p>
                        </td>

                        <td class="px-5 py-3.5">
                            <p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p>
                        </td>

                        <td class="px-5 py-3.5">
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                {{ $job->employment_type }}
                            </span>
                        </td>

                        <td class="px-5 py-3.5">
                            @php $dl = \Carbon\Carbon::parse($job->deadline); $daysLeft = now()->diffInDays($dl, false); @endphp
                            <span class="text-sm font-semibold text-slate-700">{{ $dl->format('M d, Y') }}</span>
                            <p class="text-xs mt-0.5 {{ $daysLeft < 0 ? 'text-red-400' : 'text-slate-400' }}">
                                {{ $daysLeft < 0 ? 'Expired' : $dl->diffForHumans() }}
                            </p>
                        </td>

                        <td class="px-5 py-3.5">
                            @if($isOrgDeleted)
                                <p class="text-xs font-semibold text-orange-700 leading-snug">
                                    <i class="fas fa-trash text-orange-400 mr-1 text-[10px]"></i>
                                    Deleted by <span class="font-bold">{{ $job->deleted_by ?? 'Organizer' }}</span>
                                </p>
                                <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->updated_at->format('M d, Y') }}</p>
                            @elseif($wasEdited)
                                <p class="text-xs font-semibold text-slate-700 leading-snug">
                                    Updated by <span class="{{ $updaterRole === 'admin' ? 'text-purple-700' : 'text-blue-700' }}">{{ $updaterName }}</span>
                                </p>
                                <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->updated_at->format('M d, Y') }}</p>
                            @else
                                <p class="text-xs font-semibold {{ $job->organizer ? 'text-purple-700' : 'text-slate-500' }} leading-snug">
                                    {{ $job->organizer?->name ?? 'Admin' }}
                                </p>
                                <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->created_at->format('M d, Y') }}</p>
                            @endif
                        </td>

                        <td class="px-5 py-3.5 text-center">
                            @php
                                $sc = match($job->status) {
                                    'ACTIVE'            => 'bg-emerald-100 text-emerald-700',
                                    'INACTIVE'          => 'bg-amber-100 text-amber-700',
                                    'ORGANIZER_DELETED' => 'bg-orange-100 text-orange-700',
                                    default             => 'bg-slate-100 text-slate-600',
                                };
                                $label = match($job->status) {
                                    'ORGANIZER_DELETED' => 'Deleted',
                                    default             => $job->status,
                                };
                            @endphp
                            <span class="inline-block px-3 py-1.5 rounded-full text-xs font-bold {{ $sc }}">{{ $label }}</span>
                        </td>

                        <td class="px-5 py-3.5 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button wire:click="viewJob({{ $job->id }})" class="tbl-btn tbl-btn-view">
                                    <i class="fas fa-eye text-[11px]"></i> View
                                </button>

                                @if($isOrgDeleted)
                                    <button wire:click="confirmRestore({{ $job->id }})" class="tbl-btn tbl-btn-restore">
                                        <i class="fas fa-rotate-left text-[11px]"></i> Restore
                                    </button>
                                @else
                                    @if($job->status === 'ACTIVE')
                                        <button wire:click="confirmToggle({{ $job->id }})" class="tbl-btn tbl-btn-deactivate">
                                            <i class="fas fa-ban text-[11px]"></i> Deactivate
                                        </button>
                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})" class="tbl-btn tbl-btn-activate">
                                            <i class="fas fa-circle-check text-[11px]"></i> Activate
                                        </button>
                                    @endif
                                @endif

                                <button wire:click="confirmDelete({{ $job->id }})" class="tbl-btn tbl-btn-delete">
                                    <i class="fas fa-trash text-[11px]"></i> Delete
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
                                    Try adjusting your filters.
                                @else
                                    No postings yet. Click <strong>Post a Job</strong> to create one.
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
            @php $total=$this->jobPostings->total();$pp=$this->jobPostings->perPage();$cp=$this->jobPostings->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total); @endphp
            <div class="flex items-center justify-between">
                <p class="text-slate-500 text-sm">
                    Showing <span class="font-bold text-slate-800">{{ $from }}&ndash;{{ $to }}</span>
                    of <span class="font-bold text-slate-800">{{ $total }}</span>
                    job{{ $total !== 1 ? 's' : '' }}
                </p>
                <div class="flex gap-2 items-center">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-400 rounded-lg text-sm font-semibold cursor-not-allowed">&larr; Previous</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-bold">&larr; Previous</button>
                    @endif
                    <span class="px-4 py-2 text-slate-600 text-sm font-semibold">Page {{ $this->jobPostings->currentPage() }} of {{ $this->jobPostings->lastPage() }}</span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-bold">Next &rarr;</button>
                    @else
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-400 rounded-lg text-sm font-semibold cursor-not-allowed">Next &rarr;</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════════════════
     MODAL: Post a Job (Admin)
════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-xl shadow-2xl modal-animate w-full flex flex-col" style="max-width:800px;max-height:92vh;">

        <div class="flex items-center justify-between px-7 py-5 bg-[#7a3f91] border-b border-slate-100 shrink-0">
            <div class="flex items-center gap-3">
                <div><i class="fa-solid fa-briefcase text-white text-2xl"></i></div>
                <h2 class="text-lg font-bold text-white">Post a New Job</h2>
            </div>
            <button wire:click="closePostModal" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-100 hover:bg-white/20 transition text-xl leading-none">&times;</button>
        </div>

        @if(count($postErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($postErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-7 py-6 space-y-5">

            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-input {{ isset($postErrors['postJobTitle']) ? 'field-error' : '' }}">
                @if(isset($postErrors['postJobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
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
                            <button type="button" wire:click="$set('postOrgCategory','philcst')" class="org-cat-btn {{ $postOrgCategory === 'philcst' ? 'active' : '' }}">
                                <i class="fas fa-school cat-fa"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')" class="org-cat-btn {{ $postOrgCategory === 'partner' ? 'active' : '' }}">
                                <i class="fas fa-handshake cat-fa"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')" class="org-cat-btn {{ $postOrgCategory === 'custom' ? 'active' : '' }}">
                                <i class="fas fa-pen-to-square cat-fa"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="form-error mt-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>

                    @if($postOrgCategory === 'philcst')
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
                    @elseif($postOrgCategory === 'partner')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postPartnerName" type="text" placeholder="e.g. Acme Corporation" class="form-input {{ isset($postErrors['postPartnerName']) ? 'field-error' : '' }}">
                                @if(isset($postErrors['postPartnerName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postPartnerType" type="text" placeholder="e.g. Private Company, NGO" class="form-input {{ isset($postErrors['postPartnerType']) ? 'field-error' : '' }}">
                                @if(isset($postErrors['postPartnerType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="postLocation" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote" maxlength="120" class="form-input {{ isset($postErrors['postLocation']) ? 'field-error' : '' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postPartnerName))
                        <div class="org-confirm-box partner-box">
                            <div class="org-confirm-icon" style="background:#2557a7;"><i class="fas fa-handshake"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e3a5f;">{{ $postPartnerName }}</div>
                                @if(trim($postPartnerType))<div class="org-confirm-sub" style="color:#2557a7;">{{ $postPartnerType }}</div>@endif
                                @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555;"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @elseif($postOrgCategory === 'custom')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postCustomName" type="text" placeholder="e.g. Department of Labor" class="form-input {{ isset($postErrors['postCustomName']) ? 'field-error' : '' }}">
                                @if(isset($postErrors['postCustomName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postCustomType" type="text" placeholder="e.g. Government Agency, NGO" class="form-input {{ isset($postErrors['postCustomType']) ? 'field-error' : '' }}">
                                @if(isset($postErrors['postCustomType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="postLocation" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120" class="form-input {{ isset($postErrors['postLocation']) ? 'field-error' : '' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postCustomName))
                        <div class="org-confirm-box custom-box">
                            <div class="org-confirm-icon" style="background:#475569;"><i class="fas fa-pen-to-square"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e293b;">{{ $postCustomName }}</div>
                                @if(trim($postCustomType))<div class="org-confirm-sub" style="color:#475569;">{{ $postCustomType }}</div>@endif
                                @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555;"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @else
                    <div class="text-center py-5 text-slate-400 text-sm"><i class="fas fa-arrow-up text-slate-300 text-xl block mb-2"></i>Select a category above to continue.</div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postEmpType" class="form-input {{ isset($postErrors['postEmpType']) ? 'field-error' : '' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)<option value="{{ $opt->label }}">{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($postErrors['postEmpType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postExpLevel" class="form-input {{ isset($postErrors['postExpLevel']) ? 'field-error' : '' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)<option value="{{ $opt->label }}">{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($postErrors['postExpLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-input">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postDeadline" type="date" class="form-input {{ isset($postErrors['postDeadline']) ? 'field-error' : '' }}">
                    @if(isset($postErrors['postDeadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Target College <span class="text-slate-400 font-normal text-xs">(Optional — blank = visible to all)</span></label>
                <select wire:model.live="postTargetCollege" class="form-input">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $postSelectedDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->postTargetCollege)['codes'] ?? []; @endphp
                @if(count($postSelectedDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($postSelectedDepts as $dCode)<span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>@endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="postDescription" rows="6" placeholder="Describe the role, responsibilities, qualifications..." class="form-input resize-none {{ isset($postErrors['postDescription']) ? 'field-error' : '' }}"></textarea>
                @if(isset($postErrors['postDescription']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDescription'] }}</p>@endif
            </div>

        </div>

        <div class="px-7 py-5 border-t border-slate-200 bg-slate-50 rounded-b-xl shrink-0 flex gap-3">
            <button wire:click="closePostModal" class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
            <button wire:click="savePost" wire:loading.attr="disabled" wire:target="savePost" class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                <span wire:loading wire:target="savePost"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane mr-1.5"></i> Post Job</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: View Full Job Details
════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job         = $this->viewingJob;
    $isOrgDel    = $job->status === 'ORGANIZER_DELETED';
    $updaterName = $job->updated_by ?? null;
    $updaterRole = $job->updated_by_role ?? null;
    $wasEdited   = $updaterName !== null && !$isOrgDel;
    $dl          = \Carbon\Carbon::parse($job->deadline);
    $daysLeft    = now()->diffInDays($dl, false);
    $isExpired   = $daysLeft < 0;
    $orgDept2    = $job->organizer?->department ?? '';
    $hasCollege2 = $orgDept2 !== '' && \App\Models\Course::where('college', $orgDept2)->exists();
    $postedByCollege = $orgDept2 !== ''
        ? ($hasCollege2 ? $orgDept2 : (\App\Models\Course::where('code', $orgDept2)->value('college') ?? $orgDept2))
        : null;
    $viewDepts = $job->target_college
        ? \App\Models\Course::where('college', $job->target_college)->orderBy('code')->pluck('code')->toArray()
        : [];
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="js-modal modal-animate relative">
        <button wire:click="closeViewModal" class="js-close-x" title="Close">&times;</button>

        <div class="js-header">
            @if($isOrgDel)
            <div class="deleted-banner">
                <i class="fas fa-trash text-orange-500"></i>
                <div>
                    <p class="text-sm font-bold text-orange-800">Deleted by Organizer</p>
                    <p class="text-xs text-orange-600 mt-0.5">
                        Deleted by <strong>{{ $job->deleted_by ?? 'Organizer' }}</strong> · {{ $job->updated_at->format('M d, Y · g:i A') }}
                    </p>
                </div>
            </div>
            @endif

            <div class="js-job-title {{ $isOrgDel ? 'line-through text-slate-400' : '' }}">{{ $job->job_title }}</div>
            <div class="js-company-line">
                <strong>{{ $job->company_name }}</strong>
                <span class="js-company-type-pill">{{ $job->company_type }}</span>
                @if($isOrgDel)
                    <span class="js-status-deleted">● Deleted</span>
                @elseif($job->status === 'ACTIVE')
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
            <div class="js-posted-line">
                Posted {{ $job->created_at->diffForHumans() }}
                @if($job->organizer)
                    <span class="js-dot">·</span> by {{ $job->organizer->name }}
                    @if($postedByCollege)<span class="js-dot">·</span> {{ $postedByCollege }}@endif
                @else
                    <span class="js-dot">·</span> by Admin
                @endif
            </div>
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
                    <div class="js-admin-cell"><div class="js-admin-label">Posted By</div><div class="js-admin-value">{{ $job->organizer?->name ?? 'Admin' }}</div>@if($postedByCollege)<div class="js-admin-sub">{{ $postedByCollege }}</div>@endif</div>
                    <div class="js-admin-cell"><div class="js-admin-label">Deadline</div><div class="js-admin-value">{{ $dl->format('M d, Y') }}</div><div class="js-admin-sub" style="{{ $isExpired ? 'color:#c0392b;' : '' }}">{{ $isExpired ? 'Expired' : $dl->diffForHumans() }}</div></div>
                    <div class="js-admin-cell-full">
                        <div class="js-admin-label">Activity</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div>
                                <div class="js-admin-value">{{ $job->updated_at->format('M d, Y · g:i A') }}</div>
                                <div class="js-admin-sub">{{ $job->updated_at->diffForHumans() }}</div>
                            </div>
                            @if($isOrgDel && $job->deleted_by)
                                <div class="js-update-badge delete-badge">
                                    <i class="fas fa-trash" style="font-size:9px;"></i>
                                    Deleted by {{ $job->deleted_by }}
                                </div>
                            @elseif($wasEdited)
                                <div class="js-update-badge {{ $updaterRole === 'admin' ? 'admin-badge' : '' }}">
                                    <i class="fas fa-{{ $updaterRole === 'admin' ? 'shield-halved' : 'user' }}" style="font-size:9px;"></i>
                                    {{ $updaterName }}
                                    <span style="opacity:.55;font-weight:400;">· {{ $updaterRole === 'admin' ? 'Admin' : 'Organizer' }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="js-footer">
            <button wire:click="closeViewModal" class="js-btn js-btn-close"><i class="fas fa-xmark" style="font-size:12px;"></i> Close</button>
            @if($isOrgDel)
                <button wire:click="confirmRestore({{ $job->id }})" class="js-btn js-btn-restore">
                    <i class="fas fa-rotate-left" style="font-size:12px;"></i> Restore Job
                </button>
            @else
                <button wire:click="openEditModal({{ $job->id }})" class="js-btn js-btn-edit">
                    <i class="fas fa-pen-to-square" style="font-size:12px;"></i> Edit Posting
                </button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Edit Job Posting
════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-2xl shadow-2xl modal-animate w-full flex flex-col" style="max-width:800px;max-height:92vh;">

        <div class="flex items-center justify-between px-8 py-5 bg-white border-b border-slate-100 shrink-0">
            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-9 h-9 btn-primary rounded-lg flex items-center justify-center shrink-0"><i class="fas fa-pen-to-square text-sm"></i></div>
                Edit Job Posting
            </h2>
            <button wire:click="closeEditModal" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition text-xl leading-none">&times;</button>
        </div>

        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-8 py-4 shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($editErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5 shrink-0">&bull;</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">

            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer" class="form-input {{ isset($editErrors['editJobTitle']) ? 'field-error' : '' }}">
                @if(isset($editErrors['editJobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                    <select wire:model.live="editCompanyType" class="form-input {{ isset($editErrors['editCompanyType']) ? 'field-error' : '' }}">
                        <option value="">Select Organization</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Organization Type / Company Name <span class="text-red-500">*</span></label>
                    @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                    <input wire:model.defer="editCompany" type="text" placeholder="e.g. Acme Corp"
                           @if($editIsPhilcst) readonly @endif
                           class="form-input {{ isset($editErrors['editCompany']) ? 'field-error' : '' }} {{ $editIsPhilcst ? 'bg-slate-100 text-slate-500 cursor-not-allowed' : '' }}">
                    @if($editIsPhilcst)<p class="field-hint"><i class="fas fa-lock text-[10px] mr-1"></i>Auto-set for PHILCST.</p>@endif
                    @if(isset($editErrors['editCompany']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Location <span class="text-red-500">*</span></label>
                <input wire:model="editLocation" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote"
                       @if($editIsPhilcst) readonly @endif
                       class="form-input {{ isset($editErrors['editLocation']) ? 'field-error' : '' }} {{ $editIsPhilcst ? 'bg-slate-100 text-slate-500 cursor-not-allowed' : '' }}">
                @if(isset($editErrors['editLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editEmpType" class="form-input {{ isset($editErrors['editEmpType']) ? 'field-error' : '' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)<option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editExpLevel" class="form-input {{ isset($editErrors['editExpLevel']) ? 'field-error' : '' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->jobOptions->get('experience_level', collect()) as $opt)<option value="{{ $opt->label }}" @selected($editExpLevel === $opt->label)>{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editExpLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="editSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-input">
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editDeadline" type="date" class="form-input {{ isset($editErrors['editDeadline']) ? 'field-error' : '' }}">
                    @if(isset($editErrors['editDeadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Target College <span class="text-slate-400 font-normal text-xs">(Optional)</span></label>
                <select wire:model.live="editTargetCollege" class="form-input">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $editSelectedDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->editTargetCollege)['codes'] ?? []; @endphp
                @if(count($editSelectedDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($editSelectedDepts as $dCode)<span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>@endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="editDescription" rows="7" placeholder="Describe the role, responsibilities, and qualifications..." class="form-input resize-none {{ isset($editErrors['editDescription']) ? 'field-error' : '' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>

        </div>

        <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 rounded-b-2xl shrink-0 flex gap-3">
            <button wire:click="closeEditModal" class="flex-1 px-6 py-3 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-100 transition"><i class="fas fa-xmark mr-1.5"></i> Cancel</button>
            <button wire:click="saveEditJob" wire:loading.attr="disabled" wire:target="saveEditJob" class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 shadow-md">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk mr-1"></i> Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Restore
════════════════════════════════════════════════════════════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:420px;max-width:95vw;">
        <div class="px-7 py-6 bg-orange-50 border-b border-orange-200 flex items-center gap-3 rounded-t-2xl">
            <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fas fa-rotate-left text-orange-500 text-lg"></i>
            </div>
            <h2 class="text-lg font-extrabold text-orange-800">Restore Job Posting</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to restore:</p>
            <p class="font-extrabold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-5">
                <p class="text-blue-800 text-xs font-semibold flex items-start gap-2">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                    <span>The job will be set back to <strong>ACTIVE</strong> and become visible to students again. The organizer will see it in their list.</span>
                </p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="confirm-btn confirm-btn-cancel">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore" class="confirm-btn confirm-btn-restore">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner spin-icon"></i> Restoring...</span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left"></i> Yes, Restore</span>
                </button>
            </div>
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
        <div class="px-7 py-6 bg-red-50 border-b border-red-200 flex items-center gap-3 rounded-t-2xl">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0"><i class="fas fa-triangle-exclamation text-red-500 text-lg"></i></div>
            <h2 class="text-lg font-extrabold text-red-800">Permanently Delete</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to permanently delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-3">"{{ $deleteJobTitle }}"</p>
            <p class="text-xs mb-6 bg-red-50 rounded-lg px-3 py-2 border border-red-100 text-slate-500">
                <i class="fas fa-exclamation-circle text-red-400 mr-1.5"></i>This action <strong>cannot be undone</strong>.
            </p>
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

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Toggle Status
════════════════════════════════════════════════════════════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:400px;max-width:95vw;">
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
                @if($confirmAction === 'ACTIVE')
                    This job will be marked as <span class="font-bold text-green-600">ACTIVE</span> and visible to students.
                @else
                    This job will be marked as <span class="font-bold text-amber-600">INACTIVE</span> and hidden from students. It can still be edited.
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