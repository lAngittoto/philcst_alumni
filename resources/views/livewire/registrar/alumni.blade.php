{{-- resources/views/livewire/registrar/alumni-records.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    protected function queryString(): array { return []; }

    // ── Filters ───────────────────────────────────────────────────
    public string $alumniSearch = '';
    public string $alumniBatch  = '';
    public string $alumniCourse = '';
    public string $alumniSort   = 'recent';

    // ── View profile ──────────────────────────────────────────────
    public ?int   $viewingProfileId = null;
    public        $viewingProfile   = null;

    // ── Modal ─────────────────────────────────────────────────────
    public string $activeModal = '';

    protected string $paginationTheme = 'tailwind';

    // ─────────────────────────────────────────────────────────────
    public function mount(): void
    {
        if (session()->has('success'))
            $this->dispatch('flash-message', type: 'success', message: session()->pull('success'));
        if (session()->has('error'))
            $this->dispatch('flash-message', type: 'error', message: session()->pull('error'));
    }

    // ─────────────────────────────────────────────────────────────
    public function updatingAlumniSearch() { $this->resetPage('alumniPage'); }
    public function updatingAlumniBatch()  { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse() { $this->resetPage('alumniPage'); }
    public function updatingAlumniSort()   { $this->resetPage('alumniPage'); }

    // ─────────────────────────────────────────────────────────────
    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()->select([
            'id', 'user_id',
            'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'course_name', 'batch',
            'email', 'profile_photo', 'status', 'profile_completed',
            'password_changed_at', 'created_at',
        ]);

        if ($this->alumniSearch) {
            $term = '%' . $this->alumniSearch . '%';
            $q->where(fn($s) => $s
                ->where('first_name',   'like', $term)
                ->orWhere('last_name',  'like', $term)
                ->orWhere('student_id', 'like', $term)
                ->orWhere('course_code','like', $term)
                ->orWhere('course_name','like', $term)
                ->orWhere('email',      'like', $term));
        }

        if ($this->alumniBatch)  $q->where('batch', $this->alumniBatch);
        if ($this->alumniCourse) $q->where('course_code', $this->alumniCourse);

        $q->when(
            $this->alumniSort === 'oldest',
            fn($q) => $q->orderBy('created_at'),
            fn($q) => $q->orderByDesc('created_at')
        );

        return $q->paginate(200, ['*'], 'alumniPage');
    }

    #[Computed] public function courses() { return Course::orderBy('code')->get(); }
    #[Computed] public function batches() { return Alumni::distinct()->orderByDesc('batch')->pluck('batch'); }

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

    // ─────────────────────────────────────────────────────────────
    public function resetAlumniFilters(): void
    {
        $this->alumniSearch = $this->alumniBatch = $this->alumniCourse = '';
        $this->alumniSort   = 'recent';
        $this->resetPage('alumniPage');
    }

    public function viewProfile(int $id): void
    {
        try {
            $this->viewingProfile = Alumni::select([
                'id', 'user_id',
                'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'course_code', 'course_name', 'batch', 'year_level',
                'email', 'profile_photo', 'status', 'profile_completed',
                'password_changed_at',
                'gender', 'date_of_birth',
                'contact_number',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name',  'mother_given_name',  'mother_middle_name',
                'dswd_household_no', 'disability',
                'address_street', 'address_barangay',
                'address_municipality', 'address_province',
                'created_at', 'updated_at',
            ])->findOrFail($id)->toArray();

            $this->viewingProfileId = $id;
            $this->activeModal      = 'viewProfile';
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to load profile.');
        }
    }

    public function closeModal(): void
    {
        $this->activeModal      = '';
        $this->viewingProfileId = null;
        $this->viewingProfile   = null;
    }
};
?>

<div>

{{-- ══ FLASH TOAST ══════════════════════════════════════════════════ --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,5000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2 scale-95"
     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 scale-95"
     class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3 rounded-xl shadow-2xl max-w-sm border-l-4 bg-white"
     :class="{
         'border-emerald-500': type==='success',
         'border-red-500':     type==='error',
         'border-blue-500':    type==='info',
     }"
     style="display:none">
    <i class="fas mt-0.5 text-base shrink-0"
       :class="{
           'fa-circle-check text-emerald-500': type==='success',
           'fa-circle-exclamation text-red-500': type==='error',
           'fa-circle-info text-blue-500': type==='info',
       }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-[#333333]" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 text-[#666666] leading-snug break-words font-normal" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-[#999999] hover:text-[#666666] transition shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ PAGE ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-4 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
                <i class="fas fa-graduation-cap text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-[#333333] leading-tight">Alumni Records</h1>
                <p class="text-[#666666] text-xs sm:text-xl font-normal">View and manage alumni accounts</p>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0] flex flex-col overflow-hidden"
         style="min-height:0; height:calc(100vh - 148px);">

        {{-- Filters --}}
        <div class="px-3 sm:px-4 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex flex-wrap gap-2 items-center">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[150px] max-w-xs"
                 x-data="{ query: @entangle('alumniSearch').live, timer: null,
                            onInput(e){ clearTimeout(this.timer); this.timer = setTimeout(() => { this.query = e.target.value; }, 120); } }"
                 wire:ignore>
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
                <input type="text" :value="query" @input="onInput($event)"
                       placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                       autocomplete="off" spellcheck="false">
            </div>

            <select wire:model.live="alumniBatch"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] min-w-[90px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal">
                <option value="">All Years</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b }}">{{ $b }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniCourse"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] min-w-[100px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal">
                <option value="">All Courses</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->code }}">{{ $c->code }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniSort"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] min-w-[110px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetAlumniFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-95">
                <i class="fas fa-rotate-left text-sm"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
            <div id="alumni-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-auto"
                 wire:loading.class="opacity-50 pointer-events-none"
                 wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">

                <table class="w-full border-collapse" style="min-width:720px;">
                    <thead>
                        <tr class="bg-[#F5F5F5] border-b-2 border-[#E8E0F0] sticky top-0 z-10">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-[#333333] uppercase tracking-widest">Name</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-[#333333] uppercase tracking-widest">Student ID</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-[#333333] uppercase tracking-widest">Course</th>
                            <th class="px-4 py-3 text-center text-sm font-semibold text-[#333333] uppercase tracking-widest">Batch</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-[#333333] uppercase tracking-widest hidden md:table-cell">Email</th>
                            <th class="px-4 py-3 text-center text-sm font-semibold text-[#333333] uppercase tracking-widest">View</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @forelse($this->alumniRecords as $item)
                        <tr class="bg-white hover:bg-[#FAFAFA] transition-colors duration-100">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                         alt="{{ $item->first_name }}"
                                         class="w-8 h-8 rounded-lg object-cover shrink-0 ring-1 ring-[#E8E0F0]">
                                    <span class="font-semibold text-[#333333] text-xl uppercase">
                                        {{ $this->formatDisplayName($item->first_name??'', $item->middle_initial??'', $item->last_name??'', $item->suffix??'') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase">{{ $item->student_id }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2.5 py-1 rounded-full text-sm font-semibold uppercase"
                                      style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                    {{ $item->course_code ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase">{{ $item->batch }}</span>
                            </td>
                            {{-- Always show email, even @pending.local --}}
                            <td class="px-4 py-3 hidden md:table-cell">
                                <span class="text-[#333333] text-sm font-normal uppercase">
                                    {{ strtoupper($item->email ?? '—') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="viewProfile({{ $item->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold transition active:scale-95"
                                        style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                    <i class="fas fa-eye text-sm"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-24 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                        <i class="fas fa-users text-2xl" style="color:#c89de0;"></i>
                                    </div>
                                    <p class="font-semibold text-[#666666] text-xl">No alumni found</p>
                                    <p class="text-sm text-[#999999] font-normal">Try adjusting your filters</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Scroll to top --}}
            <button x-show="showTop"
                    @click="document.getElementById('alumni-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-8 h-8 rounded-full flex items-center justify-center shadow-lg text-white transition hover:opacity-90"
                    style="background:linear-gradient(135deg,#7A3F91,#7A3F91); display:none;">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-2.5 border-t border-[#E8E0F0] shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
            @php
                $total = $this->alumniRecords->total();
                $pp    = $this->alumniRecords->perPage();
                $cp    = $this->alumniRecords->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="text-white/70 text-sm font-normal">
                Showing <strong class="text-white font-semibold">{{ $from }}–{{ $to }}</strong> of <strong class="text-white font-semibold">{{ $total }}</strong> alumni
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white/30 bg-white/5 cursor-not-allowed">← Prev</button>
                @else
                    <button wire:click="previousPage('alumniPage')"
                            class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-80 active:scale-95"
                            style="background:#7A3F91;">← Prev</button>
                @endif
                <span class="px-3 py-1.5 text-[#333333] text-sm font-semibold bg-white rounded-lg">{{ $cp }} / {{ $this->alumniRecords->lastPage() }}</span>
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage('alumniPage')"
                            class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-80 active:scale-95"
                            style="background:#7A3F91;">Next →</button>
                @else
                    <button disabled class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white/30 bg-white/5 cursor-not-allowed">Next →</button>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ══ VIEW PROFILE MODAL ════════════════════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
@php
    // Helper — uppercase shorthand used throughout the modal
    $up = fn(?string $v): string => strtoupper(trim($v ?? ''));
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 overflow-y-auto"
     style="background:rgba(27,6,46,0.55); backdrop-filter:blur(4px);"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8"
         style="animation:modalIn .2s cubic-bezier(.4,0,.2,1) both;">
        <style>@keyframes modalIn{from{opacity:0;transform:scale(.97) translateY(8px)}to{opacity:1;transform:none}}</style>

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 rounded-t-2xl sticky top-0 z-10"
             style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
            <h2 class="text-white font-semibold text-2xl flex items-center gap-2">
                <i class="fas fa-graduation-cap text-[#ffffff]"></i>
                Alumni Profile
            </h2>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        <div class="p-5 sm:p-6 space-y-4 overflow-y-auto" style="max-height:82vh;">

            {{-- Avatar + Quick Info --}}
            <div class="flex items-center gap-4 p-4 rounded-xl border border-[#E8E0F0] bg-[#F5F5F5]">
                <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}"
                     alt="{{ $viewingProfile['first_name'] ?? '' }}"
                     class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl object-cover shadow-md ring-2 ring-[#E8E0F0] shrink-0">
                <div class="flex-1 min-w-0">
                    <p class="text-2xl font-semibold text-[#333333] leading-tight uppercase">
                        {{ $this->formatDisplayName($viewingProfile['first_name']??'', $viewingProfile['middle_initial']??'', $viewingProfile['last_name']??'', $viewingProfile['suffix']??'') }}
                    </p>
                    <p class="text-sm text-[#666666] font-mono mt-0.5 uppercase font-normal">{{ $viewingProfile['student_id'] ?? '—' }}</p>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <span class="px-2 py-0.5 rounded-full text-sm font-semibold uppercase" style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                            {{ $viewingProfile['course_code'] ?? '—' }}
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-sm font-semibold uppercase bg-[#F5F5F5] text-[#333333] border border-[#E8E0F0]">
                            BATCH {{ $viewingProfile['batch'] ?? '—' }}
                        </span>
                        @if(!empty($viewingProfile['profile_completed']))
                            <span class="px-2 py-0.5 rounded-full text-sm font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <i class="fas fa-check text-xs mr-0.5"></i>COMPLETE
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-sm font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                <i class="fas fa-triangle-exclamation text-xs mr-0.5"></i>INCOMPLETE
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── SECTION 1: Student Record ── --}}
            <div class="rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0]"
                     style="background:#F9F7FC;">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0" style="background:#7A3F91;">
                        <i class="fas fa-id-card text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="font-semibold text-[#333333] text-xl uppercase tracking-wide">Student Record</p>
                    <span class="ml-auto inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-[#F5F5F5] text-[#666666]">
                        <i class="fas fa-lock text-xs"></i> From School
                    </span>
                </div>
                <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach([
                        ['Last Name',   $up($viewingProfile['last_name'] ?? '') . (!empty($viewingProfile['suffix']) ? ', '.$up($viewingProfile['suffix']) : '')],
                        ['Given Name',  $up($viewingProfile['first_name']     ?? '')],
                        ['Middle Name', $up($viewingProfile['middle_initial'] ?? '')],
                        ['Student ID',  $up($viewingProfile['student_id']     ?? '')],
                        ['Course',      $up($viewingProfile['course_code']    ?? '')],
                        ['Batch Year',  $up($viewingProfile['batch']          ?? '')],
                    ] as [$lbl, $val])
                    <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                        <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                        <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                    </div>
                    @endforeach
                </div>
                <div class="px-3 pb-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach([
                        ['Course Name', $up($viewingProfile['course_name'] ?? '')],
                    ] as [$lbl, $val])
                    <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                        <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                        <p class="text-sm font-semibold text-[#333333] break-words">{{ $val ?: '—' }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- ── SECTION 2: Personal Details ── --}}
            @php
                $dob = !empty($viewingProfile['date_of_birth'])
                    ? strtoupper(\Carbon\Carbon::parse($viewingProfile['date_of_birth'])->format('F j, Y'))
                    : '—';
            @endphp
            <div class="rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0] bg-blue-50">
                    <div class="w-6 h-6 rounded-lg bg-blue-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-person text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="font-semibold text-[#333333] text-xl uppercase tracking-wide">Personal Details</p>
                </div>
                <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach([
                        ['Gender',        $up($viewingProfile['gender']             ?? '')],
                        ['Date of Birth', $dob],
                        ['Contact No.',   $up($viewingProfile['contact_number']     ?? '')],
                        ['Disability',    $up($viewingProfile['disability']         ?? '')],
                        ['DSWD No.',      $up($viewingProfile['dswd_household_no']  ?? '')],
                        ['Email Address', $up($viewingProfile['email']              ?? '')],
                    ] as [$lbl, $val])
                    <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                        <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                        <p class="text-sm font-semibold text-[#333333] break-words">{{ $val ?: '—' }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- ── SECTION 3: Family Background ── --}}
            <div class="rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0] bg-rose-50">
                    <div class="w-6 h-6 rounded-lg bg-rose-500 flex items-center justify-center shrink-0">
                        <i class="fas fa-people-roof text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="font-semibold text-[#333333] text-xl uppercase tracking-wide">Family Background</p>
                </div>

                {{-- Father --}}
                <div class="px-3 pt-3">
                    <p class="text-xs font-semibold text-blue-600 uppercase tracking-widest mb-1.5 flex items-center gap-1">
                        <i class="fas fa-person text-blue-400 text-xs"></i> Father's Name
                    </p>
                    <div class="grid grid-cols-3 gap-2 mb-3">
                        @foreach([
                            ['Last Name',   $up($viewingProfile['father_last_name']   ?? '')],
                            ['Given Name',  $up($viewingProfile['father_given_name']  ?? '')],
                            ['Middle Name', $up($viewingProfile['father_middle_name'] ?? '')],
                        ] as [$lbl, $val])
                        <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Mother --}}
                <div class="px-3 pb-3">
                    <p class="text-xs font-semibold text-pink-600 uppercase tracking-widest mb-1.5 flex items-center gap-1">
                        <i class="fas fa-person-dress text-pink-400 text-xs"></i> Mother's Maiden Name
                    </p>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach([
                            ['Last Name',   $up($viewingProfile['mother_last_name']   ?? '')],
                            ['Given Name',  $up($viewingProfile['mother_given_name']  ?? '')],
                            ['Middle Name', $up($viewingProfile['mother_middle_name'] ?? '')],
                        ] as [$lbl, $val])
                        <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── SECTION 4: Permanent Address ── --}}
            <div class="rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-[#E8E0F0] bg-emerald-50">
                    <div class="w-6 h-6 rounded-lg bg-emerald-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-map-location-dot text-white" style="font-size:12px;"></i>
                    </div>
                    <p class="font-semibold text-[#333333] text-xl uppercase tracking-wide">Permanent Address</p>
                </div>
                <div class="p-3 space-y-2">
                    @php
                        $addrParts = array_filter([
                            $up($viewingProfile['address_street']       ?? ''),
                            $up($viewingProfile['address_barangay']     ?? ''),
                            $up($viewingProfile['address_municipality'] ?? ''),
                            $up($viewingProfile['address_province']     ?? ''),
                        ]);
                        $fullAddress = implode(', ', $addrParts) ?: '—';
                    @endphp
                    <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-2.5">
                        <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">Full Address</p>
                        <p class="text-sm font-semibold text-[#333333] leading-snug">{{ $fullAddress }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach([
                            ['Street',            $up($viewingProfile['address_street']       ?? '')],
                            ['Barangay',          $up($viewingProfile['address_barangay']     ?? '')],
                            ['City/Municipality', $up($viewingProfile['address_municipality'] ?? '')],
                            ['Province',          $up($viewingProfile['address_province']     ?? '')],
                        ] as [$lbl, $val])
                        <div class="bg-white border border-[#E8E0F0] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#666666] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Close Button --}}
            <button wire:click="closeModal"
                    class="w-full bg-white border border-[#E8E0F0] text-[#333333] px-5 py-2.5 rounded-xl text-xl font-semibold hover:bg-[#F5F5F5] transition active:scale-[.99]">
                Close
            </button>
        </div>
    </div>
</div>
@endif

</div>