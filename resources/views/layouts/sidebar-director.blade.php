<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Philcst') }} - Alumni Director</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="antialiased">

<div x-data="{ open: false }" class="flex h-screen bg-gray-100 font-sans overflow-hidden">

    {{-- Mobile overlay --}}
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
               flex flex-col h-full text-white overflow-hidden shrink-0"
        style="background-color: #2b0d3e;">

        {{-- Logo / Branding --}}
        <div class="flex items-center justify-between h-24 px-6 border-b border-white/10 shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-white leading-tight">
                    Alumni<span class="font-light opacity-70 text-[#7a3f91]">Director</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-50 text-white font-bold">
                    Alumni Management System
                </p>
            </div>
            <button @click="open = false" class="lg:hidden text-white/70 hover:text-white transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        {{-- Director Info --}}
        @php $director = auth()->user()?->director; @endphp
        @if ($director)
            <div class="px-6 py-4 border-b border-white/10 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#7a3f91] flex items-center justify-center font-bold text-lg text-white shrink-0">
                        {{ strtoupper(substr($director->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-white truncate">{{ $director->name }}</p>
                        <p class="text-xs text-white/50 truncate">{{ $director->department ?? 'Alumni Director' }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto no-scrollbar">

            @php
                $sidebarLinks = [
                    [
                        'route'   => 'director.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'director/dashboard*',
                    ],
                    [
                        'route'   => 'director.coordinator/management',
                        'icon'    => 'users-gear',
                        'label'   => 'Manage Coordinator',
                        'pattern' => 'director/coordinator/management*',
                    ],
                    [
                        'route'   => 'director.event/management',
                        'icon'    => 'calendar-check',
                        'label'   => 'Manage Event',
                        'pattern' => 'director/event/management*',
                    ],
                    [
                        'route'   => 'director.job/management',
                        'icon'    => 'briefcase',
                        'label'   => 'Manage Job',
                        'pattern' => 'director/job/management*',
                    ],
                    [
                        'route'   => 'director.director/messenger',
                        'icon'    => 'comments',
                        'label'   => 'Messenger',
                        'pattern' => 'director/messenger*',
                    ],
                    [
                        'route'   => 'director.manage/employment',
                        'icon'    => 'chart-line',
                        'label'   => 'Manage Employment',
                        'pattern' => 'manage/employment*',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php
                    $url = route($link['route']);
                    $isActive = request()->is($link['pattern']);
                @endphp
                <a href="{{ $url }}"
                   wire:navigate
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group
                          {{ $isActive ? 'bg-white/10 border border-white/20 shadow-lg' : 'hover:bg-white/5' }}">
                    <div class="w-10 h-10 flex items-center justify-center rounded-lg mr-4 shrink-0 transition-transform duration-300 group-hover:scale-110
                                {{ $isActive ? 'bg-[#7a3f91]' : 'bg-white/5' }}">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-80"></i>
                    </div>
                    <span class="font-medium tracking-wide">{{ $link['label'] }}</span>

                    @if ($isActive)
                        <i class="fa-solid fa-chevron-right ml-auto text-xs opacity-50"></i>
                    @endif
                </a>
            @endforeach

        </nav>

        {{-- Logout --}}
        <div class="p-4 mt-auto border-t border-white/10 shrink-0">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-xs transition-all flex items-center justify-center shadow-lg active:scale-95 hover:brightness-110"
                        style="background-color: #7a3f91;">
                    <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    {{-- Main content area --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-gray-200 lg:hidden shrink-0 z-30">
            <button @click="open = !open" class="text-[#2b0d3e] focus:outline-none p-2 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''" class="w-full h-0.5 bg-[#2b0d3e] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#2b0d3e]">Director Panel</h2>
            <div class="w-10"></div>
        </header>

        {{-- Page content --}}
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