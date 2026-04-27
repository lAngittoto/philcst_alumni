<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
    class="flex h-screen bg-[#F5F5F5] font-sans overflow-hidden">

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
               flex flex-col h-full text-[#333333] overflow-hidden shrink-0"
        style="background-color: #FFFFFF; border-right: 1px solid #E8E0F0;">

        {{-- Sidebar header --}}
        <div class="flex items-center justify-between h-24 px-6 border-b border-[#E8E0F0] shrink-0">
            <div class="text-left">
                <h1 class="text-2xl font-black tracking-tighter uppercase text-[#333333] leading-tight">
                    Alumni<span class="font-light opacity-70 text-[#7A3F91]">Portal</span>
                </h1>
                <p class="text-[10px] uppercase tracking-[0.2em] opacity-60 text-[#333333] font-bold">
                    Graduate Network
                </p>
            </div>
            <button @click="open = false" class="lg:hidden text-[#7A3F91] hover:text-[#6A3A7F] transition-colors">
                <i class="fa-solid fa-circle-xmark text-2xl"></i>
            </button>
        </div>

        {{-- Alumni info card --}}
        @if($authAlumni)
        <div class="mx-4 mt-5 mb-1 rounded-xl p-4 border" 
             style="background: linear-gradient(135deg, rgba(122,63,145,0.07), rgba(122,63,145,0.03)); border-color: rgba(122,63,145,0.2);">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-full flex items-center justify-center font-black text-sm shrink-0 shadow-md"
                     style="background: linear-gradient(135deg, #7A3F91, #6a3080); color: white;">
                    @if($authAlumni->profile_photo && !str_contains($authAlumni->profile_photo, 'default.png'))
                        <img src="{{ $authAlumni->getProfilePhotoUrl() }}"
                             class="w-11 h-11 rounded-full object-cover" alt="avatar">
                    @else
                        {{ $authAlumni->getAvatarLetter() }}
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-[#333333] truncate leading-tight">
                        {{ $authAlumni->first_name }} {{ $authAlumni->last_name }}
                    </p>
                    <p class="text-[11px] text-[#666666] truncate">
                        {{ $authAlumni->course_code }} · Batch {{ $authAlumni->batch }}
                    </p>
                </div>
            </div>

            {{-- Profile completion badge --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap text-[10px]">
                @if($authAlumni->status === 'VERIFIED')
                    <span class="inline-flex items-center gap-1 font-bold uppercase tracking-widest px-2.5 py-1.5 rounded-lg"
                          style="background: rgba(5, 150, 105, 0.1); color: #059669;">
                        <i class="fa-solid fa-circle-check text-[8px]"></i> Verified
                    </span>
                @elseif($authAlumni->status === 'PENDING')
                    <span class="inline-flex items-center gap-1 font-bold uppercase tracking-widest px-2.5 py-1.5 rounded-lg"
                          style="background: rgba(217, 119, 6, 0.1); color: #D97706;">
                        <i class="fa-solid fa-clock text-[8px]"></i> Pending
                    </span>
                @endif

                {{-- Profile status badge — reactive via Alpine --}}
                <template x-if="!profileComplete">
                    <a href="{{ route('alumni.information') }}"
                       class="inline-flex items-center gap-1 font-bold uppercase tracking-widest
                              px-2.5 py-1.5 rounded-lg transition-colors cursor-pointer"
                       style="background: rgba(217, 119, 6, 0.1); color: #D97706;">
                        <i class="fa-solid fa-triangle-exclamation text-[8px]"></i>
                        Complete Profile
                    </a>
                </template>
                <template x-if="profileComplete">
                    <span class="inline-flex items-center gap-1 font-bold uppercase tracking-widest px-2.5 py-1.5 rounded-lg"
                          style="background: rgba(5, 150, 105, 0.1); color: #059669;">
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
                        'icon'    => 'briefcase',
                        'label'   => 'Employment',
                        'pattern' => 'alumni/employment*',
                    ],
                    [
                        'route'   => 'alumni.messenger',
                        'icon'    => 'comments',
                        'label'   => 'Messages',
                        'pattern' => 'alumni/messenger*',
                    ],
                    [
                        'route'   => 'alumni.yearbook',
                        'icon' => 'book-open',
                        'label'   => 'Yearbook',
                        'pattern' => 'alumni/yearbook*',
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

    {{-- ══ MAIN CONTENT ═════════════════════════════════════════════════════ --}}
    <main class="flex-1 flex flex-col h-full overflow-hidden min-w-0">

        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between px-6 py-4 bg-[#FFFFFF] border-b border-[#E8E0F0] lg:hidden shrink-0 z-30">
            <button @click="open = !open"
                    class="text-[#333333] focus:outline-none p-2 rounded-lg hover:bg-[#F5F5F5] transition-colors">
                <div class="w-6 h-5 relative flex flex-col justify-between">
                    <span :class="open ? 'rotate-45 translate-y-2' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                    <span :class="open ? 'opacity-0' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300"></span>
                    <span :class="open ? '-rotate-45 -translate-y-2.5' : ''"
                          class="w-full h-0.5 bg-[#333333] transition-all duration-300 origin-center"></span>
                </div>
            </button>
            <h2 class="text-lg font-bold text-[#333333]">Alumni Portal</h2>
            <div class="w-10"></div>
        </header>

        {{-- Page content --}}
        <div class="flex-1 overflow-y-auto min-h-0 bg-[#F5F5F5] p-4 lg:p-8 no-scrollbar">
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