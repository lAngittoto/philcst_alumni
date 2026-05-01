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

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    // ── NEW: Activate / Deactivate confirm modal ──────────────────────────────
    public bool   $showToggleModal = false;
    public ?int   $toggleJobId     = null;
    public string $toggleJobTitle  = '';
    public string $toggleAction    = ''; // 'activate' | 'deactivate'
    // ─────────────────────────────────────────────────────────────────────────

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

        $paginated = $q->paginate(20);

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

    // ── TOGGLE STATUS — now uses confirm modal ────────────────────────────────
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

        // Close view modal too if it was open
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
    // ─────────────────────────────────────────────────────────────────────────

    public function confirmDelete(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);
        $this->guardOwnership($job);
        $this->deleteJobId    = $id;
        $this->deleteJobTitle = $job->job_title;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('delete_post', 5, 60)) return;

        if ($this->deleteJobId) {
            $job = JobPosting::findOrFail($this->deleteJobId);
            $this->guardOwnership($job);

            $snapshot = [
                'job_title'       => $job->job_title,
                'company_name'    => $job->company_name,
                'employment_type' => $job->employment_type,
                'status_before'   => $job->status,
                'deadline'        => $job->deadline,
                'target_college'  => $job->target_college,
            ];

            $job->update([
                'status'          => 'ORGANIZER_DELETED',
                'deleted_by'      => auth()->user()?->name,
                'deleted_by_role' => 'organizer',
            ]);

            $this->logAudit(
                action:       'deleted',
                subjectLabel: $snapshot['job_title'],
                description:  sprintf(
                    'Organizer deleted job posting "%s" (ID #%d) at %s. Status set to ORGANIZER_DELETED.',
                    $snapshot['job_title'], $job->id, $snapshot['company_name']
                ),
                oldValues: $snapshot,
                newValues: ['status' => 'ORGANIZER_DELETED'],
                severity:  'warning'
            );

            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteJobTitle}' has been deleted.");
        }
        $this->showDeleteModal = false;
        $this->deleteJobId     = null;
        $this->deleteJobTitle  = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingJobId = null; }
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId     = null;
        $this->deleteJobTitle  = '';
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
    --text-secondary: #666666;
    --text-muted:     #999999;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(14px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.m-in { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }
.filter-input {
    border: 1.5px solid #e8e0f0;
    transition: border-color .15s, box-shadow .15s;
    color: var(--text-primary);
}
.filter-input:hover  { border-color: var(--brand); }
.filter-input:focus  { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}
.tbl-row { background-color: #ffffff; }
.tbl-row:hover { background-color: #f4f0f8 !important; cursor: default; }
.form-field {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1.5px solid #e5e7eb;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    background: #ffffff;
    color: #333333;
    transition: border-color .15s, box-shadow .15s;
}
.form-field:focus {
    outline: none;
    border-color: #7a3f91;
    box-shadow: 0 0 0 3px rgba(122,63,145,.10);
}
.form-field.err {
    border-color: #f87171;
    background-color: #fff5f5;
}
.form-field.err:focus {
    border-color: #f87171;
    box-shadow: 0 0 0 3px rgba(248,113,113,.12);
}
select.form-field {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23666666' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.75rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.5rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}
</style>

{{-- ══ FLASH TOAST ══════════════════════════════════════════════════════════ --}}
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
<div class="flex flex-col flex-1 gap-5 px-4 sm:px-6 lg:px-8 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight" style="color:#333333;">Job Management</h1>
                <p class="text-sm leading-relaxed mt-0.5" style="color:#666666;">
                    Post and manage job listings for
                    @if($this->organizerCollege)
                        <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                            <i class="fas fa-building-columns text-[9px]"></i>
                            {{ $this->organizerCollege }}
                        </span>
                    @else
                        your college
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-4 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-widest">
                <i class="fas fa-briefcase text-purple-600"></i>
                {{ $this->jobPostings->total() }} {{ $this->jobPostings->total() !== 1 ? 'Jobs' : 'Job' }}
            </span>
            <button wire:click="openPostModal"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-sm text-white shadow-md transition cursor-pointer"
                    style="background-color:#7a3f91;"
                    onmouseover="this.style.backgroundColor='#5e2f72'"
                    onmouseout="this.style.backgroundColor='#7a3f91'">
                <i class="fas fa-plus text-sm"></i> Post a Job
            </button>
        </div>
    </div>

    {{-- ══ FILTER BAR ═══════════════════════════════════════════════════════ --}}
    <div class="flex flex-wrap gap-2 items-center flex-shrink-0 px-4 py-3 rounded-2xl border border-[#E8E0F0] shadow-sm"
         style="background-color:#ffffff;">
        <div class="relative flex-1 min-w-[180px] max-w-xs"
             wire:ignore
             x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#999999;"></i>
            <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                   placeholder="Search title or company…"
                   class="filter-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm"
                   style="background-color:#ffffff;"
                   autocomplete="off" maxlength="100">
        </div>
        <select wire:model.live="filterStatus"
                class="filter-input px-3 py-2.5 rounded-xl text-sm"
                style="background-color:#ffffff;">
            <option value="">All Statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="INACTIVE">Inactive</option>
        </select>
        <select wire:model.live="filterType"
                class="filter-input px-3 py-2.5 rounded-xl text-sm hidden sm:block"
                style="background-color:#ffffff;">
            <option value="">All Types</option>
            @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                <option value="{{ $opt->label }}">{{ $opt->label }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterSort"
                class="filter-input px-3 py-2.5 rounded-xl text-sm hidden sm:block"
                style="background-color:#ffffff;">
            <option value="recent">Newest First</option>
            <option value="oldest">Oldest First</option>
        </select>
        <button wire:click="resetFilters"
                class="filter-input px-3 py-2.5 rounded-xl text-sm font-semibold flex items-center gap-1.5 transition uppercase tracking-widest cursor-pointer"
                style="background-color:#ffffff; color:#666666;">
            <i class="fas fa-rotate-left text-xs"></i>
            <span class="hidden sm:inline">Reset</span>
        </button>
    </div>

    {{-- Mobile row 2 --}}
    <div class="flex gap-2 sm:hidden -mt-3 flex-shrink-0">
        <select wire:model.live="filterType"
                class="filter-input flex-1 px-3 py-2.5 rounded-xl text-sm"
                style="background-color:#ffffff;">
            <option value="">All Types</option>
            @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                <option value="{{ $opt->label }}">{{ $opt->label }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterSort"
                class="filter-input flex-1 px-3 py-2.5 rounded-xl text-sm"
                style="background-color:#ffffff;">
            <option value="recent">Newest First</option>
            <option value="oldest">Oldest First</option>
        </select>
    </div>

    {{-- ══ TABLE SECTION ════════════════════════════════════════════════════ --}}
    <div class="flex-1 min-h-0 flex flex-col"
         wire:loading.class="opacity-50 pointer-events-none"
         wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeDelete,executeToggleStatus">

        @if($this->jobPostings->count() > 0)

        <div class="flex-1 min-h-0 rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col"
             style="background-color:#ffffff;">
            <div class="overflow-x-auto overflow-y-auto flex-1 scroll-c">
                <table class="w-full min-w-[700px]" style="background-color:#ffffff;">
                    <thead class="sticky top-0 z-10" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr style="background-color:#ffffff;">
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] w-10">#</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333]">Job Title</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden md:table-cell">Type</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden lg:table-cell">Company</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#333333] hidden sm:table-cell">Deadline</th>
                            <th class="px-4 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#333333]">Status</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-widest text-[#333333]">Actions</th>
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
                            $isAdminPosted    = is_null($job->organizer_id);
                            $isActive         = $job->status === 'ACTIVE';
                            $canShare         = !$isDeadlinePassed && $isActive;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                        @endphp
                        <tr class="tbl-row transition-colors">

                            {{-- # --}}
                            <td class="px-4 py-4 text-sm font-semibold text-[#c0a0d8] text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            {{-- Job Title --}}
                            <td class="px-4 py-4">
                                <div class="max-w-[220px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $job->created_at->diffForHumans() }}</p>
                                </div>
                            </td>

                            {{-- Type --}}
                            <td class="px-4 py-4 hidden md:table-cell">
                                <span class="text-sm font-semibold" style="color:#333333;">{{ $job->employment_type }}</span>
                            </td>

                            {{-- Company --}}
                            <td class="px-4 py-4 hidden lg:table-cell">
                                <p class="text-sm max-w-[160px] truncate" style="color:#666666;">{{ $job->company_name }}</p>
                            </td>

                            {{-- Deadline --}}
                            <td class="px-4 py-4 hidden sm:table-cell whitespace-nowrap">
                                @if($isDeadlinePassed)
                                    <p class="text-sm font-semibold text-red-600">Closed</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $deadlineStr }}</p>
                                    @if(!$isAdminPosted && !$isActive)
                                        <p class="text-[10px] text-amber-600 mt-0.5 font-semibold">Edit deadline to activate</p>
                                    @endif
                                @elseif($isUrgent)
                                    <p class="text-sm font-semibold text-red-600">
                                        {{ $daysLeft === 0 ? 'Today' : ($daysLeft === 1 ? 'Tomorrow' : 'In '.$daysLeft.' days') }}
                                    </p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $deadlineStr }}</p>
                                @else
                                    <p class="text-sm font-semibold" style="color:#333333;">{{ $deadlineStr }}</p>
                                    <p class="text-xs mt-0.5" style="color:#999999;">{{ $daysLeft }} day{{ $daysLeft !== 1 ? 's' : '' }} left</p>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-4 text-center">
                                @if($isActive)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        Inactive
                                    </span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-4">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">

                                    {{-- View --}}
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-white transition hover:opacity-90 cursor-pointer whitespace-nowrap"
                                            style="background-color:#7a3f91;">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden xl:inline">View</span>
                                    </button>

                                    {{-- Share --}}
                                    @if($canShare)
                                        <button type="button" wire:click.stop="openShareModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </button>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed whitespace-nowrap select-none">
                                            <i class="fas fa-share-nodes text-xs"></i>
                                            <span class="hidden xl:inline">Share</span>
                                        </span>
                                    @endif

                                    @if(!$isAdminPosted)
                                        {{-- Activate / Deactivate — now uses confirmToggleStatus --}}
                                        @if($isActive)
                                            <button type="button" wire:click="confirmToggleStatus({{ $job->id }})"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-300 hover:bg-white hover:border-amber-500 transition cursor-pointer whitespace-nowrap">
                                                <i class="fas fa-circle-pause text-xs"></i>
                                                <span class="hidden xl:inline">Deactivate</span>
                                            </button>
                                        @elseif(!$isDeadlinePassed)
                                            <button type="button" wire:click="confirmToggleStatus({{ $job->id }})"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-300 hover:bg-white hover:border-emerald-500 transition cursor-pointer whitespace-nowrap">
                                                <i class="fas fa-circle-play text-xs"></i>
                                                <span class="hidden xl:inline">Activate</span>
                                            </button>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed whitespace-nowrap select-none"
                                                  title="Edit deadline first">
                                                <i class="fas fa-circle-play text-xs"></i>
                                                <span class="hidden xl:inline">Activate</span>
                                            </span>
                                        @endif

                                        {{-- Edit --}}
                                        <button type="button" wire:click.stop="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden xl:inline">Edit</span>
                                        </button>

                                        {{-- Delete --}}
                                        <button type="button" wire:click.stop="confirmDelete({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-red-100 text-red-700 border border-red-300 hover:bg-white hover:border-red-500 transition cursor-pointer whitespace-nowrap">
                                            <i class="fas fa-trash text-xs"></i>
                                            <span>Delete</span>
                                        </button>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-1 bg-purple-50 text-purple-600 border border-purple-200 rounded-lg whitespace-nowrap">
                                            <i class="fas fa-lock text-[9px]"></i> Admin Post
                                        </span>
                                    @endif

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @else
        <div class="flex-1 rounded-2xl border border-gray-200 shadow-sm flex flex-col items-center justify-center gap-4 text-center px-6 py-20"
             style="background-color:#ffffff;">
            <div class="w-16 h-16 rounded-2xl flex items-center justify-center bg-gray-100">
                <i class="fas fa-briefcase text-2xl" style="color:#999999;"></i>
            </div>
            <div>
                <p class="font-semibold text-lg" style="color:#333333;">
                    @if($search || $filterStatus || $filterType)
                        No jobs match your filters
                    @else
                        No job postings yet
                    @endif
                </p>
                <p class="text-sm mt-1" style="color:#999999;">
                    @if($search || $filterStatus || $filterType)
                        Try clearing your filters to see all postings.
                    @else
                        Click <strong>Post a Job</strong> to create your first listing.
                    @endif
                </p>
            </div>
            @if($search || $filterStatus || $filterType)
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                        style="background-color:#7a3f91;">
                    <i class="fas fa-rotate-left mr-1.5"></i> Clear Filters
                </button>
            @endif
        </div>
        @endif

    </div>

    {{-- ══ PAGINATION ═══════════════════════════════════════════════════════ --}}
    @php
        $total = $this->jobPostings->total();
        $pp    = $this->jobPostings->perPage();
        $cp    = $this->jobPostings->currentPage();
        $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
        $to    = min($cp * $pp, $total);
    @endphp
    <div class="flex-shrink-0 rounded-2xl px-4 sm:px-5 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
         style="background:#7a3f91;">
        <p class="text-sm font-normal" style="color:rgba(255,255,255,.75);">
            Showing <span class="font-semibold text-white">{{ $from }}&ndash;{{ $to }}</span>
            of <span class="font-semibold text-white">{{ $total }}</span> job{{ $total !== 1 ? 's' : '' }}
            @if($filterStatus || $search || $filterType)
                <span class="text-white/60 text-xs ml-1">(filtered)</span>
            @endif
        </p>
        <div class="flex items-center gap-1.5">
            @if($this->jobPostings->onFirstPage())
                <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">&larr; Prev</button>
            @else
                <button wire:click="previousPage"
                        class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                        style="background:rgba(255,255,255,.15);">&larr; Prev</button>
            @endif
            <span class="px-3 py-1.5 text-sm font-semibold rounded-lg" style="background:#fff;color:#333333;">{{ $cp }} / {{ $this->jobPostings->lastPage() }}</span>
            @if($this->jobPostings->hasMorePages())
                <button wire:click="nextPage"
                        class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition cursor-pointer hover:opacity-80"
                        style="background:rgba(255,255,255,.15);">Next &rarr;</button>
            @else
                <button disabled class="px-3 sm:px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next &rarr;</button>
            @endif
        </div>
    </div>

</div>{{-- end main layout --}}


{{-- ══════════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closePostModal()"
     x-data="{}"
     x-effect="if($wire.postErrors && Object.keys($wire.postErrors).length > 0){$nextTick(()=>{const el=$refs.postBody;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs">Fill in the job details below</p>
            </div>
        </div>
        <button wire:click="closePostModal" type="button"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Validation Errors Banner --}}
    @if(count($postErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-4 shrink-0">
        <p class="font-semibold text-red-800 text-sm mb-2 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i> Please fix the following:
        </p>
        <ul class="text-red-700 text-sm space-y-1">
            @foreach($postErrors as $err)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Scrollable Body --}}
    <div class="flex-1 overflow-y-auto" x-ref="postBody"
         style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- CARD: Job Title --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-briefcase text-[#7a3f91] text-xs"></i> Job Information
                    </h3>
                </div>
                <div class="p-6 space-y-5">

                    {{-- Job Title --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Job Title <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                               class="form-field {{ isset($postErrors['postJobTitle']) ? 'err' : '' }}">
                        @if(isset($postErrors['postJobTitle']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                    </div>

                    {{-- Employment Type + Experience Level --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Employment Type <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.defer="postEmpType" class="form-field {{ isset($postErrors['postEmpType']) ? 'err' : '' }}">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postEmpType']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Experience Level <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.defer="postExpLevel" class="form-field {{ isset($postErrors['postExpLevel']) ? 'err' : '' }}">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}">{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($postErrors['postExpLevel']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                        </div>
                    </div>

                    {{-- Salary + Deadline --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Salary <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— optional</span>
                            </label>
                            <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 / month" maxlength="100"
                                   class="form-field">
                            <p class="text-xs mt-1" style="color:#999999;"><i class="fas fa-circle-info mr-1"></i>Leave blank if not disclosed.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Application Deadline <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="postDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="form-field {{ isset($postErrors['postDeadline']) ? 'err' : '' }}">
                            @if(isset($postErrors['postDeadline']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD: Organization --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building text-[#7a3f91] text-xs"></i> Organization Details
                    </h3>
                </div>
                <div class="p-6 space-y-5">

                    {{-- Category Selector --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-2">
                            Category <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-3 gap-3">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')"
                                    class="px-3 py-3.5 border-2 rounded-xl bg-white cursor-pointer transition text-center text-sm font-semibold flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='philcst' ? 'border-[#7a3f91] shadow-md text-white' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50 text-gray-600' }}"
                                    style="{{ $postOrgCategory==='philcst' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <i class="fas fa-school text-lg"></i>
                                <span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')"
                                    class="px-3 py-3.5 border-2 rounded-xl bg-white cursor-pointer transition text-center text-sm font-semibold flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='partner' ? 'border-[#7a3f91] shadow-md text-white' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50 text-gray-600' }}"
                                    style="{{ $postOrgCategory==='partner' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <i class="fas fa-handshake text-lg"></i>
                                <span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')"
                                    class="px-3 py-3.5 border-2 rounded-xl bg-white cursor-pointer transition text-center text-sm font-semibold flex flex-col items-center gap-1.5
                                           {{ $postOrgCategory==='custom' ? 'border-[#7a3f91] shadow-md text-white' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50 text-gray-600' }}"
                                    style="{{ $postOrgCategory==='custom' ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <i class="fas fa-pen-to-square text-lg"></i>
                                <span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-red-600 text-sm mt-2 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>

                    {{-- PHILCST --}}
                    @if($postOrgCategory === 'philcst')
                        @if($philcstName)
                        <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-4 py-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 text-white shadow-sm" style="background:linear-gradient(135deg,#7a3f91,#6a3580);">
                                <i class="fas fa-school text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-[#4c1d95] text-sm">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-xs mt-0.5 text-[#7c3aed]"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-purple-700 bg-white border border-purple-200 px-2 py-0.5 rounded-full shrink-0">
                                <i class="fas fa-lock text-[9px]"></i> Auto-filled
                            </span>
                        </div>
                        @endif

                    {{-- Partner --}}
                    @elseif($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),syncName(v){$wire.set('postPartnerName',v,false)},syncType(v){$wire.set('postPartnerType',v,false)}}">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text" placeholder="e.g. Acme Corporation" maxlength="150"
                                           class="form-field {{ isset($postErrors['postPartnerName']) ? 'err' : '' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text" placeholder="e.g. Private, NGO" maxlength="100"
                                           class="form-field {{ isset($postErrors['postPartnerType']) ? 'err' : '' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                   class="form-field {{ isset($postErrors['postLocation']) ? 'err' : '' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>

                    {{-- Custom --}}
                    @elseif($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),syncName(v){$wire.set('postCustomName',v,false)},syncType(v){$wire.set('postCustomType',v,false)}}">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="form-field {{ isset($postErrors['postCustomName']) ? 'err' : '' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text" placeholder="e.g. Government, NGO" maxlength="100"
                                           class="form-field {{ isset($postErrors['postCustomType']) ? 'err' : '' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                   class="form-field {{ isset($postErrors['postLocation']) ? 'err' : '' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>

                    @else
                        <div class="text-center py-6" style="color:#999999;">
                            <i class="fas fa-arrow-up text-3xl block mb-2 text-gray-200"></i>
                            <p class="text-sm">Select a category above to continue.</p>
                        </div>
                    @endif

                </div>
            </div>

            {{-- CARD: Target College --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building-columns text-[#7a3f91] text-xs"></i> Target College
                    </h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                        <i class="fas fa-lock text-blue-500 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-blue-900 text-sm">{{ $this->organizerCollege ?? 'Your College' }}</div>
                            <div class="text-xs text-blue-700 mt-0.5">You can only post jobs for your own college's alumni.</div>
                        </div>
                        <span class="text-xs text-blue-600 bg-white border border-blue-200 px-2 py-0.5 rounded-full font-semibold shrink-0">Auto-selected</span>
                    </div>
                </div>
            </div>

            {{-- CARD: Job Description --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-file-lines text-[#7a3f91] text-xs"></i> Job Description
                    </h3>
                </div>
                <div class="p-6 space-y-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Description <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="postDescription" rows="8"
                                  placeholder="Describe the role, responsibilities, and what the candidate will be doing…" maxlength="5000"
                                  class="form-field resize-y {{ isset($postErrors['postDescription']) ? 'err' : '' }}"></textarea>
                        @if(isset($postErrors['postDescription']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postDescription'] }}</p>@endif
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            <i class="fas fa-list-check mr-1" style="color:#7a3f91;"></i>Qualifications <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="postQualifications" rows="5"
                                  placeholder="e.g. Bachelor's degree in a relevant field, at least 1 year experience."
                                  maxlength="3000"
                                  class="form-field resize-y {{ isset($postErrors['postQualifications']) ? 'err' : '' }}"></textarea>
                        @if(isset($postErrors['postQualifications']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postQualifications'] }}</p>@endif
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            <i class="fas fa-paper-plane mr-1" style="color:#7a3f91;"></i>How to Apply <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="postApplicationInstructions" rows="5"
                                  placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]"
                                  maxlength="3000"
                                  class="form-field resize-y {{ isset($postErrors['postApplicationInstructions']) ? 'err' : '' }}"></textarea>
                        @if(isset($postErrors['postApplicationInstructions']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $postErrors['postApplicationInstructions'] }}</p>@endif
                    </div>
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="flex flex-wrap gap-3 pb-3">
                <button type="button" wire:click="closePostModal"
                        class="flex-1 sm:flex-none sm:w-40 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition cursor-pointer">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color:#7a3f91;"
                        onmouseover="this.style.backgroundColor='#5e2f72'"
                        onmouseout="this.style.backgroundColor='#7a3f91'">
                    <span wire:loading wire:target="savePost">
                        <i class="fas fa-spinner animate-spin"></i> Posting…
                    </span>
                    <span wire:loading.remove wire:target="savePost">
                        <i class="fas fa-paper-plane"></i> Post Job
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     EDIT JOB — FULL SCREEN
══════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
@php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeEditModal()"
     x-data="{}"
     x-effect="if($wire.editErrors && Object.keys($wire.editErrors).length > 0){$nextTick(()=>{const el=$refs.editBody;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Edit Job Posting</h2>
                <p class="text-white/60 text-xs">Update the job details below</p>
            </div>
        </div>
        <button wire:click="closeEditModal" type="button"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition cursor-pointer">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Inactive banner --}}
    @if($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-amber-50 border-b border-amber-200 px-6 lg:px-10 py-3 shrink-0 flex items-center gap-3">
        <i class="fas fa-circle-pause text-amber-500 flex-shrink-0"></i>
        <p class="text-sm text-amber-800">
            <strong>This job is currently Inactive.</strong>
            @if($editingJob && \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay()))
                The deadline has passed — update it to a future date, save, then use <strong>Activate</strong>.
            @else
                Edit details and use <strong>Activate</strong> from the table or view panel to go live.
            @endif
        </p>
    </div>
    @endif

    {{-- Validation Errors Banner --}}
    @if(count($editErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-4 shrink-0">
        <p class="font-semibold text-red-800 text-sm mb-2 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i> Please fix the following:
        </p>
        <ul class="text-red-700 text-sm space-y-1">
            @foreach($editErrors as $err)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Scrollable Body --}}
    <div class="flex-1 overflow-y-auto" x-ref="editBody"
         style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- CARD: Job Info --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-briefcase text-[#7a3f91] text-xs"></i> Job Information
                    </h3>
                </div>
                <div class="p-6 space-y-5">

                    {{-- Job Title --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Job Title <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                               class="form-field {{ isset($editErrors['editJobTitle']) ? 'err' : '' }}">
                        @if(isset($editErrors['editJobTitle']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                    </div>

                    {{-- Org Type + Company Name --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Organization Type <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.live="editCompanyType" class="form-field {{ isset($editErrors['editCompanyType']) ? 'err' : '' }}">
                                <option value="">Select Organization</option>
                                @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editCompanyType']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                        </div>
                        <div>
                            @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Company Name <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="editCompany" type="text" maxlength="150"
                                   @if($editIsPhilcst) readonly @endif
                                   class="form-field {{ isset($editErrors['editCompany']) ? 'err' : '' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                   style="{{ $editIsPhilcst ? 'color:#999999;' : '' }}">
                            @if(isset($editErrors['editCompany']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editCompany'] }}</p>@endif
                        </div>
                    </div>

                    {{-- Location --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Location <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="editLocation" type="text" maxlength="120"
                               @if($editIsPhilcst) readonly @endif
                               class="form-field {{ isset($editErrors['editLocation']) ? 'err' : '' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                               style="{{ $editIsPhilcst ? 'color:#999999;' : '' }}">
                        @if(isset($editErrors['editLocation']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editLocation'] }}</p>@endif
                    </div>

                    {{-- Employment Type + Experience Level --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Employment Type <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.defer="editEmpType" class="form-field {{ isset($editErrors['editEmpType']) ? 'err' : '' }}">
                                <option value="">Select Type</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editEmpType']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Experience Level <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.defer="editExpLevel" class="form-field {{ isset($editErrors['editExpLevel']) ? 'err' : '' }}">
                                <option value="">Select Level</option>
                                @foreach($this->orderedExpLevels as $lvl)
                                    <option value="{{ $lvl }}" @selected($editExpLevel === $lvl)>{{ $lvl }}</option>
                                @endforeach
                            </select>
                            @if(isset($editErrors['editExpLevel']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                        </div>
                    </div>

                    {{-- Salary + Deadline --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Salary <span class="font-normal normal-case tracking-normal text-gray-400 text-xs">— optional</span>
                            </label>
                            <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25,000 / month"
                                   class="form-field">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                                Application Deadline <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="editDeadline" type="date"
                                   min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                   class="form-field {{ isset($editErrors['editDeadline']) ? 'err' : '' }}">
                            @if(isset($editErrors['editDeadline']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                            @if($editingJob && $editingJob->status === 'INACTIVE')
                                <p class="text-xs mt-1 text-amber-600 font-semibold"><i class="fas fa-lightbulb mr-1"></i>Set a future deadline → save → then Activate.</p>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD: Target College --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-building-columns text-[#7a3f91] text-xs"></i> Target College
                    </h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                        <i class="fas fa-lock text-blue-500 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-blue-900 text-sm">{{ $this->organizerCollege ?? 'Your College' }}</div>
                            <div class="text-xs text-blue-700 mt-0.5">You can only post jobs for your own college's alumni.</div>
                        </div>
                        <span class="text-xs text-blue-600 bg-white border border-blue-200 px-2 py-0.5 rounded-full font-semibold shrink-0">Auto-selected</span>
                    </div>
                </div>
            </div>

            {{-- CARD: Job Description --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-file-lines text-[#7a3f91] text-xs"></i> Job Description
                    </h3>
                </div>
                <div class="p-6 space-y-6">

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            Description <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="editDescription" rows="18" maxlength="5000"
                                  placeholder="Describe the role, responsibilities, and what the candidate will be doing…"
                                  class="form-field resize-y {{ isset($editErrors['editDescription']) ? 'err' : '' }}"
                                  style="min-height: 20rem;"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            <i class="fas fa-list-check mr-1" style="color:#7a3f91;"></i>Qualifications <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="editQualifications" rows="14" maxlength="3000"
                                  placeholder="e.g. Bachelor's degree in a relevant field, at least 1 year experience."
                                  class="form-field resize-y {{ isset($editErrors['editQualifications']) ? 'err' : '' }}"
                                  style="min-height: 14rem;"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
                            <i class="fas fa-paper-plane mr-1" style="color:#7a3f91;"></i>How to Apply <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model.defer="editApplicationInstructions" rows="14" maxlength="3000"
                                  placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]"
                                  class="form-field resize-y {{ isset($editErrors['editApplicationInstructions']) ? 'err' : '' }}"
                                  style="min-height: 14rem;"></textarea>
                        @if(isset($editErrors['editApplicationInstructions']))<p class="text-red-600 text-sm mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-sm"></i>{{ $editErrors['editApplicationInstructions'] }}</p>@endif
                    </div>

                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="flex flex-wrap gap-3 pb-3">
                <button type="button" wire:click="closeEditModal"
                        class="flex-1 sm:flex-none sm:w-40 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition cursor-pointer">
                    <i class="fas fa-xmark mr-2"></i>Cancel
                </button>
                <button type="button" wire:click="saveEditJob"
                        wire:loading.attr="disabled" wire:target="saveEditJob"
                        class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        style="background-color:#7a3f91;"
                        onmouseover="this.style.backgroundColor='#5e2f72'"
                        onmouseout="this.style.backgroundColor='#7a3f91'">
                    <span wire:loading wire:target="saveEditJob">
                        <i class="fas fa-spinner animate-spin"></i> Saving…
                    </span>
                    <span wire:loading.remove wire:target="saveEditJob">
                        <i class="fas fa-floppy-disk"></i> Save Changes
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     VIEW JOB — SLIDE-OVER
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
    $isAdminPosted  = is_null($job->organizer_id);
    $isActiveView   = $job->status === 'ACTIVE';
    $viewCanShare   = !$isExp && $isActiveView;
@endphp
<div class="fixed inset-0 z-50 overflow-hidden"
     x-data="{ open: false }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="open = false; setTimeout(() => $wire.closeViewModal(), 290)">

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm"
         @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-3xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] text-white flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-briefcase text-white text-sm"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold leading-tight truncate">{{ $job->job_title }}</h2>
                    <p class="text-xs mt-0.5" style="color:rgba(255,255,255,.7);">{{ $job->company_name }} &middot; Posted {{ $job->created_at->diffForHumans() }}</p>
                </div>
            </div>
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)"
                    class="w-9 h-9 flex items-center justify-center rounded-lg bg-white/15 hover:bg-white/25 text-white transition text-xl leading-none flex-shrink-0 ml-3 cursor-pointer">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Admin-posted notice --}}
        @if($isAdminPosted)
        <div class="bg-purple-50 border-b border-purple-200 px-6 py-3 flex-shrink-0 flex items-center gap-2.5">
            <i class="fas fa-shield-halved text-purple-500 flex-shrink-0"></i>
            <p class="text-sm text-purple-800 font-semibold">This job was posted by an <strong>Admin</strong>. Editing and deleting are not available.</p>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            {{-- Status + badges --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex flex-wrap gap-2 mb-4">
                    @if($isActiveView)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-50 border border-emerald-200 text-emerald-700">
                            <i class="fas fa-circle-check text-[10px]"></i> Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-50 border border-amber-200 text-amber-700">
                            <i class="fas fa-circle-pause text-[10px]"></i> Inactive
                        </span>
                    @endif
                    @if($isAdminPosted)
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-purple-50 border border-purple-200 text-purple-700">
                            <i class="fas fa-shield-halved text-[10px]"></i> Admin Post
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700">
                        <i class="fas fa-clock text-[10px] text-blue-500"></i> {{ $job->employment_type }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200 text-gray-600">
                        <i class="fas fa-layer-group text-[10px] text-gray-400"></i> {{ $job->experience_level }}
                    </span>
                    @if($isUrgentView)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-red-50 border border-red-200 text-red-600">
                            <i class="fas fa-fire text-[10px]"></i>
                            {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? '1 day left' : $daysLeft.' days left') }}
                        </span>
                    @endif
                </div>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-building text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">
                            {{ $job->company_name }}
                            <span class="text-sm font-normal" style="color:#666666;"> · {{ $displayType }}</span>
                        </span>
                    </li>
                    @if($job->location)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-location-dot text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $job->location }}</span>
                    </li>
                    @endif
                    @if($job->salary)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-money-bill-wave text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold" style="color:#333333;">{{ $job->salary }}</span>
                    </li>
                    @endif
                    <li class="flex items-start gap-3">
                        <i class="fas fa-calendar-xmark text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <span class="text-base font-semibold {{ $isExp ? 'text-red-600' : ($isUrgentView ? 'text-red-600' : '') }}" style="{{ (!$isExp && !$isUrgentView) ? 'color:#333333;' : '' }}">
                            Deadline: {{ $dl->format('F d, Y') }}
                            @if($isExp) <span class="text-sm font-normal text-red-500 ml-1">(Closed)</span>
                            @elseif($daysLeft === 0) <span class="text-sm font-normal text-red-500 ml-1">(Closing today!)</span>
                            @elseif($daysLeft === 1) <span class="text-sm font-normal ml-1" style="color:#666666;">(Tomorrow)</span>
                            @else <span class="text-sm font-normal ml-1" style="color:#666666;">({{ $daysLeft }} days left)</span>
                            @endif
                        </span>
                    </li>
                    @if($job->target_college)
                    <li class="flex items-start gap-3">
                        <i class="fas fa-building-columns text-[#7a3f91] mt-0.5 w-4 flex-shrink-0 text-base"></i>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach(explode(',', $job->target_college) as $col)
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100">{{ trim($col) }}</span>
                            @endforeach
                        </div>
                    </li>
                    @endif
                </ul>
            </div>

            {{-- Posting Details --}}
            <div class="px-6 py-4 border-b border-gray-100">
                <div class="grid grid-cols-2 border border-gray-200 rounded-xl overflow-hidden divide-x divide-gray-100">
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color:#999999;">Date Posted</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $createdPH->format('M d, Y') }}</p>
                        <p class="text-xs mt-0.5" style="color:#666666;">{{ $createdPH->format('g:i A') }} · by {{ $isAdminPosted ? 'Admin' : 'You' }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color:#999999;">Posted For</p>
                        <p class="text-sm font-semibold" style="color:#333333;">{{ $job->target_college ?? ($this->organizerCollege ?? '—') }}</p>
                    </div>
                </div>
            </div>

            {{-- Description --}}
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Job Description</h3>
                <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $job->description }}</p>
            </div>

            @if($job->qualifications)
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">Qualifications</h3>
                <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $job->qualifications }}</p>
            </div>
            @endif

            @if($job->application_instructions)
            <div class="px-6 py-5">
                <h3 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:#333333;">How to Apply</h3>
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
                    <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:#333333;">{{ $job->application_instructions }}</p>
                </div>
            </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-gray-200 flex-shrink-0 flex items-center justify-end gap-2 flex-wrap bg-white">
            <button @click="open = false; setTimeout(() => $wire.closeViewModal(), 290)" type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 rounded-xl transition cursor-pointer" style="color:#666666;">
                <i class="fas fa-xmark text-xs"></i> Close
            </button>
            @if($viewCanShare)
                <button type="button" wire:click="openShareModal({{ $job->id }})"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-sky-700 bg-sky-50 border border-sky-200 hover:bg-white hover:border-sky-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-share-nodes text-xs"></i> Share
                </button>
            @else
                <span class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-gray-400 bg-gray-100 border border-gray-200 rounded-xl cursor-not-allowed select-none">
                    <i class="fas fa-share-nodes text-xs"></i> Share
                </span>
            @endif
            @if(!$isAdminPosted)
                {{-- Activate / Deactivate in view footer — also uses confirmToggleStatus --}}
                @if($isActiveView)
                    <button wire:click="confirmToggleStatus({{ $job->id }})"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-200 hover:bg-white hover:border-amber-400 rounded-xl transition cursor-pointer">
                        <i class="fas fa-circle-pause text-xs"></i> Deactivate
                    </button>
                @elseif(!$isExp)
                    <button wire:click="confirmToggleStatus({{ $job->id }})"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-white hover:border-emerald-400 rounded-xl transition cursor-pointer">
                        <i class="fas fa-circle-play text-xs"></i> Activate
                    </button>
                @else
                    <span class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-gray-400 bg-gray-100 border border-gray-200 rounded-xl cursor-not-allowed select-none"
                          title="Update deadline first">
                        <i class="fas fa-circle-play text-xs"></i> Activate
                    </span>
                @endif
                <button wire:click="confirmDelete({{ $job->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-red-600 bg-red-50 border border-red-200 hover:bg-white hover:border-red-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-trash text-xs"></i> Delete
                </button>
                <button wire:click="openEditModal({{ $job->id }})" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-blue-700 bg-blue-50 border border-blue-200 hover:bg-white hover:border-blue-400 rounded-xl transition cursor-pointer">
                    <i class="fas fa-pen-to-square text-xs"></i> Edit
                </button>
            @else
                <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-purple-50 rounded-xl border border-purple-200" style="color:#7a3f91;">
                    <i class="fas fa-lock text-xs"></i> Read Only
                </span>
            @endif
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     ACTIVATE / DEACTIVATE CONFIRM MODAL  ← BAGO ITO
══════════════════════════════════════════════════════════════════════════ --}}
@if($showToggleModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape.window="cancelToggleStatus">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">

        {{-- Modal Header — color changes based on action --}}
        @if($toggleAction === 'activate')
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background-color:#059669;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-play text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Activate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will make it visible to alumni</p>
            </div>
        </div>
        @else
        <div class="px-6 py-5 rounded-t-2xl flex items-center gap-3" style="background-color:#d97706;">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-circle-pause text-white text-base"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Deactivate Job Posting</h2>
                <p class="text-white/70 text-xs mt-0.5">This will hide it from alumni</p>
            </div>
        </div>
        @endif

        {{-- Modal Body --}}
        <div class="p-6" style="background-color:#ffffff;">

            <p class="text-sm mb-1" style="color:#666666;">
                You are about to <strong>{{ $toggleAction }}</strong>:
            </p>
            <p class="font-semibold text-lg mb-4 leading-snug"
               style="{{ $toggleAction === 'activate' ? 'color:#065f46;' : 'color:#92400e;' }}">
                "{{ $toggleJobTitle }}"
            </p>

            {{-- Info box --}}
            @if($toggleAction === 'activate')
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0"></i>
                <span style="color:#065f46;">
                    Alumni will be able to see and apply to this job posting once activated.
                </span>
            </div>
            @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span style="color:#78350f;">
                    Alumni won't see this job posting until you re-activate it. No data will be lost.
                </span>
            </div>
            @endif

            {{-- Action Buttons --}}
            <div class="flex gap-3">
                <button wire:click="cancelToggleStatus"
                        class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer"
                        style="color:#333333; background-color:#ffffff;">
                    <i class="fas fa-xmark mr-1.5"></i>Cancel
                </button>

                @if($toggleAction === 'activate')
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled"
                        wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                        style="background-color:#059669;"
                        onmouseover="this.style.backgroundColor='#047857'"
                        onmouseout="this.style.backgroundColor='#059669'">
                    <span wire:loading wire:target="executeToggleStatus">
                        <i class="fas fa-spinner animate-spin"></i>
                    </span>
                    <span wire:loading.remove wire:target="executeToggleStatus">
                        <i class="fas fa-circle-play mr-1"></i> Yes, Activate
                    </span>
                </button>
                @else
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled"
                        wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition shadow-md cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                        style="background-color:#d97706;"
                        onmouseover="this.style.backgroundColor='#b45309'"
                        onmouseout="this.style.backgroundColor='#d97706'">
                    <span wire:loading wire:target="executeToggleStatus">
                        <i class="fas fa-spinner animate-spin"></i>
                    </span>
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
     DELETE MODAL
══════════════════════════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDelete">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in" style="background-color:#ffffff;">
        <div class="px-6 py-5 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-semibold text-lg">Delete Job Posting</h2>
        </div>
        <div class="p-6" style="background-color:#ffffff;">
            <p class="text-sm mb-1" style="color:#666666;">You are about to delete:</p>
            <p class="font-semibold text-red-700 text-lg mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm flex items-start gap-2" style="color:#666666;">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>The job will be removed from your list. <strong>Admin can still see and restore it</strong> if needed.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete"
                        class="flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition cursor-pointer" style="color:#333333; background-color:#ffffff;">
                    Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition shadow-md cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══════════════════════════════════════════════════════════════════════════
     SHARE — SLIDE-OVER
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
     class="fixed inset-0 z-[70] overflow-hidden"
     x-data="{
         open: false,
         copied: false, fbCopied: false, messengerCopied: false,
         fbText:  {{ json_encode($fbPostText) }},
         baseUrl: {{ json_encode($shareBaseUrl) }},
         close() {
             this.open = false;
             setTimeout(() => $wire.closeShareModal(), 290);
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
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-280"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="absolute inset-y-0 right-0 w-full max-w-4xl bg-white shadow-2xl flex flex-col will-change-transform">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0" style="background-color:#ffffff;">
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
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100" style="color:#7a3f91;">
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
                        <p class="text-sm leading-relaxed" style="color:#555555;">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f9f7fc]">
                        <i class="fas fa-globe text-xs" style="color:#999999;"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold" style="color:#666666;">{{ strtoupper($shareHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the full job caption to your clipboard and opens the platform.
                            Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.
                        </p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-5 py-4 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-base flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">Post to Batch Chats</p>
                        <p class="text-sm mt-0.5" style="color:#7a3f91;">
                            Sends the job caption directly to all batch chat rooms for
                            <strong>{{ $shareCollege ?: ($this->organizerCollege ?: 'your college') }}</strong>.
                        </p>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Share via</p>

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
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
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
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(to right,#00B2FF,#006AFF);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_j2" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_j2)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or post directly</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="shareToAlumniChats"
                        wire:loading.attr="disabled"
                        wire:target="shareToAlumniChats"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color:#5e2f72; background-color:#f5eef9;">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform"
                          style="background:#7a3f91;">
                        <i class="fas fa-users text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="shareToAlumniChats" class="block font-semibold text-sm">
                            Post to Batch Chats
                        </span>
                        <span wire:loading wire:target="shareToAlumniChats" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5" style="color:#7a3f91;">Sends to all batch rooms in your college</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm" style="color:#7a3f91;"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white" style="color:#999999;">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white"
                        style="color:#333333;">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-400'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate" style="color:#999999;">{{ $shareBaseUrl }}</p>
                    </div>
                </button>

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