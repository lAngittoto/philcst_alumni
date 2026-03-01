<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Philcst') }} - Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="antialiased">

<div x-data="{ open: false }" class="flex h-screen bg-gray-100 font-sans overflow-hidden">

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

    <aside 
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300 
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0 
               flex flex-col h-full text-white overflow-hidden shrink-0"
        style="background-color: #2b0d3e;"> {{-- DARK COLOR PARA SA SIDEBAR --}}

        <div class="flex items-center justify-between h-24 px-6 border-b border-white/10 shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-white leading-tight">
                    Admin<span class="font-light opacity-70 text-[#7a3f91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-50 text-white font-bold">
                    Management System
                </p>
            </div>

            <button @click="open = false" class="lg:hidden text-white/70 hover:text-white transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">
            
            @php
                $sidebarLinks = [
                    ['route' => 'admin.dashboard', 'icon' => 'gauge-high', 'label' => 'Dashboard', 'pattern' => 'admin/dashboard*'],
                    ['route' => 'user.management', 'icon' => 'users-gear', 'label' => 'User Management', 'pattern' => 'user/management*'],
                    ['url' => '/employment', 'icon' => 'chart-line', 'label' => 'Employment Tracking'],
                    ['url' => '/events', 'icon' => 'calendar-check', 'label' => 'Events'],
                    ['url' => '/jobs', 'icon' => 'briefcase', 'label' => 'Job Opportunities'],
                    ['route' => 'admin.yearbook', 'icon' => 'book-open', 'label' => 'Yearbook'],
                    ['url' => '/reports', 'icon' => 'file-export', 'label' => 'Reports'],
                    ['url' => '/audit-logs', 'icon' => 'clipboard-list', 'label' => 'Audit Logs'],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php
                    $url = isset($link['route']) ? route($link['route']) : $link['url'];
                    $isActive = isset($link['pattern']) ? request()->is($link['pattern']) : request()->is(ltrim($link['url'] ?? '', '/'));
                @endphp
                <a href="{{ $url }}" 
                   wire:navigate
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group {{ $isActive ? 'bg-white/10 border border-white/20 shadow-lg' : 'hover:bg-white/5' }}">
                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/5 text-white mr-4 shrink-0 transition-transform duration-300 group-hover:scale-110">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-80"></i>
                    </div>
                    <span class="font-medium tracking-wide">{{ $link['label'] }}</span>
                </a>
            @endforeach

        </nav>

        <div class="p-4 mt-auto border-t border-white/10 shrink-0">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" 
                        class="w-full text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-xs transition-all flex items-center justify-center shadow-lg active:scale-95 hover:brightness-110"
                        style="background-color: #7a3f91;"> {{-- LIGHT COLOR PARA SA LOGOUT --}}
                    <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">
        
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-gray-200 lg:hidden shrink-0 z-30">
            <button @click="open = !open" class="text-[#2b0d3e] focus:outline-none p-2 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#2b0d3e]">Admin Panel</h2>
            <div class="w-10"></div>
        </header>

        <div class="flex-1 overflow-y-auto min-h-0 bg-[#f8f9fa] p-4 lg:p-8 no-scrollbar">
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