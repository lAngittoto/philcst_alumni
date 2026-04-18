<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Mail\AlumniPasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

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
    public string $otp       = '';
    public bool   $otpSent   = false;
    public bool   $otpLocked = false;

    // ── Identity lock ─────────────────────────────────────────────────────────
    public bool $identityLocked          = false;
    public int  $identityLockSecondsLeft = 0;

    // ── UI ────────────────────────────────────────────────────────────────────
    public string $errorMessage     = '';
    public string $successMessage   = '';
    public bool   $showSuccessModal = false;

    // ─────────────────────────────────────────────────────────────────────────
    // Cache key helpers — keyed by alumni ID so they survive session resets
    // ─────────────────────────────────────────────────────────────────────────

    private function cacheEmailKey(int $alumniId): string
    {
        return "alumni_pending_email:{$alumniId}";
    }

    private function cachePasswordKey(int $alumniId): string
    {
        return "alumni_pending_password:{$alumniId}";
    }

    private function cacheStepKey(int $alumniId): string
    {
        return "alumni_pending_step:{$alumniId}";
    }

    private function clearWizardCache(int $alumniId): void
    {
        cache()->forget($this->cacheEmailKey($alumniId));
        cache()->forget($this->cachePasswordKey($alumniId));
        cache()->forget($this->cacheStepKey($alumniId));
    }

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

        $alumni = \App\Models\Alumni::where('user_id', $user->id)->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        // ── GUARD: Must arrive via the login flow ─────────────────────────────
        //
        // The session key 'alumni_requires_password_change' is ONLY set by the
        // login component after the alumni has entered their credentials.
        // If it is absent, the alumni navigated here directly (e.g. typed the
        // URL or used browser history) without logging in — so we log them out
        // and send them back to the login page to re-enter their credentials.
        //
        // Exception: if the wizard is already in progress (alumni_password_reset_step
        // is set), we let them continue — they have already authenticated this session.
        //
        $hasLoginFlag  = session()->has('alumni_requires_password_change');
        $wizardStarted = session()->has('alumni_password_reset_step');

        if (!$hasLoginFlag && !$wizardStarted) {
            // Not from login and no wizard in progress — force re-authentication.
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->redirect(route('login'));
            return;
        }

        // ── Determine whether this alumni still needs onboarding ──────────────
        $stillOnTempPassword = $alumni->hasTemporaryPassword();
        $wizardIncomplete    = $alumni->needsAccountSetup();
        $requiresOnboarding  = $wizardIncomplete || $stillOnTempPassword;

        if (!$requiresOnboarding) {
            // Wizard is genuinely complete — clean up and send to profile page
            session()->forget([
                'alumni_requires_password_change',
                'alumni_pending_email',
                'alumni_pending_password',
                'alumni_password_reset_step',
            ]);
            $this->clearWizardCache($alumni->id);
            $this->redirect(route('alumni.information'));
            return;
        }

        // ── Self-heal: DB flag inconsistent with actual password state ────────
        if (!$wizardIncomplete && $stillOnTempPassword) {
            DB::table('alumni')
                ->where('id', $alumni->id)
                ->update(['password_changed_at' => null]);
            $alumni->password_changed_at = null;

            Log::info("change-password mount: self-healed password_changed_at for alumni #{$alumni->id}");
        }

        session()->put('alumni_requires_password_change', true);

        // ── Identity lock state ───────────────────────────────────────────────
        $identityLockKey = 'alumni_identity_locked:' . $alumni->id;
        if (cache()->has($identityLockKey)) {
            $this->identityLocked          = true;
            $this->identityLockSecondsLeft = (int) cache()->get('alumni_identity_locked_time:' . $alumni->id, 600);
        }

        // ── DB-FIRST: If OTP is still active in DB, force Step 4 ─────────────
        if ($alumni->isOtpStillActive()) {
            $cachedEmail    = cache()->get($this->cacheEmailKey($alumni->id));
            $cachedPassword = cache()->get($this->cachePasswordKey($alumni->id));

            if ($cachedEmail && $cachedPassword) {
                session()->put('alumni_pending_email',       $cachedEmail);
                session()->put('alumni_pending_password',    $cachedPassword);
                session()->put('alumni_password_reset_step', 'password_set');

                $this->step      = 4;
                $this->otpSent   = true;
                $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);

                $this->dispatch('otp-sent');
                return;
            }

            // OTP active but cache evicted — clear stale OTP and start fresh
            $alumni->clearOtp();
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);
        }

        // ── Fallback: restore from session (same browser, session intact) ─────
        $resetStep       = session('alumni_password_reset_step');
        $pendingEmail    = session('alumni_pending_email');
        $pendingPassword = session('alumni_pending_password');

        if ($resetStep === 'password_set' && $pendingEmail && $pendingPassword) {
            $this->step      = 4;
            $this->otpSent   = true;
            $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);
            $this->dispatch('otp-sent');
        } elseif ($resetStep === 'email_set' && $pendingEmail) {
            $this->step  = 3;
            $this->email = $pendingEmail;
        } elseif ($resetStep === 'identity_verified') {
            $this->step = 2;
        } else {
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
            $this->clearWizardCache($alumni->id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Back to Home — LOG OUT so alumni must re-enter credentials on next visit
    //
    // Kapag nag-click ang alumni ng "Back to Home", ini-invalidate natin ang
    // session nila. Kaya sa susunod na pumunta sila sa login page, wala nang
    // auto-redirect — kailangan nilang i-enter ulit ang kanilang credentials.
    // ─────────────────────────────────────────────────────────────────────────

    public function backToHome(): void
    {
        $user   = auth()->user();
        $alumni = $user ? \App\Models\Alumni::where('user_id', $user->id)->first() : null;

        // Clear wizard cache so no stale data remains
        if ($alumni) {
            $this->clearWizardCache($alumni->id);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 1 — Verify Identity
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyIdentity(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();

        if (!$alumni) {
            $this->errorMessage = 'Alumni record not found.';
            return;
        }

        $lockKey        = 'alumni_identity_locked:'      . $alumni->id;
        $lockTimeKey    = 'alumni_identity_locked_time:' . $alumni->id;
        $attemptsKey    = 'alumni_identity_attempts:'    . $alumni->id;

        if (cache()->has($lockKey)) {
            $remaining                     = (int) cache()->get($lockTimeKey, 600);
            $this->identityLocked          = true;
            $this->identityLockSecondsLeft = $remaining;
            $mins = ceil($remaining / 60);
            $this->errorMessage = "Too many failed attempts. Your account is locked. Please try again in {$mins} minute(s).";
            return;
        }

        $attempts = cache()->get($attemptsKey, 0) + 1;
        cache()->put($attemptsKey, $attempts, 700);

        $inputFn  = strtolower(trim($this->first_name));
        $inputMi  = strtolower(trim($this->middle_initial));
        $inputLn  = strtolower(trim($this->last_name));
        $inputSfx = strtolower(trim($this->suffix ?? ''));
        $inputCc  = strtoupper(trim($this->course_code));
        $inputBat = (int) $this->batch;

        $rawId    = ltrim(preg_replace('/[^0-9]/', '', $this->student_id), '0') ?: '0';
        $inputSid = str_pad($rawId, 8, '0', STR_PAD_LEFT);

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
            if ($attempts >= 3) {
                cache()->put($lockKey,     true, 600);
                cache()->put($lockTimeKey, 600,  600);
                $this->identityLocked          = true;
                $this->identityLockSecondsLeft = 600;
                $this->errorMessage = 'Too many failed attempts. Your account is locked for 10 minutes.';
            } else {
                $remaining          = 3 - $attempts;
                $this->errorMessage = "One or more fields do not match our records. You have {$remaining} attempt(s) remaining.";
            }
            return;
        }

        cache()->forget($attemptsKey);
        cache()->forget($lockKey);
        cache()->forget($lockTimeKey);

        session()->put('alumni_password_reset_step', 'identity_verified');
        $this->step           = 2;
        $this->successMessage = 'Identity verified! Please provide your email address.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Step 2 — Set Email
    // ─────────────────────────────────────────────────────────────────────────

    public function canSetEmail(): bool
    {
        return $this->email !== ''
            && $this->email_confirmation !== ''
            && $this->email === $this->email_confirmation;
    }

    public function setEmail(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $this->validate([
            'email'              => 'required|email:rfc,dns|max:255',
            'email_confirmation' => 'required|same:email',
        ], [
            'email.required'              => 'Email address is required.',
            'email.email'                 => 'Please enter a valid email address (e.g. you@gmail.com).',
            'email_confirmation.required' => 'Please confirm your email address.',
            'email_confirmation.same'     => 'Email addresses do not match.',
        ]);

        if (str_ends_with(strtolower(trim($this->email)), '@pending.local')) {
            $this->errorMessage = 'Please enter a valid personal email address.';
            return;
        }

        $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();

        $exists = \App\Models\Alumni::where('email', $this->email)
            ->where('id', '!=', $alumni->id)
            ->whereNotNull('email')
            ->exists();

        if ($exists) {
            $this->errorMessage = 'This email address is already registered to another alumni account.';
            return;
        }

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
        cache()->put($this->cacheEmailKey($alumni->id), $this->email, now()->addHour());
        cache()->put($this->cacheStepKey($alumni->id), 'email_set', now()->addHour());

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

        $pendingEmail = session('alumni_pending_email')
            ?? cache()->get($this->cacheEmailKey(
                \App\Models\Alumni::where('user_id', auth()->id())->value('id')
            ));

        if (!$pendingEmail) {
            $this->errorMessage = 'Session expired. Please go back and re-enter your email.';
            $this->step = 2;
            return;
        }

        $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();

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

            session()->put('alumni_pending_password',    $this->password);
            session()->put('alumni_pending_email',       $pendingEmail);
            session()->put('alumni_password_reset_step', 'password_set');

            cache()->put($this->cachePasswordKey($alumni->id), $this->password,  now()->addMinutes(15));
            cache()->put($this->cacheEmailKey($alumni->id),    $pendingEmail,     now()->addMinutes(15));
            cache()->put($this->cacheStepKey($alumni->id),     'password_set',   now()->addMinutes(15));

            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);

            $this->step           = 4;
            $this->otpSent        = true;
            $this->otpLocked      = false;
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

        $trimmedOtp = trim($this->otp);

        if ($trimmedOtp === '' || strlen($trimmedOtp) < 6) {
            $this->errorMessage = 'Please enter the complete 6-digit verification code.';
            return;
        }

        if (!preg_match('/^\d{6}$/', $trimmedOtp)) {
            $this->errorMessage = 'The verification code must contain only digits.';
            return;
        }

        $user   = auth()->user();
        $alumni = \App\Models\Alumni::where('user_id', $user->id)->first();

        if (!$alumni) {
            $this->errorMessage = 'Alumni record not found.';
            return;
        }

        $pendingEmail    = session('alumni_pending_email')
            ?? cache()->get($this->cacheEmailKey($alumni->id));
        $pendingPassword = session('alumni_pending_password')
            ?? cache()->get($this->cachePasswordKey($alumni->id));

        if (!$pendingEmail || !$pendingPassword) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
            $this->clearWizardCache($alumni->id);
            return;
        }

        try {
            $lockKey     = 'alumni_otp_locked:'   . $alumni->id;
            $attemptsKey = 'alumni_otp_attempts:' . $alumni->id;
            $attempts    = cache()->get($attemptsKey, 0);

            if (cache()->has($lockKey)) {
                $this->otpLocked    = true;
                $this->errorMessage = 'Too many failed attempts. Please wait for the timer to expire, then request a new code.';
                return;
            }

            if ($attempts >= 3) {
                cache()->put($lockKey, true, 700);
                $this->otpLocked    = true;
                $this->errorMessage = 'Too many failed attempts. Wait for the timer to expire, then request a new code.';
                return;
            }

            if (!$alumni->isOtpValid($trimmedOtp)) {
                $newAttempts       = $attempts + 1;
                cache()->put($attemptsKey, $newAttempts, 700);
                $remainingAttempts = 3 - $newAttempts;

                if ($remainingAttempts <= 0) {
                    cache()->put($lockKey, true, 700);
                    $this->otpLocked    = true;
                    $this->errorMessage = 'Too many failed attempts. Wait for the timer to expire, then request a new code.';
                } else {
                    $this->errorMessage = "Invalid or expired code. You have {$remainingAttempts} attempt(s) remaining.";
                }
                return;
            }

            cache()->forget($attemptsKey);
            cache()->forget($lockKey);
            $alumni->clearOtp();

            $now = now();

            DB::transaction(function () use ($user, $alumni, $pendingEmail, $pendingPassword, $now) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'email'      => $pendingEmail,
                        'password'   => Hash::make($pendingPassword),
                        'updated_at' => $now,
                    ]);

                DB::table('alumni')
                    ->where('id', $alumni->id)
                    ->update([
                        'email'               => $pendingEmail,
                        'status'              => 'VERIFIED',
                        'password_changed_at' => $now,
                        'updated_at'          => $now,
                    ]);
            });

            $alumni->refresh();

            if ($alumni->needsAccountSetup()) {
                Log::error("Alumni verifyOtp: needsAccountSetup() still true after update for alumni #{$alumni->id}.");
                $this->errorMessage = 'Account activation failed due to a data error. Please contact support.';
                return;
            }

            session()->forget([
                'alumni_pending_email',
                'alumni_pending_password',
                'alumni_password_reset_step',
                'alumni_requires_password_change',
            ]);
            $this->clearWizardCache($alumni->id);

            Log::info("Alumni account setup completed: {$pendingEmail} (alumni_id: {$alumni->id}, student_id: {$alumni->student_id})");

            $this->showSuccessModal = true;
            $this->dispatch('account-activated');

        } catch (\Exception $e) {
            Log::error("Alumni verifyOtp error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            $this->errorMessage = 'Verification failed. Please try again.';
        }
    }

    public function resendOtp(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();

        $pendingEmail = session('alumni_pending_email')
            ?? ($alumni ? cache()->get($this->cacheEmailKey($alumni->id)) : null);

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

            $pendingPassword = session('alumni_pending_password')
                ?? cache()->get($this->cachePasswordKey($alumni->id));

            cache()->put($this->cacheEmailKey($alumni->id),    $pendingEmail,     now()->addMinutes(15));
            cache()->put($this->cachePasswordKey($alumni->id), $pendingPassword,  now()->addMinutes(15));
            cache()->put($this->cacheStepKey($alumni->id),     'password_set',   now()->addMinutes(15));

            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);

            $this->otp            = '';
            $this->otpLocked      = false;
            $this->successMessage = "New verification code sent to {$pendingEmail}. You have 3 attempts.";

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

        if ($this->step === 4) {
            $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();
            if ($alumni && $alumni->isOtpStillActive()) {
                $this->errorMessage = 'You cannot go back while a verification code is active. Please wait for the timer to expire, then request a new code.';
                return;
            }
            if ($alumni) {
                $alumni->clearOtp();
                cache()->forget('alumni_otp_attempts:' . $alumni->id);
                cache()->forget('alumni_otp_locked:'   . $alumni->id);
                $this->clearWizardCache($alumni->id);
            }
        }

        $this->step--;
        $this->errorMessage   = '';
        $this->successMessage = '';
        $this->otp            = '';
        $this->otpLocked      = false;

        if ($this->step === 1) {
            session()->forget(['alumni_pending_email', 'alumni_pending_password', 'alumni_password_reset_step']);
            $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();
            if ($alumni) $this->clearWizardCache($alumni->id);
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

    public function goToDashboard(): void
    {
        $alumni = \App\Models\Alumni::where('user_id', auth()->id())->first();

        if ($alumni && !$alumni->needsAccountSetup()) {
            $this->redirect(route('alumni.information'));
        } else {
            Log::warning("goToDashboard called but needsAccountSetup() still true for user #" . auth()->id());
            $this->errorMessage = 'Account activation is still pending. Please refresh and try again.';
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

    {{-- Back link — calls backToHome() to log out before redirecting --}}
    <button
        wire:click="backToHome"
        wire:loading.attr="disabled"
        wire:target="backToHome"
        class="fixed top-6 left-6 z-50 flex items-center gap-2 text-white hover:text-purple-100 transition-all group text-sm bg-transparent border-0 cursor-pointer"
    >
        <div class="w-8 h-8 flex items-center justify-center rounded-full border border-white/50 group-hover:border-white group-hover:bg-white/20 transition-all">
            <span wire:loading.remove wire:target="backToHome">
                <i class="fa-solid fa-arrow-left text-xs"></i>
            </span>
            <span wire:loading wire:target="backToHome">
                <i class="fa-solid fa-circle-notch fa-spin text-xs"></i>
            </span>
        </div>
        <span class="font-medium tracking-wide hidden sm:inline">Back to Home</span>
    </button>

    {{-- ═══════════════════════════════════════════════════════════════════════
         SUCCESS MODAL
    ════════════════════════════════════════════════════════════════════════ --}}
    @if ($showSuccessModal)
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            x-data="{ visible: false }"
            x-init="$nextTick(() => { visible = true })"
        >
            <div
                class="absolute inset-0 bg-black/70 backdrop-blur-md"
                x-show="visible"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
            ></div>

            <div
                class="relative z-10 w-full max-w-md bg-white rounded-3xl shadow-2xl overflow-hidden text-center"
                x-show="visible"
                x-transition:enter="transition ease-out duration-400"
                x-transition:enter-start="opacity-0 scale-90 translate-y-8"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            >
                <div class="h-2 bg-gradient-to-r from-[#7a3f91] via-emerald-500 to-[#7a3f91]"></div>

                <div class="px-8 pt-8 pb-7 space-y-5">
                    <div class="flex justify-center">
                        <div class="relative w-20 h-20">
                            <div class="absolute inset-0 rounded-full bg-emerald-100 animate-ping opacity-40"></div>
                            <div class="absolute inset-2 rounded-full bg-emerald-100 animate-ping opacity-30" style="animation-delay: 0.2s"></div>
                            <div class="relative w-20 h-20 rounded-full bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center shadow-lg shadow-emerald-200">
                                <i class="fa-solid fa-check text-white text-3xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h2 class="text-2xl font-extrabold text-gray-900 tracking-tight">🎉 Account Activated!</h2>
                        <p class="text-base font-semibold text-gray-700">Your alumni account has been verified.</p>
                        <p class="text-sm text-gray-500 leading-relaxed">
                            Welcome to the PCST Alumni Portal! Please complete your profile information to access all features.
                        </p>
                    </div>

                    <div class="bg-gradient-to-r from-[#7a3f91]/10 to-purple-50 border border-[#7a3f91]/20 rounded-2xl p-4 flex items-center gap-4">
                        <div class="w-11 h-11 rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap text-white text-base"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-xs font-semibold text-[#7a3f91] uppercase tracking-wider">Account Status</p>
                            <p class="text-sm font-bold text-gray-800">Verified Alumni ✓</p>
                            <p class="text-xs text-gray-500">Philippine College of Science and Technology</p>
                        </div>
                    </div>

                    <button
                        wire:click="goToDashboard"
                        wire:loading.attr="disabled"
                        wire:target="goToDashboard"
                        class="w-full bg-gradient-to-r from-[#7a3f91] to-[#6a3080] hover:from-[#6a3080] hover:to-[#5a2670] text-white py-3.5 rounded-2xl font-bold text-base shadow-lg shadow-purple-200 hover:shadow-xl active:scale-[0.98] transition-all flex items-center justify-center gap-3"
                    >
                        <span wire:loading.remove wire:target="goToDashboard">
                            <i class="fa-solid fa-user-circle mr-2"></i>Complete My Profile
                        </span>
                        <span wire:loading wire:target="goToDashboard">
                            <i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Loading…
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         MAIN CARD
    ════════════════════════════════════════════════════════════════════════ --}}
    <div class="relative z-10 w-full max-w-3xl bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Header --}}
        <div class="px-6 pt-5 pb-4 border-b border-gray-200">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-graduation-cap text-[#7a3f91] text-base"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Alumni Account Setup</h1>
                    <p class="text-xs text-gray-500 mt-0.5">Complete your account to access the portal</p>
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
        <div class="px-6 py-4">

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="mb-4 p-3 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-600 text-sm"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-4 p-3 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-600 text-sm"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- ═══ STEP 1 — Identity Verification ═══ --}}
            @if ($step == 1)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Verify Your Identity</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Enter your details exactly as they appear in our records. All fields must match.</p>
                    </div>

                    @if ($identityLocked)
                        <div class="bg-red-50 border-2 border-red-300 rounded-xl p-4 flex items-start gap-3">
                            <i class="fa-solid fa-lock text-red-500 mt-0.5 flex-shrink-0"></i>
                            <div>
                                <p class="text-sm font-bold text-red-700">Account Temporarily Locked</p>
                                <p class="text-xs text-red-600 mt-0.5">
                                    Too many failed attempts. Please wait 10 minutes before trying again.
                                    You may close this page and return later.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 flex items-start gap-3">
                            <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 flex-shrink-0 text-sm"></i>
                            <p class="text-sm text-blue-800">This step confirms you are the rightful owner of this student record. Use the exact spelling from your school documents.</p>
                        </div>
                    @endif

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">First Name</label>
                            <input wire:model="first_name" type="text" autocomplete="off" autocorrect="off" spellcheck="false"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Middle Name</label>
                            <input wire:model="middle_initial" type="text" autocomplete="off" autocorrect="off" spellcheck="false"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Last Name</label>
                            <input wire:model="last_name" type="text" autocomplete="off" autocorrect="off" spellcheck="false"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">
                                Suffix <span class="text-gray-400 font-normal text-xs">(optional)</span>
                            </label>
                            <input wire:model="suffix" type="text" autocomplete="off"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Student ID</label>
                            <input wire:model="student_id" type="text" autocomplete="off"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Course</label>
                            <input wire:model="course_code" type="text" autocomplete="off"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Batch Year</label>
                            <input wire:model="batch" type="text" maxlength="4" autocomplete="off"
                                   {{ $identityLocked ? 'disabled' : '' }}
                                   class="w-full px-3 py-2 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900 disabled:bg-gray-100 disabled:cursor-not-allowed">
                        </div>
                    </div>

                    <button wire:click="verifyIdentity"
                            wire:loading.attr="disabled"
                            wire:target="verifyIdentity"
                            {{ $identityLocked ? 'disabled' : '' }}
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-2.5 rounded-xl font-semibold text-sm shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                        @if ($identityLocked)
                            <span><i class="fa-solid fa-lock mr-1"></i> Account Locked — Try Again Later</span>
                        @else
                            <span wire:loading.remove wire:target="verifyIdentity">
                                <i class="fa-solid fa-shield-halved mr-1"></i> Verify My Identity
                            </span>
                            <span wire:loading wire:target="verifyIdentity">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                            </span>
                        @endif
                    </button>

                    @if (!$identityLocked)
                        <p class="text-xs text-center text-gray-400">
                            <i class="fa-solid fa-triangle-exclamation mr-1 text-amber-400"></i>
                            Maximum 3 attempts — 10-minute lockout after 3 failures
                        </p>
                    @endif
                </div>
            @endif

            {{-- ═══ STEP 2 — Email Address ═══ --}}
            @if ($step == 2)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Set Your Email Address</h2>
                        <p class="text-sm text-gray-500 mt-0.5">This email will be used for your account login and notifications.</p>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-sm text-amber-800">Use a personal email address you have access to. A verification code will be sent there.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Email Address <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="email" type="email" autocomplete="email"
                                   class="w-full px-3 py-2.5 text-sm border-2 rounded-xl focus:outline-none focus:ring-2 transition-all bg-white text-gray-900
                                       {{ $errors->has('email') ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20' }}">
                            @error('email')
                                <p class="text-xs text-red-700 mt-1.5 flex items-center gap-1 font-medium">
                                    <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-1">Confirm Email <span class="text-red-500">*</span></label>
                            <input wire:model.live.debounce.300ms="email_confirmation" type="email" autocomplete="email"
                                   class="w-full px-3 py-2.5 text-sm border-2 rounded-xl focus:outline-none focus:ring-2 transition-all bg-white text-gray-900
                                       {{ $errors->has('email_confirmation') ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ($email_confirmation !== '' && $email !== $email_confirmation ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ($email_confirmation !== '' && $email === $email_confirmation ? 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20')) }}">
                            @error('email_confirmation')
                                <p class="text-xs text-red-700 mt-1.5 flex items-center gap-1 font-medium">
                                    <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                                </p>
                            @enderror
                            @if (!$errors->has('email_confirmation'))
                                @if ($email_confirmation !== '' && $email !== $email_confirmation)
                                    <p class="text-xs text-red-700 mt-1.5 flex items-center gap-1 font-medium">
                                        <i class="fa-solid fa-xmark"></i> Emails do not match
                                    </p>
                                @elseif ($email_confirmation !== '' && $email === $email_confirmation && $email !== '')
                                    <p class="text-xs text-emerald-700 mt-1.5 flex items-center gap-1 font-medium">
                                        <i class="fa-solid fa-check"></i> Emails match
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>

                    <button wire:click="setEmail"
                            wire:loading.attr="disabled"
                            wire:target="setEmail"
                            {{ !$this->canSetEmail() ? 'disabled' : '' }}
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-2.5 rounded-xl font-semibold text-sm shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="setEmail">
                            @if (!$this->canSetEmail())
                                <i class="fa-solid fa-lock mr-1"></i> Enter matching emails to continue
                            @else
                                <i class="fa-solid fa-envelope mr-1"></i> Save Email & Continue
                            @endif
                        </span>
                        <span wire:loading wire:target="setEmail">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…
                        </span>
                    </button>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-600 py-1.5 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Identity Verification
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 3 — Create Password ═══ --}}
            @if ($step == 3)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Create Your Password</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Minimum 8 characters — "Good" or "Strong" strength required.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-5">

                        {{-- Left: requirements --}}
                        <div class="space-y-3 bg-gray-50 p-3 rounded-xl border border-gray-200 self-start">
                            <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Password Requirements</p>
                            <div class="space-y-1.5">
                                @foreach ([
                                    [strlen($password) >= 8,                 '8 or more characters'],
                                    [preg_match('/[A-Z]/', $password),       'One uppercase letter (A–Z)'],
                                    [preg_match('/[a-z]/', $password),       'One lowercase letter (a–z)'],
                                    [preg_match('/[0-9]/', $password),       'One number (0–9)'],
                                    [preg_match('/[!@#$%^&*?]/', $password), 'One special character (!@#$%^&*)'],
                                ] as [$met, $text])
                                    <div class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs
                                            {{ $met ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-500' }}">
                                            {{ $met ? '✓' : '○' }}
                                        </span>
                                        <span class="text-xs {{ $met ? 'text-emerald-800 font-medium' : 'text-gray-600' }}">{{ $text }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Right: inputs --}}
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-800 mb-1">New Password</label>
                                <div class="relative">
                                    <input wire:model.live.debounce.200ms="password"
                                           type="{{ $showPassword ? 'text' : 'password' }}"
                                           autocomplete="new-password"
                                           class="w-full px-3 py-2.5 text-sm border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all pr-10 bg-white text-gray-900">
                                    <button type="button" wire:click="$toggle('showPassword')"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#7a3f91] transition-colors">
                                        <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                                    </button>
                                </div>
                            </div>

                            @if ($password !== '')
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-xs font-semibold text-gray-700">Strength</span>
                                        <span class="text-xs font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">
                                            {{ $this->getPasswordStrengthInfo()['label'] }}
                                        </span>
                                    </div>
                                    <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-300 {{ $this->getPasswordStrengthInfo()['progressColor'] }} {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                                    </div>
                                </div>
                            @endif

                            <div>
                                <label class="block text-sm font-semibold text-gray-800 mb-1">Confirm Password</label>
                                <div class="relative">
                                    <input wire:model.live.debounce.200ms="password_confirmation"
                                           type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                           autocomplete="new-password"
                                           class="w-full px-3 py-2.5 text-sm border-2 rounded-xl focus:outline-none focus:ring-2 transition-all pr-10 bg-white text-gray-900
                                               {{ $password_confirmation !== '' && $password !== $password_confirmation ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ($password_confirmation !== '' && $password === $password_confirmation ? 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20') }}">
                                    <button type="button" wire:click="$toggle('showConfirmPassword')"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#7a3f91] transition-colors">
                                        <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                                    </button>
                                </div>
                                @if ($password_confirmation !== '' && $password !== $password_confirmation)
                                    <p class="text-xs text-red-700 mt-1 flex items-center gap-1 font-medium">
                                        <i class="fa-solid fa-xmark"></i> Passwords do not match
                                    </p>
                                @elseif ($password_confirmation !== '' && $password === $password_confirmation && $password !== '')
                                    <p class="text-xs text-emerald-700 mt-1 flex items-center gap-1 font-medium">
                                        <i class="fa-solid fa-check"></i> Passwords match
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <button wire:click="sendOtp"
                            wire:loading.attr="disabled"
                            wire:target="sendOtp"
                            {{ !$this->canSendOtp() ? 'disabled' : '' }}
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-2.5 rounded-xl font-semibold text-sm shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="sendOtp">
                            <i class="fa-solid fa-paper-plane mr-1"></i> Send Verification Code & Continue
                        </span>
                        <span wire:loading wire:target="sendOtp">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending…
                        </span>
                    </button>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-600 py-1.5 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Email Setup
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 4 — OTP Verification ═══ --}}
            @if ($step == 4)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Verify Your Email</h2>
                        <p class="text-sm text-gray-600 mt-0.5">
                            Enter the 6-digit code sent to
                            <strong class="text-[#7a3f91]">
                                {{ session('alumni_pending_email')
                                    ?? cache()->get('alumni_pending_email:' . (\App\Models\Alumni::where('user_id', auth()->id())->value('id') ?? 0))
                                    ?? 'your email' }}
                            </strong>.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-5 items-start">

                        {{-- Left: timer --}}
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl p-5 text-center">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-widest mb-1.5">Code expires in</p>
                            <div class="text-5xl font-bold font-mono tabular-nums transition-colors duration-300"
                                 :class="seconds <= 60 ? 'text-red-600' : 'text-[#7a3f91]'"
                                 x-text="formattedTime">
                                10:00
                            </div>
                            <p x-show="expired" x-cloak class="text-red-700 text-xs mt-2 font-semibold">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Code expired. Request a new one.
                            </p>

                            @if ($otpLocked)
                                <p class="text-xs text-red-600 mt-2 font-semibold">
                                    <i class="fa-solid fa-lock mr-1"></i>
                                    Wait for timer to expire, then request a new code.
                                </p>
                            @else
                                <p class="text-xs text-gray-500 mt-2">
                                    <i class="fa-solid fa-shield-halved mr-1 text-[#7a3f91]"></i>
                                    Max 3 attempts before lockout
                                </p>
                            @endif
                        </div>

                        {{-- Right: input + buttons --}}
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-800 mb-1">6-Digit Code</label>
                                <input wire:model="otp"
                                       type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                                       autocomplete="one-time-code"
                                       {{ $otpLocked ? 'disabled' : '' }}
                                       class="w-full px-3 py-3 text-center text-3xl font-bold tracking-[0.4em] border-2 rounded-xl focus:outline-none focus:ring-2 transition-all bg-white text-gray-900
                                           {{ $otpLocked ? 'border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed' : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20' }}">
                            </div>

                            @if (!$otpLocked)
                                <button wire:click="verifyOtp"
                                        wire:loading.attr="disabled"
                                        wire:target="verifyOtp"
                                        x-bind:disabled="expired"
                                        :class="expired ? 'bg-gray-400 cursor-not-allowed' : 'bg-[#7a3f91] hover:bg-[#6a3080] hover:shadow-lg active:scale-[0.98]'"
                                        class="w-full text-white py-2.5 rounded-xl font-semibold text-sm shadow-md disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                                    <span wire:loading.remove wire:target="verifyOtp">
                                        <i class="fa-solid fa-circle-check mr-1"></i> Verify & Activate Account
                                    </span>
                                    <span wire:loading wire:target="verifyOtp">
                                        <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                                    </span>
                                </button>
                            @else
                                <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-center">
                                    <i class="fa-solid fa-lock text-red-500 text-lg mb-1"></i>
                                    <p class="text-sm font-semibold text-red-700">Verification locked</p>
                                    <p class="text-xs text-red-600 mt-0.5">Wait for the timer to expire, then request a new code.</p>
                                </div>
                            @endif

                            <button wire:click="resendOtp"
                                    wire:loading.attr="disabled"
                                    wire:target="resendOtp"
                                    x-bind:disabled="!canResend"
                                    type="button"
                                    :class="{
                                        'bg-gray-100 text-gray-400 border-gray-300 cursor-not-allowed': !canResend,
                                        'bg-white text-[#7a3f91] border-[#7a3f91] hover:bg-purple-50 active:scale-[0.98] cursor-pointer': canResend
                                    }"
                                    class="w-full py-2.5 rounded-xl font-semibold text-sm border-2 transition-all flex items-center justify-center gap-2">
                                <span wire:loading.remove wire:target="resendOtp">
                                    <i class="fa-solid fa-rotate-right mr-1.5"></i>
                                    <span x-show="!canResend" class="text-sm font-medium">
                                        Resend available in <span class="font-bold" x-text="formattedTime"></span>
                                    </span>
                                    <span x-show="canResend" class="text-sm font-medium">
                                        @if ($otpLocked) Request New Code @else Resend Code @endif
                                    </span>
                                </span>
                                <span wire:loading wire:target="resendOtp">
                                    <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Sending…
                                </span>
                            </button>

                            <p x-show="!canResend" x-cloak class="text-xs text-gray-500 text-center">
                                @if ($otpLocked)
                                    Wait for the timer to reach 0:00 before requesting a new code.
                                @else
                                    Wait for the timer before requesting a new code.
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Back button — locked while OTP is active --}}
                    <div x-data>
                        <button
                            wire:click="previousStep"
                            type="button"
                            x-bind:disabled="!canResend"
                            x-bind:title="!canResend ? 'You cannot go back while a verification code is active. Wait for the timer to expire.' : ''"
                            :class="canResend
                                ? 'text-gray-600 hover:text-[#7a3f91] hover:bg-gray-50 cursor-pointer'
                                : 'text-gray-300 cursor-not-allowed'"
                            class="w-full text-sm py-1.5 text-center rounded-lg transition-colors font-medium flex items-center justify-center gap-2">
                            <i class="fa-solid fa-arrow-left text-xs"></i>
                            <span x-show="canResend">Back to Password Setup</span>
                            <span x-show="!canResend" x-cloak class="flex items-center gap-1.5">
                                <i class="fa-solid fa-lock text-xs text-gray-300"></i>
                                Back locked — wait for timer to expire
                            </span>
                        </button>

                        <p x-show="!canResend" x-cloak
                           class="text-[0.7rem] text-center text-amber-600 mt-1 font-medium">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                            A verification code is active. You can go back only after it expires.
                        </p>
                    </div>

                </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="px-6 py-3 text-center bg-gray-50 border-t border-gray-200">
            <p class="text-xs text-gray-500">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('alumniOtpTimer', () => {
        const STORAGE_KEY = 'alumni_otp_timer_expiry';
        const DURATION_MS = 10 * 60 * 1000;

        return {
            seconds:   600,
            expired:   false,
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
    });
</script>
@endscript