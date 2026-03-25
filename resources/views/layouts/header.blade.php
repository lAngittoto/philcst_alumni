<header 
    x-data="{ open: false }" 
    class="bg-white/90 backdrop-blur-md sticky top-0 z-50 border-b border-gray-100 w-full font-mono transition-all duration-500"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20 lg:h-28 transition-all duration-500"> 
            
            {{-- Logo Section --}}
            <a href="/" wire:navigate class="flex items-center gap-2 sm:gap-3 shrink-0 group cursor-pointer">
                <img src="{{ asset('images/logo.png') }}" alt="logo" 
                     class="h-12 w-auto lg:h-16 drop-shadow-sm transition-transform duration-500 group-hover:scale-105">
                
                <h1 class="flex flex-col leading-tight">
                    <span class="text-[#2b0d3e] font-mono text-xl md:text-3xl uppercase tracking-tight">
                        Philcst
                    </span>
                    <span class="text-[#7a3f91] font-mono text-sm md:text-xl uppercase tracking-wider -mt-1">
                        Alumni Connect
                    </span>
                </h1>
            </a>

            {{-- Desktop Nav --}}
            <nav class="hidden lg:flex items-center space-x-10">
                @php 
                    $navs = [
                        '/' => 'Home', 
                        'about' => 'About', 
                        'showevents' => 'Events'
                    ]; 
                @endphp
                
                @foreach($navs as $path => $label)
                    @php $isActive = Request::is($path == '/' ? '/' : $path); @endphp
                    
                    {{-- Added wire:navigate for smooth page loading --}}
                    <a href="{{ url($path) }}" 
                       wire:navigate
                       class="relative group py-2 text-xl uppercase transition-colors duration-300 text-[#2b0d3e] {{ $isActive ? 'font-bold' : 'font-light' }}">
                        
                        {{ $label }}
                        
                        <span class="absolute bottom-0 left-0 h-0.5 bg-[#7a3f91] transition-all duration-300 
                            {{ $isActive ? 'w-full' : 'w-0 group-hover:w-full' }}">
                        </span>
                    </a>
                @endforeach
                
                {{-- Login Link with wire:navigate --}}
                <a href="{{ route('login') }}" 
                   wire:navigate
                   class="relative group py-2 text-[#2b0d3e] font-light text-xl uppercase transition-all duration-300 active:scale-95">
                    LOGIN
                    <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-[#7a3f91] transition-all duration-300 group-hover:w-full"></span>
                </a>
            </nav>

            {{-- Mobile Menu Button --}}
            <div class="lg:hidden flex items-center">
                <button @click="open = !open" class="text-[#2b0d3e] p-2 focus:outline-none">
                    <div class="w-6 h-5 relative flex flex-col justify-between">
                        <span :class="open ? 'rotate-45 translate-y-2.5' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                        <span :class="open ? 'opacity-0' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                        <span :class="open ? '-rotate-45 -translate-y-2' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                    </div>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile Menu --}}
    <div x-show="open" 
         x-cloak 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-10"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-10"
         class="lg:hidden bg-white border-t border-gray-100 absolute w-full shadow-2xl z-50">
        <div class="px-8 py-10 flex flex-col gap-6 text-center">
            @foreach($navs as $path => $label)
                <a href="{{ url($path) }}" 
                   wire:navigate
                   @click="open = false"
                   class="text-2xl text-[#2b0d3e] {{ Request::is($path == '/' ? '/' : $path) ? 'font-bold underline decoration-[#7a3f91]' : 'font-light' }}">
                    {{ $label }}
                </a>
            @endforeach
            <div class="h-px bg-gray-100 w-full"></div>
            <a href="{{ route('login') }}" 
               wire:navigate 
               @click="open = false"
               class="text-2xl text-[#2b0d3e] font-light">LOGIN</a>
        </div>
    </div>
</header>