@extends('layouts.sidebar-admin')

@section('content')
<div
    x-data="initAlumni()"
    class="p-4 lg:p-6 font-sans"
>

    {{-- Flash Messages --}}
    @if(session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
         x-transition class="mb-4 p-4 bg-emerald-50 border-l-4 border-emerald-500 rounded-lg flex justify-between items-start">
        <p class="text-emerald-700 font-semibold text-sm">✓ {{ session('success') }}</p>
        <button @click="show = false" class="text-emerald-400 hover:text-emerald-600 ml-4 text-lg leading-none">✕</button>
    </div>
    @endif
    @if(session('error'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 7000)"
         x-transition class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg flex justify-between items-start">
        <p class="text-red-700 font-semibold text-sm">✗ {{ session('error') }}</p>
        <button @click="show = false" class="text-red-400 hover:text-red-600 ml-4 text-lg leading-none">✕</button>
    </div>
    @endif

    {{-- Header --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-800 tracking-tight">Alumni Management</h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage the alumni database</p>
            <p class="text-xs text-gray-400 mt-1">
                Total Alumni: <span class="font-bold text-[#7a3f91]">{{ $totalAlumni }}</span>
            </p>
        </div>
        <div class="flex gap-3 mt-4 md:mt-0 flex-wrap">
            <button @click="openRegister()"
                class="px-5 py-2.5 bg-[#7a3f91] hover:bg-[#2b0d3e] text-white font-bold rounded-xl transition-all shadow-lg flex items-center gap-2 text-sm">
                <i class="fa-solid fa-user-plus"></i> Register New Alumni
            </button>
            <button @click="openImportModal = true"
                class="px-5 py-2.5 bg-white border-2 border-[#7a3f91] text-[#7a3f91] font-bold hover:bg-purple-50 rounded-xl transition-all text-sm">
                <i class="fa-solid fa-file-import"></i> Import File
            </button>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('alumni.management') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

        <div class="flex flex-col gap-1">
            <label class="text-xs font-black text-gray-600 uppercase tracking-widest">Search</label>
            <div class="relative">
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="Name, ID, or email..."
                       class="w-full p-3 pl-10 border-2 border-gray-200 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-[#7a3f91] outline-none bg-white transition-all">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3.5 text-gray-400 text-xs"></i>
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-black text-gray-600 uppercase tracking-widest">Graduation Year</label>
            <select name="batch" onchange="this.form.submit()"
                    class="p-3 border-2 border-gray-200 rounded-xl text-sm bg-white outline-none focus:border-[#7a3f91]">
                <option value="">All Years</option>
                @foreach($batches as $year)
                    <option value="{{ $year }}" {{ $batch == $year ? 'selected' : '' }}>{{ $year }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-black text-gray-600 uppercase tracking-widest">Course</label>
            <div class="flex gap-2">
                <select name="course" onchange="this.form.submit()"
                        class="flex-1 p-3 border-2 border-gray-200 rounded-xl text-sm bg-white outline-none focus:border-[#7a3f91]">
                    <option value="">All Courses</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->code }}" {{ $course == $c->code ? 'selected' : '' }}>
                            {{ $c->code }}
                        </option>
                    @endforeach
                </select>
                <button type="button" @click="openManageCourses()"
                    class="px-4 py-3 bg-[#7a3f91] hover:bg-[#2b0d3e] text-white font-bold rounded-xl transition-all flex items-center gap-1.5 whitespace-nowrap shadow-md text-sm">
                    <i class="fa-solid fa-edit"></i> Manage
                </button>
            </div>
        </div>

        <div class="md:col-span-3 flex justify-end gap-2">
            <button type="submit"
                class="px-5 py-2 bg-[#7a3f91] text-white text-sm font-bold rounded-lg hover:bg-[#2b0d3e] transition-all">
                <i class="fa-solid fa-filter mr-1"></i> Apply
            </button>
            <a href="{{ route('alumni.management') }}"
                class="px-5 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-all">
                <i class="fa-solid fa-xmark mr-1"></i> Clear
            </a>
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide">Alumnus</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide">Student ID</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide">Course</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide text-center">Batch</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide">Email</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide text-center">Status</th>
                        <th class="p-4 text-xs font-black text-gray-600 uppercase tracking-wide text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($alumni as $item)
                    <tr class="hover:bg-purple-50/40 transition-colors group">
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                @if($item->profile_photo)
                                    <img src="{{ Storage::url($item->profile_photo) }}"
                                         class="w-9 h-9 rounded-full object-cover border-2 border-purple-200"
                                         alt="{{ $item->name }}">
                                @else
                                    <div class="w-9 h-9 rounded-full bg-purple-100 flex items-center justify-center text-[#7a3f91] font-black text-sm border-2 border-purple-200 shrink-0">
                                        {{ strtoupper(substr($item->name, 0, 1)) }}
                                    </div>
                                @endif
                                <span class="font-bold text-gray-900 text-sm group-hover:text-[#7a3f91] transition-colors">
                                    {{ $item->name }}
                                </span>
                            </div>
                        </td>
                        <td class="p-4 text-sm font-mono font-bold text-gray-600">{{ $item->student_id }}</td>
                        <td class="p-4">
                            <span class="px-2 py-1 bg-purple-100 text-[#7a3f91] text-xs rounded-lg font-black border border-purple-200 uppercase">
                                {{ $item->course_code }}
                            </span>
                        </td>
                        <td class="p-4 text-center text-sm font-bold text-gray-700">{{ $item->batch }}</td>
                        <td class="p-4 text-sm text-gray-500">{{ $item->email }}</td>
                        <td class="p-4 text-center">
                            @php
                                $statusColors = [
                                    'VERIFIED' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                    'PENDING'  => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                                    'REJECTED' => 'bg-red-100 text-red-700 border-red-200',
                                ];
                            @endphp
                            <span class="px-2 py-1 text-xs font-black rounded-full border {{ $statusColors[$item->status] ?? 'bg-gray-100 text-gray-600 border-gray-200' }}">
                                {{ $item->status }}
                            </span>
                        </td>
                        <td class="p-4 text-center">
                            <form action="{{ route('alumni.destroy', $item->id) }}" method="POST" class="inline"
                                  onsubmit="return confirm('Delete {{ addslashes($item->name) }}? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="px-3 py-1.5 bg-red-50 text-red-600 hover:bg-red-100 font-bold text-xs rounded-lg transition-all border border-red-200">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="p-16 text-center">
                            <i class="fa-solid fa-users text-4xl text-gray-200 mb-4 block"></i>
                            <p class="font-bold text-gray-400">No alumni records found</p>
                            <p class="text-sm text-gray-300 mt-1">Try adjusting your filters or register new alumni</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between bg-white">
            <span class="text-xs font-medium text-gray-400">
                Showing {{ $alumni->firstItem() ?? 0 }}–{{ $alumni->lastItem() ?? 0 }}
                of {{ $alumni->total() }} entries
            </span>
            <div class="flex items-center gap-1">
                @if($alumni->onFirstPage())
                    <span class="px-3 py-1.5 border rounded-lg text-gray-300 text-xs font-bold cursor-not-allowed">Prev</span>
                @else
                    <a href="{{ $alumni->previousPageUrl() }}" class="px-3 py-1.5 border rounded-lg text-gray-600 text-xs font-bold hover:bg-gray-50">Prev</a>
                @endif

                @foreach($alumni->getUrlRange(max(1, $alumni->currentPage()-3), min($alumni->lastPage(), $alumni->currentPage()+3)) as $page => $url)
                    @if($page == $alumni->currentPage())
                        <span class="px-3 py-1.5 rounded-lg bg-[#7a3f91] text-white text-xs font-black">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="px-3 py-1.5 border rounded-lg text-xs font-bold hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach

                @if($alumni->hasMorePages())
                    <a href="{{ $alumni->nextPageUrl() }}" class="px-3 py-1.5 border rounded-lg text-[#7a3f91] text-xs font-bold hover:bg-purple-50">Next</a>
                @else
                    <span class="px-3 py-1.5 border rounded-lg text-gray-300 text-xs font-bold cursor-not-allowed">Next</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ===================== REGISTER MODAL ===================== --}}
    <div x-show="openRegisterModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display: none;">
        <div @click="openRegisterModal = false" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
        <div x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden z-10 max-h-[90vh] overflow-y-auto">

            <div class="p-7 bg-gradient-to-r from-[#7a3f91] to-[#5a2d6f] flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-black text-white">Register New Alumni</h2>
                    <p class="text-purple-200 text-xs mt-0.5">Add a student to the alumni database</p>
                </div>
                <button @click="openRegisterModal = false" class="text-white/70 hover:text-white text-2xl leading-none">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="{{ route('alumni.store') }}" method="POST" enctype="multipart/form-data" class="p-7 space-y-5">
                @csrf

                <div class="flex items-center gap-5 p-4 bg-purple-50 rounded-2xl border-2 border-dashed border-purple-200">
                    <div class="w-14 h-14 bg-purple-200 rounded-full flex items-center justify-center text-[#7a3f91] shrink-0">
                        <i class="fa-solid fa-camera text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-bold text-gray-700 text-sm">Profile Photo <span class="font-normal text-gray-400">(optional)</span></p>
                        <p class="text-xs text-gray-400">JPG, PNG, WebP — max 5MB</p>
                        <input type="file" name="profile_photo" accept="image/*"
                               class="mt-2 text-xs text-gray-500 file:mr-3 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-[#7a3f91] file:text-white hover:file:bg-[#2b0d3e] file:cursor-pointer">
                        @error('profile_photo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-xs font-black text-gray-600 uppercase block mb-1.5">Full Name *</label>
                        <input type="text" name="name" value="{{ old('name') }}"
                               placeholder="Juan M. Dela Cruz" required
                               class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-[#7a3f91] outline-none text-sm font-semibold transition-colors">
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-black text-gray-600 uppercase block mb-1.5">Student ID *</label>
                        <input type="text" name="student_id" value="{{ old('student_id') }}"
                               placeholder="20210001" required
                               class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-[#7a3f91] outline-none text-sm font-mono font-bold transition-colors">
                        @error('student_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="text-xs font-black text-gray-600 uppercase block mb-1.5">Email Address *</label>
                    <input type="email" name="email" value="{{ old('email') }}"
                           placeholder="student@philcst.edu.ph" required
                           class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-[#7a3f91] outline-none text-sm font-semibold transition-colors">
                    @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-xs font-black text-gray-600 uppercase block mb-1.5">Batch / Year *</label>
                        <input type="number" name="batch" value="{{ old('batch', date('Y')) }}"
                               min="2000" max="{{ date('Y') }}" required
                               class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-[#7a3f91] outline-none text-sm font-bold transition-colors">
                        @error('batch') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-black text-gray-600 uppercase block mb-1.5">Course *</label>
                        <div x-show="loadingCourses"
                             class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl text-sm text-gray-400">
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading courses...
                        </div>
                        <select x-show="!loadingCourses"
                                name="course_code" required
                                class="w-full p-3 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-[#7a3f91] outline-none text-sm font-semibold transition-colors">
                            <option value="">— Select a course —</option>
                            <template x-for="c in courses" :key="c.id">
                                <option :value="c.code" x-text="`${c.code} — ${c.name}`"></option>
                            </template>
                        </select>
                        @error('course_code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit"
                        class="flex-1 py-3.5 bg-[#7a3f91] text-white font-black rounded-2xl shadow-lg hover:bg-[#2b0d3e] transition-all">
                        <i class="fa-solid fa-user-check mr-2"></i> REGISTER ALUMNI
                    </button>
                    <button type="button" @click="openRegisterModal = false"
                        class="px-6 py-3.5 bg-gray-100 text-gray-500 font-black rounded-2xl hover:bg-gray-200 transition-all">
                        CANCEL
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== IMPORT MODAL ===================== --}}
    <div x-show="openImportModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display: none;">
        <div @click="openImportModal = false" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
        <div x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="relative bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden z-10">

            <div class="p-7 bg-gradient-to-r from-[#7a3f91] to-[#5a2d6f] flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-black text-white">Import Alumni</h2>
                    <p class="text-purple-200 text-xs mt-0.5">CSV or Excel format</p>
                </div>
                <button @click="openImportModal = false" class="text-white/70 hover:text-white text-xl leading-none">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="{{ route('alumni.import') }}" method="POST" enctype="multipart/form-data" class="p-7 space-y-4">
                @csrf

                <div class="p-8 border-2 border-dashed border-purple-200 rounded-xl bg-purple-50 text-center cursor-pointer hover:border-[#7a3f91] transition-all"
                     onclick="document.getElementById('fileInput').click()">
                    <i class="fa-solid fa-cloud-arrow-up text-4xl text-[#7a3f91] mb-3 block"></i>
                    <p class="font-bold text-gray-700 text-sm">Click to upload</p>
                    <p class="text-xs text-gray-400 mt-1">CSV, XLS, or XLSX — max 10MB</p>
                    <p id="fileName" class="text-xs text-[#7a3f91] font-bold mt-2"></p>
                </div>
                <input type="file" id="fileInput" name="file" accept=".csv,.xlsx,.xls" required class="hidden"
                       onchange="document.getElementById('fileName').textContent = this.files[0] ? '✓ ' + this.files[0].name : ''">

                <div class="text-xs text-gray-500 bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <p class="font-bold text-gray-700 mb-1">Required columns:</p>
                    <code class="text-purple-700">student_id, name, email, course_code, batch</code>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                        class="flex-1 py-3 bg-[#7a3f91] text-white font-black rounded-xl hover:bg-[#2b0d3e] transition-all">
                        <i class="fa-solid fa-file-import mr-1"></i> IMPORT
                    </button>
                    <button type="button" @click="openImportModal = false"
                        class="px-5 py-3 bg-gray-100 text-gray-500 font-bold rounded-xl hover:bg-gray-200 transition-all">
                        CANCEL
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== MANAGE COURSES MODAL ===================== --}}
    <div x-show="openManageCourseModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display: none;">
        <div @click="openManageCourseModal = false; resetCourseForm()" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
        <div x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden z-10 flex flex-col max-h-[85vh]">

            <div class="p-6 bg-gradient-to-r from-[#7a3f91] to-[#5a2d6f] flex justify-between items-center shrink-0">
                <div>
                    <h2 class="text-xl font-black text-white">Manage Courses</h2>
                    <p class="text-purple-200 text-xs mt-0.5">Add, edit, or remove courses</p>
                </div>
                <button @click="openManageCourseModal = false; resetCourseForm()" class="text-white/70 hover:text-white text-xl leading-none">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-5">

                <div x-show="courseAlert.show"
                     x-transition
                     :class="courseAlert.type === 'success'
                        ? 'bg-emerald-50 border-emerald-400 text-emerald-700'
                        : 'bg-red-50 border-red-400 text-red-700'"
                     class="p-3 border-l-4 rounded-lg text-sm font-semibold flex justify-between items-center">
                    <span x-text="courseAlert.message"></span>
                    <button @click="courseAlert.show = false" class="ml-4 opacity-60 hover:opacity-100 leading-none">✕</button>
                </div>

                <div class="bg-purple-50 p-5 rounded-2xl border-2 border-purple-200">
                    <h3 class="text-sm font-black text-gray-800 mb-3">
                        <span x-show="!editingCourseId">➕ Add New Course</span>
                        <span x-show="editingCourseId">✏️ Edit Course</span>
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-black text-gray-600 uppercase block mb-1">Code *</label>
                            <input type="text" x-model="courseForm.code" placeholder="e.g. BSCS"
                                   @keydown.enter.prevent="saveCourse()"
                                   class="w-full p-2.5 bg-white border-2 border-gray-200 rounded-lg focus:border-[#7a3f91] outline-none font-bold uppercase text-sm">
                        </div>
                        <div>
                            <label class="text-xs font-black text-gray-600 uppercase block mb-1">Name *</label>
                            <input type="text" x-model="courseForm.name" placeholder="Bachelor of Science in CS"
                                   @keydown.enter.prevent="saveCourse()"
                                   class="w-full p-2.5 bg-white border-2 border-gray-200 rounded-lg focus:border-[#7a3f91] outline-none font-semibold text-sm">
                        </div>
                        <div class="md:col-span-2 flex gap-2">
                            <button @click="saveCourse()" :disabled="savingCourse"
                                class="flex-1 py-2.5 bg-[#7a3f91] text-white font-bold rounded-lg hover:bg-[#2b0d3e] transition-all text-sm disabled:opacity-60 disabled:cursor-not-allowed">
                                <span x-show="!savingCourse && !editingCourseId"><i class="fa-solid fa-plus mr-1"></i> Add Course</span>
                                <span x-show="!savingCourse && editingCourseId"><i class="fa-solid fa-save mr-1"></i> Update Course</span>
                                <span x-show="savingCourse"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...</span>
                            </button>
                            <button x-show="editingCourseId" @click="resetCourseForm()"
                                class="px-4 py-2.5 bg-gray-200 text-gray-600 font-bold rounded-lg hover:bg-gray-300 text-sm">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-black text-gray-600 uppercase mb-3 tracking-wide">
                        All Courses (<span x-text="courses.length"></span>)
                    </h3>
                    <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                        <template x-for="c in courses" :key="c.id">
                            <div :class="editingCourseId === c.id
                                    ? 'bg-blue-50 border-blue-300'
                                    : 'bg-gray-50 border-gray-200 hover:border-[#7a3f91]'"
                                 class="p-3 rounded-xl border-2 transition-all flex justify-between items-center">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="px-2 py-0.5 bg-purple-100 text-[#7a3f91] text-xs rounded font-black border border-purple-200 uppercase whitespace-nowrap shrink-0"
                                          x-text="c.code"></span>
                                    <span class="font-semibold text-sm text-gray-800 truncate" x-text="c.name"></span>
                                </div>
                                <div class="flex gap-1 ml-2 shrink-0">
                                    <button @click="openEditCourse(c)"
                                        class="px-3 py-1 bg-blue-50 text-blue-600 font-bold rounded-lg text-xs hover:bg-blue-100 border border-blue-200">
                                        Edit
                                    </button>
                                    <button @click="deleteCourse(c.id)"
                                        class="px-3 py-1 bg-red-50 text-red-600 font-bold rounded-lg text-xs hover:bg-red-100 border border-red-200">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </template>
                        <template x-if="courses.length === 0">
                            <div class="p-6 text-center text-gray-400 text-sm">
                                No courses yet. Add one above!
                            </div>
                        </template>
                    </div>
                </div>

            </div>

            <div class="p-4 border-t border-gray-100 bg-white text-right shrink-0">
                <button @click="openManageCourseModal = false; resetCourseForm()"
                    class="px-6 py-2 bg-gray-100 text-gray-600 font-black rounded-xl hover:bg-gray-200 text-sm">
                    Done
                </button>
            </div>
        </div>
    </div>

</div>

<script>
function initAlumni() {
    return {
        openRegisterModal:     {{ $errors->any() ? 'true' : 'false' }},
        openImportModal:       false,
        openManageCourseModal: false,
        editingCourseId:       null,
        savingCourse:          false,
        loadingCourses:        false,
        courses:               @json($courses),
        courseAlert: { show: false, type: 'success', message: '' },
        courseForm:  { code: '', name: '' },

        async fetchCourses() {
            this.loadingCourses = true;
            try {
                const res  = await fetch('/courses', {
                    headers: {
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    }
                });
                const data = await res.json();
                if (data.success) this.courses = data.courses;
            } catch (e) {
                console.error('Failed to fetch courses:', e);
            } finally {
                this.loadingCourses = false;
            }
        },

        async openRegister() {
            await this.fetchCourses();
            this.openRegisterModal = true;
        },

        async openManageCourses() {
            await this.fetchCourses();
            this.openManageCourseModal = true;
        },

        showAlert(type, message) {
            this.courseAlert = { show: true, type, message };
            setTimeout(() => this.courseAlert.show = false, 4000);
        },

        openEditCourse(course) {
            this.editingCourseId = course.id;
            this.courseForm      = { code: course.code, name: course.name };
        },

        resetCourseForm() {
            this.editingCourseId = null;
            this.courseForm      = { code: '', name: '' };
        },

        async saveCourse() {
            const code = this.courseForm.code.trim().toUpperCase();
            const name = this.courseForm.name.trim();
            if (!code || !name) {
                this.showAlert('error', 'Both Code and Name are required.');
                return;
            }
            this.savingCourse = true;
            try {
                const url    = this.editingCourseId ? `/courses/${this.editingCourseId}` : '/courses';
                const method = this.editingCourseId ? 'PUT' : 'POST';
                const res    = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify({ code, name }),
                });
                const data = await res.json();
                if (data.success) {
                    if (this.editingCourseId) {
                        const idx = this.courses.findIndex(c => c.id === this.editingCourseId);
                        if (idx !== -1) this.courses[idx] = data.course;
                    } else {
                        this.courses.push(data.course);
                    }
                    this.showAlert('success', data.message);
                    this.resetCourseForm();
                } else {
                    this.showAlert('error', data.message || 'An error occurred.');
                }
            } catch (e) {
                this.showAlert('error', 'Network error. Please try again.');
            } finally {
                this.savingCourse = false;
            }
        },

        async deleteCourse(courseId) {
            if (!confirm('Delete this course? This cannot be undone.')) return;
            try {
                const res  = await fetch(`/courses/${courseId}`, {
                    method:  'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                });
                const data = await res.json();
                if (data.success) {
                    this.courses = this.courses.filter(c => c.id !== courseId);
                    this.showAlert('success', data.message);
                } else {
                    this.showAlert('error', data.message);
                }
            } catch (e) {
                this.showAlert('error', 'Network error. Please try again.');
            }
        }
    }
}
</script>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection