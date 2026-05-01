{{-- resources/views/livewire/organizer/alumni-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    use WithPagination;

    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterLocation  = '';
    public string $filterRelevance = '';
    public string $filterBatch     = '';
    public string $filterCourse    = '';

    public string $organizerBatch      = '';
    public string $organizerDepartment = '';
    public string $organizerName       = '';
    public array  $allowedCourseCodes  = [];

    public int $totalAlumni     = 0;
    public int $totalEmployed   = 0;
    public int $totalSelf       = 0;
    public int $totalUnemployed = 0;
    public int $totalNotFilled  = 0;
    public int $totalLocal      = 0;
    public int $totalOFW        = 0;

    public bool  $showModal = false;
    public array $modalData = [];

    protected $queryString = [
        'search'          => ['except' => ''],
        'filterStatus'    => ['except' => ''],
        'filterLocation'  => ['except' => ''],
        'filterRelevance' => ['except' => ''],
        'filterBatch'     => ['except' => ''],
        'filterCourse'    => ['except' => ''],
    ];

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'organizer') {
            $this->redirect(route('login'));
            return;
        }

        $organizer = DB::table('organizer')
            ->where('user_id', $user->id)
            ->select(['batch', 'department'])
            ->first();

        $this->organizerBatch      = $organizer->batch      ?? '';
        $this->organizerDepartment = $organizer->department ?? '';
        $this->organizerName       = trim($user->name ?? '');

        if ($this->organizerDepartment) {
            $this->allowedCourseCodes = DB::table('courses')
                ->where('college', $this->organizerDepartment)
                ->pluck('code')
                ->toArray();
        }

        $this->computeStats();
    }

    public function computeStats(): void
    {
        $base = $this->baseAlumniQuery();

        $this->totalAlumni = (clone $base)->count();

        $withEmp = (clone $base)
            ->join('employment_trackings as et', 'alumni.id', '=', 'et.alumni_id')
            ->whereNull('et.deleted_at');

        $this->totalEmployed   = (clone $withEmp)->where('et.employment_status', 'employed')->count();
        $this->totalSelf       = (clone $withEmp)->where('et.employment_status', 'self_employed')->count();
        $this->totalUnemployed = (clone $withEmp)->where('et.employment_status', 'unemployed')->count();
        $this->totalNotFilled  = max(0,
            $this->totalAlumni - $this->totalEmployed - $this->totalSelf - $this->totalUnemployed
        );

        $this->totalLocal = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'local')
            ->count();

        $this->totalOFW = (clone $withEmp)
            ->whereIn('et.employment_status', ['employed', 'self_employed'])
            ->where('et.work_location', 'abroad')
            ->count();
    }

    private function baseAlumniQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('alumni')->whereNull('alumni.deleted_at');

        if ($this->organizerBatch) {
            $q->where('alumni.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $q->whereIn('alumni.course_code', $this->allowedCourseCodes);
        }

        return $q;
    }

    private function getAlumniPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png')) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        }
        return asset('storage/alumni-photos/default.png');
    }

    public function with(): array
    {
        $q = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->select([
                'a.id',
                'a.student_id',
                'a.profile_photo',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.course_code',
                'a.course_name',
                'a.batch',
                'et.employment_status',
                'et.company_name',
                'et.job_title',
                'et.employment_type',
                'et.work_location',
                'et.date_hired',
                'et.career_path',
                'et.education_status',
                'et.course_relevance',
                'et.unemployment_status',
                'et.updated_at as emp_updated_at',
            ]);

        if ($this->organizerBatch) {
            $q->where('a.batch', $this->organizerBatch);
        }
        if (!empty($this->allowedCourseCodes)) {
            $q->whereIn('a.course_code', $this->allowedCourseCodes);
        }

        if ($this->search) {
            $s = '%' . $this->search . '%';
            $q->where(function ($w) use ($s) {
                $w->where(DB::raw("CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,''))"), 'like', $s)
                  ->orWhere('a.student_id', 'like', $s)
                  ->orWhere('et.company_name', 'like', $s)
                  ->orWhere('et.job_title', 'like', $s);
            });
        }

        if ($this->filterStatus) {
            $this->filterStatus === 'not_filled'
                ? $q->whereNull('et.employment_status')
                : $q->where('et.employment_status', $this->filterStatus);
        }
        if ($this->filterLocation)  $q->where('et.work_location',    $this->filterLocation);
        if ($this->filterRelevance) $q->where('et.course_relevance', $this->filterRelevance);
        if ($this->filterBatch)     $q->where('a.batch',             $this->filterBatch);
        if ($this->filterCourse)    $q->where('a.course_code',       $this->filterCourse);

        $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
          ->orderBy('a.last_name');

        $rows = $q->paginate(20);

        $rows->getCollection()->transform(function ($row) {
            $row->career_path_arr = $row->career_path
                ? (json_decode($row->career_path, true) ?? []) : [];
            return $row;
        });

        $batches = DB::table('alumni')
            ->whereNull('deleted_at')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('course_code', $this->allowedCourseCodes))
            ->when($this->organizerBatch,
                fn($q) => $q->where('batch', $this->organizerBatch))
            ->distinct()->orderBy('batch', 'desc')->pluck('batch');

        $courses = DB::table('courses')
            ->when(!empty($this->allowedCourseCodes),
                fn($q) => $q->whereIn('code', $this->allowedCourseCodes))
            ->orderBy('code')
            ->get(['code', 'name']);

        return compact('rows', 'batches', 'courses');
    }

    public function updatingSearch(): void          { $this->resetPage(); }
    public function updatingFilterStatus(): void    { $this->resetPage(); }
    public function updatingFilterLocation(): void  { $this->resetPage(); }
    public function updatingFilterRelevance(): void { $this->resetPage(); }
    public function updatingFilterBatch(): void     { $this->resetPage(); }
    public function updatingFilterCourse(): void    { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterLocation =
        $this->filterRelevance = $this->filterBatch = $this->filterCourse = '';
        $this->resetPage();
    }

    public function viewDetail(int $alumniId): void
    {
        $row = DB::table('alumni as a')
            ->leftJoin('employment_trackings as et', function ($j) {
                $j->on('a.id', '=', 'et.alumni_id')->whereNull('et.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->where('a.id', $alumniId)
            ->select([
                'a.student_id',
                'a.profile_photo',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.middle_initial,''), ' ', COALESCE(a.last_name,''))) AS full_name"),
                'a.suffix', 'a.course_name', 'a.course_code', 'a.batch',
                'a.gender', 'a.civil_status', 'a.contact_number',
                'et.employment_status','et.company_name','et.job_title',
                'et.employment_type','et.work_location','et.date_hired',
                'et.career_path','et.education_status','et.course_relevance',
                'et.unemployment_status','et.updated_at as emp_updated_at',
            ])->first();

        if (!$row) return;

        if ($this->organizerBatch && $row->batch !== $this->organizerBatch) return;
        if (!empty($this->allowedCourseCodes) && !in_array($row->course_code, $this->allowedCourseCodes)) return;

        $this->modalData = (array) $row;
        $this->modalData['career_path_arr'] = $this->modalData['career_path']
            ? (json_decode($this->modalData['career_path'], true) ?? []) : [];

        $this->showModal = true;
    }

    public function closeModal(): void { $this->showModal = false; $this->modalData = []; }

}; ?>

<div>

{{-- ══ FLASH TOAST ══ --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
    :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-red-300 text-red-800':type==='error','bg-white border-blue-300 text-blue-800':type==='info'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══ PAGE WRAPPER — 90VH FLEX COLUMN ══ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-2 max-w-screen-2xl mx-auto" style="height:90vh;">

    {{-- ══ PAGE HEADER ══ --}}
    <div class="flex items-center gap-3 mb-4 shrink-0">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:#7A3F91;">
            <i class="fas fa-chart-line text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-3xl font-semibold text-[#333333] leading-tight">Employment Tracking</h1>
            <p class="text-sm text-[#666666] font-normal mt-0.5 flex flex-wrap items-center gap-2">
                Track employment status of your assigned alumni.
                @if($organizerDepartment)
                    <span class="inline-flex items-center gap-1 font-semibold text-[#7A3F91]">
                        <i class="fa-solid fa-building-columns text-xs"></i>
                        {{ $organizerDepartment }}
                    </span>
                @endif
                @if($organizerBatch)
                    <span class="inline-flex items-center gap-1 font-semibold text-[#7A3F91]">
                        <i class="fa-solid fa-calendar text-xs"></i>
                        Batch {{ $organizerBatch }}
                    </span>
                @endif
            </p>
        </div>
    </div>

    {{-- ══ STAT CARDS — 7 cards ══ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-4 shrink-0">

        {{-- Total --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow"
                     style="background:#7A3F91;">
                    <i class="fa-solid fa-users text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">All</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalAlumni }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Total Alumni</p>
        </div>

        {{-- Employed --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-briefcase text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 uppercase">Work</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalEmployed }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Employed</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full"
                         style="width:{{ min(($totalEmployed / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

        {{-- Self-Employed --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-store text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase">Self</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalSelf }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Self-Employed</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-blue-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 rounded-full"
                         style="width:{{ min(($totalSelf / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

        {{-- Unemployed --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center shadow">
                    <i class="fa-solid fa-circle-pause text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase">Idle</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalUnemployed }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Unemployed</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-amber-100 rounded-full overflow-hidden">
                    <div class="h-full bg-amber-400 rounded-full"
                         style="width:{{ min(($totalUnemployed / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

        {{-- Not Filled --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-gray-400 flex items-center justify-center shadow">
                    <i class="fa-solid fa-circle-question text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-[#666666] border border-gray-200 uppercase">N/A</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalNotFilled }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Not Filled</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                    <div class="h-full bg-gray-400 rounded-full"
                         style="width:{{ min(($totalNotFilled / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

        {{-- Local --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-teal-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-house text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 border border-teal-100 uppercase">PH</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalLocal }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Local</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-teal-100 rounded-full overflow-hidden">
                    <div class="h-full bg-teal-500 rounded-full"
                         style="width:{{ min(($totalLocal / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

        {{-- OFW --}}
        <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm p-4 relative overflow-hidden hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-orange-500 flex items-center justify-center shadow">
                    <i class="fa-solid fa-plane-departure text-white text-base"></i>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 border border-orange-100 uppercase">OFW</span>
            </div>
            <p class="text-3xl font-semibold text-[#333333] leading-none">{{ $totalOFW }}</p>
            <p class="text-sm text-[#666666] mt-1 font-normal">Abroad (OFW)</p>
            @if($totalAlumni > 0)
                <div class="mt-2 h-1.5 bg-orange-100 rounded-full overflow-hidden">
                    <div class="h-full bg-orange-500 rounded-full"
                         style="width:{{ min(($totalOFW / max($totalAlumni,1)) * 100, 100) }}%;"></div>
                </div>
            @endif
        </div>

    </div>

    {{-- ══ TABLE CARD — fills remaining height ══ --}}
    <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm flex flex-col overflow-hidden flex-1 min-h-0">

        {{-- ── Filter Bar ── --}}
        <div class="px-4 sm:px-5 py-3 border-b border-[#E8E0F0] flex flex-wrap gap-2 items-center bg-[#F9F7FC] shrink-0">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] sm:min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search name, ID, company or job…"
                       class="w-full pl-9 pr-4 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] transition focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100"
                       autocomplete="off">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Statuses</option>
                <option value="employed">Employed</option>
                <option value="self_employed">Self-Employed</option>
                <option value="unemployed">Unemployed</option>
                <option value="not_filled">Not Filled</option>
            </select>

            {{-- Location --}}
            <select wire:model.live="filterLocation"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Locations</option>
                <option value="local">Local</option>
                <option value="abroad">Abroad (OFW)</option>
            </select>

            {{-- Course Relevance --}}
            <select wire:model.live="filterRelevance"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Relevance</option>
                <option value="yes">Related to Course</option>
                <option value="partially">Partially Related</option>
                <option value="no">Not Related</option>
            </select>

            {{-- Course --}}
            @if($courses->isNotEmpty())
                <select wire:model.live="filterCourse"
                        class="px-3 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100 transition">
                    <option value="">All Courses</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->code }}">{{ $c->code }}</option>
                    @endforeach
                </select>
            @endif

            {{-- Batch --}}
            @if($batches->isNotEmpty())
                <select wire:model.live="filterBatch"
                        class="px-3 py-2 border border-[#E8E0F0] rounded-xl text-sm bg-white text-[#333333] focus:outline-none focus:border-[#9b59b6] focus:ring-2 focus:ring-purple-100 transition">
                    <option value="">All Batches</option>
                    @foreach($batches as $b)
                        <option value="{{ $b }}">Batch {{ $b }}</option>
                    @endforeach
                </select>
            @endif

            {{-- Reset --}}
            @if($search || $filterStatus || $filterLocation || $filterBatch || $filterCourse || $filterRelevance)
                <button wire:click="clearFilters"
                        class="px-3 py-2 rounded-xl border border-[#E8E0F0] bg-white text-sm font-semibold text-[#666666] hover:bg-[#F9F7FC] transition flex items-center gap-1.5">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            @endif

        </div>

        {{-- ── Table ── --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9f7fc;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,filterStatus,filterLocation,filterBatch,filterCourse,filterRelevance,clearFilters,previousPage,nextPage">
                <table class="w-full border-collapse min-w-[820px]">
                    <thead>
                        <tr class="border-b border-[#E8E0F0] sticky top-0 z-10 bg-[#F9F7FC]">
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider">Alumni</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider">Course</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider hidden md:table-cell">Batch</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider">Job Title</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider hidden lg:table-cell">Location</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold text-[#666666] uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold text-[#666666] uppercase tracking-wider hidden xl:table-cell">
                                Course Relevance
                            </th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-[#666666] uppercase tracking-wider hidden lg:table-cell">Last Updated</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold text-[#666666] uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F9F7FC]">

                        @forelse($rows as $row)
                        @php
                            $statusClass = match($row->employment_status) {
                                'employed'      => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                'self_employed' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                'unemployed'    => 'bg-amber-50 text-amber-700 border border-amber-200',
                                default         => 'bg-[#F9F7FC] text-[#999999] border border-[#E8E0F0]',
                            };
                            $statusLabel = match($row->employment_status) {
                                'employed'      => 'Employed',
                                'self_employed' => 'Self-Employed',
                                'unemployed'    => 'Unemployed',
                                default         => 'Not Filled',
                            };
                            $statusIcon = match($row->employment_status) {
                                'employed'      => 'fa-briefcase',
                                'self_employed' => 'fa-store',
                                'unemployed'    => 'fa-circle-pause',
                                default         => 'fa-circle-question',
                            };
                            $isWorking = in_array($row->employment_status, ['employed', 'self_employed']);

                            $relConfig = match($row->course_relevance ?? '') {
                                'yes'       => ['Related',          'fa-check-circle',  'text-emerald-700', 'bg-emerald-50 border-emerald-200'],
                                'no'        => ['Not Related',      'fa-times-circle',  'text-red-600',     'bg-red-50 border-red-200'],
                                'partially' => ['Partially',        'fa-adjust',        'text-amber-700',   'bg-amber-50 border-amber-200'],
                                default     => null,
                            };

                            // Photo URL
                            $photoPath = $row->profile_photo ?? null;
                            $photoUrl  = (!$photoPath || str_contains($photoPath, 'default.png'))
                                ? asset('storage/alumni-photos/default.png')
                                : (
                                    (str_starts_with($photoPath, 'alumni-photos/') || str_starts_with($photoPath, 'organizers/'))
                                    ? asset('storage/' . $photoPath)
                                    : asset('storage/alumni-photos/default.png')
                                );
                        @endphp

                        <tr class="bg-white hover:bg-[#faf7ff] transition-colors duration-100">

                            {{-- Alumni --}}
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $photoUrl }}"
                                         alt="{{ $row->full_name }}"
                                         class="w-9 h-9 rounded-xl object-cover flex-shrink-0 shadow ring-1 ring-[#E8E0F0]">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-[#333333] text-sm leading-snug truncate uppercase">{{ $row->full_name }}</p>
                                        <p class="text-xs text-[#999999] font-mono mt-0.5">{{ $row->student_id }}</p>
                                    </div>
                                </div>
                            </td>

                            {{-- Course --}}
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0] uppercase">
                                    {{ $row->course_code ?? '—' }}
                                </span>
                            </td>

                            {{-- Batch --}}
                            <td class="px-5 py-3.5 text-sm font-semibold text-[#333333] hidden md:table-cell">
                                {{ $row->batch ?? '—' }}
                            </td>

                            {{-- Job Title Only --}}
                            <td class="px-5 py-3.5">
                                @if($row->job_title)
                                    <p class="font-semibold text-[#333333] text-sm leading-snug uppercase">{{ $row->job_title }}</p>
                                    {{-- Relevance badge inline (shown on smaller screens where xl column is hidden) --}}
                                    @if($relConfig)
                                        <span class="xl:hidden inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $relConfig[2] }} {{ $relConfig[3] }}">
                                            <i class="fa-solid {{ $relConfig[1] }} text-[9px]"></i>
                                            {{ $relConfig[0] }}
                                        </span>
                                    @endif
                                @elseif($row->employment_status === 'unemployed')
                                    <span class="text-sm text-[#999999] italic">
                                        {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '—' }}
                                    </span>
                                @else
                                    <span class="text-sm italic" style="color:#CCCCCC;">No data yet</span>
                                @endif
                            </td>

                            {{-- Location --}}
                            <td class="px-5 py-3.5 hidden lg:table-cell">
                                @if($row->work_location === 'abroad')
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600">
                                        <i class="fa-solid fa-plane-departure text-xs"></i> Abroad (OFW)
                                    </span>
                                @elseif($row->work_location === 'local')
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-teal-600">
                                        <i class="fa-solid fa-house text-xs"></i> Local
                                    </span>
                                @else
                                    <span class="text-sm" style="color:#CCCCCC;">—</span>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold {{ $statusClass }}">
                                    <i class="fa-solid {{ $statusIcon }} text-xs"></i>
                                    {{ $statusLabel }}
                                </span>
                            </td>

                            {{-- Course Relevance (xl+) --}}
                            <td class="px-5 py-3.5 text-center hidden xl:table-cell">
                                @if($isWorking && $relConfig)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold border {{ $relConfig[2] }} {{ $relConfig[3] }}">
                                        <i class="fa-solid {{ $relConfig[1] }} text-xs"></i>
                                        {{ $relConfig[0] }}
                                    </span>
                                @elseif($isWorking)
                                    <span class="text-xs text-[#CCCCCC]">—</span>
                                @else
                                    <span class="text-xs text-[#CCCCCC]">N/A</span>
                                @endif
                            </td>

                            {{-- Last Updated --}}
                            <td class="px-5 py-3.5 text-xs text-[#999999] hidden lg:table-cell">
                                @if($row->emp_updated_at)
                                    {{ \Carbon\Carbon::parse($row->emp_updated_at)->diffForHumans() }}
                                @else
                                    <span style="color:#CCCCCC;">—</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-5 py-3.5 text-center">
                                <button wire:click="viewDetail({{ $row->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-xl text-white shadow transition hover:opacity-90 active:scale-[.98]"
                                        style="background:#7A3F91;">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>
                            </td>

                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center"
                                         style="background:#F9F7FC;">
                                        <i class="fas fa-users-slash text-3xl" style="color:#d9c9e8;"></i>
                                    </div>
                                    <p class="font-semibold text-[#999999] text-sm">No alumni found.</p>
                                    <p class="text-xs text-[#CCCCCC]">Try adjusting your search or filters.</p>
                                    <button wire:click="clearFilters"
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold border border-[#E8E0F0] text-[#666666] hover:bg-[#F9F7FC] transition">
                                        <i class="fas fa-rotate-left"></i> Reset Filters
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforelse

                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── PAGINATION BAR ── --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-[#E8E0F0] shrink-0 rounded-b-2xl"
             style="background:#7A3F91;">
            @php
                $total = $rows->total();
                $pp    = $rows->perPage();
                $cp    = $rows->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-white text-sm font-normal">
                    Showing
                    <span class="font-semibold">{{ $from }}–{{ $to }}</span>
                    of
                    <span class="font-semibold">{{ $total }}</span>
                    alumni
                </p>
                <div class="flex items-center gap-1.5">
                    @if($rows->onFirstPage())
                        <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold bg-white/20 text-white/40 cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white/20 text-white hover:bg-white/30 transition active:scale-[.98]">← Prev</button>
                    @endif
                    <span class="px-4 py-2 bg-white rounded-xl text-sm font-semibold shadow" style="color:#7A3F91;">{{ $cp }} / {{ $rows->lastPage() }}</span>
                    @if($rows->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white/20 text-white hover:bg-white/30 transition active:scale-[.98]">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 rounded-xl text-sm font-semibold bg-white/20 text-white/40 cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>

    </div>{{-- /table card --}}
</div>{{-- /page wrapper --}}


{{-- ══════════════════════════════════════════════════════ DETAIL MODAL ══ --}}
@if($showModal && !empty($modalData))
@php
    $md        = $modalData;
    $isEmp     = in_array($md['employment_status'] ?? '', ['employed','self_employed']);
    $statusLbl = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'][$md['employment_status'] ?? ''] ?? 'Not Filled';
    $statusCls = match($md['employment_status'] ?? '') {
        'employed'      => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        'self_employed' => 'bg-blue-50 text-blue-700 border border-blue-200',
        'unemployed'    => 'bg-amber-50 text-amber-700 border border-amber-200',
        default         => 'bg-[#F9F7FC] text-[#999999] border border-[#E8E0F0]',
    };
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $eduMap = [
        'none'               => 'None',
        'pursuing_masteral'  => 'Pursuing Masteral',
        'pursuing_doctorate' => 'Pursuing Doctorate',
    ];
    $careerLabels = [
        'ofw'                   => ['fa-plane-departure', 'OFW'],
        'freelancer'            => ['fa-laptop-code',     'Freelancer'],
        'entrepreneur'          => ['fa-store',           'Entrepreneur'],
        'career_shifter'        => ['fa-arrows-rotate',   'Career Shifter'],
        'industry_professional' => ['fa-user-tie',        'Industry Professional'],
    ];
    $relModalMap = [
        'yes'       => ['Related to Course',   'fa-check-circle',   'text-emerald-700', 'bg-emerald-50 border-emerald-200'],
        'no'        => ['Not Related',          'fa-times-circle',   'text-red-600',     'bg-red-50 border-red-200'],
        'partially' => ['Partially Related',   'fa-adjust',         'text-amber-700',   'bg-amber-50 border-amber-200'],
    ];
    $relModal = $relModalMap[$md['course_relevance'] ?? ''] ?? null;

    // Modal profile photo
    $modalPhotoPath = $md['profile_photo'] ?? null;
    $modalPhotoUrl  = (!$modalPhotoPath || str_contains($modalPhotoPath, 'default.png'))
        ? asset('storage/alumni-photos/default.png')
        : (
            (str_starts_with($modalPhotoPath, 'alumni-photos/') || str_starts_with($modalPhotoPath, 'organizers/'))
            ? asset('storage/' . $modalPhotoPath)
            : asset('storage/alumni-photos/default.png')
        );
@endphp
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-2 sm:p-4"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl w-full max-w-lg max-h-[95vh] sm:max-h-[90vh] flex flex-col overflow-hidden shadow-2xl border border-[#E8E0F0]"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0">

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 border-b border-[#E8E0F0] flex-shrink-0"
             style="background:#7A3F91;">
            <div class="flex items-center gap-3">
                <img src="{{ $modalPhotoUrl }}"
                     alt="{{ $modalData['full_name'] ?? '' }}"
                     class="w-10 h-10 rounded-xl object-cover flex-shrink-0 ring-2 ring-white/30">
                <div>
                    <p class="font-semibold text-white text-sm leading-snug uppercase">
                        {{ $modalData['full_name'] ?? '—' }}
                        @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
                    </p>
                    <p class="text-xs text-white/70 font-mono mt-0.5">{{ $modalData['student_id'] ?? '—' }}</p>
                </div>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Modal Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-5"
             style="scrollbar-width:thin;scrollbar-color:#d9c9e8 #F9F7FC;">

            {{-- Student Info --}}
            <div>
                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-user-graduate" style="color:#7A3F91;"></i> Student Information
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach([
                        'Course'  => $md['course_code']    ?? '—',
                        'Batch'   => $md['batch']          ?? '—',
                        'Gender'  => $md['gender']         ?? '—',
                        'Contact' => $md['contact_number'] ?? '—',
                    ] as $label => $value)
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#999999] mb-0.5">{{ $label }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Employment Info --}}
            <div class="border-t border-[#E8E0F0] pt-4">
                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase" style="color:#7A3F91;"></i> Employment Information
                </p>

                <div class="flex items-center gap-2 mb-4 flex-wrap">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold {{ $statusCls }}">
                        {{ $statusLbl }}
                    </span>
                    @if($md['emp_updated_at'] ?? null)
                        <span class="text-xs text-[#999999]">
                            <i class="fa-regular fa-clock mr-1"></i>
                            Updated {{ \Carbon\Carbon::parse($md['emp_updated_at'])->diffForHumans() }}
                        </span>
                    @endif
                </div>

                @if($isEmp)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach([
                            ['Company',    $md['company_name']  ?? '—'],
                            ['Job Title',  $md['job_title']     ?? '—'],
                            ['Type',       $empTypeMap[$md['employment_type'] ?? ''] ?? '—'],
                            ['Location',   ucfirst($md['work_location'] ?? '—')],
                            ['Date Hired', $md['date_hired'] ? \Carbon\Carbon::parse($md['date_hired'])->format('M d, Y') : '—'],
                            ['Education',  $eduMap[$md['education_status'] ?? ''] ?? '—'],
                        ] as [$lbl, $val])
                            <div class="bg-gray-50 rounded-xl px-3 py-2.5 border border-[#E8E0F0]">
                                <p class="text-xs font-semibold uppercase tracking-widest text-[#999999] mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-[#333333]">{{ $val }}</p>
                            </div>
                        @endforeach

                        {{-- Course Relevance --}}
                        <div class="bg-gray-50 rounded-xl px-3 py-2.5 border {{ $relModal ? $relModal[3] : 'border-[#E8E0F0]' }} sm:col-span-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#999999] mb-1.5">
                                <i class="fa-solid fa-graduation-cap mr-1" style="color:#7A3F91;"></i>
                                Job Related to Course?
                            </p>
                            @if($relModal)
                                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-bold border {{ $relModal[2] }} {{ $relModal[3] }}">
                                    <i class="fa-solid {{ $relModal[1] }} text-xs"></i>
                                    {{ $relModal[0] }}
                                </span>
                            @else
                                <span class="text-sm text-[#999999]">— Not specified</span>
                            @endif
                        </div>
                    </div>

                    @if(!empty($md['career_path_arr']))
                        <div class="mt-4">
                            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-2">Career Path</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($md['career_path_arr'] as $cp)
                                    @if(isset($careerLabels[$cp]))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-semibold bg-[#F9F7FC] text-[#7A3F91] border border-[#E8E0F0]">
                                            <i class="fa-solid {{ $careerLabels[$cp][0] }} text-xs"></i>
                                            {{ $careerLabels[$cp][1] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                @elseif(($md['employment_status'] ?? '') === 'unemployed')
                    <div class="space-y-3">
                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#999999] mb-0.5">Unemployment Status</p>
                            <p class="text-sm font-semibold text-[#333333]">
                                {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$md['unemployment_status'] ?? ''] ?? '—' }}
                            </p>
                        </div>
                        <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-widest text-[#999999] mb-0.5">Education Status</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $eduMap[$md['education_status'] ?? ''] ?? '—' }}</p>
                        </div>
                    </div>
                @else
                    <div class="bg-gray-50 border border-[#E8E0F0] rounded-xl px-4 py-8 text-center">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:#F9F7FC;">
                            <i class="fa-solid fa-circle-question text-3xl" style="color:#d9c9e8;"></i>
                        </div>
                        <p class="text-sm font-semibold text-[#999999]">No employment record yet.</p>
                        <p class="text-xs text-[#CCCCCC] mt-1">This alumni has not filled in their employment information.</p>
                    </div>
                @endif
            </div>

        </div>

        {{-- Modal Footer --}}
        <div class="px-5 py-4 border-t border-[#E8E0F0] flex justify-end flex-shrink-0 bg-[#F9F7FC]">
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-[#E8E0F0] text-[#666666] hover:bg-white transition">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>

    </div>
</div>
@endif

</div>