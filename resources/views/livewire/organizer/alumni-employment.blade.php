{{-- resources/views/livewire/organizer/alumni-employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

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
        if ($this->filterLocation)  $q->where('et.work_location',   $this->filterLocation);
        if ($this->filterRelevance) $q->where('et.course_relevance', $this->filterRelevance);
        if ($this->filterBatch)     $q->where('a.batch',             $this->filterBatch);
        if ($this->filterCourse)    $q->where('a.course_code',       $this->filterCourse);

        $q->orderByRaw("CASE WHEN et.employment_status IS NULL THEN 1 ELSE 0 END")
          ->orderBy('a.last_name');

        $rows = $q->paginate(15);

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

<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-6 pb-8 max-w-screen-2xl mx-auto min-h-screen bg-gray-50">

    {{-- ══ HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0"
                 style="background:#7a3f91;box-shadow:0 4px 14px rgba(122,63,145,.35);">
                <i class="fas fa-chart-line text-white text-xl sm:text-2xl"></i>
            </div>
            <div>
                <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-800 tracking-tight">Employment Tracking</h1>
                <p class="text-gray-500 text-sm sm:text-base mt-0.5 flex flex-wrap items-center gap-2">
                    Track employment status of your assigned alumni.
                    @if($organizerDepartment)
                        <span class="inline-flex items-center gap-1 font-semibold" style="color:#7a3f91;">
                            <i class="fa-solid fa-building-columns text-sm"></i>
                            {{ $organizerDepartment }}
                        </span>
                    @endif
                    @if($organizerBatch)
                        <span class="inline-flex items-center gap-1 font-semibold" style="color:#7a3f91;">
                            <i class="fa-solid fa-calendar text-sm"></i>
                            Batch {{ $organizerBatch }}
                        </span>
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- ══ STAT CARDS ══ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-3 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-violet-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-users text-violet-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 leading-none">{{ $totalAlumni }}</p>
                <p class="text-sm text-gray-500 mt-0.5">Total</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-3 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-briefcase text-emerald-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 leading-none">{{ $totalEmployed }}</p>
                <p class="text-sm text-gray-500 mt-0.5">Employed</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-3 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-store text-blue-600 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 leading-none">{{ $totalSelf }}</p>
                <p class="text-sm text-gray-500 mt-0.5">Self-Employed</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-3 shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-circle-pause text-amber-500 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 leading-none">{{ $totalUnemployed }}</p>
                <p class="text-sm text-gray-500 mt-0.5">Unemployed</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-5 py-4 flex items-center gap-3 shadow-sm col-span-2 sm:col-span-1">
            <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-circle-question text-gray-400 text-lg"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 leading-none">{{ $totalNotFilled }}</p>
                <p class="text-sm text-gray-500 mt-0.5">Not Filled</p>
            </div>
        </div>
    </div>

    {{-- ══ TABLE CARD ══ --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden"
         style="min-height:0;height:calc(100vh - 290px);">

        {{-- Filter Bar --}}
        <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-white flex flex-wrap gap-2 items-center">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[160px] sm:min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search name, ID, company or job…"
                       class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-800 transition focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100"
                       autocomplete="off">
            </div>

            {{-- Status --}}
            <select wire:model.live="filterStatus"
                    class="px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Statuses</option>
                <option value="employed">Employed</option>
                <option value="self_employed">Self-Employed</option>
                <option value="unemployed">Unemployed</option>
                <option value="not_filled">Not Filled</option>
            </select>

            {{-- Location --}}
            <select wire:model.live="filterLocation"
                    class="px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Locations</option>
                <option value="local">Local</option>
                <option value="abroad">Abroad (OFW)</option>
            </select>

            {{-- Course --}}
            @if($courses->isNotEmpty())
                <select wire:model.live="filterCourse"
                        class="px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                    <option value="">All Courses</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->code }}">{{ $c->code }}</option>
                    @endforeach
                </select>
            @endif

            {{-- Batch --}}
            @if($batches->isNotEmpty())
                <select wire:model.live="filterBatch"
                        class="px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                    <option value="">All Batches</option>
                    @foreach($batches as $b)
                        <option value="{{ $b }}">Batch {{ $b }}</option>
                    @endforeach
                </select>
            @endif

            {{-- Reset --}}
            @if($search || $filterStatus || $filterLocation || $filterBatch || $filterCourse || $filterRelevance)
                <button wire:click="clearFilters"
                        class="px-4 py-2 rounded-lg border border-gray-200 bg-white text-sm font-medium text-gray-600 hover:bg-gray-50 transition flex items-center gap-1.5">
                    <i class="fas fa-rotate-left text-sm"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            @endif

        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,filterStatus,filterLocation,filterBatch,filterCourse,filterRelevance,clearFilters,previousPage,nextPage">
                <table class="w-full border-collapse min-w-[700px]">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200 sticky top-0 z-10">
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider">Alumni</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider">Course</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider hidden md:table-cell">Batch</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider">Company / Job Title</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider hidden lg:table-cell">Location</th>
                            <th class="px-5 py-3.5 text-center text-sm font-bold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold text-gray-600 uppercase tracking-wider hidden lg:table-cell">Last Updated</th>
                            <th class="px-5 py-3.5 text-center text-sm font-bold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">

                        @forelse($rows as $row)
                        @php
                            $statusClass = match($row->employment_status) {
                                'employed'      => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                'self_employed' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                'unemployed'    => 'bg-amber-50 text-amber-700 border border-amber-200',
                                default         => 'bg-gray-100 text-gray-500 border border-gray-200',
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
                            $rowBg = match($row->employment_status) {
                                'unemployed' => 'bg-amber-50/40 hover:bg-amber-50',
                                null, ''     => 'bg-gray-50/60 hover:bg-gray-100/60',
                                default      => 'bg-white hover:bg-gray-50',
                            };
                        @endphp

                        <tr class="transition-colors duration-100 {{ $rowBg }}">

                            {{-- Alumni --}}
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 flex items-center justify-center text-white text-sm font-bold"
                                         style="background:#7a3f91;">
                                        {{ strtoupper(substr($row->full_name, 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 text-sm">{{ $row->full_name }}</p>
                                        <p class="text-sm text-gray-400">{{ $row->student_id }}</p>
                                    </div>
                                </div>
                            </td>

                            {{-- Course --}}
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded text-sm font-semibold bg-violet-100 text-violet-700">
                                    {{ $row->course_code ?? '—' }}
                                </span>
                            </td>

                            {{-- Batch --}}
                            <td class="px-5 py-3.5 text-sm text-gray-700 font-medium hidden md:table-cell">
                                {{ $row->batch ?? '—' }}
                            </td>

                            {{-- Company / Job --}}
                            <td class="px-5 py-3.5">
                                @if($row->job_title || $row->company_name)
                                    <p class="font-semibold text-gray-900 text-sm">{{ $row->job_title ?? '—' }}</p>
                                    <p class="text-sm text-gray-400">{{ $row->company_name ?? '' }}</p>
                                @elseif($row->employment_status === 'unemployed')
                                    <span class="text-sm text-gray-400 italic">
                                        {{ ['seeking_employment' => 'Seeking Employment', 'not_looking' => 'Not Looking'][$row->unemployment_status ?? ''] ?? '—' }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-300 italic">No data yet</span>
                                @endif
                            </td>

                            {{-- Location --}}
                            <td class="px-5 py-3.5 hidden lg:table-cell">
                                @if($row->work_location === 'abroad')
                                    <span class="inline-flex items-center gap-1 text-sm font-semibold text-amber-600">
                                        <i class="fa-solid fa-plane-departure text-sm"></i> Abroad
                                    </span>
                                @elseif($row->work_location === 'local')
                                    <span class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-600">
                                        <i class="fa-solid fa-house text-sm"></i> Local
                                    </span>
                                @else
                                    <span class="text-sm text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold {{ $statusClass }}">
                                    <i class="fa-solid {{ $statusIcon }} text-xs"></i>
                                    {{ $statusLabel }}
                                </span>
                            </td>

                            {{-- Last Updated --}}
                            <td class="px-5 py-3.5 text-sm text-gray-400 hidden lg:table-cell">
                                @if($row->emp_updated_at)
                                    {{ \Carbon\Carbon::parse($row->emp_updated_at)->diffForHumans() }}
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-5 py-3.5 text-center">
                                <button wire:click="viewDetail({{ $row->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-bold text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100 rounded-lg transition">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>
                            </td>

                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-users-slash text-4xl text-gray-300"></i>
                                    </div>
                                    <p class="font-semibold text-gray-400 text-lg">No alumni found</p>
                                    <p class="text-base text-gray-400">Try adjusting your search or filters.</p>
                                    <button wire:click="clearFilters"
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-base border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
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

        {{-- ══ PAGINATION BAR — dark purple like event-organizer ══ --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-100 shrink-0" style="background:#2b0d3e;">
            @php
                $total = $rows->total();
                $pp    = $rows->perPage();
                $cp    = $rows->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-white text-base sm:text-lg">
                    Showing
                    <span class="font-bold text-white">{{ $from }}–{{ $to }}</span>
                    of
                    <span class="font-bold text-white">{{ $total }}</span>
                    alumni
                </p>
                <div class="flex items-center gap-1.5">
                    @if($rows->onFirstPage())
                        <button disabled
                                class="px-4 sm:px-5 py-2 bg-gray-100 text-gray-400 rounded-lg text-base sm:text-lg font-semibold cursor-not-allowed">
                            ← Prev
                        </button>
                    @else
                        <button wire:click="previousPage"
                                class="px-4 sm:px-5 py-2 text-white rounded-lg text-base sm:text-lg font-semibold transition"
                                style="background:#7a3f91;"
                                onmouseover="this.style.background='#5e2f72'"
                                onmouseout="this.style.background='#7a3f91'">
                            ← Prev
                        </button>
                    @endif

                    <span class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-gray-600 text-base sm:text-lg font-semibold shadow-sm">
                        {{ $cp }} / {{ $rows->lastPage() }}
                    </span>

                    @if($rows->hasMorePages())
                        <button wire:click="nextPage"
                                class="px-4 sm:px-5 py-2 text-white rounded-lg text-base sm:text-lg font-semibold transition"
                                style="background:#7a3f91;"
                                onmouseover="this.style.background='#5e2f72'"
                                onmouseout="this.style.background='#7a3f91'">
                            Next →
                        </button>
                    @else
                        <button disabled
                                class="px-4 sm:px-5 py-2 bg-gray-100 text-gray-400 rounded-lg text-base sm:text-lg font-semibold cursor-not-allowed">
                            Next →
                        </button>
                    @endif
                </div>
            </div>
        </div>

    </div>{{-- /table card --}}
</div>{{-- /page wrapper --}}


{{-- ══════════════════════════════════════════════════════ DETAIL MODAL ══ --}}
@if($showModal && !empty($modalData))
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-2 sm:p-4"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl w-full max-w-lg max-h-[95vh] sm:max-h-[90vh] flex flex-col overflow-hidden shadow-2xl"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0">

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0"
             style="background:#7a3f91;">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center bg-white/20 text-white font-bold text-base flex-shrink-0">
                    {{ strtoupper(substr($modalData['full_name'] ?? '?', 0, 1)) }}
                </div>
                <div>
                    <p class="font-bold text-white text-base">
                        {{ $modalData['full_name'] ?? '—' }}
                        @if($modalData['suffix'] ?? null) {{ $modalData['suffix'] }}@endif
                    </p>
                    <p class="text-sm text-white/70">{{ $modalData['student_id'] ?? '—' }}</p>
                </div>
            </div>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        {{-- Modal Body --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-6 space-y-6"
             style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">

            {{-- Student Info --}}
            <div>
                <p class="text-sm font-bold text-gray-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-user-graduate text-violet-400"></i> Student Information
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @foreach([
                        'Course'  => $modalData['course_code']    ?? '—',
                        'Batch'   => $modalData['batch']          ?? '—',
                        'Gender'  => $modalData['gender']         ?? '—',
                        'Contact' => $modalData['contact_number'] ?? '—',
                    ] as $label => $value)
                        <div class="bg-gray-50 rounded-xl px-4 py-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">{{ $label }}</p>
                            <p class="text-base font-semibold text-gray-800 mt-1">{{ $value }}</p>
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
                    'employed'      => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                    'self_employed' => 'bg-blue-50 text-blue-700 border border-blue-200',
                    'unemployed'    => 'bg-amber-50 text-amber-700 border border-amber-200',
                    default         => 'bg-gray-100 text-gray-500 border border-gray-200',
                };
                $empTypeMap = [
                    'full_time'    => 'Full-Time',
                    'part_time'    => 'Part-Time',
                    'contractual'  => 'Contractual',
                    'project_based'=> 'Project-Based',
                    'internship'   => 'Internship',
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
            @endphp

            <div class="border-t border-gray-100 pt-4">
                <p class="text-sm font-bold text-gray-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase text-violet-400"></i> Employment Information
                </p>

                <div class="flex items-center gap-2 mb-4">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold {{ $statusCls }}">
                        {{ $statusLbl }}
                    </span>
                    @if($md['emp_updated_at'] ?? null)
                        <span class="text-sm text-gray-500">
                            <i class="fa-regular fa-clock mr-1"></i>
                            Updated {{ \Carbon\Carbon::parse($md['emp_updated_at'])->diffForHumans() }}
                        </span>
                    @endif
                </div>

                @if($isEmp)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach([
                            ['Company',   $md['company_name']  ?? '—'],
                            ['Job Title', $md['job_title']     ?? '—'],
                            ['Type',      $empTypeMap[$md['employment_type'] ?? ''] ?? '—'],
                            ['Location',  ucfirst($md['work_location'] ?? '—')],
                            ['Date Hired',$md['date_hired'] ? \Carbon\Carbon::parse($md['date_hired'])->format('M d, Y') : '—'],
                            ['Education', $eduMap[$md['education_status'] ?? ''] ?? '—'],
                            ['Relevance', ['yes'=>'Yes','no'=>'No','partially'=>'Partially'][$md['course_relevance'] ?? ''] ?? '—'],
                        ] as [$lbl, $val])
                            <div class="bg-gray-50 rounded-xl px-4 py-3">
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">{{ $lbl }}</p>
                                <p class="text-base font-semibold text-gray-800 mt-1">{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if(!empty($md['career_path_arr']))
                        <div class="mt-4">
                            <p class="text-sm font-bold text-gray-500 uppercase tracking-widest mb-3">Career Path</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($md['career_path_arr'] as $cp)
                                    @if(isset($careerLabels[$cp]))
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold bg-violet-100 text-violet-700">
                                            <i class="fa-solid {{ $careerLabels[$cp][0] }} text-xs"></i>
                                            {{ $careerLabels[$cp][1] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                @elseif(($md['employment_status'] ?? '') === 'unemployed')
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                        <p class="text-xs font-bold text-amber-600 uppercase tracking-wider mb-1">Unemployment Status</p>
                        <p class="text-base font-semibold text-amber-800">
                            {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$md['unemployment_status'] ?? ''] ?? '—' }}
                        </p>
                    </div>
                @else
                    <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-4 text-center">
                        <i class="fa-solid fa-circle-question text-gray-300 text-4xl mb-2 block"></i>
                        <p class="text-base text-gray-400 italic">This alumni has not filled in their employment information yet.</p>
                    </div>
                @endif
            </div>

        </div>

        {{-- Modal Footer --}}
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end flex-shrink-0 bg-gray-50/50">
            <button wire:click="closeModal"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-base font-bold border border-gray-200 text-gray-600 hover:bg-gray-100 transition">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>

    </div>
</div>
@endif

</div>