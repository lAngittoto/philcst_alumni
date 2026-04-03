{{-- resources/views/livewire/organizer/change-password.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Mail\OrganizerPasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    public int $step = 1;
    public string $password = '';
    public string $password_confirmation = '';
    public string $passwordStrength = 'weak';
    public string $otp = '';
    public bool $otpSent = false;
    public bool $otpVerified = false;
    public string $errorMessage = '';
    public string $successMessage = '';
    public bool $showPassword = false;
    public bool $showConfirmPassword = false;

    public function mount(): void
    {
        $user = auth()->user();

        if (!$user || $user->role !== 'organizer') {
            $this->redirect(route('login'));
            return;
        }

        $organizer = $user->organizer;

        if (!$organizer) {
            $this->redirect(route('login'));
            return;
        }

        if ($organizer->password_changed_at !== null) {
            $this->redirect(route('organizer.dashboard'));
            return;
        }

        if (!session()->has('organizer_requires_password_change')) {
            auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->redirect(route('login'));
            return;
        }

        $resetStep = session('password_reset_step');
        $pendingPassword = session('pending_password_plain');

        if ($resetStep === 'otp_verification' && $pendingPassword && $organizer->otp) {
            $this->step = 2;
            $this->otpSent = true;
            // Dispatch so Alpine can pick it up if timer storage is missing
            $this->dispatch('otp-sent');
        } elseif ($resetStep === 'password_confirmed' && $pendingPassword) {
            $this->step = 3;
            $this->otpVerified = true;
        } else {
            $this->step = 1;
            session()->forget(['pending_password_plain', 'password_reset_step']);
        }
    }

    public function updatedPassword(): void
    {
        $this->updatePasswordStrength();
    }

    public function updatedPasswordConfirmation(): void
    {
        // Real-time update
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

        $this->passwordStrength = match(true) {
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
        return $this->password !== '' && $this->password_confirmation !== '' && $this->password === $this->password_confirmation;
    }

    public function canSendOtp(): bool
    {
        return $this->isPasswordStrengthValid() && $this->isPasswordsMatching();
    }

    public function sendOtp(): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        if (!in_array($this->passwordStrength, ['good', 'strong'])) {
            $this->errorMessage = 'Password strength must be "Good" or "Strong". Add uppercase, numbers, and special characters.';
            return;
        }

        $this->validate([
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        $organizer = auth()->user()->organizer;
        if (!$organizer) {
            $this->errorMessage = 'Organizer record not found.';
            return;
        }

        try {
            $otp = $organizer->generateOtp();

            try {
                Mail::to($organizer->email)->send(new OrganizerPasswordReset($organizer, $otp));
                Log::info("OTP email sent to: {$organizer->email}");
            } catch (\Exception $e) {
                Log::warning("OTP mail failed for {$organizer->email}: " . $e->getMessage());
            }

            session()->put('pending_password_plain', $this->password);
            session()->put('password_reset_step', 'otp_verification');
            cache()->forget('otp_attempts:' . $organizer->id);
            cache()->forget('otp_locked:' . $organizer->id);
            cache()->forget('otp_locked_time:' . $organizer->id);

            $this->step = 2;
            $this->otpSent = true;
            $this->successMessage = 'Verification code sent to your email. Please check your inbox.';

            // Dispatch event to start the 10-minute timer (fresh start)
            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send code. Please try again.';
        }
    }

    public function verifyOtp(): void
    {
        $this->errorMessage = '';
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

        $organizer = auth()->user()->organizer;
        if (!$organizer) {
            $this->errorMessage = 'Organizer record not found.';
            return;
        }

        if (session('password_reset_step') !== 'otp_verification') {
            $this->errorMessage = 'Invalid session. Please start over.';
            $this->step = 1;
            return;
        }

        try {
            $cacheKey = 'otp_attempts:' . $organizer->id;
            $attempts = cache()->get($cacheKey, 0);

            if (cache()->has('otp_locked:' . $organizer->id)) {
                $remainingTime = cache()->get('otp_locked_time:' . $organizer->id, 3600);
                $this->errorMessage = "Account locked due to multiple failed attempts. Please try again in {$remainingTime} seconds.";
                return;
            }

            if ($attempts >= 5) {
                cache()->put('otp_locked:' . $organizer->id, true, 300);
                cache()->put('otp_locked_time:' . $organizer->id, 300, 300);
                $this->errorMessage = 'Too many failed attempts. Account locked for 5 minutes.';
                return;
            }

            if (!$organizer->isOtpValid($this->otp)) {
                $newAttempts = $attempts + 1;
                cache()->put($cacheKey, $newAttempts, 600);

                $remainingAttempts = 5 - $newAttempts;

                if ($remainingAttempts <= 0) {
                    cache()->put('otp_locked:' . $organizer->id, true, 300);
                    cache()->put('otp_locked_time:' . $organizer->id, 300, 300);
                    $this->errorMessage = 'Too many failed attempts. Account locked for 5 minutes.';
                } else {
                    $this->errorMessage = "Invalid code. You have {$remainingAttempts} attempt(s) left.";
                }
                return;
            }

            cache()->forget($cacheKey);
            $organizer->clearOtp();
            session()->put('password_reset_step', 'password_confirmed');

            $this->step = 3;
            $this->otpVerified = true;
            $this->successMessage = 'Verification successful! Click below to confirm your new password.';

        } catch (\Exception $e) {
            Log::error("verifyOtp error: " . $e->getMessage());
            $this->errorMessage = 'Verification failed. Please try again.';
        }
    }

    public function confirmPassword(): void
    {
        $this->errorMessage = '';

        $user = auth()->user();
        $organizer = $user->organizer;

        if (!$organizer) {
            $this->errorMessage = 'Organizer record not found.';
            return;
        }

        $pendingPassword = session('pending_password_plain');
        $resetStep = session('password_reset_step');

        if (!$pendingPassword || $resetStep !== 'password_confirmed') {
            $this->errorMessage = 'Invalid session. Please start over.';
            $this->step = 1;
            session()->forget(['pending_password_plain', 'password_reset_step']);
            return;
        }

        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['password' => Hash::make($pendingPassword)]);

            $organizer->markPasswordChanged();

            session()->forget([
                'pending_password_plain',
                'password_reset_step',
                'organizer_requires_password_change',
            ]);

            Log::info("Password changed for organizer: {$organizer->email}");
            $this->redirect(route('organizer.dashboard'));

        } catch (\Exception $e) {
            Log::error("confirmPassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to update password. Please try again.';
        }
    }

    public function resendOtp(): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $organizer = auth()->user()->organizer;
        if (!$organizer) {
            $this->errorMessage = 'Organizer record not found.';
            return;
        }

        if (!session()->has('pending_password_plain')) {
            $this->errorMessage = 'Session expired. Please go back and try again.';
            $this->step = 1;
            return;
        }

        try {
            $otp = $organizer->generateOtp();

            try {
                Mail::to($organizer->email)->send(new OrganizerPasswordReset($organizer, $otp));
                Log::info("Resend OTP email sent to: {$organizer->email}");
            } catch (\Exception $e) {
                Log::warning("Resend OTP mail failed: " . $e->getMessage());
            }

            session()->put('password_reset_step', 'otp_verification');
            cache()->forget('otp_attempts:' . $organizer->id);
            cache()->forget('otp_locked:' . $organizer->id);
            cache()->forget('otp_locked_time:' . $organizer->id);

            $this->otp = '';
            $this->successMessage = 'New verification code sent to your email.';

            // Dispatch fresh start event — clears localStorage and restarts
            $this->dispatch('otp-sent-fresh');

        } catch (\Exception $e) {
            Log::error("resendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to resend code. Please try again.';
        }
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
            $this->errorMessage = '';
            $this->successMessage = '';
            $this->otp = '';

            if ($this->step === 1) {
                session()->forget(['pending_password_plain', 'password_reset_step']);
            }
        }
    }

}; ?>

<div
    class="min-h-screen w-full flex flex-col items-center justify-center p-4 font-sans antialiased"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
    x-data="otpTimer()"
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
    <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Top bar with step indicator --}}
        <div class="px-6 pt-6 pb-5 border-b border-gray-200">

            {{-- Title --}}
            <div class="flex items-center gap-3 mb-5">
                <div class="w-11 h-11 rounded-xl bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-key text-[#7a3f91] text-base"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Change Password</h1>
                    <p class="text-sm text-gray-600 mt-0.5">Create your new secure password</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            <div class="flex items-center gap-1">
                @foreach ([1 => 'Password', 2 => 'Verify', 3 => 'Confirm'] as $i => $label)
                    <div class="flex items-center {{ $i < 3 ? 'flex-1' : '' }}">
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold transition-all
                                {{ $i == $step ? 'bg-[#7a3f91] text-white' : ($i < $step ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500') }}">
                                @if ($i < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $i }}
                                @endif
                            </div>
                            <span class="text-sm font-semibold hidden sm:inline
                                {{ $i == $step ? 'text-gray-800' : ($i < $step ? 'text-emerald-600' : 'text-gray-500') }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($i < 3)
                            <div class="flex-1 mx-2 h-px {{ $i < $step ? 'bg-emerald-400' : 'bg-gray-300' }}"></div>
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

            {{-- ═══ STEP 1 ═══ --}}
            @if ($step == 1)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Create New Password</h2>
                        <p class="text-sm text-gray-700 mt-1">Minimum 8 characters - "Good" or "Strong" strength required</p>
                    </div>

                    {{-- Requirements Checklist --}}
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

                    {{-- Password field --}}
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

                    {{-- Strength Indicator --}}
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

                    {{-- Confirm Password --}}
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

                    {{-- Send OTP Button --}}
                    <button
                        wire:click="sendOtp"
                        wire:loading.attr="disabled"
                        wire:target="sendOtp"
                        {{ !$this->canSendOtp() ? 'disabled' : '' }}
                        class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none transition-all flex items-center justify-center gap-2 mt-2">
                        <span wire:loading.remove wire:target="sendOtp">
                            <i class="fa-solid fa-paper-plane mr-1"></i> Send Verification Code
                        </span>
                        <span wire:loading wire:target="sendOtp">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending…
                        </span>
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 2 ═══ --}}
            @if ($step == 2)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Verify Your Email</h2>
                        <p class="text-sm text-gray-700 mt-1">Enter the 6-digit code we sent to your email address</p>
                    </div>

                    {{-- Countdown Timer --}}
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl p-6 text-center">
                        <p class="text-xs font-semibold text-gray-700 uppercase tracking-widest mb-2">Code expires in</p>
                        <div
                            class="text-5xl font-bold font-mono tabular-nums transition-colors duration-300"
                            :class="seconds <= 60 ? 'text-red-600' : 'text-[#7a3f91]'"
                            x-text="formattedTime">
                            10:00
                        </div>
                        <p x-show="expired" x-cloak class="text-red-700 text-sm mt-3 font-semibold">
                            <i class="fa-solid fa-triangle-exclamation mr-2"></i> Code has expired. Please request a new one.
                        </p>
                    </div>

                    {{-- OTP Input --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-2">6-Digit Code</label>
                        <input wire:model.live="otp"
                               type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                               placeholder="000000" autocomplete="one-time-code"
                               class="w-full px-4 py-4 text-center text-4xl font-bold tracking-[0.5em] border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/20 transition-all bg-white text-gray-900">
                    </div>

                    {{-- Action Buttons --}}
                    <div class="space-y-3 pt-2">
                        {{-- Verify Button — disabled when expired --}}
                        <button
                            wire:click="verifyOtp"
                            wire:loading.attr="disabled"
                            wire:target="verifyOtp"
                            x-bind:disabled="expired"
                            :class="expired ? 'bg-gray-400 cursor-not-allowed' : 'bg-[#7a3f91] hover:bg-[#6a3080] hover:shadow-lg active:scale-[0.98]'"
                            class="w-full text-white py-3 rounded-xl font-semibold text-base shadow-md disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="verifyOtp">
                                <i class="fa-solid fa-shield-halved mr-1"></i> Verify Code
                            </span>
                            <span wire:loading wire:target="verifyOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                            </span>
                        </button>

                        {{-- Resend Button — only clickable when timer is done --}}
                        <button
                            wire:click="resendOtp"
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

                        {{-- Info text --}}
                        <p x-show="!canResend" x-cloak class="text-xs text-gray-500 text-center">
                            Wait for the timer to finish before requesting a new code
                        </p>
                    </div>

                    {{-- Back Button --}}
                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-700 py-2 text-center hover:text-[#7a3f91] hover:bg-gray-50 rounded-lg transition-colors font-medium">
                        ← Back to Change Password
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 3 ═══ --}}
            @if ($step == 3)
                <div class="space-y-6 text-center py-4">
                    <div class="flex justify-center">
                        <div class="w-20 h-20 bg-emerald-100 rounded-2xl flex items-center justify-center animate-bounce">
                            <i class="fa-solid fa-check text-4xl text-emerald-600"></i>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-2">All Set!</h2>
                        <p class="text-base text-gray-700 leading-relaxed">Your identity has been verified. Click below to save your new password and complete the setup.</p>
                    </div>
                    <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4 text-left space-y-2.5">
                        @foreach (['Password will be secured with encryption', 'You will be taken to your dashboard', 'Use your new password for all future logins'] as $item)
                            <div class="flex items-start gap-2.5">
                                <i class="fa-solid fa-circle-check text-[#7a3f91] text-base mt-0.5 flex-shrink-0"></i>
                                <span class="text-sm text-gray-800 leading-relaxed">{{ $item }}</span>
                            </div>
                        @endforeach
                    </div>
                    <button wire:click="confirmPassword"
                            wire:loading.attr="disabled"
                            wire:target="confirmPassword"
                            class="w-full bg-emerald-500 hover:bg-emerald-600 text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:opacity-70 transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="confirmPassword">
                            <i class="fa-solid fa-arrow-right mr-1"></i> Complete Setup & Go to Dashboard
                        </span>
                        <span wire:loading wire:target="confirmPassword">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…
                        </span>
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
        /**
         * OTP Timer — persists across page refreshes via localStorage.
         *
         * localStorage keys:
         *   otp_timer_expiry  — Unix timestamp (ms) when the code expires
         *
         * Flow:
         *   startFresh()      — called when a NEW otp is sent (sets expiry = now + 10min, saves to storage)
         *   restoreOrStart()  — called on mount when step == 2 (restores from storage if still valid)
         *   initTimer(step)   — x-init entry point
         */
        function otpTimer() {
            const STORAGE_KEY = 'otp_timer_expiry';
            const DURATION_MS = 10 * 60 * 1000; // 10 minutes

            return {
                seconds: 600,
                expired: false,
                canResend: false,
                _interval: null,

                // ─── Computed ─────────────────────────────────────────────
                get formattedTime() {
                    const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                    const s = String(this.seconds % 60).padStart(2, '0');
                    return `${m}:${s}`;
                },

                // ─── Init (called by x-init) ──────────────────────────────
                initTimer(step) {
                    if (step === 2) {
                        this.restoreOrStart();
                    }
                },

                // ─── Called when Livewire dispatches otp-sent-fresh ───────
                // (new OTP sent or resend clicked)
                startFresh() {
                    const expiry = Date.now() + DURATION_MS;
                    localStorage.setItem(STORAGE_KEY, expiry.toString());
                    this._beginCountdown(expiry);
                },

                // ─── Called on page refresh / mount when step == 2 ───────
                // Restores the existing timer from localStorage, or starts fresh
                // if somehow storage is missing (fallback).
                restoreOrStart() {
                    const stored = localStorage.getItem(STORAGE_KEY);
                    if (stored) {
                        const expiry = parseInt(stored, 10);
                        const remaining = Math.floor((expiry - Date.now()) / 1000);

                        if (remaining > 0) {
                            // Timer is still running — restore it
                            this.seconds = remaining;
                            this.expired = false;
                            this.canResend = false;
                            this._tick(expiry);
                        } else {
                            // Timer already expired before page was refreshed
                            this.seconds = 0;
                            this.expired = true;
                            this.canResend = true;
                            localStorage.removeItem(STORAGE_KEY);
                        }
                    } else {
                        // No storage found — start fresh as fallback
                        this.startFresh();
                    }
                },

                // ─── Internal: set expiry and start interval ──────────────
                _beginCountdown(expiry) {
                    this._clearInterval();
                    this.seconds = Math.max(0, Math.floor((expiry - Date.now()) / 1000));
                    this.expired = false;
                    this.canResend = false;
                    this._tick(expiry);
                },

                // ─── Internal: tick every second using expiry timestamp ───
                // Using the stored expiry timestamp (not decrement) means
                // refreshing the page will always show the correct remaining time.
                _tick(expiry) {
                    this._interval = setInterval(() => {
                        const remaining = Math.floor((expiry - Date.now()) / 1000);

                        if (remaining > 0) {
                            this.seconds = remaining;
                        } else {
                            this.seconds = 0;
                            this.expired = true;
                            this.canResend = true;
                            localStorage.removeItem(STORAGE_KEY);
                            this._clearInterval();
                        }
                    }, 500); // 500ms for snappier UI updates
                },

                // ─── Internal: clear any running interval ─────────────────
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