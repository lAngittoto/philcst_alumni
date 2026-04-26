<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Philcst') }} - Registrar</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="antialiased">

<div x-data="{ open: false }" class="flex h-screen bg-[#F5F5F5] font-xl sans overflow-hidden">

    {{-- Mobile Overlay --}}
    <div 
        x-show="open"
        x-transition:enter="transition opacity-ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition opacity-ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-40 bg-black/50 lg:hidden">
    </div>

    {{-- Sidebar --}}
    <aside 
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300 
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0 
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0;">

        {{-- Logo --}}
        <div class="flex items-center justify-between h-24 px-6 border-b border-[#E8E0F0] shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-[#333333] leading-tight">
                    Registrar<span class="font-light opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-bold">
                    Records Management
                </p>
            </div>

            <button @click="open = false" class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">
            @php
                $sidebarLinks = [
                    [
                        'route' => 'registrar.dashboard',
                        'icon' => 'gauge-high',
                        'label' => 'Dashboard',
                    ],
                    [
                        'route' => 'registrar.alumni',
                        'icon' => 'users',
                        'label' => 'Alumni Records',
                    ],
                    [
                        'route' => 'registrar.alumni.register',
                        'icon' => 'user-plus',
                        'label' => 'Register Alumni',
                    ],
                    [
                        'route' => 'registrar.alumni.import',
                        'icon' => 'file-import',
                        'label' => 'Import Alumni',
                    ],

                ];

                $currentRoute = request()->route()?->getName();
            @endphp

            @foreach($sidebarLinks as $link)
                @php
                    $url = route($link['route']);
                    $isActive = $currentRoute === $link['route'];
                @endphp

                <a href="{{ $url }}"
                   wire:navigate
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group
                          {{ $isActive ? 'bg-[#F5F5F5] border border-[#E8E0F0] shadow-md' : 'hover:bg-[#F9F7FC]' }}">
                    <div class="w-10 h-10 flex items-center justify-center rounded-lg transition-transform duration-300 group-hover:scale-110 shrink-0 mr-4"
                         :style="'background-color: ' + ('{{ $isActive }}' === '1' ? '#F5F5F5' : '#F9F7FC') + '; color: #7A3F91;'">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-90"></i>
                    </div>
                    <span class="font-medium tracking-wide text-[#333333]">{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-[#E8E0F0] shrink-0">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-xs transition-all flex items-center justify-center shadow-lg active:scale-95 hover:brightness-110"
                        style="background: linear-gradient(135deg, #7A3F91, #6a3080);">
                    <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">
        
        {{-- Mobile Top Bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-[#FFFFFF] border-b border-[#E8E0F0] lg:hidden shrink-0 z-30">
            <button @click="open = !open" class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''" class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''" class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''" class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#333333]">Registrar Panel</h2>
            <div class="w-10"></div>
        </header>

        {{-- Page Content --}}
        <div class="flex-1 overflow-y-auto min-h-0 bg-[#F5F5F5] p-4 lg:p-8 no-scrollbar">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>

</div>

@livewireScripts

<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

</body>
</html>