<?php
/**
 * FILE: resources/views/livewire/organizer/dashboard.blade.php
 * ENHANCED: Added course relevance breakdown for employment overview
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public function mount(): void
    {
        if (!auth()->check() || !auth()->user()?->organizer) {
            abort(403, 'Access denied. Organizers only.');
        }
        set_time_limit(120);
    }

    #[Computed]
    public function organizerName(): string
    {
        return Auth::user()?->organizer?->name ?? Auth::user()?->name ?? 'Organizer';
    }

    #[Computed]
    public function organizerDepartment(): string
    {
        return Auth::user()?->organizer?->department ?? 'Your College';
    }

    #[Computed]
    public function organizerEmail(): string
    {
        return Auth::user()?->organizer?->email ?? Auth::user()?->email ?? '';
    }

    #[Computed]
    public function organizerId(): ?int
    {
        return Auth::user()?->organizer?->id;
    }

    #[Computed]
    public function organizerTeacherId(): string
    {
        return Auth::user()?->organizer?->id_number ?? '—';
    }

    #[Computed]
    public function organizerBatch(): string
    {
        return Auth::user()?->organizer?->batch ?? '';
    }

    #[Computed]
    public function allowedCourseCodes(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        return DB::table('courses')->where('college', $dept)->pluck('code')->toArray();
    }

    // ── Events ───────────────────────────────────────────────────
    #[Computed]
    public function totalEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED'])
            ->count();
    }

    #[Computed]
    public function pendingEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->where('status', 'PENDING')->count();
    }

    #[Computed]
    public function approvedEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->where('status', 'APPROVED')->count();
    }

    #[Computed]
    public function rejectedEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->where('status', 'REJECTED')->count();
    }

    // ── Jobs ─────────────────────────────────────────────────────
    #[Computed]
    public function totalJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])->count();
    }

    #[Computed]
    public function activeJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->where('status', 'ACTIVE')->count();
    }

    #[Computed]
    public function inactiveJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->where('status', 'INACTIVE')->count();
    }

    // ── Alumni / Employment ──────────────────────────────────────
    #[Computed]
    public function totalAlumniInCollege(): int
    {
        $q = DB::table('alumni')->whereNull('deleted_at');
        if ($this->organizerBatch)             $q->where('batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $q->whereIn('course_code', $this->allowedCourseCodes);
        return $q->count();
    }

    #[Computed]
    public function empCounts(): array
    {
        $base = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');

        if ($this->organizerBatch)             $base->where('a.batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $base->whereIn('a.course_code', $this->allowedCourseCodes);

        $rows = (clone $base)
            ->select('et.employment_status', DB::raw('COUNT(*) as total'))
            ->groupBy('et.employment_status')
            ->get()->keyBy('employment_status');

        $employed   = (int) ($rows['employed']->total      ?? 0);
        $self       = (int) ($rows['self_employed']->total ?? 0);
        $unemployed = (int) ($rows['unemployed']->total    ?? 0);
        $submitted  = $employed + $self + $unemployed;
        $noRecord   = max($this->totalAlumniInCollege - $submitted, 0);

        return compact('employed', 'self', 'unemployed', 'submitted', 'noRecord');
    }

    #[Computed]
    public function empCountsWithCourseRelevance(): array
    {
        $base = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');

        if ($this->organizerBatch)             $base->where('a.batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $base->whereIn('a.course_code', $this->allowedCourseCodes);

        $working = (clone $base)
            ->whereIn('et.employment_status', ['employed', 'self_employed']);

        $relatedCount = (clone $working)
            ->where('et.course_relevance', 'yes')
            ->count();

        return [
            'related'     => $relatedCount,
            'working'     => (clone $working)->count(),
        ];
    }

    // ── NEW: Course Relevance Breakdown for Employed ─────────────
    #[Computed]
    public function empCourseRelevanceBreakdown(): array
    {
        $base = DB::table('alumni as a')
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('a.deleted_at')
            ->whereNull('et.deleted_at');

        if ($this->organizerBatch)             $base->where('a.batch', $this->organizerBatch);
        if (!empty($this->allowedCourseCodes)) $base->whereIn('a.course_code', $this->allowedCourseCodes);

        $working = (clone $base)
            ->whereIn('et.employment_status', ['employed', 'self_employed']);

        $rows = (clone $working)
            ->select('et.course_relevance', DB::raw('COUNT(*) as total'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $related   = (int) ($rows['yes']->total       ?? 0);
        $notRelated = (int) ($rows['no']->total       ?? 0);
        $partial   = (int) ($rows['partially']->total ?? 0);
        $totalWorking = (clone $working)->count();

        return compact('related', 'notRelated', 'partial', 'totalWorking');
    }

    #[Computed]
    public function alumniByDepartment(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        $courses = Course::where('college', $dept)->orderBy('code')->get();
        $result  = [];
        foreach ($courses as $course) {
            $q = Alumni::where('course_code', $course->code);
            if ($this->organizerBatch) $q->where('batch', $this->organizerBatch);
            $result[$course->code] = [
                'count' => $q->count(),
                'name'  => $course->name ?? $course->code,
            ];
        }
        return $result;
    }

    #[Computed]
    public function recentEvents()
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED'])
            ->orderBy('created_at', 'desc')
            ->limit(5)->get();
    }

    #[Computed]
    public function recentJobs()
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('created_at', 'desc')
            ->limit(5)->get();
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = now('Asia/Manila')->hour;
        return match(true) {
            $hour < 12  => 'Good Morning',
            $hour < 18  => 'Good Afternoon',
            default     => 'Good Evening'
        };
    }

    #[Computed]
    public function todayDate(): string
    {
        return now('Asia/Manila')->format('l, F j, Y');
    }
};
?>

<style>
    /* ── Tooltip Styles ── */
    .tooltip-wrapper {
        position: relative;
        display: inline-block;
    }

    .tooltip-content {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        background-color: #1f1a2e;
        color: #ffffff;
        text-align: center;
        border-radius: 0.625rem;
        padding: 0.75rem 1rem;
        z-index: 1000;
        white-space: nowrap;
        font-size: 0.875rem;
        font-weight: 600;
        transition: opacity 0.3s ease, visibility 0.3s ease;
        box-shadow: 0 10px 32px rgba(0, 0, 0, 0.25);
    }

    .tooltip-wrapper:hover .tooltip-content {
        visibility: visible;
        opacity: 1;
    }

    .tooltip-content::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 6px solid transparent;
        border-top-color: #1f1a2e;
    }
</style>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-8 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ── PAGE HEADER ──────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-3xl font-semibold text-[#333333] leading-tight">
                {{ $this->greeting }}, {{ $this->organizerName }}
            </h1>
            <p class="text-xl text-[#666666] font-normal flex flex-wrap items-center gap-x-2">
                <span>{{ $this->todayDate }}</span>
                @if($this->organizerDepartment)
                    <span class="text-[#c0a0d8]">·</span>
                    <span class="font-semibold" style="color:#7A3F91;">{{ $this->organizerDepartment }}</span>
                @endif
                @if($this->organizerBatch)
                    <span class="text-[#c0a0d8]">·</span>
                    <span class="font-semibold" style="color:#7A3F91;">Batch {{ $this->organizerBatch }}</span>
                @endif
            </p>
        </div>
    </div>

    {{-- ── STAT CARDS ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Total Alumni --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-graduation-cap text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">Alumni</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalAlumniInCollege) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Total Alumni</p>
            @if(!empty($this->alumniByDepartment))
                <div class="flex flex-wrap gap-1.5 mt-2">
                    @foreach($this->alumniByDepartment as $code => $info)
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full cursor-default select-none"
                              style="background:rgba(122,63,145,.10);color:#7A3F91;border:1px solid rgba(122,63,145,.20);">
                            {{ $code }} · {{ $info['count'] }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Total Events --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center shadow">
                    <i class="fas fa-calendar-days text-base" style="color:#7A3F91;"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">Events</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalEvents) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Total Events</p>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                    <i class="fas fa-circle-check text-xs"></i> {{ $this->approvedEvents }} Approved
                </span>
                <span class="text-xs font-semibold text-amber-600 flex items-center gap-1">
                    <i class="fas fa-hourglass-end text-xs"></i> {{ $this->pendingEvents }} Pending
                </span>
            </div>
        </div>

        {{-- Pending Events --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center shadow">
                    <i class="fas fa-hourglass-end text-amber-500 text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Pending</span>
            </div>
            <p class="text-3xl font-semibold text-amber-600 leading-none">{{ number_format($this->pendingEvents) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Pending Review</p>
            @if($this->rejectedEvents > 0)
                <p class="text-xs text-red-500 font-semibold mt-2 flex items-center gap-1">
                    <i class="fas fa-circle-xmark text-xs"></i> {{ $this->rejectedEvents }} Rejected
                </p>
            @else
                <p class="text-xs text-[#999999] mt-2 font-normal">Awaiting admin approval</p>
            @endif
        </div>

        {{-- Job Postings --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center shadow">
                    <i class="fas fa-briefcase text-blue-500 text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Jobs</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalJobs) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Job Postings</p>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                    <i class="fas fa-circle text-[8px]"></i> {{ $this->activeJobs }} Active
                </span>
                <span class="text-xs font-semibold text-[#999999] flex items-center gap-1">
                    <i class="fas fa-circle text-[8px]"></i> {{ $this->inactiveJobs }} Inactive
                </span>
            </div>
        </div>

    </div>

    {{-- ── MAIN GRID: Employment (left 2/3) + Account Info (right 1/3) ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

        {{-- ── Employment Overview with Course Relevance ─────────── --}}
        @php
            $ec        = $this->empCounts;
            $cr        = $this->empCountsWithCourseRelevance;
            $crb       = $this->empCourseRelevanceBreakdown;
            $submitted = $ec['submitted'];

            $empRows = [
                [
                    'label'  => 'Employed',
                    'count'  => $ec['employed'],
                    'icon'   => 'fa-user-tie',
                    'color'  => '#7A3F91',
                    'light'  => '#F9F7FC',
                    'border' => '#E8E0F0',
                ],
                [
                    'label'  => 'Self-Employed',
                    'count'  => $ec['self'],
                    'icon'   => 'fa-store',
                    'color'  => '#2563eb',
                    'light'  => '#EFF6FF',
                    'border' => '#BFDBFE',
                ],
                [
                    'label'  => 'Unemployed',
                    'count'  => $ec['unemployed'],
                    'icon'   => 'fa-magnifying-glass',
                    'color'  => '#d97706',
                    'light'  => '#FFFBEB',
                    'border' => '#FCD34D',
                ],
                [
                    'label'  => 'No Record',
                    'count'  => $ec['noRecord'],
                    'icon'   => 'fa-circle-minus',
                    'color'  => '#6B7280',
                    'light'  => '#F9FAFB',
                    'border' => '#E5E7EB',
                ],
            ];
        @endphp

        <div class="lg:col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-briefcase text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Employment Overview</p>
                </div>
                <a href="{{ route('organizer.alumni/employment') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="p-4">
                {{-- Main Employment Status Grid --}}
                <div class="grid grid-cols-2 gap-3 mb-4">
                    @foreach($empRows as $row)
                    @php $barPct = $submitted > 0 && $row['count'] > 0 ? round(($row['count'] / max($submitted, 1)) * 100) : 0; @endphp
                    <div class="rounded-xl border p-3"
                         style="background:{{ $row['light'] }}; border-color:{{ $row['border'] }};">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                 style="background:{{ $row['color'] }}20; color:{{ $row['color'] }};">
                                <i class="fas {{ $row['icon'] }} text-xs"></i>
                            </div>
                            <span class="text-xl font-semibold text-[#333333]">{{ $row['label'] }}</span>
                        </div>
                        <p class="text-3xl font-semibold leading-none" style="color:{{ $row['color'] }};">
                            {{ number_format($row['count']) }}
                        </p>
                    </div>
                    @endforeach
                </div>

                {{-- Course Relevance Breakdown (for employed + self-employed) --}}
                @if($crb['totalWorking'] > 0)
                <div class="border-t border-[#E8E0F0] pt-4 mt-4">
                    <p class="text-sm font-semibold text-[#666666] uppercase tracking-wide mb-3 flex items-center gap-2">
                        <i class="fas fa-graduation-cap text-[#7A3F91]"></i>
                        Course Relevance (Employed Only)
                    </p>
                    <div class="grid grid-cols-3 gap-2">
                        {{-- Related --}}
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-center">
                            <p class="text-2xl font-semibold text-emerald-700 leading-none">{{ number_format($crb['related']) }}</p>
                            <p class="text-xs font-semibold text-emerald-600 mt-1 uppercase tracking-wide flex items-center justify-center gap-1">
                                <i class="fas fa-circle-check text-xs"></i> Related
                            </p>
                            <p class="text-xs text-emerald-600 mt-1.5 font-normal">
                                {{ $crb['totalWorking'] > 0 ? round(($crb['related'] / $crb['totalWorking']) * 100) : 0 }}%
                            </p>
                        </div>

                        {{-- Partially Related --}}
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-center">
                            <p class="text-2xl font-semibold text-amber-700 leading-none">{{ number_format($crb['partial']) }}</p>
                            <p class="text-xs font-semibold text-amber-600 mt-1 uppercase tracking-wide flex items-center justify-center gap-1">
                                <i class="fas fa-circle-half-stroke text-xs"></i> Partial
                            </p>
                            <p class="text-xs text-amber-600 mt-1.5 font-normal">
                                {{ $crb['totalWorking'] > 0 ? round(($crb['partial'] / $crb['totalWorking']) * 100) : 0 }}%
                            </p>
                        </div>

                        {{-- Not Related --}}
                        <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-center">
                            <p class="text-2xl font-semibold text-red-700 leading-none">{{ number_format($crb['notRelated']) }}</p>
                            <p class="text-xs font-semibold text-red-600 mt-1 uppercase tracking-wide flex items-center justify-center gap-1">
                                <i class="fas fa-circle-xmark text-xs"></i> Not Related
                            </p>
                            <p class="text-xs text-red-600 mt-1.5 font-normal">
                                {{ $crb['totalWorking'] > 0 ? round(($crb['notRelated'] / $crb['totalWorking']) * 100) : 0 }}%
                            </p>
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="mt-3 rounded-full h-2 bg-gray-200 overflow-hidden" style="background:rgba(122,63,145,.10);">
                        <div class="h-full flex" style="width: 100%;">
                            @if($crb['related'] > 0)
                            <div class="h-full" style="width: {{ ($crb['related'] / $crb['totalWorking']) * 100 }}%; background: #10b981;"></div>
                            @endif
                            @if($crb['partial'] > 0)
                            <div class="h-full" style="width: {{ ($crb['partial'] / $crb['totalWorking']) * 100 }}%; background: #f59e0b;"></div>
                            @endif
                            @if($crb['notRelated'] > 0)
                            <div class="h-full" style="width: {{ ($crb['notRelated'] / $crb['totalWorking']) * 100 }}%; background: #ef4444;"></div>
                            @endif
                        </div>
                    </div>

                    <p class="text-xs text-[#999999] mt-2 font-normal">
                        Out of <strong>{{ number_format($crb['totalWorking']) }}</strong> employed / self-employed alumni
                    </p>
                </div>
                @endif
            </div>

        </div>

        {{-- ── Account Info ────────────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center gap-2"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-user-circle text-white" style="font-size:12px;"></i>
                </div>
                <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Account</p>
            </div>

            <div class="divide-y divide-[#F5F5F5] px-4 flex-1">

                <div class="flex items-center justify-between py-3">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Name</span>
                    <div class="tooltip-wrapper ml-3">
                        <span class="text-sm font-semibold text-[#333333] text-right truncate max-w-[170px] block">{{ $this->organizerName }}</span>
                        <div class="tooltip-content">{{ $this->organizerName }}</div>
                    </div>
                </div>

                <div class="flex items-center justify-between py-3">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Teacher ID</span>
                    <div class="tooltip-wrapper ml-3">
                        <span class="text-sm font-semibold font-mono text-right" style="color:#7A3F91;">{{ $this->organizerTeacherId }}</span>
                        <div class="tooltip-content">{{ $this->organizerTeacherId }}</div>
                    </div>
                </div>

                <div class="flex items-start justify-between py-3">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0 mt-0.5">Email</span>
                    <div class="tooltip-wrapper ml-3">
                        <span class="text-xs text-[#666666] font-normal text-right break-all max-w-[170px] block">{{ $this->organizerEmail }}</span>
                        <div class="tooltip-content">{{ $this->organizerEmail }}</div>
                    </div>
                </div>

                <div class="flex items-center justify-between py-3">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">College</span>
                    <div class="tooltip-wrapper ml-3">
                        <span class="text-sm font-semibold text-[#333333] text-right truncate max-w-[170px] block">{{ $this->organizerDepartment }}</span>
                        <div class="tooltip-content">{{ $this->organizerDepartment }}</div>
                    </div>
                </div>

                @if($this->organizerBatch)
                <div class="flex items-center justify-between py-3">
                    <span class="text-xs font-semibold text-[#999999] uppercase tracking-wide shrink-0">Batch</span>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">{{ $this->organizerBatch }}</span>
                </div>
                @endif

            </div>
        </div>

    </div>

    {{-- ── BOTTOM GRID: Recent Events (left) + Recent Jobs (right) ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- ── Recent Events ───────────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-calendar-days text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Recent Events</p>
                </div>
                <a href="{{ route('organizer.event/organizer') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="divide-y divide-[#F5F5F5]">
                @forelse($this->recentEvents as $index => $event)
                @php
                    $sc = match($event->status) {
                        'PENDING'   => ['text-amber-700 bg-amber-50 border-amber-200',       'fa-hourglass-end', '#d97706'],
                        'APPROVED'  => ['text-emerald-700 bg-emerald-50 border-emerald-200', 'fa-circle-check',  '#059669'],
                        'REJECTED'  => ['text-red-600 bg-red-50 border-red-200',             'fa-circle-xmark',  '#dc2626'],
                        'COMPLETED' => ['text-blue-700 bg-blue-50 border-blue-200',          'fa-check-double',  '#2563eb'],
                        default     => ['text-[#666666] bg-[#F9F7FC] border-[#E8E0F0]',      'fa-circle',        '#9b59b6'],
                    };
                @endphp
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#FAFAFA] transition-colors relative z-0">
                    <span class="w-5 text-center text-sm font-semibold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
                         style="background:{{ $sc[2] }}20; color:{{ $sc[2] }};">
                        <i class="fas {{ $sc[1] }} text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-lg font-semibold text-[#333333] truncate">{{ $event->title }}</p>
                        <p class="text-sm text-[#999999] font-normal mt-0.5">
                            <i class="fas fa-calendar text-[#CCCCCC] mr-1"></i>
                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                        </p>
                    </div>
                    <div class="shrink-0 flex flex-col items-end gap-1">
                        <span class="text-sm font-semibold px-2 py-0.5 rounded-full border {{ $sc[0] }}">
                            {{ $event->status }}
                        </span>
                        <span class="text-sm text-[#999999] font-normal">{{ $event->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                @empty
                <div class="py-14 text-center">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f0e6f8;">
                        <i class="fas fa-calendar-days text-2xl" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#999999]">No events posted yet</p>
                    <a href="{{ route('organizer.event/organizer') }}" wire:navigate
                       class="text-xs font-semibold hover:underline mt-1 inline-block" style="color:#7A3F91;">
                        Create your first event →
                    </a>
                </div>
                @endforelse
            </div>

        </div>

        {{-- ── Recent Job Postings ─────────────────────────────────── --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-blue-500">
                        <i class="fas fa-briefcase text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Recent Job Posts</p>
                </div>
                <a href="{{ route('organizer.job/management') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="divide-y divide-[#F5F5F5]">
                @forelse($this->recentJobs as $index => $job)
                @php
                    $dl        = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $isExpired = now('Asia/Manila')->gt($dl);
                    $isActive  = $job->status === 'ACTIVE';
                @endphp
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#FAFAFA] transition-colors relative z-0">
                    <span class="w-5 text-center text-sm font-semibold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
                         style="background:{{ $isActive ? '#EFF6FF' : '#F9FAFB' }}; color:{{ $isActive ? '#2563eb' : '#6B7280' }};">
                        <i class="fas fa-briefcase text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-lg font-semibold text-[#333333] truncate">{{ $job->job_title }}</p>
                        <p class="text-sm text-[#999999] font-normal mt-0.5 flex flex-wrap items-center gap-1.5">
                            <span class="truncate max-w-[110px]">{{ $job->company_name }}</span>
                            <span class="text-[#E8E0F0]">·</span>
                            <span class="font-semibold text-blue-600">{{ $job->employment_type }}</span>
                            @if($isExpired)
                                <span class="text-[#E8E0F0]">·</span>
                                <span class="text-red-500 font-semibold">Closed</span>
                            @endif
                        </p>
                    </div>
                    <div class="shrink-0 flex flex-col items-end gap-1">
                        @if($isActive)
                            <span class="text-sm font-semibold px-2 py-0.5 rounded-full border text-emerald-700 bg-emerald-50 border-emerald-200">Active</span>
                        @else
                            <span class="text-sm font-semibold px-2 py-0.5 rounded-full border text-amber-700 bg-amber-50 border-amber-200">Inactive</span>
                        @endif
                        <span class="text-sm text-[#999999] font-normal">{{ $dl->format('M d, Y') }}</span>
                    </div>
                </div>
                @empty
                <div class="py-14 text-center">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3 bg-blue-50">
                        <i class="fas fa-briefcase text-2xl text-blue-300"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#999999]">No job postings yet</p>
                    <a href="{{ route('organizer.job/management') }}" wire:navigate
                       class="text-xs font-semibold hover:underline mt-1 inline-block" style="color:#7A3F91;">
                        Create your first job posting →
                    </a>
                </div>
                @endforelse
            </div>

        </div>

    </div>

</div>{{-- end page --}}