<header
    x-data="{ open: false }"
    class="bg-[#FFFFFF] sticky top-0 z-50 border-b border-[#e8e8e8] w-full transition-all duration-500"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20 sm:h-24 lg:h-28 transition-all duration-500">

            {{-- ── Logo ── --}}
            <div class="flex items-center gap-2 sm:gap-3 lg:gap-4 shrink-0">
                <img src="{{ asset('images/logo.png') }}" alt="PhilCST Logo"
                     class="h-12 sm:h-14 lg:h-16 w-auto">
                <div class="flex flex-col leading-tight">
                    <span class="font-sans font-bold text-lg sm:text-xl lg:text-2xl uppercase text-[#7a3f91]">Philcst</span>
                    <span class="font-sans font-medium text-sm sm:text-base lg:text-lg uppercase text-[#333333]">Alumni Connect</span>
                </div>
            </div>

            {{-- ── Desktop Nav ── --}}
            <nav class="hidden lg:flex items-center space-x-8">
                @php
                    $navs = [
                        '/'           => 'Home',
                        'about'       => 'About',
                        'showevents'  => 'Events',
                    ];
                @endphp

                @foreach($navs as $path => $label)
                    @php $isActive = Request::is($path === '/' ? '/' : $path); @endphp
                    <a href="{{ url($path) }}"
                       wire:navigate
                       class="font-sans font-medium text-base xl:text-lg text-[#333333] relative pb-1 group transition-all duration-300 {{ $isActive ? 'font-semibold' : '' }}">
                        {{ $label }}
                        <span class="absolute bottom-0 left-0 h-0.5 bg-[#333333] transition-all duration-300 {{ $isActive ? 'w-full' : 'w-0 group-hover:w-full' }}"></span>
                    </a>
                @endforeach

                <div class="w-px h-6 bg-[#d0d0d0]"></div>

                <a href="{{ route('login') }}"
                   wire:navigate
                   class="font-sans font-medium text-base xl:text-lg text-[#333333] relative pb-1 group transition-all duration-300">
                    Login
                    <span class="absolute bottom-0 left-0 h-0.5 bg-[#333333] transition-all duration-300 w-0 group-hover:w-full"></span>
                </a>
            </nav>

            {{-- ── Mobile Burger ── --}}
            <div class="lg:hidden flex items-center">
                <button @click="open = !open"
                        class="text-[#333333] p-2 hover:bg-[#f5f5f5] rounded-lg transition-colors duration-300"
                        aria-label="Toggle menu">
                    <div class="w-6 h-5 relative flex flex-col justify-between">
                        <span :class="open ? 'rotate-45 translate-y-2.5' : ''"
                              class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                        <span :class="open ? 'opacity-0' : ''"
                              class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                        <span :class="open ? '-rotate-45 -translate-y-2' : ''"
                              class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    </div>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Mobile Menu ── --}}
    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-10"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-10"
         class="lg:hidden bg-white border-t border-[#e8e8e8] absolute w-full shadow-lg z-50">
        <div class="px-6 sm:px-10 py-8 flex flex-col gap-5 text-center">

            @foreach($navs as $path => $label)
                @php $isActive = Request::is($path === '/' ? '/' : $path); @endphp
                <a href="{{ url($path) }}"
                   wire:navigate
                   @click="open = false"
                   class="font-sans font-medium text-base sm:text-lg text-[#333333] transition-all duration-300 {{ $isActive ? 'font-semibold underline decoration-[#333333] underline-offset-2' : '' }}">
                    {{ $label }}
                </a>
            @endforeach

            <div class="h-px bg-[#d0d0d0] w-full"></div>

            <a href="{{ route('login') }}"
               wire:navigate
               @click="open = false"
               class="font-sans font-medium text-base sm:text-lg text-[#333333] transition-all duration-300">
                Login
            </a>
        </div>
    </div>
</header>