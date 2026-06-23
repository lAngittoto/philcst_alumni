{{-- resources/views/livewire/organizer/organizer-job-management.blade.php --}}

<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\AuditLog;
use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';

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

    public $postJobImage  = null;
    public $editJobImage  = null;
    public bool $postRemoveImage = false;
    public bool $editRemoveImage = false;

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
    public string $editCurrentImage                 = '';

    public bool   $showToggleModal = false;
    public ?int   $toggleJobId     = null;
    public string $toggleJobTitle  = '';
    public string $toggleAction    = '';

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    public bool   $showRestoreModal  = false;
    public ?int   $restoreJobId      = null;
    public string $restoreJobTitle   = '';
    public bool   $restoreWillActivate = false;

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

    protected function rules(): array
    {
        return [
            'postJobImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'editJobImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

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

    private function storeJobImage($imageFile, string $existingPath = ''): ?string
    {
        if ($imageFile && $imageFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            if ($existingPath && Storage::disk('public')->exists($existingPath)) {
                Storage::disk('public')->delete($existingPath);
            }
            $path = $imageFile->store('job', 'public');
            return $path ?: null;
        }
        return null;
    }

    public static function jobImageUrl(?string $path): string
    {
        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::url($path);
        }
        return asset('storage/job/default-photo-job.jpg');
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
                'target_college','salary','deadline','status','job_image',
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

        if ($this->filterStatus === 'ORGANIZER_DELETED') {
            $q->where('organizer_id', $org->id)->where('status', 'ORGANIZER_DELETED');
        } elseif ($this->filterStatus !== '') {
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

        $q->orderBy('created_at', 'desc');

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

        if ($this->postJobImage) {
            try {
                $this->validateOnly('postJobImage');
            } catch (\Livewire\Exceptions\ValidationException $e) {
                $errors['postJobImage'] = 'Image must be JPG, PNG, or WebP and under 2MB.';
            }
        }

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

        $imagePath = $this->storeJobImage($this->postJobImage);

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
            'job_image'                => $imagePath,
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
                'has_image'                => $imagePath ? 'yes' : 'no (default)',
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
        $this->postJobImage   = null;
        $this->postRemoveImage = false;
    }

    public function viewJob(int $id): void
    {
        $this->guardAuth();
        $job = app(JobController::class)->getJob($id);

        if ($job->status === 'ORGANIZER_DELETED') {
            $org = auth()->user()?->organizer;
            if ($org && $job->organizer_id === $org->id) {
                $this->openEditModal($id);
            }
            return;
        }

        if (is_null($job->organizer_id)) {
            $this->viewingJobId = $id;
            $this->showViewModal = true;
            return;
        }

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
        $this->editCurrentImage = $job->job_image ?? '';
        $this->editJobImage     = null;
        $this->editRemoveImage  = false;
        $this->editErrors       = [];
        $this->showEditModal    = true;
    }

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
        $this->editCurrentImage = $job->job_image ?? '';
        $this->editJobImage     = null;
        $this->editRemoveImage  = false;
        $this->editErrors       = [];
        $this->showViewModal    = false;
        $this->showEditModal    = true;
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

        if ($this->editJobImage) {
            try {
                $this->validateOnly('editJobImage');
            } catch (\Livewire\Exceptions\ValidationException $e) {
                $errors['editJobImage'] = 'Image must be JPG, PNG, or WebP and under 2MB.';
            }
        }

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

        $newImagePath = $job->job_image;
        if ($this->editRemoveImage) {
            if ($job->job_image && Storage::disk('public')->exists($job->job_image)) {
                Storage::disk('public')->delete($job->job_image);
            }
            $newImagePath = null;
        } elseif ($this->editJobImage) {
            $newImagePath = $this->storeJobImage($this->editJobImage, $job->job_image ?? '');
        }

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
            'has_image'                => $job->job_image ? 'yes' : 'no',
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
            'job_image'                => $newImagePath,
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
                'has_image'                => $newImagePath ? 'yes' : 'no (default)',
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
        $this->editCurrentImage = '';
        $this->editJobImage     = null;
        $this->editRemoveImage  = false;
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
        // NOTE: we do NOT close showViewModal or showEditModal here — they stay open behind the confirm modal
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

            $this->dispatch('job-management-updated', [
                'id'     => $job->id,
                'title'  => $job->job_title,
                'action' => $newStatus === 'ACTIVE' ? 'activated' : 'deactivated',
            ]);

            $this->dispatch('flash-message', type: 'success', message: $msg);
        }

        $this->cancelToggleStatus();
        // Modal stays open — no closing of showViewModal or showEditModal
    }

    public function cancelToggleStatus(): void
    {
        $this->showToggleModal = false;
        $this->toggleJobId    = null;
        $this->toggleJobTitle = '';
        $this->toggleAction   = '';
    }

    public function confirmDeleteJob(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);
        $this->guardOwnership($job);

        if ($job->status !== 'INACTIVE') {
            $this->dispatch('flash-message', type: 'warning', message: 'Only inactive job postings can be deleted.');
            return;
        }

        $this->deleteJobId    = $id;
        $this->deleteJobTitle = $job->job_title;
        $this->showDeleteModal = true;
        // NOTE: do NOT close showViewModal or showEditModal
    }

    public function executeDeleteJob(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('delete_job', 10, 60)) return;

        if (! $this->deleteJobId) { $this->cancelDeleteJob(); return; }

        $job = JobPosting::findOrFail($this->deleteJobId);
        $this->guardOwnership($job);

        if ($job->status !== 'INACTIVE') {
            $this->dispatch('flash-message', type: 'warning', message: 'Only inactive job postings can be deleted.');
            $this->cancelDeleteJob();
            return;
        }

        $job->update([
            'status'          => 'ORGANIZER_DELETED',
            'deleted_by'      => auth()->user()->name,
            'deleted_by_role' => 'organizer',
            'updated_by'      => auth()->user()->name,
            'updated_by_role' => 'organizer',
        ]);

        $this->logAudit(
            action:       'deleted',
            subjectLabel: $job->job_title,
            description:  sprintf('Organizer soft-deleted job posting "%s" (ID #%d).', $job->job_title, $job->id),
            oldValues:    ['status' => 'INACTIVE'],
            newValues:    ['status' => 'ORGANIZER_DELETED'],
            severity:     'warning'
        );

        $this->dispatch('flash-message', type: 'success', message: 'Job posting deleted. You can restore it anytime from the Deleted filter.');
        $this->cancelDeleteJob();

        // Close modals after delete (job is now deleted, modals should close)
        if ($this->showEditModal) {
            $this->showEditModal = false;
            $this->resetEditFields();
        }
        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }
    }

    public function cancelDeleteJob(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
    }

    public function confirmRestoreJob(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);
        $this->guardOwnership($job);

        if ($job->status !== 'ORGANIZER_DELETED') {
            $this->dispatch('flash-message', type: 'warning', message: 'Only deleted job postings can be restored.');
            return;
        }

        $deadlinePassed = \Carbon\Carbon::parse($job->deadline)
            ->setTimezone('Asia/Manila')->startOfDay()
            ->lt(now('Asia/Manila')->startOfDay());

        $this->restoreJobId       = $id;
        $this->restoreJobTitle    = $job->job_title;
        $this->restoreWillActivate = !$deadlinePassed;
        $this->showRestoreModal   = true;
        // NOTE: do NOT close showViewModal or showEditModal
    }

    public function executeRestoreJob(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('restore_job', 10, 60)) return;

        if (! $this->restoreJobId) { $this->cancelRestoreJob(); return; }

        $job = JobPosting::findOrFail($this->restoreJobId);
        $this->guardOwnership($job);

        if ($job->status !== 'ORGANIZER_DELETED') {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting cannot be restored.');
            $this->cancelRestoreJob();
            return;
        }

        $deadlinePassed = \Carbon\Carbon::parse($job->deadline)
            ->setTimezone('Asia/Manila')->startOfDay()
            ->lt(now('Asia/Manila')->startOfDay());

        $newStatus = $deadlinePassed ? 'INACTIVE' : 'ACTIVE';

        $job->update([
            'status'          => $newStatus,
            'deleted_by'      => null,
            'deleted_by_role' => null,
            'updated_by'      => auth()->user()->name,
            'updated_by_role' => 'organizer',
        ]);

        $this->logAudit(
            action:       'restored',
            subjectLabel: $job->job_title,
            description:  sprintf(
                'Organizer restored deleted job posting "%s" (ID #%d). Restored as %s.',
                $job->job_title, $job->id, $newStatus
            ),
            oldValues: ['status' => 'ORGANIZER_DELETED'],
            newValues: ['status' => $newStatus],
            severity:  'info'
        );

        $this->dispatch('job-management-updated', [
            'id'     => $job->id,
            'title'  => $job->job_title,
            'action' => 'restored',
        ]);

        $msg = $newStatus === 'ACTIVE'
            ? 'Job posting restored and is now Active!'
            : 'Job posting restored as Inactive — deadline has passed. Update the deadline to activate it.';

        $this->dispatch('flash-message', type: $newStatus === 'ACTIVE' ? 'success' : 'warning', message: $msg);
        $this->cancelRestoreJob();
        // Modal stays open after restore
    }

    public function cancelRestoreJob(): void
    {
        $this->showRestoreModal  = false;
        $this->restoreJobId      = null;
        $this->restoreJobTitle   = '';
        $this->restoreWillActivate = false;
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
        // NOTE: do NOT close showViewModal or showEditModal — they stay open behind share modal
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

.fs-modal-body-col::-webkit-scrollbar { width: 4px; }
.fs-modal-body-col::-webkit-scrollbar-track { background: #f3f4f6; }
.fs-modal-body-col::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.fs-modal-body-col::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

select.tw-select-arrow,
select.filter-input,
select.form-input {
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

.modal-top-btn .mtip {
    position: absolute;
    top: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #111827;
    color: #fff;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px;
    white-space: nowrap; pointer-events: none;
    opacity: 0; transition: opacity .15s; z-index: 9999;
}
.modal-top-btn .mtip::before {
    content: '';
    position: absolute;
    bottom: 100%; left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-bottom-color: #111827;
}
.modal-top-btn:hover .mtip { opacity: 1; }

.activate-disabled-wrap { position: relative; display: inline-flex; }
.activate-disabled-wrap .adtip {
    position: absolute;
    top: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #111827;
    color: #fff;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
    padding: 4px 10px; border-radius: 6px;
    white-space: nowrap; pointer-events: none;
    opacity: 0; transition: opacity .15s; z-index: 9999;
}
.activate-disabled-wrap .adtip::before {
    content: '';
    position: absolute;
    bottom: 100%; left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-bottom-color: #111827;
}
.activate-disabled-wrap:hover .adtip { opacity: 1; }

tr.row-deleted td { opacity: 0.65; }
tr.row-deleted:hover td { opacity: 1; }

.img-upload-zone {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    transition: border-color 0.2s, background 0.2s;
    cursor: pointer;
    background: #fafafa;
}
.img-upload-zone:hover {
    border-color: #7a3f91;
    background: #f5eef9;
}
.img-upload-zone.has-image {
    border-style: solid;
    border-color: #d4aaeb;
    background: #fdf8ff;
}
.img-preview-thumb {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}
.img-preview-thumb-sm {
    width: 100%;
    height: 100px;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}

/* Change 4: taller textareas that fill to bottom */
.edit-textarea-xl  { flex: 1; min-height: 180px; }
.edit-textarea-lg  { flex: 1; min-height: 140px; }

/* View/Edit mode toggle styles */
.view-field-display {
    padding: 0.5rem 0.75rem;
    background: #fafafa;
    border: 1.5px solid #e8e0f0;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    color: #222;
    line-height: 1.6;
    min-height: 2.25rem;
}
.view-field-display.multiline {
    white-space: pre-wrap;
    min-height: 100px;
}
.view-field-display.empty {
    color: #aaa;
    font-style: italic;
}
</style>

{{-- Hover tooltip --}}
<div id="eo-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
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
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Job Management</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">
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
            <div class="relative inline-flex group">
                <button wire:click="openPostModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <i class="fas fa-plus text-sm"></i>
                </button>
                <div class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                    <i class="fas fa-plus text-[9px] mr-1"></i>Post a Job
                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-shrink-0" style="height: 75vh; max-height: 75vh; overflow: hidden;">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] placeholder-[#a78bbd] font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>
            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="ORGANIZER_DELETED">Deleted</option>
            </select>
            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            @if($filterStatus)
            @php
                $statusPillMap = [
                    'ACTIVE'            => ['label' => 'Active',   'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'INACTIVE'          => ['label' => 'Inactive', 'cls' => 'bg-amber-50 border-amber-300 text-amber-800'],
                    'ORGANIZER_DELETED' => ['label' => 'Deleted',  'cls' => 'bg-red-50 border-red-300 text-red-800'],
                ];
                $sPill = $statusPillMap[$filterStatus] ?? null;
            @endphp
            @if($sPill)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border {{ $sPill['cls'] }}">
                <i class="fas fa-filter text-[9px]"></i>{{ $sPill['label'] }}
                <button wire:click="$set('filterStatus', '')" type="button" class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer"><i class="fas fa-xmark text-[10px]"></i></button>
            </span>
            @endif
            @endif

            @if($filterType)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-blue-50 border-blue-300 text-blue-800">
                <i class="fas fa-filter text-[9px]"></i>{{ $filterType }}
                <button wire:click="$set('filterType', '')" type="button" class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer"><i class="fas fa-xmark text-[10px]"></i></button>
            </span>
            @endif

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333] bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($this->jobPostings->count() > 0)

            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto scroll-c bg-white">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10 text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Company</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest w-36 text-[#555555]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $isAlumniDirector = is_null($job->organizer_id);
                            $isActive         = $job->status === 'ACTIVE';
                            $isDeleted        = $job->status === 'ORGANIZER_DELETED';
                            $canShare         = !$isDeadlinePassed && $isActive;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                        @endphp
                        <tr class="transition-colors duration-100 cursor-pointer {{ $isDeleted ? 'bg-red-50/60 opacity-80 hover:opacity-100 hover:bg-red-100/60' : 'bg-white hover:bg-[#f5f0fa]' }}"
                            wire:click="viewJob({{ $job->id }})"
                            wire:key="job-row-{{ $job->id }}"
                            data-eo-row>

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2 {{ $isDeleted ? 'text-red-400 line-through' : 'text-[#333333]' }}">{{ $job->job_title }}</p>
                                    <p class="text-xs mt-0.5 {{ $isDeleted ? 'text-red-400' : 'text-[#666666]' }}">
                                        @if($isDeleted)
                                            Deleted {{ $job->updated_at->diffForHumans() }}
                                        @else
                                            {{ $job->created_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold {{ $isDeleted ? 'text-red-300' : 'text-[#333333]' }}">{{ $job->employment_type }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                <p class="text-sm text-[#555555] truncate max-w-[160px]">{{ $job->company_name }}</p>
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isDeleted)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-600 whitespace-nowrap">
                                        <i class="fas fa-trash-can text-[9px] mr-1"></i>Deleted
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
                                <div class="flex items-center justify-end gap-1.5" @click.stop>

                                    @if($isDeleted)
                                        <div class="relative inline-flex group" data-eo-share>
                                            <button type="button" wire:click.stop="confirmRestoreJob({{ $job->id }})"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Restore
                                                <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
                                    @else
                                        {{-- Share button --}}
                                        @if($canShare)
                                            <div class="relative inline-flex group" data-eo-share>
                                                <button type="button" wire:click.stop="openShareModal({{ $job->id }})"
                                                        class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-blue-50 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400">
                                                    <i class="fas fa-share-nodes"></i>
                                                </button>
                                                <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                    Share
                                                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                </div>
                                            </div>
                                        @else
                                            <div class="relative inline-flex group" data-eo-share>
                                                <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs bg-gray-100 text-gray-400 border border-gray-200 cursor-not-allowed">
                                                    <i class="fas fa-share-nodes"></i>
                                                </span>
                                                <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                    {{ $isDeadlinePassed ? 'Deadline Passed' : 'Inactive' }}
                                                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                </div>
                                            </div>
                                        @endif

                                        @if(!$isAlumniDirector)
                                            @if($isActive)
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <button type="button" wire:click.stop="confirmToggleStatus({{ $job->id }})"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-amber-50 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400">
                                                        <i class="fas fa-circle-pause"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Deactivate
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs bg-gray-50 text-gray-300 border border-gray-200 cursor-not-allowed">
                                                        <i class="fas fa-trash text-[10px]"></i>
                                                    </span>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Deactivate first to delete
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                            @elseif(!$isDeadlinePassed)
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <button type="button" wire:click.stop="confirmToggleStatus({{ $job->id }})"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400">
                                                        <i class="fas fa-circle-play"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Activate
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <button type="button" wire:click.stop="confirmDeleteJob({{ $job->id }})"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-red-50 text-red-600 border border-red-200 hover:bg-white hover:border-red-400">
                                                        <i class="fas fa-trash text-[10px]"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Delete
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs bg-red-50 text-red-400 border border-red-200 cursor-not-allowed">
                                                        <i class="fas fa-calendar-xmark"></i>
                                                    </span>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Update deadline to activate
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                                <div class="relative inline-flex group" data-eo-share>
                                                    <button type="button" wire:click.stop="confirmDeleteJob({{ $job->id }})"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-red-50 text-red-600 border border-red-200 hover:bg-white hover:border-red-400">
                                                        <i class="fas fa-trash text-[10px]"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Delete
                                                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                            @endif
                                        @else
                                            <div class="relative inline-flex group" data-eo-share>
                                                <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs bg-purple-50 text-purple-400 border border-purple-200 cursor-not-allowed">
                                                    <i class="fas fa-lock text-[10px]"></i>
                                                </span>
                                                <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                    Posted by Alumni Director
                                                    <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                </div>
                                            </div>
                                        @endif
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
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center {{ $filterStatus === 'ORGANIZER_DELETED' ? 'bg-red-50' : 'bg-gray-100' }}">
                    <i class="fas {{ $filterStatus === 'ORGANIZER_DELETED' ? 'fa-trash text-red-400' : 'fa-briefcase text-gray-400' }} text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-[#333333]">
                        @if($filterStatus === 'ORGANIZER_DELETED') No deleted job postings
                        @elseif($search || $filterStatus || $filterType) No jobs match your filters
                        @else No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($filterStatus === 'ORGANIZER_DELETED') Deleted jobs will appear here. You can restore them at any time.
                        @elseif($search || $filterStatus || $filterType) Try clearing your filters to see all postings.
                        @else Click the <strong>+</strong> button to post your first job listing.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterType)
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
        <div class="flex-shrink-0 border-t border-purple-800/30 px-4 flex items-center justify-between gap-2 flex-wrap min-h-[48px] py-1"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                job{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $search || $filterType)<span class="text-white/50 text-xs ml-1">(filtered)</span>@endif
            </p>

            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif
                        aria-label="Previous">
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
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif
                        aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>

</div>


{{-- ══ DELETE CONFIRM MODAL ══ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDeleteJob">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b border-red-100 bg-red-50">
            <h2 class="text-base font-semibold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-trash-can text-red-500 text-sm"></i>
                </div>
                Delete Job Posting
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">Are you sure you want to delete:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $deleteJobTitle }}
            </p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-amber-800">The job will be moved to <strong>Deleted</strong> status. You can restore it anytime from the Deleted filter.</span>
            </div>
            <div class="flex gap-2">
                {{-- CHANGE 1: Cancel button has loading spinner --}}
                <button wire:click="cancelDeleteJob"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelDeleteJob,executeDeleteJob"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelDeleteJob"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelDeleteJob"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeDeleteJob"
                        wire:loading.attr="disabled"
                        wire:target="executeDeleteJob"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-60">
                    <span wire:loading wire:target="executeDeleteJob"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeDeleteJob"><i class="fas fa-trash-can mr-1 text-xs"></i></span>
                    Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ RESTORE CONFIRM MODAL ══ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelRestoreJob">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b {{ $restoreWillActivate ? 'border-emerald-100 bg-emerald-50' : 'border-amber-100 bg-amber-50' }}">
            <h2 class="text-base font-semibold {{ $restoreWillActivate ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $restoreWillActivate ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-rotate-left {{ $restoreWillActivate ? 'text-emerald-600' : 'text-amber-600' }} text-sm"></i>
                </div>
                Restore Job Posting
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">You are about to restore:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $restoreJobTitle }}
            </p>
            @if($restoreWillActivate)
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-emerald-800">The deadline is still valid. This job will be restored and set to <strong>Active</strong> — alumni will see it immediately.</span>
            </div>
            @else
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-amber-800">The deadline has already passed. This job will be restored as <strong>Inactive</strong>. Update the deadline, then activate it manually.</span>
            </div>
            @endif
            <div class="flex gap-2">
                {{-- CHANGE 1: Cancel button has loading spinner --}}
                <button wire:click="cancelRestoreJob"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelRestoreJob,executeRestoreJob"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelRestoreJob"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelRestoreJob"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeRestoreJob"
                        wire:loading.attr="disabled"
                        wire:target="executeRestoreJob"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-60
                               {{ $restoreWillActivate ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-amber-500 hover:bg-amber-600' }}">
                    <span wire:loading wire:target="executeRestoreJob">
                        <i class="fas fa-spinner animate-spin mr-1 text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="executeRestoreJob">
                        <i class="fas fa-rotate-left mr-1 text-xs"></i>
                    </span>
                    Yes, Restore
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ ACTIVATE / DEACTIVATE CONFIRM ══ --}}
@if($showToggleModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelToggleStatus">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b {{ $toggleAction === 'activate' ? 'border-emerald-100 bg-emerald-50' : 'border-amber-100 bg-amber-50' }}">
            <h2 class="text-base font-semibold {{ $toggleAction === 'activate' ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $toggleAction === 'activate' ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas {{ $toggleAction === 'activate' ? 'fa-circle-play text-emerald-600' : 'fa-circle-pause text-amber-600' }} text-sm"></i>
                </div>
                {{ $toggleAction === 'activate' ? 'Activate Job Posting' : 'Deactivate Job Posting' }}
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">You are about to <strong>{{ $toggleAction }}</strong>:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $toggleJobTitle }}
            </p>
            @if($toggleAction === 'activate')
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-emerald-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-emerald-900">Alumni will be able to see and apply to this job posting once activated.</span>
            </div>
            @else
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-circle-info text-amber-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-amber-900">Alumni won't see this job posting until you re-activate it. No data will be lost.</span>
            </div>
            @endif
            <div class="flex gap-2">
                {{-- CHANGE 1: Cancel button has loading spinner --}}
                <button wire:click="cancelToggleStatus"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelToggleStatus,executeToggleStatus"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelToggleStatus"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelToggleStatus"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled"
                        wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-60
                               {{ $toggleAction === 'activate' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-amber-500 hover:bg-amber-600' }}">
                    <span wire:loading wire:target="executeToggleStatus">
                        <i class="fas fa-spinner animate-spin mr-1 text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="executeToggleStatus">
                        <i class="fas {{ $toggleAction === 'activate' ? 'fa-circle-play' : 'fa-circle-pause' }} mr-1 text-xs"></i>
                    </span>
                    Yes, {{ $toggleAction === 'activate' ? 'Activate' : 'Deactivate' }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ POST JOB — FULL SCREEN 3-COLUMN ══ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closePostModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] flex-shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Post a New Job</h2>
                <p class="text-white/60 text-xs mt-0.5">Fill in details — job goes live immediately</p>
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            {{-- CHANGE 1: Close (Cancel) button has loading; CHANGE 2: pure X has no loading --}}
            <button wire:click="closePostModal" type="button"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60"
                    wire:target="closePostModal,savePost"
                    class="modal-top-btn relative inline-flex items-center gap-1.5 px-3 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:pointer-events-none text-white text-xs font-semibold"
                    aria-label="Close">
                <span wire:loading wire:target="closePostModal,savePost"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                <span wire:loading.remove wire:target="closePostModal,savePost"><i class="fas fa-xmark text-xs"></i></span>
                <span wire:loading.remove wire:target="closePostModal,savePost">Cancel</span>
                <span wire:loading wire:target="closePostModal,savePost">Closing…</span>
            </button>
        </div>
    </div>

    @if(count($postErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 lg:px-10 py-2 flex-shrink-0 flex items-start gap-3">
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

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Organization + Target College + Image + Tips --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-3 space-y-3">

                {{-- Organization Category --}}
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postOrgCategory']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-building text-[9px] text-[#555555]"></i> Organization <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach([['philcst','PHILCST Campus','Internal department','fa-school'],['partner','Partner Company','Known partner organization','fa-handshake'],['custom','Other / Custom','Enter manually','fa-pen-to-square']] as [$val,$label,$sub,$ico])
                            <button type="button" wire:click="$set('postOrgCategory','{{ $val }}')"
                                    class="px-2.5 py-2 border-2 rounded-xl bg-white cursor-pointer transition text-left font-semibold flex items-center gap-2.5 text-sm
                                           {{ $postOrgCategory===$val ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 text-[#333333] hover:border-[#7a3f91] hover:bg-purple-50' }}"
                                    style="{{ $postOrgCategory===$val ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <i class="fas {{ $ico }} text-base flex-shrink-0"></i>
                                <div><span class="block text-sm">{{ $label }}</span><span class="block font-normal opacity-70 text-xs">{{ $sub }}</span></div>
                            </button>
                            @endforeach
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-red-600 flex items-center gap-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif

                        @if($postOrgCategory === 'philcst' && $philcstName)
                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-xl px-2.5 py-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-white shadow-sm" style="background:linear-gradient(135deg,#7a3f91,#6a3580);"><i class="fas fa-school text-xs"></i></div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-[#4c1d95] truncate text-sm">{{ $philcstName }}</div>
                                @if($philcstLocation)<div class="text-[#7c3aed] truncate mt-0.5 text-xs"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 font-semibold text-purple-700 bg-white border border-purple-200 px-1.5 py-0.5 rounded-full text-[0.6rem] flex-shrink-0"><i class="fas fa-lock text-[8px]"></i> Auto</span>
                        </div>
                        @endif

                        @if($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),loc:@js($postLocation),syncN(v){$wire.set('postPartnerName',v,false)},syncT(v){$wire.set('postPartnerType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncN(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postPartnerName']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncT(pType)" type="text" placeholder="e.g. Private, NGO" maxlength="100"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postPartnerType']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postLocation']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        @if($postOrgCategory === 'custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),loc:@js($postLocation),syncN(v){$wire.set('postCustomName',v,false)},syncT(v){$wire.set('postCustomType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncN(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postCustomName']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncT(cType)" type="text" placeholder="e.g. Government, NGO" maxlength="100"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postCustomType']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postLocation']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postLocation']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postLocation'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        @endif

                        @if(!$postOrgCategory)
                        <div class="text-center py-3 text-[#777777]"><p class="text-xs">Select a category above to continue.</p></div>
                        @endif
                    </div>
                </div>

                {{-- Target College --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-building-columns text-[9px] text-[#555555]"></i> Target College
                    </div>
                    <div class="p-3.5">
                        <div class="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-xl px-2.5 py-2">
                            <i class="fas fa-lock text-blue-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-blue-900 truncate text-sm">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-blue-700 mt-0.5 text-xs">Auto-selected · your alumni only</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Job Image — CHANGE 5: preview in post modal --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-image text-[9px] text-[#555555]"></i> Job Photo
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-3.5">
                        <div wire:ignore
                             x-data="{
                                 preview: null,
                                 handleFile(e) {
                                     const f = e.target.files[0];
                                     if (!f) return;
                                     const r = new FileReader();
                                     r.onload = ev => { this.preview = ev.target.result; };
                                     r.readAsDataURL(f);
                                 },
                                 clear() {
                                     this.preview = null;
                                     this.$refs.fileInput.value = '';
                                     $wire.set('postJobImage', null);
                                 }
                             }">
                            <div class="img-upload-zone" :class="preview ? 'has-image' : ''">
                                <template x-if="preview">
                                    <div class="relative">
                                        <img :src="preview" class="img-preview-thumb" alt="Preview">
                                        <button type="button" @click="clear()"
                                                class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-red-500 text-white flex items-center justify-center shadow hover:bg-red-600 transition cursor-pointer">
                                            <i class="fas fa-xmark text-[10px]"></i>
                                        </button>
                                        <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold bg-emerald-600 text-white px-1.5 py-0.5 rounded-full">PREVIEW</span>
                                    </div>
                                </template>
                                <template x-if="!preview">
                                    <label class="flex flex-col items-center justify-center gap-1.5 py-5 cursor-pointer w-full">
                                        <i class="fas fa-cloud-arrow-up text-2xl text-gray-300"></i>
                                        <p class="font-semibold text-xs text-[#555555]">Click to upload or drag &amp; drop</p>
                                        <p class="text-[10px] text-[#777777]">JPG, PNG, WebP — max 2MB</p>
                                        <input x-ref="fileInput" type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                               wire:model="postJobImage" @change="handleFile($event)">
                                    </label>
                                </template>
                            </div>
                            <div wire:loading wire:target="postJobImage" class="mt-1.5 text-xs text-[#7a3f91] flex items-center gap-2">
                                <i class="fas fa-spinner animate-spin text-xs"></i> Uploading…
                            </div>
                            @if(isset($postErrors['postJobImage']))
                                <p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobImage'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Tips --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Submission Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Set a future deadline — past deadlines auto-deactivate.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Include salary — listings with salary attract more applicants.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Job goes live immediately — no approval required.</span></li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE: Job Info + Textareas — CHANGE 4: textareas fill available height --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c flex flex-col p-3 gap-3">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Job Information
                    </div>
                    <div class="p-3.5 space-y-3">
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                                   class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postJobTitle']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                            @if(isset($postErrors['postJobTitle']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postEmpType"
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($postErrors['postEmpType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postEmpType']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Experience Level <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postExpLevel"
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($postErrors['postExpLevel']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}">{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($postErrors['postExpLevel']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Salary <span class="font-normal normal-case tracking-normal text-[#777777]">— optional</span>
                                </label>
                                <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000/mo" maxlength="100"
                                       class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="postDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($postErrors['postDeadline']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- CHANGE 4: Description with flex-1 so it stretches --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col flex-1" style="min-height:220px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Description <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <textarea wire:model.defer="postDescription"
                                  placeholder="Describe the role, responsibilities, and what the candidate will be doing…" maxlength="5000"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postDescription']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  style="min-height:120px;"></textarea>
                        @if(isset($postErrors['postDescription']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDescription'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Qualifications <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <textarea wire:model.defer="postQualifications"
                                  placeholder="e.g. Bachelor's degree in a relevant field, at least 1 year experience…" maxlength="3000"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postQualifications']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  style="min-height:100px;"></textarea>
                        @if(isset($postErrors['postQualifications']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postQualifications'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        How to Apply <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <textarea wire:model.defer="postApplicationInstructions"
                                  placeholder="e.g. Send your resume to hr@company.com with subject: Application – [Position]" maxlength="3000"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postApplicationInstructions']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  style="min-height:100px;"></textarea>
                        @if(isset($postErrors['postApplicationInstructions']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postApplicationInstructions'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT: Visibility + Actions --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto scroll-c">
            <div class="p-3 space-y-3 flex-1">
                {{-- CHANGE 5: post modal shows existing image preview too (from server) --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Default Photo Preview
                    </div>
                    <div class="p-3.5">
                        <div class="rounded-xl overflow-hidden" style="height:100px;">
                            <img src="{{ asset('storage/job/default-photo-job.jpg') }}" alt="Default job photo"
                                 class="w-full h-full object-cover"
                                 onerror="this.parentElement.style.display='none'">
                        </div>
                        <p class="text-[10px] text-[#777777] mt-1.5 text-center">Default photo shown if none uploaded</p>
                    </div>
                </div>
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Visibility
                    </div>
                    <div class="p-3.5">
                        <div class="flex items-center gap-2 bg-purple-50 border border-purple-100 rounded-xl px-2.5 py-2">
                            <i class="fas fa-users text-purple-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-purple-800 truncate text-sm">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-purple-600 mt-0.5 text-xs">Only alumni from this college can see this job</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                    <p class="font-semibold text-emerald-800 flex items-center gap-1.5 text-sm"><i class="fas fa-circle-check text-emerald-500 text-sm"></i> Ready to post</p>
                    <p class="text-emerald-700 mt-1 text-xs">Job goes live immediately after submitting. No approval required.</p>
                </div>
            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled"
                        wire:target="savePost"
                        class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <span wire:loading wire:target="savePost">
                        <i class="fas fa-spinner animate-spin text-xs"></i>
                    </span>
                    <span wire:loading.remove wire:target="savePost">
                        <i class="fas fa-paper-plane text-xs"></i>
                    </span>
                    <span wire:loading wire:target="savePost">Posting…</span>
                    <span wire:loading.remove wire:target="savePost">Post Job</span>
                </button>
                {{-- CHANGE 1 & 2: Cancel button (labeled) has loading spinner --}}
                <button type="button" wire:click="closePostModal"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="closePostModal,savePost"
                        class="w-full px-5 py-2 rounded-xl text-xs font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333] disabled:pointer-events-none flex items-center justify-center gap-1.5">
                    <span wire:loading wire:target="closePostModal,savePost"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="closePostModal,savePost"><i class="fas fa-xmark text-[10px]"></i></span>
                    <span wire:loading wire:target="closePostModal,savePost">Closing…</span>
                    <span wire:loading.remove wire:target="closePostModal,savePost">Cancel</span>
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ EDIT JOB — FULL SCREEN 3-COLUMN (CHANGE 6: view/edit mode) ══ --}}
@if($showEditModal)
@php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeEditModal()"
     x-data="{ editMode: false }">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] flex-shrink-0 shadow-lg">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-pen-to-square text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">
                    <span x-show="!editMode">View Job</span>
                    <span x-show="editMode" x-cloak>Edit Job</span>
                </h2>
                @if($editingJob)
                <p class="text-white/60 text-xs mt-0.5 truncate max-w-[260px]">{{ $editingJob->job_title }}</p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            {{-- CHANGE 6: View/Edit mode toggle button --}}
            @if($editingJob && $editingJob->status !== 'ORGANIZER_DELETED')
            <button type="button"
                    @click="editMode = !editMode"
                    class="modal-top-btn relative inline-flex items-center gap-1.5 px-3 h-8 rounded-lg cursor-pointer transition active:scale-95 border text-xs font-semibold"
                    :class="editMode ? 'bg-white/20 border-white/40 text-white' : 'bg-white text-[#7a3f91] border-white/80 hover:bg-white/90'">
                <i class="fas" :class="editMode ? 'fa-eye' : 'fa-pen'"></i>
                <span x-text="editMode ? 'View Mode' : 'Edit Mode'"></span>
            </button>
            @endif

            @if($editingJob)
                @php
                    $editJobIsActive       = $editingJob->status === 'ACTIVE';
                    $editJobIsDeleted      = $editingJob->status === 'ORGANIZER_DELETED';
                    $editJobDeadlinePassed = \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay());
                @endphp
                @if(!$editJobIsDeleted)
                    @if(!$editJobIsActive)
                        @if($editJobDeadlinePassed)
                            <div class="activate-disabled-wrap">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-not-allowed opacity-40 bg-emerald-500/10 border border-emerald-500/25">
                                    <i class="fas fa-circle-play text-emerald-300 text-sm"></i>
                                </span>
                                <span class="adtip">Update deadline to activate</span>
                            </div>
                        @else
                            <button wire:click="confirmToggleStatus({{ $editingJobId }})" type="button"
                                    class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-emerald-500/10 border border-emerald-500/25 hover:bg-emerald-500/20">
                                <i class="fas fa-circle-play text-emerald-300 text-sm"></i>
                                <span class="mtip">Activate</span>
                            </button>
                        @endif
                    @else
                        <button wire:click="confirmToggleStatus({{ $editingJobId }})" type="button"
                                class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-amber-400/12 border border-amber-400/25 hover:bg-amber-400/22">
                            <i class="fas fa-circle-pause text-amber-300 text-sm"></i>
                            <span class="mtip">Deactivate</span>
                        </button>
                    @endif
                    @php $editJobCanShare = !$editJobDeadlinePassed && $editJobIsActive; @endphp
                    @if($editJobCanShare)
                        <button wire:click="openShareModal({{ $editingJobId }})" type="button"
                                class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24">
                            <i class="fas fa-share-nodes text-white text-sm"></i>
                            <span class="mtip">Share</span>
                        </button>
                    @endif
                    @if(!$editJobIsActive)
                        <button wire:click="confirmDeleteJob({{ $editingJobId }})" type="button"
                                class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-red-500/10 border border-red-400/25 hover:bg-red-500/20">
                            <i class="fas fa-trash text-red-300 text-sm"></i>
                            <span class="mtip">Delete</span>
                        </button>
                    @endif
                @else
                    <button wire:click="confirmRestoreJob({{ $editingJobId }})" type="button"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-emerald-500/10 border border-emerald-500/25 hover:bg-emerald-500/20">
                        <i class="fas fa-rotate-left text-emerald-300 text-sm"></i>
                        <span class="mtip">Restore</span>
                    </button>
                @endif
            @endif
            {{-- CHANGE 1 & 2: Close (labeled Cancel) has loading spinner --}}
            <button wire:click="closeEditModal" type="button"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60"
                    wire:target="closeEditModal,saveEditJob"
                    class="modal-top-btn relative inline-flex items-center gap-1.5 px-3 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:pointer-events-none text-white text-xs font-semibold">
                <span wire:loading wire:target="closeEditModal,saveEditJob"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                <span wire:loading.remove wire:target="closeEditModal,saveEditJob"><i class="fas fa-xmark text-xs"></i></span>
                <span wire:loading.remove wire:target="closeEditModal,saveEditJob">Close</span>
                <span wire:loading wire:target="closeEditModal,saveEditJob">Closing…</span>
            </button>
        </div>
    </div>

    @if($editingJob && $editingJob->status === 'ORGANIZER_DELETED')
    <div class="bg-red-50 border-b border-red-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-trash text-red-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-red-800"><strong>This job has been deleted.</strong> Use the <strong>Restore</strong> button (top-right) to recover it.</p>
    </div>
    @endif
    @if($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-amber-50 border-b border-amber-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-circle-pause text-amber-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-amber-800">
            <strong>This job is currently Inactive.</strong>
            @if($editingJob && \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay()))
                The deadline has passed — update it, save, then use <strong>Activate</strong>.
            @else
                Edit details and use <strong>Activate</strong> (top-right) to go live.
            @endif
        </p>
    </div>
    @endif

    {{-- CHANGE 6: Edit mode banner --}}
    <div x-show="editMode" x-cloak class="bg-blue-50 border-b border-blue-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-pen text-blue-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-blue-800"><strong>Edit Mode:</strong> Fields are now editable. Click <strong>Save Changes</strong> when done, or switch back to View Mode.</p>
    </div>
    <div x-show="!editMode" class="bg-purple-50 border-b border-purple-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-eye text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-purple-800"><strong>View Mode:</strong> Reading job details. Click <strong>Edit Mode</strong> (top-right) to make changes.</p>
    </div>

    @if(count($editErrors))
    <div class="bg-red-50 border-b border-red-200 px-6 py-2 flex-shrink-0 flex items-start gap-3">
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

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Org Details + Job Info + Target College + Status --}}
        <div class="w-full lg:w-[290px] xl:w-[310px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-2.5 space-y-2.5">

                {{-- CHANGE 5: Job image preview on the left --}}
                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-image text-[9px] text-[#555555]"></i> Job Photo
                    </div>
                    <div class="p-3">
                        @php $viewImgUrl = $this::jobImageUrl($editingJob->job_image ?? null); @endphp
                        <div class="rounded-xl overflow-hidden" style="height:110px;">
                            <img src="{{ $viewImgUrl }}" alt="{{ $editingJob->job_title }}"
                                 class="w-full h-full object-cover"
                                 onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
                        </div>
                        <p class="text-[10px] text-[#777777] mt-1 text-center">
                            {{ $editingJob->job_image ? 'Custom photo uploaded' : 'Default photo' }}
                        </p>
                    </div>
                </div>
                @endif

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Organization Details
                    </div>
                    <div class="p-2.5 space-y-2">
                        @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp

                        {{-- CHANGE 6: View mode shows text, edit mode shows inputs --}}
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Organization Type <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editCompanyType ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <select wire:model.live="editCompanyType"
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($editErrors['editCompanyType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    <option value="">Select Organization</option>
                                    @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($editErrors['editCompanyType']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Company Name <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editCompany ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <input wire:model.defer="editCompany" type="text" maxlength="150" @if($editIsPhilcst) readonly @endif
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editCompany']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed text-[#999999]' : '' }}">
                                @if(isset($editErrors['editCompany']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editCompany'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Location <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editLocation ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <input wire:model="editLocation" type="text" maxlength="120" @if($editIsPhilcst) readonly @endif
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed text-[#999999]' : '' }}">
                                @if(isset($editErrors['editLocation']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editLocation'] }}</p>@endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Job Information
                    </div>
                    <div class="p-2.5 space-y-2">
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Job Title <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm font-semibold">{{ $editJobTitle ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <input wire:model.defer="editJobTitle" type="text" maxlength="200"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editJobTitle']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($editErrors['editJobTitle']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Employment Type <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editEmpType ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <select wire:model.defer="editEmpType"
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($editErrors['editEmpType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    <option value="">Select Type</option>
                                    @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}" @selected($editEmpType === $opt->label)>{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if(isset($editErrors['editEmpType']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Experience Level <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editExpLevel ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <select wire:model.defer="editExpLevel"
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($editErrors['editExpLevel']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    <option value="">Select Level</option>
                                    @foreach($this->orderedExpLevels as $lvl)
                                        <option value="{{ $lvl }}" @selected($editExpLevel === $lvl)>{{ $lvl }}</option>
                                    @endforeach
                                </select>
                                @if(isset($editErrors['editExpLevel']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Salary <span class="font-normal normal-case tracking-normal text-[#777777] text-[10px]">optional</span>
                                </label>
                                <div x-show="!editMode" class="view-field-display text-sm">{{ $editSalary ?: 'Not disclosed' }}</div>
                                <div x-show="editMode" x-cloak>
                                    <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25k/mo"
                                           class="w-full px-3 py-2 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">Deadline <span x-show="editMode" x-cloak class="text-red-500">*</span></label>
                                <div x-show="!editMode" class="view-field-display text-sm">
                                    @if($editDeadline)
                                        {{ \Carbon\Carbon::parse($editDeadline)->setTimezone('Asia/Manila')->format('M d, Y') }}
                                    @else —
                                    @endif
                                </div>
                                <div x-show="editMode" x-cloak>
                                    <input wire:model.defer="editDeadline" type="date"
                                           min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($editErrors['editDeadline']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Target College
                    </div>
                    <div class="p-2.5">
                        <div class="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-xl px-2.5 py-2">
                            <i class="fas fa-lock text-blue-500 flex-shrink-0 text-sm"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-blue-900 truncate text-sm">{{ $this->organizerCollege ?? 'Your College' }}</div>
                                <div class="text-blue-700 mt-0.5 text-xs">Auto-selected · your alumni only</div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($editingJob)
                @php
                    $statusColor = match($editingJob->status) {
                        'ACTIVE'            => ['bg-emerald-50 border-emerald-200', 'text-emerald-900', 'fa-circle-check text-emerald-500', 'text-emerald-700', 'Currently Active'],
                        'INACTIVE'          => ['bg-amber-50 border-amber-200',   'text-amber-900',   'fa-circle-pause text-amber-500',   'text-amber-700',   'Currently Inactive'],
                        'ORGANIZER_DELETED' => ['bg-red-50 border-red-200',       'text-red-900',     'fa-trash text-red-500',            'text-red-700',     'Deleted'],
                        default             => ['bg-gray-50 border-gray-200',     'text-gray-900',    'fa-circle text-gray-500',          'text-gray-700',    $editingJob->status],
                    };
                @endphp
                <div class="rounded-xl px-3 py-2 border {{ $statusColor[0] }}">
                    <p class="font-semibold flex items-center gap-1.5 text-sm {{ $statusColor[1] }}">
                        <i class="fas {{ $statusColor[2] }} text-sm"></i> {{ $statusColor[4] }}
                    </p>
                    <p class="text-xs mt-0.5 {{ $statusColor[3] }}">
                        @if($editingJob->status === 'ORGANIZER_DELETED') Use <strong>Restore</strong> in the top-right to recover this job.
                        @elseif($editingJob->status === 'INACTIVE' && $editJobDeadlinePassed) Update the deadline above first, save, then use <strong>Activate</strong>.
                        @else Use the {{ $editingJob->status === 'ACTIVE' ? 'Deactivate' : 'Activate' }} button in the top-right to toggle.
                        @endif
                    </p>
                </div>
                @endif

            </div>
        </div>

        {{-- MIDDLE: Textareas — CHANGE 4 & 6: tall + view/edit mode --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c flex flex-col p-3 gap-3">

                {{-- Description --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col flex-1" style="min-height:220px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Description <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        {{-- View mode: formatted text display --}}
                        <div x-show="!editMode"
                             class="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:160px;">{{ $editDescription ?: 'No description provided.' }}</div>
                        {{-- Edit mode: textarea --}}
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editDescription"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editDescription']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="Describe the role, responsibilities…" maxlength="5000"
                                  style="min-height:160px;"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                </div>

                {{-- Qualifications --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Qualifications <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:120px;">{{ $editQualifications ?: 'No qualifications listed.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editQualifications"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editQualifications']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="e.g. Bachelor's degree in relevant field…" maxlength="3000"
                                  style="min-height:120px;"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>
                </div>

                {{-- How to Apply --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        How to Apply <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="flex-1 px-3 py-2 bg-emerald-50/50 border border-emerald-100 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:120px;">{{ $editApplicationInstructions ?: 'No application instructions provided.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editApplicationInstructions"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editApplicationInstructions']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="e.g. Send your resume to hr@company.com…" maxlength="3000"
                                  style="min-height:120px;"></textarea>
                        @if(isset($editErrors['editApplicationInstructions']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editApplicationInstructions'] }}</p>@endif
                    </div>
                </div>

            </div>
        </div>

        {{-- RIGHT: Photo + History + Tips + Actions --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto scroll-c">
            <div class="p-3 space-y-3 flex-1">

                {{-- CHANGE 6 + CHANGE 5: Photo uploader shown only in edit mode; view mode shows preview only --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Job Photo
                        <span x-show="editMode" x-cloak class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-3">
                        {{-- View mode: just show the current image --}}
                        <div x-show="!editMode">
                            @php $editViewImgUrl = $editingJob ? $this::jobImageUrl($editingJob->job_image ?? null) : asset('storage/job/default-photo-job.jpg'); @endphp
                            <div class="rounded-xl overflow-hidden" style="height:110px;">
                                <img src="{{ $editViewImgUrl }}" alt="Job photo"
                                     class="w-full h-full object-cover"
                                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
                            </div>
                            <p class="text-[10px] text-[#777777] mt-1.5 text-center">
                                {{ ($editingJob && $editingJob->job_image) ? 'Custom photo uploaded' : 'Default photo' }}
                            </p>
                        </div>

                        {{-- Edit mode: uploader --}}
                        <div x-show="editMode" x-cloak>
                        <div wire:ignore
                             x-data="{
                                 preview: null,
                                 existing: @js($editCurrentImage ? Storage::url($editCurrentImage) : ''),
                                 removed: false,
                                 handleFile(e) {
                                     const f = e.target.files[0];
                                     if (!f) return;
                                     this.removed = false;
                                     const r = new FileReader();
                                     r.onload = ev => { this.preview = ev.target.result; };
                                     r.readAsDataURL(f);
                                 },
                                 clearNew() {
                                     this.preview = null;
                                     this.$refs.fileInput.value = '';
                                     $wire.set('editJobImage', null);
                                 },
                                 removeExisting() {
                                     this.existing = '';
                                     this.preview  = null;
                                     this.removed  = true;
                                     $wire.set('editRemoveImage', true);
                                     if (this.$refs.fileInput) this.$refs.fileInput.value = '';
                                     $wire.set('editJobImage', null);
                                 }
                             }">

                            <template x-if="preview">
                                <div class="relative mb-2">
                                    <img :src="preview" class="img-preview-thumb-sm" alt="New Preview">
                                    <button type="button" @click="clearNew()"
                                            class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-red-500 text-white flex items-center justify-center shadow hover:bg-red-600 transition cursor-pointer">
                                        <i class="fas fa-xmark text-[10px]"></i>
                                    </button>
                                    <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold bg-emerald-600 text-white px-1.5 py-0.5 rounded-full">NEW</span>
                                </div>
                            </template>

                            <template x-if="!preview && existing && !removed">
                                <div class="relative mb-2">
                                    <img :src="existing" class="img-preview-thumb-sm" alt="Current"
                                         onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
                                    <button type="button" @click="removeExisting()"
                                            class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-red-500 text-white flex items-center justify-center shadow hover:bg-red-600 transition cursor-pointer">
                                        <i class="fas fa-xmark text-[10px]"></i>
                                    </button>
                                    <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold bg-[#7a3f91] text-white px-1.5 py-0.5 rounded-full">CURRENT</span>
                                </div>
                            </template>

                            <template x-if="!preview && (!existing || removed)">
                                <div>
                                    <div class="img-upload-zone">
                                        <label class="flex flex-col items-center justify-center gap-1 py-4 cursor-pointer w-full">
                                            <i class="fas fa-cloud-arrow-up text-2xl text-gray-300"></i>
                                            <p class="font-semibold text-xs text-[#555555]">Upload photo</p>
                                            <p class="text-[10px] text-[#777777]">JPG, PNG, WebP · max 2MB</p>
                                            <input x-ref="fileInput" type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                                   wire:model="editJobImage" @change="handleFile($event)">
                                        </label>
                                    </div>
                                    <template x-if="removed">
                                        <p class="text-[10px] text-amber-600 mt-1 flex items-center gap-1">
                                            <i class="fas fa-triangle-exclamation text-[9px]"></i>
                                            Image removed — will use default on save.
                                        </p>
                                    </template>
                                </div>
                            </template>

                            <template x-if="!preview && existing && !removed">
                                <label class="flex items-center gap-1.5 mt-1.5 cursor-pointer text-[10px] text-[#7a3f91] font-semibold hover:underline">
                                    <i class="fas fa-arrow-up-from-bracket text-[9px]"></i>
                                    Replace photo
                                    <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                           wire:model="editJobImage" @change="handleFile($event)">
                                </label>
                            </template>

                            @if(isset($editErrors['editJobImage']))
                                <p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editJobImage'] }}</p>
                            @endif
                        </div>
                        </div>
                    </div>
                </div>

                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Job History
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-[#555555]">Created</p>
                            <p class="text-sm text-[#333333]">{{ \Carbon\Carbon::parse($editingJob->created_at)->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</p>
                        </div>
                        @if($editingJob->updated_by)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-[#555555]">Last Updated By</p>
                            <p class="text-sm text-[#333333]">{{ $editingJob->updated_by }}</p>
                        </div>
                        @endif
                        @if($editingJob->deleted_by)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-red-500">Deleted By</p>
                            <p class="text-sm text-red-700">{{ $editingJob->deleted_by }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-[#555555]">Deadline</p>
                            <p class="text-sm text-[#333333]">{{ \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->format('M d, Y') }}</p>
                        </div>
                    </div>
                </div>
                @endif

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Changes are saved immediately — no approval required.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>If inactive with past deadline, update deadline first then activate.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Deleted jobs can be restored anytime from the Deleted filter.</span></li>
                        </ul>
                    </div>
                </div>

            </div>

            {{-- CHANGE 6: Save Changes only visible in edit mode --}}
            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                @if($editingJob && $editingJob->status !== 'ORGANIZER_DELETED')
                <div x-show="editMode" x-cloak>
                    <button type="button" wire:click="saveEditJob"
                            wire:loading.attr="disabled"
                            wire:target="saveEditJob"
                            class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <span wire:loading wire:target="saveEditJob">
                            <i class="fas fa-spinner animate-spin text-xs"></i>
                        </span>
                        <span wire:loading.remove wire:target="saveEditJob">
                            <i class="fas fa-floppy-disk text-xs"></i>
                        </span>
                        <span wire:loading wire:target="saveEditJob">Saving…</span>
                        <span wire:loading.remove wire:target="saveEditJob">Save Changes</span>
                    </button>
                </div>
                <div x-show="!editMode" class="pb-1">
                    <p class="text-center text-xs text-[#777777]">Switch to <strong>Edit Mode</strong> to make changes.</p>
                </div>
                @else
                <button type="button" wire:click="confirmRestoreJob({{ $editingJobId }})"
                        class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md cursor-pointer bg-emerald-600 hover:bg-emerald-700">
                    <i class="fas fa-rotate-left text-xs"></i> Restore Job
                </button>
                @endif
                {{-- CHANGE 1 & 2: labeled Close has loading --}}
                <button type="button" wire:click="closeEditModal"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="closeEditModal,saveEditJob"
                        class="w-full px-5 py-2 rounded-xl text-xs font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333] disabled:pointer-events-none flex items-center justify-center gap-1.5">
                    <span wire:loading wire:target="closeEditModal,saveEditJob"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="closeEditModal,saveEditJob"><i class="fas fa-xmark text-[10px]"></i></span>
                    <span wire:loading wire:target="closeEditModal,saveEditJob">Closing…</span>
                    <span wire:loading.remove wire:target="closeEditModal,saveEditJob">Close</span>
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ VIEW JOB — FULL SCREEN 2-COLUMN (Alumni Director only) ══ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job              = $this->viewingJob;
    $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft         = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
    $isExp            = now('Asia/Manila')->startOfDay()->gt($dl->copy()->startOfDay());
    $isUrgentView     = $daysLeft <= 7 && !$isExp;
    $createdPH        = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType      = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $isAlumniDirector = is_null($job->organizer_id);
    $isActiveView     = $job->status === 'ACTIVE';
    $viewCanShare     = !$isExp && $isActiveView;
    $viewJobImgUrl    = $this::jobImageUrl($job->job_image ?? null);
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <div class="min-w-0">
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details — Alumni Director Post</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $job->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($viewCanShare)
                <button type="button" wire:click="openShareModal({{ $job->id }})"
                        class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24">
                    <i class="fas fa-share-nodes text-white text-sm"></i>
                    <span class="mtip">Share</span>
                </button>
            @endif
            {{-- CHANGE 2: pure icon-only close has NO loading --}}
            <button wire:click="closeViewModal" type="button"
                    class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22">
                <i class="fas fa-xmark text-white text-sm"></i>
                <span class="mtip">Close</span>
            </button>
        </div>
    </div>

    <div class="bg-purple-50 border-b border-purple-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-purple-800 font-semibold">This job was posted by an <strong>Alumni Director</strong>. View only — editing is not available.</p>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c">

            {{-- CHANGE 5: Job image shown prominently in view modal --}}
            <div class="mx-3 mt-3 mb-0 flex-shrink-0 rounded-xl overflow-hidden" style="height:160px;">
                <img src="{{ $viewJobImgUrl }}" alt="{{ $job->job_title }}" class="w-full h-full object-cover"
                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
            </div>

            <div class="mx-3 mt-2 mb-2 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-between px-4 py-2"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="flex items-center gap-2">
                    @if($isActiveView)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/80 text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Active</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/80 text-white text-xs font-semibold"><i class="fas fa-circle-pause text-[9px]"></i> Inactive</span>
                    @endif
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-purple-300/40 text-white text-xs font-semibold"><i class="fas fa-shield-halved text-[9px]"></i> Alumni Director</span>
                </div>
                <i class="fas fa-briefcase text-white/20 text-2xl"></i>
            </div>

            <div class="flex flex-col gap-2 px-3 pb-3">
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-blue-100"><i class="fas fa-clock text-blue-600 text-base"></i></span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#555555] mb-0.5">Employment Type</p>
                        <p class="font-bold text-sm text-[#333333]">{{ $job->employment_type }}</p>
                        <p class="text-sm text-[#333333] mt-0.5">{{ $job->experience_level }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-violet-100"><i class="fas fa-building text-violet-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#555555] mb-0.5">Company</p>
                        <p class="font-bold text-sm text-[#333333] truncate">{{ $job->company_name }}</p>
                        <p class="text-sm text-[#333333] truncate mt-0.5">{{ $displayType }}</p>
                    </div>
                </div>
                @if($job->location)
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-rose-100"><i class="fas fa-location-dot text-rose-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#555555] mb-0.5">Location</p>
                        <p class="font-bold text-sm text-[#333333] truncate">{{ $job->location }}</p>
                    </div>
                </div>
                @endif
                @if($job->salary)
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-emerald-100"><i class="fas fa-money-bill-wave text-emerald-600 text-base"></i></span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#555555] mb-0.5">Salary</p>
                        <p class="font-bold text-sm text-[#333333]">{{ $job->salary }}</p>
                    </div>
                </div>
                @endif
                <div class="flex items-center gap-3 p-3 rounded-xl border {{ $isExp ? 'bg-red-50 border-red-200' : ($isUrgentView ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-100') }}">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 {{ $isExp ? 'bg-red-100' : ($isUrgentView ? 'bg-amber-100' : 'bg-blue-100') }}">
                        <i class="fas fa-calendar-xmark text-base {{ $isExp ? 'text-red-600' : ($isUrgentView ? 'text-amber-600' : 'text-blue-600') }}"></i>
                    </span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider mb-0.5 {{ $isExp ? 'text-red-500' : ($isUrgentView ? 'text-amber-600' : 'text-[#555555]') }}">Deadline</p>
                        <p class="font-bold text-sm {{ $isExp ? 'text-red-700' : ($isUrgentView ? 'text-amber-700' : 'text-[#333333]') }}">{{ $dl->format('F d, Y') }}</p>
                        <p class="text-xs mt-0.5 {{ $isExp ? 'text-red-600 font-semibold' : ($isUrgentView ? 'text-amber-600' : 'text-[#555555]') }}">
                            @if($isExp) <i class="fas fa-ban text-[9px] mr-0.5"></i>No longer accepting
                            @elseif($daysLeft === 0) Closing today!
                            @elseif($daysLeft === 1) Closes tomorrow
                            @else {{ $daysLeft }} days remaining
                            @endif
                        </p>
                    </div>
                </div>
                @if($job->target_college)
                <div class="p-3 rounded-xl bg-purple-50 border border-purple-100">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-purple-600 mb-1.5">Target College</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $job->target_college) as $col)
                            <span class="inline-flex items-center font-semibold px-2 py-1 rounded-lg bg-white text-purple-700 border border-purple-200 text-xs">{{ trim($col) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
                <p class="text-center text-xs text-[#777777]">Posted {{ $createdPH->diffForHumans() }} · {{ $createdPH->format('M d, Y g:i A') }}</p>
            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">
            <div class="flex-shrink-0 px-4 py-2.5 bg-white border-b border-gray-200">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700"><i class="fas fa-clock text-[10px]"></i> {{ $job->employment_type }}</span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200 text-[#333333]"><i class="fas fa-layer-group text-[10px]"></i> {{ $job->experience_level }}</span>
                    @if($isExp)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-red-50 border border-red-200 text-red-600"><i class="fas fa-ban text-[10px]"></i> No longer accepting applications</span>
                    @elseif($isUrgentView)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-50 border border-amber-200 text-amber-700"><i class="fas fa-fire text-[10px]"></i> {{ $daysLeft === 0 ? 'Closing today!' : ($daysLeft === 1 ? 'Closes tomorrow' : $daysLeft.' days remaining') }}</span>
                    @endif
                </div>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c px-4 py-3 flex flex-col gap-3">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="font-bold mb-2 flex items-center gap-2 uppercase tracking-widest text-[10px] text-[#333333]">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-blue-50"><i class="fas fa-file-lines text-blue-500 text-[10px]"></i></span> Job Description
                    </h3>
                    <div class="leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-3 border border-gray-100 text-sm text-[#333333]" style="line-height:1.7;">{{ trim($job->description) }}</div>
                </div>
                @if($job->qualifications)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="font-bold mb-2 flex items-center gap-2 uppercase tracking-widest text-[10px] text-[#333333]">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-purple-50"><i class="fas fa-list-check text-purple-500 text-[10px]"></i></span> Qualifications
                    </h3>
                    <div class="leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-lg p-3 border border-gray-100 text-sm text-[#333333]" style="line-height:1.7;">{{ trim($job->qualifications) }}</div>
                </div>
                @endif
                @if($job->application_instructions)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="font-bold mb-2 flex items-center gap-2 uppercase tracking-widest text-[10px] text-[#333333]">
                        <span class="w-5 h-5 rounded-md flex items-center justify-center bg-emerald-50"><i class="fas fa-paper-plane text-emerald-500 text-[10px]"></i></span> How to Apply
                    </h3>
                    <div class="leading-relaxed whitespace-pre-wrap bg-emerald-50/50 rounded-lg p-3 border border-emerald-100 text-sm text-[#333333]" style="line-height:1.7;">{{ trim($job->application_instructions) }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ SHARE MODAL ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobsBaseUrl();
    $shareHost        = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $shareDlFormatted = $shareDeadline ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y') : '';
    $shareDescPreview = mb_strlen($shareDescription) > 160 ? mb_substr($shareDescription, 0, 160) . '…' : $shareDescription;
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
         close() { this.open = false; setTimeout(() => $wire.closeShareModal(), 250); },
         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); }
                 else { const ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.focus();ta.select();document.execCommand('copy');document.body.removeChild(ta); }
             } catch(e) {}
         },
         async shareOnFacebook() {
             await this.copyText(this.fbText); this.fbCopied=true;
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(this.baseUrl),'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             setTimeout(()=>{this.fbCopied=false;},7000);
         },
         async shareOnMessenger() {
             await this.copyText(this.fbText); this.messengerCopied=true;
             const isMobile=/Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
             if (isMobile) { window.location.href='fb-messenger://share/?link='+encodeURIComponent(this.baseUrl); setTimeout(()=>window.open('https://www.messenger.com/','_blank','noopener'),1500); }
             else { window.open('https://www.messenger.com/','_blank','noopener'); }
             setTimeout(()=>{this.messengerCopied=false;},7000);
         },
         async copyLinkFn() { await this.copyText(this.baseUrl); this.copied=true; setTimeout(()=>this.copied=false,2500); }
     }"
     x-init="requestAnimationFrame(() => { open = true })"
     @keydown.escape.window="close()">
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="close()"></div>
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-250" x-transition:enter-start="opacity-0 scale-95 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-4"
         class="relative w-full max-w-5xl bg-white shadow-2xl flex flex-col rounded-2xl overflow-hidden will-change-transform" style="max-height: 90vh;">
        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5 text-[#333333]"><i class="fas fa-share-nodes text-sky-600 text-sm"></i><span>Share Job Posting</span></h2>
            {{-- CHANGE 2: pure X no loading --}}
            <button @click="close()" type="button" class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]"><i class="fas fa-xmark text-base"></i></button>
        </div>
        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto scroll-c">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#333333]">Post preview</p>
                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4 bg-[#f9f7fc]">
                        <p class="font-semibold text-base leading-tight text-[#333333]">{{ $shareJobTitle }}</p>
                        <p class="text-sm mt-1 font-semibold text-[#555555]">{{ $shareCompany }}</p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @if($shareEmpType)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700"><i class="fas fa-clock text-[10px]"></i>{{ $shareEmpType }}</span>@endif
                            @if($shareLocation)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-[#333333]"><i class="fas fa-location-dot text-[10px]"></i>{{ $shareLocation }}</span>@endif
                            @if($shareExpLevel)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-700"><i class="fas fa-layer-group text-[10px]"></i>{{ $shareExpLevel }}</span>@endif
                            @if($shareSalary)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-emerald-50 text-emerald-700"><i class="fas fa-money-bill-wave text-[10px]"></i>{{ $shareSalary }}</span>@endif
                            @if($shareDlFormatted)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-red-50 text-red-600"><i class="fas fa-calendar-xmark text-[10px]"></i>Deadline: {{ $shareDlFormatted }}</span>@endif
                            @if($shareCollege)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-50 text-blue-700"><i class="fas fa-building-columns text-[10px]"></i>{{ $shareCollege }}</span>@endif
                        </div>
                    </div>
                    @if($shareDescPreview)<div class="px-5 py-3.5 border-b border-gray-100"><p class="text-sm leading-relaxed text-[#333333]">{{ $shareDescPreview }}</p></div>@endif
                    <div class="px-5 py-2 flex items-center gap-2 bg-[#f9f7fc]"><i class="fas fa-globe text-xs text-[#555555]"></i><span class="text-xs uppercase tracking-wider font-semibold text-[#333333]">{{ strtoupper($shareHost) }}</span></div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div><p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p><p class="text-sm text-blue-700 leading-relaxed">Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the full job caption to your clipboard and opens the platform. Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.</p></div>
                </div>
                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div><p class="text-sm font-semibold text-[#5e2f72]">Post to Batch Chats</p><p class="text-sm mt-0.5 text-purple-700">Sends the job caption directly to all batch chat rooms for <strong>{{ $shareCollege ?: ($this->organizerCollege ?: 'your college') }}</strong>.</p></div>
                </div>
            </div>
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto scroll-c">
                <p class="text-xs font-bold uppercase tracking-widest text-[#333333]">Share via</p>
                <div x-show="fbCopied" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2"><i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i><div><p class="text-sm font-semibold text-emerald-800">Share dialog opened!</p><p class="text-xs text-emerald-700 mt-0.5">Press Ctrl+V in the post to paste the caption.</p></div></div>
                <div x-show="messengerCopied" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2"><i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i><div><p class="text-sm font-semibold text-blue-800">Messenger opened!</p><p class="text-xs text-blue-700 mt-0.5">Press Ctrl+V in chat to paste the caption.</p></div></div>
                <button type="button" @click="shareOnFacebook()" class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group"><span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg></span><span class="flex-1 text-left"><span class="block font-semibold text-sm">Share on Facebook</span><span class="block text-xs text-white/70 mt-0.5">Opens share dialog · caption copied</span></span><i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i></button>
                <button type="button" @click="shareOnMessenger()" class="w-full flex items-center gap-4 px-4 py-3.5 rounded-xl text-white font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group" style="background:linear-gradient(to right,#00B2FF,#006AFF);"><span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5"><defs><linearGradient id="mgr_org_jb" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs><path fill="url(#mgr_org_jb)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/></svg></span><span class="flex-1 text-left"><span class="block font-semibold text-sm">Send via Messenger</span><span class="block text-xs text-white/70 mt-0.5">Opens Messenger · caption copied</span></span><i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i></button>
                <div class="relative my-0.5"><div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div><div class="relative flex justify-center"><span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or post directly</span></div></div>
                <button type="button" wire:click="shareToAlumniChats"
                        wire:loading.attr="disabled"
                        wire:target="shareToAlumniChats"
                        class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-[#d4aaeb] hover:border-[#7a3f91] hover:bg-[#ede4f5] disabled:opacity-60 disabled:cursor-not-allowed bg-[#f5eef9] text-[#5e2f72]">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-[#7a3f91]"><i class="fas fa-users text-white text-sm"></i></span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="shareToAlumniChats" class="block font-semibold text-sm">Post to Batch Chats</span>
                        <span wire:loading wire:target="shareToAlumniChats" class="block font-semibold text-sm"><i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…</span>
                        <span class="block text-xs mt-0.5 text-[#7a3f91]">Sends to all batch rooms in your college</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-[#7a3f91]"></i>
                </button>
                <div class="relative my-0.5"><div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div><div class="relative flex justify-center"><span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or copy link</span></div></div>
                <button type="button" @click="copyLinkFn()" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200 hover:border-gray-300 hover:bg-gray-50 font-semibold text-sm transition cursor-pointer group bg-white text-[#333333]"><span class="w-9 h-9 bg-gray-100 group-hover:bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0 transition"><i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-[#555555]'" class="text-base"></i></span><div class="flex-1 text-left min-w-0"><p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : ''" x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p><p class="text-xs font-mono mt-0.5 truncate text-[#555555]">{{ $shareBaseUrl }}</p></div></button>
                {{-- CHANGE 1: Cancel button in share modal has loading --}}
                <button type="button" @click="close()"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#333333] flex items-center justify-center gap-1.5">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
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
                var shareWrap = e.target.closest('[data-eo-share]');
                if (shareWrap) {
                    tip.style.opacity = '0';
                    return;
                }
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

        document.querySelectorAll('[data-eo-share]').forEach(function (sw) {
            if (sw._eoShareBound) return;
            sw._eoShareBound = true;
            sw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);
})();
</script>

</div>