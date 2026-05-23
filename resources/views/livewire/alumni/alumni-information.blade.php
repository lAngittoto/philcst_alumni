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

{{-- ROOT --}}
<div>

<style>
    /* ── Card / field styles ── */
    .ai-field-label {
        font-size: .7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #555555;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .ai-field-value {
        font-size: .92rem;
        font-weight: 600;
        color: #1a1a1a;
        word-break: break-word;
        line-height: 1.3;
    }
    .ai-card {
        background: #fff;
        border: 1px solid #ECECEC;
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .ai-card-header {
        padding: 6px 13px;
        border-bottom: 1px solid #F2F2F2;
        background: #FAFAFA;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    .ai-card-header p {
        font-size: .75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: #444444;
        margin: 0;
    }
    .ai-cell {
        padding: 7px 11px;
        border: 1px solid #F5F5F5;
        background: #fff;
        border-radius: 6px;
    }

    /* ── Input styling (edit mode) ── */
    .ai-input {
        width: 100%;
        box-sizing: border-box;
        font-size: .88rem;
        font-weight: 600;
        color: #1a1a1a;
        background: #FAFAFA;
        border: 1.5px solid #E4E4E4;
        border-radius: 7px;
        padding: 5px 9px;
        text-transform: uppercase;
        letter-spacing: .02em;
        transition: border-color .15s, box-shadow .15s, background .15s;
        outline: none;
    }
    .ai-input::placeholder {
        color: #CCCCCC;
        font-weight: 400;
        text-transform: none;
        letter-spacing: 0;
    }
    .ai-input:hover  { border-color: #c49ed8; }
    .ai-input:focus  {
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,.10);
        background: #fff;
    }
    .ai-input.error  { border-color: #ef4444; }

    /* ── Radio ── */
    .ai-radio-label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        font-size: .88rem;
        font-weight: 600;
        color: #1a1a1a;
        user-select: none;
    }
    .ai-radio-label input[type="radio"] {
        width: 15px; height: 15px;
        accent-color: #7A3F91;
        cursor: pointer;
    }

    /* ── Alert banners ── */
    .ai-alert {
        border-radius: 10px;
        padding: 8px 14px;
        font-size: .82rem;
        font-weight: 500;
        border: 1.5px solid;
        display: flex;
        align-items: flex-start;
        gap: 8px;
        flex-shrink: 0;
    }
    .ai-alert.success { background: #F0FDF4; color: #166534; border-color: #BBF7D0; }
    .ai-alert.error   { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }
    .ai-alert.warning { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }

    /* ── Buttons ── */
    .ai-btn-primary {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 6px; padding: 8px 22px; border-radius: 8px;
        font-size: .88rem; font-weight: 600; color: #fff;
        background: linear-gradient(135deg, #7A3F91, #9b59b6);
        border: none; cursor: pointer;
        transition: opacity .15s, transform .1s;
        white-space: nowrap;
    }
    .ai-btn-primary:hover:not(:disabled) { opacity: .9; }
    .ai-btn-primary:active:not(:disabled) { transform: scale(.98); }
    .ai-btn-primary:disabled { opacity: .55; cursor: not-allowed; }

    .ai-btn-ghost {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 6px; padding: 8px 18px; border-radius: 8px;
        font-size: .88rem; font-weight: 600; color: #444;
        background: #fff; border: 1.5px solid #E4E4E4; cursor: pointer;
        transition: background .15s, border-color .15s, transform .1s;
    }
    .ai-btn-ghost:hover  { background: #F5F5F5; border-color: #CCCCCC; }
    .ai-btn-ghost:active { transform: scale(.98); }

    /* ── Hint / error text ── */
    .ai-hint      { font-size: .68rem; color: #AAAAAA; font-weight: 400; margin-top: 2px; }
    .ai-error-msg { font-size: .68rem; color: #ef4444; font-weight: 500; margin-top: 2px; }

    /* ── Page header icon ── */
    .ai-header-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        background: linear-gradient(135deg, #7A3F91, #9b59b6);
        box-shadow: 0 4px 12px rgba(122,63,145,.30);
    }

    /* ── Page wrapper ── */
    .ai-page-wrap {
        height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        padding: 14px 16px 10px;
        box-sizing: border-box;
        gap: 6px;
    }

    /* ── CSS grid body — auto rows, scrollable ── */
    .ai-body-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        grid-template-rows: auto auto auto auto;
        align-items: start;
        gap: 6px;
        flex: 1;
        min-height: 0;
        overflow-y: auto;
    }

    .ai-full { grid-column: 1 / -1; }
</style>

<div class="ai-page-wrap">

    {{-- ── Page Header ─────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-3" style="flex-shrink:0;">
        <div class="flex items-center gap-3">
            <div class="ai-header-icon">
                <i class="fa-solid fa-address-card text-white" style="font-size:1.1rem;"></i>
            </div>
            <div>
                <h1 class="font-semibold text-[#333333] leading-tight" style="font-size:1.45rem;">My Profile Information</h1>
                <p class="text-[#666666] font-normal" style="font-size:.77rem;margin-top:1px;">
                    Complete your personal details. Fields marked <span class="text-red-500 font-semibold">*</span> are required.
                </p>
            </div>
        </div>
        @if(!$editing)
            <button wire:click="startEditing" class="ai-btn-primary">
                <i class="fas fa-pen" style="font-size:.75rem;"></i> Edit Profile
            </button>
        @endif
    </div>

    {{-- ── Alerts ───────────────────────────────────────────────────── --}}
    @if($errorMessage)
        <div class="ai-alert error">
            <i class="fas fa-circle-exclamation shrink-0" style="font-size:.85rem;margin-top:1px;"></i>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif
    @if($successMessage)
        <div class="ai-alert success">
            <i class="fas fa-circle-check shrink-0" style="font-size:.85rem;margin-top:1px;"></i>
            <span>{{ $successMessage }}</span>
        </div>
    @endif
    @if(!$profileComplete && !$editing)
        <div class="ai-alert warning">
            <i class="fas fa-triangle-exclamation shrink-0" style="font-size:.85rem;margin-top:1px;"></i>
            <span>Your profile is incomplete. Click <strong>Edit Profile</strong> to fill in all required fields.</span>
        </div>
    @endif

    {{-- ══ BODY GRID ══════════════════════════════════════════════════ --}}
    <div class="ai-body-grid">

        {{-- ROW 1: Student ID (col 1) | Student Name (col 2-3) --}}
        <div class="ai-card" style="grid-column:1/2;">
            <div class="ai-card-header"><p>Student ID</p></div>
            <div class="p-2">
                <div class="ai-cell">
                    <p class="ai-field-label">Student ID</p>
                    <p class="ai-field-value" style="font-family:monospace;">{{ strtoupper($student_id ?: '—') }}</p>
                </div>
            </div>
        </div>

        <div class="ai-card" style="grid-column:2/-1;">
            <div class="ai-card-header"><p>Student's Name</p></div>
            <div class="p-2 grid grid-cols-4 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">Last Name</p>
                    <p class="ai-field-value">{{ strtoupper($last_name ?: '—') }}</p>
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Given Name</p>
                    <p class="ai-field-value">{{ strtoupper($first_name ?: '—') }}</p>
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Middle Name</p>
                    <p class="ai-field-value">{{ strtoupper($middle_initial ?: '—') }}</p>
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Ext. Name</p>
                    <p class="ai-field-value">{{ strtoupper($suffix ?: '—') }}</p>
                </div>
            </div>
        </div>

        {{-- ROW 2: Student Data | Father | Mother --}}
        <div class="ai-card">
            <div class="ai-card-header"><p>Student's Data</p></div>
            <div class="p-2 grid grid-cols-2 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">Sex <span class="text-red-500">*</span></p>
                    @if($editing)
                        <div class="flex gap-3 flex-wrap mt-1">
                            <label class="ai-radio-label"><input wire:model="gender" type="radio" value="Male"> Male</label>
                            <label class="ai-radio-label"><input wire:model="gender" type="radio" value="Female"> Female</label>
                        </div>
                        @error('gender') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($gender ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Birthdate <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                               class="ai-input mt-0.5 {{ $errors->has('date_of_birth') ? 'error' : '' }}"
                               style="text-transform:none;letter-spacing:0;">
                        @error('date_of_birth') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ $date_of_birth ? strtoupper(\Carbon\Carbon::parse($date_of_birth)->format('F j, Y')) : '—' }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Course</p>
                    <p class="ai-field-value">{{ strtoupper($course_code ?: '—') }}</p>
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Year Level</p>
                    <p class="ai-field-value">{{ strtoupper($year_level ?: '—') }}</p>
                </div>
            </div>
        </div>

        <div class="ai-card">
            <div class="ai-card-header"><p>Father's Name</p></div>
            <div class="p-2 grid grid-cols-1 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">Last Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="father_last_name" type="text" placeholder="DELA CRUZ"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('father_last_name') ? 'error' : '' }}">
                        @error('father_last_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($father_last_name ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Given Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="father_given_name" type="text" placeholder="JUAN"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('father_given_name') ? 'error' : '' }}">
                        @error('father_given_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($father_given_name ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Middle Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="father_middle_name" type="text" placeholder="SANTOS"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('father_middle_name') ? 'error' : '' }}">
                        @error('father_middle_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($father_middle_name ?: '—') }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="ai-card">
            <div class="ai-card-header"><p>Mother's Maiden Name</p></div>
            <div class="p-2 grid grid-cols-1 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">Last Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="mother_last_name" type="text" placeholder="REYES"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('mother_last_name') ? 'error' : '' }}">
                        @error('mother_last_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($mother_last_name ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Given Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="mother_given_name" type="text" placeholder="MARIA"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('mother_given_name') ? 'error' : '' }}">
                        @error('mother_given_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($mother_given_name ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Middle Name <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="mother_middle_name" type="text" placeholder="CRUZ"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('mother_middle_name') ? 'error' : '' }}">
                        @error('mother_middle_name') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($mother_middle_name ?: '—') }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ROW 3: Permanent Address (full width) --}}
        <div class="ai-card ai-full">
            <div class="ai-card-header"><p>Permanent Address</p></div>
            <div class="p-2 grid grid-cols-4 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">Street <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="address_street" type="text" placeholder="123 RIZAL ST."
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('address_street') ? 'error' : '' }}">
                        @error('address_street') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($address_street ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Barangay <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="address_barangay" type="text" placeholder="BAGONG SILANG"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('address_barangay') ? 'error' : '' }}">
                        @error('address_barangay') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($address_barangay ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Municipality / City <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="address_municipality" type="text" placeholder="LIPA CITY"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('address_municipality') ? 'error' : '' }}">
                        @error('address_municipality') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($address_municipality ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Province <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="address_province" type="text" placeholder="BATANGAS"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('address_province') ? 'error' : '' }}">
                        @error('address_province') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($address_province ?: '—') }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ROW 4: DSWD | Disability | Contact | Email (full width) --}}
        <div class="ai-card ai-full">
            <div class="p-2 grid grid-cols-4 gap-1.5">
                <div class="ai-cell">
                    <p class="ai-field-label">DSWD Household No.</p>
                    @if($editing)
                        <input wire:model="dswd_household_no" type="text" placeholder="004-0012345"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5">
                        <p class="ai-hint">Leave blank if not a DSWD beneficiary.</p>
                    @else
                        <p class="ai-field-value">{{ strtoupper($dswd_household_no ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Disability</p>
                    @if($editing)
                        <input wire:model="disability" type="text" placeholder="NONE / VISUAL IMPAIRMENT"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5">
                        <p class="ai-hint">Type NONE if not applicable.</p>
                    @else
                        <p class="ai-field-value">{{ strtoupper($disability ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Contact Number <span class="text-red-500">*</span></p>
                    @if($editing)
                        <input wire:model="contact_number" type="tel" placeholder="09XX-XXX-XXXX"
                               oninput="this.value=this.value.toUpperCase()"
                               class="ai-input mt-0.5 {{ $errors->has('contact_number') ? 'error' : '' }}">
                        @error('contact_number') <p class="ai-error-msg">{{ $message }}</p> @enderror
                    @else
                        <p class="ai-field-value">{{ strtoupper($contact_number ?: '—') }}</p>
                    @endif
                </div>
                <div class="ai-cell">
                    <p class="ai-field-label">Email Address</p>
                    <p class="ai-field-value" style="font-size:.8rem;">{{ strtoupper($email ?: '—') }}</p>
                    <p class="ai-hint">Verified — cannot be changed here.</p>
                </div>
            </div>
        </div>

    </div>{{-- end body grid --}}

    {{-- ── Action Buttons ──────────────────────────────────────────── --}}
    @if($editing)
        <div class="flex gap-2 items-center" style="flex-shrink:0;">
            <button wire:click="saveProfile"
                    wire:loading.attr="disabled"
                    wire:target="saveProfile"
                    class="ai-btn-primary">
                <span wire:loading.remove wire:target="saveProfile">
                    <i class="fas fa-floppy-disk" style="font-size:.75rem;margin-right:4px;"></i>Save Profile
                </span>
                <span wire:loading wire:target="saveProfile">
                    <i class="fas fa-spinner fa-spin" style="font-size:.75rem;margin-right:4px;"></i>Saving…
                </span>
            </button>
            @if($profileComplete)
                <button wire:click="cancelEditing" class="ai-btn-ghost">
                    <i class="fas fa-xmark" style="font-size:.75rem;"></i> Cancel
                </button>
            @endif
        </div>
    @endif

</div>{{-- end ai-page-wrap --}}
</div>{{-- end root --}}