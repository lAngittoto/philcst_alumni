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

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function generateTempPassword(string $paddedId, string $lastName): string
    {
        $raw  = substr(trim($lastName), 0, 2);
        $part = ucfirst(strtolower($raw));
        return $paddedId . '_' . $part;
    }

    private function sanitizeText(string $val): string
    {
        return mb_substr(strip_tags(trim($val)), 0, 255);
    }

    private function appendImportError(array &$errors, int $max, string $msg): void
    {
        $this->importFailCount++;
        if (count($errors) < $max)       $errors[] = $msg;
        elseif (count($errors) === $max) $errors[] = '… (additional errors truncated)';
    }

    private function buildFullName(string $f, string $m, string $l, string $s): string
    {
        return implode(' ', array_filter(array_map('trim', [$f, $m, $l, $s])));
    }

    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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

    // ─────────────────────────────────────────────────────────────────────────
    // Excel parser
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Main import
    // ─────────────────────────────────────────────────────────────────────────

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

            // ── Required columns ──────────────────────────────────────────────
            $hasCourse     = in_array('course', $header, true);
            $hasCourseCode = in_array('course_code', $header, true);
            if (!$hasCourse && !$hasCourseCode) {
                throw new \Exception('Missing required column: "course" (or "course_code").');
            }
            if (!$hasCourse && $hasCourseCode) {
                $header[array_search('course_code', $header)] = 'course';
            }

            $required = ['first_name', 'last_name', 'middle_name', 'student_id', 'course', 'batch', 'email'];
            foreach ($required as $col) {
                if (!in_array($col, $header, true))
                    throw new \Exception("Missing required column: \"{$col}\".");
            }

            $this->importTotal = count($rows) - 1;

            // ── Pre-load lookups ──────────────────────────────────────────────
            $allCourses    = Course::all();
            $courseByCode  = $allCourses->keyBy(fn($c) => strtoupper(trim($c->code)));
            $courseByName  = $allCourses->keyBy(fn($c) => strtolower(trim($c->name)));

            $existingIds = DB::table('alumni')
                             ->pluck('student_id')
                             ->flip()
                             ->toArray();

            $existingEmails = DB::table('users')
                                ->whereNotNull('email')
                                ->where('email', '!=', '')
                                ->pluck('email')
                                ->map(fn($e) => strtolower($e))
                                ->flip()
                                ->toArray();

            $existingNames = DB::table('alumni')
                               ->selectRaw("LOWER(TRIM(first_name)) as fn,
                                            LOWER(TRIM(COALESCE(middle_initial,''))) as mi,
                                            LOWER(TRIM(last_name)) as ln,
                                            LOWER(TRIM(COALESCE(suffix,''))) as sf")
                               ->get()
                               ->map(fn($a) => $a->fn.'|'.$a->mi.'|'.$a->ln.'|'.$a->sf)
                               ->flip()
                               ->toArray();

            // ── Validation pass ───────────────────────────────────────────────
            $jobs             = [];
            $seenIds          = [];
            $seenEmails       = [];
            $seenNames        = [];
            $validationErrors = [];
            $duplicates       = [];
            $maxErrors        = 200;

            for ($i = 1; $i < count($rows); $i++) {
                if (count(array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) === 0) continue;
                if (count($rows[$i]) < count($header)) continue;

                $row       = array_combine($header, array_slice($rows[$i], 0, count($header)));
                $firstName = trim($row['first_name']  ?? '');

                // ── FIX: normalise middle name — collapse multiple spaces ──────
                $mid       = preg_replace('/\s+/', ' ', trim($row['middle_name'] ?? ''));

                $lastName  = trim($row['last_name']   ?? '');
                $suffix    = trim($row['suffix']      ?? '');
                $email     = strtolower(trim($row['email'] ?? ''));
                $fullName  = $this->buildFullName($firstName, $mid, $lastName, $suffix);
                $label     = 'Row '.($i + 1).($fullName ? " ({$fullName})" : '');

                // ── Email validation ──────────────────────────────────────────
                if (!$email) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Email is required and cannot be empty.");
                    continue;
                }
                if (!$this->validateEmail($email)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Email \"{$email}\" is not a valid email format.");
                    continue;
                }
                if (isset($existingEmails[$email]) || isset($seenEmails[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" is already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Name validation ───────────────────────────────────────────
                if (!$firstName) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name is empty.");
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: First name has invalid characters.");
                    continue;
                }
                if (!$lastName) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name is empty.");
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Last name has invalid characters.");
                    continue;
                }
                if ($mid === '') {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name is required.");
                    continue;
                }

                // ── FIX: allow spaces for compound middle names (Dela Cruz, De Leon, San Pedro) ──
                if (!preg_match('/^[a-zA-Z][a-zA-Z ]*[a-zA-Z]$|^[a-zA-Z]$/', $mid)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name must contain letters only (spaces allowed for compound names e.g. Dela Cruz).");
                    continue;
                }

                // ── FIX: check each word is at least 2 chars (rejects "D Cruz" but allows "De Cruz") ──
                $midParts = explode(' ', $mid);
                $midWordError = false;
                foreach ($midParts as $part) {
                    if (strlen($part) < 2) {
                        $this->appendImportError($validationErrors, $maxErrors, "{$label}: Each word in middle name must be at least 2 characters (e.g. \"Dela Cruz\" not \"D Cruz\").");
                        $midWordError = true;
                        break;
                    }
                }
                if ($midWordError) continue;

                // ── Duplicate name ────────────────────────────────────────────
                $nameKey = strtolower($firstName).'|'.strtolower($mid).'|'.strtolower($lastName).'|'.strtolower($suffix);
                if (isset($existingNames[$nameKey]) || isset($seenNames[$nameKey])) {
                    $duplicates[] = "{$label}: Full name \"{$fullName}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Student ID ────────────────────────────────────────────────
                $rawId      = preg_replace('/\..*$/', '', rtrim(rtrim((string)($row['student_id'] ?? ''), '0'), '.'));
                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int)$rawIdClean === 0) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Student ID \"{$rawId}\" is invalid (1–8 digits required).");
                    continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);
                if (isset($existingIds[$sid]) || isset($seenIds[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Course lookup — accept code OR full course name ────────────
                $courseInput = trim($row['course'] ?? '');
                $courseMatch = $courseByCode[strtoupper($courseInput)]
                            ?? $courseByName[strtolower($courseInput)]
                            ?? null;

                if (!$courseMatch) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Course \"{$courseInput}\" does not match any course code or name.");
                    continue;
                }
                $resolvedCode = $courseMatch->code;
                $resolvedName = $courseMatch->name;

                // ── Batch ─────────────────────────────────────────────────────
                $batchYear = (int)($row['batch'] ?? 0);
                if ($batchYear < 1000 || $batchYear > 9999) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Batch \"{$batchYear}\" must be a 4-digit year.");
                    continue;
                }

                $jobs[] = [
                    'fullName'    => $fullName,
                    'firstName'   => $firstName,
                    'mid'         => $mid,
                    'lastName'    => $lastName,
                    'suffix'      => $suffix,
                    'email'       => $email,
                    'sid'         => $sid,
                    'code'        => $resolvedCode,
                    'courseName'  => $resolvedName,
                    'batchYear'   => $batchYear,
                ];

                $seenIds[$sid]       = true;
                $seenEmails[$email]  = true;
                $seenNames[$nameKey] = true;
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

            // ── Bulk insert ───────────────────────────────────────────────────
            $this->importStatus = 'Importing…';

            $now         = now()->toDateTimeString();
            $chunkSize   = 500;
            $chunks      = array_chunk($jobs, $chunkSize);
            $totalChunks = count($chunks);

            DB::transaction(function () use ($chunks, $now, $totalChunks) {

                foreach ($chunks as $chunkIdx => $chunk) {

                    // ── 1. Prepare user rows ──────────────────────────────────
                    $userRows    = [];
                    $loginEmails = [];

                    foreach ($chunk as $job) {
                        $userRows[]    = [
                            'name'       => $job['fullName'],
                            'role'       => 'alumni',
                            'email'      => $job['email'],
                            'password'   => Hash::make($this->generateTempPassword($job['sid'], $job['lastName'])),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $loginEmails[] = $job['email'];
                    }

                    // ── 2. Bulk insert users ──────────────────────────────────
                    DB::table('users')->insert($userRows);

                    // ── 3. Map login email → user id ──────────────────────────
                    $userIdMap = DB::table('users')
                        ->whereIn('email', $loginEmails)
                        ->pluck('id', 'email')
                        ->toArray();

                    // ── 4. Build alumni rows ──────────────────────────────────
                    $alumniRows = [];

                    foreach ($chunk as $job) {
                        $userId = $userIdMap[$job['email']] ?? null;
                        if (!$userId) continue;

                        $row = [
                            'user_id'              => $userId,
                            'first_name'           => $job['firstName'],
                            'middle_initial'       => $job['mid']    ?: null,
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

                        $alumniRows[] = $row;
                    }

                    // ── 5. Bulk insert alumni ─────────────────────────────────
                    if (!empty($alumniRows)) {
                        DB::table('alumni')->insert($alumniRows);
                        $this->importSuccessCount += count($alumniRows);
                    }

                    $this->importProgress = min(
                        $this->importTotal,
                        (int) round(($chunkIdx + 1) / $totalChunks * $this->importTotal)
                    );
                }
            });

            $this->importProgress = $this->importTotal;
            $this->importStatus   = 'Done';
            $this->importStep     = 'done';
            $this->importingFile  = false;
            $this->importFile     = null;

            Log::info("Alumni import: {$this->importSuccessCount} inserted, {$this->importFailCount} errors, {$this->importDuplicateCount} duplicates.");

        } catch (\Exception $e) {
            Log::error('Import error: '.$e->getMessage());
            $this->importStatus  = 'Error: '.$e->getMessage();
            $this->importStep    = 'blocked';
            $this->importingFile = false;
        }
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-6 max-w-screen-2xl mx-auto" style="min-height:90vh;">

    {{-- Header --}}
    <div class="flex items-center gap-3 mb-5">
        <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
            <i class="fas fa-file-import text-white text-sm"></i>
        </div>
        <div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-[#333333] leading-tight">Import Alumni Records</h1>
            <p class="text-[#666666] text-xl sm:text-base font-normal mt-0.5">Upload and process alumni data from CSV or Excel files in bulk</p>
        </div>
    </div>

    <div class="max-w-2xl w-full mx-auto">
        <div class="bg-white rounded-2xl shadow-sm border border-purple-100 overflow-hidden">

            {{-- Card Header --}}
            <div class="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-[#f9f5ff] to-white">
                <p class="text-xl font-bold text-gray-500 uppercase tracking-wide">
                    @if($importStep === 'upload')         File Upload
                    @elseif($importStep === 'processing') Processing…
                    @elseif($importStep === 'blocked')    Import Failed
                    @else                                 Import Summary
                    @endif
                </p>
            </div>

            <div class="p-5 sm:p-6 space-y-5">

                {{-- ── UPLOAD STEP ──────────────────────────────────── --}}
                @if($importStep === 'upload')

                {{-- Column guide --}}
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-200">
                    <p class="font-bold text-xl text-blue-900 mb-2">
                        <i class="fas fa-circle-info mr-1.5"></i>Required Columns
                    </p>
                    <div class="flex flex-wrap gap-1 mb-2">
                        @foreach(['first_name','last_name','middle_name','student_id','course','batch','email'] as $col)
                            <code class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xl font-mono">{{ $col }}</code>
                        @endforeach
                    </div>
                    <p class="text-xl text-blue-600 mt-1">
                      <code class="bg-blue-100 px-1 rounded font-mono">suffix</code> 
                    </p>
                </div>

                {{-- File picker --}}
                <div>
                    <p class="text-xl font-bold text-gray-600 uppercase tracking-wide mb-2">Upload File</p>
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#faf5ff] transition"
                         @click="document.getElementById('importFileInput').click()">
                        @if($importFile)
                            <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-emerald-100">
                                <i class="fas fa-file-circle-check text-2xl text-emerald-500"></i>
                            </div>
                            <p class="text-emerald-700 font-bold text-xl">{{ $importFile->getClientOriginalName() }}</p>
                            <p class="text-gray-400 text-xl mt-0.5">Click to change file</p>
                        @else
                            <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-gray-100">
                                <i class="fas fa-file-arrow-up text-2xl text-gray-300"></i>
                            </div>
                            <p class="text-gray-700 font-semibold text-xl">Click to choose file</p>
                            <p class="text-gray-400 text-xl mt-0.5">CSV or Excel (.xlsx, .xls)</p>
                        @endif
                        <input type="file" id="importFileInput" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
                    </div>
                    <div wire:loading wire:target="importFile" class="mt-2 text-xl text-[#7a3f91] flex items-center gap-1.5">
                        <i class="fas fa-spinner animate-spin"></i> Reading file…
                    </div>
                </div>

                {{-- Buttons --}}
                <div class="flex gap-3">
                    <a href="{{ route('registrar.alumni') }}" wire:navigate
                       class="flex-1 px-5 py-2.5 rounded-xl text-xl font-bold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition text-center active:scale-[.99]">
                        Cancel
                    </a>
                    <button type="button"
                            wire:click="processImport"
                            @if(!$importFile) disabled @endif
                            wire:loading.attr="disabled" wire:target="processImport"
                            class="flex-1 px-5 py-2.5 rounded-xl text-xl font-bold text-white transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2 hover:opacity-90 active:scale-[.99]"
                            style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <span wire:loading wire:target="processImport">
                            <i class="fas fa-spinner animate-spin"></i> Processing…
                        </span>
                        <span wire:loading.remove wire:target="processImport">
                            <i class="fas fa-upload"></i> Import Now
                        </span>
                    </button>
                </div>

                {{-- ── PROCESSING STEP ──────────────────────────────── --}}
                @elseif($importStep === 'processing')

                <div class="py-10 text-center">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg"
                         style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        <i class="fas fa-spinner animate-spin text-white text-2xl"></i>
                    </div>
                    <p class="font-bold text-gray-800 text-xl mb-1">
                        Processing {{ $importProgress }} / {{ $importTotal }}
                    </p>
                    <p class="text-xl text-gray-400 mb-5">{{ $importStatus }}</p>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                        @php $pct = $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0; @endphp
                        <div class="h-full rounded-full transition-all duration-300"
                             style="width:{{ $pct }}%; background:linear-gradient(90deg,#7a3f91,#9b59b6);"></div>
                    </div>
                    <p class="text-xl text-gray-400 mt-2">{{ $pct }}% complete</p>
                </div>

                {{-- ── BLOCKED STEP ─────────────────────────────────── --}}
                @elseif($importStep === 'blocked')

                <div class="flex items-start gap-4 p-4 rounded-xl bg-red-50 border border-red-200">
                    <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fas fa-xmark text-red-600"></i>
                    </div>
                    <div>
                        <p class="font-bold text-red-900 text-xl">Import Failed</p>
                        <p class="text-xl text-red-700 mt-0.5">{{ $importStatus }}</p>
                    </div>
                </div>
                <button wire:click="resetImport"
                        class="w-full bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-xl font-bold hover:bg-gray-50 transition active:scale-[.99]">
                    <i class="fas fa-rotate-left mr-2"></i>Try Again
                </button>

                {{-- ── DONE STEP ────────────────────────────────────── --}}
                @elseif($importStep === 'done')

                @php
                    $hasNew    = $importSuccessCount   > 0;
                    $hasErrors = $importFailCount       > 0;
                    $hasDups   = $importDuplicateCount  > 0;
                @endphp

                <div class="flex items-start gap-4 p-4 rounded-xl border
                            {{ $hasNew ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }}">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0
                                {{ $hasNew ? 'bg-emerald-100' : 'bg-amber-100' }}">
                        <i class="{{ $hasNew ? 'fas fa-circle-check text-emerald-600' : 'fas fa-triangle-exclamation text-amber-600' }}"></i>
                    </div>
                    <div>
                        @if($hasNew)
                            <p class="font-bold text-emerald-900 text-xl">Import Complete</p>
                            <p class="text-xl text-emerald-700 mt-0.5">
                                <strong>{{ $importSuccessCount }}</strong> record(s) imported — all <strong>VERIFIED</strong>
                                @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                                @if($hasErrors) · {{ $importFailCount }} error(s) @endif
                            </p>
                        @else
                            <p class="font-bold text-amber-900 text-xl">Nothing Imported</p>
                            <p class="text-xl text-amber-700 mt-0.5">All records were duplicates or had errors.</p>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-2">
                    @foreach([
                        ['#f9fafb','#e5e7eb','#6b7280', $importTotal,          'Total'],
                        ['#f0fdf4','#bbf7d0','#15803d', $importSuccessCount,    'Imported'],
                        ['#fffbeb','#fde68a','#92400e', $importDuplicateCount,  'Duplicate'],
                        ['#fef2f2','#fecaca','#991b1b', $importFailCount,       'Errors'],
                    ] as [$bg, $border, $clr, $num, $lbl])
                    <div class="rounded-xl p-3 text-center" style="background:{{ $bg }};border:1px solid {{ $border }};">
                        <p class="text-xl font-extrabold" style="color:{{ $clr }};">{{ $num }}</p>
                        <p class="text-xl font-bold text-gray-400 uppercase tracking-wide mt-0.5">{{ $lbl }}</p>
                    </div>
                    @endforeach
                </div>

                @if($hasErrors)
                <div class="border border-red-200 rounded-xl overflow-hidden">
                    <div class="px-4 py-2.5 bg-red-50 border-b border-red-100 flex items-center gap-2">
                        <i class="fas fa-circle-xmark text-red-400 text-xl"></i>
                        <p class="font-bold text-red-900 text-xl">Validation Errors</p>
                        <span class="ml-auto text-xl bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                    </div>
                    <ul class="divide-y divide-red-50 overflow-y-auto" style="max-height:200px;">
                        @foreach($importErrors as $err)
                        <li class="px-4 py-2.5 text-xl text-red-700 flex items-start gap-2">
                            <i class="fas fa-circle-xmark text-red-300 mt-0.5 shrink-0"></i>
                            <span>{{ $err }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if($hasDups && count($importDuplicates) > 0)
                <div class="border border-amber-200 rounded-xl overflow-hidden">
                    <div class="px-4 py-2.5 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
                        <i class="fas fa-copy text-amber-400 text-xl"></i>
                        <p class="font-bold text-amber-900 text-xl">Duplicates Skipped</p>
                        <span class="ml-auto text-xl bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-bold">{{ $importDuplicateCount }}</span>
                    </div>
                    <ul class="divide-y divide-amber-50 overflow-y-auto" style="max-height:160px;">
                        @foreach($importDuplicates as $dup)
                        <li class="px-4 py-2.5 text-xl text-amber-700 flex items-start gap-2">
                            <i class="fas fa-triangle-exclamation text-amber-300 mt-0.5 shrink-0"></i>
                            <span>{{ $dup }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="flex gap-3">
                    <button wire:click="resetImport"
                            class="flex-1 bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-xl font-bold hover:bg-gray-50 transition active:scale-[.99]">
                        <i class="fas fa-rotate-left mr-2"></i>Import Another
                    </button>
                    <a href="{{ route('registrar.alumni') }}" wire:navigate
                       class="flex-1 text-white px-5 py-2.5 rounded-xl text-xl font-bold transition text-center hover:opacity-90 active:scale-[.99]"
                       style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        Done
                    </a>
                </div>

                @endif
            </div>
        </div>
    </div>
</d