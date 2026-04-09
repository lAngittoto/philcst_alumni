<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * AlumniPasswordChangeController
 *
 * Handles the first-login "claim your account" wizard for PENDING alumni.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FLOW OVERVIEW                                                          │
 * │                                                                         │
 * │  1. Alumni logs in with their temporary password                        │
 * │     ( format: {student_id}_{Xx}  e.g. "00037801_Ar" )                  │
 * │                                                                         │
 * │  2. EnsureAlumniPasswordChanged middleware detects temp password        │
 * │     → redirects to  GET  alumni.change-password                        │
 * │                                                                         │
 * │  3. STEP 1 — showChangePassword()                                       │
 * │     Alumni enters their real e-mail address                             │
 * │                                                                         │
 * │  4. STEP 2 — sendOtp()                                                  │
 * │     OTP (6-digit) is generated, stored in session, mailed to the        │
 * │     provided address, then alumni is shown the OTP entry form           │
 * │                                                                         │
 * │  5. STEP 3 — verifyOtp()                                                │
 * │     OTP is validated; on success the e-mail is marked verified in       │
 * │     session and alumni is shown the new-password form                   │
 * │                                                                         │
 * │  6. STEP 4 — confirmPassword()                                          │
 * │     New password is saved, alumni.email is set, user.email updated,     │
 * │     alumni.status → VERIFIED, placeholder email removed                 │
 * │                                                                         │
 * │  ROUTES (add to routes/web.php inside the alumni auth middleware group) │
 * │  ─────────────────────────────────────────────────────────────────────  │
 * │  Route::get ('alumni/change-password',          [AlumniPasswordChangeController::class, 'showChangePassword'])->name('alumni.change-password'); │
 * │  Route::post('alumni/send-otp',                 [AlumniPasswordChangeController::class, 'sendOtp'])           ->name('alumni.send-otp');         │
 * │  Route::post('alumni/verify-otp',               [AlumniPasswordChangeController::class, 'verifyOtp'])         ->name('alumni.verify-otp');        │
 * │  Route::post('alumni/confirm-password',         [AlumniPasswordChangeController::class, 'confirmPassword'])   ->name('alumni.confirm-password');  │
 * │  Route::post('alumni/resend-otp',               [AlumniPasswordChangeController::class, 'resendOtp'])         ->name('alumni.resend-otp');         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * SESSION KEYS used across the wizard
 * ────────────────────────────────────
 *  alumni_pw_email          – e-mail address the alumni entered (pending verification)
 *  alumni_pw_otp            – hashed OTP stored server-side
 *  alumni_pw_otp_expires_at – Unix timestamp when OTP expires (10 min window)
 *  alumni_pw_email_verified – true once the OTP has been validated
 *  alumni_pw_attempts       – number of wrong OTP attempts for the current code
 */
class AlumniPasswordChangeController extends Controller
{
    // ─────────────────────────────────────────────────────
    // Constants
    // ─────────────────────────────────────────────────────

    /** OTP lifetime in minutes. */
    private const OTP_TTL_MINUTES = 10;

    /** Maximum wrong OTP attempts before the code is invalidated. */
    private const OTP_MAX_ATTEMPTS = 5;

    /** Minimum password length. */
    private const PASSWORD_MIN_LENGTH = 8;

    // ─────────────────────────────────────────────────────
    // STEP 1 — Show the wizard entry page
    // ─────────────────────────────────────────────────────

    /**
     * GET alumni/change-password
     *
     * Decides which wizard "step" to render:
     *   • No OTP in session          → email entry form  (step 1)
     *   • OTP exists but not verified → OTP entry form   (step 2)
     *   • OTP verified               → new password form (step 3)
     *
     * If the alumni has already completed the wizard (no temp password),
     * they are bounced back to their dashboard.
     */
    public function showChangePassword(Request $request)
    {
        $alumni = $this->resolveAlumni();

        if (!$alumni) {
            Auth::logout();
            return redirect()->route('login')
                ->with('error', '❌ Alumni record not found. Please contact support.');
        }

        // Already set a real password → nothing to do here
        if (!$alumni->hasTemporaryPassword()) {
            return redirect()->route('alumni.dashboard')
                ->with('info', 'ℹ️ Your password has already been updated.');
        }

        // Determine which step to show based on session state
        $step = $this->currentStep($request);

        return view('alumni.change-password', [
            'alumni'         => $alumni,
            'step'           => $step,
            'pendingEmail'   => $request->session()->get('alumni_pw_email'),
            'otpExpiresAt'   => $request->session()->get('alumni_pw_otp_expires_at'),
            'otpTtlMinutes'  => self::OTP_TTL_MINUTES,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // STEP 2 — Send OTP to provided e-mail
    // ─────────────────────────────────────────────────────

    /**
     * POST alumni/send-otp
     *
     * Validates the supplied e-mail, ensures it is not already taken by
     * another user, generates a 6-digit OTP, stores a bcrypt hash of it
     * in session, and sends a plain-text mail.
     */
    public function sendOtp(Request $request)
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->alumniNotFound();
        }

        // Abort if the alumni has already finished the wizard
        if (!$alumni->hasTemporaryPassword()) {
            return redirect()->route('alumni.dashboard');
        }

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($request->input('email')));

        // Reject placeholder / pending-local addresses
        if (str_ends_with($email, '@pending.local')) {
            return back()->with('error', '❌ Please enter a valid personal e-mail address.');
        }

        // Check the email is not already claimed by another user
        // (exclude the current alumni's own placeholder)
        $conflict = \App\Models\User::where('email', $email)
            ->where('id', '!=', Auth::id())
            ->exists();

        if ($conflict) {
            return back()->with('error', '❌ That e-mail address is already registered to another account.');
        }

        // Also check alumni table for the same email
        $conflictAlumni = Alumni::where('email', $email)
            ->where('id', '!=', $alumni->id)
            ->exists();

        if ($conflictAlumni) {
            return back()->with('error', '❌ That e-mail address is already linked to another alumni record.');
        }

        // Generate & store OTP
        $otp    = $this->generateOtp();
        $hashed = Hash::make($otp);
        $expiry = now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp;

        $request->session()->put('alumni_pw_email',          $email);
        $request->session()->put('alumni_pw_otp',            $hashed);
        $request->session()->put('alumni_pw_otp_expires_at', $expiry);
        $request->session()->put('alumni_pw_email_verified', false);
        $request->session()->put('alumni_pw_attempts',       0);

        // Send the OTP mail
        try {
            Mail::raw(
                $this->buildOtpMailBody($alumni, $otp),
                function ($message) use ($email, $alumni) {
                    $message->to($email)
                            ->subject('Your Account Verification Code — ' . config('app.name'));
                }
            );
        } catch (\Exception $e) {
            Log::error("AlumniPasswordChange: OTP mail failed for alumni #{$alumni->id}: " . $e->getMessage());
            // Clear session so the alumni can try again
            $this->clearOtpSession($request);
            return back()->with('error', '❌ We could not send the verification e-mail. Please check the address and try again.');
        }

        Log::info("AlumniPasswordChange: OTP sent to {$email} for alumni #{$alumni->id}");

        return redirect()->route('alumni.change-password')
            ->with('success', "✅ A 6-digit verification code has been sent to {$email}. It expires in " . self::OTP_TTL_MINUTES . ' minutes.');
    }

    // ─────────────────────────────────────────────────────
    // STEP 2b — Resend OTP
    // ─────────────────────────────────────────────────────

    /**
     * POST alumni/resend-otp
     *
     * Clears the previous OTP from session and re-fires sendOtp()
     * using the email already stored in session.
     */
    public function resendOtp(Request $request)
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->alumniNotFound();
        }

        $email = $request->session()->get('alumni_pw_email');
        if (!$email) {
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ Session expired. Please start again.');
        }

        // Inject the stored email back into the request and delegate to sendOtp
        $request->merge(['email' => $email]);
        return $this->sendOtp($request);
    }

    // ─────────────────────────────────────────────────────
    // STEP 3 — Verify OTP
    // ─────────────────────────────────────────────────────

    /**
     * POST alumni/verify-otp
     *
     * Compares the submitted code against the session-stored hash.
     * Tracks wrong attempts; invalidates the code after OTP_MAX_ATTEMPTS.
     */
    public function verifyOtp(Request $request)
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->alumniNotFound();
        }

        $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        // Retrieve session state
        $hashedOtp  = $request->session()->get('alumni_pw_otp');
        $expiry     = $request->session()->get('alumni_pw_otp_expires_at');
        $attempts   = (int) $request->session()->get('alumni_pw_attempts', 0);

        // Guard: OTP must exist in session
        if (!$hashedOtp || !$expiry) {
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ No active verification code found. Please request a new one.');
        }

        // Guard: OTP must not be expired
        if (now()->timestamp > $expiry) {
            $this->clearOtpSession($request);
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ Your verification code has expired. Please request a new one.');
        }

        // Guard: too many wrong attempts
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            $this->clearOtpSession($request);
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ Too many incorrect attempts. Please request a new verification code.');
        }

        // Validate the OTP
        if (!Hash::check($request->input('otp'), $hashedOtp)) {
            $attempts++;
            $request->session()->put('alumni_pw_attempts', $attempts);
            $remaining = self::OTP_MAX_ATTEMPTS - $attempts;

            if ($remaining <= 0) {
                $this->clearOtpSession($request);
                return redirect()->route('alumni.change-password')
                    ->with('error', '❌ Too many incorrect attempts. Please request a new verification code.');
            }

            Log::warning("AlumniPasswordChange: Wrong OTP for alumni #{$alumni->id} (attempt {$attempts})");

            return back()->with('error', "❌ Incorrect verification code. {$remaining} attempt(s) remaining.");
        }

        // OTP is correct — mark e-mail as verified in session
        $request->session()->put('alumni_pw_email_verified', true);
        $request->session()->forget('alumni_pw_otp');         // no longer needed
        $request->session()->forget('alumni_pw_attempts');

        Log::info("AlumniPasswordChange: OTP verified for alumni #{$alumni->id}");

        return redirect()->route('alumni.change-password')
            ->with('success', '✅ E-mail verified! Please set your new password below.');
    }

    // ─────────────────────────────────────────────────────
    // STEP 4 — Save new password & finalise account
    // ─────────────────────────────────────────────────────

    /**
     * POST alumni/confirm-password
     *
     * Validates the new password, updates the User record, sets the real
     * e-mail on both User and Alumni, marks the alumni as VERIFIED, and
     * clears all wizard session keys.
     */
    public function confirmPassword(Request $request)
    {
        $alumni = $this->resolveAlumni();
        if (!$alumni) {
            return $this->alumniNotFound();
        }

        // Guard: e-mail must have been OTP-verified in this wizard session
        if (!$request->session()->get('alumni_pw_email_verified')) {
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ Please verify your e-mail first.');
        }

        $verifiedEmail = $request->session()->get('alumni_pw_email');
        if (!$verifiedEmail) {
            $this->clearAllWizardSession($request);
            return redirect()->route('alumni.change-password')
                ->with('error', '❌ Session data missing. Please restart the process.');
        }

        // Validate password fields
        $request->validate([
            'password' => [
                'required',
                'string',
                'min:' . self::PASSWORD_MIN_LENGTH,
                'confirmed',                      // requires password_confirmation field
                'regex:/[A-Z]/',                  // at least one uppercase letter
                'regex:/[a-z]/',                  // at least one lowercase letter
                'regex:/[0-9]/',                  // at least one digit
            ],
        ], [
            'password.min'     => "Password must be at least " . self::PASSWORD_MIN_LENGTH . " characters.",
            'password.regex'   => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        $newPassword = $request->input('password');

        // Prevent reusing the temporary password
        if (Hash::check($newPassword, $alumni->user->password ?? '')) {
            return back()->with('error', '❌ Your new password cannot be the same as your temporary password.');
        }

        try {
            $user = $alumni->user;

            // Update User record
            $user->update([
                'email'    => $verifiedEmail,
                'password' => Hash::make($newPassword),
            ]);

            // Update Alumni record
            $alumni->update([
                'email'  => $verifiedEmail,
                'status' => 'VERIFIED',
            ]);

            // Clear entire wizard session
            $this->clearAllWizardSession($request);

            Log::info("AlumniPasswordChange: Alumni #{$alumni->id} completed password change. Email set to {$verifiedEmail}");

            return redirect()->route('alumni.dashboard')
                ->with('success', '🎉 Your account has been activated! Welcome, ' . $alumni->first_name . '!');

        } catch (\Exception $e) {
            Log::error("AlumniPasswordChange: Failed to save password for alumni #{$alumni->id}: " . $e->getMessage());
            return back()->with('error', '❌ An unexpected error occurred. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────

    /**
     * Resolve the Alumni record for the currently authenticated user.
     * Returns null when not found (caller must handle gracefully).
     */
    private function resolveAlumni(): ?Alumni
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'alumni') {
            return null;
        }

        return Alumni::where('user_id', $user->id)->first();
    }

    /**
     * Determine which wizard step the alumni is currently on.
     *
     * Step 1 → email entry     (no OTP in session yet)
     * Step 2 → OTP entry       (OTP sent but not verified)
     * Step 3 → password entry  (OTP verified)
     */
    private function currentStep(Request $request): int
    {
        if ($request->session()->get('alumni_pw_email_verified') === true) {
            return 3;
        }

        if ($request->session()->has('alumni_pw_otp') || $request->session()->has('alumni_pw_email')) {
            return 2;
        }

        return 1;
    }

    /**
     * Generate a cryptographically random 6-digit OTP string.
     */
    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Build the plain-text body of the OTP notification e-mail.
     */
    private function buildOtpMailBody(Alumni $alumni, string $otp): string
    {
        $appName = config('app.name', 'Alumni Portal');
        $ttl     = self::OTP_TTL_MINUTES;
        $name    = $alumni->getFullName();

        return <<<TEXT
Hello, {$name}!

You are receiving this message because you are setting up your alumni account on {$appName}.

Your one-time verification code is:

  {$otp}

This code expires in {$ttl} minutes. Do not share it with anyone.

If you did not request this, please contact support immediately.

— {$appName} Team
TEXT;
    }

    /**
     * Clear only the OTP-related session keys (keeps the email key so the
     * alumni can resend an OTP without re-entering their address).
     */
    private function clearOtpSession(Request $request): void
    {
        $request->session()->forget([
            'alumni_pw_otp',
            'alumni_pw_otp_expires_at',
            'alumni_pw_email_verified',
            'alumni_pw_attempts',
        ]);
    }

    /**
     * Clear every wizard session key after the wizard is complete (or aborted).
     */
    private function clearAllWizardSession(Request $request): void
    {
        $request->session()->forget([
            'alumni_pw_email',
            'alumni_pw_otp',
            'alumni_pw_otp_expires_at',
            'alumni_pw_email_verified',
            'alumni_pw_attempts',
        ]);
    }

    /**
     * Shared response for when the alumni record cannot be resolved.
     */
    private function alumniNotFound()
    {
        Auth::logout();
        return redirect()->route('login')
            ->with('error', '❌ Alumni record not found. Please contact support.');
    }
}