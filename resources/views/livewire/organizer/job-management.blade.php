<?php
/**
 * FILE: resources/views/livewire/organizer/job-posts.blade.php
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

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public string $filterSort   = 'recent';

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

    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

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

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

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

    #[Computed]
    public function organizerCollege(): ?string
    {
        $org = auth()->user()?->organizer;
        if (!$org) return null;
        $college = \App\Models\Course::where('college', $org->department)->value('college');
        return $college ?? $org->department ?? null;
    }

    #[Computed]
    public function jobPostings()
    {
        $org = auth()->user()?->organizer;
        if (!$org) return JobPosting::whereRaw('0=1')->paginate(20);

        $q = JobPosting::where('organizer_id', $org->id)
            ->select([
                'id','organizer_id','job_title','company_name','company_type',
                'location','employment_type','experience_level',
                'target_college','salary','deadline','status',
                'created_at','updated_at','updated_by','updated_by_role',
                'deleted_by','deleted_by_role',
            ]);

        // ✅ FIX: Always exclude ORGANIZER_DELETED — organizer never sees deleted jobs
        $q->whereIn('status', ['ACTIVE', 'INACTIVE']);

        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        if ($this->filterType !== '') $q->where('employment_type', $this->filterType);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function jobOptions()
    {
        return JobOption::orderBy('type')->orderBy('label')->get()->groupBy('type');
    }

    #[Computed]
    public function orderedExpLevels(): array
    {
        $fromDb  = $this->jobOptions->get('experience_level', collect())->pluck('label')->toArray();
        $ordered = [];
        foreach ($this->expLevelOrder as $lvl) {
            if (in_array($lvl, $fromDb, true)) $ordered[] = $lvl;
        }
        foreach ($fromDb as $lvl) {
            if (!in_array($lvl, $ordered, true)) $ordered[] = $lvl;
        }
        return $ordered;
    }

    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return app(JobController::class)->getJob($this->viewingJobId);
    }

    #[Computed]
    public function collegesWithDepts(): array
    {
        return app(\App\Http\Controllers\OrganizerJobController::class)->getCollegesWithDepts();
    }

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    public function openPostModal(): void
    {
        $this->resetPostFields();
        $this->postDeadline      = now()->setTimezone('Asia/Manila')->addMonth()->format('Y-m-d');
        $this->postTargetCollege = $this->organizerCollege ?? '';
        $this->showPostModal     = true;
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

        $orgCollege = $this->organizerCollege;
        if ($orgCollege && !empty(trim($this->postTargetCollege)) && trim($this->postTargetCollege) !== $orgCollege) {
            $errors['postTargetCollege'] = "You can only post jobs for your assigned college ({$orgCollege}).";
        }

        if (empty($errors['postTargetCollege']) && !empty(trim($this->postTargetCollege))) {
            $hasAlumni = \App\Models\Alumni::whereHas('course', fn($q) => $q->where('college', $this->postTargetCollege))
                ->exists();
            if (!$hasAlumni) {
                $errors['postTargetCollege'] = "No alumni found in \"{$this->postTargetCollege}\". Cannot post a job for this college.";
            }
        }

        if (!empty($errors)) { $this->postErrors = $errors; return; }

        [$companyName, $companyType] = match($this->postOrgCategory) {
            'philcst' => [$this->philcstName,           $this->philcstName],
            'partner' => [trim($this->postPartnerName), trim($this->postPartnerType)],
            'custom'  => [trim($this->postCustomName),  trim($this->postCustomType)],
            default   => ['', ''],
        };

        $org = auth()->user()?->organizer;
        $duplicate = JobPosting::where('job_title', trim($this->postJobTitle))
            ->where('company_name', $companyName)
            ->where('employment_type', trim($this->postEmpType))
            ->where('organizer_id', $org?->id)
            ->whereNotIn('status', ['ORGANIZER_DELETED'])
            ->exists();
        if ($duplicate) {
            $this->postErrors['postJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

        $resolvedLocation = $this->postOrgCategory === 'philcst'
            ? $this->philcstLocation
            : trim($this->postLocation);

        JobPosting::create([
            'organizer_id'     => $org?->id,
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
            'updated_by_role'  => 'organizer',
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

    public function viewJob(int $id): void  { $this->viewingJobId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void  { $this->showViewModal = false; $this->viewingJobId = null; }

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
        $this->editDeadline      = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->editDescription   = $job->description;
        $this->editTargetCollege = $job->target_college ?? '';
        $this->editErrors        = [];
        $this->showViewModal     = false;
        $this->showEditModal     = true;
    }

    public function closeEditModal(): void { $this->showEditModal = false; $this->resetEditFields(); }

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

        $orgCollege = $this->organizerCollege;
        if ($orgCollege && !empty(trim($this->editTargetCollege)) && trim($this->editTargetCollege) !== $orgCollege) {
            $errors['editTargetCollege'] = "You can only target your assigned college ({$orgCollege}).";
        }

        if (empty($errors['editTargetCollege']) && !empty(trim($this->editTargetCollege))) {
            $hasAlumni = \App\Models\Alumni::whereHas('course', fn($q) => $q->where('college', $this->editTargetCollege))
                ->exists();
            if (!$hasAlumni) {
                $errors['editTargetCollege'] = "No alumni found in \"{$this->editTargetCollege}\". Cannot target this college.";
            }
        }

        if (!empty($errors)) { $this->editErrors = $errors; return; }

        $org = auth()->user()?->organizer;
        $duplicate = JobPosting::where('job_title', trim($this->editJobTitle))
            ->where('company_name', trim($this->editCompany))
            ->where('employment_type', trim($this->editEmpType))
            ->where('organizer_id', $org?->id)
            ->whereNotIn('status', ['ORGANIZER_DELETED'])
            ->where('id', '!=', $this->editingJobId)
            ->exists();
        if ($duplicate) {
            $this->editErrors['editJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

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
            'updated_by_role'  => 'organizer',
        ]);

        $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully.');
        $this->showEditModal = false;
        $this->resetEditFields();
    }

    private function resetEditFields(): void
    {
        $this->editingJobId = null;
        $this->editJobTitle = $this->editCompany = $this->editCompanyType = '';
        $this->editLocation = $this->editEmpType = $this->editExpLevel    = '';
        $this->editSalary   = $this->editDeadline = $this->editDescription = '';
        $this->editTargetCollege = '';
        $this->editErrors = [];
    }

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
            $job = JobPosting::findOrFail($this->deleteJobId);
            $job->update([
                'status'          => 'ORGANIZER_DELETED',
                'deleted_by'      => auth()->user()?->name,
                'deleted_by_role' => 'organizer',
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been deleted.");
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
};
?>

<div>
<style>
:root{--brand:#7a3f91;--brand-d:#5e2f72;--brand-50:#f5eef9;--brand-100:#e9d5f3;--brand-200:#d4aaeb;}
.btn-brand{background:#7a3f91;color:#fff;box-shadow:0 2px 8px rgba(122,63,145,.28);transition:background .18s,box-shadow .18s,transform .12s;}
.btn-brand:hover:not(:disabled){background:#5e2f72;box-shadow:0 4px 16px rgba(122,63,145,.38);transform:translateY(-1px);}
.btn-brand:active:not(:disabled){transform:translateY(0);box-shadow:0 2px 6px rgba(122,63,145,.22);}
.btn-brand:disabled{opacity:.5;cursor:not-allowed;}
.btn-ghost{background:#fff;color:#374151;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:all .15s;}
.btn-ghost:hover:not(:disabled){background:#f9fafb;border-color:#d1d5db;box-shadow:0 3px 10px rgba(0,0,0,.11);transform:translateY(-1px);}
.btn-danger{background:#fff;color:#dc2626;border:1px solid #fecaca;box-shadow:0 1px 3px rgba(220,38,38,.07);transition:all .15s;}
.btn-danger:hover:not(:disabled){background:#fef2f2;border-color:#f87171;box-shadow:0 3px 10px rgba(220,38,38,.16);transform:translateY(-1px);}
.btn-edit{background:#fff;color:#2563eb;border:1px solid #bfdbfe;box-shadow:0 1px 3px rgba(37,99,235,.07);transition:all .15s;}
.btn-edit:hover:not(:disabled){background:#eff6ff;border-color:#93c5fd;box-shadow:0 3px 10px rgba(37,99,235,.16);transform:translateY(-1px);}
.btn-view{background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;box-shadow:0 1px 3px rgba(122,63,145,.09);transition:all .15s;}
.btn-view:hover{background:#e9d5f3;border-color:#9b5bb0;box-shadow:0 3px 10px rgba(122,63,145,.20);transform:translateY(-1px);}
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
.org-confirm-name{font-size:.875rem;font-weight:700;}
.org-confirm-sub{font-size:.75rem;margin-top:2px;}
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
.jv-badge.organizer{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
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
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
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
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-800 tracking-tight">My Job Posts</h1>
                <p class="text-gray-500 text-xs sm:text-sm mt-0.5">
                    Post and manage job listings for your alumni.
                    @if($this->organizerCollege)
                        <span class="inline-flex items-center gap-1 ml-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs font-bold">
                            <i class="fas fa-building-columns text-[10px]"></i> {{ $this->organizerCollege }}
                        </span>
                    @endif
                </p>
            </div>
        </div>
        <button wire:click="openPostModal"
                class="btn-brand inline-flex items-center gap-2 px-5 py-3 rounded-xl font-bold text-sm shrink-0">
            <i class="fas fa-plus text-sm"></i> Post a Job
        </button>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden" style="min-height:0;height:calc(100vh - 210px);">

        {{-- FILTER BAR --}}
        {{-- ✅ FIX: Removed x-data deletedSeen, removed badge dot entirely --}}
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

            {{-- ✅ FIX: Only Active / Inactive — removed "Deleted" option and badge dot --}}
            <select wire:model.live="filterStatus"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700 min-w-[140px]">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <select wire:model.live="filterType" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white inp text-gray-700 min-w-[150px] hidden sm:block">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
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
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeDelete">
                <table class="w-full border-collapse min-w-[640px]">
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
                        @php
                            $dl = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                        @endphp
                        {{-- ✅ FIX: No deleted rows shown — always ACTIVE or INACTIVE only --}}
                        <tr class="tbl-row bg-white">
                            <td class="px-4 sm:px-5 py-3.5 max-w-[160px] sm:max-w-[200px]">
                                <p class="font-semibold text-sm truncate text-gray-800">{{ $job->job_title }}</p>
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
                                    <button wire:click="openEditModal({{ $job->id }})" class="btn-edit inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                        <i class="fas fa-pen-to-square text-xs"></i><span class="hidden sm:inline">Edit</span>
                                    </button>
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
                                    <p class="font-semibold text-gray-400">No job postings found</p>
                                    <p class="text-sm text-gray-400">
                                        @if($search || $filterStatus || $filterType) Try adjusting your filters.
                                        @else No postings yet. Click <strong>Post a Job</strong> to create one.@endif
                                    </p>
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

{{-- ════ MODAL: Post a Job ════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closePostModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden">
        <div class="flex items-center justify-between px-7 py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-xl font-extrabold text-white flex items-center gap-3"><i class="fas fa-briefcase"></i> Post a New Job</h2>
            <button wire:click="closePostModal" class="text-white/70 hover:text-white text-2xl leading-none transition" type="button">×</button>
        </div>

        @if(count($postErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($postErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-c px-7 py-6 space-y-5">

            <div>
                <label class="form-lbl">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-inp {{ isset($postErrors['postJobTitle']) ? 'field-err' : '' }}">
                @if(isset($postErrors['postJobTitle']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
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
                            <button type="button" wire:click="$set('postOrgCategory','philcst')" class="org-cat-btn {{ $postOrgCategory==='philcst'?'active':'' }}">
                                <i class="fas fa-school text-lg"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')" class="org-cat-btn {{ $postOrgCategory==='partner'?'active':'' }}">
                                <i class="fas fa-handshake text-lg"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')" class="org-cat-btn {{ $postOrgCategory==='custom'?'active':'' }}">
                                <i class="fas fa-pen-to-square text-lg"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="form-err mt-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>

                    @if($postOrgCategory==='philcst')
                        @if($philcstName)
                        <div class="org-confirm-box philcst-box">
                            <div class="org-confirm-icon" style="background:linear-gradient(135deg,#7a3f91,#6a3580)"><i class="fas fa-school"></i></div>
                            <div class="flex-1"><div class="org-confirm-name" style="color:#4c1d95">PHILCST</div>
                            @if($philcstLocation)<div class="org-confirm-sub" style="color:#7c3aed"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif</div>
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-white border border-purple-200 px-3 py-1.5 rounded-full shrink-0"><i class="fas fa-lock text-[10px]"></i> Auto-filled</span>
                        </div>
                        @else
                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700"><i class="fas fa-triangle-exclamation mr-2"></i>No PHILCST campus found.</div>
                        @endif

                    @elseif($postOrgCategory==='partner')
                        <div wire:ignore
                             x-data="{
                                pName: @js($postPartnerName),
                                pType: @js($postPartnerType),
                                syncName(v){ $wire.set('postPartnerName', v, false) },
                                syncType(v){ $wire.set('postPartnerType', v, false) }
                             }">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="form-lbl">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text"
                                           placeholder="e.g. Acme Corporation"
                                           class="form-inp {{ isset($postErrors['postPartnerName'])?'field-err':'' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-lbl">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text"
                                           placeholder="e.g. Private Company, NGO"
                                           class="form-inp {{ isset($postErrors['postPartnerType'])?'field-err':'' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{ loc: @js($postLocation), syncLoc(v){ $wire.set('postLocation', v, false) } }">
                            <label class="form-lbl">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text"
                                   placeholder="e.g. Tuguegarao, Cagayan / Remote" maxlength="120"
                                   class="form-inp {{ isset($postErrors['postLocation'])?'field-err':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postPartnerName))
                        <div class="org-confirm-box partner-box">
                            <div class="org-confirm-icon" style="background:#2557a7"><i class="fas fa-handshake"></i></div>
                            <div><div class="org-confirm-name" style="color:#1e3a5f">{{ $postPartnerName }}</div>
                            @if(trim($postPartnerType))<div class="org-confirm-sub" style="color:#2557a7">{{ $postPartnerType }}</div>@endif
                            @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif</div>
                        </div>
                        @endif

                    @elseif($postOrgCategory==='custom')
                        <div wire:ignore
                             x-data="{
                                cName: @js($postCustomName),
                                cType: @js($postCustomType),
                                syncName(v){ $wire.set('postCustomName', v, false) },
                                syncType(v){ $wire.set('postCustomType', v, false) }
                             }">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="form-lbl">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text"
                                           placeholder="e.g. Department of Labor"
                                           class="form-inp {{ isset($postErrors['postCustomName'])?'field-err':'' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-lbl">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text"
                                           placeholder="e.g. Government Agency, NGO"
                                           class="form-inp {{ isset($postErrors['postCustomType'])?'field-err':'' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{ loc: @js($postLocation), syncLoc(v){ $wire.set('postLocation', v, false) } }">
                            <label class="form-lbl">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text"
                                   placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="form-inp {{ isset($postErrors['postLocation'])?'field-err':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postCustomName))
                        <div class="org-confirm-box custom-box">
                            <div class="org-confirm-icon" style="background:#475569"><i class="fas fa-pen-to-square"></i></div>
                            <div><div class="org-confirm-name" style="color:#1e293b">{{ $postCustomName }}</div>
                            @if(trim($postCustomType))<div class="org-confirm-sub" style="color:#475569">{{ $postCustomType }}</div>@endif
                            @if(trim($postLocation))<div class="org-confirm-sub" style="color:#555"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif</div>
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
                    <select wire:model.defer="postEmpType" class="form-inp {{ isset($postErrors['postEmpType'])?'field-err':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postEmpType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-lbl">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postExpLevel" class="form-inp {{ isset($postErrors['postExpLevel'])?'field-err':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}">{{ $lvl }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postExpLevel']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Salary <span class="text-gray-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-inp">
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="form-lbl">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postDeadline" type="date" class="form-inp {{ isset($postErrors['postDeadline'])?'field-err':'' }}">
                    @if(isset($postErrors['postDeadline']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                </div>
            </div>

            {{-- Target College --}}
            <div>
                <label class="form-lbl">Target College <span class="text-gray-400 font-normal text-xs">(Auto-filled from your college)</span></label>
                @if($this->organizerCollege)
                    <div class="flex items-center gap-3">
                        <div class="flex-1 form-inp bg-gray-50 text-gray-600 flex items-center gap-2 cursor-not-allowed select-none">
                            <i class="fas fa-building-columns text-purple-400 text-sm"></i>
                            <span class="font-semibold">{{ $this->organizerCollege }}</span>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-purple-50 border border-purple-200 px-3 py-2 rounded-lg flex-shrink-0">
                            <i class="fas fa-lock text-[10px]"></i> Auto-set
                        </span>
                    </div>
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Jobs are automatically targeted to your assigned college.</p>
                    @if(isset($postErrors['postTargetCollege']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postTargetCollege'] }}</p>@endif
                    <input type="hidden" wire:model="postTargetCollege">
                @else
                    <select wire:model.live="postTargetCollege" class="form-inp {{ isset($postErrors['postTargetCollege'])?'field-err':'' }}">
                        <option value="">All Colleges</option>
                        @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                    </select>
                    @if(isset($postErrors['postTargetCollege']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postTargetCollege'] }}</p>@endif
                @endif
                @php $postDepts = collect($this->collegesWithDepts)->firstWhere('name', $this->postTargetCollege)['codes'] ?? []; @endphp
                @if(count($postDepts) > 0)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($postDepts as $dCode)
                        <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono">{{ $dCode }}</span>
                    @endforeach
                </div>
                @endif
            </div>

            <div>
                <label class="form-lbl">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="postDescription" rows="6" placeholder="Describe the role, responsibilities, qualifications…"
                          class="form-inp resize-none {{ isset($postErrors['postDescription'])?'field-err':'' }}"></textarea>
                @if(isset($postErrors['postDescription']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDescription'] }}</p>@endif
            </div>
        </div>

        <div class="px-7 py-5 border-t border-gray-100 bg-gray-50/80 flex-shrink-0 flex gap-3">
            <button wire:click="closePostModal" class="btn-ghost flex-1 px-4 py-3 rounded-xl text-sm font-bold">Cancel</button>
            <button wire:click="savePost" wire:loading.attr="disabled" wire:target="savePost"
                    class="btn-brand flex-1 px-4 py-3 rounded-xl text-sm font-extrabold flex items-center justify-center gap-2">
                <span wire:loading wire:target="savePost"><i class="fas fa-spinner spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane"></i> Post Job</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: View Job ════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job      = $this->viewingJob;
    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $isExp    = now('Asia/Manila')->gt($dl);
    $createdPH  = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $updatedPH  = \Carbon\Carbon::parse($job->updated_at)->setTimezone('Asia/Manila');
    $viewDepts = $job->target_college
        ? \App\Models\Course::where('college', $job->target_college)->orderBy('code')->pluck('code')->toArray()
        : [];
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closeViewModal">
    <div class="jv-modal m-in relative">
        <button wire:click="closeViewModal" class="jv-close-x" type="button">&times;</button>
        <div class="jv-header">
            <div class="jv-title">{{ $job->job_title }}</div>
            <div class="jv-company">
                <strong>{{ $job->company_name }}</strong>
                <span class="jv-pill jv-pill-type">{{ $displayType }}</span>
                @if($job->status==='ACTIVE')<span class="jv-pill jv-pill-active">Active</span>
                @else<span class="jv-pill jv-pill-inactive">Inactive</span>@endif
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
            <p style="margin-top:14px;font-size:12px;color:#777;">
                Posted {{ $createdPH->diffForHumans() }}
            </p>
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
                    <div class="jv-cell">
                        <div class="jv-cell-lbl">Posted On</div>
                        <div class="jv-cell-val">{{ $createdPH->format('M d, Y') }}</div>
                    </div>
                    <div class="jv-cell"><div class="jv-cell-lbl">Posted By</div><div class="jv-cell-val">{{ $job->organizer?->name ?? 'You' }}</div></div>
                    <div class="jv-cell">
                        <div class="jv-cell-lbl">Deadline</div>
                        <div class="jv-cell-val">{{ $dl->format('M d, Y') }}</div>
                        <div class="jv-cell-sub" style="{{ $isExp ? 'color:#c0392b' : '' }}">{{ $isExp ? 'Expired' : $dl->diffForHumans() }}</div>
                    </div>
                    <div class="jv-cell-full"><div class="jv-cell-lbl">Last Updated</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div>
                                <div class="jv-cell-val">{{ $updatedPH->format('M d, Y') }}</div>
                                <div class="jv-cell-sub">{{ $updatedPH->diffForHumans() }}</div>
                            </div>
                            @if($job->updated_by)
                                <span class="jv-badge organizer">
                                    <i class="fas fa-user" style="font-size:9px"></i>
                                    {{ $job->updated_by }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="jv-footer">
            <button wire:click="closeViewModal" class="btn-ghost inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" type="button"><i class="fas fa-xmark text-xs"></i> Close</button>
            <button wire:click="confirmDelete({{ $job->id }})" class="btn-danger inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" type="button"><i class="fas fa-trash text-xs"></i> Delete</button>
            <button wire:click="openEditModal({{ $job->id }})" class="btn-edit inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" type="button"><i class="fas fa-pen-to-square text-xs"></i> Edit</button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Edit Job ════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closeEditModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden">
        <div class="flex items-center justify-between px-7 py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-xl font-extrabold text-white flex items-center gap-3"><i class="fas fa-pen-to-square"></i> Edit Job Posting</h2>
            <button wire:click="closeEditModal" class="text-white/70 hover:text-white text-2xl leading-none transition" type="button">×</button>
        </div>

        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($editErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-c px-7 py-6 space-y-5">
            <div>
                <label class="form-lbl">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer"
                       class="form-inp {{ isset($editErrors['editJobTitle'])?'field-err':'' }}">
                @if(isset($editErrors['editJobTitle']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Organization Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="editCompanyType" class="form-inp {{ isset($editErrors['editCompanyType'])?'field-err':'' }}">
                        <option value="">Select Organization</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                    <label class="form-lbl">Company Name <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editCompany" type="text" @if($editIsPhilcst) readonly @endif
                           class="form-inp {{ isset($editErrors['editCompany'])?'field-err':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-500 cursor-not-allowed':'' }}">
                    @if(isset($editErrors['editCompany']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="form-lbl">Location <span class="text-red-500">*</span></label>
                <input wire:model="editLocation" type="text" @if($editIsPhilcst) readonly @endif
                       class="form-inp {{ isset($editErrors['editLocation'])?'field-err':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-500 cursor-not-allowed':'' }}">
                @if(isset($editErrors['editLocation']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editEmpType" class="form-inp {{ isset($editErrors['editEmpType'])?'field-err':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="form-lbl">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editExpLevel" class="form-inp {{ isset($editErrors['editExpLevel'])?'field-err':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editExpLevel']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-lbl">Salary <span class="text-gray-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="editSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / month" class="form-inp">
                </div>
                <div>
                    <label class="form-lbl">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editDeadline" type="date" class="form-inp {{ isset($editErrors['editDeadline'])?'field-err':'' }}">
                    @if(isset($editErrors['editDeadline']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                </div>
            </div>

            {{-- Target College --}}
            <div>
                <label class="form-lbl">Target College <span class="text-gray-400 font-normal text-xs">(Auto-filled from your college)</span></label>
                @if($this->organizerCollege)
                    <div class="flex items-center gap-3">
                        <div class="flex-1 form-inp bg-gray-50 text-gray-600 flex items-center gap-2 cursor-not-allowed select-none">
                            <i class="fas fa-building-columns text-purple-400 text-sm"></i>
                            <span class="font-semibold">{{ $this->organizerCollege }}</span>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-purple-50 border border-purple-200 px-3 py-2 rounded-lg flex-shrink-0">
                            <i class="fas fa-lock text-[10px]"></i> Auto-set
                        </span>
                    </div>
                    <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Targeted to your assigned college.</p>
                    @if(isset($editErrors['editTargetCollege']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editTargetCollege'] }}</p>@endif
                    <input type="hidden" wire:model="editTargetCollege">
                @else
                    <select wire:model.live="editTargetCollege" class="form-inp {{ isset($editErrors['editTargetCollege'])?'field-err':'' }}">
                        <option value="">All Colleges</option>
                        @foreach($this->collegesWithDepts as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editTargetCollege']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editTargetCollege'] }}</p>@endif
                @endif
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
                <label class="form-lbl">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="editDescription" rows="7" class="form-inp resize-none {{ isset($editErrors['editDescription'])?'field-err':'' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="form-err"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>
        </div>

        <div class="px-7 py-5 border-t border-gray-100 bg-gray-50/80 flex-shrink-0 flex gap-3">
            <button wire:click="closeEditModal" class="btn-ghost flex-1 px-4 py-3 rounded-xl text-sm font-bold">Cancel</button>
            <button wire:click="saveEditJob" wire:loading.attr="disabled" wire:target="saveEditJob"
                    class="btn-brand flex-1 px-4 py-3 rounded-xl text-sm font-extrabold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk"></i> Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="cancelDelete">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-triangle-exclamation text-red-500 text-sm"></i></div>
                Delete Job Posting
            </h2>
        </div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">You are about to delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-5 text-xs text-gray-600 flex items-start gap-2">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 shrink-0"></i>
                <span>The job will be removed from your list. <strong>Admin can still see and restore it</strong> if needed.</span>
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

</div>
</div>