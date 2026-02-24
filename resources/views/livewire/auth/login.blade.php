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
        $this->redirectRoute('admin.dashboard');
    }
    

}; ?>

<div class="min-h-screen bg-[#f8f4f9] flex items-center justify-center p-4 md:p-10 font-sans antialiased" x-data="{ show: false }" @keydown.window="true">
    
    <a href="/" class="fixed top-8 left-8 z-50 flex items-center gap-2 bg-[#2b0d3e] text-white px-5 py-2.5 rounded-full shadow-lg hover:bg-[#7a3f91] transition-all group">
        <i class="fa-solid fa-arrow-left transition-transform group-hover:-translate-x-1"></i>
        <span class="font-bold uppercase text-xs tracking-widest">Back to Home</span>
    </a>

    <div class="w-full max-w-6xl bg-white rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col md:flex-row min-h-[600px]">
        
        <div class="w-full md:w-1/2 relative hidden md:block">
            <img src="{{ asset('images/school-1.jpg') }}" 
                 alt="Alumni" 
                 class="absolute inset-0 w-full h-full object-cover"
                 style="object-position: 40% center;">
            <div class="absolute inset-0 bg-[#2b0d3e]/10"></div>
        </div>

        <div class="w-full md:w-1/2 p-8 md:p-16 flex flex-col justify-center bg-white">
            
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-[#f2eaf7] rounded-3xl mb-6 text-[#7a3f91]">
                    <i class="fa-solid fa-graduation-cap text-4xl"></i>
                </div>
                <h1 class="text-4xl font-black text-[#2b0d3e] uppercase tracking-tighter">Welcome Alumni</h1>
                <p class="text-[#7a3f91] font-medium italic mt-2">Enter your credentials to access your account.</p>
            </div>

            <form wire:submit.prevent="login" class="space-y-6">

                {{-- Error Message --}}
                @if ($errors->has('invalid'))
                    <div class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl text-sm font-medium animate-pulse">
                        <i class="fa-solid fa-circle-exclamation text-red-500"></i>
                        {{ $errors->first('invalid') }}
                    </div>
                @endif

                {{-- Username Field --}}
                <div class="space-y-2">
                    <label class="text-xs font-black uppercase tracking-widest text-[#2b0d3e] ml-1">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-[#7a3f91]">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <input wire:model="name"
                               type="text"
                               placeholder="Enter your username"
                               required
                               class="w-full pl-11 pr-4 py-4 bg-[#f2eaf7] border-2 {{ $errors->has('invalid') ? 'border-red-300' : 'border-transparent' }} rounded-2xl focus:border-[#7a3f91] focus:bg-white outline-none transition-all font-medium text-[#2b0d3e]">
                    </div>
                </div>

                {{-- Password Field --}}
                <div class="space-y-2">
                    <label class="text-xs font-black uppercase tracking-widest text-[#2b0d3e] ml-1">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-[#7a3f91]">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <input wire:model="password"
                               :type="show ? 'text' : 'password'"
                               placeholder="••••••••"
                               required
                               class="w-full pl-11 pr-12 py-4 bg-[#f2eaf7] border-2 {{ $errors->has('invalid') ? 'border-red-300' : 'border-transparent' }} rounded-2xl focus:border-[#7a3f91] focus:bg-white outline-none transition-all font-medium text-[#2b0d3e]">
                        
                        <button type="button"
                                @click="show = !show"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-[#7a3f91] hover:text-[#2b0d3e] focus:outline-none z-10">
                            <span x-show="!show"><i class="fa-solid fa-eye text-lg"></i></span>
                            <span x-show="show" x-cloak><i class="fa-solid fa-eye-slash text-lg"></i></span>
                        </button>
                    </div>
                </div>

                {{-- Submit Button --}}
                <div class="pt-6">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            class="w-full bg-[#2b0d3e] text-white py-5 rounded-2xl font-black uppercase tracking-widest shadow-xl hover:bg-[#3d1358] transition-all active:scale-[0.98] disabled:opacity-70">
                        <span wire:loading.remove>
                            Sign In <i class="fa-solid fa-arrow-right-to-bracket ml-2"></i>
                        </span>
                        <span wire:loading inline-flex items-center>
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i> Signing in...
                        </span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>