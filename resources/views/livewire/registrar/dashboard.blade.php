{{-- resources/views/livewire/registrar/dashboard.blade.php --}}

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

{{-- ═══════════════════════════════════════════════════════════
     SINGLE ROOT ELEMENT — required by Livewire
═══════════════════════════════════════════════════════════ --}}
<div
    @dash-open-alumni.window="$wire.openAlumniModal($event.detail.filter ?? 'all', $event.detail.batch ?? null)"
    @dash-open-emp.window="$wire.openEmpModal($event.detail.filter ?? '')">

    {{-- Batch data bridge for JS chart (moved INSIDE root div) --}}
    <div id="__dash_batch_data" style="display:none"
         data-batches="{{ $this->allBatches->toJson() }}">
    </div>

    <style>
        /* ── Animations ──────────────────────────────────────────── */
        @keyframes dashPageIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .dash-modal-enter { animation: dashPageIn .22s cubic-bezier(.4,0,.2,1) both; }

        /* ── Stat cards ──────────────────────────────────────────── */
        .dash-stat-card {
            position: relative;
            overflow: visible;
            cursor: pointer;
            transition: box-shadow .18s ease, border-color .18s ease, transform .12s ease;
        }
        .dash-stat-card:active { transform: scale(.985); }

        /* ── Black hover tooltip (above card) ────────────────────── */
        .dash-hover-tip {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a1a;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .05em;
            padding: 5px 11px;
            border-radius: 7px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity .15s ease;
            z-index: 200;
            box-shadow: 0 4px 14px rgba(0,0,0,.30);
        }
        .dash-hover-tip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #1a1a1a;
        }
        .dash-stat-card:hover .dash-hover-tip { opacity: 1; }

        /* ── Emp row hover tip (on employment overview rows) ─────── */
        .dash-emp-row {
            position: relative;
            cursor: pointer;
            overflow: visible;
            transition: box-shadow .15s, border-color .15s, transform .10s;
        }
        .dash-emp-row:active { transform: scale(.98); }
        .dash-emp-row .dash-hover-tip { left: 50%; }
        .dash-emp-row:hover .dash-hover-tip { opacity: 1; }

        /* ── Pagination buttons ──────────────────────────────────── */
        .dash-pg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 700;
            transition: all .15s;
            border: 1.5px solid transparent;
        }
        .dash-pg-active { background: rgba(255,255,255,1); color: #7A3F91; border-color: rgba(255,255,255,1); }
        .dash-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
        .dash-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
        .dash-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

        /* ── Close button — matches employment tracking ───────────── */
        .dash-close-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: #fff;
            cursor: pointer;
            transition: background .15s;
            overflow: visible;
        }
        .dash-close-btn:hover { background: rgba(255,255,255,.22); }
        .dash-close-tip {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: rgba(27, 6, 46, 0.88);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 7px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity .15s ease;
            z-index: 200;
            box-shadow: 0 4px 12px rgba(0,0,0,.28);
        }
        .dash-close-tip::before {
            content: '';
            position: absolute;
            bottom: 100%;
            right: 10px;
            border: 5px solid transparent;
            border-bottom-color: rgba(27, 6, 46, 0.88);
        }
        .dash-close-btn:hover .dash-close-tip { opacity: 1; }

        /* ── Filter chip ─────────────────────────────────────────── */
        .filter-chip-active {
            background: linear-gradient(135deg, #7A3F91, #9b59b6);
            color: #fff;
            border-color: transparent;
        }

        /* ── Custom dropdown ─────────────────────────────────────── */
        .dash-dropdown { position: relative; }
        .dash-dropdown-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            min-width: 100%;
            max-height: 220px;
            overflow-y: auto;
            background: #fff;
            border: 1.5px solid #E8E0F0;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(122,63,145,.13);
            z-index: 600;
            padding: 4px;
            scrollbar-width: thin;
            scrollbar-color: #d4b8e8 transparent;
        }
        .dash-dropdown-menu::-webkit-scrollbar { width: 5px; }
        .dash-dropdown-menu::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

        .dash-dropdown-item {
            display: block;
            width: 100%;
            padding: 7px 10px;
            border-radius: 7px;
            font-size: .78rem;
            font-weight: 600;
            text-align: left;
            color: #333;
            transition: background .1s;
            cursor: pointer;
            white-space: nowrap;
            border: none;
            background: transparent;
        }
        .dash-dropdown-item:hover { background: #F5F0FA; color: #7A3F91; }
        .dash-dropdown-item.active { background: #F0E6F8; color: #7A3F91; }

        .dash-dropdown-trigger {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 11px;
            border: 1.5px solid #E8E0F0;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 600;
            background: #fff;
            color: #555;
            cursor: pointer;
            transition: border-color .15s, background .15s, color .15s;
            white-space: nowrap;
            user-select: none;
        }
        .dash-dropdown-trigger:hover { border-color: #c49ed8; }
        .dash-dropdown-trigger.has-value { border-color: #7A3F91; background: #F9F7FC; color: #7A3F91; }
        .dash-dropdown-trigger .dash-chevron { transition: transform .18s; font-size: .62rem; opacity: .6; }
        .dash-dropdown-trigger.open .dash-chevron { transform: rotate(180deg); }

        /* ── Batch chart card — matches employment tracking style ─── */
        .dash-chart-card {
            background: #fff;
            border: 1px solid #E8E0F0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            transition: box-shadow .18s, border-color .18s;
        }
        .dash-chart-card:hover { box-shadow: 0 5px 16px rgba(122,63,145,.11); border-color: rgba(122,63,145,.28); }
        .dash-chart-header {
            padding: 8px 14px;
            border-bottom: 1px solid #E8E0F0;
            background: #F5F5F5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .dash-chart-header-left { display: flex; align-items: center; gap: 7px; }
        .dash-chart-dot { width: 8px; height: 8px; border-radius: 50%; background: #7a3f91; flex-shrink: 0; }
        .dash-chart-title { font-size: .78rem; font-weight: 700; color: #333333; text-transform: uppercase; letter-spacing: .06em; }
        .dash-chart-hint { font-size: .68rem; color: #bbb; font-weight: 500; display: flex; align-items: center; gap: 3px; pointer-events: none; }
        .dash-chart-body { padding: 10px; flex: 1; min-height: 0; }

        /* ── Batch nav buttons — matches employment tracking ─────── */
        .dash-batch-nav-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #E8E0F0;
            background: #fff;
            color: #7A3F91;
            font-size: .75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .15s, border-color .15s;
            flex-shrink: 0;
        }
        .dash-batch-nav-btn:hover:not(:disabled) { background: #F3E8FF; border-color: #7A3F91; }
        .dash-batch-nav-btn:disabled { opacity: .35; cursor: not-allowed; }
        .dash-batch-page-info { font-size: .74rem; font-weight: 600; color: #666; white-space: nowrap; }

        /* ── Table row hover — subtle ────────────────────────────── */
        .dash-table-row { transition: background .10s; }
        .dash-table-row:hover { background: #F5F0FA !important; }
    </style>


    {{-- ═══════════════════════════════════════════════════════════
         PAGE CONTENT
    ═══════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-4 pb-4 max-w-screen-2xl mx-auto">

        {{-- PAGE HEADER --}}
        <div class="flex items-center gap-3 mb-5">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-gauge-high text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold text-[#333333] leading-tight">Registrar Dashboard</h1>
                <p class="text-sm text-[#666666] font-normal">{{ now()->format('l, F j, Y') }}</p>
            </div>
        </div>

        {{-- ─── STAT CARDS ─────────────────────────────────────── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

            {{-- Total Alumni --}}
            <div wire:click="openAlumniModal('all')"
                 class="dash-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden
                        hover:shadow-md hover:border-[#7A3F91]/40">
                <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View All Alumni</span>
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
                 class="dash-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden
                        hover:shadow-md hover:border-emerald-300">
                <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Complete Profiles</span>
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
                 class="dash-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden
                        hover:shadow-md hover:border-amber-300">
                <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Pending Profiles</span>
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
                 class="dash-stat-card bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 overflow-hidden
                        hover:shadow-md hover:border-blue-300">
                <span class="dash-hover-tip"><i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Active Courses</span>
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
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4" style="align-items:stretch;">

            {{-- Employment Overview --}}
            @php
                $ec           = $this->empCounts;
                $submitted    = $ec['submitted'];
                $total        = $this->totalAlumni;
                $submittedPct = $total > 0 ? round(($submitted / $total) * 100) : 0;
                $empRows = [
                    ['label'=>'Employed',      'count'=>$ec['employed'],   'icon'=>'fa-user-tie',        'color'=>'#7A3F91','light'=>'#F9F7FC','border'=>'#E8E0F0','filter'=>'employed',      'tip'=>'View Employed Alumni'],
                    ['label'=>'Self-Employed', 'count'=>$ec['self'],       'icon'=>'fa-store',           'color'=>'#2563eb','light'=>'#EFF6FF','border'=>'#BFDBFE','filter'=>'self_employed', 'tip'=>'View Self-Employed Alumni'],
                    ['label'=>'Unemployed',    'count'=>$ec['unemployed'], 'icon'=>'fa-magnifying-glass','color'=>'#d97706','light'=>'#FFFBEB','border'=>'#FCD34D','filter'=>'unemployed',    'tip'=>'View Unemployed Alumni'],
                    ['label'=>'No Record',     'count'=>$ec['noRecord'],   'icon'=>'fa-circle-minus',    'color'=>'#6B7280','light'=>'#F9FAFB','border'=>'#E5E7EB','filter'=>'no_record',     'tip'=>'View No Employment Record'],
                ];
            @endphp

            <div class="lg:col-span-1 bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-visible flex flex-col">
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
                        <div class="dash-emp-row rounded-xl border p-3 transition-all duration-150 hover:shadow-md"
                             style="background:{{ $row['light'] }}; border-color:{{ $row['border'] }};"
                             wire:click="openEmpModal('{{ $row['filter'] }}')">
                            <span class="dash-hover-tip">
                                <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>{{ $row['tip'] }}
                            </span>
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

            {{-- ── Alumni by Batch Year — Chart.js (matches Employment Tracking style) ── --}}
            @if($this->allBatches->count() > 0)
            <div class="lg:col-span-2 dash-chart-card">
                <div class="dash-chart-header">
                    <div class="dash-chart-header-left">
                        <div class="dash-chart-dot" style="background:#f59e0b;"></div>
                        <span class="dash-chart-title">Alumni by Batch Year</span>
                        <span class="dash-chart-hint ml-2">
                            <i class="fas fa-hand-pointer"></i> Click bar
                        </span>
                    </div>
                    <div id="dashBatchNavControls" class="flex items-center gap-2" style="display:none!important;">
                        <button id="dashBatchPrev" class="dash-batch-nav-btn">
                            <i class="fa-solid fa-chevron-left" style="font-size:.60rem;"></i>
                        </button>
                        <span id="dashBatchPageInfo" class="dash-batch-page-info"></span>
                        <button id="dashBatchNext" class="dash-batch-nav-btn">
                            <i class="fa-solid fa-chevron-right" style="font-size:.60rem;"></i>
                        </button>
                    </div>
                </div>
                <div class="dash-chart-body" style="flex:1;min-height:200px;max-height:400px;" wire:ignore>
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
    <div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
         @keydown.escape.window="$wire.closeModal()">

        {{-- ─── Header ──────────────────────────────────────────── --}}
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
            <button wire:click="closeModal" class="dash-close-btn">
                <span class="dash-close-tip">Close</span>
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- ─── Toolbar ─────────────────────────────────────────── --}}
        <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">

            <div class="flex flex-wrap gap-3 items-center mb-3">

                <span class="text-xs font-bold tracking-widest uppercase shrink-0 px-2.5 py-1.5 rounded-lg border"
                      style="color:#7A3F91;background:#F9F7FC;border-color:#E8E0F0;pointer-events:none;">
                    <i class="fas fa-filter text-[10px] mr-1"></i>Filters
                </span>

                {{-- Search --}}
                <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                     x-data="{ q:'', init(){ this.q = $wire.alumniModalSearch ?? ''; $wire.$watch('alumniModalSearch', v => { if(v!==this.q) this.q=v; }); } }">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" x-model="q"
                           @input.debounce.300ms="$wire.set('alumniModalSearch', q)"
                           placeholder="{{ $alumniModalFilter === 'courses' ? 'Search course, name, college…' : 'Search name, ID, course, batch…' }}"
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                                  focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                           autocomplete="off">
                </div>

                {{-- Profile filter tabs --}}
                @if($alumniModalFilter !== 'courses')
                <div class="flex flex-wrap gap-1.5 items-center">
                    @foreach($visibleAlumniTabs as [$val, $lbl, $icon])
                    <button
                        @if($alumniTabsClickable) wire:click="$set('alumniModalFilter','{{ $val }}')" @endif
                        class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all flex items-center gap-1.5
                               {{ $alumniModalFilter === $val ? 'filter-chip-active' : 'bg-white text-gray-600 border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                               {{ !$alumniTabsClickable ? 'cursor-default' : 'active:scale-95' }}">
                        <i class="fas {{ $icon }} text-[10px]"></i>{{ $lbl }}
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

            @if($alumniModalFilter !== 'courses')
            <div class="flex flex-wrap gap-2 items-center">

                @if(!$alumniModalBatchLocked)
                <div class="dash-dropdown"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniModalBatch', val===''?null:parseInt(val)); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()" :class="{'has-value':$wire.alumniModalBatch!==null,'open':open}" class="dash-dropdown-trigger">
                        <i class="fas fa-calendar-alt" style="font-size:.68rem;opacity:.7;"></i>
                        <span>@if($alumniModalBatch) Batch {{ $alumniModalBatch }} @else All Batch Years @endif</span>
                        <i class="fas fa-chevron-down dash-chevron"></i>
                    </button>
                    <div x-show="open" x-transition class="dash-dropdown-menu" style="display:none;">
                        <button type="button" @click="select('')" :class="{'active':$wire.alumniModalBatch===null}" class="dash-dropdown-item">All Batch Years</button>
                        @foreach($this->availableModalBatches as $bYear)
                        <button type="button" @click="select('{{ $bYear }}')" :class="{'active':$wire.alumniModalBatch=={{ $bYear }}}" class="dash-dropdown-item">Batch {{ $bYear }}</button>
                        @endforeach
                    </div>
                </div>
                @else
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold border"
                      style="background:#F9F7FC;color:#7A3F91;border-color:#7A3F91;">
                    <i class="fas fa-calendar-check text-[10px]"></i>Batch {{ $alumniModalBatch }}
                    <i class="fas fa-lock text-[9px] opacity-50 ml-0.5"></i>
                </span>
                @endif

                <div class="dash-dropdown"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniModalCourseFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()" :class="{'has-value':$wire.alumniModalCourseFilter!=='','open':open}" class="dash-dropdown-trigger">
                        <i class="fas fa-book-open" style="font-size:.68rem;opacity:.7;"></i>
                        <span>@if($alumniModalCourseFilter) {{ $alumniModalCourseFilter }} @else All Courses @endif</span>
                        <i class="fas fa-chevron-down dash-chevron"></i>
                    </button>
                    <div x-show="open" x-transition class="dash-dropdown-menu" style="display:none;">
                        <button type="button" @click="select('')" :class="{'active':$wire.alumniModalCourseFilter===''}" class="dash-dropdown-item">All Courses</button>
                        @foreach($this->availableModalCourses as $code)
                        <button type="button" @click="select('{{ $code }}')" :class="{'active':$wire.alumniModalCourseFilter==='{{ $code }}'}" class="dash-dropdown-item">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>

                @if($alumniModalBatch || $alumniModalCourseFilter)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs text-gray-400 font-normal">Filtering by:</span>
                    @if($alumniModalBatch && !$alumniModalBatchLocked)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                            <i class="fas fa-calendar text-[10px]"></i> Batch {{ $alumniModalBatch }}
                        </span>
                    @endif
                    @if($alumniModalCourseFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                            <i class="fas fa-book text-[10px]"></i> {{ $alumniModalCourseFilter }}
                        </span>
                    @endif
                    @if(!$alumniModalBatchLocked || $alumniModalCourseFilter)
                    <button wire:click="clearAlumniModalFilters" class="text-xs text-red-400 hover:text-red-600 font-semibold transition">
                        <span wire:loading.remove wire:target="clearAlumniModalFilters">Clear all</span>
                        <span wire:loading wire:target="clearAlumniModalFilters">Clearing…</span>
                    </button>
                    @endif
                </div>
                @endif

            </div>
            @endif

        </div>

        {{-- ─── Table ── --}}
        <div class="flex-1 overflow-y-auto min-h-0" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">

            @if($alumniModalFilter === 'courses')
            <table class="w-full border-collapse" style="min-width:500px;">
                <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Course</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Course Name</th>
                        <th class="px-5 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">College</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->alumniModalRecords as $idx => $course)
                    @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                    <tr class="dash-table-row bg-white">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold text-gray-400">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-mono font-bold border"
                                  style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">{{ $course->code }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-sm font-semibold text-gray-800">{{ $course->name }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-sm text-gray-500">{{ $course->college ?? '—' }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 bg-[#f0e6f8] rounded-2xl flex items-center justify-center">
                                <i class="fas fa-book text-xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-400">No courses found</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>

            @else
            {{-- Alumni Table --}}
            <table class="w-full border-collapse" style="min-width:760px;">
                <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Student ID</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Course</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Batch</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->alumniModalRecords as $idx => $alumni)
                    @php $rowNum = ($this->alumniModalRecords->currentPage() - 1) * $this->alumniModalRecords->perPage() + $idx + 1; @endphp
                    <tr class="dash-table-row bg-white">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                     alt="{{ $alumni->first_name }}"
                                     class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                                <p class="text-sm font-semibold text-gray-900 truncate uppercase">
                                    {{ $this->formatDisplayName($alumni->first_name??'',$alumni->middle_initial??'',$alumni->last_name??'',$alumni->suffix??'') }}
                                </p>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-mono font-bold border"
                                  style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                                {{ $alumni->student_id }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm text-gray-700">{{ $alumni->course_code }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-sm font-semibold text-gray-800">{{ $alumni->batch }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($alumni->profile_completed)
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    <i class="fas fa-circle-check text-[10px]"></i> Complete
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                    <i class="fas fa-clock text-[10px]"></i> Pending
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
                            <p class="text-sm font-semibold text-gray-400">No alumni records found</p>
                            <p class="text-xs text-gray-300 font-normal">Try adjusting your search or filters</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
            @endif

        </div>

        {{-- ─── Footer Pagination ── --}}
        <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;">
            <p class="text-white/70 text-sm">
                Showing <strong class="text-white font-semibold">{{ $aFrom }}–{{ $aTo }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($aTotal) }}</strong> records
            </p>
            @if($aLastPage > 1)
            <div class="flex items-center gap-1.5 flex-wrap">
                <button @if($aCp <= 1) disabled @endif
                        wire:click="$set('alumniModalPage', {{ max(1,$aCp-1) }})"
                        class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>

                @if($aPgStart > 1)
                    <button wire:click="$set('alumniModalPage', 1)" class="dash-pg-btn dash-pg-nav">1</button>
                    @if($aPgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif

                @for($p = $aPgStart; $p <= $aPgEnd; $p++)
                    @if($p === $aCp)
                        <span class="dash-pg-btn dash-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="$set('alumniModalPage', {{ $p }})" class="dash-pg-btn dash-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($aPgEnd < $aLastPage)
                    @if($aPgEnd < $aLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="$set('alumniModalPage', {{ $aLastPage }})" class="dash-pg-btn dash-pg-nav">{{ $aLastPage }}</button>
                @endif

                <button @if($aCp >= $aLastPage) disabled @endif
                        wire:click="$set('alumniModalPage', {{ min($aLastPage,$aCp+1) }})"
                        class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>

                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $aCp }}/{{ $aLastPage }}</span>
            </div>
            @endif
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
        $isUnemployed = $this->empFilter === 'unemployed';
        $isEmployed   = $this->empFilter === 'employed';
        $isSelfEmployed = $this->empFilter === 'self_employed';

        $statusBadge = [
            'employed'      => ['Employed',      'text-[#7A3F91] bg-[#F9F7FC] border-[#E8E0F0]', 'fa-user-tie'],
            'self_employed' => ['Self-Employed',  'text-blue-700 bg-blue-50 border-blue-200',       'fa-store'],
            'unemployed'    => ['Unemployed',     'text-amber-700 bg-amber-50 border-amber-200',    'fa-magnifying-glass'],
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

        $empHasSecondaryFilter = ($empBatchFilter !== '' || $empCourseFilter !== '' || $empSearch !== '');

        $eTotal    = $records->total();
        $ePp       = $records->perPage();
        $eCp       = $records->currentPage();
        $eLastPage = $records->lastPage();
        $eFrom     = $eTotal > 0 ? ($eCp - 1) * $ePp + 1 : 0;
        $eTo       = min($eCp * $ePp, $eTotal);
        $ePgStart  = max(1, $eCp - 2);
        $ePgEnd    = min($eLastPage, $eCp + 2);

        // ── Dynamic column header label & icon based on active filter ──
        // "All" view: show Company column (mixed statuses)
        // employed      → Job Title
        // self_employed → Business Name
        // unemployed    → Contact Number
        // no_record     → Contact Number
        if ($isEmployed) {
            $dynColLabel = 'Job Title';
        } elseif ($isSelfEmployed) {
            $dynColLabel = 'Business Name';
        } elseif ($isUnemployed || $isNoRecord) {
            $dynColLabel = 'Contact Number';
        } else {
            $dynColLabel = 'Company';
        }
    @endphp
    <div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dash-modal-enter"
         @keydown.escape.window="$wire.closeModal()">

        {{-- ─── Header ──────────────────────────────────────────── --}}
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
            <button wire:click="closeModal" class="dash-close-btn">
                <span class="dash-close-tip">Close</span>
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- ─── Toolbar ─────────────────────────────────────────── --}}
        <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">

            <div class="flex flex-wrap gap-3 items-center mb-3">

                <span class="text-xs font-bold tracking-widest uppercase shrink-0 px-2.5 py-1.5 rounded-lg border"
                      style="color:#7A3F91;background:#F9F7FC;border-color:#E8E0F0;pointer-events:none;">
                    <i class="fas fa-filter text-[10px] mr-1"></i>Filters
                </span>

                {{-- Search --}}
                <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                     x-data="{ q:'', init(){ this.q = $wire.empSearch ?? ''; $wire.$watch('empSearch', v => { if(v!==this.q) this.q=v; }); } }">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" x-model="q"
                           @input.debounce.300ms="$wire.set('empSearch', q)"
                           placeholder="Search name, ID, company, email…"
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900
                                  focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition-all"
                           autocomplete="off">
                </div>

                <div class="flex flex-wrap gap-1.5 items-center">
                    @foreach($visibleEmpTabs as [$val, $lbl, $icon])
                    <button
                        @if(!$empTabsLocked) wire:click="$set('empFilter','{{ $val }}')" @endif
                        class="px-3 py-2 rounded-lg text-xs font-semibold border transition-all flex items-center gap-1.5
                               {{ $this->empFilter === $val ? 'filter-chip-active' : 'bg-white text-gray-600 border-gray-200 hover:border-[#d4aaeb] hover:text-[#7A3F91]' }}
                               {{ $empTabsLocked ? 'cursor-default' : 'active:scale-95' }}">
                        <i class="fas {{ $icon }} text-[10px]"></i>{{ $lbl }}
                    </button>
                    @endforeach
                    @if($empTabsLocked)
                    <span class="flex items-center gap-1 text-xs text-gray-400 font-normal ml-1">
                        <i class="fas fa-lock text-gray-300 text-[10px]"></i> Filtered view
                    </span>
                    @endif
                </div>

            </div>

            <div class="flex flex-wrap gap-2 items-center">

                <div class="dash-dropdown"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('empBatchFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()" :class="{'has-value':$wire.empBatchFilter!=='','open':open}" class="dash-dropdown-trigger">
                        <i class="fas fa-calendar-alt" style="font-size:.68rem;opacity:.7;"></i>
                        <span>@if($empBatchFilter) Batch {{ $empBatchFilter }} @else All Batch Years @endif</span>
                        <i class="fas fa-chevron-down dash-chevron"></i>
                    </button>
                    <div x-show="open" x-transition class="dash-dropdown-menu" style="display:none;">
                        <button type="button" @click="select('')" :class="{'active':$wire.empBatchFilter===''}" class="dash-dropdown-item">All Batch Years</button>
                        @foreach($this->availableModalBatches as $bYear)
                        <button type="button" @click="select('{{ $bYear }}')" :class="{'active':$wire.empBatchFilter==='{{ $bYear }}'}" class="dash-dropdown-item">Batch {{ $bYear }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="dash-dropdown"
                     x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('empCourseFilter',val); this.close(); } }"
                     @click.outside="close()">
                    <button type="button" @click="toggle()" :class="{'has-value':$wire.empCourseFilter!=='','open':open}" class="dash-dropdown-trigger">
                        <i class="fas fa-book-open" style="font-size:.68rem;opacity:.7;"></i>
                        <span>@if($empCourseFilter) {{ $empCourseFilter }} @else All Courses @endif</span>
                        <i class="fas fa-chevron-down dash-chevron"></i>
                    </button>
                    <div x-show="open" x-transition class="dash-dropdown-menu" style="display:none;">
                        <button type="button" @click="select('')" :class="{'active':$wire.empCourseFilter===''}" class="dash-dropdown-item">All Courses</button>
                        @foreach($this->availableModalCourses as $code)
                        <button type="button" @click="select('{{ $code }}')" :class="{'active':$wire.empCourseFilter==='{{ $code }}'}" class="dash-dropdown-item">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>

                @if($empHasSecondaryFilter)
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs text-gray-400 font-normal">Filtering by:</span>
                    @if($empBatchFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                            <i class="fas fa-calendar text-[10px]"></i> Batch {{ $empBatchFilter }}
                        </span>
                    @endif
                    @if($empCourseFilter)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                            <i class="fas fa-book text-[10px]"></i> {{ $empCourseFilter }}
                        </span>
                    @endif
                    @if($empSearch)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                            <i class="fas fa-search text-[10px]"></i> "{{ Str::limit($empSearch, 20) }}"
                        </span>
                    @endif
                    <button wire:click="clearEmpModalFilters" class="text-xs text-red-400 hover:text-red-600 font-semibold transition">
                        <span wire:loading.remove wire:target="clearEmpModalFilters">Clear all</span>
                        <span wire:loading wire:target="clearEmpModalFilters">Clearing…</span>
                    </button>
                </div>
                @endif

            </div>

        </div>

        {{-- ─── Table ── --}}
        <div class="flex-1 overflow-y-auto min-h-0" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
            <table class="w-full border-collapse" style="min-width:900px;">
                <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                    <tr class="border-b-2 border-[#E8E0F0]">
                        <th class="pl-6 lg:pl-10 pr-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-14">#</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alumni</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Student ID</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Course</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">{{ $dynColLabel }}</th>
                        <th class="pl-4 pr-6 lg:pr-10 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Email</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($records as $idx => $row)
                    @php
                        $rowNum  = ($records->currentPage() - 1) * $records->perPage() + $idx + 1;
                        $badge   = $isNoRecord ? null : ($statusBadge[$row->employment_status] ?? ['Unknown','text-gray-600 bg-gray-50 border-gray-200','fa-circle']);
                        $photo   = $this->getPhotoUrl($row->profile_photo ?? null);
                        $dName   = $this->formatDisplayName(
                            $row->first_name ?? '', $row->middle_initial ?? '',
                            $row->last_name  ?? '', $row->suffix ?? ''
                        );

                        // Determine the dynamic column value per row
                        $rowStatus = $isNoRecord ? 'no_record' : ($row->employment_status ?? '');
                        if ($rowStatus === 'employed') {
                            $dynCellValue = $row->job_title ?? null;
                            $dynCellEmpty = '—';
                            $dynCellClass = 'text-sm font-semibold text-[#333333] truncate uppercase';
                        } elseif ($rowStatus === 'self_employed') {
                            $dynCellValue = $row->company_name ?? null;
                            $dynCellEmpty = '—';
                            $dynCellClass = 'text-sm font-semibold text-[#333333] truncate uppercase';
                        } elseif ($rowStatus === 'unemployed' || $rowStatus === 'no_record') {
                            $dynCellValue = $row->contact_number ?? null;
                            $dynCellEmpty = '—';
                            $dynCellClass = 'text-sm text-[#555555] font-medium';
                        } else {
                            // Mixed "All" view — show company_name
                            $dynCellValue = $row->company_name ?? null;
                            $dynCellEmpty = '—';
                            $dynCellClass = 'text-sm font-semibold text-[#333333] truncate uppercase';
                        }
                    @endphp
                    <tr class="dash-table-row bg-white">
                        <td class="pl-6 lg:pl-10 pr-3 py-3">
                            <span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($rowNum,2,'0',STR_PAD_LEFT) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <img src="{{ $photo }}" alt="{{ $row->first_name ?? '' }}"
                                     class="w-8 h-8 rounded-xl object-cover ring-1 ring-[#E8E0F0] shrink-0">
                                <p class="text-sm font-semibold text-[#333333] truncate uppercase">{{ $dName }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-mono font-bold border"
                                  style="background:#F9F7FC;color:#7A3F91;border-color:#E8E0F0;">
                                {{ $row->student_id ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm font-semibold text-[#333333]">{{ $row->course_code ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($isNoRecord)
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200">
                                    <i class="fas fa-circle-minus text-[10px]"></i> No Record
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border {{ $badge[1] }}">
                                    <i class="fas {{ $badge[2] }} text-[10px]"></i> {{ $badge[0] }}
                                </span>
                            @endif
                        </td>
                        {{-- ── Dynamic column cell ── --}}
                        <td class="px-4 py-3 hidden md:table-cell">
                            @if($dynCellValue)
                                <p class="{{ $dynCellClass }}">{{ $dynCellValue }}</p>
                            @else
                                <span class="text-xs text-[#CCCCCC]">{{ $dynCellEmpty }}</span>
                            @endif
                        </td>
                        <td class="pl-4 pr-6 lg:pr-10 py-3 hidden sm:table-cell">
                            <span class="text-sm text-[#555555] truncate block max-w-[200px]">
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
                            <p class="text-sm font-semibold text-gray-400">No records found</p>
                            <p class="text-xs text-gray-300 font-normal">Try adjusting your search or filters</p>
                        </div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ─── Footer Pagination ── --}}
        <div class="px-4 py-2.5 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;">
            <p class="text-white/70 text-sm">
                Showing <strong class="text-white font-semibold">{{ $eFrom }}–{{ $eTo }}</strong>
                of <strong class="text-white font-semibold">{{ number_format($eTotal) }}</strong> records
            </p>
            @if($eLastPage > 1)
            <div class="flex items-center gap-1.5 flex-wrap">
                <button @if($eCp <= 1) disabled @endif
                        wire:click="$set('empModalPage', {{ max(1,$eCp-1) }})"
                        class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>

                @if($ePgStart > 1)
                    <button wire:click="$set('empModalPage', 1)" class="dash-pg-btn dash-pg-nav">1</button>
                    @if($ePgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif

                @for($p = $ePgStart; $p <= $ePgEnd; $p++)
                    @if($p === $eCp)
                        <span class="dash-pg-btn dash-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="$set('empModalPage', {{ $p }})" class="dash-pg-btn dash-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($ePgEnd < $eLastPage)
                    @if($ePgEnd < $eLastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="$set('empModalPage', {{ $eLastPage }})" class="dash-pg-btn dash-pg-nav">{{ $eLastPage }}</button>
                @endif

                <button @if($eCp >= $eLastPage) disabled @endif
                        wire:click="$set('empModalPage', {{ min($eLastPage,$eCp+1) }})"
                        class="dash-pg-btn dash-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>

                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $eCp }}/{{ $eLastPage }}</span>
            </div>
            @endif
        </div>

    </div>
    @endif

</div>{{-- END single root element --}}

{{-- ══ BATCH CHART SCRIPT ══ --}}
<script>
(function () {
    'use strict';

    var BATCH_PAGE_SIZE = 8;
    var dashBatchIndex  = 0;
    var dashBatchAll    = null;

    function readDashBatchData() {
        var el = document.getElementById('__dash_batch_data');
        if (!el) return null;
        try { return JSON.parse(el.getAttribute('data-batches') || 'null'); }
        catch (e) { return null; }
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

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: slice.labels,
                datasets: [{
                    label: 'Total Alumni',
                    data:  slice.totals,
                    backgroundColor: '#9b59b6cc',
                    borderColor:     '#7A3F91',
                    borderWidth: 1,
                    borderRadius: 4,
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
                            label: function (ctx)   { return ' ' + ctx.parsed.y + ' alumni'; },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#666666' },
                    },
                    y: {
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 10 }, color: '#9ca3af', precision: 0 },
                        beginAtZero: true,
                    },
                },
                onClick: function (event, elements) {
                    if (event && event.native) event.native.stopPropagation();
                    if (elements && elements.length) {
                        var batch = slice.labels[elements[0].index];
                        if (batch === undefined || batch === null) return;
                        window.dispatchEvent(new CustomEvent('dash-open-alumni', {
                            detail: { filter: 'all', batch: parseInt(batch) }
                        }));
                    }
                },
            },
        });

        // ── Update nav controls ──
        var total      = data.length;
        var totalPages = Math.ceil(total / BATCH_PAGE_SIZE);
        var curPage    = Math.floor(startIdx / BATCH_PAGE_SIZE) + 1;
        var navEl   = document.getElementById('dashBatchNavControls');
        var prevBtn = document.getElementById('dashBatchPrev');
        var nextBtn = document.getElementById('dashBatchNext');
        var infoEl  = document.getElementById('dashBatchPageInfo');

        if (navEl && totalPages > 1) {
            navEl.style.display    = 'flex';
            infoEl.textContent     = curPage + ' / ' + totalPages;
            prevBtn.disabled       = (startIdx <= 0);
            nextBtn.disabled       = (startIdx + BATCH_PAGE_SIZE >= total);
        } else if (navEl) {
            navEl.style.display = 'none';
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
            // Show most recent batches first (data is ordered asc, start from end)
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