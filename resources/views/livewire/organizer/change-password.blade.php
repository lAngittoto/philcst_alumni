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

        if (!$organizer || $organizer->password_changed_at !== null) {
            $this->redirect(route('organizer.dashboard'));
            return;
        }

        if (session()->has('password_reset_step')) {
            $step = session('password_reset_step');
            if ($step === 'otp_verification') {
                $this->step    = 2;
                $this->otpSent = true;
            } elseif ($step === 'password_confirmed') {
                $this->step        = 3;
                $this->otpVerified = true;
            }
        }
    }

    public function updatedPassword(): void
    {
        $this->updatePasswordStrength();
    }

    public function updatePasswordStrength(): void
    {
        $pwd = $this->password;

        if (strlen($pwd) < 8) { $this->passwordStrength = 'weak'; return; }

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
            'good'   => ['label' => 'Good',   'color' => 'text-yellow-600', 'progressColor' => 'bg-yellow-500', 'width' => 'w-3/4'],
            'strong' => ['label' => 'Strong', 'color' => 'text-green-600',  'progressColor' => 'bg-green-500',  'width' => 'w-full'],
        };
    }

    // ── STEP 1 ──────────────────────────────────────────────────────────────

    public function sendOtp(): void
    {
        $this->errorMessage  = '';
        $this->successMessage = '';

        $this->validate([
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.min'               => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        if (!in_array($this->passwordStrength, ['good', 'strong'])) {
            $this->errorMessage = 'Password must be at least "Good" strength. Add uppercase, numbers, and special characters.';
            return;
        }

        $organizer = auth()->user()->organizer;
        if (!$organizer) { $this->errorMessage = 'Organizer record not found.'; return; }

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

            $this->step           = 2;
            $this->otpSent        = true;
            $this->successMessage = 'OTP sent to your registered email. Enter the 6-digit code below.';

            // Fire event → Alpine resets and starts countdown
            $this->dispatch('otp-sent');

        } catch (\Exception $e) {
            Log::error("sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send OTP. Please try again.';
        }
    }

    // ── STEP 2 ──────────────────────────────────────────────────────────────

    public function verifyOtp(): void
    {
        $this->errorMessage  = '';
        $this->successMessage = '';

        $this->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'otp.regex' => 'OTP must be exactly 6 digits.',
        ]);

        $organizer = auth()->user()->organizer;
        if (!$organizer) { $this->errorMessage = 'Organizer record not found.'; return; }

        if (session('password_reset_step') !== 'otp_verification') {
            $this->errorMessage = 'Invalid session state. Please start over.';
            $this->step = 1;
            return;
        }

        try {
            if (!$organizer->isOtpValid($this->otp)) {
                $this->errorMessage = 'Invalid or expired OTP. Please try again or request a new code.';
                return;
            }

            $organizer->clearOtp();
            session()->put('password_reset_step', 'password_confirmed');

            $this->step           = 3;
            $this->otpVerified    = true;
            $this->successMessage = 'Identity verified! Click the button below to finalize your new password.';

        } catch (\Exception $e) {
            Log::error("verifyOtp error: " . $e->getMessage());
            $this->errorMessage = 'Verification failed. Please try again.';
        }
    }

    // ── STEP 3 ──────────────────────────────────────────────────────────────

    public function confirmPassword(): void
    {
        $this->errorMessage = '';

        $user      = auth()->user();
        $organizer = $user->organizer;

        if (!$organizer) { $this->errorMessage = 'Organizer record not found.'; return; }

        $pendingPassword = session('pending_password_plain');
        $resetStep       = session('password_reset_step');

        if (!$pendingPassword || $resetStep !== 'password_confirmed') {
            $this->errorMessage = 'Invalid session. Please start the process again.';
            $this->step = 1;
            session()->forget(['pending_password_plain', 'password_reset_step']);
            return;
        }

        try {
            // Bypass model cast — hash once, write raw
            DB::table('users')
                ->where('id', $user->id)
                ->update(['password' => Hash::make($pendingPassword)]);

            $organizer->markPasswordChanged();

            session()->forget(['pending_password_plain', 'password_reset_step']);

            Log::info("Password changed for organizer: {$organizer->email}");

            $this->redirect(route('organizer.dashboard'));

        } catch (\Exception $e) {
            Log::error("confirmPassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to update password. Please try again.';
        }
    }

    // ── RESEND ───────────────────────────────────────────────────────────────

    public function resendOtp(): void
    {
        $this->errorMessage  = '';
        $this->successMessage = '';

        $organizer = auth()->user()->organizer;
        if (!$organizer) { $this->errorMessage = 'Organizer record not found.'; return; }

        if (!session()->has('pending_password_plain')) {
            $this->errorMessage = 'Session expired. Please go back and enter your password again.';
            $this->step = 1;
            return;
        }

        try {
            $otp = $organizer->generateOtp();

            try {
                Mail::to($organizer->email)->send(new OrganizerPasswordReset($organizer, $otp));
            } catch (\Exception $e) {
                Log::warning("Resend OTP mail failed: " . $e->getMessage());
            }

            session()->put('password_reset_step', 'otp_verification');
            $this->otp            = '';
            $this->successMessage = 'A new OTP has been sent to your email.';

            // Fire event → Alpine clears old interval and restarts from 10:00
            $this->dispatch('otp-sent');

        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to resend OTP. Please try again.';
        }
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
            $this->errorMessage   = '';
            $this->successMessage = '';
            $this->otp            = '';

            if ($this->step === 1) {
                session()->forget(['pending_password_plain', 'password_reset_step']);
            }
        }
    }

}; ?>

<div
    class="min-h-screen w-full flex flex-col items-center justify-center p-6 md:p-10 font-sans antialiased"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
    x-data="otpTimer()"
    x-on:otp-sent.window="startCountdown()"
>
    <div class="absolute inset-0 bg-black/40 z-0"></div>

    <a href="/" wire:navigate
       class="fixed top-8 left-8 z-50 flex items-center gap-3 text-white hover:text-purple-200 transition-all group">
        <div class="w-12 h-12 flex items-center justify-center rounded-full border-2 border-white/30 group-hover:border-white group-hover:bg-white/10 transition-all">
            <i class="fa-solid fa-arrow-left text-lg"></i>
        </div>
        <span class="font-bold uppercase text-xs tracking-widest">Back to Home</span>
    </a>

    <div class="relative z-10 w-full max-w-2xl bg-white rounded-3xl shadow-2xl overflow-hidden">

        {{-- ── Header / Step Indicator ── --}}
        <div class="bg-gradient-to-r from-[#7a3f91] to-[#2b0d3e] text-white p-8 md:p-10">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-white/20 rounded-2xl mb-4">
                    <i class="fa-solid fa-key text-4xl"></i>
                </div>
                <h1 class="text-3xl font-bold mb-1">Change Your Password</h1>
                <p class="text-white/80 text-sm">Complete this wizard to set your new password</p>
            </div>

            <div class="flex items-center gap-2">
                @foreach ([1 => 'New Password', 2 => 'Verify OTP', 3 => 'Confirm'] as $i => $label)
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all flex-shrink-0
                                {{ $i == $step ? 'bg-white text-[#7a3f91]' : ($i < $step ? 'bg-white/40 text-white' : 'bg-white/20 text-white/50') }}">
                                @if ($i < $step) <i class="fa-solid fa-check"></i>
                                @else {{ $i }} @endif
                            </div>
                            <span class="text-sm font-semibold hidden md:inline {{ $i == $step ? 'text-white' : 'text-white/50' }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($i < 3)
                            <div class="h-1 rounded {{ $i < $step ? 'bg-white' : 'bg-white/20' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Body ── --}}
        <div class="p-8 md:p-10">

            @if ($errorMessage)
                <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg text-red-700 flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation text-xl mt-0.5 flex-shrink-0"></i>
                    <div><p class="font-bold">Error</p><p class="text-sm mt-1">{{ $errorMessage }}</p></div>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg text-green-700 flex items-start gap-3">
                    <i class="fa-solid fa-circle-check text-xl mt-0.5 flex-shrink-0"></i>
                    <div><p class="font-bold">Success</p><p class="text-sm mt-1">{{ $successMessage }}</p></div>
                </div>
            @endif

            {{-- ════ STEP 1 ════ --}}
            @if ($step == 1)
                <div class="space-y-6">
                    <div class="text-center mb-4">
                        <h2 class="text-2xl font-bold text-[#2b0d3e] mb-1">Create Your New Password</h2>
                        <p class="text-gray-500 text-sm">Must be at least 8 characters with good strength</p>
                    </div>

                    <div class="bg-blue-50 p-4 rounded-xl border border-blue-200 space-y-1.5 text-sm text-blue-800">
                        <p class="font-bold text-blue-900 mb-2">Requirements:</p>
                        @foreach ([
                            [strlen($password) >= 8,                        'At least 8 characters'],
                            [preg_match('/[A-Z]/', $password),              'One uppercase letter (A–Z)'],
                            [preg_match('/[a-z]/', $password),              'One lowercase letter (a–z)'],
                            [preg_match('/[0-9]/', $password),              'One number (0–9)'],
                            [preg_match('/[!@#$%^&*?]/', $password),        'One special character (!@#$%^&*?)'],
                        ] as [$met, $text])
                            <div class="flex items-center gap-2">
                                <i class="fa-solid text-base {{ $met ? 'fa-check text-green-600' : 'fa-circle text-gray-300' }}"></i>
                                <span class="{{ $met ? 'font-semibold text-green-800' : '' }}">{{ $text }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-[#2b0d3e] mb-2">New Password</label>
                        <div class="relative">
                            <input wire:model.live="password"
                                   type="{{ $showPassword ? 'text' : 'password' }}"
                                   placeholder="Enter new password" autocomplete="new-password"
                                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all pr-12">
                            <button type="button" wire:click="$toggle('showPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#7a3f91]">
                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-lg"></i>
                            </button>
                        </div>
                    </div>

                    @if ($password !== '')
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Strength</span>
                                <span class="text-sm font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">
                                    {{ $this->getPasswordStrengthInfo()['label'] }}
                                </span>
                            </div>
                            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500 {{ $this->getPasswordStrengthInfo()['progressColor'] }} {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-bold text-[#2b0d3e] mb-2">Confirm Password</label>
                        <div class="relative">
                            <input wire:model="password_confirmation"
                                   type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                   placeholder="Re-enter password" autocomplete="new-password"
                                   class="w-full px-4 py-3 border-2 rounded-xl focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all pr-12
                                       {{ $password_confirmation !== '' && $password !== $password_confirmation ? 'border-red-400' : ($password_confirmation !== '' && $password === $password_confirmation ? 'border-green-400' : 'border-gray-300') }}">
                            <button type="button" wire:click="$toggle('showConfirmPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#7a3f91]">
                                <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-lg"></i>
                            </button>
                        </div>
                        @if ($password_confirmation !== '' && $password !== $password_confirmation)
                            <p class="text-xs text-red-500 mt-1"><i class="fa-solid fa-xmark mr-1"></i>Passwords do not match</p>
                        @elseif ($password_confirmation !== '' && $password === $password_confirmation)
                            <p class="text-xs text-green-600 mt-1"><i class="fa-solid fa-check mr-1"></i>Passwords match</p>
                        @endif
                    </div>

                    <button wire:click="sendOtp" wire:loading.attr="disabled" wire:target="sendOtp"
                            class="w-full bg-gradient-to-r from-[#7a3f91] to-[#2b0d3e] text-white py-4 rounded-xl font-bold uppercase tracking-wide shadow-lg hover:from-[#8a4fa1] hover:to-[#3b1d4e] active:scale-95 disabled:opacity-60 transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="sendOtp"><i class="fa-solid fa-paper-plane mr-1"></i> Send OTP to Email</span>
                        <span wire:loading wire:target="sendOtp"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending...</span>
                    </button>
                </div>
            @endif

            {{-- ════ STEP 2 ════ --}}
            @if ($step == 2)
                <div class="space-y-6">
                    <div class="text-center mb-4">
                        <h2 class="text-2xl font-bold text-[#2b0d3e] mb-1">Verify Your Email</h2>
                        <p class="text-gray-500 text-sm">Enter the 6-digit code sent to your registered email</p>
                    </div>

                    {{-- Alpine-driven countdown — resets on every 'otp-sent' event --}}
                    <div class="bg-purple-50 p-5 rounded-xl border border-purple-200 text-center">
                        <p class="text-sm text-purple-800 font-semibold mb-3">Code expires in:</p>
                        <div class="text-5xl font-bold font-mono text-[#7a3f91]" x-text="formattedTime">10:00</div>
                        <p class="text-xs text-purple-600 mt-2">minutes : seconds</p>
                        <p x-show="expired" x-cloak class="text-red-600 font-bold text-sm mt-3">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> OTP expired — request a new one below.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-[#2b0d3e] mb-2">Enter 6-Digit Code</label>
                        <input wire:model="otp"
                               type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                               placeholder="000000" autocomplete="one-time-code"
                               class="w-full px-4 py-4 text-center text-3xl font-bold tracking-[0.5em] border-2 border-gray-300 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-4 focus:ring-purple-100 transition-all">
                    </div>

                    <div class="space-y-3">
                        <button wire:click="verifyOtp" wire:loading.attr="disabled" wire:target="verifyOtp"
                                class="w-full bg-gradient-to-r from-[#7a3f91] to-[#2b0d3e] text-white py-4 rounded-xl font-bold uppercase tracking-wide shadow-lg hover:from-[#8a4fa1] active:scale-95 disabled:opacity-60 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="verifyOtp"><i class="fa-solid fa-shield-halved mr-1"></i> Verify Code</span>
                            <span wire:loading wire:target="verifyOtp"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying...</span>
                        </button>

                        <button wire:click="resendOtp" wire:loading.attr="disabled" wire:target="resendOtp" type="button"
                                class="w-full bg-white text-[#7a3f91] py-3 rounded-xl font-bold uppercase tracking-wide border-2 border-[#7a3f91] hover:bg-purple-50 active:scale-95 disabled:opacity-60 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="resendOtp"><i class="fa-solid fa-rotate-right mr-1"></i> Resend Code</span>
                            <span wire:loading wire:target="resendOtp"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending...</span>
                        </button>
                    </div>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-gray-400 py-2 text-center text-sm hover:text-[#7a3f91] hover:underline transition-colors">
                        ← Back to Change Password
                    </button>
                </div>
            @endif

            {{-- ════ STEP 3 ════ --}}
            @if ($step == 3)
                <div class="space-y-6 text-center py-4">
                    <div class="flex justify-center">
                        <div class="w-28 h-28 bg-green-100 rounded-full flex items-center justify-center ring-8 ring-green-50">
                            <i class="fa-solid fa-check text-5xl text-green-500"></i>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-3xl font-bold text-green-600 mb-2">Identity Verified!</h2>
                        <p class="text-gray-600">Your OTP has been confirmed. Click below to save your new password and open your dashboard.</p>
                    </div>
                    <div class="bg-blue-50 p-5 rounded-xl border border-blue-200 text-left">
                        <p class="font-bold text-blue-900 mb-2">What happens next:</p>
                        <ul class="space-y-1.5 text-sm text-blue-800 list-disc list-inside">
                            <li>Your new password will be saved securely</li>
                            <li>You'll be redirected to your Organizer Dashboard</li>
                            <li>Use your new password for all future logins</li>
                        </ul>
                    </div>
                    <button wire:click="confirmPassword" wire:loading.attr="disabled" wire:target="confirmPassword"
                            class="w-full bg-gradient-to-r from-green-500 to-green-600 text-white py-4 rounded-xl font-bold uppercase tracking-wide shadow-lg hover:from-green-600 hover:to-green-700 active:scale-95 disabled:opacity-60 transition-all flex items-center justify-center gap-2 text-lg">
                        <span wire:loading.remove wire:target="confirmPassword"><i class="fa-solid fa-arrow-right mr-1"></i> Confirm & Go to Dashboard</span>
                        <span wire:loading wire:target="confirmPassword"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving...</span>
                    </button>
                </div>
            @endif

        </div>
    </div>

    <div class="relative z-10 mt-8 text-center text-sm text-white/70 font-semibold">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </div>

    {{--
        Alpine.js countdown timer.
        Listens for the Livewire 'otp-sent' browser event (dispatched by both
        sendOtp() and resendOtp()). On every event it clears the old setInterval
        and restarts fresh from 10:00, so Resend Code always resets the clock.
    --}}
    <script>
        function otpTimer() {
            return {
                seconds: 600,
                expired: false,
                _interval: null,
                get formattedTime() {
                    const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
                    const s = String(this.seconds % 60).padStart(2, '0');
                    return `${m}:${s}`;
                },
                startCountdown() {
                    if (this._interval) clearInterval(this._interval);  // clear existing
                    this.seconds = 600;                                  // reset to 10:00
                    this.expired = false;
                    this._interval = setInterval(() => {
                        if (this.seconds > 0) {
                            this.seconds--;
                        } else {
                            this.expired = true;
                            clearInterval(this._interval);
                        }
                    }, 1000);
                }
            }
        }
    </script>
</div>