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

    // ── "Request New Code" lockout (Step 1 button) ───────────────────────
    // After MAX_RESEND_ATTEMPTS (3) sends within the current window, the
    // account is locked for RESEND_LOCK_MINUTES (24 hours). This is tracked
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

    private const MAX_RESEND_ATTEMPTS   = 3;

    // FIX: previously 30 minutes. To mirror the seriousness of 3 failed
    // "send verification code" attempts (mirrors the same 3x-then-lock
    // pattern used for OTP verification itself), the 4th attempt now
    // locks the account for a full 24 hours instead of just 30 minutes.
    // Same single choke point (_sendOtp) enforces this uniformly whether
    // the send came from Step 1's initial "Send Verification Code" or
    // Step 2's "Request New OTP" — there is no separate path that could
    // bypass the 24-hour lock.
    private const RESEND_LOCK_MINUTES   = 1440; // 24 hours

    private const SESSION_LOCKED_ID_KEY = 'alumni_forgot_locked_id';

    private function cacheEmailKey(int $id): string           { return "fp_email:{$id}"; }
    private function cacheOtpLockKey(int $id): string         { return "fp_otp_locked:{$id}"; }
    private function cacheOtpAttemptsKey(int $id): string     { return "fp_otp_attempts:{$id}"; }
    private function cacheLastResetKey(int $id): string       { return "fp_last_reset:{$id}"; }

    /**
     * FIX: previously this was cacheStep3DeadlineKey() — a cache value
     * holding an ISO8601 timestamp 10 minutes in the future, checked on
     * every mount()/back-navigation/savePassword() call. That meant an
     * alumni who genuinely verified their OTP but then lost wifi, closed
     * the browser, or simply took longer than 10 minutes to pick a
     * password was kicked all the way back to Step 1 and forced to redo
     * the whole OTP flow — even though their identity was already proven.
     *
     * Now this is simply a "verified" flag with a generous, effectively
     * non-restrictive TTL (24 hours) — it exists only as a safety net
     * against a flag lingering forever if someone abandons the flow
     * entirely, NOT as a countdown the user is meant to race against.
     * There is no visible timer for this anymore; as long as the flag is
     * set, Step 3 is always where mount()/browser-back/refresh/lost-
     * connection will land the alumni — exactly like change-password.blade.php's
     * 'otp_verified' session flag never expiring while the wizard session
     * is alive.
     */
    private function cacheOtpVerifiedKey(int $id): string     { return "fp_otp_verified:{$id}"; }
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
     * If the OTP has already expired, this simply reflects a
     * past/zeroed-out deadline — the Alpine timer will correctly show
     * 00:00 / "expired" and enable the "Request New Code" button, instead
     * of the PHP side forcing a reset back to Step 1.
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
     * time can be shown instead of a static message. With the lock now
     * lasting 24 hours, the front-end timer formats this as
     * HH:MM:SS instead of raw minutes:seconds (see sendLockTimer below).
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
     * OR resend) for RESEND_LOCK_MINUTES (now 24 hours), and immediately
     * syncs the front-end lock state so Step 1's button reflects it right
     * away.
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

    /**
     * FIX: previously this REQUIRED $alumni->isOtpStillActive() — meaning
     * the moment the 10-minute OTP window expired, refreshing the page (or
     * pressing browser Back into Step 2) would fail this check and bounce
     * the user all the way back to Step 1 with a "session expired" error.
     * That defeats the whole point of having a "Request New Code" button
     * with its own 3x/24-hour lockout — the user should be able to just sit
     * on Step 2 with an expired timer and tap "Request New Code" instead of
     * being forced to re-enter their Student ID + email from scratch.
     *
     * Now Step 2 is restored as long as this alumni genuinely has an OTP
     * flow in progress (an otp_expires_at value exists on record — even if
     * it's already in the past) and we still know which email to show.
     * The countdown will correctly render as expired via syncOtpExpiry(),
     * and the "Request New Code" button (resendOtp()) enforces the real
     * 3x-then-24-hour-lock security on its own — that's the actual security
     * boundary, not how long ago the last code happened to expire.
     */
    private function tryRestoreStep2(int $alumniId): bool
    {
        $alumni = Alumni::find($alumniId);
        if (!$alumni) {
            return false;
        }

        // Must have actually had a code generated for this session at some
        // point — otherwise there's genuinely nothing to restore.
        if (!$alumni->otp_expires_at) {
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
        $this->syncOtpExpiry($alumni); // may render as already-expired — that's fine, "Request New Code" takes over from here
        $this->step                  = 2;
        return true;
    }

    /**
     * FIX: no time-limited "deadline" check here. Step 3 is restored
     * purely based on whether this alumni has a genuinely verified OTP on
     * record (cacheOtpVerifiedKey) — set the moment verifyOtp() succeeds,
     * and only ever cleared when the password is actually saved, or the
     * wizard is explicitly restarted/logged out of. That means Step 3
     * survives a browser refresh, browser back/forward, closing the tab,
     * or losing wifi mid-way — reopening the page (or hitting Back) always
     * lands back on Step 3, exactly where the alumni left off, for as long
     * as they haven't finished setting their new password. This is the
     * same "OTP already verified, don't make them redo it" behavior as
     * change-password.blade.php's 'otp_verified' session flag.
     */
    private function tryRestoreStep3(int $alumniId): bool
    {
        if (!cache()->has($this->cacheOtpVerifiedKey($alumniId))) {
            return false;
        }

        $alumni = Alumni::find($alumniId);
        if (!$alumni) {
            return false;
        }

        $cached             = cache()->get($this->cacheEmailKey($alumniId));
        $displayEmail       = $cached ?: trim($alumni->email ?? '');
        $this->maskedEmail  = $displayEmail ? $this->maskEmail($displayEmail) : '';
        $this->step         = 3;
        return true;
    }

    /**
     * FIX: previously this ALSO called cache()->forget($this->cacheOtpVerifiedKey())
     * whenever an alumni ID happened to still be in the session — meaning
     * the moment the wizard landed back on Step 1 (via browser Back/
     * popstate → goToWizardStep(1) → here, since tryRestoreStep3()/
     * tryRestoreStep2() had already returned false in goToWizardStep()),
     * the persistent "OTP already verified" flag was wiped out immediately
     * — even before the alumni got a chance to resubmit their Student ID +
     * email. That meant tryRestoreStep3() inside verifyAndSend() always
     * came back false on re-submission, silently forcing a full OTP redo
     * even though the alumni's identity was already proven moments ago.
     *
     * resetToStep1() now ONLY clears session state (which step we're on).
     * It never touches the persistent cache verified flag anymore — that
     * flag is the actual source of truth for "has this alumni already
     * verified an OTP", same pattern as change-password.blade.php's
     * cacheOtpVerifiedKey(), and should only ever be cleared when:
     *   (a) the password is actually saved (savePassword()),
     *   (b) a fresh OTP is sent (_sendOtp() — a new code invalidates the
     *       old verified state), or
     *   (c) the flow is explicitly and intentionally ended (goToLogin(),
     *       or restartWizard() due to a genuinely expired/invalid state).
     * Simply landing back on Step 1 via navigation is none of those —
     * re-entering the same Student ID + email on Step 1 should transparently
     * skip straight back to Step 3 via tryRestoreStep3() in verifyAndSend(),
     * with no new OTP sent, exactly like change-password.blade.php.
     */
    private function resetToStep1(): void
    {
        session()->forget(['alumni_forgot_id', 'alumni_forgot_step']);

        $this->reset([
            'studentId', 'email', 'otp', 'password', 'password_confirmation',
            'otpSent', 'otpLocked', 'maskedEmail', 'showSuccessModal', 'otpExpiresAtMs',
        ]);
        $this->clearAllErrors();
        $this->step = 1;

        // FIX: previously this called checkAndSyncSendLock() here, which
        // read a leftover SESSION_LOCKED_ID_KEY from a PRIOR verified
        // attempt and could re-show the "Too Many Code Requests" banner on
        // a now-blank Step 1 form — before the alumni had typed anything
        // again. The lock is tied to a specific alumni account, not to
        // "whoever this browser session used to be." A genuinely
        // still-active lock will correctly reappear the moment the alumni
        // re-enters and re-matches their Student ID + email in
        // verifyAndSend() (which re-derives it fresh from that lookup) —
        // it does not need to be shown proactively on a blank form.
        session()->forget(self::SESSION_LOCKED_ID_KEY);
        $this->sendLocked        = false;
        $this->sendLockedUntilMs = 0;
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

        // FIX: previously this called checkAndSyncSendLock() unconditionally
        // (or, in an earlier attempt at this fix, conditionally on a
        // leftover SESSION_LOCKED_ID_KEY) — both still let the "Too Many
        // Code Requests" banner reappear on a fresh, blank Step 1 form
        // just because a PRIOR verified attempt had once locked this
        // browser session, even though the alumni hadn't typed their
        // Student ID / email again yet. The lock must only become visible
        // again once the alumni re-enters and re-matches those fields to
        // that same locked account — see verifyAndSend(), which re-derives
        // it fresh from the actual lookup. mount() never shows it
        // proactively.
        session()->forget(self::SESSION_LOCKED_ID_KEY);
        $this->sendLocked        = false;
        $this->sendLockedUntilMs = 0;

        $alumniId = session('alumni_forgot_id');
        $step     = session('alumni_forgot_step', 1);

        // FIX: Step 3 no longer expires on a 10-minute clock. As long as
        // the OTP-verified flag is still on record (tryRestoreStep3), an
        // accidental browser back, refresh, closed tab, or dropped wifi
        // connection always resumes exactly on Step 3 — never bounced back
        // to Step 1 to redo the whole identity check again. Matches
        // change-password.blade.php's behavior where 'otp_verified' always
        // wins on mount().
        if ($alumniId && $step === 3) {
            if ($this->tryRestoreStep3($alumniId)) {
                return;
            }
            session()->forget(['alumni_forgot_id', 'alumni_forgot_step']);
            $this->errorMessage = 'For your security, this password reset session has expired. Please verify your identity again.';
            $this->step = 1;
            return;
        }

        // FIX: refreshing the page (or browser Back) while on Step 2 no
        // longer requires an ACTIVE (unexpired) OTP to stay on Step 2.
        // tryRestoreStep2() now restores Step 2 as long as an OTP flow
        // genuinely exists for this alumni — even if the 10-minute window
        // already lapsed — so the user lands back exactly where they were,
        // with the countdown correctly showing "expired" and the
        // "Request New Code" button ready to go (still governed by its own
        // 3x / 24-hour lockout). This ONLY falls through to a full Step 1
        // reset if there's genuinely no OTP session on record at all.
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
     * ever re-displays the existing OTP window state (via tryRestoreStep2 →
     * syncOtpExpiry) — expired or not — it never generates or emails a new
     * code. Requesting a new code is only ever possible through the
     * explicit "Request New Code" button (resendOtp()). Likewise,
     * restoring Step 3 (tryRestoreStep3) never re-verifies an OTP — it
     * only checks whether this alumni already has a verified flag on
     * record, with no time limit attached — so going Back into an
     * already-verified wizard always lands back on Step 3, never forces
     * a redo of the OTP.
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
        // restored — always a clean restart, never an OTP resend. Note
        // this no longer wipes the persistent OTP-verified cache flag (see
        // resetToStep1() above) — if the alumni resubmits their Student ID
        // + email on Step 1, verifyAndSend()'s own tryRestoreStep3() check
        // will transparently skip them straight back to Step 3 instead of
        // forcing a fresh OTP.
        $this->resetToStep1();
    }

    public function verifyAndSend(): void
    {
        $this->clearAllErrors();

        // ── "Request New Code" lockout ───────────────────────────────────
        // FIX: previously this was checked HERE, before the Student ID /
        // email were even looked at — driven only by a leftover session
        // key from a PRIOR verified attempt. That meant a locked-out
        // alumni who went back to a blank Step 1 form saw the "Too Many
        // Code Requests" banner immediately, before typing anything again.
        // The lock must only reappear once the alumni re-enters and
        // re-matches their Student ID + email to that same locked account
        // — see the real check further below, right after the alumni is
        // looked up (matches the identity actually submitted this time).

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

        $hasError = false;

        if ($sid === '') {
            $this->studentIdError = 'Please enter your Student ID.';
            $hasError = true;
        }

        if ($email === '') {
            $this->emailError = 'Please enter your registered email address.';
            $hasError = true;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->emailError = 'Please enter a valid email address.';
            $hasError = true;
        }

        if ($hasError) {
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

        // ── Already OTP-verified and mid password-reset — skip OTP
        //    entirely, go straight to Change Password. FIX: previously this
        //    depended on a 10-min Step 3 deadline still being in the
        //    future, so re-verifying here after that window passed always
        //    forced a fresh OTP even though identity was already proven.
        //    Now it's simply: has this alumni verified an OTP that hasn't
        //    been consumed yet? If yes, no need to request or verify an OTP
        //    again — go straight to Step 3. This is also now what makes
        //    the "verify → browser Back → resubmit Student ID + email"
        //    flow land straight on Step 3 too, since resetToStep1() no
        //    longer wipes this flag on a plain Back navigation.
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
     * lock-24-hours rule (same shape as the wrong-code lockout) applies
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
        // locks the account for 24 hours instead of sending anything.
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
            // after RESEND_LOCK_MINUTES (24 hours) so a clean, non-locked
            // user isn't stuck being counted forever.
            cache()->put($resendAttemptsKey, $sendAttempts + 1, now()->addMinutes(self::RESEND_LOCK_MINUTES));

            // NOW that the email has actually left the server, restart the
            // 10-minute window from this exact moment. This is what makes
            // the visible countdown genuinely start at 10:00 instead of
            // already being a few seconds short.
            $alumni->update(['otp_expires_at' => now()->addMinutes(10)]);

            cache()->forget($this->cacheOtpAttemptsKey($alumni->id));
            cache()->forget($this->cacheOtpLockKey($alumni->id));
            cache()->forget($this->cacheOtpVerifiedKey($alumni->id));

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

        if ($trimmed === '') {
            $this->errorMessage = 'Please enter the 6-digit verification code.';
            return;
        }
        if (!ctype_digit($trimmed)) {
            $this->errorMessage = 'The code must contain numbers only.';
            return;
        }
        if (strlen($trimmed) !== 6) {
            $this->errorMessage = 'The verification code must be exactly 6 digits.';
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

        // FIX: no more 10-minute Step 3 deadline. This flag is now the
        // single source of truth for "this alumni has a verified OTP and
        // is mid password-reset" — set with a generous 24-hour safety-net
        // TTL (not a user-facing countdown), and only ever cleared when
        // the password is actually saved (savePassword()) or the wizard is
        // explicitly restarted / a fresh OTP is sent. Losing wifi, closing
        // the browser, or going back and forward no longer costs the
        // alumni their verified status — going Back always lands them
        // right back on Step 3 since the OTP is already good.
        cache()->put($this->cacheOtpVerifiedKey($alumni->id), true, now()->addHours(24));

        session(['alumni_forgot_step' => 3]);
        $this->step                  = 3;
        $this->otp                   = '';
        $this->otpExpiresAtMs        = 0;
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

        // Early exit for quick UX — yung totoong 3x + 24-hour-lock logic ay
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

        // FIX: no more 10-minute deadline check here — just confirm the
        // verified flag is still present (it only ever disappears once the
        // password is actually saved, or the wizard is explicitly
        // restarted / a fresh OTP requested).
        if (!cache()->has($this->cacheOtpVerifiedKey($alumni->id))) {
            session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
            $this->errorMessage = 'Please complete OTP verification first.';
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
            cache()->forget($this->cacheOtpVerifiedKey($alumni->id));

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
            cache()->forget($this->cacheOtpVerifiedKey($alumni->id));
        }
        session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
        $this->redirect(route('login'));
    }

    public function restartWizard(): void
    {
        $alumni = $this->getAlumniFromSession();
        if ($alumni) {
            cache()->forget($this->cacheOtpVerifiedKey($alumni->id));
        }
        session()->forget(['alumni_forgot_step', 'alumni_forgot_id']);
        $this->successMessage = '';
        $this->errorMessage   = 'For your security, this password reset session has expired. Please verify your identity again.';
        $this->otpExpiresAtMs = 0;
        $this->step            = 1;

        // FIX: same bug as resetToStep1()/mount() — don't proactively
        // surface a lock banner from a leftover session key on a form the
        // alumni is about to fill in blank again. It reappears correctly
        // once they re-match their Student ID + email in verifyAndSend().
        session()->forget(self::SESSION_LOCKED_ID_KEY);
        $this->sendLocked        = false;
        $this->sendLockedUntilMs = 0;
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
                            For your account's security, Forgot Password has been disabled for 24 hours.
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
                @foreach ([1 => 'Identity', 2 => 'Verify Code', 3 => 'New Password'] as $num => $label)
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
                        <p class="text-sm mt-1" style="color: #555555;">Enter your Student ID and Registered Email below.</p>
                    </div>

                    <div class="flex items-start gap-2.5 px-3.5 py-2.5 rounded-lg border" style="background:#F8F4FC; border-color:#E3D3EC;">
                        <i class="fa-solid fa-circle-info flex-shrink-0 mt-0.5 text-sm" style="color:#7A3F91;"></i>
                        <p class="text-xs font-medium leading-relaxed" style="color:#5B2D6E;">Once verified, we'll automatically send a verification code to your email.</p>
                    </div>

                    <div wire:ignore x-show="locked" x-cloak
                         class="rounded-lg border px-4 py-3 flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                        <i class="fa-solid fa-lock text-red-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-xs sm:text-sm font-medium text-red-700">
                            Too many code requests. Please wait
                            <span class="font-mono font-bold" x-text="formattedTime"></span>
                            before requesting a new code.
                        </p>
                    </div>

                    <div>
                        @if ($studentIdError)
                            <div class="flex items-start gap-2.5 px-3.5 py-2.5 rounded-lg border mb-2" style="background:#FEF2F2; border-color:#FECACA;">
                                <i class="fa-solid fa-circle-exclamation text-red-500 flex-shrink-0 mt-0.5 text-sm"></i>
                                <p class="text-xs font-medium text-red-600 leading-relaxed">{{ $studentIdError }}</p>
                            </div>
                        @endif

                        @if ($emailError)
                            <div class="flex items-start gap-2.5 px-3.5 py-2.5 rounded-lg border mb-2" style="background:#FEF2F2; border-color:#FECACA;">
                                <i class="fa-solid fa-circle-exclamation text-red-500 flex-shrink-0 mt-0.5 text-sm"></i>
                                <p class="text-xs font-medium text-red-600 leading-relaxed">{{ $emailError }}</p>
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
                    </div>

                    <button wire:click="verifyAndSend"
                            wire:loading.attr="disabled"
                            wire:target="verifyAndSend"
                            x-bind:disabled="locked"
                            :class="locked ? 'opacity-50 cursor-not-allowed' : ''"
                            class="fp-submit-btn">
                        <span wire:loading.remove wire:target="verifyAndSend" x-show="!locked" class="flex items-center justify-center gap-2">
                            Verify &amp; Send Code
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

                    <div class="text-center" x-data="{ backLoading: false }">
                        <a href="{{ route('login') }}" wire:navigate
                           @click="backLoading = true"
                           class="fp-back-link">
                            <span x-show="!backLoading" class="inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-arrow-left" style="font-size:0.7rem;"></i>
                                Back to Login
                            </span>
                            <span x-show="backLoading" x-cloak class="inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-notch fp-spin" style="font-size:0.75rem;"></i>
                                Redirecting…
                            </span>
                        </a>
                    </div>
                </div>
            @endif

            {{-- ══ STEP 2: OTP ══ --}}
            @if ($step == 2)
                <div class="space-y-5" x-data="otpTimer($wire.entangle('otpExpiresAtMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Verify Your Code</h2>
                        <p class="text-sm mt-1" style="color: #555555;">A 6-digit code was sent to <strong style="color: #7A3F91;">{{ $maskedEmail }}</strong>. Enter it below and click Confirm.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div wire:ignore class="rounded-lg border p-4 text-center flex flex-col items-center justify-center" style="background: #F8F4FC; border-color: #E8E8E8;">
                            <p class="text-xs font-semibold uppercase mb-1.5" style="color: #555555; letter-spacing: 0.08em;">Code Expires In</p>
                            <div class="text-3xl sm:text-4xl font-bold font-mono tabular-nums transition-colors duration-300"
                                 style="color: #7A3F91;"
                                 :style="seconds <= 60 ? 'color: #dc2626;' : 'color: #7A3F91;'"
                                 x-text="formattedTime">10:00</div>
                            <p x-show="expired" x-cloak class="text-red-600 text-xs mt-1.5 font-semibold">OTP expired.</p>
                        </div>

                        <div class="flex flex-col justify-center" x-data="{ otpLen: {{ strlen(trim($otp)) }} }">
                            <label class="block text-sm font-semibold mb-2" style="color: #333333;">6-Digit Code</label>
                            <input wire:model="otp"
                                   wire:keydown.enter="verifyOtp"
                                   x-on:input="$event.target.value = $event.target.value.replace(/[^0-9]/g, ''); otpLen = $event.target.value.length"
                                   type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                                   {{ $otpLocked ? 'disabled' : '' }}
                                   placeholder="000000"
                                   class="w-full px-3 py-3.5 text-center text-2xl font-bold tracking-[0.25em] border rounded-lg focus:outline-none transition-all"
                                   style="{{ $otpLocked ? 'background:#F5F5F5; border-color:#E8E8E8; color:#999999; cursor:not-allowed;' : 'background:#FFFFFF; border-color:#E8E8E8; color:#333333;' }}"
                                   onfocus="if(!this.disabled)this.style.borderColor='#7A3F91'; if(!this.disabled)this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                   onblur="if(!this.disabled)this.style.borderColor='#E8E8E8'; if(!this.disabled)this.style.boxShadow='none';">
                            <p class="text-xs mt-1.5" :style="otpLen === 6 ? 'color:#059669;' : 'color:#999999;'">
                                <span x-text="otpLen"></span>/6 digits entered
                            </p>
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
                                x-bind:disabled="expired || otpLen !== 6"
                                :class="(expired || otpLen !== 6) ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-90 active:scale-[0.99]'"
                                class="w-full text-white py-3.5 rounded-lg font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2"
                                style="background: linear-gradient(135deg, #7A3F91, #6a3080); transition: transform 0.08s ease, opacity 0.12s ease;">
                            <span wire:loading.remove wire:target="verifyOtp">Confirm OTP</span>
                            <span wire:loading wire:target="verifyOtp" x-cloak class="flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-notch fp-spin" style="font-size:0.8rem;"></i>
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
                            <span x-show="canResend">Request New Code</span>
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

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

        @keyframes fpSpinAnim { to { transform: rotate(360deg); } }
        .fp-spin { animation: fpSpinAnim 0.75s linear infinite; }

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
            transition: background 0.12s ease, transform 0.08s ease;
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
        // FIX: the lock now lasts 24 hours instead of 30 minutes, so a
        // plain "MM:SS" readout would show something ugly like "1439:58".
        // formattedTime now renders as "Hh MMm SSs" once past an hour, and
        // falls back to plain "MM:SS" once under an hour remains — same
        // reactive pattern as before, still driven entirely by
        // `sendLockedUntilMs` (entangled with the real server-side cache
        // value, never guessed on the front-end).
        Alpine.data('sendLockTimer', (expiresAtMs) => ({
            expiresAtMs,
            seconds: 0,
            locked: false,
            _interval: null,

            get formattedTime() {
                const totalSeconds = this.seconds;
                const h = Math.floor(totalSeconds / 3600);
                const m = Math.floor((totalSeconds % 3600) / 60);
                const s = totalSeconds % 60;

                if (h > 0) {
                    return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
                }
                return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
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