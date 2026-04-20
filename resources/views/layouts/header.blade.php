<style>
    /* ─── HEADER FONT SYSTEM ─── */
    /* Brand name   → Courier New, 900, uppercase, #2b0d3e           */
    /* Brand sub    → Courier New, 700, uppercase, #7a3f91            */
    /* Nav links    → Courier New, uppercase, 1rem / 16px             */
    /* Mobile links → Courier New, uppercase, 1.25rem / 20px          */

    .hdr-brand-name {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        font-size: clamp(1.2rem, 2.5vw, 1.6rem);   /* 19–25px */
        text-transform: uppercase;
        letter-spacing: -0.01em;
        color: #2b0d3e;
        line-height: 1.1;
    }
    .hdr-brand-sub {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: clamp(0.7rem, 1.4vw, 0.95rem);  /* 11–15px */
        text-transform: uppercase;
        letter-spacing: 0.2em;
        color: #7a3f91;
        line-height: 1.1;
    }
    .hdr-nav-link {
        font-family: 'Courier New', monospace;
        font-weight: 400;
        font-size: 1rem;                            /* 16px */
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #2b0d3e;
        position: relative;
        padding-bottom: 0.25rem;
        transition: color 0.25s;
    }
    .hdr-nav-link.is-active {
        font-weight: 700;
    }
    .hdr-nav-link .nav-underline {
        position: absolute;
        bottom: 0; left: 0;
        height: 2px;
        background: #7a3f91;
        transition: width 0.3s ease;
    }
    .hdr-nav-link.is-active .nav-underline { width: 100%; }
    .hdr-nav-link:not(.is-active) .nav-underline { width: 0; }
    .hdr-nav-link:not(.is-active):hover .nav-underline { width: 100%; }

    .hdr-mobile-link {
        font-family: 'Courier New', monospace;
        font-size: 1.25rem;                         /* 20px */
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #2b0d3e;
        font-weight: 400;
    }
    .hdr-mobile-link.is-active {
        font-weight: 700;
        text-decoration: underline;
        text-decoration-color: #7a3f91;
        text-underline-offset: 4px;
    }
</style>

<header
    x-data="{ open: false }"
    class="bg-white/90 backdrop-blur-md sticky top-0 z-50 border-b border-[#e0d5ee] w-full transition-all duration-500"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20 lg:h-28 transition-all duration-500">

            {{-- ── Logo ── --}}
            <a class="flex items-center gap-2 sm:gap-3 shrink-0">
                <img src="{{ asset('images/logo.png') }}" alt="PhilCST Logo"
                     class="h-12 w-auto lg:h-16 drop-shadow-sm">
                <h1 class="flex flex-col leading-tight">
                    <span class="hdr-brand-name">Philcst</span>
                    <span class="hdr-brand-sub">Alumni Connect</span>
                </h1>
            </a>

            {{-- ── Desktop Nav ── --}}
            <nav class="hidden lg:flex items-center space-x-10">
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
                       class="hdr-nav-link {{ $isActive ? 'is-active' : '' }}">
                        {{ $label }}
                        <span class="nav-underline"></span>
                    </a>
                @endforeach

                <a href="{{ route('login') }}"
                   wire:navigate
                   class="hdr-nav-link">
                    Login
                    <span class="nav-underline"></span>
                </a>
            </nav>

            {{-- ── Mobile Burger ── --}}
            <div class="lg:hidden flex items-center">
                <button @click="open = !open"
                        class="text-[#2b0d3e] p-2 focus:outline-none"
                        aria-label="Toggle menu">
                    <div class="w-6 h-5 relative flex flex-col justify-between">
                        <span :class="open ? 'rotate-45 translate-y-2.5' : ''"
                              class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                        <span :class="open ? 'opacity-0' : ''"
                              class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                        <span :class="open ? '-rotate-45 -translate-y-2' : ''"
                              class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
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
         class="lg:hidden bg-white border-t border-[#e0d5ee] absolute w-full shadow-2xl z-50">
        <div class="px-8 py-10 flex flex-col gap-6 text-center">

            @foreach($navs as $path => $label)
                @php $isActive = Request::is($path === '/' ? '/' : $path); @endphp
                <a href="{{ url($path) }}"
                   wire:navigate
                   @click="open = false"
                   class="hdr-mobile-link {{ $isActive ? 'is-active' : '' }}">
                    {{ $label }}
                </a>
            @endforeach

            <div class="h-px bg-[#e0d5ee] w-full"></div>

            <a href="{{ route('login') }}"
               wire:navigate
               @click="open = false"
               class="hdr-mobile-link">
                Login
            </a>
        </div>
    </div>
</header>