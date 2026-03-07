<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $batch  = '';
    public string $course = '';

    protected string $paginationTheme = 'tailwind';

    public function updatingBatch()  { $this->resetPage(); }
    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at']);

        if ($this->search) {
            $s = $this->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('name', 'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($this->batch !== '') {
            $q->where('batch', $this->batch);
        }

        if ($this->course) {
            $q->where('course_code', $this->course);
        }

        return $q->orderByDesc('created_at')->paginate(24);
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function batches()
    {
        return Alumni::select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch')
            ->filter(fn($b) => !is_null($b))
            ->values();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batch  = '';
        $this->course = '';
        $this->resetPage();
    }

    public function getPhotoUrl(?string $path): string
    {
        if (empty($path) || $path === 'null' || is_null($path)) {
            return asset('storage/alumni-photos/default.png');
        }
        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/')) {
            return asset('storage/' . $path);
        }
        if (!str_contains($path, '/')) {
            return asset('storage/alumni-photos/' . $path);
        }
        return asset('storage/' . $path);
    }
};

?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:93vh;">

    <style>
        :root { --primary-color: #7a3f91; }
        * { scroll-behavior: smooth; }

        .scrollbar-custom::-webkit-scrollbar { width: 6px; height: 6px; }
        .scrollbar-custom::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-custom::-webkit-scrollbar-thumb { background: rgba(122,63,145,.3); border-radius: 10px; }
        .scrollbar-custom::-webkit-scrollbar-thumb:hover { background: rgba(122,63,145,.6); }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .spin-icon { animation: spin 1s linear infinite; }

        .card-animate { animation: fadeInUp 0.32s ease-out both; }
        .card-animate:nth-child(1)  { animation-delay: 0.03s; }
        .card-animate:nth-child(2)  { animation-delay: 0.06s; }
        .card-animate:nth-child(3)  { animation-delay: 0.09s; }
        .card-animate:nth-child(4)  { animation-delay: 0.12s; }
        .card-animate:nth-child(5)  { animation-delay: 0.15s; }
        .card-animate:nth-child(6)  { animation-delay: 0.18s; }
        .card-animate:nth-child(7)  { animation-delay: 0.21s; }
        .card-animate:nth-child(8)  { animation-delay: 0.24s; }
        .card-animate:nth-child(9)  { animation-delay: 0.27s; }
        .card-animate:nth-child(10) { animation-delay: 0.30s; }
        .card-animate:nth-child(11) { animation-delay: 0.33s; }
        .card-animate:nth-child(12) { animation-delay: 0.36s; }

        .btn-primary {
            background: linear-gradient(135deg, #7a3f91, #7a3f91);
            color: white; border: none;
            transition: all .25s cubic-bezier(.16,1,.3,1);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #6a3580, #6a3580);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(122,63,145,.25);
        }
        .btn-primary:active   { transform: translateY(0); }
        .btn-primary:disabled {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            cursor: not-allowed; transform: none;
        }

        .grid-loading { opacity: 0.4; pointer-events: none; transition: opacity .2s; }
        .grid-ready   { opacity: 1; transition: opacity .25s; }

        .alumni-card {
            transition: transform .25s ease, box-shadow .25s ease;
            background: white; border: none;
            border-radius: 16px; overflow: visible;
            display: flex; flex-direction: column; align-items: center;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            position: relative; will-change: transform;
        }
        .alumni-card:hover {
            box-shadow: 0 12px 32px rgba(122,63,145,.18);
            transform: translateY(-6px);
        }

        .alumni-card-header {
            width: 100%; height: 100px;
            background: #7a3f91;
            border-radius: 16px 16px 0 0;
            flex-shrink: 0;
        }

        .alumni-card-photo-container {
            width: 90px; height: 90px;
            position: absolute;
            left: 50%; transform: translateX(-50%);
            top: 55px; z-index: 10;
        }
        .alumni-card-photo {
            width: 100%; height: 100%;
            object-fit: cover; border-radius: 50%;
            border: 4px solid white; background: #c4b5d4;
            box-shadow: 0 3px 10px rgba(0,0,0,.13); display: block;
        }

        .alumni-card-body {
            width: 100%;
            padding: 62px 18px 22px;
            flex: 1; display: flex; flex-direction: column;
            align-items: center; text-align: center;
            border-radius: 0 0 16px 16px; background: white;
        }

        .alumni-card-name {
            font-size: 16px; font-weight: 700;
            color: #1e0a2e; margin-bottom: 9px;
            line-height: 1.35; word-break: break-word;
        }

        .alumni-card-batch {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 13px;
            background: rgba(122,63,145,.1); color: #5b2180;
            border-radius: 20px; font-size: 13px; font-weight: 700;
            margin-bottom: 9px;
        }

        .alumni-card-course {
            font-size: 13px; color: #4a3058;
            font-weight: 600; line-height: 1.5; margin-bottom: 10px;
        }

        .alumni-card-quote {
            font-size: 11px; color: #94a3b8;
            font-style: italic; margin-bottom: 14px; line-height: 1.4;
        }

        .alumni-card-button {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; padding: 8px 18px; margin-top: auto;
            background: white; color: #7a3f91;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all .2s ease; width: 100%;
        }
        .alumni-card-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(122,63,145,.15);
            border-color: #7a3f91; background: #faf5ff;
        }

        input, select, textarea { border-color: rgba(0,0,0,.15) !important; color: #1e293b !important; }
        input:hover, select:hover, textarea:hover { border-color: rgba(0,0,0,.3) !important; }
        input:focus, select:focus, textarea:focus { border-color: rgba(0,0,0,.4) !important; box-shadow: none !important; outline: none !important; }
    </style>

    <!-- MAIN CONTENT -->
    <div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-4">

        <!-- HEADER -->
        <div class="mb-5 shrink-0" style="animation: slideInDown 0.4s ease-out;">
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3 mb-2">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                    <i class="fas fa-book text-xl"></i>
                </div>
                Alumni Yearbook
            </h1>
            <p class="text-slate-500 text-sm ml-0.5">
                A comprehensive digital collection of verified PhilCST graduates.
            </p>
        </div>

        <!-- FILTER BAR -->
        <div class="bg-white rounded-lg shadow-sm px-5 py-3 mb-4 shrink-0">
            <div class="flex flex-wrap gap-3 items-end">

                <!-- SEARCH -->
                <div class="w-72 relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input
                        type="text"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Search name, ID, email…"
                        class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm bg-white text-slate-900"
                        style="outline:none !important; box-shadow:none !important;"
                        autocomplete="off" spellcheck="false">
                </div>

                <!-- BATCH -->
                <select wire:model.live="batch"
                        class="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white text-slate-900 min-w-[130px]"
                        style="outline:none !important;">
                    <option value="">All Batches</option>
                    @forelse($this->batches as $b)
                        <option value="{{ $b }}">{{ $b }}</option>
                    @empty
                        <option disabled>No batches</option>
                    @endforelse
                </select>

                <!-- COURSE -->
                <select wire:model.live="course"
                        class="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white text-slate-900 min-w-[130px]"
                        style="outline:none !important;">
                    <option value="">All Courses</option>
                    @forelse($this->courses as $c)
                        <option value="{{ $c->code }}">{{ $c->code }}</option>
                    @empty
                        <option disabled>No courses</option>
                    @endforelse
                </select>

                <!-- RESET -->
                <button
                    wire:click="resetFilters"
                    class="px-3 py-2 text-slate-600 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-1.5"></i>Reset
                </button>

                <!-- Spinner + count -->
                <div class="ml-auto flex items-center gap-2">
                    <span wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage">
                        <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
                    </span>
                    <span class="text-xs font-semibold text-slate-400 bg-slate-100 px-3 py-1.5 rounded-full">
                        {{ number_format($this->alumniRecords->total()) }} alumni
                    </span>
                </div>

            </div>
        </div>

        <!-- GRID -->
        <div class="relative flex-1 min-h-0" x-data="{ showScrollTop: false }">

            <div x-ref="scrollArea"
                 @scroll.passive="showScrollTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto scrollbar-custom"
                 wire:loading.class="grid-loading"
                 wire:loading.class.remove="grid-ready"
                 wire:target="search,batch,course,resetFilters,previousPage,nextPage">

                @if($this->alumniRecords->count() > 0)
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5 pb-4">
                        @foreach($this->alumniRecords as $alumni)
                        <div class="alumni-card card-animate">

                            <div class="alumni-card-header"></div>

                            <div class="alumni-card-photo-container">
                                <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                     alt="{{ $alumni->name }}"
                                     class="alumni-card-photo"
                                     loading="lazy"
                                     decoding="async"
                                     onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                            </div>

                            <div class="alumni-card-body">
                                <div class="w-full flex flex-col items-center flex-1">
                                    <p class="alumni-card-name">{{ $alumni->name }}</p>

                                    <div class="alumni-card-batch">
                                        <i class="fas fa-graduation-cap"></i>
                                        Class of {{ $alumni->batch }}
                                    </div>

                                    <p class="alumni-card-course">{{ $alumni->course_name }}</p>

                                    <div class="alumni-card-quote">"Coming soon..."</div>
                                </div>

                                <button class="alumni-card-button">
                                    <i class="fas fa-eye"></i> View Profile
                                </button>
                            </div>

                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center h-full py-20">
                        <i class="fas fa-book text-6xl text-slate-200 mb-4"></i>
                        <p class="font-semibold text-slate-400 text-lg">No alumni found</p>
                        <p class="text-sm text-slate-400 mt-1">Try adjusting your filters</p>
                    </div>
                @endif

            </div>

            <!-- Scroll to top -->
            <button x-show="showScrollTop"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-75"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-75"
                    @click="$refs.scrollArea.scrollTo({ top: 0, behavior: 'smooth' })"
                    class="absolute bottom-4 right-4 z-20 w-10 h-10 btn-primary rounded-full shadow-lg
                           flex items-center justify-center hover:shadow-xl transition-shadow"
                    style="display:none"
                    title="Back to top">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>

        </div>

        <!-- PAGINATION -->
        <div class="bg-white rounded-lg shadow-sm p-4 mt-2 shrink-0">
            <div class="flex items-center justify-between">
                @php
                    $total = $this->alumniRecords->total();
                    $pp    = $this->alumniRecords->perPage();
                    $cp    = $this->alumniRecords->currentPage();
                    $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                    $to    = min($cp * $pp, $total);
                @endphp
                <p class="text-slate-600 text-sm">
                    Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span>
                    of <span class="font-semibold text-slate-800">{{ $total }}</span>
                </p>
                <div class="flex gap-2 items-center">
                    @if($this->alumniRecords->onFirstPage())
                        <button disabled class="px-6 py-3 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-6 py-3 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-6 py-3 text-slate-700 text-sm font-semibold">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                    @if($this->alumniRecords->hasMorePages())
                        <button wire:click="nextPage" class="px-6 py-3 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else
                        <button disabled class="px-6 py-3 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>

    </div>

</div>