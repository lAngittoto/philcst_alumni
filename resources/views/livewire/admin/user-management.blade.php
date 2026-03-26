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
    public string $regFirstName      = '';
    public string $regMiddleInitial  = '';
    public string $regLastName       = '';
    public string $regSuffix         = '';
    public string $regStudentId      = '';
    public string $regEmail          = '';
    public string $regCourseCode     = '';
    public string $regYear           = '';
    public        $regPhoto          = null;
    public bool   $registeringAlumni = false;
    public array  $alumniErrors      = [];
    public string $alumniSuccess     = '';

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
    public string $organizerSuccess     = '';

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
    public        $importFile           = null;
    public bool   $importingFile        = false;
    public string $importStatus         = '';
    public int    $importProgress       = 0;
    public int    $importTotal          = 0;
    public int    $importSuccessCount   = 0;
    public int    $importFailCount      = 0;
    public int    $importDuplicateCount = 0;
    public array  $importErrors         = [];
    public array  $importDuplicates     = [];
    public string $importStep           = 'upload';

    // ── Profile ───────────────────────────────────────────
    public ?int   $viewingProfileId     = null;
    public string $viewingProfileType   = 'alumni';
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

    protected string $paginationTheme = 'tailwind';

    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void
    {
        $this->flash($type, $message);
    }

    public function mount(): void
    {
        $this->coursesList = Course::orderByDesc('created_at')->get()->toArray();
        $this->regYear     = (string) date('Y');
        $this->loadOrgCourses();

        if (session()->has('success')) {
            $this->dispatch('showFlash', type: 'success', message: session()->pull('success'));
        }
        if (session()->has('error')) {
            $this->dispatch('showFlash', type: 'error', message: session()->pull('error'));
        }
    }

    private function loadOrgCourses(): void
    {
        $grouped = [];
        foreach (Course::orderByDesc('updated_at')->orderBy('code')->get() as $c) {
            $college = $c->college ?? null;
            if ($college) {
                $grouped[$college][] = $c->toArray();
            }
        }
        $this->orgCoursesList = $grouped;
    }

    public function updatingAlumniSearch() { $this->resetPage('alumniPage'); }
    public function updatingOrgSearch()    { $this->resetPage('orgPage'); }
    public function updatingAlumniBatch()  { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse() { $this->resetPage('alumniPage'); }
    public function updatingAlumniSort()   { $this->resetPage('alumniPage'); }
    public function updatingOrgCollege()   { $this->resetPage('orgPage'); }
    public function updatingOrgSort()      { $this->resetPage('orgPage'); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query();
        if ($this->alumniSearch) {
            $q->where(fn($s) => $s
                ->where('name',       'like', "%{$this->alumniSearch}%")
                ->orWhere('first_name', 'like', "%{$this->alumniSearch}%")
                ->orWhere('last_name',  'like', "%{$this->alumniSearch}%")
                ->orWhere('student_id', 'like', "%{$this->alumniSearch}%")
                ->orWhere('email',      'like', "%{$this->alumniSearch}%")
                ->orWhere('course_code', 'like', "%{$this->alumniSearch}%")
                ->orWhere('course_name', 'like', "%{$this->alumniSearch}%"));
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
                ->where('name',      'like', "%{$this->orgSearch}%")
                ->orWhere('email',     'like', "%{$this->orgSearch}%")
                ->orWhere('id_number', 'like', "%{$this->orgSearch}%"));
        }
        if ($this->orgCollege) $q->where('department', $this->orgCollege);
        $q->when($this->orgSort === 'oldest', fn($q) => $q->orderBy('created_at'), fn($q) => $q->orderByDesc('created_at'));
        return $q->paginate(10, ['*'], 'orgPage');
    }

    #[Computed] public function courses()     { return Course::orderByDesc('created_at')->get(); }
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

    #[Computed]
    public function occupiedColleges(): array
    {
        $result = [];
        $organizers = Organizer::withoutTrashed()
            ->where('status', 'ACTIVE')
            ->select('department', 'first_name', 'middle_initial', 'last_name', 'suffix')
            ->get();

        foreach ($organizers as $org) {
            $dept        = $org->department;
            $collegeName = Course::where('college', $dept)->exists()
                ? $dept
                : (Course::where('code', $dept)->value('college') ?? $dept);
            if ($collegeName && !isset($result[$collegeName])) {
                $result[$collegeName] = $org->getFullName();
            }
        }
        return $result;
    }

    #[Computed]
    public function totalColleges(): int
    {
        return Course::whereNotNull('college')->where('college', '!=', '')->distinct('college')->count('college');
    }

    public function getCollegeForCourse(string $code): string
    {
        return Course::where('college', $code)->exists()
            ? $code
            : (Course::where('code', $code)->value('college') ?? $code);
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
        $parts = [];
        if (trim($f)) $parts[] = trim($f);
        if (trim($m)) $parts[] = trim($m);
        if (trim($l)) $parts[] = trim($l);
        if (trim($s)) $parts[] = trim($s);
        return implode(' ', $parts);
    }

    private function flash(string $type, string $msg): void
    {
        $this->dispatch('flash-message', type: $type, message: $msg);
    }

    public function switchTab(string $tab): void { $this->activeTab = $tab; }

    public function openModal(string $modal): void
    {
        if ($modal === 'importModal') $this->resetImportState();
        if ($modal === 'manageOrgCourses') { $this->loadOrgCourses(); $this->resetOrgCourseForm(); }
        if ($modal === 'registerAlumni') { $this->alumniSuccess = ''; $this->alumniErrors = []; }
        if ($modal === 'registerOrganizer') { $this->organizerSuccess = ''; $this->organizerErrors = []; }
        $this->activeModal = $modal;
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->pendingToggleId      = null;
        $this->pendingToggleAction  = '';
        $this->pendingToggleName    = '';
        $this->viewingProfileId     = null;
        $this->updatingProfilePhoto = null;
        $this->alumniSuccess        = '';
        $this->organizerSuccess     = '';
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
        $this->importStep           = 'upload';
    }

    public function cancelImport(): void
    {
        $this->resetImportState();
        $this->activeModal = '';
        $this->flash('info', 'Import cancelled.');
    }

    public function processImportFile(): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        $this->importingFile        = true;
        $this->importStatus         = 'Validating…';
        $this->importStep           = 'processing';
        $this->importProgress       = 0;
        $this->importSuccessCount   = 0;
        $this->importFailCount      = 0;
        $this->importDuplicateCount = 0;
        $this->importErrors         = [];
        $this->importDuplicates     = [];

        try {
            if (!$this->importFile) throw new \Exception('No file selected.');

            $ext  = strtolower($this->importFile->getClientOriginalExtension());
            $rows = match(true) {
                in_array($ext, ['xlsx', 'xls']) => $this->parseExcelFile($this->importFile->getRealPath()),
                $ext === 'csv'                  => array_map('str_getcsv', file($this->importFile->getRealPath())),
                default                         => throw new \Exception('File must be .csv or .xlsx/.xls.'),
            };

            if (count($rows) < 2) throw new \Exception('File is empty or has no data rows.');

            $header = array_map('trim', array_map('strtolower', $rows[0]));

            if (!in_array('first_name', $header, true) || !in_array('last_name', $header, true)) {
                throw new \Exception('Missing required columns: "first_name" and "last_name" must both be present.');
            }
            foreach (['middle_initial', 'student_id', 'course_code', 'batch', 'email'] as $req) {
                if (!in_array($req, $header, true))
                    throw new \Exception("Missing required column: \"{$req}\".");
            }

            $this->importTotal = count($rows) - 1;

            $courseMap            = Course::pluck('name', 'code')->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])->toArray();
            $existingAlumniEmails = Alumni::pluck('email')->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();
            $existingAlumniIds    = Alumni::pluck('student_id')->mapWithKeys(fn($id) => [$id => true])->toArray();
            $existingUserEmails   = User::pluck('email')->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            $sharedHash = password_hash(Str::random(32), PASSWORD_BCRYPT, ['cost' => 10]);

            $emailJobs          = [];
            $orphanEmailsToNuke = [];
            $seenEmailsInFile   = [];
            $seenIdsInFile      = [];
            $validationErrors   = [];
            $duplicates         = [];
            $maxErrorsStored    = 200;
            $now                = now()->toDateTimeString();

            for ($i = 1; $i < count($rows); $i++) {
                $this->importProgress = $i;

                if (count(array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) === 0) continue;
                if (count($rows[$i]) < count($header)) continue;

                $row = array_combine($header, array_slice($rows[$i], 0, count($header)));

                $firstName     = trim($row['first_name']     ?? '');
                $middleInitial = trim($row['middle_initial'] ?? '');
                $lastName      = trim($row['last_name']      ?? '');
                $suffix        = trim($row['suffix']         ?? '');
                $fullName      = $this->buildFullName($firstName, $middleInitial, $lastName, $suffix);

                $email  = strtolower(trim($row['email'] ?? ''));
                $rawId  = rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.');
                $rawId  = preg_replace('/\..*$/', '', $rawId);
                $code   = strtoupper(trim($row['course_code'] ?? ''));
                $year   = (string)(int)($row['batch'] ?? 0);
                $label  = "Row " . ($i + 1) . ($fullName ? " ({$fullName})" : '');

                if (!$firstName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name contains invalid characters."); continue; }
                if (!$lastName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name contains invalid characters."); continue; }
                if ($middleInitial === '') { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle initial is required."); continue; }
                if (!preg_match('/^[a-zA-Z]{1,2}$/', $middleInitial)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle initial must be 1–2 letters."); continue; }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Email \"{$email}\" is not valid."); continue; }
                if (isset($existingAlumniEmails[$email]) || isset($seenEmailsInFile[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }
                if (isset($existingUserEmails[$email])) {
                    $orphanEmailsToNuke[] = $email;
                    unset($existingUserEmails[$email]);
                }

                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int)$rawIdClean === 0) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Student ID \"{$rawId}\" is invalid (1–8 digits).");
                    continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);
                if (isset($existingAlumniIds[$sid]) || isset($seenIdsInFile[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    $this->importDuplicateCount++;
                    continue;
                }

                if (!isset($courseMap[$code])) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Course code \"{$code}\" does not exist."); continue; }

                $batchYear = (int) $year;
                if ($batchYear < 1000 || $batchYear > 9999) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Batch \"{$year}\" must be a 4-digit year."); continue; }

                $tmp = Str::random(10);

                $emailJobs[] = [
                    'fullName'   => $fullName,
                    'email'      => $email,
                    'tmp'        => $tmp,
                    'firstName'  => $firstName,
                    'middleInit' => $middleInitial,
                    'lastName'   => $lastName,
                    'suffix'     => $suffix,
                    'sid'        => $sid,
                    'code'       => $code,
                    'courseName' => $courseMap[$code],
                    'batchYear'  => $batchYear,
                    'now'        => $now,
                ];

                $seenEmailsInFile[$email] = true;
                $seenIdsInFile[$sid]      = true;
            }

            $this->importErrors     = $validationErrors;
            $this->importDuplicates = $duplicates;
            $this->importFailCount  = count($validationErrors);

            if (empty($emailJobs)) {
                $this->importStatus  = 'Done';
                $this->importStep    = 'done';
                $this->importingFile = false;
                $this->importFile    = null;
                return;
            }

            $this->importStatus = 'Importing…';

            if (!empty($orphanEmailsToNuke)) {
                User::whereIn('email', $orphanEmailsToNuke)->delete();
            }

            $userRows = array_map(fn($job) => [
                'name'       => $job['fullName'],
                'email'      => $job['email'],
                'password'   => $sharedHash,
                'role'       => 'alumni',
                'created_at' => $job['now'],
                'updated_at' => $job['now'],
            ], $emailJobs);

            foreach (array_chunk($userRows, 200) as $chunk) {
                User::insert($chunk);
            }

            $emails        = array_column($emailJobs, 'email');
            $insertedUsers = User::whereIn('email', $emails)
                                 ->pluck('id', 'email')
                                 ->mapWithKeys(fn($id, $e) => [strtolower($e) => $id])
                                 ->toArray();

            $alumniRows = [];
            foreach ($emailJobs as $job) {
                $userId = $insertedUsers[strtolower($job['email'])] ?? null;
                if (!$userId) continue;
                $alumniRows[] = [
                    'user_id'        => $userId,
                    'first_name'     => $job['firstName'],
                    'middle_initial' => $job['middleInit'] ?: null,
                    'last_name'      => $job['lastName'],
                    'suffix'         => $job['suffix']    ?: null,
                    'student_id'     => $job['sid'],
                    'email'          => $job['email'],
                    'course_code'    => $job['code'],
                    'course_name'    => $job['courseName'],
                    'batch'          => $job['batchYear'],
                    'status'         => 'VERIFIED',
                    'created_at'     => $job['now'],
                    'updated_at'     => $job['now'],
                ];
            }

            foreach (array_chunk($alumniRows, 200) as $chunk) {
                Alumni::insert($chunk);
            }

            $this->importSuccessCount = count($alumniRows);

            $insertedAlumni = Alumni::whereIn('email', $emails)->get()->keyBy(fn($a) => strtolower($a->email));

            foreach ($emailJobs as $job) {
                try {
                    $alumniModel = $insertedAlumni[strtolower($job['email'])] ?? null;
                    if ($alumniModel) {
                        Mail::to($job['email'])->queue(new \App\Mail\AlumniRegistered($alumniModel, $job['tmp']));
                    }
                } catch (\Exception $me) {
                    Log::warning('Import email queue failed: ' . $me->getMessage());
                }
            }

            $this->importStatus  = 'Done';
            $this->importStep    = 'done';
            $this->importingFile = false;
            $this->coursesList   = Course::orderByDesc('created_at')->get()->toArray();
            $this->importFile    = null;
            $this->resetAlumniFilters();

        } catch (\Exception $e) {
            Log::error('Import error: ' . $e->getMessage());
            $this->importStatus  = 'Error: ' . $e->getMessage();
            $this->importStep    = 'blocked';
            $this->importingFile = false;
        }
    }

    private function _addValidationError(array &$errors, int $max, string $msg): void
    {
        $this->importFailCount++;
        if (count($errors) < $max) $errors[] = $msg;
        elseif (count($errors) === $max) $errors[] = '… (additional errors truncated)';
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

    public function registerAlumni(): void
    {
        $this->alumniErrors  = [];
        $this->alumniSuccess = '';
        $this->registeringAlumni = true;

        try {
            if (!$this->validateName(trim($this->regFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->regLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');
            if (trim($this->regMiddleInitial) !== '' && !preg_match('/^[a-zA-Z]{1,2}$/', trim($this->regMiddleInitial)))
                throw new \Exception('Middle initial may only contain 1–2 letters.');
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
            $tmp       = Str::random(10);

            $user = User::create([
                'name'     => $fullName,
                'email'    => $this->regEmail,
                'password' => Hash::make($tmp),
                'role'     => 'alumni',
            ]);

            $alumni = Alumni::create([
                'user_id'        => $user->id,
                'first_name'     => trim($this->regFirstName),
                'middle_initial' => trim($this->regMiddleInitial) ?: null,
                'last_name'      => trim($this->regLastName),
                'suffix'         => trim($this->regSuffix) ?: null,
                'student_id'     => $paddedId,
                'email'          => $this->regEmail,
                'course_code'    => $this->regCourseCode,
                'course_name'    => $course->name,
                'batch'          => (int) $this->regYear,
                'status'         => 'VERIFIED',
                'profile_photo'  => $photoPath,
            ]);

            try {
                Mail::to($alumni->email)->send(new \App\Mail\AlumniRegistered($alumni, $tmp));
            } catch (\Exception $e) {
                Log::warning('Email: ' . $e->getMessage());
            }

            $this->alumniSuccess = "Alumni '{$fullName}' registered successfully! Login credentials sent to {$alumni->email}.";
            $this->resetRegAlumniForm();

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

    public function registerOrganizer(): void
    {
        $this->organizerErrors  = [];
        $this->organizerSuccess = '';
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

            $occupied = $this->occupiedColleges();
            if (isset($occupied[$college])) {
                throw new \Exception("College \"{$college}\" already has an active organizer ({$occupied[$college]}). Only one organizer per college is allowed.");
            }

            $this->orgDept = $college;

            $this->validate([
                'orgFirstName'     => ['required', 'string', 'max:100'],
                'orgLastName'      => ['required', 'string', 'max:100'],
                'orgMiddleInitial' => ['nullable', 'string', 'max:2', 'regex:/^[a-zA-Z]+$/'],
                'orgSuffix'        => ['nullable', 'string', 'max:10'],
                'orgTeacherId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:organizer,id_number'],
                'orgEmail'         => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
                'orgCollegeSelect' => ['required', 'string'],
                'orgPhoto'         => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ], [
                'orgTeacherId.unique'       => 'This Teacher ID is already registered.',
                'orgTeacherId.regex'        => 'Teacher ID must be 1–8 digits (numbers only).',
                'orgEmail.unique'           => 'This email address is already taken.',
                'orgCollegeSelect.required' => 'Please select a college.',
                'orgPhoto.max'              => 'Profile photo must not exceed 5MB.',
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
                'user_id'        => $user->id,
                'first_name'     => trim($this->orgFirstName),
                'middle_initial' => trim($this->orgMiddleInitial) ?: null,
                'last_name'      => trim($this->orgLastName),
                'suffix'         => trim($this->orgSuffix) ?: null,
                'email'          => $this->orgEmail,
                'id_number'      => $paddedId,
                'department'     => $college,
                'profile_photo'  => $photoPath,
                'status'         => 'ACTIVE',
            ]);

            try {
                Mail::to($organizer->email)->send(new \App\Mail\OrganizerRegistered($organizer, $tmp));
            } catch (\Exception $e) {
                Log::warning('Email: ' . $e->getMessage());
            }

            $this->organizerSuccess = "Organizer '{$fullName}' registered successfully! Login credentials sent to {$organizer->email}.";
            $this->resetOrgForm();

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
                $course  = Course::findOrFail($this->editingCourseId);
                $oldCode = $course->code;
                $oldName = $course->name;
                $course->update(['code' => $code, 'name' => $name]);
                if ($oldCode !== $code || $oldName !== $name) {
                    Alumni::where('course_code', $oldCode)->update(['course_code' => $code, 'course_name' => $name]);
                }
            } else {
                Course::create(['code' => $code, 'name' => $name]);
            }
            $this->coursesList     = Course::orderByDesc('created_at')->get()->toArray();
            $this->resetCourseForm();
            $this->courseAlert     = $this->editingCourseId ? "Course '{$code}' updated!" : "Course '{$code}' added successfully!";
            $this->courseAlertType = 'success';
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
            $course  = Course::findOrFail($this->deleteCourseId);
            $oldCode = $course->code;
            Alumni::where('course_code', $oldCode)->update(['course_code' => null, 'course_name' => null]);
            $course->delete();
            $this->coursesList      = Course::orderByDesc('created_at')->get()->toArray();
            $this->deleteCourseId   = null;
            $this->deleteCourseName = '';
            $this->courseAlert      = "Course '{$oldCode}' deleted!";
            $this->courseAlertType  = 'success';
            $this->activeModal      = 'manageCourses';
        } catch (\Exception $e) {
            $this->courseAlert     = 'Failed to delete.';
            $this->courseAlertType = 'error';
            $this->activeModal     = 'manageCourses';
        } finally {
            $this->deletingCourse = false;
        }
    }

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
        if (!$name) { $this->orgCourseAlert = 'College name is required.'; $this->orgCourseAlertType = 'error'; return; }
        if (isset($this->orgCoursesList[$name])) { $this->orgCourseAlert = "College '{$name}' already exists."; $this->orgCourseAlertType = 'error'; return; }
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
        if (!$newName) { $this->orgCourseAlert = 'New college name is required.'; $this->orgCourseAlertType = 'error'; return; }
        if ($newName === $oldName) { $this->cancelRenamingCollege(); return; }
        if (isset($this->orgCoursesList[$newName])) { $this->orgCourseAlert = "College \"{$newName}\" already exists."; $this->orgCourseAlertType = 'error'; return; }

        try {
            Course::where('college', $oldName)->update(['college' => $newName]);
            Organizer::where('department', $oldName)->update(['department' => $newName]);
            $this->cancelRenamingCollege();
            $this->loadOrgCourses();
            $this->coursesList        = Course::orderByDesc('created_at')->get()->toArray();
            $this->orgCourseAlert     = "College renamed to \"{$newName}\" successfully!";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed to rename: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        }
    }

    public function saveCollegeCourses(): void
    {
        $this->savingOrgCourse = true;
        $college = trim($this->orgAddingToCollege ?? '');
        if (!$college) { $this->orgCourseAlert = 'College name missing.'; $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }
        if (empty($this->orgSelectedCourseCodes)) { $this->orgCourseAlert = 'Select at least one course.'; $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }

        try {
            Course::where('college', $college)->whereNotIn('code', $this->orgSelectedCourseCodes)->update(['college' => null]);
            Course::whereIn('code', $this->orgSelectedCourseCodes)->update(['college' => $college]);
            $count                        = count($this->orgSelectedCourseCodes);
            $this->orgAddingToCollege     = null;
            $this->orgSelectedCourseCodes = [];
            $this->loadOrgCourses();
            $this->coursesList        = Course::orderByDesc('created_at')->get()->toArray();
            $this->orgCourseAlert     = "College '{$college}' saved with {$count} department(s)!";
            $this->orgCourseAlertType = 'success';
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
            $deleted = $this->deleteOrgCollegeName;
            $this->deleteOrgCollegeName = null;
            $this->deleteOrgCourseName  = '';
            $this->loadOrgCourses();
            $this->coursesList        = Course::orderByDesc('created_at')->get()->toArray();
            $this->orgCourseAlert     = "College '{$deleted}' removed successfully!";
            $this->orgCourseAlertType = 'success';
            $this->activeModal        = 'manageOrgCourses';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed to delete college.';
            $this->orgCourseAlertType = 'error';
            $this->activeModal        = 'manageOrgCourses';
        } finally {
            $this->deletingOrgCourse = false;
        }
    }

    public function confirmToggleOrganizerStatus(int $id, string $action): void
    {
        try {
            $organizer = Organizer::findOrFail($id);
            $this->pendingToggleId     = $id;
            $this->pendingToggleAction = $action;
            $this->pendingToggleName   = $organizer->getFullName();
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
            $this->flash('success', "{$organizer->getFullName()} has been {$verb}.");
        } catch (\Exception $e) {
            $this->flash('error', 'Could not update status: ' . $e->getMessage());
        } finally {
            $this->pendingToggleId     = null;
            $this->pendingToggleAction = '';
            $this->pendingToggleName   = '';
            $this->activeModal         = '';
        }
    }

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

<div>

{{-- ═══════════════════════════════════════════════════════
     GLOBAL STYLES
     ═══════════════════════════════════════════════════════ --}}
<style>
    :root {
        --brand:       #7a3f91;
        --brand-dark:  #5e2f72;
        --brand-light: #9b5bb0;
        --brand-50:    #f5eef9;
        --brand-100:   #e9d5f3;
        --brand-200:   #d4aaeb;
    }

    /* ── Primary button ───────────────────────────────── */
    .btn-brand {
        background-color: var(--brand);
        color: #fff;
        transition: background-color .18s ease, box-shadow .18s ease, transform .1s ease;
        box-shadow: 0 2px 6px rgba(122,63,145,.30);
    }
    .btn-brand:hover:not(:disabled) {
        background-color: var(--brand-dark);
        box-shadow: 0 4px 14px rgba(122,63,145,.40);
        transform: translateY(-1px);
    }
    .btn-brand:active:not(:disabled) {
        transform: translateY(0);
        box-shadow: 0 2px 6px rgba(122,63,145,.30);
    }
    .btn-brand:disabled { opacity: .55; cursor: not-allowed; }

    /* ── Ghost button ─────────────────────────────────── */
    .btn-ghost {
        background: #fff;
        color: #374151;
        border: 1px solid #e5e7eb;
        transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease, transform .1s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,.06);
    }
    .btn-ghost:hover:not(:disabled) {
        background: #f9fafb;
        border-color: #d1d5db;
        box-shadow: 0 2px 8px rgba(0,0,0,.10);
        transform: translateY(-1px);
    }

    /* ── Danger ghost ─────────────────────────────────── */
    .btn-danger {
        background: #fff;
        color: #dc2626;
        border: 1px solid #fecaca;
        transition: background-color .15s ease, box-shadow .15s ease, transform .1s ease;
        box-shadow: 0 1px 3px rgba(220,38,38,.08);
    }
    .btn-danger:hover:not(:disabled) {
        background: #fef2f2;
        border-color: #f87171;
        box-shadow: 0 2px 8px rgba(220,38,38,.18);
        transform: translateY(-1px);
    }

    /* ── Success ghost ────────────────────────────────── */
    .btn-success {
        background: #fff;
        color: #16a34a;
        border: 1px solid #bbf7d0;
        transition: background-color .15s ease, box-shadow .15s ease, transform .1s ease;
        box-shadow: 0 1px 3px rgba(22,163,74,.08);
    }
    .btn-success:hover:not(:disabled) {
        background: #f0fdf4;
        border-color: #4ade80;
        box-shadow: 0 2px 8px rgba(22,163,74,.18);
        transform: translateY(-1px);
    }

    /* ── View button ──────────────────────────────────── */
    .btn-view {
        background: var(--brand-50);
        color: var(--brand);
        border: 1px solid var(--brand-200);
        transition: background-color .15s ease, box-shadow .15s ease, transform .1s ease;
        box-shadow: 0 1px 3px rgba(122,63,145,.10);
    }
    .btn-view:hover {
        background: var(--brand-100);
        border-color: var(--brand-light);
        box-shadow: 0 2px 8px rgba(122,63,145,.22);
        transform: translateY(-1px);
    }

    /* ── Tab active ───────────────────────────────────── */
    .tab-active {
        background: #fff;
        color: var(--brand);
        border-bottom: 2px solid var(--brand);
        box-shadow: 0 2px 8px rgba(0,0,0,.07);
    }
    .tab-inactive {
        background: transparent;
        color: #6b7280;
    }
    .tab-inactive:hover {
        background: rgba(255,255,255,.6);
        color: var(--brand);
    }

    /* ── Input focus ──────────────────────────────────── */
    .input-brand {
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .input-brand:focus {
        outline: none;
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(122,63,145,.12);
    }

    /* ── Table row hover ──────────────────────────────── */
    .tbl-row {
        transition: background-color .12s ease;
    }
    .tbl-row:hover { background-color: #faf5fc; }

    /* ── Scroll bar ───────────────────────────────────── */
    .scroll-custom::-webkit-scrollbar { width: 5px; height: 5px; }
    .scroll-custom::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
    .scroll-custom::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
    .scroll-custom::-webkit-scrollbar-thumb:hover { background: var(--brand-light); }

    /* ── Loading overlay ──────────────────────────────── */
    .tbl-loading { opacity: .45; pointer-events: none; transition: opacity .2s ease; }

    /* ── Spinner ──────────────────────────────────────── */
    @keyframes spin { to { transform: rotate(360deg); } }
    .spin { animation: spin .7s linear infinite; display: inline-block; }

    /* ── Modal animate ────────────────────────────────── */
    @keyframes modalIn {
        from { opacity: 0; transform: translateY(16px) scale(.97); }
        to   { opacity: 1; transform: translateY(0)     scale(1);   }
    }
    .modal-in { animation: modalIn .22s cubic-bezier(.25,.8,.25,1) both; }

    /* ── Page slide ───────────────────────────────────── */
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: none; } }
    .slide-down { animation: slideDown .4s ease both; }

    /* ── Course check row ─────────────────────────────── */
    .course-row-selected {
        background: var(--brand-50) !important;
        border-color: var(--brand-200) !important;
    }

    /* ── Responsive table helpers ─────────────────────── */
    @media (max-width: 768px) {
        .hide-mobile { display: none !important; }
        .full-mobile { width: 100% !important; }
    }
</style>

{{-- ═══════════════════════════════════════════════════════
     FLASH TOAST
     ═══════════════════════════════════════════════════════ --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,8000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0 scale-100"
     x-transition:leave-end="opacity-0 translate-x-8 scale-95"
     class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-xl shadow-2xl max-w-xs sm:max-w-sm border backdrop-blur-sm"
     :class="{
         'bg-emerald-50 border-emerald-200 text-emerald-800': type==='success',
         'bg-blue-50 border-blue-200 text-blue-800': type==='info',
         'bg-red-50 border-red-200 text-red-800': type==='error'
     }"
     style="display:none">
    <i class="fas mt-0.5 text-base flex-shrink-0"
       :class="{
           'fa-circle-check text-emerald-500': type==='success',
           'fa-circle-info text-blue-500': type==='info',
           'fa-circle-exclamation text-red-500': type==='error'
       }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 leading-snug opacity-90 break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0 ml-1"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ═══════════════════════════════════════════════════════
     PAGE WRAPPER
     ═══════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-6 max-w-screen-2xl mx-auto bg-white">

    {{-- ── HEADER ─────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6 slide-down">
        <div class="flex items-center gap-4">
<div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl flex items-center justify-center shadow-lg shrink-0 bg-[#7a3f91] text-white">
    <i class="fas fa-users"></i>
</div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-800 tracking-tight">Alumni & Organizers</h1>
                <p class="text-gray-500 text-xs sm:text-sm mt-0.5">Manage alumni and organizer records efficiently</p>
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="flex flex-wrap gap-2 shrink-0">
            {{-- Alumni tab buttons --}}
            <div @class(['flex flex-wrap gap-2' => true, 'hidden' => $this->activeTab !== 'alumni'])>
                <button wire:click="openModal('registerAlumni')"
                        class="btn-brand inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm">
                    <i class="fas fa-user-plus text-xs"></i>
                    <span class="hidden sm:inline">Register Alumni</span>
                    <span class="sm:hidden">Register</span>
                </button>
                <button wire:click="openModal('importModal')"
                        class="btn-ghost inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm">
                    <i class="fas fa-file-import text-xs"></i>
                    <span class="hidden sm:inline">Import</span>
                    <span class="sm:hidden"><i class="fas fa-file-import"></i></span>
                </button>
                <button wire:click="openModal('manageCourses')"
                        class="btn-ghost inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm">
                    <i class="fas fa-sliders text-xs"></i>
                    <span class="hidden md:inline">Manage Courses</span>
                    <span class="md:hidden">Courses</span>
                </button>
            </div>
            {{-- Organizer tab buttons --}}
            <div @class(['flex flex-wrap gap-2' => true, 'hidden' => $this->activeTab !== 'organizers'])>
                <button wire:click="openModal('registerOrganizer')"
                        class="btn-brand inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm">
                    <i class="fas fa-users-gear text-xs"></i>
                    <span class="hidden sm:inline">Register Organizer</span>
                    <span class="sm:hidden">Register</span>
                </button>
                <button wire:click="openModal('manageOrgCourses')"
                        class="btn-ghost inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm">
                    <i class="fas fa-building-columns text-xs"></i>
                    <span class="hidden md:inline">Manage Colleges</span>
                    <span class="md:hidden">Colleges</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── TABS ────────────────────────────────────────────── --}}
    <div class="flex gap-1 mb-4 bg-gray-100 p-1 rounded-xl w-fit">
        <button wire:click="switchTab('alumni')"
                class="px-5 sm:px-6 py-2.5 rounded-lg font-semibold text-sm transition-all duration-200 flex items-center gap-2
                    {{ $this->activeTab==='alumni' ? 'tab-active' : 'tab-inactive' }}">
            <i class="fas fa-graduation-cap text-xs"></i>
            Alumni
        </button>
        <button wire:click="switchTab('organizers')"
                class="px-5 sm:px-6 py-2.5 rounded-lg font-semibold text-sm transition-all duration-200 flex items-center gap-2
                    {{ $this->activeTab==='organizers' ? 'tab-active' : 'tab-inactive' }}">
            <i class="fas fa-users-gear text-xs"></i>
            Organizers
        </button>
    </div>

    {{-- ── CARD WRAPPER ────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden"
         style="min-height: 0; height: calc(100vh - 220px);">

        {{-- ═══════════════════════════════════════════════
             ALUMNI TAB
             ═══════════════════════════════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0 overflow-hidden' => true, 'hidden' => $this->activeTab !== 'alumni'])>

            {{-- Filter Bar --}}
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">
                {{-- Search --}}
                <div class="relative flex-1 min-w-[180px] max-w-sm"
                     x-data="{ query: @entangle('alumniSearch').live, timer: null, onInput(e){ clearTimeout(this.timer); this.timer=setTimeout(()=>{ this.query=e.target.value; },80); } }"
                     wire:ignore>
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" :value="query" @input="onInput($event)" placeholder="Search name, ID, email…"
                           class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-800"
                           autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="alumniBatch"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-700 min-w-[110px]">
                    <option value="">All Years</option>
                    @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-700 min-w-[130px]">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-700 min-w-[130px]">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters"
                        class="btn-ghost px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            {{-- Table --}}
            <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
                <div id="alumni-scroll"
                     @scroll.passive="showTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scroll-custom"
                     wire:loading.class="tbl-loading"
                     wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">
                    <table class="w-full border-collapse min-w-[700px]">
                        <thead>
                            <tr class="bg-gray-100 border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Name</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Student ID</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Course</th>
                                <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Year</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider hide-mobile">Email</th>
                                <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Status</th>
                                <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">View</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-400">
                            @forelse($this->alumniRecords as $item)
                            <tr class="tbl-row bg-white">
                                <td class="px-4 sm:px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                             alt="{{ $item->name }}"
                                             class="w-9 h-9 rounded-lg object-cover shrink-0 shadow-sm ring-1 ring-gray-100">
                                        <span class="font-semibold text-gray-800 text-sm leading-snug">{{ $item->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5">
                                    <span class="font-mono text-gray-700 text-sm font-semibold">{{ $item->student_id }}</span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5">
                                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold"
                                          style="background:var(--brand-50);color:var(--brand);">
                                        {{ $item->course_code ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-center">
                                    <span class="font-mono text-gray-700 text-sm font-semibold">{{ $item->batch }}</span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 hide-mobile">
                                    <span class="text-gray-700 font-semibold text-sm">{{ $item->email }}</span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-center">
                                    @php
                                        $sc = match($item->status) {
                                            'VERIFIED' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                            'PENDING'  => 'bg-amber-50 text-amber-700 border border-amber-200',
                                            'REJECTED' => 'bg-red-50 text-red-700 border border-red-200',
                                            default    => 'bg-gray-50 text-gray-600 border border-gray-200'
                                        };
                                    @endphp
                                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold {{ $sc }}">
                                        {{ $item->status }}
                                    </span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-center">
                                    <button wire:click="viewProfile({{ $item->id }},'alumni')"
                                            class="btn-view inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden sm:inline">View</span>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="py-20 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-users text-2xl text-gray-300"></i>
                                        </div>
                                        <p class="font-semibold text-gray-400">No alumni found</p>
                                        <p class="text-sm text-gray-400">Try adjusting filters or register new alumni</p>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Scroll to top --}}
                <button x-show="showTop"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-75"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('alumni-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="btn-brand absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center"
                        style="display:none">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            {{-- Pagination Footer --}}
            <div class="px-4 sm:px-6 py-3.5 border-t border-gray-100 bg-[#2b0d3e] shrink-0 shadow-[0_-1px_4px_rgba(0,0,0,0.04)]">
                @php
                    $total=$this->alumniRecords->total();
                    $pp=$this->alumniRecords->perPage();
                    $cp=$this->alumniRecords->currentPage();
                    $from=$total>0?($cp-1)*$pp+1:0;
                    $to=min($cp*$pp,$total);
                @endphp
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <p class="text-white text-xs sm:text-sm">
                        Showing <span class="font-bold text-white">{{ $from }}–{{ $to }}</span>
                        of <span class="font-bold text-white">{{ $total }}</span> records
                    </p>
                    <div class="flex items-center gap-1.5">
                        @if($this->alumniRecords->onFirstPage())
                            <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('alumniPage')" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">← Prev</button>
                        @endif

                        <span class="px-3 py-2 text-gray-600 text-xs sm:text-sm font-semibold bg-white border border-gray-200 rounded-lg shadow-sm">
                            {{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}
                        </span>

                        @if($this->alumniRecords->hasMorePages())
                            <button wire:click="nextPage('alumniPage')" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">Next →</button>
                        @else
                            <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════
             ORGANIZERS TAB
             ═══════════════════════════════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0 overflow-hidden' => true, 'hidden' => $this->activeTab !== 'organizers'])>

            {{-- Filter Bar --}}
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">
                <div class="relative flex-1 min-w-[180px] max-w-sm" wire:ignore
                     x-data="{ q:'', timer:null, init(){ this.q=$wire.orgSearch??''; $wire.$watch('orgSearch',val=>{ if(val!==this.q) this.q=val; }); } }">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" x-model="q"
                           @input.debounce.80ms="$wire.set('orgSearch',q)"
                           placeholder="Search name, ID, email..."
                           class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-800"
                           autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="orgCollege"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-700 min-w-[140px]">
                    <option value="">All Colleges</option>
                    @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort"
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white input-brand text-gray-700 min-w-[130px]">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters"
                        class="btn-ghost px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5">
                    <i class="fas fa-rotate-left text-xs"></i>
                    <span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            {{-- Table --}}
            <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
                <div id="org-scroll"
                     @scroll.passive="showTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scroll-custom"
                     wire:loading.class="tbl-loading"
                     wire:target="orgSearch,orgCollege,orgSort,resetOrgFilters,executeToggleOrganizerStatus">
                    <table class="w-full border-collapse min-w-[700px]">
                        <thead>
                            <tr class="bg-gray-100 border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Name</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Teacher ID</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider hide-mobile">Email</th>
                                <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">College</th>
                                <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Status</th>
                                <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-400">
                            @forelse($this->organizerRecords as $item)
                            <tr class="tbl-row bg-white">
                                <td class="px-4 sm:px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                             alt="{{ $item->name }}"
                                             class="w-9 h-9 rounded-lg object-cover shrink-0 shadow-sm ring-1 ring-gray-100">
                                        <span class="font-semibold text-gray-800 text-sm">{{ $item->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5">
                                    <span class="font-mono text-gray-700 text-sm font-semibold">{{ $item->id_number }}</span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 hide-mobile">
                                    <span class="text-gray-700 text-sm font-semibold">{{ $item->email }}</span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5">
                                    @php
                                        $dept        = $item->department;
                                        $directMatch = \App\Models\Course::where('college', $dept)->exists();
                                        $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code', $dept)->value('college') ?? $dept);
                                        $deptCodes   = \App\Models\Course::where('college', $collegeName)->orderBy('code')->pluck('code')->toArray();
                                    @endphp
                                    <span class="block font-semibold text-gray-800 text-sm leading-snug">{{ $collegeName }}</span>
                                    @if(count($deptCodes))
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach($deptCodes as $deptCode)
                                            <span class="text-xs font-mono font-bold" style="color:var(--brand);">{{ $deptCode }}</span>
                                            @if(!$loop->last)<span class="text-gray-300 text-xs">·</span>@endif
                                        @endforeach
                                    </div>
                                    @endif
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-center">
                                    @php
                                        $sc = match($item->status) {
                                            'ACTIVE'    => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                            'INACTIVE'  => 'bg-amber-50 text-amber-700 border border-amber-200',
                                            'SUSPENDED' => 'bg-red-50 text-red-700 border border-red-200',
                                            default     => 'bg-gray-50 text-gray-600 border border-gray-200'
                                        };
                                    @endphp
                                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold {{ $sc }}">
                                        {{ $item->status }}
                                    </span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-center">
                                    <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                        <button wire:click="viewProfile({{ $item->id }},'organizer')"
                                                class="btn-view inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                            <i class="fas fa-eye text-xs"></i>
                                            <span class="hidden lg:inline">View</span>
                                        </button>
                                        @if($item->status === 'ACTIVE')
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }},'deactivate')"
                                                    class="btn-danger inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                                <i class="fas fa-ban text-xs"></i>
                                                <span class="hidden lg:inline">Deactivate</span>
                                            </button>
                                        @else
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }},'activate')"
                                                    class="btn-success inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-bold">
                                                <i class="fas fa-circle-check text-xs"></i>
                                                <span class="hidden lg:inline">Activate</span>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="py-20 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-users-gear text-2xl text-gray-300"></i>
                                        </div>
                                        <p class="font-semibold text-gray-400">No organizers found</p>
                                        <p class="text-sm text-gray-400">Register an organizer to get started</p>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <button x-show="showTop"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-75"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('org-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="btn-brand absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center"
                        style="display:none">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            {{-- Pagination Footer --}}
            <div class="px-4 sm:px-6 py-3.5 border-t border-gray-100 bg-[#2b0d3e] shrink-0 shadow-[0_-1px_4px_rgba(0,0,0,0.04)]">
                @php
                    $total=$this->organizerRecords->total();
                    $pp=$this->organizerRecords->perPage();
                    $cp=$this->organizerRecords->currentPage();
                    $from=$total>0?($cp-1)*$pp+1:0;
                    $to=min($cp*$pp,$total);
                @endphp
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <p class="text-white text-xs sm:text-sm">
                        Showing <span class="font-bold text-white">{{ $from }}–{{ $to }}</span>
                        of <span class="font-bold text-white">{{ $total }}</span> records
                    </p>
                    <div class="flex items-center gap-1.5">
                        @if($this->organizerRecords->onFirstPage())
                            <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                        @else
                            <button wire:click="previousPage('orgPage')" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">← Prev</button>
                        @endif

                        <span class="px-3 py-2 text-gray-600 text-xs sm:text-sm font-semibold bg-white border border-gray-200 rounded-lg shadow-sm">
                            {{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}
                        </span>

                        @if($this->organizerRecords->hasMorePages())
                            <button wire:click="nextPage('orgPage')" class="btn-brand px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold">Next →</button>
                        @else
                            <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>{{-- end card --}}
</div>{{-- end page wrapper --}}


{{-- ═══════════════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════════════ --}}

{{-- shared modal backdrop + wrapper macro --}}
@php
function modalWrap(string $size = 'max-w-2xl'): string {
    return "fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto";
}
@endphp

{{-- ── REGISTER ALUMNI ───────────────────────────────────────── --}}
@if($activeModal==='registerAlumni')
<div class="{{ modalWrap() }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-auto modal-in">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl" style="background:var(--brand);">
            <h2 class="text-xl sm:text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-user-plus"></i> Register Alumni
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>

        {{-- Success --}}
        @if($alumniSuccess)
        <div class="mx-6 sm:mx-8 mt-6 flex items-start gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
            <i class="fas fa-circle-check text-emerald-500 mt-0.5 shrink-0"></i>
            <div class="flex-1">
                <p class="font-bold text-emerald-800 text-sm">Registration Successful!</p>
                <p class="text-emerald-700 text-sm mt-0.5">{{ $alumniSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="btn-brand px-3 py-1.5 rounded-lg text-xs font-bold shrink-0">Done</button>
        </div>
        @endif

        {{-- Errors --}}
        @if(count($alumniErrors)>0)
        <div class="bg-red-50 border-b border-red-200 px-6 sm:px-8 py-4">
            <p class="font-bold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($alumniErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $m }}</li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerAlumni" class="p-6 sm:p-8 space-y-5">
            <div class="flex justify-end">
                <button type="button"
                        wire:click="$set('alumniErrors',[])"
                        onclick="this.closest('form').querySelectorAll('input[type=text],input[type=email],input[type=number],select').forEach(el=>{el.value='';el.dispatchEvent(new Event('input'))})"
                        class="btn-ghost inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-500">
                    <i class="fas fa-rotate-left text-xs"></i> Reset Form
                </button>
            </div>

            {{-- Photo --}}
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center cursor-pointer transition hover:border-[#7a3f91] hover:bg-[#f5eef9]"
                     onclick="document.getElementById('regPhotoInput').click()">
                    @if($regPhoto)
                        <img src="{{ $regPhoto->temporaryUrl() }}" class="w-28 h-28 rounded-xl mx-auto mb-3 object-cover shadow-md">
                        <p class="text-sm text-emerald-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                        <p class="text-sm text-gray-600 font-semibold">Click to Upload</p>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                </div>
            </div>

            {{-- Name --}}
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="regFirstName" type="text" placeholder="First Name"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regLastName" type="text" placeholder="Last Name"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <div>
                        <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. A" maxlength="2"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Middle Initial <span class="text-gray-300"></span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr. Sr. III" maxlength="10"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Suffix <span class="text-gray-300"></span></p>
                    </div>
                </div>
            </div>

            {{-- ID + Email --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Student ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric"
                           class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm font-mono input-brand text-gray-800">
                    <p class="text-xs text-gray-700 mt-1 pl-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regEmail" type="email" placeholder="student@example.com"
                           class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                </div>
            </div>

            {{-- Course + Year --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Course <span class="text-red-500">*</span></label>
                    <select wire:model.defer="regCourseCode"
                            class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <option value="">Select Course</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Batch Year <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}" min="1000" max="9999"
                           class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 pt-2">
                <button type="button" wire:click="closeModal"
                        class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerAlumni"
                        class="btn-brand flex-1 px-5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner spin"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check"></i> Register Alumni</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── IMPORT ─────────────────────────────────────────────────── --}}
@if($activeModal==='importModal')
<div class="{{ modalWrap() }}" @keydown.escape.window="$wire.cancelImport()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-auto modal-in">
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl" style="background:var(--brand);">
            <h2 class="text-xl sm:text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-file-import"></i> Import Alumni
            </h2>
            <button wire:click="cancelImport" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>
        <div class="p-6 sm:p-8 space-y-5">

            {{-- UPLOAD --}}
            @if($importStep==='upload')
            <div class="bg-blue-50 border border-blue-200 p-4 rounded-xl text-sm">
                <p class="text-blue-800 font-bold mb-2"><i class="fas fa-circle-info mr-2"></i>Supported: CSV · Excel (.xlsx, .xls)</p>
                <div class="flex flex-wrap gap-1 mb-1">
                    @foreach(['first_name','last_name','middle_initial','student_id','course_code','batch','email'] as $col)
                    <code class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-mono">{{ $col }}</code>
                    @endforeach
                </div>
                <p class="text-blue-500 text-xs mt-1">Optional: <code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">suffix</code></p>
            </div>
            <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center cursor-pointer transition hover:border-[#7a3f91] hover:bg-[#f5eef9]"
                 @click="document.getElementById('importFile').click()">
                @if($importFile)
                    <i class="fas fa-file-circle-check text-4xl text-emerald-500 block mb-3"></i>
                    <p class="text-emerald-700 font-semibold text-sm">{{ $importFile->getClientOriginalName() }}</p>
                    <p class="text-gray-400 text-xs mt-1">Click to change file</p>
                @else
                    <i class="fas fa-file-arrow-up text-4xl text-gray-300 block mb-3"></i>
                    <p class="text-gray-600 font-semibold text-sm">Click to choose file</p>
                    <p class="text-gray-400 text-xs mt-1">CSV or Excel format</p>
                @endif
                <input type="file" id="importFile" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
            </div>
            <div class="flex gap-3">
                <button type="button" wire:click="cancelImport"
                        class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                <button type="button" wire:click="processImportFile"
                        @if(!$importFile) disabled @endif
                        wire:loading.attr="disabled" wire:target="processImportFile"
                        class="btn-brand flex-1 px-5 py-2.5 rounded-xl text-sm font-bold disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner spin"></i> Processing…</span>
                    <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import Now</span>
                </button>
            </div>
            @endif

            {{-- PROCESSING --}}
            @if($importStep==='processing')
            <div>
                <div class="flex justify-between mb-2">
                    <p class="text-gray-700 font-semibold text-sm flex items-center gap-2">
                        <i class="fas fa-spinner spin" style="color:var(--brand);"></i>
                        Validating rows… {{ $importProgress }}/{{ $importTotal }}
                    </p>
                    <span class="text-xs text-gray-400 font-mono">{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-300"
                         style="background:var(--brand);width:{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%"></div>
                </div>
            </div>
            @endif

            {{-- BLOCKED --}}
            @if($importStep==='blocked')
            <div class="flex items-center gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-xmark text-red-600"></i>
                </div>
                <div>
                    <p class="font-bold text-red-800">Import Failed</p>
                    <p class="text-red-600 text-xs mt-0.5">{{ $importStatus }}</p>
                </div>
            </div>
            <button wire:click="resetImportState"
                    class="btn-ghost w-full px-5 py-2.5 rounded-xl text-sm font-bold">
                <i class="fas fa-arrow-left mr-2"></i>Try Again
            </button>
            @endif

            {{-- DONE --}}
            @if($importStep==='done')
            @php $hasNew=$importSuccessCount>0; $hasErrors=$importFailCount>0; $hasDups=$importDuplicateCount>0; @endphp

            <div class="rounded-xl border overflow-hidden {{ $hasNew?'border-emerald-200':'border-amber-200' }}">
                <div class="px-5 py-4 flex items-center gap-3 {{ $hasNew?'bg-emerald-50':'bg-amber-50' }}">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 {{ $hasNew?'bg-emerald-100':'bg-amber-100' }}">
                        <i class="{{ $hasNew?'fas fa-circle-check text-emerald-600':'fas fa-triangle-exclamation text-amber-600' }}"></i>
                    </div>
                    <div>
                        @if($hasNew)
                            <p class="font-bold text-emerald-800">Import Complete</p>
                            <p class="text-emerald-600 text-xs mt-0.5">
                                {{ $importSuccessCount }} record(s) imported
                                @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                                @if($hasErrors) · {{ $importFailCount }} error(s) @endif
                            </p>
                        @else
                            <p class="font-bold text-amber-800">Nothing Imported</p>
                            <p class="text-amber-600 text-xs mt-0.5">All records were duplicates or had errors.</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-2 sm:gap-3">
                @foreach([
                    ['bg-gray-50','border-gray-200','text-gray-700',$importTotal,'Total'],
                    ['bg-emerald-50','border-emerald-200','text-emerald-600',$importSuccessCount,'Imported'],
                    ['bg-amber-50','border-amber-200','text-amber-600',$importDuplicateCount,'Duplicate'],
                    ['bg-red-50','border-red-200','text-red-600',$importFailCount,'Error'],
                ] as [$bg,$border,$clr,$num,$lbl])
                <div class="{{ $bg }} rounded-xl p-3 sm:p-4 border {{ $border }} text-center">
                    <p class="{{ $clr }} text-xl sm:text-2xl font-extrabold">{{ $num }}</p>
                    <p class="text-gray-500 text-xs font-bold mt-1 uppercase tracking-wide">{{ $lbl }}</p>
                </div>
                @endforeach
            </div>

            @if($hasErrors)
            <div class="bg-red-50 border border-red-200 rounded-xl overflow-hidden">
                <div class="px-4 py-3 bg-red-100 border-b border-red-200 flex items-center gap-2">
                    <i class="fas fa-circle-xmark text-red-500 text-sm"></i>
                    <p class="font-bold text-red-800 text-sm">Validation Errors</p>
                    <span class="ml-auto text-xs bg-red-200 text-red-800 px-2 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                </div>
                <ul class="divide-y divide-red-100 overflow-y-auto scroll-custom" style="max-height:200px">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-3 text-xs text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-400 mt-0.5 shrink-0"></i>
                        <span>{{ $err }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="flex gap-3">
                <button wire:click="resetImportState"
                        class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">
                    <i class="fas fa-arrow-left mr-2"></i>Import Another
                </button>
                <button wire:click="closeModal"
                        class="btn-brand flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Done</button>
            </div>
            @endif

        </div>
    </div>
</div>
@endif

{{-- ── MANAGE COURSES ─────────────────────────────────────────── --}}
@if($activeModal==='manageCourses')
<div class="{{ modalWrap() }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-auto modal-in flex flex-col max-h-[92vh]">
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl shrink-0" style="background:var(--brand);">
            <h2 class="text-xl sm:text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-sliders"></i> Manage Courses
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>

        @if($courseAlert)
        <div class="mx-6 sm:mx-8 mt-5 shrink-0 flex items-start gap-3 p-4 rounded-xl border
            {{ $courseAlertType==='success'?'bg-emerald-50 border-emerald-200':'bg-red-50 border-red-200' }}">
            <i class="fas mt-0.5 {{ $courseAlertType==='success'?'fa-circle-check text-emerald-500':'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $courseAlertType==='success'?'text-emerald-800':'text-red-800' }}">{{ $courseAlert }}</p>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-custom px-6 sm:px-8 py-6 space-y-6">
            {{-- Form --}}
            <div class="border border-gray-100 rounded-xl p-5 bg-gray-50 shadow-sm">
                <h3 class="text-sm font-bold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fas fa-{{ $editingCourseId?'pencil':'plus-circle' }}" style="color:var(--brand);"></i>
                    {{ $editingCourseId?'Edit Course':'Add New Course' }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1.5">Course Code</label>
                        <input wire:model.defer="courseCode" type="text" placeholder="e.g. BSIT" maxlength="20"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1.5">Course Name</label>
                        <input wire:model.defer="courseName" type="text" placeholder="e.g. Bachelor of Science in Information Technology" maxlength="100"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                    </div>
                    <div class="flex gap-3 pt-1">
                        @if($editingCourseId)
                        <button wire:click="resetCourseForm"
                                class="btn-ghost flex-1 px-4 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                        @endif
                        <button wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse"
                                class="btn-brand flex-1 px-4 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner spin"></i></span>
                            <span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId?'Update Course':'Add Course' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- List --}}
            <div>
                <h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-book text-gray-400"></i> Courses ({{ count($coursesList) }})
                </h3>
                <div class="space-y-2 max-h-64 overflow-y-auto scroll-custom pr-1">
                    @forelse($coursesList as $c)
                    <div class="flex items-center justify-between p-4 border border-gray-400 rounded-xl bg-white shadow-sm hover:border-gray-200 transition">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-bold text-gray-800 text-sm">{{ $c['code'] }}</p>
                                @if($c['college']??null)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold border"
                                          style="background:var(--brand-50);color:var(--brand);border-color:var(--brand-200);">
                                        <i class="fas fa-building-columns text-xs"></i>{{ $c['college'] }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-50 text-gray-400 border border-gray-200 rounded-full text-xs font-medium">
                                        <i class="fas fa-circle-minus text-xs"></i> No College
                                    </span>
                                @endif
                            </div>
                            <p class="text-gray-500 text-xs mt-0.5">{{ $c['name'] }}</p>
                        </div>
                        <div class="flex gap-1.5 ml-3 shrink-0">
                            <button wire:click="openEditCourse({{ $c['id'] }})"
                                    class="btn-ghost px-2.5 py-1.5 rounded-lg text-xs font-bold text-blue-600 border-blue-100 hover:bg-blue-50 flex items-center gap-1">
                                <i class="fas fa-pencil text-xs"></i> <span class="hidden sm:inline">Edit</span>
                            </button>
                            <button wire:click="confirmDeleteCourse({{ $c['id'] }})"
                                    class="btn-danger px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1">
                                <i class="fas fa-trash text-xs"></i> <span class="hidden sm:inline">Delete</span>
                            </button>
                        </div>
                    </div>
                    @empty
                    <p class="text-center text-gray-400 py-8 text-sm">No courses yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="px-6 sm:px-8 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl shrink-0">
            <button wire:click="closeModal"
                    class="btn-ghost w-full px-5 py-2.5 rounded-xl text-sm font-bold">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COURSE CONFIRM ─────────────────────────────────── --}}
@if($activeModal==='deleteCourseConfirm')
<div class="{{ modalWrap('max-w-sm') }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-auto modal-in">
        <div class="px-6 sm:px-8 py-5 bg-red-50 border-b border-red-100 rounded-t-2xl">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation"></i> Delete Course
            </h2>
        </div>
        <div class="p-6 sm:p-8">
            <p class="text-gray-700 text-sm mb-1">Delete <strong class="text-red-600">{{ $deleteCourseName }}</strong>?</p>
            <p class="text-gray-400 text-xs mb-6">This action cannot be undone.</p>
            <div class="flex gap-3">
                <button wire:click="closeModal" class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                <button wire:click="deleteCourse" wire:loading.attr="disabled" wire:target="deleteCourse"
                        class="flex-1 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="deleteCourse">Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── VIEW PROFILE ──────────────────────────────────────────── --}}
@if($activeModal==='viewProfile' && $viewingProfile)
<div class="{{ modalWrap('max-w-lg') }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg my-auto modal-in">
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl sticky top-0 z-10" style="background:var(--brand);">
            <h2 class="text-lg sm:text-xl font-extrabold text-white flex items-center gap-2">
                <i class="fas {{ $viewingProfileType==='alumni'?'fa-graduation-cap':'fa-users-gear' }}"></i>
                {{ $viewingProfileType==='alumni'?'Alumni':'Organizer' }} Profile
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>
        <div class="p-6 sm:p-8 space-y-5 max-h-[80vh] overflow-y-auto scroll-custom">
            {{-- Avatar row --}}
            <div class="flex items-center gap-4">
                @if($updatingProfilePhoto)
                    <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl object-cover shadow-lg ring-2 ring-[#7a3f91]/20 shrink-0">
                @else
                    <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}"
                         alt="{{ $viewingProfile['name']??'' }}"
                         class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl object-cover shadow-lg ring-2 ring-gray-100 shrink-0">
                @endif
                <div>
                    <p class="text-lg font-extrabold text-gray-800 leading-tight">{{ $viewingProfile['name']??'' }}</p>
                    <p class="text-gray-500 text-sm mt-0.5">{{ $viewingProfile['email'] }}</p>
                    @php
                        $sc = $viewingProfileType==='alumni'
                            ? match($viewingProfile['status']??'') {
                                'VERIFIED'=>'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'PENDING'=>'bg-amber-50 text-amber-700 border-amber-200',
                                'REJECTED'=>'bg-red-50 text-red-700 border-red-200',
                                default=>'bg-gray-50 text-gray-600 border-gray-200'
                              }
                            : match($viewingProfile['status']??'') {
                                'ACTIVE'=>'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'INACTIVE'=>'bg-red-50 text-red-700 border-red-200',
                                'SUSPENDED'=>'bg-amber-50 text-amber-700 border-amber-200',
                                default=>'bg-gray-50 text-gray-600 border-gray-200'
                              };
                    @endphp
                    <span class="inline-block mt-2 px-3 py-1 rounded-full text-xs font-bold border {{ $sc }}">
                        {{ $viewingProfile['status']??'N/A' }}
                    </span>
                </div>
            </div>

            {{-- Fields --}}
            <div class="grid grid-cols-2 gap-3">
                @if($viewingProfileType==='alumni')
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wide mb-1">Student ID</p>
                    <p class="font-bold text-gray-800 font-mono text-sm">{{ $viewingProfile['student_id']??'—' }}</p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wide mb-1">Batch Year</p>
                    <p class="font-bold text-gray-800 text-sm">{{ $viewingProfile['batch']??'—' }}</p>
                </div>
                <div class="col-span-2 rounded-xl p-4 border" style="background:var(--brand-50);border-color:var(--brand-200);">
                    <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:var(--brand);">Course</p>
                    <p class="font-bold text-sm" style="color:var(--brand-dark);">{{ $viewingProfile['course_code']??'—' }}</p>
                    <p class="text-xs mt-0.5" style="color:var(--brand-light);">{{ $viewingProfile['course_name']??'' }}</p>
                </div>
                @else
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wide mb-1">Teacher ID</p>
                    <p class="font-bold text-gray-800 font-mono text-sm">{{ $viewingProfile['id_number']??'—' }}</p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                    <p class="text-xs text-gray-400 font-bold uppercase tracking-wide mb-1">Department</p>
                    <p class="font-bold text-gray-800 text-sm">{{ $viewingProfile['department']??'—' }}</p>
                </div>
                <div class="col-span-2 rounded-xl p-4 border" style="background:var(--brand-50);border-color:var(--brand-200);">
                    <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:var(--brand);">College</p>
                    <p class="font-bold text-sm" style="color:var(--brand-dark);">{{ $this->getCollegeForCourse($viewingProfile['department']??'') }}</p>
                </div>
                @endif
            </div>

            {{-- Photo update --}}
            <div>
                <p class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center cursor-pointer transition hover:border-[#7a3f91] hover:bg-[#f5eef9]"
                     @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-gray-300 block mb-2"></i>
                    <p class="text-gray-600 font-semibold text-sm">{{ $updatingProfilePhoto?'Change Photo':'Click to Upload New Photo' }}</p>
                    <p class="text-xs text-gray-400 mt-1">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="btn-brand w-full mt-3 px-5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner spin"></i> Saving...</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>

            <button wire:click="closeModal" class="btn-ghost w-full px-5 py-2.5 rounded-xl text-sm font-bold">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── REGISTER ORGANIZER ────────────────────────────────────── --}}
@if($activeModal==='registerOrganizer')
<div class="{{ modalWrap() }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-auto modal-in">
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl" style="background:var(--brand);">
            <h2 class="text-xl sm:text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-users-gear"></i> Register Organizer
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>

        @if($organizerSuccess)
        <div class="mx-6 sm:mx-8 mt-6 flex items-start gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
            <i class="fas fa-circle-check text-emerald-500 mt-0.5 shrink-0"></i>
            <div class="flex-1">
                <p class="font-bold text-emerald-800 text-sm">Registration Successful!</p>
                <p class="text-emerald-700 text-sm mt-0.5">{{ $organizerSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="btn-brand px-3 py-1.5 rounded-lg text-xs font-bold shrink-0">Done</button>
        </div>
        @endif

        @if(count($organizerErrors)>0)
        <div class="bg-red-50 border-b border-red-200 px-6 sm:px-8 py-4">
            <p class="font-bold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($organizerErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $m }}</li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerOrganizer" class="p-6 sm:p-8 space-y-5 max-h-[80vh] overflow-y-auto scroll-custom">
            <div class="flex justify-end">
                <button type="button"
                        wire:click="$set('organizerErrors',[])"
                        onclick="this.closest('form').querySelectorAll('input[type=text],input[type=email],select').forEach(el=>{el.value='';el.dispatchEvent(new Event('input'))})"
                        class="btn-ghost inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-500">
                    <i class="fas fa-rotate-left text-xs"></i> Reset Form
                </button>
            </div>

            {{-- Photo --}}
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Profile Photo <span class="font-normal text-gray-400">(Optional)</span></label>
                <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center cursor-pointer transition hover:border-[#7a3f91] hover:bg-[#f5eef9]"
                     onclick="document.getElementById('orgPhotoInput').click()">
                    @if($orgPhoto)
                        <img src="{{ $orgPhoto->temporaryUrl() }}" class="w-28 h-28 rounded-xl mx-auto mb-3 object-cover shadow-md">
                        <p class="text-sm text-emerald-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                        <p class="text-sm text-gray-600 font-semibold">Click to Upload</p>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                </div>
            </div>

            {{-- Name --}}
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="orgFirstName" type="text" placeholder="First Name"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgLastName" type="text" placeholder="Last Name"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <div>
                        <input wire:model.defer="orgMiddleInitial" type="text" placeholder="e.g. A" maxlength="2"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Middle Initial <span class="text-gray-300"></span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgSuffix" type="text" placeholder="e.g. Jr. Sr. III" maxlength="10"
                               class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                        <p class="text-xs text-gray-700 mt-1 pl-1">Suffix <span class="text-gray-300"></span></p>
                    </div>
                </div>
            </div>

            {{-- ID + Email --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Teacher ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgTeacherId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric"
                           class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm font-mono input-brand text-gray-800">
                    <p class="text-xs text-gray-700 mt-1 pl-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgEmail" type="email" placeholder="teacher@example.com"
                           class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                </div>
            </div>

            {{-- College --}}
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">College <span class="text-red-500">*</span></label>
                @if($this->orgDepartmentsGrouped->isEmpty())
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800 flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
                        <span>No colleges configured yet. Set up colleges via <strong>Manage Colleges</strong>.</span>
                    </div>
                @else
                    @php
                        $collegeDeptsMap  = [];
                        $occupiedColleges = $this->occupiedColleges();
                        foreach ($this->orgDepartmentsGrouped as $collegeName => $depts) {
                            $collegeDeptsMap[$collegeName] = $depts->pluck('code')->toArray();
                        }
                    @endphp
                    <div x-data="{ map: {{ Js::from($collegeDeptsMap) }}, get depts(){ return $wire.orgCollegeSelect?(this.map[$wire.orgCollegeSelect]??[]):[]; } }">
                        <select wire:model.live="orgCollegeSelect"
                                class="w-full px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800">
                            <option value=""> Select College </option>
                            @foreach($this->orgDepartmentsGrouped->keys() as $collegeName)
                                @php $isOccupied = isset($occupiedColleges[$collegeName]); @endphp
                                <option value="{{ $collegeName }}" {{ $isOccupied?'disabled':'' }}>
                                    {{ $collegeName }}{{ $isOccupied?' — occupied by '.$occupiedColleges[$collegeName]:'' }}
                                </option>
                            @endforeach
                        </select>

                        @if(count($occupiedColleges)>0)
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($occupiedColleges as $oC => $oN)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 text-red-700 border border-red-200 rounded-lg text-xs font-medium">
                                <i class="fas fa-lock text-xs text-red-400"></i>
                                <strong>{{ $oC }}</strong>
                                <span class="text-red-300">·</span>
                                {{ $oN }}
                            </span>
                            @endforeach
                        </div>
                        @endif

                        <div x-show="depts.length>0" x-cloak class="mt-3">
                            <p class="text-xs text-gray-400 mb-2 font-medium">Departments under this college:</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="code in depts" :key="code">
                                    <span class="px-3 py-1.5 rounded-lg text-xs font-bold font-mono border"
                                          style="background:var(--brand-50);color:var(--brand);border-color:var(--brand-200);" x-text="code"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" wire:click="closeModal"
                        class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerOrganizer"
                        class="btn-brand flex-1 px-5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner spin"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear"></i> Register Organizer</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── MANAGE COLLEGES ────────────────────────────────────────── --}}
@if($activeModal==='manageOrgCourses')
<div class="{{ modalWrap() }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-auto modal-in flex flex-col max-h-[92vh]">
        <div class="flex items-center justify-between px-6 sm:px-8 py-5 rounded-t-2xl shrink-0" style="background:var(--brand);">
            <h2 class="text-xl sm:text-2xl font-extrabold text-white flex items-center gap-3">
                <i class="fas fa-building-columns"></i>
                <span class="hidden sm:inline">Manage Colleges &amp; Departments</span>
                <span class="sm:hidden">Colleges</span>
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white transition text-2xl leading-none">×</button>
        </div>

        @if($orgCourseAlert)
        <div class="mx-6 sm:mx-8 mt-5 shrink-0 flex items-start gap-3 p-4 rounded-xl border
            {{ $orgCourseAlertType==='success'?'bg-emerald-50 border-emerald-200':'bg-red-50 border-red-200' }}">
            <i class="fas mt-0.5 {{ $orgCourseAlertType==='success'?'fa-circle-check text-emerald-500':'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $orgCourseAlertType==='success'?'text-emerald-800':'text-red-800' }}">{{ $orgCourseAlert }}</p>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto scroll-custom px-6 sm:px-8 py-6 space-y-5">

            {{-- Add college input --}}
            @if(!$orgAddingToCollege && !$orgRenamingCollege)
            <div class="border border-gray-100 rounded-xl p-5 bg-gray-50 shadow-sm">
                <h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-plus-circle" style="color:var(--brand);"></i> Add New College
                </h3>
                <div class="flex gap-2">
                    <input wire:model.defer="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                           class="flex-1 px-4 py-2.5 border border-gray-400 rounded-xl text-sm input-brand text-gray-800"
                           @keydown.enter.prevent="$wire.addCollege()">
                    <button wire:click="addCollege"
                            class="btn-brand px-4 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 whitespace-nowrap">
                        <i class="fas fa-plus text-xs"></i>
                        <span class="hidden sm:inline">Add College</span>
                        <span class="sm:hidden">Add</span>
                    </button>
                </div>
                <p class="text-xs text-gray-700 mt-2">After adding, assign which courses/departments belong to it.</p>
            </div>
            @endif

            {{-- Rename college --}}
            @if($orgRenamingCollege)
            <div class="border-2 rounded-xl p-5" style="border-color:var(--brand-200);background:var(--brand-50);">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fas fa-pen-to-square" style="color:var(--brand);"></i>
                    <h3 class="text-sm font-bold" style="color:var(--brand-dark);">Rename College</h3>
                </div>
                <p class="text-xs mb-3" style="color:var(--brand-light);">Current: <strong>{{ $orgRenamingCollege }}</strong></p>
                <div class="flex gap-2">
                    <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                           class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm input-brand text-gray-800 bg-white"
                           @keydown.enter.prevent="$wire.renameCollege()">
                    <button wire:click="cancelRenamingCollege" class="btn-ghost px-3 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                    <button wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                            class="btn-brand px-4 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 whitespace-nowrap">
                        <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner spin"></i></span>
                        <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- Assign departments --}}
            @if($orgAddingToCollege)
            <div class="border-2 rounded-xl p-5" style="border-color:var(--brand-200);background:var(--brand-50);">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold flex items-center gap-2" style="color:var(--brand-dark);">
                            <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege])?'pencil':'plus' }}" style="color:var(--brand);"></i>
                            {{ isset($orgCoursesList[$orgAddingToCollege])?'Edit Departments':'Assign Departments' }}
                        </h3>
                        <p class="text-xs mt-0.5" style="color:var(--brand-light);">College: <strong>{{ $orgAddingToCollege }}</strong></p>
                    </div>
                    <span class="text-xs px-2.5 py-1 rounded-full font-bold"
                          style="background:var(--brand-200);color:var(--brand-dark);">
                        {{ count($orgSelectedCourseCodes) }} selected
                    </span>
                </div>

                @if($this->allCoursesForAssign->count()>0)
                <p class="text-xs text-gray-500 mb-3">Check all courses belonging to this college:</p>
                <div class="space-y-2 max-h-56 overflow-y-auto scroll-custom pr-1 mb-4">
                    @foreach($this->allCoursesForAssign as $c)
                    @php
                        $isSelected   = in_array($c->code, $orgSelectedCourseCodes);
                        $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                        $isTaken      = $otherCollege !== null;
                    @endphp
                    <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition
                        {{ $isTaken ? 'opacity-50 cursor-not-allowed bg-gray-50 border-gray-200'
                            : ($isSelected ? 'bg-white border-[#7a3f91]/40 shadow-sm' : 'bg-white border-gray-100 hover:border-gray-300') }}">
                        <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}"
                               class="w-4 h-4 shrink-0 rounded"
                               style="accent-color:var(--brand);"
                               {{ $isTaken?'disabled':'' }}>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-gray-800 text-sm font-mono">{{ $c->code }}</span>
                                <span class="text-gray-500 text-xs">{{ $c->name }}</span>
                            </div>
                            @if($isTaken)
                            <p class="text-xs text-amber-600 mt-0.5"><i class="fas fa-lock mr-1"></i>Assigned to: <em>{{ $otherCollege }}</em></p>
                            @endif
                        </div>
                        @if($isSelected && !$isTaken)
                        <i class="fas fa-circle-check shrink-0 text-lg" style="color:var(--brand);"></i>
                        @endif
                    </label>
                    @endforeach
                </div>
                @else
                <div class="text-center py-6">
                    <i class="fas fa-book text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-gray-400 text-sm">No courses available. Add courses via <strong>Manage Courses</strong>.</p>
                </div>
                @endif

                <div class="flex gap-3">
                    <button wire:click="cancelAddingCourses"
                            class="btn-ghost flex-1 px-4 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                    <button wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                            class="btn-brand flex-1 px-4 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner spin"></i> Saving...</span>
                        <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- College list --}}
            <div>
                <h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-list text-gray-400"></i>
                    Colleges &amp; Departments
                    <span class="ml-auto text-xs font-bold text-gray-700 bg-gray-100 px-2.5 py-1 rounded-full border border-gray-200">
                        {{ count($orgCoursesList) }} {{ count($orgCoursesList)===1?'college':'colleges' }}
                    </span>
                </h3>
                @if(count($orgCoursesList)===0)
                <div class="text-center py-10 border-2 border-dashed border-gray-100 rounded-xl">
                    <i class="fas fa-building-columns text-4xl text-gray-200 block mb-3"></i>
                    <p class="text-gray-400 font-semibold text-sm">No colleges yet</p>
                    <p class="text-gray-300 text-xs mt-1">Add a college above to get started</p>
                </div>
                @else
                <div class="space-y-3">
                    @foreach($orgCoursesList as $college => $departments)
                    @php $collegeOccupied=$this->occupiedColleges(); $collegeOrg=$collegeOccupied[$college]??null; @endphp
                    <div class="border border-gray-400 rounded-xl overflow-hidden shadow-sm">
                        <div class="flex items-center justify-between px-4 py-3" style="background:var(--brand-50);">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                                     style="background:var(--brand-100);">
                                    <i class="fas fa-building-columns text-xs" style="color:var(--brand);"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-bold text-sm" style="color:var(--brand-dark);">{{ $college }}</p>
                                        @if($collegeOrg)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold">
                                                <i class="fas fa-circle-check text-xs text-emerald-500"></i>{{ $collegeOrg }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-50 text-gray-400 border border-gray-200 rounded-full text-xs font-medium">
                                                <i class="fas fa-circle-minus text-xs"></i> No Organizer
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-xs mt-0.5" style="color:var(--brand-light);">{{ count($departments) }} department{{ count($departments)!==1?'s':'' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-1.5">
                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')"
                                        class="btn-view px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-pen-to-square text-xs"></i>
                                    <span class="hidden sm:inline">Rename</span>
                                </button>
                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                        class="btn-view px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-pencil text-xs"></i>
                                    <span class="hidden sm:inline">Depts</span>
                                </button>
                                @endif
                                <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')"
                                        class="btn-danger px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-trash text-xs"></i>
                                    <span class="hidden sm:inline">Delete</span>
                                </button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-50">
                            @foreach($departments as $dept)
                            <div class="flex items-center px-4 py-3 bg-white">
                                <span class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold shrink-0"
                                      style="background:var(--brand-50);color:var(--brand);">
                                    {{ strtoupper(substr($dept['code'],0,2)) }}
                                </span>
                                <div class="ml-3">
                                    <p class="font-bold text-gray-800 text-sm">{{ $dept['code'] }}</p>
                                    <p class="text-gray-700 text-xs">{{ $dept['name'] }}</p>
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

        <div class="px-6 sm:px-8 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl shrink-0">
            <button wire:click="closeModal" class="btn-ghost w-full px-5 py-2.5 rounded-xl text-sm font-bold">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COLLEGE CONFIRM ────────────────────────────────── --}}
@if($activeModal==='deleteOrgCollegeConfirm')
<div class="{{ modalWrap('max-w-sm') }}">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-auto modal-in">
        <div class="px-6 sm:px-8 py-5 bg-red-50 border-b border-red-100 rounded-t-2xl">
            <h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation"></i> Delete College
            </h2>
        </div>
        <div class="p-6 sm:p-8">
            <p class="text-gray-700 text-sm mb-1">Remove <strong class="text-red-600">{{ $deleteOrgCourseName }}</strong>?</p>
            <p class="text-gray-400 text-xs mb-6">
                <i class="fas fa-circle-info mr-1"></i>Courses will be unassigned but <strong>not deleted</strong>.
            </p>
            <div class="flex gap-3">
                <button wire:click="openModal('manageOrgCourses')"
                        class="btn-ghost flex-1 px-5 py-2.5 rounded-xl text-sm font-bold">Cancel</button>
                <button wire:click="deleteOrgCollege" wire:loading.attr="disabled" wire:target="deleteOrgCollege"
                        class="flex-1 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteOrgCollege"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="deleteOrgCollege">Delete College</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── TOGGLE ORGANIZER STATUS ───────────────────────────────── --}}
@if($activeModal==='toggleOrganizerConfirm')
<div class="{{ modalWrap('max-w-sm') }}" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-auto modal-in">
        <div class="p-6 sm:p-8">
            <div class="flex items-center gap-4 mb-5">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0
                    {{ $pendingToggleAction==='deactivate'?'bg-red-100':'bg-emerald-100' }}">
                    <i class="text-lg {{ $pendingToggleAction==='deactivate'?'fas fa-ban text-red-600':'fas fa-circle-check text-emerald-600' }}"></i>
                </div>
                <div>
                    <p class="font-extrabold text-gray-800 text-lg">
                        {{ $pendingToggleAction==='deactivate'?'Deactivate Organizer?':'Activate Organizer?' }}
                    </p>
                    <p class="text-sm text-gray-400 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-gray-500 mb-6">
                @if($pendingToggleAction==='deactivate')
                    This organizer will no longer be able to log in. You can reactivate them anytime.
                @else
                    This organizer will regain login access.
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="closeModal"
                        class="btn-ghost flex-1 px-4 py-3 rounded-xl font-bold">Cancel</button>
                <button wire:click="executeToggleOrganizerStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleOrganizerStatus"
                        class="flex-1 px-4 py-3 rounded-xl font-bold transition flex items-center justify-center gap-2
                            {{ $pendingToggleAction==='deactivate'?'bg-red-600 hover:bg-red-700 text-white':'bg-emerald-600 hover:bg-emerald-700 text-white' }}">
                    <span wire:loading wire:target="executeToggleOrganizerStatus"><i class="fas fa-spinner spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggleOrganizerStatus">
                        {{ $pendingToggleAction==='deactivate'?'Yes, Deactivate':'Yes, Activate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>