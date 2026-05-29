<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    public string $activeModal = '';

    // ── Employment modal ──────────────────────────────────────
    public string  $empFilter        = '';
    public int     $empModalPage     = 1;
    public int     $empModalPageSize = 200;
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
    public bool    $alumniModalBatchLocked  = false;

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
        if ($this->empFilter === 'no_record') {
            $q = Alumni::select([
                'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'course_code', 'batch', 'profile_photo', 'email',
                'contact_number',
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
                    ->orWhere('email',       'like', $term)
                    ->orWhereRaw("CAST(batch AS CHAR) LIKE ?", [$term])
                );
            }

            return $q->orderBy('last_name')
                     ->paginate($this->empModalPageSize, ['*'], 'empPage', $this->empModalPage);
        }

        $q = DB::table('employment_trackings as e')
            ->join('alumni as a', 'a.id', '=', 'e.alumni_id')
            ->whereNull('e.deleted_at')
            ->select([
                'a.id as alumni_id',
                'a.first_name', 'a.middle_initial', 'a.last_name', 'a.suffix',
                'a.student_id', 'a.course_code', 'a.batch', 'a.profile_photo', 'a.email',
                'a.contact_number',
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
                ->orWhere('a.email',       'like', $term)
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
            'profile_completed', 'email', 'created_at',
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

    // ─── Batch chart data (all, for JS-side pagination) ───────

    #[Computed(persist: true)]
    public function allBatches()
    {
        return DB::table('alumni')
            ->select('batch', DB::raw('COUNT(*) as total'))
            ->groupBy('batch')
            ->orderBy('batch', 'asc')
            ->get();
    }

    // ─── Modal open / close ───────────────────────────────────

    public function openEmpModal(string $filter = ''): void
    {
        $this->empFilter       = $filter;
        $this->empModalPage    = 1;
        $this->empBatchFilter  = '';
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

    public function updatingEmpBatchFilter(): void  { $this->empModalPage = 1; }
    public function updatingEmpCourseFilter(): void { $this->empModalPage = 1; }
    public function updatingEmpSearch(): void       { $this->empModalPage = 1; }

    public function clearEmpModalFilters(): void
    {
        $this->empBatchFilter  = '';
        $this->empCourseFilter = '';
        $this->empSearch       = '';
        $this->empModalPage    = 1;
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

    public function updatingAlumniModalSearch(): void       { $this->alumniModalPage = 1; }
    public function updatingAlumniModalFilter(): void       { $this->alumniModalPage = 1; $this->alumniModalSearch = ''; $this->alumniModalCourseFilter = ''; }
    public function updatingAlumniModalBatch(): void        { $this->alumniModalPage = 1; }
    public function updatingAlumniModalCourseFilter(): void { $this->alumniModalPage = 1; }

    public function clearAlumniModalFilters(): void
    {
        $this->alumniModalBatch        = null;
        $this->alumniModalBatchLocked  = false;
        $this->alumniModalCourseFilter = '';
        $this->alumniModalPage         = 1;
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

<div
    @dash-open-alumni.window="$wire.openAlumniModal($event.detail.filter ?? 'all', $event.detail.batch ?? null)"
    @dash-open-emp.window="$wire.openEmpModal($event.detail.filter ?? '')">

    <div id="__dash_batch_data" class="hidden"
         data-batches="{{ $this->allBatches->toJson() }}"
         data-alumni-route="{{ route('registrar.alumni') }}"
         data-emp-route="{{ route('registrar.employment.tracking') }}">
    </div>

    <div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 pb-4 max-w-screen-2xl mx-auto">

        {{-- PAGE HEADER --}}
        <div class="flex items-center gap-3 mb-5">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-gauge-high text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#111111] leading-tight">Registrar Dashboard</h1>
                <p class="text-sm text-[#333333] font-normal">{{ now()->format('l, F j, Y') }}</p>
            </div>
        </div>

        {{-- ─── STAT CARDS ─────────────────────────────────────── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

            {{-- Total Alumni --}}
            <a href="{{ route('registrar.alumni') }}?profile_filter=all"
               class="relative overflow-visible bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                      hover:shadow-md hover:border-[#7A3F91]/40 transition-all duration-200 active:scale-[.985] block no-underline">
                <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                             bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                             px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                             opacity-0 z-50 shadow-lg
                             before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                             before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]
                             [.relative:hover_&]:opacity-100">
                    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View All Alumni Records
                </span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-users text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">Total</span>
                </div>
                <p class="text-3xl font-semibold text-[#111111] leading-none">{{ number_format($this->totalAlumni) }}</p>
                <p class="text-sm text-[#333333] mt-1 font-normal">Alumni Records</p>
                @if($this->newThisMonth > 0)
                    <p class="text-xs text-emerald-600 font-semibold mt-2 flex items-center gap-1">
                        <i class="fas fa-arrow-trend-up text-sm"></i> +{{ $this->newThisMonth }} this month
                    </p>
                @endif
            </a>

            {{-- Profile Complete --}}
            <a href="{{ route('registrar.alumni') }}?profile_filter=complete"
               class="relative overflow-visible bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                      hover:shadow-md hover:border-emerald-300 transition-all duration-200 active:scale-[.985] block no-underline">
                <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                             bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                             px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                             opacity-0 z-50 shadow-lg
                             before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                             before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]
                             [.relative:hover_&]:opacity-100">
                    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Complete Profiles
                </span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                        <i class="fas fa-circle-check text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase">Complete</span>
                </div>
                <p class="text-3xl font-semibold text-[#111111] leading-none">{{ number_format($this->profileComplete) }}</p>
                <p class="text-sm text-[#333333] mt-1 font-normal">Profiles Filled</p>
                <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full transition-all duration-700"
                         style="width:{{ $this->completionRate }}%;"></div>
                </div>
                <p class="text-xs text-[#333333] mt-1 font-normal">{{ $this->completionRate }}% completion rate</p>
            </a>

            {{-- Profile Pending --}}
            <a href="{{ route('registrar.alumni') }}?profile_filter=incomplete"
               class="relative overflow-visible bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                      hover:shadow-md hover:border-amber-300 transition-all duration-200 active:scale-[.985] block no-underline">
                <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                             bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                             px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                             opacity-0 z-50 shadow-lg
                             before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                             before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]
                             [.relative:hover_&]:opacity-100">
                    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Pending Profiles
                </span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                        <i class="fas fa-clock text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Pending</span>
                </div>
                <p class="text-3xl font-semibold text-[#111111] leading-none">{{ number_format($this->profileIncomplete) }}</p>
                <p class="text-sm text-[#333333] mt-1 font-normal">Pending Profiles</p>
                @if($this->totalAlumni > 0)
                    <p class="text-xs text-amber-600 font-semibold mt-2">
                        {{ round(($this->profileIncomplete / $this->totalAlumni) * 100) }}% still need info
                    </p>
                @endif
            </a>

            {{-- Total Courses --}}
            <div wire:click="openAlumniModal('courses')"
                 class="relative overflow-visible cursor-pointer bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4
                        hover:shadow-md hover:border-blue-300 transition-all duration-200 active:scale-[.985]">
                <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                             bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                             px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                             opacity-0 z-50 shadow-lg
                             before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                             before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]
                             [.relative:hover_&]:opacity-100">
                    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Active Courses
                </span>
                <div class="flex items-start justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                        <i class="fas fa-book-open text-white text-base"></i>
                    </div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Courses</span>
                </div>
                <p class="text-3xl font-semibold text-[#111111] leading-none">{{ number_format($this->totalCourses) }}</p>
                <p class="text-sm text-[#333333] mt-1 font-normal">Active Courses</p>
                @if($this->latestBatch)
                    <p class="text-xs text-blue-600 font-semibold mt-2 flex items-center gap-1">
                        <i class="fas fa-calendar-check text-sm"></i> Latest batch: {{ $this->latestBatch }}
                    </p>
                @endif
            </div>

        </div>

        {{-- ─── MAIN GRID ───────────────────────────────────────── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4" style="align-items:stretch;">

            {{-- Employment Overview --}}
            @php
                $ec           = $this->empCounts;
                $submitted    = $ec['submitted'];
                $total        = $this->totalAlumni;
                $submittedPct = $total > 0 ? round(($submitted / $total) * 100) : 0;

                $empRows = [
                    [
                        'label'       => 'Employed',
                        'count'       => $ec['employed'],
                        'color'       => '#7A3F91',
                        'light'       => '#F9F7FC',
                        'border'      => '#E8E0F0',
                        'filter'      => 'employed',
                        'tooltip'     => 'View All Employed',
                        'hoverBorder' => '#7A3F91',
                    ],
                    [
                        'label'       => 'Self-Employed',
                        'count'       => $ec['self'],
                        'color'       => '#2563eb',
                        'light'       => '#EFF6FF',
                        'border'      => '#BFDBFE',
                        'filter'      => 'self_employed',
                        'tooltip'     => 'View All Self-Employed',
                        'hoverBorder' => '#2563eb',
                    ],
                    [
                        'label'       => 'Unemployed',
                        'count'       => $ec['unemployed'],
                        'color'       => '#d97706',
                        'light'       => '#FFFBEB',
                        'border'      => '#FCD34D',
                        'filter'      => 'unemployed',
                        'tooltip'     => 'View All Unemployed',
                        'hoverBorder' => '#d97706',
                    ],
                    [
                        'label'       => 'No Record',
                        'count'       => $ec['noRecord'],
                        'color'       => '#374151',
                        'light'       => '#F9FAFB',
                        'border'      => '#E5E7EB',
                        'filter'      => 'no_record',
                        'tooltip'     => 'View All Without Record',
                        'hoverBorder' => '#374151',
                    ],
                ];
            @endphp

            <div class="lg:col-span-1 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-visible flex flex-col">
                <div class="px-5 py-3.5 border-b border-[#E8E0F0]" style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-briefcase text-white" style="font-size:11px;"></i>
                        </div>
                        <p class="text-sm font-semibold text-[#111111] uppercase tracking-wide">Employment Overview</p>
                    </div>
                </div>
                <div class="p-4 flex flex-col gap-3 flex-1">
                    <div class="bg-[#F9F7FC] border border-[#E8E0F0] rounded-xl p-3">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-semibold text-[#111111] uppercase tracking-wide">Submitted Employment Info</p>
                            <span class="text-xs font-semibold text-[#7A3F91]">{{ $submittedPct }}%</span>
                        </div>
                        <div class="h-2 rounded-full overflow-hidden bg-white border border-[#E8E0F0]">
                            <div class="h-full rounded-full transition-all duration-500"
                                 style="width:{{ $submittedPct }}%; background:linear-gradient(90deg,#7A3F91,#9b59b6);"></div>
                        </div>
                        <p class="text-xs text-[#333333] mt-1.5 font-normal">
                            <strong class="text-[#111111] font-semibold">{{ number_format($submitted) }}</strong> of
                            <strong class="text-[#111111] font-semibold">{{ number_format($total) }}</strong> alumni submitted
                        </p>
                    </div>
                    <div class="space-y-2">
                        @foreach($empRows as $row)
                        {{-- ✅ Each row: relative + overflow-visible para lalabas ang tooltip --}}
                        <div wire:click="openEmpModal('{{ $row['filter'] }}')"
                             class="relative overflow-visible rounded-xl border p-3 flex items-center justify-between
                                    transition-all duration-150 hover:shadow-md active:scale-[.98] cursor-pointer group"
                             style="background:{{ $row['light'] }}; border-color:{{ $row['border'] }};">

                            {{-- Tooltip — same style as stat cards, appears above --}}
                            <span class="absolute bottom-[calc(100%+8px)] left-1/2 -translate-x-1/2
                                         bg-[#1a1a1a] text-white text-[10px] font-bold tracking-wide
                                         px-[11px] py-[5px] rounded-[7px] whitespace-nowrap pointer-events-none
                                         opacity-0 group-hover:opacity-100 z-50 shadow-lg transition-opacity duration-150
                                         before:content-[''] before:absolute before:top-full before:left-1/2 before:-translate-x-1/2
                                         before:border-[5px] before:border-transparent before:border-t-[#1a1a1a]">
                                <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>{{ $row['tooltip'] }}
                            </span>

                            <span class="text-sm font-semibold text-[#111111]">{{ $row['label'] }}</span>
                            <span class="text-2xl font-semibold" style="color:{{ $row['color'] }};">{{ number_format($row['count']) }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── Alumni by Batch Year ── --}}
            @if($this->allBatches->count() > 0)
            <div class="lg:col-span-2 bg-white border border-[#E8E0F0] rounded-[14px] shadow-sm overflow-hidden flex flex-col
                        cursor-pointer transition-all duration-200 hover:shadow-[0_5px_16px_rgba(122,63,145,.11)] hover:border-[rgba(122,63,145,.28)]">
                <div class="px-[14px] py-2 border-b border-[#E8E0F0] bg-[#F5F5F5] flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-[7px]">
                        <div class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></div>
                        <span class="text-[.78rem] font-bold text-[#111111] uppercase tracking-[.06em]">Alumni by Batch Year</span>
                        <span class="text-[.68rem] text-[#555555] font-medium flex items-center gap-[3px] ml-2 pointer-events-none">
                            <i class="fas fa-hand-pointer"></i> Click bar to view alumni
                        </span>
                    </div>
                    <div id="dashBatchNavControls" class="hidden items-center gap-2">
                        <button id="dashBatchPrev"
                                class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91] text-[.75rem]
                                       cursor-pointer flex items-center justify-center
                                       hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                                       disabled:opacity-35 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                        </button>
                        <span id="dashBatchPageInfo" class="text-[.74rem] font-semibold text-[#333333] whitespace-nowrap"></span>
                        <button id="dashBatchNext"
                                class="w-7 h-7 rounded-[7px] border border-[#E8E0F0] bg-white text-[#7A3F91] text-[.75rem]
                                       cursor-pointer flex items-center justify-center
                                       hover:bg-[#F3E8FF] hover:border-[#7A3F91] transition-all duration-150
                                       disabled:opacity-35 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i>
                        </button>
                    </div>
                </div>
                <div class="p-[10px] flex-1 min-h-[200px] max-h-[400px]" wire:ignore>
                    <canvas id="dashChartBatch" style="width:100%;height:100%;"></canvas>
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

        $aTotal    = $this->alumniModalRecords->total();
        $aPp       = $this->alumniModalRecords->perPage();
        $aCp       = $this->alumniModalRecords->currentPage();
        $aLastPage = $this->alumniModalRecords->lastPage();
        $aFrom     = $aTotal > 0 ? ($aCp - 1) * $aPp + 1 : 0;
        $aTo       = min($aCp * $aPp, $aTotal);
        $aPgStart  = max(1, $aCp - 2);
        $aPgEnd    = min($aLastPage, $aCp + 2);
    @endphp
    <div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 animate-[dashPageIn_.22s_cubic-bezier(.4,0,.2,1)_both]"
         @keydown.escape.window="$wire.closeModal()">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                    <i class="fas {{ $this->alumniModalIcon }} text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-white font-semibold text-base leading-tight">{{ $this->alumniModalTitle }}</h2>
                    <p class="text-white/60 text-xs font-normal">
                        {{ number_format($this->alumniModalRecords->total()) }} record(s) found
                    </p>
                </div>
            </div>
            <div class="relative">
                <button wire:click="closeModal"
                        class="relative flex items-center justify-center w-9 h-9 rounded-[10px]
                               bg-white/[.12] border border-white/20 text-white cursor-pointer
                               hover:bg-white/[.22] transition-colors duration-150 overflow-visible group">
                    <span class="absolute top-[calc(100%+8px)] right-0
                                 bg-[rgba(27,6,46,.88)] text-white text-[10px] font-bold tracking-[.08em] uppercase
                                 px-[10px] py-1 rounded-[7px] whitespace-nowrap pointer-events-none
                                 opacity-0 group-hover:opacity-100 z-50 shadow-lg
                                 before:content-[''] before:absolute before:bottom-full before:right-[10px]
                                 before:border-[5px] before:border-transparent before:border-b-[rgba(27,6,46,.88)]">
                        Close
                    </span>
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">

            <div class="flex flex-wrap gap-3 items-center mb-3">

                <span class="text-xs font-bold tracking-widest uppercase shrink-0 px-2.5 py-1.5 rounded-lg border
                             text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0] pointer-events-none">
                    Filters
                </span>

                <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                     x-data="{ q:'', init(){ this.q = $wire.alumniModalSearch ?? ''; $wire.$watch('alumniModalSearch', v => { if(v!==this.q) this.q=v; }); } }">
                    <input type="text" x-model="q"
                           @input.debounce.300ms="$wire.set('alumniModalSearch', q)"
                           placeholder="{{ $alumniModalFilter === 'courses' ? 'Search course, name, college…' : 'Search name, ID, course, batch…' }}"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                                  focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                           autocomplete="off">
                </div>

                @if($alumniModalFilter !== 'courses')
                <div class="flex flex-wrap gap-1.5 items-center">
                    @foreach($visibleAlumniTabs as [$val, $lbl, $icon])
                    <button
                        @if($alumniTabsClickable) wire:click="$set('alumniModalFilter','{{ $val }}')" @endif
                        class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all
                               {{ $alumniModalFilter === $val
                                    ? 'text-white border-transparent bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]'
                                    : 'bg-white text-[#111111] border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                               {{ !$alumniTabsClickable ? 'cursor-default' : 'active:scale-95' }}">
                        {{ $lbl }}
                    </button>
                    @endforeach
                    @if(!$alumniTabsClickable && !$isAllNoFilter)
                    <span class="text-xs text-[#333333] font-normal ml-1">Filtered view</span>
                    @endif
                </div>
                @endif

            </div>

            @if($alumniModalFilter !== 'courses')
            <div class="flex flex-wrap gap-2 items-center">

                @if(!$alumniModalBatchLocked)
                <div class="relative"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniModalBatch', val===''?null:parseInt(val)); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()"
                            :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.alumniModalBatch !== null }"
                            class="inline-flex items-center gap-1.5 px-[11px] py-2 border border-[#E8E0F0] rounded-lg
                                   text-[.78rem] font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                                   hover:border-[#c49ed8] transition-all duration-150">
                        <span>@if($alumniModalBatch) Batch {{ $alumniModalBatch }} @else All Batch Years @endif</span>
                    </button>
                    <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-[220px] overflow-y-auto
                                    bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                                    shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1
                                    [scrollbar-width:thin] [scrollbar-color:#d4b8e8_transparent]" style="display:none;">
                        <button type="button" @click="select('')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.alumniModalBatch === null}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            All Batch Years
                        </button>
                        @foreach($this->availableModalBatches as $bYear)
                        <button type="button" @click="select('{{ $bYear }}')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.alumniModalBatch === {{ $bYear }}}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            Batch {{ $bYear }}
                        </button>
                        @endforeach
                    </div>
                </div>
                @else
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold border
                             bg-[#F9F7FC] text-[#7A3F91] border-[#7A3F91]">
                    Batch {{ $alumniModalBatch }}
                </span>
                @endif

                <div class="relative"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniModalCourseFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()"
                            :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.alumniModalCourseFilter !== '' }"
                            class="inline-flex items-center gap-1.5 px-[11px] py-2 border border-[#E8E0F0] rounded-lg
                                   text-[.78rem] font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                                   hover:border-[#c49ed8] transition-all duration-150">
                        <span>@if($alumniModalCourseFilter) {{ $alumniModalCourseFilter }} @else All Courses @endif</span>
                    </button>
                    <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-[220px] overflow-y-auto
                                    bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                                    shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1
                                    [scrollbar-width:thin] [scrollbar-color:#d4b8e8_transparent]" style="display:none;">
                        <button type="button" @click="select('')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.alumniModalCourseFilter === ''}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            All Courses
                        </button>
                        @foreach($this->availableModalCourses as $code)
                        <button type="button" @click="select('{{ $code }}')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.alumniModalCourseFilter === '{{ $code }}'}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            {{ $code }}
                        </button>
                        @endforeach
                    </div>
                </div>

                @if($alumniModalBatch || $alumniModalCourseFilter)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs text-[#333333] font-normal">Filtering by:</span>
                    @if($alumniModalBatch && !$alumniModalBatchLocked)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                            Batch {{ $alumniModalBatch }}
                        </span>
                    @endif
                    @if($alumniModalCourseFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                            {{ $alumniModalCourseFilter }}
                        </span>
                    @endif
                    @if(!$alumniModalBatchLocked || $alumniModalCourseFilter)
                    <button wire:click="clearAlumniModalFilters" class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors">
                        <span wire:loading.remove wire:target="clearAlumniModalFilters">Clear all</span>
                        <span wire:loading wire:target="clearAlumniModalFilters">Clearing…</span>
                    </button>
                    @endif
                </div>
                @endif

            </div>
            @endif

        </div>

        {{-- Table --}}
        <div class="flex-1 overflow-y-auto min-h-0" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            @if($alumniModalFilter === 'courses')
            <table class="w-full border-collapse" style="min-width:500px;">
                <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-14">#</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Course</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Course Name</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">College</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->alumniModalRecords as $idx => $course)
                    @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                    <tr class="bg-white transition-colors duration-100 hover:bg-[#F5F0FA]">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold text-[#333333]">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-sm font-mono font-bold text-[#111111]">{{ $course->code }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-sm font-semibold text-[#111111]">{{ $course->name }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-sm text-[#333333]">{{ $course->college ?? '—' }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 bg-[#f0e6f8] rounded-2xl flex items-center justify-center">
                                <i class="fas fa-book text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No courses found</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>

            @else
            <table class="w-full border-collapse" style="min-width:760px;">
                <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-14">#</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Name</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Student ID</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Course</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Batch</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->alumniModalRecords as $idx => $alumni)
                    @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                    <tr class="bg-white transition-colors duration-100 hover:bg-[#F5F0FA]">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold text-[#333333]">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                     alt="{{ $alumni->first_name }}"
                                     class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                                <p class="text-sm font-semibold text-[#111111] truncate uppercase">
                                    {{ $this->formatDisplayName($alumni->first_name??'',$alumni->middle_initial??'',$alumni->last_name??'',$alumni->suffix??'') }}
                                </p>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm font-mono font-semibold text-[#111111]">{{ $alumni->student_id }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm font-semibold text-[#111111]">{{ $alumni->course_code }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-sm font-semibold text-[#111111]">{{ $alumni->batch }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($alumni->profile_completed)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    Complete
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                    Pending
                                </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-users text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No alumni records found</p>
                            <p class="text-xs text-[#555555] font-normal">Try adjusting your search or filters</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
            @endif

        </div>

        {{-- Footer Pagination --}}
        <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;">
            <p class="text-white/70 text-sm">
                Showing <strong class="text-white font-semibold">{{ $aFrom }}–{{ $aTo }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($aTotal) }}</strong> records
            </p>
            <div class="flex items-center gap-1.5 flex-wrap">
                <button @if($aCp <= 1) disabled @endif
                        wire:click="$set('alumniModalPage', {{ max(1,$aCp-1) }})"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                               bg-white/[.15] text-white border-[1.5px] border-white/25
                               hover:enabled:bg-white/[.28] hover:enabled:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition-all duration-150">
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>
                @if($aPgStart > 1)
                    <button wire:click="$set('alumniModalPage', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                   bg-white/[.15] text-white border-[1.5px] border-white/25
                                   hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">1</button>
                    @if($aPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif
                @for($p = $aPgStart; $p <= $aPgEnd; $p++)
                    @if($p === $aCp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                     bg-white text-[#7A3F91] border-[1.5px] border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('alumniModalPage', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                       bg-white/[.15] text-white border-[1.5px] border-white/25
                                       hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">{{ $p }}</button>
                    @endif
                @endfor
                @if($aPgEnd < $aLastPage)
                    @if($aPgEnd < $aLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="$set('alumniModalPage', {{ $aLastPage }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                   bg-white/[.15] text-white border-[1.5px] border-white/25
                                   hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">{{ $aLastPage }}</button>
                @endif
                <button @if($aCp >= $aLastPage) disabled @endif
                        wire:click="$set('alumniModalPage', {{ min($aLastPage,$aCp+1) }})"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                               bg-white/[.15] text-white border-[1.5px] border-white/25
                               hover:enabled:bg-white/[.28] hover:enabled:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition-all duration-150">
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $aCp }}/{{ $aLastPage }}</span>
            </div>
        </div>

    </div>
    @endif


    {{-- ═══════════════════════════════════════════════════════════
         EMPLOYMENT FULL-SCREEN MODAL
    ═══════════════════════════════════════════════════════════ --}}
    @if($activeModal === 'employment')
    @php
        $records        = $this->empModalRecords;
        $isNoRecord     = $this->empFilter === 'no_record';
        $isUnemployed   = $this->empFilter === 'unemployed';
        $isEmployed     = $this->empFilter === 'employed';
        $isSelfEmployed = $this->empFilter === 'self_employed';

        $statusBadge = [
            'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]', 'fa-user-tie'],
            'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',      'fa-store'],
            'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',   'fa-magnifying-glass'],
        ];

        $allEmpTabs = [
            [''             , 'All',          'fa-briefcase'],
            ['employed'     , 'Employed',      'fa-user-tie'],
            ['self_employed', 'Self-Employed', 'fa-store'],
            ['unemployed'   , 'Unemployed',    'fa-magnifying-glass'],
            ['no_record'    , 'No Record',     'fa-circle-minus'],
        ];
        $empTabsLocked  = ($empFilter !== '');
        $visibleEmpTabs = $empTabsLocked
            ? array_values(array_filter($allEmpTabs, fn($t) => $t[0] === $empFilter))
            : $allEmpTabs;

        $empHasSecondaryFilter = ($empBatchFilter !== '' || $empCourseFilter !== '' || $empSearch !== '');

        $eTotal    = $records->total();
        $ePp       = $records->perPage();
        $eCp       = $records->currentPage();
        $eLastPage = $records->lastPage();
        $eFrom     = $eTotal > 0 ? ($eCp - 1) * $ePp + 1 : 0;
        $eTo       = min($eCp * $ePp, $eTotal);
        $ePgStart  = max(1, $eCp - 2);
        $ePgEnd    = min($eLastPage, $eCp + 2);

        if ($isEmployed)              $dynColLabel = 'Job Title';
        elseif ($isSelfEmployed)      $dynColLabel = 'Business Name';
        elseif ($isUnemployed || $isNoRecord) $dynColLabel = 'Contact Number';
        else                          $dynColLabel = 'Company';
    @endphp
    <div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 animate-[dashPageIn_.22s_cubic-bezier(.4,0,.2,1)_both]"
         @keydown.escape.window="$wire.closeModal()">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 lg:px-10 py-3.5 shrink-0 shadow"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                    <i class="fas {{ $this->empModalIcon }} text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-white font-semibold text-base leading-tight">{{ $this->empModalTitle }}</h2>
                    <p class="text-white/60 text-xs font-normal">
                        {{ number_format($records->total()) }} record(s) found
                    </p>
                </div>
            </div>
            <div class="relative">
                <button wire:click="closeModal"
                        class="relative flex items-center justify-center w-9 h-9 rounded-[10px]
                               bg-white/[.12] border border-white/20 text-white cursor-pointer
                               hover:bg-white/[.22] transition-colors duration-150 overflow-visible group">
                    <span class="absolute top-[calc(100%+8px)] right-0
                                 bg-[rgba(27,6,46,.88)] text-white text-[10px] font-bold tracking-[.08em] uppercase
                                 px-[10px] py-1 rounded-[7px] whitespace-nowrap pointer-events-none
                                 opacity-0 group-hover:opacity-100 z-50 shadow-lg
                                 before:content-[''] before:absolute before:bottom-full before:right-[10px]
                                 before:border-[5px] before:border-transparent before:border-b-[rgba(27,6,46,.88)]">
                        Close
                    </span>
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
            <div class="flex flex-wrap gap-3 items-center mb-3">
                <span class="text-xs font-bold tracking-widest uppercase shrink-0 px-2.5 py-1.5 rounded-lg border
                             text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0] pointer-events-none">
                    Filters
                </span>
                <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                     x-data="{ q:'', init(){ this.q = $wire.empSearch ?? ''; $wire.$watch('empSearch', v => { if(v!==this.q) this.q=v; }); } }">
                    <input type="text" x-model="q"
                           @input.debounce.300ms="$wire.set('empSearch', q)"
                           placeholder="Search name, ID, company, email…"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                                  focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                           autocomplete="off">
                </div>
                <div class="flex flex-wrap gap-1.5 items-center">
                    @foreach($visibleEmpTabs as [$val, $lbl, $icon])
                    <button
                        @if(!$empTabsLocked) wire:click="$set('empFilter','{{ $val }}')" @endif
                        class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all
                               {{ $this->empFilter === $val
                                    ? 'text-white border-transparent bg-gradient-to-br from-[#7A3F91] to-[#9b59b6]'
                                    : 'bg-white text-[#111111] border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                               {{ $empTabsLocked ? 'cursor-default' : 'active:scale-95' }}">
                        {{ $lbl }}
                    </button>
                    @endforeach
                    @if($empTabsLocked)
                    <span class="text-xs text-[#333333] font-normal ml-1">Filtered view</span>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <div class="relative"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('empBatchFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()"
                            :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.empBatchFilter !== '' }"
                            class="inline-flex items-center gap-1.5 px-[11px] py-2 border border-[#E8E0F0] rounded-lg
                                   text-[.78rem] font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                                   hover:border-[#c49ed8] transition-all duration-150">
                        <span>@if($empBatchFilter) Batch {{ $empBatchFilter }} @else All Batch Years @endif</span>
                    </button>
                    <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-[220px] overflow-y-auto
                                    bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                                    shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1" style="display:none;">
                        <button type="button" @click="select('')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.empBatchFilter === ''}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            All Batch Years
                        </button>
                        @foreach($this->availableModalBatches as $bYear)
                        <button type="button" @click="select('{{ $bYear }}')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.empBatchFilter === '{{ $bYear }}'}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            Batch {{ $bYear }}
                        </button>
                        @endforeach
                    </div>
                </div>
                <div class="relative"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('empCourseFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()"
                            :class="{ 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]': $wire.empCourseFilter !== '' }"
                            class="inline-flex items-center gap-1.5 px-[11px] py-2 border border-[#E8E0F0] rounded-lg
                                   text-[.78rem] font-semibold bg-white text-[#111111] cursor-pointer whitespace-nowrap select-none
                                   hover:border-[#c49ed8] transition-all duration-150">
                        <span>@if($empCourseFilter) {{ $empCourseFilter }} @else All Courses @endif</span>
                    </button>
                    <div x-show="open" x-transition class="absolute top-[calc(100%+4px)] left-0 min-w-full max-h-[220px] overflow-y-auto
                                    bg-white border-[1.5px] border-[#E8E0F0] rounded-[10px]
                                    shadow-[0_8px_24px_rgba(122,63,145,.13)] z-[600] p-1" style="display:none;">
                        <button type="button" @click="select('')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.empCourseFilter === ''}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            All Courses
                        </button>
                        @foreach($this->availableModalCourses as $code)
                        <button type="button" @click="select('{{ $code }}')"
                                :class="{'bg-[#F0E6F8] text-[#7A3F91]': $wire.empCourseFilter === '{{ $code }}'}"
                                class="block w-full px-[10px] py-[7px] rounded-[7px] text-[.78rem] font-semibold text-left text-[#111111]
                                       hover:bg-[#F5F0FA] hover:text-[#7A3F91] cursor-pointer border-none bg-transparent transition-colors duration-100">
                            {{ $code }}
                        </button>
                        @endforeach
                    </div>
                </div>
                @if($empHasSecondaryFilter)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs text-[#333333] font-normal">Filtering by:</span>
                    @if($empBatchFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                            <i class="fas fa-calendar text-[10px]"></i> Batch {{ $empBatchFilter }}
                        </span>
                    @endif
                    @if($empCourseFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                            <i class="fas fa-book text-[10px]"></i> {{ $empCourseFilter }}
                        </span>
                    @endif
                    @if($empSearch)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                            <i class="fas fa-search text-[10px]"></i> "{{ Str::limit($empSearch, 20) }}"
                        </span>
                    @endif
                    <button wire:click="clearEmpModalFilters" class="text-xs text-red-400 hover:text-red-600 font-semibold transition-colors">
                        <span wire:loading.remove wire:target="clearEmpModalFilters">Clear all</span>
                        <span wire:loading wire:target="clearEmpModalFilters">Clearing…</span>
                    </button>
                </div>
                @endif
            </div>
        </div>

        {{-- Table --}}
        <div class="flex-1 overflow-y-auto min-h-0" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
            <table class="w-full border-collapse" style="min-width:900px;">
                <thead class="sticky top-0 z-10 bg-[#f5f0fa]">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider w-14">#</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Alumni</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Student ID</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider">Course</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-[#111111] uppercase tracking-wider">Status</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider hidden md:table-cell">{{ $dynColLabel }}</th>
                        <th class="pl-4 pr-6 lg:pr-10 py-2.5 text-left text-xs font-semibold text-[#111111] uppercase tracking-wider hidden sm:table-cell">Email</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($records as $idx => $row)
                    @php
                        $rowNum  = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                        $badge   = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? ['Unknown','text-[#333333] bg-gray-50 border-gray-200','fa-circle']);
                        $photo   = $this->getPhotoUrl($row->profile_photo ?? null);
                        $dName   = $this->formatDisplayName(
                            $row->first_name ?? '', $row->middle_initial ?? '',
                            $row->last_name  ?? '', $row->suffix ?? ''
                        );
                        $rowStatus = $isNoRecord ? 'no_record' : ($row->employment_status ?? '');
                        if ($rowStatus === 'employed') {
                            $dynCellValue = $row->job_title ?? null;
                            $dynCellClass = 'text-sm font-semibold text-[#111111] truncate uppercase';
                        } elseif ($rowStatus === 'self_employed') {
                            $dynCellValue = $row->company_name ?? null;
                            $dynCellClass = 'text-sm font-semibold text-[#111111] truncate uppercase';
                        } elseif ($rowStatus === 'unemployed' || $rowStatus === 'no_record') {
                            $dynCellValue = $row->contact_number ?? null;
                            $dynCellClass = 'text-sm text-[#333333] font-medium';
                        } else {
                            $dynCellValue = $row->company_name ?? null;
                            $dynCellClass = 'text-sm font-semibold text-[#111111] truncate uppercase';
                        }
                    @endphp
                    <tr class="bg-white transition-colors duration-100 hover:bg-[#F5F0FA]">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold text-[#333333]">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <img src="{{ $photo }}" alt="{{ $row->first_name ?? '' }}"
                                     class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                                <p class="text-sm font-semibold text-[#111111] truncate uppercase">{{ $dName }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm font-mono font-semibold text-[#111111]">{{ $row->student_id ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm font-semibold text-[#111111]">{{ $row->course_code ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($isNoRecord)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border text-[#333333] bg-gray-50 border-gray-200">
                                    No Record
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                    {{ $badge[0] }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            @if($dynCellValue)
                                <p class="{{ $dynCellClass }}">{{ $dynCellValue }}</p>
                            @else
                                <span class="text-xs text-[#AAAAAA]">—</span>
                            @endif
                        </td>
                        <td class="pl-4 pr-6 lg:pr-10 py-3 hidden sm:table-cell">
                            <span class="text-sm text-[#333333] truncate block max-w-[200px]">
                                {{ strtolower($row->email ?? '—') }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-briefcase text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#333333]">No records found</p>
                            <p class="text-xs text-[#555555] font-normal">Try adjusting your search or filters</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Footer Pagination --}}
        <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;">
            <p class="text-white/70 text-sm">
                Showing <strong class="text-white font-semibold">{{ $eFrom }}–{{ $eTo }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($eTotal) }}</strong> records
            </p>
            <div class="flex items-center gap-1.5 flex-wrap">
                <button @if($eCp <= 1) disabled @endif
                        wire:click="$set('empModalPage', {{ max(1,$eCp-1) }})"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                               bg-white/[.15] text-white border-[1.5px] border-white/25
                               hover:enabled:bg-white/[.28] hover:enabled:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition-all duration-150">
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>
                @if($ePgStart > 1)
                    <button wire:click="$set('empModalPage', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                   bg-white/[.15] text-white border-[1.5px] border-white/25
                                   hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">1</button>
                    @if($ePgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif
                @for($p = $ePgStart; $p <= $ePgEnd; $p++)
                    @if($p === $eCp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                     bg-white text-[#7A3F91] border-[1.5px] border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('empModalPage', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                       bg-white/[.15] text-white border-[1.5px] border-white/25
                                       hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">{{ $p }}</button>
                    @endif
                @endfor
                @if($ePgEnd < $eLastPage)
                    @if($ePgEnd < $eLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="$set('empModalPage', {{ $eLastPage }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                                   bg-white/[.15] text-white border-[1.5px] border-white/25
                                   hover:bg-white/[.28] hover:border-white/50 transition-all duration-150">{{ $eLastPage }}</button>
                @endif
                <button @if($eCp >= $eLastPage) disabled @endif
                        wire:click="$set('empModalPage', {{ min($eLastPage,$eCp+1) }})"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-[10px] rounded-lg text-xs font-bold
                               bg-white/[.15] text-white border-[1.5px] border-white/25
                               hover:enabled:bg-white/[.28] hover:enabled:border-white/50
                               disabled:opacity-35 disabled:cursor-not-allowed transition-all duration-150">
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $eCp }}/{{ $eLastPage }}</span>
            </div>
        </div>

    </div>
    @endif

<style>
@keyframes dashPageIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
(function () {
    'use strict';

    var BAR_COLORS = [
        { bg: 'rgba(122,63,145,0.82)',  border: '#7A3F91' },
        { bg: 'rgba(37,99,235,0.80)',   border: '#2563eb' },
        { bg: 'rgba(16,185,129,0.80)',  border: '#10b981' },
        { bg: 'rgba(245,158,11,0.82)',  border: '#f59e0b' },
        { bg: 'rgba(239,68,68,0.80)',   border: '#ef4444' },
        { bg: 'rgba(59,130,246,0.80)',  border: '#3b82f6' },
        { bg: 'rgba(168,85,247,0.80)',  border: '#a855f7' },
        { bg: 'rgba(20,184,166,0.80)',  border: '#14b8a6' },
    ];

    var BATCH_PAGE_SIZE = 8;
    var dashBatchIndex  = 0;
    var dashBatchAll    = null;

    function readDashBatchData() {
        var el = document.getElementById('__dash_batch_data');
        if (!el) return null;
        try { return JSON.parse(el.getAttribute('data-batches') || 'null'); }
        catch (e) { return null; }
    }

    function getAlumniRoute() {
        var el = document.getElementById('__dash_batch_data');
        return el ? (el.getAttribute('data-alumni-route') || '') : '';
    }

    function sliceDashBatch(data, start) {
        var end = start + BATCH_PAGE_SIZE;
        return {
            labels: data.slice(start, end).map(function (r) { return r.batch; }),
            totals: data.slice(start, end).map(function (r) { return r.total; }),
        };
    }

    function buildDashBatchChart(data, startIdx) {
        if (!data || !data.length) return;
        var slice  = sliceDashBatch(data, startIdx);
        var canvas = document.getElementById('dashChartBatch');
        if (!canvas) return;

        if (window.Chart && Chart.getChart) {
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();
        }

        var bgColors     = slice.labels.map(function(_, i){ return BAR_COLORS[i % BAR_COLORS.length].bg; });
        var borderColors = slice.labels.map(function(_, i){ return BAR_COLORS[i % BAR_COLORS.length].border; });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: slice.labels,
                datasets: [{
                    label: 'Total Alumni',
                    data:  slice.totals,
                    backgroundColor: bgColors,
                    borderColor:     borderColors,
                    borderWidth: 1.5,
                    borderRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return 'Batch ' + items[0].label; },
                            label: function (ctx)   { return ' ' + ctx.parsed.y + ' alumni — click to view'; },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#111111' },
                    },
                    y: {
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 10, weight: '500' }, color: '#333333', precision: 0 },
                        beginAtZero: true,
                    },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var batch = slice.labels[elements[0].index];
                        if (batch === undefined || batch === null) return;
                        var baseRoute = getAlumniRoute();
                        if (!baseRoute) return;
                        window.location.href = baseRoute + '?profile_filter=all&batch=' + parseInt(batch);
                    }
                },
            },
        });

        var total      = data.length;
        var totalPages = Math.ceil(total / BATCH_PAGE_SIZE);
        var curPage    = Math.floor(startIdx / BATCH_PAGE_SIZE) + 1;
        var navEl   = document.getElementById('dashBatchNavControls');
        var prevBtn = document.getElementById('dashBatchPrev');
        var nextBtn = document.getElementById('dashBatchNext');
        var infoEl  = document.getElementById('dashBatchPageInfo');

        if (navEl && totalPages > 1) {
            navEl.classList.remove('hidden');
            navEl.classList.add('flex');
            infoEl.textContent     = curPage + ' / ' + totalPages;
            prevBtn.disabled       = (startIdx <= 0);
            nextBtn.disabled       = (startIdx + BATCH_PAGE_SIZE >= total);
        } else if (navEl) {
            navEl.classList.add('hidden');
            navEl.classList.remove('flex');
        }
    }

    function bindDashBatchNav() {
        var prevBtn = document.getElementById('dashBatchPrev');
        var nextBtn = document.getElementById('dashBatchNext');
        if (!prevBtn || !nextBtn) return;

        var newPrev = prevBtn.cloneNode(true);
        var newNext = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrev, prevBtn);
        nextBtn.parentNode.replaceChild(newNext, nextBtn);

        newPrev.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!dashBatchAll) return;
            dashBatchIndex = Math.max(0, dashBatchIndex - BATCH_PAGE_SIZE);
            buildDashBatchChart(dashBatchAll, dashBatchIndex);
            bindDashBatchNav();
        });
        newNext.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!dashBatchAll) return;
            var max = dashBatchAll.length - BATCH_PAGE_SIZE;
            dashBatchIndex = Math.min(max < 0 ? 0 : max, dashBatchIndex + BATCH_PAGE_SIZE);
            buildDashBatchChart(dashBatchAll, dashBatchIndex);
            bindDashBatchNav();
        });
    }

    function initDashCharts() {
        var data = readDashBatchData();
        if (!data || !data.length) return;

        var changed = !dashBatchAll || JSON.stringify(data.map(function(r){return r.batch;})) !== JSON.stringify(dashBatchAll.map(function(r){return r.batch;}));
        if (changed) {
            dashBatchAll   = data;
            var total      = dashBatchAll.length;
            dashBatchIndex = Math.max(0, total - BATCH_PAGE_SIZE);
        }

        buildDashBatchChart(dashBatchAll, dashBatchIndex);
        bindDashBatchNav();
    }

    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s    = document.createElement('script');
        s.src    = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    loadChartJs(function () {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                requestAnimationFrame(initDashCharts);
            });
        } else {
            requestAnimationFrame(initDashCharts);
        }

        document.addEventListener('livewire:navigated', function () {
            dashBatchAll   = null;
            dashBatchIndex = 0;
            requestAnimationFrame(initDashCharts);
        });

        function hookLivewire() {
            if (!window.Livewire) return;
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || function (cb) { cb({}); };
                if (typeof succeed === 'function') {
                    succeed(function () {
                        requestAnimationFrame(initDashCharts);
                    });
                }
            });
        }

        if (window.Livewire) { hookLivewire(); }
        else { document.addEventListener('livewire:initialized', hookLivewire); }
    });

})();
</script>

</div>