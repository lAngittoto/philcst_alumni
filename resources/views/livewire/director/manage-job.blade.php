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

    public bool   $showPostModal                   = false;
    public string $postJobTitle                    = '';
    public string $postOrgCategory                 = '';
    public string $postPartnerName                 = '';
    public string $postPartnerType                 = '';
    public string $postCustomName                  = '';
    public string $postCustomType                  = '';
    public string $postLocation                    = '';
    public string $postEmpType                     = '';
    public string $postExpLevel                    = '';
    public string $postSalary                      = '';
    public string $postDeadline                    = '';
    public string $postDescription                 = '';
    public string $postQualifications              = '';
    public string $postApplicationInstructions     = '';
    public array  $postTargetColleges              = [];
    public array  $postErrors                      = [];
    public bool   $postAllColleges                 = false;

    public string $philcstName     = '';
    public string $philcstLocation = '';

    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    public bool   $showEditModal                   = false;
    public ?int   $editingJobId                    = null;
    public string $editJobTitle                    = '';
    public string $editCompany                     = '';
    public string $editCompanyType                 = '';
    public string $editLocation                    = '';
    public string $editEmpType                     = '';
    public string $editExpLevel                    = '';
    public string $editSalary                      = '';
    public string $editDeadline                    = '';
    public string $editDescription                 = '';
    public string $editQualifications              = '';
    public string $editApplicationInstructions     = '';
    public array  $editTargetColleges              = [];
    public array  $editErrors                      = [];
    public bool   $editAllColleges                 = false;

    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmAction    = '';

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    public bool   $showRestoreModal = false;
    public ?int   $restoreJobId     = null;
    public string $restoreJobTitle  = '';

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
        $qualifications          = $this->sanitize($this->postQualifications);
        $applicationInstructions = $this->sanitize($this->postApplicationInstructions);
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

        if (!$description)             $errors['postDescription']             = 'Job description is required.';
        if (!$qualifications)          $errors['postQualifications']          = 'Qualifications are required.';
        if (!$applicationInstructions) $errors['postApplicationInstructions'] = 'Application instructions are required.';

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
            'organizer_id'             => null,
            'job_title'                => $title,
            'company_name'             => $companyName,
            'company_type'             => $companyType,
            'location'                 => $resolvedLocation,
            'employment_type'          => $empType,
            'experience_level'         => $expLevel,
            'salary'                   => $salary ?: null,
            'deadline'                 => $deadline,
            'description'              => $description,
            'qualifications'           => $qualifications ?: null,
            'application_instructions' => $applicationInstructions ?: null,
            'target_college'           => $targetCollegeStr,
            'status'                   => 'ACTIVE',
            'updated_by'               => $this->myDisplayName,
            'updated_by_role'          => auth()->user()->role,
        ]);

        $this->writeAuditLog(
            action:      'created',
            description: "Director posted new job: \"{$title}\" at {$companyName} ({$empType})",
            severity:    'info',
            subject:     $title,
            newValues:   [
                'job_id'                   => $job->id,
                'job_title'                => $title,
                'company_name'             => $companyName,
                'employment_type'          => $empType,
                'experience_level'         => $expLevel,
                'location'                 => $resolvedLocation,
                'deadline'                 => $deadline,
                'target_college'           => $targetCollegeStr,
                'qualifications'           => $qualifications,
                'application_instructions' => $applicationInstructions,
                'status'                   => 'ACTIVE',
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
        $this->postQualifications = $this->postApplicationInstructions = '';
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

        $this->editingJobId                = $id;
        $this->editJobTitle                = $job->job_title;
        $this->editCompany                 = $job->company_name;
        $this->editCompanyType             = $job->company_type;
        $this->editLocation                = $job->location ?? '';
        $this->editEmpType                 = $job->employment_type;
        $this->editExpLevel                = $job->experience_level;
        $this->editSalary                  = $job->salary ?? '';
        $this->editDeadline                = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->editDescription             = $job->description;
        $this->editQualifications          = $job->qualifications ?? '';
        $this->editApplicationInstructions = $job->application_instructions ?? '';
        $this->editTargetColleges          = !empty($job->target_college) ? explode(',', $job->target_college) : [];
        $this->editErrors                  = [];
        $this->editAllColleges             = false;
        $this->showViewModal               = false;
        $this->showEditModal               = true;
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

        $title                   = $this->sanitize($this->editJobTitle);
        $company                 = $this->sanitize($this->editCompany);
        $companyType             = $this->sanitize($this->editCompanyType);
        $location                = $this->sanitize($this->editLocation);
        $empType                 = $this->sanitize($this->editEmpType);
        $expLevel                = $this->sanitize($this->editExpLevel);
        $salary                  = $this->sanitize($this->editSalary);
        $deadline                = $this->sanitize($this->editDeadline);
        $description             = $this->sanitize($this->editDescription);
        $qualifications          = $this->sanitize($this->editQualifications);
        $applicationInstructions = $this->sanitize($this->editApplicationInstructions);

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

        if (!$description)             $errors['editDescription']             = 'Job description is required.';
        if (!$qualifications)          $errors['editQualifications']          = 'Qualifications are required.';
        if (!$applicationInstructions) $errors['editApplicationInstructions'] = 'Application instructions are required.';

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
            'job_title'                => $job->job_title,
            'company_name'             => $job->company_name,
            'company_type'             => $job->company_type,
            'location'                 => $job->location,
            'employment_type'          => $job->employment_type,
            'experience_level'         => $job->experience_level,
            'salary'                   => $job->salary,
            'deadline'                 => \Carbon\Carbon::parse($job->deadline)->format('Y-m-d'),
            'target_college'           => $job->target_college,
            'qualifications'           => $job->qualifications,
            'application_instructions' => $job->application_instructions,
        ];

        $targetCollegeStr = !empty($this->editTargetColleges) ? implode(',', $this->editTargetColleges) : null;

        $newValues = [
            'job_title'                => $title,
            'company_name'             => $company,
            'company_type'             => $companyType,
            'location'                 => $location,
            'employment_type'          => $empType,
            'experience_level'         => $expLevel,
            'salary'                   => $salary ?: null,
            'deadline'                 => $deadline,
            'target_college'           => $targetCollegeStr,
            'qualifications'           => $qualifications,
            'application_instructions' => $applicationInstructions,
        ];

        $deadlineDate     = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
        $shouldReactivate = $job->status === 'INACTIVE' && $deadlineDate >= now('Asia/Manila');

        $job->update(array_merge($newValues, [
            'description'              => $description,
            'qualifications'           => $qualifications ?: null,
            'application_instructions' => $applicationInstructions ?: null,
            'updated_by'               => $this->myDisplayName,
            'updated_by_role'          => auth()->user()->role,
            'status'                   => $shouldReactivate ? 'ACTIVE' : $job->status,
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
        $this->editQualifications = $this->editApplicationInstructions = '';
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

<div class="flex flex-col" style="height: calc(100vh - 120px); overflow: hidden;">

<style>
/* ── Core variables ── */
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}

/* ── Animations ── */
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes slideInFull {
    from { opacity:0; }
    to   { opacity:1; }
}
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }
[x-cloak] { display: none !important; }

/* ── Scrollbar ── */
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Filter inputs ── */
.filter-input {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
}
.filter-input:hover  { border-color: #c4b5d4; }
.filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}

/* ── Table row ── */
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #FAFAFA !important; }

/* ── Form fields ── */
.form-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #333333;
    margin-bottom: 0.4rem;
}
.form-input {
    width: 100%;
    padding: 0.7rem 0.95rem;
    border: 1.5px solid #d1d5db;
    border-radius: 0.65rem;
    font-size: 0.97rem;
    background: #fff;
    color: #222;
    transition: border-color .15s, box-shadow .15s;
}
.form-input:focus {
    outline: none;
    border-color: #7a3f91;
    box-shadow: 0 0 0 3px rgba(122,63,145,.1);
}
.form-input.error {
    border-color: #f87171;
    background: #fff5f5;
}
select.form-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.75rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.5rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

/* ── Card sections ── */
.card-section {
    background: #fff;
    border: 1.5px solid #e8e0f0;
    border-radius: 0.875rem;
    overflow: hidden;
}
.card-section-hd {
    padding: 0.55rem 0.85rem;
    background: #faf7fc;
    border-bottom: 1px solid #e8e0f0;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #7a3f91;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.card-section-body { padding: 0.85rem; }

/* ── Unified table block ── */
.table-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.table-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.table-block-body {
    flex: 1;
    min-height: 0;
    background: #fff;
}
.table-block-pagination {
    flex-shrink: 0;
    background: #7a3f91;
    padding: 0.6rem 1rem;
}

/* ── View modal meta ── */
.meta-row-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.meta-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #555555;
    margin-bottom: 0.2rem;
}
.meta-value {
    font-size: 0.975rem;
    font-weight: 700;
    color: #333333;
    line-height: 1.3;
}
.meta-sub {
    font-size: 0.875rem;
    color: #333333;
    margin-top: 0.15rem;
}
</style>

{{-- ── FLASH TOAST ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
     :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-blue-300 text-blue-800':type==='info','bg-white border-amber-300 text-amber-800':type==='warning','bg-white border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Job Overview</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">Review, moderate, and manage all job postings.</p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-briefcase text-purple-600 text-[10px]"></i>
                {{ $this->jobPostings->total() }} {{ $this->jobPostings->total() !== 1 ? 'Jobs' : 'Job' }}
            </span>
            <button wire:click="openPostModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-sm text-white shadow-md transition cursor-pointer"
                    style="background-color:#7a3f91;"
                    onmouseover="this.style.backgroundColor='#5e2f72'"
                    onmouseout="this.style.backgroundColor='#7a3f91'">
                <i class="fas fa-plus text-xs"></i> Post a Job
            </button>
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col table-block">

        {{-- ── FILTER BAR ── --}}
        <div class="table-block-filter flex flex-wrap gap-2 items-center">
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-sliders text-white text-sm"></i>
                <span class="hidden sm:inline">Filters</span>
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#7a3f91; z-index:1;"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="filter-input w-full"
                       style="padding-left:2.25rem; padding-right:1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus" class="filter-input" style="color:#333333;">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="ORGANIZER_DELETED">Deleted by Org.</option>
            </select>

            <select wire:model.live="filterType" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort" class="filter-input hidden sm:block" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer"
                    style="color:#333333;">
                <i class="fas fa-rotate-left text-sm"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            <select wire:model.live="filterType" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterSort" class="filter-input flex-1 sm:hidden" style="color:#333333;">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col">

            {{-- Loading Overlay --}}
            <div wire:loading
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeToggle,executeDelete,executeRestore"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold" style="color:#7a3f91;">Loading jobs…</span>
                </div>
            </div>

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto scroll-c" style="background:#fff;">
                <table class="w-full min-w-[760px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell" style="color:#555555;">Deadline</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $isOrgDel         = $job->status === 'ORGANIZER_DELETED';
                            $isActive         = $job->status === 'ACTIVE';
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors duration-100">

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[220px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2 {{ $isOrgDel ? 'line-through opacity-60' : '' }}" style="color:#333333;">{{ $job->job_title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $job->created_at->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                @if($organizerName)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5" style="color:#777777;">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold">
                                        <i class="fas fa-shield-halved" style="font-size:8px;"></i> Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 whitespace-nowrap">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 hidden xl:table-cell whitespace-nowrap">
                                @if($isDeadlinePassed && !$isOrgDel)
                                    <p class="text-sm font-semibold text-red-600">Closed</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $dl->format('M d, Y') }}</p>
                                @else
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $dl->format('M d, Y') }}</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $dl->diffForHumans() }}</p>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isOrgDel)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        <i class="fas fa-trash text-[9px] mr-1"></i>Deleted by Org.
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        <i class="fas fa-circle-pause text-[9px] mr-1"></i>Inactive
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    @if($isOrgDel)
                                        <button wire:click="confirmRestore({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-orange-100 text-orange-700 border border-orange-300 hover:bg-white hover:border-orange-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-rotate-left text-xs"></i>
                                            <span class="hidden xl:inline">Restore</span>
                                        </button>

                                    @elseif($isActive)
                                        <button wire:click="openShareJobModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-300 hover:bg-white hover:border-amber-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-ban text-xs"></i>
                                            <span class="hidden xl:inline">Deactivate</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>

                                    @else
                                        <button wire:click="confirmToggle({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-300 hover:bg-white hover:border-emerald-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-circle-check text-xs"></i>
                                            <span class="hidden xl:inline">Activate</span>
                                        </button>
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-briefcase text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#333333;">
                        @if($search || $filterStatus || $filterType) No jobs match your filters
                        @else No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus || $filterType) Try clearing your filters to see all postings.
                        @else Click <strong>Post a Job</strong> to create the first listing.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterType)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ── PAGINATION ── --}}
        @php
            $total = $this->jobPostings->total();
            $pp    = $this->jobPostings->perPage();
            $cp    = $this->jobPostings->currentPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);
        @endphp
        <div class="table-block-pagination flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
                Showing
                <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
                of
                <span class="font-semibold text-white">{{ $total }}</span>
                job{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterType || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->jobPostings->onFirstPage())
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">&larr; Prev</button>
                @else
                    <button wire:click="previousPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">&larr; Prev</button>
                @endif
                <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#7a3f91;">
                    {{ $cp }} / {{ $this->jobPostings->lastPage() }}
                </span>
                @if($this->jobPostings->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                            style="background:rgba(255,255,255,.15);">Next &rarr;</button>
                @else
                    <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next &rarr;</button>
                @endif
            </div>
        </div>

    </div>{{-- /table-block --}}

</div>{{-- /main layout --}}


{{-- ════════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN 3-COLUMN
════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in"
     @keydown.escape.window="$wire.closePostModal()">

    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs mt-0.5">Fill in the details — job goes live immediately</p>
            </div>
        </div>
        <button wire:click="closePostModal" type="button"
                class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline text-sm">Close</span>
        </button>
    </div>

    {{-- Validation Errors Banner --}}
    @if(count($postErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-3 shrink-0">
        <p class="font-semibold text-red-800 text-sm mb-1 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation text-xs"></i> Please fix the following:
        </p>
        <ul class="text-red-700 text-xs space-y-0.5">
            @foreach($postErrors as $err)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- 3-COLUMN BODY --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-0 overflow-hidden">

        {{-- ═══ LEFT: Organization + Target Colleges ═══ --}}
        <div class="w-full lg:w-[300px] xl:w-[340px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto scroll-c bg-white"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                {{-- Organization Category --}}
                <div class="card-section {{ isset($postErrors['postOrgCategory']) ? 'border-red-300' : '' }}">
                    <div class="card-section-hd">
                        <i class="fas fa-building text-[9px]"></i> Organization
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body space-y-3">
                        <div class="grid grid-cols-1 gap-2">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')"
                                    class="px-3 py-3 border-2 rounded-xl bg-white cursor-pointer transition text-left text-sm font-semibold flex items-center gap-3
                                           {{ $postOrgCategory==='philcst' ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50' }}"
                                    style="{{ $postOrgCategory==='philcst' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : 'color:#333333;' }}">
                                <i class="fas fa-school text-lg flex-shrink-0"></i>
                                <div>
                                    <span class="block">PHILCST Campus</span>
                                    <span class="text-xs font-normal {{ $postOrgCategory==='philcst' ? 'text-white/70' : '' }}" style="{{ $postOrgCategory!=='philcst' ? 'color:#777777;' : '' }}">Internal department</span>
                                </div>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')"
                                    class="px-3 py-3 border-2 rounded-xl bg-white cursor-pointer transition text-left text-sm font-semibold flex items-center gap-3
                                           {{ $postOrgCategory==='partner' ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50' }}"
                                    style="{{ $postOrgCategory==='partner' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : 'color:#333333;' }}">
                                <i class="fas fa-handshake text-lg flex-shrink-0"></i>
                                <div>
                                    <span class="block">Partner Company</span>
                                    <span class="text-xs font-normal {{ $postOrgCategory==='partner' ? 'text-white/70' : '' }}" style="{{ $postOrgCategory!=='partner' ? 'color:#777777;' : '' }}">Known partner organization</span>
                                </div>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')"
                                    class="px-3 py-3 border-2 rounded-xl bg-white cursor-pointer transition text-left text-sm font-semibold flex items-center gap-3
                                           {{ $postOrgCategory==='custom' ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50' }}"
                                    style="{{ $postOrgCategory==='custom' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : 'color:#333333;' }}">
                                <i class="fas fa-pen-to-square text-lg flex-shrink-0"></i>
                                <div>
                                    <span class="block">Other / Custom</span>
                                    <span class="text-xs font-normal {{ $postOrgCategory==='custom' ? 'text-white/70' : '' }}" style="{{ $postOrgCategory!=='custom' ? 'color:#777777;' : '' }}">Enter manually</span>
                                </div>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-red-600 text-xs flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif

                        {{-- PHILCST auto-fill --}}
                        @if($postOrgCategory === 'philcst' && $philcstName)
                        <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-3 py-2.5">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 text-white shadow-sm" style="background:linear-gradient(135deg,#7a3f91,#6a3580);">
                                <i class="fas fa-school text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-[#4c1d95] text-sm truncate">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-xs mt-0.5 text-[#7c3aed] truncate"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-purple-700 bg-white border border-purple-200 px-1.5 py-0.5 rounded-full shrink-0">
                                <i class="fas fa-lock text-[8px]"></i> Auto
                            </span>
                        </div>
                        @endif

                        {{-- Partner fields --}}
                        @if($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),loc:@js($postLocation),syncN(v){$wire.set('postPartnerName',v,false)},syncT(v){$wire.set('postPartnerType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-3">
                                <div>
                                    <label class="form-label">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncN(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="form-input {{ isset($postErrors['postPartnerName']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-label">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncT(pType)" type="text" placeholder="e.g. Private, NGO" maxlength="100"
                                           class="form-input {{ isset($postErrors['postPartnerType']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-label">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                           class="form-input {{ isset($postErrors['postLocation']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Custom fields --}}
                        @if($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),loc:@js($postLocation),syncN(v){$wire.set('postCustomName',v,false)},syncT(v){$wire.set('postCustomType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-3">
                                <div>
                                    <label class="form-label">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncN(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="form-input {{ isset($postErrors['postCustomName']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-label">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncT(cType)" type="text" placeholder="e.g. Government, NGO" maxlength="100"
                                           class="form-input {{ isset($postErrors['postCustomType']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="form-label">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                           class="form-input {{ isset($postErrors['postLocation']) ? 'error' : '' }}">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        @if(!$postOrgCategory)
                        <div class="text-center py-4" style="color:#777777;">
                            <i class="fas fa-arrow-up text-2xl block mb-1.5 text-gray-200"></i>
                            <p class="text-xs">Select a category above to continue.</p>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Target Colleges --}}
                <div class="card-section {{ isset($postErrors['postTargetColleges']) ? 'border-red-300' : '' }}">
                    <div class="card-section-hd">
                        <i class="fas fa-building-columns text-[9px]"></i> Target Colleges
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body space-y-2">
                        <label class="flex items-center gap-2 px-3 py-2 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                            <input type="checkbox" wire:model.live="postAllColleges" class="w-4 h-4 flex-shrink-0" style="accent-color:#7a3f91;">
                            <span class="text-sm font-semibold" style="color:#5e2f72;">All Colleges</span>
                        </label>
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach($this->collegesWithDepts as $college)
                                <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer transition text-sm font-semibold
                                              {{ in_array($college['name'], $postTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40' }}"
                                       style="{{ !in_array($college['name'], $postTargetColleges) ? 'color:#555555;' : '' }}">
                                    <input type="checkbox" wire:model.live="postTargetColleges" value="{{ $college['name'] }}"
                                           class="w-4 h-4 flex-shrink-0" style="accent-color:#7a3f91;">
                                    <span class="truncate text-xs">{{ $college['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if(isset($postErrors['postTargetColleges']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postTargetColleges'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ MIDDLE: Job Info + Description + Qualifications + How to Apply ═══ --}}
        <div class="flex-1 min-w-0 overflow-y-auto scroll-c border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                {{-- Job Information --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-briefcase text-[9px]"></i> Job Information
                    </div>
                    <div class="card-section-body space-y-4">

                        <div>
                            <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                                   class="form-input {{ isset($postErrors['postJobTitle']) ? 'error' : '' }}">
                            @if(isset($postErrors['postJobTitle']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postEmpType" class="form-input {{ isset($postErrors['postEmpType']) ? 'error' : '' }}">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postEmpType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postExpLevel" class="form-input {{ isset($postErrors['postExpLevel']) ? 'error' : '' }}">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}">{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postExpLevel']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Salary <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                                <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 / month" maxlength="100" class="form-input">
                                <p class="text-xs mt-1 flex items-center gap-1" style="color:#777777;"><i class="fas fa-circle-info text-[10px]"></i>Leave blank if not disclosed.</p>
                            </div>
                            <div>
                                <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="postDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="form-input {{ isset($postErrors['postDeadline']) ? 'error' : '' }}">
                                @if(isset($postErrors['postDeadline']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Description --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-file-lines text-[9px]"></i> Description
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="postDescription" rows="6"
                                  placeholder="Describe the role, responsibilities, and what the candidate will be doing…" maxlength="5000"
                                  class="form-input resize-none {{ isset($postErrors['postDescription']) ? 'error' : '' }}"></textarea>
                        @if(isset($postErrors['postDescription']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDescription'] }}</p>@endif
                    </div>
                </div>

                {{-- Qualifications --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-list-check text-[9px]"></i> Qualifications
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="postQualifications" rows="5"
                                  placeholder="e.g. Bachelor's degree in a relevant field, at least 1 year experience…" maxlength="3000"
                                  class="form-input resize-none {{ isset($postErrors['postQualifications']) ? 'error' : '' }}"></textarea>
                        @if(isset($postErrors['postQualifications']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postQualifications'] }}</p>@endif
                    </div>
                </div>

                {{-- How to Apply --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-paper-plane text-[9px]"></i> How to Apply
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="postApplicationInstructions" rows="5"
                                  placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]" maxlength="3000"
                                  class="form-input resize-none {{ isset($postErrors['postApplicationInstructions']) ? 'error' : '' }}"></textarea>
                        @if(isset($postErrors['postApplicationInstructions']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postApplicationInstructions'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ RIGHT: Tips + Actions ═══ --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 overflow-y-auto scroll-c bg-white flex flex-col"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4 flex-1">

                <div class="card-section">
                    <div class="card-section-hd"><i class="fas fa-lightbulb text-[9px]"></i> Posting Tips</div>
                    <div class="card-section-body">
                        <ul class="space-y-2.5">
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Set a future deadline — past deadlines auto-deactivate the listing.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Write a clear description so alumni know exactly what the role involves.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Include salary if possible — listings with salary attract more applicants.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Select target colleges carefully — only alumni from those colleges will see it.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Job goes live immediately — no approval required.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card-section">
                    <div class="card-section-hd"><i class="fas fa-shield-halved text-[9px]"></i> Posted As</div>
                    <div class="card-section-body">
                        <div class="flex items-center gap-2.5 bg-purple-50 border border-purple-100 rounded-xl px-3 py-2.5">
                            <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-purple-800 text-sm truncate">{{ $myDisplayName }}</div>
                                <div class="text-xs text-purple-600 mt-0.5">Alumni Director · visible to selected colleges</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Action Buttons --}}
            <div class="shrink-0 px-4 py-4 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="w-full px-5 py-3.5 rounded-xl text-base font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color:#7a3f91;"
                        onmouseover="this.style.backgroundColor='#5e2f72'"
                        onmouseout="this.style.backgroundColor='#7a3f91'">
                    <span wire:loading wire:target="savePost">
                        <i class="fas fa-spinner animate-spin text-sm"></i> Posting…
                    </span>
                    <span wire:loading.remove wire:target="savePost">
                        <i class="fas fa-paper-plane text-sm"></i> Post Job
                    </span>
                </button>
                <button type="button" wire:click="closePostModal"
                        class="w-full px-5 py-2.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i>Cancel
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     VIEW JOB — FULL SCREEN 2-COLUMN
════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $vj           = $this->viewingJob;
    $isOrgDel     = $vj->status === 'ORGANIZER_DELETED';
    $isActive     = $vj->status === 'ACTIVE';
    $vDl          = \Carbon\Carbon::parse($vj->deadline)->setTimezone('Asia/Manila');
    $vIsExp       = now('Asia/Manila')->startOfDay()->gt($vDl->copy()->startOfDay());
    $vDaysLeft    = (int) now('Asia/Manila')->startOfDay()->diffInDays($vDl->copy()->startOfDay(), false);
    $vIsUrgent    = $vDaysLeft <= 7 && !$vIsExp;
    $vCreatedPH   = \Carbon\Carbon::parse($vj->created_at)->setTimezone('Asia/Manila');
    $vUpdatedPH   = \Carbon\Carbon::parse($vj->updated_at)->setTimezone('Asia/Manila');
    $displayType  = ($vj->company_type === $vj->company_name) ? 'PHILCST' : $vj->company_type;
    $vOrgName     = $vj->organizer?->name ?? null;
    $vOrgCollege  = null;
    if ($vj->organizer) {
        $vOrgCollege = \App\Models\Course::where('college', $vj->organizer->department)->value('college')
            ?? $vj->organizer->department ?? null;
    }
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Header Bar with Actions --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $vj->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3 flex-wrap justify-end">
            @if(!$isOrgDel && $isActive)
                <button wire:click="openShareJobModal({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i><span class="hidden sm:inline">Share</span>
                </button>
                <button wire:click="confirmToggle({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-400/20 hover:bg-amber-400/30 border border-amber-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-ban text-xs"></i><span class="hidden sm:inline">Deactivate</span>
                </button>
            @elseif(!$isOrgDel && !$isActive)
                <button wire:click="confirmToggle({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-400/20 hover:bg-emerald-400/30 border border-emerald-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-circle-check text-xs"></i><span class="hidden sm:inline">Activate</span>
                </button>
            @endif
            @if(!$isOrgDel)
                <button wire:click="openEditModal({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs"></i><span class="hidden sm:inline">Edit</span>
                </button>
            @endif
            <button wire:click="confirmDelete({{ $vj->id }})"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-400/20 hover:bg-red-400/30 border border-red-300/40 text-white transition cursor-pointer">
                <i class="fas fa-trash text-xs"></i><span class="hidden sm:inline">Delete</span>
            </button>
            @if($isOrgDel)
                <button wire:click="confirmRestore({{ $vj->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-orange-400/20 hover:bg-orange-400/30 border border-orange-300/40 text-white transition cursor-pointer">
                    <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Restore</span>
                </button>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    @if($isOrgDel)
    <div class="bg-red-50 border-b border-red-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <i class="fas fa-trash text-red-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-red-800 font-semibold">
            Deleted by <strong>{{ $vj->deleted_by ?? $vj->organizer?->name ?? 'Coordinator' }}</strong>
            · {{ $vUpdatedPH->format('M d, Y') }} — you can restore this posting.
        </p>
    </div>
    @endif

    {{-- 2-Column Body --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- ═══ LEFT: Meta Info ═══ --}}
        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c"
             style="scrollbar-width:thin;">

            {{-- Status banner --}}
            <div class="mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-between px-4 py-3"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="flex items-center gap-2 flex-wrap">
                    @if($isOrgDel)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-red-500/80 text-white text-xs font-semibold">
                            <i class="fas fa-trash text-[9px]"></i> Deleted by Org.
                        </span>
                    @elseif($isActive)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/80 text-white text-xs font-semibold">
                            <i class="fas fa-circle-check text-[9px]"></i> Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/80 text-white text-xs font-semibold">
                            <i class="fas fa-circle-pause text-[9px]"></i> Inactive
                        </span>
                    @endif
                    @if(!$vOrgName)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-purple-300/40 text-white text-xs font-semibold">
                            <i class="fas fa-shield-halved text-[9px]"></i> Alumni Director
                        </span>
                    @endif
                </div>
                <i class="fas fa-briefcase text-white/20 text-3xl"></i>
            </div>

            {{-- Meta cards --}}
            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100"><i class="fas fa-building text-violet-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Company</p>
                        <p class="meta-value truncate">{{ $vj->company_name }}</p>
                        @if($displayType !== 'PHILCST')<p class="meta-sub truncate">{{ $displayType }}</p>@else<p class="meta-sub">PHILCST</p>@endif
                    </div>
                </div>

                @if($vj->location)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="meta-label">Location</p>
                        <p class="meta-value truncate">{{ $vj->location }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-blue-100"><i class="fas fa-clock text-blue-600 text-base"></i></span>
                    <div>
                        <p class="meta-label">Employment Type</p>
                        <p class="meta-value">{{ $vj->employment_type }}</p>
                        <p class="meta-sub">{{ $vj->experience_level }}</p>
                    </div>
                </div>

                @if($vj->salary)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-emerald-100"><i class="fas fa-money-bill-wave text-emerald-600 text-base"></i></span>
                    <div>
                        <p class="meta-label">Salary</p>
                        <p class="meta-value">{{ $vj->salary }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl border {{ $vIsExp ? 'bg-red-50 border-red-200' : ($vIsUrgent ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-100') }}">
                    <span class="meta-row-icon {{ $vIsExp ? 'bg-red-100' : ($vIsUrgent ? 'bg-amber-100' : 'bg-blue-100') }}">
                        <i class="fas fa-calendar-xmark text-base {{ $vIsExp ? 'text-red-600' : ($vIsUrgent ? 'text-amber-600' : 'text-blue-600') }}"></i>
                    </span>
                    <div>
                        <p class="meta-label">Deadline</p>
                        <p class="meta-value {{ $vIsExp ? 'text-red-700' : ($vIsUrgent ? 'text-amber-700' : '') }}">{{ $vDl->format('F d, Y') }}</p>
                        <p class="meta-sub {{ $vIsExp ? 'text-red-600' : ($vIsUrgent ? 'text-amber-600' : '') }}">
                            @if($vIsExp) Closed
                            @elseif($vDaysLeft === 0) Closing today!
                            @elseif($vDaysLeft === 1) Tomorrow
                            @else {{ $vDaysLeft }} days left
                            @endif
                        </p>
                    </div>
                </div>

                @if($vj->target_college)
                <div class="p-3.5 rounded-xl bg-purple-50 border border-purple-100">
                    <p class="meta-label text-purple-600 mb-2">Target Colleges</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $vj->target_college) as $col)
                            <span class="inline-flex items-center text-xs font-semibold px-2 py-1 rounded-lg bg-white text-purple-700 border border-purple-200">{{ trim($col) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Posted By --}}
                <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="meta-label mb-2">Posting Details</p>
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-xs">
                            <span style="color:#777777;">Posted by</span>
                            <span class="font-semibold" style="color:#333333;">{{ $vOrgName ?? $myDisplayName }}</span>
                        </div>
                        @if($vOrgCollege || !$vOrgName)
                        <div class="flex justify-between text-xs">
                            <span style="color:#777777;">College</span>
                            <span class="font-semibold" style="color:#7a3f91;">{{ $vOrgCollege ?? 'Alumni Director' }}</span>
                        </div>
                        @endif
                        <div class="flex justify-between text-xs">
                            <span style="color:#777777;">Submitted</span>
                            <span class="font-semibold" style="color:#333333;">{{ $vCreatedPH->format('M d, Y') }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span style="color:#777777;">Last updated</span>
                            <span class="font-semibold" style="color:#333333;">{{ $vUpdatedPH->diffForHumans() }}</span>
                        </div>
                        @if($vj->updated_by)
                        <div class="flex justify-between text-xs">
                            <span style="color:#777777;">Updated by</span>
                            <span class="font-semibold" style="color:#7a3f91;">{{ $vj->updated_by }}</span>
                        </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ RIGHT: Content ═══ --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            {{-- Badges bar --}}
            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700">
                        <i class="fas fa-clock text-[10px]"></i> {{ $vj->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200" style="color:#333333;">
                        <i class="fas fa-layer-group text-[10px]"></i> {{ $vj->experience_level }}
                    </span>
                    @if($vIsUrgent)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-red-50 border border-red-200 text-red-600">
                            <i class="fas fa-fire text-[10px]"></i>
                            {{ $vDaysLeft === 0 ? 'Closing today!' : ($vDaysLeft === 1 ? '1 day left' : $vDaysLeft.' days left') }}
                        </span>
                    @endif
                    @if($vIsExp)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-red-100 border border-red-200 text-red-700">
                            <i class="fas fa-calendar-xmark text-[10px]"></i> Deadline Passed
                        </span>
                    @endif
                </div>
            </div>

            {{-- Scrollable content --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-5 py-4 flex flex-col gap-4">

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        Job Description
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($vj->description) }}</div>
                </div>

                @if($vj->qualifications)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-purple-50">
                            <i class="fas fa-list-check text-purple-500 text-[10px]"></i>
                        </span>
                        Qualifications
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($vj->qualifications) }}</div>
                </div>
                @endif

                @if($vj->application_instructions)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-emerald-50">
                            <i class="fas fa-paper-plane text-emerald-500 text-[10px]"></i>
                        </span>
                        How to Apply
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-emerald-50/50 rounded-lg p-4 border border-emerald-100" style="line-height:1.75; color:#333333;">{{ trim($vj->application_instructions) }}</div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     EDIT JOB — FULL SCREEN 3-COLUMN
════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
@php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in"
     @keydown.escape.window="$wire.closeEditModal()">

    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Edit Job Posting</h2>
                <p class="text-white/60 text-xs mt-0.5">Update the job details below</p>
            </div>
        </div>
        <button wire:click="closeEditModal" type="button"
                class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark text-sm"></i><span class="hidden sm:inline text-sm">Close</span>
        </button>
    </div>

    {{-- Deadline warning --}}
    @php $editJobDeadlinePassed = $editDeadline && \Carbon\Carbon::createFromFormat('Y-m-d', $editDeadline, 'Asia/Manila')->endOfDay() < now('Asia/Manila'); @endphp
    @if($editJobDeadlinePassed)
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 shrink-0 flex items-center gap-3">
        <i class="fas fa-triangle-exclamation text-amber-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-amber-800 font-semibold">Deadline has already passed. Update it to re-activate this job.</p>
    </div>
    @endif

    {{-- Validation Errors Banner --}}
    @if(count($editErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-3 shrink-0">
        <p class="font-semibold text-red-800 text-sm mb-1 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation text-xs"></i> Please fix the following:
        </p>
        <ul class="text-red-700 text-xs space-y-0.5">
            @foreach($editErrors as $err)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- 3-COLUMN BODY --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-0 overflow-hidden">

        {{-- ═══ LEFT: Organization + Target Colleges ═══ --}}
        <div class="w-full lg:w-[300px] xl:w-[340px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto scroll-c bg-white"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-building text-[9px]"></i> Organization Details
                    </div>
                    <div class="card-section-body space-y-4">
                        @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp

                        <div>
                            <label class="form-label">Organization Type <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCompanyType"
                                    class="form-input {{ isset($editErrors['editCompanyType']) ? 'error' : '' }}">
                                <option value="">Select Organization</option>
                                @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editCompanyType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                        </div>

                        <div>
                            <label class="form-label">Company Name <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editCompany" type="text" maxlength="150"
                                   @if($editIsPhilcst) readonly @endif
                                   class="form-input {{ isset($editErrors['editCompany']) ? 'error' : '' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                   style="{{ $editIsPhilcst ? 'color:#999999;' : '' }}">
                            @if(isset($editErrors['editCompany']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompany'] }}</p>@endif
                        </div>

                        <div>
                            <label class="form-label">Location <span class="text-red-500">*</span></label>
                            <input wire:model="editLocation" type="text" maxlength="120"
                                   @if($editIsPhilcst) readonly @endif
                                   class="form-input {{ isset($editErrors['editLocation']) ? 'error' : '' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                   style="{{ $editIsPhilcst ? 'color:#999999;' : '' }}">
                            @if(isset($editErrors['editLocation']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editLocation'] }}</p>@endif
                        </div>
                    </div>
                </div>

                {{-- Target Colleges --}}
                <div class="card-section {{ isset($editErrors['editTargetColleges']) ? 'border-red-300' : '' }}">
                    <div class="card-section-hd">
                        <i class="fas fa-building-columns text-[9px]"></i> Target Colleges
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body space-y-2">
                        <label class="flex items-center gap-2 px-3 py-2 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                            <input type="checkbox" wire:model.live="editAllColleges" class="w-4 h-4 flex-shrink-0" style="accent-color:#7a3f91;">
                            <span class="text-sm font-semibold" style="color:#5e2f72;">All Colleges</span>
                        </label>
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach($this->collegesWithDepts as $college)
                                <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer transition text-sm font-semibold
                                              {{ in_array($college['name'], $editTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40' }}"
                                       style="{{ !in_array($college['name'], $editTargetColleges) ? 'color:#555555;' : '' }}">
                                    <input type="checkbox" wire:model.live="editTargetColleges" value="{{ $college['name'] }}"
                                           class="w-4 h-4 flex-shrink-0" style="accent-color:#7a3f91;">
                                    <span class="truncate text-xs">{{ $college['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if(isset($editErrors['editTargetColleges']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editTargetColleges'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ MIDDLE: Job Info + Description + Qualifications + How to Apply ═══ --}}
        <div class="flex-1 min-w-0 overflow-y-auto scroll-c border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-briefcase text-[9px]"></i> Job Information
                    </div>
                    <div class="card-section-body space-y-4">

                        <div>
                            <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                                   class="form-input {{ isset($editErrors['editJobTitle']) ? 'error' : '' }}">
                            @if(isset($editErrors['editJobTitle']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="editEmpType"
                                        class="form-input {{ isset($editErrors['editEmpType']) ? 'error' : '' }}">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($editErrors['editEmpType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                                <select wire:model.defer="editExpLevel"
                                        class="form-input {{ isset($editErrors['editExpLevel']) ? 'error' : '' }}">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}" @selected($editExpLevel === $lvl)>{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($editErrors['editExpLevel']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Salary <span class="font-normal normal-case tracking-normal" style="color:#777777;">— optional</span></label>
                                <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25,000 / month" class="form-input">
                            </div>
                            <div>
                                <label class="form-label">Application Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="editDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="form-input {{ isset($editErrors['editDeadline']) ? 'error' : '' }}">
                                @if(isset($editErrors['editDeadline']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                                @if($editingJob && $editingJob->status === 'INACTIVE')
                                    <p class="text-xs mt-1 text-amber-600 font-semibold"><i class="fas fa-lightbulb mr-1"></i>Set a future deadline → save → job auto-activates.</p>
                                @endif
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-file-lines text-[9px]"></i> Description
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="editDescription" rows="6" maxlength="5000"
                                  placeholder="Describe the role, responsibilities…"
                                  class="form-input resize-none {{ isset($editErrors['editDescription']) ? 'error' : '' }}"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                </div>

                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-list-check text-[9px]"></i> Qualifications
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="editQualifications" rows="5" maxlength="3000"
                                  placeholder="e.g. Bachelor's degree in relevant field…"
                                  class="form-input resize-none {{ isset($editErrors['editQualifications']) ? 'error' : '' }}"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>
                </div>

                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-paper-plane text-[9px]"></i> How to Apply
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="card-section-body">
                        <textarea wire:model.defer="editApplicationInstructions" rows="5" maxlength="3000"
                                  placeholder="e.g. Send your resume to hr@company.com…"
                                  class="form-input resize-none {{ isset($editErrors['editApplicationInstructions']) ? 'error' : '' }}"></textarea>
                        @if(isset($editErrors['editApplicationInstructions']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editApplicationInstructions'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ RIGHT: Tips + Actions ═══ --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 overflow-y-auto scroll-c bg-white flex flex-col"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4 flex-1">

                <div class="card-section">
                    <div class="card-section-hd"><i class="fas fa-lightbulb text-[9px]"></i> Edit Tips</div>
                    <div class="card-section-body">
                        <ul class="space-y-2.5">
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Changes are saved immediately — no approval required.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>If inactive with a past deadline, update the deadline — the job will auto-activate on save.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Otherwise, editing does not change the Active/Inactive status.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>After saving, use <strong>Share</strong> to notify alumni and coordinators.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            {{-- Action Buttons --}}
            <div class="shrink-0 px-4 py-4 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="saveEditJob"
                        wire:loading.attr="disabled" wire:target="saveEditJob"
                        class="w-full px-5 py-3.5 rounded-xl text-base font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color:#7a3f91;"
                        onmouseover="this.style.backgroundColor='#5e2f72'"
                        onmouseout="this.style.backgroundColor='#7a3f91'">
                    <span wire:loading wire:target="saveEditJob">
                        <i class="fas fa-spinner animate-spin text-sm"></i> Saving…
                    </span>
                    <span wire:loading.remove wire:target="saveEditJob">
                        <i class="fas fa-floppy-disk text-sm"></i> Save Changes
                    </span>
                </button>
                <button type="button" wire:click="closeEditModal"
                        class="w-full px-5 py-2.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i>Cancel
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ════ MODAL: Confirm Toggle ════ --}}
@if($showConfirmModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelConfirm()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in">

        @if($confirmAction === 'ACTIVE')
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background:#059669;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Activate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will make it visible to alumni</p>
            </div>
        </div>
        @else
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background:#d97706;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-ban text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Deactivate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will hide it from alumni</p>
            </div>
        </div>
        @endif

        <div class="p-6 bg-white">
            <p class="text-sm mb-4" style="color:#555555;">
                This job will be marked as <strong>{{ $confirmAction }}</strong>.
                @if($confirmAction === 'INACTIVE') It will be hidden from alumni but can still be edited.@endif
            </p>

            @if($confirmAction === 'ACTIVE')
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0"></i>
                <span style="color:#065f46;">Alumni will be able to see and apply to this job posting once activated.</span>
            </div>
            @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span style="color:#78350f;">Alumni won't see this job posting until you re-activate it. No data will be lost.</span>
            </div>
            @endif

            <div class="flex gap-3">
                <button wire:click="cancelConfirm"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    Cancel
                </button>
                @if($confirmAction === 'ACTIVE')
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60"
                        style="background-color:#059669;">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle"><i class="fas fa-circle-check mr-1"></i> Yes, Activate</span>
                </button>
                @else
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60"
                        style="background-color:#d97706;">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle"><i class="fas fa-ban mr-1"></i> Yes, Deactivate</span>
                </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════ MODAL: Restore ════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in">
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background:#ea580c;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-rotate-left text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Restore Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">Bring this posting back</p>
            </div>
        </div>
        <div class="p-6 bg-white">
            <p class="text-sm mb-1" style="color:#555555;">You are about to restore:</p>
            <p class="font-semibold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-blue-500 mt-0.5 flex-shrink-0"></i>
                <span style="color:#1e40af;">The job will be restored. If its deadline has passed it will be set to <strong>Inactive</strong> — update the deadline to re-activate it.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    Cancel
                </button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60"
                        style="background-color:#ea580c;">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i> Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in">
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
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition flex items-center justify-center gap-2 cursor-pointer bg-white" style="color:#333333;">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     SHARE JOB — SLIDE-OVER (from organizer design)
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
         copied: false, fbCopied: false, messengerCopied: false,
         fbText:  {{ json_encode($sjPostText) }},
         baseUrl: {{ json_encode($sjBaseUrl) }},
         close() { this.open=false; setTimeout(()=>$wire.closeShareJobModal(),290); },
         async copyText(text) {
             try {
                 if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(text);}
                 else{const ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.focus();ta.select();document.execCommand('copy');document.body.removeChild(ta);}
             } catch(e){}
         },
         async shareOnFacebook() { await this.copyText(this.fbText); this.fbCopied=true; window.open('https://www.facebook.com/','_blank','noopener,noreferrer'); setTimeout(()=>{this.fbCopied=false;},9000); },
         async shareOnMessenger() { await this.copyText(this.fbText); this.messengerCopied=true; const isMobile=/Android|iPhone|iPad|iPod/i.test(navigator.userAgent); if(isMobile){window.location.href='fb-messenger://share/?link='+encodeURIComponent(this.baseUrl);setTimeout(()=>window.open('https://www.messenger.com/','_blank','noopener'),1500);}else{window.open('https://www.messenger.com/','_blank','noopener');} setTimeout(()=>{this.messengerCopied=false;},9000); },
         async copyLinkFn() { await this.copyText(this.baseUrl); this.copied=true; setTimeout(()=>this.copied=false,2500); }
     }"
     x-init="requestAnimationFrame(()=>{ open=true })"
     @keydown.escape.window="close()">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Panel Header --}}
        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5" style="color:#333333;">
                <i class="fas fa-share-nodes text-sky-600 text-sm"></i>
                <span>Share Job Posting</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#333333;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Two-column body --}}
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post preview</p>

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

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the post caption to your clipboard and opens the platform.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Staff Channel</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Posts the job directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Share Buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">Text copied! Facebook is open.</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Paste with <strong>Ctrl+V</strong> in the post composer.</p>
                    </div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Text copied! Messenger is open.</p>
                        <p class="text-xs text-blue-700 mt-0.5">Paste with <strong>Ctrl+V</strong> in any chat.</p>
                    </div>
                </div>

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

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_adm2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_adm2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens messenger.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post to staff</span>
                    </div>
                </div>

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

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white" style="color:#333333;">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#999999;">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1" style="color:#666666;">
                    <i class="fas fa-xmark mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>