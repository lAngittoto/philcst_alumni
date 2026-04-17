<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithFileUploads;

    public        $importFile           = null;
    public bool   $importingFile        = false;
    public string $importStatus         = '';
    public string $importStep           = 'upload';
    public string $importMode           = 'new';

    public int    $importProgress       = 0;
    public int    $importTotal          = 0;
    public int    $importSuccessCount   = 0;
    public int    $importFailCount      = 0;
    public int    $importDuplicateCount = 0;

    public array  $importErrors         = [];
    public array  $importDuplicates     = [];

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

    private function sanitizeEmail(string $val): string
    {
        $val = filter_var(trim($val), FILTER_SANITIZE_EMAIL);
        return filter_var($val, FILTER_VALIDATE_EMAIL) ? $val : '';
    }

    private function sanitizeDate(string $val): ?string
    {
        if (!trim($val)) return null;
        try {
            return \Carbon\Carbon::parse($val)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
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

    public function resetImport(): void
    {
        $this->importFile           = null;
        $this->importingFile        = false;
        $this->importStatus         = '';
        $this->importStep           = 'upload';
        $this->importMode           = 'new';
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

            // ── Required columns — note: Excel uses "middle_name", stored in DB as "middle_initial"
            $required = ['first_name', 'last_name', 'middle_name', 'student_id', 'course_code', 'batch'];
            foreach ($required as $col) {
                if (!in_array($col, $header, true))
                    throw new \Exception("Missing required column: \"{$col}\".");
            }

            $this->importTotal = count($rows) - 1;
            $isComplete        = ($this->importMode === 'complete');

            $courseMap = Course::pluck('name', 'code')
                               ->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])
                               ->toArray();

            $existingIds = Alumni::pluck('student_id')
                                 ->mapWithKeys(fn($id) => [$id => true])
                                 ->toArray();

            $existingNames = Alumni::selectRaw(
                    'LOWER(TRIM(first_name)) as fn,
                     LOWER(TRIM(COALESCE(middle_initial,""))) as mi,
                     LOWER(TRIM(last_name)) as ln,
                     LOWER(TRIM(COALESCE(suffix,""))) as sf'
                )->get()
                 ->map(fn($a) => $a->fn . '|' . $a->mi . '|' . $a->ln . '|' . $a->sf)
                 ->flip()->toArray();

            $jobs             = [];
            $seenIds          = [];
            $seenNames        = [];
            $validationErrors = [];
            $duplicates       = [];
            $maxErrors        = 200;

            for ($i = 1; $i < count($rows); $i++) {
                $this->importProgress = $i;

                if (count(array_filter($rows[$i], fn($v) => trim((string) $v) !== '')) === 0) continue;
                if (count($rows[$i]) < count($header)) continue;

                $row       = array_combine($header, array_slice($rows[$i], 0, count($header)));
                $firstName = trim($row['first_name']  ?? '');

                // ── "middle_name" is the Excel column; stored in DB as "middle_initial"
                $mid       = trim($row['middle_name'] ?? '');

                $lastName  = trim($row['last_name']   ?? '');
                $suffix    = trim($row['suffix']      ?? '');
                $fullName  = $this->buildFullName($firstName, $mid, $lastName, $suffix);
                $label     = 'Row ' . ($i + 1) . ($fullName ? " ({$fullName})" : '');

                // ── Name validation
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
                if (!preg_match('/^[a-zA-Z]+$/', $mid)) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name must contain letters only.");
                    continue;
                }
                if (strlen($mid) < 2) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Middle name must be a full word (e.g. Santos, not S).");
                    continue;
                }

                // ── Duplicate name check
                $nameKey = strtolower($firstName) . '|' . strtolower($mid) . '|' . strtolower($lastName) . '|' . strtolower($suffix);
                if (isset($existingNames[$nameKey]) || isset($seenNames[$nameKey])) {
                    $duplicates[] = "{$label}: Full name \"{$fullName}\" already registered.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Student ID validation
                $rawId      = preg_replace('/\..*$/', '', rtrim(rtrim((string) ($row['student_id'] ?? ''), '0'), '.'));
                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int) $rawIdClean === 0) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Student ID \"{$rawId}\" is invalid (1–8 digits required).");
                    continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);
                if (isset($existingIds[$sid]) || isset($seenIds[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    $this->importDuplicateCount++;
                    continue;
                }

                // ── Course & batch validation
                $code = strtoupper(trim($row['course_code'] ?? ''));
                if (!isset($courseMap[$code])) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Course code \"{$code}\" does not exist.");
                    continue;
                }

                $batchYear = (int) ($row['batch'] ?? 0);
                if ($batchYear < 1000 || $batchYear > 9999) {
                    $this->appendImportError($validationErrors, $maxErrors, "{$label}: Batch \"{$batchYear}\" must be a 4-digit year.");
                    continue;
                }

                // ── Extra fields (complete mode)
                $extra = [];
                if ($isComplete) {
                    $email = $this->sanitizeEmail($row['email'] ?? '');
                    if (!empty($email)) {
                        if (Alumni::where('email', $email)->exists() || User::where('email', $email)->exists()) {
                            $duplicates[] = "{$label}: Email \"{$email}\" already registered.";
                            $this->importDuplicateCount++;
                            continue;
                        }
                    }
                    $extra = [
                        'email'                => $email,
                        'gender'               => $this->sanitizeText($row['gender']               ?? ''),
                        'date_of_birth'        => $this->sanitizeDate($row['date_of_birth']        ?? ''),
                        'place_of_birth'       => $this->sanitizeText($row['place_of_birth']       ?? ''),
                        'citizenship'          => $this->sanitizeText($row['citizenship']           ?? ''),
                        'civil_status'         => $this->sanitizeText($row['civil_status']          ?? ''),
                        'blood_type'           => $this->sanitizeText($row['blood_type']            ?? ''),
                        'contact_number'       => $this->sanitizeText($row['contact_number']        ?? ''),
                        'father_name'          => $this->sanitizeText($row['father_name']           ?? ''),
                        'mother_name'          => $this->sanitizeText($row['mother_name']           ?? ''),
                        'spouse_name'          => $this->sanitizeText($row['spouse_name']           ?? ''),
                        'address_no'           => $this->sanitizeText($row['address_no']            ?? ''),
                        'address_street'       => $this->sanitizeText($row['address_street']        ?? ''),
                        'address_barangay'     => $this->sanitizeText($row['address_barangay']      ?? ''),
                        'address_municipality' => $this->sanitizeText($row['address_municipality']  ?? ''),
                        'address_province'     => $this->sanitizeText($row['address_province']      ?? ''),
                        'address_zip_code'     => $this->sanitizeText($row['address_zip_code']      ?? ''),
                    ];
                }

                $jobs[] = compact('fullName', 'firstName', 'mid', 'lastName', 'suffix', 'sid', 'code', 'batchYear', 'extra')
                        + ['courseName' => $courseMap[$code]];

                $seenIds[$sid]       = true;
                $seenNames[$nameKey] = true;
            }

            $this->importErrors     = $validationErrors;
            $this->importDuplicates = $duplicates;
            $this->importFailCount  = count($validationErrors);

            if (empty($jobs)) {
                $this->importStatus  = 'Done';
                $this->importStep    = 'done';
                $this->importingFile = false;
                $this->importFile    = null;
                return;
            }

            $this->importStatus = 'Importing…';

            foreach ($jobs as $job) {
                try {
                    $hasEmail   = !empty($job['extra']['email'] ?? '');
                    $loginEmail = $hasEmail ? $job['extra']['email'] : ($job['sid'] . '@pending.local');
                    $tmpPass    = $this->generateTempPassword($job['sid'], $job['lastName']);

                    $user = User::create([
                        'name'     => $job['fullName'],
                        'role'     => 'alumni',
                        'email'    => $loginEmail,
                        'password' => Hash::make($tmpPass),
                    ]);

                    // "mid" from Excel's "middle_name" column → stored in DB column "middle_initial"
                    $data = [
                        'user_id'             => $user->id,
                        'first_name'          => $job['firstName'],
                        'middle_initial'      => $job['mid']    ?: null,   // DB column = middle_initial
                        'last_name'           => $job['lastName'],
                        'suffix'              => $job['suffix'] ?: null,
                        'student_id'          => $job['sid'],
                        'email'               => $hasEmail ? $job['extra']['email'] : null,
                        'course_code'         => $job['code'],
                        'course_name'         => $job['courseName'],
                        'batch'               => $job['batchYear'],
                        'status'              => 'VERIFIED',
                        'password_changed_at' => now(),
                        'profile_photo'       => null,
                        'profile_completed'   => $isComplete ? 1 : 0,
                    ];

                    if ($isComplete) {
                        $e    = $job['extra'];
                        $data = array_merge($data, [
                            'gender'               => $e['gender']               ?: null,
                            'date_of_birth'        => $e['date_of_birth']        ?: null,
                            'place_of_birth'       => $e['place_of_birth']       ?: null,
                            'citizenship'          => $e['citizenship']           ?: null,
                            'civil_status'         => $e['civil_status']          ?: null,
                            'blood_type'           => $e['blood_type']            ?: null,
                            'contact_number'       => $e['contact_number']        ?: null,
                            'father_name'          => $e['father_name']           ?: null,
                            'mother_name'          => $e['mother_name']           ?: null,
                            'spouse_name'          => $e['spouse_name']           ?: null,
                            'address_no'           => $e['address_no']            ?: null,
                            'address_street'       => $e['address_street']        ?: null,
                            'address_barangay'     => $e['address_barangay']      ?: null,
                            'address_municipality' => $e['address_municipality']  ?: null,
                            'address_province'     => $e['address_province']      ?: null,
                            'address_zip_code'     => $e['address_zip_code']      ?: null,
                        ]);
                    }

                    Alumni::create($data);
                    $this->importSuccessCount++;

                } catch (\Exception $e) {
                    Log::error('Import row error: ' . $e->getMessage());
                    $this->appendImportError($this->importErrors, 200, "Student ID {$job['sid']}: " . $e->getMessage());
                }
            }

            $this->importStatus  = 'Done';
            $this->importStep    = 'done';
            $this->importingFile = false;
            $this->importFile    = null;

        } catch (\Exception $e) {
            Log::error('Import error: ' . $e->getMessage());
            $this->importStatus  = 'Error: ' . $e->getMessage();
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
            <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 leading-tight">Import Alumni</h1>
        </div>
    </div>

    <div class="max-w-2xl w-full mx-auto">
        <div class="bg-white rounded-2xl shadow-sm border border-purple-100 overflow-hidden">

            {{-- Card Header bar --}}
            <div class="px-5 py-3.5 border-b border-gray-100 bg-gradient-to-r from-[#f9f5ff] to-white">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">
                    @if($importStep === 'upload')        File Upload
                    @elseif($importStep === 'processing') Processing…
                    @elseif($importStep === 'blocked')    Import Failed
                    @else                                 Import Summary
                    @endif
                </p>
            </div>

            <div class="p-5 sm:p-6 space-y-5">

                {{-- ── UPLOAD STEP ──────────────────────────────────── --}}
                @if($importStep === 'upload')

                {{-- Mode selector --}}
                <div>
                    <p class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-3">Import Mode</p>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['new',      'user-plus', 'New Alumni',          'Basic info only'],
                            ['complete', 'id-card',   'Complete Alumni Info', 'Full personal details'],
                        ] as [$mode, $icon, $label, $sub])
                        <button type="button" wire:click="$set('importMode','{{ $mode }}')"
                                class="flex flex-col items-center gap-2 p-4 rounded-xl border-2 text-center transition active:scale-[.98]
                                       {{ $importMode === $mode
                                           ? 'border-[#7a3f91] bg-[#f5eef9]'
                                           : 'border-gray-200 hover:border-purple-200 bg-white' }}">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center {{ $importMode === $mode ? 'bg-[#7a3f91]' : 'bg-gray-100' }}">
                                <i class="fas fa-{{ $icon }} text-sm {{ $importMode === $mode ? 'text-white' : 'text-gray-400' }}"></i>
                            </div>
                            <div>
                                <p class="font-bold text-sm {{ $importMode === $mode ? 'text-[#7a3f91]' : 'text-gray-700' }}">{{ $label }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $sub }}</p>
                            </div>
                            @if($importMode === $mode)
                                <i class="fas fa-circle-check text-[#7a3f91] text-xs"></i>
                            @endif
                        </button>
                        @endforeach
                    </div>
                </div>

                {{-- Column guide --}}
                @if($importMode === 'new')
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-200">
                    <p class="font-bold text-sm text-blue-900 mb-2">
                        <i class="fas fa-circle-info mr-1.5"></i>Required Columns
                    </p>
                    <div class="flex flex-wrap gap-1 mb-2">
                        @foreach(['first_name','last_name','middle_name','student_id','course_code','batch'] as $col)
                            <code class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs font-mono">{{ $col }}</code>
                        @endforeach
                    </div>
                    <p class="text-xs text-blue-700">
                        Optional: <code class="bg-blue-100 px-1 rounded font-mono">suffix</code>
                    </p>
                </div>
                @else
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200">
                    <p class="font-bold text-sm text-emerald-900 mb-2">
                        <i class="fas fa-circle-info mr-1.5"></i>Columns
                    </p>
                    <p class="text-xs text-emerald-700 font-semibold mb-1">Required:</p>
                    <div class="flex flex-wrap gap-1 mb-2">
                        @foreach(['first_name','last_name','middle_name','student_id','course_code','batch'] as $col)
                            <code class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-mono">{{ $col }}</code>
                        @endforeach
                    </div>
                    <p class="text-xs text-emerald-700 font-semibold mb-1">Optional / Extra:</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach(['suffix','email','gender','date_of_birth','place_of_birth','citizenship','civil_status','blood_type','contact_number','father_name','mother_name','spouse_name','address_no','address_street','address_barangay','address_municipality','address_province','address_zip_code'] as $col)
                            <code class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-mono">{{ $col }}</code>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- File picker --}}
                <div>
                    <p class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">Upload File</p>
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#faf5ff] transition"
                         @click="document.getElementById('importFileInput').click()">
                        @if($importFile)
                            <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-emerald-100">
                                <i class="fas fa-file-circle-check text-2xl text-emerald-500"></i>
                            </div>
                            <p class="text-emerald-700 font-bold text-sm">{{ $importFile->getClientOriginalName() }}</p>
                            <p class="text-gray-400 text-xs mt-0.5">Click to change file</p>
                        @else
                            <div class="w-12 h-12 rounded-xl mx-auto mb-3 flex items-center justify-center bg-gray-100">
                                <i class="fas fa-file-arrow-up text-2xl text-gray-300"></i>
                            </div>
                            <p class="text-gray-700 font-semibold text-sm">Click to choose file</p>
                            <p class="text-gray-400 text-xs mt-0.5">CSV or Excel (.xlsx, .xls)</p>
                        @endif
                        <input type="file" id="importFileInput" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
                    </div>
                    <div wire:loading wire:target="importFile" class="mt-2 text-xs text-[#7a3f91] flex items-center gap-1.5">
                        <i class="fas fa-spinner animate-spin"></i> Reading file…
                    </div>
                </div>

                {{-- Buttons --}}
                <div class="flex gap-3">
                    <a href="{{ route('registrar.alumni') }}" wire:navigate
                       class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition text-center active:scale-[.99]">
                        Cancel
                    </a>
                    <button type="button"
                            wire:click="processImport"
                            @if(!$importFile) disabled @endif
                            wire:loading.attr="disabled" wire:target="processImport"
                            class="flex-1 px-5 py-2.5 rounded-xl text-sm font-bold text-white transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2 hover:opacity-90 active:scale-[.99]"
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
                    <p class="font-bold text-gray-800 text-base mb-1">
                        Processing {{ $importProgress }} / {{ $importTotal }}
                    </p>
                    <p class="text-xs text-gray-400 mb-5">{{ $importStatus }}</p>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                        @php $pct = $importTotal > 0 ? round(($importProgress / $importTotal) * 100) : 0; @endphp
                        <div class="h-full rounded-full transition-all duration-300"
                             style="width:{{ $pct }}%; background:linear-gradient(90deg,#7a3f91,#9b59b6);"></div>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">{{ $pct }}% complete</p>
                </div>

                {{-- ── BLOCKED STEP ─────────────────────────────────── --}}
                @elseif($importStep === 'blocked')

                <div class="flex items-start gap-4 p-4 rounded-xl bg-red-50 border border-red-200">
                    <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fas fa-xmark text-red-600"></i>
                    </div>
                    <div>
                        <p class="font-bold text-red-900">Import Failed</p>
                        <p class="text-sm text-red-700 mt-0.5">{{ $importStatus }}</p>
                    </div>
                </div>
                <button wire:click="resetImport"
                        class="w-full bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition active:scale-[.99]">
                    <i class="fas fa-rotate-left mr-2"></i>Try Again
                </button>

                {{-- ── DONE STEP ────────────────────────────────────── --}}
                @elseif($importStep === 'done')

                @php
                    $hasNew    = $importSuccessCount   > 0;
                    $hasErrors = $importFailCount       > 0;
                    $hasDups   = $importDuplicateCount  > 0;
                @endphp

                {{-- Result banner --}}
                <div class="flex items-start gap-4 p-4 rounded-xl border
                            {{ $hasNew ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }}">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0
                                {{ $hasNew ? 'bg-emerald-100' : 'bg-amber-100' }}">
                        <i class="{{ $hasNew ? 'fas fa-circle-check text-emerald-600' : 'fas fa-triangle-exclamation text-amber-600' }}"></i>
                    </div>
                    <div>
                        @if($hasNew)
                            <p class="font-bold text-emerald-900">Import Complete</p>
                            <p class="text-xs text-emerald-700 mt-0.5">
                                <strong>{{ $importSuccessCount }}</strong> record(s) imported as
                                <strong>{{ $importMode === 'complete' ? 'Complete Alumni' : 'New Alumni' }}</strong> — all <strong>VERIFIED</strong>
                                @if($hasDups) · {{ $importDuplicateCount }} duplicate(s) skipped @endif
                                @if($hasErrors) · {{ $importFailCount }} error(s) @endif
                            </p>
                        @else
                            <p class="font-bold text-amber-900">Nothing Imported</p>
                            <p class="text-xs text-amber-700 mt-0.5">All records were duplicates or had errors.</p>
                        @endif
                    </div>
                </div>

                {{-- Stats grid --}}
                <div class="grid grid-cols-4 gap-2">
                    @foreach([
                        ['#f9fafb','#e5e7eb','#6b7280', $importTotal,          'Total'],
                        ['#f0fdf4','#bbf7d0','#15803d', $importSuccessCount,    'Imported'],
                        ['#fffbeb','#fde68a','#92400e', $importDuplicateCount,  'Duplicate'],
                        ['#fef2f2','#fecaca','#991b1b', $importFailCount,       'Errors'],
                    ] as [$bg, $border, $clr, $num, $lbl])
                    <div class="rounded-xl p-3 text-center" style="background:{{ $bg }};border:1px solid {{ $border }};">
                        <p class="text-xl font-extrabold" style="color:{{ $clr }};">{{ $num }}</p>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mt-0.5">{{ $lbl }}</p>
                    </div>
                    @endforeach
                </div>

                {{-- Errors list --}}
                @if($hasErrors)
                <div class="border border-red-200 rounded-xl overflow-hidden">
                    <div class="px-4 py-2.5 bg-red-50 border-b border-red-100 flex items-center gap-2">
                        <i class="fas fa-circle-xmark text-red-400 text-xs"></i>
                        <p class="font-bold text-red-900 text-xs">Validation Errors</p>
                        <span class="ml-auto text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">{{ count($importErrors) }}</span>
                    </div>
                    <ul class="divide-y divide-red-50 overflow-y-auto" style="max-height:200px;">
                        @foreach($importErrors as $err)
                        <li class="px-4 py-2.5 text-xs text-red-700 flex items-start gap-2">
                            <i class="fas fa-circle-xmark text-red-300 mt-0.5 shrink-0"></i>
                            <span>{{ $err }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Duplicates list --}}
                @if($hasDups && count($importDuplicates) > 0)
                <div class="border border-amber-200 rounded-xl overflow-hidden">
                    <div class="px-4 py-2.5 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
                        <i class="fas fa-copy text-amber-400 text-xs"></i>
                        <p class="font-bold text-amber-900 text-xs">Duplicates Skipped</p>
                        <span class="ml-auto text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-bold">{{ $importDuplicateCount }}</span>
                    </div>
                    <ul class="divide-y divide-amber-50 overflow-y-auto" style="max-height:160px;">
                        @foreach($importDuplicates as $dup)
                        <li class="px-4 py-2.5 text-xs text-amber-700 flex items-start gap-2">
                            <i class="fas fa-triangle-exclamation text-amber-300 mt-0.5 shrink-0"></i>
                            <span>{{ $dup }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Action buttons --}}
                <div class="flex gap-3">
                    <button wire:click="resetImport"
                            class="flex-1 bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition active:scale-[.99]">
                        <i class="fas fa-rotate-left mr-2"></i>Import Another
                    </button>
                    <a href="{{ route('registrar.alumni') }}" wire:navigate
                       class="flex-1 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition text-center hover:opacity-90 active:scale-[.99]"
                       style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                        Done
                    </a>
                </div>

                @endif
            </div>
        </div>
    </div>
</div>