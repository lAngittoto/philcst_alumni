{{-- resources/views/livewire/auth/forgot-password.blade.php --}}
{{-- Unified Forgot Password — works for Alumni, Organizer, Director, Registrar, Admin --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Mail\UserPasswordReset;
use App\Models\Alumni;
use App\Models\Organizer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

new #[Layout('app')] class extends Component {

    public int $step = 1;

    // ── Step 1 identity fields ──────────────────────────────────────────
    // One shared "identifier" input (Student ID / ID Number / Username /
    // Name) + email, same as the original alumni-only flow, but now
    // resolved against every role.
    public string $identifier = '';
    public string $email      = '';

    public string $otp         = '';
    public bool   $otpSent     = false;
    public bool   $otpLocked   = false;
    public string $maskedEmail = '';

    public int $otpExpiresAtMs      = 0;
    public int $resendAvailableAtMs = 0;

    public bool   $sendLocked        = false;
    public int    $sendLockedUntilMs = 0;
    public string $sendLockType      = '';

    public string $password              = '';
    public string $password_confirmation = '';
    public string $passwordStrength      = 'weak';
    public bool   $showPassword          = false;
    public bool   $showConfirmPassword   = false;

    public string $errorMessage          = '';
    public string $successMessage        = '';
    public string $identifierError       = '';
    public string $emailError            = '';
    public bool   $showSuccessModal      = false;
    public bool   $showResendLockedModal = false;

    private const MAX_RESEND_ATTEMPTS     = 3;
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const RESEND_BLOCK_MINUTES    = 10;
    private const MAX_DAILY_SENDS         = 12;
    private const DAILY_WINDOW_HOURS      = 24;
    private const MAX_OTP_ATTEMPTS        = 5;

    private const SESSION_LOCKED_KEY = 'fp_locked_subject';   // "role:id"
    private const SESSION_SUBJECT_KEY = 'fp_subject';         // "role:id"
    private const SESSION_STEP_KEY    = 'fp_step';

    // ─────────────────────────────────────────────────────────────────
    // Role adapter
    // ─────────────────────────────────────────────────────────────────
    //
    // Everything role-specific (how to find the account, where the
    // email/otp/password_changed_at live, how to save a new password)
    // goes through this one map + the small helper methods below it.
    // Adding a 6th role later only means adding one more entry here.

    private function roleConfig(): array
    {
        return [
            'alumni' => [
                'label' => 'Alumni',
            ],
            'organizer' => [
                'label' => 'Organizer',
            ],
            'director' => [
                'label' => 'Director',
            ],
            'registrar' => [
                'label' => 'Registrar',
            ],
            'admin' => [
                'label' => 'Admin',
            ],
        ];
    }

    /**
     * Resolve whatever the person typed into a single "subject" array:
     *   ['role' => ..., 'id' => ..., 'user_id' => ..., 'email' => ...,
     *    'fullName' => ..., 'identifierError' => (if blocked, else null)]
     * or null if nothing matched at all.
     *
     * Tries each role in turn, same order as login_blade.php:
     * Organizer -> Alumni -> Director -> Registrar/Admin (users.name).
     */
    private function resolveSubject(string $identifier, string $email): ?array
    {
        $identifier = trim($identifier);

        // ── Organizer (id_number) ───────────────────────────────────────
        $organizer = Organizer::where('id_number', $identifier)->first();
        if ($organizer && $organizer->user) {
            return [
                'role'     => 'organizer',
                'id'       => $organizer->id,
                'user_id'  => $organizer->user_id,
                'email'    => trim($organizer->email ?? ''),
                'fullName' => $organizer->getFullName(),
                'blockedMessage' => ($organizer->password_changed_at === null)
                    ? 'Forgot Password is not available for accounts that haven\'t completed initial setup. Please log in with your temporary password first.'
                    : null,
            ];
        }

        // ── Alumni (student_id) ─────────────────────────────────────────
        $rawId    = ltrim(preg_replace('/[^0-9]/', '', $identifier), '0') ?: '0';
        $paddedId = str_pad($rawId, 8, '0', STR_PAD_LEFT);

        $alumni = Alumni::where('student_id', $identifier)
            ->orWhere('student_id', $paddedId)
            ->first();

        if ($alumni && $alumni->user) {
            return [
                'role'     => 'alumni',
                'id'       => $alumni->id,
                'user_id'  => $alumni->user_id,
                'email'    => trim($alumni->email ?? ''),
                'fullName' => $alumni->getFullName(),
                'blockedMessage' => ($alumni->needsAccountSetup() || $alumni->hasTemporaryPassword() || $alumni->password_changed_at === null)
                    ? 'Forgot Password is not available for accounts that haven\'t completed initial setup. Please log in with your temporary password first.'
                    : null,
            ];
        }

        // ── Director (username -> {username}@director.internal) ──────────
        $directorLoginEmail = $identifier . '@director.internal';
        $directorUser = DB::table('users')
            ->where('email', $directorLoginEmail)
            ->where('role', 'director')
            ->first();

        if ($directorUser) {
            $directorRecord = DB::table('director')->where('user_id', $directorUser->id)->first();

            return [
                'role'     => 'director',
                'id'       => $directorRecord->id ?? null,
                'user_id'  => $directorUser->id,
                // Directors log in with a synthetic email, their REAL contact
                // email for receiving the OTP lives on the director record.
                'email'    => trim($directorRecord->email ?? ''),
                'fullName' => $directorUser->name,
                'blockedMessage' => (!$directorRecord || $directorRecord->password_changed_at === null)
                    ? 'Forgot Password is not available for accounts that haven\'t completed initial setup. Please log in with your temporary password first.'
                    : null,
            ];
        }

        // ── Registrar / Admin (users.name, Auth::attempt-style lookup) ──
        $plainUser = DB::table('users')->where('name', $identifier)->first();
        if ($plainUser && in_array($plainUser->role, ['registrar', 'admin'], true)) {
            return [
                'role'     => $plainUser->role,
                'id'       => $plainUser->id,
                'user_id'  => $plainUser->id,
                'email'    => trim($plainUser->email ?? ''),
                'fullName' => $plainUser->name,
                'blockedMessage' => null,
            ];
        }

        return null;
    }

    private function roleLabel(string $role): string
    {
        return $this->roleConfig()[$role]['label'] ?? ucfirst($role);
    }

    // ── Cache key helpers — all keyed by "role:id" so nothing collides ──
    private function subjectKey(string $role, int $id): string             { return "{$role}:{$id}"; }
    private function cacheEmailKey(string $k): string                     { return "fp_email:{$k}"; }
    private function cacheOtpLockKey(string $k): string                   { return "fp_otp_locked:{$k}"; }
    private function cacheOtpAttemptsKey(string $k): string               { return "fp_otp_attempts:{$k}"; }
    private function cacheOtpVerifiedKey(string $k): string               { return "fp_otp_verified:{$k}"; }
    private function cacheResendAttemptsKey(string $k): string            { return "fp_resend_attempts:{$k}"; }
    private function cacheResendLockKey(string $k): string                { return "fp_resend_locked:{$k}"; }
    private function cacheResendCooldownKey(string $k): string            { return "fp_resend_cooldown:{$k}"; }
    private function cacheOtpSigKey(string $k): string                    { return "fp_otp_sig:{$k}"; }
    private function cacheResendRoundKey(string $k): string               { return "fp_resend_round:{$k}"; }
    private function cacheDailySendsKey(string $k): string                { return "fp_send_daily:{$k}"; }
    private function cacheOtpStoreKey(string $k): string                  { return "fp_otp_hash:{$k}"; }       // hashed OTP itself
    private function cacheOtpExpiryKey(string $k): string                 { return "fp_otp_expiry:{$k}"; }     // expiry timestamp (ms-epoch seconds)
    private function rateLimitKey(): string                               { return 'fp_verify_' . request()->ip(); }

    private function clearAllErrors(): void
    {
        $this->errorMessage    = '';
        $this->successMessage  = '';
        $this->identifierError = '';
        $this->emailError      = '';
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

    /** Re-resolve the subject currently stored in session (role:id form). */
    private function getSubjectFromSession(): ?array
    {
        $stored = session(self::SESSION_SUBJECT_KEY);
        if (!$stored || !str_contains($stored, ':')) {
            return null;
        }
        [$role, $id] = explode(':', $stored, 2);
        $id = (int) $id;

        return match ($role) {
            'alumni' => $this->loadAlumniSubject($id),
            'organizer' => $this->loadOrganizerSubject($id),
            'director' => $this->loadDirectorSubject($id),
            'registrar', 'admin' => $this->loadPlainUserSubject($role, $id),
            default => null,
        };
    }

    private function loadAlumniSubject(int $id): ?array
    {
        $alumni = Alumni::find($id);
        if (!$alumni) return null;
        return [
            'role' => 'alumni', 'id' => $alumni->id, 'user_id' => $alumni->user_id,
            'email' => trim($alumni->email ?? ''), 'fullName' => $alumni->getFullName(),
        ];
    }

    private function loadOrganizerSubject(int $id): ?array
    {
        $organizer = Organizer::find($id);
        if (!$organizer) return null;
        return [
            'role' => 'organizer', 'id' => $organizer->id, 'user_id' => $organizer->user_id,
            'email' => trim($organizer->email ?? ''), 'fullName' => $organizer->getFullName(),
        ];
    }

    private function loadDirectorSubject(int $id): ?array
    {
        $directorRecord = DB::table('director')->where('id', $id)->first();
        if (!$directorRecord) return null;
        $directorUser = DB::table('users')->where('id', $directorRecord->user_id)->first();
        return [
            'role' => 'director', 'id' => $directorRecord->id, 'user_id' => $directorRecord->user_id,
            'email' => trim($directorRecord->email ?? ''), 'fullName' => $directorUser->name ?? '',
        ];
    }

    private function loadPlainUserSubject(string $role, int $userId): ?array
    {
        $user = DB::table('users')->where('id', $userId)->where('role', $role)->first();
        if (!$user) return null;
        return [
            'role' => $role, 'id' => $userId, 'user_id' => $userId,
            'email' => trim($user->email ?? ''), 'fullName' => $user->name ?? '',
        ];
    }

    // ── OTP storage/verification (generic — works across every role) ───

    private function generateAndStoreOtp(array $subject): string
    {
        $key   = $this->subjectKey($subject['role'], $subject['id']);
        $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = now()->addMinutes(10);

        cache()->put($this->cacheOtpStoreKey($key), Hash::make($plain), $expiry->copy()->addMinutes(2));
        cache()->put($this->cacheOtpExpiryKey($key), $expiry->getTimestamp(), $expiry->copy()->addMinutes(2));

        return $plain;
    }

    private function getOtpExpiry(array $subject): ?\Carbon\Carbon
    {
        $key = $this->subjectKey($subject['role'], $subject['id']);
        $ts  = cache()->get($this->cacheOtpExpiryKey($key));
        return $ts ? \Carbon\Carbon::createFromTimestamp($ts) : null;
    }

    private function isOtpStillActive(array $subject): bool
    {
        $expiry = $this->getOtpExpiry($subject);
        return $expiry !== null && now()->lt($expiry);
    }

    private function verifyOtpValue(array $subject, string $plain): bool
    {
        $key    = $this->subjectKey($subject['role'], $subject['id']);
        $hashed = cache()->get($this->cacheOtpStoreKey($key));
        $expiry = $this->getOtpExpiry($subject);

        if (!$hashed || !$expiry || now()->isAfter($expiry)) {
            return false;
        }

        return Hash::check($plain, $hashed);
    }

    private function clearOtpValue(array $subject): void
    {
        $key = $this->subjectKey($subject['role'], $subject['id']);
        cache()->forget($this->cacheOtpStoreKey($key));
        cache()->forget($this->cacheOtpExpiryKey($key));
    }

    /** Persist the new password for whichever role this subject belongs to. */
    private function savePasswordForSubject(array $subject, string $newPassword): void
    {
        $role = $subject['role'];

        DB::transaction(function () use ($role, $subject, $newPassword) {
            DB::table('users')->where('id', $subject['user_id'])->update([
                'password'   => Hash::make($newPassword),
                'updated_at' => now(),
            ]);

            switch ($role) {
                case 'alumni':
                    DB::table('alumni')->where('id', $subject['id'])->update([
                        'password_changed_at' => now(),
                        'updated_at'          => now(),
                    ]);
                    break;

                case 'organizer':
                    DB::table('organizer')->where('id', $subject['id'])->update([
                        'password_changed_at'         => now(),
                        'password_reset_token'        => null,
                        'password_reset_initiated_at' => null,
                        'otp'                          => null,
                        'otp_expires_at'               => null,
                        'updated_at'                   => now(),
                    ]);
                    break;

                case 'director':
                    DB::table('director')->where('id', $subject['id'])->update([
                        'password_changed_at' => now(),
                        'updated_at'          => now(),
                    ]);
                    break;

                case 'registrar':
                case 'admin':
                    // users.password_changed_at added by the OTP-columns migration.
                    DB::table('users')->where('id', $subject['user_id'])->update([
                        'password_changed_at' => now(),
                    ]);
                    break;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // Send-lock / resend-block bookkeeping — identical rules to before,
    // just re-keyed by "role:id" instead of an alumni id.
    // ─────────────────────────────────────────────────────────────────

    private function getActiveSendBlock(string $key): ?array
    {
        $block = null;

        $resendUntil = cache()->get($this->cacheResendLockKey($key));
        if ($resendUntil) {
            $until = \Carbon\Carbon::parse($resendUntil);
            if ($until->isFuture()) {
                $block = ['type' => 'resend', 'until' => $until];
            }
        }

        $daily = cache()->get($this->cacheDailySendsKey($key));
        if (is_array($daily) && (int) ($daily['count'] ?? 0) >= self::MAX_DAILY_SENDS && !empty($daily['until'])) {
            $until = \Carbon\Carbon::parse($daily['until']);
            if ($until->isFuture() && (!$block || $until->greaterThan($block['until']))) {
                $block = ['type' => 'daily', 'until' => $until];
            }
        }

        return $block;
    }

    private function checkAndSyncSendLock(): void
    {
        $lockedKey = session(self::SESSION_LOCKED_KEY);

        if (!$lockedKey) {
            $this->sendLocked = false;
            $this->sendLockedUntilMs = 0;
            $this->sendLockType = '';
            return;
        }

        $block = $this->getActiveSendBlock($lockedKey);

        if ($block) {
            $this->sendLocked = true;
            $this->sendLockedUntilMs = $block['until']->getTimestamp() * 1000;
            $this->sendLockType = $block['type'];
        } else {
            $this->sendLocked = false;
            $this->sendLockedUntilMs = 0;
            $this->sendLockType = '';
            session()->forget(self::SESSION_LOCKED_KEY);
        }
    }

    private function showSendBlock(string $key): void
    {
        session([self::SESSION_LOCKED_KEY => $key]);
        $this->checkAndSyncSendLock();
        $this->showResendLockedModal = $this->sendLocked;
    }

    public function closeResendLockedModal(): void
    {
        $this->showResendLockedModal = false;
    }

    private function recordSend(string $key): bool
    {
        $blocked = false;

        $dailyKey = $this->cacheDailySendsKey($key);
        $daily    = cache()->get($dailyKey);
        if (!is_array($daily) || empty($daily['until']) || \Carbon\Carbon::parse($daily['until'])->isPast()) {
            $daily = [
                'count' => 0,
                'until' => now()->addHours(self::DAILY_WINDOW_HOURS)->toIso8601String(),
            ];
        }
        $daily['count'] = (int) $daily['count'] + 1;
        cache()->put($dailyKey, $daily, \Carbon\Carbon::parse($daily['until']));
        if ($daily['count'] >= self::MAX_DAILY_SENDS) {
            $blocked = true;
        }

        $roundKey    = $this->cacheResendRoundKey($key);
        $attemptsKey = $this->cacheResendAttemptsKey($key);

        if (!cache()->has($roundKey)) {
            cache()->put($roundKey, true, now()->addHours(self::DAILY_WINDOW_HOURS));
            cache()->forget($attemptsKey);
            return $blocked;
        }

        $resends = (int) cache()->get($attemptsKey, 0) + 1;
        if ($resends >= self::MAX_RESEND_ATTEMPTS) {
            $until = now()->addMinutes(self::RESEND_BLOCK_MINUTES);
            cache()->put($this->cacheResendLockKey($key), $until->toIso8601String(), $until);
            cache()->forget($attemptsKey);
            $blocked = true;
        } else {
            cache()->put($attemptsKey, $resends, now()->addHours(self::DAILY_WINDOW_HOURS));
        }

        return $blocked;
    }

    private function syncOtpExpiry(array $subject): void
    {
        $key = $this->subjectKey($subject['role'], $subject['id']);
        $expiry = $this->getOtpExpiry($subject);
        $this->otpExpiresAtMs = $expiry ? $expiry->getTimestamp() * 1000 : 0;

        $cooldownUntil = cache()->get($this->cacheResendCooldownKey($key));
        $availableAt   = ($cooldownUntil && (int) $cooldownUntil > now()->getTimestamp())
            ? (int) $cooldownUntil
            : 0;
        $block = $this->getActiveSendBlock($key);
        if ($block) {
            $availableAt = max($availableAt, $block['until']->getTimestamp());
        }
        $this->resendAvailableAtMs = $availableAt * 1000;
    }

    // ── Step restoration (mount / goToWizardStep) ────────────────────

    public function mount(): void
    {
        $subject = $this->getSubjectFromSession();
        $step    = (int) session(self::SESSION_STEP_KEY, 1);

        $this->checkAndSyncSendLock();

        if ($subject) {
            if ($step === 3 && $this->tryRestoreStep3($subject)) {
                return;
            }
            if ($step >= 2 && $this->tryRestoreStep2($subject)) {
                return;
            }
        }

        $this->resetToStep1();
    }

    private function tryRestoreStep2(array $subject): bool
    {
        if (!$this->getOtpExpiry($subject)) {
            return false;
        }

        $key          = $this->subjectKey($subject['role'], $subject['id']);
        $cachedEmail  = cache()->get($this->cacheEmailKey($key));
        $displayEmail = $cachedEmail ?: $subject['email'];

        if (!$displayEmail) {
            return false;
        }

        $this->maskedEmail           = $this->maskEmail($displayEmail);
        $this->otpLocked             = cache()->has($this->cacheOtpLockKey($key));
        $this->otpSent               = true;
        $this->otp                   = '';
        $this->password              = '';
        $this->password_confirmation = '';
        $this->syncOtpExpiry($subject);
        $this->step = 2;
        return true;
    }

    private function tryRestoreStep3(array $subject): bool
    {
        $key = $this->subjectKey($subject['role'], $subject['id']);
        if (!cache()->has($this->cacheOtpVerifiedKey($key))) {
            return false;
        }

        $this->step                  = 3;
        $this->otp                   = '';
        $this->password              = '';
        $this->password_confirmation = '';
        $this->otpExpiresAtMs        = 0;
        $this->resendAvailableAtMs   = 0;
        return true;
    }

    private function resetToStep1(): void
    {
        session()->forget([self::SESSION_SUBJECT_KEY, self::SESSION_STEP_KEY]);
        $this->step                  = 1;
        $this->identifier            = '';
        $this->email                 = '';
        $this->otp                   = '';
        $this->otpSent               = false;
        $this->otpLocked             = false;
        $this->maskedEmail           = '';
        $this->otpExpiresAtMs        = 0;
        $this->resendAvailableAtMs   = 0;
        $this->password              = '';
        $this->password_confirmation = '';
        $this->clearAllErrors();
    }

    public function goToWizardStep(int $targetStep): void
    {
        $targetStep = max(1, min(3, $targetStep));
        $this->clearAllErrors();

        $subject = $this->getSubjectFromSession();

        if ($subject) {
            if ($targetStep === 3 && $this->tryRestoreStep3($subject)) {
                return;
            }
            if ($targetStep >= 2 && $this->tryRestoreStep2($subject)) {
                return;
            }
        }

        $this->resetToStep1();
    }

    // ─────────────────────────────────────────────────────────────────
    // Step 1 -> Step 2
    // ─────────────────────────────────────────────────────────────────

    public function verifyAndSend(): void
    {
        $this->clearAllErrors();

        if (RateLimiter::tooManyAttempts($this->rateLimitKey(), 10)) {
            $secs = RateLimiter::availableIn($this->rateLimitKey());
            $mins = (int) ceil($secs / 60);
            $this->errorMessage = "Too many attempts. Please try again in {$mins} minute(s).";
            return;
        }

        $identifier = trim($this->identifier);
        $email      = trim($this->email);
        $hasError   = false;

        if ($identifier === '') {
            $this->identifierError = 'Please enter your Student ID / ID Number / Username.';
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

        $subject = $this->resolveSubject($identifier, $email);

        if (!$subject) {
            RateLimiter::hit($this->rateLimitKey(), 300);
            $this->identifierError = 'No account found with that Student ID / ID Number / Username. Please check and try again.';
            return;
        }

        if (!empty($subject['blockedMessage'])) {
            $this->identifierError = $subject['blockedMessage'];
            return;
        }

        $dbEmail = trim($subject['email'] ?? '');
        if (empty($dbEmail) || str_ends_with($dbEmail, '@pending.local')) {
            $this->identifierError = 'No valid email on file for this account. Please contact the administrator.';
            return;
        }

        if (strtolower($dbEmail) !== strtolower($email)) {
            RateLimiter::hit($this->rateLimitKey(), 300);
            $this->emailError = 'This email does not match the one registered to your account. Please check and try again.';
            return;
        }

        $key = $this->subjectKey($subject['role'], $subject['id']);

        // Already verified, mid password-reset — skip straight to Step 3.
        if ($this->tryRestoreStep3($subject)) {
            RateLimiter::clear($this->rateLimitKey());
            session([
                self::SESSION_SUBJECT_KEY => $key,
                self::SESSION_STEP_KEY    => 3,
            ]);
            return;
        }

        // Active OTP already exists — restore timer.
        if ($this->isOtpStillActive($subject)) {
            RateLimiter::clear($this->rateLimitKey());
            session([
                self::SESSION_SUBJECT_KEY => $key,
                self::SESSION_STEP_KEY    => 2,
            ]);
            $this->maskedEmail = $this->maskEmail($dbEmail);
            cache()->put($this->cacheEmailKey($key), $dbEmail, now()->addMinutes(30));

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = cache()->has($this->cacheOtpLockKey($key));
            $this->otp            = '';
            $this->syncOtpExpiry($subject);
            $this->successMessage = "A verification code was already sent to {$this->maskedEmail}. Please check your inbox.";
            $this->dispatch('otp-sent');
            return;
        }

        RateLimiter::clear($this->rateLimitKey());
        session([
            self::SESSION_SUBJECT_KEY => $key,
            self::SESSION_STEP_KEY    => 2,
        ]);
        $this->maskedEmail = $this->maskEmail($dbEmail);
        cache()->put($this->cacheEmailKey($key), $dbEmail, now()->addMinutes(30));

        $this->_sendOtp($subject, $dbEmail);
    }

    private function _sendOtp(array $subject, string $targetEmail): void
    {
        $key = $this->subjectKey($subject['role'], $subject['id']);

        if ($this->getActiveSendBlock($key)) {
            $this->showSendBlock($key);
            return;
        }

        $cooldownUntil = cache()->get($this->cacheResendCooldownKey($key));
        if ($cooldownUntil && (int) $cooldownUntil > now()->getTimestamp()) {
            $wait = (int) $cooldownUntil - now()->getTimestamp();
            $this->resendAvailableAtMs = (int) $cooldownUntil * 1000;
            $this->errorMessage = "Please wait {$wait} second(s) before requesting a new code.";
            return;
        }

        try {
            $otp = $this->generateAndStoreOtp($subject);

            $sentAt        = now();
            $exactExpiry   = $sentAt->copy()->addMinutes(10);
            $cooldownUntilC = $sentAt->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

            // Re-store with the exact expiry captured now (matches the
            // "clock starts the moment Send was clicked" behaviour).
            cache()->put($this->cacheOtpExpiryKey($key), $exactExpiry->getTimestamp(), $exactExpiry->copy()->addMinutes(2));

            cache()->put(
                $this->cacheOtpSigKey($key),
                hash_hmac('sha256', $otp, (string) config('app.key')),
                $exactExpiry->copy()->addMinutes(2)
            );

            try {
                try {
                    Mail::to($targetEmail)->send(new UserPasswordReset(
                        fullName: $subject['fullName'] ?: 'User',
                        otp: $otp,
                        roleLabel: $this->roleLabel($subject['role']),
                    ));
                } catch (\Exception $mailException) {
                    Log::warning("Forgot-password OTP first attempt failed, retrying: " . $mailException->getMessage());
                    Mail::to($targetEmail)->send(new UserPasswordReset(
                        fullName: $subject['fullName'] ?: 'User',
                        otp: $otp,
                        roleLabel: $this->roleLabel($subject['role']),
                    ));
                }
                Log::info("Forgot-password OTP sent to: {$targetEmail} ({$subject['role']})");
            } catch (\Exception $e) {
                Log::error("Forgot-password OTP mail FAILED to send: " . $e->getMessage());
                $this->errorMessage = 'We could not send the verification code to your email right now. Please check your mail settings or try again in a moment.';
                return;
            }

            $blockStarted = $this->recordSend($key);

            cache()->put($this->cacheResendCooldownKey($key), $cooldownUntilC->getTimestamp(), $cooldownUntilC);

            cache()->forget($this->cacheOtpAttemptsKey($key));
            cache()->forget($this->cacheOtpLockKey($key));
            cache()->forget($this->cacheOtpVerifiedKey($key));

            $this->step           = 2;
            $this->otpSent        = true;
            $this->otpLocked      = false;
            $this->otp            = '';
            $this->syncOtpExpiry($subject);
            $this->successMessage = "Verification code sent to {$this->maskedEmail}. Check your inbox.";
            $this->dispatch('otp-sent-fresh');

            if ($blockStarted) {
                $this->showSendBlock($key);
            }

        } catch (\Exception $e) {
            Log::error("Forgot-password _sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send verification code. Please try again.';
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Step 2
    // ─────────────────────────────────────────────────────────────────

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

        $subject = $this->getSubjectFromSession();
        if (!$subject) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $key         = $this->subjectKey($subject['role'], $subject['id']);
        $lockKey     = $this->cacheOtpLockKey($key);
        $attemptsKey = $this->cacheOtpAttemptsKey($key);
        $attempts    = cache()->get($attemptsKey, 0);

        if (cache()->has($lockKey)) {
            $this->otpLocked    = true;
            $this->errorMessage = 'Too many incorrect attempts. Please request a new code to continue.';
            return;
        }

        if ($attempts >= self::MAX_OTP_ATTEMPTS) {
            cache()->put($lockKey, true, now()->addHours(24));
            $this->otpLocked    = true;
            $this->errorMessage = 'Too many incorrect attempts. Please request a new code to continue.';
            return;
        }

        $latestSig    = cache()->get($this->cacheOtpSigKey($key));
        $isLatestCode = !$latestSig
            || hash_equals((string) $latestSig, hash_hmac('sha256', $trimmed, (string) config('app.key')));

        if (!$isLatestCode || !$this->verifyOtpValue($subject, $trimmed)) {
            $new = $attempts + 1;
            cache()->put($attemptsKey, $new, 700);
            $rem = self::MAX_OTP_ATTEMPTS - $new;
            if ($rem <= 0) {
                cache()->put($lockKey, true, now()->addHours(24));
                $this->otpLocked    = true;
                $this->errorMessage = 'Too many incorrect attempts. Please request a new code to continue.';
            } else {
                $this->errorMessage = "Invalid or expired code. You have {$rem} attempt(s) remaining.";
            }
            return;
        }

        cache()->forget($attemptsKey);
        cache()->forget($lockKey);
        cache()->forget($this->cacheOtpSigKey($key));
        $this->clearOtpValue($subject);

        cache()->put($this->cacheOtpVerifiedKey($key), true, now()->addHours(24));

        session([self::SESSION_STEP_KEY => 3]);
        $this->step                = 3;
        $this->otp                 = '';
        $this->otpExpiresAtMs      = 0;
        $this->resendAvailableAtMs = 0;
        $this->successMessage      = 'Email verified! Please set your new password below.';
        $this->dispatch('otp-verified');
    }

    public function resendOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';

        $subject = $this->getSubjectFromSession();
        if (!$subject) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $key = $this->subjectKey($subject['role'], $subject['id']);

        if ($this->getActiveSendBlock($key)) {
            $this->showSendBlock($key);
            return;
        }

        $cooldownUntil = cache()->get($this->cacheResendCooldownKey($key));
        if ($cooldownUntil && (int) $cooldownUntil > now()->getTimestamp()) {
            $wait = (int) $cooldownUntil - now()->getTimestamp();
            $this->resendAvailableAtMs = (int) $cooldownUntil * 1000;
            $this->errorMessage = "Please wait {$wait} second(s) before requesting a new code.";
            return;
        }

        $targetEmail = cache()->get($this->cacheEmailKey($key)) ?? trim($subject['email'] ?? '');
        if (empty($targetEmail)) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $this->_sendOtp($subject, $targetEmail);
    }

    // ─────────────────────────────────────────────────────────────────
    // Step 3 — password strength + save
    // ─────────────────────────────────────────────────────────────────

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

        if ((int) session(self::SESSION_STEP_KEY) !== 3) {
            $this->errorMessage = 'Please complete OTP verification first.';
            $this->step = 2;
            return;
        }

        $subject = $this->getSubjectFromSession();
        if (!$subject) {
            $this->errorMessage = 'Session expired. Please start over.';
            $this->step = 1;
            return;
        }

        $key = $this->subjectKey($subject['role'], $subject['id']);

        if (!cache()->has($this->cacheOtpVerifiedKey($key))) {
            session()->forget([self::SESSION_STEP_KEY, self::SESSION_SUBJECT_KEY]);
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
            $this->savePasswordForSubject($subject, $this->password);

            session()->forget([self::SESSION_STEP_KEY, self::SESSION_SUBJECT_KEY]);
            cache()->forget($this->cacheEmailKey($key));
            cache()->forget($this->cacheOtpAttemptsKey($key));
            cache()->forget($this->cacheOtpLockKey($key));
            cache()->forget($this->cacheOtpVerifiedKey($key));
            cache()->forget($this->cacheResendRoundKey($key));
            cache()->forget($this->cacheResendAttemptsKey($key));
            cache()->forget($this->cacheResendLockKey($key));

            Log::info("Forgot-password reset completed: {$key}");
            $this->showSuccessModal = true;

        } catch (\Exception $e) {
            Log::error("Forgot-password savePassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to save password. Please try again.';
        }
    }

    public function goToLogin(): void
    {
        $subject = $this->getSubjectFromSession();
        if ($subject) {
            $key = $this->subjectKey($subject['role'], $subject['id']);
            cache()->forget($this->cacheOtpVerifiedKey($key));
        }
        session()->forget([self::SESSION_STEP_KEY, self::SESSION_SUBJECT_KEY]);
        $this->redirect(route('login'));
    }

    public function restartWizard(): void
    {
        $subject = $this->getSubjectFromSession();
        if ($subject) {
            $key = $this->subjectKey($subject['role'], $subject['id']);
            cache()->forget($this->cacheOtpVerifiedKey($key));
        }
        session()->forget([self::SESSION_STEP_KEY, self::SESSION_SUBJECT_KEY]);
        $this->successMessage = '';
        $this->errorMessage   = 'For your security, this password reset session has expired. Please verify your identity again.';
        $this->otpExpiresAtMs = 0;
        $this->resendAvailableAtMs = 0;
        $this->step            = 1;

        session()->forget(self::SESSION_LOCKED_KEY);
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
                        <p class="text-sm" style="color: #555555; line-height: 1.6;">You can now log in using your new password.</p>
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
                        <p class="text-sm" style="color: #555555; line-height: 1.6;">
                            @if($sendLockType === 'daily')
                                You've reached today's limit for verification codes.
                            @else
                                You've requested too many codes in a short time.
                            @endif
                            Please try again in:
                        </p>
                        <p class="text-2xl font-bold" style="color: #DC2626;" x-text="formattedTime"></p>
                    </div>

                    <button wire:click="closeResendLockedModal"
                            class="w-full text-white py-3 sm:py-3.5 rounded-xl font-bold text-sm sm:text-base shadow-lg hover:opacity-90 active:scale-[0.98] transition-all"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        Ok, Got It
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="relative z-10 w-full max-w-md">
        <div class="bg-white rounded-2xl shadow-2xl p-7 sm:p-8"
             x-data="{}">

            <div class="text-center mb-6">
                <h1 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Forgot Password</h1>
                <p class="text-sm mt-1" style="color: #666666;">
                    @if ($step === 1) Enter your account details to receive a verification code.
                    @elseif ($step === 2) Enter the 6-digit code sent to your email.
                    @else Set your new password.
                    @endif
                </p>
            </div>

            {{-- Error / Success banners --}}
            @if ($errorMessage)
                <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FCA5A5;">
                    {{ $errorMessage }}
                </div>
            @endif
            @if ($successMessage)
                <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#ECFDF5; color:#047857; border:1px solid #6EE7B7;">
                    {{ $successMessage }}
                </div>
            @endif

            {{-- ══ STEP 1 — Identity ══ --}}
            @if ($step === 1)
                <form wire:submit.prevent="verifyAndSend" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5" style="color:#333333;">
                            Student ID / ID Number / Username
                        </label>
                        <input type="text" wire:model="identifier"
                               class="w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                               style="border-color:#E5E7EB; --tw-ring-color:#7A3F91;"
                               placeholder="Enter your Student ID, ID Number, or Username">
                        @if ($identifierError)
                            <p class="mt-1 text-xs" style="color:#DC2626;">{{ $identifierError }}</p>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5" style="color:#333333;">
                            Registered Email Address
                        </label>
                        <input type="email" wire:model="email"
                               class="w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                               style="border-color:#E5E7EB; --tw-ring-color:#7A3F91;"
                               placeholder="you@example.com">
                        @if ($emailError)
                            <p class="mt-1 text-xs" style="color:#DC2626;">{{ $emailError }}</p>
                        @endif
                    </div>

                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="verifyAndSend"
                            @if($sendLocked) disabled @endif
                            class="w-full text-white py-3 rounded-xl font-bold text-sm shadow-lg hover:opacity-90 active:scale-[0.98] transition-all disabled:opacity-50"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="verifyAndSend">Send Verification Code</span>
                        <span wire:loading wire:target="verifyAndSend">Sending...</span>
                    </button>
                </form>
            @endif

            {{-- ══ STEP 2 — OTP ══ --}}
            @if ($step === 2)
                <div x-data="otpTimer({{ $otpExpiresAtMs }}, {{ $resendAvailableAtMs }}, @js($otpLocked))" class="space-y-4">
                    <p class="text-sm text-center" style="color:#666666;">
                        Code sent to <strong>{{ $maskedEmail }}</strong>
                    </p>

                    <form wire:submit.prevent="verifyOtp" class="space-y-4">
                        <input type="text" wire:model="otp" maxlength="6" inputmode="numeric"
                               class="w-full px-4 py-3 rounded-lg border text-center text-2xl tracking-[0.5em] font-mono focus:outline-none focus:ring-2"
                               style="border-color:#E5E7EB; --tw-ring-color:#7A3F91;"
                               placeholder="------">

                        <p class="text-xs text-center" style="color:#9CA3AF;">
                            <span x-show="!expired">Code expires in <strong x-text="formattedTime"></strong></span>
                            <span x-show="expired" style="color:#DC2626;">Code expired</span>
                        </p>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="verifyOtp"
                                class="w-full text-white py-3 rounded-xl font-bold text-sm shadow-lg hover:opacity-90 active:scale-[0.98] transition-all">
                            <span wire:loading.remove wire:target="verifyOtp">Verify Code</span>
                            <span wire:loading wire:target="verifyOtp">Verifying...</span>
                        </button>
                    </form>

                    <button wire:click="resendOtp"
                            wire:loading.attr="disabled"
                            wire:target="resendOtp"
                            x-bind:disabled="!canResend"
                            class="w-full text-sm font-medium py-2 disabled:opacity-50"
                            style="color:#7A3F91;">
                        <span x-show="canResend">Request New Code</span>
                        <span x-show="!canResend">Resend in <span x-text="resendFormattedTime"></span></span>
                    </button>

                    <button type="button" wire:click="restartWizard" class="w-full text-xs text-center" style="color:#9CA3AF;">
                        Start over
                    </button>
                </div>
            @endif

            {{-- ══ STEP 3 — New password ══ --}}
            @if ($step === 3)
                <form wire:submit.prevent="savePassword" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5" style="color:#333333;">New Password</label>
                        <input type="password" wire:model.live="password"
                               class="w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                               style="border-color:#E5E7EB; --tw-ring-color:#7A3F91;">
                        @php($info = $this->getPasswordStrengthInfo())
                        <div class="mt-2 h-1.5 rounded-full bg-gray-200 overflow-hidden">
                            <div class="h-full {{ $info['progressColor'] }} {{ $info['width'] }} transition-all"></div>
                        </div>
                        <p class="mt-1 text-xs {{ $info['color'] }}">{{ $info['label'] }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5" style="color:#333333;">Confirm Password</label>
                        <input type="password" wire:model.live="password_confirmation"
                               class="w-full px-4 py-3 rounded-lg border focus:outline-none focus:ring-2"
                               style="border-color:#E5E7EB; --tw-ring-color:#7A3F91;">
                    </div>

                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="savePassword"
                            @if(!$this->canSavePassword()) disabled @endif
                            class="w-full text-white py-3 rounded-xl font-bold text-sm shadow-lg hover:opacity-90 active:scale-[0.98] transition-all disabled:opacity-50"
                            style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                        <span wire:loading.remove wire:target="savePassword">Save New Password</span>
                        <span wire:loading wire:target="savePassword">Saving...</span>
                    </button>
                </form>
            @endif

            <div class="mt-6 text-center">
                <a href="{{ route('login') }}" wire:navigate class="text-sm font-medium" style="color:#7A3F91;">
                    &larr; Back to Login
                </a>
            </div>
        </div>
    </div>

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

        Alpine.data('otpTimer', (expiresAtMs, resendAtMs, otpLocked) => ({
            expiresAtMs, resendAtMs, otpLocked,
            seconds: 0, resendSeconds: 0, expired: true, canResend: true, _interval: null,

            get formattedTime() {
                const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                const s = String(this.seconds % 60).padStart(2, '0');
                return `${m}:${s}`;
            },
            get resendFormattedTime() {
                const t = this.resendSeconds;
                const h = Math.floor(t / 3600);
                const m = String(Math.floor((t % 3600) / 60)).padStart(2, '0');
                const s = String(t % 60).padStart(2, '0');
                return h > 0 ? `${h}h ${m}m ${s}s` : `${m}:${s}`;
            },
            init() {
                this.recalc();
                this._interval = setInterval(() => this.recalc(), 500);
                this.$watch('expiresAtMs', () => this.recalc());
                this.$watch('resendAtMs', () => this.recalc());
                this.$watch('otpLocked', () => this.recalc());
            },
            recalc() {
                if (!this.expiresAtMs) {
                    this.seconds = 0; this.expired = true;
                } else {
                    const remaining = Math.ceil((this.expiresAtMs - Date.now()) / 1000);
                    if (remaining > 0) { this.seconds = remaining; this.expired = false; }
                    else { this.seconds = 0; this.expired = true; }
                }
                const resendRemaining = this.resendAtMs ? Math.ceil((this.resendAtMs - Date.now()) / 1000) : 0;
                this.resendSeconds = resendRemaining > 0 ? resendRemaining : 0;
                this.canResend = this.resendSeconds === 0;
            },
            destroy() { if (this._interval) { clearInterval(this._interval); this._interval = null; } }
        }));

        Alpine.data('sendLockTimer', (expiresAtMs) => ({
            expiresAtMs, seconds: 0, locked: false, _interval: null,
            get formattedTime() {
                const totalSeconds = this.seconds;
                const h = Math.floor(totalSeconds / 3600);
                const m = Math.floor((totalSeconds % 3600) / 60);
                const s = totalSeconds % 60;
                if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
                return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            },
            init() {
                this.recalc();
                this._interval = setInterval(() => this.recalc(), 1000);
                this.$watch('expiresAtMs', () => this.recalc());
            },
            recalc() {
                if (!this.expiresAtMs) { this.seconds = 0; this.locked = false; return; }
                const remaining = Math.floor((this.expiresAtMs - Date.now()) / 1000);
                if (remaining > 0) { this.seconds = remaining; this.locked = true; }
                else { this.seconds = 0; this.locked = false; }
            },
            destroy() { if (this._interval) { clearInterval(this._interval); this._interval = null; } }
        }));
    </script>
    @endscript
</div>