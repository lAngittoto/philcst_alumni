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
    public string $filterSort     = 'recent';
    public string $filterPostedBy = '';

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

    public function updatingSearch()         { $this->resetPage(); }
    public function updatingFilterStatus()   { $this->resetPage(); }
    public function updatingFilterType()     { $this->resetPage(); }
    public function updatingFilterSort()     { $this->resetPage(); }
    public function updatingFilterPostedBy() { $this->resetPage(); }

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
    public function stats(): array
    {
        return [
            'total'    => JobPosting::whereIn('status', ['ACTIVE', 'INACTIVE'])->count(),
            'active'   => JobPosting::where('status', 'ACTIVE')->count(),
            'inactive' => JobPosting::where('status', 'INACTIVE')->count(),
            'expiring' => JobPosting::where('status', 'ACTIVE')
                            ->whereBetween('deadline', [
                                now('Asia/Manila')->toDateString(),
                                now('Asia/Manila')->addDays(7)->toDateString(),
                            ])
                            ->count(),
        ];
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

        if ($this->filterPostedBy === 'admin') {
            $q->whereNull('organizer_id');
        } elseif ($this->filterPostedBy === 'coordinator') {
            $q->whereNotNull('organizer_id');
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
            $job->_daysLeft         = (int) $now->diffInDays($deadline->copy(), false);
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
        $this->search = $this->filterStatus = $this->filterType = $this->filterPostedBy = '';
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

{{-- ══════════════════════════════════════════════════════════════════════
     MAIN WRAPPER  —  matches Events Monitoring layout
══════════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col" style="height: calc(100vh - 70px); overflow: hidden;">

<style>
:root {
    --brand:        #7a3f91;
    --brand-dark:   #5e2f72;
    --brand-light:  #f9f7fc;
    --brand-mid:    #f5eef9;
    --brand-border: #d4aaeb;
    --text-primary:   #333333;
    --text-secondary: #666666;
    --text-muted:     #999999;
}

@keyframes admModalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
@keyframes admSlideIn {
    from { opacity:0; }
    to   { opacity:1; }
}
.adm-m-in  { animation: admModalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.adm-fs-in { animation: admSlideIn .22s cubic-bezier(.4,0,.2,1) both; }

/* ── Scrollbar ── */
.adm-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.adm-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Filter inputs ── */
.adm-filter {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #333333;
    background: #ffffff;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
}
.adm-filter:hover { border-color: #c4b5d4; }
.adm-filter:focus { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.adm-filter {
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

/* ── Table block ── */
.adm-table-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.adm-table-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.adm-table-pagination {
    flex-shrink: 0;
    background: #7a3f91;
    padding: 0.6rem 1rem;
}

/* ── Table rows ── */
.adm-tbl-row { background-color: #ffffff; }
.adm-tbl-row:hover { background-color: #FAFAFA !important; }

/* ── Stat cards ── */
.adm-stat-card {
    border-radius: 1rem;
    border: 1.5px solid #e8e0f0;
    background: #ffffff;
    padding: 0.875rem 1.125rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: box-shadow .15s, border-color .15s;
    cursor: pointer;
}
.adm-stat-card:hover { box-shadow: 0 4px 16px rgba(122,63,145,.10); border-color: #d4aaeb; }
.adm-stat-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.625rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ── Urgent pulse ── */
@keyframes urgentPulse {
    0%,100% { opacity:1; }
    50%      { opacity:.6; }
}
.urgent-pulse { animation: urgentPulse 1.6s ease-in-out infinite; }

[x-cloak] { display:none !important; }
</style>

{{-- ══ FLASH TOAST ══════════════════════════════════════════════════════ --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{'border-emerald-300':type==='success','border-blue-300':type==='info','border-amber-300':type==='warning','border-red-300':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm"
           :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" style="color:#333333;"
           x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" style="color:#333333;" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0 text-gray-600">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ── PAGE HEADER ──────────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Job Postings</h1>
                <p class="text-xs mt-0.5" style="color:#555555;">Monitor, moderate &amp; manage all job listings.</p>
            </div>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-center">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide">
                <i class="fas fa-shield-halved text-purple-600 text-[10px]"></i>
                Admin Control Panel
            </span>
            <button wire:click="openPostModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white shadow-md transition hover:shadow-lg hover:opacity-90 cursor-pointer"
                    style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-plus text-xs"></i>
                <span class="hidden sm:inline">Post a Job</span>
            </button>
        </div>
    </div>

    {{-- ── STAT CARDS ────────────────────────────────────────────────── --}}
    @php $s = $this->stats; @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 flex-shrink-0">

        {{-- Total (ACTIVE + INACTIVE only) --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','')">
            <div class="adm-stat-icon" style="background:#f5eef9;">
                <i class="fas fa-briefcase text-sm" style="color:#7a3f91;"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Total</p>
                <p class="text-xl font-semibold leading-tight" style="color:#333333;">{{ $s['total'] }}</p>
            </div>
        </div>

        {{-- Active --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','ACTIVE')">
            <div class="adm-stat-icon bg-emerald-50">
                <i class="fas fa-circle-check text-sm text-emerald-600"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Active</p>
                <p class="text-xl font-semibold leading-tight text-emerald-600">{{ $s['active'] }}</p>
            </div>
        </div>

        {{-- Inactive --}}
        <div class="adm-stat-card" wire:click="$set('filterStatus','INACTIVE')">
            <div class="adm-stat-icon bg-amber-50">
                <i class="fas fa-ban text-sm text-amber-600"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Inactive</p>
                <p class="text-xl font-semibold leading-tight text-amber-600">{{ $s['inactive'] }}</p>
            </div>
        </div>

        {{-- Expiring Soon --}}
        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-orange-50">
                <i class="fas fa-fire text-sm text-orange-500"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Expiring Soon</p>
                <p class="text-xl font-semibold leading-tight text-orange-500 {{ $s['expiring'] > 0 ? 'urgent-pulse' : '' }}">
                    {{ $s['expiring'] }}
                </p>
            </div>
            @if($s['expiring'] > 0)
                <span class="ml-auto flex-shrink-0 w-2.5 h-2.5 rounded-full bg-orange-400 animate-pulse"></span>
            @endif
        </div>
    </div>

    {{-- ══ TABLE BLOCK ══════════════════════════════════════════════════ --}}
    <div class="flex-1 min-h-0 flex flex-col adm-table-block">

        {{-- ── Filter Bar ──────────────────────────────────────────── --}}
        <div class="adm-table-filter">

            {{-- Filters Pill --}}
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-sliders text-white text-sm"></i>
                <span class="hidden sm:inline">Filters</span>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#7a3f91;"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="adm-filter w-full"
                       style="padding-left:2.25rem; padding-right:1rem;"
                       autocomplete="off" maxlength="100">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus" class="adm-filter" style="min-width:145px;">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="ORGANIZER_DELETED">Deleted by Org.</option>
            </select>

            {{-- Posted by --}}
            <select wire:model.live="filterPostedBy" class="adm-filter hidden sm:block" style="min-width:145px;">
                <option value="">All Sources</option>
                <option value="admin">Admin / Director</option>
                <option value="coordinator">Coordinator</option>
            </select>

            {{-- Employment Type --}}
            <select wire:model.live="filterType" class="adm-filter hidden md:block" style="min-width:160px;">
                <option value="">All Emp. Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            {{-- Sort --}}
            <select wire:model.live="filterSort" class="adm-filter hidden sm:block" style="min-width:130px;">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters"><i class="fas fa-rotate-left text-sm"></i></span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile row 2 --}}
            <select wire:model.live="filterPostedBy" class="adm-filter flex-1 sm:hidden">
                <option value="">All Sources</option>
                <option value="admin">Admin / Director</option>
                <option value="coordinator">Coordinator</option>
            </select>
            <select wire:model.live="filterType" class="adm-filter flex-1 sm:hidden">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort" class="adm-filter flex-1 sm:hidden">
                <option value="recent">Recent</option>
                <option value="oldest">Oldest</option>
            </select>
        </div>

        {{-- ── Table Wrapper ───────────────────────────────────────── --}}
        <div class="relative flex-1 min-h-0 flex flex-col">

            {{-- Loading Overlay --}}
            <div wire:loading
                 wire:target="search,filterStatus,filterType,filterSort,filterPostedBy,resetFilters,previousPage,nextPage,executeToggle,executeRestore"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold" style="color:#7a3f91;">Loading…</span>
                </div>
            </div>

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto adm-scroll"
                 style="background:#fff; scrollbar-width:thin;">
                <table class="w-full min-w-[700px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Job / Company</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Posted By</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Type</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest hidden xl:table-cell" style="color:#555555;">Deadline</th>
                            <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Status</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $isOrgDel         = $job->status === 'ORGANIZER_DELETED';
                            $isActive         = $job->status === 'ACTIVE';
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $daysLeft         = $job->_daysLeft ?? 0;
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $isUrgent         = !$isDeadlinePassed && $daysLeft <= 7;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                        @endphp
                        <tr class="adm-tbl-row transition-colors duration-100">

                            {{-- # --}}
                            <td class="px-4 py-3.5 text-xs font-semibold text-center text-purple-400">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            {{-- Job / Company --}}
                            <td class="px-4 py-3.5">
                                <div class="max-w-[230px]">
                                    <p class="font-semibold text-sm leading-snug truncate" style="color:#333333;">{{ $job->job_title }}</p>
                                    <p class="text-xs mt-0.5 truncate" style="color:#666666;">{{ $job->company_name }}</p>
                                    <p class="text-xs mt-0.5" style="color:#bbbbbb;">{{ $job->created_at->diffForHumans() }}</p>
                                </div>
                            </td>

                            {{-- Posted By --}}
                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                @if($organizerName)
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5" style="color:#7a3f91;">{{ $organizerCollege }}</p>
                                    @endif
                                    <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 bg-blue-50 text-blue-600 border border-blue-100 rounded text-[10px] font-semibold">
                                        Coordinator
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-50 text-purple-600 border border-purple-100 rounded-lg text-[11px] font-semibold">
                                        <i class="fas fa-shield-halved" style="font-size:9px;"></i> Director/Admin
                                    </span>
                                @endif
                            </td>

                            {{-- Employment Type --}}
                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            {{-- Deadline --}}
                            <td class="px-4 py-3.5 hidden xl:table-cell whitespace-nowrap">
                                @if($isDeadlinePassed && !$isOrgDel)
                                    <p class="text-xs font-bold text-red-500">Closed</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $dl->format('M d, Y') }}</p>
                                @elseif($isUrgent && !$isOrgDel)
                                    <p class="text-xs font-bold text-orange-500 urgent-pulse">
                                        {{ $daysLeft === 0 ? 'Today!' : $daysLeft.' day'.($daysLeft !== 1 ? 's' : '').' left' }}
                                    </p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $dl->format('M d, Y') }}</p>
                                @else
                                    <p class="text-xs font-semibold" style="color:#333333;">{{ $dl->format('M d, Y') }}</p>
                                    @if(!$isOrgDel)
                                        <p class="text-xs mt-0.5" style="color:#999999;">{{ $dl->diffForHumans() }}</p>
                                    @endif
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-3.5 text-center">
                                @if($isOrgDel)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-trash text-[9px]"></i> Org. Deleted
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px]"></i> Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-xl text-xs font-semibold whitespace-nowrap">
                                        <i class="fas fa-ban text-[9px]"></i> Inactive
                                    </span>
                                @endif
                            </td>

                            {{-- Actions: View + Share only ──────────────── --}}
                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">

                                    {{-- View --}}
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share (Active only) --}}
                                    @if($isActive)
                                        <button wire:click="openShareJobModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
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
                        @if($search || $filterStatus || $filterType || $filterPostedBy)
                            No job postings match your filters
                        @else
                            No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1" style="color:#555555;">
                        @if($search || $filterStatus || $filterType || $filterPostedBy)
                            Try clearing your filters to see all listings.
                        @else
                            No postings yet. Click <strong>Post a Job</strong> to create one.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterType || $filterPostedBy)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition cursor-pointer"
                            style="background-color:#7a3f91;">
                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                    </button>
                @endif
            </div>
            @endif

        </div>{{-- /relative wrapper --}}

        {{-- ── Pagination ────────────────────────────────────────────── --}}
        @php
            $total = $this->jobPostings->total();
            $pp    = $this->jobPostings->perPage();
            $cp    = $this->jobPostings->currentPage();
            $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to    = min($cp * $pp, $total);
        @endphp
        <div class="adm-table-pagination flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
                Showing
                <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
                of
                <span class="font-semibold text-white">{{ $total }}</span>
                job{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterType || $search || $filterPostedBy)
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

    </div>{{-- /adm-table-block --}}

</div>{{-- /main layout --}}


{{-- ════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN OVERLAY
════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50/95 backdrop-blur-sm"
     @keydown.escape.window="$wire.closePostModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow-lg border-b border-purple-900/30"
         style="background:linear-gradient(135deg,#7a3f91,#4a1a6b);">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-bold text-lg leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs">Fill in all required fields</p>
            </div>
        </div>
        <button wire:click="closePostModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto adm-scroll" style="scrollbar-width:thin;">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            @if(count($postErrors))
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200">
                <p class="font-semibold text-sm text-red-800 mb-2 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-500"></i> Please fix the following:
                </p>
                <ul class="text-sm space-y-1 text-red-700">
                    @foreach($postErrors as $err)
                        <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0 text-red-400">•</span>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Job Title --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-briefcase" style="color:#7a3f91;"></i> Job Title
                    </h3>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Job Title <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white transition focus:outline-none {{ isset($postErrors['postJobTitle'])?'border-red-400 bg-red-50':'' }}"
                           style="color:#333333;">
                    @if(isset($postErrors['postJobTitle']))<p class="mt-1.5 text-xs text-red-600 flex items-center gap-1"><i class="fas fa-circle-exclamation"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                </div>
            </div>

            {{-- Organization --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-building" style="color:#7a3f91;"></i> Organization Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            @foreach([['philcst','fa-school','PHILCST'],['partner','fa-handshake','Partner'],['custom','fa-pen-to-square','Custom']] as [$val,$icon,$label])
                            <button type="button" wire:click="$set('postOrgCategory','{{ $val }}')"
                                    class="flex-1 py-3 px-2 border-2 rounded-xl text-xs font-bold transition flex flex-col items-center gap-1.5 cursor-pointer
                                           {{ $postOrgCategory===$val ? 'border-[#7a3f91] bg-[#7a3f91] text-white shadow-lg' : 'border-gray-200 hover:border-[#7a3f91]/40 hover:bg-purple-50 text-gray-500' }}">
                                <i class="fas {{ $icon }} text-base"></i><span>{{ $label }}</span>
                            </button>
                            @endforeach
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="mt-1.5 text-xs text-red-600 flex items-center gap-1"><i class="fas fa-circle-exclamation"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>

                    @if($postOrgCategory === 'philcst')
                        @if($philcstName)
                        <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-4 py-3">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 shadow" style="background:#7a3f91;"><i class="fas fa-school text-white text-sm"></i></div>
                            <div class="flex-1">
                                <div class="text-sm font-bold text-purple-800">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-xs mt-0.5 text-purple-600"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="text-[10px] font-bold text-purple-600 bg-white border border-purple-200 px-2 py-0.5 rounded-full"><i class="fas fa-lock mr-1 text-[8px]"></i>Auto-filled</span>
                        </div>
                        @endif
                    @elseif($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),syncName(v){$wire.set('postPartnerName',v,false)},syncType(v){$wire.set('postPartnerType',v,false)}}">
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Org. Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postPartnerName'])?'border-red-400 bg-red-50':'' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postPartnerName']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Org. Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text" placeholder="e.g. Private Co." maxlength="100"
                                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postPartnerType'])?'border-red-400 bg-red-50':'' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postPartnerType']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postLocation']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                    @elseif($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),syncName(v){$wire.set('postCustomName',v,false)},syncType(v){$wire.set('postCustomType',v,false)}}">
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Org. Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postCustomName'])?'border-red-400 bg-red-50':'' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postCustomName']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Org. Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text" placeholder="e.g. Government" maxlength="100"
                                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postCustomType'])?'border-red-400 bg-red-50':'' }}"
                                           style="color:#333333;">
                                    @if(isset($postErrors['postCustomType']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postLocation']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                    @else
                        <div class="text-center py-6 text-gray-400">
                            <i class="fas fa-arrow-up text-2xl text-gray-200 mb-2 block"></i>
                            <p class="text-sm">Select a category above to continue.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Job Details --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-circle-info" style="color:#7a3f91;"></i> Job Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <select wire:model.defer="postEmpType"
                                    class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postEmpType'])?'border-red-400 bg-red-50':'' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right .6rem center;background-repeat:no-repeat;background-size:1.25em;padding-right:2.25rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;color:#333333;">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postEmpType']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postEmpType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                            <select wire:model.defer="postExpLevel"
                                    class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($postErrors['postExpLevel'])?'border-red-400 bg-red-50':'' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right .6rem center;background-repeat:no-repeat;background-size:1.25em;padding-right:2.25rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;color:#333333;">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}">{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postExpLevel']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postExpLevel'] }}</p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Salary <span class="font-normal normal-case text-gray-400">(optional)</span></label>
                            <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 – ₱35,000 / mo" maxlength="100"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition"
                                   style="color:#333333;">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Application Deadline <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition {{ isset($postErrors['postDeadline'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333;">
                            @if(isset($postErrors['postDeadline']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postDeadline'] }}</p>@endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Target Colleges --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-building-columns" style="color:#7a3f91;"></i> Target Colleges <span class="text-red-500">*</span>
                    </h3>
                </div>
                <div class="p-6 space-y-3">
                    <label class="flex items-center gap-2 px-3 py-2.5 border-2 border-[#7a3f91] bg-purple-50 rounded-xl cursor-pointer">
                        <input type="checkbox" wire:model.live="postAllColleges" class="w-4 h-4 accent-[#7a3f91]">
                        <span class="text-sm font-bold text-purple-700">All Colleges</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach($this->collegesWithDepts as $college)
                            <label class="flex items-center gap-2 px-3 py-2.5 border rounded-xl cursor-pointer transition text-xs font-semibold {{ in_array($college['name'], $postTargetColleges) ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-200 hover:border-purple-200 hover:bg-purple-50/40 text-gray-600' }}">
                                <input type="checkbox" wire:model.live="postTargetColleges" value="{{ $college['name'] }}" class="w-3.5 h-3.5 accent-[#7a3f91]">
                                <span class="truncate">{{ $college['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if(isset($postErrors['postTargetColleges']))<p class="text-xs text-red-600 flex items-center gap-1 mt-1"><i class="fas fa-circle-exclamation"></i>{{ $postErrors['postTargetColleges'] }}</p>@endif
                </div>
            </div>

            {{-- Description --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-file-lines" style="color:#7a3f91;"></i> Job Description
                    </h3>
                </div>
                <div class="p-6 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Description <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="postDescription" rows="7" maxlength="5000"
                                  placeholder="Describe the role, responsibilities, and what the candidate will be doing…"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($postErrors['postDescription'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($postErrors['postDescription']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postDescription'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Qualifications <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="postQualifications" rows="5" maxlength="3000"
                                  placeholder="e.g. Bachelor's degree in a relevant field, at least 1 year experience…"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($postErrors['postQualifications'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($postErrors['postQualifications']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postQualifications'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">How to Apply <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="postApplicationInstructions" rows="5" maxlength="3000"
                                  placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($postErrors['postApplicationInstructions'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($postErrors['postApplicationInstructions']))<p class="mt-1 text-xs text-red-600">{{ $postErrors['postApplicationInstructions'] }}</p>@endif
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 pb-2">
                <button type="button" wire:click="closePostModal"
                        class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer"
                        style="color:#333333;">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                    <span wire:loading wire:target="savePost"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                    <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane mr-1"></i>Post Job</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════
     VIEW JOB — FULL SCREEN SLIDE-IN  (matches events view style)
════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $vj          = $this->viewingJob;
    $isOrgDel    = $vj->status === 'ORGANIZER_DELETED';
    $isActive    = $vj->status === 'ACTIVE';
    $isInactive  = $vj->status === 'INACTIVE';
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
    $isAdminPost = is_null($vj->organizer_id);
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden adm-fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $vj->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
            @if($isActive)
                <button wire:click="openShareJobModal({{ $vj->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i><span class="hidden sm:inline">Share</span>
                </button>
            @endif
            <button wire:click="openEditModal({{ $vj->id }})" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                <i class="fas fa-pen text-xs"></i><span class="hidden sm:inline">Edit</span>
            </button>
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- Body --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- ── Left Panel: Key Info ── --}}
        <div class="w-full lg:w-[360px] flex flex-col shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto adm-scroll"
             style="scrollbar-width:thin;">

            {{-- Hero banner --}}
            <div class="relative mx-4 mt-4 mb-2 shrink-0 rounded-xl overflow-hidden flex items-center justify-center"
                 style="height:90px; background:linear-gradient(135deg,#7a3f91,#4a1f6a);">
                <i class="fas fa-briefcase text-white/20 text-4xl"></i>
                <div class="absolute top-2 right-2">
                    @if($isOrgDel)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-700/90 text-white text-xs font-semibold">Org. Deleted</span>
                    @elseif($isActive)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-xs font-semibold">Active</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-600/90 text-white text-xs font-semibold">Inactive</span>
                    @endif
                </div>
            </div>

            {{-- Meta Cards --}}
            <div class="flex flex-col gap-2.5 px-4 pb-4">

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-violet-100"><i class="fas fa-building text-violet-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Organization</p>
                        <p class="text-sm font-bold truncate" style="color:#333333;">{{ $vj->company_name }}</p>
                        @if($displayType !== 'PHILCST')<p class="text-xs mt-0.5" style="color:#777777;">{{ $displayType }}</p>@endif
                    </div>
                </div>

                @if($vj->location)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Location</p>
                        <p class="text-sm font-semibold truncate" style="color:#333333;">{{ $vj->location }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-blue-100"><i class="fas fa-clock text-blue-600 text-base"></i></span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Employment</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $vj->employment_type }}</p>
                        <p class="text-xs mt-0.5" style="color:#777777;">{{ $vj->experience_level }}</p>
                    </div>
                </div>

                @if($vj->salary)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-emerald-100"><i class="fas fa-money-bill-wave text-emerald-600 text-base"></i></span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Salary</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $vj->salary }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon {{ $vIsExp ? 'bg-red-100' : 'bg-orange-100' }}">
                        <i class="fas fa-calendar-xmark text-base {{ $vIsExp ? 'text-red-500' : 'text-orange-500' }}"></i>
                    </span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Deadline</p>
                        <p class="text-sm font-semibold {{ $vIsExp ? 'text-red-600' : '' }}" style="{{ $vIsExp ? '' : 'color:#333333;' }}">
                            {{ $vDl->format('F d, Y') }}
                        </p>
                        @if($vIsExp)
                            <p class="text-xs text-red-400 mt-0.5">Deadline passed</p>
                        @else
                            <p class="text-xs mt-0.5" style="color:#777777;">{{ $vDl->diffForHumans() }}</p>
                        @endif
                    </div>
                </div>

                @if($vj->target_college)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-purple-100"><i class="fas fa-building-columns text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Target Colleges</p>
                        <p class="text-sm font-semibold leading-snug" style="color:#333333;">{{ str_replace(',', ', ', $vj->target_college) }}</p>
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-blue-100"><i class="fas fa-{{ $isAdminPost ? 'shield-halved' : 'user-tie' }} text-blue-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">{{ $isAdminPost ? 'Posted By' : 'Coordinator' }}</p>
                        @if($vOrgName)
                            <p class="text-sm font-semibold truncate" style="color:#333333;">{{ $vOrgName }}</p>
                            @if($vOrgCollege)<p class="text-xs mt-0.5" style="color:#7a3f91;">{{ $vOrgCollege }}</p>@endif
                        @else
                            <p class="text-sm font-semibold" style="color:#333333;">{{ $this->myDisplayName }}</p>
                            <p class="text-xs mt-0.5" style="color:#7a3f91;">Alumni Director</p>
                        @endif
                    </div>
                </div>

                {{-- Status badge --}}
                <div class="p-3.5 rounded-xl border
                    {{ $isOrgDel  ? 'bg-red-50 border-red-200' :
                       ($isActive  ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200') }}">
                    @if($isOrgDel)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-red-800">
                            <i class="fas fa-trash text-red-500 text-sm"></i> Deleted by Organizer
                        </p>
                        <p class="text-sm text-red-700 mt-0.5">Deleted by <strong>{{ $vj->deleted_by ?? $vOrgName ?? 'Coordinator' }}</strong> · {{ $vUpdatedPH->format('M d, Y') }}</p>
                    @elseif($isActive)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-emerald-800">
                            <i class="fas fa-circle-check text-emerald-500 text-sm"></i> Active — Now Live
                        </p>
                        <p class="text-sm text-emerald-700 mt-0.5">Visible to alumni and accepting applications.</p>
                    @else
                        <p class="text-sm font-bold flex items-center gap-1.5 text-amber-800">
                            <i class="fas fa-ban text-amber-500 text-sm"></i> Inactive
                        </p>
                        <p class="text-sm text-amber-700 mt-0.5">Hidden from alumni. Activate to make it visible.</p>
                    @endif
                </div>

                {{-- Quick actions (Activate/Deactivate + Restore) --}}
                @if($isOrgDel)
                    <button wire:click="confirmRestore({{ $vj->id }})"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-orange-700 bg-orange-50 border border-orange-200 hover:bg-orange-100 transition cursor-pointer">
                        <i class="fas fa-rotate-left"></i> Restore Job Posting
                    </button>
                @elseif($isActive)
                    <button wire:click="confirmToggle({{ $vj->id }})"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 transition cursor-pointer">
                        <i class="fas fa-ban"></i> Deactivate
                    </button>
                @else
                    <button wire:click="confirmToggle({{ $vj->id }})"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 transition cursor-pointer">
                        <i class="fas fa-circle-check"></i> Activate
                    </button>
                @endif

                <p class="text-xs text-center" style="color:#777777;">
                    Submitted {{ $vCreatedPH->diffForHumans() }} · {{ $vCreatedPH->format('M d, Y g:i A') }}
                </p>
            </div>
        </div>

        {{-- ── Right Panel: Description ── --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            {{-- Audit strip --}}
            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <p class="text-xs font-bold uppercase tracking-widest shrink-0" style="color:#333333;">Posting History</p>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 border border-gray-200 text-xs font-semibold text-gray-600">
                        <i class="fas fa-calendar-plus text-[9px]"></i> Created {{ $vCreatedPH->format('M d, Y') }}
                    </span>
                    @if($vj->updated_by)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-purple-50 border border-purple-200 text-xs font-semibold text-purple-700">
                            <i class="fas fa-pen text-[9px]"></i> {{ $vj->updated_by }} · {{ $vUpdatedPH->format('M d, Y') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Scrollable content --}}
            <div class="flex-1 min-h-0 overflow-y-auto adm-scroll px-5 py-4 flex flex-col gap-4">

                @if($vj->description)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50">
                            <i class="fas fa-file-lines text-blue-500 text-[10px]"></i>
                        </span>
                        Job Description
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($vj->description) }}</div>
                </div>
                @endif

                @if($vj->qualifications)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-amber-50">
                            <i class="fas fa-list-check text-amber-500 text-[10px]"></i>
                        </span>
                        Qualifications
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-amber-50/50 rounded-lg p-4 border border-amber-100" style="line-height:1.75; color:#333333;">{{ trim($vj->qualifications) }}</div>
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
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-emerald-50/60 rounded-lg p-4 border border-emerald-100" style="line-height:1.75; color:#333333;">{{ trim($vj->application_instructions) }}</div>
                </div>
                @endif

                @if(!$vj->description && !$vj->qualifications && !$vj->application_instructions)
                <div class="flex-1 flex items-center justify-center py-10">
                    <div class="text-center">
                        <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-circle-question text-lg text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium" style="color:#555555;">No additional details provided.</p>
                    </div>
                </div>
                @endif

            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════
     EDIT JOB — FULL SCREEN OVERLAY
════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50/95 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeEditModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow-lg border-b border-purple-900/30"
         style="background:linear-gradient(135deg,#5e2f72,#3a1050);">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-bold text-lg leading-tight">Edit Job Posting</h2>
                <p class="text-white/60 text-xs">Update the fields and save changes</p>
            </div>
        </div>
        <button wire:click="closeEditModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto adm-scroll" style="scrollbar-width:thin;">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            @php $editJobDeadlinePassed = $editDeadline && \Carbon\Carbon::createFromFormat('Y-m-d', $editDeadline, 'Asia/Manila')->endOfDay() < now('Asia/Manila'); @endphp
            @if($editJobDeadlinePassed)
            <div class="flex items-center gap-2 p-4 rounded-2xl bg-amber-50 border border-amber-200">
                <i class="fas fa-triangle-exclamation text-amber-500 flex-shrink-0"></i>
                <p class="text-sm text-amber-800 font-semibold">Deadline has already passed. Update it to re-activate this job.</p>
            </div>
            @endif

            @if(count($editErrors))
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200">
                <p class="font-semibold text-sm text-red-800 mb-2 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-500"></i> Please fix the following:
                </p>
                <ul class="text-sm space-y-1 text-red-700">
                    @foreach($editErrors as $err)
                        <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0 text-red-400">•</span>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-briefcase" style="color:#7a3f91;"></i> Job Title
                    </h3>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Job Title <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                           class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition {{ isset($editErrors['editJobTitle'])?'border-red-400 bg-red-50':'' }}"
                           style="color:#333333;">
                    @if(isset($editErrors['editJobTitle']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editJobTitle'] }}</p>@endif
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-building" style="color:#7a3f91;"></i> Organization Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCompanyType"
                                    class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($editErrors['editCompanyType'])?'border-red-400 bg-red-50':'' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right .6rem center;background-repeat:no-repeat;background-size:1.25em;padding-right:2.25rem;-webkit-appearance:none;appearance:none;color:#333333;">
                                <option value="">Select Organization</option>
                                @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editCompanyType']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editCompanyType'] }}</p>@endif
                        </div>
                        <div>
                            @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Company Name <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editCompany" type="text" maxlength="150"
                                   @if($editIsPhilcst) readonly @endif
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition {{ isset($editErrors['editCompany'])?'border-red-400 bg-red-50':'' }} {{ $editIsPhilcst?'cursor-not-allowed bg-gray-50 text-gray-400':'' }}"
                                   style="color:#333333;">
                            @if(isset($editErrors['editCompany']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editCompany'] }}</p>@endif
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                        <input wire:model="editLocation" type="text" maxlength="120"
                               @if($editIsPhilcst) readonly @endif
                               class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition {{ isset($editErrors['editLocation'])?'border-red-400 bg-red-50':'' }} {{ $editIsPhilcst?'cursor-not-allowed bg-gray-50 text-gray-400':'' }}"
                               style="color:#333333;">
                        @if(isset($editErrors['editLocation']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editLocation'] }}</p>@endif
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-circle-info" style="color:#7a3f91;"></i> Job Details
                    </h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editEmpType"
                                    class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($editErrors['editEmpType'])?'border-red-400 bg-red-50':'' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right .6rem center;background-repeat:no-repeat;background-size:1.25em;padding-right:2.25rem;-webkit-appearance:none;appearance:none;color:#333333;">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editEmpType']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editEmpType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Experience Level <span class="text-red-500">*</span></label>
                            <select wire:model.defer="editExpLevel"
                                    class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none {{ isset($editErrors['editExpLevel'])?'border-red-400 bg-red-50':'' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right .6rem center;background-repeat:no-repeat;background-size:1.25em;padding-right:2.25rem;-webkit-appearance:none;appearance:none;color:#333333;">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editExpLevel']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editExpLevel'] }}</p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Salary <span class="font-normal normal-case text-gray-400">(optional)</span></label>
                            <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25,000 – ₱35,000 / month"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition"
                                   style="color:#333333;">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Application Deadline <span class="text-red-500">*</span></label>
                            <input wire:model.defer="editDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none transition {{ isset($editErrors['editDeadline'])?'border-red-400 bg-red-50':'' }}"
                                   style="color:#333333;">
                            @if(isset($editErrors['editDeadline']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editDeadline'] }}</p>@endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-building-columns" style="color:#7a3f91;"></i> Target Colleges <span class="text-red-500">*</span>
                    </h3>
                </div>
                <div class="p-6 space-y-3">
                    <label class="flex items-center gap-2 px-3 py-2.5 border-2 border-[#7a3f91] bg-purple-50 rounded-xl cursor-pointer">
                        <input type="checkbox" wire:model.live="editAllColleges" class="w-4 h-4 accent-[#7a3f91]">
                        <span class="text-sm font-bold text-purple-700">All Colleges</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        @foreach($this->collegesWithDepts as $college)
                            <label class="flex items-center gap-2 px-3 py-2.5 border rounded-xl cursor-pointer transition text-xs font-semibold {{ in_array($college['name'], $editTargetColleges) ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-200 hover:border-purple-200 text-gray-600' }}">
                                <input type="checkbox" wire:model.live="editTargetColleges" value="{{ $college['name'] }}" class="w-3.5 h-3.5 accent-[#7a3f91]">
                                <span class="truncate">{{ $college['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if(isset($editErrors['editTargetColleges']))<p class="text-xs text-red-600 flex items-center gap-1 mt-1"><i class="fas fa-circle-exclamation"></i>{{ $editErrors['editTargetColleges'] }}</p>@endif
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-file-lines" style="color:#7a3f91;"></i> Job Description
                    </h3>
                </div>
                <div class="p-6 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Description <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="editDescription" rows="7" maxlength="5000"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($editErrors['editDescription'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Qualifications <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="editQualifications" rows="5" maxlength="3000"
                                  placeholder="e.g. Bachelor's degree in a relevant field…"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($editErrors['editQualifications'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">How to Apply <span class="text-red-500">*</span></label>
                        <textarea wire:model.defer="editApplicationInstructions" rows="5" maxlength="3000"
                                  placeholder="e.g. Send your resume to hr@company.com…"
                                  class="adm-filter w-full px-4 py-3 rounded-xl text-sm bg-white focus:outline-none resize-none transition {{ isset($editErrors['editApplicationInstructions'])?'border-red-400 bg-red-50':'' }}"
                                  style="color:#333333;"></textarea>
                        @if(isset($editErrors['editApplicationInstructions']))<p class="mt-1 text-xs text-red-600">{{ $editErrors['editApplicationInstructions'] }}</p>@endif
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 pb-2">
                <button type="button" wire:click="closeEditModal"
                        class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer"
                        style="color:#333333;">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="saveEditJob"
                        wire:loading.attr="disabled" wire:target="saveEditJob"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background:linear-gradient(135deg,#5e2f72,#3a1050);">
                    <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                    <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk mr-1"></i>Save Changes</span>
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
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden adm-m-in">
        <div class="px-6 py-5 border-b {{ $confirmAction==='ACTIVE' ? 'bg-emerald-50 border-emerald-100' : 'bg-amber-50 border-amber-100' }}">
            <h2 class="text-lg font-semibold flex items-center gap-2.5 {{ $confirmAction==='ACTIVE' ? 'text-emerald-800' : 'text-amber-800' }}">
                <div class="w-9 h-9 {{ $confirmAction==='ACTIVE' ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-xl flex items-center justify-center">
                    <i class="fas fa-{{ $confirmAction==='ACTIVE' ? 'circle-check text-emerald-600' : 'ban text-amber-600' }} text-base"></i>
                </div>
                {{ $confirmAction === 'ACTIVE' ? 'Activate Job?' : 'Deactivate Job?' }}
            </h2>
        </div>
        <div class="p-6" style="background:#ffffff;">
            <p class="text-sm mb-5" style="color:#666666;">
                This job will be marked as <strong style="color:#333333;">{{ $confirmAction }}</strong>.
                @if($confirmAction==='INACTIVE') It will be hidden from alumni but can still be edited.@endif
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelConfirm"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer"
                        style="color:#333333; background:#ffffff;">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-3 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow cursor-pointer {{ $confirmAction==='ACTIVE' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600' }}">
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


{{-- ════ MODAL: Restore ════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden adm-m-in">
        <div class="px-6 py-5 bg-orange-50 border-b border-orange-100">
            <h2 class="text-lg font-semibold text-orange-800 flex items-center gap-2.5">
                <div class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-rotate-left text-orange-500 text-base"></i>
                </div>
                Restore Job Posting
            </h2>
        </div>
        <div class="p-6" style="background:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">You are about to restore:</p>
            <p class="font-bold text-orange-700 text-base mb-4">"{{ $restoreJobTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-xs text-blue-800 flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                <span>If the deadline has passed it will be set to <strong>Inactive</strong> — update the deadline to re-activate it.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore"
                        class="flex-1 px-4 py-3 border border-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer"
                        style="color:#333333; background:#ffffff;">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-4 py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow cursor-pointer">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i> Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════ SLIDE-OVER: Share Job ════ --}}
@if($showShareJobModal)
@php
    $sjBaseUrl  = $this->jobsBaseUrl();
    $sjHost     = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $sjTargets  = $shareJobTarget ? str_replace(',', ', ', $shareJobTarget) : 'All Alumni';
    $sjDescPrev = mb_strlen($shareJobDescription) > 160 ? mb_substr($shareJobDescription, 0, 160) . '…' : $shareJobDescription;

    $sjLines = [
        "💼 @everyone — Job Opportunity!",
        "",
        "📌 {$shareJobTitle}",
        "🏢 {$shareJobCompany}" . ($shareJobLocation ? " · {$shareJobLocation}" : ''),
        "⏰ {$shareJobEmpType}" . ($shareJobExpLevel ? " · {$shareJobExpLevel}" : ''),
    ];
    if ($shareJobSalary)  $sjLines[] = "💰 {$shareJobSalary}";
    if ($shareJobTarget)  $sjLines[] = "🎓 For: {$sjTargets}";
    $sjLines[] = "📅 Apply by: {$shareJobDeadline}";
    $sjLines[] = '';
    $sjLines[] = "See full details & apply on the PHILCST Alumni Portal 👇";
    $sjLines[] = $sjBaseUrl;
    $sjPostText = implode("\n", $sjLines);
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied:false,fbCopied:false,messengerCopied:false,
         fbText:  {{ json_encode($sjPostText) }},
         baseUrl: {{ json_encode($sjBaseUrl) }},
         close() { this.open=false; setTimeout(()=>$wire.closeShareJobModal(),290); },
         async copyText(text) {
             try {
                 if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(text);}
                 else{const ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.focus();ta.select();document.execCommand('copy');document.body.removeChild(ta);}
                 return true;
             } catch(e){ return false; }
         },
         async shareOnFacebook() { await this.copyText(this.fbText); this.fbCopied=true; window.open('https://www.facebook.com/','_blank','noopener,noreferrer'); setTimeout(()=>{this.fbCopied=false;},9000); },
         async shareOnMessenger() { await this.copyText(this.fbText); this.messengerCopied=true; const isMobile=/Android|iPhone|iPad|iPod/i.test(navigator.userAgent); if(isMobile){window.location.href='fb-messenger://share/?link='+encodeURIComponent(this.baseUrl);setTimeout(()=>window.open('https://www.messenger.com/','_blank','noopener'),1500);}else{window.open('https://www.messenger.com/','_blank','noopener');} setTimeout(()=>{this.messengerCopied=false;},9000); },
         async copyLinkFn() { await this.copyText(this.baseUrl); this.copied=true; setTimeout(()=>this.copied=false,2500); }
     }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="close()">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="close()"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5" style="color:#333333;">
                <i class="fas fa-share-nodes text-sky-600 text-sm"></i>
                <span>Share Job Posting</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer" style="color:#333333;">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto adm-scroll" style="scrollbar-width:thin;">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4" style="background:#f9f7fc;">
                        <div class="flex items-start gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 shadow" style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                                <i class="fas fa-briefcase text-white text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm" style="color:#333333;">{{ $shareJobTitle }}</p>
                                <p class="text-xs mt-0.5" style="color:#666666;">{{ $shareJobCompany }}@if($shareJobLocation) · {{ $shareJobLocation }}@endif</p>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-semibold bg-purple-50 text-purple-700"><i class="fas fa-clock text-[8px]"></i>{{ $shareJobEmpType }}</span>
                                    @if($shareJobTarget)<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-semibold bg-gray-100 text-gray-600"><i class="fas fa-building-columns text-[8px]"></i>{{ Str::limit($sjTargets, 30) }}</span>@endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @if($sjDescPrev)<div class="px-5 py-3 border-b border-gray-100"><p class="text-xs leading-relaxed" style="color:#666666;">{{ $sjDescPrev }}</p></div>@endif
                    <div class="px-5 py-2 flex items-center gap-2" style="background:#f9f7fc;">
                        <i class="fas fa-globe text-[10px]" style="color:#999999;"></i>
                        <span class="text-[10px] uppercase tracking-wider font-bold" style="color:#666666;">{{ strtoupper($sjHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-400 mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs text-blue-800">Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the caption to your clipboard and opens the platform. Press <kbd class="bg-blue-100 px-1 rounded font-mono">Ctrl+V</kbd> to paste.</p>
                </div>

                <div class="rounded-xl px-4 py-3 flex items-start gap-2.5 flex-shrink-0" style="background:#f5eef9; border:1px solid #d4aaeb;">
                    <i class="fas fa-shield-halved mt-0.5 flex-shrink-0" style="color:#7a3f91;"></i>
                    <p class="text-xs" style="color:#5e2f72;"><strong>Post to Staff Chat</strong> — sends the job announcement directly to the Directors &amp; Coordinators channel. @if($shareJobTarget) Target: <strong>{{ $sjTargets }}</strong>.@endif</p>
                </div>
            </div>

            {{-- Buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto adm-scroll" style="scrollbar-width:thin;">
                <p class="text-xs font-bold uppercase tracking-widest" style="color:#333333;">Share Via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2 text-xs">
                    <i class="fas fa-check text-emerald-600 mt-0.5 flex-shrink-0"></i>
                    <div><p class="font-bold text-emerald-800">Text copied! Facebook is open.</p><p class="text-emerald-700 mt-0.5">Press <strong>Ctrl+V</strong> to paste in the composer.</p></div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2 text-xs">
                    <i class="fas fa-check text-blue-600 mt-0.5 flex-shrink-0"></i>
                    <div><p class="font-bold text-blue-800">Text copied! Messenger is open.</p><p class="text-blue-700 mt-0.5">Open any chat and press <strong>Ctrl+V</strong>.</p></div>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 bg-white rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Post on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens facebook.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/50 text-sm group-hover:text-white transition"></i>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 bg-white rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5"><defs><linearGradient id="mgr_jp" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs><path fill="url(#mgr_jp)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/></svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens messenger.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/50 text-sm group-hover:text-white transition"></i>
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
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform" style="background:#7a3f91;">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postJobToBatchChat" class="block font-semibold text-sm">Post to Staff Chat</span>
                        <span wire:loading wire:target="postJobToBatchChat" class="block font-semibold text-sm"><i class="fas fa-spinner fa-spin mr-1"></i> Posting…</span>
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
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white"
                        style="color:#333333;">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''" x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#999999;">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 cursor-pointer"
                        style="color:#666666;">
                    <i class="fas fa-xmark mr-1.5"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>