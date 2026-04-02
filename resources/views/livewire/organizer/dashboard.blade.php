<?php
/**
 * FILE: resources/views/livewire/organizer/dashboard.blade.php
 *
 * CHANGES:
 * ✓ Header — NOT sticky, scrolls with page, always #7a3f91 purple bg, better padding
 * ✓ Card order — Total Alumni · Total Events · Pending Review · Job Postings
 * ✓ Recent Jobs — shows title + company + type + deadline + status badge
 * ✓ Dept badges — hover tooltip shows full course name
 * ✓ Text readability — all gray text darkened to readable contrast
 * ✓ Account Teacher ID added
 * ✓ Header width aligned with cards (rounded card style, same horizontal margins)
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;

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

    // ── METRICS ──

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
            ->where('status', 'PENDING')
            ->count();
    }

    #[Computed]
    public function approvedEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->where('status', 'APPROVED')
            ->count();
    }

    #[Computed]
    public function rejectedEvents(): int
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->where('status', 'REJECTED')
            ->count();
    }

    #[Computed]
    public function totalJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->count();
    }

    #[Computed]
    public function activeJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->where('status', 'ACTIVE')
            ->count();
    }

    #[Computed]
    public function inactiveJobs(): int
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->where('status', 'INACTIVE')
            ->count();
    }

    // ── ALUMNI STATS ──

    #[Computed]
    public function totalAlumniInCollege(): int
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return 0;
        return Alumni::whereHas('course', fn($q) => $q->where('college', $dept))->count();
    }

    /**
     * Returns [ 'BEED' => ['count'=>50, 'name'=>'Bachelor of Elementary Education'], ... ]
     */
    #[Computed]
    public function alumniByDepartment(): array
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return [];
        $courses = Course::where('college', $dept)->orderBy('code')->get();
        $result  = [];
        foreach ($courses as $course) {
            $result[$course->code] = [
                'count' => Alumni::where('course_code', $course->code)->count(),
                'name'  => $course->name ?? $course->code,
            ];
        }
        return $result;
    }

    // ── RECENT ──

    #[Computed]
    public function recentEvents()
    {
        return OrganizerEvent::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentJobs()
    {
        return JobPosting::where('organizer_id', $this->organizerId)
            ->whereIn('status', ['ACTIVE', 'INACTIVE'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
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

<div class="min-h-screen bg-gray-50">

    {{-- ══════════════════════════════════════════
         OUTER WRAPPER — same horizontal padding as content,
         so the purple card aligns perfectly with the metric cards below
    ══════════════════════════════════════════ --}}
    <div class="max-w-screen-2xl mx-auto px-5 sm:px-8 lg:px-10 pt-7 pb-0">
        <div class="rounded-2xl px-6 sm:px-8 py-6 sm:py-7" style="background:#7a3f91;">
            <div class="flex items-center gap-4">

                {{-- Icon --}}
                <div class="w-13 h-13 sm:w-14 sm:h-14 rounded-2xl flex items-center justify-center flex-shrink-0"
                     style="background:rgba(255,255,255,.18);min-width:52px;min-height:52px;">
                    <i class="fas fa-gauge-high text-white text-xl"></i>
                </div>

                {{-- Text --}}
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight leading-snug">
                        {{ $this->greeting }}, {{ $this->organizerName }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                        <span class="text-white/80 text-sm font-medium">
                            <i class="fas fa-building-columns mr-1.5 text-white/60"></i>{{ $this->organizerDepartment }}
                        </span>
                        <span class="text-white/30 text-sm select-none">·</span>
                        <span class="text-white/70 text-sm">
                            <i class="fas fa-calendar-day mr-1.5 text-white/50"></i>{{ $this->todayDate }}
                        </span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ --}}
    <div class="max-w-screen-2xl mx-auto px-5 sm:px-8 lg:px-10 py-7">

        {{-- ── METRICS GRID  (order: Alumni · Total Events · Pending · Jobs) ── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">

            {{-- 1 · Total Alumni ── FIRST --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-purple-300 transition-all p-5">
                <div class="mb-3">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center"
                         style="background:rgba(122,63,145,.13);">
                        <i class="fas fa-graduation-cap text-base" style="color:#7a3f91;"></i>
                    </div>
                </div>
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Total Alumni</h3>
                <p class="text-3xl font-extrabold mb-2.5" style="color:#7a3f91;">
                    {{ $this->totalAlumniInCollege }}
                </p>

                {{-- Dept badges with tooltip showing full course name --}}
                @if(count($this->alumniByDepartment) > 0)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($this->alumniByDepartment as $code => $info)
                            <div class="relative group inline-flex">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold cursor-default select-none"
                                      style="background:rgba(122,63,145,.09);color:#7a3f91;border:1px solid rgba(122,63,145,.22);">
                                    {{ $code }}<span style="opacity:.4;" class="mx-0.5 font-normal">·</span>{{ $info['count'] }}
                                </span>
                                {{-- Tooltip --}}
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-20
                                            pointer-events-none opacity-0 group-hover:opacity-100
                                            transition-opacity duration-150">
                                    <div class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold text-white shadow-lg"
                                         style="background:#3b1a50;">
                                        {{ $info['name'] }}
                                    </div>
                                    {{-- Arrow --}}
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-px
                                                border-4 border-transparent"
                                         style="border-top-color:#3b1a50;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>{{ $this->organizerDepartment }}
                    </p>
                @endif
            </div>

            {{-- 2 · Total Events --}}
            <a href="{{ route('organizer.event/organizer') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-purple-300 transition-all p-5 block">
                <div class="mb-3">
                    <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center group-hover:bg-purple-200 transition">
                        <i class="fas fa-calendar-days text-base" style="color:#7a3f91;"></i>
                    </div>
                </div>
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Total Events</h3>
                <p class="text-3xl font-extrabold text-gray-900 mb-2.5">{{ $this->totalEvents }}</p>
                <div class="flex flex-wrap items-center gap-3 text-xs font-semibold">
                    <span class="flex items-center gap-1.5 text-red-600">
                        <i class="fas fa-circle-xmark"></i>{{ $this->rejectedEvents }} Rejected
                    </span>
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <i class="fas fa-circle-check"></i>{{ $this->approvedEvents }} Approved
                    </span>
                </div>
            </a>

            {{-- 3 · Pending Review Events --}}
            <a href="{{ route('organizer.event/organizer') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-yellow-300 transition-all p-5 block">
                <div class="mb-3">
                    <div class="w-11 h-11 rounded-xl bg-yellow-100 flex items-center justify-center group-hover:bg-yellow-200 transition">
                        <i class="fas fa-hourglass-end text-yellow-600 text-base"></i>
                    </div>
                </div>
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Pending Review Events</h3>
                <p class="text-3xl font-extrabold text-yellow-700 mb-2.5">{{ $this->pendingEvents }}</p>
                <p class="text-xs font-semibold text-gray-600">
                    <i class="fas fa-info-circle mr-1 text-gray-400"></i>Awaiting admin approval
                </p>
            </a>

            {{-- 4 · Job Postings --}}
            <a href="{{ route('organizer.job/management') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all p-5 block">
                <div class="mb-3">
                    <div class="w-11 h-11 rounded-xl bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition">
                        <i class="fas fa-briefcase text-blue-600 text-base"></i>
                    </div>
                </div>
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Job Postings</h3>
                <p class="text-3xl font-extrabold text-gray-900 mb-2.5">{{ $this->totalJobs }}</p>
                <div class="flex flex-wrap items-center gap-3 text-xs font-semibold">
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <i class="fas fa-circle text-[9px]"></i>{{ $this->activeJobs }} Active
                    </span>
                    <span class="flex items-center gap-1.5 text-gray-500">
                        <i class="fas fa-circle text-[9px]"></i>{{ $this->inactiveJobs }} Inactive
                    </span>
                </div>
            </a>

        </div>

        {{-- ── TWO-COLUMN LAYOUT ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- LEFT: recent panels (2/3) --}}
            <div class="lg:col-span-2 flex flex-col gap-5">

                {{-- Recent Events --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 flex items-center gap-2.5" style="background:#f9f5fc;">
                        <div class="w-7 h-7 rounded-lg bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-calendar-days text-xs" style="color:#7a3f91;"></i>
                        </div>
                        <h2 class="text-xs font-bold text-gray-700 uppercase tracking-wide">Recent Events</h2>
                    </div>
                    <div class="divide-y divide-gray-100 overflow-y-auto" style="min-height:272px;max-height:272px;">
                        @forelse($this->recentEvents as $event)
                            @php
                                $sc = match($event->status) {
                                    'PENDING'  => ['bg'=>'bg-yellow-50','text'=>'text-yellow-700','border'=>'border-yellow-200','icon'=>'hourglass-end'],
                                    'APPROVED' => ['bg'=>'bg-emerald-50','text'=>'text-emerald-700','border'=>'border-emerald-200','icon'=>'circle-check'],
                                    'REJECTED' => ['bg'=>'bg-red-50','text'=>'text-red-700','border'=>'border-red-200','icon'=>'circle-xmark'],
                                    default    => ['bg'=>'bg-gray-50','text'=>'text-gray-600','border'=>'border-gray-200','icon'=>'circle'],
                                };
                            @endphp
                            <a href="{{ route('organizer.event/organizer') }}"
                               class="px-5 py-3.5 hover:bg-purple-50/40 transition-colors block">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 truncate">{{ $event->title }}</p>
                                        <p class="text-xs text-gray-500 font-medium mt-0.5">
                                            <i class="fas fa-calendar text-gray-400 mr-1"></i>
                                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                                        </p>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold flex-shrink-0 border {{ $sc['bg'] }} {{ $sc['text'] }} {{ $sc['border'] }}">
                                        <i class="fas fa-{{ $sc['icon'] }} mr-1 text-[10px]"></i>{{ $event->status }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="flex flex-col items-center justify-center py-12 text-center" style="height:272px;">
                                <i class="fas fa-calendar-days text-2xl text-gray-200 block mb-2"></i>
                                <p class="text-sm font-semibold text-gray-500">No events posted yet</p>
                                <a href="{{ route('organizer.event/organizer') }}"
                                   class="text-xs font-bold hover:underline mt-1.5 inline-block" style="color:#7a3f91;">
                                    Create your first event →
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Recent Job Postings --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 flex items-center gap-2.5" style="background:#f0f5ff;">
                        <div class="w-7 h-7 rounded-lg bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-briefcase text-blue-600 text-xs"></i>
                        </div>
                        <h2 class="text-xs font-bold text-gray-700 uppercase tracking-wide">Recent Job Posts</h2>
                    </div>
                    <div class="divide-y divide-gray-100 overflow-y-auto" style="min-height:272px;max-height:272px;">
                        @forelse($this->recentJobs as $job)
                            @php
                                $dl        = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                                $isExpired = now('Asia/Manila')->gt($dl);
                            @endphp
                            <a href="{{ route('organizer.job/management') }}"
                               class="px-5 py-3.5 hover:bg-blue-50/40 transition-colors block">
                                <div class="flex items-start justify-between gap-3">

                                    {{-- Left: Title + meta row --}}
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 truncate">{{ $job->job_title }}</p>
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-1">
                                            {{-- Company --}}
                                            <span class="text-xs font-medium text-gray-600 flex items-center gap-1">
                                                <i class="fas fa-building text-gray-400 text-[10px]"></i>
                                                {{ $job->company_name }}
                                            </span>
                                            <span class="text-gray-300 text-xs select-none">·</span>
                                            {{-- Employment type --}}
                                            <span class="text-xs font-semibold text-blue-600">{{ $job->employment_type }}</span>
                                            <span class="text-gray-300 text-xs select-none">·</span>
                                            {{-- Deadline --}}
                                            <span class="text-xs font-medium {{ $isExpired ? 'text-red-500' : 'text-gray-500' }}">
                                                <i class="fas fa-calendar-xmark text-[10px] mr-0.5"></i>
                                                {{ $dl->format('M d, Y') }}
                                                @if($isExpired)<span class="font-bold"> · Expired</span>@endif
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Right: Status badge --}}
                                    @if($job->status === 'ACTIVE')
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold flex-shrink-0 bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <i class="fas fa-circle text-[8px] mr-1"></i>ACTIVE
                                        </span>
                                    @else
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold flex-shrink-0 bg-amber-50 text-amber-700 border border-amber-200">
                                            <i class="fas fa-circle-xmark text-[10px] mr-1"></i>INACTIVE
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="flex flex-col items-center justify-center py-12 text-center" style="height:272px;">
                                <i class="fas fa-briefcase text-2xl text-gray-200 block mb-2"></i>
                                <p class="text-sm font-semibold text-gray-500">No job postings yet</p>
                                <a href="{{ route('organizer.job/management') }}"
                                   class="text-xs font-bold hover:underline mt-1.5 inline-block" style="color:#7a3f91;">
                                    Create your first job posting →
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>

            </div>

            {{-- RIGHT: quick actions + account (1/3) --}}
            <div class="flex flex-col gap-5">

                {{-- Quick Actions --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                    <h2 class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-3.5 flex items-center gap-2">
                        <i class="fas fa-bolt" style="color:#7a3f91;"></i> Quick Actions
                    </h2>
                    <div class="flex flex-col gap-2.5">
                        <a href="{{ route('organizer.event/organizer') }}"
                           class="flex items-center gap-3 px-4 py-3 rounded-xl border transition-all group"
                           style="border-color:rgba(122,63,145,.25);background:rgba(122,63,145,.06);"
                           onmouseover="this.style.background='rgba(122,63,145,.13)'"
                           onmouseout="this.style.background='rgba(122,63,145,.06)'">
                            <i class="fas fa-calendar-plus text-base flex-shrink-0" style="color:#7a3f91;"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold" style="color:#7a3f91;">Post Event</p>
                                <p class="text-xs font-medium" style="color:#8b4fb0;">Create and submit event</p>
                            </div>
                            <i class="fas fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"
                               style="color:rgba(122,63,145,.4);"></i>
                        </a>
                        <a href="{{ route('organizer.job/management') }}"
                           class="flex items-center gap-3 px-4 py-3 rounded-xl border border-blue-200 bg-blue-50 hover:bg-blue-100 transition-colors group">
                            <i class="fas fa-briefcase text-blue-600 text-base flex-shrink-0"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-blue-700">Post Job</p>
                                <p class="text-xs font-medium text-blue-600">Create job listing</p>
                            </div>
                            <i class="fas fa-arrow-right text-blue-400 text-xs group-hover:translate-x-1 transition-transform"></i>
                        </a>
                    </div>
                </div>

                {{-- Account Info --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                    <h2 class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-3.5 flex items-center gap-2">
                        <i class="fas fa-user-circle" style="color:#7a3f91;"></i> Account
                    </h2>
                    <div class="divide-y divide-gray-100">

                        <div class="flex items-center justify-between py-2.5">
                            <span class="text-xs font-semibold text-gray-500">Name</span>
                            <span class="text-xs font-bold text-gray-900 text-right">{{ $this->organizerName }}</span>
                        </div>

                        <div class="flex items-center justify-between py-2.5">
                            <span class="text-xs font-semibold text-gray-500">Teacher ID</span>
                            <span class="text-xs font-bold font-mono text-right" style="color:#7a3f91;">
                                {{ $this->organizerTeacherId }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-2.5">
                            <span class="text-xs font-semibold text-gray-500">Email</span>
                            <span class="text-xs font-semibold text-gray-800 truncate ml-2 max-w-[160px] text-right">
                                {{ $this->organizerEmail }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-2.5">
                            <span class="text-xs font-semibold text-gray-500">College</span>
                            <span class="text-xs font-bold text-gray-900 text-right">{{ $this->organizerDepartment }}</span>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>