<?php

namespace App\Http\Controllers;

use App\Mail\OrganizerRegistered;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OrganizerController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'     => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:2',
            'last_name'      => 'required|string|max:255',
            'suffix'         => 'nullable|string|max:20',
            'email'          => 'required|email|unique:users|unique:organizer',
            'id_number'      => 'required|string|unique:organizer',
            'department'     => 'required|string|max:255',
        ]);

        try {
            $tempPassword = $this->generateTemporaryPassword();

            // Build full name for users table
            $fullName = trim(implode(' ', array_filter([
                $validated['first_name'],
                $validated['middle_initial'] ?? null,
                $validated['last_name'],
                $validated['suffix']         ?? null,
            ])));

            // Create user account
            $user = User::create([
                'name'     => $fullName,
                'email'    => $validated['email'],
                'role'     => 'organizer',
                'password' => Hash::make($tempPassword),
            ]);

            // Create organizer — NO 'name' (it's a virtual column)
            $organizer = Organizer::create([
                'user_id'             => $user->id,
                'first_name'          => $validated['first_name'],
                'middle_initial'      => $validated['middle_initial'] ?? null,
                'last_name'           => $validated['last_name'],
                'suffix'              => $validated['suffix']         ?? null,
                'email'               => $validated['email'],
                'id_number'           => $validated['id_number'],
                'department'          => $validated['department'],
                'status'              => 'ACTIVE',
                'password_changed_at' => null,
            ]);

            try {
                Mail::to($organizer->email)->send(new OrganizerRegistered($organizer, $tempPassword));
                Log::info('Welcome email sent to organizer: ' . $organizer->email);
            } catch (\Exception $mailError) {
                Log::warning('Failed to send welcome email to ' . $organizer->email . ': ' . $mailError->getMessage());
            }

            Log::info('Organizer account created: ' . $organizer->email . ' (ID: ' . $organizer->id_number . ')');

            return response()->json([
                'success'      => true,
                'message'      => 'Organizer account created successfully. Welcome email sent.',
                'organizer'    => $organizer,
                'tempPassword' => $tempPassword,
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating organizer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create organizer account: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function generateTemporaryPassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers   = '0123456789';
        $special   = '@$!%*?&';

        $password  = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $allChars = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < 10; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }

    public function show(Organizer $organizer)
    {
        return response()->json($organizer);
    }

    public function update(Request $request, Organizer $organizer)
    {
        $validated = $request->validate([
            'first_name'     => 'sometimes|string|max:255',
            'middle_initial' => 'sometimes|nullable|string|max:2',
            'last_name'      => 'sometimes|string|max:255',
            'suffix'         => 'sometimes|nullable|string|max:20',
            'department'     => 'sometimes|string|max:255',
            'profile_photo'  => 'sometimes|image|max:2048',
        ]);

        try {
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('organizer-photos');
                $validated['profile_photo'] = $path;
            }

            // Update organizer — 'name' is virtual, never include it
            $organizer->update(collect($validated)->except('profile_photo')->toArray()
                + ($request->hasFile('profile_photo') ? ['profile_photo' => $validated['profile_photo']] : []));

            // Sync full name to users table
            if (isset($validated['first_name']) || isset($validated['last_name'])) {
                $fullName = trim(implode(' ', array_filter([
                    $validated['first_name']     ?? $organizer->first_name,
                    $validated['middle_initial'] ?? $organizer->middle_initial,
                    $validated['last_name']      ?? $organizer->last_name,
                    $validated['suffix']         ?? $organizer->suffix,
                ])));

                $organizer->user?->update(['name' => $fullName]);
            }

            Log::info('Organizer profile updated: ' . $organizer->email);

            return response()->json([
                'success'   => true,
                'message'   => 'Profile updated successfully',
                'organizer' => $organizer,
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating organizer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, Organizer $organizer)
    {
        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE,SUSPENDED',
        ]);

        try {
            $organizer->update(['status' => $validated['status']]);

            Log::info('Organizer status updated: ' . $organizer->email . ' -> ' . $validated['status']);

            return response()->json([
                'success'   => true,
                'message'   => 'Status updated successfully',
                'organizer' => $organizer,
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating organizer status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Organizer $organizer)
    {
        try {
            $email = $organizer->email;

            // Cascade via user (user delete will cascade to organizer via FK)
            if ($organizer->user) {
                $organizer->user->delete();
            } else {
                $organizer->delete();
            }

            Log::info('Organizer account deleted: ' . $email);

            return response()->json([
                'success' => true,
                'message' => 'Organizer account deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting organizer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete organizer: ' . $e->getMessage(),
            ], 500);
        }
    }
}