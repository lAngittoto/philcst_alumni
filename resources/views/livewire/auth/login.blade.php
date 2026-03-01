<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

new #[Layout('app')] class extends Component {

    public string $name     = '';
    public string $password = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirect(route('admin.dashboard'));
        }
    }

    protected function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:6',
        ];
    }

    protected function throttleKey(): string
    {
        return Str::lower($this->name) . '|' . request()->ip();
    }

    public function login(): void
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->addError('invalid', "Too many attempts. Try again in {$seconds} seconds.");
            return;
        }

        if (!Auth::attempt(['name' => $this->name, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey(), 60);
            $this->password = '';
            $this->addError('invalid', 'Username or password is invalid.');
            return;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user->isAdmin()) {
            Auth::logout();
            $this->password = '';
            $this->addError('invalid', 'Username or password is invalid.');
            return;
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();
        $this->redirectRoute('admin.dashboard', navigate: true); // Added navigate: true for SPA feel
    }
}; ?>

<div class="min-h-screen w-full flex flex-col items-center justify-center p-6 md:p-10 font-sans antialiased text-[#2b0d3e] relative overflow-hidden"
     x-data="{ loading: false }"
     x-init="console.log('Page Loaded')"
     style="background-image: url('{{ asset('images/school-1.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    
    {{-- Background Overlay --}}
    <div class="absolute inset-0 bg-black/40 z-0 transition-opacity duration-1000"></div>

    <style>
        [x-cloak] { display: none !important; }

        /* Smooth Fade In for the whole card */
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        .animate-shake {
            animation: shake 0.3s ease-in-out;
            animation-iteration-count: 2;
        }
    </style>

    {{-- Back Button with Smooth Hover --}}
    <a href="/" 
       wire:navigate
       class="fixed top-8 left-8 z-50 flex items-center gap-3 text-white hover:text-purple-200 transition-all duration-300 transform hover:-translate-x-1 group">
        <div class="w-12 h-12 flex items-center justify-center rounded-full border-2 border-white/30 group-hover:border-white group-hover:bg-white/10 transition-all duration-300">
            <i class="fa-solid fa-arrow-left text-lg"></i>
        </div>
        <span class="font-bold uppercase text-xs tracking-widest shadow-sm">Back to Home</span>
    </a>

    {{-- Login Card with Entrance Animation --}}
    <div wire:ignore.self
         class="relative z-10 w-full max-w-md bg-white rounded-[3.5rem] shadow-[0_25px_60px_rgba(0,0,0,0.4)] p-10 md:p-14 fade-in-up {{ $errors->has('invalid') ? 'animate-shake' : '' }}">
        
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-[#f2eaf7] rounded-[2rem] mb-6 text-[#7a3f91] shadow-inner transform transition-transform duration-500 hover:rotate-12">
                <i class="fa-solid fa-user-shield text-4xl"></i>
            </div>
            <p class="text-gray-400 font-bold text-xs uppercase tracking-widest">PhilCST Alumni Portal</p>
        </div>

        <form wire:submit.prevent="login" @submit="loading = true" class="space-y-6">

            @if ($errors->has('invalid'))
                <div x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="flex items-center gap-3 bg-red-50 text-red-700 p-4 rounded-2xl text-xs font-bold uppercase tracking-wider border-2 border-red-100">
                    <i class="fa-solid fa-circle-exclamation text-lg"></i>
                    {{ $errors->first('invalid') }}
                </div>
            @endif

            {{-- Username --}}
            <div class="space-y-2">
                <label class="text-xs font-bold uppercase tracking-widest text-[#2b0d3e] ml-1 flex items-center gap-2">
                    <i class="fa-solid fa-user text-[#7a3f91]"></i> Username
                </label>
                <input wire:model="name"
                       type="text"
                       placeholder="Enter username"
                       required
                       class="w-full px-6 py-4 bg-gray-50 border-2 border-transparent rounded-2xl outline-none transition-all duration-300 font-bold text-[#2b0d3e] focus:border-[#7a3f91] focus:bg-white focus:ring-4 focus:ring-purple-500/10">
            </div>

            {{-- Password --}}
            <div class="space-y-2" x-data="{ show: false }">
                <label class="text-xs font-bold uppercase tracking-widest text-[#2b0d3e] ml-1 flex items-center gap-2">
                    <i class="fa-solid fa-lock text-[#7a3f91]"></i> Password
                </label>
                <div class="relative group">
                    <input wire:model="password"
                           :type="show ? 'text' : 'password'"
                           placeholder="••••••••"
                           required
                           class="w-full px-6 py-4 bg-gray-50 border-2 border-transparent rounded-2xl outline-none transition-all duration-300 font-bold text-[#2b0d3e] focus:border-[#7a3f91] focus:bg-white focus:ring-4 focus:ring-purple-500/10">
                    
                    <button type="button"
                            @click="show = !show"
                            class="absolute inset-y-0 right-0 pr-6 flex items-center text-[#7a3f91] hover:text-[#2b0d3e] transition-colors duration-300 focus:outline-none z-10">
                        <i class="fa-solid text-xl" x-show="!show" x-transition.opacity>fa-eye</i>
                        <i class="fa-solid fa-eye-slash text-xl" x-show="show" x-cloak x-transition.opacity></i>
                    </button>
                </div>
            </div>

            <div class="pt-4">
                <button type="submit"
                        wire:loading.attr="disabled"
                        class="relative w-full bg-[#2b0d3e] text-white py-5 rounded-2xl font-bold uppercase tracking-widest text-sm shadow-xl transition-all duration-300 hover:bg-[#7a3f91] hover:shadow-purple-500/20 active:scale-[0.97] disabled:opacity-70 overflow-hidden">
                    
                    {{-- Smooth Button Content Switch --}}
                    <div class="flex items-center justify-center gap-2" wire:loading.remove>
                        <span>Sign In</span>
                        <i class="fa-solid fa-paper-plane transition-transform duration-300 group-hover:translate-x-1"></i>
                    </div>

                    <div class="flex items-center justify-center gap-2" wire:loading x-cloak x-transition:enter="transition ease-out duration-300">
                        <i class="fa-solid fa-circle-notch fa-spin"></i>
                        <span>Verifying...</span>
                    </div>
                </button>
            </div>

        </form>
    </div>

    {{-- Footer --}}
    <div class="relative z-10 mt-12 text-center text-xs text-white/80 font-bold uppercase tracking-widest animate-pulse">
        &copy; {{ date('Y') }} Philippine College of Science and Technology
    </div>
</div>