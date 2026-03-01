<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AlumniController extends Controller
{
    /**
     * Import CSV/Excel file for Alumni
     */
    public function import(Request $request)
    {
        try {
            if (!$request->hasFile('file')) {
                return redirect()->back()->with('error', '❌ No file uploaded!');
            }

            $request->validate([
                'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            ]);

            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            $tempPath = storage_path('app/temp-imports/' . uniqid() . '.' . $ext);

            @mkdir(dirname($tempPath), 0777, true);
            $file->move(dirname($tempPath), basename($tempPath));

            $rows = $this->parseFile($tempPath, $ext);

            if (empty($rows)) {
                @unlink($tempPath);
                return redirect()->back()->with('error', '❌ No valid data rows found in file!');
            }

            $imported = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                foreach ($row as $key => $val) {
                    $row[$key] = is_null($val) ? '' : trim((string) $val);
                }

                if (
                    empty($row['name'] ?? '') ||
                    empty($row['student_id'] ?? '') ||
                    empty($row['email'] ?? '') ||
                    empty($row['course_code'] ?? '') ||
                    empty($row['year'] ?? '')
                ) {
                    $errors[] = "Row {$rowNum}: Missing required fields";
                    continue;
                }

                $rawId = preg_replace('/\D/', '', $row['student_id']);

                if ($rawId === '' || strlen($rawId) > 8) {
                    $errors[] = "Row {$rowNum}: Student ID must be 1–8 digits (got: {$row['student_id']})";
                    continue;
                }

                $sid = str_pad($rawId, 8, '0', STR_PAD_LEFT);

                if (Alumni::where('student_id', $sid)->exists()) {
                    $errors[] = "Row {$rowNum}: Student ID {$sid} already exists";
                    continue;
                }
                if (Alumni::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email {$row['email']} already registered";
                    continue;
                }
                if (User::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email {$row['email']} already in system";
                    continue;
                }

                $courseCode = strtoupper($row['course_code']);
                $course = Course::where('code', $courseCode)->first();
                if (!$course) {
                    $errors[] = "Row {$rowNum}: Course {$courseCode} not found";
                    continue;
                }

                $year = (int) $row['year'];
                if ($year < 2000 || $year > (int) date('Y')) {
                    $errors[] = "Row {$rowNum}: Invalid year {$year}";
                    continue;
                }

                if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row {$rowNum}: Invalid email format";
                    continue;
                }

                $status = 'VERIFIED';
                if (!empty($row['status'] ?? '')) {
                    $s = strtoupper($row['status']);
                    if (in_array($s, ['VERIFIED', 'PENDING', 'REJECTED'])) {
                        $status = $s;
                    }
                }

                try {
                    Alumni::create([
                        'student_id'    => $sid,
                        'name'          => $row['name'],
                        'email'         => $row['email'],
                        'course_code'   => $courseCode,
                        'course_name'   => $course->name,
                        'batch'         => $year,
                        'status'        => $status,
                        'profile_photo' => null, // NULL - will show default
                    ]);

                    $tempPassword = Str::random(10);
                    User::create([
                        'name'     => $row['name'],
                        'email'    => $row['email'],
                        'password' => Hash::make($tempPassword),
                        'role'     => 'alumni',
                    ]);

                    $imported++;
                } catch (\Exception $e) {
                    Log::error("Row {$rowNum} error: " . $e->getMessage());
                    $errors[] = "Row {$rowNum}: Creation failed";
                }
            }

            @unlink($tempPath);

            if ($imported > 0) {
                $msg = "✅ Successfully imported {$imported} alumni record" . ($imported > 1 ? 's' : '');
                if (!empty($errors)) {
                    $msg .= " | ⚠️ " . count($errors) . " row(s) had issues";
                }
                return redirect()->back()->with('success', $msg);
            } else {
                $msg = "❌ No alumni imported.";
                if (!empty($errors)) {
                    $shown = array_slice($errors, 0, 5);
                    $msg .= "\n\n" . implode("\n", $shown);
                    if (count($errors) > 5) {
                        $msg .= "\n… and " . (count($errors) - 5) . " more row(s)";
                    }
                }
                return redirect()->back()->with('error', $msg);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', '❌ ' . implode(', ', $e->errors()['file'] ?? ['Invalid file']));
        } catch (\Exception $e) {
            Log::error('Alumni import error: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ ' . $e->getMessage());
        }
    }

    // ================================================================
    // PARSER METHODS
    // ================================================================

    private function parseFile(string $filePath, string $extension): array
    {
        try {
            if (in_array($extension, ['csv', 'txt'])) {
                return $this->parseCSV($filePath);
            } elseif (in_array($extension, ['xlsx', 'xls'])) {
                return $this->parseExcel($filePath);
            }
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
                if (empty($line) || (count($line) === 1 && empty($line[0]))) {
                    continue;
                }

                if (empty($headers)) {
                    $headers = array_map(fn($h) => strtolower(trim($h)), $line);
                } else {
                    if (count($line) >= count($headers)) {
                        $row = [];
                        foreach ($headers as $i => $header) {
                            $val = $line[$i] ?? '';
                            $row[$header] = ltrim($val, "'");
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
            $sheet = $spreadsheet->getActiveSheet();

            $rowIndex = 0;
            $maxRows = min($sheet->getHighestRow(), 10000);

            for ($row = 1; $row <= $maxRows; $row++) {
                $rowData = [];
                $isEmpty = true;

                for ($col = 'A'; $col <= 'F'; $col++) {
                    $cell = $sheet->getCell($col . $row);
                    $value = $cell->getFormattedValue();
                    
                    if ($value === null || $value === '') {
                        $value = $cell->getValue();
                    }
                    
                    $rowData[] = is_null($value) ? '' : (string) $value;
                    
                    if (!empty($value)) {
                        $isEmpty = false;
                    }
                }

                if ($isEmpty) {
                    continue;
                }

                $rowIndex++;

                if ($rowIndex === 1) {
                    $headers = array_map(fn($h) => strtolower(trim($h)), $rowData);
                } else {
                    $row_assoc = [];
                    foreach ($headers as $i => $header) {
                        $val = $rowData[$i] ?? '';
                        $row_assoc[$header] = ltrim($val, "'");
                    }
                    if (!empty(array_filter($row_assoc))) {
                        $rows[] = $row_assoc;
                    }
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