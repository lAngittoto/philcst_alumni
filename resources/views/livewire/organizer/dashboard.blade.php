<?php
/**
 * FILE: resources/views/livewire/organizer/dashboard.blade.php
 * 
 * ORGANIZER DASHBOARD
 * Professional, modern dashboard showing key metrics and quick navigation
 * 
 * FEATURES:
 * ✓ Responsive design (mobile, tablet, desktop)
 * ✓ Security checks (auth, organizer verification)
 * ✓ Purple brand color (#7a3f91) with proper color coding
 * ✓ Key metrics cards with real-time counts
 * ✓ Quick action buttons to navigate sections
 * ✓ Recent activity overview
 * ✓ Greeting based on time of day
 * ✓ Professional UI with Tailwind + Alpine
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\OrganizerEvent;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Auth;

new class extends Component {

    public function mount(): void
    {
        // Security: Verify user is authenticated and is an organizer
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

    // ── METRICS CARDS ──
    
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
};
?>

<div class="min-h-screen bg-gray-50">

    {{-- ══ HEADER / WELCOME SECTION ══ --}}
    <div class="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-40">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 px-4 sm:px-6 lg:px-8 py-6 max-w-screen-2xl mx-auto w-full">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-[#7a3f91] flex items-center justify-center shadow-lg flex-shrink-0" 
                     style="box-shadow: 0 4px 14px rgba(122, 63, 145, 0.35);">
                    <i class="fas fa-gauge-high text-white text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">
                        {{ $this->greeting }}, {{ $this->organizerName }}
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="fas fa-building-columns text-[#7a3f91] mr-1.5"></i>
                        {{ $this->organizerDepartment }}
                    </p>
                </div>
            </div>
            <a href="{{ route('logout') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition sm:whitespace-nowrap">
                <i class="fas fa-sign-out-alt text-xs"></i> Logout
            </a>
        </div>
    </div>

    {{-- ══ MAIN CONTENT ══ --}}
    <div class="px-4 sm:px-6 lg:px-8 py-8 max-w-screen-2xl mx-auto">

        {{-- ══ METRICS GRID ══ --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

            {{-- Events Card --}}
            <a href="{{ route('organizer.event/organizer') }}" class="group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-purple-200 transition-all p-6 cursor-pointer">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center group-hover:bg-purple-200 transition">
                        <i class="fas fa-calendar-days text-[#7a3f91] text-lg"></i>
                    </div>
                    <span class="px-3 py-1 bg-purple-50 text-purple-700 rounded-full text-xs font-bold">
                        {{ $this->totalEvents }}
                    </span>
                </div>
                <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wide mb-1">Total Events</h3>
                <p class="text-3xl font-extrabold text-gray-900 mb-3">{{ $this->totalEvents }}</p>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-hourglass-end text-yellow-500"></i>
                        {{ $this->pendingEvents }} Pending
                    </span>
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-circle-check text-emerald-500"></i>
                        {{ $this->approvedEvents }} Approved
                    </span>
                </div>
            </a>

            {{-- Pending Events Card --}}
            <a href="{{ route('organizer.event/organizer') }}" class="group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-yellow-200 transition-all p-6 cursor-pointer">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center group-hover:bg-yellow-200 transition">
                        <i class="fas fa-hourglass-end text-yellow-600 text-lg"></i>
                    </div>
                    <span class="px-3 py-1 bg-yellow-50 text-yellow-700 rounded-full text-xs font-bold">
                        Review
                    </span>
                </div>
                <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wide mb-1">Pending Review</h3>
                <p class="text-3xl font-extrabold text-yellow-700 mb-3">{{ $this->pendingEvents }}</p>
                <p class="text-xs text-gray-500"><i class="fas fa-info-circle mr-1.5"></i>Awaiting admin approval</p>
            </a>

            {{-- Job Postings Card --}}
            <a href="{{ route('organizer.job/management') }}" class="group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-blue-200 transition-all p-6 cursor-pointer">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition">
                        <i class="fas fa-briefcase text-blue-600 text-lg"></i>
                    </div>
                    <span class="px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-bold">
                        {{ $this->totalJobs }}
                    </span>
                </div>
                <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wide mb-1">Job Postings</h3>
                <p class="text-3xl font-extrabold text-gray-900 mb-3">{{ $this->totalJobs }}</p>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-circle text-emerald-500"></i>
                        {{ $this->activeJobs }} Active
                    </span>
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-circle text-gray-400"></i>
                        {{ $this->inactiveJobs }} Inactive
                    </span>
                </div>
            </a>

            {{-- Active Jobs Card --}}
            <a href="{{ route('organizer.job/management') }}" class="group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-emerald-200 transition-all p-6 cursor-pointer">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center group-hover:bg-emerald-200 transition">
                        <i class="fas fa-circle text-emerald-600 text-lg"></i>
                    </div>
                    <span class="px-3 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold">
                        Live
                    </span>
                </div>
                <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wide mb-1">Jobs Live</h3>
                <p class="text-3xl font-extrabold text-emerald-700 mb-3">{{ $this->activeJobs }}</p>
                <p class="text-xs text-gray-500"><i class="fas fa-check-circle mr-1.5"></i>Currently visible to alumni</p>
            </a>
        </div>

        {{-- ══ TWO-COLUMN LAYOUT ══ --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- LEFT: RECENT ACTIVITY --}}
            <div class="lg:col-span-2">

                {{-- Recent Events --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-6">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center">
                                <i class="fas fa-calendar-days text-[#7a3f91] text-sm"></i>
                            </div>
                            <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Recent Events</h2>
                        </div>
                        <a href="{{ route('organizer.event/organizer') }}" class="text-xs font-bold text-[#7a3f91] hover:underline">View All →</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse($this->recentEvents as $event)
                            @php
                                $statusColor = match($event->status) {
                                    'PENDING' => 'yellow',
                                    'APPROVED' => 'emerald',
                                    'REJECTED' => 'red',
                                    default => 'gray'
                                };
                                $statusIcon = match($event->status) {
                                    'PENDING' => 'hourglass-end',
                                    'APPROVED' => 'circle-check',
                                    'REJECTED' => 'circle-xmark',
                                    default => 'circle'
                                };
                            @endphp
                            <a href="{{ route('organizer.event/organizer') }}" class="px-6 py-4 hover:bg-gray-50 transition block">
                                <div class="flex items-start justify-between gap-4 mb-2">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $event->title }}</h3>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <i class="fas fa-calendar text-gray-400 mr-1.5"></i>
                                            {{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                                        </p>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold flex-shrink-0 whitespace-nowrap"
                                          :class="'bg-{{ $statusColor }}-50 text-{{ $statusColor }}-700 border border-{{ $statusColor }}-200'">
                                        <i class="fas fa-{{ $statusIcon }} mr-1"></i>
                                        {{ $event->status }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="px-6 py-8 text-center text-gray-400">
                                <i class="fas fa-calendar-days text-2xl text-gray-300 block mb-2"></i>
                                <p class="text-sm">No events posted yet</p>
                                <a href="{{ route('organizer.event/organizer') }}" class="text-xs font-bold text-[#7a3f91] hover:underline mt-2 inline-block">Create your first event →</a>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Recent Job Postings --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-briefcase text-blue-600 text-sm"></i>
                            </div>
                            <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide">Recent Job Posts</h2>
                        </div>
                        <a href="{{ route('organizer.job/management') }}" class="text-xs font-bold text-[#7a3f91] hover:underline">View All →</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse($this->recentJobs as $job)
                            <a href="{{ route('organizer.job/management') }}" class="px-6 py-4 hover:bg-gray-50 transition block">
                                <div class="flex items-start justify-between gap-4 mb-2">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $job->job_title }}</h3>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <i class="fas fa-building text-gray-400 mr-1.5"></i>
                                            {{ $job->company_name }}
                                        </p>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold flex-shrink-0 whitespace-nowrap"
                                          :class="'{{ $job->status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}'">
                                        <i class="fas {{ $job->status === 'ACTIVE' ? 'fa-circle' : 'fa-circle-xmark' }} mr-1"></i>
                                        {{ $job->status }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="px-6 py-8 text-center text-gray-400">
                                <i class="fas fa-briefcase text-2xl text-gray-300 block mb-2"></i>
                                <p class="text-sm">No job postings yet</p>
                                <a href="{{ route('organizer.job/management') }}" class="text-xs font-bold text-[#7a3f91] hover:underline mt-2 inline-block">Create your first job posting →</a>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- RIGHT: QUICK ACTIONS + HELP --}}
            <div class="space-y-6">

                {{-- Quick Actions --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-4 flex items-center gap-2">
                        <i class="fas fa-lightning-bolt text-[#7a3f91]"></i> Quick Actions
                    </h2>
                    <div class="space-y-3">
                        <a href="{{ route('organizer.event/organizer') }}" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-purple-200 bg-purple-50 hover:bg-purple-100 transition group">
                            <i class="fas fa-calendar-plus text-[#7a3f91] text-lg"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-[#7a3f91]">Post Event</p>
                                <p class="text-xs text-purple-600">Create and submit event</p>
                            </div>
                            <i class="fas fa-arrow-right text-purple-400 group-hover:translate-x-1 transition"></i>
                        </a>
                        <a href="{{ route('organizer.job/management') }}" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-blue-200 bg-blue-50 hover:bg-blue-100 transition group">
                            <i class="fas fa-briefcase text-blue-600 text-lg"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-blue-600">Post Job</p>
                                <p class="text-xs text-blue-600">Create job listing</p>
                            </div>
                            <i class="fas fa-arrow-right text-blue-400 group-hover:translate-x-1 transition"></i>
                        </a>
                        <a href="{{ route('organizer.employment') }}" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 transition group">
                            <i class="fas fa-handshake text-emerald-600 text-lg"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-emerald-600">Employment</p>
                                <p class="text-xs text-emerald-600">View employment info</p>
                            </div>
                            <i class="fas fa-arrow-right text-emerald-400 group-hover:translate-x-1 transition"></i>
                        </a>
                    </div>
                </div>

                {{-- Help & Support --}}
                <div class="bg-gradient-to-br from-[#7a3f91] to-[#5e2f72] rounded-2xl shadow-md p-6 text-white">
                    <div class="flex items-start gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-question-circle text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold">Need Help?</h3>
                            <p class="text-xs text-white/70 mt-1">Have questions about posting events or jobs?</p>
                        </div>
                    </div>
                    <button class="w-full px-4 py-2.5 bg-white text-[#7a3f91] rounded-lg font-bold text-sm hover:bg-gray-50 transition">
                        <i class="fas fa-envelope mr-2"></i> Contact Support
                    </button>
                </div>

                {{-- Account Info --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-4 flex items-center gap-2">
                        <i class="fas fa-user-circle text-[#7a3f91]"></i> Account
                    </h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                            <span class="text-gray-600">Name</span>
                            <span class="font-semibold text-gray-900">{{ $this->organizerName }}</span>
                        </div>
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                            <span class="text-gray-600">Email</span>
                            <span class="font-semibold text-gray-900 text-xs truncate">{{ $this->organizerEmail }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">College</span>
                            <span class="font-semibold text-gray-900">{{ $this->organizerDepartment }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>