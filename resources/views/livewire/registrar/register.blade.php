<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithFileUploads;

    public string $regFirstName     = '';
    public string $regMiddleInitial = '';
    public string $regLastName      = '';
    public string $regSuffix        = '';
    public string $regStudentId     = '';
    public string $regCourseCode    = '';
    public string $regYear          = '';
    public string $regEmail         = '';

    public bool   $submitting  = false;
    public array  $formErrors  = [];
    public array  $fieldErrors = [];
    public string $successMsg  = '';

    public bool   $showImportModal      = false;
    public        $importFile           = null;
    public string $importFileName       = '';
    public bool   $importingFile        = false;
    public string $importStatus         = '';
    public string $importStep           = 'upload';
    public int    $importProgress       = 0;
    public int    $importTotal          = 0;
    public int    $importSuccessCount   = 0;
    public int    $importFailCount      = 0;
    public int    $importDuplicateCount = 0;
    public array  $importErrors         = [];
    public array  $importDuplicates     = [];

    public bool   $headerChecking = false;
    public bool   $headerValid    = false;
    public string $headerCheckMsg = '';

    public function mount(): void
    {
        $this->regYear = (string) date('Y');
    }

    #[\Livewire\Attributes\Computed]
    public function courses() { return Course::orderBy('code')->get(); }

    private function generateTempPassword(string $paddedId, string $lastName): string
    {
        $raw  = substr(trim($lastName), 0, 2);
        $part = ucfirst(strtolower($raw));
        return $paddedId . '_' . $part;
    }

    private function validateName(string $n): bool
    {
        return (bool) preg_match('/^[a-zA-Z\s\-\.\']+$/', $n);
    }

    private function buildFullName(string $f, string $m, string $l, string $s): string
    {
        return implode(' ', array_filter(array_map('trim', [$f, $m, $l, $s])));
    }

    private function collectErrors(): array
    {
        $errors      = [];
        $fieldErrors = [];

        $firstName = trim($this->regFirstName);
        if (!$firstName) {
            $errors[]      = 'First name is required.';
            $fieldErrors[] = 'firstName';
        } elseif (!$this->validateName($firstName)) {
            $errors[]      = 'First name may only contain letters, spaces, hyphens, or apostrophes.';
            $fieldErrors[] = 'firstName';
        }

        $lastName = trim($this->regLastName);
        if (!$lastName) {
            $errors[]      = 'Last name is required.';
            $fieldErrors[] = 'lastName';
        } elseif (!$this->validateName($lastName)) {
            $errors[]      = 'Last name may only contain letters, spaces, hyphens, or apostrophes.';
            $fieldErrors[] = 'lastName';
        }

        $mid = trim($this->regMiddleInitial);
        if ($mid === '') {
            $errors[]      = 'Middle name is required.';
            $fieldErrors[] = 'middleName';
        } else {
            if (!preg_match('/^[a-zA-Z]+$/', $mid)) {
                $errors[]      = 'Middle name must contain letters only.';
                $fieldErrors[] = 'middleName';
            } elseif (strlen($mid) < 2) {
                $errors[]      = 'Middle name must be a full word (e.g. Santos, not S).';
                $fieldErrors[] = 'middleName';
            }
        }

        $suffix = trim($this->regSuffix);
        if ($suffix !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', $suffix)) {
            $errors[]      = 'Suffix may only contain letters and periods (e.g. Jr. Sr. III).';
            $fieldErrors[] = 'suffix';
        }

        $studentId = trim($this->regStudentId);
        if (!$studentId) {
            $errors[]      = 'Student ID is required.';
            $fieldErrors[] = 'studentId';
        } elseif (!preg_match('/^\d{1,8}$/', $studentId)) {
            $errors[]      = 'Student ID must be 1-8 digits (numbers only).';
            $fieldErrors[] = 'studentId';
        } else {
            $paddedId = str_pad($studentId, 8, '0', STR_PAD_LEFT);
            if (Alumni::where('student_id', $paddedId)->exists()) {
                $errors[]      = 'This Student ID is already registered.';
                $fieldErrors[] = 'studentId';
            }
        }

        if (!trim($this->regCourseCode)) {
            $errors[]      = 'Please select a course.';
            $fieldErrors[] = 'course';
        } elseif (!Course::where('code', $this->regCourseCode)->exists()) {
            $errors[]      = 'The selected course does not exist.';
            $fieldErrors[] = 'course';
        }

        $year = trim($this->regYear);
        if (!$year) {
            $errors[]      = 'Batch year is required.';
            $fieldErrors[] = 'year';
        } elseif (!preg_match('/^\d{4}$/', $year)) {
            $errors[]      = 'Batch year must be exactly 4 digits.';
            $fieldErrors[] = 'year';
        }

        $email = trim($this->regEmail);
        if (!$email) {
            $errors[]      = 'Email address is required.';
            $fieldErrors[] = 'email';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[]      = 'Please enter a valid email address.';
            $fieldErrors[] = 'email';
        } elseif (Alumni::whereNotNull('email')->whereRaw('LOWER(TRIM(email))=?', [strtolower($email)])->exists()) {
            $errors[]      = 'This email address is already registered.';
            $fieldErrors[] = 'email';
        }

        return ['errors' => $errors, 'fields' => $fieldErrors];
    }

    public function registerAlumni(): void
    {
        $this->formErrors  = [];
        $this->fieldErrors = [];
        $this->successMsg  = '';
        $this->submitting  = true;

        try {
            $result = $this->collectErrors();

            if (!empty($result['errors'])) {
                $this->formErrors  = ['general' => $result['errors']];
                $this->fieldErrors = $result['fields'];
                return;
            }

            $paddedId = str_pad($this->regStudentId, 8, '0', STR_PAD_LEFT);
            $mid      = trim($this->regMiddleInitial);
            $email    = trim($this->regEmail);
            $course   = Course::where('code', $this->regCourseCode)->firstOrFail();
            $fullName = $this->buildFullName(trim($this->regFirstName), $mid, trim($this->regLastName), trim($this->regSuffix));
            $tmpPass  = $this->generateTempPassword($paddedId, trim($this->regLastName));

            $user = User::create([
                'name'     => $fullName,
                'role'     => 'alumni',
                'email'    => $paddedId . '@pending.local',
                'password' => Hash::make($tmpPass),
            ]);

            Alumni::create([
                'user_id'             => $user->id,
                'first_name'          => trim($this->regFirstName),
                'middle_initial'      => $mid ?: null,
                'last_name'           => trim($this->regLastName),
                'suffix'              => trim($this->regSuffix) ?: null,
                'student_id'          => $paddedId,
                'email'               => $email,
                'course_code'         => $this->regCourseCode,
                'course_name'         => $course->name,
                'batch'               => (int) $this->regYear,
                'status'              => 'VERIFIED',
                'password_changed_at' => now(),
                'profile_photo'       => null,
                'profile_completed'   => 0,
            ]);

            $this->successMsg = "Alumni '{$fullName}' has been registered successfully.";
            $this->dispatch('alumni-registered', name: $fullName, id: $paddedId);
            $this->resetForm();

        } catch (\Exception $e) {
            Log::error('Alumni register error: ' . $e->getMessage());
            $this->formErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->submitting = false;
        }
    }

    public function resetForm(): void
    {
        $this->regFirstName = $this->regMiddleInitial = $this->regLastName = $this->regSuffix = '';
        $this->regStudentId = $this->regCourseCode = $this->regEmail = '';
        $this->regYear      = (string) date('Y');
        $this->formErrors   = [];
        $this->fieldErrors  = [];
    }

    public function clearSuccess(): void
    {
        $this->successMsg = '';
    }

    public function openImportModal(): void
    {
        $this->resetImport();
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        if ($this->importStep === 'processing') return;
        $this->showImportModal = false;
        $this->resetImport();
    }

    public function resetImport(): void
    {
        $this->importFile           = null;
        $this->importFileName       = '';
        $this->importingFile        = false;
        $this->importStatus         = '';
        $this->importStep           = 'upload';
        $this->importProgress       = 0;
        $this->importTotal          = 0;
        $this->importSuccessCount   = 0;
        $this->importFailCount      = 0;
        $this->importDuplicateCount = 0;
        $this->importErrors         = [];
        $this->importDuplicates     = [];
        $this->headerChecking       = false;
        $this->headerValid          = false;
        $this->headerCheckMsg       = '';
    }

    public function updatedImportFile(): void
    {
        if (!$this->importFile) return;

        $ext = strtolower($this->importFile->getClientOriginalExtension());

        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            $this->importFile     = null;
            $this->importFileName = '';
            $this->importStatus   = 'invalid_type';
            $this->importStep     = 'upload';
            return;
        }

        $this->importStatus   = '';
        $this->importFileName = $this->importFile->getClientOriginalName();
        $this->importStep     = 'preview';

        $this->headerChecking = true;
        $this->headerValid    = false;
        $this->headerCheckMsg = '';
    }

    public function backToUpload(): void
    {
        $this->importFile     = null;
        $this->importFileName = '';
        $this->importStatus   = '';
        $this->importStep     = 'upload';
        $this->headerChecking = false;
        $this->headerValid    = false;
        $this->headerCheckMsg = '';
    }

    public function confirmImport(): void
    {
        if (!$this->headerValid) return;

        $this->importStep    = 'processing';
        $this->importStatus  = 'Starting...';
        $this->importingFile = true;
    }

    private function parseExcel(string $path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $sheet      = $reader->load($path)->getActiveSheet();
            $rows       = [];
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();

            foreach ($sheet->getRowIterator(1, $highestRow) as $row) {
                $rd = [];
                $ci = $row->getCellIterator('A', $highestCol);
                $ci->setIterateOnlyExistingCells(false);
                foreach ($ci as $cell) $rd[] = $cell->getValue();
                $rows[] = $rd;
            }
            return $rows;
        } catch (\Exception $e) {
            throw new \Exception('Excel parse failed: ' . $e->getMessage());
        }
    }

    private function peekHeaderRow(string $path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1;
                }
            });
            $sheet      = $reader->load($path)->getActiveSheet();
            $highestCol = $sheet->getHighestDataColumn();
            $rows       = [];

            foreach ($sheet->getRowIterator(1, 1) as $row) {
                $rd = [];
                $ci = $row->getCellIterator('A', $highestCol);
                $ci->setIterateOnlyExistingCells(false);
                foreach ($ci as $cell) $rd[] = $cell->getValue();
                $rows[] = $rd;
            }
            return $rows;
        } catch (\Exception $e) {
            throw new \Exception('Could not read the file: ' . $e->getMessage());
        }
    }

    public function checkFileHeaders(): void
    {
        $this->headerChecking = true;

        try {
            if (!$this->importFile) {
                $this->headerValid    = false;
                $this->headerCheckMsg = 'No file selected.';
                return;
            }

            $rows = $this->peekHeaderRow($this->importFile->getRealPath());

            if (empty($rows) || empty(array_filter($rows[0], fn($v) => trim((string) $v) !== ''))) {
                $this->headerValid    = false;
                $this->headerCheckMsg = 'The file appears to be empty.';
                return;
            }

            $header = array_map('trim', array_map('strtolower', $rows[0]));

            $hasCourse     = in_array('course', $header, true);
            $hasCourseCode = in_array('course_code', $header, true);

            $required = ['first_name', 'last_name', 'middle_name', 'student_id', 'course', 'batch', 'email'];
            $missing  = [];

            foreach ($required as $col) {
                if ($col === 'course') {
                    if (!$hasCourse && !$hasCourseCode) $missing[] = 'course';
                    continue;
                }
                if (!in_array($col, $header, true)) $missing[] = $col;
            }

            if (!empty($missing)) {
                $this->headerValid    = false;
                $this->headerCheckMsg = 'Missing required column' . (count($missing) > 1 ? 's' : '') . ': '
                    . implode(', ', array_map(fn($c) => "\"{$c}\"", $missing)) . '.';
                return;
            }

            $this->headerValid    = true;
            $this->headerCheckMsg = '';

        } catch (\Exception $e) {
            $this->headerValid    = false;
            $this->headerCheckMsg = $e->getMessage();
        } finally {
            $this->headerChecking = false;
        }
    }

    private function appendImportError(array &$errors, int $max, string $msg): void
    {
        $this->importFailCount++;
        if (count($errors) < $max)       $errors[] = $msg;
        elseif (count($errors) === $max) $errors[] = '... (additional errors truncated)';
    }

    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function processImport(): void
    {
        if (function_exists('set_time_limit')) @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $this->importStatus         = 'Validating...';
        $this->importProgress       = 0;
        $this->importSuccessCount   = 0;
        $this->importFailCount      = 0;
        $this->importDuplicateCount = 0;
        $this->importErrors         = [];
        $this->importDuplicates     = [];

        try {
            if (!$this->importFile) throw new \Exception('No file selected.');

            $ext = strtolower($this->importFile->getClientOriginalExtension());

            if (!in_array($ext, ['xlsx', 'xls'], true))
                throw new \Exception('File must be .xlsx or .xls. CSV is not accepted.');

            $rows = $this->parseExcel($this->importFile->getRealPath());

            if (count($rows) < 2) throw new \Exception('File is empty or has no data rows.');

            $header = array_map('trim', array_map('strtolower', $rows[0]));

            $hasCourse     = in_array('course', $header, true);
            $hasCourseCode = in_array('course_code', $header, true);
            if (!$hasCourse && !$hasCourseCode)
                throw new \Exception('Missing required column: "course".');
            if (!$hasCourse && $hasCourseCode)
                $header[array_search('course_code', $header)] = 'course';

            $required = ['first_name', 'last_name', 'middle_name', 'student_id', 'course', 'batch', 'email'];
            foreach ($required as $col)
                if (!in_array($col, $header, true))
                    throw new \Exception("Missing required column: \"{$col}\".");

            $this->importTotal = count($rows) - 1;

            $allCourses   = Course::all();
            $courseByCode = $allCourses->keyBy(fn($c) => strtoupper(preg_replace('/\s+/', ' ', trim($c->code))));
            $courseByName = $allCourses->keyBy(fn($c) => strtolower(preg_replace('/\s+/', ' ', trim($c->name))));

            $existingIds = DB::table('alumni')->pluck('student_id')->flip()->toArray();

            $existingEmails = DB::table('alumni')
                                ->whereNotNull('email')->where('email', '!=', '')
                                ->pluck('email')->map(fn($e) => strtolower($e))->flip()->toArray();

            $jobs = $seenIds = $seenEmails = [];
            $validationErrors = $duplicates = [];
            $maxErrors = 200;

            for ($i = 1; $i < count($rows); $i++) {
                if (count(array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) === 0) continue;
                if (count($rows[$i]) < count($header)) continue;

                $row       = array_combine($header, array_slice($rows[$i], 0, count($header)));
                $firstName = trim($row['first_name'] ?? '');
                $mid       = preg_replace('/\s+/', ' ', trim($row['middle_name'] ?? ''));
                $lastName  = trim($row['last_name']  ?? '');
                $suffix    = trim($row['suffix']     ?? '');
                $email     = strtolower(trim($row['email'] ?? ''));
                $fullName  = $this->buildFullName($firstName, $mid, $lastName, $suffix);
                $label     = 'Row '.($i + 1).($fullName ? " ({$fullName})" : '');

                if (!$email) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Email is required."); continue; }
                if (!$this->validateEmail($email)) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Email \"{$email}\" is invalid."); continue; }
                if (isset($existingEmails[$email]) || isset($seenEmails[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }

                if (!$firstName) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name has invalid characters."); continue; }
                if (!$lastName)  { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name has invalid characters."); continue; }
                if ($mid === '') { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name is required."); continue; }
                if (!preg_match('/^[a-zA-Z][a-zA-Z ]*[a-zA-Z]$|^[a-zA-Z]$/', $mid)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name must contain letters only."); continue;
                }
                $midError = false;
                foreach (explode(' ', $mid) as $part) {
                    if (strlen($part) < 2) {
                        $this->appendImportError($validationErrors, $maxErrors, "{$label}: Each word in middle name must be >=2 chars."); $midError = true; break;
                    }
                }
                if ($midError) continue;

                $rawId      = preg_replace('/\..*$/', '', rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.'));
                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int)$rawIdClean === 0) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Student ID \"{$rawId}\" is invalid."); continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);
                if (isset($existingIds[$sid]) || isset($seenIds[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    $this->importDuplicateCount++;
                    continue;
                }

                $courseInput = preg_replace('/\s+/', ' ', trim($row['course'] ?? ''));
                $courseMatch = $courseByCode[strtoupper($courseInput)] ?? $courseByName[strtolower($courseInput)] ?? null;
                if (!$courseMatch) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Course \"{$courseInput}\" not found."); continue; }

                $batchYear = (int)($row['batch'] ?? 0);
                if ($batchYear < 1000 || $batchYear > 9999) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Batch \"{$batchYear}\" must be 4-digit year."); continue; }

                $jobs[] = compact('fullName','firstName','mid','lastName','suffix','email','sid','batchYear') + ['code' => $courseMatch->code, 'courseName' => $courseMatch->name];
                $seenIds[$sid]      = true;
                $seenEmails[$email] = true;
            }

            $this->importErrors     = $validationErrors;
            $this->importDuplicates = $duplicates;

            if (empty($jobs)) {
                $this->importStatus  = 'Done';
                $this->importStep    = 'done';
                $this->importingFile = false;
                $this->importFile    = null;
                return;
            }

            $this->importStatus = 'Importing...';
            $now = now()->toDateTimeString();

            $hashedPasswords = [];
            foreach ($jobs as $job) {
                $plain = $this->generateTempPassword($job['sid'], $job['lastName']);
                $hashedPasswords[$job['sid']] = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
            }

            DB::transaction(function () use ($jobs, $now, $hashedPasswords) {
                foreach (array_chunk($jobs, 500) as $chunkIdx => $chunk) {
                    $userRows    = [];
                    $loginEmails = [];
                    foreach ($chunk as $job) {
                        $userRows[]    = [
                            'name'       => $job['fullName'],
                            'role'       => 'alumni',
                            'email'      => $job['email'],
                            'password'   => $hashedPasswords[$job['sid']],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $loginEmails[] = $job['email'];
                    }
                    DB::table('users')->insert($userRows);
                    $userIdMap = DB::table('users')->whereIn('email', $loginEmails)->pluck('id', 'email')->toArray();

                    $alumniRows = [];
                    foreach ($chunk as $job) {
                        $uid = $userIdMap[$job['email']] ?? null;
                        if (!$uid) continue;
                        $alumniRows[] = [
                            'user_id'              => $uid,
                            'first_name'           => $job['firstName'],
                            'middle_initial'       => $job['mid'] ?: null,
                            'last_name'            => $job['lastName'],
                            'suffix'               => $job['suffix'] ?: null,
                            'student_id'           => $job['sid'],
                            'email'                => $job['email'],
                            'course_code'          => $job['code'],
                            'course_name'          => $job['courseName'],
                            'batch'                => $job['batchYear'],
                            'status'               => 'VERIFIED',
                            'password_changed_at'  => $now,
                            'profile_photo'        => null,
                            'profile_completed'    => 0,
                            'gender'               => null,
                            'date_of_birth'        => null,
                            'contact_number'       => null,
                            'disability'           => null,
                            'dswd_household_no'    => null,
                            'father_last_name'     => null,
                            'father_given_name'    => null,
                            'father_middle_name'   => null,
                            'mother_last_name'     => null,
                            'mother_given_name'    => null,
                            'mother_middle_name'   => null,
                            'address_street'       => null,
                            'address_barangay'     => null,
                            'address_municipality' => null,
                            'address_province'     => null,
                            'created_at'           => $now,
                            'updated_at'           => $now,
                        ];
                    }
                    if (!empty($alumniRows)) {
                        DB::table('alumni')->insert($alumniRows);
                        $this->importSuccessCount += count($alumniRows);
                    }
                    $this->importProgress = min(
                        $this->importTotal,
                        (int) round(($chunkIdx + 1) / max(1, ceil(count($jobs) / 500)) * $this->importTotal)
                    );
                }
            });

            $this->importProgress = $this->importTotal;
            $this->importStatus   = 'Done';
            $this->importStep     = 'done';
            $this->importingFile  = false;
            $this->importFile     = null;
            Log::info("Alumni import: {$this->importSuccessCount} inserted, {$this->importFailCount} errors, {$this->importDuplicateCount} duplicates.");

            $this->dispatch('alumni-imported', count: $this->importSuccessCount);

        } catch (\Exception $e) {
            Log::error('Import error: ' . $e->getMessage());
            $this->importStatus  = 'Error: ' . $e->getMessage();
            $this->importStep    = 'blocked';
            $this->importingFile = false;
        }
    }
};
?>

<div>

<style>
    /* ══ FLOATING LABEL INPUTS ══ */
    .fl-group { position: relative; }

    .fl-input {
        font-family: 'Inter', sans-serif;
        font-size: 1rem;
        font-weight: 500;
        color: #111111;
        width: 100%;
        height: 60px;
        padding: 22px 1rem 8px 3rem;
        background: #ffffff;
        border: 1.5px solid #DDDDDD;
        border-radius: 10px;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .fl-input:focus {
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,0.08);
    }
    .fl-input.no-icon { padding-left: 1rem; }

    /* ── Red error state for inputs ── */
    .fl-input.field-error {
        border-color: #f87171 !important;
        background: #fff8f8;
        box-shadow: 0 0 0 3px rgba(248,113,113,0.10);
    }
    .fl-input.field-error:focus {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 3px rgba(239,68,68,0.13);
    }
    .fl-group:has(.field-error) .fl-icon { color: #f87171; }

    /* ── Red error state for dropdowns ── */
    .reg-dropdown-trigger.field-error {
        border-color: #f87171 !important;
        background: #fff8f8;
        box-shadow: 0 0 0 3px rgba(248,113,113,0.10);
    }
    .reg-dropdown-trigger.field-error .reg-trigger-label { color: #ef4444 !important; }
    .reg-dropdown-trigger.field-error .reg-trigger-icon  { color: #f87171 !important; }

    .fl-label {
        position: absolute;
        left: 3rem;
        top: 50%;
        transform: translateY(-50%);
        font-family: 'Inter', sans-serif;
        font-size: 0.95rem;
        font-weight: 400;
        color: #555555;
        pointer-events: none;
        transition: all 0.18s cubic-bezier(.4,0,.2,1);
        background: #ffffff;
        padding: 0 0.2rem;
        line-height: 1;
    }
    .fl-label.no-icon { left: 1rem; }
    .fl-input:focus ~ .fl-label,
    .fl-input:not(:placeholder-shown) ~ .fl-label {
        top: 0;
        transform: translateY(-50%);
        font-size: 0.65rem;
        font-weight: 700;
        color: #7A3F91;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .fl-input.field-error ~ .fl-label { color: #ef4444 !important; }

    .fl-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.85rem;
        color: #666666;
        pointer-events: none;
        transition: color 0.2s ease;
        z-index: 1;
    }
    .fl-group:focus-within .fl-icon { color: #7A3F91; }

    /* ── Dropdown trigger ── */
    .reg-dropdown { position: relative; }

    .reg-dropdown-trigger {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        width: 100%;
        height: 60px;
        padding: 22px 12px 8px 3rem;
        border: 1.5px solid #DDDDDD;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 500;
        background: #fff;
        color: #111111;
        cursor: pointer;
        transition: border-color .2s, box-shadow .2s;
        white-space: nowrap;
        user-select: none;
        position: relative;
    }
    .reg-dropdown-trigger:hover { border-color: #c49ed8; }
    .reg-dropdown-trigger.has-value { border-color: #7A3F91; }
    .reg-dropdown-trigger.open {
        border-color: #7A3F91;
        box-shadow: 0 0 0 3px rgba(122,63,145,0.08);
    }

    .reg-trigger-label {
        position: absolute;
        left: 3rem;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.95rem;
        font-weight: 400;
        color: #555555;
        pointer-events: none;
        transition: all 0.18s cubic-bezier(.4,0,.2,1);
        background: #ffffff;
        padding: 0 0.2rem;
        line-height: 1;
    }
    .reg-dropdown-trigger.has-value .reg-trigger-label,
    .reg-dropdown-trigger.open .reg-trigger-label {
        top: 0;
        transform: translateY(-50%);
        font-size: 0.65rem;
        font-weight: 700;
        color: #7A3F91;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .reg-dropdown-trigger.field-error.has-value .reg-trigger-label,
    .reg-dropdown-trigger.field-error.open .reg-trigger-label { color: #ef4444 !important; }

    .reg-trigger-value {
        position: absolute;
        left: 3rem;
        bottom: 10px;
        font-size: 1rem;
        font-weight: 500;
        color: #111111;
        pointer-events: none;
    }
    .reg-trigger-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.85rem;
        color: #666666;
        pointer-events: none;
        transition: color .2s;
    }
    .reg-dropdown-trigger.open .reg-trigger-icon,
    .reg-dropdown-trigger.has-value .reg-trigger-icon { color: #7A3F91; }
    .reg-trigger-chevron {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.62rem;
        opacity: .5;
        transition: transform .18s;
    }
    .reg-dropdown-trigger.open .reg-trigger-chevron { transform: translateY(-50%) rotate(180deg); }

    /* ── Picker panel ── */
    .reg-picker-panel {
        position: absolute;
        bottom: calc(100% + 8px);
        left: 0;
        background: #fff;
        border: 1.5px solid #E8E0F0;
        border-radius: 14px;
        box-shadow: 0 -4px 6px rgba(0,0,0,.03), 0 16px 40px rgba(122,63,145,.18);
        z-index: 600;
        overflow: hidden;
    }
    .reg-picker-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 12px 8px;
        border-bottom: 1px solid #F0E6F8;
        background: #FDFAFF;
    }
    .reg-picker-range-label {
        font-size: .82rem;
        font-weight: 700;
        color: #7A3F91;
        letter-spacing: .03em;
        white-space: nowrap;
    }
    .reg-picker-nav-btn {
        width: 28px; height: 28px;
        border-radius: 8px;
        border: 1.5px solid #E8E0F0;
        background: #fff;
        color: #7A3F91;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: background .13s, border-color .13s;
        flex-shrink: 0;
    }
    .reg-picker-nav-btn:hover:not(:disabled) { background: #F3E8FF; border-color: #c49ed8; }
    .reg-picker-nav-btn:disabled { opacity: .3; cursor: not-allowed; }
    .reg-picker-grid { display: grid; gap: 4px; padding: 10px; }
    .reg-picker-cell {
        padding: 7px 4px;
        border-radius: 8px;
        border: 1.5px solid transparent;
        font-size: .78rem; font-weight: 600; color: #444;
        background: transparent;
        cursor: pointer; text-align: center;
        transition: background .12s, color .12s, border-color .12s;
        line-height: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .reg-picker-cell:hover { background: #F3E8FF; color: #7A3F91; }
    .reg-picker-cell--selected { background: #7A3F91 !important; color: #fff !important; border-color: #7A3F91 !important; }
    .reg-picker-cell--today { border-color: #c49ed8; color: #7A3F91; background: #FDFAFF; }
    .reg-picker-footer {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 10px 10px;
        border-top: 1px solid #F0E6F8;
        background: #FDFAFF;
        gap: 6px;
    }
    .reg-picker-clear-btn {
        font-size: .72rem; font-weight: 700; color: #999;
        background: none; border: none; cursor: pointer;
        padding: 4px 8px; border-radius: 6px;
        transition: color .12s, background .12s;
    }
    .reg-picker-clear-btn:hover { color: #dc2626; background: #fef2f2; }
    .reg-picker-action-btn {
        font-size: .72rem; font-weight: 700; color: #7A3F91;
        background: #F0E6F8; border: none; cursor: pointer;
        padding: 4px 10px; border-radius: 6px;
        transition: background .12s; margin-left: auto;
    }
    .reg-picker-action-btn:hover { background: #E4D0F5; }
    .reg-course-picker { width: 320px; }
    .reg-year-picker   { width: 268px; }

    /* ── Suffix scrollable dropdown ── */
    .reg-suffix-panel {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        width: 100%;
        background: #fff;
        border: 1.5px solid #E8E0F0;
        border-radius: 14px;
        box-shadow: 0 -4px 6px rgba(0,0,0,.03), 0 16px 40px rgba(122,63,145,.18);
        z-index: 600;
        overflow: hidden;
    }
    .reg-suffix-header {
        padding: 10px 14px 8px;
        border-bottom: 1px solid #F0E6F8;
        background: #FDFAFF;
    }
    .reg-suffix-title {
        font-size: .75rem; font-weight: 700; color: #7A3F91;
        letter-spacing: .05em; text-transform: uppercase;
    }
    .reg-suffix-list {
        max-height: 200px;
        overflow-y: auto;
        padding: 6px;
        scrollbar-width: thin;
        scrollbar-color: #c49ed8 #F9F5FF;
    }
    .reg-suffix-list::-webkit-scrollbar { width: 5px; }
    .reg-suffix-list::-webkit-scrollbar-track { background: #F9F5FF; border-radius: 99px; }
    .reg-suffix-list::-webkit-scrollbar-thumb { background: #c49ed8; border-radius: 99px; }
    .reg-suffix-item {
        width: 100%; text-align: left;
        padding: 9px 12px;
        border-radius: 8px;
        border: none; background: transparent;
        font-size: .88rem; font-weight: 600; color: #333;
        cursor: pointer;
        transition: background .12s, color .12s;
        display: flex; align-items: center; gap: 8px;
    }
    .reg-suffix-item:hover { background: #F3E8FF; color: #7A3F91; }
    .reg-suffix-item--selected { background: #7A3F91 !important; color: #fff !important; }
    .reg-suffix-footer {
        padding: 8px 10px;
        border-top: 1px solid #F0E6F8;
        background: #FDFAFF;
    }
    .reg-suffix-clear {
        font-size: .72rem; font-weight: 700; color: #999;
        background: none; border: none; cursor: pointer;
        padding: 4px 8px; border-radius: 6px;
        transition: color .12s, background .12s;
        width: 100%; text-align: center;
    }
    .reg-suffix-clear:hover { color: #dc2626; background: #fef2f2; }

    /* ── Import tooltip ── */
    .import-btn-wrap { position: relative; display: inline-flex; }
    .import-btn-tooltip {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: #1a1a1a;
        color: #fff;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: .03em;
        padding: 7px 14px;
        border-radius: 8px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 99;
        box-shadow: 0 4px 14px rgba(0,0,0,.25);
    }
    .import-btn-tooltip::after {
        content: '';
        position: absolute;
        bottom: 100%; right: 12px;
        border: 6px solid transparent;
        border-bottom-color: #1a1a1a;
    }
    .import-btn-wrap:hover .import-btn-tooltip { opacity: 1; }
    /* Tooltip never shows on touch / small screens */
    @media (max-width: 768px), (hover: none) {
        .import-btn-tooltip { display: none !important; }
    }

    /* ══ SUCCESS TOAST — compact auto-dismiss ══ */
    @keyframes successSlideIn {
        from { opacity: 0; transform: translateY(-10px) scale(.97); }
        to   { opacity: 1; transform: translateY(0)    scale(1); }
    }
    @keyframes successSlideOut {
        from { opacity: 1; transform: translateY(0) scale(1); max-height: 60px; margin-bottom: 1rem; }
        to   { opacity: 0; transform: translateY(-8px) scale(.97); max-height: 0; margin-bottom: 0; }
    }
    .success-toast {
        animation: successSlideIn .25s cubic-bezier(.4,0,.2,1) both;
        overflow: hidden;
    }
    .success-toast.dismissing {
        animation: successSlideOut .3s cubic-bezier(.4,0,.2,1) forwards;
    }

    /* ── Dropzone shimmer ── */
    @keyframes regShimmer {
        0%   { background-position: -200% 0; }
        100% { background-position:  200% 0; }
    }
    .reg-drop-loading {
        background: linear-gradient(90deg,#f9f5ff 25%,#ede5f7 50%,#f9f5ff 75%);
        background-size: 200% 100%;
        animation: regShimmer 1.2s infinite linear;
    }

    /* ── Preview scanning pulse ── */
    @keyframes regScanPulse {
        0%   { transform: scale(1);    opacity: .55; }
        70%  { transform: scale(1.55); opacity: 0; }
        100% { transform: scale(1.55); opacity: 0; }
    }
    .reg-scan-ring {
        position: absolute;
        inset: 0;
        border-radius: 16px;
        border: 2px solid #21A366;
        animation: regScanPulse 1.4s cubic-bezier(.4,0,.6,1) infinite;
    }
    .reg-scan-ring--delay { animation-delay: .55s; }

    /* ── GREEN importing scan rings ── */
    @keyframes importScanPulse {
        0%   { transform: scale(1);    opacity: .6; }
        70%  { transform: scale(1.6);  opacity: 0; }
        100% { transform: scale(1.6);  opacity: 0; }
    }
    .import-scan-ring {
        position: absolute;
        inset: 0;
        border-radius: 20px;
        border: 2.5px solid #22c55e;
        animation: importScanPulse 1.5s cubic-bezier(.4,0,.6,1) infinite;
        pointer-events: none;
    }
    .import-scan-ring--delay1 { animation-delay: .5s; }
    .import-scan-ring--delay2 { animation-delay: 1s; }

    /* ── Green orbit dots ── */
    @keyframes importOrbit {
        from { transform: rotate(0deg) translateX(36px) rotate(0deg); }
        to   { transform: rotate(360deg) translateX(36px) rotate(-360deg); }
    }
    .import-orbit-dot {
        position: absolute;
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #22c55e;
        top: calc(50% - 4px);
        left: calc(50% - 4px);
        animation: importOrbit 2s linear infinite;
        box-shadow: 0 0 6px rgba(34,197,94,.8);
    }
    .import-orbit-dot--2 { animation-delay: -.666s; background: #4ade80; }
    .import-orbit-dot--3 { animation-delay: -1.333s; background: #86efac; }

    /* ── Green progress bar ── */
    @keyframes greenBarShine {
        0%   { transform: translateX(-100%); }
        100% { transform: translateX(300%); }
    }
    .reg-progress-track {
        position: relative;
        width: 100%;
        height: 12px;
        border-radius: 999px;
        background: #dcfce7;
        overflow: hidden;
    }
    .reg-progress-fill {
        position: relative;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #16a34a, #22c55e, #4ade80);
        transition: width .45s cubic-bezier(.4,0,.2,1);
        overflow: hidden;
    }
    .reg-progress-fill::after {
        content: '';
        position: absolute; top: 0; left: 0; bottom: 0; width: 40%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent);
        animation: greenBarShine 1.4s infinite linear;
    }

    /* ── Required columns — horizontal wrap chips ── */
    .req-col-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 12px 14px;
    }
    .req-col-chip {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .78rem;
        font-weight: 700;
        color: #1D4ED8;
        background: #EAF1FF;
        border: 1px solid #D2E2FF;
        border-radius: 6px;
        padding: 4px 10px;
        white-space: nowrap;
    }

    /* ── Page card ── */
    .reg-card {
        background:#fff;
        border:1px solid #ECE4F4;
        border-radius:18px;
        box-shadow:0 1px 2px rgba(20,10,30,.03), 0 8px 24px rgba(122,63,145,.05);
    }

    /* ── Success pop (done step) ── */
    @keyframes regPopIn {
        0%   { transform: scale(.4); opacity:0; }
        60%  { transform: scale(1.08); opacity:1; }
        100% { transform: scale(1); }
    }
    .reg-pop { animation: regPopIn .38s cubic-bezier(.34,1.56,.64,1) both; }

    /* ── Stat tile ── */
    .reg-stat-tile {
        border-radius: 12px;
        padding: 12px 8px;
        text-align: center;
    }

    /* ── Import modal: fixed size on desktop, full screen on mobile ── */
    .import-modal-box {
        width: 640px;
        height: 750px;
        max-width: calc(100vw - 32px);
        max-height: calc(100dvh - 40px);
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 25px 60px rgba(27,6,46,0.30);
        overflow: hidden;
        animation: panelIn .22s cubic-bezier(.4,0,.2,1) both;
        flex-shrink: 0;
    }
    @keyframes panelIn {
        from { opacity:0; transform:translateY(14px) scale(.98); }
        to   { opacity:1; transform:none; }
    }
    @media (max-width: 640px) {
        .import-modal-box {
            width: 100vw;
            height: 100dvh;
            max-width: 100vw;
            max-height: 100dvh;
            border-radius: 0;
        }
    }

    /* Scrollable result lists in done step */
    .import-result-list {
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #c49ed8 transparent;
    }
    .import-result-list::-webkit-scrollbar { width: 4px; }
    .import-result-list::-webkit-scrollbar-thumb { background: #c49ed8; border-radius: 99px; }

    /* ── Modal close-button tooltip: hide on mobile / touch ── */
    @media (max-width: 768px), (hover: none) {
        .import-modal-box .group span.pointer-events-none { display: none !important; }
    }

    /* ── Page scroll area (contains the page inside the viewport, no bottom buffer) ── */
    #reg-scroll {
        scrollbar-width: thin;
        scrollbar-color: #d4b8e8 transparent;
    }
    #reg-scroll::-webkit-scrollbar { width: 6px; }
    #reg-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

    /* ── Panel entrance animation (same as alumni-records) ── */
    @keyframes regPanelIn {
        from { opacity:0; transform:translateY(10px) scale(.99); }
        to   { opacity:1; transform:none; }
    }
    .reg-panel { animation: regPanelIn .18s cubic-bezier(.4,0,.2,1) both; }

    /* ── Page height: full screen, dvh-based so mobile browser chrome doesn't clip it ── */
    .reg-page-height {
        height: calc(100dvh - 180px);
        max-height: calc(100dvh - 180px);
        overflow: hidden;
    }
    @media (max-width: 640px) {
        .reg-page-height {
            height: calc(100dvh - 110px);
            max-height: calc(100dvh - 110px);
        }
    }

    /* ── MOBILE: Register Alumni page goes edge-to-edge, no container/box look ── */
    @media (max-width: 640px) {
        .reg-card {
            border-radius: 0;
            border-left: none;
            border-right: none;
            box-shadow: none;
        }
    }
</style>

{{-- ══ PAGE ══ --}}
<div class="flex flex-col px-0 sm:px-5 lg:px-6 pt-0 sm:pt-4 pb-3 max-w-screen-2xl mx-auto reg-page-height">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-3 mb-3 px-3 sm:px-0 pt-3 sm:pt-0 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-user-plus text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-2xl font-semibold text-[#333333] leading-tight">Register Alumni</h1>
                <p class="text-[#666666] text-xs sm:text-sm font-normal">Add new alumni to the system with their details and credentials</p>
            </div>
        </div>

        <div class="import-btn-wrap">
            <span class="import-btn-tooltip">Import from Excel</span>
            <button wire:click="openImportModal"
                    class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center text-white shadow-lg transition hover:shadow-xl shrink-0"
                    style="background:linear-gradient(135deg,#2563EB,#3B82F6);">
                <i class="fas fa-file-import text-sm"></i>
            </button>
        </div>
    </div>

    {{-- ══ SUCCESS TOAST ══ --}}
    @if($successMsg)
    <div
        x-data="{
            show: true,
            timer: null,
            init() {
                this.timer = setTimeout(() => {
                    this.$el.classList.add('dismissing');
                    setTimeout(() => { $wire.clearSuccess(); }, 300);
                }, 5000);
            },
            dismiss() {
                clearTimeout(this.timer);
                this.$el.classList.add('dismissing');
                setTimeout(() => { $wire.clearSuccess(); }, 300);
            }
        }"
        class="success-toast relative mb-3 mx-3 sm:mx-0 shrink-0 inline-flex items-center gap-2.5 px-4 py-2.5 rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm self-start"
        style="min-width:0; max-width:560px;">
        <i class="fas fa-circle-check text-emerald-500 text-base shrink-0"></i>
        <p class="text-sm font-semibold text-emerald-800 leading-snug pr-1">{{ $successMsg }}</p>
        <button @click="dismiss()"
                class="ml-1 shrink-0 w-5 h-5 flex items-center justify-center rounded-md text-emerald-400 hover:text-emerald-700 hover:bg-emerald-100 transition">
            <i class="fas fa-xmark text-xs"></i>
        </button>
    </div>
    @endif

    {{-- ══ Scrollable body (fills remaining height, no page-level scroll/buffer) ══ --}}
    <div id="reg-scroll" class="flex-1 min-h-0 overflow-y-auto">
    <div class="flex items-start justify-center pt-1 pb-4 px-3 sm:px-0">
        <div class="w-full flex flex-col sm:flex-row gap-5 items-start justify-center
                    {{ count($formErrors) > 0 ? 'max-w-5xl' : 'max-w-2xl' }}
                    transition-all duration-300">

            {{-- ── Form Column ── --}}
            <div class="{{ count($formErrors) > 0 ? 'flex-1 min-w-0 w-full' : 'w-full' }}">
                <div class="reg-card reg-panel">
                    <form wire:submit="registerAlumni" class="p-5 sm:p-7 space-y-5 pb-7">

                        {{-- Full Name --}}
                        <div>
                            <p class="text-sm font-bold text-[#333333] uppercase tracking-wide mb-3">
                                Full Name <span class="text-red-500">*</span>
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="fl-group">
                                    <span class="fl-icon"><i class="fas fa-user"></i></span>
                                    <input wire:model.defer="regFirstName" type="text" placeholder=" "
                                           class="fl-input {{ in_array('firstName', $fieldErrors) ? 'field-error' : '' }}"
                                           maxlength="100" autocomplete="given-name">
                                    <label class="fl-label">First Name</label>
                                </div>
                                <div class="fl-group">
                                    <span class="fl-icon"><i class="fas fa-user"></i></span>
                                    <input wire:model.defer="regLastName" type="text" placeholder=" "
                                           class="fl-input {{ in_array('lastName', $fieldErrors) ? 'field-error' : '' }}"
                                           maxlength="100" autocomplete="family-name">
                                    <label class="fl-label">Last Name</label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3 mt-3">
                                <div class="fl-group">
                                    <span class="fl-icon"><i class="fas fa-user"></i></span>
                                    <input wire:model.defer="regMiddleInitial" type="text" placeholder=" "
                                           class="fl-input {{ in_array('middleName', $fieldErrors) ? 'field-error' : '' }}"
                                           maxlength="50">
                                    <label class="fl-label">Middle Name</label>
                                </div>

                                {{-- Suffix dropdown --}}
                                <div class="reg-dropdown"
                                     x-data="{
                                         open: false,
                                         suffixes: [
                                             { label: 'Jr.',   desc: 'Junior'        },
                                             { label: 'Sr.',   desc: 'Senior'        },
                                             { label: 'II',    desc: 'The Second'    },
                                             { label: 'III',   desc: 'The Third'     },
                                             { label: 'IV',    desc: 'The Fourth'    },
                                             { label: 'V',     desc: 'The Fifth'     },
                                             { label: 'VI',    desc: 'The Sixth'     },
                                             { label: 'VII',   desc: 'The Seventh'   },
                                             { label: 'VIII',  desc: 'The Eighth'    },
                                             { label: 'IX',    desc: 'The Ninth'     },
                                             { label: 'X',     desc: 'The Tenth'     },
                                             { label: 'XI',    desc: 'The Eleventh'  },
                                             { label: 'XII',   desc: 'The Twelfth'   },
                                             { label: 'XIII',  desc: 'The Thirteenth'},
                                             { label: 'XIV',   desc: 'The Fourteenth'},
                                             { label: 'XV',    desc: 'The Fifteenth' },
                                             { label: 'XVI',   desc: 'The Sixteenth' },
                                             { label: 'XVII',  desc: 'The Seventeenth'},
                                             { label: 'XVIII', desc: 'The Eighteenth'},
                                             { label: 'XIX',   desc: 'The Nineteenth'},
                                             { label: 'XX',    desc: 'The Twentieth' },
                                         ],
                                         toggle() { this.open = !this.open; },
                                         close()  { this.open = false; },
                                         select(val) { $wire.set('regSuffix', val); this.close(); },
                                         clear()  { $wire.set('regSuffix', ''); }
                                     }"
                                     @click.outside="close()">

                                    <button type="button"
                                            @click="toggle()"
                                            :class="{ 'has-value': $wire.regSuffix !== '', 'open': open }"
                                            class="reg-dropdown-trigger {{ in_array('suffix', $fieldErrors) ? 'field-error' : '' }}">
                                        <i class="fas fa-tag reg-trigger-icon"></i>
                                        <span class="reg-trigger-label">Suffix</span>
                                        <span class="reg-trigger-value" x-show="$wire.regSuffix !== ''" x-text="$wire.regSuffix" style="display:none;"></span>
                                        <i class="fas fa-chevron-down reg-trigger-chevron"></i>
                                    </button>

                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         class="reg-suffix-panel" style="display:none;">
                                        <div class="reg-suffix-header">
                                            <p class="reg-suffix-title">Select Suffix</p>
                                        </div>
                                        <div class="reg-suffix-list">
                                            <template x-for="s in suffixes" :key="s.label">
                                                <button type="button"
                                                        @click.stop="select(s.label)"
                                                        :class="{ 'reg-suffix-item--selected': $wire.regSuffix === s.label }"
                                                        class="reg-suffix-item">
                                                    <span class="font-mono font-bold w-12 shrink-0" x-text="s.label"></span>
                                                    <span class="text-xs font-medium" x-text="s.desc"
                                                          :class="$wire.regSuffix === s.label ? 'text-white/70' : 'text-[#888]'"></span>
                                                </button>
                                            </template>
                                        </div>
                                        <div class="reg-suffix-footer">
                                            <button type="button" @click.stop="clear()" class="reg-suffix-clear"
                                                    x-show="$wire.regSuffix !== ''" style="display:none;">
                                                <i class="fas fa-xmark mr-1" style="font-size:.65rem;"></i>Clear suffix
                                            </button>
                                            <p class="text-center text-[0.65rem] text-[#bbb] font-medium"
                                               x-show="$wire.regSuffix === ''" style="display:none;">
                                                Optional — leave blank if none
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Student ID --}}
                        <div>
                            <p class="text-sm font-bold text-[#333333] uppercase tracking-wide mb-3">
                                Student ID <span class="text-red-500">*</span>
                            </p>
                            <div class="fl-group">
                                <span class="fl-icon"><i class="fas fa-id-card"></i></span>
                                <input wire:model.defer="regStudentId" type="text" placeholder=" "
                                       class="fl-input font-mono {{ in_array('studentId', $fieldErrors) ? 'field-error' : '' }}"
                                       maxlength="8" inputmode="numeric" autocomplete="off">
                                <label class="fl-label">Student ID</label>
                            </div>
                        </div>

                        {{-- Course + Batch --}}
                        <div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <p class="text-sm font-bold text-[#333333] uppercase tracking-wide">
                                    Course <span class="text-red-500">*</span>
                                </p>
                                <p class="text-sm font-bold text-[#333333] uppercase tracking-wide">
                                    Batch <span class="text-red-500">*</span>
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="overflow:visible;position:relative;z-index:10;">

                                {{-- Course picker --}}
                                @php $courseCodesJson = $this->courses->pluck('code')->toJson(); @endphp
                                <div class="reg-dropdown"
                                     x-data="{
                                         open: false,
                                         allCodes: {{ $courseCodesJson }},
                                         pageSize: 12,
                                         pageIndex: 0,
                                         get totalPages() { return Math.max(1, Math.ceil(this.allCodes.length / this.pageSize)); },
                                         get pageCodes() { let s = this.pageIndex * this.pageSize; return this.allCodes.slice(s, s + this.pageSize); },
                                         get rangeLabel() {
                                             if (!this.allCodes.length) return 'No courses';
                                             let s = this.pageIndex * this.pageSize;
                                             let e = Math.min(s + this.pageSize - 1, this.allCodes.length - 1);
                                             return this.totalPages === 1 ? 'All Courses' : this.allCodes[s] + ' - ' + this.allCodes[e];
                                         },
                                         prevPage() { if (this.pageIndex > 0) this.pageIndex--; },
                                         nextPage() { if (this.pageIndex < this.totalPages - 1) this.pageIndex++; },
                                         canPrev() { return this.pageIndex > 0; },
                                         canNext() { return this.pageIndex < this.totalPages - 1; },
                                         toggle() { this.open = !this.open; },
                                         close()  { this.open = false; },
                                         select(code) { $wire.set('regCourseCode', code); this.close(); },
                                         jumpToSelected() { let i = this.allCodes.indexOf($wire.regCourseCode); if (i >= 0) this.pageIndex = Math.floor(i / this.pageSize); }
                                     }"
                                     @click.outside="close()">

                                    <button type="button"
                                            @click="toggle(); if(open) jumpToSelected()"
                                            :class="{ 'has-value': $wire.regCourseCode !== '', 'open': open }"
                                            class="reg-dropdown-trigger {{ in_array('course', $fieldErrors) ? 'field-error' : '' }}">
                                        <i class="fas fa-book-open reg-trigger-icon"></i>
                                        <span class="reg-trigger-label">Course</span>
                                        <span class="reg-trigger-value" x-show="$wire.regCourseCode !== ''" x-text="$wire.regCourseCode" style="display:none;"></span>
                                        <i class="fas fa-chevron-down reg-trigger-chevron"></i>
                                    </button>

                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100 scale-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         class="reg-picker-panel reg-course-picker" style="display:none;">
                                        <div class="reg-picker-header">
                                            <button type="button" @click.stop="prevPage()" :disabled="!canPrev()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-left" style="font-size:.58rem;"></i>
                                            </button>
                                            <span class="reg-picker-range-label" x-text="rangeLabel"></span>
                                            <button type="button" @click.stop="nextPage()" :disabled="!canNext()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-right" style="font-size:.58rem;"></i>
                                            </button>
                                        </div>
                                        <div class="reg-picker-grid" style="grid-template-columns: repeat(4, 1fr);">
                                            <template x-for="code in pageCodes" :key="code">
                                                <button type="button" @click.stop="select(code)"
                                                        :class="{ 'reg-picker-cell--selected': $wire.regCourseCode === code }"
                                                        class="reg-picker-cell" x-text="code"></button>
                                            </template>
                                            <template x-for="i in (pageSize - pageCodes.length)" :key="'e-' + i">
                                                <div></div>
                                            </template>
                                        </div>
                                        <div class="reg-picker-footer">
                                            <button type="button" @click.stop="select('')" class="reg-picker-clear-btn"
                                                    x-show="$wire.regCourseCode !== ''" style="display:none;">
                                                <i class="fas fa-xmark" style="font-size:.65rem;margin-right:3px;"></i>Clear
                                            </button>
                                            <span x-show="totalPages > 1"
                                                  style="cursor:default;font-size:.68rem;color:#aaa;margin-left:auto;">
                                                Page <span x-text="pageIndex + 1"></span> / <span x-text="totalPages"></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Batch Year picker --}}
                                <div class="reg-dropdown"
                                     x-data="{
                                         open: false,
                                         minYear: 1995, maxYear: 3030,
                                         today: {{ (int) date('Y') }},
                                         pageSize: 12,
                                         pageStart: Math.floor(({{ (int) date('Y') }} - 1995) / 12) * 12 + 1995,
                                         get pageYears() {
                                             let ys = [];
                                             for (let y = this.pageStart; y < this.pageStart + this.pageSize; y++)
                                                 if (y >= this.minYear && y <= this.maxYear) ys.push(y);
                                             return ys;
                                         },
                                         prevPage() { if (this.canPrev()) this.pageStart -= this.pageSize; },
                                         nextPage() { if (this.canNext()) this.pageStart += this.pageSize; },
                                         canPrev() { return this.pageStart > this.minYear; },
                                         canNext() { return this.pageStart + this.pageSize <= this.maxYear; },
                                         toggle() { this.open = !this.open; },
                                         close()  { this.open = false; },
                                         select(y) { $wire.set('regYear', y === '' ? '' : String(y)); this.close(); },
                                         jumpToToday() {
                                             this.pageStart = Math.floor((this.today - this.minYear) / this.pageSize) * this.pageSize + this.minYear;
                                             this.select(this.today);
                                         }
                                     }"
                                     @click.outside="close()">

                                    <button type="button"
                                            @click="toggle()"
                                            :class="{ 'has-value': $wire.regYear !== '', 'open': open }"
                                            class="reg-dropdown-trigger {{ in_array('year', $fieldErrors) ? 'field-error' : '' }}">
                                        <i class="fas fa-calendar-alt reg-trigger-icon"></i>
                                        <span class="reg-trigger-label">Batch Year</span>
                                        <span class="reg-trigger-value" x-show="$wire.regYear !== ''" x-text="$wire.regYear" style="display:none;"></span>
                                        <i class="fas fa-chevron-down reg-trigger-chevron"></i>
                                    </button>

                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100 scale-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         class="reg-picker-panel reg-year-picker" style="display:none;">
                                        <div class="reg-picker-header">
                                            <button type="button" @click.stop="prevPage()" :disabled="!canPrev()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-left" style="font-size:.58rem;"></i>
                                            </button>
                                            <span class="reg-picker-range-label"
                                                  x-text="pageStart + ' - ' + Math.min(pageStart + pageSize - 1, maxYear)"></span>
                                            <button type="button" @click.stop="nextPage()" :disabled="!canNext()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-right" style="font-size:.58rem;"></i>
                                            </button>
                                        </div>
                                        <div class="reg-picker-grid" style="grid-template-columns: repeat(4, 1fr);">
                                            <template x-for="y in pageYears" :key="y">
                                                <button type="button" @click.stop="select(y)"
                                                        :class="{
                                                            'reg-picker-cell--selected': $wire.regYear === String(y),
                                                            'reg-picker-cell--today':    y === today && $wire.regYear !== String(y)
                                                        }"
                                                        class="reg-picker-cell" x-text="y"></button>
                                            </template>
                                        </div>
                                        <div class="reg-picker-footer">
                                            <button type="button" @click.stop="select('')" class="reg-picker-clear-btn"
                                                    x-show="$wire.regYear !== ''" style="display:none;">
                                                <i class="fas fa-xmark" style="font-size:.65rem;margin-right:3px;"></i>Clear
                                            </button>
                                            <button type="button" @click.stop="jumpToToday()" class="reg-picker-action-btn">
                                                <i class="fas fa-circle-dot" style="font-size:.65rem;margin-right:3px;"></i>This Year
                                            </button>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        {{-- Email --}}
                        <div>
                            <p class="text-sm font-bold text-[#333333] uppercase tracking-wide mb-3">
                                Email <span class="text-red-500">*</span>
                            </p>
                            <div class="fl-group">
                                <span class="fl-icon"><i class="fas fa-envelope"></i></span>
                                <input wire:model.defer="regEmail" type="email" placeholder=" "
                                       class="fl-input {{ in_array('email', $fieldErrors) ? 'field-error' : '' }}"
                                       maxlength="255" autocomplete="email">
                                <label class="fl-label">Email Address</label>
                            </div>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex gap-3 pt-1">
                            <button type="button" wire:click="resetForm"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-3 rounded-xl text-base font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                                <i class="fa-solid fa-arrow-rotate-left mr-1.5"></i>Reset
                            </button>
                            <button type="submit"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-3 rounded-xl text-base font-semibold text-white transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                    style="background:#7A3F91;">
                                <span wire:loading wire:target="registerAlumni">
                                    <i class="fas fa-spinner animate-spin"></i> Registering...
                                </span>
                                <span wire:loading.remove wire:target="registerAlumni">
                                    <i class="fas fa-user-check"></i> Register Alumni
                                </span>
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            {{-- ── Side Error Panel ── --}}
            @if(count($formErrors) > 0)
            <div class="w-full sm:w-80 shrink-0">
                <div class="bg-red-50 border border-red-200 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-4 py-3 flex items-center justify-between" style="background:#DC2626;">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-circle-xmark text-white text-base"></i>
                            <span class="text-base font-semibold text-white">Validation Errors</span>
                        </div>
                        <div class="relative group">
                            <button wire:click="resetForm" type="button"
                                    class="text-white/70 hover:text-white transition w-7 h-7 flex items-center justify-center rounded-lg hover:bg-white/15">
                                <i class="fas fa-xmark text-sm"></i>
                            </button>
                            <span class="pointer-events-none absolute top-[calc(100%+6px)] right-0 bg-[#1a1a1a] text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 shadow-lg z-50">
                                Clear errors
                                <span class="absolute bottom-full right-2.5 border-4 border-transparent border-b-[#1a1a1a]"></span>
                            </span>
                        </div>
                    </div>
                    <div class="p-4">
                        <p class="text-sm font-semibold text-red-800 mb-3">Please fix the following errors:</p>
                        <ul class="space-y-2">
                            @foreach($formErrors as $msgs)
                                @foreach($msgs as $msg)
                                    <li class="flex items-start gap-2">
                                        <span class="shrink-0 mt-0.5 text-red-500 font-bold">•</span>
                                        <span class="text-sm text-red-700 leading-relaxed">{{ $msg }}</span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            @endif

        </div>
    </div>
    </div>{{-- end reg-scroll --}}
</div>

{{-- ══ IMPORT MODAL ══ --}}
@if($showImportModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-0 sm:px-4"
     style="background:rgba(27,6,46,0.60);backdrop-filter:blur(4px);"
     @keydown.escape.window="@if($importStep !== 'processing') $wire.closeImportModal() @endif">

    {{-- Fixed-size modal box on desktop, full screen on mobile --}}
    <div class="import-modal-box">

        {{-- Modal Header — solid purple --}}
        <div class="flex items-center justify-between px-5 py-4 shrink-0" style="background:#7A3F91;">
            <h2 class="text-white font-bold text-xl flex items-center gap-2.5">
                <i class="fas fa-file-import"></i> Import Alumni Records
            </h2>
            <div class="relative group">
                <button wire:click="closeImportModal"
                        @if($importStep === 'processing') disabled @endif
                        class="w-8 h-8 rounded-xl flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition disabled:opacity-30 disabled:cursor-not-allowed">
                    <i class="fas fa-xmark text-base"></i>
                </button>
                @if($importStep !== 'processing')
                <span class="pointer-events-none absolute top-[calc(100%+6px)] right-0 bg-[#1a1a1a] text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 shadow-lg z-50">
                    Close
                    <span class="absolute bottom-full right-2.5 border-4 border-transparent border-b-[#1a1a1a]"></span>
                </span>
                @endif
            </div>
        </div>

        {{-- Scrollable body --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">

            {{-- Step Indicator --}}
            @php
                $stepMap     = ['upload' => 0, 'preview' => 1, 'processing' => 2, 'blocked' => 2, 'done' => 3];
                $currentStep = $stepMap[$importStep] ?? 0;
                $stepDefs    = [[0,'1','Upload'],[1,'2','Preview'],[2,'3','Importing'],[3,'4','Done']];
            @endphp
            <div class="flex items-center gap-2">
                @foreach($stepDefs as [$idx, $num, $lbl])
                    @php $isActive = $currentStep === $idx; $isDone = $currentStep > $idx; @endphp
                    <div class="flex items-center gap-1.5 {{ $isActive ? 'opacity-100' : ($isDone ? 'opacity-80' : 'opacity-30') }}">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold text-white shrink-0"
                             style="background:{{ $isActive || $isDone ? '#7A3F91' : '#ccc' }}">
                            @if($isDone) <i class="fas fa-check" style="font-size:10px;"></i>
                            @else {{ $num }}
                            @endif
                        </div>
                        <span class="text-sm font-semibold text-[#333333]">{{ $lbl }}</span>
                    </div>
                    @if($idx < 3)<div class="flex-1 h-px bg-[#E8E0F0]"></div>@endif
                @endforeach
            </div>

            {{-- STEP 1: UPLOAD --}}
            @if($importStep === 'upload')

            {{-- ══ Required Columns — horizontal chips, no icons ══ --}}
            <div class="rounded-xl border border-blue-200 overflow-hidden" style="background:#F8FBFF;">
                {{-- header --}}
                <div class="flex items-center gap-2 px-4 py-2.5 border-b border-blue-100" style="background:#EEF4FF;">
                    <div class="w-6 h-6 rounded-md flex items-center justify-center shrink-0" style="background:#1D4ED8;">
                        <i class="fas fa-table-columns text-white" style="font-size:.6rem;"></i>
                    </div>
                    <p class="font-bold text-blue-900 text-sm">Required Excel Columns</p>

                </div>

                {{-- Horizontal chips — text only, no icons ── --}}
                @php
                $reqCols = [
                    'first_name', 'last_name', 'middle_name', 'suffix',
                    'student_id', 'course',    'batch',       'email',
                ];
                @endphp
                <div class="req-col-chips">
                    @foreach($reqCols as $col)
                        <span class="req-col-chip">{{ $col }}</span>
                    @endforeach
                </div>
            </div>

            @if($importStatus === 'invalid_type')
            <div class="flex items-start gap-3 p-3.5 rounded-xl bg-red-50 border border-red-200">
                <i class="fas fa-circle-xmark text-red-500 mt-0.5 shrink-0"></i>
                <div>
                    <p class="font-bold text-red-800 text-sm">Invalid file type</p>
                    <p class="text-sm text-red-700 mt-0.5">Only <strong>.xlsx</strong> and <strong>.xls</strong> Excel files are accepted.</p>
                </div>
            </div>
            @endif

            {{-- Dropzone normal --}}
            <div wire:loading.remove wire:target="importFile">
                <p class="text-sm font-bold text-[#555555] uppercase tracking-wide mb-2">Choose File</p>
                <div class="border-2 border-dashed border-[#E8E0F0] rounded-xl p-8 text-center cursor-pointer hover:border-[#2563EB] hover:bg-[#eff6ff] hover:shadow-sm transition-all duration-200"
                     @click="document.getElementById('importFileInput').click()">
                    <div class="w-14 h-14 rounded-2xl mx-auto mb-3 flex items-center justify-center shadow-sm" style="background:rgba(37,99,235,.12);">
                        <i class="fas fa-file-excel text-3xl" style="color:#2563EB;"></i>
                    </div>
                    <p class="text-[#333333] font-semibold text-base">Click to choose file</p>
                    <p class="text-[#888888] text-sm mt-1">Excel files only (.xlsx / .xls)</p>
                    <input type="file" id="importFileInput" wire:model="importFile" accept=".xlsx,.xls" class="hidden">
                </div>
            </div>

            {{-- Shimmer loading state --}}
            <div wire:loading wire:target="importFile" class="w-full">
                <p class="text-sm font-bold text-[#555555] uppercase tracking-wide mb-2 text-center">Choose File</p>
                <div class="w-full border-2 border-dashed border-[#2563EB] rounded-xl p-8 flex flex-col items-center justify-center text-center"
                     style="background:linear-gradient(90deg,#eff6ff 25%,#dbeafe 50%,#eff6ff 75%);background-size:200% 100%;animation:regShimmer 1.2s infinite linear;">
                    <div class="w-14 h-14 rounded-2xl mb-3 flex items-center justify-center" style="background:rgba(37,99,235,.15);">
                        <i class="fas fa-spinner animate-spin text-2xl" style="color:#1D4ED8;"></i>
                    </div>
                    <p class="font-semibold text-base" style="color:#1D4ED8;">Reading file...</p>
                    <p class="text-sm mt-1" style="color:#2563EB;">Please wait while we process your file</p>
                </div>
            </div>

            <button wire:click="closeImportModal"
                    class="w-full px-4 py-3 rounded-xl text-base font-bold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                Cancel
            </button>

            {{-- STEP 2: PREVIEW --}}
            @elseif($importStep === 'preview')

                @if($headerChecking)
                    <div wire:init="checkFileHeaders" class="w-full py-12 flex flex-col items-center justify-center text-center">
                        <div class="relative w-20 h-20 mb-5 mx-auto">
                            <span class="reg-scan-ring"></span>
                            <span class="reg-scan-ring reg-scan-ring--delay"></span>
                            <div class="absolute inset-0 rounded-2xl flex items-center justify-center shadow-lg" style="background:#2563EB;">
                                <i class="fas fa-file-excel text-white text-3xl"></i>
                            </div>
                        </div>
                        <p class="font-bold text-[#333333] text-lg">Scanning your file...</p>
                        <p class="text-sm text-[#888888] mt-1">Checking the columns in "{{ $importFileName }}"</p>
                    </div>

                @elseif(!$headerValid)
                    <div class="flex flex-col items-center text-center gap-3 py-8 px-2">
                        <div class="w-14 h-14 rounded-2xl bg-red-100 flex items-center justify-center">
                            <i class="fas fa-triangle-exclamation text-red-500 text-2xl"></i>
                        </div>
                        <div>
                            <p class="font-bold text-red-800 text-base">This file can't be imported</p>
                            <p class="text-sm text-red-700 mt-1.5 max-w-sm mx-auto leading-relaxed">{{ $headerCheckMsg }}</p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button wire:click="backToUpload"
                                class="flex-1 px-4 py-3 rounded-xl text-base font-bold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-[.99] flex items-center justify-center gap-2">
                            <i class="fas fa-arrow-left text-sm"></i> Choose Another File
                        </button>
                        <button wire:click="closeImportModal"
                                class="flex-1 px-4 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 active:scale-[.99]"
                                style="background:#7A3F91;">
                            Cancel
                        </button>
                    </div>

                @else
                    <div class="flex flex-col items-center text-center gap-3 p-5 rounded-xl bg-[#eff6ff] border border-[#bfdbfe]">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-sm" style="background:#2563EB;">
                            <i class="fas fa-file-excel text-white text-2xl"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="font-bold text-[#333333] text-base break-all">{{ $importFileName }}</p>
                            <p class="text-sm text-[#1D4ED8] mt-0.5 font-medium">
                                <i class="fas fa-circle-check mr-1"></i>Columns look good — confirm below to start importing.
                            </p>
                        </div>
                        <button type="button"
                                onclick="document.getElementById('importFileInputPreview').click()"
                                class="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold border border-[#E8E0F0] text-[#555555] hover:bg-[#F5F5F5] transition">
                            <i class="fas fa-arrows-rotate text-xs"></i> Replace
                        </button>
                        <input type="file" id="importFileInputPreview" wire:model="importFile" accept=".xlsx,.xls" class="hidden">
                    </div>

                    <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-3">
                        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                        <p class="text-sm text-amber-800 leading-relaxed">
                            Clicking <strong>Confirm Import</strong> will begin importing immediately.
                            Duplicates are skipped automatically; invalid rows are reported after completion.
                        </p>
                    </div>

                    <div class="flex gap-3">
                        <button wire:click="backToUpload"
                                class="flex-1 px-4 py-3 rounded-xl text-base font-bold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-[.99] flex items-center justify-center gap-2">
                            <i class="fas fa-arrow-left text-sm"></i> Change File
                        </button>
                        <button wire:click="confirmImport"
                                wire:loading.attr="disabled" wire:target="confirmImport"
                                class="flex-1 px-4 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 active:scale-[.99] disabled:opacity-60 flex items-center justify-center gap-2"
                                style="background:#7A3F91;">
                            <span wire:loading wire:target="confirmImport">
                                <i class="fas fa-spinner animate-spin"></i> Starting...
                            </span>
                            <span wire:loading.remove wire:target="confirmImport">
                                <i class="fas fa-play"></i> Confirm Import
                            </span>
                        </button>
                    </div>
                @endif

            {{-- STEP 3: IMPORTING --}}
            @elseif($importStep === 'processing')

            <div wire:init="processImport" class="py-8 text-center">
                <div class="relative w-24 h-24 mx-auto mb-6 flex items-center justify-center">
                    <span class="import-scan-ring"></span>
                    <span class="import-scan-ring import-scan-ring--delay1"></span>
                    <span class="import-scan-ring import-scan-ring--delay2"></span>
                    <div class="import-orbit-dot"></div>
                    <div class="import-orbit-dot import-orbit-dot--2"></div>
                    <div class="import-orbit-dot import-orbit-dot--3"></div>
                    <div class="relative z-10 w-16 h-16 rounded-2xl flex items-center justify-center shadow-xl"
                         style="background:linear-gradient(135deg,#22c55e,#16a34a);">
                        <i class="fas fa-file-excel text-white text-2xl"></i>
                    </div>
                </div>

                <p class="font-extrabold text-[#1a2e1a] text-2xl mb-1 tracking-tight">
                    @if($importTotal > 0)
                        Importing {{ number_format($importProgress) }} / {{ number_format($importTotal) }}
                    @else
                        Preparing...
                    @endif
                </p>
                <p class="text-base font-medium mb-6" style="color:#16a34a;">{{ $importStatus ?: 'Please wait...' }}</p>

                @if($importTotal > 0)
                @php $pct = $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0; @endphp
                <div class="px-2">
                    <div class="reg-progress-track">
                        <div class="reg-progress-fill" style="width:{{ $pct }}%;"></div>
                    </div>
                    <div class="flex items-center justify-between mt-2 px-1">
                        <span class="text-xs font-bold" style="color:#15803d;">{{ $pct }}% complete</span>
                        <span class="text-xs font-medium text-[#aaa]">{{ number_format($importSuccessCount) }} imported</span>
                    </div>
                </div>
                @else
                <div class="px-2">
                    <div class="reg-progress-track">
                        <div class="reg-progress-fill" style="width:100%;"></div>
                    </div>
                    <p class="text-xs font-bold mt-2" style="color:#15803d;">Reading records...</p>
                </div>
                @endif

                <p class="text-xs text-[#aaa] mt-5 font-medium">
                    <i class="fas fa-lock text-[0.6rem] mr-1 opacity-60"></i>
                    Do not close this window while importing
                </p>
            </div>

            {{-- BLOCKED --}}
            @elseif($importStep === 'blocked')

            <div class="flex items-start gap-4 p-4 rounded-xl bg-red-50 border border-red-200">
                <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                    <i class="fas fa-xmark text-red-600 text-lg"></i>
                </div>
                <div>
                    <p class="font-bold text-red-900 text-base">Import Failed</p>
                    <p class="text-base text-red-700 mt-1">{{ $importStatus }}</p>
                </div>
            </div>
            <button wire:click="resetImport"
                    class="w-full bg-white border border-[#E8E0F0] text-[#333333] px-5 py-3 rounded-xl text-base font-bold hover:bg-[#F5F5F5] transition active:scale-[.99]">
                <i class="fas fa-rotate-left mr-2"></i>Try Again
            </button>

            {{-- STEP 4: DONE --}}
            @elseif($importStep === 'done')

            @php
                $hasNew    = $importSuccessCount   > 0;
                $hasErrors = $importFailCount       > 0;
                $hasDups   = $importDuplicateCount  > 0;
            @endphp

            <div class="flex items-start gap-3 p-4 rounded-xl border
                        {{ $hasNew ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }}">
                <div class="reg-pop w-10 h-10 rounded-xl flex items-center justify-center shrink-0
                            {{ $hasNew ? 'bg-emerald-100' : 'bg-amber-100' }}">
                    <i class="{{ $hasNew ? 'fas fa-circle-check text-emerald-600 text-lg' : 'fas fa-triangle-exclamation text-amber-600 text-lg' }}"></i>
                </div>
                <div>
                    @if($hasNew)
                        <p class="font-bold text-emerald-900 text-base">Import Complete</p>
                        <p class="text-base text-emerald-700 mt-0.5">
                            <strong>{{ $importSuccessCount }}</strong> record(s) imported
                            @if($hasDups) &middot; {{ $importDuplicateCount }} duplicate(s) skipped @endif
                            @if($hasErrors) &middot; {{ $importFailCount }} error(s) @endif
                        </p>
                    @else
                        <p class="font-bold text-amber-900 text-base">Nothing Imported</p>
                        <p class="text-base text-amber-700 mt-0.5">All records were duplicates or had errors.</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-4 gap-2">
                @foreach([
                    ['#f9fafb','#e5e7eb','#4b5563', $importTotal,         'Total'],
                    ['#eff6ff','#bfdbfe','#1D4ED8', $importSuccessCount,   'Imported'],
                    ['#fffbeb','#fde68a','#92400e', $importDuplicateCount, 'Duplicate'],
                    ['#fef2f2','#fecaca','#991b1b', $importFailCount,      'Errors'],
                ] as [$bg, $border, $clr, $cnt, $lbl])
                <div class="reg-stat-tile" style="background:{{ $bg }};border:1px solid {{ $border }};">
                    <p class="text-2xl font-extrabold" style="color:{{ $clr }};">{{ $cnt }}</p>
                    <p class="text-xs font-bold text-[#888888] uppercase tracking-wide mt-0.5">{{ $lbl }}</p>
                </div>
                @endforeach
            </div>

            @if($hasErrors)
            <div class="border border-red-200 rounded-xl overflow-hidden">
                <div class="px-4 py-3 bg-red-50 border-b border-red-100 flex items-center gap-2">
                    <i class="fas fa-circle-xmark text-red-400"></i>
                    <p class="font-bold text-red-900 text-base">Validation Errors</p>
                    <span class="ml-auto text-sm bg-red-100 text-red-700 px-2.5 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                </div>
                <ul class="import-result-list divide-y divide-red-50" style="max-height:140px;">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-2.5 text-sm text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-300 mt-0.5 shrink-0 text-xs"></i>
                        <span>{{ $err }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if($hasDups && count($importDuplicates) > 0)
            <div class="border border-amber-200 rounded-xl overflow-hidden">
                <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
                    <i class="fas fa-copy text-amber-400"></i>
                    <p class="font-bold text-amber-900 text-base">Duplicates Skipped</p>
                    <span class="ml-auto text-sm bg-amber-100 text-amber-700 px-2.5 py-0.5 rounded-full font-bold">{{ $importDuplicateCount }}</span>
                </div>
                <ul class="import-result-list divide-y divide-amber-50" style="max-height:140px;">
                    @foreach($importDuplicates as $dup)
                    <li class="px-4 py-2.5 text-sm text-amber-700 flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation text-amber-300 mt-0.5 shrink-0 text-xs"></i>
                        <span>{{ $dup }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="flex gap-3">
                <button wire:click="resetImport"
                        class="flex-1 bg-white border border-[#E8E0F0] text-[#333333] px-4 py-3 rounded-xl text-base font-bold hover:bg-[#F5F5F5] transition active:scale-[.99]">
                    <i class="fas fa-rotate-left mr-2"></i>Import Another
                </button>
                <button wire:click="closeImportModal"
                        class="flex-1 text-white px-4 py-3 rounded-xl text-base font-bold transition hover:opacity-90 active:scale-[.99]"
                        style="background:#7A3F91;">
                    <i class="fas fa-check mr-2"></i>Done
                </button>
            </div>

            @endif
        </div>

        {{-- Modal Footer --}}
        <div class="shrink-0 px-5 py-3 border-t border-[#E8E0F0] bg-[#FAFAFA]">
            <p class="text-sm text-[#888888]">
                @if($importStep === 'upload')    Choose an Excel file (.xlsx / .xls) to get started.
                @elseif($importStep === 'preview')
                    @if($headerChecking) Checking the file's columns...
                    @elseif(!$headerValid) Fix the file and try again, or choose a different one.
                    @else Review the file, then click Confirm Import.
                    @endif
                @elseif($importStep === 'processing') Do not close this window while importing.
                @elseif($importStep === 'done')       Import complete — you may close this window.
                @elseif($importStep === 'blocked')    Fix the error above and try again.
                @endif
            </p>
        </div>
    </div>
</div>
@endif

</div>