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

        // ── EXPIRING SOON special filter (ACTIVE + deadline within 7 days) ──
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

    // ── View ──────────────────────────────────────────────────────────────────
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

    // ── Share Modal ────────────────────────────────────────────────────────────
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

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

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
.adm-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.adm-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

select.adm-select-arrow {
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

/* ── Stat card: display-only, no hover effect ── */
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
</style>

{{-- Hover tooltip (row) --}}
<div id="admjob-hover-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold tracking-[.05em] px-3 py-1.5 rounded-[7px] whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
     style="transform: translate(12px, -110%);">
    <i class="fas fa-eye mr-1.5"></i>View Details
    <span class="absolute top-full left-3.5 border-[5px] border-transparent border-t-[#1a1a1a]"></span>
</div>

{{-- Action button tooltip (fixed-position — escapes the table's scroll clipping) --}}
<div id="admjob-action-tip"
     class="fixed bg-[#1a1a1a] text-white text-[11px] font-semibold px-2.5 py-1.5 rounded-md whitespace-nowrap pointer-events-none opacity-0 transition-opacity duration-150 z-[99999] shadow-[0_4px_14px_rgba(0,0,0,.30)]"
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
                <h1 class="text-xl font-semibold tracking-tight text-[#333333]">Job Postings</h1>
                <p class="text-xs leading-relaxed mt-0.5 text-[#555555]">Monitor and review all job listings across colleges.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 uppercase tracking-wide self-start sm:self-center">
            <i class="fas fa-shield-halved text-purple-600 text-[10px]"></i>
            Admin Control Panel
        </span>
    </div>

    {{-- ── STAT CARDS (display-only, no click) ── --}}
    @php $s = $this->stats; @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 flex-shrink-0">

        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#f5eef9;">
                <i class="fas fa-briefcase text-sm" style="color:#7a3f91;"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Total</p>
                <p class="text-xl font-semibold leading-tight" style="color:#333333;">{{ $s['total'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-emerald-50">
                <i class="fas fa-circle-check text-sm text-emerald-600"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Active</p>
                <p class="text-xl font-semibold leading-tight text-emerald-600">{{ $s['active'] }}</p>
            </div>
        </div>

        <div class="adm-stat-card">
            <div class="adm-stat-icon bg-amber-50">
                <i class="fas fa-ban text-sm text-amber-600"></i>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest" style="color:#999999;">Inactive</p>
                <p class="text-xl font-semibold leading-tight text-amber-600">{{ $s['inactive'] }}</p>
            </div>
        </div>

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

    {{-- ══ UNIFIED TABLE BLOCK ══ --}}
    <div class="flex flex-col rounded-2xl overflow-hidden border border-[#E8E0F0] shadow-sm flex-shrink-0" style="height: 65vh; max-height: 65vh; overflow: hidden;">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-[#F5F5F5] border-b border-[#E8E0F0] px-3.5 py-2.5 flex-shrink-0 flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide text-[#7a3f91]">
                Filters
            </div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none text-[#333333] z-[1]"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Search title or company…"
                       class="w-full pl-9 pr-4 py-2 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] placeholder-[#a78bbd] font-normal
                              hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            {{-- Status filter — now includes Expiring Soon --}}
            <select wire:model.live="filterStatus"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="EXPIRING">Expiring Soon (≤7 days)</option>
            </select>

            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] font-normal
                           hover:border-[#c4b5d4] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition adm-select-arrow hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>

            {{-- Active pill: Status --}}
            @if($filterStatus)
            @php
                $sPillMap = [
                    'ACTIVE'    => ['label' => 'Active',               'cls' => 'bg-emerald-50 border-emerald-300 text-emerald-800'],
                    'INACTIVE'  => ['label' => 'Inactive',             'cls' => 'bg-amber-50 border-amber-300 text-amber-800'],
                    'EXPIRING'  => ['label' => 'Expiring Soon',        'cls' => 'bg-orange-50 border-orange-300 text-orange-800'],
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

            {{-- Active pill: Type --}}
            @if($filterType)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border bg-blue-50 border-blue-300 text-blue-800">
                <i class="fas fa-filter text-[9px]"></i>{{ $filterType }}
                <button wire:click="$set('filterType', '')" type="button"
                        class="ml-0.5 hover:opacity-70 transition leading-none cursor-pointer">
                    <i class="fas fa-xmark text-[10px]"></i>
                </button>
            </span>
            @endif

            {{-- Active pill: College --}}
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
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-normal text-[#333333]
                           bg-white border border-[#E8E0F0] hover:bg-gray-50 transition active:scale-95 disabled:pointer-events-none cursor-pointer">
                <i class="fas fa-rotate-left text-sm text-[#333333]"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            {{-- Mobile selects --}}
            <select wire:model.live="filterType"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Types</option>
                @foreach($this->jobOptions->get('employment_type', collect()) as $opt)
                    <option value="{{ $opt->label }}">{{ $opt->label }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterCollege"
                    class="py-2 px-3 text-sm border border-[#E8E0F0] rounded-lg bg-white text-[#333333] flex-1 sm:hidden adm-select-arrow">
                <option value="">All Colleges</option>
                @foreach($this->collegesWithDepts as $college)
                    <option value="{{ $college['name'] }}">{{ $college['name'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── TABLE WRAPPER ── --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

            @if($this->jobPostings->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto adm-scroll bg-white">
                <table class="w-full bg-white border-collapse">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow: 0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest w-10 text-[#555555]">#</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest text-[#555555]">Job Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden lg:table-cell text-[#555555]">Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell text-[#555555]">Type</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest text-[#555555]">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-widest w-24 text-[#555555]"></th>
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
                            data-admjob-row>

                            <td class="px-4 py-3.5 text-xs font-semibold text-purple-400 text-center">
                                {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                            </td>

                            <td class="px-4 py-3.5 max-w-[230px]">
                                <p class="font-semibold text-sm leading-snug line-clamp-2 text-[#333333]">{{ $job->job_title }}</p>
                                <p class="text-xs mt-0.5 truncate text-[#666666]">{{ $job->company_name }}</p>
                                <p class="text-xs mt-0.5 text-[#bbbbbb]">{{ $job->created_at->diffForHumans() }}</p>
                            </td>

                            <td class="px-4 py-3.5 hidden lg:table-cell max-w-[160px]">
                                @if($organizerName)
                                    <p class="text-sm font-semibold text-[#333333] truncate">{{ $organizerName }}</p>
                                    @if($organizerCollege)
                                        <p class="text-xs mt-0.5 text-[#7a3f91] truncate">{{ $organizerCollege }}</p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold whitespace-nowrap">
                                        Alumni Director
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1.5 rounded-xl border border-purple-200 bg-purple-50 text-purple-700 whitespace-nowrap">
                                    {{ $job->employment_type }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-center">
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

                            {{-- Actions: Share only — row click already opens View --}}
                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1.5" @click.stop>
                                    @if($canShare)
                                        <button wire:click.stop="openShareJobModal({{ $job->id }})"
                                                data-admjob-action data-tip="Share"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-xs font-semibold transition cursor-pointer
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
                        @else No job postings have been submitted yet.
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
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
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
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
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
@endphp

<div class="fixed inset-0 z-50 flex flex-col bg-gray-50 overflow-hidden adm-fs-in"
     @keydown.escape.window="$wire.closeViewModal()">

    <div class="flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-md"
         style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
        <div class="flex items-center gap-3 min-w-0">
            <div>
                <p class="text-white/60 text-xs font-semibold uppercase tracking-widest">Job Details</p>
                <h2 class="text-white font-semibold text-base leading-tight truncate">{{ $vj->job_title }}</h2>
            </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0 ml-3">
            @if($vCanShare)
                <div class="relative inline-flex group">
                    <button type="button" wire:click="openShareJobModal({{ $vj->id }})"
                            class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/14 border border-white/20 hover:bg-white/24"
                            aria-label="Share job">
                        <i class="fas fa-share-nodes text-white text-sm"></i>
                    </button>
                    <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                        Share
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                    </div>
                </div>
            @endif

            <div class="relative inline-flex group">
                <button wire:click="closeViewModal" type="button"
                        class="relative inline-flex items-center justify-center w-8 h-8 rounded-lg cursor-pointer transition active:scale-95 bg-white/10 border border-white/15 hover:bg-white/22"
                        aria-label="Close">
                    <i class="fas fa-xmark text-white text-sm"></i>
                </button>
                <div class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 bg-[#111827] text-white text-[10px] font-bold uppercase tracking-[.05em] px-2.5 py-1 rounded-md whitespace-nowrap pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity z-[9999]">
                    Close
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-[#111827]"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <div class="w-full lg:w-[380px] flex flex-col flex-shrink-0 border-b lg:border-b-0 lg:border-r border-gray-200 bg-white overflow-y-auto adm-scroll">

            {{-- Job photo --}}
            <div class="mx-4 mt-4 mb-0 flex-shrink-0 rounded-xl overflow-hidden" style="height:150px;">
                <img src="{{ $vJobImgUrl }}" alt="{{ $vj->job_title }}"
                     class="w-full h-full object-cover"
                     onerror="this.src='{{ asset('storage/job/default-photo-job.jpg') }}'">
            </div>

            <div class="mx-4 mt-2 mb-0 flex-shrink-0 rounded-xl overflow-hidden flex items-center justify-between px-4 py-2"
                 style="background: linear-gradient(135deg, #7A3F91 0%, #4a1f6a 100%);">
                <div class="flex items-center gap-2 flex-wrap">
                    @if($isActive)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/80 text-white text-xs font-semibold"><i class="fas fa-circle-check text-[9px]"></i> Active</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/80 text-white text-xs font-semibold"><i class="fas fa-ban text-[9px]"></i> Inactive</span>
                    @endif
                    @if($vOrgName)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white/20 text-white text-xs font-semibold truncate max-w-[150px]">{{ $vOrgName }}</span>
                    @endif
                </div>
                <i class="fas fa-briefcase text-white/20 text-2xl flex-shrink-0"></i>
            </div>

            <div class="flex flex-col gap-2.5 px-4 pb-4 pt-3">

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

                <div class="flex items-center gap-3 p-3.5 rounded-xl border {{ $vIsExp ? 'bg-red-50 border-red-200' : ($vIsUrgent ? 'bg-orange-50 border-orange-200' : 'bg-gray-50 border-gray-100') }}">
                    <span class="adm-stat-icon {{ $vIsExp ? 'bg-red-100' : ($vIsUrgent ? 'bg-orange-100' : 'bg-blue-100') }}">
                        <i class="fas fa-calendar-xmark text-base {{ $vIsExp ? 'text-red-500' : ($vIsUrgent ? 'text-orange-500' : 'text-blue-600') }}"></i>
                    </span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">Deadline</p>
                        <p class="text-sm font-semibold {{ $vIsExp ? 'text-red-600' : '' }}" style="{{ $vIsExp ? '' : 'color:#333333;' }}">
                            {{ $vDl->format('F d, Y') }}
                        </p>
                        @if($vIsExp)
                            <p class="text-xs text-red-400 mt-0.5">Deadline passed</p>
                        @elseif($vIsUrgent)
                            <p class="text-xs text-orange-500 mt-0.5 urgent-pulse">{{ $vDaysLeft === 0 ? 'Closing today!' : $vDaysLeft.' day'.($vDaysLeft !== 1 ? 's' : '').' left' }}</p>
                        @else
                            <p class="text-xs mt-0.5" style="color:#777777;">{{ $vDl->diffForHumans() }}</p>
                        @endif
                    </div>
                </div>

                @if($vj->target_college)
                <div class="p-3.5 rounded-xl bg-purple-50 border border-purple-100">
                    <p class="text-[10px] font-bold uppercase tracking-wide mb-1.5" style="color:#7a3f91;">Target Colleges</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(explode(',', $vj->target_college) as $col)
                            <span class="inline-flex items-center font-semibold px-2 py-1 rounded-lg bg-white text-purple-700 border border-purple-200 text-xs">{{ trim($col) }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-100">
                    <span class="adm-stat-icon bg-blue-100"><i class="fas fa-{{ $vOrgName ? 'user-tie' : 'shield-halved' }} text-blue-600 text-base"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:#555555;">{{ $vOrgName ? 'Coordinator' : 'Posted By' }}</p>
                        @if($vOrgName)
                            <p class="text-sm font-semibold truncate" style="color:#333333;">{{ $vOrgName }}</p>
                            @if($vOrgCollege)<p class="text-xs mt-0.5" style="color:#7a3f91;">{{ $vOrgCollege }}</p>@endif
                        @else
                            <p class="text-sm font-semibold" style="color:#333333;">Alumni Director</p>
                        @endif
                    </div>
                </div>

                {{-- Status info box --}}
                <div class="p-3.5 rounded-xl border {{ $isActive ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }}">
                    @if($isActive)
                        <p class="text-sm font-bold flex items-center gap-1.5 text-emerald-800"><i class="fas fa-circle-check text-emerald-500 text-sm"></i> Active — Now Live</p>
                        <p class="text-sm text-emerald-700 mt-0.5">Visible to alumni and accepting applications.</p>
                    @else
                        <p class="text-sm font-bold flex items-center gap-1.5 text-amber-800"><i class="fas fa-ban text-amber-500 text-sm"></i> Inactive</p>
                        <p class="text-sm text-amber-700 mt-0.5">Hidden from alumni. Director can re-activate it.</p>
                    @endif
                </div>

                @if($vj->updated_by)
                <div class="px-4 py-3 rounded-xl bg-gray-50 border border-gray-100 text-xs" style="color:#555555;">
                    <span class="font-semibold">Last updated by:</span> {{ $vj->updated_by }}
                    <span class="ml-1">· {{ \Carbon\Carbon::parse($vj->updated_at)->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</span>
                </div>
                @endif

                <p class="text-xs text-center" style="color:#777777;">
                    Submitted {{ $vCreatedPH->diffForHumans() }} · {{ $vCreatedPH->format('M d, Y g:i A') }}
                </p>
            </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col overflow-hidden bg-gray-50">

            <div class="flex-shrink-0 px-5 py-3 bg-white border-b border-gray-200">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-100 text-blue-700">{{ $vj->employment_type }}</span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 border border-gray-200 text-[#333333]">{{ $vj->experience_level }}</span>
                    @if($vIsUrgent)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200 text-orange-600">
                            <i class="fas fa-fire text-[10px]"></i>{{ $vDaysLeft === 0 ? 'Closing today!' : ($vDaysLeft === 1 ? '1 day left' : $vDaysLeft.' days left') }}
                        </span>
                    @endif
                    @if($vIsExp)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 border border-red-200 text-red-700">
                            <i class="fas fa-ban text-[10px]"></i> Deadline Passed
                        </span>
                    @endif
                </div>
            </div>

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

        <div class="flex items-center justify-between px-6 py-3.5 border-b border-gray-100 flex-shrink-0 bg-white">
            <h2 class="text-base font-semibold flex items-center gap-2.5 text-[#333333]">
                <i class="fas fa-share-nodes text-blue-500 text-sm"></i>
                <span>Share Job Posting</span>
            </h2>
            <button @click="close()" type="button"
                    class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 transition cursor-pointer text-[#333333]">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row overflow-hidden">

            {{-- Preview --}}
            <div class="flex-1 px-6 py-5 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-4 overflow-y-auto adm-scroll">
                <p class="text-xs font-bold uppercase tracking-widest flex-shrink-0 text-[#333333]">Post preview</p>

                <div class="rounded-2xl border border-gray-200 overflow-hidden shadow-sm flex-shrink-0">
                    <div class="border-b border-gray-200 px-5 py-4 flex items-start gap-4 bg-[#f0f7ff]">
                        <div class="w-16 h-16 rounded-xl flex items-center justify-center flex-shrink-0 shadow"
                             style="background: linear-gradient(135deg,#7a3f91,#5e2f72);">
                            <i class="fas fa-briefcase text-white text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-base leading-tight text-[#333333]">{{ $shareJobTitle }}</p>
                            <p class="text-sm mt-1 font-semibold text-[#555555]">{{ $shareJobCompany }}@if($shareJobLocation) · {{ $shareJobLocation }}@endif</p>
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700">{{ $shareJobEmpType }}</span>
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
                    <div class="px-5 py-2.5 flex items-center gap-2 bg-[#f0f7ff]">
                        <i class="fas fa-globe text-xs text-blue-400"></i>
                        <span class="text-xs uppercase tracking-wider font-semibold text-blue-600">{{ strtoupper($sjHost) }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-circle-info text-blue-500 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800 mb-1">How sharing works</p>
                        <p class="text-sm text-blue-700 leading-relaxed">Clicking <strong>Facebook</strong> or <strong>Messenger</strong> copies the post caption to your clipboard and opens the platform. Press <kbd class="bg-blue-100 px-1.5 rounded font-mono text-xs">Ctrl+V</kbd> to paste.</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 flex items-start gap-3 flex-shrink-0">
                    <i class="fas fa-users text-blue-600 text-sm flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-blue-800">Post to Staff Channel</p>
                        <p class="text-sm mt-0.5 text-blue-700">Posts the job directly to the <strong>Directors &amp; Coordinators</strong> chat.
                            @if($shareJobTarget) Targeting: <strong>{{ $sjTargets }}</strong>.@endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- Share buttons --}}
            <div class="w-full md:w-80 px-6 py-5 flex flex-col gap-3 flex-shrink-0 overflow-y-auto adm-scroll">
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
                        style="background:linear-gradient(135deg, #0084FF 0%, #0050D0 100%);">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5">
                            <defs><linearGradient id="mgr_admjob" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#00B2FF"/><stop offset="100%" style="stop-color:#006AFF"/></linearGradient></defs>
                            <path fill="url(#mgr_admjob)" d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
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
                        class="w-full flex items-center gap-4 px-5 py-4 rounded-xl font-semibold text-sm shadow hover:shadow-md transition-all cursor-pointer group border-2 border-blue-200 hover:border-blue-400 hover:bg-blue-50 disabled:opacity-60 disabled:cursor-not-allowed bg-blue-50 text-blue-700">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-105 transition-transform bg-blue-600">
                        <i class="fas fa-shield-halved text-white text-base"></i>
                    </span>
                    <span class="flex-1 text-left">
                        <span wire:loading.remove wire:target="postJobToBatchChat" class="block font-semibold text-sm">Post to Staff Chat</span>
                        <span wire:loading wire:target="postJobToBatchChat" class="block font-semibold text-sm">
                            <i class="fas fa-spinner fa-spin mr-1 text-xs"></i> Posting…
                        </span>
                        <span class="block text-xs mt-0.5 text-blue-600">Directors &amp; Coordinators · caption included</span>
                    </span>
                    <i class="fas fa-paper-plane text-sm text-blue-500"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs font-semibold uppercase tracking-widest bg-white text-[#555555]">or copy link</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border-2 border-gray-200 hover:border-blue-300 hover:bg-blue-50 font-semibold text-sm transition cursor-pointer group bg-white text-[#333333]">
                    <span class="w-10 h-10 bg-gray-100 group-hover:bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0 transition">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy text-blue-500'" class="text-lg"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="font-semibold text-sm" :class="copied ? 'text-emerald-600' : 'text-blue-600'"
                           x-text="copied ? '✓ Link copied!' : 'Copy Jobs Page Link'"></p>
                        <p class="text-xs font-mono mt-0.5 truncate text-[#555555]">{{ $sjBaseUrl }}</p>
                    </div>
                </button>

                <button type="button" @click="close()"
                        class="w-full px-5 py-3 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50 transition mt-1 text-[#333333] flex items-center justify-center gap-1.5">
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
})();
</script>