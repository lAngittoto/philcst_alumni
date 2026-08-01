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

/* ── Search / filter loading progress bar (same pattern as Event Organizer / User Management) ── */
.adm-filter-progress-track { height: 2px; width: 100%; overflow: hidden; background: transparent; position: relative; }
.adm-filter-progress-bar {
    position: absolute; top: 0; left: 0; height: 100%; width: 40%;
    border-radius: 99px; background: linear-gradient(135deg,#7a3f91,#9b59b6);
    animation: admFilterProgress 1s ease-in-out infinite;
}
@keyframes admFilterProgress { 0% { left: -40%; } 100% { left: 100%; } }

/* ── Table container height — locked, no page-level scroll ── */
.adm-table-card { display: flex; flex-direction: column; min-height: 0; height: 58vh; max-height: 58vh; }

@media (max-width: 640px) {
    .adm-table-card {
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: none !important;
        box-shadow: none !important;
    }
}

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
    font-size: 0.7rem;
    font-weight: 800;
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
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-[#111111]">Job Postings</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#111111]">Monitor and review all job listings across colleges.</p>
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
                <option value="EXPIRING">Expiring Soon (≤7 days)</option>
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
                <i class="fas fa-rotate-left text-sm text-[#111111]"></i>
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

        {{-- Filtering / searching progress bar --}}
        <div class="adm-filter-progress-track flex-shrink-0" wire:loading wire:target="search,filterStatus,filterType,filterCollege">
            <div class="adm-filter-progress-bar"></div>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto adm-scroll bg-white transition-opacity duration-200"
                 wire:loading.class="opacity-60" wire:target="search,filterStatus,filterType,filterCollege">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #e0e0e0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-[#111111]">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest hidden lg:table-cell text-[#111111]">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest hidden md:table-cell text-[#111111]">Type</th>
                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-widest text-[#111111]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-widest w-24 text-[#111111]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f0f0f0]">
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
                            data-admjob-row>

                            <td class="px-4 py-3.5 max-w-[230px]">
                                <p class="font-bold text-sm leading-snug line-clamp-2 text-[#111111]">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5 truncate text-[#111111] font-semibold">{{ $job->company_name }}</p>
                                <p class="text-xs mt-0.5 text-[#111111]">{{ $job->created_at->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell max-w-[160px]">
                                @if($organizerName)
                                    <p class="text-sm font-bold text-[#111111] truncate">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5 text-[#7a3f91] font-semibold truncate">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold whitespace-nowrap">
                                        Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 whitespace-nowrap">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                @if($isActive && $isUrgent)
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-orange-200 bg-orange-50 text-orange-700 whitespace-nowrap">
                                        <i class="fas fa-fire text-[9px] mr-1"></i>Expiring
                                    </span>
                                @elseif($isActive)
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 whitespace-nowrap">
                                        <i class="fas fa-circle-check text-[9px] mr-1"></i>Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-xs font-bold px-2.5 py-1.5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 whitespace-nowrap">
                                        <i class="fas fa-ban text-[9px] mr-1"></i>Inactive
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>
                                    @if($canShare)
                                        <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                                data-admjob-action data-tip="Share"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-bold transition cursor-pointer
                                                       bg-blue-100 text-blue-600 border border-blue-200 hover:bg-white hover:border-blue-400">
                                            <i class="fas fa-share-nodes"></i>
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
<div class="fixed inset-0 z-50 flex flex-col overflow-hidden adm-fs-in" style="background:#f2f2f2;"
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
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20"
                            aria-label="Share">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111111] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111111]"></span>
                    </div>
                </div>
            @endif
            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/20 hover:bg-white/20"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm"></i>
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


{{-- ══ SHARE JOB — SLIDE-OVER ══ --}}
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

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-[#e0e0e0] flex-shrink-0 bg-white">
            <h2 class="text-base font-bold flex items-center gap-2.5 text-[#111111]">
                <i class="fas fa-share-nodes text-blue-500 text-sm"></i>
                <span>Share Job Posting</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[#f2f2f2] transition cursor-pointer text-[#111111]">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-[#e0e0e0] flex flex-col gap-4 overflow-y-auto adm-scroll bg-white">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#111111]">Post preview</p>

                <div class="rounded-2xl border border-[#e0e0e0] overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-[#e0e0e0] px-5 py-4 flex items-start gap-4 bg-[#f0f7ff]">
                        <div class="w-16 h-16 rounded-xl flex items-center justify-center flex-shrink-0 shadow"
                             style="background: linear-gradient(135deg,#7a3f91,#5e2f72);">
                            <i class="fas fa-briefcase text-white text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-base leading-tight text-[#111111]">{{ $shareJobTitle }}</p>
                            <p class="text-sm mt-1 font-bold text-[#111111]">{{ $shareJobCompany }}@if($shareJobLocation) · {{ $shareJobLocation }}@endif</p>
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-bold bg-blue-100 text-blue-700">{{ $shareJobEmpType }}</span>
                                @if($shareJobTarget)
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-bold bg-[#f2f2f2] text-[#111111]">{{ Str::limit($sjTargets, 30) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if($sjDescPrev)
                    <div class="px-5 py-3.5 border-b border-[#e0e0e0] bg-white">
                        <p class="text-sm leading-relaxed text-[#111111]">{{ $sjDescPrev }}</p>
                    </div>
                    @endif
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f0f7ff]">
                        <i class="fas fa-globe text-xs text-blue-400"></i>
                        <span class="text-xs uppercase tracking-wider font-bold text-blue-600">{{ strtoupper($sjHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-bold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the post caption to your clipboard and opens the platform. Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-blue-600 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-bold text-blue-800">Post to Staff Channel</p>
                        <p class="text-sm mt-0.5 text-blue-700">Posts the job directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto adm-scroll bg-white">
                <p class="text-xs font-bold uppercase tracking-widest text-[#111111]">Share via</p>

                <div x-show="fbCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-emerald-50 border border-emerald-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-emerald-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-bold text-emerald-800">Text copied! Facebook is open.</p>
                        <p class="text-xs text-emerald-700 mt-0.5">Paste with <strong>Ctrl+V</strong> in the post composer.</p>
                    </div>
                </div>

                <div x-show="messengerCopied" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="bg-blue-50 border border-blue-300 rounded-xl px-4 py-3 flex items-start gap-2">
                    <i class="fas fa-check text-blue-600 text-sm mt-0.5 flex-shrink-0"></i>
                    <div>
                        <p class="text-sm font-bold text-blue-800">Text copied! Messenger is open.</p>
                        <p class="text-xs text-blue-700 mt-0.5">Paste with <strong>Ctrl+V</strong> in any chat.</p>
                    </div>
                </div>

                <button type="button" @click="shareOnFacebook()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl bg-[#1877F2] hover:bg-[#166fe5] text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#1877F2">
                            <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-bold text-sm">Post on Facebook</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens facebook.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <button type="button" @click="shareOnMessenger()"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl text-white font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group"
                        style="background:linear-gradient(135deg, #0084FF 0%, #0050D0 100%);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_admjob" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_admjob)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="flex-1 text-left">
                        <span class="block font-bold text-sm">Send via Messenger</span>
                        <span class="block text-xs text-white/70 mt-0.5">Copies caption + opens messenger.com</span>
                    </span>
                    <i class="fas fa-arrow-up-right-from-square text-white/60 text-sm group-hover:text-white transition"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-[#e0e0e0]"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-bold uppercase tracking-widest bg-white text-[#111111]">or post to staff</span>
                    </div>
                </div>

                <button type="button"
                        wire:click="postJobToBatchChat"
                        wire:loading.attr="disabled"
                        wire:target="postJobToBatchChat"
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-bold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-blue-200 hover:border-blue-400 hover:bg-blue-50 disabled:opacity-60 disabled:cursor-not-allowed bg-blue-50 text-blue-700">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-blue-600">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postJobToBatchChat" class="block font-bold text-sm">Post to Staff Chat</span>
                        <span wire:loading wire:target="postJobToBatchChat" class="block font-bold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5 text-blue-600">Directors &amp; Coordinators · caption included</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-blue-500"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-[#e0e0e0]"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-bold uppercase tracking-widest bg-white text-[#111111]">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-[#e0e0e0] hover:border-blue-300 hover:bg-blue-50 font-bold text-sm transition cursor-pointer group bg-white text-[#111111]">
                    <span class="w-10 h-10 bg-[#f2f2f2] group-hover:bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-blue-500'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-bold text-sm" :class="copied ? 'text-emerald-600' : 'text-blue-600'"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate text-[#111111]">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-[#e0e0e0] text-sm font-bold hover:bg-[#f2f2f2] transition mt-1 text-[#111111] flex items-center justify-center gap-1.5">
                    <i class="fas fa-xmark mr-1.5 text-xs"></i> Close
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
        document.querySelectorAll('[data-admjob-row]').forEach(function (row) {
            if (row._admjobTipBound) return;
            row._admjobTipBound = true;

            row.addEventListener('mousemove', function (e) {
                if (!tip) return;
                var actionWrap = e.target.closest('[data-admjob-action]');
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

        document.querySelectorAll('[data-admjob-action]').forEach(function (sw) {
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