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
    public $importFile = null;
    public bool $importingFile = false;
    public string $importStatus = '';
    public int $importProgress = 0;
    public int $importTotal = 0;
    public int $importSuccessCount = 0;
    public int $importFailCount = 0;
    public array $importErrors = [];

    // Profile Modal
    public ?int $viewingProfileId = null;
    public string $viewingProfileType = 'alumni';
    public $viewingProfile = null;
    public $updatingProfilePhoto = null;
    public bool $updatingProfile = false;

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
    
    public function openModal(string $modal): void { 
        $this->activeModal = $modal;
        if ($modal === 'importModal') {
            $this->resetImportState();
        }
    }
    
    public function closeModal(): void { 
        $this->activeModal = '';
        $this->resetImportState();
        $this->viewingProfileId = null;
        $this->updatingProfilePhoto = null;
    }

    public function resetImportState(): void {
        $this->importFile = null;
        $this->importingFile = false;
        $this->importStatus = '';
        $this->importProgress = 0;
        $this->importTotal = 0;
        $this->importSuccessCount = 0;
        $this->importFailCount = 0;
        $this->importErrors = [];
    }

    public function cancelImport(): void {
        $this->resetImportState();
        $this->activeModal = '';
        $this->flash('info', 'Import cancelled. No changes were made.');
    }

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

    private function validateName(string $name): bool
    {
        return preg_match('/^[a-zA-Z\s\-\.\']+$/', $name) === 1;
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
            if (!$this->validateName(trim($this->regFirstName))) {
                throw new \Exception('First name must contain only letters, spaces, hyphens, or apostrophes');
            }
            if (!$this->validateName(trim($this->regLastName))) {
                throw new \Exception('Last name must contain only letters, spaces, hyphens, or apostrophes');
            }
            if (trim($this->regSuffix) !== '' && !preg_match('/^[a-zA-Z\s\.\,]+$/', $this->regSuffix)) {
                throw new \Exception('Suffix must contain only letters, spaces, periods, or commas');
            }

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
                'profile_photo' => $photoPath,
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
        } catch (\Exception $e) {
            Log::error('Alumni creation failed: ' . $e->getMessage());
            $this->alumniErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->registeringAlumni = false;
        }
    }

    private function storeAlumniPhoto($photo): ?string
    {
        if (!$photo) return null;
        
        try {
            $uuid = Str::uuid();
            $extension = $photo->getClientOriginalExtension();
            $filename = "alumni-{$uuid}.{$extension}";
            $path = $photo->storeAs('alumni-photos', $filename, 'public');
            
            if ($path === false) {
                Log::error('Photo storage failed');
                return null;
            }
            
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
            if (!$this->validateName(trim($this->orgFirstName))) {
                throw new \Exception('First name must contain only letters, spaces, hyphens, or apostrophes');
            }
            if (!$this->validateName(trim($this->orgLastName))) {
                throw new \Exception('Last name must contain only letters, spaces, hyphens, or apostrophes');
            }
            if (trim($this->orgSuffix) !== '' && !preg_match('/^[a-zA-Z\s\.\,]+$/', $this->orgSuffix)) {
                throw new \Exception('Suffix must contain only letters, spaces, periods, or commas');
            }

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
                'profile_photo' => $photoPath,
                'status'        => 'ACTIVE'
            ]);
            
            Log::info("Organizer created: {$organizer->name}");
            
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
        } catch (\Exception $e) {
            Log::error('Organizer creation failed: ' . $e->getMessage());
            $this->organizerErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->registeringOrganizer = false;
        }
    }

    private function storeOrganizerPhoto($photo): ?string
    {
        if (!$photo) return null;
        
        try {
            $uuid = Str::uuid();
            $extension = $photo->getClientOriginalExtension();
            $filename = "organizer-{$uuid}.{$extension}";
            $path = $photo->storeAs('organizers', $filename, 'public');
            
            if ($path === false) {
                Log::error('Organizer photo storage failed');
                return null;
            }
            
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
        $this->savingCourse = false;
    }

    public function saveCourse(): void
    {
        $this->savingCourse = true;
        $code = strtoupper(trim($this->courseCode));
        $name = trim($this->courseName);
        
        if (!$code || !$name) {
            $this->courseAlert = 'Code and Name are required.';
            $this->courseAlertType = 'error';
            $this->savingCourse = false;
            return;
        }
        
        try {
            if ($this->editingCourseId) {
                Course::findOrFail($this->editingCourseId)->update(['code' => $code, 'name' => $name]);
                $this->courseAlert = '✓ Course updated successfully!';
            } else {
                Course::create(['code' => $code, 'name' => $name]);
                $this->courseAlert = '✓ Course added successfully!';
            }
            $this->courseAlertType = 'success';
            $this->coursesList = Course::all()->toArray();
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
            $this->courseAlert = '✓ Course deleted successfully!';
            $this->courseAlertType = 'success';
            $this->coursesList = Course::all()->toArray();
            $this->deleteCourseId = null;
            $this->deleteCourseName = '';
            $this->activeModal = 'manageCourses';
        } catch (\Exception $e) {
            $this->courseAlert = 'Failed to delete course.';
            $this->courseAlertType = 'error';
            $this->activeModal = 'manageCourses';
        } finally {
            $this->deletingCourse = false;
        }
    }

    public function viewProfile(int $id, string $type): void
    {
        try {
            $this->viewingProfileType = $type;
            if ($type === 'alumni') {
                $this->viewingProfile = Alumni::findOrFail($id)->toArray();
            } else {
                $this->viewingProfile = Organizer::findOrFail($id)->toArray();
            }
            $this->viewingProfileId = $id;
            $this->activeModal = 'viewProfile';
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to load profile');
        }
    }

    public function updateProfilePhoto(): void
    {
        if (!$this->updatingProfilePhoto || !$this->viewingProfileId) {
            return;
        }

        $this->updatingProfile = true;

        try {
            if ($this->viewingProfileType === 'alumni') {
                $alumni = Alumni::findOrFail($this->viewingProfileId);
                
                if ($alumni->profile_photo && strpos($alumni->profile_photo, 'default.png') === false) {
                    Storage::disk('public')->delete($alumni->profile_photo);
                }

                $photoPath = $this->storeAlumniPhoto($this->updatingProfilePhoto);
                $alumni->update(['profile_photo' => $photoPath]);
                $this->viewingProfile['profile_photo'] = $photoPath;
            } else {
                $organizer = Organizer::findOrFail($this->viewingProfileId);
                
                if ($organizer->profile_photo && strpos($organizer->profile_photo, 'default.png') === false) {
                    Storage::disk('public')->delete($organizer->profile_photo);
                }

                $photoPath = $this->storeOrganizerPhoto($this->updatingProfilePhoto);
                $organizer->update(['profile_photo' => $photoPath]);
                $this->viewingProfile['profile_photo'] = $photoPath;
            }

            $this->updatingProfilePhoto = null;
            $this->flash('success', 'Profile photo updated successfully!');
        } catch (\Exception $e) {
            Log::error('Profile photo update failed: ' . $e->getMessage());
            $this->flash('error', 'Failed to update profile photo');
        } finally {
            $this->updatingProfile = false;
        }
    }

    public function processImportFile(): void
    {
        // Show importing state IMMEDIATELY
        $this->importingFile = true;
        $this->importStatus = 'IMPORTING...';
        $this->importProgress = 0;
        $this->importSuccessCount = 0;
        $this->importFailCount = 0;
        $this->importErrors = [];
        
        try {
            if (!$this->importFile) {
                throw new \Exception('No file selected');
            }

            $file = $this->importFile;
            $filename = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());

            if ($extension === 'xlsx' || $extension === 'xls') {
                $csv = $this->parseExcelFile($file->getRealPath());
            } else if ($extension === 'csv') {
                $csv = array_map('str_getcsv', file($file->getRealPath()));
            } else {
                throw new \Exception('File must be CSV or Excel (.xlsx, .xls)');
            }
            
            if (count($csv) < 2) {
                throw new \Exception('File is empty. Please add data and try again.');
            }

            $header = array_map('strtolower', $csv[0]);
            $requiredFields = ['name', 'student_id', 'course_code', 'year', 'email'];
            
            foreach ($requiredFields as $field) {
                if (!in_array($field, $header)) {
                    throw new \Exception("Missing required column: {$field}");
                }
            }

            $this->importTotal = count($csv) - 1;

            for ($i = 1; $i < count($csv); $i++) {
                if (count($csv[$i]) < count($header)) {
                    continue;
                }

                $this->importProgress = $i;
                $this->importStatus = "IMPORTING... ({$i}/{$this->importTotal})";

                $row = array_combine($header, array_slice($csv[$i], 0, count($header)));
                
                try {
                    if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', trim($row['name']))) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Name contains invalid characters";
                        continue;
                    }

                    if (Alumni::where('email', trim($row['email']))->exists()) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Email '{$row['email']}' already exists";
                        continue;
                    }
                    if (User::where('email', trim($row['email']))->exists()) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Email '{$row['email']}' already exists in system";
                        continue;
                    }

                    $paddedStudentId = str_pad(trim($row['student_id']), 8, '0', STR_PAD_LEFT);
                    
                    if (!preg_match('/^\d{8}$/', $paddedStudentId)) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Invalid student ID format";
                        continue;
                    }

                    if (Alumni::where('student_id', $paddedStudentId)->exists()) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Student ID '{$paddedStudentId}' already exists";
                        continue;
                    }

                    $course = Course::where('code', strtoupper(trim($row['course_code'])))->first();
                    if (!$course) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Course '{$row['course_code']}' not found";
                        continue;
                    }

                    if (!filter_var(trim($row['email']), FILTER_VALIDATE_EMAIL)) {
                        $this->importFailCount++;
                        $this->importErrors[] = "Row {$i}: Invalid email format";
                        continue;
                    }

                    $alumni = Alumni::create([
                        'name'          => trim($row['name']),
                        'student_id'    => $paddedStudentId,
                        'email'         => trim($row['email']),
                        'course_code'   => strtoupper(trim($row['course_code'])),
                        'course_name'   => $course->name,
                        'batch'         => (int)$row['year'],
                        'status'        => 'VERIFIED',
                    ]);

                    $tempPassword = Str::random(10);
                    User::create([
                        'name'     => trim($row['name']),
                        'email'    => trim($row['email']),
                        'password' => Hash::make($tempPassword),
                        'role'     => 'alumni'
                    ]);

                    try {
                        Mail::to($alumni->email)->send(new \App\Mail\AlumniRegistered($alumni, $tempPassword));
                    } catch (\Exception $e) {
                        Log::warning("Email not sent to {$alumni->email}: " . $e->getMessage());
                    }

                    $this->importSuccessCount++;
                } catch (\Exception $e) {
                    $this->importFailCount++;
                    $this->importErrors[] = "Row {$i}: " . $e->getMessage();
                }
            }

            $this->importStatus = 'Import completed!';
            $this->coursesList = Course::all()->toArray();
            
            $this->importFile = null;
            $this->activeModal = '';
            $this->resetAlumniFilters();

            if ($this->importSuccessCount > 0) {
                $message = "✓ Imported {$this->importSuccessCount} alumni";
                if ($this->importFailCount > 0) {
                    $message .= ", {$this->importFailCount} failed";
                }
                $this->flash('success', $message);
            } else {
                $this->flash('error', "Failed to import - Check your file for duplicate or invalid data");
            }

        } catch (\Exception $e) {
            Log::error('Import error: ' . $e->getMessage());
            $this->importStatus = 'Error: ' . $e->getMessage();
            $this->importingFile = false;
        }
    }

    private function parseExcelFile($filepath): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filepath);
            $spreadsheet = $reader->load($filepath);
            $sheet = $spreadsheet->getActiveSheet();
            
            $data = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rowData = [];
                foreach ($row->getCellIterator() as $cell) {
                    $rowData[] = $cell->getValue();
                }
                $data[] = $rowData;
            }
            
            return $data;
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse Excel file: ' . $e->getMessage());
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

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:90vh;">

    <style>
        :root {
            --primary-color: #7a3f91;
            --primary-dark: #6a3580;
            --primary-light: #8a4fa1;
        }

        * {
            scroll-behavior: smooth;
        }

        .scrollbar-custom::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .scrollbar-custom::-webkit-scrollbar-track {
            background: transparent;
        }

        .scrollbar-custom::-webkit-scrollbar-thumb {
            background: rgba(122, 63, 145, 0.3);
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .scrollbar-custom::-webkit-scrollbar-thumb:hover {
            background: rgba(122, 63, 145, 0.6);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(10px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        @keyframes progressPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(122, 63, 145, 0.4);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(122, 63, 145, 0);
            }
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes dots {
            0%, 20% {
                content: '.';
            }
            40% {
                content: '..';
            }
            60%, 100% {
                content: '...';
            }
        }

        .modal-animate {
            animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .flash-animate {
            animation: slideInRight 0.4s ease-out;
        }

        .progress-animate {
            animation: progressPulse 2s infinite;
        }

        .spin-icon {
            animation: spin 1s linear infinite;
        }

        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(3, end) infinite;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7a3f91, #6a3580);
            color: white;
            border: none;
        }

        .btn-primary:disabled {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            cursor: not-allowed;
        }

        .input-focus:focus {
            border-color: #7a3f91 !important;
            box-shadow: 0 0 0 3px rgba(122, 63, 145, 0.1) !important;
            outline: none !important;
        }

        .table-row-hover {
            transition: all 0.2s ease;
        }

        .table-row-hover:hover {
            background-color: rgba(122, 63, 145, 0.05);
        }

        .course-item {
            background: white;
        }

        .status-badge {
            font-weight: 600;
            letter-spacing: 0.3px;
        }
    </style>

    <!-- FLASH MESSAGE -->
    @if($showFlash)
    <div x-data="{ visible: true }"
         x-effect="if (visible) { setTimeout(() => { visible = false; $wire.showFlash = false }, 5000) }"
         x-show="visible"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-x-6"
         x-transition:enter-end="opacity-100 translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-x-0"
         x-transition:leave-end="opacity-0 translate-x-6"
         class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-lg shadow-lg max-w-sm border backdrop-blur-sm flash-animate"
         :class="'{{ $flashType }}' === 'success'
             ? 'bg-emerald-50/95 border-emerald-200 text-emerald-800'
             : '{{ $flashType }}' === 'info'
             ? 'bg-blue-50/95 border-blue-200 text-blue-800'
             : 'bg-red-50/95 border-red-200 text-red-800'">
        <i class="fas mt-0.5 text-lg"
           :class="'{{ $flashType }}' === 'success' ? 'fa-check-circle' : '{{ $flashType }}' === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle'"></i>
        <div class="flex-1 min-w-0">
            <div class="font-semibold text-base">
                {{ $flashType === 'success' ? '✓ Success!' : ($flashType === 'info' ? 'ℹ Info' : '✕ Error') }}
            </div>
            <div class="text-sm mt-1 leading-snug opacity-90">{{ $flashMessage }}</div>
        </div>
        <button @click="visible=false; $wire.showFlash=false" class="opacity-50 hover:opacity-100 shrink-0 mt-0.5 transition">
            <i class="fas fa-times text-base"></i>
        </button>
    </div>
    @endif

    <!-- MAIN CONTENT -->
    <div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

        <!-- HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0" style="animation: slideInDown 0.5s ease-out;">
            <div>
                <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">
                    <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                        <i class="fas fa-users text-xl"></i>
                    </div>
                    Alumni & Organizers
                </h1>
                <p class="text-slate-600 text-sm mt-2 ml-0.5">Manage alumni and organizer records efficiently</p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                @if($this->activeTab === 'alumni')
                    <button wire:click="openModal('registerAlumni')"
                            class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                        <i class="fas fa-user-plus"></i> Register Alumni
                    </button>
                    <button wire:click="openModal('importModal')"
                            class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                        <i class="fas fa-file-import"></i> Import
                    </button>
                    <button wire:click="openModal('manageCourses')"
                            class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                        <i class="fas fa-sliders"></i> Manage Courses
                    </button>
                @elseif($this->activeTab === 'organizers')
                    <button wire:click="openModal('registerOrganizer')"
                            class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                        <i class="fas fa-users-gear"></i> Register Organizer
                    </button>
                    <button wire:click="openModal('manageCourses')"
                            class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                        <i class="fas fa-sliders"></i> Manage Courses
                    </button>
                @endif
            </div>
        </div>

        <!-- TABS -->
        <div class="flex gap-2 mb-4 shrink-0">
            <button wire:click="switchTab('alumni')"
                    class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm
                           {{ $this->activeTab==='alumni' ? 'bg-white text-slate-800 shadow-sm' : 'bg-white/50 text-slate-600 hover:bg-white/70' }}">
                <i class="fas fa-graduation-cap"></i> Alumni
            </button>
            <button wire:click="switchTab('organizers')"
                    class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm
                           {{ $this->activeTab==='organizers' ? 'bg-white text-slate-800 shadow-sm' : 'bg-white/50 text-slate-600 hover:bg-white/70' }}">
                <i class="fas fa-users-gear"></i> Organizers
            </button>
        </div>

        <!-- TABLE PANEL -->
        <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">

            {{-- ALUMNI TAB --}}
            @if($this->activeTab === 'alumni')

            <!-- Filter bar -->
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input wire:model.live.debounce.150ms="alumniSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                </div>
                <select wire:model.live="alumniBatch"
                        class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Years</option>
                    @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse"
                        class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort"
                        class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters"
                        class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto scrollbar-custom">
                <table class="w-full">
                    <thead class="btn-primary text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Student ID</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Course</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Year</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($this->alumniRecords as $item)
                        <tr class="table-row-hover">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-10 h-10 rounded-lg object-cover shrink-0">
                                    <span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->student_id }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">{{ $item->course_code }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->batch }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-slate-700 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @php $sc = match($item->status) { 'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600' }; @endphp
                                <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold status-badge {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button wire:click="viewProfile({{ $item->id }}, 'alumni')"
                                        class="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-16 text-center">
                                <i class="fas fa-users text-5xl text-slate-200 block mb-4"></i>
                                <p class="font-semibold text-slate-400 text-base">No alumni found</p>
                                <p class="text-sm text-slate-400 mt-1">Try adjusting your filters</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-slate-600 text-sm">
                        @php $total=$this->alumniRecords->total(); $pp=$this->alumniRecords->perPage(); $cp=$this->alumniRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span>
                    </p>
                    <div class="flex gap-2 items-center">
                        @if($this->alumniRecords->onFirstPage())
                            <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                        @endif
                        <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                        @if($this->alumniRecords->hasMorePages())
                            <button wire:click="nextPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                        @else
                            <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- ORGANIZERS TAB --}}
            @if($this->activeTab === 'organizers')

            <!-- Filter bar -->
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input wire:model.live.debounce.150ms="orgSearch" type="text" placeholder="Search name, ID, email…"
                           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                </div>
                <select wire:model.live="orgDepartment"
                        class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Departments</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort"
                        class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters"
                        class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto scrollbar-custom">
                <table class="w-full">
                    <thead class="btn-primary text-white sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Teacher ID</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Department</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($this->organizerRecords as $item)
                        <tr class="table-row-hover">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                         class="w-10 h-10 rounded-lg object-cover shrink-0">
                                    <span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->id_number }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-slate-700 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">{{ $item->department }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @php $sc = match($item->status) { 'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600' }; @endphp
                                <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold status-badge {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button wire:click="viewProfile({{ $item->id }}, 'organizer')"
                                        class="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <i class="fas fa-users-gear text-5xl text-slate-200 block mb-4"></i>
                                <p class="font-semibold text-slate-400 text-base">No organizers found</p>
                                <p class="text-sm text-slate-400 mt-1">Register an organizer to get started</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                <div class="flex items-center justify-between">
                    <p class="text-slate-600 text-sm">
                        @php $total=$this->organizerRecords->total(); $pp=$this->organizerRecords->perPage(); $cp=$this->organizerRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                        Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span>
                    </p>
                    <div class="flex gap-2 items-center">
                        @if($this->organizerRecords->onFirstPage())
                            <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                        @endif
                        <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                        @if($this->organizerRecords->hasMorePages())
                            <button wire:click="nextPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                        @else
                            <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

        </div>

        <!-- ========== MODALS ========== -->

        <!-- REGISTER ALUMNI MODAL -->
        @if($activeModal === 'registerAlumni')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
                <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-user-plus text-2xl"></i> Register Alumni</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
                </div>

                @if(count($alumniErrors) > 0)
                <div class="bg-red-50 border-b border-red-200 px-8 py-5">
                    <p class="font-semibold text-red-800 text-sm mb-3">⚠️ Please fix the following errors:</p>
                    <ul class="text-red-700 text-sm space-y-2">
                        @foreach($alumniErrors as $field => $messages)
                            @foreach($messages as $message)
                                <li class="flex items-start gap-2">
                                    <span class="text-red-500 mt-0.5">•</span>
                                    <span>{{ $message }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
                @endif

                <form wire:submit="registerAlumni" class="p-8 space-y-6">

                    <!-- PHOTO UPLOAD -->
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                        <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                             onclick="document.getElementById('regPhotoInput').click()">
                            @if($regPhoto)
                                <img src="{{ $regPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md">
                                <p class="text-sm text-emerald-600 font-semibold">✓ Photo Selected</p>
                                <p class="text-xs text-slate-500 mt-2">Click to change photo</p>
                            @else
                                <i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i>
                                <p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p>
                                <p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                            @endif
                            <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                        </div>
                    </div>

                    <!-- FULL NAME -->
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
                                <input wire:model="regMiddleInitial" type="text" placeholder="Middle Initial" maxlength="5"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial</p>
                            </div>
                            <div>
                                <input wire:model="regSuffix" type="text" placeholder="Suffix (Jr., Sr.)" maxlength="10"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix</p>
                            </div>
                        </div>
                        @if($regFirstName || $regLastName)
                        <p class="text-sm text-purple-700 font-semibold mt-3 pl-1">
                            Preview: {{ trim("{$regFirstName} {$regMiddleInitial} {$regLastName}" . ($regSuffix ? ' '.$regSuffix : '')) }}
                        </p>
                        @endif
                    </div>

                    <!-- STUDENT ID & EMAIL -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">
                                Student ID <span class="text-red-500">*</span>
                                <span class="font-normal text-slate-600 text-xs">(up to 8 digits)</span>
                            </label>
                            <div class="relative">
                                <input wire:model="regStudentId" type="text" placeholder="e.g. 12345"
                                       maxlength="8" inputmode="numeric" pattern="\d*"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">
                                @if($regStudentId && strlen($regStudentId) < 8)
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500 font-mono">
                                    → {{ str_pad($regStudentId, 8, '0', STR_PAD_LEFT) }}
                                </span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">Email Address <span class="text-red-500">*</span></label>
                            <input wire:model="regEmail" type="email" placeholder="student@example.com"
                                   class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        </div>
                    </div>

                    <!-- COURSE & YEAR -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">Course <span class="text-red-500">*</span></label>
                            <select wire:model="regCourseCode"
                                    class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <option value="">Select Course</option>
                                @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">Year <span class="text-red-500">*</span></label>
                            <input wire:model="regYear" type="number" placeholder="{{ date('Y') }}" min="2000" max="{{ date('Y') }}"
                                   class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        </div>
                    </div>

                    <!-- BUTTONS -->
                    <div class="flex gap-4 pt-3">
                        <button type="button" wire:click="closeModal"
                                class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="registerAlumni" wire:loading.class="opacity-50 cursor-not-allowed"
                                class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner spin-icon"></i> <span class="loading-dots">Registering</span></span>
                            <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check text-base"></i> Register Alumni</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

        <!-- REGISTER ORGANIZER MODAL -->
        @if($activeModal === 'registerOrganizer')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
                <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-users-gear text-2xl"></i> Register Organizer</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
                </div>

                @if(count($organizerErrors) > 0)
                <div class="bg-red-50 border-b border-red-200 px-8 py-5">
                    <p class="font-semibold text-red-800 text-sm mb-3">⚠️ Please fix the following errors:</p>
                    <ul class="text-red-700 text-sm space-y-2">
                        @foreach($organizerErrors as $field => $messages)
                            @foreach($messages as $message)
                                <li class="flex items-start gap-2">
                                    <span class="text-red-500 mt-0.5">•</span>
                                    <span>{{ $message }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
                @endif

                <form wire:submit="registerOrganizer" class="p-8 space-y-6">

                    <!-- PHOTO UPLOAD -->
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                        <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                             onclick="document.getElementById('orgPhotoInput').click()">
                            @if($orgPhoto)
                                <img src="{{ $orgPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md">
                                <p class="text-sm text-emerald-600 font-semibold">✓ Photo Selected</p>
                                <p class="text-xs text-slate-500 mt-2">Click to change photo</p>
                            @else
                                <i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i>
                                <p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p>
                                <p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>
                            @endif
                            <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                        </div>
                    </div>

                    <!-- FULL NAME -->
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-3">Full Name <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <input wire:model="orgFirstName" type="text" placeholder="First Name"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p>
                            </div>
                            <div>
                                <input wire:model="orgLastName" type="text" placeholder="Last Name"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-3">
                            <div>
                                <input wire:model="orgMiddleInitial" type="text" placeholder="Middle Initial" maxlength="5"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial</p>
                            </div>
                            <div>
                                <input wire:model="orgSuffix" type="text" placeholder="Suffix (Jr., Sr.)" maxlength="10"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                                <p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix</p>
                            </div>
                        </div>
                        @if($orgFirstName || $orgLastName)
                        <p class="text-sm text-purple-700 font-semibold mt-3 pl-1">
                            Preview: {{ trim("{$orgFirstName} {$orgMiddleInitial} {$orgLastName}" . ($orgSuffix ? ' '.$orgSuffix : '')) }}
                        </p>
                        @endif
                    </div>

                    <!-- TEACHER ID & EMAIL -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">
                                Teacher ID <span class="text-red-500">*</span>
                                <span class="font-normal text-slate-600 text-xs">(up to 8 digits)</span>
                            </label>
                            <div class="relative">
                                <input wire:model="orgTeacherId" type="text" placeholder="e.g. 12345"
                                       maxlength="8" inputmode="numeric" pattern="\d*"
                                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">
                                @if($orgTeacherId && strlen($orgTeacherId) < 8)
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500 font-mono">
                                    → {{ str_pad($orgTeacherId, 8, '0', STR_PAD_LEFT) }}
                                </span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-3">Email Address <span class="text-red-500">*</span></label>
                            <input wire:model="orgEmail" type="email" placeholder="teacher@example.com"
                                   class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        </div>
                    </div>

                    <!-- DEPARTMENT -->
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-3">Department <span class="text-red-500">*</span></label>
                        <select wire:model="orgDept"
                                class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                            <option value="">Select Department</option>
                            @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
                        </select>
                    </div>

                    <!-- BUTTONS -->
                    <div class="flex gap-4 pt-3">
                        <button type="button" wire:click="closeModal"
                                class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="registerOrganizer" wire:loading.class="opacity-50 cursor-not-allowed"
                                class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner spin-icon"></i> <span class="loading-dots">Registering</span></span>
                            <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear text-base"></i> Register Organizer</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

        <!-- IMPORT MODAL -->
        @if($activeModal === 'importModal')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelImport()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl modal-animate">
                <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-file-import text-2xl"></i> Import Alumni</h2>
                    <button wire:click="cancelImport" class="text-3xl leading-none hover:opacity-70 transition">×</button>
                </div>

                <div class="p-8 space-y-6">
                    @if(!$importingFile)
                        <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg">
                            <p class="text-blue-800 text-sm"><strong>📋 Supports:</strong> CSV & Excel (.xlsx, .xls)</p>
                            <p class="text-blue-700 text-xs mt-2"><strong>Required columns:</strong> name, student_id, course_code, year, email</p>
                        </div>

                        <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                             @click="document.getElementById('importFile').click()">
                            @if($importFile)
                                <i class="fas fa-check-circle text-5xl text-emerald-500 block mb-3"></i>
                                <p class="text-sm text-emerald-600 font-semibold">✓ File Selected</p>
                                <p class="text-xs text-slate-600 mt-2">{{ $importFile->getClientOriginalName() }}</p>
                            @else
                                <i class="fas fa-file-csv text-5xl text-slate-400 block mb-3"></i>
                                <p class="text-sm text-slate-700 font-semibold">Click to Upload File</p>
                                <p class="text-xs text-slate-600 mt-2">CSV or Excel format</p>
                            @endif
                            <input type="file" id="importFile" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
                        </div>

                        <div class="flex gap-3">
                            <button type="button" wire:click="cancelImport"
                                    class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                                Cancel
                            </button>
                            <button type="button" wire:click="processImportFile" wire:loading.attr="disabled" wire:target="processImportFile"
                                    @if(!$importFile) disabled @endif
                                    class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                                <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner spin-icon"></i> <span class="loading-dots">IMPORTING</span></span>
                                <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import File</span>
                            </button>
                        </div>
                    @else
                        <!-- IMPORT PROGRESS -->
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between mb-2">
                                    <p class="text-slate-800 font-semibold text-sm flex items-center gap-2">
                                        <i class="fas fa-spinner spin-icon text-purple-600"></i>
                                        <span class="loading-dots">{{ $importStatus }}</span>
                                    </p>
                                    <p class="text-slate-600 text-xs font-mono">{{ $importProgress }}/{{ $importTotal }}</p>
                                </div>
                                <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                                    <div class="btn-primary h-full rounded-full progress-animate transition-all duration-300" style="width: {{ $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0 }}%"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-3 text-center">
                                <div class="bg-emerald-50 rounded-lg p-4 border border-emerald-200">
                                    <p class="text-emerald-600 text-xl font-bold">{{ $importSuccessCount }}</p>
                                    <p class="text-emerald-700 text-xs mt-1">Success</p>
                                </div>
                                <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                                    <p class="text-blue-600 text-xl font-bold">{{ $importProgress }}</p>
                                    <p class="text-blue-700 text-xs mt-1">Processing</p>
                                </div>
                                <div class="bg-red-50 rounded-lg p-4 border border-red-200">
                                    <p class="text-red-600 text-xl font-bold">{{ $importFailCount }}</p>
                                    <p class="text-red-700 text-xs mt-1">Failed</p>
                                </div>
                            </div>

                            @if(count($importErrors) > 0)
                            <div class="bg-red-50 rounded-lg p-4 max-h-40 overflow-y-auto scrollbar-custom border border-red-200">
                                <p class="text-red-800 font-semibold text-sm mb-2">❌ Error Details:</p>
                                <ul class="space-y-1 text-red-700 text-xs">
                                    @foreach(array_slice($importErrors, 0, 8) as $error)
                                        <li class="flex items-start gap-2">
                                            <span class="text-red-600 font-bold mt-0.5">•</span>
                                            <span>{{ $error }}</span>
                                        </li>
                                    @endforeach
                                    @if(count($importErrors) > 8)
                                        <li class="text-red-600 italic font-semibold">... and {{ count($importErrors) - 8 }} more errors</li>
                                    @endif
                                </ul>
                            </div>
                            @endif

                            <button type="button" wire:click="cancelImport"
                                    class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                                Close
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endif

        <!-- MANAGE COURSES MODAL -->
        @if($activeModal === 'manageCourses')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
                <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                    <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-sliders text-2xl"></i> Manage Courses</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
                </div>

                @if($courseAlert)
                <div x-data="{ show: true }" 
                     x-effect="if (show) { setTimeout(() => { show = false }, 4000) }"
                     x-show="show"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:leave="transition ease-in duration-200"
                     :class="'{{ $courseAlertType }}' === 'success'
                         ? 'bg-emerald-50 border-l-4 border-emerald-400'
                         : 'bg-red-50 border-l-4 border-red-400'"
                     class="p-4 mx-8 mt-6 rounded-lg">
                    <p :class="'{{ $courseAlertType }}' === 'success' ? 'text-emerald-800' : 'text-red-800'"
                       class="text-sm font-semibold">
                        <i :class="'{{ $courseAlertType }}' === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'"
                           class="mr-2"></i>
                        {{ $courseAlert }}
                    </p>
                </div>
                @endif

                <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-6">
                    <!-- ADD/EDIT FORM -->
                    <div class="border border-slate-200 rounded-lg p-6 bg-slate-50">
                        <h3 class="text-base font-bold text-slate-800 mb-4">
                            {{ $editingCourseId ? '✏️ Edit Course' : '➕ Add New Course' }}
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
                                            class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">
                                        Cancel
                                    </button>
                                @endif
                                <button type="button" wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse"
                                        class="flex-1 px-4 py-2 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                                    <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner spin-icon"></i> <span class="loading-dots">{{ $editingCourseId ? 'Updating' : 'Adding' }}</span></span>
                                    <span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId ? 'Update Course' : 'Add Course' }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- COURSES LIST WITH SCROLL -->
                    <div>
                        <h3 class="text-base font-bold text-slate-800 mb-4">📚 Existing Courses ({{ count($coursesList) }})</h3>
                        <div class="space-y-2 max-h-64 overflow-y-auto scrollbar-custom pr-2">
                            @forelse($coursesList as $course)
                            <div class="course-item flex items-center justify-between p-4 border border-slate-200 rounded-lg bg-white">
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-800 text-sm">{{ $course['code'] }}</p>
                                    <p class="text-slate-600 text-xs mt-1">{{ $course['name'] }}</p>
                                </div>
                                <div class="flex gap-2 ml-4">
                                    <button wire:click="openEditCourse({{ $course['id'] }})"
                                            class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition font-semibold text-xs border border-blue-200">
                                        <i class="fas fa-pencil"></i> Edit
                                    </button>
                                    <button wire:click="confirmDeleteCourse({{ $course['id'] }})"
                                            class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                            @empty
                            <p class="text-center text-slate-500 py-8 text-sm">No courses yet. Add one to get started!</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="px-8 py-4 border-t border-slate-200 bg-slate-50">
                    <button wire:click="closeModal"
                            class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">
                        Close
                    </button>
                </div>
            </div>
        </div>
        @endif

        <!-- DELETE COURSE CONFIRMATION MODAL -->
        @if($activeModal === 'deleteCourseConfirm')
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
                <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
                    <h2 class="text-xl font-bold text-red-800 flex items-center gap-3">
                        <i class="fas fa-triangle-exclamation"></i> Delete Course
                    </h2>
                </div>

                <div class="p-8">
                    <p class="text-slate-800 text-sm mb-4">
                        Are you sure you want to delete <strong class="text-red-600">{{ $deleteCourseName }}</strong>?
                    </p>
                    <p class="text-slate-600 text-xs mb-6">This action cannot be undone.</p>

                    <div class="flex gap-3">
                        <button type="button" wire:click="closeModal"
                                class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                            Cancel
                        </button>
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

        <!-- VIEW PROFILE MODAL -->
        @if($activeModal === 'viewProfile' && $viewingProfile)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
                <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                    <h2 class="text-2xl font-bold">Profile</h2>
                    <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
                </div>

                <div class="p-8 space-y-6">
                    <!-- Profile Photo Section -->
                    <div class="text-center">
                        <!-- Display current photo or preview of new photo -->
                        @if($updatingProfilePhoto)
                            <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" 
                                 alt="Preview"
                                 class="w-32 h-32 rounded-lg object-cover shadow-md mx-auto">
                        @else
                            <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}" 
                                 alt="{{ $viewingProfile['name'] }}"
                                 class="w-32 h-32 rounded-lg object-cover shadow-md mx-auto">
                        @endif
                        
                        <!-- Photo Upload Section -->
                        <div class="mt-6 border-2 border-dashed border-slate-300 rounded-lg p-6 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition"
                             @click="document.getElementById('profilePhotoInput').click()">
                            <i class="fas fa-camera text-3xl text-slate-400 block mb-2"></i>
                            <p class="text-slate-700 font-semibold text-sm">
                                {{ $updatingProfilePhoto ? 'Change Photo' : 'Click to Update Photo' }}
                            </p>
                            <p class="text-xs text-slate-600 mt-1">JPG, PNG, WebP · max 5 MB</p>
                            <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                        </div>

                        @if($updatingProfilePhoto)
                        <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                                class="w-full mt-4 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                            <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-save"></i> Save Photo</span>
                        </button>
                        @endif
                    </div>

                    <!-- Close Button -->
                    <button wire:click="closeModal"
                            class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                        Close
                    </button>
                </div>
            </div>
        </div>
        @endif

    </div>
</div>