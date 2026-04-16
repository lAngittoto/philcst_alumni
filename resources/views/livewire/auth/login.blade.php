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

new #[Layout('app')] class extends Component {

    const MAX_ATTEMPTS    = 5;
    const LOCKOUT_SECONDS = 600;

    public string $name     = '';
    public string $password = '';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

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

                if ($alumni && $alumni->needsAccountSetup()) {
                    Auth::logout();
                    session()->invalidate();
                    session()->regenerateToken();
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

            // ── FIX: Registrar redirect on mount ──────────────────────────────
            if ($user->role === 'registrar') {
                $this->redirect(route('registrar.dashboard'));
                return;
            }

            Auth::logout();
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    // ── Login Action ──────────────────────────────────────────────────────────

    public function login(): void
    {
        $this->validate([
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:1',
        ]);

        // ── 1. Check account-level lockout ────────────────────────────────────
        $lockedFor = $this->accountLockedSeconds();
        if ($lockedFor > 0) {
            $this->password = '';
            $this->addError('invalid',
                "This account is temporarily locked. Please try again in "
                . $this->formatLockTime($lockedFor) . '.'
            );
            return;
        }

        // ── 2. IP-level rate limit ────────────────────────────────────────────
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 15)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->password = '';
            $this->addError('invalid',
                "Too many requests from your IP. Try again in "
                . $this->formatLockTime($seconds) . '.'
            );
            return;
        }

        // ── 3. Try ALUMNI (Student ID) login ──────────────────────────────────
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

            // ✅ ALUMNI PASSWORD CORRECT
            Auth::login($user, false);
            $this->clearAttempts();
            session()->regenerate();

            $alumni = Alumni::where('user_id', $user->id)->first();

            // GATE 1: needs wizard?
            if (!$alumni || $alumni->needsAccountSetup()) {
                session()->forget([
                    'alumni_pending_email',
                    'alumni_pending_password',
                    'alumni_password_reset_step',
                ]);
                session()->put('alumni_requires_password_change', true);
                $this->redirectRoute('alumni.change-password', navigate: true);
                return;
            }

            // GATE 2: needs profile?
            if (!$alumni->isProfileComplete()) {
                $this->redirectRoute('alumni.information', navigate: true);
                return;
            }

            $this->redirectRoute('alumni.dashboard', navigate: true);
            return;
        }

        // ── 4. Try ORGANIZER (Teacher ID) login ───────────────────────────────
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

            // ✅ ORGANIZER SUCCESS
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

        // ── 5. Try ADMIN / REGISTRAR (username) login ─────────────────────────
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

        // ── FIX: Handle REGISTRAR role ────────────────────────────────────────
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

        // ── Handle ADMIN role ─────────────────────────────────────────────────
        if ($user->role !== 'admin') {
            Auth::logout();
            $this->password = '';
            $this->addError('invalid', 'Username/ID or password is invalid.');
            return;
        }

        // ✅ ADMIN SUCCESS
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
     x-data="{ loading: false }"
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

        <div class="text-center mb-10">
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

            <p class="text-[11px] text-gray-400 text-center -mt-2 font-medium">
                Accounts are locked for 10 minutes after 5 consecutive failed attempts.
            </p>

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
    </div>

    <div class="relative z-10 mt-12 text-center text-xs text-white/80 font-bold uppercase tracking-widest animate-pulse">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </div>

</div>