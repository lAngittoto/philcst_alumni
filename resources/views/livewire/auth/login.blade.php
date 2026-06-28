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

            if ($user->role === 'director') {
                $this->redirect(route('director.dashboard'));
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

            if ($organizer->status !== 'ACTIVE' && $organizer->password_changed_at !== null) {
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
            AuditLog::logLogin(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'registrar'], true);
            $this->redirectRoute('registrar.dashboard', navigate: true);
            return;
        }

        if ($user->role === 'director') {
            $this->clearAttempts();
            session()->regenerate();
            AuditLog::logLogin(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'director'], true);
            $this->redirectRoute('director.dashboard', navigate: true);
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
        AuditLog::logLogin(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'admin'], true);
        $this->redirectRoute('admin.dashboard', navigate: true);
    }

}; ?>

<div class="min-h-screen w-full flex flex-col items-center justify-center p-5 antialiased relative"
     x-data="{ showGuide: false, forgotLoading: false }"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;">

    {{-- Overlay --}}
    <div class="absolute inset-0 bg-black/55 z-0"></div>

    <style>
        [x-cloak] { display: none !important; }

        /* ── Card entrance ── */
        @keyframes lgFadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .lg-card { animation: lgFadeUp 0.45s cubic-bezier(.22,1,.36,1) forwards; }

        @keyframes lgShake {
            0%,100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(6px); }
            60%      { transform: translateX(-4px); }
            80%      { transform: translateX(4px); }
        }
        .lg-shake { animation: lgShake 0.4s ease-in-out; }

        /* ── Dot loader ── */
        @keyframes dotBounce {
            0%,80%,100% { transform: translateY(0); opacity: 0.4; }
            40%          { transform: translateY(-5px); opacity: 1; }
        }
        .dot1 { animation: dotBounce 1.1s ease-in-out infinite 0s; }
        .dot2 { animation: dotBounce 1.1s ease-in-out infinite 0.18s; }
        .dot3 { animation: dotBounce 1.1s ease-in-out infinite 0.36s; }

        @keyframes spinAnim { to { transform: rotate(360deg); } }
        .lg-spin { animation: spinAnim 0.75s linear infinite; }

        /* ══ FLOATING LABEL INPUTS ══ */
        .fl-group { position: relative; }

        .fl-input {
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: #111111;
            width: 100%;
            height: 60px;
            padding: 22px 3rem 8px 3rem;
            background: #ffffff;
            border: 1.5px solid #DDDDDD;
            border-radius: 10px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .fl-input:focus {
            border-color: #7A3F91;
            box-shadow: 0 0 0 3px rgba(122,63,145,0.08);
        }
        .fl-input.has-eye { padding-right: 3rem; }

        .fl-label {
            position: absolute;
            left: 3rem;
            top: 50%;
            transform: translateY(-50%);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            font-weight: 400;
            color: #555555;
            pointer-events: none;
            transition: all 0.18s cubic-bezier(.4,0,.2,1);
            background: #ffffff;
            padding: 0 0.2rem;
            line-height: 1;
        }
        .fl-input:focus ~ .fl-label,
        .fl-input:not(:placeholder-shown) ~ .fl-label {
            top: 0;
            transform: translateY(-50%);
            font-size: 0.65rem;
            font-weight: 700;
            color: #7A3F91;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .fl-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            color: #666666;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 1;
        }
        .fl-group:focus-within .fl-icon { color: #7A3F91; }

        .fl-eye {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666666;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color 0.2s ease;
        }

        /* ── Error box ── */
        .lg-error-box {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            background: #FFF5F5;
            border: 1px solid #FECACA;
            border-left: 3px solid #EF4444;
            border-radius: 8px;
            padding: 0.85rem 1rem;
        }
        .lg-error-txt {
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 400;
            color: #B91C1C;
            line-height: 1.55;
        }

        /* ── Submit button ── */
        .lg-submit {
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
            transition: background 0.2s ease, transform 0.15s ease;
        }
        .lg-submit:hover:not(:disabled) {
            background: #6B3680;
            transform: translateY(-1px);
        }
        .lg-submit:active:not(:disabled) {
            transform: scale(0.985);
            background: #5E2F72;
        }
        .lg-submit:disabled { opacity: 0.55; cursor: not-allowed; }

        /* ── Bottom text links ── */
        .lg-link {
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 400;
            color: #333333;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .lg-link:hover { color: #111111; }

        /* ── Back button ── */
        .lg-back-btn {
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 9999;
        }
        .lg-back-tooltip {
            position: absolute;
            top: 50%;
            left: calc(100% + 10px);
            transform: translateY(-50%) translateX(-4px);
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
            font-size: 0.75rem;
            font-weight: 600;
            color: #ffffff;
            background: #111111;
            padding: 0.35rem 0.7rem;
            border-radius: 6px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .lg-back-btn:hover .lg-back-tooltip {
            opacity: 1;
            transform: translateY(-50%) translateX(0);
        }

        /* ── Guide modal ── */
        .guide-bd { backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }

        /* ── Guide step row ── */
        .guide-step {
            display: flex;
            gap: 1rem;
            padding: 1.1rem 1.25rem;
            background: #FAFAFA;
            border: 1px solid #EEEEEE;
            border-radius: 10px;
        }
        .guide-step-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #7A3F91;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .guide-step-title {
            font-family: 'Inter', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            color: #111111;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin: 0 0 0.3rem;
        }
        .guide-step-body {
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #111111;
            line-height: 1.65;
            margin: 0;
        }

        /* ── Formula box ── */
        .guide-formula {
            background: #F6F6F6;
            border: 1px solid #E8E8E8;
            border-radius: 10px;
            padding: 1.1rem 1.25rem;
        }
        .guide-formula-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #333333;
            margin: 0 0 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .guide-chip {
            font-family: 'Inter', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            background: #ffffff;
            border: 1.5px solid #DDDDDD;
            color: #111111;
            padding: 0.25rem 0.7rem;
            border-radius: 6px;
        }
        .guide-chip-sep {
            font-size: 0.9rem;
            font-weight: 700;
            color: #333333;
        }
        .guide-example {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #EEEEEE;
        }
        .guide-code {
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            color: #111111;
            background: #EEEEEE;
            border: 1px solid #DDDDDD;
            padding: 0.15rem 0.5rem;
            border-radius: 5px;
        }

        /* ── Warning box ── */
        .guide-warning {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            background: #FFFBF0;
            border: 1px solid #E8D9A0;
            border-left: 3px solid #D4A017;
            border-radius: 10px;
            padding: 0.9rem 1.1rem;
        }
        .guide-warning-txt {
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            color: #4A3500;
            line-height: 1.65;
            margin: 0;
        }

        /* ── Close button ── */
        .guide-close-btn {
            font-family: 'Inter', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #ffffff;
            width: 100%;
            background: #7A3F91;
            padding: 0.95rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: background 0.2s ease, transform 0.15s ease;
        }
        .guide-close-btn:hover  { background: #6B3680; transform: translateY(-1px); }
        .guide-close-btn:active { transform: scale(0.985); }
    </style>

    {{-- Back button — hidden when guide modal is open --}}
    <a href="/" wire:navigate
       x-show="!showGuide"
       x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0"
       x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-150"
       x-transition:leave-start="opacity-100"
       x-transition:leave-end="opacity-0"
       class="lg-back-btn flex items-center justify-center w-10 h-10 rounded-full border border-white/30 hover:border-white/60 hover:bg-white/10 transition-all duration-200">
        <i class="fa-solid fa-arrow-left text-white text-sm"></i>
        <span class="lg-back-tooltip">Back to Home</span>
    </a>

    {{-- ══ LOGIN CARD ══ --}}
    <div wire:ignore.self
         class="lg-card relative z-10 w-full max-w-[420px] bg-white rounded-2xl shadow-xl mt-14 sm:mt-0 {{ $errors->has('invalid') ? 'lg-shake' : '' }}">

        <div class="px-9 py-9">

            {{-- Brand --}}
            <div class="flex items-center gap-3.5 mb-8">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0"
                     style="background:#7A3F91;">
                    <i class="fa-solid fa-user-shield text-white" style="font-size:1.1rem;"></i>
                </div>
                <div>
                    <p style="font-family:'Inter',sans-serif; font-size:1rem; font-weight:700; line-height:1.2; margin:0;">
                        <span style="color:#7A3F91;">PhilCST</span><span style="color:#111111;"> Alumni Connect</span>
                    </p>
                    <p style="font-family:'Inter',sans-serif; font-size:0.85rem; color:#333333; margin:0.2rem 0 0;">Sign in to your account</p>
                </div>
            </div>

            {{-- Form --}}
            <form wire:submit.prevent="login" class="space-y-4">

                {{-- Error --}}
                @if ($errors->has('invalid'))
                    <div x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="lg-error-box">
                        <i class="fa-solid fa-circle-exclamation text-red-500 flex-shrink-0 mt-0.5" style="font-size:0.85rem;"></i>
                        <span class="lg-error-txt">{{ $errors->first('invalid') }}</span>
                    </div>
                @endif

                {{-- Username --}}
                <div class="fl-group">
                    <span class="fl-icon"><i class="fa-solid fa-user"></i></span>
                    <input wire:model="name"
                           type="text"
                           placeholder=" "
                           autocomplete="username"
                           required
                           class="fl-input">
                    <label class="fl-label">Username</label>
                </div>

                {{-- Password --}}
                <div x-data="{ show: false }">
                    <div class="fl-group">
                        <span class="fl-icon"><i class="fa-solid fa-lock"></i></span>
                        <input wire:model="password"
                               :type="show ? 'text' : 'password'"
                               placeholder=" "
                               autocomplete="current-password"
                               required minlength="1"
                               class="fl-input has-eye">
                        <label class="fl-label">Password</label>
                        <button type="button"
                                @click="show = !show"
                                class="fl-eye focus:outline-none"
                                :style="show ? 'color:#7A3F91;' : 'color:#666666;'">
                            <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" style="width:17px;height:17px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" style="width:17px;height:17px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.313-3.592M6.938 6.938A9.97 9.97 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.97 9.97 0 01-1.497 2.627M6.938 6.938L3 3m3.938 3.938L8.12 8.12M17.062 17.062L21 21m-3.938-3.938L15.88 15.88M9.88 9.88a3 3 0 104.24 4.24"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="pt-1">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="login"
                            class="lg-submit">
                        <span wire:loading.remove wire:target="login"
                              class="flex items-center justify-center gap-2">
                            Sign In
                            <i class="fa-solid fa-arrow-right-to-bracket" style="font-size:0.72rem;"></i>
                        </span>
                        <span wire:loading wire:target="login" x-cloak
                              class="flex items-center justify-center gap-2">
                            <span class="flex gap-1">
                                <span class="dot1 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                <span class="dot2 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                                <span class="dot3 inline-block w-1.5 h-1.5 bg-white rounded-full"></span>
                            </span>
                            <span style="font-size:0.78rem; letter-spacing:0.16em;">Verifying</span>
                        </span>
                    </button>
                </div>

            </form>

            {{-- Bottom links --}}
            <div class="mt-7 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="h-px flex-1 bg-[#F0F0F0]"></div>
                    <span style="font-family:'Inter',sans-serif; font-size:0.7rem; color:#999999; letter-spacing:0.08em; text-transform:uppercase;">or</span>
                    <div class="h-px flex-1 bg-[#F0F0F0]"></div>
                </div>

                <div class="text-center">
                    <a href="{{ route('alumni.forgot-password') }}"
                       wire:navigate
                       @click="forgotLoading = true"
                       class="lg-link">
                        <span x-show="!forgotLoading">Forgot your password?</span>
                        <span x-show="forgotLoading" x-cloak class="inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-notch lg-spin" style="font-size:0.75rem;"></i>
                            Redirecting…
                        </span>
                    </a>
                </div>

                <div class="text-center">
                    <button type="button" @click="showGuide = true" class="lg-link">
                        Don't have an account yet?
                    </button>
                </div>
            </div>

        </div>
    </div>

    {{-- Copyright --}}
    <p class="relative z-10 mt-5 text-center"
       style="font-family:'Inter',sans-serif; font-size:0.72rem; color:rgba(255,255,255,0.40); letter-spacing:0.04em;">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </p>

    {{-- ══ FIRST-TIME GUIDE MODAL ══ --}}
    <div x-show="showGuide"
         x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-180"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click.self.stop="null"
         @keydown.escape.window.stop="null"
         class="fixed inset-0 z-[100] flex items-center justify-center p-5 guide-bd bg-black/55">

        <div x-show="showGuide"
             x-transition:enter="transition ease-out duration-250"
             x-transition:enter-start="opacity-0 scale-95 translate-y-3"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition ease-in duration-180"
             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
             x-transition:leave-end="opacity-0 scale-95 translate-y-3"
             @click.stop="null"
             class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

            {{-- Modal Header --}}
            <div class="px-8 pt-7 pb-6 border-b border-[#F0F0F0] shrink-0">
                <p style="font-family:'Inter',sans-serif; font-size:0.62rem; font-weight:700; letter-spacing:0.2em; text-transform:uppercase; color:#333333; margin-bottom:0.3rem;">First-Time Login Guide</p>
                <h2 style="font-family:'Inter',sans-serif; font-size:1.2rem; font-weight:700; color:#7A3F91; margin:0; line-height:1.25;">Welcome, Alumni</h2>
                <p style="font-family:'Inter',sans-serif; font-size:0.875rem; color:#333333; margin:0.3rem 0 0; line-height:1.5;">Follow these steps to access your account for the first time.</p>
            </div>

            {{-- Modal Body --}}
            <div class="px-8 py-6 overflow-y-auto space-y-3" style="scrollbar-width:thin; scrollbar-color:#DDDDDD #F9F9F9;">

                {{-- Steps --}}
                <div class="guide-step">
                    <div class="guide-step-num">1</div>
                    <div>
                        <p class="guide-step-title">Enter Your Student ID</p>
                        <p class="guide-step-body">Use your 8-digit Student ID as your username (e.g. <span class="guide-code">00037801</span>).</p>
                    </div>
                </div>

                <div class="guide-step">
                    <div class="guide-step-num">2</div>
                    <div>
                        <p class="guide-step-title">Use the Default Password</p>
                        <p class="guide-step-body">Your default password is your Student ID + underscore + first 2 letters of your last name.</p>
                    </div>
                </div>

                <div class="guide-step">
                    <div class="guide-step-num">3</div>
                    <div>
                        <p class="guide-step-title">Set Up Your Account</p>
                        <p class="guide-step-body">After logging in, you will be guided to change your password and complete your profile.</p>
                    </div>
                </div>

                {{-- Formula --}}
                <div class="guide-formula">
                    <p class="guide-formula-label">
                        <i class="fa-solid fa-key"></i> Password Formula
                    </p>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="guide-chip">Student ID</span>
                        <span class="guide-chip-sep">+</span>
                        <span class="guide-chip">_</span>
                        <span class="guide-chip-sep">+</span>
                        <span class="guide-chip">First 2 letters of Last Name</span>
                    </div>
                    <div class="guide-example">
                        <i class="fa-solid fa-circle-check text-green-500 shrink-0" style="font-size:0.8rem;"></i>
                        <span style="font-family:'Inter',sans-serif; font-size:0.875rem; color:#111111;">
                            Example: last name is "Alford" →
                            <span class="guide-code">00037801_Al</span>
                        </span>
                    </div>
                </div>

                {{-- Case warning --}}
                <div class="guide-warning">
                    <i class="fa-solid fa-triangle-exclamation flex-shrink-0 mt-0.5" style="font-size:0.8rem; color:#C08A00;"></i>
                    <p class="guide-warning-txt">
                        The 2 letters are <strong>case-sensitive</strong> — first letter uppercase, second lowercase.
                        Use <span class="guide-code" style="background:#FEF3C7; border-color:#E8D9A0;">Al</span>,
                        not <span class="guide-code" style="background:#FEF3C7; border-color:#E8D9A0;">al</span>
                        or <span class="guide-code" style="background:#FEF3C7; border-color:#E8D9A0;">AL</span>.
                    </p>
                </div>

                {{-- Close CTA --}}
                <div class="pt-1 pb-1">
                    <button type="button" @click="showGuide = false" class="guide-close-btn">
                        Ok, Let Me Log In
                        <i class="fa-solid fa-arrow-right" style="font-size:0.65rem;"></i>
                    </button>
                </div>

            </div>
        </div>
    </div>

</div>