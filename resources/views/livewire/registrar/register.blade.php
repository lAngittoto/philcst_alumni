{{-- resources/views/livewire/registrar/register-alumni.blade.php --}}

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
    public string $regEmail         = '';

    // ══ REGISTER STATE ═══════════════════════════════════════════
    public bool   $submitting   = false;
    public array  $formErrors   = [];
    public string $successMsg   = '';
    public string $successName  = '';
    public string $successId    = '';
    public string $successPass  = '';
    public string $successEmail = '';

    // ══ IMPORT STATE ══════════════════════════════════════════════
    public bool   $showImportModal      = false;
    public        $importFile           = null;
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

        // ── Middle name is now REQUIRED ──────────────────────────
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

            $this->successMsg   = "Alumni '{$fullName}' registered successfully!";
            $this->successName  = $fullName;
            $this->successId    = $paddedId;
            $this->successPass  = $tmpPass;
            $this->successEmail = $email;
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
        $this->successMsg = $this->successName = $this->successId = $this->successPass = $this->successEmail = '';
    }

    // ══ IMPORT HELPERS ════════════════════════════════════════════
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

            $ext = strtolower($this->importFile->getClientOriginalExtension());
            if (!in_array($ext, ['csv', 'xlsx', 'xls'], true))
                throw new \Exception('File must be .csv, .xlsx, or .xls.');

            $rows = match(true) {
                in_array($ext, ['xlsx', 'xls']) => $this->parseExcel($this->importFile->getRealPath()),
                $ext === 'csv'                  => array_map('str_getcsv', file($this->importFile->getRealPath())),
            };

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

            $allCourses    = Course::all();
            $courseByCode  = $allCourses->keyBy(fn($c) => strtoupper(trim($c->code)));
            $courseByName  = $allCourses->keyBy(fn($c) => strtolower(trim($c->name)));

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
                $this->importStatus = 'Done'; $this->importStep = 'done'; $this->importingFile = false; $this->importFile = null; return;
            }

            $this->importStatus = 'Importing…';
            $now = now()->toDateTimeString();

            // ── Pre-hash all passwords in one pass using cost=4 for speed ──
            // BCrypt default cost=10 is ~100ms each — cost=4 is ~3ms each.
            // Temp passwords are short-lived and changed on first login.
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
                            'user_id'             => $uid,
                            'first_name'          => $job['firstName'],
                            'middle_initial'      => $job['mid'] ?: null,
                            'last_name'           => $job['lastName'],
                            'suffix'              => $job['suffix'] ?: null,
                            'student_id'          => $job['sid'],
                            'email'               => $job['email'],
                            'course_code'         => $job['code'],
                            'course_name'         => $job['courseName'],
                            'batch'               => $job['batchYear'],
                            'status'              => 'VERIFIED',
                            'password_changed_at' => $now,
                            'profile_photo'       => null,
                            'profile_completed'   => 0,
                            'gender'              => null,
                            'date_of_birth'       => null,
                            'contact_number'      => null,
                            'disability'          => null,
                            'dswd_household_no'   => null,
                            'father_last_name'    => null,
                            'father_given_name'   => null,
                            'father_middle_name'  => null,
                            'mother_last_name'    => null,
                            'mother_given_name'   => null,
                            'mother_middle_name'  => null,
                            'address_street'      => null,
                            'address_barangay'    => null,
                            'address_municipality'=> null,
                            'address_province'    => null,
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ];
                    }
                    if (!empty($alumniRows)) {
                        DB::table('alumni')->insert($alumniRows);
                        $this->importSuccessCount += count($alumniRows);
                    }
                    $this->importProgress = min($this->importTotal, (int) round(($chunkIdx + 1) / max(1, ceil(count($jobs) / 500)) * $this->importTotal));
                }
            });

            $this->importProgress = $this->importTotal;
            $this->importStatus   = 'Done';
            $this->importStep     = 'done';
            $this->importingFile  = false;
            $this->importFile     = null;
            Log::info("Alumni import: {$this->importSuccessCount} inserted, {$this->importFailCount} errors, {$this->importDuplicateCount} duplicates.");

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

{{-- ══ PAGE ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-4 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
                <i class="fas fa-user-plus text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-semibold text-[#333333] leading-tight">Register Alumni</h1>
                <p class="text-[#666666] text-sm font-normal mt-0.5">Add new alumni to the system with their details and credentials</p>
            </div>
        </div>

        {{-- Import Button --}}
        <button wire:click="openImportModal"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white shadow-sm transition hover:opacity-90 active:scale-95 shrink-0"
                style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-file-import text-sm"></i>
            <span class="hidden sm:inline">Import</span>
            <span class="sm:hidden">Import</span>
        </button>
    </div>

    {{-- Main Layout: Form + Side Panel --}}
    <div class="flex-1 flex items-start justify-center pt-1">
        <div class="w-full flex gap-5 items-start justify-center
                    {{ ($successMsg || count($formErrors) > 0) ? 'max-w-5xl' : 'max-w-2xl' }}
                    transition-all duration-300">

            {{-- Form Column --}}
            <div class="{{ ($successMsg || count($formErrors) > 0) ? 'flex-1 min-w-0' : 'w-full' }}">
                <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0] overflow-hidden">
                    <div class="px-4 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5]">

                    </div>
                    <form wire:submit="registerAlumni" class="p-5 sm:p-6 space-y-5">

                        {{-- Full Name --}}
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-[#333333] uppercase tracking-wide">
                                Full Name <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regFirstName" type="text" placeholder="First Name"
                                           class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                           maxlength="100" autocomplete="given-name">
                                    <p class="text-xs text-[#666666] mt-1 font-normal">First Name <span class="text-red-500">*</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regLastName" type="text" placeholder="Last Name"
                                           class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                           maxlength="100" autocomplete="family-name">
                                    <p class="text-xs text-[#666666] mt-1 font-normal">Last Name <span class="text-red-500">*</span></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <input wire:model.defer="regMiddleInitial" type="text" placeholder="e.g. Santos"
                                           class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                           maxlength="50">
                                    {{-- ── Middle name now REQUIRED ── --}}
                                    <p class="text-xs text-[#666666] mt-1 font-normal">Middle Name <span class="text-red-500">*</span></p>
                                </div>
                                <div>
                                    <input wire:model.defer="regSuffix" type="text" placeholder="e.g. Jr."
                                           class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                           maxlength="10">
                                    <p class="text-xs text-[#666666] mt-1 font-normal">Suffix</p>
                                </div>
                            </div>
                        </div>

                        {{-- Student ID --}}
                        <div>
                            <label class="block text-sm font-semibold text-[#333333] uppercase tracking-wide mb-2">
                                Student ID <span class="text-red-500">*</span>
                            </label>
                            <input wire:model.defer="regStudentId" type="text" placeholder="e.g. 12345"
                                   class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] font-mono placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                                   maxlength="8" inputmode="numeric" autocomplete="off">
                        </div>

                        {{-- Course + Batch --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-[#333333] uppercase tracking-wide mb-2">
                                    Course <span class="text-red-500">*</span>
                                </label>
                                <select wire:model.defer="regCourseCode"
                                        class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 cursor-pointer font-normal">
                                    <option value="">Select Course…</option>
                                    @foreach($this->courses as $c)
                                        <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-[#333333] uppercase tracking-wide mb-2">
                                    Batch Year <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="regYear" type="number" placeholder="{{ date('Y') }}"
                                       class="w-full px-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                       min="1900" max="9999">
                            </div>
                        </div>

                        {{-- Email --}}
                        <div>
                            <label class="block text-sm font-semibold text-[#333333] uppercase tracking-wide mb-2">
                                Email Address <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-envelope text-[#999999] text-sm"></i>
                                </span>
                                <input wire:model.defer="regEmail" type="email"
                                       class="w-full pl-9 pr-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333] placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                                       maxlength="255" autocomplete="email">
                            </div>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex gap-3 pt-2">
                            {{-- FIX: Cancel replaced with Reset — clears the form in place --}}
                            <button type="button"
                                    wire:click="resetForm"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-2.5 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                               <i class="fa-solid fa-arrow-rotate-left"></i> Reset
                            </button>
                            <button type="submit"
                                    wire:loading.attr="disabled" wire:target="registerAlumni"
                                    class="flex-1 px-5 py-2.5 rounded-lg text-sm font-semibold text-white transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                    style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
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

            {{-- Side Error Panel --}}
            @if(count($formErrors) > 0)
            <div class="w-80 shrink-0">
                <div class="bg-red-50 border border-red-200 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-4 py-3 flex items-center justify-between"
                         style="background:linear-gradient(135deg,#DC2626,#DC2626);">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-circle-xmark text-white text-base"></i>
                            <span class="text-sm font-semibold text-white">Validation Errors</span>
                        </div>
                        <button wire:click="resetForm" type="button" class="text-white/70 hover:text-white transition">
                            <i class="fas fa-xmark text-sm"></i>
                        </button>
                    </div>
                    <div class="p-4">
                        <p class="text-xs font-semibold text-red-800 mb-3">Please fix the following errors:</p>
                        <ul class="space-y-2">
                            @foreach($formErrors as $msgs)
                                @foreach($msgs as $msg)
                                    <li class="flex items-start gap-2 text-sm">
                                        <span class="shrink-0 mt-0.5 text-red-500 font-bold">•</span>
                                        <span class="text-red-700 leading-relaxed">{{ $msg }}</span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            @endif

            {{-- Side Success Panel --}}
            @if($successMsg)
            <div class="w-80 shrink-0">
                <div class="bg-emerald-50 border border-emerald-200 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-4 py-3 flex items-center justify-between"
                         style="background:linear-gradient(135deg,#059669,#059669);">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-circle-check text-white text-base"></i>
                            <span class="text-sm font-semibold text-white">Registration Successful</span>
                        </div>
                        <button wire:click="clearSuccess" class="text-white/70 hover:text-white transition">
                            <i class="fas fa-xmark text-sm"></i>
                        </button>
                    </div>
                    <div class="p-4 space-y-4">
                        <div class="text-center py-3 px-2 bg-white rounded-xl border border-emerald-100">
                            <div class="w-12 h-12 rounded-full mx-auto mb-2 flex items-center justify-center"
                                 style="background:linear-gradient(135deg,#7A3F91,#7A3F91);">
                                <i class="fas fa-user-graduate text-white"></i>
                            </div>
                            <p class="text-xs text-[#666666] mb-0.5 font-normal">Registered Alumni</p>
                            <p class="text-sm font-semibold text-[#333333] leading-tight">{{ $successName }}</p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-[10px] font-semibold text-[#666666] uppercase tracking-wide">Login Credentials</p>
                            <div class="bg-white rounded-xl border border-emerald-100 divide-y divide-emerald-50">
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-[#666666] font-semibold mb-0.5">Student ID</p>
                                    <p class="text-sm font-mono font-semibold text-[#333333]">{{ $successId }}</p>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-[#666666] font-semibold mb-0.5">Temporary Password</p>
                                    <p class="text-sm font-mono font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-1 rounded-lg inline-block mt-0.5">{{ $successPass }}</p>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-[#666666] font-semibold mb-0.5">Email Address</p>
                                    <p class="text-sm font-normal text-[#333333] break-all">{{ $successEmail }}</p>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="text-[10px] text-[#666666] font-semibold mb-0.5">Status</p>
                                    <span class="text-xs font-semibold px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full">VERIFIED</span>
                                </div>
                            </div>
                        </div>
                        <p class="text-[10px] text-emerald-700 leading-relaxed bg-emerald-100/60 rounded-lg p-2.5 font-normal">
                            <i class="fas fa-circle-info mr-1"></i>Share the temporary password with the alumni. They can change it after logging in.
                        </p>
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
     style="background:rgba(27,6,46,0.60); backdrop-filter:blur(4px);"
     @keydown.escape.window="@if($importStep !== 'processing') $wire.closeImportModal() @endif">

    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:90vh; animation:panelIn .22s cubic-bezier(.4,0,.2,1) both;">
        <style>
            @keyframes panelIn {
                from { opacity:0; transform:translateY(14px) scale(.98); }
                to   { opacity:1; transform:none; }
            }
        </style>

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-5 py-4 shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#5A2D70);">
            <h2 class="text-white font-semibold text-xl flex items-center gap-2.5">
                <i class="fas fa-file-import"></i>
                Import Alumni Records
            </h2>
            <button wire:click="closeImportModal"
                    @if($importStep === 'processing') disabled @endif
                    class="w-8 h-8 rounded-xl flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition disabled:opacity-30 disabled:cursor-not-allowed">
                <i class="fas fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Scrollable Content --}}
        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4">

            {{-- Step indicator --}}
            <div class="flex items-center gap-2">
                @foreach([['upload','1','Upload'],['processing','2','Processing'],['done','3','Done']] as [$st,$num,$lbl])
                @php
                    $stepMap  = ['upload' => 0, 'processing' => 1, 'blocked' => 1, 'done' => 2];
                    $current  = $stepMap[$importStep] ?? 0;
                    $stepIdx  = (int)$num - 1;
                    $active   = $current === $stepIdx;
                    $done     = $current > $stepIdx;
                @endphp
                <div class="flex items-center gap-1.5 {{ $active ? 'opacity-100' : ($done ? 'opacity-80' : 'opacity-30') }}">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold text-white shrink-0"
                         style="background:{{ $active || $done ? '#7A3F91' : '#ccc' }}">
                        @if($done) <i class="fas fa-check" style="font-size:10px;"></i>
                        @else {{ $num }}
                        @endif
                    </div>
                    <span class="text-sm font-semibold text-[#333333]">{{ $lbl }}</span>
                </div>
                @if($num < 3)
                    <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                @endif
                @endforeach
            </div>

            {{-- ── UPLOAD STEP ────────────────────────────────── --}}
            @if($importStep === 'upload')

            {{-- Required columns info box --}}
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

            <div>
                <p class="text-sm font-bold text-[#555555] uppercase tracking-wide mb-2">Upload File</p>
                <div class="border-2 border-dashed border-[#E8E0F0] rounded-xl p-8 text-center cursor-pointer hover:border-[#7A3F91] hover:bg-[#faf5ff] transition"
                     @click="document.getElementById('importFileInput').click()">
                    @if($importFile)
                        <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-emerald-100">
                            <i class="fas fa-file-circle-check text-2xl text-emerald-500"></i>
                        </div>
                        <p class="text-emerald-700 font-bold text-base">{{ $importFile->getClientOriginalName() }}</p>
                        <p class="text-[#888888] text-sm mt-1">Click to change file</p>
                    @else
                        <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-[#F5F5F5]">
                            <i class="fas fa-file-arrow-up text-2xl text-[#BBBBBB]"></i>
                        </div>
                        <p class="text-[#333333] font-semibold text-base">Click to choose file</p>
                        <p class="text-[#888888] text-sm mt-1">CSV or Excel (.xlsx / .xls)</p>
                    @endif
                    <input type="file" id="importFileInput" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
                </div>
                <div wire:loading wire:target="importFile" class="mt-2.5 text-base text-[#7A3F91] flex items-center gap-2">
                    <i class="fas fa-spinner animate-spin text-sm"></i> Reading file…
                </div>
            </div>

            <div class="flex gap-3">
                <button wire:click="closeImportModal"
                        class="flex-1 px-4 py-3 rounded-xl text-base font-bold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition text-center active:scale-[.99]">
                    Cancel
                </button>
                <button type="button" wire:click="processImport"
                        @if(!$importFile) disabled @endif
                        wire:loading.attr="disabled" wire:target="processImport"
                        class="flex-1 px-4 py-3 rounded-xl text-base font-bold text-white transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2 hover:opacity-90 active:scale-[.99]"
                        style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <span wire:loading wire:target="processImport"><i class="fas fa-spinner animate-spin"></i> Processing…</span>
                    <span wire:loading.remove wire:target="processImport"><i class="fas fa-upload"></i> Import Now</span>
                </button>
            </div>

            {{-- ── PROCESSING STEP ────────────────────────────── --}}
            @elseif($importStep === 'processing')

            <div class="py-12 text-center">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-5 shadow-lg"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-spinner animate-spin text-white text-2xl"></i>
                </div>
                <p class="font-bold text-[#333333] text-xl mb-1.5">
                    Processing {{ $importProgress }} / {{ $importTotal }}
                </p>
                <p class="text-base text-[#888888] mb-6">{{ $importStatus }}</p>
                <div class="w-full bg-[#F0F0F0] rounded-full h-3 overflow-hidden">
                    @php $pct = $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0; @endphp
                    <div class="h-full rounded-full transition-all duration-500"
                         style="width:{{ $pct }}%; background:linear-gradient(90deg,#7A3F91,#9b59b6);"></div>
                </div>
                <p class="text-sm text-[#888888] mt-2.5 font-semibold">{{ $pct }}% complete</p>
            </div>

            {{-- ── BLOCKED STEP ────────────────────────────────── --}}
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

            {{-- ── DONE STEP ────────────────────────────────────── --}}
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
                ] as [$bg, $border, $clr, $num, $lbl])
                <div class="rounded-xl p-3 text-center" style="background:{{ $bg }};border:1px solid {{ $border }};">
                    <p class="text-2xl font-extrabold" style="color:{{ $clr }};">{{ $num }}</p>
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
                        style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                    <i class="fas fa-check mr-2"></i>Done
                </button>
            </div>

            @endif
        </div>

        {{-- Modal Footer --}}
        <div class="shrink-0 px-5 py-3 border-t border-[#E8E0F0] bg-[#FAFAFA] flex items-center justify-between">
            <p class="text-sm text-[#888888] font-normal">
                @if($importStep === 'upload')
                @elseif($importStep === 'processing') Do not close this window while importing.
                @elseif($importStep === 'done')   Import complete — you may close this window.
                @else                             Fix the error and try again.
                @endif
            </p>
            @if($importStep !== 'processing')
            <button wire:click="closeImportModal"
                    class="text-sm text-[#555555] hover:text-[#333333] font-semibold flex items-center gap-1 transition">
            </button>
            @endif
        </div>
    </div>
</div>
@endif

</div>