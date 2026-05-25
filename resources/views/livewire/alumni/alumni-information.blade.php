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

    public array $snapshot = [];

    private function editableKeys(): array
    {
        return [
            'gender', 'date_of_birth',
            'father_last_name', 'father_given_name', 'father_middle_name',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number',
        ];
    }

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
                'student_id', 'course_code', 'course_name', 'batch',
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

        $keys = $this->editableKeys();
        $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));
    }

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $keys = $this->editableKeys();
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

        $isDirty = false;
        foreach ($this->editableKeys() as $key) {
            $snap    = strtoupper(trim((string)($this->snapshot[$key] ?? '')));
            $current = strtoupper(trim((string)($this->$key ?? '')));
            if ($snap !== $current) {
                $isDirty = true;
                break;
            }
        }

        if (!$isDirty) {
            $this->dispatch('show-toast', type: 'error', message: 'No changes were made. Please edit a field before saving.');
            return;
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

            $keys = $this->editableKeys();
            $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));

            $this->successMessage = $profileComplete
                ? 'Profile saved successfully.'
                : 'Progress saved. Complete all required fields to finish your profile.';

            $this->dispatch('show-toast', type: 'success', message: $this->successMessage);

            $this->dispatch('profile-updated', completed: $profileComplete);
            Log::info("Alumni profile saved | student_id: {$this->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
        }
    }
}; ?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

<style>
/* ── Tooltip ── */
.ai-tooltip {
    position: absolute;
    bottom: calc(100% + 6px);
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 200;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
}
.ai-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-top-color: #111827;
}
.group:hover .ai-tooltip { opacity: 1; }

/* ── Spinner ── */
@keyframes ai-spin { to { transform: rotate(360deg); } }
.ai-spin { animation: ai-spin .7s linear infinite; }

/* ── Toast — white bg, colored text ── */
#profile-toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%) translateY(-90px);
    z-index: 9999;
    min-width: 300px;
    max-width: 480px;
    pointer-events: none;
    opacity: 0;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
}
#profile-toast.toast-visible {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
    pointer-events: auto;
}
#profile-toast.toast-hiding {
    transform: translateX(-50%) translateY(-90px);
    opacity: 0;
    pointer-events: none;
}
.toast-inner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 600;
    line-height: 1.4;
    background: #ffffff;
    box-shadow: none;
}

/* Success: white bg, green text, solid green border, no icon */
.toast-success {
    color: #15803d;
    border: 1.5px solid #16a34a;
}
.toast-success .toast-icon {
    display: none;
}
.toast-success .toast-close {
    color: rgba(21,128,61,.45);
}
.toast-success .toast-close:hover {
    color: #15803d;
}

/* Error: white bg, red text, solid red border, no icon */
.toast-error {
    color: #b91c1c;
    border: 1.5px solid #dc2626;
}
.toast-error .toast-icon {
    display: none;
}
.toast-error .toast-close {
    color: rgba(185,28,28,.45);
}
.toast-error .toast-close:hover {
    color: #b91c1c;
}

.toast-close {
    margin-left: auto;
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    padding: 2px 4px;
    border-radius: 4px;
    transition: color .15s;
    line-height: 1;
}
</style>

{{-- ── TOAST ELEMENT ── --}}
<div id="profile-toast" role="alert" aria-live="polite">
    <div class="toast-inner toast-success" id="profile-toast-inner">
        <i class="fas fa-circle-check toast-icon" id="profile-toast-icon"></i>
        <span id="profile-toast-msg">Profile saved successfully.</span>
        <button class="toast-close" onclick="hideProfileToast()" aria-label="Dismiss">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
</div>

<script>
(function () {
    let _toastTimer = null;

    window.showProfileToast = function (type, message) {
        const toast = document.getElementById('profile-toast');
        const inner = document.getElementById('profile-toast-inner');
        const icon  = document.getElementById('profile-toast-icon');
        const msg   = document.getElementById('profile-toast-msg');
        if (!toast) return;

        clearTimeout(_toastTimer);
        toast.classList.remove('toast-visible', 'toast-hiding');

        msg.textContent = message;
        inner.className = 'toast-inner ' + (type === 'success' ? 'toast-success' : 'toast-error');
        icon.className  = 'toast-icon fas ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation');

        void toast.offsetWidth;
        toast.classList.add('toast-visible');

        _toastTimer = setTimeout(window.hideProfileToast, 3500);
    };

    window.hideProfileToast = function () {
        const toast = document.getElementById('profile-toast');
        if (!toast) return;
        clearTimeout(_toastTimer);
        toast.classList.remove('toast-visible');
        toast.classList.add('toast-hiding');
        setTimeout(() => toast.classList.remove('toast-hiding'), 400);
    };
})();

document.addEventListener('livewire:initialized', () => {
    Livewire.on('show-toast', ({ type, message }) => {
        window.showProfileToast(type, message);
    });
});
</script>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex items-center justify-between gap-4 flex-shrink-0">

        {{-- Left: icon + title --}}
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fa-solid fa-address-card text-white text-lg"></i>
            </div>
            <div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900">My Profile Information</h1>
                    @if($editing)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-widest bg-amber-100 text-amber-700 border border-amber-300">
                            <i class="fas fa-pen text-[9px]"></i> Edit Mode
                        </span>
                    @endif
                </div>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700">
                    Complete your personal details. Fields marked
                    <span class="text-red-500 font-semibold">*</span> are required.
                </p>
            </div>
        </div>

        {{-- Right: action buttons --}}
        <div class="flex items-center gap-2 flex-shrink-0">
            @if(!$editing)
                {{-- Edit — blue --}}
                <button wire:click="startEditing"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="startEditing"
                        type="button"
                        class="group relative w-10 h-10 rounded-lg flex items-center justify-center
                               bg-blue-500 border border-blue-600 text-white
                               hover:bg-blue-600 transition active:scale-95 cursor-pointer shadow-sm">
                    <span class="ai-tooltip">Edit Profile</span>
                    <span wire:loading.remove wire:target="startEditing">
                        <i class="fas fa-pen text-base"></i>
                    </span>
                    <span wire:loading wire:target="startEditing">
                        <svg class="ai-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                    </span>
                </button>
            @else
                {{-- Save — green --}}
                <button wire:click="saveProfile"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                        wire:target="saveProfile"
                        type="button"
                        class="group relative w-10 h-10 rounded-lg flex items-center justify-center
                               bg-emerald-500 border border-emerald-600 text-white
                               hover:bg-emerald-600 transition active:scale-95 cursor-pointer shadow-sm">
                    <span class="ai-tooltip">Save Profile</span>
                    <span wire:loading.remove wire:target="saveProfile">
                        <i class="fas fa-floppy-disk text-base"></i>
                    </span>
                    <span wire:loading wire:target="saveProfile">
                        <svg class="ai-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                    </span>
                </button>
                @if($profileComplete)
                    {{-- Cancel — red outline --}}
                    <button wire:click="cancelEditing"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-60 cursor-wait"
                            wire:target="cancelEditing"
                            type="button"
                            class="group relative w-10 h-10 rounded-lg flex items-center justify-center
                                   bg-red-50 border border-red-200 text-red-500
                                   hover:bg-red-100 hover:border-red-300 transition active:scale-95 cursor-pointer shadow-sm">
                        <span class="ai-tooltip">Cancel</span>
                        <span wire:loading.remove wire:target="cancelEditing">
                            <i class="fas fa-xmark text-base"></i>
                        </span>
                        <span wire:loading wire:target="cancelEditing">
                            <svg class="ai-spin w-4 h-4 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                        </span>
                    </button>
                @endif
            @endif
        </div>

    </div>

    {{-- ══ INLINE ALERTS (validation errors only) ══ --}}
    @if($errorMessage)
        <div class="rounded-xl px-4 py-3 text-sm border bg-red-50 text-red-600 border-red-200 flex items-center gap-2 flex-shrink-0">
            <span>{{ $errorMessage }}</span>
        </div>
    @endif
    @if(!$profileComplete && !$editing)
        <div class="rounded-xl px-4 py-3 text-sm border bg-yellow-50 text-yellow-800 border-yellow-200 flex items-center gap-2 flex-shrink-0">
            <span>Your profile is incomplete. Click the <strong>edit</strong> button to fill in all required fields.</span>
        </div>
    @endif

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── CARD BODY ── --}}
        <div class="bg-white flex-1 overflow-y-auto">

            {{-- ROW 1: Student ID | Student's Name --}}
            <div class="flex border-b border-gray-200">
                <div class="flex-none w-[220px] border-r border-gray-200">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Student ID</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="flex flex-col gap-1">
                            <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Student ID</p>
                            <p class="text-base font-semibold text-gray-900 font-mono tracking-wide m-0">{{ strtoupper($student_id ?: '—') }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Student's Name</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="grid grid-cols-4 gap-3">
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Last Name</p>
                                <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($last_name ?: '—') }}</p>
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Given Name</p>
                                <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($first_name ?: '—') }}</p>
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Middle Name</p>
                                <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($middle_initial ?: '—') }}</p>
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Ext.</p>
                                <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($suffix ?: '—') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ROW 2: Student's Data | Father's Name | Mother's Maiden Name --}}
            <div class="flex border-b border-gray-200">

                {{-- Student's Data --}}
                <div class="flex-none w-[280px] border-r border-gray-200">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Student's Data</p>
                    </div>
                    <div class="px-4 py-3.5 flex flex-col gap-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Sex <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <div class="flex gap-4 flex-wrap">
                                        <label class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 cursor-pointer">
                                            <input wire:model="gender" type="radio" value="Male" class="w-4 h-4 accent-[#7a3f91] cursor-pointer"> Male
                                        </label>
                                        <label class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 cursor-pointer">
                                            <input wire:model="gender" type="radio" value="Female" class="w-4 h-4 accent-[#7a3f91] cursor-pointer"> Female
                                        </label>
                                    </div>
                                    @error('gender') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 m-0">{{ strtoupper($gender ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Birthdate <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                                        class="w-full box-border text-sm font-semibold text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white {{ $errors->has('date_of_birth') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('date_of_birth') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 m-0">{{ $date_of_birth ? \Carbon\Carbon::parse($date_of_birth)->format('F j, Y') : '—' }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Course</p>
                            <p class="text-base font-semibold text-gray-900 m-0">{{ strtoupper($course_code ?: '—') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Father's Name --}}
                <div class="flex-1 min-w-0 border-r border-gray-200">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Father's Name</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Last Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="father_last_name" type="text" placeholder="DELA CRUZ" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('father_last_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('father_last_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($father_last_name ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Given Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="father_given_name" type="text" placeholder="JUAN" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('father_given_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('father_given_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($father_given_name ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Middle Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="father_middle_name" type="text" placeholder="SANTOS" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('father_middle_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('father_middle_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($father_middle_name ?: '—') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Mother's Maiden Name --}}
                <div class="flex-1 min-w-0">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Mother's Maiden Name</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Last Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="mother_last_name" type="text" placeholder="REYES" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('mother_last_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('mother_last_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($mother_last_name ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Given Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="mother_given_name" type="text" placeholder="MARIA" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('mother_given_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('mother_given_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($mother_given_name ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Middle Name <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="mother_middle_name" type="text" placeholder="CRUZ" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none text-center transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('mother_middle_name') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('mother_middle_name') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($mother_middle_name ?: '—') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- ROW 3: Permanent Address --}}
            <div class="flex border-b border-gray-200">
                <div class="flex-1 min-w-0">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Permanent Address</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="grid grid-cols-4 gap-3">
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Street <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="address_street" type="text" placeholder="123 RIZAL ST." oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('address_street') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('address_street') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($address_street ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Barangay <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="address_barangay" type="text" placeholder="BAGONG SILANG" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('address_barangay') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('address_barangay') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($address_barangay ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Municipality / City <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="address_municipality" type="text" placeholder="LIPA CITY" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('address_municipality') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('address_municipality') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($address_municipality ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Province <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="address_province" type="text" placeholder="BATANGAS" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('address_province') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('address_province') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($address_province ?: '—') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ROW 4: Additional Information --}}
            <div class="flex">
                <div class="flex-1 min-w-0">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                        <p class="text-[0.7rem] font-bold uppercase tracking-widest text-gray-800 m-0">Additional Information</p>
                    </div>
                    <div class="px-4 py-3.5">
                        <div class="grid grid-cols-4 gap-3">
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">DSWD Household No.</p>
                                @if($editing)
                                    <input wire:model="dswd_household_no" type="text" placeholder="004-0012345" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal">
                                    <p class="text-xs text-gray-400 font-normal mt-0.5 m-0">Leave blank if not applicable.</p>
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($dswd_household_no ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Disability</p>
                                @if($editing)
                                    <input wire:model="disability" type="text" placeholder="NONE / VISUAL IMPAIRMENT" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal">
                                    <p class="text-xs text-gray-400 font-normal mt-0.5 m-0">Type NONE if not applicable.</p>
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($disability ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Contact Number <span class="text-red-500">*</span></p>
                                @if($editing)
                                    <input wire:model="contact_number" type="tel" placeholder="09XX-XXX-XXXX" oninput="this.value=this.value.toUpperCase()"
                                        class="w-full box-border text-sm font-normal text-gray-900 bg-gray-50 border rounded-lg px-2.5 py-2 uppercase tracking-wide outline-none transition hover:border-gray-300 focus:border-[#7a3f91] focus:bg-white placeholder:text-gray-300 placeholder:font-normal placeholder:normal-case placeholder:tracking-normal {{ $errors->has('contact_number') ? 'border-red-400' : 'border-gray-200' }}">
                                    @error('contact_number') <p class="text-xs text-red-500 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    <p class="text-base font-semibold text-gray-900 break-words m-0">{{ strtoupper($contact_number ?: '—') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-[0.7rem] font-bold uppercase tracking-wider text-gray-500 m-0">Email Address</p>
                                <p class="text-sm font-semibold text-gray-900 break-all m-0">{{ strtoupper($email ?: '—') }}</p>
                                <p class="text-xs text-gray-400 font-normal mt-0.5 m-0">Verified — cannot be changed here.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- end card body --}}

        {{-- ══ FOOTER BAR ══ --}}
        <div class="flex items-center px-5 min-h-[48px]
                    bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                @if($profileComplete)
                    <strong class="text-white font-bold">Profile complete</strong> — all required fields are filled.
                @else
                    <strong class="text-white font-bold">Profile incomplete</strong> — fill in all required fields.
                @endif
            </p>
        </div>

    </div>{{-- end content block --}}

</div>{{-- end main layout --}}

</div>