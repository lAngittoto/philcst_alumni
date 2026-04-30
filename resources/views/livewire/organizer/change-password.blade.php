{{-- resources/views/livewire/organizer/change-password.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    // ── Password fields ────────────────────────────────────────────────────────
    public string $password              = '';
    public string $password_confirmation = '';
    public string $passwordStrength      = 'weak';
    public bool   $showPassword          = false;
    public bool   $showConfirmPassword   = false;

    // ── UI ────────────────────────────────────────────────────────────────────
    public string $errorMessage   = '';
    public string $successMessage = '';

    // ─────────────────────────────────────────────────────────────────────────
    // Mount
    // ─────────────────────────────────────────────────────────────────────────

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

        // Already changed → go to dashboard
        if ($organizer->password_changed_at !== null) {
            session()->forget('organizer_requires_password_change');
            $this->redirect(route('organizer.dashboard'));
            return;
        }

        // Ensure session flag is present
        session()->put('organizer_requires_password_change', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Password strength helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function updatedPassword(): void
    {
        $this->updatePasswordStrength();
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
        return $this->password !== ''
            && $this->password_confirmation !== ''
            && $this->password === $this->password_confirmation;
    }

    public function canSubmit(): bool
    {
        return $this->isPasswordStrengthValid() && $this->isPasswordsMatching();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Save Password (no OTP — direct save)
    // ─────────────────────────────────────────────────────────────────────────

    public function changePassword(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        if (!$this->isPasswordStrengthValid()) {
            $this->errorMessage = 'Password strength must be "Good" or "Strong". Add uppercase letters, numbers, and special characters.';
            return;
        }

        $this->validate([
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.min'               => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        $user      = auth()->user();
        $organizer = $user->organizer;

        if (!$organizer) {
            $this->errorMessage = 'Organizer record not found.';
            return;
        }

        try {
            DB::transaction(function () use ($user, $organizer) {
                // Update password on users table
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'password' => Hash::make($this->password),
                    ]);

                // Mark password as changed on organizer record
                $organizer->update([
                    'password_changed_at' => now(),
                    'status'              => 'ACTIVE',
                ]);
            });

            // Clear wizard session flag
            session()->forget('organizer_requires_password_change');

            Log::info("Organizer password changed: user #{$user->id} (organizer #{$organizer->id})");

            $this->redirect(route('organizer.dashboard'));

        } catch (\Exception $e) {
            Log::error("Organizer changePassword error: " . $e->getMessage());
            $this->errorMessage = 'Failed to update password. Please try again.';
        }
    }

}; ?>

<div
    class="min-h-screen w-full flex flex-col items-center justify-center p-4 font-sans antialiased"
    style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center;"
>
    {{-- Overlay --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm z-0"></div>

    {{-- Card --}}
    <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">

        {{-- Header --}}
        <div class="px-6 pt-6 pb-5 border-b border-gray-200">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-lock text-[#7a3f91] text-base"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Set Your Password</h1>
                    <p class="text-sm text-gray-600 mt-0.5">Create a secure password to access the organizer portal</p>
                </div>
            </div>
        </div>

        {{-- Body --}}
        <div class="p-6 space-y-5">

            {{-- Organizer info --}}
            @if(auth()->user()?->organizer)
                <div class="bg-purple-50 border border-purple-200 rounded-xl p-4 flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-[#7a3f91]/10 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-user text-[#7a3f91] text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ auth()->user()->organizer->full_name ?? auth()->user()->name }}</p>
                        <p class="text-xs text-gray-500">Organizer Account</p>
                    </div>
                </div>
            @endif

            {{-- Alerts --}}
            @if ($errorMessage)
                <div class="p-4 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0 text-red-600 text-base"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $errorMessage }}</p>
                </div>
            @endif

            @if ($successMessage)
                <div class="p-4 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-3">
                    <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-emerald-600 text-base"></i>
                    <p class="text-sm leading-relaxed font-medium">{{ $successMessage }}</p>
                </div>
            @endif

            {{-- Password requirements --}}
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
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs
                                {{ $met ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600' }}">
                                {{ $met ? '✓' : '○' }}
                            </span>
                            <span class="text-sm {{ $met ? 'text-emerald-800 font-medium' : 'text-gray-700' }}">{{ $text }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- New password --}}
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

            {{-- Strength bar --}}
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

            {{-- Confirm password --}}
            <div>
                <label class="block text-sm font-semibold text-gray-800 mb-2">Confirm Password</label>
                <div class="relative">
                    <input wire:model.live="password_confirmation"
                           type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                           placeholder="Re-enter your password"
                           autocomplete="new-password"
                           class="w-full px-4 py-3 text-base border-2 rounded-xl focus:outline-none focus:ring-2 transition-all pr-12 bg-white text-gray-900
                               {{ $password_confirmation !== '' && $password !== $password_confirmation
                                   ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
                                   : ($password_confirmation !== '' && $password === $password_confirmation
                                       ? 'border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100'
                                       : 'border-gray-300 focus:border-[#7a3f91] focus:ring-[#7a3f91]/20') }}">
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

            {{-- Submit button --}}
            <button wire:click="changePassword"
                    wire:loading.attr="disabled"
                    wire:target="changePassword"
                    {{ !$this->canSubmit() ? 'disabled' : '' }}
                    class="w-full bg-[#7a3f91] hover:bg-[#6a3080] disabled:bg-gray-400 disabled:cursor-not-allowed text-white py-3 rounded-xl font-semibold text-base shadow-md hover:shadow-lg active:scale-[0.98] disabled:shadow-none transition-all flex items-center justify-center gap-2 mt-2">
                <span wire:loading.remove wire:target="changePassword">
                    <i class="fa-solid fa-lock mr-1"></i> Set Password & Continue
                </span>
                <span wire:loading wire:target="changePassword">
                    <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…
                </span>
            </button>

        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 text-center bg-gray-50 border-t border-gray-200">
            <p class="text-xs text-gray-600 font-medium">&copy; {{ date('Y') }} Philippine College of Science and Technology</p>
        </div>
    </div>
</div>