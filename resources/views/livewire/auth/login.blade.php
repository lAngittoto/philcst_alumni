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

<div class="fixed inset-0 bg-[#7a3f91] flex items-center justify-center px-6 font-sans antialiased"
     x-data="{ show: false }">

    <div class="w-full max-w-md bg-[#ffffff] rounded-[2.5rem] shadow-2xl overflow-hidden border border-white/20 mt-35">

        <div class="p-8 md:p-10">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-[#f2eaf7] rounded-2xl mb-4 text-[#7a3f91]">
                    <i class="fa-solid fa-graduation-cap text-3xl"></i>
                </div>
                <h1 class="text-3xl font-black text-[#2b0d3e] uppercase tracking-tighter">Welcome Alumni</h1>
                <p class="text-[#7a3f91] font-medium italic mt-2 text-sm">Enter your credentials to access your account.</p>
            </div>

            <form wire:submit="login" class="space-y-6">

                {{-- Error Message --}}
                @if ($errors->has('invalid'))
                    <div class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl text-sm font-medium">
                        <i class="fa-solid fa-circle-exclamation text-red-500"></i>
                        {{ $errors->first('invalid') }}
                    </div>
                @endif

                {{-- Name Field --}}
                <div class="space-y-2">
                    <label class="text-xs font-black uppercase tracking-widest text-[#2b0d3e] ml-1">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-[#7a3f91]">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <input wire:model="name"
                               type="text"
                               autocomplete="name"
                               class="w-full pl-11 pr-4 py-4 bg-[#f2eaf7] border-2
                                      {{ $errors->has('invalid') ? 'border-red-300' : 'border-transparent' }}
                                      rounded-2xl focus:border-[#c59dd9] focus:bg-white outline-none transition-all font-medium text-[#2b0d3e]">
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
                               autocomplete="current-password"
                               class="w-full pl-11 pr-12 py-4 bg-[#f2eaf7] border-2
                                      {{ $errors->has('invalid') ? 'border-red-300' : 'border-transparent' }}
                                      rounded-2xl focus:border-[#c59dd9] focus:bg-white outline-none transition-all font-medium text-[#2b0d3e]">
                        <button type="button"
                                @click="show = !show"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-[#7a3f91] hover:text-[#2b0d3e] focus:outline-none z-10">
                            <span x-show="!show"><i class="fa-solid fa-eye text-lg"></i></span>
                            <span x-show="show" x-cloak><i class="fa-solid fa-eye-slash text-lg"></i></span>
                        </button>
                    </div>
                </div>

                {{-- Submit Button --}}
                <div class="pt-4">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-70 cursor-not-allowed"
                            class="w-full bg-[#2b0d3e] text-white py-4 rounded-2xl font-black uppercase tracking-widest shadow-lg hover:bg-[#3d1358] transition-all active:scale-95">
                        <span wire:loading.remove>
                            Sign In <i class="fa-solid fa-arrow-right-to-bracket ml-2"></i>
                        </span>
                        <span wire:loading>
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i> Signing in...
                        </span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>