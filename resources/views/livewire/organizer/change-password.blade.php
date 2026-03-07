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

        // ── Clear stale/orphaned session data ──────────────────────────────
        $resetStep       = session('password_reset_step');
        $pendingPassword = session('pending_password_plain');

        // Session says OTP was sent but organizer has no active OTP → stale
        if ($resetStep === 'otp_verification' && !$organizer->otp) {
            session()->forget(['pending_password_plain', 'password_reset_step']);
            $resetStep = null;
        }

        // No pending password but session claims a step → stale
        if (!$pendingPassword && in_array($resetStep, ['otp_verification', 'password_confirmed'])) {
            session()->forget(['pending_password_plain', 'password_reset_step']);
            $resetStep = null;
        }

        // Restore step only if session is genuinely valid
        if ($resetStep === 'otp_verification' && $pendingPassword) {
            $this->step    = 2;
            $this->otpSent = true;
        } elseif ($resetStep === 'password_confirmed' && $pendingPassword) {
            $this->step        = 3;
            $this->otpVerified = true;
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
            'weak'   => ['label' => 'Weak',   'color' => 'text-red-500',    'progressColor' => 'bg-red-400',    'width' => 'w-1/4'],
            'fair'   => ['label' => 'Fair',   'color' => 'text-orange-500', 'progressColor' => 'bg-orange-400', 'width' => 'w-2/4'],
            'good'   => ['label' => 'Good',   'color' => 'text-amber-500',  'progressColor' => 'bg-amber-400',  'width' => 'w-3/4'],
            'strong' => ['label' => 'Strong', 'color' => 'text-emerald-600','progressColor' => 'bg-emerald-500','width' => 'w-full'],
        };
    }

    public function sendOtp(): void
    {
        $this->errorMessage   = '';
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

            $this->dispatch('otp-sent');

        } catch (\Exception $e) {
            Log::error("sendOtp error: " . $e->getMessage());
            $this->errorMessage = 'Failed to send OTP. Please try again.';
        }
    }

    public function verifyOtp(): void
    {
        $this->errorMessage   = '';
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
            DB::table('users')
                ->where('id', $user->id)
                ->update(['password' => Hash::make($pendingPassword)]);

            $organizer->markPasswordChanged();

            // Clear ALL password-change related session data including the fresh-login flag
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
        $this->errorMessage   = '';
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

            $this->dispatch('otp-sent');

        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to resend OTP. Please try again.';
        }
    }

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
    class="min-h-screen w-full flex flex-col items-center justify-center p-4 font-sans antialiased"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
    x-data="otpTimer()"
    x-on:otp-sent.window="startCountdown()"
>
    {{-- Overlay --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm z-0"></div>

    {{-- Back link --}}
    <a href="/" wire:navigate
       class="fixed top-6 left-6 z-50 flex items-center gap-2 text-white/80 hover:text-white transition-all group text-sm">
        <div class="w-8 h-8 flex items-center justify-center rounded-full border border-white/30 group-hover:border-white/70 group-hover:bg-white/10 transition-all">
            <i class="fa-solid fa-arrow-left text-xs"></i>
        </div>
        <span class="font-medium tracking-wide hidden sm:inline">Back to Home</span>
    </a>

    {{-- Card --}}
    <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Top bar with step indicator --}}
        <div class="px-6 pt-6 pb-5 border-b border-gray-100">

            {{-- Title --}}
            <div class="flex items-center gap-3 mb-5">
                <div class="w-11 h-11 rounded-xl bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-key text-[#7a3f91] text-base"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Change Password</h1>
                    <p class="text-sm text-gray-400 mt-0.5">Set up your new secure password</p>
                </div>
            </div>

            {{-- Step Indicator --}}
            <div class="flex items-center gap-1">
                @foreach ([1 => 'Password', 2 => 'Verify OTP', 3 => 'Confirm'] as $i => $label)
                    <div class="flex items-center {{ $i < 3 ? 'flex-1' : '' }}">
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold transition-all
                                {{ $i == $step ? 'bg-[#7a3f91] text-white' : ($i < $step ? 'bg-[#7a3f91]/20 text-[#7a3f91]' : 'bg-gray-100 text-gray-400') }}">
                                @if ($i < $step)
                                    <i class="fa-solid fa-check text-xs"></i>
                                @else
                                    {{ $i }}
                                @endif
                            </div>
                            <span class="text-sm font-semibold hidden sm:inline
                                {{ $i == $step ? 'text-gray-800' : ($i < $step ? 'text-[#7a3f91]' : 'text-gray-400') }}">
                                {{ $label }}
                            </span>
                        </div>
                        @if ($i < 3)
                            <div class="flex-1 mx-2 h-px {{ $i < $step ? 'bg-[#7a3f91]/40' : 'bg-gray-200' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Body --}}
        <div class="p-6">

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-500 text-base"></i>
                    <p class="text-sm leading-relaxed">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 flex items-start gap-3">
                    <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-500 text-base"></i>
                    <p class="text-sm leading-relaxed">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- ═══ STEP 1 ═══ --}}
            @if ($step == 1)
                <div class="space-y-5">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Create New Password</h2>
                        <p class="text-sm text-gray-400 mt-1">Min. 8 characters with good strength</p>
                    </div>

                    {{-- Requirements --}}
                    <div class="grid grid-cols-1 gap-1.5">
                        @foreach ([
                            [strlen($password) >= 8,                 '8+ characters'],
                            [preg_match('/[A-Z]/', $password),       'Uppercase (A–Z)'],
                            [preg_match('/[a-z]/', $password),       'Lowercase (a–z)'],
                            [preg_match('/[0-9]/', $password),       'Number (0–9)'],
                            [preg_match('/[!@#$%^&*?]/', $password), 'Special char (!@#$%^&*?)'],
                        ] as [$met, $text])
                            <div class="flex items-center gap-2 py-0.5">
                                <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0
                                    {{ $met ? 'bg-emerald-100 text-emerald-600' : 'bg-gray-100 text-gray-300' }}">
                                    <i class="fa-solid {{ $met ? 'fa-check' : 'fa-circle' }} text-[9px]"></i>
                                </span>
                                <span class="text-sm {{ $met ? 'text-emerald-700 font-medium' : 'text-gray-400' }}">{{ $text }}</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- Password field --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-2">New Password</label>
                        <div class="relative">
                            <input wire:model.live="password"
                                   type="{{ $showPassword ? 'text' : 'password' }}"
                                   placeholder="Enter new password" autocomplete="new-password"
                                   class="w-full px-4 py-3 text-base border border-gray-200 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/15 transition-all pr-12 bg-gray-50/50">
                            <button type="button" wire:click="$toggle('showPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 hover:text-[#7a3f91] transition-colors">
                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }} text-base"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Strength bar --}}
                    @if ($password !== '')
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-sm text-gray-400 font-medium">Strength</span>
                                <span class="text-sm font-bold {{ $this->getPasswordStrengthInfo()['color'] }}">
                                    {{ $this->getPasswordStrengthInfo()['label'] }}
                                </span>
                            </div>
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500 {{ $this->getPasswordStrengthInfo()['progressColor'] }} {{ $this->getPasswordStrengthInfo()['width'] }}"></div>
                            </div>
                        </div>
                    @endif

                    {{-- Confirm password --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-2">Confirm Password</label>
                        <div class="relative">
                            <input wire:model="password_confirmation"
                                   type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                   placeholder="Re-enter password" autocomplete="new-password"
                                   class="w-full px-4 py-3 text-base border rounded-xl focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/15 transition-all pr-12 bg-gray-50/50
                                       {{ $password_confirmation !== '' && $password !== $password_confirmation ? 'border-red-300 focus:border-red-400' : ($password_confirmation !== '' && $password === $password_confirmation ? 'border-emerald-300 focus:border-emerald-400' : 'border-gray-200 focus:border-[#7a3f91]') }}">
                            <button type="button" wire:click="$toggle('showConfirmPassword')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 hover:text-[#7a3f91] transition-colors">
                                <i class="fa-solid {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-base"></i>
                            </button>
                        </div>
                        @if ($password_confirmation !== '' && $password !== $password_confirmation)
                            <p class="text-sm text-red-500 mt-1.5 flex items-center gap-1.5">
                                <i class="fa-solid fa-xmark"></i> Passwords do not match
                            </p>
                        @elseif ($password_confirmation !== '' && $password === $password_confirmation)
                            <p class="text-sm text-emerald-600 mt-1.5 flex items-center gap-1.5">
                                <i class="fa-solid fa-check"></i> Passwords match
                            </p>
                        @endif
                    </div>

                    <button wire:click="sendOtp" wire:loading.attr="disabled" wire:target="sendOtp"
                            class="w-full bg-[#7a3f91] hover:bg-[#6a3080] text-white py-3 rounded-xl font-semibold text-base shadow-sm hover:shadow-md active:scale-[0.98] disabled:opacity-60 transition-all flex items-center justify-center gap-2 mt-1">
                        <span wire:loading.remove wire:target="sendOtp">
                            <i class="fa-solid fa-paper-plane mr-1"></i> Send OTP to Email
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
                        <p class="text-sm text-gray-400 mt-1">Enter the 6-digit code sent to your email</p>
                    </div>

                    {{-- Countdown --}}
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 text-center">
                        <p class="text-sm text-gray-400 mb-2 font-semibold uppercase tracking-wide">Code expires in</p>
                        <div class="text-4xl font-bold font-mono text-[#7a3f91] tabular-nums" x-text="formattedTime">10:00</div>
                        <p x-show="expired" x-cloak class="text-red-500 text-sm mt-2 font-semibold">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> OTP expired — request a new one below.
                        </p>
                    </div>

                    {{-- OTP input --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-2">6-Digit Code</label>
                        <input wire:model="otp"
                               type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                               placeholder="000000" autocomplete="one-time-code"
                               class="w-full px-4 py-4 text-center text-3xl font-bold tracking-[0.6em] border border-gray-200 rounded-xl focus:border-[#7a3f91] focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/15 transition-all bg-gray-50/50">
                    </div>

                    <div class="space-y-3 pt-1">
                        <button wire:click="verifyOtp" wire:loading.attr="disabled" wire:target="verifyOtp"
                                class="w-full bg-[#7a3f91] hover:bg-[#6a3080] text-white py-3 rounded-xl font-semibold text-base shadow-sm hover:shadow-md active:scale-[0.98] disabled:opacity-60 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="verifyOtp">
                                <i class="fa-solid fa-shield-halved mr-1"></i> Verify Code
                            </span>
                            <span wire:loading wire:target="verifyOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Verifying…
                            </span>
                        </button>

                        <button wire:click="resendOtp" wire:loading.attr="disabled" wire:target="resendOtp" type="button"
                                class="w-full bg-white text-[#7a3f91] py-3 rounded-xl font-semibold text-base border border-[#7a3f91]/30 hover:border-[#7a3f91] hover:bg-[#7a3f91]/5 active:scale-[0.98] disabled:opacity-60 transition-all flex items-center justify-center gap-2">
                            <span wire:loading.remove wire:target="resendOtp">
                                <i class="fa-solid fa-rotate-right mr-1"></i> Resend Code
                            </span>
                            <span wire:loading wire:target="resendOtp">
                                <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Sending…
                            </span>
                        </button>
                    </div>

                    <button wire:click="previousStep" type="button"
                            class="w-full text-sm text-gray-400 py-1.5 text-center hover:text-[#7a3f91] transition-colors font-medium">
                        ← Back to Change Password
                    </button>
                </div>
            @endif

            {{-- ═══ STEP 3 ═══ --}}
            @if ($step == 3)
                <div class="space-y-5 text-center py-2">
                    <div class="flex justify-center">
                        <div class="w-20 h-20 bg-emerald-100 rounded-2xl flex items-center justify-center">
                            <i class="fa-solid fa-check text-3xl text-emerald-500"></i>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-2">Identity Verified</h2>
                        <p class="text-base text-gray-500 leading-relaxed">Your OTP has been confirmed. Click below to save your new password.</p>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-left space-y-2.5">
                        @foreach (['Your new password will be saved securely', 'You\'ll be redirected to your Organizer Dashboard', 'Use your new password for all future logins'] as $item)
                            <div class="flex items-start gap-2.5">
                                <i class="fa-solid fa-circle-dot text-[#7a3f91]/50 text-sm mt-0.5 flex-shrink-0"></i>
                                <span class="text-sm text-gray-600 leading-relaxed">{{ $item }}</span>
                            </div>
                        @endforeach
                    </div>
                    <button wire:click="confirmPassword" wire:loading.attr="disabled" wire:target="confirmPassword"
                            class="w-full bg-emerald-500 hover:bg-emerald-600 text-white py-3 rounded-xl font-semibold text-base shadow-sm hover:shadow-md active:scale-[0.98] disabled:opacity-60 transition-all flex items-center justify-center gap-2 mt-1">
                        <span wire:loading.remove wire:target="confirmPassword">
                            <i class="fa-solid fa-arrow-right mr-1"></i> Confirm & Go to Dashboard
                        </span>
                        <span wire:loading wire:target="confirmPassword">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…
                        </span>
                    </button>
                </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="px-6 pb-5 text-center">
            <p class="text-sm text-gray-300">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
        </div>
    </div>

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
                    if (this._interval) clearInterval(this._interval);
                    this.seconds = 600;
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