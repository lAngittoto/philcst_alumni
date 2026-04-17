<?php

use Livewire\Volt\Component;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

new class extends Component {

    // ── Form Fields ──────────────────────────────────────────────
    public string $regFirstName     = '';
    public string $regMiddleInitial = '';
    public string $regLastName      = '';
    public string $regSuffix        = '';
    public string $regStudentId     = '';
    public string $regCourseCode    = '';
    public string $regYear          = '';

    // ── State ────────────────────────────────────────────────────
    public bool   $submitting  = false;
    public array  $formErrors  = [];
    public string $successMsg  = '';
    public string $successName = '';
    public string $successId   = '';
    public string $successPass = '';

    public function mount(): void
    {
        $this->regYear = (string) date('Y');
    }

    #[\Livewire\Attributes\Computed]
    public function courses() { return Course::orderBy('code')->get(); }

    private function generateTempPassword(string $paddedId, string $lastName): string
    {
        $raw  = substr(trim($lastName), 0, 2);
        $part = ucfirst(strtolower($raw));
        return $paddedId . '_' . $part;
    }

    private function validateName(string $n): bool
    {
        return (bool) preg_match('/^[a-zA-Z\s\-\.\']+$/', $n);
    }

    private function buildFullName(string $f, string $m, string $l, string $s): string
    {
        return implode(' ', array_filter(array_map('trim', [$f, $m, $l, $s])));
    }

    private function fullNameExists(string $f, string $m, string $l, string $s): bool
    {
        return Alumni::whereRaw('LOWER(TRIM(first_name))=?',                  [strtolower(trim($f))])
                     ->whereRaw('LOWER(TRIM(last_name))=?',                   [strtolower(trim($l))])
                     ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,"")))=?', [strtolower(trim($m))])
                     ->whereRaw('LOWER(TRIM(COALESCE(suffix,"")))=?',         [strtolower(trim($s))])
                     ->exists();
    }

    // ── Collect ALL validation errors at once ─────────────────────
    private function collectErrors(): array
    {
        $errors = [];

        // ── First Name ──────────────────────────────────────────
        $firstName = trim($this->regFirstName);
        if (!$firstName) {
            $errors[] = 'First name is required.';
        } elseif (!$this->validateName($firstName)) {
            $errors[] = 'First name may only contain letters, spaces, hyphens, or apostrophes.';
        }

        // ── Last Name ───────────────────────────────────────────
        $lastName = trim($this->regLastName);
        if (!$lastName) {
            $errors[] = 'Last name is required.';
        } elseif (!$this->validateName($lastName)) {
            $errors[] = 'Last name may only contain letters, spaces, hyphens, or apostrophes.';
        }

        // ── Middle Name ─────────────────────────────────────────
        $mid = trim($this->regMiddleInitial);
        if ($mid !== '') {
            if (!preg_match('/^[a-zA-Z]+$/', $mid)) {
                $errors[] = 'Middle name must contain letters only.';
            } elseif (strlen($mid) < 2) {
                $errors[] = 'Middle name must be a full word (e.g. Santos, not S).';
            }
        }

        // ── Suffix ──────────────────────────────────────────────
        $suffix = trim($this->regSuffix);
        if ($suffix !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', $suffix)) {
            $errors[] = 'Suffix may only contain letters and periods (e.g. Jr. Sr. III).';
        }

        // ── Duplicate full name (only check if names are valid) ─
        if (!$errors && $firstName && $lastName) {
            if ($this->fullNameExists($firstName, $mid, $lastName, $suffix)) {
                $errors[] = 'An alumni with that full name already exists.';
            }
        }

        // ── Student ID ──────────────────────────────────────────
        $studentId = trim($this->regStudentId);
        if (!$studentId) {
            $errors[] = 'Student ID is required.';
        } elseif (!preg_match('/^\d{1,8}$/', $studentId)) {
            $errors[] = 'Student ID must be 1–8 digits (numbers only).';
        } else {
            $paddedId = str_pad($studentId, 8, '0', STR_PAD_LEFT);
            if (Alumni::where('student_id', $paddedId)->exists()) {
                $errors[] = 'This Student ID is already registered.';
            }
        }

        // ── Course ──────────────────────────────────────────────
        if (!trim($this->regCourseCode)) {
            $errors[] = 'Please select a course.';
        } elseif (!Course::where('code', $this->regCourseCode)->exists()) {
            $errors[] = 'The selected course does not exist.';
        }

        // ── Batch Year ──────────────────────────────────────────
        $year = trim($this->regYear);
        if (!$year) {
            $errors[] = 'Batch year is required.';
        } elseif (!preg_match('/^\d{4}$/', $year)) {
            $errors[] = 'Batch year must be exactly 4 digits.';
        }

        return $errors;
    }

    public function registerAlumni(): void
    {
        $this->formErrors = [];
        $this->successMsg = '';
        $this->submitting = true;

        try {
            $errors = $this->collectErrors();

            if (!empty($errors)) {
                $this->formErrors = ['general' => $errors];
                return;
            }

            $paddedId  = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $mid       = trim($this->regMiddleInitial);
            $course    = Course::where('code', $this->regCourseCode)->firstOrFail();
            $fullName  = $this->buildFullName(
                            trim($this->regFirstName),
                            $mid,
                            trim($this->regLastName),
                            trim($this->regSuffix)
                         );
            $tmpPass   = $this->generateTempPassword($paddedId, trim($this->regLastName));

            $loginEmail = $paddedId . '@pending.local';
            $user = User::create([
                'name'     => $fullName,
                'role'     => 'alumni',
                'email'    => $loginEmail,
                'password' => Hash::make($tmpPass),
            ]);

            Alumni::create([
                'user_id'             => $user->id,
                'first_name'          => trim($this->regFirstName),
                'middle_initial'      => $mid ?: null,
                'last_name'           => trim($this->regLastName),
                'suffix'              => trim($this->regSuffix) ?: null,
                'student_id'          => $paddedId,
                'email'               => null,
                'course_code'         => $this->regCourseCode,
                'course_name'         => $course->name,
                'batch'               => (int) $this->regYear,
                'status'              => 'VERIFIED',
                'password_changed_at' => now(),
                'profile_photo'       => null,
                'profile_completed'   => 0,
            ]);

            $this->successMsg  = "Alumni '{$fullName}' registered successfully!";
            $this->successName = $fullName;
            $this->successId   = $paddedId;
            $this->successPass = $tmpPass;
            $this->resetForm();

        } catch (\Exception $e) {
            Log::error('Alumni register error: ' . $e->getMessage());
            $this->formErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->submitting = false;
        }
    }

    public function resetForm(): void
    {
        $this->regFirstName = $this->regMiddleInitial = $this->regLastName = $this->regSuffix = '';
        $this->regStudentId = $this->regCourseCode = '';
        $this->regYear      = (string) date('Y');
        $this->formErrors   = [];
    }

    public function clearSuccess(): void
    {
        $this->successMsg = $this->successName = $this->successId = $this->successPass = '';
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-3 pb-6 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- Header --}}
    <div class="flex items-center gap-3 mb-4">
        <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fas fa-user-plus text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 leading-tight">Register Alumni</h1>
        </div>
    </div>

    {{-- Main Layout: Form + Side Success Panel --}}
    <div class="flex-1 flex items-start justify-center pt-1">
        <div class="w-full flex gap-5 items-start justify-center
                    {{ $successMsg ? 'max-w-4xl' : 'max-w-2xl' }}
                    transition-all duration-300">

            {{-- Form Column --}}
            <div class="{{ $successMsg ? 'flex-1 min-w-0' : 'w-full' }}">

                {{-- Error Banner --}}
                @if(count($formErrors) > 0)
                <div class="mb-4 p-4 rounded-2xl bg-red-50 border border-red-200">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <p class="font-bold text-sm text-red-900 flex items-center gap-1.5">
                            <i class="fas fa-triangle-exclamation"></i>
                            Please fix the following:
                        </p>
                        <button wire:click="resetForm" type="button"
                                class="shrink-0 inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg bg-red-100 hover:bg-red-200 text-red-700 border border-red-200 transition active:scale-95">
                            <i class="fas fa-rotate-left text-[10px]"></i> Reset Form
                        </button>
                    </div>
                    <ul class="space-y-1.5 text-xs text-red-700">
                        @foreach($formErrors as $msgs)
                            @foreach($msgs as $msg)
                                <li class="flex items-start gap-1.5">
                                    <span class="shrink-0 mt-0.5 text-red-400">•</span>{{ $msg }}
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Form Card --}}
                <div class="bg-white rounded-2xl shadow-sm border border-purple-100 overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-200 bg-gray-50">
                        <p class="text-xs font-bold text-gray-600 uppercase tracking-wide">Alumni Information</p>
                    </div>
                    <form wire:submit="registerAlumni" class="p-5 sm:p-7 space-y-6">

                        {{-- Name Fields --}}
                        <div class="space-y-3">
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide">
                                Full Name <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regFirstName" type="text" placeholder="First Name"
                                           class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                           maxlength="100" autocomplete="given-name">
                                    <p class="text-[10px] text-gray-500 mt-1">First Name <span class="text-red-500">*</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regLastName" type="text" placeholder="Last Name"
                                           class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                           maxlength="100" autocomplete="family-name">
                                    <p class="text-[10px] text-gray-500 mt-1">Last Name <span class="text-red-500">*</span></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. Santos"
                                           class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                           maxlength="50">
                                    <p class="text-[10px] text-gray-500 mt-1">Middle Name <span class="text-gray-400 font-normal">(full word, optional)</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr."
                                           class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                           maxlength="10">
                                    <p class="text-[10px] text-gray-500 mt-1">Suffix <span class="text-gray-400 font-normal">(optional)</span></p>
                                </div>
                            </div>
                        </div>

                        {{-- Student ID --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-2">
                                Student ID <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345"
                                   class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 font-mono placeholder-gray-400 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   maxlength="8" inputmode="numeric" autocomplete="off">
                            <p class="text-[10px] text-gray-500 mt-1">Numbers only · zero-padded to 8 digits (e.g. 12345 → 00012345)</p>
                        </div>

                        {{-- Course + Batch --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-2">
                                    Course <span class="text-red-500">*</span>
                                </label>
                                <select wire:model.defer="regCourseCode"
                                        class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 cursor-pointer">
                                    <option value="">Select Course…</option>
                                    @foreach($this->courses as $c)
                                        <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-2">
                                    Batch Year <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}"
                                       class="w-full px-3 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                       min="1900" max="9999">
                            </div>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex gap-3 pt-2">
                            <a href="{{ route('registrar.alumni') }}" wire:navigate
                               class="flex-1 px-5 py-3 rounded-xl text-sm font-bold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition text-center active:scale-[.99]">
                                Cancel
                            </a>
                            <button type="submit"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-3 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                    style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                                <span wire:loading wire:target="registerAlumni">
                                    <i class="fas fa-spinner animate-spin"></i> Registering…
                                </span>
                                <span wire:loading.remove wire:target="registerAlumni">
                                    <i class="fas fa-user-check"></i> Register Alumni
                                </span>
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            {{-- Side Success Panel --}}
            @if($successMsg)
            <div class="w-72 shrink-0">
                <div class="bg-emerald-50 border border-emerald-200 rounded-2xl overflow-hidden shadow-sm">

                    {{-- Panel Header --}}
                    <div class="px-4 py-3 flex items-center justify-between"
                         style="background:linear-gradient(135deg,#059669,#10b981);">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-circle-check text-white text-sm"></i>
                            <span class="text-sm font-bold text-white">Registration Successful</span>
                        </div>
                        <button wire:click="clearSuccess" class="text-white/70 hover:text-white transition">
                            <i class="fas fa-xmark text-sm"></i>
                        </button>
                    </div>

                    {{-- Panel Body --}}
                    <div class="p-4 space-y-4">

                        {{-- Alumni Name --}}
                        <div class="text-center py-3 px-2 bg-white rounded-xl border border-emerald-100">
                            <div class="w-12 h-12 rounded-full mx-auto mb-2 flex items-center justify-center"
                                 style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                                <i class="fas fa-user-graduate text-white"></i>
                            </div>
                            <p class="text-xs text-gray-500 mb-0.5">Registered Alumni</p>
                            <p class="text-sm font-extrabold text-gray-900 leading-tight">{{ $successName }}</p>
                        </div>

                        {{-- Credentials --}}
                        <div class="space-y-2">
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Login Credentials</p>
                            <div class="bg-white rounded-xl border border-emerald-100 divide-y divide-emerald-50">
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-gray-400 font-semibold mb-0.5">Student ID</p>
                                    <p class="text-sm font-mono font-bold text-gray-900">{{ $successId }}</p>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-gray-400 font-semibold mb-0.5">Temporary Password</p>
                                    <p class="text-sm font-mono font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-1 rounded-lg inline-block mt-0.5">{{ $successPass }}</p>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-gray-400 font-semibold mb-0.5">Status</p>
                                    <span class="text-xs font-bold px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full">VERIFIED</span>
                                </div>
                            </div>
                        </div>

                        {{-- Note --}}
                        <p class="text-[10px] text-emerald-700 leading-relaxed bg-emerald-100/60 rounded-lg p-2.5">
                            <i class="fas fa-circle-info mr-1"></i>Share the temporary password with the alumni. They can change it after logging in.
                        </p>
                    </div>
                </div>
            </div>
            @endif

        </div>
    </div>

</div>