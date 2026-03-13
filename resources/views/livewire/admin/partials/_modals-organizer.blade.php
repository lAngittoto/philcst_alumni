{{-- =====================================================
     ORGANIZER MODALS
     register organizer · manage colleges · delete college confirm · toggle status confirm
     ===================================================== --}}

{{-- ── REGISTER ORGANIZER ─────────────────────────────────────────────────── --}}
@if($activeModal==='registerOrganizer')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-users-gear text-2xl"></i> Register Organizer</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if(count($organizerErrors)>0)
        <div class="bg-red-50 border-b border-red-200 px-8 py-5">
            <p class="font-semibold text-red-800 text-sm mb-3"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following errors:</p>
            <ul class="text-red-700 text-sm space-y-2">
                @foreach($organizerErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="text-red-500 mt-0.5">•</span><span>{{ $m }}</span></li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerOrganizer" class="p-8 space-y-6">
            <div class="flex justify-end">
                <button type="button" wire:click="$set('organizerErrors',[])"
                        onclick="this.closest('form').querySelectorAll('input[type=text],input[type=email],select').forEach(el=>{el.value='';el.dispatchEvent(new Event('input'))})"
                        class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-slate-500 hover:text-red-600 hover:bg-red-50 border border-slate-200 hover:border-red-200 rounded-lg transition">
                    <i class="fas fa-rotate-left"></i> Reset Form
                </button>
            </div>

            {{-- Photo --}}
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                     onclick="document.getElementById('orgPhotoInput').click()">
                    @if($orgPhoto)
                        <img src="{{ $orgPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md">
                        <p class="text-sm text-emerald-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i>
                        <p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p>
                        <p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                </div>
            </div>

            {{-- Name --}}
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <input wire:model.defer="orgFirstName" type="text" placeholder="First Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgLastName" type="text" placeholder="Last Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3">
                    <div>
                        <input wire:model.defer="orgMiddleInitial" type="text" placeholder="e.g. A" maxlength="2" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial <span class="text-slate-400">(letters only)</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgSuffix" type="text" placeholder="e.g. Jr. Sr. III" maxlength="10" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix <span class="text-slate-400">(optional)</span></p>
                    </div>
                </div>
            </div>

            {{-- ID / Email --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Teacher ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgTeacherId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">
                    <p class="text-xs text-slate-500 mt-1.5 pl-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgEmail" type="email" placeholder="teacher@example.com" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                </div>
            </div>

            {{-- College --}}
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">College <span class="text-red-500">*</span></label>
                @if($this->orgDepartmentsGrouped->isEmpty())
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
                        <span>No colleges configured yet. Please set up colleges first via <strong>Manage Colleges</strong>.</span>
                    </div>
                @else
                    @php
                        $collegeDeptsMap = [];
                        foreach ($this->orgDepartmentsGrouped as $collegeName => $depts) {
                            $collegeDeptsMap[$collegeName] = $depts->pluck('code')->toArray();
                        }
                    @endphp
                    <div x-data="{
                            map: {{ Js::from($collegeDeptsMap) }},
                            selected: @entangle('orgCollegeSelect').defer,
                            get depts() { return this.selected ? (this.map[this.selected] ?? []) : []; }
                         }">
                        <select x-model="selected" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                            <option value="">— Select College —</option>
                            @foreach($this->orgDepartmentsGrouped->keys() as $collegeName)
                                <option value="{{ $collegeName }}">{{ $collegeName }}</option>
                            @endforeach
                        </select>
                        <div x-show="depts.length > 0" x-cloak class="mt-3">
                            <p class="text-xs text-slate-500 mb-2 font-medium">Departments under this college:</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="code in depts" :key="code">
                                    <span class="px-3 py-1.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg text-xs font-bold font-mono" x-text="code"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex gap-4 pt-3">
                <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerOrganizer"
                        class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner spin-icon"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear"></i> Register Organizer</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── MANAGE COLLEGES ─────────────────────────────────────────────────────── --}}
@if($activeModal==='manageOrgCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-building-columns text-2xl"></i> Manage Colleges & Departments</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if($orgCourseAlert)
        <div x-data="{s:true}" x-effect="if(s){setTimeout(()=>{s=false},4000)}" x-show="s"
             :class="'{{ $orgCourseAlertType }}'==='success'?'bg-emerald-50 border-l-4 border-emerald-400':'bg-red-50 border-l-4 border-red-400'"
             class="p-4 mx-8 mt-5 rounded-lg shrink-0">
            <p :class="'{{ $orgCourseAlertType }}'==='success'?'text-emerald-800':'text-red-800'" class="text-sm font-semibold">
                <i :class="'{{ $orgCourseAlertType }}'==='success'?'fas fa-check-circle':'fas fa-exclamation-circle'" class="mr-2"></i>{{ $orgCourseAlert }}
            </p>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">

            {{-- Add college input --}}
            @if(!$orgAddingToCollege && !$orgRenamingCollege)
            <div class="border border-slate-200 rounded-lg p-5 bg-slate-50">
                <h3 class="text-sm font-bold text-slate-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-purple-600"></i> Add New College
                </h3>
                <div class="flex gap-3">
                    <input wire:model.defer="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                           class="flex-1 px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"
                           @keydown.enter.prevent="$wire.addCollege()">
                    <button type="button" wire:click="addCollege"
                            class="px-5 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center gap-2 whitespace-nowrap">
                        <i class="fas fa-plus"></i> Add College
                    </button>
                </div>
                <p class="text-xs text-slate-500 mt-2">After adding, select which courses/departments belong to it.</p>
            </div>
            @endif

            {{-- Rename college --}}
            @if($orgRenamingCollege)
            <div class="border-2 border-purple-300 rounded-lg p-5 bg-purple-50">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fas fa-pen-to-square text-purple-600"></i>
                    <h3 class="text-sm font-bold text-purple-800">Rename College</h3>
                </div>
                <p class="text-xs text-purple-600 mb-3">Current name: <strong>{{ $orgRenamingCollege }}</strong></p>
                <div class="flex gap-3">
                    <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                           class="flex-1 px-4 py-2.5 border border-purple-300 rounded-lg text-sm input-focus text-slate-800 bg-white"
                           @keydown.enter.prevent="$wire.renameCollege()">
                    <button type="button" wire:click="cancelRenamingCollege"
                            class="px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                            class="px-5 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center gap-2 whitespace-nowrap">
                        <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner spin-icon"></i></span>
                        <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save Name</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- Assign departments --}}
            @if($orgAddingToCollege)
            <div class="border-2 border-purple-300 rounded-lg p-5 bg-purple-50">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-purple-800 flex items-center gap-2">
                            <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege]) ? 'pencil' : 'plus' }} text-purple-600"></i>
                            {{ isset($orgCoursesList[$orgAddingToCollege]) ? 'Edit Departments' : 'Assign Departments' }}
                        </h3>
                        <p class="text-xs text-purple-600 mt-0.5">College: <strong>{{ $orgAddingToCollege }}</strong></p>
                    </div>
                    <span class="text-xs bg-purple-200 text-purple-800 px-2.5 py-1 rounded-full font-semibold">{{ count($orgSelectedCourseCodes) }} selected</span>
                </div>

                @if($this->allCoursesForAssign->count() > 0)
                <p class="text-xs text-slate-600 mb-3">Check all courses that belong to this college:</p>
                <div class="space-y-2 max-h-56 overflow-y-auto scrollbar-custom pr-1 mb-4">
                    @foreach($this->allCoursesForAssign as $c)
                    @php
                        $isSelected  = in_array($c->code, $orgSelectedCourseCodes);
                        $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                    @endphp
                    <label class="course-check-row flex items-center gap-3 p-3 border rounded-lg cursor-pointer {{ $isSelected ? 'is-selected border-purple-400' : 'border-slate-200 bg-white' }}">
                        <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}" class="w-4 h-4 shrink-0" style="accent-color:#7a3f91;">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-slate-800 text-sm font-mono">{{ $c->code }}</span>
                                <span class="text-slate-600 text-xs">{{ $c->name }}</span>
                            </div>
                            @if($otherCollege)
                            <p class="text-xs text-amber-600 mt-0.5"><i class="fas fa-triangle-exclamation mr-1"></i>Currently under: <em>{{ $otherCollege }}</em></p>
                            @endif
                        </div>
                        @if($isSelected)<i class="fas fa-check-circle text-purple-600 shrink-0 text-lg"></i>@endif
                    </label>
                    @endforeach
                </div>
                @else
                <div class="text-center py-6">
                    <i class="fas fa-book text-3xl text-slate-300 block mb-2"></i>
                    <p class="text-slate-500 text-sm">No courses available. Add courses first via <strong>Manage Courses</strong> (Alumni tab).</p>
                </div>
                @endif

                <div class="flex gap-3">
                    <button type="button" wire:click="cancelAddingCourses"
                            class="flex-1 px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                            class="flex-1 px-4 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                        <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- College list --}}
            <div>
                <h3 class="text-base font-bold text-slate-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-list text-slate-500"></i> Colleges & Departments
                </h3>
                @if(count($orgCoursesList)===0)
                <div class="text-center py-10 border border-dashed border-slate-300 rounded-lg">
                    <i class="fas fa-building-columns text-5xl text-slate-200 block mb-3"></i>
                    <p class="text-slate-500 font-semibold text-sm">No colleges yet</p>
                    <p class="text-slate-400 text-xs mt-1">Add a college above to get started</p>
                </div>
                @else
                <div class="space-y-3">
                    @foreach($orgCoursesList as $college => $departments)
                    <div class="border border-slate-200 rounded-lg overflow-hidden college-card">
                        <div class="flex items-center justify-between px-5 py-3 bg-purple-50">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-purple-200 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-building-columns text-purple-700 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-purple-900 text-sm">{{ $college }}</p>
                                    <p class="text-purple-600 text-xs">{{ count($departments) }} department{{ count($departments)!==1?'s':'' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')"
                                        class="px-3 py-1.5 bg-white text-purple-700 rounded-lg hover:bg-purple-50 transition font-semibold text-xs border border-purple-300 flex items-center gap-1.5">
                                    <i class="fas fa-pen-to-square"></i> Rename
                                </button>
                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                        class="px-3 py-1.5 bg-white text-purple-700 rounded-lg hover:bg-purple-100 transition font-semibold text-xs border border-purple-300 flex items-center gap-1.5">
                                    <i class="fas fa-pencil"></i> Depts
                                </button>
                                @endif
                                <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')"
                                        class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200 flex items-center gap-1.5">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @foreach($departments as $dept)
                            <div class="flex items-center px-5 py-3 bg-white">
                                <span class="w-8 h-8 bg-purple-50 text-purple-600 rounded-lg flex items-center justify-center text-xs font-bold shrink-0">
                                    {{ strtoupper(substr($dept['code'],0,2)) }}
                                </span>
                                <div class="ml-3">
                                    <p class="font-semibold text-slate-800 text-sm">{{ $dept['code'] }}</p>
                                    <p class="text-slate-500 text-xs">{{ $dept['name'] }}</p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <div class="px-8 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COLLEGE CONFIRM ──────────────────────────────────────────────── --}}
@if($activeModal==='deleteOrgCollegeConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete College</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-2">Remove college <strong class="text-red-600">{{ $deleteOrgCourseName }}</strong>?</p>
            <p class="text-slate-600 text-xs mb-6"><i class="fas fa-circle-info mr-1 text-slate-400"></i>Courses will be unassigned from this college but <strong>not deleted</strong>.</p>
            <div class="flex gap-3">
                <button type="button" wire:click="openModal('manageOrgCourses')"
                        class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="button" wire:click="deleteOrgCollege" wire:loading.attr="disabled" wire:target="deleteOrgCollege"
                        class="flex-1 px-6 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteOrgCollege"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="deleteOrgCollege">Delete College</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── TOGGLE ORGANIZER STATUS CONFIRM ────────────────────────────────────── --}}
@if($activeModal==='toggleOrganizerConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm modal-animate">
        <div class="p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 {{ $pendingToggleAction==='deactivate'?'bg-red-100':'bg-emerald-100' }}">
                    <i class="text-lg {{ $pendingToggleAction==='deactivate'?'fas fa-ban text-red-600':'fas fa-circle-check text-emerald-600' }}"></i>
                </div>
                <div>
                    <p class="font-bold text-slate-800 text-lg">
                        {{ $pendingToggleAction==='deactivate'?'Deactivate Organizer?':'Activate Organizer?' }}
                    </p>
                    <p class="text-sm text-slate-500 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-slate-600 mb-6">
                @if($pendingToggleAction==='deactivate')
                    This organizer will no longer be able to log in. You can reactivate them at any time.
                @else
                    This organizer will be able to log in again.
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="closeModal"
                        class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-lg text-base font-bold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeToggleOrganizerStatus" wire:loading.attr="disabled" wire:target="executeToggleOrganizerStatus"
                        class="flex-1 px-4 py-3 rounded-lg text-base font-bold transition flex items-center justify-center gap-2
                            {{ $pendingToggleAction==='deactivate'?'bg-red-600 hover:bg-red-700 text-white':'bg-emerald-600 hover:bg-emerald-700 text-white' }}">
                    <span wire:loading wire:target="executeToggleOrganizerStatus"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeToggleOrganizerStatus">
                        {{ $pendingToggleAction==='deactivate'?'Yes, Deactivate':'Yes, Activate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif