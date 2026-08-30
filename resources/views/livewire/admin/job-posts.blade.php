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
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

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

<div class="flex flex-col h-full min-h-0" style="overflow: hidden;">

<style>
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
.adm-table-card { display: flex; flex-direction: column; flex: 0 0 58vh !important; min-height: 58vh !important; height: 58vh !important; max-height: 58vh !important; }

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
</style>

{{-- Hover tooltip (row) --}}
<div id="admjob-hover-tip"
     class="fixed bg-[#111111] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#111111]"></span>
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
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-briefcase text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Job Postings</h1>
                <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
                    Monitor and review job listings across
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs">
                        <i class="fas fa-building-columns text-[9px]"></i>
                        all colleges
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    @php $s = $this->stats; @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 flex-shrink-0">

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

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-orange-50">
                <i class="fas fa-fire text-sm text-orange-500"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Expiring Soon</p>
                <p class="text-xl font-bold leading-tight text-orange-500 {{ $s['expiring'] > 0 ? 'urgent-pulse' : '' }}">
                    {{ $s['expiring'] }}
                </p>
            </div>
            @if($s['expiring'] > 0)
                <span class="ml-auto flex-shrink-0 w-2.5 h-2.5 rounded-full bg-orange-400 animate-pulse"></span>
            @endif
        </div>
    </div>

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="adm-table-card flex-1 min-h-0 rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="search,filterStatus,filterType,filterCollege">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-bold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#111111] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] placeholder-[#aaaaaa] font-normal
                              hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] font-normal
                           hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] font-normal
                           hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] font-normal
                           hover:border-[#bbbbbb] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>

            {{-- Active pill: Status --}}
            @if($filterStatus)
            @php
                $sPillMap = [
                    'ACTIVE'    => ['label' => 'Active',        'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'INACTIVE'  => ['label' => 'Inactive',      'cls' => 'bg-amber-50 border-amber-300 text-amber-800'],
                    'EXPIRING'  => ['label' => 'Expiring Soon', 'cls' => 'bg-orange-50 border-orange-300 text-orange-800'],
                ];
                $sPill = $sPillMap[$filterStatus] ?? null;
            @endphp
            @if($sPill)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border {{ $sPill['cls'] }}">
                @if($filterStatus === 'EXPIRING')<i class="fas fa-fire text-[9px]"></i>@else<i class="fas fa-filter text-[9px]"></i>@endif
                {{ $sPill['label'] }}
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
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold text-[#111111]
                           bg-white border border-[#E0E0E0] hover:bg-[#f5f5f5] transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm text-[#111111]"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile selects --}}
            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E0E0E0] rounded-lg bg-white text-[#111111] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="relative flex-1 min-h-0 flex flex-col overflow-hidden">

            {{-- Centered loading spinner — mirrors Event Monitoring's table overlay --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="search,filterStatus,filterType,filterCollege,resetFilters,previousPage,nextPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto adm-scroll bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-50" wire:target="search,filterStatus,filterType,filterCollege,resetFilters,previousPage,nextPage">
                {{-- ── DESKTOP / TABLET: table view ── --}}
                <table class="w-full bg-white border-collapse hidden md:table table-fixed">
                    <colgroup>
                        <col style="width:32%;"><col style="width:20%;"><col style="width:22%;"><col style="width:14%;"><col style="width:12%;">
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #e0e0e0;">
                        <tr>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Job Title</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Coordinator</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Type</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($this->jobPostings as $index => $job)
                        @php
                            $isActive         = $job->status === 'ACTIVE';
                            $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                            $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                            $daysLeft         = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
                            $isUrgent         = $daysLeft <= 7 && !$isDeadlinePassed;
                            $organizerName    = $job->organizer?->name ?? null;
                            $organizerCollege = $job->_organizerCollege ?? null;
                            $rowNum           = ($this->jobPostings->currentPage() - 1) * $this->jobPostings->perPage() + $index + 1;
                            $canShare         = $isActive && !$isDeadlinePassed;
                        @endphp
                        <tr class="bg-white cursor-pointer transition-colors duration-100 hover:bg-[#f5f0fa]"
                            wire:click="viewJob({{ $job->id }})"
                            wire:key="admjob-row-{{ $job->id }}"
                            data-adm-row>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#111111]">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5 text-[#666666] truncate">{{ $job->company_name }} &middot; {{ $job->created_at->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                @if($organizerName)
                                    <p class="text-sm font-semibold text-[#111111] truncate">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5 text-[#7a3f91] font-semibold truncate">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold whitespace-nowrap">
                                        Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-4 overflow-hidden">
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 whitespace-nowrap">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @if($isActive && $isUrgent)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-fire text-[9px] mr-1"></i>Expiring
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        <i class="fas fa-ban text-[9px] mr-1"></i>Inactive
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 sm:px-5 py-4 text-center overflow-visible">
                                <div class="flex items-center justify-center gap-1.5" @click.stop>
                                    @if($canShare)
                                        <span class="adm-share-tip-wrap">
                                            <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                                    wire:loading.attr="disabled" wire:target="openShareJobModal({{ $job->id }})"
                                                    data-adm-action
                                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
                                                           bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                                <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareJobModal({{ $job->id }})"></i>
                                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareJobModal({{ $job->id }})"></i>
                                            </button>
                                            <span class="adm-share-tip-bubble">Share</span>
                                        </span>
                                    @else
                                        <span class="text-xs text-[#bbbbbb]">&mdash;</span>
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
                        $isActive         = $job->status === 'ACTIVE';
                        $isDeadlinePassed = $job->_isDeadlinePassed ?? false;
                        $dl               = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                        $daysLeft         = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);
                        $isUrgent         = $daysLeft <= 7 && !$isDeadlinePassed;
                        $canShare         = $isActive && !$isDeadlinePassed;
                    @endphp
                    <div class="adm-mrow" wire:click="viewJob({{ $job->id }})" wire:key="admjob-mrow-{{ $job->id }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#111111]">{{ $job->job_title }}</p>
                                @if($isActive && $isUrgent)
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-fire text-[8px] mr-1"></i>Expiring
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-circle-check text-[8px] mr-1"></i>Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-[10px] font-semibold px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap flex-shrink-0">
                                        <i class="fas fa-ban text-[8px] mr-1"></i>Inactive
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs mt-1 text-[#666666]">
                                {{ $job->company_name }} &middot; {{ $job->employment_type }}
                            </p>
                            <div class="flex items-center justify-between mt-2">
                                <p class="text-xs text-[#7a3f91] font-semibold truncate">
                                    {{ $job->organizer?->name ?? 'Alumni Director' }}
                                </p>
                                @if($canShare)
                                    <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                            wire:loading.attr="disabled" wire:target="openShareJobModal({{ $job->id }})"
                                            aria-label="Share"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer flex-shrink-0
                                                   bg-blue-100 text-blue-600 border border-blue-200 active:bg-white active:border-blue-400 disabled:opacity-60 disabled:cursor-wait">
                                        <i class="fas fa-share-nodes" wire:loading.remove wire:target="openShareJobModal({{ $job->id }})"></i>
                                        <i class="fas fa-spinner fa-spin" wire:loading wire:target="openShareJobModal({{ $job->id }})"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f2f2f2]">
                    <i class="fas fa-briefcase text-xl text-[#111111]"></i>
                </div>
                <div>
                    <p class="font-bold text-base text-[#111111]">
                        @if($search || $filterStatus || $filterType || $filterCollege) No jobs match your filters
                        @else No job postings yet
                        @endif
                    </p>
                    <p class="text-sm mt-1 text-[#111111]">
                        @if($search || $filterStatus || $filterType || $filterCollege) Try clearing your filters to see all postings.
                        @else No job postings have been submitted yet.
                        @endif
                    </p>
                </div>
                @if($search || $filterStatus || $filterType || $filterCollege)
                    <button wire:click="resetFilters"
                            class="px-4 py-2 rounded-xl text-sm font-bold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
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
                @if($filterStatus || $filterType || $filterCollege || $search)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-bold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-bold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif>
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>

</div>


{{-- ══ VIEW JOB — FULL SCREEN ══ --}}
@if($showViewModal && $this->viewingJob)
@php
    $vj           = $this->viewingJob;
    $isActive     = $vj->status === 'ACTIVE';
    $vDl          = \Carbon\Carbon::parse($vj->deadline)->setTimezone('Asia/Manila');
    $vIsExp       = now('Asia/Manila')->startOfDay()->gt($vDl->copy()->startOfDay());
    $vDaysLeft    = (int) now('Asia/Manila')->startOfDay()->diffInDays($vDl->copy()->startOfDay(), false);
    $vIsUrgent    = $vDaysLeft <= 7 && !$vIsExp;
    $vCreatedPH   = \Carbon\Carbon::parse($vj->created_at)->setTimezone('Asia/Manila');
    $displayType  = ($vj->company_type === $vj->company_name) ? 'PHILCST' : $vj->company_type;
    $vOrgName     = $vj->organizer?->name ?? null;
    $vOrgCollege  = null;
    if ($vj->organizer) {
        $vOrgCollege = \App\Models\Course::where('college', $vj->organizer->department)->value('college')
            ?? $vj->organizer->department ?? null;
    }
    $vCanShare  = $isActive && !$vIsExp;
    $vJobImgUrl = $this::jobImageUrl($vj->job_image ?? null);

    $vStatusLabel = $isActive ? 'Active' : 'Inactive';
    $vStatusColor = $isActive ? 'text-emerald-600' : 'text-amber-600';

    $vDeadlineLabel = $vIsExp
        ? 'Deadline passed'
        : ($vIsUrgent
            ? ($vDaysLeft === 0 ? 'Closing today' : $vDaysLeft.' day'.($vDaysLeft !== 1 ? 's' : '').' left')
            : $vDl->diffForHumans());
    $vDeadlineColor = $vIsExp ? 'text-red-600' : ($vIsUrgent ? 'text-orange-600' : 'text-[#111111]');
@endphp

{{-- Outer wrapper: light gray overall bg --}}
<div class="fixed inset-0 flex flex-col overflow-hidden adm-fs-in" style="background:#f2f2f2;z-index:9995;"
     @keydown.escape.window="$wire.closeViewModal()">

    {{-- Purple header bar --}}
    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-sm" style="background:#7a3f91;">
        <div class="min-w-0">
            <p class="text-white/60 text-xs font-bold uppercase tracking-widest">Job Details</p>
            <h2 class="text-white font-bold text-base leading-tight truncate">{{ $vj->job_title }}</h2>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($vCanShare)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareJobModal({{ $vj->id }})"
                            wire:loading.attr="disabled" wire:target="openShareJobModal({{ $vj->id }})"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20 disabled:opacity-60 disabled:cursor-wait"
                            aria-label="Share">
                        <i class="fas fa-share-nodes text-white text-sm" wire:loading.remove wire:target="openShareJobModal({{ $vj->id }})"></i>
                        <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="openShareJobModal({{ $vj->id }})"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111111] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111111]"></span>
                    </div>
                </div>
            @endif
            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        wire:loading.attr="disabled" wire:target="closeViewModal"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20 disabled:opacity-60 disabled:cursor-wait"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm" wire:loading.remove wire:target="closeViewModal"></i>
                    <i class="fas fa-spinner fa-spin text-white text-sm" wire:loading wire:target="closeViewModal"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111111] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111111]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden adm-scroll">

        {{-- LEFT PANEL — white bg, white field cards --}}
        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-[#e0e0e0] overflow-visible lg:overflow-y-auto adm-scroll" style="background:#ffffff;">

            <div class="mx-4 mt-4 mb-0 flex-shrink-0 rounded-xl overflow-hidden" style="height:150px;">
                <img src="{{ $vJobImgUrl }}" alt="{{ $vj->job_title }}"
                     class="w-full h-full object-cover"
                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
            </div>

            {{-- Status + chip row --}}
            <div class="px-4 pt-3 pb-1 flex-shrink-0 flex items-center justify-between">
                <span class="text-sm font-bold {{ $vStatusColor }}">{{ $vStatusLabel }}</span>
                @if($vOrgName)
                    <span class="vw-chip">{{ $vOrgName }}</span>
                @else
                    <span class="vw-chip">Alumni Director</span>
                @endif
            </div>

            <div class="flex flex-col gap-2.5 px-4 pb-4 pt-2">

                <div class="vw-field">
                    <p class="vw-label">Organization</p>
                    <p class="vw-value">{{ $vj->company_name }}</p>
                    @if($displayType !== 'PHILCST')<p class="vw-subvalue">{{ $displayType }}</p>@endif
                </div>

                @if($vj->location)
                <div class="vw-field">
                    <p class="vw-label">Location</p>
                    <p class="vw-value">{{ $vj->location }}</p>
                </div>
                @endif

                <div class="vw-field">
                    <p class="vw-label">Employment</p>
                    <p class="vw-value">{{ $vj->employment_type }}</p>
                    <p class="vw-subvalue">{{ $vj->experience_level }}</p>
                </div>

                @if($vj->salary)
                <div class="vw-field">
                    <p class="vw-label">Salary</p>
                    <p class="vw-value">{{ $vj->salary }}</p>
                </div>
                @endif

                <div class="vw-field">
                    <p class="vw-label">Deadline</p>
                    <p class="vw-value {{ $vIsExp ? 'text-red-600' : '' }}">{{ $vDl->format('F d, Y') }}</p>
                    <p class="vw-subvalue {{ $vDeadlineColor }}">{{ $vDeadlineLabel }}</p>
                </div>

                @if($vj->target_college)
                <div class="vw-field">
                    <p class="vw-label">Target Colleges</p>
                    <p class="vw-value">{{ str_replace(',', ', ', $vj->target_college) }}</p>
                </div>
                @endif

                <div class="vw-field">
                    <p class="vw-label">{{ $vOrgName ? 'Coordinator' : 'Posted By' }}</p>
                    @if($vOrgName)
                        <p class="vw-value">{{ $vOrgName }}</p>
                        @if($vOrgCollege)<p class="vw-subvalue">{{ $vOrgCollege }}</p>@endif
                    @else
                        <p class="vw-value">Alumni Director</p>
                    @endif
                </div>

                @if($vj->updated_by)
                <div class="vw-field">
                    <p class="vw-label">Last Updated By</p>
                    <p class="vw-value">{{ $vj->updated_by }}</p>
                    <p class="vw-subvalue">{{ \Carbon\Carbon::parse($vj->updated_at)->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</p>
                </div>
                @endif

                <p class="text-xs text-center text-[#111111] pt-1 font-semibold">
                    Submitted {{ $vCreatedPH->diffForHumans() }} · {{ $vCreatedPH->format('M d, Y g:i A') }}
                </p>
            </div>
        </div>

        {{-- RIGHT PANEL — light gray bg (#f2f2f2), black text, white body boxes --}}
        <div class="flex-1 min-w-0 flex flex-col lg:overflow-hidden" style="background:#f2f2f2;">

            {{-- Scrollable body sections --}}
            <div class="lg:flex-1 lg:min-h-0 overflow-visible lg:overflow-y-auto adm-scroll px-5 py-5 flex flex-col gap-5" style="background:#f2f2f2;">

                @if($vj->description)
                <div>
                    <p class="vw-section-title">Job Description</p>
                    <div class="vw-body-box">{{ trim($vj->description) }}</div>
                </div>
                @endif

                @if($vj->qualifications)
                <div>
                    <p class="vw-section-title">Qualifications</p>
                    <div class="vw-body-box">{{ trim($vj->qualifications) }}</div>
                </div>
                @endif

                @if($vj->application_instructions)
                <div>
                    <p class="vw-section-title">How to Apply</p>
                    <div class="vw-body-box">{{ trim($vj->application_instructions) }}</div>
                </div>
                @endif

                @if(!$vj->description && !$vj->qualifications && !$vj->application_instructions)
                <div class="flex-1 flex items-center justify-center py-10">
                    <p class="text-sm font-bold text-[#111111]">No additional details provided.</p>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>
@endif


{{-- ══ SHARE JOB — MODAL ══ --}}
@if($showShareJobModal)
@php
    $sjBaseUrl  = $this->jobsBaseUrl();

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
    $sjLines[] = 'See full details and apply on the PHILCST Alumni Connect portal.';
    $sjLines[] = $sjBaseUrl;
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
    max-height: 90vh;
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
</style>

<div id="admjob-share-modal-backdrop" class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45 adm-share-backdrop"
     x-data="{
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         sharingTo: null,
         shareText: {{ json_encode($sjPostText) }},
         jobTitle:  {{ json_encode($shareJobTitle) }},

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

         async nativeShare() {
             this.sharingTo = 'native';
             try {
                 await navigator.share({ title: this.jobTitle, text: this.shareText });
             } catch (e) { /* cancelled by user — nothing to do */ }
             this.sharingTo = null;
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
     @keydown.escape.window="$wire.closeShareJobModal()">

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

                <div class="rounded-xl border border-gray-200 flex-shrink-0">
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

                <button type="button" @click="openFacebook()" :disabled="sharingTo==='facebook'" class="adm-share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#1877F2] text-sm" x-show="sharingTo==='facebook'" x-cloak></i>
                        <svg x-show="sharingTo!=='facebook'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='facebook' ? 'Opening…' : 'Share on Facebook'"></span>
                </button>

                <button type="button" @click="openMessenger()" :disabled="sharingTo==='messenger'" class="adm-share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <i class="fas fa-spinner fa-spin text-[#0084FF] text-sm" x-show="sharingTo==='messenger'" x-cloak></i>
                        <svg x-show="sharingTo!=='messenger'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold" x-text="sharingTo==='messenger' ? 'Opening…' : 'Send via Messenger'"></span>
                </button>

                <p class="text-[10px] text-center text-[#666666]">Sharing this job is available until its deadline passes.</p>
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

        // Fire into the admin notification infrastructure
        window.dispatchEvent(new CustomEvent('admin-job-updated', {
            detail: [{
                id:      payload.id,
                title:   payload.title  || 'New Job Posting',
                company: payload.company || '',
                poster:  payload.poster  || 'Alumni Director',
                // __message is used by the _saveAdminNotif handler in admin.blade.php
                // We override it via a custom event so the message is richer
            }]
        }));

        // Also directly save with the rich message by calling the store's
        // internal save pathway — we re-dispatch with the extra _message field
        // so the admin.blade.php handler can pick it up correctly.
        // (The admin-job-updated handler in admin.blade.php uses d.title for the
        //  message, so we patch the title to carry the poster info.)
        //
        // Actually: the admin.blade.php handler reads d.title for the notification
        // title and builds its own message string. We need a richer message, so
        // we dispatch a SECOND custom event with a _message override.
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
</script>