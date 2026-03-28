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

    #[Computed(cache: true, seconds: 60)]
    public function courses()
    {
        return Course::orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed(cache: true, seconds: 60)]
    public function batches()
    {
        return Alumni::select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch')
            ->filter(fn($b) => !is_null($b))
            ->values();
    }

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

        return $q->orderByDesc('created_at')->paginate(100);
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

{{-- Root: full viewport height, flex column, no page-level overflow --}}
<div class="flex flex-col" style="background:#f3f4f6; height:95vh; overflow:hidden;">

<style>
    :root {
        --brand:      #7a3f91;
        --brand-dark: #5e2f72;
        --brand-50:   #f5eef9;
        --brand-100:  #e9d5f3;
        --brand-200:  #d4aaeb;
    }

    /* ── Scrollbar ── */
    .yb-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
    .yb-scroll::-webkit-scrollbar-track { background: #e5e7eb; border-radius: 9999px; }
    .yb-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 9999px; }
    .yb-scroll::-webkit-scrollbar-thumb:hover { background: var(--brand-200); }

    /* ── Animations ── */
    @keyframes spin        { to { transform: rotate(360deg); } }
    @keyframes fadeInUp    { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
    @keyframes slideDown   { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:none; } }

    .spin-icon { animation: spin 1s linear infinite; }

    .card-in { animation: fadeInUp .28s ease-out both; }
    .card-in:nth-child(1)  { animation-delay:.02s }
    .card-in:nth-child(2)  { animation-delay:.05s }
    .card-in:nth-child(3)  { animation-delay:.08s }
    .card-in:nth-child(4)  { animation-delay:.11s }
    .card-in:nth-child(5)  { animation-delay:.14s }
    .card-in:nth-child(6)  { animation-delay:.17s }
    .card-in:nth-child(7)  { animation-delay:.20s }
    .card-in:nth-child(8)  { animation-delay:.23s }
    .card-in:nth-child(9)  { animation-delay:.26s }
    .card-in:nth-child(10) { animation-delay:.29s }
    .card-in:nth-child(n+11) { animation-delay:.32s }

    /* ── Filter bar inputs ── */
    .yb-input, .yb-select {
        border: 1.5px solid #e5e7eb !important;
        color: #111827 !important;
        background: #fff !important;
        transition: border-color .15s, box-shadow .15s;
        outline: none !important;
    }
    .yb-input:hover, .yb-select:hover { border-color: var(--brand-200) !important; }
    .yb-input:focus, .yb-select:focus {
        border-color: var(--brand) !important;
        box-shadow: 0 0 0 3px rgba(122,63,145,.12) !important;
    }
    .yb-input::placeholder { color: #9ca3af; }

    /* ── Primary button ── */
    .btn-brand {
        background: var(--brand);
        color: #fff;
        transition: background .18s ease, transform .1s ease, box-shadow .18s ease;
        box-shadow: 0 2px 6px rgba(122,63,145,.25);
    }
    .btn-brand:hover:not(:disabled) {
        background: var(--brand-dark);
        box-shadow: 0 4px 14px rgba(122,63,145,.35);
        transform: translateY(-1px);
    }
    .btn-brand:active { transform: translateY(0); }
    .btn-brand:disabled { opacity:.55; cursor:not-allowed; }

    /* ── Ghost button ── */
    .btn-ghost {
        background: #fff;
        color: #374151;
        border: 1.5px solid #e5e7eb;
        transition: background .15s, border-color .15s, box-shadow .15s;
    }
    .btn-ghost:hover {
        background: #f9fafb;
        border-color: #d1d5db;
        box-shadow: 0 2px 6px rgba(0,0,0,.06);
    }

    /* ── Alumni card ── */
    .alumni-card {
        background: #fff;
        border-radius: 1rem;
        overflow: hidden;
        box-shadow: 0 1px 8px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
        display: flex;
        flex-direction: column;
        align-items: center;
        transition: box-shadow .22s ease, transform .22s ease;
        position: relative;
    }
    .alumni-card:hover {
        box-shadow: 0 8px 28px rgba(122,63,145,.18), 0 0 0 1px rgba(122,63,145,.12);
        transform: translateY(-4px);
    }

    /* Purple header strip */
    .card-header-strip {
        width: 100%;
        height: 80px;
        background: var(--brand);
        flex-shrink: 0;
        position: relative;
    }

    /* Photo floated over strip */
    .card-photo-wrap {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        bottom: -36px;
        width: 72px;
        height: 72px;
        z-index: 2;
    }
    .card-photo {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #fff;
        box-shadow: 0 2px 10px rgba(0,0,0,.15);
        background: #c4b5d4;
        display: block;
    }

    /* Card body */
    .card-body {
        width: 100%;
        padding: 46px 14px 18px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        flex: 1;
    }
    .card-name {
        font-size: .9375rem;
        font-weight: 700;
        color: #111827;
        line-height: 1.35;
        margin-bottom: 7px;
        word-break: break-word;
    }
    .card-batch {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        background: var(--brand-50);
        color: var(--brand);
        border: 1px solid var(--brand-200);
        border-radius: 9999px;
        font-size: .75rem;
        font-weight: 700;
        margin-bottom: 7px;
    }
    .card-course {
        font-size: .75rem;
        color: #4b5563;
        font-weight: 600;
        line-height: 1.4;
        margin-bottom: 10px;
    }
    .card-quote {
        font-size: .6875rem;
        color: #9ca3af;
        font-style: italic;
        margin-bottom: 14px;
    }
    .card-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        width: 100%;
        padding: 7px 14px;
        margin-top: auto;
        background: #fff;
        color: var(--brand);
        border: 1.5px solid var(--brand-200);
        border-radius: .625rem;
        font-size: .75rem;
        font-weight: 700;
        cursor: pointer;
        transition: background .15s, border-color .15s, box-shadow .15s;
    }
    .card-btn:hover {
        background: var(--brand-50);
        border-color: var(--brand);
        box-shadow: 0 2px 8px rgba(122,63,145,.14);
    }

    /* ── Grid ── */
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        width: 100%;
    }
    @media (min-width: 640px)  { .cards-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.125rem; } }
    @media (min-width: 1024px) { .cards-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    @media (min-width: 1280px) { .cards-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
    @media (min-width: 1536px) { .cards-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); } }

    /* ── Loading fade ── */
    .grid-fading { opacity: .35; pointer-events: none; transition: opacity .15s; }

    /* ── Responsive hide ── */
    @media (max-width: 640px)  { .hide-xs { display: none !important; } }
</style>

{{-- ── PAGE CONTENT: flex column filling viewport ── --}}
<div class="flex flex-col flex-1 px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto w-full"
     style="height:100vh; overflow:hidden;">

    {{-- Header (shrinks to content) --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 flex-shrink-0"
         style="animation:slideDown .35s ease both;">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow-md shrink-0"
                 style="background:var(--brand);">
                <i class="fas fa-book text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 leading-tight">Alumni Yearbook</h1>
                <p class="text-gray-500 text-xs mt-0.5">A digital collection of PhilCST graduates</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-gray-500 bg-white border border-gray-200 px-3 py-1.5 rounded-full shadow-sm">
                {{ number_format($this->alumniRecords->total()) }} alumni
            </span>
        </div>
    </div>

    {{-- Filter bar (shrinks to content) --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-3 sm:px-5 py-3 mb-4 flex flex-wrap gap-2 items-center flex-shrink-0">
        {{-- Search --}}
        <div class="relative flex-1 min-w-[140px] max-w-xs"
             x-data="{ q: @entangle('search').live, timer: null, onInput(e){ clearTimeout(this.timer); this.timer=setTimeout(()=>{ this.q=e.target.value; },200); } }"
             wire:ignore>
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
            <input type="text" :value="q" @input="onInput($event)"
                   placeholder="Search name, ID, email…"
                   class="yb-input w-full pl-8 pr-3 py-2 rounded-lg text-xs sm:text-sm"
                   autocomplete="off" spellcheck="false">
        </div>

        {{-- Batch --}}
        <select wire:model.live="batch"
                class="yb-select flex-1 sm:flex-none px-3 py-2 rounded-lg text-xs sm:text-sm min-w-[100px]">
            <option value="">All Batches</option>
            @forelse($this->batches as $b)
                <option value="{{ $b }}">{{ $b }}</option>
            @empty
                <option disabled>No batches</option>
            @endforelse
        </select>

        {{-- Course --}}
        <select wire:model.live="course"
                class="yb-select flex-1 sm:flex-none px-3 py-2 rounded-lg text-xs sm:text-sm min-w-[100px]">
            <option value="">All Courses</option>
            @forelse($this->courses as $c)
                <option value="{{ $c->code }}">{{ $c->code }}</option>
            @empty
                <option disabled>No courses</option>
            @endforelse
        </select>

        {{-- Reset --}}
        <button wire:click="resetFilters"
                class="btn-ghost px-3 py-2 rounded-lg text-xs font-semibold flex items-center gap-1.5">
            <i class="fas fa-rotate-left text-xs"></i>
            <span class="hide-xs">Reset</span>
        </button>

        {{-- Spinner --}}
        <span wire:loading wire:target="search,batch,course,resetFilters,previousPage,nextPage"
              class="ml-auto">
            <i class="fas fa-spinner spin-icon text-xs" style="color:var(--brand);"></i>
        </span>
    </div>

    {{-- Card panel: flex-1 so it fills remaining height, overflow hidden so inner scroll works --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col flex-1 min-h-0 overflow-hidden">

        {{-- Scrollable card area --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
            <div id="yb-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-hidden yb-scroll p-3 sm:p-5">

                <div wire:loading.class="grid-fading"
                     wire:target="search,batch,course,resetFilters,previousPage,nextPage">

                    @if($this->alumniRecords->count() > 0)
                        <div class="cards-grid">
                            @foreach($this->alumniRecords as $alumni)
                            <div class="alumni-card card-in">

                                <div class="card-header-strip">
                                    <div class="card-photo-wrap">
                                        <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                             alt="{{ $alumni->name }}"
                                             class="card-photo"
                                             loading="lazy"
                                             decoding="async"
                                             onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                    </div>
                                </div>

                                <div class="card-body">
                                    <p class="card-name">{{ $alumni->name }}</p>
                                    <div class="card-batch">
                                        <i class="fas fa-graduation-cap text-xs"></i>
                                        Class of {{ $alumni->batch }}
                                    </div>
                                    <p class="card-course">{{ $alumni->course_name }}</p>
                                    <p class="card-quote">"Coming soon..."</p>
                                    <button class="card-btn">
                                        <i class="fas fa-eye text-xs"></i> View Profile
                                    </button>
                                </div>

                            </div>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-24">
                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-book text-2xl text-gray-300"></i>
                            </div>
                            <p class="font-semibold text-gray-400 text-base">No alumni found</p>
                            <p class="text-sm text-gray-400 mt-1">Try adjusting your filters</p>
                        </div>
                    @endif

                </div>
            </div>

            {{-- Scroll-to-top button --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="btn-brand absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center shadow-lg">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>
        </div>

        {{-- Pagination footer — always visible at bottom --}}
        <div class="px-3 sm:px-5 py-3 border-t border-gray-200 flex-shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#2b0d3e;">
            @php
                $total = $this->alumniRecords->total();
                $pp    = $this->alumniRecords->perPage();
                $cp    = $this->alumniRecords->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="text-white text-xs sm:text-sm">
                Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $total }}</strong>
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">← Prev</button>
                @else
                    <button wire:click="previousPage" class="btn-brand px-3 py-1.5 rounded-lg text-xs font-semibold">← Prev</button>
                @endif
                <span class="px-3 py-1.5 text-gray-900 text-xs font-semibold bg-white rounded-lg">
                    {{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}
                </span>
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage" class="btn-brand px-3 py-1.5 rounded-lg text-xs font-semibold">Next →</button>
                @else
                    <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">Next →</button>
                @endif
            </div>
        </div>

    </div>{{-- end card panel --}}

</div>{{-- end page content --}}

</div>{{-- end root --}}