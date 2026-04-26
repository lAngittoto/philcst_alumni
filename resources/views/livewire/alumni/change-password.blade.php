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

    public int    $step = 1;

    // ── Email Display (Step 1) ────────────────────────────────────
    public bool   $hasExistingEmail = false;
    public string $maskedEmail      = '';

    // ── OTP (Step 2) ─────────────────────────────────────────────
    public string $otp       = '';
    public bool   $otpSent   = false;
    public bool   $otpLocked = false;

    // ── Password (Step 3) ────────────────────────────────────────
    public string $password              = '';
    public string $password_confirmation = '';
    public string $passwordStrength      = 'weak';
    public bool   $showPassword          = false;
    public bool   $showConfirmPassword   = false;

    // ── UI ────────────────────────────────────────────────────────
    public string $errorMessage     = '';
    public string $successMessage   = '';
    public bool   $showSuccessModal = false;

    // ─────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '***@***';
        [$local, $domain] = explode('@', $email, 2);
        $len    = strlen($local);
        $masked = $len <= 2
            ? str_repeat('*', $len)
            : substr($local, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($local, -1);
        return $masked . '@' . $domain;
    }

    private function cacheEmailKey(int $id): string    { return "alumni_pending_email:{$id}"; }
    private function cachePasswordKey(int $id): string { return "alumni_pending_password:{$id}"; }
    private function cacheFlowKey(int $id): string     { return "alumni_wizard_flow:{$id}"; }

    private function clearWizardCache(int $id): void
    {
        cache()->forget($this->cacheEmailKey($id));
        cache()->forget($this->cachePasswordKey($id));
        cache()->forget($this->cacheFlowKey($id));
    }

    private function getAlumni(): ?\App\Models\Alumni
    {
        return \App\Models\Alumni::where('user_id', auth()->id())->first();
    }

    private function resolveExistingEmail(\App\Models\Alumni $alumni): ?string
    {
        $e = trim($alumni->email ?? '');
        return ($e !== '' && !str_ends_with($e, '@pending.local')) ? $e : null;
    }

    // ─────────────────────────────────────────────────────────────
    // Mount
    // ─────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login')); return;
        }

        $alumni = $this->getAlumni();
        if (!$alumni) {
            $this->redirect(route('login')); return;
        }

        $hasLoginFlag  = session()->has('alumni_requires_password_change');
        $wizardStarted = session()->has('alumni_password_reset_step');

        if (!$hasLoginFlag && !$wizardStarted) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->redirect(route('login')); return;
        }

        $stillOnTemp = $alumni->hasTemporaryPassword();
        $wizardInc   = $alumni->needsAccountSetup();

        if (!$wizardInc && !$stillOnTemp) {
            session()->forget(['alumni_requires_password_change', 'alumni_pending_email',
                'alumni_pending_password', 'alumni_password_reset_step', 'alumni_wizard_flow']);
            $this->clearWizardCache($alumni->id);
            $this->redirect(route('alumni.information')); return;
        }

        if (!$wizardInc && $stillOnTemp) {
            DB::table('alumni')->where('id', $alumni->id)->update(['password_changed_at' => null]);
            $alumni->password_changed_at = null;
            Log::info("change-password mount: self-healed password_changed_at for alumni #{$alumni->id}");
        }

        session()->put('alumni_requires_password_change', true);

        // ── Email state ───────────────────────────────────────────
        $existingEmail          = $this->resolveExistingEmail($alumni);
        $this->hasExistingEmail = $existingEmail !== null;
        if ($this->hasExistingEmail) {
            $this->maskedEmail = $this->maskEmail($existingEmail);
        }

        // ── DB-FIRST: active OTP → restore OTP step ──────────────
        // FIX: Fall back to resolving email directly from alumni so
        //      re-login after "Back to Home" still restores step 2
        //      as long as the OTP hasn't expired in the DB.
        if ($alumni->isOtpStillActive()) {
            $cachedEmail = cache()->get($this->cacheEmailKey($alumni->id))
                           ?? $existingEmail; // ← fallback to alumni record

            if ($cachedEmail) {
                // Repopulate cache so it survives future page loads
                cache()->put($this->cacheEmailKey($alumni->id), $cachedEmail, now()->addMinutes(15));

                session()->put('alumni_pending_email',       $cachedEmail);
                session()->put('alumni_password_reset_step', 'otp_sent');
                $this->step      = 2;
                $this->otpSent   = true;
                $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);
                $this->dispatch('otp-sent');
                return;
            }

            // No usable email at all — clear the stale OTP
            $alumni->clearOtp();
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);
        }

        // ── Session restore ───────────────────────────────────────
        $resetStep    = session('alumni_password_reset_step');
        $pendingEmail = session('alumni_pending_email');

        if ($resetStep === 'otp_verified') {
            $this->step = 3;
        } elseif ($resetStep === 'otp_sent' && $pendingEmail) {
            $this->step      = 2;
            $this->otpSent   = true;
            $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);
            $this->dispatch('otp-sent');
        } else {
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_password_reset_step', 'alumni_wizard_flow']);
            $this->clearWizardCache($alumni->id);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Back to Home — LOG OUT
    // FIX: Do NOT wipe the OTP email cache when an OTP is still
    //      active so that re-login correctly restores step 2.
    // ─────────────────────────────────────────────────────────────

    public function backToHome(): void
    {
        $alumni = $this->getAlumni();
        if ($alumni) {
            if (!$alumni->isOtpStillActive()) {
                // OTP already expired/unused — safe to wipe everything
                $this->clearWizardCache($alumni->id);
            }
            // If OTP is still active we intentionally leave the cache
            // intact so mount() can restore step 2 on the next login.
        }
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/');
    }

    // ─────────────────────────────────────────────────────────────
    // Step 1 — Send OTP
    // ─────────────────────────────────────────────────────────────

    public function sendOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $alumni        = $this->getAlumni();
        $existingEmail = $alumni ? $this->resolveExistingEmail($alumni) : null;

        if (!$alumni || !$existingEmail) {
            $this->errorMessage = 'No valid email found on your account. Please contact the registrar.';
            return;
        }

        try {
            $otp = $alumni->generateOtp();

            try {
                Mail::to($existingEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Alumni OTP sent to: {$existingEmail}");
            } catch (\Exception $e) {
                Log::warning("Alumni OTP mail failed: " . $e->getMessage());
            }

            session()->put('alumni_pending_email',       $existingEmail);
            session()->put('alumni_password_reset_step', 'otp_sent');
            cache()->put($this->cacheEmailKey($alumni->id), $existingEmail, now()->addMinutes(15));
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = false;
            $this->successMessage = "Verification code sent to {$this->maskedEmail}. Please check your inbox.";
            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send verification code. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Step 2 — Verify OTP
    // ─────────────────────────────────────────────────────────────

    public function verifyOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $trimmed = trim($this->otp);

        if ($trimmed === '' || strlen($trimmed) < 6) {
            $this->errorMessage = 'Please enter the complete 6-digit verification code.'; return;
        }
        if (!preg_match('/^\d{6}$/', $trimmed)) {
            $this->errorMessage = 'The code must contain digits only.'; return;
        }

        $alumni = $this->getAlumni();
        if (!$alumni) { $this->errorMessage = 'Alumni record not found.'; return; }

        $lockKey     = 'alumni_otp_locked:'   . $alumni->id;
        $attemptsKey = 'alumni_otp_attempts:' . $alumni->id;
        $attempts    = cache()->get($attemptsKey, 0);

        if (cache()->has($lockKey)) {
            $this->otpLocked = true;
            $this->errorMessage = 'Too many failed attempts. Wait for the timer to expire, then request a new code.';
            return;
        }

        if ($attempts >= 3) {
            cache()->put($lockKey, true, 700);
            $this->otpLocked    = true;
            $this->errorMessage = 'Too many failed attempts. Wait for the timer to expire.';
            return;
        }

        if (!$alumni->isOtpValid($trimmed)) {
            $new = $attempts + 1;
            cache()->put($attemptsKey, $new, 700);
            $rem = 3 - $new;
            if ($rem <= 0) {
                cache()->put($lockKey, true, 700);
                $this->otpLocked    = true;
                $this->errorMessage = 'Too many failed attempts. Wait for the timer to expire, then request a new code.';
            } else {
                $this->errorMessage = "Invalid or expired code. You have {$rem} attempt(s) remaining.";
            }
            return;
        }

        cache()->forget($attemptsKey);
        cache()->forget($lockKey);
        $alumni->clearOtp();

        session()->put('alumni_password_reset_step', 'otp_verified');
        $this->step           = 3;
        $this->successMessage = 'Email verified! Please set your new password below.';
    }

    // ─────────────────────────────────────────────────────────────
    // OTP — Resend
    // ─────────────────────────────────────────────────────────────

    public function resendOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $alumni = $this->getAlumni();
        if (!$alumni) { $this->errorMessage = 'Session expired.'; $this->step = 1; return; }

        $targetEmail  = $this->resolveExistingEmail($alumni);
        $displayEmail = $this->maskedEmail;

        if (!$targetEmail) {
            $this->errorMessage = 'Session expired. Please start over.'; $this->step = 1; return;
        }

        try {
            $otp = $alumni->generateOtp();

            try {
                Mail::to($targetEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Alumni OTP resent to: {$targetEmail}");
            } catch (\Exception $e) {
                Log::warning("Alumni resend OTP mail failed: " . $e->getMessage());
            }

            cache()->put($this->cacheEmailKey($alumni->id), $targetEmail, now()->addMinutes(15));
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);
            session()->put('alumni_password_reset_step', 'otp_sent');

            $this->otp            = '';
            $this->otpLocked      = false;
            $this->successMessage = "New code sent to {$displayEmail}. You have 3 attempts.";
            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("resendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to resend code. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Password Helpers
    // ─────────────────────────────────────────────────────────────

    public function updatedPassword(): void { $this->updatePasswordStrength(); }

    public function updatePasswordStrength(): void
    {
        $pwd = $this->password;
        if (strlen($pwd) < 8) { $this->passwordStrength = 'weak'; return; }
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
            'fair'   => ['label' => 'Fair',   'color' => 'text-orange-500', 'progressColor' => 'bg-orange-500', 'width' => 'w-2/4'],
            'good'   => ['label' => 'Good',   'color' => 'text-amber-600',  'progressColor' => 'bg-amber-500',  'width' => 'w-3/4'],
            'strong' => ['label' => 'Strong', 'color' => 'text-emerald-600','progressColor' => 'bg-emerald-500','width' => 'w-full'],
        };
    }

    public function isPasswordStrengthValid(): bool { return in_array($this->passwordStrength, ['good', 'strong']); }

    public function isPasswordsMatching(): bool
    {
        return $this->password !== ''
            && $this->password_confirmation !== ''
            && $this->password === $this->password_confirmation;
    }

    public function canSavePassword(): bool { return $this->isPasswordStrengthValid() && $this->isPasswordsMatching(); }

    // ─────────────────────────────────────────────────────────────
    // Step 3 — Save Password
    // ─────────────────────────────────────────────────────────────

    public function savePassword(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if (session('alumni_password_reset_step') !== 'otp_verified') {
            $this->errorMessage = 'Please complete OTP verification first.';
            $this->step = 2;
            return;
        }

        if (!$this->isPasswordStrengthValid()) {
            $this->errorMessage = 'Password must be "Good" or "Strong" strength. Add uppercase letters, numbers, and special characters.';
            return;
        }

        $this->validate([
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.min'               => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        $user   = auth()->user();
        $alumni = $this->getAlumni();
        if (!$alumni) { $this->errorMessage = 'Alumni record not found.'; return; }

        try {
            $now = now();

            DB::transaction(function () use ($user, $alumni, $now) {
                DB::table('users')->where('id', $user->id)->update([
                    'password'   => Hash::make($this->password),
                    'updated_at' => $now,
                ]);
                DB::table('alumni')->where('id', $alumni->id)->update([
                    'status'              => 'VERIFIED',
                    'password_changed_at' => $now,
                    'updated_at'          => $now,
                ]);
            });

            $alumni->refresh();

            if ($alumni->needsAccountSetup()) {
                Log::error("savePassword: needsAccountSetup() still true after update for alumni #{$alumni->id}.");
                $this->errorMessage = 'Activation failed due to a data error. Please contact support.';
                return;
            }

            session()->forget(['alumni_pending_email', 'alumni_pending_password',
                'alumni_password_reset_step', 'alumni_requires_password_change', 'alumni_wizard_flow']);
            $this->clearWizardCache($alumni->id);

            Log::info("Alumni account setup completed: alumni_id #{$alumni->id}");

            $this->showSuccessModal = true;
            $this->dispatch('account-activated');

        } catch (\Exception $e) {
            Log::error("savePassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to save password. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Navigation — Previous Step
    // ─────────────────────────────────────────────────────────────

    public function previousStep(): void
    {
        if ($this->step <= 1) return;
        $alumni = $this->getAlumni();

        if ($this->step === 2 && $alumni && $alumni->isOtpStillActive()) {
            $this->errorMessage = 'You cannot go back while a verification code is active. Wait for the timer to expire.';
            return;
        }

        if ($this->step === 2 && $alumni) {
            $alumni->clearOtp();
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);
            $this->clearWizardCache($alumni->id);
        }

        if ($this->step === 3) {
            $this->dispatch('otp-reset');
            session()->put('alumni_password_reset_step', 'otp_sent');
        }

        $this->step--;
        $this->errorMessage = $this->successMessage = '';
        $this->otp = ''; $this->otpLocked = false;

        if ($this->step === 1) {
            session()->forget(['alumni_pending_email', 'alumni_password_reset_step', 'alumni_wizard_flow']);
            if ($alumni) $this->clearWizardCache($alumni->id);
            $this->password = $this->password_confirmation = '';
            $this->passwordStrength = 'weak';
        }
    }

    public function goToDashboard(): void
    {
        $alumni = $this->getAlumni();
        if ($alumni && !$alumni->needsAccountSetup()) {
            $this->redirect(route('alumni.information'));
        } else {
            Log::warning("goToDashboard: needsAccountSetup() still true for user #" . auth()->id());
            $this->errorMessage = 'Account activation still pending. Please refresh and try again.';
        }
    }
}; ?>

<div
    class="min-h-screen w-full flex flex-col items-center justify-center p-4 font-sans"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
    x-data="alumniOtpTimer()"
    x-init="initTimer({{ $step }})"
    x-on:otp-sent-fresh.window="startFresh()"
    x-on:otp-sent.window="restoreOrStart()"
    x-on:otp-reset.window="resetTimer()"
>
    {{-- Dark overlay for readability --}}
    <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>

    {{-- Back to Home --}}
    <div class="relative z-10 w-full max-w-2xl mb-4">
        <button
            wire:click="backToHome"
            wire:loading.attr="disabled"
            wire:target="backToHome"
            class="inline-flex items-center gap-2 text-white hover:text-white/80 transition-colors font-semibold text-xl group"
        >
            <div class="w-8 h-8 flex items-center justify-center rounded-lg border border-white/30 bg-white/10 group-hover:border-white/60 group-hover:bg-white/20 transition-all shadow-sm">
                <span wire:loading.remove wire:target="backToHome"><i class="fa-solid fa-arrow-left text-sm"></i></span>
                <span wire:loading wire:target="backToHome"><i class="fa-solid fa-circle-notch fa-spin text-sm"></i></span>
            </div>
            <span>Back to Home</span>
        </button>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         SUCCESS MODAL
    ═══════════════════════════════════════════════════════════════ --}}
    @if ($showSuccessModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
             x-data="{ visible: false }" x-init="$nextTick(() => { visible = true })">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"></div>

            <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden text-center"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-400"
                 x-transition:enter-start="opacity-0 scale-90 translate-y-6"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0">

                {{-- Top accent bar --}}
                <div class="h-1.5 w-full" style="background: linear-gradient(90deg, #7A3F91, #059669, #7A3F91);"></div>

                <div class="px-8 pt-8 pb-7 space-y-5">
                    {{-- Animated checkmark --}}
                    <div class="flex justify-center">
                        <div class="relative w-20 h-20">
                            <div class="absolute inset-0 rounded-full bg-emerald-100 animate-ping opacity-40"></div>
                            <div class="absolute inset-2 rounded-full bg-emerald-100 animate-ping opacity-30" style="animation-delay:.2s"></div>
                            <div class="relative w-20 h-20 rounded-full flex items-center justify-center shadow-lg"
                                 style="background: linear-gradient(135deg, #34d399, #059669);">
                                <i class="fa-solid fa-check text-white text-3xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <h2 class="text-2xl font-extrabold text-[#333333]">🎉 Account Activated!</h2>
                        <p class="text-xl font-semibold text-[#333333]">Your alumni account has been verified.</p>
                        <p class="text-xl text-[#666666] leading-relaxed">Welcome to the PCST Alumni Portal! Please complete your profile to access all features.</p>
                    </div>

                    <div class="rounded-2xl p-4 flex items-center gap-4 border"
                         style="background: linear-gradient(135deg, rgba(122,63,145,0.07), rgba(122,63,145,0.03)); border-color: rgba(122,63,145,0.2);">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
                             style="background: #7A3F91;">
                            <i class="fa-solid fa-graduation-cap text-white"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-bold uppercase tracking-wider" style="color: #7A3F91;">Account Status</p>
                            <p class="text-xl font-bold text-[#333333]">Verified Alumni ✓</p>
                            <p class="text-xl text-[#666666]">Philippine College of Science and Technology</p>
                        </div>
                    </div>

                    <button wire:click="goToDashboard" wire:loading.attr="disabled" wire:target="goToDashboard"
                            class="w-full text-white py-3.5 rounded-xl font-bold text-xl shadow-lg hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
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

    {{-- ══════════════════════════════════════════════════════════════
         MAIN CARD
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="relative z-10 w-full max-w-2xl bg-white rounded-2xl shadow-2xl border overflow-hidden" style="border-color: #E8E0F0;">

        {{-- ── Card Header ─────────────────────────────────────────── --}}
        <div class="px-8 pt-7 pb-6 border-b" style="border-color: #E8E0F0;">

            {{-- Logo + Title --}}
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-md flex-shrink-0"
                     style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                    <i class="fa-solid fa-graduation-cap text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold leading-tight" style="color: #333333;">Alumni Account Setup</h1>
                    <p class="text-base" style="color: #666666;">Philippine College of Science and Technology</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            @php
                $steps = ['Send OTP', 'Verify OTP', 'Set Password'];
            @endphp
            <div class="flex items-center gap-0">
                @foreach ($steps as $idx => $label)
                    @php $pos = $idx + 1; $total = count($steps); @endphp
                    <div class="flex items-center {{ $pos < $total ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1 flex-shrink-0">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold border-2 transition-all
                                @if($pos == $step) text-white border-transparent @elseif($pos < $step) text-white border-transparent @else border-[#E0E0E0] text-[#999999] bg-white @endif"
                                style="{{ $pos == $step ? 'background:#7A3F91;' : ($pos < $step ? 'background:#059669;' : '') }}">
                                @if ($pos < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $pos }}
                                @endif
                            </div>
                            <span class="text-sm font-semibold whitespace-nowrap
                                @if($pos == $step) text-[#7A3F91] @elseif($pos < $step) text-emerald-600 @else text-[#999999] @endif">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($pos < $total)
                            <div class="flex-1 h-0.5 mx-3 mb-5 rounded-full transition-all
                                {{ $pos < $step ? 'bg-emerald-400' : 'bg-[#E0E0E0]' }}">
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Card Body ───────────────────────────────────────────── --}}
        <div class="px-8 py-7">

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="mb-5 px-4 py-3.5 rounded-xl border flex items-start gap-3"
                     style="background:#FEF2F2; border-color:#FECACA;">
                    <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5 flex-shrink-0 text-xl"></i>
                    <p class="text-base font-medium text-red-800 leading-snug">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-5 px-4 py-3.5 rounded-xl border flex items-start gap-3"
                     style="background:#ECFDF5; border-color:#A7F3D0;">
                    <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-xl"></i>
                    <p class="text-base font-medium text-emerald-800 leading-snug">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- ══════════════════════════════════════════════════════
                 STEP 1 — Send OTP
            ════════════════════════════════════════════════════════ --}}
            @if ($step == 1)
                <div class="space-y-6">
                    <div>
                        <h2 class="text-2xl font-bold" style="color: #333333;">Welcome Back!</h2>
                        <p class="text-base mt-1" style="color: #666666;">
                            To activate your account, we'll send a verification code to your registered email address.
                        </p>
                    </div>

                    @if ($hasExistingEmail)
                        {{-- Email card --}}
                        <div class="rounded-xl border-2 p-5" style="border-color: #E8E0F0; background: #FAFAFA;">
                            <div class="flex items-center gap-4">
                                <div class="w-14 h-14 rounded-xl flex items-center justify-center flex-shrink-0"
                                     style="background: rgba(122,63,145,0.1);">
                                    <i class="fa-solid fa-envelope text-2xl" style="color: #7A3F91;"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-wide" style="color: #666666;">Registered Email Address</p>
                                    <p class="text-lg font-bold tracking-wide mt-0.5" style="color: #7A3F91;">{{ $maskedEmail }}</p>
                                    <p class="text-sm mt-0.5" style="color: #999999;">A 6-digit code will be sent here</p>
                                </div>
                            </div>
                        </div>

                        <button
                            wire:click="sendOtp"
                            wire:loading.attr="disabled"
                            wire:target="sendOtp"
                            class="w-full text-white py-4 rounded-xl font-bold text-base shadow-md hover:opacity-90 active:scale-[0.99] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                            <span wire:loading.remove wire:target="sendOtp">
                                <i class="fa-solid fa-paper-plane mr-2"></i>Send Verification Code
                            </span>
                            <span wire:loading wire:target="sendOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Sending…
                            </span>
                        </button>

                    @else
                        {{-- No email on file --}}
                        <div class="rounded-xl border-2 p-6 text-center space-y-3"
                             style="background: #FFFBEB; border-color: #FDE68A;">
                            <div class="w-14 h-14 rounded-full flex items-center justify-center mx-auto"
                                 style="background: #FEF3C7;">
                                <i class="fa-solid fa-envelope-circle-check text-amber-500 text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-amber-800">No Email Address Found</h3>
                                <p class="text-base text-amber-700 mt-1 leading-relaxed">
                                    We don't have an email address on file for your account. Please contact the registrar to add your email before proceeding.
                                </p>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ══════════════════════════════════════════════════════
                 STEP 2 — Verify OTP
            ════════════════════════════════════════════════════════ --}}
            @if ($step == 2)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold" style="color: #333333;">Enter Verification Code</h2>
                        <p class="text-base mt-1" style="color: #666666;">
                            A 6-digit code was sent to
                            <strong style="color: #7A3F91;">{{ $maskedEmail }}</strong>.
                            Check your inbox.
                        </p>
                    </div>

                    {{-- Timer + OTP Input stacked --}}
                    <div class="space-y-4">

                        {{-- Timer --}}
                        <div class="rounded-xl border p-5 text-center" style="background: #F8F4FC; border-color: #E8E0F0;">
                            <p class="text-sm font-semibold uppercase tracking-widest mb-1" style="color: #666666;">
                                Code expires in
                            </p>
                            <div class="text-5xl font-bold font-mono tabular-nums transition-colors duration-300"
                                 :class="seconds <= 60 ? 'text-red-600' : ''"
                                 :style="seconds > 60 ? 'color: #7A3F91;' : ''"
                                 x-text="formattedTime">10:00</div>
                            <p x-show="expired" x-cloak class="text-red-600 text-sm mt-2 font-semibold">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>Code expired. Request a new one below.
                            </p>
                            @if (!$otpLocked)
                                <p class="text-sm mt-2" style="color: #999999;">
                                    <i class="fa-solid fa-shield-halved mr-1" style="color: #7A3F91;"></i>Maximum 3 attempts before lockout
                                </p>
                            @endif
                        </div>

                        {{-- OTP Field --}}
                        <div>
                            <label class="block text-base font-semibold mb-2" style="color: #333333;">6-Digit Code</label>
                            <input wire:model="otp"
                                   type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                                   autocomplete="one-time-code"
                                   {{ $otpLocked ? 'disabled' : '' }}
                                   placeholder="——————"
                                   class="w-full px-4 py-4 text-center text-4xl font-bold tracking-[0.5em] border-2 rounded-xl focus:outline-none focus:ring-2 transition-all"
                                   style="{{ $otpLocked
                                       ? 'background:#F5F5F5; border-color:#E0E0E0; color:#999999; cursor:not-allowed;'
                                       : 'background:#FFFFFF; border-color:#E8E0F0; color:#333333;' }}"
                                   onfocus="if(!this.disabled)this.style.borderColor='#7A3F91'"
                                   onblur="if(!this.disabled)this.style.borderColor='#E8E0F0'">
                        </div>

                        {{-- Locked notice --}}
                        @if ($otpLocked)
                            <div class="rounded-xl border px-4 py-3 flex items-center gap-3"
                                 style="background:#FEF2F2; border-color:#FECACA;">
                                <i class="fa-solid fa-lock text-red-500 text-xl flex-shrink-0"></i>
                                <div>
                                    <p class="text-base font-bold text-red-700">Verification Locked</p>
                                    <p class="text-sm text-red-600">Wait for the timer to expire, then request a new code.</p>
                                </div>
                            </div>
                        @endif

                        {{-- Verify button --}}
                        @if (!$otpLocked)
                            <button wire:click="verifyOtp" wire:loading.attr="disabled" wire:target="verifyOtp"
                                    x-bind:disabled="expired"
                                    :class="expired ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-90 active:scale-[0.99]'"
                                    class="w-full text-white py-4 rounded-xl font-bold text-base shadow-md transition-all flex items-center justify-center gap-2"
                                    style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                                <span wire:loading.remove wire:target="verifyOtp">
                                    <i class="fa-solid fa-circle-check mr-2"></i>Verify Code & Continue
                                </span>
                                <span wire:loading wire:target="verifyOtp">
                                    <i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Verifying…
                                </span>
                            </button>
                        @endif

                        {{-- Resend --}}
                        <button wire:click="resendOtp" wire:loading.attr="disabled" wire:target="resendOtp"
                                x-bind:disabled="!canResend" type="button"
                                :class="canResend ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
                                class="w-full py-3.5 rounded-xl font-semibold text-base border-2 transition-all flex items-center justify-center gap-2"
                                :style="canResend
                                    ? 'background:#FFFFFF; border-color:#7A3F91; color:#7A3F91;'
                                    : 'background:#F5F5F5; border-color:#E0E0E0; color:#999999;'">
                            <span wire:loading.remove wire:target="resendOtp">
                                <i class="fa-solid fa-rotate-right mr-1.5"></i>
                                <span x-show="!canResend">
                                    Resend in <span class="font-bold" x-text="formattedTime"></span>
                                </span>
                                <span x-show="canResend">
                                    @if ($otpLocked) Request New Code @else Resend Code @endif
                                </span>
                            </span>
                            <span wire:loading wire:target="resendOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i>Sending…
                            </span>
                        </button>

                        {{-- Back --}}
                        <div x-data>
                            <button wire:click="previousStep" type="button"
                                    x-bind:disabled="!canResend"
                                    :class="canResend ? 'cursor-pointer hover:text-[#7A3F91] hover:bg-[#F5F5F5]' : 'cursor-not-allowed opacity-40'"
                                    class="w-full py-2.5 rounded-lg text-base font-medium transition-colors flex items-center justify-center gap-2"
                                    style="color: #666666;">
                                <i class="fa-solid fa-arrow-left text-sm"></i>
                                <span x-show="canResend">Back to Step 1</span>
                                <span x-show="!canResend" x-cloak class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-lock text-sm"></i> Back locked — wait for timer to expire
                                </span>
                            </button>
                            <p x-show="!canResend" x-cloak
                               class="text-sm text-center text-amber-600 mt-1 font-medium">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                A verification code is active. You can go back only after it expires.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ══════════════════════════════════════════════════════
                 STEP 3 — Set Password
            ════════════════════════════════════════════════════════ --}}
            @if ($step == 3)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold" style="color: #333333;">Set Your New Password</h2>
                        <p class="text-base mt-1" style="color: #666666;">Create a strong password to secure your alumni account.</p>
                    </div>

                    {{-- Password Requirements --}}
                    <div class="rounded-xl border p-5 space-y-2.5" style="background: #FAFAFA; border-color: #E8E0F0;">
                        <p class="text-sm font-bold uppercase tracking-wide mb-3" style="color: #666666;">Password Requirements</p>
                        @foreach ([
                            [strlen($password) >= 8,                 '8 or more characters'],
                            [preg_match('/[A-Z]/', $password),       'One uppercase letter (A–Z)'],
                            [preg_match('/[a-z]/', $password),       'One lowercase letter (a–z)'],
                            [preg_match('/[0-9]/', $password),       'One number (0–9)'],
                            [preg_match('/[!@#$%^&*?]/', $password), 'One special character (!@#$%^&*)'],
                        ] as [$met, $text])
                            <div class="flex items-center gap-2.5">
                                <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold
                                    {{ $met ? 'bg-emerald-100 text-emerald-700' : 'bg-[#F0F0F0] text-[#999999]' }}">
                                    {{ $met ? '✓' : '○' }}
                                </span>
                                <span class="text-base {{ $met ? 'text-emerald-700 font-semibold' : '' }}"
                                      style="{{ !$met ? 'color:#666666;' : '' }}">{{ $text }}</span>
                            </div>
                        @endforeach
                        <p class="text-sm mt-2 pt-2 border-t" style="border-color: #E8E0F0; color: #999999;">
                            <i class="fa-solid fa-circle-info mr-1 text-blue-400"></i>
                            "Good" or "Strong" strength required to proceed.
                        </p>
                    </div>

                    {{-- New Password --}}
                    <div>
                        <label class="block text-base font-semibold mb-2" style="color: #333333;">New Password</label>
                        <div class="relative">
                            <input wire:model.live.debounce.200ms="password"
                                   type="{{ $showPassword ? 'text' : 'password' }}"
                                   autocomplete="new-password"
                                   class="w-full px-4 py-3.5 text-base border-2 rounded-xl focus:outline-none transition-all pr-12"
                                   style="border-color: #E8E0F0; color: #333333; background: #FFFFFF;"
                                   onfocus="this.style.borderColor='#7A3F91'"
                                   onblur="this.style.borderColor='#E8E0F0'">
                            <button type="button" wire:click="$toggle('showPassword')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 transition-colors hover:opacity-80"
                                    style="color: #999999;">
                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-lg"></i>
                            </button>
                        </div>

                        {{-- Strength bar --}}
                        @if ($password !== '')
                            <div class="mt-2 space-y-1">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-semibold" style="color: #666666;">Strength</span>
                                    <span class="text-sm font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">
                                        {{ $this->getPasswordStrengthInfo()['label'] }}
                                    </span>
                                </div>
                                <div class="h-2 rounded-full overflow-hidden" style="background: #E8E0F0;">
                                    <div class="h-full rounded-full transition-all duration-300
                                        {{ $this->getPasswordStrengthInfo()['progressColor'] }}
                                        {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Confirm Password --}}
                    <div>
                        <label class="block text-base font-semibold mb-2" style="color: #333333;">Confirm Password</label>
                        <div class="relative">
                            <input wire:model.live.debounce.200ms="password_confirmation"
                                   type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                   autocomplete="new-password"
                                   class="w-full px-4 py-3.5 text-base border-2 rounded-xl focus:outline-none transition-all pr-12"
                                   style="border-color: {{ $password_confirmation !== '' && $password !== $password_confirmation ? '#FCA5A5' : ($password_confirmation !== '' && $password === $password_confirmation ? '#6EE7B7' : '#E8E0F0') }}; color: #333333; background: #FFFFFF;"
                                   onfocus="this.style.borderColor='#7A3F91'"
                                   onblur="this.style.borderColor='{{ $password_confirmation !== '' && $password !== $password_confirmation ? '#FCA5A5' : ($password_confirmation !== '' && $password === $password_confirmation ? '#6EE7B7' : '#E8E0F0') }}'">
                            <button type="button" wire:click="$toggle('showConfirmPassword')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 transition-colors hover:opacity-80"
                                    style="color: #999999;">
                                <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-lg"></i>
                            </button>
                        </div>
                        @if ($password_confirmation !== '' && $password !== $password_confirmation)
                            <p class="text-sm text-red-600 mt-1.5 flex items-center gap-1 font-medium">
                                <i class="fa-solid fa-xmark"></i> Passwords do not match
                            </p>
                        @elseif ($password_confirmation !== '' && $password === $password_confirmation && $password !== '')
                            <p class="text-sm text-emerald-600 mt-1.5 flex items-center gap-1 font-medium">
                                <i class="fa-solid fa-check"></i> Passwords match
                            </p>
                        @endif
                    </div>

                    {{-- Save Button --}}
                    <button wire:click="savePassword" wire:loading.attr="disabled" wire:target="savePassword"
                            {{ !$this->canSavePassword() ? 'disabled' : '' }}
                            class="w-full text-white py-4 rounded-xl font-bold text-base shadow-md transition-all flex items-center justify-center gap-2
                                {{ !$this->canSavePassword() ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-90 active:scale-[0.99]' }}"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="savePassword">
                            @if (!$this->canSavePassword())
                                <i class="fa-solid fa-lock mr-2"></i>Enter a strong matching password to continue
                            @else
                                <i class="fa-solid fa-key mr-2"></i>Set Password & Activate Account
                            @endif
                        </span>
                        <span wire:loading wire:target="savePassword">
                            <i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Saving…
                        </span>
                    </button>

                    {{-- Back --}}
                    <button wire:click="previousStep" type="button"
                            class="w-full py-2.5 rounded-lg text-base font-medium transition-colors hover:bg-[#F5F5F5] flex items-center justify-center gap-2"
                            style="color: #666666;">
                        <i class="fa-solid fa-arrow-left text-sm"></i> Back to OTP Verification
                    </button>
                </div>
            @endif

        </div>

        {{-- ── Card Footer ─────────────────────────────────────────── --}}
        <div class="px-8 py-4 text-center border-t" style="background: #FAFAFA; border-color: #E8E0F0;">
            <p class="text-sm" style="color: #999999;">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
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
                if (step === 2) this.restoreOrStart();
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

            resetTimer() {
                this._clearInterval();
                this.seconds   = 0;
                this.expired   = true;
                this.canResend = true;
                localStorage.setItem(STORAGE_KEY, '0');
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