{{-- resources/views/livewire/alumni/alumni-information.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use App\Models\Alumni;

new class extends Component {

    public string $first_name     = '';
    public string $middle_initial = '';
    public string $last_name      = '';
    public string $suffix         = '';
    public string $student_id     = '';
    public string $course_code    = '';
    public string $course_name    = '';
    public string $batch          = '';
    public string $email          = '';

    public string $gender         = '';
    public string $date_of_birth  = '';
    public string $place_of_birth = '';
    public string $citizenship    = 'Filipino';
    public string $civil_status   = '';
    public string $blood_type     = '';
    public string $contact_number = '';

    public string $father_name = '';
    public string $mother_name = '';
    public string $spouse_name = '';

    public string $address_no           = '';
    public string $address_street       = '';
    public string $address_barangay     = '';
    public string $address_municipality = '';
    public string $address_province     = '';
    public string $address_zip_code     = '';

    public string $errorMessage    = '';
    public string $successMessage  = '';
    public bool   $profileComplete = false;
    public bool   $editing         = false;

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
                'id','first_name','middle_initial','last_name','suffix',
                'student_id','course_code','course_name','batch',
                'gender','date_of_birth','place_of_birth','citizenship',
                'civil_status','blood_type','contact_number',
                'father_name','mother_name','spouse_name',
                'address_no','address_street','address_barangay',
                'address_municipality','address_province','address_zip_code',
                'profile_completed',
            ])
            ->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->first_name           = $alumni->first_name           ?? '';
        $this->middle_initial       = $alumni->middle_initial       ?? '';
        $this->last_name            = $alumni->last_name            ?? '';
        $this->suffix               = $alumni->suffix               ?? '';
        $this->student_id           = $alumni->student_id           ?? '';
        $this->course_code          = $alumni->course_code          ?? '';
        $this->course_name          = $alumni->course_name          ?? '';
        $this->batch                = (string) ($alumni->batch      ?? '');
        $this->email                = $user->email                  ?? '';
        $this->gender               = $alumni->gender               ?? '';
        $this->date_of_birth        = $alumni->date_of_birth
                                          ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('Y-m-d')
                                          : '';
        $this->place_of_birth       = $alumni->place_of_birth       ?? '';
        $this->citizenship          = $alumni->citizenship          ?? 'Filipino';
        $this->civil_status         = $alumni->civil_status         ?? '';
        $this->blood_type           = $alumni->blood_type           ?? '';
        $this->contact_number       = $alumni->contact_number       ?? '';
        $this->father_name          = $alumni->father_name          ?? '';
        $this->mother_name          = $alumni->mother_name          ?? '';
        $this->spouse_name          = $alumni->spouse_name          ?? '';
        $this->address_no           = $alumni->address_no           ?? '';
        $this->address_street       = $alumni->address_street       ?? '';
        $this->address_barangay     = $alumni->address_barangay     ?? '';
        $this->address_municipality = $alumni->address_municipality ?? '';
        $this->address_province     = $alumni->address_province     ?? '';
        $this->address_zip_code     = $alumni->address_zip_code     ?? '';
        $this->profileComplete      = (bool) ($alumni->profile_completed ?? false);

        $this->editing = !$this->profileComplete;
    }

    public function startEditing(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $this->snapshot = [
            'gender' => $this->gender, 'date_of_birth' => $this->date_of_birth,
            'place_of_birth' => $this->place_of_birth, 'citizenship' => $this->citizenship,
            'civil_status' => $this->civil_status, 'blood_type' => $this->blood_type,
            'contact_number' => $this->contact_number, 'father_name' => $this->father_name,
            'mother_name' => $this->mother_name, 'spouse_name' => $this->spouse_name,
            'address_no' => $this->address_no, 'address_street' => $this->address_street,
            'address_barangay' => $this->address_barangay,
            'address_municipality' => $this->address_municipality,
            'address_province' => $this->address_province,
            'address_zip_code' => $this->address_zip_code,
        ];

        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $key => $value) { $this->$key = $value; }
        $this->editing = false;
    }

    public function saveProfile(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $this->validate([
            'gender'               => 'required|string|in:Male,Female',
            'date_of_birth'        => 'required|date|before:today',
            'place_of_birth'       => 'required|string|max:255',
            'citizenship'          => 'required|string|max:100',
            'civil_status'         => 'required|string|in:Single,Married,Widowed,Separated,Annulled',
            'contact_number'       => 'required|string|max:20',
            'father_name'          => 'required|string|max:255',
            'mother_name'          => 'required|string|max:255',
            'spouse_name'          => 'nullable|string|max:255',
            'address_no'           => 'nullable|string|max:50',
            'address_street'       => 'required|string|max:255',
            'address_barangay'     => 'required|string|max:255',
            'address_municipality' => 'required|string|max:255',
            'address_province'     => 'required|string|max:255',
            'address_zip_code'     => 'required|string|max:10',
            'blood_type'           => 'nullable|string|max:10',
        ], [
            'gender.required'               => 'Please select your gender.',
            'date_of_birth.required'        => 'Date of birth is required.',
            'date_of_birth.before'          => 'Date of birth must be in the past.',
            'place_of_birth.required'       => 'Place of birth is required.',
            'citizenship.required'          => 'Citizenship is required.',
            'civil_status.required'         => 'Please select your civil status.',
            'contact_number.required'       => 'Contact number is required.',
            'father_name.required'          => "Father's name is required.",
            'mother_name.required'          => "Mother's name is required.",
            'address_street.required'       => 'Street address is required.',
            'address_barangay.required'     => 'Barangay is required.',
            'address_municipality.required' => 'Municipality/City is required.',
            'address_province.required'     => 'Province is required.',
            'address_zip_code.required'     => 'Zip code is required.',
        ]);

        $alumni = Alumni::where('user_id', auth()->id())->first();
        if (!$alumni) { $this->errorMessage = 'Alumni record not found.'; return; }

        try {
            $profileComplete = !empty($this->gender) && !empty($this->date_of_birth)
                && !empty($this->place_of_birth) && !empty($this->citizenship)
                && !empty($this->civil_status) && !empty($this->contact_number)
                && !empty($this->father_name) && !empty($this->mother_name)
                && !empty($this->address_street) && !empty($this->address_barangay)
                && !empty($this->address_municipality) && !empty($this->address_province)
                && !empty($this->address_zip_code);

            \Illuminate\Support\Facades\DB::table('alumni')->where('id', $alumni->id)->update([
                'gender'               => $this->gender,
                'date_of_birth'        => $this->date_of_birth ?: null,
                'place_of_birth'       => $this->place_of_birth,
                'citizenship'          => $this->citizenship,
                'civil_status'         => $this->civil_status,
                'blood_type'           => $this->blood_type     ?: null,
                'contact_number'       => $this->contact_number,
                'father_name'          => $this->father_name,
                'mother_name'          => $this->mother_name,
                'spouse_name'          => $this->spouse_name    ?: null,
                'address_no'           => $this->address_no     ?: null,
                'address_street'       => $this->address_street,
                'address_barangay'     => $this->address_barangay,
                'address_municipality' => $this->address_municipality,
                'address_province'     => $this->address_province,
                'address_zip_code'     => $this->address_zip_code,
                'profile_completed'    => $profileComplete,
                'updated_at'           => now(),
            ]);

            $this->profileComplete = $profileComplete;
            $this->editing         = false;
            $this->successMessage  = $profileComplete
                ? '✅ Profile saved successfully!'
                : 'Progress saved. Fill in all required fields to complete your profile.';

            Log::info("Alumni profile saved | student_id: {$alumni->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Exception $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
        }
    }

}; ?>

<div class="space-y-4">
<style>
.field-editable {
    border: 1.5px solid #d1d5db;
    background: #fff;
    color: #111827;
    transition: border-color .15s, box-shadow .15s;
}
.field-editable:hover { border-color: #7a3f91; }
.field-editable:focus { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }

.field-disabled {
    border: 1.5px solid #e5e7eb;
    background: #f9fafb;
    color: #374151;
    cursor: default;
    pointer-events: none;
    opacity: .85;
}
</style>

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900 tracking-tight">My Profile Information</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Complete all fields marked <span class="text-red-500 font-semibold">*</span> to complete your profile.
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            {{-- Status badge --}}
            @if ($profileComplete)
                <span class="inline-flex items-center gap-2 bg-emerald-100 border border-emerald-300
                             text-emerald-800 px-4 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-circle-check text-emerald-600"></i>
                    Profile Complete
                </span>
            @else
                <span class="inline-flex items-center gap-2 bg-amber-50 border border-amber-300
                             text-amber-800 px-4 py-2 rounded-xl text-sm font-semibold">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
                    Profile Incomplete
                </span>
            @endif

            {{-- Edit Profile button —only in view mode --}}
            @if(!$editing)
                <button wire:click="startEditing"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-bold
                               text-white shadow-sm transition hover:opacity-90 active:scale-95"
                        style="background-color:#7a3f91;">
                    <i class="fa-solid fa-pen"></i>
                    Edit Profile
                </button>
            @endif
        </div>
    </div>

    {{-- Alerts --}}
    @if ($errorMessage)
        <div class="p-3 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-2">
            <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-600 text-sm"></i>
            <p class="text-sm font-medium">{{ $errorMessage }}</p>
        </div>
    @endif
    @if ($successMessage)
        <div class="p-3 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-2">
            <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-600 text-sm"></i>
            <p class="text-sm font-medium">{{ $successMessage }}</p>
        </div>
    @endif

    {{-- ══ SECTION 1 — STUDENT RECORD ════════════════════════════════════════ --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3" style="background-color:#f9f5ff;">
            <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
                 style="background-color:#7a3f91;">
                <i class="fa-solid fa-id-card text-white text-xs"></i>
            </div>
            <div class="flex-1">
                <h2 class="font-bold text-gray-900 text-sm">Student Record</h2>
                <p class="text-xs text-gray-500">From your school records — read only</p>
            </div>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-600">
                <i class="fa-solid fa-lock text-xs"></i> Locked
            </span>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">First Name</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <span class="text-sm font-semibold text-gray-900">{{ $first_name ?: '—' }}</span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Middle Name</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <span class="text-sm font-semibold text-gray-900">{{ $middle_initial ?: '—' }}</span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Last Name</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <span class="text-sm font-semibold text-gray-900">
                            {{ trim($last_name . ($suffix ? ', ' . $suffix : '')) ?: '—' }}
                        </span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Student ID</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <i class="fa-solid fa-hashtag text-gray-400 text-xs"></i>
                        <span class="text-sm font-mono font-semibold text-gray-900">{{ $student_id ?: '—' }}</span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Course</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <i class="fa-solid fa-graduation-cap text-gray-400 text-xs"></i>
                        <span class="text-sm font-semibold text-gray-900 truncate" title="{{ $course_name }}">
                            {{ $course_name ?: $course_code ?: '—' }}
                        </span>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Batch Year</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <i class="fa-solid fa-calendar text-gray-400 text-xs"></i>
                        <span class="text-sm font-semibold text-gray-900">{{ $batch ?: '—' }}</span>
                    </div>
                </div>
                <div class="col-span-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Email Address</p>
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200">
                        <i class="fa-solid fa-envelope text-gray-400 text-xs"></i>
                        <span class="text-sm font-semibold text-gray-900">{{ $email ?: '—' }}</span>
                        <span class="ml-auto inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">
                            <i class="fa-solid fa-check text-[10px]"></i> Verified
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ SECTIONS 2–4 ══════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- ══ SECTION 2 — PERSONAL DETAILS ════════════════════════════════ --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-blue-600 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-person text-white text-xs"></i>
                </div>
                <div>
                    <h2 class="font-bold text-gray-900 text-sm">Personal Details</h2>
                    <p class="text-xs text-gray-500">Basic personal information</p>
                </div>
                @if(!$editing)
                    <span class="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold
                                 px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                        <i class="fa-solid fa-eye text-xs"></i> View Only
                    </span>
                @endif
            </div>

            <div class="p-4 space-y-3">

                {{-- Gender --}}
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">
                        Gender @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    @if($editing)
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input wire:model="gender" type="radio" value="Male"
                                       class="w-4 h-4 accent-blue-600 cursor-pointer">
                                <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors">
                                    <i class="fa-solid fa-mars text-blue-500 mr-1"></i>Male
                                </span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input wire:model="gender" type="radio" value="Female"
                                       class="w-4 h-4 accent-pink-500 cursor-pointer">
                                <span class="text-sm font-medium text-gray-700 group-hover:text-pink-500 transition-colors">
                                    <i class="fa-solid fa-venus text-pink-500 mr-1"></i>Female
                                </span>
                            </label>
                        </div>
                        @error('gender')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1">
                                <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                            </p>
                        @enderror
                    @else
                        <div class="px-3 py-2 rounded-xl field-disabled text-sm">
                            @if($gender === 'Male') <i class="fa-solid fa-mars text-blue-400 mr-1.5"></i>
                            @elseif($gender === 'Female') <i class="fa-solid fa-venus text-pink-400 mr-1.5"></i>
                            @endif
                            {{ $gender ?: '—' }}
                        </div>
                    @endif
                </div>

                {{-- Date of Birth + Civil Status --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Date of Birth @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                               {{ !$editing ? 'disabled' : '' }}
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('date_of_birth') ? ' border-red-400' : '') : 'field-disabled' }}">
                        @error('date_of_birth')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Civil Status @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <select wire:model="civil_status" {{ !$editing ? 'disabled' : '' }}
                                class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('civil_status') ? ' border-red-400' : '') : 'field-disabled' }}">
                            <option value="">— Select —</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                            <option value="Annulled">Annulled</option>
                        </select>
                        @error('civil_status')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Place of Birth --}}
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1">
                        Place of Birth @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="place_of_birth" type="text" placeholder="City/Municipality, Province"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('place_of_birth') ? ' border-red-400' : '') : 'field-disabled' }}">
                    @error('place_of_birth')
                        <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

                {{-- Citizenship + Blood Type --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Citizenship @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <input wire:model="citizenship" type="text" placeholder="e.g. Filipino"
                               {{ !$editing ? 'disabled' : '' }}
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('citizenship') ? ' border-red-400' : '') : 'field-disabled' }}">
                        @error('citizenship')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Blood Type <span class="text-gray-400 font-normal text-xs">(optional)</span>
                        </label>
                        <select wire:model="blood_type" {{ !$editing ? 'disabled' : '' }}
                                class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable' : 'field-disabled' }}">
                            <option value="">— Select —</option>
                            <option value="A+">A+</option>
                            <option value="B+">B+</option>
                            <option value="AB+">AB+</option>
                            <option value="O+">O+</option>
                            <option value="Unknown">Unknown</option>
                        </select>
                    </div>
                </div>

                {{-- Contact Number --}}
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1">
                        Contact Number @if($editing)<span class="text-red-500">*</span>@endif
                    </label>
                    <input wire:model="contact_number" type="tel" placeholder="e.g. 09xx-xxx-xxxx"
                           {{ !$editing ? 'disabled' : '' }}
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('contact_number') ? ' border-red-400' : '') : 'field-disabled' }}">
                    @error('contact_number')
                        <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                    @enderror
                </div>

            </div>
        </div>

        {{-- ══ RIGHT COLUMN ══════════════════════════════════════════════════ --}}
        <div class="space-y-4">

            {{-- ══ SECTION 3 — FAMILY BACKGROUND ══════════════════════════ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-rose-500 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-people-roof text-white text-xs"></i>
                    </div>
                    <div>
                        <h2 class="font-bold text-gray-900 text-sm">Family Background</h2>
                        <p class="text-xs text-gray-500">Parents and spouse information</p>
                    </div>
                    @if(!$editing)
                        <span class="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold
                                     px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                            <i class="fa-solid fa-eye text-xs"></i> View Only
                        </span>
                    @endif
                </div>

                <div class="p-4 space-y-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Father's Full Name @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <input wire:model="father_name" type="text" placeholder="First Middle Last"
                               {{ !$editing ? 'disabled' : '' }}
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('father_name') ? ' border-red-400' : '') : 'field-disabled' }}">
                        @error('father_name')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Mother's Full Name @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <input wire:model="mother_name" type="text" placeholder="First Middle Last (Maiden)"
                               {{ !$editing ? 'disabled' : '' }}
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('mother_name') ? ' border-red-400' : '') : 'field-disabled' }}">
                        @error('mother_name')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Spouse Name <span class="text-gray-400 font-normal text-xs">(if married)</span>
                        </label>
                        <input wire:model="spouse_name" type="text" placeholder="Full name of spouse"
                               {{ !$editing ? 'disabled' : '' }}
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable' : 'field-disabled' }}">
                    </div>
                </div>
            </div>

            {{-- ══ SECTION 4 — HOME ADDRESS ═════════════════════════════════ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-emerald-600 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-map-location-dot text-white text-xs"></i>
                    </div>
                    <div>
                        <h2 class="font-bold text-gray-900 text-sm">Home Address</h2>
                        <p class="text-xs text-gray-500">Current residential address</p>
                    </div>
                    @if(!$editing)
                        <span class="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold
                                     px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                            <i class="fa-solid fa-eye text-xs"></i> View Only
                        </span>
                    @endif
                </div>

                <div class="p-4 space-y-3">
                    <div class="grid grid-cols-4 gap-2">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                No. <span class="text-gray-400 font-normal text-xs">(opt.)</span>
                            </label>
                            <input wire:model="address_no" type="text" placeholder="123"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable' : 'field-disabled' }}">
                        </div>
                        <div class="col-span-3">
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Street @if($editing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="address_street" type="text" placeholder="Street name"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_street') ? ' border-red-400' : '') : 'field-disabled' }}">
                            @error('address_street')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Barangay @if($editing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="address_barangay" type="text" placeholder="Barangay"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_barangay') ? ' border-red-400' : '') : 'field-disabled' }}">
                            @error('address_barangay')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Municipality / City @if($editing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="address_municipality" type="text" placeholder="Municipality or City"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_municipality') ? ' border-red-400' : '') : 'field-disabled' }}">
                            @error('address_municipality')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Province @if($editing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="address_province" type="text" placeholder="Province"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_province') ? ' border-red-400' : '') : 'field-disabled' }}">
                            @error('address_province')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Zip Code @if($editing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="address_zip_code" type="text" maxlength="10" placeholder="e.g. 2400"
                                   {{ !$editing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_zip_code') ? ' border-red-400' : '') : 'field-disabled' }}">
                            @error('address_zip_code')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- ══ ACTION BUTTONS — only visible in edit mode ════════════════════════ --}}
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

            <button wire:click="cancelEditing"
                    wire:loading.attr="disabled"
                    wire:target="cancelEditing"
                    class="flex-1 py-3 rounded-xl font-bold text-sm border-2 border-gray-300 bg-white
                           text-gray-700 hover:bg-gray-100 active:scale-[0.98] transition-all
                           flex items-center justify-center gap-2">
                <i class="fa-solid fa-xmark mr-1.5"></i> Cancel
            </button>
        </div>
    @endif

    @if (!$profileComplete && !$editing)
        <p class="text-xs text-center text-gray-400 pb-2">
            <i class="fa-solid fa-circle-info mr-1 text-blue-400"></i>
            Click <strong>Edit Profile</strong> to fill in your information.
        </p>
    @endif

</div>