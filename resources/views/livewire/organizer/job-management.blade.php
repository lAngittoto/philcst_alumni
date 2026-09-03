{{-- resources/views/livewire/organizer/organizer-job-management.blade.php --}}

<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\AuditLog;
use App\Http\Controllers\JobController;
use App\Http\Controllers\AlumniNotificationController;
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
    public string $postQualifications                = '';
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
    public string $sharePhotoUrl    = '';

    public array $shareAvailableRooms = [];   // [['id'=>, 'label'=>, 'type'=>, 'department'=>], ...]
    public array $shareTargetRoomIds  = [];   // checkbox-bound selected room ids (as strings)
    public array $shareAutoRoomIds    = [];   // room ids auto-checked because they match the job's target college(s)
    public array $shareTargetCollegesList = []; // parsed target_college list used for the "Auto-selected for" banner

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

    // ─────────────────────────────────────────────────────────────────────
    // NOTIFY ADMIN — real-time, same request as the job insert.
    //
    // Mirrors the director side's writeAdminNotif() (manage-job_blade.php)
    // so an organizer's job post shows up in the Admin bell at the exact
    // moment it's saved — not several minutes later from a separate
    // poller.
    //
    // admin_notifications is a single GLOBAL feed shared by every admin
    // account — there is no user_id column on this table (per the
    // migration: id, icon, title, message, link_route, link_label,
    // dedup_key, read, read_at, timestamps only). So this writes one
    // row that every admin sees, with no per-user resolution needed.
    // ─────────────────────────────────────────────────────────────────────
    private function writeAdminNotif(string $dedupKey, string $notifTitle, string $message): void
    {
        try {
            $exists = DB::table('admin_notifications')
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) {
                DB::table('admin_notifications')
                    ->where('dedup_key', $dedupKey)
                    ->update([
                        'title'      => $notifTitle,
                        'message'    => $message,
                        'read'       => 0,
                        'read_at'    => null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('admin_notifications')->insert([
                    'icon'        => 'briefcase',
                    'title'       => $notifTitle,
                    'message'     => $message,
                    'link_route'  => 'admin.job/posts',
                    'link_label'  => 'View Jobs',
                    'dedup_key'   => $dedupKey,
                    'read'        => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            $this->dispatch('admin-notif-refresh');
        } catch (\Throwable) {
            // Non-critical — don't break the main action if this fails
        }
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

        // ── Deep-link: arriving from a "View Post" job card shared in chat ──
        //    (organizer/chat-alumni.blade.php's [[JOB:id]] preview card links
        //    here with ?highlight_job=ID). If the ID is valid, viewJob() opens
        //    automatically for that exact job — same as clicking its row.
        //    viewJob() itself already decides View (read-only, ACTIVE jobs)
        //    vs Edit (INACTIVE jobs) — no extra branching needed here.
        $highlightId = (int) request()->query('highlight_job', 0);
        if ($highlightId > 0) {
            try {
                $this->viewJob($highlightId);
            } catch (\Throwable $e) {
                // Invalid id, not owned, or deleted — silently ignore and
                // land on the plain table instead of a 500/abort.
            }
        }
    }

    // ── Deep-link (same-page case): fired by sidebar-organizer_blade.php's
    //    notification click handler when the organizer is ALREADY on Job
    //    Management and clicks a job-related notification. Skips the
    //    URL/reload path entirely and opens View/Edit Details straight
    //    away — same treatment as 'open-view-event' on the events page. ──
    #[On('open-view-job')]
    public function openViewJobEvent(int $id): void
    {
        try {
            $this->viewJob($id);
        } catch (\Throwable $e) {
            // Invalid id, not owned, or deleted — silently ignore.
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

        // Deleted (permanently removed) job postings are never shown in this
        // table, regardless of which status filter is selected. The row stays
        // in the database (status = ORGANIZER_DELETED) for audit-trail
        // purposes only — it is simply excluded from every query here.
        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        } else {
            $q->whereIn('status', ['ACTIVE', 'INACTIVE']);
        }
        $q->where('status', '!=', 'ORGANIZER_DELETED');

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

    // ── Live-refreshed copy of the job currently open in the Edit modal.
    //    Used so that after Activate/Deactivate (which happens while the
    //    modal stays open) the "editMode" state and header controls update
    //    immediately without needing to close/reopen the modal. ──
    #[Computed]
    public function editingJobFresh(): ?JobPosting
    {
        if (!$this->editingJobId) return null;
        return JobPosting::find($this->editingJobId);
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
    $this->postTargetColleges = !empty($this->organizerCollege) ? [$this->organizerCollege] : [];
    $this->showPostModal      = true;
    $this->dispatch('close-sidebar');
}

public function closePostModal(): void
{
    $this->showPostModal = false;
    $this->resetPostFields();
    $this->dispatch('open-sidebar');
}
    public function savePost(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('save_post', 5, 60)) return;

        $this->postErrors = [];
        $errors = [];

        if (!trim($this->postJobTitle))    $errors['postJobTitle']    = 'Job title is required.';
        if (!trim($this->postOrgCategory)) $errors['postOrgCategory'] = 'Please select an employer category.';

        if ($this->postOrgCategory === 'partner') {
            if (!trim($this->postPartnerName)) $errors['postPartnerName'] = 'Company name is required.';
            if (!trim($this->postPartnerType)) $errors['postPartnerType'] = 'Industry is required.';
            if (!trim($this->postLocation))    $errors['postLocation']    = 'Location is required.';
        }
        if ($this->postOrgCategory === 'custom') {
            if (!trim($this->postCustomName)) $errors['postCustomName'] = 'Company name is required.';
            if (!trim($this->postCustomType)) $errors['postCustomType'] = 'Industry is required.';
            if (!trim($this->postLocation))   $errors['postLocation']   = 'Location is required.';
        }

        if (!trim($this->postEmpType))  $errors['postEmpType']  = 'Employment type is required.';
        if (!trim($this->postExpLevel)) $errors['postExpLevel'] = 'Experience level is required.';

        // Salary stays optional, but if the organizer types SOMETHING in
        // it, it has to actually contain a number — free text like "doy"
        // or "negotiable" alone isn't a salary. ₱/$ signs, commas, "/mo",
        // "yearly", ranges, etc. are all still fine as long as at least
        // one digit is present somewhere in the value.
        if (trim($this->postSalary) !== '' && !preg_match('/\d/', $this->postSalary)) {
            $errors['postSalary'] = 'Please include a numeric amount in the salary field (for example, ₱25,000 per month).';
        }

        if (!trim($this->postDeadline)) {
            $errors['postDeadline'] = 'Deadline is required.';
        } else {
            // Deadline must be a future date — today itself is rejected
            // too, since a job whose deadline is "today" is effectively
            // useless (it would need to auto-deactivate the same day).
            $deadlineDay = \Carbon\Carbon::createFromFormat('Y-m-d', $this->postDeadline, 'Asia/Manila')->startOfDay();
            $todayDay    = now('Asia/Manila')->startOfDay();
            if ($deadlineDay->lte($todayDay)) {
                $errors['postDeadline'] = 'Deadline must be a future date — today or earlier is not allowed.';
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

        if (!empty($errors)) {
            $this->postErrors = $errors;
            // Errors already render inline next to each field, but when the
            // form is long the failing field(s) can be scrolled out of view
            // — the person only sees the summary banner up top and has to
            // hunt for what's actually wrong. Scroll the first invalid
            // field into view so it's immediately visible.
            $this->dispatch('scroll-to-first-error');
            return;
        }

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
            $this->postErrors['postJobTitle'] = 'A job posting with this title, company, and employment type already exists.';
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

        // ── NOTIFY ALUMNI (server-side, direct DB insert) ───────────────────
        app(AlumniNotificationController::class)->notifyAlumniOfNewJob($job);

        // ── NOTIFY SELF (organizer sidebar notif — grouped ×N per day client-side) ──
        $this->dispatch('job-self-action', action: 'created', id: $job->id, title: $job->job_title);

        // ── NOTIFY ADMIN — real-time, written in this same request so the
        //    Admin bell timestamp matches the jobs table "posted X ago"
        //    exactly. dedup_key uses job-posted:: to match the format the
        //    Admin sidebar's client-side grouping already expects (one
        //    row per job, never collapsed). ──
        $this->writeAdminNotif(
            dedupKey:   'job-posted::' . $job->id,
            notifTitle: 'New Job Posting',
            message:    $job->job_title
                        . ($job->company_name ? ' at ' . $job->company_name : '')
                        . ' — Posted by: ' . $this->organizerName(),
        );

    $this->dispatch('flash-message', type: 'success', message: 'Job posting created successfully!');
        $this->showPostModal = false;
        $this->resetPostFields();
        $this->dispatch('open-sidebar');
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
        return;
    }

    if (is_null($job->organizer_id)) {
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
        $this->dispatch('close-sidebar');
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
    $this->dispatch('close-sidebar');
}
   public function closeViewModal(): void
{
    $this->showViewModal = false;
    $this->viewingJobId  = null;
    $this->dispatch('open-sidebar');
}
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
    $this->dispatch('close-sidebar');
}

  public function closeEditModal(): void
{
    $this->showEditModal = false;
    $this->resetEditFields();
    $this->dispatch('open-sidebar');
}

    public function saveEditJob(): void
    {
        $this->guardAuth();
        if (! $this->throttleAction('save_edit', 10, 60)) return;

        $this->editErrors = [];
        $errors = [];

        $jobForGuard = JobPosting::find($this->editingJobId);
        if ($jobForGuard && $jobForGuard->status === 'ACTIVE') {
            $this->dispatch('flash-message', type: 'warning', message: 'Deactivate this job posting before editing it.');
            return;
        }

        if (!trim($this->editJobTitle))    $errors['editJobTitle']    = 'Job title is required.';
        if (!trim($this->editCompany))     $errors['editCompany']     = 'Company name is required.';
        if (!trim($this->editCompanyType)) $errors['editCompanyType'] = 'Industry is required.';
        if (!trim($this->editLocation))    $errors['editLocation']    = 'Location is required.';
        if (!trim($this->editEmpType))     $errors['editEmpType']     = 'Employment type is required.';
        if (!trim($this->editExpLevel))    $errors['editExpLevel']    = 'Experience level is required.';

        // Same rule as the create form — optional, but must contain a
        // number if the organizer types anything into it.
        if (trim($this->editSalary) !== '' && !preg_match('/\d/', $this->editSalary)) {
            $errors['editSalary'] = 'Please include a numeric amount in the salary field (for example, ₱25,000 per month).';
        }

        if (!trim($this->editDeadline)) {
            $errors['editDeadline'] = 'Deadline is required.';
        } else {
            // Same rule as the create form — today itself is rejected,
            // deadline must be strictly a future date.
            $deadlineDay = \Carbon\Carbon::createFromFormat('Y-m-d', $this->editDeadline, 'Asia/Manila')->startOfDay();
            $todayDay    = now('Asia/Manila')->startOfDay();
            if ($deadlineDay->lte($todayDay)) {
                $errors['editDeadline'] = 'Deadline must be a future date — today or earlier is not allowed.';
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

        if (!empty($errors)) {
            $this->editErrors = $errors;
            $this->dispatch('scroll-to-first-error');
            return;
        }

        $org = auth()->user()?->organizer;
        $duplicate = JobPosting::where('job_title', $this->sanitize($this->editJobTitle))
            ->where('company_name', $this->sanitize($this->editCompany))
            ->where('employment_type', $this->sanitize($this->editEmpType))
            ->whereNotIn('status', ['ORGANIZER_DELETED'])
            ->where('id', '!=', $this->editingJobId)
            ->where(fn($q) => $q->where('organizer_id', $org?->id)->orWhereNull('organizer_id'))
            ->exists();

        if ($duplicate) {
            $this->editErrors['editJobTitle'] = 'A job posting with this title, company, and employment type already exists.';
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

        // ── NOTIFY SELF (organizer sidebar notif — grouped ×N per day client-side) ──
        $this->dispatch('job-self-action', action: 'updated', id: $job->id, title: $this->sanitize($this->editJobTitle));

        $this->dispatch('flash-message', type: 'success', message: 'Job posting updated successfully.');
        $this->showEditModal = false;
        $this->resetEditFields();

        // ── FIX: reveal the sidebar again after a successful save. Editing a
        //    job hides the sidebar (close-sidebar was dispatched when the
        //    modal opened); previously this method never dispatched
        //    open-sidebar back, so the sidebar stayed hidden/blank after
        //    saving changes until the user manually navigated away. ──
        $this->dispatch('open-sidebar');
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

            if ($newStatus === 'ACTIVE') {
                app(AlumniNotificationController::class)->notifyAlumniOfActivatedJob($job);
            }

            $this->dispatch('job-management-updated', [
                'id'     => $job->id,
                'title'  => $job->job_title,
                'action' => $newStatus === 'ACTIVE' ? 'activated' : 'deactivated',
            ]);

            // ── NOTIFY SELF (organizer sidebar notif — grouped ×N per day client-side) ──
            $this->dispatch('job-self-action', action: $newStatus === 'ACTIVE' ? 'activated' : 'deactivated', id: $job->id, title: $job->job_title);

            $this->dispatch('flash-message', type: 'success', message: $msg);
        }

        $this->cancelToggleStatus();
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
            description:  sprintf('Organizer permanently deleted job posting "%s" (ID #%d).', $job->job_title, $job->id),
            oldValues:    ['status' => 'INACTIVE'],
            newValues:    ['status' => 'ORGANIZER_DELETED'],
            severity:     'warning'
        );

        // ── NOTIFY SELF (organizer sidebar notif — grouped ×N per day client-side) ──
        $this->dispatch('job-self-action', action: 'deleted', id: $job->id, title: $job->job_title);

        $this->dispatch('flash-message', type: 'success', message: 'Job posting permanently deleted.');
        $this->cancelDeleteJob();

        if ($this->showEditModal) {
            $this->showEditModal = false;
            $this->resetEditFields();
        }
        if ($this->showViewModal) {
            $this->showViewModal = false;
            $this->viewingJobId  = null;
        }

        // ── FIX: deleting a job from inside the Edit/View modal closes that
        //    modal, but the sidebar (hidden via close-sidebar when the modal
        //    opened) was never told to come back. Restore it here too. ──
        $this->dispatch('open-sidebar');
    }

    public function cancelDeleteJob(): void
    {
        $this->showDeleteModal = false;
        $this->deleteJobId    = null;
        $this->deleteJobTitle = '';
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
        $this->sharePhotoUrl    = $this::jobImageUrl($job->job_image ?? null);

        $this->loadShareableRooms();
        $this->computeAutoSelectedShareRooms();

        $this->showShareModal   = true;
    }

    /**
     * ── Auto-select the chats that match this job's target audience ──
     * Jobs target one or more colleges via target_college (comma-separated,
     * e.g. "College of Engineering,College of Business" / blank = the
     * organizer's own college). Pre-checks each targeted college's
     * college-wide room plus its course "All Batches" rooms, mirroring the
     * batch/course auto-select already used by Event Management's Share to
     * Message Hub. Staff Chat is never auto-selected. The organizer can
     * still freely check/uncheck before hitting Share.
     */
    private function computeAutoSelectedShareRooms(): void
    {
        $raw      = $this->shareCollege !== '' ? $this->shareCollege : ($this->organizerCollege ?? '');
        $colleges = array_values(array_filter(array_map('trim', explode(',', $raw))));

        $this->shareTargetCollegesList = $colleges;

        $matched = [];
        if (!empty($colleges)) {
            foreach ($this->shareAvailableRooms as $r) {
                if (($r['type'] ?? '') === 'staff') continue;
                if (in_array(($r['type'] ?? ''), ['college', 'course'], true)
                    && in_array(($r['department'] ?? ''), $colleges, true)) {
                    $matched[] = (string) $r['id'];
                }
            }
        }

        $this->shareAutoRoomIds   = $matched;
        $this->shareTargetRoomIds = $matched;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal     = false;
        $this->shareJobId         = null;
        $this->shareJobTitle      = '';
        $this->shareCompany       = '';
        $this->shareEmpType       = '';
        $this->shareLocation      = '';
        $this->shareExpLevel      = '';
        $this->shareSalary        = '';
        $this->shareDeadline      = '';
        $this->shareDescription   = '';
        $this->shareCollege       = '';
        $this->sharePhotoUrl      = '';
        $this->shareAvailableRooms = [];
        $this->shareTargetRoomIds  = [];
        $this->shareAutoRoomIds    = [];
        $this->shareTargetCollegesList = [];
    }

    // ── "Share to Alumni Batch Chats" — posts the job recap as a chat
    //    message into every chat room the organizer picks (checkbox list,
    //    with Select All). Mirrors the same chat_rooms/chat_messages
    //    tables used by Event Management's "Share to Message Hub". ──
    private function loadShareableRooms(): void
    {
        $college = $this->shareCollege ?: $this->organizerCollege;

        $rooms = [];

        // Staff Chat (directors + coordinators)
        $staffRoom = DB::table('chat_rooms')->where('course_code', '__director__')->first(['id']);
        if ($staffRoom) {
            $rooms[] = ['id' => (int) $staffRoom->id, 'label' => 'Staff Chat', 'type' => 'staff', 'department' => ''];
        }

        if ($college) {
            // College-wide room
            $collegeRoom = DB::table('chat_rooms')
                ->where('department', $college)
                ->where('batch', 0)
                ->where(function ($q) {
                    $q->where('course_code', '')->orWhere('course_code', 'like', 'CLG_%');
                })
                ->first(['id']);
            if ($collegeRoom) {
                $rooms[] = ['id' => (int) $collegeRoom->id, 'label' => $college . ' · College-Wide', 'type' => 'college', 'department' => $college];
            }

            $collegeCourseCodes = \App\Models\Course::where('college', $college)->pluck('code')->toArray();

            if (!empty($collegeCourseCodes)) {
                // Course "All Batches" GCs
                $courseRooms = DB::table('chat_rooms')
                    ->whereIn('course_code', $collegeCourseCodes)
                    ->where('batch', 0)
                    ->get(['id', 'course_code']);

                foreach ($courseRooms as $r) {
                    $rooms[] = ['id' => (int) $r->id, 'label' => strtoupper($r->course_code) . ' · All Batches', 'type' => 'course', 'department' => $college];
                }

                // Per-batch GCs
                $batchRooms = DB::table('chat_rooms')
                    ->whereIn('course_code', $collegeCourseCodes)
                    ->where('batch', '>', 0)
                    ->orderBy('course_code')->orderByDesc('batch')
                    ->get(['id', 'course_code', 'batch', 'name']);

                foreach ($batchRooms as $r) {
                    $rooms[] = ['id' => (int) $r->id, 'label' => $r->name ?: (strtoupper($r->course_code) . ' · Batch ' . $r->batch), 'type' => 'batch', 'department' => $college];
                }
            }
        }

        $this->shareAvailableRooms = $rooms;
    }

    public function toggleSelectAllShareRooms(): void
    {
        $allIds = collect($this->shareAvailableRooms)->pluck('id')->map(fn ($id) => (string) $id)->toArray();

        // If everything is already selected, uncheck all. Otherwise select all.
        $allSelected = !empty($allIds) && empty(array_diff($allIds, $this->shareTargetRoomIds));

        $this->shareTargetRoomIds = $allSelected ? [] : $allIds;
    }

    public function shareToAlumniChats(): void
    {
        $this->guardAuth();
        if (! $this->shareJobId) {
            $this->dispatch('flash-message', type: 'error', message: 'No job selected to share.');
            return;
        }

        if (empty($this->shareTargetRoomIds)) {
            $this->dispatch('flash-message', type: 'error', message: 'Select at least one chat to share to.');
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

        $validRoomIds = collect($this->shareAvailableRooms)->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $roomIds      = array_values(array_intersect($this->shareTargetRoomIds, $validRoomIds));

        if (empty($roomIds)) {
            $this->dispatch('flash-message', type: 'error', message: 'Select at least one chat to share to.');
            return;
        }

        $org = auth()->user()?->organizer;

        // ── Card marker only — mirrors Event Management's [[EVENT:TYPE:id]]
        //    marker. The chat views (chat-alumni.blade.php /
        //    director-messenger.blade.php) must resolve [[JOB:id]] into a
        //    rich preview card (photo, title, company, deadline, View Job
        //    button) instead of the old wall of plain emoji text. ─────────
        $body = '[[JOB:' . $this->shareJobId . ']]';
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
        $this->dispatch('flash-message', type: 'success', message: "Job shared to {$count} chat" . ($count === 1 ? '' : 's') . "!");

        // Close the whole Share Job Posting modal right after a successful
        // share — no need to make the organizer manually close it.
        $this->closeShareModal();
    }

    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try { $path = route('jobs.index', [], false); } catch (\Throwable) { $path = '/jobs'; }
        return $base . $path;
    }
};
?>

<div class="flex flex-col" style="height: calc(100vh - 180px); max-height: calc(100vh - 180px); overflow: hidden;">

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
.scroll-c::-webkit-scrollbar-track { background: transparent; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

.fs-modal-body-col::-webkit-scrollbar { width: 4px; }
.fs-modal-body-col::-webkit-scrollbar-track { background: transparent; }
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

/* ── Disabled select styling (used for locked "Industry" field when PHILCST) ── */
select:disabled {
    background-color: #f3f4f6 !important;
    color: #999999 !important;
    cursor: not-allowed !important;
    opacity: 1;
}

/* ── Date inputs: reserve room so the full date (incl. the 4-digit year)
     is never clipped or overlapped by the native calendar-picker icon.
     Previously "07/24/2026" would render with "2026" crammed right up
     against (or partially behind) the icon on smaller field widths. ── */
input[type="date"] {
    position: relative;
    padding-right: 2.5rem !important;
}
input[type="date"]::-webkit-calendar-picker-indicator {
    position: absolute;
    right: 0.55rem;
    top: 50%;
    transform: translateY(-50%);
    margin: 0;
    padding: 0;
    cursor: pointer;
    opacity: 0.75;
}
input[type="date"]::-webkit-datetime-edit {
    padding-right: 2px;
}
input[type="date"]::-webkit-datetime-edit-fields-wrapper {
    padding-right: 2px;
}

/* ── Modal top-right icon buttons: fully transparent, no white fill ── */
.modal-top-btn {
    background: transparent !important;
    border: 1px solid rgba(255,255,255,.35) !important;
}
.modal-top-btn:hover {
    background: rgba(255,255,255,.12) !important;
    border-color: rgba(255,255,255,.55) !important;
}
.modal-top-btn:active { transform: scale(.93); }

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

@media (max-width: 768px), (hover: none), (pointer: coarse) {
    #eo-hover-tip { display: none !important; }
    .modal-top-btn .mtip { display: none !important; }
    .activate-disabled-wrap .adtip { display: none !important; }
    [class*="group-hover:opacity-100"] { display: none !important; }
}

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

.edit-textarea-xl  { flex: 1; min-height: 180px; }
.edit-textarea-lg  { flex: 1; min-height: 140px; }

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

@media (max-width: 767px) {
    .jm-share-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
    }
    .jm-share-backdrop .jm-share-sheet {
        border-radius: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
    }
}

.jm-share-close-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    cursor: pointer; transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
}
.jm-share-close-btn:hover  { background: #e5e7eb; border-color: #d1d5db; }
.jm-share-close-btn:active { transform: scale(.93); }
.jm-share-close-btn svg    { width: 14px; height: 14px; stroke: #4b5563; stroke-width: 2.25; stroke-linecap: round; }
.jm-share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.jm-share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.jm-share-close-btn:hover .tip { opacity: 1; }

.jm-share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .15s, transform .1s; border: none;
}
.jm-share-option-btn:hover  { filter: brightness(0.94); }
.jm-share-option-btn:active { transform: scale(.98); }
.jm-share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.jm-share-option-btn .label-text { flex: 1; text-align: left; }

.jm-share-photo-preview {
    width: 100%;
    height: 140px;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}
.jm-share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain;
}
.jm-share-photo-preview .dl-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(17,24,39,.75); color: #fff;
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px;
    display: flex; align-items: center; gap: 4px;
    pointer-events: none;
}

.jm-dl-confirm-icon {
    width: 3rem; height: 3rem; border-radius: 0.9rem;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.jm-dl-confirm-btn {
    flex: 1; padding: 0.65rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .15s, transform .1s; border: none;
}
.jm-dl-confirm-btn:active { transform: scale(.97); }
.jm-dl-confirm-btn.primary { background: #7a3f91; color: #fff; }
.jm-dl-confirm-btn.primary:hover { filter: brightness(0.95); }
.jm-dl-confirm-btn.secondary { background: #f3f4f6; color: #333333; border: 1px solid #e5e7eb; }
.jm-dl-confirm-btn.secondary:hover { background: #e5e7eb; }

@keyframes jmPanelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.jm-share-sheet { animation: jmPanelIn .2s cubic-bezier(.25,.8,.25,1) both; }
.jm-share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ══ FIX 1: table rows white (not gray), and no inner horizontal scrollbar ══ */
#jm-table-scroll,
#jm-table-scroll table,
#jm-table-scroll tbody,
#jm-table-scroll tr {
    background: #ffffff !important;
}
#jm-table-scroll .overflow-x-auto {
    overflow-x: visible !important;
}

/* ══ FIX 3: default photo fully visible (no dimming) in Post modal ══ */
.img-upload-zone img.job-default-photo-img {
    opacity: 1 !important;
}

/* ══ FIX 4: description / qualifications / how-to-apply view containers white, not gray ══ */
.view-content-box {
    background: #ffffff !important;
    border: 1.5px solid #e8e0f0 !important;
}

/* ══ Alumni Director badge (jobs table + modals) ══ */
.jm-director-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    background: #f5eef9;
    border: 1px solid #d4aaeb;
    color: #7a3f91;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
}

/* ══ MOBILE: View/Edit Job full-screen header — title & badge sizing ══ */
@media (max-width: 640px) {
    .jm-modal-header-title {
        font-size: 0.9375rem !important;
        line-height: 1.3 !important;
    }
    .jm-modal-header-sub {
        font-size: 0.6875rem !important;
        max-width: 170px !important;
    }
    .jm-modal-header-row {
        padding-left: 1rem !important;
        padding-right: 1rem !important;
        gap: 0.5rem !important;
    }
    .jm-director-badge {
        font-size: 9px;
        padding: 2px 7px;
    }
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
                        wire:loading.attr="disabled" wire:target="openPostModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-70 disabled:cursor-wait">
                    <span wire:loading.remove wire:target="openPostModal">
                        <i class="fas fa-plus text-sm"></i>
                    </span>
                    <span wire:loading wire:target="openPostModal">
                        <i class="fas fa-spinner fa-spin text-sm"></i>
                    </span>
                </button>
                <div class="absolute top-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
                    <i class="fas fa-plus text-[9px] mr-1"></i>Post a Job
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-1 min-h-0">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-transparent border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus,filterType">
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
            </select>
            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333] bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            @if($filterStatus)
            @php
                $statusPillMap = [
                    'ACTIVE'   => ['label' => 'Active',   'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'INACTIVE' => ['label' => 'Inactive', 'cls' => 'bg-amber-50 border-amber-300 text-amber-800'],
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
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 bg-white">

            {{-- Centered loading spinner — big icon over the table itself,
                 same pattern as the alumni-facing yearbook, instead of only
                 the thin progress bar in the filter strip. --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="search,filterStatus,filterType,resetFilters,previousPage,nextPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            <div id="jm-table-scroll"
                 class="scroll-c h-full overflow-y-auto overflow-x-hidden bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-50" wire:target="search,filterStatus,filterType,resetFilters,previousPage,nextPage">

            @if($this->jobPostings->count() > 0)

            <div class="bg-white">
                <table class="w-full bg-white border-collapse table-fixed">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
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
                            $canShare         = !$isDeadlinePassed && $isActive;
                        @endphp
                        <tr class="transition-colors duration-100 cursor-pointer bg-white hover:bg-[#f5f0fa]"
                            wire:click="viewJob({{ $job->id }})"
                            wire:key="job-row-{{ $job->id }}"
                            data-eo-row>

                            <td class="px-4 py-3.5">
                                <div class="max-w-[240px]">
                                    <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333] flex items-center gap-1.5 flex-wrap">
                                        {{ $job->job_title }}
                                        @if($isAlumniDirector)
                                            <span class="jm-director-badge"><i class="fas fa-shield-halved text-[8px]"></i>Alumni Director</span>
                                        @endif
                                    </p>
                                    <p class="text-xs mt-0.5 text-[#666666]">
                                        {{ $job->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <p class="text-sm font-semibold text-[#333333]">{{ $job->employment_type }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell">
                                <p class="text-sm text-[#555555] truncate max-w-[160px]">{{ $job->company_name }}</p>
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
                                <div class="flex items-center justify-end gap-1.5" @click.stop>

                                    {{-- Share --}}
                                    <div class="relative inline-flex group" data-eo-share>
                                        <button type="button"
                                                @if($canShare) wire:click.stop="openShareModal({{ $job->id }})" @endif
                                                wire:loading.attr="disabled" wire:target="openShareModal({{ $job->id }})"
                                                @disabled(!$canShare)
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition bg-blue-50 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-blue-50 disabled:hover:border-blue-200 {{ $canShare ? 'cursor-pointer' : '' }}">
                                            <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareModal({{ $job->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareModal({{ $job->id }})"></i>
                                        </button>
                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                            Share
                                            <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                        </div>
                                    </div>

                                    @php
                                        $canToggle = !$isAlumniDirector && !$isDeadlinePassed;
                                        $canDelete = !$isAlumniDirector && !$isActive;
                                    @endphp

                                    {{-- Activate / Deactivate --}}
                                    <div class="relative inline-flex group" data-eo-share>
                                        <button type="button"
                                                @if($canToggle) wire:click.stop="confirmToggleStatus({{ $job->id }})" @endif
                                                wire:loading.attr="disabled" wire:target="confirmToggleStatus({{ $job->id }})"
                                                @disabled(!$canToggle)
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition {{ $isActive ? 'bg-amber-50 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400 disabled:hover:bg-amber-50 disabled:hover:border-amber-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400 disabled:hover:bg-emerald-50 disabled:hover:border-emerald-200' }} disabled:opacity-40 disabled:cursor-not-allowed {{ $canToggle ? 'cursor-pointer' : '' }}">
                                            <i class="fas {{ $isActive ? 'fa-circle-pause' : 'fa-circle-play' }}" wire:loading.remove wire:target="confirmToggleStatus({{ $job->id }})"></i>
                                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="confirmToggleStatus({{ $job->id }})"></i>
                                        </button>
                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                            {{ $isActive ? 'Deactivate' : 'Activate' }}
                                            <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                        </div>
                                    </div>

                                    {{-- Delete --}}
                                    <div class="relative inline-flex group" data-eo-share>
                                        <button type="button"
                                                @if($canDelete) wire:click.stop="confirmDeleteJob({{ $job->id }})" @endif
                                                wire:loading.attr="disabled" wire:target="confirmDeleteJob({{ $job->id }})"
                                                @disabled(!$canDelete)
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition bg-red-50 text-red-600 border border-red-200 hover:bg-white hover:border-red-400 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-red-50 disabled:hover:border-red-200 {{ $canDelete ? 'cursor-pointer' : '' }}">
                                            <i class="fas fa-trash text-[10px]" wire:loading.remove wire:target="confirmDeleteJob({{ $job->id }})"></i>
                                            <i class="fas fa-spinner fa-spin text-[10px]" wire:loading wire:target="confirmDeleteJob({{ $job->id }})"></i>
                                        </button>
                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                            Delete
                                            <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                        </div>
                                    </div>

                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-briefcase text-gray-400 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-[#333333]">
                        @if($search || $filterStatus || $filterType) No jobs match your filters
                        @else No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#555555]">
                        @if($search || $filterStatus || $filterType) Try clearing your filters to see all postings.
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
                Permanently Delete Job Posting
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">Are you sure you want to permanently delete:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $deleteJobTitle }}
            </p>
            <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-5 flex items-start gap-2">
                <i class="fas fa-triangle-exclamation text-red-500 mt-0.5 flex-shrink-0 text-xs"></i>
                <span class="text-xs text-red-800"><strong>This action cannot be undone.</strong> The job posting will be permanently removed and will no longer appear anywhere in this table. There is no restore option.</span>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelDeleteJob"
                        wire:loading.attr="disabled" wire:target="executeDeleteJob,cancelDeleteJob"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="cancelDeleteJob">
                        <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                    </span>
                    <span wire:loading wire:target="cancelDeleteJob">
                        <i class="fas fa-spinner fa-spin mr-1 text-xs"></i>Cancel
                    </span>
                </button>
                <button wire:click="executeDeleteJob"
                        wire:loading.attr="disabled" wire:target="executeDeleteJob"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-70 disabled:cursor-wait">
                    <span wire:loading.remove wire:target="executeDeleteJob">
                        <i class="fas fa-trash-can mr-1 text-xs"></i>
                        Yes, Delete Permanently
                    </span>
                    <span wire:loading wire:target="executeDeleteJob">
                        <i class="fas fa-spinner fa-spin mr-1 text-xs"></i>
                        Deleting…
                    </span>
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
                <span class="text-xs text-amber-900">Alumni won't see this job posting until you re-activate it. All fields become editable while inactive.</span>
            </div>
            @endif
            <div class="flex gap-2">
                <button wire:click="cancelToggleStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleStatus,cancelToggleStatus"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="cancelToggleStatus">
                        <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                    </span>
                    <span wire:loading wire:target="cancelToggleStatus">
                        <i class="fas fa-spinner fa-spin mr-1 text-xs"></i>Cancel
                    </span>
                </button>
                <button wire:click="executeToggleStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleStatus"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-70 disabled:cursor-wait
                               {{ $toggleAction === 'activate' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-amber-500 hover:bg-amber-600' }}">
                    <span wire:loading.remove wire:target="executeToggleStatus">
                        <i class="fas {{ $toggleAction === 'activate' ? 'fa-circle-play' : 'fa-circle-pause' }} mr-1 text-xs"></i>
                        Yes, {{ $toggleAction === 'activate' ? 'Activate' : 'Deactivate' }}
                    </span>
                    <span wire:loading wire:target="executeToggleStatus">
                        <i class="fas fa-spinner fa-spin mr-1 text-xs"></i>
                        {{ $toggleAction === 'activate' ? 'Activating…' : 'Deactivating…' }}
                    </span>
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
            <button wire:click="closePostModal" type="button"
                    wire:loading.attr="disabled" wire:target="closePostModal"
                    class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95"
                    aria-label="Close">
                <span wire:loading.remove wire:target="closePostModal">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2L12 12M12 2L2 12" stroke="#ffffff" stroke-width="2.25" stroke-linecap="round"/>
                    </svg>
                </span>
                <span wire:loading wire:target="closePostModal">
                    <i class="fas fa-spinner fa-spin text-white text-xs"></i>
                </span>
                <span class="mtip">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Photo first, then Company --}}
        <div class="w-full lg:w-[280px] xl:w-[300px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-3 space-y-3">

                {{-- Job Photo — shown first so the default photo is visible immediately at the top --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-image text-[9px] text-[#555555]"></i> Job Photo
                        <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-3.5">
                        <div wire:ignore
                             x-data="{
                                 preview: null,
                                 defaultUrl: @js(asset('storage/job/default-photo-job.jpg')),
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
                                    <div class="relative">
                                        <img :src="defaultUrl" class="img-preview-thumb job-default-photo-img" alt="Default job photo"
                                             onerror="this.style.display='none'">
                                        <label class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 cursor-pointer w-full bg-black/0 hover:bg-black/35 transition group/uploadlbl">
                                            <span class="opacity-0 group-hover/uploadlbl:opacity-100 transition flex flex-col items-center gap-1.5 bg-white/90 rounded-xl px-4 py-3">
                                                <i class="fas fa-cloud-arrow-up text-2xl text-[#7a3f91]"></i>
                                                <p class="font-semibold text-xs text-[#333333]">Click to upload a photo</p>
                                                <p class="text-[10px] text-[#555555]">JPG, PNG, WebP — max 2MB</p>
                                            </span>
                                            <input x-ref="fileInput" type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                                   wire:model="postJobImage" @change="handleFile($event)">
                                        </label>
                                        <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold bg-gray-600 text-white px-1.5 py-0.5 rounded-full">DEFAULT</span>
                                    </div>
                                </template>
                            </div>
                            <p class="text-[10px] mt-1.5 text-center font-medium" style="color:#111111;">The default photo above is used automatically if you don't upload one. Click photo to update.</p>
                            <div wire:loading wire:target="postJobImage" class="mt-1.5 text-xs text-[#7a3f91] flex items-center gap-2 justify-center">
                                <i class="fas fa-spinner fa-spin text-xs"></i> Uploading…
                            </div>
                            @if(isset($postErrors['postJobImage']))
                                <p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobImage'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Employer Category --}}
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postOrgCategory']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-building text-[9px] text-[#555555]"></i> Employer <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach([['philcst','PHILCST Campus','Internal department','fa-school'],['partner','Partner Company','Known partner company','fa-handshake'],['custom','Other Company','Enter manually','fa-pen-to-square']] as [$val,$label,$sub,$ico])
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
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Company Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncN(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postPartnerName']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Industry <span class="text-red-500">*</span></label>
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
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Company Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncN(cName)" type="text" placeholder="e.g. Dept. of Labor" maxlength="150"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postCustomName']) ? 'border-red-300 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[#333333] mb-1">Industry <span class="text-red-500">*</span></label>
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
                        <div class="text-center py-3 text-[#777777]"><p class="text-xs">Select an option above to continue.</p></div>
                        @endif
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE: Job Info + Textareas --}}
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
                                <input wire:model.defer="postSalary" type="text" placeholder="e.g. ₱25,000 per month" maxlength="100"
                                       oninput="window.__eoFormatSalaryInput(this)"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($postErrors['postSalary']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($postErrors['postSalary']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postSalary'] }}</p>@endif
                            </div>
                            <div>
                                <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                    Deadline <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="postDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->addDay()->format('Y-m-d') }}"
                                       oninput="window.__eoGuardDeadlineInput(this)"
                                       onchange="window.__eoGuardDeadlineInput(this)"
                                       onclick="window.__eoOpenDatePicker(this)"
                                       class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 cursor-pointer {{ isset($postErrors['postDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                @if(isset($postErrors['postDeadline']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                            </div>
                        </div>
                    </div>
                </div>

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

        {{-- RIGHT: Target College + Submission Tips (moved here, above Visibility) + Actions --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto scroll-c">
            <div class="p-3 space-y-3 flex-1">

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

                {{-- Submission Tips --}}
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

                {{-- Visibility --}}
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
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-70 disabled:cursor-wait">
                    <span wire:loading.remove wire:target="savePost" class="flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane text-xs"></i>
                        Post Job
                    </span>
                    <span wire:loading wire:target="savePost" class="flex items-center justify-center gap-2">
                        <i class="fas fa-spinner fa-spin text-xs"></i>
                        Posting…
                    </span>
                </button>
                <button type="button" wire:click="closePostModal"
                        wire:loading.attr="disabled" wire:target="savePost,closePostModal"
                        class="w-full px-5 py-2 rounded-xl text-xs font-semibold bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333] flex items-center justify-center gap-1.5 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="closePostModal" class="flex items-center justify-center gap-1.5">
                        <i class="fas fa-xmark text-[10px]"></i>
                        Cancel
                    </span>
                    <span wire:loading wire:target="closePostModal" class="flex items-center justify-center gap-1.5">
                        <i class="fas fa-spinner fa-spin text-[10px]"></i>
                        Cancel
                    </span>
                </button>
            </div>
        </div>

    </div>
</div>
@endif


{{--
    ══ VIEW / EDIT JOB — FULL SCREEN 3-COLUMN ══
    Fix #5: this same modal now also serves Alumni-Director-posted jobs.
    A "Alumni Director" badge is shown, and edit/delete/deactivate
    controls are simply hidden (organizer cannot manage it) while everything
    else (layout, colors, sections) stays identical.

    Fix #3: whenever a job is INACTIVE (and NOT an Alumni-Director job), ALL
    fields are automatically editable — no manual "Edit Mode" toggle needed.
    Alumni-Director jobs always stay read-only, regardless of status.

    Fix (this round):
      - Header title/subtitle sized down for mobile (jm-modal-header-* classes).
      - The pen-to-square "Edit"/"View Job" header icon logic: it's purely a
        label icon (not a clickable control) but conceptually should only
        ever imply "editable" when it truly is — i.e. NOT when the job is
        ACTIVE and NOT when it's an Alumni Director job. It now switches to
        an eye icon in those two read-only cases, matching the "View Job"
        label already shown next to it.
      - Industry (`editCompanyType`) select now carries a real `disabled`
        attribute when the company is PHILCST (not just "readonly"-styled
        via classes, which does nothing on a <select>), matching the
        Company Name / Location fields beside it.
--}}
@if($showEditModal)
@php
    $editingJob = $this->editingJobFresh;
    $editJobIsActive = $editingJob && $editingJob->status === 'ACTIVE';
    $editIsAlumniDirectorJob = $editingJob && is_null($editingJob->organizer_id);
    // Auto-editable the moment the job is INACTIVE — unless it's an Alumni Director job, which is always read-only.
    $editModeAllowed = (!$editJobIsActive && !$editIsAlumniDirectorJob);
    // Header icon should only ever *look* editable (pen icon) when editing is
    // actually possible. Active jobs and Alumni Director jobs are always
    // read-only, so the header shows an eye icon for those instead.
    $editHeaderIsReadOnly = $editJobIsActive || $editIsAlumniDirectorJob;
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeEditModal()"
     x-data="{ editMode: {{ $editModeAllowed ? 'true' : 'false' }} }"
     x-effect="editMode = {{ $editModeAllowed ? 'true' : 'false' }}">

    <div class="flex items-center justify-between px-4 sm:px-6 lg:px-10 py-3 bg-[#7a3f91] flex-shrink-0 shadow-lg jm-modal-header-row">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                @if($editHeaderIsReadOnly)
                    <i class="fas fa-eye text-white text-sm"></i>
                @else
                    <i class="fas fa-pen-to-square text-white text-sm"></i>
                @endif
            </div>
            <div class="min-w-0">
                <h2 class="text-white font-semibold text-lg leading-tight flex items-center gap-2 flex-wrap jm-modal-header-title">
                    <span x-show="!editMode">View Job</span>
                    <span x-show="editMode" x-cloak>Edit Job</span>
                    @if($editIsAlumniDirectorJob)
                        <span class="jm-director-badge" style="background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.35);color:#ffffff;">
                            <i class="fas fa-shield-halved text-[8px]"></i>Alumni Director
                        </span>
                    @endif
                </h2>
                @if($editingJob)
                <p class="text-white/60 text-xs mt-0.5 truncate max-w-[260px] jm-modal-header-sub">{{ $editingJob->job_title }}</p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0">
            @if($editingJob && !$editIsAlumniDirectorJob)
                @php
                    $editJobDeadlinePassed = \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay());
                @endphp
                @if(!$editJobIsActive)
                    @if($editJobDeadlinePassed)
                        <div class="activate-disabled-wrap">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-not-allowed opacity-40 border border-white/35">
                                <i class="fas fa-circle-play text-white text-sm"></i>
                            </span>
                            <span class="adtip">Update deadline to activate</span>
                        </div>
                    @else
                        <button wire:click="confirmToggleStatus({{ $editingJobId }})" type="button"
                                wire:loading.attr="disabled" wire:target="confirmToggleStatus({{ $editingJobId }})"
                                class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                            <span wire:loading.remove wire:target="confirmToggleStatus({{ $editingJobId }})">
                                <i class="fas fa-circle-play text-white text-sm"></i>
                            </span>
                            <span wire:loading wire:target="confirmToggleStatus({{ $editingJobId }})">
                                <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                            </span>
                            <span class="mtip">Activate</span>
                        </button>
                    @endif
                @else
                    <button wire:click="confirmToggleStatus({{ $editingJobId }})" type="button"
                            wire:loading.attr="disabled" wire:target="confirmToggleStatus({{ $editingJobId }})"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                        <span wire:loading.remove wire:target="confirmToggleStatus({{ $editingJobId }})">
                            <i class="fas fa-circle-pause text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="confirmToggleStatus({{ $editingJobId }})">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                        <span class="mtip">Deactivate</span>
                    </button>
                @endif
                @php $editJobCanShare = !$editJobDeadlinePassed && $editJobIsActive; @endphp
                @if($editJobCanShare)
                    <button wire:click="openShareModal({{ $editingJobId }})" type="button"
                            wire:loading.attr="disabled" wire:target="openShareModal({{ $editingJobId }})"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                        <span wire:loading.remove wire:target="openShareModal({{ $editingJobId }})">
                            <i class="fas fa-share-nodes text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="openShareModal({{ $editingJobId }})">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                        <span class="mtip">Share</span>
                    </button>
                @endif
                @if(!$editJobIsActive)
                    <button wire:click="confirmDeleteJob({{ $editingJobId }})" type="button"
                            wire:loading.attr="disabled" wire:target="confirmDeleteJob({{ $editingJobId }})"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                        <span wire:loading.remove wire:target="confirmDeleteJob({{ $editingJobId }})">
                            <i class="fas fa-trash text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="confirmDeleteJob({{ $editingJobId }})">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                        <span class="mtip">Delete</span>
                    </button>
                @endif
            @elseif($editingJob && $editIsAlumniDirectorJob)
                @php
                    $editJobDeadlinePassed = \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay());
                    $editJobCanShare = !$editJobDeadlinePassed && $editJobIsActive;
                @endphp
                @if($editJobCanShare)
                    <button wire:click="openShareModal({{ $editingJobId }})" type="button"
                            wire:loading.attr="disabled" wire:target="openShareModal({{ $editingJobId }})"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                        <span wire:loading.remove wire:target="openShareModal({{ $editingJobId }})">
                            <i class="fas fa-share-nodes text-white text-sm"></i>
                        </span>
                        <span wire:loading wire:target="openShareModal({{ $editingJobId }})">
                            <i class="fas fa-spinner fa-spin text-white text-sm"></i>
                        </span>
                        <span class="mtip">Share</span>
                    </button>
                @endif
            @endif
            <button wire:click="closeEditModal" type="button"
                    wire:loading.attr="disabled" wire:target="closeEditModal"
                    class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95">
                <span wire:loading.remove wire:target="closeEditModal">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2L12 12M12 2L2 12" stroke="#ffffff" stroke-width="2.25" stroke-linecap="round"/>
                    </svg>
                </span>
                <span wire:loading wire:target="closeEditModal">
                    <i class="fas fa-spinner fa-spin text-white text-xs"></i>
                </span>
                <span class="mtip">Close</span>
            </button>
        </div>
    </div>

    @if($editingJob && $editIsAlumniDirectorJob)
    <div class="bg-purple-50 border-b border-purple-200 px-4 sm:px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-purple-800 font-semibold">
            <span class="jm-director-badge mr-1.5">
                <i class="fas fa-shield-halved text-[8px]"></i> Alumni Director
            </span>
            View only — editing is not available for this job posting.
        </p>
    </div>
    @elseif($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-blue-50 border-b border-blue-200 px-4 sm:px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-pen text-blue-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-blue-800">
            <strong>This job is currently Inactive — all fields below are editable.</strong>
            @if($editJobDeadlinePassed)
                The deadline has passed — update it, save, then use <strong>Activate</strong>.
            @else
                Review your changes carefully before clicking <strong>Save Changes</strong>, then use <strong>Activate</strong> (top-right) to go live.
            @endif
        </p>
    </div>
    @endif

    @if(!$editIsAlumniDirectorJob && $editJobIsActive)
    <div class="bg-purple-50 border-b border-purple-200 px-4 sm:px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-eye text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-purple-800"><strong>View Mode:</strong> This job is currently Active. Deactivate it (top-right) first to make changes.</p>
    </div>
    @endif


    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        {{-- LEFT: Company Details + Job Info --}}
        <div class="w-full lg:w-[290px] xl:w-[310px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-2.5 space-y-2.5">

                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        <i class="fas fa-image text-[9px] text-[#555555]"></i> Job Photo
                        <span x-show="editMode" x-cloak class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-3">
                        <div x-show="!editMode">
                            @php $editViewImgUrl = $this::jobImageUrl($editingJob->job_image ?? null); @endphp
                            <div class="rounded-xl overflow-hidden" style="height:110px;">
                                <img src="{{ $editViewImgUrl }}" alt="{{ $editingJob->job_title }}"
                                     class="w-full h-full object-cover"
                                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
                            </div>
                            <p class="text-[10px] mt-1 text-center font-medium" style="color:#111111;">
                                {{ $editingJob->job_image ? 'Custom photo uploaded' : 'Default photo' }}
                            </p>
                        </div>

                        <div x-show="editMode" x-cloak>
                        <div wire:ignore
                             x-data="{
                                 preview: null,
                                 existing: @js($editCurrentImage ? Storage::url($editCurrentImage) : ''),
                                 defaultUrl: @js(asset('storage/job/default-photo-job.jpg')),
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
                                    <div class="img-upload-zone relative">
                                        <img :src="defaultUrl" class="img-preview-thumb-sm job-default-photo-img" alt="Default job photo"
                                             onerror="this.style.display='none'">
                                        <label class="absolute inset-0 flex flex-col items-center justify-center gap-1 cursor-pointer w-full bg-black/0 hover:bg-black/35 transition group/uploadlbl2">
                                            <span class="opacity-0 group-hover/uploadlbl2:opacity-100 transition flex flex-col items-center gap-1 bg-white/90 rounded-xl px-3 py-2">
                                                <i class="fas fa-cloud-arrow-up text-2xl text-[#7a3f91]"></i>
                                                <p class="font-semibold text-xs text-[#333333]">Upload photo</p>
                                                <p class="text-[10px] text-[#555555]">JPG, PNG, WebP · max 2MB</p>
                                            </span>
                                            <input x-ref="fileInput" type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                                   wire:model="editJobImage" @change="handleFile($event)">
                                        </label>
                                        <span class="absolute bottom-1.5 left-1.5 text-[10px] font-bold bg-gray-600 text-white px-1.5 py-0.5 rounded-full">DEFAULT</span>
                                    </div>
                                    <template x-if="removed">
                                        <p class="text-[10px] text-amber-600 mt-1 flex items-center gap-1">
                                            <i class="fas fa-triangle-exclamation text-[9px]"></i>
                                            Image removed — will use default on save.
                                        </p>
                                    </template>
                                    <p class="text-[10px] mt-1.5 text-center font-medium" style="color:#111111;">The default photo above is used automatically if you don't upload one. Click photo to update.</p>
                                    <div wire:loading wire:target="editJobImage" class="mt-1.5 text-xs text-[#7a3f91] flex items-center gap-2 justify-center">
                                        <i class="fas fa-spinner fa-spin text-xs"></i> Uploading…
                                    </div>
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
                @endif

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest">
                        Company Details
                    </div>
                    <div class="p-2.5 space-y-2">
                        @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp

                        <div>
                            <label class="block text-[0.7rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1">
                                Industry
                                <span x-show="editMode" x-cloak class="text-red-500">*</span>
                                @if($editIsPhilcst)
                                <span class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— locked (PHILCST)</span>
                                @endif
                            </label>
                            <div x-show="!editMode" class="view-field-display text-sm">{{ $editCompanyType ?: '—' }}</div>
                            <div x-show="editMode" x-cloak>
                                <select wire:model.live="editCompanyType" @if($editIsPhilcst) disabled @endif
                                        class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 tw-select-arrow {{ isset($editErrors['editCompanyType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} {{ $editIsPhilcst ? 'bg-gray-100 cursor-not-allowed text-[#999999]' : '' }}">
                                    <option value="">Select Industry</option>
                                    @foreach($this->jobOptions->get('company_type', collect()) as $opt)
                                        <option value="{{ $opt->label }}" @selected($editCompanyType === $opt->label)>{{ $opt->label }}</option>
                                    @endforeach
                                </select>
                                @if($editIsPhilcst)
                                    <p class="text-[10px] mt-0.5 flex items-center gap-1 text-[#777777]"><i class="fas fa-lock text-[9px]"></i>Industry is locked because the company is PHILCST.</p>
                                @endif
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
                                    <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. ₱25,000 per month"
                                           oninput="window.__eoFormatSalaryInput(this)"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editSalary']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($editErrors['editSalary']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editSalary'] }}</p>@endif
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
                                           min="{{ now()->setTimezone('Asia/Manila')->addDay()->format('Y-m-d') }}"
                                           oninput="window.__eoGuardDeadlineInput(this)"
                                           onchange="window.__eoGuardDeadlineInput(this)"
                                           onclick="window.__eoOpenDatePicker(this)"
                                           class="w-full px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 cursor-pointer {{ isset($editErrors['editDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}">
                                    @if(isset($editErrors['editDeadline']))<p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- MIDDLE: Textareas --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c flex flex-col p-3 gap-3">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col flex-1" style="min-height:220px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Description <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:160px;">{{ $editDescription ?: 'No description provided.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editDescription"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editDescription']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="Describe the role, responsibilities…" maxlength="5000"
                                  style="min-height:160px;"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        Qualifications <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:120px;">{{ $editQualifications ?: 'No qualifications listed.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editQualifications"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editQualifications']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="e.g. Bachelor's degree in relevant field…" maxlength="3000"
                                  style="min-height:120px;"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[#333333] text-[0.7rem] font-semibold uppercase tracking-widest flex-shrink-0">
                        How to Apply <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
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

        {{-- RIGHT: Target College + Status + History + Tips + Actions --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto scroll-c">
            <div class="p-3 space-y-3 flex-1">

                {{-- Target College --}}
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

                {{-- Status --}}
                @if($editingJob)
                @php
                    $statusColor = match($editingJob->status) {
                        'ACTIVE'   => ['bg-emerald-50 border-emerald-200', 'text-emerald-900', 'fa-circle-check text-emerald-500', 'text-emerald-700', 'Currently Active'],
                        'INACTIVE' => ['bg-amber-50 border-amber-200',   'text-amber-900',   'fa-circle-pause text-amber-500',   'text-amber-700',   'Currently Inactive'],
                        default    => ['bg-gray-50 border-gray-200',     'text-gray-900',    'fa-circle text-gray-500',          'text-gray-700',    $editingJob->status],
                    };
                @endphp
                <div class="rounded-xl px-3 py-2 border {{ $statusColor[0] }}">
                    <p class="font-semibold flex items-center gap-1.5 text-sm {{ $statusColor[1] }}">
                        <i class="fas {{ $statusColor[2] }} text-sm"></i> {{ $statusColor[4] }}
                    </p>
                    <p class="text-xs mt-0.5 {{ $statusColor[3] }}">
                        @if($editIsAlumniDirectorJob)
                            Posted by Alumni Director — status is managed by them.
                        @elseif($editingJob->status === 'INACTIVE' && $editJobDeadlinePassed)
                            Update the deadline above first, save, then use <strong>Activate</strong>.
                        @else
                            Use the {{ $editingJob->status === 'ACTIVE' ? 'Deactivate' : 'Activate' }} button in the top-right to toggle.
                        @endif
                    </p>
                </div>
                @endif

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
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Deleting a job is permanent — there is no restore.</span></li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                @if($editingJob && !$editJobIsActive && !$editIsAlumniDirectorJob)
                <div x-show="editMode" x-cloak>
                    <button type="button" wire:click="saveEditJob"
                            wire:loading.attr="disabled" wire:target="saveEditJob"
                            class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-70 disabled:cursor-wait">
                        <span wire:loading.remove wire:target="saveEditJob" class="flex items-center justify-center gap-2">
                            <i class="fas fa-floppy-disk text-xs"></i>
                            Save Changes
                        </span>
                        <span wire:loading wire:target="saveEditJob" class="flex items-center justify-center gap-2">
                            <i class="fas fa-spinner fa-spin text-xs"></i>
                            Saving…
                        </span>
                    </button>
                </div>
                @elseif($editIsAlumniDirectorJob)
                <div class="pb-1">
                    <p class="text-center text-xs text-[#777777]">Posted by Alumni Director — editing is not available.</p>
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
    $shareDlFormatted = $shareDeadline ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y') : '';

    $fbLines   = [];
    $fbLines[] = strtoupper($shareJobTitle);
    $fbLines[] = '';
    $fbLines[] = "Company: {$shareCompany}";
    if ($shareLocation)  $fbLines[] = "Location: {$shareLocation}";
    if ($shareEmpType)   $fbLines[] = "Employment Type: {$shareEmpType}";
    if ($shareExpLevel)  $fbLines[] = "Experience Level: {$shareExpLevel}";
    if ($shareSalary)    $fbLines[] = "Salary: {$shareSalary}";
    if ($shareDlFormatted) $fbLines[] = "Deadline: {$shareDlFormatted}";
    if ($shareCollege)   $fbLines[] = "Open For: {$shareCollege}";

    if (trim($shareDescription) !== '') {
        $fbLines[] = '';
        $fbLines[] = 'About This Job:';
        $fbLines[] = mb_strlen(trim($shareDescription)) > 300 ? mb_substr(trim($shareDescription), 0, 300) . '…' : trim($shareDescription);
    }

    $fbLines[] = '';
    $fbLines[] = 'For more information, visit our PHILCST Alumni Connect and login.';
    $fbLines[] = '#YourFutureStarsHere';
    $fbPostText = implode("\n", $fbLines);
@endphp

<div id="jm-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 jm-share-backdrop"
     x-data="{
         copied:false,
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         downloading:false,
         downloaded:false,
         shareText: {{ json_encode($fbPostText) }},
         jobTitle: {{ json_encode($shareJobTitle) }},
         imageUrl:  {{ json_encode($sharePhotoUrl) }},

         showDlConfirm: false,
         pendingTarget: null,

         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'job-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
         },

         async autoCopyCaption() {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(this.shareText);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch (e) { return false; }
         },

         async downloadImage() {
             if (!this.imageUrl) return false;
             this.downloading = true;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 const url  = URL.createObjectURL(blob);
                 const a = document.createElement('a');
                 a.href = url;
                 a.download = 'job-photo.' + ext;
                 document.body.appendChild(a);
                 a.click();
                 document.body.removeChild(a);
                 setTimeout(() => URL.revokeObjectURL(url), 4000);
                 this.downloading = false;
                 this.downloaded  = true;
                 setTimeout(() => this.downloaded = false, 4000);
                 return true;
             } catch (e) {
                 this.downloading = false;
                 return false;
             }
         },

         async nativeShare() {
             try {
                 const shareData = { title: this.jobTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user — nothing to do */ }
         },

         askShare(target) {
             if (this.nativeShareSupported) { this.nativeShare(); return; }
             this.pendingTarget = target;
             this.showDlConfirm = true;
         },

         async confirmDownloadThenGo() {
             await this.downloadImage();
             this.proceedToTarget();
         },

         proceedToTarget() {
             this.showDlConfirm = false;
             const target = this.pendingTarget;
             this.pendingTarget = null;
             if (target === 'facebook') this.openFacebook();
             else if (target === 'messenger') this.openMessenger();
         },

         cancelDlConfirm() {
             this.showDlConfirm = false;
             this.pendingTarget = null;
         },

         async openFacebook() {
             const copyOk = await this.autoCopyCaption();
             const w=680,h=560,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             const win = window.open(url, 'philcst_jm_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into the Facebook post box that just opened.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Facebook.'
             });
         },

         async openMessenger() {
             const copyOk = await this.autoCopyCaption();
             const win = window.open('https://www.messenger.com/new', 'philcst_jm_messenger_share', 'noopener,noreferrer');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into Messenger.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Messenger.'
             });
         },

         async copyLinkFn() {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(this.shareText); }
                 else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 this.copied = true; setTimeout(() => this.copied = false, 2500);
             } catch(e) { console.warn('Copy failed', e); }
         }
     }"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="if(showDlConfirm){cancelDlConfirm()}else{$wire.closeShareModal()}">

    <div class="jm-share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 jm-share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" type="button"
                    wire:loading.attr="disabled" wire:target="closeShareModal"
                    class="jm-share-close-btn" aria-label="Close">
                <span wire:loading.remove wire:target="closeShareModal">
                    <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 2L12 12M12 2L2 12"/>
                    </svg>
                </span>
                <span wire:loading wire:target="closeShareModal">
                    <i class="fas fa-spinner fa-spin text-xs"></i>
                </span>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-c">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                @if($sharePhotoUrl)
                <div class="jm-share-photo-preview">
                    <img src="{{ $sharePhotoUrl }}" alt="{{ $shareJobTitle }}"
                         onerror="this.style.display='none'">
                    <span class="dl-badge" x-show="downloading || downloaded" x-cloak>
                        <i class="fas" :class="downloading ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="downloading ? 'Downloading…' : 'Downloaded'"></span>
                    </span>
                </div>
                @endif

                <div class="rounded-xl border border-gray-200 flex-shrink-0">
                    <div class="px-4 py-3">
                        <p class="whitespace-pre-wrap leading-relaxed" style="font-size:clamp(11px,1vw,13px);color:#333333;">{{ rtrim(preg_replace('/#YourFutureStarsHere\s*$/', '', $fbPostText)) }}</p>
                        <p class="whitespace-pre-wrap leading-relaxed font-semibold mt-1" style="font-size:clamp(11px,1vw,13px);color:#1877F2;">#YourFutureStarsHere</p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto scroll-c">
                <p class="text-[10px] font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="jm-share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" class="jm-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Share on Facebook</span>
                </button>

                <button type="button" @click="askShare('messenger')" class="jm-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Send via Messenger</span>
                </button>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0" x-data="{ open: true }">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center gap-2.5 px-3 py-2.5 bg-[#F5F0FA] hover:bg-[#EFE6F7] active:scale-[.98] transition-all duration-150 cursor-pointer">
                        <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#7a3f91;">
                            <i class="fas fa-comments text-white text-xs"></i>
                        </span>
                        <span class="flex-1 text-left text-xs font-semibold" style="color:#333333;">Share to Alumni Batch Chats</span>
                        <i class="fas fa-chevron-down text-[10px] transition-transform" style="color:#7a3f91;" :class="open ? 'rotate-180' : ''"></i>
                    </button>

                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        @if(empty($shareAvailableRooms))
                            <p class="px-3 py-3 text-[11px]" style="color:#333333;">No chats available to share to yet.</p>
                        @else
                            @php
                                $shareAllIds = collect($shareAvailableRooms)->pluck('id')->map(fn($id) => (string) $id)->toArray();
                                $shareAllSelected = !empty($shareAllIds) && empty(array_diff($shareAllIds, $shareTargetRoomIds));

                                $shareTargetLabel = !empty($shareTargetCollegesList)
                                    ? implode(', ', $shareTargetCollegesList)
                                    : '';
                            @endphp

                            @if($shareTargetLabel !== '' && !empty($shareAutoRoomIds))
                            <div class="mx-3 mt-2.5 mb-0.5 px-2.5 py-1.5 rounded-lg flex items-center gap-1.5" style="background:#F5F0FA;">
                                <i class="fas fa-wand-magic-sparkles text-[10px]" style="color:#7a3f91;"></i>
                                <span class="text-[10.5px] leading-snug" style="color:#5c2d7a;">
                                    Auto-selected for this job's target: <span class="font-bold">{{ $shareTargetLabel }}</span>
                                </span>
                            </div>
                            @endif

                            <label class="flex items-center gap-2.5 px-3 py-2 border-t border-gray-100 cursor-pointer hover:bg-gray-50 active:bg-gray-100 transition-colors duration-100">
                                <input type="checkbox" wire:click="toggleSelectAllShareRooms"
                                       wire:key="share-select-all-{{ $shareAllSelected ? 'on' : 'off' }}"
                                       @checked($shareAllSelected)
                                       class="w-3.5 h-3.5 rounded accent-[#7a3f91] cursor-pointer flex-shrink-0">
                                <span class="text-[11px] font-bold uppercase tracking-wide" style="color:#7a3f91;">Select All</span>
                            </label>

                            <div class="max-h-40 overflow-y-auto scroll-c border-t border-gray-100">
                                @foreach($shareAvailableRooms as $r)
                                    @php $isAutoRoom = in_array((string) $r['id'], $shareAutoRoomIds, true); @endphp
                                    <label class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 active:bg-gray-100 transition-colors duration-100 {{ $isAutoRoom ? 'bg-[#FAF7FD]' : '' }}">
                                        <input type="checkbox" wire:model.live="shareTargetRoomIds" value="{{ $r['id'] }}"
                                               class="w-3.5 h-3.5 rounded accent-[#7a3f91] cursor-pointer flex-shrink-0">
                                        <span class="text-xs truncate flex-1" style="color:#333333;">{{ $r['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="px-3 py-2.5 border-t border-gray-100 bg-white">
                                <button type="button" wire:click="shareToAlumniChats"
                                        wire:loading.attr="disabled" wire:target="shareToAlumniChats"
                                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-white text-xs font-semibold cursor-pointer transition-all duration-150 active:scale-[.97] disabled:opacity-60 disabled:cursor-wait disabled:active:scale-100"
                                        style="background:#7a3f91;" onmouseover="this.style.background='#6a3280'" onmouseout="this.style.background='#7a3f91'">
                                    <span wire:loading.remove wire:target="shareToAlumniChats">
                                        <i class="fas fa-paper-plane text-[11px]"></i> Share ({{ count($shareTargetRoomIds) }})
                                    </span>
                                    <span wire:loading wire:target="shareToAlumniChats">
                                        <i class="fas fa-spinner fa-spin text-[11px]"></i> Sharing…
                                    </span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                <p class="text-[10px] text-center" style="color:#333333;">Sharing is available until the deadline passes.</p>
            </div>
        </div>

        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex-shrink-0">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5" style="color:#333333;"></i>
                <p class="text-xs leading-relaxed" style="color:#333333;">
                    The caption is copied to your clipboard automatically — just paste it (Ctrl+V)
                    into the Facebook or Messenger window that opens.
                </p>
            </div>
        </div>
    </div>

    {{-- ── PRE-SHARE "Download the photo?" CONFIRM MODAL ── --}}
    <div x-show="showDlConfirm" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[10010] flex items-center justify-center p-4 bg-black/55"
         @click.self="cancelDlConfirm()">
        <div class="jm-share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="jm-dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold" style="color:#333333;">Download the job photo?</p>
                    <p class="text-xs mt-1 leading-relaxed" style="color:#333333;">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($sharePhotoUrl)
            <div class="jm-share-photo-preview" style="height:110px;">
                <img src="{{ $sharePhotoUrl }}" alt="{{ $shareJobTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="jm-dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="jm-dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    // ── Live salary comma-formatter ──────────────────────────────────
    // Auto-inserts thousands separators into any run of 4+ digits as the
    // organizer types, WITHOUT forcing a strict numbers-only field — the
    // ₱/$ symbol, "/mo", "yearly", "Negotiable", etc. are all left alone.
    // Only the digit runs get commas; everything else in the string is
    // untouched and stays exactly where the user typed it.
    //
    // Cursor position is preserved by measuring how many digits sit to
    // the left of the caret before formatting, then walking the newly
    // formatted string forward that many digits to place the caret back
    // in the equivalent spot (so typing mid-string doesn't jump the
    // cursor to the end).
    function formatDigitRuns(raw) {
        // Match a whole "number block" — digits AND any commas already
        // sitting inside them — as ONE unit, not just raw consecutive
        // digits. Matching digits alone made a comma already inserted
        // mid-typing act as a hard break, so typing another digit right
        // after an existing comma (e.g. "4,555" -> "4,5555") re-grouped
        // only the piece after the comma and left the leading digit(s)
        // stranded — producing "4,5,555" instead of "45,555".
        return raw.replace(/[\d,]*\d[\d,]*/g, function (block) {
            var digitsOnly = block.replace(/,/g, '');
            if (digitsOnly.length < 4) return block; // too short to need grouping — leave as-is (and un-comma it)
            return digitsOnly.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        });
    }

    window.__eoFormatSalaryInput = function (el) {
        if (!el) return;
        var raw = el.value;
        var caret = el.selectionStart == null ? raw.length : el.selectionStart;

        // Count digits strictly before the caret in the RAW string.
        var digitsBeforeCaret = (raw.slice(0, caret).match(/\d/g) || []).length;

        var formatted = formatDigitRuns(raw);
        if (formatted === raw) return; // nothing changed, leave caret alone

        el.value = formatted;

        // Walk forward through the formatted string until we've passed
        // the same number of digits, landing the caret right after them.
        var seen = 0, pos = 0;
        if (digitsBeforeCaret > 0) {
            for (pos = 0; pos < formatted.length; pos++) {
                if (/\d/.test(formatted[pos])) {
                    seen++;
                    if (seen === digitsBeforeCaret) { pos++; break; }
                }
            }
        }
        el.setSelectionRange(pos, pos);

        // Keep Livewire's deferred model in sync with the formatted value
        // (wire:model.defer only reads on its own 'input'/'change' event,
        // and we didn't block that — this just makes sure el.value is
        // already correct by the time Livewire reads it).
    };

    // ── Deadline hard guard ─────────────────────────────────────────
    // The native <input type="date" min="..."> attribute blocks picking
    // a past/today date from the calendar UI, but some browsers still
    // let a date get typed in manually (keyboard entry into the M/D/Y
    // segments) that lands before "min" without the picker stopping it.
    // This clears the field immediately if that happens, so an invalid
    // date never sits in the input even before the form is submitted —
    // the server-side check is still the final source of truth, this is
    // just an earlier, friendlier catch.
    window.__eoGuardDeadlineInput = function (el) {
        if (!el || !el.value) return;
        var picked = new Date(el.value + 'T00:00:00');
        if (isNaN(picked.getTime())) return;

        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        if (picked.getTime() < tomorrow.getTime()) {
            el.value = '';
            // Brief visible red flash so it's clear the date got rejected
            // rather than the field just mysteriously going blank.
            el.classList.add('border-red-500', 'ring-2', 'ring-red-200');
            clearTimeout(el._eoDeadlineFlashTimer);
            el._eoDeadlineFlashTimer = setTimeout(function () {
                el.classList.remove('border-red-500', 'ring-2', 'ring-red-200');
            }, 1200);
        }
    };

    // ── Click anywhere in the date field to open the picker ────────────
    // A native <input type="date"> only opens its calendar when you click
    // the small icon on the right — clicking the text/box area just moves
    // the text cursor. This makes the WHOLE input act like the icon, so
    // one click anywhere in the box pops the picker open immediately.
    window.__eoOpenDatePicker = function (el) {
        if (!el) return;
        if (typeof el.showPicker === 'function') {
            try { el.showPicker(); } catch (e) { /* ignore — e.g. not user-triggered enough for some browsers */ }
        }
    };
})();
</script>

<script>
(function () {
    // ── Scroll to first invalid field on validation failure ────────────
    // The red summary banner at the top lists what's wrong, but on a long
    // form the actual field (with its own inline error message) can be
    // scrolled out of view — the person sees the banner and has to hunt
    // for the field. This scrolls the first invalid field into view and
    // focuses it as soon as Livewire dispatches 'scroll-to-first-error'.
    window.addEventListener('scroll-to-first-error', function () {
        // Wait a tick for Livewire to finish re-rendering the error classes
        // onto the fields before querying for them.
        setTimeout(function () {
            var firstBad = document.querySelector(
                '.border-red-400, .border-red-300'
            );
            if (!firstBad) return;
            firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstBad.focus === 'function') {
                try { firstBad.focus({ preventScroll: true }); } catch (e) { firstBad.focus(); }
            }
        }, 50);
    });
})();
</script>

<script>
(function () {
    var tip = document.getElementById('eo-hover-tip');

    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }

    function bindRows() {
        document.querySelectorAll('[data-eo-row]').forEach(function (row) {
            if (row._eoTipBound) return;
            row._eoTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip || !isHoverCapable()) return;
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

<script>
(function () {
    // ── Clean URL: strip ?highlight_job=ID from the address bar ─────────
    // Two different callers land here with ?highlight_job=ID in the URL:
    //   1) sidebar-organizer_blade.php's notification click handler
    //   2) organizer/chat-alumni.blade.php's shared "View Post" job card
    // mount() (server-side) already reads and consumes this value on page
    // load to open the matching job's View/Edit modal — the query string
    // itself is never needed again after that first render. Left in place,
    // though, it stays in the address bar and gets carried along on every
    // refresh/share/bookmark of this page, which is confusing since the
    // modal isn't what a plain revisit should show.
    //
    // history.replaceState swaps the URL in the address bar WITHOUT a
    // reload and WITHOUT adding a back-button entry — the already-open
    // modal (opened server-side during this same request) is untouched.
    if (window.location.search.indexOf('highlight_job') !== -1) {
        var url = new URL(window.location.href);
        url.searchParams.delete('highlight_job');
        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
})();
</script>

</div>