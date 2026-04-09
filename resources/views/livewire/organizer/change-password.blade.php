{{-- resources/views/livewire/alumni/change-password.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Mail\AlumniPasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    public int $step = 1;

    // ── Step 1 — Identity Verification ────────────────────────────────────────
    public string $first_name     = '';
    public string $middle_initial = '';
    public string $last_name      = '';
    public string $suffix         = '';
    public string $student_id     = '';
    public string $course_code    = '';
    public string $batch          = '';

    // ── Step 2 — Email ────────────────────────────────────────────────────────
    public string $email              = '';
    public string $email_confirmation = '';

    // ── Step 3 — Password ─────────────────────────────────────────────────────
    public string $password              = '';
    public string $password_confirmation = '';
    public string $passwordStrength      = 'weak';
    public bool   $showPassword          = false;
    public bool   $showConfirmPassword   = false;

    // ── Step 4 — OTP ──────────────────────────────────────────────────────────
    public string $otp     = '';
    public bool   $otpSent = false;

    // ── UI ────────────────────────────────────────────────────────────────────
    public string $errorMessage   = '';
    public string $successMessage = '';

    // ─────────────────────────────────────────────────────────────────────────
    // Mount
    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = $user->alumni;

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        // ── GUARD: If setup is already complete, bounce to dashboard ──────────
        // needsAccountSetup() is the SINGLE source of truth.
        // No session flag required here — we trust the model check.
        if (!$alumni->needsAccountSetup()) {
            // Clean up any stale wizard session keys
            session()->forget([
                'alumni_requires_password_change',
                'alumni_pending_email',
                'alumni_pending_password',
                'alumni_password_reset_step',
            ]);
            $this->redirect(route('alumni.dashboard'));
            return;
        }

        // ── Guarantee the session flag is always present on the wizard page ───
        // The flag is used for step-restoration only, NOT as a security gate.
        // The middleware and needsAccountSetup() are the real gates.
        session()->put('alumni_requires_password_change', true);

        // ── Restore wizard position from session ──────────────────────────────
        $resetStep       = session('alumni_password_reset_step');
        $pendingEmail    = session('alumni_pending_email');
        $pendingPassword = session('alumni_pending_password');

        if ($resetStep === 'password_set' && $pendingEmail && $pendingPassword && $alumni->otp) {
            $this->step    = 4;
            $this->otpSent = true;
            $this->dispatch('otp-sent');
        } elseif ($resetStep === 'email_set' && $pendingEmail) {
            $this->step  = 3;
            $this->email = $pendingEmail;
        } elseif ($resetStep === 'identity_verified') {
            $this->step = 2;
        } else {
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 1 — Verify Identity
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyIdentity(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $this->validate([
            'first_name'     => 'required|string|max:100',
            'middle_initial' => 'required|string|max:2',
            'last_name'      => 'required|string|max:100',
            'suffix'         => 'nullable|string|max:20',
            'student_id'     => 'required|string',
            'course_code'    => 'required|string|max:20',
            'batch'          => ['required', 'digits:4'],
        ], [
            'first_name.required'     => 'First name is required.',
            'middle_initial.required' => 'Middle initial is required.',
            'last_name.required'      => 'Last name is required.',
            'student_id.required'     => 'Student ID is required.',
            'course_code.required'    => 'Course code is required.',
            'batch.required'          => 'Batch year is required.',
            'batch.digits'            => 'Batch must be a 4-digit year.',
        ]);

        $alumni = auth()->user()->alumni;

        if (!$alumni) {
            $this->errorMessage = 'Alumni record not found.';
            return;
        }

        // ── Normalize inputs ──────────────────────────────────────────────────
        $inputFn  = strtolower(trim($this->first_name));
        $inputMi  = strtolower(trim($this->middle_initial));
        $inputLn  = strtolower(trim($this->last_name));
        $inputSfx = strtolower(trim($this->suffix ?? ''));
        $inputCc  = strtoupper(trim($this->course_code));
        $inputBat = (int) $this->batch;

        // Normalize submitted student ID (strip leading zeros, re-pad to 8)
        $rawId    = ltrim(preg_replace('/[^0-9]/', '', $this->student_id), '0') ?: '0';
        $inputSid = str_pad($rawId, 8, '0', STR_PAD_LEFT);

        // ── Normalize DB values ───────────────────────────────────────────────
        $dbFn  = strtolower(trim($alumni->first_name));
        $dbMi  = strtolower(trim($alumni->middle_initial ?? ''));
        $dbLn  = strtolower(trim($alumni->last_name));
        $dbSfx = strtolower(trim($alumni->suffix ?? ''));
        $dbCc  = strtoupper(trim($alumni->course_code));
        $dbBat = (int) $alumni->batch;
        $dbSid = $alumni->student_id;

        $valid = $inputFn  === $dbFn
              && $inputMi  === $dbMi
              && $inputLn  === $dbLn
              && $inputSfx === $dbSfx
              && $inputSid === $dbSid
              && $inputCc  === $dbCc
              && $inputBat === $dbBat;

        if (!$valid) {
            $this->errorMessage = 'One or more fields do not match our records. Please double-check your information and try again.';
            return;
        }

        session()->put('alumni_password_reset_step', 'identity_verified');
        $this->step           = 2;
        $this->successMessage = 'Identity verified! Please provide your email address.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 2 — Set Email
    // ─────────────────────────────────────────────────────────────────────────

    public function setEmail(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $this->validate([
            'email'              => 'required|email:rfc,dns|max:255',
            'email_confirmation' => 'required|same:email',
        ], [
            'email.required'          => 'Please enter your email address.',
            'email.email'             => 'Please enter a valid email address.',
            'email_confirmation.same' => 'Email addresses do not match.',
        ]);

        // Reject placeholder / pending-local addresses
        if (str_ends_with(strtolower(trim($this->email)), '@pending.local')) {
            $this->errorMessage = 'Please enter a valid personal email address.';
            return;
        }

        $alumni = auth()->user()->alumni;

        // Check email is not already used by another alumni
        $exists = \App\Models\Alumni::where('email', $this->email)
            ->where('id', '!=', $alumni->id)
            ->whereNotNull('email')
            ->exists();

        if ($exists) {
            $this->errorMessage = 'This email address is already registered to another alumni account.';
            return;
        }

        // Also check the users table
        $userConflict = \App\Models\User::where('email', $this->email)
            ->where('id', '!=', auth()->id())
            ->where('email', 'not like', '%@pending.local')
            ->exists();

        if ($userConflict) {
            $this->errorMessage = 'This email address is already registered to another account.';
            return;
        }

        session()->put('alumni_pending_email', $this->email);
        session()->put('alumni_password_reset_step', 'email_set');

        $this->step           = 3;
        $this->successMessage = 'Email saved! Now create a strong password for your account.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 3 — Password + Send OTP
    // ─────────────────────────────────────────────────────────────────────────

    public function updatedPassword(): void
    {
        $this->updatePasswordStrength();
    }

    public function updatePasswordStrength(): void
    {
        $pwd = $this->password;

        if (strlen($pwd) < 8) {
            $this->passwordStrength = 'weak';
            return;
        }

        $score = (int) preg_match('/[A-Z]/', $pwd)
               + (int) preg_match('/[a-z]/', $pwd)
               + (int) preg_match('/[0-9]/', $pwd)
               + (int) preg_match('/[!@#$%^&*?]/', $pwd);

        $this->passwordStrength = match (true) {
            strlen($pwd) >= 12 && $score >= 4 => 'strong',
            strlen($pwd) >= 10 && $score >= 3 => 'good',
            strlen($pwd) >= 8  && $score >= 2 => 'fair',
            default                            => 'weak',
        };
    }

    public function getPasswordStrengthInfo(): array
    {
        return match ($this->passwordStrength) {
            'weak'   => ['label' => 'Weak',   'color' => 'text-red-600',    'progressColor' => 'bg-red-500',    'width' => 'w-1/4'],
            'fair'   => ['label' => 'Fair',   'color' => 'text-orange-600', 'progressColor' => 'bg-orange-500', 'width' => 'w-2/4'],
            'good'   => ['label' => 'Good',   'color' => 'text-amber-600',  'progressColor' => 'bg-amber-500',  'width' => 'w-3/4'],
            'strong' => ['label' => 'Strong', 'color' => 'text-emerald-600','progressColor' => 'bg-emerald-500','width' => 'w-full'],
        };
    }

    public function isPasswordStrengthValid(): bool
    {
        return in_array($this->passwordStrength, ['good', 'strong']);
    }

    public function isPasswordsMatching(): bool
    {
        return $this->password !== ''
            && $this->password_confirmation !== ''
            && $this->password === $this->password_confirmation;
    }

    public function canSendOtp(): bool
    {
        return $this->isPasswordStrengthValid() && $this->isPasswordsMatching();
    }

    public function sendOtp(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        if (!$this->isPasswordStrengthValid()) {
            $this->errorMessage = 'Password strength must be "Good" or "Strong". Add uppercase letters, numbers, and special characters.';
            return;
        }

        $this->validate([
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.min'               => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        $pendingEmail = session('alumni_pending_email');

        if (!$pendingEmail) {
            $this->errorMessage = 'Session expired. Please go back and re-enter your email.';
            $this->step = 2;
            return;
        }

        $alumni = auth()->user()->alumni;

        if (!$alumni) {
            $this->errorMessage = 'Alumni record not found.';
            return;
        }

        try {
            $otp = $alumni->generateOtp();

            try {
                Mail::to($pendingEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Alumni OTP sent to: {$pendingEmail}");
            } catch (\Exception $e) {
                Log::warning("Alumni OTP mail failed for {$pendingEmail}: " . $e->getMessage());
            }

            session()->put('alumni_pending_password', $this->password);
            session()->put('alumni_password_reset_step', 'password_set');

            // Reset any previous lockout/attempt counters
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:' . $alumni->id);
            cache()->forget('alumni_otp_locked_time:' . $alumni->id);

            $this->step           = 4;
            $this->otpSent        = true;
            $this->successMessage = "Verification code sent to {$pendingEmail}. Please check your inbox.";

            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("Alumni sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send verification code. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 4 — Verify OTP & Save Everything
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyOtp(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        if (empty($this->otp)) {
            $this->errorMessage = 'Please enter the 6-digit verification code.';
            return;
        }

        $this->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'otp.regex' => 'Verification code must be exactly 6 digits.',
        ]);

        $user   = auth()->user();
        $alumni = $user->alumni;

        if (!$alumni) {
            $this->errorMessage = 'Alumni record not found.';
            return;
        }

        if (session('alumni_password_reset_step') !== 'password_set') {
            $this->errorMessage = 'Invalid session state. Please start over.';
            $this->step = 1;
            return;
        }

        $pendingEmail    = session('alumni_pending_email');
        $pendingPassword = session('alumni_pending_password');

        if (!$pendingEmail || !$pendingPassword) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
            return;
        }

        try {
            $lockKey     = 'alumni_otp_locked:' . $alumni->id;
            $attemptsKey = 'alumni_otp_attempts:' . $alumni->id;
            $attempts    = cache()->get($attemptsKey, 0);

            if (cache()->has($lockKey)) {
                $remaining = cache()->get('alumni_otp_locked_time:' . $alumni->id, 300);
                $this->errorMessage = "Too many failed attempts. Please try again in {$remaining} seconds.";
                return;
            }

            if ($attempts >= 5) {
                cache()->put($lockKey, true, 300);
                cache()->put('alumni_otp_locked_time:' . $alumni->id, 300, 300);
                $this->errorMessage = 'Too many failed attempts. Account locked for 5 minutes.';
                return;
            }

            if (!$alumni->isOtpValid($this->otp)) {
                $newAttempts       = $attempts + 1;
                cache()->put($attemptsKey, $newAttempts, 600);
                $remainingAttempts = 5 - $newAttempts;

                if ($remainingAttempts <= 0) {
                    cache()->put($lockKey, true, 300);
                    cache()->put('alumni_otp_locked_time:' . $alumni->id, 300, 300);
                    $this->errorMessage = 'Too many failed attempts. Account locked for 5 minutes.';
                } else {
                    $this->errorMessage = "Invalid code. You have {$remainingAttempts} attempt(s) left.";
                }
                return;
            }

            // ── OTP valid — save everything atomically ────────────────────────
            cache()->forget($attemptsKey);
            $alumni->clearOtp();

            DB::transaction(function () use ($user, $alumni, $pendingEmail, $pendingPassword) {
                // 1. Update User: real email + new hashed password
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'email'    => $pendingEmail,
                        'password' => Hash::make($pendingPassword),
                    ]);

                // 2. Update Alumni: real email + mark as VERIFIED
                $alumni->update([
                    'email'  => $pendingEmail,
                    'status' => 'VERIFIED',
                ]);
            });

            // 3. Clear all wizard session keys AFTER successful DB write
            session()->forget([
                'alumni_pending_email',
                'alumni_pending_password',
                'alumni_password_reset_step',
                'alumni_requires_password_change',
            ]);

            Log::info("Alumni account setup completed: {$pendingEmail} (student_id: {$alumni->student_id})");

            $this->redirect(route('alumni.dashboard'));

        } catch (\Exception $e) {
            Log::error("Alumni verifyOtp error: " . $e->getMessage());
            $this->errorMessage = 'Verification failed. Please try again.';
        }
    }

    public function resendOtp(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $pendingEmail = session('alumni_pending_email');
        $alumni       = auth()->user()->alumni;

        if (!$alumni || !$pendingEmail) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        try {
            $otp = $alumni->generateOtp();

            try {
                Mail::to($pendingEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Alumni OTP resent to: {$pendingEmail}");
            } catch (\Exception $e) {
                Log::warning("Alumni resend OTP mail failed: " . $e->getMessage());
            }

            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:' . $alumni->id);
            cache()->forget('alumni_otp_locked_time:' . $alumni->id);

            $this->otp            = '';
            $this->successMessage = "New verification code sent to {$pendingEmail}.";

            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("Alumni resendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to resend code. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Navigation
    // ─────────────────────────────────────────────────────────────────────────

    public function previousStep(): void
    {
        if ($this->step <= 1) return;

        $this->step--;
        $this->errorMessage   = '';
        $this->successMessage = '';
        $this->otp            = '';

        if ($this->step === 1) {
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
            $this->first_name = $this->middle_initial = $this->last_name = '';
            $this->suffix = $this->student_id = $this->course_code = $this->batch = '';
        } elseif ($this->step === 2) {
            session()->forget(['alumni_pending_password', 'alumni_password_reset_step']);
            session()->put('alumni_password_reset_step', 'identity_verified');
            $this->email              = session('alumni_pending_email', '');
            $this->email_confirmation = $this->email;
        } elseif ($this->step === 3) {
            session()->forget('alumni_pending_password');
            session()->put('alumni_password_reset_step', 'email_set');
            $this->password              = '';
            $this->password_confirmation = '';
            $this->passwordStrength      = 'weak';
        }
    }

}; ?>

<div
    class="min-h-screen w-full flex flex-col items-center justify-center p-4 font-sans antialiased"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
    x-data="alumniOtpTimer()"
    x-init="initTimer({{ $step }})"
    x-on:otp-sent-fresh.window="startFresh()"
    x-on:otp-sent.window="restoreOrStart()"
>
    {{-- Overlay --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm z-0"></div>

    {{-- Back link --}}
    <a href="/" wire:navigate
       class="fixed top-6 left-6 z-50 flex items-center gap-2 text-white hover:text-purple-100 transition-all group text-sm">
        <div class="w-8 h-8 flex items-center justify-center rounded-full border border-white/50 group-hover:border-white group-hover:bg-white/20 transition-all">
            <i class="fa-solid fa-arrow-left text-xs"></i>
        </div>
        <span class="font-medium tracking-wide hidden sm:inline">Back to Home</span>
    </a>

    {{-- Card --}}
    <div class="relative z-10 w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Header with step indicator --}}
        <div class="px-6 pt-6 pb-5 border-b border-gray-200">

            {{-- Title --}}
            <div class="flex items-center gap-3 mb-5">
                <div class="w-11 h-11 rounded-xl bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-graduation-cap text-[#7a3f91] text-base"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Alumni Account Setup</h1>
                    <p class="text-sm text-gray-600 mt-0.5">Complete your account to access the portal</p>
                </div>
            </div>

            {{-- 4-Step Indicator --}}
            <div class="flex items-center gap-1">
                @foreach ([1 => 'Verify', 2 => 'Email', 3 => 'Password', 4 => 'OTP'] as $i => $label)
                    <div class="flex items-center {{ $i < 4 ? 'flex-1' : '' }}">
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold transition-all
                                {{ $i == $step ? 'bg-[#7a3f91] text-white' : ($i < $step ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500') }}">
                                @if ($i < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $i }}
                                @endif
                            </div>
                            <span class="text-xs font-semibold hidden sm:inline
                                {{ $i == $step ? 'text-gray-800' : ($i < $step ? 'text-emerald-600' : 'text-gray-400') }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($i < 4)
                            <div class="flex-1 mx-1.5 h-px {{ $i < $step ? 'bg-emerald-400' : 'bg-gray-300' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Body --}}
        <div class="p-6">

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-600 text-base"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-5 p-4 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-600 text-base"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- ═══ STEP 1 — Identity Verification ═══ --}}
            @if ($step == 1)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Verify Your Identity</h2>
                        <p class="text-sm text-gray-600 mt-1">Enter your details exactly as they appear in our records. All fields must match.</p>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-start gap-3">
                        <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 flex-shrink-0"></i>
                        <p class="text-sm text-blue-800">This step confirms you are the rightful owner of this student record. Use the exact spelling from your school documents.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">First Name <span class="text-red-500">*</span></label>
                            <input wire:model.live="first_name" type="text" placeholder="e.g. Juan"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">Last Name <span class="text-red-500">*</span></label>
                            <input wire:model.live="last_name" type="text" placeholder="e.g. Dela Cruz"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">Middle Initial <span class="text-red-500">*</span></label>
                            <input wire:model.live="middle_initial" type="text" placeholder="e.g. S" maxlength="2"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">Suffix <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                            <input wire:model.live="suffix" type="text" placeholder="e.g. Jr., III"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1.5">Student ID <span class="text-red-500">*</span></label>
                        <input wire:model.live="student_id" type="text" placeholder="e.g. 00037801" maxlength="8"
                               class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        <p class="text-xs text-gray-500 mt-1">Enter your 8-digit student ID number.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">Course Code <span class="text-red-500">*</span></label>
                            <input wire:model.live="course_code" type="text" placeholder="e.g. BSCS"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1.5">Batch Year <span class="text-red-500">*</span></label>
                            <input wire:model.live="batch" type="text" placeholder="e.g. 2023" maxlength="4"
                                   class="w-full px-4 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                        </div>
                    </div>

                    <button wire:click="verifyIdentity"
                            wire:loading.attr="disabled"
                            wire:target="verifyIdentity"
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:opacity-70 transition-all flex items-center justify-center gap-2 mt-2">
                        <span wire:loading.remove wire:target="verifyIdentity">
                            <i class="fa-solid fa-shield-halved mr-1"></i> Verify My Identity
                        </span>
                        <span wire:loading wire:target="verifyIdentity">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                        </span>
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 2 — Email Address ═══ --}}
            @if ($step == 2)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Set Your Email Address</h2>
                        <p class="text-sm text-gray-600 mt-1">This email will be used for your account login and notifications.</p>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <p class="text-sm text-amber-800">Use a personal email address you have access to. A verification code will be sent there.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">Email Address <span class="text-red-500">*</span></label>
                        <input wire:model.live="email" type="email" placeholder="yourname@example.com" autocomplete="email"
                               class="w-full px-4 py-3 text-base border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">Confirm Email Address <span class="text-red-500">*</span></label>
                        <input wire:model.live="email_confirmation" type="email" placeholder="Re-enter your email" autocomplete="email"
                               class="w-full px-4 py-3 text-base border-2 rounded-xl focus:outline-none focus:ring-2 transition-all bg-white text-gray-900
                                   {{ $email_confirmation !== '' && $email !== $email_confirmation ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ($email_confirmation !== '' && $email === $email_confirmation ? 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20') }}">
                        @if ($email_confirmation !== '' && $email !== $email_confirmation)
                            <p class="text-sm text-red-700 mt-2 flex items-center gap-1.5 font-medium">
                                <i class="fa-solid fa-xmark"></i> Email addresses do not match
                            </p>
                        @elseif ($email_confirmation !== '' && $email === $email_confirmation && $email !== '')
                            <p class="text-sm text-emerald-700 mt-2 flex items-center gap-1.5 font-medium">
                                <i class="fa-solid fa-check"></i> Email addresses match
                            </p>
                        @endif
                    </div>

                    <button wire:click="setEmail"
                            wire:loading.attr="disabled"
                            wire:target="setEmail"
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="setEmail">
                            <i class="fa-solid fa-envelope mr-1"></i> Save Email & Continue
                        </span>
                        <span wire:loading wire:target="setEmail">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…
                        </span>
                    </button>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-700 py-2 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Identity Verification
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 3 — Create Password ═══ --}}
            @if ($step == 3)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Create Your Password</h2>
                        <p class="text-sm text-gray-700 mt-1">Minimum 8 characters — "Good" or "Strong" strength required.</p>
                    </div>

                    <div class="space-y-2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Password Requirements</p>
                        <div class="space-y-1.5">
                            @foreach ([
                                [strlen($password) >= 8,                 '8 or more characters'],
                                [preg_match('/[A-Z]/', $password),       'At least one uppercase letter (A–Z)'],
                                [preg_match('/[a-z]/', $password),       'At least one lowercase letter (a–z)'],
                                [preg_match('/[0-9]/', $password),       'At least one number (0–9)'],
                                [preg_match('/[!@#$%^&*?]/', $password), 'At least one special character (!@#$%^&*)'],
                            ] as [$met, $text])
                                <div class="flex items-center gap-2 py-1">
                                    <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs
                                        {{ $met ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600' }}">
                                        {{ $met ? '✓' : '○' }}
                                    </span>
                                    <span class="text-sm {{ $met ? 'text-emerald-800 font-medium' : 'text-gray-700' }}">{{ $text }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">New Password</label>
                        <div class="relative">
                            <input wire:model.live="password"
                                   type="{{ $showPassword ? 'text' : 'password' }}"
                                   placeholder="Enter your new password"
                                   autocomplete="new-password"
                                   class="w-full px-4 py-3 text-base border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all pr-12 bg-white text-gray-900">
                            <button type="button" wire:click="$toggle('showPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-[#7a3f91] transition-colors">
                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-base"></i>
                            </button>
                        </div>
                    </div>

                    @if ($password !== '')
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-gray-800">Password Strength</span>
                                <span class="text-sm font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">
                                    {{ $this->getPasswordStrengthInfo()['label'] }}
                                </span>
                            </div>
                            <div class="h-2 bg-gray-300 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-300 {{ $this->getPasswordStrengthInfo()['progressColor'] }} {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">Confirm Password</label>
                        <div class="relative">
                            <input wire:model.live="password_confirmation"
                                   type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                   placeholder="Re-enter your password"
                                   autocomplete="new-password"
                                   class="w-full px-4 py-3 text-base border-2 rounded-xl focus:outline-none focus:ring-2 transition-all pr-12 bg-white text-gray-900
                                       {{ $password_confirmation !== '' && $password !== $password_confirmation ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ($password_confirmation !== '' && $password === $password_confirmation ? 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20') }}">
                            <button type="button" wire:click="$toggle('showConfirmPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-[#7a3f91] transition-colors">
                                <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-base"></i>
                            </button>
                        </div>
                        @if ($password_confirmation !== '' && $password !== $password_confirmation)
                            <p class="text-sm text-red-700 mt-2 flex items-center gap-1.5 font-medium">
                                <i class="fa-solid fa-xmark"></i> Passwords do not match
                            </p>
                        @elseif ($password_confirmation !== '' && $password === $password_confirmation && $password !== '')
                            <p class="text-sm text-emerald-700 mt-2 flex items-center gap-1.5 font-medium">
                                <i class="fa-solid fa-check"></i> Passwords match
                            </p>
                        @endif
                    </div>

                    <button wire:click="sendOtp"
                            wire:loading.attr="disabled"
                            wire:target="sendOtp"
                            {{ !$this->canSendOtp() ? 'disabled' : '' }}
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none transition-all flex items-center justify-center gap-2 mt-2">
                        <span wire:loading.remove wire:target="sendOtp">
                            <i class="fa-solid fa-paper-plane mr-1"></i> Send Verification Code & Continue
                        </span>
                        <span wire:loading wire:target="sendOtp">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending…
                        </span>
                    </button>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-700 py-2 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Email Setup
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 4 — OTP Verification ═══ --}}
            @if ($step == 4)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Verify Your Email</h2>
                        <p class="text-sm text-gray-700 mt-1">
                            Enter the 6-digit code sent to
                            <strong class="text-[#7a3f91]">{{ session('alumni_pending_email', 'your email') }}</strong>.
                        </p>
                    </div>

                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl p-6 text-center">
                        <p class="text-xs font-semibold text-gray-700 uppercase tracking-widest mb-2">Code expires in</p>
                        <div class="text-5xl font-bold font-mono tabular-nums transition-colors duration-300"
                             :class="seconds <= 60 ? 'text-red-600' : 'text-[#7a3f91]'"
                             x-text="formattedTime">
                            10:00
                        </div>
                        <p x-show="expired" x-cloak class="text-red-700 text-sm mt-3 font-semibold">
                            <i class="fa-solid fa-triangle-exclamation mr-2"></i> Code has expired. Please request a new one.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">6-Digit Code</label>
                        <input wire:model.live="otp"
                               type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                               placeholder="000000" autocomplete="one-time-code"
                               class="w-full px-4 py-4 text-center text-4xl font-bold tracking-[0.5em] border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                    </div>

                    <div class="space-y-3 pt-2">
                        <button wire:click="verifyOtp"
                                wire:loading.attr="disabled"
                                wire:target="verifyOtp"
                                x-bind:disabled="expired"
                                :class="expired ? 'bg-gray-400 cursor-not-allowed' : 'bg-[#7a3f91] hover:bg-[#6a3080] hover:shadow-lg active:scale-[0.98]'"
                                class="w-full text-white py-3 rounded-xl font-semibold text-base shadow-md disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="verifyOtp">
                                <i class="fa-solid fa-circle-check mr-1"></i> Verify & Activate Account
                            </span>
                            <span wire:loading wire:target="verifyOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                            </span>
                        </button>

                        <button wire:click="resendOtp"
                                wire:loading.attr="disabled"
                                wire:target="resendOtp"
                                x-bind:disabled="!canResend"
                                type="button"
                                :class="{
                                    'bg-gray-100 text-gray-400 border-gray-300 cursor-not-allowed': !canResend,
                                    'bg-white text-[#7a3f91] border-[#7a3f91] hover:bg-purple-50 active:scale-[0.98] cursor-pointer': canResend
                                }"
                                class="w-full py-3 rounded-xl font-semibold text-base border-2 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="resendOtp">
                                <i class="fa-solid fa-rotate-right mr-2"></i>
                                <span x-show="!canResend" class="text-sm font-medium">
                                    Resend available in <span class="font-bold" x-text="formattedTime"></span>
                                </span>
                                <span x-show="canResend" class="text-sm font-medium">Resend Code</span>
                            </span>
                            <span wire:loading wire:target="resendOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-2"></i> Sending…
                            </span>
                        </button>

                        <p x-show="!canResend" x-cloak class="text-xs text-gray-500 text-center">
                            Wait for the timer to finish before requesting a new code.
                        </p>
                    </div>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-700 py-2 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Password Setup
                    </button>
                </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 text-center bg-gray-50 border-t border-gray-200">
            <p class="text-xs text-gray-600 font-medium">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
        </div>
    </div>

    <script>
        function alumniOtpTimer() {
            const STORAGE_KEY = 'alumni_otp_timer_expiry';
            const DURATION_MS = 10 * 60 * 1000;

            return {
                seconds: 600,
                expired: false,
                canResend: false,
                _interval: null,

                get formattedTime() {
                    const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                    const s = String(this.seconds % 60).padStart(2, '0');
                    return `${m}:${s}`;
                },

                initTimer(step) {
                    if (step === 4) this.restoreOrStart();
                },

                startFresh() {
                    const expiry = Date.now() + DURATION_MS;
                    localStorage.setItem(STORAGE_KEY, expiry.toString());
                    this._beginCountdown(expiry);
                },

                restoreOrStart() {
                    const stored = localStorage.getItem(STORAGE_KEY);
                    if (stored) {
                        const expiry    = parseInt(stored, 10);
                        const remaining = Math.floor((expiry - Date.now()) / 1000);
                        if (remaining > 0) {
                            this.seconds   = remaining;
                            this.expired   = false;
                            this.canResend = false;
                            this._tick(expiry);
                        } else {
                            this.seconds   = 0;
                            this.expired   = true;
                            this.canResend = true;
                            localStorage.removeItem(STORAGE_KEY);
                        }
                    } else {
                        this.startFresh();
                    }
                },

                _beginCountdown(expiry) {
                    this._clearInterval();
                    this.seconds   = Math.max(0, Math.floor((expiry - Date.now()) / 1000));
                    this.expired   = false;
                    this.canResend = false;
                    this._tick(expiry);
                },

                _tick(expiry) {
                    this._interval = setInterval(() => {
                        const remaining = Math.floor((expiry - Date.now()) / 1000);
                        if (remaining > 0) {
                            this.seconds = remaining;
                        } else {
                            this.seconds   = 0;
                            this.expired   = true;
                            this.canResend = true;
                            localStorage.removeItem(STORAGE_KEY);
                            this._clearInterval();
                        }
                    }, 500);
                },

                _clearInterval() {
                    if (this._interval) {
                        clearInterval(this._interval);
                        this._interval = null;
                    }
                }
            };
        }
    </script>
</div>