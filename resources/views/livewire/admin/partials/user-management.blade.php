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

    protected function queryString(): array { return []; }

    // ── UI State ──────────────────────────────────────────
    public string $activeTab   = 'alumni';
    public string $activeModal = '';

    // ── Alumni Filters ────────────────────────────────────
    public string $alumniSearch = '';
    public string $alumniBatch  = '';
    public string $alumniCourse = '';
    public string $alumniSort   = 'recent';

    // ── Organizer Filters ─────────────────────────────────
    public string $orgSearch  = '';
    public string $orgCollege = '';
    public string $orgSort    = 'recent';

    // ── Register Alumni ───────────────────────────────────
    public string $regFirstName     = '';
    public string $regMiddleInitial = '';
    public string $regLastName      = '';
    public string $regSuffix        = '';
    public string $regStudentId     = '';
    public string $regEmail         = '';
    public string $regCourseCode    = '';
    public string $regYear          = '';
    public        $regPhoto         = null;
    public bool   $registeringAlumni = false;
    public array  $alumniErrors      = [];

    // ── Register Organizer ────────────────────────────────
    public string $orgFirstName         = '';
    public string $orgMiddleInitial     = '';
    public string $orgLastName          = '';
    public string $orgSuffix            = '';
    public string $orgTeacherId         = '';
    public string $orgEmail             = '';
    public string $orgDept              = '';
    public string $orgCollegeSelect     = '';
    public        $orgPhoto             = null;
    public bool   $registeringOrganizer = false;
    public array  $organizerErrors      = [];

    // ── Course Management ─────────────────────────────────
    public array   $coursesList      = [];
    public string  $courseCode       = '';
    public string  $courseName       = '';
    public ?int    $editingCourseId  = null;
    public bool    $savingCourse     = false;
    public string  $courseAlert      = '';
    public string  $courseAlertType  = '';
    public ?int    $deleteCourseId   = null;
    public string  $deleteCourseName = '';
    public bool    $deletingCourse   = false;

    // ── College Management ────────────────────────────────
    public array   $orgCoursesList         = [];
    public string  $orgNewCollegeName      = '';
    public ?string $orgAddingToCollege     = null;
    public array   $orgSelectedCourseCodes = [];
    public bool    $savingOrgCourse        = false;
    public string  $orgCourseAlert         = '';
    public string  $orgCourseAlertType     = '';
    public ?string $deleteOrgCollegeName   = null;
    public string  $deleteOrgCourseName    = '';
    public bool    $deletingOrgCourse      = false;
    public ?string $orgRenamingCollege     = null;
    public string  $orgRenameCollegeName   = '';

    // ── Organizer Status Toggle ───────────────────────────
    public ?int   $pendingToggleId     = null;
    public string $pendingToggleAction = '';
    public string $pendingToggleName   = '';

    // ── Import ────────────────────────────────────────────
    public        $importFile         = null;
    public bool   $importingFile      = false;
    public string $importStatus       = '';
    public int    $importProgress     = 0;
    public int    $importTotal        = 0;
    public int    $importSuccessCount = 0;
    public int    $importFailCount    = 0;
    public int    $importDuplicateCount = 0;
    public array  $importErrors       = [];
    public array  $importDuplicates   = [];

    // ── Profile ───────────────────────────────────────────
    public ?int   $viewingProfileId     = null;
    public string $viewingProfileType   = 'alumni';
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

    protected string $paginationTheme = 'tailwind';

    // ─────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────

    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void
    {
        $this->flash($type, $message);
    }

    public function mount(): void
    {
        $this->coursesList = Course::all()->toArray();
        $this->regYear     = (string) date('Y');
        $this->loadOrgCourses();

        if (session()->has('success')) {
            $msg = session()->pull('success');
            $this->dispatch('showFlash', type: 'success', message: $msg);
        }
        if (session()->has('error')) {
            $msg = session()->pull('error');
            $this->dispatch('showFlash', type: 'error', message: $msg);
        }
    }

    private function loadOrgCourses(): void
    {
        $grouped = [];
        foreach (Course::whereNotNull('college')->where('college', '!=', '')->orderBy('college')->orderBy('code')->get() as $c) {
            $grouped[$c->college][] = $c->toArray();
        }
        $this->orgCoursesList = $grouped;
    }

    // ─────────────────────────────────────────────────────
    // Filter Watchers
    // ─────────────────────────────────────────────────────

    public function updatingAlumniSearch() { $this->resetPage('alumniPage'); }
    public function updatingOrgSearch()    { $this->resetPage('orgPage'); }
    public function updatingAlumniBatch()  { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse() { $this->resetPage('alumniPage'); }
    public function updatingAlumniSort()   { $this->resetPage('alumniPage'); }
    public function updatingOrgCollege()   { $this->resetPage('orgPage'); }
    public function updatingOrgSort()      { $this->resetPage('orgPage'); }

    // ─────────────────────────────────────────────────────
    // Computed Properties
    // ─────────────────────────────────────────────────────

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query();
        if ($this->alumniSearch) {
            $q->where(fn($s) => $s
                ->where('name', 'like', "%{$this->alumniSearch}%")
                ->orWhere('student_id', 'like', "%{$this->alumniSearch}%")
                ->orWhere('email', 'like', "%{$this->alumniSearch}%"));
        }
        if ($this->alumniBatch)  $q->where('batch', $this->alumniBatch);
        if ($this->alumniCourse) $q->where('course_code', $this->alumniCourse);
        $q->when($this->alumniSort === 'oldest', fn($q) => $q->orderBy('created_at'), fn($q) => $q->orderByDesc('created_at'));
        return $q->paginate(100, ['*'], 'alumniPage');
    }

    #[Computed]
    public function organizerRecords()
    {
        $q = Organizer::withoutTrashed();
        if ($this->orgSearch) {
            $q->where(fn($s) => $s
                ->where('name', 'like', "%{$this->orgSearch}%")
                ->orWhere('email', 'like', "%{$this->orgSearch}%")
                ->orWhere('id_number', 'like', "%{$this->orgSearch}%"));
        }
        if ($this->orgCollege) {
            $codes = Course::where('college', $this->orgCollege)->pluck('code')->toArray();
            $q->whereIn('department', $codes);
        }
        $q->when($this->orgSort === 'oldest', fn($q) => $q->orderBy('created_at'), fn($q) => $q->orderByDesc('created_at'));
        return $q->paginate(100, ['*'], 'orgPage');
    }

    #[Computed] public function courses()     { return Course::orderBy('code')->get(); }
    #[Computed] public function batches()     { return Alumni::distinct()->orderByDesc('batch')->pluck('batch'); }
    #[Computed] public function orgColleges() { return Course::whereNotNull('college')->where('college', '!=', '')->distinct()->orderBy('college')->pluck('college'); }

    #[Computed]
    public function orgDepartmentsGrouped()
    {
        return Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->orderBy('college')->orderBy('code')
            ->get()->groupBy('college');
    }

    #[Computed]
    public function allCoursesForAssign() { return Course::orderBy('code')->get(); }

    // ─────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────

    public function getCollegeForCourse(string $code): string
    {
        return Course::where('code', $code)->value('college') ?? $code;
    }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');

        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');

        return asset('storage/alumni-photos/default.png');
    }

    private function validateName(string $n): bool
    {
        return preg_match('/^[a-zA-Z\s\-\.\']+$/', $n) === 1;
    }

    private function buildFullName(string $f, string $m, string $l, string $s): string
    {
        $n = implode(' ', array_filter([trim($f), trim($m), trim($l)]));
        if (trim($s) !== '') $n .= ' ' . trim($s);
        return $n;
    }

    private function flash(string $type, string $msg): void
    {
        $this->dispatch('flash-message', type: $type, message: $msg);
    }

    // ─────────────────────────────────────────────────────
    // Modal / Tab
    // ─────────────────────────────────────────────────────

    public function switchTab(string $tab): void { $this->activeTab = $tab; }

    public function openModal(string $modal): void
    {
        $this->activeModal = $modal;
        if ($modal === 'importModal')      $this->resetImportState();
        if ($modal === 'manageOrgCourses') { $this->loadOrgCourses(); $this->resetOrgCourseForm(); }
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->pendingToggleId      = null;
        $this->pendingToggleAction  = '';
        $this->pendingToggleName    = '';
        $this->viewingProfileId     = null;
        $this->updatingProfilePhoto = null;
        $this->resetImportState();
    }

    public function resetAlumniFilters(): void
    {
        $this->alumniSearch = $this->alumniBatch = $this->alumniCourse = '';
        $this->alumniSort   = 'recent';
        $this->resetPage('alumniPage');
    }

    public function resetOrgFilters(): void
    {
        $this->orgSearch = $this->orgCollege = '';
        $this->orgSort   = 'recent';
        $this->resetPage('orgPage');
    }

    // ─────────────────────────────────────────────────────
    // Import
    // ─────────────────────────────────────────────────────

    public function resetImportState(): void
    {
        $this->importFile           = null;
        $this->importingFile        = false;
        $this->importStatus         = '';
        $this->importProgress       = 0;
        $this->importTotal          = 0;
        $this->importSuccessCount   = 0;
        $this->importFailCount      = 0;
        $this->importDuplicateCount = 0;
        $this->importErrors         = [];
        $this->importDuplicates     = [];
    }

    public function cancelImport(): void
    {
        $this->resetImportState();
        $this->activeModal = '';
        $this->flash('info', 'Import cancelled.');
    }

    public function processImportFile(): void
    {
        set_time_limit(0);

        $this->importingFile        = true;
        $this->importStatus         = 'Validating…';
        $this->importProgress       = 0;
        $this->importSuccessCount   = 0;
        $this->importFailCount      = 0;
        $this->importDuplicateCount = 0;
        $this->importErrors         = [];
        $this->importDuplicates     = [];

        try {
            if (!$this->importFile) throw new \Exception('No file selected.');

            $ext = strtolower($this->importFile->getClientOriginalExtension());
            $rows = match(true) {
                in_array($ext, ['xlsx', 'xls']) => $this->parseExcelFile($this->importFile->getRealPath()),
                $ext === 'csv'                  => array_map('str_getcsv', file($this->importFile->getRealPath())),
                default                         => throw new \Exception('File must be .csv or .xlsx/.xls.'),
            };

            if (count($rows) < 2) throw new \Exception('File is empty or has no data rows.');

            // Build header map
            $header = array_map('trim', array_map('strtolower', $rows[0]));
            foreach (['name', 'student_id', 'course_code', 'year', 'email'] as $required) {
                if (!in_array($required, $header, true))
                    throw new \Exception("Missing required column: \"{$required}\".");
            }

            $this->importTotal = count($rows) - 1;

            // ── Pre-load all lookup data (zero per-row DB queries) ──
            $courseMap = Course::pluck('name', 'code')
                ->mapWithKeys(fn($name, $code) => [strtoupper($code) => $name])
                ->toArray();

            $existingAlumniEmails = Alumni::pluck('email')
                ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            $existingAlumniIds = Alumni::pluck('student_id')
                ->mapWithKeys(fn($id) => [$id => true])->toArray();

            $existingUserEmails = User::pluck('email')
                ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            // ── FIRST PASS: validate ALL rows before inserting anything ──
            $toInsertAlumni   = [];
            $toInsertUsers    = [];
            $emailJobs        = [];
            $seenEmailsInFile = [];
            $seenIdsInFile    = [];
            $validationErrors = [];
            $duplicates       = [];
            $maxErrorsStored  = 200;

            for ($i = 1; $i < count($rows); $i++) {
                $this->importProgress = $i;

                // Skip blank rows
                if (count(array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) === 0) continue;
                if (count($rows[$i]) < count($header)) continue;

                $row   = array_combine($header, array_slice($rows[$i], 0, count($header)));
                $name  = trim($row['name']        ?? '');
                $email = strtolower(trim($row['email']       ?? ''));
                $rawId = trim($row['student_id']  ?? '');
                $code  = strtoupper(trim($row['course_code'] ?? ''));
                $year  = trim($row['year']        ?? '');
                $label = "Row " . ($i + 1) . ($name ? " ({$name})" : '');

                // ── Name validation ──
                if (!$name) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Name is empty.");
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $name)) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Name \"{$name}\" contains invalid characters (letters, spaces, hyphens, apostrophes only).");
                    continue;
                }

                // ── Email validation ──
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Email \"{$email}\" is not a valid email address.");
                    continue;
                }

                // ── Duplicate detection ──
                if (isset($existingAlumniEmails[$email]) || isset($seenEmailsInFile[$email])) {
                    $source = isset($existingAlumniEmails[$email]) ? 'already exists in the system' : 'appears more than once in this file';
                    $duplicates[] = "{$label}: Email \"{$email}\" — {$source}.";
                    $this->importDuplicateCount++;
                    continue;
                }
                if (isset($existingUserEmails[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" — already used by an existing account.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Student ID validation ──
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawId)) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Student ID \"{$rawId}\" must be 1–8 digits (numbers only).");
                    continue;
                }
                $sid = str_pad($rawId, 8, '0', STR_PAD_LEFT);

                if (isset($existingAlumniIds[$sid]) || isset($seenIdsInFile[$sid])) {
                    $source = isset($existingAlumniIds[$sid]) ? 'already exists in the system' : 'appears more than once in this file';
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" — {$source}.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Course validation ──
                if (!isset($courseMap[$code])) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Course code \"{$code}\" does not exist in the system.");
                    continue;
                }

                // ── Year validation — accepts any 4-digit numeric year ──
                $batchYear = (int) $year;
                if (!preg_match('/^\d{4}$/', (string) $year) || $batchYear < 1000) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Year \"{$year}\" is invalid (must be a 4-digit year e.g. 2024).");
                    continue;
                }

                // ── Row passed — stage for insert ──
                $now      = now()->toDateTimeString();
                $tmp      = Str::random(10);
                $hashedTmp = password_hash($tmp, PASSWORD_BCRYPT, ['cost' => 4]);

                $toInsertAlumni[] = [
                    'name'        => $name,
                    'student_id'  => $sid,
                    'email'       => $email,
                    'course_code' => $code,
                    'course_name' => $courseMap[$code],
                    'batch'       => $batchYear,
                    'status'      => 'VERIFIED',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                $toInsertUsers[] = [
                    'name'       => $name,
                    'email'      => $email,
                    'password'   => $hashedTmp,
                    'role'       => 'alumni',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $emailJobs[] = compact('name', 'email', 'tmp', 'code', 'batchYear', 'sid') + [
                    'course_name' => $courseMap[$code],
                    'course_code' => $code,
                    'batch'       => $batchYear,
                ];

                $seenEmailsInFile[$email] = true;
                $seenIdsInFile[$sid]      = true;
            }

            // ── Store all error categories ──
            $this->importErrors     = $validationErrors;
            $this->importDuplicates = $duplicates;
            $this->importFailCount  = count($validationErrors);

            // ── If any HARD validation errors exist, abort entirely ──
            if (count($validationErrors) > 0) {
                $this->importStatus  = 'Validation Failed';
                $this->importingFile = false;
                $this->importFile    = null;
                return;
            }

            // ── No hard errors — bulk insert valid rows ──
            $this->importStatus = 'Importing…';

            foreach (array_chunk($toInsertAlumni, 100) as $chunk) Alumni::insert($chunk);
            foreach (array_chunk($toInsertUsers,  100) as $chunk) User::insert($chunk);

            $this->importSuccessCount = count($toInsertAlumni);

            // ── Queue welcome emails (non-blocking) ──
            if ($this->importSuccessCount > 0) {
                $insertedAlumni = Alumni::whereIn('email', array_column($emailJobs, 'email'))
                    ->get()->keyBy(fn($a) => strtolower($a->email));

                foreach ($emailJobs as $job) {
                    try {
                        $alumniModel = $insertedAlumni[strtolower($job['email'])] ?? null;
                        if ($alumniModel) {
                            Mail::to($job['email'])->queue(new \App\Mail\AlumniRegistered($alumniModel, $job['tmp']));
                        }
                    } catch (\Exception $me) {
                        Log::warning('Import queue email failed: ' . $me->getMessage());
                    }
                }
            }

            // ── Wrap up ──
            $this->importStatus  = 'Done';
            $this->importingFile = false;
            $this->coursesList   = Course::all()->toArray();
            $this->importFile    = null;

            if ($this->importSuccessCount === 0 && $this->importDuplicateCount > 0) {
                $this->importStatus = 'All Duplicates';
            }

            $this->resetAlumniFilters();

        } catch (\Exception $e) {
            Log::error('Import error: ' . $e->getMessage());
            $this->importStatus  = 'Error: ' . $e->getMessage();
            $this->importingFile = false;
        }
    }

    private function _addValidationError(array &$errors, int $max, string $msg): void
    {
        $this->importFailCount++;
        if (count($errors) < $max) {
            $errors[] = $msg;
        } elseif (count($errors) === $max) {
            $errors[] = '… (additional errors truncated — fix the listed issues first)';
        }
    }

    private function parseExcelFile(string $path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($path);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = [];
            $highestRow  = $sheet->getHighestDataRow();
            $highestCol  = $sheet->getHighestDataColumn();

            foreach ($sheet->getRowIterator(1, $highestRow) as $row) {
                $rd = [];
                $ci = $row->getCellIterator('A', $highestCol);
                $ci->setIterateOnlyExistingCells(false);
                foreach ($ci as $cell) $rd[] = $cell->getValue();
                $rows[] = $rd;
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            return $rows;
        } catch (\Exception $e) {
            throw new \Exception('Excel parse failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────
    // Alumni Registration
    // ─────────────────────────────────────────────────────

    public function registerAlumni(): void
    {
        $this->alumniErrors      = [];
        $this->registeringAlumni = true;

        try {
            if (!$this->validateName(trim($this->regFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->regLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');
            if (trim($this->regMiddleInitial) !== '' && !preg_match('/^[a-zA-Z]+$/', trim($this->regMiddleInitial)))
                throw new \Exception('Middle initial may only contain letters.');
            if (trim($this->regSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->regSuffix)))
                throw new \Exception('Suffix may only contain letters and periods (e.g. Jr. Sr. III)');

            $fullName = $this->buildFullName($this->regFirstName, $this->regMiddleInitial, $this->regLastName, $this->regSuffix);

            $this->validate([
                'regFirstName'     => ['required', 'string', 'max:100'],
                'regLastName'      => ['required', 'string', 'max:100'],
                'regMiddleInitial' => ['nullable', 'string', 'max:2', 'regex:/^[a-zA-Z]+$/'],
                'regSuffix'        => ['nullable', 'string', 'max:10'],
                'regStudentId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:alumni,student_id'],
                'regEmail'         => ['required', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
                'regCourseCode'    => ['required', 'string', 'exists:courses,code'],
                'regYear'          => ['required', 'digits:4'],
                'regPhoto'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ], [
                'regStudentId.unique'  => 'This Student ID is already registered.',
                'regStudentId.regex'   => 'Student ID must be 1–8 digits (numbers only).',
                'regEmail.unique'      => 'This email address is already taken.',
                'regCourseCode.exists' => 'The selected course does not exist.',
                'regYear.digits'       => 'Batch year must be exactly 4 digits (e.g. 2024).',
                'regPhoto.max'         => 'Profile photo must not exceed 5MB.',
            ]);

            $paddedId  = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $course    = Course::where('code', $this->regCourseCode)->firstOrFail();
            $photoPath = $this->regPhoto ? $this->storeAlumniPhoto($this->regPhoto) : null;

            $alumni = Alumni::create([
                'name'          => $fullName,
                'student_id'    => $paddedId,
                'email'         => $this->regEmail,
                'course_code'   => $this->regCourseCode,
                'course_name'   => $course->name,
                'batch'         => (int) $this->regYear,
                'status'        => 'VERIFIED',
                'profile_photo' => $photoPath,
            ]);

            $tmp = Str::random(10);
            User::create(['name' => $fullName, 'email' => $this->regEmail, 'password' => Hash::make($tmp), 'role' => 'alumni']);

            try {
                Mail::to($alumni->email)->queue(new \App\Mail\AlumniRegistered($alumni, $tmp));
            } catch (\Exception $e) {
                Log::warning('Email: ' . $e->getMessage());
            }

            $this->resetRegAlumniForm();
            $this->flash('success', "Alumni '{$fullName}' registered successfully!");
            $this->activeModal = '';

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->alumniErrors = $e->errors();
        } catch (\Exception $e) {
            Log::error('Alumni: ' . $e->getMessage());
            $this->alumniErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->registeringAlumni = false;
        }
    }

    private function storeAlumniPhoto($p): ?string
    {
        if (!$p) return null;
        try {
            $f = "alumni-" . Str::uuid() . "." . $p->getClientOriginalExtension();
            $r = $p->storeAs('alumni-photos', $f, 'public');
            return $r === false ? null : "alumni-photos/{$f}";
        } catch (\Exception $e) {
            Log::error('Photo: ' . $e->getMessage());
            return null;
        }
    }

    private function resetRegAlumniForm(): void
    {
        $this->regFirstName = $this->regMiddleInitial = $this->regLastName = $this->regSuffix = '';
        $this->regStudentId = $this->regEmail = $this->regCourseCode = '';
        $this->regPhoto     = null;
        $this->regYear      = (string) date('Y');
        $this->alumniErrors = [];
    }

    // ─────────────────────────────────────────────────────
    // Organizer Registration
    // ─────────────────────────────────────────────────────

    public function registerOrganizer(): void
    {
        $this->organizerErrors      = [];
        $this->registeringOrganizer = true;

        try {
            if (!$this->validateName(trim($this->orgFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->orgLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');
            if (trim($this->orgMiddleInitial) !== '' && !preg_match('/^[a-zA-Z]+$/', trim($this->orgMiddleInitial)))
                throw new \Exception('Middle initial may only contain letters.');
            if (trim($this->orgSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->orgSuffix)))
                throw new \Exception('Suffix may only contain letters and periods (e.g. Jr. Sr. III)');

            $fullName = $this->buildFullName($this->orgFirstName, $this->orgMiddleInitial, $this->orgLastName, $this->orgSuffix);
            $college  = trim($this->orgCollegeSelect);

            if (!$college) throw new \Exception('Please select a college.');
            $this->orgDept = $college;

            $this->validate([
                'orgFirstName'     => ['required', 'string', 'max:100'],
                'orgLastName'      => ['required', 'string', 'max:100'],
                'orgMiddleInitial' => ['nullable', 'string', 'max:2', 'regex:/^[a-zA-Z]+$/'],
                'orgSuffix'        => ['nullable', 'string', 'max:10'],
                'orgTeacherId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:organizer,id_number'],
                'orgEmail'         => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
                'orgDept'          => ['required', 'string'],
                'orgPhoto'         => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ], [
                'orgTeacherId.unique' => 'This Teacher ID is already registered.',
                'orgTeacherId.regex'  => 'Teacher ID must be 1–8 digits (numbers only).',
                'orgEmail.unique'     => 'This email address is already taken.',
                'orgDept.required'    => 'Please select a college.',
                'orgPhoto.max'        => 'Profile photo must not exceed 5MB.',
            ]);

            $paddedId  = str_pad($this->orgTeacherId, 8, '0', STR_PAD_LEFT);
            $photoPath = $this->orgPhoto ? $this->storeOrganizerPhoto($this->orgPhoto) : null;
            $tmp       = Str::random(10);

            $user = User::create([
                'name'     => $fullName,
                'email'    => $this->orgEmail,
                'role'     => 'organizer',
                'password' => Hash::make($tmp),
            ]);

            $organizer = Organizer::create([
                'user_id'       => $user->id,
                'name'          => $fullName,
                'email'         => $this->orgEmail,
                'id_number'     => $paddedId,
                'department'    => $college,
                'profile_photo' => $photoPath,
                'status'        => 'ACTIVE',
            ]);

            try {
                Mail::to($organizer->email)->queue(new \App\Mail\OrganizerRegistered($organizer, $tmp));
            } catch (\Exception $e) {
                Log::warning('Email: ' . $e->getMessage());
            }

            $this->resetOrgForm();
            $this->flash('success', "Organizer '{$fullName}' registered successfully!");
            $this->activeModal = '';

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->organizerErrors = $e->errors();
        } catch (\Exception $e) {
            Log::error('Organizer: ' . $e->getMessage());
            $this->organizerErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->registeringOrganizer = false;
        }
    }

    private function storeOrganizerPhoto($p): ?string
    {
        if (!$p) return null;
        try {
            $f = "organizer-" . Str::uuid() . "." . $p->getClientOriginalExtension();
            $r = $p->storeAs('organizers', $f, 'public');
            return $r === false ? null : "organizers/{$f}";
        } catch (\Exception $e) {
            Log::error('OrgPhoto: ' . $e->getMessage());
            return null;
        }
    }

    private function resetOrgForm(): void
    {
        $this->orgFirstName = $this->orgMiddleInitial = $this->orgLastName = $this->orgSuffix = '';
        $this->orgTeacherId = $this->orgEmail = $this->orgDept = $this->orgCollegeSelect = '';
        $this->orgPhoto        = null;
        $this->organizerErrors = [];
    }

    // ─────────────────────────────────────────────────────
    // Course Management
    // ─────────────────────────────────────────────────────

    public function openEditCourse(int $id): void
    {
        try {
            $c = Course::findOrFail($id);
            $this->editingCourseId = $c->id;
            $this->courseCode      = $c->code;
            $this->courseName      = $c->name;
            $this->courseAlert     = '';
            $this->courseAlertType = '';
        } catch (\Exception $e) {
            $this->courseAlert     = 'Failed to load course.';
            $this->courseAlertType = 'error';
        }
    }

    public function resetCourseForm(): void
    {
        $this->editingCourseId = null;
        $this->courseCode      = $this->courseName = '';
        $this->courseAlert     = '';
        $this->courseAlertType = '';
        $this->savingCourse    = false;
    }

    public function saveCourse(): void
    {
        $this->savingCourse = true;
        $code = strtoupper(trim($this->courseCode));
        $name = trim($this->courseName);

        if (!$code || !$name) {
            $this->courseAlert     = 'Code and Name are required.';
            $this->courseAlertType = 'error';
            $this->savingCourse    = false;
            return;
        }

        try {
            if ($this->editingCourseId) {
                Course::findOrFail($this->editingCourseId)->update(['code' => $code, 'name' => $name]);
                $this->courseAlert = 'Course updated!';
            } else {
                Course::create(['code' => $code, 'name' => $name]);
                $this->courseAlert = 'Course added!';
            }
            $this->courseAlertType = 'success';
            $this->coursesList     = Course::all()->toArray();
            $this->resetCourseForm();
        } catch (\Exception $e) {
            $this->courseAlert     = str_contains($e->getMessage(), 'Duplicate') ? 'Course code already exists.' : 'Failed to save.';
            $this->courseAlertType = 'error';
        } finally {
            $this->savingCourse = false;
        }
    }

    public function confirmDeleteCourse(int $id): void
    {
        try {
            $c = Course::findOrFail($id);
            $this->deleteCourseId   = $id;
            $this->deleteCourseName = $c->name;
            $this->activeModal      = 'deleteCourseConfirm';
        } catch (\Exception $e) {
            $this->courseAlert     = 'Failed.';
            $this->courseAlertType = 'error';
        }
    }

    public function deleteCourse(): void
    {
        $this->deletingCourse = true;
        try {
            Course::findOrFail($this->deleteCourseId)->delete();
            $this->courseAlert      = 'Course deleted!';
            $this->courseAlertType  = 'success';
            $this->coursesList      = Course::all()->toArray();
            $this->deleteCourseId   = null;
            $this->deleteCourseName = '';
            $this->activeModal      = 'manageCourses';
        } catch (\Exception $e) {
            $this->courseAlert     = 'Failed to delete.';
            $this->courseAlertType = 'error';
            $this->activeModal     = 'manageCourses';
        } finally {
            $this->deletingCourse = false;
        }
    }

    // ─────────────────────────────────────────────────────
    // College Management
    // ─────────────────────────────────────────────────────

    public function resetOrgCourseForm(): void
    {
        $this->orgNewCollegeName      = '';
        $this->orgAddingToCollege     = null;
        $this->orgSelectedCourseCodes = [];
        $this->savingOrgCourse        = false;
        $this->orgCourseAlert         = '';
        $this->orgCourseAlertType     = '';
        $this->deleteOrgCollegeName   = null;
        $this->deleteOrgCourseName    = '';
        $this->orgRenamingCollege     = null;
        $this->orgRenameCollegeName   = '';
    }

    public function addCollege(): void
    {
        $name = trim($this->orgNewCollegeName);
        if (!$name) {
            $this->orgCourseAlert     = 'College name is required.';
            $this->orgCourseAlertType = 'error';
            return;
        }
        if (isset($this->orgCoursesList[$name])) {
            $this->orgCourseAlert     = "College '{$name}' already exists.";
            $this->orgCourseAlertType = 'error';
            return;
        }
        $this->orgAddingToCollege     = $name;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName      = '';
        $this->orgCourseAlert         = '';
        $this->orgCourseAlertType     = '';
    }

    public function startEditingCollege(string $college): void
    {
        $this->orgAddingToCollege     = $college;
        $this->orgSelectedCourseCodes = Course::where('college', $college)->pluck('code')->toArray();
        $this->orgCourseAlert         = '';
        $this->orgCourseAlertType     = '';
    }

    public function cancelAddingCourses(): void
    {
        $this->orgAddingToCollege     = null;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName      = '';
        $this->orgCourseAlert         = '';
        $this->orgCourseAlertType     = '';
    }

    public function startRenamingCollege(string $college): void
    {
        $this->orgRenamingCollege   = $college;
        $this->orgRenameCollegeName = $college;
        $this->orgCourseAlert       = '';
        $this->orgCourseAlertType   = '';
    }

    public function cancelRenamingCollege(): void
    {
        $this->orgRenamingCollege   = null;
        $this->orgRenameCollegeName = '';
    }

    public function renameCollege(): void
    {
        $oldName = trim($this->orgRenamingCollege ?? '');
        $newName = trim($this->orgRenameCollegeName);

        if (!$newName) {
            $this->orgCourseAlert     = 'New college name is required.';
            $this->orgCourseAlertType = 'error';
            return;
        }
        if ($newName === $oldName) { $this->cancelRenamingCollege(); return; }
        if (isset($this->orgCoursesList[$newName])) {
            $this->orgCourseAlert     = "A college named \"{$newName}\" already exists.";
            $this->orgCourseAlertType = 'error';
            return;
        }

        try {
            Course::where('college', $oldName)->update(['college' => $newName]);
            Organizer::where('department', $oldName)->update(['department' => $newName]);
            $this->orgCourseAlert     = "College renamed to \"{$newName}\" successfully.";
            $this->orgCourseAlertType = 'success';
            $this->cancelRenamingCollege();
            $this->loadOrgCourses();
            $this->coursesList = Course::all()->toArray();
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed to rename: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        }
    }

    public function saveCollegeCourses(): void
    {
        $this->savingOrgCourse = true;
        $college = trim($this->orgAddingToCollege ?? '');

        if (!$college) {
            $this->orgCourseAlert     = 'College name missing.';
            $this->orgCourseAlertType = 'error';
            $this->savingOrgCourse    = false;
            return;
        }
        if (empty($this->orgSelectedCourseCodes)) {
            $this->orgCourseAlert     = 'Select at least one course.';
            $this->orgCourseAlertType = 'error';
            $this->savingOrgCourse    = false;
            return;
        }

        try {
            Course::where('college', $college)->whereNotIn('code', $this->orgSelectedCourseCodes)->update(['college' => null]);
            Course::whereIn('code', $this->orgSelectedCourseCodes)->update(['college' => $college]);
            $this->orgCourseAlert     = "Saved '{$college}' with " . count($this->orgSelectedCourseCodes) . " department(s)!";
            $this->orgCourseAlertType = 'success';
            $this->orgAddingToCollege     = null;
            $this->orgSelectedCourseCodes = [];
            $this->loadOrgCourses();
            $this->coursesList = Course::all()->toArray();
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        } finally {
            $this->savingOrgCourse = false;
        }
    }

    public function confirmDeleteCollege(string $college): void
    {
        $this->deleteOrgCollegeName = $college;
        $this->deleteOrgCourseName  = $college;
        $this->activeModal          = 'deleteOrgCollegeConfirm';
    }

    public function deleteOrgCollege(): void
    {
        $this->deletingOrgCourse = true;
        try {
            Course::where('college', $this->deleteOrgCollegeName)->update(['college' => null]);
            $this->orgCourseAlert     = "College '{$this->deleteOrgCollegeName}' removed.";
            $this->orgCourseAlertType = 'success';
            $this->deleteOrgCollegeName = null;
            $this->deleteOrgCourseName  = '';
            $this->loadOrgCourses();
            $this->coursesList = Course::all()->toArray();
            $this->activeModal = 'manageOrgCourses';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed.';
            $this->orgCourseAlertType = 'error';
            $this->activeModal        = 'manageOrgCourses';
        } finally {
            $this->deletingOrgCourse = false;
        }
    }

    // ─────────────────────────────────────────────────────
    // Organizer Status Toggle
    // ─────────────────────────────────────────────────────

    public function confirmToggleOrganizerStatus(int $id, string $action): void
    {
        try {
            $organizer = Organizer::findOrFail($id);
            $this->pendingToggleId     = $id;
            $this->pendingToggleAction = $action;
            $this->pendingToggleName   = $organizer->name;
            $this->activeModal         = 'toggleOrganizerConfirm';
        } catch (\Exception $e) {
            $this->flash('error', 'Could not find organizer.');
        }
    }

    public function executeToggleOrganizerStatus(): void
    {
        if (!$this->pendingToggleId) return;
        try {
            $organizer = Organizer::findOrFail($this->pendingToggleId);
            $newStatus = $this->pendingToggleAction === 'deactivate' ? 'INACTIVE' : 'ACTIVE';
            $organizer->update(['status' => $newStatus]);
            $verb = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';
            $this->flash('success', "{$organizer->name} has been {$verb}.");
        } catch (\Exception $e) {
            $this->flash('error', 'Could not update status: ' . $e->getMessage());
        } finally {
            $this->pendingToggleId     = null;
            $this->pendingToggleAction = '';
            $this->pendingToggleName   = '';
            $this->activeModal         = '';
        }
    }

    // ─────────────────────────────────────────────────────
    // Profile Management
    // ─────────────────────────────────────────────────────

    public function viewProfile(int $id, string $type): void
    {
        try {
            $this->viewingProfileType = $type;
            $this->viewingProfile     = $type === 'alumni'
                ? Alumni::findOrFail($id)->toArray()
                : Organizer::findOrFail($id)->toArray();
            $this->viewingProfileId = $id;
            $this->activeModal      = 'viewProfile';
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to load profile');
        }
    }

    public function updateProfilePhoto(): void
    {
        if (!$this->updatingProfilePhoto || !$this->viewingProfileId) return;
        $this->updatingProfile = true;

        try {
            if ($this->viewingProfileType === 'alumni') {
                $a = Alumni::findOrFail($this->viewingProfileId);
                if ($a->profile_photo && !str_contains($a->profile_photo, 'default.png'))
                    Storage::disk('public')->delete($a->profile_photo);
                $p = $this->storeAlumniPhoto($this->updatingProfilePhoto);
                $a->update(['profile_photo' => $p]);
                $this->viewingProfile['profile_photo'] = $p;
            } else {
                $o = Organizer::findOrFail($this->viewingProfileId);
                if ($o->profile_photo && !str_contains($o->profile_photo, 'default.png'))
                    Storage::disk('public')->delete($o->profile_photo);
                $p = $this->storeOrganizerPhoto($this->updatingProfilePhoto);
                $o->update(['profile_photo' => $p]);
                $this->viewingProfile['profile_photo'] = $p;
            }
            $this->updatingProfilePhoto = null;
            $this->flash('success', 'Photo updated!');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to update photo');
        } finally {
            $this->updatingProfile = false;
        }
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-50 overflow-hidden" style="height:90vh">

<link rel="stylesheet" href="{{ asset('css/user-management.css') }}">

{{-- ── FLASH TOAST ──────────────────────────────────────────────────────── --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,10000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm"
     :class="{
         'bg-emerald-50 border-emerald-200 text-emerald-800': type==='success',
         'bg-blue-50 border-blue-200 text-blue-800': type==='info',
         'bg-red-50 border-red-200 text-red-800': type==='error'
     }"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0"
       :class="{
           'fa-check-circle text-emerald-500': type==='success',
           'fa-info-circle text-blue-500': type==='info',
           'fa-exclamation-circle text-red-500': type==='error'
       }"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></div>
        <div class="text-sm mt-0.5 leading-snug opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 shrink-0 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    {{-- ── HEADER ────────────────────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
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
            <div @class(['flex flex-wrap gap-2' => true, 'hidden' => $this->activeTab !== 'alumni'])>
                <button wire:click="openModal('registerAlumni')" class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                    <i class="fas fa-user-plus"></i> Register Alumni
                </button>
                <button wire:click="openModal('importModal')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                    <i class="fas fa-file-import"></i> Import
                </button>
                <button wire:click="openModal('manageCourses')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                    <i class="fas fa-sliders"></i> Manage Courses
                </button>
            </div>
            <div @class(['flex flex-wrap gap-2' => true, 'hidden' => $this->activeTab !== 'organizers'])>
                <button wire:click="openModal('registerOrganizer')" class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                    <i class="fas fa-users-gear"></i> Register Organizer
                </button>
                <button wire:click="openModal('manageOrgCourses')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200">
                    <i class="fas fa-building-columns"></i> Manage Colleges
                </button>
            </div>
        </div>
    </div>

    {{-- ── TABS ───────────────────────────────────────────────────────────── --}}
    <div class="flex gap-2 mb-4 shrink-0">
        <button wire:click="switchTab('alumni')"
                class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm {{ $this->activeTab==='alumni'?'bg-white text-slate-800 shadow-sm':'bg-white/50 text-slate-600 hover:bg-white/70' }}">
            <i class="fas fa-graduation-cap"></i> Alumni
        </button>
        <button wire:click="switchTab('organizers')"
                class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm {{ $this->activeTab==='organizers'?'bg-white text-slate-800 shadow-sm':'bg-white/50 text-slate-600 hover:bg-white/70' }}">
            <i class="fas fa-users-gear"></i> Organizers
        </button>
    </div>

    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">
        <div @class(['flex flex-col flex-1 min-h-0' => true, 'hidden' => $this->activeTab !== 'alumni'])>
            @include('livewire.admin.partials._alumni-table')
        </div>
        <div @class(['flex flex-col flex-1 min-h-0' => true, 'hidden' => $this->activeTab !== 'organizers'])>
            @include('livewire.admin.partials._organizers-table')
        </div>
    </div>

</div>

{{-- ── MODALS ─────────────────────────────────────────────────────────────── --}}
@include('livewire.admin.partials._modals-alumni')
@include('livewire.admin.partials._modals-organizer')

</div>