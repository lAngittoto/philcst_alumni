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

    // ── OTP countdown source of truth ────────────────────────────
    // Epoch milliseconds when the current OTP expires. This ALWAYS comes
    // straight from the alumni.otp_expires_at DB column (never guessed on
    // the front-end, never stored in localStorage), and is entangled with
    // the Alpine timer component so the visible countdown can never drift
    // from the real deadline — whether the page is freshly loaded,
    // restored via browser back/forward, or a brand-new code was just
    // requested. Mirrors the same fix applied to forgot-password.blade.php.
    public int $otpExpiresAtMs = 0;

    // ── "Request New Code" lockout (Step 1 + Step 2 resend) ───────
    // After MAX_RESEND_ATTEMPTS (3) sends within the current window — the
    // very first "Send Verification Code" from Step 1 AND every "Resend
    // Code" from Step 2 all count toward the same limit — the account is
    // locked for RESEND_LOCK_MINUTES (30 mins). Unlike forgot-password.blade.php
    // (which has to track this via a session key because the alumni isn't
    // known until Step 1 is submitted), here the alumni is always resolvable
    // straight from auth()->id(), so the lock state can just be synced
    // directly off the DB/cache any time — including on mount(), so Step 1's
    // send button reflects a real, still-active lock immediately.
    public bool $sendLocked            = false;
    public int  $sendLockedUntilMs     = 0;
    public bool $showResendLockedModal = false;

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

    private const MAX_RESEND_ATTEMPTS = 3;
    private const RESEND_LOCK_MINUTES = 30;

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

    private function cacheEmailKey(int $id): string           { return "alumni_pending_email:{$id}"; }
    private function cachePasswordKey(int $id): string        { return "alumni_pending_password:{$id}"; }
    private function cacheFlowKey(int $id): string            { return "alumni_wizard_flow:{$id}"; }
    private function cacheResendAttemptsKey(int $id): string  { return "alumni_resend_attempts:{$id}"; }
    private function cacheResendLockKey(int $id): string      { return "alumni_resend_locked:{$id}"; }

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

    /**
     * Sync the front-end countdown to the REAL otp_expires_at column for
     * this alumni. Called any time we (re)enter step 2 — fresh send,
     * resend, or restoring after browser back/forward/refresh — so the
     * visible timer is always derived from the database, never guessed
     * and never bound to localStorage (which is what caused the timer to
     * desync or reset incorrectly on browser back/forward before).
     */
    private function syncOtpExpiry(\App\Models\Alumni $alumni): void
    {
        $this->otpExpiresAtMs = $alumni->otp_expires_at
            ? $alumni->otp_expires_at->getTimestamp() * 1000
            : 0;
    }

    /**
     * Sync the "Request New Code" lockout state from cache. The cache
     * value under cacheResendLockKey() stores the ISO8601 expiry timestamp
     * itself (not just `true`), so the exact remaining time can be shown
     * instead of a static "30 minutes" message. Safe to call any time —
     * including on every mount() — since the alumni is always known here.
     */
    private function checkAndSyncSendLock(\App\Models\Alumni $alumni): void
    {
        $expiry = cache()->get($this->cacheResendLockKey($alumni->id));

        if ($expiry && \Carbon\Carbon::parse($expiry)->isFuture()) {
            $this->sendLocked        = true;
            $this->sendLockedUntilMs = \Carbon\Carbon::parse($expiry)->getTimestamp() * 1000;
        } else {
            $this->sendLocked        = false;
            $this->sendLockedUntilMs = 0;
        }
    }

    /**
     * Marks the given alumni as locked out of sending any new code (fresh
     * OR resend) for RESEND_LOCK_MINUTES, and immediately syncs the
     * front-end lock state so Step 1's button reflects it right away.
     */
    private function lockSending(\App\Models\Alumni $alumni): void
    {
        $expiry = now()->addMinutes(self::RESEND_LOCK_MINUTES);
        cache()->put($this->cacheResendLockKey($alumni->id), $expiry->toIso8601String(), $expiry);
        cache()->forget($this->cacheResendAttemptsKey($alumni->id));

        $this->checkAndSyncSendLock($alumni);
        $this->showResendLockedModal = true;
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

        // Sync the "Request New Code" lockout state unconditionally, right
        // after we know who the alumni is — independent of which step we
        // end up landing on below — so Step 1's send button is always
        // accurate the moment it's (re)rendered (page load, refresh, or
        // returning here after logging back in mid-lock).
        $this->checkAndSyncSendLock($alumni);

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
        if ($alumni->isOtpStillActive()) {
            $cachedEmail = cache()->get($this->cacheEmailKey($alumni->id))
                           ?? $existingEmail;

            if ($cachedEmail) {
                cache()->put($this->cacheEmailKey($alumni->id), $cachedEmail, now()->addMinutes(15));

                session()->put('alumni_pending_email',       $cachedEmail);
                session()->put('alumni_password_reset_step', 'otp_sent');
                $this->step      = 2;
                $this->otpSent   = true;
                $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);
                $this->syncOtpExpiry($alumni);
                return;
            }

            $alumni->clearOtp();
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);
        }

        // ── Session restore ───────────────────────────────────────
        // FIX: previously this branch restored Step 2 purely off the
        // session flag, even if the underlying OTP had already expired —
        // showing an OTP form with a code that was no longer valid. Now it
        // only restores Step 2 if the OTP is genuinely still active
        // (isOtpStillActive), same principle used in forgot-password.
        $resetStep    = session('alumni_password_reset_step');
        $pendingEmail = session('alumni_pending_email');

        if ($resetStep === 'otp_verified') {
            $this->step = 3;
        } elseif ($resetStep === 'otp_sent' && $pendingEmail && $alumni->isOtpStillActive()) {
            $this->step      = 2;
            $this->otpSent   = true;
            $this->otpLocked = cache()->has('alumni_otp_locked:' . $alumni->id);
            $this->syncOtpExpiry($alumni);
        } else {
            $this->step = 1;
            session()->forget(['alumni_pending_email', 'alumni_password_reset_step', 'alumni_wizard_flow']);
            $this->clearWizardCache($alumni->id);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Back to Home — LOG OUT
    // ─────────────────────────────────────────────────────────────

    public function backToHome(): void
    {
        $alumni = $this->getAlumni();
        if ($alumni) {
            if (!$alumni->isOtpStillActive()) {
                $this->clearWizardCache($alumni->id);
            }
        }
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/');
    }

    // ─────────────────────────────────────────────────────────────
    // Back to Login — LOG OUT (used by mid-wizard back buttons)
    //
    // Previously, going back within Steps 2/3 just decremented the
    // step (and blocked entirely while an OTP was still active,
    // surfacing a "Back locked" warning). That's been removed —
    // going back now always logs the alumni out and sends them to
    // the login screen (route('login')), matching the forgot-password
    // flow: they must re-authenticate before reaching the OTP step
    // again. This always redirects to the LOGIN page, never to Home —
    // "Back to Home" is a separate, distinct action (see backToHome()).
    // ─────────────────────────────────────────────────────────────

    public function backToLogin(): void
    {
        $alumni = $this->getAlumni();
        if ($alumni) {
            if (!$alumni->isOtpStillActive()) {
                $this->clearWizardCache($alumni->id);
            }
        }
        session()->forget(['alumni_pending_email', 'alumni_pending_password',
            'alumni_password_reset_step', 'alumni_requires_password_change', 'alumni_wizard_flow']);
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect(route('login'));
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

        // ── "Request New Code" lockout — checked FIRST, before sending
        //    anything. This mirrors the state already shown (disabled
        //    button + countdown) on Step 1, so it can't be bypassed by
        //    just clicking through anyway.
        $this->checkAndSyncSendLock($alumni);
        if ($this->sendLocked) {
            $this->showResendLockedModal = true;
            return;
        }

        $this->_sendOtp($alumni, $existingEmail);
    }

    /**
     * SINGLE choke point for every actual OTP send — both the initial send
     * from Step 1 (sendOtp) and every resend from Step 2 ("Resend Code" /
     * "Request New Code") pass through here. That means the 3-sends-then-
     * lock-30-minutes rule applies uniformly no matter which button
     * triggered the send — the very first send from Step 1 counts toward
     * the same limit as resends, so the lock can't be bypassed by starting
     * the wizard fresh.
     */
    private function _sendOtp(\App\Models\Alumni $alumni, string $targetEmail): void
    {
        $resendLockKey     = $this->cacheResendLockKey($alumni->id);
        $resendAttemptsKey = $this->cacheResendAttemptsKey($alumni->id);

        // Already locked out from a previous 4th attempt.
        if (cache()->has($resendLockKey)) {
            $this->checkAndSyncSendLock($alumni);
            $this->showResendLockedModal = true;
            return;
        }

        // Enforce max 3 OTP sends per rolling window — the 4th attempt
        // locks the account for 30 minutes instead of sending anything.
        $sendAttempts = cache()->get($resendAttemptsKey, 0);
        if ($sendAttempts >= self::MAX_RESEND_ATTEMPTS) {
            $this->lockSending($alumni);
            return;
        }

        try {
            // FIX: generateOtp() sets a provisional otp_expires_at, but we
            // must NOT treat that as the real start of the 10-minute window
            // yet — Mail::send() is synchronous and can take several
            // seconds (sometimes longer on a slow SMTP connection). If we
            // started the clock at generateOtp() time, the user would open
            // their inbox and already see e.g. 9:50 instead of 10:00,
            // because those seconds ticked away while the email was still
            // being sent. Same fix as forgot-password.blade.php.
            $otp = $alumni->generateOtp();

            try {
                Mail::to($targetEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Alumni OTP sent to: {$targetEmail}");
            } catch (\Exception $e) {
                Log::warning("Alumni OTP mail failed: " . $e->getMessage());
            }

            // Only count this attempt once we've actually tried to send —
            // counted here (not gated on mail success) to match this file's
            // original behavior of not hard-failing on mail errors.
            cache()->put($resendAttemptsKey, $sendAttempts + 1, now()->addMinutes(self::RESEND_LOCK_MINUTES));

            // NOW that the send has actually been attempted, restart the
            // 10-minute window from this exact moment. This is what makes
            // the visible countdown genuinely start at 10:00 instead of
            // already being several seconds short.
            $alumni->update(['otp_expires_at' => now()->addMinutes(10)]);

            session()->put('alumni_pending_email',       $targetEmail);
            session()->put('alumni_password_reset_step', 'otp_sent');
            cache()->put($this->cacheEmailKey($alumni->id), $targetEmail, now()->addMinutes(15));
            cache()->forget('alumni_otp_attempts:' . $alumni->id);
            cache()->forget('alumni_otp_locked:'   . $alumni->id);

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = false;
            $this->otp            = '';
            $this->syncOtpExpiry($alumni);
            $this->successMessage = "Verification code sent to {$this->maskedEmail}. Please check your inbox.";

        } catch (\Exception $e) {
            Log::error("_sendOtp error: " . $e->getMessage());
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
        $this->otpExpiresAtMs = 0;
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

        // Same 3x + 30-min-lock rule as Step 1's send — enforced uniformly
        // inside _sendOtp(), which this now also passes through.
        $this->checkAndSyncSendLock($alumni);
        if ($this->sendLocked) {
            $this->showResendLockedModal = true;
            return;
        }

        $this->_sendOtp($alumni, $targetEmail);
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
            'good'   => ['label' => 'Good',   'color' => 'text-emerald-600','progressColor' => 'bg-emerald-500','width' => 'w-3/4'],
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

<div class="min-h-screen w-full flex flex-col items-center justify-center p-4 sm:p-5 antialiased"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;">

    {{-- Dark overlay --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm z-0"></div>

    {{-- Back to Home --}}
    <div class="relative z-10 w-full max-w-2xl mb-4">
        <button
            wire:click="backToHome"
            wire:loading.attr="disabled"
            wire:target="backToHome"
            class="inline-flex items-center gap-2 text-white hover:text-white/80 transition-colors font-semibold text-sm group">
            <div class="w-8 h-8 flex items-center justify-center rounded-lg border border-white/30 bg-white/10 group-hover:border-white/60 group-hover:bg-white/20 transition-all shadow-sm">
                <span wire:loading.remove wire:target="backToHome"><i class="fa-solid fa-arrow-left text-sm"></i></span>
                <span wire:loading wire:target="backToHome" x-cloak class="flex gap-0.5">
                    <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                    <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                    <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                </span>
            </div>
            <span>Back to Home</span>
        </button>
    </div>

    {{-- ══ Success Modal ══ --}}
    @if ($showSuccessModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
             x-data="{ visible: false }"
             x-init="$nextTick(() => { visible = true })">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"></div>

            <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden text-center"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-400"
                 x-transition:enter-start="opacity-0 scale-90 translate-y-6"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0">
                <div class="h-1 w-full" style="background: linear-gradient(90deg, #7A3F91, #059669, #7A3F91);"></div>

                <div class="px-6 sm:px-8 pt-8 pb-7 space-y-5">
                    <div class="flex justify-center">
                        <div class="relative w-20 h-20">
                            <div class="absolute inset-0 rounded-full bg-emerald-100 animate-ping opacity-40"></div>
                            <div class="absolute inset-2 rounded-full bg-emerald-100 animate-ping opacity-30" style="animation-delay:.2s"></div>
                            <div class="relative w-20 h-20 rounded-full flex items-center justify-center shadow-lg"
                                 style="background: linear-gradient(135deg, #34d399, #059669);">
                                <i class="fa-solid fa-check text-white text-2xl sm:text-3xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <h2 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Account Activated!</h2>
                        <p class="text-sm sm:text-base font-medium" style="color: #333333;">Your alumni account has been verified.</p>
                        <p class="text-sm" style="color: #555555; line-height: 1.6;">Welcome to the PCST Alumni Portal! Please complete your profile to access all features.</p>
                    </div>

                    <div class="rounded-lg border px-4 py-3 flex items-center gap-3 text-left" style="background: #F8F4FC; border-color: #E8E0F0;">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background: #7A3F91;">
                            <i class="fa-solid fa-graduation-cap text-white text-sm"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider" style="color: #7A3F91;">Account Status</p>
                            <p class="text-sm font-bold" style="color: #333333;">Verified Alumni ✓</p>
                        </div>
                    </div>

                    <button wire:click="goToDashboard" wire:loading.attr="disabled" wire:target="goToDashboard"
                            class="w-full text-white py-3 sm:py-3.5 rounded-xl font-bold text-sm sm:text-base shadow-lg hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="goToDashboard">Complete My Profile</span>
                        <span wire:loading wire:target="goToDashboard" x-cloak class="flex items-center gap-1.5">
                            <span class="flex gap-0.5">
                                <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                                <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                                <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                            </span>
                            <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Loading</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ══ Resend Locked Modal ══ --}}
    @if ($showResendLockedModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
             x-data="{ visible: false }"
             x-init="$nextTick(() => { visible = true })">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"></div>

            <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden text-center"
                 x-show="visible"
                 x-transition:enter="transition ease-out duration-400"
                 x-transition:enter-start="opacity-0 scale-90 translate-y-6"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-data="sendLockTimer($wire.entangle('sendLockedUntilMs'))">
                <div class="h-1 w-full" style="background: linear-gradient(90deg, #DC2626, #7A3F91, #DC2626);"></div>

                <div class="px-6 sm:px-8 pt-8 pb-7 space-y-5">
                    <div class="flex justify-center">
                        <div class="relative w-20 h-20">
                            <div class="absolute inset-0 rounded-full bg-red-100 animate-ping opacity-40"></div>
                            <div class="relative w-20 h-20 rounded-full flex items-center justify-center shadow-lg"
                                 style="background: linear-gradient(135deg, #f87171, #dc2626);">
                                <i class="fa-solid fa-lock text-white text-2xl sm:text-3xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <h2 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Too Many Code Requests</h2>
                        <p class="text-sm sm:text-base font-medium" style="color: #333333;">You've reached the maximum number of code requests (3).</p>
                        <p class="text-sm" style="color: #555555; line-height: 1.6;">
                            For your account's security, sending a new code has been disabled.
                            <span x-show="locked">You can try again in <strong class="font-mono" x-text="formattedTime"></strong>.</span>
                        </p>
                    </div>

                    <button wire:click="backToLogin"
                            wire:loading.attr="disabled"
                            wire:target="backToLogin"
                            class="w-full text-white py-3 sm:py-3.5 rounded-xl font-bold text-sm sm:text-base shadow-lg hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="backToLogin">Back to Login</span>
                        <span wire:loading wire:target="backToLogin" x-cloak class="flex items-center gap-1.5">
                            <span class="flex gap-0.5">
                                <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                                <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                                <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                            </span>
                            <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Redirecting</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ══ Main Card ══ --}}
    <div class="relative z-10 w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Header --}}
        <div class="px-6 sm:px-10 pt-7 pb-6 border-b" style="border-color: #E8E8E8;">
            <div class="flex items-center gap-3 mb-7">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background: #7A3F91;">
                    <i class="fa-solid fa-graduation-cap text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Alumni Account Setup</h1>
                    <p class="text-xs sm:text-sm mt-0.5" style="color: #555555;">PhilCST Alumni</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            @php
                $steps = ['Send OTP', 'OTP', 'Password'];
            @endphp
            <div class="flex items-center gap-1 sm:gap-0">
                @foreach ($steps as $idx => $label)
                    @php $pos = $idx + 1; $total = count($steps); @endphp
                    <div class="flex items-center {{ $pos < $total ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1.5 flex-shrink-0">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-xs sm:text-sm font-bold border-2 transition-all"
                                style="{{ $pos == $step ? 'background:#7A3F91; border-color:#7A3F91; color:#ffffff;' : ($pos < $step ? 'background:#059669; border-color:#059669; color:#ffffff;' : 'background:#ffffff; border-color:#E8E8E8; color:#999999;') }}">
                                @if ($pos < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $pos }}
                                @endif
                            </div>
                            <span class="text-xs font-semibold whitespace-nowrap"
                                style="{{ $pos == $step ? 'color:#7A3F91;' : ($pos < $step ? 'color:#059669;' : 'color:#999999;') }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($pos < $total)
                            <div class="flex-1 h-0.5 mx-2 sm:mx-3 mb-5 rounded-full transition-all {{ $pos < $step ? 'bg-emerald-400' : 'bg-[#E8E8E8]' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Body --}}
        <div class="px-6 sm:px-10 py-7">

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="mb-5 px-4 py-3 rounded-lg border flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                    <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5 flex-shrink-0 text-lg"></i>
                    <p class="text-sm font-medium text-red-700">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-5 px-4 py-3 rounded-lg border flex items-start gap-3" style="background:#ECFDF5; border-color:#A7F3D0;">
                    <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 flex-shrink-0 text-lg"></i>
                    <p class="text-sm font-medium text-emerald-700">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- ══ STEP 1: Send OTP ══ --}}
            @if ($step == 1)
                <div class="space-y-5" x-data="sendLockTimer($wire.entangle('sendLockedUntilMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Welcome Back!</h2>
                        <p class="text-sm mt-1" style="color: #555555;">To activate your account, we'll send a verification code to your registered email address.</p>
                    </div>

                    {{-- ── Locked-out notice — shown instead of relying only on the modal,
                         so the button is visibly disabled with a live countdown even
                         before the user clicks anything. ── --}}
                    <div wire:ignore x-show="locked" x-cloak
                         class="rounded-lg border px-4 py-3 flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                        <i class="fa-solid fa-lock text-red-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-xs sm:text-sm font-medium text-red-700">
                            Too many code requests. Please wait
                            <span class="font-mono font-bold" x-text="formattedTime"></span>
                            before requesting a new code.
                        </p>
                    </div>

                    @if ($hasExistingEmail)
                        <div class="rounded-lg border px-4 py-3.5 flex items-center gap-3" style="background: #F8F4FC; border-color: #E8E8E8;">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background: rgba(122,63,145,0.1);">
                                <i class="fa-solid fa-envelope" style="color: #7A3F91;"></i>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide" style="color: #555555;">Registered Email Address</p>
                                <p class="text-sm font-bold tracking-wide mt-0.5" style="color: #7A3F91;">{{ $maskedEmail }}</p>
                            </div>
                        </div>

                        <button
                            wire:click="sendOtp"
                            wire:loading.attr="disabled"
                            wire:target="sendOtp"
                            x-bind:disabled="locked"
                            :class="locked ? 'opacity-50 cursor-not-allowed' : ''"
                            class="fp-submit-btn">
                            <span wire:loading.remove wire:target="sendOtp" x-show="!locked" class="flex items-center justify-center gap-2">
                                Send Verification Code
                                <i class="fa-solid fa-paper-plane" style="font-size:0.72rem;"></i>
                            </span>
                            <span x-show="locked" x-cloak class="flex items-center justify-center gap-2">
                                Try again in <span class="font-mono" x-text="formattedTime"></span>
                            </span>
                            <span wire:loading wire:target="sendOtp" x-cloak class="flex items-center justify-center gap-2">
                                <span class="flex gap-0.5">
                                    <span class="dot1 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                    <span class="dot2 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                    <span class="dot3 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                </span>
                                <span style="font-size:0.78rem; letter-spacing:0.16em;">Sending</span>
                            </span>
                        </button>
                    @else
                        <div class="rounded-lg border px-4 py-4 text-center space-y-2" style="background: #FFFBEB; border-color: #FDE68A;">
                            <div class="w-11 h-11 rounded-full flex items-center justify-center mx-auto" style="background: #FEF3C7;">
                                <i class="fa-solid fa-envelope-circle-check text-amber-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-amber-800">No Email Address Found</h3>
                                <p class="text-xs text-amber-700 mt-1 leading-relaxed">
                                    We don't have an email address on file for your account. Please contact the registrar to add your email before proceeding.
                                </p>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ══ STEP 2: Verify OTP ══ --}}
            @if ($step == 2)
                <div class="space-y-5" x-data="otpTimer($wire.entangle('otpExpiresAtMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Enter Verification Code</h2>
                        <p class="text-sm mt-1" style="color: #555555;">A 6-digit code was sent to <strong style="color: #7A3F91;">{{ $maskedEmail }}</strong></p>
                    </div>

                    {{-- Timer + OTP side by side --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div wire:ignore class="rounded-lg border p-4 text-center flex flex-col items-center justify-center" style="background: #F8F4FC; border-color: #E8E8E8;">
                            <p class="text-xs font-semibold uppercase mb-1.5" style="color: #555555; letter-spacing: 0.08em;">Code Expires In</p>
                            <div class="text-3xl sm:text-4xl font-bold font-mono tabular-nums transition-colors duration-300"
                                 style="color: #7A3F91;"
                                 :style="seconds <= 60 ? 'color: #dc2626;' : 'color: #7A3F91;'"
                                 x-text="formattedTime">10:00</div>
                            <p x-show="expired" x-cloak class="text-red-600 text-xs mt-1.5 font-semibold">Code expired.</p>
                            @if (!$otpLocked)
                                <p class="text-xs mt-1.5" style="color: #999999;" x-show="!expired">
                                    <i class="fa-solid fa-shield-halved mr-1" style="color: #7A3F91;"></i>Max 3 attempts
                                </p>
                            @endif
                        </div>

                        <div class="flex flex-col justify-center">
                            <label class="block text-sm font-semibold mb-2" style="color: #333333;">6-Digit Code</label>
                            <input wire:model="otp"
                                   type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                                   autocomplete="one-time-code"
                                   {{ $otpLocked ? 'disabled' : '' }}
                                   placeholder="000000"
                                   class="w-full px-3 py-3.5 text-center text-2xl font-bold tracking-[0.25em] border rounded-lg focus:outline-none transition-all"
                                   style="{{ $otpLocked ? 'background:#F5F5F5; border-color:#E8E8E8; color:#999999; cursor:not-allowed;' : 'background:#FFFFFF; border-color:#E8E8E8; color:#333333;' }}"
                                   onfocus="if(!this.disabled)this.style.borderColor='#7A3F91'; if(!this.disabled)this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                   onblur="if(!this.disabled)this.style.borderColor='#E8E8E8'; if(!this.disabled)this.style.boxShadow='none';">
                        </div>
                    </div>

                    @if ($otpLocked)
                        <div class="rounded-lg border px-4 py-3 flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                            <i class="fa-solid fa-lock text-red-500 text-base flex-shrink-0 mt-0.5"></i>
                            <div>
                                <p class="text-sm font-bold text-red-700">Verification Locked</p>
                                <p class="text-xs text-red-600">Wait for the timer to expire, then request a new code.</p>
                            </div>
                        </div>
                    @endif

                    @if (!$otpLocked)
                        <button wire:click="verifyOtp"
                                wire:loading.attr="disabled"
                                wire:target="verifyOtp"
                                x-bind:disabled="expired"
                                :class="expired ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-90 active:scale-[0.99]'"
                                class="w-full text-white py-3.5 rounded-lg font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2"
                                style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                            <span wire:loading.remove wire:target="verifyOtp">Verify Code & Continue</span>
                            <span wire:loading wire:target="verifyOtp" x-cloak class="flex items-center gap-1.5">
                                <span class="flex gap-0.5">
                                    <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                                    <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                                    <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                                </span>
                                <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Verifying</span>
                            </span>
                        </button>
                    @endif

                    <button wire:click="resendOtp"
                            wire:loading.attr="disabled"
                            wire:target="resendOtp"
                            x-bind:disabled="!canResend"
                            type="button"
                            :class="canResend ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
                            class="w-full py-3 rounded-lg font-semibold text-sm border transition-all flex items-center justify-center gap-2"
                            :style="canResend ? 'background:#FFFFFF; border-color:#7A3F91; color:#7A3F91;' : 'background:#F5F5F5; border-color:#E8E8E8; color:#999999;'">
                        <span wire:loading.remove wire:target="resendOtp">
                            <span x-show="!canResend" wire:ignore>Resend in <span class="font-bold" x-text="formattedTime"></span></span>
                            <span x-show="canResend">
                                @if ($otpLocked) Request New Code @else Resend Code @endif
                            </span>
                        </span>
                        <span wire:loading wire:target="resendOtp" x-cloak class="flex items-center gap-1.5">
                            <span class="flex gap-0.5">
                                <span class="dot1 inline-block w-1 h-1 rounded-full" style="background: #7A3F91;"></span>
                                <span class="dot2 inline-block w-1 h-1 rounded-full" style="background: #7A3F91;"></span>
                                <span class="dot3 inline-block w-1 h-1 rounded-full" style="background: #7A3F91;"></span>
                            </span>
                            <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Sending</span>
                        </span>
                    </button>
                </div>
            @endif

            {{-- ══ STEP 3: Set Password ══ --}}
            @if ($step == 3)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Set Your New Password</h2>
                        <p class="text-sm mt-0.5" style="color: #555555;">Create a strong password to secure your alumni account.</p>
                    </div>

                    {{-- Two-column layout --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                        {{-- LEFT: password fields --}}
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-semibold mb-1.5" style="color: #333333;">New Password</label>
                                <div class="relative">
                                    <input wire:model.live.debounce.200ms="password"
                                           type="{{ $showPassword ? 'text' : 'password' }}"
                                           autocomplete="new-password"
                                           class="w-full px-4 py-3 text-sm border rounded-lg focus:outline-none transition-all pr-10"
                                           style="border-color: #E8E8E8; color: #333333; background: #FFFFFF;"
                                           onfocus="this.style.borderColor='#7A3F91'; this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                           onblur="this.style.borderColor='#E8E8E8'; this.style.boxShadow='none';">
                                    <button type="button" wire:click="$toggle('showPassword')" class="absolute right-3 top-1/2 -translate-y-1/2" style="color: #999999;">
                                        <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                                    </button>
                                </div>
                                @if ($password !== '')
                                    <div class="mt-1.5 space-y-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs" style="color: #555555;">Strength</span>
                                            <span class="text-xs font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">{{ $this->getPasswordStrengthInfo()['label'] }}</span>
                                        </div>
                                        <div class="h-1.5 rounded-full overflow-hidden" style="background: #E8E8E8;">
                                            <div class="h-full rounded-full transition-all duration-300 {{ $this->getPasswordStrengthInfo()['progressColor'] }} {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-1.5" style="color: #333333;">Confirm Password</label>
                                <div class="relative">
                                    <input wire:model.live.debounce.200ms="password_confirmation"
                                           type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                           autocomplete="new-password"
                                           class="w-full px-4 py-3 text-sm border rounded-lg focus:outline-none transition-all pr-10"
                                           style="border-color: {{ $password_confirmation !== '' && $password !== $password_confirmation ? '#FCA5A5' : ($password_confirmation !== '' && $password === $password_confirmation ? '#6EE7B7' : '#E8E8E8') }}; color: #333333; background: #FFFFFF;"
                                           onfocus="this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                           onblur="this.style.boxShadow='none';">
                                    <button type="button" wire:click="$toggle('showConfirmPassword')" class="absolute right-3 top-1/2 -translate-y-1/2" style="color: #999999;">
                                        <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                                    </button>
                                </div>
                                @if ($password_confirmation !== '')
                                    <p class="text-xs mt-1 font-medium" style="color: {{ $password === $password_confirmation ? '#059669' : '#DC2626' }};">
                                        <i class="fa-solid {{ $password === $password_confirmation ? 'fa-check' : 'fa-xmark' }} mr-1"></i>
                                        {{ $password === $password_confirmation ? 'Passwords match' : 'Passwords do not match' }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- RIGHT: requirements + button --}}
                        <div class="flex flex-col gap-3">
                            <div class="rounded-lg border p-3" style="background: #FAFAFA; border-color: #E8E8E8;">
                                <p class="text-xs font-bold uppercase mb-2" style="color: #777777; letter-spacing: 0.07em;">Requirements</p>
                                <div class="space-y-1.5">
                                    @foreach ([
                                        [strlen($password) >= 8,                 '8+ characters'],
                                        [preg_match('/[A-Z]/', $password),       'Uppercase (A–Z)'],
                                        [preg_match('/[a-z]/', $password),       'Lowercase (a–z)'],
                                        [preg_match('/[0-9]/', $password),       'Number (0–9)'],
                                        [preg_match('/[!@#$%^&*?]/', $password), 'Special (!@#$%)'],
                                    ] as [$met, $text])
                                        <div class="flex items-center gap-2">
                                            <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold {{ $met ? 'bg-emerald-100 text-emerald-700' : 'bg-[#E8E8E8] text-[#999999]' }}">{{ $met ? '✓' : '○' }}</span>
                                            <span class="text-xs {{ $met ? 'font-semibold text-emerald-700' : '' }}" style="{{ !$met ? 'color:#555555;' : '' }}">{{ $text }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex-1"></div>

                            <button wire:click="savePassword"
                                    wire:loading.attr="disabled"
                                    wire:target="savePassword"
                                    {{ !$this->canSavePassword() ? 'disabled' : '' }}
                                    class="w-full text-white py-3.5 rounded-lg font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2 {{ !$this->canSavePassword() ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-90 active:scale-[0.99]' }}"
                                    style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                                <span wire:loading.remove wire:target="savePassword">{{ $this->canSavePassword() ? 'Set Password & Activate' : 'Password Not Ready' }}</span>
                                <span wire:loading wire:target="savePassword" x-cloak class="flex items-center gap-1.5">
                                    <span class="flex gap-0.5">
                                        <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                                        <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                                        <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                                    </span>
                                    <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Saving</span>
                                </span>
                            </button>

                            <button wire:click="backToLogin"
                                    wire:loading.attr="disabled"
                                    wire:target="backToLogin"
                                    type="button"
                                    class="w-full py-2 rounded-lg text-xs font-medium transition-colors hover:bg-[#F5F5F5] flex items-center justify-center gap-1.5"
                                    style="color: #555555;">
                                <span wire:loading.remove wire:target="backToLogin" class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-arrow-left text-xs"></i> Back to Login
                                </span>
                                <span wire:loading wire:target="backToLogin" x-cloak class="flex items-center gap-1.5">
                                    <span class="flex gap-0.5">
                                        <span class="dot1 inline-block w-1 h-1 rounded-full" style="background:#555555;"></span>
                                        <span class="dot2 inline-block w-1 h-1 rounded-full" style="background:#555555;"></span>
                                        <span class="dot3 inline-block w-1 h-1 rounded-full" style="background:#555555;"></span>
                                    </span>
                                    <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Logging out</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="px-6 sm:px-10 py-4 text-center border-t" style="background: #FAFAFA; border-color: #E8E8E8;">
            <p class="text-xs" style="color: #999999;">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }

        @keyframes dotBounce {
            0%,80%,100% { transform: translateY(0); opacity: 0.4; }
            40%          { transform: translateY(-4px); opacity: 1; }
        }
        .dot1 { animation: dotBounce 1.1s ease-in-out infinite 0s; }
        .dot2 { animation: dotBounce 1.1s ease-in-out infinite 0.18s; }
        .dot3 { animation: dotBounce 1.1s ease-in-out infinite 0.36s; }

        /* ── Submit button (same as forgot-password) ── */
        .fp-submit-btn {
            font-family: 'Inter', sans-serif;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #ffffff;
            width: 100%;
            background: #7A3F91;
            padding: 1rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .fp-submit-btn:hover:not(:disabled) {
            background: #6B3680;
            transform: translateY(-1px);
        }
        .fp-submit-btn:active:not(:disabled) {
            transform: scale(0.985);
            background: #5E2F72;
        }
        .fp-submit-btn:disabled { opacity: 0.55; cursor: not-allowed; }
    </style>

    @script
    <script>
        // ── OTP countdown ─────────────────────────────────────────────────
        // FIX: previously this timer lived entirely in localStorage
        // ('alumni_otp_timer_expiry'), completely disconnected from the
        // real otp_expires_at DB column. That caused the countdown to
        // desync on browser back/forward or refresh — sometimes showing
        // time that had already passed, sometimes falsely resetting.
        //
        // Now the countdown is derived ENTIRELY from `otpExpiresAtMs`,
        // entangled with the server-side property of the same name, which
        // is only ever set from the real `alumni.otp_expires_at` column
        // (see syncOtpExpiry() in the PHP class). Same pattern as
        // forgot-password.blade.php:
        //   - A fresh code (sendOtp / resendOtp) sets a brand-new deadline,
        //     and the $watch below picks it up immediately.
        //   - Restoring Step 2 (page refresh, browser back/forward)
        //     reflects the REAL remaining time — the timer can never
        //     falsely "restart" back to 10:00 unless a new code was truly
        //     just sent.
        Alpine.data('otpTimer', (expiresAtMs) => ({
            expiresAtMs,
            seconds: 0,
            expired: true,
            canResend: true,
            _interval: null,

            get formattedTime() {
                const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                const s = String(this.seconds % 60).padStart(2, '0');
                return `${m}:${s}`;
            },

            init() {
                this.recalc();
                this._interval = setInterval(() => this.recalc(), 500);
                this.$watch('expiresAtMs', () => this.recalc());
            },

            recalc() {
                if (!this.expiresAtMs) {
                    this.seconds   = 0;
                    this.expired   = true;
                    this.canResend = true;
                    return;
                }
                const remaining = Math.floor((this.expiresAtMs - Date.now()) / 1000);
                if (remaining > 0) {
                    this.seconds   = remaining;
                    this.expired   = false;
                    this.canResend = false;
                } else {
                    this.seconds   = 0;
                    this.expired   = true;
                    this.canResend = true;
                }
            },

            destroy() {
                if (this._interval) {
                    clearInterval(this._interval);
                    this._interval = null;
                }
            }
        }));

        // ── "Request New Code" lockout countdown (Step 1 button + modal) ───
        // Derived ENTIRELY from `sendLockedUntilMs`, entangled with the
        // server-side property of the same name. That property only ever
        // reflects a REAL lock stored in cache (see checkAndSyncSendLock()
        // in the PHP class) — so this timer can never show a fake lock, and
        // it survives page refresh for as long as the underlying 30-minute
        // lock is genuinely still active. Same pattern as forgot-password.
        Alpine.data('sendLockTimer', (expiresAtMs) => ({
            expiresAtMs,
            seconds: 0,
            locked: false,
            _interval: null,

            get formattedTime() {
                const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                const s = String(this.seconds % 60).padStart(2, '0');
                return `${m}:${s}`;
            },

            init() {
                this.recalc();
                this._interval = setInterval(() => this.recalc(), 1000);
                this.$watch('expiresAtMs', () => this.recalc());
            },

            recalc() {
                if (!this.expiresAtMs) {
                    this.seconds = 0;
                    this.locked  = false;
                    return;
                }
                const remaining = Math.floor((this.expiresAtMs - Date.now()) / 1000);
                if (remaining > 0) {
                    this.seconds = remaining;
                    this.locked  = true;
                } else {
                    this.seconds = 0;
                    this.locked  = false;
                }
            },

            destroy() {
                if (this._interval) {
                    clearInterval(this._interval);
                    this._interval = null;
                }
            }
        }));
    </script>
    @endscript
</div>