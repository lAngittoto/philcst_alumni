{{-- resources/views/livewire/auth/login.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\Organizer;
use App\Models\Alumni;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

new #[Layout('app')] class extends Component {

    const MAX_ATTEMPTS    = 5;
    const LOCKOUT_SECONDS = 600;

    public string $name     = '';
    public string $password = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $user = Auth::user();

            if ($user->role === 'organizer') {
                $organizer = Organizer::where('user_id', $user->id)->first();
                if ($organizer && $organizer->password_changed_at === null) {
                    Auth::logout();
                    session()->invalidate();
                    session()->regenerateToken();
                    return;
                }
                $this->redirect(route('organizer.dashboard'));
                return;
            }

            if ($user->role === 'alumni') {
                $alumni = Alumni::where('user_id', $user->id)->first();

                if ($alumni && ($alumni->needsAccountSetup() || $alumni->hasTemporaryPassword())) {
                    if (!$alumni->needsAccountSetup() && $alumni->hasTemporaryPassword()) {
                        DB::table('alumni')
                            ->where('id', $alumni->id)
                            ->update(['password_changed_at' => null]);
                    }
                    session()->put('alumni_requires_password_change', true);
                    $this->redirect(route('alumni.change-password'));
                    return;
                }

                if ($alumni && !$alumni->isProfileComplete()) {
                    $this->redirect(route('alumni.information'));
                    return;
                }

                $this->redirect(route('alumni.dashboard'));
                return;
            }

            if ($user->role === 'admin') {
                $this->redirect(route('admin.dashboard'));
                return;
            }

            if ($user->role === 'registrar') {
                $this->redirect(route('registrar.dashboard'));
                return;
            }

            Auth::logout();
        }
    }

    protected function accountAttemptsKey(): string
    {
        return 'login_attempts_' . Str::lower(trim($this->name));
    }

    protected function accountLockedKey(): string
    {
        return 'account_locked_' . Str::lower(trim($this->name));
    }

    protected function throttleKey(): string
    {
        return 'login_ip_' . Str::lower($this->name) . '|' . request()->ip();
    }

    protected function accountLockedSeconds(): int
    {
        $expires = Cache::get($this->accountLockedKey());
        if (!$expires) return 0;
        return max(0, $expires - now()->timestamp);
    }

    protected function recordFailedAttempt(): int
    {
        $key      = $this->accountAttemptsKey();
        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, self::LOCKOUT_SECONDS + 60);

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put(
                $this->accountLockedKey(),
                now()->addSeconds(self::LOCKOUT_SECONDS)->timestamp,
                self::LOCKOUT_SECONDS
            );
        }

        return $attempts;
    }

    protected function clearAttempts(): void
    {
        Cache::forget($this->accountAttemptsKey());
        Cache::forget($this->accountLockedKey());
        RateLimiter::clear($this->throttleKey());
    }

    protected function formatLockTime(int $seconds): string
    {
        if ($seconds >= 60) {
            $mins = ceil($seconds / 60);
            return "{$mins} minute" . ($mins !== 1 ? 's' : '');
        }
        return "{$seconds} second" . ($seconds !== 1 ? 's' : '');
    }

    public function login(): void
    {
        $this->validate([
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:1',
        ]);

        $lockedFor = $this->accountLockedSeconds();
        if ($lockedFor > 0) {
            $this->password = '';
            $this->addError('invalid',
                "This account is temporarily locked. Please try again in "
                . $this->formatLockTime($lockedFor) . '.'
            );
            return;
        }

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 15)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->password = '';
            $this->addError('invalid',
                "Too many requests from your IP. Try again in "
                . $this->formatLockTime($seconds) . '.'
            );
            return;
        }

        $rawId    = ltrim(preg_replace('/[^0-9]/', '', $this->name), '0') ?: '0';
        $paddedId = str_pad($rawId, 8, '0', STR_PAD_LEFT);

        $alumni = Alumni::where('student_id', $this->name)
            ->orWhere('student_id', $paddedId)
            ->first();

        if ($alumni && $alumni->user) {
            $user = $alumni->user;

            if (!Hash::check($this->password, $user->password)) {
                RateLimiter::hit($this->throttleKey(), self::LOCKOUT_SECONDS);
                $attempts = $this->recordFailedAttempt();

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->password = '';
                    $this->addError('invalid',
                        "Account locked for " . $this->formatLockTime(self::LOCKOUT_SECONDS)
                        . " after {$attempts} failed attempts."
                    );
                    return;
                }

                $remaining = self::MAX_ATTEMPTS - $attempts;
                $this->password = '';
                $this->addError('invalid',
                    'Student ID or password is invalid. '
                    . "{$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' remaining before lockout.'
                );
                return;
            }

            Auth::login($user, false);
            $this->clearAttempts();
            session()->regenerate();

            $alumni = Alumni::where('user_id', $user->id)->first();

            if (!$alumni || $alumni->needsAccountSetup() || $alumni->hasTemporaryPassword()) {

                if ($alumni && !$alumni->needsAccountSetup() && $alumni->hasTemporaryPassword()) {
                    DB::table('alumni')
                        ->where('id', $alumni->id)
                        ->update(['password_changed_at' => null]);

                    $alumni->password_changed_at = null;
                }

                session()->forget([
                    'alumni_pending_email',
                    'alumni_pending_password',
                    'alumni_password_reset_step',
                ]);
                session()->put('alumni_requires_password_change', true);
                $this->redirectRoute('alumni.change-password', navigate: true);
                return;
            }

            if (!$alumni->isProfileComplete()) {
                $this->redirectRoute('alumni.information', navigate: true);
                return;
            }

            $this->redirectRoute('alumni.dashboard', navigate: true);
            return;
        }

        $organizer = Organizer::where('id_number', $this->name)->first();

        if ($organizer && $organizer->user) {
            $user = $organizer->user;

            if (!Hash::check($this->password, $user->password)) {
                RateLimiter::hit($this->throttleKey(), self::LOCKOUT_SECONDS);
                $attempts = $this->recordFailedAttempt();

                AuditLog::logLogin([
                    'id'    => $user->id,
                    'name'  => $organizer->name,
                    'email' => $organizer->email,
                    'role'  => 'organizer',
                ], false, 'Incorrect password');

                if ($attempts >= self::MAX_ATTEMPTS) {
                    AuditLog::logAccountLocked($this->name, $attempts, $user->id);
                    $this->password = '';
                    $this->addError('invalid',
                        "Account locked for " . $this->formatLockTime(self::LOCKOUT_SECONDS)
                        . " after {$attempts} failed attempts."
                    );
                    return;
                }

                $remaining = self::MAX_ATTEMPTS - $attempts;
                $this->password = '';
                $this->addError('invalid',
                    'Username/ID or password is invalid. '
                    . "{$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' remaining before lockout.'
                );
                return;
            }

            if ($organizer->status !== 'ACTIVE') {
                $this->password = '';
                $this->addError('invalid',
                    'Your account is ' . strtolower($organizer->status) . '. Please contact the administrator.'
                );
                return;
            }

            Auth::login($user, false);
            $this->clearAttempts();
            session()->regenerate();
            $organizer->update(['last_login' => now()]);

            AuditLog::logLogin([
                'id'    => $user->id,
                'name'  => $organizer->name,
                'email' => $organizer->email,
                'role'  => 'organizer',
            ], true);

            if ($organizer->password_changed_at === null) {
                session()->forget(['pending_password_plain', 'password_reset_step']);
                session()->put('organizer_requires_password_change', true);
                $this->redirectRoute('organizer.change-password', navigate: true);
                return;
            }

            $this->redirectRoute('organizer.dashboard', navigate: true);
            return;
        }

        if (!Auth::attempt(['name' => $this->name, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey(), self::LOCKOUT_SECONDS);
            $attempts = $this->recordFailedAttempt();

            AuditLog::logLogin([
                'id'    => null,
                'name'  => $this->name,
                'email' => null,
                'role'  => 'unknown',
            ], false, 'Invalid username or password');

            if ($attempts >= self::MAX_ATTEMPTS) {
                AuditLog::logAccountLocked($this->name, $attempts);
                $this->password = '';
                $this->addError('invalid',
                    "Account locked for " . $this->formatLockTime(self::LOCKOUT_SECONDS)
                    . " after {$attempts} failed attempts."
                );
                return;
            }

            $remaining = self::MAX_ATTEMPTS - $attempts;
            $this->password = '';
            $this->addError('invalid',
                'Username/ID or password is invalid. '
                . "{$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' remaining before lockout.'
            );
            return;
        }

        $user = Auth::user();

        if ($user->role === 'registrar') {
            $this->clearAttempts();
            session()->regenerate();

            AuditLog::logLogin([
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => 'registrar',
            ], true);

            $this->redirectRoute('registrar.dashboard', navigate: true);
            return;
        }

        if ($user->role !== 'admin') {
            Auth::logout();
            $this->password = '';
            $this->addError('invalid', 'Username/ID or password is invalid.');
            return;
        }

        $this->clearAttempts();
        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        AuditLog::logLogin([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => 'admin',
        ], true);

        $this->redirectRoute('admin.dashboard', navigate: true);
    }

}; ?>

<div class="min-h-screen w-full flex flex-col items-center justify-center p-6 md:p-10 antialiased relative overflow-hidden"
     x-data="{ loading: false, showGuide: false }"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">

    {{-- Dark overlay --}}
    <div class="absolute inset-0 bg-black/45 z-0"></div>

    {{-- Google Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        /*
         * FONT SYSTEM
         * Labels / buttons : DM Mono 500  — clean, readable monospace
         * All body copy    : Inter 400    — neutral, highly legible sans-serif
         * Inputs           : Inter 400
         * No bold anywhere in step bodies
         */

        [x-cloak] { display: none !important; }

        /* ── Animations ── */
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-8px); }
            75%       { transform: translateX(8px); }
        }
        .animate-shake { animation: shake 0.3s ease-in-out 2; }

        /* ── Card ── */
        .login-card {
            background: #ffffff;
            border-radius: 2.5rem;
            box-shadow: 0 25px 60px rgba(43,13,62,0.45);
        }

        /* ── Brand label ── */
        .lg-brand-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #9ca3af;
        }

        /* ── Field labels ── */
        .lg-field-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #2b0d3e;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 0.25rem;
        }

        /* ── Input fields ── */
        .lg-input {
            font-family: 'Inter', sans-serif;
            font-size: 0.93rem;
            font-weight: 400;
            color: #2b0d3e;
            width: 100%;
            padding: 1rem 1.5rem;
            background: #f9f7fc;
            border: 1.5px solid #e8e0f0;
            border-radius: 1rem;
            outline: none;
            transition: border-color 0.25s, background 0.25s, box-shadow 0.25s;
        }
        .lg-input::placeholder {
            font-family: 'Inter', sans-serif;
            font-weight: 400;
            font-size: 0.9rem;
            color: #c4b5d1;
        }
        .lg-input:focus {
            border-color: #7a3f91;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(122,63,145,0.10);
        }

        /* ── Error alert ── */
        .lg-error {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 1rem;
            padding: 0.9rem 1.1rem;
        }
        .lg-error-text {
            font-family: 'Inter', sans-serif;
            font-size: 0.83rem;
            font-weight: 400;
            color: #dc2626;
            line-height: 1.5;
        }

        /* ── Submit button ── */
        .lg-btn {
            font-family: 'DM Mono', monospace;
            font-size: 0.82rem;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #ffffff;
            width: 100%;
            background: #2b0d3e;
            padding: 1.1rem;
            border-radius: 1rem;
            border: none;
            cursor: pointer;
            transition: background 0.25s, transform 0.15s, box-shadow 0.25s;
            box-shadow: 0 6px 20px rgba(43,13,62,0.3);
        }
        .lg-btn:hover  { background: #7a3f91; box-shadow: 0 8px 24px rgba(122,63,145,0.35); }
        .lg-btn:active { transform: scale(0.97); }
        .lg-btn:disabled { opacity: 0.65; cursor: not-allowed; }

        /* ── "Don't have account" link ── */
        .lg-no-account {
            font-family: 'Inter', sans-serif;
            font-size: 0.83rem;
            font-weight: 400;
            color: #7a3f91;
            background: transparent;
            border: none;
            padding: 0;
            cursor: pointer;
            text-decoration: underline;
            text-decoration-color: transparent;
            text-underline-offset: 3px;
            transition: text-decoration-color 0.2s, opacity 0.2s;
        }
        .lg-no-account:hover {
            opacity: 0.8;
            text-decoration-color: #7a3f91;
        }

        /* ── Back button ── */
        .lg-back-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.83rem;
            font-weight: 500;
            color: #ffffff;
        }

        /* ── Copyright ── */
        .lg-copyright {
            font-family: 'Inter', sans-serif;
            font-size: 0.72rem;
            font-weight: 400;
            letter-spacing: 0.04em;
            color: rgba(255,255,255,0.65);
        }

        /* ── GUIDE MODAL ── */
        .guide-backdrop {
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        /* Header */
        .guide-header-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.75);
            display: block;
            margin-bottom: 0.3rem;
        }
        .guide-header-title {
            font-family: 'Inter', sans-serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: #ffffff;
            line-height: 1.2;
        }
        .guide-header-sub {
            font-family: 'Inter', sans-serif;
            font-size: 0.88rem;
            font-weight: 400;
            color: rgba(255,255,255,0.80);
            margin-top: 0.35rem;
        }

        /* Step cards */
        .guide-step-title {
            font-family: 'DM Mono', monospace;
            font-size: 0.72rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #2b0d3e;
            margin-bottom: 0.5rem;
        }
        .guide-step-body {
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 400;
            color: #6b7280;
            line-height: 1.65;
        }
        /* strip all bold from inside step cards */
        .guide-step-body strong,
        .guide-step-body b {
            font-weight: 400;
            color: inherit;
        }
        .guide-step-num {
            font-family: 'DM Mono', monospace;
            font-weight: 500;
            font-size: 1rem;
        }

        /* Formula */
        .guide-formula-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #7a3f91;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .guide-formula-chip {
            font-family: 'DM Mono', monospace;
            font-weight: 400;
            font-size: 0.82rem;
            background: #ffffff;
            border: 1.5px solid #e8e0f0;
            color: #2b0d3e;
            padding: 0.4rem 1rem;
            border-radius: 0.75rem;
        }
        .guide-formula-eg {
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            font-weight: 400;
            color: #6b7280;
            margin-left: 0.35rem;
        }

        /* Warning */
        .guide-warning-text {
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 400;
            color: #92400e;
            line-height: 1.65;
        }

        /* Close button */
        .guide-close-btn {
            font-family: 'DM Mono', monospace;
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #ffffff;
            width: 100%;
            background: #2b0d3e;
            padding: 1rem;
            border-radius: 1rem;
            border: none;
            cursor: pointer;
            transition: background 0.25s, transform 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .guide-close-btn:hover  { background: #7a3f91; }
        .guide-close-btn:active { transform: scale(0.97); }
    </style>

    {{-- ── Back to Home ── --}}
    <a href="/" wire:navigate
       class="fixed top-8 left-8 z-50 flex items-center gap-3 hover:opacity-80 transition-opacity duration-200 group">
        <div class="w-11 h-11 flex items-center justify-center rounded-full border-2 border-white/40 group-hover:border-white group-hover:bg-white/10 transition-all duration-300">
            <i class="fa-solid fa-arrow-left text-white text-base"></i>
        </div>
        <span class="lg-back-label">Back to Home</span>
    </a>

    {{-- ── Login Card ── --}}
    <div wire:ignore.self
         class="login-card relative z-10 w-full max-w-md p-10 md:p-14 fade-in-up {{ $errors->has('invalid') ? 'animate-shake' : '' }}">

        {{-- Brand mark --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-[#f3ecfa] rounded-[1.5rem] mb-5 text-[#7a3f91] shadow-inner transition-transform duration-500 hover:rotate-12">
                <i class="fa-solid fa-user-shield text-4xl"></i>
            </div>
            <p class="lg-brand-label">PhilCST Alumni Connect</p>
        </div>

        {{-- Form --}}
        <form wire:submit.prevent="login" @submit="loading = true" class="space-y-6">

            {{-- Error --}}
            @if ($errors->has('invalid'))
                <div x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="lg-error">
                    <i class="fa-solid fa-circle-exclamation text-red-500 text-lg flex-shrink-0 mt-0.5"></i>
                    <span class="lg-error-text">{{ $errors->first('invalid') }}</span>
                </div>
            @endif

            {{-- Username --}}
            <div class="space-y-2">
                <label class="lg-field-label">
                    <i class="fa-solid fa-user text-[#7a3f91]"></i>
                    Username
                </label>
                <input wire:model="name" type="text"
                       placeholder="Enter your username"
                       autocomplete="username" required
                       class="lg-input">
            </div>

            {{-- Password --}}
            <div class="space-y-2" x-data="{ show: false }">
                <label class="lg-field-label">
                    <i class="fa-solid fa-lock text-[#7a3f91]"></i>
                    Password
                </label>
                <div class="relative">
                    <input wire:model="password" :type="show ? 'text' : 'password'"
                           placeholder="Enter your password"
                           autocomplete="current-password" required minlength="1"
                           class="lg-input" style="padding-right:3.5rem;">
                    <button type="button" @click="show = !show"
                            class="absolute inset-y-0 right-0 pr-5 flex items-center text-[#7a3f91] hover:text-[#2b0d3e] transition-colors duration-200 focus:outline-none z-10">
                        <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.313-3.592M6.938 6.938A9.97 9.97 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.97 9.97 0 01-1.497 2.627M6.938 6.938L3 3m3.938 3.938L8.12 8.12M17.062 17.062L21 21m-3.938-3.938L15.88 15.88M9.88 9.88a3 3 0 104.24 4.24"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Submit --}}
            <div class="pt-1">
                <button type="submit" wire:loading.attr="disabled" wire:target="login"
                        class="lg-btn">
                    <div class="flex items-center justify-center gap-2"
                         wire:loading.remove wire:target="login">
                        <span>Sign In</span>
                        <i class="fa-solid fa-paper-plane" style="font-size:0.8rem;"></i>
                    </div>
                    <div class="flex items-center justify-center gap-2"
                         wire:loading wire:target="login" x-cloak>
                        <i class="fa-solid fa-circle-notch fa-spin"></i>
                        <span>Verifying…</span>
                    </div>
                </button>
            </div>

        </form>

        {{-- Don't have account --}}
        <div class="mt-6 text-center">
            <button type="button" @click="showGuide = true" class="lg-no-account">
                Don't have an account yet?
            </button>
        </div>

    </div>

    {{-- Copyright --}}
    <p class="relative z-10 mt-6 lg-copyright">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </p>

    {{-- ══ FIRST-TIME LOGIN GUIDE MODAL ══ --}}
    <div x-show="showGuide"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[100] flex items-center justify-center p-4 guide-backdrop bg-black/60"
         @keydown.escape.window="showGuide = false">

        <div x-show="showGuide"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-90 translate-y-4"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
             x-transition:leave-end="opacity-0 scale-90 translate-y-4"
             class="relative w-full max-w-2xl bg-white rounded-[2rem] shadow-[0_30px_80px_rgba(43,13,62,0.5)] overflow-hidden">

            {{-- Modal header --}}
            <div class="bg-[#7a3f91] px-10 pt-8 pb-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap text-white text-2xl"></i>
                        </div>
                        <div>
                            <span class="guide-header-label">PhilCST Alumni Portal</span>
                            <h2 class="guide-header-title">First-Time Login Guide</h2>
                            <p class="guide-header-sub">Welcome back, Alumni! 👋 Here's how to get in.</p>
                        </div>
                    </div>
                    <button @click="showGuide = false"
                            class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 flex items-center justify-center text-white transition-colors duration-200 focus:outline-none flex-shrink-0 mt-0.5">
                        <i class="fa-solid fa-xmark text-base"></i>
                    </button>
                </div>
            </div>

            {{-- Modal body --}}
            <div class="px-10 py-8">

                {{-- 3 steps --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-7">

                    {{-- Step 1 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f7fc] rounded-2xl p-5 border border-[#e8e0f0]">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center flex-shrink-0">
                            <span class="guide-step-num">1</span>
                        </div>
                        <div>
                            <p class="guide-step-title">Enter Your Student ID</p>
                            <p class="guide-step-body">
                                Use your 8-digit Student ID as your username.
                            </p>
                            <div class="mt-3 inline-flex items-center gap-2 bg-white border border-[#e8e0f0] rounded-xl px-3 py-2">
                                <i class="fa-solid fa-id-card text-[#7a3f91] text-sm"></i>
                                <code style="font-family:'DM Mono',monospace; font-weight:400; font-size:0.85rem; color:#2b0d3e;">00037801</code>
                            </div>
                        </div>
                    </div>

                    {{-- Step 2 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f7fc] rounded-2xl p-5 border border-[#e8e0f0]">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center flex-shrink-0">
                            <span class="guide-step-num">2</span>
                        </div>
                        <div>
                            <p class="guide-step-title">Default Password</p>
                            <p class="guide-step-body">
                                Your password is your Student ID + underscore + first 2 letters of your last name (first letter uppercase).
                            </p>
                            <div class="mt-3 inline-flex items-center gap-2 bg-white border border-[#e8e0f0] rounded-xl px-3 py-2">
                                <i class="fa-solid fa-key text-[#7a3f91] text-sm"></i>
                                <code style="font-family:'DM Mono',monospace; font-weight:400; font-size:0.85rem; color:#2b0d3e;">00037801_Al</code>
                            </div>
                        </div>
                    </div>

                    {{-- Step 3 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f7fc] rounded-2xl p-5 border border-[#e8e0f0]">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center flex-shrink-0">
                            <span class="guide-step-num">3</span>
                        </div>
                        <div>
                            <p class="guide-step-title">You're In!</p>
                            <p class="guide-step-body">
                                After your first login, you'll be guided to set up your profile and change your password.
                            </p>
                        </div>
                    </div>

                </div>

                {{-- Password formula --}}
                <div class="bg-[#f9f7fc] border-2 border-[#e0d5ee] rounded-2xl px-6 py-5 mb-5">
                    <p class="guide-formula-label">
                        <i class="fa-solid fa-flask"></i> Password Formula
                    </p>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="guide-formula-chip">StudentID</span>
                        <span style="font-family:'DM Mono',monospace; font-weight:500; font-size:1.1rem; color:#7a3f91;">+</span>
                        <span class="guide-formula-chip">_</span>
                        <span style="font-family:'DM Mono',monospace; font-weight:500; font-size:1.1rem; color:#7a3f91;">+</span>
                        <span class="guide-formula-chip">First 2 Letters of Last Name</span>
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-green-500"></i>
                        <span class="guide-formula-eg">
                            Example: <code style="font-family:'DM Mono',monospace; font-weight:400; color:#2b0d3e; background:#fff; border:1px solid #e8e0f0; padding:0.1rem 0.5rem; border-radius:0.5rem;">00037801_Al</code>
                            — for a last name starting with "Al…"
                        </span>
                    </div>
                </div>

                {{-- Warning --}}
                <div class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 mb-6">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-base flex-shrink-0 mt-0.5"></i>
                    <p class="guide-warning-text">
                        The first 2 letters of your last name are case-sensitive.
                        The first letter must be uppercase and the second lowercase —
                        e.g., <code style="font-family:'DM Mono',monospace; background:#fef3c7; padding:0.1rem 0.4rem; border-radius:0.4rem;">Al</code>,
                        not <code style="font-family:'DM Mono',monospace; background:#fef3c7; padding:0.1rem 0.4rem; border-radius:0.4rem;">al</code>
                        or <code style="font-family:'DM Mono',monospace; background:#fef3c7; padding:0.1rem 0.4rem; border-radius:0.4rem;">AL</code>.
                    </p>
                </div>

                {{-- Close --}}
                <button @click="showGuide = false" class="guide-close-btn">
                    <span>Got It — Let Me Log In</span>
                    <i class="fa-solid fa-arrow-right" style="font-size:0.8rem;"></i>
                </button>

            </div>
        </div>
    </div>
    {{-- ══ END GUIDE MODAL ══ --}}

</div>