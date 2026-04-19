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

<div class="min-h-screen w-full flex flex-col items-center justify-center p-6 md:p-10 font-sans antialiased text-[#2b0d3e] relative overflow-hidden"
     x-data="{
         loading: false,
         showGuide: false,
     }"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">

    <div class="absolute inset-0 bg-black/40 z-0"></div>

    <style>
        [x-cloak] { display: none !important; }
        .fade-in-up { animation: fadeInUp 0.6s ease-out forwards; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-8px); }
            75%       { transform: translateX(8px); }
        }
        .animate-shake { animation: shake 0.3s ease-in-out; animation-iteration-count: 2; }
        .guide-backdrop {
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
    </style>

    <a href="/" wire:navigate
       class="fixed top-8 left-8 z-50 flex items-center gap-3 text-white hover:text-purple-200 transition-all duration-300 transform hover:-translate-x-1 group">
        <div class="w-12 h-12 flex items-center justify-center rounded-full border-2 border-white/30 group-hover:border-white group-hover:bg-white/10 transition-all duration-300">
            <i class="fa-solid fa-arrow-left text-lg"></i>
        </div>
        <span class="font-bold uppercase text-xs tracking-widest shadow-sm">Back to Home</span>
    </a>

    <div wire:ignore.self
         class="relative z-10 w-full max-w-md bg-white rounded-[3.5rem] shadow-[0_25px_60px_rgba(0,0,0,0.4)] p-10 md:p-14 fade-in-up {{ $errors->has('invalid') ? 'animate-shake' : '' }}">

        <div class="text-center">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-[#f2eaf7] rounded-[2rem] mb-6 text-[#7a3f91] shadow-inner transform transition-transform duration-500 hover:rotate-12">
                <i class="fa-solid fa-user-shield text-4xl"></i>
            </div>
            <p class="text-gray-400 font-bold text-xs uppercase tracking-widest">PhilCST Alumni Connect</p>
        </div>

        <form wire:submit.prevent="login" @submit="loading = true" class="space-y-6">

            @if ($errors->has('invalid'))
                <div x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="flex items-start gap-3 bg-red-50 text-red-700 p-4 rounded-2xl text-xs font-bold uppercase tracking-wider border-2 border-red-100">
                    <i class="fa-solid fa-circle-exclamation text-lg flex-shrink-0 mt-0.5"></i>
                    <span>{{ $errors->first('invalid') }}</span>
                </div>
            @endif

            <div class="space-y-2">
                <label class="text-xs font-bold uppercase tracking-widest text-[#2b0d3e] ml-1 flex items-center gap-2">
                    <i class="fa-solid fa-user text-[#7a3f91]"></i>
                    Username
                </label>
                <input wire:model="name" type="text"
                       placeholder="Enter username, Teacher ID, or Student ID"
                       autocomplete="username" required
                       class="w-full px-6 py-4 bg-gray-50 border-2 border-transparent rounded-2xl outline-none transition-all duration-300 font-bold text-[#2b0d3e] focus:border-[#7a3f91] focus:bg-white focus:ring-4 focus:ring-purple-500/10">
            </div>

            <div class="space-y-2" x-data="{ show: false }">
                <label class="text-xs font-bold uppercase tracking-widest text-[#2b0d3e] ml-1 flex items-center gap-2">
                    <i class="fa-solid fa-lock text-[#7a3f91]"></i>
                    Password
                </label>
                <div class="relative">
                    <input wire:model="password" :type="show ? 'text' : 'password'"
                           placeholder="••••••••" autocomplete="current-password" required minlength="1"
                           class="w-full px-6 py-4 bg-gray-50 border-2 border-transparent rounded-2xl outline-none transition-all duration-300 font-bold text-[#2b0d3e] focus:border-[#7a3f91] focus:bg-white focus:ring-4 focus:ring-purple-500/10">
                    <button type="button" @click="show = !show"
                            class="absolute inset-y-0 right-0 pr-6 flex items-center text-[#7a3f91] hover:text-[#2b0d3e] transition-colors duration-300 focus:outline-none z-10">
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

            <div class="pt-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="login"
                        class="relative w-full bg-[#2b0d3e] text-white py-5 rounded-2xl font-bold uppercase tracking-widest text-sm shadow-xl transition-all duration-300 hover:bg-[#7a3f91] hover:shadow-purple-500/20 active:scale-[0.97] disabled:opacity-70 overflow-hidden">
                    <div class="flex items-center justify-center gap-2" wire:loading.remove wire:target="login">
                        <span>Sign In</span>
                        <i class="fa-solid fa-paper-plane"></i>
                    </div>
                    <div class="flex items-center justify-center gap-2" wire:loading wire:target="login" x-cloak>
                        <i class="fa-solid fa-circle-notch fa-spin"></i>
                        <span>Verifying…</span>
                    </div>
                </button>
            </div>

        </form>

        {{-- ✅ PLAIN TEXT ONLY — no underline, no border, no background --}}
        <div class="mt-6 text-center">
            <button
                type="button"
                @click="showGuide = true"
                class="text-[#7a3f91] text-xs font-bold uppercase tracking-widest hover:opacity-60 transition-opacity duration-200 focus:outline-none bg-transparent border-0 p-0 cursor-pointer">
                Don't have an account yet?
            </button>
        </div>

    </div>

    <div class="relative z-10 mt-5 text-center text-xs text-white/80 font-bold uppercase tracking-widest animate-pulse">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════ --}}
    {{-- FIRST-TIME LOGIN GUIDE MODAL                                          --}}
    {{-- ══════════════════════════════════════════════════════════════════════ --}}
    <div
        x-show="showGuide"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 guide-backdrop bg-black/60"
        @keydown.escape.window="showGuide = false">

        <div
            x-show="showGuide"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-90 translate-y-4"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-90 translate-y-4"
            class="relative w-full max-w-2xl bg-white rounded-[2rem] shadow-[0_30px_80px_rgba(0,0,0,0.5)] overflow-hidden">

            {{-- ✅ SOLID PURPLE HEADER — no gradient --}}
            <div class="bg-[#7a3f91] px-10 pt-8 pb-7 text-white">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-purple-200 mb-1">PhilCST Alumni Portal</p>
                            <h2 class="text-xl font-black uppercase tracking-wide leading-tight">First-Time Login Guide</h2>
                            <p class="text-sm text-purple-100 font-medium mt-1">Welcome back, Alumni! 👋 Here's how to access your account.</p>
                        </div>
                    </div>
                    <button
                        @click="showGuide = false"
                        class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 flex items-center justify-center transition-colors duration-200 focus:outline-none flex-shrink-0 mt-0.5">
                        <i class="fa-solid fa-xmark text-base"></i>
                    </button>
                </div>
            </div>

            {{-- ── Guide body ──────────────────────────────────────────────── --}}
            <div class="px-10 py-8">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-7">

                    {{-- Step 1 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f5ff] rounded-2xl p-5 border border-purple-100">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center font-black text-base flex-shrink-0">
                            1
                        </div>
                        <div>
                            <p class="text-sm font-black uppercase tracking-wide text-[#2b0d3e] mb-1">Enter Your Student ID</p>
                            <p class="text-sm text-gray-500 font-medium leading-relaxed">
                                Use your <strong class="text-[#2b0d3e]">8-digit Student ID</strong> as your username when logging in.
                            </p>
                            <div class="mt-3 inline-flex items-center gap-2 bg-white border border-purple-200 rounded-xl px-3 py-2">
                                <i class="fa-solid fa-id-card text-[#7a3f91] text-sm"></i>
                                <code class="font-mono font-bold text-sm text-[#2b0d3e]">00037801</code>
                            </div>
                        </div>
                    </div>

                    {{-- Step 2 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f5ff] rounded-2xl p-5 border border-purple-100">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center font-black text-base flex-shrink-0">
                            2
                        </div>
                        <div>
                            <p class="text-sm font-black uppercase tracking-wide text-[#2b0d3e] mb-1">Default Password</p>
                            <p class="text-sm text-gray-500 font-medium leading-relaxed">
                                Your password is your <strong class="text-[#2b0d3e]">Student ID</strong> + underscore + <strong class="text-[#2b0d3e]">first 2 letters of your last name</strong> (first letter uppercase).
                            </p>
                            <div class="mt-3 inline-flex items-center gap-2 bg-white border border-purple-200 rounded-xl px-3 py-2">
                                <i class="fa-solid fa-key text-[#7a3f91] text-sm"></i>
                                <code class="font-mono font-bold text-sm text-[#2b0d3e]">00037801_Al</code>
                            </div>
                        </div>
                    </div>

                    {{-- Step 3 --}}
                    <div class="flex flex-col items-start gap-3 bg-[#f9f5ff] rounded-2xl p-5 border border-purple-100">
                        <div class="w-10 h-10 rounded-xl bg-[#7a3f91] text-white flex items-center justify-center font-black text-base flex-shrink-0">
                            3
                        </div>
                        <div>
                            <p class="text-sm font-black uppercase tracking-wide text-[#2b0d3e] mb-1">You're In!</p>
                            <p class="text-sm text-gray-500 font-medium leading-relaxed">
                                After your first successful login, you'll be guided to <strong class="text-[#2b0d3e]">set up your profile</strong> and <strong class="text-[#2b0d3e]">change your password</strong>.
                            </p>
                        </div>
                    </div>

                </div>

                {{-- Password formula card --}}
                <div class="bg-gradient-to-br from-[#f9f5ff] to-[#ede9fe] border-2 border-purple-200 rounded-2xl px-6 py-5 mb-5">
                    <p class="text-xs font-black uppercase tracking-widest text-[#7a3f91] flex items-center gap-2 mb-3">
                        <i class="fa-solid fa-flask"></i> Password Formula
                    </p>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="bg-white border border-purple-200 text-[#2b0d3e] font-mono font-bold text-sm px-4 py-2 rounded-xl shadow-sm">
                            StudentID
                        </span>
                        <span class="text-[#7a3f91] font-black text-lg">+</span>
                        <span class="bg-white border border-purple-200 text-[#2b0d3e] font-mono font-bold text-sm px-4 py-2 rounded-xl shadow-sm">
                            _
                        </span>
                        <span class="text-[#7a3f91] font-black text-lg">+</span>
                        <span class="bg-white border border-purple-200 text-[#2b0d3e] font-mono font-bold text-sm px-4 py-2 rounded-xl shadow-sm">
                            First 2 Letters of Last Name
                        </span>
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-green-500"></i>
                        <span class="text-sm text-gray-600 font-medium">
                            Example: <code class="font-mono font-bold text-[#2b0d3e] bg-white border border-purple-100 px-2 py-0.5 rounded-lg">00037801_Al</code>
                            <span class="text-gray-400 ml-1">— for a last name starting with "Al…"</span>
                        </span>
                    </div>
                </div>

                {{-- Warning note --}}
                <div class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 mb-6">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-base flex-shrink-0 mt-0.5"></i>
                    <p class="text-sm text-amber-700 font-semibold leading-relaxed">
                        The first 2 letters of your last name are <strong>case-sensitive</strong>.
                        The first letter must be <strong>uppercase</strong> and the second <strong>lowercase</strong> —
                        e.g., <code class="font-mono bg-amber-100 px-1.5 py-0.5 rounded">Al</code>, not
                        <code class="font-mono bg-amber-100 px-1.5 py-0.5 rounded">al</code> or
                        <code class="font-mono bg-amber-100 px-1.5 py-0.5 rounded">AL</code>.
                    </p>
                </div>

                {{-- Close button --}}
                <button
                    @click="showGuide = false"
                    class="w-full bg-[#2b0d3e] hover:bg-[#7a3f91] text-white font-black uppercase tracking-widest text-sm py-4 rounded-2xl transition-all duration-300 active:scale-[0.97] shadow-lg flex items-center justify-center gap-2">
                    <span>Got It — Let Me Log In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>

            </div>
        </div>
    </div>
    {{-- ══════════════════════════════════════════════════════════════════════ --}}

</div>