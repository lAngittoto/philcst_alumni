<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\AlumniRegistered;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AlumniController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');
        $batch  = $request->get('batch');
        $course = $request->get('course');

        $alumni = Alumni::query()
            ->search($search)
            ->byBatch($batch)
            ->byCourse($course)
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends($request->query());

        $courses     = Course::orderBy('code')->get();
        $batches     = Alumni::distinct()->orderByDesc('batch')->pluck('batch');
        $totalAlumni = Alumni::count();

        return view('livewire.admin.alumni-management', compact(
            'alumni', 'courses', 'batches', 'totalAlumni', 'search', 'batch', 'course'
        ));
    }

   public function store(Request $request)
{
    $validated = $request->validate([
        'name'          => ['required', 'string', 'max:255'],
        'student_id'    => ['required', 'string', 'max:50', 'unique:alumni,student_id'],
        'email'         => ['required', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
        'course_code'   => ['required', 'string', 'exists:courses,code'],
        'batch'         => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
        'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
    ]);

    $course = Course::where('code', $validated['course_code'])->firstOrFail();

    $validated['course_name'] = $course->name;
    $validated['status']      = 'VERIFIED';

    if ($request->hasFile('profile_photo')) {
        $validated['profile_photo'] = $request->file('profile_photo')
            ->store('alumni-photos', 'public');
    }

    $alumni       = Alumni::create($validated);
    $tempPassword = Str::random(10);

    // Create user account with alumni role
    \App\Models\User::create([
        'name'     => $alumni->name,
        'email'    => $alumni->email,
        'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
        'role'     => 'alumni',
    ]);

    // Send email — show actual error in flash message during development
    $mailError = null;
    try {
        Mail::to($alumni->email)->send(new AlumniRegistered($alumni, $tempPassword));
    } catch (\Exception $e) {
        Log::error('Alumni registration email failed: ' . $e->getMessage());
        $mailError = $e->getMessage();
    }

    if ($mailError) {
        return back()->with('success', 'Alumni registered successfully!')
                     ->with('error', 'Email not sent: ' . $mailError);
    }

    return back()->with('success', 'Alumni registered! Credentials sent to ' . $alumni->email);
}

    public function destroy(Alumni $alumni)
    {
        if ($alumni->profile_photo && Storage::disk('public')->exists($alumni->profile_photo)) {
            Storage::disk('public')->delete($alumni->profile_photo);
        }

        $alumni->delete();

        return back()->with('success', 'Alumni record deleted successfully.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        try {
            $file     = $request->file('file');
            $path     = $file->store('temp-imports');
            $fullPath = storage_path('app/' . $path);
            $rows     = $this->parseFile($fullPath, $file->getClientOriginalExtension());

            $imported = 0;
            $errors   = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                $row = array_map('trim', $row);

                if (empty($row['student_id']) || empty($row['name']) || empty($row['email']) ||
                    empty($row['course_code']) || empty($row['batch'])) {
                    $errors[] = "Row {$rowNum}: Missing required fields.";
                    continue;
                }

                if (Alumni::where('student_id', $row['student_id'])->exists()) {
                    $errors[] = "Row {$rowNum}: Student ID '{$row['student_id']}' already exists.";
                    continue;
                }

                if (Alumni::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email '{$row['email']}' already exists.";
                    continue;
                }

                $course = Course::where('code', strtoupper($row['course_code']))->first();
                if (! $course) {
                    $errors[] = "Row {$rowNum}: Course '{$row['course_code']}' not found.";
                    continue;
                }

                $batch = (int) $row['batch'];
                if ($batch < 2000 || $batch > (int) date('Y')) {
                    $errors[] = "Row {$rowNum}: Invalid batch year '{$batch}'.";
                    continue;
                }

                try {
                    $alumni       = Alumni::create([
                        'student_id'  => $row['student_id'],
                        'name'        => $row['name'],
                        'email'       => $row['email'],
                        'course_code' => strtoupper($row['course_code']),
                        'course_name' => $course->name,
                        'batch'       => $batch,
                        'status'      => 'VERIFIED',
                    ]);

                    $tempPassword = Str::random(10);
                    Mail::to($alumni->email)->send(new AlumniRegistered($alumni, $tempPassword));
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }

            Storage::delete($path);

            $message = "{$imported} alumni imported successfully.";
            if (! empty($errors)) {
                $shown    = array_slice($errors, 0, 3);
                $message .= ' Issues: ' . implode(' | ', $shown);
                if (count($errors) > 3) {
                    $message .= ' ... and ' . (count($errors) - 3) . ' more.';
                }
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Import failed: ' . $e->getMessage());
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    private function parseFile(string $filePath, string $extension): array
    {
        $rows    = [];
        $headers = [];

        if (in_array(strtolower($extension), ['csv', 'txt'])) {
            $handle = fopen($filePath, 'r');
            while (($line = fgetcsv($handle)) !== false) {
                if (empty($headers)) {
                    $headers = array_map('strtolower', array_map('trim', $line));
                } else {
                    if (count($line) === count($headers)) {
                        $rows[] = array_combine($headers, $line);
                    }
                }
            }
            fclose($handle);
        } else {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet       = $spreadsheet->getActiveSheet();

            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells   = $row->getCellIterator();
                $rowData = [];
                foreach ($cells as $cell) {
                    $rowData[] = (string) $cell->getValue();
                }

                if ($rowIndex === 1) {
                    $headers = array_map('strtolower', array_map('trim', $rowData));
                } elseif (count($rowData) === count($headers)) {
                    $rows[] = array_combine($headers, $rowData);
                }
            }
        }

        return array_filter($rows, fn($r) => ! empty($r['student_id']));
    }
}