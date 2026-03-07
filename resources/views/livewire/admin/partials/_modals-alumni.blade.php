{{-- =====================================================
     ALUMNI MODALS
     register alumni · import · manage courses · delete course · view profile
     ===================================================== --}}

{{-- REGISTER ALUMNI --}}
@if($activeModal==='registerAlumni')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-user-plus text-2xl"></i> Register Alumni</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        @if(count($alumniErrors)>0)
        <div class="bg-red-50 border-b border-red-200 px-8 py-5">
            <p class="font-semibold text-red-800 text-sm mb-3"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following errors:</p>
            <ul class="text-red-700 text-sm space-y-2">
                @foreach($alumniErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="text-red-500 mt-0.5">•</span><span>{{ $m }}</span></li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif
        <form wire:submit="registerAlumni" class="p-8 space-y-6">
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                     onclick="document.getElementById('regPhotoInput').click()">
                    @if($regPhoto)
                        <img src="{{ $regPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md">
                        <p class="text-sm text-emerald-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i>
                        <p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p>
                        <p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <input wire:model="regFirstName" type="text" placeholder="First Name"
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model="regLastName" type="text" placeholder="Last Name"
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3">
                    <div>
                        <input wire:model="regMiddleInitial" type="text" placeholder="Middle Initial" maxlength="2"
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial</p>
                    </div>
                    <div>
                        <input wire:model="regSuffix" type="text" placeholder="Suffix (Jr., Sr.)" maxlength="10"
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix</p>
                    </div>
                </div>
                @if($regFirstName||$regLastName)
                <p class="text-sm text-purple-700 font-semibold mt-3 pl-1">
                    Preview: {{ trim("{$regFirstName} {$regMiddleInitial} {$regLastName}".($regSuffix?' '.$regSuffix:'')) }}
                </p>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Student ID <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input wire:model="regStudentId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric"
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">
                        @if($regStudentId&&strlen($regStudentId)<8)
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500 font-mono">→ {{ str_pad($regStudentId,8,'0',STR_PAD_LEFT) }}</span>
                        @endif
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Email <span class="text-red-500">*</span></label>
                    <input wire:model="regEmail" type="email" placeholder="student@example.com"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Course <span class="text-red-500">*</span></label>
                    <select wire:model="regCourseCode" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <option value="">Select Course</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Year <span class="text-red-500">*</span></label>
                    <input wire:model="regYear" type="number" placeholder="{{ date('Y') }}" min="1000" max="9999"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                </div>
            </div>
            <div class="flex gap-4 pt-3">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerAlumni"
                        class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner spin-icon"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check"></i> Register Alumni</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- IMPORT --}}
@if($activeModal==='importModal')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelImport()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-file-import text-2xl"></i> Import Alumni</h2>
            <button wire:click="cancelImport" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        <div class="p-8 space-y-5">
            @if(!$importingFile)
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg text-sm">
                <p class="text-blue-800 font-semibold mb-1"><i class="fas fa-circle-info mr-2"></i>Supported formats: CSV · Excel (.xlsx, .xls)</p>
                <p class="text-blue-700 text-xs">Required columns:
                    <code class="bg-blue-100 px-1 rounded">name</code>
                    <code class="bg-blue-100 px-1 rounded">student_id</code>
                    <code class="bg-blue-100 px-1 rounded">course_code</code>
                    <code class="bg-blue-100 px-1 rounded">year</code>
                    <code class="bg-blue-100 px-1 rounded">email</code>
                </p>
            </div>
            <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition-all"
                 @click="document.getElementById('importFile').click()">
                @if($importFile)
                    <i class="fas fa-file-circle-check text-5xl text-emerald-500 block mb-3"></i>
                    <p class="text-sm text-emerald-700 font-semibold">{{ $importFile->getClientOriginalName() }}</p>
                    <p class="text-xs text-slate-500 mt-1">Click to change file</p>
                @else
                    <i class="fas fa-file-arrow-up text-5xl text-slate-300 block mb-3"></i>
                    <p class="text-slate-700 font-semibold text-sm">Click to choose file</p>
                    <p class="text-xs text-slate-400 mt-1">CSV or Excel format</p>
                @endif
                <input type="file" id="importFile" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
            </div>
            <div class="flex gap-3">
                <button type="button" wire:click="cancelImport"
                        class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="button" wire:click="processImportFile" @if(!$importFile) disabled @endif
                        wire:loading.attr="disabled" wire:target="processImportFile"
                        class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner spin-icon"></i> Processing…</span>
                    <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import Now</span>
                </button>
            </div>
            @else
            @php $isDone = $importStatus === 'Done!'; @endphp
            <div>
                <div class="flex items-center justify-between mb-2">
                    @if($isDone)
                        <p class="text-slate-800 font-semibold text-sm flex items-center gap-2"><i class="fas fa-circle-check text-emerald-500"></i> Import complete</p>
                    @else
                        <p class="text-slate-800 font-semibold text-sm flex items-center gap-2"><i class="fas fa-spinner spin-icon text-purple-600"></i> Importing… {{ $importProgress }}/{{ $importTotal }}</p>
                    @endif
                    <span class="text-xs text-slate-500 font-mono">{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500 {{ $isDone&&$importFailCount===0 ? 'bg-emerald-500' : ($isDone ? 'bg-amber-500' : 'bg-purple-600') }}"
                         style="width:{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%"></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-200 text-center">
                    <p class="text-emerald-600 text-2xl font-bold">{{ $importSuccessCount }}</p>
                    <p class="text-emerald-700 text-xs font-semibold mt-1 uppercase tracking-wide">Imported</p>
                </div>
                <div class="bg-red-50 rounded-xl p-4 border border-red-200 text-center">
                    <p class="text-red-600 text-2xl font-bold">{{ $importFailCount }}</p>
                    <p class="text-red-700 text-xs font-semibold mt-1 uppercase tracking-wide">Skipped</p>
                </div>
            </div>
            @if(count($importErrors)>0)
            <div class="bg-red-50 rounded-xl border border-red-200 overflow-hidden">
                <div class="px-4 py-3 bg-red-100 border-b border-red-200 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-600 text-sm"></i>
                    <p class="text-red-800 font-semibold text-sm">{{ count($importErrors) }} row(s) were skipped</p>
                </div>
                <ul class="divide-y divide-red-100 max-h-56 overflow-y-auto scrollbar-custom">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-2.5 text-xs text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-400 mt-0.5 shrink-0"></i><span>{{ $err }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
            <button type="button" wire:click="cancelImport"
                    class="w-full px-6 py-2.5 {{ $isDone ? 'btn-primary' : 'border border-slate-300 text-slate-700' }} rounded-lg text-sm font-semibold transition">
                {{ $isDone ? 'Done' : 'Close' }}
            </button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- MANAGE ALUMNI COURSES --}}
@if($activeModal==='manageCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-sliders text-2xl"></i> Manage Courses</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        @if($courseAlert)
        <div x-data="{s:true}" x-effect="if(s){setTimeout(()=>{s=false},4000)}" x-show="s"
             :class="'{{ $courseAlertType }}'==='success'?'bg-emerald-50 border-l-4 border-emerald-400':'bg-red-50 border-l-4 border-red-400'"
             class="p-4 mx-8 mt-6 rounded-lg">
            <p :class="'{{ $courseAlertType }}'==='success'?'text-emerald-800':'text-red-800'" class="text-sm font-semibold">
                <i :class="'{{ $courseAlertType }}'==='success'?'fas fa-check-circle':'fas fa-exclamation-circle'" class="mr-2"></i>{{ $courseAlert }}
            </p>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-6">
            <div class="border border-slate-200 rounded-lg p-6 bg-slate-50">
                <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-{{ $editingCourseId ? 'pencil' : 'plus-circle' }} text-purple-600"></i>
                    {{ $editingCourseId ? 'Edit Course' : 'Add New Course' }}
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">Course Code</label>
                        <input wire:model="courseCode" type="text" placeholder="e.g. CS101" maxlength="20"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">Course Name</label>
                        <input wire:model="courseName" type="text" placeholder="e.g. Computer Science" maxlength="100"
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                    </div>
                    <div class="flex gap-3 pt-2">
                        @if($editingCourseId)
                        <button type="button" wire:click="resetCourseForm"
                                class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
                        @endif
                        <button type="button" wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse"
                                class="flex-1 px-4 py-2 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner spin-icon"></i></span>
                            <span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId ? 'Update' : 'Add Course' }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-book text-slate-500"></i> Courses ({{ count($coursesList) }})
                </h3>
                <div class="space-y-2 max-h-64 overflow-y-auto scrollbar-custom pr-2">
                    @forelse($coursesList as $c)
                    <div class="flex items-center justify-between p-4 border border-slate-200 rounded-lg bg-white">
                        <div class="flex-1">
                            <p class="font-semibold text-slate-800 text-sm">{{ $c['code'] }}</p>
                            <p class="text-slate-600 text-xs mt-1">{{ $c['name'] }}</p>
                        </div>
                        <div class="flex gap-2 ml-4">
                            <button wire:click="openEditCourse({{ $c['id'] }})"
                                    class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition font-semibold text-xs border border-blue-200 flex items-center gap-1.5">
                                <i class="fas fa-pencil"></i> Edit
                            </button>
                            <button wire:click="confirmDeleteCourse({{ $c['id'] }})"
                                    class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    @empty
                    <p class="text-center text-slate-500 py-8 text-sm">No courses yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="px-8 py-4 border-t border-slate-200 bg-slate-50">
            <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- DELETE ALUMNI COURSE CONFIRM --}}
@if($activeModal==='deleteCourseConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete Course</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-4">Delete <strong class="text-red-600">{{ $deleteCourseName }}</strong>?</p>
            <p class="text-slate-600 text-xs mb-6">This action cannot be undone.</p>
            <div class="flex gap-3">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="button" wire:click="deleteCourse" wire:loading.attr="disabled" wire:target="deleteCourse"
                        class="flex-1 px-6 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="deleteCourse">Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- VIEW PROFILE (shared alumni + organizer) --}}
@if($activeModal==='viewProfile'&&$viewingProfile)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
        <div class="flex items-center justify-between px-8 py-5 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-xl font-bold flex items-center gap-2">
                <i class="fas {{ $viewingProfileType==='alumni'?'fa-graduation-cap':'fa-users-gear' }}"></i>
                {{ $viewingProfileType==='alumni'?'Alumni':'Organizer' }} Profile
            </h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        <div class="p-8 space-y-6">
            <div class="flex items-center gap-5">
                @if($updatingProfilePhoto)
                    <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" alt="Preview" class="w-40 h-40 rounded-xl object-cover shadow-md shrink-0">
                @else
                    <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}" alt="{{ $viewingProfile['name'] }}" class="w-40 h-40 rounded-xl object-cover shadow-md shrink-0">
                @endif
                <div>
                    <p class="text-xl font-bold text-slate-800 leading-tight">{{ $viewingProfile['name'] }}</p>
                    <p class="text-slate-500 text-sm mt-1">{{ $viewingProfile['email'] }}</p>
                    @if($viewingProfileType==='alumni')
                        @php $sc=match($viewingProfile['status']??''){'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                    @else
                        @php $sc=match($viewingProfile['status']??''){'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-red-100 text-red-700','SUSPENDED'=>'bg-amber-100 text-amber-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                    @endif
                    <span class="inline-block mt-2 px-3 py-1 rounded-full text-xs font-semibold {{ $sc }}">{{ $viewingProfile['status'] ?? 'N/A' }}</span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                @if($viewingProfileType==='alumni')
                <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                    <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Student ID</p>
                    <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['student_id'] ?? '—' }}</p>
                </div>
                <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                    <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Batch Year</p>
                    <p class="font-bold text-slate-800">{{ $viewingProfile['batch'] ?? '—' }}</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 border border-purple-200 col-span-2">
                    <p class="text-xs text-purple-600 font-semibold uppercase tracking-wide mb-1">Course</p>
                    <p class="font-bold text-purple-900 text-sm">{{ $viewingProfile['course_code'] ?? '—' }}</p>
                    <p class="text-purple-700 text-xs mt-0.5">{{ $viewingProfile['course_name'] ?? '' }}</p>
                </div>
                @else
                <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                    <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Teacher ID</p>
                    <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                </div>
                <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                    <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Department</p>
                    <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['department'] ?? '—' }}</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 border border-purple-200 col-span-2">
                    <p class="text-xs text-purple-600 font-semibold uppercase tracking-wide mb-1">College</p>
                    <p class="font-bold text-purple-900 text-sm">{{ $this->getCollegeForCourse($viewingProfile['department'] ?? '') }}</p>
                </div>
                @endif
            </div>
            <div>
                <p class="text-xs font-bold text-slate-700 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-slate-300 rounded-lg p-5 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                     @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-slate-400 block mb-2"></i>
                    <p class="text-slate-700 font-semibold text-sm">{{ $updatingProfilePhoto?'Change Photo':'Click to Upload New Photo' }}</p>
                    <p class="text-xs text-slate-500 mt-1">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="w-full mt-3 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>
            <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Close</button>
        </div>
    </div>
</div>
@endif