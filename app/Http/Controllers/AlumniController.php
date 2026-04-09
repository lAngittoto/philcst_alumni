<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AlumniController extends Controller
{
    // ─────────────────────────────────────────────────────
    // Temporary Password Generator
    // Format : {student_id}_{Xx}   e.g. "00037801_Ar"
    // Xx      = first 2 letters of last name, Title-cased
    // ─────────────────────────────────────────────────────

    private function generateTempPassword(string $studentId, string $lastName): string
    {
        $part = substr(trim($lastName), 0, 2);   // first 2 chars
        $part = ucfirst(strtolower($part));       // → "Ar"
        return $studentId . '_' . $part;          // → "00037801_Ar"
    }

    // ─────────────────────────────────────────────────────
    // Import
    // ─────────────────────────────────────────────────────

    public function import(Request $request)
    {
        try {
            if (!$request->hasFile('file')) {
                return redirect()->back()->with('error', '❌ No file uploaded!');
            }

            $request->validate([
                'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            ]);

            $file    = $request->file('file');
            $ext     = strtolower($file->getClientOriginalExtension());
            $tempDir = storage_path('app/temp-imports/');
            @mkdir($tempDir, 0777, true);
            $tempPath = $tempDir . uniqid() . '.' . $ext;
            $file->move($tempDir, basename($tempPath));

            $rows = $this->parseFile($tempPath, $ext);
            @unlink($tempPath);

            if (empty($rows)) {
                return redirect()->back()->with('error', '❌ No valid data rows found in file!');
            }

            $firstRow = $rows[0] ?? [];

            if (!array_key_exists('first_name', $firstRow) || !array_key_exists('last_name', $firstRow)) {
                return redirect()->back()->with('error', '❌ Missing required columns: "first_name" and "last_name" must both be present.');
            }

            foreach (['middle_initial', 'student_id', 'course_code', 'batch'] as $required) {
                if (!array_key_exists($required, $firstRow)) {
                    return redirect()->back()->with('error', "❌ Missing required column: \"{$required}\".");
                }
            }

            $courseMap = Course::pluck('name', 'code')
                ->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])
                ->toArray();

            $existingAlumniIds = Alumni::pluck('student_id')
                ->mapWithKeys(fn($id) => [$id => true])
                ->toArray();

            $existingFullNames = Alumni::selectRaw(
                    'LOWER(TRIM(first_name)) as fn,
                     LOWER(TRIM(COALESCE(middle_initial,""))) as mi,
                     LOWER(TRIM(last_name)) as ln,
                     LOWER(TRIM(COALESCE(suffix,""))) as sf'
                )
                ->get()
                ->map(fn($a) => $a->fn . '|' . $a->mi . '|' . $a->ln . '|' . $a->sf)
                ->flip()
                ->toArray();

            $seenIdsInBatch   = [];
            $seenNamesInBatch = [];
            $imported         = 0;
            $errors           = [];
            $duplicates       = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                foreach ($row as $k => $v) {
                    $row[$k] = is_null($v) ? '' : trim((string) $v);
                }

                $firstName     = trim($row['first_name']     ?? '');
                $middleInitial = trim($row['middle_initial'] ?? '');
                $lastName      = trim($row['last_name']      ?? '');
                $suffix        = trim($row['suffix']         ?? '');
                $fullName      = trim(implode(' ', array_filter([
                    $firstName,
                    $middleInitial ?: null,
                    $lastName,
                    $suffix        ?: null,
                ])));

                $rawId = rtrim(rtrim((string) ($row['student_id'] ?? ''), '0'), '.');
                $rawId = preg_replace('/\..*$/', '', $rawId);
                $code  = strtoupper(trim($row['course_code'] ?? ''));
                $year  = (string)(int)($row['batch'] ?? 0);
                $label = "Row {$rowNum}" . ($fullName ? " ({$fullName})" : '');

                // ── Validate name fields ──────────────────────────────────
                if (!$firstName) {
                    $errors[] = "{$label}: First name is empty.";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) {
                    $errors[] = "{$label}: First name \"{$firstName}\" contains invalid characters.";
                    continue;
                }
                if (!$lastName) {
                    $errors[] = "{$label}: Last name is empty.";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) {
                    $errors[] = "{$label}: Last name \"{$lastName}\" contains invalid characters.";
                    continue;
                }
                if ($middleInitial === '') {
                    $errors[] = "{$label}: Middle initial is required.";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z]{1,2}$/', $middleInitial)) {
                    $errors[] = "{$label}: Middle initial must be 1–2 letters.";
                    continue;
                }

                // ── Full name duplicate check ─────────────────────────────
                $nameKey = strtolower($firstName) . '|' . strtolower($middleInitial) . '|' . strtolower($lastName) . '|' . strtolower($suffix);
                if (isset($existingFullNames[$nameKey]) || isset($seenNamesInBatch[$nameKey])) {
                    $duplicates[] = "{$label}: Full name \"{$fullName}\" already registered.";
                    continue;
                }

                // ── Validate student ID ───────────────────────────────────
                $rawIdClean = ltrim($rawId, '0') ?: '0';
                if (!$rawId || !preg_match('/^\d{1,8}$/', $rawIdClean) || (int) $rawIdClean === 0) {
                    $errors[] = "{$label}: Student ID \"{$rawId}\" is invalid (must be 1–8 digits).";
                    continue;
                }
                $sid = str_pad($rawIdClean, 8, '0', STR_PAD_LEFT);

                if (isset($existingAlumniIds[$sid]) || isset($seenIdsInBatch[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    continue;
                }

                // ── Validate course ───────────────────────────────────────
                if (!isset($courseMap[$code])) {
                    $errors[] = "{$label}: Course code \"{$code}\" does not exist.";
                    continue;
                }

                // ── Validate batch year ───────────────────────────────────
                $batchYear = (int) $year;
                if ($batchYear < 1000 || $batchYear > 9999) {
                    $errors[] = "{$label}: Batch \"{$year}\" must be a 4-digit year.";
                    continue;
                }

                // ── Generate temp password & create User record ───────────
                // Plain:            {student_id}_{Xx}        e.g. "00037801_Ar"
                // Stored in:        users.password           as bcrypt hash
                // Placeholder email for PENDING alumni (no real email yet):
                //                   {student_id}@pending.local
                //                   → replaced when alumni claims their account
                $tempPassword     = $this->generateTempPassword($sid, $lastName);
                $placeholderEmail = $sid . '@pending.local';

                try {
                    // 1️⃣  Create the User account with the hashed temp password
                    $user = User::create([
                        'name'     => $fullName,
                        'role'     => 'alumni',
                        'email'    => $placeholderEmail,
                        'password' => Hash::make($tempPassword),
                    ]);

                    // 2️⃣  Create the Alumni record linked to that User
                    Alumni::create([
                        'user_id'        => $user->id,
                        'first_name'     => $firstName,
                        'middle_initial' => $middleInitial ?: null,
                        'last_name'      => $lastName,
                        'suffix'         => $suffix        ?: null,
                        'student_id'     => $sid,
                        'email'          => null,           // real email set on claim
                        'course_code'    => $code,
                        'course_name'    => $courseMap[$code],
                        'batch'          => $batchYear,
                        'status'         => 'PENDING',
                        'profile_photo'  => null,
                    ]);

                    $seenIdsInBatch[$sid]      = true;
                    $seenNamesInBatch[$nameKey] = true;
                    $imported++;

                } catch (\Exception $e) {
                    Log::error("Row {$rowNum} insert error: " . $e->getMessage());
                    $errors[] = "{$label}: Insert failed — " . $e->getMessage();
                }
            }

            if ($imported > 0) {
                $msg = "✅ Successfully imported {$imported} alumni record" . ($imported > 1 ? 's' : '');
                if (!empty($errors))     $msg .= " | ⚠️ " . count($errors)     . " row(s) had errors";
                if (!empty($duplicates)) $msg .= " | 🔁 " . count($duplicates) . " duplicate(s) skipped";
                return redirect()->back()->with('success', $msg);
            }

            $msg       = "❌ No alumni imported.";
            $allIssues = array_merge($errors, $duplicates);
            if (!empty($allIssues)) {
                $shown = array_slice($allIssues, 0, 5);
                $msg  .= "\n\n" . implode("\n", $shown);
                if (count($allIssues) > 5) {
                    $msg .= "\n… and " . (count($allIssues) - 5) . " more row(s)";
                }
            }
            return redirect()->back()->with('error', $msg);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', '❌ ' . implode(', ', $e->errors()['file'] ?? ['Invalid file']));
        } catch (\Exception $e) {
            Log::error('Alumni import error: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────
    // File Parsers
    // ─────────────────────────────────────────────────────

    private function parseFile(string $filePath, string $extension): array
    {
        try {
            if (in_array($extension, ['csv', 'txt']))   return $this->parseCSV($filePath);
            if (in_array($extension, ['xlsx', 'xls'])) return $this->parseExcel($filePath);
        } catch (\Exception $e) {
            Log::error('Parse error: ' . $e->getMessage());
        }
        return [];
    }

    private function parseCSV(string $filePath): array
    {
        $rows    = [];
        $headers = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($line = fgetcsv($handle)) !== false) {
                if (empty($line) || (count($line) === 1 && empty($line[0]))) continue;

                if (empty($headers)) {
                    $headers = array_map(fn($h) => strtolower(trim($h)), $line);
                } else {
                    if (count($line) >= count($headers)) {
                        $row = [];
                        foreach ($headers as $i => $header) {
                            $row[$header] = ltrim($line[$i] ?? '', "'");
                        }
                        $rows[] = $row;
                    }
                }
            }
            fclose($handle);
        }

        return $rows;
    }

    private function parseExcel(string $filePath): array
    {
        $rows    = [];
        $headers = [];

        try {
            if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                Log::warning('PhpSpreadsheet not installed');
                return [];
            }

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = min($sheet->getHighestRow(), 10000);
            $maxCol      = $sheet->getHighestColumn();
            $rowIndex    = 0;

            for ($r = 1; $r <= $maxRow; $r++) {
                $rowData = [];
                $isEmpty = true;

                for ($c = 'A'; $c <= $maxCol; $c++) {
                    $val = $sheet->getCell($c . $r)->getFormattedValue();
                    if ($val === null || $val === '') {
                        $val = $sheet->getCell($c . $r)->getValue();
                    }
                    $val       = is_null($val) ? '' : (string) $val;
                    $rowData[] = $val;
                    if ($val !== '') $isEmpty = false;
                }

                if ($isEmpty) continue;
                $rowIndex++;

                if ($rowIndex === 1) {
                    $headers = array_map(fn($h) => strtolower(trim($h)), $rowData);
                } else {
                    $assoc = [];
                    foreach ($headers as $i => $header) {
                        $assoc[$header] = ltrim($rowData[$i] ?? '', "'");
                    }
                    if (!empty(array_filter($assoc))) $rows[] = $assoc;
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

        } catch (\Exception $e) {
            Log::error('Excel parse error: ' . $e->getMessage());
        }

        return $rows;
    }
}