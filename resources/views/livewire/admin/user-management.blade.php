<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithFileUploads;

    public string $activeTab = 'alumni';
    public string $alumniSearch = '';
    public string $alumniBatch = '';
    public string $alumniCourse = '';
    public string $alumniSort = 'recent';
    public string $orgSearch = '';
    public string $orgDepartment = '';
    public string $orgSort = 'recent';

    public string $regFirstName = '';
    public string $regMiddleInitial = '';
    public string $regLastName = '';
    public string $regSuffix = '';
    public string $regStudentId = '';
    public string $regEmail = '';
    public string $regCourseCode = '';
    public string $regYear = '';
    public $regPhoto = null;
    public bool $registeringAlumni = false;
    public array $alumniErrors = [];

    public string $orgFirstName = '';
    public string $orgMiddleInitial = '';
    public string $orgLastName = '';
    public string $orgSuffix = '';
    public string $orgTeacherId = '';
    public string $orgEmail = '';
    public string $orgDept = '';
    public $orgPhoto = null;
    public bool $registeringOrganizer = false;
    public array $organizerErrors = [];

    public array $coursesList = [];
    public string $courseCode = '';
    public string $courseName = '';
    public ?int $editingCourseId = null;
    public bool $savingCourse = false;
    public string $courseAlert = '';
    public string $courseAlertType = '';

    public ?int $deleteCourseId = null;
    public string $deleteCourseName = '';
    public bool $deletingCourse = false;

    public string $flashMessage = '';
    public string $flashType = '';
    public bool $showFlash = false;

    public string $activeModal = '';

    protected string $paginationTheme = 'tailwind';

    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void
    {
        $this->flash($type, $message);
    }

    public function mount()
    {
        $this->coursesList = Course::all()->toArray();
        $this->regYear = (string) date('Y');
        $this->courseAlert = '';
        $this->courseAlertType = '';
        $this->showFlash = false;
        
        if (session()->has('success')) {
            $msg = session('success');
            session()->forget('success');
            $this->dispatch('showFlash', type: 'success', message: $msg);
        }
        if (session()->has('error')) {
            $msg = session('error');
            session()->forget('error');
            $this->dispatch('showFlash', type: 'error', message: $msg);
        }
    }

    public function updatingAlumniSearch() { $this->resetPage('alumniPage'); }
    public function updatingOrgSearch() { $this->resetPage('orgPage'); }
    public function updatingAlumniBatch() { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse() { $this->resetPage('alumniPage'); }
    public function updatingAlumniSort() { $this->resetPage('alumniPage'); }
    public function updatingOrgDepartment() { $this->resetPage('orgPage'); }
    public function updatingOrgSort() { $this->resetPage('orgPage'); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query();
        if ($this->alumniSearch) {
            $q->where(function ($sub) {
                $sub->where('name', 'like', "%{$this->alumniSearch}%")
                    ->orWhere('student_id', 'like', "%{$this->alumniSearch}%")
                    ->orWhere('email', 'like', "%{$this->alumniSearch}%");
            });
        }
        if ($this->alumniBatch) $q->where('batch', $this->alumniBatch);
        if ($this->alumniCourse) $q->where('course_code', $this->alumniCourse);
        $q->when($this->alumniSort === 'oldest', fn($q) => $q->orderBy('created_at', 'asc'), fn($q) => $q->orderByDesc('created_at'));
        return $q->paginate(100, ['*'], 'alumniPage');
    }

    #[Computed]
    public function organizerRecords()
    {
        $q = Organizer::withoutTrashed();
        if ($this->orgSearch) {
            $q->where(function ($sub) {
                $sub->where('name', 'like', "%{$this->orgSearch}%")
                    ->orWhere('email', 'like', "%{$this->orgSearch}%")
                    ->orWhere('id_number', 'like', "%{$this->orgSearch}%");
            });
        }
        if ($this->orgDepartment) $q->where('department', $this->orgDepartment);
        $q->when($this->orgSort === 'oldest', fn($q) => $q->orderBy('created_at', 'asc'), fn($q) => $q->orderByDesc('created_at'));
        return $q->paginate(100, ['*'], 'orgPage');
    }

    #[Computed] public function courses() { return Course::orderBy('code')->get(); }
    #[Computed] public function batches() { return Alumni::distinct()->orderByDesc('batch')->pluck('batch'); }

    public function switchTab(string $tab): void { $this->activeTab = $tab; }
    public function openModal(string $modal): void { $this->activeModal = $modal; }
    public function closeModal(): void { $this->activeModal = ''; }

    public function resetAlumniFilters(): void
    {
        $this->alumniSearch = $this->alumniBatch = $this->alumniCourse = '';
        $this->alumniSort = 'recent';
        $this->resetPage('alumniPage');
    }

    public function resetOrgFilters(): void
    {
        $this->orgSearch = $this->orgDepartment = '';
        $this->orgSort = 'recent';
        $this->resetPage('orgPage');
    }

    private function buildFullName(string $first, string $mi, string $last, string $suffix): string
    {
        $parts = array_filter([
            trim($first),
            trim($mi),
            trim($last),
        ]);
        $name = implode(' ', $parts);
        if (trim($suffix) !== '') {
            $name .= ' ' . trim($suffix);
        }
        return $name;
    }

    public function registerAlumni(): void
    {
        $this->alumniErrors = [];
        $this->registeringAlumni = true;
        
        try {
            $fullName = $this->buildFullName(
                $this->regFirstName,
                $this->regMiddleInitial,
                $this->regLastName,
                $this->regSuffix
            );
            
            $this->validate([
                'regFirstName'     => ['required', 'string', 'max:100'],
                'regLastName'      => ['required', 'string', 'max:100'],
                'regMiddleInitial' => ['nullable', 'string', 'max:5'],
                'regSuffix'        => ['nullable', 'string', 'max:10'],
                'regStudentId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:alumni,student_id'],
                'regEmail'         => ['required', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
                'regCourseCode'    => ['required', 'string', 'exists:courses,code'],
                'regYear'          => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
                'regPhoto'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);
            
            $paddedStudentId = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $course = Course::where('code', $this->regCourseCode)->firstOrFail();
            
            // ✅ FIX: Store photo FIRST, then save to database
            $photoPath = null;
            if ($this->regPhoto) {
                $photoPath = $this->storeAlumniPhoto($this->regPhoto);
            }
            
            $alumni = Alumni::create([
                'name'          => $fullName,
                'student_id'    => $paddedStudentId,
                'email'         => $this->regEmail,
                'course_code'   => $this->regCourseCode,
                'course_name'   => $course->name,
                'batch'         => (int)$this->regYear,
                'status'        => 'VERIFIED',
                'profile_photo' => $photoPath, // ✅ This is now correctly set
            ]);
            
            Log::info("Alumni created: {$alumni->name}, photo_path: {$photoPath}");
            
            $tempPassword = Str::random(10);
            User::create([
                'name'     => $fullName,
                'email'    => $this->regEmail,
                'password' => Hash::make($tempPassword),
                'role'     => 'alumni'
            ]);
            
            try {
                Mail::to($alumni->email)->send(new \App\Mail\AlumniRegistered($alumni, $tempPassword));
            } catch (\Exception $e) {
                Log::warning("Email not sent: " . $e->getMessage());
            }
            
            $this->resetRegAlumniForm();
            $this->flash('success', "Alumni '{$fullName}' registered successfully!");
            $this->activeModal = '';
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->alumniErrors = $e->errors();
            Log::error('Validation error: ' . json_encode($e->errors()));
        } catch (\Exception $e) {
            Log::error('Alumni creation failed: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
            $this->alumniErrors = ['general' => ['Failed to register alumni: ' . $e->getMessage()]];
        } finally {
            $this->registeringAlumni = false;
        }
    }

    private function storeAlumniPhoto($photo): ?string
    {
        if (!$photo) {
            return null;
        }
        
        try {
            // Generate unique filename
            $uuid = Str::uuid();
            $extension = $photo->getClientOriginalExtension();
            $filename = "alumni-{$uuid}.{$extension}";
            
            // Store in public disk under alumni-photos
            $path = $photo->storeAs('alumni-photos', $filename, 'public');
            
            Log::info("Photo stored: {$path}");
            
            if ($path === false) {
                Log::error('Photo storage failed');
                return null;
            }
            
            // Return the relative path for database
            return "alumni-photos/{$filename}";
            
        } catch (\Exception $e) {
            Log::error('Photo upload error: ' . $e->getMessage());
            return null;
        }
    }

    private function resetRegAlumniForm(): void
    {
        $this->regFirstName = $this->regMiddleInitial = $this->regLastName = $this->regSuffix = '';
        $this->regStudentId = $this->regEmail = $this->regCourseCode = '';
        $this->regPhoto = null;
        $this->regYear = (string) date('Y');
        $this->alumniErrors = [];
    }

    public function registerOrganizer(): void
    {
        $this->organizerErrors = [];
        $this->registeringOrganizer = true;
        
        try {
            $fullName = $this->buildFullName(
                $this->orgFirstName,
                $this->orgMiddleInitial,
                $this->orgLastName,
                $this->orgSuffix
            );
            
            $this->validate([
                'orgFirstName'     => ['required', 'string', 'max:100'],
                'orgLastName'      => ['required', 'string', 'max:100'],
                'orgMiddleInitial' => ['nullable', 'string', 'max:5'],
                'orgSuffix'        => ['nullable', 'string', 'max:10'],
                'orgTeacherId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:organizer,id_number'],
                'orgEmail'         => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
                'orgDept'          => ['required', 'string', 'exists:courses,code'],
                'orgPhoto'         => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ]);
            
            $paddedTeacherId = str_pad($this->orgTeacherId, 8, '0', STR_PAD_LEFT);
            
            // ✅ FIX: Store photo FIRST, then save to database
            $photoPath = null;
            if ($this->orgPhoto) {
                $photoPath = $this->storeOrganizerPhoto($this->orgPhoto);
            }
            
            $tempPassword = Str::random(10);
            
            $user = User::create([
                'name'     => $fullName,
                'email'    => $this->orgEmail,
                'role'     => 'organizer',
                'password' => Hash::make($tempPassword)
            ]);
            
            $organizer = Organizer::create([
                'user_id'       => $user->id,
                'name'          => $fullName,
                'email'         => $this->orgEmail,
                'id_number'     => $paddedTeacherId,
                'department'    => strtoupper($this->orgDept),
                'profile_photo' => $photoPath, // ✅ This is now correctly set
                'status'        => 'ACTIVE'
            ]);
            
            Log::info("Organizer created: {$organizer->name}, photo_path: {$photoPath}");
            
            try {
                Mail::to($organizer->email)->send(new \App\Mail\OrganizerRegistered($organizer, $tempPassword));
            } catch (\Exception $e) {
                Log::warning("Email not sent: " . $e->getMessage());
            }
            
            $this->resetOrgForm();
            $this->flash('success', "Organizer '{$fullName}' registered successfully!");
            $this->activeModal = '';
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->organizerErrors = $e->errors();
            Log::error('Validation error: ' . json_encode($e->errors()));
        } catch (\Exception $e) {
            Log::error('Organizer creation failed: ' . $e->getMessage());
            $this->organizerErrors = ['general' => ['Failed to register organizer: ' . $e->getMessage()]];
        } finally {
            $this->registeringOrganizer = false;
        }
    }

    private function storeOrganizerPhoto($photo): ?string
    {
        if (!$photo) {
            return null;
        }
        
        try {
            // Generate unique filename
            $uuid = Str::uuid();
            $extension = $photo->getClientOriginalExtension();
            $filename = "organizer-{$uuid}.{$extension}";
            
            // Store in public disk under organizers
            $path = $photo->storeAs('organizers', $filename, 'public');
            
            Log::info("Organizer photo stored: {$path}");
            
            if ($path === false) {
                Log::error('Organizer photo storage failed');
                return null;
            }
            
            // Return the relative path for database
            return "organizers/{$filename}";
            
        } catch (\Exception $e) {
            Log::error('Organizer photo upload error: ' . $e->getMessage());
            return null;
        }
    }

    private function resetOrgForm(): void
    {
        $this->orgFirstName = $this->orgMiddleInitial = $this->orgLastName = $this->orgSuffix = '';
        $this->orgTeacherId = $this->orgEmail = $this->orgDept = '';
        $this->orgPhoto = null;
        $this->organizerErrors = [];
    }

    public function openEditCourse(int $id): void
    {
        try {
            $course = Course::findOrFail($id);
            $this->editingCourseId = $course->id;
            $this->courseCode = $course->code;
            $this->courseName = $course->name;
            $this->courseAlert = '';
            $this->courseAlertType = '';
        } catch (\Exception $e) {
            $this->courseAlert = 'Failed to load course data.';
            $this->courseAlertType = 'error';
        }
    }

    public function resetCourseForm(): void
    {
        $this->editingCourseId = null;
        $this->courseCode = $this->courseName = '';
        $this->courseAlert = '';
        $this->courseAlertType = '';
    }

    public function saveCourse(): void
    {
        $code = strtoupper(trim($this->courseCode));
        $name = trim($this->courseName);
        
        if (!$code || !$name) {
            $this->courseAlert = 'Code and Name are required.';
            $this->courseAlertType = 'error';
            return;
        }
        
        $this->savingCourse = true;
        try {
            if ($this->editingCourseId) {
                Course::findOrFail($this->editingCourseId)->update(['code' => $code, 'name' => $name]);
                $this->courseAlert = 'Course updated successfully!';
            } else {
                Course::create(['code' => $code, 'name' => $name]);
                $this->courseAlert = 'Course added successfully!';
            }
            $this->courseAlertType = 'success';
            $this->coursesList = Course::all()->toArray();
            $this->dispatch('clearCourseAlert');
            $this->resetCourseForm();
        } catch (\Exception $e) {
            $this->courseAlert = str_contains($e->getMessage(), 'Duplicate entry')
                ? 'A course with this code already exists.'
                : 'Failed to save course.';
            $this->courseAlertType = 'error';
        } finally {
            $this->savingCourse = false;
        }
    }

    public function confirmDeleteCourse(int $id): void
    {
        try {
            $course = Course::findOrFail($id);
            $this->deleteCourseId = $id;
            $this->deleteCourseName = $course->name;
            $this->activeModal = 'deleteCourseConfirm';
        } catch (\Exception $e) {
            $this->courseAlert = 'Failed to load course data.';
            $this->courseAlertType = 'error';
        }
    }

    public function deleteCourse(): void
    {
        $this->deletingCourse = true;
        try {
            Course::findOrFail($this->deleteCourseId)->delete();
            $this->courseAlert = 'Course deleted successfully!';
            $this->courseAlertType = 'success';
            $this->coursesList = Course::all()->toArray();
            $this->deleteCourseId = null;
            $this->deleteCourseName = '';
            $this->activeModal = 'manageCourses';
            $this->dispatch('clearCourseAlert');
        } catch (\Exception $e) {
            $this->courseAlert = 'Failed to delete course.';
            $this->courseAlertType = 'error';
            $this->activeModal = 'manageCourses';
        } finally {
            $this->deletingCourse = false;
        }
    }

    private function flash(string $type, string $message): void
    {
        $this->flashType = $type;
        $this->flashMessage = $message;
        $this->showFlash = true;
    }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path) {
            return asset('storage/alumni-photos/default.png');
        }

        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }

        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            if (Storage::disk('public')->exists($path)) {
                return asset('storage/' . $path);
            }
            return asset('storage/alumni-photos/default.png');
        }

        return asset('storage/alumni-photos/default.png');
    }
};
?>

<div class="flex flex-col bg-gray-50 overflow-hidden" style="height:90vh;">

    <style>
        .scrollbar-thin::-webkit-scrollbar        { width:5px; height:5px; }
        .scrollbar-thin::-webkit-scrollbar-track  { background:#f3f4f6; border-radius:10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb  { background:#c4b5d4; border-radius:10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background:#9c7db5; }
    </style>

    <!-- FLASH MESSAGE -->
    @if($showFlash)
    <div x-data="{ visible: true }"
         x-effect="if (visible) { setTimeout(() => { visible = false; $wire.showFlash = false }, 5000) }"
         x-show="visible"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-xl shadow-xl max-w-sm border"
         :class="'{{ $flashType }}' === 'success'
             ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
             : 'bg-red-50 border-red-200 text-red-800'">
        <i class="fas mt-0.5 text-lg"
           :class="'{{ $flashType }}' === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'"></i>
        <div class="flex-1 min-w-0">
            <div class="font-bold text-lg">{{ $flashType === 'success' ? 'Success!' : 'Error' }}</div>
            <div class="text-base mt-1 leading-snug">{{ $flashMessage }}</div>
        </div>
        <button @click="visible=false; $wire.showFlash=false" class="opacity-50 hover:opacity-100 shrink-0 mt-0.5">
            <i class="fas fa-times text-base"></i>
        </button>
    </div>
    @endif

    <!-- MAIN CONTENT -->
    <div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

        <!-- HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0">
            <div>
                <h1 class="text-4xl font-bold text-gray-900 flex items-center gap-3">
                    <div class="w-14 h-14 bg-[#7A3F91] rounded-xl flex items-center justify-center text-white shadow">
                        <i class="fas fa-users text-xl"></i>
                    </div>
                    Alumni & Organizers Management
                </h1>
                <p class="text-gray-500 text-base mt-2 ml-0.5">A centralized system for managing alumni and organizer records.</p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                @if($this->activeTab === 'alumni')
                    <button wire:click="openModal('registerAlumni')"
                            class="inline-flex items-center gap-2 px-5 py-3 bg-[#7A3F91] text-white rounded-lg font-semibold hover:bg-[#6a3680] transition shadow-sm text-sm">
                        <i class="fas fa-user-plus"></i> Register Alumni
                    </button>
                    <button wire:click="openModal('importModal')"
                            class="inline-flex items-center gap-2 px-5 py-3 border-2 border-[#7A3F91] text-[#7A3F91] rounded-lg font-semibold hover:bg-purple-50 transition text-sm">
                        <i class="fas fa-file-import"></i> Import
                    </button>
                    <button wire:click="openModal('manageCourses')"
                            class="inline-flex items-center gap-2 px-5 py-3 border-2 border-[#7A3F91] text-[#7A3F91] rounded-lg font-semibold hover:bg-purple-50 transition text-sm">
                        <i class="fas fa-sliders"></i> Manage Courses
                    </button>
                @elseif($this->activeTab === 'organizers')
                    <button wire:click="openModal('registerOrganizer')"
                            class="inline-flex items-center gap-2 px-5 py-3 bg-[#7A3F91] text-white rounded-lg font-semibold hover:bg-[#6a3680] transition shadow-sm text-sm">
                        <i class="fas fa-users-gear"></i> Register Organizer
                    </button>
                    <button wire:click="openModal('manageCourses')"
                            class="inline-flex items-center gap-2 px-5 py-3 border-2 border-[#7A3F91] text-[#7A3F91] rounded-lg font-semibold hover:bg-purple-50 transition text-sm">
                        <i class="fas fa-sliders"></i> Manage Courses
                    </button>
                @endif
            </div>
        </div>

        <!-- TABS -->
        <div class="flex gap-2 mb-4 shrink-0">
            <button wire:click="switchTab('alumni')"
                    class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm
                           {{ $this->activeTab==='alumni' ? 'bg-[#7A3F91] text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:border-[#7A3F91]' }}">
                <i class="fas fa-graduation-cap"></i> Alumni
            </button>
            <button wire:click="switchTab('organizers')"
                    class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm
                           {{ $this->activeTab==='organizers' ? 'bg-[#7A3F91] text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:border-[#7A3F91]' }}">
                <i class="fas fa-users-gear"></i> Organizers
            </button>
        </div>

        <!-- TABLE PANEL -->
        <div class="flex-1 min-h-0 bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">

            {{-- ALUMNI TAB --}}
            @if($this->activeTab === 'alumni')

            <!-- Filter bar -->
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input wire:model.live.debounce.400ms="alumniSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none">
                </div>
                <select wire:model.live="alumniBatch"
                        class="px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Years</option>
                    @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse"
                        class="px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort"
                        class="px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters"
                        class="px-4 py-3 text-gray-700 hover:bg-gray-100 rounded-lg border border-gray-300 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto scrollbar-thin">
                <table class="w-full">
                    <thead class="bg-[#7A3F91] text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Name</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Student ID</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Course</th>
                            <th class="px-6 py-4 text-center text-sm font-bold uppercase tracking-wide whitespace-nowrap">Year</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Email</th>
                            <th class="px-6 py-4 text-center text-sm font-bold uppercase tracking-wide whitespace-nowrap">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->alumniRecords as $item)
                        <tr class="hover:bg-purple-50/40 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-10 h-10 rounded-lg object-cover border border-[#7A3F91]/20 shrink-0">
                                    <span class="font-semibold text-gray-900 text-base">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono text-gray-800 text-base font-semibold">{{ $item->student_id }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-2 bg-[#7A3F91]/10 text-[#7A3F91] rounded-full text-sm font-bold">{{ $item->course_code }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="font-mono text-gray-800 text-base font-semibold">{{ $item->batch }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-gray-800 text-base">{{ $item->email }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @php $sc = match($item->status) { 'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-gray-100 text-gray-600' }; @endphp
                                <span class="inline-block px-3 py-2 rounded-full text-sm font-bold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <i class="fas fa-users text-5xl text-gray-200 block mb-4"></i>
                                <p class="font-semibold text-gray-400 text-lg">No alumni found</p>
                                <p class="text-base text-gray-400 mt-1">Try adjusting your filters</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-gray-600 text-sm">
                        @php $total=$this->alumniRecords->total(); $pp=$this->alumniRecords->perPage(); $cp=$this->alumniRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <b class="text-gray-800">{{ $from }}</b>–<b class="text-gray-800">{{ $to }}</b> of <b class="text-gray-800">{{ $total }}</b>
                    </p>
                    <div class="flex gap-2 items-center">
                        @if($this->alumniRecords->onFirstPage())
                            <button disabled class="px-4 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('alumniPage')" class="px-4 py-2 bg-[#7A3F91] text-white rounded-lg text-sm font-medium hover:bg-[#6a3680] transition">← Prev</button>
                        @endif
                        <span class="px-4 py-2 text-gray-700 text-sm font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                        @if($this->alumniRecords->hasMorePages())
                            <button wire:click="nextPage('alumniPage')" class="px-4 py-2 bg-[#7A3F91] text-white rounded-lg text-sm font-medium hover:bg-[#6a3680] transition">Next →</button>
                        @else
                            <button disabled class="px-4 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- ORGANIZERS TAB --}}
            @if($this->activeTab === 'organizers')

            <!-- Filter bar -->
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input wire:model.live.debounce.400ms="orgSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none">
                </div>
                <select wire:model.live="orgDepartment"
                        class="px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Departments</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort"
                        class="px-4 py-3 border border-gray-300 rounded-lg text-sm bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters"
                        class="px-4 py-3 text-gray-700 hover:bg-gray-100 rounded-lg border border-gray-300 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto scrollbar-thin">
                <table class="w-full">
                    <thead class="bg-[#7A3F91] text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Name</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Teacher ID</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Email</th>
                            <th class="px-6 py-4 text-left text-sm font-bold uppercase tracking-wide whitespace-nowrap">Department</th>
                            <th class="px-6 py-4 text-center text-sm font-bold uppercase tracking-wide whitespace-nowrap">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->organizerRecords as $item)
                        <tr class="hover:bg-purple-50/40 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-10 h-10 rounded-lg object-cover border border-[#7A3F91]/20 shrink-0">
                                    <span class="font-semibold text-gray-900 text-base">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono text-gray-800 text-base font-semibold">{{ $item->id_number }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-gray-800 text-base">{{ $item->email }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-2 bg-[#7A3F91]/10 text-[#7A3F91] rounded-full text-sm font-bold">{{ $item->department }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @php $sc = match($item->status) { 'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-gray-100 text-gray-600' }; @endphp
                                <span class="inline-block px-3 py-2 rounded-full text-sm font-bold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-16 text-center">
                                <i class="fas fa-users-gear text-5xl text-gray-200 block mb-4"></i>
                                <p class="font-semibold text-gray-400 text-lg">No organizers found</p>
                                <p class="text-base text-gray-400 mt-1">Register an organizer to get started</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-gray-600 text-sm">
                        @php $total=$this->organizerRecords->total(); $pp=$this->organizerRecords->perPage(); $cp=$this->organizerRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <b class="text-gray-800">{{ $from }}</b>–<b class="text-gray-800">{{ $to }}</b> of <b class="text-gray-800">{{ $total }}</b>
                    </p>
                    <div class="flex gap-2 items-center">
                        @if($this->organizerRecords->onFirstPage())
                            <button disabled class="px-4 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('orgPage')" class="px-4 py-2 bg-[#7A3F91] text-white rounded-lg text-sm font-medium hover:bg-[#6a3680] transition">← Prev</button>
                        @endif
                        <span class="px-4 py-2 text-gray-700 text-sm font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                        @if($this->organizerRecords->hasMorePages())
                            <button wire:click="nextPage('orgPage')" class="px-4 py-2 bg-[#7A3F91] text-white rounded-lg text-sm font-medium hover:bg-[#6a3680] transition">Next →</button>
                        @else
                            <button disabled class="px-4 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

        </div>

        <!-- REGISTER ALUMNI MODAL - LARGER VERSION -->
        @if($activeModal === 'registerAlumni')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-thin">
                <div class="flex items-center justify-between px-8 py-6 bg-[#7A3F91] text-white rounded-t-2xl sticky top-0 z-10">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-user-plus text-2xl"></i> Register Alumni</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70">×</button>
                </div>

                @if(count($alumniErrors) > 0)
                <div class="bg-red-50 border-b-4 border-red-300 px-8 py-5">
                    <p class="font-bold text-red-800 text-base mb-3">⚠️ Please fix the following errors:</p>
                    <ul class="text-red-700 text-base space-y-2">
                        @foreach($alumniErrors as $field => $messages)
                            @foreach($messages as $message)
                                <li class="flex items-start gap-2">
                                    <span class="text-red-500 mt-1">•</span>
                                    <span>{{ $message }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
                @endif

                <form wire:submit="registerAlumni" class="p-8 space-y-6">

                    <!-- PHOTO UPLOAD - LARGE PREVIEW -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Profile Photo <span class="font-normal text-gray-500">(Optional)</span></label>
                        <div class="border-3 border-dashed border-[#7A3F91]/40 rounded-2xl p-8 text-center cursor-pointer hover:border-[#7A3F91] hover:bg-purple-50 transition"
                             onclick="document.getElementById('regPhotoInput').click()">
                            @if($regPhoto)
                                <img src="{{ $regPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-xl mx-auto mb-4 object-cover shadow-md border-4 border-[#7A3F91]/20">
                                <p class="text-base text-emerald-600 font-bold">✓ Photo Selected</p>
                                <p class="text-sm text-gray-500 mt-2">Click to change photo</p>
                            @else
                                <i class="fas fa-cloud-arrow-up text-5xl text-[#7A3F91]/30 block mb-3"></i>
                                <p class="text-base text-gray-700 font-semibold">Click to Upload Photo</p>
                                <p class="text-sm text-gray-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                            @endif
                            <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                        </div>
                    </div>

                    <!-- FULL NAME -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Full Name <span class="text-red-500 text-lg">*</span></label>
                        <div class="grid grid-cols-12 gap-3">
                            <div class="col-span-5">
                                <input wire:model="regFirstName" type="text" placeholder="First Name"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">First <span class="text-red-400">*</span></p>
                            </div>
                            <div class="col-span-2">
                                <input wire:model="regMiddleInitial" type="text" placeholder="M.I." maxlength="5"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">M.I.</p>
                            </div>
                            <div class="col-span-5">
                                <input wire:model="regLastName" type="text" placeholder="Last Name"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">Last <span class="text-red-400">*</span></p>
                            </div>
                            <div class="col-span-3">
                                <input wire:model="regSuffix" type="text" placeholder="Jr." maxlength="10"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">Suffix</p>
                            </div>
                        </div>
                        @if($regFirstName || $regLastName)
                        <p class="text-base text-[#7A3F91] font-bold mt-3 pl-1">
                            Preview: {{ trim("{$regFirstName} {$regMiddleInitial} {$regLastName}" . ($regSuffix ? ' '.$regSuffix : '')) }}
                        </p>
                        @endif
                    </div>

                    <!-- STUDENT ID -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">
                            Student ID <span class="text-red-500 text-lg">*</span>
                            <span class="font-normal text-gray-600 text-sm">(up to 8 digits)</span>
                        </label>
                        <div class="relative">
                            <input wire:model="regStudentId" type="text" placeholder="e.g. 12345"
                                   maxlength="8" inputmode="numeric" pattern="\d*"
                                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base font-mono focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                            @if($regStudentId && strlen($regStudentId) < 8)
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-mono">
                                → {{ str_pad($regStudentId, 8, '0', STR_PAD_LEFT) }}
                            </span>
                            @endif
                        </div>
                    </div>

                    <!-- EMAIL -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Email Address <span class="text-red-500 text-lg">*</span></label>
                        <input wire:model="regEmail" type="email" placeholder="student@example.com"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                    </div>

                    <!-- COURSE & YEAR -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-base font-bold text-gray-800 mb-3">Course <span class="text-red-500 text-lg">*</span></label>
                            <select wire:model="regCourseCode"
                                    class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:outline-none text-gray-800">
                                <option value="">Select Course</option>
                                @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-base font-bold text-gray-800 mb-3">Year <span class="text-red-500 text-lg">*</span></label>
                            <input wire:model="regYear" type="number" placeholder="{{ date('Y') }}" min="2000" max="{{ date('Y') }}"
                                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:outline-none text-gray-800">
                        </div>
                    </div>

                    <!-- BUTTONS -->
                    <div class="flex gap-4 pt-3">
                        <button type="button" wire:click="closeModal"
                                class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-800 rounded-lg text-base font-bold hover:bg-gray-100 transition">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                                class="flex-1 px-6 py-3 bg-[#7A3F91] text-white rounded-lg text-base font-bold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                            <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner fa-spin"></i> Registering…</span>
                            <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check text-lg"></i> Register Alumni</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

        <!-- REGISTER ORGANIZER MODAL - LARGER VERSION -->
        @if($activeModal === 'registerOrganizer')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-thin">
                <div class="flex items-center justify-between px-8 py-6 bg-[#7A3F91] text-white rounded-t-2xl sticky top-0 z-10">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-users-gear text-2xl"></i> Register Organizer</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70">×</button>
                </div>

                @if(count($organizerErrors) > 0)
                <div class="bg-red-50 border-b-4 border-red-300 px-8 py-5">
                    <p class="font-bold text-red-800 text-base mb-3">⚠️ Please fix the following errors:</p>
                    <ul class="text-red-700 text-base space-y-2">
                        @foreach($organizerErrors as $field => $messages)
                            @foreach($messages as $message)
                                <li class="flex items-start gap-2">
                                    <span class="text-red-500 mt-1">•</span>
                                    <span>{{ $message }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
                @endif

                <form wire:submit="registerOrganizer" class="p-8 space-y-6">

                    <!-- PHOTO UPLOAD - LARGE PREVIEW -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Profile Photo <span class="font-normal text-gray-500">(Optional)</span></label>
                        <div class="border-3 border-dashed border-[#7A3F91]/40 rounded-2xl p-8 text-center cursor-pointer hover:border-[#7A3F91] hover:bg-purple-50 transition"
                             onclick="document.getElementById('orgPhotoInput').click()">
                            @if($orgPhoto)
                                <img src="{{ $orgPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-xl mx-auto mb-4 object-cover shadow-md border-4 border-[#7A3F91]/20">
                                <p class="text-base text-emerald-600 font-bold">✓ Photo Selected</p>
                                <p class="text-sm text-gray-500 mt-2">Click to change photo</p>
                            @else
                                <i class="fas fa-cloud-arrow-up text-5xl text-[#7A3F91]/30 block mb-3"></i>
                                <p class="text-base text-gray-700 font-semibold">Click to Upload Photo</p>
                                <p class="text-sm text-gray-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                            @endif
                            <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                        </div>
                    </div>

                    <!-- FULL NAME -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Full Name <span class="text-red-500 text-lg">*</span></label>
                        <div class="grid grid-cols-12 gap-3">
                            <div class="col-span-5">
                                <input wire:model="orgFirstName" type="text" placeholder="First Name"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">First <span class="text-red-400">*</span></p>
                            </div>
                            <div class="col-span-2">
                                <input wire:model="orgMiddleInitial" type="text" placeholder="M.I." maxlength="5"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">M.I.</p>
                            </div>
                            <div class="col-span-5">
                                <input wire:model="orgLastName" type="text" placeholder="Last Name"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">Last <span class="text-red-400">*</span></p>
                            </div>
                            <div class="col-span-3">
                                <input wire:model="orgSuffix" type="text" placeholder="Jr." maxlength="10"
                                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                                <p class="text-xs text-gray-500 mt-1 pl-1">Suffix</p>
                            </div>
                        </div>
                        @if($orgFirstName || $orgLastName)
                        <p class="text-base text-[#7A3F91] font-bold mt-3 pl-1">
                            Preview: {{ trim("{$orgFirstName} {$orgMiddleInitial} {$orgLastName}" . ($orgSuffix ? ' '.$orgSuffix : '')) }}
                        </p>
                        @endif
                    </div>

                    <!-- TEACHER ID -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">
                            Teacher ID <span class="text-red-500 text-lg">*</span>
                            <span class="font-normal text-gray-600 text-sm">(up to 8 digits)</span>
                        </label>
                        <div class="relative">
                            <input wire:model="orgTeacherId" type="text" placeholder="e.g. 12345"
                                   maxlength="8" inputmode="numeric" pattern="\d*"
                                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base font-mono focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                            @if($orgTeacherId && strlen($orgTeacherId) < 8)
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-500 font-mono">
                                → {{ str_pad($orgTeacherId, 8, '0', STR_PAD_LEFT) }}
                            </span>
                            @endif
                        </div>
                    </div>

                    <!-- EMAIL -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Email Address <span class="text-red-500 text-lg">*</span></label>
                        <input wire:model="orgEmail" type="email" placeholder="teacher@example.com"
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                    </div>

                    <!-- DEPARTMENT -->
                    <div>
                        <label class="block text-base font-bold text-gray-800 mb-3">Department <span class="text-red-500 text-lg">*</span></label>
                        <select wire:model="orgDept"
                                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-base focus:border-[#7A3F91] focus:outline-none text-gray-800">
                            <option value="">Select Department</option>
                            @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
                        </select>
                    </div>

                    <!-- BUTTONS -->
                    <div class="flex gap-4 pt-3">
                        <button type="button" wire:click="closeModal"
                                class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-800 rounded-lg text-base font-bold hover:bg-gray-100 transition">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                                class="flex-1 px-6 py-3 bg-[#7A3F91] text-white rounded-lg text-base font-bold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                            <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner fa-spin"></i> Registering…</span>
                            <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear text-lg"></i> Register Organizer</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

    </div>
</div>