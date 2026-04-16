<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} — Registrar Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Sidebar transition */
        #sidebar {
            transition: transform 0.3s cubic-bezier(.4,0,.2,1);
        }

        /* Nav link hover shimmer */
        .nav-link {
            position: relative;
            overflow: hidden;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.04), transparent);
            transform: translateX(-100%);
            transition: transform 0.4s;
        }
        .nav-link:hover::after { transform: translateX(100%); }
    </style>
</head>
<body class="antialiased bg-[#f7f3fe]">

<div x-data="{ open: false }" class="flex h-screen overflow-hidden">

    {{-- ── Mobile Overlay ─────────────────────────────────────────── --}}
    <div x-show="open"
         x-transition:enter="transition-opacity duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="open = false"
         class="fixed inset-0 z-40 bg-black/60 lg:hidden backdrop-blur-sm"
         style="display:none">
    </div>

    {{-- ── Sidebar ─────────────────────────────────────────────────── --}}
    <aside id="sidebar"
           :class="open ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-50 w-64 flex flex-col h-full overflow-hidden shadow-2xl
                  lg:translate-x-0 lg:static lg:inset-auto lg:h-full lg:z-auto shrink-0"
           style="background: linear-gradient(160deg, #1e0630 0%, #2b0d3e 50%, #1a0828 100%);">

        {{-- Logo --}}
        <div class="flex items-center justify-between px-5 py-5 border-b border-white/10 shrink-0">
            <div>
                <div class="flex items-center gap-2 mb-0.5">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0"
                         style="background: linear-gradient(135deg, #7a3f91, #9b59b6);">
                        <i class="fas fa-graduation-cap text-white text-xs"></i>
                    </div>
                    <h1 class="text-lg font-black tracking-tight text-white">
                        Registra<span class="text-[#9b6fbe] font-light">Portal</span>
                    </h1>
                </div>
                <p class="text-[9px] uppercase tracking-[0.25em] text-white/30 font-bold pl-9">
                    Records Management
                </p>
            </div>
            <button @click="open = false" class="lg:hidden text-white/50 hover:text-white transition p-1">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto no-scrollbar">
            @php
                $navLinks = [
                    ['route' => 'registrar.dashboard',              'icon' => 'gauge-high',     'label' => 'Dashboard'],
                    ['route' => 'registrar.alumni',                 'icon' => 'users',           'label' => 'Alumni Records'],
                    ['route' => 'registrar.alumni.register',        'icon' => 'user-plus',       'label' => 'Register Alumni'],
                    ['route' => 'registrar.alumni.import',          'icon' => 'file-import',     'label' => 'Import Alumni'],
                    ['route' => 'registrar.courses',                'icon' => 'book-open',       'label' => 'Courses'],
                    ['route' => 'registrar.information-management', 'icon' => 'database',        'label' => 'Info Management'],
                ];
            @endphp

            @foreach($navLinks as $link)
                @php
                    /* Exact route-name match — only ONE can be active at a time */
                    $isActive = request()->routeIs($link['route']);
                @endphp
                <a href="{{ route($link['route']) }}"
                   wire:navigate
                   class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group
                          {{ $isActive
                              ? 'bg-white/15 shadow-inner shadow-white/5'
                              : 'hover:bg-white/8 hover:translate-x-0.5' }}">

                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 transition-all duration-200
                                {{ $isActive ? 'shadow-lg' : 'bg-white/5 group-hover:bg-white/10' }}"
                         style="{{ $isActive ? 'background: linear-gradient(135deg, #7a3f91, #9b59b6); box-shadow: 0 2px 8px rgba(122,63,145,0.4);' : '' }}">
                        <i class="fas fa-{{ $link['icon'] }} text-xs
                                  {{ $isActive ? 'text-white' : 'text-white/50 group-hover:text-white/80' }}"></i>
                    </div>

                    <span class="text-sm font-semibold leading-tight
                                 {{ $isActive ? 'text-white' : 'text-white/55 group-hover:text-white/90' }}">
                        {{ $link['label'] }}
                    </span>

                    @if($isActive)
                        <div class="ml-auto w-1.5 h-1.5 rounded-full bg-[#9b6fbe] shrink-0"></div>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- User / Logout --}}
        <div class="p-3 border-t border-white/10 shrink-0">
            <div class="flex items-center gap-2.5 px-3 py-2 mb-2">
                <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 bg-white/10">
                    <i class="fas fa-user-tie text-white/60 text-xs"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-bold text-white/80 truncate">{{ auth()->user()->name ?? 'Registrar' }}</p>
                    <p class="text-[10px] text-white/35">Registrar</p>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold uppercase tracking-widest text-white/80 hover:text-white border border-white/10 hover:border-white/20 hover:bg-white/5 transition-all duration-200">
                    <i class="fas fa-right-from-bracket text-xs"></i>
                    Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- ── Main ────────────────────────────────────────────────────── --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-4 py-3.5 bg-white border-b border-gray-200 lg:hidden shrink-0 z-30 shadow-sm">
            <button @click="open = !open"
                    class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 transition text-[#2b0d3e]">
                <i class="fas fa-bars text-base"></i>
            </button>
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0"
                     style="background: linear-gradient(135deg, #7a3f91, #9b59b6);">
                    <i class="fas fa-graduation-cap text-white text-[10px]"></i>
                </div>
                <span class="text-sm font-black text-[#2b0d3e]">RegistraPortal</span>
            </div>
            <div class="w-9"></div>
        </header>

        {{-- Page Content --}}
        <div class="flex-1 overflow-y-auto no-scrollbar min-h-0" style="background:#f7f3fe;">
            @yield('content')
        </div>
    </main>

</div>

@livewireScripts
</body>
</html>