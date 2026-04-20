<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    // ── Batch chart pagination ────────────────────────────────────
    public int $batchPage     = 0;   // current page (0-based)
    public int $batchPageSize = 8;   // bars per page

    // ─────────────────────────────────────────────────────────────
    // Computed — Stats
    // ─────────────────────────────────────────────────────────────

    #[Computed]
    public function totalAlumni(): int
    {
        return Alumni::count();
    }

    #[Computed]
    public function profileComplete(): int
    {
        return Alumni::where('profile_completed', 1)->count();
    }

    #[Computed]
    public function profileIncomplete(): int
    {
        return Alumni::where('profile_completed', 0)->count();
    }

    #[Computed]
    public function totalCourses(): int
    {
        return Course::count();
    }

    #[Computed]
    public function newThisMonth(): int
    {
        return Alumni::whereMonth('created_at', now()->month)
                     ->whereYear('created_at', now()->year)
                     ->count();
    }

    #[Computed]
    public function latestBatch(): ?int
    {
        return Alumni::max('batch');
    }

    #[Computed]
    public function completionRate(): int
    {
        $total = $this->totalAlumni;
        return $total === 0 ? 0 : (int) round(($this->profileComplete / $total) * 100);
    }

    // ── Top 5 courses by alumni count ─────────────────────────────
    #[Computed]
    public function courseDistribution()
    {
        return DB::table('alumni')
            ->select('course_code', DB::raw('COUNT(*) as total'))
            ->groupBy('course_code')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    // ── ALL batch years ordered descending ────────────────────────
    #[Computed]
    public function allBatches()
    {
        return DB::table('alumni')
            ->select('batch', DB::raw('COUNT(*) as total'))
            ->groupBy('batch')
            ->orderByDesc('batch')
            ->get();
    }

    // ── Slice for current page ────────────────────────────────────
    #[Computed]
    public function batchPageData()
    {
        return $this->allBatches->slice(
            $this->batchPage * $this->batchPageSize,
            $this->batchPageSize
        )->values();
    }

    #[Computed]
    public function batchTotalPages(): int
    {
        $count = $this->allBatches->count();
        if ($count === 0) return 1;
        return (int) ceil($count / $this->batchPageSize);
    }

    // ── Recent registrations ──────────────────────────────────────
    #[Computed]
    public function recentAlumni()
    {
        return Alumni::select([
            'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'batch', 'profile_photo',
            'profile_completed', 'created_at',
        ])
        ->orderByDesc('created_at')
        ->limit(8)
        ->get();
    }

    // ─────────────────────────────────────────────────────────────
    // Batch chart navigation
    // ─────────────────────────────────────────────────────────────

    public function batchPrev(): void
    {
        if ($this->batchPage > 0) $this->batchPage--;
    }

    public function batchNext(): void
    {
        if ($this->batchPage < $this->batchTotalPages - 1) $this->batchPage++;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }

    public function formatDisplayName(string $f, string $m, string $l, string $s): string
    {
        $parts = [trim($f)];
        if (trim($m) !== '') $parts[] = ucfirst(strtolower(substr(trim($m), 0, 1))) . '.';
        $parts[] = trim($l);
        if (trim($s) !== '') $parts[] = trim($s);
        return implode(' ', array_filter($parts));
    }
};
?>

{{-- ══════════════════════════════════════════════════════════════════
     REGISTRAR DASHBOARD
     ══════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-8 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ── PAGE HEADER ──────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-4xl font-extrabold text-[#2b0d3e] leading-tight">Registrar Dashboard</h1>
            <p class="text-gray-500 text-sm sm:text-base">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    {{-- ── STAT CARDS ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Total Alumni --}}
        <div class="bg-white rounded-2xl border border-purple-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07]" style="background:#7a3f91;"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                    <i class="fas fa-users text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-50 text-purple-600 border border-purple-100 uppercase">Total</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ number_format($this->totalAlumni) }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Alumni Records</p>
            @if($this->newThisMonth > 0)
                <p class="text-xs text-emerald-600 font-bold mt-2 flex items-center gap-1">
                    <i class="fas fa-arrow-trend-up text-sm"></i> +{{ $this->newThisMonth }} this month
                </p>
            @endif
        </div>

        {{-- Profile Complete --}}
        <div class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07] bg-emerald-500"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                    <i class="fas fa-circle-check text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase">Complete</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ number_format($this->profileComplete) }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Profiles Filled</p>
            <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full" style="width:{{ $this->completionRate }}%;"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1">{{ $this->completionRate }}% completion rate</p>
        </div>

        {{-- Profile Incomplete --}}
        <div class="bg-white rounded-2xl border border-amber-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07] bg-amber-400"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                    <i class="fas fa-triangle-exclamation text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Pending</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ number_format($this->profileIncomplete) }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Incomplete Profiles</p>
            @if($this->totalAlumni > 0)
                <p class="text-xs text-amber-600 font-bold mt-2">
                    {{ round(($this->profileIncomplete / $this->totalAlumni) * 100) }}% still need info
                </p>
            @endif
        </div>

        {{-- Total Courses --}}
        <div class="bg-white rounded-2xl border border-blue-100 shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="absolute -right-5 -top-5 w-24 h-24 rounded-full opacity-[0.07] bg-blue-500"></div>
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                    <i class="fas fa-book-open text-white text-base"></i>
                </div>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Courses</span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 leading-none">{{ number_format($this->totalCourses) }}</p>
            <p class="text-sm text-gray-500 mt-1 font-medium">Active Courses</p>
            @if($this->latestBatch)
                <p class="text-xs text-blue-600 font-bold mt-2 flex items-center gap-1">
                    <i class="fas fa-calendar-check text-sm"></i> Latest batch: {{ $this->latestBatch }}
                </p>
            @endif
        </div>

    </div>

    {{-- ── MAIN GRID: Recent (left) + Course Distribution (right) ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

        {{-- ── Recent Registrations ──────────────────────────────── --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-purple-100 shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
                 style="background:linear-gradient(135deg,#f9f5ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <i class="fas fa-clock-rotate-left text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Recent Registrations</p>
                </div>
                <a href="{{ route('registrar.alumni') }}" wire:navigate
                   class="text-xs font-bold text-[#7a3f91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="divide-y divide-gray-50">
                @forelse($this->recentAlumni as $index => $alumni)
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#faf7ff] transition-colors">
                    <span class="w-5 text-center text-xs font-extrabold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                         alt="{{ $alumni->first_name }}"
                         class="w-9 h-9 rounded-xl object-cover ring-1 ring-gray-200 shrink-0">
                    <div class="flex-1 min-w-0">
                        <p class="text-base font-bold text-gray-900 truncate uppercase">
                            {{ $this->formatDisplayName(
                                $alumni->first_name      ?? '',
                                $alumni->middle_initial  ?? '',
                                $alumni->last_name       ?? '',
                                $alumni->suffix          ?? ''
                            ) }}
                        </p>
                        <p class="text-xs text-gray-400 font-mono mt-0.5">
                            {{ $alumni->student_id }} &bull; {{ $alumni->course_code }} &bull; {{ $alumni->batch }}
                        </p>
                    </div>
                    <div class="shrink-0 flex flex-col items-end gap-1">
                        @if($alumni->profile_completed)
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">Complete</span>
                        @else
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 uppercase">Pending</span>
                        @endif
                        <span class="text-xs text-gray-400">{{ $alumni->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                @empty
                <div class="py-16 text-center">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f0e6f8;">
                        <i class="fas fa-users text-4xl" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-base font-bold text-gray-400">No alumni records yet</p>
                    <p class="text-sm text-gray-300 mt-1">Start by registering or importing alumni</p>
                </div>
                @endforelse
            </div>

        </div>

        {{-- ── Top 5 Courses by Alumni Count ────────────────────── --}}
        <div class="bg-white rounded-2xl border border-purple-100 shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-gray-100" style="background:linear-gradient(135deg,#f9f5ff,#ffffff);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <i class="fas fa-ranking-star text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Top 5 Courses</p>
                </div>
            </div>

            @php
                $topCourses  = $this->courseDistribution;
                $maxCourse   = $topCourses->max('total') ?: 1;
                $rankColors  = [
                    ['#7a3f91','#f0e6f8','#c89de0'],
                    ['#9b59b6','#f5eef9','#d2a8e8'],
                    ['#3b82f6','#eff6ff','#93c5fd'],
                    ['#10b981','#ecfdf5','#6ee7b7'],
                    ['#f59e0b','#fffbeb','#fcd34d'],
                ];
                $medals = ['🥇','🥈','🥉','4th','5th'];
            @endphp

            <div class="p-4 space-y-3">
                @forelse($topCourses as $i => $row)
                @php [$main, $light, $mid] = $rankColors[$i % count($rankColors)]; @endphp
                <div>
                    {{-- Rank + course label + count --}}
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-base leading-none">{{ $medals[$i] ?? ($i+1).'th' }}</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full"
                                  style="background:{{ $light }};color:{{ $main }};border:1px solid {{ $mid }};">
                                {{ $row->course_code }}
                            </span>
                        </div>
                        <span class="text-sm font-extrabold text-gray-800">
                            {{ number_format($row->total) }}
                            <span class="text-xs font-semibold text-gray-400 ml-0.5">alumni</span>
                        </span>
                    </div>
                    {{-- Progress bar --}}
                    <div class="h-2.5 rounded-full overflow-hidden" style="background:{{ $light }};">
                        <div class="h-full rounded-full transition-all duration-500"
                             style="width:{{ round(($row->total / $maxCourse) * 100) }}%; background:{{ $main }};"></div>
                    </div>
                    {{-- Percentage text --}}
                    <p class="text-xs text-gray-400 mt-0.5 text-right">
                        {{ round(($row->total / $maxCourse) * 100) }}% of top
                    </p>
                </div>
                @empty
                <p class="text-sm text-gray-400 text-center py-8">No course data available</p>
                @endforelse
            </div>

        </div>

    </div>

    {{-- ── ALUMNI BY BATCH YEAR — Paginated Bar Chart ───────────── --}}
    @php
        $allBatches    = $this->allBatches;
        $totalPages    = $this->batchTotalPages;
        $pageData      = $this->batchPageData;
        $globalMax     = (int) $allBatches->max('total') ?: 1;
        $chartH        = 150; // px — usable bar area
        $hasPrev       = $this->batchPage > 0;
        $hasNext       = $this->batchPage < $totalPages - 1;
        $pageStart     = $this->batchPage * $this->batchPageSize + 1;
        $pageEnd       = min($pageStart + $this->batchPageSize - 1, $allBatches->count());
    @endphp

    @if($allBatches->count() > 0)
    <div class="bg-white rounded-2xl border border-purple-100 shadow-sm overflow-hidden">

        {{-- Card Header --}}
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between"
             style="background:linear-gradient(135deg,#f9f5ff,#ffffff);">
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                    <i class="fas fa-chart-bar text-white" style="font-size:12px;"></i>
                </div>
                <p class="text-sm font-bold text-gray-700 uppercase tracking-wide">Alumni by Batch Year</p>
            </div>
            <div class="flex items-center gap-3">
                {{-- Page info --}}
                <span class="text-xs text-gray-400">
                    Showing <strong class="text-gray-600">{{ $pageStart }}–{{ $pageEnd }}</strong>
                    of <strong class="text-gray-600">{{ $allBatches->count() }}</strong> batches
                </span>
                {{-- Nav arrows --}}
                <div class="flex items-center gap-1">
                    <button wire:click="batchPrev"
                            @if(!$hasPrev) disabled @endif
                            class="w-7 h-7 rounded-lg flex items-center justify-center transition font-bold text-sm
                                   {{ $hasPrev
                                       ? 'bg-white border border-purple-200 text-[#7a3f91] hover:bg-purple-50 active:scale-95'
                                       : 'bg-gray-100 border border-gray-200 text-gray-300 cursor-not-allowed' }}">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <span class="text-xs font-bold text-gray-500 px-1">
                        {{ $this->batchPage + 1 }} / {{ $totalPages }}
                    </span>
                    <button wire:click="batchNext"
                            @if(!$hasNext) disabled @endif
                            class="w-7 h-7 rounded-lg flex items-center justify-center transition font-bold text-sm
                                   {{ $hasNext
                                       ? 'bg-white border border-purple-200 text-[#7a3f91] hover:bg-purple-50 active:scale-95'
                                       : 'bg-gray-100 border border-gray-200 text-gray-300 cursor-not-allowed' }}">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- Chart Body --}}
        <div class="px-6 pt-5 pb-4">

            <div class="relative" style="height:{{ $chartH + 56 }}px;">

                {{-- Horizontal grid lines --}}
                @foreach([100, 66, 33] as $pct)
                @php $topPx = (int) round((1 - $pct / 100) * $chartH); @endphp
                <div class="absolute left-0 right-0 flex items-center gap-2"
                     style="top:{{ $topPx }}px;">
                    <span class="text-xs text-gray-300 font-mono w-10 text-right shrink-0">
                        {{ number_format((int) round($globalMax * $pct / 100)) }}
                    </span>
                    <div class="flex-1 border-t border-dashed border-gray-100"></div>
                </div>
                @endforeach

                {{-- Bars --}}
                <div class="absolute bottom-8 left-12 right-0 flex items-end gap-2 sm:gap-3"
                     style="height:{{ $chartH }}px;">

                    @foreach($pageData as $row)
                    @php
                        $barPx = max((int) round(($row->total / $globalMax) * $chartH), 12);
                        $isTop = (int)$row->total === $globalMax;
                    @endphp
                    <div class="flex-1 flex flex-col items-center justify-end group cursor-default"
                         style="height:{{ $chartH }}px;">

                        {{-- Count label above bar --}}
                        <span class="block text-center text-sm font-extrabold mb-1.5 leading-none"
                              style="color:{{ $isTop ? '#7a3f91' : '#a07bbf' }};">
                            {{ number_format($row->total) }}
                        </span>

                        {{-- Bar --}}
                        <div class="w-full rounded-t-lg relative overflow-hidden"
                             style="height:{{ $barPx }}px;
                                    background:{{ $isTop
                                        ? 'linear-gradient(180deg,#7a3f91 0%,#9b59b6 100%)'
                                        : 'linear-gradient(180deg,#c8a0e0 0%,#dbbcef 100%)' }};">
                            <div class="absolute top-0 left-0 right-0 h-1 opacity-25 rounded-t-lg" style="background:white;"></div>
                            {{-- Tooltip --}}
                            <div class="absolute -top-9 left-1/2 -translate-x-1/2 whitespace-nowrap z-20
                                        bg-gray-900 text-white text-xs font-bold px-2 py-1 rounded-lg shadow-lg
                                        opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                                Batch {{ $row->batch }}: {{ number_format($row->total) }} alumni
                                <div class="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0"
                                     style="border-left:4px solid transparent;border-right:4px solid transparent;border-top:4px solid #111827;"></div>
                            </div>
                        </div>

                    </div>
                    @endforeach

                </div>

                {{-- Year labels --}}
                <div class="absolute bottom-0 left-12 right-0 flex gap-2 sm:gap-3">
                    @foreach($pageData as $row)
                    <div class="flex-1 text-center">
                        <span class="text-sm font-bold text-gray-500">{{ $row->batch }}</span>
                    </div>
                    @endforeach
                </div>

            </div>

            {{-- Baseline --}}
            <div class="ml-12 h-px bg-gray-200 rounded-full -mt-1 mb-3"></div>

            {{-- Legend + Summary --}}
            <div class="ml-12 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#7a3f91,#9b59b6);"></div>
                        <span class="text-xs text-gray-500 font-semibold">Highest batch</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#c8a0e0,#dbbcef);"></div>
                        <span class="text-xs text-gray-500 font-semibold">Other batches</span>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-xs text-gray-400">
                        Peak: <strong class="text-gray-600">{{ number_format($globalMax) }}</strong> alumni
                    </span>
                    <span class="text-xs text-gray-400">
                        All batches total: <strong class="text-gray-600">{{ number_format($allBatches->sum('total')) }}</strong>
                    </span>
                </div>
            </div>

        </div>
    </div>
    @endif

</div>