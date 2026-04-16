<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithFileUploads;

    // ── Form Fields ──────────────────────────────────────────────
    public string $regFirstName     = '';
    public string $regMiddleInitial = '';
    public string $regLastName      = '';
    public string $regSuffix        = '';
    public string $regStudentId     = '';
    public string $regEmail         = '';
    public string $regCourseCode    = '';
    public string $regYear          = '';
    public        $regPhoto         = null;

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

    private function storePhoto($photo): ?string
    {
        if (!$photo) return null;
        try {
            $fname = 'alumni-' . Str::uuid() . '.' . $photo->getClientOriginalExtension();
            $photo->storeAs('alumni-photos', $fname, 'public');
            return "alumni-photos/{$fname}";
        } catch (\Exception $e) {
            Log::error('Alumni photo store failed: ' . $e->getMessage());
            return null;
        }
    }

    public function registerAlumni(): void
    {
        $this->formErrors = [];
        $this->successMsg = '';
        $this->submitting = true;

        try {
            if (!trim($this->regFirstName))
                throw new \Exception('First name is required.');
            if (!$this->validateName(trim($this->regFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!trim($this->regLastName))
                throw new \Exception('Last name is required.');
            if (!$this->validateName(trim($this->regLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            $mid = trim($this->regMiddleInitial);
            if ($mid !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $mid))
                    throw new \Exception('Middle name must contain letters only.');
                if (strlen($mid) < 2)
                    throw new \Exception('Middle name must be a full word (e.g. Santos, not S).');
            }

            if (trim($this->regSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->regSuffix)))
                throw new \Exception('Suffix may only contain letters and periods (e.g. Jr. Sr. III).');

            if ($this->fullNameExists(trim($this->regFirstName), $mid, trim($this->regLastName), trim($this->regSuffix)))
                throw new \Exception('An alumni with that full name already exists.');

            $this->validate([
                'regStudentId'  => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:alumni,student_id'],
                'regCourseCode' => ['required', 'string', 'exists:courses,code'],
                'regYear'       => ['required', 'digits:4'],
                'regEmail'      => ['nullable', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
                'regPhoto'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ], [
                'regStudentId.unique'  => 'This Student ID is already registered.',
                'regStudentId.regex'   => 'Student ID must be 1–8 digits.',
                'regCourseCode.exists' => 'The selected course does not exist.',
                'regYear.digits'       => 'Batch year must be exactly 4 digits.',
                'regEmail.unique'      => 'This email address is already in use.',
                'regPhoto.max'         => 'Profile photo must not exceed 5 MB.',
            ]);

            $paddedId  = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $course    = Course::where('code', $this->regCourseCode)->firstOrFail();
            $fullName  = $this->buildFullName($this->regFirstName, $mid, $this->regLastName, $this->regSuffix);
            $photoPath = $this->storePhoto($this->regPhoto);
            $tmpPass   = $this->generateTempPassword($paddedId, trim($this->regLastName));

            $loginEmail = trim($this->regEmail) ?: ($paddedId . '@pending.local');
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
                'email'               => trim($this->regEmail) ?: null,
                'course_code'         => $this->regCourseCode,
                'course_name'         => $course->name,
                'batch'               => (int) $this->regYear,
                'status'              => 'VERIFIED',
                'password_changed_at' => now(),
                'profile_photo'       => $photoPath,
                'profile_completed'   => 0,
            ]);

            $this->successMsg  = "Alumni '{$fullName}' registered successfully!";
            $this->successName = $fullName;
            $this->successId   = $paddedId;
            $this->successPass = $tmpPass;
            $this->resetForm();

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->formErrors = $e->errors();
        } catch (\Exception $e) {
            Log::error('Alumni register error: ' . $e->getMessage());
            $this->formErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->submitting = false;
        }
    }

    private function resetForm(): void
    {
        $this->regFirstName = $this->regMiddleInitial = $this->regLastName = $this->regSuffix = '';
        $this->regStudentId = $this->regEmail = $this->regCourseCode = '';
        $this->regPhoto     = null;
        $this->regYear      = (string) date('Y');
        $this->formErrors   = [];
    }

    public function clearSuccess(): void
    {
        $this->successMsg = $this->successName = $this->successId = $this->successPass = '';
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-6 max-w-screen-2xl mx-auto" style="min-height:100vh; background:#f7f3fe;">

    {{-- Header --}}
    <div class="flex items-center gap-3 mb-5">
        <a href="{{ route('registrar.alumni') }}" wire:navigate
           class="w-9 h-9 rounded-xl flex items-center justify-center bg-white border border-gray-200 hover:bg-gray-50 text-gray-500 transition shadow-sm">
            <i class="fas fa-arrow-left text-sm"></i>
        </a>
        <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fas fa-user-plus text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 leading-tight">Register Alumni</h1>
            <p class="text-gray-400 text-xs">Status will be set to <span class="font-bold text-emerald-600">VERIFIED</span> immediately</p>
        </div>
    </div>

    <div class="max-w-2xl w-full mx-auto">

        {{-- Success Banner --}}
        @if($successMsg)
        <div class="mb-4 p-4 rounded-2xl bg-emerald-50 border border-emerald-200">
            <div class="flex items-start gap-3">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center shrink-0">
                    <i class="fas fa-circle-check text-emerald-600"></i>
                </div>
                <div class="flex-1">
                    <p class="font-bold text-emerald-900 text-sm">Registration Successful!</p>
                    <p class="text-xs text-emerald-700 mt-0.5">{{ $successMsg }}</p>
                    <div class="mt-3 p-3 bg-white border border-emerald-100 rounded-xl space-y-2">
                        @foreach([
                            ['Student ID',      $successId,   'font-mono font-bold text-gray-900'],
                            ['Temp Password',   $successPass, 'font-mono font-bold text-gray-900 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded'],
                            ['Status',          'VERIFIED',   'px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full font-bold'],
                        ] as [$label, $value, $class])
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-gray-400 w-28 shrink-0">{{ $label }}</span>
                            <span class="text-xs {{ $class }}">{{ $value }}</span>
                        </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-emerald-600 mt-2">
                        <i class="fas fa-circle-info mr-1"></i>Share the temporary password with the alumni. They can change it after logging in.
                    </p>
                </div>
                <button wire:click="clearSuccess" class="text-emerald-400 hover:text-emerald-600 transition shrink-0">
                    <i class="fas fa-xmark text-sm"></i>
                </button>
            </div>
        </div>
        @endif

        {{-- Error Banner --}}
        @if(count($formErrors) > 0)
        <div class="mb-4 p-4 rounded-2xl bg-red-50 border border-red-200">
            <p class="font-bold text-sm text-red-900 mb-2 flex items-center gap-1.5">
                <i class="fas fa-triangle-exclamation"></i>Please fix the following:
            </p>
            <ul class="space-y-1 text-xs text-red-700">
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
            <div class="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-[#f9f5ff] to-white">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Alumni Information</p>
            </div>
            <form wire:submit="registerAlumni" class="p-5 sm:p-6 space-y-5">

                {{-- Photo --}}
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                        Profile Photo <span class="text-gray-300 normal-case font-normal">(optional)</span>
                    </label>
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#faf5ff] transition"
                         onclick="document.getElementById('regPhotoInput').click()">
                        @if($regPhoto)
                            <img src="{{ $regPhoto->temporaryUrl() }}" class="w-20 h-20 rounded-xl mx-auto mb-2 object-cover shadow">
                            <p class="text-xs text-emerald-600 font-semibold">
                                <i class="fas fa-check mr-1"></i>Photo selected — click to change
                            </p>
                        @else
                            <i class="fas fa-cloud-arrow-up text-2xl text-gray-300 block mb-2"></i>
                            <p class="text-sm text-gray-600 font-semibold">Click to Upload Photo</p>
                            <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                        @endif
                        <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                    </div>
                    <div wire:loading wire:target="regPhoto" class="mt-2 text-xs text-[#7a3f91] flex items-center gap-1.5">
                        <i class="fas fa-spinner animate-spin"></i> Uploading photo…
                    </div>
                </div>

                {{-- Name fields --}}
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                        Full Name <span class="text-red-400">*</span>
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <input wire:model.defer="regFirstName" type="text" placeholder="First Name"
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   maxlength="100" autocomplete="given-name">
                            <p class="text-[10px] text-gray-400 mt-1">First Name <span class="text-red-400">*</span></p>
                        </div>
                        <div>
                            <input wire:model.defer="regLastName" type="text" placeholder="Last Name"
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   maxlength="100" autocomplete="family-name">
                            <p class="text-[10px] text-gray-400 mt-1">Last Name <span class="text-red-400">*</span></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. Santos"
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   maxlength="50">
                            <p class="text-[10px] text-gray-400 mt-1">Middle Name <span class="text-gray-300">(full word, optional)</span></p>
                        </div>
                        <div>
                            <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr."
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   maxlength="10">
                            <p class="text-[10px] text-gray-400 mt-1">Suffix <span class="text-gray-300">(optional)</span></p>
                        </div>
                    </div>
                </div>

                {{-- Student ID --}}
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                        Student ID <span class="text-red-400">*</span>
                    </label>
                    <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345"
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                           maxlength="8" inputmode="numeric" autocomplete="off">
                    <p class="text-[10px] text-gray-400 mt-1">Numbers only · zero-padded to 8 digits (e.g. 12345 → 00012345)</p>
                </div>

                {{-- Email --}}
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                        Email Address <span class="text-gray-300 normal-case font-normal">(optional)</span>
                    </label>
                    <input wire:model.defer="regEmail" type="email" placeholder="alumni@example.com"
                           class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                           maxlength="255" autocomplete="email">
                    <p class="text-[10px] text-gray-400 mt-1">Leave blank if email is not yet known</p>
                </div>

                {{-- Course + Batch --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                            Course <span class="text-red-400">*</span>
                        </label>
                        <select wire:model.defer="regCourseCode"
                                class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 cursor-pointer">
                            <option value="">Select Course…</option>
                            @foreach($this->courses as $c)
                                <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                            Batch Year <span class="text-red-400">*</span>
                        </label>
                        <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}"
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               min="1900" max="9999">
                    </div>
                </div>

                {{-- Info Box --}}
                <div class="rounded-xl p-4 flex items-start gap-3 border" style="background:#f5eef9;border-color:#d4aaeb;">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#e9d5f3;">
                        <i class="fas fa-key text-sm" style="color:#7a3f91;"></i>
                    </div>
                    <div class="text-xs space-y-1" style="color:#5e2f72;">
                        <p class="font-bold" style="color:#3d1a56;">Credentials & Status</p>
                        <p>Temp password: <code class="bg-white px-1.5 py-0.5 rounded font-mono border" style="border-color:#d4aaeb;">StudentID_Xx</code>
                           (e.g. <code class="bg-white px-1.5 py-0.5 rounded font-mono border" style="border-color:#d4aaeb;">00012345_Sa</code>)</p>
                        <p>Status set to <strong class="text-emerald-700">VERIFIED</strong> — alumni can log in immediately.</p>
                    </div>
                </div>

                {{-- Buttons --}}
                <div class="flex gap-3 pt-1">
                    <a href="{{ route('registrar.alumni') }}" wire:navigate
                       class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition text-center active:scale-[.99]">
                        Cancel
                    </a>
                    <button type="submit"
                            wire:loading.attr="disabled" wire:target="registerAlumni"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
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
</div>