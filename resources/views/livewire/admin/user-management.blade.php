<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
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

    // Register Alumni
    public string $regName = '';
    public string $regStudentId = '';
    public string $regEmail = '';
    public string $regCourseCode = '';
    public string $regBatch = '';
    public $regPhoto = null;
    public bool $registeringAlumni = false;

    // Edit Alumni
    public ?int $editAlumniId = null;
    public string $editName = '';
    public string $editStudentId = '';
    public string $editCourseCode = '';
    public string $editBatch = '';
    public $editPhoto = null;
    public ?string $editAlumniCurrentPhoto = null;
    public bool $updatingAlumni = false;

    // Register Organizer
    public string $orgName = '';
    public string $orgEmail = '';
    public string $orgIdNumber = '';
    public string $orgDept = '';
    public $orgPhoto = null;
    public bool $registeringOrganizer = false;

    // Edit Organizer
    public ?int $editOrganizerId = null;
    public string $editOrgName = '';
    public string $editOrgIdNumber = '';
    public string $editOrgDept = '';
    public $editOrgPhoto = null;
    public ?string $editOrgCurrentPhoto = null;
    public bool $updatingOrganizer = false;

    // Delete
    public ?int $deleteId = null;
    public string $deleteType = '';
    public string $deleteName = '';
    public bool $deleting = false;

    // Manage Courses
    public array $coursesList = [];
    public string $courseCode = '';
    public string $courseName = '';
    public ?int $editingCourseId = null;
    public bool $savingCourse = false;
    public string $courseAlert = '';
    public string $courseAlertType = '';

    // Delete Course Confirmation
    public ?int $deleteCourseId = null;
    public string $deleteCourseName = '';
    public bool $deletingCourse = false;

    // Flash
    public string $flashMessage = '';
    public string $flashType = '';
    public bool $showFlash = false;

    // Modal state tracked in Livewire (not just Alpine) to survive re-renders
    public string $activeModal = '';

    protected string $paginationTheme = 'tailwind';

    public function mount()
    {
        $this->coursesList = Course::all()->toArray();
        $this->regBatch = (string) date('Y');
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
    #[Computed] public function totalAlumni() { return Alumni::count(); }
    #[Computed] public function totalOrganizers() { return Organizer::withoutTrashed()->count(); }

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

    // ============ ALUMNI CRUD ============

    public function registerAlumni(): void
    {
        $this->registeringAlumni = true;
        try {
            $this->validate([
                'regName'       => ['required', 'string', 'max:255'],
                'regStudentId'  => ['required', 'string', 'size:8', 'regex:/^\d+$/', 'unique:alumni,student_id'],
                'regEmail'      => ['required', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
                'regCourseCode' => ['required', 'string', 'exists:courses,code'],
                'regBatch'      => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
                'regPhoto'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);
            $course     = Course::where('code', $this->regCourseCode)->firstOrFail();
            $photoPath  = $this->regPhoto ? $this->regPhoto->store('alumni-photos', 'public') : $this->copyDefaultPhoto();
            $alumni     = Alumni::create([
                'name' => $this->regName, 'student_id' => $this->regStudentId, 'email' => $this->regEmail,
                'course_code' => $this->regCourseCode, 'course_name' => $course->name,
                'batch' => (int)$this->regBatch, 'status' => 'VERIFIED', 'profile_photo' => $photoPath,
            ]);
            $tempPassword = Str::random(10);
            User::create(['name' => $alumni->name, 'email' => $alumni->email, 'password' => Hash::make($tempPassword), 'role' => 'alumni']);
            try { Mail::to($alumni->email)->send(new \App\Mail\AlumniRegistered($alumni, $tempPassword)); } catch (\Exception $e) { Log::warning("Email not sent: " . $e->getMessage()); }
            $this->resetRegAlumniForm();
            $this->flash('success', "Alumni '{$alumni->name}' registered successfully!");
            $this->activeModal = '';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->flash('error', 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))));
        } catch (\Exception $e) {
            Log::error('Alumni creation failed: ' . $e->getMessage());
            $this->flash('error', str_contains($e->getMessage(), 'Duplicate entry') ? 'This student ID or email is already registered.' : 'Failed to register alumni.');
        } finally { $this->registeringAlumni = false; }
    }

    private function resetRegAlumniForm(): void
    {
        $this->regName = $this->regStudentId = $this->regEmail = $this->regCourseCode = '';
        $this->regPhoto = null;
        $this->regBatch = (string) date('Y');
    }

    public function openEditAlumni(int $id): void
    {
        try {
            $alumni = Alumni::findOrFail($id);
            $this->editAlumniId = $alumni->id;
            $this->editName = $alumni->name;
            $this->editStudentId = $alumni->student_id;
            $this->editCourseCode = $alumni->course_code;
            $this->editBatch = (string)$alumni->batch;
            $this->editAlumniCurrentPhoto = $alumni->profile_photo;
            $this->editPhoto = null;
            $this->activeModal = 'editAlumni';
        } catch (\Exception $e) { Log::error($e->getMessage()); $this->flash('error', 'Failed to load alumni data.'); }
    }

    public function updateAlumni(): void
    {
        $this->updatingAlumni = true;
        try {
            $this->validate([
                'editName'       => ['required', 'string', 'max:255'],
                'editStudentId'  => ['required', 'string', 'size:8', 'regex:/^\d+$/', "unique:alumni,student_id,{$this->editAlumniId}"],
                'editCourseCode' => ['required', 'string', 'exists:courses,code'],
                'editBatch'      => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
                'editPhoto'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);
            $alumni    = Alumni::findOrFail($this->editAlumniId);
            $course    = Course::where('code', $this->editCourseCode)->firstOrFail();
            $photoPath = $this->editAlumniCurrentPhoto;
            if ($this->editPhoto) {
                if ($photoPath && $photoPath !== 'alumni-photos/default.png' && Storage::disk('public')->exists($photoPath)) Storage::disk('public')->delete($photoPath);
                $photoPath = $this->editPhoto->store('alumni-photos', 'public');
            }
            $alumni->update(['name' => $this->editName, 'student_id' => $this->editStudentId, 'course_code' => $this->editCourseCode, 'course_name' => $course->name, 'batch' => (int)$this->editBatch, 'profile_photo' => $photoPath]);
            if ($alumni->user) $alumni->user()->update(['name' => $this->editName]);
            $this->editAlumniId = null; $this->editName = $this->editStudentId = $this->editCourseCode = $this->editBatch = ''; $this->editAlumniCurrentPhoto = null; $this->editPhoto = null;
            $this->flash('success', "Alumni '{$alumni->name}' updated successfully!");
            $this->activeModal = '';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->flash('error', 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))));
        } catch (\Exception $e) {
            Log::error('Alumni update failed: ' . $e->getMessage());
            $this->flash('error', str_contains($e->getMessage(), 'Duplicate entry') ? 'This student ID is already in use.' : 'Failed to update alumni.');
        } finally { $this->updatingAlumni = false; }
    }

    // ============ ORGANIZER CRUD ============

    public function registerOrganizer(): void
    {
        $this->registeringOrganizer = true;
        try {
            $this->validate([
                'orgName'     => ['required', 'string', 'max:255'],
                'orgEmail'    => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
                'orgIdNumber' => ['required', 'string', 'unique:organizer,id_number'],
                'orgDept'     => ['required', 'string', 'exists:courses,code'],
                'orgPhoto'    => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ]);
            $photoPath = $this->orgPhoto ? $this->orgPhoto->store('organizer-photos', 'public') : $this->copyDefaultPhoto();
            $tempPassword = Str::random(10);
            $user = User::create(['name' => $this->orgName, 'email' => $this->orgEmail, 'role' => 'organizer', 'password' => Hash::make($tempPassword)]);
            $organizer = Organizer::create(['user_id' => $user->id, 'name' => $this->orgName, 'email' => $this->orgEmail, 'id_number' => $this->orgIdNumber, 'department' => strtoupper($this->orgDept), 'profile_photo' => $photoPath, 'status' => 'ACTIVE']);
            try { Mail::to($organizer->email)->send(new \App\Mail\OrganizerRegistered($organizer, $tempPassword)); } catch (\Exception $e) { Log::warning("Email not sent: " . $e->getMessage()); }
            $this->resetOrgForm();
            $this->flash('success', "Organizer '{$organizer->name}' registered successfully!");
            $this->activeModal = '';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->flash('error', 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))));
        } catch (\Exception $e) {
            Log::error('Organizer creation failed: ' . $e->getMessage());
            $this->flash('error', str_contains($e->getMessage(), 'Duplicate entry') ? 'This email or ID number is already registered.' : 'Failed to register organizer.');
        } finally { $this->registeringOrganizer = false; }
    }

    private function resetOrgForm(): void { $this->orgName = $this->orgEmail = $this->orgIdNumber = $this->orgDept = ''; $this->orgPhoto = null; }

    public function openEditOrganizer(int $id): void
    {
        try {
            $org = Organizer::findOrFail($id);
            $this->editOrganizerId = $org->id; $this->editOrgName = $org->name; $this->editOrgIdNumber = $org->id_number;
            $this->editOrgDept = $org->department; $this->editOrgCurrentPhoto = $org->profile_photo; $this->editOrgPhoto = null;
            $this->activeModal = 'editOrganizer';
        } catch (\Exception $e) { Log::error($e->getMessage()); $this->flash('error', 'Failed to load organizer data.'); }
    }

    public function updateOrganizer(): void
    {
        $this->updatingOrganizer = true;
        try {
            $this->validate([
                'editOrgName'     => ['required', 'string', 'max:255'],
                'editOrgIdNumber' => ['required', 'string', "unique:organizer,id_number,{$this->editOrganizerId}"],
                'editOrgDept'     => ['required', 'string', 'exists:courses,code'],
                'editOrgPhoto'    => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ]);
            $org = Organizer::findOrFail($this->editOrganizerId);
            $photoPath = $this->editOrgCurrentPhoto;
            if ($this->editOrgPhoto) {
                if ($photoPath && $photoPath !== 'alumni-photos/default.png' && Storage::disk('public')->exists($photoPath)) Storage::disk('public')->delete($photoPath);
                $photoPath = $this->editOrgPhoto->store('organizer-photos', 'public');
            }
            if ($org->user) $org->user()->update(['name' => $this->editOrgName]);
            $org->update(['name' => $this->editOrgName, 'id_number' => $this->editOrgIdNumber, 'department' => strtoupper($this->editOrgDept), 'profile_photo' => $photoPath]);
            $this->editOrganizerId = null; $this->editOrgName = $this->editOrgIdNumber = $this->editOrgDept = ''; $this->editOrgCurrentPhoto = null; $this->editOrgPhoto = null;
            $this->flash('success', "Organizer '{$org->name}' updated successfully!");
            $this->activeModal = '';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->flash('error', 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors()))));
        } catch (\Exception $e) {
            Log::error('Organizer update failed: ' . $e->getMessage());
            $this->flash('error', str_contains($e->getMessage(), 'Duplicate entry') ? 'This ID number is already in use.' : 'Failed to update organizer.');
        } finally { $this->updatingOrganizer = false; }
    }

    public function confirmDelete(int $id, string $type): void
    {
        try {
            $this->deleteId = $id; $this->deleteType = $type;
            $record = $type === 'alumni' ? Alumni::findOrFail($id) : Organizer::findOrFail($id);
            $this->deleteName = $record->name;
            $this->activeModal = 'deleteConfirm';
        } catch (\Exception $e) { Log::error($e->getMessage()); $this->flash('error', 'Failed to load record data.'); }
    }

    public function deleteRecord(): void
    {
        $this->deleting = true;
        try {
            if ($this->deleteType === 'alumni') {
                $record = Alumni::findOrFail($this->deleteId);
            } else {
                $record = Organizer::findOrFail($this->deleteId);
            }
            if ($record->profile_photo && $record->profile_photo !== 'alumni-photos/default.png' && Storage::disk('public')->exists($record->profile_photo)) {
                Storage::disk('public')->delete($record->profile_photo);
            }
            if ($record->user) $record->user()->delete();
            $record->delete();
            $this->flash('success', "'{$this->deleteName}' deleted successfully!");
            $this->deleteId = null; $this->deleteType = ''; $this->deleteName = '';
            $this->activeModal = '';
        } catch (\Exception $e) { Log::error('Delete failed: ' . $e->getMessage()); $this->flash('error', 'Failed to delete record.');
        } finally { $this->deleting = false; }
    }

    // ============ COURSES ============

    public function openEditCourse(int $id): void
    {
        try {
            $course = Course::findOrFail($id);
            $this->editingCourseId = $course->id; $this->courseCode = $course->code; $this->courseName = $course->name; $this->courseAlert = '';
        } catch (\Exception $e) { Log::error($e->getMessage()); $this->courseAlert = 'Failed to load course data.'; $this->courseAlertType = 'error'; }
    }

    public function resetCourseForm(): void { $this->editingCourseId = null; $this->courseCode = $this->courseName = $this->courseAlert = $this->courseAlertType = ''; }

    public function saveCourse(): void
    {
        $code = strtoupper(trim($this->courseCode)); $name = trim($this->courseName);
        if (!$code || !$name) { $this->courseAlert = 'Code and Name are required.'; $this->courseAlertType = 'error'; return; }
        $this->savingCourse = true;
        try {
            if ($this->editingCourseId) { Course::findOrFail($this->editingCourseId)->update(['code' => $code, 'name' => $name]); $this->courseAlert = 'Course updated successfully!'; }
            else { Course::create(['code' => $code, 'name' => $name]); $this->courseAlert = 'Course added successfully!'; }
            $this->courseAlertType = 'success'; $this->coursesList = Course::all()->toArray(); $this->resetCourseForm();
        } catch (\Exception $e) {
            Log::error('Course save failed: ' . $e->getMessage());
            $this->courseAlert = str_contains($e->getMessage(), 'Duplicate entry') ? 'A course with this code already exists.' : 'Failed to save course.';
            $this->courseAlertType = 'error';
        } finally { $this->savingCourse = false; }
    }

    public function confirmDeleteCourse(int $id): void
    {
        try {
            $course = Course::findOrFail($id); $this->deleteCourseId = $id; $this->deleteCourseName = $course->name;
            $this->activeModal = 'deleteCourseConfirm';
        } catch (\Exception $e) { Log::error($e->getMessage()); $this->courseAlert = 'Failed to load course data.'; $this->courseAlertType = 'error'; }
    }

    public function deleteCourse(): void
    {
        $this->deletingCourse = true;
        try {
            Course::findOrFail($this->deleteCourseId)->delete();
            $this->courseAlert = 'Course deleted successfully!'; $this->courseAlertType = 'success';
            $this->coursesList = Course::all()->toArray(); $this->deleteCourseId = null; $this->deleteCourseName = '';
            $this->activeModal = 'manageCourses';
        } catch (\Exception $e) { Log::error('Course delete failed: ' . $e->getMessage()); $this->courseAlert = 'Failed to delete course.'; $this->courseAlertType = 'error'; $this->activeModal = 'manageCourses';
        } finally { $this->deletingCourse = false; }
    }

    // ============ HELPERS ============

    private function copyDefaultPhoto(): string
    {
        $default = 'alumni-photos/default.png'; $dest = 'alumni-photos/' . Str::uuid() . '.png';
        if (Storage::disk('public')->exists($default)) { Storage::disk('public')->copy($default, $dest); return $dest; }
        return $default;
    }

    private function flash(string $type, string $message): void { $this->flashType = $type; $this->flashMessage = $message; $this->showFlash = true; }

    public function getPhotoUrl(?string $path): string
    {
        if ($path && Storage::disk('public')->exists($path)) return asset('storage/' . $path);
        return asset('storage/alumni-photos/default.png');
    }
};
?>

{{--
    LAYOUT NOTE:
    Assumes this component is embedded inside a layout that has a fixed sidebar.
    The outer div uses height:100vh + overflow:hidden → page never scrolls.
    Only the tbody scrolls. Adjust padding values if your nav bar height differs.
--}}
<div class="flex flex-col bg-gray-50 overflow-hidden" style="height:90vh;">

    <style>
        /* Slim, purple-tinted scrollbar */
        .scrollbar-thin::-webkit-scrollbar        { width:5px; height:5px; }
        .scrollbar-thin::-webkit-scrollbar-track  { background:#f3f4f6; border-radius:10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb  { background:#c4b5d4; border-radius:10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background:#9c7db5; }
    </style>

    <!-- ── FLASH ─────────────────────────────────────────── -->
    <div x-data="{ visible: false }"
         x-init="$watch('$wire.showFlash', val => { if(val){ visible=true; setTimeout(()=>{ visible=false; $wire.showFlash=false; },4000); } })"
         x-show="visible"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         class="fixed top-5 right-6 z-50 flex items-start gap-3 px-5 py-3.5 rounded-xl shadow-xl max-w-sm border"
         :class="$wire.flashType==='success'
             ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
             : 'bg-red-50 border-red-200 text-red-800'">
        <i class="fas mt-0.5 text-sm"
           :class="$wire.flashType==='success' ? 'fa-check-circle' : 'fa-exclamation-circle'"></i>
        <div class="flex-1 min-w-0">
            <div class="font-bold text-sm" x-text="$wire.flashType==='success' ? 'Success' : 'Error'"></div>
            <div class="text-xs mt-0.5 leading-snug" x-text="$wire.flashMessage"></div>
        </div>
        <button @click="visible=false; $wire.showFlash=false" class="opacity-50 hover:opacity-100 shrink-0">
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>

    <!-- ── MAIN CONTENT  (px-8 pt-7 pb-6 — tweak if your layout differs) ── -->
    <div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

        <!-- HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                    <div class="w-11 h-11 bg-[#7A3F91] rounded-xl flex items-center justify-center text-white shadow">
                        <i class="fas fa-users text-base"></i>
                    </div>
                    Alumni & Organizers Management
                </h1>
                <p class="text-gray-500 text-xs mt-1.5 ml-0.5">A centralized system for managing alumni and organizer records.</p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                <button wire:click="openModal('registerAlumni')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#7A3F91] text-white rounded-lg font-semibold hover:bg-[#6a3680] transition shadow-sm text-xs">
                    <i class="fas fa-user-plus"></i> Register Alumni
                </button>
                <button wire:click="openModal('registerOrganizer')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#7A3F91] text-white rounded-lg font-semibold hover:bg-[#6a3680] transition shadow-sm text-xs">
                    <i class="fas fa-users-gear"></i> Register Organizer
                </button>
                <button wire:click="openModal('importModal')"
                        class="inline-flex items-center gap-2 px-4 py-2 border-2 border-[#7A3F91] text-[#7A3F91] rounded-lg font-semibold hover:bg-purple-50 transition text-xs">
                    <i class="fas fa-file-import"></i> Import
                </button>
                <button wire:click="openModal('manageCourses')"
                        class="inline-flex items-center gap-2 px-4 py-2 border-2 border-[#7A3F91] text-[#7A3F91] rounded-lg font-semibold hover:bg-purple-50 transition text-xs">
                    <i class="fas fa-sliders"></i> Manage Courses
                </button>
            </div>
        </div>

        <!-- TABS -->
        <div class="flex gap-2 mb-3 shrink-0">
            <button wire:click="switchTab('alumni')"
                    class="px-5 py-2 rounded-lg font-semibold transition flex items-center gap-2 text-xs
                           {{ $this->activeTab==='alumni' ? 'bg-[#7A3F91] text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:border-[#7A3F91]' }}">
                <i class="fas fa-graduation-cap"></i> Alumni
                <span class="text-xs font-bold px-2 py-0.5 rounded-full
                             {{ $this->activeTab==='alumni' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }}">
                    {{ $this->totalAlumni }}
                </span>
            </button>
            <button wire:click="switchTab('organizers')"
                    class="px-5 py-2 rounded-lg font-semibold transition flex items-center gap-2 text-xs
                           {{ $this->activeTab==='organizers' ? 'bg-[#7A3F91] text-white shadow' : 'bg-white text-gray-600 border border-gray-200 hover:border-[#7A3F91]' }}">
                <i class="fas fa-users-gear"></i> Organizers
                <span class="text-xs font-bold px-2 py-0.5 rounded-full
                             {{ $this->activeTab==='organizers' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }}">
                    {{ $this->totalOrganizers }}
                </span>
            </button>
        </div>

        <!-- ── TABLE PANEL — flex-1 fills ALL remaining height ── -->
        <div class="flex-1 min-h-0 bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col overflow-hidden">

            {{-- ════ ALUMNI TAB ════ --}}
            @if($this->activeTab === 'alumni')

            <!-- Filter bar -->
            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-2 items-center shrink-0">
                <div class="relative flex-1 min-w-[180px] max-w-xs">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input wire:model.live.debounce.400ms="alumniSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none">
                </div>
                <select wire:model.live="alumniBatch"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Years</option>
                    @foreach($this->batches as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters"
                        class="px-3 py-2 text-gray-600 hover:bg-gray-100 rounded-lg border border-gray-200 transition text-xs font-medium">
                    <i class="fas fa-rotate-left mr-1"></i>Reset
                </button>
            </div>

            <!-- Scrollable table body -->
            <div class="flex-1 overflow-auto scrollbar-thin">
                <table class="w-full h-70">
                    {{-- Header: no bottom border on th, generous vertical padding so text breathes --}}
                    <thead class="bg-[#7A3F91] text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Name</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Student ID</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Course</th>
                            <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wide whitespace-nowrap">Year</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Email</th>
                            <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wide whitespace-nowrap">Status</th>
                            <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wide whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->alumniRecords as $item)
                        <tr class="hover:bg-purple-50/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-8 h-8 rounded-lg object-cover border border-[#7A3F91]/20 shrink-0">
                                    <span class="font-semibold text-gray-900 text-sm">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="font-mono text-gray-800 text-sm font-semibold">{{ $item->student_id }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-block px-2.5 py-1 bg-[#7A3F91]/10 text-[#7A3F91] rounded-full text-xs font-bold">{{ $item->course_code }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="font-mono text-gray-800 text-sm font-semibold">{{ $item->batch }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="text-gray-800 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @php $sc = match($item->status) { 'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-gray-100 text-gray-600' }; @endphp
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-center gap-1.5">
                                    <button wire:click="openEditAlumni({{ $item->id }})"
                                            class="p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition" title="Edit">
                                        <i class="fas fa-pen-to-square text-xs"></i>
                                    </button>
                                    <button wire:click="confirmDelete({{ $item->id }}, 'alumni')"
                                            class="p-2 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition" title="Delete">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-16 text-center">
                                <i class="fas fa-users text-4xl text-gray-200 block mb-3"></i>
                                <p class="font-semibold text-gray-400 text-sm">No alumni found</p>
                                <p class="text-xs text-gray-400 mt-1">Try adjusting your filters</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-gray-500 text-xs">
                        @php $total=$this->alumniRecords->total(); $pp=$this->alumniRecords->perPage(); $cp=$this->alumniRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <b class="text-gray-700">{{ $from }}</b>–<b class="text-gray-700">{{ $to }}</b> of <b class="text-gray-700">{{ $total }}</b>
                    </p>
                    <div class="flex gap-1.5 items-center">
                        @if($this->alumniRecords->onFirstPage())
                            <button disabled class="px-3 py-1.5 bg-gray-200 text-gray-400 rounded-lg text-xs font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('alumniPage')" class="px-3 py-1.5 bg-[#7A3F91] text-white rounded-lg text-xs font-medium hover:bg-[#6a3680] transition">← Prev</button>
                        @endif
                        <span class="px-3 py-1.5 text-gray-600 text-xs font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                        @if($this->alumniRecords->hasMorePages())
                            <button wire:click="nextPage('alumniPage')" class="px-3 py-1.5 bg-[#7A3F91] text-white rounded-lg text-xs font-medium hover:bg-[#6a3680] transition">Next →</button>
                        @else
                            <button disabled class="px-3 py-1.5 bg-gray-200 text-gray-400 rounded-lg text-xs font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- ════ ORGANIZERS TAB ════ --}}
            @if($this->activeTab === 'organizers')

            <!-- Filter bar -->
            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-2 items-center shrink-0">
                <div class="relative flex-1 min-w-[180px] max-w-xs">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input wire:model.live.debounce.400ms="orgSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none">
                </div>
                <select wire:model.live="orgDepartment"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="">All Departments</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white focus:border-[#7A3F91] focus:outline-none">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters"
                        class="px-3 py-2 text-gray-600 hover:bg-gray-100 rounded-lg border border-gray-200 transition text-xs font-medium">
                    <i class="fas fa-rotate-left mr-1"></i>Reset
                </button>
            </div>

            <!-- Scrollable table body -->
            <div class="flex-1 overflow-auto scrollbar-thin">
                <table class="w-full">
                    <thead class="bg-[#7A3F91] text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Name</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">ID Number</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Email</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide whitespace-nowrap">Department</th>
                            <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wide whitespace-nowrap">Status</th>
                            <th class="px-5 py-4 text-center text-xs font-bold uppercase tracking-wide whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->organizerRecords as $item)
                        <tr class="hover:bg-purple-50/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-8 h-8 rounded-lg object-cover border border-[#7A3F91]/20 shrink-0">
                                    <span class="font-semibold text-gray-900 text-sm">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="font-mono text-gray-800 text-sm font-semibold">{{ $item->id_number }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="text-gray-800 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-block px-2.5 py-1 bg-[#7A3F91]/10 text-[#7A3F91] rounded-full text-xs font-bold">{{ $item->department }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @php $sc = match($item->status) { 'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-gray-100 text-gray-600' }; @endphp
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-center gap-1.5">
                                    <button wire:click="openEditOrganizer({{ $item->id }})"
                                            class="p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition" title="Edit">
                                        <i class="fas fa-pen-to-square text-xs"></i>
                                    </button>
                                    <button wire:click="confirmDelete({{ $item->id }}, 'organizer')"
                                            class="p-2 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition" title="Delete">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <i class="fas fa-users-gear text-4xl text-gray-200 block mb-3"></i>
                                <p class="font-semibold text-gray-400 text-sm">No organizers found</p>
                                <p class="text-xs text-gray-400 mt-1">Register an organizer to get started</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-gray-500 text-xs">
                        @php $total=$this->organizerRecords->total(); $pp=$this->organizerRecords->perPage(); $cp=$this->organizerRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <b class="text-gray-700">{{ $from }}</b>–<b class="text-gray-700">{{ $to }}</b> of <b class="text-gray-700">{{ $total }}</b>
                    </p>
                    <div class="flex gap-1.5 items-center">
                        @if($this->organizerRecords->onFirstPage())
                            <button disabled class="px-3 py-1.5 bg-gray-200 text-gray-400 rounded-lg text-xs font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('orgPage')" class="px-3 py-1.5 bg-[#7A3F91] text-white rounded-lg text-xs font-medium hover:bg-[#6a3680] transition">← Prev</button>
                        @endif
                        <span class="px-3 py-1.5 text-gray-600 text-xs font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                        @if($this->organizerRecords->hasMorePages())
                            <button wire:click="nextPage('orgPage')" class="px-3 py-1.5 bg-[#7A3F91] text-white rounded-lg text-xs font-medium hover:bg-[#6a3680] transition">Next →</button>
                        @else
                            <button disabled class="px-3 py-1.5 bg-gray-200 text-gray-400 rounded-lg text-xs font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

        </div>{{-- end TABLE PANEL --}}
    </div>{{-- end MAIN CONTENT --}}


    {{-- ══════════════════════════════════════════════════
         MODALS — all controlled by Livewire $activeModal
    ══════════════════════════════════════════════════ --}}

    <!-- Register Alumni -->
    @if($activeModal === 'registerAlumni')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto scrollbar-thin">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-user-plus"></i> Register Alumni</h2>
                <button wire:click="closeModal" class="text-xl leading-none hover:opacity-70">×</button>
            </div>
            <form wire:submit="registerAlumni" class="p-7 space-y-4">
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    <p class="font-bold text-red-800 text-xs mb-1">Please fix the following errors:</p>
                    <ul class="text-red-700 text-xs space-y-0.5">@foreach($errors->all() as $e)<li>• {{ $e }}</li>@endforeach</ul>
                </div>
                @endif
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                    <div class="border-2 border-dashed border-[#7A3F91]/30 rounded-lg p-4 text-center cursor-pointer hover:border-[#7A3F91] transition"
                         onclick="document.getElementById('regPhotoInput').click()">
                        <i class="fas fa-cloud-arrow-up text-xl text-[#7A3F91]/40 block mb-1"></i>
                        <p class="text-xs text-gray-500">Click to upload · JPG, PNG, WebP · max 5 MB</p>
                        <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                        @if($regPhoto)<p class="text-xs text-emerald-600 font-semibold mt-1.5">✓ Photo selected</p>@endif
                    </div>
                    @error('regPhoto')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Full Name <span class="text-red-500">*</span></label>
                    <input wire:model="regName" type="text" placeholder="Juan dela Cruz"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none @error('regName') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('regName')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Student ID <span class="text-red-500">*</span> <span class="font-normal text-gray-400">(8 digits)</span></label>
                    <input wire:model="regStudentId" type="text" placeholder="20210001" maxlength="8" inputmode="numeric"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm font-mono focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none @error('regStudentId') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('regStudentId')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                    <input wire:model="regEmail" type="email" placeholder="student@example.com"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none @error('regEmail') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('regEmail')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Course <span class="text-red-500">*</span></label>
                        <select wire:model="regCourseCode"
                                class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('regCourseCode') border-red-400 bg-red-50 @enderror text-gray-800">
                            <option value="">Select</option>
                            @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                        </select>
                        @error('regCourseCode')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Year <span class="text-red-500">*</span></label>
                        <input wire:model="regBatch" type="number" placeholder="{{ date('Y') }}" min="2000" max="{{ date('Y') }}"
                               class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('regBatch') border-red-400 bg-red-50 @enderror text-gray-800">
                        @error('regBatch')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="flex gap-3 pt-1">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                            class="flex-1 px-4 py-2.5 bg-[#7A3F91] text-white rounded-lg text-sm font-semibold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner fa-spin"></i> Registering…</span>
                        <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check"></i> Register</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Edit Alumni -->
    @if($activeModal === 'editAlumni')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto scrollbar-thin">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-pen-to-square"></i> Edit Alumni</h2>
                <button wire:click="closeModal" class="text-xl leading-none hover:opacity-70">×</button>
            </div>
            <form wire:submit="updateAlumni" class="p-7 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                    @if($editAlumniCurrentPhoto)
                    <div class="mb-2.5 flex items-center gap-3">
                        <img src="{{ $this->getPhotoUrl($editAlumniCurrentPhoto) }}" class="w-12 h-12 rounded-lg object-cover border-2 border-[#7A3F91]/20">
                        <span class="text-xs text-gray-500">Current photo</span>
                    </div>
                    @endif
                    <div class="border-2 border-dashed border-[#7A3F91]/30 rounded-lg p-4 text-center cursor-pointer hover:border-[#7A3F91] transition"
                         onclick="document.getElementById('editPhotoInput').click()">
                        <i class="fas fa-cloud-arrow-up text-xl text-[#7A3F91]/40 block mb-1"></i>
                        <p class="text-xs text-gray-500">Click to upload new photo · max 5 MB</p>
                        <input type="file" id="editPhotoInput" wire:model="editPhoto" accept="image/*" class="hidden">
                        @if($editPhoto)<p class="text-xs text-emerald-600 font-semibold mt-1.5">✓ New photo selected</p>@endif
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Full Name <span class="text-red-500">*</span></label>
                    <input wire:model="editName" type="text"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editName') border-red-400 @enderror text-gray-800">
                    @error('editName')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Student ID <span class="text-red-500">*</span></label>
                    <input wire:model="editStudentId" type="text" maxlength="8" inputmode="numeric"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm font-mono focus:border-[#7A3F91] focus:outline-none @error('editStudentId') border-red-400 @enderror text-gray-800">
                    @error('editStudentId')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Course <span class="text-red-500">*</span></label>
                        <select wire:model="editCourseCode"
                                class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editCourseCode') border-red-400 @enderror text-gray-800">
                            <option value="">Select</option>
                            @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                        </select>
                        @error('editCourseCode')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Year <span class="text-red-500">*</span></label>
                        <input wire:model="editBatch" type="number" min="2000" max="{{ date('Y') }}"
                               class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editBatch') border-red-400 @enderror text-gray-800">
                        @error('editBatch')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="flex gap-3 pt-1">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                            class="flex-1 px-4 py-2.5 bg-[#7A3F91] text-white rounded-lg text-sm font-semibold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="updateAlumni"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
                        <span wire:loading.remove wire:target="updateAlumni"><i class="fas fa-save"></i> Save Changes</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Register Organizer -->
    @if($activeModal === 'registerOrganizer')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto scrollbar-thin">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-users-gear"></i> Register Organizer</h2>
                <button wire:click="closeModal" class="text-xl leading-none hover:opacity-70">×</button>
            </div>
            <form wire:submit="registerOrganizer" class="p-7 space-y-4">
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    <p class="font-bold text-red-800 text-xs mb-1">Please fix the following errors:</p>
                    <ul class="text-red-700 text-xs space-y-0.5">@foreach($errors->all() as $e)<li>• {{ $e }}</li>@endforeach</ul>
                </div>
                @endif
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                    <div class="border-2 border-dashed border-[#7A3F91]/30 rounded-lg p-4 text-center cursor-pointer hover:border-[#7A3F91] transition"
                         onclick="document.getElementById('orgPhotoInput').click()">
                        <i class="fas fa-cloud-arrow-up text-xl text-[#7A3F91]/40 block mb-1"></i>
                        <p class="text-xs text-gray-500">Click to upload · max 5 MB</p>
                        <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                        @if($orgPhoto)<p class="text-xs text-emerald-600 font-semibold mt-1.5">✓ Photo selected</p>@endif
                    </div>
                    @error('orgPhoto')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Full Name <span class="text-red-500">*</span></label>
                    <input wire:model="orgName" type="text" placeholder="Juan dela Cruz"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('orgName') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('orgName')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">ID Number <span class="text-red-500">*</span></label>
                    <input wire:model="orgIdNumber" type="text"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('orgIdNumber') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('orgIdNumber')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                    <input wire:model="orgEmail" type="email" placeholder="organizer@example.com"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('orgEmail') border-red-400 bg-red-50 @enderror text-gray-800">
                    @error('orgEmail')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Department <span class="text-red-500">*</span></label>
                    <select wire:model="orgDept"
                            class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('orgDept') border-red-400 bg-red-50 @enderror text-gray-800">
                        <option value="">Select Department</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
                    </select>
                    @error('orgDept')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div class="flex gap-3 pt-1">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                            class="flex-1 px-4 py-2.5 bg-[#7A3F91] text-white rounded-lg text-sm font-semibold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner fa-spin"></i> Registering…</span>
                        <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear"></i> Register</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Edit Organizer -->
    @if($activeModal === 'editOrganizer')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto scrollbar-thin">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-pen-to-square"></i> Edit Organizer</h2>
                <button wire:click="closeModal" class="text-xl leading-none hover:opacity-70">×</button>
            </div>
            <form wire:submit="updateOrganizer" class="p-7 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                    @if($editOrgCurrentPhoto)
                    <div class="mb-2.5 flex items-center gap-3">
                        <img src="{{ $this->getPhotoUrl($editOrgCurrentPhoto) }}" class="w-12 h-12 rounded-lg object-cover border-2 border-[#7A3F91]/20">
                        <span class="text-xs text-gray-500">Current photo</span>
                    </div>
                    @endif
                    <div class="border-2 border-dashed border-[#7A3F91]/30 rounded-lg p-4 text-center cursor-pointer hover:border-[#7A3F91] transition"
                         onclick="document.getElementById('editOrgPhotoInput').click()">
                        <i class="fas fa-cloud-arrow-up text-xl text-[#7A3F91]/40 block mb-1"></i>
                        <p class="text-xs text-gray-500">Click to upload new photo · max 5 MB</p>
                        <input type="file" id="editOrgPhotoInput" wire:model="editOrgPhoto" accept="image/*" class="hidden">
                        @if($editOrgPhoto)<p class="text-xs text-emerald-600 font-semibold mt-1.5">✓ New photo selected</p>@endif
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Full Name <span class="text-red-500">*</span></label>
                    <input wire:model="editOrgName" type="text"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editOrgName') border-red-400 @enderror text-gray-800">
                    @error('editOrgName')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">ID Number <span class="text-red-500">*</span></label>
                    <input wire:model="editOrgIdNumber" type="text"
                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editOrgIdNumber') border-red-400 @enderror text-gray-800">
                    @error('editOrgIdNumber')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Department <span class="text-red-500">*</span></label>
                    <select wire:model="editOrgDept"
                            class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-sm focus:border-[#7A3F91] focus:outline-none @error('editOrgDept') border-red-400 @enderror text-gray-800">
                        <option value="">Select</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
                    </select>
                    @error('editOrgDept')<span class="text-red-600 text-xs mt-1 block">{{ $message }}</span>@enderror
                </div>
                <div class="flex gap-3 pt-1">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                            class="flex-1 px-4 py-2.5 bg-[#7A3F91] text-white rounded-lg text-sm font-semibold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="updateOrganizer"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
                        <span wire:loading.remove wire:target="updateOrganizer"><i class="fas fa-save"></i> Save Changes</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Delete Record -->
    @if($activeModal === 'deleteConfirm')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm">
            <div class="p-7 text-center">
                <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-trash-alt text-xl text-red-500"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-1.5">Delete Record?</h3>
                <p class="text-gray-500 text-sm mb-1">You are about to permanently delete</p>
                <p class="text-gray-900 font-bold text-sm mb-3">{{ $deleteName }}</p>
                <p class="text-red-500 text-xs font-semibold">This action cannot be undone.</p>
            </div>
            <div class="flex gap-3 px-7 pb-7">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="deleteRecord" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                        class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteRecord"><i class="fas fa-spinner fa-spin"></i> Deleting…</span>
                    <span wire:loading.remove wire:target="deleteRecord"><i class="fas fa-trash"></i> Confirm Delete</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <!-- Delete Course -->
    @if($activeModal === 'deleteCourseConfirm')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.openModal('manageCourses')">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm">
            <div class="p-7 text-center">
                <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-trash-alt text-xl text-red-500"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-1.5">Delete Course?</h3>
                <p class="text-gray-500 text-sm mb-1">Are you sure you want to delete</p>
                <p class="text-gray-900 font-bold text-sm mb-3">{{ $deleteCourseName }}</p>
                <p class="text-red-500 text-xs font-semibold">This action cannot be undone.</p>
            </div>
            <div class="flex gap-3 px-7 pb-7">
                <button type="button" wire:click="openModal('manageCourses')"
                        class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="deleteCourse" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                        class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner fa-spin"></i> Deleting…</span>
                    <span wire:loading.remove wire:target="deleteCourse"><i class="fas fa-trash"></i> Confirm Delete</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <!-- Import -->
    @if($activeModal === 'importModal')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-file-import"></i> Import Records</h2>
                <button wire:click="closeModal" class="text-xl leading-none hover:opacity-70">×</button>
            </div>
            <form action="{{ route('alumni.import') }}" method="POST" enctype="multipart/form-data" class="p-7 space-y-4">
                @csrf
                <div class="border-2 border-dashed border-[#7A3F91]/30 bg-purple-50 rounded-lg p-7 text-center cursor-pointer hover:border-[#7A3F91] transition"
                     onclick="document.getElementById('importFile').click()">
                    <i class="fas fa-cloud-arrow-up text-3xl text-[#7A3F91]/40 block mb-2"></i>
                    <p class="font-bold text-gray-700 text-sm">Click to upload or drag & drop</p>
                    <p class="text-xs text-gray-400 mt-1">CSV, XLS, XLSX — max 10 MB</p>
                    <input type="file" id="importFile" name="file" accept=".csv,.xlsx,.xls" class="hidden"
                           onchange="document.getElementById('importFileName').textContent = this.files[0]?.name || ''">
                    <p id="importFileName" class="text-xs font-bold text-[#7A3F91] mt-2"></p>
                </div>
                <div class="bg-blue-50 border border-blue-100 rounded-lg p-3">
                    <p class="text-xs font-bold text-blue-800 mb-1">📋 Required columns:</p>
                    <code class="text-xs font-mono text-blue-600">student_id, name, email, course_code, batch</code>
                </div>
                <div class="flex gap-3">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 px-4 py-2.5 border-2 border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit"
                            class="flex-1 px-4 py-2.5 bg-[#7A3F91] text-white rounded-lg text-sm font-semibold hover:bg-[#6a3680] transition flex items-center justify-center gap-2">
                        <i class="fas fa-file-import"></i> Import
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Manage Courses -->
    @if($activeModal === 'manageCourses')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         x-data @keydown.escape.window="$wire.closeModal(); $wire.resetCourseForm()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col overflow-hidden">
            <div class="flex items-center justify-between px-7 py-4 bg-[#7A3F91] text-white rounded-t-xl shrink-0">
                <h2 class="text-sm font-bold flex items-center gap-2"><i class="fas fa-book"></i> Manage Courses</h2>
                <button wire:click="closeModal(); resetCourseForm()" class="text-xl leading-none hover:opacity-70">×</button>
            </div>

            <div class="overflow-y-auto flex-1 p-7 scrollbar-thin">
                @if($courseAlert)
                <div class="mb-5 flex items-center justify-between p-3 rounded-lg text-xs font-semibold
                            {{ $courseAlertType==='success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' }}">
                    <span>{{ $courseAlert }}</span>
                    <button wire:click="$set('courseAlert','')" class="opacity-50 hover:opacity-100 ml-3"><i class="fas fa-times"></i></button>
                </div>
                @endif

                <!-- Add / edit form -->
                <div class="bg-[#7A3F91]/5 border-2 border-[#7A3F91]/20 rounded-lg p-5 mb-6">
                    <h3 class="text-xs font-bold text-[#7A3F91] uppercase tracking-wider mb-3 flex items-center gap-2">
                        <i class="fas {{ $editingCourseId ? 'fa-pen-to-square' : 'fa-plus' }}"></i>
                        {{ $editingCourseId ? 'Edit Course' : 'Add New Course' }}
                    </h3>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1.5">Code <span class="text-red-500">*</span></label>
                            <input wire:model="courseCode" type="text" placeholder="e.g. BSCS"
                                   class="w-full px-3 py-2 border-2 border-[#7A3F91]/20 rounded-lg text-sm focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none uppercase text-gray-800">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1.5">Course Name <span class="text-red-500">*</span></label>
                            <input wire:model="courseName" type="text" placeholder="Bachelor of Science in…"
                                   class="w-full px-3 py-2 border-2 border-[#7A3F91]/20 rounded-lg text-sm focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/20 focus:outline-none text-gray-800">
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="saveCourse" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed"
                                class="px-5 py-2 bg-[#7A3F91] text-white rounded-lg text-xs font-semibold hover:bg-[#6a3680] transition flex items-center gap-2">
                            <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
                            <span wire:loading.remove wire:target="saveCourse">
                                <i class="fas {{ $editingCourseId ? 'fa-save' : 'fa-plus' }}"></i>
                                {{ $editingCourseId ? 'Update Course' : 'Add Course' }}
                            </span>
                        </button>
                        @if($editingCourseId)
                        <button wire:click="resetCourseForm"
                                class="px-5 py-2 border-2 border-gray-300 text-gray-600 rounded-lg text-xs font-semibold hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        @endif
                    </div>
                </div>

                <!-- List -->
                <div>
                    <h3 class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <i class="fas fa-list text-[#7A3F91]"></i>
                        All Courses <span class="text-gray-400 font-normal">({{ count($coursesList) }})</span>
                    </h3>
                    <div class="space-y-2 max-h-60 overflow-y-auto scrollbar-thin pr-1">
                        @forelse($coursesList as $course)
                        <div class="flex items-center justify-between px-4 py-3 rounded-lg border-2
                                    {{ $editingCourseId===$course['id'] ? 'border-[#7A3F91]/50 bg-[#7A3F91]/5' : 'border-gray-100 bg-gray-50' }}">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <span class="px-2.5 py-1 bg-[#7A3F91] text-white text-xs font-bold rounded shrink-0">{{ $course['code'] }}</span>
                                <span class="text-gray-800 text-sm font-medium truncate">{{ $course['name'] }}</span>
                            </div>
                            <div class="flex gap-1.5 shrink-0 ml-3">
                                <button wire:click="openEditCourse({{ $course['id'] }})"
                                        class="p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition">
                                    <i class="fas fa-pen-to-square text-xs"></i>
                                </button>
                                <button wire:click="confirmDeleteCourse({{ $course['id'] }})"
                                        class="p-2 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-10">
                            <i class="fas fa-inbox text-3xl text-gray-200 block mb-2"></i>
                            <p class="text-sm font-medium text-gray-400">No courses yet</p>
                            <p class="text-xs text-gray-400">Add one using the form above</p>
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="px-7 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
                <button wire:click="closeModal(); resetCourseForm()"
                        class="w-full px-4 py-2.5 bg-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-300 transition">
                    Close
                </button>
            </div>
        </div>
    </div>
    @endif

</div>