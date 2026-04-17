<?php
/**
 * FILE: resources/views/livewire/organizer/job-management.blade.php
 * RULES:
 *  - Organizer can EDIT a job regardless of status (ACTIVE or INACTIVE)
 *  - Organizer CANNOT change status — only Admin can activate/deactivate
 *  - saveEditJob preserves the current status, no auto-reactivation
 *  - Batch auto-inactive on deadline via SQL UPDATE (no dirty attr bug)
 *  - All create / edit / delete actions are written to the audit log
 *  - Share to Facebook: copies full post text to clipboard THEN opens FB
 *    (Facebook removed quote= support — clipboard+open is the fix)
 *
 * FIX v3:
 *   1. Both "Share on Facebook" and "Copy Link" now use the BASE jobs URL
 *      with NO job ID — e.g. https://alumniphilcst.com/jobs
 *   2. FB share: copies full job text to clipboard, then opens FB sharer
 *      with the base URL so FB renders the OG card for the jobs listing.
 *   3. NOTE: Facebook intentionally removed pre-filled text support on
 *      their sharer. The clipboard approach is the only clean workaround.
 *   4. APP_URL in .env must be your real domain:
 *        APP_URL=https://alumniphilcst.com
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\AuditLog;
use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public string $filterSort   = 'recent';

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

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    // ── Share modal ───────────────────────────────────────────────────────────
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
    // ─────────────────────────────────────────────────────────────────────────

    private array $expLevelOrder = [
        'No Experience Required',
        'Entry Level (At Least 1 Year)',
        'Mid Level (2-3 Years)',
        'Senior Level (4-5 Years)',
        'Expert Level (5+ Years)',
    ];

    // ── Auth / ownership guards ───────────────────────────────────────────────

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

    // ── Audit logging helper ──────────────────────────────────────────────────
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
        } catch (\Throwable) {
            // Silently swallow; logging must never cascade-fail to the UI
        }
    }

    // ── Mount ────────────────────────────────────────────────────────────────

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

    // ── Filter watchers ───────────────────────────────────────────────────────

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

    // ── Computed properties ───────────────────────────────────────────────────

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

    // ── Filters ───────────────────────────────────────────────────────────────

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterType = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    // ── Post modal ────────────────────────────────────────────────────────────

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

        if (!trim($this->postDescription)) $errors['postDescription'] = 'Job description is required.';

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
            'organizer_id'     => $org?->id,
            'job_title'        => $this->sanitize($this->postJobTitle),
            'company_name'     => $companyName,
            'company_type'     => $companyType,
            'location'         => $this->postOrgCategory === 'philcst' ? $this->philcstLocation : $this->sanitize($this->postLocation),
            'employment_type'  => $this->sanitize($this->postEmpType),
            'experience_level' => $this->sanitize($this->postExpLevel),
            'salary'           => $this->sanitize($this->postSalary) ?: null,
            'deadline'         => $this->postDeadline,
            'description'      => $this->sanitize($this->postDescription),
            'target_college'   => implode(',', $this->postTargetColleges) ?: null,
            'status'           => 'ACTIVE',
            'updated_by'       => auth()->user()->name,
            'updated_by_role'  => 'organizer',
        ]);

        $this->logAudit(
            action:       'created',
            subjectLabel: $job->job_title,
            description:  sprintf(
                'Organizer created job posting "%s" at %s (%s) — %s, deadline %s.',
                $job->job_title,
                $job->company_name,
                $job->employment_type,
                $job->experience_level,
                \Carbon\Carbon::parse($job->deadline)->format('M j, Y')
            ),
            newValues: [
                'job_title'        => $job->job_title,
                'company_name'     => $job->company_name,
                'company_type'     => $job->company_type,
                'location'         => $job->location,
                'employment_type'  => $job->employment_type,
                'experience_level' => $job->experience_level,
                'salary'           => $job->salary ?? 'Not disclosed',
                'deadline'         => $job->deadline,
                'target_college'   => $job->target_college,
                'status'           => $job->status,
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
        $this->postTargetColleges = [];
        $this->postErrors = [];
    }

    // ── View modal ────────────────────────────────────────────────────────────

    public function viewJob(int $id): void  { $this->guardAuth(); $this->viewingJobId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void  { $this->showViewModal = false; $this->viewingJobId = null; }

    // ── Edit modal ────────────────────────────────────────────────────────────

    public function openEditModal(int $id): void
    {
        $this->guardAuth();
        $job = app(JobController::class)->getJob($id);
        $this->guardOwnership($job);

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
        $this->editTargetColleges = !empty($job->target_college)
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

        if (!trim($this->editDescription)) $errors['editDescription'] = 'Job description is required.';

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
            'job_title'        => $job->job_title,
            'company_name'     => $job->company_name,
            'company_type'     => $job->company_type,
            'location'         => $job->location,
            'employment_type'  => $job->employment_type,
            'experience_level' => $job->experience_level,
            'salary'           => $job->salary ?? 'Not disclosed',
            'deadline'         => $job->deadline,
            'target_college'   => $job->target_college,
        ];

        $job->update([
            'job_title'        => $this->sanitize($this->editJobTitle),
            'company_name'     => $this->sanitize($this->editCompany),
            'company_type'     => $this->sanitize($this->editCompanyType),
            'location'         => $this->sanitize($this->editLocation),
            'employment_type'  => $this->sanitize($this->editEmpType),
            'experience_level' => $this->sanitize($this->editExpLevel),
            'salary'           => $this->sanitize($this->editSalary) ?: null,
            'deadline'         => $this->editDeadline,
            'description'      => $this->sanitize($this->editDescription),
            'target_college'   => implode(',', $this->editTargetColleges) ?: null,
            // 'status' intentionally omitted — preserved as-is, Admin controls this
            'updated_by'       => auth()->user()->name,
            'updated_by_role'  => 'organizer',
        ]);

        $after = [
            'job_title'        => $this->sanitize($this->editJobTitle),
            'company_name'     => $this->sanitize($this->editCompany),
            'company_type'     => $this->sanitize($this->editCompanyType),
            'location'         => $this->sanitize($this->editLocation),
            'employment_type'  => $this->sanitize($this->editEmpType),
            'experience_level' => $this->sanitize($this->editExpLevel),
            'salary'           => $this->sanitize($this->editSalary) ?: 'Not disclosed',
            'deadline'         => $this->editDeadline,
            'target_college'   => implode(',', $this->editTargetColleges) ?: null,
        ];

        $this->logAudit(
            action:       'updated',
            subjectLabel: $this->sanitize($this->editJobTitle),
            description:  sprintf(
                'Organizer updated job posting "%s" (ID #%d). Status preserved as %s.',
                $this->sanitize($this->editJobTitle),
                $job->id,
                $job->status
            ),
            oldValues: $before,
            newValues: $after,
            severity:  'info'
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
        $this->editTargetColleges = [];
        $this->editErrors = [];
    }

    // ── Delete modal ──────────────────────────────────────────────────────────

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
                    'Organizer deleted job posting "%s" (ID #%d) at %s. Status set to ORGANIZER_DELETED. Admin can restore.',
                    $snapshot['job_title'],
                    $job->id,
                    $snapshot['company_name']
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

    // ── Share modal ───────────────────────────────────────────────────────────

    public function openShareModal(int $id): void
    {
        $this->guardAuth();
        $job = JobPosting::findOrFail($id);
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

    /**
     * Base jobs listing URL — used for BOTH Share on Facebook and Copy Link.
     * No job ID, just https://alumniphilcst.com/jobs
     */
    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try {
            $path = route('jobs.index', [], false);
        } catch (\Throwable) {
            $path = '/jobs';
        }
        return $base . $path;
    }
    // ─────────────────────────────────────────────────────────────────────────
};
?>

<div class="min-h-screen bg-gray-50">

{{-- FLASH TOAST --}}
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
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-6 pb-8 max-w-screen-2xl mx-auto">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl bg-[#7a3f91] flex items-center justify-center shadow-lg flex-shrink-0"
                 style="box-shadow:0 4px 14px rgba(122,63,145,.35);">
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
                class="bg-[#7a3f91] text-white shadow-md hover:bg-[#5e2f72] active:shadow-sm transition-all inline-flex items-center gap-2 px-5 py-3 rounded-xl font-bold text-sm shrink-0 cursor-pointer">
            <i class="fas fa-plus text-sm"></i> Post a Job
        </button>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden"
         style="min-height:0; height:calc(100vh - 210px);">

        {{-- FILTER BAR --}}
        <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] sm:min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 text-gray-800 transition"
                       autocomplete="off">
            </div>
            <select wire:model.live="filterStatus"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 text-gray-700 transition">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>
            <select wire:model.live="filterType"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 text-gray-700 hidden sm:block transition">
                <option value="">All Employment Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 text-gray-700 hidden sm:block transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters"
                    class="bg-white text-gray-600 border border-gray-200 hover:bg-gray-50 hover:border-gray-300 transition px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5 cursor-pointer">
                <i class="fas fa-rotate-left text-xs"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Mobile row 2 --}}
        <div class="px-4 py-2 border-b border-gray-100 bg-gray-50/80 flex gap-2 sm:hidden">
            <select wire:model.live="filterType"
                    class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] text-gray-700 transition">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort"
                    class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:border-[#7a3f91] text-gray-700 transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- TABLE --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 wire:loading.class="opacity-45 pointer-events-none"
                 wire:target="search,filterStatus,filterType,filterSort,resetFilters,previousPage,nextPage,executeDelete">
                <table class="w-full border-collapse min-w-[640px]">
                    <thead>
                        <tr class="bg-gray-100 border-b-2 border-gray-200 sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Job Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Organization</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Employment Type</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden lg:table-cell">Deadline</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden xl:table-cell">Posted By</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($this->jobPostings as $job)
                        @php
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $isAdminPosted    = is_null($job->organizer_id);
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $isInactive       = $job->status === 'INACTIVE';
                            $shouldBeOrange   = $isDeadlinePassed || $isInactive;
                        @endphp
                        <tr class="transition-colors {{ $shouldBeOrange ? 'bg-orange-50 hover:bg-orange-100' : 'hover:bg-purple-50' }}">
                            <td class="px-4 sm:px-5 py-3.5 max-w-[160px] sm:max-w-[200px]">
                                <p class="font-semibold text-sm truncate {{ $shouldBeOrange ? 'text-orange-900' : 'text-gray-900' }}">
                                    {{ $job->job_title }}
                                </p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 max-w-[150px]">
                                <p class="font-semibold text-sm truncate {{ $shouldBeOrange ? 'text-orange-800' : 'text-gray-800' }}">
                                    {{ $job->company_name }}
                                </p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden md:table-cell">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold
                                    {{ $shouldBeOrange ? 'bg-orange-50 text-orange-700 border border-orange-200' : 'bg-purple-50 text-purple-700 border border-purple-100' }}">
                                    {{ $job->employment_type }}
                                </span>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden lg:table-cell whitespace-nowrap">
                                <span class="text-sm font-semibold {{ $shouldBeOrange ? 'text-orange-800' : 'text-gray-800' }}">
                                    {{ $dl->format('M d, Y') }}
                                </span>
                                @if($isDeadlinePassed)
                                    <span class="block text-[10px] text-red-600 font-bold">Deadline passed</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden xl:table-cell whitespace-nowrap">
                                @if($isAdminPosted)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold
                                        {{ $shouldBeOrange ? 'bg-orange-50 text-orange-700 border border-orange-200' : 'bg-purple-50 text-purple-700 border border-purple-100' }}">
                                        <i class="fas fa-shield-halved text-[8px]"></i> Admin
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold
                                        {{ $shouldBeOrange ? 'bg-orange-50 text-orange-700 border border-orange-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                                        <i class="fas fa-check text-[8px]"></i> You
                                    </span>
                                @endif
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
                                    {{-- VIEW --}}
                                    <button wire:click="viewJob({{ $job->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-100 text-purple-700 border border-purple-300 hover:bg-white hover:border-purple-500 transition cursor-pointer">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden sm:inline">View</span>
                                    </button>
                                    {{-- SHARE --}}
                                    <button wire:click="openShareModal({{ $job->id }})"
                                            title="Share this job"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-sky-100 text-sky-700 border border-sky-300 hover:bg-white hover:border-sky-500 transition cursor-pointer">
                                        <i class="fas fa-share-nodes text-xs"></i>
                                        <span class="hidden sm:inline">Share</span>
                                    </button>
                                    @if(!$isAdminPosted)
                                        {{-- EDIT --}}
                                        <button wire:click="openEditModal({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-blue-100 text-blue-700 border border-blue-300 hover:bg-white hover:border-blue-500 transition cursor-pointer">
                                            <i class="fas fa-pen-to-square text-xs"></i>
                                            <span class="hidden sm:inline">Edit</span>
                                        </button>
                                        {{-- DELETE --}}
                                        <button wire:click="confirmDelete({{ $job->id }})"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-red-100 text-red-700 border border-red-300 hover:bg-white hover:border-red-500 transition cursor-pointer">
                                            <i class="fas fa-trash text-xs"></i>
                                            <span class="hidden lg:inline">Delete</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-briefcase text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="font-semibold text-gray-500">No job postings found</p>
                                    <p class="text-sm text-gray-400">
                                        @if($search || $filterStatus || $filterType)
                                            Try adjusting your filters.
                                        @else
                                            No postings yet. Click <strong>Post a Job</strong> to create one.
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

        {{-- PAGINATION FOOTER --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-100 bg-[#2b0d3e] shrink-0 rounded-b-2xl">
            @php
                $total = $this->jobPostings->total();
                $pp    = $this->jobPostings->perPage();
                $cp    = $this->jobPostings->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-white text-xs sm:text-sm">
                    Showing <span class="font-bold">{{ $from }}&ndash;{{ $to }}</span>
                    of <span class="font-bold">{{ $total }}</span> jobs
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->jobPostings->onFirstPage())
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">&larr; Prev</button>
                    @else
                        <button wire:click="previousPage" class="bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold cursor-pointer">&larr; Prev</button>
                    @endif
                    <span class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold shadow-sm">
                        {{ $cp }} / {{ $this->jobPostings->lastPage() }}
                    </span>
                    @if($this->jobPostings->hasMorePages())
                        <button wire:click="nextPage" class="bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold cursor-pointer">Next &rarr;</button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next &rarr;</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- POST MODAL --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closePostModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col overflow-hidden relative">
        <button wire:click="closePostModal" type="button" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-white/20 transition text-white cursor-pointer"><i class="fas fa-xmark text-lg"></i></button>
        <div class="flex items-center px-7 py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-xl font-extrabold text-white flex items-center gap-3"><i class="fas fa-briefcase"></i> Post a New Job</h2>
        </div>
        @if(count($postErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($postErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto px-7 py-6 space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                       class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postJobTitle'])?'border-red-400 bg-red-50':'' }}">
                @if(isset($postErrors['postJobTitle']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
            </div>
            <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 px-5 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-building text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Organization Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-2 tracking-wider">Category <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <button type="button" wire:click="$set('postOrgCategory','philcst')"
                                    class="flex-1 px-3 py-3 border-2 rounded-lg bg-white cursor-pointer transition text-center text-xs font-bold flex flex-col items-center gap-1.5 {{ $postOrgCategory==='philcst'?'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md':'border-gray-200 text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50' }}">
                                <i class="fas fa-school text-lg"></i><span>PHILCST Campus</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','partner')"
                                    class="flex-1 px-3 py-3 border-2 rounded-lg bg-white cursor-pointer transition text-center text-xs font-bold flex flex-col items-center gap-1.5 {{ $postOrgCategory==='partner'?'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md':'border-gray-200 text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50' }}">
                                <i class="fas fa-handshake text-lg"></i><span>Partner Company</span>
                            </button>
                            <button type="button" wire:click="$set('postOrgCategory','custom')"
                                    class="flex-1 px-3 py-3 border-2 rounded-lg bg-white cursor-pointer transition text-center text-xs font-bold flex flex-col items-center gap-1.5 {{ $postOrgCategory==='custom'?'border-[#7a3f91] bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white shadow-md':'border-gray-200 text-gray-600 hover:border-[#7a3f91] hover:text-[#7a3f91] hover:bg-purple-50' }}">
                                <i class="fas fa-pen-to-square text-lg"></i><span>Other / Custom</span>
                            </button>
                        </div>
                        @if(isset($postErrors['postOrgCategory']))<p class="text-red-600 text-xs mt-2 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postOrgCategory'] }}</p>@endif
                    </div>
                    @if($postOrgCategory==='philcst')
                        @if($philcstName)
                        <div class="bg-purple-50 border-2 border-purple-300 rounded-lg px-4 py-3 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#6a3580] text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-school text-sm"></i></div>
                            <div class="flex-1"><div class="text-sm font-bold text-[#4c1d95]">PHILCST</div>@if($philcstLocation)<div class="text-xs mt-0.5 text-[#7c3aed]"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif</div>
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-700 bg-white border border-purple-300 px-2.5 py-1 rounded-full shrink-0"><i class="fas fa-lock text-[9px]"></i> Auto-filled</span>
                        </div>
                        @endif
                    @elseif($postOrgCategory==='partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),syncName(v){$wire.set('postPartnerName',v,false)},syncType(v){$wire.set('postPartnerType',v,false)}}">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncName(pName)" type="text" placeholder="e.g. Acme Corporation" maxlength="150" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postPartnerName'])?'border-red-400 bg-red-50':'' }}">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncType(pType)" type="text" placeholder="e.g. Private Company, NGO" maxlength="100" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postPartnerType'])?'border-red-400 bg-red-50':'' }}">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postPartnerName))
                        <div class="bg-blue-50 border-2 border-blue-200 rounded-lg px-4 py-3 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-blue-600 text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-handshake text-sm"></i></div>
                            <div><div class="text-sm font-bold text-blue-900">{{ $postPartnerName }}</div>@if(trim($postPartnerType))<div class="text-xs text-blue-700 mt-0.5">{{ $postPartnerType }}</div>@endif@if(trim($postLocation))<div class="text-xs text-gray-600 mt-0.5"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif</div>
                        </div>
                        @endif
                    @elseif($postOrgCategory==='custom')
                        <div wire:ignore x-data="{cName:@js($postCustomName),cType:@js($postCustomType),syncName(v){$wire.set('postCustomName',v,false)},syncType(v){$wire.set('postCustomType',v,false)}}">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Organization Name <span class="text-red-500">*</span></label>
                                    <input x-model="cName" @input.debounce.300ms="syncName(cName)" type="text" placeholder="e.g. Department of Labor" maxlength="150" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postCustomName'])?'border-red-400 bg-red-50':'' }}">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Organization Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncType(cType)" type="text" placeholder="e.g. Government Agency, NGO" maxlength="100" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postCustomType'])?'border-red-400 bg-red-50':'' }}">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                            </div>
                        </div>
                        <div wire:ignore x-data="{loc:@js($postLocation),syncLoc(v){$wire.set('postLocation',v,false)}}">
                            <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Location <span class="text-red-500">*</span></label>
                            <input x-model="loc" @input.debounce.300ms="syncLoc(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postLocation'])?'border-red-400 bg-red-50':'' }}">
                            @if(isset($postErrors['postLocation']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postLocation'] }}</p>@endif
                        </div>
                        @if(trim($postCustomName))
                        <div class="bg-gray-50 border-2 border-gray-300 rounded-lg px-4 py-3 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-gray-600 text-white flex items-center justify-center flex-shrink-0"><i class="fas fa-pen-to-square text-sm"></i></div>
                            <div><div class="text-sm font-bold text-gray-900">{{ $postCustomName }}</div>@if(trim($postCustomType))<div class="text-xs text-gray-700 mt-0.5">{{ $postCustomType }}</div>@endif@if(trim($postLocation))<div class="text-xs text-gray-600 mt-0.5"><i class="fas fa-location-dot mr-1"></i>{{ $postLocation }}</div>@endif</div>
                        </div>
                        @endif
                    @else
                        <div class="text-center py-5 text-gray-400 text-sm"><i class="fas fa-arrow-up text-gray-300 text-xl block mb-2"></i>Select a category above to continue.</div>
                    @endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postEmpType" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postEmpType'])?'border-red-400 bg-red-50':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)<option value="{{ $opt->label }}">{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($postErrors['postEmpType']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="postExpLevel" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postExpLevel'])?'border-red-400 bg-red-50':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)<option value="{{ $lvl }}">{{ $lvl }}</option>@endforeach
                    </select>
                    @if(isset($postErrors['postExpLevel']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postExpLevel'] }}</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Salary <span class="text-gray-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="postSalary" type="text" placeholder="e.g. &#8369;25,000 &ndash; &#8369;35,000 / month" maxlength="100" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition">
                    <p class="text-gray-400 text-xs mt-1"><i class="fas fa-circle-info mr-1"></i>Leave blank if not disclosed.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="postDeadline" type="date" min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($postErrors['postDeadline'])?'border-red-400 bg-red-50':'' }}">
                    @if(isset($postErrors['postDeadline']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Target College <span class="text-red-500">*</span></label>
                <div class="border-2 border-blue-200 rounded-lg p-4 bg-blue-50">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-lock text-blue-500 text-sm"></i>
                        <span class="text-sm font-bold text-blue-900">{{ $this->organizerCollege ?? 'Your College' }}</span>
                        <span class="text-xs text-blue-600 bg-white border border-blue-200 px-2 py-0.5 rounded-full font-bold">Auto-selected</span>
                    </div>
                    <p class="text-xs text-blue-700"><i class="fas fa-info-circle mr-1"></i>You can only post jobs for your own college's alumni.</p>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="postDescription" rows="6" placeholder="Describe the role, responsibilities, qualifications&hellip;" maxlength="5000"
                          class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition resize-none {{ isset($postErrors['postDescription'])?'border-red-400 bg-red-50':'' }}"></textarea>
                @if(isset($postErrors['postDescription']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $postErrors['postDescription'] }}</p>@endif
            </div>
        </div>
        <div class="px-7 py-5 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex gap-3">
            <button wire:click="closePostModal" class="flex-1 px-4 py-3 bg-white text-gray-700 border border-gray-200 rounded-xl text-sm font-bold hover:bg-gray-50 transition cursor-pointer">Cancel</button>
            <button wire:click="savePost" wire:loading.attr="disabled" wire:target="savePost"
                    class="flex-1 px-4 py-3 bg-[#7a3f91] text-white rounded-xl text-sm font-extrabold hover:bg-[#5e2f72] disabled:opacity-50 transition shadow-md flex items-center justify-center gap-2 cursor-pointer">
                <span wire:loading wire:target="savePost"><i class="fas fa-spinner animate-spin"></i> Saving&hellip;</span>
                <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane"></i> Post Job</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- VIEW MODAL --}}
@if($showViewModal && $this->viewingJob)
@php
    $job            = $this->viewingJob;
    $dl             = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $isExp          = now('Asia/Manila')->gt($dl);
    $createdPH      = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $viewDepts      = $job->target_college ? \App\Models\Course::whereIn('college', explode(',', $job->target_college))->orderBy('code')->pluck('code')->toArray() : [];
    $displayType    = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $isAdminPosted  = is_null($job->organizer_id);
    $isInactiveView = $job->status === 'INACTIVE';
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closeViewModal">
    <div class="bg-white rounded-2xl shadow-2xl flex flex-col w-full max-w-2xl max-h-[92vh] overflow-hidden relative">
        <button wire:click="closeViewModal" type="button" class="absolute top-4 right-4 w-7 h-7 rounded-full hover:bg-gray-100 transition text-gray-500 hover:text-gray-800 flex items-center justify-center cursor-pointer"><i class="fas fa-xmark text-base"></i></button>
        <div class="px-8 py-6 border-b border-gray-200 flex-shrink-0">
            <div class="text-2xl font-extrabold text-gray-900 mb-2 pr-8">{{ $job->job_title }}</div>
            <div class="flex items-center gap-2 mb-4 flex-wrap">
                <strong class="text-gray-800">{{ $job->company_name }}</strong>
                <span class="text-xs font-bold bg-purple-100 text-purple-700 px-2 py-0.5 rounded">{{ $displayType }}</span>
                @if($job->status === 'ACTIVE')
                    <span class="text-xs font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded">Active</span>
                @else
                    <span class="text-xs font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Inactive</span>
                @endif
                @if($isInactiveView)
                    <span class="text-xs font-bold bg-red-50 text-red-600 border border-red-200 px-2 py-0.5 rounded inline-flex items-center gap-1">
                        <i class="fas fa-shield-halved text-[9px]"></i> Only Admin can activate
                    </span>
                @endif
            </div>
            <ul class="space-y-2">
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-location-dot text-xs"></i></span><span>{{ $job->location ?? 'Not specified' }}</span></li>
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-clock text-xs"></i></span><span>{{ $job->employment_type }}</span></li>
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-layer-group text-xs"></i></span><span>{{ $job->experience_level }}</span></li>
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-money-bill-wave text-xs"></i></span>
                    @if($job->salary)<span>{{ $job->salary }}</span>@else<span class="text-gray-500 italic">Salary not disclosed</span>@endif
                </li>
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-calendar-xmark text-xs"></i></span>
                    <span>Deadline: {{ $dl->format('F d, Y') }}
                        @if($isExp)<span class="font-bold text-red-700 ml-1">(Passed)</span>
                        @else<span class="text-gray-500 ml-1">&middot; {{ $dl->diffForHumans() }}</span>@endif
                    </span>
                </li>
                @if($job->target_college)
                <li class="flex items-start gap-2.5 text-sm text-gray-800"><span class="text-[#7a3f91] mt-0.5 flex-shrink-0"><i class="fas fa-building-columns text-xs"></i></span><span>For: {{ str_replace(',', ', ', $job->target_college) }}</span></li>
                @endif
            </ul>
            <p class="text-xs text-gray-500 mt-3">Posted {{ $createdPH->diffForHumans() }} &middot; by {{ $isAdminPosted ? 'Admin' : 'You' }}</p>
        </div>
        <div class="flex-1 overflow-y-auto">
            <div class="px-8 py-5 border-b border-gray-200">
                <div class="text-sm font-bold text-gray-900 mb-3">Job Description</div>
                <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $job->description }}</div>
            </div>
            @if($job->target_college && count($viewDepts))
            <div class="px-8 py-5 border-b border-gray-200">
                <div class="text-sm font-bold text-gray-900 mb-3">Target Colleges</div>
                <div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
                    <div class="text-sm font-bold text-gray-900">{{ str_replace(',', ', ', $job->target_college) }}</div>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        @foreach($viewDepts as $dc)<span class="text-xs font-bold font-mono bg-white border border-purple-300 text-purple-700 px-2 py-1 rounded">{{ $dc }}</span>@endforeach
                    </div>
                </div>
            </div>
            @endif
            <div class="px-8 py-5">
                <div class="text-xs font-bold text-gray-500 uppercase mb-3 tracking-wider">Posting Details</div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="grid grid-cols-3 divide-x divide-gray-200">
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Posted On</div>
                            <div class="text-sm font-bold text-gray-900">{{ $createdPH->format('M d, Y') }}</div>
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Posted By</div>
                            @if($isAdminPosted)<div class="text-sm font-bold text-gray-900">Admin</div><div class="text-xs text-gray-500">System admin</div>
                            @else<div class="text-sm font-bold text-gray-900">You</div><div class="text-xs text-gray-500">Organization</div>@endif
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs font-bold text-gray-500 uppercase mb-1 tracking-wider">Deadline</div>
                            <div class="text-sm font-bold {{ $isExp?'text-red-700':'text-gray-900' }}">{{ $dl->format('M d, Y') }}</div>
                            <div class="text-xs {{ $isExp?'text-red-600':'text-gray-500' }}">{{ $isExp?'Passed':$dl->diffForHumans() }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-8 py-4 border-t border-gray-200 bg-gray-50 flex-shrink-0 flex items-center justify-end gap-2">
            <button wire:click="closeViewModal" type="button" class="px-4 py-2.5 bg-white text-gray-700 border border-gray-200 rounded-lg text-sm font-bold hover:bg-gray-50 transition cursor-pointer"><i class="fas fa-xmark text-xs mr-1"></i> Close</button>
            <button wire:click="openShareModal({{ $job->id }})" type="button"
                    class="px-4 py-2.5 bg-sky-100 text-sky-700 border border-sky-300 rounded-lg text-sm font-bold hover:bg-white hover:border-sky-500 transition cursor-pointer">
                <i class="fas fa-share-nodes text-xs mr-1"></i> Share
            </button>
            @if(!$isAdminPosted)
                <button wire:click="confirmDelete({{ $job->id }})" type="button" class="px-4 py-2.5 bg-red-100 text-red-700 border border-red-300 rounded-lg text-sm font-bold hover:bg-white hover:border-red-500 transition cursor-pointer"><i class="fas fa-trash text-xs mr-1"></i> Delete</button>
                <button wire:click="openEditModal({{ $job->id }})" type="button" class="px-4 py-2.5 bg-blue-100 text-blue-700 border border-blue-300 rounded-lg text-sm font-bold hover:bg-white hover:border-blue-500 transition cursor-pointer"><i class="fas fa-pen-to-square text-xs mr-1"></i> Edit</button>
            @else
                <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold text-gray-500 bg-gray-100 rounded-lg border border-gray-200"><i class="fas fa-lock text-xs"></i> Posted by Admin</span>
            @endif
        </div>
    </div>
</div>
@endif

{{-- EDIT MODAL --}}
@if($showEditModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="closeEditModal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col overflow-hidden relative">
        <button wire:click="closeEditModal" type="button" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-white/20 transition text-white cursor-pointer"><i class="fas fa-xmark text-lg"></i></button>
        <div class="flex items-center px-7 py-5 bg-[#7a3f91] flex-shrink-0">
            <h2 class="text-xl font-extrabold text-white flex items-center gap-3"><i class="fas fa-pen-to-square"></i> Edit Job Posting</h2>
        </div>
        @php $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null; @endphp
        @if($editingJob && $editingJob->status === 'INACTIVE')
        <div class="bg-amber-50 border-b border-amber-200 px-7 py-3 flex-shrink-0 flex items-center gap-3">
            <i class="fas fa-shield-halved text-amber-500 text-sm flex-shrink-0"></i>
            <p class="text-amber-800 text-xs font-semibold">
                This job is currently <strong>Inactive</strong>. You can edit the details, but only an Admin can re-activate it.
            </p>
        </div>
        @endif
        @if(count($editErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($editErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">&bull;</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto px-7 py-6 space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Job Title <span class="text-red-500">*</span></label>
                <input wire:model.defer="editJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editJobTitle'])?'border-red-400 bg-red-50':'' }}">
                @if(isset($editErrors['editJobTitle']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editJobTitle'] }}</p>@endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Organization Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="editCompanyType" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editCompanyType'])?'border-red-400 bg-red-50':'' }}">
                        <option value="">Select Organization</option>
                        @foreach($this->jobOptions->get('company_type', collect()) as $opt)<option value="{{ $opt->label }}" @selected($editCompanyType===$opt->label)>{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editCompanyType']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompanyType'] }}</p>@endif
                </div>
                <div>
                    @php $editIsPhilcst = str_contains(strtoupper($editCompanyType), 'PHILCST'); @endphp
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Company Name <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editCompany" type="text" maxlength="150" @if($editIsPhilcst) readonly @endif class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editCompany'])?'border-red-400 bg-red-50':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-500 cursor-not-allowed':'' }}">
                    @if(isset($editErrors['editCompany']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editCompany'] }}</p>@endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Location <span class="text-red-500">*</span></label>
                <input wire:model="editLocation" type="text" maxlength="120" @if($editIsPhilcst) readonly @endif class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editLocation'])?'border-red-400 bg-red-50':'' }} {{ $editIsPhilcst?'bg-gray-100 text-gray-500 cursor-not-allowed':'' }}">
                @if(isset($editErrors['editLocation']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editLocation'] }}</p>@endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Employment Type <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editEmpType" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editEmpType'])?'border-red-400 bg-red-50':'' }}">
                        <option value="">Select Employment Type</option>
                        @foreach($this->jobOptions->get('employment_type', collect()) as $opt)<option value="{{ $opt->label }}" @selected($editEmpType===$opt->label)>{{ $opt->label }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editEmpType']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editEmpType'] }}</p>@endif
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Experience Level <span class="text-red-500">*</span></label>
                    <select wire:model.defer="editExpLevel" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editExpLevel'])?'border-red-400 bg-red-50':'' }}">
                        <option value="">Select Experience Level</option>
                        @foreach($this->orderedExpLevels as $lvl)<option value="{{ $lvl }}" @selected($editExpLevel===$lvl)>{{ $lvl }}</option>@endforeach
                    </select>
                    @if(isset($editErrors['editExpLevel']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editExpLevel'] }}</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Salary <span class="text-gray-400 font-normal">(Optional)</span></label>
                    <input wire:model.defer="editSalary" type="text" maxlength="100" placeholder="e.g. &#8369;25,000 &ndash; &#8369;35,000 / month" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Application Deadline <span class="text-red-500">*</span></label>
                    <input wire:model.defer="editDeadline" type="date" min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition {{ isset($editErrors['editDeadline'])?'border-red-400 bg-red-50':'' }}">
                    @if(isset($editErrors['editDeadline']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDeadline'] }}</p>@endif
                    <p class="text-[11px] text-amber-600 mt-1"><i class="fas fa-shield-halved mr-1"></i>Status is managed by Admin only.</p>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Target College <span class="text-red-500">*</span></label>
                <div class="border-2 border-blue-200 rounded-lg p-4 bg-blue-50">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-lock text-blue-500 text-sm"></i>
                        <span class="text-sm font-bold text-blue-900">{{ $this->organizerCollege ?? 'Your College' }}</span>
                        <span class="text-xs text-blue-600 bg-white border border-blue-200 px-2 py-0.5 rounded-full font-bold">Auto-selected</span>
                    </div>
                    <p class="text-xs text-blue-700"><i class="fas fa-info-circle mr-1"></i>You can only post jobs for your own college's alumni.</p>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5 tracking-wider">Job Description <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="editDescription" rows="7" maxlength="5000" class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-purple-200 transition resize-none {{ isset($editErrors['editDescription'])?'border-red-400 bg-red-50':'' }}"></textarea>
                @if(isset($editErrors['editDescription']))<p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $editErrors['editDescription'] }}</p>@endif
            </div>
        </div>
        <div class="px-7 py-5 border-t border-gray-100 bg-gray-50 flex-shrink-0 flex gap-3">
            <button wire:click="closeEditModal" class="flex-1 px-4 py-3 bg-white text-gray-700 border border-gray-200 rounded-xl text-sm font-bold hover:bg-gray-50 transition cursor-pointer">Cancel</button>
            <button wire:click="saveEditJob" wire:loading.attr="disabled" wire:target="saveEditJob"
                    class="flex-1 px-4 py-3 bg-[#7a3f91] text-white rounded-xl text-sm font-extrabold hover:bg-[#5e2f72] disabled:opacity-50 transition shadow-md flex items-center justify-center gap-2 cursor-pointer">
                <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner animate-spin"></i> Saving&hellip;</span>
                <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk"></i> Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- DELETE MODAL --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" wire:keydown.escape="cancelDelete">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 bg-red-50 border-b border-red-200">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center"><i class="fas fa-triangle-exclamation text-red-600 text-sm"></i></div>
                Delete Job Posting
            </h2>
        </div>
        <div class="p-6">
            <p class="text-gray-700 text-sm mb-1">You are about to delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $deleteJobTitle }}"</p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 text-xs text-gray-700 flex items-start gap-2">
                <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span>The job will be removed from your list. <strong>Admin can still see and restore it</strong> if needed.</span>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-4 py-3 bg-white text-gray-700 border border-gray-200 rounded-lg text-sm font-bold hover:bg-gray-50 transition cursor-pointer">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-4 py-3 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-lg text-sm font-extrabold flex items-center justify-center gap-2 transition shadow-md cursor-pointer">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════════
     SHARE MODAL v3
     ─────────────────────────────────────────────────────────────────────────
     CHANGES FROM v2:
       • Both FB sharer URL and Copy Link now use the BASE jobs URL (no ID).
         → https://alumniphilcst.com/jobs  (never /jobs/22 or any number)
       • jobPublicUrl() method removed — only jobsBaseUrl() is used.
       • FB pre-filled text is technically impossible (Facebook blocked it).
         The clipboard approach remains the cleanest available workaround.
     ─────────────────────────────────────────────────────────────────────────
     ⚠️  Make sure APP_URL in .env = your real domain:
           APP_URL=https://alumniphilcst.com
═══════════════════════════════════════════════════════════════════════════ --}}
@if($showShareModal)
@php
    // ── Single base URL for everything — NO job ID ────────────────────────────
    $shareBaseUrl  = $this->jobsBaseUrl();  // e.g. https://alumniphilcst.com/jobs

    // ── Deadline formatted ────────────────────────────────────────────────────
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';

    // ── Description preview (max 120 chars) ───────────────────────────────────
    $shareDescPreview = mb_strlen($shareDescription) > 120
        ? mb_substr($shareDescription, 0, 120) . '…'
        : $shareDescription;

    // ── Full post text — copied to clipboard when FB button is clicked ─────────
    // Facebook no longer supports quote= so we copy it ourselves first.
    $fbPostLines   = [];
    $fbPostLines[] = "🎯 Job Opening: {$shareJobTitle}";
    $fbPostLines[] = "🏢 {$shareCompany}";
    if ($shareLocation)    $fbPostLines[] = "📍 {$shareLocation}";
    if ($shareEmpType)     $fbPostLines[] = "💼 {$shareEmpType}";
    if ($shareExpLevel)    $fbPostLines[] = "📊 {$shareExpLevel}";
    if ($shareSalary)      $fbPostLines[] = "💰 {$shareSalary}";
    if ($shareDlFormatted) $fbPostLines[] = "📅 Deadline: {$shareDlFormatted}";
    if ($shareCollege)     $fbPostLines[] = "🏫 For: {$shareCollege}";
    $fbPostLines[] = '';
    $fbPostLines[] = "Apply now through the PHILCST Alumni Portal 👇";
    $fbPostLines[] = $shareBaseUrl;
    $fbPostText    = implode("\n", $fbPostLines);

    // ── FB sharer URL — uses base URL (no job ID) ─────────────────────────────
    $fbShareUrl    = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($shareBaseUrl);

    // ── Hostname for OG card footer display ───────────────────────────────────
    $shareHost     = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
@endphp

<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     wire:keydown.escape="closeShareModal"
     x-data="{
         copied:   false,
         fbCopied: false,
         fbText:   {{ json_encode($fbPostText) }},
         baseUrl:  {{ json_encode($shareBaseUrl) }},
         fbUrl:    {{ json_encode($fbShareUrl) }},

         shareOnFacebook() {
             // Step 1 — copy full job text to clipboard so user can paste it in FB
             navigator.clipboard.writeText(this.fbText).then(() => {
                 this.fbCopied = true;
                 setTimeout(() => this.fbCopied = false, 6000);
             }).catch(() => {
                 // clipboard permission denied — still open FB, just without pre-copy
             });
             // Step 2 — open Facebook sharer popup with the base jobs URL
             const w = 620, h = 520;
             const left = Math.round((screen.width  - w) / 2);
             const top  = Math.round((screen.height - h) / 2);
             window.open(
                 this.fbUrl, 'fb_share',
                 'width='+w+',height='+h+',left='+left+',top='+top
                 +',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1'
             );
         },

         copyLinkFn() {
             navigator.clipboard.writeText(this.baseUrl).then(() => {
                 this.copied = true;
                 setTimeout(() => this.copied = false, 2500);
             });
         }
     }"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        {{-- ── Modal header ──────────────────────────────────────────────── --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-extrabold text-gray-800 flex items-center gap-2">
                <i class="fas fa-share-nodes text-sky-600"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" type="button"
                    class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition cursor-pointer">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="px-6 pt-5 pb-5 space-y-4">

            {{-- ── FB "text copied" success banner ─────────────────────── --}}
            <div x-show="fbCopied" x-cloak
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-3">
                <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i class="fas fa-check text-emerald-600 text-xs"></i>
                </div>
                <div>
                    <p class="text-sm font-extrabold text-emerald-800">Job text copied to clipboard!</p>
                    <p class="text-xs text-emerald-700 mt-0.5 leading-snug">
                        Sa Facebook popup, i-click ang text box tapos
                        <strong>i-paste (Ctrl+V / ⌘V)</strong> — tapos na, ready to post!
                    </p>
                </div>
            </div>

            {{-- ── Label ─────────────────────────────────────────────────── --}}
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest">
                Preview — What people will see
            </p>

            {{-- ── Job preview card ─────────────────────────────────────── --}}
            <div class="rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm">

                <div class="bg-[#f0f2f5] border-b border-gray-200 px-4 py-3 flex items-start gap-3">
                    <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#4c1d95] flex items-center justify-center flex-shrink-0 shadow">
                        <i class="fas fa-briefcase text-white text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-extrabold text-gray-900 text-sm leading-tight truncate">
                            {{ $shareJobTitle }}
                        </p>
                        <p class="text-xs text-gray-700 mt-0.5 font-semibold">
                            {{ $shareCompany }}
                            @if($shareEmpType)
                                &middot; <span class="text-purple-700">{{ $shareEmpType }}</span>
                            @endif
                        </p>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @if($shareLocation)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-200 text-gray-700">
                                    <i class="fas fa-location-dot text-[8px]"></i>{{ $shareLocation }}
                                </span>
                            @endif
                            @if($shareExpLevel)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700">
                                    <i class="fas fa-layer-group text-[8px]"></i>{{ $shareExpLevel }}
                                </span>
                            @endif
                            @if($shareSalary)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700">
                                    <i class="fas fa-money-bill-wave text-[8px]"></i>{{ $shareSalary }}
                                </span>
                            @endif
                            @if($shareDlFormatted)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-600">
                                    <i class="fas fa-calendar-xmark text-[8px]"></i>Deadline: {{ $shareDlFormatted }}
                                </span>
                            @endif
                            @if($shareCollege)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700">
                                    <i class="fas fa-building-columns text-[8px]"></i>{{ $shareCollege }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($shareDescPreview)
                <div class="px-4 py-2.5 bg-white border-b border-gray-100">
                    <p class="text-xs text-gray-600 leading-relaxed line-clamp-3">{{ $shareDescPreview }}</p>
                </div>
                @endif

                <div class="px-4 py-2 bg-[#f0f2f5] flex items-center gap-2">
                    <i class="fas fa-globe text-gray-400 text-[10px]"></i>
                    <span class="text-[10px] text-gray-500 uppercase tracking-wide font-semibold">{{ strtoupper($shareHost) }}</span>
                </div>
            </div>
            {{-- ── End preview card ─────────────────────────────────────── --}}

            {{-- ── How it works hint ──────────────────────────────────────── --}}
            <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 flex items-start gap-2.5">
                <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
<p class="text-xs text-blue-800 leading-snug">
    <strong>How it works:</strong> Click on <em>Share on Facebook</em> — 
    the full job text will be automatically copied, 
    and then Facebook will open. Just paste 
    (<kbd class="bg-blue-100 px-1 rounded font-mono text-[10px]">Ctrl+V</kbd>) 
    into your post, and you're done!
</p>
            </div>

            {{-- ── Share via label ─────────────────────────────────────── --}}
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest pt-1">Share via</p>

            {{-- ── Facebook Share Button ──────────────────────────────── --}}
            <button type="button"
                    @click="shareOnFacebook()"
                    class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl
                           bg-[#1877F2] hover:bg-[#166fe5] active:bg-[#1464d8]
                           text-white font-extrabold text-sm
                           shadow hover:shadow-md
                           transition-all duration-150 cursor-pointer group">
                <span class="w-8 h-8 bg-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2">
                        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                    </svg>
                </span>
                <span class="flex-1 text-left">
                    <span x-show="!fbCopied">Share on Facebook</span>
                    <span x-show="fbCopied" x-cloak>
                        <i class="fas fa-check mr-1"></i> Bukas na! I-paste ang text sa FB
                    </span>
                </span>
                <i class="fas fa-arrow-up-right-from-square text-white/70 text-xs group-hover:text-white transition"></i>
            </button>

            {{-- ── Copy Link button (base URL, no job ID) ──────────────── --}}
            <button type="button"
                    @click="copyLinkFn()"
                    class="w-full flex items-center gap-3 px-4 py-3 rounded-xl
                           border-2 border-gray-200 hover:border-gray-300
                           bg-white hover:bg-gray-50 active:bg-gray-100
                           text-gray-700 font-bold text-sm
                           transition-all duration-150 cursor-pointer group">
                <span class="w-8 h-8 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0 transition">
                    <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-gray-500'" class="text-xs"></i>
                </span>
                <div class="flex-1 text-left">
                    <p :class="copied ? 'text-emerald-600' : 'text-gray-700'" class="font-bold text-sm"
                       x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                    <p class="text-[10px] text-gray-400 font-mono mt-0.5">{{ $shareBaseUrl }}</p>
                </div>
            </button>

            {{-- ── Footer note ─────────────────────────────────────────── --}}
            <p class="text-[11px] text-gray-400 text-center leading-snug pb-1">
                Make sure the posting is <strong class="text-gray-500">Active</strong> before sharing.
            </p>
        </div>
    </div>
</div>
@endif
{{-- END SHARE MODAL ══════════════════════════════════════════════════════════ --}}

</div>