{{-- resources/views/livewire/alumni/alumni-information.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;
use App\Models\EmploymentTracking;

new class extends Component {

    // ── Student Record (read-only) ────────────────────────────────────────────
    public string $first_name     = '';
    public string $middle_initial = '';
    public string $last_name      = '';
    public string $suffix         = '';
    public string $student_id     = '';
    public string $course_code    = '';
    public string $course_name    = '';
    public string $batch          = '';
    public string $email          = '';

    // ── Personal Details ──────────────────────────────────────────────────────
    public string $gender         = '';
    public string $date_of_birth  = '';
    public string $place_of_birth = '';
    public string $citizenship    = 'Filipino';
    public string $civil_status   = '';
    public string $blood_type     = '';
    public string $contact_number = '';

    // ── Family Background ─────────────────────────────────────────────────────
    public string $father_name = '';
    public string $mother_name = '';
    public string $spouse_name = '';

    // ── Home Address ──────────────────────────────────────────────────────────
    public string $address_no           = '';
    public string $address_street       = '';
    public string $address_barangay     = '';
    public string $address_municipality = '';
    public string $address_province     = '';
    public string $address_zip_code     = '';

    // ── Employment Tracking ───────────────────────────────────────────────────
    public string $employment_status   = '';
    public string $company_name        = '';
    public string $job_title           = '';
    public string $employment_type     = '';
    public string $work_location       = '';
    public string $date_hired          = '';
    public array  $career_path         = [];
    public string $education_status    = '';
    public string $course_relevance    = '';
    public string $unemployment_status = '';

    // ── UI State ──────────────────────────────────────────────────────────────
    public string $errorMessage      = '';
    public string $successMessage    = '';
    public string $empErrorMessage   = '';
    public string $empSuccessMessage = '';
    public bool   $profileComplete   = false;
    public bool   $editing           = false;
    public bool   $empEditing        = false;
    public bool   $empRecordExists   = false;
    public int    $alumniId          = 0;

    protected array $snapshot    = [];
    protected array $empSnapshot = [];

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
            ])->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId             = $alumni->id;
        $this->first_name           = $alumni->first_name           ?? '';
        $this->middle_initial       = $alumni->middle_initial       ?? '';
        $this->last_name            = $alumni->last_name            ?? '';
        $this->suffix               = $alumni->suffix               ?? '';
        $this->student_id           = $alumni->student_id           ?? '';
        $this->course_code          = $alumni->course_code          ?? '';
        $this->course_name          = $alumni->course_name          ?? '';
        $this->batch                = (string)($alumni->batch       ?? '');
        $this->email                = $user->email                  ?? '';
        $this->gender               = $alumni->gender               ?? '';
        $this->date_of_birth        = $alumni->date_of_birth
                                        ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('Y-m-d') : '';
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
        $this->profileComplete      = (bool)($alumni->profile_completed ?? false);
        $this->editing              = !$this->profileComplete;

        $emp = EmploymentTracking::where('alumni_id', $alumni->id)
            ->select([
                'employment_status','company_name','job_title',
                'employment_type','work_location','date_hired',
                'career_path','education_status','course_relevance','unemployment_status',
            ])->first();

        if ($emp) {
            $this->empRecordExists     = true;
            $this->employment_status   = $emp->employment_status   ?? '';
            $this->company_name        = $emp->company_name        ?? '';
            $this->job_title           = $emp->job_title           ?? '';
            $this->employment_type     = $emp->employment_type     ?? '';
            $this->work_location       = $emp->work_location       ?? '';
            $this->date_hired          = $emp->date_hired
                                           ? \Carbon\Carbon::parse($emp->date_hired)->format('Y-m-d') : '';
            $this->career_path         = is_array($emp->career_path)
                                           ? $emp->career_path
                                           : (json_decode($emp->career_path ?? '[]', true) ?? []);
            $this->education_status    = $emp->education_status    ?? '';
            $this->course_relevance    = $emp->course_relevance    ?? '';
            $this->unemployment_status = $emp->unemployment_status ?? '';
        }

        $this->empEditing = false;
    }

    // ── Profile edit / cancel ─────────────────────────────────────────────────

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $keys = [
            'gender','date_of_birth','place_of_birth','citizenship',
            'civil_status','blood_type','contact_number',
            'father_name','mother_name','spouse_name',
            'address_no','address_street','address_barangay',
            'address_municipality','address_province','address_zip_code',
        ];
        $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) { $this->$k = $v; }
        $this->editing = false;
    }

    // ── Employment edit / cancel ──────────────────────────────────────────────

    public function startEmpEditing(): void
    {
        $this->empErrorMessage = $this->empSuccessMessage = '';
        $keys = ['employment_status','company_name','job_title','employment_type',
                 'work_location','date_hired','career_path','education_status',
                 'course_relevance','unemployment_status'];
        $this->empSnapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));
        $this->empEditing = true;
    }

    public function cancelEmpEditing(): void
    {
        $this->empErrorMessage = $this->empSuccessMessage = '';
        $this->resetValidation();
        foreach ($this->empSnapshot as $k => $v) { $this->$k = $v; }
        $this->empEditing = false;
    }

    // ── Save profile ──────────────────────────────────────────────────────────

    public function saveProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';

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

        try {
            $profileComplete =
                !empty($this->gender) && !empty($this->date_of_birth)
                && !empty($this->place_of_birth) && !empty($this->citizenship)
                && !empty($this->civil_status) && !empty($this->contact_number)
                && !empty($this->father_name) && !empty($this->mother_name)
                && !empty($this->address_street) && !empty($this->address_barangay)
                && !empty($this->address_municipality)
                && !empty($this->address_province) && !empty($this->address_zip_code);

            DB::table('alumni')->where('id', $this->alumniId)->update([
                'gender'               => $this->gender,
                'date_of_birth'        => $this->date_of_birth ?: null,
                'place_of_birth'       => $this->place_of_birth,
                'citizenship'          => $this->citizenship,
                'civil_status'         => $this->civil_status,
                'blood_type'           => $this->blood_type ?: null,
                'contact_number'       => $this->contact_number,
                'father_name'          => $this->father_name,
                'mother_name'          => $this->mother_name,
                'spouse_name'          => $this->spouse_name ?: null,
                'address_no'           => $this->address_no ?: null,
                'address_street'       => $this->address_street,
                'address_barangay'     => $this->address_barangay,
                'address_municipality' => $this->address_municipality,
                'address_province'     => $this->address_province,
                'address_zip_code'     => $this->address_zip_code,
                'profile_completed'    => $profileComplete,
                'updated_at'           => now(),
            ]);

            $this->profileComplete = $profileComplete;
            $this->editing = false;
            $this->successMessage = $profileComplete
                ? 'Profile saved successfully!'
                : 'Progress saved. Fill in all required fields to complete your profile.';

            Log::info("Alumni profile saved | student_id: {$this->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
        }
    }

    // ── Save employment ───────────────────────────────────────────────────────

    public function saveEmployment(): void
    {
        $this->empErrorMessage = $this->empSuccessMessage = '';

        if (empty($this->employment_status)) {
            $this->empEditing = false;
            $this->empSuccessMessage = 'Employment section skipped. You can fill it in anytime.';
            return;
        }

        $this->validate([
            'employment_status' => 'required|string|in:employed,self_employed,unemployed',
        ], ['employment_status.required' => 'Please select your employment status.']);

        if (in_array($this->employment_status, ['employed', 'self_employed'], true)) {
            $this->validate([
                'company_name'     => 'required|string|max:255',
                'job_title'        => 'required|string|max:255',
                'employment_type'  => 'required|string|in:full_time,part_time,contractual,project_based,internship',
                'work_location'    => 'required|string|in:local,abroad',
                'date_hired'       => 'required|date|before_or_equal:today',
                'career_path'      => 'nullable|array|max:5',
                'career_path.*'    => 'string|in:ofw,freelancer,entrepreneur,career_shifter,industry_professional',
                'education_status' => 'required|string|in:none,pursuing_masteral,pursuing_doctorate',
                'course_relevance' => 'required|string|in:yes,no,partially',
            ], [
                'company_name.required'      => 'Company / Business name is required.',
                'job_title.required'         => 'Job title is required.',
                'employment_type.required'   => 'Please select an employment type.',
                'work_location.required'     => 'Please select a work location.',
                'date_hired.required'        => 'Date hired is required.',
                'date_hired.before_or_equal' => 'Date hired cannot be in the future.',
                'education_status.required'  => 'Please select your education status.',
                'course_relevance.required'  => 'Please indicate course relevance.',
            ]);
        }

        if ($this->employment_status === 'unemployed') {
            $this->validate([
                'unemployment_status' => 'required|string|in:seeking_employment,not_looking',
            ], ['unemployment_status.required' => 'Please select your unemployment status.']);
        }

        try {
            $isEmp = in_array($this->employment_status, ['employed', 'self_employed'], true);

            $payload = [
                'employment_status'   => $this->employment_status,
                'company_name'        => $isEmp ? $this->company_name     : null,
                'job_title'           => $isEmp ? $this->job_title        : null,
                'employment_type'     => $isEmp ? $this->employment_type  : null,
                'work_location'       => $isEmp ? $this->work_location    : null,
                'date_hired'          => $isEmp && $this->date_hired ? $this->date_hired : null,
                'career_path'         => $isEmp ? json_encode($this->career_path) : null,
                'education_status'    => $isEmp ? $this->education_status : null,
                'course_relevance'    => $isEmp ? $this->course_relevance : null,
                'unemployment_status' => $this->employment_status === 'unemployed' ? $this->unemployment_status : null,
                'updated_at'          => now(),
            ];

            DB::transaction(function () use ($payload) {
                if (EmploymentTracking::where('alumni_id', $this->alumniId)->exists()) {
                    DB::table('employment_trackings')->where('alumni_id', $this->alumniId)->update($payload);
                } else {
                    DB::table('employment_trackings')->insert(array_merge($payload, [
                        'alumni_id'  => $this->alumniId,
                        'created_at' => now(),
                    ]));
                    $this->empRecordExists = true;
                }
            });

            $this->empRecordExists   = true;
            $this->empEditing        = false;
            $this->empSuccessMessage = 'Employment information saved successfully!';

            Log::info("Employment saved | alumni_id: {$this->alumniId} | status: {$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('saveEmployment error: ' . $e->getMessage());
            $this->empErrorMessage = 'Failed to save. Please try again.';
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
.field-editable.err   { border-color: #ef4444; }

.field-disabled {
    border: 1.5px solid #e5e7eb;
    background: #f9fafb;
    color: #374151;
    cursor: default;
    pointer-events: none;
    opacity: .85;
}

/* Radio pill */
.radio-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; border: 1.5px solid #e5e7eb;
    border-radius: .75rem; cursor: pointer;
    transition: border-color .15s, background .15s; font-size: .875rem;
}
.radio-pill:hover { border-color: #7a3f91; background: #f9f5ff; }

/* Checkbox card */
.check-card {
    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
    border: 1.5px solid #e5e7eb; border-radius: .75rem; cursor: pointer;
    transition: border-color .15s, background .15s; font-size: .8rem;
}
.check-card:hover  { border-color: #7a3f91; background: #f9f5ff; }
.check-card.active { border-color: #7a3f91; background: #f9f5ff; }

/* Career path chip */
.career-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 9999px;
    background: #ede9fe; color: #5b21b6; font-size: .75rem; font-weight: 600;
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
                        <div class="flex gap-3">
                            <label class="radio-pill">
                                <input wire:model="gender" type="radio" value="Male" class="w-4 h-4 accent-blue-600">
                                <i class="fa-solid fa-mars text-blue-500"></i>
                                <span class="font-medium text-gray-700">Male</span>
                            </label>
                            <label class="radio-pill">
                                <input wire:model="gender" type="radio" value="Female" class="w-4 h-4 accent-pink-500">
                                <i class="fa-solid fa-venus text-pink-500"></i>
                                <span class="font-medium text-gray-700">Female</span>
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
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('date_of_birth') ? ' err' : '') : 'field-disabled' }}">
                        @error('date_of_birth')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            Civil Status @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        <select wire:model="civil_status" {{ !$editing ? 'disabled' : '' }}
                                class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('civil_status') ? ' err' : '') : 'field-disabled' }}">
                            <option value="">— Select —</option>
                            @foreach(['Single','Married','Widowed','Separated','Annulled'] as $s)
                                <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
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
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('place_of_birth') ? ' err' : '') : 'field-disabled' }}">
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
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('citizenship') ? ' err' : '') : 'field-disabled' }}">
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
                            @foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'] as $bt)
                                <option value="{{ $bt }}">{{ $bt }}</option>
                            @endforeach
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
                           class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('contact_number') ? ' err' : '') : 'field-disabled' }}">
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
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('father_name') ? ' err' : '') : 'field-disabled' }}">
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
                               class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('mother_name') ? ' err' : '') : 'field-disabled' }}">
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
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_street') ? ' err' : '') : 'field-disabled' }}">
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
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_barangay') ? ' err' : '') : 'field-disabled' }}">
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
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_municipality') ? ' err' : '') : 'field-disabled' }}">
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
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_province') ? ' err' : '') : 'field-disabled' }}">
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
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $editing ? 'field-editable'.($errors->has('address_zip_code') ? ' err' : '') : 'field-disabled' }}">
                            @error('address_zip_code')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- ══ PROFILE ACTION BUTTONS ════════════════════════════════════════════ --}}
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
                    class="flex-1 py-3 rounded-xl font-bold text-sm border-2 border-gray-300 bg-white
                           text-gray-700 hover:bg-gray-100 active:scale-[0.98] transition-all
                           flex items-center justify-center gap-2">
                <i class="fa-solid fa-xmark mr-1.5"></i> Cancel
            </button>
        </div>
    @endif

    @if(!$profileComplete && !$editing)
        <p class="text-xs text-center text-gray-400 pb-2">
            <i class="fa-solid fa-circle-info mr-1 text-blue-400"></i>
            Click <strong>Edit Profile</strong> to fill in your information.
        </p>
    @endif

    {{-- ══════════════════════════════════════════════════════════════════════
         SECTION 5 — EMPLOYMENT INFORMATION
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div class="border-t-2 border-dashed border-gray-200 pt-4">

        {{-- Employment section header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <div>
                <h2 class="text-base font-extrabold text-gray-900 tracking-tight flex flex-wrap items-center gap-2">
                    <i class="fa-solid fa-briefcase text-violet-600"></i>
                    Employment Information
                    <span class="inline-flex items-center text-xs font-normal px-2 py-0.5 rounded-full
                                 bg-gray-100 border border-gray-200 text-gray-500">Optional</span>
                </h2>
                <p class="text-xs text-gray-500 mt-0.5">Your employment status and career details — you can fill this in anytime.</p>
            </div>
            @if(!$empEditing && $empRecordExists)
                <button wire:click="startEmpEditing"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-bold
                               text-white shadow-sm transition hover:opacity-90 active:scale-95 self-start sm:self-auto"
                        style="background-color:#7a3f91;">
                    <i class="fa-solid fa-pen"></i> Edit Employment
                </button>
            @endif
        </div>

        {{-- Employment alerts --}}
        @if($empErrorMessage)
            <div class="p-3 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-2 mb-4">
                <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-600 text-sm"></i>
                <p class="text-sm font-medium">{{ $empErrorMessage }}</p>
            </div>
        @endif
        @if($empSuccessMessage)
            <div class="p-3 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-2 mb-4">
                <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-600 text-sm"></i>
                <p class="text-sm font-medium">{{ $empSuccessMessage }}</p>
            </div>
        @endif

        {{-- Employment Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3" style="background-color:#f9f5ff;">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background-color:#7a3f91;">
                    <i class="fa-solid fa-briefcase text-white text-xs"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-gray-900 text-sm">Employment & Career Tracking</h3>
                    <p class="text-xs text-gray-500">Status, workplace, and career path</p>
                </div>
                @if(!$empEditing)
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-100 text-gray-500">
                        <i class="fa-solid fa-eye text-xs"></i> View Only
                    </span>
                @endif
            </div>

            <div class="p-5 space-y-5">

                {{-- ── 1. Employment Status ──────────────────────────────── --}}
                <div>
                    <label class="block text-sm font-bold text-gray-800 mb-2">
                        Employment Status
                        @if($empEditing)
                            <span class="text-gray-400 font-normal text-xs">(leave blank to skip)</span>
                        @endif
                    </label>
                    @if($empEditing)
                        <div class="flex flex-wrap gap-2">
                            @foreach(['employed' => ['label'=>'Employed','icon'=>'fa-briefcase'], 'self_employed' => ['label'=>'Self-Employed','icon'=>'fa-store'], 'unemployed' => ['label'=>'Unemployed','icon'=>'fa-circle-pause']] as $val => $opt)
                                <label class="radio-pill">
                                    <input wire:model.live="employment_status" type="radio" value="{{ $val }}"
                                           class="w-4 h-4 accent-violet-600">
                                    <i class="fa-solid {{ $opt['icon'] }} text-violet-500"></i>
                                    <span class="font-medium text-gray-700">{{ $opt['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('employment_status')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    @else
                        @php
                            $statusMap  = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'];
                            $statusIcon = ['employed'=>'fa-briefcase text-violet-500','self_employed'=>'fa-store text-blue-500','unemployed'=>'fa-circle-pause text-amber-500'];
                        @endphp
                        <div class="px-3 py-2 rounded-xl field-disabled text-sm font-semibold">
                            @if($employment_status)
                                <i class="fa-solid {{ $statusIcon[$employment_status] ?? 'fa-question' }} mr-1.5"></i>
                                {{ $statusMap[$employment_status] ?? $employment_status }}
                            @else
                                <span class="text-gray-400 italic font-normal">Not filled in yet</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- ── 2. EMPLOYED / SELF-EMPLOYED FIELDS ─────────────────── --}}
                @if(in_array($employment_status, ['employed','self_employed']))
                <div class="border-t border-gray-100 pt-5 space-y-4">

                    {{-- Company + Job Title --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Company / Business Name @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="company_name" type="text" placeholder="e.g. Acme Corporation"
                                   {{ !$empEditing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $empEditing ? 'field-editable'.($errors->has('company_name') ? ' err' : '') : 'field-disabled' }}">
                            @error('company_name')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Job Title @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="job_title" type="text" placeholder="e.g. Software Engineer"
                                   {{ !$empEditing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $empEditing ? 'field-editable'.($errors->has('job_title') ? ' err' : '') : 'field-disabled' }}">
                            @error('job_title')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- Employment Type + Work Location --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Employment Type @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            <select wire:model="employment_type" {{ !$empEditing ? 'disabled' : '' }}
                                    class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $empEditing ? 'field-editable'.($errors->has('employment_type') ? ' err' : '') : 'field-disabled' }}">
                                <option value="">— Select —</option>
                                @foreach(['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'] as $v=>$l)
                                    <option value="{{ $v }}">{{ $l }}</option>
                                @endforeach
                            </select>
                            @error('employment_type')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Work Location @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            @if($empEditing)
                                <div class="flex flex-wrap gap-2 mt-1">
                                    <label class="radio-pill">
                                        <input wire:model="work_location" type="radio" value="local" class="w-4 h-4 accent-violet-600">
                                        <i class="fa-solid fa-house text-emerald-500"></i>
                                        <span class="font-medium text-gray-700">Local</span>
                                    </label>
                                    <label class="radio-pill">
                                        <input wire:model="work_location" type="radio" value="abroad" class="w-4 h-4 accent-violet-600">
                                        <i class="fa-solid fa-plane-departure text-amber-500"></i>
                                        <span class="font-medium text-gray-700">Abroad (OFW)</span>
                                    </label>
                                </div>
                                @error('work_location')
                                    <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                                @enderror
                            @else
                                <div class="px-3 py-2 rounded-xl field-disabled text-sm mt-1">
                                    @if($work_location === 'abroad')<i class="fa-solid fa-plane-departure text-amber-400 mr-1.5"></i>Abroad (OFW)
                                    @elseif($work_location === 'local')<i class="fa-solid fa-house text-emerald-500 mr-1.5"></i>Local
                                    @else —@endif
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Date Hired --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Date Hired @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            <input wire:model="date_hired" type="date" max="{{ date('Y-m-d') }}"
                                   {{ !$empEditing ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $empEditing ? 'field-editable'.($errors->has('date_hired') ? ' err' : '') : 'field-disabled' }}">
                            @error('date_hired')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- Career Path --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">
                            Career Path
                            <span class="text-gray-400 font-normal text-xs">(select all that apply)</span>
                        </label>
                        @php
                            $careerOpts = [
                                'ofw'                   => ['icon'=>'fa-plane-departure', 'label'=>'OFW'],
                                'freelancer'            => ['icon'=>'fa-laptop-code',     'label'=>'Freelancer'],
                                'entrepreneur'          => ['icon'=>'fa-store',           'label'=>'Entrepreneur'],
                                'career_shifter'        => ['icon'=>'fa-arrows-rotate',   'label'=>'Career Shifter'],
                                'industry_professional' => ['icon'=>'fa-user-tie',        'label'=>'Industry Professional'],
                            ];
                        @endphp
                        @if($empEditing)
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                @foreach($careerOpts as $val => $opt)
                                    <label class="check-card {{ in_array($val, $career_path) ? 'active' : '' }}">
                                        <input wire:model="career_path" type="checkbox" value="{{ $val }}"
                                               class="w-4 h-4 accent-violet-600 flex-shrink-0">
                                        <i class="fa-solid {{ $opt['icon'] }} text-violet-500 text-xs flex-shrink-0"></i>
                                        <span class="font-medium text-gray-700">{{ $opt['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @forelse(array_filter($careerOpts, fn($k) => in_array($k, $career_path), ARRAY_FILTER_USE_KEY) as $key => $opt)
                                    <span class="career-chip">
                                        <i class="fa-solid {{ $opt['icon'] }} text-xs"></i>
                                        {{ $opt['label'] }}
                                    </span>
                                @empty
                                    <span class="text-sm text-gray-400 italic">None selected</span>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    {{-- Education Status + Course Relevance --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Education Status @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            <select wire:model="education_status" {{ !$empEditing ? 'disabled' : '' }}
                                    class="w-full px-3 py-2 text-sm rounded-xl transition-all {{ $empEditing ? 'field-editable'.($errors->has('education_status') ? ' err' : '') : 'field-disabled' }}">
                                <option value="">— Select —</option>
                                <option value="none">None</option>
                                <option value="pursuing_masteral">Pursuing Masteral</option>
                                <option value="pursuing_doctorate">Pursuing Doctorate</option>
                            </select>
                            @error('education_status')
                                <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Job Related to Course? @if($empEditing)<span class="text-red-500">*</span>@endif
                            </label>
                            @if($empEditing)
                                <div class="flex flex-wrap gap-2 mt-1">
                                    @foreach(['yes'=>'Yes','no'=>'No','partially'=>'Partially'] as $v=>$l)
                                        <label class="radio-pill">
                                            <input wire:model="course_relevance" type="radio" value="{{ $v }}" class="w-4 h-4 accent-violet-600">
                                            <span class="font-medium text-gray-700">{{ $l }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('course_relevance')
                                    <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                                @enderror
                            @else
                                @php
                                    $relColor = ['yes'=>'bg-emerald-100 text-emerald-800','no'=>'bg-red-100 text-red-800','partially'=>'bg-amber-100 text-amber-800'];
                                    $relLabel = ['yes'=>'Yes','no'=>'No','partially'=>'Partially'];
                                @endphp
                                <div class="mt-1">
                                    @if($course_relevance)
                                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold {{ $relColor[$course_relevance] ?? 'bg-gray-100 text-gray-600' }}">
                                            {{ $relLabel[$course_relevance] ?? $course_relevance }}
                                        </span>
                                    @else
                                        <span class="text-sm text-gray-400 italic">—</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                </div>
                @endif

                {{-- ── 3. UNEMPLOYED FIELDS ──────────────────────────────── --}}
                @if($employment_status === 'unemployed')
                <div class="border-t border-gray-100 pt-5">
                    <label class="block text-sm font-semibold text-gray-800 mb-2">
                        Unemployment Status @if($empEditing)<span class="text-red-500">*</span>@endif
                    </label>
                    @if($empEditing)
                        <div class="flex flex-wrap gap-2">
                            @foreach(['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'] as $v=>$l)
                                <label class="radio-pill">
                                    <input wire:model="unemployment_status" type="radio" value="{{ $v }}" class="w-4 h-4 accent-amber-500">
                                    <span class="font-medium text-gray-700">{{ $l }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('unemployment_status')
                            <p class="text-xs text-red-600 mt-1 flex items-center gap-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                        @enderror
                    @else
                        <div class="px-3 py-2 rounded-xl field-disabled text-sm font-semibold">
                            <i class="fa-solid fa-circle-pause text-amber-400 mr-1.5"></i>
                            {{ ['seeking_employment'=>'Seeking Employment','not_looking'=>'Currently Not Looking'][$unemployment_status] ?? ($unemployment_status ?: '—') }}
                        </div>
                    @endif
                </div>
                @endif

                {{-- Empty state --}}
                @if(!$employment_status && !$empEditing)
                    <div class="text-center py-8">
                        <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                            <i class="fa-solid fa-briefcase text-gray-400 text-xl"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-600">No employment information yet</p>
                        <p class="text-xs text-gray-400 mt-1">This section is optional — fill it in when you're ready.</p>
                        <button wire:click="startEmpEditing"
                                class="inline-flex items-center gap-2 mt-4 px-5 py-2 rounded-xl text-sm font-bold
                                       text-white shadow-sm transition hover:opacity-90 active:scale-95"
                                style="background-color:#7a3f91;">
                            <i class="fa-solid fa-plus"></i> Add Employment Info
                        </button>
                    </div>
                @endif

            </div>
        </div>

        {{-- Employment Action Buttons --}}
        @if($empEditing)
            <div class="flex flex-col sm:flex-row gap-3 mt-4">
                <button wire:click="saveEmployment"
                        wire:loading.attr="disabled"
                        wire:target="saveEmployment"
                        class="flex-1 text-white py-3 rounded-xl font-bold text-sm shadow-md hover:opacity-90
                               disabled:opacity-70 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                        style="background-color:#7a3f91;">
                    <span wire:loading.remove wire:target="saveEmployment">
                        <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Employment Info
                    </span>
                    <span wire:loading wire:target="saveEmployment">
                        <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Saving…
                    </span>
                </button>
                <button wire:click="cancelEmpEditing"
                        class="flex-1 py-3 rounded-xl font-bold text-sm border-2 border-gray-300 bg-white
                               text-gray-700 hover:bg-gray-100 active:scale-[0.98] transition-all
                               flex items-center justify-center gap-2">
                    <i class="fa-solid fa-xmark mr-1.5"></i>
                    {{ $empRecordExists ? 'Cancel' : 'Skip for Now' }}
                </button>
            </div>
        @endif

    </div>{{-- /employment section --}}

</div>