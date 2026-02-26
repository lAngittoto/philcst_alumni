<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Philcst') }} - Admin</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Vite (Tailwind + App JS) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">

<div x-data="{ open: false }" class="flex h-screen bg-gray-100 font-sans overflow-hidden">

    <!-- Overlay (Mobile Only) -->
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

    <!-- Sidebar -->
    <aside 
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300 
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0 
               flex flex-col h-full text-white overflow-hidden shrink-0"
        style="background-color: #7a3f91;">

        <!-- Header -->
        <div class="flex items-center justify-between h-24 px-6 border-b border-white/10 shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-white leading-tight">
                    Admin<span class="font-light opacity-70">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-50 text-white">
                    Management System
                </p>
            </div>

            <!-- Close button mobile -->
            <button @click="open = false" class="lg:hidden text-white/70 hover:text-white transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        <!-- Nav -->
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">
            
            <a href="{{ route('admin.dashboard') }}" 
               class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group {{ request()->is('admin/dashboard*') ? 'bg-white/20 border border-white/30 shadow-lg' : 'hover:bg-white/10' }}">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 text-white mr-4 shrink-0">
                    <i class="fa-solid fa-gauge-high opacity-80 group-hover:scale-110 transition-transform"></i>
                </div>
                <span class="font-medium tracking-wide">Dashboard</span>
            </a>

            <a href="{{ route('alumni.management') }}" 
               class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group {{ request()->is('alumni/management*') ? 'bg-white/20 border border-white/30 shadow-lg' : 'hover:bg-white/10' }}">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-users-gear opacity-80"></i>
                </div>
                <span class="font-medium tracking-wide">Alumni Management</span>
            </a>

            <a href="/employment" class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <span class="font-medium tracking-wide">Employment Tracking</span>
            </a>

            <a href="/events" class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <span class="font-medium tracking-wide">Events</span>
            </a>

            <a href="/jobs" class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <span class="font-medium tracking-wide">Job Opportunities</span>
            </a>

            <a href="{{ route('admin.yearbook') }}" 
            class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <span class="font-medium tracking-wide">Yearbook</span>
            </a>

            <a href="/reports" class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-file-export"></i>
                </div>
                <span class="font-medium tracking-wide">Reports</span>
            </a>

            <a href="/audit-logs" class="flex items-center px-4 py-3 rounded-xl group hover:bg-white/10 transition-all duration-300">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/10 mr-4 shrink-0">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <span class="font-medium tracking-wide">Audit Logs</span>
            </a>

        </nav>

        <!-- Logout -->
        <div class="p-4 mt-auto border-t border-white/10 shrink-0">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full bg-[#2b0d3e] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#3d1358] transition-all flex items-center justify-center">
                    <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">
        
        <!-- Mobile Header -->
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

        <!-- Content -->
        <div class="flex-1 overflow-y-auto min-h-0 bg-[#f8f9fa] p-4 lg:p-8 no-scrollbar">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>

</div>

<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

</body>
</html>