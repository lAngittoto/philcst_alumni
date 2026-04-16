<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    // ── Filters ───────────────────────────────────────────────────
    public string $alumniSearch = '';
    public string $alumniBatch  = '';
    public string $alumniCourse = '';
    public string $alumniSort   = 'recent';

    // ── View profile ──────────────────────────────────────────────
    public ?int   $viewingProfileId     = null;
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

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

        return $q->paginate(100, ['*'], 'alumniPage');
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
                'student_id', 'course_code', 'course_name', 'batch',
                'email', 'profile_photo', 'status', 'profile_completed',
                'password_changed_at',
                'gender', 'date_of_birth', 'place_of_birth',
                'citizenship', 'civil_status', 'blood_type', 'contact_number',
                'father_name', 'mother_name', 'spouse_name',
                'address_no', 'address_street', 'address_barangay',
                'address_municipality', 'address_province', 'address_zip_code',
                'created_at', 'updated_at',
            ])->findOrFail($id)->toArray();

            $this->viewingProfileId = $id;
            $this->activeModal      = 'viewProfile';
        } catch (\Exception) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to load profile.');
        }
    }

    public function updateProfilePhoto(): void
    {
        if (!$this->updatingProfilePhoto || !$this->viewingProfileId) return;

        $this->validate([
            'updatingProfilePhoto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $this->updatingProfile = true;
        try {
            $a = Alumni::findOrFail($this->viewingProfileId);
            if ($a->profile_photo && !str_contains($a->profile_photo, 'default.png'))
                Storage::disk('public')->delete($a->profile_photo);

            $f = 'alumni-' . \Illuminate\Support\Str::uuid() . '.' . $this->updatingProfilePhoto->getClientOriginalExtension();
            $this->updatingProfilePhoto->storeAs('alumni-photos', $f, 'public');
            $p = "alumni-photos/{$f}";

            $a->update(['profile_photo' => $p]);
            $this->viewingProfile['profile_photo'] = $p;
            $this->updatingProfilePhoto            = null;
            $this->dispatch('flash-message', type: 'success', message: 'Photo updated successfully!');
        } catch (\Exception) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to update photo.');
        } finally {
            $this->updatingProfile = false;
        }
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->viewingProfileId     = null;
        $this->viewingProfile       = null;
        $this->updatingProfilePhoto = null;
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
    <i class="fas mt-0.5 text-sm shrink-0"
       :class="{
           'fa-circle-check text-emerald-500': type==='success',
           'fa-circle-exclamation text-red-500': type==='error',
           'fa-circle-info text-blue-500': type==='info',
       }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-xs text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 text-gray-500 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-300 hover:text-gray-500 transition shrink-0 mt-0.5">
        <i class="fas fa-xmark text-xs"></i>
    </button>
</div>

{{-- ══ PAGE ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-4 max-w-screen-2xl mx-auto" style="min-height:100vh; background:#f7f3fe;">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                <i class="fas fa-graduation-cap text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 leading-tight">Alumni Records</h1>
                <p class="text-gray-400 text-xs">View and manage alumni accounts</p>
            </div>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <a href="{{ route('registrar.alumni.register') }}" wire:navigate
               class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold text-white shadow-md transition hover:opacity-90 active:scale-95"
               style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                <i class="fas fa-user-plus text-xs"></i>
                <span class="hidden sm:inline">Register</span>
            </a>
            <a href="{{ route('registrar.alumni.import') }}" wire:navigate
               class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-white text-[#7a3f91] border border-[#d4aaeb] shadow-sm transition hover:bg-[#f5eef9] active:scale-95">
                <i class="fas fa-file-import text-xs"></i>
                <span class="hidden sm:inline">Import</span>
            </a>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-purple-100 flex flex-col overflow-hidden"
         style="min-height:0; height:calc(100vh - 148px);">

        {{-- Filters --}}
        <div class="px-3 sm:px-4 py-2.5 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[150px] max-w-xs"
                 x-data="{ query: @entangle('alumniSearch').live, timer: null,
                            onInput(e){ clearTimeout(this.timer); this.timer = setTimeout(() => { this.query = e.target.value; }, 120); } }"
                 wire:ignore>
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs pointer-events-none"></i>
                <input type="text" :value="query" @input="onInput($event)"
                       placeholder="Search name, ID, course…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" spellcheck="false">
            </div>

            <select wire:model.live="alumniBatch"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 min-w-[90px] focus:outline-none focus:border-[#7a3f91] cursor-pointer">
                <option value="">All Years</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b }}">{{ $b }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniCourse"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 min-w-[100px] focus:outline-none focus:border-[#7a3f91] cursor-pointer">
                <option value="">All Courses</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->code }}">{{ $c->code }}</option>
                @endforeach
            </select>

            <select wire:model.live="alumniSort"
                    class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 min-w-[110px] focus:outline-none focus:border-[#7a3f91] cursor-pointer">
                <option value="recent">Newest First</option>
                <option value="oldest">Oldest First</option>
            </select>

            <button wire:click="resetAlumniFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 transition active:scale-95">
                <i class="fas fa-rotate-left text-xs"></i>
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
                        <tr class="bg-gray-50 border-b border-gray-100 sticky top-0 z-10">
                            <th class="px-4 py-3 text-left text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Name</th>
                            <th class="px-4 py-3 text-left text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Student ID</th>
                            <th class="px-4 py-3 text-left text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Course</th>
                            <th class="px-4 py-3 text-center text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Batch</th>
                            <th class="px-4 py-3 text-left text-[10px] font-extrabold text-gray-400 uppercase tracking-widest hidden md:table-cell">Email</th>
                            <th class="px-4 py-3 text-center text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($this->alumniRecords as $item)
                        <tr class="bg-white hover:bg-[#faf7ff] transition-colors duration-100">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                         alt="{{ $item->first_name }}"
                                         class="w-8 h-8 rounded-lg object-cover shrink-0 ring-1 ring-gray-100">
                                    <span class="font-semibold text-gray-800 text-sm">
                                        {{ $this->formatDisplayName($item->first_name??'', $item->middle_initial??'', $item->last_name??'', $item->suffix??'') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <code class="font-mono text-gray-700 text-xs font-bold bg-gray-50 px-2 py-0.5 rounded">{{ $item->student_id }}</code>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold"
                                      style="background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;">
                                    {{ $item->course_code ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="font-mono text-gray-600 text-xs font-bold">{{ $item->batch }}</span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                @if(!empty($item->email))
                                    <span class="text-gray-500 text-xs">{{ $item->email }}</span>
                                @else
                                    <span class="text-gray-300 text-xs italic">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="viewProfile({{ $item->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition active:scale-95"
                                        style="background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;">
                                    <i class="fas fa-eye text-xs"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-24 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f5eef9;">
                                        <i class="fas fa-users text-2xl" style="color:#d4aaeb;"></i>
                                    </div>
                                    <p class="font-bold text-gray-400 text-sm">No alumni found</p>
                                    <p class="text-xs text-gray-300">Try adjusting your filters</p>
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
                    style="background:linear-gradient(135deg,#7a3f91,#9b59b6); display:none;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-2.5 border-t border-gray-100 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:linear-gradient(135deg,#2b0d3e,#1e0630);">
            @php
                $total = $this->alumniRecords->total();
                $pp    = $this->alumniRecords->perPage();
                $cp    = $this->alumniRecords->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="text-white/60 text-xs">
                Showing <strong class="text-white">{{ $from }}–{{ $to }}</strong> of <strong class="text-white">{{ $total }}</strong> alumni
            </p>
            <div class="flex items-center gap-1.5">
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="px-3 py-1.5 rounded-lg text-xs font-bold text-white/25 bg-white/5 cursor-not-allowed">← Prev</button>
                @else
                    <button wire:click="previousPage('alumniPage')"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold text-white transition hover:opacity-80 active:scale-95"
                            style="background:#7a3f91;">← Prev</button>
                @endif
                <span class="px-3 py-1.5 text-gray-800 text-xs font-bold bg-white rounded-lg">{{ $cp }} / {{ $this->alumniRecords->lastPage() }}</span>
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage('alumniPage')"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold text-white transition hover:opacity-80 active:scale-95"
                            style="background:#7a3f91;">Next →</button>
                @else
                    <button disabled class="px-3 py-1.5 rounded-lg text-xs font-bold text-white/25 bg-white/5 cursor-not-allowed">Next →</button>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ══ VIEW PROFILE MODAL ════════════════════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 overflow-y-auto"
     style="background:rgba(27,6,46,0.55); backdrop-filter:blur(4px);"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8"
         style="animation:modalIn .2s cubic-bezier(.4,0,.2,1) both;">
        <style>@keyframes modalIn{from{opacity:0;transform:scale(.97) translateY(8px)}to{opacity:1;transform:none}}</style>

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 rounded-t-2xl sticky top-0 z-10"
             style="background:linear-gradient(135deg,#2b0d3e,#3d1559);">
            <h2 class="text-white font-extrabold text-base flex items-center gap-2">
                <i class="fas fa-graduation-cap text-[#9b6fbe]"></i>
                Alumni Profile
            </h2>
            <button wire:click="closeModal"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition">
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-5 sm:p-6 space-y-4 overflow-y-auto" style="max-height:82vh;">

            {{-- Avatar + Quick Info --}}
            <div class="flex items-center gap-4 p-4 rounded-xl border border-gray-100 bg-gray-50">
                @if($updatingProfilePhoto)
                    <img src="{{ $updatingProfilePhoto->temporaryUrl() }}"
                         class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl object-cover shadow-md ring-2 ring-[#7a3f91]/30 shrink-0">
                @else
                    <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}"
                         alt="{{ $viewingProfile['first_name'] ?? '' }}"
                         class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl object-cover shadow-md ring-2 ring-gray-200 shrink-0">
                @endif
                <div class="flex-1 min-w-0">
                    <p class="text-base font-extrabold text-gray-900 leading-tight">
                        {{ $this->formatDisplayName($viewingProfile['first_name']??'', $viewingProfile['middle_initial']??'', $viewingProfile['last_name']??'', $viewingProfile['suffix']??'') }}
                    </p>
                    <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $viewingProfile['student_id'] ?? '—' }}</p>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;">
                            {{ $viewingProfile['course_code'] ?? '—' }}
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-200">
                            Batch {{ $viewingProfile['batch'] ?? '—' }}
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <i class="fas fa-check text-[9px] mr-0.5"></i>VERIFIED
                        </span>
                    </div>
                </div>
            </div>

            {{-- Student Record --}}
            @include('partials.profile-section', [
                'title'  => 'Student Record',
                'icon'   => 'id-card',
                'bg'     => '#f9f5ff',
                'color'  => '#7a3f91',
                'fields' => [
                    ['First Name',   $viewingProfile['first_name']    ?? '—'],
                    ['Middle Name',  $viewingProfile['middle_initial'] ?? '—'],
                    ['Last Name',    trim(($viewingProfile['last_name']??'').' '.($viewingProfile['suffix']??'')) ?: '—'],
                    ['Student ID',   $viewingProfile['student_id']    ?? '—'],
                    ['Course Code',  $viewingProfile['course_code']   ?? '—'],
                    ['Batch Year',   $viewingProfile['batch']         ?? '—'],
                ],
                'wide' => [
                    ['Course Name',    $viewingProfile['course_name'] ?? '—'],
                    ['Email Address',  $viewingProfile['email'] ?: 'No email on record'],
                ],
            ])

            {{-- Personal Details --}}
            @php $dob = !empty($viewingProfile['date_of_birth']) ? \Carbon\Carbon::parse($viewingProfile['date_of_birth'])->format('F j, Y') : '—'; @endphp
            <div class="rounded-xl border border-gray-100 overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-blue-50">
                    <div class="w-6 h-6 rounded-lg bg-blue-500 flex items-center justify-center shrink-0">
                        <i class="fas fa-person text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="font-bold text-gray-800 text-xs uppercase tracking-wide">Personal Details</p>
                </div>
                <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach([
                        ['Gender',         $viewingProfile['gender']         ?? '—'],
                        ['Date of Birth',  $dob],
                        ['Civil Status',   $viewingProfile['civil_status']   ?? '—'],
                        ['Place of Birth', $viewingProfile['place_of_birth'] ?? '—'],
                        ['Citizenship',    $viewingProfile['citizenship']    ?? '—'],
                        ['Blood Type',     $viewingProfile['blood_type']     ?: '—'],
                        ['Contact No.',    $viewingProfile['contact_number'] ?: '—'],
                    ] as [$lbl, $val])
                    <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                        <p class="text-xs font-semibold text-gray-800">{{ $val }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Family --}}
            <div class="rounded-xl border border-gray-100 overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-rose-50">
                    <div class="w-6 h-6 rounded-lg bg-rose-500 flex items-center justify-center shrink-0">
                        <i class="fas fa-people-roof text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="font-bold text-gray-800 text-xs uppercase tracking-wide">Family Background</p>
                </div>
                <div class="p-3 grid grid-cols-1 sm:grid-cols-3 gap-2">
                    @foreach([
                        ["Father's Name", $viewingProfile['father_name'] ?: '—'],
                        ["Mother's Name", $viewingProfile['mother_name'] ?: '—'],
                        ['Spouse Name',   $viewingProfile['spouse_name'] ?: '—'],
                    ] as [$lbl, $val])
                    <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                        <p class="text-xs font-semibold text-gray-800">{{ $val }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Address --}}
            <div class="rounded-xl border border-gray-100 overflow-hidden">
                <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-emerald-50">
                    <div class="w-6 h-6 rounded-lg bg-emerald-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-map-location-dot text-white" style="font-size:10px;"></i>
                    </div>
                    <p class="font-bold text-gray-800 text-xs uppercase tracking-wide">Home Address</p>
                </div>
                <div class="p-3 space-y-2">
                    @php
                        $addrParts   = array_filter([
                            trim(($viewingProfile['address_no']??'').' '.($viewingProfile['address_street']??'')),
                            $viewingProfile['address_barangay']     ?? '',
                            $viewingProfile['address_municipality'] ?? '',
                            $viewingProfile['address_province']     ?? '',
                            $viewingProfile['address_zip_code']     ?? '',
                        ]);
                        $fullAddress = implode(', ', $addrParts) ?: '—';
                    @endphp
                    <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-2.5">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-0.5">Full Address</p>
                        <p class="text-xs font-semibold text-gray-800 leading-snug">{{ $fullAddress }}</p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach([
                            ['House/Block No.',   $viewingProfile['address_no']            ?: '—'],
                            ['Street',            $viewingProfile['address_street']         ?: '—'],
                            ['Barangay',          $viewingProfile['address_barangay']       ?: '—'],
                            ['City/Municipality', $viewingProfile['address_municipality']   ?: '—'],
                            ['Province',          $viewingProfile['address_province']       ?: '—'],
                            ['Zip Code',          $viewingProfile['address_zip_code']       ?: '—'],
                        ] as [$lbl, $val])
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-xs font-semibold text-gray-800">{{ $val }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Update Photo --}}
            <div class="border-t border-gray-100 pt-4">
                <p class="text-xs font-bold text-gray-700 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#faf5ff] transition"
                     @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-gray-300 block mb-1.5"></i>
                    <p class="text-gray-600 font-semibold text-sm">{{ $updatingProfilePhoto ? 'Change Photo' : 'Click to Upload New Photo' }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto"
                        wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="w-full mt-3 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2 hover:opacity-90 active:scale-[.99]"
                        style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>

            <button wire:click="closeModal"
                    class="w-full bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition active:scale-[.99]">
                Close
            </button>
        </div>
    </div>
</div>
@endif

</div>