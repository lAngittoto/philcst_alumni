<?php
namespace App\Http\Controllers;

use App\Mail\AlumniRegistered;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AlumniController extends Controller
{
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

            $firstRow     = $rows[0] ?? [];
            $hasFirstLast = array_key_exists('first_name', $firstRow) && array_key_exists('last_name', $firstRow);
            $hasName      = array_key_exists('name', $firstRow);

            if (!$hasFirstLast && !$hasName) {
                return redirect()->back()->with('error', '❌ Missing name column(s). Use "first_name"+"last_name" or a single "name" column.');
            }

            $courseMap = Course::pluck('name', 'code')
                ->mapWithKeys(fn($n, $c) => [strtoupper($c) => $n])
                ->toArray();

            $existingAlumniEmails = Alumni::pluck('email')
                ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            $existingAlumniIds = Alumni::pluck('student_id')
                ->mapWithKeys(fn($id) => [$id => true])->toArray();

            $existingUserEmails = User::pluck('email')
                ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();

            $seenEmailsInBatch = [];
            $seenIdsInBatch    = [];
            $imported          = 0;
            $errors            = [];
            $duplicates        = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                foreach ($row as $k => $v) {
                    $row[$k] = is_null($v) ? '' : trim((string) $v);
                }

                if ($hasFirstLast) {
                    $firstName     = trim($row['first_name']     ?? '');
                    $middleInitial = trim($row['middle_initial'] ?? '');
                    $lastName      = trim($row['last_name']      ?? '');
                    $suffix        = trim($row['suffix']         ?? '');
                    $fullName      = trim(implode(' ', array_filter([$firstName, $middleInitial ?: null, $lastName, $suffix ?: null])));
                } else {
                    $firstName     = trim($row['name'] ?? '');
                    $middleInitial = '';
                    $lastName      = '';
                    $suffix        = '';
                    $fullName      = $firstName;
                }

                $email = strtolower(trim($row['email']       ?? ''));
                $rawId = trim($row['student_id']             ?? '');
                $code  = strtoupper(trim($row['course_code'] ?? ''));
                $year  = trim($row['year']                   ?? '');
                $label = "Row {$rowNum}" . ($fullName ? " ({$fullName})" : '');

                if (!$firstName) { $errors[] = "{$label}: First name is empty."; continue; }
                if ($hasFirstLast && !$lastName) { $errors[] = "{$label}: Last name is empty."; continue; }
                if (!$email || !$rawId || !$code || !$year) {
                    $errors[] = "{$label}: Missing required fields (email, student_id, course_code, year).";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z\s\-\.\']+$/', $firstName)) {
                    $errors[] = "{$label}: First name \"{$firstName}\" contains invalid characters.";
                    continue;
                }
                if ($hasFirstLast && $lastName && !preg_match('/^[a-zA-Z\s\-\.\']+$/', $lastName)) {
                    $errors[] = "{$label}: Last name \"{$lastName}\" contains invalid characters.";
                    continue;
                }
                if ($middleInitial !== '' && !preg_match('/^[a-zA-Z]{1,2}$/', $middleInitial)) {
                    $errors[] = "{$label}: Middle initial \"{$middleInitial}\" must be 1–2 letters.";
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "{$label}: Email \"{$email}\" is not valid.";
                    continue;
                }
                if (isset($existingAlumniEmails[$email]) || isset($seenEmailsInBatch[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" already exists.";
                    continue;
                }
                if (isset($existingUserEmails[$email])) {
                    $duplicates[] = "{$label}: Email \"{$email}\" already used by another account.";
                    continue;
                }

                $rawId = preg_replace('/\D/', '', $rawId);
                if ($rawId === '' || strlen($rawId) > 8) {
                    $errors[] = "{$label}: Student ID must be 1–8 digits.";
                    continue;
                }
                $sid = str_pad($rawId, 8, '0', STR_PAD_LEFT);

                if (isset($existingAlumniIds[$sid]) || isset($seenIdsInBatch[$sid])) {
                    $duplicates[] = "{$label}: Student ID \"{$sid}\" already exists.";
                    continue;
                }
                if (!isset($courseMap[$code])) {
                    $errors[] = "{$label}: Course code \"{$code}\" does not exist in the system.";
                    continue;
                }

                $batchYear = (int) $year;
                if (!preg_match('/^\d{4}$/', $year) || $batchYear < 1000) {
                    $errors[] = "{$label}: Year \"{$year}\" is invalid (must be 4-digit year e.g. 2024).";
                    continue;
                }

                $status = 'VERIFIED';
                if (!empty($row['status'] ?? '')) {
                    $s = strtoupper($row['status']);
                    if (in_array($s, ['VERIFIED', 'PENDING', 'REJECTED'])) $status = $s;
                }

                try {
                    $tempPassword = Str::random(10);

                    // 1. Create User FIRST
                    $user = User::create([
                        'name'     => $fullName,
                        'email'    => $email,
                        'password' => Hash::make($tempPassword),
                        'role'     => 'alumni',
                    ]);

                    // 2. Create Alumni linked to User
                    $alumni = Alumni::create([
                        'user_id'        => $user->id,
                        'first_name'     => $firstName,
                        'middle_initial' => $middleInitial ?: null,
                        'last_name'      => $lastName      ?: null,
                        'suffix'         => $suffix        ?: null,
                        'student_id'     => $sid,
                        'email'          => $email,
                        'course_code'    => $code,
                        'course_name'    => $courseMap[$code],
                        'batch'          => $batchYear,
                        'status'         => $status,
                        'profile_photo'  => null,
                    ]);

                    // 3. Send welcome email ← THIS WAS MISSING
                    try {
                        Mail::to($email)->send(new AlumniRegistered($alumni, $tempPassword));
                    } catch (\Exception $mailError) {
                        Log::warning("Email failed for {$email}: " . $mailError->getMessage());
                    }

                    $seenEmailsInBatch[$email] = true;
                    $seenIdsInBatch[$sid]      = true;
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
                if (count($allIssues) > 5) $msg .= "\n… and " . (count($allIssues) - 5) . " more row(s)";
            }
            return redirect()->back()->with('error', $msg);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', '❌ ' . implode(', ', $e->errors()['file'] ?? ['Invalid file']));
        } catch (\Exception $e) {
            Log::error('Alumni import error: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ ' . $e->getMessage());
        }
    }

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
                    if ($val === null || $val === '') $val = $sheet->getCell($c . $r)->getValue();
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