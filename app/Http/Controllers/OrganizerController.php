<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrganizerController extends Controller
{
    private function getDefaultPhotoPath(): string
    {
        $dest = 'alumni-photos/default.png';
        if (Storage::disk('public')->exists($dest)) return $dest;

        $candidates = [
            public_path('storage/alumni-photos/default.png'),
            storage_path('app/public/alumni-photos/default.png'),
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
            $size = 128; $img = imagecreatetruecolor($size, $size);
            $bg = imagecolorallocate($img, 122, 63, 145); $fg = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $bg); imagefilledellipse($img, 64, 42, 42, 42, $fg);
            imagefilledellipse($img, 64, 106, 70, 56, $fg);
            ob_start(); imagepng($img); $bytes = ob_get_clean(); imagedestroy($img);
        } else {
            $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        }
        Storage::disk('public')->makeDirectory('alumni-photos');
        Storage::disk('public')->put($dest, $bytes);
        return $dest;
    }

    private function storePhoto(Request $request, string $fieldName): string
    {
        if ($request->hasFile($fieldName)) {
            $file = $request->file($fieldName);
            return $file->storeAs('organizers', Str::uuid() . '.' . $file->getClientOriginalExtension(), 'public');
        }
        return $this->getDefaultPhotoPath();
    }

    public function checkDuplicate(Request $request)
    {
        $field = $request->input('field'); $value = $request->input('value'); $exists = false;
        if ($field === 'id_number')  $exists = Organizer::where('id_number', $value)->exists();
        elseif ($field === 'email')  $exists = Organizer::where('email', $value)->exists() || User::where('email', $value)->exists();
        return response()->json(['exists' => $exists]);
    }

    /* Store — fields prefixed with org_ to prevent old() bleed from alumni form */
    public function store(Request $request)
    {
        $validated = $request->validateWithBag('registerOrganizer', [
            'org_name'          => ['required', 'string', 'max:255'],
            'org_email'         => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
            'org_id_number'     => ['required', 'string', 'unique:organizer,id_number'],
            'org_department'    => ['required', 'string', 'exists:courses,code'],
            'org_profile_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'org_name.required'       => 'Full Name is required.',
            'org_email.required'      => 'Email is required.',
            'org_email.email'         => 'Please enter a valid email address.',
            'org_email.unique'        => 'This email is already registered.',
            'org_id_number.required'  => 'ID Number is required.',
            'org_id_number.unique'    => 'This ID number is already registered.',
            'org_department.required' => 'Department is required.',
            'org_department.exists'   => 'Selected department does not exist.',
        ]);

        try {
            $tempPassword = Str::random(10);
            $profilePhotoPath = $this->storePhoto($request, 'org_profile_photo');

            $user = User::create([
                'name'     => $validated['org_name'],
                'email'    => $validated['org_email'],
                'role'     => 'organizer',
                'password' => bcrypt($tempPassword),
            ]);

            $organizer = Organizer::create([
                'user_id'       => $user->id,
                'name'          => $validated['org_name'],
                'email'         => $validated['org_email'],
                'id_number'     => $validated['org_id_number'],
                'department'    => strtoupper($validated['org_department']),
                'profile_photo' => $profilePhotoPath,
                'status'        => 'ACTIVE',
            ]);

            try {
                \Illuminate\Support\Facades\Mail::to($organizer->email)
                    ->send(new \App\Mail\OrganizerRegistered($organizer, $tempPassword));
            } catch (\Exception $e) {
                Log::error('Organizer email failed: ' . $e->getMessage());
                return redirect()->route('alumni.management')
                    ->with('success', "Organizer '{$organizer->name}' registered!")
                    ->with('warning', 'Email could not be sent: ' . $e->getMessage());
            }

            return redirect()->route('alumni.management')
                ->with('success', "Organizer '{$organizer->name}' registered! Credentials sent to {$organizer->email}");

        } catch (\Exception $e) {
            Log::error('Organizer creation failed: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to register organizer: ' . $e->getMessage());
        }
    }

    /* Update — fields prefixed with org_ */
    public function update(Request $request, Organizer $organizer)
    {
        $request->merge(['_organizer_id' => $organizer->id]);

        $validated = $request->validateWithBag('editOrganizer', [
            'org_name'          => ['required', 'string', 'max:255'],
            'org_id_number'     => ['required', 'string', 'unique:organizer,id_number,' . $organizer->id],
            'org_department'    => ['required', 'string', 'exists:courses,code'],
            'org_profile_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'org_name.required'       => 'Full Name is required.',
            'org_id_number.required'  => 'ID Number is required.',
            'org_id_number.unique'    => 'This ID number is already registered.',
            'org_department.required' => 'Department is required.',
            'org_department.exists'   => 'Selected department does not exist.',
        ]);

        try {
            if ($organizer->user) $organizer->user()->update(['name' => $validated['org_name']]);

            if ($request->hasFile('org_profile_photo')) {
                if ($organizer->profile_photo && $organizer->profile_photo !== 'alumni-photos/default.png'
                    && Storage::disk('public')->exists($organizer->profile_photo)) {
                    Storage::disk('public')->delete($organizer->profile_photo);
                }
                $file = $request->file('org_profile_photo');
                $organizer->profile_photo = $file->storeAs('organizers', Str::uuid() . '.' . $file->getClientOriginalExtension(), 'public');
            }

            $organizer->name       = $validated['org_name'];
            $organizer->id_number  = $validated['org_id_number'];
            $organizer->department = strtoupper($validated['org_department']);
            $organizer->save();

            return redirect()->route('alumni.management')
                ->with('success', "Organizer '{$organizer->name}' updated successfully!");

        } catch (\Exception $e) {
            Log::error('Organizer update failed: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to update: ' . $e->getMessage());
        }
    }

    public function destroy(Organizer $organizer)
    {
        try {
            $name = $organizer->name;
            if ($organizer->profile_photo && $organizer->profile_photo !== 'alumni-photos/default.png'
                && Storage::disk('public')->exists($organizer->profile_photo)) {
                Storage::disk('public')->delete($organizer->profile_photo);
            }
            if ($organizer->user) $organizer->user()->delete();
            $organizer->delete();
            return redirect()->route('alumni.management')->with('success', "Organizer '{$name}' deleted successfully.");
        } catch (\Exception $e) {
            Log::error('Organizer deletion failed: ' . $e->getMessage());
            return redirect()->route('alumni.management')->with('error', 'Failed to delete: ' . $e->getMessage());
        }
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,xlsx,xls,txt', 'max:10240']]);
        try {
            $file = $request->file('file');
            $path = $file->store('temp-imports');
            $rows = $this->parseFile(storage_path('app/' . $path), $file->getClientOriginalExtension());
            $imported = 0; $errors = [];
            foreach ($rows as $index => $row) {
                $result = $this->importOrganizerRow(array_map('trim', $row), $index + 2);
                if ($result === true) $imported++; else $errors[] = $result;
            }
            Storage::delete($path);
            $message = "{$imported} organizers imported successfully.";
            if (!empty($errors)) {
                $shown = array_slice($errors, 0, 3);
                $message .= ' Issues: ' . implode(' | ', $shown);
                if (count($errors) > 3) $message .= ' ... and ' . (count($errors) - 3) . ' more.';
            }
            return redirect()->route('alumni.management')->with('success', $message);
        } catch (\Exception $e) {
            Log::error('Organizer import failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    private function importOrganizerRow(array $data, int $line): bool|string
    {
        try {
            if (empty($data['name']) || empty($data['email']) || empty($data['id_number']) || empty($data['department']))
                return "Line {$line}: Missing required fields";
            if (Organizer::where('email', $data['email'])->exists())      return "Line {$line}: Email already exists";
            if (Organizer::where('id_number', $data['id_number'])->exists()) return "Line {$line}: ID Number already exists";
            if (User::where('email', $data['email'])->exists())           return "Line {$line}: Email already in users";
            $course = \App\Models\Course::where('code', strtoupper($data['department']))->first();
            if (!$course) return "Line {$line}: Department not found";
            $tempPassword = Str::random(16);
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'role' => 'organizer', 'password' => bcrypt($tempPassword)]);
            $organizer = Organizer::create([
                'user_id' => $user->id, 'name' => $data['name'], 'email' => $data['email'],
                'id_number' => $data['id_number'], 'department' => strtoupper($data['department']),
                'status' => 'ACTIVE', 'profile_photo' => $this->getDefaultPhotoPath(),
            ]);
            try { \Illuminate\Support\Facades\Mail::to($organizer->email)->send(new \App\Mail\OrganizerRegistered($organizer, $tempPassword)); }
            catch (\Exception $e) { Log::warning("Email not sent for {$data['email']}: " . $e->getMessage()); }
            return true;
        } catch (\Exception $e) { return "Line {$line}: " . $e->getMessage(); }
    }

    private function parseFile(string $filePath, string $extension): array
    {
        $rows = []; $headers = [];
        if (in_array(strtolower($extension), ['csv', 'txt'])) {
            $handle = fopen($filePath, 'r');
            while (($line = fgetcsv($handle)) !== false) {
                if (empty($headers)) $headers = array_map('strtolower', array_map('trim', $line));
                elseif (count($line) === count($headers)) $rows[] = array_combine($headers, $line);
            }
            fclose($handle);
        } else {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells = $row->getCellIterator(); $rowData = [];
                foreach ($cells as $cell) $rowData[] = (string) $cell->getValue();
                if ($rowIndex === 1) $headers = array_map('strtolower', array_map('trim', $rowData));
                elseif (count($rowData) === count($headers)) $rows[] = array_combine($headers, $rowData);
            }
        }
        return array_filter($rows, fn($r) => !empty($r['id_number']));
    }

    public function export()
    {
        $organizers = Organizer::withoutTrashed()->orderBy('created_at', 'desc')->get();
        $csv = "Name,ID Number,Email,Department,Status,Created At\n";
        foreach ($organizers as $o)
            $csv .= "\"{$o->name}\",\"{$o->id_number}\",\"{$o->email}\",\"{$o->department}\",\"{$o->status}\",\"{$o->created_at}\"\n";
        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="organizers_' . date('Y-m-d') . '.csv"');
    }
}