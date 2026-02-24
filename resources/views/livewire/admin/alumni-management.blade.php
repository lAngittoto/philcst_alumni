@extends('layouts.sidebar-admin')

@section('content')
{{-- ALPINE.JS STATE: Dito natin kino-control yung pagbukas ng Modal --}}
<div x-data="{ openRegisterModal: false }" class="p-4 lg:p-6 flex flex-col h-[calc(100vh-40px)] font-sans overflow-hidden">
    
    {{-- Header Section --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 shrink-0">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Alumni Management</h1>
            <p class="text-lg text-gray-500">Verify and manage the alumni database</p>
        </div>
        <div class="flex gap-3 mt-4 md:mt-0">
            {{-- BUTTON TO OPEN MODAL --}}
            <button @click="openRegisterModal = true" class="px-6 py-3 bg-[#7a3f91] hover:bg-[#2b0d3e] text-white text-base font-bold rounded-xl transition-all shadow-lg flex items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> Register New Student
            </button>
            <button class="px-6 py-3 bg-white border-2 border-[#7a3f91] text-[#7a3f91] text-base font-bold hover:bg-purple-50 rounded-xl transition-all">
                Import File
            </button>
        </div>
    </div>

    {{-- Filters Section --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 shrink-0">
        <div class="flex flex-col gap-2">
            <label class="text-sm font-black text-gray-600 uppercase tracking-widest ml-1">Search Database</label>
            <input type="search" placeholder="Search name or ID..." class="p-3 border-2 border-gray-200 rounded-xl text-base focus:ring-4 focus:ring-purple-100 focus:border-[#7a3f91] outline-none bg-white transition-all">
        </div>
        <div class="flex flex-col gap-2">
            <label class="text-sm font-black text-gray-600 uppercase tracking-widest ml-1">Graduation Year</label>
            <select class="p-3 border-2 border-gray-200 rounded-xl text-base bg-white outline-none focus:border-[#7a3f91]">
                <option>All Years</option>
                @for ($i = date('Y'); $i >= 2000; $i--)
                    <option value="{{ $i }}">{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="flex flex-col gap-2">
            <label class="text-sm font-black text-gray-600 uppercase tracking-widest ml-1">Course / Department</label>
            <select class="p-3 border-2 border-gray-200 rounded-xl text-base bg-white outline-none focus:border-[#7a3f91]">
                <option>All Courses</option>
                <option>BSIT</option>
                <option>BSCS</option>
                <option>BSIS</option>
            </select>
        </div>
    </div>

    {{-- Table Section (Saktong sukat sa screen) --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col flex-1 overflow-hidden">
        <div class="flex-1 overflow-y-auto no-scrollbar">
            <table class="w-full text-left border-collapse sticky-header">
                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                    <tr>
                        <th class="p-5 text-sm font-black text-gray-700 uppercase">Alumnus Name</th>
                        <th class="p-5 text-sm font-black text-gray-700 uppercase">Student ID</th>
                        <th class="p-5 text-sm font-black text-gray-700 uppercase">Course</th>
                        <th class="p-5 text-sm font-black text-gray-700 uppercase text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @for ($i = 1; $i <= 15; $i++)
                    <tr class="hover:bg-gray-50/80 transition-colors group">
                        <td class="p-5">
                            <div class="flex flex-col">
                                <span class="text-lg font-bold text-gray-900 group-hover:text-[#7a3f91]">Juan Dela Cruz {{ $i }}</span>
                                <span class="text-sm text-gray-400 font-medium italic">juan{{$i}}@philcst.edu.ph</span>
                            </div>
                        </td>
                        <td class="p-5 text-base text-gray-600 font-mono font-bold tracking-tight">2021-00{{ $i }}</td>
                        <td class="p-5">
                            <span class="px-3 py-1 bg-purple-100 text-[#7a3f91] text-xs rounded-lg font-black border border-purple-200 uppercase">BSIT</span>
                        </td>
                        <td class="p-5 text-center">
                            <span class="inline-flex items-center text-emerald-700 text-sm font-black px-4 py-1.5 bg-emerald-50 rounded-full border border-emerald-200">
                                <span class="w-2 h-2 bg-emerald-500 rounded-full mr-2 animate-pulse"></span> VERIFIED
                            </span>
                        </td>
                    </tr>
                    @endfor
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="p-4 border-t border-gray-200 flex items-center justify-between bg-white shrink-0">
            <span class="text-sm font-medium text-gray-500">Showing 1 to 15 of 500 entries</span>
            <div class="flex items-center gap-2">
                <button class="px-4 py-2 border-2 rounded-xl text-gray-400 font-bold hover:bg-gray-50 transition-all">Prev</button>
                <button class="px-4 py-2 rounded-xl bg-[#7a3f91] text-white font-black shadow-md">1</button>
                <button class="px-4 py-2 rounded-xl border-2 font-bold hover:bg-gray-50">2</button>
                <button class="px-4 py-2 border-2 rounded-xl text-[#7a3f91] font-black hover:bg-purple-50">Next</button>
            </div>
        </div>
    </div>

    {{-- ========================================== --}}
    {{-- REGISTER STUDENT MODAL (Pop-up) --}}
    {{-- ========================================== --}}
    <div 
        x-show="openRegisterModal" 
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 overflow-hidden"
        x-cloak>
        
        <div 
            x-show="openRegisterModal" 
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="openRegisterModal = false" 
            class="absolute inset-0 bg-[#2b0d3e]/60 backdrop-blur-sm">
        </div>

        <div 
            x-show="openRegisterModal"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-90 translate-y-8"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-90 translate-y-8"
            class="relative bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden">
            
            <div class="p-8 border-b border-gray-100 bg-gray-50/50">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-3xl font-black text-gray-800">Register New Student</h2>
                        <p class="text-gray-500 font-medium mt-1">Enter student info for the verification queue.</p>
                    </div>
                    <button @click="openRegisterModal = false" class="text-gray-400 hover:text-red-500 transition-colors">
                        <i class="fa-solid fa-circle-xmark text-3xl"></i>
                    </button>
                </div>
            </div>

            <form action="#" method="POST" class="p-8 space-y-6">
                
                <div class="flex items-center gap-6 p-4 bg-purple-50 rounded-2xl border-2 border-dashed border-purple-200">
                    <div class="w-20 h-20 bg-purple-200 rounded-full flex items-center justify-center text-[#7a3f91]">
                        <i class="fa-solid fa-camera text-3xl"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-gray-700">Upload Profile Photo</h4>
                        <p class="text-xs text-gray-500">JPG, PNG up to 5MB</p>
                        <input type="file" class="mt-2 text-sm text-gray-500 file:mr-4 file:py-1 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-[#7a3f91] file:text-white hover:file:bg-[#2b0d3e]">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-sm font-black text-gray-600 uppercase">Full Name</label>
                        <input type="text" placeholder="e.g. Juan M. Dela Cruz" class="w-full p-4 bg-gray-50 border-2 border-gray-200 rounded-2xl focus:border-[#7a3f91] outline-none font-bold">
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-black text-gray-600 uppercase">Student ID (Number Only)</label>
                        <input type="number" placeholder="20210001" class="w-full p-4 bg-gray-50 border-2 border-gray-200 rounded-2xl focus:border-[#7a3f91] outline-none font-bold font-mono">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-black text-gray-600 uppercase">Email Address</label>
                    <input type="email" placeholder="student@philcst.edu.ph" class="w-full p-4 bg-gray-50 border-2 border-gray-200 rounded-2xl focus:border-[#7a3f91] outline-none font-bold">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-sm font-black text-gray-600 uppercase">Batchmates / Year</label>
                        <input type="year" value="{{ date('Y') }}" class="w-full p-4 bg-gray-50 border-2 border-gray-200 rounded-2xl focus:border-[#7a3f91] outline-none font-bold">
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-black text-gray-600 uppercase">Course / Program</label>
                        <select class="w-full p-4 bg-gray-50 border-2 border-gray-200 rounded-2xl focus:border-[#7a3f91] outline-none font-bold">
                            <option>BSIT (Information Technology)</option>
                            <option>BSCS (Computer Science)</option>
                            <option>BSIS (Information Systems)</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 flex gap-4">
                    <button type="submit" class="flex-1 py-4 bg-[#7a3f91] text-white font-black rounded-2xl shadow-xl hover:bg-[#2b0d3e] transition-all transform hover:-translate-y-1">
                        SAVE STUDENT DATA
                    </button>
                    <button type="button" @click="openRegisterModal = false" class="px-8 py-4 bg-gray-100 text-gray-500 font-black rounded-2xl hover:bg-gray-200 transition-all">
                        CANCEL
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    .sticky-header thead th { background-color: #f9fafb; box-shadow: inset 0 -1px 0 #e5e7eb; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
@endsection