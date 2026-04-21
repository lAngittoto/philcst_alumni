{{-- resources/views/livewire/alumni/alumni-information.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;

new class extends Component {

    // ── Student Record (read-only from school) ────────────────────────────────
    public string $last_name      = '';
    public string $first_name     = '';
    public string $middle_initial = '';
    public string $suffix         = '';
    public string $student_id     = '';
    public string $course_code    = '';
    public string $course_name    = '';
    public string $batch          = '';
    public string $year_level     = '';
    public string $email          = '';

    // ── Student Data (editable) ───────────────────────────────────────────────
    public string $gender        = '';
    public string $date_of_birth = '';

    // ── Father's Name (editable) ──────────────────────────────────────────────
    public string $father_last_name   = '';
    public string $father_given_name  = '';
    public string $father_middle_name = '';

    // ── Mother's Maiden Name (editable) ───────────────────────────────────────
    public string $mother_last_name   = '';
    public string $mother_given_name  = '';
    public string $mother_middle_name = '';

    // ── DSWD / Address / Disability / Contact ────────────────────────────────
    public string $dswd_household_no    = '';
    public string $address_street       = '';
    public string $address_barangay     = '';
    public string $address_municipality = '';
    public string $address_province     = '';
    public string $disability           = '';
    public string $contact_number       = '';

    // ── UI State ──────────────────────────────────────────────────────────────
    public string $errorMessage   = '';
    public string $successMessage = '';
    public bool   $profileComplete = false;
    public bool   $editing         = false;
    public int    $alumniId        = 0;

    protected array $snapshot = [];

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)
            ->select([
                'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'course_code', 'course_name', 'batch', 'year_level',
                'gender', 'date_of_birth',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name',  'mother_given_name',  'mother_middle_name',
                'dswd_household_no',
                'address_street', 'address_barangay', 'address_municipality', 'address_province',
                'disability', 'contact_number',
                'profile_completed',
            ])->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId           = $alumni->id;
        $this->last_name          = $alumni->last_name      ?? '';
        $this->first_name         = $alumni->first_name     ?? '';
        $this->middle_initial     = $alumni->middle_initial ?? '';
        $this->suffix             = $alumni->suffix         ?? '';
        $this->student_id         = $alumni->student_id     ?? '';
        $this->course_code        = $alumni->course_code    ?? '';
        $this->course_name        = $alumni->course_name    ?? '';
        $this->batch              = (string)($alumni->batch ?? '');
        $this->year_level         = $alumni->year_level     ?? '';
        $this->email              = $user->email            ?? '';

        $this->gender        = $alumni->gender ?? '';
        $this->date_of_birth = $alumni->date_of_birth
            ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('Y-m-d') : '';

        $this->father_last_name   = $alumni->father_last_name   ?? '';
        $this->father_given_name  = $alumni->father_given_name  ?? '';
        $this->father_middle_name = $alumni->father_middle_name ?? '';

        $this->mother_last_name   = $alumni->mother_last_name   ?? '';
        $this->mother_given_name  = $alumni->mother_given_name  ?? '';
        $this->mother_middle_name = $alumni->mother_middle_name ?? '';

        $this->dswd_household_no    = $alumni->dswd_household_no    ?? '';
        $this->address_street       = $alumni->address_street       ?? '';
        $this->address_barangay     = $alumni->address_barangay     ?? '';
        $this->address_municipality = $alumni->address_municipality ?? '';
        $this->address_province     = $alumni->address_province     ?? '';
        $this->disability           = $alumni->disability           ?? '';
        $this->contact_number       = $alumni->contact_number       ?? '';

        $this->profileComplete = (bool)($alumni->profile_completed ?? false);
        $this->editing         = !$this->profileComplete;
    }

    // ── Edit / Cancel ─────────────────────────────────────────────────────────

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $keys = [
            'gender', 'date_of_birth',
            'father_last_name', 'father_given_name', 'father_middle_name',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number',
        ];
        $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) {
            $this->$k = $v;
        }
        $this->editing = false;
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    public function saveProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';

        // Force uppercase before validation
        $upperFields = [
            'father_last_name', 'father_given_name', 'father_middle_name',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number',
        ];
        foreach ($upperFields as $field) {
            $this->$field = strtoupper(trim($this->$field));
        }

        $this->validate([
            'gender'               => 'required|string|in:Male,Female',
            'date_of_birth'        => 'required|date|before:today',
            'father_last_name'     => 'required|string|max:100',
            'father_given_name'    => 'required|string|max:100',
            'father_middle_name'   => 'nullable|string|max:100',
            'mother_last_name'     => 'required|string|max:100',
            'mother_given_name'    => 'required|string|max:100',
            'mother_middle_name'   => 'nullable|string|max:100',
            'dswd_household_no'    => 'nullable|string|max:50',
            'address_street'       => 'required|string|max:255',
            'address_barangay'     => 'required|string|max:255',
            'address_municipality' => 'required|string|max:255',
            'address_province'     => 'required|string|max:255',
            'disability'           => 'nullable|string|max:255',
            'contact_number'       => 'required|string|max:20',
        ], [
            'gender.required'               => 'Please select your sex/gender.',
            'date_of_birth.required'        => 'Birth date is required.',
            'date_of_birth.before'          => 'Birth date must be in the past.',
            'father_last_name.required'     => "Father's last name is required.",
            'father_given_name.required'    => "Father's given name is required.",
            'mother_last_name.required'     => "Mother's last name is required.",
            'mother_given_name.required'    => "Mother's given name is required.",
            'address_street.required'       => 'Street is required.',
            'address_barangay.required'     => 'Barangay is required.',
            'address_municipality.required' => 'Town/City/Municipality is required.',
            'address_province.required'     => 'Province is required.',
            'contact_number.required'       => 'Contact number is required.',
        ]);

        try {
            $profileComplete =
                !empty($this->gender) && !empty($this->date_of_birth)
                && !empty($this->father_last_name) && !empty($this->father_given_name)
                && !empty($this->mother_last_name) && !empty($this->mother_given_name)
                && !empty($this->address_street) && !empty($this->address_barangay)
                && !empty($this->address_municipality) && !empty($this->address_province)
                && !empty($this->contact_number);

            DB::table('alumni')->where('id', $this->alumniId)->update([
                'gender'               => $this->gender,
                'date_of_birth'        => $this->date_of_birth ?: null,
                'father_last_name'     => $this->father_last_name     ?: null,
                'father_given_name'    => $this->father_given_name    ?: null,
                'father_middle_name'   => $this->father_middle_name   ?: null,
                'mother_last_name'     => $this->mother_last_name     ?: null,
                'mother_given_name'    => $this->mother_given_name    ?: null,
                'mother_middle_name'   => $this->mother_middle_name   ?: null,
                'dswd_household_no'    => $this->dswd_household_no    ?: null,
                'address_street'       => $this->address_street       ?: null,
                'address_barangay'     => $this->address_barangay     ?: null,
                'address_municipality' => $this->address_municipality ?: null,
                'address_province'     => $this->address_province     ?: null,
                'disability'           => $this->disability           ?: null,
                'contact_number'       => $this->contact_number       ?: null,
                'profile_completed'    => $profileComplete,
                'updated_at'           => now(),
            ]);

            $this->profileComplete = $profileComplete;
            $this->editing = false;
            $this->successMessage = $profileComplete
                ? 'Profile saved successfully!'
                : 'Progress saved. Fill in all required fields to complete your profile.';

            // Tell the layout to refresh the profileComplete state
            $this->dispatch('profile-updated', completed: $profileComplete);

            Log::info("Alumni profile saved | student_id: {$this->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
        }
    }
}; ?>

<div class="space-y-5">

<style>
/* ── Base tokens ─────────────────────────────────────────────────── */
:root {
    --brand:       #7a3f91;
    --brand-light: #f3eef8;
    --brand-mid:   #ede9fe;
}

/* ── ALL TEXT INPUTS → UPPERCASE ─────────────────────────────────── */
input[type="text"],
input[type="tel"],
input[type="search"] {
    text-transform: uppercase;
    letter-spacing: .03em;
}

/* ── Field states ────────────────────────────────────────────────── */
.f-edit {
    border: 1.5px solid #d1d5db;
    background: #fff;
    color: #111827;
    transition: border-color .15s, box-shadow .15s;
}
.f-edit:hover  { border-color: var(--brand); }
.f-edit:focus  { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.f-edit.err    { border-color: #ef4444; }

.f-view {
    border: 1.5px solid #e5e7eb;
    background: #f9fafb;
    color: #111827;
    cursor: default;
    pointer-events: none;
}

/* Locked = from school records, truly immutable */
.f-locked {
    border: 1.5px solid #e5e7eb;
    background: #f9fafb;
    color: #111827;
    cursor: not-allowed;
    pointer-events: none;
}

/* ── Radio pill ──────────────────────────────────────────────────── */
.r-pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border: 1.5px solid #e5e7eb;
    border-radius: .75rem; cursor: pointer;
    transition: border-color .15s, background .15s; font-size: .875rem;
}
.r-pill:hover      { border-color: var(--brand); background: var(--brand-light); }
.r-pill input:checked ~ * { color: var(--brand); }

/* ── Section card ────────────────────────────────────────────────── */
.s-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.s-head {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 18px; border-bottom: 1px solid #f3f4f6;
    background: var(--brand-light);
}
.s-icon {
    width: 32px; height: 32px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: var(--brand); flex-shrink: 0;
}
.s-label {
    font-size: .7rem; font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; color: #6b7280;
}

/* ── Error text ──────────────────────────────────────────────────── */
.e-msg {
    font-size: .75rem; color: #ef4444;
    display: flex; align-items: center; gap: 4px; margin-top: 3px;
}

/* ── Divider label ───────────────────────────────────────────────── */
.div-lbl {
    display: flex; align-items: center; gap: 8px;
    font-size: .7rem; font-weight: 700; letter-spacing: .07em;
    text-transform: uppercase; color: #9ca3af;
    margin-bottom: 10px; margin-top: 2px;
}
.div-lbl::after { content: ''; flex: 1; height: 1px; background: #f3f4f6; }
</style>

{{-- ══ PAGE HEADER ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-3xl font-extrabold text-[#2b0d3e] tracking-tight">My Profile Information</h1>
        <p class="text-sm leading-relaxed mt-2 text-gray-500">
            Complete your personal details to update your alumni profile. Fields marked with 
            <span class="text-red-500 font-bold">*</span> are required. 
           
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        @if ($profileComplete)
            <span class="inline-flex items-center gap-2 bg-emerald-100 border border-emerald-300
                         text-emerald-800 px-4 py-2 rounded-xl text-sm font-semibold">
                <i class="fa-solid fa-circle-check text-emerald-600"></i> Profile Complete
            </span>
        @else
            <span class="inline-flex items-center gap-2 bg-amber-50 border border-amber-300
                         text-amber-800 px-4 py-2 rounded-xl text-sm font-semibold">
                <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Profile Incomplete
            </span>
        @endif

        @if(!$editing)
            <button wire:click="startEditing"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-bold
                           text-white shadow-sm transition hover:opacity-90 active:scale-95"
                    style="background-color:#7a3f91;">
                <i class="fa-solid fa-pen"></i> Edit Profile
            </button>
        @endif
    </div>
</div>

{{-- ── Alerts ── --}}
@if ($errorMessage)
    <div class="p-3 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-2">
        <i class="fa-solid fa-circle-exclamation mt-0.5 text-red-600 text-sm flex-shrink-0"></i>
        <p class="text-sm font-medium">{{ $errorMessage }}</p>
    </div>
@endif
@if ($successMessage)
    <div class="p-3 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-2">
        <i class="fa-solid fa-circle-check mt-0.5 text-emerald-600 text-sm flex-shrink-0"></i>
        <p class="text-sm font-medium">{{ $successMessage }}</p>
    </div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     SECTION 1 — STUDENT'S NAME
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon"><i class="fa-solid fa-id-card text-white text-xs"></i></div>
        <div class="flex-1">
            <p class="text-sm font-bold text-gray-900">Student's Name</p>
            <p class="text-xs text-gray-500">From your school records — read only</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
            <i class="fa-solid fa-lock text-xs"></i> Locked
        </span>
    </div>

    <div class="p-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
                <p class="s-label mb-1">Last Name</p>
                <div class="px-3 py-2 rounded-xl f-locked text-sm font-semibold uppercase">
                    {{ $last_name ?: '—' }}
                </div>
            </div>
            <div>
                <p class="s-label mb-1">Given Name</p>
                <div class="px-3 py-2 rounded-xl f-locked text-sm font-semibold uppercase">
                    {{ $first_name ?: '—' }}
                </div>
            </div>
            <div>
                <p class="s-label mb-1">Middle Name</p>
                <div class="px-3 py-2 rounded-xl f-locked text-sm font-semibold uppercase">
                    {{ $middle_initial ?: '—' }}
                </div>
            </div>
            <div>
                <p class="s-label mb-1">Ext. Name</p>
                <div class="px-3 py-2 rounded-xl f-locked text-sm font-semibold uppercase">
                    {{ $suffix ?: '—' }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SECTION 2 — STUDENT'S DATA
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon"><i class="fa-solid fa-user-graduate text-white text-xs"></i></div>
        <div class="flex-1">
            <p class="text-sm font-bold text-gray-900">Student's Data</p>
            <p class="text-xs text-gray-500">Sex, birth date, and course</p>
        </div>
        @if(!$editing)
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                <i class="fa-solid fa-eye text-xs"></i> View Only
            </span>
        @endif
    </div>

    <div class="p-4 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">

            {{-- Sex / Gender --}}
            <div>
                <label class="block s-label mb-2">
                    Sex @if($editing)<span class="text-red-500 normal-case font-normal text-xs ml-1">*</span>@endif
                </label>
                @if($editing)
                    <div class="flex gap-2 flex-wrap">
                        <label class="r-pill">
                            <input wire:model="gender" type="radio" value="Male" class="w-4 h-4 accent-blue-600">
                            <i class="fa-solid fa-mars text-blue-500 text-xs"></i>
                            <span class="font-medium text-gray-700 text-sm">Male</span>
                        </label>
                        <label class="r-pill">
                            <input wire:model="gender" type="radio" value="Female" class="w-4 h-4 accent-pink-500">
                            <i class="fa-solid fa-venus text-pink-500 text-xs"></i>
                            <span class="font-medium text-gray-700 text-sm">Female</span>
                        </label>
                    </div>
                    @error('gender')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                @else
                    <div class="px-3 py-2 rounded-xl f-view text-sm font-semibold">
                        @if($gender === 'Male') <i class="fa-solid fa-mars text-blue-400 mr-1.5"></i>
                        @elseif($gender === 'Female') <i class="fa-solid fa-venus text-pink-400 mr-1.5"></i>
                        @endif
                        {{ $gender ?: '—' }}
                    </div>
                @endif
            </div>

            {{-- Birth Date --}}
            <div>
                <label class="block s-label mb-2">
                    Birth Date @if($editing)<span class="text-red-500 normal-case font-normal text-xs ml-1">*</span>@endif
                </label>
                <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                       {{ !$editing ? 'disabled' : '' }}
                       class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('date_of_birth') ? ' err' : '') : 'f-view' }}">
                @error('date_of_birth')
                    <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                @enderror
            </div>

            {{-- Course --}}
            <div>
                <p class="s-label mb-2">Course</p>
                <div class="px-3 py-2 rounded-xl f-locked text-sm font-bold tracking-wide uppercase" title="{{ $course_name }}">
                    <i class="fa-solid fa-graduation-cap text-violet-400 mr-1.5 text-xs"></i>
                    {{ $course_code ?: '—' }}
                </div>
                @if($course_name)
                    <p class="text-xs text-gray-500 mt-1 pl-1 truncate uppercase" title="{{ $course_name }}">{{ $course_name }}</p>
                @endif
            </div>

        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SECTIONS 3 & 4 — PARENT NAMES
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- FATHER'S NAME --}}
    <div class="s-card">
        <div class="s-head">
            <div class="s-icon" style="background:#2563eb;"><i class="fa-solid fa-person text-white text-xs"></i></div>
            <div class="flex-1">
                <p class="text-sm font-bold text-gray-900">Father's Name</p>
                <p class="text-xs text-gray-500">Father's full name</p>
            </div>
            @if(!$editing)
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                    <i class="fa-solid fa-eye text-xs"></i> View Only
                </span>
            @endif
        </div>
        <div class="p-4">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block s-label mb-1">
                        Last Name @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="father_last_name" type="text" placeholder="DELA CRUZ"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('father_last_name') ? ' err' : '') : 'f-view' }}">
                    @error('father_last_name')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block s-label mb-1">
                        Given Name @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="father_given_name" type="text" placeholder="JUAN"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('father_given_name') ? ' err' : '') : 'f-view' }}">
                    @error('father_given_name')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block s-label mb-1">Middle Name</label>
                    <input wire:model="father_middle_name" type="text" placeholder="SANTOS"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit' : 'f-view' }}">
                </div>
            </div>
        </div>
    </div>

    {{-- MOTHER'S MAIDEN NAME --}}
    <div class="s-card">
        <div class="s-head">
            <div class="s-icon" style="background:#db2777;"><i class="fa-solid fa-person-dress text-white text-xs"></i></div>
            <div class="flex-1">
                <p class="text-sm font-bold text-gray-900">Mother's Maiden Name</p>
                <p class="text-xs text-gray-500">Mother's maiden name before marriage</p>
            </div>
            @if(!$editing)
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                    <i class="fa-solid fa-eye text-xs"></i> View Only
                </span>
            @endif
        </div>
        <div class="p-4">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block s-label mb-1">
                        Last Name @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="mother_last_name" type="text" placeholder="REYES"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('mother_last_name') ? ' err' : '') : 'f-view' }}">
                    @error('mother_last_name')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block s-label mb-1">
                        Given Name @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="mother_given_name" type="text" placeholder="MARIA"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('mother_given_name') ? ' err' : '') : 'f-view' }}">
                    @error('mother_given_name')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block s-label mb-1">Middle Name</label>
                    <input wire:model="mother_middle_name" type="text" placeholder="CRUZ"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit' : 'f-view' }}">
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SECTION 5 — DSWD + ADDRESS + DISABILITY + CONTACT + EMAIL
══════════════════════════════════════════════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#059669;"><i class="fa-solid fa-map-location-dot text-white text-xs"></i></div>
        <div class="flex-1">
            <p class="text-sm font-bold text-gray-900">Other Information</p>
            <p class="text-xs text-gray-500">DSWD, address, disability, contact &amp; email</p>
        </div>
        @if(!$editing)
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                <i class="fa-solid fa-eye text-xs"></i> View Only
            </span>
        @endif
    </div>

    <div class="p-4 space-y-4">

        {{-- DSWD --}}
        <div>
            <div class="div-lbl"><span>DSWD Household</span></div>
            <label class="block s-label mb-1">
                DSWD Household No. <span class="normal-case font-normal text-gray-400 text-xs">(optional)</span>
            </label>
            <input wire:model="dswd_household_no" type="text" placeholder="E.G. 004-0012345"
                   oninput="this.value=this.value.toUpperCase()"
                   {{ !$editing ? 'disabled' : '' }}
                   class="w-full sm:w-1/2 px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit' : 'f-view' }}">
            @if($editing)
                <p class="text-xs text-gray-400 mt-1">
                    <i class="fa-solid fa-circle-info text-blue-400 mr-1"></i>
                    Leave blank if not a DSWD beneficiary.
                </p>
            @endif
        </div>

        {{-- Permanent Address --}}
        <div>
            <div class="div-lbl"><span>Permanent Address</span></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                <div>
                    <label class="block s-label mb-1">
                        Street @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="address_street" type="text" placeholder="E.G. 123 RIZAL ST."
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('address_street') ? ' err' : '') : 'f-view' }}">
                    @error('address_street')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block s-label mb-1">
                        Barangay @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="address_barangay" type="text" placeholder="E.G. BAGONG SILANG"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('address_barangay') ? ' err' : '') : 'f-view' }}">
                    @error('address_barangay')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block s-label mb-1">
                        Town / City / Municipality @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="address_municipality" type="text" placeholder="E.G. LIPA CITY"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('address_municipality') ? ' err' : '') : 'f-view' }}">
                    @error('address_municipality')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block s-label mb-1">
                        Province @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="address_province" type="text" placeholder="E.G. BATANGAS"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('address_province') ? ' err' : '') : 'f-view' }}">
                    @error('address_province')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

            </div>
        </div>

        {{-- Disability --}}
        <div>
            <div class="div-lbl"><span>Disability</span></div>
            <label class="block s-label mb-1">
                Disability <span class="normal-case font-normal text-gray-400 text-xs">(optional)</span>
            </label>
            <input wire:model="disability" type="text" placeholder="E.G. NONE / VISUAL IMPAIRMENT / HEARING LOSS"
                   oninput="this.value=this.value.toUpperCase()"
                   {{ !$editing ? 'disabled' : '' }}
                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit' : 'f-view' }}">
            @if($editing)
                <p class="text-xs text-gray-400 mt-1">
                    <i class="fa-solid fa-circle-info text-blue-400 mr-1"></i>
                    Type <strong>NONE</strong> if you have no disability.
                </p>
            @endif
        </div>

        {{-- Contact + Email --}}
        <div>
            <div class="div-lbl"><span>Contact &amp; Email</span></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                <div>
                    <label class="block s-label mb-1">
                        Contact Number @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="contact_number" type="tel" placeholder="09XX-XXX-XXXX"
                           oninput="this.value=this.value.toUpperCase()"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'f-edit'.($errors->has('contact_number') ? ' err' : '') : 'f-view' }}">
                    @error('contact_number')
                        <p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block s-label mb-1">Email Address</label>
                    <div class="flex items-center gap-2 px-3 py-2 f-locked rounded-xl text-sm">
                        <i class="fa-solid fa-envelope text-gray-400 text-xs flex-shrink-0"></i>
                        <span class="truncate text-gray-800 font-medium">{{ $email ?: '—' }}</span>
                        <span class="ml-auto inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5
                                     rounded-full bg-emerald-100 text-emerald-700 flex-shrink-0">
                            <i class="fa-solid fa-check text-[10px]"></i> Verified
                        </span>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

{{-- ══ ACTION BUTTONS ════════════════════════════════════════════════════════ --}}
@if($editing)
    <div class="flex flex-col sm:flex-row gap-3">
        <button wire:click="saveProfile"
                wire:loading.attr="disabled"
                wire:target="saveProfile"
                class="flex-1 text-white py-3 rounded-xl font-bold text-sm shadow-md hover:opacity-90
                       disabled:opacity-70 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                style="background-color:#7a3f91;">
            <span wire:loading.remove wire:target="saveProfile">
                <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Profile
            </span>
            <span wire:loading wire:target="saveProfile">
                <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Saving…
            </span>
        </button>

        @if($profileComplete)
            <button wire:click="cancelEditing"
                    class="flex-1 py-3 rounded-xl font-bold text-sm border-2 border-gray-300 bg-white
                           text-gray-700 hover:bg-gray-100 active:scale-[0.98] transition-all
                           flex items-center justify-center gap-2">
                <i class="fa-solid fa-xmark mr-1.5"></i> Cancel
            </button>
        @endif
    </div>
@endif

@if(!$profileComplete && !$editing)
    <p class="text-xs text-center text-gray-400 pb-2">
        <i class="fa-solid fa-circle-info mr-1 text-blue-400"></i>
        Click <strong>Edit Profile</strong> to fill in your information.
    </p>
@endif

</div>