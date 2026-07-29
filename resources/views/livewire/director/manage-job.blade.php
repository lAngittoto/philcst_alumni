{{-- resources/views/livewire/director/manage-job.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search         = '';
    public string $filterStatus   = '';
    public string $filterType     = '';
    public string $filterCollege  = '';
    public string $filterSort     = 'recent';

    public string $myDisplayName = '';
    public int    $directorId    = 0;

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

    public $postJobImage  = null;
    public $editJobImage  = null;
    public bool $postRemoveImage = false;
    public bool $editRemoveImage = false;

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
    public string $editCurrentImage                = '';

    public bool   $showConfirmModal = false;
    public ?int   $confirmJobId     = null;
    public string $confirmJobTitle  = ''; // NEW — needed so the confirm modal can show the job's title
    public string $confirmAction    = '';

    public bool   $showDeleteModal = false;
    public ?int   $deleteJobId     = null;
    public string $deleteJobTitle  = '';

    public bool   $showRestoreModal    = false;
    public ?int   $restoreJobId        = null;
    public string $restoreJobTitle     = '';
    public bool   $restoreWillActivate = false; // NEW — so the restore modal can tell the admin what status it'll restore to

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

    protected function rules(): array
    {
        return [
            'postJobImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'editJobImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

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

        // ─────────────────────────────────────────────────────────────────
        // NEW: pick up the auto-filter coming from the Director Dashboard's
        // job stat cards ("Total Jobs" / "Active" / "Inactive" mini tiles).
        // Dashboard sets 'director_job_status' in session right before
        // redirecting here (same clean-URL pattern already used for
        // 'director_coord_status' -> Active Coordinators).
        //
        // We apply it once to $filterStatus, then pull() it out of the
        // session (pull = read + forget in one call) — so a plain refresh
        // of this page afterwards goes back to showing every status
        // (ACTIVE + INACTIVE + ORGANIZER_DELETED), same as normal.
        // ─────────────────────────────────────────────────────────────────
        if (session()->has('director_job_status')) {
            $incomingStatus = session()->pull('director_job_status'); // '' | 'ACTIVE' | 'INACTIVE'
            $this->filterStatus = in_array($incomingStatus, ['', 'ACTIVE', 'INACTIVE'], true)
                ? $incomingStatus
                : '';
            $this->resetPage();
        }

        $dirRecord = DB::table('director')
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->first();

        if ($dirRecord) {
            $this->myDisplayName = trim(($dirRecord->first_name ?? '') . ' ' . ($dirRecord->last_name ?? ''));
            $this->directorId    = (int) $dirRecord->id;
        }
        if (! $this->myDisplayName) {
            $this->myDisplayName = auth()->user()?->name ?? 'Admin';
        }

        $expiredCount = JobPosting::where('status', 'ACTIVE')
            ->whereDate('deadline', '<', now('Asia/Manila')->toDateString())
            ->count();

        if ($expiredCount > 0) {
            $expiredDirectorJobs = JobPosting::where('status', 'ACTIVE')
                ->whereDate('deadline', '<', now('Asia/Manila')->toDateString())
                ->whereNull('organizer_id')
                ->get(['id', 'job_title']);

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

            foreach ($expiredDirectorJobs as $expJob) {
                $this->notifySelf('expired', $expJob);
            }
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

    // ─────────────────────────────────────────────────────────────────────
    // Self-notification — fires when the currently logged-in user (Admin
    // OR Director, both allowed on this page) performs an action (create /
    // update / activate / deactivate / delete / restore / system
    // auto-expire) on a job posting, so they get a confirmation
    // notification in their own bell.
    //
    // FIX: previously this ALWAYS wrote to `director_notifications` keyed
    // by `director_id`. If the logged-in user was an Admin with no row in
    // the `director` table, $this->directorId stayed 0, notifySelf()
    // returned early, and NOTHING got written — silently, since the
    // insert was wrapped in try/catch(\Throwable). This is why Admin
    // never saw notifications for create/update/activate/deactivate.
    //
    // Now it's role-aware:
    //   - Director (with a director record) -> director_notifications,
    //     keyed by director_id (unchanged behavior).
    //   - Admin (or anyone without a director record) -> admin_notifications,
    //     keyed by user_id.
    //
    // NOTE: this assumes an `admin_notifications` table mirroring
    // `director_notifications` (user_id, icon, title, message, link_route,
    // link_label, dedup_key, count, read, created_at, updated_at) and a
    // `admin-notif-refresh` JS event on the Admin layout, matching the
    // bell system already built for the Admin sidebar. If your actual
    // column names differ, tell me and I'll adjust writeAdminNotif().
    // ─────────────────────────────────────────────────────────────────────
    private function notifySelf(string $action, JobPosting $job, array $context = []): void
    {
        $title = $job->job_title;

        // Resolve target college label
        $targetLabel = 'All Colleges';
        if (!empty($job->target_college)) {
            $colleges    = array_map('trim', explode(',', $job->target_college));
            $allColleges = collect($this->collegesWithDepts)->pluck('name')->toArray();
            $isAll       = !empty($allColleges) && count(array_diff($allColleges, $colleges)) === 0;
            $targetLabel = $isAll ? 'All Colleges' : implode(', ', $colleges);
        }

        switch ($action) {
            case 'created':
                $notifTitle = 'Job Posted';
                $message    = "You created a job post \"{$title}\" for {$targetLabel}.";
                break;

            case 'updated':
                $notifTitle = 'Job Updated';
                if (!empty($context['reactivated'])) {
                    $message = "You updated \"{$title}\" — it has been re-activated after the deadline change.";
                } elseif (!empty($context['deadline_changed'])) {
                    $message = "You updated \"{$title}\" and set the deadline to {$context['deadline_label']}.";
                } else {
                    $message = "You updated the job post \"{$title}\".";
                }
                break;

            case 'activated':
                $notifTitle = 'Job Activated';
                $message    = "You activated the job post \"{$title}\". It's now visible to {$targetLabel}.";
                break;

            case 'deactivated':
                $notifTitle = 'Job Deactivated';
                $message    = "You deactivated the job post \"{$title}\".";
                break;

            case 'expired':
                $notifTitle = 'Job Auto-Deactivated';
                $message    = "\"{$title}\" has been automatically deactivated — its deadline has passed.";
                break;

            case 'deleted':
                $notifTitle = 'Job Deleted';
                $message    = "You deleted the job post \"{$title}\".";
                break;

            case 'restored':
                $notifTitle = 'Job Restored';
                $message    = "You restored the job post \"{$title}\".";
                break;

            default:
                return;
        }

        $dedupKey = 'job-self::' . $job->id . '::' . $action . '::' . now()->format('Y-m-d');
        $role     = auth()->user()?->role;

        if ($role === 'director' && $this->directorId) {
            $this->writeDirectorNotif($dedupKey, $notifTitle, $message);
            return;
        }

        $this->writeAdminNotif($dedupKey, $notifTitle, $message);
    }

    private function writeDirectorNotif(string $dedupKey, string $notifTitle, string $message): void
    {
        $directorId = $this->directorId;
        if (!$directorId) return;

        try {
            $exists = DB::table('director_notifications')
                ->where('director_id', $directorId)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) {
                DB::table('director_notifications')
                    ->where('director_id', $directorId)
                    ->where('dedup_key', $dedupKey)
                    ->update([
                        'title'      => $notifTitle,
                        'message'    => $message,
                        'read'       => 0,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('director_notifications')->insert([
                    'director_id' => $directorId,
                    'icon'        => 'briefcase',
                    'title'       => $notifTitle,
                    'message'     => $message,
                    'link_route'  => 'director.job/management',
                    'link_label'  => 'View Jobs',
                    'dedup_key'   => $dedupKey,
                    'count'       => 1,
                    'read'        => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            $this->dispatch('dir-notif-refresh');
        } catch (\Throwable) {
            // Non-critical — don't break the main action if this fails
        }
    }

    private function writeAdminNotif(string $dedupKey, string $notifTitle, string $message): void
    {
        $userId = auth()->id();
        if (!$userId) return;

        try {
            $exists = DB::table('admin_notifications')
                ->where('user_id', $userId)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) {
                DB::table('admin_notifications')
                    ->where('user_id', $userId)
                    ->where('dedup_key', $dedupKey)
                    ->update([
                        'title'      => $notifTitle,
                        'message'    => $message,
                        'read'       => 0,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('admin_notifications')->insert([
                    'user_id'     => $userId,
                    'icon'        => 'briefcase',
                    'title'       => $notifTitle,
                    'message'     => $message,
                    'link_route'  => 'admin.job/management',
                    'link_label'  => 'View Jobs',
                    'dedup_key'   => $dedupKey,
                    'count'       => 1,
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

    // ─────────────────────────────────────────────────────────────────────
    // Notifies the Organizer/Coordinator side (bell in the organizer
    // layout) whenever the Director acts on a director-posted job
    // (create/update/activate/deactivate/delete/restore). The organizer
    // layout's JS listens for the 'job-management-updated' browser event
    // and groups these per-day + per-action into a single "×N" row.
    //
    // Only fired for director-posted jobs (organizer_id === null) since
    // those are the ones visible to organizer/coordinator's target
    // college/department — organizer's own jobs already notify themselves
    // via their own component's 'job-self-action' event.
    //
    // ── FIX (this version) ──────────────────────────────────────────────
    // Previously this used:
    //     Organizer::query()->whereIn('department', $colleges)->pluck('user_id')
    // which is an EXACT SQL string match against $job->target_college.
    // Any whitespace or casing difference between organizer.department and
    // the target_college label (very easy to happen since they're entered/
    // selected in different places of the app) causes the whereIn() to
    // silently match ZERO rows. That empties $targetUserIds, the method
    // returns early, and NOTHING gets inserted — with no visible error,
    // which is exactly why the Alumni-Director-posted job notification +
    // "POSTED BY ALUMNI DIRECTOR" badge never showed up on the organizer
    // side even though notifySelf()/audit log/etc. all worked fine.
    //
    // Now colleges are compared in PHP after normalizing both sides
    // (trim + collapse internal whitespace + uppercase), so a stray space
    // or case difference can no longer cause a silent, total match-miss.
    // If $job->target_college is empty (meaning "All Colleges"), every
    // active organizer/coordinator is notified instead of nobody.
    // ─────────────────────────────────────────────────────────────────────
    private function notifyOrganizers(string $action, JobPosting $job): void
    {
        \Log::info('notifyOrganizers CALLED', ['action' => $action, 'job_id' => $job->id, 'target_college' => $job->target_college]);

        // Still fire the browser event too, in case a coordinator/organizer
        // happens to be logged in on this same browser session right now.
        $this->dispatch('job-management-updated', [
            'id'     => $job->id,
            'title'  => $job->job_title,
            'action' => $action,
        ]);

        $titleMap = [
            'director_posted' => 'Job Posted by Alumni Director',
            'updated'          => 'Job Updated by Alumni Director',
            'activated'        => 'Job Activated by Alumni Director',
            'deactivated'      => 'Job Deactivated by Alumni Director',
            'deleted'          => 'Job Deleted by Alumni Director',
            'restored'         => 'Job Restored by Alumni Director',
        ];
        $msgMap = [
            'director_posted' => 'Alumni Director posted a new job: "' . $job->job_title . '".',
            'updated'          => 'Alumni Director updated the job posting "' . $job->job_title . '".',
            'activated'        => 'Alumni Director activated "' . $job->job_title . '" — now visible to alumni.',
            'deactivated'      => 'Alumni Director deactivated "' . $job->job_title . '".',
            'deleted'          => 'Alumni Director deleted the job posting "' . $job->job_title . '".',
            'restored'         => 'Alumni Director restored the job posting "' . $job->job_title . '".',
        ];
        $iconMap = [
            'director_posted' => 'briefcase',
            'updated'          => 'pen-to-square',
            'activated'        => 'circle-check',
            'deactivated'      => 'circle-pause',
            'deleted'          => 'trash',
            'restored'         => 'rotate-left',
        ];

        if (!isset($titleMap[$action])) return;

        // Resolve target coordinator user_ids based on the job's target
        // colleges, using normalized (trim + collapse-whitespace + upper)
        // comparison instead of an exact SQL string match.
        $targetUserIds = collect();

        if ($job->target_college) {
            $normalize = fn ($s) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $s)));

            $wantedColleges = collect(explode(',', $job->target_college))
                ->map($normalize)
                ->filter()
                ->values();

            \Log::info('notifyOrganizers WANTED COLLEGES (normalized)', ['wanted' => $wantedColleges->toArray()]);

            $targetUserIds = \App\Models\Organizer::query()
                ->whereNull('deleted_at')
                ->get(['id', 'user_id', 'department'])
                ->filter(function ($org) use ($wantedColleges, $normalize) {
                    return $wantedColleges->contains($normalize($org->department ?? ''));
                })
                ->pluck('user_id')
                ->filter()
                ->unique();
        } else {
            // No target_college recorded on the job = "All Colleges" ->
            // notify every active (non-deleted) organizer/coordinator.
            $targetUserIds = \App\Models\Organizer::query()
                ->whereNull('deleted_at')
                ->pluck('user_id')
                ->filter()
                ->unique();
        }

        \Log::info('notifyOrganizers TARGET IDS', ['targetUserIds' => $targetUserIds->toArray()]);

        if ($targetUserIds->isEmpty()) {
            \Log::warning('notifyOrganizers EMPTY TARGETS - stopped here', [
                'job_id'           => $job->id,
                'target_college'   => $job->target_college,
                'all_departments'  => \App\Models\Organizer::query()->whereNull('deleted_at')->pluck('department'),
            ]);
            return;
        }

        $dedupKey = 'job-management::' . $action . '::' . $job->id;
        $now = now();

        $rows = $targetUserIds->map(function ($uid) use ($dedupKey, $titleMap, $msgMap, $iconMap, $action, $now) {
            return [
                'user_id'    => $uid,
                'icon'       => $iconMap[$action],
                'title'      => $titleMap[$action],
                'message'    => $msgMap[$action],
                'link_route' => 'organizer.job/management',
                'link_label' => 'View Jobs',
                'dedup_key'  => $dedupKey,
                'read'       => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->toArray();

        try {
            \App\Models\CoordinatorNotification::where('dedup_key', $dedupKey)
                ->where('created_at', '>=', $now->copy()->subMinutes(5))
                ->delete(); // clear stale dup from same action within window, if any

            \App\Models\CoordinatorNotification::insert($rows);
            \Log::info('notifyOrganizers INSERT SUCCESS', ['rows' => count($rows), 'user_ids' => $targetUserIds->toArray()]);
        } catch (\Throwable $e) {
            \Log::error('notifyOrganizers INSERT FAILED', ['error' => $e->getMessage()]);
        }
    }

    private function fetchJob(int $id): JobPosting
    {
        return JobPosting::with('organizer')->findOrFail($id);
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
                'target_college','salary','deadline','status','job_image',
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

        if ($this->postJobImage) {
            try {
                $this->validateOnly('postJobImage');
            } catch (\Livewire\Exceptions\ValidationException $e) {
                $errors['postJobImage'] = 'Image must be JPG, PNG, or WebP and under 2MB.';
            }
        }

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
        $imagePath        = $this->storeJobImage($this->postJobImage);

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
            'job_image'                => $imagePath,
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
                'has_image'                => $imagePath ? 'yes' : 'no (default)',
            ],
        );

        $this->notifySelf('created', $job);
        $this->notifyOrganizers('director_posted', $job);

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
        $this->postJobImage    = null;
        $this->postRemoveImage = false;
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
        $this->editCurrentImage            = $job->job_image ?? '';
        $this->editJobImage                = null;
        $this->editRemoveImage             = false;
        $this->editErrors                  = [];

        // Auto-check "All Colleges" if every college is already targeted
        $allCollegeNames        = collect($this->collegesWithDepts)->pluck('name')->toArray();
        $this->editAllColleges  = !empty($allCollegeNames) && empty(array_diff($allCollegeNames, $this->editTargetColleges));

        $this->showViewModal                = false;
        $this->showEditModal                = true;
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

        // Auto-editable jobs are only editable while INACTIVE — guard here
        // the same way the organizer component guards saveEditJob().
        if ($checkJob && $checkJob->status === 'ACTIVE') {
            $this->dispatch('flash-message', type: 'warning', message: 'Deactivate this job posting before editing it.');
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

        if ($this->editJobImage) {
            try {
                $this->validateOnly('editJobImage');
            } catch (\Livewire\Exceptions\ValidationException $e) {
                $errors['editJobImage'] = 'Image must be JPG, PNG, or WebP and under 2MB.';
            }
        }

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
            'has_image'                => $job->job_image ? 'yes' : 'no',
        ];

        // Handle image
        $newImagePath = $job->job_image;
        if ($this->editRemoveImage) {
            if ($job->job_image && Storage::disk('public')->exists($job->job_image)) {
                Storage::disk('public')->delete($job->job_image);
            }
            $newImagePath = null;
        } elseif ($this->editJobImage) {
            $newImagePath = $this->storeJobImage($this->editJobImage, $job->job_image ?? '');
        }

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
            'has_image'                => $newImagePath ? 'yes' : 'no (default)',
        ];

        $deadlineDate     = \Carbon\Carbon::createFromFormat('Y-m-d', $deadline, 'Asia/Manila')->endOfDay();
        $shouldReactivate = $job->status === 'INACTIVE' && $deadlineDate >= now('Asia/Manila');

        $job->update(array_merge($newValues, [
            'description'              => $description,
            'qualifications'           => $qualifications ?: null,
            'application_instructions' => $applicationInstructions ?: null,
            'job_image'                => $newImagePath,
            'updated_by'               => $this->myDisplayName,
            'updated_by_role'          => auth()->user()->role,
            'status'                   => $shouldReactivate ? 'ACTIVE' : $job->status,
        ]));

        $this->notifySelf('updated', $job, [
            'deadline_changed' => $deadline !== $oldValues['deadline'],
            'deadline_label'   => \Carbon\Carbon::parse($deadline)->format('M d, Y'),
            'reactivated'      => $shouldReactivate,
        ]);
        $this->notifyOrganizers('updated', $job);

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
        $this->editAllColleges  = false;
        $this->editCurrentImage = '';
        $this->editJobImage     = null;
        $this->editRemoveImage  = false;
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
        $this->confirmJobTitle  = $job->job_title;
        $this->confirmAction    = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $this->showConfirmModal = true;
        // NOTE: intentionally NOT closing showEditModal here — the confirm
        // modal stacks on top (z-[60] > z-50) so the edit screen stays
        // visible behind it, same pattern as the organizer component.
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

        $toggleAction = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';

        $this->notifySelf($toggleAction, $job);
        $this->notifyOrganizers($toggleAction, $job);

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
        $this->confirmJobTitle  = '';
        // Edit modal (if open) stays open after toggle — same as organizer pattern.
    }

    public function cancelConfirm(): void
    {
        $this->showConfirmModal = false;
        $this->confirmJobId     = null;
        $this->confirmJobTitle  = '';
    }

    public function confirmDelete(int $id): void
    {
        $this->authorizeRole();
        $job = JobPosting::findOrFail($id);
        $this->deleteJobId     = $id;
        $this->deleteJobTitle  = $job->job_title;
        $this->showDeleteModal = true;
        // NOTE: intentionally NOT closing showEditModal/showViewModal — the
        // delete modal stacks on top (z-[60] > z-50).
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

        $wasDirectorJob = is_null($job->organizer_id);

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

        $this->notifySelf('deleted', $job);
        if ($wasDirectorJob) {
            $this->notifyOrganizers('deleted', $job);
        }

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

        $deadline = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila')->startOfDay();
        $today    = now('Asia/Manila')->startOfDay();

        $this->restoreJobId        = $id;
        $this->restoreJobTitle     = $job->job_title;
        $this->restoreWillActivate = $today <= $deadline;
        $this->showRestoreModal    = true;
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

        $this->notifySelf('restored', $job);
        $this->notifyOrganizers('restored', $job);

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
        $this->showRestoreModal     = false;
        $this->restoreJobId         = null;
        $this->restoreJobTitle      = '';
        $this->restoreWillActivate  = false;

        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingJobId = null; }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal    = false;
        $this->restoreJobId        = null;
        $this->restoreJobTitle     = '';
        $this->restoreWillActivate = false;
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
        $now  = now();

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $roomId,
            'sender_type' => 'director',
            'sender_id'   => $dirRecord->id,
            'body'        => $body,
            'reply_to_id' => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        DB::table('chat_mentions')->insert([
            'message_id'   => $msgId,
            'mention_type' => 'everyone',
            'mentioned_id' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
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
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        }

        $this->dispatch('flash-message', type: 'success', message: 'Job posted to the Staff Channel! 💼');
        $this->closeShareJobModal();
    }
};
?>
<div class="flex flex-col" style="height: calc(100vh - 120px); max-height: calc(100vh - 120px); overflow: hidden;">

{{-- ═══════════════════ STYLES ═══════════════════ --}}
<style>
[x-cloak] { display: none !important; }

/* ══ Fixed-height card, matches Manage Coordinator sizing exactly ══ */
.job-table-card { display: flex; flex-direction: column; min-height: 0; max-height: calc(100vh - 320px); }

@media (max-width: 640px) {
    .job-table-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: none !important;
        box-shadow: none !important;
    }
}

/* ══ Mobile stacked card rows (same interaction language as coord-mrow) ══ */
.job-mrow {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    background: #fff;
    border-bottom: 1px solid #F0ECF5;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background .08s ease;
}
.job-mrow:active { background: #F0ECF5; }

@keyframes fadeIn    { from { opacity:0 } to { opacity:1 } }
@keyframes modalIn   { from { opacity:0; transform:translateY(14px) scale(.97) } to { opacity:1; transform:none } }
@keyframes slideInFull { from { opacity:0 } to { opacity:1 } }
.m-in  { animation: modalIn .2s cubic-bezier(.25,.8,.25,1) both; }
.fs-in { animation: slideInFull .22s cubic-bezier(.4,0,.2,1) both; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: transparent; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

select.tw-select-arrow {
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

/* NOTE: "Update deadline to activate" now uses the fixed/JS-driven
   #eo-deadline-tip overlay instead of an absolutely-positioned span, so it
   can never get clipped by a scrollable table/list ancestor. This wrapper
   class is kept only for layout (icon centering). */
.activate-disabled-wrap { display: inline-flex; }

.img-upload-zone {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    transition: border-color 0.2s, background 0.2s;
    cursor: pointer;
    background: #fafafa;
}
.img-upload-zone:hover { border-color: #7a3f91; background: #f5eef9; }
.img-upload-zone.has-image { border-style: solid; border-color: #d4aaeb; background: #fdf8ff; }

.img-preview-thumb    { width:100%; height:120px; object-fit:cover; border-radius:10px; display:block; }
.img-preview-thumb-sm { width:100%; height:100px; object-fit:cover; border-radius:10px; display:block; }

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
.view-field-display.multiline { white-space: pre-wrap; min-height: 100px; }
.view-field-display.empty     { color: #aaa; font-style: italic; }

/* ══ Table rows white (not gray), no inner horizontal scrollbar — matches
   the organizer component's table styling exactly. ══ */
#dm-table-scroll,
#dm-table-scroll table,
#dm-table-scroll tbody,
#dm-table-scroll tr {
    background: #ffffff !important;
}
#dm-table-scroll .overflow-x-auto {
    overflow-x: visible !important;
}

.dm-filter-progress-track { height: 2px; width: 100%; overflow: hidden; background: transparent; position: relative; }
.dm-filter-progress-bar {
    position: absolute; top: 0; left: 0; height: 100%; width: 40%;
    border-radius: 99px; background: linear-gradient(135deg,#7a3f91,#9b59b6);
    animation: dmFilterProgress 1s ease-in-out infinite;
}
@keyframes dmFilterProgress { 0% { left: -40%; } 100% { left: 100%; } }
</style>

{{-- Hover tooltip (row "View Details") --}}
<div id="eo-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)] transition-opacity duration-150"
     style="transform:translate(12px,-110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

{{-- NEW: global fixed/overlay tooltip for the "Update deadline to activate"
     icon (and any other [data-tip] element). Always positioned ABOVE the
     cursor and is position:fixed, so it can never get cut off by the
     table's overflow-y-auto — it floats above everything (z-[99999]). --}}
<div id="eo-deadline-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)] transition-opacity duration-150"
     style="transform:translate(-50%,-130%);">
    <i class="fas fa-calendar-xmark mr-1.5"></i><span id="eo-deadline-tip-text">Update deadline to activate</span>
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
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

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
            <div class="relative inline-flex group">
                <button wire:click="openPostModal"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-xl font-semibold text-white shadow-md transition cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <i class="fas fa-plus text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+8px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50 shadow-lg">
                    <i class="fas fa-plus text-[9px] mr-1"></i>Post a Job
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-[4px] border-transparent border-b-[#1a1a1a]"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK — same fixed-height / inner-scroll pattern as
         the organizer job management component: only the table body area
         scrolls (and shows the loading dim/progress bar), never the whole
         page or the table sideways. ══ --}}
    <div class="job-table-card flex-1 min-h-0 rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-transparent border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus,filterType,filterCollege,filterSort">
            <div class="flex items-center px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>
            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 pl-9 rounded-lg w-full transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] placeholder-[#a78bbd]"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg tw-select-arrow transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4]">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <select wire:model.live="filterType"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg tw-select-arrow transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] hidden sm:block">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterCollege"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg tw-select-arrow transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 hover:border-[#c4b5d4] hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>

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
                <button wire:click="$set('filterStatus', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif
            @endif

            @if($filterType)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-blue-50 border-blue-300 text-blue-800">
                <i class="fas fa-filter text-[9px]"></i>{{ $filterType }}
                <button wire:click="$set('filterType', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            @if($filterCollege)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-purple-50 border-purple-300 text-purple-800">
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
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333] bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline text-[#333333]">Reset</span>
            </button>

            {{-- Mobile-only selects --}}
            <select wire:model.live="filterType"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg tw-select-arrow flex-1 sm:hidden">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCollege"
                    class="border border-[#E8E0F0] bg-white text-[#333333] text-sm px-3 py-2 rounded-lg tw-select-arrow flex-1 sm:hidden">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- Filtering / searching progress bar (matches organizer pattern) --}}
        <div class="dm-filter-progress-track flex-shrink-0" wire:loading wire:target="search,filterStatus,filterType,filterCollege,filterSort">
            <div class="dm-filter-progress-bar"></div>
        </div>

        {{-- ── TABLE WRAPPER — only this region scrolls; loading dim applies
             here only so the header/filter bar and pagination stay fixed. ── --}}
        <div class="relative flex-1 min-h-0 bg-white">
            <div id="dm-table-scroll"
                 class="scroll-c h-full overflow-y-auto overflow-x-hidden bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-60" wire:target="search,filterStatus,filterType,filterCollege,filterSort">

            @if($this->jobPostings->count() > 0)

            <div class="bg-white">
                {{-- ── DESKTOP / TABLET: table view ── --}}
                <table class="w-full bg-white border-collapse hidden md:table table-fixed">
                    <colgroup>
                        <col style="width:6%;"><col style="width:32%;"><col style="width:20%;"><col style="width:14%;"><col style="width:14%;"><col style="width:14%;">
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Type</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest text-[#555555]"></th>
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
                        <tr class="transition-colors duration-100 cursor-pointer {{ $isOrgDel ? 'bg-red-50/60 opacity-80 hover:opacity-100 hover:bg-red-100/60' : 'bg-white hover:bg-[#f5f0fa]' }}"
                            wire:click="viewJob({{ $job->id }})"
                            wire:key="job-row-{{ $job->id }}"
                            data-eo-row>

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5 max-w-[200px]">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 {{ $isOrgDel ? 'line-through text-red-400' : 'text-[#333333]' }}">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5 {{ $isOrgDel ? 'text-red-400' : 'text-[#777777]' }}">
                                    @if($isOrgDel) Deleted {{ $job->updated_at->diffForHumans() }}
                                    @else {{ $job->created_at->diffForHumans() }}
                                    @endif
                                </p>
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

                            <td class="px-4 py-3.5 hidden md:table-cell whitespace-nowrap">
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isOrgDel)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-red-200 bg-red-50 text-red-700 whitespace-nowrap">
                                        <i class="fas fa-trash-can text-[9px] mr-1"></i>Deleted by Org.
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
                                    @if($isOrgDel)
                                        <div class="relative inline-flex group" data-eo-action>
                                            <button wire:click.stop="confirmRestore({{ $job->id }})" type="button"
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                            <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                Restore<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                            </div>
                                        </div>
                                    @else
                                        @if($canShare)
                                            <div class="relative inline-flex group" data-eo-action>
                                                <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                                        class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold bg-sky-100 text-sky-700 border border-sky-200 hover:bg-white hover:border-sky-400 transition cursor-pointer">
                                                    <i class="fas fa-share-nodes"></i>
                                                </button>
                                                <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                    Share<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                </div>
                                            </div>
                                        @endif

                                        @if($isDirectorJob)
                                            @if($isActive)
                                                <div class="relative inline-flex group" data-eo-action>
                                                    <button wire:click.stop="confirmToggle({{ $job->id }})" type="button"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-amber-50 text-amber-700 border border-amber-200 hover:bg-white hover:border-amber-400">
                                                        <i class="fas fa-circle-pause"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Deactivate<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                            @elseif(!$isDeadlinePassed)
                                                <div class="relative inline-flex group" data-eo-action>
                                                    <button wire:click.stop="confirmToggle({{ $job->id }})" type="button"
                                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-white hover:border-emerald-400">
                                                        <i class="fas fa-circle-play"></i>
                                                    </button>
                                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#1a1a1a] text-white px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                                                        Activate<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-[#1a1a1a]"></span>
                                                    </div>
                                                </div>
                                            @else
                                                {{-- Uses the global fixed/JS-driven #eo-deadline-tip overlay so it can
                                                     never get clipped by the table's overflow-y-auto ancestor. --}}
                                                <div class="activate-disabled-wrap" data-eo-action data-tip="Update deadline to activate">
                                                    <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs bg-red-50 text-red-400 border border-red-200 cursor-not-allowed">
                                                        <i class="fas fa-calendar-xmark"></i>
                                                    </span>
                                                </div>
                                            @endif
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- ── MOBILE: stacked card list ── --}}
                <div class="block md:hidden">
                    @foreach($this->jobPostings as $index => $job)
                    @php
                        $isOrgDel         = $job->status === 'ORGANIZER_DELETED';
                        $isActive         = $job->status === 'ACTIVE';
                        $organizerName    = $job->organizer?->name ?? null;
                        $isDirectorJob    = is_null($job->organizer_id);
                    @endphp
                    <div class="job-mrow" wire:key="job-mrow-{{ $job->id }}" wire:click="viewJob({{ $job->id }})">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm truncate {{ $isOrgDel ? 'line-through text-red-400' : 'text-gray-900' }}">
                                {{ $job->job_title }}
                            </p>
                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span class="text-xs text-gray-600 truncate">
                                    {{ $organizerName ?: 'Alumni Director' }}
                                </span>
                                <span class="text-gray-300 text-xs">&bull;</span>
                                <span class="text-xs text-gray-600 truncate">{{ $job->employment_type }}</span>
                            </div>
                            @if($isOrgDel)
                                <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-50 text-red-700">
                                    <i class="fas fa-trash-can text-[8px]"></i>Deleted by Org.
                                </span>
                            @elseif($isActive)
                                <span class="inline-block mt-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700">Active</span>
                            @else
                                <span class="inline-block mt-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700">Inactive</span>
                            @endif
                        </div>
                        <i class="fas fa-chevron-right text-gray-300 text-xs shrink-0"></i>
                    </div>
                    @endforeach
                </div>
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
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
        <div class="flex-shrink-0 border-t border-[#7a3f91]/30 px-4 min-h-[48px] flex items-center justify-between gap-2 flex-wrap py-1"
             style="background: linear-gradient(to right, #7a3f91, #9b59b6);">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}&ndash;{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                job{{ $total !== 1 ? 's' : '' }}
                @if($filterStatus || $filterType || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1 flex-wrap py-2">
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
     DELETE CONFIRM MODAL — z-[60], stacks above Edit/View modal
════════════════════════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelDelete">
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
                <span class="text-xs text-amber-800">This job will be marked as deleted and removed from the active list.</span>
            </div>
            <div class="flex gap-2">
                <button wire:click="cancelDelete"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelDelete,executeDelete"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelDelete"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelDelete"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeDelete"
                        wire:loading.attr="disabled"
                        wire:target="executeDelete"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-500 hover:bg-red-600 transition cursor-pointer disabled:opacity-60">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash-can mr-1 text-xs"></i></span>
                    Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     RESTORE CONFIRM MODAL — z-[60]
════════════════════════════════════════════════════════════════════════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelRestore">
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
                <button wire:click="cancelRestore"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelRestore,executeRestore"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelRestore"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelRestore"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeRestore"
                        wire:loading.attr="disabled"
                        wire:target="executeRestore"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-60
                               {{ $restoreWillActivate ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-amber-500 hover:bg-amber-600' }}">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1 text-xs"></i></span>
                    Yes, Restore
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     ACTIVATE / DEACTIVATE CONFIRM MODAL — z-[60]
════════════════════════════════════════════════════════════════════════ --}}
@if($showConfirmModal)
@php $confirmIsActivating = $confirmAction === 'ACTIVE'; @endphp
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
     wire:keydown.escape.window="cancelConfirm">
    <div class="rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden m-in bg-white">
        <div class="px-6 py-4 border-b {{ $confirmIsActivating ? 'border-emerald-100 bg-emerald-50' : 'border-amber-100 bg-amber-50' }}">
            <h2 class="text-base font-semibold {{ $confirmIsActivating ? 'text-emerald-800' : 'text-amber-800' }} flex items-center gap-2.5">
                <div class="w-8 h-8 {{ $confirmIsActivating ? 'bg-emerald-100' : 'bg-amber-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas {{ $confirmIsActivating ? 'fa-circle-play text-emerald-600' : 'fa-circle-pause text-amber-600' }} text-sm"></i>
                </div>
                {{ $confirmIsActivating ? 'Activate Job Posting' : 'Deactivate Job Posting' }}
            </h2>
        </div>
        <div class="p-5 bg-white">
            <p class="text-sm text-[#555555] mb-1">You are about to <strong>{{ $confirmIsActivating ? 'activate' : 'deactivate' }}</strong>:</p>
            <p class="font-semibold text-[#333333] text-sm mb-4 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg leading-snug">
                {{ $confirmJobTitle }}
            </p>
            @if($confirmIsActivating)
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
                <button wire:click="cancelConfirm"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="cancelConfirm,executeToggle"
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-50 transition text-[#333333] cursor-pointer disabled:pointer-events-none">
                    <span wire:loading wire:target="cancelConfirm"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="cancelConfirm"><i class="fas fa-xmark mr-1 text-xs"></i></span>
                    Cancel
                </button>
                <button wire:click="executeToggle"
                        wire:loading.attr="disabled"
                        wire:target="executeToggle"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition cursor-pointer disabled:opacity-60
                               {{ $confirmIsActivating ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-amber-500 hover:bg-amber-600' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin mr-1 text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeToggle"><i class="fas {{ $confirmIsActivating ? 'fa-circle-play' : 'fa-circle-pause' }} mr-1 text-xs"></i></span>
                    Yes, {{ $confirmIsActivating ? 'Activate' : 'Deactivate' }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ════════════════════════════════════════════════════════════════════════
     POST JOB — FULL SCREEN 3-COLUMN
════════════════════════════════════════════════════════════════════════ --}}
@if($showPostModal)
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closePostModal()">

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
        <button wire:click="closePostModal" type="button"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-60"
                wire:target="closePostModal,savePost"
                class="modal-top-btn relative inline-flex items-center gap-1.5 px-3 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22 disabled:pointer-events-none text-white text-xs font-semibold">
            <span wire:loading wire:target="closePostModal,savePost"><i class="fas fa-spinner animate-spin text-xs"></i></span>
            <span wire:loading.remove wire:target="closePostModal,savePost"><i class="fas fa-xmark text-xs"></i></span>
            <span wire:loading.remove wire:target="closePostModal,savePost">Cancel</span>
            <span wire:loading wire:target="closePostModal,savePost">Closing…</span>
        </button>
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

        {{-- LEFT: Organization + Target Colleges + Job Photo --}}
        <div class="w-full lg:w-[290px] xl:w-[310px] shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-3 space-y-3">

                {{-- Organization Category --}}
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postOrgCategory']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        <i class="fas fa-building text-[9px] text-[#555555]"></i> Organization
                        <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 space-y-2">
                        <div class="grid grid-cols-1 gap-1.5">
                            @foreach([['philcst','PHILCST Campus','Internal department','fa-school'],['partner','Partner Company','Known partner organization','fa-handshake'],['custom','Other / Custom','Enter manually','fa-pen-to-square']] as [$val,$label,$sub,$ico])
                            <button type="button" wire:click="$set('postOrgCategory','{{ $val }}')"
                                    class="px-2.5 py-2 border-2 rounded-xl bg-white cursor-pointer transition text-left font-semibold flex items-center gap-2.5 text-sm
                                           {{ $postOrgCategory===$val ? 'border-[#7a3f91] text-white shadow-md' : 'border-gray-200 hover:border-[#7a3f91] hover:bg-purple-50 text-[#333333]' }}"
                                    style="{{ $postOrgCategory===$val ? 'background:linear-gradient(135deg,#7a3f91,#6a3580);' : '' }}">
                                <i class="fas {{ $ico }} text-base flex-shrink-0"></i>
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
                                @if($philcstLocation)<div class="text-[#7c3aed] truncate mt-0.5 text-[0.65rem]"><i class="fas fa-location-dot mr-1"></i>{{ $philcstLocation }}</div>@endif
                            </div>
                            <span class="inline-flex items-center gap-1 font-semibold text-purple-700 bg-white border border-purple-200 px-1.5 py-0.5 rounded-full shrink-0 text-[0.6rem]">
                                <i class="fas fa-lock text-[8px]"></i> Auto
                            </span>
                        </div>
                        @endif

                        @if($postOrgCategory === 'partner')
                        <div wire:ignore x-data="{pName:@js($postPartnerName),pType:@js($postPartnerType),loc:@js($postLocation),syncN(v){$wire.set('postPartnerName',v,false)},syncT(v){$wire.set('postPartnerType',v,false)},syncL(v){$wire.set('postLocation',v,false)}}">
                            <div class="space-y-2">
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Name <span class="text-red-500">*</span></label>
                                    <input x-model="pName" @input.debounce.300ms="syncN(pName)" type="text" placeholder="e.g. Acme Corp" maxlength="150"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postPartnerName']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postPartnerName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="pType" @input.debounce.300ms="syncT(pType)" type="text" placeholder="e.g. Private, NGO" maxlength="100"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postPartnerType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postPartnerType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postPartnerType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Tuguegarao / Remote" maxlength="120"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
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
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postCustomName']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postCustomName']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomName'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Org Type <span class="text-red-500">*</span></label>
                                    <input x-model="cType" @input.debounce.300ms="syncT(cType)" type="text" placeholder="e.g. Government, NGO" maxlength="100"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postCustomType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                    @if(isset($postErrors['postCustomType']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postCustomType'] }}</p>@endif
                                </div>
                                <div>
                                    <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Location <span class="text-red-500">*</span></label>
                                    <input x-model="loc" @input.debounce.300ms="syncL(loc)" type="text" placeholder="e.g. Manila / Remote / Hybrid" maxlength="120"
                                           class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postLocation']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
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
                <div class="bg-white border-[1.5px] {{ isset($postErrors['postTargetColleges']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        <i class="fas fa-building-columns text-[9px] text-[#555555]"></i> Target Colleges
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

                {{-- Job Photo --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
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

            </div>
        </div>

        {{-- MIDDLE: Job Info + Textareas --}}
        <div class="flex-1 min-w-0 overflow-y-auto border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50 scroll-c">
            <div class="p-3 space-y-3 flex flex-col flex-1">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Job Information
                    </div>
                    <div class="p-3.5 space-y-3">
                        <div>
                            <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Job Title <span class="text-red-500">*</span></label>
                            <input wire:model.defer="postJobTitle" type="text" placeholder="e.g. Software Engineer" maxlength="200"
                                   class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postJobTitle']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            @if(isset($postErrors['postJobTitle']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postJobTitle'] }}</p>@endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                                <select wire:model.defer="postEmpType"
                                        class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postEmpType']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
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
                                        class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postExpLevel']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition tw-select-arrow">
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
                                       class="w-full px-3.5 py-2.5 border-[1.5px] border-gray-300 rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                            </div>
                            <div>
                                <label class="block text-[0.78rem] font-semibold uppercase tracking-[.06em] text-[#333333] mb-1.5">Deadline <span class="text-red-500">*</span></label>
                                <input wire:model.defer="postDeadline" type="date"
                                       min="{{ now()->setTimezone('Asia/Manila')->format('Y-m-d') }}"
                                       class="w-full px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors['postDeadline']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition">
                                @if(isset($postErrors['postDeadline']))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors['postDeadline'] }}</p>@endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Textareas --}}
                @foreach([['postDescription','Description','fas fa-file-lines','Describe the role, responsibilities, and what the candidate will be doing…','5000'],['postQualifications','Qualifications','fas fa-list-check','e.g. Bachelor\'s degree in a relevant field, at least 1 year experience…','3000'],['postApplicationInstructions','How to Apply','fas fa-paper-plane','e.g. Send your resume to hr@company.com with subject: Application – [Position]','3000']] as [$field,$title,$ico,$placeholder,$maxlen])
                <div class="bg-white border-[1.5px] {{ isset($postErrors[$field]) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] shrink-0">
                        <i class="{{ $ico }} text-[9px] text-[#555555]"></i> {{ $title }} <span class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <textarea wire:model.defer="{{ $field }}"
                                  class="w-full flex-1 px-3.5 py-2.5 border-[1.5px] {{ isset($postErrors[$field]) ? 'border-red-400 bg-red-50' : 'border-gray-300' }} rounded-xl text-sm bg-white text-[#222] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition resize-none"
                                  placeholder="{{ $placeholder }}" maxlength="{{ $maxlen }}"
                                  style="min-height:100px;"></textarea>
                        @if(isset($postErrors[$field]))<p class="text-red-600 flex items-center gap-1 mt-0.5 text-[0.7rem]"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $postErrors[$field] }}</p>@endif
                    </div>
                </div>
                @endforeach

            </div>
        </div>

        {{-- RIGHT: Default Photo Preview + Posted As + Tips + Actions --}}
        <div class="w-full lg:w-[240px] xl:w-[260px] shrink-0 overflow-y-auto bg-white flex flex-col scroll-c">
            <div class="p-3 space-y-3 flex-1">

                {{-- Default Photo Preview --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Default Photo Preview
                    </div>
                    <div class="p-3.5">
                        <div class="rounded-xl overflow-hidden" style="height:100px;">
                            <img src="{{ asset('storage/job/default-photo-job.jpg') }}" alt="Default job photo"
                                 class="w-full h-full object-cover"
                                 onerror="this.parentElement.style.display='none'">
                        </div>
                        <p class="text-[10px] text-[#777777] mt-1.5 text-center">Default photo if none uploaded</p>
                    </div>
                </div>

                {{-- Posted As --}}
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
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
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-1.5">
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Set a future deadline — past deadlines auto-deactivate.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Include salary — listings with salary attract more applicants.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Select target colleges carefully — only those alumni will see it.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Job goes live immediately — no approval required.</span></li>
                            <li class="flex items-start gap-1.5 text-[0.7rem] text-[#333333]"><i class="fas fa-bell text-purple-500 mt-0.5 flex-shrink-0 text-[8px]"></i><span>Coordinators will be notified automatically when you post.</span></li>
                        </ul>
                    </div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                    <p class="font-semibold text-emerald-800 flex items-center gap-1.5 text-sm">
                        <i class="fas fa-circle-check text-emerald-500 text-sm"></i> Ready to post
                    </p>
                    <p class="text-emerald-700 mt-1 text-[0.68rem]">Job goes live immediately. Coordinators will be notified.</p>
                </div>
            </div>

            <div class="shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <button type="button" wire:click="savePost"
                        wire:loading.attr="disabled" wire:target="savePost"
                        class="w-full px-4 py-2.5 rounded-xl font-semibold text-white text-sm transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    <span wire:loading wire:target="savePost"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="savePost"><i class="fas fa-paper-plane text-xs"></i></span>
                    <span wire:loading wire:target="savePost">Posting…</span>
                    <span wire:loading.remove wire:target="savePost">Post Job</span>
                </button>
                <button type="button" wire:click="closePostModal"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="closePostModal,savePost"
                        class="w-full px-4 py-2 rounded-xl font-semibold text-sm bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer text-[#333333] disabled:pointer-events-none flex items-center justify-center gap-1.5">
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


{{-- ════════════════════════════════════════════════════════════════════════
     EDIT JOB MODAL — director-posted jobs (organizer_id is null)
     z-50, full screen.

     CHANGED: no more manual "Edit Mode / View Mode" toggle button. This now
     mirrors the organizer component's behavior exactly:
       - If the job is INACTIVE (and always director-posted here, since only
         director jobs reach this modal), all fields are AUTOMATICALLY
         editable the moment you open it — no extra click needed.
       - If the job is ACTIVE, it's automatically view-only; Deactivate it
         first (top-right button) to unlock editing.
     Alpine's `editMode` is now driven by the job's real status via
     `x-effect`, same pattern as organizer-job-management.blade.php.
════════════════════════════════════════════════════════════════════════ --}}
@if($showEditModal)
@php
    $editingJob = $editingJobId ? \App\Models\JobPosting::find($editingJobId) : null;
    $editJobIsActive       = $editingJob && $editingJob->status === 'ACTIVE';
    $editJobDeadlinePassed = $editingJob ? \Carbon\Carbon::parse($editingJob->deadline)->setTimezone('Asia/Manila')->startOfDay()->lt(now('Asia/Manila')->startOfDay()) : false;
    $editJobCanShare       = $editingJob && !$editJobDeadlinePassed && $editJobIsActive;
    // Auto-editable the moment the job is INACTIVE — no manual toggle.
    $editModeAllowed       = $editingJob && !$editJobIsActive;
@endphp
<div class="fixed inset-0 z-50 flex flex-col bg-gray-100 fs-in overflow-hidden"
     @keydown.escape.window="$wire.closeEditModal()"
     x-data="{ editMode: {{ $editModeAllowed ? 'true' : 'false' }} }"
     x-effect="editMode = {{ $editModeAllowed ? 'true' : 'false' }}">

    <div class="flex items-center justify-between px-6 lg:px-10 py-3 bg-[#7a3f91] flex-shrink-0 shadow-lg">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas" :class="editMode ? 'fa-pen-to-square' : 'fa-eye'" style="color:#fff;"></i>
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

            @if($editingJob)
                @if(!$editJobIsActive)
                    @if($editJobDeadlinePassed)
                        <div class="activate-disabled-wrap" data-tip="Update deadline to activate">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-not-allowed opacity-40 bg-emerald-500/10 border border-emerald-500/25">
                                <i class="fas fa-circle-play text-emerald-300 text-sm"></i>
                            </span>
                        </div>
                    @else
                        <button wire:click="confirmToggle({{ $editingJobId }})" type="button"
                                class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-emerald-500/10 border border-emerald-500/25 hover:bg-emerald-500/20">
                            <i class="fas fa-circle-play text-emerald-300 text-sm"></i>
                            <span class="mtip">Activate</span>
                        </button>
                    @endif
                @else
                    <button wire:click="confirmToggle({{ $editingJobId }})" type="button"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-amber-400/12 border border-amber-400/25 hover:bg-amber-400/22">
                        <i class="fas fa-circle-pause text-amber-300 text-sm"></i>
                        <span class="mtip">Deactivate</span>
                    </button>
                @endif

                @if($editJobCanShare)
                    <button wire:click="openShareJobModal({{ $editingJobId }})" type="button"
                            class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                        <span class="mtip">Share</span>
                    </button>
                @endif

                <button wire:click="confirmDelete({{ $editingJobId }})" type="button"
                        class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-red-500/10 border border-red-400/25 hover:bg-red-500/20">
                    <i class="fas fa-trash text-red-300 text-sm"></i>
                    <span class="mtip">Delete</span>
                </button>
            @endif

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

    @if($editingJob && $editingJob->status === 'INACTIVE')
    <div class="bg-blue-50 border-b border-blue-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
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

    @if($editJobIsActive)
    <div class="bg-purple-50 border-b border-purple-200 px-6 py-1.5 flex-shrink-0 flex items-center gap-3">
        <i class="fas fa-eye text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-xs text-purple-800"><strong>View Mode:</strong> This job is currently Active. Deactivate it (top-right) first to make changes.</p>
    </div>
    @endif

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

        {{-- LEFT: Photo + Org Details + Target Colleges + Status --}}
        <div class="w-full lg:w-[290px] xl:w-[310px] flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto bg-white scroll-c">
            <div class="p-2.5 space-y-2.5">

                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        <i class="fas fa-image text-[9px] text-[#555555]"></i> Job Photo
                        <span x-show="editMode" x-cloak class="font-normal normal-case tracking-normal text-[10px] ml-1 text-[#777777]">— optional</span>
                    </div>
                    <div class="p-3">
                        {{-- View mode: just show the current image --}}
                        <div x-show="!editMode">
                            @php $editViewImgUrl = $this::jobImageUrl($editingJob->job_image ?? null); @endphp
                            <div class="rounded-xl overflow-hidden" style="height:110px;">
                                <img src="{{ $editViewImgUrl }}" alt="Job photo" class="w-full h-full object-cover"
                                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
                            </div>
                            <p class="text-[10px] text-[#777777] mt-1.5 text-center">
                                {{ $editingJob->job_image ? 'Custom photo uploaded' : 'Default photo' }}
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
                @endif

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Organization Details
                    </div>
                    <div class="p-2.5 space-y-2">
                        @php $editIsPhilcst = str_contains(strtoupper($editCompanyType ?? ''), 'PHILCST'); @endphp

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

                <div class="bg-white border-[1.5px] {{ isset($editErrors['editTargetColleges']) ? 'border-red-300' : 'border-[#e8e0f0]' }} rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        <i class="fas fa-building-columns text-[9px] text-[#555555]"></i> Target Colleges
                        <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-2.5">
                        {{-- View mode: pills --}}
                        <div x-show="!editMode" class="flex flex-wrap gap-1.5">
                            @forelse($editTargetColleges as $tc)
                                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-200 text-xs font-semibold">{{ trim($tc) }}</span>
                            @empty
                                <span class="view-field-display empty text-sm w-full">No college selected.</span>
                            @endforelse
                        </div>

                        {{-- Edit mode: checkboxes --}}
                        <div x-show="editMode" x-cloak class="space-y-1.5">
                            <label class="flex items-center gap-2 px-3 py-2 border-2 border-[#7a3f91] bg-[#f5eef9] rounded-xl cursor-pointer">
                                <input type="checkbox" wire:model.live="editAllColleges" class="w-4 h-4 flex-shrink-0 accent-[#7a3f91]">
                                <span class="text-sm font-semibold text-[#5e2f72]">All Colleges</span>
                            </label>
                            @foreach($this->collegesWithDepts as $college)
                                <label class="flex items-center gap-2 px-3 py-2 border rounded-xl cursor-pointer transition font-semibold
                                              {{ in_array($college['name'], $editTargetColleges) ? 'border-[#7a3f91]/40 bg-[#f5eef9] text-[#7a3f91]' : 'border-gray-200 hover:border-[#7a3f91]/30 hover:bg-[#f5eef9]/40 text-[#555555]' }}">
                                    <input type="checkbox" wire:model.live="editTargetColleges" value="{{ $college['name'] }}"
                                           class="w-4 h-4 flex-shrink-0 accent-[#7a3f91]">
                                    <span class="truncate text-xs">{{ $college['name'] }}</span>
                                </label>
                            @endforeach
                            @if(isset($editErrors['editTargetColleges']))<p class="text-red-600 text-xs mt-1 flex items-center gap-1"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editTargetColleges'] }}</p>@endif
                        </div>
                    </div>
                </div>

                @if($editingJob)
                @php
                    $statusColor = match($editingJob->status) {
                        'ACTIVE'        => ['bg-emerald-50 border-emerald-200', 'text-emerald-900', 'fa-circle-check text-emerald-500', 'text-emerald-700', 'Currently Active'],
                        'INACTIVE'      => ['bg-amber-50 border-amber-200',   'text-amber-900',   'fa-circle-pause text-amber-500',   'text-amber-700',   'Currently Inactive'],
                        'ADMIN_DELETED' => ['bg-red-50 border-red-200',       'text-red-900',     'fa-trash text-red-500',            'text-red-700',     'Deleted'],
                        default         => ['bg-gray-50 border-gray-200',     'text-gray-900',    'fa-circle text-gray-500',          'text-gray-700',    $editingJob->status],
                    };
                @endphp
                <div class="rounded-xl px-3 py-2 border {{ $statusColor[0] }}">
                    <p class="font-semibold flex items-center gap-1.5 text-sm {{ $statusColor[1] }}">
                        <i class="fas {{ $statusColor[2] }} text-sm"></i> {{ $statusColor[4] }}
                    </p>
                    <p class="text-xs mt-0.5 {{ $statusColor[3] }}">
                        @if($editingJob->status === 'INACTIVE' && $editJobDeadlinePassed) Update the deadline above first, save, then use <strong>Activate</strong>.
                        @else Use the {{ $editingJob->status === 'ACTIVE' ? 'Deactivate' : 'Activate' }} button in the top-right to toggle.
                        @endif
                    </p>
                </div>
                @endif

            </div>
        </div>

        {{-- MIDDLE: Job Info + Textareas --}}
        <div class="flex-1 min-w-0 flex flex-col overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-c flex flex-col p-3 gap-3">

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
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
                        <div class="grid grid-cols-2 gap-2">
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

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col flex-1" style="min-height:220px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] flex-shrink-0">
                        Description <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:160px;background:#ffffff;border:1.5px solid #e8e0f0;">{{ $editDescription ?: 'No description provided.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editDescription"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editDescription']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="Describe the role, responsibilities…" maxlength="5000"
                                  style="min-height:160px;"></textarea>
                        @if(isset($editErrors['editDescription']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editDescription'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] flex-shrink-0">
                        Qualifications <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:120px;background:#ffffff;border:1.5px solid #e8e0f0;">{{ $editQualifications ?: 'No qualifications listed.' }}</div>
                        <textarea x-show="editMode" x-cloak
                                  wire:model.defer="editQualifications"
                                  class="w-full flex-1 px-3 py-2 border-[1.5px] rounded-xl text-sm bg-white text-[#222] resize-none transition focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 {{ isset($editErrors['editQualifications']) ? 'border-red-400 bg-red-50' : 'border-gray-300' }}"
                                  placeholder="e.g. Bachelor's degree in relevant field…" maxlength="3000"
                                  style="min-height:120px;"></textarea>
                        @if(isset($editErrors['editQualifications']))<p class="text-red-600 flex items-center gap-1 mt-1 text-xs"><i class="fas fa-circle-exclamation text-[10px]"></i>{{ $editErrors['editQualifications'] }}</p>@endif
                    </div>
                </div>

                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden flex flex-col" style="min-height:180px;">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91] flex-shrink-0">
                        How to Apply <span x-show="editMode" x-cloak class="text-red-400 font-semibold ml-0.5">*</span>
                    </div>
                    <div class="p-3.5 flex flex-col flex-1">
                        <div x-show="!editMode"
                             class="view-content-box flex-1 px-3 py-2 rounded-xl text-sm text-[#333333] leading-relaxed whitespace-pre-wrap overflow-y-auto scroll-c"
                             style="min-height:120px;background:#ffffff;border:1.5px solid #e8e0f0;">{{ $editApplicationInstructions ?: 'No application instructions provided.' }}</div>
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

        {{-- RIGHT: History + Tips + Actions --}}
        <div class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white flex flex-col overflow-y-auto scroll-c">
            <div class="p-3 space-y-3 flex-1">

                @if($editingJob)
                <div class="bg-white border-[1.5px] border-[#e8e0f0] rounded-2xl overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
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
                    <div class="px-3.5 py-2 bg-[#faf7fc] border-b border-[#e8e0f0] flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[.07em] text-[#7a3f91]">
                        Tips
                    </div>
                    <div class="p-3.5">
                        <ul class="space-y-2">
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Changes are saved immediately — no approval required.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>If inactive with past deadline, update the deadline first then activate.</span></li>
                            <li class="flex items-start gap-1.5 text-[11px] text-[#333333]"><i class="fas fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-[9px]"></i><span>Deleting removes it from the active list immediately.</span></li>
                        </ul>
                    </div>
                </div>

            </div>

            <div class="flex-shrink-0 px-3 py-3 border-t border-gray-200 bg-white space-y-2">
                <div x-show="editMode" x-cloak>
                    <button type="button" wire:click="saveEditJob"
                            wire:loading.attr="disabled"
                            wire:target="saveEditJob"
                            class="w-full px-5 py-3 rounded-xl text-sm font-semibold text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        <span wire:loading wire:target="saveEditJob"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                        <span wire:loading.remove wire:target="saveEditJob"><i class="fas fa-floppy-disk text-xs"></i></span>
                        <span wire:loading wire:target="saveEditJob">Saving…</span>
                        <span wire:loading.remove wire:target="saveEditJob">Save Changes</span>
                    </button>
                </div>
                <div x-show="!editMode" class="pb-1">
                    <p class="text-center text-xs text-[#777777]">Deactivate this job (top-right) to make changes.</p>
                </div>
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


{{-- ════════════════════════════════════════════════════════════════════════
     VIEW JOB MODAL — organizer-posted jobs, read-only
     z-50, full screen, 2-column
════════════════════════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingJob)
@php
    $job              = $this->viewingJob;
    $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft         = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
    $isExp            = now('Asia/Manila')->startOfDay()->gt($dl->copy()->startOfDay());
    $isUrgentView     = $daysLeft <= 7 && !$isExp;
    $createdPH        = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType      = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $isActiveView     = $job->status === 'ACTIVE';
    $isOrgDeletedView = $job->status === 'ORGANIZER_DELETED';
    $viewCanShare     = !$isExp && $isActiveView;
    $viewJobImgUrl    = $this::jobImageUrl($job->job_image ?? null);
    $organizerName    = $job->organizer?->name ?? null;
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
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details — Posted by Coordinator</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $job->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($viewCanShare)
                <button type="button" wire:click="openShareJobModal({{ $job->id }})"
                        class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24">
                    <i class="fas fa-share-nodes text-white text-sm"></i>
                    <span class="mtip">Share</span>
                </button>
            @endif
            @if(!$isOrgDeletedView)
                <button type="button" wire:click="confirmDelete({{ $job->id }})"
                        class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-red-500/15 border border-red-300/30 hover:bg-red-500/25">
                    <i class="fas fa-trash text-white text-sm"></i>
                    <span class="mtip">Delete</span>
                </button>
            @endif
            <button wire:click="closeViewModal" type="button"
                    class="modal-top-btn relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22">
                <i class="fas fa-xmark text-white text-sm"></i>
                <span class="mtip">Close</span>
            </button>
        </div>
    </div>

    @if($isOrgDeletedView)
    <div class="bg-red-50 border-b border-red-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <i class="fas fa-trash text-red-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-red-800 font-semibold">This job was deleted by the Coordinator. Use the <strong>Restore</strong> button in the table row to recover it.</p>
    </div>
    @else
    <div class="bg-purple-50 border-b border-purple-200 px-6 py-2 flex-shrink-0 flex items-center gap-2.5">
        <i class="fas fa-shield-halved text-purple-500 flex-shrink-0 text-xs"></i>
        <p class="text-sm text-purple-800 font-semibold">This job was posted by a Coordinator. View only — editing is not available here.</p>
    </div>
    @endif

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto scroll-c">

            <div class="mx-3 mt-3 mb-0 flex-shrink-0 rounded-xl overflow-hidden" style="height:160px;">
                <img src="{{ $viewJobImgUrl }}" alt="{{ $job->job_title }}" class="w-full h-full object-cover"
                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
            </div>

            <div class="mx-3 mt-2 mb-2 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-between px-4 py-2"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="flex items-center gap-2">
                    @if($isOrgDeletedView)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-red-500/80 text-white text-xs font-semibold"><i class="fas fa-trash text-[9px]"></i> Deleted</span>
                    @elseif($isActiveView)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/80 text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Active</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/80 text-white text-xs font-semibold"><i class="fas fa-circle-pause text-[9px]"></i> Inactive</span>
                    @endif
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-purple-300/40 text-white text-xs font-semibold"><i class="fas fa-user-tie text-[9px]"></i> Coordinator Post</span>
                </div>
                <i class="fas fa-briefcase text-white/20 text-2xl"></i>
            </div>

            <div class="flex flex-col gap-2 px-3 pb-3">
                @if($organizerName)
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-purple-100"><i class="fas fa-user-tie text-purple-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#555555] mb-0.5">Posted By</p>
                        <p class="font-bold text-sm text-[#333333] truncate">{{ $organizerName }}</p>
                    </div>
                </div>
                @endif
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


{{-- ════════════════════════════════════════════════════════════════════════
     SHARE JOB — SLIDE-OVER
════════════════════════════════════════════════════════════════════════ --}}
@if($showShareJobModal)
@php
    $sjBaseUrl  = $this->jobsBaseUrl();
    $sjHost     = parse_url(config('app.url'), PHP_URL_HOST) ?? 'alumniphilcst.com';
    $sjTargets  = $shareJobTarget ? str_replace(',', ', ', $shareJobTarget) : 'All Alumni';
    $sjDescPrev = mb_strlen($shareJobDescription) > 160 ? mb_substr($shareJobDescription, 0, 160) . '…' : $shareJobDescription;

    $sjLines   = [];
    $sjLines[] = "💼 Job Opportunity: {$shareJobTitle}";
    $sjLines[] = "🏢 {$shareJobCompany}" . ($shareJobLocation ? " · {$shareJobLocation}" : '');
    $sjLines[] = "⏰ {$shareJobEmpType}" . ($shareJobExpLevel ? " · {$shareJobExpLevel}" : '');
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
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto scroll-c">
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
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-[#f5eef9] text-[#7a3f91]">{{ $shareJobEmpType }}</span>
                                @if($shareJobTarget)
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-[#333333]">{{ Str::limit($sjTargets, 30) }}</span>
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
                        <p class="text-sm text-blue-700 leading-relaxed">Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the post caption to your clipboard and opens the platform. Just press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.</p>
                    </div>
                </div>

                <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-shield-halved text-[#7a3f91] text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-[#5e2f72]">Post to Staff Channel</p>
                        <p class="text-sm mt-0.5 text-[#7a3f91]">Posts the job directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto scroll-c">
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
                            <defs><linearGradient id="mgr_adm_jp" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_adm_jp)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
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
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#666666] flex items-center justify-center gap-1.5">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ══ ROW HOVER TOOLTIP + DEADLINE-OVERLAY TOOLTIP SCRIPT ══ --}}
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

    // Generic fixed/overlay tooltip for any [data-tip] element. Always
    // position:fixed + follows the mouse + shows ABOVE the cursor, so it
    // can never get clipped by a scrollable ancestor (table, modal body,
    // etc.) — fixes the "Update deadline to activate" readability bug.
    function bindDeadlineTips() {
        var dTip     = document.getElementById('eo-deadline-tip');
        var dTipText = document.getElementById('eo-deadline-tip-text');
        if (!dTip || !dTipText) return;

        document.querySelectorAll('[data-tip]').forEach(function (el) {
            if (el._eoDeadlineTipBound) return;
            el._eoDeadlineTipBound = true;

            el.addEventListener('mouseenter', function () {
                dTipText.textContent = this.getAttribute('data-tip') || '';
                dTip.style.opacity = '1';
            });

            el.addEventListener('mousemove', function (e) {
                dTip.style.left = e.clientX + 'px';
                dTip.style.top  = e.clientY + 'px';
            });

            el.addEventListener('mouseleave', function () {
                dTip.style.opacity = '0';
            });
        });
    }

    bindRows();
    bindDeadlineTips();
    document.addEventListener('livewire:updated', function () {
        bindRows();
        bindDeadlineTips();
    });
})();
</script>

</div>