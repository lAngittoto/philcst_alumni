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

    /**
     * Fix: every catch block in this file called Log::error()/warning()/info()
     * directly. On hosts where the log path isn't writable (e.g. Railway's
     * ephemeral filesystem without LOG_CHANNEL=stderr configured), that Log
     * call itself throws — and an exception thrown INSIDE a catch block is
     * never caught, so it escapes as a raw 500 Server Error page. This was
     * the exact "500 every time I send/resend, before the 10-minute block
     * clears" bug: resend-lock (and other) failures were being caught, then
     * the logging of that failure was what actually crashed the request.
     * Wrapping every log call here means a logging failure can never take
     * the page down — worst case, that one log line is silently skipped.
     */
    private function safeLog(string $level, string $message): void
    {
        try {
            Log::{$level}($message);
        } catch (\Throwable $ignored) {
            // Logging itself failed — never let that become the user-facing error.
        }
    }

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
        // Wrapped for the same reason as verifyAndSend()/resendOtp() below —
        // a stale/corrupt session subject should fall back to Step 1, not
        // crash the whole page.
        try {
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
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password mount error: " . $e->getMessage());
            $this->resetToStep1();
            // TEMP DIAGNOSTIC — remove this line once the real cause is found.
            // APP_DEBUG is off on Railway so the blank 500 page hides the actual
            // exception; this surfaces it directly on the Step 1 screen instead.
            $this->errorMessage = 'DEBUG — mount() failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            return;
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

        try {
            $subject = $this->getSubjectFromSession();

            if ($subject) {
                if ($targetStep === 3 && $this->tryRestoreStep3($subject)) {
                    return;
                }
                if ($targetStep >= 2 && $this->tryRestoreStep2($subject)) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password goToWizardStep error: " . $e->getMessage());
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
            $this->identifierError = 'Please enter your username.';
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

        // ── Generic, non-enumerating failure message ─────────────────────
        // Deliberately the SAME wording whether the identifier doesn't
        // exist, the account has no usable email on file, or the email
        // just doesn't match what's on record. Revealing *which* field was
        // wrong (old behavior) lets an attacker enumerate valid Student
        // IDs / usernames one probe at a time. Real-world auth flows
        // (Google, Microsoft, GitHub, etc.) never say "that account
        // doesn't exist" vs "wrong email" separately for this reason.
        $genericError = 'We couldn\'t verify those details. Please check your username and registered email, then try again.';

        // Everything below touches the DB, cache, and session — any hiccup
        // there (a null field inside getFullName(), a cache driver blip,
        // etc.) used to bubble up as a raw 500 error page even though the
        // OTP step itself kept working underneath. Wrapping the whole
        // lookup-and-send flow the same way _sendOtp() already does keeps
        // the person on a friendly message instead.
        try {
            $subject = $this->resolveSubject($identifier, $email);

            if (!$subject) {
                RateLimiter::hit($this->rateLimitKey(), 300);
                $this->errorMessage = $genericError;
                return;
            }

            // Setup-incomplete accounts ARE allowed to see a distinct message —
            // it isn't secret information (it doesn't confirm or deny a
            // specific identifier/email pairing by itself) and the user needs
            // to know to log in with their temporary password instead.
            if (!empty($subject['blockedMessage'])) {
                $this->errorMessage = $subject['blockedMessage'];
                return;
            }

            $dbEmail = trim($subject['email'] ?? '');
            if (empty($dbEmail) || str_ends_with($dbEmail, '@pending.local')) {
                RateLimiter::hit($this->rateLimitKey(), 300);
                $this->errorMessage = $genericError;
                return;
            }

            if (strtolower($dbEmail) !== strtolower($email)) {
                RateLimiter::hit($this->rateLimitKey(), 300);
                $this->errorMessage = $genericError;
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
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password verifyAndSend error: " . $e->getMessage());
            $this->errorMessage = 'Something went wrong while sending the verification code. Please try again.';
        }
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
                } catch (\Throwable $mailException) {
                    $this->safeLog('warning', "Forgot-password OTP first attempt failed, retrying: " . $mailException->getMessage());
                    Mail::to($targetEmail)->send(new UserPasswordReset(
                        fullName: $subject['fullName'] ?: 'User',
                        otp: $otp,
                        roleLabel: $this->roleLabel($subject['role']),
                    ));
                }
                $this->safeLog('info', "Forgot-password OTP sent to: {$targetEmail} ({$subject['role']})");
            } catch (\Throwable $e) {
                $this->safeLog('error', "Forgot-password OTP mail FAILED to send: " . $e->getMessage());
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

        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password _sendOtp error: " . $e->getMessage());
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

        // Fix: this whole method used to run unguarded — a cache/session
        // hiccup here (same class of issue as the resend 500) would also
        // raw-500 instead of showing a friendly message.
        try {
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
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password verifyOtp error: " . $e->getMessage());
            $this->errorMessage = 'Something went wrong while verifying your code. Please try again.';
        }
    }

    public function resendOtp(): void
    {
        $this->errorMessage = $this->successMessage = '';

        // Same reasoning as verifyAndSend() above — wrap the lookup +
        // resend flow so an unexpected hiccup shows a friendly message
        // instead of a raw 500 error page.
        try {
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
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password resendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Something went wrong while resending the verification code. Please try again.';
        }
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

            $this->safeLog('info', "Forgot-password reset completed: {$key}");
            $this->showSuccessModal = true;

        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password savePassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to save password. Please try again.';
        }
    }

    public function goToLogin(): void
    {
        try {
            $subject = $this->getSubjectFromSession();
            if ($subject) {
                $key = $this->subjectKey($subject['role'], $subject['id']);
                cache()->forget($this->cacheOtpVerifiedKey($key));
            }
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password goToLogin error: " . $e->getMessage());
        }
        session()->forget([self::SESSION_STEP_KEY, self::SESSION_SUBJECT_KEY]);
        $this->redirect(route('login'));
    }

    public function restartWizard(): void
    {
        try {
            $subject = $this->getSubjectFromSession();
            if ($subject) {
                $key = $this->subjectKey($subject['role'], $subject['id']);
                cache()->forget($this->cacheOtpVerifiedKey($key));
            }
        } catch (\Throwable $e) {
            $this->safeLog('error', "Forgot-password restartWizard error: " . $e->getMessage());
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

    {{-- Back to Login — Step 1 only, same pattern as change-password.blade.php --}}
    @if ($step === 1)
        <div class="relative z-10 w-full max-w-2xl mb-4">
            <a href="{{ route('login') }}" wire:navigate
               class="inline-flex items-center gap-2 text-white hover:text-white/80 transition-colors font-semibold text-sm group">
                <div class="w-8 h-8 flex items-center justify-center rounded-lg border border-white/30 bg-white/10 group-hover:border-white/60 group-hover:bg-white/20 transition-all shadow-sm">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </div>
                <span>Back to Login</span>
            </a>
        </div>
    @endif

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

    {{-- ══ Main Card — same shell as change-password.blade.php ══ --}}
    <div class="relative z-10 w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Header --}}
        <div class="px-6 sm:px-10 pt-7 pb-6 border-b" style="border-color: #E8E8E8;">
            <div class="flex items-center gap-3 mb-7">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background: #7A3F91;">
                    <i class="fa-solid fa-key text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold" style="color: #333333;">Forgot Password</h1>
                    <p class="text-xs sm:text-sm mt-0.5" style="color: #555555;">PhilCST Alumni Connect</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            @php
                $fpSteps = ['Identity', 'Verify Code', 'New Password'];
            @endphp
            <div class="flex items-center gap-1 sm:gap-0">
                @foreach ($fpSteps as $idx => $label)
                    @php $pos = $idx + 1; $total = count($fpSteps); @endphp
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

            {{-- Alerts — same style as change-password.blade.php.
                 NOTE: $errorMessage is intentionally worded generically
                 whenever it concerns account lookup (see verifyAndSend()):
                 it never confirms or denies whether a specific Student ID
                 / username exists, or which of the two fields was wrong.
                 That's what keeps this screen from being an account
                 enumeration tool. --}}
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

            {{-- ══ STEP 1 — Identity ══ --}}
            @if ($step === 1)
                <div class="space-y-5" x-data="sendLockTimer($wire.entangle('sendLockedUntilMs'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Verify Your Identity</h2>
                        <p class="text-sm mt-1" style="color: #555555;">Enter your account details and we'll send a verification code to your registered email.</p>
                    </div>

                    {{-- Locked-out notice, same pattern as change-password.blade.php --}}
                    <div wire:ignore x-show="locked" x-cloak
                         class="rounded-lg border px-4 py-3 flex items-start gap-3" style="background:#FEF2F2; border-color:#FECACA;">
                        <i class="fa-solid fa-lock text-red-500 mt-0.5 flex-shrink-0 text-sm"></i>
                        <p class="text-xs sm:text-sm font-medium text-red-700">
                            Too many attempts. Please wait
                            <span class="font-mono font-bold" x-text="formattedTime"></span>
                            before trying again.
                        </p>
                    </div>

                    <form wire:submit.prevent="verifyAndSend" class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color: #333333;">
                                Username
                            </label>
                            <input type="text" wire:model="identifier"
                                   {{ $sendLocked ? 'disabled' : '' }}
                                   class="w-full px-4 py-3 text-sm border rounded-lg focus:outline-none transition-all"
                                   style="{{ $sendLocked ? 'background:#F5F5F5; border-color:#E8E8E8; color:#999999;' : 'border-color: #E8E8E8; color: #333333; background: #FFFFFF;' }}"
                                   onfocus="if(!this.disabled)this.style.borderColor='#7A3F91'; if(!this.disabled)this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                   onblur="if(!this.disabled)this.style.borderColor='#E8E8E8'; if(!this.disabled)this.style.boxShadow='none';"
                                   placeholder="Enter your username">
                            {{-- Only shown for a genuinely empty field — never
                                 confirms whether the value itself is valid. --}}
                            @if ($identifierError)
                                <p class="mt-1.5 text-xs font-medium" style="color:#DC2626;">{{ $identifierError }}</p>
                            @endif
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color: #333333;">
                                Registered Email Address
                            </label>
                            <input type="email" wire:model="email"
                                   {{ $sendLocked ? 'disabled' : '' }}
                                   class="w-full px-4 py-3 text-sm border rounded-lg focus:outline-none transition-all"
                                   style="{{ $sendLocked ? 'background:#F5F5F5; border-color:#E8E8E8; color:#999999;' : 'border-color: #E8E8E8; color: #333333; background: #FFFFFF;' }}"
                                   onfocus="if(!this.disabled)this.style.borderColor='#7A3F91'; if(!this.disabled)this.style.boxShadow='0 0 0 3px rgba(122,63,145,0.08)';"
                                   onblur="if(!this.disabled)this.style.borderColor='#E8E8E8'; if(!this.disabled)this.style.boxShadow='none';"
                                   placeholder="you@example.com">
                            {{-- Only shown for empty / malformed email —
                                 never for "doesn't match our records". --}}
                            @if ($emailError)
                                <p class="mt-1.5 text-xs font-medium" style="color:#DC2626;">{{ $emailError }}</p>
                            @endif
                        </div>

                        <button type="submit"
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
                                <span style="font-size:0.78rem; letter-spacing:0.16em;">Sending</span>
                            </span>
                        </button>
                    </form>
                </div>
            @endif

            {{-- ══ STEP 2 — Verify OTP ══ --}}
            @if ($step === 2)
                <div class="space-y-5" x-data="otpTimer($wire.entangle('otpExpiresAtMs'), $wire.entangle('resendAvailableAtMs'), $wire.entangle('otpLocked'))">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Enter Verification Code</h2>
                        <p class="text-sm mt-1" style="color: #555555;">A 6-digit code was sent to <strong style="color: #7A3F91;">{{ $maskedEmail }}</strong></p>
                    </div>

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
                                    <i class="fa-solid fa-shield-halved mr-1" style="color: #7A3F91;"></i>Max 5 attempts
                                </p>
                            @endif
                        </div>

                        <div class="flex flex-col justify-center">
                            <label class="block text-sm font-semibold mb-2" style="color: #333333;">6-Digit Code</label>
                            <input wire:model="otp"
                                   wire:keydown.enter="verifyOtp"
                                   x-on:input="$event.target.value = $event.target.value.replace(/[^0-9]/g, ''); otpLen = $event.target.value.length"
                                   type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                                   autocomplete="one-time-code"
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
                                <p class="text-sm font-bold text-red-700">Verification Locked</p>
                                <p class="text-xs text-red-600">Too many incorrect attempts. Request a new code to continue.</p>
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
                            <span x-show="!canResend" wire:ignore>Resend in <span class="font-bold" x-text="resendFormattedTime"></span></span>
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

                    <button type="button" wire:click="restartWizard" class="w-full text-xs text-center py-1" style="color:#9CA3AF;">
                        Start over
                    </button>
                </div>
            @endif

            {{-- ══ STEP 3 — New Password ══ --}}
            @if ($step === 3)
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg sm:text-xl font-bold" style="color: #333333;">Set Your New Password</h2>
                        <p class="text-sm mt-0.5" style="color: #555555;">Create a strong password to secure your account.</p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-5">
                        {{-- LEFT: password fields --}}
                        <div class="space-y-4">
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

        Alpine.data('otpTimer', (expiresAtMs, resendAtMs, otpLocked) => ({
            expiresAtMs, resendAtMs, otpLocked,
            seconds: 0, resendSeconds: 0, expired: true, canResend: true, otpLen: 0, _interval: null,

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