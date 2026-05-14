{{-- resources/views/livewire/registrar/alumni-records.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    // ── Filters ───────────────────────────────────────────────────
    public string $alumniSearch = '';
    public string $alumniBatch  = '';
    public string $alumniCourse = '';
    public string $alumniSort   = 'recent';

    // ── View profile ──────────────────────────────────────────────
    public ?int   $viewingProfileId  = null;
    public        $viewingProfile    = null;
    public        $viewingEmployment = null;

    // ── Photo upload ──────────────────────────────────────────────
    public $newAlumniPhoto = null;

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

    public function resetAlumniFilters(): void
    {
        $this->alumniSearch = '';
        $this->alumniBatch  = '';
        $this->alumniCourse = '';
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

            $emp = DB::table('employment_trackings')
                ->where('alumni_id', $id)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();

            $this->viewingEmployment = $emp ? (array) $emp : null;
            $this->viewingProfileId  = $id;
            $this->newAlumniPhoto    = null;
            $this->activeModal       = 'viewProfile';
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to load profile.');
        }
    }

    public function uploadAlumniPhoto(): void
    {
        $this->validate([
            'newAlumniPhoto' => 'required|image|max:2048|mimes:jpg,jpeg,png,webp',
        ], [
            'newAlumniPhoto.max'   => 'Photo must not exceed 2MB.',
            'newAlumniPhoto.mimes' => 'Only JPG, PNG, or WebP images are allowed.',
        ]);

        if (!$this->viewingProfileId) return;

        try {
            $alumni = Alumni::findOrFail($this->viewingProfileId);

            if ($alumni->profile_photo
                && !str_contains($alumni->profile_photo, 'default.png')
                && Storage::disk('public')->exists($alumni->profile_photo)) {
                Storage::disk('public')->delete($alumni->profile_photo);
            }

            $path = $this->newAlumniPhoto->store('alumni-photos', 'public');
            $alumni->update(['profile_photo' => $path]);

            $this->viewingProfile['profile_photo'] = $path;
            $this->newAlumniPhoto = null;

            $this->dispatch('flash-message', type: 'success', message: 'Profile photo updated successfully.');
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to upload photo.');
        }
    }

    public function closeModal(): void
    {
        $this->activeModal       = '';
        $this->viewingProfileId  = null;
        $this->viewingProfile    = null;
        $this->viewingEmployment = null;
        $this->newAlumniPhoto    = null;
    }
};
?>

<div>

<style>
    /* ── Cursor-follow tooltip ─────────────────────────────────── */
    #ar-global-tooltip {
        position: fixed;
        background: rgba(122, 63, 145, 0.92);
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 5px 10px;
        border-radius: 8px;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        white-space: nowrap;
        z-index: 99999;
        transform: translate(14px, 14px);
    }
    #ar-global-tooltip.visible { opacity: 1; }

    /* ── Row: pointer + NO text selection ─────────────────────── */
    .ar-row {
        cursor: pointer;
        user-select: none;
        -webkit-user-select: none;
    }

    /* ── Numbered pagination buttons ──────────────────────────── */
    .ar-pg-btn {
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
    .ar-pg-active { background: #7A3F91; color: #fff; border-color: #7A3F91; }
    .ar-pg-nav    { background: #fff; color: #7A3F91; border-color: #d9c9e8; }
    .ar-pg-nav:hover:not(:disabled) { background: #f9f7fc; border-color: #7A3F91; }
    .ar-pg-nav:disabled { opacity: .4; cursor: not-allowed; }

    /* ── Close-button tooltip ──────────────────────────────────── */
    .ar-close-btn { position: relative; }
    .ar-close-tip {
        position: absolute;
        right: calc(100% + 8px);
        top: 50%;
        transform: translateY(-50%);
        background: rgba(27, 6, 46, 0.82);
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 4px 9px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
    }
    .ar-close-btn:hover .ar-close-tip { opacity: 1; }

    /* ── Filters label ─────────────────────────────────────────── */
    .ar-filter-label { pointer-events: none; }
</style>

{{-- Global cursor-follow tooltip --}}
<div id="ar-global-tooltip">
    <i class="fas fa-eye mr-1" style="font-size:.6rem;"></i>View Profile
</div>

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
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-4 max-w-screen-2xl mx-auto" style="height:90vh; overflow:hidden;">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
                <i class="fas fa-graduation-cap text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-[#333333] leading-tight">Alumni Records</h1>
                <p class="text-[#666666] text-xs sm:text-sm font-normal">View and manage alumni information and records.</p>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0] flex flex-col overflow-hidden flex-1 min-h-0">

        {{-- ── Filters ── --}}
        {{-- FIX 1: Removed the purple icon. "FILTERS" label now appears only on hover of this bar. --}}
        <div class="ar-filter-bar px-3 sm:px-4 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex flex-wrap gap-2 items-center shrink-0">

            {{-- Hover-only FILTERS label --}}
            <span class="ar-filter-label text-xs font-bold tracking-widest uppercase shrink-0 select-none"
                  style="color:#7A3F91;">FILTERS</span>

            <div class="relative flex-1 min-w-[150px] max-w-xs"
                 wire:ignore
                 x-data="{
                     q: '',
                     init() {
                         this.q = $wire.alumniSearch ?? '';
                         $wire.$watch('alumniSearch', v => { if (v !== this.q) this.q = v; });
                     }
                 }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.300ms="$wire.set('alumniSearch', q)"
                       placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                              placeholder-[#999999] focus:outline-none focus:border-[#7A3F91]
                              focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                       autocomplete="off" spellcheck="false">
            </div>

            <select wire:model.live="alumniBatch"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                           min-w-[90px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal
                           transition-colors duration-150"
                    :class="'{{ $alumniBatch ? 'border-[#7A3F91] bg-[#F9F7FC] text-[#7A3F91]' : '' }}'">
                <option value="">All Years</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b }}">{{ $b }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniCourse"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                           min-w-[100px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal
                           transition-colors duration-150">
                <option value="">All Courses</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->code }}">{{ $c->code }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniSort"
                    class="px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                           min-w-[110px] focus:outline-none focus:border-[#7A3F91] cursor-pointer font-normal">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetAlumniFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetAlumniFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5]
                           transition active:scale-95 disabled:pointer-events-none">
                <span wire:loading.remove wire:target="resetAlumniFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetAlumniFilters">
                    <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- ── Table wrapper ── --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">

            {{-- Loading Overlay --}}
            <div wire:loading
                 wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters,previousPage,nextPage"
                 class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none"
                 style="background:rgba(255,255,255,.65);">
                <div class="flex items-center gap-2.5 px-5 py-3 bg-white rounded-xl shadow-lg border border-[#E8E0F0]">
                    <svg class="animate-spin w-4 h-4 text-[#7A3F91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span class="text-xs font-semibold text-[#7A3F91]">Loading records…</span>
                </div>
            </div>

            {{-- Scrollable table --}}
            <div id="alumni-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-auto">

                <table class="w-full border-collapse table-fixed" style="min-width:620px;">
                    <colgroup>
                        <col style="width:28%;">
                        <col style="width:18%;">
                        <col style="width:14%;">
                        <col style="width:12%;">
                        <col style="width:28%;">
                    </colgroup>
                    <thead>
                        <tr class="bg-[#F5F5F5] border-b-2 border-[#E8E0F0] sticky top-0 z-10">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Student ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Course</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-[#555555] uppercase tracking-widest">Batch</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest hidden md:table-cell">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F0ECF5]">
                        @forelse($this->alumniRecords as $item)
                        <tr class="ar-row bg-white transition-colors duration-100"
                            onmouseenter="this.style.background='#EBEBEB'"
                            onmouseleave="this.style.background=''"
                            wire:click="viewProfile({{ $item->id }})">
                            <td class="px-4 py-3 overflow-hidden">
                                <div class="flex items-center gap-2.5">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                         alt="{{ $item->first_name }}"
                                         class="w-8 h-8 rounded-lg object-cover shrink-0 ring-1 ring-[#E8E0F0]"
                                         draggable="false">
                                    <span class="font-semibold text-[#333333] text-sm uppercase truncate">
                                        {{ $this->formatDisplayName($item->first_name??'', $item->middle_initial??'', $item->last_name??'', $item->suffix??'') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 overflow-hidden">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase truncate block">{{ $item->student_id }}</span>
                            </td>
                            <td class="px-4 py-3 overflow-hidden">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold uppercase truncate max-w-full"
                                      style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                    {{ $item->course_code ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center overflow-hidden">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase">{{ $item->batch }}</span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell overflow-hidden">
                                <span class="text-[#333333] text-sm font-normal uppercase truncate block">
                                    {{ strtoupper($item->email ?? '—') }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-24 text-center">
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
                    class="absolute bottom-4 right-4 z-20 w-8 h-8 rounded-full flex items-center justify-center
                           shadow-lg text-white transition hover:opacity-90"
                    style="background:#7A3F91; display:none;">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>

        </div>

        {{-- ── Pagination footer ── --}}
        @php
            $total    = $this->alumniRecords->total();
            $pp       = $this->alumniRecords->perPage();
            $cp       = $this->alumniRecords->currentPage();
            $lastPage = $this->alumniRecords->lastPage();
            $from     = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to       = min($cp * $pp, $total);
            $pgStart  = max(1, $cp - 2);
            $pgEnd    = min($lastPage, $cp + 2);
        @endphp
        <div class="px-4 py-2.5 border-t border-[#E8E0F0] shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;">

            <p class="text-white/70 text-sm font-normal">
                Showing <strong class="text-white font-semibold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-semibold">{{ $total }}</strong> alumni
            </p>

            @if($lastPage > 1)
            <div class="flex items-center gap-1.5 flex-wrap">

                {{-- Prev arrow --}}
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="ar-pg-btn ar-pg-nav">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                @else
                    <button wire:click="previousPage('alumniPage')" class="ar-pg-btn ar-pg-nav">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </button>
                @endif

                {{-- Leading ellipsis --}}
                @if($pgStart > 1)
                    <button wire:click="gotoPage(1, 'alumniPage')" class="ar-pg-btn ar-pg-nav">1</button>
                    @if($pgStart > 2)
                        <span class="text-white/50 text-sm font-bold px-1">…</span>
                    @endif
                @endif

                {{-- Numbered pages --}}
                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="ar-pg-btn ar-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }}, 'alumniPage')" class="ar-pg-btn ar-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                {{-- Trailing ellipsis --}}
                @if($pgEnd < $lastPage)
                    @if($pgEnd < $lastPage - 1)
                        <span class="text-white/50 text-sm font-bold px-1">…</span>
                    @endif
                    <button wire:click="gotoPage({{ $lastPage }}, 'alumniPage')" class="ar-pg-btn ar-pg-nav">{{ $lastPage }}</button>
                @endif

                {{-- Next arrow --}}
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage('alumniPage')" class="ar-pg-btn ar-pg-nav">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                @else
                    <button disabled class="ar-pg-btn ar-pg-nav">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </button>
                @endif

                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">
                    Page {{ $cp }}/{{ $lastPage }}
                </span>

            </div>
            @endif

        </div>

    </div>{{-- end table card --}}
</div>{{-- end page --}}


{{-- ══ VIEW PROFILE — FULLSCREEN PANEL ════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
@php
    $up = fn(?string $v): string => strtoupper(trim($v ?? ''));

    $emp = $viewingEmployment;

    $empStatusMap = [
        'employed'      => 'Employed',
        'self_employed' => 'Self-Employed',
        'unemployed'    => 'Unemployed',
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $relevanceMap = [
        'yes'       => 'Related to Course',
        'no'        => 'Not Related to Course',
        'partially' => 'Partially Related',
    ];
    $unempMap = [
        'seeking_employment' => 'Actively Seeking Employment',
        'not_looking'        => 'Not Currently Looking',
    ];

    $empStatus   = $emp['employment_status'] ?? null;
    $isWorking   = in_array($empStatus, ['employed', 'self_employed']);
    $empTypeLbl  = $empTypeMap[$emp['employment_type'] ?? ''] ?? null;
    $dateHired   = !empty($emp['date_hired'])
        ? \Carbon\Carbon::parse($emp['date_hired'])->format('F j, Y')
        : null;
    $submittedAt = !empty($emp['created_at'])
        ? \Carbon\Carbon::parse($emp['created_at'])->format('M j, Y')
        : null;
    $careerPath  = !empty($emp['career_path']) ? json_decode($emp['career_path'], true) : [];
    $cpLabels    = [
        'ofw'                   => 'OFW',
        'freelancer'            => 'Freelancer',
        'entrepreneur'          => 'Entrepreneur',
        'career_shifter'        => 'Career Shifter',
        'industry_professional' => 'Industry Professional',
    ];
@endphp

<div class="fixed inset-0 z-50 flex flex-col"
     style="background:rgba(27,6,46,0.60); backdrop-filter:blur(4px);"
     @keydown.escape.window="$wire.closeModal()">

    <div class="w-full h-full bg-white flex flex-col overflow-hidden"
         style="animation:panelIn .22s cubic-bezier(.4,0,.2,1) both;">
        <style>
            @keyframes panelIn {
                from { opacity:0; transform:translateY(14px) scale(.98); }
                to   { opacity:1; transform:none; }
            }
        </style>

        {{-- ── Sticky Header ── --}}
        <div class="flex items-center justify-between px-5 sm:px-7 py-4 shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#5A2D70);">
            <h2 class="text-white font-semibold text-xl sm:text-2xl">Alumni Profile</h2>

            {{-- FIX 2: Icon-only close button. "CLOSE" tooltip shows on hover via .ar-close-tip. --}}
            <button wire:click="closeModal"
                    class="ar-close-btn flex items-center justify-center w-9 h-9 rounded-xl bg-white/10
                           hover:bg-white/20 active:scale-95 text-white transition-all duration-150">
                <span class="ar-close-tip">Close</span>
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- ── Scrollable Content ── --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-4 sm:p-6 space-y-4 bg-[#F7F7F7]">

            {{-- ── Avatar + Quick Info ── --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-4 rounded-xl border border-[#E0E0E0] bg-white">

                <div class="relative shrink-0 group self-start sm:self-auto" style="width:88px; height:88px;">
                    @if($newAlumniPhoto)
                        <img src="{{ $newAlumniPhoto->temporaryUrl() }}" alt="Preview"
                             class="w-full h-full rounded-xl object-cover shadow-md ring-2 ring-[#BBBBBB]">
                    @else
                        <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}"
                             alt="{{ $viewingProfile['first_name'] ?? '' }}"
                             class="w-full h-full rounded-xl object-cover shadow-md ring-2 ring-[#DDDDDD]">
                    @endif
                    <label class="absolute inset-0 rounded-xl flex flex-col items-center justify-center gap-0.5
                                  bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                        <i class="fas fa-camera text-white text-lg"></i>
                        <span class="text-white font-semibold" style="font-size:10px;">CHANGE</span>
                        <input type="file" wire:model="newAlumniPhoto" class="hidden"
                               accept="image/jpeg,image/png,image/webp">
                    </label>
                    @if($newAlumniPhoto)
                        <span class="absolute -top-1.5 -right-1.5 w-3.5 h-3.5 rounded-full bg-[#7A3F91] border-2 border-white"></span>
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <p class="text-xl sm:text-2xl font-semibold text-[#333333] leading-tight uppercase">
                        {{ $this->formatDisplayName($viewingProfile['first_name']??'', $viewingProfile['middle_initial']??'', $viewingProfile['last_name']??'', $viewingProfile['suffix']??'') }}
                    </p>
                    <p class="text-sm text-[#777777] font-mono mt-0.5 uppercase">{{ $viewingProfile['student_id'] ?? '—' }}</p>

                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase bg-[#F0F0F0] text-[#333333] border border-[#DEDEDE]">
                            {{ $viewingProfile['course_code'] ?? '—' }}
                        </span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase bg-[#F0F0F0] text-[#333333] border border-[#DEDEDE]">
                            BATCH {{ $viewingProfile['batch'] ?? '—' }}
                        </span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#F0F0F0] text-[#333333] border border-[#DEDEDE]">
                            {{ !empty($viewingProfile['profile_completed']) ? 'COMPLETE' : 'INCOMPLETE' }}
                        </span>
                    </div>

                    @if($newAlumniPhoto)
                        <div class="flex items-center gap-2 mt-3">
                            <button wire:click="uploadAlumniPhoto"
                                    wire:loading.attr="disabled" wire:target="uploadAlumniPhoto"
                                    class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold
                                           text-white transition active:scale-95 disabled:opacity-60"
                                    style="background:#7A3F91;">
                                <span wire:loading.remove wire:target="uploadAlumniPhoto">
                                    <i class="fas fa-upload text-xs mr-1"></i>Save Photo
                                </span>
                                <span wire:loading wire:target="uploadAlumniPhoto">
                                    <i class="fas fa-spinner fa-spin text-xs mr-1"></i>Uploading…
                                </span>
                            </button>
                            <button wire:click="$set('newAlumniPhoto', null)"
                                    wire:loading.attr="disabled" wire:target="uploadAlumniPhoto"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                           bg-white border border-[#DDDDDD] text-[#666666] hover:bg-[#F5F5F5] transition">
                                <i class="fas fa-xmark text-xs"></i> Cancel
                            </button>
                            <span class="text-xs text-[#999999]">JPG / PNG / WebP · max 2 MB</span>
                        </div>
                    @else
                        <p class="text-xs text-[#AAAAAA] mt-2">Hover photo to change</p>
                    @endif
                </div>
            </div>

            {{-- ── Two-column grid ── --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                {{-- Student Record --}}
                {{-- FIX 3: All card cell backgrounds changed from gray (#F9F9F9/#F5F5F5) to white. Borders kept. --}}
                <div class="rounded-xl border border-[#E0E0E0] overflow-hidden bg-white">
                    <div class="px-4 py-2.5 flex items-center justify-between border-b border-[#E0E0E0] bg-white">
                        <p class="font-semibold text-[#333333] text-sm uppercase tracking-wide">Student Record</p>
                        <span class="text-xs text-[#AAAAAA] font-semibold">From School</span>
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
                        <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                    <div class="px-3 pb-3">
                        <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">Course Name</p>
                            <p class="text-sm font-semibold text-[#333333] break-words">{{ $up($viewingProfile['course_name'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Personal Details --}}
                @php
                    $dob = !empty($viewingProfile['date_of_birth'])
                        ? strtoupper(\Carbon\Carbon::parse($viewingProfile['date_of_birth'])->format('F j, Y'))
                        : '—';
                @endphp
                <div class="rounded-xl border border-[#E0E0E0] overflow-hidden bg-white">
                    <div class="px-4 py-2.5 border-b border-[#E0E0E0] bg-white">
                        <p class="font-semibold text-[#333333] text-sm uppercase tracking-wide">Personal Details</p>
                    </div>
                    <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach([
                            ['Gender',        $up($viewingProfile['gender']            ?? '')],
                            ['Date of Birth', $dob],
                            ['Contact No.',   $up($viewingProfile['contact_number']    ?? '')],
                            ['Disability',    $up($viewingProfile['disability']        ?? '')],
                            ['DSWD No.',      $up($viewingProfile['dswd_household_no'] ?? '')],
                            ['Email Address', $up($viewingProfile['email']             ?? '')],
                        ] as [$lbl, $val])
                        <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-[#333333] break-words">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Family Background --}}
                <div class="rounded-xl border border-[#E0E0E0] overflow-hidden bg-white">
                    <div class="px-4 py-2.5 border-b border-[#E0E0E0] bg-white">
                        <p class="font-semibold text-[#333333] text-sm uppercase tracking-wide">Family Background</p>
                    </div>
                    <div class="px-3 pt-3">
                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-widest mb-1.5">Father's Name</p>
                        <div class="grid grid-cols-3 gap-2 mb-3">
                            @foreach([
                                ['Last Name',   $up($viewingProfile['father_last_name']   ?? '')],
                                ['Given Name',  $up($viewingProfile['father_given_name']  ?? '')],
                                ['Middle Name', $up($viewingProfile['father_middle_name'] ?? '')],
                            ] as [$lbl, $val])
                            <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-widest mb-1.5">Mother's Maiden Name</p>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach([
                                ['Last Name',   $up($viewingProfile['mother_last_name']   ?? '')],
                                ['Given Name',  $up($viewingProfile['mother_given_name']  ?? '')],
                                ['Middle Name', $up($viewingProfile['mother_middle_name'] ?? '')],
                            ] as [$lbl, $val])
                            <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Permanent Address --}}
                <div class="rounded-xl border border-[#E0E0E0] overflow-hidden bg-white">
                    <div class="px-4 py-2.5 border-b border-[#E0E0E0] bg-white">
                        <p class="font-semibold text-[#333333] text-sm uppercase tracking-wide">Permanent Address</p>
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
                        <div class="bg-white border border-[#E0E0E0] rounded-lg p-2.5">
                            <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">Full Address</p>
                            <p class="text-sm font-semibold text-[#333333] leading-snug">{{ $fullAddress }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach([
                                ['Street',            $up($viewingProfile['address_street']       ?? '')],
                                ['Barangay',          $up($viewingProfile['address_barangay']     ?? '')],
                                ['City/Municipality', $up($viewingProfile['address_municipality'] ?? '')],
                                ['Province',          $up($viewingProfile['address_province']     ?? '')],
                            ] as [$lbl, $val])
                            <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-[#333333]">{{ $val ?: '—' }}</p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>{{-- end two-column grid --}}

            {{-- ── Employment Status ── --}}
            <div class="rounded-xl border border-[#E0E0E0] overflow-hidden bg-white">
                <div class="px-4 py-2.5 flex items-center justify-between border-b border-[#E0E0E0] bg-white">
                    <p class="font-semibold text-[#333333] text-sm uppercase tracking-wide">Employment Status</p>
                    @if($emp && $submittedAt)
                        <span class="text-xs text-[#AAAAAA] font-semibold">Updated {{ $submittedAt }}</span>
                    @endif
                </div>

                @if(!$emp)
                    <div class="p-6 text-center">
                        <p class="text-sm font-semibold text-[#666666]">No employment record submitted yet.</p>
                        <p class="text-xs text-[#AAAAAA] mt-1">The alumni has not filled in their employment information.</p>
                    </div>
                @else
                    <div class="p-3 space-y-3">

                        {{-- Status badges --}}
                        <div class="flex flex-wrap gap-2">
                            @if($empStatus && isset($empStatusMap[$empStatus]))
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-white text-[#333333] border border-[#DEDEDE]">
                                    {{ $empStatusMap[$empStatus] }}
                                </span>
                            @endif
                            @if($isWorking && $empTypeLbl)
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold bg-white text-[#555555] border border-[#E0E0E0]">
                                    {{ $empTypeLbl }}
                                </span>
                            @endif
                            @if($isWorking && !empty($emp['work_location']))
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold bg-white text-[#555555] border border-[#E0E0E0]">
                                    {{ ucfirst($emp['work_location']) }}
                                </span>
                            @endif
                            @if($empStatus === 'unemployed' && !empty($emp['unemployment_status']) && isset($unempMap[$emp['unemployment_status']]))
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold bg-white text-[#555555] border border-[#E0E0E0]">
                                    {{ $unempMap[$emp['unemployment_status']] }}
                                </span>
                            @endif
                        </div>

                        {{-- Company / Job title --}}
                        @if($isWorking && (!empty($emp['company_name']) || !empty($emp['job_title'])))
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @if(!empty($emp['company_name']))
                                    <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">
                                            {{ $empStatus === 'self_employed' ? 'Business Name' : 'Company Name' }}
                                        </p>
                                        <p class="text-sm font-bold text-[#333333] uppercase">{{ strtoupper($emp['company_name']) }}</p>
                                    </div>
                                @endif
                                @if(!empty($emp['job_title']))
                                    <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">Job Title / Position</p>
                                        <p class="text-sm font-bold text-[#333333] uppercase">{{ strtoupper($emp['job_title']) }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Date hired / course relevance --}}
                        @if($isWorking)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @if($dateHired)
                                    <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">Date Hired</p>
                                        <p class="text-sm font-semibold text-[#333333]">{{ $dateHired }}</p>
                                    </div>
                                @endif
                                @if(!empty($emp['course_relevance']) && isset($relevanceMap[$emp['course_relevance']]))
                                    <div class="bg-white border border-[#EBEBEB] rounded-lg p-2.5">
                                        <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-0.5">Course Relevance</p>
                                        <p class="text-sm font-semibold text-[#333333]">{{ $relevanceMap[$emp['course_relevance']] }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Career path --}}
                        @if($isWorking && count($careerPath))
                            <div>
                                <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-1.5">Career Path</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($careerPath as $cpKey)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white text-[#333333] border border-[#DEDEDE]">
                                            {{ $cpLabels[$cpKey] ?? $cpKey }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Further education --}}
                        @if(!empty($emp['education_status']) && $emp['education_status'] !== 'none')
                            @php
                                $eduMap = [
                                    'pursuing_masteral'  => 'Pursuing Masteral',
                                    'pursuing_doctorate' => 'Pursuing Doctorate',
                                ];
                            @endphp
                            @if(isset($eduMap[$emp['education_status']]))
                                <div class="pt-1 border-t border-[#EEEEEE]">
                                    <p class="text-xs font-semibold text-[#AAAAAA] uppercase tracking-wide mb-1.5">Further Education</p>
                                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold bg-white text-[#333333] border border-[#DEDEDE]">
                                        {{ $eduMap[$emp['education_status']] }}
                                    </span>
                                </div>
                            @endif
                        @endif

                    </div>
                @endif
            </div>

        </div>{{-- end scrollable content --}}

    </div>{{-- end panel --}}
</div>{{-- end overlay --}}
@endif

</div>{{-- end root --}}

<script>
(function () {
    var tip = document.getElementById('ar-global-tooltip');
    if (!tip) return;

    function bindRows() {
        document.querySelectorAll('.ar-row').forEach(function (row) {
            if (row._arTipBound) return;
            row._arTipBound = true;
            row.addEventListener('mousemove', function (e) {
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.classList.add('visible');
            });
            row.addEventListener('mouseleave', function () {
                tip.classList.remove('visible');
            });
            row.addEventListener('click', function () {
                tip.classList.remove('visible');
            });
        });
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);
})();
</script>