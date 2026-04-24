<?php
/**
 * FILE: resources/views/livewire/organizer/dashboard.blade.php
 * UPDATED: Larger font sizes for better readability
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

    #[Computed]
    public function totalAlumniInCollege(): int
    {
        $dept = Auth::user()?->organizer?->department;
        if (!$dept) return 0;
        return Alumni::whereHas('course', fn($q) => $q->where('college', $dept))->count();
    }

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

    {{-- ══ HEADER ══ --}}
    <div class="max-w-screen-2xl mx-auto px-5 sm:px-8 lg:px-10 pt-7 pb-0">
        <div class="rounded-2xl px-6 sm:px-8 py-6 sm:py-7" style="background:#7a3f91;">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0"
                     style="background:rgba(255,255,255,.18);">
                    <i class="fas fa-gauge-high text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-white leading-snug">
                        {{ $this->greeting }}, {{ $this->organizerName }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1">
                        <span class="text-white/80 text-sm font-normal">
                            <i class="fas fa-building-columns mr-1.5 text-white/60"></i>{{ $this->organizerDepartment }}
                        </span>
                        <span class="text-white/30 text-sm select-none">·</span>
                        <span class="text-white/70 text-sm font-normal">
                            <i class="fas fa-calendar-day mr-1.5 text-white/50"></i>{{ $this->todayDate }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ MAIN CONTENT ══ --}}
    <div class="max-w-screen-2xl mx-auto px-5 sm:px-8 lg:px-10 py-7">

        {{-- ── METRICS GRID ── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-7">

            {{-- 1 · Total Alumni --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-purple-300 transition-all p-6">
                <div class="mb-4">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center"
                         style="background:rgba(122,63,145,.12);">
                        <i class="fas fa-graduation-cap text-base" style="color:#7a3f91;"></i>
                    </div>
                </div>
                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-2">Total Alumni</h3>
                <p class="text-4xl font-bold mb-4" style="color:#7a3f91;">
                    {{ $this->totalAlumniInCollege }}
                </p>
                @if(count($this->alumniByDepartment) > 0)
                    <div class="flex flex-wrap gap-2">
                        @foreach($this->alumniByDepartment as $code => $info)
                            <div class="relative group inline-flex">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-sm font-medium cursor-default select-none"
                                      style="background:rgba(122,63,145,.08);color:#7a3f91;border:1px solid rgba(122,63,145,.2);">
                                    {{ $code }}<span class="opacity-40 mx-0.5">·</span>{{ $info['count'] }}
                                </span>
                                {{-- Tooltip --}}
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-20
                                            pointer-events-none opacity-0 group-hover:opacity-100
                                            transition-opacity duration-150">
                                    <div class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-white shadow-lg"
                                         style="background:#3b1a50;">
                                        {{ $info['name'] }}
                                    </div>
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-px border-4 border-transparent"
                                         style="border-top-color:#3b1a50;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>{{ $this->organizerDepartment }}
                    </p>
                @endif
            </div>

            {{-- 2 · Total Events --}}
            <a href="{{ route('organizer.event/organizer') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-purple-300 transition-all p-6 block">
                <div class="mb-4">
                    <div class="w-11 h-11 rounded-xl bg-purple-100 flex items-center justify-center group-hover:bg-purple-200 transition">
                        <i class="fas fa-calendar-days text-base" style="color:#7a3f91;"></i>
                    </div>
                </div>
                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-2">Total Events</h3>
                <p class="text-4xl font-bold text-gray-800 mb-4">{{ $this->totalEvents }}</p>
                <div class="flex flex-wrap items-center gap-3 text-sm font-medium">
                    <span class="flex items-center gap-1.5 text-red-500">
                        <i class="fas fa-circle-xmark"></i>{{ $this->rejectedEvents }} Rejected
                    </span>
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <i class="fas fa-circle-check"></i>{{ $this->approvedEvents }} Approved
                    </span>
                </div>
            </a>

            {{-- 3 · Pending Review --}}
            <a href="{{ route('organizer.event/organizer') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-yellow-300 transition-all p-6 block">
                <div class="mb-4">
                    <div class="w-11 h-11 rounded-xl bg-yellow-100 flex items-center justify-center group-hover:bg-yellow-200 transition">
                        <i class="fas fa-hourglass-end text-yellow-500 text-base"></i>
                    </div>
                </div>
                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-2">Pending Review</h3>
                <p class="text-4xl font-bold text-yellow-600 mb-4">{{ $this->pendingEvents }}</p>
                <p class="text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1 text-gray-400"></i>Awaiting admin approval
                </p>
            </a>

            {{-- 4 · Job Postings --}}
            <a href="{{ route('organizer.job/management') }}"
               class="group bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all p-6 block">
                <div class="mb-4">
                    <div class="w-11 h-11 rounded-xl bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition">
                        <i class="fas fa-briefcase text-blue-500 text-base"></i>
                    </div>
                </div>
                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-2">Job Postings</h3>
                <p class="text-4xl font-bold text-gray-800 mb-4">{{ $this->totalJobs }}</p>
                <div class="flex flex-wrap items-center gap-3 text-sm font-medium">
                    <span class="flex items-center gap-1.5 text-emerald-600">
                        <i class="fas fa-circle text-[9px]"></i>{{ $this->activeJobs }} Active
                    </span>
                    <span class="flex items-center gap-1.5 text-gray-400">
                        <i class="fas fa-circle text-[9px]"></i>{{ $this->inactiveJobs }} Inactive
                    </span>
                </div>
            </a>

        </div>

        {{-- ── TWO-COLUMN LAYOUT ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- LEFT: recent panels (2/3) --}}
            <div class="lg:col-span-2 flex flex-col gap-6">

                {{-- Recent Events --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3" style="background:#f9f5fc;">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-calendar-days text-sm" style="color:#7a3f91;"></i>
                        </div>
                        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Recent Events</h2>
                    </div>
                    <div class="divide-y divide-gray-100 overflow-y-auto" style="min-height:320px;max-height:320px;">
                        @forelse($this->recentEvents as $event)
                            @php
                                $sc = match($event->status) {
                                    'PENDING'  => ['bg'=>'bg-yellow-50','text'=>'text-yellow-700','border'=>'border-yellow-200','icon'=>'hourglass-end'],
                                    'APPROVED' => ['bg'=>'bg-emerald-50','text'=>'text-emerald-700','border'=>'border-emerald-200','icon'=>'circle-check'],
                                    'REJECTED' => ['bg'=>'bg-red-50','text'=>'text-red-600','border'=>'border-red-200','icon'=>'circle-xmark'],
                                    default    => ['bg'=>'bg-gray-50','text'=>'text-gray-500','border'=>'border-gray-200','icon'=>'circle'],
                                };
                            @endphp
                            <a href="{{ route('organizer.event/organizer') }}"
                               class="px-6 py-4 hover:bg-purple-50/40 transition-colors block">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-base font-semibold text-gray-800 truncate">{{ $event->title }}</p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            <i class="fas fa-calendar text-gray-400 mr-1"></i>
                                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                                        </p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-sm font-medium flex-shrink-0 border {{ $sc['bg'] }} {{ $sc['text'] }} {{ $sc['border'] }}">
                                        <i class="fas fa-{{ $sc['icon'] }} mr-1.5 text-[11px]"></i>{{ $event->status }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="flex flex-col items-center justify-center py-16 text-center" style="height:320px;">
                                <i class="fas fa-calendar-days text-4xl text-gray-200 block mb-3"></i>
                                <p class="text-base font-medium text-gray-400">No events posted yet</p>
                                <a href="{{ route('organizer.event/organizer') }}"
                                   class="text-sm font-semibold hover:underline mt-2 inline-block" style="color:#7a3f91;">
                                    Create your first event →
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Recent Job Postings --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3" style="background:#f0f5ff;">
                        <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-briefcase text-blue-500 text-sm"></i>
                        </div>
                        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Recent Job Posts</h2>
                    </div>
                    <div class="divide-y divide-gray-100 overflow-y-auto" style="min-height:320px;max-height:320px;">
                        @forelse($this->recentJobs as $job)
                            @php
                                $dl        = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                                $isExpired = now('Asia/Manila')->gt($dl);
                            @endphp
                            <a href="{{ route('organizer.job/management') }}"
                               class="px-6 py-4 hover:bg-blue-50/40 transition-colors block">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-base font-semibold text-gray-800 truncate">{{ $job->job_title }}</p>
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 mt-1">
                                            <span class="text-sm text-gray-500 flex items-center gap-1">
                                                <i class="fas fa-building text-gray-400 text-[11px]"></i>
                                                {{ $job->company_name }}
                                            </span>
                                            <span class="text-gray-300 text-sm select-none">·</span>
                                            <span class="text-sm font-medium text-blue-600">{{ $job->employment_type }}</span>
                                            <span class="text-gray-300 text-sm select-none">·</span>
                                            <span class="text-sm {{ $isExpired ? 'text-red-500' : 'text-gray-500' }}">
                                                <i class="fas fa-calendar-xmark text-[11px] mr-0.5"></i>
                                                {{ $dl->format('M d, Y') }}
                                                @if($isExpired)<span class="font-semibold"> · Expired</span>@endif
                                            </span>
                                        </div>
                                    </div>
                                    @if($job->status === 'ACTIVE')
                                        <span class="px-3 py-1 rounded-full text-sm font-medium flex-shrink-0 bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <i class="fas fa-circle text-[8px] mr-1.5"></i>ACTIVE
                                        </span>
                                    @else
                                        <span class="px-3 py-1 rounded-full text-sm font-medium flex-shrink-0 bg-amber-50 text-amber-700 border border-amber-200">
                                            <i class="fas fa-circle-xmark text-[11px] mr-1.5"></i>INACTIVE
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="flex flex-col items-center justify-center py-16 text-center" style="height:320px;">
                                <i class="fas fa-briefcase text-4xl text-gray-200 block mb-3"></i>
                                <p class="text-base font-medium text-gray-400">No job postings yet</p>
                                <a href="{{ route('organizer.job/management') }}"
                                   class="text-sm font-semibold hover:underline mt-2 inline-block" style="color:#7a3f91;">
                                    Create your first job posting →
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>

            </div>

            {{-- RIGHT: quick actions + account (1/3) --}}
            <div class="flex flex-col gap-6">

                {{-- Quick Actions --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-4 flex items-center gap-2">
                        <i class="fas fa-bolt" style="color:#7a3f91;"></i> Quick Actions
                    </h2>
                    <div class="flex flex-col gap-3">
                        <a href="{{ route('organizer.event/organizer') }}"
                           class="flex items-center gap-3 px-4 py-3.5 rounded-xl border transition-all group"
                           style="border-color:rgba(122,63,145,.22);background:rgba(122,63,145,.05);"
                           onmouseover="this.style.background='rgba(122,63,145,.10)'"
                           onmouseout="this.style.background='rgba(122,63,145,.05)'">
                            <i class="fas fa-calendar-plus text-lg flex-shrink-0" style="color:#7a3f91;"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-base font-semibold" style="color:#7a3f91;">Post Event</p>
                                <p class="text-sm text-gray-500">Create and submit event</p>
                            </div>
                            <i class="fas fa-arrow-right text-sm group-hover:translate-x-1 transition-transform"
                               style="color:rgba(122,63,145,.35);"></i>
                        </a>
                        <a href="{{ route('organizer.job/management') }}"
                           class="flex items-center gap-3 px-4 py-3.5 rounded-xl border border-blue-200 bg-blue-50 hover:bg-blue-100 transition-colors group">
                            <i class="fas fa-briefcase text-blue-600 text-lg flex-shrink-0"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-base font-semibold text-blue-700">Post Job</p>
                                <p class="text-sm text-gray-500">Create job listing</p>
                            </div>
                            <i class="fas fa-arrow-right text-blue-400 text-sm group-hover:translate-x-1 transition-transform"></i>
                        </a>
                    </div>
                </div>

                {{-- Account Info --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-4 flex items-center gap-2">
                        <i class="fas fa-user-circle" style="color:#7a3f91;"></i> Account
                    </h2>
                    <div class="divide-y divide-gray-100">

                        <div class="flex items-center justify-between py-3">
                            <span class="text-sm text-gray-600 shrink-0">Name</span>
                            <span class="text-sm font-medium text-gray-800 text-right ml-2">{{ $this->organizerName }}</span>
                        </div>

                        <div class="flex items-center justify-between py-3">
                            <span class="text-sm text-gray-600 shrink-0">Teacher ID</span>
                            <span class="text-sm font-semibold font-mono text-right ml-2" style="color:#7a3f91;">
                                {{ $this->organizerTeacherId }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-3">
                            <span class="text-sm text-gray-600 shrink-0">Email</span>
                            <span class="text-sm text-gray-700 truncate ml-2 max-w-[160px] text-right">
                                {{ $this->organizerEmail }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between py-3">
                            <span class="text-sm text-gray-600 shrink-0">College</span>
                            <span class="text-sm font-medium text-gray-800 text-right ml-2">{{ $this->organizerDepartment }}</span>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>