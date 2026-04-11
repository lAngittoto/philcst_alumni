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

    public string $activeTab   = 'alumni';
    public string $activeModal = '';

    public string $alumniSearch = '';
    public string $alumniBatch  = '';
    public string $alumniCourse = '';
    public string $alumniSort   = 'recent';

    public string $orgSearch  = '';
    public string $orgCollege = '';
    public string $orgSort    = 'recent';

    public string $regFirstName      = '';
    public string $regMiddleInitial  = '';
    public string $regLastName       = '';
    public string $regSuffix         = '';
    public string $regStudentId      = '';
    public string $regCourseCode     = '';
    public string $regYear           = '';
    public        $regPhoto          = null;
    public bool   $registeringAlumni = false;
    public array  $alumniErrors      = [];
    public string $alumniSuccess     = '';

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

    public ?int   $pendingToggleId     = null;
    public string $pendingToggleAction = '';
    public string $pendingToggleName   = '';

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

    public ?int   $viewingProfileId     = null;
    public string $viewingProfileType   = 'alumni';
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

    protected string $paginationTheme = 'tailwind';

    private function generateTempPassword(string $studentId, string $lastName): string
    {
        $raw  = substr(trim($lastName), 0, 2);
        $part = ucfirst(strtolower($raw));
        return $studentId . '_' . $part;
    }

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
                ->where('name',        'like', "%{$this->alumniSearch}%")
                ->orWhere('first_name', 'like', "%{$this->alumniSearch}%")
                ->orWhere('last_name',  'like', "%{$this->alumniSearch}%")
                ->orWhere('student_id', 'like', "%{$this->alumniSearch}%")
                ->orWhere('course_code','like', "%{$this->alumniSearch}%")
                ->orWhere('course_name','like', "%{$this->alumniSearch}%"));
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

    public function formatAlumniDisplayName(
        string $firstName,
        string $middleInitial,
        string $lastName,
        string $suffix
    ): string {
        $parts   = [];
        $parts[] = trim($firstName);
        if (trim($middleInitial) !== '') {
            $parts[] = strtoupper(substr(trim($middleInitial), 0, 1)) . '.';
        }
        $parts[] = trim($lastName);
        if (trim($suffix) !== '') {
            $parts[] = trim($suffix);
        }
        return implode(' ', array_filter($parts));
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

    private function alumniFullNameExists(string $firstName, string $middleInitial, string $lastName, string $suffix, ?int $exceptId = null): bool
    {
        $q = Alumni::whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,""))) = ?', [strtolower(trim($middleInitial))])
            ->whereRaw('LOWER(TRIM(COALESCE(suffix,""))) = ?', [strtolower(trim($suffix))]);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }

    private function organizerFullNameExists(string $firstName, string $middleInitial, string $lastName, string $suffix, ?int $exceptId = null): bool
    {
        $q = Organizer::withoutTrashed()
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,""))) = ?', [strtolower(trim($middleInitial))])
            ->whereRaw('LOWER(TRIM(COALESCE(suffix,""))) = ?', [strtolower(trim($suffix))]);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
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
        if (function_exists('set_time_limit')) @set_time_limit(300);
        @ini_set('memory_limit', '512M');

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
            foreach (['middle_initial', 'student_id', 'course_code', 'batch'] as $req) {
                if (!in_array($req, $header, true))
                    throw new \Exception("Missing required column: \"{$req}\".");
            }

            $this->importTotal = count($rows) - 1;

            $courseMap         = Course::pluck('name', 'code')->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])->toArray();
            $existingAlumniIds = Alumni::pluck('student_id')->mapWithKeys(fn($id) => [$id => true])->toArray();

            $existingFullNames = Alumni::selectRaw('LOWER(TRIM(first_name)) as fn, LOWER(TRIM(COALESCE(middle_initial,""))) as mi, LOWER(TRIM(last_name)) as ln, LOWER(TRIM(COALESCE(suffix,""))) as sf')
                ->get()
                ->map(fn($a) => $a->fn . '|' . $a->mi . '|' . $a->ln . '|' . $a->sf)
                ->flip()
                ->toArray();

            $alumniJobs       = [];
            $seenIdsInFile    = [];
            $seenNamesInFile  = [];
            $validationErrors = [];
            $duplicates       = [];
            $maxErrorsStored  = 200;

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

                $rawId = rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.');
                $rawId = preg_replace('/\..*$/', '', $rawId);
                $code  = strtoupper(trim($row['course_code'] ?? ''));
                $year  = (string)(int)($row['batch'] ?? 0);
                $label = "Row " . ($i + 1) . ($fullName ? " ({$fullName})" : '');

                if (!$firstName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: First name contains invalid characters."); continue; }
                if (!$lastName) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) { $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Last name contains invalid characters."); continue; }

                if ($middleInitial === '') {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle name is required.");
                    continue;
                }
                if (!preg_match('/^[a-zA-Z]+$/', $middleInitial)) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle name must contain letters only (no spaces or special characters).");
                    continue;
                }
                if (strlen($middleInitial) < 2) {
                    $this->_addValidationError($validationErrors, $maxErrorsStored, "{$label}: Middle name must be a full name, not just an initial (e.g. Santos, not S).");
                    continue;
                }

                $nameKey = strtolower($firstName) . '|' . strtolower($middleInitial) . '|' . strtolower($lastName) . '|' . strtolower($suffix);
                if (isset($existingFullNames[$nameKey]) || isset($seenNamesInFile[$nameKey])) {
                    $duplicates[] = "{$label}: Full name \"{$fullName}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
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

                $alumniJobs[] = [
                    'fullName'   => $fullName,
                    'firstName'  => $firstName,
                    'middleInit' => $middleInitial,
                    'lastName'   => $lastName,
                    'suffix'     => $suffix,
                    'sid'        => $sid,
                    'code'       => $code,
                    'courseName' => $courseMap[$code],
                    'batchYear'  => $batchYear,
                ];

                $seenIdsInFile[$sid]       = true;
                $seenNamesInFile[$nameKey] = true;
            }

            $this->importErrors     = $validationErrors;
            $this->importDuplicates = $duplicates;
            $this->importFailCount  = count($validationErrors);

            if (empty($alumniJobs)) {
                $this->importStatus  = 'Done';
                $this->importStep    = 'done';
                $this->importingFile = false;
                $this->importFile    = null;
                return;
            }

            $this->importStatus = 'Importing…';

            foreach ($alumniJobs as $job) {
                try {
                    $tempPassword     = $this->generateTempPassword($job['sid'], $job['lastName']);
                    $placeholderEmail = $job['sid'] . '@pending.local';

                    $user = User::create([
                        'name'     => $job['fullName'],
                        'role'     => 'alumni',
                        'email'    => $placeholderEmail,
                        'password' => Hash::make($tempPassword),
                    ]);

                    Alumni::create([
                        'user_id'        => $user->id,
                        'first_name'     => $job['firstName'],
                        'middle_initial' => $job['middleInit'] ?: null,
                        'last_name'      => $job['lastName'],
                        'suffix'         => $job['suffix']    ?: null,
                        'student_id'     => $job['sid'],
                        'email'          => null,
                        'course_code'    => $job['code'],
                        'course_name'    => $job['courseName'],
                        'batch'          => $job['batchYear'],
                        'status'         => 'PENDING',
                        'profile_photo'  => null,
                    ]);

                    $this->importSuccessCount++;

                } catch (\Exception $e) {
                    Log::error('Import row insert error: ' . $e->getMessage());
                    $this->_addValidationError($this->importErrors, 200, "Student ID {$job['sid']}: Insert failed — " . $e->getMessage());
                    $this->importFailCount++;
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
        $this->alumniErrors      = [];
        $this->alumniSuccess     = '';
        $this->registeringAlumni = true;

        try {
            if (!$this->validateName(trim($this->regFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->regLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            $midTrimmed = trim($this->regMiddleInitial);
            if ($midTrimmed !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $midTrimmed))
                    throw new \Exception('Middle name must contain letters only (no spaces or special characters).');
                if (strlen($midTrimmed) < 2)
                    throw new \Exception('Middle name must be a full name, not just an initial (e.g. Santos, not S).');
            }

            if (trim($this->regSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->regSuffix)))
                throw new \Exception('Suffix may only contain letters and periods (e.g. Jr. Sr. III)');

            if ($this->alumniFullNameExists(
                trim($this->regFirstName),
                trim($this->regMiddleInitial),
                trim($this->regLastName),
                trim($this->regSuffix)
            )) {
                $fullName = $this->buildFullName($this->regFirstName, $this->regMiddleInitial, $this->regLastName, $this->regSuffix);
                throw new \Exception("An alumni with the full name \"{$fullName}\" already exists.");
            }

            $fullName = $this->buildFullName($this->regFirstName, $this->regMiddleInitial, $this->regLastName, $this->regSuffix);

            $this->validate([
                'regFirstName'     => ['required', 'string', 'max:100'],
                'regLastName'      => ['required', 'string', 'max:100'],
                'regMiddleInitial' => ['nullable', 'string', 'min:2', 'max:50', 'regex:/^[a-zA-Z]+$/'],
                'regSuffix'        => ['nullable', 'string', 'max:10'],
                'regStudentId'     => ['required', 'string', 'regex:/^\d{1,8}$/', 'unique:alumni,student_id'],
                'regCourseCode'    => ['required', 'string', 'exists:courses,code'],
                'regYear'          => ['required', 'digits:4'],
                'regPhoto'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ], [
                'regStudentId.unique'    => 'This Student ID is already registered.',
                'regStudentId.regex'     => 'Student ID must be 1–8 digits (numbers only).',
                'regCourseCode.exists'   => 'The selected course does not exist.',
                'regYear.digits'         => 'Batch year must be exactly 4 digits (e.g. 2024).',
                'regPhoto.max'           => 'Profile photo must not exceed 5MB.',
                'regMiddleInitial.regex' => 'Middle name must contain letters only.',
                'regMiddleInitial.min'   => 'Middle name must be a full name, not just an initial (e.g. Santos, not S).',
            ]);

            $paddedId         = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $course           = Course::where('code', $this->regCourseCode)->firstOrFail();
            $photoPath        = $this->regPhoto ? $this->storeAlumniPhoto($this->regPhoto) : null;

            $tempPassword     = $this->generateTempPassword($paddedId, trim($this->regLastName));
            $placeholderEmail = $paddedId . '@pending.local';

            $user = User::create([
                'name'     => $fullName,
                'role'     => 'alumni',
                'email'    => $placeholderEmail,
                'password' => Hash::make($tempPassword),
            ]);

            Alumni::create([
                'user_id'        => $user->id,
                'first_name'     => trim($this->regFirstName),
                'middle_initial' => trim($this->regMiddleInitial) ?: null,
                'last_name'      => trim($this->regLastName),
                'suffix'         => trim($this->regSuffix) ?: null,
                'student_id'     => $paddedId,
                'email'          => null,
                'course_code'    => $this->regCourseCode,
                'course_name'    => $course->name,
                'batch'          => (int) $this->regYear,
                'status'         => 'PENDING',
                'profile_photo'  => $photoPath,
            ]);

            $this->alumniSuccess = "Alumni '{$fullName}' registered successfully!";
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
        $this->regStudentId = $this->regCourseCode = '';
        $this->regPhoto     = null;
        $this->regYear      = (string) date('Y');
        $this->alumniErrors = [];
    }

    public function registerOrganizer(): void
    {
        $this->organizerErrors      = [];
        $this->organizerSuccess     = '';
        $this->registeringOrganizer = true;

        try {
            if (!$this->validateName(trim($this->orgFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->orgLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            $orgMidTrimmed = trim($this->orgMiddleInitial);
            if ($orgMidTrimmed !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $orgMidTrimmed))
                    throw new \Exception('Middle name must contain letters only (no spaces or special characters).');
                if (strlen($orgMidTrimmed) < 2)
                    throw new \Exception('Middle name must be a full name, not just an initial (e.g. Santos, not S).');
            }

            if (trim($this->orgSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->orgSuffix)))
                throw new \Exception('Suffix may only contain letters and periods (e.g. Jr. Sr. III)');

            if ($this->organizerFullNameExists(
                trim($this->orgFirstName),
                trim($this->orgMiddleInitial),
                trim($this->orgLastName),
                trim($this->orgSuffix)
            )) {
                $fullName = $this->buildFullName($this->orgFirstName, $this->orgMiddleInitial, $this->orgLastName, $this->orgSuffix);
                throw new \Exception("An organizer with the full name \"{$fullName}\" already exists.");
            }

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
                'orgMiddleInitial' => ['nullable', 'string', 'min:2', 'max:50', 'regex:/^[a-zA-Z]+$/'],
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
                'orgMiddleInitial.regex'    => 'Middle name must contain letters only.',
                'orgMiddleInitial.min'      => 'Middle name must be a full name, not just an initial (e.g. Santos, not S).',
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

    // ── UPDATED: fetch ALL alumni fields including profile information ──
    public function viewProfile(int $id, string $type): void
    {
        try {
            $this->viewingProfileType = $type;

            if ($type === 'alumni') {
                $alumni = Alumni::select([
                    'id', 'user_id',
                    'first_name', 'middle_initial', 'last_name', 'suffix',
                    'student_id', 'course_code', 'course_name', 'batch',
                    'email', 'profile_photo', 'status', 'profile_completed',
                    // Personal details
                    'gender', 'date_of_birth', 'place_of_birth',
                    'citizenship', 'civil_status', 'blood_type', 'contact_number',
                    // Family background
                    'father_name', 'mother_name', 'spouse_name',
                    // Address
                    'address_no', 'address_street', 'address_barangay',
                    'address_municipality', 'address_province', 'address_zip_code',
                    'created_at', 'updated_at',
                ])->findOrFail($id);
                $this->viewingProfile = $alumni->toArray();
            } else {
                $this->viewingProfile = Organizer::findOrFail($id)->toArray();
            }

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

{{-- FLASH TOAST --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,8000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="opacity-0 translate-x-6 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-4 right-4 sm:right-5 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl max-w-[90vw] sm:max-w-sm border"
     :class="{ 'bg-white border-emerald-200': type==='success', 'bg-white border-blue-200': type==='info', 'bg-white border-red-200': type==='error' }"
     style="display:none">
    <i class="fas mt-0.5 text-sm flex-shrink-0"
       :class="{ 'fa-circle-check text-emerald-500': type==='success', 'fa-circle-info text-blue-500': type==='info', 'fa-circle-exclamation text-red-500': type==='error' }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-xs text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 text-gray-600 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-600 transition shrink-0"><i class="fas fa-xmark text-xs"></i></button>
</div>

<div class="flex flex-col px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto" style="background:#f3f4f6;min-height:100vh;">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 animate-in fade-in slide-in-from-top-2 duration-300">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-[#7a3f91]">
                <i class="fas fa-users text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-gray-900 leading-tight">Alumni &amp; Organizers</h1>
                <p class="text-gray-500 text-xs mt-0.5">Manage alumni and organizer records</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <div @class(['flex flex-wrap gap-2'=>true,'hidden'=>$this->activeTab!=='alumni'])>
                <button wire:click="openModal('registerAlumni')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-semibold text-xs sm:text-sm bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition shadow-md hover:shadow-lg hover:scale-100 active:scale-100">
                    <i class="fas fa-user-plus text-xs"></i><span>Register Alumni</span>
                </button>
                <button wire:click="openModal('importModal')" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg font-semibold text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">
                    <i class="fas fa-file-import text-xs"></i><span class="hidden sm:inline">Import</span>
                </button>
                <button wire:click="openModal('manageCourses')" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg font-semibold text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">
                    <i class="fas fa-sliders text-xs"></i><span class="hidden sm:inline">Courses</span>
                </button>
            </div>
            <div @class(['flex flex-wrap gap-2'=>true,'hidden'=>$this->activeTab!=='organizers'])>
                <button wire:click="openModal('registerOrganizer')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg font-semibold text-xs sm:text-sm bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition shadow-md hover:shadow-lg hover:scale-100 active:scale-100">
                    <i class="fas fa-users-gear text-xs"></i><span class="hidden sm:inline">Register Organizer</span>
                </button>
                <button wire:click="openModal('manageOrgCourses')" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg font-semibold text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">
                    <i class="fas fa-building-columns text-xs"></i><span class="hidden sm:inline">Colleges</span>
                </button>
            </div>
        </div>
    </div>

    {{-- TABS --}}
    <div class="flex gap-1 mb-4 bg-gray-200 p-1 rounded-xl w-fit">
        <button wire:click="switchTab('alumni')"
                class="px-4 sm:px-5 py-2 rounded-lg font-semibold text-xs sm:text-sm transition-all duration-200 flex items-center gap-1.5 {{ $this->activeTab==='alumni' ? 'bg-white text-[#7a3f91] border-b-2 border-[#7a3f91] shadow-sm' : 'bg-transparent text-gray-600 hover:bg-white/65' }}">
            <i class="fas fa-graduation-cap text-xs"></i>Alumni
        </button>
        <button wire:click="switchTab('organizers')"
                class="px-4 sm:px-5 py-2 rounded-lg font-semibold text-xs sm:text-sm transition-all duration-200 flex items-center gap-1.5 {{ $this->activeTab==='organizers' ? 'bg-white text-[#7a3f91] border-b-2 border-[#7a3f91] shadow-sm' : 'bg-transparent text-gray-600 hover:bg-white/65' }}">
            <i class="fas fa-users-gear text-xs"></i>Organizers
        </button>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col overflow-hidden" style="min-height:0;height:calc(100vh - 210px);">

        {{-- ══════════════════════ ALUMNI TAB ══════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0 overflow-hidden'=>true,'hidden'=>$this->activeTab!=='alumni'])>

            <div class="px-3 sm:px-5 py-2.5 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-2 items-center">
                <div class="relative flex-1 min-w-[160px] max-w-xs"
                     x-data="{ query: @entangle('alumniSearch').live, timer: null, onInput(e){ clearTimeout(this.timer); this.timer=setTimeout(()=>{ this.query=e.target.value; },80); } }"
                     wire:ignore>
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" :value="query" @input="onInput($event)" placeholder="Search name, ID, course…"
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                           autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="alumniBatch" class="px-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 min-w-[95px] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <option value="">All Years</option>
                    @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                </select>
                <select wire:model.live="alumniCourse" class="px-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 min-w-[110px] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <option value="">All Courses</option>
                    @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="alumniSort" class="px-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 min-w-[110px] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetAlumniFilters" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-900 px-3 py-2 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition">
                    <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
                <div id="alumni-scroll"
                     @scroll.passive="showTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scroll-custom"
                     wire:loading.class="opacity-40 pointer-events-none"
                     wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">
                    <table class="w-full border-collapse" style="min-width:700px;">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Student ID</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Course</th>
                                <th class="px-3 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Year</th>
                                <th class="px-3 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Email</th>
                                <th class="px-3 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($this->alumniRecords as $item)
                            <tr class="bg-white hover:bg-gray-50 transition">
                                <td class="px-3 sm:px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                             alt="{{ $item->name }}"
                                             class="w-8 h-8 rounded-lg object-cover shrink-0 shadow-sm ring-1 ring-gray-100">
                                        <span class="font-semibold text-gray-900 text-sm leading-snug">
                                            {{ $this->formatAlumniDisplayName(
                                                $item->first_name ?? '',
                                                $item->middle_initial ?? '',
                                                $item->last_name ?? '',
                                                $item->suffix ?? ''
                                            ) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-5 py-3">
                                    <span class="font-mono text-gray-800 text-xs font-bold">{{ $item->student_id }}</span>
                                </td>
                                <td class="px-3 sm:px-5 py-3">
                                    <span class="inline-block px-2.5 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold">{{ $item->course_code ?? '—' }}</span>
                                </td>
                                <td class="px-3 sm:px-5 py-3 text-center">
                                    <span class="font-mono text-gray-800 text-xs font-bold">{{ $item->batch }}</span>
                                </td>
                                <td class="px-3 sm:px-5 py-3 text-center">
                                    @if(!empty($item->email))
                                        <span class="inline-block px-2.5 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-xs font-bold">VERIFIED</span>
                                    @else
                                        <span class="inline-block px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold">PENDING</span>
                                    @endif
                                </td>
                                <td class="px-3 sm:px-5 py-3 hidden md:table-cell">
                                    @if(!empty($item->email))
                                        <span class="text-gray-700 text-xs">{{ $item->email }}</span>
                                    @else
                                        <span class="text-gray-300 text-xs italic">—</span>
                                    @endif
                                </td>
                                <td class="px-3 sm:px-5 py-3 text-center">
                                    <button wire:click="viewProfile({{ $item->id }},'alumni')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] hover:bg-[#e9d5f3] transition">
                                        <i class="fas fa-eye text-xs"></i>
                                        <span class="hidden sm:inline">View</span>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-users text-xl text-gray-300"></i>
                                        </div>
                                        <p class="font-semibold text-gray-400 text-sm">No alumni found</p>
                                        <p class="text-xs text-gray-400">Try adjusting filters or register new alumni</p>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button x-show="showTop"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-75" x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('alumni-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="absolute bottom-4 right-4 z-20 w-8 h-8 rounded-full flex items-center justify-center shadow-lg bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition"
                        style="display:none">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            <div class="px-3 sm:px-5 py-3 border-t border-gray-200 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#2b0d3e]">
                @php $total=$this->alumniRecords->total(); $pp=$this->alumniRecords->perPage(); $cp=$this->alumniRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                <p class="text-white text-xs sm:text-sm">Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $total }}</strong></p>
                <div class="flex items-center gap-1.5">
                    @if($this->alumniRecords->onFirstPage())
                        <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage('alumniPage')" class="px-3 py-1.5 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-xs font-semibold transition">← Prev</button>
                    @endif
                    <span class="px-3 py-1.5 text-gray-900 text-xs font-semibold bg-white rounded-lg">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                    @if($this->alumniRecords->hasMorePages())
                        <button wire:click="nextPage('alumniPage')" class="px-3 py-1.5 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-xs font-semibold transition">Next →</button>
                    @else
                        <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>

        {{-- ══════════════════════ ORGANIZERS TAB ══════════════════════ --}}
        <div @class(['flex flex-col flex-1 min-h-0 overflow-hidden'=>true,'hidden'=>$this->activeTab!=='organizers'])>
            <div class="px-3 sm:px-5 py-2.5 border-b border-gray-100 bg-gray-50 flex flex-wrap gap-2 items-center">
                <div class="relative flex-1 min-w-[160px] max-w-xs" wire:ignore
                     x-data="{ q:'', timer:null, init(){ this.q=$wire.orgSearch??''; $wire.$watch('orgSearch',val=>{ if(val!==this.q) this.q=val; }); } }">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" x-model="q" @input.debounce.80ms="$wire.set('orgSearch',q)"
                           placeholder="Search name, ID, email..."
                           class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                           autocomplete="off" spellcheck="false">
                </div>
                <select wire:model.live="orgCollege" class="px-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 min-w-[120px] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <option value="">All Colleges</option>
                    @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
                </select>
                <select wire:model.live="orgSort" class="px-3 py-2 border border-gray-200 rounded-lg text-xs sm:text-sm bg-white text-gray-900 min-w-[110px] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <option value="recent">Recent First</option>
                    <option value="oldest">Oldest First</option>
                </select>
                <button wire:click="resetOrgFilters" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-900 px-3 py-2 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition">
                    <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Reset</span>
                </button>
            </div>

            <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
                <div id="org-scroll"
                     @scroll.passive="showTop = $event.target.scrollTop > 200"
                     class="h-full overflow-y-auto overflow-x-auto scroll-custom"
                     wire:loading.class="opacity-40 pointer-events-none"
                     wire:target="orgSearch,orgCollege,orgSort,resetOrgFilters,executeToggleOrganizerStatus">
                    <table class="w-full border-collapse" style="min-width:640px;">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Teacher ID</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Email</th>
                                <th class="px-3 sm:px-5 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">College</th>
                                <th class="px-3 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                <th class="px-3 sm:px-5 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($this->organizerRecords as $item)
                            <tr class="bg-white hover:bg-gray-50 transition">
                                <td class="px-3 sm:px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                             class="w-8 h-8 rounded-lg object-cover shrink-0 shadow-sm ring-1 ring-gray-100">
                                        <span class="font-semibold text-gray-900 text-sm">
                                            {{ $this->formatAlumniDisplayName(
                                                $item->first_name ?? '',
                                                $item->middle_initial ?? '',
                                                $item->last_name ?? '',
                                                $item->suffix ?? ''
                                            ) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 sm:px-5 py-3"><span class="font-mono text-gray-800 text-xs font-bold">{{ $item->id_number }}</span></td>
                                <td class="px-3 sm:px-5 py-3 hidden md:table-cell"><span class="text-gray-700 text-xs">{{ $item->email }}</span></td>
                                <td class="px-3 sm:px-5 py-3">
                                    @php
                                        $dept        = $item->department;
                                        $directMatch = \App\Models\Course::where('college', $dept)->exists();
                                        $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code', $dept)->value('college') ?? $dept);
                                        $deptCodes   = \App\Models\Course::where('college', $collegeName)->orderBy('code')->pluck('code')->toArray();
                                    @endphp
                                    <span class="block font-semibold text-gray-900 text-xs leading-snug">{{ $collegeName }}</span>
                                    @if(count($deptCodes))
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach($deptCodes as $deptCode)
                                            <span class="text-xs font-mono font-bold text-[#7a3f91]">{{ $deptCode }}</span>
                                            @if(!$loop->last)<span class="text-gray-300 text-xs">·</span>@endif
                                        @endforeach
                                    </div>
                                    @endif
                                </td>
                                <td class="px-3 sm:px-5 py-3 text-center">
                                    @php
                                        $statusColor = match($item->status) {
                                            'ACTIVE' => 'bg-green-50 text-green-700 border-green-200',
                                            'INACTIVE' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'SUSPENDED' => 'bg-red-50 text-red-700 border-red-200',
                                            default => 'bg-gray-50 text-gray-700 border-gray-200'
                                        };
                                    @endphp
                                    <span class="inline-block px-2.5 py-1 border rounded-full text-xs font-bold {{ $statusColor }}">{{ $item->status }}</span>
                                </td>
                                <td class="px-3 sm:px-5 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                        <button wire:click="viewProfile({{ $item->id }},'organizer')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] hover:bg-[#e9d5f3] transition">
                                            <i class="fas fa-eye text-xs"></i><span class="hidden lg:inline">View</span>
                                        </button>
                                        @if($item->status === 'ACTIVE')
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }},'deactivate')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white text-red-600 border border-red-100 hover:bg-red-50 transition">
                                                <i class="fas fa-ban text-xs"></i><span class="hidden lg:inline">Deactivate</span>
                                            </button>
                                        @else
                                            <button wire:click="confirmToggleOrganizerStatus({{ $item->id }},'activate')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white text-green-600 border border-green-100 hover:bg-green-50 transition">
                                                <i class="fas fa-circle-check text-xs"></i><span class="hidden lg:inline">Activate</span>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-users-gear text-xl text-gray-300"></i>
                                        </div>
                                        <p class="font-semibold text-gray-400 text-sm">No organizers found</p>
                                        <p class="text-xs text-gray-400">Register an organizer to get started</p>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button x-show="showTop"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-75" x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-75"
                        @click="document.getElementById('org-scroll').scrollTo({top:0,behavior:'smooth'})"
                        class="absolute bottom-4 right-4 z-20 w-8 h-8 rounded-full flex items-center justify-center shadow-lg bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition"
                        style="display:none">
                    <i class="fas fa-arrow-up text-xs"></i>
                </button>
            </div>

            <div class="px-3 sm:px-5 py-3 border-t border-gray-200 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#2b0d3e]">
                @php $total=$this->organizerRecords->total(); $pp=$this->organizerRecords->perPage(); $cp=$this->organizerRecords->currentPage(); $from=$total>0?($cp-1)*$pp+1:0; $to=min($cp*$pp,$total); @endphp
                <p class="text-white text-xs sm:text-sm">Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $total }}</strong></p>
                <div class="flex items-center gap-1.5">
                    @if($this->organizerRecords->onFirstPage())
                        <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage('orgPage')" class="px-3 py-1.5 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-xs font-semibold transition">← Prev</button>
                    @endif
                    <span class="px-3 py-1.5 text-gray-900 text-xs font-semibold bg-white rounded-lg">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                    @if($this->organizerRecords->hasMorePages())
                        <button wire:click="nextPage('orgPage')" class="px-3 py-1.5 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-xs font-semibold transition">Next →</button>
                    @else
                        <button disabled class="px-3 py-1.5 bg-white/10 text-white/40 rounded-lg text-xs font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════ MODALS ═══════════════════════════ --}}

{{-- ── REGISTER ALUMNI ──────────────────────────────────────────── --}}
@if($activeModal==='registerAlumni')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2"><i class="fas fa-user-plus"></i> Register Alumni</h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        @if($alumniSuccess)
        <div class="mx-5 sm:mx-7 mt-5 flex items-start gap-3 p-4 rounded-xl bg-green-50 border border-green-200">
            <i class="fas fa-circle-check text-green-500 mt-0.5 shrink-0"></i>
            <div class="flex-1">
                <p class="font-bold text-sm text-green-900">Registration Successful!</p>
                <p class="text-sm mt-0.5 text-green-800">{{ $alumniSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="bg-[#7a3f91] text-white px-3 py-1.5 rounded-lg text-xs font-bold shrink-0 hover:bg-[#5e2f72] transition">Done</button>
        </div>
        @endif

        @if(count($alumniErrors)>0)
        <div class="mx-5 sm:mx-7 mt-5 p-4 rounded-xl bg-red-50 border border-red-200">
            <p class="font-bold text-sm text-red-900 mb-1.5"><i class="fas fa-triangle-exclamation mr-1.5"></i>Please fix the following:</p>
            <ul class="text-sm space-y-1 text-red-800">
                @foreach($alumniErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $m }}</li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerAlumni" class="p-5 sm:p-7 space-y-5 overflow-y-auto" style="max-height:calc(100vh - 180px);">
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Profile Photo</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition" onclick="document.getElementById('regPhotoInput').click()">
                    @if($regPhoto)
                        <img src="{{ $regPhoto->temporaryUrl() }}" class="w-24 h-24 rounded-xl mx-auto mb-2 object-cover shadow-md">
                        <p class="text-xs text-green-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                        <p class="text-sm text-gray-700 font-semibold">Click to Upload</p>
                        <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="regFirstName" type="text" placeholder="First Name" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regLastName" type="text" placeholder="Last Name" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-2 gap-3 mt-3">
                    <div>
                        <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. Santos" maxlength="50" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Middle Name <span class="text-gray-400">(full word, not just S)</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr." maxlength="10" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Suffix</p>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Student ID <span class="text-red-500">*</span></label>
                <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                <p class="text-xs text-gray-500 mt-1">Numbers only · padded to 8 digits</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Course <span class="text-red-500">*</span></label>
                    <select wire:model.defer="regCourseCode" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <option value="">Select Course</option>
                        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Batch Year <span class="text-red-500">*</span></label>
                    <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}" min="1000" max="9999" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                </div>
            </div>

            <div class="flex gap-3 pt-4">
                <button type="button" wire:click="closeModal" class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerAlumni"
                        class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner animate-spin"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check"></i> Register Alumni</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── IMPORT ────────────────────────────────────────────────────── --}}
@if($activeModal==='importModal')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.cancelImport()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2"><i class="fas fa-file-import"></i> Import Alumni</h2>
            <button wire:click="cancelImport" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="p-5 sm:p-7 space-y-5 overflow-y-auto" style="max-height:calc(100vh - 180px);">

            @if($importStep==='upload')
            <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-sm text-blue-900">
                <p class="font-bold mb-2"><i class="fas fa-circle-info mr-1.5"></i>Supported: CSV · Excel (.xlsx, .xls)</p>
                <div class="flex flex-wrap gap-1 mb-1">
                    @foreach(['first_name','last_name','middle_initial','student_id','course_code','batch'] as $col)
                    <code class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs font-mono">{{ $col }}</code>
                    @endforeach
                </div>
                <p class="text-xs text-blue-700 mt-1">
                    <strong>middle_initial</strong> = full middle name (e.g. <code class="bg-blue-100 px-1 rounded font-mono">SANTOS</code>, not just <code class="bg-blue-100 px-1 rounded font-mono">S</code>).
                    Optional: <code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">suffix</code>
                </p>
                <p class="text-xs text-amber-700 mt-2 font-medium"><i class="fas fa-triangle-exclamation mr-1"></i>Duplicate full names and student IDs are automatically blocked.</p>
            </div>
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition" @click="document.getElementById('importFile').click()">
                @if($importFile)
                    <i class="fas fa-file-circle-check text-4xl text-green-500 block mb-2"></i>
                    <p class="text-green-700 font-semibold text-sm">{{ $importFile->getClientOriginalName() }}</p>
                    <p class="text-gray-400 text-xs mt-0.5">Click to change file</p>
                @else
                    <i class="fas fa-file-arrow-up text-4xl text-gray-300 block mb-2"></i>
                    <p class="text-gray-700 font-semibold text-sm">Click to choose file</p>
                    <p class="text-gray-400 text-xs mt-0.5">CSV or Excel format</p>
                @endif
                <input type="file" id="importFile" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
            </div>
            <div class="flex gap-3">
                <button type="button" wire:click="cancelImport" class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">Cancel</button>
                <button type="button" wire:click="processImportFile"
                        @if(!$importFile) disabled @endif
                        wire:loading.attr="disabled" wire:target="processImportFile"
                        class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner animate-spin"></i> Processing…</span>
                    <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import Now</span>
                </button>
            </div>
            @endif

            @if($importStep==='processing')
            <div>
                <div class="flex justify-between mb-2">
                    <p class="text-gray-800 font-semibold text-sm flex items-center gap-2">
                        <i class="fas fa-spinner animate-spin" style="color:#7a3f91;"></i>
                        Validating rows… {{ $importProgress }}/{{ $importTotal }}
                    </p>
                    <span class="text-xs text-gray-500 font-mono">{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-300 bg-[#7a3f91]" style="width:{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%"></div>
                </div>
            </div>
            @endif

            @if($importStep==='blocked')
            <div class="flex items-center gap-3 p-4 rounded-xl bg-red-50 border border-red-200">
                <div class="w-9 h-9 bg-red-100 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-xmark text-red-600 text-sm"></i>
                </div>
                <div>
                    <p class="font-bold text-red-900 text-sm">Import Failed</p>
                    <p class="text-xs mt-0.5 text-red-700">{{ $importStatus }}</p>
                </div>
            </div>
            <button wire:click="resetImportState" class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">
                <i class="fas fa-arrow-left mr-2"></i>Try Again
            </button>
            @endif

            @if($importStep==='done')
            @php $hasNew=$importSuccessCount>0; $hasErrors=$importFailCount>0; $hasDups=$importDuplicateCount>0; @endphp
            <div class="rounded-xl border p-4 {{ $hasNew?'bg-green-50 border-green-200':'bg-amber-50 border-amber-200' }} flex items-center gap-3">
                <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $hasNew?'bg-green-100':'bg-amber-100' }}">
                    <i class="{{ $hasNew?'fas fa-circle-check text-green-600':'fas fa-triangle-exclamation text-amber-600' }} text-sm"></i>
                </div>
                <div>
                    @if($hasNew)
                        <p class="font-bold text-green-900 text-sm">Import Complete</p>
                        <p class="text-xs mt-0.5 text-green-700">
                            {{ $importSuccessCount }} record(s) imported
                            @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                            @if($hasErrors) · {{ $importFailCount }} error(s) @endif
                        </p>
                    @else
                        <p class="font-bold text-amber-900 text-sm">Nothing Imported</p>
                        <p class="text-xs mt-0.5 text-amber-700">All records were duplicates or had errors.</p>
                    @endif
                </div>
            </div>
            <div class="grid grid-cols-4 gap-2">
                @foreach([
                    ['#f9fafb','#e5e7eb','#111827',$importTotal,'Total'],
                    ['#f0fdf4','#86efac','#14532d',$importSuccessCount,'Imported'],
                    ['#fffbeb','#fcd34d','#92400e',$importDuplicateCount,'Duplicate'],
                    ['#fef2f2','#fca5a5','#7f1d1d',$importFailCount,'Error'],
                ] as [$bg,$border,$clr,$num,$lbl])
                <div class="rounded-xl p-3 text-center" style="background:{{ $bg }};border:1px solid {{ $border }};">
                    <p class="text-lg sm:text-xl font-extrabold" style="color:{{ $clr }}">{{ $num }}</p>
                    <p class="text-xs font-bold mt-0.5 text-gray-500 uppercase tracking-wide">{{ $lbl }}</p>
                </div>
                @endforeach
            </div>
            @if($hasErrors)
            <div class="border border-red-200 rounded-xl overflow-hidden">
                <div class="px-4 py-2.5 bg-red-50 border-b border-red-200 flex items-center gap-2">
                    <i class="fas fa-circle-xmark text-red-500 text-xs"></i>
                    <p class="font-bold text-red-900 text-xs">Validation Errors</p>
                    <span class="ml-auto text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                </div>
                <ul class="divide-y divide-red-50 overflow-y-auto" style="max-height:180px">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-2.5 text-xs text-red-800 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-400 mt-0.5 shrink-0"></i>
                        <span>{{ $err }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
            <div class="flex gap-3">
                <button wire:click="resetImportState" class="flex-1 bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Import Another
                </button>
                <button wire:click="closeModal" class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-5 py-2.5 rounded-xl text-sm font-bold transition">Done</button>
            </div>
            @endif

        </div>
    </div>
</div>
@endif

{{-- ── MANAGE COURSES ────────────────────────────────────────────── --}}
@if($activeModal==='manageCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200 flex flex-col" style="max-height:92vh;">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2"><i class="fas fa-sliders"></i> Manage Courses</h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>
        @if($courseAlert)
        <div class="mx-5 sm:mx-7 mt-4 shrink-0 flex items-start gap-2.5 p-3.5 rounded-xl {{ $courseAlertType==='success'?'bg-green-50 border border-green-200':'bg-red-50 border border-red-200' }}">
            <i class="fas mt-0.5 text-sm {{ $courseAlertType==='success'?'fa-circle-check text-green-500':'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $courseAlertType==='success'?'text-green-900':'text-red-900' }}">{{ $courseAlert }}</p>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto px-5 sm:px-7 py-5 space-y-5">
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3 flex items-center gap-2">
                    <i class="fas fa-{{ $editingCourseId?'pencil':'plus-circle' }} text-[#7a3f91]"></i>
                    {{ $editingCourseId?'Edit Course':'Add New Course' }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-1">Course Code</label>
                        <input wire:model.defer="courseCode" type="text" placeholder="e.g. BSIT" maxlength="20" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-1">Course Name</label>
                        <input wire:model.defer="courseName" type="text" placeholder="e.g. Bachelor of Science in Information Technology" maxlength="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    </div>
                    <div class="flex gap-2.5 pt-2">
                        @if($editingCourseId)
                        <button wire:click="resetCourseForm" class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                        @endif
                        <button wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse"
                                class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                            <span wire:loading wire:target="saveCourse"><i class="fas fa-spinner animate-spin"></i></span>
                            <span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId?'Update Course':'Add Course' }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-2 flex items-center gap-2"><i class="fas fa-book text-gray-400"></i> Courses ({{ count($coursesList) }})</h3>
                <div class="space-y-2 overflow-y-auto pr-1" style="max-height:260px;">
                    @forelse($coursesList as $c)
                    <div class="flex items-center justify-between p-3.5 border border-gray-200 rounded-xl bg-white hover:border-gray-300 transition">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-bold text-gray-900 text-sm">{{ $c['code'] }}</p>
                                @if($c['college']??null)
                                    <span class="inline-block px-2 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold"><i class="fas fa-building-columns mr-1"></i>{{ $c['college'] }}</span>
                                @else
                                    <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-full text-xs font-bold">No College</span>
                                @endif
                            </div>
                            <p class="text-gray-500 text-xs mt-0.5 truncate">{{ $c['name'] }}</p>
                        </div>
                        <div class="flex gap-1.5 ml-3 shrink-0">
                            <button wire:click="openEditCourse({{ $c['id'] }})" class="bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#e9d5f3] px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 transition">
                                <i class="fas fa-pencil text-xs"></i> <span class="hidden sm:inline">Edit</span>
                            </button>
                            <button wire:click="confirmDeleteCourse({{ $c['id'] }})" class="bg-white border border-red-100 text-red-600 hover:bg-red-50 px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 transition">
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
        <div class="px-5 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl shrink-0">
            <button wire:click="closeModal" class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COURSE CONFIRM ─────────────────────────────────────── --}}
@if($activeModal==='deleteCourseConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-4 bg-red-50 border-b border-red-100 rounded-t-2xl">
            <h2 class="text-base font-extrabold text-red-900 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Delete Course</h2>
        </div>
        <div class="p-6">
            <p class="text-gray-800 text-sm mb-1">Delete <strong class="text-red-700">{{ $deleteCourseName }}</strong>?</p>
            <p class="text-gray-500 text-xs mb-5">This action cannot be undone.</p>
            <div class="flex gap-3">
                <button wire:click="closeModal" class="flex-1 bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="deleteCourse" wire:loading.attr="disabled" wire:target="deleteCourse"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="deleteCourse">Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── VIEW PROFILE ──────────────────────────────────────────────── --}}
@if($activeModal==='viewProfile' && $viewingProfile)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl sticky top-0 z-10">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2">
                <i class="fas {{ $viewingProfileType==='alumni'?'fa-graduation-cap':'fa-users-gear' }}"></i>
                {{ $viewingProfileType==='alumni'?'Alumni':'Organizer' }} Profile
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        <div class="p-5 sm:p-6 space-y-5 overflow-y-auto" style="max-height:84vh;">

            @if($viewingProfileType === 'alumni')

                {{-- ══ ALUMNI: Updated Information Banner ══ --}}
                @php
                    $isProfileComplete = !empty($viewingProfile['profile_completed']);
                    $updatedAt = !empty($viewingProfile['updated_at'])
                        ? \Carbon\Carbon::parse($viewingProfile['updated_at'])->timezone('Asia/Manila')->format('F j, Y \a\t g:i A')
                        : null;
                @endphp

                @if($isProfileComplete && $updatedAt)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-circle-check text-emerald-600 text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-emerald-900 text-sm">This Alumni's Updated Information</p>
                        <p class="text-xs text-emerald-700 mt-0.5 flex items-center gap-1">
                            <i class="fas fa-clock text-emerald-500 text-xs"></i>
                            Last updated: <strong>{{ $updatedAt }}</strong>
                        </p>
                    </div>
                </div>
                @elseif(!$isProfileComplete)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200">
                    <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center shrink-0">
                        <i class="fas fa-triangle-exclamation text-amber-600 text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-amber-900 text-sm">Profile Incomplete</p>
                        <p class="text-xs text-amber-700 mt-0.5">This alumni has not yet completed their profile information.</p>
                    </div>
                </div>
                @endif

                {{-- ══ Avatar + Quick Info ══ --}}
                <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-xl border border-gray-200">
                    @if($updatingProfilePhoto)
                        <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-[#7a3f91]/20 shrink-0">
                    @else
                        <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}"
                             alt="{{ $viewingProfile['first_name']??'' }}"
                             class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-gray-200 shrink-0">
                    @endif
                    <div class="flex-1 min-w-0">
                        <p class="text-base font-extrabold text-gray-900 leading-tight">
                            {{ $this->formatAlumniDisplayName(
                                $viewingProfile['first_name'] ?? '',
                                $viewingProfile['middle_initial'] ?? '',
                                $viewingProfile['last_name'] ?? '',
                                $viewingProfile['suffix'] ?? ''
                            ) }}
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5 font-mono">ID: {{ $viewingProfile['student_id'] ?? '—' }}</p>
                        <div class="flex flex-wrap gap-1.5 mt-1.5">
                            <span class="inline-block px-2 py-0.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold">{{ $viewingProfile['course_code'] ?? '—' }}</span>
                            <span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-700 border border-gray-200 rounded-full text-xs font-semibold">Batch {{ $viewingProfile['batch'] ?? '—' }}</span>
                            @if(!empty($viewingProfile['email']))
                                <span class="inline-block px-2 py-0.5 bg-green-50 text-green-700 border border-green-200 rounded-full text-xs font-bold">
                                    <i class="fas fa-check text-[10px] mr-0.5"></i>VERIFIED
                                </span>
                            @else
                                <span class="inline-block px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold">PENDING</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- ══ SECTION 1: Student Record ══ --}}
                <div class="rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100" style="background:#f9f5ff;">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0" style="background:#7a3f91;">
                            <i class="fas fa-id-card text-white" style="font-size:10px;"></i>
                        </div>
                        <p class="font-bold text-gray-900 text-xs uppercase tracking-wide">Student Record</p>
                        <span class="ml-auto inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">
                            <i class="fas fa-lock text-xs"></i> Read-only
                        </span>
                    </div>
                    <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        @foreach([
                            ['First Name',    $viewingProfile['first_name']    ?? '—'],
                            ['Middle Name',   $viewingProfile['middle_initial'] ?? '—'],
                            ['Last Name',     trim(($viewingProfile['last_name']??'').' '.($viewingProfile['suffix']??'')) ?: '—'],
                            ['Student ID',    $viewingProfile['student_id']    ?? '—'],
                            ['Course Code',   $viewingProfile['course_code']   ?? '—'],
                            ['Batch Year',    $viewingProfile['batch']         ?? '—'],
                        ] as [$lbl, $val])
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-gray-800 truncate">{{ $val }}</p>
                        </div>
                        @endforeach
                        <div class="col-span-2 sm:col-span-3 bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">Course Name</p>
                            <p class="text-sm font-semibold text-gray-800">{{ $viewingProfile['course_name'] ?? '—' }}</p>
                        </div>
                        <div class="col-span-2 sm:col-span-3 bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">Email Address</p>
                            @if(!empty($viewingProfile['email']))
                                <p class="text-sm font-semibold text-gray-800">{{ $viewingProfile['email'] }}</p>
                            @else
                                <p class="text-xs text-gray-400 italic">No email — alumni has not self-registered yet</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- ══ SECTION 2: Personal Details ══ --}}
                <div class="rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-blue-50">
                        <div class="w-6 h-6 rounded-lg bg-blue-600 flex items-center justify-center shrink-0">
                            <i class="fas fa-person text-white" style="font-size:10px;"></i>
                        </div>
                        <p class="font-bold text-gray-900 text-xs uppercase tracking-wide">Personal Details</p>
                        @if(!$isProfileComplete)
                            <span class="ml-auto text-xs text-amber-600 font-semibold italic">Not yet filled</span>
                        @endif
                    </div>
                    <div class="p-3 grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        @php
                            $dob = !empty($viewingProfile['date_of_birth'])
                                ? \Carbon\Carbon::parse($viewingProfile['date_of_birth'])->format('F j, Y')
                                : '—';
                        @endphp
                        @foreach([
                            ['Gender',       $viewingProfile['gender']         ?? '—'],
                            ['Date of Birth', $dob],
                            ['Civil Status',  $viewingProfile['civil_status']  ?? '—'],
                            ['Place of Birth',$viewingProfile['place_of_birth']?? '—'],
                            ['Citizenship',   $viewingProfile['citizenship']   ?? '—'],
                            ['Blood Type',    $viewingProfile['blood_type']    ?: '—'],
                        ] as [$lbl, $val])
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-gray-800">{{ $val }}</p>
                        </div>
                        @endforeach
                        <div class="col-span-2 sm:col-span-3 bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">Contact Number</p>
                            <p class="text-sm font-semibold text-gray-800">{{ $viewingProfile['contact_number'] ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                {{-- ══ SECTION 3: Family Background ══ --}}
                <div class="rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-rose-50">
                        <div class="w-6 h-6 rounded-lg bg-rose-500 flex items-center justify-center shrink-0">
                            <i class="fas fa-people-roof text-white" style="font-size:10px;"></i>
                        </div>
                        <p class="font-bold text-gray-900 text-xs uppercase tracking-wide">Family Background</p>
                        @if(!$isProfileComplete)
                            <span class="ml-auto text-xs text-amber-600 font-semibold italic">Not yet filled</span>
                        @endif
                    </div>
                    <div class="p-3 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        @foreach([
                            ["Father's Name", $viewingProfile['father_name'] ?: '—'],
                            ["Mother's Name", $viewingProfile['mother_name'] ?: '—'],
                            ['Spouse Name',   $viewingProfile['spouse_name'] ?: '—'],
                        ] as [$lbl, $val])
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                            <p class="text-sm font-semibold text-gray-800">{{ $val }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- ══ SECTION 4: Home Address ══ --}}
                <div class="rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 bg-emerald-50">
                        <div class="w-6 h-6 rounded-lg bg-emerald-600 flex items-center justify-center shrink-0">
                            <i class="fas fa-map-location-dot text-white" style="font-size:10px;"></i>
                        </div>
                        <p class="font-bold text-gray-900 text-xs uppercase tracking-wide">Home Address</p>
                        @if(!$isProfileComplete)
                            <span class="ml-auto text-xs text-amber-600 font-semibold italic">Not yet filled</span>
                        @endif
                    </div>
                    <div class="p-3 space-y-2">
                        {{-- Full address line --}}
                        @php
                            $addrParts = array_filter([
                                trim(($viewingProfile['address_no'] ?? '') . ' ' . ($viewingProfile['address_street'] ?? '')),
                                $viewingProfile['address_barangay']     ?? '',
                                $viewingProfile['address_municipality'] ?? '',
                                $viewingProfile['address_province']     ?? '',
                                $viewingProfile['address_zip_code']     ?? '',
                            ]);
                            $fullAddress = implode(', ', $addrParts) ?: '—';
                        @endphp
                        <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-2.5">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">Full Address</p>
                            <p class="text-sm font-semibold text-gray-800 leading-snug">{{ $fullAddress }}</p>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach([
                                ['House/Block No.',  $viewingProfile['address_no']           ?: '—'],
                                ['Street',           $viewingProfile['address_street']        ?: '—'],
                                ['Barangay',         $viewingProfile['address_barangay']      ?: '—'],
                                ['Municipality/City',$viewingProfile['address_municipality']  ?: '—'],
                                ['Province',         $viewingProfile['address_province']      ?: '—'],
                                ['Zip Code',         $viewingProfile['address_zip_code']      ?: '—'],
                            ] as [$lbl, $val])
                            <div class="bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-0.5">{{ $lbl }}</p>
                                <p class="text-sm font-semibold text-gray-800">{{ $val }}</p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            @else
                {{-- ══ ORGANIZER PROFILE (unchanged) ══ --}}
                <div class="flex items-center gap-4">
                    @if($updatingProfilePhoto)
                        <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-[#7a3f91]/20 shrink-0">
                    @else
                        <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}"
                             alt="{{ $viewingProfile['name']??'' }}"
                             class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-gray-100 shrink-0">
                    @endif
                    <div>
                        <p class="text-base font-bold text-gray-900 leading-tight">
                            {{ $this->formatAlumniDisplayName(
                                $viewingProfile['first_name'] ?? '',
                                $viewingProfile['middle_initial'] ?? '',
                                $viewingProfile['last_name'] ?? '',
                                $viewingProfile['suffix'] ?? ''
                            ) }}
                        </p>
                        <p class="text-gray-500 text-xs mt-0.5">{{ $viewingProfile['email'] }}</p>
                        @php $sc = match($viewingProfile['status']??'') { 'ACTIVE'=>'bg-green-50 text-green-700 border-green-200','INACTIVE'=>'bg-amber-50 text-amber-700 border-amber-200','SUSPENDED'=>'bg-red-50 text-red-700 border-red-200',default=>'bg-gray-50 text-gray-700 border-gray-200' }; @endphp
                        <span class="inline-block mt-2 px-2.5 py-1 border rounded-full text-xs font-bold {{ $sc }}">{{ $viewingProfile['status']??'N/A' }}</span>
                    </div>
                </div>
                <div class="space-y-3">
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Personal Information</h3>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">First Name</p>
                            <p class="text-sm font-medium text-gray-700">{{ $viewingProfile['first_name'] ?? '—' }}</p>
                        </div>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Last Name</p>
                            <p class="text-sm font-medium text-gray-700">{{ $viewingProfile['last_name'] ?? '—' }}</p>
                        </div>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Middle Name</p>
                            <p class="text-sm font-medium text-gray-700">{{ $viewingProfile['middle_initial'] ?? '—' }}</p>
                        </div>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Suffix</p>
                            <p class="text-sm font-medium text-gray-700">{{ $viewingProfile['suffix'] ?: '—' }}</p>
                        </div>
                    </div>
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider pt-2">Assignment</h3>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Teacher ID</p>
                            <p class="text-sm font-medium text-gray-700 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                        </div>
                        <div class="col-span-1 bg-[#f5eef9] border border-[#d4aaeb] rounded-lg p-3">
                            <p class="text-xs font-bold text-[#7a3f91] uppercase tracking-wide mb-1">College</p>
                            <p class="text-sm font-medium text-[#5e2f72]">{{ $this->getCollegeForCourse($viewingProfile['department']??'') }}</p>
                        </div>
                    </div>
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider pt-2">Account</h3>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Email Address</p>
                        <p class="text-sm font-medium text-gray-700">{{ $viewingProfile['email'] ?? '—' }}</p>
                    </div>
                </div>
            @endif

            {{-- ══ Update Profile Photo (both types) ══ --}}
            <div class="border-t border-gray-100 pt-4">
                <p class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition" @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-gray-300 block mb-1.5"></i>
                    <p class="text-gray-700 font-semibold text-sm">{{ $updatingProfilePhoto?'Change Photo':'Click to Upload New Photo' }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="w-full mt-3 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-5 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>

            <button wire:click="closeModal" class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── REGISTER ORGANIZER ────────────────────────────────────────── --}}
@if($activeModal==='registerOrganizer')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2"><i class="fas fa-users-gear"></i> Register Organizer</h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        @if($organizerSuccess)
        <div class="mx-5 sm:mx-7 mt-5 flex items-start gap-3 p-4 rounded-xl bg-green-50 border border-green-200">
            <i class="fas fa-circle-check text-green-500 mt-0.5 shrink-0"></i>
            <div class="flex-1">
                <p class="font-bold text-sm text-green-900">Registration Successful!</p>
                <p class="text-sm mt-0.5 text-green-800">{{ $organizerSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="bg-[#7a3f91] text-white px-3 py-1.5 rounded-lg text-xs font-bold shrink-0 hover:bg-[#5e2f72] transition">Done</button>
        </div>
        @endif

        @if(count($organizerErrors)>0)
        <div class="mx-5 sm:mx-7 mt-5 p-4 rounded-xl bg-red-50 border border-red-200">
            <p class="font-bold text-sm text-red-900 mb-1.5"><i class="fas fa-triangle-exclamation mr-1.5"></i>Please fix the following:</p>
            <ul class="text-sm space-y-1 text-red-800">
                @foreach($organizerErrors as $ms)@foreach($ms as $m)
                <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $m }}</li>
                @endforeach@endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerOrganizer" class="p-5 sm:p-7 space-y-5 overflow-y-auto" style="max-height:calc(100vh - 180px);">
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Profile Photo</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition" onclick="document.getElementById('orgPhotoInput').click()">
                    @if($orgPhoto)
                        <img src="{{ $orgPhoto->temporaryUrl() }}" class="w-24 h-24 rounded-xl mx-auto mb-2 object-cover shadow-md">
                        <p class="text-xs text-green-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                        <p class="text-sm text-gray-700 font-semibold">Click to Upload</p>
                        <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="orgFirstName" type="text" placeholder="First Name" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgLastName" type="text" placeholder="Last Name" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div>
                        <input wire:model.defer="orgMiddleInitial" type="text" placeholder="e.g. Santos" maxlength="50" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Middle Name <span class="text-gray-400">(full word, not just S)</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="orgSuffix" type="text" placeholder="e.g. Jr." maxlength="10" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Suffix</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Teacher ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgTeacherId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <p class="text-xs text-gray-500 mt-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="orgEmail" type="email" placeholder="teacher@example.com" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-2">College <span class="text-red-500">*</span></label>
                @if($this->orgDepartmentsGrouped->isEmpty())
                    <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-sm flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                        <span class="text-gray-800">No colleges configured yet. Set up colleges via <strong>Manage Colleges</strong>.</span>
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
                        <select wire:model.live="orgCollegeSelect" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
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
                            <p class="text-xs text-gray-500 mb-1.5 font-medium">Departments under this college:</p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="code in depts" :key="code">
                                    <span class="inline-block px-2 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-bold font-mono" x-text="code"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            <div class="flex gap-3 pt-4">
                <button type="button" wire:click="closeModal" class="flex-1 bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerOrganizer"
                        class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-5 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner animate-spin"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear"></i> Register Organizer</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── MANAGE COLLEGES ───────────────────────────────────────────── --}}
@if($activeModal==='manageOrgCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200 flex flex-col" style="max-height:92vh;">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-lg flex items-center gap-2"><i class="fas fa-building-columns"></i>
                <span class="hidden sm:inline">Manage Colleges &amp; Departments</span>
                <span class="sm:hidden">Colleges</span>
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>
        @if($orgCourseAlert)
        <div class="mx-5 sm:mx-7 mt-4 shrink-0 flex items-start gap-2.5 p-3.5 rounded-xl {{ $orgCourseAlertType==='success'?'bg-green-50 border border-green-200':'bg-red-50 border border-red-200' }}">
            <i class="fas mt-0.5 text-sm {{ $orgCourseAlertType==='success'?'fa-circle-check text-green-500':'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $orgCourseAlertType==='success'?'text-green-900':'text-red-900' }}">{{ $orgCourseAlert }}</p>
        </div>
        @endif
        <div class="flex-1 overflow-y-auto px-5 sm:px-7 py-5 space-y-5">
            @if(!$orgAddingToCollege && !$orgRenamingCollege)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-3 flex items-center gap-2"><i class="fas fa-plus-circle text-[#7a3f91]"></i> Add New College</h3>
                <div class="flex gap-2">
                    <input wire:model.defer="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                           class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10" @keydown.enter.prevent="$wire.addCollege()">
                    <button wire:click="addCollege" class="bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition flex items-center gap-1.5">
                        <i class="fas fa-plus text-xs"></i><span class="hidden sm:inline">Add College</span><span class="sm:hidden">Add</span>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-2">After adding, assign which courses/departments belong to it.</p>
            </div>
            @endif

            @if($orgRenamingCollege)
            <div class="border-2 rounded-xl p-5 border-[#d4aaeb] bg-[#f5eef9]">
                <div class="flex items-center gap-2 mb-3">
                    <i class="fas fa-pen-to-square text-[#7a3f91]"></i>
                    <h3 class="text-sm font-bold text-gray-900">Rename College</h3>
                </div>
                <p class="text-xs text-gray-600 mb-2">Current: <strong class="text-gray-800">{{ $orgRenamingCollege }}</strong></p>
                <div class="flex gap-2">
                    <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                           class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10" @keydown.enter.prevent="$wire.renameCollege()">
                    <button wire:click="cancelRenamingCollege" class="bg-white border border-gray-300 text-gray-900 px-3 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                            class="bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-1.5 whitespace-nowrap">
                        <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner animate-spin"></i></span>
                        <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save</span>
                    </button>
                </div>
            </div>
            @endif

            @if($orgAddingToCollege)
            <div class="border-2 rounded-xl p-5 border-[#d4aaeb] bg-[#f5eef9]">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                            <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege])?'pencil':'plus' }} text-[#7a3f91]"></i>
                            {{ isset($orgCoursesList[$orgAddingToCollege])?'Edit Departments':'Assign Departments' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">College: <strong class="text-gray-800">{{ $orgAddingToCollege }}</strong></p>
                    </div>
                    <span class="inline-block px-2 py-1 bg-[#7a3f91] text-white rounded-full text-xs font-bold">{{ count($orgSelectedCourseCodes) }} selected</span>
                </div>
                @if($this->allCoursesForAssign->count()>0)
                <p class="text-xs text-gray-600 mb-2.5">Check all courses belonging to this college:</p>
                <div class="space-y-1.5 overflow-y-auto pr-1 mb-4" style="max-height:220px;">
                    @foreach($this->allCoursesForAssign as $c)
                    @php
                        $isSelected   = in_array($c->code, $orgSelectedCourseCodes);
                        $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                        $isTaken      = $otherCollege !== null;
                    @endphp
                    <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition bg-white
                        {{ $isTaken ? 'opacity-50 cursor-not-allowed border-gray-100' : ($isSelected ? 'border-[#7a3f91]/40 shadow-sm' : 'border-gray-100 hover:border-gray-300') }}">
                        <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}"
                               class="w-4 h-4 shrink-0 rounded" style="accent-color:#7a3f91;" {{ $isTaken?'disabled':'' }}>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-gray-900 text-xs font-mono">{{ $c->code }}</span>
                                <span class="text-gray-500 text-xs truncate">{{ $c->name }}</span>
                            </div>
                            @if($isTaken)
                            <p class="text-xs text-amber-700 mt-0.5"><i class="fas fa-lock mr-1"></i>Assigned to: <em>{{ $otherCollege }}</em></p>
                            @endif
                        </div>
                        @if($isSelected && !$isTaken)
                        <i class="fas fa-circle-check shrink-0 text-[#7a3f91]"></i>
                        @endif
                    </label>
                    @endforeach
                </div>
                @else
                <div class="text-center py-5">
                    <i class="fas fa-book text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-gray-500 text-sm">No courses available. Add courses via <strong>Manage Courses</strong>.</p>
                </div>
                @endif
                <div class="flex gap-3">
                    <button wire:click="cancelAddingCourses" class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                            class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                        <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                    </button>
                </div>
            </div>
            @endif

            <div>
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wide mb-2 flex items-center gap-2">
                    <i class="fas fa-list text-gray-400"></i>
                    Colleges &amp; Departments
                    <span class="ml-auto text-xs font-bold text-gray-700 bg-gray-100 px-2 py-1 rounded-full border border-gray-200">
                        {{ count($orgCoursesList) }} {{ count($orgCoursesList)===1?'college':'colleges' }}
                    </span>
                </h3>
                @if(count($orgCoursesList)===0)
                <div class="text-center py-8 border-2 border-dashed border-gray-100 rounded-xl">
                    <i class="fas fa-building-columns text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-gray-400 font-semibold text-sm">No colleges yet</p>
                    <p class="text-gray-300 text-xs mt-1">Add a college above to get started</p>
                </div>
                @else
                <div class="space-y-2.5">
                    @foreach($orgCoursesList as $college => $departments)
                    @php $collegeOccupied=$this->occupiedColleges(); $collegeOrg=$collegeOccupied[$college]??null; @endphp
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 bg-[#f5eef9]">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-[#e9d5f3]">
                                    <i class="fas fa-building-columns text-xs text-[#7a3f91]"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-bold text-sm text-gray-900">{{ $college }}</p>
                                        @if($collegeOrg)
                                            <span class="inline-block px-2 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-xs font-bold"><i class="fas fa-circle-check mr-1 text-xs"></i>{{ $collegeOrg }}</span>
                                        @else
                                            <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-full text-xs font-bold">No Organizer</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">{{ count($departments) }} dept{{ count($departments)!==1?'s':'' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-1.5">
                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')" class="bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#e9d5f3] px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                    <i class="fas fa-pen-to-square text-xs"></i><span class="hidden sm:inline">Rename</span>
                                </button>
                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')" class="bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#e9d5f3] px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                    <i class="fas fa-pencil text-xs"></i><span class="hidden sm:inline">Depts</span>
                                </button>
                                @endif
                                <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')" class="bg-white border border-red-100 text-red-600 hover:bg-red-50 px-2.5 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                    <i class="fas fa-trash text-xs"></i><span class="hidden sm:inline">Delete</span>
                                </button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-50 bg-white">
                            @foreach($departments as $dept)
                            <div class="flex items-center px-4 py-2.5">
                                <span class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold shrink-0 bg-[#f5eef9] text-[#7a3f91]">
                                    {{ strtoupper(substr($dept['code'],0,2)) }}
                                </span>
                                <div class="ml-2.5">
                                    <p class="font-bold text-gray-900 text-xs">{{ $dept['code'] }}</p>
                                    <p class="text-gray-500 text-xs">{{ $dept['name'] }}</p>
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
        <div class="px-5 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl shrink-0">
            <button wire:click="closeModal" class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COLLEGE CONFIRM ────────────────────────────────────── --}}
@if($activeModal==='deleteOrgCollegeConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-4 bg-red-50 border-b border-red-100 rounded-t-2xl">
            <h2 class="text-base font-extrabold text-red-900 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Delete College</h2>
        </div>
        <div class="p-6">
            <p class="text-gray-800 text-sm mb-1">Remove <strong class="text-red-700">{{ $deleteOrgCourseName }}</strong>?</p>
            <p class="text-gray-500 text-xs mb-5"><i class="fas fa-circle-info mr-1"></i>Courses will be unassigned but <strong>not deleted</strong>.</p>
            <div class="flex gap-3">
                <button wire:click="openModal('manageOrgCourses')" class="flex-1 bg-white border border-gray-300 text-gray-900 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="deleteOrgCollege" wire:loading.attr="disabled" wire:target="deleteOrgCollege"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteOrgCollege"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="deleteOrgCollege">Delete College</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── TOGGLE ORGANIZER STATUS ───────────────────────────────────── --}}
@if($activeModal==='toggleOrganizerConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto" @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0 {{ $pendingToggleAction==='deactivate'?'bg-red-100':'bg-green-100' }}">
                    <i class="{{ $pendingToggleAction==='deactivate'?'fas fa-ban text-red-600':'fas fa-circle-check text-green-600' }}"></i>
                </div>
                <div>
                    <p class="font-extrabold text-gray-900 text-base">{{ $pendingToggleAction==='deactivate'?'Deactivate Organizer?':'Activate Organizer?' }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-gray-600 mb-5">
                @if($pendingToggleAction==='deactivate')
                    This organizer will no longer be able to log in. You can reactivate them anytime.
                @else
                    This organizer will regain login access.
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="closeModal" class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeToggleOrganizerStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleOrganizerStatus"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2
                            {{ $pendingToggleAction==='deactivate'?'bg-red-600 hover:bg-red-700 text-white':'bg-green-600 hover:bg-green-700 text-white' }}">
                    <span wire:loading wire:target="executeToggleOrganizerStatus"><i class="fas fa-spinner animate-spin"></i></span>
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