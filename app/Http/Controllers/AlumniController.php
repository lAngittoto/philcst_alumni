<?php

namespace App\Http\Controllers;

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
    /* ─────────────────────────────────────────
     | Default profile photo helper
     ───────────────────────────────────────── */
    private function getDefaultPhotoPath(): string
    {
        $dest = 'alumni-photos/default.png';

        if (Storage::disk('public')->exists($dest)) {
            return $dest;
        }

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

        if (function_exists('imagecreatetruecolor')) {
            $size = 128;
            $img  = imagecreatetruecolor($size, $size);
            $bg   = imagecolorallocate($img, 122, 63, 145);
            $fg   = imagecolorallocate($img, 255, 255, 255);
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

    /* ─────────────────────────────────────────
     | Check duplicate (AJAX)
     ───────────────────────────────────────── */
    public function checkDuplicate(Request $request)
    {
        $field  = $request->input('field');
        $value  = $request->input('value');
        $exists = false;

        if ($field === 'student_id') {
            $exists = Alumni::where('student_id', $value)->exists();
        } elseif ($field === 'email') {
            $exists = Alumni::where('email', $value)->exists()
                   || \App\Models\User::where('email', $value)->exists();
        }

        return response()->json(['exists' => $exists]);
    }

    /* ─────────────────────────────────────────
     | Index
     ───────────────────────────────────────── */
    public function index(Request $request)
    {
        $courses     = Course::orderBy('code')->get();
        $batches     = Alumni::distinct()->orderByDesc('batch')->pluck('batch');
        $totalAlumni = Alumni::count();
        $organizers  = \App\Models\Organizer::withoutTrashed()
                         ->orderByRaw('GREATEST(created_at, COALESCE(updated_at, created_at)) DESC')
                         ->get();
        $departments = \App\Models\Organizer::withoutTrashed()->distinct()->pluck('department')->sort();

        if ($request->ajax()) {
            $section = $request->get('section');
            if ($section === 'alumni')     return $this->getAlumniData($request);
            if ($section === 'organizers') return $this->getOrganizerData($request);
        }

        $alumni = Alumni::orderByRaw('GREATEST(created_at, COALESCE(updated_at, created_at)) DESC')->paginate(100);

        return view('livewire.admin.alumni-management', compact(
            'alumni', 'courses', 'batches', 'totalAlumni', 'organizers', 'departments'
        ));
    }

    /* ─────────────────────────────────────────
     | AJAX — Alumni table data
     ───────────────────────────────────────── */
    private function getAlumniData(Request $request)
    {
        $search = $request->get('search', '');
        $batch  = $request->get('batch', '');
        $course = $request->get('course', '');
        $sort   = $request->get('sort', 'recent');
        $page   = $request->get('page', 1);

        $query = Alumni::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name',       'like', "%{$search}%")
                  ->orWhere('student_id','like', "%{$search}%")
                  ->orWhere('email',     'like', "%{$search}%");
            });
        }
        if ($batch)  $query->where('batch',       $batch);
        if ($course) $query->where('course_code', $course);
        if ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            // recent: show newly added OR recently updated first
            $query->orderByRaw('GREATEST(created_at, COALESCE(updated_at, created_at)) DESC');
        }

        $alumni = $query->paginate(100, ['*'], 'page', $page);

        $defaultPath = $this->getDefaultPhotoPath();
        $defaultUrl = asset('storage/' . $defaultPath);

        $tbody = '';
        foreach ($alumni as $item) {
            $sc = [
                'VERIFIED' => 'badge-ok',
                'PENDING'  => 'badge-warn',
                'REJECTED' => 'badge-danger',
            ][$item->status] ?? 'badge-gray';

            // Verify the file actually exists before using its URL
            if ($item->profile_photo && Storage::disk('public')->exists($item->profile_photo)) {
                $photoUrl = asset('storage/' . $item->profile_photo);
            } else {
                $photoUrl = $defaultUrl;
            }

            $n      = htmlspecialchars($item->name,       ENT_QUOTES);
            $em     = htmlspecialchars($item->email,       ENT_QUOTES);
            $cc     = htmlspecialchars($item->course_code, ENT_QUOTES);
            $letter = htmlspecialchars(strtoupper(substr($item->name, 0, 1)), ENT_QUOTES);

            $tbody .= <<<HTML
            <tr>
                <td>
                    <div class="user-cell">
                        <img src="{$photoUrl}" class="avatar" alt="{$n}" loading="lazy"
                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="avatar-letter" style="display:none;">{$letter}</div>
                        <span class="user-name">{$n}</span>
                    </div>
                </td>
                <td><span class="mono">{$item->student_id}</span></td>
                <td><span class="badge badge-brand">{$cc}</span></td>
                <td class="tc"><span class="mono">{$item->batch}</span></td>
                <td><span class="muted">{$em}</span></td>
                <td class="tc"><span class="badge {$sc}">{$item->status}</span></td>
                <td>
                    <div class="act-btns">
                        <button type="button"
                            data-id="{$item->id}"
                            data-name="{$n}"
                            data-student-id="{$item->student_id}"
                            data-email="{$em}"
                            data-batch="{$item->batch}"
                            data-course-code="{$cc}"
                            onclick="openEditAlumni(this)"
                            class="act-btn act-btn-edit" title="Edit">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button type="button"
                            data-delete-url="/alumni/{$item->id}"
                            data-delete-name="{$n}"
                            data-delete-type="alumni"
                            onclick="showDeleteConfirm(this.dataset.deleteUrl,this.dataset.deleteName,this.dataset.deleteType)"
                            class="act-btn act-btn-del" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            HTML;
        }

        return response()->json([
            'tbody'      => $tbody,
            'pagination' => $this->getPaginationHtml($alumni, 'alumni'),
            'info'       => "Showing " . ($alumni->firstItem() ?? 0) . "–" . ($alumni->lastItem() ?? 0) . " of " . $alumni->total() . " entries",
        ]);
    }

    /* ─────────────────────────────────────────
     | AJAX — Organizer table data
     ───────────────────────────────────────── */
    private function getOrganizerData(Request $request)
    {
        $search     = $request->get('search', '');
        $department = $request->get('department', '');
        $sort       = $request->get('sort', 'recent');
        $page       = $request->get('page', 1);

        $query = \App\Models\Organizer::withoutTrashed();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name',      'like', "%{$search}%")
                  ->orWhere('id_number','like', "%{$search}%")
                  ->orWhere('email',    'like', "%{$search}%");
            });
        }
        if ($department) $query->where('department', $department);
        if ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderByRaw('GREATEST(created_at, COALESCE(updated_at, created_at)) DESC');
        }

        $organizers = $query->paginate(100, ['*'], 'page', $page);

        $defaultPath = $this->getDefaultPhotoPath();
        $defaultUrl = asset('storage/' . $defaultPath);

        $tbody = '';
        foreach ($organizers as $item) {
            $os = [
                'ACTIVE'    => 'badge-ok',
                'INACTIVE'  => 'badge-warn',
                'SUSPENDED' => 'badge-danger',
            ][$item->status] ?? 'badge-gray';

            // Verify the file actually exists before using its URL
            if ($item->profile_photo && Storage::disk('public')->exists($item->profile_photo)) {
                $photoUrl = asset('storage/' . $item->profile_photo);
            } else {
                $photoUrl = $defaultUrl;
            }

            $n      = htmlspecialchars($item->name,      ENT_QUOTES);
            $em     = htmlspecialchars($item->email,      ENT_QUOTES);
            $dp     = htmlspecialchars($item->department, ENT_QUOTES);
            $idn    = htmlspecialchars($item->id_number,  ENT_QUOTES);
            $letter = htmlspecialchars(strtoupper(substr($item->name, 0, 1)), ENT_QUOTES);

            $tbody .= <<<HTML
            <tr>
                <td>
                    <div class="user-cell">
                        <img src="{$photoUrl}" class="avatar" alt="{$n}" loading="lazy"
                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="avatar-letter" style="display:none;">{$letter}</div>
                        <span class="user-name">{$n}</span>
                    </div>
                </td>
                <td><span class="mono">{$idn}</span></td>
                <td><span class="muted">{$em}</span></td>
                <td><span class="badge badge-brand">{$dp}</span></td>
                <td class="tc"><span class="badge {$os}">{$item->status}</span></td>
                <td>
                    <div class="act-btns">
                        <button type="button"
                            data-id="{$item->id}"
                            data-name="{$n}"
                            data-email="{$em}"
                            data-id-number="{$idn}"
                            data-department="{$dp}"
                            onclick="openEditOrganizer(this)"
                            class="act-btn act-btn-edit" title="Edit">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button type="button"
                            data-delete-url="/organizers/{$item->id}"
                            data-delete-name="{$n}"
                            data-delete-type="organizer"
                            onclick="showDeleteConfirm(this.dataset.deleteUrl,this.dataset.deleteName,this.dataset.deleteType)"
                            class="act-btn act-btn-del" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            HTML;
        }

        return response()->json([
            'tbody'      => $tbody,
            'pagination' => $this->getPaginationHtml($organizers, 'organizers'),
            'info'       => "Showing " . ($organizers->firstItem() ?? 0) . "–" . ($organizers->lastItem() ?? 0) . " of " . $organizers->total() . " entries",
        ]);
    }

    /* ─────────────────────────────────────────
     | Pagination HTML
     ───────────────────────────────────────── */
    private function getPaginationHtml($paginator, string $section): string
    {
        $html    = '';
        $cur     = $paginator->currentPage();
        $last    = $paginator->lastPage();
        $fn      = $section === 'alumni' ? 'fetchAlumniPage' : 'fetchOrgPage';
        $dis     = 'class="pag-btn disabled"';
        $normal  = 'class="pag-btn"';

        $html .= $cur == 1
            ? "<span {$dis}>Prev</span>"
            : "<button type=\"button\" onclick=\"{$fn}(" . ($cur - 1) . ")\" {$normal}>Prev</button>";

        for ($p = max(1, $cur - 3); $p <= min($last, $cur + 3); $p++) {
            $html .= $p == $cur
                ? "<span class=\"pag-btn active\">{$p}</span>"
                : "<button type=\"button\" onclick=\"{$fn}({$p})\" {$normal}>{$p}</button>";
        }

        $html .= $cur < $last
            ? "<button type=\"button\" onclick=\"{$fn}(" . ($cur + 1) . ")\" {$normal}>Next</button>"
            : "<span {$dis}>Next</span>";

        return $html;
    }

    /* ─────────────────────────────────────────
     | Store (Register Alumni)
     ───────────────────────────────────────── */
    public function store(Request $request)
    {
        $validated = $request->validateWithBag('registerAlumni', [
            'name'          => ['required', 'string', 'max:255'],
            'student_id'    => ['required', 'string', 'size:8', 'regex:/^\d+$/', 'unique:alumni,student_id'],
            'email'         => ['required', 'email', 'max:255', 'unique:alumni,email', 'unique:users,email'],
            'course_code'   => ['required', 'string', 'exists:courses,code'],
            'batch'         => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'name.required'        => 'Full Name is required.',
            'student_id.required'  => 'Student ID is required.',
            'student_id.size'      => 'Student ID must be exactly 8 digits.',
            'student_id.regex'     => 'Student ID must contain only numbers.',
            'student_id.unique'    => 'This Student ID is already registered.',
            'email.required'       => 'Email is required.',
            'email.email'          => 'Please enter a valid email address.',
            'email.unique'         => 'This email is already registered.',
            'course_code.required' => 'Course is required.',
            'course_code.exists'   => 'Selected course does not exist.',
            'batch.required'       => 'Batch year is required.',
            'batch.min'            => 'Batch year cannot be before 2000.',
            'batch.max'            => 'Batch year cannot be in the future.',
        ]);

        try {
            $course = Course::where('code', $validated['course_code'])->firstOrFail();
            $validated['course_name'] = $course->name;
            $validated['status']      = 'VERIFIED';

            if ($request->hasFile('profile_photo')) {
                $validated['profile_photo'] = $request->file('profile_photo')
                    ->store('alumni-photos', 'public');
            } else {
                $defaultSrc = $this->getDefaultPhotoPath();
                $destName   = 'alumni-photos/' . Str::uuid() . '.png';
                Storage::disk('public')->copy($defaultSrc, $destName);
                $validated['profile_photo'] = $destName;
            }

            $alumni       = Alumni::create($validated);
            $tempPassword = Str::random(10);

            \App\Models\User::create([
                'name'     => $alumni->name,
                'email'    => $alumni->email,
                'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
                'role'     => 'alumni',
            ]);

            try {
                Mail::to($alumni->email)->send(new AlumniRegistered($alumni, $tempPassword));
            } catch (\Exception $e) {
                Log::error('Alumni registration email failed: ' . $e->getMessage());
                return back()->with('success', 'Alumni registered successfully!')
                             ->with('warning', 'Email could not be sent: ' . $e->getMessage());
            }

            return back()->with('success', 'Alumni registered! Credentials sent to ' . $alumni->email);

        } catch (\Exception $e) {
            Log::error('Alumni creation failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to register alumni: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
     | Update (Edit Alumni)
     ───────────────────────────────────────── */
    public function update(Request $request, Alumni $alumni)
    {
        $request->merge(['_alumni_id' => $alumni->id]);

        $validated = $request->validateWithBag('editAlumni', [
            'name'          => ['required', 'string', 'max:255'],
            'student_id'    => ['required', 'string', 'size:8', 'regex:/^\d+$/', 'unique:alumni,student_id,' . $alumni->id],
            'batch'         => ['required', 'integer', 'min:2000', 'max:' . date('Y')],
            'course_code'   => ['required', 'string', 'exists:courses,code'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'name.required'        => 'Full Name is required.',
            'student_id.size'      => 'Student ID must be exactly 8 digits.',
            'student_id.regex'     => 'Student ID must contain only numbers.',
            'student_id.unique'    => 'This Student ID is already registered.',
            'batch.required'       => 'Batch year is required.',
            'course_code.required' => 'Course is required.',
        ]);

        try {
            $course = Course::where('code', $validated['course_code'])->firstOrFail();
            $validated['course_name'] = $course->name;

            if ($request->hasFile('profile_photo')) {
                if ($alumni->profile_photo
                    && $alumni->profile_photo !== 'alumni-photos/default.png'
                    && Storage::disk('public')->exists($alumni->profile_photo)) {
                    Storage::disk('public')->delete($alumni->profile_photo);
                }
                $validated['profile_photo'] = $request->file('profile_photo')
                    ->store('alumni-photos', 'public');
            } else {
                unset($validated['profile_photo']);
            }

            $alumni->update($validated);

            return redirect()->route('alumni.management')
                ->with('success', "Alumni record for '{$alumni->name}' updated successfully!");

        } catch (\Exception $e) {
            Log::error('Alumni update failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to update alumni: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
     | Destroy (Delete Alumni)
     ───────────────────────────────────────── */
    public function destroy(Alumni $alumni)
    {
        try {
            $name = $alumni->name;

            if ($alumni->profile_photo
                && $alumni->profile_photo !== 'alumni-photos/default.png'
                && Storage::disk('public')->exists($alumni->profile_photo)) {
                Storage::disk('public')->delete($alumni->profile_photo);
            }

            if ($alumni->user) {
                $alumni->user()->delete();
            }

            $alumni->delete();

            return redirect()->route('alumni.management')
                ->with('success', "Alumni '{$name}' has been deleted successfully.");

        } catch (\Exception $e) {
            Log::error('Alumni deletion failed: ' . $e->getMessage());
            return redirect()->route('alumni.management')
                ->with('error', 'Failed to delete alumni: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
     | Import
     ───────────────────────────────────────── */
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
            $default  = $this->getDefaultPhotoPath();

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;
                $row    = array_map('trim', $row);

                if (empty($row['student_id']) || empty($row['name']) || empty($row['email']) ||
                    empty($row['course_code']) || empty($row['batch'])) {
                    $errors[] = "Row {$rowNum}: Missing required fields.";
                    continue;
                }

                if (!ctype_digit($row['student_id']) || strlen($row['student_id']) !== 8) {
                    $errors[] = "Row {$rowNum}: Student ID must be exactly 8 digits.";
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
                if (\App\Models\User::where('email', $row['email'])->exists()) {
                    $errors[] = "Row {$rowNum}: Email '{$row['email']}' already exists in users.";
                    continue;
                }

                $course = Course::where('code', strtoupper($row['course_code']))->first();
                if (!$course) {
                    $errors[] = "Row {$rowNum}: Course '{$row['course_code']}' not found.";
                    continue;
                }

                $batch = (int) $row['batch'];
                if ($batch < 2000 || $batch > (int) date('Y')) {
                    $errors[] = "Row {$rowNum}: Invalid batch year '{$batch}'.";
                    continue;
                }

                try {
                    $photoPath = 'alumni-photos/' . Str::uuid() . '.png';
                    Storage::disk('public')->copy($default, $photoPath);

                    $alumni = Alumni::create([
                        'student_id'    => $row['student_id'],
                        'name'          => $row['name'],
                        'email'         => $row['email'],
                        'course_code'   => strtoupper($row['course_code']),
                        'course_name'   => $course->name,
                        'batch'         => $batch,
                        'status'        => 'VERIFIED',
                        'profile_photo' => $photoPath,
                    ]);

                    $tempPassword = Str::random(10);
                    \App\Models\User::create([
                        'name'     => $alumni->name,
                        'email'    => $alumni->email,
                        'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
                        'role'     => 'alumni',
                    ]);

                    Mail::to($alumni->email)->send(new AlumniRegistered($alumni, $tempPassword));
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }

            Storage::delete($path);

            $message = "{$imported} alumni imported successfully.";
            if (!empty($errors)) {
                $shown    = array_slice($errors, 0, 3);
                $message .= ' Issues: ' . implode(' | ', $shown);
                if (count($errors) > 3) $message .= ' ... and ' . (count($errors) - 3) . ' more.';
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Import failed: ' . $e->getMessage());
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
     | Parse CSV / Excel file
     ───────────────────────────────────────── */
    private function parseFile(string $filePath, string $extension): array
    {
        $rows    = [];
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
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet       = $spreadsheet->getActiveSheet();
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells   = $row->getCellIterator();
                $rowData = [];
                foreach ($cells as $cell) $rowData[] = (string) $cell->getValue();

                if ($rowIndex === 1) {
                    $headers = array_map('strtolower', array_map('trim', $rowData));
                } elseif (count($rowData) === count($headers)) {
                    $rows[] = array_combine($headers, $rowData);
                }
            }
        }

        return array_filter($rows, fn ($r) => !empty($r['student_id']));
    }
}