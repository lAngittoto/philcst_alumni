{{-- resources/views/livewire/alumni/forgot-password.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Mail\AlumniPasswordReset;
use App\Models\Alumni;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

new #[Layout('app')] class extends Component {

    public int    $step = 1;

    public string $studentId = '';
    public string $email     = '';

    public string $otp        = '';
    public bool   $otpSent    = false;
    public bool   $otpLocked  = false;
    public string $maskedEmail = '';

    // ── OTP countdown source of truth ────────────────────────────────────
    // Epoch milliseconds when the current OTP expires. This ALWAYS comes
    // straight from the alumni.otp_expires_at DB column (never guessed on
    // the front-end), and is entangled with the Alpine timer component so
    // the visible countdown can never drift from the real deadline —
    // whether the page is freshly loaded, restored via browser back/
    // forward, or a brand-new code was just requested.
    public int $otpExpiresAtMs = 0;

    public int $step3RemainingSeconds = 600;

    // ── "Request New Code" lockout (Step 1 button) ───────────────────────
    // After MAX_RESEND_ATTEMPTS (3) sends within the current window, the
    // account is locked for RESEND_LOCK_MINUTES (30 mins). This is tracked
    // independently of the normal wizard session (via the
    // 'alumni_forgot_locked_id' session key) so that even if the user
    // resets back to Step 1 — via browser Back, refresh, or restarting —
    // the "Send Verification Code" button on Step 1 stays disabled with a
    // live countdown for as long as the lock is genuinely still active.
    public bool $sendLocked        = false;
    public int  $sendLockedUntilMs = 0;

    public string $password              = '';
    public string $password_confirmation = '';
    public string $passwordStrength      = 'weak';
    public bool   $showPassword          = false;
    public bool   $showConfirmPassword   = false;

    public string $errorMessage     = '';
    public string $successMessage   = '';
    public string $studentIdError   = '';
    public string $emailError       = '';
    public bool   $showSuccessModal = false;
    public bool   $showResendLockedModal = false;

    private const STEP3_TTL_MINUTES     = 10;
    private const MAX_RESEND_ATTEMPTS   = 3;
    private const RESEND_LOCK_MINUTES   = 30;

    private const SESSION_LOCKED_ID_KEY = 'alumni_forgot_locked_id';

    private function cacheEmailKey(int $id): string           { return "fp_email:{$id}"; }
    private function cacheOtpLockKey(int $id): string         { return "fp_otp_locked:{$id}"; }
    private function cacheOtpAttemptsKey(int $id): string     { return "fp_otp_attempts:{$id}"; }
    private function cacheLastResetKey(int $id): string       { return "fp_last_reset:{$id}"; }
    private function cacheStep3DeadlineKey(int $id): string   { return "fp_step3_deadline:{$id}"; }
    private function cacheResendAttemptsKey(int $id): string  { return "fp_resend_attempts:{$id}"; }
    private function cacheResendLockKey(int $id): string      { return "fp_resend_locked:{$id}"; }
    private function rateLimitKey(): string                  { return 'fp_verify_' . request()->ip(); }

    private function clearAllErrors(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';
        $this->studentIdError = '';
        $this->emailError     = '';
    }

    private function getCooldownRemaining(int $alumniId): ?string
    {
        $resetAt = cache()->get($this->cacheLastResetKey($alumniId));
        if (!$resetAt) return null;

        $availableAt = \Carbon\Carbon::parse($resetAt)->addDays(5);
        $now         = \Carbon\Carbon::now();

        if ($now->greaterThanOrEqualTo($availableAt)) {
            cache()->forget($this->cacheLastResetKey($alumniId));
            return null;
        }

        $diff    = $now->diff($availableAt);
        $days    = (int) $diff->days;
        $hours   = (int) $diff->h;
        $minutes = (int) $diff->i;

        if ($days > 0)  return "{$days} day(s) and {$hours} hour(s)";
        if ($hours > 0) return "{$hours} hour(s) and {$minutes} minute(s)";
        return "{$minutes} minute(s)";
    }

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

    private function getAlumniFromSession(): ?Alumni
    {
        $id = session('alumni_forgot_id');
        return $id ? Alumni::find($id) : null;
    }

    /**
     * Sync the front-end countdown to the REAL otp_expires_at column for
     * this alumni. Called any time we (re)enter step 2 — fresh send,
     * resend, or restoring after browser back/forward/refresh — so the
     * visible timer is always derived from the database, never guessed.
     */
    private function syncOtpExpiry(Alumni $alumni): void
    {
        $this->otpExpiresAtMs = $alumni->otp_expires_at
            ? $alumni->otp_expires_at->getTimestamp() * 1000
            : 0;
    }

    /**
     * Sync the "Request New Code" lockout state from the DB/cache, and
     * decide whether Step 1's send button should be disabled with a live
     * countdown. Driven by an independent session key
     * (SESSION_LOCKED_ID_KEY) rather than the normal wizard session, so
     * the lock survives a full reset back to Step 1 — the whole point is
     * that going back to Step 1 does NOT clear a real, still-active lock.
     *
     * The cache value under cacheResendLockKey() now stores the ISO8601
     * expiry timestamp itself (not just `true`), so the exact remaining
     * time can be shown instead of a static "30 minutes" message.
     */
    private function checkAndSyncSendLock(): void
    {
        $lockedId = session(self::SESSION_LOCKED_ID_KEY);

        if (!$lockedId) {
            $this->sendLocked        = false;
            $this->sendLockedUntilMs = 0;
            return;
        }

        $expiry = cache()->get($this->cacheResendLockKey((int) $lockedId));

        if ($expiry && \Carbon\Carbon::parse($expiry)->isFuture()) {
            $this->sendLocked        = true;
            $this->sendLockedUntilMs = \Carbon\Carbon::parse($expiry)->getTimestamp() * 1000;
        } else {
            $this->sendLocked        = false;
            $this->sendLockedUntilMs = 0;
            session()->forget(self::SESSION_LOCKED_ID_KEY);
        }
    }

    /**
     * Marks the given alumni as locked out of sending any new code (fresh
     * OR resend) for RESEND_LOCK_MINUTES, and immediately syncs the
     * front-end lock state so Step 1's button reflects it right away.
     */
    private function lockSending(int $alumniId): void
    {
        $expiry = now()->addMinutes(self::RESEND_LOCK_MINUTES);
        cache()->put($this->cacheResendLockKey($alumniId), $expiry->toIso8601String(), $expiry);
        cache()->forget($this->cacheResendAttemptsKey($alumniId));

        session([self::SESSION_LOCKED_ID_KEY => $alumniId]);
        $this->checkAndSyncSendLock();
        $this->showResendLockedModal = true;
    }

    // ── Shared step-restoration helpers ─────────────────────────────────────
    // Used by both mount() (page load / refresh) and goToWizardStep()
    // (browser back/forward navigation), so both entry points behave
    // identically and neither one ever triggers a fresh OTP send.

    private function tryRestoreStep2(int $alumniId): bool
    {
        $alumni = Alumni::find($alumniId);

        // Only restore Step 2 if there is a REAL, still-valid OTP in the
        // database for this alumni. Previously this only checked whether a
        // cache key existed, which meant Step 2 could be "restored" with an
        // already-expired OTP and a timer that lied about how much time was
        // left. Checking the DB directly is what actually fixes that.
        if (!$alumni || !$alumni->isOtpStillActive()) {
            return false;
        }

        $cachedEmail  = cache()->get($this->cacheEmailKey($alumniId));
        $displayEmail = $cachedEmail ?: $alumni->email;

        if (!$displayEmail) {
            return false;
        }

        $this->maskedEmail           = $this->maskEmail($displayEmail);
        $this->otpLocked             = cache()->has($this->cacheOtpLockKey($alumniId));
        $this->otpSent               = true;
        $this->otp                   = '';
        $this->password              = '';
        $this->password_confirmation = '';
        $this->syncOtpExpiry($alumni);
        $this->step                  = 2;
        return true;
    }

    private function tryRestoreStep3(int $alumniId): bool
    {
        $deadline = cache()->get($this->cacheStep3DeadlineKey($alumniId));
        if (!$deadline || \Carbon\Carbon::parse($deadline)->isPast()) {
            return false;
        }

        $cached                      = cache()->get($this->cacheEmailKey($alumniId));
        $this->maskedEmail           = $cached ? $this->maskEmail($cached) : '';
        $this->step3RemainingSeconds = max(0, \Carbon\Carbon::parse($deadline)->getTimestamp() - now()->getTimestamp());
        $this->step                  = 3;
        return true;
    }

    private function resetToStep1(): void
    {
        $alumniId = session('alumni_forgot_id');
        if ($alumniId) {
            cache()->forget($this->cacheStep3DeadlineKey($alumniId));
        }
        session()->forget(['alumni_forgot_id', 'alumni_forgot_step']);

        $this->reset([
            'studentId', 'email', 'otp', 'password', 'password_confirmation',
            'otpSent', 'otpLocked', 'maskedEmail', 'showSuccessModal', 'otpExpiresAtMs',
        ]);
        $this->clearAllErrors();
        $this->step = 1;

        // Deliberately NOT forgetting SESSION_LOCKED_ID_KEY here — a real,
        // still-active "too many requests" lock must keep Step 1's send
        // button disabled even after a full wizard reset. It only clears
        // itself once it's genuinely expired (see checkAndSyncSendLock()).
        $this->checkAndSyncSendLock();
    }

    public function mount(): void
    {
        if (Auth::check()) {
            $role = Auth::user()->role;
            $this->redirect(match ($role) {
                'alumni'    => route('alumni.dashboard'),
                'admin'     => route('admin.dashboard'),
                'organizer' => route('organizer.dashboard'),
                'registrar' => route('registrar.dashboard'),
                'director'  => route('director.dashboard'),
                default     => route('login'),
            });
            return;
        }

        // Sync the Step 1 "Request New Code" lockout state unconditionally
        // — independent of which step we end up landing on below — so it's
        // always accurate the moment Step 1 is (re)rendered.
        $this->checkAndSyncSendLock();

        $alumniId = session('alumni_forgot_id');
        $step     = session('alumni_forgot_step', 1);

        if ($alumniId && $step === 3) {
            if ($this->tryRestoreStep3($alumniId)) {
                return;
            }
            session()->forget(['alumni_forgot_id', 'alumni_forgot_step']);
            $this->errorMessage = 'For your security, this password reset session has expired. Please verify your identity again.';
            $this->step = 1;
            return;
        }

        // FIX: previously, refreshing the page while on Step 2 (OTP) would
        // unconditionally forget the session and force the user back to
        // Step 1 — kicking them back to the "login"/verify screen even
        // though their OTP session was still valid. Now we restore Step 2
        // the same way Step 3 is restored above, only resetting to Step 1
        // if the OTP session has genuinely expired.
        if ($alumniId && $step === 2) {
            if ($this->tryRestoreStep2($alumniId)) {
                return;
            }
            session()->forget(['alumni_forgot_id', 'alumni_forgot_step']);
            $this->errorMessage = 'For your security, this password reset session has expired. Please verify your identity again.';
            $this->step = 1;
            return;
        }

        $this->step = 1;
    }

    /**
     * FIX: browser Back/Forward (popstate) now navigates the wizard itself
     * instead of leaving the page or doing nothing. Pressing Back always
     * lands cleanly on Step 1 WITHOUT sending a new OTP — it just restores
     * the form. Pressing Forward again restores whichever step is still
     * legitimately valid (based on cache/session), also without re-sending
     * or re-verifying anything. Called from the front-end via $wire.
     *
     * IMPORTANT: this method NEVER calls _sendOtp(). Restoring Step 2 only
     * ever re-displays the existing, still-valid OTP window (via
     * tryRestoreStep2 → syncOtpExpiry) — it never generates or emails a new
     * code. Requesting a new code is only ever possible through the
     * explicit "Request New Code" button (resendOtp()).
     */
    public function goToWizardStep(int $targetStep): void
    {
        $targetStep = max(1, min(3, $targetStep));
        $this->clearAllErrors();

        $alumniId = session('alumni_forgot_id');

        if ($targetStep === 3 && $alumniId && $this->tryRestoreStep3($alumniId)) {
            return;
        }

        if ($targetStep >= 2 && $alumniId && $this->tryRestoreStep2($alumniId)) {
            return;
        }

        // Target was Step 1, or nothing above could be legitimately
        // restored — always a clean restart, never an OTP resend.
        $this->resetToStep1();
    }

    public function verifyAndSend(): void
    {
        $this->clearAllErrors();

        // ── "Request New Code" lockout — checked FIRST, before anything
        //    else, so a locked-out user can't bypass it just by re-typing
        //    their Student ID / email on Step 1. This mirrors the state
        //    already shown (disabled button + countdown) on the page.
        $this->checkAndSyncSendLock();
        if ($this->sendLocked) {
            $this->showResendLockedModal = true;
            return;
        }

        // ── Rate limit ────────────────────────────────────────────────────
        if (RateLimiter::tooManyAttempts($this->rateLimitKey(), 10)) {
            $secs = RateLimiter::availableIn($this->rateLimitKey());
            $mins = (int) ceil($secs / 60);
            $this->errorMessage = "Too many attempts. Please try again in {$mins} minute(s).";
            return;
        }

        // ── Basic field presence check (no Livewire validate() so errors
        //    go straight to our own properties, not the error bag) ─────────
        $sid   = trim($this->studentId);
        $email = trim($this->email);

        if ($sid === '') {
            $this->studentIdError = 'Please enter your Student ID.';
            return;
        }

        if ($email === '') {
            $this->emailError = 'Please enter your registered email address.';
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->emailError = 'Please enter a valid email address.';
            return;
        }

        // ── Look up alumni ────────────────────────────────────────────────
        $rawId    = ltrim(preg_replace('/[^0-9]/', '', $sid), '0') ?: '0';
        $paddedId = str_pad($rawId, 8, '0', STR_PAD_LEFT);

        $alumni = Alumni::where('student_id', $sid)
            ->orWhere('student_id', $paddedId)
            ->first();

        if (!$alumni || !$alumni->user) {
            RateLimiter::hit($this->rateLimitKey(), 300);
            $this->studentIdError = 'No account found with that Student ID. Please check and try again.';
            return;
        }

        // ── Resend lockout still active — block re-entry via Step 1 too,
        //    otherwise the lock could be bypassed by simply restarting.
        if (cache()->has($this->cacheResendLockKey($alumni->id))) {
            session([self::SESSION_LOCKED_ID_KEY => $alumni->id]);
            $this->checkAndSyncSendLock();
            $this->showResendLockedModal = true;
            return;
        }

        // ── Account not yet set up ────────────────────────────────────────
        if ($alumni->needsAccountSetup() || $alumni->hasTemporaryPassword() || $alumni->password_changed_at === null) {
            $this->studentIdError = 'Forgot Password is not available for accounts that haven\'t completed initial setup. Please log in with your temporary password first.';
            return;
        }

        // ── Cooldown ──────────────────────────────────────────────────────
        $remaining = $this->getCooldownRemaining($alumni->id);
        if ($remaining !== null) {
            $this->errorMessage = "You recently reset your password. For security reasons, Forgot Password can only be used once every 5 days. Please try again in {$remaining}.";
            return;
        }

        // ── No valid email on file ────────────────────────────────────────
        $dbEmail = trim($alumni->email ?? '');
        if (empty($dbEmail) || str_ends_with($dbEmail, '@pending.local')) {
            $this->studentIdError = 'No valid email on file for this account. Please contact the registrar.';
            return;
        }

        // ── Email mismatch ────────────────────────────────────────────────
        if (strtolower($dbEmail) !== strtolower($email)) {
            RateLimiter::hit($this->rateLimitKey(), 300);
            $this->emailError = 'This email does not match the one registered to your school account. Please check and try again.';
            return;
        }

        // ── Step 3 window still active — skip OTP entirely, go straight to
        //    Change Password. FIX: dati, bawat pag-verify dito ay
        //    nagfo-forget ng Step 3 deadline agad kaya laging bumabalik sa
        //    OTP step kahit may 10-min Step 3 session pa (hal. galing sa
        //    browser back/forward). Ngayon, kung may valid pa palang Step 3
        //    window (di pa nag-expire), diretso doon — hindi na kailangan
        //    mag-request ng OTP ulit.
        if ($this->tryRestoreStep3($alumni->id)) {
            RateLimiter::clear($this->rateLimitKey());
            session(['alumni_forgot_id' => $alumni->id, 'alumni_forgot_step' => 3]);
            return;
        }

        // ── Active OTP already exists — restore timer ─────────────────────
        if ($alumni->isOtpStillActive()) {
            RateLimiter::clear($this->rateLimitKey());
            session(['alumni_forgot_id' => $alumni->id, 'alumni_forgot_step' => 2]);
            $this->maskedEmail = $this->maskEmail($dbEmail);
            cache()->put($this->cacheEmailKey($alumni->id), $dbEmail, now()->addMinutes(30));

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = cache()->has($this->cacheOtpLockKey($alumni->id));
            $this->otp            = '';
            $this->syncOtpExpiry($alumni);
            $this->successMessage = "A verification code was already sent to {$this->maskedEmail}. Please check your inbox.";
            $this->dispatch('otp-sent');
            return;
        }

        RateLimiter::clear($this->rateLimitKey());
        session(['alumni_forgot_id' => $alumni->id, 'alumni_forgot_step' => 2]);
        $this->maskedEmail = $this->maskEmail($dbEmail);
        cache()->put($this->cacheEmailKey($alumni->id), $dbEmail, now()->addMinutes(30));

        $this->_sendOtp($alumni, $dbEmail);
    }

    /**
     * FIX: previously used Mail::to()->queue(...) which only pushes the
     * mailable into the `jobs` table. If QUEUE_CONNECTION is not `sync`
     * and no `php artisan queue:work` worker is running, the email NEVER
     * actually leaves the app — it just silently sits queued forever.
     * Switched to Mail::to()->send(...) so it goes out immediately, and
     * the failure is now surfaced to the user instead of only logged.
     *
     * FIX 2: this is now the SINGLE choke point for every actual OTP send —
     * both the initial send from Step 1 and every resend from Step 2
     * ("Request New OTP") pass through here. That means the 3-sends-then-
     * lock-30-minutes rule (same shape as the wrong-code lockout) applies
     * uniformly no matter which button triggered the send. Previously the
     * counter only lived inside resendOtp(), so the very first send from
     * Step 1 was "free" and didn't count toward the limit.
     */
    private function _sendOtp(Alumni $alumni, string $targetEmail): void
    {
        $resendLockKey     = $this->cacheResendLockKey($alumni->id);
        $resendAttemptsKey = $this->cacheResendAttemptsKey($alumni->id);

        // Already locked out from a previous 4th attempt.
        if (cache()->has($resendLockKey)) {
            session([self::SESSION_LOCKED_ID_KEY => $alumni->id]);
            $this->checkAndSyncSendLock();
            $this->showResendLockedModal = true;
            return;
        }

        // Enforce max 3 OTP sends per rolling window — the 4th attempt
        // locks the account for 30 minutes instead of sending anything.
        $sendAttempts = cache()->get($resendAttemptsKey, 0);
        if ($sendAttempts >= self::MAX_RESEND_ATTEMPTS) {
            $this->lockSending($alumni->id);
            return;
        }

        try {
            // Generate + hash the OTP first (this also sets a provisional
            // otp_expires_at), but DON'T treat that timestamp as the real
            // start of the 10-minute window yet — Mail::send() is
            // synchronous and can take a few seconds (sometimes longer on a
            // slow SMTP connection). If we started the clock here, the user
            // would open their inbox and already see e.g. 9:50 instead of
            // 10:00, because those seconds ticked away while the email was
            // still being sent.
            $otp = $alumni->generateOtp();

            try {
                Mail::to($targetEmail)->send(new AlumniPasswordReset($alumni, $otp));
                Log::info("Forgot-password OTP sent to: {$targetEmail}");
            } catch (\Exception $e) {
                Log::error("Forgot-password OTP mail FAILED to send: " . $e->getMessage());

                // Surface the failure instead of pretending it worked.
                $this->errorMessage = 'We could not send the verification code to your email right now. Please check your mail settings or try again in a moment.';
                return;
            }

            // Only count this attempt once the email has genuinely left the
            // server — a failed send above returns early and does NOT burn
            // one of the 3 attempts. The attempts counter itself expires
            // after RESEND_LOCK_MINUTES so a clean, non-locked user isn't
            // stuck being counted forever.
            cache()->put($resendAttemptsKey, $sendAttempts + 1, now()->addMinutes(self::RESEND_LOCK_MINUTES));

            // NOW that the email has actually left the server, restart the
            // 10-minute window from this exact moment. This is what makes
            // the visible countdown genuinely start at 10:00 instead of
            // already being a few seconds short.
            $alumni->update(['otp_expires_at' => now()->addMinutes(10)]);

            cache()->forget($this->cacheOtpAttemptsKey($alumni->id));
            cache()->forget($this->cacheOtpLockKey($alumni->id));
            cache()->forget($this->cacheStep3DeadlineKey($alumni->id));

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = false;
            $this->otp            = '';
            // otp_expires_at now reflects the moment sending finished —
            // this is what actually resets the visible countdown, every
            // single time, without needing a page refresh.
            $this->syncOtpExpiry($alumni);
            $this->successMessage = "Verification code sent to {$this->maskedEmail}. Check your inbox.";
            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("Forgot-password _sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send verification code. Please try again.';
        }
    }

    public function verifyOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $trimmed = trim($this->otp);

        if ($trimmed === '' || strlen($trimmed) < 6) {
            $this->errorMessage = 'Please enter the complete 6-digit verification code.';
            return;
        }
        if (!preg_match('/^\d{6}$/', $trimmed)) {
            $this->errorMessage = 'The code must contain digits only.';
            return;
        }

        $alumni = $this->getAlumniFromSession();
        if (!$alumni) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $lockKey     = $this->cacheOtpLockKey($alumni->id);
        $attemptsKey = $this->cacheOtpAttemptsKey($alumni->id);
        $attempts    = cache()->get($attemptsKey, 0);

        if (cache()->has($lockKey)) {
            $this->otpLocked    = true;
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

        $deadline = now()->addMinutes(self::STEP3_TTL_MINUTES);
        cache()->put(
            $this->cacheStep3DeadlineKey($alumni->id),
            $deadline->toIso8601String(),
            $deadline->copy()->addMinute()
        );

        session(['alumni_forgot_step' => 3]);
        $this->step                  = 3;
        $this->otp                   = '';
        $this->otpExpiresAtMs        = 0;
        $this->step3RemainingSeconds = self::STEP3_TTL_MINUTES * 60;
        $this->successMessage        = 'Email verified! Please set your new password below.';
        $this->dispatch('otp-verified');
    }

    public function resendOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';

        $alumni = $this->getAlumniFromSession();
        if (!$alumni) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        // Early exit for quick UX — yung totoong 3x + 30-min-lock logic ay
        // nasa _sendOtp() na, applied uniformly sa bawat aktwal na send
        // (initial send man sa Step 1 o resend dito sa Step 2).
        if (cache()->has($this->cacheResendLockKey($alumni->id))) {
            session([self::SESSION_LOCKED_ID_KEY => $alumni->id]);
            $this->checkAndSyncSendLock();
            $this->showResendLockedModal = true;
            return;
        }

        $targetEmail = cache()->get($this->cacheEmailKey($alumni->id)) ?? trim($alumni->email ?? '');
        if (empty($targetEmail)) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $this->_sendOtp($alumni, $targetEmail);
    }

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

    public function canSavePassword(): bool
    {
        return $this->isPasswordStrengthValid() && $this->isPasswordsMatching();
    }

    public function savePassword(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if (session('alumni_forgot_step') !== 3) {
            $this->errorMessage = 'Please complete OTP verification first.';
            $this->step = 2;
            return;
        }

        $alumni = $this->getAlumniFromSession();
        if (!$alumni || !$alumni->user) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $deadline = cache()->get($this->cacheStep3DeadlineKey($alumni->id));
        if (!$deadline || \Carbon\Carbon::parse($deadline)->isPast()) {
            session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
            cache()->forget($this->cacheStep3DeadlineKey($alumni->id));
            $this->errorMessage = 'For your security, this password reset session has expired. Please start over.';
            $this->step = 1;
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

        try {
            DB::transaction(function () use ($alumni) {
                DB::table('users')->where('id', $alumni->user_id)->update([
                    'password'   => Hash::make($this->password),
                    'updated_at' => now(),
                ]);
                DB::table('alumni')->where('id', $alumni->id)->update([
                    'password_changed_at' => now(),
                    'updated_at'          => now(),
                ]);
            });

            cache()->put(
                $this->cacheLastResetKey($alumni->id),
                now()->toIso8601String(),
                now()->addDays(5)->addHour()
            );

            session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
            cache()->forget($this->cacheEmailKey($alumni->id));
            cache()->forget($this->cacheOtpAttemptsKey($alumni->id));
            cache()->forget($this->cacheOtpLockKey($alumni->id));
            cache()->forget($this->cacheStep3DeadlineKey($alumni->id));

            Log::info("Alumni forgot-password reset completed: alumni_id #{$alumni->id}");
            $this->showSuccessModal = true;

        } catch (\Exception $e) {
            Log::error("Forgot-password savePassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to save password. Please try again.';
        }
    }

    public function goToLogin(): void
    {
        $alumni = $this->getAlumniFromSession();
        if ($alumni) {
            cache()->forget($this->cacheStep3DeadlineKey($alumni->id));
        }
        session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
        $this->redirect(route('login'));
    }

    public function restartWizard(): void
    {
        $alumni = $this->getAlumniFromSession();
        if ($alumni) {
            cache()->forget($this->cacheStep3DeadlineKey($alumni->id));
        }
        session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
        $this->successMessage = '';
        $this->errorMessage   = 'For your security, this password reset session has expired. Please verify your identity again.';
        $this->otpExpiresAtMs = 0;
        $this->step            = 1;
        $this->checkAndSyncSendLock();
    }
}; ?>

<div class="min-h-screen w-full flex flex-col items-center justify-center p-4 sm:p-5 antialiased"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;"
     x-data="wizardNav()"
     x-init="initHistory({{ $step }})"
     x-on:otp-sent-fresh.window="pushStepState(2)"
     x-on:otp-sent.window="pushStepState(2)"
     x-on:otp-verified.window="pushStepState(3)"
     x-on:popstate.window="handlePopState($event)">

    {{-- Dark overlay --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm z-0"></div>

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
                        <h2 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Password Reset!</h2>
                        <p class="text-sm sm:text-base font-medium" style="color: #333333;">Your password has been updated successfully.</p>
                        <p class="text-sm" style="color: #555555; line-height: 1.6;">You can now log in to your alumni account using your new password.</p>
                    </div>

                    <div class="rounded-lg border px-4 py-3 flex items-start gap-2 text-left" style="background: #FFFBEB; border-color: #FDE68A;">
                        <i class="fa-solid fa-clock text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-xs sm:text-sm" style="color: #92400e; line-height: 1.5;">
                            <strong>Security note:</strong> You can use Forgot Password again after <strong>5 days</strong>.
                        </p>
                    </div>

                    <button wire:click="goToLogin"
                            wire:loading.attr="disabled"
                            wire:target="goToLogin"
                            class="w-full text-white py-3 sm:py-3.5 rounded-xl font-bold text-sm sm:text-base shadow-lg hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="goToLogin">Go to Login</span>
                        <span wire:loading wire:target="goToLogin" x-cloak class="flex items-center gap-1.5">
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
                            For your account's security, Forgot Password has been disabled.
                            <span x-show="locked">You can try again in <strong class="font-mono" x-text="formattedTime"></strong>.</span>
                        </p>
                    </div>

                    <button wire:click="goToLogin"
                            wire:loading.attr="disabled"
                            wire:target="goToLogin"
                            class="w-full text-white py-3 sm:py-3.5 rounded-xl font-bold text-sm sm:text-base shadow-lg hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="goToLogin">Back to Login</span>
                        <span wire:loading wire:target="goToLogin" x-cloak class="flex items-center gap-1.5">
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
                    <i class="fa-solid fa-lock text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Reset Password</h1>
                    <p class="text-xs sm:text-sm mt-0.5" style="color: #555555;">PhilCST Alumni</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            <div class="flex items-center gap-1 sm:gap-0">
                @foreach ([1 => 'Verify', 2 => 'OTP', 3 => 'Password'] as $num => $label)
                    <div class="flex items-center {{ $num < 3 ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1.5 flex-shrink-0">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-xs sm:text-sm font-bold border-2 transition-all"
                                style="{{ $num == $step ? 'background:#7A3F91; border-color:#7A3F91; color:#ffffff;' : ($num < $step ? 'background:#059669; border-color:#059669; color:#ffffff;' : 'background:#ffffff; border-color:#E8E8E8; color:#999999;') }}">
                                @if ($num < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $num }}
                                @endif
                            </div>
                            <span class="text-xs font-semibold whitespace-nowrap"
                                style="{{ $num == $step ? 'color:#7A3F91;' : ($num < $step ? 'color:#059669;' : 'color:#999999;') }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($num < 3)
                            <div class="flex-1 h-0.5 mx-2 sm:mx-3 mb-5 rounded-full transition-all {{ $num < $step ? 'bg-emerald-400' : 'bg-[#E8E8E8]' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Body --}}
        <div class="px-6 sm:px-10 py-7">

            {{-- General alerts (rate limit, cooldown, session errors only) --}}
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

            {{-- ══ STEP 1: Verify Identity ══ --}}
            @if ($step == 1)
                <div class="space-y-5" x-data="sendLockTimer($wire.entangle('sendLockedUntilMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Verify Your Identity</h2>
                        <p class="text-sm mt-1" style="color: #555555;">Enter your Student ID and registered email to receive a verification code.</p>
                    </div>

                    {{-- ── Locked-out notice — shown instead of relying only on the modal,
                         so the button is visibly disabled with a live countdown even
                         before the user submits anything. ── --}}
                    <div wire:ignore x-show="locked" x-cloak
                         class="rounded-lg border px-4 py-3 flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                        <i class="fa-solid fa-lock text-red-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-xs sm:text-sm font-medium text-red-700">
                            Too many code requests. Please wait
                            <span class="font-mono font-bold" x-text="formattedTime"></span>
                            before requesting a new code.
                        </p>
                    </div>

                    {{-- ── Student ID — floating label ── --}}
                    <div>
                        {{-- Inline error callout — above the field --}}
                        @if ($studentIdError)
                            <div class="flex items-start gap-2.5 px-3.5 py-2.5 rounded-lg border mb-2" style="background:#FEF2F2; border-color:#FECACA;">
                                <i class="fa-solid fa-circle-exclamation text-red-500 flex-shrink-0 mt-0.5 text-sm"></i>
                                <p class="text-xs font-medium text-red-600 leading-relaxed">{{ $studentIdError }}</p>
                            </div>
                        @endif

                        <div class="fp-fl-group">
                            <span class="fp-fl-icon"><i class="fa-solid fa-id-card"></i></span>
                            <input wire:model="studentId"
                                   type="text"
                                   placeholder=" "
                                   autocomplete="username"
                                   x-bind:disabled="locked"
                                   class="fp-fl-input {{ $studentIdError ? 'fp-fl-error' : '' }}">
                            <label class="fp-fl-label">Student ID</label>
                        </div>
                    </div>

                    {{-- ── Registered Email — floating label ── --}}
                    <div>
                        <div class="fp-fl-group">
                            <span class="fp-fl-icon"><i class="fa-solid fa-envelope"></i></span>
                            <input wire:model="email"
                                   type="email"
                                   placeholder=" "
                                   autocomplete="email"
                                   x-bind:disabled="locked"
                                   class="fp-fl-input {{ $emailError ? 'fp-fl-error' : '' }}">
                            <label class="fp-fl-label">Registered Email</label>
                        </div>

                        {{-- Inline email error --}}
                        @if ($emailError)
                            <div class="flex items-start gap-2 mt-1.5">
                                <i class="fa-solid fa-triangle-exclamation text-red-500 flex-shrink-0 mt-0.5 text-xs"></i>
                                <p class="text-xs font-medium text-red-600 leading-relaxed">{{ $emailError }}</p>
                            </div>
                        @endif
                    </div>

                    <button wire:click="verifyAndSend"
                            wire:loading.attr="disabled"
                            wire:target="verifyAndSend"
                            x-bind:disabled="locked"
                            :class="locked ? 'opacity-50 cursor-not-allowed' : ''"
                            class="fp-submit-btn">
                        <span wire:loading.remove wire:target="verifyAndSend" x-show="!locked" class="flex items-center justify-center gap-2">
                            Send Verification Code
                            <i class="fa-solid fa-paper-plane" style="font-size:0.72rem;"></i>
                        </span>
                        <span x-show="locked" x-cloak class="flex items-center justify-center gap-2">
                            Try again in <span class="font-mono" x-text="formattedTime"></span>
                        </span>
                        <span wire:loading wire:target="verifyAndSend" x-cloak class="flex items-center justify-center gap-2">
                            <span class="flex gap-0.5">
                                <span class="dot1 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                <span class="dot2 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                <span class="dot3 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                            </span>
                            <span style="font-size:0.78rem; letter-spacing:0.16em;">Verifying</span>
                        </span>
                    </button>

                    <div class="text-center">
                        <a href="{{ route('login') }}" wire:navigate
                           class="fp-back-link">
                            <i class="fa-solid fa-arrow-left" style="font-size:0.7rem;"></i>
                            Back to Login
                        </a>
                    </div>
                </div>
            @endif

            {{-- ══ STEP 2: OTP ══ --}}
            @if ($step == 2)
                <div class="space-y-5" x-data="otpTimer($wire.entangle('otpExpiresAtMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Enter One-Time Password</h2>
                        <p class="text-sm mt-1" style="color: #555555;">A 6-digit OTP was sent to <strong style="color: #7A3F91;">{{ $maskedEmail }}</strong></p>
                    </div>

                    {{-- Timer + OTP side by side --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div wire:ignore class="rounded-lg border p-4 text-center flex flex-col items-center justify-center" style="background: #F8F4FC; border-color: #E8E8E8;">
                            <p class="text-xs font-semibold uppercase mb-1.5" style="color: #555555; letter-spacing: 0.08em;">OTP Expires In</p>
                            <div class="text-3xl sm:text-4xl font-bold font-mono tabular-nums transition-colors duration-300"
                                 style="color: #7A3F91;"
                                 :style="seconds <= 60 ? 'color: #dc2626;' : 'color: #7A3F91;'"
                                 x-text="formattedTime">10:00</div>
                            <p x-show="expired" x-cloak class="text-red-600 text-xs mt-1.5 font-semibold">OTP expired.</p>
                        </div>

                        <div class="flex flex-col justify-center">
                            <label class="block text-sm font-semibold mb-2" style="color: #333333;">6-Digit OTP</label>
                            <input wire:model="otp"
                                   type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
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
                                <p class="text-sm font-bold text-red-700">Locked</p>
                                <p class="text-xs text-red-600">Wait for the timer to expire, then request a new OTP.</p>
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
                            <span wire:loading.remove wire:target="verifyOtp">Continue</span>
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
                            <span x-show="canResend">Request New OTP</span>
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

            {{-- ══ STEP 3: Create Password ══ --}}
            @if ($step == 3)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Create New Password</h2>
                        <p class="text-sm mt-0.5" style="color: #555555;">Choose a strong password to secure your account.</p>
                    </div>

                    {{-- Step 3 session timer --}}
                    <div class="rounded-lg border px-4 py-3 flex items-start gap-2.5"
                         style="background:#FFFBEB; border-color:#FDE68A;"
                         x-data="passwordSessionTimer({{ $step3RemainingSeconds }})"
                         x-init="start()">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <div class="flex-1">
                            <p x-show="!expired" class="text-xs" style="color: #92400e; line-height: 1.5;">
                                For your security, please set your new password within
                                <strong class="font-mono" x-text="formatted"></strong>.
                            </p>
                            <div x-show="expired" x-cloak>
                                <p class="text-xs font-bold" style="color: #b45309;">Your time to set a new password has expired.</p>
                                <button type="button"
                                        wire:click="restartWizard"
                                        wire:loading.attr="disabled"
                                        wire:target="restartWizard"
                                        class="mt-1.5 text-xs font-bold underline"
                                        style="color: #7A3F91;">
                                    Start Over
                                </button>
                            </div>
                        </div>
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
                                           placeholder="Enter new password"
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
                                           placeholder="Re-enter password"
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
                                <span wire:loading.remove wire:target="savePassword">{{ $this->canSavePassword() ? 'Reset Password' : 'Password Not Ready' }}</span>
                                <span wire:loading wire:target="savePassword" x-cloak class="flex items-center gap-1.5">
                                    <span class="flex gap-0.5">
                                        <span class="dot1 inline-block w-1 h-1 bg-white rounded-full"></span>
                                        <span class="dot2 inline-block w-1 h-1 bg-white rounded-full"></span>
                                        <span class="dot3 inline-block w-1 h-1 bg-white rounded-full"></span>
                                    </span>
                                    <span style="font-size: 0.75rem; letter-spacing: 0.1em;">Saving</span>
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

        /* ══ FLOATING LABEL — Step 1 fields ══ */
        .fp-fl-group {
            position: relative;
        }

        .fp-fl-input {
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: #111111;
            width: 100%;
            height: 60px;
            padding: 22px 1rem 8px 3rem;
            background: #ffffff;
            border: 1.5px solid #DDDDDD;
            border-radius: 10px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .fp-fl-input:focus {
            border-color: #7A3F91;
            box-shadow: 0 0 0 3px rgba(122,63,145,0.08);
        }
        .fp-fl-input.fp-fl-error {
            border-color: #FCA5A5;
        }
        .fp-fl-input.fp-fl-error:focus {
            border-color: #EF4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.08);
        }
        .fp-fl-input:disabled {
            background: #F5F5F5;
            color: #999999;
            cursor: not-allowed;
        }

        .fp-fl-label {
            position: absolute;
            left: 3rem;
            top: 50%;
            transform: translateY(-50%);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            font-weight: 400;
            color: #555555;
            pointer-events: none;
            transition: all 0.18s cubic-bezier(.4,0,.2,1);
            background: #ffffff;
            padding: 0 0.2rem;
            line-height: 1;
        }
        .fp-fl-input:focus ~ .fp-fl-label,
        .fp-fl-input:not(:placeholder-shown) ~ .fp-fl-label {
            top: 0;
            transform: translateY(-50%);
            font-size: 0.65rem;
            font-weight: 700;
            color: #7A3F91;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .fp-fl-input.fp-fl-error ~ .fp-fl-label {
            color: #EF4444;
        }
        .fp-fl-input.fp-fl-error:not(:placeholder-shown) ~ .fp-fl-label,
        .fp-fl-input.fp-fl-error:focus ~ .fp-fl-label {
            color: #EF4444;
        }

        .fp-fl-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            color: #666666;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 1;
        }
        .fp-fl-group:focus-within .fp-fl-icon { color: #7A3F91; }

        /* ── Submit button ── */
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

        /* ── Back to Login link ── */
        .fp-back-link {
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 500;
            color: #7A3F91;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.15s ease, opacity 0.15s ease;
        }
        .fp-back-link:hover { opacity: 0.75; }
    </style>

    @script
    <script>
        // ── Wizard step history (browser Back/Forward) ──────────────────────
        // This ONLY manages which step is shown when the user presses the
        // browser's Back/Forward buttons. It never sends OTPs — going back
        // to Step 1 or forward to Step 2 just re-displays whatever the
        // server confirms is still valid (see goToWizardStep() in the PHP
        // class). Requesting an actual new code always requires an explicit
        // tap on "Request New OTP".
        Alpine.data('wizardNav', () => ({
            initHistory(step) {
                if (!history.state || typeof history.state.step === 'undefined') {
                    history.replaceState({ step: step }, '', window.location.href);
                }
            },

            pushStepState(step) {
                if (!history.state || history.state.step !== step) {
                    history.pushState({ step: step }, '', window.location.href);
                }
            },

            handlePopState(event) {
                const targetStep = (event.state && event.state.step) ? event.state.step : 1;
                this.$wire.goToWizardStep(targetStep);
            }
        }));

        // ── OTP countdown ─────────────────────────────────────────────────
        // The countdown is derived ENTIRELY from `otpExpiresAtMs`, which is
        // entangled with the server-side property of the same name. That
        // property is only ever set from the real `alumni.otp_expires_at`
        // database column (see syncOtpExpiry() in the PHP class), so:
        //   - A fresh code (verifyAndSend / resendOtp) sets a brand-new
        //     deadline, and the $watch below picks it up immediately —
        //     no page refresh needed.
        //   - Restoring Step 2 (page refresh, browser back/forward) reflects
        //     the REAL remaining time, so the timer can never falsely
        //     "restart" back to 10:00 unless a new code was truly just sent.
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
        // it survives page refresh / browser back / a full wizard reset for
        // as long as the underlying 30-minute lock is genuinely still active.
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

        Alpine.data('passwordSessionTimer', (initialSeconds) => ({
            seconds:   initialSeconds,
            expired:   initialSeconds <= 0,
            _interval: null,

            get formatted() {
                const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                const s = String(this.seconds % 60).padStart(2, '0');
                return `${m}:${s}`;
            },

            start() {
                if (this.seconds <= 0) {
                    this.expired = true;
                    return;
                }
                this._interval = setInterval(() => {
                    this.seconds--;
                    if (this.seconds <= 0) {
                        this.seconds = 0;
                        this.expired = true;
                        clearInterval(this._interval);
                        this._interval = null;
                    }
                }, 1000);
            }
        }));
    </script>
    @endscript
</div>