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
        foreach (Course::orderByDesc('updated_at')
            ->orderBy('code')
            ->get() as $c) {
            $college = $c->college ?? null;
            if ($college) {
                $grouped[$college][] = $c->toArray();
            }
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

    // ─────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────
    // Modal / Tab
    // ─────────────────────────────────────────────────────

    public function switchTab(string $tab): void { $this->activeTab = $tab; }

    public function openModal(string $modal): void
    {
        if ($modal === 'importModal') {
            $this->resetImportState();
        }
        if ($modal === 'manageOrgCourses') {
            $this->loadOrgCourses();
            $this->resetOrgCourseForm();
        }
        if ($modal === 'registerAlumni') {
            $this->alumniSuccess = '';
            $this->alumniErrors  = [];
        }
        if ($modal === 'registerOrganizer') {
            $this->organizerSuccess = '';
            $this->organizerErrors  = [];
        }
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

            // ── Validate required columns ──────────────────────────────────
            if (!in_array('first_name', $header, true) || !in_array('last_name', $header, true)) {
                throw new \Exception('Missing required columns: "first_name" and "last_name" must both be present.');
            }
            foreach (['middle_initial', 'student_id', 'course_code', 'batch', 'email'] as $req) {
                if (!in_array($req, $header, true))
                    throw new \Exception("Missing required column: \"{$req}\".");
            }

            $this->importTotal = count($rows) - 1;

            // ── Pre-load lookups into memory (zero per-row DB calls) ───────
            $courseMap            = Course::pluck('name', 'code')
                                        ->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])->toArray();
            $existingAlumniEmails = Alumni::pluck('email')
                                        ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();
            $existingAlumniIds    = Alumni::pluck('student_id')
                                        ->mapWithKeys(fn($id) => [$id => true])->toArray();
            $existingUserEmails   = User::pluck('email')
                                        ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            // ── Generate ONE shared password placeholder per import ────────
            // bcrypt × 500 = the reason it took 20 mins. One hash for all,
            // each alumni gets their own $tmp plain-text sent via email.
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
                // Handle Excel float IDs e.g. 12345.0 → "12345"
                $rawId  = rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.');
                $rawId  = preg_replace('/\..*$/', '', $rawId); // strip any decimal
                $code   = strtoupper(trim($row['course_code'] ?? ''));
                // Handle Excel float batch e.g. 2023.0 → "2023"
                $year   = (string)(int)($row['batch'] ?? 0);
                $label  = "Row " . ($i + 1) . ($fullName ? " ({$fullName})" : '');

                // ── Name validation ────────────────────────────────────────
                if (!$firstName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name contains invalid characters."); continue; }
                if (!$lastName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name contains invalid characters."); continue; }

                // ── Middle initial (required) ──────────────────────────────
                if ($middleInitial === '') { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle initial is required."); continue; }
                if (!preg_match('/^[a-zA-Z]{1,2}$/', $middleInitial)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle initial must be 1–2 letters."); continue; }

                // ── Email ──────────────────────────────────────────────────
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Email \"{$email}\" is not valid."); continue; }
                if (isset($existingAlumniEmails[$email]) || isset($seenEmailsInFile[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }
                // Track orphaned user accounts — batch delete later, not per-row
                if (isset($existingUserEmails[$email])) {
                    $orphanEmailsToNuke[] = $email;
                    unset($existingUserEmails[$email]);
                }

                // ── Student ID ─────────────────────────────────────────────
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

                // ── Course code ────────────────────────────────────────────
                if (!isset($courseMap[$code])) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Course code \"{$code}\" does not exist."); continue; }

                // ── Batch year ─────────────────────────────────────────────
                $batchYear = (int) $year;
                if ($batchYear < 1000 || $batchYear > 9999) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Batch \"{$year}\" must be a 4-digit year."); continue; }

                // ── Valid row — generate unique tmp password per alumni ─────
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

            // ── Batch delete orphaned users in ONE query ───────────────────
            if (!empty($orphanEmailsToNuke)) {
                User::whereIn('email', $orphanEmailsToNuke)->delete();
            }

            // ── Build user rows — ONE shared hash, no bcrypt loop ──────────
            $userRows = array_map(fn($job) => [
                'name'       => $job['fullName'],
                'email'      => $job['email'],
                'password'   => $sharedHash,   // ← single hash, massively faster
                'role'       => 'alumni',
                'created_at' => $job['now'],
                'updated_at' => $job['now'],
            ], $emailJobs);

            // ── Bulk insert users in chunks of 200 ─────────────────────────
            foreach (array_chunk($userRows, 200) as $chunk) {
                User::insert($chunk);
            }

            // ── Fetch inserted user IDs in ONE query ───────────────────────
            $emails        = array_column($emailJobs, 'email');
            $insertedUsers = User::whereIn('email', $emails)
                                 ->pluck('id', 'email')
                                 ->mapWithKeys(fn($id, $e) => [strtolower($e) => $id])
                                 ->toArray();

            // ── Build alumni rows ──────────────────────────────────────────
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

            // ── Bulk insert alumni in chunks of 200 ────────────────────────
            foreach (array_chunk($alumniRows, 200) as $chunk) {
                Alumni::insert($chunk);
            }

            $this->importSuccessCount = count($alumniRows);

            // ── Queue emails — fire-and-forget after response ──────────────
            // Fetch just-inserted alumni models for email sending
            $insertedAlumni = Alumni::whereIn('email', $emails)
                                    ->get()
                                    ->keyBy(fn($a) => strtolower($a->email));

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

    // ─────────────────────────────────────────────────────
    // Alumni Registration
    // ─────────────────────────────────────────────────────

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

            $fullName = $this->buildFullName(
                $this->regFirstName, $this->regMiddleInitial, $this->regLastName, $this->regSuffix
            );

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

    // ─────────────────────────────────────────────────────
    // Organizer Registration
    // ─────────────────────────────────────────────────────

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

            $fullName = $this->buildFullName(
                $this->orgFirstName, $this->orgMiddleInitial, $this->orgLastName, $this->orgSuffix
            );

            $college = trim($this->orgCollegeSelect);
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

    // ─────────────────────────────────────────────────────
    // Organizer Status Toggle
    // ─────────────────────────────────────────────────────

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

        {{-- ═══════════════════════════════════════════════════════════════
             ALUMNI TAB
             ═══════════════════════════════════════════════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0' => true, 'hidden' => $this->activeTab !== 'alumni'])>
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm"
                     x-data="{ query: @entangle('alumniSearch').live, timer: null, onInput(e) { clearTimeout(this.timer); this.timer = setTimeout(() => { this.query = e.target.value; }, 80); } }"
                     wire:ignore>
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input type="text" :value="query" @input="onInput($event)" placeholder="Search name, ID, email…"
                           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus" autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="alumniBatch" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Years</option>
                    @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <div class="relative flex-1 min-h-0" x-data="{ showScrollTop: false }">
                <div id="alumni-table-scroll" @scroll.passive="showScrollTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
                     wire:loading.class="tbl-loading" wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">
                    <table class="w-full border-separate border-spacing-0">
                        <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
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
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}" class="w-10 h-10 rounded-lg object-cover shrink-0">
                                        <span class="font-semibold text-slate-900 text-sm block">{{ $item->name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->student_id }}</span></td>
                                <td class="px-6 py-4"><span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">{{ $item->course_code ?? '—' }}</span></td>
                                <td class="px-6 py-4 text-center"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->batch }}</span></td>
                                <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                                <td class="px-6 py-4 text-center">
                                    @php $sc=match($item->status){'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                                    <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button wire:click="viewProfile({{ $item->id }},'alumni')"
                                            class="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="py-16 text-center">
                                    <i class="fas fa-users text-5xl text-slate-200 block mb-4"></i>
                                    <p class="font-semibold text-slate-400">No alumni found</p>
                                    <p class="text-sm text-slate-400 mt-1">Try adjusting filters or register new alumni</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button x-show="showScrollTop"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-75" x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('alumni-table-scroll').scrollTo({ top: 0, behavior: 'smooth' })"
                        class="absolute bottom-4 right-4 z-20 w-10 h-10 btn-primary rounded-full shadow-lg flex items-center justify-center hover:shadow-xl transition-shadow"
                        style="display:none" title="Back to top">
                    <i class="fas fa-arrow-up text-sm"></i>
                </button>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                <div class="flex items-center justify-between">
                    @php $total=$this->alumniRecords->total(); $pp=$this->alumniRecords->perPage(); $cp=$this->alumniRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                    <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                    <div class="flex gap-2 items-center">
                        @if($this->alumniRecords->onFirstPage()) <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else <button wire:click="previousPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button> @endif
                        <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                        @if($this->alumniRecords->hasMorePages()) <button wire:click="nextPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                        @else <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button> @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════
             ORGANIZERS TAB
             ═══════════════════════════════════════════════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0' => true, 'hidden' => $this->activeTab !== 'organizers'])>
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
                <div class="relative flex-1 min-w-[200px] max-w-sm" wire:ignore
                     x-data="{ q: '', timer: null, init() { this.q = $wire.orgSearch ?? ''; $wire.$watch('orgSearch', val => { if (val !== this.q) this.q = val; }); } }">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input type="text" x-model="q" @input.debounce.80ms="$wire.set('orgSearch', q)" placeholder="Search name, ID, email..."
                           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus" autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="orgCollege" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="">All Colleges</option>
                    @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>

            <div class="relative flex-1 min-h-0" x-data="{ showScrollTop: false }">
                <div id="org-table-scroll" @scroll.passive="showScrollTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
                     wire:loading.class="tbl-loading" wire:target="orgSearch,orgCollege,orgSort,resetOrgFilters,executeToggleOrganizerStatus">
                    <table class="w-full border-separate border-spacing-0">
                        <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Teacher ID</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">College</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($this->organizerRecords as $item)
                            <tr class="table-row-hover">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}" class="w-10 h-10 rounded-lg object-cover shrink-0">
                                        <span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->id_number }}</span></td>
                                <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                                <td class="px-6 py-4">
                                    @php
                                        $dept        = $item->department;
                                        $directMatch = \App\Models\Course::where('college', $dept)->exists();
                                        $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code', $dept)->value('college') ?? $dept);
                                        $deptCodes   = \App\Models\Course::where('college', $collegeName)->orderBy('code')->pluck('code')->toArray();
                                    @endphp
                                    <span class="block font-semibold text-slate-800 text-sm leading-snug">{{ $collegeName }}</span>
                                    @if(count($deptCodes))
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach($deptCodes as $deptCode)
                                            <span class="text-xs font-mono font-semibold text-purple-700">{{ $deptCode }}</span>
                                            @if(!$loop->last)<span class="text-slate-300 text-xs">·</span>@endif
                                        @endforeach
                                    </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @php $sc=match($item->status){'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                                    <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button wire:click="viewProfile({{ $item->id }}, 'organizer')"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        @if($item->status === 'ACTIVE')
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }}, 'deactivate')"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                                <i class="fas fa-ban"></i> Deactivate
                                            </button>
                                        @else
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }}, 'activate')"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                                <i class="fas fa-circle-check"></i> Activate
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <i class="fas fa-users-gear text-5xl text-slate-200 block mb-4"></i>
                                    <p class="font-semibold text-slate-400">No organizers found</p>
                                    <p class="text-sm text-slate-400 mt-1">Register an organizer to get started</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button x-show="showScrollTop"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-75" x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('org-table-scroll').scrollTo({ top: 0, behavior: 'smooth' })"
                        class="absolute bottom-4 right-4 z-20 w-10 h-10 btn-primary rounded-full shadow-lg flex items-center justify-center hover:shadow-xl transition-shadow"
                        style="display:none" title="Back to top">
                    <i class="fas fa-arrow-up text-sm"></i>
                </button>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                <div class="flex items-center justify-between">
                    @php $total=$this->organizerRecords->total(); $pp=$this->organizerRecords->perPage(); $cp=$this->organizerRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                    <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                    <div class="flex gap-2 items-center">
                        @if($this->organizerRecords->onFirstPage()) <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                        @else <button wire:click="previousPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button> @endif
                        <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                        @if($this->organizerRecords->hasMorePages()) <button wire:click="nextPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                        @else <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button> @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════════════════════════ --}}

{{-- ── REGISTER ALUMNI ──────────────────────────────────────────────────── --}}
@if($activeModal==='registerAlumni')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-user-plus text-2xl"></i> Register Alumni</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if($alumniSuccess)
        <div class="mx-8 mt-6 flex items-start gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
            <div class="w-9 h-9 bg-emerald-100 rounded-full flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-emerald-600"></i>
            </div>
            <div class="flex-1">
                <p class="font-bold text-emerald-800 text-sm">Registration Successful!</p>
                <p class="text-emerald-700 text-sm mt-0.5">{{ $alumniSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="px-4 py-2 btn-primary rounded-lg text-xs font-semibold shrink-0">Done</button>
        </div>
        @endif

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
            <div class="flex justify-end">
                <button type="button" wire:click="$set('alumniErrors',[])"
                        onclick="this.closest('form').querySelectorAll('input[type=text],input[type=email],input[type=number],select').forEach(el=>{el.value='';el.dispatchEvent(new Event('input'))})"
                        class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-slate-500 hover:text-red-600 hover:bg-red-50 border border-slate-200 hover:border-red-200 rounded-lg transition">
                    <i class="fas fa-rotate-left"></i> Reset Form
                </button>
            </div>
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
                        <input wire:model.defer="regFirstName" type="text" placeholder="First Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regLastName" type="text" placeholder="Last Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3">
                    <div>
                        <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. A" maxlength="2" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial <span class="text-slate-400">(1–2 letters, optional)</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr. Sr. III" maxlength="10" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix <span class="text-slate-400">(optional)</span></p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Student ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">
                    <p class="text-xs text-slate-500 mt-1.5 pl-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regEmail" type="email" placeholder="student@example.com" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Course <span class="text-red-500">*</span></label>
                    <select wire:model.defer="regCourseCode" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <option value="">Select Course</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Batch Year <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}" min="1000" max="9999" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                </div>
            </div>
            <div class="flex gap-4 pt-3">
                <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
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

{{-- ── IMPORT ────────────────────────────────────────────────────────────── --}}
@if($activeModal==='importModal')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelImport()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl modal-animate max-h-[92vh] overflow-y-auto scrollbar-custom">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-file-import text-2xl"></i> Import Alumni</h2>
            <button wire:click="cancelImport" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        <div class="p-8 space-y-5 min-h-[200px]">

            {{-- ── STEP: UPLOAD ─────────────────────────────────────── --}}
            @if($importStep === 'upload')
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg text-sm">
                <p class="text-blue-800 font-semibold mb-2"><i class="fas fa-circle-info mr-2"></i>Supported formats: CSV · Excel (.xlsx, .xls)</p>
                <p class="text-blue-700 text-xs mb-1.5">
                    Required columns:
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">first_name</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">last_name</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">middle_initial</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">student_id</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">course_code</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">batch</code>
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">email</code>
                </p>
                <p class="text-blue-600 text-xs">
                    Optional:
                    <code class="bg-blue-100 px-1.5 py-0.5 rounded mx-0.5 font-mono">suffix</code>
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
                <button type="button" wire:click="cancelImport" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="button" wire:click="processImportFile" @if(!$importFile) disabled @endif
                        wire:loading.attr="disabled" wire:target="processImportFile"
                        class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner spin-icon"></i> Processing…</span>
                    <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import Now</span>
                </button>
            </div>
            @endif

            {{-- ── STEP: PROCESSING ─────────────────────────────────── --}}
            @if($importStep === 'processing')
            <div>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-slate-800 font-semibold text-sm flex items-center gap-2">
                        <i class="fas fa-spinner spin-icon text-purple-600"></i> Validating rows… {{ $importProgress }}/{{ $importTotal }}
                    </p>
                    <span class="text-xs text-slate-500 font-mono">{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                    <div class="h-full rounded-full bg-purple-600 transition-all duration-300" style="width:{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%"></div>
                </div>
            </div>
            @endif

            {{-- ── STEP: BLOCKED (fatal error before processing) ────── --}}
            @if($importStep === 'blocked')
            <div class="flex items-center gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="w-10 h-10 bg-red-200 rounded-full flex items-center justify-center shrink-0"><i class="fas fa-xmark text-red-700 text-lg"></i></div>
                <div class="flex-1">
                    <p class="font-bold text-red-800 text-base">Import Failed</p>
                    <p class="text-red-600 text-xs mt-0.5">{{ $importStatus }}</p>
                </div>
            </div>
            <button type="button" wire:click="resetImportState" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                <i class="fas fa-arrow-left mr-2"></i>Try Again
            </button>
            @endif

            {{-- ── STEP: DONE ───────────────────────────────────────── --}}
            @if($importStep === 'done')
            @php
                $hasNew    = $importSuccessCount > 0;
                $hasErrors = $importFailCount > 0;
                $hasDups   = $importDuplicateCount > 0;
            @endphp

            {{-- Result banner --}}
            <div class="rounded-xl overflow-hidden border {{ $hasNew ? 'border-emerald-200' : 'border-amber-200' }}">
                <div class="px-5 py-4 flex items-center gap-3 {{ $hasNew ? 'bg-emerald-50' : 'bg-amber-50' }}">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 {{ $hasNew ? 'bg-emerald-200' : 'bg-amber-200' }}">
                        <i class="text-lg {{ $hasNew ? 'fas fa-circle-check text-emerald-700' : 'fas fa-triangle-exclamation text-amber-700' }}"></i>
                    </div>
                    <div>
                        @if($hasNew)
                            <p class="font-bold text-emerald-800 text-base">Import Complete</p>
                            <p class="text-emerald-600 text-xs mt-0.5">
                                {{ $importSuccessCount }} record(s) imported successfully
                                @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                                @if($hasErrors) · {{ $importFailCount }} row(s) had errors @endif
                            </p>
                        @else
                            <p class="font-bold text-amber-800 text-base">Nothing Imported</p>
                            <p class="text-amber-600 text-xs mt-0.5">All records were duplicates or had errors.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Stats: Total · Imported · Duplicate · Error --}}
            <div class="grid grid-cols-4 gap-3">
                <div class="bg-slate-50 rounded-xl p-4 border border-slate-200 text-center">
                    <p class="text-slate-700 text-2xl font-bold">{{ $importTotal }}</p>
                    <p class="text-slate-500 text-xs font-semibold mt-1 uppercase tracking-wide">Total</p>
                </div>
                <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-200 text-center">
                    <p class="text-emerald-600 text-2xl font-bold">{{ $importSuccessCount }}</p>
                    <p class="text-emerald-700 text-xs font-semibold mt-1 uppercase tracking-wide">Imported</p>
                </div>
                <div class="bg-amber-50 rounded-xl p-4 border border-amber-200 text-center">
                    <p class="text-amber-600 text-2xl font-bold">{{ $importDuplicateCount }}</p>
                    <p class="text-amber-700 text-xs font-semibold mt-1 uppercase tracking-wide">Duplicate</p>
                </div>
                <div class="bg-red-50 rounded-xl p-4 border border-red-200 text-center">
                    <p class="text-red-600 text-2xl font-bold">{{ $importFailCount }}</p>
                    <p class="text-red-700 text-xs font-semibold mt-1 uppercase tracking-wide">Error</p>
                </div>
            </div>

            {{-- Error list — only shown when there are errors --}}
            @if($hasErrors)
            <div class="bg-red-50 border border-red-200 rounded-xl overflow-hidden">
                <div class="px-4 py-3 bg-red-100 border-b border-red-200 flex items-center gap-2">
                    <i class="fas fa-circle-xmark text-red-500 text-sm"></i>
                    <p class="font-semibold text-red-800 text-sm">Validation Errors</p>
                    <span class="ml-auto text-xs bg-red-200 text-red-800 px-2 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                </div>
                <ul class="divide-y divide-red-100 overflow-y-auto scrollbar-custom" style="max-height:220px">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-3 text-xs text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-400 mt-0.5 shrink-0"></i>
                        <span>{{ $err }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Action buttons --}}
            <div class="flex gap-3">
                <button type="button" wire:click="resetImportState" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Import Another
                </button>
                <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold transition">Done</button>
            </div>
            @endif

        </div>
    </div>
</div>
@endif

{{-- ── MANAGE COURSES ────────────────────────────────────────────────────── --}}
@if($activeModal==='manageCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-sliders text-2xl"></i> Manage Courses</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        @if($courseAlert)
        <div class="mx-8 mt-5 shrink-0 flex items-start gap-3 p-4 rounded-xl border {{ $courseAlertType === 'success' ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200' }}">
            <i class="fas mt-0.5 {{ $courseAlertType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $courseAlertType === 'success' ? 'text-emerald-800' : 'text-red-800' }}">{{ $courseAlert }}</p>
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
                        <input wire:model.defer="courseCode" type="text" placeholder="e.g. BSIT" maxlength="20" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2">Course Name</label>
                        <input wire:model.defer="courseName" type="text" placeholder="e.g. Bachelor of Science in Information Technology" maxlength="100" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                    </div>
                    <div class="flex gap-3 pt-2">
                        @if($editingCourseId)
                        <button type="button" wire:click="resetCourseForm" class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
                        @endif
                        <button type="button" wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse"
                                class="flex-1 px-4 py-2 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner spin-icon"></i></span>
                            <span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId ? 'Update Course' : 'Add Course' }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2"><i class="fas fa-book text-slate-500"></i> Courses ({{ count($coursesList) }})</h3>
                <div class="space-y-2 max-h-64 overflow-y-auto scrollbar-custom pr-2">
                    @forelse($coursesList as $c)
                    @php $belongsToCollege = $c['college'] ?? null; @endphp
                    <div class="flex items-center justify-between p-4 border border-slate-200 rounded-lg bg-white">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-slate-800 text-sm">{{ $c['code'] }}</p>
                                @if($belongsToCollege)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full text-xs font-semibold border border-purple-200"><i class="fas fa-building-columns text-xs"></i>{{ $belongsToCollege }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-slate-100 text-slate-400 rounded-full text-xs font-medium border border-slate-200"><i class="fas fa-circle-minus text-xs"></i> No College</span>
                                @endif
                            </div>
                            <p class="text-slate-600 text-xs mt-1">{{ $c['name'] }}</p>
                        </div>
                        <div class="flex gap-2 ml-4 shrink-0">
                            <button wire:click="openEditCourse({{ $c['id'] }})" class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition font-semibold text-xs border border-blue-200 flex items-center gap-1.5"><i class="fas fa-pencil"></i> Edit</button>
                            <button wire:click="confirmDeleteCourse({{ $c['id'] }})" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </div>
                    @empty
                    <p class="text-center text-slate-500 py-8 text-sm">No courses yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="px-8 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COURSE CONFIRM ─────────────────────────────────────────────── --}}
@if($activeModal==='deleteCourseConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete Course</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-2">Delete <strong class="text-red-600">{{ $deleteCourseName }}</strong>?</p>
            <p class="text-slate-600 text-xs mb-6">This action cannot be undone.</p>
            <div class="flex gap-3">
                <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
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

{{-- ── VIEW PROFILE ──────────────────────────────────────────────────────── --}}
@if($activeModal==='viewProfile' && $viewingProfile)
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
                    <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}" alt="{{ $viewingProfile['name'] ?? '' }}" class="w-40 h-40 rounded-xl object-cover shadow-md shrink-0">
                @endif
                <div>
                    <p class="text-xl font-bold text-slate-800 leading-tight">{{ $viewingProfile['name'] ?? '' }}</p>
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

{{-- ── REGISTER ORGANIZER ───────────────────────────────────────────────── --}}
@if($activeModal==='registerOrganizer')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-users-gear text-2xl"></i> Register Organizer</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>

        @if($organizerSuccess)
        <div class="mx-8 mt-6 flex items-start gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
            <div class="w-9 h-9 bg-emerald-100 rounded-full flex items-center justify-center shrink-0">
                <i class="fas fa-circle-check text-emerald-600"></i>
            </div>
            <div class="flex-1">
                <p class="font-bold text-emerald-800 text-sm">Registration Successful!</p>
                <p class="text-emerald-700 text-sm mt-0.5">{{ $organizerSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="px-4 py-2 btn-primary rounded-lg text-xs font-semibold shrink-0">Done</button>
        </div>
        @endif

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
            <div>
                <label class="block text-sm font-bold text-slate-800 mb-3">College <span class="text-red-500">*</span></label>
                @if($this->orgDepartmentsGrouped->isEmpty())
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
                        <span>No colleges configured yet. Please set up colleges first via <strong>Manage Colleges</strong>.</span>
                    </div>
                @else
                    @php
                        $collegeDeptsMap  = [];
                        $occupiedColleges = $this->occupiedColleges();
                        foreach ($this->orgDepartmentsGrouped as $collegeName => $depts) {
                            $collegeDeptsMap[$collegeName] = $depts->pluck('code')->toArray();
                        }
                    @endphp
                    <div x-data="{ map: {{ Js::from($collegeDeptsMap) }}, get depts() { return $wire.orgCollegeSelect ? (this.map[$wire.orgCollegeSelect] ?? []) : []; } }">
                        <select wire:model.live="orgCollegeSelect" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                            <option value="">— Select College —</option>
                            @foreach($this->orgDepartmentsGrouped->keys() as $collegeName)
                                @php $isOccupied = isset($occupiedColleges[$collegeName]); @endphp
                                <option value="{{ $collegeName }}" {{ $isOccupied ? 'disabled' : '' }}>
                                    {{ $collegeName }}{{ $isOccupied ? ' — occupied by ' . $occupiedColleges[$collegeName] : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if(count($occupiedColleges) > 0)
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($occupiedColleges as $occCollege => $occName)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-50 text-red-700 border border-red-200 rounded-lg text-xs font-medium">
                                <i class="fas fa-lock text-xs text-red-400"></i>
                                <span class="font-semibold">{{ $occCollege }}</span>
                                <span class="text-red-400">·</span>
                                <span>{{ $occName }}</span>
                            </span>
                            @endforeach
                        </div>
                        @endif
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

{{-- ── MANAGE COLLEGES ──────────────────────────────────────────────────── --}}
@if($activeModal==='manageOrgCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
        <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
            <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-building-columns text-2xl"></i> Manage Colleges & Departments</h2>
            <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
        </div>
        @if($orgCourseAlert)
        <div class="mx-8 mt-5 shrink-0 flex items-start gap-3 p-4 rounded-xl border {{ $orgCourseAlertType === 'success' ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200' }}">
            <i class="fas mt-0.5 {{ $orgCourseAlertType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $orgCourseAlertType === 'success' ? 'text-emerald-800' : 'text-red-800' }}">{{ $orgCourseAlert }}</p>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">
            @if(!$orgAddingToCollege && !$orgRenamingCollege)
            <div class="border border-slate-200 rounded-lg p-5 bg-slate-50">
                <h3 class="text-sm font-bold text-slate-800 mb-3 flex items-center gap-2"><i class="fas fa-plus-circle text-purple-600"></i> Add New College</h3>
                <div class="flex gap-3">
                    <input wire:model.defer="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                           class="flex-1 px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"
                           @keydown.enter.prevent="$wire.addCollege()">
                    <button type="button" wire:click="addCollege" class="px-5 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center gap-2 whitespace-nowrap">
                        <i class="fas fa-plus"></i> Add College
                    </button>
                </div>
                <p class="text-xs text-slate-500 mt-2">After adding, select which courses/departments belong to it.</p>
            </div>
            @endif

            @if($orgRenamingCollege)
            <div class="border-2 border-purple-300 rounded-lg p-5 bg-purple-50">
                <div class="flex items-center gap-2 mb-4"><i class="fas fa-pen-to-square text-purple-600"></i><h3 class="text-sm font-bold text-purple-800">Rename College</h3></div>
                <p class="text-xs text-purple-600 mb-3">Current name: <strong>{{ $orgRenamingCollege }}</strong></p>
                <div class="flex gap-3">
                    <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                           class="flex-1 px-4 py-2.5 border border-purple-300 rounded-lg text-sm input-focus text-slate-800 bg-white"
                           @keydown.enter.prevent="$wire.renameCollege()">
                    <button type="button" wire:click="cancelRenamingCollege" class="px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                            class="px-5 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center gap-2 whitespace-nowrap">
                        <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner spin-icon"></i></span>
                        <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save Name</span>
                    </button>
                </div>
            </div>
            @endif

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
                        $isSelected   = in_array($c->code, $orgSelectedCourseCodes);
                        $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                        $isTaken      = $otherCollege !== null;
                    @endphp
                    <label class="course-check-row flex items-center gap-3 p-3 border rounded-lg {{ $isTaken ? 'opacity-50 cursor-not-allowed bg-slate-100 border-slate-200' : ($isSelected ? 'is-selected border-purple-400 cursor-pointer' : 'border-slate-200 bg-white cursor-pointer') }}">
                        <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}" class="w-4 h-4 shrink-0" style="accent-color:#7a3f91;" {{ $isTaken ? 'disabled' : '' }}>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-slate-800 text-sm font-mono">{{ $c->code }}</span>
                                <span class="text-slate-600 text-xs">{{ $c->name }}</span>
                            </div>
                            @if($isTaken)<p class="text-xs text-amber-600 mt-0.5"><i class="fas fa-lock mr-1"></i>Already assigned to: <em>{{ $otherCollege }}</em></p>@endif
                        </div>
                        @if($isSelected && !$isTaken)<i class="fas fa-check-circle text-purple-600 shrink-0 text-lg"></i>@endif
                    </label>
                    @endforeach
                </div>
                @else
                <div class="text-center py-6">
                    <i class="fas fa-book text-3xl text-slate-300 block mb-2"></i>
                    <p class="text-slate-500 text-sm">No courses available. Add courses first via <strong>Manage Courses</strong>.</p>
                </div>
                @endif
                <div class="flex gap-3">
                    <button type="button" wire:click="cancelAddingCourses" class="flex-1 px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                            class="flex-1 px-4 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                        <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                    </button>
                </div>
            </div>
            @endif

            <div>
                <h3 class="text-base font-bold text-slate-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-list text-slate-500"></i> Colleges & Departments
                    <span class="ml-auto text-xs font-semibold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-full border border-slate-200">{{ count($orgCoursesList) }} {{ count($orgCoursesList) === 1 ? 'college' : 'colleges' }}</span>
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
                    @php $collegeOccupied = $this->occupiedColleges(); $collegeOrganizer = $collegeOccupied[$college] ?? null; @endphp
                    <div class="border border-slate-200 rounded-lg overflow-hidden college-card">
                        <div class="flex items-center justify-between px-5 py-3 bg-purple-50">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-purple-200 rounded-lg flex items-center justify-center"><i class="fas fa-building-columns text-purple-700 text-sm"></i></div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-bold text-purple-900 text-sm">{{ $college }}</p>
                                        @if($collegeOrganizer)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-full text-xs font-semibold"><i class="fas fa-circle-check text-xs text-emerald-500"></i>{{ $collegeOrganizer }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-full text-xs font-medium"><i class="fas fa-circle-minus text-xs"></i> No Organizer</span>
                                        @endif
                                    </div>
                                    <p class="text-purple-600 text-xs mt-0.5">{{ count($departments) }} department{{ count($departments)!==1?'s':'' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')" class="px-3 py-1.5 bg-white text-purple-700 rounded-lg hover:bg-purple-50 transition font-semibold text-xs border border-purple-300 flex items-center gap-1.5"><i class="fas fa-pen-to-square"></i> Rename</button>
                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')" class="px-3 py-1.5 bg-white text-purple-700 rounded-lg hover:bg-purple-100 transition font-semibold text-xs border border-purple-300 flex items-center gap-1.5"><i class="fas fa-pencil"></i> Depts</button>
                                @endif
                                <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200 flex items-center gap-1.5"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @foreach($departments as $dept)
                            <div class="flex items-center px-5 py-3 bg-white">
                                <span class="w-8 h-8 bg-purple-50 text-purple-600 rounded-lg flex items-center justify-center text-xs font-bold shrink-0">{{ strtoupper(substr($dept['code'],0,2)) }}</span>
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

{{-- ── DELETE COLLEGE CONFIRM ───────────────────────────────────────────── --}}
@if($activeModal==='deleteOrgCollegeConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
        <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg">
            <h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete College</h2>
        </div>
        <div class="p-8">
            <p class="text-slate-800 text-sm mb-2">Remove college <strong class="text-red-600">{{ $deleteOrgCourseName }}</strong>?</p>
            <p class="text-slate-600 text-xs mb-6"><i class="fas fa-circle-info mr-1 text-slate-400"></i>Courses will be unassigned but <strong>not deleted</strong>.</p>
            <div class="flex gap-3">
                <button type="button" wire:click="openModal('manageOrgCourses')" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
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

{{-- ── TOGGLE ORGANIZER STATUS CONFIRM ─────────────────────────────────── --}}
@if($activeModal==='toggleOrganizerConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm modal-animate">
        <div class="p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 {{ $pendingToggleAction==='deactivate'?'bg-red-100':'bg-emerald-100' }}">
                    <i class="text-lg {{ $pendingToggleAction==='deactivate'?'fas fa-ban text-red-600':'fas fa-circle-check text-emerald-600' }}"></i>
                </div>
                <div>
                    <p class="font-bold text-slate-800 text-lg">{{ $pendingToggleAction==='deactivate'?'Deactivate Organizer?':'Activate Organizer?' }}</p>
                    <p class="text-sm text-slate-500 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-slate-600 mb-6">
                @if($pendingToggleAction==='deactivate') This organizer will no longer be able to log in. You can reactivate them at any time.
                @else This organizer will be able to log in again. @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="closeModal" class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-lg text-base font-bold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeToggleOrganizerStatus" wire:loading.attr="disabled" wire:target="executeToggleOrganizerStatus"
                        class="flex-1 px-4 py-3 rounded-lg text-base font-bold transition flex items-center justify-center gap-2 {{ $pendingToggleAction==='deactivate'?'bg-red-600 hover:bg-red-700 text-white':'bg-emerald-600 hover:bg-emerald-700 text-white' }}">
                    <span wire:loading wire:target="executeToggleOrganizerStatus"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeToggleOrganizerStatus">{{ $pendingToggleAction==='deactivate'?'Yes, Deactivate':'Yes, Activate' }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>