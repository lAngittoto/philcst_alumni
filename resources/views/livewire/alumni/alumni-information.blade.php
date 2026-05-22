{{-- resources/views/livewire/alumni/alumni-information.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;

new class extends Component {

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

    public string $gender        = '';
    public string $date_of_birth = '';

    public string $father_last_name   = '';
    public string $father_given_name  = '';
    public string $father_middle_name = '';

    public string $mother_last_name   = '';
    public string $mother_given_name  = '';
    public string $mother_middle_name = '';

    public string $dswd_household_no    = '';
    public string $address_street       = '';
    public string $address_barangay     = '';
    public string $address_municipality = '';
    public string $address_province     = '';
    public string $disability           = '';
    public string $contact_number       = '';

    public string $errorMessage    = '';
    public string $successMessage  = '';
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
                'email', 'gender', 'date_of_birth',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name',  'mother_given_name',  'mother_middle_name',
                'dswd_household_no',
                'address_street', 'address_barangay', 'address_municipality', 'address_province',
                'disability', 'contact_number', 'profile_completed',
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
        $this->email              = $alumni->email          ?? '';

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

    public function saveProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';

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
            'father_middle_name'   => 'required|string|max:100',
            'mother_last_name'     => 'required|string|max:100',
            'mother_given_name'    => 'required|string|max:100',
            'mother_middle_name'   => 'required|string|max:100',
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
            'father_middle_name.required'   => "Father's middle name is required.",
            'mother_last_name.required'     => "Mother's last name is required.",
            'mother_given_name.required'    => "Mother's given name is required.",
            'mother_middle_name.required'   => "Mother's middle name is required.",
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
                && !empty($this->father_middle_name)
                && !empty($this->mother_last_name) && !empty($this->mother_given_name)
                && !empty($this->mother_middle_name)
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
            $this->editing         = false;
            $this->successMessage  = $profileComplete
                ? 'Profile saved successfully.'
                : 'Progress saved. Complete all required fields to finish your profile.';

            $this->dispatch('profile-updated', completed: $profileComplete);

            Log::info("Alumni profile saved | student_id: {$this->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
        }
    }
}; ?>

{{-- ══ ROOT ══════════════════════════════════════════════════════════════ --}}
<div class="space-y-6">

{{-- ── Page header ──────────────────────────────────────────────────────── --}}
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
    <div class="flex items-center gap-3">
        <div class="w-[42px] h-[42px] rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-address-card text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">My Profile Information</h1>
            <p class="text-sm text-gray-500 mt-0.5">
                Complete your personal details to update your alumni profile.
                Fields marked <span class="text-red-500 font-semibold">*</span> are required.
            </p>
        </div>
    </div>
    @if(!$editing)
        <div class="flex items-center gap-2 flex-wrap">
            <button wire:click="startEditing"
                    class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                           bg-[#7a3f91] text-white text-sm font-semibold cursor-pointer
                           hover:opacity-90 active:scale-[.98] transition">
                Edit Profile
            </button>
        </div>
    @endif
</div>

{{-- ── Alerts ────────────────────────────────────────────────────────────── --}}
@if($errorMessage)
    <div class="rounded-xl px-4 py-3 text-sm border bg-red-50 text-red-600 border-red-200">
        {{ $errorMessage }}
    </div>
@endif
@if($successMessage)
    <div class="rounded-xl px-4 py-3 text-sm border bg-green-50 text-green-700 border-green-200">
        {{ $successMessage }}
    </div>
@endif

{{-- ══ STUDENT ID CARD ══════════════════════════════════════════════════ --}}
<div class="bg-white border border-gray-200 rounded-xl px-5 py-4 flex items-center gap-3">
    <div>
        <p class="text-[11px] font-bold uppercase tracking-widest text-gray-400 mb-1">Student ID</p>
        <p class="text-base font-semibold text-gray-900">{{ $student_id ?: '—' }}</p>
    </div>
</div>

{{-- ══ SECTION 1 — STUDENT'S NAME ══════════════════════════════════════ --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-2">
        <span class="text-base font-semibold text-gray-900">Student's Name</span>
    </div>
    <div class="p-5">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Last Name</span>
                <p class="text-base font-semibold text-gray-900">{{ $last_name ?: '—' }}</p>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Given Name</span>
                <p class="text-base font-semibold text-gray-900">{{ $first_name ?: '—' }}</p>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Middle Name</span>
                <p class="text-base font-semibold text-gray-900">{{ $middle_initial ?: '—' }}</p>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Extension Name</span>
                <p class="text-base font-semibold text-gray-900">{{ $suffix ?: '—' }}</p>
            </div>
        </div>
    </div>
</div>

{{-- ══ SECTION 2 — STUDENT'S DATA ══════════════════════════════════════ --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Student's Data</span>
    </div>
    <div class="p-5">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            {{-- Sex --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                    Sex <span class="text-red-500">*</span>
                </label>
                @if($editing)
                    <div class="flex gap-5 flex-wrap pt-0.5">
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input wire:model="gender" type="radio" value="Male"
                                   class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                            <span class="text-base font-semibold text-gray-900 cursor-pointer">Male</span>
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input wire:model="gender" type="radio" value="Female"
                                   class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                            <span class="text-base font-semibold text-gray-900 cursor-pointer">Female</span>
                        </label>
                    </div>
                    @error('gender') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                @else
                    <p class="text-base font-semibold text-gray-900">{{ $gender ?: '—' }}</p>
                @endif
            </div>

            {{-- Birth Date --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                    Date of Birth <span class="text-red-500">*</span>
                </label>
                @if($editing)
                    <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                           class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                  border border-gray-200 rounded-lg px-3 py-2 transition
                                  hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                  focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                  {{ $errors->has('date_of_birth') ? 'border-red-500' : '' }}">
                    @error('date_of_birth') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                @else
                    <p class="text-base font-semibold text-gray-900">
                        {{ $date_of_birth ? \Carbon\Carbon::parse($date_of_birth)->format('F d, Y') : '—' }}
                    </p>
                @endif
            </div>

            {{-- Course --}}
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">Course</span>
                <p class="text-base font-semibold text-gray-900">{{ $course_code ?: '—' }}</p>
                @if($course_name)
                    <p class="text-sm font-normal text-gray-900 mt-0.5">{{ $course_name }}</p>
                @endif
            </div>

        </div>
    </div>
</div>

{{-- ══ SECTIONS 3 & 4 — PARENT NAMES ══════════════════════════════════ --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Father's Name --}}
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100">
            <span class="text-base font-semibold text-gray-900">Father's Name</span>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="father_last_name" type="text" placeholder="DELA CRUZ"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('father_last_name') ? 'border-red-500' : '' }}">
                        @error('father_last_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $father_last_name ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Given Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="father_given_name" type="text" placeholder="JUAN"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('father_given_name') ? 'border-red-500' : '' }}">
                        @error('father_given_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $father_given_name ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Middle Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="father_middle_name" type="text" placeholder="SANTOS"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('father_middle_name') ? 'border-red-500' : '' }}">
                        @error('father_middle_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $father_middle_name ?: '—' }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Mother's Maiden Name --}}
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100">
            <span class="text-base font-semibold text-gray-900">Mother's Maiden Name</span>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="mother_last_name" type="text" placeholder="REYES"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('mother_last_name') ? 'border-red-500' : '' }}">
                        @error('mother_last_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $mother_last_name ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Given Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="mother_given_name" type="text" placeholder="MARIA"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('mother_given_name') ? 'border-red-500' : '' }}">
                        @error('mother_given_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $mother_given_name ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Middle Name <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="mother_middle_name" type="text" placeholder="CRUZ"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('mother_middle_name') ? 'border-red-500' : '' }}">
                        @error('mother_middle_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $mother_middle_name ?: '—' }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ══ SECTION 5 — OTHER INFORMATION ══════════════════════════════════ --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Other Information</span>
    </div>
    <div class="p-5 space-y-6">

        {{-- DSWD Household --}}
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 pb-1.5 border-b border-gray-100 mb-4">
                DSWD Household
            </p>
            <div class="max-w-sm">
                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                    DSWD Household No.
                    <span class="normal-case font-normal text-gray-400 text-[11px] tracking-normal ml-1">(optional)</span>
                </label>
                @if($editing)
                    <input wire:model="dswd_household_no" type="text" placeholder="E.G. 004-0012345"
                           oninput="this.value=this.value.toUpperCase()"
                           class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                  border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                  transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                  focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white">
                    <p class="text-xs text-gray-400 mt-1">Leave blank if not a DSWD beneficiary.</p>
                @else
                    <p class="text-base font-semibold text-gray-900">{{ $dswd_household_no ?: '—' }}</p>
                @endif
            </div>
        </div>

        {{-- Permanent Address --}}
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 pb-1.5 border-b border-gray-100 mb-4">
                Permanent Address
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Street <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="address_street" type="text" placeholder="E.G. 123 RIZAL ST."
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('address_street') ? 'border-red-500' : '' }}">
                        @error('address_street') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $address_street ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Barangay <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="address_barangay" type="text" placeholder="E.G. BAGONG SILANG"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('address_barangay') ? 'border-red-500' : '' }}">
                        @error('address_barangay') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $address_barangay ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Town / City / Municipality <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="address_municipality" type="text" placeholder="E.G. LIPA CITY"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('address_municipality') ? 'border-red-500' : '' }}">
                        @error('address_municipality') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $address_municipality ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Province <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="address_province" type="text" placeholder="E.G. BATANGAS"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('address_province') ? 'border-red-500' : '' }}">
                        @error('address_province') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $address_province ?: '—' }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Disability --}}
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 pb-1.5 border-b border-gray-100 mb-4">
                Disability
            </p>
            <div class="max-w-lg">
                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                    Disability
                    <span class="normal-case font-normal text-gray-400 text-[11px] tracking-normal ml-1">(optional)</span>
                </label>
                @if($editing)
                    <input wire:model="disability" type="text"
                           placeholder="E.G. NONE / VISUAL IMPAIRMENT / HEARING LOSS"
                           oninput="this.value=this.value.toUpperCase()"
                           class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                  border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                  transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                  focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white">
                    <p class="text-xs text-gray-400 mt-1">Type NONE if not applicable.</p>
                @else
                    <p class="text-base font-semibold text-gray-900">{{ $disability ?: '—' }}</p>
                @endif
            </div>
        </div>

        {{-- Contact & Email --}}
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 pb-1.5 border-b border-gray-100 mb-4">
                Contact &amp; Email
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Contact Number <span class="text-red-500">*</span>
                    </label>
                    @if($editing)
                        <input wire:model="contact_number" type="tel" placeholder="09XX-XXX-XXXX"
                               oninput="this.value=this.value.toUpperCase()"
                               class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                      border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                                      transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                      focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                      {{ $errors->has('contact_number') ? 'border-red-500' : '' }}">
                        @error('contact_number') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @else
                        <p class="text-base font-semibold text-gray-900">{{ $contact_number ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Email Address
                    </span>
                    <p class="text-base font-semibold text-gray-500">{{ $email ?: '—' }}</p>
                    <p class="text-xs text-gray-400 mt-1">Verified — cannot be changed here.</p>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ══ ACTION BUTTONS ══════════════════════════════════════════════════ --}}
@if($editing)
    <div class="flex gap-3 flex-wrap">
        <button wire:click="saveProfile"
                wire:loading.attr="disabled"
                wire:target="saveProfile"
                class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                       bg-[#7a3f91] text-white text-sm font-semibold cursor-pointer
                       hover:opacity-90 active:scale-[.98] transition
                       disabled:opacity-55 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="saveProfile">Save Profile</span>
            <span wire:loading wire:target="saveProfile">Saving…</span>
        </button>
        @if($profileComplete)
            <button wire:click="cancelEditing"
                    class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                           bg-transparent text-gray-900 text-sm font-semibold cursor-pointer
                           border border-gray-200 hover:bg-gray-50 active:scale-[.98] transition">
                Cancel
            </button>
        @endif
    </div>
@endif

@if(!$profileComplete && !$editing)
    <div class="rounded-xl px-4 py-3 text-sm border bg-amber-50 text-amber-700 border-amber-200">
        Your profile is incomplete. Click <strong>Edit Profile</strong> to fill in all required fields.
    </div>
@endif

</div>