<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    // ── Batch chart pagination ────────────────────────────────────
    public int $batchPage     = 0;
    public int $batchPageSize = 8;

    // ── Employment modal ─────────────────────────────────────────
    public string $activeModal       = '';
    public string $empFilter         = '';   // 'employed' | 'self_employed' | 'unemployed' | ''
    public int    $empModalPage      = 1;
    public int    $empModalPageSize  = 10;

    // ─────────────────────────────────────────────────────────────
    // Computed — Stats
    // ─────────────────────────────────────────────────────────────

    #[Computed]
    public function totalAlumni(): int { return Alumni::count(); }

    #[Computed]
    public function profileComplete(): int { return Alumni::where('profile_completed', 1)->count(); }

    #[Computed]
    public function profileIncomplete(): int { return Alumni::where('profile_completed', 0)->count(); }

    #[Computed]
    public function totalCourses(): int { return Course::count(); }

    #[Computed]
    public function newThisMonth(): int
    {
        return Alumni::whereMonth('created_at', now()->month)
                     ->whereYear('created_at', now()->year)
                     ->count();
    }

    #[Computed]
    public function latestBatch(): ?int { return Alumni::max('batch'); }

    #[Computed]
    public function completionRate(): int
    {
        $total = $this->totalAlumni;
        return $total === 0 ? 0 : (int) round(($this->profileComplete / $total) * 100);
    }

    // ── Employment counts ─────────────────────────────────────────
    #[Computed]
    public function empCounts()
    {
        $rows = DB::table('employment_trackings')
            ->whereNull('deleted_at')
            ->select('employment_status', DB::raw('COUNT(*) as total'))
            ->groupBy('employment_status')
            ->get()
            ->keyBy('employment_status');

        $employed     = (int) ($rows['employed']->total      ?? 0);
        $self         = (int) ($rows['self_employed']->total ?? 0);
        $unemployed   = (int) ($rows['unemployed']->total    ?? 0);
        $submitted    = $employed + $self + $unemployed;
        $noRecord     = max($this->totalAlumni - $submitted, 0);

        return compact('employed', 'self', 'unemployed', 'submitted', 'noRecord');
    }

    // ── Employment modal paginated list ───────────────────────────
    #[Computed]
    public function empModalRecords()
    {
        $q = DB::table('employment_trackings as e')
            ->join('alumni as a', 'a.id', '=', 'e.alumni_id')
            ->whereNull('e.deleted_at')
            ->select([
                'a.id as alumni_id',
                'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                'a.student_id', 'a.course_code', 'a.batch', 'a.profile_photo',
                'e.employment_status', 'e.job_title', 'e.company_name',
                'e.employment_type', 'e.work_location', 'e.education_status',
                'e.created_at as emp_created_at',
            ])
            ->when($this->empFilter !== '', fn($q) => $q->where('e.employment_status', $this->empFilter))
            ->orderByDesc('e.created_at');

        return $q->paginate($this->empModalPageSize, ['*'], 'empPage', $this->empModalPage);
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
        return $count === 0 ? 1 : (int) ceil($count / $this->batchPageSize);
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
    // Actions
    // ─────────────────────────────────────────────────────────────

    public function batchPrev(): void { if ($this->batchPage > 0) $this->batchPage--; }
    public function batchNext(): void { if ($this->batchPage < $this->batchTotalPages - 1) $this->batchPage++; }

    public function openEmpModal(string $filter = ''): void
    {
        $this->empFilter    = $filter;
        $this->empModalPage = 1;
        $this->activeModal  = 'employment';
    }

    public function closeModal(): void { $this->activeModal = ''; }

    public function updatingEmpFilter(): void { $this->empModalPage = 1; }

    public function empPrevPage(): void { if ($this->empModalPage > 1) $this->empModalPage--; }
    public function empNextPage(): void
    {
        if ($this->empModalPage < $this->empModalRecords->lastPage())
            $this->empModalPage++;
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

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-8 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- ── PAGE HEADER ──────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-3xl font-semibold text-[#333333] leading-tight">Registrar Portal</h1>
            <p class="text-xl text-[#666666] font-normal">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    {{-- ── STAT CARDS ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Total Alumni --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-users text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">Total</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalAlumni) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Alumni Records</p>
            @if($this->newThisMonth > 0)
                <p class="text-xs text-emerald-600 font-semibold mt-2 flex items-center gap-1">
                    <i class="fas fa-arrow-trend-up text-sm"></i> +{{ $this->newThisMonth }} this month
                </p>
            @endif
        </div>

        {{-- Profile Complete --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                    <i class="fas fa-circle-check text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase">Complete</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->profileComplete) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Profiles Filled</p>
            <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full" style="width:{{ $this->completionRate }}%;"></div>
            </div>
            <p class="text-xs text-[#999999] mt-1 font-normal">{{ $this->completionRate }}% completion rate</p>
        </div>

        {{-- Profile Incomplete --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                    <i class="fas fa-triangle-exclamation text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Pending</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->profileIncomplete) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Incomplete Profiles</p>
            @if($this->totalAlumni > 0)
                <p class="text-xs text-amber-600 font-semibold mt-2">
                    {{ round(($this->profileIncomplete / $this->totalAlumni) * 100) }}% still need info
                </p>
            @endif
        </div>

        {{-- Total Courses --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                    <i class="fas fa-book-open text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Courses</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalCourses) }}</p>
            <p class="text-xl text-[#666666] mt-1 font-normal">Active Courses</p>
            @if($this->latestBatch)
                <p class="text-xs text-blue-600 font-semibold mt-2 flex items-center gap-1">
                    <i class="fas fa-calendar-check text-sm"></i> Latest batch: {{ $this->latestBatch }}
                </p>
            @endif
        </div>

    </div>

    {{-- ── MAIN GRID: Recent (left) + Employment Overview (right) ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

        {{-- ── Recent Registrations ──────────────────────────────── --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-clock-rotate-left text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Recent Registrations</p>
                </div>
                <a href="{{ route('registrar.alumni') }}" wire:navigate
                   class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="divide-y divide-[#F5F5F5]">
                @forelse($this->recentAlumni as $index => $alumni)
                <div class="px-4 py-3 flex items-center gap-3 hover:bg-[#FAFAFA] transition-colors">
                    <span class="w-5 text-center text-xs font-semibold shrink-0" style="color:#c0a0d8;">
                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                         alt="{{ $alumni->first_name }}"
                         class="w-9 h-9 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                    <div class="flex-1 min-w-0">
                        <p class="text-xl font-semibold text-[#333333] truncate uppercase">
                            {{ $this->formatDisplayName(
                                $alumni->first_name      ?? '',
                                $alumni->middle_initial  ?? '',
                                $alumni->last_name       ?? '',
                                $alumni->suffix          ?? ''
                            ) }}
                        </p>
                        <p class="text-xs text-[#999999] font-normal mt-0.5">
                            {{ $alumni->student_id }} &bull; {{ $alumni->course_code }} &bull; {{ $alumni->batch }}
                        </p>
                    </div>
                    <div class="shrink-0 flex flex-col items-end gap-1">
                        @if($alumni->profile_completed)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">Complete</span>
                        @else
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 uppercase">Pending</span>
                        @endif
                        <span class="text-xs text-[#999999] font-normal">{{ $alumni->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                @empty
                <div class="py-16 text-center">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#f0e6f8;">
                        <i class="fas fa-users text-4xl" style="color:#c89de0;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#999999]">No alumni records yet</p>
                    <p class="text-xs text-[#CCCCCC] mt-1 font-normal">Start by registering or importing alumni</p>
                </div>
                @endforelse
            </div>

        </div>

        {{-- ── Employment Overview ────────────────────────────────── --}}
        @php
            $ec       = $this->empCounts;
            $submitted = $ec['submitted'];
            $total     = $this->totalAlumni;
            $submittedPct = $total > 0 ? round(($submitted / $total) * 100) : 0;

            $empRows = [
                [
                    'label'  => 'Employed',
                    'count'  => $ec['employed'],
                    'icon'   => 'fa-user-tie',
                    'color'  => '#7A3F91',
                    'light'  => '#F9F7FC',
                    'border' => '#E8E0F0',
                    'text'   => 'text-[#7A3F91]',
                    'filter' => 'employed',
                ],
                [
                    'label'  => 'Self-Employed',
                    'count'  => $ec['self'],
                    'icon'   => 'fa-store',
                    'color'  => '#2563eb',
                    'light'  => '#EFF6FF',
                    'border' => '#BFDBFE',
                    'text'   => 'text-blue-600',
                    'filter' => 'self_employed',
                ],
                [
                    'label'  => 'Unemployed',
                    'count'  => $ec['unemployed'],
                    'icon'   => 'fa-magnifying-glass',
                    'color'  => '#d97706',
                    'light'  => '#FFFBEB',
                    'border' => '#FCD34D',
                    'text'   => 'text-amber-600',
                    'filter' => 'unemployed',
                ],
                [
                    'label'  => 'No Record',
                    'count'  => $ec['noRecord'],
                    'icon'   => 'fa-circle-minus',
                    'color'  => '#6B7280',
                    'light'  => '#F9FAFB',
                    'border' => '#E5E7EB',
                    'text'   => 'text-gray-500',
                    'filter' => '',
                ],
            ];
        @endphp
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">

            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-briefcase text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Employment Overview</p>
                </div>
                <button wire:click="openEmpModal('')"
                        class="text-xs font-semibold text-[#7A3F91] hover:underline flex items-center gap-1">
                    View All <i class="fas fa-arrow-right text-xs"></i>
                </button>
            </div>

            <div class="p-4 flex flex-col gap-3 flex-1">

                {{-- Submitted progress --}}
                <div class="bg-[#F9F7FC] border border-[#E8E0F0] rounded-xl p-3">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide">Submitted Employment Info</p>
                        <span class="text-xs font-semibold text-[#7A3F91]">{{ $submittedPct }}%</span>
                    </div>
                    <div class="h-2 rounded-full overflow-hidden bg-white border border-[#E8E0F0]">
                        <div class="h-full rounded-full transition-all duration-500"
                             style="width:{{ $submittedPct }}%; background:linear-gradient(90deg,#7A3F91,#9b59b6);"></div>
                    </div>
                    <p class="text-xs text-[#999999] mt-1.5 font-normal">
                        <strong class="text-[#333333] font-semibold">{{ number_format($submitted) }}</strong> of
                        <strong class="text-[#333333] font-semibold">{{ number_format($total) }}</strong> alumni submitted
                    </p>
                </div>

                {{-- Breakdown rows --}}
                <div class="space-y-2">
                    @foreach($empRows as $row)
                    @php $barPct = $submitted > 0 && $row['count'] > 0 ? round(($row['count'] / max($submitted, 1)) * 100) : 0; @endphp
                    <div class="rounded-xl border p-3 cursor-pointer transition-all hover:shadow-sm"
                         style="background:{{ $row['light'] }}; border-color:{{ $row['border'] }};"
                         wire:click="{{ $row['filter'] !== '' ? 'openEmpModal(\''.$row['filter'].'\')' : 'openEmpModal(\'\')' }}">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                     style="background:{{ $row['color'] }}20; color:{{ $row['color'] }};">
                                    <i class="fas {{ $row['icon'] }} text-xs"></i>
                                </div>
                                <span class="text-sm font-semibold text-[#333333]">{{ $row['label'] }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-2xl font-semibold" style="color:{{ $row['color'] }};">
                                    {{ number_format($row['count']) }}
                                </span>
                                <i class="fas fa-chevron-right text-xs text-[#CCCCCC]"></i>
                            </div>
                        </div>
                        @if($row['count'] > 0 && $row['filter'] !== '')

                      
                        @endif
                    </div>
                    @endforeach
                </div>

            </div>
        </div>

    </div>

    {{-- ── ALUMNI BY BATCH YEAR — Paginated Bar Chart ───────────── --}}
    @php
        $allBatches = $this->allBatches;
        $totalPages = $this->batchTotalPages;
        $pageData   = $this->batchPageData;
        $globalMax  = (int) $allBatches->max('total') ?: 1;
        $chartH     = 150;
        $hasPrev    = $this->batchPage > 0;
        $hasNext    = $this->batchPage < $totalPages - 1;
        $pageStart  = $this->batchPage * $this->batchPageSize + 1;
        $pageEnd    = min($pageStart + $this->batchPageSize - 1, $allBatches->count());
    @endphp

    @if($allBatches->count() > 0)
    <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden">

        <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
             style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-chart-bar text-white" style="font-size:12px;"></i>
                </div>
                <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Alumni by Batch Year</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-[#666666] font-normal">
                    Showing <strong class="text-[#333333] font-semibold">{{ $pageStart }}–{{ $pageEnd }}</strong>
                    of <strong class="text-[#333333] font-semibold">{{ $allBatches->count() }}</strong> batches
                </span>
                <div class="flex items-center gap-1">
                    <button wire:click="batchPrev"
                            @if(!$hasPrev) disabled @endif
                            class="w-7 h-7 rounded-lg flex items-center justify-center transition font-semibold text-sm
                                   {{ $hasPrev
                                       ? 'bg-white border border-[#E8E0F0] text-[#7A3F91] hover:bg-[#F9F7FC] active:scale-95'
                                       : 'bg-[#F5F5F5] border border-[#E8E0F0] text-[#CCCCCC] cursor-not-allowed' }}">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                    <span class="text-xs font-semibold text-[#666666] px-1">
                        {{ $this->batchPage + 1 }} / {{ $totalPages }}
                    </span>
                    <button wire:click="batchNext"
                            @if(!$hasNext) disabled @endif
                            class="w-7 h-7 rounded-lg flex items-center justify-center transition font-semibold text-sm
                                   {{ $hasNext
                                       ? 'bg-white border border-[#E8E0F0] text-[#7A3F91] hover:bg-[#F9F7FC] active:scale-95'
                                       : 'bg-[#F5F5F5] border border-[#E8E0F0] text-[#CCCCCC] cursor-not-allowed' }}">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="px-6 pt-5 pb-4">
            <div class="relative" style="height:{{ $chartH + 56 }}px;">

                @foreach([100, 66, 33] as $pct)
                @php $topPx = (int) round((1 - $pct / 100) * $chartH); @endphp
                <div class="absolute left-0 right-0 flex items-center gap-2" style="top:{{ $topPx }}px;">
                    <span class="text-xs text-[#CCCCCC] font-normal w-10 text-right shrink-0">
                        {{ number_format((int) round($globalMax * $pct / 100)) }}
                    </span>
                    <div class="flex-1 border-t border-dashed border-[#E8E0F0]"></div>
                </div>
                @endforeach

                <div class="absolute bottom-8 left-12 right-0 flex items-end gap-2 sm:gap-3"
                     style="height:{{ $chartH }}px;">
                    @foreach($pageData as $row)
                    @php
                        $barPx = max((int) round(($row->total / $globalMax) * $chartH), 12);
                        $isTop = (int)$row->total === $globalMax;
                    @endphp
                    <div class="flex-1 flex flex-col items-center justify-end group cursor-default"
                         style="height:{{ $chartH }}px;">
                        <span class="block text-center text-2xl font-semibold mb-1.5 leading-none"
                              style="color:{{ $isTop ? '#7A3F91' : '#a07bbf' }};">
                            {{ number_format($row->total) }}
                        </span>
                        <div class="w-full rounded-t-lg relative overflow-hidden"
                             style="height:{{ $barPx }}px;
                                    background:{{ $isTop
                                        ? 'linear-gradient(180deg,#7A3F91 0%,#9b59b6 100%)'
                                        : 'linear-gradient(180deg,#c8a0e0 0%,#dbbcef 100%)' }};">
                            <div class="absolute top-0 left-0 right-0 h-1 opacity-25 rounded-t-lg" style="background:white;"></div>
                            <div class="absolute -top-9 left-1/2 -translate-x-1/2 whitespace-nowrap z-20
                                        bg-[#333333] text-white text-xs font-semibold px-2 py-1 rounded-lg shadow-lg
                                        opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                                Batch {{ $row->batch }}: {{ number_format($row->total) }} alumni
                                <div class="absolute left-1/2 -translate-x-1/2 top-full w-0 h-0"
                                     style="border-left:4px solid transparent;border-right:4px solid transparent;border-top:4px solid #333333;"></div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="absolute bottom-0 left-12 right-0 flex gap-2 sm:gap-3">
                    @foreach($pageData as $row)
                    <div class="flex-1 text-center">
                        <span class="text-2xl font-semibold text-[#666666]">{{ $row->batch }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="ml-12 h-px bg-[#E8E0F0] rounded-full -mt-1 mb-3"></div>
            <div class="ml-12 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);"></div>
                        <span class="text-xs text-[#666666] font-semibold">Highest batch</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#c8a0e0,#dbbcef);"></div>
                        <span class="text-xs text-[#666666] font-semibold">Other batches</span>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-xs text-[#666666] font-normal">
                        Peak: <strong class="text-[#333333] font-semibold">{{ number_format($globalMax) }}</strong> alumni
                    </span>
                    <span class="text-xs text-[#666666] font-normal">
                        All batches total: <strong class="text-[#333333] font-semibold">{{ number_format($allBatches->sum('total')) }}</strong>
                    </span>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>{{-- end page --}}


{{-- ══ EMPLOYMENT MODAL ═════════════════════════════════════════════ --}}
@if($activeModal === 'employment')
@php
    $records    = $this->empModalRecords;
    $filterMap  = [
        ''             => 'All Employment Records',
        'employed'     => 'Employed Alumni',
        'self_employed'=> 'Self-Employed Alumni',
        'unemployed'   => 'Unemployed Alumni',
    ];
    $filterTitle = $filterMap[$this->empFilter] ?? 'All Employment Records';

    $statusBadge = [
        'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]',    'fa-user-tie'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',          'fa-store'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',       'fa-magnifying-glass'],
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5"
     style="background:rgba(27,6,46,0.55); backdrop-filter:blur(4px);"
     @keydown.escape.window="$wire.closeModal()">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl flex flex-col"
         style="max-height:90vh; animation:modalIn .2s cubic-bezier(.4,0,.2,1) both;">
        <style>@keyframes modalIn{from{opacity:0;transform:scale(.97) translateY(8px)}to{opacity:1;transform:none}}</style>

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 rounded-t-2xl shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <div>
                <h2 class="text-white font-semibold text-2xl flex items-center gap-2">
                    <i class="fas fa-briefcase"></i> {{ $filterTitle }}
                </h2>
                <p class="text-white/70 text-xs mt-0.5 font-normal">
                    {{ number_format($records->total()) }} record(s) found
                </p>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Filter tabs --}}
        <div class="px-5 pt-3 pb-2 border-b border-[#E8E0F0] flex flex-wrap gap-1.5 shrink-0 bg-[#F9F7FC]">
            @foreach([
                [''             , 'All'],
                ['employed'     , 'Employed'],
                ['self_employed', 'Self-Employed'],
                ['unemployed'   , 'Unemployed'],
            ] as [$val, $lbl])
            <button wire:click="$set('empFilter','{{ $val }}')"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all
                           {{ $this->empFilter === $val
                               ? 'text-white border-transparent'
                               : 'bg-white text-[#666666] border-[#E8E0F0] hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}"
                    style="{{ $this->empFilter === $val ? 'background:linear-gradient(135deg,#7A3F91,#9b59b6);' : '' }}">
                {{ $lbl }}
            </button>
            @endforeach
        </div>

        {{-- Table --}}
        <div class="flex-1 overflow-y-auto min-h-0">
            <table class="w-full border-collapse" style="min-width:560px;">
                <thead class="sticky top-0 z-10 bg-[#F5F5F5]">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#333333] uppercase tracking-widest">Alumni</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#333333] uppercase tracking-widest">Status</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#333333] uppercase tracking-widest hidden sm:table-cell">Company / Position</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#333333] uppercase tracking-widest">Batch</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F5F5F5]">
                    @forelse($records as $row)
                    @php
                        $badge = $statusBadge[$row->employment_status] ?? ['Unknown','text-gray-600 bg-gray-50 border-gray-200','fa-circle'];
                        $empType = $empTypeMap[$row->employment_type ?? ''] ?? null;
                    @endphp
                    <tr class="bg-white hover:bg-[#FAFAFA] transition-colors">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <img src="{{ $this->getPhotoUrl($row->profile_photo) }}"
                                     alt="{{ $row->first_name }}"
                                     class="w-8 h-8 rounded-lg object-cover ring-1 ring-[#E8E0F0] shrink-0">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-[#333333] truncate uppercase">
                                        {{ $this->formatDisplayName($row->first_name??'', $row->middle_initial??'', $row->last_name??'', $row->suffix??'') }}
                                    </p>
                                    <p class="text-xs text-[#999999] font-normal">{{ $row->student_id }} &bull; {{ $row->course_code }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                <i class="fas {{ $badge[2] }} text-xs"></i>
                                {{ $badge[0] }}
                            </span>
                            @if($empType)
                                <p class="text-xs text-[#999999] mt-0.5 font-normal">{{ $empType }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            @if($row->company_name || $row->job_title)
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $row->company_name ?? '—' }}</p>
                                <p class="text-xs text-[#999999] font-normal truncate uppercase">{{ $row->job_title ?? '' }}</p>
                            @else
                                <span class="text-xs text-[#CCCCCC] font-normal">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-sm font-semibold text-[#333333]">{{ $row->batch }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="py-16 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                    <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                                </div>
                                <p class="text-sm font-semibold text-[#999999]">No records found</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Modal Footer / Pagination --}}
        <div class="px-5 py-3 border-t border-[#E8E0F0] shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            @php
                $rTotal = $records->total();
                $rPp    = $records->perPage();
                $rCp    = $records->currentPage();
                $rFrom  = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
                $rTo    = min($rCp * $rPp, $rTotal);
            @endphp
            <p class="text-white text-sm font-normal">
                Showing <strong class="font-semibold">{{ $rFrom }}–{{ $rTo }}</strong>
                of <strong class="font-semibold">{{ $rTotal }}</strong>
            </p>
            <div class="flex items-center gap-1.5">
                @if($records->onFirstPage())
                    <button disabled class="px-3 py-1.5 bg-white/20 text-white/50 rounded-xl text-xs font-semibold cursor-not-allowed">← Prev</button>
                @else
                    <button wire:click="empPrevPage"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-white text-[#7A3F91] shadow hover:bg-[#f0e6f8] transition">← Prev</button>
                @endif
                <span class="px-3 py-1.5 text-[#7A3F91] text-xs font-semibold bg-white rounded-xl">
                    {{ $rCp }} / {{ $records->lastPage() }}
                </span>
                @if($records->hasMorePages())
                    <button wire:click="empNextPage"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-white text-[#7A3F91] shadow hover:bg-[#f0e6f8] transition">Next →</button>
                @else
                    <button disabled class="px-3 py-1.5 bg-white/20 text-white/50 rounded-xl text-xs font-semibold cursor-not-allowed">Next →</button>
                @endif
            </div>
        </div>

    </div>
</div>
@endif