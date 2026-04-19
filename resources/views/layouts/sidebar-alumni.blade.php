<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Philcst') }} - Alumni</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="antialiased">

@php
    $authAlumni       = auth()->user()?->alumni;
    $profileCompleted = (bool)($authAlumni?->profile_completed ?? false);
@endphp

<div
    x-data="{
        open:            false,
        profileComplete: {{ $profileCompleted ? 'true' : 'false' }},
    }"
    x-on:profile-updated.window="profileComplete = $event.detail.completed"
    class="flex h-screen bg-gray-100 font-sans overflow-hidden">

    {{-- ── Mobile overlay ─────────────────────────────────────────────────── --}}
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

    {{-- ══ SIDEBAR ══════════════════════════════════════════════════════════ --}}
    <aside
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-72 min-w-[18rem] transform transition-transform duration-300
               shadow-2xl lg:translate-x-0 lg:static lg:inset-0
               flex flex-col h-full text-white overflow-hidden shrink-0"
        style="background-color: #2b0d3e;">

        {{-- Sidebar header --}}
        <div class="flex items-center justify-between h-24 px-6 border-b border-white/10 shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-white leading-tight">
                    Alumni<span class="font-light opacity-70" style="color: #7a3f91;">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-50 text-white font-bold">
                    Graduate Network
                </p>
            </div>
            <button @click="open = false" class="lg:hidden text-white/70 hover:text-white transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        {{-- Alumni info card --}}
        @if($authAlumni)
        <div class="mx-4 mt-5 mb-1 rounded-xl p-4 border border-white/10" style="background-color: rgba(255,255,255,0.05);">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-black text-sm shrink-0 shadow-lg"
                     style="background-color: #7a3f91;">
                    @if($authAlumni->profile_photo && !str_contains($authAlumni->profile_photo, 'default.png'))
                        <img src="{{ $authAlumni->getProfilePhotoUrl() }}"
                             class="w-10 h-10 rounded-full object-cover" alt="avatar">
                    @else
                        {{ $authAlumni->getAvatarLetter() }}
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-white truncate leading-tight">
                        {{ $authAlumni->first_name }} {{ $authAlumni->last_name }}
                    </p>
                    <p class="text-[11px] text-white/50 truncate">
                        {{ $authAlumni->course_code }} · Batch {{ $authAlumni->batch }}
                    </p>
                </div>
            </div>

            {{-- Profile completion badge --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap">
                @if($authAlumni->status === 'VERIFIED')
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-green-500/20 text-green-400">
                        <i class="fa-solid fa-circle-check text-[8px]"></i> Verified
                    </span>
                @elseif($authAlumni->status === 'PENDING')
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-yellow-500/20 text-yellow-400">
                        <i class="fa-solid fa-clock text-[8px]"></i> Pending
                    </span>
                @endif

                {{-- Profile status badge — reactive via Alpine --}}
                <template x-if="!profileComplete">
                    <a href="{{ route('alumni.information') }}"
                       class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest
                              px-2 py-1 rounded-full bg-amber-500/20 text-amber-300
                              hover:bg-amber-500/30 transition-colors cursor-pointer">
                        <i class="fa-solid fa-triangle-exclamation text-[8px]"></i>
                        Update Profile to Unlock More Features
                    </a>
                </template>
                <template x-if="profileComplete">
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-emerald-500/20 text-emerald-300">
                        <i class="fa-solid fa-circle-check text-[8px]"></i> Profile Complete
                    </span>
                </template>
            </div>
        </div>
        @endif

        {{-- Navigation --}}
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto no-scrollbar">

            @php
                $sidebarLinks = [
                    [
                        'route'   => 'alumni.dashboard',
                        'icon'    => 'gauge-high',
                        'label'   => 'Dashboard',
                        'pattern' => 'alumni/dashboard*',
                    ],
                    [
                        'route'   => 'alumni.information',
                        'icon'    => 'user-circle',
                        'label'   => 'My Profile',
                        'pattern' => 'alumni/information*',
                    ],
                    [
                        'route'   => 'job.opportunities',
                        'icon'    => 'briefcase',
                        'label'   => 'Job Board',
                        'pattern' => 'job/opportunities*',
                    ],
                    [
                        'route'   => 'upcoming.events',
                        'icon'    => 'calendar',
                        'label'   => 'Events',
                        'pattern' => 'upcoming/events*',
                    ],
                    [
                        'route'   => 'alumni.employment',
                        'icon'    => 'calendar',
                        'label'   => 'Employment',
                        'pattern' => 'alumni/employment*',
                    ],
                    [
                        'route'   => 'alumni.messenger',
                        'icon'    => 'calendar',
                        'label'   => 'Messenger',
                        'pattern' => 'alumni/messenger*',
                    ],
                ];
            @endphp

            @foreach($sidebarLinks as $link)
                @php
                    $url      = route($link['route']);
                    $isActive = request()->is($link['pattern']);
                @endphp

                <a href="{{ $url }}"
                   wire:navigate
                   class="flex items-center px-4 py-3 transition-all duration-300 rounded-xl group relative
                          {{ $isActive ? 'bg-white/10 border border-white/20 shadow-lg' : 'hover:bg-white/5' }}">
                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-white/5 text-white mr-4 shrink-0 transition-transform duration-300 group-hover:scale-110">
                        <i class="fa-solid fa-{{ $link['icon'] }} opacity-80"></i>
                    </div>
                    <span class="font-medium tracking-wide">{{ $link['label'] }}</span>
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

    {{-- ══ MAIN CONTENT ═════════════════════════════════════════════════════ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-white border-b border-gray-200 lg:hidden shrink-0 z-30">
            <button @click="open = !open"
                    class="focus:outline-none p-2 rounded-lg hover:bg-gray-100 transition-colors"
                    style="color: #2b0d3e;">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 transition-all duration-300 origin-center"
                          style="background-color: #2b0d3e;"></span>
                    <span :class="open ? 'opacity-0' : ''"
                          class="w-full h-0.5 transition-all duration-300"
                          style="background-color: #2b0d3e;"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 transition-all duration-300 origin-center"
                          style="background-color: #2b0d3e;"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold" style="color: #2b0d3e;">Alumni Portal</h2>
            <div class="w-10"></div>
        </header>

        {{-- Page content --}}
        <div class="flex-1 overflow-y-auto min-h-0 bg-gray-100 p-4 lg:p-8 no-scrollbar">
            <div class="container mx-auto">
                @yield('content')
            </div>
        </div>
    </main>

</div>{{-- /x-data shell --}}

@livewireScripts

<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

</body>
</html>