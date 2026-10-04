{{-- resources/views/livewire/admin/job-posts.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use App\Models\JobPosting;
use App\Models\JobOption;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithoutUrlPagination;

    protected function queryString(): array { return []; }

    protected string $paginationTheme = 'tailwind';

    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterType    = '';
    public string $filterCollege = '';
    public string $filterSort    = 'recent';

    public string $myDisplayName = '';

    // ── View Modal ─────────────────────────────────────────────────────────────
    public bool $showViewModal = false;
    public ?int $viewingJobId  = null;

    // ── Share Modal ────────────────────────────────────────────────────────────
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
    public string $shareJobPhotoUrl    = '';

    private function authorizeRole(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }

    public function mount(): void
    {
        $this->authorizeRole();

        $this->myDisplayName = auth()->user()?->name ?? 'Admin';

        // ── Auto-expire past-deadline active jobs ──────────────────────────
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

        // ── Dispatch notifications for recently posted jobs ─────────────────
        $this->dispatchJobNotifications();

        // ── Auto-apply the status filter when arriving from the admin
        // dashboard's Job Postings Snapshot mini-tiles (goToJobs() there
        // stores the target status in session before redirecting here) —
        // same "click a stat -> land already filtered" pattern as Events'
        // admin_events_filter handling. Values match $filterStatus 1:1:
        // ACTIVE, INACTIVE, EXPIRING.
        $jobsFilter = session()->pull('admin_jobs_filter', '');
        if (in_array($jobsFilter, ['ACTIVE', 'INACTIVE', 'EXPIRING'], true)) {
            $this->filterStatus = $jobsFilter;
        }

        // ── Auto-open View Details when arriving from a notification
        // (sidebar notif panel routes here with ?highlight_job={id}) —
        // same "click a notif -> land with the record already open"
        // pattern as Event Management's highlight_event handling. Only
        // opens if the job still exists; a deleted job's notif link
        // just lands on the plain table instead of erroring.
        $highlightJobId = request()->query('highlight_job');
        if ($highlightJobId && JobPosting::whereKey($highlightJobId)->exists()) {
            $this->viewJob((int) $highlightJobId);

            // Strip ?highlight_job=... from the address bar once the modal
            // is open — the query param has done its job, and leaving it
            // there means a manual refresh or reshare of the URL keeps
            // popping the same modal back open, plus it's just noise in
            // the URL bar. history.replaceState swaps it out in place
            // with no reload and no extra navigation entry.
            $this->js(<<<'JS'
                window.history.replaceState({}, '', window.location.pathname);
            JS);
        }
    }

    /**
     * Fire admin-job-updated browser events for every job posted
     * in the last 24 hours that hasn't been notified yet.
     *
     * Each job uses dedup_key = 'job-posted::{id}' so the JS store
     * will collapse duplicates and the backend dedup prevents re-insertion.
     */
    private function dispatchJobNotifications(): void
    {
        try {
            $recentJobs = JobPosting::with('organizer:id,name')
                ->whereIn('status', ['ACTIVE', 'INACTIVE'])
                ->where('created_at', '>=', now('Asia/Manila')->subHours(24))
                ->orderBy('created_at', 'desc')
                ->get(['id', 'job_title', 'company_name', 'organizer_id', 'created_at']);

            foreach ($recentJobs as $job) {
                // Resolve who posted it
                $posterName = $job->organizer?->name ?? 'Alumni Director';

                $this->dispatch('admin-job-posted-notify', [
                    'id'          => $job->id,
                    'title'       => $job->job_title,
                    'company'     => $job->company_name,
                    'poster'      => $posterName,
                    'created_at'  => $job->created_at->toIso8601String(),
                ]);
            }
        } catch (\Throwable) {
            // Silent — don't break page load
        }
    }

    private function sanitize(string $value): string
    {
        return strip_tags(trim($value));
    }

    /** Wraps matches of the current search term in a light-blue <mark>,
     *  same visual treatment as the Alumni Records / Yearbook pages'
     *  highlight(). Used on job_title and company_name, since those are
     *  the only two fields the search box matches against. */
    public function highlight(string $text, string $search): string
    {
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="jp-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
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
                'user_role'     => 'admin',
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

    public static function jobImageUrl(?string $path): string
    {
        $default = asset('storage/job/default-photo-job.jpg');
        if (!$path || str_contains($path, 'default-photo-job')) {
            return $default;
        }

        // Cloudinary / full URL — return as-is. Job photos are uploaded to
        // Cloudinary by the Director's Job Management page (folder
        // "job-photos"), so the DB holds a full https URL. The old code
        // always did asset('storage/' . $path), which turned that URL into
        // a broken path and silently showed the default photo.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        // Legacy local file on the public disk (older postings).
        // asset('storage/' . $path) — kept as-is (Storage::exists() was
        // resolving incorrectly on this server even with a correct DB path).
        return asset('storage/' . ltrim($path, '/'));
    }

    public function updatingSearch()        { $this->resetPage(); }
    public function updatingFilterStatus()  { $this->resetPage(); }
    public function updatingFilterType()    { $this->resetPage(); }
    public function updatingFilterCollege() { $this->resetPage(); }
    public function updatingFilterSort()    { $this->resetPage(); }

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
                'target_college','salary','deadline','status','job_image',
                'created_at','updated_at','updated_by','updated_by_role',
                'deleted_by','deleted_by_role',
            ])
            ->whereIn('status', ['ACTIVE', 'INACTIVE']);

        if ($this->filterStatus === 'EXPIRING') {
            $q->where('status', 'ACTIVE')
              ->whereBetween('deadline', [
                  now('Asia/Manila')->toDateString(),
                  now('Asia/Manila')->addDays(7)->toDateString(),
              ]);
        } elseif ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
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
            $q->where('target_college', 'like', "%{$college}%");
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

    public function viewJob(int $id): void
    {
        $this->authorizeRole();
        $this->viewingJobId  = $id;
        $this->showViewModal = true;
    }

    // Same-page notif click: the sidebar dispatches this directly (instead
    // of a full navigate) when the admin is already on Job Posts, so the
    // View Details modal opens immediately with no page flash.
    #[On('open-view-job')]
    public function openViewJobFromNotif(int $id): void
    {
        $this->viewJob($id);
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingJobId  = null;
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
        $this->shareJobPhotoUrl    = $this::jobImageUrl($job->job_image ?? null);

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
        $this->shareJobPhotoUrl    = '';
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
            'sender_type' => 'admin',
            'sender_id'   => auth()->id(),
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

<div id="jp-root" class="jp-root flex flex-col h-full min-h-0" style="overflow: hidden;">

<style>
/* ── Full-screen page: the page fits the viewport exactly (desktop) and ONLY
   the job list scrolls. --jp-h is set by the script at the bottom
   (viewport height minus the space above this page); the calc() is just
   a fallback until that runs. ── */
@media (min-width: 1024px) {
    .jp-root {
        height: var(--jp-h, calc(100dvh - 4.5rem)) !important;
        max-height: var(--jp-h, calc(100dvh - 4.5rem)) !important;
        overflow: hidden !important;
    }
    .jp-root #jb-content-block { min-height: 260px; }
}
/* ── Search highlight ── */
mark.jp-hl {
    background: #BFDBFE;
    color: inherit;
    border-radius: 2px;
    padding: 0 1px;
    font-weight: 700;
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

.adm-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.adm-scroll::-webkit-scrollbar-track { background: #eeeeee; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #cccccc; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Close-button tooltip (Share modal) — mirrors Event Monitoring's share modal ── */
.adm-share-close-btn { position: relative; }
.adm-share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
}
.adm-share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.adm-share-close-btn:hover .tip { opacity: 1; }

/* ── Table container height — always 58vh, never shrinks or grows regardless of content or flex siblings ── */

/* ── Share button tooltip (table rows) — pure CSS hover, no JS dependency ── */
.adm-share-tip-wrap { position: relative; display: inline-flex; }
.adm-share-tip-bubble {
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #111111; color: #ffffff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s;
    z-index: 999; box-shadow: 0 4px 14px rgba(0,0,0,.30);
}
.adm-share-tip-bubble::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #111111;
}
.adm-share-tip-wrap:hover .adm-share-tip-bubble { opacity: 1; }

@media (max-width: 640px) {
    .adm-table-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: none !important;
        box-shadow: none !important;
    }
}

/* ══ Mobile stacked card row ══ */
.adm-mrow {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    background: #fff;
    border-bottom: 1px solid #F0ECF5;
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: background .08s ease;
}
.adm-mrow:active { background: #F7F4FA; }

select.adm-select-arrow {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23111111' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    cursor: pointer;
}

/* ── Stat card ── */
.adm-stat-card {
    border-radius: 1rem;
    border: 1.5px solid #e8e0f0;
    background: #ffffff;
    padding: 0.875rem 1.125rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.adm-stat-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.625rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

@keyframes urgentPulse {
    0%,100% { opacity:1; }
    50%      { opacity:.6; }
}
.urgent-pulse { animation: urgentPulse 1.6s ease-in-out infinite; }

[x-cloak] { display:none !important; }

/* ══════════════════════════════════════════════
   VIEW MODAL — LEFT PANEL (white) field cards
   RIGHT PANEL (light gray #f2f2f2) body text
   ALL TEXT = #111111 black, zero gray text
   ══════════════════════════════════════════════ */

/* Left panel field cards — white bg, black text */
.vw-field {
    padding: 0.6rem 0.8rem;
    background: #ffffff;
    border: 1.5px solid #e0e0e0;
    border-radius: 0.75rem;
}
.vw-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #111111;
    margin-bottom: 3px;
}
.vw-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: #111111;
    line-height: 1.5;
}
.vw-subvalue {
    font-size: 0.75rem;
    font-weight: 400;
    color: #555555;
    margin-top: 2px;
}

/* Chip badges */
.vw-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    background: #ffffff;
    border: 1.5px solid #cccccc;
    color: #111111;
}

/* Right panel section title */
.vw-section-title {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #111111;
    margin-bottom: 0.5rem;
}

/* Right panel body text box — light gray bg, black text */
.vw-body-box {
    font-size: 0.875rem;
    font-weight: 400;
    line-height: 1.8;
    color: #333333;
    white-space: pre-wrap;
    background: #ffffff;
    border: 1.5px solid #e0e0e0;
    border-radius: 0.75rem;
    padding: 1rem 1.125rem;
}

/* ── Seek-style layout (same design as Job Opportunities) ── */
.adm-table-card { min-height:0; }
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center; background-repeat: no-repeat; background-size: 1.1em 1.1em;
    padding-right: 2.1rem; -webkit-appearance: none; appearance: none;
}
/* Header: icon + text on the left (same pattern as Alumni Dashboard) */
.jb-header { display: flex; align-items: center; gap: .75rem 1rem; flex-shrink: 0; width: 100%; }
.jb-header-icon {
    width: 2.75rem; height: 2.75rem; border-radius: 1rem; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: #7A3F91; color: #ffffff; font-size: 1.05rem;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -2px rgba(0,0,0,.1);
}
.jb-header-text { min-width: 0; text-align: left; }
@media (max-width: 639px) {
    .jb-header-icon { width: 2.5rem; height: 2.5rem; border-radius: .85rem; font-size: .95rem; }
    .jb-header h1 { font-size: 1.05rem; line-height: 1.3; }
    .jb-header p  { font-size: .8rem; }
    /* list panel goes edge to edge on phones */
    .jb-block {
        margin-left: -1.25rem; margin-right: -1.25rem; margin-top: 0 !important;
        border-left: 0 !important; border-right: 0 !important; border-bottom: 0 !important;
        border-radius: 0 !important; box-shadow: none !important;
    }
}

/* Filters toggle button exists on phones only; from 640px up the filter
   panel is always visible so the button is hidden. */
@media (min-width: 640px) {
    .jb-filter-toggle { display: none !important; }
}

@keyframes detailIn { from { opacity: 0; } to { opacity: 1; } }
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }

@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

.pre-wrap { white-space: pre-wrap; }

#jb-cursor-label {
    position: fixed;
    z-index: 99999;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 5px;
    background: #111827;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    box-shadow: 0 4px 16px rgba(0,0,0,.28);
    user-select: none;
    font-family: ui-sans-serif, system-ui, sans-serif;
    opacity: 0;
    visibility: hidden;
    transition: opacity .1s ease, visibility .1s ease;
    left: -999px;
    top: -999px;
}
#jb-cursor-label svg {
    width: 11px; height: 11px; flex-shrink: 0;
    fill: none; stroke: #fff; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round;
}

[data-jb-card] { transition: background .15s ease; position: relative; }

/* Seek-style list rows */
.jb-list-row { background: #fff; }
.jb-list-row:hover { background: #faf7fc; }
.jb-list-row-active { background: #f5eef9 !important; border-left: 3px solid #7a3f91 !important; }
.jb-list-row-active:hover { background: #f0e8f7 !important; }

/* ── Job card click spinner ───────────────────────────
   Same purple "..." dot loader used on the Alumni Dashboard
   (.dash-card-clickable), applied here to the job card since
   it opens the detail view via wire:click instead of a page nav. */
[data-jb-card].is-loading > *:not(.jb-card-spinner) {
    filter: blur(4px);
    opacity: 0.5;
    pointer-events: none;
    user-select: none;
}
.jb-card-spinner {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 6px;
    z-index: 40;
}
.jb-card-spinner span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #7A3F91;
    animation: jbDotPulse 1.1s ease-in-out infinite;
}
.jb-card-spinner span:nth-child(2) { animation-delay: 0.15s; }
.jb-card-spinner span:nth-child(3) { animation-delay: 0.3s; }
@keyframes jbDotPulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}
[data-jb-card].is-loading .jb-card-spinner {
    display: flex;
}
[data-jb-card].is-loading {
    pointer-events: none;
}

/* ── Global "one loading at a time" lock ──────────────────────────
   Copied from .dash-card-clickable/.is-blocked pattern in dashboard.
   When a card or filter triggers a request:
     • the clicked card → .is-loading (spinner shows, blurred content)
     • every other card → .is-blocked (dimmed, no pointer, no hover)
     • filter inputs / selects / pagination → .jb-el-blocked
   Cleared on commit succeed/fail and on livewire:navigated. ── */

/* Other cards while one is loading */
[data-jb-card].is-blocked {
    pointer-events: none !important;
    cursor: default !important;
    opacity: 0.55;
    filter: grayscale(25%);
}
[data-jb-card].is-blocked:hover {
    border-color: inherit !important;
    box-shadow: none !important;
}

/* Filter inputs, selects, pagination buttons while any request is in flight */
.jb-el-blocked {
    pointer-events: none !important;
    cursor: default !important;
    opacity: 0.55;
    user-select: none !important;
    -webkit-user-select: none !important;
}

/* ── Filter bar selects + inputs: default pointer, no text selection on click ── */
.filter-input {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
}
.filter-input[type="text"],
input.filter-input {
    cursor: text;
    user-select: text;
    -webkit-user-select: text;
}
select.filter-input option {
    cursor: pointer;
}

.card-share-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0; z-index: 2;
}
.card-share-btn:hover { background: #dbeafe; border-color: #93c5fd; transform: scale(1.08); }
.card-share-btn .tip {
    position: absolute; bottom: calc(100% + 7px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.card-share-btn .tip::after {
    content: ''; position: absolute; top: 100%; right: 10px;
    border: 4px solid transparent; border-top-color: #111827;
}
.card-share-btn:hover .tip { opacity: 1; }

.detail-top-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    cursor: pointer; transition: background .15s, transform .1s;
    flex-shrink: 0; border: none; outline: none;
}
.detail-top-btn:active { transform: scale(.93); }
.detail-top-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.detail-top-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.detail-top-btn:hover .tip { opacity: 1; }

.detail-top-btn.share-btn { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); color: #fff; }
.detail-top-btn.share-btn:hover { background: rgba(255,255,255,.24); }
.detail-top-btn.close-btn { background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.15); }
.detail-top-btn.close-btn:hover { background: rgba(255,255,255,.22); }
.detail-top-btn.close-btn svg { width: 13px; height: 13px; stroke: #fff; stroke-width: 2.5; stroke-linecap: round; }

/* ─────────────────────────────────────────────
   PHILCST "OFFICIAL POST" DETAIL STYLING
───────────────────────────────────────────── */
.philcst-post-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 14px; overflow: hidden; }
.philcst-post-banner { width: 100%; height: 180px; object-fit: cover; display: block; background: #f3f4f6; }
.philcst-post-ribbon {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #7a3f91; background: #f5eef9; border: 1px solid #e3cdf0;
    padding: 5px 12px; border-radius: 999px;
}
.philcst-checklist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.philcst-checklist li { display: flex; align-items: flex-start; gap: 10px; font-size: clamp(15px, 1.2vw, 17px); line-height: 1.6; color: #333333; }
.philcst-checklist li .chk {
    flex-shrink: 0; width: 22px; height: 22px; border-radius: 6px;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center; font-size: 12px; margin-top: 1px;
}

/* ─────────────────────────────────────────────
   DETAIL VIEW — fixed-height, no page scroll.
   Two columns: left sidebar (meta/info), right
   content. Only the two inner panels scroll on
   their own if their content is long — the page
   itself never grows past the viewport.
───────────────────────────────────────────── */
.detail-side-item { display: flex; align-items: center; gap: 10px; }
.detail-side-icon {
    flex-shrink: 0; width: 38px; height: 38px; border-radius: 9px;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center; font-size: 16px;
}
.detail-side-label { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #666; margin: 0; }
.detail-side-value { font-size: 17px; font-weight: 600; color: #333333; margin: 0; line-height: 1.4; }

/* ─────────────────────────────────────────────
   RESPONSIVE — icon-only on small / touch screens:
   tooltips and the mouse-follow label disappear.
───────────────────────────────────────────── */
@media (max-width: 767px), (hover: none) and (pointer: coarse) {
    #jb-cursor-label { display: none !important; }
    .card-share-btn .tip,
    .detail-top-btn .tip,
    .share-close-btn .tip { display: none !important; }
}

.detail-page * {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                 "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    font-style: normal !important;
}
.detail-header-title {
    font-size: 15px; font-weight: 600; color: #fff; line-height: 1.3;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.detail-label { font-style: italic; }

</style>

{{-- Mouse-following cursor label (desktop only) --}}
<div id="jb-cursor-label">
    <svg viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
    View Details
</div>


{{-- Action button tooltip --}}
<div id="admjob-action-tip"
     class="fixed bg-[#111111] text-white text-[11px] font-semibold px-2.5 py-1.5 rounded-md whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(-50%, -100%);">
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

    {{-- ── PAGE HEADER ── --}}
    <div class="jb-header">
        <div class="jb-header-icon">
            <i class="fas fa-briefcase"></i>
        </div>
        <div class="jb-header-text">
            <h1 class="text-xl font-semibold tracking-tight text-gray-900" style="user-select:none;-webkit-user-select:none;">Job Postings</h1>
            <p class="text-sm font-semibold leading-relaxed mt-0.5 text-gray-700" style="user-select:none;-webkit-user-select:none;">
                Monitor and review job listings across
                <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">
                    <i class="fas fa-building-columns text-[9px]"></i> all colleges
                </span>
            </p>
        </div>
    </div>

    {{-- ── STAT CARDS (Expiring Soon removed → 3 cards) ── --}}
    @php $s = $this->stats; @endphp
    <div class="grid grid-cols-3 gap-3 flex-shrink-0">

        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#f5eef9;">
                <i class="fas fa-briefcase text-sm" style="color:#7a3f91;"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Total</p>
                <p class="text-xl font-bold leading-tight text-[#111111]">{{ $s['total'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-emerald-50">
                <i class="fas fa-circle-check text-sm text-emerald-600"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Active</p>
                <p class="text-xl font-bold leading-tight text-emerald-600">{{ $s['active'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-amber-50">
                <i class="fas fa-ban text-sm text-amber-600"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Inactive</p>
                <p class="text-xl font-bold leading-tight text-amber-600">{{ $s['inactive'] }}</p>
            </div>
        </div>
    </div>

    {{-- Shared detail variables (used by the right panel AND the mobile full-screen view) --}}
    @php
        $vj = $showViewModal ? $this->viewingJob : null;
        if ($vj) {
            $isActive     = $vj->status === 'ACTIVE';
            $vDl          = \Carbon\Carbon::parse($vj->deadline)->setTimezone('Asia/Manila');
            $vDaysLeft    = (int) now('Asia/Manila')->startOfDay()->diffInDays($vDl->copy()->startOfDay(), false);
            $vIsExp       = $vDaysLeft < 0;
            $vIsUrgent    = $vDaysLeft <= 7 && !$vIsExp;
            if ($vDaysLeft === 0)      $dlLabel = 'Closes today';
            elseif ($vDaysLeft === 1)  $dlLabel = '1 day left';
            elseif ($vDaysLeft > 1)    $dlLabel = $vDaysLeft . ' days left';
            else                       $dlLabel = 'Deadline passed';
            $dlIsUrgent   = $vDaysLeft <= 3;
            $dlIsSoon     = !$dlIsUrgent && $vDaysLeft <= 14;
            $dlValueClass = $vIsExp ? 'text-gray-500 font-bold' : ($dlIsUrgent ? 'text-red-600 font-bold' : ($dlIsSoon ? 'text-orange-700 font-bold' : 'text-gray-900 font-semibold'));
            $vCreatedPH   = \Carbon\Carbon::parse($vj->created_at)->setTimezone('Asia/Manila');
            $displayType  = ($vj->company_type === $vj->company_name) ? 'PHILCST' : $vj->company_type;
            $isPhilcst    = $displayType === 'PHILCST';
            $vOrgName     = $vj->organizer?->name ?? null;
            $vOrgCollege  = null;
            if ($vj->organizer) {
                $vOrgCollege = \App\Models\Course::where('college', $vj->organizer->department)->value('college')
                    ?? $vj->organizer->department ?? null;
            }
            $vCanShare    = $isActive && !$vIsExp;
            $detailImg    = $this::jobImageUrl($vj->job_image ?? null);
            $hasQual      = !empty($vj->qualifications);
            $hasInstr     = !empty($vj->application_instructions);
            $qualLines    = $hasQual  ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $vj->qualifications)), fn($l) => $l !== '')) : [];
            $instrLines   = $hasInstr ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $vj->application_instructions)), fn($l) => $l !== '')) : [];
        }
    @endphp


    {{-- ══ SEEK-STYLE 2-COLUMN LAYOUT (same design as Job Opportunities) ══ --}}
    <div class="jb-block adm-table-card flex-1 min-h-0 flex gap-0 rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm relative"
         id="jb-content-block">

        {{-- ── LEFT PANEL: Filter bar + List + Pagination ── --}}
        <div class="flex flex-col w-full lg:w-[420px] xl:w-[460px] flex-shrink-0 border-r border-[#E8E0F0] bg-white min-h-0 relative">

            @php
                $hasActiveFilters  = $search !== '' || $filterStatus !== '' || $filterType !== '' || $filterCollege !== '' || $filterSort !== 'recent';
                $activeFilterCount = ($filterStatus !== '' ? 1 : 0) + ($filterType !== '' ? 1 : 0) + ($filterCollege !== '' ? 1 : 0);
            @endphp
            <div class="bg-gray-50 border-b border-[#E8E0F0] flex-shrink-0 select-none"
                 x-data="{ open: window.matchMedia('(min-width: 640px)').matches }"
                 @resize.window="if (window.matchMedia('(min-width: 640px)').matches) open = true">

                {{-- Row 1: search + filter toggle (phones) --}}
                <div class="flex items-center gap-2 px-3 py-2.5">
                    <div class="relative flex-1 min-w-0"
                         wire:ignore
                         x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                        <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                               placeholder="Search jobs…"
                               class="filter-input w-full h-10 sm:h-9 pl-8 pr-8 text-[16px] sm:text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                                      hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               style="cursor:text;user-select:text;-webkit-user-select:text;"
                               autocomplete="off" maxlength="100" spellcheck="false">
                        <button type="button" x-show="q" x-cloak
                                @click="q=''; $wire.set('search','')"
                                class="absolute right-1.5 top-1/2 -translate-y-1/2 w-6 h-6 inline-flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition"
                                aria-label="Clear search">
                            <i class="fas fa-xmark text-[11px]"></i>
                        </button>
                    </div>

                    <button type="button"
                            @click="open = !open"
                            :aria-expanded="open.toString()"
                            aria-controls="jb-filter-panel"
                            aria-label="Toggle filters"
                            class="jb-filter-toggle relative flex-shrink-0 inline-flex items-center justify-center gap-1.5 h-10 w-10 rounded-lg border text-xs font-semibold transition active:scale-95"
                            :class="open ? 'bg-[#7a3f91] border-[#7a3f91] text-white' : 'bg-white border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300'">
                        <i class="fas fa-sliders text-[13px]"></i>
                        @if($activeFilterCount > 0)
                            <span class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center rounded-full bg-rose-500 text-white text-[10px] font-bold leading-none border-2 border-gray-50">{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                </div>

                {{-- Row 2: collapsible filters --}}
                <div id="jb-filter-panel"
                     x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-1"
                     class="px-3 pb-3">

                    <div class="flex items-center justify-between gap-2 mb-2 px-0.5">
                        <span class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] select-none">Filters</span>
                        <button wire:click="resetFilters"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-60 cursor-wait"
                                wire:target="resetFilters"
                                data-jb-reset
                                @disabled(!$hasActiveFilters)
                                class="inline-flex items-center gap-1.5 px-3 py-[5px] rounded-lg text-xs font-semibold border transition active:scale-95
                                       {{ $hasActiveFilters ? 'bg-white border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300 cursor-pointer' : 'bg-gray-50 border-gray-100 text-gray-300 cursor-not-allowed' }}">
                            <span wire:loading.remove wire:target="resetFilters"><i class="fas fa-rotate-left text-xs"></i> Reset</span>
                            <span wire:loading wire:target="resetFilters"><i class="fas fa-spinner fa-spin text-xs" style="color:#7a3f91;"></i> Reset</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex flex-col gap-1 min-w-0">
                            <span class="text-[11px] font-semibold text-gray-500 px-0.5">Status</span>
                            <select wire:model.live="filterStatus"
                                    class="filter-input w-full min-w-0 truncate h-10 sm:h-9 px-2.5 text-[16px] sm:text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                                <option value="">All Statuses</option>
                                <option value="ACTIVE">Active</option>
                                <option value="INACTIVE">Inactive</option>
                                @if($filterStatus === 'EXPIRING')<option value="EXPIRING">Expiring Soon</option>@endif
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 min-w-0">
                            <span class="text-[11px] font-semibold text-gray-500 px-0.5">Type</span>
                            <select wire:model.live="filterType"
                                    class="filter-input w-full min-w-0 truncate h-10 sm:h-9 px-2.5 text-[16px] sm:text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                                <option value="">All Types</option>
                                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 min-w-0 col-span-2">
                            <span class="text-[11px] font-semibold text-gray-500 px-0.5">College</span>
                            <select wire:model.live="filterCollege"
                                    class="filter-input w-full min-w-0 truncate h-10 sm:h-9 px-2.5 text-[16px] sm:text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                                <option value="">All Colleges</option>
                                @foreach($this->collegesWithDepts as $college)
                                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Loading overlay (centered over the list) --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden pointer-events-none"
                 wire:loading.flex wire:target="search,filterStatus,filterType,filterCollege,resetFilters,previousPage,nextPage,gotoPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            {{-- Job list --}}
            <div class="flex-1 min-h-0 overflow-y-auto scroll-thin transition-opacity duration-200"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,filterStatus,filterType,filterCollege,resetFilters,previousPage,nextPage,gotoPage">

                @if($this->jobPostings->count() > 0)
                    @foreach($this->jobPostings as $job)
                    @php
                        $rActive    = $job->status === 'ACTIVE';
                        $rPassed    = $job->_isDeadlinePassed ?? false;
                        $rDl        = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                        $rDaysLeft  = (int) now('Asia/Manila')->startOfDay()->diffInDays($rDl->copy()->startOfDay(), false);
                        $rUrgent    = $rActive && !$rPassed && $rDaysLeft <= 7;
                        $rLabel     = $rDaysLeft === 0 ? 'Closes today' : ($rDaysLeft === 1 ? '1 day left' : $rDaysLeft . ' days left');
                        $rCanShare  = $rActive && !$rPassed;
                        $rOrgName   = $job->organizer?->name ?? 'Alumni Director';
                        $rImg       = $this::jobImageUrl($job->job_image ?? null);
                        $rSelected  = $viewingJobId === $job->id;
                    @endphp

                    <div wire:key="job-card-{{ $job->id }}"
                         class="jb-list-row relative cursor-pointer select-none border-b border-gray-100 transition-colors
                                {{ $rSelected ? 'jb-list-row-active' : '' }}
                                {{ (!$rActive || $rPassed) ? 'opacity-70' : '' }}"
                         data-jb-card
                         wire:click="viewJob({{ $job->id }})"
                         role="button" tabindex="0"
                         onkeypress="if(event.key==='Enter')this.click()">

                        <div class="jb-card-spinner"><span></span><span></span><span></span></div>

                        <div class="flex gap-3 px-4 py-4">
                            <img src="{{ $rImg }}" alt="{{ $job->job_title }}"
                                 loading="lazy"
                                 class="w-16 h-16 rounded-lg object-contain bg-gray-50 border border-gray-100 flex-shrink-0"
                                 onerror="this.onerror=null;this.src='{{ asset('storage/job/default-photo-job.jpg') }}';">

                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-[16px] leading-snug line-clamp-1 text-[#7a3f91]">{!! $this->highlight($job->job_title, $search) !!}</h3>
                                <p class="text-[14px] font-medium text-gray-600 mt-0.5 line-clamp-1">{!! $this->highlight($job->company_name, $search) !!}</p>

                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 mt-1.5">
                                    @if($job->location)
                                    <span class="text-[13px] text-gray-500 flex items-center gap-1">
                                        <i class="fas fa-location-dot text-[12px] text-[#7a3f91]/60"></i>{{ \Illuminate\Support\Str::limit($job->location, 30) }}
                                    </span>
                                    @endif
                                    @if($job->employment_type)
                                    <span class="text-[13px] text-gray-500 flex items-center gap-1">
                                        <i class="fas fa-briefcase text-[12px] text-[#7a3f91]/60"></i>{{ $job->employment_type }}
                                    </span>
                                    @endif
                                    @if($job->salary)
                                    <span class="text-[13px] text-gray-500 flex items-center gap-1">
                                        <i class="fas fa-sack-dollar text-[12px] text-[#7a3f91]/60"></i>{{ $job->salary }}
                                    </span>
                                    @endif
                                    <span class="text-[13px] text-gray-500 flex items-center gap-1 min-w-0">
                                        <i class="fas fa-user-tie text-[12px] text-[#7a3f91]/60"></i><span class="truncate max-w-[150px]">{{ $rOrgName }}</span>
                                    </span>
                                </div>

                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-[13px] text-gray-400">{{ \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila')->diffForHumans() }}</span>
                                    <div class="flex items-center gap-2">
                                        @if(!$rActive)
                                            <span class="text-[12px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                                <i class="fas fa-ban text-[11px] mr-0.5"></i>Inactive
                                            </span>
                                        @elseif($rPassed)
                                            <span class="text-[12px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full border border-gray-200">Expired</span>
                                        @elseif($rUrgent)
                                            <span class="text-[12px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded-full border border-red-200">
                                                <i class="fas fa-fire text-[11px] mr-0.5"></i>{{ $rLabel }}
                                            </span>
                                        @else
                                            <span class="text-[12px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                                <i class="fas fa-circle-check text-[11px] mr-0.5"></i>Active
                                            </span>
                                        @endif
                                        @if($rCanShare)
                                        <button type="button"
                                                data-jb-share
                                                wire:click.stop="openShareJobModal({{ $job->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openShareJobModal({{ $job->id }})"
                                                class="card-share-btn">
                                            <span wire:loading.remove wire:target="openShareJobModal({{ $job->id }})"><i class="fas fa-share-nodes text-[12px]"></i></span>
                                            <span wire:loading wire:target="openShareJobModal({{ $job->id }})"><i class="fas fa-spinner fa-spin text-[12px]"></i></span>
                                            <span class="tip">Share</span>
                                        </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                @else
                <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                        <i class="fas fa-briefcase text-xl text-gray-400"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-base text-gray-700">
                            @if($search || $filterStatus || $filterType || $filterCollege) No jobs match your filters
                            @else No job postings yet @endif
                        </p>
                        <p class="text-sm mt-1 text-gray-500">
                            @if($search || $filterStatus || $filterType || $filterCollege) Try clearing your filters to see all postings.
                            @else No job postings have been submitted yet. @endif
                        </p>
                    </div>
                    @if($search || $filterStatus || $filterType || $filterCollege)
                        <button wire:click="resetFilters" data-jb-reset
                                class="px-4 py-2 rounded-xl text-sm font-bold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                            <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                        </button>
                    @endif
                </div>
                @endif
            </div>

            {{-- ── PAGINATION BAR ── --}}
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
            <div class="flex items-center justify-between gap-2 flex-wrap px-4 py-2.5 min-h-[44px]
                        bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0 select-none"
                 style="padding-bottom: calc(0.625rem + env(safe-area-inset-bottom, 0px));">
                <p class="text-white/80 text-[11px] font-normal whitespace-nowrap">
                    <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong> / <strong class="text-white font-bold">{{ $total }}</strong>
                </p>
                <div class="flex items-center gap-1">
                    <button wire:click="previousPage" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-wait" wire:target="previousPage"
                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 disabled:opacity-35 disabled:cursor-not-allowed transition"
                            @if($this->jobPostings->onFirstPage()) disabled @endif aria-label="Previous">
                        <i class="fas fa-chevron-left text-[9px]"></i>
                    </button>
                    @if($pgStart > 1)
                        <button wire:click="gotoPage(1)" wire:loading.attr="disabled" wire:target="gotoPage(1)"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">
                            <span wire:loading.remove wire:target="gotoPage(1)">1</span>
                            <span wire:loading wire:target="gotoPage(1)"><i class="fas fa-spinner fa-spin text-[9px]"></i></span>
                        </button>
                        @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    @endif
                    @for($p = $pgStart; $p <= $pgEnd; $p++)
                        @if($p === $cp)
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                        @else
                            <button wire:click="gotoPage({{ $p }})" wire:loading.attr="disabled" wire:target="gotoPage({{ $p }})"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">
                                <span wire:loading.remove wire:target="gotoPage({{ $p }})">{{ $p }}</span>
                                <span wire:loading wire:target="gotoPage({{ $p }})"><i class="fas fa-spinner fa-spin text-[9px]"></i></span>
                            </button>
                        @endif
                    @endfor
                    @if($pgEnd < $lp)
                        @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                        <button wire:click="gotoPage({{ $lp }})" wire:loading.attr="disabled" wire:target="gotoPage({{ $lp }})"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">
                            <span wire:loading.remove wire:target="gotoPage({{ $lp }})">{{ $lp }}</span>
                            <span wire:loading wire:target="gotoPage({{ $lp }})"><i class="fas fa-spinner fa-spin text-[9px]"></i></span>
                        </button>
                    @endif
                    <button wire:click="nextPage" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-wait" wire:target="nextPage"
                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 disabled:opacity-35 disabled:cursor-not-allowed transition"
                            @if(!$this->jobPostings->hasMorePages()) disabled @endif aria-label="Next">
                        <i class="fas fa-chevron-right text-[9px]"></i>
                    </button>
                </div>
            </div>
        </div>{{-- end left panel --}}

        {{-- ── RIGHT PANEL: Job Detail inline (desktop only) ── --}}
        <div class="hidden lg:flex flex-1 min-w-0 min-h-0 flex-col bg-gray-50 jb-right-panel">
            @if($vj)
            <div class="flex items-center justify-between px-5 h-[48px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-3">
                <span class="text-white font-semibold text-base truncate">{{ $vj->job_title }}</span>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    @if($vCanShare)
                    <button type="button" wire:click="openShareJobModal({{ $vj->id }})" wire:loading.attr="disabled" wire:target="openShareJobModal({{ $vj->id }})" class="detail-top-btn share-btn" aria-label="Share">
                        <span wire:loading.remove wire:target="openShareJobModal({{ $vj->id }})"><i class="fas fa-share-nodes text-[13px] text-white"></i></span>
                        <span wire:loading wire:target="openShareJobModal({{ $vj->id }})"><i class="fas fa-spinner fa-spin text-[13px] text-white"></i></span>
                        <span class="tip">Share</span>
                    </button>
                    @endif
                    <button type="button" wire:click="closeViewModal" wire:loading.attr="disabled" wire:target="closeViewModal" class="detail-top-btn close-btn" aria-label="Close" data-jb-reset>
                        <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="closeViewModal"><path d="M2 2L12 12M12 2L2 12"/></svg>
                        <i class="fas fa-spinner fa-spin text-[13px] text-white" wire:loading wire:target="closeViewModal"></i>
                        <span class="tip">Close</span>
                    </button>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-5 py-5 flex flex-col gap-4 bg-white">

                {{-- Top: image + title --}}
                <div class="flex items-center gap-5">
                    <img src="{{ $detailImg }}" alt="{{ $vj->job_title }}"
                         class="w-[130px] h-[130px] flex-shrink-0 object-contain bg-white border border-gray-100 rounded-xl shadow-sm"
                         onerror="this.onerror=null;this.src='{{ asset('storage/job/default-photo-job.jpg') }}';">
                    <div class="flex flex-col gap-2 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($isPhilcst)
                                <span class="philcst-post-ribbon self-start"><i class="fas fa-school text-[12px]"></i> Official PHILCST Posting</span>
                            @endif
                            @if($isActive)
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>Inactive
                                </span>
                            @endif
                        </div>
                        <p class="text-2xl font-bold leading-snug" style="color:#333333;">🎉 WE'RE HIRING: {{ strtoupper($vj->job_title) }}</p>
                        @if($vIsExp)
                            <span class="inline-flex items-center text-sm font-bold px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 self-start">
                                <i class="fas fa-ban mr-1 text-[11px]"></i>Expired
                            </span>
                        @endif
                    </div>
                </div>

                <div class="border-t border-gray-100"></div>

                {{-- Meta info compact --}}
                <div class="flex flex-wrap gap-x-5 gap-y-2 items-center">
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-building"></i></span>
                        <p class="detail-side-value">{{ $vj->company_name }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-location-dot"></i></span>
                        <p class="detail-side-value">{{ $vj->location ?: '—' }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-calendar-days"></i></span>
                        <p class="detail-side-value {{ $dlValueClass }}">
                            {{ $vDl->format('M d, Y') }} <span class="font-normal text-xs">(@if($dlIsUrgent && !$vIsExp)<i class="fas fa-fire"></i> @endif{{ $dlLabel }})</span>
                        </p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-briefcase"></i></span>
                        <p class="detail-side-value">{{ $vj->employment_type }}</p>
                    </div>
                    @if($vj->experience_level)
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-chart-line"></i></span>
                        <p class="detail-side-value">{{ $vj->experience_level }}</p>
                    </div>
                    @endif
                    @if($vj->salary)
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-sack-dollar"></i></span>
                        <p class="detail-side-value">{{ $vj->salary }}</p>
                    </div>
                    @endif
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-users"></i></span>
                        <p class="detail-side-value">{{ $vj->target_college ? str_replace(',', ' · ', $vj->target_college) : 'All Alumni' }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-user-tie"></i></span>
                        <p class="detail-side-value">
                            {{ $vOrgName ?? 'Alumni Director' }}
                            @if($vOrgCollege)<span class="font-normal text-xs" style="color:#7a3f91;">({{ $vOrgCollege }})</span>@endif
                        </p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <p class="detail-side-value" style="color:#888;">Posted {{ $vCreatedPH->format('M d, Y') }} · {{ $vCreatedPH->diffForHumans() }}</p>
                    </div>
                </div>

                @if($vIsUrgent)
                <div class="bg-red-50 border border-red-200 border-l-4 border-l-red-600 rounded-lg px-4 py-3 text-sm text-gray-900 leading-relaxed">
                    @if($vDaysLeft === 0) Deadline is <strong class="text-red-600">today</strong>.
                    @elseif($vDaysLeft === 1) Only <strong class="text-red-600">1 day</strong> left.
                    @else Only <strong class="text-red-600">{{ $vDaysLeft }} days</strong> left. Closes {{ $vDl->format('F d, Y') }}.
                    @endif
                </div>
                @endif

                <div class="border-t border-gray-100"></div>

                {{-- Content sections --}}
                <div class="grid grid-cols-1 {{ ($hasQual || $hasInstr) ? 'xl:grid-cols-2' : '' }} gap-4">
                    <div class="border border-gray-200 rounded-xl px-4 py-3.5">
                        <p class="font-bold mb-2" style="color:#333333;font-size:clamp(16px,1.3vw,18px);">📄 Job Description:</p>
                        @if($vj->description)
                            <div class="pre-wrap leading-relaxed" style="color:#333333;font-size:clamp(15px,1.2vw,17px);">{{ trim($vj->description) }}</div>
                        @else
                            <p class="text-sm text-gray-500">No description provided.</p>
                        @endif
                    </div>
                    @if($hasQual || $hasInstr)
                    <div class="flex flex-col gap-3">
                        @if($hasQual)
                        <div class="border border-gray-200 rounded-xl px-4 py-3.5">
                            <p class="font-bold mb-2" style="color:#333333;font-size:clamp(16px,1.3vw,18px);">📌 Requirements &amp; Qualifications:</p>
                            <ul class="philcst-checklist">
                                @foreach($qualLines as $line)
                                    <li><span class="chk"><i class="fas fa-check"></i></span><span>{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                        @if($hasInstr)
                        <div class="bg-emerald-50/60 border border-emerald-100 rounded-xl px-4 py-3.5">
                            <p class="font-bold text-emerald-800 mb-2" style="font-size:clamp(16px,1.3vw,18px);">📝 How to Apply:</p>
                            <ul class="philcst-checklist">
                                @foreach($instrLines as $line)
                                    <li><span class="chk" style="background:#d1fae5;color:#047857;"><i class="fas fa-arrow-right"></i></span><span>{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            @else
            <div class="flex flex-col items-center justify-center h-full gap-4 text-center px-8">
                <div class="w-20 h-20 rounded-3xl flex items-center justify-center bg-gradient-to-br from-[#f5eef9] to-[#ede1f5] shadow-sm">
                    <i class="fas fa-briefcase text-3xl text-[#7a3f91]/50"></i>
                </div>
                <div>
                    <p class="font-bold text-lg text-gray-700">Select a job</p>
                    <p class="text-sm text-gray-400 mt-1">Click any job on the left to view details here</p>
                </div>
            </div>
            @endif
        </div>{{-- end right panel --}}

    </div>{{-- end content-block --}}

</div>



{{-- ══ MOBILE FULL-SCREEN JOB DETAIL (hidden on lg+, where the right panel is used) ══ --}}
@if($vj)
<div class="detail-page lg:hidden fixed inset-0 z-[9995] flex flex-col bg-gray-100 overflow-y-auto"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-5 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <span class="detail-header-title">Job Details</span>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0">
            @if($vCanShare)
            <button type="button" wire:click="openShareJobModal({{ $vj->id }})" wire:loading.attr="disabled" wire:target="openShareJobModal({{ $vj->id }})" class="detail-top-btn share-btn" aria-label="Share">
                <span wire:loading.remove wire:target="openShareJobModal({{ $vj->id }})"><i class="fas fa-share-nodes text-[13px] text-white"></i></span>
                <span wire:loading wire:target="openShareJobModal({{ $vj->id }})"><i class="fas fa-spinner fa-spin text-[13px] text-white"></i></span>
                <span class="tip">Share</span>
            </button>
            @endif
            <button type="button" wire:click="closeViewModal" wire:loading.attr="disabled" wire:target="closeViewModal" class="detail-top-btn close-btn" aria-label="Close" data-jb-reset>
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="closeViewModal"><path d="M2 2L12 12M12 2L2 12"/></svg>
                <i class="fas fa-spinner fa-spin text-[13px] text-white" wire:loading wire:target="closeViewModal"></i>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-hidden bg-gray-100 flex items-stretch justify-center p-3 sm:p-4">
        <div class="w-full max-w-[1400px] bg-white border border-[#E8E0F0] rounded-2xl overflow-hidden flex flex-col">
            <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-5 sm:px-8 py-5 flex flex-col gap-4">

                {{-- Top: image + title --}}
                <div class="flex items-center gap-5">
                    <img src="{{ $detailImg }}" alt="{{ $vj->job_title }}"
                         class="w-[96px] h-[96px] sm:w-[120px] sm:h-[120px] flex-shrink-0 object-contain bg-white border border-gray-100 rounded-xl shadow-sm"
                         onerror="this.onerror=null;this.src='{{ asset('storage/job/default-photo-job.jpg') }}';">
                    <div class="flex flex-col gap-2 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($isPhilcst)
                                <span class="philcst-post-ribbon self-start"><i class="fas fa-school text-[12px]"></i> Official PHILCST Posting</span>
                            @endif
                            @if($isActive)
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>Inactive
                                </span>
                            @endif
                        </div>
                        <p class="text-lg sm:text-xl font-bold leading-snug" style="color:#333333;">🎉 WE'RE HIRING: {{ strtoupper($vj->job_title) }}</p>
                        @if($vIsExp)
                            <span class="inline-flex items-center text-sm font-bold px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 self-start">
                                <i class="fas fa-ban mr-1 text-[11px]"></i>Expired
                            </span>
                        @endif
                    </div>
                </div>

                <div class="border-t border-gray-100"></div>

                {{-- Meta info compact --}}
                <div class="flex flex-wrap gap-x-6 gap-y-2 items-center">
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-building"></i></span>
                        <p class="detail-side-value">{{ $vj->company_name }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-location-dot"></i></span>
                        <p class="detail-side-value">{{ $vj->location ?: '—' }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-calendar-days"></i></span>
                        <p class="detail-side-value {{ $dlValueClass }}">
                            {{ $vDl->format('M d, Y') }} <span class="font-normal text-xs">(@if($dlIsUrgent && !$vIsExp)<i class="fas fa-fire"></i> @endif{{ $dlLabel }})</span>
                        </p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-briefcase"></i></span>
                        <p class="detail-side-value">{{ $vj->employment_type }}</p>
                    </div>
                    @if($vj->experience_level)
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-chart-line"></i></span>
                        <p class="detail-side-value">{{ $vj->experience_level }}</p>
                    </div>
                    @endif
                    @if($vj->salary)
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-sack-dollar"></i></span>
                        <p class="detail-side-value">{{ $vj->salary }}</p>
                    </div>
                    @endif
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-users"></i></span>
                        <p class="detail-side-value">{{ $vj->target_college ? str_replace(',', ' · ', $vj->target_college) : 'All Alumni' }}</p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-user-tie"></i></span>
                        <p class="detail-side-value">
                            {{ $vOrgName ?? 'Alumni Director' }}
                            @if($vOrgCollege)<span class="font-normal text-xs" style="color:#7a3f91;">({{ $vOrgCollege }})</span>@endif
                        </p>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <p class="detail-side-value" style="color:#888;">Posted {{ $vCreatedPH->format('M d, Y') }} · {{ $vCreatedPH->diffForHumans() }}</p>
                    </div>
                </div>

                @if($vIsUrgent)
                <div class="bg-red-50 border border-red-200 border-l-4 border-l-red-600 rounded-lg px-4 py-3 text-sm text-gray-900 leading-relaxed">
                    @if($vDaysLeft === 0) Deadline is <strong class="text-red-600">today</strong>.
                    @elseif($vDaysLeft === 1) Only <strong class="text-red-600">1 day</strong> left.
                    @else Only <strong class="text-red-600">{{ $vDaysLeft }} days</strong> left. Closes {{ $vDl->format('F d, Y') }}.
                    @endif
                </div>
                @endif

                <div class="border-t border-gray-100"></div>

                {{-- Content sections --}}
                <div class="grid grid-cols-1 {{ ($hasQual || $hasInstr) ? 'lg:grid-cols-2' : '' }} gap-4">
                    <div class="border border-gray-200 rounded-xl px-4 py-3.5">
                        <p class="font-bold mb-2" style="color:#333333;font-size:clamp(16px,1.3vw,18px);">📄 Job Description:</p>
                        @if($vj->description)
                            <div class="pre-wrap leading-relaxed" style="color:#333333;font-size:clamp(15px,1.2vw,17px);">{{ trim($vj->description) }}</div>
                        @else
                            <p class="text-sm text-gray-500">No description provided.</p>
                        @endif
                    </div>
                    @if($hasQual || $hasInstr)
                    <div class="flex flex-col gap-3">
                        @if($hasQual)
                        <div class="border border-gray-200 rounded-xl px-4 py-3.5">
                            <p class="font-bold mb-2" style="color:#333333;font-size:clamp(16px,1.3vw,18px);">📌 Requirements &amp; Qualifications:</p>
                            <ul class="philcst-checklist">
                                @foreach($qualLines as $line)
                                    <li><span class="chk"><i class="fas fa-check"></i></span><span>{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                        @if($hasInstr)
                        <div class="bg-emerald-50/60 border border-emerald-100 rounded-xl px-4 py-3.5">
                            <p class="font-bold text-emerald-800 mb-2" style="font-size:clamp(16px,1.3vw,18px);">📝 How to Apply:</p>
                            <ul class="philcst-checklist">
                                @foreach($instrLines as $line)
                                    <li><span class="chk" style="background:#d1fae5;color:#047857;"><i class="fas fa-arrow-right"></i></span><span>{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>

            </div>
        </div>
    </div>
</div>
@endif




{{-- ══ SHARE JOB — MODAL ══ --}}
@if($showShareJobModal)
@php
    $sjTargetParts = $shareJobTarget
        ? array_values(array_filter(array_map('trim', explode(',', $shareJobTarget))))
        : [];

    if (empty($sjTargetParts)) {
        $sjTargets = 'All Alumni';
    } elseif (count($sjTargetParts) > 2) {
        $sjTargets = implode(', ', array_slice($sjTargetParts, 0, 2))
            . ' +' . (count($sjTargetParts) - 2) . ' more';
    } else {
        $sjTargets = implode(', ', $sjTargetParts);
    }

    $sjLines   = [];
    $sjLines[] = strtoupper($shareJobTitle);
    $sjLines[] = '';
    $sjLines[] = "Company: {$shareJobCompany}" . ($shareJobLocation ? " · {$shareJobLocation}" : '');
    $sjLines[] = "{$shareJobEmpType}" . ($shareJobExpLevel ? " · {$shareJobExpLevel}" : '');
    if ($shareJobSalary)  $sjLines[] = "Salary: {$shareJobSalary}";
    if ($shareJobTarget)  $sjLines[] = "Open for: {$sjTargets}";
    $sjLines[] = "Apply by: {$shareJobDeadline}";

    if (trim($shareJobDescription) !== '') {
        $sjLines[] = '';
        $sjLines[] = 'Job Description:';
        $sjLines[] = trim($shareJobDescription);
    }

    $sjLines[] = '';
    $sjLines[] = 'For more information, visit our PHILCST Alumni Connect and login.';
    $sjLines[] = '#YourFutureStarsHere';
    $sjPostText = implode("\n", $sjLines);
@endphp

<style>
@keyframes admPanelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.adm-share-sheet { animation: admPanelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.adm-share-modal-wrapper {
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Share modal: full screen on mobile, centered card on desktop ── */
@media (max-width: 767px) {
    .adm-share-backdrop {
        padding: 0 !important;
        align-items: stretch !important;
        justify-content: stretch !important;
    }
    .adm-share-backdrop .adm-share-sheet {
        border-radius: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
    }
}

.adm-share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .12s ease-out, transform .1s ease-out; border: none;
    will-change: transform;
}
.adm-share-option-btn:hover  { filter: brightness(0.94); }
.adm-share-option-btn:active { transform: scale(.97); transition-duration: .05s; }
.adm-share-option-btn:disabled { opacity: .7; cursor: wait; }
.adm-share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.adm-share-option-btn .label-text { flex: 1; text-align: left; }

/* ── Job photo preview (Post Preview column) ── */
.adm-share-photo-preview {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: 0.75rem;
    overflow: hidden;
    border: 1px solid #E5E7EB;
    background: #f2f2f2;
    flex-shrink: 0;
}
.adm-share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain; display: block;
}
.adm-share-photo-preview .dl-badge {
    position: absolute; bottom: 8px; right: 8px;
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(0,0,0,.72); color: #fff;
    font-size: 11px; font-weight: 600;
    padding: 4px 10px; border-radius: 999px;
}
@media (max-width: 480px) {
    .adm-share-photo-preview { aspect-ratio: 4 / 3; }
}

/* ── Pre-share "download the photo?" confirm dialog ── */
.adm-dl-confirm-icon {
    width: 2.25rem; height: 2.25rem; border-radius: 0.65rem; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: #F5F0FA; color: #7a3f91; font-size: 0.95rem;
}
.adm-dl-confirm-btn {
    flex: 1; padding: 0.6rem 0.9rem; border-radius: 0.65rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .12s ease-out, transform .1s ease-out; border: none;
}
.adm-dl-confirm-btn:active { transform: scale(.97); transition-duration: .05s; }
.adm-dl-confirm-btn.primary   { background: #7a3f91; color: #fff; }
.adm-dl-confirm-btn.primary:hover  { filter: brightness(0.94); }
.adm-dl-confirm-btn.primary:disabled { opacity: .7; cursor: wait; }
.adm-dl-confirm-btn.secondary { background: #F3F4F6; color: #374151; }
.adm-dl-confirm-btn.secondary:hover { background: #E5E7EB; }
</style>

<div id="admjob-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 adm-share-backdrop"
     x-data="{
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         sharingTo: null,
         shareText: {{ json_encode($sjPostText) }},
         jobTitle:  {{ json_encode($shareJobTitle) }},
         imageUrl:  {{ json_encode($shareJobPhotoUrl) }},

         downloading: false,
         downloaded:  false,
         showDlConfirm: false,
         pendingTarget: null,

         async copyText(text) {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(text);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = text; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch (e) { return false; }
         },

         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'job-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
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
             this.sharingTo = 'native';
             try {
                 const shareData = { title: this.jobTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user — nothing to do */ }
             this.sharingTo = null;
         },

         // Facebook/Messenger don't accept a file via window.open, so —
         // same pattern as the organizer side — ask the admin to download
         // the photo first (or skip if they already have it) before we
         // open the share target and copy the caption.
         askShare(target) {
             if (!this.imageUrl) { this.pendingTarget = target; this.proceedToTarget(); return; }
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
             this.sharingTo = 'facebook';
             const copyOk = await this.copyText(this.shareText);
             const w=680,h=560,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             const win = window.open(url, 'philcst_admjob_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into the Facebook post box that just opened.'
                     : 'Could not copy the caption automatically — please copy it manually from the preview, then paste it into Facebook.'
             });
             this.sharingTo = null;
         },

         async openMessenger() {
             this.sharingTo = 'messenger';
             const copyOk = await this.copyText(this.shareText);
             const win = window.open('https://www.messenger.com/new', 'philcst_admjob_messenger_share', 'noopener,noreferrer');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into Messenger.'
                     : 'Could not copy the caption automatically — please copy it manually from the preview, then paste it into Messenger.'
             });
             this.sharingTo = null;
         }
     }"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="if(showDlConfirm){cancelDlConfirm()}else{$wire.closeShareJobModal()}">

    <div class="adm-share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 adm-share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2 text-[#111111]">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareJobModal" type="button"
                    wire:loading.attr="disabled" wire:target="closeShareJobModal"
                    class="adm-share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                     wire:loading.remove wire:target="closeShareJobModal">
                    <path d="M2 2L12 12M12 2L2 12" stroke="#4b5563" stroke-width="2.25" stroke-linecap="round"/>
                </svg>
                <i class="fas fa-spinner fa-spin" style="font-size:12px;color:#4b5563;" wire:loading wire:target="closeShareJobModal"></i>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto adm-scroll">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0 text-[#111111]">Post Preview</p>

                @if($shareJobPhotoUrl)
                <div class="adm-share-photo-preview">
                    <img src="{{ $shareJobPhotoUrl }}" alt="{{ $shareJobTitle }}"
                         onerror="this.style.display='none'">
                    <span class="dl-badge" x-show="downloading || downloaded" x-cloak>
                        <i class="fas" :class="downloading ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="downloading ? 'Downloading…' : 'Downloaded'"></span>
                    </span>
                </div>
                @endif

                <div class="rounded-xl border border-gray-200 flex-shrink-0 overflow-y-auto adm-scroll" style="max-height: 180px;">
                    <div class="px-4 py-3">
                        <p class="whitespace-pre-wrap leading-relaxed text-[#111111]" style="font-size:clamp(11px,1vw,13px);">{{ rtrim(preg_replace('/#YourFutureStarsHere\s*$/', '', $sjPostText)) }}</p>
                        <p class="whitespace-pre-wrap leading-relaxed font-semibold mt-1" style="font-size:clamp(11px,1vw,13px);color:#1877F2;">#YourFutureStarsHere</p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto adm-scroll">
                <p class="text-[10px] font-bold uppercase tracking-widest text-[#111111]">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" :disabled="sharingTo==='native'" class="adm-share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-spinner fa-spin text-[#7a3f91] text-sm" x-show="sharingTo==='native'" x-cloak></i>
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm" x-show="sharingTo!=='native'"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" :disabled="sharingTo==='facebook'" class="adm-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#1877F2] text-sm" x-show="sharingTo==='facebook'" x-cloak></i>
                        <svg x-show="sharingTo!=='facebook'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='facebook' ? 'Opening…' : 'Share on Facebook'"></span>
                </button>

                <button type="button" @click="askShare('messenger')" :disabled="sharingTo==='messenger'" class="adm-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#0084FF] text-sm" x-show="sharingTo==='messenger'" x-cloak></i>
                        <svg x-show="sharingTo!=='messenger'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='messenger' ? 'Opening…' : 'Send via Messenger'"></span>
                </button>

                <p class="text-xs text-center text-[#666666]">Sharing this job is available until its deadline passes.</p>
            </div>
        </div>

        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex-shrink-0">
            <div class="flex items-start gap-2.5">
                <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5 text-[#666666]"></i>
                <p class="text-xs leading-relaxed text-[#666666]">
                    The caption is copied to your clipboard automatically — just paste it (Ctrl+V)
                    into the Facebook or Messenger window that opens.
                </p>
            </div>
        </div>
    </div>

    {{-- ── PRE-SHARE "Download the photo?" CONFIRM MODAL ──
         Facebook/Messenger's window.open() can't carry an attached file,
         so — same UX as the organizer side — ask the admin to download
         the job photo first (or skip if they already have it saved)
         before we open the share target and copy the caption. ── --}}
    <div x-show="showDlConfirm" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[10010] flex items-center justify-center p-4 bg-black/55"
         @click.self="cancelDlConfirm()">
        <div class="adm-share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="adm-dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold text-[#111111]">Download the job photo?</p>
                    <p class="text-xs mt-1 leading-relaxed text-[#666666]">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($shareJobPhotoUrl)
            <div class="adm-share-photo-preview">
                <img src="{{ $shareJobPhotoUrl }}" alt="{{ $shareJobTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="adm-dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="adm-dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


</div>

<script>
(function () {
    function findScrollableAncestors(el) {
        var found = [];
        var node = el ? el.parentElement : null;
        while (node && node !== document.body) {
            var cs = window.getComputedStyle(node);
            if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
                found.push(node);
            }
            node = node.parentElement;
        }
        return found;
    }

    var lockedNodes = [];
    var prevStyles = [];

    function lockScroll() {
        var scrollEl = document.querySelector('[wire\\:id]') || document.body;
        var ancestors = findScrollableAncestors(scrollEl);

        [document.documentElement, document.body].concat(ancestors).forEach(function (node) {
            if (lockedNodes.indexOf(node) !== -1) return;
            prevStyles.push([node, node.style.overflow, node.style.overflowY]);
            node.style.overflow = 'hidden';
            node.style.overflowY = 'hidden';
            lockedNodes.push(node);
        });
    }

    function restore() {
        prevStyles.forEach(function (entry) {
            entry[0].style.overflow = entry[1];
            entry[0].style.overflowY = entry[2];
        });
        lockedNodes = [];
        prevStyles = [];
        document.removeEventListener('livewire:navigating', restore);
        window.removeEventListener('beforeunload', restore);
    }

    lockScroll();
    setTimeout(lockScroll, 150);
    setTimeout(lockScroll, 500);

    document.addEventListener('livewire:navigating', restore);
    window.addEventListener('beforeunload', restore);
})();
</script>

<script>
(function () {
    // ─────────────────────────────────────────────────────────────────
    //  ROW HOVER TOOLTIP
    // ─────────────────────────────────────────────────────────────────
    var tip       = document.getElementById('admjob-hover-tip');
    var actionTip = document.getElementById('admjob-action-tip');

    function bindRows() {
        document.querySelectorAll('[data-adm-row]').forEach(function (row) {
            if (row._admjobTipBound) return;
            row._admjobTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var actionWrap = e.target.closest('[data-adm-action]');
                if (actionWrap) {
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

        document.querySelectorAll('[data-adm-action]').forEach(function (sw) {
            if (sw._admjobActionBound) return;
            sw._admjobActionBound = true;
            sw.addEventListener('mouseenter', function () {
                if (tip) tip.style.opacity = '0';
            });
        });
    }

    function bindActionTips() {
        if (!actionTip) return;
        document.querySelectorAll('[data-tip]').forEach(function (btn) {
            if (btn._admjobActionTipBound) return;
            btn._admjobActionTipBound = true;

            btn.addEventListener('mouseenter', function () {
                var rect = btn.getBoundingClientRect();
                actionTip.textContent  = btn.getAttribute('data-tip');
                actionTip.style.left   = (rect.left + rect.width / 2) + 'px';
                actionTip.style.top    = (rect.top - 8) + 'px';
                actionTip.style.opacity = '1';
            });

            btn.addEventListener('mouseleave', function () {
                actionTip.style.opacity = '0';
            });

            btn.addEventListener('click', function () {
                actionTip.style.opacity = '0';
            });
        });
    }

    bindRows();
    bindActionTips();
    document.addEventListener('livewire:updated', function () {
        bindRows();
        bindActionTips();
    });
    document.addEventListener('livewire:morph', function () {
        bindRows();
        bindActionTips();
    });
    document.addEventListener('livewire:morphed', function () {
        bindRows();
        bindActionTips();
    });

    // MutationObserver fallback — guarantees rebinding even if the Livewire
    // lifecycle event names above ever change between versions. Watches the
    // table body for row swaps caused by filtering/searching/pagination.
    var admjobTableRoot = document.querySelector('.adm-table-card');
    if (admjobTableRoot && window.MutationObserver) {
        var admjobObserver = new MutationObserver(function () {
            bindRows();
            bindActionTips();
        });
        admjobObserver.observe(admjobTableRoot, { childList: true, subtree: true });
    }

    // ─────────────────────────────────────────────────────────────────
    //  JOB NOTIFICATION BRIDGE
    //
    //  Listens for the Livewire-dispatched 'admin-job-posted-notify'
    //  event and forwards it to the admin notification store via the
    //  existing 'admin-job-updated' window event.
    //
    //  Dedup: uses sessionStorage to track which job IDs we've already
    //  fired this session — prevents repeated toasts on filter changes
    //  or Livewire re-renders, while still notifying on fresh page loads
    //  for jobs in the last 24 hours.
    // ─────────────────────────────────────────────────────────────────
    var NOTIF_STORE_KEY = 'admjob_notified_ids';

    function _getNotifiedIds() {
        try {
            return JSON.parse(sessionStorage.getItem(NOTIF_STORE_KEY) || '[]');
        } catch (e) { return []; }
    }

    function _addNotifiedId(id) {
        try {
            var ids = _getNotifiedIds();
            if (ids.indexOf(id) === -1) {
                ids.push(id);
                // Keep last 200 to avoid unbounded growth
                if (ids.length > 200) ids = ids.slice(-200);
                sessionStorage.setItem(NOTIF_STORE_KEY, JSON.stringify(ids));
            }
        } catch (e) {}
    }

    function _isAlreadyNotified(id) {
        return _getNotifiedIds().indexOf(id) !== -1;
    }

    // Listen for Livewire dispatched event
    document.addEventListener('admin-job-posted-notify', function (e) {
        var d = e.detail;
        if (!d) return;
        // Livewire wraps detail in array for browser events
        var payload = Array.isArray(d) ? d[0] : d;
        if (!payload || !payload.id) return;

        var jobId = String(payload.id);

        // Skip if we already fired a notif for this job this session
        if (_isAlreadyNotified(jobId)) return;

        _addNotifiedId(jobId);

        // Build the message: "Job Title — Posted by: Organizer Name"
        var posterLabel = payload.poster
            ? 'Posted by: ' + payload.poster
            : 'New job listing available';

        var message = (payload.title || 'A new job posting')
            + (payload.company ? ' at ' + payload.company : '')
            + ' — ' + posterLabel;

        // ── Fire straight into the SAME save pathway the coordinator/
        //    organizer flow already uses correctly (the __admin-job-posted-rich
        //    handler below, which POSTs with the explicit link_route:
        //    'job.posts'). Previously this also fired a generic
        //    'admin-job-updated' event that a separate handler elsewhere
        //    picked up and saved its own competing notification row for
        //    the same dedup_key — whichever one landed last in the DB
        //    won, which is why a director-posted job (poster label
        //    falls back to 'Alumni Director' since there's no organizer
        //    relation) would intermittently end up with the wrong
        //    link_route and land on the dashboard instead of View
        //    Details. Removing the duplicate dispatch means there's only
        //    ever one save path — the one that's already proven correct
        //    for coordinator/organizer job posts. ──
        window.dispatchEvent(new CustomEvent('__admin-job-posted-rich', {
            detail: {
                id:      payload.id,
                title:   payload.title   || 'New Job Posting',
                company: payload.company || '',
                poster:  payload.poster  || 'Alumni Director',
                message: message,
            }
        }));
    });

    // Handle the rich save directly here — bypass the generic handler
    // in admin.blade.php so we get "Job Title at Company — Posted by: Name"
    if (!window.__admJobRichListenerBound) {
        window.__admJobRichListenerBound = true;

        window.addEventListener('__admin-job-posted-rich', async function (e) {
            var d = e.detail;
            if (!d || !d.id) return;
            try {
                var csrf = document.querySelector('meta[name="csrf-token"]');
                if (!csrf) return;
                await window.fetch('/admin/notifications', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     csrf.content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        icon:       'briefcase',
                        title:      'New Job Posting',
                        message:    d.message,
                        link_route: 'job.posts',
                        link_label: 'View Jobs',
                        job_id:     d.id,
                        dedup_key:  'job-posted::' + d.id,
                    }),
                });
                // Refresh the notif store
                await new Promise(function (r) { setTimeout(r, 300); });
                var s = window.__safeAdminNotifsStore ? window.__safeAdminNotifsStore() : null;
                if (s) await s._fetch();
                setTimeout(async function () {
                    var s2 = window.__safeAdminNotifsStore ? window.__safeAdminNotifsStore() : null;
                    if (s2) await s2._fetch();
                }, 700);
            } catch (err) { /* silent */ }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  BACKGROUND POLLING — check for new jobs every 60s while page
    //  is open (catches jobs posted after initial mount)
    // ─────────────────────────────────────────────────────────────────
    if (!window.__admJobPollTimer) {
        window.__admJobPollTimer = setInterval(async function () {
            try {
                var res = await window.fetch('/admin/notifications/new-jobs-check', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) return;
                var jobs = await res.json();
                if (!Array.isArray(jobs)) return;
                jobs.forEach(function (job) {
                    if (!job.id) return;
                    var jobId = String(job.id);
                    if (_isAlreadyNotified(jobId)) return;
                    _addNotifiedId(jobId);

                    var posterLabel = job.poster ? 'Posted by: ' + job.poster : 'New job listing available';
                    var message = (job.title || 'A new job posting')
                        + (job.company ? ' at ' + job.company : '')
                        + ' — ' + posterLabel;

                    window.dispatchEvent(new CustomEvent('__admin-job-posted-rich', {
                        detail: {
                            id:      job.id,
                            title:   job.title   || 'New Job Posting',
                            company: job.company || '',
                            poster:  job.poster  || 'Alumni Director',
                            message: message,
                        }
                    }));
                });
            } catch (e) { /* silent */ }
        }, 60000); // every 60 seconds
    }

    // Clean up poll timer on Livewire navigation away
    document.addEventListener('livewire:navigating', function () {
        if (window.__admJobPollTimer) {
            clearInterval(window.__admJobPollTimer);
            window.__admJobPollTimer = null;
        }
    });
})();

<script>
(function () {
    'use strict';

    // ─── HELPERS ─────────────────────────────────────────────────────────
    function isTouchOrSmall() {
        return window.matchMedia('(max-width: 767px)').matches ||
               window.matchMedia('(pointer: coarse)').matches;
    }

    // ─── CURSOR LABEL (desktop "View Details" follower) ──────────────────
    var activeCard = null;

    function initCursorLabel() {
        const label = document.getElementById('jb-cursor-label');
        if (!label || isTouchOrSmall()) return;

        function show() {
            if (isTouchOrSmall()) return;
            // Hide while any card/filter is locked
            if (document.querySelector('[data-jb-card].is-blocked, [data-jb-card].is-loading')) return;
            label.style.opacity    = '1';
            label.style.visibility = 'visible';
        }
        function hide() {
            label.style.opacity    = '0';
            label.style.visibility = 'hidden';
        }
        function onMouseMove(e) {
            label.style.left = (e.clientX + 16) + 'px';
            label.style.top  = (e.clientY + 14) + 'px';
        }
        function onCardEnter(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = e.currentTarget;
            document.addEventListener('mousemove', onMouseMove);
            show();
        }
        function onCardLeave(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = null;
            hide();
            document.removeEventListener('mousemove', onMouseMove);
        }
        function onShareEnter() { hide(); }
        function onShareLeave() { if (activeCard) show(); }

        document.querySelectorAll('[data-jb-card]').forEach(card => {
            if (card._jbLabelBound) return;
            card._jbLabelBound = true;
            card.addEventListener('mouseenter', onCardEnter);
            card.addEventListener('mouseleave', onCardLeave);
            const shareBtn = card.querySelector('[data-jb-share]');
            if (shareBtn) {
                shareBtn.addEventListener('mouseenter', onShareEnter);
                shareBtn.addEventListener('mouseleave', onShareLeave);
            }
        });

        // Hide label during any Livewire update
        document.addEventListener('livewire:update', () => { hide(); activeCard = null; });
    }

    // ─── LOCK / UNLOCK (dashboard .is-blocked pattern) ───────────────────
    // lockJbAll(clickedCard):
    //   • clickedCard → .is-loading (spinner visible, content blurred)
    //   • every other card → .is-blocked (dimmed, no pointer, no hover)
    //   • all filter inputs, selects, pagination buttons → .jb-el-blocked
    // clearAll(): removes all lock classes everywhere.

    function lockJbAll(clickedCard) {
        // Cards
        document.querySelectorAll('[data-jb-card]').forEach(el => {
            if (el === clickedCard) {
                el.classList.remove('is-blocked');
                el.classList.add('is-loading');
            } else {
                el.classList.remove('is-loading');
                el.classList.add('is-blocked');
            }
        });
        // Filter inputs, selects, pagination buttons
        document.querySelectorAll(
            '#jb-content-block .filter-input, ' +
            '#jb-content-block select, ' +
            '#jb-content-block button[wire\\:click], ' +
            '#jb-content-block button[wire\\:click\\.prevent]'
        ).forEach(el => el.classList.add('jb-el-blocked'));
    }

    function lockJbFilters() {
        // Called when a filter/search/sort/pagination fires (no specific card)
        document.querySelectorAll('[data-jb-card]').forEach(el => {
            el.classList.remove('is-loading');
            el.classList.add('is-blocked');
        });
        document.querySelectorAll(
            '#jb-content-block .filter-input, ' +
            '#jb-content-block select, ' +
            '#jb-content-block button[wire\\:click], ' +
            '#jb-content-block button[wire\\:click\\.prevent]'
        ).forEach(el => el.classList.add('jb-el-blocked'));
    }

    function clearAll() {
        document.querySelectorAll('[data-jb-card]').forEach(el => {
            el.classList.remove('is-loading', 'is-blocked');
        });
        document.querySelectorAll('.jb-el-blocked').forEach(el => {
            el.classList.remove('jb-el-blocked');
        });
    }

    // ─── CARD CLICK BINDING ───────────────────────────────────────────────
    function bindCardClicks() {
        document.querySelectorAll('[data-jb-card]').forEach(card => {
            if (card._jbClickBound) return;
            card._jbClickBound = true;
            card.addEventListener('click', function (e) {
                // Ignore if clicking the share button inside the card
                if (e.target.closest('[data-jb-share]')) return;
                // If already blocked, swallow the click
                if (card.classList.contains('is-blocked')) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return;
                }
                // If another card is already loading, swallow
                if (document.querySelector('[data-jb-card].is-loading')) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return;
                }
                lockJbAll(card);
            });
        });
    }

    // ─── FILTER / SELECT / PAGINATION CHANGE & CLICK ─────────────────────
    // Selects fire 'change'; pagination/reset fire 'click' (capture).
    //
    // FIX (freeze bug): listeners are stored in module-level refs so
    // removeEventListener can replace them cleanly. The old code used
    // block._jbFilterBound as a one-way guard but then reset it to false
    // in queueRebind() and called bindFilterElements() again — meaning a
    // SECOND anonymous listener was added after every Livewire morph.
    //
    // With two capture listeners on the block, clicking Reset would:
    //   • Listener 1: nothing locked yet → lockJbFilters() → reset button
    //                 gets .jb-el-blocked
    //   • Listener 2: sees .jb-el-blocked on btn → stopImmediatePropagation()
    //                 → Livewire never receives the click → no commit fires
    //                 → clearAll() never runs → permanent freeze.
    //
    // Fix: store the two function refs (_jbChangeFn / _jbClickCaptureFn)
    // and removeEventListener the old ones before re-adding, so only ONE
    // listener ever exists at a time regardless of how many morphs happen.
    // bindFilterElements() is also removed from queueRebind() — event
    // delegation on #jb-content-block survives morph (the element itself
    // is morphed in-place, not replaced), so rebinding on every morph was
    // never necessary for filter elements, only for per-card listeners.
    var _jbChangeFn = null;
    var _jbClickCaptureFn = null;

    function bindFilterElements() {
        const block = document.getElementById('jb-content-block');
        if (!block) return;

        // Always remove old listeners first — safe even on first call
        // when refs are null (removeEventListener is a no-op for null).
        if (_jbChangeFn)       block.removeEventListener('change', _jbChangeFn);
        if (_jbClickCaptureFn) block.removeEventListener('click',  _jbClickCaptureFn, true);

        // Select change (filterType, filterLevel)
        _jbChangeFn = function (e) {
            if (!e.target.matches('select')) return;
            if (document.querySelector('[data-jb-card].is-blocked, .jb-el-blocked')) return;
            lockJbFilters();
        };

        // Buttons with wire:click (pagination, resetFilters) — capture phase
        // so this runs BEFORE Livewire's own handler and can block it.
        //
        // FIX (reset freeze): the reset button ([data-jb-reset]) must never
        // be blocked by lockJbFilters() — if it gets .jb-el-blocked before
        // Livewire receives the click, the commit never fires and clearAll()
        // never runs, freezing the UI permanently. Skip it entirely here;
        // Livewire's own wire:loading handling on the button is enough.
        _jbClickCaptureFn = function (e) {
            const btn = e.target.closest('button[wire\\:click], button[wire\\:click\\.prevent]');
            if (!btn) return;
            // Reset button — never lock it; let Livewire handle it freely.
            if (btn.hasAttribute('data-jb-reset')) return;
            // Already locked — block the click so Livewire can't double-fire.
            if (btn.classList.contains('jb-el-blocked')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            if (document.querySelector('[data-jb-card].is-blocked, .jb-el-blocked')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            lockJbFilters();
        };

        block.addEventListener('change', _jbChangeFn);
        block.addEventListener('click',  _jbClickCaptureFn, true);
    }

    // ─── LIVEWIRE COMMIT HOOK — clear on succeed/fail ────────────────────
    function initLivewireHook() {
        if (!window.Livewire) return;
        try {
            window.Livewire.hook('commit', ({ succeed, fail }) => {
                succeed(() => clearAll());
                fail(() => clearAll());
            });
        } catch (e) {}
    }

    // ─── REBIND (coalesced per rAF tick, avoids double-paint) ────────────
    // Only rebinds per-card listeners and the cursor label — both need
    // refreshing after morph because new card DOM nodes appear. Filter
    // element listeners are intentionally NOT rebind here: they use event
    // delegation on #jb-content-block which survives morph in-place, so
    // rebinding them on every morph was what caused the listener
    // accumulation that froze the reset button.
    var jbRebindQueued = false;
    function queueRebind() {
        if (jbRebindQueued) return;
        jbRebindQueued = true;
        requestAnimationFrame(() => {
            jbRebindQueued = false;
            // Reset per-card binding flags so new/morphed cards get listeners.
            document.querySelectorAll('[data-jb-card]').forEach(c => {
                c._jbClickBound = false;
                c._jbLabelBound = false;
            });
            bindCardClicks();
            initCursorLabel();
            // bindFilterElements intentionally omitted — see comment above.
        });
    }

    // ─── INIT ─────────────────────────────────────────────────────────────
    function init() {
        bindCardClicks();
        initCursorLabel();
        bindFilterElements(); // bound once; event delegation survives morph
        initLivewireHook();

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', () => queueRebind());
        }
        document.addEventListener('livewire:navigated', () => {
            // Full SPA navigation: new DOM, so rebind everything including
            // filter elements (bindFilterElements removes old refs first).
            clearAll();
            bindFilterElements();
            queueRebind();
        });
        // Safety net: bfcache restore
        window.addEventListener('pageshow', clearAll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<script>
(function () {
    // Sizes the Job Postings page to exactly the visible screen so the whole
    // page never scrolls — only the job list (left panel) and the detail
    // panel (right) scroll internally.
    var BOTTOM_GAP = 16;  // breathing room under the page (px)
    var MIN_H      = 420; // never smaller than this (px)

    // Nearest ancestor that can scroll (or the page itself).
    function scrollParent(el) {
        var node = el.parentElement;
        while (node && node !== document.body && node !== document.documentElement) {
            var oy = window.getComputedStyle(node).overflowY;
            if (oy === 'auto' || oy === 'scroll') return node;
            node = node.parentElement;
        }
        return document.scrollingElement || document.documentElement;
    }

    function fit() {
        var el = document.getElementById('jp-root');
        if (!el) return;
        var root = document.documentElement;
        if (window.innerWidth < 1024) {
            root.style.removeProperty('--jp-h');
            return;
        }
        // Top of the page at scroll position 0 (add back every ancestor's scrollTop)
        var top = el.getBoundingClientRect().top + (window.scrollY || 0);
        var node = el.parentElement;
        while (node && node !== document.body && node !== document.documentElement) {
            top += node.scrollTop || 0;
            node = node.parentElement;
        }
        var h = Math.max(MIN_H, Math.floor(window.innerHeight - top - BOTTOM_GAP));
        root.style.setProperty('--jp-h', h + 'px');

        // Safety net: shrink by whatever still overflows so no scrollbar appears.
        var sp   = scrollParent(el);
        var over = sp.scrollHeight - sp.clientHeight;
        if (over > 0) {
            h = Math.max(MIN_H, h - over - 2);
            root.style.setProperty('--jp-h', h + 'px');
        }
    }

    function run() {
        fit();
        setTimeout(fit, 80);
        setTimeout(fit, 350);
    }

    window.addEventListener('resize', fit);
    document.addEventListener('livewire:navigated', run);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>