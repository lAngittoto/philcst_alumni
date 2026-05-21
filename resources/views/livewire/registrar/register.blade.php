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

    // ══ REGISTER FIELDS ══════════════════════════════════════════
    public string $regFirstName     = '';
    public string $regMiddleInitial = '';
    public string $regLastName      = '';
    public string $regSuffix        = '';
    public string $regStudentId     = '';
    public string $regCourseCode    = '';
    public string $regYear          = '';

    // ══ REGISTER STATE ═══════════════════════════════════════════
    public bool   $submitting = false;
    public array  $formErrors = [];
    public string $successMsg = '';

    // ══ IMPORT STATE ══════════════════════════════════════════════
    // Steps: 'upload' → 'preview' → 'processing' → 'done' | 'blocked'
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

    // ─────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $this->regYear = (string) date('Y');
    }

    #[\Livewire\Attributes\Computed]
    public function courses() { return Course::orderBy('code')->get(); }

    // ══ REGISTER HELPERS ═════════════════════════════════════════
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

    private function fullNameExists(string $f, string $m, string $l, string $s): bool
    {
        return Alumni::whereRaw('LOWER(TRIM(first_name))=?',                  [strtolower(trim($f))])
                     ->whereRaw('LOWER(TRIM(last_name))=?',                   [strtolower(trim($l))])
                     ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,"")))=?', [strtolower(trim($m))])
                     ->whereRaw('LOWER(TRIM(COALESCE(suffix,"")))=?',         [strtolower(trim($s))])
                     ->exists();
    }

    private function collectErrors(): array
    {
        $errors = [];

        $firstName = trim($this->regFirstName);
        if (!$firstName)
            $errors[] = 'First name is required.';
        elseif (!$this->validateName($firstName))
            $errors[] = 'First name may only contain letters, spaces, hyphens, or apostrophes.';

        $lastName = trim($this->regLastName);
        if (!$lastName)
            $errors[] = 'Last name is required.';
        elseif (!$this->validateName($lastName))
            $errors[] = 'Last name may only contain letters, spaces, hyphens, or apostrophes.';

        $mid = trim($this->regMiddleInitial);
        if ($mid === '') {
            $errors[] = 'Middle name is required.';
        } else {
            if (!preg_match('/^[a-zA-Z]+$/', $mid))
                $errors[] = 'Middle name must contain letters only.';
            elseif (strlen($mid) < 2)
                $errors[] = 'Middle name must be a full word (e.g. Santos, not S).';
        }

        $suffix = trim($this->regSuffix);
        if ($suffix !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', $suffix))
            $errors[] = 'Suffix may only contain letters and periods (e.g. Jr. Sr. III).';

        if (!$errors && $firstName && $lastName) {
            if ($this->fullNameExists($firstName, $mid, $lastName, $suffix))
                $errors[] = 'An alumni with that full name already exists.';
        }

        $studentId = trim($this->regStudentId);
        if (!$studentId)
            $errors[] = 'Student ID is required.';
        elseif (!preg_match('/^\d{1,8}$/', $studentId))
            $errors[] = 'Student ID must be 1–8 digits (numbers only).';
        else {
            $paddedId = str_pad($studentId, 8, '0', STR_PAD_LEFT);
            if (Alumni::where('student_id', $paddedId)->exists())
                $errors[] = 'This Student ID is already registered.';
        }

        if (!trim($this->regCourseCode))
            $errors[] = 'Please select a course.';
        elseif (!Course::where('code', $this->regCourseCode)->exists())
            $errors[] = 'The selected course does not exist.';

        $year = trim($this->regYear);
        if (!$year)
            $errors[] = 'Batch year is required.';
        elseif (!preg_match('/^\d{4}$/', $year))
            $errors[] = 'Batch year must be exactly 4 digits.';

        $email = trim($this->regEmail);
        if (!$email)
            $errors[] = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'Please enter a valid email address.';
        elseif (Alumni::whereNotNull('email')->whereRaw('LOWER(TRIM(email))=?', [strtolower($email)])->exists())
            $errors[] = 'This email address is already registered.';

        return $errors;
    }

    public function registerAlumni(): void
    {
        $this->formErrors = [];
        $this->successMsg = '';
        $this->submitting = true;

        try {
            $errors = $this->collectErrors();

            if (!empty($errors)) {
                $this->formErrors = ['general' => $errors];
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
    }

    public function clearSuccess(): void
    {
        $this->successMsg = '';
    }

    // ══ IMPORT ═══════════════════════════════════════════════════
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
    }

    public function backToUpload(): void
    {
        $this->importFile     = null;
        $this->importFileName = '';
        $this->importStatus   = '';
        $this->importStep     = 'upload';
    }

    public function confirmImport(): void
    {
        $this->importStep    = 'processing';
        $this->importStatus  = 'Starting…';
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

    private function appendImportError(array &$errors, int $max, string $msg): void
    {
        $this->importFailCount++;
        if (count($errors) < $max)       $errors[] = $msg;
        elseif (count($errors) === $max) $errors[] = '… (additional errors truncated)';
    }

    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function processImport(): void
    {
        if (function_exists('set_time_limit')) @set_time_limit(300);
        @ini_set('memory_limit', '512M');

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

            if (!in_array($ext, ['xlsx', 'xls'], true))
                throw new \Exception('File must be .xlsx or .xls. CSV is not accepted.');

            $rows = $this->parseExcel($this->importFile->getRealPath());

            if (count($rows) < 2) throw new \Exception('File is empty or has no data rows.');

            $header = array_map('trim', array_map('strtolower', $rows[0]));

            $hasCourse     = in_array('course', $header, true);
            $hasCourseCode = in_array('course_code', $header, true);
            if (!$hasCourse && !$hasCourseCode)
                throw new \Exception('Missing required column: "course" (or "course_code").');
            if (!$hasCourse && $hasCourseCode)
                $header[array_search('course_code', $header)] = 'course';

            $required = ['first_name', 'last_name', 'middle_name', 'student_id', 'course', 'batch', 'email'];
            foreach ($required as $col)
                if (!in_array($col, $header, true))
                    throw new \Exception("Missing required column: \"{$col}\".");

            $this->importTotal = count($rows) - 1;

            $allCourses   = Course::all();
            $courseByCode = $allCourses->keyBy(fn($c) => strtoupper(trim($c->code)));
            $courseByName = $allCourses->keyBy(fn($c) => strtolower(trim($c->name)));

            $existingIds = DB::table('alumni')->pluck('student_id')->flip()->toArray();

            $existingEmails = DB::table('users')
                                ->whereNotNull('email')->where('email', '!=', '')
                                ->pluck('email')->map(fn($e) => strtolower($e))->flip()->toArray();

            $existingNames = DB::table('alumni')
                               ->selectRaw("LOWER(TRIM(first_name)) as fn,
                                            LOWER(TRIM(COALESCE(middle_initial,''))) as mi,
                                            LOWER(TRIM(last_name)) as ln,
                                            LOWER(TRIM(COALESCE(suffix,''))) as sf")
                               ->get()
                               ->map(fn($a) => $a->fn.'|'.$a->mi.'|'.$a->ln.'|'.$a->sf)
                               ->flip()->toArray();

            $jobs = $seenIds = $seenEmails = $seenNames = [];
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
                if (isset($existingEmails[$email]) || isset($seenEmails[$email])) { $duplicates[] = "{$label}: Email \"{$email}\" already registered."; $this->importDuplicateCount++; continue; }

                if (!$firstName) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name has invalid characters."); continue; }
                if (!$lastName)  { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name is empty."); continue; }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name has invalid characters."); continue; }
                if ($mid === '') { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name is required."); continue; }

                if (!preg_match('/^[a-zA-Z][a-zA-Z ]*[a-zA-Z]$|^[a-zA-Z]$/', $mid)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name must contain letters only (spaces ok for e.g. Dela Cruz)."); continue;
                }
                $midError = false;
                foreach (explode(' ', $mid) as $part) {
                    if (strlen($part) < 2) {
                        $this->appendImportError($validationErrors, $maxErrors, "{$label}: Each word in middle name must be ≥2 chars."); $midError = true; break;
                    }
                }
                if ($midError) continue;

                $nameKey = strtolower($firstName).'|'.strtolower($mid).'|'.strtolower($lastName).'|'.strtolower($suffix);
                if (isset($existingNames[$nameKey]) || isset($seenNames[$nameKey])) { $duplicates[] = "{$label}: Name \"{$fullName}\" already registered."; $this->importDuplicateCount++; continue; }

                $rawId      = preg_replace('/\..*$/', '', rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.'));
                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int)$rawIdClean === 0) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Student ID \"{$rawId}\" is invalid."); continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);
                if (isset($existingIds[$sid]) || isset($seenIds[$sid])) { $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists."; $this->importDuplicateCount++; continue; }

                $courseInput = trim($row['course'] ?? '');
                $courseMatch = $courseByCode[strtoupper($courseInput)] ?? $courseByName[strtolower($courseInput)] ?? null;
                if (!$courseMatch) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Course \"{$courseInput}\" not found."); continue; }

                $batchYear = (int)($row['batch'] ?? 0);
                if ($batchYear < 1000 || $batchYear > 9999) { $this->appendImportError($validationErrors, $maxErrors, "{$label}: Batch \"{$batchYear}\" must be 4-digit year."); continue; }

                $jobs[] = compact('fullName','firstName','mid','lastName','suffix','email','sid','batchYear') + ['code' => $courseMatch->code, 'courseName' => $courseMatch->name];
                $seenIds[$sid] = $seenEmails[$email] = $seenNames[$nameKey] = true;
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

            $this->importStatus = 'Importing…';
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
    /* ── Shared dropdown trigger ────────────────────────────────── */
    .reg-dropdown { position: relative; }

    .reg-dropdown-trigger {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        width: 100%;
        padding: 10px 12px;
        border: 1.5px solid #E8E0F0;
        border-radius: 8px;
        font-size: .95rem;
        font-weight: 500;
        background: #fff;
        color: #333;
        cursor: pointer;
        transition: border-color .15s, background .15s, color .15s;
        white-space: nowrap;
        user-select: none;
    }
    .reg-dropdown-trigger:hover { border-color: #c49ed8; }
    .reg-dropdown-trigger.has-value { border-color: #7A3F91; background: #FDFAFF; color: #333; }
    .reg-dropdown-trigger .reg-chevron { transition: transform .18s; font-size: .62rem; opacity: .5; margin-left: auto; }
    .reg-dropdown-trigger.open { border-color: #7A3F91; box-shadow: 0 0 0 3px rgba(122,63,145,.08); }
    .reg-dropdown-trigger.open .reg-chevron { transform: rotate(180deg); }
    .reg-placeholder { color: #AAAAAA; }

    /* ── Shared picker panel (used by BOTH course & year pickers) ── */
    .reg-picker-panel {
        position: absolute;
        bottom: calc(100% + 8px); /* opens UPWARD */
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
        width: 28px;
        height: 28px;
        border-radius: 8px;
        border: 1.5px solid #E8E0F0;
        background: #fff;
        color: #7A3F91;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .13s, border-color .13s;
        flex-shrink: 0;
    }
    .reg-picker-nav-btn:hover:not(:disabled) { background: #F3E8FF; border-color: #c49ed8; }
    .reg-picker-nav-btn:disabled { opacity: .3; cursor: not-allowed; }

    .reg-picker-grid {
        display: grid;
        gap: 4px;
        padding: 10px;
    }

    .reg-picker-cell {
        padding: 7px 4px;
        border-radius: 8px;
        border: 1.5px solid transparent;
        font-size: .78rem;
        font-weight: 600;
        color: #444;
        background: transparent;
        cursor: pointer;
        text-align: center;
        transition: background .12s, color .12s, border-color .12s;
        line-height: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .reg-picker-cell:hover { background: #F3E8FF; color: #7A3F91; }

    .reg-picker-cell--selected {
        background: #7A3F91 !important;
        color: #fff !important;
        border-color: #7A3F91 !important;
    }

    .reg-picker-cell--today {
        border-color: #c49ed8;
        color: #7A3F91;
        background: #FDFAFF;
    }

    .reg-picker-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 10px 10px;
        border-top: 1px solid #F0E6F8;
        background: #FDFAFF;
        gap: 6px;
    }

    .reg-picker-clear-btn {
        font-size: .72rem;
        font-weight: 700;
        color: #999;
        background: none;
        border: none;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: color .12s, background .12s;
    }
    .reg-picker-clear-btn:hover { color: #dc2626; background: #fef2f2; }

    .reg-picker-action-btn {
        font-size: .72rem;
        font-weight: 700;
        color: #7A3F91;
        background: #F0E6F8;
        border: none;
        cursor: pointer;
        padding: 4px 10px;
        border-radius: 6px;
        transition: background .12s;
        margin-left: auto;
    }
    .reg-picker-action-btn:hover { background: #E4D0F5; }

    /* ── Course picker specific sizing ─────────────────────────── */
    .reg-course-picker {
        width: 320px;
    }

    /* ── Year picker specific sizing ───────────────────────────── */
    .reg-year-picker {
        width: 268px;
    }
</style>

{{-- ══ PAGE ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-4 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:#7A3F91;">
                <i class="fas fa-user-plus text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-[#333333] leading-tight">Register Alumni</h1>
                <p class="text-[#666666] text-sm font-normal mt-0.5">Add new alumni to the system with their details and credentials</p>
            </div>
        </div>
        <button wire:click="openImportModal"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white shadow-sm transition hover:opacity-90 active:scale-95 shrink-0"
                style="background:#7A3F91;">
            <i class="fas fa-file-import text-sm"></i>
            <span>Import</span>
        </button>
    </div>

    {{-- Compact success banner --}}
    @if($successMsg)
    <div class="mb-4 flex items-center justify-between gap-3 px-4 py-3 rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm">
        <div class="flex items-center gap-2.5">
            <i class="fas fa-circle-check text-emerald-500 text-lg shrink-0"></i>
            <p class="text-sm font-semibold text-emerald-800">{{ $successMsg }}</p>
        </div>
        <button wire:click="clearSuccess" class="text-emerald-400 hover:text-emerald-700 transition shrink-0">
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>
    @endif

    {{-- Main Layout --}}
    <div class="flex-1 flex items-start justify-center pt-1">
        <div class="w-full flex gap-5 items-start justify-center
                    {{ count($formErrors) > 0 ? 'max-w-5xl' : 'max-w-2xl' }}
                    transition-all duration-300">

            {{-- ── Form Column ──────────────────────────────────────── --}}
            <div class="{{ count($formErrors) > 0 ? 'flex-1 min-w-0' : 'w-full' }}">
                <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0]">
                    <form wire:submit="registerAlumni" class="p-5 sm:p-6 space-y-5 pb-6">

                        {{-- Full Name --}}
                        <div class="space-y-3">
                            <label class="block text-base font-bold text-[#333333] uppercase tracking-wide">
                                Full Name <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regFirstName" type="text" placeholder="First Name"
                                           class="w-full px-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                           maxlength="100" autocomplete="given-name">
                                    <p class="text-sm text-[#666666] mt-1.5 font-medium">First Name <span class="text-red-500">*</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regLastName" type="text" placeholder="Last Name"
                                           class="w-full px-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                           maxlength="100" autocomplete="family-name">
                                    <p class="text-sm text-[#666666] mt-1.5 font-medium">Last Name <span class="text-red-500">*</span></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. Santos"
                                           class="w-full px-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                           maxlength="50">
                                    <p class="text-sm text-[#666666] mt-1.5 font-medium">Middle Name <span class="text-red-500">*</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr."
                                           class="w-full px-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                           maxlength="10">
                                    <p class="text-sm text-[#666666] mt-1.5 font-medium">Suffix <span class="text-[#AAAAAA] font-normal">(optional)</span></p>
                                </div>
                            </div>
                        </div>

                        {{-- Student ID --}}
                        <div>
                            <label class="block text-base font-bold text-[#333333] uppercase tracking-wide mb-2">
                                Student ID <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345"
                                   class="w-full px-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] font-mono placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                   maxlength="8" inputmode="numeric" autocomplete="off">
                        </div>

                        {{-- Course + Batch --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" style="overflow:visible;position:relative;z-index:10;">

                            {{-- ══════════════════════════════════════════════════════
                                 COURSE — Calendar-style grid picker (opens UPWARD).
                                 Courses are paginated in a 4-column grid, 12 per page,
                                 sorted alphabetically by code. Same look & feel as the
                                 Batch Year picker.
                            ══════════════════════════════════════════════════════ --}}
                            <div>
                                <label class="block text-base font-bold text-[#333333] uppercase tracking-wide mb-2">
                                    Course <span class="text-red-500">*</span>
                                </label>

                                {{--
                                    We pass all course codes to Alpine as a JSON array so
                                    we can paginate purely in JS without extra Livewire calls.
                                --}}
                                @php
                                    $courseCodesJson = $this->courses->pluck('code')->toJson();
                                @endphp

                                <div class="reg-dropdown"
                                     x-data="{
                                         open: false,
                                         allCodes: {{ $courseCodesJson }},
                                         pageSize: 12,
                                         pageIndex: 0,
                                         get totalPages() { return Math.max(1, Math.ceil(this.allCodes.length / this.pageSize)); },
                                         get pageCodes() {
                                             let start = this.pageIndex * this.pageSize;
                                             return this.allCodes.slice(start, start + this.pageSize);
                                         },
                                         get rangeLabel() {
                                             if (this.allCodes.length === 0) return 'No courses';
                                             let start = this.pageIndex * this.pageSize;
                                             let end   = Math.min(start + this.pageSize - 1, this.allCodes.length - 1);
                                             if (this.totalPages === 1) return 'All Courses';
                                             return this.allCodes[start] + ' – ' + this.allCodes[end];
                                         },
                                         prevPage() { if (this.pageIndex > 0) this.pageIndex--; },
                                         nextPage() { if (this.pageIndex < this.totalPages - 1) this.pageIndex++; },
                                         canPrev() { return this.pageIndex > 0; },
                                         canNext() { return this.pageIndex < this.totalPages - 1; },
                                         toggle() { this.open = !this.open; },
                                         close()  { this.open = false; },
                                         select(code) {
                                             $wire.set('regCourseCode', code);
                                             this.close();
                                         },
                                         jumpToSelected() {
                                             let idx = this.allCodes.indexOf($wire.regCourseCode);
                                             if (idx >= 0) this.pageIndex = Math.floor(idx / this.pageSize);
                                         }
                                     }"
                                     @click.outside="close()">

                                    {{-- Trigger --}}
                                    <button type="button"
                                            @click="toggle(); if(open) jumpToSelected()"
                                            :class="{ 'has-value': $wire.regCourseCode !== '', 'open': open }"
                                            class="reg-dropdown-trigger">
                                        <i class="fas fa-book-open" style="font-size:.72rem;opacity:.6;flex-shrink:0;"></i>
                                        <span x-text="$wire.regCourseCode !== '' ? $wire.regCourseCode : 'Select Course…'"
                                              :class="{ 'reg-placeholder': $wire.regCourseCode === '' }"></span>
                                        <i class="fas fa-chevron-down reg-chevron"></i>
                                    </button>

                                    {{-- Course grid — opens UPWARD --}}
                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         class="reg-picker-panel reg-course-picker"
                                         style="display:none;">

                                        {{-- Header --}}
                                        <div class="reg-picker-header">
                                            <button type="button" @click.stop="prevPage()" :disabled="!canPrev()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-left" style="font-size:.58rem;"></i>
                                            </button>
                                            <span class="reg-picker-range-label" x-text="rangeLabel"></span>
                                            <button type="button" @click.stop="nextPage()" :disabled="!canNext()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-right" style="font-size:.58rem;"></i>
                                            </button>
                                        </div>

                                        {{-- 4-col grid --}}
                                        <div class="reg-picker-grid" style="grid-template-columns: repeat(4, 1fr);">
                                            <template x-for="code in pageCodes" :key="code">
                                                <button type="button"
                                                        @click.stop="select(code)"
                                                        :class="{ 'reg-picker-cell--selected': $wire.regCourseCode === code }"
                                                        class="reg-picker-cell"
                                                        x-text="code">
                                                </button>
                                            </template>
                                            {{-- Fill empty cells so the last row isn't ragged --}}
                                            <template x-for="i in (pageSize - pageCodes.length)" :key="'empty-' + i">
                                                <div></div>
                                            </template>
                                        </div>

                                        {{-- Footer --}}
                                        <div class="reg-picker-footer">
                                            <button type="button"
                                                    @click.stop="select('')"
                                                    class="reg-picker-clear-btn"
                                                    x-show="$wire.regCourseCode !== ''"
                                                    style="display:none;">
                                                <i class="fas fa-xmark" style="font-size:.65rem;margin-right:3px;"></i>Clear
                                            </button>
                                            <span x-show="totalPages > 1"
                                                  class="reg-picker-action-btn"
                                                  style="cursor:default;background:transparent;color:#aaa;font-size:.68rem;margin-left:0;">
                                                Page <span x-text="pageIndex + 1"></span> / <span x-text="totalPages"></span>
                                            </span>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            {{-- ══════════════════════════════════════════════════════
                                 BATCH YEAR — Calendar-style year grid picker.
                                 4-column grid, 12 years per page. Range: 1995 – 3030.
                                 Picker opens UPWARD so it never gets clipped.
                            ══════════════════════════════════════════════════════ --}}
                            <div>
                                <label class="block text-base font-bold text-[#333333] uppercase tracking-wide mb-2">
                                    Batch Year <span class="text-red-500">*</span>
                                </label>
                                <div class="reg-dropdown"
                                     x-data="{
                                         open: false,
                                         minYear: 1995,
                                         maxYear: 3030,
                                         today: {{ (int) date('Y') }},
                                         pageSize: 12,
                                         pageStart: Math.floor(({{ (int) date('Y') }} - 1995) / 12) * 12 + 1995,
                                         get pageYears() {
                                             let ys = [];
                                             for (let y = this.pageStart; y < this.pageStart + this.pageSize; y++) {
                                                 if (y >= this.minYear && y <= this.maxYear) ys.push(y);
                                             }
                                             return ys;
                                         },
                                         prevPage() {
                                             if (this.canPrev()) this.pageStart -= this.pageSize;
                                         },
                                         nextPage() {
                                             if (this.canNext()) this.pageStart += this.pageSize;
                                         },
                                         canPrev() { return this.pageStart > this.minYear; },
                                         canNext() { return this.pageStart + this.pageSize <= this.maxYear; },
                                         toggle() { this.open = !this.open; },
                                         close()  { this.open = false; },
                                         select(y) {
                                             $wire.set('regYear', y === '' ? '' : String(y));
                                             this.close();
                                         },
                                         jumpToToday() {
                                             this.pageStart = Math.floor((this.today - this.minYear) / this.pageSize) * this.pageSize + this.minYear;
                                             this.select(this.today);
                                         }
                                     }"
                                     @click.outside="close()">

                                    {{-- Trigger --}}
                                    <button type="button"
                                            @click="toggle()"
                                            :class="{ 'open': open }"
                                            class="reg-dropdown-trigger">
                                        <i class="fas fa-calendar-alt" style="font-size:.72rem;opacity:.6;flex-shrink:0;"></i>
                                        <span x-text="$wire.regYear !== '' ? $wire.regYear : 'Select Year…'"
                                              :class="{ 'reg-placeholder': $wire.regYear === '' }"></span>
                                        <i class="fas fa-chevron-down reg-chevron"></i>
                                    </button>

                                    {{-- Year grid — opens UPWARD --}}
                                    <div x-show="open"
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         class="reg-picker-panel reg-year-picker"
                                         style="display:none;">

                                        {{-- Header --}}
                                        <div class="reg-picker-header">
                                            <button type="button" @click.stop="prevPage()" :disabled="!canPrev()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-left" style="font-size:.58rem;"></i>
                                            </button>
                                            <span class="reg-picker-range-label"
                                                  x-text="pageStart + ' – ' + Math.min(pageStart + pageSize - 1, maxYear)"></span>
                                            <button type="button" @click.stop="nextPage()" :disabled="!canNext()" class="reg-picker-nav-btn">
                                                <i class="fas fa-chevron-right" style="font-size:.58rem;"></i>
                                            </button>
                                        </div>

                                        {{-- 4-col grid --}}
                                        <div class="reg-picker-grid" style="grid-template-columns: repeat(4, 1fr);">
                                            <template x-for="y in pageYears" :key="y">
                                                <button type="button"
                                                        @click.stop="select(y)"
                                                        :class="{
                                                            'reg-picker-cell--selected': $wire.regYear === String(y),
                                                            'reg-picker-cell--today':    y === today && $wire.regYear !== String(y)
                                                        }"
                                                        class="reg-picker-cell"
                                                        x-text="y">
                                                </button>
                                            </template>
                                        </div>

                                        {{-- Footer --}}
                                        <div class="reg-picker-footer">
                                            <button type="button"
                                                    @click.stop="select('')"
                                                    class="reg-picker-clear-btn"
                                                    x-show="$wire.regYear !== ''"
                                                    style="display:none;">
                                                <i class="fas fa-xmark" style="font-size:.65rem;margin-right:3px;"></i>Clear
                                            </button>
                                            <button type="button"
                                                    @click.stop="jumpToToday()"
                                                    class="reg-picker-action-btn">
                                                <i class="fas fa-circle-dot" style="font-size:.65rem;margin-right:3px;"></i>This Year
                                            </button>
                                        </div>

                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- Email --}}
                        <div>
                            <label class="block text-base font-bold text-[#333333] uppercase tracking-wide mb-2">
                                Email Address <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-envelope text-[#AAAAAA]"></i>
                                </span>
                                <input wire:model.defer="regEmail" type="email"
                                       class="w-full pl-9 pr-3 py-2.5 border border-[#E8E0F0] rounded-lg text-base bg-white text-[#333333] placeholder-[#AAAAAA] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                       maxlength="255" autocomplete="email">
                            </div>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex gap-3 pt-1">
                            <button type="button" wire:click="resetForm"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-2.5 rounded-lg text-base font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                                <i class="fa-solid fa-arrow-rotate-left mr-1"></i> Reset
                            </button>
                            <button type="submit"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-2.5 rounded-lg text-base font-semibold text-white transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                    style="background:#7A3F91;">
                                <span wire:loading wire:target="registerAlumni">
                                    <i class="fas fa-spinner animate-spin"></i> Registering…
                                </span>
                                <span wire:loading.remove wire:target="registerAlumni">
                                    <i class="fas fa-user-check"></i> Register Alumni
                                </span>
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            {{-- ── Side Error Panel ─────────────────────────────────── --}}
            @if(count($formErrors) > 0)
            <div class="w-80 shrink-0">
                <div class="bg-red-50 border border-red-200 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-4 py-3 flex items-center justify-between" style="background:#DC2626;">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-circle-xmark text-white text-base"></i>
                            <span class="text-base font-semibold text-white">Validation Errors</span>
                        </div>
                        <button wire:click="resetForm" type="button" class="text-white/70 hover:text-white transition">
                            <i class="fas fa-xmark text-sm"></i>
                        </button>
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
</div>

{{-- ══ IMPORT MODAL ════════════════════════════════════════════════ --}}
@if($showImportModal)
<div class="fixed inset-0 z-50 flex items-center justify-center px-4"
     style="background:rgba(27,6,46,0.60);backdrop-filter:blur(4px);"
     @keydown.escape.window="@if($importStep !== 'processing') $wire.closeImportModal() @endif">

    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:90vh;animation:panelIn .22s cubic-bezier(.4,0,.2,1) both;">
        <style>
            @keyframes panelIn {
                from { opacity:0; transform:translateY(14px) scale(.98); }
                to   { opacity:1; transform:none; }
            }
        </style>

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 shrink-0" style="background:#7A3F91;">
            <h2 class="text-white font-semibold text-xl flex items-center gap-2.5">
                <i class="fas fa-file-import"></i> Import Alumni Records
            </h2>
            <button wire:click="closeImportModal"
                    @if($importStep === 'processing') disabled @endif
                    class="w-8 h-8 rounded-xl flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition disabled:opacity-30 disabled:cursor-not-allowed">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Scrollable body --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">

            {{-- ── Step Indicator ──────────────────────────────── --}}
            @php
                $stepMap     = ['upload' => 0, 'preview' => 1, 'processing' => 2, 'blocked' => 2, 'done' => 3];
                $currentStep = $stepMap[$importStep] ?? 0;
                $stepDefs    = [[0,'1','Upload'],[1,'2','Preview'],[2,'3','Processing'],[3,'4','Done']];
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

            {{-- STEP 1 · UPLOAD --}}
            @if($importStep === 'upload')

            <div class="p-4 rounded-xl bg-blue-50 border border-blue-200">
                <p class="font-bold text-base text-blue-900 mb-2.5 flex items-center gap-1.5">
                    <i class="fas fa-circle-info text-blue-500"></i>Required Columns
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach(['first_name','last_name','middle_name','student_id','course','batch','email'] as $col)
                        <code class="bg-blue-100 text-blue-800 px-2.5 py-1 rounded-lg text-sm font-mono font-semibold">{{ $col }}</code>
                    @endforeach
                </div>
            </div>

            @if($importStatus === 'invalid_type')
            <div class="flex items-start gap-3 p-3.5 rounded-xl bg-red-50 border border-red-200">
                <i class="fas fa-circle-xmark text-red-500 mt-0.5 shrink-0"></i>
                <div>
                    <p class="font-bold text-red-800 text-sm">Invalid file type</p>
                    <p class="text-sm text-red-700 mt-0.5">Only <strong>.xlsx</strong> and <strong>.xls</strong> Excel files are accepted. CSV files are not supported.</p>
                </div>
            </div>
            @endif

            <div>
                <p class="text-sm font-bold text-[#555555] uppercase tracking-wide mb-2">Choose File</p>
                <div class="border-2 border-dashed border-[#E8E0F0] rounded-xl p-8 text-center cursor-pointer hover:border-[#7A3F91] hover:bg-[#faf5ff] transition"
                     @click="document.getElementById('importFileInput').click()">
                    <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-[#F5F5F5]">
                        <i class="fas fa-file-excel text-2xl text-[#BBBBBB]"></i>
                    </div>
                    <p class="text-[#333333] font-semibold text-base">Click to choose file</p>
                    <p class="text-[#888888] text-sm mt-1">Excel files only (.xlsx / .xls)</p>
                    <input type="file" id="importFileInput" wire:model="importFile" accept=".xlsx,.xls" class="hidden">
                </div>
                <div wire:loading wire:target="importFile" class="mt-2.5 text-base text-[#7A3F91] flex items-center gap-2">
                    <i class="fas fa-spinner animate-spin text-sm"></i> Reading file…
                </div>
            </div>

            <button wire:click="closeImportModal"
                    class="w-full px-4 py-3 rounded-xl text-base font-bold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                Cancel
            </button>

            {{-- STEP 2 · PREVIEW --}}
            @elseif($importStep === 'preview')

            <div class="flex items-center gap-4 p-4 rounded-xl bg-[#faf5ff] border border-[#E8E0F0]">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0" style="background:#7A3F91;">
                    <i class="fas fa-file-excel text-white text-xl"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-bold text-[#333333] text-base truncate">{{ $importFileName }}</p>
                    <p class="text-sm text-[#888888] mt-0.5">File ready — confirm below to start importing.</p>
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
                    Clicking <strong>Confirm Import</strong> will begin processing immediately.
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
                        <i class="fas fa-spinner animate-spin"></i> Starting…
                    </span>
                    <span wire:loading.remove wire:target="confirmImport">
                        <i class="fas fa-play"></i> Confirm Import
                    </span>
                </button>
            </div>

            {{-- STEP 3 · PROCESSING --}}
            @elseif($importStep === 'processing')

            <div wire:init="processImport" class="py-12 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-5 shadow-lg" style="background:#7A3F91;">
                    <i class="fas fa-spinner animate-spin text-white text-2xl"></i>
                </div>
                <p class="font-bold text-[#333333] text-xl mb-1.5">
                    @if($importTotal > 0)
                        Processing {{ $importProgress }} / {{ $importTotal }}
                    @else
                        Preparing…
                    @endif
                </p>
                <p class="text-base text-[#888888] mb-6">{{ $importStatus ?: 'Please wait…' }}</p>
                @if($importTotal > 0)
                <div class="w-full bg-[#F0F0F0] rounded-full h-3 overflow-hidden">
                    @php $pct = $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0; @endphp
                    <div class="h-full rounded-full transition-all duration-500"
                         style="width:{{ $pct }}%;background:#7A3F91;"></div>
                </div>
                <p class="text-sm text-[#888888] mt-2.5 font-semibold">{{ $pct }}% complete</p>
                @endif
            </div>

            {{-- STEP BLOCKED · fatal error --}}
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

            {{-- STEP 4 · DONE --}}
            @elseif($importStep === 'done')

            @php
                $hasNew    = $importSuccessCount   > 0;
                $hasErrors = $importFailCount       > 0;
                $hasDups   = $importDuplicateCount  > 0;
            @endphp

            <div class="flex items-start gap-3 p-4 rounded-xl border
                        {{ $hasNew ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }}">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0
                            {{ $hasNew ? 'bg-emerald-100' : 'bg-amber-100' }}">
                    <i class="{{ $hasNew ? 'fas fa-circle-check text-emerald-600 text-lg' : 'fas fa-triangle-exclamation text-amber-600 text-lg' }}"></i>
                </div>
                <div>
                    @if($hasNew)
                        <p class="font-bold text-emerald-900 text-base">Import Complete</p>
                        <p class="text-base text-emerald-700 mt-0.5">
                            <strong>{{ $importSuccessCount }}</strong> record(s) imported — all <strong>VERIFIED</strong>
                            @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                            @if($hasErrors) · {{ $importFailCount }} error(s) @endif
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
                    ['#f0fdf4','#bbf7d0','#15803d', $importSuccessCount,   'Imported'],
                    ['#fffbeb','#fde68a','#92400e', $importDuplicateCount, 'Duplicate'],
                    ['#fef2f2','#fecaca','#991b1b', $importFailCount,      'Errors'],
                ] as [$bg, $border, $clr, $cnt, $lbl])
                <div class="rounded-xl p-3 text-center" style="background:{{ $bg }};border:1px solid {{ $border }};">
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
                <ul class="divide-y divide-red-50 overflow-y-auto" style="max-height:160px;">
                    @foreach($importErrors as $err)
                    <li class="px-4 py-2.5 text-base text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-xmark text-red-300 mt-0.5 shrink-0 text-sm"></i>
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
                <ul class="divide-y divide-amber-50 overflow-y-auto" style="max-height:130px;">
                    @foreach($importDuplicates as $dup)
                    <li class="px-4 py-2.5 text-base text-amber-700 flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation text-amber-300 mt-0.5 shrink-0 text-sm"></i>
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
                @elseif($importStep === 'preview')    Review the file, then click Confirm Import.
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