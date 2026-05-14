<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    public int $batchPage     = 0;
    public int $batchPageSize = 8;

    public string $activeModal = '';

    // ── Employment modal ──────────────────────────────────────
    public string  $empFilter        = '';
    public int     $empModalPage     = 1;
    public int     $empModalPageSize = 200;
    // NEW: batch + course + search filters for employment modal
    public string  $empBatchFilter   = '';
    public string  $empCourseFilter  = '';
    public string  $empSearch        = '';

    // ── Alumni / Courses modal ────────────────────────────────
    public string  $alumniModalFilter       = 'all';
    public int     $alumniModalPage         = 1;
    public int     $alumniModalPageSize     = 200;
    public string  $alumniModalSearch       = '';
    public ?int    $alumniModalBatch        = null;
    public string  $alumniModalCourseFilter = '';

    // Track whether batch was set by clicking the chart (locks the batch dropdown)
    public bool $alumniModalBatchLocked = false;

    // ─── Summary stats ────────────────────────────────────────

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
                     ->whereYear('created_at',  now()->year)
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

    // ─── Employment counts ────────────────────────────────────

    #[Computed]
    public function empCounts()
    {
        $rows = DB::table('employment_trackings')
            ->whereNull('deleted_at')
            ->select('employment_status', DB::raw('COUNT(*) as total'))
            ->groupBy('employment_status')
            ->get()
            ->keyBy('employment_status');

        $employed   = (int) ($rows['employed']->total      ?? 0);
        $self       = (int) ($rows['self_employed']->total ?? 0);
        $unemployed = (int) ($rows['unemployed']->total    ?? 0);
        $submitted  = $employed + $self + $unemployed;
        $noRecord   = max($this->totalAlumni - $submitted, 0);

        return compact('employed', 'self', 'unemployed', 'submitted', 'noRecord');
    }

    // ─── Employment modal records ─────────────────────────────

    #[Computed]
    public function empModalRecords()
    {
        // ── No-Record branch ─────────────────────────────────
        if ($this->empFilter === 'no_record') {
            $q = Alumni::select([
                'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'course_code', 'batch', 'profile_photo',
            ])
            ->whereNotExists(fn ($q) => $q
                ->from('employment_trackings')
                ->whereColumn('alumni_id', 'alumni.id')
                ->whereNull('deleted_at')
            );

            if ($this->empBatchFilter !== '')
                $q->where('batch', $this->empBatchFilter);

            if ($this->empCourseFilter !== '')
                $q->where('course_code', $this->empCourseFilter);

            if ($this->empSearch !== '') {
                $term = '%' . $this->empSearch . '%';
                $q->where(fn ($s) => $s
                    ->where('first_name',   'like', $term)
                    ->orWhere('last_name',   'like', $term)
                    ->orWhere('student_id',  'like', $term)
                    ->orWhere('course_code', 'like', $term)
                    ->orWhereRaw("CAST(batch AS CHAR) LIKE ?", [$term])
                );
            }

            return $q->orderBy('last_name')
                     ->paginate($this->empModalPageSize, ['*'], 'empPage', $this->empModalPage);
        }

        // ── Normal employment records branch ─────────────────
        $q = DB::table('employment_trackings as e')
            ->join('alumni as a', 'a.id', '=', 'e.alumni_id')
            ->whereNull('e.deleted_at')
            ->select([
                'a.id as alumni_id',
                'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                'a.student_id', 'a.course_code', 'a.batch', 'a.profile_photo',
                'e.employment_status', 'e.job_title', 'e.company_name',
                'e.employment_type', 'e.work_location',
                'e.created_at as emp_created_at',
            ])
            ->when($this->empFilter !== '', fn ($q) => $q->where('e.employment_status', $this->empFilter))
            ->when($this->empBatchFilter !== '',  fn ($q) => $q->where('a.batch', $this->empBatchFilter))
            ->when($this->empCourseFilter !== '', fn ($q) => $q->where('a.course_code', $this->empCourseFilter));

        if ($this->empSearch !== '') {
            $term = '%' . $this->empSearch . '%';
            $q->where(fn ($s) => $s
                ->where('a.first_name',   'like', $term)
                ->orWhere('a.last_name',   'like', $term)
                ->orWhere('a.student_id',  'like', $term)
                ->orWhere('a.course_code', 'like', $term)
                ->orWhere('e.company_name','like', $term)
                ->orWhere('e.job_title',   'like', $term)
                ->orWhereRaw("CAST(a.batch AS CHAR) LIKE ?", [$term])
            );
        }

        return $q->orderByDesc('e.created_at')
                 ->paginate($this->empModalPageSize, ['*'], 'empPage', $this->empModalPage);
    }

    #[Computed]
    public function empModalTitle(): string
    {
        return match ($this->empFilter) {
            'employed'      => 'Employed Alumni',
            'self_employed' => 'Self-Employed Alumni',
            'unemployed'    => 'Unemployed Alumni',
            'no_record'     => 'Alumni Without Employment Record',
            default         => 'All Employment Records',
        };
    }

    #[Computed]
    public function empModalIcon(): string
    {
        return match ($this->empFilter) {
            'employed'      => 'fa-user-tie',
            'self_employed' => 'fa-store',
            'unemployed'    => 'fa-magnifying-glass',
            'no_record'     => 'fa-circle-minus',
            default         => 'fa-briefcase',
        };
    }

    // ─── Alumni / Courses modal records ──────────────────────

    #[Computed]
    public function alumniModalRecords()
    {
        if ($this->alumniModalFilter === 'courses') {
            $q = Course::query();
            if ($this->alumniModalSearch) {
                $term = '%' . $this->alumniModalSearch . '%';
                $q->where(fn ($s) => $s
                    ->where('code',    'like', $term)
                    ->orWhere('name',    'like', $term)
                    ->orWhere('college', 'like', $term)
                );
            }
            return $q->orderBy('college')->orderBy('code')
                     ->paginate($this->alumniModalPageSize, ['*'], 'aPage', $this->alumniModalPage);
        }

        $q = Alumni::select([
            'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'batch', 'profile_photo',
            'profile_completed', 'created_at',
        ]);

        if ($this->alumniModalFilter === 'complete')
            $q->where('profile_completed', 1);
        elseif ($this->alumniModalFilter === 'incomplete')
            $q->where('profile_completed', 0);

        if ($this->alumniModalBatch !== null)
            $q->where('batch', $this->alumniModalBatch);

        if ($this->alumniModalCourseFilter !== '')
            $q->where('course_code', $this->alumniModalCourseFilter);

        if ($this->alumniModalSearch) {
            $term = '%' . $this->alumniModalSearch . '%';
            $q->where(fn ($s) => $s
                ->where('first_name',  'like', $term)
                ->orWhere('last_name',  'like', $term)
                ->orWhere('student_id', 'like', $term)
                ->orWhere('course_code','like', $term)
                ->orWhereRaw("CAST(batch AS CHAR) LIKE ?", [$term])
            );
        }

        return $q->orderByDesc('created_at')
                 ->paginate($this->alumniModalPageSize, ['*'], 'aPage', $this->alumniModalPage);
    }

    #[Computed]
    public function alumniModalTitle(): string
    {
        if ($this->alumniModalBatch !== null) {
            return match ($this->alumniModalFilter) {
                'complete'   => 'Complete Profiles — Batch ' . $this->alumniModalBatch,
                'incomplete' => 'Pending Profiles — Batch ' . $this->alumniModalBatch,
                default      => 'Batch ' . $this->alumniModalBatch . ' Alumni',
            };
        }
        return match ($this->alumniModalFilter) {
            'complete'   => 'Complete Profiles',
            'incomplete' => 'Pending Profiles',
            'courses'    => 'Active Courses',
            default      => 'All Alumni Records',
        };
    }

    #[Computed]
    public function alumniModalIcon(): string
    {
        if ($this->alumniModalBatch !== null) return 'fa-calendar-check';
        return match ($this->alumniModalFilter) {
            'complete'   => 'fa-circle-check',
            'incomplete' => 'fa-clock',
            'courses'    => 'fa-book-open',
            default      => 'fa-users',
        };
    }

    // Persisted so they don't re-query on every render (shared by both modals)
    #[Computed(persist: true)]
    public function availableModalBatches()
    {
        return DB::table('alumni')
            ->select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch');
    }

    #[Computed(persist: true)]
    public function availableModalCourses()
    {
        return DB::table('alumni')
            ->select('course_code')
            ->distinct()
            ->orderBy('course_code')
            ->pluck('course_code');
    }

    // ─── Batch chart ──────────────────────────────────────────

    #[Computed(persist: true)]
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

    public function batchPrev(): void { if ($this->batchPage > 0) $this->batchPage--; }
    public function batchNext(): void { if ($this->batchPage < $this->batchTotalPages - 1) $this->batchPage++; }

    // ─── Modal open / close ───────────────────────────────────

    public function openEmpModal(string $filter = ''): void
    {
        $this->empFilter       = $filter;
        $this->empModalPage    = 1;
        $this->empBatchFilter  = '';   // reset secondary filters on open
        $this->empCourseFilter = '';
        $this->empSearch       = '';
        $this->activeModal     = 'employment';
    }

    public function updatingEmpFilter(): void
    {
        $this->empModalPage    = 1;
        $this->empBatchFilter  = '';
        $this->empCourseFilter = '';
        $this->empSearch       = '';
    }

    // Hooks — reset page on every secondary filter change (single round-trip each)
    public function updatingEmpBatchFilter(): void  { $this->empModalPage = 1; }
    public function updatingEmpCourseFilter(): void { $this->empModalPage = 1; }
    public function updatingEmpSearch(): void       { $this->empModalPage = 1; }

    // Single method — clears batch + course + search in ONE round-trip
    public function clearEmpModalFilters(): void
    {
        $this->empBatchFilter  = '';
        $this->empCourseFilter = '';
        $this->empSearch       = '';
        $this->empModalPage    = 1;
    }

    public function empPrevPage(): void { if ($this->empModalPage > 1) $this->empModalPage--; }
    public function empNextPage(): void
    {
        if ($this->empModalPage < $this->empModalRecords->lastPage())
            $this->empModalPage++;
    }

    public function openAlumniModal(string $filter = 'all', ?int $batch = null): void
    {
        $this->alumniModalFilter       = $filter;
        $this->alumniModalBatch        = $batch;
        $this->alumniModalBatchLocked  = $batch !== null;
        $this->alumniModalPage         = 1;
        $this->alumniModalSearch       = '';
        $this->alumniModalCourseFilter = '';
        $this->activeModal             = 'alumni';
    }

    public function updatingAlumniModalSearch(): void  { $this->alumniModalPage = 1; }
    public function updatingAlumniModalFilter(): void  { $this->alumniModalPage = 1; $this->alumniModalSearch = ''; $this->alumniModalCourseFilter = ''; }
    public function updatingAlumniModalBatch(): void   { $this->alumniModalPage = 1; }
    public function updatingAlumniModalCourseFilter(): void { $this->alumniModalPage = 1; }

    // Single method — clears batch + course in ONE round-trip
    public function clearAlumniModalFilters(): void
    {
        $this->alumniModalBatch        = null;
        $this->alumniModalBatchLocked  = false;
        $this->alumniModalCourseFilter = '';
        $this->alumniModalPage         = 1;
    }

    public function alumniModalPrev(): void { if ($this->alumniModalPage > 1) $this->alumniModalPage--; }
    public function alumniModalNext(): void
    {
        if ($this->alumniModalPage < $this->alumniModalRecords->lastPage())
            $this->alumniModalPage++;
    }

    public function closeModal(): void { $this->activeModal = ''; }

    // ─── Helpers ──────────────────────────────────────────────

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

<div>

<style>
    @keyframes dashPageIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .dash-modal-enter { animation: dashPageIn .22s cubic-bezier(.4,0,.2,1) both; }
    .batch-bar-col:hover .batch-bar-inner { filter: brightness(1.12); transform: scaleY(1.03); transform-origin: bottom; }
    .batch-bar-inner { transition: filter .15s ease, transform .15s ease; }
    .stat-card:active { transform: scale(.985); }
    .stat-card { transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease; }

    .modal-select {
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237A3F91' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        padding-right: 30px !important;
        cursor: pointer;
    }
    .modal-select:focus {
        outline: none;
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,.10);
    }
    .filter-chip-active {
        background: linear-gradient(135deg, #7A3F91, #9b59b6);
        color: #fff;
        border-color: transparent;
    }
</style>

{{-- ═══════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 pb-4 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- PAGE HEADER --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-gauge-high text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-[#333333] leading-tight">Registrar Portal</h1>
            <p class="text-sm text-[#666666] font-normal">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    {{-- ─── STAT CARDS ─────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        {{-- Total Alumni --}}
        <div wire:click="openAlumniModal('all')"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-[#7A3F91]/40">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-users text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">Total</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalAlumni) }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Alumni Records</p>
            @if($this->newThisMonth > 0)
                <p class="text-xs text-emerald-600 font-semibold mt-2 flex items-center gap-1">
                    <i class="fas fa-arrow-trend-up text-sm"></i> +{{ $this->newThisMonth }} this month
                </p>
            @endif
        </div>

        {{-- Profile Complete --}}
        <div wire:click="openAlumniModal('complete')"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-emerald-300">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                    <i class="fas fa-circle-check text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase">Complete</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->profileComplete) }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Profiles Filled</p>
            <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all duration-700"
                     style="width:{{ $this->completionRate }}%;"></div>
            </div>
            <p class="text-xs text-[#999999] mt-1 font-normal">{{ $this->completionRate }}% completion rate</p>
        </div>

        {{-- Profile Pending --}}
        <div wire:click="openAlumniModal('incomplete')"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-amber-300">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                    <i class="fas fa-clock text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Pending</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->profileIncomplete) }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Pending Profiles</p>
            @if($this->totalAlumni > 0)
                <p class="text-xs text-amber-600 font-semibold mt-2">
                    {{ round(($this->profileIncomplete / $this->totalAlumni) * 100) }}% still need info
                </p>
            @endif
        </div>

        {{-- Total Courses --}}
        <div wire:click="openAlumniModal('courses')"
             class="stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden cursor-pointer
                    hover:shadow-md hover:border-blue-300">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                    <i class="fas fa-book-open text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Courses</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ number_format($this->totalCourses) }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Active Courses</p>
            @if($this->latestBatch)
                <p class="text-xs text-blue-600 font-semibold mt-2 flex items-center gap-1">
                    <i class="fas fa-calendar-check text-sm"></i> Latest batch: {{ $this->latestBatch }}
                </p>
            @endif
        </div>

    </div>

    {{-- ─── MAIN GRID ───────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

        {{-- Employment Overview --}}
        @php
            $ec           = $this->empCounts;
            $submitted    = $ec['submitted'];
            $total        = $this->totalAlumni;
            $submittedPct = $total > 0 ? round(($submitted / $total) * 100) : 0;
            $empRows = [
                ['label'=>'Employed',      'count'=>$ec['employed'],   'icon'=>'fa-user-tie',        'color'=>'#7A3F91','light'=>'#F9F7FC','border'=>'#E8E0F0','filter'=>'employed'],
                ['label'=>'Self-Employed', 'count'=>$ec['self'],        'icon'=>'fa-store',           'color'=>'#2563eb','light'=>'#EFF6FF','border'=>'#BFDBFE','filter'=>'self_employed'],
                ['label'=>'Unemployed',    'count'=>$ec['unemployed'], 'icon'=>'fa-magnifying-glass', 'color'=>'#d97706','light'=>'#FFFBEB','border'=>'#FCD34D','filter'=>'unemployed'],
                ['label'=>'No Record',     'count'=>$ec['noRecord'],   'icon'=>'fa-circle-minus',     'color'=>'#6B7280','light'=>'#F9FAFB','border'=>'#E5E7EB','filter'=>'no_record'],
            ];
        @endphp

        <div class="lg:col-span-1 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0]" style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Employment Overview</p>
                </div>
            </div>
            <div class="p-4 flex flex-col gap-3 flex-1">
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
                <div class="space-y-2">
                    @foreach($empRows as $row)
                    <div class="rounded-xl border p-3 cursor-pointer transition-all duration-150 hover:shadow-md active:scale-[.98]"
                         style="background:{{ $row['light'] }}; border-color:{{ $row['border'] }};"
                         wire:click="openEmpModal('{{ $row['filter'] }}')">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                                     style="background:{{ $row['color'] }}20; color:{{ $row['color'] }};">
                                    <i class="fas {{ $row['icon'] }} text-xs"></i>
                                </div>
                                <span class="text-sm font-semibold text-[#333333]">{{ $row['label'] }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-2xl font-semibold" style="color:{{ $row['color'] }};">{{ number_format($row['count']) }}</span>
                                <i class="fas fa-chevron-right text-xs text-[#CCCCCC]"></i>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Alumni by Batch Year --}}
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
        <div class="lg:col-span-2 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
            <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between"
                 style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-chart-bar text-white" style="font-size:11px;"></i>
                    </div>
                    <p class="text-sm font-semibold text-[#333333] uppercase tracking-wide">Alumni by Batch Year</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-[#666666] font-normal hidden sm:inline">
                        <strong class="text-[#333333] font-semibold">{{ $pageStart }}–{{ $pageEnd }}</strong>
                        of <strong class="text-[#333333] font-semibold">{{ $allBatches->count() }}</strong> batches
                    </span>
                    <div class="flex items-center gap-1">
                        <button wire:click="batchPrev" @if(!$hasPrev) disabled @endif
                                class="w-7 h-7 rounded-lg flex items-center justify-center text-sm transition-all duration-150
                                       {{ $hasPrev ? 'bg-white border border-[#E8E0F0] text-[#7A3F91] hover:bg-[#F9F7FC] active:scale-95'
                                                   : 'bg-[#F5F5F5] border border-[#E8E0F0] text-[#CCCCCC] cursor-not-allowed' }}">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </button>
                        <span class="text-xs font-semibold text-[#666666] px-1">{{ $this->batchPage + 1 }} / {{ $totalPages }}</span>
                        <button wire:click="batchNext" @if(!$hasNext) disabled @endif
                                class="w-7 h-7 rounded-lg flex items-center justify-center text-sm transition-all duration-150
                                       {{ $hasNext ? 'bg-white border border-[#E8E0F0] text-[#7A3F91] hover:bg-[#F9F7FC] active:scale-95'
                                                   : 'bg-[#F5F5F5] border border-[#E8E0F0] text-[#CCCCCC] cursor-not-allowed' }}">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="px-6 pt-5 pb-4 flex-1">
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
                        <div class="batch-bar-col flex-1 flex flex-col items-center justify-end cursor-pointer group"
                             style="height:{{ $chartH }}px;"
                             wire:click="openAlumniModal('all', {{ $row->batch }})">
                            <span class="block text-center text-xs font-semibold mb-1.5 leading-none"
                                  style="color:{{ $isTop ? '#7A3F91' : '#a07bbf' }};">
                                {{ number_format($row->total) }}
                            </span>
                            <div class="batch-bar-inner w-full rounded-t-lg relative overflow-hidden"
                                 style="height:{{ $barPx }}px;
                                        background:{{ $isTop
                                            ? 'linear-gradient(180deg,#7A3F91 0%,#9b59b6 100%)'
                                            : 'linear-gradient(180deg,#c8a0e0 0%,#dbbcef 100%)' }};">
                                <div class="absolute top-0 left-0 right-0 h-1 opacity-25 rounded-t-lg" style="background:white;"></div>
                                <div class="absolute -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap z-20
                                            bg-[#333333] text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg shadow-lg
                                            opacity-0 group-hover:opacity-100 transition-opacity duration-150 pointer-events-none">
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
                            <span class="text-xs font-semibold text-[#666666]">{{ $row->batch }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="ml-12 h-px bg-[#E8E0F0] rounded-full -mt-1 mb-3"></div>
                <div class="ml-12 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-1.5">
                            <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);"></div>
                            <span class="text-xs text-[#666666] font-medium">Highest batch</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#c8a0e0,#dbbcef);"></div>
                            <span class="text-xs text-[#666666] font-medium">Other batches</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="text-xs text-[#666666] font-normal">
                            Peak: <strong class="text-[#333333] font-semibold">{{ number_format($globalMax) }}</strong>
                        </span>
                        <span class="text-xs text-[#666666] font-normal">
                            Total: <strong class="text-[#333333] font-semibold">{{ number_format($allBatches->sum('total')) }}</strong>
                        </span>
                    </div>
                </div>
                <p class="ml-12 mt-2 text-xs text-[#AAAAAA] font-normal">Click any bar to view alumni for that batch</p>
            </div>
        </div>
        @endif

    </div>

</div>{{-- END page content --}}


{{-- ═══════════════════════════════════════════════════════════
     ALUMNI / COURSES FULL-SCREEN MODAL
═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'alumni')
@php
    $allFilterTabs = [
        ['all',        'All Alumni', 'fa-users'],
        ['complete',   'Complete',   'fa-circle-check'],
        ['incomplete', 'Pending',    'fa-clock'],
    ];

    $isAllNoFilter   = ($alumniModalFilter === 'all' && $alumniModalBatch === null);
    $hasBatchContext = ($alumniModalBatch !== null);

    if ($isAllNoFilter) {
        $visibleAlumniTabs   = [['all', 'All Alumni', 'fa-users']];
        $alumniTabsClickable = false;
    } elseif ($hasBatchContext) {
        $visibleAlumniTabs   = $allFilterTabs;
        $alumniTabsClickable = true;
    } else {
        $visibleAlumniTabs   = array_values(array_filter($allFilterTabs, fn($t) => $t[0] === $alumniModalFilter));
        $alumniTabsClickable = false;
    }
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- ─── Header ──────────────────────────────────────────── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->alumniModalIcon }} text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $this->alumniModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">
                    {{ number_format($this->alumniModalRecords->total()) }} record(s) found
                </p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- ─── Toolbar ─────────────────────────────────────────── --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">

        {{-- Row 1: Search + Filter tabs --}}
        <div class="flex flex-wrap gap-3 items-center mb-3">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q = $wire.alumniModalSearch ?? ''; $wire.$watch('alumniModalSearch', v => { if(v!==this.q) this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('alumniModalSearch', q)"
                       placeholder="{{ $alumniModalFilter === 'courses' ? 'Search course, name, college…' : 'Search name, ID, course, batch…' }}"
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all duration-150"
                       autocomplete="off">
            </div>

            {{-- Profile filter tabs (only for alumni, not courses) --}}
            @if($alumniModalFilter !== 'courses')
            <div class="flex flex-wrap gap-1.5 items-center">
                @foreach($visibleAlumniTabs as [$val, $lbl, $icon])
                <button
                    @if($alumniTabsClickable) wire:click="$set('alumniModalFilter','{{ $val }}')" @endif
                    class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all duration-150 flex items-center gap-1.5
                           {{ $alumniModalFilter === $val
                               ? 'filter-chip-active'
                               : 'bg-white text-gray-600 border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                           {{ !$alumniTabsClickable ? 'cursor-default' : 'active:scale-95' }}">
                    <i class="fas {{ $icon }} text-xs"></i>{{ $lbl }}
                </button>
                @endforeach

                @if(!$alumniTabsClickable && !$isAllNoFilter)
                <span class="flex items-center gap-1 text-xs text-gray-400 font-normal ml-1">
                    <i class="fas fa-lock text-gray-300 text-xs"></i> Filtered view
                </span>
                @endif
            </div>
            @endif

        </div>

        {{-- Row 2: Batch Year + Course dropdowns (alumni only) --}}
        @if($alumniModalFilter !== 'courses')
        <div class="flex flex-wrap gap-2 items-center">

            {{-- Batch Year dropdown (hidden when batch-locked from chart) --}}
            @if(!$alumniModalBatchLocked)
            <div class="relative flex items-center">
                <i class="fas fa-calendar-alt text-xs absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $alumniModalBatch ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="alumniModalBatch"
                        class="modal-select pl-8 pr-8 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $alumniModalBatch
                                   ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]'
                                   : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Batch Years</option>
                    @foreach($this->availableModalBatches as $bYear)
                        <option value="{{ $bYear }}">Batch {{ $bYear }}</option>
                    @endforeach
                </select>
                @if($alumniModalBatch)
                <button wire:click="$set('alumniModalBatch', null)"
                        class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear batch filter">
                    <i class="fas fa-xmark" style="font-size:9px;"></i>
                </button>
                @endif
            </div>
            @else
            {{-- Locked batch chip (from chart bar click) --}}
            <div class="flex items-center">
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold border"
                      style="background:#F9F7FC; color:#7A3F91; border-color:#7A3F91;">
                    <i class="fas fa-calendar-check text-xs"></i>
                    Batch {{ $alumniModalBatch }}
                    <i class="fas fa-lock text-[10px] opacity-50 ml-0.5"></i>
                </span>
            </div>
            @endif

            {{-- Course dropdown --}}
            <div class="relative flex items-center">
                <i class="fas fa-book-open text-xs absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $alumniModalCourseFilter ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="alumniModalCourseFilter"
                        class="modal-select pl-8 pr-8 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $alumniModalCourseFilter
                                   ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]'
                                   : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Courses</option>
                    @foreach($this->availableModalCourses as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
                @if($alumniModalCourseFilter)
                <button wire:click="$set('alumniModalCourseFilter', '')"
                        class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear course filter">
                    <i class="fas fa-xmark" style="font-size:9px;"></i>
                </button>
                @endif
            </div>

            {{-- Active filter chips + Clear All --}}
            @if($alumniModalBatch || $alumniModalCourseFilter)
            <div class="flex items-center gap-1.5 ml-1">
                <span class="text-xs text-gray-400 font-normal">Filtering by:</span>
                @if($alumniModalBatch && !$alumniModalBatchLocked)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                          style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                        <i class="fas fa-calendar text-xs"></i> Batch {{ $alumniModalBatch }}
                    </span>
                @endif
                @if($alumniModalCourseFilter)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                          style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                        <i class="fas fa-book text-xs"></i> {{ $alumniModalCourseFilter }}
                    </span>
                @endif
                @if(!$alumniModalBatchLocked || $alumniModalCourseFilter)
                <button wire:click="clearAlumniModalFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-wait"
                        class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors duration-100 ml-1">
                    <span wire:loading.remove wire:target="clearAlumniModalFilters">Clear all</span>
                    <span wire:loading wire:target="clearAlumniModalFilters">Clearing…</span>
                </button>
                @endif
            </div>
            @endif

        </div>
        @endif

    </div>

    {{-- ─── Table (with loading overlay) ──────────────────── --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb;">

        {{-- Livewire loading overlay --}}
        <div wire:loading
             wire:target="alumniModalPage,alumniModalFilter,alumniModalBatch,alumniModalCourseFilter,alumniModalSearch,clearAlumniModalFilters,alumniModalPrev,alumniModalNext"
             class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
             style="background:rgba(255,255,255,.6);">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
            </div>
        </div>

        @if($alumniModalFilter === 'courses')
        {{-- ── Courses table ── --}}
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course Name</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Colleges</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->alumniModalRecords as $idx => $course)
                @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold text-gray-400">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-5 py-3.5">
                        <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-mono font-bold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $course->code }}</span>
                    </td>
                    <td class="px-5 py-3.5">
                        <p class="text-sm font-semibold text-gray-800">{{ $course->name }}</p>
                    </td>
                    <td class="px-5 py-3.5">
                        <span class="text-sm text-gray-500">{{ $course->college ?? '—' }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 bg-[#f0e6f8] rounded-2xl flex items-center justify-center">
                            <i class="fas fa-book text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No courses found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>

        @else
        {{-- ── Alumni table ── --}}
        <table class="w-full border-collapse" style="min-width:720px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Student ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Batch</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Profile</th>
                    <th class="pl-4 pr-6 lg:pr-10 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Registered</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->alumniModalRecords as $idx => $alumni)
                @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                 alt="{{ $alumni->first_name }}"
                                 class="w-9 h-9 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <p class="text-sm font-semibold text-gray-900 truncate uppercase">
                                {{ $this->formatDisplayName($alumni->first_name??'',$alumni->middle_initial??'',$alumni->last_name??'',$alumni->suffix??'') }}
                            </p>
                        </div>
                    </td>
                    <td class="px-4 py-3.5">
                        <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-mono font-bold border"
                              style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0; letter-spacing:.03em;">
                            {{ $alumni->student_id }}
                        </span>
                    </td>
                    <td class="px-4 py-3.5">
                        <span class="font-mono text-sm text-gray-700">{{ $alumni->course_code }}</span>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="text-sm font-semibold text-gray-800">{{ $alumni->batch }}</span>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        @if($alumni->profile_completed)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                <i class="fas fa-circle-check text-xs"></i> Complete
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                <i class="fas fa-clock text-xs"></i> Pending
                            </span>
                        @endif
                    </td>
                    <td class="pl-4 pr-6 lg:pr-10 py-3.5 text-right hidden sm:table-cell">
                        <span class="text-xs text-gray-400 font-normal">{{ $alumni->created_at->diffForHumans() }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-users text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No alumni records found</p>
                        <p class="text-xs text-gray-300 font-normal">Try adjusting your search or filters</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @endif

    </div>

    {{-- ─── Footer Pagination ───────────────────────────────── --}}
    @php
        $rTotal = $this->alumniModalRecords->total();
        $rPp    = $this->alumniModalRecords->perPage();
        $rCp    = $this->alumniModalRecords->currentPage();
        $rFrom  = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
        $rTo    = min($rCp * $rPp, $rTotal);
    @endphp
    <div class="px-5 py-4 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <p class="text-white text-base font-normal">
            Showing <strong class="font-bold text-lg">{{ $rFrom }}–{{ $rTo }}</strong>
            of <strong class="font-bold text-lg">{{ number_format($rTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-2">
            @if($this->alumniModalRecords->onFirstPage())
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">← Prev</button>
            @else
                <button wire:click="alumniModalPrev"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">← Prev</button>
            @endif
            <span class="px-4 py-2 text-sm font-bold bg-white rounded-xl shadow-sm" style="color:#7A3F91;">
                Page {{ $rCp }} / {{ $this->alumniModalRecords->lastPage() }}
            </span>
            @if($this->alumniModalRecords->hasMorePages())
                <button wire:click="alumniModalNext"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">Next →</button>
            @else
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">Next →</button>
            @endif
        </div>
    </div>

</div>
@endif


{{-- ═══════════════════════════════════════════════════════════
     EMPLOYMENT FULL-SCREEN MODAL
═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'employment')
@php
    $records     = $this->empModalRecords;
    $isNoRecord  = $this->empFilter === 'no_record';
    $statusBadge = [
        'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]', 'fa-user-tie'],
        'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',       'fa-store'],
        'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',    'fa-magnifying-glass'],
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];

    $allEmpTabs = [
        [''             , 'All',           'fa-briefcase'],
        ['employed'     , 'Employed',       'fa-user-tie'],
        ['self_employed', 'Self-Employed',  'fa-store'],
        ['unemployed'   , 'Unemployed',     'fa-magnifying-glass'],
        ['no_record'    , 'No Record',      'fa-circle-minus'],
    ];
    $empTabsLocked  = ($empFilter !== '');
    $visibleEmpTabs = $empTabsLocked
        ? array_values(array_filter($allEmpTabs, fn($t) => $t[0] === $empFilter))
        : $allEmpTabs;

    // Whether any secondary filter is active (for "Clear all" button)
    $empHasSecondaryFilter = ($empBatchFilter !== '' || $empCourseFilter !== '' || $empSearch !== '');
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
     @keydown.escape.window="$wire.closeModal()">

    {{-- ─── Header ──────────────────────────────────────────── --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas {{ $this->empModalIcon }} text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $this->empModalTitle }}</h2>
                <p class="text-white/60 text-xs font-normal">
                    {{ number_format($records->total()) }} record(s) found
                </p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-sm font-semibold transition-all duration-150">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- ─── Toolbar ─────────────────────────────────────────── --}}
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">

        {{-- Row 1: Status filter tabs + Search --}}
        <div class="flex flex-wrap gap-3 items-center mb-3">

            {{-- Status tabs (locked when a specific status was clicked from the dashboard) --}}
            <div class="flex flex-wrap gap-1.5 items-center">
                @foreach($visibleEmpTabs as [$val, $lbl, $icon])
                <button
                    @if(!$empTabsLocked) wire:click="$set('empFilter','{{ $val }}')" @endif
                    class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all duration-150 flex items-center gap-1.5
                           {{ $this->empFilter === $val
                               ? 'filter-chip-active'
                               : 'bg-white text-gray-600 border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                           {{ $empTabsLocked ? 'cursor-default' : 'active:scale-95' }}">
                    <i class="fas {{ $icon }} text-xs"></i>{{ $lbl }}
                </button>
                @endforeach

                @if($empTabsLocked)
                <span class="flex items-center gap-1 text-xs text-gray-400 font-normal ml-1">
                    <i class="fas fa-lock text-gray-300 text-xs"></i> Filtered view
                </span>
                @endif
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q = $wire.empSearch ?? ''; $wire.$watch('empSearch', v => { if(v!==this.q) this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q"
                       @input.debounce.300ms="$wire.set('empSearch', q)"
                       placeholder="Search name, ID, company, job title…"
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900
                              focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all duration-150"
                       autocomplete="off">
            </div>

        </div>

        {{-- Row 2: Batch + Course dropdowns ─────────────────── --}}
        <div class="flex flex-wrap gap-2 items-center">

            {{-- Batch Year dropdown --}}
            <div class="relative flex items-center">
                <i class="fas fa-calendar-alt text-xs absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $empBatchFilter ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="empBatchFilter"
                        class="modal-select pl-8 pr-8 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $empBatchFilter
                                   ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]'
                                   : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Batch Years</option>
                    @foreach($this->availableModalBatches as $bYear)
                        <option value="{{ $bYear }}">Batch {{ $bYear }}</option>
                    @endforeach
                </select>
                @if($empBatchFilter)
                <button wire:click="$set('empBatchFilter', '')"
                        class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear batch filter">
                    <i class="fas fa-xmark" style="font-size:9px;"></i>
                </button>
                @endif
            </div>

            {{-- Course dropdown --}}
            <div class="relative flex items-center">
                <i class="fas fa-book-open text-xs absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                   style="color:{{ $empCourseFilter ? '#7A3F91' : '#9CA3AF' }};"></i>
                <select wire:model.live="empCourseFilter"
                        class="modal-select pl-8 pr-8 py-2 border rounded-lg text-xs font-semibold transition-all duration-150
                               {{ $empCourseFilter
                                   ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]'
                                   : 'border-gray-200 bg-white text-gray-600 hover:border-[#d4aaeb]' }}">
                    <option value="">All Courses</option>
                    @foreach($this->availableModalCourses as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
                @if($empCourseFilter)
                <button wire:click="$set('empCourseFilter', '')"
                        class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 flex items-center justify-center rounded-full
                               bg-[#7A3F91]/15 text-[#7A3F91] hover:bg-[#7A3F91]/25 transition-colors duration-100"
                        title="Clear course filter">
                    <i class="fas fa-xmark" style="font-size:9px;"></i>
                </button>
                @endif
            </div>

            {{-- Active filter chips + Clear All --}}
            @if($empHasSecondaryFilter)
            <div class="flex items-center gap-1.5 ml-1">
                <span class="text-xs text-gray-400 font-normal">Filtering by:</span>
                @if($empBatchFilter)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                          style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                        <i class="fas fa-calendar text-xs"></i> Batch {{ $empBatchFilter }}
                    </span>
                @endif
                @if($empCourseFilter)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                          style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                        <i class="fas fa-book text-xs"></i> {{ $empCourseFilter }}
                    </span>
                @endif
                @if($empSearch)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                          style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">
                        <i class="fas fa-search text-xs"></i> "{{ Str::limit($empSearch, 20) }}"
                    </span>
                @endif
                {{-- Single method = ONE round-trip, no lag --}}
                <button wire:click="clearEmpModalFilters"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-wait"
                        class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors duration-100 ml-1">
                    <span wire:loading.remove wire:target="clearEmpModalFilters">Clear all</span>
                    <span wire:loading wire:target="clearEmpModalFilters">Clearing…</span>
                </button>
            </div>
            @endif

        </div>

    </div>

    {{-- ─── Table (with loading overlay) ──────────────────── --}}
    <div class="flex-1 overflow-y-auto min-h-0 relative" style="scrollbar-width:thin; scrollbar-color:#d1d5db #f9fafb;">

        {{-- Livewire loading overlay --}}
        <div wire:loading
             wire:target="empFilter,empBatchFilter,empCourseFilter,empSearch,empModalPage,empPrevPage,empNextPage,clearEmpModalFilters"
             class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
             style="background:rgba(255,255,255,.6);">
            <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
            </div>
        </div>

        <table class="w-full border-collapse" style="min-width:580px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Alumni</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Company / Position</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Batch</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($records as $idx => $row)
                @php
                    $rowNum  = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                    $badge   = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? ['Unknown','text-gray-600 bg-gray-50 border-gray-200','fa-circle']);
                    $empType = !$isNoRecord ? ($empTypeMap[$row->employment_type ?? ''] ?? null) : null;
                    $photo   = $this->getPhotoUrl($row->profile_photo ?? null);
                    $dName   = $this->formatDisplayName(
                        $row->first_name ?? '', $row->middle_initial ?? '',
                        $row->last_name  ?? '', $row->suffix ?? ''
                    );
                    $studentId  = $row->student_id ?? '';
                    $courseCode = $row->course_code ?? '';
                    $batch      = $row->batch ?? '—';
                @endphp
                <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5">
                        <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                    </td>
                    <td class="px-4 py-3.5">
                        <div class="flex items-center gap-2.5">
                            <img src="{{ $photo }}" alt="{{ $row->first_name ?? '' }}"
                                 class="w-9 h-9 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $dName }}</p>
                                <p class="text-xs text-[#999999] font-normal">{{ $studentId }} &bull; {{ $courseCode }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3.5">
                        @if($isNoRecord)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">
                                <i class="fas fa-circle-minus text-xs"></i> No Record
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                <i class="fas {{ $badge[2] }} text-xs"></i> {{ $badge[0] }}
                            </span>
                            @if($empType)
                                <p class="text-xs text-[#999999] mt-0.5 font-normal">{{ $empType }}</p>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell">
                        @if(!$isNoRecord && ($row->company_name || $row->job_title))
                            <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $row->company_name ?? '—' }}</p>
                            <p class="text-xs text-[#999999] font-normal truncate uppercase">{{ $row->job_title ?? '' }}</p>
                        @else
                            <span class="text-xs text-[#CCCCCC] font-normal">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="text-sm font-semibold text-[#333333]">{{ $batch }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                            <i class="fas fa-briefcase text-2xl" style="color:#c89de0;"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-400">No records found</p>
                        <p class="text-xs text-gray-300 font-normal">Try adjusting your search or filters</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ─── Footer Pagination ───────────────────────────────── --}}
    @php
        $rTotal = $records->total();
        $rPp    = $records->perPage();
        $rCp    = $records->currentPage();
        $rFrom  = $rTotal > 0 ? ($rCp - 1) * $rPp + 1 : 0;
        $rTo    = min($rCp * $rPp, $rTotal);
    @endphp
    <div class="px-5 py-4 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <p class="text-white text-base font-normal">
            Showing <strong class="font-bold text-lg">{{ $rFrom }}–{{ $rTo }}</strong>
            of <strong class="font-bold text-lg">{{ number_format($rTotal) }}</strong> records
        </p>
        <div class="flex items-center gap-2">
            @if($records->onFirstPage())
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">← Prev</button>
            @else
                <button wire:click="empPrevPage"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">← Prev</button>
            @endif
            <span class="px-4 py-2 text-sm font-bold bg-white rounded-xl shadow-sm" style="color:#7A3F91;">
                Page {{ $rCp }} / {{ $records->lastPage() }}
            </span>
            @if($records->hasMorePages())
                <button wire:click="empNextPage"
                        class="px-4 py-2 rounded-xl text-sm font-bold bg-white shadow hover:bg-[#f0e6f8] active:scale-95 transition-all duration-150"
                        style="color:#7A3F91;">Next →</button>
            @else
                <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold cursor-not-allowed"
                        style="background:rgba(255,255,255,.15); color:rgba(255,255,255,.4);">Next →</button>
            @endif
        </div>
    </div>

</div>
@endif

</div>{{-- END single root element --}}