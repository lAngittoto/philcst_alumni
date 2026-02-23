<header 
    x-data="{ open: false }" 
    class="bg-[#ffffff] backdrop-blur-md shadow-sm sticky top-0 z-50 border-b border-gray-100 font-sans transition-all duration-300 w-full"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20 lg:h-37"> 
            
            <div class="flex items-center gap-2 sm:gap-3 shrink-0 cursor-pointer group" onclick="window.location='/'">
                <img src="{{ asset('images/logo.png') }}" alt="logo" 
                     class="h-10 w-auto sm:h-12 lg:h-14 drop-shadow-sm group-hover:scale-105 transition-transform duration-300">
                
                <h1 class="flex flex-col leading-tight">
                    <span class="text-[#2b0d3e] font-black text-[20px] sm:text-lg md:text-2xl uppercase tracking-tight">
                        Philcst
                    </span>
                    <span class="text-[#7a3f91] font-extrabold text-[15px] sm:text-lg md:text-xl uppercase tracking-wider -mt-0.5">
                        Alumni Connect
                    </span>
                </h1>
            </div>

            <nav class="hidden lg:flex items-center space-x-8">
                <a href="/" class="{{ Request::is('/') ? 'text-[#7a3f91] border-b-2 border-[#7a3f91]' : 'text-[#2b0d3e]' }} font-bold text-lg uppercase transition duration-300 pb-1">
                    Home
                </a>

                <a href="/about" class="{{ Request::is('about') ? 'text-[#7a3f91] border-b-2 border-[#7a3f91]' : 'text-[#2b0d3e]' }} font-bold text-lg uppercase transition duration-300 pb-1">
                    About
                </a>

                <a href="/events" class="{{ Request::is('events') ? 'text-[#7a3f91] border-b-2 border-[#7a3f91]' : 'text-[#2b0d3e]' }} font-bold text-lg uppercase transition duration-300 pb-1">
                    Events
                </a>
                
<a href="{{ route('login') }}" class="bg-[#2b0d3e] text-white px-6 py-2.5 rounded-full font-bold text-sm hover:bg-[#7a3f91] transition duration-300 shadow-md active:scale-95 uppercase">
    LOGIN
</a>
            </nav>

            <div class="lg:hidden flex items-center">
                <button @click="open = !open" class="text-[#2b0d3e] focus:outline-none p-2 rounded-lg" :class="open ? 'bg-gray-100' : ''">
                    <div class="w-6 h-5 relative flex flex-col justify-between">
                        <span :class="open ? 'rotate-45 translate-y-2' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                        <span :class="open ? 'opacity-0' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                        <span :class="open ? '-rotate-45 -translate-y-2.5' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <div x-show="open" 
         x-cloak 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="lg:hidden bg-white border-t border-gray-50 absolute w-full shadow-xl">
        <div class="px-6 py-6 space-y-1 flex flex-col font-bold">
            <a href="/" class="{{ Request::is('/') ? 'text-[#7a3f91] bg-purple-50' : 'text-[#2b0d3e]' }} py-3 px-4 rounded-lg border-b border-gray-50 uppercase">Home</a>
            <a href="/about" class="{{ Request::is('about') ? 'text-[#7a3f91] bg-purple-50' : 'text-[#2b0d3e]' }} py-3 px-4 rounded-lg border-b border-gray-50 uppercase">About</a>
            <a href="/events" class="{{ Request::is('events') ? 'text-[#7a3f91] bg-purple-50' : 'text-[#2b0d3e]' }} py-3 px-4 rounded-lg border-b border-gray-50 uppercase">Events</a>
            <a href="{{ route('login') }}" class="block w-full text-center bg-[#2b0d3e] text-white py-4 rounded-xl uppercase">LOGIN</a>
        </div>
    </div>
</header>