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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AlumniController extends Controller
{
    /**
     * Get or create default photo path
     */
    private function getDefaultPhotoPath(): string
    {
        $dest = 'alumni-photos/default.png';

        if (Storage::disk('public')->exists($dest)) {
            return $dest;
        }

        // Try to find existing default photo
        $candidates = [
            public_path('storage/alumni-photos/default.png'),
            storage_path('app/public/alumni-photos/default.png'),
            base_path('public/storage/alumni-photos/default.png'),
        ];

        foreach ($candidates as $src) {
            if (file_exists($src) && is_readable($src)) {
                $bytes = file_get_contents($src);
                if ($bytes !== false) {
                    Storage::disk('public')->makeDirectory('alumni-photos');
                    Storage::disk('public')->put($dest, $bytes);
                    return $dest;
                }
            }
        }

        // Generate fallback image if GD is available
        if (function_exists('imagecreatetruecolor')) {
            $size = 128;
            $img = imagecreatetruecolor($size, $size);
            $bg = imagecolorallocate($img, 122, 63, 145);
            $fg = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $bg);
            imagefilledellipse($img, 64, 42, 42, 42, $fg);
            imagefilledellipse($img, 64, 106, 70, 56, $fg);
            ob_start();
            imagepng($img);
            $bytes = ob_get_clean();
            imagedestroy($img);
        } else {
            $bytes = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
            );
        }

        Storage::disk('public')->makeDirectory('alumni-photos');
        Storage::disk('public')->put($dest, $bytes);
        return $dest;
    }

    /**
     * Import CSV/Excel file
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        try {
            $file = $request->file('file');
            $path = $file->store('temp-imports');
            $fullPath = storage_path('app/' . $path);
            $rows = $this->parseFile($fullPath, $file->getClientOriginalExtension());

            $imported = 0;
            $errors = [];
            $default = $this->getDefaultPhotoPath();

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;
                $row = array_map('trim', $row);

                // Validate required fields
                if (empty($row['student_id']) || empty($row['name']) || empty($row['email']) ||
                    empty($row['course_code']) || empty($row['batch'])) {
                    $errors[] = "Row {$rowNum}: Missing required fields (student_id, name, email, course_code, batch).";
                    continue;
                }

                // Validate student ID format
                if (!ctype_digit($row['student_id']) || strlen($row['student_id']) !== 8) {
                    $errors[] = "Row {$rowNum}: Student ID must be exactly 8 digits.";
                    continue;
                }

                // Check for duplicates
                if (Alumni::where('student_id', $row['student_id'])->exists()) {
                    $errors[] = "Row {$rowNum}: Student ID '{$row['student_id']}' already exists.";
                    continue;
                }
                if (Alumni::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email '{$row['email']}' already exists.";
                    continue;
                }
                if (User::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email '{$row['email']}' already exists in users.";
                    continue;
                }

                // Validate course exists
                $course = Course::where('code', strtoupper($row['course_code']))->first();
                if (!$course) {
                    $errors[] = "Row {$rowNum}: Course '{$row['course_code']}' not found.";
                    continue;
                }

                // Validate batch year
                $batch = (int)$row['batch'];
                if ($batch < 2000 || $batch > (int)date('Y')) {
                    $errors[] = "Row {$rowNum}: Invalid batch year '{$batch}'.";
                    continue;
                }

                // Create alumni and user
                try {
                    $photoPath = 'alumni-photos/' . Str::uuid() . '.png';
                    Storage::disk('public')->copy($default, $photoPath);

                    $alumni = Alumni::create([
                        'student_id' => $row['student_id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'course_code' => strtoupper($row['course_code']),
                        'course_name' => $course->name,
                        'batch' => $batch,
                        'status' => 'VERIFIED',
                        'profile_photo' => $photoPath,
                    ]);

                    $tempPassword = Str::random(10);
                    User::create([
                        'name' => $alumni->name,
                        'email' => $alumni->email,
                        'password' => Hash::make($tempPassword),
                        'role' => 'alumni',
                    ]);

                    try {
                        Mail::send(new AlumniRegistered($alumni, $tempPassword));
                    } catch (\Exception $e) {
                        Log::warning("Email not sent for alumni {$alumni->email}: " . $e->getMessage());
                    }

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }

            Storage::delete($path);

            $message = "{$imported} alumni imported successfully.";
            if (!empty($errors)) {
                $shown = array_slice($errors, 0, 3);
                $message .= ' Issues: ' . implode(' | ', $shown);
                if (count($errors) > 3) {
                    $message .= ' ... and ' . (count($errors) - 3) . ' more.';
                }
            }

            return redirect()->route('alumni.management')->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Alumni import failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Parse CSV or Excel file
     */
    private function parseFile(string $filePath, string $extension): array
    {
        $rows = [];
        $headers = [];

        if (in_array(strtolower($extension), ['csv', 'txt'])) {
            $handle = fopen($filePath, 'r');
            while (($line = fgetcsv($handle)) !== false) {
                if (empty($headers)) {
                    $headers = array_map('strtolower', array_map('trim', $line));
                } elseif (count($line) === count($headers)) {
                    $rows[] = array_combine($headers, $line);
                }
            }
            fclose($handle);
        } else {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $cells = $row->getCellIterator();
                    $rowData = [];
                    foreach ($cells as $cell) {
                        $rowData[] = (string)$cell->getValue();
                    }

                    if ($rowIndex === 1) {
                        $headers = array_map('strtolower', array_map('trim', $rowData));
                    } elseif (count($rowData) === count($headers)) {
                        $rows[] = array_combine($headers, $rowData);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Excel parsing failed: ' . $e->getMessage());
            }
        }

        return array_filter($rows, fn($r) => !empty($r['student_id']));
    }
}