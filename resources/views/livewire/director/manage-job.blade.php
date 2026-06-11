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

    public string $search         = '';
    public string $filterStatus   = '';
    public string $filterType     = '';
    public string $filterCollege  = '';
    public string $filterSort     = 'recent';

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

    public function updatingSearch()        { $this->resetPage(); }
    public function updatingFilterStatus()  { $this->resetPage(); }
    public function updatingFilterType()    { $this->resetPage(); }
    public function updatingFilterCollege() { $this->resetPage(); }
    public function updatingFilterSort()    { $this->resetPage(); }

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

        if ($this->filterCollege !== '') {
            $college = $this->sanitize($this->filterCollege);
            $q->where(function($sub) use ($college) {
                $sub->where('target_college', 'like', "%{$college}%");
            });
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
        $this->search = $this->filterStatus = $this->filterType = $this->filterCollege = '';
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
        $job = JobPosting::find($id);
        if ($job && is_null($job->organizer_id)) {
            $this->openEditModal($id);
            return;
        }
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

        if (!is_null($job->organizer_id)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only director-posted jobs can be edited.');
            return;
        }

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

        $checkJob = JobPosting::find($this->editingJobId);
        if ($checkJob && !is_null($checkJob->organizer_id)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only director-posted jobs can be edited.');
            $this->showEditModal = false;
            return;
        }

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

        if (!is_null($job->organizer_id)) {
            $this->dispatch('flash-message', type: 'error', message: 'Only director-posted jobs can be activated or deactivated here.');
            return;
        }

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
        if ($this->showEditModal) { $this->showEditModal = false; $this->resetEditFields(); }
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
        $this->showEditModal     = false;
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

<div class="flex flex-col h-[90vh] overflow-hidden">

<style>[x-cloak]{display:none!important}</style>

{{-- Row hover tooltip --}}
<div id="eo-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)] transition-opacity duration-150"
     style="transform:translate(12px,-110%);">
    View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

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
<div class="flex flex-col flex-1 min-h-0 gap-4 px-5 sm:px-7 lg:px-10 pt-5 pb-5 max-w-screen-2xl mx-auto w-full">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Job Overview</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">Review, moderate, and manage all job postings.</p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-briefcase text-purple-600 text-[10px]"></i>
                {{ $this->jobPostings->total() }} {{ $this->jobPostings->total() !== 1 ? 'Jobs' : 'Job' }}
            </span>

            {{-- Post Job button --}}
            <div class="relative inline-flex flex-col items-center group">
                <button wire:click="openPostModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <i class="fas fa-plus text-sm"></i>
                </button>
                <div class="absolute top-full mt-2 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50 shadow-lg">
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                    Post a Job
                </div>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">

            <div class="flex items-center px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 pl-9 rounded-lg w-full transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] placeholder-[#a78bbd]"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg appearance-none cursor-pointer transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] pr-8"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-repeat:no-repeat;background-size:1.25em 1.25em;">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            {{-- Employment Type --}}
            <select wire:model.live="filterType"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg appearance-none cursor-pointer transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] pr-8 hidden sm:block"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-repeat:no-repeat;background-size:1.25em 1.25em;">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            {{-- College Filter --}}
            <select wire:model.live="filterCollege"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg appearance-none cursor-pointer transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] pr-8 hidden sm:block"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-repeat:no-repeat;background-size:1.25em 1.25em;">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>

            {{-- Active filter pills --}}
            @if($filterStatus)
            @php
                $statusPillMap = [
                    'ACTIVE'   => ['label' => 'Active',   'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'INACTIVE' => ['label' => 'Inactive', 'cls' => 'bg-amber-50 border-amber-300 text-amber-800'],
                ];
                $sPill = $statusPillMap[$filterStatus] ?? null;
            @endphp
            @if($sPill)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border-[1.5px] {{ $sPill['cls'] }}">
                <i class="fas fa-filter text-[9px]"></i>{{ $sPill['label'] }}
                <button wire:click="$set('filterStatus', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif
            @endif

            @if($filterType)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border-[1.5px] bg-blue-50 border-blue-300 text-blue-800">
                <i class="fas fa-filter text-[9px]"></i>{{ $filterType }}
                <button wire:click="$set('filterType', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            @if($filterCollege)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border-[1.5px] bg-purple-50 border-purple-300 text-purple-800">
                <i class="fas fa-building-columns text-[9px]"></i>{{ $filterCollege }}
                <button wire:click="$set('filterCollege', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333] bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer hover:bg-gray-50">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline text-[#333333]">Reset</span>
            </button>

            {{-- Mobile-only selects --}}
            <select wire:model.live="filterType"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg appearance-none cursor-pointer flex-1 sm:hidden pr-8"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-repeat:no-repeat;background-size:1.25em 1.25em;">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCollege"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg appearance-none cursor-pointer flex-1 sm:hidden pr-8"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.6rem center;background-repeat:no-repeat;background-size:1.25em 1.25em;">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col bg-white">

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10 text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Type</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest w-24 text-[#555555]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $isOrgDel         = $job->status === 'ORGANIZER_DELETED';
                            $isActive         = $job->status === 'ACTIVE';
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $daysLeft         = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
                            $isUrgent         = $daysLeft <= 7 && !$isDeadlinePassed;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                            $canShare         = $isActive && !$isDeadlinePassed;
                            $isDirectorJob    = is_null($job->organizer_id);
                        @endphp
                        <tr class="bg-white cursor-pointer hover:bg-[#f5f0fa] transition-colors duration-100"
                            wire:click="viewJob({{ $job->id }})"
                            data-eo-row>

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center w-10">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5 max-w-[200px]">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333] {{ $isOrgDel ? 'line-through opacity-60' : '' }}">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5 text-[#777777]">{{ $job->created_at->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell max-w-[160px]">
                                @if($organizerName)
                                    <p class="text-sm font-semibold text-[#333333] truncate">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5 text-[#777777] truncate">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold whitespace-nowrap">
                                        Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 whitespace-nowrap">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isOrgDel)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        Deleted by Org.
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        Inactive
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>
                                    @if($canShare && !$isOrgDel)
                                        <div class="relative inline-flex group" data-eo-action>
                                            <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer">
                                                <i class="fas fa-share-nodes"></i>
                                            </button>
                                            <div class="absolute bottom-full mb-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Share<span class="absolute top-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
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
                    <p class="font-semibold text-base text-[#333333]">
                        @if($search || $filterStatus || $filterType || $filterCollege) No jobs match your filters
                        @else No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($search || $filterStatus || $filterType || $filterCollege) Try clearing your filters to see all postings.
                        @else Click the <strong>+</strong> button to post the first listing.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterType || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ── PAGINATION ── --}}
        @php
            $total   = $this->jobPostings->total();
            $pp      = $this->jobPostings->perPage();
            $cp      = $this->jobPostings->currentPage();
            $lp      = $this->jobPostings->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="flex-shrink-0 border-t border-[#7a3f91]/30 px-4 min-h-[48px] flex items-center justify-between gap-2 flex-wrap py-2"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                job{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterType || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif>
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>{{-- /table-block --}}

</div>{{-- /main layout --}}


{{-- ════════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN 3-COLUMN
════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 overflow-hidden"
     style="animation: fadeIn .22s cubic-bezier(.4,0,.2,1) both;"
     @keydown.escape.window="$wire.closePostModal()">
    <style>@keyframes fadeIn{from{opacity:0}to{opacity:1}}</style>

    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-base leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs mt-0.5">Fill in the details — job goes live immediately</p>
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            <button wire:click="closePostModal" type="button"
                    class="relative w-8 h-8 rounded-lg flex items-center justify-center bg-white/10 border border-white/15 hover:bg-white/22 transition active:scale-95 group"
                    aria-label="Close">
                <i class="fas fa-xmark text-white text-sm"></i>
                <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </button>
        </div>
    </div>

    {{-- Validation Errors Banner --}}
    @if(count($postErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-2 shrink-0 flex items-start gap-3">
        <i class="fas fa-triangle-exclamation text-red-500 mt-0.5 flex-shrink-0 text-xs"></i>
        <div class="flex-1 min-w-0">
            <p class="font-semibold text-red-800 text-xs mb-0.5">Please fix the following:</p>
            <ul class="text-red-700 text-xs flex flex-wrap gap-x-4 gap-y-0.5">
                @foreach($postErrors as $err)
                    <li class="flex items-center gap-1"><span class="text-red-400">&bull;</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    {{-- 3-COLUMN BODY --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Organization + Target Colleges --}}
        <div class="w-full lg:w-[290px] xl:w-[310px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="p-3 space-y-3">

                {{-- Organization Category --}}
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postOrgCategory']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Organization
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach([['philcst','PHILCST Campus','Internal department'],['partner','Partner Company','Known partner organization'],['custom','Other / Custom','Enter manually']] as [$val,$label,$sub])
                            <button type="button" wire:click="$set('postOrgCategory','{{ $val }}')"
                                    class="px-2.5 py-2 border-2 rounded-xl bg-white cursor-pointer transition text-left font-semibold flex items-center gap-2.5 text-sm
                                           {{ $postOrgCategory===$val ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50 text-[#333333]' }}"
                                    style="{{ $postOrgCategory===$val ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <div>
                                    <span class="block">{{ $label }}</span>
                                    <span class="block font-normal opacity-70 text-xs">{{ $sub }}</span>
                                </div>
                            </button>
                            @endforeach
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-red-600 flex items-center gap-1 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif

                        @if($postOrgCategory === 'philcst' && $philcstName)
                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-xl px-2.5 py-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-white shadow-sm" style="background:linear-gradient(135deg,#7a3f91,#6a3580);">
                                <i class="fas fa-school text-xs"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-[#4c1d95] truncate text-sm">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-[#7c3aed] truncate mt-0.5 text-[0.65rem]">{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 font-semibold text-purple-700 bg-white border border-purple-200 px-1.5 py-0.5 rounded-full shrink-0 text-[0.6rem]">
                                Auto
                            </span>
                        </div>
                        @endif

                        @if($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),loc:@js($postLocation),syncN(v){$wire.set('postPartnerName',v,false)},syncT(v){$wire.set('postPartnerType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncN(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postPartnerName']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncT(pType)" type="text" placeholder="e.g. Private, NGO" maxlength="100"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postPartnerType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        @if($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),loc:@js($postLocation),syncN(v){$wire.set('postCustomName',v,false)},syncT(v){$wire.set('postCustomType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncN(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postCustomName']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncT(cType)" type="text" placeholder="e.g. Government, NGO" maxlength="100"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postCustomType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        @if(!$postOrgCategory)
                        <div class="text-center py-3 text-[#777777]">
                            <p class="text-xs">Select a category above to continue.</p>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Target Colleges --}}
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postTargetColleges']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Target Colleges
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <label class="flex items-center gap-2 px-3 py-2 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                            <input type="checkbox" wire:model.live="postAllColleges" class="w-4 h-4 flex-shrink-0 accent-[#7a3f91]">
                            <span class="text-sm font-semibold text-[#5e2f72]">All Colleges</span>
                        </label>
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach($this->collegesWithDepts as $college)
                                <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer transition font-semibold
                                              {{ in_array($college['name'], $postTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40 text-[#555555]' }}">
                                    <input type="checkbox" wire:model.live="postTargetColleges" value="{{ $college['name'] }}"
                                           class="w-4 h-4 flex-shrink-0 accent-[#7a3f91]">
                                    <span class="truncate text-xs">{{ $college['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if(isset($postErrors['postTargetColleges']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postTargetColleges'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE: Job Info + Textareas --}}
        <div class="flex-1 min-w-0 overflow-y-auto border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="p-3 space-y-3">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Job Information
                    </div>
                    <div class="p-3.5 space-y-3">
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                                   class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postJobTitle']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            @if(isset($postErrors['postJobTitle']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postEmpType"
                                        class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postEmpType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition appearance-none pr-8"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postEmpType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postExpLevel"
                                        class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postExpLevel']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition appearance-none pr-8"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}">{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postExpLevel']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Salary <span class="font-normal normal-case tracking-normal text-[#777777] text-xs">— optional</span></label>
                                <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000/mo" maxlength="100"
                                       class="w-full px-3.5 py-2.5 border-[1.5px] border-gray-300 rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            </div>
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="postDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                @if(isset($postErrors['postDeadline']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                            </div>
                        </div>
                    </div>
                </div>

                @foreach([['postDescription','Description','Describe the role, responsibilities, and what the candidate will be doing…','5000'],['postQualifications','Qualifications','e.g. Bachelor\'s degree in a relevant field, at least 1 year experience…','3000'],['postApplicationInstructions','How to Apply','e.g. Send your resume to hr@company.com with subject: Application – [Position]','3000']] as [$field,$title,$placeholder,$maxlen])
                <div class="bg-white border-[1.5px] {{ isset($postErrors[$field]) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        {{ $title }} <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5">
                        <textarea wire:model.defer="{{ $field }}"
                                  style="height:clamp(80px,12vh,200px);"
                                  placeholder="{{ $placeholder }}" maxlength="{{ $maxlen }}"
                                  class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors[$field]) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"></textarea>
                        @if(isset($postErrors[$field]))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors[$field] }}</p>@endif
                    </div>
                </div>
                @endforeach

            </div>
        </div>

        {{-- RIGHT: Posted As + Tips + Actions --}}
        <div class="w-full lg:w-[240px] xl:w-[260px] shrink-0 overflow-y-auto bg-white flex flex-col" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="p-3 space-y-3 flex-1">

                {{-- Posted As --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Posted As
                    </div>
                    <div class="p-3.5">
                        <div class="flex items-center gap-2.5 bg-purple-50 border border-purple-100 rounded-xl px-2.5 py-2">
                            <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-purple-800 truncate text-sm">{{ $myDisplayName }}</div>
                                <div class="text-purple-600 mt-0.5 text-[0.65rem]">Alumni Director · visible to selected colleges</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tips --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-1.5">
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Set a future deadline — past deadlines auto-deactivate.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Include salary — listings with salary attract more applicants.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Select target colleges carefully — only those alumni will see it.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Job goes live immediately — no approval required.</span></li>
                        </ul>
                    </div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                    <p class="font-semibold text-emerald-800 flex items-center gap-1.5 text-sm">
                        <i class="fas fa-circle-check text-emerald-500 text-sm"></i> Ready to post
                    </p>
                    <p class="text-emerald-700 mt-1 text-[0.68rem]">Job goes live immediately after submitting. No approval required.</p>
                </div>
            </div>

            <div class="shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="w-full px-4 py-2.5 rounded-xl font-semibold text-white text-sm transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <span wire:loading wire:target="savePost"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane text-xs"></i></span>
                    <span wire:loading.remove wire:target="savePost">Post Job</span>
                    <span wire:loading wire:target="savePost">Posting…</span>
                </button>
                <button type="button" wire:click="closePostModal"
                        class="w-full px-4 py-2 rounded-xl font-semibold text-sm bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333]">
                    <i class="fas fa-xmark mr-1 text-[10px]"></i>Cancel
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     VIEW JOB — FULL SCREEN 2-COLUMN
     FIX: Remove all FontAwesome icons from meta cards and section headers
          Keep only status pills and tag pills clean (no icons)
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
    $vCanShare      = $isActive && !$vIsExp;
    $vIsDirectorJob = is_null($vj->organizer_id);
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden"
     style="animation: fadeIn .22s cubic-bezier(.4,0,.2,1) both;"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Clean Header --}}
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

        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($vCanShare && !$isOrgDel)
                <div class="relative group">
                    <button wire:click="openShareJobModal({{ $vj->id }})" type="button"
                            class="w-8 h-8 rounded-lg flex items-center justify-center bg-white/14 border border-white/20 hover:bg-white/24 transition active:scale-95">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                    </button>
                    <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                    </div>
                </div>
            @endif

            @if($isOrgDel)
                <div class="relative group">
                    <button wire:click="confirmRestore({{ $vj->id }})" type="button"
                            class="w-8 h-8 rounded-lg flex items-center justify-center bg-orange-400/12 border border-orange-400/25 hover:bg-orange-400/22 transition active:scale-95">
                        <i class="fas fa-rotate-left text-orange-300 text-sm"></i>
                    </button>
                    <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Restore<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                    </div>
                </div>
            @endif

            <div class="relative group">
                <button wire:click="closeViewModal" type="button"
                        class="w-8 h-8 rounded-lg flex items-center justify-center bg-white/10 border border-white/15 hover:bg-white/22 transition active:scale-95">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    @if($isOrgDel)
    <div class="bg-red-50 border-b border-red-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <p class="text-sm text-red-800 font-semibold">
            Deleted by <strong>{{ $vj->deleted_by ?? $vj->organizer?->name ?? 'Coordinator' }}</strong>
            · {{ $vUpdatedPH->format('M d, Y') }} — you can restore this posting.
        </p>
    </div>
    @endif

    {{-- 2-Column Body --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Meta Info — scrollable internally --}}
        <div class="w-full lg:w-[300px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">

            {{-- Status block — clean, no icons --}}
            <div class="mx-4 mt-4 mb-2 shrink-0">
                <div class="flex items-center gap-2 flex-wrap">
                    @if($isOrgDel)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border border-red-200 bg-red-50 text-red-700">
                            Deleted by Org.
                        </span>
                    @elseif($isActive)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border border-emerald-200 bg-emerald-50 text-emerald-700">
                            Active
                        </span>
                    @else
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border border-amber-200 bg-amber-50 text-amber-700">
                            Inactive
                        </span>
                    @endif
                    @if($vOrgName)
                        <span class="text-xs font-semibold text-[#555555]">{{ $vOrgName }}</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-col gap-2 px-4 pb-4">

                {{-- Company — no icon badge, clean rows --}}
                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-0.5">Company</p>
                    <p class="text-sm font-bold text-[#333333] leading-tight">{{ $vj->company_name }}</p>
                    <p class="text-xs text-[#555555] mt-0.5">{{ $displayType }}</p>
                </div>

                @if($vj->location)
                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-0.5">Location</p>
                    <p class="text-sm font-bold text-[#333333] leading-tight">{{ $vj->location }}</p>
                </div>
                @endif

                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-0.5">Employment Type</p>
                    <p class="text-sm font-bold text-[#333333]">{{ $vj->employment_type }}</p>
                    <p class="text-xs text-[#555555] mt-0.5">{{ $vj->experience_level }}</p>
                </div>

                @if($vj->salary)
                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-0.5">Salary</p>
                    <p class="text-sm font-bold text-[#333333]">{{ $vj->salary }}</p>
                </div>
                @endif

                <div class="p-3 rounded-xl border {{ $vIsExp ? 'bg-red-50 border-red-200' : ($vIsUrgent ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-100') }}">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-0.5">Deadline</p>
                    <p class="text-sm font-bold {{ $vIsExp ? 'text-red-700' : ($vIsUrgent ? 'text-amber-700' : 'text-[#333333]') }}">{{ $vDl->format('F d, Y') }}</p>
                    <p class="text-xs mt-0.5 {{ $vIsExp ? 'text-red-600' : ($vIsUrgent ? 'text-amber-600' : 'text-[#555555]') }}">
                        @if($vIsExp) Closed
                        @elseif($vDaysLeft === 0) Closing today!
                        @elseif($vDaysLeft === 1) Tomorrow
                        @else {{ $vDaysLeft }} days left
                        @endif
                    </p>
                </div>

                @if($vj->target_college)
                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-1.5">Target Colleges</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach(explode(',', $vj->target_college) as $col)
                            <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-lg bg-white text-[#555555] border border-gray-200">{{ trim($col) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.07em] text-[#555555] mb-1.5">Posting Details</p>
                    <div class="space-y-1">
                        <div class="flex justify-between text-xs">
                            <span class="text-[#777777]">Posted by</span>
                            <span class="font-semibold text-[#333333]">{{ $vOrgName ?? $myDisplayName }}</span>
                        </div>
                        @if($vOrgCollege || !$vOrgName)
                        <div class="flex justify-between text-xs">
                            <span class="text-[#777777]">College</span>
                            <span class="font-semibold text-[#7a3f91]">{{ $vOrgCollege ?? 'Alumni Director' }}</span>
                        </div>
                        @endif
                        <div class="flex justify-between text-xs">
                            <span class="text-[#777777]">Submitted</span>
                            <span class="font-semibold text-[#333333]">{{ $vCreatedPH->format('M d, Y') }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-[#777777]">Last updated</span>
                            <span class="font-semibold text-[#333333]">{{ $vUpdatedPH->diffForHumans() }}</span>
                        </div>
                        @if($vj->updated_by)
                        <div class="flex justify-between text-xs">
                            <span class="text-[#777777]">Updated by</span>
                            <span class="font-semibold text-[#7a3f91]">{{ $vj->updated_by }}</span>
                        </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT: Content — scrollable internally --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            {{-- Tag pills bar --}}
            <div class="shrink-0 px-5 py-2.5 bg-white border-b border-gray-200">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700">
                        {{ $vj->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200 text-[#333333]">
                        {{ $vj->experience_level }}
                    </span>
                    @if($vIsUrgent)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-50 border border-red-200 text-red-600">
                            {{ $vDaysLeft === 0 ? 'Closing today!' : ($vDaysLeft === 1 ? '1 day left' : $vDaysLeft.' days left') }}
                        </span>
                    @endif
                    @if($vIsExp)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 border border-red-200 text-red-700">
                            Deadline Passed
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto px-5 py-3 flex flex-col gap-3" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">

                {{-- Job Description — clean header, no icon --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-xs font-bold mb-2.5 uppercase tracking-widest text-[#333333]">
                        Job Description
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-3.5 border border-gray-100 text-[#333333]" style="line-height:1.7;">{{ trim($vj->description) }}</div>
                </div>

                @if($vj->qualifications)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-xs font-bold mb-2.5 uppercase tracking-widest text-[#333333]">
                        Qualifications
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-3.5 border border-gray-100 text-[#333333]" style="line-height:1.7;">{{ trim($vj->qualifications) }}</div>
                </div>
                @endif

                @if($vj->application_instructions)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-xs font-bold mb-2.5 uppercase tracking-widest text-[#333333]">
                        How to Apply
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-emerald-50/50 rounded-lg p-3.5 border border-emerald-100 text-[#333333]" style="line-height:1.7;">{{ trim($vj->application_instructions) }}</div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     EDIT JOB — FULL SCREEN
     FIX #2: Header shows only "Edit Mode" title — NO job title subtitle
     FIX #3: No vertical scroll on middle panel — 3 textareas fit in a
             fixed grid so the page never needs to scroll
════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
@php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 overflow-hidden"
     style="animation: fadeIn .22s cubic-bezier(.4,0,.2,1) both;"
     @keydown.escape.window="$wire.closeEditModal()">

    {{-- FIX #2: Top Bar — only "Edit Mode" title, no job title below --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                {{-- Only "Edit Mode" — no subtitle/job title --}}
                <h2 class="text-white font-semibold text-base leading-tight">Edit Mode</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            @if($editingJob)
                @php $editJobIsActive = $editingJob->status === 'ACTIVE'; @endphp
                @if(!$editJobIsActive)
                    <div class="relative group">
                        <button wire:click="confirmToggle({{ $editingJobId }})" type="button"
                                class="w-8 h-8 rounded-lg flex items-center justify-center bg-emerald-400/10 border border-emerald-400/25 hover:bg-emerald-400/20 transition active:scale-95">
                            <i class="fas fa-circle-play text-emerald-300 text-sm"></i>
                        </button>
                        <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                            Activate<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                        </div>
                    </div>
                @else
                    <div class="relative group">
                        <button wire:click="confirmToggle({{ $editingJobId }})" type="button"
                                class="w-8 h-8 rounded-lg flex items-center justify-center bg-amber-400/12 border border-amber-400/25 hover:bg-amber-400/22 transition active:scale-95">
                            <i class="fas fa-circle-pause text-amber-300 text-sm"></i>
                        </button>
                        <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                            Deactivate<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                        </div>
                    </div>
                @endif
                @php
                    $editJobDeadlinePassed = $editingJob && \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay());
                    $editJobCanShare = !$editJobDeadlinePassed && $editJobIsActive;
                @endphp
                @if($editJobCanShare)
                    <div class="relative group">
                        <button wire:click="openShareJobModal({{ $editingJobId }})" type="button"
                                class="w-8 h-8 rounded-lg flex items-center justify-center bg-white/14 border border-white/20 hover:bg-white/24 transition active:scale-95">
                            <i class="fas fa-share-nodes text-white text-sm"></i>
                        </button>
                        <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                            Share<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                        </div>
                    </div>
                @endif
                <div class="relative group">
                    <button wire:click="confirmDelete({{ $editingJobId }})" type="button"
                            class="w-8 h-8 rounded-lg flex items-center justify-center bg-red-400/12 border border-red-400/25 hover:bg-red-400/22 transition active:scale-95">
                        <i class="fas fa-trash text-red-300 text-sm"></i>
                    </button>
                    <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Delete<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                    </div>
                </div>
            @endif
            <div class="relative group">
                <button wire:click="closeEditModal" type="button"
                        class="w-8 h-8 rounded-lg flex items-center justify-center bg-white/10 border border-white/15 hover:bg-white/22 transition active:scale-95">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    @if($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-amber-50 border-b border-amber-200 px-6 py-1.5 shrink-0 flex items-center gap-3">
        <i class="fas fa-circle-pause text-amber-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-amber-800">
            <strong>This job is currently Inactive.</strong>
            @if($editingJob && \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay()))
                The deadline has passed — update it and save; it will auto-reactivate.
            @else
                Use <strong>Activate</strong> (top-right) to go live.
            @endif
        </p>
    </div>
    @endif

    @if(count($editErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 py-2 shrink-0 flex items-start gap-3">
        <i class="fas fa-triangle-exclamation text-red-500 mt-0.5 flex-shrink-0 text-xs"></i>
        <div class="flex-1 min-w-0">
            <p class="font-semibold text-red-800 text-xs mb-0.5">Please fix the following:</p>
            <ul class="text-red-700 text-xs flex flex-wrap gap-x-4 gap-y-0.5">
                @foreach($editErrors as $err)
                    <li class="flex items-center gap-1"><span class="text-red-400">&bull;</span>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    {{-- FIX #3: 3-COLUMN BODY — middle panel uses flex column with equal-height textareas, NO scroll --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Org Details + Job Info + Target Colleges — scrollable --}}
        <div class="w-full lg:w-[300px] xl:w-[320px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="p-2.5 space-y-2.5">

                {{-- Edit Mode badge in left panel --}}
                <div class="flex items-center gap-2 px-1 pt-0.5">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[0.7rem] font-extrabold uppercase tracking-[0.1em] text-[#7a3f91] bg-[#f5eef9] border border-[#d4aaeb]">
                        Edit Mode
                    </span>
                    @if($editingJob)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[0.65rem] font-semibold border {{ $editingJob->status === 'ACTIVE' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                        {{ $editingJob->status === 'ACTIVE' ? 'Active' : 'Inactive' }}
                    </span>
                    @endif
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Organization Details
                    </div>
                    <div class="p-3.5 space-y-2">
                        @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCompanyType"
                                    class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editCompanyType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition appearance-none pr-8"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;">
                                <option value="">Select Organization</option>
                                @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editCompanyType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Company Name <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editCompany" type="text" maxlength="150"
                                   @if($editIsPhilcst) readonly @endif
                                   class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editCompany']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed text-[#999999]' : 'bg-white text-[#222]' }}">
                            @if(isset($editErrors['editCompany']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompany'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input wire:model="editLocation" type="text" maxlength="120"
                                   @if($editIsPhilcst) readonly @endif
                                   class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed text-[#999999]' : 'bg-white text-[#222]' }}">
                            @if(isset($editErrors['editLocation']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editLocation'] }}</p>@endif
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Job Information
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                                   class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editJobTitle']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            @if(isset($editErrors['editJobTitle']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editEmpType"
                                    class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editEmpType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition appearance-none pr-8"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editEmpType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editExpLevel"
                                    class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editExpLevel']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition appearance-none pr-8"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}" @selected($editExpLevel === $lvl)>{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editExpLevel']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Salary <span class="font-normal normal-case tracking-normal text-[#777777] text-[0.6rem]">optional</span></label>
                                <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25k/mo"
                                       class="w-full px-3.5 py-2.5 border-[1.5px] border-gray-300 rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            </div>
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="editDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                @if(isset($editErrors['editDeadline']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                            </div>
                        </div>
                        @if($editingJob && $editingJob->status === 'INACTIVE')
                        <p class="text-xs text-amber-600 font-semibold flex items-center gap-1"><i class="fas fa-lightbulb text-[10px]"></i>Set a future deadline → save → job auto-activates.</p>
                        @endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] {{ isset($editErrors['editTargetColleges']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Target Colleges <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <label class="flex items-center gap-2 px-2.5 py-1.5 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                            <input type="checkbox" wire:model.live="editAllColleges" class="w-3.5 h-3.5 flex-shrink-0 accent-[#7a3f91]">
                            <span class="font-semibold text-[#5e2f72] text-sm">All Colleges</span>
                        </label>
                        <div class="grid grid-cols-1 gap-1">
                            @foreach($this->collegesWithDepts as $college)
                                <label class="flex items-center gap-2 px-2.5 py-1.5 border rounded-xl cursor-pointer transition font-semibold
                                              {{ in_array($college['name'], $editTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40 text-[#555555]' }}">
                                    <input type="checkbox" wire:model.live="editTargetColleges" value="{{ $college['name'] }}"
                                           class="w-3.5 h-3.5 flex-shrink-0 accent-[#7a3f91]">
                                    <span class="truncate text-xs">{{ $college['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if(isset($editErrors['editTargetColleges']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editTargetColleges'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- FIX #3: MIDDLE — 3 textareas in a flex column that fills the viewport WITHOUT scrolling --}}
        {{-- Each textarea gets flex-1 so they share the available height equally --}}
        <div class="flex-1 min-w-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50 flex flex-col overflow-hidden p-3 gap-2">

            {{-- Description --}}
            <div class="flex-1 min-h-0 bg-white border-[1.5px] {{ isset($editErrors['editDescription']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden flex flex-col">
                <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] shrink-0">
                    Description <span class="text-red-400 font-semibold ml-0.5">*</span>
                </div>
                <div class="flex-1 min-h-0 p-3">
                    <textarea wire:model.defer="editDescription"
                              class="w-full h-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editDescription']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                              placeholder="Describe the role, responsibilities, and what the candidate will be doing…"
                              maxlength="5000"></textarea>
                </div>
                @if(isset($editErrors['editDescription']))<p class="text-red-600 flex items-center gap-1 px-3 pb-2 text-[0.7rem] shrink-0"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>

            {{-- Qualifications --}}
            <div class="flex-1 min-h-0 bg-white border-[1.5px] {{ isset($editErrors['editQualifications']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden flex flex-col">
                <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] shrink-0">
                    Qualifications <span class="text-red-400 font-semibold ml-0.5">*</span>
                </div>
                <div class="flex-1 min-h-0 p-3">
                    <textarea wire:model.defer="editQualifications"
                              class="w-full h-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editQualifications']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                              placeholder="e.g. Bachelor's degree in relevant field, at least 1 year experience…"
                              maxlength="3000"></textarea>
                </div>
                @if(isset($editErrors['editQualifications']))<p class="text-red-600 flex items-center gap-1 px-3 pb-2 text-[0.7rem] shrink-0"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editQualifications'] }}</p>@endif
            </div>

            {{-- How to Apply --}}
            <div class="flex-1 min-h-0 bg-white border-[1.5px] {{ isset($editErrors['editApplicationInstructions']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-[0.875rem] overflow-hidden flex flex-col">
                <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] shrink-0">
                    How to Apply <span class="text-red-400 font-semibold ml-0.5">*</span>
                </div>
                <div class="flex-1 min-h-0 p-3">
                    <textarea wire:model.defer="editApplicationInstructions"
                              class="w-full h-full px-3.5 py-2.5 border-[1.5px] {{ isset($editErrors['editApplicationInstructions']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-[0.65rem] text-[0.97rem] bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                              placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]"
                              maxlength="3000"></textarea>
                </div>
                @if(isset($editErrors['editApplicationInstructions']))<p class="text-red-600 flex items-center gap-1 px-3 pb-2 text-[0.7rem] shrink-0"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editApplicationInstructions'] }}</p>@endif
            </div>

        </div>

        {{-- RIGHT: Job History + Tips + Save/Cancel --}}
        <div class="w-full lg:w-[240px] xl:w-[260px] shrink-0 overflow-y-auto bg-white flex flex-col" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="p-3 space-y-3 flex-1">

                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Job History
                    </div>
                    <div class="p-3.5 space-y-1.5">
                        <div>
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.06em] text-[#555555]">Created</p>
                            <p class="text-sm text-[#333333]">{{ \Carbon\Carbon::parse($editingJob->created_at)->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</p>
                        </div>
                        @if($editingJob->updated_by)
                        <div>
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.06em] text-[#555555]">Last Updated By</p>
                            <p class="text-sm text-[#333333]">{{ $editingJob->updated_by }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.06em] text-[#555555]">Deadline</p>
                            <p class="text-sm text-[#333333]">{{ \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                        </div>
                        @if($editingJob->organizer)
                        <div>
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.06em] text-[#555555]">Posted By</p>
                            <p class="text-sm text-[#333333]">{{ $editingJob->organizer->name }}</p>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-[0.875rem] overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Edit Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-1.5">
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Changes are saved immediately — no approval required.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>If inactive with a past deadline, update deadline then save — it auto-activates.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Editing does not change the Active/Inactive status otherwise.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>After saving, use <strong>Share</strong> to notify alumni and coordinators.</span></li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="saveEditJob"
                        wire:loading.attr="disabled" wire:target="saveEditJob"
                        class="w-full px-4 py-2.5 rounded-xl font-semibold text-white text-sm transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk text-xs"></i></span>
                    <span wire:loading.remove wire:target="saveEditJob">Save Changes</span>
                    <span wire:loading wire:target="saveEditJob">Saving…</span>
                </button>
                <button type="button" wire:click="closeEditModal"
                        class="w-full px-4 py-2 rounded-xl font-semibold text-sm bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333]">
                    <i class="fas fa-xmark mr-1 text-[10px]"></i>Cancel
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
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" style="animation:modalIn .2s cubic-bezier(.25,.8,.25,1) both;">
        <style>@keyframes modalIn{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}</style>

        @if($confirmAction === 'ACTIVE')
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3 bg-[#059669]">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-play text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Activate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will make it visible to alumni</p>
            </div>
        </div>
        @else
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3 bg-[#d97706]">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-pause text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Deactivate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will hide it from alumni</p>
            </div>
        </div>
        @endif

        <div class="p-6 bg-white">
            <p class="text-sm mb-4 text-[#555555]">
                This job will be marked as <strong>{{ $confirmAction }}</strong>.
                @if($confirmAction === 'INACTIVE') It will be hidden from alumni but can still be edited.@endif
            </p>

            @if($confirmAction === 'ACTIVE')
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-emerald-800">Alumni will be able to see and apply to this job posting once activated.</span>
            </div>
            @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-amber-900">Alumni won't see this job posting until you re-activate it. No data will be lost.</span>
            </div>
            @endif

            <div class="flex gap-3">
                <button wire:click="cancelConfirm"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white text-[#333333]">
                    Cancel
                </button>
                @if($confirmAction === 'ACTIVE')
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60 bg-[#059669] hover:bg-[#047857]">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle"><i class="fas fa-circle-play mr-1"></i> Yes, Activate</span>
                </button>
                @else
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60 bg-[#d97706] hover:bg-[#b45309]">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle"><i class="fas fa-circle-pause mr-1"></i> Yes, Deactivate</span>
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
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" style="animation:modalIn .2s cubic-bezier(.25,.8,.25,1) both;">
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3 bg-[#ea580c]">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-rotate-left text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Restore Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">Bring this posting back</p>
            </div>
        </div>
        <div class="p-6">
            <p class="text-sm mb-1 text-[#555555]">You are about to restore:</p>
            <p class="font-semibold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-blue-500 mt-0.5 flex-shrink-0"></i>
                <span class="text-blue-800">The job will be restored. If its deadline has passed it will be set to <strong>Inactive</strong> — update the deadline to re-activate it.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white text-[#333333]">
                    Cancel
                </button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60 bg-[#ea580c] hover:bg-[#c2410c]">
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
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" style="animation:modalIn .2s cubic-bezier(.25,.8,.25,1) both;">
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
                <p class="font-semibold text-sm text-[#333333]">Are you sure you want to permanently delete</p>
                <p class="font-semibold text-lg mt-1 text-red-700">"{{ $deleteJobTitle }}"?</p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3.5 mb-5 space-y-1.5">
                <p class="text-sm font-semibold text-red-800 flex items-center gap-2"><i class="fas fa-circle-exclamation text-red-500"></i> This action cannot be undone.</p>
                <p class="text-sm text-red-700 pl-5">The job posting will be <strong>permanently removed</strong>.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition flex items-center justify-center gap-2 cursor-pointer bg-white text-[#333333]">
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
     SHARE JOB — SLIDE-OVER
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

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5 text-[#333333]">
                <i class="fas fa-share-nodes text-sky-600 text-sm"></i>
                <span>Share Job Posting</span>
            </h2>
            <div class="relative group">
                <button @click="close()" type="button"
                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]">
                    <i class="fas fa-xmark text-base"></i>
                </button>
                <div class="absolute top-full mt-1.5 left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close<span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </div>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#333333]">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4 flex items-start gap-4 bg-[#f9f7fc]">
                        <div class="w-16 h-16 rounded-xl flex items-center justify-center flex-shrink-0 shadow"
                             style="background: linear-gradient(135deg,#7a3f91,#5e2f72);">
                            <i class="fas fa-briefcase text-white text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-base leading-tight text-[#333333]">{{ $shareJobTitle }}</p>
                            <p class="text-sm mt-1 font-semibold text-[#555555]">{{ $shareJobCompany }}@if($shareJobLocation) · {{ $shareJobLocation }}@endif</p>
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-[#f5eef9] text-[#7a3f91]">
                                    {{ $shareJobEmpType }}
                                </span>
                                @if($shareJobTarget)
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-[#333333]">
                                    {{ Str::limit($sjTargets, 30) }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if($sjDescPrev)
                    <div class="px-5 py-3.5 border-b border-gray-100">
                        <p class="text-sm leading-relaxed text-[#555555]">{{ $sjDescPrev }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs text-[#999999]"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold text-[#666666]">{{ strtoupper($sjHost) }}</span>
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
                        <p class="text-sm font-semibold text-[#5e2f72]">Post to Staff Channel</p>
                        <p class="text-sm mt-0.5 text-[#7a3f91]">
                            Posts the job directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Share via</p>

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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or post to staff</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postJobToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postJobToBatchChat"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed text-[#5e2f72] bg-[#f5eef9]">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-[#7a3f91]">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postJobToBatchChat" class="block font-semibold text-sm">Post to Staff Chat</span>
                        <span wire:loading wire:target="postJobToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5 text-[#7a3f91]">Directors &amp; Coordinators · caption included</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-[#7a3f91]"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white text-[#333333]">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate text-[#999999]">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#666666]">
                    <i class="fas fa-xmark mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    var tip = document.getElementById('eo-hover-tip');

    function bindRows() {
        document.querySelectorAll('[data-eo-row]').forEach(function (row) {
            if (row._eoTipBound) return;
            row._eoTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var actionWrap = e.target.closest('[data-eo-action]');
                if (actionWrap) { tip.style.opacity = '0'; return; }
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.style.opacity = '1';
            });

            row.addEventListener('mouseleave', function () {
                if (tip) tip.style.opacity = '0';
            });

            row.addEventListener('click', function () {
                if (tip) tip.style.opacity = '0';
            });
        });

        document.querySelectorAll('[data-eo-action]').forEach(function (aw) {
            if (aw._eoActionBound) return;
            aw._eoActionBound = true;
            aw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);
})();
</script>

</div>