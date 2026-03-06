{{-- resources/views/organizer/dashboard.blade.php --}}

@extends('layouts.sidebar-organizer')

@section('content')

@php $organizer = auth()->user()->organizer; @endphp

@if (!$organizer)
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
        <i class="fa-solid fa-circle-exclamation mr-2"></i>
        Organizer record not found. Please contact the administrator.
    </div>
@else

{{-- Page heading --}}
<div class="mb-8">
    <h1 class="text-3xl font-black text-[#2b0d3e]">Dashboard</h1>
    <p class="text-gray-500 mt-1">Welcome back, <span class="font-semibold text-[#7a3f91]">{{ $organizer->name }}</span>!</p>
</div>

{{-- Stats Grid --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">

    <div class="bg-white rounded-2xl shadow p-6 hover:shadow-md transition-all border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-gray-500 font-semibold text-xs uppercase tracking-widest">Account Status</h3>
            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color: #f0e6f7;">
                <i class="fa-solid fa-user text-[#7a3f91]"></i>
            </div>
        </div>
        <p class="text-2xl font-black text-[#2b0d3e] mb-1">{{ $organizer->status ?? 'Unknown' }}</p>
        <p class="text-xs text-gray-400">Your current account status</p>
    </div>

    <div class="bg-white rounded-2xl shadow p-6 hover:shadow-md transition-all border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-gray-500 font-semibold text-xs uppercase tracking-widest">Department</h3>
            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color: #f0e6f7;">
                <i class="fa-solid fa-building text-[#7a3f91]"></i>
            </div>
        </div>
        <p class="text-2xl font-black text-[#2b0d3e] mb-1 truncate">{{ $organizer->department ?? 'N/A' }}</p>
        <p class="text-xs text-gray-400">Your assigned department</p>
    </div>

    <div class="bg-white rounded-2xl shadow p-6 hover:shadow-md transition-all border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-gray-500 font-semibold text-xs uppercase tracking-widest">Teacher ID</h3>
            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color: #f0e6f7;">
                <i class="fa-solid fa-id-card text-[#7a3f91]"></i>
            </div>
        </div>
        <p class="text-2xl font-black text-[#2b0d3e] mb-1">{{ $organizer->id_number ?? 'N/A' }}</p>
        <p class="text-xs text-gray-400">Your ID number</p>
    </div>

    <div class="bg-white rounded-2xl shadow p-6 hover:shadow-md transition-all border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-gray-500 font-semibold text-xs uppercase tracking-widest">Password</h3>
            <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-green-50">
                <i class="fa-solid fa-lock text-green-600"></i>
            </div>
        </div>
        <p class="text-2xl font-black text-green-600 mb-1">Secure ✓</p>
        <p class="text-xs text-gray-400">Password has been set</p>
    </div>

</div>

{{-- Profile + Quick Actions --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Profile --}}
    <div class="lg:col-span-2 bg-white rounded-2xl shadow p-8 border border-gray-100">
        <h2 class="text-xl font-black text-[#2b0d3e] mb-6 flex items-center gap-2">
            <i class="fa-solid fa-user-circle text-[#7a3f91]"></i> Profile Information
        </h2>
        <div class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 pb-4 border-b border-gray-100">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest sm:w-32 shrink-0">Full Name</span>
                <span class="text-gray-800 font-semibold">{{ auth()->user()->name }}</span>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 pb-4 border-b border-gray-100">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest sm:w-32 shrink-0">Email</span>
                <span class="text-gray-800 font-semibold">{{ auth()->user()->email }}</span>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 pb-4 border-b border-gray-100">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest sm:w-32 shrink-0">Department</span>
                <span class="text-gray-800 font-semibold">{{ $organizer->department ?? 'Not assigned' }}</span>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest sm:w-32 shrink-0">Status</span>
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold
                    {{ $organizer->status === 'ACTIVE'    ? 'bg-green-100 text-green-800'   : '' }}
                    {{ $organizer->status === 'INACTIVE'  ? 'bg-yellow-100 text-yellow-800' : '' }}
                    {{ $organizer->status === 'SUSPENDED' ? 'bg-red-100 text-red-800'       : '' }}">
                    {{ $organizer->status ?? 'Unknown' }}
                </span>
            </div>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="bg-white rounded-2xl shadow p-8 border border-gray-100">
        <h2 class="text-xl font-black text-[#2b0d3e] mb-6 flex items-center gap-2">
            <i class="fa-solid fa-bolt text-[#7a3f91]"></i> Quick Actions
        </h2>
        <div class="space-y-3">
            <a href="{{ route('organizer.events') }}"
               class="flex items-center gap-3 w-full px-4 py-3 rounded-xl font-bold text-sm transition-all hover:scale-[1.02] active:scale-95 text-white"
               style="background-color: #7a3f91;">
                <i class="fa-solid fa-calendar-check w-4 text-center"></i> Event Manager
            </a>
            <a href="{{ route('organizer.jobs') }}"
               class="flex items-center gap-3 w-full px-4 py-3 rounded-xl font-bold text-sm transition-all hover:scale-[1.02] active:scale-95 bg-blue-600 text-white">
                <i class="fa-solid fa-briefcase w-4 text-center"></i> Job Posting
            </a>
            <a href="{{ route('organizer.employment') }}"
               class="flex items-center gap-3 w-full px-4 py-3 rounded-xl font-bold text-sm transition-all hover:scale-[1.02] active:scale-95 bg-emerald-600 text-white">
                <i class="fa-solid fa-chart-line w-4 text-center"></i> Employment Tracking
            </a>
            <a href="{{ route('organizer.reports') }}"
               class="flex items-center gap-3 w-full px-4 py-3 rounded-xl font-bold text-sm transition-all hover:scale-[1.02] active:scale-95 bg-orange-500 text-white">
                <i class="fa-solid fa-file-export w-4 text-center"></i> Generate Report
            </a>
        </div>
    </div>

</div>

{{-- Footer note --}}
<div class="mt-8 bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
    <p class="text-sm text-gray-400">
        <i class="fa-solid fa-info-circle mr-2 text-[#7a3f91]"></i>
        For questions or assistance, please contact the system administrator.
    </p>
</div>

@endif

@endsection