<?php
/**
 * FILE: resources/views/livewire/admin/job-posts.blade.php
 *
 * CHANGES:
 *  - Removed all row background colors (orange/red) — uniform white rows
 *  - Job Title, Organization, Deadline columns = pure black text always
 *  - Auto-inactive: expired ACTIVE jobs are set to INACTIVE on mount
 *  - Activating a job with passed deadline forces admin to update deadline first
 *  - AuditLog::create() calls on all job actions
 *  - Direct Eloquent instead of controller autoloads (Windows ClassLoader fix)
 *  - writeAuditLog() helper — non-blocking
 *  - ADMIN_DELETED jobs completely hidden from view
 *  - DELETED filter shows only ORGANIZER_DELETED jobs
 *  - [FIX] abort_unless now accepts both 'admin' and 'director' roles (403 fix)
 *  - [FIX] updated_by_role / deleted_by_role now use auth()->user()->role dynamically
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

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
    public array  $postTargetColleges = [];
    public array  $postErrors        = [];
    public bool   $postAllColleges   = false;

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
    public array  $editTargetColleges = [];
    public array  $editErrors        = [];
    public bool   $editAllColleges   = false;

    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    public bool   $showRestoreModal = false;
    public ?int   $restoreJobId     = null;
    public string $restoreJobTitle  = '';

    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // ROLE HELPER
    // ──────────────────────────────────────────────────────────────────────────
    private function authorizeRole(): void
    {
        abort_unless(
            auth()->check() && in_array(auth()->user()->role, ['admin', 'director']),
            403
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MOUNT
    // ──────────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $this->authorizeRole();

        // AUTO-INACTIVE: Set all ACTIVE jobs with passed deadlines to INACTIVE
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

            // Audit the auto-expiry batch
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

    // ──────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────────────────
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
                'user_name'     => auth()->user()?->name  ?? 'System',
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
        } catch (\Throwable) {
            // Audit failure must never surface to the user
        }
    }

    private function fetchJob(int $id): JobPosting
    {
        return JobPosting::with('organizer')->findOrFail($id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FILTER WATCHERS
    // ──────────────────────────────────────────────────────────────────────────
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

    // ──────────────────────────────────────────────────────────────────────────
    // COMPUTED PROPERTIES
    // ──────────────────────────────────────────────────────────────────────────
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

        if ($this->filterStatus === 'DELETED') {
            // Only show ORGANIZER_DELETED jobs in DELETED filter
            $q->where('status', 'ORGANIZER_DELETED');
        } elseif ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        } else {
            // Main view: show ACTIVE and INACTIVE only (ADMIN_DELETED completely hidden)
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
            ->filter()
            ->unique()
            ->values();

        $collegeMap = [];
        if ($depts->isNotEmpty()) {
            $collegeMap = Course::whereIn('college', $depts)
                ->distinct()
                ->pluck('college', 'college')
                ->toArray();
        }

        $now = now('Asia/Manila')->startOfDay();

        $paginated->getCollection()->transform(function ($job) use ($collegeMap, $now) {
            $dept                    = $job->organizer?->department;
            $job->_organizerCollege  = $dept ? ($collegeMap[$dept] ?? $dept) : null;
            $deadline                = \Carbon\Carbon::parse($job->deadline)
                                           ->setTimezone('Asia/Manila')
                                           ->startOfDay();
            $job->_isDeadlinePassed  = $deadline < $now;
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
            return Course::select('college')
                ->distinct()
                ->orderBy('college')
                ->get()
                ->map(fn($c) => ['name' => $c->college])
                ->values()
                ->toArray();
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FILTER RESET
    // ──────────────────────────────────────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST MODAL
    // ──────────────────────────────────────────────────────────────────────────
    public function openPostModal(): void
    {
        $this->authorizeRole();
        $this->resetPostFields();
        $this->postDeadline       = now()->setTimezone('Asia/Manila')->addMonth()->format('Y-m-d');
        $this->postTargetColleges = [];
        $this->postAllColleges    = false;
        $this->showPostModal      = true;
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
            'updated_by'       => auth()->user()->name,
            'updated_by_role'  => auth()->user()->role,
        ]);

        $this->writeAuditLog(
            action:      'created',
            description: "Admin posted new job: \"{$title}\" at {$companyName} ({$empType})",
            severity:    'info',
            subject:     $title,
            newValues:   [
                'job_id'           => $job->id,
                'job_title'        => $title,
                'company_name'     => $companyName,
                'company_type'     => $companyType,
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

    // ──────────────────────────────────────────────────────────────────────────
    // VIEW MODAL
    // ──────────────────────────────────────────────────────────────────────────
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

    // ──────────────────────────────────────────────────────────────────────────
    // EDIT MODAL
    // ──────────────────────────────────────────────────────────────────────────
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

        // If deadline was previously expired and is now future, re-activate
        $deadlineDate = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
        $shouldReactivate = $job->status === 'INACTIVE' && $deadlineDate >= now('Asia/Manila');

        $job->update(array_merge($newValues, [
            'description'     => $description,
            'updated_by'      => auth()->user()->name,
            'updated_by_role' => auth()->user()->role,
            'status'          => $shouldReactivate ? 'ACTIVE' : $job->status,
        ]));

        $this->writeAuditLog(
            action:      'updated',
            description: "Admin edited job: \"{$title}\" (ID {$job->id}) at {$company}"
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

    // ──────────────────────────────────────────────────────────────────────────
    // TOGGLE ACTIVE / INACTIVE
    // ──────────────────────────────────────────────────────────────────────────
    public function confirmToggle(int $id): void
    {
        $this->authorizeRole();
        $job = JobPosting::findOrFail($id);

        // If trying to activate, check deadline first
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

        // Double-check deadline before activating
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
            'updated_by'      => auth()->user()->name,
            'updated_by_role' => auth()->user()->role,
        ]);

        $label = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';

        $this->writeAuditLog(
            action:      'updated',
            description: "Admin {$label} job: \"{$job->job_title}\" (ID {$job->id})",
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

    // ──────────────────────────────────────────────────────────────────────────
    // DELETE
    // ──────────────────────────────────────────────────────────────────────────
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
            'deleted_by'      => auth()->user()?->name,
            'deleted_by_role' => auth()->user()?->role,
        ]);

        $this->writeAuditLog(
            action:      'deleted',
            description: "Admin deleted job: \"{$this->deleteJobTitle}\" (ID {$job->id})",
            severity:    'critical',
            subject:     $this->deleteJobTitle,
            oldValues:   $snapshot,
            newValues:   ['status' => 'ADMIN_DELETED', 'deleted_by' => auth()->user()?->name],
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

    // ──────────────────────────────────────────────────────────────────────────
    // RESTORE
    // ──────────────────────────────────────────────────────────────────────────
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

        // If deadline already passed, restore as INACTIVE and notify admin to update
        $deadline = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
        $today    = now('Asia/Manila')->startOfDay();
        $restoreStatus = ($today > $deadline) ? 'INACTIVE' : 'ACTIVE';

        $job->update([
            'status'          => $restoreStatus,
            'deleted_by'      => null,
            'deleted_by_role' => null,
            'updated_by'      => 'Restored by ' . auth()->user()->name,
            'updated_by_role' => auth()->user()->role,
        ]);

        $this->writeAuditLog(
            action:      'updated',
            description: "Admin restored job: \"{$this->restoreJobTitle}\" (ID {$job->id}) → {$restoreStatus}",
            severity:    'info',
            subject:     $this->restoreJobTitle,
            oldValues:   ['status' => $oldStatus],
            newValues:   ['status' => $restoreStatus, 'restored_by' => auth()->user()->name],
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
};
?>

<div class=" min-height:90vh; ">
<style>
:root {
    --brand:     #7a3f91;
    --brand-d:   #5e2f72;
    --brand-50:  #f5eef9;
    --brand-100: #e9d5f3;
    --brand-200: #d4aaeb;
}

@keyframes modalIn { from { opacity:0; transform:translateY(16px) scale(.96) } to { opacity:1; transform:none } }
.m-in { animation:modalIn .22s cubic-bezier(.25,.8,.25,1) both; }

@keyframes spin { from{transform:rotate(0)}to{transform:rotate(360deg)} }
.spin { animation:spin 1s linear infinite; }

.scroll-c::-webkit-scrollbar { width:5px; height:5px; }
.scroll-c::-webkit-scrollbar-track { background:#f3f4f6; border-radius:99px; }
.scroll-c::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background:#9b5bb0; }

.tbl-load { opacity:.4; pointer-events:none; transition:opacity .2s; }

/* Uniform white rows — no background color differences */
.tbl-row { background:#fff; transition:background .1s; }
.tbl-row:hover { background:#fafafa; }
</style>

{{-- FLASH TOAST --}}
<div x-data="{
        show:false,type:'success',msg:'',timer:null,
        display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{
        'bg-white border-emerald-300 text-emerald-800': type==='success',
        'bg-white border-blue-300 text-blue-800':      type==='info',
        'bg-white border-amber-300 text-amber-800':    type==='warning',
        'bg-white border-red-300 text-red-800':        type==='error'
     }"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-75 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- PAGE WRAPPER --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-8 pt-5 pb-8 max-w-screen-2xl mx-auto">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 sm:w-13 sm:h-13 rounded-xl bg-[#7a3f91] flex items-center justify-center shadow-md flex-shrink-0"
                 style="box-shadow:0 4px 14px rgba(122,63,145,.35);">
                <i class="fas fa-briefcase text-white text-base sm:text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 tracking-tight">Job Management</h1>
                <p class="text-gray-500 text-xs mt-0.5">Review, moderate, and manage all job postings.</p>
            </div>
        </div>
        <button wire:click="openPostModal"
                class="inline-flex items-center justify-center gap-1.5 font-bold rounded-xl transition cursor-pointer border-none outline-none px-4 py-2.5 text-sm bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72] hover:shadow-lg shrink-0">
            <i class="fas fa-plus text-xs"></i> Post a Job
        </button>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl border border-gray-200 flex flex-col overflow-hidden"
         style="box-shadow:0 4px 12px rgba(0,0,0,0.10), 0 2px 4px rgba(0,0,0,0.06); min-height:0; height:calc(100vh - 195px);">

        {{-- FILTER BAR --}}
        <div class="px-4 sm:px-6 py-3 border-b border-gray-200 bg-gray-50 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-900 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition"
                       autocomplete="off" maxlength="100">
            </div>

            <select wire:model.live="filterStatus"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="DELETED">Deleted by organizer</option>
            </select>

            <select wire:model.live="filterType"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-100 transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-600 hover:bg-gray-100 transition flex items-center gap-1.5">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- TABLE --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto scroll-c"
                 wire:loading.class="tbl-load"
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeToggle,executeDelete,executeRestore">
                <table class="w-full border-collapse min-w-[860px]">
                    <thead>
                        <tr class="sticky top-0 z-10 bg-gray-100 border-b-2 border-gray-300">
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Job Title</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Organization</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden lg:table-cell">Organizer</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Type</th>
                            <th class="px-4 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden xl:table-cell">Deadline</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->jobPostings as $job)
                        @php
                            $isOrgDel   = $job->status === 'ORGANIZER_DELETED';
                            $isDel      = $isOrgDel;
                            $dl         = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                        @endphp
                        <tr class="tbl-row">
                            {{-- Job Title — pure black always --}}
                            <td class="px-4 sm:px-5 py-3 max-w-[170px] sm:max-w-[210px]">
                                <p class="font-semibold text-sm truncate text-gray-900 {{ $isDel ? 'line-through' : '' }}">
                                    {{ $job->job_title }}
                                </p>
                            </td>
                            {{-- Organization — pure black always --}}
                            <td class="px-4 sm:px-5 py-3 max-w-[155px]">
                                <p class="font-medium text-sm truncate text-gray-900">
                                    {{ $job->company_name }}
                                </p>
                            </td>
                            {{-- Organizer --}}
                            <td class="px-4 sm:px-5 py-3 hidden lg:table-cell min-w-[160px]">
                                @if($organizerName)
                                    <div class="text-sm font-bold text-gray-900 line-clamp-1">{{ $organizerName }}</div>
                                    @if($organizerCollege)
                                        <div class="text-xs font-semibold text-[#7a3f91] mt-0.5 flex items-center gap-1">
                                            <i class="fas fa-building-columns" style="font-size:8px;"></i>
                                            {{ $organizerCollege }}
                                        </div>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-600 border border-purple-100 rounded-full text-xs font-bold">
                                        <i class="fas fa-shield-halved text-[8px]"></i> Admin
                                    </span>
                                @endif
                            </td>
                            {{-- Employment type --}}
                            <td class="px-4 sm:px-5 py-3 hidden md:table-cell">
                                <span class="inline-block px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-100 rounded-full text-xs font-semibold">
                                    {{ $job->employment_type }}
                                </span>
                            </td>
                            {{-- Deadline — pure black always --}}
                            <td class="px-4 sm:px-5 py-3 hidden xl:table-cell whitespace-nowrap">
                                <span class="text-sm font-medium text-gray-900">
                                    {{ $dl->format('M d, Y') }}
                                </span>
                                @if($isDeadlinePassed && !$isDel)
                                    <span class="block text-xs text-red-500 font-semibold mt-0.5">Expired</span>
                                @endif
                            </td>
                            {{-- Status --}}
                            <td class="px-4 sm:px-5 py-3 text-center whitespace-nowrap">
                                @if($isDel)
                                    <span class="inline-block px-3 py-1 bg-red-50 text-red-700 border border-red-200 rounded-full text-xs font-bold">Deleted</span>
                                @elseif($job->status === 'ACTIVE')
                                    <span class="inline-block px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">Active</span>
                                @else
                                    <span class="inline-block px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold">Inactive</span>
                                @endif
                            </td>
                            {{-- Actions --}}
                            <td class="px-4 sm:px-5 py-3 text-center">
                                <div class="flex items-center justify-center gap-2 flex-wrap">
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="px-3 py-1.5 text-xs font-semibold text-purple-600 hover:text-purple-800 bg-purple-50 border border-purple-300 rounded-lg hover:bg-purple-100 transition cursor-pointer">
                                        <i class="fas fa-eye text-xs mr-1"></i>View
                                    </button>

                                    @if($isDel)
                                        <button wire:click="confirmRestore({{ $job->id }})"
                                                class="px-3 py-1.5 text-xs font-semibold text-orange-600 hover:text-orange-800 bg-orange-50 border border-orange-300 rounded-lg hover:bg-orange-100 transition cursor-pointer">
                                            <i class="fas fa-rotate-left text-xs mr-1"></i>Restore
                                        </button>
                                    @elseif($job->status === 'ACTIVE')
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="px-3 py-1.5 text-xs font-semibold text-orange-600 hover:text-orange-800 bg-orange-50 border border-orange-300 rounded-lg hover:bg-orange-100 transition cursor-pointer">
                                            <i class="fas fa-ban text-xs mr-1"></i>Deactivate
                                        </button>
                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="px-3 py-1.5 text-xs font-semibold text-green-600 hover:text-green-800 bg-green-50 border border-green-300 rounded-lg hover:bg-green-100 transition cursor-pointer">
                                            <i class="fas fa-circle-check text-xs mr-1"></i>Activate
                                        </button>
                                    @endif

                                    @if(!$isDel)
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="px-3 py-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 border border-blue-300 rounded-lg hover:bg-blue-100 transition cursor-pointer">
                                            <i class="fas fa-pen-to-square text-xs mr-1"></i>Edit
                                        </button>
                                    @endif

                                    <button wire:click="confirmDelete({{ $job->id }})"
                                            class="px-3 py-1.5 text-xs font-semibold text-red-600 hover:text-red-800 bg-red-50 border border-red-300 rounded-lg hover:bg-red-100 transition cursor-pointer">
                                        <i class="fas fa-trash text-xs mr-1"></i>Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-briefcase text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="font-semibold text-gray-400 text-sm">
                                        {{ $filterStatus === 'DELETED' ? 'No deleted job postings' : 'No job postings found' }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        @if($filterStatus === 'DELETED') Jobs deleted by organizers will appear here.
                                        @elseif($search||$filterStatus||$filterType) Try adjusting your filters.
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

        {{-- PAGINATION --}}
        <div class="px-4 sm:px-5 py-3 border-t border-gray-200 bg-[#2b0d3e] shrink-0 rounded-b-2xl">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp-1)*$pp+1 : 0;
                $to    = min($cp*$pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-white text-xs sm:text-sm">
                    Showing <span class="font-bold">{{ $from }}–{{ $to }}</span>
                    of <span class="font-bold">{{ $total }}</span> jobs
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72]">← Prev</button>
                    @endif
                    <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold shadow-sm">
                        {{ $cp }} / {{ $this->jobPostings->lastPage() }}
                    </span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="inline-flex items-center justify-center gap-1 font-bold px-3 sm:px-4 py-1.5 rounded-lg text-xs sm:text-sm bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72]">Next →</button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-1.5 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- POST MODAL -->
@if($showPostModal)
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="closePostModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <button wire:click="closePostModal" type="button" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg transition text-gray-400 hover:text-gray-600">
            <i class="fas fa-xmark text-lg"></i>
        </button>
        <div class="flex items-center justify-between px-6 sm:px-7 py-4 sm:py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-lg sm:text-xl font-extrabold text-white flex items-center gap-2.5">
                <i class="fas fa-briefcase text-sm"></i> Post a New Job
            </h2>
        </div>
        @if(count($postErrors))
        <div class="bg-red-50 border-b border-red-200 px-6 sm:px-7 py-3 flex-shrink-0">
            <p class="font-bold text-red-800 text-xs mb-1.5 flex items-center gap-1.5">
                <i class="fas fa-triangle-exclamation"></i> Please fix the following:
            </p>
            <ul class="text-red-700 text-xs space-y-1">
                @foreach($postErrors as $err)
                    <li class="flex items-start gap-1.5"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto scroll-c px-6 sm:px-7 py-5 space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Job Title</label>
                <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                       class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postJobTitle']) ? 'border-red-300 bg-red-50' : '' }}">
                @if(isset($postErrors['postJobTitle']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
            </div>
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-100 border-b border-gray-200 px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-building text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Organization Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 tracking-wide"><span class="text-red-500">*</span> Category</label>
                        <div class="flex gap-2 sm:gap-3">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')"
                                    class="flex-1 px-3 py-3 border-2 border-gray-200 rounded-lg bg-white cursor-pointer transition text-center text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50 flex flex-col items-center gap-1.5 text-xs font-bold {{ $postOrgCategory==='philcst' ? 'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md' : '' }}">
                                <i class="fas fa-school text-lg"></i><span>PHILCST</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')"
                                    class="flex-1 px-3 py-3 border-2 border-gray-200 rounded-lg bg-white cursor-pointer transition text-center text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50 flex flex-col items-center gap-1.5 text-xs font-bold {{ $postOrgCategory==='partner' ? 'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md' : '' }}">
                                <i class="fas fa-handshake text-lg"></i><span>Partner</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')"
                                    class="flex-1 px-3 py-3 border-2 border-gray-200 rounded-lg bg-white cursor-pointer transition text-center text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50 flex flex-col items-center gap-1.5 text-xs font-bold {{ $postOrgCategory==='custom' ? 'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md' : '' }}">
                                <i class="fas fa-pen-to-square text-lg"></i><span>Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-xs text-red-600 mt-2 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>
                    @if($postOrgCategory==='philcst')
                        @if($philcstName)
                        <div class="border-2 border-purple-100 rounded-lg px-4 py-3 flex items-center gap-3 bg-purple-50">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-school text-sm"></i></div>
                            <div class="flex-1">
                                <div class="text-sm font-bold text-[#4c1d95]">PHILCST</div>
                                @if($philcstLocation)<div class="text-xs text-[#7c3aed] mt-0.5"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-white border border-purple-200 px-2.5 py-1 rounded-full shrink-0">
                                <i class="fas fa-lock text-[9px]"></i> Auto-filled
                            </span>
                        </div>
                        @endif
                    @elseif($postOrgCategory==='partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),syncName(v){$wire.set('postPartnerName',v,false)},syncType(v){$wire.set('postPartnerType',v,false)}}">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Organization Name</label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text"
                                           placeholder="e.g. Acme Corporation" maxlength="150"
                                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postPartnerName'])?'border-red-300 bg-red-50':'' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Organization Type</label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text"
                                           placeholder="e.g. Private Company" maxlength="100"
                                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postPartnerType'])?'border-red-300 bg-red-50':'' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Location</label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text"
                                   placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                   class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postLocation'])?'border-red-300 bg-red-50':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postPartnerName))
                        <div class="border-2 border-blue-200 rounded-lg px-4 py-3 flex items-center gap-3 bg-blue-50">
                            <div class="w-9 h-9 rounded-lg bg-[#2557a7] text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-handshake text-sm"></i></div>
                            <div>
                                <div class="text-sm font-bold text-[#1e3a5f]">{{ $postPartnerName }}</div>
                                @if(trim($postPartnerType))<div class="text-xs text-[#2557a7] mt-0.5">{{ $postPartnerType }}</div>@endif
                                @if(trim($postLocation))<div class="text-xs text-gray-600 mt-1"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @elseif($postOrgCategory==='custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),syncName(v){$wire.set('postCustomName',v,false)},syncType(v){$wire.set('postCustomType',v,false)}}">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Organization Name</label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text"
                                           placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postCustomName'])?'border-red-300 bg-red-50':'' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Organization Type</label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text"
                                           placeholder="e.g. Government Agency" maxlength="100"
                                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postCustomType'])?'border-red-300 bg-red-50':'' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Location</label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text"
                                   placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postLocation'])?'border-red-300 bg-red-50':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postCustomName))
                        <div class="border-2 border-gray-300 rounded-lg px-4 py-3 flex items-center gap-3 bg-gray-50">
                            <div class="w-9 h-9 rounded-lg bg-gray-600 text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-pen-to-square text-sm"></i></div>
                            <div>
                                <div class="text-sm font-bold text-[#1e293b]">{{ $postCustomName }}</div>
                                @if(trim($postCustomType))<div class="text-xs text-gray-600 mt-0.5">{{ $postCustomType }}</div>@endif
                                @if(trim($postLocation))<div class="text-xs text-gray-600 mt-1"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif
                            </div>
                        </div>
                        @endif
                    @else
                    <div class="text-center py-5 text-gray-400 text-sm">
                        <i class="fas fa-arrow-up text-gray-300 text-xl block mb-2"></i>
                        Select a category above to continue.
                    </div>
                    @endif
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Employment Type</label>
                    <select wire:model.defer="postEmpType" class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postEmpType'])?'border-red-300 bg-red-50':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postEmpType']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Experience Level</label>
                    <select wire:model.defer="postExpLevel" class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postExpLevel'])?'border-red-300 bg-red-50':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}">{{ $lvl }}</option>
                        @endforeach
                    </select>
                    @if(isset($postErrors['postExpLevel']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide">Salary <span class="text-gray-500 font-normal">(Optional)</span></label>
                    <input wire:model.defer="postSalary" type="text"
                           placeholder="e.g. ₱25,000 – ₱35,000 / month" maxlength="100"
                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none">
                    <p class="text-xs text-gray-500 mt-0.5"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Application Deadline</label>
                    <input wire:model.defer="postDeadline" type="date"
                           min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($postErrors['postDeadline'])?'border-red-300 bg-red-50':'' }}">
                    @if(isset($postErrors['postDeadline']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-2 tracking-wide"><span class="text-red-500">*</span> Target Colleges</label>
                <div class="border-2 border-gray-300 rounded-lg p-4 bg-white">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="allColleges" wire:model.live="postAllColleges" class="w-4 h-4 rounded cursor-pointer accent-[#7a3f91]">
                            <label for="allColleges" class="text-sm font-bold text-gray-700 cursor-pointer"><strong>All Colleges</strong></label>
                        </div>
                        @foreach($this->collegesWithDepts as $college)
                        <div class="flex items-center gap-2">
                            <input type="checkbox"
                                   id="college-{{ $loop->index }}"
                                   wire:model.live="postTargetColleges"
                                   value="{{ $college['name'] }}"
                                   :disabled="$wire.postAllColleges"
                                   class="w-4 h-4 rounded cursor-pointer accent-[#7a3f91]">
                            <label for="college-{{ $loop->index }}" class="text-sm text-gray-700 cursor-pointer">{{ $college['name'] }}</label>
                        </div>
                        @endforeach
                    </div>
                </div>
                @if(isset($postErrors['postTargetColleges']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postTargetColleges'] }}</p>@endif
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Job Description</label>
                <textarea wire:model.defer="postDescription" rows="6"
                          placeholder="Describe the role, responsibilities, qualifications…"
                          maxlength="5000"
                          class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none resize-none {{ isset($postErrors['postDescription'])?'border-red-300 bg-red-50':'' }}"></textarea>
                @if(isset($postErrors['postDescription']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDescription'] }}</p>@endif
            </div>
        </div>
        <div class="px-6 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex gap-3">
            <button wire:click="closePostModal" class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-white text-gray-600 border border-gray-300 hover:bg-gray-100 transition cursor-pointer">Cancel</button>
            <button wire:click="savePost" wire:loading.attr="disabled" wire:target="savePost"
                    class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72] transition cursor-pointer">
                <span wire:loading wire:target="savePost"><i class="fas fa-spinner spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane text-xs"></i> Post Job</span>
            </button>
        </div>
    </div>
</div>
@endif

<!-- VIEW MODAL -->
@if($showViewModal && $this->viewingJob)
@php
    $job        = $this->viewingJob;
    $isOrgDel   = $job->status === 'ORGANIZER_DELETED';
    $isDel      = $isOrgDel;
    $dl         = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $isExp      = now('Asia/Manila')->gt($dl);
    $createdPH  = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $updatedPH  = \Carbon\Carbon::parse($job->updated_at)->setTimezone('Asia/Manila');
    $viewDepts  = $job->target_college
        ? \App\Models\Course::whereIn('college', explode(',', $job->target_college))->orderBy('code')->pluck('code')->toArray()
        : [];
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $isUpdatedByOrganizer = ($job->updated_by_role === 'organizer');
    $viewOrganizerName    = $job->organizer?->name ?? null;
    $viewOrganizerCollege = null;
    if ($job->organizer) {
        $viewOrganizerCollege = \App\Models\Course::where('college', $job->organizer->department)->value('college')
            ?? $job->organizer->department
            ?? null;
    }
@endphp
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="closeViewModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <button wire:click="closeViewModal" type="button" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg transition text-gray-400 hover:text-gray-600">
            <i class="fas fa-xmark text-lg"></i>
        </button>

        <div class="{{ $isDel ? 'bg-red-50' : 'bg-white' }} border-b border-gray-200 px-6 sm:px-7 py-5 flex-shrink-0">
            @if($isDel)
            <div class="flex items-center gap-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-4">
                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                    <i class="fas fa-trash text-red-500 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-red-800">Deleted by Organizer</p>
                    <p class="text-xs text-red-600 mt-0.5">{{ $job->deleted_by ?? 'Unknown' }} · {{ $updatedPH->format('M d, Y') }}</p>
                </div>
            </div>
            @endif
            <div class="text-2xl font-extrabold {{ $isDel ? 'text-red-800' : 'text-gray-900' }} mb-2 pr-10">{{ $job->job_title }}</div>
            <div class="flex items-center gap-2 flex-wrap mb-3">
                <strong class="{{ $isDel ? 'text-red-800' : 'text-gray-900' }}">{{ $job->company_name }}</strong>
                <span class="text-xs font-bold rounded px-2 py-0.5 {{ $isDel ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800' }}">{{ $displayType }}</span>
                @if($isDel)    <span class="text-xs font-bold rounded px-2 py-0.5 bg-red-100 text-red-800">● Deleted</span>
                @elseif($job->status==='ACTIVE') <span class="text-xs font-bold rounded px-2 py-0.5 bg-green-100 text-green-800">● Active</span>
                @else          <span class="text-xs font-bold rounded px-2 py-0.5 bg-yellow-100 text-yellow-800">● Inactive</span>
                @endif
            </div>
            <ul class="space-y-2">
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-location-dot text-xs"></i></span><span>{{ $job->location ?? 'Not specified' }}</span></li>
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-clock text-xs"></i></span><span>{{ $job->employment_type }}</span></li>
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-layer-group text-xs"></i></span><span>{{ $job->experience_level }}</span></li>
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-money-bill-wave text-xs"></i></span>
                    @if($job->salary)<span>{{ $job->salary }}</span>
                    @else<span class="text-gray-500 italic">Salary not disclosed</span>
                    @endif
                </li>
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-calendar-xmark text-xs"></i></span>
                    <span>Deadline: {{ $dl->format('F d, Y') }}
                        @if($isExp)<span class="text-red-700 font-bold ml-1">(Passed)</span>
                        @else<span class="text-gray-600 ml-1">· {{ $dl->diffForHumans() }}</span>
                        @endif
                    </span>
                </li>
                @if($job->target_college)
                <li class="flex items-start gap-2 text-sm {{ $isDel ? 'text-red-800' : 'text-gray-700' }}"><span class="text-[#7a3f91] flex-shrink-0 mt-0.5"><i class="fas fa-building-columns text-xs"></i></span><span>For: {{ str_replace(',', ', ', $job->target_college) }}</span></li>
                @endif
            </ul>
            <p class="text-xs text-gray-600 mt-3">
                Posted {{ $createdPH->diffForHumans() }} · by {{ $job->organizer?->name ?? 'Admin' }}
            </p>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto scroll-c">
            <div class="px-6 sm:px-7 py-5 border-b border-gray-200">
                <div class="text-sm font-bold text-gray-900 mb-3">Job Description</div>
                <div class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $job->description }}</div>
            </div>
            @if($job->target_college && count($viewDepts))
            <div class="px-6 sm:px-7 py-5 border-b border-gray-200">
                <div class="text-sm font-bold text-gray-900 mb-3">Target Colleges</div>
                <div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
                    <div class="text-sm font-bold text-gray-900 mb-2">{{ str_replace(',', ', ', $job->target_college) }}</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach($viewDepts as $dc)<span class="text-xs font-bold bg-white border border-purple-300 text-purple-700 rounded px-2 py-1">{{ $dc }}</span>@endforeach
                    </div>
                </div>
            </div>
            @endif
            <div class="px-6 sm:px-7 py-5">
                <div class="text-xs font-bold text-gray-600 uppercase mb-3 tracking-wide">Posting Details</div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="grid grid-cols-2 sm:grid-cols-3 divide-x divide-y divide-gray-200">
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Posted On</div>
                            <div class="text-sm font-bold text-gray-900">{{ $createdPH->format('M d, Y') }}</div>
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Posted By</div>
                            @if($viewOrganizerName)
                                <div class="text-sm font-bold text-gray-900">{{ $viewOrganizerName }}</div>
                                @if($viewOrganizerCollege)
                                    <div class="text-xs text-[#7a3f91] mt-1 flex items-center gap-1">
                                        <i class="fas fa-building-columns text-[8px]"></i> {{ $viewOrganizerCollege }}
                                    </div>
                                @endif
                            @else
                                <div class="text-sm font-bold text-gray-900">Admin</div>
                                <div class="text-xs text-gray-600">System administrator</div>
                            @endif
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Deadline</div>
                            <div class="text-sm font-bold text-gray-900">{{ $dl->format('M d, Y') }}</div>
                            <div class="text-xs {{ $isExp ? 'text-red-700' : 'text-gray-600' }} mt-0.5">
                                {{ $isExp ? 'Passed' : $dl->diffForHumans() }}
                            </div>
                        </div>
                    </div>
                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                        <div class="text-xs font-bold text-gray-500 uppercase mb-2 tracking-wider">Last Updated</div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <div>
                                <div class="text-sm font-bold text-gray-900">{{ $updatedPH->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-600">{{ $updatedPH->diffForHumans() }}</div>
                            </div>
                            @if($isDel && $job->deleted_by)
                                <span class="text-xs font-bold text-red-800 bg-red-100 border border-red-300 px-2.5 py-1 rounded inline-flex items-center gap-1"><i class="fas fa-trash text-[9px]"></i> Deleted by {{ $job->deleted_by }}</span>
                            @elseif($job->updated_by)
                                @if($isUpdatedByOrganizer)
                                    <span class="text-xs font-bold text-blue-800 bg-blue-100 border border-blue-300 px-2.5 py-1 rounded inline-flex items-center gap-1"><i class="fas fa-user text-[9px]"></i> {{ $job->updated_by }}</span>
                                @else
                                    <span class="text-xs font-bold text-purple-800 bg-purple-100 border border-purple-300 px-2.5 py-1 rounded inline-flex items-center gap-1"><i class="fas fa-shield-halved text-[9px]"></i> {{ $job->updated_by }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-6 sm:px-7 py-4 border-t border-gray-200 bg-white flex-shrink-0 flex gap-2 flex-wrap justify-end">
            <button wire:click="closeViewModal" type="button" class="px-3 py-1.5 text-xs font-semibold text-gray-700 hover:text-gray-900 bg-gray-50 border border-gray-300 rounded-lg hover:bg-gray-100 transition cursor-pointer">
                <i class="fas fa-xmark text-xs mr-1"></i>Close
            </button>
            <button wire:click="confirmDelete({{ $job->id }})" type="button" class="px-3 py-1.5 text-xs font-semibold text-red-600 hover:text-red-800 bg-red-50 border border-red-300 rounded-lg hover:bg-red-100 transition cursor-pointer">
                <i class="fas fa-trash text-xs mr-1"></i>Delete
            </button>
            @if($isDel)
                <button wire:click="confirmRestore({{ $job->id }})" type="button" class="px-3 py-1.5 text-xs font-semibold text-orange-600 hover:text-orange-800 bg-orange-50 border border-orange-300 rounded-lg hover:bg-orange-100 transition cursor-pointer">
                    <i class="fas fa-rotate-left text-xs mr-1"></i>Restore
                </button>
            @else
                @if($job->status==='ACTIVE')
                    <button wire:click="confirmToggle({{ $job->id }})" type="button" class="px-3 py-1.5 text-xs font-semibold text-orange-600 hover:text-orange-800 bg-orange-50 border border-orange-300 rounded-lg hover:bg-orange-100 transition cursor-pointer">
                        <i class="fas fa-ban text-xs mr-1"></i>Deactivate
                    </button>
                @else
                    <button wire:click="confirmToggle({{ $job->id }})" type="button" class="px-3 py-1.5 text-xs font-semibold text-green-600 hover:text-green-800 bg-green-50 border border-green-300 rounded-lg hover:bg-green-100 transition cursor-pointer">
                        <i class="fas fa-circle-check text-xs mr-1"></i>Activate
                    </button>
                @endif
                <button wire:click="openEditModal({{ $job->id }})" type="button" class="px-3 py-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 border border-blue-300 rounded-lg hover:bg-blue-100 transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs mr-1"></i>Edit
                </button>
            @endif
        </div>
    </div>
</div>
@endif

<!-- EDIT MODAL -->
@if($showEditModal)
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="closeEditModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col m-in overflow-hidden relative"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <button wire:click="closeEditModal" type="button" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg transition text-gray-400 hover:text-gray-600">
            <i class="fas fa-xmark text-lg"></i>
        </button>
        <div class="flex items-center justify-between px-6 sm:px-7 py-4 sm:py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-lg sm:text-xl font-extrabold text-white flex items-center gap-2.5">
                <i class="fas fa-pen-to-square text-sm"></i> Edit Job Posting
            </h2>
        </div>
        {{-- Hint banner when deadline is expired --}}
        @php
            $editJobDeadlinePassed = $editDeadline && \Carbon\Carbon::createFromFormat('Y-m-d', $editDeadline, 'Asia/Manila')->endOfDay() < now('Asia/Manila');
        @endphp
        @if($editJobDeadlinePassed)
        <div class="bg-amber-50 border-b border-amber-200 px-6 sm:px-7 py-3 flex-shrink-0 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation text-amber-500 text-sm flex-shrink-0"></i>
            <p class="text-xs text-amber-800 font-semibold">This job's deadline has already passed. Update the deadline to a future date to re-activate it.</p>
        </div>
        @endif
        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-6 sm:px-7 py-3 flex-shrink-0">
            <p class="font-bold text-red-800 text-xs mb-1.5 flex items-center gap-1.5">
                <i class="fas fa-triangle-exclamation"></i> Please fix the following:
            </p>
            <ul class="text-red-700 text-xs space-y-1">
                @foreach($editErrors as $err)<li class="flex items-start gap-1.5"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto scroll-c px-6 sm:px-7 py-5 space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Job Title</label>
                <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer"
                       maxlength="200"
                       class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editJobTitle'])?'border-red-300 bg-red-50':'' }}">
                @if(isset($editErrors['editJobTitle']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Organization Type</label>
                    <select wire:model.live="editCompanyType"
                            class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editCompanyType'])?'border-red-300 bg-red-50':'' }}">
                        <option value="">Select Organization</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Company Name</label>
                    <input wire:model.defer="editCompany" type="text" maxlength="150"
                           @if($editIsPhilcst) readonly @endif
                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editCompany'])?'border-red-300 bg-red-50':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-400 cursor-not-allowed':'' }}">
                    @if(isset($editErrors['editCompany']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Location</label>
                <input wire:model="editLocation" type="text" maxlength="120"
                       @if($editIsPhilcst) readonly @endif
                       class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editLocation'])?'border-red-300 bg-red-50':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-400 cursor-not-allowed':'' }}">
                @if(isset($editErrors['editLocation']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Employment Type</label>
                    <select wire:model.defer="editEmpType"
                            class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editEmpType'])?'border-red-300 bg-red-50':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                            <option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Experience Level</label>
                    <select wire:model.defer="editExpLevel"
                            class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editExpLevel'])?'border-red-300 bg-red-50':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)
                            <option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>
                        @endforeach
                    </select>
                    @if(isset($editErrors['editExpLevel']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide">Salary <span class="text-gray-500 font-normal">(Optional)</span></label>
                    <input wire:model.defer="editSalary" type="text" maxlength="100"
                           placeholder="e.g. ₱25,000 – ₱35,000 / month"
                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Application Deadline</label>
                    <input wire:model.defer="editDeadline" type="date"
                           min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                           class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none {{ isset($editErrors['editDeadline'])?'border-red-300 bg-red-50':'' }}">
                    @if(isset($editErrors['editDeadline']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-2 tracking-wide"><span class="text-red-500">*</span> Target Colleges</label>
                <div class="border-2 border-gray-300 rounded-lg p-4 bg-white">
                    <div class="flex flex-col gap-2.5">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="editAllColleges" wire:model.live="editAllColleges" class="w-4 h-4 rounded cursor-pointer accent-[#7a3f91]">
                            <label for="editAllColleges" class="text-sm font-bold text-gray-700 cursor-pointer"><strong>All Colleges</strong></label>
                        </div>
                        @foreach($this->collegesWithDepts as $college)
                        <div class="flex items-center gap-2">
                            <input type="checkbox"
                                   id="editCollege-{{ $loop->index }}"
                                   wire:model.live="editTargetColleges"
                                   value="{{ $college['name'] }}"
                                   :disabled="$wire.editAllColleges"
                                   class="w-4 h-4 rounded cursor-pointer accent-[#7a3f91]">
                            <label for="editCollege-{{ $loop->index }}" class="text-sm text-gray-700 cursor-pointer">{{ $college['name'] }}</label>
                        </div>
                        @endforeach
                    </div>
                </div>
                @if(isset($editErrors['editTargetColleges']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editTargetColleges'] }}</p>@endif
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 mb-1 tracking-wide"><span class="text-red-500">*</span> Job Description</label>
                <textarea wire:model.defer="editDescription" rows="7" maxlength="5000"
                          class="w-full px-3 py-2.5 border-2 border-gray-300 rounded-lg text-sm text-gray-900 bg-white transition focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 focus:outline-none resize-none {{ isset($editErrors['editDescription'])?'border-red-300 bg-red-50':'' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>
        </div>
        <div class="px-6 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex gap-3">
            <button wire:click="closeEditModal" class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-white text-gray-600 border border-gray-300 hover:bg-gray-100 transition cursor-pointer">Cancel</button>
            <button wire:click="saveEditJob" wire:loading.attr="disabled" wire:target="saveEditJob"
                    class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72] transition cursor-pointer">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk text-xs"></i> Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

<!-- CONFIRM TOGGLE MODAL -->
@if($showConfirmModal)
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="cancelConfirm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <div class="px-6 py-5 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-50 border-b border-emerald-100' : 'bg-amber-50 border-b border-amber-100' }}">
            <h2 class="text-base font-extrabold {{ $confirmAction==='ACTIVE' ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-xl flex items-center justify-center">
                    <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check text-emerald-600' : 'ban text-amber-600' }} text-sm"></i>
                </div>
                {{ $confirmAction==='ACTIVE' ? 'Activate Job?' : 'Deactivate Job?' }}
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 leading-relaxed mb-5">
                This job will be marked as
                <span class="font-bold {{ $confirmAction==='ACTIVE' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $confirmAction }}</span>.
                @if($confirmAction==='INACTIVE') It will be hidden from students but can still be edited.@endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm" class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-white text-gray-600 border border-gray-300 hover:bg-gray-100 transition cursor-pointer">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm text-white transition cursor-pointer {{ $confirmAction==='ACTIVE' ? 'bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300' : 'bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check' : 'ban' }} mr-1 text-xs"></i>
                        {{ $confirmAction==='ACTIVE' ? 'Yes, Activate' : 'Yes, Deactivate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<!-- RESTORE MODAL -->
@if($showRestoreModal)
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="cancelRestore">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <div class="px-6 py-5 bg-orange-50 border-b border-orange-100">
            <h2 class="text-base font-extrabold text-orange-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-rotate-left text-orange-500 text-sm"></i>
                </div>
                Restore Job Posting
            </h2>
        </div>
        <div class="p-6">
            <p class="text-gray-500 text-xs mb-1">Restoring:</p>
            <p class="font-extrabold text-orange-700 text-sm mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-xs text-blue-800 flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                <span>The job will be restored. If its deadline has passed it will be set to <strong>Inactive</strong> — update the deadline to re-activate it.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-white text-gray-600 border border-gray-300 hover:bg-gray-100 transition cursor-pointer">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 bg-orange-500 hover:bg-orange-600 disabled:bg-orange-300 text-white rounded-xl text-sm transition cursor-pointer">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1 text-xs"></i>Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<!-- DELETE MODAL -->
@if($showDeleteModal)
<div class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45 backdrop-blur-sm" wire:keydown.escape="cancelDelete">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-in overflow-hidden"
         style="box-shadow:0 20px 50px rgba(0,0,0,.20), 0 8px 16px rgba(0,0,0,.10);">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100">
            <h2 class="text-base font-extrabold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-triangle-exclamation text-red-500 text-sm"></i>
                </div>
                Permanently Delete
            </h2>
        </div>
        <div class="p-6">
            <p class="text-gray-500 text-xs mb-1">Permanently deleting:</p>
            <p class="font-extrabold text-red-700 text-sm mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-red-50 border border-red-100 rounded-xl px-4 py-3 mb-5 text-xs text-gray-600 flex items-start gap-2">
                <i class="fas fa-exclamation-circle text-red-400 mt-0.5 shrink-0"></i>
                <span>This action <strong>cannot be undone</strong>. The job posting will be permanently removed.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 rounded-xl text-sm bg-white text-gray-600 border border-gray-300 hover:bg-gray-100 transition cursor-pointer">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 inline-flex items-center justify-center gap-1 font-bold px-4 py-2.5 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm transition cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1 text-xs"></i>Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>