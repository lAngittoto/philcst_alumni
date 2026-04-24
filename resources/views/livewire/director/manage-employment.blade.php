{{-- resources/views/livewire/director/manage-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component {

    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterLocation  = '';
    public string $filterRelevance = '';
    public string $filterBatch     = '';
    public string $filterCourse    = '';
    public string $filterDept      = '';
    public string $sortBy          = 'a.last_name';
    public string $sortDir         = 'asc';

    // ── Modal ─────────────────────────────────────────────────────────────────
    public bool  $showModal = false;
    public array $modalData = [];

    // ── Stats ─────────────────────────────────────────────────────────────────
    public int $totalAlumni     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalAbroad     = 0;
    public int $totalLocal      = 0;

    // ── Chart Data (JSON strings) ─────────────────────────────────────────────
    public string $chartStatusData    = '{}';
    public string $chartLocationData  = '{}';
    public string $chartRelevanceData = '{}';
    public string $chartBatchData     = '{}';
    public string $chartCourseData    = '{}';

    protected $queryString = [
        'search'          => ['except' => ''],
        'filterStatus'    => ['except' => ''],
        'filterLocation'  => ['except' => ''],
        'filterRelevance' => ['except' => ''],
        'filterBatch'     => ['except' => ''],
        'filterCourse'    => ['except' => ''],
        'filterDept'      => ['except' => ''],
        'sortBy'          => ['except' => 'a.last_name'],
        'sortDir'         => ['except' => 'asc'],
    ];

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'director') {
            $this->redirect(route('login'));
            return;
        }
        $this->computeStats();
        $this->buildCharts();
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('alumni as a')->whereNull('a.deleted_at');
    }

    public function computeStats(): void
    {
        $base = $this->baseQuery();
        $this->totalAlumni = (clone $base)->count();

        $withEmp = (clone $base)
            ->join('employment_trackings as et', 'a.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        $this->totalEmployed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $this->totalNotFilled  = max(0, $this->totalAlumni - $this->totalEmployed - $this->totalSelf - $this->totalUnemployed);
        $this->totalAbroad     = (clone $withEmp)->where('et.work_location', 'abroad')->count();
        $this->totalLocal      = (clone $withEmp)->where('et.work_location', 'local')->count();
    }

    public function buildCharts(): void
    {
        $this->chartStatusData = json_encode([
            'labels' => ['Employed', 'Self-Employed', 'Unemployed', 'Not Filled'],
            'data'   => [$this->totalEmployed, $this->totalSelf, $this->totalUnemployed, $this->totalNotFilled],
            'colors' => ['#10b981','#3b82f6','#f59e0b','#d1d5db'],
        ]);

        $this->chartLocationData = json_encode([
            'labels' => ['Local', 'Abroad (OFW)'],
            'data'   => [$this->totalLocal, $this->totalAbroad],
            'colors' => ['#7a3f91','#c084fc'],
        ]);

        $relevanceRows = DB::table('employment_trackings as et')
            ->whereNull('et.deleted_at')
            ->whereNotNull('et.course_relevance')
            ->select('et.course_relevance', DB::raw('COUNT(*) as cnt'))
            ->groupBy('et.course_relevance')
            ->get()->keyBy('course_relevance');

        $this->chartRelevanceData = json_encode([
            'labels' => ['Yes', 'Partially', 'No'],
            'data'   => [
                $relevanceRows->get('yes')->cnt ?? 0,
                $relevanceRows->get('partially')->cnt ?? 0,
                $relevanceRows->get('no')->cnt ?? 0,
            ],
            'colors' => ['#10b981','#f59e0b','#ef4444'],
        ]);

        $batchRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->select(
                'a.batch',
                DB::raw("SUM(CASE WHEN et.employment_status = 'employed' THEN 1 ELSE 0 END) as employed"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'self_employed' THEN 1 ELSE 0 END) as self_emp"),
                DB::raw("SUM(CASE WHEN et.employment_status = 'unemployed' THEN 1 ELSE 0 END) as unemployed"),
                DB::raw('COUNT(a.id) as total')
            )
            ->groupBy('a.batch')
            ->orderBy('a.batch', 'desc')
            ->limit(8)
            ->get();

        $this->chartBatchData = json_encode([
            'labels'     => $batchRows->pluck('batch')->reverse()->values(),
            'employed'   => $batchRows->pluck('employed')->reverse()->values(),
            'self_emp'   => $batchRows->pluck('self_emp')->reverse()->values(),
            'unemployed' => $batchRows->pluck('unemployed')->reverse()->values(),
        ]);

        $courseRows = DB::table('alumni as a')
            ->whereNull('a.deleted_at')
            ->join('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereIn('et.employment_status', ['employed','self_employed'])
            ->select('a.course_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('a.course_code')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        $this->chartCourseData = json_encode([
            'labels' => $courseRows->pluck('course_code'),
            'data'   => $courseRows->pluck('cnt'),
        ]);
    }

    // ── Main Table Query ──────────────────────────────────────────────────────
    public function with(): array
    {
        $allowedSorts = [
            'a.last_name', 'a.batch', 'a.course_code',
            'et.employment_status', 'et.work_location', 'et.updated_at',
        ];
        $sortCol = in_array($this->sortBy, $allowedSorts) ? $this->sortBy : 'a.last_name';
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->select([
                'a.id', 'a.student_id',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.course_code', 'a.course_name', 'a.batch', 'a.gender',
                'et.employment_status', 'et.company_name', 'et.job_title',
                'et.employment_type', 'et.work_location', 'et.date_hired',
                'et.career_path', 'et.education_status', 'et.course_relevance',
                'et.unemployment_status', 'et.updated_at as emp_updated_at',
            ]);

        if ($this->search) {
            $s = '%' . $this->search . '%';
            $q->where(function ($w) use ($s) {
                $w->where(DB::raw("CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))"), 'like', $s)
                  ->orWhere('a.student_id', 'like', $s)
                  ->orWhere('et.company_name', 'like', $s)
                  ->orWhere('et.job_title', 'like', $s)
                  ->orWhere('a.course_code', 'like', $s);
            });
        }

        if ($this->filterStatus) {
            $this->filterStatus === 'not_filled'
                ? $q->whereNull('et.employment_status')
                : $q->where('et.employment_status', $this->filterStatus);
        }

        if ($this->filterLocation)  $q->where('et.work_location',   $this->filterLocation);
        if ($this->filterRelevance) $q->where('et.course_relevance', $this->filterRelevance);
        if ($this->filterBatch)     $q->where('a.batch',             $this->filterBatch);
        if ($this->filterCourse)    $q->where('a.course_code',       $this->filterCourse);

        if ($this->filterDept) {
            $codes = DB::table('courses')->where('college', $this->filterDept)->pluck('code')->toArray();
            $q->whereIn('a.course_code', $codes);
        }

        $q->orderBy($sortCol, $sortDir);
        $rows = $q->paginate(100);

        $rows->getCollection()->transform(function ($row) {
            $row->career_path_arr = $row->career_path
                ? (json_decode($row->career_path, true) ?? []) : [];
            return $row;
        });

        $batches     = DB::table('alumni')->whereNull('deleted_at')->distinct()->orderBy('batch', 'desc')->pluck('batch');
        $courses     = DB::table('courses')->orderBy('code')->get(['code', 'name']);
        $departments = DB::table('courses')->distinct()->orderBy('college')->pluck('college');

        return compact('rows', 'batches', 'courses', 'departments');
    }

    public function sortOn(string $col): void
    {
        $allowed = ['a.last_name','a.batch','a.course_code','et.employment_status','et.work_location','et.updated_at'];
        if (!in_array($col, $allowed)) return;
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function updatingSearch(): void          { $this->resetPage(); }
    public function updatingFilterStatus(): void    { $this->resetPage(); }
    public function updatingFilterLocation(): void  { $this->resetPage(); }
    public function updatingFilterRelevance(): void { $this->resetPage(); }
    public function updatingFilterBatch(): void     { $this->resetPage(); }
    public function updatingFilterCourse(): void    { $this->resetPage(); }
    public function updatingFilterDept(): void      { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterLocation =
        $this->filterRelevance = $this->filterBatch = $this->filterCourse =
        $this->filterDept = '';
        $this->resetPage();
    }

    public function viewDetail(int $alumniId): void
    {
        if ($alumniId <= 0) return;

        $row = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->where('a.id', $alumniId)
            ->select([
                'a.student_id',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.middle_initial,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.suffix','a.course_name','a.course_code','a.batch',
                'a.gender','a.civil_status','a.contact_number',
                'et.employment_status','et.company_name','et.job_title',
                'et.employment_type','et.work_location','et.date_hired',
                'et.career_path','et.education_status','et.course_relevance',
                'et.unemployment_status','et.updated_at as emp_updated_at',
            ])->first();

        if (!$row) return;

        $this->modalData = (array) $row;
        $this->modalData['career_path_arr'] = $this->modalData['career_path']
            ? (json_decode($this->modalData['career_path'], true) ?? []) : [];

        $this->showModal = true;
    }

    public function closeModal(): void { $this->showModal = false; $this->modalData = []; }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'director') abort(403);

        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->select([
                'a.student_id',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.course_code','a.batch','a.gender',
                'et.employment_status','et.company_name','et.job_title',
                'et.employment_type','et.work_location','et.date_hired',
                'et.education_status','et.course_relevance','et.updated_at as emp_updated_at',
            ]);

        if ($this->filterStatus) {
            $this->filterStatus === 'not_filled'
                ? $q->whereNull('et.employment_status')
                : $q->where('et.employment_status', $this->filterStatus);
        }
        if ($this->filterBatch)  $q->where('a.batch', $this->filterBatch);
        if ($this->filterCourse) $q->where('a.course_code', $this->filterCourse);

        $rows = $q->orderBy('a.last_name')->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Student ID','Full Name','Course','Batch','Gender',
                'Employment Status','Company','Job Title',
                'Employment Type','Work Location','Date Hired',
                'Education Status','Course Relevance','Last Updated',
            ]);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->student_id, $r->full_name, $r->course_code,
                    $r->batch, $r->gender, $r->employment_status ?? 'not_filled',
                    $r->company_name, $r->job_title, $r->employment_type,
                    $r->work_location, $r->date_hired,
                    $r->education_status, $r->course_relevance,
                    $r->emp_updated_at,
                ]);
            }
            fclose($handle);
        }, 'employment-tracking-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}; ?>

{{--
╔══════════════════════════════════════════════════════════════════════════════╗
║  FIX #1 — SINGLE ROOT ELEMENT                                               ║
║  Livewire requires exactly ONE root element. Multiple roots break morphing  ║
║  (filters, pagination, modal, sort all stop working). Everything lives      ║
║  inside this single <div>.                                                  ║
╚══════════════════════════════════════════════════════════════════════════════╝
--}}
<div>

<style>
    :root {
        --primary:    #7a3f91;
        --primary-dk: #5e2f72;
        --primary-lt: #f3e8ff;
        --ink:        #1a1523;
        --muted:      #6b7280;
        --border:     #e5e7eb;
        --surface:    #ffffff;
        --bg:         #f3f4f6;
        --emerald:    #10b981;
        --blue:       #3b82f6;
        --amber:      #f59e0b;
        --red:        #ef4444;
    }
    .stat-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 4px rgba(0,0,0,.04); transition:transform .15s,box-shadow .15s; }
    .stat-card:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(122,63,145,.10); }
    .stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .stat-number { font-size:1.7rem; font-weight:900; line-height:1; color:var(--ink); }
    .stat-label  { font-size:.85rem; font-weight:600; color:var(--muted); margin-top:2px; }
    .chart-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; box-shadow:0 1px 4px rgba(0,0,0,.04); overflow:hidden; }
    .chart-header { padding:14px 18px 10px; border-bottom:1px solid #f3f4f6; background:#fff; display:flex; align-items:center; gap:8px; }
    .chart-dot   { width:9px; height:9px; border-radius:50%; background:var(--primary); }
    .chart-title { font-size:.9rem; font-weight:800; color:var(--ink); text-transform:uppercase; letter-spacing:.06em; }
    .chart-body  { padding:16px; }
    .tbl-th { padding:13px 16px; font-size:.8rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; background:#f9fafb; white-space:nowrap; cursor:pointer; user-select:none; transition:background .1s; }
    .tbl-th:hover { background:#f3e8ff; color:var(--primary); }
    .tbl-td { padding:13px 16px; font-size:.92rem; color:var(--ink); vertical-align:middle; }
    .tbl-row { border-bottom:1px solid #f3f4f6; transition:background .1s; }
    .tbl-row:hover { background:#faf8ff; }
    .sort-arrow { font-size:.7rem; margin-left:4px; opacity:.5; }
    .badge { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:999px; font-size:.8rem; font-weight:700; }
    .badge-emp   { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
    .badge-self  { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
    .badge-unemp { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
    .badge-none  { background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb; }
    .badge-local  { background:#d1fae5; color:#065f46; }
    .badge-abroad { background:#fef9c3; color:#713f12; }
    .flt-input,.flt-select { padding:8px 14px; border:1px solid var(--border); border-radius:10px; font-size:.88rem; background:#fff; color:var(--ink); transition:border-color .15s,box-shadow .15s; outline:none; }
    .flt-input:focus,.flt-select:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(122,63,145,.10); }
    .pag-wrap { background:#2b0d3e; padding:12px 18px; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
    .pag-btn  { padding:7px 18px; border-radius:8px; font-size:.88rem; font-weight:700; background:var(--primary); color:#fff; border:none; cursor:pointer; transition:background .15s; }
    .pag-btn:hover    { background:var(--primary-dk); }
    .pag-btn:disabled { background:rgba(255,255,255,.12); color:rgba(255,255,255,.3); cursor:not-allowed; }
    .pag-info    { color:rgba(255,255,255,.75); font-size:.88rem; }
    .pag-current { padding:7px 14px; border-radius:8px; background:#fff; color:var(--ink); font-size:.88rem; font-weight:800; border:none; }
    .modal-field       { background:#f9fafb; border-radius:12px; padding:11px 14px; }
    .modal-field-label { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); margin-bottom:4px; }
    .modal-field-value { font-size:.92rem; font-weight:700; color:var(--ink); }
    .progress-bar  { height:6px; border-radius:999px; background:#e9d5ff; overflow:hidden; }
    .progress-fill { height:100%; border-radius:999px; background:var(--primary); transition:width .6s cubic-bezier(.4,0,.2,1); }
    .thin-scroll { scrollbar-width:thin; scrollbar-color:#d1d5db #f3f4f6; }
    .thin-scroll::-webkit-scrollbar       { width:5px; height:5px; }
    .thin-scroll::-webkit-scrollbar-track { background:#f3f4f6; }
    .thin-scroll::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:4px; }
    .alumni-name   { font-size:.95rem; font-weight:700; color:var(--ink); }
    .alumni-sub    { font-size:.8rem;  color:var(--muted); margin-top:1px; }
    .job-title-txt { font-size:.92rem; font-weight:700; color:var(--ink); }
    .company-txt   { font-size:.8rem;  color:var(--muted); }
</style>

{{--
╔══════════════════════════════════════════════════════════════════════════════╗
║  FIX #2 — CHART DATA IN DOM (data-* attributes)                             ║
║  Livewire morphs this element on every re-render, so data-* values are      ║
║  always fresh. The JS reads from here instead of a stale JS const.          ║
╚══════════════════════════════════════════════════════════════════════════════╝
--}}
<div id="__emp_chart_data"
     style="display:none"
     data-status="{{ $chartStatusData }}"
     data-location="{{ $chartLocationData }}"
     data-relevance="{{ $chartRelevanceData }}"
     data-batch="{{ $chartBatchData }}"
     data-course="{{ $chartCourseData }}">
</div>

{{-- ══ FLASH TOAST ══ --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8"
    x-transition:enter-end="opacity-100 translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-5 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-sm border w-full bg-white"
    :class="{'border-emerald-300 text-emerald-800':type==='success','border-red-300 text-red-800':type==='error'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-80 break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition"><i class="fas fa-xmark text-sm"></i></button>
</div>

<div class="min-h-screen" style="background:var(--bg);">
<div class="px-4 sm:px-6 lg:px-10 pt-6 pb-10 max-w-screen-2xl mx-auto">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-7">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0"
                 style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);box-shadow:0 6px 20px rgba(122,63,145,.35);">
                <i class="fas fa-chart-column text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight" style="color:var(--ink);">
                    Employment Tracking
                </h1>
                <p class="text-base mt-0.5" style="color:var(--muted);">
                    System-wide alumni employment data &amp; analytics
                </p>
            </div>
        </div>
        <button wire:click="exportCsv"
                wire:loading.attr="disabled" wire:target="exportCsv"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-md hover:opacity-90 transition active:scale-95"
                style="background:var(--primary);">
            <span wire:loading.remove wire:target="exportCsv">
                <i class="fa-solid fa-file-csv mr-1"></i> Export CSV
            </span>
            <span wire:loading wire:target="exportCsv">
                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Exporting…
            </span>
        </button>
    </div>

    {{-- ══ STAT CARDS ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3 mb-7">

        <div class="stat-card lg:col-span-1">
            <div class="stat-icon" style="background:#ede9fe;">
                <i class="fa-solid fa-users" style="color:var(--primary);font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalAlumni }}</p>
                <p class="stat-label">Total Alumni</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-briefcase" style="color:#059669;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalEmployed }}</p>
                <p class="stat-label">Employed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:64px;">
                        <div class="progress-fill" style="width:{{ round($totalEmployed/$totalAlumni*100) }}%;background:#10b981;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;">
                <i class="fa-solid fa-store" style="color:#2563eb;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalSelf }}</p>
                <p class="stat-label">Self-Employed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:64px;">
                        <div class="progress-fill" style="width:{{ round($totalSelf/$totalAlumni*100) }}%;background:#3b82f6;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;">
                <i class="fa-solid fa-circle-pause" style="color:#d97706;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalUnemployed }}</p>
                <p class="stat-label">Unemployed</p>
                @if($totalAlumni > 0)
                    <div class="progress-bar mt-1" style="width:64px;">
                        <div class="progress-fill" style="width:{{ round($totalUnemployed/$totalAlumni*100) }}%;background:#f59e0b;"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#f3f4f6;">
                <i class="fa-solid fa-circle-question" style="color:#9ca3af;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalNotFilled }}</p>
                <p class="stat-label">Not Filled</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#fef9c3;">
                <i class="fa-solid fa-plane-departure" style="color:#b45309;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalAbroad }}</p>
                <p class="stat-label">OFW / Abroad</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#d1fae5;">
                <i class="fa-solid fa-house" style="color:#059669;font-size:1.1rem;"></i>
            </div>
            <div>
                <p class="stat-number">{{ $totalLocal }}</p>
                <p class="stat-label">Local</p>
            </div>
        </div>

    </div>

    {{-- ══ CHARTS ROW ══ --}}
    {{--
        FIX #2 (continued): Chart canvases are wrapped in wire:ignore so
        Livewire never destroys/recreates them during morphing. The JS
        reads fresh data from #__emp_chart_data and re-draws on each
        Livewire commit (via the hook registered in the script below).
    --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot"></div>
                <span class="chart-title">Status Breakdown</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartStatus"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#c084fc;"></div>
                <span class="chart-title">Work Location</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartLocation"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#10b981;"></div>
                <span class="chart-title">Job-Course Relevance</span>
            </div>
            <div class="chart-body flex items-center justify-center" style="height:220px;" wire:ignore>
                <canvas id="chartRelevance"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-dot" style="background:#3b82f6;"></div>
                <span class="chart-title">Top Courses (Employed)</span>
            </div>
            <div class="chart-body" style="height:220px;" wire:ignore>
                <canvas id="chartCourse"></canvas>
            </div>
        </div>

        <div class="chart-card md:col-span-2 xl:col-span-4">
            <div class="chart-header">
                <div class="chart-dot" style="background:#f59e0b;"></div>
                <span class="chart-title">Employment by Batch Year</span>
            </div>
            <div class="chart-body" style="height:240px;" wire:ignore>
                <canvas id="chartBatch"></canvas>
            </div>
        </div>

    </div>

    {{-- ══ TABLE CARD ══ --}}
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden" style="border-color:var(--border);">

        {{-- Filter bar --}}
        <div class="px-4 sm:px-5 py-3 border-b flex flex-wrap gap-2 items-center" style="background:#f9fafb;border-color:#e5e7eb;">

            {{-- Search: wire:ignore + Alpine debounce (same pattern as organizer) --}}
            <div class="relative flex-1 min-w-[200px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search pointer-events-none"
                   style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:.75rem;color:#9ca3af;z-index:2;"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search name, ID, company…"
                       class="flt-input w-full"
                       style="padding-left:34px;position:relative;z-index:1;"
                       autocomplete="off">
            </div>

            <select wire:model.live="filterStatus" class="flt-select">
                <option value="">All Statuses</option>
                <option value="employed">Employed</option>
                <option value="self_employed">Self-Employed</option>
                <option value="unemployed">Unemployed</option>
                <option value="not_filled">Not Filled</option>
            </select>

            <select wire:model.live="filterLocation" class="flt-select">
                <option value="">All Locations</option>
                <option value="local">Local</option>
                <option value="abroad">Abroad (OFW)</option>
            </select>

            <select wire:model.live="filterRelevance" class="flt-select">
                <option value="">All Relevance</option>
                <option value="yes">Yes</option>
                <option value="partially">Partially</option>
                <option value="no">No</option>
            </select>

            @if($departments->isNotEmpty())
                <select wire:model.live="filterDept" class="flt-select">
                    <option value="">All Departments</option>
                    @foreach($departments as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                    @endforeach
                </select>
            @endif

            @if($courses->isNotEmpty())
                <select wire:model.live="filterCourse" class="flt-select">
                    <option value="">All Courses</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->code }}">{{ $c->code }}</option>
                    @endforeach
                </select>
            @endif

            @if($batches->isNotEmpty())
                <select wire:model.live="filterBatch" class="flt-select">
                    <option value="">All Batches</option>
                    @foreach($batches as $b)
                        <option value="{{ $b }}">Batch {{ $b }}</option>
                    @endforeach
                </select>
            @endif

            @if($search || $filterStatus || $filterLocation || $filterBatch || $filterCourse || $filterRelevance || $filterDept)
                <button wire:click="clearFilters"
                        class="flt-input flex items-center gap-1.5 text-sm font-semibold hover:bg-gray-100 transition"
                        style="color:var(--primary);border-color:#d1d5db;">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            @endif

        </div>

        {{-- Table --}}
        <div class="relative"
             wire:loading.class="opacity-40 pointer-events-none"
             wire:target="search,filterStatus,filterLocation,filterBatch,filterCourse,filterRelevance,filterDept,clearFilters,sortOn,previousPage,nextPage">

            <div class="thin-scroll overflow-x-auto" style="max-height:calc(100vh - 480px);overflow-y:auto;">
                <table class="w-full border-collapse" style="min-width:860px;">
                    <thead>
                        <tr style="border-bottom:2px solid #f0eaf8;position:sticky;top:0;z-index:10;">
                            <th class="tbl-th" wire:click="sortOn('a.last_name')">
                                Alumni
                                <span class="sort-arrow">{{ $sortBy === 'a.last_name' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th" wire:click="sortOn('a.course_code')">
                                Course
                                <span class="sort-arrow">{{ $sortBy === 'a.course_code' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th hidden md:table-cell" wire:click="sortOn('a.batch')">
                                Batch
                                <span class="sort-arrow">{{ $sortBy === 'a.batch' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th">Company / Job Title</th>
                            <th class="tbl-th hidden lg:table-cell" wire:click="sortOn('et.work_location')">
                                Location
                                <span class="sort-arrow">{{ $sortBy === 'et.work_location' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th text-center" wire:click="sortOn('et.employment_status')">
                                Status
                                <span class="sort-arrow">{{ $sortBy === 'et.employment_status' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th hidden xl:table-cell">Relevance</th>
                            <th class="tbl-th hidden lg:table-cell" wire:click="sortOn('et.updated_at')">
                                Updated
                                <span class="sort-arrow">{{ $sortBy === 'et.updated_at' ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </th>
                            <th class="tbl-th text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        @php
                            $badgeCls = match($row->employment_status) {
                                'employed'      => 'badge-emp',
                                'self_employed' => 'badge-self',
                                'unemployed'    => 'badge-unemp',
                                default         => 'badge-none',
                            };
                            $badgeLbl = match($row->employment_status) {
                                'employed'      => '<i class="fa-solid fa-briefcase" style="font-size:.7rem;"></i> Employed',
                                'self_employed' => '<i class="fa-solid fa-store" style="font-size:.7rem;"></i> Self-Emp.',
                                'unemployed'    => '<i class="fa-solid fa-circle-pause" style="font-size:.7rem;"></i> Unemployed',
                                default         => '<i class="fa-solid fa-circle-question" style="font-size:.7rem;"></i> Not Filled',
                            };
                            $relMap = [
                                'yes'       => ['Yes',     'bg-emerald-100 text-emerald-800'],
                                'no'        => ['No',      'bg-red-100 text-red-800'],
                                'partially' => ['Partial', 'bg-amber-100 text-amber-800'],
                            ];
                        @endphp
                        <tr class="tbl-row">

                            <td class="tbl-td">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 flex items-center justify-center text-white text-sm font-bold"
                                         style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);">
                                        {{ strtoupper(substr($row->full_name, 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="alumni-name truncate">{{ $row->full_name }}</p>
                                        <p class="alumni-sub">{{ $row->student_id }}</p>
                                    </div>
                                </div>
                            </td>

                            <td class="tbl-td">
                                <span class="badge" style="background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;font-size:.82rem;">
                                    {{ $row->course_code ?? '—' }}
                                </span>
                            </td>

                            <td class="tbl-td hidden md:table-cell" style="color:var(--muted);font-weight:600;">
                                {{ $row->batch ?? '—' }}
                            </td>

                            <td class="tbl-td">
                                @if($row->job_title || $row->company_name)
                                    <p class="job-title-txt">{{ $row->job_title ?? '—' }}</p>
                                    <p class="company-txt">{{ $row->company_name ?? '' }}</p>
                                @elseif($row->employment_status === 'unemployed')
                                    <span style="font-size:.85rem;font-style:italic;color:var(--muted);">
                                        {{ ['seeking_employment'=>'Seeking','not_looking'=>'Not Looking'][$row->unemployment_status ?? ''] ?? '—' }}
                                    </span>
                                @else
                                    <span style="font-size:.85rem;font-style:italic;color:#d1d5db;">No data</span>
                                @endif
                            </td>

                            <td class="tbl-td hidden lg:table-cell">
                                @if($row->work_location === 'abroad')
                                    <span class="badge badge-abroad"><i class="fa-solid fa-plane-departure" style="font-size:.7rem;"></i> Abroad</span>
                                @elseif($row->work_location === 'local')
                                    <span class="badge badge-local"><i class="fa-solid fa-house" style="font-size:.7rem;"></i> Local</span>
                                @else
                                    <span style="color:#d1d5db;font-size:.88rem;">—</span>
                                @endif
                            </td>

                            <td class="tbl-td text-center">
                                <span class="badge {{ $badgeCls }}">{!! $badgeLbl !!}</span>
                            </td>

                            <td class="tbl-td hidden xl:table-cell">
                                @if(isset($relMap[$row->course_relevance ?? '']))
                                    <span class="badge {{ $relMap[$row->course_relevance][1] }}">{{ $relMap[$row->course_relevance][0] }}</span>
                                @else
                                    <span style="color:#d1d5db;font-size:.88rem;">—</span>
                                @endif
                            </td>

                            <td class="tbl-td hidden lg:table-cell" style="color:var(--muted);font-size:.85rem;">
                                @if($row->emp_updated_at)
                                    {{ \Carbon\Carbon::parse($row->emp_updated_at)->diffForHumans() }}
                                @else
                                    <span style="color:#d1d5db;">—</span>
                                @endif
                            </td>

                            <td class="tbl-td text-center">
                                <button wire:click="viewDetail({{ $row->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-bold rounded-lg transition"
                                        style="background:var(--primary-lt);color:var(--primary);border:1px solid #ddd6fe;"
                                        onmouseover="this.style.background='#ede9fe'"
                                        onmouseout="this.style.background='var(--primary-lt)'">
                                    <i class="fa-solid fa-eye" style="font-size:.75rem;"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>
                            </td>

                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center" style="background:#f3e8ff;">
                                        <i class="fas fa-users-slash text-2xl" style="color:#c4b5fd;"></i>
                                    </div>
                                    <p class="font-semibold text-base" style="color:var(--muted);">No alumni found</p>
                                    <p class="text-sm" style="color:#9ca3af;">Try adjusting your search or filters.</p>
                                    <button wire:click="clearFilters"
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm border font-medium transition hover:bg-gray-50"
                                            style="border-color:var(--border);color:var(--muted);">
                                        <i class="fas fa-rotate-left text-xs"></i> Reset Filters
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pagination --}}
        <div class="pag-wrap">
            @php
                $total = $rows->total();
                $pp    = $rows->perPage();
                $cp    = $rows->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="pag-info">
                Showing <strong class="text-white">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white">{{ $total }}</strong> alumni
            </p>
            <div class="flex items-center gap-1.5">
                @if($rows->onFirstPage())
                    <button disabled class="pag-btn">← Prev</button>
                @else
                    <button wire:click="previousPage" wire:loading.attr="disabled" wire:target="previousPage" class="pag-btn">← Prev</button>
                @endif
                <span class="pag-current">{{ $cp }} / {{ $rows->lastPage() }}</span>
                @if($rows->hasMorePages())
                    <button wire:click="nextPage" wire:loading.attr="disabled" wire:target="nextPage" class="pag-btn">Next →</button>
                @else
                    <button disabled class="pag-btn">Next →</button>
                @endif
            </div>
        </div>

    </div>{{-- /TABLE CARD --}}
</div>
</div>{{-- /page wrapper --}}

{{-- ══ DETAIL MODAL ══ --}}
{{--
    FIX #3 — Modal is now INSIDE the single root <div>.
    Previously it sat outside the root div, so Livewire couldn't morph it
    and $showModal / $modalData changes were invisible to the DOM.
--}}
@if($showModal && !empty($modalData))
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5"
     style="background:rgba(0,0,0,.55);backdrop-filter:blur(4px);"
     @keydown.escape.window="$wire.closeModal()">

    <div class="bg-white rounded-2xl w-full max-w-xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:92vh;"
         x-data
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 flex-shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#4c1d6e);">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center font-bold text-base flex-shrink-0"
                     style="background:rgba(255,255,255,.2);color:#fff;">
                    {{ strtoupper(substr($modalData['full_name'] ?? '?', 0, 1)) }}
                </div>
                <div>
                    <p class="font-bold text-white text-base">
                        {{ $modalData['full_name'] ?? '—' }}
                        @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
                    </p>
                    <p class="text-sm" style="color:rgba(255,255,255,.65);">{{ $modalData['student_id'] ?? '—' }}</p>
                </div>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl flex items-center justify-center transition text-white"
                    style="background:rgba(255,255,255,.18);"
                    onmouseover="this.style.background='rgba(255,255,255,.28)'"
                    onmouseout="this.style.background='rgba(255,255,255,.18)'">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-5 thin-scroll">

            {{-- Student Info --}}
            <div>
                <p class="text-xs font-bold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:var(--muted);">
                    <i class="fa-solid fa-user-graduate" style="color:#a78bfa;"></i> Student Information
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                    @foreach([
                        'Course'       => ($modalData['course_code'] ?? '—') . ' — ' . ($modalData['course_name'] ?? ''),
                        'Batch'        => $modalData['batch']          ?? '—',
                        'Gender'       => $modalData['gender']         ?? '—',
                        'Civil Status' => $modalData['civil_status']   ?? '—',
                        'Contact'      => $modalData['contact_number'] ?? '—',
                    ] as $lbl => $val)
                        <div class="modal-field">
                            <div class="modal-field-label">{{ $lbl }}</div>
                            <div class="modal-field-value">{{ $val ?: '—' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Employment Info --}}
            @php
                $md        = $modalData;
                $isEmp     = in_array($md['employment_status'] ?? '', ['employed','self_employed']);
                $statusLbl = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'][$md['employment_status'] ?? ''] ?? 'Not Filled';
                $statusCls = match($md['employment_status'] ?? '') {
                    'employed'      => 'badge-emp',
                    'self_employed' => 'badge-self',
                    'unemployed'    => 'badge-unemp',
                    default         => 'badge-none',
                };
                $empTypeMap = ['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'];
                $eduMap     = ['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'];
                $careerLabels = [
                    'ofw'                   => ['fa-plane-departure','OFW'],
                    'freelancer'            => ['fa-laptop-code','Freelancer'],
                    'entrepreneur'          => ['fa-store','Entrepreneur'],
                    'career_shifter'        => ['fa-arrows-rotate','Career Shifter'],
                    'industry_professional' => ['fa-user-tie','Industry Professional'],
                ];
            @endphp

            <div style="border-top:1px solid #f3f4f6;padding-top:16px;">
                <p class="text-xs font-bold uppercase tracking-widest mb-3 flex items-center gap-2" style="color:var(--muted);">
                    <i class="fa-solid fa-briefcase" style="color:#a78bfa;"></i> Employment Information
                </p>

                <div class="flex items-center gap-2 mb-4">
                    <span class="badge {{ $statusCls }}">{{ $statusLbl }}</span>
                    @if($md['emp_updated_at'] ?? null)
                        <span class="text-sm" style="color:var(--muted);">
                            <i class="fa-regular fa-clock mr-0.5"></i>
                            Updated {{ \Carbon\Carbon::parse($md['emp_updated_at'])->diffForHumans() }}
                        </span>
                    @endif
                </div>

                @if($isEmp)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        @foreach([
                            ['Company',    $md['company_name']  ?? '—'],
                            ['Job Title',  $md['job_title']     ?? '—'],
                            ['Type',       $empTypeMap[$md['employment_type'] ?? ''] ?? '—'],
                            ['Location',   ucfirst($md['work_location'] ?? '—')],
                            ['Date Hired', $md['date_hired'] ? \Carbon\Carbon::parse($md['date_hired'])->format('M d, Y') : '—'],
                            ['Education',  $eduMap[$md['education_status'] ?? ''] ?? '—'],
                            ['Relevance',  ['yes'=>'Yes','no'=>'No','partially'=>'Partially'][$md['course_relevance'] ?? ''] ?? '—'],
                        ] as [$lbl, $val])
                            <div class="modal-field">
                                <div class="modal-field-label">{{ $lbl }}</div>
                                <div class="modal-field-value">{{ $val }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if(!empty($md['career_path_arr']))
                        <div class="mt-3">
                            <p class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--muted);">Career Path</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($md['career_path_arr'] as $cp)
                                    @if(isset($careerLabels[$cp]))
                                        <span class="badge" style="background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;">
                                            <i class="fa-solid {{ $careerLabels[$cp][0] }}" style="font-size:.7rem;"></i>
                                            {{ $careerLabels[$cp][1] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                @elseif(($md['employment_status'] ?? '') === 'unemployed')
                    <div class="rounded-xl px-4 py-3" style="background:#fef3c7;border:1px solid #fcd34d;">
                        <p class="text-xs font-bold uppercase tracking-wider mb-1" style="color:#d97706;">Unemployment Status</p>
                        <p class="text-sm font-semibold" style="color:#92400e;">
                            {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$md['unemployment_status'] ?? ''] ?? '—' }}
                        </p>
                    </div>
                @else
                    <div class="rounded-xl px-4 py-5 text-center" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <i class="fa-solid fa-circle-question text-3xl mb-2 block" style="color:#d1d5db;"></i>
                        <p class="text-sm italic" style="color:#9ca3af;">Alumni has not submitted employment information yet.</p>
                    </div>
                @endif
            </div>

        </div>

        {{-- Footer --}}
        <div class="px-5 py-4 flex justify-end flex-shrink-0" style="border-top:1px solid #f3f4f6;background:#f9fafb;">
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold border transition hover:bg-gray-100"
                    style="border-color:var(--border);color:var(--muted);">
                <i class="fa-solid fa-xmark text-xs"></i> Close
            </button>
        </div>

    </div>
</div>
@endif

{{--
╔══════════════════════════════════════════════════════════════════════════════╗
║  FIX #2 (script) — CHART INITIALISATION                                     ║
║                                                                             ║
║  Strategy:                                                                  ║
║  1. Chart.js CDN is loaded with a guard so it is never double-injected.    ║
║  2. readData() always pulls from #__emp_chart_data data-* attributes,      ║
║     which Livewire morphs on every re-render — so values are always fresh. ║
║  3. Chart canvases sit inside wire:ignore containers so Livewire never     ║
║     destroys them; we destroy-and-recreate Chart instances ourselves.      ║
║  4. initAllCharts() is called:                                              ║
║     • once on DOMContentLoaded / immediate rAF                             ║
║     • after every Livewire commit (filter, sort, paginate all trigger this) ║
║     • after Livewire SPA navigation                                         ║
╚══════════════════════════════════════════════════════════════════════════════╝
--}}
<script>
(function () {
    'use strict';

    // ── Load Chart.js once ──────────────────────────────────────────────────
    function loadChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    // ── Read chart data from morphed DOM element ────────────────────────────
    function readData() {
        var el = document.getElementById('__emp_chart_data');
        if (!el) return null;
        try {
            return {
                status:    JSON.parse(el.getAttribute('data-status')    || 'null'),
                location:  JSON.parse(el.getAttribute('data-location')  || 'null'),
                relevance: JSON.parse(el.getAttribute('data-relevance') || 'null'),
                batch:     JSON.parse(el.getAttribute('data-batch')     || 'null'),
                course:    JSON.parse(el.getAttribute('data-course')    || 'null'),
            };
        } catch (e) { return null; }
    }

    // ── Instance registry (destroy before recreate) ─────────────────────────
    var registry = {};
    function destroyChart(id) {
        if (registry[id]) { registry[id].destroy(); delete registry[id]; }
    }

    // ── Donut helper ────────────────────────────────────────────────────────
    function buildDonut(id, data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById(id);
        if (!canvas) return;
        destroyChart(id);
        registry[id] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 7,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12, weight: '700' }, color: '#374151', padding: 12, usePointStyle: true, pointStyleWidth: 9 },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    }

    // ── Stacked bar (batch) ─────────────────────────────────────────────────
    function buildBatchBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartBatch');
        if (!canvas) return;
        destroyChart('chartBatch');
        registry['chartBatch'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Employed',      data: data.employed,   backgroundColor: '#10b981', borderRadius: 4, stack: 'a' },
                    { label: 'Self-Employed', data: data.self_emp,   backgroundColor: '#3b82f6', borderRadius: 4, stack: 'a' },
                    { label: 'Unemployed',    data: data.unemployed, backgroundColor: '#f59e0b', borderRadius: 4, stack: 'a' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { font: { size: 12, weight: '700' }, color: '#374151', padding: 14, usePointStyle: true },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 12, weight: '600' }, color: '#6b7280' } },
                    y: { stacked: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                },
            },
        });
    }

    // ── Horizontal bar (top courses) ────────────────────────────────────────
    function buildCourseBar(data) {
        if (!data || !data.labels) return;
        var canvas = document.getElementById('chartCourse');
        if (!canvas) return;
        destroyChart('chartCourse');
        registry['chartCourse'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Employed Alumni',
                    data: data.data,
                    backgroundColor: '#7a3f91cc',
                    borderColor: '#7a3f91',
                    borderWidth: 1,
                    borderRadius: 5,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.parsed.x + ' alumni'; } } },
                },
                scales: {
                    x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af', precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { font: { size: 12, weight: '700' }, color: '#374151' } },
                },
            },
        });
    }

    // ── Master init (always reads fresh data from DOM) ──────────────────────
    function initAllCharts() {
        var d = readData();
        if (!d) return;
        buildDonut('chartStatus',    d.status);
        buildDonut('chartLocation',  d.location);
        buildDonut('chartRelevance', d.relevance);
        buildBatchBar(d.batch);
        buildCourseBar(d.course);
    }

    // ── Boot: load Chart.js then wire up listeners ──────────────────────────
    loadChartJs(function () {

        // 1) Initial page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { requestAnimationFrame(initAllCharts); });
        } else {
            requestAnimationFrame(initAllCharts);
        }

        // 2) After Livewire SPA navigation
        document.addEventListener('livewire:navigated', function () { requestAnimationFrame(initAllCharts); });

        // 3) After every Livewire component commit (filter, sort, paginate)
        //    Uses the Livewire v3 hook API for reliable post-morph timing.
        if (window.Livewire) {
            Livewire.hook('commit', function (payload) {
                var succeed = payload.succeed || (payload.component && payload.respond);
                if (typeof succeed === 'function') {
                    succeed(function () { requestAnimationFrame(initAllCharts); });
                } else {
                    // Fallback for slightly different hook signatures
                    requestAnimationFrame(initAllCharts);
                }
            });
        } else {
            // If Livewire hasn't initialised yet, wait for it
            document.addEventListener('livewire:initialized', function () {
                Livewire.hook('commit', function (payload) {
                    var succeed = payload.succeed || function (cb) { cb({}); };
                    succeed(function () { requestAnimationFrame(initAllCharts); });
                });
            });
        }
    });

})();
</script>

</div>{{-- ═══ END SINGLE ROOT ELEMENT ═══ --}}