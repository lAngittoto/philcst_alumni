{{-- resources/views/livewire/organizer/organizer-job-management.blade.php --}}

<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\AuditLog;
use App\Http\Controllers\JobController;
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

    public bool   $showPostModal                    = false;
    public string $postJobTitle                     = '';
    public string $postOrgCategory                  = '';
    public string $postPartnerName                  = '';
    public string $postPartnerType                  = '';
    public string $postCustomName                   = '';
    public string $postCustomType                   = '';
    public string $postLocation                     = '';
    public string $postEmpType                      = '';
    public string $postExpLevel                     = '';
    public string $postSalary                       = '';
    public string $postDeadline                     = '';
    public string $postDescription                  = '';
    public string $postQualifications               = '';
    public string $postApplicationInstructions      = '';
    public array  $postTargetColleges               = [];
    public array  $postErrors                       = [];

    public string $philcstName     = '';
    public string $philcstLocation = '';

    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    public bool   $showEditModal                    = false;
    public ?int   $editingJobId                     = null;
    public string $editJobTitle                     = '';
    public string $editCompany                      = '';
    public string $editCompanyType                  = '';
    public string $editLocation                     = '';
    public string $editEmpType                      = '';
    public string $editExpLevel                     = '';
    public string $editSalary                       = '';
    public string $editDeadline                     = '';
    public string $editDescription                  = '';
    public string $editQualifications               = '';
    public string $editApplicationInstructions      = '';
    public array  $editTargetColleges               = [];
    public array  $editErrors                       = [];

    public bool   $showToggleModal = false;
    public ?int   $toggleJobId     = null;
    public string $toggleJobTitle  = '';
    public string $toggleAction    = '';

    public bool   $showShareModal   = false;
    public ?int   $shareJobId       = null;
    public string $shareJobTitle    = '';
    public string $shareCompany     = '';
    public string $shareEmpType     = '';
    public string $shareLocation    = '';
    public string $shareExpLevel    = '';
    public string $shareSalary      = '';
    public string $shareDeadline    = '';
    public string $shareDescription = '';
    public string $shareCollege     = '';

    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

    private function guardAuth(): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'));
        }
    }

    private function guardOwnership(JobPosting $job): void
    {
        $org = auth()->user()?->organizer;
        if (! $org || $job->organizer_id !== $org->id) {
            $this->dispatch('flash-message', type: 'error', message: 'Unauthorized action.');
            $this->js('window.location.reload()');
        }
    }

    private function throttleAction(string $key, int $maxAttempts = 10, int $decaySeconds = 60): bool
    {
        $userId = auth()->id() ?? 'guest';
        $ratKey = "{$key}:{$userId}";
        if (RateLimiter::tooManyAttempts($ratKey, $maxAttempts)) {
            $this->dispatch('flash-message', type: 'error', message: 'Too many requests. Please slow down.');
            return false;
        }
        RateLimiter::hit($ratKey, $decaySeconds);
        return true;
    }

    private function sanitize(string $value): string
    {
        return strip_tags(trim($value));
    }

    private function logAudit(
        string  $action,
        string  $subjectLabel,
        string  $description,
        array   $oldValues  = [],
        array   $newValues  = [],
        string  $severity   = 'info'
    ): void {
        try {
            AuditLog::create([
                'action'        => $action,
                'module'        => 'job_posting',
                'user_id'       => auth()->id(),
                'user_name'     => auth()->user()?->name,
                'user_email'    => auth()->user()?->email,
                'user_role'     => 'organizer',
                'subject_label' => $subjectLabel,
                'description'   => $description,
                'old_values'    => $oldValues  ?: null,
                'new_values'    => $newValues  ?: null,
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'severity'      => $severity,
                'is_flagged'    => false,
            ]);
            Cache::forget('audit_stats');
        } catch (\Throwable) {}
    }

    public function mount(): void
    {
        $this->guardAuth();

        $philcst = Cache::remember('philcst_option', 600, fn() =>
            JobOption::where('type', 'company_type')
                ->where('label', 'like', '%PHILCST%')
                ->orderBy('label')
                ->first()
        );

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
        $opt = Cache::remember("company_type_opt_{$value}", 300, fn() =>
            JobOption::where('type', 'company_type')->where('label', $value)->first()
        );
        if ($opt && !empty($opt->default_location)) {
            $this->editLocation = $opt->default_location;
        }
    }

    #[Computed]
    public function organizerCollege(): ?string
    {
        $org = auth()->user()?->organizer;
        if (!$org) return null;
        return \App\Models\Course::where('college', $org->department)->value('college')
            ?? $org->department
            ?? null;
    }

    #[Computed]
    public function organizerName(): string
    {
        return auth()->user()?->organizer?->name ?? auth()->user()?->name ?? '';
    }

    #[Computed]
    public function jobPostings()
    {
        $org = auth()->user()?->organizer;
        if (!$org) return JobPosting::whereRaw('0=1')->paginate(20);

        $orgCollege = $this->organizerCollege;
        $today      = now('Asia/Manila')->startOfDay()->toDateString();

        JobPosting::where(function ($q) use ($org, $orgCollege) {
                $q->where('organizer_id', $org->id)
                  ->orWhere(function ($sub) use ($orgCollege) {
                      $sub->whereNull('organizer_id')
                          ->where(function ($inner) use ($orgCollege) {
                              $inner->whereNull('target_college')
                                    ->orWhere('target_college', $orgCollege);
                          });
                  });
            })
            ->where('status', 'ACTIVE')
            ->where('deadline', '<', $today)
            ->update([
                'status'          => 'INACTIVE',
                'updated_by'      => 'System',
                'updated_by_role' => 'system',
                'updated_at'      => now(),
            ]);

        $q = JobPosting::with('organizer')
            ->select([
                'id','organizer_id','job_title','company_name','company_type',
                'location','employment_type','experience_level',
                'target_college','salary','deadline','status',
                'created_at','updated_at','updated_by','updated_by_role',
                'deleted_by','deleted_by_role',
            ]);

        $q->where(function ($query) use ($org, $orgCollege) {
            $query
                ->where('organizer_id', $org->id)
                ->orWhere(function ($sub) use ($orgCollege) {
                    $sub->whereNull('organizer_id')
                        ->where(function ($inner) use ($orgCollege) {
                            $inner->whereNull('target_college')
                                  ->orWhere('target_college', $orgCollege);
                        });
                });
        });

        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        } else {
            $q->whereIn('status', ['ACTIVE', 'INACTIVE']);
        }

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        if ($this->filterType !== '') {
            $q->where('employment_type', $this->filterType);
        }

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');

        $paginated = $q->paginate(15);

        $nowDate = now('Asia/Manila')->startOfDay();
        $paginated->getCollection()->transform(function ($job) use ($nowDate) {
            $job->_isDeadlinePassed = \Carbon\Carbon::parse($job->deadline)
                ->setTimezone('Asia/Manila')->startOfDay()->lt($nowDate);
            $job->syncOriginal();
            return $job;
        });

        return $paginated;
    }

    #[Computed]
    public function jobOptions()
    {
        return Cache::remember('job_options_grouped', 600, fn() =>
            JobOption::orderBy('type')->orderBy('label')->get()->groupBy('type')
        );
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
        return Cache::remember('colleges_with_depts', 600, fn() =>
            app(\App\Http\Controllers\OrganizerJobController::class)->getCollegesWithDepts()
        );
    }

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    public function openPostModal(): void
    {
        $this->guardAuth();
        $this->resetPostFields();
        $this->postDeadline       = now()->setTimezone('Asia/Manila')->addMonth()->format('Y-m-d');
        $this->postTargetColleges = !empty($this->organizerCollege) ? [$this->organizerCollege] : [];
        $this->showPostModal      = true;
    }

    public function closePostModal(): void
    {
        $this->showPostModal = false;
        $this->resetPostFields();
    }

    public function savePost(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('save_post', 5, 60)) return;

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
        } else {
            $deadline = \Carbon\Carbon::createFromFormat('Y-m-d', $this->postDeadline, 'Asia/Manila')->endOfDay();
            if ($deadline->lt(now('Asia/Manila'))) {
                $errors['postDeadline'] = 'Deadline must be today or in the future.';
            }
        }

        if (!trim($this->postDescription))             $errors['postDescription']             = 'Job description is required.';
        if (!trim($this->postQualifications))          $errors['postQualifications']          = 'Qualifications are required.';
        if (!trim($this->postApplicationInstructions)) $errors['postApplicationInstructions'] = 'Application instructions are required.';

        if (empty($this->postTargetColleges)) {
            $errors['postTargetColleges'] = 'Your college has been auto-selected.';
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

        [$companyName, $companyType] = match($this->postOrgCategory) {
            'philcst' => [$this->philcstName,                      $this->philcstName],
            'partner' => [$this->sanitize($this->postPartnerName), $this->sanitize($this->postPartnerType)],
            'custom'  => [$this->sanitize($this->postCustomName),  $this->sanitize($this->postCustomType)],
            default   => ['', ''],
        };

        $org = auth()->user()?->organizer;

        $duplicate = JobPosting::where('job_title', $this->sanitize($this->postJobTitle))
            ->where('company_name', $companyName)
            ->where('employment_type', $this->sanitize($this->postEmpType))
            ->whereNotIn('status', ['ORGANIZER_DELETED'])
            ->where(fn($q) => $q->where('organizer_id', $org?->id)->orWhereNull('organizer_id'))
            ->exists();

        if ($duplicate) {
            $this->postErrors['postJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

        $job = JobPosting::create([
            'organizer_id'             => $org?->id,
            'job_title'                => $this->sanitize($this->postJobTitle),
            'company_name'             => $companyName,
            'company_type'             => $companyType,
            'location'                 => $this->postOrgCategory === 'philcst' ? $this->philcstLocation : $this->sanitize($this->postLocation),
            'employment_type'          => $this->sanitize($this->postEmpType),
            'experience_level'         => $this->sanitize($this->postExpLevel),
            'salary'                   => $this->sanitize($this->postSalary) ?: null,
            'deadline'                 => $this->postDeadline,
            'description'              => $this->sanitize($this->postDescription),
            'qualifications'           => $this->sanitize($this->postQualifications),
            'application_instructions' => $this->sanitize($this->postApplicationInstructions),
            'target_college'           => implode(',', $this->postTargetColleges) ?: null,
            'status'                   => 'ACTIVE',
            'updated_by'               => auth()->user()->name,
            'updated_by_role'          => 'organizer',
        ]);

        $this->logAudit(
            action:       'created',
            subjectLabel: $job->job_title,
            description:  sprintf(
                'Organizer created job posting "%s" at %s (%s) — %s, deadline %s.',
                $job->job_title, $job->company_name, $job->employment_type,
                $job->experience_level,
                \Carbon\Carbon::parse($job->deadline)->format('M j, Y')
            ),
            newValues: [
                'job_title'                => $job->job_title,
                'company_name'             => $job->company_name,
                'company_type'             => $job->company_type,
                'location'                 => $job->location,
                'employment_type'          => $job->employment_type,
                'experience_level'         => $job->experience_level,
                'salary'                   => $job->salary ?? 'Not disclosed',
                'deadline'                 => $job->deadline,
                'target_college'           => $job->target_college,
                'qualifications'           => $job->qualifications,
                'application_instructions' => $job->application_instructions,
                'status'                   => $job->status,
            ],
            severity: 'info'
        );

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
    }

    public function viewJob(int $id): void  { $this->guardAuth(); $this->viewingJobId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void  { $this->showViewModal = false; $this->viewingJobId = null; }

    public function openEditModal(int $id): void
    {
        $this->guardAuth();
        $job = app(JobController::class)->getJob($id);
        $this->guardOwnership($job);

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
        $this->editTargetColleges          = !empty($job->target_college)
            ? explode(',', $job->target_college)
            : [$this->organizerCollege];
        $this->editErrors     = [];
        $this->showViewModal  = false;
        $this->showEditModal  = true;
    }

    public function closeEditModal(): void { $this->showEditModal = false; $this->resetEditFields(); }

    public function saveEditJob(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('save_edit', 10, 60)) return;

        $this->editErrors = [];
        $errors = [];

        if (!trim($this->editJobTitle))    $errors['editJobTitle']    = 'Job title is required.';
        if (!trim($this->editCompany))     $errors['editCompany']     = 'Organization name is required.';
        if (!trim($this->editCompanyType)) $errors['editCompanyType'] = 'Organization type is required.';
        if (!trim($this->editLocation))    $errors['editLocation']    = 'Location is required.';
        if (!trim($this->editEmpType))     $errors['editEmpType']     = 'Employment type is required.';
        if (!trim($this->editExpLevel))    $errors['editExpLevel']    = 'Experience level is required.';

        if (!trim($this->editDeadline)) {
            $errors['editDeadline'] = 'Deadline is required.';
        } else {
            $deadline = \Carbon\Carbon::createFromFormat('Y-m-d', $this->editDeadline, 'Asia/Manila')->endOfDay();
            if ($deadline->lt(now('Asia/Manila'))) {
                $errors['editDeadline'] = 'Deadline must be today or in the future.';
            }
        }

        if (!trim($this->editDescription))             $errors['editDescription']             = 'Job description is required.';
        if (!trim($this->editQualifications))          $errors['editQualifications']          = 'Qualifications are required.';
        if (!trim($this->editApplicationInstructions)) $errors['editApplicationInstructions'] = 'Application instructions are required.';

        if (empty($this->editTargetColleges)) {
            $errors['editTargetColleges'] = 'Your college has been auto-selected.';
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

        $org = auth()->user()?->organizer;
        $duplicate = JobPosting::where('job_title', $this->sanitize($this->editJobTitle))
            ->where('company_name', $this->sanitize($this->editCompany))
            ->where('employment_type', $this->sanitize($this->editEmpType))
            ->whereNotIn('status', ['ORGANIZER_DELETED'])
            ->where('id', '!=', $this->editingJobId)
            ->where(fn($q) => $q->where('organizer_id', $org?->id)->orWhereNull('organizer_id'))
            ->exists();

        if ($duplicate) {
            $this->editErrors['editJobTitle'] = 'A job posting with this title, organization, and employment type already exists.';
            return;
        }

        $job = app(JobController::class)->getJob($this->editingJobId);
        $this->guardOwnership($job);

        $before = [
            'job_title'                => $job->job_title,
            'company_name'             => $job->company_name,
            'company_type'             => $job->company_type,
            'location'                 => $job->location,
            'employment_type'          => $job->employment_type,
            'experience_level'         => $job->experience_level,
            'salary'                   => $job->salary ?? 'Not disclosed',
            'deadline'                 => $job->deadline,
            'target_college'           => $job->target_college,
            'qualifications'           => $job->qualifications,
            'application_instructions' => $job->application_instructions,
        ];

        $job->update([
            'job_title'                => $this->sanitize($this->editJobTitle),
            'company_name'             => $this->sanitize($this->editCompany),
            'company_type'             => $this->sanitize($this->editCompanyType),
            'location'                 => $this->sanitize($this->editLocation),
            'employment_type'          => $this->sanitize($this->editEmpType),
            'experience_level'         => $this->sanitize($this->editExpLevel),
            'salary'                   => $this->sanitize($this->editSalary) ?: null,
            'deadline'                 => $this->editDeadline,
            'description'              => $this->sanitize($this->editDescription),
            'qualifications'           => $this->sanitize($this->editQualifications),
            'application_instructions' => $this->sanitize($this->editApplicationInstructions),
            'target_college'           => implode(',', $this->editTargetColleges) ?: null,
            'updated_by'               => auth()->user()->name,
            'updated_by_role'          => 'organizer',
        ]);

        $this->logAudit(
            action:       'updated',
            subjectLabel: $this->sanitize($this->editJobTitle),
            description:  sprintf(
                'Organizer updated job posting "%s" (ID #%d). Status preserved as %s.',
                $this->sanitize($this->editJobTitle), $job->id, $job->status
            ),
            oldValues: $before,
            newValues: [
                'job_title'                => $this->sanitize($this->editJobTitle),
                'company_name'             => $this->sanitize($this->editCompany),
                'company_type'             => $this->sanitize($this->editCompanyType),
                'location'                 => $this->sanitize($this->editLocation),
                'employment_type'          => $this->sanitize($this->editEmpType),
                'experience_level'         => $this->sanitize($this->editExpLevel),
                'salary'                   => $this->sanitize($this->editSalary) ?: 'Not disclosed',
                'deadline'                 => $this->editDeadline,
                'target_college'           => implode(',', $this->editTargetColleges) ?: null,
                'qualifications'           => $this->sanitize($this->editQualifications),
                'application_instructions' => $this->sanitize($this->editApplicationInstructions),
            ],
            severity: 'info'
        );

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
        $this->editQualifications = $this->editApplicationInstructions = '';
        $this->editTargetColleges = [];
        $this->editErrors = [];
    }

    public function confirmToggleStatus(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);
        $this->guardOwnership($job);

        $this->toggleJobId    = $id;
        $this->toggleJobTitle = $job->job_title;
        $this->toggleAction   = $job->status === 'ACTIVE' ? 'deactivate' : 'activate';
        $this->showToggleModal = true;
    }

    public function executeToggleStatus(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('toggle_status', 10, 60)) return;

        if ($this->toggleJobId) {
            $job = JobPosting::findOrFail($this->toggleJobId);
            $this->guardOwnership($job);

            $oldStatus = $job->status;

            if ($oldStatus === 'INACTIVE') {
                $deadline = \Carbon\Carbon::parse($job->deadline)
                    ->setTimezone('Asia/Manila')->startOfDay();
                if ($deadline->lt(now('Asia/Manila')->startOfDay())) {
                    $this->dispatch('flash-message', type: 'warning',
                        message: 'Cannot activate — deadline has already passed. Please edit the job and set a future deadline first, then activate it.');
                    $this->cancelToggleStatus();
                    return;
                }
                $newStatus = 'ACTIVE';
                $msg       = 'Job posting activated successfully.';
            } else {
                $newStatus = 'INACTIVE';
                $msg       = 'Job posting deactivated.';
            }

            $job->update([
                'status'          => $newStatus,
                'updated_by'      => auth()->user()->name,
                'updated_by_role' => 'organizer',
            ]);

            $this->logAudit(
                action:       $newStatus === 'ACTIVE' ? 'activated' : 'deactivated',
                subjectLabel: $job->job_title,
                description:  sprintf(
                    'Organizer changed status of "%s" (ID #%d) from %s to %s.',
                    $job->job_title, $job->id, $oldStatus, $newStatus
                ),
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
                severity:  'info'
            );

            $this->dispatch('flash-message', type: 'success', message: $msg);
        }

        $this->cancelToggleStatus();

        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }
    }

    public function cancelToggleStatus(): void
    {
        $this->showToggleModal = false;
        $this->toggleJobId    = null;
        $this->toggleJobTitle = '';
        $this->toggleAction   = '';
    }

    public function openShareModal(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);

        $deadlinePassed = \Carbon\Carbon::parse($job->deadline)
            ->setTimezone('Asia/Manila')->startOfDay()
            ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — deadline has passed.');
            return;
        }

        $this->shareJobId       = $id;
        $this->shareJobTitle    = $job->job_title;
        $this->shareCompany     = $job->company_name;
        $this->shareEmpType     = $job->employment_type;
        $this->shareLocation    = $job->location ?? '';
        $this->shareExpLevel    = $job->experience_level ?? '';
        $this->shareSalary      = $job->salary ?? '';
        $this->shareDeadline    = $job->deadline ?? '';
        $this->shareDescription = $job->description ?? '';
        $this->shareCollege     = $job->target_college ?? '';
        $this->showShareModal   = true;
        $this->showViewModal    = false;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal   = false;
        $this->shareJobId       = null;
        $this->shareJobTitle    = '';
        $this->shareCompany     = '';
        $this->shareEmpType     = '';
        $this->shareLocation    = '';
        $this->shareExpLevel    = '';
        $this->shareSalary      = '';
        $this->shareDeadline    = '';
        $this->shareDescription = '';
        $this->shareCollege     = '';
    }

    public function shareToAlumniChats(): void
    {
        $this->guardAuth();
        if (! $this->shareJobId) {
            $this->dispatch('flash-message', type: 'error', message: 'No job selected to share.');
            return;
        }

        $deadlinePassed = $this->shareDeadline
            && \Carbon\Carbon::parse($this->shareDeadline)
                ->setTimezone('Asia/Manila')->startOfDay()
                ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — deadline has passed.');
            return;
        }

        $college     = $this->shareCollege ?: $this->organizerCollege;
        $courseCodes = \App\Models\Course::where('college', $college)->pluck('code')->toArray();

        if (empty($courseCodes)) {
            $this->dispatch('flash-message', type: 'error', message: 'No alumni batch chats found for your college.');
            return;
        }

        $roomIds = DB::table('chat_rooms')
            ->whereIn('course_code', $courseCodes)
            ->pluck('id')
            ->toArray();

        if (empty($roomIds)) {
            $this->dispatch('flash-message', type: 'error', message: 'No batch chat rooms found for this college.');
            return;
        }

        $dl = $this->shareDeadline
            ? \Carbon\Carbon::parse($this->shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
            : null;

        $org     = auth()->user()?->organizer;
        $orgName = auth()->user()?->name ?? 'Organizer';

        $lines   = [];
        $lines[] = "📢 Job Opportunity — Posted by {$orgName}";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "🎯 {$this->shareJobTitle}";
        $lines[] = "🏢 {$this->shareCompany}";
        if ($this->shareLocation)  $lines[] = "📍 {$this->shareLocation}";
        if ($this->shareEmpType)   $lines[] = "💼 {$this->shareEmpType}";
        if ($this->shareExpLevel)  $lines[] = "📊 {$this->shareExpLevel}";
        if ($this->shareSalary)    $lines[] = "💰 {$this->shareSalary}";
        if ($dl)                   $lines[] = "📅 Deadline: {$dl}";
        if ($this->shareCollege)   $lines[] = "🏫 For: {$this->shareCollege}";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "👀 Check it out on the Alumni Portal → " . $this->jobsBaseUrl();

        $body = implode("\n", $lines);
        $now  = now();

        $inserts = array_map(fn($roomId) => [
            'room_id'     => $roomId,
            'sender_type' => 'organizer',
            'sender_id'   => $org?->id ?? 0,
            'body'        => $body,
            'reply_to_id' => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $roomIds);

        DB::table('chat_messages')->insert($inserts);

        $count = count($roomIds);
        $this->closeShareModal();
        $this->dispatch('flash-message', type: 'success', message: "Job shared to {$count} alumni batch chat" . ($count > 1 ? 's' : '') . "!");
    }

    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('jobs.index', [], false); } catch (\Throwable) { $path = '/jobs'; }
        return $base . $path;
    }
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
:root {
    --brand:       #7a3f91;
    --brand-dark:  #5e2f72;
    --brand-light: #f9f7fc;
    --brand-mid:   #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}
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
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #FAFAFA !important; cursor: default; }

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
/* ── View modal meta row ── */
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
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
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

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color:#333333;">Job Management</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color:#555555;">
                    Post and manage job listings for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        {{ $this->organizerCollege ?: 'your college' }}
                    </span>
                </p>
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
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="filter-input w-full"
                       style="padding-left: 2.25rem; padding-right: 1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus" class="filter-input" style="color:#333333;">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
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
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-4 h-4" style="color:#7a3f91;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
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
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage"
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
                <table class="w-full min-w-[700px] bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10" style="color:#555555;">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell" style="color:#555555;">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell" style="color:#555555;">Company</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden sm:table-cell" style="color:#555555;">Deadline</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest" style="color:#555555;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $today            = now('Asia/Manila')->startOfDay();
                            $daysLeft         = (int) $today->diffInDays($dl->copy()->startOfDay(), false);
                            $isUrgent         = $daysLeft <= 7 && !($job->_isDeadlinePassed ?? false);
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $deadlineStr      = $dl->format('M d, Y');
                            $isAlumniDirector = is_null($job->organizer_id);
                            $isActive         = $job->status === 'ACTIVE';
                            $canShare         = !$isDeadlinePassed && $isActive;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors duration-100">

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#666666;">{{ $job->created_at->diffForHumans() }}</p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <p class="text-sm font-semibold" style="color:#333333;">{{ $job->employment_type }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                <p class="text-sm max-w-[160px] truncate" style="color:#555555;">{{ $job->company_name }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden sm:table-cell whitespace-nowrap">
                                @if($isDeadlinePassed)
                                    <p class="text-sm font-semibold text-red-600">Closed</p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $deadlineStr }}</p>
                                    @if(!$isAlumniDirector && !$isActive)
                                        <p class="text-[10px] text-amber-600 mt-0.5 font-semibold">Edit deadline to activate</p>
                                    @endif
                                @elseif($isUrgent)
                                    <p class="text-sm font-semibold text-red-600">
                                        {{ $daysLeft === 0 ? 'Today' : ($daysLeft === 1 ? 'Tomorrow' : 'In '.$daysLeft.' days') }}
                                    </p>
                                    <p class="text-xs mt-0.5" style="color:#777777;">{{ $deadlineStr }}</p>
                                @else
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $deadlineStr }}</p>
                                    <p class="text-xs mt-0.5" style="color:#555555;">{{ $daysLeft }} day{{ $daysLeft !== 1 ? 's' : '' }} left</p>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isActive)
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

                                    {{-- View --}}
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share --}}
                                    @if($canShare)
                                        <button type="button" wire:click.stop="openShareModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed whitespace-nowrap select-none">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </span>
                                    @endif

                                    @if(!$isAlumniDirector)
                                        {{-- Activate / Deactivate --}}
                                        @if($isActive)
                                            <button type="button" wire:click="confirmToggleStatus({{ $job->id }})"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-300 hover:bg-white hover:border-amber-500 transition cursor-pointer whitespace-nowrap">
                                                <i class="fas fa-circle-pause text-xs"></i>
                                                <span class="hidden xl:inline">Deactivate</span>
                                            </button>
                                        @elseif(!$isDeadlinePassed)
                                            <button type="button" wire:click="confirmToggleStatus({{ $job->id }})"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-300 hover:bg-white hover:border-emerald-500 transition cursor-pointer whitespace-nowrap">
                                                <i class="fas fa-circle-play text-xs"></i>
                                                <span class="hidden xl:inline">Activate</span>
                                            </button>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed whitespace-nowrap select-none"
                                                  title="Edit deadline first">
                                                <i class="fas fa-circle-play text-xs"></i>
                                                <span class="hidden xl:inline">Activate</span>
                                            </span>
                                        @endif

                                        {{-- Edit --}}
                                        <button type="button" wire:click.stop="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-1 bg-purple-50 text-purple-600 border border-purple-200 rounded-lg whitespace-nowrap">
                                            <i class="fas fa-lock text-[9px]"></i> Alumni Director
                                        </span>
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
                        @else Click <strong>Post a Job</strong> to create your first listing.
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

        </div>{{-- /relative wrapper --}}

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
                @if($filterStatus || $search || $filterType)
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


{{-- ══════════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN 3-COLUMN  (matches Events layout)
══════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in"
     @keydown.escape.window="$wire.closePostModal()">

    {{-- ── Top Bar ── --}}
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

    {{-- ── Validation Errors Banner ── --}}
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

    {{-- ── 3-COLUMN BODY ── --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-0 overflow-hidden">

        {{-- ═══ LEFT COLUMN: Organization + Target College ═══ --}}
        <div class="w-full lg:w-[300px] xl:w-[320px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto scroll-c bg-white"
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

                {{-- Target College --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-building-columns text-[9px]"></i> Target College
                    </div>
                    <div class="card-section-body">
                        <div class="flex items-center gap-2.5 bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5">
                            <i class="fas fa-lock text-blue-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-blue-900 text-sm truncate">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-xs text-blue-700 mt-0.5">Auto-selected · your alumni only</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ MIDDLE COLUMN: Job Info + Description + Qualifications + How to Apply ═══ --}}
        <div class="flex-1 min-w-0 overflow-y-auto scroll-c border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4">

                {{-- Job Information --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-briefcase text-[9px]"></i> Job Information
                    </div>
                    <div class="card-section-body space-y-4">

                        {{-- Job Title --}}
                        <div>
                            <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                                   class="form-input {{ isset($postErrors['postJobTitle']) ? 'error' : '' }}">
                            @if(isset($postErrors['postJobTitle']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                        </div>

                        {{-- Type + Level --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postEmpType" class="form-input {{ isset($postErrors['postEmpType']) ? 'error' : '' }}"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;padding-right:2.5rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postEmpType']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                            </div>
                            <div>
                                <label class="form-label">Experience Level <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postExpLevel" class="form-input {{ isset($postErrors['postExpLevel']) ? 'error' : '' }}"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;padding-right:2.5rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}">{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postExpLevel']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                            </div>
                        </div>

                        {{-- Salary + Deadline --}}
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

        {{-- ═══ RIGHT COLUMN: Tips + Actions ═══ --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 overflow-y-auto scroll-c bg-white flex flex-col"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4 flex-1">

                {{-- Quick Tips Card --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-lightbulb text-[9px]"></i> Posting Tips
                    </div>
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
                                <span>Use <strong>Share to Batch Chats</strong> after posting to notify alumni directly.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Job goes live immediately — no approval required.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                {{-- College Notice --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-building-columns text-[9px]"></i> Visibility
                    </div>
                    <div class="card-section-body">
                        <div class="flex items-center gap-2.5 bg-purple-50 border border-purple-100 rounded-xl px-3 py-2.5">
                            <i class="fas fa-users text-purple-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-purple-800 text-xs truncate">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-xs text-purple-600 mt-0.5">Only alumni from this college can see this job</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Action Buttons - sticky at bottom --}}
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


{{-- ══════════════════════════════════════════════════════════════════════════
     EDIT JOB — FULL SCREEN 3-COLUMN  (matches Events layout)
══════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
@php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in"
     @keydown.escape.window="$wire.closeEditModal()">

    {{-- ── Top Bar ── --}}
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

    {{-- Inactive Banner --}}
    @if($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-2 shrink-0 flex items-center gap-3">
        <i class="fas fa-circle-pause text-amber-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-amber-800">
            <strong>This job is currently Inactive.</strong>
            @if($editingJob && \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay()))
                The deadline has passed — update it, save, then use <strong>Activate</strong>.
            @else
                Edit details and use <strong>Activate</strong> from the table or view panel to go live.
            @endif
        </p>
    </div>
    @endif

    {{-- ── Validation Errors Banner ── --}}
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

    {{-- ── 3-COLUMN BODY ── --}}
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-0 overflow-hidden">

        {{-- ═══ LEFT COLUMN: Organization + Target College ═══ --}}
        <div class="w-full lg:w-[300px] xl:w-[320px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto scroll-c bg-white"
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
                                    class="form-input {{ isset($editErrors['editCompanyType']) ? 'error' : '' }}"
                                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;padding-right:2.5rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;">
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

                {{-- Target College --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-building-columns text-[9px]"></i> Target College
                    </div>
                    <div class="card-section-body">
                        <div class="flex items-center gap-2.5 bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5">
                            <i class="fas fa-lock text-blue-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-blue-900 text-sm truncate">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-xs text-blue-700 mt-0.5">Auto-selected · your alumni only</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- ═══ MIDDLE COLUMN: Job Info + Description + Qualifications + How to Apply ═══ --}}
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
                            <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                                   class="form-input {{ isset($editErrors['editJobTitle']) ? 'error' : '' }}">
                            @if(isset($editErrors['editJobTitle']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="editEmpType"
                                        class="form-input {{ isset($editErrors['editEmpType']) ? 'error' : '' }}"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;padding-right:2.5rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;">
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
                                        class="form-input {{ isset($editErrors['editExpLevel']) ? 'error' : '' }}"
                                        style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.75rem center;background-repeat:no-repeat;background-size:1.1em 1.1em;padding-right:2.5rem;-webkit-appearance:none;-moz-appearance:none;appearance:none;">
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
                                    <p class="text-xs mt-1 text-amber-600 font-semibold"><i class="fas fa-lightbulb mr-1"></i>Set a future deadline → save → then Activate.</p>
                                @endif
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
                        <textarea wire:model.defer="editDescription" rows="6" maxlength="5000"
                                  placeholder="Describe the role, responsibilities…"
                                  class="form-input resize-none {{ isset($editErrors['editDescription']) ? 'error' : '' }}"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                </div>

                {{-- Qualifications --}}
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

                {{-- How to Apply --}}
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

        {{-- ═══ RIGHT COLUMN: Tips + Actions ═══ --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 overflow-y-auto scroll-c bg-white flex flex-col"
             style="scrollbar-width:thin;">
            <div class="p-4 space-y-4 flex-1">

                {{-- Quick Tips Card --}}
                <div class="card-section">
                    <div class="card-section-hd">
                        <i class="fas fa-lightbulb text-[9px]"></i> Edit Tips
                    </div>
                    <div class="card-section-body">
                        <ul class="space-y-2.5">
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Changes are saved immediately — no approval required.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>If inactive with a past deadline, update the deadline first then activate.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>Editing does not change the Active/Inactive status.</span>
                            </li>
                            <li class="flex items-start gap-2 text-xs" style="color:#333333;">
                                <i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[10px]"></i>
                                <span>After saving, use <strong>Share to Batch Chats</strong> to re-notify alumni.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            {{-- Action Buttons - sticky at bottom --}}
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


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW JOB — FULL SCREEN 2-COLUMN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job            = $this->viewingJob;
    $dl             = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft       = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
    $isExp          = now('Asia/Manila')->startOfDay()->gt($dl->copy()->startOfDay());
    $isUrgentView   = $daysLeft <= 7 && !$isExp;
    $createdPH      = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType    = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $isAlumniDirector = is_null($job->organizer_id);
    $isActiveView   = $job->status === 'ACTIVE';
    $viewCanShare   = !$isExp && $isActiveView;
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- ── Header Bar with Actions ── --}}
    <div class="flex items-center justify-between px-5 py-3 shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $job->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 ml-3 flex-wrap justify-end">
            {{-- Share --}}
            @if($viewCanShare)
                <button type="button" wire:click="openShareModal({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i>
                    <span class="hidden sm:inline">Share</span>
                </button>
            @endif
            @if(!$isAlumniDirector)
                {{-- Edit --}}
                <button wire:click="openEditModal({{ $job->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white/10 hover:bg-white/20 border border-white/20 text-white transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs"></i>
                    <span class="hidden sm:inline">Edit</span>
                </button>
                {{-- Activate / Deactivate --}}
                @if($isActiveView)
                    <button wire:click="confirmToggleStatus({{ $job->id }})" type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-400/20 hover:bg-amber-400/30 border border-amber-300/40 text-white transition cursor-pointer">
                        <i class="fas fa-circle-pause text-xs"></i>
                        <span class="hidden sm:inline">Deactivate</span>
                    </button>
                @elseif(!$isExp)
                    <button wire:click="confirmToggleStatus({{ $job->id }})" type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-400/20 hover:bg-emerald-400/30 border border-emerald-300/40 text-white transition cursor-pointer">
                        <i class="fas fa-circle-play text-xs"></i>
                        <span class="hidden sm:inline">Activate</span>
                    </button>
                @endif
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-purple-300/20 border border-purple-200/30 text-white">
                    <i class="fas fa-lock text-xs"></i> Read Only
                </span>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i><span class="hidden sm:inline">Close</span>
            </button>
        </div>
    </div>

    {{-- Alumni Director Notice --}}
    @if($isAlumniDirector)
    <div class="bg-purple-50 border-b border-purple-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-purple-800 font-semibold">This job was posted by an <strong>Alumni Director</strong>. Editing is not available.</p>
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
                <div class="flex items-center gap-2">
                    @if($isActiveView)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/80 text-white text-xs font-semibold">
                            <i class="fas fa-circle-check text-[9px]"></i> Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/80 text-white text-xs font-semibold">
                            <i class="fas fa-circle-pause text-[9px]"></i> Inactive
                        </span>
                    @endif
                    @if($isAlumniDirector)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-purple-300/40 text-white text-xs font-semibold">
                            <i class="fas fa-shield-halved text-[9px]"></i> Alumni Director
                        </span>
                    @endif
                </div>
                <i class="fas fa-briefcase text-white/20 text-3xl"></i>
            </div>

            {{-- Meta cards --}}
            <div class="flex flex-col gap-2.5 px-4 pb-4">

                {{-- Job basics --}}
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-blue-100">
                        <i class="fas fa-clock text-blue-600 text-base"></i>
                    </span>
                    <div>
                        <p class="meta-label">Employment Type</p>
                        <p class="meta-value">{{ $job->employment_type }}</p>
                        <p class="meta-sub">{{ $job->experience_level }}</p>
                    </div>
                </div>

                {{-- Company --}}
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-violet-100">
                        <i class="fas fa-building text-violet-600 text-base"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="meta-label">Company</p>
                        <p class="meta-value truncate">{{ $job->company_name }}</p>
                        <p class="meta-sub truncate">{{ $displayType }}</p>
                    </div>
                </div>

                {{-- Location --}}
                @if($job->location)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-rose-100">
                        <i class="fas fa-location-dot text-rose-600 text-base"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="meta-label">Location</p>
                        <p class="meta-value truncate">{{ $job->location }}</p>
                    </div>
                </div>
                @endif

                {{-- Salary --}}
                @if($job->salary)
                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="meta-row-icon bg-emerald-100">
                        <i class="fas fa-money-bill-wave text-emerald-600 text-base"></i>
                    </span>
                    <div>
                        <p class="meta-label">Salary</p>
                        <p class="meta-value">{{ $job->salary }}</p>
                    </div>
                </div>
                @endif

                {{-- Deadline --}}
                <div class="flex items-center gap-3 p-3.5 rounded-xl border {{ $isExp ? 'bg-red-50 border-red-200' : ($isUrgentView ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-100') }}">
                    <span class="meta-row-icon {{ $isExp ? 'bg-red-100' : ($isUrgentView ? 'bg-amber-100' : 'bg-blue-100') }}">
                        <i class="fas fa-calendar-xmark text-base {{ $isExp ? 'text-red-600' : ($isUrgentView ? 'text-amber-600' : 'text-blue-600') }}"></i>
                    </span>
                    <div>
                        <p class="meta-label">Deadline</p>
                        <p class="meta-value {{ $isExp ? 'text-red-700' : ($isUrgentView ? 'text-amber-700' : '') }}">{{ $dl->format('F d, Y') }}</p>
                        <p class="meta-sub {{ $isExp ? 'text-red-600' : ($isUrgentView ? 'text-amber-600' : '') }}">
                            @if($isExp) Closed
                            @elseif($daysLeft === 0) Closing today!
                            @elseif($daysLeft === 1) Tomorrow
                            @else {{ $daysLeft }} days left
                            @endif
                        </p>
                        @if($isExp && !$isAlumniDirector && !$isActiveView)
                            <p class="text-[10px] text-amber-600 mt-0.5 font-semibold">Edit deadline to activate</p>
                        @endif
                    </div>
                </div>

                {{-- Target College --}}
                @if($job->target_college)
                <div class="p-3.5 rounded-xl bg-purple-50 border border-purple-100">
                    <p class="meta-label text-purple-600 mb-2">Target College</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $job->target_college) as $col)
                            <span class="inline-flex items-center text-xs font-semibold px-2 py-1 rounded-lg bg-white text-purple-700 border border-purple-200">{{ trim($col) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Posted info --}}
                <p class="text-xs text-center" style="color:#777777;">
                    Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}
                    · by {{ $isAlumniDirector ? 'Alumni Director' : 'You' }}
                </p>

            </div>
        </div>

        {{-- ═══ RIGHT: Content ═══ --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            {{-- Badges bar --}}
            <div class="shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700">
                        <i class="fas fa-clock text-[10px]"></i> {{ $job->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200" style="color:#333333;">
                        <i class="fas fa-layer-group text-[10px]"></i> {{ $job->experience_level }}
                    </span>
                    @if($isUrgentView)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-red-50 border border-red-200 text-red-600">
                            <i class="fas fa-fire text-[10px]"></i>
                            {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft.' days left') }}
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
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($job->description) }}</div>
                </div>

                @if($job->qualifications)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-purple-50">
                            <i class="fas fa-list-check text-purple-500 text-[10px]"></i>
                        </span>
                        Qualifications
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-4 border border-gray-100" style="line-height:1.75; color:#333333;">{{ trim($job->qualifications) }}</div>
                </div>
                @endif

                @if($job->application_instructions)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <h3 class="text-xs font-bold mb-3 flex items-center gap-2 uppercase tracking-widest" style="color:#333333;">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-emerald-50">
                            <i class="fas fa-paper-plane text-emerald-500 text-[10px]"></i>
                        </span>
                        How to Apply
                    </h3>
                    <div class="text-sm leading-relaxed whitespace-pre-wrap bg-emerald-50/50 rounded-lg p-4 border border-emerald-100" style="line-height:1.75; color:#333333;">{{ trim($job->application_instructions) }}</div>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     ACTIVATE / DEACTIVATE CONFIRM MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showToggleModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape.window="cancelToggleStatus">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">

        @if($toggleAction === 'activate')
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background:#059669;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-play text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Activate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will make it visible to alumni</p>
            </div>
        </div>
        @else
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background:#d97706;">
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
            <p class="text-sm mb-1" style="color:#555555;">You are about to <strong>{{ $toggleAction }}</strong>:</p>
            <p class="font-semibold text-lg mb-4 leading-snug"
               style="{{ $toggleAction === 'activate' ? 'color:#065f46;' : 'color:#92400e;' }}">
                "{{ $toggleJobTitle }}"
            </p>

            @if($toggleAction === 'activate')
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
                <button wire:click="cancelToggleStatus"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer bg-white" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5"></i>Cancel
                </button>
                @if($toggleAction === 'activate')
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60"
                        style="background-color:#059669;"
                        onmouseover="this.style.backgroundColor='#047857'"
                        onmouseout="this.style.backgroundColor='#059669'">
                    <span wire:loading wire:target="executeToggleStatus"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggleStatus">
                        <i class="fas fa-circle-play mr-1"></i> Yes, Activate
                    </span>
                </button>
                @else
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60"
                        style="background-color:#d97706;"
                        onmouseover="this.style.backgroundColor='#b45309'"
                        onmouseout="this.style.backgroundColor='#d97706'">
                    <span wire:loading wire:target="executeToggleStatus"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggleStatus">
                        <i class="fas fa-circle-pause mr-1"></i> Yes, Deactivate
                    </span>
                </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE — CENTERED MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobsBaseUrl();
    $shareHost        = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';
    $shareDescPreview = mb_strlen($shareDescription) > 160
        ? mb_substr($shareDescription, 0, 160) . '…'
        : $shareDescription;

    $fbLines   = [];
    $fbLines[] = "🎯 Job Opening: {$shareJobTitle}";
    $fbLines[] = "🏢 {$shareCompany}";
    if ($shareLocation)    $fbLines[] = "📍 {$shareLocation}";
    if ($shareEmpType)     $fbLines[] = "💼 {$shareEmpType}";
    if ($shareExpLevel)    $fbLines[] = "📊 {$shareExpLevel}";
    if ($shareSalary)      $fbLines[] = "💰 {$shareSalary}";
    if ($shareDlFormatted) $fbLines[] = "📅 Deadline: {$shareDlFormatted}";
    if ($shareCollege)     $fbLines[] = "🏫 For: {$shareCollege}";
    $fbLines[] = '';
    $fbLines[] = "Apply now through the PHILCST Alumni Portal 👇";
    $fbLines[] = $shareBaseUrl;
    $fbPostText = implode("\n", $fbLines);
@endphp

<div wire:ignore
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},
         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareModal(), 250);
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
             } catch(e) { console.warn('Copy failed', e); }
         },
         async shareOnFacebook() {
             await this.copyText(this.fbText);
             this.fbCopied = true;
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(this.baseUrl),'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             setTimeout(() => { this.fbCopied = false; }, 7000);
         },
         async shareOnMessenger() {
             await this.copyText(this.fbText);
             this.messengerCopied = true;
             const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) {
                 window.location.href = 'fb-messenger://share/?link=' + encodeURIComponent(this.baseUrl);
                 setTimeout(() => window.open('https://www.messenger.com/','_blank','noopener'), 1500);
             } else {
                 window.open('https://www.messenger.com/','_blank','noopener');
             }
             setTimeout(() => { this.messengerCopied = false; }, 7000);
         },
         async copyLinkFn() {
             await this.copyText(this.baseUrl);
             this.copied = true;
             setTimeout(() => this.copied = false, 2500);
         }
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
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
         x-transition:leave-end="opacity-0 scale-95 translate-y-4"
         class="relative w-full max-w-5xl bg-white shadow-2xl flex flex-col rounded-2xl overflow-hidden will-change-transform"
         style="max-height: 90vh;">

        {{-- Header --}}
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
                    <div class="border-b border-gray-200 px-5 py-4 bg-[#f9f7fc]">
                        <p class="font-semibold text-base leading-tight" style="color:#333333;">{{ $shareJobTitle }}</p>
                        <p class="text-sm mt-1 font-semibold" style="color:#555555;">{{ $shareCompany }}</p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEmpType)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700">
                                <i class="fas fa-clock text-[10px]"></i>{{ $shareEmpType }}
                            </span>
                            @endif
                            @if($shareLocation)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100" style="color:#333333;">
                                <i class="fas fa-location-dot text-[10px]"></i>{{ $shareLocation }}
                            </span>
                            @endif
                            @if($shareExpLevel)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700">
                                <i class="fas fa-layer-group text-[10px]"></i>{{ $shareExpLevel }}
                            </span>
                            @endif
                            @if($shareSalary)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-emerald-50 text-emerald-700">
                                <i class="fas fa-money-bill-wave text-[10px]"></i>{{ $shareSalary }}
                            </span>
                            @endif
                            @if($shareDlFormatted)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-red-50 text-red-600">
                                <i class="fas fa-calendar-xmark text-[10px]"></i>Deadline: {{ $shareDlFormatted }}
                            </span>
                            @endif
                            @if($shareCollege)
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700">
                                <i class="fas fa-building-columns text-[10px]"></i>{{ $shareCollege }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-5 py-3.5 border-b border-gray-100">
                        <p class="text-sm leading-relaxed" style="color:#333333;">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#555555;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#333333;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the full job caption to your clipboard and opens the platform.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5 text-purple-700">
                            Sends the job caption directly to all batch chat rooms for
                            <strong>{{ $shareCollege ?: ($this->organizerCollege ?: 'your college') }}</strong>.
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
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">Share dialog opened!</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Press Ctrl+V in the post to paste the caption.</p>
                    </div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Messenger opened!</p>
                        <p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste the caption.</p>
                    </div>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Share on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens share dialog · caption copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_j3" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_j3)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-semibold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Opens Messenger · caption copied</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#555555;">or post directly</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="shareToAlumniChats"
                        wire:loading.attr="disabled"
                        wire:target="shareToAlumniChats"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-sm"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="shareToAlumniChats" class="block font-semibold text-sm">
                            Post to Batch Chats
                        </span>
                        <span wire:loading wire:target="shareToAlumniChats" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends to all batch rooms in your college</span>
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
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white" style="color:#333333;">
                    <span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-base" style="color:#555555;"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#555555;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1" style="color:#333333;">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>