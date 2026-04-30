{{-- resources/views/livewire/admin/job-posts.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public string $filterSort   = 'recent';

    public string $myDisplayName = '';

    public bool   $showPostModal      = false;
    public string $postJobTitle       = '';
    public string $postOrgCategory    = '';
    public string $postPartnerName    = '';
    public string $postPartnerType    = '';
    public string $postCustomName     = '';
    public string $postCustomType     = '';
    public string $postLocation       = '';
    public string $postEmpType        = '';
    public string $postExpLevel       = '';
    public string $postSalary         = '';
    public string $postDeadline       = '';
    public string $postDescription    = '';
    public array  $postTargetColleges = [];
    public array  $postErrors         = [];
    public bool   $postAllColleges    = false;

    public string $philcstName     = '';
    public string $philcstLocation = '';

    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    public bool   $showEditModal      = false;
    public ?int   $editingJobId       = null;
    public string $editJobTitle       = '';
    public string $editCompany        = '';
    public string $editCompanyType    = '';
    public string $editLocation       = '';
    public string $editEmpType        = '';
    public string $editExpLevel       = '';
    public string $editSalary         = '';
    public string $editDeadline       = '';
    public string $editDescription    = '';
    public array  $editTargetColleges = [];
    public array  $editErrors         = [];
    public bool   $editAllColleges    = false;

    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    public bool   $showRestoreModal = false;
    public ?int   $restoreJobId     = null;
    public string $restoreJobTitle  = '';

    // ── Share Job Modal ───────────────────────────────────────────────────────
    public bool   $showShareJobModal   = false;
    public ?int   $shareJobId          = null;
    public string $shareJobTitle       = '';
    public string $shareJobCompany     = '';
    public string $shareJobCompanyType = '';
    public string $shareJobLocation    = '';
    public string $shareJobEmpType     = '';
    public string $shareJobExpLevel    = '';
    public string $shareJobSalary      = '';
    public string $shareJobDeadline    = '';
    public string $shareJobDescription = '';
    public string $shareJobTarget      = '';
    // ─────────────────────────────────────────────────────────────────────────

    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

    private function authorizeRole(): void
    {
        abort_unless(
            auth()->check() && in_array(auth()->user()->role, ['admin', 'director']),
            403
        );
    }

    public function mount(): void
    {
        $this->authorizeRole();

        $dirRecord = DB::table('director')
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->first();

        if ($dirRecord) {
            $this->myDisplayName = trim(($dirRecord->first_name ?? '') . ' ' . ($dirRecord->last_name ?? ''));
        }
        if (! $this->myDisplayName) {
            $this->myDisplayName = auth()->user()?->name ?? 'Admin';
        }

        $expiredCount = JobPosting::where('status', 'ACTIVE')
            ->whereDate('deadline', '<', now('Asia/Manila')->toDateString())
            ->count();

        if ($expiredCount > 0) {
            JobPosting::where('status', 'ACTIVE')
                ->whereDate('deadline', '<', now('Asia/Manila')->toDateString())
                ->update([
                    'status'          => 'INACTIVE',
                    'updated_by'      => 'System (Auto-Expired)',
                    'updated_by_role' => 'admin',
                    'updated_at'      => now(),
                ]);

            $this->writeAuditLog(
                action:      'updated',
                description: "System auto-deactivated {$expiredCount} expired job(s) on page load.",
                severity:    'info',
                subject:     'Auto-Expiry',
                newValues:   ['status' => 'INACTIVE', 'count' => $expiredCount],
            );
        }

        $philcst = JobOption::where('type', 'company_type')
            ->where('label', 'like', '%PHILCST%')
            ->orderBy('label')
            ->first();

        if ($philcst) {
            $this->philcstName     = $philcst->label;
            $this->philcstLocation = $philcst->default_location ?? '';
        }
    }

    private function sanitize(string $value): string
    {
        return strip_tags(trim($value));
    }

    private function writeAuditLog(
        string $action,
        string $description,
        string $severity   = 'info',
        ?string $subject   = null,
        ?array  $oldValues = null,
        ?array  $newValues = null,
    ): void {
        try {
            AuditLog::create([
                'action'        => $action,
                'module'        => 'job_posting',
                'user_name'     => $this->myDisplayName,
                'user_email'    => auth()->user()?->email ?? null,
                'user_role'     => auth()->user()?->role  ?? 'admin',
                'subject_label' => $subject,
                'description'   => $description,
                'old_values'    => $oldValues,
                'new_values'    => $newValues,
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'severity'      => $severity,
                'is_flagged'    => false,
            ]);
        } catch (\Throwable) {}
    }

    private function fetchJob(int $id): JobPosting
    {
        return JobPosting::with('organizer')->findOrFail($id);
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

    public function updatedPostAllColleges($value): void
    {
        $this->postTargetColleges = $value
            ? collect($this->collegesWithDepts)->pluck('name')->toArray()
            : [];
    }

    public function updatedEditAllColleges($value): void
    {
        $this->editTargetColleges = $value
            ? collect($this->collegesWithDepts)->pluck('name')->toArray()
            : [];
    }

    #[Computed]
    public function jobPostings()
    {
        $this->authorizeRole();

        $q = JobPosting::with('organizer:id,name,department')
            ->select([
                'id','organizer_id','job_title','company_name','company_type',
                'location','employment_type','experience_level',
                'target_college','salary','deadline','status',
                'created_at','updated_at','updated_by','updated_by_role',
                'deleted_by','deleted_by_role',
            ]);

        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        } else {
            $q->whereIn('status', ['ACTIVE', 'INACTIVE', 'ORGANIZER_DELETED']);
        }

        if ($this->search !== '') {
            $s = $this->sanitize($this->search);
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        if ($this->filterType !== '') {
            $q->where('employment_type', $this->sanitize($this->filterType));
        }

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');

        $paginated = $q->paginate(20);

        $depts = $paginated->getCollection()
            ->pluck('organizer.department')
            ->filter()->unique()->values();

        $collegeMap = [];
        if ($depts->isNotEmpty()) {
            $collegeMap = Course::whereIn('college', $depts)
                ->distinct()->pluck('college', 'college')->toArray();
        }

        $now = now('Asia/Manila')->startOfDay();

        $paginated->getCollection()->transform(function ($job) use ($collegeMap, $now) {
            $dept                   = $job->organizer?->department;
            $job->_organizerCollege = $dept ? ($collegeMap[$dept] ?? $dept) : null;
            $deadline               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
            $job->_isDeadlinePassed = $deadline < $now;
            return $job;
        });

        return $paginated;
    }

    #[Computed]
    public function jobOptions()
    {
        return Cache::remember('job_options_grouped', 300, function () {
            return JobOption::orderBy('type')->orderBy('label')->get()->groupBy('type');
        });
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
        return JobPosting::with('organizer')->find($this->viewingJobId);
    }

    #[Computed]
    public function collegesWithDepts(): array
    {
        return Cache::remember('colleges_with_depts_v2', 600, function () {
            return Course::select('college')->distinct()->orderBy('college')->get()
                ->map(fn($c) => ['name' => $c->college])->values()->toArray();
        });
    }

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    public function openPostModal(): void
    {
        $this->authorizeRole();
        $this->resetPostFields();
        $this->postDeadline  = now()->setTimezone('Asia/Manila')->addMonth()->format('Y-m-d');
        $this->showPostModal = true;
    }

    public function closePostModal(): void
    {
        $this->showPostModal = false;
        $this->resetPostFields();
    }

    public function savePost(): void
    {
        $this->authorizeRole();

        $key = 'post_job_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->postErrors['rate'] = 'Too many attempts. Please wait a moment.';
            return;
        }
        RateLimiter::hit($key, 60);

        $this->postErrors = [];
        $errors = [];

        $title       = $this->sanitize($this->postJobTitle);
        $orgCat      = $this->sanitize($this->postOrgCategory);
        $partnerName = $this->sanitize($this->postPartnerName);
        $partnerType = $this->sanitize($this->postPartnerType);
        $customName  = $this->sanitize($this->postCustomName);
        $customType  = $this->sanitize($this->postCustomType);
        $location    = $this->sanitize($this->postLocation);
        $empType     = $this->sanitize($this->postEmpType);
        $expLevel    = $this->sanitize($this->postExpLevel);
        $salary      = $this->sanitize($this->postSalary);
        $description = $this->sanitize($this->postDescription);
        $deadline    = $this->sanitize($this->postDeadline);

        if (!$title)  $errors['postJobTitle']    = 'Job title is required.';
        if (!$orgCat) $errors['postOrgCategory'] = 'Please select an organization category.';

        if ($orgCat === 'partner') {
            if (!$partnerName) $errors['postPartnerName'] = 'Organization name is required.';
            if (!$partnerType) $errors['postPartnerType'] = 'Organization type is required.';
            if (!$location)    $errors['postLocation']    = 'Location is required.';
        }
        if ($orgCat === 'custom') {
            if (!$customName) $errors['postCustomName'] = 'Organization name is required.';
            if (!$customType) $errors['postCustomType'] = 'Organization type is required.';
            if (!$location)   $errors['postLocation']   = 'Location is required.';
        }

        if (!$empType)  $errors['postEmpType']  = 'Employment type is required.';
        if (!$expLevel) $errors['postExpLevel'] = 'Experience level is required.';

        if (!$deadline) {
            $errors['postDeadline'] = 'Deadline is required.';
        } else {
            $now          = now('Asia/Manila');
            $deadlineDate = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
            if ($deadlineDate < $now) {
                $errors['postDeadline'] = 'Deadline must be today or in the future.';
            }
        }

        if (!$description) $errors['postDescription'] = 'Job description is required.';

        if (empty($this->postTargetColleges)) {
            $errors['postTargetColleges'] = 'Please select at least one college.';
        } else {
            foreach ($this->postTargetColleges as $college) {
                $hasAlumni = \App\Models\Alumni::whereHas('course', fn($q) => $q->where('college', $college))->exists();
                if (!$hasAlumni) {
                    $errors['postTargetColleges'] = "No alumni found in \"{$college}\". Cannot post a job for this college.";
                    break;
                }
            }
        }

        if (!empty($errors)) { $this->postErrors = $errors; return; }

        [$companyName, $companyType] = match($orgCat) {
            'philcst' => [$this->philcstName, $this->philcstName],
            'partner' => [$partnerName, $partnerType],
            'custom'  => [$customName, $customType],
            default   => ['', ''],
        };

        $duplicate = JobPosting::where('job_title', $title)
            ->where('company_name', $companyName)
            ->where('employment_type', $empType)
            ->whereNotIn('status', ['ORGANIZER_DELETED', 'ADMIN_DELETED'])
            ->exists();

        if ($duplicate) {
            $this->postErrors['postJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

        $resolvedLocation = $orgCat === 'philcst' ? $this->philcstLocation : $location;
        $targetCollegeStr = !empty($this->postTargetColleges) ? implode(',', $this->postTargetColleges) : null;

        $job = JobPosting::create([
            'organizer_id'     => null,
            'job_title'        => $title,
            'company_name'     => $companyName,
            'company_type'     => $companyType,
            'location'         => $resolvedLocation,
            'employment_type'  => $empType,
            'experience_level' => $expLevel,
            'salary'           => $salary ?: null,
            'deadline'         => $deadline,
            'description'      => $description,
            'target_college'   => $targetCollegeStr,
            'status'           => 'ACTIVE',
            'updated_by'       => $this->myDisplayName,
            'updated_by_role'  => auth()->user()->role,
        ]);

        $this->writeAuditLog(
            action:      'created',
            description: "Director posted new job: \"{$title}\" at {$companyName} ({$empType})",
            severity:    'info',
            subject:     $title,
            newValues:   [
                'job_id'           => $job->id,
                'job_title'        => $title,
                'company_name'     => $companyName,
                'employment_type'  => $empType,
                'experience_level' => $expLevel,
                'location'         => $resolvedLocation,
                'deadline'         => $deadline,
                'target_college'   => $targetCollegeStr,
                'status'           => 'ACTIVE',
            ],
        );

        Cache::forget('job_options_grouped');
        $this->dispatch('flash-message', type: 'success', message: 'Job posting created successfully!');
        $this->showPostModal = false;
        $this->resetPostFields();
    }

    private function resetPostFields(): void
    {
        $this->postJobTitle = $this->postOrgCategory = '';
        $this->postPartnerName = $this->postPartnerType = $this->postCustomName = $this->postCustomType = '';
        $this->postLocation = $this->postEmpType = $this->postExpLevel = $this->postSalary = '';
        $this->postDeadline = $this->postDescription = '';
        $this->postTargetColleges = [];
        $this->postErrors = [];
        $this->postAllColleges = false;
    }

    public function viewJob(int $id): void
    {
        $this->authorizeRole();
        $this->viewingJobId  = $id;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingJobId  = null;
    }

    public function openEditModal(int $id): void
    {
        $this->authorizeRole();
        $job = $this->fetchJob($id);

        $this->editingJobId       = $id;
        $this->editJobTitle       = $job->job_title;
        $this->editCompany        = $job->company_name;
        $this->editCompanyType    = $job->company_type;
        $this->editLocation       = $job->location ?? '';
        $this->editEmpType        = $job->employment_type;
        $this->editExpLevel       = $job->experience_level;
        $this->editSalary         = $job->salary ?? '';
        $this->editDeadline       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->editDescription    = $job->description;
        $this->editTargetColleges = !empty($job->target_college) ? explode(',', $job->target_college) : [];
        $this->editErrors         = [];
        $this->editAllColleges    = false;
        $this->showViewModal      = false;
        $this->showEditModal      = true;
    }

    public function closeEditModal(): void { $this->showEditModal = false; $this->resetEditFields(); }

    public function saveEditJob(): void
    {
        $this->authorizeRole();

        $key = 'edit_job_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->editErrors['rate'] = 'Too many attempts. Please wait a moment.';
            return;
        }
        RateLimiter::hit($key, 60);

        $this->editErrors = [];
        $errors = [];

        $title       = $this->sanitize($this->editJobTitle);
        $company     = $this->sanitize($this->editCompany);
        $companyType = $this->sanitize($this->editCompanyType);
        $location    = $this->sanitize($this->editLocation);
        $empType     = $this->sanitize($this->editEmpType);
        $expLevel    = $this->sanitize($this->editExpLevel);
        $salary      = $this->sanitize($this->editSalary);
        $deadline    = $this->sanitize($this->editDeadline);
        $description = $this->sanitize($this->editDescription);

        if (!$title)       $errors['editJobTitle']    = 'Job title is required.';
        if (!$company)     $errors['editCompany']     = 'Organization name is required.';
        if (!$companyType) $errors['editCompanyType'] = 'Organization type is required.';
        if (!$location)    $errors['editLocation']    = 'Location is required.';
        if (!$empType)     $errors['editEmpType']     = 'Employment type is required.';
        if (!$expLevel)    $errors['editExpLevel']    = 'Experience level is required.';

        if (!$deadline) {
            $errors['editDeadline'] = 'Deadline is required.';
        } else {
            $now          = now('Asia/Manila');
            $deadlineDate = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
            if ($deadlineDate < $now) {
                $errors['editDeadline'] = 'Deadline must be today or in the future.';
            }
        }

        if (!$description) $errors['editDescription'] = 'Job description is required.';

        if (empty($this->editTargetColleges)) {
            $errors['editTargetColleges'] = 'Please select at least one college.';
        } else {
            foreach ($this->editTargetColleges as $college) {
                $hasAlumni = \App\Models\Alumni::whereHas('course', fn($q) => $q->where('college', $college))->exists();
                if (!$hasAlumni) {
                    $errors['editTargetColleges'] = "No alumni found in \"{$college}\". Cannot target this college.";
                    break;
                }
            }
        }

        if (!empty($errors)) { $this->editErrors = $errors; return; }

        $duplicate = JobPosting::where('job_title', $title)
            ->where('company_name', $company)
            ->where('employment_type', $empType)
            ->whereNotIn('status', ['ORGANIZER_DELETED', 'ADMIN_DELETED'])
            ->where('id', '!=', $this->editingJobId)
            ->exists();

        if ($duplicate) {
            $this->editErrors['editJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

        $job = JobPosting::findOrFail($this->editingJobId);

        $oldValues = [
            'job_title'        => $job->job_title,
            'company_name'     => $job->company_name,
            'company_type'     => $job->company_type,
            'location'         => $job->location,
            'employment_type'  => $job->employment_type,
            'experience_level' => $job->experience_level,
            'salary'           => $job->salary,
            'deadline'         => \Carbon\Carbon::parse($job->deadline)->format('Y-m-d'),
            'target_college'   => $job->target_college,
        ];

        $targetCollegeStr = !empty($this->editTargetColleges) ? implode(',', $this->editTargetColleges) : null;

        $newValues = [
            'job_title'        => $title,
            'company_name'     => $company,
            'company_type'     => $companyType,
            'location'         => $location,
            'employment_type'  => $empType,
            'experience_level' => $expLevel,
            'salary'           => $salary ?: null,
            'deadline'         => $deadline,
            'target_college'   => $targetCollegeStr,
        ];

        $deadlineDate     = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
        $shouldReactivate = $job->status === 'INACTIVE' && $deadlineDate >= now('Asia/Manila');

        $job->update(array_merge($newValues, [
            'description'     => $description,
            'updated_by'      => $this->myDisplayName,
            'updated_by_role' => auth()->user()->role,
            'status'          => $shouldReactivate ? 'ACTIVE' : $job->status,
        ]));

        $this->writeAuditLog(
            action:      'updated',
            description: "Director edited job: \"{$title}\" (ID {$job->id}) at {$company}"
                . ($shouldReactivate ? ' — re-activated after deadline update.' : ''),
            severity:    'info',
            subject:     $title,
            oldValues:   $oldValues,
            newValues:   array_merge($newValues, $shouldReactivate ? ['status' => 'ACTIVE'] : []),
        );

        $msg = $shouldReactivate
            ? 'Job updated and re-activated successfully.'
            : 'Job posting updated successfully.';

        $this->dispatch('flash-message', type: 'success', message: $msg);
        $this->showEditModal = false;
        $this->resetEditFields();
    }

    private function resetEditFields(): void
    {
        $this->editingJobId = null;
        $this->editJobTitle = $this->editCompany = $this->editCompanyType = '';
        $this->editLocation = $this->editEmpType = $this->editExpLevel    = '';
        $this->editSalary   = $this->editDeadline = $this->editDescription = '';
        $this->editTargetColleges = [];
        $this->editErrors = [];
        $this->editAllColleges = false;
    }

    public function confirmToggle(int $id): void
    {
        $this->authorizeRole();
        $job = JobPosting::findOrFail($id);

        if ($job->status === 'INACTIVE') {
            $deadline = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
            $today    = now('Asia/Manila')->startOfDay();

            if ($today > $deadline) {
                $this->dispatch('flash-message', type: 'warning',
                    message: 'Deadline has already passed. Please update the deadline before activating.');
                $this->openEditModal($id);
                return;
            }
        }

        $this->confirmJobId     = $id;
        $this->confirmAction    = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $this->showConfirmModal = true;
    }

    public function executeToggle(): void
    {
        $this->authorizeRole();

        if (!$this->confirmJobId) { $this->showConfirmModal = false; return; }

        $job = JobPosting::findOrFail($this->confirmJobId);

        if ($job->status === 'INACTIVE') {
            $deadline = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
            $today    = now('Asia/Manila')->startOfDay();

            if ($today > $deadline) {
                $this->dispatch('flash-message', type: 'error',
                    message: 'Cannot activate: deadline has already passed. Update the deadline first.');
                $this->showConfirmModal = false;
                $this->openEditModal($this->confirmJobId);
                return;
            }
        }

        $oldStatus = $job->status;
        $newStatus = $oldStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';

        $job->update([
            'status'          => $newStatus,
            'updated_by'      => $this->myDisplayName,
            'updated_by_role' => auth()->user()->role,
        ]);

        $label = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';

        $this->writeAuditLog(
            action:      'updated',
            description: "Director {$label} job: \"{$job->job_title}\" (ID {$job->id})",
            severity:    $newStatus === 'INACTIVE' ? 'warning' : 'info',
            subject:     $job->job_title,
            oldValues:   ['status' => $oldStatus],
            newValues:   ['status' => $newStatus],
        );

        $this->dispatch('flash-message', type: 'success', message: "Job posting has been {$label}.");
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
    }

    public function cancelConfirm(): void { $this->showConfirmModal = false; $this->confirmJobId = null; }

    public function confirmDelete(int $id): void
    {
        $this->authorizeRole();
        $job = JobPosting::findOrFail($id);
        $this->deleteJobId     = $id;
        $this->deleteJobTitle  = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        $this->authorizeRole();

        $key = 'delete_job_' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->dispatch('flash-message', type: 'error', message: 'Too many delete attempts. Please wait.');
            return;
        }
        RateLimiter::hit($key, 60);

        if (!$this->deleteJobId) { $this->showDeleteModal = false; return; }

        $job = JobPosting::findOrFail($this->deleteJobId);

        $snapshot = [
            'job_title'       => $job->job_title,
            'company_name'    => $job->company_name,
            'employment_type' => $job->employment_type,
            'status_before'   => $job->status,
        ];

        $job->update([
            'status'          => 'ADMIN_DELETED',
            'deleted_by'      => $this->myDisplayName,
            'deleted_by_role' => auth()->user()?->role,
        ]);

        $this->writeAuditLog(
            action:      'deleted',
            description: "Director deleted job: \"{$this->deleteJobTitle}\" (ID {$job->id})",
            severity:    'critical',
            subject:     $this->deleteJobTitle,
            oldValues:   $snapshot,
            newValues:   ['status' => 'ADMIN_DELETED', 'deleted_by' => $this->myDisplayName],
        );

        $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been deleted.");
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

    public function confirmRestore(int $id): void
    {
        $this->authorizeRole();
        $job = JobPosting::findOrFail($id);
        $this->restoreJobId     = $id;
        $this->restoreJobTitle  = $job->job_title;
        $this->showRestoreModal = true;
    }

    public function executeRestore(): void
    {
        $this->authorizeRole();

        if (!$this->restoreJobId) { $this->showRestoreModal = false; return; }

        $job = JobPosting::findOrFail($this->restoreJobId);
        $oldStatus = $job->status;

        $deadline      = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
        $today         = now('Asia/Manila')->startOfDay();
        $restoreStatus = ($today > $deadline) ? 'INACTIVE' : 'ACTIVE';

        $job->update([
            'status'          => $restoreStatus,
            'deleted_by'      => null,
            'deleted_by_role' => null,
            'updated_by'      => 'Restored by ' . $this->myDisplayName,
            'updated_by_role' => auth()->user()->role,
        ]);

        $this->writeAuditLog(
            action:      'updated',
            description: "Director restored job: \"{$this->restoreJobTitle}\" (ID {$job->id}) → {$restoreStatus}",
            severity:    'info',
            subject:     $this->restoreJobTitle,
            oldValues:   ['status' => $oldStatus],
            newValues:   ['status' => $restoreStatus, 'restored_by' => $this->myDisplayName],
        );

        $msg = $restoreStatus === 'INACTIVE'
            ? "'{$this->restoreJobTitle}' restored as Inactive — deadline has passed. Please update the deadline to activate it."
            : "'{$this->restoreJobTitle}' has been restored and is now Active.";

        $this->dispatch('flash-message', type: $restoreStatus === 'INACTIVE' ? 'warning' : 'success', message: $msg);
        $this->showRestoreModal = false;
        $this->restoreJobId    = null;
        $this->restoreJobTitle = '';

        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingJobId = null; }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreJobId    = null;
        $this->restoreJobTitle = '';
    }

    public function openShareJobModal(int $id): void
    {
        $this->authorizeRole();

        $job = JobPosting::find($id);
        if (!$job || $job->status !== 'ACTIVE') {
            $this->dispatch('flash-message', type: 'error', message: 'Only active job postings can be shared.');
            return;
        }

        $this->shareJobId          = $id;
        $this->shareJobTitle       = $job->job_title;
        $this->shareJobCompany     = $job->company_name;
        $this->shareJobCompanyType = $job->company_type;
        $this->shareJobLocation    = $job->location ?? '';
        $this->shareJobEmpType     = $job->employment_type;
        $this->shareJobExpLevel    = $job->experience_level;
        $this->shareJobSalary      = $job->salary ?? '';
        $this->shareJobDeadline    = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->format('F d, Y');
        $this->shareJobDescription = $job->description ?? '';
        $this->shareJobTarget      = $job->target_college ?? '';

        $this->showShareJobModal = true;
        $this->showViewModal     = false;
    }

    public function closeShareJobModal(): void
    {
        $this->showShareJobModal   = false;
        $this->shareJobId          = null;
        $this->shareJobTitle       = '';
        $this->shareJobCompany     = '';
        $this->shareJobCompanyType = '';
        $this->shareJobLocation    = '';
        $this->shareJobEmpType     = '';
        $this->shareJobExpLevel    = '';
        $this->shareJobSalary      = '';
        $this->shareJobDeadline    = '';
        $this->shareJobDescription = '';
        $this->shareJobTarget      = '';
    }

    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('upcoming.jobs', [], false); } catch (\Throwable) { $path = '/jobs'; }
        return $base . $path;
    }

    public function postJobToBatchChat(): void
    {
        $this->authorizeRole();

        if (!$this->shareJobId) {
            $this->dispatch('flash-message', type: 'error', message: 'Job not found.');
            return;
        }

        $job = JobPosting::find($this->shareJobId);
        if (!$job) {
            $this->dispatch('flash-message', type: 'error', message: 'Job not found.');
            return;
        }

        $room = DB::table('chat_rooms')->where('course_code', '__director__')->first();

        if (!$room) {
            $roomId = DB::table('chat_rooms')->insertGetId([
                'name'        => 'Directors & Coordinators',
                'course_code' => '__director__',
                'batch'       => 0,
                'department'  => 'ALL',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $roomId = $room->id;
        }

        $dirRecord = DB::table('director')
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->first();

        if (!$dirRecord) {
            $this->dispatch('flash-message', type: 'error', message: 'Director record not found.');
            return;
        }

        $baseUrl = $this->jobsBaseUrl();
        $targets = $job->target_college ? str_replace(',', ', ', $job->target_college) : 'All Alumni';

        $lines = [
            "💼 @everyone — Job Opportunity!",
            "",
            "📌 {$job->job_title}",
            "🏢 {$job->company_name}" . ($job->location ? " · {$job->location}" : ''),
            "⏰ {$job->employment_type}" . ($job->experience_level ? " · {$job->experience_level}" : ''),
        ];
        if ($job->salary)          $lines[] = "💰 {$job->salary}";
        if ($job->target_college)  $lines[] = "🎓 For: {$targets}";
        $lines[] = "📅 Apply by: {$this->shareJobDeadline}";
        $lines[] = "";
        $lines[] = "See full details & apply on the PHILCST Alumni Portal 👇";
        $lines[] = $baseUrl;

        $body = implode("\n", $lines);

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $roomId,
            'sender_type' => 'director',
            'sender_id'   => $dirRecord->id,
            'body'        => $body,
            'reply_to_id' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('chat_mentions')->insert([
            'message_id'   => $msgId,
            'mention_type' => 'everyone',
            'mentioned_id' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        if ($job->organizer_id) {
            $org = DB::table('organizer')
                ->where('id', $job->organizer_id)
                ->whereNull('deleted_at')
                ->first(['id']);
            if ($org) {
                DB::table('chat_mentions')->insert([
                    'message_id'   => $msgId,
                    'mention_type' => 'coordinator',
                    'mentioned_id' => $org->id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $this->dispatch('flash-message', type: 'success', message: 'Job posted to the Staff Channel! 💼');
        $this->closeShareJobModal();
    }
};
?>

<div style="min-height:90vh;">

<style>
.dir-filter-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem !important;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}
.dir-filter-select:hover  { border-color: #7a3f91 !important; }
.dir-filter-select:focus  { outline: none; border-color: #7a3f91 !important; box-shadow: 0 0 0 3px rgba(122,63,145,.12) !important; }

@keyframes dirModalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.d-m-in { animation: dirModalIn .2s cubic-bezier(.25,.8,.25,1) both; }

@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.fade-in { animation: fadeIn .2s ease both; }

[x-cloak] { display: none !important; }
</style>

{{-- ── FLASH TOAST ─────────────────────────────────────────────────────────── --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,
             display(t,m){this.type=t;this.msg=m;this.show=true;
             clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show" x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-6 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-6"
    class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-2xl max-w-sm w-full border-l-4 bg-white"
    :class="{'border-emerald-500':type==='success','border-red-500':type==='error','border-blue-500':type==='info','border-amber-500':type==='warning'}"
    style="display:none">
    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info','bg-amber-100':type==='warning'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" style="color:#333333;" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 leading-snug break-words" style="color:#666666;" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-700 transition flex-shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

<div class="px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto space-y-5">

    {{-- ── HEADER ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0"
             style="box-shadow:0 4px 14px rgba(122,63,145,.25)">
            <i class="fas fa-briefcase text-white text-sm"></i>
        </div>
        <div class="flex-1">
            <h1 class="text-2xl font-semibold leading-tight" style="color:#333333;">Job Overview</h1>
            <p class="text-sm mt-0.5 font-normal" style="color:#999999;">Review, moderate, and manage all job postings.</p>
        </div>
        <button wire:click="openPostModal"
                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-[#7a3f91] hover:bg-[#5e2f72] transition shadow-md shrink-0">
            <i class="fas fa-plus text-xs"></i> Post a Job
        </button>
    </div>

    {{-- ── MAIN CARD ────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden flex flex-col"
         style="height: calc(100vh - 175px); min-height: 500px;">

        {{-- ── Filter Bar ─────────────────────────────────────────────────── --}}
        <div class="px-4 sm:px-5 py-3 border-b border-gray-200 bg-white flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       style="color:#333333;"
                       autocomplete="off" maxlength="100">
            </div>

            <select wire:model.live="filterStatus"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333; min-width:150px;">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <select wire:model.live="filterType"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:160px;">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort"
                    class="dir-filter-select px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition hidden sm:block"
                    style="color:#333333; min-width:130px;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="px-3 py-2.5 rounded-lg border border-gray-200 bg-white text-sm font-semibold hover:bg-gray-50 transition flex items-center gap-1.5"
                    style="color:#666666;">
                <i class="fas fa-rotate-left text-sm"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Mobile row 2 --}}
        <div class="px-4 py-2.5 border-b border-gray-200 bg-white flex gap-2 sm:hidden">
            <select wire:model.live="filterType"
                    class="dir-filter-select flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort"
                    class="dir-filter-select flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none transition"
                    style="color:#333333;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── Table ──────────────────────────────────────────────────────── --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;"
                 wire:loading.class="opacity-50 pointer-events-none"
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,
                              previousPage,nextPage,executeToggle,executeDelete,executeRestore">
                <table class="w-full border-collapse min-w-[720px]">
                    <thead>
                        <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef] sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Job Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden lg:table-cell" style="color:#333333;">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:#333333;">Type</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider hidden xl:table-cell" style="color:#333333;">Deadline</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:#333333;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->jobPostings as $job)
                        @php
                            $isOrgDel         = $job->status === 'ORGANIZER_DELETED';
                            $isActive         = $job->status === 'ACTIVE';
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                        @endphp
                        <tr class="bg-white hover:bg-[#faf7fd] transition-colors duration-100">

                            {{-- Job Title --}}
                            <td class="px-4 sm:px-5 py-4 max-w-[180px] sm:max-w-[220px]">
                                <p class="font-semibold text-sm truncate {{ $isOrgDel ? 'line-through opacity-60' : '' }}" style="color:#333333;">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5" style="color:#999999;">{{ $job->created_at->diffForHumans() }}</p>
                            </td>

                            {{-- Coordinator --}}
                            <td class="px-4 sm:px-5 py-4 hidden lg:table-cell">
                                @if($organizerName)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5" style="color:#999999;">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold">
                                        <i class="fas fa-shield-halved" style="font-size:8px;"></i> Alumni Director
                                    </span>
                                @endif
                            </td>

                            {{-- Employment Type --}}
                            <td class="px-4 sm:px-5 py-4 hidden md:table-cell">
                                <span class="inline-block px-2.5 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            {{-- Deadline --}}
                            <td class="px-4 sm:px-5 py-4 hidden xl:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $dl->format('M d, Y') }}</p>
                                @if($isDeadlinePassed && !$isOrgDel)
                                    <p class="text-xs mt-0.5 font-semibold text-red-500">Closed</p>
                                @else
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $dl->diffForHumans() }}</p>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @if($isOrgDel)
                                    <span class="inline-block px-2.5 py-1.5 bg-red-100 text-red-700 border border-red-300 rounded-full text-xs font-semibold">Deleted by Org.</span>
                                @elseif($isActive)
                                    <span class="inline-block px-2.5 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold">Active</span>
                                @else
                                    <span class="inline-block px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-semibold">Inactive</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 sm:px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-[#7a3f91] bg-[#f5eef9] border border-[#d4aaeb] hover:bg-[#e9d5f3] rounded-lg transition">
                                        <i class="fas fa-eye text-xs"></i><span>View</span>
                                    </button>

                                    @if($isOrgDel)
                                        <button wire:click="confirmRestore({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-orange-600 bg-orange-50 border border-orange-200 hover:bg-orange-100 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-xs"></i><span>Restore</span>
                                        </button>

                                    @elseif($isActive)
                                        <button wire:click="openShareJobModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-lg transition">
                                            <i class="fas fa-share-nodes text-xs"></i><span>Share</span>
                                        </button>
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 rounded-lg transition">
                                            <i class="fas fa-ban text-xs"></i><span>Deactivate</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-blue-100 rounded-lg transition">
                                            <i class="fas fa-pen-to-square text-xs"></i><span>Edit</span>
                                        </button>

                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-lg transition">
                                            <i class="fas fa-circle-check text-xs"></i><span>Activate</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-blue-100 rounded-lg transition">
                                            <i class="fas fa-pen-to-square text-xs"></i><span>Edit</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-[#f5eef9] rounded-full flex items-center justify-center">
                                        <i class="fas fa-briefcase text-2xl text-[#c49dd8]"></i>
                                    </div>
                                    <p class="font-semibold text-base" style="color:#666666;">No job postings found</p>
                                    <p class="text-sm" style="color:#999999;">
                                        @if($search || $filterStatus || $filterType) Try adjusting your filters.
                                        @else No postings yet. Click <strong>Post a Job</strong> to create one.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Pagination ──────────────────────────────────────────────────── --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-200 shrink-0" style="background:#7a3f91;">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-sm" style="color:rgba(255,255,255,.8);">
                    Showing <strong class="text-white">{{ $from }}–{{ $to }}</strong>
                    of <strong class="text-white">{{ $total }}</strong>
                    job{{ $total !== 1 ? 's' : '' }}
                    @if($filterStatus || $filterType || $search)<span class="text-xs ml-1" style="color:rgba(255,255,255,.5);">(filtered)</span>@endif
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">← Prev</button>
                    @endif
                    <span class="px-4 py-2 bg-white rounded-lg text-sm font-semibold shadow-sm" style="color:#333333;">{{ $cp }} / {{ $this->jobPostings->lastPage() }}</span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════════════════════════════
     FULL SCREEN OVERLAY: Post New Job
     (Same pattern as Register Coordinator in manage-coordinator)
════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closePostModal()">

    {{-- Top bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs">Fill in all required fields below</p>
            </div>
        </div>
        <button wire:click="closePostModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Scrollable body --}}
    <div class="flex-1 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- Error banner --}}
            @if(count($postErrors))
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 shadow-sm">
                <p class="font-semibold text-sm text-red-900 mb-2 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-500"></i> Please fix the following:
                </p>
                <ul class="text-sm space-y-1 text-red-800">
                    @foreach($postErrors as $err)
                        <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Job Title --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-briefcase text-[#7a3f91] text-xs"></i> Job Title
                    </h3>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Job Title <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postJobTitle'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                           style="color:#333333;">
                    @if(isset($postErrors['postJobTitle']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postJobTitle'] }}</span></p>@endif
                </div>
            </div>

            {{-- Organization Section --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building text-[#7a3f91] text-xs"></i> Organization Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')"
                                    class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='philcst' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                    style="{{ $postOrgCategory!=='philcst' ? 'color:#666666;' : '' }}">
                                <i class="fas fa-school text-base"></i><span>PHILCST</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')"
                                    class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='partner' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                    style="{{ $postOrgCategory!=='partner' ? 'color:#666666;' : '' }}">
                                <i class="fas fa-handshake text-base"></i><span>Partner</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')"
                                    class="flex-1 py-3 px-3 border-2 rounded-xl text-sm font-semibold transition flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='custom' ? 'border-[#7a3f91] bg-[#7a3f91] text-white' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-[#f5eef9] bg-white' }}"
                                    style="{{ $postOrgCategory!=='custom' ? 'color:#666666;' : '' }}">
                                <i class="fas fa-pen-to-square text-base"></i><span>Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postOrgCategory'] }}</span></p>@endif
                    </div>

                    @if($postOrgCategory === 'philcst')
                        @if($philcstName)
                        <div class="flex items-center gap-3 bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3">
                            <div class="w-9 h-9 rounded-lg bg-[#7a3f91] text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-school text-sm"></i></div>
                            <div class="flex-1">
                                <div class="text-sm font-semibold" style="color:#5e2f72;">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-xs mt-0.5" style="color:#7a3f91;"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-[#7a3f91] bg-white border border-[#d4aaeb] px-2.5 py-1 rounded-full shrink-0">
                                <i class="fas fa-lock text-[9px]"></i> Auto-filled
                            </span>
                        </div>
                        @endif

                    @elseif($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),syncName(v){$wire.set('postPartnerName',v,false)},syncType(v){$wire.set('postPartnerType',v,false)}}">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text" placeholder="e.g. Acme Corporation" maxlength="150"
                                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postPartnerName'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postPartnerName']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text" placeholder="e.g. Private Company" maxlength="100"
                                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postPartnerType'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postPartnerType']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postLocation']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>

                    @elseif($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),syncName(v){$wire.set('postCustomName',v,false)},syncType(v){$wire.set('postCustomType',v,false)}}">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postCustomName'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postCustomName']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text" placeholder="e.g. Government Agency" maxlength="100"
                                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postCustomType'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postCustomType']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postLocation']))<p class="mt-1 text-sm text-red-600 flex items-start gap-1"><i class="fas fa-circle-exclamation mt-0.5"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>

                    @else
                        <div class="text-center py-6" style="color:#999999;">
                            <i class="fas fa-arrow-up text-3xl block mb-2 text-gray-200"></i>
                            <p class="text-sm">Select a category above to continue.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Job Details Section --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-circle-info text-[#7a3f91] text-xs"></i> Job Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <select wire:model.defer="postEmpType"
                                    class="dir-filter-select w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postEmpType'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                    style="color:#333333;">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postEmpType']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postEmpType'] }}</span></p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                            <select wire:model.defer="postExpLevel"
                                    class="dir-filter-select w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postExpLevel'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                    style="color:#333333;">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}">{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postExpLevel']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postExpLevel'] }}</span></p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                Salary <span class="font-normal normal-case text-gray-400">(Optional)</span>
                            </label>
                            <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / mo" maxlength="100"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   style="color:#333333;">
                            <p class="mt-1 text-xs text-gray-400"><i class="fas fa-circle-info mr-1"></i>Leave blank if not disclosed.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Application Deadline <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postDeadline'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postDeadline']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postDeadline'] }}</span></p>@endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Target Colleges Section --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building-columns text-[#7a3f91] text-xs"></i> Target Colleges <span class="text-red-500 text-xs">*</span>
                    </h3>
                </div>
                <div class="p-6 space-y-3">
                    <label class="flex items-center gap-2 px-3 py-2.5 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                        <input type="checkbox" wire:model.live="postAllColleges" class="w-4 h-4" style="accent-color:#7a3f91;">
                        <span class="text-sm font-semibold" style="color:#5e2f72;">All Colleges</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach($this->collegesWithDepts as $college)
                            <label class="flex items-center gap-2 px-3 py-2.5 border rounded-xl cursor-pointer transition text-sm font-semibold {{ in_array($college['name'], $postTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40' }}"
                                   style="{{ !in_array($college['name'], $postTargetColleges) ? 'color:#666666;' : '' }}">
                                <input type="checkbox" wire:model.live="postTargetColleges" value="{{ $college['name'] }}"
                                       class="w-4 h-4" style="accent-color:#7a3f91;">
                                <span class="truncate">{{ $college['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if(isset($postErrors['postTargetColleges']))<p class="text-sm text-red-600 flex items-start gap-1.5 mt-1"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postTargetColleges'] }}</span></p>@endif
                </div>
            </div>

            {{-- Description --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-align-left text-[#7a3f91] text-xs"></i> Job Description <span class="text-red-500 text-xs">*</span>
                    </h3>
                </div>
                <div class="p-6">
                    <textarea wire:model.defer="postDescription" rows="7" maxlength="5000"
                              placeholder="Describe the role, responsibilities, qualifications…"
                              class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($postErrors['postDescription'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }} resize-none"
                              style="color:#333333;"></textarea>
                    @if(isset($postErrors['postDescription']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $postErrors['postDescription'] }}</span></p>@endif
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="flex flex-wrap gap-3 pb-2">
                <button type="button" wire:click="closePostModal"
                        class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading wire:target="savePost"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                    <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane mr-1"></i>Post Job</span>
                </button>
            </div>

        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: View Job  (kept as slide-over — white hero, no purple bg)
════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $vj          = $this->viewingJob;
    $isOrgDel    = $vj->status === 'ORGANIZER_DELETED';
    $isActive    = $vj->status === 'ACTIVE';
    $vDl         = \Carbon\Carbon::parse($vj->deadline)->setTimezone('Asia/Manila');
    $vIsExp      = now('Asia/Manila')->gt($vDl);
    $vCreatedPH  = \Carbon\Carbon::parse($vj->created_at)->setTimezone('Asia/Manila');
    $vUpdatedPH  = \Carbon\Carbon::parse($vj->updated_at)->setTimezone('Asia/Manila');
    $displayType = ($vj->company_type === $vj->company_name) ? 'PHILCST' : $vj->company_type;
    $vOrgName    = $vj->organizer?->name ?? null;
    $vOrgCollege = null;
    if ($vj->organizer) {
        $vOrgCollege = \App\Models\Course::where('college', $vj->organizer->department)->value('college')
            ?? $vj->organizer->department ?? null;
    }
@endphp
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeViewModal(), 290)">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"></div>

    {{-- Panel --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Panel Header --}}
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] text-white flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-briefcase text-white text-sm"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold leading-tight truncate">{{ $vj->job_title }}</h2>
                    <p class="text-xs mt-0.5" style="color:rgba(255,255,255,.7);">Posted {{ $vCreatedPH->diffForHumans() }}</p>
                </div>
            </div>
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none flex-shrink-0 ml-3">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Scrollable Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            {{-- Job Info Banner — WHITE, no purple gradient --}}
            <div class="px-6 py-5 border-b border-gray-100 bg-white">
                @if($isOrgDel)
                    <div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5 mb-4 text-sm font-semibold text-red-700">
                        <i class="fas fa-trash shrink-0 text-red-500"></i>
                        Deleted by <strong>{{ $vj->deleted_by ?? $vj->organizer?->name ?? 'Coordinator' }}</strong>
                        · {{ $vUpdatedPH->format('M d, Y') }}
                    </div>
                @endif
                <div class="flex items-start justify-between gap-3 mb-4">
                    <h2 class="text-xl font-bold leading-snug" style="color:#1a1a1a;">{{ $vj->job_title }}</h2>
                    @if($isOrgDel)
                        <span class="flex-shrink-0 px-3 py-1.5 bg-red-100 text-red-700 border border-red-300 rounded-full text-xs font-semibold">Deleted by Org.</span>
                    @elseif($isActive)
                        <span class="flex-shrink-0 px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold">Active</span>
                    @else
                        <span class="flex-shrink-0 px-3 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-semibold">Inactive</span>
                    @endif
                </div>
                <ul class="space-y-2">
                    <li class="flex items-center gap-2.5 text-sm font-semibold" style="color:#333333;">
                        <i class="fas fa-building w-4 text-[#7a3f91]"></i>
                        {{ $vj->company_name }}
                        @if($displayType !== 'PHILCST')<span class="text-gray-400 text-xs font-normal">· {{ $displayType }}</span>@endif
                    </li>
                    @if($vj->location)<li class="flex items-center gap-2.5 text-sm" style="color:#555555;"><i class="fas fa-location-dot w-4 text-[#7a3f91]"></i>{{ $vj->location }}</li>@endif
                    <li class="flex items-center gap-2.5 text-sm" style="color:#555555;"><i class="fas fa-clock w-4 text-[#7a3f91]"></i>{{ $vj->employment_type }}</li>
                    <li class="flex items-center gap-2.5 text-sm" style="color:#555555;"><i class="fas fa-layer-group w-4 text-[#7a3f91]"></i>{{ $vj->experience_level }}</li>
                    @if($vj->salary)<li class="flex items-center gap-2.5 text-sm" style="color:#555555;"><i class="fas fa-money-bill-wave w-4 text-[#7a3f91]"></i>{{ $vj->salary }}</li>@endif
                    <li class="flex items-center gap-2.5 text-sm {{ $vIsExp ? 'text-red-600 font-semibold' : '' }}" style="{{ !$vIsExp ? 'color:#555555;' : '' }}">
                        <i class="fas fa-calendar-xmark w-4 {{ $vIsExp ? 'text-red-500' : 'text-[#7a3f91]' }}"></i>
                        Apply by {{ $vDl->format('F d, Y') }}
                        @if($vIsExp)<span class="text-xs ml-1 text-red-500">(Passed)</span>@else<span class="text-xs ml-1 text-gray-400">· {{ $vDl->diffForHumans() }}</span>@endif
                    </li>
                    @if($vj->target_college)
                        <li class="flex items-center gap-2.5 text-sm" style="color:#555555;">
                            <i class="fas fa-building-columns w-4 text-[#7a3f91]"></i>
                            For: {{ str_replace(',', ', ', $vj->target_college) }}
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Description --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Job Description</h3>
                <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $vj->description }}</p>
            </div>

            {{-- Status --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Status</h3>
                @if($isOrgDel)
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-red-800"><i class="fas fa-trash mr-2 text-red-500"></i>Deleted by Coordinator</p>
                        <p class="text-sm text-red-600 mt-1">You can restore this job posting back to Active.</p>
                    </div>
                @elseif($isActive)
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800"><i class="fas fa-circle-check mr-2 text-emerald-500"></i>Active</p>
                        <p class="text-sm text-emerald-700 mt-1">This job is visible to alumni and accepting applications.</p>
                    </div>
                @else
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                        <p class="text-sm font-semibold text-amber-800"><i class="fas fa-ban mr-2 text-amber-500"></i>Inactive</p>
                        <p class="text-sm text-amber-700 mt-1">This job is hidden from alumni. Activate to make it visible again.</p>
                    </div>
                @endif
            </div>

            {{-- Posting Details --}}
            <div class="px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#999999;">Posting Details</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 border border-gray-200 rounded-xl overflow-hidden divide-x divide-y divide-gray-100">
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Submitted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $vCreatedPH->format('M d, Y') }}</p>
                        <p class="text-sm font-semibold mt-0.5" style="color:#555555;">{{ $vCreatedPH->format('g:i A') }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Posted By</p>
                        @if($vOrgName)
                            <p class="text-sm font-semibold" style="color:#333333;">{{ $vOrgName }}</p>
                            @if($vOrgCollege)<p class="text-xs font-semibold mt-0.5" style="color:#7a3f91;">{{ $vOrgCollege }}</p>@endif
                        @else
                            <p class="text-sm font-semibold" style="color:#333333;">{{ $this->myDisplayName }}</p>
                            <p class="text-xs font-semibold mt-0.5" style="color:#7a3f91;">Alumni Director</p>
                        @endif
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1.5" style="color:#999999;">Last Updated</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $vUpdatedPH->format('M d, Y') }}</p>
                        <p class="text-xs font-semibold mt-0.5" style="color:#555555;">{{ $vUpdatedPH->diffForHumans() }}</p>
                        @if($vj->updated_by)
                            <p class="text-xs mt-1 font-semibold" style="color:#7a3f91;">{{ $vj->updated_by }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Panel Footer --}}
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-2 flex-wrap bg-white flex-shrink-0">
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 rounded-xl transition"
                    style="color:#666666;">
                <i class="fas fa-xmark text-sm"></i> Close
            </button>

            @if($isOrgDel)
                <button wire:click="confirmDelete({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                <button wire:click="confirmRestore({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-orange-600 border border-orange-200 bg-white hover:bg-orange-50 rounded-xl transition">
                    <i class="fas fa-rotate-left text-sm"></i> Restore
                </button>
            @else
                <button wire:click="confirmDelete({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition">
                    <i class="fas fa-trash text-sm"></i> Delete
                </button>
                @if($isActive)
                    <button wire:click="openShareJobModal({{ $vj->id }})"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-xl transition">
                        <i class="fas fa-share-nodes text-sm"></i> Share
                    </button>
                    <button wire:click="confirmToggle({{ $vj->id }})"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 rounded-xl transition">
                        <i class="fas fa-ban text-sm"></i> Deactivate
                    </button>
                @else
                    <button wire:click="confirmToggle({{ $vj->id }})"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 rounded-xl transition">
                        <i class="fas fa-circle-check text-sm"></i> Activate
                    </button>
                @endif
                <button wire:click="openEditModal({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-white bg-[#7a3f91] hover:bg-[#5e2f72] rounded-xl transition shadow-sm">
                    <i class="fas fa-pen-to-square text-sm"></i> Edit
                </button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     FULL SCREEN OVERLAY: Edit Job
     (Same pattern as Register Coordinator in manage-coordinator)
════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeEditModal()">

    {{-- Top bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Edit Job Posting</h2>
                <p class="text-white/60 text-xs">Update the fields below then save your changes</p>
            </div>
        </div>
        <button wire:click="closeEditModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Scrollable body --}}
    <div class="flex-1 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- Deadline warning --}}
            @php $editJobDeadlinePassed = $editDeadline && \Carbon\Carbon::createFromFormat('Y-m-d', $editDeadline, 'Asia/Manila')->endOfDay() < now('Asia/Manila'); @endphp
            @if($editJobDeadlinePassed)
            <div class="flex items-center gap-2 p-4 rounded-2xl bg-amber-50 border border-amber-200 shadow-sm">
                <i class="fas fa-triangle-exclamation text-amber-500 text-sm flex-shrink-0"></i>
                <p class="text-sm text-amber-800 font-semibold">Deadline has already passed. Update it to re-activate this job.</p>
            </div>
            @endif

            {{-- Error banner --}}
            @if(count($editErrors))
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 shadow-sm">
                <p class="font-semibold text-sm text-red-900 mb-2 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-500"></i> Please fix the following:
                </p>
                <ul class="text-sm space-y-1 text-red-800">
                    @foreach($editErrors as $err)
                        <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Job Title --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-briefcase text-[#7a3f91] text-xs"></i> Job Title
                    </h3>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Job Title <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                           class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editJobTitle'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                           style="color:#333333;">
                    @if(isset($editErrors['editJobTitle']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editJobTitle'] }}</span></p>@endif
                </div>
            </div>

            {{-- Organization Details --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building text-[#7a3f91] text-xs"></i> Organization Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCompanyType"
                                    class="dir-filter-select w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editCompanyType'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                    style="color:#333333;">
                                <option value="">Select Organization</option>
                                @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editCompanyType']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editCompanyType'] }}</span></p>@endif
                        </div>
                        <div>
                            @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Company Name <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editCompany" type="text" maxlength="150"
                                   @if($editIsPhilcst) readonly @endif
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editCompany'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }} {{ $editIsPhilcst?'cursor-not-allowed opacity-60':'' }}"
                                   style="color:#333333;">
                            @if(isset($editErrors['editCompany']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editCompany'] }}</span></p>@endif
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                        <input wire:model="editLocation" type="text" maxlength="120"
                               @if($editIsPhilcst) readonly @endif
                               class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editLocation'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }} {{ $editIsPhilcst?'cursor-not-allowed opacity-60':'' }}"
                               style="color:#333333;">
                        @if(isset($editErrors['editLocation']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editLocation'] }}</span></p>@endif
                    </div>
                </div>
            </div>

            {{-- Job Details --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-circle-info text-[#7a3f91] text-xs"></i> Job Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editEmpType"
                                    class="dir-filter-select w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editEmpType'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                    style="color:#333333;">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editEmpType']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editEmpType'] }}</span></p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editExpLevel"
                                    class="dir-filter-select w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editExpLevel'])?'border-red-400 bg-red-50':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                    style="color:#333333;">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editExpLevel']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editExpLevel'] }}</span></p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                Salary <span class="font-normal normal-case text-gray-400">(Optional)</span>
                            </label>
                            <input wire:model.defer="editSalary" type="text" maxlength="100"
                                   placeholder="e.g. ₱25,000 – ₱35,000 / month"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   style="color:#333333;">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Application Deadline <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editDeadline'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }}"
                                   style="color:#333333;">
                            @if(isset($editErrors['editDeadline']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editDeadline'] }}</span></p>@endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Target Colleges --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building-columns text-[#7a3f91] text-xs"></i> Target Colleges <span class="text-red-500 text-xs">*</span>
                    </h3>
                </div>
                <div class="p-6 space-y-3">
                    <label class="flex items-center gap-2 px-3 py-2.5 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                        <input type="checkbox" wire:model.live="editAllColleges" class="w-4 h-4" style="accent-color:#7a3f91;">
                        <span class="text-sm font-semibold" style="color:#5e2f72;">All Colleges</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach($this->collegesWithDepts as $college)
                            <label class="flex items-center gap-2 px-3 py-2.5 border rounded-xl cursor-pointer transition text-sm font-semibold {{ in_array($college['name'], $editTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40' }}"
                                   style="{{ !in_array($college['name'], $editTargetColleges) ? 'color:#666666;' : '' }}">
                                <input type="checkbox" wire:model.live="editTargetColleges" value="{{ $college['name'] }}"
                                       class="w-4 h-4" style="accent-color:#7a3f91;">
                                <span class="truncate">{{ $college['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if(isset($editErrors['editTargetColleges']))<p class="text-sm text-red-600 flex items-start gap-1.5 mt-1"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editTargetColleges'] }}</span></p>@endif
                </div>
            </div>

            {{-- Description --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-align-left text-[#7a3f91] text-xs"></i> Job Description <span class="text-red-500 text-xs">*</span>
                    </h3>
                </div>
                <div class="p-6">
                    <textarea wire:model.defer="editDescription" rows="7" maxlength="5000"
                              class="w-full px-4 py-3 border rounded-xl text-sm bg-white transition focus:outline-none focus:ring-2 {{ isset($editErrors['editDescription'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/10' }} resize-none"
                              style="color:#333333;"></textarea>
                    @if(isset($editErrors['editDescription']))<p class="mt-1.5 text-sm text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $editErrors['editDescription'] }}</span></p>@endif
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="flex flex-wrap gap-3 pb-2">
                <button type="button" wire:click="closeEditModal"
                        class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="saveEditJob"
                        wire:loading.attr="disabled" wire:target="saveEditJob"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                    <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk mr-1"></i>Save Changes</span>
                </button>
            </div>

        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Confirm Toggle (small — kept as modal) ════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelConfirm()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-50 border-b border-emerald-100' : 'bg-amber-50 border-b border-amber-100' }}">
            <h2 class="text-lg font-semibold {{ $confirmAction==='ACTIVE' ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-9 h-9 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-xl flex items-center justify-center">
                    <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check text-emerald-600' : 'ban text-amber-600' }} text-base"></i>
                </div>
                {{ $confirmAction === 'ACTIVE' ? 'Activate Job?' : 'Deactivate Job?' }}
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-5" style="color:#666666;">
                This job will be marked as <strong>{{ $confirmAction }}</strong>.
                @if($confirmAction==='INACTIVE') It will be hidden from alumni but can still be edited.@endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm" class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition" style="color:#333333;">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition {{ $confirmAction==='ACTIVE' ? 'bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300' : 'bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check' : 'ban' }} mr-1"></i>
                        {{ $confirmAction === 'ACTIVE' ? 'Yes, Activate' : 'Yes, Deactivate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Restore (small) ════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 bg-orange-50 border-b border-orange-100">
            <h2 class="text-lg font-semibold text-orange-800 flex items-center gap-2.5">
                <div class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center"><i class="fas fa-rotate-left text-orange-500 text-base"></i></div>
                Restore Job Posting
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1" style="color:#666666;">You are about to restore:</p>
            <p class="font-semibold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-sm text-blue-800 flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                <span>The job will be restored. If its deadline has passed it will be set to <strong>Inactive</strong> — update the deadline to re-activate it.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition" style="color:#333333;">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-4 py-3 bg-orange-500 hover:bg-orange-600 disabled:bg-orange-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i> Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete (small) ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden d-m-in">
        <div class="px-6 py-5 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-semibold text-lg">Permanently Delete</h2>
        </div>
        <div class="p-6">
            <div class="flex flex-col items-center text-center mb-5">
                <div class="w-16 h-16 bg-red-50 border-2 border-red-200 rounded-full flex items-center justify-center mb-3">
                    <i class="fas fa-triangle-exclamation text-red-500 text-2xl"></i>
                </div>
                <p class="font-semibold text-sm" style="color:#333333;">Are you sure you want to permanently delete</p>
                <p class="font-semibold text-lg mt-1 text-red-700">"{{ $deleteJobTitle }}"?</p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3.5 mb-5 space-y-1.5">
                <p class="text-sm font-semibold text-red-800 flex items-center gap-2"><i class="fas fa-circle-exclamation text-red-500"></i> This action cannot be undone.</p>
                <p class="text-sm text-red-700 pl-5">The job posting will be <strong>permanently removed</strong>.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition flex items-center justify-center gap-2" style="color:#333333;">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     SLIDE-OVER: Share Job
════════════════════════════════════════════════════════════════════════ --}}
@if($showShareJobModal)
@php
    $sjBaseUrl  = $this->jobsBaseUrl();
    $sjHost     = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $sjTargets  = $shareJobTarget ? str_replace(',', ', ', $shareJobTarget) : 'All Alumni';
    $sjDescPrev = mb_strlen($shareJobDescription) > 160 ? mb_substr($shareJobDescription, 0, 160) . '…' : $shareJobDescription;

    $sjLines = [
        "💼 Job Opportunity: {$shareJobTitle}",
        "🏢 {$shareJobCompany}" . ($shareJobLocation ? " · {$shareJobLocation}" : ''),
        "⏰ {$shareJobEmpType}" . ($shareJobExpLevel ? " · {$shareJobExpLevel}" : ''),
    ];
    if ($shareJobSalary)  $sjLines[] = "💰 {$shareJobSalary}";
    if ($shareJobTarget)  $sjLines[] = "🎓 For: {$sjTargets}";
    $sjLines[] = "📅 Apply by: {$shareJobDeadline}";
    $sjLines[] = '';
    if ($shareJobDescription) {
        $dPrev     = mb_strlen($shareJobDescription) > 200 ? mb_substr($shareJobDescription, 0, 200) . '…' : $shareJobDescription;
        $sjLines[] = $dPrev;
        $sjLines[] = '';
    }
    $sjLines[] = "See full details & apply on the PHILCST Alumni Portal 👇";
    $sjLines[] = $sjBaseUrl;
    $sjPostText = implode("\n", $sjLines);
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied:          false,
         fbCopied:        false,
         messengerCopied: false,
         fbText:  {{ json_encode($sjPostText) }},
         baseUrl: {{ json_encode($sjBaseUrl) }},

         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareJobModal(), 290);
         },

         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch(e) { return false; }
         },

         async shareOnFacebook() {
             await this.copyText(this.fbText);
             this.fbCopied = true;
             window.open('https://www.facebook.com/', '_blank', 'noopener,noreferrer');
             setTimeout(() => { this.fbCopied = false; }, 9000);
         },

         async shareOnMessenger() {
             await this.copyText(this.fbText);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/', '_blank', 'noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/', '_blank', 'noopener');
             }
             setTimeout(() => { this.messengerCopied = false; }, 9000);
         },

         async copyLinkFn() {
             await this.copyText(this.baseUrl);
             this.copied = true;
             setTimeout(() => this.copied = false, 2500);
         }
     }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="close()">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    {{-- Panel --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Panel Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-lg font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-sky-600 text-lg"></i>
                <span>Share Job Posting</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer"
                    style="color:#999999;">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        {{-- Two-column body --}}
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-semibold uppercase tracking-widest flex-shrink-0" style="color:#999999;">Post preview</p>

                {{-- Preview card --}}
                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4 flex items-start gap-4" style="background-color:#f9f7fc;">
                        <div class="w-16 h-16 rounded-xl flex items-center justify-center flex-shrink-0 shadow"
                             style="background: linear-gradient(135deg,#7a3f91,#5e2f72);">
                            <i class="fas fa-briefcase text-white text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareJobTitle }}</p>
                            <p class="text-sm mt-1 font-semibold" style="color:#555555;">{{ $shareJobCompany }}@if($shareJobLocation) · {{ $shareJobLocation }}@endif</p>
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-[#f5eef9]" style="color:#7a3f91;">
                                    <i class="fas fa-clock text-[10px]"></i>{{ $shareJobEmpType }}
                                </span>
                                @if($shareJobTarget)
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                    <i class="fas fa-building-columns text-[10px]"></i>{{ Str::limit($sjTargets, 30) }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if($sjDescPrev)
                    <div class="px-5 py-3.5 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#555555;">{{ $sjDescPrev }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#999999;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#666666;">{{ strtoupper($sjHost) }}</span>
                    </div>
                </div>

                {{-- How it works --}}
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the post caption to your clipboard and opens the platform.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste in the composer.
                        </p>
                    </div>
                </div>

                {{-- Staff chat info --}}
                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Staff Chat</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Posts the job announcement directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Share via</p>

                {{-- FB feedback --}}
                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">Text copied! Facebook is open.</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Click "What's on your mind?" and press <strong>Ctrl+V</strong>.</p>
                    </div>
                </div>

                {{-- Messenger feedback --}}
                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Text copied! Messenger is open.</p>
                        <p class="text-xs text-blue-700 mt-0.5">Open any chat and press <strong>Ctrl+V</strong>.</p>
                    </div>
                </div>

                {{-- Facebook button --}}
                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Post on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens facebook.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                {{-- Messenger button --}}
                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_job2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_job2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens messenger.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                {{-- Divider --}}
                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or post to staff</span>
                    </div>
                </div>

                {{-- Staff Chat button --}}
                <button type="button"
                        wire:click="postJobToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postJobToBatchChat"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postJobToBatchChat" class="block font-semibold text-sm">Post to Staff Chat</span>
                        <span wire:loading wire:target="postJobToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Directors &amp; Coordinators · caption included</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                {{-- Divider --}}
                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or copy link</span>
                    </div>
                </div>

                {{-- Copy Link button --}}
                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white"
                        style="color:#333333;">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#999999;">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                {{-- Close button --}}
                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1"
                        style="color:#666666;">
                    <i class="fas fa-xmark mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>