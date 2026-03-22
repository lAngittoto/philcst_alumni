<?php
/**
 * FILE: resources/views/livewire/admin/jobs.blade.php
 * Design matches user-management.blade.php exactly.
 * Experience levels ordered: No Experience → Entry → Mid → Senior → Expert
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

    // ── Experience level canonical order ──────────────────────
    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

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

        if ($this->filterStatus !== '') $q->where('status', $this->filterStatus);
        if ($this->filterType   !== '') $q->where('employment_type', $this->filterType);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');

        return $q->paginate(20);
    }

    // ── Computed: Job Options ─────────────────────────────────
    #[Computed]
    public function jobOptions()
    {
        return JobOption::orderBy('type')->orderBy('label')->get()->groupBy('type');
    }

    // ── Computed: Experience levels in correct order ──────────
    #[Computed]
    public function orderedExpLevels(): array
    {
        $fromDb  = $this->jobOptions->get('experience_level', collect())->pluck('label')->toArray();
        $ordered = [];
        foreach ($this->expLevelOrder as $lvl) {
            if (in_array($lvl, $fromDb, true)) $ordered[] = $lvl;
        }
        // Append any DB levels not in our predefined order (future-proof)
        foreach ($fromDb as $lvl) {
            if (!in_array($lvl, $ordered, true)) $ordered[] = $lvl;
        }
        return $ordered;
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
        $this->deleteJobId     = $id;
        $this->deleteJobTitle  = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteJobId) {
            app(JobController::class)->deleteJob($this->deleteJobId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been permanently deleted.");
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

    // ── Restore ───────────────────────────────────────────────
    public function confirmRestore(int $id): void
    {
        $job = JobPosting::findOrFail($id);
        $this->restoreJobId     = $id;
        $this->restoreJobTitle  = $job->job_title;
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
        $this->restoreJobId     = null;
        $this->restoreJobTitle  = '';
        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreJobId     = null;
        $this->restoreJobTitle  = '';
    }
};
?>

{{-- ═══════════════════════════════════════════════════════════════════════
     TEMPLATE — inherits user-management.css for btn-primary, scrollbar,
     modal-animate, spin-icon, input-focus, tbl-container, tbl-loading,
     table-row-hover.
     ═══════════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-50 overflow-hidden" style="height:90vh">



<style>
    /* ── Org category selector buttons ── */
    .org-cat-btn{flex:1;padding:13px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;transition:all .18s;text-align:center;font-size:.8rem;font-weight:700;color:#64748b;display:flex;flex-direction:column;align-items:center;gap:6px}
    .org-cat-btn:hover{border-color:#7a3f91;color:#7a3f91;background:#faf5ff}
    .org-cat-btn.active{border-color:#7a3f91;background:linear-gradient(135deg,#7a3f91,#6a3580);color:#fff;box-shadow:0 3px 12px rgba(122,63,145,.3)}
    .org-cat-btn .cat-fa{font-size:1.1rem}

    /* ── Org confirm preview boxes ── */
    .org-confirm-box{border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px}
    .org-confirm-box.philcst-box{background:#faf5ff;border:1.5px solid #c4b5fd}
    .org-confirm-box.partner-box{background:#eff6ff;border:1.5px solid #bfdbfe}
    .org-confirm-box.custom-box{background:#f8fafc;border:1.5px solid #e2e8f0}
    .org-confirm-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;flex-shrink:0}
    .org-confirm-name{font-size:.875rem;font-weight:700}
    .org-confirm-sub{font-size:.75rem;margin-top:2px}

    /* ── Form fields (same as user-management modals) ── */
    .form-label{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:.45rem;letter-spacing:.01em}
    .form-input{width:100%;padding:.625rem 1rem;border:1.5px solid #e2e8f0;border-radius:.5rem;font-size:.875rem;color:#1e293b;background:#fff;transition:border-color .15s,box-shadow .15s}
    .form-input:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.12)!important;outline:none!important}
    .form-input:disabled,.form-input[readonly]{background:#f1f5f9;color:#64748b;cursor:not-allowed}
    .form-error{font-size:.74rem;color:#ef4444;margin-top:.35rem;display:flex;align-items:center;gap:.3rem}
    .field-error{border-color:#f87171!important;background:#fff8f8!important}
    .field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}

    /* ── Table row deleted state ── */
    .table-row-deleted{background-color:#fff7ed!important}
    .table-row-deleted:hover{background-color:#ffedd5!important}

    /* ── View Job modal (js- classes kept for specificity) ── */
    .js-modal{background:#fff;border-radius:10px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:760px;max-width:96vw;max-height:92vh;overflow:hidden}
    .js-header{background:#fff;padding:26px 32px 20px;border-bottom:1px solid #ebebeb;flex-shrink:0;position:relative}
    .js-job-title{font-size:22px;font-weight:700;color:#111;line-height:1.25;margin-bottom:6px;padding-right:36px}
    .js-company-line{display:flex;align-items:center;gap:6px;font-size:13.5px;color:#444;margin-bottom:16px;flex-wrap:wrap}
    .js-company-line strong{color:#111;font-weight:600}
    .js-pill{font-size:11px;font-weight:600;border-radius:3px;padding:2px 8px}
    .js-pill-type{background:#f0ebff;color:#6d28d9}
    .js-pill-active{background:#dcfce7;color:#15803d}
    .js-pill-inactive{background:#fef9c3;color:#a16207}
    .js-pill-deleted{background:#ffedd5;color:#c2410c}
    .js-meta-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:9px}
    .js-meta-item{display:flex;align-items:flex-start;gap:11px;font-size:13.5px;color:#222;line-height:1.4}
    .js-meta-icon{width:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;color:#7a3f91;font-size:13px}
    .js-meta-muted{color:#999;font-style:italic}
    .js-posted-line{margin-top:14px;font-size:12px;color:#777;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .js-dot{color:#ccc}
    .js-body{flex:1;min-height:0;overflow-y:auto;background:#fff}
    .js-section{padding:22px 32px;border-bottom:1px solid #f0f0f0}
    .js-section:last-child{border-bottom:none}
    .js-section-title{font-size:15px;font-weight:700;color:#111;margin-bottom:12px}
    .js-description{font-size:13.5px;color:#222;line-height:1.85;white-space:pre-wrap}
    .js-admin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden}
    .js-admin-cell{padding:13px 16px;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8}
    .js-admin-cell:nth-child(3n){border-right:none}
    .js-admin-cell-full{grid-column:span 3;padding:13px 16px;border-bottom:none}
    .js-admin-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px}
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
    .js-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff}
    .js-btn-close{color:#374151;border-color:#cbd5e1}
    .js-btn-close:hover{background:#f8fafc}
    .js-btn-edit{color:#2557a7;border-color:#2557a7}
    .js-btn-edit:hover{background:#eff6ff}
    .js-btn-restore{color:#ea580c;border-color:#ea580c}
    .js-btn-restore:hover{background:#fff7ed}
    .js-close-x{position:absolute;top:16px;right:18px;width:28px;height:28px;border-radius:50%;border:none;background:transparent;color:#999;font-size:19px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s;line-height:1}
    .js-close-x:hover{background:#f0f0f0;color:#333}

    /* ── Deleted org banner inside view modal ── */
    .deleted-banner{background:#fff7ed;border:1.5px solid #fed7aa;border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:14px}
</style>

{{-- ── FLASH TOAST (same as user-management) ───────────────────────────── --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,10000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm"
     :class="{
         'bg-emerald-50 border-emerald-200 text-emerald-800': type==='success',
         'bg-blue-50 border-blue-200 text-blue-800': type==='info',
         'bg-red-50 border-red-200 text-red-800': type==='error'
     }"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0"
       :class="{
           'fa-check-circle text-emerald-500': type==='success',
           'fa-info-circle text-blue-500': type==='info',
           'fa-exclamation-circle text-red-500': type==='error'
       }"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></div>
        <div class="text-sm mt-0.5 leading-snug opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 shrink-0 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- ── HEADER ── --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
        <div>
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                    <i class="fas fa-briefcase text-xl"></i>
                </div>
                Job Posts Overview
            </h1>
            <p class="text-slate-600 text-sm mt-2 ml-0.5">Review, moderate, and manage all job postings across the platform.</p>
        </div>
        <button wire:click="openPostModal"
                class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all shrink-0">
            <i class="fas fa-plus"></i> Post a Job
        </button>
    </div>

    {{-- ── TABLE PANEL ── --}}
    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.search??''; $wire.$watch('search',val=>{ if(val!==this.q)this.q=val; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.200ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
                       autocomplete="off">
            </div>

            <select wire:model.live="filterStatus" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="ORGANIZER_DELETED">Deleted by Organizer</option>
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
        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
                 wire:loading.class="tbl-loading"
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeToggle,executeDelete,executeRestore">
                <table class="w-full border-separate border-spacing-0">
                    <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Job Title</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Organization</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Employment Type</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Deadline</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Activity</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($this->jobPostings as $job)
                        @php
                            $isOrgDeleted = $job->status === 'ORGANIZER_DELETED';
                            $updaterName  = $job->updated_by      ?? null;
                            $updaterRole  = $job->updated_by_role ?? null;
                            $wasEdited    = $updaterName !== null && !$isOrgDeleted;
                            $dl           = \Carbon\Carbon::parse($job->deadline);
                            $daysLeft     = now()->diffInDays($dl, false);
                        @endphp
                        <tr class="{{ $isOrgDeleted ? 'table-row-deleted' : 'table-row-hover' }}">

                            <td class="px-6 py-4">
                                <p class="font-semibold text-sm leading-snug {{ $isOrgDeleted ? 'text-slate-400 line-through' : 'text-slate-900' }}">
                                    {{ $job->job_title }}
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ $job->experience_level }}</p>
                            </td>

                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-700 text-sm">{{ $job->company_name }}</p>
                                @if($job->location)
                                    <p class="text-xs text-slate-400 mt-0.5"><i class="fas fa-location-dot mr-1"></i>{{ $job->location }}</p>
                                @endif
                            </td>

                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-6 py-4">
                                <span class="text-sm font-semibold text-slate-700">{{ $dl->format('M d, Y') }}</span>
                                <p class="text-xs mt-0.5 {{ $daysLeft < 0 ? 'text-red-400' : 'text-slate-400' }}">
                                    {{ $daysLeft < 0 ? 'Expired' : $dl->diffForHumans() }}
                                </p>
                            </td>

                            <td class="px-6 py-4">
                                @if($isOrgDeleted)
                                    <p class="text-xs font-semibold text-orange-700 leading-snug">
                                        <i class="fas fa-trash text-orange-400 mr-1 text-[10px]"></i>
                                        Deleted by <span class="font-bold">{{ $job->deleted_by ?? 'Organizer' }}</span>
                                    </p>
                                    <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->updated_at->format('M d, Y') }}</p>
                                @elseif($wasEdited)
                                    <p class="text-xs font-semibold text-slate-700 leading-snug">
                                        Updated by
                                        <span class="{{ $updaterRole === 'admin' ? 'text-purple-700' : 'text-blue-700' }}">{{ $updaterName }}</span>
                                    </p>
                                    <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->updated_at->format('M d, Y') }}</p>
                                @else
                                    <p class="text-xs font-semibold {{ $job->organizer ? 'text-purple-700' : 'text-slate-500' }} leading-snug">
                                        {{ $job->organizer?->name ?? 'Admin' }}
                                    </p>
                                    <p class="text-[10px] text-slate-400 mt-0.5">{{ $job->created_at->format('M d, Y') }}</p>
                                @endif
                            </td>

                            <td class="px-6 py-4 text-center">
                                @php
                                    $sc = match($job->status) {
                                        'ACTIVE'            => 'bg-emerald-100 text-emerald-700',
                                        'INACTIVE'          => 'bg-amber-100 text-amber-700',
                                        'ORGANIZER_DELETED' => 'bg-orange-100 text-orange-700',
                                        default             => 'bg-slate-100 text-slate-600',
                                    };
                                    $lbl = $job->status === 'ORGANIZER_DELETED' ? 'Deleted' : $job->status;
                                @endphp
                                <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $lbl }}</span>
                            </td>

                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                        <i class="fas fa-eye"></i> View
                                    </button>

                                    @if($isOrgDeleted)
                                        <button wire:click="confirmRestore({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-orange-600 hover:bg-orange-50 rounded-lg transition border border-orange-200">
                                            <i class="fas fa-rotate-left"></i> Restore
                                        </button>
                                    @elseif($job->status === 'ACTIVE')
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-600 hover:bg-amber-50 rounded-lg transition border border-amber-200">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                            <i class="fas fa-circle-check"></i> Activate
                                        </button>
                                    @endif

                                    <button wire:click="confirmDelete({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
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
        </div>

        {{-- Pagination ── same markup pattern as user-management --}}
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex items-center justify-between">
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
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $cp }} / {{ $this->jobPostings->lastPage() }}</span>
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
     MODAL: Post a Job
════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closePostModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate flex flex-col">

        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-briefcase text-2xl"></i> Post a New Job</h2>
            <button wire:click="closePostModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if(count($postErrors))
        <div class="bg-red-50 border-b border-red-200 px-8 py-5 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-3"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-2">
                @foreach($postErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-500 mt-0.5">•</span><span>{{ $err }}</span></li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 px-8 py-6 space-y-6 overflow-y-auto scrollbar-custom">

            {{-- Job Title --}}
            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-input {{ isset($postErrors['postJobTitle']) ? 'field-error' : '' }}">
                @if(isset($postErrors['postJobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
            </div>

            {{-- Organization --}}
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-building text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Organization Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="form-label">Select Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')" class="org-cat-btn {{ $postOrgCategory==='philcst'?'active':'' }}">
                                <i class="fas fa-school cat-fa"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')" class="org-cat-btn {{ $postOrgCategory==='partner'?'active':'' }}">
                                <i class="fas fa-handshake cat-fa"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')" class="org-cat-btn {{ $postOrgCategory==='custom'?'active':'' }}">
                                <i class="fas fa-pen-to-square cat-fa"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="form-error mt-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>

                    @if($postOrgCategory==='philcst')
                        @if($philcstName)
                        <div class="org-confirm-box philcst-box">
                            <div class="org-confirm-icon" style="background:linear-gradient(135deg,#7a3f91,#6a3580)"><i class="fas fa-school"></i></div>
                            <div class="flex-1">
                                <div class="org-confirm-name" style="color:#4c1d95">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="org-confirm-sub" style="color:#7c3aed"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-purple-600 bg-white border border-purple-200 px-3 py-1.5 rounded-full shrink-0">
                                <i class="fas fa-lock text-[10px]"></i> Auto-filled
                            </span>
                        </div>
                        @else
                        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-700"><i class="fas fa-triangle-exclamation mr-2"></i>No PHILCST campus found.</div>
                        @endif

                    @elseif($postOrgCategory==='partner')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postPartnerName" type="text" placeholder="e.g. Acme Corporation"
                                       class="form-input {{ isset($postErrors['postPartnerName'])?'field-error':'' }}">
                                @if(isset($postErrors['postPartnerName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postPartnerType" type="text" placeholder="e.g. Private Company, NGO"
                                       class="form-input {{ isset($postErrors['postPartnerType'])?'field-error':'' }}">
                                @if(isset($postErrors['postPartnerType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="postLocation" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote" maxlength="120"
                                   class="form-input {{ isset($postErrors['postLocation'])?'field-error':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postPartnerName))
                        <div class="org-confirm-box partner-box">
                            <div class="org-confirm-icon" style="background:#2557a7"><i class="fas fa-handshake"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e3a5f">{{ $postPartnerName }}</div>
                                @if(trim($postPartnerType))<div class="org-confirm-sub" style="color:#2557a7">{{ $postPartnerType }}</div>@endif
                                @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
                            </div>
                        </div>
                        @endif

                    @elseif($postOrgCategory==='custom')
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Organization Name <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postCustomName" type="text" placeholder="e.g. Department of Labor"
                                       class="form-input {{ isset($postErrors['postCustomName'])?'field-error':'' }}">
                                @if(isset($postErrors['postCustomName']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                                <input wire:model.live.debounce.300ms="postCustomType" type="text" placeholder="e.g. Government Agency, NGO"
                                       class="form-input {{ isset($postErrors['postCustomType'])?'field-error':'' }}">
                                @if(isset($postErrors['postCustomType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="postLocation" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="form-input {{ isset($postErrors['postLocation'])?'field-error':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postCustomName))
                        <div class="org-confirm-box custom-box">
                            <div class="org-confirm-icon" style="background:#475569"><i class="fas fa-pen-to-square"></i></div>
                            <div>
                                <div class="org-confirm-name" style="color:#1e293b">{{ $postCustomName }}</div>
                                @if(trim($postCustomType))<div class="org-confirm-sub" style="color:#475569">{{ $postCustomType }}</div>@endif
                                @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
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

            {{-- Employment Type & Experience Level --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postEmpType" class="form-input {{ isset($postErrors['postEmpType'])?'field-error':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postEmpType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postExpLevel" class="form-input {{ isset($postErrors['postExpLevel'])?'field-error':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}">{{ $lvl }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postExpLevel']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                </div>
            </div>

            {{-- Salary & Deadline --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Salary <span class="text-slate-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-input">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postDeadline" type="date" class="form-input {{ isset($postErrors['postDeadline'])?'field-error':'' }}">
                    @if(isset($postErrors['postDeadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                </div>
            </div>

            {{-- Target College --}}
            <div>
                <label class="form-label">Target College <span class="text-slate-400 font-normal text-xs">(Optional — blank = visible to all)</span></label>
                <select wire:model.live="postTargetCollege" class="form-input">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $postDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->postTargetCollege)['codes'] ?? []; @endphp
                @if(count($postDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($postDepts as $dCode)
                        <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Description --}}
            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="postDescription" rows="6" placeholder="Describe the role, responsibilities, qualifications…"
                          class="form-input resize-none {{ isset($postErrors['postDescription'])?'field-error':'' }}"></textarea>
                @if(isset($postErrors['postDescription']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDescription'] }}</p>@endif
            </div>
        </div>

        <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 shrink-0 flex gap-4">
            <button type="button" wire:click="closePostModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
            <button type="button" wire:click="savePost" wire:loading.attr="disabled" wire:target="savePost"
                    class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                <span wire:loading wire:target="savePost"><i class="fas fa-spinner spin-icon"></i> Saving…</span>
                <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane"></i> Post Job</span>
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
    $job             = $this->viewingJob;
    $isOrgDel        = $job->status === 'ORGANIZER_DELETED';
    $updaterName     = $job->updated_by ?? null;
    $updaterRole     = $job->updated_by_role ?? null;
    $wasEdited       = $updaterName !== null && !$isOrgDel;
    $dl              = \Carbon\Carbon::parse($job->deadline);
    $daysLeft        = now()->diffInDays($dl, false);
    $isExpired       = $daysLeft < 0;
    $orgDept2        = $job->organizer?->department ?? '';
    $hasCollege2     = $orgDept2 !== '' && \App\Models\Course::where('college', $orgDept2)->exists();
    $postedByCollege = $orgDept2 !== ''
        ? ($hasCollege2 ? $orgDept2 : (\App\Models\Course::where('code', $orgDept2)->value('college') ?? $orgDept2))
        : null;
    $viewDepts = $job->target_college
        ? \App\Models\Course::where('college', $job->target_college)->orderBy('code')->pluck('code')->toArray()
        : [];
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeViewModal()">
    <div class="js-modal modal-animate relative">
        <button wire:click="closeViewModal" class="js-close-x">&times;</button>

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
                <span class="js-pill js-pill-type">{{ $job->company_type }}</span>
                @if($isOrgDel)
                    <span class="js-pill js-pill-deleted">● Deleted</span>
                @elseif($job->status === 'ACTIVE')
                    <span class="js-pill js-pill-active">● Active</span>
                @else
                    <span class="js-pill js-pill-inactive">● Inactive</span>
                @endif
            </div>
            <ul class="js-meta-list">
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-location-dot"></i></span><span>{{ $job->location ?? 'Location not specified' }}</span></li>
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-clock"></i></span><span>{{ $job->employment_type }}</span></li>
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-layer-group"></i></span><span>{{ $job->experience_level }}</span></li>
                <li class="js-meta-item">
                    <span class="js-meta-icon"><i class="fas fa-money-bill-wave"></i></span>
                    @if($job->salary)<span>{{ $job->salary }}</span>@else<span class="js-meta-muted">Salary not disclosed</span>@endif
                </li>
                <li class="js-meta-item">
                    <span class="js-meta-icon"><i class="fas fa-calendar-xmark"></i></span>
                    <span>Deadline: {{ $dl->format('F d, Y') }}
                        @if($isExpired)<span style="color:#c0392b;font-weight:700;margin-left:6px;">(Expired)</span>
                        @else<span style="color:#666;margin-left:6px;">· {{ $dl->diffForHumans() }}</span>@endif
                    </span>
                </li>
                @if($job->target_college)
                <li class="js-meta-item"><span class="js-meta-icon"><i class="fas fa-building-columns"></i></span><span>For: {{ $job->target_college }}</span></li>
                @endif
            </ul>
            <div class="js-posted-line">
                Posted {{ $job->created_at->diffForHumans() }}
                <span class="js-dot">·</span>
                @if($job->organizer)
                    by {{ $job->organizer->name }}
                    @if($postedByCollege)<span class="js-dot">·</span> {{ $postedByCollege }}@endif
                @else
                    by Admin
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
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:12px;">Posting Details</div>
                <div class="js-admin-grid">
                    <div class="js-admin-cell">
                        <div class="js-admin-label">Posted On</div>
                        <div class="js-admin-value">{{ $job->created_at->format('M d, Y') }}</div>
                        <div class="js-admin-sub">{{ $job->created_at->format('g:i A') }}</div>
                    </div>
                    <div class="js-admin-cell">
                        <div class="js-admin-label">Posted By</div>
                        <div class="js-admin-value">{{ $job->organizer?->name ?? 'Admin' }}</div>
                        @if($postedByCollege)<div class="js-admin-sub">{{ $postedByCollege }}</div>@endif
                    </div>
                    <div class="js-admin-cell">
                        <div class="js-admin-label">Deadline</div>
                        <div class="js-admin-value">{{ $dl->format('M d, Y') }}</div>
                        <div class="js-admin-sub" style="{{ $isExpired ? 'color:#c0392b' : '' }}">{{ $isExpired ? 'Expired' : $dl->diffForHumans() }}</div>
                    </div>
                    <div class="js-admin-cell-full">
                        <div class="js-admin-label">Activity</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div>
                                <div class="js-admin-value">{{ $job->updated_at->format('M d, Y · g:i A') }}</div>
                                <div class="js-admin-sub">{{ $job->updated_at->diffForHumans() }}</div>
                            </div>
                            @if($isOrgDel && $job->deleted_by)
                                <div class="js-update-badge delete-badge"><i class="fas fa-trash" style="font-size:9px"></i> Deleted by {{ $job->deleted_by }}</div>
                            @elseif($wasEdited)
                                <div class="js-update-badge {{ $updaterRole === 'admin' ? 'admin-badge' : '' }}">
                                    <i class="fas fa-{{ $updaterRole === 'admin' ? 'shield-halved' : 'user' }}" style="font-size:9px"></i>
                                    {{ $updaterName }}
                                    <span style="opacity:.55;font-weight:400">· {{ $updaterRole === 'admin' ? 'Admin' : 'Organizer' }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="js-footer">
            <button wire:click="closeViewModal" class="js-btn js-btn-close"><i class="fas fa-xmark" style="font-size:12px"></i> Close</button>
            @if($isOrgDel)
                <button wire:click="confirmRestore({{ $job->id }})" class="js-btn js-btn-restore"><i class="fas fa-rotate-left" style="font-size:12px"></i> Restore Job</button>
            @else
                <button wire:click="openEditModal({{ $job->id }})" class="js-btn js-btn-edit"><i class="fas fa-pen-to-square" style="font-size:12px"></i> Edit Posting</button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Edit Job Posting
════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeEditModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate flex flex-col">

        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-pen-to-square text-2xl"></i> Edit Job Posting</h2>
            <button wire:click="closeEditModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-8 py-5 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-3"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-2">
                @foreach($editErrors as $err)
                    <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span><span>{{ $err }}</span></li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 px-8 py-6 space-y-6 overflow-y-auto scrollbar-custom">

            <div>
                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-input {{ isset($editErrors['editJobTitle'])?'field-error':'' }}">
                @if(isset($editErrors['editJobTitle']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Organization <span class="text-red-500">*</span></label>
                    <select wire:model.live="editCompanyType" class="form-input {{ isset($editErrors['editCompanyType'])?'field-error':'' }}">
                        <option value="">Select Organization</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                    <label class="form-label">Company Name <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editCompany" type="text" placeholder="e.g. Acme Corp"
                           @if($editIsPhilcst) readonly @endif
                           class="form-input {{ isset($editErrors['editCompany'])?'field-error':'' }} {{ $editIsPhilcst?'bg-slate-100 text-slate-500 cursor-not-allowed':'' }}">
                    @if($editIsPhilcst)<p class="field-hint"><i class="fas fa-lock text-[10px] mr-1"></i>Auto-set for PHILCST.</p>@endif
                    @if(isset($editErrors['editCompany']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Location <span class="text-red-500">*</span></label>
                <input wire:model="editLocation" type="text" placeholder="e.g. Tuguegarao, Cagayan / Remote"
                       @if($editIsPhilcst) readonly @endif
                       class="form-input {{ isset($editErrors['editLocation'])?'field-error':'' }} {{ $editIsPhilcst?'bg-slate-100 text-slate-500 cursor-not-allowed':'' }}">
                @if(isset($editErrors['editLocation']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editEmpType" class="form-input {{ isset($editErrors['editEmpType'])?'field-error':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editExpLevel" class="form-input {{ isset($editErrors['editExpLevel'])?'field-error':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>
                        @endforeach
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
                    <input wire:model.defer="editDeadline" type="date" class="form-input {{ isset($editErrors['editDeadline'])?'field-error':'' }}">
                    @if(isset($editErrors['editDeadline']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                </div>
            </div>

            <div>
                <label class="form-label">Target College <span class="text-slate-400 font-normal text-xs">(Optional)</span></label>
                <select wire:model.live="editTargetCollege" class="form-input">
                    <option value="">All Colleges</option>
                    @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                </select>
                @php $editDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->editTargetCollege)['codes'] ?? []; @endphp
                @if(count($editDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($editDepts as $dCode)
                        <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>
                    @endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-label">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="editDescription" rows="7" placeholder="Describe the role, responsibilities, and qualifications…"
                          class="form-input resize-none {{ isset($editErrors['editDescription'])?'field-error':'' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>
        </div>

        <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 shrink-0 flex gap-4">
            <button type="button" wire:click="closeEditModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
            <button type="button" wire:click="saveEditJob" wire:loading.attr="disabled" wire:target="saveEditJob"
                    class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner spin-icon"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk"></i> Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL: Confirm Restore
════════════════════════════════════════════════════════════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-orange-50 border-b border-orange-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-orange-800 flex items-center gap-3"><i class="fas fa-rotate-left"></i> Restore Job Posting</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-1">You are about to restore:</p>
            <p class="font-bold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-6 text-xs text-blue-800 font-semibold flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                <span>The job will be set back to <strong>ACTIVE</strong> and become visible to students again.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-6 py-2.5 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner spin-icon"></i></span>
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
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Permanently Delete</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-1">You are about to permanently delete:</p>
            <p class="font-bold text-red-700 text-base mb-4">"{{ $deleteJobTitle }}"</p>
            <p class="text-xs mb-6 bg-red-50 rounded-lg px-3 py-2 border border-red-100 text-slate-500">
                <i class="fas fa-exclamation-circle text-red-400 mr-1.5"></i>This action <strong>cannot be undone</strong>.
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-6 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin-icon"></i></span>
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
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelConfirm()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-50 border-b border-emerald-200' : 'bg-amber-50 border-b border-amber-200' }} rounded-t-lg">
            <h2 class="text-xl font-bold {{ $confirmAction==='ACTIVE' ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-3">
                <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check' : 'ban' }}"></i>
                {{ $confirmAction==='ACTIVE' ? 'Activate Job Posting?' : 'Deactivate Job Posting?' }}
            </h2>
        </div>
        <div class="p-8">
            <p class="text-sm text-slate-600 leading-relaxed mb-6">
                @if($confirmAction==='ACTIVE')
                    This job will be marked as <span class="font-bold text-emerald-600">ACTIVE</span> and visible to students.
                @else
                    This job will be marked as <span class="font-bold text-amber-600">INACTIVE</span> and hidden from students. It can still be edited.
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-6 py-2.5 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600' }} text-white rounded-lg text-sm font-semibold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check' : 'ban' }}"></i>
                        {{ $confirmAction==='ACTIVE' ? 'Yes, Activate' : 'Yes, Deactivate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>